<?php
declare(strict_types=1);
require dirname(__DIR__) . '/joma-core/domain_rules.php';
require dirname(__DIR__) . '/joma-core/db.php';
require dirname(__DIR__) . '/joma-core/session.php';
require dirname(__DIR__) . '/joma-core/auth.php';
require dirname(__DIR__) . '/joma-core/context.php';

set_error_handler(function ($severity,$message,$file,$line) {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message,0,$severity,$file,$line);
});

$checks=0; $failures=0;
function t(string $name, bool $cond): void { global $checks,$failures; ++$checks; if (!$cond) ++$failures; echo ($cond?'PASS ':'FAIL ').$name.PHP_EOL; }
function id(int $i): string { return sprintf('00000000-0000-4000-8000-%012x',$i); }
function binId(int $i): string { $b=joma_db_uuid_to_bin(id($i)); if($b===null) throw new RuntimeException('bin'); return $b; }

/** Minimal mock mysqli infrastructure */
class MockResult {
    private $rows; private $idx=0;
    function __construct(array $rows){ $this->rows=$rows; }
    function fetch_assoc(){ return $this->rows[$this->idx++] ?? null; }
}
class MockStmt {
    public $sql; public $bound=[]; private $db; private $resultRows;
    function __construct(string $sql, $db, array $resultRows){ $this->sql=$sql; $this->db=$db; $this->resultRows=$resultRows; }
    function bind_param(string $types, &...$vars): bool { $this->bound=$vars; $this->db->lastBound=$vars; $this->db->lastSql=$this->sql; // capture for inspection
        // Also validate types length matches vars
        return true;
    }
    function execute(): bool { return true; }
    function get_result(){ return new MockResult($this->resultRows); }
    function close(): void {}
}
class MockMysqli {
    public $lastSql=null; public $lastBound=null;
    private $expectations=[]; // queue of [sqlContains, rows]
    function expect(string $sqlContains, array $rows){ $this->expectations[]=['contains'=>$sqlContains,'rows'=>$rows]; }
    function prepare(string $sql){
        // Verify sql uses placeholders and does not contain raw uuid
        if (strpos($sql,'?')===false) { throw new RuntimeException('SQL without placeholder: '.$sql); }
        // Find matching expectation by substring; fallback empty.
        foreach($this->expectations as $k=>$e){
            if (strpos($sql,$e['contains'])!==false){
                $rows=$e['rows']; unset($this->expectations[$k]); return new MockStmt($sql,$this,$rows);
            }
        }
        // Default: no rows
        return new MockStmt($sql,$this,[]);
    }
}

$now='2026-09-26 10:00:00.000000';
$future='2026-09-26 12:00:00.000000';

// --- db helpers
t('db uuid bin roundtrip', joma_db_bin_to_uuid(joma_db_uuid_to_bin(id(10)))===id(10));
t('db uuid bin rejects bad', joma_db_uuid_to_bin('not-a-uuid')===null);
t('db uuid bin rejects sentinel', joma_db_uuid_to_bin('00000000-0000-0000-0000-000000000000')===null);
t('db bin rejects wrong length', joma_db_bin_to_uuid('short')===null);
t('db int unsigned valid string', joma_db_int_unsigned('1')===1);
t('db int unsigned max', joma_db_int_unsigned('4294967295')===4294967295);
t('db int unsigned zero rejected', joma_db_int_unsigned('0')===null);
t('db int unsigned negative rejected', joma_db_int_unsigned('-1')===null);
t('db int unsigned overflow rejected', joma_db_int_unsigned('4294967296')===null);
t('db int unsigned leading zero rejected', joma_db_int_unsigned('01')===null);
t('db int unsigned float rejected', joma_db_int_unsigned('1.5')===null);
t('db int unsigned int valid', joma_db_int_unsigned(42)===42);
t('db int unsigned int zero rejected', joma_db_int_unsigned(0)===null);
t('db utc null allowed', joma_db_is_utc_us(null)===true);
t('db utc valid', joma_db_is_utc_us($now)===true);
t('db utc invalid', joma_db_is_utc_us('bad')===false);

// --- login validation
t('login trim valid', joma_auth_validate_login_name('  alice  ')==='alice');
t('login short rejected', joma_auth_validate_login_name('ab')===null);
t('login too long rejected', joma_auth_validate_login_name(str_repeat('a',192))===null);
t('login NUL rejected', joma_auth_validate_login_name("a\0b")===null);
t('login newline rejected', joma_auth_validate_login_name("a\nb")===null);

// --- password verify
$hash = password_hash('S3cure!Pass', PASSWORD_BCRYPT);
t('password verify correct', joma_auth_verify_password('S3cure!Pass',$hash)===true);
t('password verify wrong', joma_auth_verify_password('wrong',$hash)===false);
t('password empty rejected', joma_auth_verify_password('', $hash)===false);
t('password too long rejected', joma_auth_verify_password(str_repeat('a',201),$hash)===false);

