<?php
/* WIP: exact percentage settlement arithmetic. Data attribution is separate.
 * Request deposits contribute when assigned to an appointment, not when cash
 * was first received. Refunds and appointment-to-appointment transfers need an
 * explicit manual decision; no automatic reversal or rounding is applied. */
require_once __DIR__ . '/record_event.php';
function joma_v2_percent_basis_points($text) {
    if (!is_string($text) || !preg_match('/^([0-9]{1,3})(?:\.([0-9]{1,2}))?$/D',$text,$m)) throw new InvalidArgumentException('Invalid center percentage');
    $bp=(int)$m[1]*100+(isset($m[2]) ? (int)str_pad($m[2],2,'0') : 0);
    if ($bp > 10000) throw new InvalidArgumentException('Center percentage exceeds 100');
    return $bp;
}
/** Every item is a unique immutable source: DIRECT_RECEIPT and REQUEST_TRANSFER
 * add to the base; LEGACY_DEPOSIT/BALANCE use legacy paid timestamps. REFUND
 * and APPOINTMENT_TRANSFER must be considered manually, not silently netted. */
function joma_v2_center_percentage_exact($percent,$items) {
    $bp=joma_v2_percent_basis_points($percent); $gross=0; $manual=array(); $seen=array();
    foreach ($items as $item) {
        if (!is_array($item) || !isset($item['kind'],$item['source_id'],$item['amount_rial']) ||
            !in_array($item['kind'],array('DIRECT_RECEIPT','REQUEST_TRANSFER','LEGACY_DEPOSIT','LEGACY_BALANCE','REFUND','APPOINTMENT_TRANSFER'),true)) throw new InvalidArgumentException('Invalid percentage source');
        joma_v2_positive_id($item['source_id'],'Source'); joma_v2_rial($item['amount_rial']);
        $key=$item['kind'].':'.$item['source_id'];
        if (isset($seen[$key])) throw new InvalidArgumentException('Duplicate percentage source');
        $seen[$key]=true;
        if (in_array($item['kind'],array('REFUND','APPOINTMENT_TRANSFER'),true)) { $manual[]=$item; continue; }
        if ($item['amount_rial'] > 999999999999999999 - $gross) throw new OverflowException('Settlement base overflow');
        $gross += $item['amount_rial'];
    }
    // Split prior to multiplication to keep the computation exact in int64.
    $whole=intdiv($gross,10000)*$bp;
    $fraction=($gross % 10000)*$bp;
    $whole+=intdiv($fraction,10000);
    return array('gross_rial'=>$gross,'basis_points'=>$bp,'whole_rial'=>$whole,
        'remainder'=>$fraction%10000,'denominator'=>10000,'manual_items'=>$manual);
}
function joma_v2_center_percentage_final($exact,$manual_rial=null,$reason='') {
    if (!is_array($exact) || !isset($exact['whole_rial'],$exact['remainder'],$exact['manual_items']) || !is_string($reason)) throw new InvalidArgumentException('Invalid percentage result');
    if ($manual_rial !== null) {
        joma_v2_rial($manual_rial,true);
        if (trim($reason)==='' || mb_strlen(trim($reason),'UTF-8')>255) throw new InvalidArgumentException('Manual amount requires a reason');
        return array('amount_rial'=>$manual_rial,'reason'=>trim($reason));
    }
    if ($exact['remainder']!==0 || count($exact['manual_items'])>0) throw new InvalidArgumentException('Manual decision required');
    return array('amount_rial'=>$exact['whole_rial'],'reason'=>null);
}

/** Convert supplied, verified historic sources into inputs for the calculator.
 * Values are never guessed: legacy center attribution must be explicitly
 * verified, and overlapping old/new wallet histories require reconciliation.
 * Callers must supply ALL relevant events/legacy records, not a biased subset.
 * Bounds are UTC start-inclusive/end-exclusive; no database writes here. */
