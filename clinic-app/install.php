<?php
// ============================================================================
// نصب‌کننده تحت وب «مدیریت مطب» — بدون نیاز به shell/composer
// پس از نصب موفق، حتماً همین فایل را از روی هاست حذف کنید.
// ============================================================================
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');
mb_internal_encoding('UTF-8');

require_once dirname(__FILE__) . '/functions/clinic_db.php';

function ins_h($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

$already = clinic_db_is_installed();

$checks = array();
$checks[] = array('نسخه PHP (' . PHP_VERSION . ')', version_compare(PHP_VERSION, '7.0.0', '>='), 'حداقل PHP 7.0 لازم است.');
$checks[] = array('افزونه mysqli', function_exists('mysqli_connect'), 'از cPanel گزینه mysqli را فعال کنید.');
$checks[] = array('افزونه json', function_exists('json_encode'), 'روی هاست فعال کنید.');
$checks[] = array('افزونه mbstring', function_exists('mb_strlen'), 'برای متن فارسی لازم است.');
$checks[] = array('نشست (session)', function_exists('session_start'), 'روی هاست فعال کنید.');
$cfgdir = dirname(__FILE__) . '/config';
$datadir = dirname(__FILE__) . '/data';
if (!is_dir($cfgdir)) @mkdir($cfgdir, 0755, true);
if (!is_dir($datadir)) @mkdir($datadir, 0755, true);
$checks[] = array('قابل‌نوشتن بودن پوشه config', is_writable($cfgdir), 'سطح دسترسی پوشه my/config باید قابل نوشتن باشد.');
$checks[] = array('قابل‌نوشتن بودن پوشه data', is_writable($datadir), 'سطح دسترسی پوشه my/data باید قابل نوشتن باشد.');
// اختیاری
$zip_ok = class_exists('ZipArchive');
$xml_ok = function_exists('simplexml_load_string');

$env_ok = true;
foreach ($checks as $c) { if (!$c[1]) $env_ok = false; }

$msg_ok = '';
$msg_err = '';
$f = array('db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'db_pass' => '', 'a_first' => '', 'a_last' => '', 'a_user' => 'admin', 'a_pass1' => '', 'a_pass2' => '');

if (!$already && $_SERVER['REQUEST_METHOD'] === 'POST' && $env_ok) {
    foreach ($f as $k => $v) $f[$k] = trim(isset($_POST[$k]) ? (string) $_POST[$k] : '');
    if ($f['db_name'] === '' || $f['db_user'] === '') {
        $msg_err = 'نام دیتابیس و نام کاربری دیتابیس را وارد کنید.';
    } elseif (!preg_match('/^[a-z0-9_.]{3,40}$/', strtolower($f['a_user']))) {
        $msg_err = 'نام کاربری مدیر باید انگلیسی، حداقل ۳ حرف و فقط شامل حروف، عدد، نقطه و آندرلاین باشد.';
    } elseif (strlen($f['a_pass1']) < 6) {
        $msg_err = 'رمز عبور مدیر باید حداقل ۶ نویسه باشد.';
    } elseif ($f['a_pass1'] !== $f['a_pass2']) {
        $msg_err = 'تکرار رمز عبور با رمز عبور یکی نیست.';
    } else {
        $cfg = array('db_host' => $f['db_host'] !== '' ? $f['db_host'] : 'localhost', 'db_name' => $f['db_name'], 'db_user' => $f['db_user'], 'db_pass' => $f['db_pass']);
        $m = @mysqli_connect($cfg['db_host'], $cfg['db_user'], $cfg['db_pass'], $cfg['db_name']);
        if (!$m) {
            $msg_err = 'اتصال به دیتابیس ناموفق بود: ' . ins_h(@mysqli_connect_error()) . ' — مشخصات را بررسی کنید.';
        } else {
            @mysqli_set_charset($m, 'utf8mb4');
            $errs = clinic_db_run_schema($m);
            if ($errs) {
                $msg_err = 'خطا در ساخت جدول‌ها:<br>' . ins_h(implode("\n", $errs));
            } else {
                $errs2 = clinic_db_seed_defaults($m);
                // ساخت مدیر
                $au = strtolower($f['a_user']);
                $stmt = mysqli_prepare($m, 'SELECT `id` FROM `clinic_users` WHERE `username`=? LIMIT 1');
                $exists = false;
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 's', $au);
                    mysqli_stmt_execute($stmt);
                    mysqli_stmt_store_result($stmt);
                    $exists = mysqli_stmt_num_rows($stmt) > 0;
                    mysqli_stmt_close($stmt);
                }
                if ($exists) {
                    $errs2[] = 'این نام کاربری مدیر قبلاً وجود دارد؛ نام دیگری انتخاب کنید.';
                } else {
                    $stmt = mysqli_prepare($m, 'INSERT INTO `clinic_users` (`first_name`,`last_name`,`username`,`email`,`phone`,`job`,`password_hash`,`role_key`,`access_level`,`doctor_id`,`active`,`created_at`) VALUES (?,?,?,\'\',\'\',\'مدیر سیستم\',?, \'admin\',5,0,1,?)');
                    if ($stmt) {
                        $hash = password_hash($f['a_pass1'], PASSWORD_DEFAULT);
                        $now = date('Y-m-d H:i:s');
                        mysqli_stmt_bind_param($stmt, 'sssss', $f['a_first'], $f['a_last'], $au, $hash, $now);
                        if (!mysqli_stmt_execute($stmt)) $errs2[] = 'ساخت مدیر: ' . mysqli_stmt_error($stmt);
                        mysqli_stmt_close($stmt);
                    } else {
                        $errs2[] = 'ساخت مدیر: ' . mysqli_error($m);
                    }
                }
                if ($errs2) {
                    $msg_err = 'نصب ناقص ماند:<br>' . ins_h(implode("\n", $errs2));
                } else {
                    // نوشتن کانفیگ
                    $content = "<?php\n// ساخته‌شده توسط install.php در " . date('Y-m-d H:i:s') . " — محرمانه\nreturn " . var_export($cfg, true) . ";\n";
                    $w1 = @file_put_contents(clinic_db_config_path(), $content);
                    $w2 = @file_put_contents(clinic_db_lock_path(), 'installed ' . date('Y-m-d H:i:s') . "\n");
                    if ($w1 === false || $w2 === false) {
                        $msg_err = 'جدول‌ها ساخته شد ولی نوشتن فایل تنظیمات ناموفق بود. سطح دسترسی پوشه‌های config و data را بررسی کنید و دوباره تلاش کنید.';
                    } else {
                        @chmod(clinic_db_config_path(), 0600);
                        $msg_ok = 'نصب با موفقیت انجام شد ✅';
                        $already = true;
                    }
                }
            }
            @mysqli_close($m);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>نصب مدیریت مطب</title>
<style>
body{font-family:Tahoma,Arial,sans-serif;background:#f4f6f8;color:#222;margin:0;padding:20px}
.box{max-width:640px;margin:20px auto;background:#fff;border:1px solid #ddd;border-radius:10px;padding:24px}
h1{font-size:20px;margin:0 0 12px}
h2{font-size:15px;margin:20px 0 8px;color:#444}
label{display:block;margin:8px 0 4px;font-size:13px}
input[type=text],input[type=password]{width:100%;box-sizing:border-box;padding:9px;border:1px solid #ccc;border-radius:6px;font-size:14px}
input.ltr{direction:ltr;text-align:left}
button{background:#1a7a4c;color:#fff;border:0;border-radius:6px;padding:11px 26px;font-size:15px;cursor:pointer;margin-top:14px}
.ok{background:#e6f6ec;border:1px solid #9ed3b3;color:#14632f;padding:10px;border-radius:6px;margin:10px 0}
.err{background:#fdecec;border:1px solid #f0a9a9;color:#8f1d1d;padding:10px;border-radius:6px;margin:10px 0}
.chk{font-size:13px;margin:3px 0}
.chk b.okc{color:#14632f}.chk b.badc{color:#8f1d1d}
.warn{background:#fff7e6;border:1px solid #e0b64f;color:#7a5410;padding:10px;border-radius:6px;margin:10px 0}
a{color:#1a5fa8}
small{color:#777}
</style>
</head>
<body>
<div class="box">
<h1>🏥 نصب «مدیریت مطب»</h1>

<h2>۱) بررسی محیط هاست</h2>
<?php foreach ($checks as $c): ?>
<div class="chk"><?php echo $c[1] ? '<b class="okc">✅</b>' : '<b class="badc">❌</b>'; ?> <?php echo ins_h($c[0]); ?><?php if (!$c[1]) echo ' — <small>' . ins_h($c[2]) . '</small>'; ?></div>
<?php endforeach; ?>
<div class="chk"><?php echo $zip_ok ? '<b class="okc">✅</b>' : '⚠️'; ?> ZipArchive <small>(اختیاری؛ فقط برای ورود فایل اکسل xlsx — بدون آن از CSV استفاده کنید)</small></div>
<div class="chk"><?php echo $xml_ok ? '<b class="okc">✅</b>' : '⚠️'; ?> SimpleXML <small>(اختیاری؛ همراه ZipArchive برای اکسل)</small></div>

<?php if ($already && $msg_ok === ''): ?>
<div class="warn">⚠️ نصب قبلاً انجام شده است (فایل قفل وجود دارد).<br>اگر نصب را تازه انجام داده‌اید، <strong>همین فایل install.php را از روی هاست حذف کنید</strong> و سپس <a href="index.php">وارد سامانه شوید</a>.</div>
<?php elseif ($msg_ok !== ''): ?>
<div class="ok"><?php echo $msg_ok; ?></div>
<div class="warn">قدم بعدی (مهم): <strong>همین فایل install.php را از File Manager هاست حذف کنید</strong>، بعد <a href="index.php">وارد سامانه شوید</a> و با نام کاربری مدیر وارد شوید.</div>
<?php else: ?>
<?php if ($msg_err !== ''): ?><div class="err"><?php echo $msg_err; ?></div><?php endif; ?>
<?php if (!$env_ok): ?>
<div class="err">❌ پیش‌نیازهای محیط کامل نیست. موارد ❌ بالا را در هاست رفع کنید و این صفحه را رفرش کنید.</div>
<?php else: ?>
<form method="post" action="install.php">
<h2>۲) مشخصات دیتابیس (از cPanel → MySQL Databases)</h2>
<label>هاست دیتابیس</label>
<input class="ltr" type="text" name="db_host" value="<?php echo ins_h($f['db_host']); ?>">
<label>نام دیتابیس *</label>
<input class="ltr" type="text" name="db_name" value="<?php echo ins_h($f['db_name']); ?>" placeholder="مثل: mirbolou_clinic">
<label>نام کاربری دیتابیس *</label>
<input class="ltr" type="text" name="db_user" value="<?php echo ins_h($f['db_user']); ?>" placeholder="مثل: mirbolou_clinicuser">
<label>رمز دیتابیس</label>
<input class="ltr" type="password" name="db_pass" value="">
<h2>۳) حساب مدیر سیستم</h2>
<label>نام</label>
<input type="text" name="a_first" value="<?php echo ins_h($f['a_first']); ?>">
<label>نام خانوادگی</label>
<input type="text" name="a_last" value="<?php echo ins_h($f['a_last']); ?>">
<label>نام کاربری مدیر (انگلیسی) *</label>
<input class="ltr" type="text" name="a_user" value="<?php echo ins_h($f['a_user']); ?>">
<label>رمز عبور (حداقل ۶ نویسه) *</label>
<input class="ltr" type="password" name="a_pass1" value="">
<label>تکرار رمز عبور *</label>
<input class="ltr" type="password" name="a_pass2" value="">
<br>
<button type="submit">نصب و ساخت جدول‌ها</button>
</form>
<?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
