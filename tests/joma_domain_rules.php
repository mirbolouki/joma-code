<?php
declare(strict_types=1);
require dirname(__DIR__) . '/joma-core/domain_rules.php';
set_error_handler(function ($severity,$message,$file,$line) { throw new ErrorException($message,0,$severity,$file,$line); });
$checks=0; $failures=0;
function t(string $name, bool $condition): void {
    global $checks,$failures; ++$checks;
    if (!$condition) { ++$failures; }
    echo ($condition ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
}
function id(int $i): string { return sprintf('00000000-0000-4000-8000-%012x',$i); }
function validWindow(): array { return ['valid_from'=>null,'valid_until'=>null,'revoked_at'=>null]; }
function staff(): array {
    return ['account'=>['id'=>id(1),'person_id'=>id(2),'status'=>'ACTIVE'],
        'membership'=>['id'=>id(3),'person_id'=>id(2),'scope_id'=>id(4),'status'=>'ACTIVE']+validWindow(),
        'role_assignment'=>['id'=>id(5),'account_id'=>id(1),'person_id'=>id(2),'membership_id'=>id(3),'scope_id'=>id(4)]+validWindow(),
        'policy_status'=>'APPROVED','permissions'=>['routing.accept','scheduling.hold']];
}
function engagement(): array {
    return ['id'=>id(10),'responsible_therapist_id'=>id(2),'accepted_therapist_id'=>id(2),
        'relationship_therapist_id'=>id(2),'status'=>'ACTIVE','relationship_status'=>'ACTIVE'];
}
$now='2026-09-26 10:00:00.000000';
$start='2026-09-26 12:00:00.000000'; $end='2026-09-26 13:00:00.000000';
$expiry='2026-09-26 10:15:00.000000';
$ctx=staff();

t('UUID canonical v4',joma_rule_uuid(strtoupper(id(10)))===id(10));
t('UUID rejects zero/sentinel',joma_rule_uuid('00000000-0000-0000-0000-000000000000')===null);
t('UUID rejects integer',joma_rule_uuid(123)===null);
t('UUID rejects suffix/newline',joma_rule_uuid(id(10)."\n")===null);
$generated=[];for($i=0;$i<100;$i++){$generated[]=joma_uuid_v4();}
t('UUID generator: valid v4 samples',count(array_filter($generated,fn($x)=>joma_rule_uuid($x)!==null))===100);
t('UUID generator: sample uniqueness only',count(array_unique($generated))===100);
t('UTC exact microseconds',joma_rule_utc_us('2026-09-26 10:00:00.000001')-joma_rule_utc_us($now)===1);
t('UTC rejects impossible calendar date',joma_rule_utc_us('2026-02-30 10:00:00.000000')===null);
t('UTC rejects timezone-free short UI time',joma_rule_utc_us('10:00')===null);
t('UTC rejects missing fractional precision',joma_rule_utc_us('2026-09-26 10:00:00')===null);
t('UTC rejects NUL without parser exception',joma_rule_utc_us($now.chr(0))===null);
t('UTC rejects year outside SQL DATETIME range',joma_rule_utc_us('0000-01-01 00:00:00.000000')===null);
t('Empty principal rejected',!joma_rule_principal([])['allowed']);
t('Valid selected context',joma_rule_context($ctx,id(4),'routing.accept',$now)['allowed']);
foreach(['LOCKED','INACTIVE','UNKNOWN'] as $status){$x=$ctx;$x['account']['status']=$status;t('Account '.$status.' denied',!joma_rule_context($x,id(4),'routing.accept',$now)['allowed']);}
$x=$ctx;$x['membership']['person_id']=id(99);t('Membership person mismatch',!joma_rule_context($x,id(4),'routing.accept',$now)['allowed']);
t('Cross-center context denied',!joma_rule_context($ctx,id(99),'routing.accept',$now)['allowed']);
$x=$ctx;$x['role_assignment']['account_id']=id(99);t('Role cannot belong to other login',!joma_rule_context($x,id(4),'routing.accept',$now)['allowed']);
$x=$ctx;$x['role_assignment']['membership_id']=id(99);t('Role cannot belong to other membership',!joma_rule_context($x,id(4),'routing.accept',$now)['allowed']);
$x=$ctx;unset($x['role_assignment']['id']);t('Missing authority identity denied',!joma_rule_context($x,id(4),'routing.accept',$now)['allowed']);
$x=$ctx;$x['membership']['status']='ENDED';t('Ended membership denied',!joma_rule_context($x,id(4),'routing.accept',$now)['allowed']);
$x=$ctx;$x['role_assignment']['valid_until']=$now;t('Authority expires at exact boundary',!joma_rule_context($x,id(4),'routing.accept',$now)['allowed']);
$x=$ctx;$x['role_assignment']['valid_from']=$now;t('Authority starts at exact boundary',joma_rule_context($x,id(4),'routing.accept',$now)['allowed']);
$x=$ctx;$x['role_assignment']['valid_from']=$expiry;t('Future authority denied',!joma_rule_context($x,id(4),'routing.accept',$now)['allowed']);
$x=$ctx;$x['role_assignment']['revoked_at']=$now;t('Revoked authority denied',!joma_rule_context($x,id(4),'routing.accept',$now)['allowed']);
$x=$ctx;unset($x['role_assignment']['revoked_at']);t('Missing revocation state denied',!joma_rule_context($x,id(4),'routing.accept',$now)['allowed']);
$x=$ctx;$x['permissions']=[];t('Permission not inherited from another role',!joma_rule_context($x,id(4),'routing.accept',$now)['allowed']);
$x=$ctx;$x['policy_status']='UNDECIDED';t('UNDECIDED is not allow',!joma_rule_context($x,id(4),'routing.accept',$now)['allowed']);
$assignment=['id'=>id(6),'admission_id'=>id(7),'scope_id'=>id(4),'therapist_person_id'=>id(2),'status'=>'ASSIGNED','admission_status'=>'AWAITING_THERAPIST','linked_case_id'=>null];
t('Assigned therapist can pass acceptance guard',joma_rule_accept_assignment($ctx,$assignment,$now)['allowed']);
$x=$assignment;$x['therapist_person_id']=id(99);t('Secretary/other therapist cannot accept for assigned therapist',!joma_rule_accept_assignment($ctx,$x,$now)['allowed']);
foreach(['ACCEPTED','DECLINED','CANCELLED'] as $status){$x=$assignment;$x['status']=$status;t('Terminal assignment '.$status.' not re-executed',!joma_rule_accept_assignment($ctx,$x,$now)['allowed']);}
$x=$assignment;$x['linked_case_id']=id(10);t('Linked case cannot be silently overwritten',!joma_rule_accept_assignment($ctx,$x,$now)['allowed']);
$x=$assignment;unset($x['linked_case_id']);t('Unknown case link fails closed',!joma_rule_accept_assignment($ctx,$x,$now)['allowed']);
$case=engagement();
t('15-minute post-acceptance hold terms',joma_rule_hold_terms($case,$start,$end,$now,$expiry)['allowed']);
t('15-minute plus microsecond rejected',!joma_rule_hold_terms($case,$start,$end,$now,'2026-09-26 10:15:00.000001')['allowed']);
t('Zero TTL rejected',!joma_rule_hold_terms($case,$start,$end,$now,$now)['allowed']);
t('Reversed interval rejected',!joma_rule_hold_terms($case,$end,$start,$now,$expiry)['allowed']);
t('Pre-case hold rejected',!joma_rule_hold_terms([],$start,$end,$now,$expiry)['allowed']);
$x=$case;$x['accepted_therapist_id']=id(99);t('Wrong acceptance cannot activate hold',!joma_rule_hold_terms($x,$start,$end,$now,$expiry)['allowed']);
$x=$case;$x['status']='CLOSED';t('Closed case cannot start new hold',!joma_rule_hold_terms($x,$start,$end,$now,$expiry)['allowed']);
t('Half-open adjacent slots do not overlap',joma_rule_intervals_overlap($now,$start,$start,$end)===false);
t('Contained intervals overlap',joma_rule_intervals_overlap($now,$end,$start,'2026-09-26 12:30:00.000000')===true);
t('Same intervals overlap',joma_rule_intervals_overlap($start,$end,$start,$end)===true);
t('Invalid interval is unknown, not available',joma_rule_intervals_overlap($end,$start,$start,$end)===null);
$hold=['id'=>id(11),'case_id'=>id(10),'offering_id'=>id(12),'status'=>'HELD','starts_at'=>$start,'ends_at'=>$end,'held_at'=>$now,'expires_at'=>$expiry];
t('Valid held interval can pass confirmation guard',joma_rule_confirm_hold($case,$hold,id(12),$now)['allowed']);
t('At exact expiration cannot confirm',!joma_rule_confirm_hold($case,$hold,id(12),$expiry)['allowed']);
t('Before hold start cannot confirm',!joma_rule_confirm_hold($case,$hold,id(12),'2026-09-26 09:59:59.999999')['allowed']);
t('Different offering cannot consume hold',!joma_rule_confirm_hold($case,$hold,id(99),$now)['allowed']);
foreach(['CONSUMED','RELEASED','EXPIRED'] as $state){$x=$hold;$x['status']=$state;t('Hold '.$state.' cannot be consumed',!joma_rule_confirm_hold($case,$x,id(12),$now)['allowed']);}
$x=$hold;$x['case_id']=id(99);t('Different case cannot consume hold',!joma_rule_confirm_hold($case,$x,id(12),$now)['allowed']);
$hash=hash('sha256','fixture canonical payload');
$receipt=['command_name'=>'accept_assignment','scope_id'=>id(4),'actor_person_id'=>id(2),'payload_hash'=>$hash,'status'=>'SUCCEEDED'];
t('Same request can seek authorized replay',joma_rule_retry($receipt,'accept_assignment',id(4),id(2),$hash)['code']==='REPLAY_REQUIRES_CURRENT_AUTHORIZATION');
t('Changed payload conflicts',!joma_rule_retry($receipt,'accept_assignment',id(4),id(2),hash('sha256','different'))['allowed']);
t('Different actor cannot replay',!joma_rule_retry($receipt,'accept_assignment',id(4),id(99),$hash)['allowed']);
t('Different scope cannot replay',!joma_rule_retry($receipt,'accept_assignment',id(99),id(2),$hash)['allowed']);
$x=$receipt;$x['status']='PROCESSING';t('In-progress command not duplicated',joma_rule_retry($x,'accept_assignment',id(4),id(2),$hash)['code']==='COMMAND_IN_PROGRESS');
$note=['kind'=>'PRIVATE_NOTE','author_person_id'=>id(2)];
t('Private note writer match',joma_rule_private_note_author($ctx,$note)['allowed']);
$x=$ctx;unset($x['membership'],$x['role_assignment']);t('Former clinic membership not required for own note predicate',joma_rule_private_note_author($x,$note)['allowed']);
$x=$note;$x['author_person_id']=id(99);t('No admin/clinical override for private note',!joma_rule_private_note_author($ctx,$x)['allowed']);

// Portal snapshots are trusted server projections. No membership shortcut is involved.
$portal=['account'=>['id'=>id(20),'person_id'=>id(21),'status'=>'ACTIVE']];
$resource=['kind'=>'REPORT','id'=>id(22),'version_id'=>id(23),'case_id'=>id(10),'scope_id'=>id(4),'subject_person_ids'=>[id(21)],'service_id'=>1,'required_product_id'=>null,'policy_status'=>'APPROVED','revision_state'=>'REVIEWED'];
$publication=['id'=>id(24),'resource_id'=>id(22),'version_id'=>id(23),'scope_id'=>id(4),'channel'=>'PORTAL','published_at'=>$now,'revoked_at'=>null];
$audience=['publication_id'=>id(24),'recipient_person_id'=>id(21),'access_subject_person_id'=>id(21),'scope_id'=>id(4),'basis_kind'=>'DIRECT_PERSON','representation_id'=>null,'revoked_at'=>null];
function readRule($p,$r,$pub,$a,$rep=null,$restrictions=null,$entitlement=null): array {
    return joma_rule_portal_read($p,$r,$pub,$a,$rep,$restrictions,$entitlement,'2026-09-26 10:00:00.000000');
}
t('Explicit published audience allows portal predicate',readRule($portal,$resource,$publication,$audience)['allowed']);
t('Case membership alone not a grant',!readRule($portal,$resource,$publication,[])['allowed']);
foreach(['PRIVATE_NOTE','RAW_ASSESSMENT','UNKNOWN'] as $kind){$x=$resource;$x['kind']=$kind;t('Portal denies '.$kind,!readRule($portal,$x,$publication,$audience)['allowed']);}
$x=$resource;$x['policy_status']='UNDECIDED';t('Unknown raw/access policy remains deny',!readRule($portal,$x,$publication,$audience)['allowed']);
$x=$resource;$x['revision_state']='DRAFT_CLINICIAN_REVIEW';t('Upload does not publish draft report',!readRule($portal,$x,$publication,$audience)['allowed']);
$x=$resource;$x['kind']='FORM_RESPONSE';$x['revision_state']='DRAFT';t('Draft form cannot leak through publication record',!readRule($portal,$x,$publication,$audience)['allowed']);
$x['revision_state']='SUBMITTED';t('Submitted form needs and has explicit audience',readRule($portal,$x,$publication,$audience)['allowed']);
$x=$publication;$x['version_id']=id(99);t('Old publication not valid for new revision',!readRule($portal,$resource,$x,$audience)['allowed']);
$x=$publication;$x['channel']='IN_PERSON';t('In-person delivery not portal permission',!readRule($portal,$resource,$x,$audience)['allowed']);
$x=$publication;$x['published_at']=$expiry;t('Future publication not yet visible',!readRule($portal,$resource,$x,$audience)['allowed']);
$x=$publication;$x['revoked_at']=$now;t('Revoked publication denied',!readRule($portal,$resource,$x,$audience)['allowed']);
$x=$audience;$x['recipient_person_id']=id(99);t('Partner cannot read other recipient document',!readRule($portal,$resource,$publication,$x)['allowed']);
$x=$audience;$x['scope_id']=id(99);t('Audience cannot cross clinic scope',!readRule($portal,$resource,$publication,$x)['allowed']);
$x=$audience;$x['revoked_at']=$now;t('Revoked audience denied',!readRule($portal,$resource,$publication,$x)['allowed']);
$paid=$resource;$paid['required_product_id']=7;
t('Publication without entitlement denied',!readRule($portal,$paid,$publication,$audience)['allowed']);
$entitlement=['product_id'=>7,'beneficiary_person_id'=>id(21),'source_verified'=>true]+validWindow();
t('Entitlement + publication + audience passes',readRule($portal,$paid,$publication,$audience,null,null,$entitlement)['allowed']);
$x=$entitlement;$x['product_id']=8;t('Other product purchase insufficient',!readRule($portal,$paid,$publication,$audience,null,null,$x)['allowed']);
$x=$entitlement;$x['source_verified']=false;t('Unverified clinic payment is not entitlement',!readRule($portal,$paid,$publication,$audience,null,null,$x)['allowed']);
$x=$entitlement;$x['valid_until']=$now;t('Expired entitlement denied',!readRule($portal,$paid,$publication,$audience,null,null,$x)['allowed']);
$x=$publication;$x['revoked_at']=$now;t('Payment cannot override revoked publication',!readRule($portal,$paid,$x,$audience,null,null,$entitlement)['allowed']);

$child=$resource;$child['subject_person_ids']=[id(30)];
$parentAudience=$audience;$parentAudience['basis_kind']='REPRESENTATIVE';$parentAudience['representation_id']=id(31);$parentAudience['access_subject_person_id']=id(30);
$rep=['id'=>id(31),'representative_person_id'=>id(21),'subject_person_id'=>id(30),'scope_id'=>id(4),'status'=>'VERIFIED','allowed_actions'=>['document.read']]+validWindow();
t('Explicit representative grant with live authority',readRule($portal,$child,$publication,$parentAudience,$rep,[])['allowed']);
t('Administrative VERIFIED alone not audience',!readRule($portal,$child,$publication,[],$rep,[])['allowed']);
t('Restrictions not loaded is not empty set',!readRule($portal,$child,$publication,$parentAudience,$rep,null)['allowed']);
$x=$rep;$x['subject_person_id']=id(99);t('Other child representation insufficient',!readRule($portal,$child,$publication,$parentAudience,$x,[])['allowed']);
$x=$rep;$x['revoked_at']=$now;t('Revoked representative cannot use existing session/grant',!readRule($portal,$child,$publication,$parentAudience,$x,[])['allowed']);
$x=$rep;$x['allowed_actions']=[];t('Booking authority does not grant document read',!readRule($portal,$child,$publication,$parentAudience,$x,[])['allowed']);
$x=$rep;$x['valid_until']=$now;t('Exact representation expiry denied',!readRule($portal,$child,$publication,$parentAudience,$x,[])['allowed']);
$restriction=['representation_id'=>id(31),'case_id'=>id(10),'service_id'=>null,'status'=>'SUSPENDED'];
t('Case suspension denies representative read',!readRule($portal,$child,$publication,$parentAudience,$rep,[$restriction])['allowed']);
$x=$restriction;$x['case_id']=id(99);t('Other case suspension does not deny this case',readRule($portal,$child,$publication,$parentAudience,$rep,[$x])['allowed']);
$x=$restriction;$x['representation_id']=id(99);t('Other representative restriction not a global parent ban',readRule($portal,$child,$publication,$parentAudience,$rep,[$x])['allowed']);
$x=$restriction;$x['service_id']=2;t('Other service restriction scoped correctly',readRule($portal,$child,$publication,$parentAudience,$rep,[$x])['allowed']);
$x=$restriction;$x['service_id']=1;t('Same service restriction denies',!readRule($portal,$child,$publication,$parentAudience,$rep,[$x])['allowed']);
$x=$restriction;$x['service_id']='1';t('Malformed restriction cannot bypass comparison',!readRule($portal,$child,$publication,$parentAudience,$rep,[$x])['allowed']);
$x=$child;$x['service_id']=null;$r=$restriction;$r['service_id']=1;t('Unknown service cannot bypass scoped suspension',!readRule($portal,$x,$publication,$parentAudience,$rep,[$r])['allowed']);
$before=serialize([$ctx,$case,$hold,$resource,$publication,$audience,$rep]);
readRule($portal,$child,$publication,$parentAudience,$rep,[$restriction]);
joma_rule_confirm_hold($case,$hold,id(12),$now);
t('Rule calls have no input-state mutations',serialize([$ctx,$case,$hold,$resource,$publication,$audience,$rep])===$before);

echo "\nPHP ".PHP_VERSION." — $checks checks, $failures failures\n";
echo "UNIT RULES ONLY: no DB, HTTP, session-authentication, concurrent transactions or crypto tested.\n";
exit($failures ? 1 : 0);
