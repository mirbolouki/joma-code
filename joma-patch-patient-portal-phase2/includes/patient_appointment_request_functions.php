<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — پورتال مراجع، فاز ۲: درخواست‌های نوبت
 *
 *  درخواست در این جدول، نوبت را خودش ایجاد، لغو یا جابه‌جا نمی‌کند.
 *  پرسنل پس از انجام کار در تقویم معمول سامانه، درخواست را می‌بندند.
 * ═══════════════════════════════════════════════════════════════════ */

if (!defined('PATIENT_APPOINTMENT_REQUEST_MIGRATION_VERSION')) {
    define('PATIENT_APPOINTMENT_REQUEST_MIGRATION_VERSION', '6.1.0');
}
if (!defined('PATIENT_APPOINTMENT_REQUEST_NOTE_MAX')) {
    define('PATIENT_APPOINTMENT_REQUEST_NOTE_MAX', 300);
}

/** آیا جدول و نسخهٔ مهاجرت درخواست‌های نوبت آماده‌اند؟ */
function patient_appointment_requests_ready($db)
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    $table_exists = db_select_one($db,
        "SELECT 1 AS present FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
        's', array('patient_appointment_requests')) !== null;
    if (!$table_exists) {
        return false;
    }
    $migrations_exists = db_select_one($db,
        "SELECT 1 AS present FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
        's', array('migrations')) !== null;
    if (!$migrations_exists) {
        return false;
    }
    $ready = migration_applied($db, PATIENT_APPOINTMENT_REQUEST_MIGRATION_VERSION);
    return $ready;
}

function patient_appointment_request_type_label($type)
{
    $map = array(
        'NEW_APPOINTMENT' => 'درخواست نوبت تازه',
        'CANCEL_APPOINTMENT' => 'درخواست لغو نوبت',
        'RESCHEDULE_APPOINTMENT' => 'درخواست جابه‌جایی نوبت',
    );
    return isset($map[$type]) ? $map[$type] : 'درخواست نوبت';
}

function patient_appointment_request_status_label($status)
{
    $map = array(
        'NEW' => 'در انتظار بررسی',
        'CONTACTED' => 'در حال هماهنگی',
        'COMPLETED' => 'رسیدگی شد',
        'REJECTED' => 'امکان انجام نیست',
        'WITHDRAWN' => 'انصراف مراجع',
    );
    return isset($map[$status]) ? $map[$status] : 'نامشخص';
}

function patient_appointment_request_status_class($status)
{
    $map = array(
        'NEW' => 'badge-warning',
        'CONTACTED' => 'badge-primary',
        'COMPLETED' => 'badge-success',
        'REJECTED' => 'badge-danger',
        'WITHDRAWN' => 'badge-muted',
    );
    return isset($map[$status]) ? $map[$status] : 'badge-muted';
}

/** پرونده‌های فعال خود مراجع؛ شناسهٔ عمومی برای انتخاب در فرم */
function patient_appointment_request_active_cases($db, $person_id)
{
    return db_select_all($db,
        "SELECT ad.id, ad.public_id, ad.status,
                st.title AS service_title,
                CONCAT(tp.first_name, ' ', tp.last_name) AS therapist_name,
                cc.opened_at
           FROM clinical_cases cc
           INNER JOIN admissions ad ON ad.id = cc.admission_id
           INNER JOIN service_types st ON st.id = ad.service_type_id
           INNER JOIN persons tp ON tp.id = cc.responsible_therapist_person_id
          WHERE cc.patient_person_id = ?
            AND cc.status = 'ACTIVE'
            AND ad.status IN ('AWAITING_THERAPIST','ACCEPTED')
          ORDER BY cc.opened_at DESC, ad.id DESC",
        'i', array((int)$person_id));
}

/** یک پروندهٔ فعال که واقعاً متعلق به نشست مراجع باشد */
function patient_appointment_request_case_find($db, $admission_public_id, $person_id)
{
    return db_select_one($db,
        "SELECT ad.id, ad.public_id, ad.status,
                st.title AS service_title,
                CONCAT(tp.first_name, ' ', tp.last_name) AS therapist_name
           FROM clinical_cases cc
           INNER JOIN admissions ad ON ad.id = cc.admission_id
           INNER JOIN service_types st ON st.id = ad.service_type_id
           INNER JOIN persons tp ON tp.id = cc.responsible_therapist_person_id
          WHERE ad.public_id = ?
            AND cc.patient_person_id = ?
            AND cc.status = 'ACTIVE'
            AND ad.status IN ('AWAITING_THERAPIST','ACCEPTED')
          LIMIT 1",
        'si', array((string)$admission_public_id, (int)$person_id));
}

