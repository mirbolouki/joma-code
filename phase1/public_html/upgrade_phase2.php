<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — ارتقا به فاز ۲ (نوبت‌دهی و تقویم)
 *
 *  قواعد ایمنی این فایل:
 *    • فقط «مدیرِ واردشده به سامانه» می‌تواند آن را اجرا کند.
 *    • اجرا فقط با POST و توکن CSRF انجام می‌شود (باز کردن نشانی کافی نیست).
 *    • هیچ جدول یا ستونی از فاز ۱ حذف، بازسازی یا تغییر داده نمی‌شود.
 *    • فایل‌های config.php و install.lock و تنظیمات پیامک دست نمی‌خورند.
 *    • اجرای دوباره، جدول یا دادهٔ تکراری نمی‌سازد (Idempotent).
 *    • نسخهٔ Migration فقط پس از موفقیت و راستی‌آزمایی همهٔ گام‌ها ثبت می‌شود.
 *    • هیچ «اتاق نمونه» روی سرور واقعی ساخته نمی‌شود.
 *
 *  هشدار فنی مهم:
 *    در MySQL دستورهای ساخت جدول (DDL) تراکنش‌پذیر نیستند؛ یعنی اگر گام
 *    چهارم شکست بخورد، سه جدولِ ساخته‌شده به‌خودی‌خود برنمی‌گردند.
 *    به همین دلیل این برنامه «گام‌به‌گام و با بررسی وجود» کار می‌کند:
 *    کافی است ایراد را برطرف کنید و دوباره دکمه را بزنید؛ از همان‌جا
 *    که مانده بود ادامه می‌دهد. روش بازگشت کامل = بازگرداندن پشتیبان.
 *
 *  پس از دیدن پیام موفقیت، این فایل را از هاست حذف کنید.
 * ═══════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/includes/bootstrap.php';

define('PHASE2_MIGRATION_VERSION', '2.0.0');

$db = $GLOBALS['db'];

/* ── کنترل دسترسی: فقط مدیرِ واردشده ─────────────────────────────── */
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

$actor_person_id = isset($_SESSION['person_id']) ? (int)$_SESSION['person_id'] : null;
$actor_role_code = isset($_SESSION['active_role_code']) ? $_SESSION['active_role_code'] : ROLE_ADMIN;

$schema_path = __DIR__ . '/database/phase2_schema.sql';
$new_tables = array('rooms', 'therapist_service_tariffs', 'therapist_absence_rules',
                    'appointments', 'appointment_holds');
$phase1_tables = array('migrations', 'persons', 'accounts', 'role_assignments', 'otp_codes',
                       'lookup_lists', 'lookup_items', 'service_types', 'admissions',
                       'clinical_cases', 'audit_log');

/* ── ابزارهای کوچک ───────────────────────────────────────────────── */

function up_table_exists($db, $name)
{
    $res = @mysqli_query($db, "SHOW TABLES LIKE '" . mysqli_real_escape_string($db, $name) . "'");
    if (!$res) {
        return false;
    }
    $exists = (mysqli_num_rows($res) > 0);
    mysqli_free_result($res);
    return $exists;
}

