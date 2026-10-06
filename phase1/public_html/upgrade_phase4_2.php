<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — ارتقا به وصلهٔ ۴.۲.۰ «درخواست نوبت اینترنتی»
 *
 *  قواعد ایمنی این فایل — همان قواعد ارتقای فازهای ۲، ۳ و ۴:
 *    • فقط «مدیرِ واردشده به سامانه» می‌تواند آن را اجرا کند.
 *    • اجرا فقط با POST و توکن CSRF انجام می‌شود.
 *    • هیچ جدول یا ستونی حذف یا بازسازی نمی‌شود. تغییرهای روی
 *      otp_codes هر دو افزایشی‌اند.
 *    • اجرای دوباره چیزی را تکرار نمی‌کند (Idempotent).
 *    • نسخهٔ Migration فقط پس از راستی‌آزمایی همهٔ گام‌ها ثبت می‌شود.
 *
 *  در MySQL دستورهای DDL تراکنش‌پذیر نیستند؛ روش بازگشت کامل =
 *  بازگرداندن پشتیبان. پیش از اجرا از phpMyAdmin ← Export پشتیبان بگیرید.
 *
 *  پس از دیدن پیام موفقیت و بررسی پاورقی، این فایل را از هاست حذف کنید.
 * ═══════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/includes/bootstrap.php';

define('PHASE4_2_MIGRATION_VERSION', '4.2.0');

$db = $GLOBALS['db'];

auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

$actor_person_id = isset($_SESSION['person_id']) ? (int)$_SESSION['person_id'] : null;
$actor_role_code = isset($_SESSION['active_role_code']) ? $_SESSION['active_role_code'] : ROLE_ADMIN;

$schema_path = __DIR__ . '/database/phase4_2_schema.sql';
$new_tables  = array('booking_requests');
$required_tables = array('migrations', 'persons', 'accounts', 'role_assignments', 'otp_codes',
                         'lookup_lists', 'lookup_items', 'service_types', 'admissions',
                         'clinical_cases', 'audit_log');

/* ── ابزارها ─────────────────────────────────────────────────────── */

function up42_table_exists($db, $name)
{
    $res = @mysqli_query($db, "SHOW TABLES LIKE '" . mysqli_real_escape_string($db, $name) . "'");
    if (!$res) {
        return false;
    }
    $exists = (mysqli_num_rows($res) > 0);
    mysqli_free_result($res);
    return $exists;
}

function up42_column_exists($db, $table, $column)
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

function up42_index_exists($db, $table, $index)
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

/** آیا ستون otp_codes.purpose مقدار BOOKING_REQUEST را می‌پذیرد؟ */
function up42_otp_purpose_ready($db)
{
    $res = @mysqli_query($db, "SHOW COLUMNS FROM otp_codes LIKE 'purpose'");
    if (!$res) {
        return false;
    }
    $row = mysqli_fetch_assoc($res);
    mysqli_free_result($res);
    if (!$row || !isset($row['Type'])) {
        return false;
    }
    return (strpos($row['Type'], 'BOOKING_REQUEST') !== false);
}

/**
 * کلید HMAC برای هش IP.
 *
 * بدون این کلید، صفحهٔ عمومی نمی‌تواند سقف IP را اعمال کند. عمداً
 * پیش‌نیازِ سخت است، نه هشدار: اگر اینجا سهل بگیریم، مهاجرت انجام
 * می‌شود، صفحه بالا می‌آید و لایهٔ سقف IP بی‌صدا غایب می‌ماند.
 */
function up42_ip_key_ready()
{
    if (!defined('IP_HASH_KEY')) {
        return false;
    }
    $k = IP_HASH_KEY;
    return (is_string($k) && strlen($k) === 64 && ctype_xdigit($k));
}

function up42_parse_schema($path)
{
    $sql = @file_get_contents($path);
    if ($sql === false) {
        throw new Exception('فایل database/phase4_2_schema.sql پیدا نشد.');
    }
    /* توضیح‌های -- حذف می‌شوند؛ در این فایل هیچ رشتهٔ متنی حاوی -- نیست */
    $sql = preg_replace('/--[^\n]*/', '', $sql);
    $parts = explode(';', $sql);
    $out = array();
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if (preg_match('/^CREATE\s+TABLE\s+`?([A-Za-z0-9_]+)`?/i', $part, $m)) {
            $out[] = array('table' => $m[1], 'sql' => $part);
        }
    }
    return $out;
}

