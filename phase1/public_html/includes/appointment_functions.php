<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما فاز ۲ — نوبت‌دهی، ظرفیت اتاق، عدم‌حضور درمانگر
 *
 *  قواعد تثبیت‌شده:
 *   • تداخل در «یک اتاق» تا سقف ظرفیت اتاق فقط هشدار است؛
 *     رسیدن به ظرفیت، ثبت را متوقف می‌کند.
 *   • تداخل برای «یک درمانگر» همیشه ممنوع است (قید سخت).
 *   • ساعات کاری ۹ تا ۲۰ به وقت تهران سنجیده می‌شود.
 *   • لغو نوبت در کمتر از ۳۰ دقیقهٔ مانده ممنوع است.
 *   • هیچ نوبتی حذف فیزیکی نمی‌شود؛ فقط وضعیتش عوض می‌شود.
 * ═══════════════════════════════════════════════════════════════════ */

/* ───────────────── برچسب‌ها ───────────────── */

function appointment_status_label($status)
{
    $map = array(
        'SCHEDULED' => 'رزروشده',
        'COMPLETED' => 'انجام‌شده',
        'CANCELLED' => 'لغوشده',
        'NO_SHOW'   => 'عدم مراجعه',
    );
    return isset($map[$status]) ? $map[$status] : $status;
}

function appointment_status_class($status)
{
    $map = array(
        'SCHEDULED' => 'badge-info',
        'COMPLETED' => 'badge-success',
        'CANCELLED' => 'badge-muted',
        'NO_SHOW'   => 'badge-danger',
    );
    return isset($map[$status]) ? $map[$status] : 'badge-muted';
}

/* ───────────────── عدم‌حضور درمانگر ───────────────── */

/** آیا درمانگر در تاریخ محلی داده‌شده غایب است؟ خروجی: رکورد قانون یا null */
function absence_rule_matching($db, $therapist_person_id, $local_date)
{
    $rules = db_select_all(
        $db,
        "SELECT a.id, a.public_id, a.start_date, a.end_date, a.is_repeating, li.label AS reason_label
         FROM therapist_absence_rules a
         LEFT JOIN lookup_items li ON li.id = a.reason_id
         WHERE a.therapist_person_id = ? AND a.is_active = 1",
        'i',
        array((int)$therapist_person_id)
    );

    $md = substr($local_date, 5); /* MM-DD */

    foreach ($rules as $r) {
        if ((int)$r['is_repeating'] === 1) {
            $s = substr($r['start_date'], 5);
            $e = substr($r['end_date'], 5);
            if ($s <= $e) {
                if ($md >= $s && $md <= $e) { return $r; }
            } else {
                /* بازه‌ای که از پایان سال می‌گذرد */
                if ($md >= $s || $md <= $e) { return $r; }
            }
        } else {
            if ($local_date >= $r['start_date'] && $local_date <= $r['end_date']) {
                return $r;
            }
        }
    }
    return null;
}

/** فهرست قوانین عدم‌حضور یک درمانگر */
function absence_rules_fetch($db, $therapist_person_id)
{
    return db_select_all(
        $db,
        "SELECT a.id, a.public_id, a.start_date, a.end_date, a.is_repeating, a.is_active,
                a.reason_id, li.label AS reason_label
         FROM therapist_absence_rules a
         LEFT JOIN lookup_items li ON li.id = a.reason_id
         WHERE a.therapist_person_id = ? AND a.is_active = 1
         ORDER BY a.start_date ASC",
        'i',
        array((int)$therapist_person_id)
    );
}

/** افزودن قانون عدم‌حضور */
function absence_rule_create($db, $therapist_person_id, $start_date, $end_date, $reason_id,
                             $is_repeating, $actor_person_id, $actor_role_code)
{
    if ($end_date < $start_date) {
        throw new Exception('تاریخ پایان نمی‌تواند پیش از تاریخ شروع باشد.');
    }
    if (!$is_repeating && date_diff_days($start_date, $end_date) > 365) {
        throw new Exception('بازهٔ عدم‌حضور نمی‌تواند بیش از یک سال باشد.');
    }

    $public_id = generate_public_id('ab');
    $reason_id = ($reason_id === null || $reason_id === '') ? null : (int)$reason_id;

    $res = db_execute(
        $db,
        "INSERT INTO therapist_absence_rules
         (public_id, therapist_person_id, start_date, end_date, reason_id, is_repeating, is_active, created_at)
         VALUES (?,?,?,?,?,?,1,?)",
        'sissiis',
        array($public_id, (int)$therapist_person_id, $start_date, $end_date,
              $reason_id, ($is_repeating ? 1 : 0), now_dt())
    );

    audit_log_write($db, $actor_person_id, $actor_role_code, 'ABSENCE_RULE_CREATED',
        'therapist_absence_rule', (int)$res['insert_id'],
        array('start' => $start_date, 'end' => $end_date, 'repeating' => $is_repeating ? 1 : 0));

    return (int)$res['insert_id'];
}

