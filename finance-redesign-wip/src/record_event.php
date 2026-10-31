<?php
/* Work in progress, not production-ready. Storage must lock both wallets until
 * commit, provide a unique operation key, and roll back on any error. */
require_once __DIR__ . '/calculations.php';

function joma_v2_positive_id($value, $label)
{
    if (!is_int($value) || $value <= 0) {
        throw new InvalidArgumentException($label . ' must be a positive integer');
    }
    return $value;
}

function joma_v2_rial($value, $allow_zero = false)
{
    if (!is_int($value) || $value < 0 || (!$allow_zero && $value === 0) || $value > 999999999999999999) {
        throw new InvalidArgumentException('Invalid rial amount');
    }
    return $value;
}

/** Input is trusted only after complete server-side validation. $store must
 * implement begin, commit, rollback, operationExists, lockWallets, balance,
 * accountActive and insertEvent. Wallet records must be fetched from the DB. */
function joma_v2_record_event($store, $input, $actor)
{
    if (!isset($actor['person_id'], $actor['role']) ||
        !in_array($actor['role'], array('admin', 'secretary'), true)) {
        throw new InvalidArgumentException('Not authorised');
    }
    joma_v2_positive_id($actor['person_id'], 'Actor');
    if (!isset($input['key'], $input['type'], $input['wallet']) || !is_string($input['key']) ||
        !preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $input['key'])) {
        throw new InvalidArgumentException('Invalid operation key');
    }
    $type = $input['type'];
    if (!in_array($type, array('RECEIPT', 'REFUND', 'TRANSFER', 'CHARGE'), true)) {
        throw new InvalidArgumentException('Invalid event type');
    }
    $source = joma_v2_positive_id($input['wallet'], 'Wallet');
    $target = null;
    if ($type === 'TRANSFER') {
        $target = joma_v2_positive_id(isset($input['to']) ? $input['to'] : null, 'Destination');
        if ($source === $target) throw new InvalidArgumentException('Cannot transfer to same wallet');
    }
    $reason = isset($input['reason']) && is_string($input['reason']) ? trim($input['reason']) : '';
    if (strlen($reason) > 255) throw new InvalidArgumentException('Reason is too long');
    if (in_array($type, array('TRANSFER', 'REFUND'), true) && $reason === '') {
        throw new InvalidArgumentException('Reason is required');
    }
    $store->begin();
    try {
        if ($store->operationExists($input['key'])) throw new InvalidArgumentException('Duplicate operation');
        $ids = $target === null ? array($source) : array($source, $target);
        sort($ids, SORT_NUMERIC); // stable lock order, prevents transfer deadlocks
        $wallets = $store->lockWallets($ids);
        if (!isset($wallets[$source]) || ($target !== null && !isset($wallets[$target]))) {
            throw new InvalidArgumentException('Wallet not found');
        }
        $wallet = $wallets[$source];
        $balance = $store->balance($source);
        $event = array('operation_key' => $input['key'], 'event_type' => $type,
            'wallet_id' => $source, 'destination_wallet_id' => $target,
            'receipt_kind' => null, 'account_id' => null, 'actual_minutes' => null, 'actual_session_date' => null,
            'calculated_rial' => null, 'reason' => $reason === '' ? null : $reason,
            'recorded_by_person_id' => $actor['person_id'], 'recorded_by_role_code' => $actor['role']);
        if ($type === 'CHARGE') {
            if ($wallet['kind'] !== 'APPOINTMENT' || $wallet['appointment_status'] !== 'COMPLETED') {
                throw new InvalidArgumentException('Only a completed appointment can be charged');
            }
            if ($wallet['rate_rial_per_minute'] === null) throw new InvalidArgumentException('No rate snapshot');
            if (!isset($input['actual_minutes']) || !is_int($input['actual_minutes']) || $input['actual_minutes'] <= 0 || $input['actual_minutes'] > 4294967295) {
                throw new InvalidArgumentException('Actual minutes are required');
            }
            $date = isset($input['actual_session_date']) ? $input['actual_session_date'] : null;
            if (!is_string($date) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D',$date,$parts) ||
                !checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1])) {
                throw new InvalidArgumentException('Actual session date is required');
            }
            $event['actual_session_date'] = $date;
            $override = array_key_exists('override_rial', $input) ? $input['override_rial'] : null;
            if ($balance['charge'] !== null && $reason === '') throw new InvalidArgumentException('Charge revision requires reason');
            $calculated = joma_finance_session_charge($input['actual_minutes'], $wallet['rate_rial_per_minute'], $override, $reason);
            joma_v2_rial($calculated['base_rial'], true);
            $event['actual_minutes'] = $input['actual_minutes'];
            $event['calculated_rial'] = $calculated['base_rial'];
            $event['amount_rial'] = joma_v2_rial($calculated['final_rial'], true);
        } else {
            $event['amount_rial'] = joma_v2_rial(isset($input['amount_rial']) ? $input['amount_rial'] : null);
            if ($type === 'RECEIPT' || $type === 'REFUND') {
                $account = joma_v2_positive_id(isset($input['account_id']) ? $input['account_id'] : null, 'Account');
                if (!$store->accountActive($account)) throw new InvalidArgumentException('Account not found or inactive');
                $event['account_id'] = $account;
                if ($type === 'RECEIPT') {
                    if (!isset($input['receipt_kind']) || !in_array($input['receipt_kind'], array('DEPOSIT', 'PAYMENT'), true)) {
                        throw new InvalidArgumentException('Receipt kind required');
                    }
                    $event['receipt_kind'] = $input['receipt_kind'];
                } elseif ($event['amount_rial'] > $balance['received']) {
                    throw new InvalidArgumentException('Refund exceeds receipts');
                }
            } elseif ($type === 'TRANSFER') {
                $dest = $wallets[$target];
                // Booking requests have no reliable patient identity until converted.
                // Transfer from a request is restricted to its converted admission.
                if ($wallet['kind'] === 'REQUEST') {
                    if ($dest['kind'] !== 'APPOINTMENT' || $wallet['admission_id'] === null ||
                        $wallet['admission_id'] !== $dest['admission_id']) {
                        throw new InvalidArgumentException('Request and appointment admission do not match');
                    }
                } elseif ($wallet['kind'] !== 'APPOINTMENT' || $dest['kind'] !== 'APPOINTMENT' ||
                    $wallet['patient_id'] === null || $wallet['patient_id'] !== $dest['patient_id']) {
                    throw new InvalidArgumentException('Transfer requires the same patient');
                }
                if ($event['amount_rial'] > max(0, $balance['received'] - ($balance['charge'] === null ? 0 : $balance['charge']))) {
                    throw new InvalidArgumentException('Transfer exceeds available credit');
                }
                $dest_balance = $store->balance($target);
                if ($event['amount_rial'] > 999999999999999999 - $dest_balance['received']) {
                    throw new OverflowException('Destination balance overflow');
                }
            }
            if ($type === 'RECEIPT' && $event['amount_rial'] > 999999999999999999 - $balance['received']) {
                throw new OverflowException('Balance overflow');
            }
        }
        $id = $store->insertEvent($event);
        $store->commit();
        return $id;
    } catch (Throwable $ex) {
        $store->rollback();
        throw $ex;
    }
}