function up42_ensure_list($db, $list_code, $list_title)
{
    $row = db_select_one($db, "SELECT id FROM lookup_lists WHERE list_code = ?", 's', array($list_code));
    if ($row) {
        return (int)$row['id'];
    }
    $res = db_execute($db, "INSERT INTO lookup_lists (list_code, list_title) VALUES (?,?)",
        'ss', array($list_code, $list_title));
    return (int)$res['insert_id'];
}

function up42_ensure_item($db, $list_id, $item_code, $label, $order)
{
    $row = db_select_one($db,
        "SELECT id FROM lookup_items WHERE lookup_list_id = ? AND item_code = ?",
        'is', array((int)$list_id, $item_code));
    if ($row) {
        return false;
    }
    db_execute($db,
        "INSERT INTO lookup_items (lookup_list_id, item_code, label, display_order, is_active)
         VALUES (?,?,?,?,1)",
        'issi', array((int)$list_id, $item_code, $label, (int)$order));
    return true;
}

/* ── دادهٔ مرجعی که وصلهٔ ۴.۲.۰ لازم دارد ───────────────────────────
 *
 * فهرست خدمات عیناً از فرم فعلی mirbolouki.com/form/reservation
 * برداشته شده است، با یک اصلاح: «طزحواره درمانی» ← «طرحواره درمانی».
 *
 * طبق ADR-010 هر دو فهرست از صفحهٔ مشترک مدیریت فهرست‌ها ویرایش
 * می‌شوند. هیچ جدول اختصاصی‌ای ساخته نمی‌شود.
 */
$seed_lists = array(
    array(
        'code'  => 'booking_service_option',
        'title' => 'خدمات قابل انتخاب در فرم نوبت اینترنتی',
        'items' => array(
            array('sex_therapy',         'سکس تراپی',                        10),
            array('couple_therapy',      'زوج درمانی',                       20),
            array('premarital',          'مشاوره پیش از ازدواج',             30),
            array('marital_conflict',    'حل تعارضات زناشویی',               40),
            array('infidelity',          'خیانت',                            50),
            array('divorce',             'مشاوره طلاق',                      60),
            array('psychotherapy',       'روان درمانی',                      70),
            array('individual_coaching', 'مشاوره فردی و کوچینگ',             80),
            array('family_therapy',      'خانواده درمانی',                   90),
            array('schema_therapy',      'طرحواره درمانی',                  100),
            array('anxiety',             'تشخیص و درمان اختلالات اضطرابی',  110),
            array('sexual_conflict',     'تشخیص و حل تعارضات جنسی',         120),
        ),
    ),
    array(
        'code'  => 'booking_reject_reason',
        'title' => 'دلیل رد درخواست نوبت',
        'items' => array(
            array('duplicate',    'درخواست تکراری',                 10),
            array('unreachable',  'پاسخگو نبود یا شماره نادرست بود', 20),
            array('out_of_scope', 'خارج از حوزهٔ خدمات کلینیک',      30),
            array('withdrawn',    'انصراف متقاضی',                   40),
            array('spam',         'اسپم یا درخواست آزمایشی',         50),
        ),
    ),
);

/* ── اجرا ────────────────────────────────────────────────────────── */

$log = array();
$done = false;
$failed = false;
$already_applied = migration_applied($db, PHASE4_2_MIGRATION_VERSION);
$status_tables = array();
$missing_required = array();

foreach ($required_tables as $t) {
    if (!up42_table_exists($db, $t)) {
        $missing_required[] = $t;
    }
}

