<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۴: ورود مراجع با رمز یک‌بارمصرف و نگهبان پورتال
 *
 *  تصمیم D4-3 (امنیت، گزینهٔ ب):
 *    نشستی که با پیامک ساخته شده، «نشست پورتال» است:
 *      • پرچم patient_portal_only روی نشست می‌نشیند،
 *      • نقش فعال به patient قفل می‌شود،
 *      • هر مسیر بیرون از patient/ برای این نشست ۴۰۳ می‌گیرد.
 *    یک کد پیامکی هرگز نباید دری بی‌رمز به پنل پرسنل باز کند.
 *
 *  هیچ حسابی با رمز عبور برای مراجع ساخته نمی‌شود؛ تنها راه ورود او
 *  پیامک است. رمز ذخیره‌شده یک رشتهٔ تصادفی غیرقابل‌استفاده است.
 * ═══════════════════════════════════════════════════════════════════ */

define('PATIENT_OTP_PURPOSE', 'PATIENT_LOGIN');

/** پیام یکسان برای «شماره ناشناس» و «شمارهٔ شناخته‌شده» — جلوگیری از شمارش شماره‌ها */
define('PATIENT_LOGIN_GENERIC',
    'اگر این شماره در سامانه ثبت شده باشد، کد ورود برای آن پیامک می‌شود.');

/** مسیرهایی که نشست پورتال مراجع اجازهٔ دیدنشان را دارد */
function patient_portal_allowed_scripts()
{
    return array(
        'logout.php', 'login.php', 'patient_login.php', 'index.php', '403.php',
    );
}

/**
 * آیا درخواست جاری برای نشست پورتال مجاز است؟
 * مجاز = هر چیزی داخل پوشهٔ patient/ ، به‌علاوهٔ چند صفحهٔ عمومی.
 */
function patient_portal_path_allowed()
{
    $script = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '';
    $dir = basename(dirname($script));
    if ($dir === 'patient') {
        return true;
    }
    return in_array(basename($script), patient_portal_allowed_scripts(), true);
}

/** آیا نشست جاری، نشست پورتال مراجع است؟ */
function patient_session_is_portal()
{
    return !empty($_SESSION['patient_portal_only']);
}

/**
 * نگهبان صفحه‌های پوشهٔ patient/ .
 * نشست پرسنلی هرچقدر هم قدرتمند، اینجا راه ندارد: پورتال، جای مراجع است.
 */
function patient_require_portal_session($db)
{
    require_login();
    if (!patient_session_is_portal()) {
        http_response_code(403);
        require __DIR__ . '/../templates/403.php';
        exit;
    }
    auth_require_active_session($db);
    require_role(array(ROLE_PATIENT));
    return (int)$_SESSION['person_id'];
}

/* ───────────────────────── مرحلهٔ ۱: ارسال کد ───────────────────────── */

/**
 * آیا این شخص «مراجع» است؟ یعنی دست‌کم یک پذیرش به نامش ثبت شده.
 * بدون پذیرش، پورتال چیزی برای نشان دادن ندارد.
 */
function patient_person_is_eligible($db, $person_id)
{
    $row = db_select_one($db,
        "SELECT id FROM admissions WHERE patient_person_id = ? LIMIT 1",
        'i', array((int)$person_id));
    return $row !== null;
}

/**
 * آغاز ورود مراجع: در صورت واجد شرایط بودن، کد پیامک می‌شود.
 *
 * @return array('ok'=>bool,'message'=>string,'mode'=>string|null)
 *   ok=true یعنی «به مرحلهٔ کد برو»؛ حتی وقتی شماره ناشناس است، تا
 *   مهاجم نتواند فهرست شماره‌های کلینیک را بسازد.
 */
function patient_login_start($db, $mobile_raw)
{
    $mobile = normalize_mobile_number($mobile_raw);
    if ($mobile === null) {
        return array('ok' => false, 'message' => 'شمارهٔ موبایل معتبر نیست.', 'mode' => null);
    }

    $person = person_find_by_mobile($db, $mobile);
    $eligible = ($person && $person['status'] === 'ACTIVE'
        && patient_person_is_eligible($db, (int)$person['id']));

    if (!$eligible) {
        audit_log_write($db, null, null, 'PATIENT_LOGIN_FAILED', 'person', null,
            array('stage' => 'request', 'reason' => 'not_eligible'));
        /* پیام یکسان، بدون ارسال پیامک */
        return array('ok' => true, 'message' => PATIENT_LOGIN_GENERIC, 'mode' => null);
    }

    $sent = otp_create_and_send($db, $mobile, PATIENT_OTP_PURPOSE, (int)$person['id']);
    if (!$sent['ok']) {
        return array('ok' => false, 'message' => $sent['message'], 'mode' => $sent['mode']);
    }

    audit_log_write($db, (int)$person['id'], null, 'PATIENT_LOGIN_OTP_SENT', 'person',
        (int)$person['id'], array('mode' => $sent['mode']));

    return array('ok' => true, 'message' => PATIENT_LOGIN_GENERIC, 'mode' => $sent['mode']);
}

/* ───────────────────────── مرحلهٔ ۲: بررسی کد ───────────────────────── */

/**
 * بررسی کد و ساخت نشست پورتال.
 *
 * @return array('ok'=>bool,'message'=>string)
 */
