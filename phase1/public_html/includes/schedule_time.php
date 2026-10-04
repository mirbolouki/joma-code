<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما فاز ۲ — ابزار زمان تقویم
 *
 *  قاعدهٔ طلایی (ادامهٔ فاز ۱):
 *    • هر لحظهٔ زمانی در دیتابیس به وقت جهانی (UTC) ذخیره می‌شود.
 *    • برنامهٔ کاری کلینیک (ساعت دیواری) به وقت تهران تفسیر می‌شود.
 *  این فایل، پل بین این دو است.
 * ═══════════════════════════════════════════════════════════════════ */

if (!defined('CLINIC_DAY_START_HOUR')) { define('CLINIC_DAY_START_HOUR', 9); }
if (!defined('CLINIC_DAY_END_HOUR'))   { define('CLINIC_DAY_END_HOUR', 20); }
if (!defined('HOLD_HORIZON_DAYS'))     { define('HOLD_HORIZON_DAYS', 30); }
if (!defined('CANCEL_LOCK_MINUTES'))   { define('CANCEL_LOCK_MINUTES', 30); }
if (!defined('SLOT_STEP_MINUTES'))     { define('SLOT_STEP_MINUTES', 15); }

/** منطقهٔ زمانی کلینیک */
function clinic_tz()
{
    static $tz = null;
    if ($tz === null) {
        $tz = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Tehran');
    }
    return $tz;
}

/** «Y-m-d H:i:s» محلی (تهران) → «Y-m-d H:i:s» جهانی (UTC) */
function local_to_utc($local_datetime)
{
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $local_datetime, clinic_tz());
    if (!$dt) {
        $dt = DateTime::createFromFormat('Y-m-d H:i', $local_datetime, clinic_tz());
    }
    if (!$dt) {
        throw new Exception('قالب تاریخ و ساعت نامعتبر است.');
    }
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

/** «Y-m-d H:i:s» جهانی (UTC) → «Y-m-d H:i:s» محلی (تهران) */
function utc_to_local($utc_datetime)
{
    if ($utc_datetime === null || $utc_datetime === '') {
        return '';
    }
    $dt = new DateTime($utc_datetime, new DateTimeZone('UTC'));
    $dt->setTimezone(clinic_tz());
    return $dt->format('Y-m-d H:i:s');
}

/** افزودن دقیقه به یک زمان UTC */
function utc_add_minutes($utc_datetime, $minutes)
{
    $dt = new DateTime($utc_datetime, new DateTimeZone('UTC'));
    $dt->modify('+' . (int)$minutes . ' minutes');
    return $dt->format('Y-m-d H:i:s');
}

/** تاریخ امروز به وقت تهران، قالب Y-m-d */
function clinic_today()
{
    $dt = new DateTime('now', clinic_tz());
    return $dt->format('Y-m-d');
}

/** ساعت و دقیقهٔ کنونی تهران، قالب H:i */
function clinic_now_time()
{
    $dt = new DateTime('now', clinic_tz());
    return $dt->format('H:i');
}

/** مرز شروع و پایان یک روز محلی، به UTC: array(start_utc, end_utc) */
function local_day_bounds_utc($local_date)
{
    return array(
        local_to_utc($local_date . ' 00:00:00'),
        local_to_utc($local_date . ' 23:59:59'),
    );
}

/** نمایش ساعت به فارسی، مثلاً ۱۴:۳۰ */
function time_display($time_or_datetime)
{
    if ($time_or_datetime === null || $time_or_datetime === '') {
        return '—';
    }
    $s = (string)$time_or_datetime;
    if (strlen($s) > 8) {
        $s = substr($s, 11);
    }
    return to_persian_digits(substr($s, 0, 5));
}

/** نمایش کامل «۱۴۰۵/۰۸/۰۵ — ۱۰:۳۰» از یک زمان UTC */
function appointment_display($utc_datetime)
{
    if (!$utc_datetime) {
        return '—';
    }
    $local = utc_to_local($utc_datetime);
    return jalali_display($utc_datetime, 'date') . ' — ' . time_display($local);
}

/** اعتبارسنجی «HH:MM» و بازگرداندن قالب یکدست H:i (یا null) */
function parse_clock($value)
{
    $value = trim(to_latin_digits((string)$value));
    if (!preg_match('/^([0-1]?[0-9]|2[0-3]):([0-5][0-9])$/', $value, $m)) {
        return null;
    }
    return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
}

/** «HH:MM» → تعداد دقیقه از نیمه‌شب */
function clock_to_minutes($clock)
{
    $parts = explode(':', $clock);
    return ((int)$parts[0]) * 60 + (int)$parts[1];
}

/** تعداد دقیقه از نیمه‌شب → «HH:MM» */
function minutes_to_clock($minutes)
{
    $minutes = max(0, (int)$minutes);
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

/** آیا بازهٔ ساعت محلی درون ساعات کاری کلینیک است؟ */
function within_working_hours($start_clock, $end_clock)
{
    $s = clock_to_minutes($start_clock);
    $e = clock_to_minutes($end_clock);
    return ($s >= CLINIC_DAY_START_HOUR * 60 && $e <= CLINIC_DAY_END_HOUR * 60 && $e > $s);
}

/**
 * تاریخ شمسی «۱۴۰۵/۰۸/۰۵» یا «1405-08-05» → میلادی «Y-m-d».
 * در صورت نامعتبر بودن null برمی‌گرداند.
 */
function jalali_input_to_gregorian($value)
{
    $value = trim(to_latin_digits((string)$value));
    $value = str_replace(array('-', '.'), '/', $value);
    if (!preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $value, $m)) {
        return null;
    }
    $jy = (int)$m[1];
    $jm = (int)$m[2];
    $jd = (int)$m[3];
    if ($jy < 1300 || $jy > 1500 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) {
        return null;
    }
    $g = jalali_to_gregorian($jy, $jm, $jd);
    if (!$g || !checkdate($g[1], $g[2], $g[0])) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $g[0], $g[1], $g[2]);
}

/** میلادی «Y-m-d» → شمسی «۱۴۰۵/۰۸/۰۵» برای نمایش در فرم */
function gregorian_to_jalali_input($date)
{
    if (!$date) {
        return '';
    }
    $p = explode('-', substr($date, 0, 10));
    if (count($p) !== 3) {
        return '';
    }
    $j = gregorian_to_jalali((int)$p[0], (int)$p[1], (int)$p[2]);
    return to_persian_digits(sprintf('%04d/%02d/%02d', $j[0], $j[1], $j[2]));
}

/** افزودن روز به یک تاریخ میلادی Y-m-d */
function date_add_days($date, $days)
{
    $dt = new DateTime($date . ' 00:00:00', new DateTimeZone('UTC'));
    $dt->modify(($days >= 0 ? '+' : '') . (int)$days . ' days');
    return $dt->format('Y-m-d');
}

/** اختلاف روزها میان دو تاریخ میلادی (b - a) */
function date_diff_days($a, $b)
{
    $da = new DateTime($a . ' 00:00:00', new DateTimeZone('UTC'));
    $db = new DateTime($b . ' 00:00:00', new DateTimeZone('UTC'));
    return (int)$da->diff($db)->format('%r%a');
}
