<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — پذیرش (admissions)
 * ═══════════════════════════════════════════════════════════════════ */

/** اعتبارسنجی ورودی فرم ثبت پذیرش */
function admission_validate_input($db, $data)
{
    $errors = array();

    if (empty($data['patient_person_id'])) {
        $errors['patient_person_id'] = 'مراجع انتخاب‌شده معتبر نیست.';
    }

    $service = db_select_one(
        $db,
        "SELECT id, is_multi_person FROM service_types WHERE id = ? AND is_active = 1",
        'i',
        array((int)$data['service_type_id'])
    );
    if (!$service) {
        $errors['service_type_id'] = 'خدمت انتخاب‌شده معتبر یا فعال نیست.';
    }

    $companion_count = isset($data['companion_count']) ? (int)$data['companion_count'] : 0;
    if ($service && (int)$service['is_multi_person'] === 1) {
        if ($companion_count < 1 || $companion_count > 10) {
            $errors['companion_count'] = 'تعداد همراهان باید بین ۱ تا ۱۰ نفر باشد.';
        }
    } else {
        $companion_count = 0;
    }

    $reason = db_select_one(
        $db,
        "SELECT li.id FROM lookup_items li
           INNER JOIN lookup_lists ll ON ll.id = li.lookup_list_id
          WHERE li.id = ? AND ll.list_code = 'referral_reason' AND li.is_active = 1",
        'i',
        array((int)$data['referral_reason_id'])
    );
    if (!$reason) {
        $errors['referral_reason_id'] = 'دلیل مراجعه را انتخاب کنید.';
    }

    if (empty($data['referred_therapist_person_id'])
        || !role_assignment_is_active($db, (int)$data['referred_therapist_person_id'], ROLE_THERAPIST)) {
        $errors['referred_therapist_person_id'] = 'درمانگر انتخاب‌شده معتبر یا فعال نیست.';
    }

    return array('ok' => empty($errors), 'errors' => $errors, 'companion_count' => $companion_count);
}

/** ثبت پذیرش جدید در وضعیت «در انتظار درمانگر» */
function admission_insert($db, $data, $created_by_person_id)
{
    $public_id = generate_public_id('ad');
    $result = db_execute(
        $db,
        "INSERT INTO admissions
         (public_id, patient_person_id, referred_therapist_person_id, service_type_id,
          companion_count, referral_reason_id, status, created_by_person_id, created_at)
         VALUES (?,?,?,?,?,?,'AWAITING_THERAPIST',?,?)",
        'siiiiiis',
        array(
            $public_id,
            (int)$data['patient_person_id'],
            (int)$data['referred_therapist_person_id'],
            (int)$data['service_type_id'],
            (int)$data['companion_count'],
            (int)$data['referral_reason_id'],
            (int)$created_by_person_id,
            now_dt(),
        )
    );
    $admission_id = (int)$result['insert_id'];
    audit_log_write($db, $created_by_person_id, ROLE_SECRETARY, 'ADMISSION_CREATED',
        'admission', $admission_id, null);
    return $admission_id;
}

function admission_find_by_id($db, $id)
{
    return db_select_one(
        $db,
        "SELECT id, public_id, patient_person_id, referred_therapist_person_id, service_type_id,
                companion_count, referral_reason_id, decline_reason_id, status,
                created_by_person_id, created_at, decided_at
           FROM admissions WHERE id = ? LIMIT 1",
        'i',
        array((int)$id)
    );
}

function admission_find_by_public_id($db, $public_id)
{
    return db_select_one(
        $db,
        "SELECT id, public_id, patient_person_id, referred_therapist_person_id, service_type_id,
                companion_count, referral_reason_id, decline_reason_id, status,
                created_by_person_id, created_at, decided_at
           FROM admissions WHERE public_id = ? LIMIT 1",
        's',
        array($public_id)
    );
}

/**
 * قفل‌کردن سطر پذیرش برای به‌روزرسانی.
 * هشدار: فقط داخل یک تراکنش فعال (mysqli_begin_transaction) فراخوانی شود.
 */
function admission_lock_for_update($db, $id)
{
    return db_select_one(
        $db,
        "SELECT id, patient_person_id, referred_therapist_person_id, status
           FROM admissions WHERE id = ? FOR UPDATE",
        'i',
        array((int)$id)
    );
}

function admission_update_status($db, $id, $status)
{
    db_execute(
        $db,
        "UPDATE admissions SET status = ?, decided_at = ? WHERE id = ?",
        'ssi',
        array($status, now_dt(), (int)$id)
    );
}

/** کارتابل درمانگر: پذیرش‌های در انتظار تصمیم او */
function admissions_fetch_awaiting_for_therapist($db, $therapist_person_id)
{
    return db_select_all(
        $db,
        "SELECT a.id, a.public_id, a.companion_count, a.created_at,
                p.first_name, p.last_name, p.mobile_number,
                st.title AS service_title, li.label AS reason_label
           FROM admissions a
           INNER JOIN persons p ON p.id = a.patient_person_id
           INNER JOIN service_types st ON st.id = a.service_type_id
           INNER JOIN lookup_items li ON li.id = a.referral_reason_id
          WHERE a.referred_therapist_person_id = ? AND a.status = 'AWAITING_THERAPIST'
          ORDER BY a.created_at ASC",
        'i',
        array((int)$therapist_person_id)
    );
}

