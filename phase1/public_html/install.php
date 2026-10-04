<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — نصب‌کنندهٔ سه مرحله‌ای
 *
 *  مرحلهٔ ۱: اتصال دیتابیس + نشانی سامانه + تنظیمات پیامک → ساخت config.php
 *  مرحلهٔ ۲: ساخت ۱۱ جدول و داده‌های اولیه
 *  مرحلهٔ ۳: ساخت حساب مدیر → ساخت install.lock
 *
 *  این فایل عمداً مستقل نوشته شده و به bootstrap.php وابسته نیست،
 *  چون در مرحلهٔ اول هنوز فایل تنظیمات وجود ندارد.
 * ═══════════════════════════════════════════════════════════════════ */

error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('UTC');

if (file_exists(__DIR__ . '/install.lock')) {
    die('سامانه قبلاً نصب شده است.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('JOMAINSTALL');
    session_start();
}
if (!isset($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}

$CONFIG_PATH = __DIR__ . '/includes/config.php';
$SCHEMA_PATH = __DIR__ . '/database/phase1_schema.sql';

/* ───────────────────────── ابزارهای کوچک ───────────────────────── */

function ins_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function ins_csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . ins_e($_SESSION['install_csrf']) . '">';
}

function ins_csrf_check()
{
    $t = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!hash_equals($_SESSION['install_csrf'], $t)) {
        die('درخواست نامعتبر است؛ صفحه را دوباره بارگذاری کنید.');
    }
}

function ins_https()
{
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') { return true; }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') { return true; }
    return (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
}

/** حدس نشانی پایهٔ سامانه برای پیشنهاد در فرم */
function ins_guess_base_url()
{
    $scheme = ins_https() ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $host = preg_replace('/[^A-Za-z0-9\.\-\:]/', '', $host);
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return $scheme . '://' . $host . $path;
}

/** متن فایل تنظیمات */
function ins_build_config($v)
{
    $q = function ($s) { return str_replace(array('\\', "'"), array('\\\\', "\\'"), (string)$s); };
    $sms_enabled = ($v['sms_mode'] === 'off') ? 'false' : 'true';
    $provider = ($v['sms_mode'] === 'smsir') ? 'smsir' : 'dev';

    return "<?php\n"
        . "/* فایل تنظیمات جوما — ساخته‌شده توسط نصب‌کننده در "
        . gmdate('Y-m-d H:i:s') . " UTC */\n\n"
        . "define('DB_HOST', '" . $q($v['db_host']) . "');\n"
        . "define('DB_NAME', '" . $q($v['db_name']) . "');\n"
        . "define('DB_USER', '" . $q($v['db_user']) . "');\n"
        . "define('DB_PASS', '" . $q($v['db_pass']) . "');\n\n"
        . "define('APP_BASE_URL', '" . $q($v['base_url']) . "');\n\n"
        . "/* پیامک */\n"
        . "define('SMS_ENABLED', " . $sms_enabled . ");\n"
        . "define('SMS_PROVIDER', '" . $provider . "');\n"
        . "define('SMSIR_API_KEY', '" . $q($v['api_key']) . "');\n"
        . "define('SMSIR_OTP_TEMPLATE_ID', '" . $q($v['template_id']) . "');\n"
        . "define('SMSIR_LINE', '" . $q($v['line']) . "');\n\n"
        . "/* کد یک‌بارمصرف */\n"
        . "define('OTP_CODE_LENGTH', 6);\n"
        . "define('OTP_TTL_SECONDS', 120);\n"
        . "define('OTP_MAX_ATTEMPTS', 5);\n"
        . "define('OTP_RESEND_COOLDOWN', 90);\n\n"
        . "/* نشست و زمان */\n"
        . "define('SESSION_IDLE_TIMEOUT', 1800);\n"
        . "define('APP_TIMEZONE', 'Asia/Tehran');\n";
}

/** اجرای فایل SQL: جداسازی دستورها با سمی‌کالن انتهای خط */
/**
 * متن SQL را به دستورهای مستقل می‌شکند.
 * کامنت‌های  -- ،  #  و  /* *&#47;  حذف می‌شوند و نقطه‌ویرگول داخل
 * رشته یا نام‌های بک‌تیک‌دار به‌اشتباه جداکننده در نظر گرفته نمی‌شود.
 * پایان‌خط ویندوزی/مک و BOM هم یکدست می‌شوند.
 */
