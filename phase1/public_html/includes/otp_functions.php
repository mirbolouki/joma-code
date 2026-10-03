<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — کد یک‌بارمصرف (OTP)
 *
 *  • طول کد: OTP_CODE_LENGTH (۶ رقم) — به‌صورت رشته، صفر ابتدایی حفظ می‌شود
 *  • اعتبار: OTP_TTL_SECONDS (۱۲۰ ثانیه)
 *  • حداکثر تلاش: OTP_MAX_ATTEMPTS (۵)
 *  • فاصلهٔ ارسال دوباره: OTP_RESEND_COOLDOWN (۹۰ ثانیه)
 *  • کد خام هرگز ذخیره نمی‌شود؛ فقط هش آن (bcrypt)
 *  • همهٔ عملیات مقید به «شمارهٔ موبایل + purpose» است
 * ═══════════════════════════════════════════════════════════════════ */

/** تولید کد تصادفی با طول تعیین‌شده (رشته — ممکن است با صفر شروع شود) */
function otp_generate_code()
{
    $length = defined('OTP_CODE_LENGTH') ? (int)OTP_CODE_LENGTH : 6;
    if ($length < 4) { $length = 4; }
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= (string)random_int(0, 9);
    }
    return $code;
}

/** آیا از آخرین ارسال، به‌اندازهٔ کافی زمان گذشته است؟ */
function otp_can_resend($db, $mobile, $purpose)
{
    $row = db_select_one(
        $db,
        "SELECT created_at FROM otp_codes
          WHERE mobile_number = ? AND purpose = ?
          ORDER BY id DESC LIMIT 1",
        'ss',
        array($mobile, $purpose)
    );
    if (!$row) {
        return true;
    }
    $cooldown = defined('OTP_RESEND_COOLDOWN') ? (int)OTP_RESEND_COOLDOWN : 90;
    return (time() - strtotime($row['created_at'] . ' UTC')) >= $cooldown;
}

/** ثانیه‌های باقی‌مانده تا امکان ارسال دوباره */
function otp_resend_wait_seconds($db, $mobile, $purpose)
{
    $row = db_select_one(
        $db,
        "SELECT created_at FROM otp_codes
          WHERE mobile_number = ? AND purpose = ?
          ORDER BY id DESC LIMIT 1",
        'ss',
        array($mobile, $purpose)
    );
    if (!$row) {
        return 0;
    }
    $cooldown = defined('OTP_RESEND_COOLDOWN') ? (int)OTP_RESEND_COOLDOWN : 90;
    $passed = time() - strtotime($row['created_at'] . ' UTC');
    $remaining = $cooldown - $passed;
    return $remaining > 0 ? $remaining : 0;
}

/**
 * محدودسازی تعداد درخواست کد برای یک شماره.
 * بیش از ۳ درخواست در ۱۰ دقیقه مسدود می‌شود.
 */
function otp_rate_limit_check($db, $mobile)
{
    $since = gmdate('Y-m-d H:i:s', time() - 600);
    $row = db_select_one(
        $db,
        "SELECT COUNT(*) AS cnt FROM otp_codes WHERE mobile_number = ? AND created_at > ?",
        'ss',
        array($mobile, $since)
    );
    $count = $row ? (int)$row['cnt'] : 0;
    if ($count >= 3) {
        return array(
            'ok' => false,
            'message' => 'تعداد درخواست‌های کد برای این شماره زیاد است. لطفاً ۱۰ دقیقه صبر نمایید.',
        );
    }
    return array('ok' => true, 'message' => '');
}

/**
 * ساخت کد، باطل‌کردن کدهای قبلی و ارسال پیامک.
 *
 * @return array('ok' => bool, 'message' => string, 'mode' => string)
 */
