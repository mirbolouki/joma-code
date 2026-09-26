<?php
declare(strict_types=1);
require dirname(__DIR__) . '/joma-core/domain_rules.php';
require dirname(__DIR__) . '/joma-core/db.php';
require dirname(__DIR__) . '/joma-core/receipt.php';
require dirname(__DIR__) . '/joma-core/audit.php';

set_error_handler(function($s,$m,$f,$l){ if(!(error_reporting()&$s))return false; throw new ErrorException($m,0,$s,$f,$l);});
$checks=0;$failures=0;
function t(string $n,bool $c):void{global $checks,$failures;++$checks;if(!$c)++$failures;echo($c?'PASS ':'FAIL ').$n.PHP_EOL;}
function id(int $i):string{return sprintf('00000000-0000-4000-8000-%012x',$i);}
function binId(int $i):string{$b=joma_db_uuid_to_bin(id($i));if($b===null) throw new RuntimeException('bin');return $b;}

class MockResultR{private $rows;private $idx=0;function __construct(array $r){$this->rows=$r;}function fetch_assoc(){return $this->rows[$this->idx++]??null;}}
class MockStmtR{public string $sql;private $db;private $rows;private bool $ok;function __construct(string $s,$db,array $r,bool $ok=true){$this->sql=$s;$this->db=$db;$this->rows=$r;$this->ok=$ok;}function bind_param(string $t,&...$vars):bool{$this->db->preparedSqls[]=$this->sql;$this->db->allBounds[]=$vars;return true;}function execute():bool{$this->db->executedSqls[]=$this->sql;return $this->ok;}function get_result(){return new MockResultR($this->rows);}function close():void{}}
class MockMysqliR{
    public array $expect=[];public array $preparedSqls=[];public array $executedSqls=[];public array $allBounds=[];
    function expect(string $contains,array $rows=[],bool $ok=true){$this->expect[]=['contains'=>$contains,'rows'=>$rows,'ok'=>$ok];}
    function prepare(string $sql){
        if(strpos($sql,'?')===false) throw new RuntimeException('no placeholder: '.$sql);
        foreach($this->expect as $k=>$e){ if(strpos($sql,$e['contains'])!==false){ $r=$e['rows'];$ok=$e['ok'];unset($this->expect[$k]);$this->expect=array_values($this->expect);return new MockStmtR($sql,$this,$r,$ok);}}
        return new MockStmtR($sql,$this,[],true);
    }
    function begin_transaction():bool{return true;}function commit():bool{return true;}function rollback():bool{return true;}
}

// Canonical
$payload=['b'=>2,'a'=>1,'c'=>['z'=>3,'y'=>2]];
$canon=joma_receipt_canonical($payload);
t('canonical sorted', $canon==='{"a":1,"b":2,"c":{"y":2,"z":3}}');
$canon2=joma_receipt_canonical(['a'=>1,'b'=>2]);
t('canonical stable order', joma_receipt_canonical(['b'=>2,'a'=>1])=== $canon2);
t('canonical rejects non-array', joma_receipt_canonical('string')===null);
t('hash hex length', strlen(joma_receipt_hash($canon))===64);
t('hash deterministic', joma_receipt_hash($canon)===joma_receipt_hash($canon));
$key=joma_receipt_generate_key();
t('key hex 64', preg_match('/\A[0-9a-f]{64}\z/',$key)===1);
t('key bin 32', strlen(joma_receipt_bin_from_hex($key))===32);
t('bin hex roundtrip', joma_receipt_hex_from_bin(joma_receipt_bin_from_hex($key))===$key);
t('bin reject bad hex', joma_receipt_bin_from_hex('zz')===null);

// Receipt claim inserted
$now='2026-09-26 10:00:00.000000';
$scope=id(4); $actor=id(2); $cmd='accept_assignment'; $payloadHex=joma_receipt_hash($canon); $idem=$key;
$db=new MockMysqliR();
$db->expect('INSERT INTO joma_command_receipts', [], true); // success
$res=joma_receipt_claim($db,$scope,$actor,null,$cmd,$idem,$payloadHex,$now);
t('receipt inserted', $res['status']==='INSERTED' && joma_rule_uuid($res['id'])!==null);
t('receipt uses placeholder', count(array_filter($db->preparedSqls,fn($s)=>strpos($s,'?')===false))===0);

// Duplicate same payload -> replay requires auth
$db2=new MockMysqliR();
$db2->expect('INSERT INTO joma_command_receipts', [], false); // duplicate -> execute returns false (simulate duplicate key)
$db2->expect('SELECT id, scope_id', [[
    'id'=>binId(100),'scope_id'=>binId(4),'actor_person_id'=>binId(2),'system_actor_code'=>null,'command_name'=>$cmd,'idempotency_key'=>joma_receipt_bin_from_hex($idem),'payload_hash'=>joma_receipt_bin_from_hex($payloadHex),'status'=>'SUCCEEDED'
]]);
$res=joma_receipt_claim($db2,$scope,$actor,null,$cmd,$idem,$payloadHex,$now);
t('duplicate same payload replay', $res['status']==='EXISTS' && $res['code']==='REPLAY_REQUIRES_CURRENT_AUTHORIZATION');