function patient_login_verify($db, $mobile_raw, $code)
{
    $mobile = normalize_mobile_number($mobile_raw);
    if ($mobile === null) {
        return array('ok' => false, 'message' => 'شمارهٔ موبایل معتبر نیست.');
    }

    $verify = otp_verify($db, $mobile, PATIENT_OTP_PURPOSE, $code);
    if (!$verify['ok']) {
        audit_log_write($db, null, null, 'PATIENT_LOGIN_FAILED', 'person', null,
            array('stage' => 'verify'));
        return array('ok' => false, 'message' => $verify['message']);
    }

    $person = person_find_by_mobile($db, $mobile);
    if (!$person || $person['status'] !== 'ACTIVE'
        || !patient_person_is_eligible($db, (int)$person['id'])) {
        audit_log_write($db, null, null, 'PATIENT_LOGIN_FAILED', 'person', null,
            array('stage' => 'verify', 'reason' => 'not_eligible'));
        return array('ok' => false, 'message' => 'امکان ورود با این شماره وجود ندارد.');
    }

    $person_id = (int)$person['id'];
    $account = account_find_by_person_id($db, $person_id);

    /* حساب پرسنلی هرگز از این در وارد نمی‌شود (D4-3) */
    if ($account && $account['account_type'] !== 'PATIENT') {
        audit_log_write($db, $person_id, null, 'PATIENT_LOGIN_FAILED', 'account',
            (int)$account['id'], array('reason' => 'staff_account'));
        return array('ok' => false,
            'message' => 'این شماره به یک حساب پرسنلی تعلق دارد. '
                . 'لطفاً از صفحهٔ ورود پرسنل با نام کاربری و رمز عبور وارد شوید.');
    }

    if (!$account) {
        $account_id = patient_account_create($db, $person_id, $mobile);
        $account = account_find_by_person_id($db, $person_id);
        if (!$account) {
            return array('ok' => false,
                'message' => 'ساخت حساب مراجع ممکن نشد. لطفاً با کلینیک تماس بگیرید.');
        }
    }

    if ($account['status'] !== 'ACTIVE') {
        return array('ok' => false, 'message' => 'دسترسی این حساب غیرفعال شده است.');
    }

    if (!role_assignment_is_active($db, $person_id, ROLE_PATIENT)) {
        role_assignment_insert($db, $person_id, ROLE_PATIENT);
    }

    session_regenerate_id(true);
    $_SESSION['person_id'] = $person_id;
    $_SESSION['account_id'] = (int)$account['id'];
    $_SESSION['available_roles'] = array(ROLE_PATIENT);
    $_SESSION['active_role_code'] = ROLE_PATIENT;
    $_SESSION['display_name'] = $person['first_name'] . ' ' . $person['last_name'];
    $_SESSION['patient_portal_only'] = true;
    $_SESSION['last_activity_at'] = time();

    db_execute($db, "UPDATE accounts SET last_login_at = ? WHERE id = ?",
        'si', array(now_dt(), (int)$account['id']));

    audit_log_write($db, $person_id, ROLE_PATIENT, 'PATIENT_LOGIN_SUCCESS', 'account',
        (int)$account['id'], null);

    return array('ok' => true, 'message' => '');
}

/**
 * ساخت حساب مراجع در نخستین ورود موفق.
 * رمز عبور تصادفی و دورریختنی است؛ مراجع هرگز رمز ندارد و
 * must_change_password = 0 می‌ماند تا به صفحهٔ تغییر رمز پرتاب نشود.
 */
function patient_account_create($db, $person_id, $mobile)
{
    $identifier = $mobile;
    if (account_login_identifier_exists($db, $identifier)) {
        $identifier = $mobile . '-p' . $person_id;
    }
    $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
    $res = db_execute($db,
        "INSERT INTO accounts
           (public_id, person_id, login_identifier, password_hash, account_type,
            status, must_change_password, created_at)
         VALUES (?,?,?,?,'PATIENT','ACTIVE',0,?)",
        'sisss',
        array(generate_public_id('ac'), (int)$person_id, $identifier, $hash, now_dt()));

    audit_log_write($db, (int)$person_id, ROLE_PATIENT, 'PATIENT_ACCOUNT_CREATED',
        'account', (int)$res['insert_id'], null);

    return (int)$res['insert_id'];
}

/* ───────────────────────── دادهٔ پورتال ───────────────────────── */

/** نوبت‌های آیندهٔ مراجع — فقط اطلاعات خودش */
function patient_upcoming_appointments($db, $person_id, $limit = 10)
{
    $limit = (int)$limit;
    if ($limit < 1) { $limit = 1; }
    if ($limit > 50) { $limit = 50; }
    return db_select_all($db,
        "SELECT ap.public_id, ap.appointment_start_utc, ap.appointment_end_utc, ap.status,
                st.title AS service_title,
                tp.first_name AS therapist_first_name, tp.last_name AS therapist_last_name
           FROM appointments ap
           INNER JOIN admissions ad ON ad.id = ap.admission_id
           INNER JOIN service_types st ON st.id = ap.service_type_id
           INNER JOIN persons tp ON tp.id = ap.therapist_person_id
          WHERE ad.patient_person_id = ?
            AND ap.status = 'SCHEDULED'
            AND ap.appointment_start_utc >= ?
          ORDER BY ap.appointment_start_utc ASC
          LIMIT " . $limit,
        'is', array((int)$person_id, now_dt()));
}
