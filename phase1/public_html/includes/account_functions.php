<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — حساب‌های کاربری و انتساب نقش‌ها
 * ═══════════════════════════════════════════════════════════════════ */

function account_find_by_identifier($db, $identifier, $type)
{
    return db_select_one(
        $db,
        "SELECT id, public_id, person_id, login_identifier, password_hash, account_type,
                status, must_change_password, last_login_at, created_at
           FROM accounts WHERE login_identifier = ? AND account_type = ? LIMIT 1",
        'ss',
        array($identifier, $type)
    );
}

function account_login_identifier_exists($db, $identifier)
{
    $row = db_select_one($db, "SELECT id FROM accounts WHERE login_identifier = ? LIMIT 1",
        's', array($identifier));
    return $row !== null;
}

function account_find_by_person_id($db, $person_id)
{
    return db_select_one(
        $db,
        "SELECT id, public_id, person_id, login_identifier, password_hash, account_type,
                status, must_change_password, last_login_at, created_at
           FROM accounts WHERE person_id = ? LIMIT 1",
        'i',
        array((int)$person_id)
    );
}

function account_exists_for_person($db, $person_id)
{
    return account_find_by_person_id($db, $person_id) !== null;
}

/** ساخت حساب؛ must_change_password همیشه ۱ است (ADR-011) */
function account_insert($db, $person_id, $login_identifier, $password_hash, $account_type)
{
    $public_id = generate_public_id('ac');
    $result = db_execute(
        $db,
        "INSERT INTO accounts
         (public_id, person_id, login_identifier, password_hash, account_type, status, must_change_password, created_at)
         VALUES (?,?,?,?,?,'ACTIVE',1,?)",
        'sissss',
        array($public_id, (int)$person_id, $login_identifier, $password_hash, $account_type, now_dt())
    );
    return (int)$result['insert_id'];
}

function account_update_password($db, $account_id, $password_hash)
{
    db_execute(
        $db,
        "UPDATE accounts SET password_hash = ?, must_change_password = 0 WHERE id = ?",
        'si',
        array($password_hash, (int)$account_id)
    );
}

function account_set_status($db, $account_id, $status)
{
    db_execute($db, "UPDATE accounts SET status = ? WHERE id = ?", 'si', array($status, (int)$account_id));
}

function role_assignment_is_active($db, $person_id, $role_code)
{
    $row = db_select_one(
        $db,
        "SELECT id FROM role_assignments
          WHERE person_id = ? AND role_code = ? AND status = 'ACTIVE' LIMIT 1",
        'is',
        array((int)$person_id, $role_code)
    );
    return $row !== null;
}

function role_assignment_insert($db, $person_id, $role_code)
{
    $public_id = generate_public_id('ra');
    $result = db_execute(
        $db,
        "INSERT INTO role_assignments (public_id, person_id, role_code, status, valid_from)
         VALUES (?,?,?,'ACTIVE',?)",
        'siss',
        array($public_id, (int)$person_id, $role_code, now_dt())
    );
    return (int)$result['insert_id'];
}

function role_assignments_fetch_active($db, $person_id)
{
    return db_select_all(
        $db,
        "SELECT role_code FROM role_assignments WHERE person_id = ? AND status = 'ACTIVE'",
        'i',
        array((int)$person_id)
    );
}

/** فهرست همهٔ کاربران پرسنلی همراه نقش‌هایشان */
function staff_users_fetch_all($db)
{
    $rows = db_select_all(
        $db,
        "SELECT p.id AS person_id, p.first_name, p.last_name, p.mobile_number,
                a.id AS account_id, a.login_identifier, a.status, a.last_login_at
           FROM persons p
           INNER JOIN accounts a ON a.person_id = p.id
          WHERE a.account_type = 'STAFF'
          ORDER BY p.first_name",
        '',
        array()
    );
    foreach ($rows as $i => $row) {
        $roles = role_assignments_fetch_active($db, $row['person_id']);
        $codes = array();
        foreach ($roles as $r) {
            $codes[] = $r['role_code'];
        }
        $rows[$i]['roles'] = $codes;
    }
    return $rows;
}

/** شمارش کاربران پرسنلی فعال (کارت آماری داشبورد مدیر) */
function count_staff_users($db)
{
    $row = db_select_one(
        $db,
        "SELECT COUNT(*) AS cnt FROM accounts WHERE account_type = 'STAFF' AND status = 'ACTIVE'",
        '',
        array()
    );
    return $row ? (int)$row['cnt'] : 0;
}