function ins_split_sql($sql)
{
    if (substr($sql, 0, 3) === "\xEF\xBB\xBF") {
        $sql = substr($sql, 3);
    }
    $sql = str_replace(array("\r\n", "\r"), "\n", $sql);

    $out = array();
    $buf = '';
    $len = strlen($sql);
    $i = 0;
    $in_single = false;
    $in_double = false;
    $in_tick = false;

    while ($i < $len) {
        $ch = $sql[$i];
        $next = ($i + 1 < $len) ? $sql[$i + 1] : '';

        if (!$in_single && !$in_double && !$in_tick) {
            /* کامنت خطی:  --  یا  # */
            $dash_comment = ($ch === '-' && $next === '-'
                && ($i + 2 >= $len || $sql[$i + 2] === ' ' || $sql[$i + 2] === "\t" || $sql[$i + 2] === "\n"));
            if ($dash_comment || $ch === '#') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            /* کامنت بلوکی */
            if ($ch === '/' && $next === '*') {
                $i += 2;
                while ($i + 1 < $len && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    $i++;
                }
                $i += 2;
                continue;
            }
            if ($ch === ';') {
                $out[] = $buf;
                $buf = '';
                $i++;
                continue;
            }
        }

        if ($ch === '\\' && ($in_single || $in_double)) {
            $buf .= $ch;
            $i++;
            if ($i < $len) {
                $buf .= $sql[$i];
                $i++;
            }
            continue;
        }
        if ($ch === "'" && !$in_double && !$in_tick) {
            $in_single = !$in_single;
        } elseif ($ch === '"' && !$in_single && !$in_tick) {
            $in_double = !$in_double;
        } elseif ($ch === '`' && !$in_single && !$in_double) {
            $in_tick = !$in_tick;
        }
        $buf .= $ch;
        $i++;
    }
    $out[] = $buf;

    $clean = array();
    foreach ($out as $s) {
        $s = trim($s);
        if ($s !== '') {
            $clean[] = $s;
        }
    }
    return $clean;
}

function ins_run_sql_file($link, $path)
{
    $sql = @file_get_contents($path);
    if ($sql === false) {
        throw new Exception('فایل اسکیما خوانده نشد: ' . $path);
    }
    $statements = ins_split_sql($sql);
    if (count($statements) === 0) {
        throw new Exception('فایل اسکیما خالی است یا درست خوانده نشد.');
    }

    $count = 0;
    foreach ($statements as $stmt) {
        if (!mysqli_query($link, $stmt)) {
            $snippet = preg_replace('/\s+/', ' ', $stmt);
            if (function_exists('mb_substr')) {
                $snippet = mb_substr($snippet, 0, 120, 'UTF-8');
            } else {
                $snippet = substr($snippet, 0, 120);
            }
            throw new Exception('خطا در اجرای دستور دیتابیس: ' . mysqli_error($link)
                . ' | دستور: ' . $snippet);
        }
        $count++;
    }
    return $count;
}

/* دادهٔ اولیه */
$seed_lookup_lists = array(
    'referral_reason' => array(
        'title' => 'دلیل مراجعه',
        'items' => array(
            'rr_anxiety'     => 'اضطراب و نگرانی',
            'rr_depression'  => 'افسردگی',
            'rr_marital'     => 'مشکلات زناشویی',
            'rr_family'      => 'مشکلات خانوادگی',
            'rr_child'       => 'مشکلات کودک و نوجوان',
            'rr_assessment'  => 'ارزیابی روان‌شناختی',
            'rr_other'       => 'سایر',
        ),
    ),
    'admission_decline_reason' => array(
        'title' => 'دلیل عدم پذیرش',
        'items' => array(
            'dr_fit'      => 'عدم تناسب تخصصی',
            'dr_capacity' => 'تکمیل ظرفیت',
            'dr_other'    => 'سایر',
        ),
    ),
);

