<?php
/* Synthetic test database only; never run on a real database. */
$name=getenv('JOMA_TEST_DATABASE');
if (!is_string($name)||!preg_match('/^joma_v2_test_[a-z0-9_]+$/D',$name)||getenv('JOMA_TEST_ALLOW_WRITES')!=='YES'||!in_array(getenv('JOMA_TEST_HOST'),array('127.0.0.1','localhost'),true)) throw new Exception('Refusing non-test database');
require getenv('JOMA_TEST_DB_HELPERS'); require __DIR__.'/../src/storage/mysqli_report_store.php';
function same($a,$b,$message) { if ($a!==$b) throw new Exception($message.': '.var_export($a,true)); }
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=mysqli_connect(getenv('JOMA_TEST_HOST'),getenv('JOMA_TEST_USER'),getenv('JOMA_TEST_PASSWORD'),$name,(int)getenv('JOMA_TEST_PORT')); mysqli_set_charset($db,'utf8mb4');
// A is entered in January, moved to February, then voided in March.
// B is entered in February for January. C is corrected both before and
// exactly at Tehran's midnight boundary. All timestamps are UTC.
db_execute($db,"INSERT INTO finance_v2_expenses (id,operation_key,expense_date,category,amount_rial,account_id,recorded_by_person_id,recorded_at) VALUES
 (1,'asof_expense_one_001','2026-01-05','synthetic',100,1,2,'2026-01-02 10:00:00'),
 (2,'asof_expense_two_001','2026-01-07','synthetic',400,1,2,'2026-02-03 10:00:00'),
 (3,'asof_expense_three_1','2026-01-06','synthetic',300,1,2,'2026-01-03 10:00:00')");
db_execute($db,"INSERT INTO finance_v2_expense_revisions (expense_id,operation_key,action,expense_date,category,amount_rial,account_id,reason,recorded_by_person_id,recorded_at) VALUES
 (1,'asof_revision_one_01','CORRECT','2026-02-05','synthetic',200,1,'synthetic',2,'2026-02-02 10:00:00'),
 (1,'asof_revision_one_02','VOID',NULL,NULL,NULL,NULL,'synthetic',2,'2026-03-02 10:00:00'),
 (3,'asof_revision_three1','CORRECT','2026-01-06','synthetic',350,1,'synthetic',2,'2026-01-31 20:29:59'),
 (3,'asof_revision_three2','CORRECT','2026-01-08','synthetic',375,1,'synthetic',2,'2026-01-31 20:30:00')");
$s=new JomaV2MysqliReportStore($db);
$jan=$s->build('2025-12-31 20:30:00','2026-01-31 20:30:00','2026-01-01','2026-02-01');
same($jan['expenses_rial'],775,'January current corrections, including late entry');
same($jan['expenses_as_of_end_rial'],450,'January cutoff excludes late insert, later correction, boundary correction and void');
$two=$s->build('2025-12-31 20:30:00','2026-02-28 20:30:00','2026-01-01','2026-03-01');
same($two['expenses_rial'],775,'current versions across two months');
same($two['expenses_as_of_end_rial'],975,'February cutoff includes correction and late insert, not future void');
$three=$s->build('2025-12-31 20:30:00','2026-03-31 20:30:00','2026-01-01','2026-04-01');
same($three['expenses_rial'],775,'current three months'); same($three['expenses_as_of_end_rial'],775,'void effective by March cutoff');
// Corrupt corrections must fail closed rather than vanish or fall back silently.
db_execute($db,"INSERT INTO finance_v2_expense_revisions (expense_id,operation_key,action,reason,recorded_by_person_id,recorded_at) VALUES (3,'asof_corrupt_000001','CORRECT','synthetic corrupt',2,'2026-01-01 00:00:00')");
try { $s->build('2025-12-31 20:30:00','2026-03-31 20:30:00','2026-01-01','2026-04-01'); throw new Exception('Expected corrupted revision rejection'); } catch (UnexpectedValueException $ex) {}
echo "Expense reporting current and cutoff views passed (synthetic database).\n";
