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
        "SELECT p.id AS person_id, p.public_id AS person_public_id,
                p.first_name, p.last_name, p.mobile_number,
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

/* ═════════════════════════════════════════════════════════════════════
 *  مدیریت نقش کاربران موجود (افزوده‌شده پس از فاز ۳)
 *
 *  نقش‌ها هرگز فیزیکی حذف نمی‌شوند؛ ردیف با status='REVOKED' باطل
 *  می‌شود تا تاریخچه بماند. اعطای دوبارهٔ همان نقش، ردیف تازه می‌سازد.
 *
 *  اثر آنی: تابع auth_require_active_session() در هر درخواست نقش‌ها را
 *  از پایگاه داده بازمی‌خواند، پس تغییر بلافاصله اعمال می‌شود و اگر نقش
 *  فعالِ جاری کاربر برداشته شود، در همان درخواست بعدی از نشست او حذف
 *  می‌گردد. نیازی به خروج و ورود دوباره نیست.
 * ═══════════════════════════════════════════════════════════════════ */

/** باطل کردن یک نقش فعال */
function role_assignment_revoke($db, $person_id, $role_code)
{
    $res = db_execute(
        $db,
        "UPDATE role_assignments
            SET status = 'REVOKED', revoked_at = ?
          WHERE person_id = ? AND role_code = ? AND status = 'ACTIVE'",
        'sis',
        array(now_dt(), (int)$person_id, $role_code)
    );
    return (int)$res['affected'];
}

/** شمارش مدیرهای فعالِ سامانه (حساب فعال + نقش مدیر فعال) */
function count_active_admins($db)
{
    $row = db_select_one(
        $db,
        "SELECT COUNT(DISTINCT p.id) AS cnt
           FROM persons p
           INNER JOIN accounts a ON a.person_id = p.id
           INNER JOIN role_assignments r ON r.person_id = p.id
          WHERE a.account_type = 'STAFF'
            AND a.status = 'ACTIVE'
            AND p.status = 'ACTIVE'
            AND r.role_code = 'admin'
            AND r.status = 'ACTIVE'",
        '',
        array()
    );
    return $row ? (int)$row['cnt'] : 0;
}

/**
 * به‌روزرسانی مجموعهٔ نقش‌های یک کاربر پرسنلی.
 *
 * @param array $desired_roles فهرست کد نقش‌های موردنظر (حالت نهایی)
 * @return array('granted' => array, 'revoked' => array)
 *
 * نگهبان‌های ایمنی:
 *   • دست‌کم یک نقش باید بماند؛ کاربرِ بدون نقش در ورود بعدی قفل می‌شود.
 *   • مدیر نمی‌تواند نقش «مدیر» خودش را بردارد (قفل‌شدن خودِ کاربر).
 *   • آخرین مدیر فعال سامانه نمی‌تواند نقش مدیر را از دست بدهد.
 */
