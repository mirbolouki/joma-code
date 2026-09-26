<?php
declare(strict_types=1);
require dirname(__DIR__) . '/joma-core/domain_rules.php';
require dirname(__DIR__) . '/joma-core/db.php';
require dirname(__DIR__) . '/joma-core/context.php';
require dirname(__DIR__) . '/joma-core/acceptance.php';

set_error_handler(function ($severity,$message,$file,$line) {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message,0,$severity,$file,$line);
});

$checks=0; $failures=0;
function t(string $name, bool $cond): void { global $checks,$failures; ++$checks; if(!$cond)++$failures; echo ($cond?'PASS ':'FAIL ').$name.PHP_EOL; }
function id(int $i): string { return sprintf('00000000-0000-4000-8000-%012x',$i); }
function binId(int $i): string { $b=joma_db_uuid_to_bin(id($i)); if($b===null) throw new RuntimeException('bin'); return $b; }
function staffSnapshot(): array {
    return ['account'=>['id'=>id(1),'person_id'=>id(2),'status'=>'ACTIVE'],
        'membership'=>['id'=>id(3),'person_id'=>id(2),'scope_id'=>id(4),'status'=>'ACTIVE','valid_from'=>'2026-01-01 00:00:00.000000','valid_until'=>null,'revoked_at'=>null],
        'role_assignment'=>['id'=>id(5),'account_id'=>id(1),'person_id'=>id(2),'membership_id'=>id(3),'scope_id'=>id(4),'role_id'=>1,'valid_from'=>'2026-01-01 00:00:00.000000','valid_until'=>null,'revoked_at'=>null],
        'policy_status'=>'APPROVED','permissions'=>['routing.accept','scheduling.hold']];
}
$now='2026-09-26 10:00:00.000000';

/** Mock for acceptance transaction */
class MockResultA { private $rows; private $idx=0; function __construct(array $rows){$this->rows=$rows;} function fetch_assoc(){return $this->rows[$this->idx++] ?? null;} }
class MockStmtA {
    public string $sql;
    private $db;
    private $rows;
    private $shouldSucceed;
    public array $bound=[];
    function __construct(string $sql, $db, array $rows, bool $shouldSucceed=true){$this->sql=$sql;$this->db=$db;$this->rows=$rows;$this->shouldSucceed=$shouldSucceed;}
    function bind_param(string $types, &...$vars): bool { $this->bound=$vars; $this->db->lastBound=$vars; $this->db->preparedSqls[]=$this->sql; // track
        // also capture bound for inspection if needed
        $this->db->allBounds[]=$vars;
        return true;
    }
    function execute(): bool { $this->db->executedSqls[]=$this->sql; return $this->shouldSucceed; }
    function get_result(){ return new MockResultA($this->rows); }
    function close(): void {}
}
class MockMysqliA {
    public array $expectations=[]; // queue of ['contains'=>string,'rows'=>array,'success'=>bool]
    public array $preparedSqls=[];
    public array $executedSqls=[];
    public array $allBounds=[];
    public $lastBound=null;
    public bool $began=false; public bool $committed=false; public bool $rolledBack=false;
    function expect(string $contains, array $rows = [], bool $success=true){ $this->expectations[]=['contains'=>$contains,'rows'=>$rows,'success'=>$success]; }
    function prepare(string $sql){
        if (strpos($sql,'?')===false) { throw new RuntimeException('SQL without placeholder: '.$sql); }
        // Find first matching expectation where contains substring present
        foreach($this->expectations as $k=>$e){
            if (strpos($sql,$e['contains'])!==false){
                $rows=$e['rows']; $succ=$e['success']; unset($this->expectations[$k]); $this->expectations=array_values($this->expectations);
                return new MockStmtA($sql,$this,$rows,$succ);
            }
        }
        // Default: no rows, success
        return new MockStmtA($sql,$this,[],true);
    }
    function begin_transaction(): bool { $this->began=true; return true; }
    function commit(): bool { $this->committed=true; return true; }
    function rollback(): bool { $this->rolledBack=true; return true; }
}

// Purpose validation
t('purpose valid Persian', joma_acceptance_validate_purpose('  ارزیابی اولیه خانواده  ') === 'ارزیابی اولیه خانواده');
t('purpose empty rejected', joma_acceptance_validate_purpose('   ') === null);
t('purpose NUL rejected', joma_acceptance_validate_purpose("a\0b") === null);
t('purpose too long rejected', joma_acceptance_validate_purpose(str_repeat('a',501)) === null);
t('purpose 500 ok', joma_acceptance_validate_purpose(str_repeat('a',500)) !== null);
t('purpose control char rejected', joma_acceptance_validate_purpose("a\x07b") === null);

