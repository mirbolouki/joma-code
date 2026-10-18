<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — پیام‌های امنِ مراجع و اعلان پیامکیِ لینک ورود
 *
 *  متن کامل پیام فقط در پورتال ذخیره و نمایش داده می‌شود؛ پیامک فقط
 *  یک اعلان عمومی و پیوند ورود دارد و هرگز متن پیام یا یادداشت درمانگر
 *  را منتقل نمی‌کند.
 * ═══════════════════════════════════════════════════════════════════ */

if (!defined('PATIENT_MESSAGES_MIGRATION_VERSION')) {
    define('PATIENT_MESSAGES_MIGRATION_VERSION', '6.2.0');
}
if (!defined('PATIENT_MESSAGE_BODY_MAX')) {
    define('PATIENT_MESSAGE_BODY_MAX', 3000);
}

/** آیا جدول پیام‌های مراجع و نسخهٔ مهاجرت آن آماده‌اند؟ */
function patient_messages_ready($db)
{
    static $ready = false;
    if ($ready) {
        return true;
    }

    $table = db_select_one($db,
        "SELECT 1 AS present FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
        's', array('patient_messages'));
    if (!$table) {
        return false;
    }
    $migrations = db_select_one($db,
        "SELECT 1 AS present FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1",
        's', array('migrations'));
    if (!$migrations) {
        return false;
    }

    $ready = migration_applied($db, PATIENT_MESSAGES_MIGRATION_VERSION);
    return $ready;
}

/** تعداد پیام‌های خوانده‌نشدهٔ خودِ مراجع */
function patient_messages_unread_count($db, $patient_person_id)
{
    if (!patient_messages_ready($db)) {
        return 0;
    }
    $row = db_select_one($db,
        "SELECT COUNT(*) AS total FROM patient_messages
          WHERE patient_person_id = ? AND read_at IS NULL",
        'i', array((int)$patient_person_id));
    return $row ? (int)$row['total'] : 0;
}

/** پیام‌های یک مراجع؛ شناسهٔ شخص همیشه از نشست معتبر فراخواننده می‌آید. */
function patient_messages_fetch_for_person($db, $patient_person_id, $limit = 100)
{
    if (!patient_messages_ready($db)) {
        return array();
    }
    $limit = (int)$limit;
    if ($limit < 1 || $limit > 200) {
        $limit = 100;
    }
    return db_select_all($db,
        "SELECT id, public_id, message_type, subject, body, created_at, read_at
           FROM patient_messages
          WHERE patient_person_id = ?
          ORDER BY created_at DESC, id DESC
          LIMIT " . $limit,
        'i', array((int)$patient_person_id));
}

/** علامت‌گذاری پیام به‌عنوان خوانده‌شده، فقط برای همان مراجع. */
function patient_message_mark_read($db, $patient_person_id, $message_public_id)
{
    if (!patient_messages_ready($db)) {
        throw new Exception('بخش پیام‌های مراجع هنوز فعال نشده است.');
    }
    $row = db_select_one($db,
        "SELECT id FROM patient_messages
          WHERE public_id = ? AND patient_person_id = ? LIMIT 1",
        'si', array((string)$message_public_id, (int)$patient_person_id));
    if (!$row) {
        throw new Exception('پیام موردنظر پیدا نشد.');
    }
    db_execute($db,
        "UPDATE patient_messages SET read_at = ?
          WHERE id = ? AND patient_person_id = ? AND read_at IS NULL",
        'sii', array(now_dt(), (int)$row['id'], (int)$patient_person_id));
}

function patient_message_type_label($type)
{
    $labels = array(
        'MANUAL' => 'پیام کلینیک',
        'ADMISSION_ACCEPTED' => 'تأیید پذیرش',
        'APPOINTMENT_CONFIRMED' => 'تأیید نوبت',
        'FORM_ASSIGNED' => 'فرم تازه',
    );
    return isset($labels[$type]) ? $labels[$type] : 'پیام کلینیک';
}

