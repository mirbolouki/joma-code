<?php
require dirname(__FILE__) . '/includes/bootstrap.php';

// CLINIC-DEDICATED (v2.0): این ساب‌دامین فقط «مدیریت مطب» است.
// فایل‌ها و داده‌های قدیمی جوما/هم‌مسیر روی دیسک دست‌نخورده می‌مانند ولی هیچ
// routeای به آن‌ها باز نیست (dormant) — هیچ includeای به hammasir.php نمی‌شود.
$p = isset($_GET['p']) ? $_GET['p'] : 'clinic_dashboard';
$allowed = array(
    'clinic_login', 'logout',
    'clinic_dashboard', 'clinic_clients', 'clinic_client', 'clinic_intake',
    'clinic_appointments', 'clinic_finance', 'clinic_receipt',
    'clinic_settings', 'clinic_import', 'clinic_users',
);
// نگاشت لینک‌های قدیمی ذخیره‌شده در مرورگر/بوکمارک کاربران
$legacy_map = array(
    'login' => 'clinic_login', 'register' => 'clinic_login', 'forgot' => 'clinic_login',
    'home' => 'clinic_dashboard', 'dashboard' => 'clinic_dashboard',
);
if (isset($legacy_map[$p])) {
    joma_redirect('index.php?p=' . $legacy_map[$p]);
}
if (!in_array($p, $allowed, true)) $p = 'clinic_dashboard';
// لایه دیتابیس (سبک، بدون وابستگی)
require_once dirname(__FILE__) . '/functions/clinic_db.php';
// گیت نصب: تا نصب انجام نشده، همه‌چیز به install.php می‌رود
if (!clinic_db_is_installed()) {
    header('Location: install.php');
    exit;
}
// توابع مطب
require_once dirname(__FILE__) . '/functions/clinic.php';
// نشست قدیمی (نقش ناآشنا برای مطب) معتبر نیست — خروج تمیز و هدایت به ورود مطب
$__cu = current_user();
if ($__cu) {
    $__cr = isset($__cu['role_key']) ? $__cu['role_key'] : '';
    if (!isset(clinic_roles()[$__cr])) {
        unset($_SESSION['user']);
        joma_redirect('index.php?p=clinic_login');
    }
}
unset($__cu, $__cr);
// گیت ورود سراسری (به‌جز صفحه ورود و فرم عمومی اینتیک)
if ($p !== 'clinic_login' && $p !== 'clinic_intake') {
    require_login();
}
$file = dirname(__FILE__) . '/pages/' . $p . '.php';
if (!file_exists($file)) $p = 'clinic_dashboard';
require dirname(__FILE__) . '/pages/' . $p . '.php';
