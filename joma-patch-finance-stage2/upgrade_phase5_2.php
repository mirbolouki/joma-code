<?php
/* ═══════════════════════════════════════════════════════════════════
 *  جوما — ارتقا به فاز ۵ «مدیریت مالی» (مرحلهٔ ۲: پرداختِ جلسات)
 *
 *  قواعد ایمنی این فایل — همان قواعد ارتقای فازهای ۲، ۳ و ۴:
 *    • فقط «مدیرِ واردشده به سامانه» می‌تواند آن را اجرا کند.
 *    • اجرا فقط با POST و توکن CSRF انجام می‌شود.
 *    • هیچ جدول یا ستونی حذف یا بازسازی نمی‌شود. همهٔ تغییرها افزایشی‌اند.
 *    • اجرای دوباره چیزی را تکرار نمی‌کند (Idempotent).
 *    • نسخهٔ Migration فقط پس از راستی‌آزمایی همهٔ گام‌ها ثبت می‌شود.
 *
 *  در MySQL دستورهای DDL تراکنش‌پذیر نیستند؛ روش بازگشت کامل =
 *  بازگرداندن پشتیبان. پیش از اجرا از phpMyAdmin ← Export پشتیبان بگیرید.
 *
 *  پس از دیدن پیام موفقیت، این فایل را از هاست حذف کنید.
 * ═══════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/includes/bootstrap.php';

define('PHASE5_2_MIGRATION_VERSION', '5.1.0');

$db = $GLOBALS['db'];

auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

$actor_person_id = isset($_SESSION['person_id']) ? (int)$_SESSION['person_id'] : null;
$actor_role_code = isset($_SESSION['active_role_code']) ? $_SESSION['active_role_code'] : ROLE_ADMIN;

$schema_path = __DIR__ . '/database/phase5_2_payments_schema.sql';
$new_tables  = array('finance_session_payments');
$required_tables = array('migrations', 'persons', 'appointments', 'admissions',
                         'finance_centers', 'finance_bank_accounts', 'rooms');

/* ── ابزارها ─────────────────────────────────────────────────────── */

function up52_table_exists($db, $name)
{
    $res = @mysqli_query($db, "SHOW TABLES LIKE '" . mysqli_real_escape_string($db, $name) . "'");
    if (!$res) {
        return false;
    }
    $exists = (mysqli_num_rows($res) > 0);
    mysqli_free_result($res);
    return $exists;
}

function up52_index_exists($db, $table, $index)
{
    $res = @mysqli_query($db, "SHOW INDEX FROM `" . mysqli_real_escape_string($db, $table)
        . "` WHERE Key_name = '" . mysqli_real_escape_string($db, $index) . "'");
    if (!$res) {
        return false;
    }
    $exists = (mysqli_num_rows($res) > 0);
    mysqli_free_result($res);
    return $exists;
}

function up52_parse_schema($path)
{
    $sql = @file_get_contents($path);
    if ($sql === false) {
        throw new Exception('فایل database/phase5_2_payments_schema.sql پیدا نشد.');
    }
    /* توضیح‌های -- حذف می‌شوند */
    $sql = preg_replace('/--[^\\n]*/', '', $sql);
    $parts = explode(';', $sql);
    $out = array();
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if (preg_match('/^CREATE\\s+TABLE\\s+`?([A-Za-z0-9_]+)`?/i', $part, $m)) {
            $out[] = array('table' => $m[1], 'sql' => $part);
        }
    }
    return $out;
}

/* ── اجرا ────────────────────────────────────────────────────────── */

$log = array();
$done = false;
$failed = false;
$already_applied = migration_applied($db, PHASE5_2_MIGRATION_VERSION);
$status_tables = array();
$missing_required = array();

