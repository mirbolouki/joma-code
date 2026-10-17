<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — مهاجرتِ پیام امن مراجع، نسخهٔ ۶.۲.۰
 *  فقط مدیر؛ اجرا با POST و CSRF؛ فقط یک جدول تازه می‌سازد.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/includes/bootstrap.php';

auth_require_active_session($GLOBALS['db']);
require_role(array(ROLE_ADMIN));

$db = $GLOBALS['db'];
$version = PATIENT_MESSAGES_MIGRATION_VERSION;
$actor_person_id = isset($_SESSION['person_id']) ? (int)$_SESSION['person_id'] : null;
$actor_role_code = isset($_SESSION['active_role_code']) ? $_SESSION['active_role_code'] : ROLE_ADMIN;
$schema_path = __DIR__ . '/database/patient_messages_schema.sql';
$required_tables = array('migrations', 'persons', 'admissions', 'audit_log',
                         'clinical_cases', 'appointments', 'form_assignments',
                         'form_templates', 'patient_appointment_requests');
$required_columns = array('id', 'public_id', 'patient_person_id', 'admission_id',
    'message_type', 'event_key', 'subject', 'body', 'created_by_person_id',
    'created_by_role_code', 'sms_status', 'sms_error', 'sms_sent_at', 'read_at', 'created_at');
$required_indexes = array('PRIMARY', 'public_id', 'uq_patient_messages_event_key',
    'idx_patient_messages_patient_created', 'idx_patient_messages_patient_read',
    'idx_patient_messages_admission_created');
$log = array();
$missing_required = array();
$done = false;
$failed = false;

function pmsg_table_exists($db, $name)
{
    return db_select_one($db,
        "SELECT 1 AS present FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
        's', array((string)$name)) !== null;
}

function pmsg_column_exists($db, $table, $column)
{
    return db_select_one($db,
        "SELECT 1 AS present FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
        'ss', array((string)$table, (string)$column)) !== null;
}

function pmsg_index_exists($db, $table, $index)
{
    return db_select_one($db,
        "SELECT 1 AS present FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1",
        'ss', array((string)$table, (string)$index)) !== null;
}

foreach ($required_tables as $table_name) {
    if (!pmsg_table_exists($db, $table_name)) {
        $missing_required[] = $table_name;
    }
}
$migrations_exists = pmsg_table_exists($db, 'migrations');
$previous_admin_migration = $migrations_exists ? migration_applied($db, '6.0.0') : false;
$previous_portal_migration = $migrations_exists ? migration_applied($db, '6.1.0') : false;
$phase_prerequisites_ready = pmsg_table_exists($db, 'appointments')
    && pmsg_table_exists($db, 'form_templates')
    && pmsg_table_exists($db, 'form_assignments');