// Valid acceptance success
$snap = staffSnapshot();
$assignmentId = id(10);
$admissionId = id(11);
$commandId = id(12);
$acceptId = id(20); $relId=id(21); $caseId=id(22); $partId=id(23); $ctxId=id(24);
$db = new MockMysqliA();
// Order: assignment lock, admission lock, then inserts/updates
$db->expect('FROM joma_therapist_assignments', [['id'=>binId(10),'admission_id'=>binId(11),'therapist_person_id'=>binId(2),'status'=>'ASSIGNED']]);
$db->expect('FROM joma_admissions', [['id'=>binId(11),'primary_subject_id'=>binId(30),'scope_id'=>binId(4),'status'=>'AWAITING_THERAPIST','case_id'=>null]]);
$db->expect('INSERT INTO joma_responsibility_acceptances', [], true);
$db->expect('INSERT INTO joma_therapeutic_relationships', [], true);
$db->expect('INSERT INTO joma_clinical_cases', [], true);
$db->expect('INSERT INTO joma_case_participants', [], true);
$db->expect('INSERT INTO joma_case_operational_contexts', [], true);
$db->expect('UPDATE joma_therapist_assignments', [], true);
$db->expect('UPDATE joma_admissions', [], true);
$res = joma_acceptance_execute($db, $snap, $assignmentId, 'پذیرش درمان فردی', $now, $commandId, ['acceptance_id'=>$acceptId,'relationship_id'=>$relId,'case_id'=>$caseId,'participant_id'=>$partId,'context_id'=>$ctxId]);
t('accept success', ($res['ok']??false)===true && $res['case_id']===$caseId && $res['acceptance_id']===$acceptId);
t('accept began transaction', $db->began===true);
t('accept committed', $db->committed===true && $db->rolledBack===false);
t('accept used FOR UPDATE locks', count(array_filter($db->preparedSqls, fn($s)=>strpos($s,'FOR UPDATE')!==false))===2);
t('accept uses placeholders only', count(array_filter($db->preparedSqls, fn($s)=>strpos($s,'?')===false))===0);
// Ensure no interpolation: none of the sql should contain raw uuid substring
$containsUuid = false; foreach($db->preparedSqls as $s){ if(strpos($s,$assignmentId)!==false) $containsUuid=true; }
t('no uuid interpolation in SQL', $containsUuid===false);

// Wrong therapist
$db2 = new MockMysqliA();
$db2->expect('FROM joma_therapist_assignments', [['id'=>binId(10),'admission_id'=>binId(11),'therapist_person_id'=>binId(99),'status'=>'ASSIGNED']]);
$db2->expect('FROM joma_admissions', [['id'=>binId(11),'primary_subject_id'=>binId(30),'scope_id'=>binId(4),'status'=>'AWAITING_THERAPIST','case_id'=>null]]);
$res = joma_acceptance_execute($db2, $snap, $assignmentId, 'پذیرش', $now, $commandId, ['acceptance_id'=>id(30),'relationship_id'=>id(31),'case_id'=>id(32),'participant_id'=>id(33),'context_id'=>id(34)]);
t('wrong therapist denies', ($res['ok']??true)===false && $res['code']==='ASSIGNED_THERAPIST_REQUIRED');
t('wrong therapist rolled back no commit', $db2->rolledBack===true && $db2->committed===false);
t('wrong therapist no inserts after guard', count($db2->executedSqls)===2); // only two selects

// Already accepted assignment
$db3 = new MockMysqliA();
$db3->expect('FROM joma_therapist_assignments', [['id'=>binId(10),'admission_id'=>binId(11),'therapist_person_id'=>binId(2),'status'=>'ACCEPTED']]);
$db3->expect('FROM joma_admissions', [['id'=>binId(11),'primary_subject_id'=>binId(30),'scope_id'=>binId(4),'status'=>'AWAITING_THERAPIST','case_id'=>null]]);
$res = joma_acceptance_execute($db3, $snap, $assignmentId, 'پذیرش', $now, $commandId);
t('already accepted denied', $res['code']==='STATE_CONFLICT');
t('already accepted rolled back', $db3->rolledBack===true);

