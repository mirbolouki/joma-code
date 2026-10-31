<?php
/* Test DDL/data writes only on an explicitly named local synthetic database. */
$name=getenv('JOMA_TEST_DATABASE');
if (!is_string($name) || !preg_match('/^joma_v2_test_[a-z0-9_]+$/D',$name) || getenv('JOMA_TEST_ALLOW_WRITES')!=='YES' || !in_array(getenv('JOMA_TEST_HOST'),array('127.0.0.1','localhost'),true)) throw new Exception('Refusing non-test database');
require getenv('JOMA_TEST_DB_HELPERS');
require __DIR__ . '/../src/legacy_center_review.php';
require __DIR__ . '/../src/center_percentage.php';
require __DIR__ . '/../src/storage/mysqli_legacy_center_review_store.php';
function now_dt() { return gmdate('Y-m-d H:i:s'); }
function same($a,$b,$m) { if ($a!==$b) throw new Exception($m.': '.var_export($a,true)); }
function rejects($f,$m) { try {$f();} catch (InvalidArgumentException $e) {return;} throw new Exception('Expected rejection: '.$m); }
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=mysqli_connect(getenv('JOMA_TEST_HOST'),getenv('JOMA_TEST_USER'),getenv('JOMA_TEST_PASSWORD'),$name,(int)getenv('JOMA_TEST_PORT')); mysqli_set_charset($db,'utf8mb4');
db_execute($db,"INSERT INTO finance_session_payments (id,public_id,appointment_id,session_fee_rial,deposit_amount_rial,deposit_bank_account_id,deposit_status,deposit_paid_at,created_at) VALUES (1,'LEGACY1',1,1000,500,1,'PAID','2026-10-01 12:00:00',NOW())");
function payment($db) { return db_select_one($db,'SELECT id,appointment_id,session_fee_rial,deposit_amount_rial,deposit_bank_account_id,deposit_status,deposit_paid_at,balance_amount_rial,balance_bank_account_id,balance_status,balance_paid_at FROM finance_session_payments WHERE id=1'); }
$p=payment($db); $s=new JomaV2MysqliLegacyCenterReviewStore($db); $admin=array('person_id'=>2,'role'=>'admin');
$input=array('payment_id'=>1,'deposit_center_id'=>1,'deposit_center_confirmed'=>true,'balance_center_id'=>null,'balance_center_confirmed'=>false,'fingerprint'=>joma_v2_legacy_payment_fingerprint($p),'expected_review_id'=>0,'reason'=>'verified source');
$id=joma_v2_review_legacy_center($s,$input,$admin); same($id,1,'review created');
$display=$s->findPayment(1); same((int)$display['review_id'],1,'latest review shown'); same($display['appointment_public'],'AP1','appointment displayed'); same((int)$display['has_new_history'],0,'no mixed history shown');
same(count($s->listPayments()),1,'payment list'); same(count($s->listPayments(1)),0,'cursor hides newer payment'); same(count($s->centers()),1,'center list'); same(count($s->history(1)),1,'review history');
$review=db_select_one($db,'SELECT * FROM finance_v2_legacy_center_reviews WHERE id=?','i',array($id));
same(joma_v2_legacy_review_is_current($p,$review,false),true,'review matches');
same(json_decode($review['payment_snapshot_json'],true)['deposit_amount_rial'],'500','original snapshot retained');
$legacy=joma_v2_legacy_review_percentage_record($p,$review,false); $items=joma_v2_percentage_sources(1,'2026-10-01 00:00:00','2026-11-01 00:00:00',array(),array($legacy));
$sum=joma_v2_center_percentage_exact('10.00',$items); same($sum['gross_rial'],500,'verified legacy input to percentage');
rejects(function()use($s,$input,$admin){joma_v2_review_legacy_center($s,$input,$admin);},'stale review');
db_execute($db,'UPDATE finance_session_payments SET deposit_amount_rial=550 WHERE id=1'); $changed=payment($db);
same(joma_v2_legacy_review_is_current($changed,$review,false),false,'changed amount invalidates');
$legacy=joma_v2_legacy_review_percentage_record($changed,$review,false); rejects(function()use($legacy){joma_v2_percentage_sources(1,'2026-10-01 00:00:00','2026-11-01 00:00:00',array(),array($legacy));},'stale verification blocks calculation');
$next=$input; $next['expected_review_id']=$id;
rejects(function()use($s,$next,$admin){joma_v2_review_legacy_center($s,$next,$admin);},'stale fingerprint');
$next['fingerprint']=joma_v2_legacy_payment_fingerprint($changed); $next['deposit_center_id']=null; $next['reason']='verified no center';
$id2=joma_v2_review_legacy_center($s,$next,$admin); same($id2,2,'new revision');
$old=db_select_one($db,'SELECT payment_snapshot_json FROM finance_v2_legacy_center_reviews WHERE id=1');
same(json_decode($old['payment_snapshot_json'],true)['deposit_amount_rial'],'500','old evidence not overwritten');
// Deposit and remaining payment may belong to different centers.
db_execute($db,"INSERT INTO finance_centers (id,public_id,name,commission_model,created_at) VALUES (2,'C2','Synthetic other','PERCENTAGE',NOW())");
db_execute($db,"UPDATE finance_session_payments SET balance_status='PAID',balance_amount_rial=300,balance_bank_account_id=1,balance_paid_at='2026-10-02 12:00:00' WHERE id=1");
$both=payment($db); $next['fingerprint']=joma_v2_legacy_payment_fingerprint($both); $next['expected_review_id']=$id2;
$next['deposit_center_id']=1; $next['deposit_center_confirmed']=true; $next['balance_center_id']=2; $next['balance_center_confirmed']=true; $next['reason']='two verified centers';
$id3=joma_v2_review_legacy_center($s,$next,$admin);
$split=db_select_one($db,'SELECT * FROM finance_v2_legacy_center_reviews WHERE id=?','i',array($id3));
$record=joma_v2_legacy_review_percentage_record($both,$split,false);
$dep=joma_v2_center_percentage_exact('10.00',joma_v2_percentage_sources(1,'2026-10-01 00:00:00','2026-11-01 00:00:00',array(),array($record)));
$bal=joma_v2_center_percentage_exact('10.00',joma_v2_percentage_sources(2,'2026-10-01 00:00:00','2026-11-01 00:00:00',array(),array($record)));
same($dep['gross_rial'],550,'deposit center separate'); same($bal['gross_rial'],300,'balance center separate');
// Fabricate a mixed old/new history. Neither automatic reconciliation nor a
// fresh human center review is allowed to silently merge the histories.
$r=db_execute($db,'INSERT INTO finance_v2_wallets (appointment_id,created_at) VALUES (?,?)','is',array(1,now_dt())); $wallet=(int)$r['insert_id'];
db_execute($db,"INSERT INTO finance_v2_events (operation_key,event_type,receipt_kind,wallet_id,center_snapshot_recorded,source_center_id,amount_rial,account_id,occurred_at,recorded_at,recorded_by_person_id,recorded_by_role_code) VALUES ('mixed_event_0000001','RECEIPT','PAYMENT',?,1,1,1,1,NOW(),NOW(),2,'admin')",'i',array($wallet));
$display=$s->findPayment(1); same((int)$display['has_new_history'],1,'mixed history displayed');
$next['expected_review_id']=$id3; rejects(function()use($s,$next,$admin){joma_v2_review_legacy_center($s,$next,$admin);},'mixed history rejected');
$count=db_select_one($db,'SELECT COUNT(*) AS n FROM finance_v2_legacy_center_reviews WHERE payment_id=1'); same((int)$count['n'],3,'no partial review');
echo "Legacy review on reference MySQL schema passed.\n";
