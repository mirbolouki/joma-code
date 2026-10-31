<?php
/* WIP, requires installation and compatibility testing on the target site. */
class JomaV2MysqliRequestWalletStore
{
    private $db;
    function __construct($db) { $this->db = $db; }
    function begin() { if (!mysqli_query($this->db,'START TRANSACTION')) throw new RuntimeException('Begin failed'); }
    function commit() { if (!mysqli_query($this->db,'COMMIT')) throw new RuntimeException('Commit failed'); }
    function rollback() { mysqli_query($this->db,'ROLLBACK'); }
    function lockRequest($request_id) {
        return (bool)db_select_one($this->db,'SELECT id FROM booking_requests WHERE id = ? FOR UPDATE','i',array($request_id));
    }
    function findRequestWallet($request_id) {
        $r=db_select_one($this->db,'SELECT id FROM finance_v2_wallets WHERE request_id = ? FOR UPDATE','i',array($request_id));
        return $r ? (int)$r['id'] : null;
    }
    function insertRequestWallet($request_id) {
        $r=db_execute($this->db,'INSERT INTO finance_v2_wallets (request_id,created_at) VALUES (?,?)','is',array($request_id,now_dt()));
        return (int)$r['insert_id'];
    }
}