// Admission already linked
$db4 = new MockMysqliA();
$db4->expect('FROM joma_therapist_assignments', [['id'=>binId(10),'admission_id'=>binId(11),'therapist_person_id'=>binId(2),'status'=>'ASSIGNED']]);
$db4->expect('FROM joma_admissions', [['id'=>binId(11),'primary_subject_id'=>binId(30),'scope_id'=>binId(4),'status'=>'AWAITING_THERAPIST','case_id'=>binId(22)]]);
$res = joma_acceptance_execute($db4, $snap, $assignmentId, 'پذیرش', $now, $commandId);
t('admission already linked denied', $res['code']==='STATE_CONFLICT');

// Admission wrong status
$db5 = new MockMysqliA();
$db5->expect('FROM joma_therapist_assignments', [['id'=>binId(10),'admission_id'=>binId(11),'therapist_person_id'=>binId(2),'status'=>'ASSIGNED']]);
$db5->expect('FROM joma_admissions', [['id'=>binId(11),'primary_subject_id'=>binId(30),'scope_id'=>binId(4),'status'=>'LINKED_TO_CASE','case_id'=>binId(22)]]);
$res = joma_acceptance_execute($db5, $snap, $assignmentId, 'پذیرش', $now, $commandId);
t('admission wrong status denied', $res['code']==='STATE_CONFLICT');

// Cross scope: snapshot scope 4, admission scope 99
$db6 = new MockMysqliA();
$db6->expect('FROM joma_therapist_assignments', [['id'=>binId(10),'admission_id'=>binId(11),'therapist_person_id'=>binId(2),'status'=>'ASSIGNED']]);
$db6->expect('FROM joma_admissions', [['id'=>binId(11),'primary_subject_id'=>binId(30),'scope_id'=>binId(99),'status'=>'AWAITING_THERAPIST','case_id'=>null]]);
$res = joma_acceptance_execute($db6, $snap, $assignmentId, 'پذیرش', $now, $commandId);
t('cross scope denied', $res['code']==='CONTEXT_DENIED' || $res['code']==='STATE_CONFLICT' || $res['code']==='CONTEXT_DENIED'); // domain guard returns CONTEXT_DENIED
t('cross scope rolled back', $db6->rolledBack===true);
t('cross scope guard via context', $res['code']==='CONTEXT_DENIED');

// Permission missing: remove routing.accept
$snapNoPerm = $snap; $snapNoPerm['permissions']=['scheduling.hold'];
$db7 = new MockMysqliA();
$db7->expect('FROM joma_therapist_assignments', [['id'=>binId(10),'admission_id'=>binId(11),'therapist_person_id'=>binId(2),'status'=>'ASSIGNED']]);
$db7->expect('FROM joma_admissions', [['id'=>binId(11),'primary_subject_id'=>binId(30),'scope_id'=>binId(4),'status'=>'AWAITING_THERAPIST','case_id'=>null]]);
$res = joma_acceptance_execute($db7, $snapNoPerm, $assignmentId, 'پذیرش', $now, $commandId);
t('permission missing denied', $res['code']==='PERMISSION_DENIED');
t('permission missing rolled back', $db7->rolledBack===true);

// Invalid purpose without DB attempt
$db8 = new MockMysqliA();
$res = joma_acceptance_execute($db8, $snap, $assignmentId, '   ', $now, $commandId);
t('empty purpose without transaction', $res['code']==='INVALID_PURPOSE' && $db8->began===false);

// Invalid IDs
$db9 = new MockMysqliA();
$res = joma_acceptance_execute($db9, $snap, 'not-uuid', 'پذیرش', $now, $commandId);
t('invalid assignment id', $res['code']==='INVALID_ID' && $db9->began===false);
$db10 = new MockMysqliA();
$res = joma_acceptance_execute($db10, $snap, $assignmentId, 'پذیرش', 'bad time', $commandId);
t('invalid time rejected', $res['code']==='INVALID_TIME');