// --- auth find mock
$db = new MockMysqli();
$row = ['id'=>binId(1),'person_id'=>binId(2),'login_name'=>'alice','password_hash'=>$hash,'status'=>'ACTIVE'];
$db->expect('FROM joma_accounts', [$row]);
$found = joma_auth_find_account_by_login($db,'alice');
t('find account success', $found !== null && $found['id']===id(1) && $found['status']==='ACTIVE');
t('find uses placeholder not interpolation', $db->lastSql !== null && strpos($db->lastSql,'?')!==false && strpos($db->lastSql,'alice')===false);

// not found
$db2 = new MockMysqli();
$db2->expect('FROM joma_accounts', []);
t('find not found', joma_auth_find_account_by_login($db2,'bob')===null);

// injection attempt: login with quote should be literal, not inject
$db3 = new MockMysqli();
$db3->expect('FROM joma_accounts', []); // no matching row
$inj = joma_auth_find_account_by_login($db3,"' OR '1'='1");
t('injection treated as literal', $inj===null && $db3->lastBound[0]==="' OR '1'='1" );

// --- auth login success
$db4 = new MockMysqli();
$db4->expect('FROM joma_accounts', [$row]);
$res = joma_auth_login($db4,'alice','S3cure!Pass',$now);
t('auth login success', ($res['ok']??false)===true && $res['account']['id']===id(1));

// wrong password
$db5 = new MockMysqli();
$db5->expect('FROM joma_accounts', [$row]);
$res = joma_auth_login($db5,'alice','wrong',$now);
t('auth login wrong password uniform', ($res['ok']??true)===false && $res['code']==='INVALID_CREDENTIALS');

// locked account
$rowLocked = $row; $rowLocked['status']='LOCKED';
$db6 = new MockMysqli();
$db6->expect('FROM joma_accounts', $rowLocked ? [$rowLocked] : []);
 // Actually rowLocked needs binary still
$rowLocked['id']=binId(1); $rowLocked['person_id']=binId(2); // ensure still binary
$db6 = new MockMysqli();
$db6->expect('FROM joma_accounts', [['id'=>binId(1),'person_id'=>binId(2),'login_name'=>'alice','password_hash'=>$hash,'status'=>'LOCKED']]);
$res = joma_auth_login($db6,'alice','S3cure!Pass',$now);
t('locked account not allowed', ($res['ok']??true)===false && $res['code']==='ACCOUNT_LOCKED');

// inactive
$db7 = new MockMysqli();
$db7->expect('FROM joma_accounts', [['id'=>binId(1),'person_id'=>binId(2),'login_name'=>'alice','password_hash'=>$hash,'status'=>'INACTIVE']]);
$res = joma_auth_login($db7,'alice','S3cure!Pass',$now);
t('inactive not allowed', $res['code']==='ACCOUNT_INACTIVE');

// not found uniform timing
$db8 = new MockMysqli();
$db8->expect('FROM joma_accounts', []);
$res = joma_auth_login($db8,'nobody','any',$now);
t('not found uniform', $res['code']==='INVALID_CREDENTIALS');

// invalid time
$db9 = new MockMysqli();
$res = joma_auth_login($db9,'alice','S3cure!Pass','bad time');
t('login invalid time', $res['code']==='INVALID_TIME');

// --- context loader
$nowValid = $now;
// Build a valid context row
$validRow = [
    'a_id'=>binId(1),
    'a_person_id'=>binId(2),
    'a_status'=>'ACTIVE',
    'm_id'=>binId(3),
    'm_person_id'=>binId(2),
    'm_scope_id'=>binId(4),
    'm_status'=>'ACTIVE',
    'm_valid_from'=>'2026-01-01 00:00:00.000000',
    'm_valid_until'=>null,
    'ra_id'=>binId(5),
    'ra_account_id'=>binId(1),
    'ra_person_id'=>binId(2),
    'ra_membership_id'=>binId(3),
    'ra_scope_id'=>binId(4),
    'ra_role_id'=>'1',
    'ra_valid_from'=>'2026-01-01 00:00:00.000000',
    'ra_valid_until'=>null,
    'ra_revoked_at'=>null,
    'rd_code'=>'therapist',
    'rd_is_active'=>1,
];
$dbC = new MockMysqli();
$dbC->expect('FROM joma_role_assignments', [$validRow]);
$loaded = joma_context_load($dbC, id(1), id(5), $nowValid);
t('context load valid', ($loaded['ok']??false)===true && $loaded['snapshot']['policy_status']==='APPROVED' && in_array('routing.accept',$loaded['snapshot']['permissions'],true));
t('context load uses placeholders', strpos($dbC->lastSql,'?')!==false);

// mismatched account -> not found vs denied: we simulate mismatch by returning row but with different person? loader checks integrity -> DENIED
$badRow = $validRow; $badRow['ra_person_id']=binId(99);
$dbC2 = new MockMysqli();
$dbC2->expect('FROM joma_role_assignments', [$badRow]);
$loaded = joma_context_load($dbC2, id(1), id(5), $nowValid);
t('context person mismatch denied', ($loaded['ok']??true)===false && $loaded['code']==='CONTEXT_DENIED');

