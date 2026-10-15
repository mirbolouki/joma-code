<?php
/* ═════════════════════════════════════════════════════════════════════
 *  توابع بخش مدیریتیِ پرونده‌ها و مشخصات کلینیک
 * ═══════════════════════════════════════════════════════════════════ */

if (!defined('CLINIC_ADMIN_MODULES_MIGRATION_VERSION')) {
    define('CLINIC_ADMIN_MODULES_MIGRATION_VERSION', '6.0.0');
}

function clinic_admin_table_exists($db, $table_name)
{
    $allowed = array('clinical_case_status_events', 'clinic_settings', 'migrations',
                     'clinical_cases', 'persons', 'audit_log');
    if (!in_array($table_name, $allowed, true)) {
        return false;
    }
    $row = db_select_one(
        $db,
        "SELECT COUNT(*) AS table_count
           FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = ?",
        's',
        array($table_name)
    );
    return $row && (int)$row['table_count'] > 0;
}

function clinic_admin_modules_ready($db)
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        if (!clinic_admin_table_exists($db, 'clinical_case_status_events')
            || !clinic_admin_table_exists($db, 'clinic_settings')
            || !clinic_admin_table_exists($db, 'migrations')) {
            $cached = false;
            return $cached;
        }
        $migration = db_select_one(
            $db,
            "SELECT id FROM migrations WHERE version = ? LIMIT 1",
            's',
            array(CLINIC_ADMIN_MODULES_MIGRATION_VERSION)
        );
        $settings = db_select_one(
            $db,
            "SELECT id FROM clinic_settings WHERE id = 1 LIMIT 1",
            '',
            array()
        );
        $cached = (bool)$migration && (bool)$settings;
        return $cached;
    } catch (Exception $ex) {
        $cached = false;
        return $cached;
    }
}

/** مقدار پیش‌فرض برای سربرگ تا پیش از اجرای ارتقا یا در صورت نبود ردیف تنظیمات. */
function clinic_settings_name($db)
{
    static $loaded = false;
    static $cached_name = 'کلینیک جوما';
    if ($loaded) {
        return $cached_name;
    }
    $loaded = true;
    try {
        if (clinic_admin_modules_ready($db)) {
            $row = db_select_one($db,
                "SELECT clinic_name FROM clinic_settings WHERE id = 1 LIMIT 1", '', array());
            if ($row && trim((string)$row['clinic_name']) !== '') {
                $cached_name = (string)$row['clinic_name'];
            }
        }
    } catch (Exception $ex) {
        /* خرابی/نبود تنظیمات نباید سربرگِ صفحه‌های دیگر را از کار بیندازد. */
    }
    return $cached_name;
}

function clinic_settings_fetch($db)
{
    $row = db_select_one(
        $db,
        "SELECT clinic_name, clinic_phone, clinic_address, updated_at
           FROM clinic_settings WHERE id = 1 LIMIT 1",
        '',
        array()
    );
    if (!$row) {
        return array(
            'clinic_name' => 'کلینیک جوما',
            'clinic_phone' => '',
            'clinic_address' => '',
            'updated_at' => null,
        );
    }
    return $row;
}

function clinic_text_length($value)
{
    if (function_exists('mb_strlen')) {
        return mb_strlen((string)$value, 'UTF-8');
    }
    $count = @preg_match_all('/./us', (string)$value, $matches);
    return ($count === false) ? strlen((string)$value) : $count;
}

