<?php
/* ═══════════════════════════════════════════════════════════════════
 *  جوما — ارتقا به فاز ۵ «مدیریت مالی» (مرحلهٔ ۱: زیرساخت)
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

define('PHASE5_MIGRATION_VERSION', '5.0.0');

$db = $GLOBALS['db'];

auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

$actor_person_id = isset($_SESSION['person_id']) ? (int)$_SESSION['person_id'] : null;
$actor_role_code = isset($_SESSION['active_role_code']) ? $_SESSION['active_role_code'] : ROLE_ADMIN;

$schema_path = __DIR__ . '/database/phase5_finance_schema.sql';
$new_tables  = array('finance_centers', 'finance_bank_accounts');
$required_tables = array('migrations', 'persons', 'accounts', 'role_assignments', 'otp_codes',
                         'lookup_lists', 'lookup_items', 'service_types', 'admissions',
                         'clinical_cases', 'audit_log', 'rooms');

/* ── ابزارها ─────────────────────────────────────────────────────── */

function up5_table_exists($db, $name)
{
    $res = @mysqli_query($db, "SHOW TABLES LIKE '" . mysqli_real_escape_string($db, $name) . "'");
    if (!$res) {
        return false;
    }
    $exists = (mysqli_num_rows($res) > 0);
    mysqli_free_result($res);
    return $exists;
}

function up5_column_exists($db, $table, $column)
{
    $res = @mysqli_query($db, "SHOW COLUMNS FROM `" . mysqli_real_escape_string($db, $table)
        . "` LIKE '" . mysqli_real_escape_string($db, $column) . "'");
    if (!$res) {
        return false;
    }
    $exists = (mysqli_num_rows($res) > 0);
    mysqli_free_result($res);
    return $exists;
}

function up5_index_exists($db, $table, $index)
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