function joma_v2_percentage_sources($center,$start_utc,$end_utc,$events,$legacy) {
    require_once __DIR__ . '/reporting.php';
    joma_v2_positive_id($center,'Center'); joma_v2_report_time($start_utc); joma_v2_report_time($end_utc);
    if ($start_utc >= $end_utc) throw new InvalidArgumentException('Invalid period');
    $items=array(); $seen=array();
    foreach ($events as $ev) {
        if (!is_array($ev) || !isset($ev['id'],$ev['type'],$ev['source_kind'],$ev['amount_rial'],$ev['occurred_at']) ||
            !in_array($ev['source_kind'],array('REQUEST','APPOINTMENT'),true) || !in_array($ev['type'],array('RECEIPT','REFUND','TRANSFER','CHARGE'),true)) throw new InvalidArgumentException('Invalid event');
        joma_v2_positive_id($ev['id'],'Event');
        if (isset($seen[$ev['id']])) throw new InvalidArgumentException('Duplicate event'); $seen[$ev['id']]=true;
        $time=joma_v2_report_time($ev['occurred_at']);
        if ($time < $start_utc || $time >= $end_utc || $ev['type']==='CHARGE') continue;
        joma_v2_rial($ev['amount_rial']);
        if ($ev['type']==='TRANSFER' && (!isset($ev['destination_kind']) || $ev['destination_kind']!=='APPOINTMENT')) throw new InvalidArgumentException('Invalid transfer destination');
        if ($ev['source_kind']==='REQUEST' && $ev['type']!=='TRANSFER') continue;
        if (!isset($ev['center_snapshot_recorded']) || $ev['center_snapshot_recorded'] !== true ||
            !array_key_exists('source_center_id',$ev) || !array_key_exists('destination_center_id',$ev)) throw new InvalidArgumentException('Event center attribution needs reconciliation');
        $source=$ev['source_center_id']; $destination=$ev['destination_center_id'];
        if ($source !== null) joma_v2_positive_id($source,'Source center');
        if ($destination !== null) joma_v2_positive_id($destination,'Destination center');
        $kind=null;
        if ($ev['type']==='RECEIPT' && $ev['source_kind']==='APPOINTMENT' && $source===$center) $kind='DIRECT_RECEIPT';
        elseif ($ev['type']==='REFUND' && $ev['source_kind']==='APPOINTMENT' && $source===$center) $kind='REFUND';
        elseif ($ev['type']==='TRANSFER' && $ev['source_kind']==='REQUEST' && $destination===$center) $kind='REQUEST_TRANSFER';
        elseif ($ev['type']==='TRANSFER' && $ev['source_kind']==='APPOINTMENT' && ($source===$center || $destination===$center)) $kind='APPOINTMENT_TRANSFER';
        if ($kind!==null) $items[]=array('kind'=>$kind,'source_id'=>$ev['id'],'amount_rial'=>$ev['amount_rial'],'occurred_at'=>$time);
    }
    $seen=array();
    foreach ($legacy as $record) {
        if (!is_array($record) || !isset($record['id'],$record['center_verified'],$record['has_new_history']) ||
            !is_bool($record['center_verified']) || !is_bool($record['has_new_history']) || !array_key_exists('deposit_center_id',$record) || !array_key_exists('balance_center_id',$record)) throw new InvalidArgumentException('Invalid legacy record');
        joma_v2_positive_id($record['id'],'Legacy payment');
        if (isset($seen[$record['id']])) throw new InvalidArgumentException('Duplicate legacy payment'); $seen[$record['id']]=true;
        if (!$record['center_verified'] || $record['has_new_history']) throw new InvalidArgumentException('Legacy history needs reconciliation');
        foreach (array('deposit'=>'LEGACY_DEPOSIT','balance'=>'LEGACY_BALANCE') as $field=>$kind) {
            if (!isset($record[$field.'_status']) || !in_array($record[$field.'_status'],array('PAID','PENDING'),true)) throw new InvalidArgumentException('Invalid legacy status');
            if ($record[$field.'_status']!=='PAID') continue;
            $legacyCenter=$record[$field.'_center_id'];
            if ($legacyCenter!==null) joma_v2_positive_id($legacyCenter,'Legacy center');
            if ($legacyCenter!==$center) continue;
            if (!isset($record[$field.'_paid_at'])) throw new InvalidArgumentException('Legacy payment date missing');
            $time=joma_v2_report_time($record[$field.'_paid_at']);
            if ($time < $start_utc || $time >= $end_utc) continue;
            $amount=isset($record[$field.'_amount_rial']) ? $record[$field.'_amount_rial'] : null;
            if (!is_int($amount) || $amount<0) throw new InvalidArgumentException('Legacy paid amount unknown');
            if ($amount>0) { joma_v2_rial($amount); $items[]=array('kind'=>$kind,'source_id'=>$record['id'],'amount_rial'=>$amount,'occurred_at'=>$time); }
        }
    }
    return $items;
}
