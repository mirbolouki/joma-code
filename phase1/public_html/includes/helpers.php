<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — توابع کمکی عمومی
 * ═══════════════════════════════════════════════════════════════════ */

/** پاک‌سازی خروجی برای جلوگیری از XSS */
function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** یکدست‌سازی شمارهٔ موبایل — شناسهٔ اصلی اشخاص در سامانه */
function normalize_mobile_number($raw)
{
    $raw = to_latin_digits($raw);
    $digits = preg_replace('/\D/', '', (string)$raw);
    if (strpos($digits, '0098') === 0) {
        $digits = substr($digits, 4);
    }
    if (strpos($digits, '98') === 0 && strlen($digits) === 12) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) === 10 && isset($digits[0]) && $digits[0] === '9') {
        $digits = '0' . $digits;
    }
    return $digits;
}

/** شناسهٔ عمومی غیرقابل‌حدس: مثلاً ad_3f9c1b... (حداکثر ۲۳ نویسه) */
function generate_public_id($prefix)
{
    return $prefix . '_' . bin2hex(random_bytes(10));
}

/** زمان کنونی به وقت جهانی (UTC) — قالب ذخیره در دیتابیس */
function now_dt()
{
    return gmdate('Y-m-d H:i:s');
}

/** زمان گذشته/آینده به وقت جهانی */
function utc_dt_offset($seconds)
{
    return gmdate('Y-m-d H:i:s', time() + $seconds);
}

function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

function flash_set($key, $message)
{
    $_SESSION['flash'][$key] = $message;
}

function flash_get($key)
{
    if (isset($_SESSION['flash'][$key])) {
        $msg = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
    return null;
}

/** نمایش پیام فلش به‌صورت جعبهٔ رنگی */
function flash_render($key, $class)
{
    $msg = flash_get($key);
    if ($msg === null) {
        return '';
    }
    return '<div class="alert alert-' . e($class) . '">' . e($msg) . '</div>';
}

function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . e($_SESSION['csrf_token']) . '">';
}

function csrf_check()
{
    $token = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(400);
        die('درخواست نامعتبر است؛ لطفاً صفحه را دوباره بارگذاری کنید.');
    }
}

/**
 * ثبت خطای فنی در فایل لاگ و بازگرداندن کد پیگیری کوتاه.
 * هیچ‌گاه متن خطای واقعی به کاربر نشان داده نمی‌شود.
 */
function log_system_error($context, $exception)
{
    $ref = bin2hex(random_bytes(4));
    $message = is_object($exception) && method_exists($exception, 'getMessage')
        ? $exception->getMessage()
        : (string)$exception;
    $log_line = gmdate('Y-m-d H:i:s') . " [REF:{$ref}] [{$context}] " . $message . "\n";
    @file_put_contents(
        __DIR__ . '/../storage/logs/error_' . gmdate('Y-m-d') . '.log',
        $log_line,
        FILE_APPEND
    );
    return $ref;
}

/** نمایش صفحهٔ خطای عمومی با کد پیگیری و پایان اجرا */
function render_error_page($ref)
{
    $error_ref = $ref;
    http_response_code(500);
    require __DIR__ . '/../templates/error_generic.php';
    exit;
}

/** نشانی داشبورد هر نقش */
function dashboard_url_for_role($role)
{
    if ($role === ROLE_ADMIN)     { return APP_BASE_URL . '/admin/index.php'; }
    if ($role === ROLE_SECRETARY) { return APP_BASE_URL . '/reception/index.php'; }
    if ($role === ROLE_THERAPIST) { return APP_BASE_URL . '/therapist/index.php'; }
    if ($role === ROLE_PATIENT)   { return APP_BASE_URL . '/patient/index.php'; }
    return APP_BASE_URL . '/index.php';
}

/** برچسب فارسی نقش‌ها */
function role_label($role_code)
{
    $labels = array(
        'admin'         => 'مدیر',
        'secretary'     => 'منشی',
        'therapist'     => 'درمانگر',
        'psychometrist' => 'روان‌سنج',
        'patient'       => 'مراجع',
    );
    return isset($labels[$role_code]) ? $labels[$role_code] : $role_code;
}

/** برچسب فارسی وضعیت پذیرش */
function admission_status_label($status)
{
    $labels = array(
        'AWAITING_THERAPIST' => 'در انتظار درمانگر',
        'ACCEPTED'           => 'پذیرفته شد',
        'DECLINED'           => 'رد شد',
    );
    return isset($labels[$status]) ? $labels[$status] : $status;
}

/** کلاس CSS نشانگر وضعیت پذیرش */
function admission_status_class($status)
{
    if ($status === 'ACCEPTED') { return 'status-active'; }
    if ($status === 'DECLINED') { return 'status-off'; }
    return 'status-waiting';
}

/** تشخیص درخواست AJAX */
function is_ajax_request()
{
    return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/** پاسخ JSON و پایان اجرا */
function json_response($data)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** آیا اتصال فعلی امن (HTTPS) است؟ */
function is_https_request()
{
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
        && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    return false;
}
