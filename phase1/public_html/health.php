<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — خودآزمون سامانه (health.php)
 *
 *  وضعیت فنی نصب را بررسی می‌کند. پس از اطمینان از سلامت نصب،
 *  می‌توانید این فایل را از هاست حذف کنید.
 *
 *  قاعدهٔ دسترسی: فقط «مدیرِ واردشده». دسترسی ناشناس وجود ندارد
 *  (اصلاحیهٔ امنیتی فاز ۲ — محدودیت ساعتی جای احراز هویت را نمی‌گیرد).
 * ═══════════════════════════════════════════════════════════════════ */

error_reporting(E_ALL);
ini_set('display_errors', '0');

$config_exists = file_exists(__DIR__ . '/includes/config.php');
$checks = array();
$is_admin = false;

if ($config_exists) {
    require_once __DIR__ . '/includes/bootstrap.php';
    $is_admin = (isset($_SESSION['active_role_code']) && $_SESSION['active_role_code'] === ROLE_ADMIN);
} else {
    date_default_timezone_set('UTC');
}

/* ── کنترل دسترسی: فقط مدیرِ واردشده ──────────────────────────── */
if (!$is_admin) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
       . '<title>خودآزمون جوما</title></head><body style="font-family:Tahoma;padding:40px;text-align:center">'
       . '<h2>🔒 دسترسی مجاز نیست</h2>'
       . '<p>این صفحه فقط برای «مدیر»ِ واردشده به سامانه در دسترس است.</p>'
       . '<p><a href="login.php">ورود به سامانه</a></p>'
       . '</body></html>';
    exit;
}

/* ── اجرای بررسی‌ها ─────────────────────────────────────────────── */

function hc($title, $ok, $detail, $critical = true)
{
    return array('title' => $title, 'ok' => $ok, 'detail' => $detail, 'critical' => $critical);
}

/* نسخهٔ PHP */
$checks[] = hc('نسخهٔ PHP', version_compare(PHP_VERSION, '7.4.0', '>='),
    PHP_VERSION . ' (حداقل لازم: 7.4)');

/* افزونه‌ها */
$checks[] = hc('افزونهٔ mysqli', extension_loaded('mysqli'),
    extension_loaded('mysqli') ? 'فعال' : 'نصب نیست — سامانه کار نمی‌کند');
$checks[] = hc('توابع json', function_exists('json_encode'),
    function_exists('json_encode') ? 'در دسترس' : 'در دسترس نیست');
$checks[] = hc('تابع random_bytes', function_exists('random_bytes'),
    function_exists('random_bytes') ? 'در دسترس' : 'در دسترس نیست');
$checks[] = hc('افزونهٔ cURL', function_exists('curl_init'),
    function_exists('curl_init') ? 'فعال' : 'نصب نیست — ارسال پیامک از روش جایگزین انجام می‌شود', false);
$checks[] = hc('کتابخانهٔ mbstring', function_exists('mb_strlen'),
    function_exists('mb_strlen') ? 'فعال' : 'نصب نیست — ممکن است نام‌های فارسی درست بررسی نشوند');

/* فایل تنظیمات */
$checks[] = hc('فایل تنظیمات includes/config.php', $config_exists,
    $config_exists ? 'موجود' : 'ساخته نشده — ابتدا install.php را اجرا کنید');

/* قفل نصب */
$lock = file_exists(__DIR__ . '/install.lock');
$checks[] = hc('قفل نصب (install.lock)', $lock,
    $lock ? 'موجود — نصب‌کننده قفل است' : 'وجود ندارد — نصب هنوز کامل نشده است');

/* فایل نصب‌کننده */
$installer = file_exists(__DIR__ . '/install.php');
$checks[] = hc('حذف فایل install.php', !$installer,
    $installer ? 'هنوز روی هاست است — پس از نصب آن را حذف کنید' : 'حذف شده ✓', false);

/* قابل نوشتن بودن پوشهٔ لاگ */
$logs_writable = is_writable(__DIR__ . '/storage/logs');
$checks[] = hc('قابل نوشتن بودن storage/logs', $logs_writable,
    $logs_writable ? 'بله' : 'خیر — دسترسی پوشه را روی 755 بگذارید');

/* فایل‌های محافظ */
foreach (array('includes', 'database', 'templates', 'storage/logs') as $dir) {
    $has = file_exists(__DIR__ . '/' . $dir . '/.htaccess');
    $checks[] = hc('محافظ پوشهٔ ' . $dir, $has,
        $has ? 'فایل .htaccess موجود است' : 'فایل .htaccess موجود نیست — دوباره آپلود کنید');
}

/* HTTPS */
$https = $config_exists ? is_https_request() : (!empty($_SERVER['HTTPS']));
$checks[] = hc('اتصال امن (HTTPS)', $https,
    $https ? 'برقرار' : 'صفحه با http باز شده است — گواهی SSL را فعال کنید', false);

