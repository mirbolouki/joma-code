<?php
declare(strict_types=1);
/**
 * JOMA demo seed — idempotent. Creates demo-therapist + subject + scope + admission + assignment.
 * Run: https://domain/joma-demo/seed.php?token=TOKEN  or  php seed.php (CLI with config)
 */
error_reporting(E_ALL);
ini_set('display_errors','1');
$configFile = __DIR__ . '/joma-config.php';
if (!is_file($configFile)) { http_response_code(500); echo "missing joma-config.php\n"; exit(1); }
require $configFile;
if (!isset($joma_config) || !is_array($joma_config)) { http_response_code(500); echo "bad config\n"; exit(1); }
$cfg = $joma_config;
$token = $cfg['token'] ?? '';
if (php_sapi_name() !== 'cli') {
    $got = $_GET['token'] ?? '';
    if (!is_string($token) || strlen($token) < 16 || !hash_equals($token, $got)) { http_response_code(403); echo "forbidden\n"; exit; }
}
require_once __DIR__ . '/joma-core/db.php';
require_once __DIR__ . '/joma-core/domain_rules.php';

function gen_uuid(): string { $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128); $h=bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
function to_bin(string $uuid): string { $b=joma_db_uuid_to_bin($uuid); if($b===null) throw new RuntimeException("bad uuid"); return $b; }

$db = joma_db_connect($cfg);
if (!$db) { http_response_code(500); echo "DB connect failed\n"; exit(1); }
echo "DB ".$db->server_info." php ".PHP_VERSION."\n";
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);

