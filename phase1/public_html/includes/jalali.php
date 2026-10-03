<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — ابزار تبدیل تاریخ میلادی به شمسی
 *  بدون هیچ وابستگی بیرونی (بدون Composer).
 *
 *  قاعدهٔ سامانه:
 *    • ذخیره در دیتابیس: همیشه میلادی و به وقت جهانی (UTC)
 *    • نمایش: تبدیل به منطقهٔ زمانی APP_TIMEZONE و سپس تقویم شمسی
 * ═══════════════════════════════════════════════════════════════════ */

/** تبدیل تاریخ میلادی به شمسی. خروجی: array(سال، ماه، روز) */
function gregorian_to_jalali($gy, $gm, $gd)
{
    $g_d_m = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100))
          + ((int)(($gy2 + 399) / 400)) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * ((int)($days / 12053)));
    $days %= 12053;
    $jy += 4 * ((int)($days / 1461));
    $days %= 1461;
    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + (int)($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + (int)(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return array($jy, $jm, $jd);
}

/** تبدیل تاریخ شمسی به میلادی. خروجی: array(سال، ماه، روز) */
function jalali_to_gregorian($jy, $jm, $jd)
{
    $jy += 1595;
    $days = -355668 + (365 * $jy) + (((int)($jy / 33)) * 8) + ((int)((($jy % 33) + 3) / 4)) + $jd
          + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy = 400 * ((int)($days / 146097));
    $days %= 146097;
    if ($days > 36524) {
        $gy += 100 * ((int)(--$days / 36524));
        $days %= 36524;
        if ($days >= 365) { $days++; }
    }
    $gy += 4 * ((int)($days / 1461));
    $days %= 1461;
    if ($days > 365) {
        $gy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $sal_a = array(0, 31, (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) ? 29 : 28,
                   31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
    for ($gm = 0; $gm < 13 && $gd > $sal_a[$gm]; $gm++) {
        $gd -= $sal_a[$gm];
    }
    return array($gy, $gm, $gd);
}

/** نام ماه شمسی */
function jalali_month_name($jm)
{
    $names = array(1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
                   'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند');
    return isset($names[$jm]) ? $names[$jm] : '';
}

/** نام روز هفته بر اساس برچسب انگلیسی (Saturday…) */
function jalali_weekday_name($english_day)
{
    $map = array('Saturday' => 'شنبه', 'Sunday' => 'یکشنبه', 'Monday' => 'دوشنبه',
                 'Tuesday' => 'سه‌شنبه', 'Wednesday' => 'چهارشنبه',
                 'Thursday' => 'پنجشنبه', 'Friday' => 'جمعه');
    return isset($map[$english_day]) ? $map[$english_day] : '';
}

/** تبدیل ارقام لاتین به فارسی (فقط برای نمایش) */
function to_persian_digits($text)
{
    $latin = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
    $persian = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
    return str_replace($latin, $persian, (string)$text);
}

/** تبدیل ارقام فارسی/عربی به لاتین (برای ورودی‌های کاربر) */
function to_latin_digits($text)
{
    $persian = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹');
    $arabic  = array('٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩');
    $latin   = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
    return str_replace($arabic, $latin, str_replace($persian, $latin, (string)$text));
}

/**
 * تبدیل یک تاریخ‌وساعت UTC از دیتابیس به رشتهٔ شمسی برای نمایش.
 *
 * @param string|null $utc_datetime قالب 'Y-m-d H:i:s' به وقت جهانی
 * @param string      $mode  'date' | 'datetime' | 'long'
 */
function jalali_display($utc_datetime, $mode = 'datetime')
{
    if ($utc_datetime === null || $utc_datetime === '' || $utc_datetime === '0000-00-00 00:00:00') {
        return '—';
    }
    try {
        $dt = new DateTime($utc_datetime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Tehran'));
    } catch (Exception $e) {
        return '—';
    }

    list($jy, $jm, $jd) = gregorian_to_jalali(
        (int)$dt->format('Y'), (int)$dt->format('n'), (int)$dt->format('j')
    );

    if ($mode === 'date') {
        $out = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    } elseif ($mode === 'long') {
        $out = jalali_weekday_name($dt->format('l')) . ' ' . $jd . ' ' . jalali_month_name($jm) . ' ' . $jy;
    } else {
        $out = sprintf('%04d/%02d/%02d — %s', $jy, $jm, $jd, $dt->format('H:i'));
    }
    return to_persian_digits($out);
}

/**
 * مرزهای «امروز» به وقت تهران، تبدیل‌شده به UTC.
 * خروجی: array('start' => 'Y-m-d H:i:s', 'end' => 'Y-m-d H:i:s') — هر دو UTC
 * بازه: از ۰۰:۰۰ امروز تهران تا پیش از ۰۰:۰۰ فردای تهران.
 */
function today_bounds_utc()
{
    $tz = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Tehran');
    $utc = new DateTimeZone('UTC');

    $start = new DateTime('now', $tz);
    $start->setTime(0, 0, 0);
    $end = clone $start;
    $end->modify('+1 day');

    $start->setTimezone($utc);
    $end->setTimezone($utc);

    return array('start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s'));
}

/** تاریخ امروز به شمسی و به‌صورت بلند (برای هدر داشبورد) */
function jalali_today_long()
{
    return jalali_display(gmdate('Y-m-d H:i:s'), 'long');
}
