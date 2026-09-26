<?php
declare(strict_types=1);
require dirname(__DIR__) . '/joma-core/domain_rules.php';
require dirname(__DIR__) . '/joma-core/db.php';
require dirname(__DIR__) . '/joma-core/portal.php';

set_error_handler(function($s,$m,$f,$l){ if(!(error_reporting()&$s))return false; throw new ErrorException($m,0,$s,$f,$l);});
$checks=0;$failures=0;
function t(string $n,bool $c):void{global $checks,$failures;++$checks;if(!$c)++$failures;echo($c?'PASS ':'FAIL ').$n.PHP_EOL;}
function id(int $i):string{return sprintf('00000000-0000-4000-8000-%012x',$i);}
function binId(int $i):string{ $b=joma_db_uuid_to_bin(id($i)); if($b===null) throw new RuntimeException('bin'); return $b;}
function snap():array{ return ['account'=>['id'=>id(20),'person_id'=>id(21),'status'=>'ACTIVE']];}
$now='2026-09-26 10:00:00.000000';

class MockResultP{private $rows;private $idx=0;function __construct(array $r){$this->rows=$r;}function fetch_assoc(){return $this->rows[$this->idx++]??null;}}
class MockStmtP{public string $sql;private $db;private $rows;public array $bound=[];function __construct(string $s,$db,array $r){$this->sql=$s;$this->db=$db;$this->rows=$r;}function bind_param(string $t,&...$vars):bool{$this->bound=$vars;$this->db->preparedSqls[]=$this->sql;$this->db->allBounds[]=$vars;return true;}function execute():bool{$this->db->executedSqls[]=$this->sql;return true;}function get_result(){return new MockResultP($this->rows);}function close():void{}}
class MockMysqliP{
    public array $expect=[];public array $preparedSqls=[];public array $executedSqls=[];public array $allBounds=[];
    function expect(string $contains,array $rows){$this->expect[]=['contains'=>$contains,'rows'=>$rows];}
    function prepare(string $sql){
        if(strpos($sql,'?')===false) throw new RuntimeException('no placeholder');
        foreach($this->expect as $k=>$e){ if(strpos($sql,$e['contains'])!==false){ $r=$e['rows']; unset($this->expect[$k]); $this->expect=array_values($this->expect); return new MockStmtP($sql,$this,$r);}}
        return new MockStmtP($sql,$this,[]);
    }
}

// Base resource: REPORT, version id(23), report id(22), case 10 scope 4, subject 21, no product
function baseResource(?int $product=null,?array $subjects=null):array{
    return ['kind'=>'REPORT','id'=>id(22),'version_id'=>id(23),'case_id'=>id(10),'scope_id'=>id(4),'subject_person_ids'=>$subjects??[id(21)],'service_id'=>1,'required_product_id'=>$product,'policy_status'=>'APPROVED','revision_state'=>'REVIEWED'];
}

// Direct success
$db=new MockMysqliP();
// Need to mock join publication+audience
$db->expect('FROM joma_report_publications rp JOIN joma_report_publication_audiences', [[
    'pub_id'=>binId(24),'pub_version_id'=>binId(23),'pub_case_id'=>binId(10),'channel'=>'PORTAL','published_at'=>$now,'revoked_at'=>null,
    'aud_id'=>binId(30),'publication_id'=>binId(24),'recipient_person_id'=>binId(21),'access_subject_person_id'=>binId(21),'aud_scope_id'=>binId(4),'basis_kind'=>'DIRECT_PERSON','representation_id'=>null,'aud_revoked'=>null,
]]);
$res=joma_portal_check($db,snap(),baseResource(),$now);
t('direct portal allowed',$res['allowed']===true);

// No audience -> deny
$db2=new MockMysqliP();
$db2->expect('FROM joma_report_publications rp JOIN joma_report_publication_audiences', []); // empty
$res=joma_portal_check($db2,snap(),baseResource(),$now);
t('no audience denied',$res['allowed']===false);

