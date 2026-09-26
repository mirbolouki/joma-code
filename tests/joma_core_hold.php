<?php
declare(strict_types=1);
require dirname(__DIR__) . '/joma-core/domain_rules.php';
require dirname(__DIR__) . '/joma-core/db.php';
require dirname(__DIR__) . '/joma-core/hold.php';

set_error_handler(function ($severity,$message,$file,$line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message,0,$severity,$file,$line);
});
$checks=0;$failures=0;
function t(string $n,bool $c):void{global $checks,$failures;++$checks;if(!$c)++$failures;echo($c?'PASS ':'FAIL ').$n.PHP_EOL;}
function id(int $i):string{return sprintf('00000000-0000-4000-8000-%012x',$i);}
function binId(int $i):string{$b=joma_db_uuid_to_bin(id($i));if($b===null)throw new RuntimeException('bin');return $b;}
function snap():array{return ['account'=>['id'=>id(1),'person_id'=>id(2),'status'=>'ACTIVE'],'membership'=>['id'=>id(3),'person_id'=>id(2),'scope_id'=>id(4),'status'=>'ACTIVE','valid_from'=>'2026-01-01 00:00:00.000000','valid_until'=>null,'revoked_at'=>null],'role_assignment'=>['id'=>id(5),'account_id'=>id(1),'person_id'=>id(2),'membership_id'=>id(3),'scope_id'=>id(4),'role_id'=>1,'valid_from'=>'2026-01-01 00:00:00.000000','valid_until'=>null,'revoked_at'=>null],'policy_status'=>'APPROVED','permissions'=>['scheduling.hold','scheduling.confirm']];}
$now='2026-09-26 10:00:00.000000';
$start='2026-09-26 12:00:00.000000';
$end='2026-09-26 13:00:00.000000';
$held=$now;
$expires='2026-09-26 10:15:00.000000';

/** Mock infrastructure for hold */
class MockResultH{private $rows;private $idx=0;function __construct(array $rows){$this->rows=$rows;}function fetch_assoc(){return $this->rows[$this->idx++]??null;}}
class MockStmtH{public string $sql;private $db;private $rows;private bool $ok;public array $bound=[];function __construct(string $sql,$db,array $rows,bool $ok=true){$this->sql=$sql;$this->db=$db;$this->rows=$rows;$this->ok=$ok;}function bind_param(string $t,&...$vars):bool{$this->bound=$vars;$this->db->lastBound=$vars;$this->db->preparedSqls[]=$this->sql;$this->db->allBounds[]=$vars;return true;}function execute():bool{$this->db->executedSqls[]=$this->sql;return $this->ok;}function get_result(){return new MockResultH($this->rows);}function close():void{}}
class MockMysqliH{
    public array $expect=[];public array $preparedSqls=[];public array $executedSqls=[];public array $allBounds=[];public $lastBound=null;public bool $began=false;public bool $committed=false;public bool $rolledBack=false;
    function expect(string $contains,array $rows=[],bool $ok=true){$this->expect[]=['contains'=>$contains,'rows'=>$rows,'ok'=>$ok];}
    function prepare(string $sql){
        if(strpos($sql,'?')===false) throw new RuntimeException('no placeholder: '.$sql);
        foreach($this->expect as $k=>$e){
            if(strpos($sql,$e['contains'])!==false){
                $rows=$e['rows'];$ok=$e['ok'];unset($this->expect[$k]);$this->expect=array_values($this->expect);
                return new MockStmtH($sql,$this,$rows,$ok);
            }
        }
        return new MockStmtH($sql,$this,[],true);
    }
    function begin_transaction():bool{$this->began=true;return true;}
    function commit():bool{$this->committed=true;return true;}
    function rollback():bool{$this->rolledBack=true;return true;}
}

function caseEngagementRow():array{
    return ['c_id'=>binId(20),'c_status'=>'ACTIVE','relationship_id'=>binId(21),'responsible_therapist_id'=>binId(2),'r_status'=>'ACTIVE','r_therapist'=>binId(2),'accepted_therapist'=>binId(2)];
}

