<?php
/* WIP: exact hourly room arithmetic, independent from settlement/reporting. */
require_once __DIR__ . '/calculations.php';

/**
 * $sessions must be explicitly selected/validated by the caller; this function
 * does not decide whether cancelled, planned or historical sessions are owed.
 * hourly_rate_rial is per hour. A rational remainder is returned as /60 rial,
 * never rounded, and missing actual time never falls back to scheduled time.
 */
function joma_v2_center_hourly_exact($basis, $sessions)
{
    $numerator = 0;
    $minutes_total = 0;
    foreach ($sessions as $session) {
        if (!is_array($session) || !array_key_exists('reserved_minutes', $session) ||
            !array_key_exists('actual_minutes', $session) || !array_key_exists('hourly_rate_rial', $session)) {
            throw new InvalidArgumentException('Incomplete session data');
        }
        $minutes = joma_finance_center_minutes($basis, $session['reserved_minutes'], $session['actual_minutes']);
        $rate = joma_finance_nonnegative_integer($session['hourly_rate_rial'], 'Hourly rate');
        if ($rate && $minutes > intdiv(PHP_INT_MAX - $numerator, $rate)) {
            throw new OverflowException('Hourly settlement exceeds integer range');
        }
        if ($minutes > PHP_INT_MAX - $minutes_total) throw new OverflowException('Duration overflow');
        $numerator += $minutes * $rate;
        $minutes_total += $minutes;
    }
    // Validate basis even for an empty set.
    if (!in_array($basis, array('ACTUAL', 'RESERVED'), true)) throw new InvalidArgumentException('Unknown basis');
    return array('minutes' => $minutes_total, 'whole_rial' => intdiv($numerator, 60),
        'remainder' => $numerator % 60, 'denominator' => 60);
}

/** Final amounts must be whole rials. A fractional result requires an explicit
 * manual amount and reason; no automatic rounding policy is silently imposed. */
function joma_v2_center_final_rial($exact, $manual_rial = null, $reason = '')
{
    if (!is_array($exact) || !isset($exact['whole_rial'], $exact['remainder'], $exact['denominator']) ||
        !is_int($exact['whole_rial']) || $exact['whole_rial'] < 0 || !is_int($exact['remainder']) ||
        $exact['remainder'] < 0 || $exact['remainder'] >= 60 || $exact['denominator'] !== 60) {
        throw new InvalidArgumentException('Invalid exact settlement');
    }
    if ($manual_rial !== null) {
        joma_finance_nonnegative_integer($manual_rial, 'Manual settlement');
        if (!is_string($reason) || trim($reason) === '') throw new InvalidArgumentException('Manual settlement reason required');
        return array('amount_rial' => $manual_rial, 'reason' => trim($reason));
    }
    if ($exact['remainder'] !== 0) throw new InvalidArgumentException('Fractional rial needs an explicit settlement decision');
    return array('amount_rial' => $exact['whole_rial'], 'reason' => null);
}
