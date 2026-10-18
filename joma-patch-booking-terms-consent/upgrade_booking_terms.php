<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — مهاجرتِ ثبت ضوابط درخواست نوبت، نسخهٔ ۶.۳.۰
 *  فقط مدیر؛ اجرا با POST و CSRF؛ یک جدول تازه و بدون بازسازی داده‌ها.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/includes/bootstrap.php';

auth_require_active_session($GLOBALS['db']);
require_role(array(ROLE_ADMIN));

$db = $GLOBALS['db'];
$version = BOOKING_TERMS_MIGRATION_VERSION;
$actor_person_id = isset($_SESSION['person_id']) ? (int)$_SESSION['person_id'] : null;
$actor_role_code = isset($_SESSION['active_role_code']) ? $_SESSION['active_role_code'] : ROLE_ADMIN;
$schema_path = __DIR__ . '/database/booking_terms_acceptances_schema.sql';
$required_tables = array('migrations', 'booking_requests', 'admissions', 'audit_log');
$required_columns = array(
    'id', 'public_id', 'booking_request_id', 'terms_version', 'terms_sha256',
    'terms_snapshot', 'ack_general', 'ack_privacy_recording',
    'ack_deposit_cancellation', 'ack_adult_independence',
    'accepted_mobile', 'accepted_at'
);
$required_indexes = array(
    'PRIMARY', 'uq_booking_terms_public_id', 'uq_booking_terms_request',
    'idx_booking_terms_accepted_at'
);
$log = array();
$missing_required = array();
$done = false;
$failed = false;

function bta_upgrade_table_exists($db, $name)
{
    return db_select_one($db,
        "SELECT 1 AS present FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
        's', array((string)$name)) !== null;
}

function bta_upgrade_column_exists($db, $table, $column)
{
    return db_select_one($db,
        "SELECT 1 AS present FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1",
        'ss', array((string)$table, (string)$column)) !== null;
}

function bta_upgrade_index_exists($db, $table, $index)
{
    return db_select_one($db,
        "SELECT 1 AS present FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1",
        'ss', array((string)$table, (string)$index)) !== null;
}

foreach ($required_tables as $table_name) {
    if (!bta_upgrade_table_exists($db, $table_name)) {
        $missing_required[] = $table_name;
    }
}
$previous_booking_migration = bta_upgrade_table_exists($db, 'migrations')
    && migration_applied($db, '4.2.0');