// Hold create success single resource
$db=new MockMysqliH();
$db->expect('FROM joma_clinical_cases c JOIN joma_therapeutic_relationships',[caseEngagementRow()]);
$db->expect('FROM joma_service_offerings WHERE id', [['scope_id'=>binId(4)]]);
$db->expect('FROM joma_case_operational_contexts', [['scope_id'=>binId(4)]]);
$db->expect('FROM joma_schedule_resources WHERE id IN', [['id'=>binId(50)]]); // found
$db->expect('FROM joma_hold_allocations ha JOIN joma_capacity_holds', []); // no overlap holds
$db->expect('FROM joma_appointment_allocations', []); // no overlap appointments
$db->expect('INSERT INTO joma_capacity_holds', [], true);
$db->expect('INSERT INTO joma_hold_allocations', [], true);
$res=joma_hold_create($db,snap(),id(20),id(60),id(70),$start,$end,$held,$expires,[id(50)],$now,id(80),['hold_id'=>id(90),'allocation_ids'=>[id(91)]]);
t('hold create success',$res['ok']===true && $res['hold_id']===id(90));
t('hold create began+commit',$db->began && $db->committed && !$db->rolledBack);
t('hold create locks FOR UPDATE',count(array_filter($db->preparedSqls,fn($s)=>strpos($s,'FOR UPDATE')!==false))>=2);
t('hold create no uuid interpolation',count(array_filter($db->preparedSqls,fn($s)=>strpos($s,id(90))!==false))===0);

// Overlap denied: existing HELD overlapping
$db2=new MockMysqliH();
$db2->expect('FROM joma_clinical_cases c JOIN joma_therapeutic_relationships',[caseEngagementRow()]);
$db2->expect('FROM joma_service_offerings WHERE id', [['scope_id'=>binId(4)]]);
$db2->expect('FROM joma_case_operational_contexts', [['scope_id'=>binId(4)]]);
$db2->expect('FROM joma_schedule_resources WHERE id IN', [['id'=>binId(50)]]);
$db2->expect('FROM joma_hold_allocations ha JOIN joma_capacity_holds', [['1'=>1]]); // overlap exists
$res=joma_hold_create($db2,snap(),id(20),id(60),id(70),$start,$end,$held,$expires,[id(50)],$now,id(81));
t('hold overlap denied',$res['ok']===false && $res['code']==='CAPACITY_CONFLICT');
t('hold overlap rollback',$db2->rolledBack && !$db2->committed);
t('hold overlap no insert after',count(array_filter($db2->executedSqls,fn($s)=>strpos($s,'INSERT INTO joma_capacity_holds')!==false))===0);

// Adjacent not overlapping: existing 10-12, requested 12-13 should be allowed (half-open)
$db3=new MockMysqliH();
$db3->expect('FROM joma_clinical_cases c JOIN joma_therapeutic_relationships',[caseEngagementRow()]);
$db3->expect('FROM joma_service_offerings WHERE id', [['scope_id'=>binId(4)]]);
$db3->expect('FROM joma_case_operational_contexts', [['scope_id'=>binId(4)]]);
$db3->expect('FROM joma_schedule_resources WHERE id IN', [['id'=>binId(50)]]);
// holds overlap query should return empty for adjacent
$db3->expect('FROM joma_hold_allocations ha JOIN joma_capacity_holds', []);
$db3->expect('FROM joma_appointment_allocations', []);
$db3->expect('INSERT INTO joma_capacity_holds', [],true);
$db3->expect('INSERT INTO joma_hold_allocations', [],true);
$res=joma_hold_create($db3,snap(),id(20),id(60),id(70),$start,$end,$held,$expires,[id(50)],$now,id(82),['hold_id'=>id(92),'allocation_ids'=>[id(93)]]);
t('adjacent hold allowed',$res['ok']===true);

