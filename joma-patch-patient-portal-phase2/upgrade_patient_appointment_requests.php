<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — ارتقا به پورتال مراجع، فاز ۲: درخواست‌های نوبت
 *
 *  فقط مدیر مجاز است. اجرا با POST و CSRF انجام می‌شود. مهاجرت فقط
 *  یک جدول تازه می‌سازد و پس از راستی‌آزمایی، نسخهٔ ۶.۱.۰ را ثبت می‌کند.
 *  اجرای دوبارهٔ همین مهاجرت بی‌خطر است.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/includes/bootstrap.php';

auth_require_active_session($GLOBALS['db']);
require_role(array(ROLE_ADMIN));

$db = $GLOBALS['db'];
$version = PATIENT_APPOINTMENT_REQUEST_MIGRATION_VERSION;
$actor_person_id = isset($_SESSION['person_id']) ? (int)$_SESSION['person_id'] : null;
$actor_role_code = isset($_SESSION['active_role_code']) ? $_SESSION['active_role_code'] : ROLE_ADMIN;
$schema_path = __DIR__ . '/database/patient_appointment_requests_schema.sql';
$required_tables = array('migrations', 'persons', 'admissions', 'clinical_cases',
                         'appointments', 'service_types', 'rooms', 'audit_log');
$required_columns = array('id', 'public_id', 'patient_person_id', 'admission_id', 'appointment_id',
    'request_type', 'preferred_day_code', 'preferred_window_code', 'preferred_text',
    'patient_note', 'status', 'patient_response', 'staff_note', 'handled_by_person_id',
    'handled_at', 'created_at', 'updated_at');
$required_indexes = array('PRIMARY', 'public_id', 'idx_par_patient_created',
                          'idx_par_patient_status', 'idx_par_status_created', 'idx_par_appointment_status',
                          'idx_par_admission_status');
$log = array();
$missing_required = array();
$done = false;
$failed = false;

function ppar_table_exists($db, $name)
{
    return db_select_one($db,
        "SELECT 1 AS present FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
        's', array((string)$name)) !== null;
}

function ppar_column_exists($db, $table, $column)
{
    return db_select_one($db,
        "SELECT 1 AS present FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
        'ss', array((string)$table, (string)$column)) !== null;
}

function ppar_index_exists($db, $table, $index)
{
    return db_select_one($db,
        "SELECT 1 AS present FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1",
        'ss', array((string)$table, (string)$index)) !== null;
}

foreach ($required_tables as $table_name) {
    if (!ppar_table_exists($db, $table_name)) {
        $missing_required[] = $table_name;
    }
}
$previous_migration_applied = ppar_table_exists($db, 'migrations')
    ? migration_applied($db, '6.0.0') : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if (count($missing_required) > 0) {
            throw new Exception('پیش‌نیازها کامل نیستند؛ جدول‌های غایب: '
                . implode('، ', $missing_required) . '. مهاجرت‌های قبلی را دوباره اجرا نکنید.');
        }
        if (!$previous_migration_applied) {
            throw new Exception('ارتقای ۶.۰.۰ پرونده‌ها و تنظیمات مدیر ثبت نشده است. '
                . 'ابتدا وضعیت همان مهاجرت را با مدیر سامانه بررسی کنید؛ آن را بی‌دلیل دوباره اجرا نکنید.');
        }

        if (!ppar_table_exists($db, 'patient_appointment_requests')) {
            $schema = @file_get_contents($schema_path);
            if ($schema === false) {
                throw new Exception('فایل database/patient_appointment_requests_schema.sql پیدا نشد.');
            }
            $schema = preg_replace('/--[^\n]*/', '', $schema);
            $schema = trim($schema);
            $schema = rtrim($schema, "; \t\r\n");
            if (!preg_match('/^CREATE\s+TABLE\s+patient_appointment_requests\b/i', $schema)) {
                throw new Exception('ساختار فایل اسکیما معتبر نیست.');
            }
            if (!@mysqli_query($db, $schema)) {
                throw new Exception('ساخت جدول درخواست‌های نوبت ناموفق بود: ' . mysqli_error($db));
            }
            $log[] = array('new', 'جدول «patient_appointment_requests» ساخته شد.');
        } else {
            $log[] = array('ok', 'جدول «patient_appointment_requests» از قبل وجود داشت؛ تغییری داده نشد.');
        }

        if (!ppar_table_exists($db, 'patient_appointment_requests')) {
            throw new Exception('راستی‌آزمایی ناموفق بود؛ جدول درخواست‌های نوبت ساخته نشده است.');
        }
        foreach ($required_columns as $column_name) {
            if (!ppar_column_exists($db, 'patient_appointment_requests', $column_name)) {
                throw new Exception('راستی‌آزمایی ناموفق بود؛ ستون «' . $column_name . '» وجود ندارد.');
            }
        }
        foreach ($required_indexes as $index_name) {
            if (!ppar_index_exists($db, 'patient_appointment_requests', $index_name)) {
                throw new Exception('راستی‌آزمایی ناموفق بود؛ نمایهٔ «' . $index_name . '» وجود ندارد.');
            }
        }
        $log[] = array('ok', 'جدول، ستون‌ها و نمایه‌های لازم راستی‌آزمایی شدند.');

        if (!migration_applied($db, $version)) {
            db_execute($db, "INSERT INTO migrations (version, applied_at) VALUES (?,?)",
                'ss', array($version, now_dt()));
            $log[] = array('new', 'نسخهٔ ' . $version . ' در جدول migrations ثبت شد.');
            audit_log_write($db, $actor_person_id, $actor_role_code, 'MIGRATION_APPLIED',
                'migration', null, array('version' => $version));
        } else {
            $log[] = array('ok', 'نسخهٔ ' . $version . ' از قبل ثبت شده بود.');
        }
        $done = true;
    } catch (Exception $ex) {
        $failed = true;
        $log[] = array('fail', $ex->getMessage());
        log_system_error('PATIENT_APPOINTMENT_REQUEST_UPGRADE', $ex);
    }
}

