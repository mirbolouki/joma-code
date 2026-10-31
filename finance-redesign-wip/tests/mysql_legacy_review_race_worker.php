<?php
$name=getenv('JOMA_TEST_DATABASE');
if (!is_string($name)||!preg_match('/^joma_v2_test_[a-z0-9_]+$/D',$name)||getenv('JOMA_TEST_ALLOW_WRITES')!=='YES'||!in_array(getenv('JOMA_TEST_HOST'),array('127.0.0.1','localhost'),true)) throw new Exception('Refusing non-test database');
require getenv('JOMA_TEST_DB_HELPERS'); require __DIR__.'/../src/legacy_center_review.php'; require __DIR__.'/../src/storage/mysqli_legacy_center_review_store.php';
function now_dt() { return gmdate('Y-m-d H:i:s'); }
class SlowLegacyReviewStore extends JomaV2MysqliLegacyCenterReviewStore {
    function lockPaymentAndWallet($id) { $p=parent::lockPaymentAndWallet($id); usleep(800000); return $p; }
}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=mysqli_connect(getenv('JOMA_TEST_HOST'),getenv('JOMA_TEST_USER'),getenv('JOMA_TEST_PASSWORD'),$name,(int)getenv('JOMA_TEST_PORT')); mysqli_set_charset($db,'utf8mb4');
$s=new SlowLegacyReviewStore($db); $p=$s->findPayment(1);
$input=array('payment_id'=>1,'deposit_center_id'=>1,'deposit_center_confirmed'=>true,'balance_center_id'=>null,'balance_center_confirmed'=>false,
    'fingerprint'=>joma_v2_legacy_payment_fingerprint($p),'expected_review_id'=>0,'reason'=>'synthetic concurrent review');
try { joma_v2_review_legacy_center($s,$input,array('person_id'=>2,'role'=>'admin')); echo "ACCEPT\n"; }
catch (InvalidArgumentException $e) { echo "REJECT\n"; }
