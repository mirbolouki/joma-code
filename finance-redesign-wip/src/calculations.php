<?php
/* WIP: pure calculation helpers; not wired into the application or database.
 * All monetary inputs are integer rials. Never deploy this file on its own. */

function joma_finance_nonnegative_integer($value, $label)
{
    if (!is_int($value) || $value < 0) {
        throw new InvalidArgumentException($label . ' must be a non-negative integer');
    }
    return $value;
}

/** Select only an exact service/therapist match. No fallback to session prices. */
function joma_finance_rate_snapshot($rates, $service_id, $therapist_id)
{
    if (!is_int($service_id) || $service_id <= 0 || !is_int($therapist_id) || $therapist_id <= 0) {
        throw new InvalidArgumentException('Invalid service or therapist');
    }
    $selected = null;
    foreach ($rates as $rate) {
        if ($rate['service_id'] === $service_id && $rate['therapist_id'] === $therapist_id
            && $rate['active'] === true) {
            if ($selected !== null) {
                throw new InvalidArgumentException('Duplicate active rate');
            }
            joma_finance_nonnegative_integer($rate['rate_rial_per_minute'], 'Rate');
            $selected = array('service_id' => $service_id, 'therapist_id' => $therapist_id,
                'rate_rial_per_minute' => $rate['rate_rial_per_minute']);
        }
    }
    if ($selected === null) {
        throw new InvalidArgumentException('No rate configured for this service and therapist');
    }
    return $selected;
}

/** Actual duration is an explicitly recorded integer number of minutes. */
function joma_finance_session_charge($actual_minutes, $rate_rial_per_minute, $override_rial = null, $reason = '')
{
    joma_finance_nonnegative_integer($actual_minutes, 'Actual minutes');
    joma_finance_nonnegative_integer($rate_rial_per_minute, 'Rate');
    if ($actual_minutes === 0) {
        throw new InvalidArgumentException('Actual minutes must be positive');
    }
    if ($rate_rial_per_minute && $actual_minutes > intdiv(PHP_INT_MAX, $rate_rial_per_minute)) {
        throw new OverflowException('Session charge exceeds integer range');
    }
    $base = $actual_minutes * $rate_rial_per_minute;
    if ($override_rial !== null) {
        joma_finance_nonnegative_integer($override_rial, 'Override');
        if (!is_string($reason) || trim($reason) === '') {
            throw new InvalidArgumentException('Manual adjustment requires a reason');
        }
    }
    return array('actual_minutes' => $actual_minutes, 'rate_rial_per_minute' => $rate_rial_per_minute,
        'base_rial' => $base, 'final_rial' => $override_rial === null ? $base : $override_rial,
        'override_reason' => $override_rial === null ? null : trim($reason));
}

/** Receipts and refunds already allocated to ONE item, not all patient transactions. */
function joma_finance_item_balance($final_rial, $entries)
{
    if ($final_rial !== null) {
        joma_finance_nonnegative_integer($final_rial, 'Final charge');
    }
    $net = 0;
    foreach ($entries as $entry) {
        if (!isset($entry['kind'], $entry['amount_rial']) ||
            !in_array($entry['kind'], array('DEPOSIT', 'PAYMENT', 'REFUND'), true)) {
            throw new InvalidArgumentException('Invalid entry');
        }
        $amount = joma_finance_nonnegative_integer($entry['amount_rial'], 'Entry amount');
        if ($amount === 0) {
            throw new InvalidArgumentException('Entry amount must be positive');
        }
        if ($entry['kind'] === 'REFUND') {
            if ($amount > $net) {
                throw new InvalidArgumentException('Refund exceeds allocated receipts');
            }
            $net -= $amount;
        } else {
            if ($amount > PHP_INT_MAX - $net) {
                throw new OverflowException('Receipts exceed integer range');
            }
            $net += $amount;
        }
    }
    return array('net_receipts_rial' => $net,
        'due_rial' => $final_rial === null ? null : max(0, $final_rial - $net),
        'credit_rial' => $final_rial === null ? $net : max(0, $net - $final_rial));
}

/** Never silently fall back to reserved minutes when actual time is missing. */
function joma_finance_center_minutes($basis, $reserved_minutes, $actual_minutes)
{
    if ($basis === 'RESERVED') {
        return joma_finance_nonnegative_integer($reserved_minutes, 'Reserved minutes');
    }
    if ($basis === 'ACTUAL') {
        if ($actual_minutes === null) {
            throw new InvalidArgumentException('Actual duration is not recorded');
        }
        return joma_finance_nonnegative_integer($actual_minutes, 'Actual minutes');
    }
    throw new InvalidArgumentException('Center time basis is not configured');
}