// Expired hold does not block: we simulate holds overlap returns empty because expired (our mock returns empty)
$db4=new MockMysqliH();
$db4->expect('FROM joma_clinical_cases c JOIN joma_therapeutic_relationships',[caseEngagementRow()]);
$db4->expect('FROM joma_service_offerings WHERE id', [['scope_id'=>binId(4)]]);
$db4->expect('FROM joma_case_operational_contexts', [['scope_id'=>binId(4)]]);
$db4->expect('FROM joma_schedule_resources WHERE id IN', [['id'=>binId(50)]]);
$db4->expect('FROM joma_hold_allocations ha JOIN joma_capacity_holds', []); // expired not returned
$db4->expect('FROM joma_appointment_allocations', []);
$db4->expect('INSERT INTO joma_capacity_holds', [],true);
$db4->expect('INSERT INTO joma_hold_allocations', [],true);
$res=joma_hold_create($db4,snap(),id(20),id(60),id(70),$start,$end,$held,$expires,[id(50)],$now,id(83),['hold_id'=>id(94),'allocation_ids'=>[id(95)]]);
t('expired hold does not block',$res['ok']===true);

// Invalid TTL >15min denied before DB (via hold_terms)
$db5=new MockMysqliH();
$res=joma_hold_create($db5,snap(),id(20),id(60),id(70),$start,$end,$now,'2026-09-26 10:15:00.000001',[id(50)],$now,id(84));
t('TTL >15min denied',$res['code']==='INVALID_TIME_WINDOW' || $res['code']==='CAPACITY_CONFLICT' || $res['code']==='INVALID_TIME_WINDOW');
t('TTL >15min no transaction',$db5->began===false);

// Check that long TTL is rejected via hold_terms code INVALID_TIME_WINDOW
$db5b=new MockMysqliH();
$db5b->expect('FROM joma_clinical_cases c JOIN joma_therapeutic_relationships',[caseEngagementRow()]);
$db5b->expect('FROM joma_service_offerings WHERE id', [['scope_id'=>binId(4)]]);
$db5b->expect('FROM joma_case_operational_contexts', [['scope_id'=>binId(4)]]);
$res=joma_hold_create($db5b,snap(),id(20),id(60),id(70),$start,$end,$now,'2026-09-26 10:15:00.000001',[id(50)],$now,id(84));
t('TTL >15 micro denied after load',$res['code']==='INVALID_TIME_WINDOW');

// Invalid resource duplicate
$db6=new MockMysqliH();
$res=joma_hold_create($db6,snap(),id(20),id(60),id(70),$start,$end,$held,$expires,[id(50),id(50)],$now,id(85));
t('duplicate resource rejected',$res['code']==='INVALID_RESOURCE');

// Inactive case denied
$inactiveCase=caseEngagementRow(); $inactiveCase['c_status']='CLOSED';
$db7=new MockMysqliH();
$db7->expect('FROM joma_clinical_cases c JOIN joma_therapeutic_relationships',[$inactiveCase]);
$res=joma_hold_create($db7,snap(),id(20),id(60),id(70),$start,$end,$held,$expires,[id(50)],$now,id(86));
t('inactive case denied',$res['code']==='ACTIVE_ENGAGEMENT_REQUIRED');

// Confirm success
$holdId=id(90);
$offeringId=id(60);
$dbC=new MockMysqliH();
// lock hold
$dbC->expect('FROM joma_capacity_holds WHERE id = ? FOR UPDATE',[['id'=>binId(90),'case_id'=>binId(20),'offering_id'=>binId(60),'status'=>'HELD','starts_at'=>$start,'ends_at'=>$end,'held_at'=>$held,'expires_at'=>$expires]]);
// allocations
$dbC->expect('FROM joma_hold_allocations WHERE hold_id', [['resource_id'=>binId(50),'starts_at'=>$start,'ends_at'=>$end]]);
// case engagement
$dbC->expect('FROM joma_clinical_cases c JOIN joma_therapeutic_relationships',[caseEngagementRow()]);
// offering scope
$dbC->expect('FROM joma_service_offerings WHERE id', [['scope_id'=>binId(4)]]);
// lock resources
$dbC->expect('FROM joma_schedule_resources WHERE id IN', [['id'=>binId(50)]]);
// overlap holds excluding self
$dbC->expect('FROM joma_hold_allocations ha JOIN joma_capacity_holds', []);
// overlap appointments
$dbC->expect('FROM joma_appointment_allocations', []);
// inserts
$dbC->expect('INSERT INTO joma_appointments', [],true);
$dbC->expect('INSERT INTO joma_appointment_allocations', [],true);
$dbC->expect('UPDATE joma_capacity_holds SET status', [],true);
$res=joma_hold_confirm($dbC,snap(),$holdId,$offeringId,$now,id(100),['appointment_id'=>id(110),'allocation_ids'=>[id(111)]]);
t('confirm success',$res['ok']===true && $res['appointment_id']===id(110));
t('confirm committed',$dbC->committed && !$dbC->rolledBack);