function qRow(mysqli $db, string $sql, array $params=[]): ?array {
    $stmt=$db->prepare($sql);
    if (!$stmt) throw new RuntimeException("prepare $sql");
    if ($params) {
        $types=str_repeat('s', count($params));
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $res=$stmt->get_result();
    $row=$res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}
function qExec(mysqli $db, string $sql, array $params=[]): void {
    $stmt=$db->prepare($sql);
    if (!$stmt) throw new RuntimeException("prepare $sql");
    if ($params) {
        $types=str_repeat('s', count($params));
        // mysqli bind_param needs references
        $refs=[]; foreach($params as $k=>$v) $refs[$k]=$v;
        $stmt->bind_param($types, ...$refs);
    }
    $stmt->execute();
    $stmt->close();
}

// 1. role/service
$row=qRow($db,"SELECT id FROM joma_role_definitions WHERE code='therapist' LIMIT 1");
if($row){ $roleId=(int)$row['id']; echo "role therapist $roleId\n"; }
else { $db->query("INSERT INTO joma_role_definitions (code,label,is_active) VALUES ('therapist','Therapist',1)"); $roleId=(int)$db->insert_id; echo "created role $roleId\n"; }
$row=qRow($db,"SELECT id FROM joma_service_definitions WHERE code='individual' LIMIT 1");
if($row){ $serviceId=(int)$row['id']; echo "service individual $serviceId\n"; }
else { $db->query("INSERT INTO joma_service_definitions (code,label,is_active) VALUES ('individual','Individual',1)"); $serviceId=(int)$db->insert_id; echo "created service $serviceId\n"; }

// 2. scope
$row=qRow($db,"SELECT id FROM joma_work_scopes WHERE label='کلینیک مرکزی' LIMIT 1");
if($row){ $scopeBin=$row['id']; $scopeUuid=joma_db_bin_to_uuid($scopeBin); echo "scope $scopeUuid\n"; }
else { $scopeUuid=gen_uuid(); $scopeBin=to_bin($scopeUuid); qExec($db,"INSERT INTO joma_work_scopes (id,kind,label,status) VALUES (?,'CENTER','کلینیک مرکزی','ACTIVE')",[$scopeBin]); echo "created scope $scopeUuid\n"; }

// 3. persons
function ensurePerson(mysqli $db,string $given,string $family): array {
    $row=qRow($db,"SELECT id FROM joma_persons WHERE given_name=? AND family_name=? LIMIT 1",[$given,$family]);
    if($row){ $bin=$row['id']; $uuid=joma_db_bin_to_uuid($bin); echo "person $given $family $uuid\n"; return [$uuid,$bin]; }
    $uuid=gen_uuid(); $bin=to_bin($uuid);
    qExec($db,"INSERT INTO joma_persons (id,given_name,family_name) VALUES (?,?,?)",[$bin,$given,$family]);
    echo "created person $given $family $uuid\n"; return [$uuid,$bin];
}
[$therapistUuid,$therapistBin]=ensurePerson($db,'درمانگر','تست');
[$subjectUuid,$subjectBin]=ensurePerson($db,'مراجع','تست');
[$adminUuid,$adminBin]=ensurePerson($db,'ادمین','تست');

// 4. account demo-therapist / Demo1234!
$login='demo-therapist';
$row=qRow($db,"SELECT id,person_id,password_hash FROM joma_accounts WHERE login_name=? LIMIT 1",[$login]);
if($row){
    $accountBin=$row['id']; $accountUuid=joma_db_bin_to_uuid($accountBin);
    echo "account $login $accountUuid\n";
    if(!password_verify('Demo1234!',$row['password_hash'])){
        $h=password_hash('Demo1234!',PASSWORD_DEFAULT);
        qExec($db,"UPDATE joma_accounts SET password_hash=? WHERE id=?",[$h,$accountBin]);
        echo "updated password\n";
    }
} else {
    $accountUuid=gen_uuid(); $accountBin=to_bin($accountUuid);
    $h=password_hash('Demo1234!',PASSWORD_DEFAULT);
    qExec($db,"INSERT INTO joma_accounts (id,person_id,login_name,password_hash,status) VALUES (?,?,?,?,'ACTIVE')",[$accountBin,$therapistBin,$login,$h]);
    echo "created account $login $accountUuid / Demo1234!\n";
}

// 5. membership
$row=qRow($db,"SELECT id FROM joma_memberships WHERE person_id=? AND scope_id=? LIMIT 1",[$therapistBin,$scopeBin]);
if($row){ $membershipBin=$row['id']; echo "membership ".joma_db_bin_to_uuid($membershipBin)."\n"; }
else { $membershipUuid=gen_uuid(); $membershipBin=to_bin($membershipUuid); $now=gmdate('Y-m-d H:i:s.000000'); qExec($db,"INSERT INTO joma_memberships (id,person_id,scope_id,status,valid_from) VALUES (?,?,?,'ACTIVE',?)",[$membershipBin,$therapistBin,$scopeBin,$now]); echo "created membership $membershipUuid\n"; }

// 6. role_assignment
$row=qRow($db,"SELECT id FROM joma_role_assignments WHERE account_id=? AND scope_id=? AND role_id=? LIMIT 1",[$accountBin,$scopeBin,(string)$roleId]);
if($row){ $raBin=$row['id']; echo "role_assignment ".joma_db_bin_to_uuid($raBin)."\n"; }
else { $raUuid=gen_uuid(); $raBin=to_bin($raUuid); $now=gmdate('Y-m-d H:i:s.000000'); qExec($db,"INSERT INTO joma_role_assignments (id,account_id,person_id,membership_id,scope_id,role_id,valid_from) VALUES (?,?,?,?,?,?,?)",[$raBin,$accountBin,$therapistBin,$membershipBin,$scopeBin,(string)$roleId,$now]); echo "created role_assignment $raUuid\n"; }

// 6b. schedule_resource for therapist (for holds)
$row=qRow($db,"SELECT id FROM joma_schedule_resources WHERE therapist_person_id=? LIMIT 1",[$therapistBin]);
if($row){ $resourceBin=$row['id']; echo "resource ".joma_db_bin_to_uuid($resourceBin)." THERAPIST\n"; }
else {
    $resourceUuid=gen_uuid(); $resourceBin=to_bin($resourceUuid);
    qExec($db,"INSERT INTO joma_schedule_resources (id,therapist_person_id,kind,label) VALUES (?,?,'THERAPIST','اتاق درمانگر تست')",[$resourceBin,$therapistBin]);
    echo "created resource $resourceUuid\n";
}

// 7. policy + offering (minimal, idempotent)
$row=qRow($db,"SELECT id FROM joma_service_policy_versions WHERE service_id=? AND version_no=1 LIMIT 1",[(string)$serviceId]);
if($row){ $policyBin=$row['id']; echo "policy ".joma_db_bin_to_uuid($policyBin)."\n"; }
else {
    $policyUuid=gen_uuid(); $policyBin=to_bin($policyUuid); $now=gmdate('Y-m-d H:i:s.000000');
    $policyJson=json_encode(['v'=>1],JSON_UNESCAPED_UNICODE);
    $status='PUBLISHED'; $ver=1;
    $stmt=$db->prepare("INSERT INTO joma_service_policy_versions (id,service_id,version_no,status,policy_json,author_person_id,approved_by_person_id,effective_at) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->bind_param('siisssss', $policyBin,$serviceId,$ver,$status,$policyJson,$adminBin,$adminBin,$now);
    $stmt->execute(); $stmt->close();
    echo "created policy $policyUuid\n";
}
$row=qRow($db,"SELECT id FROM joma_service_offerings WHERE scope_id=? LIMIT 1",[$scopeBin]);
if($row){ $offeringBin=$row['id']; echo "offering ".joma_db_bin_to_uuid($offeringBin)."\n"; }
else {
    $rowP=qRow($db,"SELECT id FROM joma_service_policy_versions WHERE service_id=? LIMIT 1",[(string)$serviceId]);
    $policyBin=$rowP['id'];
    $offeringUuid=gen_uuid(); $offeringBin=to_bin($offeringUuid);
    $stmt=$db->prepare("INSERT INTO joma_service_offerings (id,scope_id,service_id,policy_version_id,duration_minutes,status) VALUES (?,?,?,?,60,'ACTIVE')");
    $stmt->bind_param('ssis', $offeringBin,$scopeBin,$serviceId,$policyBin);
    $stmt->execute(); $stmt->close();
    echo "created offering $offeringUuid\n";
}
$row=qRow($db,"SELECT id FROM joma_service_offerings WHERE scope_id=? LIMIT 1",[$scopeBin]);
$offeringBin=$row['id']; $offeringUuid=joma_db_bin_to_uuid($offeringBin);

// 8. admission + assignment (open)
$row=qRow($db,"SELECT id FROM joma_admissions WHERE primary_subject_id=? AND scope_id=? AND status='AWAITING_THERAPIST' LIMIT 1",[$subjectBin,$scopeBin]);
if($row){ $admissionBin=$row['id']; $admissionUuid=joma_db_bin_to_uuid($admissionBin); echo "admission $admissionUuid\n"; }
else {
    $admissionUuid=gen_uuid(); $admissionBin=to_bin($admissionUuid);
    qExec($db,"INSERT INTO joma_admissions (id,primary_subject_id,scope_id,offering_id,recorded_by_person_id,status) VALUES (?,?,?,?,?,'AWAITING_THERAPIST')",[$admissionBin,$subjectBin,$scopeBin,$offeringBin,$adminBin]);
    echo "created admission $admissionUuid\n";
}
$row=qRow($db,"SELECT id FROM joma_therapist_assignments WHERE admission_id=? AND therapist_person_id=? AND status='ASSIGNED' LIMIT 1",[$admissionBin,$therapistBin]);
if($row){ $assignBin=$row['id']; echo "assignment ".joma_db_bin_to_uuid($assignBin)." ASSIGNED\n"; }
else {
    // if any ASSIGNED for this admission exists with different therapist, keep it else create
    $row2=qRow($db,"SELECT id FROM joma_therapist_assignments WHERE admission_id=? AND status='ASSIGNED' LIMIT 1",[$admissionBin]);
    if($row2){ $assignBin=$row2['id']; echo "assignment existing ".joma_db_bin_to_uuid($assignBin)."\n"; }
    else {
        $assignUuid=gen_uuid(); $assignBin=to_bin($assignUuid);
        qExec($db,"INSERT INTO joma_therapist_assignments (id,admission_id,therapist_person_id,assigned_by_person_id,status) VALUES (?,?,?,?, 'ASSIGNED')",[$assignBin,$admissionBin,$therapistBin,$adminBin]);
        echo "created assignment $assignUuid\n";
    }
}

// 9. sample form template/version for demo (D-31)
$row=qRow($db,"SELECT id FROM joma_form_templates WHERE label='فرم پذیرش اولیه' LIMIT 1");
if($row){ $formTplBin=$row['id']; echo "form template ".joma_db_bin_to_uuid($formTplBin)."\n"; }
else {
    $formTplUuid=gen_uuid(); $formTplBin=to_bin($formTplUuid);
    qExec($db,"INSERT INTO joma_form_templates (id,scope_id,label,form_kind) VALUES (?,?,?,?)",[$formTplBin,$scopeBin,'فرم پذیرش اولیه','ADMINISTRATIVE']);
    echo "created form template $formTplUuid\n";
}
$row=qRow($db,"SELECT id FROM joma_form_versions WHERE template_id=? AND version_no=1 LIMIT 1",[$formTplBin]);
if($row){ $formVerBin=$row['id']; echo "form version ".joma_db_bin_to_uuid($formVerBin)."\n"; }
else {
    $formVerUuid=gen_uuid(); $formVerBin=to_bin($formVerUuid);
    $schemaJson=json_encode(['q1'=>['type'=>'text','label'=>'شکایت اصلی'],'q2'=>['type'=>'text','label'=>'سابقه']], JSON_UNESCAPED_UNICODE);
    qExec($db,"INSERT INTO joma_form_versions (id,template_id,version_no,schema_json,status,author_person_id,published_at) VALUES (?,?,1,?,'PUBLISHED',?,?)",[$formVerBin,$formTplBin,$schemaJson,$adminBin,gmdate('Y-m-d H:i:s.000000')]);
    echo "created form version $formVerUuid PUBLISHED\n";
}
echo "\nSEED DONE — login demo-therapist / Demo1234!\n";