$table_exists = ppar_table_exists($db, 'patient_appointment_requests');
$version_applied = false;
if (ppar_table_exists($db, 'migrations')) {
    $version_applied = migration_applied($db, $version);
}
$ready = $table_exists && $version_applied;

$page_title = 'فعال‌سازی درخواست‌های نوبت مراجع';
$layout = 'auth';
$wide_card = true;
$brand_subtitle = 'پورتال مراجع — فاز ۲';
require __DIR__ . '/templates/header.php';
?>
<h2>📨 فعال‌سازی درخواست‌های نوبت مراجع</h2>

<?php if ($done) { ?>
  <div class="alert alert-success">
    <strong>✅ مهاجرت فاز ۲ با موفقیت انجام شد.</strong><br>
    درخواست نوبت، لغو و جابه‌جایی اکنون در حساب مراجع و صف پرسنل قابل‌استفاده است.
    پس از بررسی، فایل <code>upgrade_patient_appointment_requests.php</code> را از هاست حذف کنید.
  </div>
<?php } elseif ($failed) { ?>
  <div class="alert alert-danger">
    <strong>⚠️ مهاجرت کامل نشد.</strong><br>
    علت در گزارش اجرا آمده است. هیچ مهاجرت قبلی را دوباره اجرا نکنید.
  </div>
<?php } elseif ($ready) { ?>
  <div class="alert alert-info">نسخهٔ <?php echo e($version); ?> از قبل روی این پایگاه داده فعال است؛ مهاجرت دوباره لازم نیست.</div>
<?php } ?>

<?php if (count($missing_required) > 0) { ?>
  <div class="alert alert-danger">
    پیش‌نیازها کامل نیستند. جدول‌های غایب: <?php echo e(implode('، ', $missing_required)); ?>
  </div>
<?php } ?>
<?php if (!$previous_migration_applied) { ?>
  <div class="alert alert-warning">
    نسخهٔ ۶.۰.۰ در جدول مهاجرت‌ها ثبت نشده است. این صفحه مهاجرت قبلی را تکرار نمی‌کند؛
    وضعیت نصب قبلی را با مدیر سامانه بررسی کنید.
  </div>
<?php } ?>

<div class="card">
  <div class="card-header">این مهاجرت چه می‌کند؟</div>
  <div class="card-body">
    <ul class="hint-list">
      <li>فقط یک جدول تازه برای درخواست‌های مراجع می‌سازد؛ جدول‌های قبلی حذف یا بازنویسی نمی‌شوند.</li>
      <li>هیچ نوبتی خودکار ایجاد، لغو یا جابه‌جا نمی‌شود؛ مدیر یا منشی درخواست را در تقویم معمول پردازش می‌کند.</li>
      <li>اجرای دوبارهٔ همین مهاجرت، جدول یا اطلاعات موجود را تکراری نمی‌کند.</li>
      <li>این قابلیت به ثابت <code>IP_HASH_KEY</code> وابسته نیست و مهاجرت‌های قبلی را دوباره اجرا نمی‌کند.</li>
    </ul>
    <div class="check-row">
      <span><code>patient_appointment_requests</code></span>
      <span class="badge <?php echo $table_exists ? 'badge-success' : 'badge-muted'; ?>">
        <?php echo $table_exists ? 'موجود' : 'ساخته نشده'; ?>
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
    <form method="post" action="upgrade_patient_appointment_requests.php">
      <?php echo csrf_field(); ?>
      <button type="submit" class="btn btn-primary btn-block"
              <?php echo (count($missing_required) > 0 || !$previous_migration_applied) ? 'disabled' : ''; ?>>
        🚀 اجرای مهاجرت جدید فاز ۲
      </button>
    </form>
  </div>
</div>
<?php } ?>
<?php require __DIR__ . '/templates/footer.php'; ?>