/** نوبت آیندهٔ قابل درخواست که به شخص واردشده تعلق دارد */
function patient_appointment_request_appointment_find($db, $appointment_public_id, $person_id)
{
    return db_select_one($db,
        "SELECT ap.id, ap.public_id, ap.admission_id, ap.status,
                ap.appointment_start_utc, ap.appointment_end_utc,
                ap.duration_minutes, ad.public_id AS admission_public_id,
                cc.status AS case_status,
                st.title AS service_title,
                CONCAT(tp.first_name, ' ', tp.last_name) AS therapist_name,
                r.name AS room_name
           FROM appointments ap
           INNER JOIN admissions ad ON ad.id = ap.admission_id
           LEFT JOIN clinical_cases cc ON cc.admission_id = ad.id
           INNER JOIN service_types st ON st.id = ap.service_type_id
           INNER JOIN persons tp ON tp.id = ap.therapist_person_id
           LEFT JOIN rooms r ON r.id = ap.room_id
          WHERE ap.public_id = ?
            AND ad.patient_person_id = ?
          LIMIT 1",
        'si', array((string)$appointment_public_id, (int)$person_id));
}

/** ساخت درخواست پس از بازاعتبارسنجی مالکیت و وضعیت در سمت سرور */
function patient_appointment_request_create($db, $person_id, $type, $admission_public_id,
                                           $appointment_public_id, $day_code,
                                           $window_code, $free_text, $patient_note)
{
    if (!patient_appointment_requests_ready($db)) {
        throw new Exception('بخش درخواست‌های نوبت هنوز فعال نشده است.');
    }
    $allowed_types = array('NEW_APPOINTMENT', 'CANCEL_APPOINTMENT', 'RESCHEDULE_APPOINTMENT');
    if (!in_array($type, $allowed_types, true)) {
        throw new Exception('نوع درخواست معتبر نیست.');
    }

    $person_id = (int)$person_id;
    $patient_note = trim((string)$patient_note);
    $free_text = trim((string)$free_text);
    if (mb_strlen($patient_note, 'UTF-8') > PATIENT_APPOINTMENT_REQUEST_NOTE_MAX) {
        throw new Exception('توضیح شما بیش از حد طولانی است.');
    }
    if (mb_strlen($free_text, 'UTF-8') > 60) {
        throw new Exception('توضیح زمان ترجیحی بیش از حد طولانی است.');
    }
    if ($patient_note === '') {
        $patient_note = null;
    }

    $preferred_text = null;
    if ($type === 'NEW_APPOINTMENT' || $type === 'RESCHEDULE_APPOINTMENT') {
        $day_code = trim((string)$day_code);
        $window_code = trim((string)$window_code);
        $days = booking_day_options();
        $windows = booking_window_options();
        if (!isset($days[$day_code]) || !isset($windows[$window_code])) {
            throw new Exception('روز یا بازهٔ زمانی ترجیحی معتبر نیست.');
        }
        $preferred_text = booking_preferred_text($day_code, $window_code, $free_text);
    } else {
        $day_code = null;
        $window_code = null;
        $free_text = '';
    }

    mysqli_begin_transaction($db);
    try {
        $person = db_select_one($db,
            "SELECT id FROM persons WHERE id = ? AND status = 'ACTIVE' FOR UPDATE",
            'i', array($person_id));
        if (!$person) {
            throw new Exception('حساب مراجع فعال نیست.');
        }

        $admission_id = 0;
        $appointment_id = null;
        if ($type === 'NEW_APPOINTMENT') {
            $case = patient_appointment_request_case_find($db, $admission_public_id, $person_id);
            if (!$case) {
                throw new Exception('پروندهٔ فعال برای ثبت درخواست پیدا نشد. با کلینیک تماس بگیرید.');
            }
            $admission_id = (int)$case['id'];
        } else {
            $appointment = patient_appointment_request_appointment_find(
                $db, $appointment_public_id, $person_id);
            if (!$appointment || $appointment['status'] !== 'SCHEDULED'
                || $appointment['appointment_start_utc'] <= now_dt()) {
                throw new Exception('فقط نوبت آیندهٔ رزروشدهٔ خودتان قابل درخواست است.');
            }
            if ($type === 'RESCHEDULE_APPOINTMENT' && $appointment['case_status'] !== 'ACTIVE') {
                throw new Exception('برای پروندهٔ بایگانی‌شده درخواست جابه‌جایی پذیرفته نمی‌شود.');
            }
            $admission_id = (int)$appointment['admission_id'];
            $appointment_id = (int)$appointment['id'];
        }

        if ($appointment_id !== null) {
            $duplicate = db_select_one($db,
                "SELECT id FROM patient_appointment_requests
                  WHERE patient_person_id = ? AND appointment_id = ?
                    AND status IN ('NEW','CONTACTED')
                  LIMIT 1 FOR UPDATE",
                'ii', array($person_id, $appointment_id));
        } else {
            $duplicate = db_select_one($db,
                "SELECT id FROM patient_appointment_requests
                  WHERE patient_person_id = ? AND admission_id = ?
                    AND request_type = 'NEW_APPOINTMENT'
                    AND status IN ('NEW','CONTACTED')
                  LIMIT 1 FOR UPDATE",
                'ii', array($person_id, $admission_id));
        }
        if ($duplicate) {
            throw new Exception('برای همین مورد، یک درخواستِ در حال رسیدگی دارید. وضعیت آن را در «درخواست‌های من» ببینید.');
        }

        $open_count = db_select_one($db,
            "SELECT COUNT(*) AS total FROM patient_appointment_requests
              WHERE patient_person_id = ? AND status IN ('NEW','CONTACTED')",
            'i', array($person_id));
        if ($open_count && (int)$open_count['total'] >= 5) {
            throw new Exception('تعداد درخواست‌های باز شما به حد مجاز رسیده است؛ ابتدا وضعیت درخواست‌های قبلی را بررسی کنید.');
        }

        $public_id = generate_public_id('par');
        $created_at = now_dt();
        $res = db_execute($db,
            "INSERT INTO patient_appointment_requests
                (public_id, patient_person_id, admission_id, appointment_id, request_type,
                 preferred_day_code, preferred_window_code, preferred_text, patient_note,
                 status, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,'NEW',?)",
            'siiissssss',
            array($public_id, $person_id, $admission_id, $appointment_id, $type,
                  $day_code, $window_code, $preferred_text, $patient_note, $created_at));

        audit_log_write($db, $person_id, ROLE_PATIENT, 'PATIENT_APPOINTMENT_REQUEST_CREATED',
            'patient_appointment_request', (int)$res['insert_id'],
            array('public_id' => $public_id, 'request_type' => $type));
        mysqli_commit($db);
        return $public_id;
    } catch (Exception $ex) {
        mysqli_rollback($db);
        throw $ex;
    }
}

