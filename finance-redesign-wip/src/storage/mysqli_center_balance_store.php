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
    function resolutionOperationExists($key) {
        if (db_select_one($this->connection,'SELECT id FROM finance_v2_percentage_late_resolutions WHERE operation_key=? FOR UPDATE','s',array($key))) return true;
        return (bool)db_select_one($this->connection,'SELECT id FROM finance_v2_percentage_resolution_revisions WHERE operation_key=? FOR UPDATE','s',array($key));
    }
    function lockResolutionVersion($late) {
        $r=db_select_one($this->connection,'SELECT id,delta_rial FROM finance_v2_percentage_late_resolutions WHERE late_event_id=? FOR UPDATE','i',array($late));
        if (!$r) return null;
        $v=db_select_one($this->connection,'SELECT id,delta_rial FROM finance_v2_percentage_resolution_revisions WHERE resolution_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE','i',array((int)$r['id']));
        return array('id'=>(int)$r['id'],'revision_id'=>$v?(int)$v['id']:0,'delta_rial'=>$this->integer($v?$v['delta_rial']:$r['delta_rial'],true));
    }
    function insertResolutionRevision($id,$key,$delta,$reason,$actor) {
        $r=db_execute($this->connection,'INSERT INTO finance_v2_percentage_resolution_revisions (resolution_id,operation_key,delta_rial,reason,recorded_by_person_id,recorded_at) VALUES (?,?,?,?,?,?)','isssis',array($id,$key,(string)$delta,$reason,$actor,now_dt())); return (int)$r['insert_id'];
    }
    function paymentOperationExists($key) {
        if (db_select_one($this->connection,'SELECT id FROM finance_v2_center_payment_events WHERE operation_key=? FOR UPDATE','s',array($key))) return true;
        return (bool)db_select_one($this->connection,'SELECT id FROM finance_v2_center_payment_revisions WHERE operation_key=? FOR UPDATE','s',array($key));
    }
    function lockPaymentVersion($id) {
        $p=db_select_one($this->connection,'SELECT id,settlement_id,kind,amount_rial,account_id FROM finance_v2_center_payment_events WHERE id=? FOR UPDATE','i',array($id));
        if (!$p) return null;
        $v=db_select_one($this->connection,'SELECT id,action,kind,amount_rial,account_id FROM finance_v2_center_payment_revisions WHERE payment_event_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE','i',array($id));
        if ($v && !in_array($v['action'],array('CORRECT','VOID'),true)) throw new UnexpectedValueException('Invalid payment revision');
        $selected=$v && $v['action']==='CORRECT'?$v:$p;
        if ($v && $v['action']==='VOID') {
            // Restore may need the historically correct account even if that
            // account is now inactive. Find the last complete pre-void version.
            $previous=db_select_one($this->connection,"SELECT kind,amount_rial,account_id FROM finance_v2_center_payment_revisions WHERE payment_event_id=? AND action='CORRECT' AND id<? ORDER BY id DESC LIMIT 1 FOR UPDATE",'ii',array($id,(int)$v['id']));
            if ($previous) $selected=$previous;
        }
        if (!$selected['kind'] || $selected['amount_rial']===null || $selected['account_id']===null) throw new UnexpectedValueException('Incomplete payment version');
        return array('settlement_id'=>(int)$p['settlement_id'],'revision_id'=>$v?(int)$v['id']:0,'action'=>$v?$v['action']:'ORIGINAL',
            'kind'=>$selected['kind'],'amount_rial'=>$this->integer($selected['amount_rial']),'account_id'=>(int)$selected['account_id']);
    }
    function insertPaymentRevision($event,$key,$action,$kind,$amount,$account,$reason,$actor) {
        $r=db_execute($this->connection,'INSERT INTO finance_v2_center_payment_revisions (payment_event_id,operation_key,action,kind,amount_rial,account_id,reason,recorded_by_person_id,recorded_at) VALUES (?,?,?,?,?,?,?,?,?)','issssisis',array($event,$key,$action,$kind,$amount===null?null:(string)$amount,$account,$reason,$actor,now_dt())); return (int)$r['insert_id'];
    }
    function hasUnresolvedFlags($settlement) {
        return (bool)db_select_one($this->connection,'SELECT l.id FROM finance_v2_percentage_late_events l JOIN finance_center_settlements origin ON origin.id=l.settlement_id JOIN finance_center_settlements target ON target.center_id=origin.center_id LEFT JOIN finance_v2_percentage_late_resolutions r ON r.late_event_id=l.id WHERE target.id=? AND r.id IS NULL LIMIT 1 FOR UPDATE','i',array($settlement));
    }
    function accountActive($id) { return (bool)db_select_one($this->connection,"SELECT id FROM finance_bank_accounts WHERE id=? AND status='ACTIVE' FOR UPDATE",'i',array($id)); }
    function balance($id) {
        $base=db_select_one($this->connection,'SELECT amount_due_rial FROM finance_center_settlements WHERE id=? FOR UPDATE','i',array($id));
        if (!$base) throw new InvalidArgumentException('Settlement missing');
        $adjustments=array();
        $rs=db_select_all($this->connection,'SELECT r.id,r.delta_rial,v.delta_rial AS revised_delta,v.id AS revision_id FROM finance_v2_percentage_late_resolutions r JOIN finance_v2_percentage_late_events l ON l.id=r.late_event_id LEFT JOIN finance_v2_percentage_resolution_revisions v ON v.id=(SELECT MAX(v2.id) FROM finance_v2_percentage_resolution_revisions v2 WHERE v2.resolution_id=r.id) WHERE l.settlement_id=? ORDER BY r.id FOR UPDATE','i',array($id));
        foreach ($rs as $r) $adjustments[(int)$r['id']]=$this->integer($r['revision_id']===null?$r['delta_rial']:$r['revised_delta'],true);
        $payments=array();
        $rs=db_select_all($this->connection,'SELECT p.id,p.kind,p.amount_rial,p.account_id,v.id AS revision_id,v.action,v.kind AS revised_kind,v.amount_rial AS revised_amount,v.account_id AS revised_account FROM finance_v2_center_payment_events p LEFT JOIN finance_v2_center_payment_revisions v ON v.id=(SELECT MAX(v2.id) FROM finance_v2_center_payment_revisions v2 WHERE v2.payment_event_id=p.id) WHERE p.settlement_id=? ORDER BY p.id FOR UPDATE','i',array($id));
        foreach ($rs as $r) {
            if ($r['revision_id']!==null && $r['action']==='VOID') continue;
            if ($r['revision_id']!==null && $r['action']!=='CORRECT') throw new UnexpectedValueException('Invalid payment revision');
            $kind=$r['revision_id']===null?$r['kind']:$r['revised_kind'];
            $amount=$r['revision_id']===null?$r['amount_rial']:$r['revised_amount'];
            $account=$r['revision_id']===null?$r['account_id']:$r['revised_account'];
            if ($kind===null || $amount===null || $account===null) throw new UnexpectedValueException('Incomplete payment version');
            $payments[(int)$r['id']]=array('kind'=>$kind,'amount_rial'=>$this->integer($amount));
        }
        return array('principal'=>$this->integer($base['amount_due_rial']),'adjustments'=>$adjustments,'payments'=>$payments);
    }
    function insertResolution($late,$key,$delta,$reason,$actor) {
        $r=db_execute($this->connection,'INSERT INTO finance_v2_percentage_late_resolutions (late_event_id,operation_key,delta_rial,reason,recorded_by_person_id,recorded_at) VALUES (?,?,?,?,?,?)','isssis',array($late,$key,(string)$delta,$reason,$actor,now_dt())); return (int)$r['insert_id'];
    }
    function insertPayment($settlement,$key,$kind,$amount,$account,$reason,$actor) {
        $r=db_execute($this->connection,'INSERT INTO finance_v2_center_payment_events (settlement_id,operation_key,kind,amount_rial,account_id,reason,recorded_by_person_id,recorded_at) VALUES (?,?,?,?,?,?,?,?)','isssisis',array($settlement,$key,$kind,(string)$amount,$account,$reason,$actor,now_dt())); return (int)$r['insert_id'];
    }
}