$suggested_key = bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if (count($missing_required) > 0) {
            throw new Exception('پیش‌نیازها کامل نیستند؛ جدول‌های غایب: '
                . implode('، ', $missing_required)
                . '. ابتدا ارتقای فازهای پیشین را کامل کنید.');
        }
        if (!migration_applied($db, '4.0.0')) {
            throw new Exception('نسخهٔ ۴.۰.۰ هنوز روی این پایگاه داده اجرا نشده است.');
        }
        if (!up42_ip_key_ready()) {
            throw new Exception('ثابت IP_HASH_KEY در includes/config.php تعریف نشده '
                . 'یا ۶۴ نویسهٔ هگز نیست. بدون آن، سقف مبتنی بر IP کار نمی‌کند. '
                . 'مقدار پیشنهادی در کادر زیر آمده است.');
        }

        /* گام ۱ — ساخت جدول قرنطینه */
        $statements = up42_parse_schema($schema_path);
        if (count($statements) !== count($new_tables)) {
            throw new Exception('فایل اسکیما ناقص است؛ ' . count($new_tables)
                . ' جدول انتظار می‌رفت و ' . count($statements) . ' مورد پیدا شد.');
        }
        foreach ($statements as $st) {
            if (up42_table_exists($db, $st['table'])) {
                $log[] = array('ok', 'جدول «' . $st['table'] . '» از قبل وجود داشت — بدون تغییر.');
                continue;
            }
            if (!@mysqli_query($db, $st['sql'])) {
                throw new Exception('ساخت جدول «' . $st['table'] . '» ناموفق بود: '
                    . mysqli_error($db));
            }
            $log[] = array('new', 'جدول «' . $st['table'] . '» ساخته شد.');
        }

        /* گام ۲ — افزودن BOOKING_REQUEST به ENUM کاربردهای کد یک‌بارمصرف */
        if (up42_otp_purpose_ready($db)) {
            $log[] = array('ok', 'ستون otp_codes.purpose از قبل مقدار BOOKING_REQUEST را داشت.');
        } else {
            $alter = "ALTER TABLE otp_codes MODIFY purpose "
                . "ENUM('REGISTER','RESET_PASSWORD','CONFIRM_MOBILE_CHANGE',"
                . "'PATIENT_LOGIN','BOOKING_REQUEST') NOT NULL";
            if (!@mysqli_query($db, $alter)) {
                throw new Exception('افزودن مقدار BOOKING_REQUEST به ستون otp_codes.purpose ناموفق بود: '
                    . mysqli_error($db));
            }
            $log[] = array('new', 'مقدار BOOKING_REQUEST به ستون otp_codes.purpose افزوده شد.');
        }

        /* گام ۳ — ستون ip_hash روی otp_codes */
        if (up42_column_exists($db, 'otp_codes', 'ip_hash')) {
            $log[] = array('ok', 'ستون otp_codes.ip_hash از قبل وجود داشت.');
        } else {
            if (!@mysqli_query($db, "ALTER TABLE otp_codes ADD COLUMN ip_hash CHAR(64) NULL AFTER purpose")) {
                throw new Exception('افزودن ستون otp_codes.ip_hash ناموفق بود: ' . mysqli_error($db));
            }
            $log[] = array('new', 'ستون otp_codes.ip_hash افزوده شد.');
        }

        /* گام ۴ — نمایهٔ شمارش سقف IP
         * بدون این نمایه، هر بررسی سقف یک پویش کامل جدول است و با
         * رشد otp_codes کندتر و کندتر می‌شود. */
        if (up42_index_exists($db, 'otp_codes', 'idx_otp_ip')) {
            $log[] = array('ok', 'نمایهٔ idx_otp_ip از قبل وجود داشت.');
        } else {
            if (!@mysqli_query($db, "ALTER TABLE otp_codes ADD KEY idx_otp_ip (ip_hash, created_at)")) {
                throw new Exception('افزودن نمایهٔ idx_otp_ip ناموفق بود: ' . mysqli_error($db));
            }
            $log[] = array('new', 'نمایهٔ idx_otp_ip (ip_hash, created_at) افزوده شد.');
        }

        /* گام ۵ — دادهٔ مرجع */
        foreach ($seed_lists as $list) {
            $list_id = up42_ensure_list($db, $list['code'], $list['title']);
            $added = 0;
            foreach ($list['items'] as $item) {
                if (up42_ensure_item($db, $list_id, $item[0], $item[1], $item[2])) {
                    $added++;
                }
            }
            $log[] = array($added > 0 ? 'new' : 'ok',
                'فهرست «' . $list['title'] . '»: ' . to_persian_digits($added)
                . ' گزینهٔ تازه افزوده شد.');
        }

        /* گام ۶ — راستی‌آزمایی */
        foreach ($new_tables as $t) {
            if (!up42_table_exists($db, $t)) {
                throw new Exception('راستی‌آزمایی ناموفق: جدول «' . $t . '» ساخته نشده است.');
            }
        }
        if (!up42_otp_purpose_ready($db)) {
            throw new Exception('راستی‌آزمایی ناموفق: ستون otp_codes.purpose مقدار BOOKING_REQUEST را نمی‌پذیرد.');
        }
        if (!up42_column_exists($db, 'otp_codes', 'ip_hash')) {
            throw new Exception('راستی‌آزمایی ناموفق: ستون otp_codes.ip_hash وجود ندارد.');
        }
        if (!up42_index_exists($db, 'otp_codes', 'idx_otp_ip')) {
            throw new Exception('راستی‌آزمایی ناموفق: نمایهٔ idx_otp_ip وجود ندارد.');
        }
        foreach ($seed_lists as $list) {
            foreach ($list['items'] as $item) {
                $row = db_select_one($db,
                    "SELECT li.id FROM lookup_items li
                     INNER JOIN lookup_lists ll ON ll.id = li.lookup_list_id
                     WHERE ll.list_code = ? AND li.item_code = ?",
                    'ss', array($list['code'], $item[0]));
                if (!$row) {
                    throw new Exception('راستی‌آزمایی ناموفق: گزینهٔ مرجع «' . $item[1] . '» ثبت نشد.');
                }
            }
        }
        /* راستی‌آزمایی اصل قرنطینه: هیچ کلید خارجی‌ای از booking_requests
           به persons نباید وجود داشته باشد جز handled_by_person_id. */
        $bad_fk = db_select_all($db,
            "SELECT CONSTRAINT_NAME, COLUMN_NAME
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'booking_requests'
                AND REFERENCED_TABLE_NAME = 'persons'
                AND COLUMN_NAME <> 'handled_by_person_id'", '', array());
        if (is_array($bad_fk) && count($bad_fk) > 0) {
            throw new Exception('راستی‌آزمایی ناموفق: جدول booking_requests یک کلید خارجی '
                . 'غیرمجاز به persons دارد. اصل قرنطینه نقض شده است.');
        }
        $log[] = array('ok', 'راستی‌آزمایی جدول، ستون‌ها، نمایه، داده‌های مرجع و اصل قرنطینه موفق بود.');

        /* گام ۷ — ثبت نسخه */
        if (!migration_applied($db, PHASE4_2_MIGRATION_VERSION)) {
            db_execute($db, "INSERT INTO migrations (version, applied_at) VALUES (?,?)",
                'ss', array(PHASE4_2_MIGRATION_VERSION, now_dt()));
            $log[] = array('new', 'نسخهٔ ' . PHASE4_2_MIGRATION_VERSION . ' در جدول migrations ثبت شد.');
        } else {
            $log[] = array('ok', 'نسخهٔ ' . PHASE4_2_MIGRATION_VERSION . ' از قبل ثبت شده بود.');
        }

        audit_log_write($db, $actor_person_id, $actor_role_code, 'MIGRATION_APPLIED',
            'migration', null, array('version' => PHASE4_2_MIGRATION_VERSION));

        $done = true;
        $already_applied = true;
    } catch (Exception $ex) {
        $failed = true;
        $log[] = array('fail', $ex->getMessage());
        log_system_error('PHASE4_2_UPGRADE', $ex);
    }
}