/** فهرست درخواست‌های شخص واردشده؛ هرگز person_id را از نشانی نمی‌گیرد */
function patient_appointment_requests_for_person($db, $person_id, $limit = 100)
{
    $limit = (int)$limit;
    if ($limit < 1) { $limit = 1; }
    if ($limit > 200) { $limit = 200; }
    return db_select_all($db,
        "SELECT r.public_id, r.admission_id, r.appointment_id, r.request_type,
                r.preferred_text, r.patient_note, r.status, r.patient_response,
                r.created_at, r.handled_at,
                ad.public_id AS admission_public_id,
                st.title AS admission_service_title,
                ap.public_id AS appointment_public_id,
                ap.appointment_start_utc, ap.status AS appointment_status,
                apst.title AS appointment_service_title,
                CONCAT(tp.first_name, ' ', tp.last_name) AS appointment_therapist_name
           FROM patient_appointment_requests r
           INNER JOIN admissions ad ON ad.id = r.admission_id
           INNER JOIN service_types st ON st.id = ad.service_type_id
           LEFT JOIN appointments ap ON ap.id = r.appointment_id
           LEFT JOIN service_types apst ON apst.id = ap.service_type_id
           LEFT JOIN persons tp ON tp.id = ap.therapist_person_id
          WHERE r.patient_person_id = ?
          ORDER BY r.created_at DESC, r.id DESC
          LIMIT " . $limit,
        'i', array((int)$person_id));
}

