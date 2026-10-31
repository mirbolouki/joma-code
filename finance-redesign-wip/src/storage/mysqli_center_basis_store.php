<?php
/* WIP: no final migration or site integration yet. */
class JomaV2MysqliCenterBasisStore
{
    private $db;
    function __construct($db) { $this->db=$db; }
    function begin() { if (!mysqli_query($this->db,'START TRANSACTION')) throw new RuntimeException('Begin failed'); }
    function commit() { if (!mysqli_query($this->db,'COMMIT')) throw new RuntimeException('Commit failed'); }
    function rollback() { mysqli_query($this->db,'ROLLBACK'); }
    function lockActiveCenter($id) { return (bool)db_select_one($this->db,"SELECT id FROM finance_centers WHERE id=? AND status='ACTIVE' FOR UPDATE",'i',array($id)); }
    function centerBasisForUpdate($id) { $row=db_select_one($this->db,'SELECT time_basis FROM finance_v2_center_basis WHERE center_id=? FOR UPDATE','i',array($id)); return $row ? $row['time_basis'] : null; }
    function saveCenterBasis($id,$basis,$actor,$previous) {
        if ($previous === null) db_execute($this->db,'INSERT INTO finance_v2_center_basis (center_id,time_basis,updated_by_person_id,updated_at) VALUES (?,?,?,?)','isis',array($id,$basis,$actor,now_dt()));
        else db_execute($this->db,'UPDATE finance_v2_center_basis SET time_basis=?,updated_by_person_id=?,updated_at=? WHERE center_id=?','sisi',array($basis,$actor,now_dt(),$id));
    }
    function appendCenterBasisEvent($id,$old,$new,$actor) {
        db_execute($this->db,'INSERT INTO finance_v2_center_basis_events (center_id,old_basis,new_basis,recorded_by_person_id,recorded_at) VALUES (?,?,?,?,?)','issis',array($id,$old,$new,$actor,now_dt()));
    }
}