function otp_create_and_send($db, $mobile, $purpose, $actor_person_id = null)
{
    $limit = otp_rate_limit_check($db, $mobile);
    if (!$limit['ok']) {
        return array('ok' => false, 'message' => $limit['message'], 'mode' => sms_provider_mode());
    }
    if (!otp_can_resend($db, $mobile, $purpose)) {
        $wait = otp_resend_wait_seconds($db, $mobile, $purpose);
        return array(
            'ok' => false,
            'message' => 'برای ارسال دوبارهٔ کد، ' . to_persian_digits($wait) . ' ثانیهٔ دیگر صبر کنید.',
            'mode' => sms_provider_mode(),
        );
    }

    /* کدهای استفاده‌نشدهٔ قبلی برای همین شماره و همین کاربرد باطل می‌شوند */
    db_execute(
        $db,
        "UPDATE otp_codes SET consumed_at = ?
          WHERE mobile_number = ? AND purpose = ? AND consumed_at IS NULL",
        'sss',
        array(now_dt(), $mobile, $purpose)
    );

    $code = otp_generate_code();
    $code_hash = password_hash($code, PASSWORD_BCRYPT);
    $ttl = defined('OTP_TTL_SECONDS') ? (int)OTP_TTL_SECONDS : 120;

    db_execute(
        $db,
        "INSERT INTO otp_codes (mobile_number, code_hash, purpose, expires_at, attempts, created_at)
         VALUES (?,?,?,?,0,?)",
        'sssss',
        array($mobile, $code_hash, $purpose, utc_dt_offset($ttl), now_dt())
    );

    $send = sms_send_otp_code($mobile, $code);

    audit_log_write(
        $db, $actor_person_id, null,
        $send['ok'] ? 'OTP_SENT' : 'OTP_SEND_FAILED',
        'otp_code', null,
        array('purpose' => $purpose, 'mode' => $send['mode'])
    );

    if (!$send['ok']) {
        return array('ok' => false, 'message' => $send['message'], 'mode' => $send['mode']);
    }
    return array('ok' => true, 'message' => '', 'mode' => $send['mode']);
}

/**
 * بررسی و مصرف کد.
 * شمارندهٔ تلاش پیش از بررسی افزایش می‌یابد و مصرف کد اتمیک است
 * (شرط consumed_at IS NULL در همان UPDATE) تا یک کد دوبار استفاده نشود.
 */
function otp_verify($db, $mobile, $purpose, $submitted_code)
{
    $submitted_code = preg_replace('/\D/', '', to_latin_digits($submitted_code));
    $max_attempts = defined('OTP_MAX_ATTEMPTS') ? (int)OTP_MAX_ATTEMPTS : 5;

    $row = db_select_one(
        $db,
        "SELECT id, code_hash, expires_at, attempts FROM otp_codes
          WHERE mobile_number = ? AND purpose = ? AND consumed_at IS NULL
          ORDER BY id DESC LIMIT 1",
        'ss',
        array($mobile, $purpose)
    );

    if (!$row) {
        return array('ok' => false, 'message' => 'کد معتبری برای این شماره یافت نشد؛ لطفاً دوباره درخواست کد بدهید.');
    }
    if ((int)$row['attempts'] >= $max_attempts) {
        db_execute($db, "UPDATE otp_codes SET consumed_at = ? WHERE id = ?",
            'si', array(now_dt(), $row['id']));
        return array('ok' => false, 'message' => 'تعداد تلاش‌های مجاز به پایان رسید؛ کد جدید درخواست کنید.');
    }
    if (strtotime($row['expires_at'] . ' UTC') < time()) {
        return array('ok' => false, 'message' => 'کد تأیید منقضی شده است؛ کد جدید درخواست کنید.');
    }

    db_execute($db, "UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?", 'i', array($row['id']));

    if (!password_verify($submitted_code, $row['code_hash'])) {
        $remaining = $max_attempts - ((int)$row['attempts'] + 1);
        if ($remaining < 0) { $remaining = 0; }
        return array(
            'ok' => false,
            'message' => 'کد تأیید نادرست است. ' . to_persian_digits($remaining) . ' تلاش دیگر باقی مانده است.',
        );
    }

    /* مصرف اتمیک: فقط اگر هنوز مصرف نشده باشد */
    $res = db_execute(
        $db,
        "UPDATE otp_codes SET consumed_at = ? WHERE id = ? AND consumed_at IS NULL",
        'si',
        array(now_dt(), $row['id'])
    );
    if ((int)$res['affected'] !== 1) {
        return array('ok' => false, 'message' => 'این کد قبلاً استفاده شده است؛ کد جدید درخواست کنید.');
    }

    return array('ok' => true, 'message' => '');
}
