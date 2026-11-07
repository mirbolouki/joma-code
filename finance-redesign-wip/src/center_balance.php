<?php
/* Experimental only: signed adjustment and append-only center cash movements.
 * Not wired to any live payment page, and not an installer. */
require_once __DIR__.'/record_event.php';
require_once __DIR__.'/reporting.php';
function joma_v2_center_balance($principal,$adjustments,$payments) {
    joma_v2_rial($principal,true);
    $due=$principal; $paid=0;
    foreach ($adjustments as $delta) {
        if (!is_int($delta) || $delta < -999999999999999999 || $delta > 999999999999999999) throw new InvalidArgumentException('Invalid adjustment');
        $due=joma_v2_report_add($due,$delta);
        if ($due < -999999999999999999 || $due > 999999999999999999) throw new OverflowException('Settlement balance out of range');
    }
    foreach ($payments as $payment) {
        if (!is_array($payment) || !isset($payment['kind'],$payment['amount_rial']) || !in_array($payment['kind'],array('PAYMENT','RETURN'),true)) throw new InvalidArgumentException('Invalid center payment');
        joma_v2_rial($payment['amount_rial']);
        $paid=joma_v2_report_add($paid,$payment['kind']==='PAYMENT'?$payment['amount_rial']:-$payment['amount_rial']);
        if ($paid < -999999999999999999 || $paid > 999999999999999999) throw new OverflowException('Center cash out of range');
    }
    $balance=joma_v2_report_add($due,-$paid);
    if ($balance < -999999999999999999 || $balance > 999999999999999999) throw new OverflowException('Center balance out of range');
    return array('due_rial'=>$due,'paid_rial'=>$paid,'balance_rial'=>$balance,
        'payable_rial'=>max(0,$balance),'receivable_rial'=>max(0,-$balance));
}
function joma_v2_center_finance_authorize($input,$actor) {
    if (!isset($actor['person_id'],$actor['role']) || $actor['role']!=='admin') throw new InvalidArgumentException('فقط مدیر مجاز است.');
    joma_v2_positive_id($actor['person_id'],'Actor');
    if (!isset($input['key']) || !is_string($input['key']) || !preg_match('/^[A-Za-z0-9_-]{16,100}$/D',$input['key'])) throw new InvalidArgumentException('شناسهٔ عملیات نامعتبر است.');
    $reason=isset($input['reason']) && is_string($input['reason']) ? trim($input['reason']) : '';
    if ($reason==='' || mb_strlen($reason,'UTF-8')>255) throw new InvalidArgumentException('دلیل الزامی است.');
    return $reason;
}
/** Experimental manual resolution: exactly one signed agreed delta per flag.
 * 0 is explicit no-effect, not an assumption. Never infer the refund policy. */
function joma_v2_resolve_percentage_late($store,$input,$actor) {
    $reason=joma_v2_center_finance_authorize($input,$actor);
    $late=joma_v2_positive_id(isset($input['late_event_id'])?$input['late_event_id']:null,'Late event');
    $delta=isset($input['delta_rial'])?$input['delta_rial']:null;
    if (!is_int($delta) || $delta < -999999999999999999 || $delta > 999999999999999999) throw new InvalidArgumentException('مبلغ اصلاح نامعتبر است.');
    $store->begin();
    try {
        $store->lockLedger();
        $row=$store->lockLate($late);
        if (!$row) throw new InvalidArgumentException('گردش دیرهنگام یافت نشد.');
        $settlement=(int)$row['settlement_id'];
        $store->lockEligibleSettlement($settlement);
        if ($store->hasResolution($late)) throw new InvalidArgumentException('این گردش قبلاً تعیین تکلیف شده است.');
        if ($store->resolutionOperationExists($input['key'])) throw new InvalidArgumentException('عملیات تکراری است.');
        $state=$store->balance($settlement); $adjustments=$state['adjustments']; $adjustments[]=$delta;
        $balance=joma_v2_center_balance($state['principal'],$adjustments,$state['payments']);
        $id=$store->insertResolution($late,$input['key'],$delta,$reason,$actor['person_id']);
        $store->commit(); return array('id'=>$id,'balance'=>$balance);
    } catch (Throwable $ex) { $store->rollback(); throw $ex; }
}
/** A payment/return is a real separate cash entry; unresolved flags for any
 * settlement of the same center block it, including unclassified legacy flags.
 * A negative balance is a receivable and must not be hidden as zero. */
function joma_v2_record_center_cash($store,$input,$actor) {
    $reason=joma_v2_center_finance_authorize($input,$actor);
    $settlement=joma_v2_positive_id(isset($input['settlement_id'])?$input['settlement_id']:null,'Settlement');
    $account=joma_v2_positive_id(isset($input['account_id'])?$input['account_id']:null,'Account');
    $kind=isset($input['kind'])?$input['kind']:null;
    if (!in_array($kind,array('PAYMENT','RETURN'),true)) throw new InvalidArgumentException('نوع گردش نامعتبر است.');
    $amount=isset($input['amount_rial'])?$input['amount_rial']:null; joma_v2_rial($amount);
    $store->begin();
    try {
        $store->lockLedger(); $store->lockEligibleSettlement($settlement);
        if ($store->hasUnresolvedFlags($settlement)) throw new InvalidArgumentException('ابتدا گردش‌های دیرهنگام را تعیین تکلیف کنید.');
        if ($store->paymentOperationExists($input['key'])) throw new InvalidArgumentException('عملیات تکراری است.');
        if (!$store->accountActive($account)) throw new InvalidArgumentException('حساب فعال نیست.');
        $state=$store->balance($settlement);
        $balance=joma_v2_center_balance($state['principal'],$state['adjustments'],$state['payments']);
        $allowed=$kind==='PAYMENT'?$balance['payable_rial']:$balance['receivable_rial'];
        if ($amount>$allowed) throw new InvalidArgumentException('مبلغ بیش از ماندهٔ این جهت است.');
        $state['payments'][]=array('kind'=>$kind,'amount_rial'=>$amount);
        $balance=joma_v2_center_balance($state['principal'],$state['adjustments'],$state['payments']);
        $id=$store->insertPayment($settlement,$input['key'],$kind,$amount,$account,$reason,$actor['person_id']);
        $store->commit(); return array('id'=>$id,'balance'=>$balance);
    } catch (Throwable $ex) { $store->rollback(); throw $ex; }
}
