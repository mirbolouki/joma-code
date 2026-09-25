<?php
// Temporary authenticated database diagnostic. NOT the application or an installer.
// Upload this file and expected-schema.json to the website document ROOT.
// Place joma-db-check-config.php in its parent, outside public web access.
ini_set('display_errors', '0');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Referrer-Policy: no-referrer');
header('Content-Type: text/html; charset=utf-8');

function joma_check_escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function joma_check_line($name, $ok, $detail = '') {
    echo '<p>' . ($ok ? 'PASS' : 'FAIL') . ' — ' . joma_check_escape($name);
    if ($detail !== '') { echo ': ' . joma_check_escape($detail); }
    echo '</p>';
}
function joma_check_uuid() {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
    $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
    return $bytes;
}
function joma_check_exec($db, $sql, $args = []) {
    $statement = $db->prepare($sql);
    if ($args) { $statement->bind_param(str_repeat('s', count($args)), ...$args); }
    $statement->execute();
    $statement->close();
}
function joma_check_expect_error($db, $name, $sql, $args, $expectedCode) {
    try {
        joma_check_exec($db, $sql, $args);
        joma_check_line($name, false, 'Invalid write was accepted');
        return false;
    } catch (mysqli_sql_exception $e) {
        $ok = (int) $e->getCode() === $expectedCode;
        joma_check_line($name, $ok, 'Database error code ' . (int) $e->getCode());
        return $ok;
    }
}

