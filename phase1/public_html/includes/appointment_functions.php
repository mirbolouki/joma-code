<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما فاز ۲ — موتور نوبت‌دهی
 *
 *  قواعد تثبیت‌شده (اصلاحیهٔ گام ۱):
 *   • appointment_holds = «قفل موقت رزرو» با عمر ۳۰۰ ثانیه؛
 *     برنامهٔ حضور هفتگی درمانگر نیست و چنین جدولی وجود ندارد.
 *   • زمان قابل رزرو از این چهار چیز محاسبه می‌شود:
 *       ساعات کاری ۹–۲۰ تهران، عدم‌حضور درمانگر،
 *       نوبت‌های معتبر، و Holdهای منقضی‌نشده.
 *   • تداخل «درمانگر» ممنوعیت سخت است.
 *   • تداخل «اتاق» فقط هشدار نرم است — حتی در اتاق با ظرفیت ۱.
 *     ظرفیت اتاق اطلاعاتی است و هرگز رزرو را متوقف نمی‌کند.
 *     اتاق غیرفعال برای رزرو جدید انتخاب‌شدنی نیست.
 *   • درمانگر، مدت و مبلغ در لحظهٔ رزرو داخل خود نوبت تثبیت می‌شوند.
 *   • همهٔ مسیرهای رزرو از یک سازوکار قفل مشترک استفاده می‌کنند:
 *     قفل ردیف شخصِ درمانگر با SELECT ... FOR UPDATE.
 *   • انقضای Hold به‌صورت Lazy است؛ هیچ Cron لازم نیست.
 *   • هیچ نوبتی حذف فیزیکی نمی‌شود.
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

/* ═════════════ قفل مشترک هم‌زمانی ═════════════
 * هر مسیری که زمان درمانگر را اشغال می‌کند باید نخست این را صدا بزند،
 * داخل یک تراکنش باز. ردیف شخصِ درمانگر تا Commit قفل می‌ماند، پس دو
 * درخواست هم‌زمان برای دو پذیرش متفاوتِ همان درمانگر سریال می‌شوند.
 */
function schedule_lock_therapist($db, $therapist_person_id)
{
    $row = db_select_one(
        $db,
        "SELECT id, status FROM persons WHERE id = ? FOR UPDATE",
        'i',
        array((int)$therapist_person_id)
    );
    if (!$row) {
        throw new Exception('درمانگر موردنظر پیدا نشد.');
    }
    if ($row['status'] !== 'ACTIVE') {
        throw new Exception('این درمانگر غیرفعال است.');
    }
    return $row;
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

/**
 * افزودن قانون عدم‌حضور.
 * نوبت‌های موجود را به‌صورت خودکار لغو نمی‌کند؛ فهرست نوبت‌های متأثر
 * برگردانده می‌شود تا در رابط کاربری به مدیر/منشی نشان داده شود.
 */
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
         (public_id, therapist_person_id, start_date, end_date, reason_id, is_repeating,
          is_active, created_by_person_id, created_at)
         VALUES (?,?,?,?,?,?,1,?,?)",
        'sissiiis',
        array($public_id, (int)$therapist_person_id, $start_date, $end_date,
              $reason_id, ($is_repeating ? 1 : 0), (int)$actor_person_id, now_dt())
    );
    $rule_id = (int)$res['insert_id'];

    $affected = absence_affected_appointments($db, $therapist_person_id, $start_date, $end_date, $is_repeating);

    audit_log_write($db, $actor_person_id, $actor_role_code, 'ABSENCE_RULE_CREATED',
        'therapist_absence_rule', $rule_id,
        array('start' => $start_date, 'end' => $end_date,
              'repeating' => $is_repeating ? 1 : 0,
              'affected_appointments' => count($affected)));

    return array('rule_id' => $rule_id, 'affected' => $affected);
}