foreach ($new_tables as $t) {
    $status_tables[$t] = up42_table_exists($db, $t);
}

$page_title = 'ارتقا به وصلهٔ ۴.۲.۰';
$layout = 'auth';
$wide_card = true;
$brand_subtitle = 'وصلهٔ ۴.۲.۰ — درخواست نوبت اینترنتی';
require __DIR__ . '/templates/header.php';
?>
<h2>📨 ارتقا به وصلهٔ ۴.۲.۰ — درخواست نوبت اینترنتی</h2>

<?php if ($done) { ?>
  <div class="alert alert-success">
    <strong>✅ مهاجرت با موفقیت انجام شد.</strong><br>
    جدول قرنطینهٔ <code>booking_requests</code> ساخته شد و دو فهرست مرجع افزوده شد.<br>
    <strong>هنوز هیچ صفحه‌ای فعال نیست</strong> — صفحهٔ عمومی و میز تریاژ منشی در
    گام بعدی نصب می‌شوند. این عمدی است: ساختار پیش از کد.<br>
    پس از بررسی، فایل <code>upgrade_phase4_2.php</code> را از هاست حذف کنید.
  </div>
<?php } elseif ($failed) { ?>
  <div class="alert alert-danger">
    <strong>⚠️ مهاجرت کامل نشد.</strong><br>
    پیام خطا را در گزارش زیر ببینید. گام‌هایی که پیش‌تر انجام شده‌اند دوباره اجرا
    نمی‌شوند؛ پس از رفع ایراد، کافی است دوباره دکمه را بزنید.
  </div>
<?php } elseif ($already_applied) { ?>
  <div class="alert alert-info">
    ℹ️ نسخهٔ <?php echo e(PHASE4_2_MIGRATION_VERSION); ?> پیش‌تر روی این پایگاه داده اجرا شده است.
    اجرای دوباره بی‌خطر است.
  </div>
<?php } ?>

<?php if (count($missing_required) > 0) { ?>
  <div class="alert alert-danger">
    پیش‌نیازها کامل نیستند. جدول‌های غایب: <?php echo e(implode('، ', $missing_required)); ?>
  </div>
<?php } ?>

