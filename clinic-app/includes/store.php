<?php
function store_mode() {
    return isset($GLOBALS['JOMA_CONFIG']['storage']) ? $GLOBALS['JOMA_CONFIG']['storage'] : 'file';
}

function store_path() {
    if (!empty($GLOBALS['JOMA_STORE_PATH'])) return $GLOBALS['JOMA_STORE_PATH'];
    return dirname(__FILE__) . '/../data/store.json';
}

function store_empty() {
    return array(
        'users' => array(),
        'preferences' => array(),
        'activities' => array(),
        'periods' => array(),
        'plans' => array(),
        'plan_activities' => array(),
        'events' => array(),
        'moods' => array(),
        'projections' => array(),
        'seq' => 1,
        // CLINIC (مدیریت مطب) — کلیدهای جدید؛ با store_load روی دیتای قدیمی هم merge می‌شود
        'clinic_clients' => array(),
        'clinic_invites' => array(),
        'clinic_appointments' => array(),
        'clinic_notes' => array(),
        'clinic_tariffs' => array(),
        'clinic_overrides' => array(),
        'clinic_transactions' => array(),
        'clinic_settings' => array(),
        'clinic_audit' => array(),
        'clinic_file_seq' => array(),
    );
}

function store_load() {
    $path = store_path();
    if (!file_exists($path)) {
        $init = store_empty();
        store_save($init);
        return $init;
    }
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) return store_empty();
    $base = store_empty();
    foreach ($base as $k => $v) {
        if (!isset($data[$k])) $data[$k] = $v;
    }
    return $data;
}

function store_save($data) {
    $dir = dirname(store_path());
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    file_put_contents(store_path(), json_encode($data, JSON_UNESCAPED_UNICODE));
}

function store_next_id(&$data) {
    $id = isset($data['seq']) ? (int) $data['seq'] : 1;
    $data['seq'] = $id + 1;
    return $id;
}

function db() {
    static $mysqli = null;
    if (store_mode() !== 'mysql') return null;
    if ($mysqli) return $mysqli;
    $c = $GLOBALS['JOMA_CONFIG'];
    $mysqli = @mysqli_connect($c['db_host'], $c['db_user'], $c['db_pass'], $c['db_name']);
    if (!$mysqli) {
        die('اتصال پایگاه داده برقرار نشد.');
    }
    mysqli_set_charset($mysqli, 'utf8mb4');
    return $mysqli;
}

function store_role_permissions($role) {
    $base = array('VIEW_DASHBOARD', 'CREATE_PLAN', 'EDIT_PLAN', 'RECORD_PERFORMANCE', 'VIEW_REPORT', 'VIEW_HISTORY', 'MANAGE_ACTIVITY_LIBRARY');
    if ($role === 'admin') {
        $base[] = 'MANAGE_USERS';
        $base[] = 'ADMIN_ACCESS';
    }
    // CLINIC: نقش‌های مطب — دسترسی جوما حداقلی؛ دسترسی مطب با توابع clinic_* کنترل می‌شود
    if ($role === 'doctor' || $role === 'head_secretary' || $role === 'secretary') {
        return $base;
    }
    if ($role === 'client') {
        return array('VIEW_DASHBOARD');
    }
    return $base;
}

function joma_stmt_bind($stmt, $types, $values) {
    if (!$stmt) return false;
    if ($types === '') return true;
    $refs = array();
    $args = array($stmt, $types);
    $n = count($values);
    for ($i = 0; $i < $n; $i++) {
        $refs[$i] = $values[$i];
        $args[] = &$refs[$i];
    }
    return call_user_func_array('mysqli_stmt_bind_param', $args);
}

function joma_stmt_fetch_all($stmt) {
    if (function_exists('mysqli_stmt_get_result')) {
        $res = @mysqli_stmt_get_result($stmt);
        if ($res) {
            $out = array();
            while ($row = mysqli_fetch_assoc($res)) $out[] = $row;
            return $out;
        }
    }
    $meta = mysqli_stmt_result_metadata($stmt);
    if (!$meta) return array();
    $fields = array();
    $row = array();
    $bind = array($stmt);
    while ($field = mysqli_fetch_field($meta)) {
        $fields[] = $field->name;
        $row[$field->name] = null;
    }
    mysqli_free_result($meta);
    foreach ($fields as $name) {
        $bind[] = &$row[$name];
    }
    call_user_func_array('mysqli_stmt_bind_result', $bind);
    $out = array();
    while (mysqli_stmt_fetch($stmt)) {
        $copy = array();
        foreach ($fields as $name) $copy[$name] = $row[$name];
        $out[] = $copy;
    }
    return $out;
}

function joma_query($sql, $types, $values) {
    $stmt = mysqli_prepare(db(), $sql);
    if (!$stmt) return array();
    joma_stmt_bind($stmt, $types, $values);
    mysqli_stmt_execute($stmt);
    $rows = joma_stmt_fetch_all($stmt);
    mysqli_stmt_close($stmt);
    return $rows;
}

function joma_query_one($sql, $types, $values) {
    $rows = joma_query($sql, $types, $values);
    return $rows ? $rows[0] : null;
}

function joma_exec($sql, $types, $values) {
    $stmt = mysqli_prepare(db(), $sql);
    if (!$stmt) return false;
    joma_stmt_bind($stmt, $types, $values);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}
