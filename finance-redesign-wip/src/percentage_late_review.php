<?php
/* Experimental evidence only. This does NOT settle, adjust or unblock money. */
require_once __DIR__.'/record_event.php';
function joma_v2_review_percentage_late($store,$input,$actor) {
    if (!isset($actor['person_id'],$actor['role']) || $actor['role']!=='admin') throw new InvalidArgumentException('فقط مدیر می‌تواند بررسی ثبت کند.');
    joma_v2_positive_id($actor['person_id'],'Actor');
    $id=joma_v2_positive_id(isset($input['late_event_id'])?$input['late_event_id']:null,'Late event');
    $version=isset($input['expected_review_id'])?$input['expected_review_id']:null;
    if (!is_int($version) || $version<0) throw new InvalidArgumentException('نسخهٔ بررسی نامعتبر است.');
    $kind=isset($input['kind'])?$input['kind']:null;
    if (!in_array($kind,array('INVESTIGATING','AGREEMENT_NOTED'),true)) throw new InvalidArgumentException('نوع بررسی نامعتبر است.');
    $reason=isset($input['reason']) && is_string($input['reason']) ? trim($input['reason']) : '';
    if ($reason==='' || mb_strlen($reason,'UTF-8')>1000) throw new InvalidArgumentException('شرح بررسی الزامی است و باید حداکثر ۱۰۰۰ نویسه باشد.');
    $store->begin();
    try {
        $store->lockLedger();
        if (!$store->lockLateEvent($id)) throw new InvalidArgumentException('گردش دیرهنگام یافت نشد.');
        $latest=$store->latestReview($id);
        if (($latest?(int)$latest['id']:0)!==$version) throw new InvalidArgumentException('بررسی تغییر کرده است؛ صفحه را دوباره باز کنید.');
        $result=$store->appendReview($id,$kind,$reason,$actor['person_id']);
        $store->commit(); return $result;
    } catch (Throwable $ex) { $store->rollback(); throw $ex; }
}