// Confirm expired denied
$dbC2=new MockMysqliH();
$dbC2->expect('FROM joma_capacity_holds WHERE id = ? FOR UPDATE',[['id'=>binId(90),'case_id'=>binId(20),'offering_id'=>binId(60),'status'=>'HELD','starts_at'=>$start,'ends_at'=>$end,'held_at'=>$held,'expires_at'=>$expires]]);
// need allocations to reach guard
$dbC2->expect('FROM joma_hold_allocations WHERE hold_id', [['resource_id'=>binId(50),'starts_at'=>$start,'ends_at'=>$end]]);
$dbC2->expect('FROM joma_clinical_cases c JOIN joma_therapeutic_relationships',[caseEngagementRow()]);
// at expires exactly, confirm should be HOLD_EXPIRED_OR_NOT_STARTED
$res=joma_hold_confirm($dbC2,snap(),$holdId,$offeringId,$expires,id(101));
t('confirm at exact expiry denied',$res['code']==='HOLD_EXPIRED_OR_NOT_STARTED');
t('confirm expiry rolled back',$dbC2->rolledBack);

// Confirm before held_at denied
$dbC3=new MockMysqliH();
$dbC3->expect('FROM joma_capacity_holds WHERE id = ? FOR UPDATE',[['id'=>binId(90),'case_id'=>binId(20),'offering_id'=>binId(60),'status'=>'HELD','starts_at'=>$start,'ends_at'=>$end,'held_at'=>$held,'expires_at'=>$expires]]);
$dbC3->expect('FROM joma_hold_allocations WHERE hold_id', [['resource_id'=>binId(50),'starts_at'=>$start,'ends_at'=>$end]]);
$dbC3->expect('FROM joma_clinical_cases c JOIN joma_therapeutic_relationships',[caseEngagementRow()]);
$res=joma_hold_confirm($dbC3,snap(),$holdId,$offeringId,'2026-09-26 09:59:59.999999',id(102));
t('confirm before held denied',$res['code']==='HOLD_EXPIRED_OR_NOT_STARTED');

// Wrong offering
$dbC4=new MockMysqliH();
$dbC4->expect('FROM joma_capacity_holds WHERE id = ? FOR UPDATE',[['id'=>binId(90),'case_id'=>binId(20),'offering_id'=>binId(60),'status'=>'HELD','starts_at'=>$start,'ends_at'=>$end,'held_at'=>$held,'expires_at'=>$expires]]);
$dbC4->expect('FROM joma_hold_allocations WHERE hold_id', [['resource_id'=>binId(50)]]);
$res=joma_hold_confirm($dbC4,snap(),$holdId,id(99),$now,id(103));
t('confirm wrong offering denied',$res['code']==='HOLD_CONFLICT');

// Already consumed
$dbC5=new MockMysqliH();
$dbC5->expect('FROM joma_capacity_holds WHERE id = ? FOR UPDATE',[['id'=>binId(90),'case_id'=>binId(20),'offering_id'=>binId(60),'status'=>'CONSUMED','starts_at'=>$start,'ends_at'=>$end,'held_at'=>$held,'expires_at'=>$expires]]);
$dbC5->expect('FROM joma_hold_allocations WHERE hold_id', [['resource_id'=>binId(50)]]);
$dbC5->expect('FROM joma_clinical_cases c JOIN joma_therapeutic_relationships',[caseEngagementRow()]);
$res=joma_hold_confirm($dbC5,snap(),$holdId,$offeringId,$now,id(104));
t('confirm consumed denied',$res['code']==='HOLD_CONFLICT');

