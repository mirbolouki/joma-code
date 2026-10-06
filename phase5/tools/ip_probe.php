<?php
/* ═══════════════════════════════════════════════════════════════════
 *  ip_probe.php — ابزار تشخیص موقت
 *
 *  هدف: معلوم کند IP واقعی بازدیدکننده چطور به PHP می‌رسد.
 *  بدون این، سقف مبتنی بر IP یا کار نمی‌کند یا قابل دور زدن است.
 *
 *  روش استفاده:
 *   ۱) این فایل را در ریشهٔ my.mirbolouki.com بگذارید.
 *   ۲) یک بار با مرورگر گوشی روی «دیتای موبایل» بازش کنید (نه وای‌فای).
 *   ۳) خروجی را برای ایجنت بفرستید.
 *   ۴) ★ فایل را فوراً حذف کنید. ★
 * ═══════════════════════════════════════════════════════════════════ */
header('Content-Type: text/plain; charset=utf-8');

echo "REMOTE_ADDR = " . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '(ندارد)') . "\n";
echo "SERVER_ADDR = " . (isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '(ندارد)') . "\n";
echo "SERVER_SOFTWARE = " . (isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '(ندارد)') . "\n";
echo str_repeat('-', 60) . "\n";

/* هر سرنخی که یک پروکسی یا CDN می‌گذارد */
$interesting = array(
    'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_X_CLIENT_IP',
    'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED',
    'HTTP_FORWARDED_FOR', 'HTTP_VIA', 'HTTP_X_FORWARDED_PROTO',
    'HTTP_CF_CONNECTING_IP', 'HTTP_CF_IPCOUNTRY', 'HTTP_CF_RAY',
    'HTTP_AR_REAL_IP', 'HTTP_X_ARVANCLOUD_REAL_IP',
    'HTTP_TRUE_CLIENT_IP', 'HTTP_X_SUCURI_CLIENTIP',
);
$found = false;
foreach ($interesting as $k) {
    if (isset($_SERVER[$k])) { echo $k . " = " . $_SERVER[$k] . "\n"; $found = true; }
}
if (!$found) {
    echo "هیچ هدر پروکسی‌ای وجود ندارد.\n";
    echo "یعنی احتمالاً REMOTE_ADDR همان IP واقعی کاربر است.\n";
}

echo str_repeat('-', 60) . "\n";
echo "همهٔ هدرهای HTTP_* (برای اطمینان):\n";
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0) { echo "  " . $k . " = " . $v . "\n"; }
}
echo str_repeat('-', 60) . "\n";
echo "IPv6؟ " . (isset($_SERVER['REMOTE_ADDR']) && strpos($_SERVER['REMOTE_ADDR'], ':') !== false
      ? 'بله — پیشوند /64 باید هش شود، نه کل نشانی' : 'خیر (IPv4)') . "\n";
echo "PHP " . PHP_VERSION . "\n";
echo "\n★ پس از ارسال خروجی، این فایل را حذف کنید. ★\n";
