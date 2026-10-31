<?php
/* SQLite test adapter; does not cover MySQL implementation or locking. */
require __DIR__ . '/../src/expenses.php';
class ExpenseTestStore {
    public $db; public $fail = false;
    function __construct() { $this->db=new PDO('sqlite::memory:'); $this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $this->db->exec('CREATE TABLE expenses(id INTEGER PRIMARY KEY, operation_key TEXT UNIQUE, expense_date TEXT, category TEXT, amount_rial INTEGER, account_id INTEGER, description TEXT, recorded_by_person_id INTEGER)'); }
    function lockExpense($id) { $q=$this->db->prepare('SELECT * FROM expenses WHERE id=?'); $q->execute(array($id)); return $q->fetch(PDO::FETCH_ASSOC); }
    function latestExpenseRevision($id) { $q=$this->db->prepare('SELECT * FROM revisions WHERE expense_id=? ORDER BY id DESC LIMIT 1'); $q->execute(array($id)); return $q->fetch(PDO::FETCH_ASSOC); }
    function revisionOperationExists($key) { $q=$this->db->prepare('SELECT id FROM revisions WHERE operation_key=?'); $q->execute(array($key)); return (bool)$q->fetch(); }
    function insertExpenseRevision($r) { $cols=array_keys($r); $q=$this->db->prepare('INSERT INTO revisions('.implode(',',$cols).') VALUES('.implode(',',array_fill(0,count($cols),'?')).')'); $q->execute(array_values($r)); if ($this->fail) throw new RuntimeException('Simulated failure'); return (int)$this->db->lastInsertId(); }
    function begin() { $this->db->beginTransaction(); }
    function commit() { $this->db->commit(); }
    function rollback() { $this->db->rollBack(); }
    function expenseOperationExists($key) { $s=$this->db->prepare('SELECT id FROM expenses WHERE operation_key=?'); $s->execute(array($key)); return (bool)$s->fetch(); }
    function expenseAccountActive($id) { return $id === 5; }
    function insertExpense($r) { $columns=array_keys($r); $s=$this->db->prepare('INSERT INTO expenses('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($columns),'?')).')'); $s->execute(array_values($r)); if ($this->fail) throw new RuntimeException('Simulated failure'); return (int)$this->db->lastInsertId(); }
}
function same($a,$b,$message) { if ($a !== $b) throw new Exception($message . ': ' . var_export($a,true)); }
function rejects($f,$message) { try { $f(); } catch (InvalidArgumentException $e) { return; } throw new Exception('Expected rejection: '.$message); }
$s=new ExpenseTestStore(); $s->db->exec('CREATE TABLE revisions(id INTEGER PRIMARY KEY, expense_id INTEGER, operation_key TEXT UNIQUE, action TEXT, expense_date TEXT, category TEXT, amount_rial INTEGER, account_id INTEGER, description TEXT, reason TEXT, recorded_by_person_id INTEGER)'); $admin=array('person_id'=>1,'role'=>'admin');
$expense=array('key'=>'expense_0000000001','date'=>'2026-10-31','category'=>' اجاره ', 'amount_rial'=>3000000, 'account_id'=>5, 'description'=>'اجاره دفتر');
same(joma_v2_record_expense($s,$expense,$admin),1,'expense id');
$row=$s->db->query('SELECT * FROM expenses')->fetch(PDO::FETCH_ASSOC);
same($row['category'],'اجاره','category trimmed'); same((int)$row['amount_rial'],3000000,'amount stored');
rejects(function()use($s,$expense,$admin){joma_v2_record_expense($s,$expense,$admin);},'duplicate');
rejects(function()use($s,$expense){joma_v2_record_expense($s,$expense,array('person_id'=>2,'role'=>'secretary'));},'role');
$bad=$expense; $bad['date']='2026-02-30'; rejects(function()use($s,$bad,$admin){joma_v2_record_expense($s,$bad,$admin);},'date');
$bad=$expense; $bad['amount_rial']=0; rejects(function()use($s,$bad,$admin){joma_v2_record_expense($s,$bad,$admin);},'zero amount');
$bad=$expense; $bad['key']='expense_0000000002'; $bad['account_id']=4; rejects(function()use($s,$bad,$admin){joma_v2_record_expense($s,$bad,$admin);},'inactive account');
$bad=$expense; $bad['key']='expense_0000000003'; $s->fail=true;
try { joma_v2_record_expense($s,$bad,$admin); throw new Exception('Expected rollback'); } catch (RuntimeException $e) {}
same((int)$s->db->query('SELECT COUNT(*) FROM expenses')->fetchColumn(),1,'rollback and rejected attempts do not persist');
$s->fail=false;
$change=array('key'=>'revision_0000000001','expense_id'=>1,'expected_revision_id'=>0,'action'=>'CORRECT','date'=>'2026-11-01','category'=>'لوازم','amount_rial'=>2500000,'account_id'=>5,'description'=>'اصلاح','reason'=>'اشتباه در ثبت');
rejects(function()use($s,$change){joma_v2_revise_expense($s,$change,array('person_id'=>2,'role'=>'secretary'));},'only admin');
$bad=$change; $bad['reason']=' '; rejects(function()use($s,$bad,$admin){joma_v2_revise_expense($s,$bad,$admin);},'reason required');
same(joma_v2_revise_expense($s,$change,$admin),1,'revision created');
rejects(function()use($s,$change,$admin){joma_v2_revise_expense($s,$change,$admin);},'stale version rejected');
same((int)$s->db->query('SELECT amount_rial FROM expenses WHERE id=1')->fetchColumn(),3000000,'original preserved');
$void=array('key'=>'revision_0000000002','expense_id'=>1,'expected_revision_id'=>1,'action'=>'VOID','reason'=>'ثبت تکراری');
$s->fail=true; try { joma_v2_revise_expense($s,$void,$admin); throw new Exception('Expected failure'); } catch (RuntimeException $e) {}
same((int)$s->db->query('SELECT COUNT(*) FROM revisions')->fetchColumn(),1,'revision rollback');
$s->fail=false; same(joma_v2_revise_expense($s,$void,$admin),2,'void saved');
$void['key']='revision_0000000003'; $void['expected_revision_id']=2;
rejects(function()use($s,$void,$admin){joma_v2_revise_expense($s,$void,$admin);},'already void');
echo "Expense recording and revision tests passed (SQLite; no MySQL locking).\n";
