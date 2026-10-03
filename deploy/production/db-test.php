<?php
declare(strict_types=1);

/**
 * JOMA DB Diagnostic Tool
 * Run: https://my.mirbolouki.com/db-test.php
 * Shows exactly why MySQL connection fails (wrong password, wrong user, port, socket, etc.)
 */

ini_set('display_errors', '1');
error_reporting(E_ALL);

$configFile = __DIR__ . '/joma-config.php';
if (!is_file($configFile)) {
    // Also check demo config just in case
    $configFile = __DIR__ . '/joma-demo-config.php';
}

if (!is_file($configFile)) {
    echo "<h2>❌ خطا: فایل کانفیگ یافت نشد</h2>";
    echo "<p>مطمئن شوید فایل <code>joma-config.example.php</code> را به <code>joma-config.php</code> تغییر نام داده‌اید.</p>";
    exit;
}

require $configFile;
$cfg = $joma_config ?? ($joma_demo_config ?? null);

if (!$cfg || !is_array($cfg)) {
    echo "<h2>❌ خطا: آرایه تنظیمات در فایل کانفیگ موجود نیست</h2>";
    exit;
}

$host = $cfg['host'] ?? '127.0.0.1';
$port = (int)($cfg['port'] ?? 3306);
$user = $cfg['username'] ?? '';
$pass = $cfg['password'] ?? '';
$db   = $cfg['database'] ?? '';

echo "<h2>🔍 ابزار عیب‌یابی اتصال دیتابیس (DB Diagnostic)</h2>";
echo "<ul>";
echo "<li><b>Host:</b> " . htmlspecialchars($host) . "</li>";
echo "<li><b>Port:</b> " . htmlspecialchars((string)$port) . "</li>";
echo "<li><b>Database:</b> " . htmlspecialchars($db) . "</li>";
echo "<li><b>Username:</b> " . htmlspecialchars($user) . "</li>";
echo "<li><b>Password Length:</b> " . strlen($pass) . " کاراکتر " . (strlen($pass) === 0 ? "⚠️ (خالی است!)" : "") . "</li>";
echo "</ul>";

echo "<h3>تست اتصال با mysqli:</h3>";

// 1. Try with 127.0.0.1
$conn1 = @mysqli_init();
$conn1->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
$ok1 = @$conn1->real_connect($host, $user, $pass, $db, $port);

if ($ok1) {
    echo "<p style='color:green;font-weight:bold;'>✓ اتصال موفق با Host: $host</p>";
    echo "<p>نسخه سرور: " . htmlspecialchars($conn1->server_info) . "</p>";
    $conn1->close();
} else {
    $errNum1 = mysqli_connect_errno();
    $errMsg1 = mysqli_connect_error();
    echo "<p style='color:red;'>✗ خطا در اتصال با Host: $host</p>";
    echo "<p><b>کد خطا:</b> $errNum1<br><b>پیام خطا:</b> " . htmlspecialchars($errMsg1) . "</p>";

    // If 127.0.0.1 failed, try localhost (Unix socket in cPanel)
    if ($host === '127.0.0.1') {
        echo "<h3>تلاش ثانویه با Host: localhost (سوکت محلی cPanel):</h3>";
        $conn2 = @mysqli_init();
        $conn2->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
        $ok2 = @$conn2->real_connect('localhost', $user, $pass, $db);
        if ($ok2) {
            echo "<p style='color:green;font-weight:bold;'>✓ اتصال با localhost موفقیت‌آمیز بود!</p>";
            echo "<p style='color:blue;'><b>راه حل:</b> در فایل <code>joma-config.php</code> مقدار <code>'host' => '127.0.0.1'</code> را به <code>'host' => 'localhost'</code> تغییر دهید.</p>";
            $conn2->close();
        } else {
            $errNum2 = mysqli_connect_errno();
            $errMsg2 = mysqli_connect_error();
            echo "<p style='color:red;'>✗ اتصال با localhost نیز ناموفق بود:<br><b>کد خطا:</b> $errNum2<br><b>پیام خطا:</b> " . htmlspecialchars($errMsg2) . "</p>";
        }
    }
}

echo "<hr><p style='color:#666;font-size:12px;'>پس از رفع مشکل، این فایل (<code>db-test.php</code>) را برای امنیت بیشتر حذف کنید.</p>";