/** پذیرش‌های ردشده که منتظر ارجاع مجدد توسط منشی هستند */
function admissions_fetch_declined($db)
{
    return db_select_all(
        $db,
        "SELECT a.id, a.public_id, a.decided_at,
                p.first_name, p.last_name, p.mobile_number,
                st.title AS service_title,
                tp.first_name AS therapist_first_name, tp.last_name AS therapist_last_name,
                dr.label AS decline_reason_label
           FROM admissions a
           INNER JOIN persons p ON p.id = a.patient_person_id
           INNER JOIN persons tp ON tp.id = a.referred_therapist_person_id
           INNER JOIN service_types st ON st.id = a.service_type_id
           LEFT JOIN lookup_items dr ON dr.id = a.decline_reason_id
          WHERE a.status = 'DECLINED'
          ORDER BY a.decided_at DESC",
        '',
        array()
    );
}

/** آخرین پذیرش‌ها برای داشبورد منشی */
function admissions_fetch_recent($db, $limit = 20)
{
    $limit = (int)$limit;
    if ($limit < 1 || $limit > 200) {
        $limit = 20;
    }
    return db_select_all(
        $db,
        "SELECT a.id, a.public_id, a.status, a.created_at,
                p.first_name, p.last_name,
                st.title AS service_title,
                tp.first_name AS therapist_first_name, tp.last_name AS therapist_last_name
           FROM admissions a
           INNER JOIN persons p ON p.id = a.patient_person_id
           INNER JOIN persons tp ON tp.id = a.referred_therapist_person_id
           INNER JOIN service_types st ON st.id = a.service_type_id
          ORDER BY a.created_at DESC LIMIT " . $limit,
        '',
        array()
    );
}

/** ارجاع مجدد یک پذیرش ردشده به درمانگر دیگر */
function admission_reassign_therapist($db, $id, $new_therapist_person_id, $actor_person_id)
{
    if (!role_assignment_is_active($db, (int)$new_therapist_person_id, ROLE_THERAPIST)) {
        throw new Exception('درمانگر انتخاب‌شده معتبر یا فعال نیست.');
    }

    mysqli_begin_transaction($db);
    try {
        $admission = admission_lock_for_update($db, $id);
        if (!$admission) {
            throw new Exception('پذیرش موردنظر یافت نشد.');
        }
        if ($admission['status'] !== 'DECLINED') {
            throw new Exception('فقط پذیرش‌های ردشده قابل ارجاع مجدد هستند.');
        }
        db_execute(
            $db,
            "UPDATE admissions
                SET referred_therapist_person_id = ?, status = 'AWAITING_THERAPIST',
                    decided_at = NULL, decline_reason_id = NULL
              WHERE id = ?",
            'ii',
            array((int)$new_therapist_person_id, (int)$id)
        );
        audit_log_write($db, $actor_person_id, ROLE_SECRETARY, 'ADMISSION_REASSIGNED',
            'admission', $id, array('new_therapist_person_id' => (int)$new_therapist_person_id));

        /* فاز ۳ — اگر پرونده‌ای برای این پذیرش باز شده باشد، درمانگر مسئول آن
           هم جابه‌جا می‌شود و رویداد برای درمانگر پیشین در حسابرسی ثبت می‌گردد.
           هشدار دیدنی، در کارتابل درمانگر پیشین از روی داده استنتاج می‌شود. */
        if (function_exists('phase3_ready') && phase3_ready($db)) {
            $linked_case = db_select_one(
                $db,
                "SELECT id, responsible_therapist_person_id FROM clinical_cases
                  WHERE admission_id = ? LIMIT 1",
                'i',
                array((int)$id)
            );
            if ($linked_case
                && (int)$linked_case['responsible_therapist_person_id'] !== (int)$new_therapist_person_id) {
                therapist_case_transfer_warning(
                    $db,
                    (int)$linked_case['id'],
                    (int)$linked_case['responsible_therapist_person_id'],
                    (int)$new_therapist_person_id
                );
            }
        }

        mysqli_commit($db);
    } catch (Exception $e) {
        mysqli_rollback($db);
        throw $e;
    }
}

/** شمارش پذیرش‌های «امروز» بر مبنای شبانه‌روز تهران */
function count_admissions_today($db)
{
    $bounds = today_bounds_utc();
    $row = db_select_one(
        $db,
        "SELECT COUNT(*) AS cnt FROM admissions WHERE created_at >= ? AND created_at < ?",
        'ss',
        array($bounds['start'], $bounds['end'])
    );
    return $row ? (int)$row['cnt'] : 0;
}