function up5_parse_schema($path)
{
    $sql = @file_get_contents($path);
    if ($sql === false) {
        throw new Exception('فایل database/phase5_finance_schema.sql پیدا نشد.');
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
$already_applied = migration_applied($db, PHASE5_MIGRATION_VERSION);
$status_tables = array();
$missing_required = array();

foreach ($required_tables as $t) {
    if (!up5_table_exists($db, $t)) {
        $missing_required[] = $t;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if (count($missing_required) > 0) {
            throw new Exception('پیش‌نیازها کامل نیستند؛ جدول‌های غایب: '
                . implode('، ', $missing_required)
                . '. ابتدا ارتقای فازهای پیشین را کامل کنید.');
        }
        if (!migration_applied($db, '4.2.0')) {
            throw new Exception('نسخهٔ ۴.۲.۰ هنوز روی این پایگاه داده اجرا نشده است.');
        }

        /* گام ۱ — ساخت جدول‌های مالی */
        $statements = up5_parse_schema($schema_path);
        if (count($statements) !== count($new_tables)) {
            throw new Exception('فایل اسکیما ناقص است؛ ' . count($new_tables)
                . ' جدول انتظار می‌رفت و ' . count($statements) . ' مورد پیدا شد.');
        }
        foreach ($statements as $st) {
            if (up5_table_exists($db, $st['table'])) {
                $log[] = array('ok', 'جدول «' . $st['table'] . '» از قبل وجود داشت — بدون تغییر.');
                continue;
            }
            if (!@mysqli_query($db, $st['sql'])) {
                throw new Exception('ساخت جدول «' . $st['table'] . '» ناموفق بود: '
                    . mysqli_error($db));
            }
            $log[] = array('new', 'جدول «' . $st['table'] . '» ساخته شد.');
        }

        /* گام ۲ — ستون center_id روی rooms */
        if (up5_column_exists($db, 'rooms', 'center_id')) {
            $log[] = array('ok', 'ستون rooms.center_id از قبل وجود داشت.');
        } else {
            if (!@mysqli_query($db, "ALTER TABLE rooms ADD COLUMN center_id BIGINT UNSIGNED NULL AFTER status")) {
                throw new Exception('افزودن ستون rooms.center_id ناموفق بود: ' . mysqli_error($db));
            }
            $log[] = array('new', 'ستون rooms.center_id افزوده شد.');
        }

        /* گام ۳ — ستون room_type روی rooms */
        if (up5_column_exists($db, 'rooms', 'room_type')) {
            $log[] = array('ok', 'ستون rooms.room_type از قبل وجود داشت.');
        } else {
            if (!@mysqli_query($db, "ALTER TABLE rooms ADD COLUMN room_type ENUM('SINGLE','COUPLE') NULL AFTER center_id")) {
                throw new Exception('افزودن ستون rooms.room_type ناموفق بود: ' . mysqli_error($db));
            }
            $log[] = array('new', 'ستون rooms.room_type افزوده شد.');
        }

        /* گام ۴ — ستون hourly_rate روی rooms */
        if (up5_column_exists($db, 'rooms', 'hourly_rate')) {
            $log[] = array('ok', 'ستون rooms.hourly_rate از قبل وجود داشت.');
        } else {
            if (!@mysqli_query($db, "ALTER TABLE rooms ADD COLUMN hourly_rate DECIMAL(12,2) NULL AFTER room_type")) {
                throw new Exception('افزودن ستون rooms.hourly_rate ناموفق بود: ' . mysqli_error($db));
            }
            $log[] = array('new', 'ستون rooms.hourly_rate افزوده شد.');
        }

        /* گام ۵ — نمایهٔ جستجوی اتاق‌های یک مرکز */
        if (up5_index_exists($db, 'rooms', 'idx_rooms_center')) {
            $log[] = array('ok', 'نمایهٔ idx_rooms_center از قبل وجود داشت.');
        } else {
            if (!@mysqli_query($db, "ALTER TABLE rooms ADD KEY idx_rooms_center (center_id)")) {
                throw new Exception('افزودن نمایهٔ idx_rooms_center ناموفق بود: ' . mysqli_error($db));
            }
            $log[] = array('new', 'نمایهٔ idx_rooms_center (center_id) افزوده شد.');
        }

        /* گام ۶ — راستی‌آزمایی */
        foreach ($new_tables as $t) {
            if (!up5_table_exists($db, $t)) {
                throw new Exception('راستی‌آزمایی ناموفق: جدول «' . $t . '» ساخته نشده است.');
            }
        }
        foreach (array('center_id', 'room_type', 'hourly_rate') as $col) {
            if (!up5_column_exists($db, 'rooms', $col)) {
                throw new Exception('راستی‌آزمایی ناموفق: ستون rooms.' . $col . ' وجود ندارد.');
            }
        }
        if (!up5_index_exists($db, 'rooms', 'idx_rooms_center')) {
            throw new Exception('راستی‌آزمایی ناموفق: نمایهٔ idx_rooms_center وجود ندارد.');
        }
        $log[] = array('ok', 'راستی‌آزمایی جدول‌ها، ستون‌ها و نمایه موفق بود.');

        /* گام ۷ — ثبت نسخه */
        if (!migration_applied($db, PHASE5_MIGRATION_VERSION)) {
            db_execute($db, "INSERT INTO migrations (version, applied_at) VALUES (?,?)",
                'ss', array(PHASE5_MIGRATION_VERSION, now_dt()));
            $log[] = array('new', 'نسخهٔ ' . PHASE5_MIGRATION_VERSION . ' در جدول migrations ثبت شد.');
        } else {
            $log[] = array('ok', 'نسخهٔ ' . PHASE5_MIGRATION_VERSION . ' از قبل ثبت شده بود.');
        }

        audit_log_write($db, $actor_person_id, $actor_role_code, 'MIGRATION_APPLIED',
            'migration', null, array('version' => PHASE5_MIGRATION_VERSION));

        $done = true;
        $already_applied = true;
    } catch (Exception $ex) {
        $failed = true;
        $log[] = array('fail', $ex->getMessage());
        log_system_error('PHASE5_UPGRADE', $ex);
    }
}

foreach ($new_tables as $t) {
    $status_tables[$t] = up5_table_exists($db, $t);
}

$page_title = 'ارتقا به فاز ۵ — مدیریت مالی';
$layout = 'auth';
$wide_card = true;
$brand_subtitle = 'فاز ۵ — مدیریت مالی (مرحلهٔ ۱)';
require __DIR__ . '/templates/header.php';
?>
<h2>💰 ارتقا به فاز ۵ — مدیریت مالی (مرحلهٔ ۱)</h2>

<?php if ($done) { ?>
  <div class="alert alert-success">
    <strong>✅ مهاجرت با موفقیت انجام شد.</strong><br>
    جدول‌های <code>finance_centers</code> و <code>finance_bank_accounts</code> ساخته شدند
    و جدول <code>rooms</code> سه ستون تازه گرفت
    (<code>center_id</code>، <code>room_type</code>، <code>hourly_rate</code>).<br>
    <strong>رفتار سامانه تغییری نمی‌کند</strong> — صفحه‌های تازهٔ مالی
    در منوی مدیر ظاهر می‌شوند؛ تا زمانی که شما داده‌ای وارد نکنید،
    تغییری برای کاربران عادی رخ نمی‌دهد.
    پس از بررسی، فایل <code>upgrade_phase5.php</code> را از هاست حذف کنید.
  </div>
<?php } elseif ($failed) { ?>
  <div class="alert alert-danger">
    <strong>⚠️ مهاجرت کامل نشد.</strong><br>
    پیام خطا را در گزارش زیر ببینید. گام‌هایی که پیش‌تر انجام شده‌اند دوباره اجرا
    نمی‌شوند؛ پس از رفع ایراد، کافی است دوباره دکمه را بزنید.
  </div>
<?php } elseif ($already_applied) { ?>
  <div class="alert alert-info">
    ℹ️ نسخهٔ <?php echo e(PHASE5_MIGRATION_VERSION); ?> پیش‌تر روی این پایگاه داده اجرا شده است.
    اجرای دوباره بی‌خطر است.
  </div>
<?php } ?>

<?php if (count($missing_required) > 0) { ?>
  <div class="alert alert-danger">
    پیش‌نیازها کامل نیستند. جدول‌های غایب: <?php echo e(implode('، ', $missing_required)); ?>
  </div>
<?php } ?>

<div class="card">
  <div class="card-header">وضعیت تغییرهای فاز ۵ (مرحلهٔ ۱)</div>
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
      <span><code>rooms.center_id</code></span>
      <span class="badge <?php echo up5_column_exists($db, 'rooms', 'center_id') ? 'badge-success' : 'badge-muted'; ?>">
        <?php echo up5_column_exists($db, 'rooms', 'center_id') ? 'موجود' : 'افزوده نشده'; ?>
      </span>
    </div>
    <div class="check-row">
      <span><code>rooms.room_type</code></span>
      <span class="badge <?php echo up5_column_exists($db, 'rooms', 'room_type') ? 'badge-success' : 'badge-muted'; ?>">
        <?php echo up5_column_exists($db, 'rooms', 'room_type') ? 'موجود' : 'افزوده نشده'; ?>
      </span>
    </div>
    <div class="check-row">
      <span><code>rooms.hourly_rate</code></span>
      <span class="badge <?php echo up5_column_exists($db, 'rooms', 'hourly_rate') ? 'badge-success' : 'badge-muted'; ?>">
        <?php echo up5_column_exists($db, 'rooms', 'hourly_rate') ? 'موجود' : 'افزوده نشده'; ?>
      </span>
    </div>
    <div class="check-row">
      <span><code>idx_rooms_center (center_id)</code></span>
      <span class="badge <?php echo up5_index_exists($db, 'rooms', 'idx_rooms_center') ? 'badge-success' : 'badge-muted'; ?>">
        <?php echo up5_index_exists($db, 'rooms', 'idx_rooms_center') ? 'موجود' : 'افزوده نشده'; ?>
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
      <li><strong>دو</strong> جدول تازه ساخته می‌شود:
          <code>finance_centers</code> و <code>finance_bank_accounts</code>.
          هیچ جدول قدیمی حذف یا بازسازی نمی‌شود.</li>
      <li>سه ستون <strong>افزایشی</strong> به جدول <code>rooms</code> اضافه می‌شود
          (<code>center_id</code>، <code>room_type</code>، <code>hourly_rate</code>)
          به‌همراه یک نمایه. هیچ ردیف موجودی تغییر نمی‌کند.</li>
      <li><strong>هیچ رفتار تازه‌ای فعال نمی‌شود.</strong> پس از این مهاجرت،
          سامانه برای کاربران دقیقاً مثل قبل کار می‌کند؛ فقط بخش
          «مدیریت مالی» در منوی مدیر ظاهر می‌شود.</li>
      <li>اجرای چندباره بی‌خطر است.</li>
    </ul>
    <form method="post" action="upgrade_phase5.php">
      <?php echo csrf_field(); ?>
      <button type="submit" class="btn btn-primary btn-block">🚀 اجرای مهاجرت فاز ۵ (مرحلهٔ ۱)</button>
    </form>
  </div>
</div>
<?php } ?>
<?php require __DIR__ . '/templates/footer.php'; ?>
