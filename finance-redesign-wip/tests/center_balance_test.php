<?php
require __DIR__.'/../src/center_balance.php';
class FakeCenterBalanceStore {
    public $principal=10000,$resolutions=array(),$payments=array(),$late=array(1=>1,2=>1),$snapshot,$fail=false;
    function begin() { $this->snapshot=array($this->resolutions,$this->payments); }
    function rollback() { list($this->resolutions,$this->payments)=$this->snapshot; }
    function commit() {}
    function lockLedger() {}
    function lockLate($id) { return isset($this->late[$id])?array('settlement_id'=>$this->late[$id]):null; }
    function lockEligibleSettlement($id) { if ($id!==1) throw new InvalidArgumentException('Unknown settlement'); }
    function hasResolution($id) { return isset($this->resolutions[$id]); }
    function resolutionOperationExists($key) { foreach ($this->resolutions as $r) if ($r['key']===$key) return true; return false; }
    function paymentOperationExists($key) { foreach ($this->payments as $r) if ($r['key']===$key) return true; return false; }
    function hasUnresolvedFlags($id) { foreach ($this->late as $late=>$settlement) if (in_array($settlement,array(1,2),true) && !isset($this->resolutions[$late])) return true; return false; }
    function accountActive($id) { return $id===7; }
    function balance($id) { return array('principal'=>$this->principal,'adjustments'=>array_column($this->resolutions,'delta'),'payments'=>$this->payments); }
    function insertResolution($late,$key,$delta,$reason,$actor) { $this->resolutions[$late]=array('key'=>$key,'delta'=>$delta,'reason'=>$reason,'actor'=>$actor); if ($this->fail) throw new RuntimeException('simulated failure'); return $late; }
    function insertPayment($id,$key,$kind,$amount,$account,$reason,$actor) { $this->payments[]=array('key'=>$key,'kind'=>$kind,'amount_rial'=>$amount,'actor'=>$actor); if ($this->fail) throw new RuntimeException('simulated failure'); return count($this->payments); }
}
function same($a,$b,$m) { if ($a!==$b) throw new Exception($m.': '.var_export($a,true)); }
function rejects($f,$m) { try { $f(); } catch (InvalidArgumentException $e) { return; } throw new Exception('Expected rejection: '.$m); }
$admin=array('person_id'=>2,'role'=>'admin'); $sec=array('person_id'=>3,'role'=>'secretary'); $s=new FakeCenterBalanceStore();
$r=array('key'=>'resolution_000001','late_event_id'=>1,'delta_rial'=>500,'reason'=>'agreement');
$p=array('key'=>'payment_000000001','settlement_id'=>1,'kind'=>'PAYMENT','amount_rial'=>5000,'account_id'=>7,'reason'=>'installment');
rejects(function()use($s,$r,$sec){joma_v2_resolve_percentage_late($s,$r,$sec);},'role');
rejects(function()use($s,$p,$admin){joma_v2_record_center_cash($s,$p,$admin);},'unresolved flags');
$bad=$r; $bad['reason']=' '; rejects(function()use($s,$bad,$admin){joma_v2_resolve_percentage_late($s,$bad,$admin);},'reason');
$bad=$r; $bad['delta_rial']='500'; rejects(function()use($s,$bad,$admin){joma_v2_resolve_percentage_late($s,$bad,$admin);},'integer delta');
$s->fail=true; try { joma_v2_resolve_percentage_late($s,$r,$admin); throw new Exception('Expected failure'); } catch (RuntimeException $e) {} same(count($s->resolutions),0,'resolution rollback'); $s->fail=false;
$resolved=joma_v2_resolve_percentage_late($s,$r,$admin); same($resolved['balance']['due_rial'],10500,'manual addition');
rejects(function()use($s,$r,$admin){joma_v2_resolve_percentage_late($s,$r,$admin);},'already resolved');
rejects(function()use($s,$p,$admin){joma_v2_record_center_cash($s,$p,$admin);},'second flag unresolved');
$r['key']='resolution_000002'; $r['late_event_id']=2; $r['delta_rial']=-12000; $r['reason']='agreement creates receivable';
$resolved=joma_v2_resolve_percentage_late($s,$r,$admin); same($resolved['balance']['due_rial'],-1500,'signed due'); same($resolved['balance']['receivable_rial'],1500,'receivable visible');
$s->late[3]=2; $p['kind']='RETURN';
rejects(function()use($s,$p,$admin){joma_v2_record_center_cash($s,$p,$admin);},'unresolved flag on another settlement of same center'); unset($s->late[3]); $p['kind']='PAYMENT';
rejects(function()use($s,$p,$admin){joma_v2_record_center_cash($s,$p,$admin);},'cannot pay when center owes clinic');
$p['kind']='RETURN'; $p['amount_rial']=1600; rejects(function()use($s,$p,$admin){joma_v2_record_center_cash($s,$p,$admin);},'cannot exceed receivable');
$p['amount_rial']=500; $p['account_id']=8; rejects(function()use($s,$p,$admin){joma_v2_record_center_cash($s,$p,$admin);},'inactive account'); $p['account_id']=7;
$s->fail=true; try { joma_v2_record_center_cash($s,$p,$admin); throw new Exception('Expected failure'); } catch (RuntimeException $e) {} same(count($s->payments),0,'payment rollback'); $s->fail=false;
$paid=joma_v2_record_center_cash($s,$p,$admin); same($paid['balance']['paid_rial'],-500,'return is negative net payment'); same($paid['balance']['receivable_rial'],1000,'partial receivable');
rejects(function()use($s,$p,$admin){joma_v2_record_center_cash($s,$p,$admin);},'duplicate payment');
$p['key']='payment_000000002'; $p['amount_rial']=1000; $paid=joma_v2_record_center_cash($s,$p,$admin); same($paid['balance']['balance_rial'],0,'receivable settled');
same($s->resolutions[1]['reason'],'agreement','audit history preserved'); same(count($s->payments),2,'separate cash events');
$b=joma_v2_center_balance(10000,array(-9000),array(array('kind'=>'PAYMENT','amount_rial'=>4000)));
same($b['balance_rial'],-3000,'adjustment after payment creates visible overpayment'); same($b['receivable_rial'],3000,'overpayment is receivable');
try { joma_v2_center_balance(999999999999999999,array(1),array()); throw new Exception('Expected overflow'); } catch (OverflowException $e) {}
echo "Experimental center balance and cash tests passed (in-memory only).\n";