/** نوبت‌های رزروشده‌ای که داخل بازهٔ عدم‌حضور می‌افتند (فقط گزارش، بدون لغو) */
function absence_affected_appointments($db, $therapist_person_id, $start_date, $end_date, $is_repeating)
{
    if ($is_repeating) {
        $from = clinic_today();
        $to = date_add_days($from, 365);
    } else {
        $from = $start_date;
        $to = $end_date;
    }
    $b1 = local_day_bounds_utc($from);
    $b2 = local_day_bounds_utc($to);

    $rows = db_select_all(
        $db,
        appointment_select_sql() . " WHERE ap.therapist_person_id = ? AND ap.status = 'SCHEDULED'
           AND ap.appointment_start_utc >= ? AND ap.appointment_start_utc <= ?
         ORDER BY ap.appointment_start_utc ASC LIMIT 200",
        'iss',
        array((int)$therapist_person_id, $b1[0], $b2[1])
    );

    $out = array();
    foreach ($rows as $r) {
        $local_date = substr(utc_to_local($r['appointment_start_utc']), 0, 10);
        if ($is_repeating) {
            $md = substr($local_date, 5);
            $s = substr($start_date, 5);
            $e = substr($end_date, 5);
            $hit = ($s <= $e) ? ($md >= $s && $md <= $e) : ($md >= $s || $md <= $e);
        } else {
            $hit = ($local_date >= $start_date && $local_date <= $end_date);
        }
        if ($hit) {
            $out[] = $r;
        }
    }
    return $out;
}

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

/* ═════════════ قفل موقت رزرو (Hold) ═════════════ */

/** عبارت SQL «این Hold هنوز معتبر است» */
function hold_active_condition()
{
    return " consumed_at IS NULL AND released_at IS NULL AND expires_at > ? ";
}

/**
 * پاکسازی Lazy: Holdهای منقضی‌شده علامت می‌خورند.
 * هیچ Cronی لازم نیست؛ هر مسیر رزرو این را صدا می‌زند.
 */
function holds_expire_lazy($db)
{
    $res = db_execute(
        $db,
        "UPDATE appointment_holds SET released_at = ?
         WHERE consumed_at IS NULL AND released_at IS NULL AND expires_at <= ?",
        'ss',
        array(now_dt(), now_dt())
    );
    return (int)$res['affected'];
}

/** تعداد Holdهای معتبرِ متداخل برای یک درمانگر */
function hold_overlap_count($db, $therapist_person_id, $start_utc, $end_utc, $exclude_hold_id = null)
{
    $sql = "SELECT COUNT(*) AS cnt FROM appointment_holds
            WHERE therapist_person_id = ? AND hold_start_utc < ? AND hold_end_utc > ?
              AND " . hold_active_condition();
    $types = 'isss';
    $params = array((int)$therapist_person_id, $end_utc, $start_utc, now_dt());

    if ($exclude_hold_id) {
        $sql .= " AND id != ?";
        $types .= 'i';
        $params[] = (int)$exclude_hold_id;
    }
    $row = db_select_one($db, $sql, $types, $params);
    return $row ? (int)$row['cnt'] : 0;
}

/**
 * ساخت قفل موقت.
 * خروجی: array('hold'=>row, 'warnings'=>array)
 */
