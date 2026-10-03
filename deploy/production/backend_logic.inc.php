<?php
declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
session_start();

// Live DB detection
$liveDb = null; $liveMode = false; $liveError = null;
$configFile = __DIR__ . '/joma-config.php';
if (is_file($configFile)) {
    require $configFile;
    if (isset($joma_config) && is_array($joma_config)) {
        require_once __DIR__ . '/joma-core/db.php';
        require_once __DIR__ . '/joma-core/domain_rules.php';
        require_once __DIR__ . '/joma-core/auth.php';
        require_once __DIR__ . '/joma-core/context.php';
        require_once __DIR__ . '/joma-core/acceptance.php';
        require_once __DIR__ . '/joma-core/hold.php';
        require_once __DIR__ . '/joma-core/forms.php';
        require_once __DIR__ . '/joma-core/clinical_session.php';
        require_once __DIR__ . '/joma-core/reports.php';
        require_once __DIR__ . '/joma-core/case_closure.php';
        require_once __DIR__ . '/joma-core/private_notes.php';
        require_once __DIR__ . '/joma-core/audit.php';
        require_once __DIR__ . '/joma-core/session.php';
        $tmp = joma_db_connect($joma_config);
        if ($tmp instanceof mysqli) { $liveDb = $tmp; $liveMode = true; }
        else { $liveError = 'DB connect failed — mock mode'; }
    }
}

$demoUser = $_SESSION['joma_demo_user'] ?? null;
$principal = $_SESSION['joma_principal'] ?? null;
$accountCache = $_SESSION['joma_account_cache'] ?? null;
$snapshotCache = $_SESSION['joma_snapshot'] ?? null;

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['joma_demo_user'], $_SESSION['joma_principal'], $_SESSION['joma_account_cache'], $_SESSION['joma_snapshot']);
    if ($liveMode) { require_once __DIR__ . '/../../joma-core/session.php'; joma_session_clear(); }
    header('Location: index.php'); exit;
}

// Handle live acceptance BEFORE rendering
$acceptResult = null;
if ($liveMode && $liveDb && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['live_accept'])) {
    // CSRF check if available
    if (function_exists('joma_csrf_verify') && isset($_POST['csrf'])) {
        if (!joma_csrf_verify($_POST['csrf'])) { $acceptResult = ['ok'=>false,'code'=>'CSRF']; }
    }
    if ($acceptResult === null) {
        $assignmentId = trim($_POST['assignment_id'] ?? '');
        $purpose = trim($_POST['purpose'] ?? 'پرونده تست — اضطراب');
        $nowUtc = gmdate('Y-m-d H:i:s.000000');
        $commandId = strtolower(trim($_POST['command_id'] ?? ''));
        if (!preg_match('/^[0-9a-f-]{36}$/',$commandId)) { $commandId = strtolower((function(){ $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128); $h=bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); })()); }
        $snapshot = $snapshotCache;
        if (!$snapshot && $principal && $accountCache) {
            // try to reload snapshot if missing
            $accountId = $principal['account_id'] ?? null;
            if ($accountId) {
                $row = $liveDb->query("SELECT id FROM joma_role_assignments WHERE account_id = UNHEX(REPLACE('$accountId','-','')) LIMIT 1");
                // fallback: find via direct query
                $stmt = $liveDb->prepare("SELECT id FROM joma_role_assignments WHERE account_id = ? LIMIT 1");
                if ($stmt) {
                    $bin = hex2bin(str_replace('-','',$accountId));
                    $stmt->bind_param('s',$bin); $stmt->execute(); $res=$stmt->get_result(); $r=$res?$res->fetch_assoc():null; $stmt->close();
                    if ($r && isset($r['id'])) {
                        $raUuid = bin2hex($r['id']); $raUuid = substr($raUuid,0,8).'-'.substr($raUuid,8,4).'-'.substr($raUuid,12,4).'-'.substr($raUuid,16,4).'-'.substr($raUuid,20);
                        $loaded = joma_context_load($liveDb, $accountId, $raUuid, $nowUtc);
                        if ($loaded['ok'] ?? false) { $snapshot = $loaded['snapshot']; $_SESSION['joma_snapshot']=$snapshot; }
                    }
                }
            }
        }
        if (!$snapshot) { $acceptResult = ['ok'=>false,'code'=>'NO_SNAPSHOT']; }
        elseif (joma_rule_uuid($assignmentId)===null) { $acceptResult=['ok'=>false,'code'=>'INVALID_ID']; }
        else {
            // Ensure command_receipt exists as PROCESSING for idempotency (optional, but we create)
            try {
                $scopeBin = hex2bin(str_replace('-','',$snapshot['role_assignment']['scope_id']));
                $cmdBin = hex2bin(str_replace('-','',$commandId));
                $scopeIdText = $snapshot['role_assignment']['scope_id'];
                $actorId = $snapshot['account']['person_id'];
                $actorBin = hex2bin(str_replace('-','',$actorId));
                $key = hash('sha256',$commandId.$assignmentId.$purpose,true);
                $payloadHash = hash('sha256',$purpose,true);
                $stmt=$liveDb->prepare("INSERT INTO joma_command_receipts (id,scope_id,actor_person_id,command_name,idempotency_key,payload_hash,status) VALUES (?,?,?,?,?,?, 'PROCESSING')");
                if($stmt){ $stmt->bind_param('ssssss',$cmdBin,$scopeBin,$actorBin,$tmpCmd,$key,$payloadHash); $tmpCmd='acceptance.accept'; @$stmt->execute(); $stmt->close(); }
            } catch(Throwable $e){}
            $acceptResult = joma_acceptance_execute($liveDb, $snapshot, $assignmentId, $purpose, $nowUtc, $commandId);
            if ($acceptResult['ok'] ?? false) {
                // mark receipt succeeded
                try { $stmt=$liveDb->prepare("UPDATE joma_command_receipts SET status='SUCCEEDED', completed_at=? WHERE id=?"); $stmt->bind_param('ss',$nowUtc,$cmdBin); $stmt->execute(); $stmt->close(); } catch(Throwable $e){}
            }
        }
    }
    // store result in session to show after redirect? Show inline instead
    $_SESSION['joma_last_accept'] = $acceptResult;
}

