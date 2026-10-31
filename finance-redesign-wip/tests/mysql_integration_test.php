<?php
/* SYNTHETIC TEST DATABASE ONLY. Never use a production database. */
$dbname = getenv('JOMA_TEST_DATABASE');
if (!is_string($dbname) || !preg_match('/^joma_v2_test_[a-z0-9_]+$/D', $dbname) || getenv('JOMA_TEST_ALLOW_WRITES') !== 'YES' || !in_array(getenv('JOMA_TEST_HOST'), array('127.0.0.1','localhost'), true)) {
    throw new Exception('Refusing to run without an explicitly named joma_v2_test_* database');
}
$helpers = getenv('JOMA_TEST_DB_HELPERS');
if (!is_string($helpers) || !is_file($helpers)) throw new Exception('Set JOMA_TEST_DB_HELPERS to the existing app db.php');
require $helpers;
require __DIR__ . '/../src/rates.php';
require __DIR__ . '/../src/expenses.php';
require __DIR__ . '/../src/request_wallet.php';
require __DIR__ . '/../src/assign_rate.php';
require __DIR__ . '/../src/center_basis.php';
require __DIR__ . '/../src/center_settlement.php';
require __DIR__ . '/../src/storage/mysqli_center_settlement_store.php';
require __DIR__ . '/../src/storage/mysqli_center_basis_store.php';
require __DIR__ . '/../src/storage/mysqli_request_wallet_store.php';
require __DIR__ . '/../src/storage/mysqli_store.php';
require __DIR__ . '/../src/storage/mysqli_rate_store.php';
require __DIR__ . '/../src/storage/mysqli_expense_store.php';
require __DIR__ . '/../src/storage/mysqli_report_store.php';
function now_dt() { return gmdate('Y-m-d H:i:s'); }
function generate_public_id($prefix) { return $prefix . bin2hex(random_bytes(8)); }
function audit_log_write($db,$actor,$role,$action,$type,$id,$data) { /* synthetic audit stub */ }
function local_day_bounds_utc($date) { $d=new DateTimeImmutable($date.' 00:00:00',new DateTimeZone('Asia/Tehran')); return array('start'=>$d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')); }
function date_add_days($date,$days) { return (new DateTimeImmutable($date,new DateTimeZone('UTC')))->modify(($days>=0?'+':'').$days.' days')->format('Y-m-d'); }

function same($a,$b,$message) { if ($a !== $b) throw new Exception($message.': '.var_export($a,true)); }
function rejects($f,$message) { try { $f(); } catch (InvalidArgumentException $e) { return; } throw new Exception('Expected rejection: '.$message); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = mysqli_connect(getenv('JOMA_TEST_HOST'), getenv('JOMA_TEST_USER'), getenv('JOMA_TEST_PASSWORD'), $dbname, (int)getenv('JOMA_TEST_PORT'));
mysqli_set_charset($db, 'utf8mb4');
$admin = array('person_id'=>2,'role'=>'admin'); $secretary = array('person_id'=>3,'role'=>'secretary');
$rate = new JomaV2MysqliRateStore($db);
joma_v2_set_rate($rate,1,1,120000,true,$admin);
mysqli_query($db,'START TRANSACTION');
same(joma_v2_capture_booking_rate($rate,1),true,'rate captured');
mysqli_query($db,'COMMIT');
joma_v2_set_rate($rate,1,1,150000,true,$admin);
$row = db_select_one($db,'SELECT id, rate_rial_per_minute FROM finance_v2_wallets WHERE appointment_id=1');
same((int)$row['rate_rial_per_minute'],120000,'snapshot immutable'); $appt=(int)$row['id'];
$requestStore=new JomaV2MysqliRequestWalletStore($db);
$req=joma_v2_request_wallet($requestStore,1,$secretary);
same(joma_v2_request_wallet($requestStore,1,$secretary),$req,'request wallet idempotent');
rejects(function() use($requestStore,$secretary){joma_v2_request_wallet($requestStore,999,$secretary);},'unknown request');
$store=new JomaV2MysqliStore($db);
$deposit=array('key'=>'deposit_000000000001','type'=>'RECEIPT','wallet'=>$req,'amount_rial'=>1000000,'account_id'=>1,'receipt_kind'=>'DEPOSIT');
joma_v2_record_event($store,$deposit,$secretary);
rejects(function() use($store,$deposit,$secretary){joma_v2_record_event($store,$deposit,$secretary);},'duplicate receipt');
joma_v2_record_event($store,array('key'=>'transfer_00000000001','type'=>'TRANSFER','wallet'=>$req,'to'=>$appt,'amount_rial'=>1000000,'reason'=>'deposit allocation'),$secretary);
joma_v2_record_event($store,array('key'=>'charge_000000000001','type'=>'CHARGE','wallet'=>$appt,'actual_minutes'=>35),$secretary);
joma_v2_record_event($store,array('key'=>'payment_00000000001','type'=>'RECEIPT','wallet'=>$appt,'amount_rial'=>2000000,'account_id'=>1,'receipt_kind'=>'PAYMENT'),$secretary);
joma_v2_record_event($store,array('key'=>'refund_000000000001','type'=>'REFUND','wallet'=>$appt,'amount_rial'=>500000,'account_id'=>1,'reason'=>'correction'),$secretary);
mysqli_query($db,'START TRANSACTION'); $store->lockWallets(array($appt)); $balance=$store->balance($appt); mysqli_query($db,'COMMIT');
same($balance['received'],2500000,'allocated receipts'); same($balance['charge'],4200000,'calculated fee');
rejects(function() use($store,$secretary,$appt){joma_v2_record_event($store,array('key'=>'overrefund_00000001','type'=>'REFUND','wallet'=>$appt,'amount_rial'=>2500001,'account_id'=>1,'reason'=>'x'),$secretary);},'excess refund');
joma_v2_set_rate($rate,1,1,150000,false,$admin);
mysqli_query($db,'START TRANSACTION');
same(joma_v2_capture_booking_rate($rate,3),false,'inactive rate leaves a blank snapshot');
mysqli_query($db,'COMMIT');
$missing=db_select_one($db,'SELECT id,rate_rial_per_minute FROM finance_v2_wallets WHERE appointment_id=3');
same($missing['rate_rial_per_minute'],null,'missing rate never guessed');
$missingId=(int)$missing['id'];
rejects(function() use($store,$missingId,$secretary){joma_v2_assign_missing_rate($store,$missingId,135000,'manual confirmation',$secretary);},'rate assignment admin only');
joma_v2_assign_missing_rate($store,$missingId,135000,'manual confirmation',$admin);
rejects(function() use($store,$missingId,$admin){joma_v2_assign_missing_rate($store,$missingId,150000,'change',$admin);},'snapshot cannot be overwritten');
$changed=db_select_one($db,'SELECT rate_rial_per_minute FROM finance_v2_wallets WHERE id=?','i',array($missingId));
same((int)$changed['rate_rial_per_minute'],135000,'manual rate saved exactly');
$log=db_select_one($db,'SELECT reason FROM finance_v2_rate_assignments WHERE wallet_id=?','i',array($missingId));
same($log['reason'],'manual confirmation','manual rate assignment audited');
$centers=new JomaV2MysqliCenterBasisStore($db);
joma_v2_set_center_basis($centers,1,'ACTUAL',$admin);
joma_v2_set_center_basis($centers,1,'ACTUAL',$admin);
rejects(function()use($centers,$secretary){joma_v2_set_center_basis($centers,1,'RESERVED',$secretary);},'center admin only');
rejects(function()use($centers,$admin){joma_v2_set_center_basis($centers,2,'ACTUAL',$admin);},'inactive center');
joma_v2_set_center_basis($centers,1,'RESERVED',$admin);
$basis=db_select_one($db,'SELECT time_basis FROM finance_v2_center_basis WHERE center_id=1');
same($basis['time_basis'],'RESERVED','center basis updated');
$hist=db_select_one($db,'SELECT COUNT(*) AS n FROM finance_v2_center_basis_events WHERE center_id=1');
same((int)$hist['n'],2,'center basis changes logged once');
$settlementStore=new JomaV2MysqliCenterSettlementStore($db);
joma_v2_set_center_basis($centers,1,'ACTUAL',$admin);
$settled=joma_v2_create_hourly_settlement($settlementStore,1,'2026-10-20','2026-10-20',null,'',$admin);
same($settled['amount_due_rial'],350000,'actual-minute hourly settlement');
$detail=db_select_one($db,'SELECT time_basis,total_minutes,exact_remainder FROM finance_v2_center_settlement_details WHERE settlement_id=?','i',array($settled['settlement_id']));
same($detail['time_basis'],'ACTUAL','basis persisted'); same((int)$detail['total_minutes'],35,'actual minutes persisted');
$item=db_select_one($db,'SELECT minutes,source_charge_event_id FROM finance_v2_center_settlement_items WHERE settlement_id=?','i',array($settled['settlement_id']));
same((int)$item['minutes'],35,'item minutes persisted'); if (!$item['source_charge_event_id']) throw new Exception('Missing charge event reference');
rejects(function()use($settlementStore,$admin){joma_v2_create_hourly_settlement($settlementStore,1,'2026-10-20','2026-10-21',null,'',$admin);},'overlapping settlement');
rejects(function()use($settlementStore,$admin){joma_v2_create_hourly_settlement($settlementStore,1,'2026-10-21','2026-10-21',null,'',$admin);},'missing actual duration');
joma_v2_set_center_basis($centers,1,'RESERVED',$admin);
rejects(function()use($settlementStore,$admin){joma_v2_create_hourly_settlement($settlementStore,1,'2026-10-21','2026-10-21',null,'',$admin);},'fraction needs decision');
$manual=joma_v2_create_hourly_settlement($settlementStore,1,'2026-10-21','2026-10-21',583330,'agreed amount',$admin);
same($manual['amount_due_rial'],583330,'manual settlement');
$detail=db_select_one($db,'SELECT exact_whole_rial,exact_remainder,exact_denominator,manual_reason FROM finance_v2_center_settlement_details WHERE settlement_id=?','i',array($manual['settlement_id']));
same((int)$detail['exact_whole_rial'],583333,'exact total retained'); same((int)$detail['exact_remainder'],200,'fraction retained'); same((int)$detail['exact_denominator'],600,'denominator retained'); same($detail['manual_reason'],'agreed amount','reason retained');
db_execute($db,"INSERT INTO appointments VALUES (6,1,1,1,'COMPLETED',NULL,'2026-10-24 09:00:00',1,60)");
class FailingCenterStore extends JomaV2MysqliCenterSettlementStore {
    function insertSettlementItem($id,$basis,$row) { parent::insertSettlementItem($id,$basis,$row); throw new RuntimeException('Simulated item failure'); }
}
try { joma_v2_create_hourly_settlement(new FailingCenterStore($db),1,'2026-10-24','2026-10-24',null,'',$admin); throw new Exception('Expected failure'); }
catch (RuntimeException $ex) {}
$rolledBack=db_select_one($db,"SELECT COUNT(*) AS n FROM finance_center_settlements WHERE center_id=1 AND period_start='2026-10-24'");
same((int)$rolledBack['n'],0,'failed settlement fully rolled back');
$expenseStore=new JomaV2MysqliExpenseStore($db);
joma_v2_record_expense($expenseStore,array('key'=>'expense_00000000001','date'=>'2026-10-20','category'=>'اجاره','amount_rial'=>10000,'account_id'=>1),$admin);
$report=(new JomaV2MysqliReportStore($db))->build('2000-01-01 00:00:00','2100-01-01 00:00:00','2000-01-01','2100-01-01');
same($report['cash_received_rial'],3000000,'cash receipts'); same($report['cash_refunded_rial'],500000,'cash refund');
same($report['session_charge_changes_rial'],4200000,'charge report'); same($report['expenses_rial'],10000,'expense report'); same($report['appointment_due_rial'],1700000,'debt report');
echo "MariaDB/MySQL synthetic integration passed.\n";
