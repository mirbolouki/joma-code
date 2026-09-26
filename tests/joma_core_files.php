<?php
declare(strict_types=1);
require dirname(__DIR__) . '/joma-core/domain_rules.php';
require dirname(__DIR__) . '/joma-core/db.php';
require dirname(__DIR__) . '/joma-core/files.php';

set_error_handler(function($s,$m,$f,$l){ if(!(error_reporting()&$s))return false; throw new ErrorException($m,0,$s,$f,$l);});
$checks=0;$failures=0;
function t(string $n,bool $c):void{global $checks,$failures;++$checks;if(!$c)++$failures;echo($c?'PASS ':'FAIL ').$n.PHP_EOL;}
function id(int $i):string{return sprintf('00000000-0000-4000-8000-%012x',$i);}
function binId(int $i):string{$b=joma_db_uuid_to_bin(id($i));if($b===null)throw new RuntimeException('bin');return $b;}
function snap():array{return ['account'=>['id'=>id(20),'person_id'=>id(21),'status'=>'ACTIVE']];}
$now='2026-09-26 10:00:00.000000';

class MockResultF{private $rows;private $idx=0;function __construct(array $r){$this->rows=$r;}function fetch_assoc(){return $this->rows[$this->idx++]??null;}}
class MockStmtF{public string $sql;private $db;private $rows;function __construct(string $s,$db,array $r){$this->sql=$s;$this->db=$db;$this->rows=$r;}function bind_param(string $t,&...$vars):bool{$this->db->preparedSqls[]=$this->sql;return true;}function execute():bool{$this->db->executedSqls[]=$this->sql;return true;}function get_result(){return new MockResultF($this->rows);}function close():void{}}
class MockMysqliF{
    public array $expect=[];public array $preparedSqls=[];public array $executedSqls=[];
    function expect(string $c,array $r){$this->expect[]=['contains'=>$c,'rows'=>$r];}
    function prepare(string $sql){
        if(strpos($sql,'?')===false) throw new RuntimeException('no placeholder');
        foreach($this->expect as $k=>$e){ if(strpos($sql,$e['contains'])!==false){ $r=$e['rows']; unset($this->expect[$k]);$this->expect=array_values($this->expect); return new MockStmtF($sql,$this,$r);}}
        return new MockStmtF($sql,$this,[]);
    }
}

// Validate row
$goodRow=['id'=>id(70),'storage_key'=>'reports/'.id(70).'.pdf','original_name'=>'گزارش.pdf','media_type'=>'application/pdf','byte_length'=>12345,'sha256'=>random_bytes(32),'state'=>'READY'];
t('validate good row', joma_files_validate_row($goodRow)===true);
$bad=$goodRow;$bad['state']='QUARANTINED'; t('validate quarantine denied', joma_files_validate_row($bad)===false);
$bad=$goodRow;$bad['storage_key']='../etc/passwd'; t('validate traversal denied', joma_files_validate_row($bad)===false);
$bad=$goodRow;$bad['byte_length']=0; t('validate zero length denied', joma_files_validate_row($bad)===false);
$bad=$goodRow;$bad['sha256']='short'; t('validate sha length denied', joma_files_validate_row($bad)===false);

// Load file
$db=new MockMysqliF();
$db->expect('FROM joma_protected_files WHERE id', [[ 'id'=>binId(70),'storage_key'=>'reports/'.id(70).'.pdf','original_name'=>'گزارش.pdf','media_type'=>'application/pdf','byte_length'=>'12345','sha256'=>random_bytes(32),'state'=>'READY']]);
$row=joma_files_load($db,id(70));
t('load file success',$row!==null && $row['id']===id(70));
t('load uses placeholder',strpos($db->preparedSqls[0],'?')!==false);

// Not found
$db2=new MockMysqliF();
$db2->expect('FROM joma_protected_files WHERE id', []);
t('load not found null', joma_files_load($db2,id(99))===null);

// Authorize report file: need linkage + portal
$resource=['kind'=>'REPORT','id'=>id(22),'version_id'=>id(23),'case_id'=>id(10),'scope_id'=>id(4),'subject_person_ids'=>[id(21)],'service_id'=>1,'required_product_id'=>null,'policy_status'=>'APPROVED','revision_state'=>'REVIEWED'];
$fileRow=$goodRow; $fileRow['id']=id(80); // file id 80 linked to version 23
$db3=new MockMysqliF();
// linkage check: report_versions WHERE id=? AND protected_file_id=?
$db3->expect('FROM joma_report_versions WHERE id = ? AND protected_file_id', [['id'=>binId(23)]]);
// then portal check will do: publication+audience join
$db3->expect('FROM joma_report_publications rp JOIN joma_report_publication_audiences', [[
    'pub_id'=>binId(24),'pub_version_id'=>binId(23),'pub_case_id'=>binId(10),'channel'=>'PORTAL','published_at'=>$now,'revoked_at'=>null,
    'aud_id'=>binId(30),'publication_id'=>binId(24),'recipient_person_id'=>binId(21),'access_subject_person_id'=>binId(21),'aud_scope_id'=>binId(4),'basis_kind'=>'DIRECT_PERSON','representation_id'=>null,'aud_revoked'=>null,
]]);
$res=joma_files_authorize($db3,snap(),$fileRow,$resource,$now);
t('file authorize direct allowed',$res['allowed']===true);