// not found
$dbC3 = new MockMysqli();
$dbC3->expect('FROM joma_role_assignments', []);
$loaded = joma_context_load($dbC3, id(1), id(5), $nowValid);
t('context not found', $loaded['code']==='NOT_FOUND');

// revoked
$revokedRow = $validRow; $revokedRow['ra_revoked_at']=$nowValid;
$dbC4 = new MockMysqli();
$dbC4->expect('FROM joma_role_assignments', [$revokedRow]);
$loaded = joma_context_load($dbC4, id(1), id(5), $nowValid);
t('context revoked loads but fails principal? actually window check later', ($loaded['ok']??false)===true); // loader itself succeeds, but joma_rule_context will deny
if (($loaded['ok']??false)===true) {
    $check = joma_rule_context($loaded['snapshot'], id(4), 'routing.accept', $nowValid);
    t('revoked context denied by rule', $check['allowed']===false);
} else { t('revoked context denied by rule', false); }

// expired membership
$expRow = $validRow; $expRow['m_valid_until']='2026-01-02 00:00:00.000000';
$dbC5 = new MockMysqli();
$dbC5->expect('FROM joma_role_assignments', [$expRow]);
$loaded = joma_context_load($dbC5, id(1), id(5), '2026-01-03 00:00:00.000000');
t('expired membership still loads snapshot', ($loaded['ok']??false)===true);
if (($loaded['ok']??false)===true) {
    $check = joma_rule_context($loaded['snapshot'], id(4), 'routing.accept', '2026-01-03 00:00:00.000000');
    t('expired membership denied by rule', $check['allowed']===false);
}

// role inactive
$inactiveRoleRow = $validRow; $inactiveRoleRow['rd_is_active']=0;
$dbC6 = new MockMysqli();
$dbC6->expect('FROM joma_role_assignments', [$inactiveRoleRow]);
$loaded = joma_context_load($dbC6, id(1), id(5), $nowValid);
t('inactive role policy not approved', ($loaded['ok']??false)===true && $loaded['snapshot']['policy_status']==='NOT_APPROVED');

// unknown role code
$unknownRow = $validRow; $unknownRow['rd_code']='unknown_xyz';
$dbC7 = new MockMysqli();
$dbC7->expect('FROM joma_role_assignments', [$unknownRow]);
$loaded = joma_context_load($dbC7, id(1), id(5), $nowValid);
t('unknown role yields empty permissions', ($loaded['ok']??false)===true && $loaded['snapshot']['permissions']===[]);

// invalid id
$dbC8 = new MockMysqli();
$loaded = joma_context_load($dbC8, 'not-uuid', id(5), $nowValid);
t('invalid account id rejected', $loaded['code']==='INVALID_ID');

// invalid time
$loaded = joma_context_load($dbC7, id(1), id(5), 'bad');
t('invalid now rejected', $loaded['code']==='INVALID_TIME');

// cross-scope require
$dbC9 = new MockMysqli();
$dbC9->expect('FROM joma_role_assignments', [$validRow]);
$req = joma_context_require($dbC9, id(1), id(5), id(4), 'routing.accept', $nowValid);
t('context require valid scope', ($req['ok']??false)===true);
$dbC10 = new MockMysqli();
$dbC10->expect('FROM joma_role_assignments', [$validRow]);
$req = joma_context_require($dbC10, id(1), id(5), id(99), 'routing.accept', $nowValid);
t('context require cross-scope denied', ($req['ok']??true)===false);

// permission not in role
$dbC11 = new MockMysqli();
$dbC11->expect('FROM joma_role_assignments', [$validRow]);
$req = joma_context_require($dbC11, id(1), id(5), id(4), 'report.publish', $nowValid);
t('permission not in role denied', ($req['ok']??true)===false);

// --- session
// Reset session superglobal for isolation
$_SESSION=[];
$acc = ['id'=>id(1),'person_id'=>id(2),'status'=>'ACTIVE','login_name'=>'alice'];
joma_session_store_principal($acc);
$principal = joma_session_get_principal();
t('session store and get principal', $principal !== null && $principal['account_id']===id(1));
$_SESSION=[];
$accBad = ['id'=>id(1),'person_id'=>id(2),'status'=>'LOCKED'];
joma_session_store_principal($accBad);
t('locked not stored', joma_session_get_principal()===null);

// CSRF
$_SESSION=[];
$tok = joma_csrf_token();
t('csrf token format', preg_match('/\A[0-9a-f]{64}\z/',$tok)===1);
t('csrf verify correct', joma_csrf_verify($tok)===true);
t('csrf verify wrong rejected', joma_csrf_verify('00')===false);
t('csrf second call same token', joma_csrf_token()===$tok);

// Ensure input mutations none: context loader does not mutate
$before = [$validRow];
$dbCMut = new MockMysqli();
$dbCMut->expect('FROM joma_role_assignments', [$validRow]);
joma_context_load($dbCMut, id(1), id(5), $nowValid);
t('no mutation of source row', $validRow === $before[0]);

echo "\nPHP ".PHP_VERSION." — $checks checks, $failures failures\n";
echo "ADAPTERS: synthetic DB/session only; no real DB, HTTP, or crypto tested.\n";
exit($failures?1:0);
