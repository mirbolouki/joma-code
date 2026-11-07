<?php
/* Experimental review-only store; lock ordering follows percentage settlements. */
require_once __DIR__.'/mysqli_center_settlement_store.php';
class JomaV2MysqliPercentageLateReviewStore extends JomaV2MysqliCenterSettlementStore {
    private $connection;
    function __construct($db) { parent::__construct($db); $this->connection=$db; }
    function lockLateEvent($id) {
        return db_select_one($this->connection,'SELECT id FROM finance_v2_percentage_late_events WHERE id=? FOR UPDATE','i',array($id));
    }
    function latestReview($id) {
        return db_select_one($this->connection,'SELECT id FROM finance_v2_percentage_late_reviews WHERE late_event_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE','i',array($id));
    }
    function appendReview($id,$kind,$reason,$actor) {
        $r=db_execute($this->connection,'INSERT INTO finance_v2_percentage_late_reviews (late_event_id,review_kind,reason,recorded_by_person_id,recorded_at) VALUES (?,?,?,?,?)','issis',array($id,$kind,$reason,$actor,now_dt()));
        return (int)$r['insert_id'];
    }
    function findLateEvent($id) {
        return db_select_one($this->connection,'SELECT l.id,s.public_id,s.period_start,s.period_end,c.name AS center_name,ev.id AS event_id,ev.event_type,ev.amount_rial,ev.occurred_at FROM finance_v2_percentage_late_events l JOIN finance_center_settlements s ON s.id=l.settlement_id JOIN finance_centers c ON c.id=s.center_id JOIN finance_v2_events ev ON ev.id=l.event_id WHERE l.id=?','i',array($id));
    }
    function history($id) {
        return db_select_all($this->connection,'SELECT id,review_kind,reason,recorded_by_person_id,recorded_at FROM finance_v2_percentage_late_reviews WHERE late_event_id=? ORDER BY id','i',array($id));
    }
}
