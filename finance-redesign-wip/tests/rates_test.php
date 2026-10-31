<?php
/* SQLite integration of domain logic; does not test the MySQL adapter/locks. */
require __DIR__ . '/../src/rates.php';
class RateTestStore {
    public $db;
    public $subjects = array('1:1'=>true, '1:2'=>true, '2:1'=>true);
    public $failAudit = false;
    function __construct() {
        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE rates (id INTEGER PRIMARY KEY, therapist INTEGER, service INTEGER, amount INTEGER, active INTEGER, UNIQUE(therapist,service)); CREATE TABLE changes (id INTEGER PRIMARY KEY, rate_id INTEGER, old_amount INTEGER, new_amount INTEGER, active INTEGER, actor INTEGER); CREATE TABLE appointments (id INTEGER PRIMARY KEY, therapist INTEGER, service INTEGER, previous INTEGER); CREATE TABLE wallets (id INTEGER PRIMARY KEY, appointment INTEGER UNIQUE, therapist INTEGER, service INTEGER, amount INTEGER)');
    }
    function begin() { $this->db->beginTransaction(); }
    function commit() { $this->db->commit(); }
    function rollback() { $this->db->rollBack(); }
    function lockRateSubject($therapist, $service) { return isset($this->subjects[$therapist.':'.$service]); }
    function rateForUpdate($therapist, $service) { $s=$this->db->prepare('SELECT * FROM rates WHERE therapist=? AND service=?'); $s->execute(array($therapist,$service)); $r=$s->fetch(PDO::FETCH_ASSOC); return $r ? array('id'=>(int)$r['id'],'amount'=>(int)$r['amount'],'active'=>(bool)$r['active']) : null; }
    function saveRate($therapist, $service, $amount, $active, $previous) {
        if ($previous === null) { $s=$this->db->prepare('INSERT INTO rates(therapist,service,amount,active) VALUES(?,?,?,?)'); $s->execute(array($therapist,$service,$amount,(int)$active)); return (int)$this->db->lastInsertId(); }
        $s=$this->db->prepare('UPDATE rates SET amount=?,active=? WHERE id=?'); $s->execute(array($amount,(int)$active,$previous['id'])); return $previous['id'];
    }
    function appendRateChange($id,$previous,$amount,$active,$actor) { if ($this->failAudit) throw new RuntimeException('Simulated audit failure'); $s=$this->db->prepare('INSERT INTO changes(rate_id,old_amount,new_amount,active,actor) VALUES(?,?,?,?,?)'); $s->execute(array($id,$previous === null ? null : $previous['amount'],$amount,(int)$active,$actor)); }
    function newAppointmentForUpdate($id) { $s=$this->db->prepare('SELECT * FROM appointments WHERE id=?'); $s->execute(array($id)); $r=$s->fetch(PDO::FETCH_ASSOC); return $r ? array('therapist_id'=>(int)$r['therapist'],'service_id'=>(int)$r['service'],'rescheduled_from_id'=>$r['previous'] === null ? null : (int)$r['previous']) : null; }
    function walletForAppointment($id) { $s=$this->db->prepare('SELECT * FROM wallets WHERE appointment=?'); $s->execute(array($id)); $r=$s->fetch(PDO::FETCH_ASSOC); return $r ? array('therapist_id'=>(int)$r['therapist'],'service_id'=>(int)$r['service'],'amount'=>$r['amount'] === null ? null : (int)$r['amount']) : null; }
    function insertAppointmentWallet($id,$therapist,$service,$amount) { $s=$this->db->prepare('INSERT INTO wallets (appointment,therapist,service,amount) VALUES (?,?,?,?)'); $s->execute(array($id,$therapist,$service,$amount)); }
}
function same($a,$b,$why) { if ($a !== $b) throw new Exception($why.': '.var_export($a,true)); }
function rejects($f,$why) { try { $f(); } catch (InvalidArgumentException $e) { return; } throw new Exception('Expected rejection: '.$why); }
$s = new RateTestStore(); $admin=array('person_id'=>3,'role'=>'admin');
rejects(function()use($s){joma_v2_set_rate($s,1,1,10000,true,array('person_id'=>3,'role'=>'secretary'));},'not admin');
rejects(function()use($s,$admin){joma_v2_set_rate($s,9,1,10000,true,$admin);},'invalid therapist');
joma_v2_set_rate($s,1,1,10000,true,$admin);
joma_v2_set_rate($s,1,2,20000,true,$admin);
joma_v2_set_rate($s,2,1,30000,true,$admin);
joma_v2_set_rate($s,1,1,10000,true,$admin);
same((int)$s->db->query('SELECT COUNT(*) FROM changes')->fetchColumn(),3,'unchanged rate no audit duplicate');
$s->db->exec('INSERT INTO appointments VALUES(1,1,1,NULL)');
$s->begin(); same(joma_v2_capture_booking_rate($s,1),true,'booking captured'); $s->commit();
same($s->walletForAppointment(1)['amount'],10000,'correct combination captured');
joma_v2_set_rate($s,1,1,12000,true,$admin);
same($s->walletForAppointment(1)['amount'],10000,'old appointment rate immutable');
$s->db->exec('INSERT INTO appointments VALUES(2,1,1,1)');
$s->begin(); same(joma_v2_capture_rescheduled_rate($s,2,1),true,'reschedule captured'); $s->commit();
same($s->walletForAppointment(2)['amount'],10000,'reschedule preserves old rate');
$s->db->exec('INSERT INTO appointments VALUES(3,2,1,1)');
$s->begin(); same(joma_v2_capture_rescheduled_rate($s,3,1),false,'changed therapist requires explicit rate'); $s->commit();
same($s->walletForAppointment(3)['amount'],null,'no implicit new rate');
$s->db->exec('INSERT INTO appointments VALUES(4,1,2,NULL)');
joma_v2_set_rate($s,1,2,20000,false,$admin);
$s->begin(); same(joma_v2_capture_booking_rate($s,4),false,'inactive rate unpriced'); $s->commit();
same($s->walletForAppointment(4)['amount'],null,'inactive rate not used');
$s->db->exec('INSERT INTO appointments VALUES(5,1,1,99)');
$s->begin(); same(joma_v2_capture_rescheduled_rate($s,5,99),false,'historic with no wallet'); $s->commit();
same($s->walletForAppointment(5)['amount'],null,'no guessed historical rate');
$s->begin(); rejects(function()use($s){joma_v2_capture_booking_rate($s,1);},'duplicate wallet'); $s->rollback();
joma_v2_set_rate($s,1,2,0,true,$admin);
$s->db->exec('INSERT INTO appointments VALUES(7,1,2,NULL)');
$s->begin(); same(joma_v2_capture_booking_rate($s,7),true,'zero rate is configured'); $s->commit();
same($s->walletForAppointment(7)['amount'],0,'zero rate preserved');
$s->failAudit=true;
try { joma_v2_set_rate($s,1,1,13000,true,$admin); throw new Exception('Audit failure not propagated'); } catch (RuntimeException $e) {}
same($s->rateForUpdate(1,1)['amount'],12000,'audit failure rolls back change');
$s->begin(); $s->db->exec('INSERT INTO appointments VALUES(6,1,1,NULL)'); joma_v2_capture_booking_rate($s,6); $s->rollback();
same($s->newAppointmentForUpdate(6),null,'booking and wallet rollback together');
same($s->walletForAppointment(6),null,'snapshot rollback');
echo "Rate service and booking snapshot tests passed (SQLite; no MySQL locking).\n";
