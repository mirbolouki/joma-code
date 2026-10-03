<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — ارتباط با درگاه پیامک (SMS.ir)
 *
 *  این فایل فقط «ارسال» را انجام می‌دهد؛ منطق کد یک‌بارمصرف در
 *  includes/otp_functions.php است. هیچ جدول صف پیامکی در فاز ۱ وجود ندارد.
 *
 *  سه حالت ممکن:
 *    smsir    → ارسال واقعی با سرویس «کد تأیید» SMS.ir
 *    dev      → کد فقط در storage/logs/sms_debug.log نوشته می‌شود
 *    disabled → هیچ پیامکی ارسال نمی‌شود (و موفقیت هم محسوب نمی‌شود)
 * ═══════════════════════════════════════════════════════════════════ */

/** تعیین حالت فعلی ارسال پیامک */
function sms_provider_mode()
{
    if (!defined('SMS_ENABLED') || SMS_ENABLED !== true) {
        return 'disabled';
    }
    if (defined('SMS_PROVIDER') && SMS_PROVIDER === 'smsir') {
        return 'smsir';
    }
    return 'dev';
}

/** آیا تنظیمات SMS.ir کامل است؟ */
function smsir_is_configured()
{
    return defined('SMSIR_API_KEY') && trim((string)SMSIR_API_KEY) !== ''
        && defined('SMSIR_OTP_TEMPLATE_ID') && trim((string)SMSIR_OTP_TEMPLATE_ID) !== '';
}

/** قالب موبایل موردپذیرش SMS.ir: 9xxxxxxxxx */
function smsir_format_mobile($mobile)
{
    $digits = preg_replace('/\D/', '', (string)$mobile);
    if (strlen($digits) === 11 && strpos($digits, '09') === 0) {
        return substr($digits, 1);
    }
    return $digits;
}

/** نوشتن یک سطر در فایل لاگ پیامک */
function sms_debug_log($line)
{
    @file_put_contents(
        __DIR__ . '/../storage/logs/sms_debug.log',
        gmdate('Y-m-d H:i:s') . ' UTC | ' . $line . "\n",
        FILE_APPEND
    );
}

/**
 * ارسال درخواست JSON به سرویس.
 * ابتدا با cURL؛ اگر cURL نبود، با file_get_contents.
 * @return array('ok','http_code','body','json')
 */
function sms_http_post_json($url, $api_key, $payload)
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $headers = array(
        'Content-Type: application/json',
        'Accept: application/json',
        'x-api-key: ' . $api_key,
    );

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $response = curl_exec($ch);
        $http_code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return array('ok' => false, 'http_code' => 0, 'body' => $curl_error, 'json' => null);
        }
    } else {
        $context = stream_context_create(array('http' => array(
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'timeout'       => 8,
            'ignore_errors' => true,
        )));
        $response = @file_get_contents($url, false, $context);
        $http_code = 0;
        if (isset($http_response_header[0])
            && preg_match('{HTTP/\S+\s+(\d{3})}', $http_response_header[0], $m)) {
            $http_code = (int)$m[1];
        }
        if ($response === false) {
            return array('ok' => false, 'http_code' => $http_code, 'body' => '', 'json' => null);
        }
    }

    $json = json_decode($response, true);
    $ok = ($http_code >= 200 && $http_code < 300);
    return array('ok' => $ok, 'http_code' => $http_code, 'body' => $response, 'json' => $json);
}

/**
 * ارسال کد تأیید.
 * ارسال فقط وقتی موفق محسوب می‌شود که پاسخ HTTP موفق باشد و status = 1 برگردد.
 *
 * @return array('ok' => bool, 'mode' => string, 'message' => string)
 */