// DB error during insert: simulate failure on case insert
$db11 = new MockMysqliA();
$db11->expect('FROM joma_therapist_assignments', [['id'=>binId(10),'admission_id'=>binId(11),'therapist_person_id'=>binId(2),'status'=>'ASSIGNED']]);
$db11->expect('FROM joma_admissions', [['id'=>binId(11),'primary_subject_id'=>binId(30),'scope_id'=>binId(4),'status'=>'AWAITING_THERAPIST','case_id'=>null]]);
$db11->expect('INSERT INTO joma_responsibility_acceptances', [], true);
$db11->expect('INSERT INTO joma_therapeutic_relationships', [], true);
$db11->expect('INSERT INTO joma_clinical_cases', [], false); // fail
$res = joma_acceptance_execute($db11, $snap, $assignmentId, 'پذیرش', $now, $commandId, ['acceptance_id'=>id(40),'relationship_id'=>id(41),'case_id'=>id(42),'participant_id'=>id(43),'context_id'=>id(44)]);
t('insert failure rolls back', $res['code']==='DB_ERROR' && $db11->rolledBack===true && $db11->committed===false);

// Not found assignment
$db12 = new MockMysqliA();
$db12->expect('FROM joma_therapist_assignments', []); // empty
$res = joma_acceptance_execute($db12, $snap, $assignmentId, 'پذیرش', $now, $commandId);
t('assignment not found', $res['code']==='NOT_FOUND' && $db12->rolledBack===true);

// Admission not found
$db13 = new MockMysqliA();
$db13->expect('FROM joma_therapist_assignments', [['id'=>binId(10),'admission_id'=>binId(11),'therapist_person_id'=>binId(2),'status'=>'ASSIGNED']]);
$db13->expect('FROM joma_admissions', []); // not found
$res = joma_acceptance_execute($db13, $snap, $assignmentId, 'پذیرش', $now, $commandId);
t('admission not found', $res['code']==='NOT_FOUND' && $db13->rolledBack===true);

// Ensure generated ids are v4 when not provided
$db14 = new MockMysqliA();
$db14->expect('FROM joma_therapist_assignments', [['id'=>binId(10),'admission_id'=>binId(11),'therapist_person_id'=>binId(2),'status'=>'ASSIGNED']]);
$db14->expect('FROM joma_admissions', [['id'=>binId(11),'primary_subject_id'=>binId(30),'scope_id'=>binId(4),'status'=>'AWAITING_THERAPIST','case_id'=>null]]);
$db14->expect('INSERT INTO joma_responsibility_acceptances', [], true);
$db14->expect('INSERT INTO joma_therapeutic_relationships', [], true);
$db14->expect('INSERT INTO joma_clinical_cases', [], true);
$db14->expect('INSERT INTO joma_case_participants', [], true);
$db14->expect('INSERT INTO joma_case_operational_contexts', [], true);
$db14->expect('UPDATE joma_therapist_assignments', [], true);
$db14->expect('UPDATE joma_admissions', [], true);
$res = joma_acceptance_execute($db14, $snap, $assignmentId, 'پذیرش خودکار', $now, $commandId);
t('auto generated ids valid uuid', joma_rule_uuid($res['case_id']??'')!==null && joma_rule_uuid($res['acceptance_id']??'')!==null);
t('auto generated ids lower case', $res['case_id']===strtolower($res['case_id']));

// No mutation of snapshot
$snapCopy = $snap;
$db15 = new MockMysqliA();
$db15->expect('FROM joma_therapist_assignments', [['id'=>binId(10),'admission_id'=>binId(11),'therapist_person_id'=>binId(2),'status'=>'ASSIGNED']]);
$db15->expect('FROM joma_admissions', [['id'=>binId(11),'primary_subject_id'=>binId(30),'scope_id'=>binId(4),'status'=>'AWAITING_THERAPIST','case_id'=>null]]);
$db15->expect('INSERT INTO joma_responsibility_acceptances', [], true);
$db15->expect('INSERT INTO joma_therapeutic_relationships', [], true);
$db15->expect('INSERT INTO joma_clinical_cases', [], true);
$db15->expect('INSERT INTO joma_case_participants', [], true);
$db15->expect('INSERT INTO joma_case_operational_contexts', [], true);
$db15->expect('UPDATE joma_therapist_assignments', [], true);
$db15->expect('UPDATE joma_admissions', [], true);
joma_acceptance_execute($db15, $snap, $assignmentId, 'پذیرش', $now, $commandId, ['acceptance_id'=>id(50),'relationship_id'=>id(51),'case_id'=>id(52),'participant_id'=>id(53),'context_id'=>id(54)]);
t('no snapshot mutation', $snap===$snapCopy);


echo "\nPHP ".PHP_VERSION." — $checks checks, $failures failures\n";
echo "ACCEPTANCE: synthetic transaction only; no real DB, no concurrency tested.\n";
exit($failures?1:0);