foreach ($required_tables as $t) {
    if (!up52_table_exists($db, $t)) {
        $missing_required[] = $t;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if (count($missing_required) > 0) {
            throw new Exception('پیش‌نیازها کامل نیستند؛ جدول‌های غایب: '
                . implode('، ', $missing_required)
                . '. ابتدا مرحلهٔ ۱ (فاز ۵.۰.۰) را نصب کنید.');
        }
        if (!migration_applied($db, '5.0.0')) {
            throw new Exception('نسخهٔ ۵.۰.۰ (مرحلهٔ ۱) هنوز روی این پایگاه داده اجرا نشده است.');
        }

        /* گام ۱ — ساخت جدول پرداختِ جلسات */
        $statements = up52_parse_schema($schema_path);
        if (count($statements) !== count($new_tables)) {
            throw new Exception('فایل اسکیما ناقص است؛ ' . count($new_tables)
                . ' جدول انتظار می‌رفت و ' . count($statements) . ' مورد پیدا شد.');
        }
        foreach ($statements as $st) {
            if (up52_table_exists($db, $st['table'])) {
                $log[] = array('ok', 'جدول «' . $st['table'] . '» از قبل وجود داشت — بدون تغییر.');
                continue;
            }
            if (!@mysqli_query($db, $st['sql'])) {
                throw new Exception('ساخت جدول «' . $st['table'] . '» ناموفق بود: '
                    . mysqli_error($db));
            }
            $log[] = array('new', 'جدول «' . $st['table'] . '» ساخته شد.');
        }

        /* گام ۲ — راستی‌آزمایی */
        foreach ($new_tables as $t) {
            if (!up52_table_exists($db, $t)) {
                throw new Exception('راستی‌آزمایی ناموفق: جدول «' . $t . '» ساخته نشده است.');
            }
        }
        if (!up52_index_exists($db, 'finance_session_payments', 'uq_fsp_appointment')) {
            throw new Exception('راستی‌آزمایی ناموفق: نمایهٔ uq_fsp_appointment وجود ندارد.');
        }
        $log[] = array('ok', 'راستی‌آزمایی جدول و نمایه‌ها موفق بود.');

        /* گام ۳ — ثبت نسخه */
        if (!migration_applied($db, PHASE5_2_MIGRATION_VERSION)) {
            db_execute($db, "INSERT INTO migrations (version, applied_at) VALUES (?,?)",
                'ss', array(PHASE5_2_MIGRATION_VERSION, now_dt()));
            $log[] = array('new', 'نسخهٔ ' . PHASE5_2_MIGRATION_VERSION . ' در جدول migrations ثبت شد.');
        } else {
            $log[] = array('ok', 'نسخهٔ ' . PHASE5_2_MIGRATION_VERSION . ' از قبل ثبت شده بود.');
        }

        audit_log_write($db, $actor_person_id, $actor_role_code, 'MIGRATION_APPLIED',
            'migration', null, array('version' => PHASE5_2_MIGRATION_VERSION));

        $done = true;
        $already_applied = true;
    } catch (Exception $ex) {
        $failed = true;
        $log[] = array('fail', $ex->getMessage());
        log_system_error('PHASE5_2_UPGRADE', $ex);
    }
}

foreach ($new_tables as $t) {
    $status_tables[$t] = up52_table_exists($db, $t);
}

$page_title = 'ارتقا به فاز ۵ — پرداختِ جلسات';
$layout = 'auth';
$wide_card = true;
$brand_subtitle = 'فاز ۵ — پرداختِ جلسات (مرحلهٔ ۲)';
require __DIR__ . '/templates/header.php';
?>
<h2>💳 ارتقا به فاز ۵ — پرداختِ جلسات (مرحلهٔ ۲)</h2>