// Handle live hold creation
$holdResult = $_SESSION['joma_last_hold'] ?? null;
if ($liveMode && $liveDb && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['live_hold'])) {
    unset($_SESSION['joma_last_hold']);
    $holdResult = null;
    if (function_exists('joma_csrf_verify') && isset($_POST['csrf'])) {
        if (!joma_csrf_verify($_POST['csrf'])) { $holdResult = ['ok'=>false,'code'=>'CSRF']; }
    }
    if ($holdResult === null) {
        $caseId = trim($_POST['hold_case_id'] ?? '');
        $offeringId = trim($_POST['hold_offering_id'] ?? '');
        $resourceId = trim($_POST['hold_resource_id'] ?? '');
        $startsAt = trim($_POST['starts_at'] ?? '');
        $endsAt = trim($_POST['ends_at'] ?? '');
        $nowUtc = gmdate('Y-m-d H:i:s.000000');
        $heldAt = $nowUtc;
        $expiresAt = gmdate('Y-m-d H:i:s.000000', time()+900); // 15min
        $commandId = strtolower(trim($_POST['hold_command_id'] ?? ''));
        if (!preg_match('/^[0-9a-f-]{36}$/',$commandId)) { $commandId = strtolower((function(){ $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128); $h=bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); })()); }
        $snapshot = $snapshotCache;
        if (!$snapshot) { $holdResult = ['ok'=>false,'code'=>'NO_SNAPSHOT']; }
        elseif (joma_rule_uuid($caseId)===null || joma_rule_uuid($offeringId)===null || joma_rule_uuid($resourceId)===null) { $holdResult=['ok'=>false,'code'=>'INVALID_ID']; }
        else {
            // need policyVersionId for offering
            $policyVersionId = null;
            $stmt=$liveDb->prepare("SELECT policy_version_id FROM joma_service_offerings WHERE id=?");
            if($stmt){ $bin=hex2bin(str_replace('-','',$offeringId)); $stmt->bind_param('s',$bin); $stmt->execute(); $res=$stmt->get_result(); $row=$res?$res->fetch_assoc():null; $stmt->close(); if($row) $policyVersionId=joma_db_bin_to_uuid($row['policy_version_id']); }
            if (!$policyVersionId) { $holdResult=['ok'=>false,'code'=>'NOT_FOUND']; }
            else {
                // use provided times or default to now+1h
                if ($startsAt===''||$endsAt==='') { $startsAt=gmdate('Y-m-d H:i:s.000000', time()+3600); $endsAt=gmdate('Y-m-d H:i:s.000000', time()+4500); }
                else {
                    // datetime-local comes as Y-m-d\TH:i — convert to UTC
                    $startsAt=str_replace('T',' ',$startsAt); if(strlen($startsAt)==16) $startsAt.=':00.000000'; elseif(strpos($startsAt,'.')===false) $startsAt.='.000000';
                    $endsAt=str_replace('T',' ',$endsAt); if(strlen($endsAt)==16) $endsAt.=':00.000000'; elseif(strpos($endsAt,'.')===false) $endsAt.='.000000';
                }
                $holdResult = joma_hold_create($liveDb, $snapshot, $caseId, $offeringId, $policyVersionId, $startsAt, $endsAt, $heldAt, $expiresAt, [$resourceId], $nowUtc, $commandId);
                if ($holdResult['ok']??false) { $_SESSION['joma_last_hold']=$holdResult; }
            }
        }
    }
    $_SESSION['joma_last_hold']=$holdResult;
    $holdResult = $holdResult;
}
$holdConfirmResult = $_SESSION['joma_last_hold_confirm'] ?? null;
if ($liveMode && $liveDb && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['live_hold_confirm'])) {
    unset($_SESSION['joma_last_hold_confirm']);
    $holdConfirmResult=null;
    if (function_exists('joma_csrf_verify') && isset($_POST['csrf'])) {
        if (!joma_csrf_verify($_POST['csrf'])) { $holdConfirmResult=['ok'=>false,'code'=>'CSRF']; }
    }
    if ($holdConfirmResult===null) {
        $holdId=trim($_POST['confirm_hold_id']??'');
        $offeringId=trim($_POST['confirm_offering_id']??'');
        $nowUtc=gmdate('Y-m-d H:i:s.000000');
        $commandId=strtolower(trim($_POST['confirm_command_id']??''));
        if(!preg_match('/^[0-9a-f-]{36}$/',$commandId)) $commandId=strtolower((function(){ $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128); $h=bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); })());
        $snapshot=$snapshotCache;
        if(!$snapshot) $holdConfirmResult=['ok'=>false,'code'=>'NO_SNAPSHOT'];
        elseif(joma_rule_uuid($holdId)===null||joma_rule_uuid($offeringId)===null) $holdConfirmResult=['ok'=>false,'code'=>'INVALID_ID'];
        else {
            $holdConfirmResult=joma_hold_confirm($liveDb,$snapshot,$holdId,$offeringId,$nowUtc,$commandId);
            if($holdConfirmResult['ok']??false) $_SESSION['joma_last_hold_confirm']=$holdConfirmResult;
        }
    }
    $_SESSION['joma_last_hold_confirm']=$holdConfirmResult;
}
$formResult=null;
if($liveMode && $liveDb && ($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && isset($_POST['live_form_create'])){
    $formVersionId=trim($_POST['form_version_id']??'');
    $caseIdForForm=trim($_POST['form_case_id']??'');
    $nowUtc=gmdate('Y-m-d H:i:s.000000');
    $snapshot=$snapshotCache;
    if(!$snapshot) $formResult=['ok'=>false,'code'=>'NO_SNAPSHOT'];
    elseif(joma_rule_uuid($formVersionId)===null||($caseIdForForm!=='' && joma_rule_uuid($caseIdForForm)===null)) $formResult=['ok'=>false,'code'=>'INVALID_ID'];
    else {
        // scope is from snapshot
        $scopeId=$snapshot['role_assignment']['scope_id']??null;
        $caseId = $caseIdForForm!==''?$caseIdForForm:null;
        $formResult=joma_form_create_instance($liveDb,$snapshot,$formVersionId,$scopeId,$caseId,null,null,$snapshot['account']['person_id'],$nowUtc);
        if($formResult['ok']??false) $_SESSION['joma_last_form']=$formResult;
    }
    $formResult=$formResult;
}
$formSubmitResult=null;
if($liveMode && $liveDb && ($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && isset($_POST['live_form_submit'])){
    $instanceId=trim($_POST['instance_id']??'');
    $answersRaw=trim($_POST['answers_json']??'');
    $nowUtc=gmdate('Y-m-d H:i:s.000000');
    $snapshot=$snapshotCache;
    $actorId=$snapshot['account']['person_id']??null;
    $respondentId=$actorId;
    $commandId=strtolower((function(){ $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128); $h=bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); })());
    if(joma_rule_uuid($instanceId)===null) $formSubmitResult=['ok'=>false,'code'=>'INVALID_ID'];
    else {
        // if answers empty, use simple
        if($answersRaw==='') $answersRaw=json_encode(['q1'=>'پاسخ تست'],JSON_UNESCAPED_UNICODE);
        $formSubmitResult=joma_form_submit_revision($liveDb,$snapshot,$instanceId,$respondentId,$actorId,$answersRaw,'SUBMITTED',$commandId,$nowUtc);
    }
}
$sessionCreateResult=null;
if($liveMode && $liveDb && ($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && isset($_POST['live_create_session'])){
    $apId=trim($_POST['session_appointment_id']??'');
    $caseId=trim($_POST['session_case_id']??'');
    $nowUtc=gmdate('Y-m-d H:i:s.000000');
    $snapshot=$snapshotCache;
    $clinicianId=$snapshot['account']['person_id']??null;
    $commandId=strtolower((function(){ $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128); $h=bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); })());
    if(!$snapshot) $sessionCreateResult=['ok'=>false,'code'=>'NO_SNAPSHOT'];
    elseif(joma_rule_uuid($caseId)===null) $sessionCreateResult=['ok'=>false,'code'=>'INVALID_ID'];
    else {
        $startedAt=$nowUtc;
        $endedAt=gmdate('Y-m-d H:i:s.000000', time()+2700); // 45m
        $apParam=$apId!==''?$apId:null;
        $sessionCreateResult=joma_session_create($liveDb,$snapshot,$caseId,$clinicianId,$apParam,$startedAt,$endedAt,$nowUtc,$commandId);
    }
}
$reportUploadResult=null;
if($liveMode && $liveDb && ($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && isset($_POST['live_upload_report'])){
    $caseId=trim($_POST['report_case_id']??'');
    $text=trim($_POST['report_text']??'');
    $nowUtc=gmdate('Y-m-d H:i:s.000000');
    $snapshot=$snapshotCache;
    if(!$snapshot) $reportUploadResult=['ok'=>false,'code'=>'NO_SNAPSHOT'];
    elseif(joma_rule_uuid($caseId)===null) $reportUploadResult=['ok'=>false,'code'=>'INVALID_ID'];
    else {
        $reportUploadResult=joma_report_upload_version($liveDb,$snapshot,$caseId,$text,$nowUtc);
    }
}
$reportPublishResult=null;
if($liveMode && $liveDb && ($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && isset($_POST['live_publish_report'])){
    $rvId=trim($_POST['report_version_id']??'');
    $caseId=trim($_POST['publish_case_id']??'');
    $recipientId=trim($_POST['recipient_person_id']??'');
    $scopeId=$snapshotCache['role_assignment']['scope_id']??null;
    $nowUtc=gmdate('Y-m-d H:i:s.000000');
    $snapshot=$snapshotCache;
    $commandId=strtolower((function(){ $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128); $h=bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); })());
    if(!$snapshot) $reportPublishResult=['ok'=>false,'code'=>'NO_SNAPSHOT'];
    elseif(joma_rule_uuid($rvId)===null||joma_rule_uuid($caseId)===null||joma_rule_uuid($recipientId)===null||joma_rule_uuid($scopeId)===null) {
        $reportPublishResult=['ok'=>false,'code'=>'INVALID_ID'];
    } else {
        $reportPublishResult=joma_report_publish($liveDb,$snapshot,$rvId,$caseId,'PORTAL',$recipientId,$scopeId,$nowUtc,$commandId);
    }
}
$caseCloseResult=null;
if($liveMode && $liveDb && ($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && isset($_POST['live_close_case'])){
    $caseId=trim($_POST['close_case_id']??'');
    $reason=trim($_POST['close_reason']??'پایان موفق دوره درمانی');
    $nowUtc=gmdate('Y-m-d H:i:s.000000');
    $snapshot=$snapshotCache;
    $commandId=strtolower((function(){ $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128); $h=bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); })());
    if(!$snapshot) $caseCloseResult=['ok'=>false,'code'=>'NO_SNAPSHOT'];
    elseif(joma_rule_uuid($caseId)===null) $caseCloseResult=['ok'=>false,'code'=>'INVALID_ID'];
    else {
        $caseCloseResult=joma_case_close($liveDb,$snapshot,$caseId,$reason,$nowUtc,$commandId);
        if($caseCloseResult['ok']??false){
            joma_audit_log($liveDb, $commandId, $snapshot['account']['person_id']??null, null, $snapshot['role_assignment']['scope_id']??null, 'case.close', 'case', $caseId, 'ALLOWED', 'SUCCESS', $nowUtc);
        }
    }
}
$privateNoteResult=null;
if($liveMode && $liveDb && ($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && isset($_POST['live_create_note'])){
    $caseId=trim($_POST['note_case_id']??'');
    $mode=trim($_POST['storage_mode']??'SERVER_CIPHERTEXT');
    $nowUtc=gmdate('Y-m-d H:i:s.000000');
    $snapshot=$snapshotCache;
    $opaqueHex=bin2hex(random_bytes(32));
    if(!$snapshot) $privateNoteResult=['ok'=>false,'code'=>'NO_SNAPSHOT'];
    elseif(joma_rule_uuid($caseId)===null) $privateNoteResult=['ok'=>false,'code'=>'INVALID_ID'];
    else {
        $privateNoteResult=joma_private_note_create($liveDb,$snapshot,$caseId,$mode,$opaqueHex,$nowUtc);
    }
}
$suspendRepResult=null;
if($liveMode && $liveDb && ($_SERVER['REQUEST_METHOD'] ?? '')==='POST' && isset($_POST['live_suspend_rep'])){
    $caseId=trim($_POST['suspend_case_id']??'');
    $repId=trim($_POST['suspend_rep_id']??'');
    $reason=trim($_POST['suspend_reason']??'CLINICAL_CHILD_SAFEGUARD');
    $nowUtc=gmdate('Y-m-d H:i:s.000000');
    $snapshot=$snapshotCache;
    $commandId=strtolower((function(){ $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128); $h=bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); })());
    if(!$snapshot) $suspendRepResult=['ok'=>false,'code'=>'NO_SNAPSHOT'];
    elseif(joma_rule_uuid($caseId)===null||joma_rule_uuid($repId)===null) $suspendRepResult=['ok'=>false,'code'=>'INVALID_ID'];
    else {
        $suspendRepResult=joma_representation_suspend($liveDb,$snapshot,$repId,$caseId,$reason,$nowUtc,$commandId);
        if($suspendRepResult['ok']??false){
            joma_audit_log($liveDb, $commandId, $snapshot['account']['person_id']??null, null, $snapshot['role_assignment']['scope_id']??null, 'case.suspend_rep', 'representation', $repId, 'ALLOWED', $reason, $nowUtc);
        }
    }
}

