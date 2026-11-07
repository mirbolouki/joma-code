<?php
$name=getenv('JOMA_TEST_DATABASE');
if (!is_string($name)||!preg_match('/^joma_v2_test_[a-z0-9_]+$/D',$name)||getenv('JOMA_TEST_ALLOW_WRITES')!=='YES'||!in_array(getenv('JOMA_TEST_HOST'),array('127.0.0.1','localhost'),true)) throw new Exception('Refusing non-test database');
require getenv('JOMA_TEST_DB_HELPERS'); require __DIR__.'/../src/center_balance.php'; require __DIR__.'/../src/storage/mysqli_center_balance_store.php'; require __DIR__.'/../src/storage/mysqli_store.php';
function now_dt() { return gmdate('Y-m-d H:i:s'); }
class SlowCenterBalanceStore extends JomaV2MysqliCenterBalanceStore {
    function lockEligibleSettlement($id) { $result=parent::lockEligibleSettlement($id); usleep(800000); return $result; }
}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=mysqli_connect(getenv('JOMA_TEST_HOST'),getenv('JOMA_TEST_USER'),getenv('JOMA_TEST_PASSWORD'),$name,(int)getenv('JOMA_TEST_PORT')); mysqli_set_charset($db,'utf8mb4');
$s=new SlowCenterBalanceStore($db); $actor=array('person_id'=>2,'role'=>'admin');
$key='race_center_'.bin2hex(random_bytes(8));
try {
    if (getenv('JOMA_MODE')==='RESOLVE') joma_v2_resolve_percentage_late($s,array('key'=>$key,'late_event_id'=>(int)getenv('JOMA_LATE_ID'),'delta_rial'=>5000,'reason'=>'concurrent synthetic agreement'),$actor);
    elseif (getenv('JOMA_MODE')==='PAYMENT') joma_v2_record_center_cash($s,array('key'=>$key,'settlement_id'=>(int)getenv('JOMA_SETTLEMENT_ID'),'kind'=>'PAYMENT','amount_rial'=>(int)getenv('JOMA_AMOUNT'),'account_id'=>1,'reason'=>'concurrent synthetic payment'),$actor);
    elseif (getenv('JOMA_MODE')==='RECEIPT') joma_v2_record_event(new JomaV2MysqliStore($db),array('key'=>$key,'type'=>'RECEIPT','wallet'=>(int)getenv('JOMA_WALLET_ID'),'amount_rial'=>5000,'account_id'=>1,'receipt_kind'=>'PAYMENT'),array('person_id'=>3,'role'=>'secretary'));
    else throw new Exception('Unknown mode');
    echo "ACCEPT\n";
} catch (InvalidArgumentException $e) { echo "REJECT\n"; }