<?php if ($done) { ?>
  <div class="alert alert-success">
    <strong>✅ مهاجرت با موفقیت انجام شد.</strong><br>
    جدول <code>finance_session_payments</code> ساخته شد
    (یک رکوردِ پرداخت برای هر جلسه، با بخشِ بیعانه و بخشِ الباقی).<br>
    صفحهٔ تازهٔ «💳 پرداختِ جلسات» در منوی <strong>مدیر و منشی</strong> ظاهر می‌شود.
    پس از بررسی، فایل <code>upgrade_phase5_2.php</code> را از هاست حذف کنید.
  </div>
<?php } elseif ($failed) { ?>
  <div class="alert alert-danger">
    <strong>⚠️ مهاجرت کامل نشد.</strong><br>
    پیام خطا را در گزارش زیر ببینید. گام‌هایی که پیش‌تر انجام شده‌اند دوباره اجرا
    نمی‌شوند؛ پس از رفع ایراد، کافی است دوباره دکمه را بزنید.
  </div>
<?php } elseif ($already_applied) { ?>
  <div class="alert alert-info">
    ℹ️ نسخهٔ <?php echo e(PHASE5_2_MIGRATION_VERSION); ?> پیش‌تر روی این پایگاه داده اجرا شده است.
    اجرای دوباره بی‌خطر است.
  </div>
<?php } ?>

<?php if (count($missing_required) > 0) { ?>
  <div class="alert alert-danger">
    پیش‌نیازها کامل نیستند. جدول‌های غایب: <?php echo e(implode('، ', $missing_required)); ?>
  </div>
<?php } ?>

<div class="card">
  <div class="card-header">وضعیت تغییرهای مرحلهٔ ۲</div>
  <div class="card-body">
    <?php foreach ($status_tables as $t => $exists) { ?>
      <div class="check-row">
        <span><code><?php echo e($t); ?></code></span>
        <span class="badge <?php echo $exists ? 'badge-success' : 'badge-muted'; ?>">
          <?php echo $exists ? 'موجود' : 'ساخته نشده'; ?>
        </span>
      </div>
    <?php } ?>
    <div class="check-row">
      <span><code>uq_fsp_appointment (appointment_id)</code></span>
      <span class="badge <?php echo up52_index_exists($db, 'finance_session_payments', 'uq_fsp_appointment') ? 'badge-success' : 'badge-muted'; ?>">
        <?php echo up52_index_exists($db, 'finance_session_payments', 'uq_fsp_appointment') ? 'موجود' : 'افزوده نشده'; ?>
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
        <span class="badge <?php
          echo $line[0] === 'fail' ? 'badge-danger' : ($line[0] === 'new' ? 'badge-primary' : 'badge-muted'); ?>">
          <?php echo $line[0] === 'fail' ? 'خطا' : ($line[0] === 'new' ? 'انجام شد' : 'بدون تغییر'); ?>
        </span>
      </div>
    <?php } ?>
  </div>
</div>
<?php } ?>

<?php if (!$done) { ?>
<div class="card">
  <div class="card-header">پیش از زدن دکمه</div>
  <div class="card-body">
    <ul class="hint-list">
      <li>از پایگاه داده پشتیبان بگیرید (cPanel ← phpMyAdmin ← Export).</li>
      <li><strong>یک</strong> جدول تازه ساخته می‌شود: <code>finance_session_payments</code>
          (بیعانه + الباقی برای هر جلسه).
          هیچ جدول قدیمی حذف یا بازسازی نمی‌شود.</li>
      <li>هر جلسه دقیقاً یک رکوردِ پرداخت دارد
          (کلیدِ یکتای <code>appointment_id</code>).</li>
      <li><strong>هیچ رفتار تازه‌ای فعال نمی‌شود.</strong> فقط صفحهٔ
          «💳 پرداختِ جلسات» در منوی مدیر و منشی ظاهر می‌شود.</li>
      <li>اجرای چندباره بی‌خطر است.</li>
    </ul>
    <form method="post" action="upgrade_phase5_2.php">
      <?php echo csrf_field(); ?>
      <button type="submit" class="btn btn-primary btn-block">🚀 اجرای مهاجرت مرحلهٔ ۲ (پرداختِ جلسات)</button>
    </form>
  </div>
</div>
<?php } ?>
<?php require __DIR__ . '/templates/footer.php'; ?>