/* دیتابیس */
$db_ok = false;
$table_report = '';
if ($config_exists) {
    try {
        $db = $GLOBALS['db'];
        $row = db_select_one($db, "SELECT VERSION() AS v", '', array());
        $db_ok = true;
        $checks[] = hc('اتصال به دیتابیس', true, 'برقرار');
        $checks[] = hc('نسخهٔ MySQL / MariaDB', true, $row ? $row['v'] : '—', false);

        $expected = array('migrations','persons','accounts','role_assignments','otp_codes',
                          'lookup_lists','lookup_items','service_types','admissions',
                          'clinical_cases','audit_log');
        $missing = array();
        foreach ($expected as $t) {
            $res = mysqli_query($db, "SHOW TABLES LIKE '" . mysqli_real_escape_string($db, $t) . "'");
            if (!$res || mysqli_num_rows($res) === 0) {
                $missing[] = $t;
            }
        }
        $checks[] = hc('۱۱ جدول سامانه', count($missing) === 0,
            count($missing) === 0 ? 'همه موجودند' : 'جدول‌های ناموجود: ' . implode('، ', $missing));

        $admin_count = db_select_one($db,
            "SELECT COUNT(*) AS cnt FROM role_assignments WHERE role_code = 'admin' AND status = 'ACTIVE'",
            '', array());
        $checks[] = hc('وجود حداقل یک مدیر فعال', $admin_count && (int)$admin_count['cnt'] > 0,
            $admin_count ? to_persian_digits((int)$admin_count['cnt']) . ' مدیر' : '—');
    } catch (Exception $ex) {
        $checks[] = hc('اتصال به دیتابیس', false, 'برقرار نشد');
    }

    /* پیامک */
    $mode = sms_provider_mode();
    $sms_detail = array(
        'smsir' => 'فعال — ارسال واقعی با SMS.ir',
        'dev' => 'حالت آزمایشی — کد فقط در فایل لاگ نوشته می‌شود',
        'disabled' => 'غیرفعال — بازیابی رمز عبور کار نمی‌کند',
    );
    $checks[] = hc('تنظیمات پیامک', $mode !== 'disabled',
        isset($sms_detail[$mode]) ? $sms_detail[$mode] : '—', false);
    if ($mode === 'smsir') {
        $checks[] = hc('کلید API و شناسهٔ قالب', smsir_is_configured(),
            smsir_is_configured() ? 'ثبت شده' : 'ناقص است — از صفحهٔ «تنظیمات پیامک» کامل کنید');
    }
}

$fail_critical = 0;
$warn = 0;
foreach ($checks as $c) {
    if (!$c['ok']) {
        if ($c['critical']) { $fail_critical++; } else { $warn++; }
    }
}
?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>خودآزمون سامانهٔ جوما</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="auth-page">
  <div class="auth-brand">
    <img src="assets/img/logo.jpg" alt="لوگوی کلینیک جوما">
    <h1>کلینیک جوما</h1>
    <p>ابزار بررسی سلامت نصب</p>
  </div>
  <div class="auth-card auth-card-wide">
    <h2 style="font-size:20px;color:var(--color-primary);margin-bottom:8px">🩻 خودآزمون سامانه</h2>
    <p class="text-muted text-small mb-3">
      پس از اطمینان از سلامت نصب، فایل <span class="mono">health.php</span> را از هاست حذف کنید.
    </p>

    <?php foreach ($checks as $c) { ?>
      <div class="check-row">
        <span class="fw-bold"><?php echo htmlspecialchars($c['title'], ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="text-small text-muted"><?php echo htmlspecialchars($c['detail'], ENT_QUOTES, 'UTF-8'); ?></span>
        <?php if ($c['ok']) { ?>
          <span class="badge badge-success">✓ سالم</span>
        <?php } elseif ($c['critical']) { ?>
          <span class="badge badge-danger">✗ ایراد</span>
        <?php } else { ?>
          <span class="badge badge-warning">⚠ بررسی شود</span>
        <?php } ?>
      </div>
    <?php } ?>

    <?php if ($fail_critical === 0 && $warn === 0) { ?>
      <div class="alert alert-success mt-3">✓ همهٔ موارد سالم است؛ سامانه آمادهٔ استفاده است.</div>
    <?php } elseif ($fail_critical === 0) { ?>
      <div class="alert alert-warning mt-3">
        سامانه کار می‌کند، اما <?php echo htmlspecialchars((string)$warn, ENT_QUOTES, 'UTF-8'); ?> مورد غیربحرانی نیاز به بررسی دارد.
      </div>
    <?php } else { ?>
      <div class="alert alert-error mt-3">
        ✗ <?php echo htmlspecialchars((string)$fail_critical, ENT_QUOTES, 'UTF-8'); ?> ایراد مهم پیدا شد؛
        موارد قرمز بالا را برطرف کنید.
      </div>
    <?php } ?>

    <a href="login.php" class="btn btn-secondary mt-2">بازگشت به صفحهٔ ورود</a>
  </div>
  <div class="auth-footer">سامانهٔ مدیریت کلینیک جوما — نسخهٔ ۱.۰ (فاز ۱)</div>
</div>
</body>
</html>