$seed_services = array(
    array('code' => 'individual', 'title' => 'مشاورهٔ فردی بزرگسال',      'is_multi_person' => 0, 'duration' => 45),
    array('code' => 'couple',     'title' => 'زوج‌درمانی و خانواده',       'is_multi_person' => 1, 'duration' => 60),
    array('code' => 'premarital', 'title' => 'مشاورهٔ پیش از ازدواج',      'is_multi_person' => 1, 'duration' => 60),
    array('code' => 'child',      'title' => 'روان‌شناسی کودک و نوجوان',   'is_multi_person' => 0, 'duration' => 45),
    array('code' => 'assessment', 'title' => 'ارزیابی و تفسیر تست بالینی', 'is_multi_person' => 0, 'duration' => 60),
);

/* ───────────────────────── جریان مراحل ──────────────────────────── */

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$errors = array();
$notice = '';
$step2_report = array();

$defaults = array(
    'db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
    'base_url' => ins_guess_base_url(), 'sms_mode' => 'smsir',
    'api_key' => '', 'template_id' => '', 'line' => '',
);
$v = isset($_SESSION['install_values']) ? array_merge($defaults, $_SESSION['install_values']) : $defaults;

/* اگر config.php از قبل هست، مستقیم به مرحلهٔ ۲ */
if (file_exists($CONFIG_PATH) && $step === 1 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $step = 2;
}

