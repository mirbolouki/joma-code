<?php
require __DIR__.'/../src/center_balance.php';
class FakePaymentRevisionStore {
    public $event=array('id'=>9,'settlement_id'=>8,'kind'=>'PAYMENT','amount_rial'=>3000,'account_id'=>7,'key'=>'payment_original_001');
    public $revisions=array(),$backup,$fail=false,$account_is_active=true;
    function begin() { $this->backup=$this->revisions; }
    function commit() {}
    function rollback() { $this->revisions=$this->backup; }
    function lockLedger() {}
    function lockEligibleSettlement($id) { if ($id!==8) throw new InvalidArgumentException('Invalid settlement'); }
    function accountActive($id) { return $this->account_is_active && $id===7; }
    function paymentOperationExists($key) { if ($key===$this->event['key']) return true; foreach ($this->revisions as $r) if ($r['key']===$key) return true; return false; }
    function lockPaymentVersion($id) {
        if ($id!==9) return null;
        $r=$this->revisions ? $this->revisions[count($this->revisions)-1] : null;
        $v=$r && $r['action']==='CORRECT' ? $r : $this->event;
        if ($r && $r['action']==='VOID') foreach ($this->revisions as $prior) if ($prior['action']==='CORRECT') $v=$prior;
        return array('settlement_id'=>8,'revision_id'=>$r?$r['id']:0,'action'=>$r?$r['action']:'ORIGINAL', 'kind'=>$v['kind'],'amount_rial'=>$v['amount_rial'],'account_id'=>$v['account_id']);
    }
    function balance($id) {
        $v=$this->lockPaymentVersion(9); $payments=array();
        if ($v['action']!=='VOID') $payments[9]=array('kind'=>$v['kind'],'amount_rial'=>$v['amount_rial']);
        return array('principal'=>10000,'adjustments'=>array(1=>1000),'payments'=>$payments);
    }
    function insertPaymentRevision($event,$key,$action,$kind,$amount,$account,$reason,$actor) {
        $v=array('id'=>count($this->revisions)+1,'key'=>$key,'action'=>$action,'kind'=>$kind,'amount_rial'=>$amount,'account_id'=>$account,'reason'=>$reason,'actor'=>$actor);
        $this->revisions[]=$v; if ($this->fail) throw new RuntimeException('Simulated failure'); return $v['id'];
    }
}
function same($a,$b,$msg) { if ($a!==$b) throw new Exception($msg.': '.var_export($a,true)); }
function rejects($f,$msg) { try { $f(); } catch (InvalidArgumentException $e) { return; } throw new Exception('Expected rejection: '.$msg); }
$s=new FakePaymentRevisionStore(); $admin=array('person_id'=>2,'role'=>'admin');
$in=array('key'=>'cash_revision_00001','payment_event_id'=>9,'expected_revision_id'=>0,'action'=>'CORRECT','kind'=>'PAYMENT','amount_rial'=>5000,'account_id'=>7,'reason'=>'incorrect entry');
rejects(function()use($s,$in){joma_v2_revise_center_cash($s,$in,array('person_id'=>3,'role'=>'secretary'));},'admin');
$bad=$in; $bad['expected_revision_id']='0'; rejects(function()use($s,$bad,$admin){joma_v2_revise_center_cash($s,$bad,$admin);},'version type');
$bad=$in; $bad['amount_rial']=0; rejects(function()use($s,$bad,$admin){joma_v2_revise_center_cash($s,$bad,$admin);},'positive amount');
$bad=$in; $bad['account_id']=8; rejects(function()use($s,$bad,$admin){joma_v2_revise_center_cash($s,$bad,$admin);},'inactive account');
$bad=$in; $bad['amount_rial']=3000; rejects(function()use($s,$bad,$admin){joma_v2_revise_center_cash($s,$bad,$admin);},'no-op');
$bad=$in; $bad['key']='payment_original_001'; rejects(function()use($s,$bad,$admin){joma_v2_revise_center_cash($s,$bad,$admin);},'operation key reuse');
$s->fail=true; try { joma_v2_revise_center_cash($s,$in,$admin); throw new Exception('Expected failure'); } catch (RuntimeException $e) {} same(count($s->revisions),0,'rollback'); $s->fail=false;
$r=joma_v2_revise_center_cash($s,$in,$admin); same($r['balance']['paid_rial'],5000,'correct entry only'); same($r['balance']['payable_rial'],6000,'corrected outstanding');
rejects(function()use($s,$in,$admin){joma_v2_revise_center_cash($s,$in,$admin);},'stale');
$in=array('key'=>'cash_revision_00002','payment_event_id'=>9,'expected_revision_id'=>1,'action'=>'VOID','reason'=>'entry was duplicated');
$r=joma_v2_revise_center_cash($s,$in,$admin); same($r['balance']['paid_rial'],0,'void is not actual return'); same($r['balance']['payable_rial'],11000,'void excludes erroneous payment');
$in['key']='cash_revision_00003'; $in['expected_revision_id']=2; rejects(function()use($s,$in,$admin){joma_v2_revise_center_cash($s,$in,$admin);},'duplicate void');
$s->account_is_active=false; // Restoring the same historically recorded account is allowed.
$in['action']='CORRECT'; $in['kind']='RETURN'; $in['amount_rial']=3000; $in['account_id']=7; $in['reason']='restore corrected direction';
$r=joma_v2_revise_center_cash($s,$in,$admin); same($r['balance']['paid_rial'],-3000,'restored as return'); same($r['balance']['payable_rial'],14000,'corrected history retains sign');
same($s->event['amount_rial'],3000,'original unchanged'); same(count($s->revisions),3,'all versions kept'); same($s->revisions[1]['action'],'VOID','void reason retained');
echo "Experimental center cash revision tests passed (in-memory only).\n";