$configPath = dirname(__DIR__) . '/joma-db-check-config.php';
try { $config = is_file($configPath) ? require $configPath : []; }
catch (Throwable $e) { $config = []; }
if (!is_array($config) || empty($config['enabled']) ||
    !is_string($config['access_token'] ?? null) || strlen($config['access_token']) < 32) {
    http_response_code(404); exit('Not available');
}
// Do not trust arbitrary X-Forwarded-Proto headers. If the host terminates TLS upstream,
// configure its trusted HTTPS signal; do not remove this guard for a public test.
if (empty($_SERVER['HTTPS']) || strtolower((string) $_SERVER['HTTPS']) === 'off') {
    http_response_code(403); exit('HTTPS required');
}
echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>بررسی موقت اتصال جوما</title><body><h1>بررسی موقت پایگاه آزمایشی جوما</h1>';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo '<p>این ابزار نصب‌کننده یا ورود به برنامه نیست. فقط توکن موقت را وارد کنید، نه رمز دیتابیس.</p>';
    echo '<form method="post"><label>توکن موقت <input type="password" name="token" minlength="32" maxlength="512" autocomplete="off" required></label>';
    echo '<p><button name="mode" value="read">بررسی اتصال و ساختار — بدون نوشتن داده</button></p>';
    if (!empty($config['allow_rollback_tests'])) {
        echo '<p><button name="mode" value="rollback">تست قیود با داده ساختگی و ROLLBACK</button></p>';
    }
    echo '</form></body></html>'; exit;
}
$token = $_POST['token'] ?? null;
if (!is_string($token) || strlen($token) > 512 || !hash_equals($config['access_token'], $token)) {
    http_response_code(403); exit('Access denied');
}
unset($token);
if (!extension_loaded('mysqli')) { joma_check_line('mysqli', false); exit; }
if (empty($config['test_database_confirmed']) || ($config['db']['name'] ?? '') !== 'mirbolouki_clinic') {
    joma_check_line('Disposable database confirmation', false); exit;
}
$manifest = json_decode((string) @file_get_contents(__DIR__ . '/expected-schema.json'), true);
if (!is_array($manifest) || count($manifest['tables'] ?? []) !== 70) {
    joma_check_line('Expected schema manifest', false); exit;
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = null;
try {
    $db = mysqli_init();
    $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
    $db->real_connect($config['db']['host'], $config['db']['user'], $config['db']['password'],
        $config['db']['name'], (int) ($config['db']['port'] ?? 3306));
    $db->set_charset('utf8mb4');
    $db->query("SET SESSION time_zone = '+00:00'");
    $environment = $db->query("SELECT VERSION() AS v, @@foreign_key_checks AS fk_on, @@check_constraint_checks AS ck_on, @@sql_mode AS mode")->fetch_assoc();
    joma_check_line('mysqli connection', true, 'PHP ' . PHP_VERSION . ' / ' . $environment['v']);
    $targetOk = stripos($environment['v'], 'MariaDB') !== false && preg_match('/(?:^|-)10\.11\./', $environment['v']);
    joma_check_line('MariaDB 10.11 target', (bool) $targetOk);
    $strict = strpos($environment['mode'], 'STRICT_TRANS_TABLES') !== false || strpos($environment['mode'], 'STRICT_ALL_TABLES') !== false;
    $checksOn = (int) $environment['fk_on'] === 1 && (int) $environment['ck_on'] === 1;
    joma_check_line('Constraint checks enabled', $checksOn);
    joma_check_line('Strict SQL mode', $strict);

    $tables = [];
    $rows = $db->query("SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'");
    while ($row = $rows->fetch_assoc()) { $tables[$row['TABLE_NAME']] = $row; }
    $nonJomaTables = array_filter(array_keys($tables), function ($name) { return strpos($name, 'joma_') !== 0; });
    $missingTables = array_diff($manifest['tables'], array_keys($tables));
    $unexpectedTables = array_diff(array_filter(array_keys($tables), function ($name) { return strpos($name, 'joma_') === 0; }), $manifest['tables']);
    $tableOk = !$missingTables && !$unexpectedTables;
    foreach ($manifest['tables'] as $table) {
        if (isset($tables[$table]) && ($tables[$table]['ENGINE'] !== 'InnoDB' || $tables[$table]['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci')) { $tableOk = false; }
    }
    joma_check_line('70 expected InnoDB tables / table collation', $tableOk, 'Missing: ' . count($missingTables));
    $actual = [];
    $rows = $db->query("SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()");
    while ($row = $rows->fetch_assoc()) { $actual[$row['CONSTRAINT_TYPE']][] = $row['CONSTRAINT_NAME']; }
    $fkOk = !array_diff($manifest['foreign_keys'], $actual['FOREIGN KEY'] ?? []);
    $ckOk = !array_diff($manifest['explicit_checks'], $actual['CHECK'] ?? []);
    $fkDetails = [];
    $rows = $db->query("SELECT CONSTRAINT_NAME,TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY CONSTRAINT_NAME,ORDINAL_POSITION");
    while ($row = $rows->fetch_assoc()) {
        $name = $row['CONSTRAINT_NAME'];
        $fkDetails[$name]['table'] = $row['TABLE_NAME'];
        $fkDetails[$name]['referenced_table'] = $row['REFERENCED_TABLE_NAME'];
        $fkDetails[$name]['columns'][] = $row['COLUMN_NAME'];
        $fkDetails[$name]['referenced_columns'][] = $row['REFERENCED_COLUMN_NAME'];
    }
    foreach ($manifest['foreign_key_details'] ?? [] as $expected) {
        $actualFk = $fkDetails[$expected['name']] ?? [];
        foreach (['table','referenced_table','columns','referenced_columns'] as $field) {
            if (($actualFk[$field] ?? null) !== $expected[$field]) { $fkOk = false; }
        }
    }
    if (count($manifest['foreign_key_details'] ?? []) !== 173) { $fkOk = false; }
    joma_check_line('173 foreign keys: names, tables, columns', $fkOk);
    joma_check_line('90 explicit CHECK constraints', $ckOk, 'MariaDB JSON_VALID adds further checks');
    $badRules = (int) $db->query("SELECT COUNT(*) AS n FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,5)='joma_' AND (DELETE_RULE <> 'RESTRICT' OR UPDATE_RULE <> 'RESTRICT')")->fetch_assoc()['n'];
    joma_check_line('RESTRICT referential actions', $badRules === 0);
    $indexes = [];
    $rows = $db->query("SELECT TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX,COLUMN_NAME,NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX");
    while ($row = $rows->fetch_assoc()) {
        if ((int) $row['NON_UNIQUE'] === 0) { $indexes[$row['TABLE_NAME']][$row['INDEX_NAME']][] = $row['COLUMN_NAME']; }
    }
    $uniqueOk = true;
    foreach ($manifest['unique_indexes'] as $key) {
        if (($indexes[$key['table']][$key['name']] ?? null) !== $key['columns']) { $uniqueOk = false; }
    }
    joma_check_line('72 UNIQUE indexes and their columns', $uniqueOk);
    $metadataOk = $targetOk && $strict && $checksOn && $tableOk && $fkOk && $ckOk && $badRules === 0 && $uniqueOk;
    echo '<p>این نتیجه فقط اتصال و بخشی از ساختار را بررسی می‌کند؛ تست مجوز، هم‌زمانی یا انطباق کامل برنامه نیست.</p>';

    if ($nonJomaTables) {
        echo '<p>احتیاط: جدول‌های خارج از پیشوند joma_ موجودند. دیتابیس خالی و یک‌بارمصرف فرض نمی‌شود؛ تست نوشتنی مسدود است.</p>';
    }
    if (($_POST['mode'] ?? 'read') === 'rollback') {
        if (empty($config['allow_rollback_tests']) || !$metadataOk || $nonJomaTables) {
            joma_check_line('Write-test gate', false, 'Disabled, metadata failed, or non-JOMA tables exist');
        } else {
            $db->begin_transaction();
            try {
                $p = joma_check_uuid(); $scope = joma_check_uuid(); $template = joma_check_uuid();
                $tag = 'joma_probe_' . bin2hex(random_bytes(8));
                joma_check_exec($db, "INSERT INTO joma_persons(id,given_name,status) VALUES(?,?,'ACTIVE')", [$p,$tag]);
                joma_check_exec($db, "INSERT INTO joma_work_scopes(id,kind,label,status) VALUES(?,'CENTER',?,'ACTIVE')", [$scope,$tag]);
                $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
                $accountSql = "INSERT INTO joma_accounts(id,person_id,login_name,password_hash,status) VALUES(?,?,?,?,'ACTIVE')";
                joma_check_exec($db,$accountSql,[joma_check_uuid(),$p,$tag.'_a',$hash]);
                joma_check_expect_error($db,'Duplicate ACTIVE account rejected',$accountSql,[joma_check_uuid(),$p,$tag.'_b',$hash],1062);
                joma_check_expect_error($db,'Missing Person FK rejected',$accountSql,[joma_check_uuid(),joma_check_uuid(),$tag.'_c',$hash],1452);
                joma_check_expect_error($db,'Invalid Person status CHECK rejected',"INSERT INTO joma_persons(id,given_name,status) VALUES(?,?,'INVALID')",[joma_check_uuid(),$tag],4025);
                joma_check_expect_error($db,'Neither authority basis rejected',"INSERT INTO joma_verification_authority_bases(id,actor_person_id,scope_id) VALUES(?,?,?)",[joma_check_uuid(),$p,$scope],4025);
                joma_check_exec($db,"INSERT INTO joma_form_templates(id,scope_id,label,form_kind) VALUES(?,?,?,'CLINICAL')",[$template,$scope,$tag]);
                $formSql="INSERT INTO joma_form_versions(id,template_id,version_no,schema_json,status,author_person_id) VALUES(?,?,? ,?,'DRAFT',?)";
                joma_check_exec($db,$formSql,[joma_check_uuid(),$template,'1','{"fields":[]}',$p]);
                joma_check_line('Valid JSON object accepted',true);
                joma_check_expect_error($db,'Malformed JSON rejected',$formSql,[joma_check_uuid(),$template,'2','{',$p],4025);
                joma_check_expect_error($db,'Wrong JSON root (array) rejected',$formSql,[joma_check_uuid(),$template,'3','[]',$p],4025);
                echo '<p>این آزمون چند قید پایه را می‌سنجد؛ جلسه/نوبت، رقابت دو اتصال و دسترسی پورتال هنوز جدا باید آزموده شوند.</p>';
            } finally {
                $db->rollback();
                joma_check_line('ROLLBACK of artificial test rows',true);
            }
        }
    }
} catch (Throwable $e) {
    // Do not expose raw connection errors, SQL, paths, credentials, or provider data.
    http_response_code(500);
    joma_check_line('Diagnostic stopped',false,'Error code ' . (int) $e->getCode());
    echo '<p>کد خطا را بدون اطلاعات محرمانه گزارش کنید. خطا به معنی موفقیت تست نیست.</p>';
} finally {
    if ($db instanceof mysqli) { try { $db->close(); } catch (Throwable $ignored) {} }
}
echo '<p>پس از پایان، ابزار، manifest عمومی و فایل تنظیم موقت را از هاست حذف کنید.</p></body></html>';