/* ── دانلود فایل تنظیمات آماده (حالت اضطراری) ───────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'download_config') {
    ins_csrf_check();
    if (!isset($_SESSION['install_config_text'])) {
        die('ابتدا اطلاعات دیتابیس را وارد کنید.');
    }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="config.php"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo $_SESSION['install_config_text'];
    exit;
}

/* ── مرحلهٔ ۱ ────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'step1') {
    ins_csrf_check();
    $step = 1;

    foreach (array('db_host','db_name','db_user','db_pass','base_url','sms_mode','api_key','template_id','line') as $f) {
        $v[$f] = isset($_POST[$f]) ? trim((string)$_POST[$f]) : '';
    }
    $v['base_url'] = rtrim($v['base_url'], '/');
    $_SESSION['install_values'] = $v;

    if ($v['db_host'] === '' || $v['db_name'] === '' || $v['db_user'] === '') {
        $errors[] = 'میزبان، نام دیتابیس و نام کاربری دیتابیس الزامی هستند.';
    }
    if (!preg_match('#^https?://[A-Za-z0-9\.\-]+(:\d+)?(/[A-Za-z0-9_\-/\.]*)?$#', $v['base_url'])) {
        $errors[] = 'نشانی سامانه معتبر نیست. نمونهٔ درست: https://example.com/joma';
    }
    if ($v['sms_mode'] === 'smsir' && ($v['api_key'] === '' || $v['template_id'] === '')) {
        $errors[] = 'برای ارسال واقعی پیامک، کلید API و شناسهٔ قالب الزامی است. '
            . 'اگر هنوز آن‌ها را ندارید، فعلاً حالت «آزمایشی» را انتخاب کنید.';
    }
    if (!ins_https() && empty($_POST['http_ack'])) {
        $errors[] = 'این صفحه با http باز شده است. برای ادامه، کادر تأیید هشدار امنیتی را علامت بزنید.';
    }

    if (empty($errors)) {
        $link = @mysqli_connect($v['db_host'], $v['db_user'], $v['db_pass'], $v['db_name']);
        if (!$link) {
            $errors[] = 'اتصال به دیتابیس برقرار نشد. اطلاعات واردشده را بررسی کنید. '
                . '(پیام سرور: ' . mysqli_connect_error() . ')';
        } else {
            mysqli_close($link);
            $config_text = ins_build_config($v);
            $_SESSION['install_config_text'] = $config_text;

            if (@file_put_contents($CONFIG_PATH, $config_text) !== false) {
                @chmod($CONFIG_PATH, 0644);
                header('Location: install.php?step=2');
                exit;
            }
            $errors[] = 'NOT_WRITABLE';
        }
    }
}

/* ── مرحلهٔ ۲ ────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'step2') {
    ins_csrf_check();
    $step = 2;

    if (!file_exists($CONFIG_PATH)) {
        $errors[] = 'فایل تنظیمات پیدا نشد؛ به مرحلهٔ ۱ بازگردید.';
        $step = 1;
    } else {
        require_once $CONFIG_PATH;
        $link = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if (!$link) {
            $errors[] = 'اتصال به دیتابیس برقرار نشد.';
        } else {
            mysqli_set_charset($link, 'utf8mb4');
            @mysqli_query($link, "SET time_zone = '+00:00'");
            try {
                $existing = mysqli_query($link, "SHOW TABLES LIKE 'persons'");
                $tables_exist = ($existing && mysqli_num_rows($existing) > 0);
                $skip_schema = (isset($_POST['skip_schema']) && $_POST['skip_schema'] === '1');

                if ($tables_exist && !$skip_schema) {
                    throw new Exception('جدول‌های سامانه از قبل در این دیتابیس وجود دارند. '
                        . 'اگر آن‌ها را خودتان با phpMyAdmin از روی فایل database/phase1_schema.sql '
                        . 'ساخته‌اید، گزینهٔ «جدول‌ها از قبل ساخته شده‌اند» را تیک بزنید؛ '
                        . 'در غیر این صورت همهٔ جدول‌های دیتابیس را حذف کنید و دوباره تلاش کنید.');
                }
                if (!$tables_exist && $skip_schema) {
                    throw new Exception('گزینهٔ «جدول‌ها از قبل ساخته شده‌اند» تیک خورده، '
                        . 'ولی جدولی در دیتابیس پیدا نشد. تیک را بردارید.');
                }

                if ($skip_schema) {
                    $step2_report[] = array('ساخت جدول‌های سامانه', 'رد شد — جدول‌ها از قبل موجود بودند');
                    @mysqli_query($link, "DELETE FROM lookup_items");
                    @mysqli_query($link, "DELETE FROM lookup_lists");
                    @mysqli_query($link, "DELETE FROM service_types");
                    @mysqli_query($link, "DELETE FROM migrations");
                } else {
                    $count = ins_run_sql_file($link, $SCHEMA_PATH);
                    $step2_report[] = array('ساخت جدول‌های سامانه', $count . ' دستور اجرا شد');
                }

                $now = gmdate('Y-m-d H:i:s');

                /* فهرست‌های پایه */
                foreach ($seed_lookup_lists as $list_code => $list) {
                    $stmt = mysqli_prepare($link, "INSERT INTO lookup_lists (list_code, list_title) VALUES (?,?)");
                    mysqli_stmt_bind_param($stmt, 'ss', $list_code, $list['title']);
                    mysqli_stmt_execute($stmt);
                    $list_id = mysqli_stmt_insert_id($stmt);
                    mysqli_stmt_close($stmt);

                    $order = 1;
                    foreach ($list['items'] as $item_code => $label) {
                        $stmt = mysqli_prepare($link,
                            "INSERT INTO lookup_items (lookup_list_id, item_code, label, display_order, is_active)
                             VALUES (?,?,?,?,1)");
                        mysqli_stmt_bind_param($stmt, 'issi', $list_id, $item_code, $label, $order);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                        $order++;
                    }
                    $step2_report[] = array('فهرست «' . $list['title'] . '»',
                        count($list['items']) . ' گزینه ثبت شد');
                }

                /* خدمات کلینیک */
                foreach ($seed_services as $svc) {
                    $public_id = 'sv_' . bin2hex(random_bytes(10));
                    $stmt = mysqli_prepare($link,
                        "INSERT INTO service_types
                         (public_id, code, title, is_multi_person, default_duration_minutes, is_active)
                         VALUES (?,?,?,?,?,1)");
                    mysqli_stmt_bind_param($stmt, 'sssii', $public_id, $svc['code'], $svc['title'],
                        $svc['is_multi_person'], $svc['duration']);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_close($stmt);
                }
                $step2_report[] = array('خدمات کلینیک', count($seed_services) . ' خدمت ثبت شد');

                /* ثبت نسخهٔ اسکیما */
                $version = '1.0.0';
                $stmt = mysqli_prepare($link, "INSERT INTO migrations (version, applied_at) VALUES (?,?)");
                mysqli_stmt_bind_param($stmt, 'ss', $version, $now);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $step2_report[] = array('ثبت نسخهٔ اسکیما', 'نسخهٔ ' . $version);

                $_SESSION['install_step2_done'] = true;
                $_SESSION['install_step2_report'] = $step2_report;
                mysqli_close($link);
                header('Location: install.php?step=3');
                exit;
            } catch (Exception $ex) {
                $errors[] = $ex->getMessage();
                mysqli_close($link);
            }
        }
    }
}

