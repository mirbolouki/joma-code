<?php
function joma_div($a, $b) {
    return (int) ($a / $b);
}

function gregorian_to_jalali($gy, $gm, $gd) {
    $g_d_m = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + joma_div($gy2 + 3, 4) - joma_div($gy2 + 99, 100) + joma_div($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * joma_div($days, 12053));
    $days %= 12053;
    $jy += 4 * joma_div($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += joma_div($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + joma_div($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + joma_div($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return array($jy, $jm, $jd);
}

function jalali_to_gregorian($jy, $jm, $jd) {
    $jy += 1595;
    $days = -355668 + (365 * $jy) + (joma_div($jy, 33) * 8) + joma_div(($jy % 33) + 3, 4) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy = 400 * joma_div($days, 146097);
    $days %= 146097;
    if ($days > 36524) {
        $gy += 100 * joma_div(--$days, 36524);
        $days %= 36524;
        if ($days >= 365) $days++;
    }
    $gy += 4 * joma_div($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy += joma_div($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $sal_a = array(0, 31, (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
    $gm = 0;
    while ($gm < 13 && $gd > $sal_a[$gm]) {
        $gd -= $sal_a[$gm];
        $gm++;
    }
    return array($gy, $gm, $gd);
}

function jalali_pad($n) {
    return str_pad((string) $n, 2, '0', STR_PAD_LEFT);
}

function jalali_today() {
    $p = gregorian_to_jalali((int) date('Y'), (int) date('n'), (int) date('j'));
    return $p[0] . '-' . jalali_pad($p[1]) . '-' . jalali_pad($p[2]);
}

function jalali_period_key($date) {
    return substr($date, 0, 7);
}

function jalali_is_leap($jy) {
    $breaks = array(-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178);
    $bl = count($breaks);
    $gy = $jy + 621;
    $leapJ = -14;
    $jp = $breaks[0];
    $jump = 0;
    for ($i = 1; $i < $bl; $i++) {
        $jm = $breaks[$i];
        $jump = $jm - $jp;
        if ($jy < $jm) break;
        $leapJ = $leapJ + joma_div($jump, 33) * 8 + joma_div(($jump % 33), 4);
        $jp = $jm;
    }
    $n = $jy - $jp;
    if ($jump - $n < 6) $n = $n - $jump + joma_div($jump + 4, 33) * 33;
    $leap = ((($n + 1) % 33) - 1) % 4;
    if ($leap === -1) $leap = 4;
    return $leap === 0;
}

function jalali_month_length($jy, $jm) {
    if ($jm <= 6) return 31;
    if ($jm <= 11) return 30;
    return jalali_is_leap($jy) ? 30 : 29;
}

function jalali_period_bounds($period_key) {
    $parts = explode('-', $period_key);
    $y = (int) $parts[0];
    $m = (int) $parts[1];
    $len = jalali_month_length($y, $m);
    return array(
        'year' => $y,
        'month' => $m,
        'start' => $y . '-' . jalali_pad($m) . '-01',
        'end' => $y . '-' . jalali_pad($m) . '-' . jalali_pad($len),
        'days' => $len,
    );
}

function jalali_in_period($date, $period_key) {
    $b = jalali_period_bounds($period_key);
    return ($date >= $b['start'] && $date <= $b['end']);
}

function jalali_week_start($date) {
    $p = explode('-', $date);
    $g = jalali_to_gregorian((int) $p[0], (int) $p[1], (int) $p[2]);
    $ts = mktime(0, 0, 0, $g[1], $g[2], $g[0]);
    $w = (int) date('w', $ts);
    $from_sat = ($w + 1) % 7;
    $ts -= $from_sat * 86400;
    $j = gregorian_to_jalali((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));
    return $j[0] . '-' . jalali_pad($j[1]) . '-' . jalali_pad($j[2]);
}

function jalali_same_week($a, $b) {
    return jalali_week_start($a) === jalali_week_start($b);
}

function jalali_month_name($m) {
    $n = array('', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند');
    return isset($n[(int) $m]) ? $n[(int) $m] : '';
}

function jalali_format($date) {
    $p = explode('-', $date);
    return ((int) $p[2]) . ' ' . jalali_month_name($p[1]) . ' ' . $p[0];
}

function jalali_period_label($key) {
    $p = explode('-', $key);
    return jalali_month_name($p[1]) . ' ' . $p[0];
}

function jalali_weekday_index($date) {
    $p = explode('-', $date);
    $g = jalali_to_gregorian((int) $p[0], (int) $p[1], (int) $p[2]);
    $ts = mktime(0, 0, 0, $g[1], $g[2], $g[0]);
    $w = (int) date('w', $ts);
    return ($w + 1) % 7;
}

function jalali_weekday_name($date) {
    $names = array('شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه');
    return $names[jalali_weekday_index($date)];
}

function jalali_is_valid($date) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    $p = explode('-', $date);
    $y = (int) $p[0];
    $m = (int) $p[1];
    $d = (int) $p[2];
    if ($m < 1 || $m > 12) return false;
    if ($d < 1 || $d > jalali_month_length($y, $m)) return false;
    return true;
}

function fa_num($v) {
    return strtr((string) $v, array('0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹'));
}
