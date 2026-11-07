<?php
/* Local synthetic DB only. Run after mysql_percentage_settlement_test.php. */
$name=getenv('JOMA_TEST_DATABASE');
if (!is_string($name)||!preg_match('/^joma_v2_test_[a-z0-9_]+$/D',$name)||getenv('JOMA_TEST_ALLOW_WRITES')!=='YES'||!in_array(getenv('JOMA_TEST_HOST'),array('127.0.0.1','localhost'),true)) throw new Exception('Refusing non-test database');
require getenv('JOMA_TEST_DB_HELPERS'); require __DIR__.'/../src/center_balance.php'; require __DIR__.'/../src/storage/mysqli_center_balance_store.php'; require __DIR__.'/../src/storage/mysqli_store.php';
function now_dt() { return gmdate('Y-m-d H:i:s'); }
function same($a,$b,$m) { if ($a!==$b) throw new Exception($m.': '.var_export($a,true)); }
function rejects($f,$m) { try {$f();} catch (InvalidArgumentException $e) {return;} throw new Exception('Expected rejection: '.$m); }
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=mysqli_connect(getenv('JOMA_TEST_HOST'),getenv('JOMA_TEST_USER'),getenv('JOMA_TEST_PASSWORD'),$name,(int)getenv('JOMA_TEST_PORT')); mysqli_set_charset($db,'utf8mb4');
$row=db_select_one($db,"SELECT l.id,l.settlement_id,ev.wallet_id FROM finance_v2_percentage_late_events l JOIN finance_v2_events ev ON ev.id=l.event_id JOIN finance_v2_percentage_details p ON p.settlement_id=l.settlement_id WHERE p.manual_reason='manual decision'");
if (!$row) throw new Exception('Expected synthetic test flag');
$late=(int)$row['id']; $settlement=(int)$row['settlement_id']; $wallet=(int)$row['wallet_id'];
$s=new JomaV2MysqliCenterBalanceStore($db); $admin=array('person_id'=>2,'role'=>'admin');
$pay=array('key'=>'center_payment_001','settlement_id'=>$settlement,'account_id'=>1,'kind'=>'PAYMENT','amount_rial'=>15000,'reason'=>'synthetic installment');
$resolve=array('key'=>'center_resolve_001','late_event_id'=>$late,'delta_rial'=>5000,'reason'=>'synthetic agreement');
rejects(function()use($s,$pay,$admin){joma_v2_record_center_cash($s,$pay,$admin);},'late flag blocks cash');
$unclassified=db_select_one($db,'SELECT l.id FROM finance_v2_percentage_late_events l LEFT JOIN finance_v2_percentage_details p ON p.settlement_id=l.settlement_id WHERE p.settlement_id IS NULL');
$old=$resolve; $old['late_event_id']=(int)$unclassified['id']; rejects(function()use($s,$old,$admin){joma_v2_resolve_percentage_late($s,$old,$admin);},'unclassified legacy settlement');
db_execute($db,'UPDATE finance_center_settlements SET amount_paid_rial=1 WHERE id=?','i',array($settlement));
rejects(function()use($s,$resolve,$admin){joma_v2_resolve_percentage_late($s,$resolve,$admin);},'legacy payment overlap');
db_execute($db,'UPDATE finance_center_settlements SET amount_paid_rial=NULL WHERE id=?','i',array($settlement));
class FailingCenterBalanceStore extends JomaV2MysqliCenterBalanceStore {
 function insertResolution($late,$key,$delta,$reason,$actor) { parent::insertResolution($late,$key,$delta,$reason,$actor); throw new RuntimeException('simulated'); }
 function insertPayment($settlement,$key,$kind,$amount,$account,$reason,$actor) { parent::insertPayment($settlement,$key,$kind,$amount,$account,$reason,$actor); throw new RuntimeException('simulated'); }
}
try { joma_v2_resolve_percentage_late(new FailingCenterBalanceStore($db),$resolve,$admin); throw new Exception('Expected failure'); } catch (RuntimeException $e) {}
same($s->hasResolution($late),false,'failed resolution rolled back');
$r=joma_v2_resolve_percentage_late($s,$resolve,$admin); same($r['balance']['payable_rial'],30000,'signed adjustment');
rejects(function()use($s,$resolve,$admin){joma_v2_resolve_percentage_late($s,$resolve,$admin);},'same late flag twice');
// An unresolved flag on a different settlement of the same center also
// blocks payment. Synthetic cross-period fixture only; resolve it explicitly.
$other=db_select_one($db,'SELECT s.id FROM finance_center_settlements s JOIN finance_v2_percentage_details d ON d.settlement_id=s.id WHERE s.id<>? AND s.center_id=(SELECT center_id FROM finance_center_settlements WHERE id=?) LIMIT 1','ii',array($settlement,$settlement));
$source=db_select_one($db,'SELECT event_id FROM finance_v2_percentage_late_events WHERE id=?','i',array($late));
$synthetic=db_execute($db,'INSERT INTO finance_v2_percentage_late_events (settlement_id,event_id,detected_at) VALUES (?,?,?)','iis',array((int)$other['id'],(int)$source['event_id'],now_dt()));
rejects(function()use($s,$pay,$admin){joma_v2_record_center_cash($s,$pay,$admin);},'another unresolved flag at the same center');
joma_v2_resolve_percentage_late($s,array('key'=>'center_resolve_cross','late_event_id'=>(int)$synthetic['insert_id'],'delta_rial'=>0,'reason'=>'synthetic zero effect'),$admin);
try { joma_v2_record_center_cash(new FailingCenterBalanceStore($db),$pay,$admin); throw new Exception('Expected failure'); } catch (RuntimeException $e) {}
same(count($s->balance($settlement)['payments']),0,'failed payment rolled back');
$r=joma_v2_record_center_cash($s,$pay,$admin); same($r['balance']['balance_rial'],15000,'first installment');
rejects(function()use($s,$pay,$admin){joma_v2_record_center_cash($s,$pay,$admin);},'replayed payment');
$pay['key']='center_payment_002'; $pay['amount_rial']=15001; rejects(function()use($s,$pay,$admin){joma_v2_record_center_cash($s,$pay,$admin);},'overpayment');
$pay['amount_rial']=15000; joma_v2_record_center_cash($s,$pay,$admin);
// A second actual late event arrives after payment. The old principal and
// payment records must remain intact; the agreed reduction creates a credit.
$eventId=joma_v2_record_event(new JomaV2MysqliStore($db),array('key'=>'center_late_000001','type'=>'REFUND','wallet'=>$wallet,'amount_rial'=>1000,'account_id'=>1,'reason'=>'synthetic refund'),array('person_id'=>3,'role'=>'secretary'));
$flag=db_select_one($db,'SELECT id FROM finance_v2_percentage_late_events WHERE settlement_id=? AND event_id=?','ii',array($settlement,$eventId));
$pay['key']='center_return_00001'; $pay['kind']='RETURN'; $pay['amount_rial']=10000;
rejects(function()use($s,$pay,$admin){joma_v2_record_center_cash($s,$pay,$admin);},'second late flag blocks cash');
$resolve=array('key'=>'center_resolve_002','late_event_id'=>(int)$flag['id'],'delta_rial'=>-35000,'reason'=>'synthetic agreed reduction');
$r=joma_v2_resolve_percentage_late($s,$resolve,$admin); same($r['balance']['due_rial'],-5000,'negative due preserved'); same($r['balance']['receivable_rial'],35000,'center owes after adjustment');
$r=joma_v2_record_center_cash($s,$pay,$admin); same($r['balance']['receivable_rial'],25000,'partial return');
$pay['key']='center_return_00002'; $pay['amount_rial']=25000; $r=joma_v2_record_center_cash($s,$pay,$admin); same($r['balance']['balance_rial'],0,'return clears debt');
$base=db_select_one($db,'SELECT amount_due_rial,amount_paid_rial FROM finance_center_settlements WHERE id=?','i',array($settlement));
same((int)$base['amount_due_rial'],25000,'original principal untouched'); same($base['amount_paid_rial'],null,'legacy payment field untouched');
same(count($s->balance($settlement)['payments']),4,'all payment entries retained');
// An append-only revision replaces the agreed delta after payments have
// occurred. A resulting receivable remains visible and the old data survive.
$rev=array('key'=>'center_revision_001','late_event_id'=>$late,'expected_revision_id'=>0,'delta_rial'=>15000,'reason'=>'synthetic corrected agreement');
class FailingCenterRevisionStore extends JomaV2MysqliCenterBalanceStore {
 function insertResolutionRevision($id,$key,$delta,$reason,$actor) { parent::insertResolutionRevision($id,$key,$delta,$reason,$actor); throw new RuntimeException('simulated'); }
}
try { joma_v2_revise_percentage_resolution(new FailingCenterRevisionStore($db),$rev,$admin); throw new Exception('Expected failure'); } catch (RuntimeException $e) {}
$version=$s->lockResolutionVersion($late); same($version['revision_id'],0,'revision rolled back');
$r=joma_v2_revise_percentage_resolution($s,$rev,$admin); same($r['balance']['due_rial'],5000,'corrected delta replaces prior'); same($r['balance']['payable_rial'],10000,'prior cash retained'); $revision=$r['id'];
rejects(function()use($s,$rev,$admin){joma_v2_revise_percentage_resolution($s,$rev,$admin);},'stale decision version');
$rev['expected_revision_id']=$revision; rejects(function()use($s,$rev,$admin){joma_v2_revise_percentage_resolution($s,$rev,$admin);},'duplicate revision operation');
$rev['key']='center_revision_002'; $rev['delta_rial']=0; $rev['reason']='second synthetic revision';
$r=joma_v2_revise_percentage_resolution($s,$rev,$admin); same($r['balance']['due_rial'],-10000,'zero replaces previous delta'); same($r['balance']['receivable_rial'],5000,'negative balance preserved after revision');
$history=db_select_all($db,'SELECT delta_rial,reason FROM finance_v2_percentage_resolution_revisions WHERE resolution_id=? ORDER BY id','i',array($version['id']));
same(count($history),2,'both revisions retained'); same((int)$history[0]['delta_rial'],15000,'first revision retained'); same((int)$history[1]['delta_rial'],0,'second revision retained');
$original=db_select_one($db,'SELECT delta_rial,reason FROM finance_v2_percentage_late_resolutions WHERE id=?','i',array($version['id']));
same((int)$original['delta_rial'],5000,'original agreement untouched'); same($original['reason'],'synthetic agreement','original reason retained');
// Incorrectly entered center cash is revised or voided in an audit trail;
// VOID is not an actual cash RETURN. Restore is another complete version.
$event=db_select_one($db,"SELECT id,amount_rial FROM finance_v2_center_payment_events WHERE settlement_id=? AND kind='PAYMENT' ORDER BY id LIMIT 1",'i',array($settlement));
$cashRev=array('key'=>'center_cash_revision_01','payment_event_id'=>(int)$event['id'],'expected_revision_id'=>0,'action'=>'CORRECT','kind'=>'PAYMENT','amount_rial'=>10000,'account_id'=>1,'reason'=>'synthetic correction');
class FailingCashRevisionStore extends JomaV2MysqliCenterBalanceStore {
 function insertPaymentRevision($event,$key,$action,$kind,$amount,$account,$reason,$actor) { parent::insertPaymentRevision($event,$key,$action,$kind,$amount,$account,$reason,$actor); throw new RuntimeException('simulated'); }
}
try { joma_v2_revise_center_cash(new FailingCashRevisionStore($db),$cashRev,$admin); throw new Exception('Expected failure'); } catch (RuntimeException $e) {}
same($s->lockPaymentVersion((int)$event['id'])['revision_id'],0,'cash revision rollback');
$r=joma_v2_revise_center_cash($s,$cashRev,$admin); same($r['balance']['paid_rial'],-10000,'corrected cash replaces original'); same($r['balance']['balance_rial'],0,'corrected balance'); $cashVersion=$r['id'];
rejects(function()use($s,$cashRev,$admin){joma_v2_revise_center_cash($s,$cashRev,$admin);},'stale cash revision');
$void=array('key'=>'center_cash_revision_02','payment_event_id'=>(int)$event['id'],'expected_revision_id'=>$cashVersion,'action'=>'VOID','reason'=>'synthetic entry error');
$r=joma_v2_revise_center_cash($s,$void,$admin); same($r['balance']['paid_rial'],-20000,'void does not insert return'); same($r['balance']['payable_rial'],10000,'voided entry excluded');
$cashRev['key']='center_cash_revision_03'; $cashRev['expected_revision_id']=$r['id']; $cashRev['kind']='RETURN'; $cashRev['reason']='synthetic restore';
// Preserve the same historically recorded account even if now inactive.
db_execute($db,"UPDATE finance_bank_accounts SET status='INACTIVE' WHERE id=1");
$r=joma_v2_revise_center_cash($s,$cashRev,$admin);
db_execute($db,"UPDATE finance_bank_accounts SET status='ACTIVE' WHERE id=1"); same($r['balance']['paid_rial'],-30000,'restored corrected direction'); same($r['balance']['payable_rial'],20000,'restored balance');
$history=db_select_all($db,'SELECT action,amount_rial,reason FROM finance_v2_center_payment_revisions WHERE payment_event_id=? ORDER BY id','i',array((int)$event['id']));
same(count($history),3,'all cash revisions retained'); same($history[1]['action'],'VOID','void retained');
$original=db_select_one($db,'SELECT kind,amount_rial FROM finance_v2_center_payment_events WHERE id=?','i',array((int)$event['id']));
same($original['kind'],'PAYMENT','original direction untouched'); same((int)$original['amount_rial'],15000,'original cash untouched');
// Important: V2 settlement guard still remains blocked until the adjusted
// balances, payment screens and reporting are integrated together.
echo "Experimental center cash and signed adjustment integration passed (synthetic DB only).\n";
