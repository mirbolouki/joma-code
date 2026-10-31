<?php
/* WIP: explicit one-time assignment, no guessing historical rates. */
require_once __DIR__ . '/record_event.php';
function joma_v2_assign_missing_rate($store, $wallet_id, $amount, $reason, $actor)
{
    if (!isset($actor['person_id'], $actor['role']) || $actor['role'] !== 'admin') throw new InvalidArgumentException('Only administrators can assign a missing rate');
    joma_v2_positive_id($actor['person_id'],'Actor');
    joma_v2_positive_id($wallet_id,'Wallet');
    joma_v2_rial($amount,true);
    if (!is_string($reason) || trim($reason) === '' || mb_strlen(trim($reason),'UTF-8') > 255) throw new InvalidArgumentException('Reason required');
    $store->begin();
    try {
        $wallets=$store->lockWallets(array($wallet_id));
        if (!isset($wallets[$wallet_id]) || $wallets[$wallet_id]['kind'] !== 'APPOINTMENT') throw new InvalidArgumentException('Appointment wallet not found');
        if ($wallets[$wallet_id]['rate_rial_per_minute'] !== null) throw new InvalidArgumentException('Rate is already set');
        $store->assignMissingRate($wallet_id,$amount,trim($reason),$actor['person_id']);
        $store->commit();
    } catch (Throwable $ex) { $store->rollback(); throw $ex; }
}
