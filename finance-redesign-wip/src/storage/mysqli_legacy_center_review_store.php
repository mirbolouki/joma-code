<?php
/* Prototype; database operations must be wrapped in the service transaction. */
class JomaV2MysqliLegacyCenterReviewStore {
    private $db;
    function __construct($db) { $this->db=$db; }
    function begin() { if (!mysqli_query($this->db,'START TRANSACTION')) throw new RuntimeException('Begin failed'); }
    function commit() { if (!mysqli_query($this->db,'COMMIT')) throw new RuntimeException('Commit failed'); }
    function lockLedger() { if (!db_select_one($this->db,'SELECT id FROM finance_v2_locks WHERE id=1 FOR UPDATE')) throw new RuntimeException('Finance lock missing'); }
    function rollback() { mysqli_query($this->db,'ROLLBACK'); }
    function lockPaymentAndWallet($id) {
        // Try the existing ledger lock order (wallet before appointment).
        // If a wallet is created concurrently, current locking reads below
        // either serialize it or cause a rollback on deadlock; never guess.
        $p=db_select_one($this->db,'SELECT appointment_id FROM finance_session_payments WHERE id=?','i',array($id));
        if (!$p) return null;
        $appointment=(int)$p['appointment_id'];
        $w=db_select_one($this->db,'SELECT id FROM finance_v2_wallets WHERE appointment_id=?','i',array($appointment));
        if ($w) db_select_one($this->db,'SELECT id FROM finance_v2_wallets WHERE id=? FOR UPDATE','i',array((int)$w['id']));
        db_select_one($this->db,'SELECT id FROM appointments WHERE id=? FOR UPDATE','i',array($appointment));
        db_select_one($this->db,'SELECT id FROM finance_v2_wallets WHERE appointment_id=? FOR UPDATE','i',array($appointment));
        $payment=db_select_one($this->db,'SELECT id,appointment_id,session_fee_rial,deposit_amount_rial,deposit_bank_account_id,deposit_status,deposit_paid_at,balance_amount_rial,balance_bank_account_id,balance_status,balance_paid_at FROM finance_session_payments WHERE id=? FOR UPDATE','i',array($id));
        if (!$payment || (int)$payment['appointment_id']!==$appointment) throw new InvalidArgumentException('Payment appointment changed; retry');
        return $payment;
    }
    function hasNewHistory($appointment) {
        return (bool) db_select_one($this->db,'SELECT ev.id FROM finance_v2_wallets w JOIN finance_v2_events ev ON ev.wallet_id=w.id OR ev.destination_wallet_id=w.id WHERE w.appointment_id=? LIMIT 1 FOR UPDATE','i',array($appointment));
    }
    function latestReview($id) {
        return db_select_one($this->db,'SELECT id,payment_fingerprint,deposit_center_id,balance_center_id FROM finance_v2_legacy_center_reviews WHERE payment_id=? ORDER BY id DESC LIMIT 1 FOR UPDATE','i',array($id));
    }
    function centerExists($id) { return (bool) db_select_one($this->db,'SELECT id FROM finance_centers WHERE id=? FOR UPDATE','i',array($id)); }
    function appendReview($payment,$depositCenter,$balanceCenter,$fingerprint,$snapshot,$reason,$actor) {
        $r=db_execute($this->db,'INSERT INTO finance_v2_legacy_center_reviews (payment_id,deposit_center_id,balance_center_id,payment_fingerprint,payment_snapshot_json,reason,recorded_by_person_id,recorded_at) VALUES (?,?,?,?,?,?,?,?)',
            'iiisssis',array($payment,$depositCenter,$balanceCenter,$fingerprint,$snapshot,$reason,$actor,now_dt())); return (int)$r['insert_id'];
    }
    private function listSql() {
        return "SELECT p.id,p.appointment_id,p.session_fee_rial,p.deposit_amount_rial,p.deposit_bank_account_id,p.deposit_status,p.deposit_paid_at,
            p.balance_amount_rial,p.balance_bank_account_id,p.balance_status,p.balance_paid_at,ap.public_id AS appointment_public,
            COALESCE(rv.id,0) AS review_id,rv.payment_fingerprint,rv.deposit_center_id,rv.balance_center_id,
            CASE WHEN EXISTS (SELECT ev.id FROM finance_v2_wallets w JOIN finance_v2_events ev ON ev.wallet_id=w.id OR ev.destination_wallet_id=w.id WHERE w.appointment_id=p.appointment_id) THEN 1 ELSE 0 END AS has_new_history
            FROM finance_session_payments p JOIN appointments ap ON ap.id=p.appointment_id
            LEFT JOIN finance_v2_legacy_center_reviews rv ON rv.id=(SELECT MAX(r2.id) FROM finance_v2_legacy_center_reviews r2 WHERE r2.payment_id=p.id)";
    }
    function listPayments($before=null) {
        if ($before===null) return db_select_all($this->db,$this->listSql().' ORDER BY p.id DESC LIMIT 101');
        joma_v2_positive_id($before,'Cursor');
        return db_select_all($this->db,$this->listSql().' WHERE p.id<? ORDER BY p.id DESC LIMIT 101','i',array($before));
    }
    function findPayment($id) { return db_select_one($this->db,$this->listSql().' WHERE p.id=?','i',array($id)); }
    function centers() { return db_select_all($this->db,'SELECT id,name,status FROM finance_centers ORDER BY name,id'); }
    function history($id) { return db_select_all($this->db,'SELECT rv.*,dc.name AS deposit_center_name,bc.name AS balance_center_name FROM finance_v2_legacy_center_reviews rv LEFT JOIN finance_centers dc ON dc.id=rv.deposit_center_id LEFT JOIN finance_centers bc ON bc.id=rv.balance_center_id WHERE rv.payment_id=? ORDER BY rv.id','i',array($id)); }
}
