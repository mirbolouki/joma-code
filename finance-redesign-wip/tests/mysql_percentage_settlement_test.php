<?php
/* Synthetic DB only; no real site data. */
$name=getenv('JOMA_TEST_DATABASE');
if (!is_string($name)||!preg_match('/^joma_v2_test_[a-z0-9_]+$/D',$name)||getenv('JOMA_TEST_ALLOW_WRITES')!=='YES'||!in_array(getenv('JOMA_TEST_HOST'),array('127.0.0.1','localhost'),true)) throw new Exception('Refusing non-test database');
require getenv('JOMA_TEST_DB_HELPERS');
require __DIR__.'/../src/rates.php'; require __DIR__.'/../src/request_wallet.php'; require __DIR__.'/../src/percentage_settlement.php';
require __DIR__.'/../src/storage/mysqli_rate_store.php'; require __DIR__.'/../src/storage/mysqli_request_wallet_store.php';
require __DIR__.'/../src/storage/mysqli_store.php'; require __DIR__.'/../src/storage/mysqli_percentage_settlement_store.php';
function now_dt() { return gmdate('Y-m-d H:i:s'); }
function generate_public_id($prefix) { return $prefix.bin2hex(random_bytes(8)); }
function audit_log_write($db,$actor,$role,$action,$type,$id,$data) {}
function local_day_bounds_utc($date) { $d=new DateTimeImmutable($date.' 00:00:00',new DateTimeZone('Asia/Tehran')); return array('start'=>$d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')); }
function date_add_days($date,$days) { return (new DateTimeImmutable($date,new DateTimeZone('UTC')))->modify(($days>=0?'+':'').$days.' days')->format('Y-m-d'); }
function same($a,$b,$m) { if ($a!==$b) throw new Exception($m.': '.var_export($a,true)); }
function rejects($f,$m) { try {$f();} catch (InvalidArgumentException $e) {return;} throw new Exception('Expected rejection: '.$m); }
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=mysqli_connect(getenv('JOMA_TEST_HOST'),getenv('JOMA_TEST_USER'),getenv('JOMA_TEST_PASSWORD'),$name,(int)getenv('JOMA_TEST_PORT')); mysqli_set_charset($db,'utf8mb4');
$admin=array('person_id'=>2,'role'=>'admin'); $secretary=array('person_id'=>3,'role'=>'secretary');
db_execute($db,"UPDATE finance_centers SET commission_model='PERCENTAGE',commission_percent=10.00 WHERE id=1");
db_execute($db,"INSERT INTO appointments (id,public_id,admission_id,therapist_person_id,service_type_id,room_id,appointment_start_utc,appointment_end_utc,duration_minutes,status,created_by_person_id,created_by_role_code,created_at) VALUES (2,'AP2',1,1,1,1,'2020-10-01 09:00:00','2020-10-01 10:00:00',60,'COMPLETED',2,'admin',NOW())");
db_execute($db,"INSERT INTO finance_session_payments (id,public_id,appointment_id,session_fee_rial,deposit_amount_rial,deposit_bank_account_id,deposit_status,deposit_paid_at,balance_amount_rial,balance_bank_account_id,balance_status,balance_paid_at,created_at) VALUES (1,'LEGACY1',2,300000,100000,1,'PAID','2020-10-01 09:00:00',200000,1,'PAID','2020-10-01 10:00:00',NOW())");
$s=new JomaV2MysqliPercentageSettlementStore($db);
rejects(function()use($s,$admin){joma_v2_create_percentage_settlement($s,1,'2020-10-01','2020-10-01',null,'',$admin);},'unverified legacy');
$old=db_select_one($db,'SELECT id,appointment_id,session_fee_rial,deposit_amount_rial,deposit_bank_account_id,deposit_status,deposit_paid_at,balance_amount_rial,balance_bank_account_id,balance_status,balance_paid_at FROM finance_session_payments WHERE id=1');
$reviewStore=new JomaV2MysqliLegacyCenterReviewStore($db);
$reviewId=joma_v2_review_legacy_center($reviewStore,array('payment_id'=>1,'deposit_center_id'=>1,'deposit_center_confirmed'=>true,'balance_center_id'=>1,'balance_center_confirmed'=>true,'fingerprint'=>joma_v2_legacy_payment_fingerprint($old),'expected_review_id'=>0,'reason'=>'synthetic documents'),$admin);
$legacy=joma_v2_create_percentage_settlement($s,1,'2020-10-01','2020-10-01',null,'',$admin);
same($legacy['amount_due_rial'],30000,'legacy percent due'); same($legacy['exact']['gross_rial'],300000,'legacy base');
$oldItems=db_select_all($db,'SELECT kind,legacy_payment_id,legacy_review_id FROM finance_v2_percentage_items WHERE settlement_id=? ORDER BY id','i',array($legacy['settlement_id']));
same(count($oldItems),2,'two old payment components saved'); same((int)$oldItems[0]['legacy_review_id'],$reviewId,'review reference saved');
rejects(function()use($s,$admin){joma_v2_create_percentage_settlement($s,1,'2020-10-01','2020-10-02',null,'',$admin);},'overlap rejected');
db_execute($db,'UPDATE finance_session_payments SET deposit_amount_rial=100001 WHERE id=1');
rejects(function()use($s,$admin){joma_v2_create_percentage_settlement($s,1,'2020-10-03','2020-10-03',null,'',$admin);},'change in prior settled legacy record blocks later settlement');
db_execute($db,'UPDATE finance_session_payments SET deposit_amount_rial=100000 WHERE id=1');
$rate=new JomaV2MysqliRateStore($db); joma_v2_set_rate($rate,1,1,10000,true,$admin);
mysqli_query($db,'START TRANSACTION'); joma_v2_capture_booking_rate($rate,1); mysqli_query($db,'COMMIT');
$wallet=(int)db_select_one($db,'SELECT id FROM finance_v2_wallets WHERE appointment_id=1')['id'];
$request=joma_v2_request_wallet(new JomaV2MysqliRequestWalletStore($db),1,$secretary); $events=new JomaV2MysqliStore($db);
joma_v2_record_event($events,array('key'=>'pct_deposit_0000001','type'=>'RECEIPT','wallet'=>$request,'amount_rial'=>100000,'account_id'=>1,'receipt_kind'=>'DEPOSIT'),$secretary);
joma_v2_record_event($events,array('key'=>'pct_transfer_000001','type'=>'TRANSFER','wallet'=>$request,'to'=>$wallet,'amount_rial'=>100000,'reason'=>'allocated'),$secretary);
joma_v2_record_event($events,array('key'=>'pct_receipt_0000001','type'=>'RECEIPT','wallet'=>$wallet,'amount_rial'=>200000,'account_id'=>1,'receipt_kind'=>'PAYMENT'),$secretary);
joma_v2_record_event($events,array('key'=>'pct_refund_00000001','type'=>'REFUND','wallet'=>$wallet,'amount_rial'=>50000,'account_id'=>1,'reason'=>'refund'),$secretary);
$today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
rejects(function()use($s,$today,$admin){joma_v2_create_percentage_settlement($s,1,$today,$today,null,'',$admin);},'refund needs manual review');
class FailingPercentageStore extends JomaV2MysqliPercentageSettlementStore {
    function insertPercentageItem($id,$item) { parent::insertPercentageItem($id,$item); throw new RuntimeException('simulated failure'); }
}
try { joma_v2_create_percentage_settlement(new FailingPercentageStore($db),1,$today,$today,25000,'manual decision',$admin); throw new Exception('Expected failure'); } catch (RuntimeException $e) {}
$count=db_select_one($db,'SELECT COUNT(*) AS n FROM finance_center_settlements WHERE period_start=?','s',array($today)); same((int)$count['n'],0,'all percent records rolled back');
$current=joma_v2_create_percentage_settlement($s,1,$today,$today,25000,'manual decision',$admin);
same($current['amount_due_rial'],25000,'manual agreement'); same($current['exact']['gross_rial'],300000,'transfer and receipt, no double deposit'); same(count($current['exact']['manual_items']),1,'refund marked');
$detail=db_select_one($db,'SELECT gross_rial,exact_whole_rial,manual_item_count,manual_reason FROM finance_v2_percentage_details WHERE settlement_id=?','i',array($current['settlement_id']));
same((int)$detail['gross_rial'],300000,'base persisted'); same((int)$detail['exact_whole_rial'],30000,'calculation persisted'); same((int)$detail['manual_item_count'],1,'manual item count'); same($detail['manual_reason'],'manual decision','decision reason');
$items=db_select_all($db,'SELECT kind FROM finance_v2_percentage_items WHERE settlement_id=? ORDER BY id','i',array($current['settlement_id'])); same(count($items),3,'source items saved');
// Subsequent same-day cash must not be rejected or silently omitted.
$lateId=joma_v2_record_event($events,array('key'=>'pct_late_receipt_001','type'=>'RECEIPT','wallet'=>$wallet,'amount_rial'=>5000,'account_id'=>1,'receipt_kind'=>'PAYMENT'),$secretary);
$late=db_select_one($db,'SELECT settlement_id,event_id FROM finance_v2_percentage_late_events WHERE event_id=?','i',array($lateId));
same((int)$late['settlement_id'],$current['settlement_id'],'late same-day event flagged for review');
rejects(function()use($s,$today,$admin){$next=date_add_days($today,1); joma_v2_create_percentage_settlement($s,1,$next,$next,null,'',$admin);},'unresolved late event blocks future settlement');
// An unclassified legacy settlement also gets a conservative warning.
db_execute($db,"INSERT INTO finance_centers (id,public_id,name,commission_model,commission_percent,created_at) VALUES (2,'C2','Synthetic legacy','PERCENTAGE',10,NOW())");
db_execute($db,"INSERT INTO rooms (id,public_id,name,created_at,center_id) VALUES (2,'R2','Old center room',NOW(),2)");
db_execute($db,"INSERT INTO appointments (id,public_id,admission_id,therapist_person_id,service_type_id,room_id,appointment_start_utc,appointment_end_utc,duration_minutes,status,created_by_person_id,created_by_role_code,created_at) VALUES (3,'AP3',1,1,1,2,'2020-10-01 09:00:00','2020-10-01 10:00:00',60,'COMPLETED',2,'admin',NOW())");
$dbrow=db_execute($db,'INSERT INTO finance_center_settlements (public_id,center_id,period_start,period_end,amount_due_rial,recorded_by_person_id,created_at) VALUES (?,?,?,?,?,?,?)','sisssis',array('OLD1',2,$today,$today,'1000',2,now_dt())); $oldSettlement=(int)$dbrow['insert_id'];
$dbrow=db_execute($db,'INSERT INTO finance_v2_wallets (appointment_id,created_at) VALUES (?,?)','is',array(3,now_dt())); $third=(int)$dbrow['insert_id'];
$oldLateId=joma_v2_record_event($events,array('key'=>'pct_old_late_000001','type'=>'RECEIPT','wallet'=>$third,'amount_rial'=>1000,'account_id'=>1,'receipt_kind'=>'PAYMENT'),$secretary);
$flag=db_select_one($db,'SELECT settlement_id FROM finance_v2_percentage_late_events WHERE event_id=?','i',array($oldLateId));
same((int)$flag['settlement_id'],$oldSettlement,'unclassified legacy settlement flagged');
// A missing serialization row fails closed and leaves no event behind.
db_execute($db,'DELETE FROM finance_v2_locks WHERE id=1');
try { joma_v2_record_event($events,array('key'=>'pct_lock_missing_001','type'=>'RECEIPT','wallet'=>$third,'amount_rial'=>1000,'account_id'=>1,'receipt_kind'=>'PAYMENT'),$secretary); throw new Exception('Expected missing mutex failure'); } catch (RuntimeException $e) {}
$missing=db_select_one($db,"SELECT COUNT(*) AS n FROM finance_v2_events WHERE operation_key='pct_lock_missing_001'"); same((int)$missing['n'],0,'no event without serialization lock');
db_execute($db,'INSERT INTO finance_v2_locks (id) VALUES (1)');
echo "Percentage settlement synthetic integration passed (not live).\n";