/** حذف نرم قانون عدم‌حضور */
function absence_rule_remove($db, $public_id, $therapist_person_id, $actor_person_id, $actor_role_code)
{
    $rule = db_select_one(
        $db,
        "SELECT id FROM therapist_absence_rules WHERE public_id = ? AND therapist_person_id = ?",
        'si',
        array($public_id, (int)$therapist_person_id)
    );
    if (!$rule) {
        throw new Exception('قانون عدم‌حضور پیدا نشد.');
    }
    db_execute($db, "UPDATE therapist_absence_rules SET is_active = 0 WHERE id = ?",
        'i', array((int)$rule['id']));
    audit_log_write($db, $actor_person_id, $actor_role_code, 'ABSENCE_RULE_REMOVED',
        'therapist_absence_rule', (int)$rule['id'], null);
}

/* ───────────────── بازه‌های در دسترس (Hold) ───────────────── */

/** ثبت یک بازهٔ در دسترس برای درمانگر */
function hold_create($db, $therapist_person_id, $local_date, $start_clock, $end_clock, $room_id,
                     $actor_person_id, $actor_role_code)
{
    $today = clinic_today();
    if ($local_date < $today) {
        throw new Exception('تاریخ نمی‌تواند در گذشته باشد.');
    }
    if (date_diff_days($today, $local_date) > HOLD_HORIZON_DAYS) {
        throw new Exception('حداکثر تا ' . to_persian_digits(HOLD_HORIZON_DAYS)
            . ' روز آینده می‌توانید زمان آزاد تعریف کنید.');
    }
    if (!within_working_hours($start_clock, $end_clock)) {
        throw new Exception('ساعت باید میان ' . to_persian_digits(CLINIC_DAY_START_HOUR)
            . ':۰۰ تا ' . to_persian_digits(CLINIC_DAY_END_HOUR) . ':۰۰ باشد و پایان پس از شروع.');
    }
    if (absence_rule_matching($db, $therapist_person_id, $local_date)) {
        throw new Exception('این تاریخ در بازهٔ عدم‌حضور شما قرار دارد.');
    }

    $dup = db_select_one(
        $db,
        "SELECT id FROM appointment_holds
         WHERE therapist_person_id = ? AND hold_date = ? AND hold_start_time = ?",
        'iss',
        array((int)$therapist_person_id, $local_date, $start_clock . ':00')
    );
    if ($dup) {
        throw new Exception('برای این تاریخ و ساعت قبلاً زمان آزاد ثبت کرده‌اید.');
    }

    $public_id = generate_public_id('hd');
    $start_utc = local_to_utc($local_date . ' ' . $start_clock . ':00');
    $end_utc = local_to_utc($local_date . ' ' . $end_clock . ':00');
    $room_id = ($room_id === null || $room_id === '') ? null : (int)$room_id;

    $res = db_execute(
        $db,
        "INSERT INTO appointment_holds
         (public_id, therapist_person_id, room_id, hold_date, hold_start_time, hold_end_time,
          hold_start_utc, hold_end_utc, created_at)
         VALUES (?,?,?,?,?,?,?,?,?)",
        'siissssss',
        array($public_id, (int)$therapist_person_id, $room_id, $local_date,
              $start_clock . ':00', $end_clock . ':00', $start_utc, $end_utc, now_dt())
    );

    audit_log_write($db, $actor_person_id, $actor_role_code, 'HOLD_CREATED',
        'appointment_hold', (int)$res['insert_id'],
        array('date' => $local_date, 'time' => $start_clock . '-' . $end_clock));

    return (int)$res['insert_id'];
}

