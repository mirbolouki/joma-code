<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — اشخاص (persons)
 *  شمارهٔ موبایل، شناسهٔ یکتای هر شخص در سامانه است (ADR-014).
 * ═══════════════════════════════════════════════════════════════════ */

function person_find_by_mobile($db, $mobile)
{
    return db_select_one(
        $db,
        "SELECT id, public_id, first_name, last_name, national_code, mobile_number, status, created_at
           FROM persons WHERE mobile_number = ? LIMIT 1",
        's',
        array($mobile)
    );
}

function person_find_by_id($db, $id)
{
    return db_select_one(
        $db,
        "SELECT id, public_id, first_name, last_name, national_code, mobile_number, status, created_at
           FROM persons WHERE id = ? LIMIT 1",
        'i',
        array((int)$id)
    );
}

function person_find_by_public_id($db, $public_id)
{
    return db_select_one(
        $db,
        "SELECT id, public_id, first_name, last_name, national_code, mobile_number, status, created_at
           FROM persons WHERE public_id = ? LIMIT 1",
        's',
        array($public_id)
    );
}

/** ثبت شخص جدید؛ کد ملی اختیاری است */
function person_insert($db, $first_name, $last_name, $mobile, $national_code = null)
{
    $public_id = generate_public_id('pr');
    $now = now_dt();
    $national_code = ($national_code === '' ) ? null : $national_code;

    $result = db_execute(
        $db,
        "INSERT INTO persons (public_id, first_name, last_name, national_code, mobile_number, status, created_at)
         VALUES (?,?,?,?,?,'ACTIVE',?)",
        'ssssss',
        array($public_id, trim($first_name), trim($last_name), $national_code, $mobile, $now)
    );
    return (int)$result['insert_id'];
}

/** نام کامل برای نمایش */
function person_full_name($person)
{
    if (!$person) {
        return '—';
    }
    return trim($person['first_name'] . ' ' . $person['last_name']);
}