/* ── مرحلهٔ ۳ ────────────────────────────────────────────────────── */
$admin_form = array('first_name' => '', 'last_name' => '', 'mobile_number' => '', 'login_identifier' => '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'step3') {
    ins_csrf_check();
    $step = 3;

    require_once $CONFIG_PATH;
    require_once __DIR__ . '/includes/jalali.php';
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/helpers.php';
    require_once __DIR__ . '/includes/auth.php';
    require_once __DIR__ . '/includes/validate.php';
    require_once __DIR__ . '/includes/person_functions.php';
    require_once __DIR__ . '/includes/account_functions.php';
    require_once __DIR__ . '/includes/staff_functions.php';
    require_once __DIR__ . '/includes/audit_functions.php';

    foreach (array('first_name','last_name','mobile_number','login_identifier') as $f) {
        $admin_form[$f] = isset($_POST[$f]) ? trim((string)$_POST[$f]) : '';
    }
    $admin_form['login_identifier'] = strtolower($admin_form['login_identifier']);
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
    $password2 = isset($_POST['password_confirm']) ? (string)$_POST['password_confirm'] : '';

    $data = $admin_form;
    $data['password'] = $password;
    $data['role_codes'] = array(ROLE_ADMIN);

    $check = staff_create_validate($data);
    if (!$check['ok']) {
        foreach ($check['errors'] as $msg) { $errors[] = $msg; }
    }
    if ($password !== $password2) {
        $errors[] = 'رمز عبور و تکرار آن یکسان نیستند.';
    }

    if (empty($errors)) {
        $db = db_connect();
        try {
            $person_data = array(
                'first_name'    => $admin_form['first_name'],
                'last_name'     => $admin_form['last_name'],
                'mobile_number' => $check['mobile'],
                'national_code' => $check['national_code'],
            );
            staff_account_create($db, $person_data, $admin_form['login_identifier'], $password,
                array(ROLE_ADMIN), null, true);

            @file_put_contents(__DIR__ . '/install.lock',
                'نصب‌شده در ' . gmdate('Y-m-d H:i:s') . " UTC\n");

            unset($_SESSION['install_values'], $_SESSION['install_config_text'],
                  $_SESSION['install_step2_done'], $_SESSION['install_step2_report']);

            $step = 4;  /* صفحهٔ پایان */
        } catch (Exception $ex) {
            $errors[] = $ex->getMessage();
        }
    }
}

