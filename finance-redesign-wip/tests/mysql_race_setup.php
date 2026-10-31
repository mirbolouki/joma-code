<?php
$name=getenv('JOMA_TEST_DATABASE');
if (!is_string($name) || !preg_match('/^joma_v2_test_[a-z0-9_]+$/D',$name) || getenv('JOMA_TEST_ALLOW_WRITES') !== 'YES' || !in_array(getenv('JOMA_TEST_HOST'), array('127.0.0.1','localhost'), true)) throw new Exception('Refusing non-test database');
require getenv('JOMA_TEST_DB_HELPERS');
require __DIR__ . '/../src/record_event.php';
require __DIR__ . '/../src/storage/mysqli_store.php';
function now_dt() { return gmdate('Y-m-d H:i:s'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=mysqli_connect(getenv('JOMA_TEST_HOST'),getenv('JOMA_TEST_USER'),getenv('JOMA_TEST_PASSWORD'),$name,(int)getenv('JOMA_TEST_PORT'));
mysqli_set_charset($db,'utf8mb4');
db_execute($db,'UPDATE booking_requests SET converted_admission_id=1 WHERE id=2');
$r=db_execute($db,'INSERT INTO finance_v2_wallets (request_id,created_at) VALUES (?,?)','is',array(2,now_dt())); $source=(int)$r['insert_id'];
$row=db_select_one($db,'SELECT id FROM finance_v2_wallets WHERE appointment_id=3');
if (!$row) { $r=db_execute($db,'INSERT INTO finance_v2_wallets (appointment_id,rate_therapist_person_id,rate_service_type_id,rate_rial_per_minute,created_at) VALUES (?,?,?,?,?)','iiiss',array(3,1,1,'120000',now_dt())); $target=(int)$r['insert_id']; } else { $target=(int)$row['id']; }
$existing=db_select_one($db,'SELECT id FROM finance_v2_wallets WHERE appointment_id=1');
joma_v2_record_event(new JomaV2MysqliStore($db),array('key'=>'race_deposit_000001','type'=>'RECEIPT','wallet'=>$source,'amount_rial'=>1000,'account_id'=>1,'receipt_kind'=>'DEPOSIT'),array('person_id'=>3,'role'=>'secretary'));
echo json_encode(array('source'=>$source,'target1'=>(int)$existing['id'],'target2'=>$target)),"\n";
