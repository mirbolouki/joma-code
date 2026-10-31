<?php
/* WIP: new HOURLY settlements only. Percentage settlements intentionally fail
 * closed until the new/legacy receipt attribution is fully reconciled. */
require_once __DIR__ . '/center_hourly.php';
require_once __DIR__ . '/record_event.php';
function joma_v2_center_date($date) {
    if (!is_string($date) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D',$date,$m) || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) throw new InvalidArgumentException('تاریخ تسویه نامعتبر است.');
    return $date;
}
function joma_v2_create_hourly_settlement($store,$center_id,$start,$end,$manual_rial,$reason,$actor)
{
    if (!isset($actor['person_id'],$actor['role']) || $actor['role'] !== 'admin') throw new InvalidArgumentException('فقط مدیر می‌تواند تسویه ثبت کند.');
    joma_v2_positive_id($actor['person_id'],'Actor'); joma_v2_positive_id($center_id,'Center');
    joma_v2_center_date($start); joma_v2_center_date($end);
    if ($end < $start) throw new InvalidArgumentException('بازهٔ تسویه نامعتبر است.');
    if (!is_string($reason) || mb_strlen(trim($reason),'UTF-8') > 255) throw new InvalidArgumentException('دلیل نامعتبر است.');
    if ($manual_rial !== null) joma_v2_rial($manual_rial,true);
    $store->begin();
    try {
        // Every settlement for a center serializes on this center row. Old
        // periods, including partially paid ones, remain overlap barriers.
        $center=$store->lockCenter($center_id);
        if (!$center || $center['status'] !== 'ACTIVE') throw new InvalidArgumentException('مرکز فعال نیست.');
        if ($center['commission_model'] !== 'HOURLY') throw new InvalidArgumentException('تسویهٔ درصدی هنوز در مدل جدید فعال نشده است.');
        if ($store->overlappingSettlement($center_id,$start,$end)) throw new InvalidArgumentException('این بازه با یک تسویهٔ ثبت‌شده هم‌پوشانی دارد.');
        $basis=$store->centerBasis($center_id);
        if (!in_array($basis,array('ACTUAL','RESERVED'),true)) throw new InvalidArgumentException('ابتدا مبنای زمان این مرکز را تنظیم کنید.');
        $sessions=$store->lockSessions($center_id,$start,$end,$basis);
        if (count($sessions) === 0) throw new InvalidArgumentException('در این بازه نوبت واجد شرایطی وجود ندارد.');
        try { $exact=joma_v2_center_hourly_toman_exact($basis,$sessions); }
        catch (InvalidArgumentException $ex) { throw new InvalidArgumentException('مدت واقعی یا تعرفهٔ ساعتی یکی از نوبت‌ها ثبت نشده یا نامعتبر است.'); }
        try { $final=joma_v2_center_final_rial($exact,$manual_rial,$reason); }
        catch (InvalidArgumentException $ex) { throw new InvalidArgumentException('مبلغ دقیق: ' . $exact['whole_rial'] . ' ریال و ' . $exact['remainder'] . '/' . $exact['denominator'] . ' ریال؛ برای تعیین دستی مبلغ و دلیل را وارد کنید.'); }
        // Existing finance_center_settlements uses DECIMAL(12,0), not 18 digits.
        if ($final['amount_rial'] > 999999999999) throw new OverflowException('مبلغ تسویه بیش از ظرفیت جدول موجود است.');
        $id=$store->insertSettlement($center_id,$start,$end,$final['amount_rial'],$actor['person_id']);
        $store->insertSettlementDetails($id,$basis,$exact,$final['reason']);
        foreach ($sessions as $row) $store->insertSettlementItem($id,$basis,$row);
        $store->commit();
        return array('settlement_id'=>$id,'amount_due_rial'=>$final['amount_rial'],'exact'=>$exact,'basis'=>$basis);
    } catch (Throwable $ex) { $store->rollback(); throw $ex; }
}
