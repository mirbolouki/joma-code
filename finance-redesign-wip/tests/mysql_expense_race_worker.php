<?php
$name=getenv('JOMA_TEST_DATABASE');
if (!is_string($name) || !preg_match('/^joma_v2_test_[a-z0-9_]+$/D',$name) || getenv('JOMA_TEST_ALLOW_WRITES') !== 'YES' || !in_array(getenv('JOMA_TEST_HOST'),array('127.0.0.1','localhost'),true)) throw new Exception('Refusing non-test database');
require getenv('JOMA_TEST_DB_HELPERS');
require __DIR__ . '/../src/expenses.php';
require __DIR__ . '/../src/storage/mysqli_expense_store.php';
function now_dt() { return gmdate('Y-m-d H:i:s'); }
class SlowExpenseStore extends JomaV2MysqliExpenseStore {
    function lockExpense($id) { $row=parent::lockExpense($id); usleep(800000); return $row; }
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=mysqli_connect(getenv('JOMA_TEST_HOST'),getenv('JOMA_TEST_USER'),getenv('JOMA_TEST_PASSWORD'),$name,(int)getenv('JOMA_TEST_PORT'));
mysqli_set_charset($db,'utf8mb4');
$change=array('key'=>getenv('JOMA_RACE_KEY'),'expense_id'=>(int)getenv('JOMA_RACE_EXPENSE'),'expected_revision_id'=>0,'action'=>'CORRECT',
    'date'=>'2026-10-20','category'=>'corrected','amount_rial'=>2000,'account_id'=>1,'reason'=>'race test');
try { joma_v2_revise_expense(new SlowExpenseStore($db),$change,array('person_id'=>2,'role'=>'admin')); echo "ACCEPT\n"; }
catch (InvalidArgumentException $e) { echo "REJECT\n"; }