// Publication future -> domain rule deny (published_at > now)
$db3=new MockMysqliP();
$db3->expect('FROM joma_report_publications rp JOIN joma_report_publication_audiences', [[
    'pub_id'=>binId(24),'pub_version_id'=>binId(23),'pub_case_id'=>binId(10),'channel'=>'PORTAL','published_at'=>'2026-09-26 10:15:00.000000','revoked_at'=>null,
    'aud_id'=>binId(30),'publication_id'=>binId(24),'recipient_person_id'=>binId(21),'access_subject_person_id'=>binId(21),'aud_scope_id'=>binId(4),'basis_kind'=>'DIRECT_PERSON','representation_id'=>null,'aud_revoked'=>null,
]]);
$res=joma_portal_check($db3,snap(),baseResource(),$now);
t('future publication denied',$res['allowed']===false);

// Revoked publication -> no row (mock returns empty due to WHERE revoked_at IS NULL), so deny
$db3b=new MockMysqliP();
$db3b->expect('FROM joma_report_publications rp JOIN joma_report_publication_audiences', []); // because revoked, query filtered
$res=joma_portal_check($db3b,snap(),baseResource(),$now);
t('revoked publication no row denied',$res['allowed']===false);

// Draft resource denied before DB? Actually resource revision_state DRAFT will be denied by domain rule even if DB returns publication; we should test that domain rule still denies.
$db4=new MockMysqliP();
$db4->expect('FROM joma_report_publications rp JOIN joma_report_publication_audiences', [[
    'pub_id'=>binId(24),'pub_version_id'=>binId(23),'pub_case_id'=>binId(10),'channel'=>'PORTAL','published_at'=>$now,'revoked_at'=>null,
    'aud_id'=>binId(30),'publication_id'=>binId(24),'recipient_person_id'=>binId(21),'access_subject_person_id'=>binId(21),'aud_scope_id'=>binId(4),'basis_kind'=>'DIRECT_PERSON','representation_id'=>null,'aud_revoked'=>null,
]]);
$draftRes=baseResource(); $draftRes['revision_state']='DRAFT_CLINICIAN_REVIEW';
$res=joma_portal_check($db4,snap(),$draftRes,$now);
t('draft resource denied',$res['allowed']===false);

// Representative success with no restrictions
$db5=new MockMysqliP();
$db5->expect('FROM joma_report_publications rp JOIN joma_report_publication_audiences', [[
    'pub_id'=>binId(24),'pub_version_id'=>binId(23),'pub_case_id'=>binId(10),'channel'=>'PORTAL','published_at'=>$now,'revoked_at'=>null,
    'aud_id'=>binId(31),'publication_id'=>binId(24),'recipient_person_id'=>binId(21),'access_subject_person_id'=>binId(30),'aud_scope_id'=>binId(4),'basis_kind'=>'REPRESENTATIVE','representation_id'=>binId(40),'aud_revoked'=>null,
]]);
$db5->expect('FROM joma_representations WHERE id', [[
    'id'=>binId(40),'representative_person_id'=>binId(21),'subject_person_id'=>binId(30),'scope_id'=>binId(4),'status'=>'VERIFIED','allowed_actions_json'=>'["document.read"]','valid_from'=>null,'valid_until'=>null,'revoked_at'=>null,
]]);
$db5->expect('FROM joma_case_representation_restrictions', []); // no restrictions
$childRes=baseResource(null,[id(30)]);
$res=joma_portal_check($db5,snap(),$childRes,$now);
t('representative with no restrictions allowed',$res['allowed']===true);