/** حذف یک بازهٔ در دسترس (اگر نوبتی رویش ثبت نشده باشد) */
function hold_remove($db, $public_id, $therapist_person_id, $actor_person_id, $actor_role_code)
{
    $hold = db_select_one(
        $db,
        "SELECT id, hold_start_utc, hold_end_utc FROM appointment_holds
         WHERE public_id = ? AND therapist_person_id = ?",
        'si',
        array($public_id, (int)$therapist_person_id)
    );
    if (!$hold) {
        throw new Exception('بازهٔ موردنظر پیدا نشد.');
    }

    $busy = db_select_one(
        $db,
        "SELECT COUNT(*) AS cnt FROM appointments
         WHERE therapist_person_id = ? AND status = 'SCHEDULED'
           AND appointment_start_utc < ? AND appointment_end_utc > ?",
        'iss',
        array((int)$therapist_person_id, $hold['hold_end_utc'], $hold['hold_start_utc'])
    );
    if ($busy && (int)$busy['cnt'] > 0) {
        throw new Exception('روی این بازه نوبت ثبت‌شده وجود دارد؛ ابتدا نوبت را لغو کنید.');
    }

    db_execute($db, "DELETE FROM appointment_holds WHERE id = ?", 'i', array((int)$hold['id']));
    audit_log_write($db, $actor_person_id, $actor_role_code, 'HOLD_REMOVED',
        'appointment_hold', (int)$hold['id'], null);
}

/** بازه‌های در دسترس یک درمانگر در یک بازهٔ تاریخی محلی */
function holds_fetch($db, $therapist_person_id, $from_date, $to_date)
{
    return db_select_all(
        $db,
        "SELECT h.id, h.public_id, h.hold_date, h.hold_start_time, h.hold_end_time,
                h.hold_start_utc, h.hold_end_utc, h.room_id, r.name AS room_name, r.room_number
         FROM appointment_holds h
         LEFT JOIN rooms r ON r.id = h.room_id
         WHERE h.therapist_person_id = ? AND h.hold_date BETWEEN ? AND ?
         ORDER BY h.hold_date ASC, h.hold_start_time ASC",
        'iss',
        array((int)$therapist_person_id, $from_date, $to_date)
    );
}

/** آیا لحظهٔ خواسته‌شده داخل یکی از بازه‌های آزاد درمانگر است؟ */
function hold_covers($db, $therapist_person_id, $start_utc, $end_utc)
{
    $row = db_select_one(
        $db,
        "SELECT COUNT(*) AS cnt FROM appointment_holds
         WHERE therapist_person_id = ? AND hold_start_utc <= ? AND hold_end_utc >= ?",
        'iss',
        array((int)$therapist_person_id, $start_utc, $end_utc)
    );
    return ($row && (int)$row['cnt'] > 0);
}

/* ───────────────── تداخل ───────────────── */

/** تعداد نوبت‌های فعالِ متداخل در یک اتاق */
function room_overlap_count($db, $room_id, $start_utc, $end_utc, $exclude_appointment_id = null)
{
    $sql = "SELECT COUNT(*) AS cnt FROM appointments
            WHERE room_id = ? AND status IN ('SCHEDULED','COMPLETED')
              AND appointment_start_utc < ? AND appointment_end_utc > ?";
    $types = 'iss';
    $params = array((int)$room_id, $end_utc, $start_utc);

    if ($exclude_appointment_id) {
        $sql .= " AND id != ?";
        $types .= 'i';
        $params[] = (int)$exclude_appointment_id;
    }
    $row = db_select_one($db, $sql, $types, $params);
    return $row ? (int)$row['cnt'] : 0;
}

/** تعداد نوبت‌های فعالِ متداخل برای یک درمانگر */
function therapist_overlap_count($db, $therapist_person_id, $start_utc, $end_utc, $exclude_appointment_id = null)
{
    $sql = "SELECT COUNT(*) AS cnt FROM appointments
            WHERE therapist_person_id = ? AND status IN ('SCHEDULED','COMPLETED')
              AND appointment_start_utc < ? AND appointment_end_utc > ?";
    $types = 'iss';
    $params = array((int)$therapist_person_id, $end_utc, $start_utc);

    if ($exclude_appointment_id) {
        $sql .= " AND id != ?";
        $types .= 'i';
        $params[] = (int)$exclude_appointment_id;
    }
    $row = db_select_one($db, $sql, $types, $params);
    return $row ? (int)$row['cnt'] : 0;
}

/* ───────────────── ساخت نوبت ───────────────── */

/**
 * اعتبارسنجی و ثبت نوبت.
 * خروجی: array('appointment_id'=>int,'public_id'=>string,'warnings'=>array)
 */