if ($step === 3 && empty($_SESSION['install_step2_done']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    /* ورود مستقیم به مرحلهٔ ۳ بدون انجام مرحلهٔ ۲ */
    if (!file_exists($CONFIG_PATH)) { $step = 1; }
}

$not_writable = in_array('NOT_WRITABLE', $errors, true);
$errors = array_values(array_filter($errors, function ($e) { return $e !== 'NOT_WRITABLE'; }));

$base_for_assets = 'assets';
?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>نصب سامانهٔ جوما</title>
<link rel="stylesheet" href="<?php echo $base_for_assets; ?>/css/style.css">
</head>
<body>
<div class="auth-page">
  <div class="auth-brand">
    <img src="<?php echo $base_for_assets; ?>/img/logo.jpg" alt="لوگوی کلینیک جوما">
    <h1>کلینیک جوما</h1>
    <p>نصب سامانه — نسخهٔ ۱.۰ (فاز ۱)</p>
  </div>
  <div class="auth-card auth-card-wide">

    <div class="steps">
      <div class="step <?php echo $step > 1 ? 'done' : ($step === 1 ? 'active' : ''); ?>">
        ۱) اتصال دیتابیس<?php echo $step > 1 ? ' ✓' : ''; ?></div>
      <div class="step <?php echo $step > 2 ? 'done' : ($step === 2 ? 'active' : ''); ?>">
        ۲) ساخت جدول‌ها<?php echo $step > 2 ? ' ✓' : ''; ?></div>
      <div class="step <?php echo $step > 3 ? 'done' : ($step === 3 ? 'active' : ''); ?>">
        ۳) مدیر سامانه<?php echo $step > 3 ? ' ✓' : ''; ?></div>
    </div>

    <?php foreach ($errors as $err) { ?>
      <div class="alert alert-error">✗ <?php echo ins_e($err); ?></div>
    <?php } ?>

    <?php if ($not_writable) { ?>
      <div class="alert alert-warning">
        <strong>⚠️ پوشهٔ includes قابل نوشتن نیست</strong>
        نصب‌کننده نتوانست فایل تنظیمات را خودش بسازد. نگران نباشید؛ کار ساده‌ای در پیش دارید:
        <ol style="margin:10px 22px 0">
          <li>دکمهٔ زیر را بزنید تا فایل آمادهٔ <span class="mono">config.php</span> دانلود شود.</li>
          <li>در cPanel وارد File Manager شوید و به پوشهٔ <span class="mono">public_html/includes</span> بروید.</li>
          <li>فایل دانلود‌شده را همان‌جا Upload کنید.</li>
          <li>به همین صفحه برگردید و دکمهٔ «ادامه» را بزنید.</li>
        </ol>
        <form method="post" action="install.php" class="mt-2">
          <?php echo ins_csrf_field(); ?>
          <input type="hidden" name="action" value="download_config">
          <button type="submit" class="btn btn-primary">⬇ دانلود فایل تنظیمات آماده</button>
        </form>
        <p class="text-small mt-2">
          نکته: هرگز دسترسی پوشه را روی ۷۷۷ نگذارید؛ مقدار درست برای پوشه ۷۵۵ و برای فایل ۶۴۴ است.
        </p>
        <a href="install.php?step=2" class="btn btn-secondary mt-2">فایل را آپلود کردم — ادامه</a>
      </div>
    <?php } ?>

    <?php if ($step === 1) { ?>
      <?php if (!ins_https()) { ?>
        <div class="alert alert-warning">
          <strong>⚠️ هشدار امنیتی</strong>
          این صفحه با پروتکل ناامن http باز شده است. توصیهٔ اکید می‌شود نصب را با https انجام دهید.
        </div>
      <?php } ?>

      <h2 style="font-size:20px;color:var(--color-primary);margin-bottom:8px">اطلاعات دیتابیس</h2>
      <p class="text-muted text-small mb-3">
        این چهار مقدار را از بخش «MySQL Databases» در cPanel بردارید.
      </p>

      <form method="post" action="install.php" data-guard>
        <?php echo ins_csrf_field(); ?>
        <input type="hidden" name="action" value="step1">

        <div class="form-group">
          <label for="db_host">میزبان دیتابیس <span class="required-star">*</span></label>
          <input type="text" id="db_host" name="db_host" dir="ltr" value="<?php echo ins_e($v['db_host']); ?>" required>
          <span class="form-hint">در اغلب هاست‌های اشتراکی همان localhost است.</span>
        </div>
        <div class="form-group">
          <label for="db_name">نام دیتابیس <span class="required-star">*</span></label>
          <input type="text" id="db_name" name="db_name" dir="ltr" value="<?php echo ins_e($v['db_name']); ?>" required>
        </div>
        <div class="form-group">
          <label for="db_user">نام کاربری دیتابیس <span class="required-star">*</span></label>
          <input type="text" id="db_user" name="db_user" dir="ltr" value="<?php echo ins_e($v['db_user']); ?>" required>
        </div>
        <div class="form-group">
          <label for="db_pass">رمز عبور دیتابیس</label>
          <input type="password" id="db_pass" name="db_pass" dir="ltr" value="<?php echo ins_e($v['db_pass']); ?>">
        </div>
        <div class="form-group">
          <label for="base_url">نشانی سامانه</label>
          <input type="text" id="base_url" name="base_url" dir="ltr" value="<?php echo ins_e($v['base_url']); ?>" required>
          <span class="form-hint">به‌صورت خودکار تشخیص داده شد؛ در صورت نیاز اصلاح کنید (بدون اسلش پایانی).</span>
        </div>

        <hr class="section-divider">

        <div class="form-group">
          <label for="sms_mode">تنظیمات پیامک (برای بازیابی رمز عبور)</label>
          <select id="sms_mode" name="sms_mode" data-toggle-target="#smsirBox" data-toggle-value="smsir">
            <option value="smsir"<?php echo $v['sms_mode'] === 'smsir' ? ' selected' : ''; ?>>
              ارسال واقعی با سامانهٔ SMS.ir</option>
            <option value="dev"<?php echo $v['sms_mode'] === 'dev' ? ' selected' : ''; ?>>
              حالت آزمایشی (کد فقط در فایل لاگ نوشته می‌شود)</option>
            <option value="off"<?php echo $v['sms_mode'] === 'off' ? ' selected' : ''; ?>>
              فعلاً غیرفعال</option>
          </select>
        </div>
        <div id="smsirBox" class="conditional-block<?php echo $v['sms_mode'] === 'smsir' ? '' : ' hidden'; ?>">
          <div class="form-group">
            <label for="api_key">کلید API سامانهٔ SMS.ir</label>
            <input type="text" id="api_key" name="api_key" dir="ltr" value="<?php echo ins_e($v['api_key']); ?>">
          </div>
          <div class="form-group">
            <label for="template_id">شناسهٔ قالب کد تأیید (Template ID)</label>
            <input type="text" id="template_id" name="template_id" dir="ltr" data-digits="en"
                   value="<?php echo ins_e($v['template_id']); ?>">
            <span class="form-hint">نام پارامتر قالب در پنل SMS.ir باید دقیقاً CODE باشد.</span>
          </div>
          <div class="form-group">
            <label for="line">شمارهٔ خط (اختیاری)</label>
            <input type="text" id="line" name="line" dir="ltr" data-digits="en" value="<?php echo ins_e($v['line']); ?>">
            <span class="form-hint">برای ارسال کد تأیید لازم نیست؛ فقط برای پیامک متنی آزمایشی کاربرد دارد.</span>
          </div>
        </div>

        <?php if (!ins_https()) { ?>
          <label class="checkbox">
            <input type="checkbox" name="http_ack" value="1"> با وجود هشدار، نصب روی http را ادامه می‌دهم.
          </label>
        <?php } ?>

        <button type="submit" class="btn btn-primary btn-block mt-2">آزمایش اتصال و ادامه ⬅</button>
      </form>

    <?php } elseif ($step === 2) { ?>
      <div class="alert alert-success">
        <strong>✓ اتصال به دیتابیس برقرار است</strong>
        فایل تنظیمات <span class="mono">includes/config.php</span> آماده است.
      </div>
      <h2 style="font-size:20px;color:var(--color-primary);margin-bottom:8px">ساخت جدول‌ها و داده‌های اولیه</h2>
      <p class="text-muted text-small mb-3">
        با زدن دکمهٔ زیر، ۱۱ جدول سامانه ساخته و فهرست‌های پایه و خدمات کلینیک ثبت می‌شوند.
      </p>
      <form method="post" action="install.php?step=2" data-guard>
        <?php echo ins_csrf_field(); ?>
        <input type="hidden" name="action" value="step2">
        <label class="checkbox mb-3">
          <input type="checkbox" name="skip_schema" value="1">
          جدول‌ها از قبل ساخته شده‌اند (فایل <span class="mono">phase1_schema.sql</span> را خودم
          در phpMyAdmin وارد کرده‌ام) — فقط داده‌های اولیه ثبت شود
        </label>
        <button type="submit" class="btn btn-primary btn-block">ساخت جدول‌ها ⬅</button>
      </form>

    <?php } elseif ($step === 3) { ?>
      <?php if (!empty($_SESSION['install_step2_report'])) { ?>
        <div class="alert alert-success"><strong>✓ جدول‌ها و داده‌های اولیه ساخته شدند</strong></div>
        <div class="table-wrap mb-3">
          <table class="table">
            <thead><tr><th>مورد</th><th>نتیجه</th></tr></thead>
            <tbody>
            <?php foreach ($_SESSION['install_step2_report'] as $r) { ?>
              <tr><td><?php echo ins_e($r[0]); ?></td><td><?php echo ins_e($r[1]); ?></td></tr>
            <?php } ?>
            </tbody>
          </table>
        </div>
      <?php } ?>

      <h2 style="font-size:20px;color:var(--color-primary);margin-bottom:16px">ساخت حساب مدیر سامانه</h2>
      <form method="post" action="install.php?step=3" data-guard>
        <?php echo ins_csrf_field(); ?>
        <input type="hidden" name="action" value="step3">

        <div class="form-section-title">اطلاعات شخصی</div>
        <div class="form-row">
          <div class="form-group">
            <label for="first_name">نام <span class="required-star">*</span></label>
            <input type="text" id="first_name" name="first_name"
                   value="<?php echo ins_e($admin_form['first_name']); ?>" required>
          </div>
          <div class="form-group">
            <label for="last_name">نام خانوادگی <span class="required-star">*</span></label>
            <input type="text" id="last_name" name="last_name"
                   value="<?php echo ins_e($admin_form['last_name']); ?>" required>
          </div>
        </div>
        <div class="form-group">
          <label for="mobile_number">شمارهٔ موبایل <span class="required-star">*</span></label>
          <input type="tel" id="mobile_number" name="mobile_number" dir="ltr" maxlength="13" data-digits="en"
                 placeholder="09121234567" value="<?php echo ins_e($admin_form['mobile_number']); ?>" required>
          <span class="form-hint">کد بازیابی رمز عبور به همین شماره پیامک می‌شود.</span>
        </div>

        <div class="form-section-title">اطلاعات حساب</div>
        <div class="form-group">
          <label for="login_identifier">نام کاربری <span class="required-star">*</span></label>
          <input type="text" id="login_identifier" name="login_identifier" dir="ltr"
                 value="<?php echo ins_e($admin_form['login_identifier']); ?>" required>
          <span class="form-hint">فقط حروف کوچک انگلیسی، عدد و زیرخط — حداقل ۴ نویسه.</span>
        </div>
        <div class="form-group">
          <label for="password">رمز عبور <span class="required-star">*</span></label>
          <input type="password" id="password" name="password" autocomplete="new-password" required>
          <div data-strength-for="password"></div>
          <span class="form-hint">حداقل ۸ نویسه، شامل حرف و عدد.</span>
        </div>
        <div class="form-group">
          <label for="password_confirm">تکرار رمز عبور <span class="required-star">*</span></label>
          <input type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required>
        </div>

        <button type="submit" class="btn btn-success btn-block">پایان نصب و ساخت حساب مدیر ✓</button>
      </form>
      <div class="alert alert-info mt-3">
        پس از پایان نصب، فایل <span class="mono">install.lock</span> ساخته می‌شود و این صفحه دیگر باز نخواهد شد.
      </div>

    <?php } else { ?>
      <div class="alert alert-success">
        <strong>🎉 نصب با موفقیت به پایان رسید</strong>
        حساب مدیر ساخته شد و نصب‌کننده قفل گردید.
      </div>
      <div class="card mb-3">
        <div class="card-header card-header-light">کارهای باقی‌مانده</div>
        <div class="card-body">
          <ol style="margin-right:20px">
            <li>با نام کاربری و رمزی که همین الان ساختید وارد شوید (در نخستین ورود، رمز را تغییر دهید).</li>
            <li>فایل <span class="mono">install.php</span> را از هاست حذف کنید.</li>
            <li>برای بررسی سلامت نصب، یک‌بار <span class="mono">health.php</span> را باز کنید و سپس آن را هم حذف کنید.</li>
          </ol>
        </div>
      </div>
      <a href="login.php" class="btn btn-primary btn-block">ورود به سامانه</a>
    <?php } ?>

  </div>
  <div class="auth-footer">سامانهٔ مدیریت کلینیک جوما — نسخهٔ ۱.۰ (فاز ۱)</div>
</div>
<script src="<?php echo $base_for_assets; ?>/js/app.js"></script>
</body>
</html>
