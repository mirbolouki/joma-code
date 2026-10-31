<?php
/* WIP only. Review never changes legacy financial records or guesses centers. */
require_once __DIR__ . '/record_event.php';
require_once __DIR__ . '/reporting.php';
/** Hash a fixed, ordered set of stored financial fields. NULL is distinct from
 * zero; values are kept as strings so no precision is lost. */
function joma_v2_legacy_payment_snapshot($payment) {
    $fields=array('id','appointment_id','session_fee_rial','deposit_amount_rial','deposit_bank_account_id',
        'deposit_status','deposit_paid_at','balance_amount_rial','balance_bank_account_id','balance_status','balance_paid_at');
    $values=array();
    foreach ($fields as $field) {
        if (!array_key_exists($field,$payment)) throw new InvalidArgumentException('Incomplete legacy payment');
        $value=$payment[$field];
        if ($value!==null && !is_scalar($value)) throw new InvalidArgumentException('Invalid legacy value');
        $values[$field]=$value===null ? null : (string)$value;
    }
    return json_encode($values,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
function joma_v2_legacy_payment_fingerprint($payment) { return hash('sha256',joma_v2_legacy_payment_snapshot($payment)); }
/** A review is an append-only assertion for a specific payment version.
 * For each PAID component, center=null means explicitly verified no center,
 * not that the center is unknown. Deposit and balance may differ. Both stale form and mixed ledger are rejected. */
function joma_v2_review_legacy_center($store,$input,$actor) {
    if (!isset($actor['person_id'],$actor['role']) || $actor['role']!=='admin') throw new InvalidArgumentException('Only admin can review legacy data');
    joma_v2_positive_id($actor['person_id'],'Actor');
    $id=joma_v2_positive_id(isset($input['payment_id'])?$input['payment_id']:null,'Legacy payment');
    foreach (array('deposit_center_id','balance_center_id') as $field) {
        if (!array_key_exists($field,$input)) throw new InvalidArgumentException('Center selection required');
        $confirmed=str_replace('_id','_confirmed',$field);
        if (!isset($input[$confirmed]) || !is_bool($input[$confirmed])) throw new InvalidArgumentException('Explicit confirmation required');
        if ($input[$field]!==null) joma_v2_positive_id($input[$field],'Center');
    }
    $fingerprint=isset($input['fingerprint'])?$input['fingerprint']:null;
    if (!is_string($fingerprint) || !preg_match('/^[a-f0-9]{64}$/D',$fingerprint)) throw new InvalidArgumentException('Invalid fingerprint');
    $version=isset($input['expected_review_id'])?$input['expected_review_id']:null;
    if (!is_int($version) || $version<0) throw new InvalidArgumentException('Invalid review version');
    $reason=isset($input['reason'])&&is_string($input['reason'])?trim($input['reason']):'';
    if ($reason==='' || mb_strlen($reason,'UTF-8')>255) throw new InvalidArgumentException('Reason required');
    $store->begin();
    try {
        $payment=$store->lockPaymentAndWallet($id);
        if (!$payment) throw new InvalidArgumentException('Payment not found');
        if (!hash_equals(joma_v2_legacy_payment_fingerprint($payment),$fingerprint)) throw new InvalidArgumentException('Legacy payment changed; review it again');
        if ($store->hasNewHistory((int)$payment['appointment_id'])) throw new InvalidArgumentException('Mixed legacy and new ledger needs separate reconciliation');
        $latest=$store->latestReview($id);
        if (($latest?(int)$latest['id']:0)!==$version) throw new InvalidArgumentException('Review changed; reload');
        $centers=array();
        foreach (array('deposit','balance') as $field) {
            if (!in_array($payment[$field.'_status'],array('PAID','PENDING'),true)) throw new InvalidArgumentException('Invalid payment status');
            $value=$input[$field.'_center_id']; $confirmed=$input[$field.'_center_confirmed'];
            if ($payment[$field.'_status']==='PAID' && !$confirmed) throw new InvalidArgumentException('Paid component requires explicit center confirmation');
            if ($payment[$field.'_status']==='PAID') {
                $amount=$payment[$field.'_amount_rial']; $paidAt=$payment[$field.'_paid_at'];
                if ($amount===null || !preg_match('/^(0|[1-9][0-9]{0,17})$/D',(string)$amount) || $paidAt===null) throw new InvalidArgumentException('Paid component amount or date missing');
                joma_v2_report_time($paidAt);
            }
            if ($payment[$field.'_status']!=='PAID' && ($value!==null || $confirmed)) throw new InvalidArgumentException('Pending payment has no attribution yet');
            if ($value!==null) $centers[$value]=$value;
        }
        // Acquire center rows in ascending order.
        sort($centers,SORT_NUMERIC);
        foreach ($centers as $center) if (!$store->centerExists($center)) throw new InvalidArgumentException('Center not found');
        $result=$store->appendReview($id,$input['deposit_center_id'],$input['balance_center_id'],$fingerprint,joma_v2_legacy_payment_snapshot($payment),$reason,$actor['person_id']);
        $store->commit(); return $result;
    } catch (Throwable $ex) { $store->rollback(); throw $ex; }
}
/** Not verified if a payment was modified or if the ledgers overlap. */
function joma_v2_legacy_review_is_current($payment,$review,$has_new_history) {
    return is_array($review) && isset($review['payment_fingerprint']) && is_string($review['payment_fingerprint']) &&
        $has_new_history===false && hash_equals(joma_v2_legacy_payment_fingerprint($payment),$review['payment_fingerprint']);
}
/** Convert a reviewed row to percentage input. A stale/missing review stays
 * unverified and will be rejected by the percentage calculator. */
function joma_v2_legacy_review_percentage_record($payment,$review,$has_new_history) {
    if (!is_bool($has_new_history)) throw new InvalidArgumentException('Invalid history flag');
    $verified=joma_v2_legacy_review_is_current($payment,$review,$has_new_history);
    if (!isset($payment['id']) || !preg_match('/^[1-9][0-9]{0,17}$/D',(string)$payment['id'])) throw new InvalidArgumentException('Invalid legacy identifier');
    $out=array('id'=>(int)$payment['id'],'center_verified'=>$verified,'has_new_history'=>$has_new_history);
    joma_v2_positive_id($out['id'],'Legacy payment');
    foreach (array('deposit','balance') as $field) {
        $centerKey=$field.'_center_id';
        if ($verified && !array_key_exists($centerKey,$review)) throw new InvalidArgumentException('Incomplete review');
        $center=$verified ? $review[$centerKey] : null;
        if ($center!==null) {
            if (!preg_match('/^[1-9][0-9]{0,17}$/D',(string)$center)) throw new InvalidArgumentException('Invalid center');
            $center=(int)$center;
        }
        $out[$centerKey]=$center;
        $amount=$payment[$field.'_amount_rial'];
        if ($amount!==null) {
            if (!preg_match('/^(0|[1-9][0-9]{0,17})$/D',(string)$amount)) throw new InvalidArgumentException('Invalid legacy amount');
            $amount=(int)$amount;
        }
        $out[$field.'_amount_rial']=$amount;
        $out[$field.'_status']=$payment[$field.'_status'];
        $out[$field.'_paid_at']=$payment[$field.'_paid_at'];
    }
    return $out;
}
