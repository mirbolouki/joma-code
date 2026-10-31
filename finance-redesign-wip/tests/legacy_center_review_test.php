<?php
require __DIR__ . '/../src/legacy_center_review.php';
class LegacyReviewTestStore {
    public $payment,$reviews=array(),$mixed=false,$fail=false; private $backup;
    function __construct($payment) { $this->payment=$payment; }
    function lockLedger() {} // in-memory test adapter only
    function begin() { $this->backup=$this->reviews; }
    function commit() { $this->backup=null; }
    function rollback() { $this->reviews=$this->backup; }
    function lockPaymentAndWallet($id) { return $id===1?$this->payment:null; }
    function hasNewHistory($id) { return $this->mixed; }
    function latestReview($id) { return $this->reviews ? $this->reviews[count($this->reviews)-1] : null; }
    function centerExists($id) { return in_array($id,array(5,6),true); }
    function appendReview($payment,$depositCenter,$balanceCenter,$fingerprint,$snapshot,$reason,$actor) {
        $id=count($this->reviews)+1;
        $this->reviews[]=array('id'=>$id,'deposit_center_id'=>$depositCenter,'balance_center_id'=>$balanceCenter,'payment_fingerprint'=>$fingerprint,'snapshot'=>$snapshot,'reason'=>$reason,'actor'=>$actor);
        if ($this->fail) throw new RuntimeException('Simulated write error'); return $id;
    }
}
function same($a,$b,$msg) { if ($a!==$b) throw new Exception($msg.': '.var_export($a,true)); }
function rejects($f,$msg) { try { $f(); } catch (InvalidArgumentException $e) { return; } throw new Exception('Expected rejection: '.$msg); }
$p=array('id'=>'1','appointment_id'=>'3','session_fee_rial'=>'1000','deposit_amount_rial'=>'500','deposit_bank_account_id'=>'2','deposit_status'=>'PAID','deposit_paid_at'=>'2026-01-01 00:00:00','balance_amount_rial'=>null,'balance_bank_account_id'=>null,'balance_status'=>'PENDING','balance_paid_at'=>null);
$s=new LegacyReviewTestStore($p); $a=array('person_id'=>2,'role'=>'admin');
$input=array('payment_id'=>1,'deposit_center_id'=>5,'deposit_center_confirmed'=>true,'balance_center_id'=>null,'balance_center_confirmed'=>false,'fingerprint'=>joma_v2_legacy_payment_fingerprint($p),'expected_review_id'=>0,'reason'=>'contract review');
rejects(function()use($s,$input){joma_v2_review_legacy_center($s,$input,array('person_id'=>3,'role'=>'secretary'));},'admin only');
$bad=$input; unset($bad['deposit_center_id']); rejects(function()use($s,$bad,$a){joma_v2_review_legacy_center($s,$bad,$a);},'explicit center selection');
$bad=$input; $bad['deposit_center_id']=null; $bad['deposit_center_confirmed']=false; rejects(function()use($s,$bad,$a){joma_v2_review_legacy_center($s,$bad,$a);},'unknown is not explicitly verified none');
$bad=$input; $bad['reason']=' '; rejects(function()use($s,$bad,$a){joma_v2_review_legacy_center($s,$bad,$a);},'reason');
$bad=$input; $bad['balance_center_id']=6; rejects(function()use($s,$bad,$a){joma_v2_review_legacy_center($s,$bad,$a);},'pending balance cannot have center yet');
$bad=$input; $bad['deposit_center_id']=999; rejects(function()use($s,$bad,$a){joma_v2_review_legacy_center($s,$bad,$a);},'unknown center');
$missing=$p; $missing['deposit_paid_at']=null; $s->payment=$missing;
$bad=$input; $bad['fingerprint']=joma_v2_legacy_payment_fingerprint($missing);
rejects(function()use($s,$bad,$a){joma_v2_review_legacy_center($s,$bad,$a);},'missing paid date');
$missing=$p; $missing['deposit_amount_rial']=null; $s->payment=$missing; $bad['fingerprint']=joma_v2_legacy_payment_fingerprint($missing);
rejects(function()use($s,$bad,$a){joma_v2_review_legacy_center($s,$bad,$a);},'missing paid amount');
$s->payment=$p;
same(joma_v2_review_legacy_center($s,$input,$a),1,'review saved');
same(joma_v2_legacy_review_is_current($p,$s->reviews[0],false),true,'review valid');
same(json_decode($s->reviews[0]['snapshot'],true)['deposit_amount_rial'],'500','verified financial snapshot retained');
rejects(function()use($s,$input,$a){joma_v2_review_legacy_center($s,$input,$a);},'stale review');
$changed=$p; $changed['deposit_amount_rial']='501'; $s->payment=$changed;
same(joma_v2_legacy_review_is_current($changed,$s->reviews[0],false),false,'financial edit invalidates review');
$bad=$input; $bad['expected_review_id']=1; rejects(function()use($s,$bad,$a){joma_v2_review_legacy_center($s,$bad,$a);},'stale payment fingerprint');
$bad['fingerprint']=joma_v2_legacy_payment_fingerprint($changed); $bad['deposit_center_id']=null; $bad['reason']='verified no center';
$s->mixed=true; rejects(function()use($s,$bad,$a){joma_v2_review_legacy_center($s,$bad,$a);},'mixed ledger');
same(joma_v2_legacy_review_is_current($p,$s->reviews[0],true),false,'mixed history invalid');
$s->mixed=false; $s->fail=true; try { joma_v2_review_legacy_center($s,$bad,$a); throw new Exception('Expected failure'); } catch (RuntimeException $e) {}
same(count($s->reviews),1,'rollback preserves history'); $s->fail=false;
same(joma_v2_review_legacy_center($s,$bad,$a),2,'explicit no center'); same($s->reviews[1]['deposit_center_id'],null,'null not confused with unknown');
$changed['deposit_bank_account_id']=null; same(joma_v2_legacy_review_is_current($changed,$s->reviews[1],false),false,'changed account invalidates review');
echo "Legacy center review tests passed (in-memory only).\n";