function appointment_create($db, $admission_id, $room_id, $local_date, $start_clock,
                            $duration_minutes, $notes, $actor_person_id, $actor_role_code)
{
    $warnings = array();

    $admission = db_select_one(
        $db,
        "SELECT a.id, a.public_id, a.status, a.service_type_id, a.referred_therapist_person_id,
                a.patient_person_id, st.default_duration_minutes, st.title AS service_title
         FROM admissions a
         INNER JOIN service_types st ON st.id = a.service_type_id
         WHERE a.id = ?",
        'i',
        array((int)$admission_id)
    );
    if (!$admission) {
        throw new Exception('پذیرش موردنظر پیدا نشد.');
    }
    if ($admission['status'] !== 'ACCEPTED') {
        throw new Exception('فقط برای پذیرشی که درمانگر آن را «پذیرفته» است می‌توان نوبت ثبت کرد.');
    }

    $room = room_find($db, $room_id);
    if (!$room) {
        throw new Exception('اتاق موردنظر پیدا نشد.');
    }
    if ($room['status'] !== 'ACTIVE') {
        throw new Exception('این اتاق غیرفعال است و قابل رزرو نیست.');
    }

    $duration_minutes = (int)$duration_minutes;
    if ($duration_minutes <= 0) {
        $duration_minutes = (int)$admission['default_duration_minutes'];
    }
    if ($duration_minutes < 15 || $duration_minutes > 240) {
        throw new Exception('مدت جلسه باید میان ۱۵ تا ۲۴۰ دقیقه باشد.');
    }

    $end_clock = minutes_to_clock(clock_to_minutes($start_clock) + $duration_minutes);
    if (!within_working_hours($start_clock, $end_clock)) {
        throw new Exception('نوبت باید کاملاً درون ساعات کاری ('
            . to_persian_digits(CLINIC_DAY_START_HOUR) . ':۰۰ تا '
            . to_persian_digits(CLINIC_DAY_END_HOUR) . ':۰۰) باشد.');
    }

    $start_utc = local_to_utc($local_date . ' ' . $start_clock . ':00');
    $end_utc = utc_add_minutes($start_utc, $duration_minutes);

    if ($start_utc <= now_dt()) {
        throw new Exception('زمان نوبت نمی‌تواند در گذشته باشد.');
    }

    $therapist_id = (int)$admission['referred_therapist_person_id'];

    $absence = absence_rule_matching($db, $therapist_id, $local_date);
    if ($absence) {
        throw new Exception('درمانگر در این تاریخ حضور ندارد'
            . ($absence['reason_label'] ? ' (' . $absence['reason_label'] . ')' : '') . '.');
    }

    mysqli_begin_transaction($db);
    try {
        /* قفل سطر پذیرش تا پایان تراکنش */
        db_select_one($db, "SELECT id FROM admissions WHERE id = ? FOR UPDATE",
            'i', array((int)$admission_id));

        if (therapist_overlap_count($db, $therapist_id, $start_utc, $end_utc) > 0) {
            throw new Exception('درمانگر در این بازه نوبت دیگری دارد.');
        }

        $room_busy = room_overlap_count($db, (int)$room['id'], $start_utc, $end_utc);
        $capacity = max(1, (int)$room['capacity']);
        if ($room_busy >= $capacity) {
            throw new Exception('ظرفیت این اتاق در این بازه تکمیل است ('
                . to_persian_digits($room_busy) . ' از ' . to_persian_digits($capacity) . ').');
        }
        if ($room_busy > 0) {
            $warnings[] = 'در این بازه ' . to_persian_digits($room_busy)
                . ' جلسهٔ دیگر در همین اتاق ثبت شده است.';
        }

        if (!hold_covers($db, $therapist_id, $start_utc, $end_utc)) {
            $warnings[] = 'این زمان در فهرست زمان‌های آزادِ اعلام‌شدهٔ درمانگر نیست.';
        }

        $public_id = generate_public_id('ap');
        $notes = ($notes === null || trim($notes) === '') ? null : mb_substr(trim($notes), 0, 1000, 'UTF-8');

        $res = db_execute(
            $db,
            "INSERT INTO appointments
             (public_id, admission_id, therapist_person_id, service_type_id, room_id,
              appointment_start_utc, appointment_end_utc, duration_minutes, status, notes,
              created_by_person_id, created_by_role_code, created_at)
             VALUES (?,?,?,?,?,?,?,?,'SCHEDULED',?,?,?,?)",
            'siiiissisiss',
            array($public_id, (int)$admission_id, $therapist_id,
                  (int)$admission['service_type_id'], (int)$room['id'],
                  $start_utc, $end_utc, $duration_minutes, $notes,
                  (int)$actor_person_id, $actor_role_code, now_dt())
        );
        $appt_id = (int)$res['insert_id'];

        mysqli_commit($db);
    } catch (Exception $ex) {
        mysqli_rollback($db);
        throw $ex;
    }

    audit_log_write($db, $actor_person_id, $actor_role_code, 'APPOINTMENT_CREATED',
        'appointment', $appt_id,
        array('admission_public_id' => $admission['public_id'],
              'start_utc' => $start_utc,
              'duration' => $duration_minutes,
              'room_id' => (int)$room['id'],
              'warnings' => count($warnings)));

    return array('appointment_id' => $appt_id, 'public_id' => $public_id, 'warnings' => $warnings);
}

