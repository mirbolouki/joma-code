<?php
/* Local fabricated schema and data only, never run against the real site. */
$name=getenv('JOMA_TEST_DATABASE');
if (!is_string($name) || !preg_match('/^joma_v2_test_[a-z0-9_]+$/D',$name) || getenv('JOMA_TEST_ALLOW_WRITES') !== 'YES' || !in_array(getenv('JOMA_TEST_HOST'),array('127.0.0.1','localhost'),true)) throw new Exception('Refusing non-test database');
require getenv('JOMA_TEST_DB_HELPERS');
require __DIR__ . '/../src/rates.php';
require __DIR__ . '/../src/request_wallet.php';
require __DIR__ . '/../src/storage/mysqli_rate_store.php';
require __DIR__ . '/../src/storage/mysqli_request_wallet_store.php';
require __DIR__ . '/../src/storage/mysqli_store.php';
require __DIR__ . '/../src/storage/mysqli_report_store.php';
function now_dt() { return gmdate('Y-m-d H:i:s'); }
function generate_public_id($prefix) { return $prefix.bin2hex(random_bytes(8)); }
function audit_log_write($db,$actor,$role,$action,$type,$id,$data) {}
function same($value,$expected,$message) { if ($value !== $expected) throw new Exception($message.': '.var_export($value,true)); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=mysqli_connect(getenv('JOMA_TEST_HOST'),getenv('JOMA_TEST_USER'),getenv('JOMA_TEST_PASSWORD'),$name,(int)getenv('JOMA_TEST_PORT'));
mysqli_set_charset($db,'utf8mb4');
$admin=array('person_id'=>2,'role'=>'admin'); $secretary=array('person_id'=>3,'role'=>'secretary');
$rates=new JomaV2MysqliRateStore($db); joma_v2_set_rate($rates,1,1,10000,true,$admin);
mysqli_query($db,'START TRANSACTION'); same(joma_v2_capture_booking_rate($rates,1),true,'snapshot'); mysqli_query($db,'COMMIT');
$appointment=db_select_one($db,'SELECT id FROM finance_v2_wallets WHERE appointment_id=1'); $appointment=(int)$appointment['id'];
$request=joma_v2_request_wallet(new JomaV2MysqliRequestWalletStore($db),1,$secretary);
$store=new JomaV2MysqliStore($db);
joma_v2_record_event($store,array('key'=>'schema_deposit_00001','type'=>'RECEIPT','wallet'=>$request,'amount_rial'=>100000,'account_id'=>1,'receipt_kind'=>'DEPOSIT'),$secretary);
joma_v2_record_event($store,array('key'=>'schema_transfer_0001','type'=>'TRANSFER','wallet'=>$request,'to'=>$appointment,'amount_rial'=>100000,'reason'=>'allocation'),$secretary);
joma_v2_record_event($store,array('key'=>'schema_charge_000001','type'=>'CHARGE','wallet'=>$appointment,'actual_minutes'=>45,'actual_session_date'=>'2026-10-19'),$secretary);
$report=(new JomaV2MysqliReportStore($db))->build('2000-01-01 00:00:00','2100-01-01 00:00:00','2026-10-19','2026-10-20');
same($report['actual_session_charges_rial'],450000,'actual session report'); same($report['cash_received_rial'],100000,'receipt report');
echo "Reference schema smoke test passed with fabricated data.\n";
