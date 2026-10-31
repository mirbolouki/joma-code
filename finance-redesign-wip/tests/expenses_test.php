<?php
/* SQLite test adapter; does not cover MySQL implementation or locking. */
require __DIR__ . '/../src/expenses.php';
class ExpenseTestStore {
    public $db; public $fail = false;
    function __construct() { $this->db=new PDO('sqlite::memory:'); $this->db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $this->db->exec('CREATE TABLE expenses(id INTEGER PRIMARY KEY, operation_key TEXT UNIQUE, expense_date TEXT, category TEXT, amount_rial INTEGER, account_id INTEGER, description TEXT, recorded_by_person_id INTEGER)'); }
    function begin() { $this->db->beginTransaction(); }
    function commit() { $this->db->commit(); }
    function rollback() { $this->db->rollBack(); }
    function expenseOperationExists($key) { $s=$this->db->prepare('SELECT id FROM expenses WHERE operation_key=?'); $s->execute(array($key)); return (bool)$s->fetch(); }
    function expenseAccountActive($id) { return $id === 5; }
    function insertExpense($r) { $columns=array_keys($r); $s=$this->db->prepare('INSERT INTO expenses('.implode(',',$columns).') VALUES('.implode(',',array_fill(0,count($columns),'?')).')'); $s->execute(array_values($r)); if ($this->fail) throw new RuntimeException('Simulated failure'); return (int)$this->db->lastInsertId(); }
}
function same($a,$b,$message) { if ($a !== $b) throw new Exception($message . ': ' . var_export($a,true)); }
function rejects($f,$message) { try { $f(); } catch (InvalidArgumentException $e) { return; } throw new Exception('Expected rejection: '.$message); }
$s=new ExpenseTestStore(); $admin=array('person_id'=>1,'role'=>'admin');
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
echo "Expense recording tests passed (SQLite; no MySQL locking).\n";