/** استخراج دستورهای CREATE TABLE از فایل اسکیمای فاز ۲ */
function up_parse_schema($path)
{
    $sql = @file_get_contents($path);
    if ($sql === false) {
        throw new Exception('فایل database/phase2_schema.sql پیدا نشد.');
    }
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
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

/** افزودن یک فهرست مرجع در صورت نبودن؛ بازگشت: شناسهٔ فهرست */
function up_ensure_list($db, $list_code, $list_title)
{
    $row = db_select_one($db, "SELECT id FROM lookup_lists WHERE list_code = ?", 's', array($list_code));
    if ($row) {
        return (int)$row['id'];
    }
    $res = db_execute($db, "INSERT INTO lookup_lists (list_code, list_title) VALUES (?,?)",
        'ss', array($list_code, $list_title));
    return (int)$res['insert_id'];
}

/** افزودن یک قلم مرجع در صورت نبودن؛ بازگشت: true اگر تازه ساخته شد */
function up_ensure_item($db, $list_id, $item_code, $label, $order)
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

/* ── دادهٔ مرجعی که فاز ۲ لازم دارد (فاز ۱ ندارد) ────────────────── */
$seed_lists = array(
    array(
        'code'  => 'absence_reason',
        'title' => 'دلیل عدم حضور درمانگر',
        'items' => array(
            array('cr_sick_leave', 'مرخصی بیماری', 10),
            array('cr_vacation',   'تعطیلات سالانه', 20),
            array('cr_training',   'دورهٔ تأهیل', 30),
        ),
    ),
    array(
        'code'  => 'cancellation_reason',
        'title' => 'دلیل لغو نوبت',
        'items' => array(
            array('ca_patient_request',     'درخواست مراجع', 10),
            array('ca_therapist_emergency', 'موارد فوری درمانگر', 20),
        ),
    ),
);

/* ── وضعیت فعلی نصب ──────────────────────────────────────────────── */
$already_applied = false;
try {
    $already_applied = migration_applied($db, PHASE2_MIGRATION_VERSION);
} catch (Exception $ex) {
    $already_applied = false;
}

$status_tables = array();
foreach ($new_tables as $t) {
    $status_tables[$t] = up_table_exists($db, $t);
}
$missing_phase1 = array();
foreach ($phase1_tables as $t) {
    if (!up_table_exists($db, $t)) {
        $missing_phase1[] = $t;
    }
}

$log = array();
$failed = false;
$done = false;

/* ── اجرا ────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    try {
        if (count($missing_phase1) > 0) {
            throw new Exception('جدول‌های فاز ۱ کامل نیستند: ' . implode('، ', $missing_phase1)
                . '. ابتدا نصب فاز ۱ را کامل کنید.');
        }

        /* گام ۱ تا ۵ — ساخت جدول‌ها (در صورت نبودن) */
        $statements = up_parse_schema($schema_path);
        if (count($statements) !== count($new_tables)) {
            throw new Exception('فایل اسکیما ناقص است؛ ' . count($new_tables)
                . ' جدول انتظار می‌رفت و ' . count($statements) . ' مورد پیدا شد.');
        }

        foreach ($statements as $st) {
            if (up_table_exists($db, $st['table'])) {
                $log[] = array('ok', 'جدول «' . $st['table'] . '» از قبل وجود داشت — بدون تغییر.');
                continue;
            }
            if (!@mysqli_query($db, $st['sql'])) {
                throw new Exception('ساخت جدول «' . $st['table'] . '» ناموفق بود: '
                    . mysqli_error($db));
            }
            $log[] = array('new', 'جدول «' . $st['table'] . '» ساخته شد.');
        }

        /* گام ۶ — دادهٔ مرجع */
        foreach ($seed_lists as $list) {
            $list_id = up_ensure_list($db, $list['code'], $list['title']);
            $added = 0;
            foreach ($list['items'] as $item) {
                if (up_ensure_item($db, $list_id, $item[0], $item[1], $item[2])) {
                    $added++;
                }
            }
            $log[] = array($added > 0 ? 'new' : 'ok',
                'فهرست «' . $list['title'] . '»: ' . to_persian_digits($added) . ' گزینهٔ تازه افزوده شد.');
        }

        /* گام ۷ — راستی‌آزمایی نهایی پیش از ثبت نسخه */
        foreach ($new_tables as $t) {
            if (!up_table_exists($db, $t)) {
                throw new Exception('راستی‌آزمایی ناموفق: جدول «' . $t . '» ساخته نشده است.');
            }
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
        $log[] = array('ok', 'راستی‌آزمایی همهٔ جدول‌ها و داده‌های مرجع موفق بود.');

        /* گام ۸ — ثبت نسخه، فقط پس از موفقیت همهٔ گام‌ها */
        if (!migration_applied($db, PHASE2_MIGRATION_VERSION)) {
            db_execute($db, "INSERT INTO migrations (version, applied_at) VALUES (?,?)",
                'ss', array(PHASE2_MIGRATION_VERSION, now_dt()));
            $log[] = array('new', 'نسخهٔ ' . PHASE2_MIGRATION_VERSION . ' در جدول migrations ثبت شد.');
        } else {
            $log[] = array('ok', 'نسخهٔ ' . PHASE2_MIGRATION_VERSION . ' از قبل ثبت شده بود.');
        }

        audit_log_write($db, $actor_person_id, $actor_role_code, 'MIGRATION_APPLIED',
            'migration', null, array('version' => PHASE2_MIGRATION_VERSION));

        $done = true;
        $already_applied = true;
    } catch (Exception $ex) {
        $failed = true;
        $log[] = array('fail', $ex->getMessage());
        log_system_error('PHASE2_UPGRADE', $ex);
    }

    foreach ($new_tables as $t) {
        $status_tables[$t] = up_table_exists($db, $t);
    }
}