// Duplicate different payload -> conflict
$db3=new MockMysqliR();
$db3->expect('INSERT INTO joma_command_receipts', [], false);
$otherHash=str_repeat('a',64);
$db3->expect('SELECT id, scope_id', [[
    'id'=>binId(100),'scope_id'=>binId(4),'actor_person_id'=>binId(2),'system_actor_code'=>null,'command_name'=>$cmd,'idempotency_key'=>joma_receipt_bin_from_hex($idem),'payload_hash'=>joma_receipt_bin_from_hex($payloadHex),'status'=>'SUCCEEDED'
]]);
$res=joma_receipt_claim($db3,$scope,$actor,null,$cmd,$idem,$otherHash,$now);
t('duplicate different payload conflict', $res['status']==='CONFLICT' && $res['code']==='IDEMPOTENCY_CONFLICT');

// PROCESSING -> in progress
$db4=new MockMysqliR();
$db4->expect('INSERT INTO joma_command_receipts', [], false);
$db4->expect('SELECT id, scope_id', [[
    'id'=>binId(100),'scope_id'=>binId(4),'actor_person_id'=>binId(2),'system_actor_code'=>null,'command_name'=>$cmd,'idempotency_key'=>joma_receipt_bin_from_hex($idem),'payload_hash'=>joma_receipt_bin_from_hex($payloadHex),'status'=>'PROCESSING'
]]);
$res=joma_receipt_claim($db4,$scope,$actor,null,$cmd,$idem,$payloadHex,$now);
t('processing in progress', $res['code']==='COMMAND_IN_PROGRESS');

// Different actor conflict
$db5=new MockMysqliR();
$db5->expect('INSERT INTO joma_command_receipts', [], false);
$db5->expect('SELECT id, scope_id', [[
    'id'=>binId(100),'scope_id'=>binId(4),'actor_person_id'=>binId(99),'system_actor_code'=>null,'command_name'=>$cmd,'idempotency_key'=>joma_receipt_bin_from_hex($idem),'payload_hash'=>joma_receipt_bin_from_hex($payloadHex),'status'=>'SUCCEEDED'
]]);
$res=joma_receipt_claim($db5,$scope,$actor,null,$cmd,$idem,$payloadHex,$now);
t('different actor conflict', $res['code']==='IDEMPOTENCY_CONFLICT');

// System actor path
$db6=new MockMysqliR();
$db6->expect('INSERT INTO joma_command_receipts', [], true);
$res=joma_receipt_claim($db6,$scope,null,'SYSTEM_CRON',$cmd,$idem,$payloadHex,$now);
t('system actor inserted',$res['status']==='INSERTED');

// Invalid idempotency hex
$db7=new MockMysqliR();
$res=joma_receipt_claim($db7,$scope,$actor,null,$cmd,'badhex',$payloadHex,$now);
t('invalid idempotency rejected',$res['code']==='INVALID_HASH' && count($db7->preparedSqls)===0);

// Receipt complete
$db8=new MockMysqliR();
$db8->expect('UPDATE joma_command_receipts SET status', [], true);
$ok=joma_receipt_complete($db8,id(100),'SUCCEEDED','{"ref":"a"}',$now);
t('receipt complete succeeded',$ok===true);
$db9=new MockMysqliR();
$ok=joma_receipt_complete($db9,'not-uuid','SUCCEEDED',null,$now);
t('receipt complete invalid id',$ok===false);

// Audit log allowed
$dbA=new MockMysqliR();
$dbA->expect('INSERT INTO joma_audit_entries', [], true);
$ok=joma_audit_log($dbA,id(100),id(2),null,id(4),'routing.accept','TherapistAssignment',id(10),'ALLOWED','ACCEPTANCE_GUARD_PASSED',$now,['scope'=>id(4)]);
t('audit allowed inserted',$ok===true);
t('audit uses placeholder', strpos($dbA->preparedSqls[0],'?')!==false);

// Audit rejects secret in metadata
$dbA2=new MockMysqliR();
$ok=joma_audit_log($dbA2,id(100),id(2),null,id(4),'routing.accept','Test',id(10),'ALLOWED','OK',$now,['password'=>'secret']);
t('audit rejects secret',$ok===false && count($dbA2->preparedSqls)===0);

// Audit rejects invalid reason
$dbA3=new MockMysqliR();
$ok=joma_audit_log($dbA3,id(100),id(2),null,id(4),'routing.accept','Test',id(10),'ALLOWED','bad-reason',$now);
t('audit rejects bad reason',$ok===false);

// Audit decision mapping
[$http,$reason]=joma_http_map('CAPACITY_CONFLICT');
t('http map conflict', $http===409);
[$http,$reason]=joma_http_map('AUTHENTICATION_REQUIRED');
t('http map auth', $http===401);
[$http,$reason]=joma_http_map('UNKNOWN_CODE');
t('http map unknown 500',$http===500);

// Audit requires exactly one actor
$dbA4=new MockMysqliR();
$ok=joma_audit_log($dbA4,id(100),null,null,id(4),'routing.accept','Test',id(10),'ALLOWED','OK',$now);
t('audit requires actor',$ok===false);

// Ensure receipt canonical stable for nested
$nested1=['x'=>['b'=>2,'a'=>1],'y'=>3];
$nested2=['y'=>3,'x'=>['a'=>1,'b'=>2]];
t('nested canonical stable', joma_receipt_canonical($nested1)===joma_receipt_canonical($nested2));

echo "\nPHP ".PHP_VERSION." — $checks checks, $failures failures\n";
echo "RECEIPT_AUDIT: synthetic only; no real DB constraints or HTTP.\n";
exit($failures?1:0);