function patient_message_sms_status_label($status)
{
    $labels = array(
        'PENDING' => 'در انتظار ارسال',
        'SENT' => 'ارسال شد',
        'FAILED' => 'ارسال نشد',
        'DISABLED' => 'پیامک غیرفعال است',
        'DEV_LOGGED' => 'فقط حالت آزمایشی',
    );
    return isset($labels[$status]) ? $labels[$status] : 'نامشخص';
}

/**
 * ارسال اعلان SMS.ir.
 *
 * مسیر اصلی: قالب خدماتی Verify با متغیر کوتاه `notice` و لینک ثابت داخل قالب.
 * SMS.ir برای مقدار متغیر Verify سقف طول دارد؛ URL کامل نباید متغیر شود.
 * مسیر جایگزین: اگر شناسهٔ قالب وارد نشده اما SMSIR_LINE موجود است، پیام
 * عمومی از خط موجود با Bulk ارسال می‌شود.
 *
 * @return array('status' => string, 'error' => string|null)
 */
function patient_message_sms_attempt($mobile)
{
    if (!function_exists('sms_provider_mode') || !function_exists('sms_http_post_json')) {
        return array('status' => 'FAILED', 'error' => 'SMS_MODULE_UNAVAILABLE');
    }

    $mode = sms_provider_mode();
    if ($mode === 'disabled') {
        return array('status' => 'DISABLED', 'error' => 'SMS_DISABLED');
    }
    if ($mode === 'dev') {
        /* عمداً شماره و محتوای پیام در لاگ نوشته نمی‌شوند. */
        if (function_exists('sms_debug_log')) {
            sms_debug_log('PATIENT PORTAL NOTICE (dev) | no SMS sent');
        }
        return array('status' => 'DEV_LOGGED', 'error' => null);
    }

    if (!defined('SMSIR_API_KEY') || trim((string)SMSIR_API_KEY) === '') {
        return array('status' => 'FAILED', 'error' => 'SMSIR_API_KEY_MISSING');
    }

    $digits = preg_replace('/\D/', '', (string)$mobile);
    if (strlen($digits) === 12 && strpos($digits, '98') === 0) {
        $digits = substr($digits, 2);
    } elseif (strlen($digits) === 14 && strpos($digits, '0098') === 0) {
        $digits = substr($digits, 4);
    } elseif (strlen($digits) === 11 && strpos($digits, '09') === 0) {
        $digits = substr($digits, 1);
    }
    if (!preg_match('/^9[0-9]{9}$/', $digits)) {
        return array('status' => 'FAILED', 'error' => 'MOBILE_INVALID');
    }

    $login_link = rtrim(APP_BASE_URL, '/') . '/patient_login.php';
    $template_id = defined('SMSIR_PATIENT_NOTICE_TEMPLATE_ID')
        ? trim((string)SMSIR_PATIENT_NOTICE_TEMPLATE_ID) : '';

    if ($template_id !== '') {
        if (!preg_match('/^[0-9]{1,12}$/', $template_id)) {
            return array('status' => 'FAILED', 'error' => 'NOTICE_TEMPLATE_INVALID');
        }
        /* مقدار کوتاه می‌ماند؛ URL کامل به‌صورت ثابت در متن قالب تأییدشده است. */
        $payload = array(
            'mobile' => $digits,
            'templateId' => (int)$template_id,
            'parameters' => array(
                array('name' => 'notice', 'value' => 'پیام جدید'),
            ),
        );
        $endpoint = 'https://api.sms.ir/v1/send/verify';
        $log_prefix = 'PATIENT PORTAL NOTICE (verify)';
    } else {
        $line = defined('SMSIR_LINE')
            ? preg_replace('/\D/', '', (string)SMSIR_LINE) : '';
        if ($line === '') {
            return array('status' => 'FAILED', 'error' => 'NOTICE_TEMPLATE_OR_LINE_MISSING');
        }
        $payload = array(
            'lineNumber' => (int)$line,
            'messageText' => 'کلینیک جوما: پیام جدید دارید؛ وارد کلینیک شوید و پیام را بررسی کنید: '
                . $login_link,
            'mobiles' => array($digits),
            'sendDateTime' => null,
        );
        $endpoint = 'https://api.sms.ir/v1/send/bulk';
        $log_prefix = 'PATIENT PORTAL NOTICE (bulk)';
    }

    try {
        $response = sms_http_post_json($endpoint, SMSIR_API_KEY, $payload);
    } catch (Throwable $ex) {
        return array('status' => 'FAILED', 'error' => 'GATEWAY_REQUEST_FAILED');
    }

    $gateway_status = (is_array($response['json']) && isset($response['json']['status']))
        ? $response['json']['status'] : null;
    if (!empty($response['ok']) && (int)$gateway_status === 1) {
        if (function_exists('sms_debug_log')) {
            sms_debug_log($log_prefix . ' SENT | HTTP: ' . (int)$response['http_code']);
        }
        return array('status' => 'SENT', 'error' => null);
    }

    /* پاسخ درگاه، کلید، متن پیام و شماره در لاگ پایگاه داده ذخیره نمی‌شود. */
    if (function_exists('sms_debug_log')) {
        sms_debug_log($log_prefix . ' FAILED | HTTP: ' . (int)$response['http_code']);
    }
    return array('status' => 'FAILED', 'error' => 'GATEWAY_REJECTED');
}

