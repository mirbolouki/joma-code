<?php
/* ============================================================
   JOMA — فایل تشخیص (نسخه ۲)
   آدرس: آدرس-سایت/diag-admin.php
   نتیجه را بفرستید و سپس این فایل را پاک کنید.
   ============================================================ */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ob_start();
header('Content-Type: text/plain; charset=utf-8');

function diag_out($s) { echo $s, "\n"; }

diag_out('== JOMA DIAG v2 ==');
diag_out('PHP ' . PHP_VERSION);

$root = __DIR__;

try {
    require $root . '/includes/bootstrap.php';
    diag_out('bootstrap: OK');
} catch (Throwable $e) {
    diag_out('bootstrap: FAIL -> ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    exit;
}

diag_out('storage: ' . (function_exists('store_mode') ? store_mode() : '?'));

/* آیا فایل اصلاح‌شدهٔ کنسول مدیر نصب شده؟ */
$ac = @file_get_contents($root . '/pages/admin_console.php');
diag_out('admin_console-fix(FIX-AC1): ' . (is_string($ac) && strpos($ac, 'FIX-AC1') !== false ? 'INSTALLED' : 'NOT INSTALLED'));

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
    @mysqli_report(MYSQLI_REPORT_OFF);

    $queries = array(
        'users-list'   => 'SELECT id, first_name, last_name, username, email, phone, job, role_key, access_level, created_at FROM joma_users ORDER BY id',
        'count-events' => 'SELECT COUNT(*) AS c FROM joma_performance_events',
        'count-moods'  => 'SELECT COUNT(*) AS c FROM joma_mood_records',
        'count-notes'  => "SELECT COUNT(*) AS c FROM joma_mood_records WHERE note IS NOT NULL AND note <> ''",
        'count-plans'  => 'SELECT COUNT(*) AS c FROM joma_plans',
        'top-acts-NEW' => "SELECT COALESCE(pa.activity_code, '-') AS activity_code, COUNT(*) AS c FROM joma_performance_events ev LEFT JOIN joma_plan_activities pa ON pa.id = ev.plan_activity_id GROUP BY COALESCE(pa.activity_code, '-') ORDER BY c DESC LIMIT 8",
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

    /* جدول‌های هم‌مسیر — اگر نبودند باید فایل‌های مرحلهٔ ۸ اجرا شوند */
    $htables = array('joma_hammasir_providers','joma_hammasir_links','joma_hammasir_messages','joma_hammasir_permissions','joma_hammasir_permission_history','joma_hammasir_system_events','joma_hammasir_user_flags','joma_hammasir_invite_codes');
    foreach ($htables as $t) {
        $r = @mysqli_query($m, "SELECT 1 FROM `$t` LIMIT 1");
        if ($r) {
            diag_out('table[' . $t . ']: OK');
            @mysqli_free_result($r);
        } else {
            diag_out('table[' . $t . ']: MISSING');
        }
    }
    @mysqli_close($m);
} else {
    diag_out('db-queries: SKIP (storage is not mysql)');
}

diag_out('== END ==');