function staff_roles_update($db, $person_id, $desired_roles, $actor_person_id, $actor_role_code)
{
    $person_id = (int)$person_id;
    $actor_person_id = (int)$actor_person_id;

    $allowed = array(ROLE_ADMIN, ROLE_SECRETARY, ROLE_THERAPIST, ROLE_PSYCHOMETRIST);

    /* پاک‌سازی ورودی */
    $desired = array();
    foreach ((array)$desired_roles as $code) {
        $code = (string)$code;
        if (in_array($code, $allowed, true) && !in_array($code, $desired, true)) {
            $desired[] = $code;
        }
    }

    if (count($desired) === 0) {
        throw new Exception('دست‌کم یک نقش باید برای کاربر انتخاب شود. '
            . 'کاربر بدون نقش نمی‌تواند وارد سامانه شود. '
            . 'اگر می‌خواهید دسترسی او قطع شود، از دکمهٔ «غیرفعال‌سازی» استفاده کنید.');
    }

    mysqli_begin_transaction($db);
    try {
        /* قفل سطر شخص تا دو مدیر هم‌زمان نقش‌ها را خراب نکنند */
        $locked = db_select_one(
            $db,
            "SELECT id FROM persons WHERE id = ? FOR UPDATE",
            'i',
            array($person_id)
        );
        if (!$locked) {
            throw new Exception('کاربر موردنظر یافت نشد.');
        }

        $account = db_select_one(
            $db,
            "SELECT id FROM accounts WHERE person_id = ? AND account_type = 'STAFF' LIMIT 1",
            'i',
            array($person_id)
        );
        if (!$account) {
            throw new Exception('این شخص حساب کاربری پرسنلی ندارد.');
        }

        /* نقش‌های فعال کنونی */
        $current = array();
        foreach (role_assignments_fetch_active($db, $person_id) as $r) {
            $current[] = $r['role_code'];
        }

        $granted = array();
        $revoked = array();
        foreach ($desired as $code) {
            if (!in_array($code, $current, true)) {
                $granted[] = $code;
            }
        }
        foreach ($current as $code) {
            if (!in_array($code, $desired, true)) {
                $revoked[] = $code;
            }
        }

        if (count($granted) === 0 && count($revoked) === 0) {
            mysqli_commit($db);
            return array('granted' => array(), 'revoked' => array());
        }

        /* نگهبان‌های ضدقفل‌شدن سامانه.
         *
         * ترتیب مهم است: نخست «آخرین مدیر» بررسی می‌شود و بعد «خودِ کاربر».
         * اگر برعکس باشد، در سامانه‌ای که فقط یک مدیر دارد پیام «از مدیر دیگری
         * بخواهید» داده می‌شود در حالی که مدیر دیگری وجود ندارد — راهنمایی‌ای که
         * کاربر نمی‌تواند به آن عمل کند. (یافتهٔ مالک، ۱۴۰۵/۰۷/۱۳ — نقص D5)
         */
        if (in_array(ROLE_ADMIN, $revoked, true)) {
            $admin_count = count_active_admins($db);

            if ($admin_count <= 1) {
                if ($person_id === $actor_person_id) {
                    throw new Exception('شما تنها مدیر فعال سامانه هستید؛ اگر نقش مدیر را '
                        . 'از خودتان بردارید هیچ‌کس دیگری نمی‌تواند کاربران، اتاق‌ها و '
                        . 'تنظیمات را مدیریت کند و راه بازگشتی از داخل سامانه نخواهد بود. '
                        . 'ابتدا از «کاربر جدید» یک مدیر دیگر بسازید — یا به کاربری که '
                        . 'از پیش هست نقش مدیر بدهید — و بعد این کار را انجام دهید.');
                }
                throw new Exception('این تنها مدیر فعال سامانه است. اگر نقش مدیر از او '
                    . 'گرفته شود، هیچ‌کس نمی‌تواند کاربران را مدیریت کند. '
                    . 'ابتدا مدیر دیگری تعریف کنید.');
            }

            if ($person_id === $actor_person_id) {
                throw new Exception('نمی‌توانید نقش «مدیر» را از حساب خودتان بردارید. '
                    . 'در حال حاضر ' . $admin_count . ' مدیر فعال در سامانه هست؛ '
                    . 'از یکی از مدیران دیگر بخواهید این کار را انجام دهد.');
            }
        }

        foreach ($revoked as $code) {
            role_assignment_revoke($db, $person_id, $code);
        }
        foreach ($granted as $code) {
            if (!role_assignment_is_active($db, $person_id, $code)) {
                role_assignment_insert($db, $person_id, $code);
            }
        }

        /* راستی‌آزمایی پس از تغییر */
        $after = array();
        foreach (role_assignments_fetch_active($db, $person_id) as $r) {
            $after[] = $r['role_code'];
        }
        sort($after);
        $expected = $desired;
        sort($expected);
        if ($after !== $expected) {
            throw new Exception('راستی‌آزمایی نقش‌ها پس از تغییر ناموفق بود؛ تغییری اعمال نشد.');
        }

        foreach ($revoked as $code) {
            audit_log_write(
                $db, $actor_person_id, $actor_role_code, 'ROLE_REVOKED',
                'person', $person_id,
                array('role_code' => $code, 'roles_after' => $after)
            );
        }
        foreach ($granted as $code) {
            audit_log_write(
                $db, $actor_person_id, $actor_role_code, 'ROLE_GRANTED',
                'person', $person_id,
                array('role_code' => $code, 'roles_after' => $after)
            );
        }

        mysqli_commit($db);
        return array('granted' => $granted, 'revoked' => $revoked);
    } catch (Exception $e) {
        mysqli_rollback($db);
        throw $e;
    }
}