/** اجرای دستیِ آزمون اعلان به شمارهٔ خود مدیر؛ هیچ پیام مراجع ایجاد نمی‌شود. */
function patient_message_send_sms_notice($mobile)
{
    return patient_message_sms_attempt($mobile);
}

/**
 * ساخت پیام پورتال و تلاش برای ارسال اعلان SMS.
 *
 * @return array('id' => int, 'sms_status' => string, 'already' => bool)
 */
function patient_message_create($db, $patient_person_id, $message_type, $subject, $body,
                                $admission_id = null, $event_key = null,
                                $actor_person_id = null, $actor_role_code = null)
{
    if (!patient_messages_ready($db)) {
        throw new Exception('بخش پیام‌های مراجع هنوز فعال نشده است.');
    }

    $allowed_types = array('MANUAL', 'ADMISSION_ACCEPTED',
        'APPOINTMENT_CONFIRMED', 'FORM_ASSIGNED');
    if (!in_array($message_type, $allowed_types, true)) {
        throw new Exception('نوع پیام معتبر نیست.');
    }

    $subject = trim((string)$subject);
    $body = trim((string)$body);
    if ($subject === '' || mb_strlen($subject, 'UTF-8') > 160) {
        throw new Exception('عنوان پیام را وارد کنید (حداکثر ۱۶۰ نویسه).');
    }
    if ($body === '' || mb_strlen($body, 'UTF-8') > PATIENT_MESSAGE_BODY_MAX) {
        throw new Exception('متن پیام را وارد کنید (حداکثر '
            . to_persian_digits(PATIENT_MESSAGE_BODY_MAX) . ' نویسه).');
    }

    if ($event_key !== null) {
        $event_key = trim((string)$event_key);
        if ($event_key === '' || strlen($event_key) > 120) {
            throw new Exception('شناسهٔ رویداد پیام معتبر نیست.');
        }
        $existing = db_select_one($db,
            "SELECT id, sms_status FROM patient_messages WHERE event_key = ? LIMIT 1",
            's', array($event_key));
        if ($existing) {
            return array('id' => (int)$existing['id'],
                'sms_status' => $existing['sms_status'], 'already' => true);
        }
    }

    $patient_person_id = (int)$patient_person_id;
    $person = person_find_by_id($db, $patient_person_id);
    if (!$person || $person['status'] !== 'ACTIVE'
        || !role_assignment_is_active($db, $patient_person_id, ROLE_PATIENT)) {
        throw new Exception('حساب مراجعِ فعال پیدا نشد.');
    }

    if ($admission_id !== null) {
        $admission_id = (int)$admission_id;
        $admission = db_select_one($db,
            "SELECT id FROM admissions WHERE id = ? AND patient_person_id = ? LIMIT 1",
            'ii', array($admission_id, $patient_person_id));
        if (!$admission) {
            throw new Exception('پذیرش انتخاب‌شده متعلق به این مراجع نیست.');
        }
    }

    $public_id = generate_public_id('pm');
    try {
        $insert = db_execute($db,
            "INSERT INTO patient_messages
                (public_id, patient_person_id, admission_id, message_type, event_key,
                 subject, body, created_by_person_id, created_by_role_code, sms_status, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,'PENDING',?)",
            'siissssiss',
            array($public_id, $patient_person_id, $admission_id, $message_type,
                  $event_key, $subject, $body,
                  ($actor_person_id === null ? null : (int)$actor_person_id),
                  $actor_role_code, now_dt()));
        $message_id = (int)$insert['insert_id'];
    } catch (Exception $ex) {
        /* اگر دو درخواست هم‌زمان یک رویداد را ثبت کردند، نسخهٔ یکتا برنده است. */
        if ($event_key !== null) {
            $existing = db_select_one($db,
                "SELECT id, sms_status FROM patient_messages WHERE event_key = ? LIMIT 1",
                's', array($event_key));
            if ($existing) {
                return array('id' => (int)$existing['id'],
                    'sms_status' => $existing['sms_status'], 'already' => true);
            }
        }
        throw $ex;
    }

    try {
        audit_log_write($db,
            ($actor_person_id === null ? null : (int)$actor_person_id),
            $actor_role_code, 'PATIENT_MESSAGE_CREATED', 'patient_message', $message_id,
            array('message_type' => $message_type,
                  'body_length' => mb_strlen($body, 'UTF-8')));
    } catch (Throwable $ex) {
        try { log_system_error('PATIENT_MESSAGE_AUDIT', $ex); } catch (Throwable $ignored) { }
    }

    $sms = patient_message_sms_attempt($person['mobile_number']);
    $sent_at = ($sms['status'] === 'SENT') ? now_dt() : null;
    try {
        db_execute($db,
            "UPDATE patient_messages
                SET sms_status = ?, sms_error = ?, sms_sent_at = ?
              WHERE id = ?",
            'sssi', array($sms['status'], $sms['error'], $sent_at, $message_id));
    } catch (Throwable $ex) {
        try { log_system_error('PATIENT_MESSAGE_SMS_STATUS', $ex); } catch (Throwable $ignored) { }
    }

    if ($sms['status'] === 'SENT' || $sms['status'] === 'FAILED') {
        try {
            audit_log_write($db,
                ($actor_person_id === null ? null : (int)$actor_person_id),
                $actor_role_code,
                ($sms['status'] === 'SENT') ? 'PATIENT_MESSAGE_SMS_SENT' : 'PATIENT_MESSAGE_SMS_FAILED',
                'patient_message', $message_id,
                ($sms['status'] === 'SENT') ? null : array('reason' => $sms['error']));
        } catch (Throwable $ex) {
            try { log_system_error('PATIENT_MESSAGE_SMS_AUDIT', $ex); } catch (Throwable $ignored) { }
        }
    }

    return array('id' => $message_id, 'sms_status' => $sms['status'], 'already' => false);
}

/** ثبت امنِ پیام رویدادی؛ شکست اعلان هیچ‌گاه پذیرش، نوبت یا تخصیص فرم را برنمی‌گرداند. */
function patient_message_create_event_safe($db, $patient_person_id, $admission_id,
                                          $message_type, $subject, $body, $event_key,
                                          $actor_person_id = null, $actor_role_code = null)
{
    try {
        if (!patient_messages_ready($db)) {
            return null;
        }
        return patient_message_create($db, $patient_person_id, $message_type,
            $subject, $body, $admission_id, $event_key,
            $actor_person_id, $actor_role_code);
    } catch (Throwable $ex) {
        try {
            log_system_error('PATIENT_MESSAGE_EVENT', $ex);
        } catch (Throwable $ignored) {
            /* پیام نباید عملیات اصلی را خراب کند، حتی اگر ثبت خطا هم در دسترس نباشد. */
        }
        return null;
    }
}
