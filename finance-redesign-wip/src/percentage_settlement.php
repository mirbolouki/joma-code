<?php
/* Prototype only. Do not expose on a live site until late-event handling,
 * migration and end-to-end security/concurrency review are complete. */
require_once __DIR__.'/center_settlement.php';
require_once __DIR__.'/center_percentage.php';
function joma_v2_create_percentage_settlement($store,$center_id,$start,$end,$manual_rial,$reason,$actor) {
    if (!isset($actor['person_id'],$actor['role']) || $actor['role']!=='admin') throw new InvalidArgumentException('فقط مدیر می‌تواند تسویه ثبت کند.');
    joma_v2_positive_id($actor['person_id'],'Actor'); joma_v2_positive_id($center_id,'Center');
    joma_v2_center_date($start); joma_v2_center_date($end);
    if ($end<$start) throw new InvalidArgumentException('بازهٔ تسویه نامعتبر است.');
    if (!is_string($reason) || mb_strlen(trim($reason),'UTF-8')>255) throw new InvalidArgumentException('دلیل نامعتبر است.');
    if ($manual_rial!==null) joma_v2_rial($manual_rial,true);
    $store->begin();
    try {
        $store->lockLedger();
        $center=$store->lockCenter($center_id);
        if (!$center || $center['status']!=='ACTIVE' || $center['commission_model']!=='PERCENTAGE') throw new InvalidArgumentException('مرکز درصدی فعال نیست.');
        if ($store->overlappingSettlement($center_id,$start,$end)) throw new InvalidArgumentException('این بازه با تسویهٔ قبلی هم‌پوشانی دارد.');
        if ($store->hasUnresolvedLateEvents($center_id)) throw new InvalidArgumentException('گردش ثبت‌شده بعد از تسویهٔ قبلی نیازمند بررسی و اصلاح است.');
        $store->verifyPastLegacyItems($center_id);
        $items=$store->collectPercentageItems($center_id,$start,$end);
        if (!$items) throw new InvalidArgumentException('در این بازه گردش واجد شرایطی وجود ندارد.');
        $exact=joma_v2_center_percentage_exact($center['commission_percent'],$items);
        try { $final=joma_v2_center_percentage_final($exact,$manual_rial,$reason); }
        catch (InvalidArgumentException $e) {
            $refs=array();
            foreach (array_slice($exact['manual_items'],0,20) as $item) {
                $kind=$item['kind']==='REFUND'?'استرداد':'انتقال بین نوبت‌ها';
                $refs[]=$kind.' شمارهٔ '.$item['source_id'].' به مبلغ '.$item['amount_rial'].' ریال';
            }
            $detail=$refs ? ' موارد: '.implode('، ',$refs).(count($exact['manual_items'])>20?' و موارد دیگر.':'.') : '';
            throw new InvalidArgumentException('مبنای دریافتی '.$exact['gross_rial'].' ریال؛ سهم دقیق '.$exact['whole_rial'].' و '.$exact['remainder'].'/10000 ریال؛ '.count($exact['manual_items']).' مورد نیازمند بررسی دستی است.'.$detail.' مبلغ و دلیل توافق را وارد کنید.');
        }
        if ($final['amount_rial']>999999999999) throw new OverflowException('مبلغ تسویه بیش از ظرفیت جدول موجود است.');
        $id=$store->insertSettlement($center_id,$start,$end,$final['amount_rial'],$actor['person_id']);
        $store->insertPercentageDetails($id,$exact,$final['reason']);
        foreach ($items as $item) $store->insertPercentageItem($id,$item);
        $store->commit();
        return array('settlement_id'=>$id,'amount_due_rial'=>$final['amount_rial'],'exact'=>$exact);
    } catch (Throwable $ex) { $store->rollback(); throw $ex; }
}