// Linkage missing -> deny
$db4=new MockMysqliF();
$db4->expect('FROM joma_report_versions WHERE id = ? AND protected_file_id', []); // no linkage
$res=joma_files_authorize($db4,snap(),$fileRow,$resource,$now);
t('file linkage missing denied',$res['allowed']===false);

// No portal audience -> deny
$db5=new MockMysqliF();
$db5->expect('FROM joma_report_versions WHERE id = ? AND protected_file_id', [['id'=>binId(23)]]);
$db5->expect('FROM joma_report_publications rp JOIN joma_report_publication_audiences', []); // no audience
$res=joma_files_authorize($db5,snap(),$fileRow,$resource,$now);
t('file without audience denied',$res['allowed']===false);

// FORM kind should be denied for file (no file linkage for forms in this schema)
$formRes=['kind'=>'FORM_RESPONSE','id'=>id(50),'version_id'=>id(51),'case_id'=>id(10),'scope_id'=>id(4),'subject_person_ids'=>[id(21)],'service_id'=>1,'required_product_id'=>null,'policy_status'=>'APPROVED','revision_state'=>'SUBMITTED'];
$db6=new MockMysqliF();
$res=joma_files_authorize($db6,snap(),$goodRow,$formRes,$now);
t('form file denied',$res['allowed']===false);

// Headers
$hdrs=joma_files_headers($goodRow,false);
t('headers content-type',$hdrs['Content-Type']==='application/pdf');
t('headers disposition attachment',strpos($hdrs['Content-Disposition'],'attachment;')===0);
t('headers cache private',strpos($hdrs['Cache-Control'],'private')!==false);
t('headers etag hex',preg_match('/\A"[0-9a-f]{64}"\z/',$hdrs['ETag'])===1);
$hdrsInline=joma_files_headers($goodRow,true);
t('inline disposition',strpos($hdrsInline['Content-Disposition'],'inline;')===0);

// Storage path outside docroot
$path=joma_files_storage_path('/var/joma/storage',$goodRow);
t('storage path join',$path==='/var/joma/storage/reports/'.id(70).'.pdf');
$traversalRow=$goodRow;$traversalRow['storage_key']='../x';
t('storage path traversal null',joma_files_storage_path('/var/joma/storage',$traversalRow)===null);

// Mismatched sha -> but validate only checks length, not content; linkage ensures file integrity via sha header, not auth
$badShaRow=$goodRow;$badShaRow['sha256']=random_bytes(32);
t('different sha still valid row',joma_files_validate_row($badShaRow)===true);

// Authorize with representation + restriction (reuse portal logic)
$childRes=['kind'=>'REPORT','id'=>id(22),'version_id'=>id(23),'case_id'=>id(10),'scope_id'=>id(4),'subject_person_ids'=>[id(30)],'service_id'=>1,'required_product_id'=>null,'policy_status'=>'APPROVED','revision_state'=>'REVIEWED'];
$fileRow2=$goodRow; $fileRow2['id']=id(81);
$db7=new MockMysqliF();
$db7->expect('FROM joma_report_versions WHERE id = ? AND protected_file_id', [['id'=>binId(23)]]);
$db7->expect('FROM joma_report_publications rp JOIN joma_report_publication_audiences', [[
    'pub_id'=>binId(24),'pub_version_id'=>binId(23),'pub_case_id'=>binId(10),'channel'=>'PORTAL','published_at'=>$now,'revoked_at'=>null,
    'aud_id'=>binId(31),'publication_id'=>binId(24),'recipient_person_id'=>binId(21),'access_subject_person_id'=>binId(30),'aud_scope_id'=>binId(4),'basis_kind'=>'REPRESENTATIVE','representation_id'=>binId(40),'aud_revoked'=>null,
]]);
$db7->expect('FROM joma_representations WHERE id', [[
    'id'=>binId(40),'representative_person_id'=>binId(21),'subject_person_id'=>binId(30),'scope_id'=>binId(4),'status'=>'VERIFIED','allowed_actions_json'=>'["document.read"]','valid_from'=>null,'valid_until'=>null,'revoked_at'=>null,
]]);
$db7->expect('FROM joma_case_representation_restrictions', []); // no suspension
$res=joma_files_authorize($db7,snap(),$fileRow2,$childRes,$now);
t('representative file allowed',$res['allowed']===true);

// No mutation
$snapCopy=snap();
$db8=new MockMysqliF();
$db8->expect('FROM joma_report_versions WHERE id = ? AND protected_file_id', [['id'=>binId(23)]]);
$db8->expect('FROM joma_report_publications rp JOIN joma_report_publication_audiences', [[
    'pub_id'=>binId(24),'pub_version_id'=>binId(23),'pub_case_id'=>binId(10),'channel'=>'PORTAL','published_at'=>$now,'revoked_at'=>null,
    'aud_id'=>binId(30),'publication_id'=>binId(24),'recipient_person_id'=>binId(21),'access_subject_person_id'=>binId(21),'aud_scope_id'=>binId(4),'basis_kind'=>'DIRECT_PERSON','representation_id'=>null,'aud_revoked'=>null,
]]);
joma_files_authorize($db8,$snapCopy,$fileRow,$resource,$now);
t('no snapshot mutation',$snapCopy===snap());

echo "\nPHP ".PHP_VERSION." — $checks checks, $failures failures\n";
echo "FILES: synthetic protected file checks only; no real filesystem.\n";
exit($failures?1:0);
