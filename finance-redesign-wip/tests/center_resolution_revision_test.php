<?php
require __DIR__.'/../src/center_balance.php';
class FakeResolutionRevisionStore {
    public $resolution=array('id'=>5,'delta'=>500,'key'=>'initial_resolution_1'),$revisions=array(),$payments=array(array('kind'=>'PAYMENT','amount_rial'=>3000)),$backup,$fail=false;
    function begin() { $this->backup=$this->revisions; }
    function commit() {}
    function rollback() { $this->revisions=$this->backup; }
    function lockLedger() {}
    function lockLate($id) { return $id===1?array('settlement_id'=>8):($id===2?array('settlement_id'=>8):null); }
    function lockEligibleSettlement($id) { if ($id!==8) throw new InvalidArgumentException('Unknown settlement'); }
    function lockResolutionVersion($late) {
        if ($late!==1) return null;
        $r=$this->revisions ? $this->revisions[count($this->revisions)-1] : null;
        return array('id'=>5,'revision_id'=>$r?$r['id']:0,'delta_rial'=>$r?$r['delta']:$this->resolution['delta']);
    }
    function resolutionOperationExists($key) {
        if ($key===$this->resolution['key']) return true;
        foreach ($this->revisions as $v) if ($key===$v['key']) return true;
        return false;
    }
    function balance($id) { $v=$this->lockResolutionVersion(1); return array('principal'=>10000,'adjustments'=>array(5=>$v['delta_rial']),'payments'=>$this->payments); }
    function insertResolutionRevision($id,$key,$delta,$reason,$actor) {
        $v=array('id'=>count($this->revisions)+1,'resolution_id'=>$id,'key'=>$key,'delta'=>$delta,'reason'=>$reason,'actor'=>$actor);
        $this->revisions[]=$v; if ($this->fail) throw new RuntimeException('Simulated failure'); return $v['id'];
    }
}
function same($a,$b,$msg) { if ($a!==$b) throw new Exception($msg.': '.var_export($a,true)); }
function rejects($f,$msg) { try { $f(); } catch (InvalidArgumentException $e) { return; } throw new Exception('Expected rejection: '.$msg); }
$s=new FakeResolutionRevisionStore(); $admin=array('person_id'=>2,'role'=>'admin');
$input=array('key'=>'resolution_revision_1','late_event_id'=>1,'expected_revision_id'=>0,'delta_rial'=>-12000,'reason'=>'correct agreement');
rejects(function()use($s,$input){joma_v2_revise_percentage_resolution($s,$input,array('person_id'=>3,'role'=>'secretary'));},'admin only');
$bad=$input; $bad['late_event_id']=2; rejects(function()use($s,$bad,$admin){joma_v2_revise_percentage_resolution($s,$bad,$admin);},'initial decision required');
$bad=$input; $bad['expected_revision_id']='0'; rejects(function()use($s,$bad,$admin){joma_v2_revise_percentage_resolution($s,$bad,$admin);},'version type');
$bad=$input; $bad['reason']=' '; rejects(function()use($s,$bad,$admin){joma_v2_revise_percentage_resolution($s,$bad,$admin);},'reason');
$bad=$input; $bad['delta_rial']=500; rejects(function()use($s,$bad,$admin){joma_v2_revise_percentage_resolution($s,$bad,$admin);},'no-op');
$bad=$input; $bad['key']='initial_resolution_1'; rejects(function()use($s,$bad,$admin){joma_v2_revise_percentage_resolution($s,$bad,$admin);},'initial key cannot be reused');
$s->fail=true; try { joma_v2_revise_percentage_resolution($s,$input,$admin); throw new Exception('Expected failure'); } catch (RuntimeException $e) {} same(count($s->revisions),0,'failed revision rollback'); $s->fail=false;
$result=joma_v2_revise_percentage_resolution($s,$input,$admin); same($result['id'],1,'revision id'); same($result['balance']['due_rial'],-2000,'replacement not additive'); same($result['balance']['receivable_rial'],5000,'prior payment makes receivable');
rejects(function()use($s,$input,$admin){joma_v2_revise_percentage_resolution($s,$input,$admin);},'stale version');
$bad=$input; $bad['expected_revision_id']=1; rejects(function()use($s,$bad,$admin){joma_v2_revise_percentage_resolution($s,$bad,$admin);},'duplicate key');
$input['expected_revision_id']=1; $input['key']='resolution_revision_2'; $input['delta_rial']=0; $input['reason']='revised agreement';
$result=joma_v2_revise_percentage_resolution($s,$input,$admin); same($result['balance']['payable_rial'],7000,'revised to zero and previous payment retained');
same($s->resolution['delta'],500,'initial decision untouched'); same($s->revisions[0]['delta'],-12000,'first revision untouched'); same($s->revisions[1]['delta'],0,'second revision stored');
echo "Experimental resolution revision tests passed (in-memory only).\n";