$phase_prerequisites_ready = phase4_2_ready($db) && $previous_booking_migration;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if (count($missing_required) > 0) {
            throw new Exception('پیش‌نیازها کامل نیستند؛ جدول‌های غایب: '
                . implode('، ', $missing_required) . '. مهاجرت‌های قبلی را دوباره اجرا نکنید.');
        }
        if (!$phase_prerequisites_ready) {
            throw new Exception('پیش‌نیاز درخواست نوبتِ نسخهٔ ۴.۲.۰ فعال نیست. '
                . 'مهاجرت قبلی را دوباره اجرا نکنید؛ ابتدا وضعیت نصب را بررسی کنید.');
        }

        if (!bta_upgrade_table_exists($db, 'booking_terms_acceptances')) {
            $schema = @file_get_contents($schema_path);
            if ($schema === false) {
                throw new Exception('فایل database/booking_terms_acceptances_schema.sql پیدا نشد.');
            }
            $schema = preg_replace('/--[^\n]*/', '', $schema);
            $schema = trim($schema);
            $schema = rtrim($schema, "; \t\r\n");
            if (!preg_match('/^CREATE\s+TABLE\s+booking_terms_acceptances\b/i', $schema)) {
                throw new Exception('ساختار فایل اسکیما معتبر نیست.');
            }
            if (!@mysqli_query($db, $schema)) {
                throw new Exception('ساخت جدول پذیرش ضوابط ناموفق بود: ' . mysqli_error($db));
            }
            $log[] = array('new', 'جدولِ «booking_terms_acceptances» ساخته شد.');
        } else {
            $log[] = array('ok', 'جدولِ «booking_terms_acceptances» از قبل وجود داشت؛ تغییری داده نشد.');
        }

        foreach ($required_columns as $column_name) {
            if (!bta_upgrade_column_exists($db, 'booking_terms_acceptances', $column_name)) {
                throw new Exception('راستی‌آزمایی ناموفق بود؛ ستونِ «' . $column_name . '» وجود ندارد. '
                    . 'این صفحه جدول موجود را حذف یا بازسازی نمی‌کند.');
            }
        }
        foreach ($required_indexes as $index_name) {
            if (!bta_upgrade_index_exists($db, 'booking_terms_acceptances', $index_name)) {
                throw new Exception('راستی‌آزمایی ناموفق بود؛ نمایهٔ «' . $index_name . '» وجود ندارد. '
                    . 'این صفحه جدول موجود را حذف یا بازسازی نمی‌کند.');
            }
        }
        $log[] = array('ok', 'ستون‌ها و نمایه‌های جدول پذیرش ضوابط راستی‌آزمایی شدند.');

        if (!migration_applied($db, $version)) {
            db_execute($db, "INSERT INTO migrations (version, applied_at) VALUES (?,?)",
                'ss', array($version, now_dt()));
            $log[] = array('new', 'نسخهٔ ' . $version . ' در جدول migrations ثبت شد.');
            try {
                audit_log_write($db, $actor_person_id, $actor_role_code,
                    'MIGRATION_APPLIED', 'migration', null, array('version' => $version));
            } catch (Throwable $audit_error) {
                try { log_system_error('BOOKING_TERMS_UPGRADE_AUDIT', $audit_error); }
                catch (Throwable $ignored) { }
                $log[] = array('fail', 'مهاجرت ثبت شد، اما رویداد حسابرسی ثبت نشد.');
            }
        } else {
            $log[] = array('ok', 'نسخهٔ ' . $version . ' از قبل ثبت شده بود.');
        }
        $done = true;
    } catch (Throwable $ex) {
        $failed = true;
        $log[] = array('fail', $ex->getMessage());
        try { log_system_error('BOOKING_TERMS_UPGRADE', $ex); }
        catch (Throwable $ignored) { }
    }
}

$table_exists = bta_upgrade_table_exists($db, 'booking_terms_acceptances');
$version_applied = bta_upgrade_table_exists($db, 'migrations')
    ? migration_applied($db, $version) : false;
$schema_ok = $table_exists;
if ($table_exists) {
    foreach ($required_columns as $column_name) {
        if (!bta_upgrade_column_exists($db, 'booking_terms_acceptances', $column_name)) {
            $schema_ok = false;
        }
    }
    foreach ($required_indexes as $index_name) {
        if (!bta_upgrade_index_exists($db, 'booking_terms_acceptances', $index_name)) {
            $schema_ok = false;
        }
    }
}
$ready = $table_exists && $schema_ok && $version_applied;
$can_run = count($missing_required) === 0 && $phase_prerequisites_ready
    && (!$table_exists || $schema_ok) && !$ready;

$page_title = 'فعال‌سازی ثبت ضوابط';
$active_menu = 'booking_terms_upgrade';
$layout = 'auth';
$wide_card = true;
$brand_subtitle = 'درخواست نوبت — ثبت نسخهٔ ضوابط';
require __DIR__ . '/templates/header.php';
?>
<h2>📜 فعال‌سازی ثبت ضوابط درخواست نوبت</h2>