// Handle login (live or mock)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['login'])) {
    $u = trim($_POST['username'] ?? '');
    $p = (string)($_POST['password'] ?? '');
    if ($liveMode && $liveDb) {
        $nowUtc = gmdate('Y-m-d H:i:s.000000');
        $res = joma_auth_login($liveDb, $u, $p, $nowUtc);
        if ($res['ok'] ?? false) {
            $account = $res['account'];
            $_SESSION['joma_account_cache']=$account;
            joma_session_store_principal($account);
            // auto-select first role_assignment for this account
            $accountId = $account['id'];
            $bin = hex2bin(str_replace('-','',$accountId));
            $stmt=$liveDb->prepare("SELECT id FROM joma_role_assignments WHERE account_id=? LIMIT 1");
            $stmt->bind_param('s',$bin); $stmt->execute(); $res2=$stmt->get_result(); $row=$res2?$res2->fetch_assoc():null; $stmt->close();
            if ($row && isset($row['id'])) {
                $raBin=$row['id']; $raUuid=bin2hex($raBin); $raUuid=substr($raUuid,0,8).'-'.substr($raUuid,8,4).'-'.substr($raUuid,12,4).'-'.substr($raUuid,16,4).'-'.substr($raUuid,20);
                $loaded=joma_context_load($liveDb, $accountId, $raUuid, $nowUtc);
                if($loaded['ok']??false){ $_SESSION['joma_snapshot']=$loaded['snapshot']; $_SESSION['joma_demo_user']=['name'=>$u,'role'=>'therapist','live'=>true,'account_id'=>$accountId,'ra_id'=>$raUuid]; }
                else { $_SESSION['joma_demo_user']=['name'=>$u,'role'=>'therapist','live'=>true,'account_id'=>$accountId]; }
            } else {
                $_SESSION['joma_demo_user']=['name'=>$u,'role'=>'therapist','live'=>true,'account_id'=>$accountId];
            }
            header('Location: index.php'); exit;
        } else {
            $loginError = 'نام کاربری یا رمز نادرست';
        }
    } else {
        // mock
        if ($u !== '' && $p !== '') {
            $role = (strpos($u,'therapist')!==false || $u==='alice') ? 'therapist' : (strpos($u,'patient')!==false ? 'patient' : 'therapist');
            $_SESSION['joma_demo_user'] = ['name'=>$u,'role'=>$role,'live'=>false];
            header('Location: index.php'); exit;
        } else { $loginError='نام کاربری و رمز الزامی است'; }
    }
}
$demoUser = $_SESSION['joma_demo_user'] ?? null;
$principal = $_SESSION['joma_principal'] ?? null;
$snapshotCache = $_SESSION['joma_snapshot'] ?? null;
$role = $demoUser['role'] ?? null;
$liveActive = ($demoUser['live'] ?? false) && $liveMode;

