<?php
/* EXPERIMENTAL only. Caller transaction and global ledger lock required. */
require_once __DIR__.'/mysqli_center_settlement_store.php';
class JomaV2MysqliCenterBalanceStore extends JomaV2MysqliCenterSettlementStore {
    private $connection;
    function __construct($db) { parent::__construct($db); $this->connection=$db; }
    private function integer($value,$signed=false) {
        $text=(string)$value;
        if (!preg_match($signed?'/^-?(0|[1-9][0-9]{0,17})$/D':'/^(0|[1-9][0-9]{0,17})$/D',$text)) throw new UnexpectedValueException('Invalid stored amount');
        return (int)$text;
    }
    function lockLate($id) {
        return db_select_one($this->connection,'SELECT settlement_id FROM finance_v2_percentage_late_events WHERE id=? FOR UPDATE','i',array($id));
    }
    function lockEligibleSettlement($id) {
        $r=db_select_one($this->connection,'SELECT s.id,s.amount_paid_rial,s.paid_bank_account_id,s.paid_at,d.settlement_id AS pct_id,h.settlement_id AS hourly_id FROM finance_center_settlements s LEFT JOIN finance_v2_percentage_details d ON d.settlement_id=s.id LEFT JOIN finance_v2_center_settlement_details h ON h.settlement_id=s.id WHERE s.id=? FOR UPDATE','i',array($id));
        // Unclassified legacy and hourly settlements need their own separate
        // reconciliation. No mixing with mutable legacy payment history.
        if (!$r || $r['pct_id']===null || $r['hourly_id']!==null ||
            ($r['amount_paid_rial']!==null && $this->integer($r['amount_paid_rial'])!==0) ||
            $r['paid_bank_account_id']!==null || $r['paid_at']!==null) throw new InvalidArgumentException('تسویه یا سابقهٔ پرداخت قدیمی نیازمند تطبیق جداگانه است.');
        return $r;
    }
    function hasResolution($late) { return (bool)db_select_one($this->connection,'SELECT id FROM finance_v2_percentage_late_resolutions WHERE late_event_id=? FOR UPDATE','i',array($late)); }
    function resolutionOperationExists($key) { return (bool)db_select_one($this->connection,'SELECT id FROM finance_v2_percentage_late_resolutions WHERE operation_key=? FOR UPDATE','s',array($key)); }
    function paymentOperationExists($key) { return (bool)db_select_one($this->connection,'SELECT id FROM finance_v2_center_payment_events WHERE operation_key=? FOR UPDATE','s',array($key)); }
    function hasUnresolvedFlags($settlement) {
        return (bool)db_select_one($this->connection,'SELECT l.id FROM finance_v2_percentage_late_events l JOIN finance_center_settlements origin ON origin.id=l.settlement_id JOIN finance_center_settlements target ON target.center_id=origin.center_id LEFT JOIN finance_v2_percentage_late_resolutions r ON r.late_event_id=l.id WHERE target.id=? AND r.id IS NULL LIMIT 1 FOR UPDATE','i',array($settlement));
    }
    function accountActive($id) { return (bool)db_select_one($this->connection,"SELECT id FROM finance_bank_accounts WHERE id=? AND status='ACTIVE' FOR UPDATE",'i',array($id)); }
    function balance($id) {
        $base=db_select_one($this->connection,'SELECT amount_due_rial FROM finance_center_settlements WHERE id=? FOR UPDATE','i',array($id));
        if (!$base) throw new InvalidArgumentException('Settlement missing');
        $adjustments=array();
        $rs=db_select_all($this->connection,'SELECT r.delta_rial FROM finance_v2_percentage_late_resolutions r JOIN finance_v2_percentage_late_events l ON l.id=r.late_event_id WHERE l.settlement_id=? ORDER BY r.id FOR UPDATE','i',array($id));
        foreach ($rs as $r) $adjustments[]=$this->integer($r['delta_rial'],true);
        $payments=array();
        $rs=db_select_all($this->connection,'SELECT kind,amount_rial FROM finance_v2_center_payment_events WHERE settlement_id=? ORDER BY id FOR UPDATE','i',array($id));
        foreach ($rs as $r) $payments[]=array('kind'=>$r['kind'],'amount_rial'=>$this->integer($r['amount_rial']));
        return array('principal'=>$this->integer($base['amount_due_rial']),'adjustments'=>$adjustments,'payments'=>$payments);
    }
    function insertResolution($late,$key,$delta,$reason,$actor) {
        $r=db_execute($this->connection,'INSERT INTO finance_v2_percentage_late_resolutions (late_event_id,operation_key,delta_rial,reason,recorded_by_person_id,recorded_at) VALUES (?,?,?,?,?,?)','isssis',array($late,$key,(string)$delta,$reason,$actor,now_dt())); return (int)$r['insert_id'];
    }
    function insertPayment($settlement,$key,$kind,$amount,$account,$reason,$actor) {
        $r=db_execute($this->connection,'INSERT INTO finance_v2_center_payment_events (settlement_id,operation_key,kind,amount_rial,account_id,reason,recorded_by_person_id,recorded_at) VALUES (?,?,?,?,?,?,?,?)','isssisis',array($settlement,$key,$kind,(string)$amount,$account,$reason,$actor,now_dt())); return (int)$r['insert_id'];
    }
}