// Representative with case-specific suspension denied
$db6=new MockMysqliP();
$db6->expect('FROM joma_report_publications rp JOIN joma_report_publication_audiences', [[
    'pub_id'=>binId(24),'pub_version_id'=>binId(23),'pub_case_id'=>binId(10),'channel'=>'PORTAL','published_at'=>$now,'revoked_at'=>null,
    'aud_id'=>binId(31),'publication_id'=>binId(24),'recipient_person_id'=>binId(21),'access_subject_person_id'=>binId(30),'aud_scope_id'=>binId(4),'basis_kind'=>'REPRESENTATIVE','representation_id'=>binId(40),'aud_revoked'=>null,
]]);
$db6->expect('FROM joma_representations WHERE id', [[
    'id'=>binId(40),'representative_person_id'=>binId(21),'subject_person_id'=>binId(30),'scope_id'=>binId(4),'status'=>'VERIFIED','allowed_actions_json'=>'["document.read"]','valid_from'=>null,'valid_until'=>null,'revoked_at'=>null,
]]);
$db6->expect('FROM joma_case_representation_restrictions', [[
    'representation_id'=>binId(40),'case_id'=>binId(10),'service_id'=>null,'status'=>'SUSPENDED',
]]);
$res=joma_portal_check($db6,snap(),$childRes,$now);
t('representative case suspension denied',$res['allowed']===false);

// Other case suspension not affect
$db7=new MockMysqliP();
$db7->expect('FROM joma_report_publications rp JOIN joma_report_publication_audiences', [[
    'pub_id'=>binId(24),'pub_version_id'=>binId(23),'pub_case_id'=>binId(10),'channel'=>'PORTAL','published_at'=>$now,'revoked_at'=>null,
    'aud_id'=>binId(31),'publication_id'=>binId(24),'recipient_person_id'=>binId(21),'access_subject_person_id'=>binId(30),'aud_scope_id'=>binId(4),'basis_kind'=>'REPRESENTATIVE','representation_id'=>binId(40),'aud_revoked'=>null,
]]);
$db7->expect('FROM joma_representations WHERE id', [[
    'id'=>binId(40),'representative_person_id'=>binId(21),'subject_person_id'=>binId(30),'scope_id'=>binId(4),'status'=>'VERIFIED','allowed_actions_json'=>'["document.read"]','valid_from'=>null,'valid_until'=>null,'revoked_at'=>null,
]]);
$db7->expect('FROM joma_case_representation_restrictions', [[
    'representation_id'=>binId(40),'case_id'=>binId(99),'service_id'=>null,'status'=>'SUSPENDED',
]]);
$res=joma_portal_check($db7,snap(),$childRes,$now);
t('other case suspension allowed',$res['allowed']===true);

// Representation revoked -> domain deny
$db8=new MockMysqliP();
$db8->expect('FROM joma_report_publications rp JOIN joma_report_publication_audiences', [[
    'pub_id'=>binId(24),'pub_version_id'=>binId(23),'pub_case_id'=>binId(10),'channel'=>'PORTAL','published_at'=>$now,'revoked_at'=>null,
    'aud_id'=>binId(31),'publication_id'=>binId(24),'recipient_person_id'=>binId(21),'access_subject_person_id'=>binId(30),'aud_scope_id'=>binId(4),'basis_kind'=>'REPRESENTATIVE','representation_id'=>binId(40),'aud_revoked'=>null,
]]);
$db8->expect('FROM joma_representations WHERE id', [[
    'id'=>binId(40),'representative_person_id'=>binId(21),'subject_person_id'=>binId(30),'scope_id'=>binId(4),'status'=>'REVOKED','allowed_actions_json'=>'["document.read"]','valid_from'=>null,'valid_until'=>null,'revoked_at'=>$now,
]]);
$db8->expect('FROM joma_case_representation_restrictions', []);
$res=joma_portal_check($db8,snap(),$childRes,$now);
t('revoked representation denied',$res['allowed']===false);

// Wrong allowed_actions
$db9=new MockMysqliP();
$db9->expect('FROM joma_report_publications rp JOIN joma_report_publication_audiences', [[
    'pub_id'=>binId(24),'pub_version_id'=>binId(23),'pub_case_id'=>binId(10),'channel'=>'PORTAL','published_at'=>$now,'revoked_at'=>null,
    'aud_id'=>binId(31),'publication_id'=>binId(24),'recipient_person_id'=>binId(21),'access_subject_person_id'=>binId(30),'aud_scope_id'=>binId(4),'basis_kind'=>'REPRESENTATIVE','representation_id'=>binId(40),'aud_revoked'=>null,
]]);
$db9->expect('FROM joma_representations WHERE id', [[
    'id'=>binId(40),'representative_person_id'=>binId(21),'subject_person_id'=>binId(30),'scope_id'=>binId(4),'status'=>'VERIFIED','allowed_actions_json'=>'[]','valid_from'=>null,'valid_until'=>null,'revoked_at'=>null,
]]);
$db9->expect('FROM joma_case_representation_restrictions', []);
$res=joma_portal_check($db9,snap(),$childRes,$now);
t('representative without document.read denied',$res['allowed']===false);