/* ───────────────── تغییر وضعیت ───────────────── */

/** لغو نوبت (با قید ۳۰ دقیقه) */
function appointment_cancel($db, $appointment_id, $reason_id, $actor_person_id, $actor_role_code)
{
    $appt = db_select_one(
        $db,
        "SELECT id, public_id, status, appointment_start_utc FROM appointments WHERE id = ?",
        'i',
        array((int)$appointment_id)
    );
    if (!$appt) {
        throw new Exception('نوبت پیدا نشد.');
    }
    if ($appt['status'] !== 'SCHEDULED') {
        throw new Exception('فقط نوبت «رزروشده» قابل لغو است.');
    }

    $minutes_left = (strtotime($appt['appointment_start_utc'] . ' UTC') - time()) / 60;
    if ($minutes_left < CANCEL_LOCK_MINUTES) {
        throw new Exception('نوبت را نمی‌توان در کمتر از '
            . to_persian_digits(CANCEL_LOCK_MINUTES) . ' دقیقهٔ مانده لغو کرد.');
    }

    $reason_id = ($reason_id === null || $reason_id === '') ? null : (int)$reason_id;
    if ($reason_id === null) {
        throw new Exception('دلیل لغو را انتخاب کنید.');
    }

    db_execute(
        $db,
        "UPDATE appointments
         SET status = 'CANCELLED', cancellation_reason_id = ?, cancelled_by_role_code = ?, cancelled_at = ?
         WHERE id = ? AND status = 'SCHEDULED'",
        'issi',
        array($reason_id, $actor_role_code, now_dt(), (int)$appointment_id)
    );

    audit_log_write($db, $actor_person_id, $actor_role_code, 'APPOINTMENT_CANCELLED',
        'appointment', (int)$appointment_id, array('reason_id' => $reason_id));
}

/** علامت‌گذاری انجام‌شده / عدم مراجعه */
function appointment_set_outcome($db, $appointment_id, $new_status, $actor_person_id, $actor_role_code)
{
    if ($new_status !== 'COMPLETED' && $new_status !== 'NO_SHOW') {
        throw new Exception('وضعیت نامعتبر است.');
    }
    $appt = db_select_one(
        $db,
        "SELECT id, status, appointment_start_utc FROM appointments WHERE id = ?",
        'i',
        array((int)$appointment_id)
    );
    if (!$appt) {
        throw new Exception('نوبت پیدا نشد.');
    }
    if ($appt['status'] !== 'SCHEDULED') {
        throw new Exception('فقط نوبت «رزروشده» قابل تغییر وضعیت است.');
    }
    if ($appt['appointment_start_utc'] > now_dt()) {
        throw new Exception('این نوبت هنوز فرانرسیده است.');
    }

    db_execute($db, "UPDATE appointments SET status = ? WHERE id = ? AND status = 'SCHEDULED'",
        'si', array($new_status, (int)$appointment_id));

    audit_log_write($db, $actor_person_id, $actor_role_code,
        ($new_status === 'COMPLETED' ? 'APPOINTMENT_COMPLETED' : 'APPOINTMENT_NO_SHOW'),
        'appointment', (int)$appointment_id, null);
}

/* ───────────────── خواندن ───────────────── */