$admin_prerequisite_ready = function_exists('clinic_admin_modules_ready')
    && clinic_admin_modules_ready($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if (count($missing_required) > 0) {
            throw new Exception('پیش‌نیازها کامل نیستند؛ جدول‌های غایب: '
                . implode('، ', $missing_required) . '. مهاجرت‌های قبلی را دوباره اجرا نکنید.');
        }
        if (!$previous_admin_migration || !$previous_portal_migration
            || !$phase_prerequisites_ready || !$admin_prerequisite_ready) {
            throw new Exception('پیش‌نیازهای نسخه‌های ۶.۰.۰ و ۶.۱.۰ یا فازهای ۲ و ۴ کامل نیستند. '
                . 'مهاجرت‌های قبلی را بی‌دلیل دوباره اجرا نکنید؛ وضعیت نصب را بررسی کنید.');
        }

        if (!pmsg_table_exists($db, 'patient_messages')) {
            $schema = @file_get_contents($schema_path);
            if ($schema === false) {
                throw new Exception('فایل database/patient_messages_schema.sql پیدا نشد.');
            }
            $schema = preg_replace('/--[^\n]*/', '', $schema);
            $schema = trim($schema);
            $schema = rtrim($schema, "; \t\r\n");
            if (!preg_match('/^CREATE\s+TABLE\s+patient_messages\b/i', $schema)) {
                throw new Exception('ساختار فایل اسکیما معتبر نیست.');
            }
            if (!@mysqli_query($db, $schema)) {
                throw new Exception('ساخت جدول پیام‌های مراجع ناموفق بود: ' . mysqli_error($db));
            }
            $log[] = array('new', 'جدول «patient_messages» ساخته شد.');
        } else {
            $log[] = array('ok', 'جدول «patient_messages» از قبل وجود داشت؛ تغییری داده نشد.');
        }

        if (!pmsg_table_exists($db, 'patient_messages')) {
            throw new Exception('راستی‌آزمایی ناموفق بود؛ جدول پیام‌ها ساخته نشده است.');
        }
        foreach ($required_columns as $column_name) {
            if (!pmsg_column_exists($db, 'patient_messages', $column_name)) {
                throw new Exception('راستی‌آزمایی ناموفق بود؛ ستون «' . $column_name . '» وجود ندارد.');
            }
        }
        foreach ($required_indexes as $index_name) {
            if (!pmsg_index_exists($db, 'patient_messages', $index_name)) {
                throw new Exception('راستی‌آزمایی ناموفق بود؛ نمایهٔ «' . $index_name . '» وجود ندارد.');
            }
        }
        $log[] = array('ok', 'جدول، ستون‌ها و نمایه‌های پیام مراجع راستی‌آزمایی شدند.');

        if (!migration_applied($db, $version)) {
            db_execute($db, "INSERT INTO migrations (version, applied_at) VALUES (?,?)",
                'ss', array($version, now_dt()));
            $log[] = array('new', 'نسخهٔ ' . $version . ' در جدول migrations ثبت شد.');
            try {
                audit_log_write($db, $actor_person_id, $actor_role_code,
                    'MIGRATION_APPLIED', 'migration', null, array('version' => $version));
            } catch (Throwable $audit_error) {
                try { log_system_error('PATIENT_MESSAGES_UPGRADE_AUDIT', $audit_error); }
                catch (Throwable $ignored) { }
                $log[] = array('fail', 'مهاجرت ثبت شد، اما ثبت رویداد حسابرسی ممکن نشد.');
            }
        } else {
            $log[] = array('ok', 'نسخهٔ ' . $version . ' از قبل ثبت شده بود.');
        }
        $done = true;
    } catch (Exception $ex) {
        $failed = true;
        $log[] = array('fail', $ex->getMessage());
        log_system_error('PATIENT_MESSAGES_UPGRADE', $ex);
    }
}

$table_exists = pmsg_table_exists($db, 'patient_messages');
$version_applied = pmsg_table_exists($db, 'migrations')
    ? migration_applied($db, $version) : false;
$schema_ok = $table_exists;
if ($table_exists) {
    foreach ($required_columns as $column_name) {
        if (!pmsg_column_exists($db, 'patient_messages', $column_name)) {
            $schema_ok = false;
        }
    }
    foreach ($required_indexes as $index_name) {
        if (!pmsg_index_exists($db, 'patient_messages', $index_name)) {
            $schema_ok = false;
        }
    }
}
$ready = $table_exists && $schema_ok && $version_applied;

$page_title = 'فعال‌سازی پیام‌های مراجع';
$active_menu = 'patient_messages_upgrade';
$layout = 'auth';
$wide_card = true;
$brand_subtitle = 'پورتال مراجع — پیام امن';
require __DIR__ . '/templates/header.php';
?>
<h2>✉️ فعال‌سازی پیام‌های امن مراجع</h2>

<?php if ($done) { ?>
  <div class="alert alert-success">
    <strong>✅ مهاجرت نسخهٔ <?php echo e($version); ?> با موفقیت انجام شد.</strong><br>
    صفحهٔ پیام‌های پورتال و اعلان‌های رویدادی اکنون آمادهٔ استفاده است.
    پس از اطمینان از عملکرد، فایل <code>upgrade_patient_messages.php</code> را از هاست حذف کنید.
  </div>
<?php } elseif ($failed) { ?>
  <div class="alert alert-danger">
    <strong>⚠️ مهاجرت کامل نشد.</strong><br>
    علت در گزارش اجرا آمده است. مهاجرت‌های قبلی را دوباره اجرا نکنید.
  </div>
<?php } elseif ($ready) { ?>
  <div class="alert alert-info">نسخهٔ <?php echo e($version); ?> از قبل فعال است؛ اجرای دوباره لازم نیست.</div>
<?php } ?>

