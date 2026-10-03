<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — لایهٔ دسترسی به دیتابیس
 *  فقط mysqli با Prepared Statement.
 *
 *  نکتهٔ بسیار مهم:
 *  این فایل عمداً از mysqli_stmt::get_result() و fetch_all() استفاده
 *  نمی‌کند، چون آن‌ها به افزونهٔ mysqlnd وابسته‌اند که روی بسیاری از
 *  هاست‌های اشتراکی نصب نیست. به‌جای آن از result_metadata + bind_result
 *  + mysqli_stmt_fetch استفاده شده است.
 * ═══════════════════════════════════════════════════════════════════ */

/**
 * اتصال به دیتابیس.
 * منطقهٔ زمانی نشست روی UTC تنظیم می‌شود تا NOW() و CURRENT_TIMESTAMP
 * مستقل از تنظیم سرور، همیشه وقت جهانی بدهند.
 */
function db_connect()
{
    $link = @mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if (!$link) {
        @file_put_contents(
            __DIR__ . '/../storage/logs/error_' . gmdate('Y-m-d') . '.log',
            gmdate('Y-m-d H:i:s') . " [DB_CONNECT_FAILED] " . mysqli_connect_error() . "\n",
            FILE_APPEND
        );
        die('سامانه در حال حاضر در دسترس نیست. لطفاً کمی بعد دوباره تلاش کنید.');
    }
    mysqli_set_charset($link, 'utf8mb4');
    @mysqli_query($link, "SET time_zone = '+00:00'");
    return $link;
}

/**
 * آماده‌سازی دستور SQL.
 * @throws Exception
 */
function db_prepare($db, $sql)
{
    $stmt = mysqli_prepare($db, $sql);
    if (!$stmt) {
        throw new Exception('DB_PREPARE_ERROR: ' . mysqli_error($db));
    }
    return $stmt;
}

/**
 * مقداردهی پارامترها و اجرای دستور.
 * @throws Exception
 */
function db_bind_and_execute($stmt, $types, $params)
{
    if ($types !== '' && count($params) > 0) {
        $bind_args = array();
        $bind_args[] = $types;
        foreach ($params as $key => $value) {
            $bind_args[] = &$params[$key];
        }
        call_user_func_array(array($stmt, 'bind_param'), $bind_args);
    }
    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new Exception('DB_EXECUTE_ERROR: ' . $error);
    }
    return $stmt;
}

/**
 * خواندن همهٔ سطرها به شکل آرایهٔ انجمنی (بدون mysqlnd).
 */
function db_select_all($db, $sql, $types = '', $params = array())
{
    $stmt = db_prepare($db, $sql);
    db_bind_and_execute($stmt, $types, $params);

    $meta = mysqli_stmt_result_metadata($stmt);
    if (!$meta) {
        mysqli_stmt_close($stmt);
        return array();
    }

    $field_names = array();
    while ($field = mysqli_fetch_field($meta)) {
        $field_names[] = $field->name;
    }
    mysqli_free_result($meta);

    $bind_vars = array();
    $bind_refs = array();
    foreach ($field_names as $i => $name) {
        $bind_vars[$i] = null;
        $bind_refs[$i] = &$bind_vars[$i];
    }
    call_user_func_array(array($stmt, 'bind_result'), $bind_refs);

    $rows = array();
    while (mysqli_stmt_fetch($stmt)) {
        $row = array();
        foreach ($field_names as $i => $name) {
            $row[$name] = $bind_vars[$i];
        }
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

/**
 * خواندن یک سطر (یا null).
 */
function db_select_one($db, $sql, $types = '', $params = array())
{
    $rows = db_select_all($db, $sql, $types, $params);
    return isset($rows[0]) ? $rows[0] : null;
}

/**
 * اجرای دستور نوشتنی (INSERT/UPDATE/DELETE).
 * @return array('affected' => int, 'insert_id' => int)
 */
function db_execute($db, $sql, $types = '', $params = array())
{
    $stmt = db_prepare($db, $sql);
    db_bind_and_execute($stmt, $types, $params);
    $affected = mysqli_stmt_affected_rows($stmt);
    $insert_id = mysqli_stmt_insert_id($stmt);
    mysqli_stmt_close($stmt);
    return array('affected' => $affected, 'insert_id' => $insert_id);
}