// Entitlement required and present
$paidRes=baseResource(7,[id(21)]);
$db10=new MockMysqliP();
$db10->expect('FROM joma_report_publications rp JOIN joma_report_publication_audiences', [[
    'pub_id'=>binId(24),'pub_version_id'=>binId(23),'pub_case_id'=>binId(10),'channel'=>'PORTAL','published_at'=>$now,'revoked_at'=>null,
    'aud_id'=>binId(30),'publication_id'=>binId(24),'recipient_person_id'=>binId(21),'access_subject_person_id'=>binId(21),'aud_scope_id'=>binId(4),'basis_kind'=>'DIRECT_PERSON','representation_id'=>null,'aud_revoked'=>null,
]]);
$db10->expect('FROM joma_product_entitlements WHERE product_id', [[
    'product_id'=>'7','beneficiary_person_id'=>binId(21),'source_verified'=>1,'valid_from'=>null,'valid_until'=>null,'revoked_at'=>null,
]]);
$res=joma_portal_check($db10,snap(),$paidRes,$now);
t('entitlement present allowed',$res['allowed']===true);

// Entitlement missing -> deny
$db11=new MockMysqliP();
$db11->expect('FROM joma_report_publications rp JOIN joma_report_publication_audiences', [[
    'pub_id'=>binId(24),'pub_version_id'=>binId(23),'pub_case_id'=>binId(10),'channel'=>'PORTAL','published_at'=>$now,'revoked_at'=>null,
    'aud_id'=>binId(30),'publication_id'=>binId(24),'recipient_person_id'=>binId(21),'access_subject_person_id'=>binId(21),'aud_scope_id'=>binId(4),'basis_kind'=>'DIRECT_PERSON','representation_id'=>null,'aud_revoked'=>null,
]]);
$db11->expect('FROM joma_product_entitlements WHERE product_id', []); // not found
$res=joma_portal_check($db11,snap(),$paidRes,$now);
t('entitlement missing denied',$res['allowed']===false);

// Unverified entitlement denied
$db12=new MockMysqliP();
$db12->expect('FROM joma_report_publications rp JOIN joma_report_publication_audiences', [[
    'pub_id'=>binId(24),'pub_version_id'=>binId(23),'pub_case_id'=>binId(10),'channel'=>'PORTAL','published_at'=>$now,'revoked_at'=>null,
    'aud_id'=>binId(30),'publication_id'=>binId(24),'recipient_person_id'=>binId(21),'access_subject_person_id'=>binId(21),'aud_scope_id'=>binId(4),'basis_kind'=>'DIRECT_PERSON','representation_id'=>null,'aud_revoked'=>null,
]]);
$db12->expect('FROM joma_product_entitlements WHERE product_id', [[
    'product_id'=>'7','beneficiary_person_id'=>binId(21),'source_verified'=>0,'valid_from'=>null,'valid_until'=>null,'revoked_at'=>null,
]]);
$res=joma_portal_check($db12,snap(),$paidRes,$now);
t('unverified entitlement denied',$res['allowed']===false);

