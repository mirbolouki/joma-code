<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۳: نمای پروندهٔ بالینی
 *
 *  این فایل فقط «گردآوری داده برای صفحهٔ پرونده» را انجام می‌دهد.
 *  قواعد دسترسی به یادداشت کاملاً در note_functions.php است و اینجا
 *  دور زده نمی‌شود.
 *
 *  دسترسی به خودِ پرونده: تنها درمانگر مسئول. مدیر و منشی هیچ مسیری
 *  به این صفحه ندارند (ADR-004 — وراثت مدیر به حوزهٔ بالینی نمی‌رسد).
 * ═══════════════════════════════════════════════════════════════════ */

/** آیا این درمانگر، مسئول این پرونده است؟ */
function therapist_can_view_case($db, $clinical_case_id, $therapist_person_id)
{
    $row = db_select_one(
        $db,
        "SELECT responsible_therapist_person_id FROM clinical_cases WHERE id = ? LIMIT 1",
        'i',
        array((int)$clinical_case_id)
    );
    return $row && ((int)$row['responsible_therapist_person_id'] === (int)$therapist_person_id);
}

/**
 * یافتن پرونده با شناسهٔ عمومی، همراه با اطلاعات مراجع و پذیرش.
 * اگر پرونده مال این درمانگر نباشد، استثنا پرتاب و رویداد ثبت می‌شود.
 */
function clinical_case_find_for_therapist($db, $case_public_id, $therapist_person_id)
{
    $case = db_select_one(
        $db,
        "SELECT c.id, c.public_id, c.status, c.opened_at,
                c.admission_id, c.patient_person_id, c.responsible_therapist_person_id,
                p.first_name, p.last_name, p.mobile_number, p.birth_date, p.gender,
                p.public_id AS patient_public_id,
                a.public_id AS admission_public_id, a.status AS admission_status,
                a.created_at AS admission_created_at, a.companion_count,
                st.title AS service_title, st.default_duration_minutes,
                li.label AS reason_label
           FROM clinical_cases c
           INNER JOIN persons p ON p.id = c.patient_person_id
           INNER JOIN admissions a ON a.id = c.admission_id
           LEFT JOIN service_types st ON st.id = a.service_type_id
           LEFT JOIN lookup_items li ON li.id = a.referral_reason_id
          WHERE c.public_id = ?
          LIMIT 1",
        's',
        array((string)$case_public_id)
    );

    /* پیام یکسان برای «نبودن» و «مال شما نبودن» — تا وجود یا نبودِ یک
       شمارهٔ پرونده از روی تفاوت پیام قابل حدس نباشد. */
    $deny_message = 'پرونده یافت نشد یا شما اجازهٔ دسترسی به آن را ندارید.';

    if (!$case) {
        throw new Exception($deny_message);
    }
    if ((int)$case['responsible_therapist_person_id'] !== (int)$therapist_person_id) {
        audit_log_write(
            $db, (int)$therapist_person_id,
            isset($_SESSION['active_role_code']) ? $_SESSION['active_role_code'] : null,
            'CASE_ACCESS_DENIED', 'clinical_case', (int)$case['id'],
            array('case_public_id' => $case['public_id'])
        );
        throw new Exception($deny_message);
    }

    return $case;
}

/**
 * نوبت‌های همین پرونده — فقط برای نمایش.
 *
 * تصمیم مالک: درمانگر در این صفحه هیچ دکمهٔ لغو یا جابه‌جایی ندارد؛
 * این دو اختیار همچنان مال منشی و مدیر است (قاعدهٔ فاز ۲ دست‌نخورده).
 *
 * نکتهٔ مهم: فیلتر روی therapist_person_id خودِ نوبت انجام می‌شود، نه روی
 * درمانگر پذیرش. اگر پرونده بعداً ارجاع مجدد شود، نوبت‌های گذشته جابه‌جا
 * نمی‌شوند (قاعدهٔ ثابت فاز ۲).
 */
function case_appointments_for_therapist($db, $admission_id, $therapist_person_id)
{
    if (!phase2_ready($db)) {
        return array();
    }
    return db_select_all(
        $db,
        "SELECT ap.public_id, ap.appointment_start_utc, ap.appointment_end_utc,
                ap.duration_minutes, ap.status, ap.price_rial,
                st.title AS service_title,
                r.name AS room_name, r.room_number
           FROM appointments ap
           LEFT JOIN service_types st ON st.id = ap.service_type_id
           LEFT JOIN rooms r ON r.id = ap.room_id
          WHERE ap.admission_id = ? AND ap.therapist_person_id = ?
          ORDER BY ap.appointment_start_utc DESC",
        'ii',
        array((int)$admission_id, (int)$therapist_person_id)
    );
}

/** سن تقریبی از تاریخ تولد میلادی ذخیره‌شده — فقط نمایشی */
function case_patient_age($birth_date)
{
    if (!$birth_date || $birth_date === '0000-00-00') {
        return null;
    }
    $ts = strtotime($birth_date . ' UTC');
    if ($ts === false) {
        return null;
    }
    $age = (int)gmdate('Y') - (int)gmdate('Y', $ts);
    if ((int)gmdate('md') < (int)gmdate('md', $ts)) {
        $age--;
    }
    return ($age >= 0 && $age < 130) ? $age : null;
}

/** برچسب جنسیت */
function case_gender_label($gender)
{
    if ($gender === 'MALE') {
        return 'مرد';
    }
    if ($gender === 'FEMALE') {
        return 'زن';
    }
    return '—';
}
