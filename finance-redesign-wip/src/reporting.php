<?php
/* WIP, NOT A DEPLOYABLE REPORT. New-ledger only: no legacy transactions,
 * test/package sales or center payments. Events must be supplied in timestamp
 * and then database-ID order. All monetary values are integer rials. */
require_once __DIR__ . '/calculations.php';

function joma_v2_report_time($value, $date_only = false)
{
    $pattern = $date_only ? '/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D'
        : '/^([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2})$/D';
    if (!is_string($value) || !preg_match($pattern, $value, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ||
        (!$date_only && ((int)$m[4] > 23 || (int)$m[5] > 59 || (int)$m[6] > 59))) {
        throw new InvalidArgumentException('Invalid report date or time');
    }
    return $value;
}

function joma_v2_report_add($current, $delta)
{
    if (!is_int($current) || !is_int($delta)) throw new InvalidArgumentException('Non-integer report amount');
    if (($delta > 0 && $current > PHP_INT_MAX - $delta) ||
        ($delta < 0 && $current < PHP_INT_MIN - $delta)) throw new OverflowException('Report total overflow');
    return $current + $delta;
}

/**
 * Inputs: wallets keyed by string ('w1'), each with kind REQUEST/APPOINTMENT;
 * events in (occurred_at,id) order, fields id, type, wallet, amount, occurred_at,
 * and 'to' for transfers; expenses use date in local Gregorian calendar.
 * start/end times are UTC, inclusive/exclusive. Expense dates are separately
 * supplied as local Gregorian inclusive/exclusive bounds by the caller.
 * Existing-session fees: report CHARGE changes at the time they are recorded,
 * not at the appointment's scheduled date. Separately, final charges at cutoff
 * can be grouped by scheduled_at, which is NOT the actual treatment date.
 * Closing balances are as of end; expense dates reflect records supplied now.
 */
function joma_v2_report_build($wallets, $events, $expenses, $start_utc, $end_utc, $start_local, $end_local)
{
    joma_v2_report_time($start_utc); joma_v2_report_time($end_utc);
    joma_v2_report_time($start_local, true); joma_v2_report_time($end_local, true);
    if ($start_utc >= $end_utc || $start_local >= $end_local) throw new InvalidArgumentException('Invalid report range');
    $balances = array();
    foreach ($wallets as $key => $wallet) {
        if (!is_string($key) || $key === '' || !isset($wallet['kind']) || !in_array($wallet['kind'], array('REQUEST','APPOINTMENT'), true)) {
            throw new InvalidArgumentException('Invalid report wallet');
        }
        $scheduled = isset($wallet['scheduled_at']) ? joma_v2_report_time($wallet['scheduled_at']) : null;
        if ($wallet['kind'] === 'REQUEST' && $scheduled !== null) throw new InvalidArgumentException('Request cannot have an appointment date');
        $balances[$key] = array('kind'=>$wallet['kind'], 'received'=>0, 'charge'=>null, 'scheduled_at'=>$scheduled);
    }
    $result = array('cash_received_rial'=>0, 'cash_refunded_rial'=>0, 'net_cash_rial'=>0,
        'session_charge_changes_rial'=>0, 'scheduled_session_charges_rial'=>0, 'undated_invoiced_count'=>0, 'expenses_rial'=>0,
        'request_credit_rial'=>0, 'appointment_credit_rial'=>0, 'appointment_due_rial'=>0);
    $seen = array(); $previous_time = null; $previous_id = 0;
    foreach ($events as $event) {
        if (!is_array($event) || !isset($event['id'], $event['type'], $event['wallet'], $event['amount'], $event['occurred_at']) ||
            !is_int($event['id']) || $event['id'] <= 0 || !is_string($event['wallet']) ||
            !isset($balances[$event['wallet']]) || !is_int($event['amount']) || $event['amount'] < 0 ||
            !in_array($event['type'], array('RECEIPT','REFUND','TRANSFER','CHARGE'), true) ||
            ($event['type'] !== 'CHARGE' && $event['amount'] === 0)) {
            throw new InvalidArgumentException('Invalid report event');
        }
        $time = joma_v2_report_time($event['occurred_at']);
        if (isset($seen[$event['id']]) || ($previous_time !== null && ($time < $previous_time || ($time === $previous_time && $event['id'] <= $previous_id)))) {
            throw new InvalidArgumentException('Duplicate or unsorted report event');
        }
        $seen[$event['id']] = true; $previous_time = $time; $previous_id = $event['id'];
        if ($time >= $end_utc) continue;
        $in_period = ($time >= $start_utc);
        $id = $event['wallet']; $amount = $event['amount']; $type = $event['type'];
        if ($type === 'RECEIPT') {
            $balances[$id]['received'] = joma_v2_report_add($balances[$id]['received'], $amount);
            if ($in_period) $result['cash_received_rial'] = joma_v2_report_add($result['cash_received_rial'], $amount);
        } elseif ($type === 'REFUND') {
            if ($amount > $balances[$id]['received']) throw new InvalidArgumentException('Negative wallet balance in history');
            $balances[$id]['received'] -= $amount;
            if ($in_period) $result['cash_refunded_rial'] = joma_v2_report_add($result['cash_refunded_rial'], $amount);
        } elseif ($type === 'TRANSFER') {
            if (!isset($event['to']) || !is_string($event['to']) || $event['to'] === $id || !isset($balances[$event['to']])) {
                throw new InvalidArgumentException('Invalid transfer in history');
            }
            $charge = $balances[$id]['charge'];
            $credit = max(0, $balances[$id]['received'] - ($charge === null ? 0 : $charge));
            if ($amount > $credit) throw new InvalidArgumentException('Transfer exceeds historical credit');
            $balances[$id]['received'] -= $amount;
            $balances[$event['to']]['received'] = joma_v2_report_add($balances[$event['to']]['received'], $amount);
        } else {
            if ($balances[$id]['kind'] !== 'APPOINTMENT') throw new InvalidArgumentException('Charge on request');
            $old = $balances[$id]['charge'] === null ? 0 : $balances[$id]['charge'];
            $balances[$id]['charge'] = $amount;
            if ($in_period) $result['session_charge_changes_rial'] = joma_v2_report_add($result['session_charge_changes_rial'], $amount - $old);
        }
    }
    $expense_ids = array();
    foreach ($expenses as $expense) {
        if (!is_array($expense) || !isset($expense['id'], $expense['date'], $expense['amount']) ||
            !is_int($expense['id']) || $expense['id'] <= 0 || isset($expense_ids[$expense['id']]) ||
            !is_int($expense['amount']) || $expense['amount'] <= 0) throw new InvalidArgumentException('Invalid report expense');
        $expense_ids[$expense['id']] = true;
        $date = joma_v2_report_time($expense['date'], true);
        if ($date >= $start_local && $date < $end_local) $result['expenses_rial'] = joma_v2_report_add($result['expenses_rial'], $expense['amount']);
    }
    $result['net_cash_rial'] = joma_v2_report_add($result['cash_received_rial'], -$result['cash_refunded_rial']);
    foreach ($balances as $balance) {
        $charge = $balance['charge'] === null ? 0 : $balance['charge'];
        $credit = max(0, $balance['received'] - $charge);
        if ($balance['kind'] === 'REQUEST') {
            $result['request_credit_rial'] = joma_v2_report_add($result['request_credit_rial'], $credit);
        } else {
            // The current schema stores scheduled start, not the actual date.
            // Never silently call that an actual treatment date.
            if ($balance['charge'] !== null) {
                if ($balance['scheduled_at'] === null) {
                    $result['undated_invoiced_count'] = joma_v2_report_add($result['undated_invoiced_count'], 1);
                } elseif ($balance['scheduled_at'] >= $start_utc && $balance['scheduled_at'] < $end_utc) {
                    $result['scheduled_session_charges_rial'] = joma_v2_report_add($result['scheduled_session_charges_rial'], $balance['charge']);
                }
            }
            $result['appointment_credit_rial'] = joma_v2_report_add($result['appointment_credit_rial'], $credit);
            if ($balance['charge'] !== null) $result['appointment_due_rial'] = joma_v2_report_add($result['appointment_due_rial'], max(0, $charge - $balance['received']));
        }
    }
    return $result;
}
