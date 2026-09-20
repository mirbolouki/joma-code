<?php
/* ============================================================
   JOMA — فایل تشخیص موقت (مشکل ۵۰۰ صفحهٔ کنسول مدیر)
   این فایل را در ریشهٔ سایت اصلی آپلود کنید و آدرسش را باز کنید:
       آدرس-سایت/diag-admin.php
   نتیجه را برای پشتیبانی بفرستید و سپس این فایل را پاک کنید.
   هیچ رمزی را نمایش نمی‌دهد؛ فقط وضعیت هر بخش را چاپ می‌کند.
   ============================================================ */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ob_start(); // اجازه می‌دهد بوت‌استرپ نشست را بدون تداخل با خروجی شروع کند
header('Content-Type: text/plain; charset=utf-8');

function diag_out($s) { echo $s, "\n"; }

diag_out('== JOMA DIAG admin_console ==');
diag_out('PHP ' . PHP_VERSION);

$root = __DIR__;

/* ۱) بارگذاری بوت‌استرپ (کانفیگ + همهٔ فایل‌های توابع) */
try {
    require $root . '/includes/bootstrap.php';
    diag_out('bootstrap: OK');
} catch (Throwable $e) {
    diag_out('bootstrap: FAIL -> ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    exit;
}

diag_out('storage: ' . (function_exists('store_mode') ? store_mode() : '?'));

/* ۲) اگر حالت دیتابیس است، اتصال و تک‌تک کوئری‌های صفحهٔ کنسول مدیر */
if (function_exists('store_mode') && store_mode() === 'mysql') {
    $c = isset($GLOBALS['JOMA_CONFIG']) ? $GLOBALS['JOMA_CONFIG'] : array();
    $m = @mysqli_connect(
        isset($c['db_host']) ? $c['db_host'] : '',
        isset($c['db_user']) ? $c['db_user'] : '',
        isset($c['db_pass']) ? $c['db_pass'] : '',
        isset($c['db_name']) ? $c['db_name'] : ''
    );
    if (!$m) {
        diag_out('db-connect: FAIL -> ' . mysqli_connect_error());
        exit;
    }
    diag_out('db-connect: OK');
    @mysqli_set_charset($m, 'utf8mb4');

    $queries = array(
        'users-list'   => 'SELECT id, first_name, last_name, username, email, phone, job, role_key, access_level, created_at FROM joma_users ORDER BY id',
        'count-events' => 'SELECT COUNT(*) AS c FROM joma_performance_events',
        'count-moods'  => 'SELECT COUNT(*) AS c FROM joma_mood_records',
        'count-notes'  => "SELECT COUNT(*) AS c FROM joma_mood_records WHERE note IS NOT NULL AND note <> ''",
        'count-plans'  => 'SELECT COUNT(*) AS c FROM joma_plans',
        'top-acts'     => 'SELECT activity_code, COUNT(*) AS c FROM joma_performance_events GROUP BY activity_code ORDER BY c DESC LIMIT 8',
        'settings'     => 'SELECT setting_key FROM joma_settings LIMIT 1',
    );
    foreach ($queries as $name => $sql) {
        $r = @mysqli_query($m, $sql);
        if ($r) {
            diag_out('query[' . $name . ']: OK');
            @mysqli_free_result($r);
        } else {
            diag_out('query[' . $name . ']: FAIL -> ' . mysqli_error($m));
        }
    }
    @mysqli_close($m);
} else {
    diag_out('db-queries: SKIP (storage is not mysql)');
}

/* ۳) وجود تابع‌هایی که صفحهٔ کنسول مدیر استفاده می‌کند */
$fns = array('fa_num', 'joma_header', 'joma_footer', 'joma_v2_pagehead', 'role_label', 'jalali_format', 'official_library', 'joma_kv_get_json', 'require_perm', 'has_perm');
foreach ($fns as $f) {
    diag_out('fn[' . $f . ']: ' . (function_exists($f) ? 'OK' : 'MISSING'));
}

/* ۴) بررسی نحوی خودِ فایل صفحه */
$src = @file_get_contents($root . '/pages/admin_console.php');
if ($src === false) {
    diag_out('page-file: MISSING');
} else {
    $tok = @token_get_all($src);
    diag_out('page-file: ' . (is_array($tok) ? 'parse OK (' . count($tok) . ' tokens)' : 'PARSE FAIL'));
}

diag_out('== END ==');