function sms_send_otp_code($mobile, $code)
{
    $mode = sms_provider_mode();

    if ($mode === 'disabled') {
        return array(
            'ok' => false,
            'mode' => 'disabled',
            'message' => 'سرویس پیامک غیرفعال است. برای فعال‌سازی با مدیر سامانه تماس بگیرید.',
        );
    }

    if ($mode === 'dev') {
        /* فقط در فایل لاگ — هرگز روی صفحه نمایش داده نمی‌شود */
        sms_debug_log('OTP | TO: ' . $mobile . ' | CODE: ' . $code);
        return array('ok' => true, 'mode' => 'dev', 'message' => '');
    }

    if (!smsir_is_configured()) {
        return array(
            'ok' => false,
            'mode' => 'smsir',
            'message' => 'تنظیمات پیامک کامل نیست (کلید API یا شناسهٔ قالب ثبت نشده است).',
        );
    }

    $payload = array(
        'mobile'     => smsir_format_mobile($mobile),
        'templateId' => (int)SMSIR_OTP_TEMPLATE_ID,
        'parameters' => array(
            array('name' => 'CODE', 'value' => (string)$code),
        ),
    );

    $res = sms_http_post_json('https://api.sms.ir/v1/send/verify', SMSIR_API_KEY, $payload);

    $status = (is_array($res['json']) && isset($res['json']['status'])) ? $res['json']['status'] : null;
    if ($res['ok'] && (int)$status === 1) {
        /* در حالت واقعی، کد خام هرگز در هیچ لاگی نوشته نمی‌شود */
        sms_debug_log('OTP SENT (smsir) | TO: ' . $mobile . ' | HTTP: ' . $res['http_code']);
        return array('ok' => true, 'mode' => 'smsir', 'message' => '');
    }

    sms_debug_log('OTP FAILED (smsir) | TO: ' . $mobile .
        ' | HTTP: ' . $res['http_code'] . ' | RESPONSE: ' . substr((string)$res['body'], 0, 300));

    return array(
        'ok' => false,
        'mode' => 'smsir',
        'message' => 'ارسال پیامک ناموفق بود. لطفاً کمی بعد دوباره تلاش کنید.',
    );
}

/**
 * ارسال پیامک متنی ساده (برای آزمایش تنظیمات).
 * از سرویس ارسال انبوه SMS.ir استفاده می‌کند و به شمارهٔ خط نیاز دارد.
 */
function sms_send_text($mobile, $message)
{
    $mode = sms_provider_mode();

    if ($mode === 'disabled') {
        return array('ok' => false, 'mode' => 'disabled', 'message' => 'سرویس پیامک غیرفعال است.');
    }
    if ($mode === 'dev') {
        sms_debug_log('TEXT | TO: ' . $mobile . ' | MESSAGE: ' . $message);
        return array('ok' => true, 'mode' => 'dev', 'message' => '');
    }
    if (!smsir_is_configured()) {
        return array('ok' => false, 'mode' => 'smsir', 'message' => 'تنظیمات پیامک کامل نیست.');
    }
    $line = defined('SMSIR_LINE') ? preg_replace('/\D/', '', (string)SMSIR_LINE) : '';
    if ($line === '') {
        return array(
            'ok' => false,
            'mode' => 'smsir',
            'message' => 'ارسال پیامک متنی به شمارهٔ خط نیاز دارد؛ این مقدار در تنظیمات ثبت نشده است.',
        );
    }

    $payload = array(
        'lineNumber'   => (int)$line,
        'messageText'  => $message,
        'mobiles'      => array(smsir_format_mobile($mobile)),
        'sendDateTime' => null,
    );
    $res = sms_http_post_json('https://api.sms.ir/v1/send/bulk', SMSIR_API_KEY, $payload);

    $status = (is_array($res['json']) && isset($res['json']['status'])) ? $res['json']['status'] : null;
    if ($res['ok'] && (int)$status === 1) {
        return array('ok' => true, 'mode' => 'smsir', 'message' => '');
    }

    sms_debug_log('TEXT FAILED (smsir) | TO: ' . $mobile .
        ' | HTTP: ' . $res['http_code'] . ' | RESPONSE: ' . substr((string)$res['body'], 0, 300));

    return array('ok' => false, 'mode' => 'smsir', 'message' => 'ارسال پیامک ناموفق بود.');
}