/** انصراف مراجع فقط تا پیش از شروع رسیدگی */
function patient_appointment_request_withdraw($db, $public_id, $person_id)
{
    mysqli_begin_transaction($db);
    try {
        $row = db_select_one($db,
            "SELECT id, status FROM patient_appointment_requests
              WHERE public_id = ? AND patient_person_id = ? LIMIT 1 FOR UPDATE",
            'si', array((string)$public_id, (int)$person_id));
        if (!$row) {
            throw new Exception('درخواست پیدا نشد.');
        }
        if ($row['status'] !== 'NEW') {
            throw new Exception('فقط درخواستِ در انتظار بررسی را می‌توانید پس بگیرید.');
        }
        $res = db_execute($db,
            "UPDATE patient_appointment_requests
                SET status = 'WITHDRAWN', updated_at = ?
              WHERE id = ? AND patient_person_id = ? AND status = 'NEW'",
            'sii', array(now_dt(), (int)$row['id'], (int)$person_id));
        if ((int)$res['affected'] !== 1) {
            throw new Exception('وضعیت درخواست تغییر کرده است؛ صفحه را تازه کنید و دوباره بررسی کنید.');
        }
        audit_log_write($db, (int)$person_id, ROLE_PATIENT, 'PATIENT_APPOINTMENT_REQUEST_WITHDRAWN',
            'patient_appointment_request', (int)$row['id'], array('public_id' => $public_id));
        mysqli_commit($db);
    } catch (Exception $ex) {
        mysqli_rollback($db);
        throw $ex;
    }
}

/** صف درخواست‌ها برای مدیر یا منشی */
function patient_appointment_requests_staff_list($db, $status = 'OPEN', $limit = 100)
{
    $allowed = array('NEW', 'CONTACTED', 'COMPLETED', 'REJECTED', 'WITHDRAWN');
    $limit = (int)$limit;
    if ($limit < 1) { $limit = 1; }
    if ($limit > 200) { $limit = 200; }
    $where = '';
    $types = '';
    $params = array();
    if ($status === 'OPEN' || (!in_array($status, $allowed, true) && $status !== 'ALL')) {
        $where = " WHERE r.status IN ('NEW','CONTACTED')";
    } elseif (in_array($status, $allowed, true)) {
        $where = ' WHERE r.status = ?';
        $types = 's';
        $params[] = $status;
    }
    return db_select_all($db,
        "SELECT r.id, r.public_id, r.request_type, r.status, r.preferred_text,
                r.patient_note, r.patient_response, r.staff_note, r.created_at, r.handled_at,
                p.first_name AS patient_first_name, p.last_name AS patient_last_name,
                p.mobile_number,
                ad.public_id AS admission_public_id,
                st.title AS admission_service_title,
                ap.public_id AS appointment_public_id,
                ap.status AS appointment_status,
                ap.appointment_start_utc, ap.appointment_end_utc,
                apst.title AS appointment_service_title,
                CONCAT(tp.first_name, ' ', tp.last_name) AS appointment_therapist_name
           FROM patient_appointment_requests r
           INNER JOIN persons p ON p.id = r.patient_person_id
           INNER JOIN admissions ad ON ad.id = r.admission_id
           INNER JOIN service_types st ON st.id = ad.service_type_id
           LEFT JOIN appointments ap ON ap.id = r.appointment_id
           LEFT JOIN service_types apst ON apst.id = ap.service_type_id
           LEFT JOIN persons tp ON tp.id = ap.therapist_person_id" . $where . "
          ORDER BY CASE WHEN r.status IN ('NEW','CONTACTED') THEN 0 ELSE 1 END,
                   r.created_at DESC, r.id DESC
          LIMIT " . $limit,
        $types, $params);
}

