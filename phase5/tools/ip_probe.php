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

/* ── محاسبهٔ درست پیشوند /64 ────────────────────────────────────────
 * هشدار: explode(':') و برداشتن چهار بخش اول، روی نشانی فشردهٔ IPv6
 * نتیجهٔ غلط می‌دهد؛ «2001:db8::1» چهار بخش ندارد.
 * تنها راه درست، تبدیل به صورت دودویی و ماسک‌کردن ۶۴ بیت اول است. */
function ipv6_prefix64($ip)
{
    $bin = @inet_pton($ip);
    if ($bin === false || strlen($bin) !== 16) {
        return null;                      /* IPv4 یا نشانی نامعتبر */
    }
    return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8));
}

$peer = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
$p64  = ipv6_prefix64($peer);
if ($p64 !== null) {
    echo "IPv6 است. آنچه باید هش شود، پیشوند /64 است:\n";
    echo "  نشانی کامل : " . $peer . "\n";
    echo "  پیشوند /64 : " . $p64 . "\n";
} else {
    echo "IPv4 است (یا نشانی نامعتبر). کل نشانی هش می‌شود.\n";
}

/* آیا نشانیِ متصل‌شونده خصوصی است؟ اگر بله، تقریباً قطعاً یک پروکسی
   جلوی سرور نشسته و IP واقعی در یکی از هدرهای بالا است. */
$is_public = filter_var($peer, FILTER_VALIDATE_IP,
                        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
echo "REMOTE_ADDR عمومی است؟ " . ($is_public !== false
     ? 'بله — احتمالاً سرور مستقیم است'
     : 'خیر — یعنی یک پروکسی یا متعادل‌کنندهٔ بار جلوی سرور هست') . "\n";
echo "PHP " . PHP_VERSION . "\n";
echo "\n★ پس از ارسال خروجی، این فایل را حذف کنید. ★\n";
