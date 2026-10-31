<?php
/* Test-only worker. It may only write a joma_v2_test_* database. */
$name=getenv('JOMA_TEST_DATABASE');
if (!is_string($name) || !preg_match('/^joma_v2_test_[a-z0-9_]+$/D',$name) || getenv('JOMA_TEST_ALLOW_WRITES') !== 'YES') throw new Exception('Refusing non-test database');
require getenv('JOMA_TEST_DB_HELPERS');
require __DIR__ . '/../src/record_event.php';
require __DIR__ . '/../src/storage/mysqli_store.php';
function now_dt() { return gmdate('Y-m-d H:i:s'); }
class SlowRaceStore extends JomaV2MysqliStore {
    function lockWallets($ids) { $result=parent::lockWallets($ids); usleep(800000); return $result; }
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=mysqli_connect(getenv('JOMA_TEST_HOST'),getenv('JOMA_TEST_USER'),getenv('JOMA_TEST_PASSWORD'),$name,(int)getenv('JOMA_TEST_PORT'));
mysqli_set_charset($db,'utf8mb4');
$source=(int)getenv('JOMA_RACE_SOURCE'); $target=(int)getenv('JOMA_RACE_TARGET'); $key=getenv('JOMA_RACE_KEY');
try {
    joma_v2_record_event(new SlowRaceStore($db),array('key'=>$key,'type'=>'TRANSFER','wallet'=>$source,'to'=>$target,'amount_rial'=>700,'reason'=>'race test'),array('person_id'=>3,'role'=>'secretary'));
    echo "ACCEPT\n";
} catch (InvalidArgumentException $e) { echo "REJECT\n"; }