function appointment_select_sql()
{
    return "SELECT ap.id, ap.public_id, ap.status, ap.notes,
                   ap.appointment_start_utc, ap.appointment_end_utc, ap.duration_minutes,
                   ap.created_at, ap.cancelled_at, ap.cancelled_by_role_code,
                   ap.admission_id, ap.therapist_person_id, ap.room_id,
                   ad.public_id AS admission_public_id,
                   pt.first_name AS patient_first_name, pt.last_name AS patient_last_name,
                   pt.mobile_number AS patient_mobile,
                   th.first_name AS therapist_first_name, th.last_name AS therapist_last_name,
                   st.title AS service_title,
                   r.name AS room_name, r.room_number,
                   li.label AS cancellation_reason_label
            FROM appointments ap
            INNER JOIN admissions ad ON ad.id = ap.admission_id
            INNER JOIN persons pt ON pt.id = ad.patient_person_id
            INNER JOIN persons th ON th.id = ap.therapist_person_id
            INNER JOIN service_types st ON st.id = ap.service_type_id
            INNER JOIN rooms r ON r.id = ap.room_id
            LEFT JOIN lookup_items li ON li.id = ap.cancellation_reason_id ";
}

/** یافتن نوبت با شناسهٔ عمومی */
function appointment_find_by_public_id($db, $public_id)
{
    return db_select_one($db, appointment_select_sql() . " WHERE ap.public_id = ?",
        's', array($public_id));
}

/** نوبت‌های یک بازهٔ تاریخی محلی (با فیلتر اختیاری درمانگر/وضعیت) */
function appointments_fetch_range($db, $from_local_date, $to_local_date,
                                  $therapist_person_id = null, $status = null, $limit = 300)
{
    $bounds_from = local_day_bounds_utc($from_local_date);
    $bounds_to = local_day_bounds_utc($to_local_date);

    $sql = appointment_select_sql()
         . " WHERE ap.appointment_start_utc >= ? AND ap.appointment_start_utc <= ?";
    $types = 'ss';
    $params = array($bounds_from[0], $bounds_to[1]);

    if ($therapist_person_id) {
        $sql .= " AND ap.therapist_person_id = ?";
        $types .= 'i';
        $params[] = (int)$therapist_person_id;
    }
    if ($status) {
        $sql .= " AND ap.status = ?";
        $types .= 's';
        $params[] = $status;
    }
    $sql .= " ORDER BY ap.appointment_start_utc ASC LIMIT " . (int)$limit;

    return db_select_all($db, $sql, $types, $params);
}

/** نوبت‌های آیندهٔ یک پذیرش */
function appointments_fetch_for_admission($db, $admission_id)
{
    return db_select_all($db, appointment_select_sql()
        . " WHERE ap.admission_id = ? ORDER BY ap.appointment_start_utc DESC LIMIT 50",
        'i', array((int)$admission_id));
}

/** پذیرش‌های پذیرفته‌شدهٔ آمادهٔ نوبت‌دهی، بر پایهٔ شمارهٔ موبایل */
function admissions_searchable_for_appointment($db, $mobile)
{
    return db_select_all(
        $db,
        "SELECT a.id, a.public_id, a.created_at,
                p.first_name, p.last_name, p.mobile_number,
                th.first_name AS therapist_first_name, th.last_name AS therapist_last_name,
                th.id AS therapist_person_id,
                st.title AS service_title, st.default_duration_minutes
         FROM admissions a
         INNER JOIN persons p ON p.id = a.patient_person_id
         INNER JOIN persons th ON th.id = a.referred_therapist_person_id
         INNER JOIN service_types st ON st.id = a.service_type_id
         WHERE a.status = 'ACCEPTED' AND p.mobile_number = ?
         ORDER BY a.created_at DESC LIMIT 20",
        's',
        array($mobile)
    );
}

/** آمار کوتاه برای داشبورد */
function appointments_count_today($db, $therapist_person_id = null)
{
    $bounds = local_day_bounds_utc(clinic_today());
    $sql = "SELECT COUNT(*) AS cnt FROM appointments
            WHERE status = 'SCHEDULED' AND appointment_start_utc BETWEEN ? AND ?";
    $types = 'ss';
    $params = array($bounds[0], $bounds[1]);
    if ($therapist_person_id) {
        $sql .= " AND therapist_person_id = ?";
        $types .= 'i';
        $params[] = (int)$therapist_person_id;
    }
    $row = db_select_one($db, $sql, $types, $params);
    return $row ? (int)$row['cnt'] : 0;
}