<?php if (count($missing_required) > 0) { ?>
  <div class="alert alert-danger">
    جدول‌های پیش‌نیاز پیدا نشدند: <?php echo e(implode('، ', $missing_required)); ?>
  </div>
<?php } ?>
<?php if (!$previous_admin_migration || !$previous_portal_migration
          || !$phase_prerequisites_ready || !$admin_prerequisite_ready) { ?>
  <div class="alert alert-warning">
    پیش‌نیازها آماده نیستند: نسخه‌های ۶.۰.۰ و ۶.۱.۰، نوبت‌دهی فاز ۲، فرم‌های فاز ۴،
    و فعال‌بودن پرونده‌ها و تنظیمات مدیر لازم‌اند. این صفحه مهاجرت‌های قبلی را تکرار نمی‌کند.
  </div>
<?php } ?>
<?php if ($table_exists && !$schema_ok) { ?>
  <div class="alert alert-danger">
    جدول پیام‌ها از قبل وجود دارد، اما همهٔ ستون‌ها یا نمایه‌های موردنیاز را ندارد.
    این صفحه آن را حذف یا بازسازی نمی‌کند؛ دکمهٔ اجرا غیرفعال است تا از آسیب به داده‌ها جلوگیری شود.
    وضعیت را برای پشتیبانی بفرستید.
  </div>
<?php } ?>

<div class="card">
  <div class="card-header">چه چیزی فعال می‌شود؟</div>
  <div class="card-body">
    <ul class="hint-list">
      <li>فقط یک جدول تازه برای پیام‌های قابل‌مشاهدهٔ مراجع می‌سازد؛ داده‌های فعلی حذف یا بازنویسی نمی‌شوند.</li>
      <li>پیام کامل فقط داخل پورتال امن نمایش داده می‌شود. پیامک فقط می‌گوید پیام جدید دارید و لینک ورود را می‌فرستد.</li>
      <li>تأیید پذیرش، ثبت/تأیید نوبت و تخصیص فرم به مراجع پیام رویدادی می‌سازند؛ پیام دستی هم از پروندهٔ مراجع یا پروندهٔ درمانگر فرستاده می‌شود.</li>
      <li>یادداشت محرمانهٔ درمانگر هرگز پیام یا اعلان مراجع نمی‌شود.</li>
      <li>اگر SMS.ir آماده نباشد، پیام داخل پورتال ثبت می‌شود و وضعیت پیامک به‌عنوان ارسال‌نشده ثبت خواهد شد.</li>
    </ul>
    <div class="check-row">
      <span><code>patient_messages</code></span>
      <span class="badge <?php echo $table_exists && $schema_ok ? 'badge-success' : 'badge-muted'; ?>">
        <?php echo !$table_exists ? 'ساخته نشده' : ($schema_ok ? 'ساختار درست است' : 'ساختار ناقص'); ?>
      </span>
    </div>
    <div class="check-row">
      <span>نسخهٔ <code><?php echo e($version); ?></code></span>
      <span class="badge <?php echo $version_applied ? 'badge-success' : 'badge-muted'; ?>">
        <?php echo $version_applied ? 'ثبت شده' : 'ثبت نشده'; ?>
      </span>
    </div>
  </div>
</div>

<?php if (count($log) > 0) { ?>
<div class="card">
  <div class="card-header">گزارش اجرا</div>
  <div class="card-body">
    <?php foreach ($log as $line) { ?>
      <div class="check-row">
        <span><?php echo e($line[1]); ?></span>
        <span class="badge <?php echo $line[0] === 'fail' ? 'badge-danger' : ($line[0] === 'new' ? 'badge-primary' : 'badge-muted'); ?>">
          <?php echo $line[0] === 'fail' ? 'خطا' : ($line[0] === 'new' ? 'انجام شد' : 'بدون تغییر'); ?>
        </span>
      </div>
    <?php } ?>
  </div>
</div>
<?php } ?>

<?php if (!$ready && !$done) { ?>
<div class="card">
  <div class="card-body">
    <p>پیش از اجرا از پایگاه داده در phpMyAdmin یک نسخهٔ پشتیبان Export بگیرید.</p>
    <form method="post" action="upgrade_patient_messages.php" data-guard>
      <?php echo csrf_field(); ?>
      <button type="submit" class="btn btn-primary btn-block"
              <?php echo (count($missing_required) > 0 || !$previous_admin_migration
                  || !$previous_portal_migration || !$phase_prerequisites_ready
                  || !$admin_prerequisite_ready || ($table_exists && !$schema_ok))
                  ? 'disabled' : ''; ?>
              data-confirm="فقط جدول تازهٔ پیام‌های مراجع ساخته شود؟">
        🚀 اجرای مهاجرت ۶.۲.۰
      </button>
    </form>
  </div>
</div>
<?php } ?>
<?php require __DIR__ . '/templates/footer.php'; ?>