function clinic_settings_save($db, $clinic_name, $clinic_phone, $clinic_address,
                              $actor_person_id, $actor_role_code)
{
    $updated_at = now_dt();
    db_execute(
        $db,
        "INSERT INTO clinic_settings
            (id, clinic_name, clinic_phone, clinic_address, updated_by_person_id, updated_at)
         VALUES (1, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            clinic_name = VALUES(clinic_name),
            clinic_phone = VALUES(clinic_phone),
            clinic_address = VALUES(clinic_address),
            updated_by_person_id = VALUES(updated_by_person_id),
            updated_at = VALUES(updated_at)",
        'sssis',
        array((string)$clinic_name, (string)$clinic_phone, (string)$clinic_address,
              (int)$actor_person_id, $updated_at)
    );
    audit_log_write(
        $db,
        (int)$actor_person_id,
        (string)$actor_role_code,
        'CLINIC_PROFILE_UPDATED',
        'clinic_settings',
        1,
        array('fields' => array('clinic_name', 'clinic_phone', 'clinic_address'))
    );
}

/**
 * بایگانی یا بازگشایی پرونده با قفل تراکنشی و ثبت تاریخچه.
 * مدیر به یادداشت‌ها و اسناد بالینی دسترسی پیدا نمی‌کند.
 */
function clinic_case_admin_change_status($db, $case_public_id, $new_status,
                                        $reason_text, $actor_person_id, $actor_role_code)
{
    if (!preg_match('/^cs_[a-f0-9]{20}$/', (string)$case_public_id)) {
        throw new Exception('شناسهٔ پرونده معتبر نیست.');
    }
    if (!in_array($new_status, array('ACTIVE', 'ARCHIVED'), true)) {
        throw new Exception('وضعیت درخواستی معتبر نیست.');
    }
    $reason_text = trim((string)$reason_text);
    if ($reason_text === '') {
        throw new Exception('برای تغییر وضعیت، علت را بنویسید.');
    }
    if (clinic_text_length($reason_text) > 500) {
        throw new Exception('علت باید حداکثر ۵۰۰ نویسه باشد.');
    }

    mysqli_begin_transaction($db);
    try {
        $case = db_select_one(
            $db,
            "SELECT id, status FROM clinical_cases WHERE public_id = ? LIMIT 1 FOR UPDATE",
            's',
            array((string)$case_public_id)
        );
        if (!$case) {
            throw new Exception('پرونده پیدا نشد. فهرست را تازه‌سازی کنید.');
        }
        $old_status = (string)$case['status'];
        if ($old_status === $new_status) {
            throw new Exception('وضعیت پرونده از قبل همین است؛ فهرست را تازه‌سازی کنید.');
        }
        if (!in_array($old_status, array('ACTIVE', 'ARCHIVED'), true)) {
            throw new Exception('وضعیت فعلی پرونده قابل تغییر نیست.');
        }

        db_execute(
            $db,
            "UPDATE clinical_cases SET status = ? WHERE id = ?",
            'si',
            array($new_status, (int)$case['id'])
        );
        db_execute(
            $db,
            "INSERT INTO clinical_case_status_events
                (public_id, clinical_case_id, from_status, to_status, reason_text,
                 changed_by_person_id, changed_by_role_code, created_at)
             VALUES (?,?,?,?,?,?,?,?)",
            'sisssiss',
            array(generate_public_id('ce'), (int)$case['id'], $old_status, $new_status,
                  $reason_text, (int)$actor_person_id, (string)$actor_role_code, now_dt())
        );

        $action = ($new_status === 'ARCHIVED') ? 'CASE_ARCHIVED' : 'CASE_REOPENED';
        audit_log_write(
            $db,
            (int)$actor_person_id,
            (string)$actor_role_code,
            $action,
            'clinical_case',
            (int)$case['id'],
            array('from_status' => $old_status, 'to_status' => $new_status)
        );
        mysqli_commit($db);
    } catch (Exception $ex) {
        mysqli_rollback($db);
        throw $ex;
    }
}

function clinic_case_admin_status_history($db, $case_public_id)
{
    return db_select_all(
        $db,
        "SELECT ev.public_id, ev.from_status, ev.to_status, ev.reason_text, ev.created_at,
                p.first_name AS actor_first_name, p.last_name AS actor_last_name
           FROM clinical_case_status_events ev
           INNER JOIN clinical_cases c ON c.id = ev.clinical_case_id
           INNER JOIN persons p ON p.id = ev.changed_by_person_id
          WHERE c.public_id = ?
          ORDER BY ev.created_at DESC, ev.id DESC
          LIMIT 100",
        's',
        array((string)$case_public_id)
    );
}
