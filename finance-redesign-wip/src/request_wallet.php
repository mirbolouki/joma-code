<?php
/* WIP: The request wallet is created only for a real existing booking request.
 * A unique request_id and a locked booking request row prevent duplication. */
require_once __DIR__ . '/record_event.php';
function joma_v2_request_wallet($store, $request_id, $actor)
{
    if (!isset($actor['person_id'], $actor['role']) || !in_array($actor['role'], array('admin','secretary'), true)) {
        throw new InvalidArgumentException('Not authorised');
    }
    joma_v2_positive_id($actor['person_id'], 'Actor');
    joma_v2_positive_id($request_id, 'Request');
    $store->begin();
    try {
        if (!$store->lockRequest($request_id)) throw new InvalidArgumentException('Request not found');
        $wallet = $store->findRequestWallet($request_id);
        if ($wallet === null) $wallet = $store->insertRequestWallet($request_id);
        $store->commit();
        return $wallet;
    } catch (Throwable $ex) {
        $store->rollback();
        throw $ex;
    }
}
