<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — پروندهٔ بالینی (clinical_cases)
 *  قلب فاز ۱: تصمیم درمانگر به‌صورت اتمیک ثبت می‌شود.
 * ═══════════════════════════════════════════════════════════════════ */

/**
 * پذیرش مسئولیت توسط درمانگر و گشودن پروندهٔ بالینی.
 * تراکنش + قفل سطر، تا دو درخواست هم‌زمان نتوانند دو پرونده بسازند.
 *
 * @return int شناسهٔ داخلی پروندهٔ ساخته‌شده
 * @throws Exception با پیام فارسی قابل‌نمایش
 */
function clinical_case_open_from_admission($db, $admission_id, $therapist_person_id, $actor_person_id)
{
    mysqli_begin_transaction($db);
    try {
        $admission = admission_lock_for_update($db, $admission_id);
        if (!$admission) {
            throw new Exception('پذیرش موردنظر یافت نشد.');
        }
        if ($admission['status'] !== 'AWAITING_THERAPIST') {
            throw new Exception('این پذیرش قبلاً نهایی شده است.');
        }
        if ((int)$admission['referred_therapist_person_id'] !== (int)$therapist_person_id) {
            throw new Exception('این پذیرش به شما ارجاع نشده است.');
        }

        $public_id = generate_public_id('cs');
        $result = db_execute(
            $db,
            "INSERT INTO clinical_cases
             (public_id, admission_id, patient_person_id, responsible_therapist_person_id, status, opened_at)
             VALUES (?,?,?,?,'ACTIVE',?)",
            'siiis',
            array($public_id, (int)$admission_id, (int)$admission['patient_person_id'],
                  (int)$therapist_person_id, now_dt())
        );
        $case_id = (int)$result['insert_id'];

        admission_update_status($db, $admission_id, 'ACCEPTED');

        audit_log_write($db, $actor_person_id, ROLE_THERAPIST, 'CASE_OPENED', 'clinical_case',
            $case_id, array('admission_id' => (int)$admission_id));

        /* فاز ۴ / تصمیم D4-4: فرم‌هایی که پیش از باز شدن پرونده به این پذیرش
           تخصیص یافته‌اند (مثلاً فرم پذیرش اولیهٔ خودکار) به پروندهٔ تازه
           وصل می‌شوند. داخل همین تراکنش و بی‌اثر وقتی فاز ۴ نصب نیست. */
        if (function_exists('form_assignments_attach_case')) {
            form_assignments_attach_case($db, (int)$admission_id, $case_id);
        }

        mysqli_commit($db);
        return $case_id;
    } catch (Exception $e) {
        mysqli_rollback($db);
        throw $e;
    }
}

/**
 * عدم پذیرش توسط درمانگر، با ثبت دلیل.
 * پذیرش به وضعیت DECLINED می‌رود و در داشبورد منشی برای ارجاع مجدد دیده می‌شود.
 */
function admission_decline($db, $admission_id, $therapist_person_id, $reason_id, $actor_person_id)
{
    $reason = db_select_one(
        $db,
        "SELECT li.id FROM lookup_items li
           INNER JOIN lookup_lists ll ON ll.id = li.lookup_list_id
          WHERE li.id = ? AND ll.list_code = 'admission_decline_reason' AND li.is_active = 1",
        'i',
        array((int)$reason_id)
    );
    if (!$reason) {
        throw new Exception('دلیل عدم پذیرش را انتخاب کنید.');
    }

    mysqli_begin_transaction($db);
    try {
        $admission = admission_lock_for_update($db, $admission_id);
        if (!$admission || $admission['status'] !== 'AWAITING_THERAPIST') {
            throw new Exception('این پذیرش قبلاً نهایی شده است.');
        }
        if ((int)$admission['referred_therapist_person_id'] !== (int)$therapist_person_id) {
            throw new Exception('این پذیرش به شما ارجاع نشده است.');
        }

        db_execute(
            $db,
            "UPDATE admissions SET status = 'DECLINED', decided_at = ?, decline_reason_id = ? WHERE id = ?",
            'sii',
            array(now_dt(), (int)$reason_id, (int)$admission_id)
        );

        audit_log_write($db, $actor_person_id, ROLE_THERAPIST, 'ADMISSION_DECLINED', 'admission',
            $admission_id, array('reason_id' => (int)$reason_id));

        mysqli_commit($db);
    } catch (Exception $e) {
        mysqli_rollback($db);
        throw $e;
    }
}

/** پرونده‌های یک درمانگر */
function clinical_cases_fetch_for_therapist($db, $therapist_person_id)
{
    return db_select_all(
        $db,
        "SELECT c.id, c.public_id, c.status, c.opened_at,
                p.first_name, p.last_name, p.mobile_number
           FROM clinical_cases c
           INNER JOIN persons p ON p.id = c.patient_person_id
          WHERE c.responsible_therapist_person_id = ?
          ORDER BY c.opened_at DESC",
        'i',
        array((int)$therapist_person_id)
    );
}

/** شمارش پرونده‌های فعال (کارت آماری داشبورد مدیر) */
function count_active_cases($db)
{
    $row = db_select_one(
        $db,
        "SELECT COUNT(*) AS cnt FROM clinical_cases WHERE status = 'ACTIVE'",
        '',
        array()
    );
    return $row ? (int)$row['cnt'] : 0;
}
