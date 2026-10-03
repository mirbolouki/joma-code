<?php
/**
 * زیرساخت ارسال پیامک جوما — درگاه SMS.ir
 * برای OTP ورود و توسعه‌نویسی
 *
 * فایل: includes/sms.php
 *
 * ──────────────────────────────────────────────────────────────────
 * توجه: این فایل نسخهٔ *مرجع* است و مستقیماً در بستهٔ فاز ۱ اجرا
 * نمی‌شود. نسخهٔ نهایی به دو فایل تفکیک شده است:
 *   public_html/includes/sms.php            (فقط درگاه)
 *   public_html/includes/otp_functions.php  (فقط منطق OTP)
 * تفاوت‌های نسخهٔ نهایی در reference/README_REFERENCE.md فهرست شده است.
 * ──────────────────────────────────────────────────────────────────
 */

if (!defined('SMS_ENABLED'))        define('SMS_ENABLED', true);
if (!defined('SMS_PROVIDER'))       define('SMS_PROVIDER', 'dev');
if (!defined('SMSIR_API_KEY'))      define('SMSIR_API_KEY', '');
if (!defined('SMSIR_OTP_TEMPLATE_ID')) define('SMSIR_OTP_TEMPLATE_ID', '');
if (!defined('SMSIR_LINE'))         define('SMSIR_LINE', '');
if (!defined('OTP_TTL_SECONDS'))    define('OTP_TTL_SECONDS', 120);
if (!defined('OTP_MAX_ATTEMPTS'))   define('OTP_MAX_ATTEMPTS', 5);
if (!defined('OTP_RESEND_COOLDOWN')) define('OTP_RESEND_COOLDOWN', 90);
if (!defined('OTP_CODE_LENGTH'))    define('OTP_CODE_LENGTH', 6);

/**
 * POST با JSON به سرویس SMS.ir
 */
function sms_http_post_json($url, $api_key, $payload) {
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $headers = array(
        'Content-Type: application/json',
        'Accept: application/json',
        'x-api-key: ' . $api_key
    );

    $response_body = false;
    $http_code = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true
        ));
        $response_body = curl_exec($ch);
        if ($response_body === false) {
            error_log('SMS CURL Error: ' . curl_error($ch));
        }
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 8
            ),
            'ssl' => array('verify_peer' => true)
        ));
        $response_body = @file_get_contents($url, false, $context);
        if (isset($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#HTTP/[\d.]+\s+(\d+)#', $h, $m)) {
                    $http_code = (int)$m[1];
                }
            }
        }
    }

    if ($response_body === false) {
        return array('ok' => false, 'http_code' => $http_code, 'body' => '', 'json' => null);
    }

    $json = json_decode($response_body, true);
    return array(
        'ok' => true,
        'http_code' => $http_code,
        'body' => $response_body,
        'json' => is_array($json) ? $json : null
    );
}

/**
 * تبدیل 09xxxxxxxxx → 9xxxxxxxxx
 */
function smsir_format_mobile($mobile) {
    $mobile = preg_replace('/[^0-9]/', '', (string)$mobile);
    if (strlen($mobile) === 11 && substr($mobile, 0, 2) === '09') {
        return substr($mobile, 1);
    }
    return $mobile;
}

/**
 * تعیین سرویس مؤثر
 */
function sms_effective_provider() {
    if (!SMS_ENABLED) {
        return 'disabled';
    }
    if (SMS_PROVIDER === 'smsir' && trim(SMSIR_API_KEY) !== '') {
        return 'smsir';
    }
    return 'dev';
}

/**
 * محدودسازی نرخ OTP
 */
function otp_rate_limit_check($db, $mobile) {
    $row = db_select_one($db,
        "SELECT COUNT(*) AS cnt FROM otp_codes 
         WHERE mobile_number = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)",
        's', array($mobile)
    );
    $count = $row ? (int)$row['cnt'] : 0;

    if ($count >= 3) {
        return array('allowed' => false, 'message' => 'تعداد درخواست‌های کد برای این شماره زیاد است. لطفاً ۱۰ دقیقه صبر نمایید.');
    }
    return array('allowed' => true, 'message' => '');
}

/**
 * تولید و ارسال OTP
 */
