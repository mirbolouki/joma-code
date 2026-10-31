<?php
/* Integration test of service against SQLite test store, NOT MySQL locking. */
require __DIR__ . '/../src/record_event.php';
class TestSqliteStore {
    public $pdo;
    private $wallets;
    function __construct($wallets) {
        $this->wallets = $wallets;
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE events (id INTEGER PRIMARY KEY, operation_key TEXT UNIQUE, event_type TEXT, wallet_id INTEGER, destination_wallet_id INTEGER, amount_rial INTEGER, receipt_kind TEXT, account_id INTEGER, actual_minutes INTEGER, calculated_rial INTEGER, reason TEXT, recorded_by_person_id INTEGER, recorded_by_role_code TEXT)');
    }
    function begin() { $this->pdo->beginTransaction(); }
    function commit() { $this->pdo->commit(); }
    function rollback() { $this->pdo->rollBack(); }
    function operationExists($key) { $s=$this->pdo->prepare('SELECT id FROM events WHERE operation_key=?'); $s->execute(array($key)); return (bool)$s->fetch(); }
    function lockWallets($ids) { $rows=array(); foreach ($ids as $id) if (isset($this->wallets[$id])) $rows[$id]=$this->wallets[$id]; return $rows; }
    function accountActive($id) { return $id === 7; }
    function insertEvent($e) {
        $keys=array_keys($e); $this->pdo->prepare('INSERT INTO events ('.implode(',', $keys).') VALUES ('.implode(',', array_fill(0,count($keys),'?')).')')->execute(array_values($e));
        return (int)$this->pdo->lastInsertId();
    }
    function balance($id) {
        $s=$this->pdo->prepare("SELECT COALESCE(SUM(CASE WHEN wallet_id=? AND event_type='RECEIPT' THEN amount_rial WHEN wallet_id=? AND event_type IN ('REFUND','TRANSFER') THEN -amount_rial WHEN destination_wallet_id=? AND event_type='TRANSFER' THEN amount_rial ELSE 0 END),0) FROM events WHERE wallet_id=? OR destination_wallet_id=?");
        $s->execute(array($id,$id,$id,$id,$id)); $received=(int)$s->fetchColumn();
        $s=$this->pdo->prepare("SELECT amount_rial FROM events WHERE wallet_id=? AND event_type='CHARGE' ORDER BY id DESC LIMIT 1"); $s->execute(array($id)); $charge=$s->fetchColumn();
        return array('received'=>$received,'charge'=>$charge === false ? null : (int)$charge);
    }
}
function same($a,$b,$m) { if ($a !== $b) throw new Exception($m.': '.var_export($a,true)); }
function rejects($f,$m) { try { $f(); } catch (InvalidArgumentException $e) { return; } catch (OverflowException $e) { return; } throw new Exception('Expected rejection: '.$m); }
$wallets = array(
  1=>array('kind'=>'REQUEST','admission_id'=>5,'patient_id'=>null,'appointment_status'=>null,'rate_rial_per_minute'=>null),
  2=>array('kind'=>'APPOINTMENT','admission_id'=>5,'patient_id'=>11,'appointment_status'=>'COMPLETED','rate_rial_per_minute'=>10000),
  3=>array('kind'=>'APPOINTMENT','admission_id'=>6,'patient_id'=>12,'appointment_status'=>'COMPLETED','rate_rial_per_minute'=>20000),
  4=>array('kind'=>'APPOINTMENT','admission_id'=>7,'patient_id'=>11,'appointment_status'=>'COMPLETED','rate_rial_per_minute'=>20000),
  5=>array('kind'=>'APPOINTMENT','admission_id'=>5,'patient_id'=>11,'appointment_status'=>'SCHEDULED','rate_rial_per_minute'=>10000)
);
$s = new TestSqliteStore($wallets); $a = array('person_id'=>1,'role'=>'secretary');
$receipt=array('key'=>'receipt_0000000001','type'=>'RECEIPT','wallet'=>1,'amount_rial'=>500000,'account_id'=>7,'receipt_kind'=>'DEPOSIT');
joma_v2_record_event($s,$receipt,$a);
rejects(function()use($s,$receipt,$a){joma_v2_record_event($s,$receipt,$a);},'duplicate');
rejects(function()use($s,$a){joma_v2_record_event($s,array('key'=>'transfer_00000001','type'=>'TRANSFER','wallet'=>1,'to'=>3,'amount_rial'=>100000,'reason'=>'x'),$a);},'wrong admission');
same($s->balance(1)['received'],500000,'rejected transfer rolls back');
joma_v2_record_event($s,array('key'=>'transfer_00000002','type'=>'TRANSFER','wallet'=>1,'to'=>2,'amount_rial'=>500000,'reason'=>'advance'),$a);
same($s->balance(1)['received'],0,'request transferred');
same($s->balance(2)['received'],500000,'appointment credited');
rejects(function()use($s,$a){joma_v2_record_event($s,array('key'=>'badcharge_0000001','type'=>'CHARGE','wallet'=>5,'actual_minutes'=>35),$a);},'scheduled not chargeable');
joma_v2_record_event($s,array('key'=>'charge_0000000001','type'=>'CHARGE','wallet'=>2,'actual_minutes'=>35),$a);
same($s->balance(2)['charge'],350000,'actual minutes at snapshot rate');
rejects(function()use($s,$a){joma_v2_record_event($s,array('key'=>'transfer_00000003','type'=>'TRANSFER','wallet'=>2,'to'=>3,'amount_rial'=>100000,'reason'=>'x'),$a);},'different patient');
rejects(function()use($s,$a){joma_v2_record_event($s,array('key'=>'transfer_00000004','type'=>'TRANSFER','wallet'=>2,'to'=>4,'amount_rial'=>150001,'reason'=>'x'),$a);},'exceeds credit');
joma_v2_record_event($s,array('key'=>'transfer_00000005','type'=>'TRANSFER','wallet'=>2,'to'=>4,'amount_rial'=>150000,'reason'=>'credit'),$a);
same($s->balance(2)['received'],350000,'paid session unchanged');
rejects(function()use($s,$a){joma_v2_record_event($s,array('key'=>'refund_0000000001','type'=>'REFUND','wallet'=>4,'amount_rial'=>150001,'account_id'=>7,'reason'=>'x'),$a);},'excess refund');
joma_v2_record_event($s,array('key'=>'refund_0000000002','type'=>'REFUND','wallet'=>4,'amount_rial'=>50000,'account_id'=>7,'reason'=>'refund'),$a);
same($s->balance(4)['received'],100000,'refund recorded');
rejects(function()use($s,$a){joma_v2_record_event($s,array('key'=>'charge_0000000002','type'=>'CHARGE','wallet'=>2,'actual_minutes'=>35),$a);},'revision needs reason');
joma_v2_record_event($s,array('key'=>'charge_0000000003','type'=>'CHARGE','wallet'=>2,'actual_minutes'=>35,'override_rial'=>300000,'reason'=>'discount'),$a);
same($s->balance(2)['charge'],300000,'override recorded');
rejects(function()use($s,$receipt){joma_v2_record_event($s,$receipt,array('person_id'=>1,'role'=>'therapist'));},'role');
$rows=$s->pdo->query('SELECT event_type, amount_rial, calculated_rial FROM events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
same(count($rows),6,'only accepted events persisted');
same((int)$rows[5]['calculated_rial'],350000,'base amount kept after override');
echo "SQLite service integration tests passed (no MySQL lock test).\n";
