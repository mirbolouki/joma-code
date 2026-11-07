<?php
require __DIR__.'/../src/percentage_late_review.php';
class LateReviewMemory {
    public $rows=array(),$backup,$exists=true,$fail=false,$locked=false;
    function begin() { $this->backup=$this->rows; }
    function lockLedger() { $this->locked=true; }
    function lockLateEvent($id) { if (!$this->locked) throw new RuntimeException('not locked'); return $this->exists && $id===3; }
    function latestReview($id) { return $this->rows ? $this->rows[count($this->rows)-1] : null; }
    function appendReview($id,$kind,$reason,$actor) {
        $this->rows[]=array('id'=>count($this->rows)+1,'late_event_id'=>$id,'kind'=>$kind,'reason'=>$reason,'actor'=>$actor);
        if ($this->fail) throw new RuntimeException('simulated insert failure');
        return count($this->rows);
    }
    function commit() { $this->backup=null; }
    function rollback() { $this->rows=$this->backup; }
}
function same($a,$b,$m) { if ($a!==$b) throw new Exception($m); }
function rejects($f,$m) { try { $f(); } catch (InvalidArgumentException $e) { return; } throw new Exception('Expected rejection: '.$m); }
$s=new LateReviewMemory(); $actor=array('person_id'=>2,'role'=>'admin');
$in=array('late_event_id'=>3,'expected_review_id'=>0,'kind'=>'INVESTIGATING','reason'=>'checking documents');
rejects(function()use($s,$in){joma_v2_review_percentage_late($s,$in,array('person_id'=>3,'role'=>'secretary'));},'admin');
foreach (array(array('kind'=>'RESOLVED'),array('reason'=>' '),array('reason'=>str_repeat('x',1001)),array('late_event_id'=>999),array('expected_review_id'=>'0')) as $bad) {
    $arg=array_merge($in,$bad); rejects(function()use($s,$arg,$actor){joma_v2_review_percentage_late($s,$arg,$actor);},'invalid input');
}
same(joma_v2_review_percentage_late($s,$in,$actor),1,'first review');
rejects(function()use($s,$in,$actor){joma_v2_review_percentage_late($s,$in,$actor);},'stale');
$in['expected_review_id']=1; $in['kind']='AGREEMENT_NOTED'; $in['reason']='manual agreement';
$s->fail=true; try { joma_v2_review_percentage_late($s,$in,$actor); throw new Exception('Expected error'); } catch (RuntimeException $e) {}
same(count($s->rows),1,'rollback'); $s->fail=false;
same(joma_v2_review_percentage_late($s,$in,$actor),2,'second review');
same($s->rows[0]['reason'],'checking documents','original preserved');
echo "Percentage late review tests passed (in-memory only).\n";