/** جزئیات یک درخواست برای پرسنل */
function patient_appointment_request_staff_find($db, $public_id)
{
    return db_select_one($db,
        "SELECT r.*, p.first_name AS patient_first_name,
                p.last_name AS patient_last_name, p.mobile_number,
                ad.public_id AS admission_public_id,
                st.title AS admission_service_title,
                ap.public_id AS appointment_public_id,
                ap.status AS appointment_status,
                ap.appointment_start_utc, ap.appointment_end_utc,
                apst.title AS appointment_service_title,
                CONCAT(tp.first_name, ' ', tp.last_name) AS appointment_therapist_name
           FROM patient_appointment_requests r
           INNER JOIN persons p ON p.id = r.patient_person_id
           INNER JOIN admissions ad ON ad.id = r.admission_id
           INNER JOIN service_types st ON st.id = ad.service_type_id
           LEFT JOIN appointments ap ON ap.id = r.appointment_id
           LEFT JOIN service_types apst ON apst.id = ap.service_type_id
           LEFT JOIN persons tp ON tp.id = ap.therapist_person_id
          WHERE r.public_id = ?
          LIMIT 1",
        's', array((string)$public_id));
}

/** ثبت تصمیم مدیر/منشی و رویداد حسابرسی */
function patient_appointment_request_staff_set_status($db, $public_id, $new_status,
                                                       $patient_response, $staff_note,
                                                       $actor_person_id, $actor_role_code)
{
    $allowed = array('CONTACTED', 'COMPLETED', 'REJECTED');
    if (!in_array($new_status, $allowed, true)) {
        throw new Exception('وضعیت انتخاب‌شده معتبر نیست.');
    }
    $patient_response = trim((string)$patient_response);
    $staff_note = trim((string)$staff_note);
    if (mb_strlen($patient_response, 'UTF-8') > 300) {
        throw new Exception('پیام قابل‌نمایش برای مراجع بیش از حد طولانی است.');
    }
    if (mb_strlen($staff_note, 'UTF-8') > 2000) {
        throw new Exception('یادداشت داخلی بیش از حد طولانی است.');
    }
    if ($new_status === 'REJECTED' && $patient_response === '') {
        throw new Exception('برای رد درخواست، توضیح قابل‌نمایش برای مراجع لازم است.');
    }
    if ($patient_response === '') {
        $patient_response = null;
    }
    if ($staff_note === '') {
        $staff_note = null;
    }

    mysqli_begin_transaction($db);
    try {
        $row = db_select_one($db,
            "SELECT id, status, patient_response, staff_note
               FROM patient_appointment_requests
              WHERE public_id = ? FOR UPDATE",
            's', array((string)$public_id));
        if (!$row) {
            throw new Exception('درخواست پیدا نشد.');
        }
        if (!in_array($row['status'], array('NEW', 'CONTACTED'), true)) {
            throw new Exception('این درخواست قبلاً بسته شده و دیگر قابل‌تغییر نیست.');
        }

        $handled_at = now_dt();
        if ($patient_response === null) {
            $patient_response = $row['patient_response'];
        }
        $updated_staff_note = $row['staff_note'];
        if ($staff_note !== null) {
            $entry = '[' . $handled_at . '] ' . $staff_note;
            $updated_staff_note = ($updated_staff_note === null || $updated_staff_note === '')
                ? $entry : $updated_staff_note . "\n" . $entry;
            if (mb_strlen($updated_staff_note, 'UTF-8') > 10000) {
                $updated_staff_note = mb_substr($updated_staff_note, -10000, 10000, 'UTF-8');
            }
        }
        db_execute($db,
            "UPDATE patient_appointment_requests
                SET status = ?, patient_response = ?, staff_note = ?,
                    handled_by_person_id = ?, handled_at = ?, updated_at = ?
              WHERE id = ? AND status IN ('NEW','CONTACTED')",
            'sssissi', array($new_status, $patient_response, $updated_staff_note,
                (int)$actor_person_id, $handled_at, $handled_at, (int)$row['id']));
        audit_log_write($db, (int)$actor_person_id, (string)$actor_role_code,
            'PATIENT_APPOINTMENT_REQUEST_STATUS_CHANGED', 'patient_appointment_request',
            (int)$row['id'], array('from' => $row['status'], 'to' => $new_status));
        mysqli_commit($db);
    } catch (Exception $ex) {
        mysqli_rollback($db);
        throw $ex;
    }
}
