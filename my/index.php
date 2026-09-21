<?php
require dirname(__FILE__) . '/includes/bootstrap.php';
$p = isset($_GET['p']) ? $_GET['p'] : 'home';
$allowed = array(
    'home', 'login', 'register', 'forgot', 'logout',
    'mood', 'dashboard', 'plan', 'today', 'library',
    'periods', 'period', 'reports', 'profile', 'settings', 'about', 'support', 'learn',
);
// CLINIC (مدیریت مطب) — ADD-ONLY: فقط همین ۱۰ route اضافه شده است.
$allowed[] = 'clinic_dashboard';
$allowed[] = 'clinic_clients';
$allowed[] = 'clinic_client';
$allowed[] = 'clinic_intake';
$allowed[] = 'clinic_appointments';
$allowed[] = 'clinic_finance';
$allowed[] = 'clinic_receipt';
$allowed[] = 'clinic_settings';
$allowed[] = 'clinic_import';
$allowed[] = 'clinic_users';
// CLINIC: لود تنبل توابع مطب — فقط وقتی صفحه مطب درخواست شود (الگوی هم‌مسیر)؛
// صفحات قبلی هیچ فایل/تابعی از مطب لود نمی‌کنند، پس خطای احتمالی مطب به آن‌ها سرایت نمی‌کند.
if (strpos($p, 'clinic_') === 0) {
    $__clinic_lib = dirname(__FILE__) . '/functions/clinic.php';
    if (is_file($__clinic_lib)) require_once $__clinic_lib;
    unset($__clinic_lib);
}
// JOMA-HAMMASIR-BEGIN
// اتصال حداقلی ماژول «هم‌مسیر» (Phase 1B — D26/D28/D29؛ fail-closed)
// با Flag خاموش: فقط همین فایل کانفیگ کوچک خوانده می‌شود؛ هیچ query،
// include توابع هم‌مسیر، صفحه یا تغییر رفتاری رخ نمی‌دهد و $allowed همان مقدار قبلی می‌ماند.
// اگر کانفیگ/توابع موجود نباشند یا خطایی رخ دهد، این بلوک کاملاً بی‌اثر می‌ماند (fail-closed؛ PC-4).
// لود توابع هم‌مسیر فقط برای route خود هم‌مسیر انجام می‌شود (نه همه‌ی صفحات).
try {
    $__hammasir_cfg_file = dirname(__FILE__) . '/config/hammasir_config.php';
    if (is_file($__hammasir_cfg_file)) {
        $HAMMASIR_CONFIG = array();
        include $__hammasir_cfg_file;
        if (isset($HAMMASIR_CONFIG['hammasir_enabled']) && $HAMMASIR_CONFIG['hammasir_enabled'] === true) {
            // صفحات ماژول (حکم PO — بخش دو): صفحه‌ی مرکزی + دو صفحه‌ی مستقل مدیریت
            $__hammasir_pages = array('hammasir', 'hammasir_providers', 'hammasir_admins');
            if (in_array($p, $__hammasir_pages, true) && !defined('JOMA_IN_APP')) {
                define('JOMA_IN_APP', true);
            }
            if (in_array($p, $__hammasir_pages, true)) {
                $__hammasir_functions = dirname(__FILE__) . '/functions/hammasir.php';
                if (is_file($__hammasir_functions)) {
                    include_once $__hammasir_functions;
                }
            }
            foreach ($__hammasir_pages as $__hammasir_page) {
                $allowed[] = $__hammasir_page;
            }
            unset($__hammasir_page, $__hammasir_pages);
        }
        unset($HAMMASIR_CONFIG);
    }
    unset($__hammasir_cfg_file);
} catch (Throwable $e) {
    // fail-closed: هیچ خروجی؛ route باز نمی‌شود؛ فقط لاگ امن با پیام ثابت (PC-4)
    error_log('hammasir module kept disabled: safe load of hammasir config/functions failed.');
}
// JOMA-HAMMASIR-END
if (!in_array($p, $allowed, true)) $p = 'home';
maybe_mood_gate($p);
$file = dirname(__FILE__) . '/pages/' . $p . '.php';
if (!file_exists($file)) $p = 'home';
require dirname(__FILE__) . '/pages/' . $p . '.php';
