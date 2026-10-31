<?php
$name=getenv('JOMA_TEST_DATABASE');
if (!is_string($name)||!preg_match('/^joma_v2_test_[a-z0-9_]+$/D',$name)||getenv('JOMA_TEST_ALLOW_WRITES')!=='YES'||!in_array(getenv('JOMA_TEST_HOST'),array('127.0.0.1','localhost'),true)) throw new Exception('Refusing non-test database');
require getenv('JOMA_TEST_DB_HELPERS'); require __DIR__.'/../src/percentage_settlement.php'; require __DIR__.'/../src/storage/mysqli_percentage_settlement_store.php'; require __DIR__.'/../src/storage/mysqli_store.php';
function now_dt() { return gmdate('Y-m-d H:i:s'); }
function generate_public_id($prefix) { return $prefix.bin2hex(random_bytes(8)); }
function audit_log_write($db,$actor,$role,$action,$type,$id,$data) {}
function local_day_bounds_utc($date) { $d=new DateTimeImmutable($date.' 00:00:00',new DateTimeZone('Asia/Tehran')); return array('start'=>$d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')); }
function date_add_days($date,$days) { return (new DateTimeImmutable($date,new DateTimeZone('UTC')))->modify(($days>=0?'+':'').$days.' days')->format('Y-m-d'); }
class SlowPercentSettlement extends JomaV2MysqliPercentageSettlementStore { function lockLedger() { parent::lockLedger(); usleep(800000); } }
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=mysqli_connect(getenv('JOMA_TEST_HOST'),getenv('JOMA_TEST_USER'),getenv('JOMA_TEST_PASSWORD'),$name,(int)getenv('JOMA_TEST_PORT')); mysqli_set_charset($db,'utf8mb4');
if (getenv('JOMA_MODE')==='SETTLE') {
    $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
    $result=joma_v2_create_percentage_settlement(new SlowPercentSettlement($db),1,$today,$today,null,'',array('person_id'=>2,'role'=>'admin'));
    echo 'SETTLED:',(int)$result['settlement_id'],"\n";
} elseif (getenv('JOMA_MODE')==='RECEIPT') {
    $id=joma_v2_record_event(new JomaV2MysqliStore($db),array('key'=>'percentage_race_event_001','type'=>'RECEIPT','wallet'=>1,'amount_rial'=>2000,'account_id'=>1,'receipt_kind'=>'PAYMENT'),array('person_id'=>3,'role'=>'secretary'));
    echo 'RECEIPT:',(int)$id,"\n";
} else throw new Exception('Invalid race mode');
