<?php
/* Experimental in-memory ledger. Not a database implementation. Do not deploy.
 * All money is in integer rials; events must be committed atomically by any
 * future persistent implementation. No clinical information belongs here. */
require_once __DIR__ . '/calculations.php';

function joma_ledger_new($wallets)
{
    $state = array('wallets' => array(), 'event_ids' => array(), 'events' => array());
    foreach ($wallets as $id => $wallet) {
        if (!is_string($id) || $id === '' || !is_array($wallet) ||
            !isset($wallet['kind']) || !in_array($wallet['kind'], array('REQUEST', 'APPOINTMENT'), true)) {
            throw new InvalidArgumentException('Invalid wallet');
        }
        // Reconstructed state must start empty; existing events are replayed.
        $state['wallets'][$id] = array('kind' => $wallet['kind'], 'receipts' => 0, 'charge' => null);
    }
    return $state;
}

function joma_ledger_wallet($state, $id)
{
    if (!is_string($id) || !isset($state['wallets'][$id])) {
        throw new InvalidArgumentException('Unknown wallet');
    }
    return $state['wallets'][$id];
}

/** Apply an event to a copy; failures leave the caller's state unchanged.
 *  RECEIPT/REFUND change cash. TRANSFER is internal and never changes cash.
 *  CHARGE records or revises a session fee and is not a cash movement.
 */
function joma_ledger_apply($state, $event)
{
    if (!isset($event['id'], $event['type']) || !is_string($event['id']) ||
        trim($event['id']) === '' || strlen($event['id']) > 100 ||
        isset($state['event_ids'][$event['id']])) {
        throw new InvalidArgumentException('Invalid or duplicate event ID');
    }
    $type = $event['type'];
    if (!in_array($type, array('RECEIPT', 'REFUND', 'TRANSFER', 'CHARGE'), true)) {
        throw new InvalidArgumentException('Invalid event type');
    }
    if (!isset($event['wallet'], $event['amount']) || !is_int($event['amount']) || $event['amount'] < 0 ||
        ($type !== 'CHARGE' && $event['amount'] === 0)) {
        throw new InvalidArgumentException('Invalid event amount');
    }
    $wallet = joma_ledger_wallet($state, $event['wallet']);
    $amount = $event['amount'];
    if ($type === 'CHARGE') {
        if ($wallet['kind'] !== 'APPOINTMENT') {
            throw new InvalidArgumentException('A charge requires an appointment');
        }
        if ($wallet['charge'] !== null && (!isset($event['reason']) || !is_string($event['reason']) || trim($event['reason']) === '')) {
            throw new InvalidArgumentException('Changing a charge requires a reason');
        }
        $state['wallets'][$event['wallet']]['charge'] = $amount;
    } elseif ($type === 'RECEIPT') {
        if ($amount > PHP_INT_MAX - $wallet['receipts']) {
            throw new OverflowException('Receipt overflow');
        }
        $state['wallets'][$event['wallet']]['receipts'] += $amount;
    } elseif ($type === 'REFUND') {
        if (!isset($event['reason']) || !is_string($event['reason']) || trim($event['reason']) === '') {
            throw new InvalidArgumentException('Refund requires a reason');
        }
        if ($amount > $wallet['receipts']) {
            throw new InvalidArgumentException('Refund exceeds allocated receipts');
        }
        // A refund may create a debt on an invoiced appointment; it is explicit.
        $state['wallets'][$event['wallet']]['receipts'] -= $amount;
    } else {
        if (!isset($event['to']) || $event['to'] === $event['wallet']) {
            throw new InvalidArgumentException('Invalid transfer destination');
        }
        $destination = joma_ledger_wallet($state, $event['to']);
        $credit = max(0, $wallet['receipts'] - ($wallet['charge'] === null ? 0 : $wallet['charge']));
        if ($amount > $credit) {
            throw new InvalidArgumentException('Transfer exceeds unconsumed credit');
        }
        if ($amount > PHP_INT_MAX - $destination['receipts']) {
            throw new OverflowException('Transfer overflow');
        }
        if (!isset($event['reason']) || !is_string($event['reason']) || trim($event['reason']) === '') {
            throw new InvalidArgumentException('Transfer requires a reason');
        }
        $state['wallets'][$event['wallet']]['receipts'] -= $amount;
        $state['wallets'][$event['to']]['receipts'] += $amount;
    }
    $state['event_ids'][$event['id']] = true;
    $state['events'][] = $event;
    return $state;
}

function joma_ledger_balance($state, $wallet_id)
{
    $wallet = joma_ledger_wallet($state, $wallet_id);
    $charge = $wallet['charge'];
    $received = $wallet['receipts'];
    return array('received' => $received, 'charge' => $charge,
        'due' => $charge === null ? null : max(0, $charge - $received),
        'credit' => max(0, $received - ($charge === null ? 0 : $charge)));
}
