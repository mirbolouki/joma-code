<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — ساخت اتمیک کاربر پرسنلی
 *  همهٔ مراحل (شخص + حساب + نقش‌ها) در یک تراکنش انجام می‌شود؛
 *  اگر هر مرحله شکست بخورد، هیچ ردّ ناقصی در دیتابیس نمی‌ماند.
 * ═══════════════════════════════════════════════════════════════════ */

/**
 * @param array  $person_data  first_name, last_name, mobile_number, national_code (اختیاری)
 * @param array  $role_codes   مثلاً array('secretary','therapist')
 * @param bool   $confirm_existing_person  تأیید اینکه شمارهٔ تکراری، همان شخص است
 *
 * @return array
 *   array('needs_confirmation' => true, 'existing_person' => array)  یا
 *   array('needs_confirmation' => false, 'person_id' => int)
 * @throws Exception با پیام فارسی قابل‌نمایش
 */
function staff_account_create($db, $person_data, $login_identifier, $password, $role_codes,
                              $created_by_person_id, $confirm_existing_person = false)
{
    if (account_login_identifier_exists($db, $login_identifier)) {
        throw new Exception('این نام کاربری قبلاً استفاده شده است؛ نام دیگری انتخاب کنید.');
    }
    if (count($role_codes) === 0) {
        throw new Exception('حداقل یک نقش باید انتخاب شود.');
    }
    foreach ($role_codes as $rc) {
        if (!in_array($rc, $GLOBALS['VALID_ROLE_CODES'], true) || $rc === ROLE_PATIENT) {
            throw new Exception('نقش انتخاب‌شده معتبر نیست.');
        }
    }

    $mobile = normalize_mobile_number($person_data['mobile_number']);
    $existing_person = person_find_by_mobile($db, $mobile);

    if ($existing_person && !$confirm_existing_person) {
        return array('needs_confirmation' => true, 'existing_person' => $existing_person);
    }

    mysqli_begin_transaction($db);
    try {
        if ($existing_person) {
            $person_id = (int)$existing_person['id'];
            if (account_exists_for_person($db, $person_id)) {
                throw new Exception('این شخص از قبل حساب کاربری دارد؛ نمی‌توان حساب دوم ساخت.');
            }
        } else {
            $national_code = isset($person_data['national_code']) ? $person_data['national_code'] : null;
            $person_id = person_insert(
                $db,
                $person_data['first_name'],
                $person_data['last_name'],
                $mobile,
                $national_code
            );
        }

        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        account_insert($db, $person_id, $login_identifier, $password_hash, 'STAFF');

        foreach ($role_codes as $rc) {
            if (!role_assignment_is_active($db, $person_id, $rc)) {
                role_assignment_insert($db, $person_id, $rc);
            }
        }

        audit_log_write(
            $db, $created_by_person_id, ROLE_ADMIN, 'STAFF_USER_CREATED', 'person', $person_id,
            array('roles' => $role_codes, 'login_identifier' => $login_identifier)
        );

        mysqli_commit($db);
        return array('needs_confirmation' => false, 'person_id' => $person_id);
    } catch (Exception $e) {
        mysqli_rollback($db);
        throw $e;
    }
}

/** فعال یا غیرفعال کردن حساب یک کاربر پرسنلی به‌همراه ثبت رویداد */
function staff_account_toggle_status($db, $account_id, $new_status, $actor_person_id)
{
    if (!in_array($new_status, array('ACTIVE', 'DISABLED'), true)) {
        throw new Exception('وضعیت درخواستی معتبر نیست.');
    }
    account_set_status($db, $account_id, $new_status);
    audit_log_write(
        $db, $actor_person_id, ROLE_ADMIN,
        $new_status === 'ACTIVE' ? 'STAFF_USER_ENABLED' : 'STAFF_USER_DISABLED',
        'account', $account_id, null
    );
}