<?php if ($done) { ?>
  <div class="alert alert-success">
    <strong>✅ مهاجرت نسخهٔ <?php echo e($version); ?> با موفقیت انجام شد.</strong><br>
    جدول تازه ساخته و بررسی شد. هیچ جدول قبلی حذف یا بازسازی نشد.
    تا وقتی متن ضوابط جداگانه تأیید و نسخهٔ زنده تنظیم نشود، فرم عمومی همچنان غیرفعال می‌ماند.
  </div>
<?php } elseif ($failed) { ?>
  <div class="alert alert-danger">
    <strong>⚠️ مهاجرت کامل نشد.</strong><br>
    علت در گزارش اجرا آمده است. مهاجرت‌های قبلی را بی‌دلیل دوباره اجرا نکنید.
  </div>
<?php } elseif ($ready) { ?>
  <div class="alert alert-info">مهاجرت <?php echo e($version); ?> از قبل فعال است؛ اجرای دوباره لازم نیست.</div>
<?php } ?>

<?php if (count($missing_required) > 0) { ?>
  <div class="alert alert-danger">
    جدول‌های پیش‌نیاز پیدا نشدند: <?php echo e(implode('، ', $missing_required)); ?>
  </div>
<?php } ?>
<?php if (!$phase_prerequisites_ready) { ?>
  <div class="alert alert-warning">
    مهاجرت درخواست نوبتِ نسخهٔ ۴.۲.۰ باید از قبل فعال باشد. این صفحه آن مهاجرت را اجرا یا تکرار نمی‌کند.
  </div>
<?php } ?>
<?php if ($table_exists && !$schema_ok) { ?>
  <div class="alert alert-danger">
    جدول پذیرش ضوابط از قبل وجود دارد، اما ساختارش کامل نیست. این صفحه آن را حذف یا بازسازی نمی‌کند؛
    دکمهٔ اجرا غیرفعال است تا از آسیب به داده‌ها جلوگیری شود.
  </div>
<?php } ?>
<?php if (!booking_terms_document_is_approved()) { ?>
  <div class="alert alert-warning">
    متن همراه این پچ پیش‌نویس است. این مهاجرت فقط جدول را می‌سازد و فرم عمومی را فعال نمی‌کند؛
    پیش از استفادهٔ زنده، متن باید تأیید و بازبینی حقوقی شود.
  </div>
<?php } ?>

<div class="card">
  <div class="card-header">پیش از اجرا</div>
  <div class="card-body">
    <ul class="hint-list">
      <li>پیش از تغییر، از پایگاه داده پشتیبان بگیرید: cPanel ← phpMyAdmin ← Export.</li>
      <li>فقط یک جدول تازه، <code>booking_terms_acceptances</code>، ساخته می‌شود؛ ردیف‌های قبلی دست‌نخورده می‌مانند.</li>
      <li>نسخهٔ متن، hash، snapshot دقیق، چهار تیک، شمارهٔ تأییدشده و زمان ثبت نگهداری می‌شود؛ کد OTP خام ذخیره نمی‌شود.</li>
      <li>مهاجرت‌های قبلی را دوباره اجرا نکنید. اجرای دوبارهٔ همین صفحه پس از موفقیت تغییری در جدول نمی‌دهد.</li>
    </ul>
    <form method="post" action="upgrade_booking_terms.php">
      <?php echo csrf_field(); ?>
      <button type="submit" class="btn btn-primary btn-block" <?php echo $can_run ? '' : 'disabled'; ?>>
        اجرای مهاجرت ۶.۳.۰
      </button>
    </form>
  </div>
</div>

<?php if (count($log) > 0) { ?>
  <div class="card">
    <div class="card-header">گزارش اجرا</div>
    <div class="card-body"><ul class="hint-list">
      <?php foreach ($log as $item) { ?>
        <li><?php echo $item[0] === 'fail' ? '⚠️ ' : ($item[0] === 'new' ? '✅ ' : '✓ '); ?>
            <?php echo e($item[1]); ?></li>
      <?php } ?>
    </ul></div>
  </div>
<?php } ?>

<p class="form-hint">پس از اطمینان از اجرا، فایل <code>upgrade_booking_terms.php</code> را از هاست حذف کنید.</p>
<?php require __DIR__ . '/templates/footer.php'; ?>