function hold_create($db, $admission_id, $room_id, $local_date, $start_clock, $duration_minutes,
                     $actor_person_id, $actor_role_code)
{
    holds_expire_lazy($db);

    $ctx = booking_context($db, $admission_id, $room_id, $local_date, $start_clock, $duration_minutes);
    $warnings = array();

    mysqli_begin_transaction($db);
    try {
        schedule_lock_therapist($db, $ctx['therapist_person_id']);

        booking_assert_free($db, $ctx['therapist_person_id'], $ctx['start_utc'], $ctx['end_utc']);

        $room_busy = room_overlap_count($db, (int)$ctx['room']['id'], $ctx['start_utc'], $ctx['end_utc']);
        if ($room_busy > 0) {
            $warnings[] = 'در این بازه ' . to_persian_digits($room_busy)
                . ' جلسهٔ دیگر در همین اتاق ثبت شده است (فقط هشدار).';
        }

        $public_id = generate_public_id('hd');
        $expires_at = utc_dt_offset(HOLD_TTL_SECONDS);

        $res = db_execute(
            $db,
            "INSERT INTO appointment_holds
             (public_id, admission_id, therapist_person_id, service_type_id, room_id,
              hold_start_utc, hold_end_utc, duration_minutes, expires_at, created_by_person_id, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            'siiiissisis',
            array($public_id, (int)$ctx['admission']['id'], (int)$ctx['therapist_person_id'],
                  (int)$ctx['admission']['service_type_id'], (int)$ctx['room']['id'],
                  $ctx['start_utc'], $ctx['end_utc'], (int)$ctx['duration_minutes'],
                  $expires_at, (int)$actor_person_id, now_dt())
        );
        $hold_id = (int)$res['insert_id'];

        mysqli_commit($db);
    } catch (Exception $ex) {
        mysqli_rollback($db);
        throw $ex;
    }

    audit_log_write($db, $actor_person_id, $actor_role_code, 'HOLD_CREATED', 'appointment_hold',
        $hold_id, array('start_utc' => $ctx['start_utc'], 'ttl_seconds' => HOLD_TTL_SECONDS));

    return array('hold' => hold_find_by_public_id($db, $public_id), 'warnings' => $warnings);
}

function hold_find_by_public_id($db, $public_id)
{
    return db_select_one(
        $db,
        "SELECT h.id, h.public_id, h.admission_id, h.therapist_person_id, h.service_type_id,
                h.room_id, h.hold_start_utc, h.hold_end_utc, h.duration_minutes,
                h.expires_at, h.consumed_at, h.consumed_appointment_id, h.released_at,
                h.created_by_person_id
         FROM appointment_holds h WHERE h.public_id = ?",
        's',
        array($public_id)
    );
}

/** چند ثانیه از عمر Hold مانده است (۰ یعنی منقضی) */
function hold_seconds_left($hold)
{
    if (!$hold || $hold['consumed_at'] !== null || $hold['released_at'] !== null) {
        return 0;
    }
    $left = strtotime($hold['expires_at'] . ' UTC') - time();
    return $left > 0 ? (int)$left : 0;
}

/** رها کردن دستی قفل موقت */
function hold_release($db, $public_id, $actor_person_id, $actor_role_code)
{
    $hold = hold_find_by_public_id($db, $public_id);
    if (!$hold || $hold['consumed_at'] !== null) {
        return;
    }
    db_execute($db, "UPDATE appointment_holds SET released_at = ? WHERE id = ? AND consumed_at IS NULL",
        'si', array(now_dt(), (int)$hold['id']));
    audit_log_write($db, $actor_person_id, $actor_role_code, 'HOLD_RELEASED',
        'appointment_hold', (int)$hold['id'], null);
}

/* ═════════════ محاسبات مشترک رزرو ═════════════ */

/**
 * آماده‌سازی و اعتبارسنجی پایهٔ یک رزرو.
 * پذیرش «در انتظار تصمیم درمانگر» هم می‌تواند نوبت بگیرد؛
 * پذیرش ردشده باید ابتدا ارجاع مجدد شود.
 */
function booking_context($db, $admission_id, $room_id, $local_date, $start_clock, $duration_minutes = null)
{
    $admission = db_select_one(
        $db,
        "SELECT a.id, a.public_id, a.status, a.service_type_id, a.referred_therapist_person_id,
                a.patient_person_id, st.default_duration_minutes, st.title AS service_title,
                p.first_name, p.last_name, p.mobile_number
         FROM admissions a
         INNER JOIN service_types st ON st.id = a.service_type_id
         INNER JOIN persons p ON p.id = a.patient_person_id
         WHERE a.id = ?",
        'i',
        array((int)$admission_id)
    );
    if (!$admission) {
        throw new Exception('پذیرش موردنظر پیدا نشد.');
    }
    if ($admission['status'] === 'DECLINED') {
        throw new Exception('این پذیرش رد شده است؛ ابتدا باید به درمانگر دیگری ارجاع مجدد شود.');
    }

    $room = room_find($db, $room_id);
    if (!$room) {
        throw new Exception('اتاق موردنظر پیدا نشد.');
    }
    if ($room['status'] !== 'ACTIVE') {
        throw new Exception('این اتاق غیرفعال است و قابل رزرو نیست.');
    }

    /* مدت جلسه از خدمت گرفته می‌شود؛ مقدار ثابت ۶۰ دقیقه ممنوع است. */
    $duration_minutes = (int)$duration_minutes;
    if ($duration_minutes <= 0) {
        $duration_minutes = (int)$admission['default_duration_minutes'];
    }
    if ($duration_minutes < 15 || $duration_minutes > 240) {
        throw new Exception('مدت جلسه باید میان ۱۵ تا ۲۴۰ دقیقه باشد.');
    }

    $start_clock = parse_clock($start_clock);
    if ($start_clock === null) {
        throw new Exception('ساعت شروع نامعتبر است.');
    }
    $end_clock = minutes_to_clock(clock_to_minutes($start_clock) + $duration_minutes);
    if (!within_working_hours($start_clock, $end_clock)) {
        throw new Exception('شروع و پایان جلسه باید کاملاً درون ساعات کاری ('
            . to_persian_digits(CLINIC_DAY_START_HOUR) . ':۰۰ تا '
            . to_persian_digits(CLINIC_DAY_END_HOUR) . ':۰۰ به وقت تهران) باشد.');
    }

    $today = clinic_today();
    if ($local_date < $today) {
        throw new Exception('تاریخ نوبت نمی‌تواند در گذشته باشد.');
    }
    if (date_diff_days($today, $local_date) > BOOKING_HORIZON_DAYS) {
        throw new Exception('حداکثر تا ' . to_persian_digits(BOOKING_HORIZON_DAYS)
            . ' روز آینده می‌توان نوبت ثبت کرد.');
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

    return array(
        'admission' => $admission,
        'room' => $room,
        'therapist_person_id' => $therapist_id,
        'local_date' => $local_date,
        'start_clock' => $start_clock,
        'end_clock' => $end_clock,
        'duration_minutes' => $duration_minutes,
        'start_utc' => $start_utc,
        'end_utc' => $end_utc,
    );
}

/**
 * بررسی سخت تداخل درمانگر — باید داخل تراکنش و پس از قفل اجرا شود.
 * شامل نوبت‌های معتبر و Holdهای منقضی‌نشده.
 */
function booking_assert_free($db, $therapist_person_id, $start_utc, $end_utc,
                             $exclude_appointment_id = null, $exclude_hold_id = null)
{
    if (therapist_overlap_count($db, $therapist_person_id, $start_utc, $end_utc, $exclude_appointment_id) > 0) {
        throw new Exception('درمانگر در این بازه نوبت دیگری دارد.');
    }
    if (hold_overlap_count($db, $therapist_person_id, $start_utc, $end_utc, $exclude_hold_id) > 0) {
        throw new Exception('این بازه هم‌اکنون توسط کاربر دیگری در حال رزرو است؛ چند دقیقهٔ دیگر تلاش کنید.');
    }
}

/** تعداد نوبت‌های فعالِ متداخل در یک اتاق (فقط برای هشدار) */
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

/* ═════════════ تثبیت نوبت ═════════════ */

/**
 * تثبیت یک قفل موقت و تبدیل آن به نوبت.
 * اجرای دوبارهٔ همین تابع روی همان Hold، نوبت دوم نمی‌سازد؛
 * نوبت قبلی برگردانده می‌شود (idempotent).
 */
function appointment_confirm_hold($db, $hold_public_id, $notes, $actor_person_id, $actor_role_code)
{
    holds_expire_lazy($db);

    $hold = hold_find_by_public_id($db, $hold_public_id);
    if (!$hold) {
        throw new Exception('قفل رزرو پیدا نشد.');
    }

    /* تثبیت تکراری → همان نوبت قبلی */
    if ($hold['consumed_at'] !== null && $hold['consumed_appointment_id']) {
        $existing = db_select_one($db, appointment_select_sql() . " WHERE ap.id = ?",
            'i', array((int)$hold['consumed_appointment_id']));
        return array('appointment' => $existing, 'warnings' => array(), 'already' => true);
    }
    if ($hold['released_at'] !== null || hold_seconds_left($hold) <= 0) {
        throw new Exception('مهلت نگه‌داشتن این زمان به پایان رسیده است؛ دوباره زمان را انتخاب کنید.');
    }

    $warnings = array();
    $notes = ($notes === null || trim($notes) === '') ? null : mb_substr(trim($notes), 0, 1000, 'UTF-8');

    mysqli_begin_transaction($db);
    try {
        schedule_lock_therapist($db, $hold['therapist_person_id']);

        /* خواندن دوبارهٔ Hold داخل قفل، برای جلوگیری از تثبیت هم‌زمان */
        $fresh = db_select_one(
            $db,
            "SELECT id, consumed_at, consumed_appointment_id, released_at, expires_at
             FROM appointment_holds WHERE id = ? FOR UPDATE",
            'i',
            array((int)$hold['id'])
        );
        if ($fresh['consumed_at'] !== null) {
            mysqli_commit($db);
            $existing = db_select_one($db, appointment_select_sql() . " WHERE ap.id = ?",
                'i', array((int)$fresh['consumed_appointment_id']));
            return array('appointment' => $existing, 'warnings' => array(), 'already' => true);
        }
        if ($fresh['released_at'] !== null || $fresh['expires_at'] <= now_dt()) {
            throw new Exception('مهلت نگه‌داشتن این زمان به پایان رسیده است.');
        }

        booking_assert_free($db, $hold['therapist_person_id'],
            $hold['hold_start_utc'], $hold['hold_end_utc'], null, (int)$hold['id']);

        $local_date = substr(utc_to_local($hold['hold_start_utc']), 0, 10);
        $absence = absence_rule_matching($db, $hold['therapist_person_id'], $local_date);
        if ($absence) {
            throw new Exception('درمانگر در این تاریخ حضور ندارد.');
        }

        $room_busy = room_overlap_count($db, (int)$hold['room_id'],
            $hold['hold_start_utc'], $hold['hold_end_utc']);
        if ($room_busy > 0) {
            $warnings[] = 'در این بازه ' . to_persian_digits($room_busy)
                . ' جلسهٔ دیگر در همین اتاق ثبت شده است (فقط هشدار).';
        }

        /* مبلغ در لحظهٔ رزرو تثبیت می‌شود */
        $tariff = tariff_find_active($db, $hold['therapist_person_id'], $hold['service_type_id']);
        $price_rial = $tariff ? (float)$tariff['price_per_session'] : null;
        if ($price_rial === null) {
            $warnings[] = 'برای این درمانگر و خدمت، تعرفه‌ای ثبت نشده است؛ مبلغ نوبت خالی ماند.';
        }

        $public_id = generate_public_id('ap');
        $res = db_execute(
            $db,
            "INSERT INTO appointments
             (public_id, admission_id, therapist_person_id, service_type_id, room_id,
              appointment_start_utc, appointment_end_utc, duration_minutes, price_rial, currency,
              status, notes, created_by_person_id, created_by_role_code, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,'IRR','SCHEDULED',?,?,?,?)",
            'siiiissidsiss',
            array($public_id, (int)$hold['admission_id'], (int)$hold['therapist_person_id'],
                  (int)$hold['service_type_id'], (int)$hold['room_id'],
                  $hold['hold_start_utc'], $hold['hold_end_utc'], (int)$hold['duration_minutes'],
                  $price_rial, $notes,
                  (int)$actor_person_id, $actor_role_code, now_dt())
        );
        $appt_id = (int)$res['insert_id'];

        $upd = db_execute(
            $db,
            "UPDATE appointment_holds SET consumed_at = ?, consumed_appointment_id = ?
             WHERE id = ? AND consumed_at IS NULL",
            'sii',
            array(now_dt(), $appt_id, (int)$hold['id'])
        );
        if ((int)$upd['affected'] !== 1) {
            throw new Exception('این زمان هم‌زمان توسط کاربر دیگری تثبیت شد.');
        }

        mysqli_commit($db);
    } catch (Exception $ex) {
        mysqli_rollback($db);
        throw $ex;
    }

    audit_log_write($db, $actor_person_id, $actor_role_code, 'APPOINTMENT_CREATED',
        'appointment', $appt_id,
        array('hold_public_id' => $hold['public_id'],
              'start_utc' => $hold['hold_start_utc'],
              'duration' => (int)$hold['duration_minutes'],
              'room_id' => (int)$hold['room_id'],
              'warnings' => count($warnings)));

    /* نقطهٔ اتصال فاز ۴: تخصیص فرم اولیه در «تثبیت نخستین نوبتِ این پذیرش»
       انجام خواهد شد — نه در ایجاد Hold. در فاز ۲ هیچ جدول فرمی ساخته نمی‌شود. */

    $appointment = db_select_one($db, appointment_select_sql() . " WHERE ap.id = ?",
        'i', array($appt_id));

    return array('appointment' => $appointment, 'warnings' => $warnings, 'already' => false);
}

/* ═════════════ جابه‌جایی اتمیک ═════════════ */

/**
 * جابه‌جایی نوبت. در صورت هر شکستی، نوبت قبلی دست‌نخورده می‌ماند.
 * نوبت قبلی لغو و نوبت تازه با ارجاع `rescheduled_from_id` ساخته می‌شود.
 */
function appointment_reschedule($db, $appointment_id, $room_id, $local_date, $start_clock,
                                $reason_id, $actor_person_id, $actor_role_code)
{
    holds_expire_lazy($db);

    $old = db_select_one(
        $db,
        "SELECT id, public_id, admission_id, therapist_person_id, service_type_id,
                duration_minutes, status, price_rial, notes
         FROM appointments WHERE id = ?",
        'i',
        array((int)$appointment_id)
    );
    if (!$old) {
        throw new Exception('نوبت پیدا نشد.');
    }
    if ($old['status'] !== 'SCHEDULED') {
        throw new Exception('فقط نوبت «رزروشده» قابل جابه‌جایی است.');
    }

    $ctx = booking_context($db, $old['admission_id'], $room_id, $local_date,
        $start_clock, (int)$old['duration_minutes']);

    $warnings = array();
    $new_public_id = generate_public_id('ap');

    mysqli_begin_transaction($db);
    try {
        schedule_lock_therapist($db, $ctx['therapist_person_id']);

        booking_assert_free($db, $ctx['therapist_person_id'],
            $ctx['start_utc'], $ctx['end_utc'], (int)$old['id'], null);

        $room_busy = room_overlap_count($db, (int)$ctx['room']['id'],
            $ctx['start_utc'], $ctx['end_utc'], (int)$old['id']);
        if ($room_busy > 0) {
            $warnings[] = 'در این بازه ' . to_persian_digits($room_busy)
                . ' جلسهٔ دیگر در همین اتاق ثبت شده است (فقط هشدار).';
        }

        $cancel = db_execute(
            $db,
            "UPDATE appointments
             SET status = 'CANCELLED', cancellation_reason_id = ?, cancelled_by_role_code = ?, cancelled_at = ?
             WHERE id = ? AND status = 'SCHEDULED'",
            'issi',
            array(($reason_id ? (int)$reason_id : null), $actor_role_code, now_dt(), (int)$old['id'])
        );
        if ((int)$cancel['affected'] !== 1) {
            throw new Exception('وضعیت نوبت هم‌زمان تغییر کرد؛ دوباره تلاش کنید.');
        }

        $res = db_execute(
            $db,
            "INSERT INTO appointments
             (public_id, admission_id, therapist_person_id, service_type_id, room_id,
              appointment_start_utc, appointment_end_utc, duration_minutes, price_rial, currency,
              status, notes, rescheduled_from_id, created_by_person_id, created_by_role_code, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,'IRR','SCHEDULED',?,?,?,?,?)",
            'siiiissidsiiss',
            array($new_public_id, (int)$old['admission_id'], (int)$ctx['therapist_person_id'],
                  (int)$old['service_type_id'], (int)$ctx['room']['id'],
                  $ctx['start_utc'], $ctx['end_utc'], (int)$old['duration_minutes'],
                  ($old['price_rial'] === null ? null : (float)$old['price_rial']),
                  $old['notes'], (int)$old['id'],
                  (int)$actor_person_id, $actor_role_code, now_dt())
        );
        $new_id = (int)$res['insert_id'];

        mysqli_commit($db);
    } catch (Exception $ex) {
        mysqli_rollback($db);
        throw $ex;
    }

    audit_log_write($db, $actor_person_id, $actor_role_code, 'APPOINTMENT_RESCHEDULED',
        'appointment', $new_id,
        array('from_appointment' => $old['public_id'], 'new_start_utc' => $ctx['start_utc']));

    return array(
        'appointment' => db_select_one($db, appointment_select_sql() . " WHERE ap.id = ?", 'i', array($new_id)),
        'warnings' => $warnings,
    );
}

/* ═════════════ تغییر وضعیت ═════════════ */

/** لغو نوبت؛ بررسی، تغییر و ثبت Audit همگی داخل یک تراکنش */
function appointment_cancel($db, $appointment_id, $reason_id, $actor_person_id, $actor_role_code)
{
    $reason_id = ($reason_id === null || $reason_id === '') ? null : (int)$reason_id;
    if ($reason_id === null) {
        throw new Exception('دلیل لغو را انتخاب کنید.');
    }

    mysqli_begin_transaction($db);
    try {
        $appt = db_select_one(
            $db,
            "SELECT id, public_id, status, appointment_start_utc FROM appointments WHERE id = ? FOR UPDATE",
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

        mysqli_commit($db);
    } catch (Exception $ex) {
        mysqli_rollback($db);
        throw $ex;
    }
}

/** ثبت «انجام‌شده» یا «عدم مراجعه» */
function appointment_set_outcome($db, $appointment_id, $new_status, $actor_person_id, $actor_role_code)
{
    if ($new_status !== 'COMPLETED' && $new_status !== 'NO_SHOW') {
        throw new Exception('وضعیت نامعتبر است.');
    }

    mysqli_begin_transaction($db);
    try {
        $appt = db_select_one(
            $db,
            "SELECT id, status, appointment_start_utc FROM appointments WHERE id = ? FOR UPDATE",
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

        mysqli_commit($db);
    } catch (Exception $ex) {
        mysqli_rollback($db);
        throw $ex;
    }
}

/* ═════════════ خواندن ═════════════ */

function appointment_select_sql()
{
    return "SELECT ap.id, ap.public_id, ap.status, ap.notes,
                   ap.appointment_start_utc, ap.appointment_end_utc, ap.duration_minutes,
                   ap.price_rial, ap.currency, ap.created_at, ap.cancelled_at,
                   ap.cancelled_by_role_code, ap.rescheduled_from_id,
                   ap.admission_id, ap.therapist_person_id, ap.room_id, ap.service_type_id,
                   ad.public_id AS admission_public_id, ad.status AS admission_status,
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

function appointment_find_by_public_id($db, $public_id)
{
    return db_select_one($db, appointment_select_sql() . " WHERE ap.public_id = ?",
        's', array($public_id));
}

/** نوبت‌های یک بازهٔ تاریخی محلی */
function appointments_fetch_range($db, $from_local_date, $to_local_date,
                                  $therapist_person_id = null, $status = null, $limit = 300)
{
    $b1 = local_day_bounds_utc($from_local_date);
    $b2 = local_day_bounds_utc($to_local_date);

    $sql = appointment_select_sql()
         . " WHERE ap.appointment_start_utc >= ? AND ap.appointment_start_utc <= ?";
    $types = 'ss';
    $params = array($b1[0], $b2[1]);

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

function appointments_fetch_for_admission($db, $admission_id)
{
    return db_select_all($db, appointment_select_sql()
        . " WHERE ap.admission_id = ? ORDER BY ap.appointment_start_utc DESC LIMIT 50",
        'i', array((int)$admission_id));
}

/** پذیرش‌های قابل نوبت‌دهی بر پایهٔ شمارهٔ موبایل (پذیرفته‌شده یا در انتظار) */
function admissions_searchable_for_appointment($db, $mobile)
{
    return db_select_all(
        $db,
        "SELECT a.id, a.public_id, a.status, a.created_at,
                p.first_name, p.last_name, p.mobile_number,
                th.first_name AS therapist_first_name, th.last_name AS therapist_last_name,
                th.id AS therapist_person_id,
                st.id AS service_type_id, st.title AS service_title, st.default_duration_minutes
         FROM admissions a
         INNER JOIN persons p ON p.id = a.patient_person_id
         INNER JOIN persons th ON th.id = a.referred_therapist_person_id
         INNER JOIN service_types st ON st.id = a.service_type_id
         WHERE a.status IN ('ACCEPTED','AWAITING_THERAPIST') AND p.mobile_number = ?
         ORDER BY a.created_at DESC LIMIT 20",
        's',
        array($mobile)
    );
}

/**
 * زمان‌های قابل رزرو یک درمانگر در یک روز محلی.
 * از ساعات کاری، عدم‌حضور، نوبت‌های معتبر و Holdهای فعال محاسبه می‌شود.
 * هیچ جدول «برنامهٔ حضور» در کار نیست.
 */
function bookable_slots($db, $therapist_person_id, $local_date, $duration_minutes)
{
    holds_expire_lazy($db);

    if (absence_rule_matching($db, $therapist_person_id, $local_date)) {
        return array();
    }
    $today = clinic_today();
    if ($local_date < $today || date_diff_days($today, $local_date) > BOOKING_HORIZON_DAYS) {
        return array();
    }

    $bounds = local_day_bounds_utc($local_date);

    $busy = db_select_all(
        $db,
        "SELECT appointment_start_utc AS s, appointment_end_utc AS e FROM appointments
         WHERE therapist_person_id = ? AND status IN ('SCHEDULED','COMPLETED')
           AND appointment_start_utc <= ? AND appointment_end_utc >= ?",
        'iss',
        array((int)$therapist_person_id, $bounds[1], $bounds[0])
    );
    $held = db_select_all(
        $db,
        "SELECT hold_start_utc AS s, hold_end_utc AS e FROM appointment_holds
         WHERE therapist_person_id = ? AND hold_start_utc <= ? AND hold_end_utc >= ?
           AND consumed_at IS NULL AND released_at IS NULL AND expires_at > ?",
        'isss',
        array((int)$therapist_person_id, $bounds[1], $bounds[0], now_dt())
    );
    $blocked = array_merge($busy, $held);

    $slots = array();
    $now_utc = now_dt();
    $start_min = CLINIC_DAY_START_HOUR * 60;
    $end_min = CLINIC_DAY_END_HOUR * 60;
    $duration_minutes = (int)$duration_minutes;

    for ($m = $start_min; $m + $duration_minutes <= $end_min; $m += SLOT_STEP_MINUTES) {
        $clock = minutes_to_clock($m);
        $s_utc = local_to_utc($local_date . ' ' . $clock . ':00');
        $e_utc = utc_add_minutes($s_utc, $duration_minutes);
        if ($s_utc <= $now_utc) {
            continue;
        }
        $free = true;
        foreach ($blocked as $b) {
            if ($s_utc < $b['e'] && $e_utc > $b['s']) {
                $free = false;
                break;
            }
        }
        if ($free) {
            $slots[] = array('clock' => $clock, 'label' => to_persian_digits($clock),
                             'start_utc' => $s_utc, 'end_utc' => $e_utc);
        }
    }
    return $slots;
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

/**
 * چرا در این روز زمان آزادی نیست؟ (پیام دقیق به‌جای جملهٔ مبهم)
 * فقط زمانی صدا زده می‌شود که bookable_slots() آرایهٔ خالی برگردانده باشد.
 */
function slots_empty_reason($db, $therapist_person_id, $local_date, $duration_minutes)
{
    $today = clinic_today();
    $duration_minutes = (int)$duration_minutes;

    if ($local_date < $today) {
        return 'این تاریخ گذشته است؛ تاریخ دیگری انتخاب کنید.';
    }
    if (date_diff_days($today, $local_date) > BOOKING_HORIZON_DAYS) {
        return 'حداکثر تا ' . to_persian_digits(BOOKING_HORIZON_DAYS)
             . ' روز آینده می‌توان نوبت ثبت کرد؛ تاریخ نزدیک‌تری انتخاب کنید.';
    }

    $absence = absence_rule_matching($db, $therapist_person_id, $local_date);
    if ($absence) {
        return 'درمانگر در این تاریخ حضور ندارد'
             . (!empty($absence['reason_label']) ? ' (' . $absence['reason_label'] . ')' : '')
             . '؛ تاریخ دیگری انتخاب کنید.';
    }

    /* امروز است و ساعت کاری عملاً تمام شده */
    if ($local_date === $today) {
        $now_min = clock_to_minutes(clinic_now_time());
        $last_start = CLINIC_DAY_END_HOUR * 60 - $duration_minutes;
        if ($now_min > $last_start) {
            return 'ساعات کاری امروز برای یک جلسهٔ '
                 . to_persian_digits($duration_minutes) . ' دقیقه‌ای به پایان رسیده است '
                 . '(آخرین شروع ممکن: ' . to_persian_digits(minutes_to_clock($last_start))
                 . ' و اکنون ' . to_persian_digits(clinic_now_time())
                 . ' است). لطفاً «فردا» یا روزی دیگر را انتخاب کنید.';
        }
    }

    return 'همهٔ ساعت‌های کاری این روز پر شده یا هم‌اکنون توسط کاربر دیگری در حال رزرو است.';
}
