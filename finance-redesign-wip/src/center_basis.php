<?php
/* WIP: configuration only. It is not connected to legacy settlements yet. */
require_once __DIR__ . '/record_event.php';
function joma_v2_set_center_basis($store,$center_id,$basis,$actor)
{
    if (!isset($actor['person_id'],$actor['role']) || $actor['role'] !== 'admin') throw new InvalidArgumentException('Only administrators can configure a center');
    joma_v2_positive_id($actor['person_id'],'Actor'); joma_v2_positive_id($center_id,'Center');
    if (!in_array($basis,array('ACTUAL','RESERVED'),true)) throw new InvalidArgumentException('Invalid center basis');
    $store->begin();
    try {
        if (!$store->lockActiveCenter($center_id)) throw new InvalidArgumentException('Center not active');
        $previous=$store->centerBasisForUpdate($center_id);
        if ($previous !== $basis) {
            $store->saveCenterBasis($center_id,$basis,$actor['person_id'],$previous);
            $store->appendCenterBasisEvent($center_id,$previous,$basis,$actor['person_id']);
        }
        $store->commit();
    } catch (Throwable $ex) { $store->rollback(); throw $ex; }
}
