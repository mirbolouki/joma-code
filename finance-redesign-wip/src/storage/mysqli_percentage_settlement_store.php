<?php
/* Experimental only. All reads and inserts belong to the caller transaction. */
require_once __DIR__.'/mysqli_center_settlement_store.php';
require_once __DIR__.'/mysqli_legacy_center_review_store.php';
require_once __DIR__.'/../legacy_center_review.php';
require_once __DIR__.'/../center_percentage.php';
class JomaV2MysqliPercentageSettlementStore extends JomaV2MysqliCenterSettlementStore {
    private $connection;
    function __construct($db) { parent::__construct($db); $this->connection=$db; }
    private function integer($v) {
        if (!preg_match('/^(0|[1-9][0-9]{0,17})$/D',(string)$v)) throw new UnexpectedValueException('Invalid stored amount');
        return (int)$v;
    }
    function collectPercentageItems($center,$start,$end) {
        $from=local_day_bounds_utc($start); $until=local_day_bounds_utc(date_add_days($end,1));
        $rows=db_select_all($this->connection,"SELECT ev.id,ev.event_type,ev.amount_rial,ev.occurred_at,ev.center_snapshot_recorded,ev.source_center_id,ev.destination_center_id,
            src.request_id AS source_request_id,src.appointment_id AS source_appointment_id,dst.id AS destination_id,dst.request_id AS destination_request_id,dst.appointment_id AS destination_appointment_id
            FROM finance_v2_events ev JOIN finance_v2_wallets src ON src.id=ev.wallet_id
            LEFT JOIN finance_v2_wallets dst ON dst.id=ev.destination_wallet_id
            WHERE ev.occurred_at>=? AND ev.occurred_at<? ORDER BY ev.id FOR UPDATE",'ss',array($from['start'],$until['start']));
        $events=array();
        foreach ($rows as $r) {
            if (($r['source_request_id']===null)===($r['source_appointment_id']===null) ||
                ($r['destination_id']!==null && (($r['destination_request_id']===null)===($r['destination_appointment_id']===null)))) throw new UnexpectedValueException('Invalid stored wallet identity');
            $events[]=array('id'=>$this->integer($r['id']),'type'=>$r['event_type'],
            'source_kind'=>$r['source_request_id']===null?'APPOINTMENT':'REQUEST',
            'destination_kind'=>$r['destination_appointment_id']===null?null:'APPOINTMENT',
            'amount_rial'=>$this->integer($r['amount_rial']),'occurred_at'=>$r['occurred_at'],
            'center_snapshot_recorded'=>(int)$r['center_snapshot_recorded']===1,
            'source_center_id'=>$r['source_center_id']===null?null:$this->integer($r['source_center_id']),
            'destination_center_id'=>$r['destination_center_id']===null?null:$this->integer($r['destination_center_id']));
        }
        $legacyStore=new JomaV2MysqliLegacyCenterReviewStore($this->connection);
        $ids=db_select_all($this->connection,'SELECT id FROM finance_session_payments ORDER BY id FOR UPDATE');
        $legacy=array();
        foreach ($ids as $r) {
            $payment=$legacyStore->lockPaymentAndWallet($this->integer($r['id']));
            $mixed=$legacyStore->hasNewHistory((int)$payment['appointment_id']);
            if ($mixed) throw new InvalidArgumentException('هم‌پوشانی پرداخت قدیمی و دفتر جدید نیاز به تطبیق دارد.');
            $possiblyInPeriod=false;
            foreach (array('deposit','balance') as $field) {
                if ($payment[$field.'_status']!=='PAID') continue;
                $date=$payment[$field.'_paid_at'];
                if ($date===null) { $possiblyInPeriod=true; break; }
                joma_v2_report_time($date);
                if ($date >= $from['start'] && $date < $until['start']) { $possiblyInPeriod=true; break; }
            }
            if (!$possiblyInPeriod) continue;
            $review=$legacyStore->latestReview((int)$payment['id']);
            $legacy[]=joma_v2_legacy_review_percentage_record($payment,$review,false);
        }
        return joma_v2_percentage_sources($center,$from['start'],$until['start'],$events,$legacy);
    }
    /** A previously settled legacy component must not silently change later. */
    function verifyPastLegacyItems($center) {
        $items=db_select_all($this->connection,'SELECT i.legacy_payment_id,i.legacy_review_id FROM finance_v2_percentage_items i JOIN finance_center_settlements s ON s.id=i.settlement_id WHERE s.center_id=? AND i.legacy_payment_id IS NOT NULL ORDER BY i.legacy_payment_id FOR UPDATE','i',array($center));
        $legacyStore=new JomaV2MysqliLegacyCenterReviewStore($this->connection);
        foreach ($items as $item) {
            $payment=$legacyStore->lockPaymentAndWallet((int)$item['legacy_payment_id']);
            $review=db_select_one($this->connection,'SELECT payment_fingerprint FROM finance_v2_legacy_center_reviews WHERE id=? AND payment_id=? FOR UPDATE','ii',array((int)$item['legacy_review_id'],(int)$item['legacy_payment_id']));
            if (!$payment || !$review || !joma_v2_legacy_review_is_current($payment,$review,$legacyStore->hasNewHistory((int)$payment['appointment_id']))) throw new InvalidArgumentException('پرداخت قدیمی مرتبط با تسویهٔ قبلی تغییر کرده یا هم‌پوشانی دارد؛ بررسی و اصلاح لازم است.');
        }
    }
    function hasUnresolvedLateEvents($center) {
        return (bool) db_select_one($this->connection,'SELECT l.id FROM finance_v2_percentage_late_events l JOIN finance_center_settlements s ON s.id=l.settlement_id WHERE s.center_id=? LIMIT 1 FOR UPDATE','i',array($center));
    }
    function insertPercentageDetails($id,$exact,$reason) {
        db_execute($this->connection,'INSERT INTO finance_v2_percentage_details (settlement_id,percent_basis_points,gross_rial,exact_whole_rial,exact_remainder,exact_denominator,manual_item_count,manual_reason) VALUES (?,?,?,?,?,?,?,?)',
            'iissiiis',array($id,$exact['basis_points'],(string)$exact['gross_rial'],(string)$exact['whole_rial'],
                $exact['remainder'],$exact['denominator'],count($exact['manual_items']),$reason));
    }
    function insertPercentageItem($settlement,$item) {
        $legacy=in_array($item['kind'],array('LEGACY_DEPOSIT','LEGACY_BALANCE'),true);
        $review=$legacy && isset($item['review_id']) ? $item['review_id'] : null;
        if ($legacy && $review===null) throw new InvalidArgumentException('Legacy review missing');
        db_execute($this->connection,'INSERT INTO finance_v2_percentage_items (settlement_id,kind,event_id,legacy_payment_id,legacy_review_id,amount_rial,occurred_at) VALUES (?,?,?,?,?,?,?)',
            'isiiiss',array($settlement,$item['kind'],$legacy?null:$item['source_id'],$legacy?$item['source_id']:null,$review,(string)$item['amount_rial'],$item['occurred_at']));
    }
}