// Expired entitlement denied
$db13=new MockMysqliP();
$db13->expect('FROM joma_report_publications rp JOIN joma_report_publication_audiences', [[
    'pub_id'=>binId(24),'pub_version_id'=>binId(23),'pub_case_id'=>binId(10),'channel'=>'PORTAL','published_at'=>$now,'revoked_at'=>null,
    'aud_id'=>binId(30),'publication_id'=>binId(24),'recipient_person_id'=>binId(21),'access_subject_person_id'=>binId(21),'aud_scope_id'=>binId(4),'basis_kind'=>'DIRECT_PERSON','representation_id'=>null,'aud_revoked'=>null,
]]);
$db13->expect('FROM joma_product_entitlements WHERE product_id', [[
    'product_id'=>'7','beneficiary_person_id'=>binId(21),'source_verified'=>1,'valid_from'=>'2026-01-01 00:00:00.000000','valid_until'=>$now,'revoked_at'=>null,
]]);
$res=joma_portal_check($db13,snap(),$paidRes,$now);
t('expired entitlement denied',$res['allowed']===false);

// Form response direct success (submitted)
$formRes=['kind'=>'FORM_RESPONSE','id'=>id(50),'version_id'=>id(51),'case_id'=>id(10),'scope_id'=>id(4),'subject_person_ids'=>[id(21)],'service_id'=>1,'required_product_id'=>null,'policy_status'=>'APPROVED','revision_state'=>'SUBMITTED'];
$db14=new MockMysqliP();
$db14->expect('FROM joma_form_publications fp JOIN joma_form_publication_audiences', [[
    'pub_id'=>binId(60),'pub_version_id'=>binId(51),'pub_case_id'=>binId(10),'published_at'=>$now,'revoked_at'=>null,
    'aud_id'=>binId(61),'publication_id'=>binId(60),'recipient_person_id'=>binId(21),'access_subject_person_id'=>binId(21),'aud_scope_id'=>binId(4),'basis_kind'=>'DIRECT_PERSON','representation_id'=>null,'aud_revoked'=>null,
]]);
$res=joma_portal_check($db14,snap(),$formRes,$now);
t('form submitted direct allowed',$res['allowed']===true);

// Form draft denied
$formDraft=$formRes; $formDraft['revision_state']='DRAFT';
$db14b=new MockMysqliP();
$db14b->expect('FROM joma_form_publications fp JOIN joma_form_publication_audiences', [[
    'pub_id'=>binId(60),'pub_version_id'=>binId(51),'pub_case_id'=>binId(10),'published_at'=>$now,'revoked_at'=>null,
    'aud_id'=>binId(61),'publication_id'=>binId(60),'recipient_person_id'=>binId(21),'access_subject_person_id'=>binId(21),'aud_scope_id'=>binId(4),'basis_kind'=>'DIRECT_PERSON','representation_id'=>null,'aud_revoked'=>null,
]]);
$res=joma_portal_check($db14b,snap(),$formDraft,$now);
t('form draft denied',$res['allowed']===false);

// Uses placeholders only
$db15=new MockMysqliP();
$db15->expect('FROM joma_report_publications rp JOIN joma_report_publication_audiences', [[
    'pub_id'=>binId(24),'pub_version_id'=>binId(23),'pub_case_id'=>binId(10),'channel'=>'PORTAL','published_at'=>$now,'revoked_at'=>null,
    'aud_id'=>binId(30),'publication_id'=>binId(24),'recipient_person_id'=>binId(21),'access_subject_person_id'=>binId(21),'aud_scope_id'=>binId(4),'basis_kind'=>'DIRECT_PERSON','representation_id'=>null,'aud_revoked'=>null,
]]);
joma_portal_check($db15,snap(),baseResource(),$now);
t('portal uses placeholders',count(array_filter($db15->preparedSqls,fn($s)=>strpos($s,'?')===false))===0);
t('portal no interpolation',count(array_filter($db15->preparedSqls,fn($s)=>strpos($s,id(21))!==false))===0);

// Private note author
$note=['kind'=>'PRIVATE_NOTE','author_person_id'=>id(21)];
t('private note author allowed',joma_private_note_check(snap(),$note)['allowed']===true);
$noteBad=['kind'=>'PRIVATE_NOTE','author_person_id'=>id(99)];
t('private note other denied',joma_private_note_check(snap(),$noteBad)['allowed']===false);

echo "\nPHP ".PHP_VERSION." — $checks checks, $failures failures\n";
echo "PORTAL: synthetic DB audience checks only; no real file delivery.\n";
exit($failures?1:0);
