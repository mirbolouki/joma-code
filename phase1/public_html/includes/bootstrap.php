<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — نقطهٔ شروع مشترک همهٔ صفحات
 *  هر فایل PHP سامانه، نخست این فایل را فراخوانی می‌کند.
 * ═══════════════════════════════════════════════════════════════════ */

error_reporting(E_ALL);
ini_set('display_errors', '0');   /* هیچ خطای فنی به کاربر نشان داده نمی‌شود */
ini_set('log_errors', '1');

if (!file_exists(__DIR__ . '/config.php')) {
    die('سامانه هنوز نصب نشده است. لطفاً نشانی install.php را در مرورگر باز کنید.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jalali.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/sms.php';
require_once __DIR__ . '/otp_functions.php';
require_once __DIR__ . '/person_functions.php';
require_once __DIR__ . '/account_functions.php';
require_once __DIR__ . '/staff_functions.php';
require_once __DIR__ . '/admission_functions.php';
require_once __DIR__ . '/case_functions.php';
require_once __DIR__ . '/lookup_functions.php';
require_once __DIR__ . '/audit_functions.php';
/* ── فاز ۲: نوبت‌دهی و تقویم ── */
require_once __DIR__ . '/schedule_time.php';
require_once __DIR__ . '/room_functions.php';
require_once __DIR__ . '/tariff_functions.php';
require_once __DIR__ . '/appointment_functions.php';

/* منطقهٔ زمانی داخلی PHP روی UTC؛ تبدیل به وقت تهران فقط هنگام نمایش */
date_default_timezone_set('UTC');

/* مسیر کوکی نشست با محل نصب هماهنگ می‌شود (پشتیبانی از نصب در زیرپوشه) */
$joma_cookie_path = '/';
$joma_base_path = parse_url(APP_BASE_URL, PHP_URL_PATH);
if (is_string($joma_base_path) && $joma_base_path !== '' && $joma_base_path !== '/') {
    $joma_cookie_path = rtrim($joma_base_path, '/') . '/';
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(0, $joma_cookie_path, '', is_https_request(), true);
    session_name('JOMASESSID');
    session_start();
}

$GLOBALS['db'] = db_connect();

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