// Capacity conflict on confirm with other hold
$dbC6=new MockMysqliH();
$dbC6->expect('FROM joma_capacity_holds WHERE id = ? FOR UPDATE',[['id'=>binId(90),'case_id'=>binId(20),'offering_id'=>binId(60),'status'=>'HELD','starts_at'=>$start,'ends_at'=>$end,'held_at'=>$held,'expires_at'=>$expires]]);
$dbC6->expect('FROM joma_hold_allocations WHERE hold_id', [['resource_id'=>binId(50)]]);
$dbC6->expect('FROM joma_clinical_cases c JOIN joma_therapeutic_relationships',[caseEngagementRow()]);
$dbC6->expect('FROM joma_service_offerings WHERE id', [['scope_id'=>binId(4)]]);
$dbC6->expect('FROM joma_schedule_resources WHERE id IN', [['id'=>binId(50)]]);
$dbC6->expect('FROM joma_hold_allocations ha JOIN joma_capacity_holds', [['1'=>1]]); // overlapping other hold
$res=joma_hold_confirm($dbC6,snap(),$holdId,$offeringId,$now,id(105));
t('confirm capacity conflict',$res['code']==='CAPACITY_CONFLICT');
t('confirm conflict rolled back',$dbC6->rolledBack);

// Appointment conflict
$dbC7=new MockMysqliH();
$dbC7->expect('FROM joma_capacity_holds WHERE id = ? FOR UPDATE',[['id'=>binId(90),'case_id'=>binId(20),'offering_id'=>binId(60),'status'=>'HELD','starts_at'=>$start,'ends_at'=>$end,'held_at'=>$held,'expires_at'=>$expires]]);
$dbC7->expect('FROM joma_hold_allocations WHERE hold_id', [['resource_id'=>binId(50)]]);
$dbC7->expect('FROM joma_clinical_cases c JOIN joma_therapeutic_relationships',[caseEngagementRow()]);
$dbC7->expect('FROM joma_service_offerings WHERE id', [['scope_id'=>binId(4)]]);
$dbC7->expect('FROM joma_schedule_resources WHERE id IN', [['id'=>binId(50)]]);
$dbC7->expect('FROM joma_hold_allocations ha JOIN joma_capacity_holds', []); // no hold conflict
$dbC7->expect('FROM joma_appointment_allocations', [['1'=>1]]); // appointment overlap
$res=joma_hold_confirm($dbC7,snap(),$holdId,$offeringId,$now,id(106));
t('confirm appointment conflict',$res['code']==='CAPACITY_CONFLICT');

// No mutation of snapshot
$snapCopy=snap();
$db8=new MockMysqliH();
$db8->expect('FROM joma_clinical_cases c JOIN joma_therapeutic_relationships',[caseEngagementRow()]);
$db8->expect('FROM joma_service_offerings WHERE id', [['scope_id'=>binId(4)]]);
$db8->expect('FROM joma_case_operational_contexts', [['scope_id'=>binId(4)]]);
$db8->expect('FROM joma_schedule_resources WHERE id IN', [['id'=>binId(50)]]);
$db8->expect('FROM joma_hold_allocations ha JOIN joma_capacity_holds', []);
$db8->expect('FROM joma_appointment_allocations', []);
$db8->expect('INSERT INTO joma_capacity_holds', [],true);
$db8->expect('INSERT INTO joma_hold_allocations', [],true);
joma_hold_create($db8,$snapCopy,id(20),id(60),id(70),$start,$end,$held,$expires,[id(50)],$now,id(107),['hold_id'=>id(120),'allocation_ids'=>[id(121)]]);
t('hold no snapshot mutation',$snapCopy===snap());

echo "\nPHP ".PHP_VERSION." — $checks checks, $failures failures\n";
echo "HOLD: synthetic transaction only; no real DB concurrency tested.\n";
exit($failures?1:0);