$page_title = 'ارتقا به فاز ۲';
$layout = 'auth';
$wide_card = true;
$brand_subtitle = 'ارتقای سامانه به فاز ۲ — نوبت‌دهی و تقویم';
require __DIR__ . '/templates/header.php';
?>
<h2>🧩 ارتقا به فاز ۲ — نوبت‌دهی و تقویم</h2>

<?php if ($done) { ?>
  <div class="alert alert-success">
    <strong>✅ ارتقا با موفقیت انجام شد.</strong><br>
    اکنون گزینه‌های «اتاق‌ها»، «تعرفه‌ها» و «تقویم نوبت‌ها» در منو در دسترس است.<br>
    لطفاً همین حالا فایل <code>upgrade_phase2.php</code> را از هاست حذف کنید.
  </div>
<?php } elseif ($failed) { ?>
  <div class="alert alert-danger">
    <strong>⚠️ ارتقا کامل نشد.</strong><br>
    پیام خطا را در جدول زیر ببینید. در MySQL دستورهای ساخت جدول قابل بازگشت خودکار
    نیستند؛ اما این برنامه گام‌هایی را که قبلاً انجام شده دوباره اجرا نمی‌کند.
    پس از رفع ایراد، کافی است دوباره دکمهٔ ارتقا را بزنید.
  </div>
<?php } elseif ($already_applied) { ?>
  <div class="alert alert-info">
    ℹ️ نسخهٔ <?php echo e(PHASE2_MIGRATION_VERSION); ?> پیش‌تر روی این پایگاه داده اجرا شده است.
    اجرای دوباره بی‌خطر است و چیزی را تکرار نمی‌کند.
  </div>
<?php } ?>

<?php if (count($missing_phase1) > 0) { ?>
  <div class="alert alert-danger">
    جدول‌های فاز ۱ کامل نیستند: <?php echo e(implode('، ', $missing_phase1)); ?>
  </div>
<?php } ?>

<div class="card">
  <div class="card-header">وضعیت جدول‌های فاز ۲</div>
  <div class="card-body">
    <?php foreach ($status_tables as $t => $exists) { ?>
      <div class="check-row">
        <span><code><?php echo e($t); ?></code></span>
        <span class="badge <?php echo $exists ? 'badge-success' : 'badge-muted'; ?>">
          <?php echo $exists ? 'موجود' : 'ساخته نشده'; ?>
        </span>
      </div>
    <?php } ?>
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
      <li>این برنامه هیچ جدول فاز ۱ را حذف یا بازسازی نمی‌کند.</li>
      <li>فایل‌های <code>includes/config.php</code> و <code>install.lock</code> دست نمی‌خورند.</li>
      <li>هیچ «اتاق نمونه» ساخته نمی‌شود؛ اتاق‌ها را خودتان در صفحهٔ «اتاق‌ها» وارد کنید.</li>
      <li>اجرای چندباره بی‌خطر است.</li>
    </ul>
    <form method="post" action="upgrade_phase2.php">
      <?php echo csrf_field(); ?>
      <button type="submit" class="btn btn-primary btn-block">🚀 اجرای ارتقا</button>
    </form>
  </div>
</div>
<?php } else { ?>
  <a class="btn btn-primary btn-block" href="<?php echo e(APP_BASE_URL); ?>/admin/rooms.php">
    ➡️ رفتن به صفحهٔ اتاق‌ها
  </a>
<?php } ?>
<?php require __DIR__ . '/templates/footer.php'; ?>