<?php if (!up42_ip_key_ready()) { ?>
  <div class="alert alert-danger">
    <strong>🔑 پیش از اجرا: ثابت <code>IP_HASH_KEY</code> را بسازید.</strong><br>
    این کلید، IP بازدیدکننده را به یک هش برگشت‌ناپذیر تبدیل می‌کند. بدون آن
    IP خام ذخیره می‌شد یا سقف IP اصلاً کار نمی‌کرد؛ پس مهاجرت بدون آن اجرا نمی‌شود.<br><br>
    خط زیر را به انتهای <code>includes/config.php</code> اضافه کنید
    (پیش از <code>?&gt;</code> پایانی، اگر وجود دارد):
    <pre class="mono" style="white-space:pre-wrap;word-break:break-all;margin-top:8px">define('IP_HASH_KEY', '<?php echo e($suggested_key); ?>');</pre>
    <span class="text-small">این مقدار همین حالا به‌صورت تصادفی ساخته شد (۳۲ بایت، ۶۴ نویسهٔ هگز).
    با هر بار بارگذاری صفحه عوض می‌شود، پس همین یکی را کپی کنید.
    اگر بعداً تغییرش دهید، همهٔ هش‌های قبلی بی‌معنا می‌شوند و سقف‌های در جریان صفر می‌شوند —
    که بی‌خطر است ولی باید بدانید.</span>
  </div>
<?php } ?>

<div class="card">
  <div class="card-header">وضعیت تغییرهای وصلهٔ ۴.۲.۰</div>
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
      <span><code>otp_codes.purpose = BOOKING_REQUEST</code></span>
      <span class="badge <?php echo up42_otp_purpose_ready($db) ? 'badge-success' : 'badge-muted'; ?>">
        <?php echo up42_otp_purpose_ready($db) ? 'آماده' : 'افزوده نشده'; ?>
      </span>
    </div>
    <div class="check-row">
      <span><code>otp_codes.ip_hash</code></span>
      <span class="badge <?php echo up42_column_exists($db, 'otp_codes', 'ip_hash') ? 'badge-success' : 'badge-muted'; ?>">
        <?php echo up42_column_exists($db, 'otp_codes', 'ip_hash') ? 'موجود' : 'افزوده نشده'; ?>
      </span>
    </div>
    <div class="check-row">
      <span><code>idx_otp_ip (ip_hash, created_at)</code></span>
      <span class="badge <?php echo up42_index_exists($db, 'otp_codes', 'idx_otp_ip') ? 'badge-success' : 'badge-muted'; ?>">
        <?php echo up42_index_exists($db, 'otp_codes', 'idx_otp_ip') ? 'موجود' : 'افزوده نشده'; ?>
      </span>
    </div>
    <div class="check-row">
      <span><code>IP_HASH_KEY</code> در <code>config.php</code></span>
      <span class="badge <?php echo up42_ip_key_ready() ? 'badge-success' : 'badge-danger'; ?>">
        <?php echo up42_ip_key_ready() ? 'تعریف شده' : 'تعریف نشده'; ?>
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
      <li><strong>یک</strong> جدول تازه ساخته می‌شود: <code>booking_requests</code>.
          هیچ جدول قدیمی حذف یا بازسازی نمی‌شود.</li>
      <li>دو تغییر <strong>افزایشی</strong> روی <code>otp_codes</code>:
          یک مقدار تازه در ENUM و یک ستون <code>NULL</code>‌پذیر با نمایه‌اش.
          هیچ ردیف موجودی تغییر نمی‌کند.</li>
      <li>۱۲ خدمت و ۵ دلیل رد، در <code>lookup_items</code> افزوده می‌شود؛
          همگی بعداً از صفحهٔ فهرست‌های مرجع قابل ویرایش‌اند.</li>
      <li><strong>هیچ صفحهٔ تازه‌ای فعال نمی‌شود.</strong> پس از این مهاجرت،
          رفتار سامانه برای کاربران دقیقاً مثل قبل است.</li>
      <li>اجرای چندباره بی‌خطر است.</li>
    </ul>
    <form method="post" action="upgrade_phase4_2.php">
      <?php echo csrf_field(); ?>
      <button type="submit" class="btn btn-primary btn-block"
              <?php echo up42_ip_key_ready() ? '' : 'disabled'; ?>>
        🚀 اجرای مهاجرت ۴.۲.۰
      </button>
    </form>
    <?php if (!up42_ip_key_ready()) { ?>
      <p class="text-small text-center text-muted" style="margin-top:8px">
        دکمه تا تعریف <code>IP_HASH_KEY</code> غیرفعال است.
      </p>
    <?php } ?>
  </div>
</div>
<?php } ?>
<?php require __DIR__ . '/templates/footer.php'; ?>