function otp_generate_and_send($db, $mobile) {
    $rate = otp_rate_limit_check($db, $mobile);
    if (!$rate['allowed']) {
        return array('success' => false, 'message' => $rate['message'], 'dev_code' => null);
    }

    db_execute($db,
        "UPDATE otp_codes SET consumed_at = NOW() WHERE mobile_number = ? AND consumed_at IS NULL",
        's', array($mobile)
    );

    $code = '';
    for ($i = 0; $i < OTP_CODE_LENGTH; $i++) {
        $code .= (string)random_int(0, 9);
    }

    $code_hash = password_hash($code, PASSWORD_DEFAULT);
    $expires_at = date('Y-m-d H:i:s', time() + OTP_TTL_SECONDS);

    db_execute($db,
        "INSERT INTO otp_codes (mobile_number, code_hash, purpose, expires_at, attempts, created_at)
         VALUES (?, ?, 'RESET_PASSWORD', ?, 0, ?)",
        'ssss', array($mobile, $code_hash, $expires_at, now_dt())
    );

    $provider = sms_effective_provider();
    $dev_code = null;

    if ($provider === 'smsir' && trim(SMSIR_OTP_TEMPLATE_ID) !== '') {
        $res = sms_http_post_json(
            'https://api.sms.ir/v1/send/verify',
            SMSIR_API_KEY,
            array(
                'mobile' => smsir_format_mobile($mobile),
                'templateId' => (int)SMSIR_OTP_TEMPLATE_ID,
                'parameters' => array(
                    array('name' => 'CODE', 'value' => $code)
                )
            )
        );

        $sent = $res['ok'] && isset($res['json']['status']) && (int)$res['json']['status'] === 1;

        if (!$sent) {
            error_log('SMS_OTP_FAILED: HTTP ' . $res['http_code']);
            return array('success' => false, 'message' => 'خطا در ارسال کد تأیید. دوباره تلاش کنید.', 'dev_code' => null);
        }
    } else {
        @file_put_contents(__DIR__ . '/../storage/logs/sms_debug.log',
            date('Y-m-d H:i:s') . " TO:{$mobile} CODE:{$code}\n", FILE_APPEND);
        $dev_code = $code;
    }

    return array('success' => true, 'message' => 'کد تأیید به شماره شما پیامک شد.', 'dev_code' => $dev_code);
}

/**
 * راستی‌آزمایی کد
 */
function otp_verify($db, $mobile, $code) {
    $fa_digits = array('۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩');
    $en_digits = array('0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9');
    $code = str_replace($fa_digits, $en_digits, (string)$code);
    $code = preg_replace('/[^0-9]/', '', $code);

    if (strlen($code) === 0) {
        return array('ok' => false, 'message' => 'لطفاً کد تأیید را وارد نمایید.');
    }

    $row = db_select_one($db,
        "SELECT * FROM otp_codes 
         WHERE mobile_number = ? AND consumed_at IS NULL 
         ORDER BY created_at DESC LIMIT 1",
        's', array($mobile)
    );

    if (!$row) {
        return array('ok' => false, 'message' => 'کد فعالی برای این شماره یافت نشد.');
    }

    if (strtotime($row['expires_at']) < time()) {
        return array('ok' => false, 'message' => 'مهلت کد به پایان رسیده است. کد جدید درخواست کنید.');
    }

    if ((int)$row['attempts'] >= OTP_MAX_ATTEMPTS) {
        db_execute($db, "UPDATE otp_codes SET consumed_at = NOW() WHERE id = ?", 'i', array($row['id']));
        return array('ok' => false, 'message' => 'تلاش‌های مجاز تمام شد. کد جدید درخواست کنید.');
    }

    db_execute($db, "UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?", 'i', array($row['id']));

    if (!password_verify($code, $row['code_hash'])) {
        $remaining = OTP_MAX_ATTEMPTS - ((int)$row['attempts'] + 1);
        return array('ok' => false, 'message' => 'کد نادرست است. تلاش باقی‌مانده: ' . $remaining);
    }

    db_execute($db, "UPDATE otp_codes SET consumed_at = NOW() WHERE id = ?", 'i', array($row['id']));

    return array('ok' => true, 'message' => 'شماره‌تان تأیید شد.');
}