// Fetch live data if live
$liveAdmissions = []; $liveCases = []; $liveAssign = null; $liveOfferingId=null; $liveResourceId=null; $liveHolds=[]; $liveForms=[]; $liveAppointments=[]; $liveFormInstances=[]; $liveReports=[]; $liveNotes=[]; $liveClientReports=[];
if ($liveActive && $liveDb) {
    try {
        // offering/resource for holds
        $row=$liveDb->query("SELECT id FROM joma_service_offerings LIMIT 1"); if($r=$row?$row->fetch_assoc():null){ $liveOfferingId=joma_db_bin_to_uuid($r['id']); }
        $row=$liveDb->query("SELECT id FROM joma_schedule_resources WHERE kind='THERAPIST' LIMIT 1"); if($r=$row?$row->fetch_assoc():null){ $liveResourceId=joma_db_bin_to_uuid($r['id']); }
        // published form versions
        $resF=$liveDb->query("SELECT v.id as vid, t.label as tlabel, v.version_no FROM joma_form_versions v JOIN joma_form_templates t ON t.id=v.template_id WHERE v.status='PUBLISHED' LIMIT 5");
        if($resF) while($rf=$resF->fetch_assoc()){ $liveForms[]=['id'=>joma_db_bin_to_uuid($rf['vid']),'label'=>$rf['tlabel'],'ver'=>$rf['version_no']]; }
        // appointments
        $resA=$liveDb->query("SELECT a.id, a.case_id, a.starts_at, a.ends_at, a.status, s.id as session_id FROM joma_appointments a LEFT JOIN joma_clinical_sessions s ON s.appointment_id=a.id ORDER BY a.starts_at DESC LIMIT 5");
        if($resA) while($ra=$resA->fetch_assoc()){ $liveAppointments[]=['id'=>joma_db_bin_to_uuid($ra['id']),'case_id'=>joma_db_bin_to_uuid($ra['case_id']),'starts'=>$ra['starts_at'],'ends'=>$ra['ends_at'],'status'=>$ra['status'],'session_id'=>$ra['session_id']?joma_db_bin_to_uuid($ra['session_id']):null]; }
        // form instances for this scope
        $resI=$liveDb->query("SELECT id, form_version_id, case_id, status FROM joma_form_instances ORDER BY created_at DESC LIMIT 5");
        if($resI) while($ri=$resI->fetch_assoc()){ $liveFormInstances[]=['id'=>joma_db_bin_to_uuid($ri['id']),'fv'=>joma_db_bin_to_uuid($ri['form_version_id']),'case_id'=>joma_db_bin_to_uuid($ri['case_id']??''),'status'=>$ri['status']]; }
        // report versions
        $resR=$liveDb->query("SELECT rv.id, rv.case_id, rv.version_no, rv.report_text, rv.status FROM joma_report_versions rv ORDER BY rv.created_at DESC LIMIT 5");
        if($resR) while($rr=$resR->fetch_assoc()){ $liveReports[]=['id'=>joma_db_bin_to_uuid($rr['id']),'case_id'=>joma_db_bin_to_uuid($rr['case_id']),'ver'=>$rr['version_no'],'text'=>$rr['report_text'],'status'=>$rr['status']]; }
        // client portal view: reports accessible to current actor as recipient
        $actorPersonId = $snapshotCache['account']['person_id'] ?? null;
        if($actorPersonId) {
            $portalRes = joma_portal_accessible_reports($liveDb, $snapshotCache, $actorPersonId);
            $liveClientReports = $portalRes['reports'] ?? [];
            // private notes: AUTHOR_ONLY
            $resN = $liveDb->query("SELECT id, case_id, storage_mode, created_at FROM joma_private_note_references WHERE author_person_id = " . "0x" . bin2hex(joma_db_uuid_to_bin($actorPersonId)) . " ORDER BY created_at DESC LIMIT 5");
            if($resN) while($rn=$resN->fetch_assoc()){ $liveNotes[]=['id'=>joma_db_bin_to_uuid($rn['id']),'case_id'=>joma_db_bin_to_uuid($rn['case_id']),'mode'=>$rn['storage_mode'],'created_at'=>$rn['created_at']]; }
        }
        // Find open assignments for this therapist
        $therapistPersonId = $snapshotCache['account']['person_id'] ?? ($principal['person_id'] ?? null);
        if ($therapistPersonId) {
            $bin = hex2bin(str_replace('-','',$therapistPersonId));
            $stmt=$liveDb->prepare("SELECT ta.id as ta_id, ta.admission_id, a.primary_subject_id, a.scope_id, a.status as a_status, ta.status as ta_status, p.given_name, p.family_name FROM joma_therapist_assignments ta JOIN joma_admissions a ON a.id=ta.admission_id LEFT JOIN joma_persons p ON p.id=a.primary_subject_id WHERE ta.therapist_person_id=? AND ta.status='ASSIGNED' AND a.status='AWAITING_THERAPIST' LIMIT 5");
            $stmt->bind_param('s',$bin); $stmt->execute(); $res=$stmt->get_result();
            while($row=$res?$res->fetch_assoc():null){
                if(!$row) break;
                $taUuid= bin2hex($row['ta_id']); $taUuid=substr($taUuid,0,8).'-'.substr($taUuid,8,4).'-'.substr($taUuid,12,4).'-'.substr($taUuid,16,4).'-'.substr($taUuid,20);
                $adUuid= bin2hex($row['admission_id']); $adUuid=substr($adUuid,0,8).'-'.substr($adUuid,8,4).'-'.substr($adUuid,12,4).'-'.substr($adUuid,16,4).'-'.substr($adUuid,20);
                $liveAdmissions[]=['ta_id'=>$taUuid,'ad_id'=>$adUuid,'given'=>$row['given_name'],'family'=>$row['family_name'],'a_status'=>$row['a_status']];
                if(!$liveAssign) $liveAssign=$taUuid;
            }
            $stmt->close();
            // holds for these cases (for display)
            try{ $resH=$liveDb->query("SELECT id, case_id, starts_at, ends_at, status FROM joma_capacity_holds WHERE status='HELD' ORDER BY held_at DESC LIMIT 5"); if($resH) while($rh=$resH->fetch_assoc()){ $liveHolds[]=['id'=>joma_db_bin_to_uuid($rh['id']),'case_id'=>joma_db_bin_to_uuid($rh['case_id']),'starts'=>$rh['starts_at'],'ends'=>$rh['ends_at'],'status'=>$rh['status']]; } }catch(Throwable $e){}
            // cases
            $stmt=$liveDb->prepare("SELECT c.id as c_id, c.purpose_summary, c.status, c.opened_at, r.status as r_status FROM joma_clinical_cases c JOIN joma_therapeutic_relationships r ON r.id=c.relationship_id WHERE c.responsible_therapist_id=? ORDER BY c.opened_at DESC LIMIT 5");
            $stmt->bind_param('s',$bin); $stmt->execute(); $res=$stmt->get_result();
            while($row=$res?$res->fetch_assoc():null){
                if(!$row) break;
                $cUuid=bin2hex($row['c_id']); $cUuid=substr($cUuid,0,8).'-'.substr($cUuid,8,4).'-'.substr($cUuid,12,4).'-'.substr($cUuid,16,4).'-'.substr($cUuid,20);
                $liveCases[]=['id'=>$cUuid,'purpose'=>$row['purpose_summary'],'status'=>$row['status'],'opened'=>$row['opened_at']];
            }
            $stmt->close();
        }
    } catch(Throwable $e){ $liveError=$e->getMessage(); }
}
$csrf = function_exists('joma_csrf_token') ? joma_csrf_token() : bin2hex(random_bytes(16));
?>
<!doctype html>
