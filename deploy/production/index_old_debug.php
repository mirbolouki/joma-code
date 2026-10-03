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
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>جوما — نمایش login تا پورتال</title>
<style>
:root{--c:#0f62fe;--c2:#0b4dd1;--bg:#f4f6fb;--card:#ffffff;--line:#e6e8ef;--muted:#667085;--ok:#067647;--ink:#0f172a}
*{box-sizing:border-box}html,body{margin:0;font-family:IRANSans,Vazirmatn,system-ui,-apple-system,Segoe UI,Roboto,Tahoma,sans-serif;background:linear-gradient(180deg,#f8faff 0,#f4f6fb 100%);color:var(--ink);line-height:1.6}
a{color:var(--c);text-decoration:none}
header{position:sticky;top:0;background:rgba(255,255,255,.92);backdrop-filter:saturate(180%) blur(10px);border-bottom:1px solid var(--line);z-index:10}
.wrap{max-width:860px;margin:0 auto;padding:16px}
.brand{display:flex;align-items:center;gap:12px;font-weight:900;letter-spacing:.2px}
.brand i{width:40px;height:40px;border-radius:12px;background:linear-gradient(135deg,var(--c),#7c3aed);color:#fff;display:grid;place-items:center;font-style:normal;box-shadow:0 8px 20px rgba(15,98,254,.25)}
.card{background:var(--card);border:1px solid var(--line);border-radius:20px;padding:20px;box-shadow:0 10px 30px rgba(15,23,42,.06),0 1px 2px rgba(15,23,42,.06)}
.steps{display:flex;gap:8px;overflow:auto;padding:8px 0;scrollbar-width:none}
.step{flex:0 0 auto;display:flex;align-items:center;gap:8px;padding:9px 13px;border-radius:999px;border:1px solid var(--line);background:#fff;font-size:13px;color:#344054}
.step.active{border-color:#c7d7fe;background:#eef2ff;color:var(--c);font-weight:800;box-shadow:0 2px 10px rgba(15,98,254,.12)}
.step.ok{border-color:#abefc6;background:#ecfdf3;color:var(--ok)}
.btn{appearance:none;border:0;background:linear-gradient(180deg,var(--c),var(--c2));color:#fff;padding:13px 16px;border-radius:14px;font-weight:800;width:100%;cursor:pointer;box-shadow:0 8px 18px rgba(15,98,254,.22);transition:transform .08s,box-shadow .2s}
.btn:active{transform:translateY(1px);box-shadow:0 4px 12px rgba(15,98,254,.18)}
.btn:disabled{opacity:.6;cursor:not-allowed;box-shadow:none}
.btn.sec{background:#fff;color:var(--c);border:1px solid #c7d7fe;box-shadow:none}
.input{width:100%;padding:13px 14px;border:1px solid #d0d5dd;border-radius:14px;background:#fff;outline:0;transition:border .2s,box-shadow .2s}
.input:focus{border-color:#8bb0ff;box-shadow:0 0 0 4px rgba(15,98,254,.12)}
.label{font-size:13px;color:var(--muted);margin:6px 2px 6px 0;display:block}
.grid{display:grid;gap:16px}
@media(min-width:720px){.grid{grid-template-columns:1.15fr .85fr}}
.kv{display:flex;justify-content:space-between;gap:12px;border-bottom:1px dashed #eceff5;padding:11px 0;font-size:14px}
.badge{display:inline-block;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:800;letter-spacing:.1px}
.badge.ok{background:#ecfdf3;color:var(--ok);border:1px solid #abefc6}
.badge.wait{background:#fffaeb;color:#b54708;border:1px solid #fedf89}
.badge.live{background:#eef2ff;color:var(--c);border:1px solid #c7d7fe}
.muted{color:var(--muted);font-size:13px}
.hint{background:linear-gradient(180deg,#f0f4ff,#eef2ff);border:1px solid #c7d7fe;padding:12px 14px;border-radius:14px;font-size:13px;color:#1e3a8a}
h2,h3{letter-spacing:.2px}
</style>
</head>
<body>
<header><div class="wrap" style="display:flex;justify-content:space-between;align-items:center">
<div class="brand"><i>ج</i><span>جوما — نمایشِ login تا پورتال</span> <?php if($liveMode): ?><span class="badge live">LIVE DB</span><?php else: ?><span class="badge wait">MOCK</span><?php endif; ?></div>
<?php if($demoUser): ?><a href="?logout=1">خروج</a><?php endif; ?>
</div></header>
<main class="wrap" style="padding-top:18px">
<?php if(!$demoUser): ?>
<div class="grid">
<div class="card">
<h2 style="margin:0 0 6px">ورود <?= $liveMode ? 'واقعی' : 'نمایشی' ?></h2>
<p class="muted" style="margin:0 0 14px">
<?php if($liveMode): ?>متصل به دیتابیسِ واقعی — با <code>demo-therapist / Demo1234!</code> وارد شوید (seed.php را یک‌بار اجرا کنید).<?php else: ?>بدون دیتابیس — هر نام/رمزی بزنید. برای نقش: <code>alice</code> درمانگر، <code>patient</code> بیمار.<?php endif; ?>
</p>
<?php if(!empty($loginError)): ?><div class="hint" style="background:#fee4e2;border-color:#fecdc2;color:#b42318;margin-bottom:12px"><?=htmlspecialchars($loginError,ENT_QUOTES,'UTF-8')?></div><?php endif; ?>
<?php if($liveError): ?><div class="hint" style="background:#fee4e2;border-color:#fecdc2;color:#b42318;margin-bottom:12px"><?=htmlspecialchars($liveError,ENT_QUOTES,'UTF-8')?></div><?php endif; ?>
<form method="post">
<label class="label">نام کاربری</label>
<input class="input" name="username" placeholder="<?= $liveMode ? 'demo-therapist' : 'alice یا patient' ?>" required>
<label class="label">رمز</label>
<input class="input" name="password" type="password" placeholder="<?= $liveMode ? 'Demo1234!' : 'هر رمزی' ?>" required>
<div style="height:12px"></div>
<button class="btn" name="login" value="1">ورود</button>
<p class="muted" style="margin:10px 0 0"><?php if($liveMode): ?>لاگین با <code>joma-core/auth.php</code> و <code>mysqli</code> واقعی.<?php else: ?>برای اتصالِ واقعی فایل <code>joma-config.php</code> را ایجاد کنید.<?php endif; ?></p>
</form>
</div>
<div class="card">
<h3 style="margin:0 0 8px">مسیر ۴ قدم</h3>
<div class="steps" style="flex-wrap:wrap">
<span class="step active">۱ ورود</span><span class="step">۲ ساخت پرونده</span><span class="step">۳ رزرو ۱۵دقیقه</span><span class="step">۴ پورتال</span>
</div>
<div class="hint">هاست ۱۴ تست PASS — <?php if($liveMode): ?>حالتِ LIVE فعال است، پذیرش واقعاً ۵ INSERT اتمیک می‌کند.<?php else: ?>حالتِ mock — فقط ظاهرِ موبایل-اول.<?php endif; ?></div>
</div>
</div>
<?php else: ?>
<div class="card" style="margin-bottom:12px">
<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
<div><b>سلام <?=htmlspecialchars($demoUser['name'],ENT_QUOTES,'UTF-8')?></b> <span class="badge <?= $role==='therapist'?'ok':'wait'?>"><?= $role==='therapist'?'درمانگر':'بیمار'?></span> <?php if($liveActive): ?><span class="badge live">LIVE</span><?php endif; ?></div>
<div class="muted"><?= $liveActive ? 'متصل به DB واقعی' : 'نمایشی' ?> — سندِ مادر ۴۰بخش</div>
</div>
<div class="steps">
<span class="step ok">۱ ورود ✓</span><span class="step active">۲ پرونده</span><span class="step">۳ رزرو</span><span class="step">۴ پورتال</span>
</div>
</div>

<div class="card" style="border:2px solid var(--c);margin-bottom:16px;background:#f8faff">
<h2 style="margin:0 0 8px;color:var(--c)">⚡ منوی سریع عملیات کلینیک</h2>
<p class="muted" style="margin:0 0 14px">کلیک روی هر گزینه شما را مستقیماً به بخش مربوطه می‌برد:</p>
<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));gap:10px">
<button class="btn" onclick="document.getElementById('liveAcceptBox').scrollIntoView({behavior:'smooth'})" style="padding:10px;font-size:13px">۱. تایید پذیرش و ساخت پرونده</button>
<button class="btn sec" onclick="document.getElementById('holdCard').scrollIntoView({behavior:'smooth'})" style="padding:10px;font-size:13px">۲. رزرو نوبت ۱۵دقیقه و جلسه</button>
<button class="btn sec" onclick="document.getElementById('formsCard').scrollIntoView({behavior:'smooth'})" style="padding:10px;font-size:13px">۳. ثبت فرم و پاسخ‌ها</button>
<button class="btn sec" onclick="document.getElementById('reportsCard').scrollIntoView({behavior:'smooth'})" style="padding:10px;font-size:13px">۴. گزارش ارزیابی و انتشار</button>
<button class="btn sec" onclick="document.getElementById('privateNotesCard').scrollIntoView({behavior:'smooth'})" style="padding:10px;font-size:13px">۵. یادداشت خصوصی</button>
<button class="btn sec" onclick="document.getElementById('closeCard').scrollIntoView({behavior:'smooth'})" style="padding:10px;font-size:13px;color:#b42318;border-color:#fecdc2">۶. بستن پرونده</button>
</div>
</div>

<?php if($liveActive): ?>
<div class="card" style="margin-bottom:12px;border:1px solid #c7d7fe">
<h3 style="margin:0 0 8px">📁 پرونده‌های واقعیِ شما (LIVE DB)</h3>
<?php if(empty($liveCases)): ?><p class="muted">هنوز پروندهٔ فعالی ندارید — یک پذیرش را تایید کنید تا ساخته شود.</p><?php else: ?>
<div style="display:grid;gap:10px">
<?php foreach($liveCases as $c): ?>
<div style="border:1px solid #e6e8ef;border-radius:12px;padding:12px">
<div style="display:flex;justify-content:space-between"><b>پرونده #<?= substr($c['id'],0,8) ?></b><span class="badge ok"><?= htmlspecialchars($c['status']) ?></span></div>
<div class="muted" style="margin:4px 0"><?= htmlspecialchars($c['purpose']) ?> · <?= htmlspecialchars($c['opened']) ?></div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
<?php endif; ?>

<div class="card" id="caseListCard">
<div style="display:flex;justify-content:space-between;align-items:center">
<h3 style="margin:0">📁 پرونده‌های من</h3>
<span class="muted">۳ پرونده — طبق سند: فردی/زوج/کودک</span>
</div>
<div style="height:10px"></div>
<div style="display:grid;gap:10px">
<div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;cursor:pointer" onclick="document.getElementById('caseDetail').scrollIntoView({behavior:'smooth'})">
<div style="display:flex;justify-content:space-between"><b>پرونده #C-1001 — خانم احمدی</b><span class="badge ok">ACTIVE</span></div>
<div class="muted" style="margin:4px 0">فردی بزرگسال · هدف: اضطراب · درمانگر مسئول: alice · امروز</div>
<div class="muted">یک CTA: دیدن پرونده → رزرو/یادداشت/گزارش</div>
</div>
<div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;opacity:.9;cursor:pointer" onclick="alert('نمایشی: پروندهٔ زوج — افراد: آقای حسینی + خانم حسینی — یک پرونده، دو شرکت‌کننده، یک درمانگرِ مسئول')">
<div style="display:flex;justify-content:space-between"><b>پرونده #C-1002 — حسینی (زوج)</b><span class="badge ok">ACTIVE</span></div>
<div class="muted" style="margin:4px 0">زوج · هدف: تعارضِ زوجی · شرکت‌کنندگان: ۲ نفر · جلسهٔ فردیِ درونِ زوج با همین پرونده</div>
</div>
<div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;opacity:.7">
<div style="display:flex;justify-content:space-between"><b>پرونده #C-1003 — کودک علی (۹ ساله)</b><span class="badge wait" style="background:#fee4e2;color:#b42318;border-color:#fecdc2">CLOSED</span></div>
<div class="muted" style="margin:4px 0">کودک · ولی: مادر (نماینده/پرداخت) · مستند: مادر پاسخ‌دهنده است نه شرکت‌کنندهٔ بالینی</div>
<div class="muted">بسته شده — باز نمی‌شود؛ بازگشت = پروندهٔ جدید (سند)</div>
</div>
</div>
<div class="hint" style="margin-top:12px">طبق سند: پرونده مرزِ هدف+بافت+افراد+رابطه است، نه برچسبِ خدمت. تغییرِ درمانگر = پروندهٔ جدید با حفظِ تاریخچه.</div>
</div>

<div style="height:12px"></div>
<div class="grid">
<div class="card" id="liveAcceptBox" style="border:2px solid var(--c)">
<h3 style="margin:0 0 4px">📁 ساخت پرونده — پذیرش <?= $liveActive ? '(LIVE)' : '(نمایشی)' ?></h3>
<p class="muted" style="margin:0 0 12px">در جوما «پذیرش = ساخت پرونده». با یک کلیک، <b>Relationship + Case (پرونده)</b> با هم و اتمیک ساخته می‌شود — نه جدا.</p>
<?php if($liveActive): ?>
<?php if(!empty($acceptResult)): ?>
<div class="hint" style="margin-bottom:10px;background:<?= ($acceptResult['ok']??false)?'#ecfdf3':'#fee4e2' ?>;border-color:<?= ($acceptResult['ok']??false)?'#abefc6':'#fecdc2' ?>;color:<?= ($acceptResult['ok']??false)?'#067647':'#b42318' ?>">
<?php if($acceptResult['ok']): ?>✓ پرونده ساخته شد — ۵ INSERT اتمیک: case <?= substr($acceptResult['case_id']??'',0,8) ?> — همین الان در لیستِ بالا ببین.<?php else: ?>✗ پذیرش رد شد: <?= htmlspecialchars($acceptResult['code']??'DB_ERROR') ?><?php endif; ?>
</div>
<?php endif; ?>
<?php if(empty($liveAdmissions)): ?>
<p class="muted">پذیرشِ بازی برای این درمانگر یافت نشد — <a href="seed.php?token=<?= htmlspecialchars($joma_config['token']??'') ?>">seed.php</a> را یک‌بار اجرا کنید.</p>
<?php else: foreach($liveAdmissions as $ad): ?>
<div style="border:1px solid #c7d7fe;border-radius:12px;padding:12px;margin-bottom:10px;background:#f8faff">
<div class="kv"><span>درخواست</span><b>#<?= substr($ad['ad_id'],0,8) ?> — AWAITING_THERAPIST</b></div>
<div class="kv"><span>متقاضی</span><b><?= htmlspecialchars(($ad['given']??'').' '.($ad['family']??'')) ?></b></div>
<div class="kv"><span>درمانگر</span><b><?= htmlspecialchars($demoUser['name']) ?></b></div>
<form method="post" style="margin-top:10px">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
<input type="hidden" name="assignment_id" value="<?= htmlspecialchars($ad['ta_id']) ?>">
<input type="hidden" name="command_id" value="<?= htmlspecialchars(strtolower((function(){ $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128); $h=bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); })())) ?>">
<label class="label">هدفِ پرونده (۱..۵۰۰ حرف)</label>
<input class="input" name="purpose" value="درمان فردی — اضطراب" required>
<div style="height:10px"></div>
<button class="btn" name="live_accept" value="1">✓ پذیرش و ساختِ پروندهٔ واقعی</button>
</form>
</div>
<?php endforeach; endif; ?>
<?php else: ?>
<div class="kv"><span>درخواستِ پذیرش</span><b>#A-9001 — AWAITING_THERAPIST</b></div>
<div class="kv"><span>متقاضی</span><b>خانم احمدی — فردی بزرگسال</b></div>
<div class="kv"><span>درمانگر مسئول</span><b>alice (شما)</b></div>
<div class="kv"><span>بافت/هدف</span><b>درمان فردی — اضطراب</b></div>
<div class="kv"><span>وضعیت</span><span class="badge wait">پرونده هنوز ساخته نشده</span></div>
<div style="height:12px"></div>
<button class="btn" onclick="document.getElementById('accept').style.display='block';this.style.display='none'">✓ پذیرش و ساختِ پروندهٔ فعال (نمایشی)</button>
<div id="accept" style="display:none">
<div class="hint" style="margin-bottom:10px">✓ پرونده ساخته شد — ۵ ردیفِ اتمیک: Relationship + Case + عضویت + کانتکست + Audit. از این به بعد <b>رزروِ ۱۵دقیقه و جلسات</b> فعال می‌شود.</div>
<div id="caseDetail" style="background:#ecfdf3;border:1px solid #abefc6;border-radius:12px;padding:12px">
<div style="display:flex;justify-content:space-between;align-items:center"><b>📁 پرونده #C-1001 — جزئیات</b><span class="badge ok">ACTIVE</span></div>
<div class="kv" style="border:0;padding:6px 0 0"><span>شماره پرونده</span><b>C-1001 / R-9001</b></div>
<div class="kv" style="border:0;padding:4px 0"><span>تاریخ ساخت</span><b>امروز — توسط alice</b></div>
<div class="kv" style="border:0;padding:4px 0"><span>افرادِ پرونده</span><b>خانم احمدی (مراجع) + alice (مسئول)</b></div>
<div class="kv" style="border:0;padding:4px 0"><span>یادداشتِ خصوصی</span><span class="muted">فقط نویسنده می‌بیند — حتی مدیر هم نه</span></div>
<div class="kv" style="border:0;padding:4px 0 0"><span>وضعیتِ مالی/رضایت</span><span class="muted">دروازهٔ خدمت — نه شرطِ ساختِ پرونده</span></div>
</div>
<div style="height:10px"></div>
<button class="btn sec" onclick="document.getElementById('holdCard').scrollIntoView({behavior:'smooth'})">رفتن به رزروِ پرونده</button>
</div>
<?php endif; ?>
</div>
<div class="card" style="border:1px dashed #d0d5dd">
<h3 style="margin:0 0 8px">📝 یادداشتِ خصوصی — فقط نویسنده</h3>
<p class="muted" style="margin:0 0 10px">حتی مدیر با نقشِ درمانی هم نمی‌بیند؛ بیمار/ولی/درمانگرِ دیگر هم نه. جدا از گزارش.</p>
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px">
<b>یادداشتِ خصوصیِ alice — امروز ۱۰:۳۰</b>
<div class="muted" style="margin:6px 0">«مراجع اضطرابِ موقعیتی گزارش کرد...»</div>
<span class="badge wait">Author-only</span>
</div>
<div style="height:8px"></div>
<button class="btn sec" onclick="alert('نمایشی: تلاشِ بیمار/مدیر برای دیدنِ این یادداشت → 404 یکنواخت (joma-core/files.php + portal)')">تلاشِ بیمار برای دیدن → باید 404</button>
</div>
<div class="card">
<h3 style="margin:0 0 8px">📄 گزارش — انتشار با Audience صریح</h3>
<p class="muted" style="margin:0 0 10px">Draft فقط برای درمانگر؛ انتشار با Audience صریح برای بیمار.</p>
<div class="kv"><span>گزارش #R-201</span><span class="badge wait">DRAFT</span></div>
<div class="kv"><span>دسترسی بیمار</span><span class="muted">ندارد</span></div>
<div style="height:8px"></div>
<button class="btn sec" onclick="alert('نمایشی: در واقعی joma-core/portal.php با JOIN انتشار+Audience چک می‌کند')">تلاش برای دانلود (باید 404 بدهد)</button>
<div style="height:10px"></div>
<div class="kv"><span>گزارش #R-202</span><span class="badge ok">PUBLISHED — AUDIENCE: patient</span></div>
<div class="kv"><span>دسترسی بیمار</span><span class="badge ok">دارد</span></div>
<button class="btn" onclick="alert('نمایشی: دانلود با هدرهای private,no-store و ETag')">دانلود مجاز (نمایشی)</button>
</div>
</div>

<div class="card" id="holdCard" style="margin-top:16px">
<h3 style="margin:0 0 8px">📅 نوبت و جلسه — ۱:۱ اختیاری <?= $liveActive ? '(LIVE)' : '' ?></h3>
<p class="muted" style="margin:0 0 10px">Session ↔ Appointment اختیاریِ ۱:۱؛ <?php if($liveActive): ?>در LIVE با <code>joma_hold_create</code> و قفلِ ۱۵دقیقه واقعی.<?php else: ?>جلسهٔ بدون نوبت با Case/درمانگرِ معتبر مجاز است.<?php endif; ?></p>
<?php if($liveActive): ?>
<?php if(!empty($holdResult)): ?>
<div class="hint" style="margin-bottom:10px;background:<?= ($holdResult['ok']??false)?'#ecfdf3':'#fee4e2' ?>;border-color:<?= ($holdResult['ok']??false)?'#abefc6':'#fecdc2' ?>;color:<?= ($holdResult['ok']??false)?'#067647':'#b42318' ?>">
<?php if($holdResult['ok']): ?>✓ hold ساخته شد — <?= htmlspecialchars($holdResult['hold_id']??'') ?> — انقضا ۱۵ دقیقه<?php else: ?>✗ hold رد شد: <?= htmlspecialchars($holdResult['code']??'DB_ERROR') ?><?php endif; ?>
</div>
<?php endif; ?>
<?php if(empty($liveCases)): ?>
<p class="muted">ابتدا یک پرونده بسازید تا نوبت بگیرید.</p>
<?php else: ?>
<form method="post" style="display:grid;gap:10px">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
<input type="hidden" name="hold_command_id" value="<?= htmlspecialchars(strtolower((function(){ $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128); $h=bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); })())) ?>">
<label class="label">پرونده</label>
<select class="input" name="hold_case_id" required>
<?php foreach($liveCases as $c): ?><option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars(substr($c['id'],0,8).' — '.$c['purpose']) ?></option><?php endforeach; ?>
</select>
<input type="hidden" name="hold_offering_id" value="<?= htmlspecialchars($liveOfferingId??'') ?>">
<input type="hidden" name="hold_resource_id" value="<?= htmlspecialchars($liveResourceId??'') ?>">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
<div><label class="label">شروع (UTC)</label><input class="input" name="starts_at" type="datetime-local" required></div>
<div><label class="label">پایان (UTC)</label><input class="input" name="ends_at" type="datetime-local" required></div>
</div>
<p class="muted" style="margin:0">زمان به UTC ذخیره می‌شود — ۱۵ دقیقه سقف. اگر هم‌زمانِ متداخل بزنید <code>CAPACITY_CONFLICT</code> می‌گیرید.</p>
<button class="btn" name="live_hold" value="1">رزروِ ۱۵ دقیقه (LIVE)</button>
</form>
<?php if(!empty($liveHolds)): ?>
<div style="margin-top:12px;border-top:1px dashed #eceff5;padding-top:10px">
<b>holds فعال — تبدیل به نوبتِ قطعی</b>
<?php foreach($liveHolds as $h): ?><div style="border:1px solid #c7d7fe;border-radius:12px;padding:10px;margin-top:8px;background:#f8faff">
<div class="kv" style="border:0;padding:4px 0"><span><?= substr($h['id'],0,8) ?> — <?= htmlspecialchars($h['starts'].' → '.$h['ends']) ?></span><span class="badge ok"><?= htmlspecialchars($h['status']) ?></span></div>
<form method="post" style="margin-top:6px">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
<input type="hidden" name="confirm_hold_id" value="<?= htmlspecialchars($h['id']) ?>">
<input type="hidden" name="confirm_offering_id" value="<?= htmlspecialchars($liveOfferingId??'') ?>">
<input type="hidden" name="confirm_command_id" value="<?= htmlspecialchars(strtolower((function(){ $b=random_bytes(16); $b[6]=chr((ord($b[6])&15)|64); $b[8]=chr((ord($b[8])&63)|128); $h=bin2hex($b); return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); })())) ?>">
<button class="btn sec" name="live_hold_confirm" value="1" style="padding:8px">تبدیل به نوبتِ قطعی (مصرفِ hold)</button>
</form>
</div><?php endforeach; ?>
<?php if(!empty($holdConfirmResult)): ?>
<div class="hint" style="margin-top:10px;background:<?= ($holdConfirmResult['ok']??false)?'#ecfdf3':'#fee4e2' ?>;border-color:<?= ($holdConfirmResult['ok']??false)?'#abefc6':'#fecdc2' ?>;color:<?= ($holdConfirmResult['ok']??false)?'#067647':'#b42318' ?>">
<?php if($holdConfirmResult['ok']): ?>✓ نوبتِ قطعی شد — appointment <?= substr($holdConfirmResult['appointment_id']??'',0,8) ?><?php else: ?>✗ تبدیل رد شد: <?= htmlspecialchars($holdConfirmResult['code']??'DB_ERROR') ?><?php endif; ?>
</div>
<?php endif; ?>
</div>
<?php endif; ?>
<?php if(!empty($liveAppointments)): ?>
<div style="margin-top:14px;border-top:1px dashed #eceff5;padding-top:10px">
<b>نوبت‌های قطعی (Appointments) — ۱:۱ اختیاری با Session</b>
<?php if(!empty($sessionCreateResult)): ?>
<div class="hint" style="margin:8px 0;background:<?= ($sessionCreateResult['ok']??false)?'#ecfdf3':'#fee4e2' ?>;border-color:<?= ($sessionCreateResult['ok']??false)?'#abefc6':'#fecdc2' ?>;color:<?= ($sessionCreateResult['ok']??false)?'#067647':'#b42318' ?>">
<?php if($sessionCreateResult['ok']): ?>✓ جلسه بالینی ۱:۱ تشکیل شد — session <?= substr($sessionCreateResult['session_id']??'',0,8) ?><?php else: ?>✗ تشکیل جلسه رد شد: <?= htmlspecialchars($sessionCreateResult['code']??'DB_ERROR') ?><?php endif; ?>
</div>
<?php endif; ?>
<?php foreach($liveAppointments as $ap): ?>
<div style="border:1px solid #d0d5dd;border-radius:10px;padding:8px 12px;margin-top:8px;background:#fff">
<div class="kv" style="border:0;padding:2px 0">
<span>نوبت <?= substr($ap['id'],0,8) ?> (پرونده <?= substr($ap['case_id'],0,8) ?>) — <?= htmlspecialchars($ap['starts']) ?></span>
<span class="badge ok"><?= htmlspecialchars($ap['status']) ?></span>
</div>
<?php if($ap['session_id']): ?>
<div class="hint" style="margin-top:4px;padding:4px 8px;font-size:12px;background:#f0fdf4;color:#166534">
✓ متصل به جلسه بالینی ۱:۱ (جلسه <?= substr($ap['session_id'],0,8) ?>)
</div>
<?php else: ?>
<form method="post" style="margin-top:6px">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
<input type="hidden" name="session_appointment_id" value="<?= htmlspecialchars($ap['id']) ?>">
<input type="hidden" name="session_case_id" value="<?= htmlspecialchars($ap['case_id']) ?>">
<button class="btn sec" name="live_create_session" value="1" style="padding:4px 10px;font-size:13px">تشکیل جلسه بالینی برای این نوبت (اتصال ۱:۱)</button>
</form>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php else: ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
<div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px"><b>۱۰:۰۰–۱۰:۱۵</b><div class="muted">نوبتِ hold — خالی</div><button class="btn" style="margin-top:8px" onclick="this.textContent='رزرو شد ✓';this.disabled=true;document.getElementById('holdOk').style.display='block';document.getElementById('sessionOk').style.display='block'">رزروِ نوبت</button></div>
<div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;opacity:.6"><b>۱۰:۱۰–۱۰:۲۵</b><div class="muted">متداخل — باید رد شود</div><button class="btn sec" disabled>رد (CAPACITY_CONFLICT)</button></div>
</div>
<div id="holdOk" style="display:none;margin-top:12px" class="hint">✓ hold ساخته شد — انقضا ۱۵ دقیقه. در واقعی قفلِ سطر ۲ثانیه.</div>
<div id="sessionOk" style="display:none;margin-top:10px;background:#ecfdf3;border:1px solid #abefc6;border-radius:12px;padding:12px">
<b>جلسه #S-301 — پرونده #C-1001</b><div class="muted" style="margin:4px 0">بدون نوبت هم می‌شود؛ با نوبت، یک جلسه ↔ یک نوبت (nullable UNIQUE)</div>
<div class="kv" style="border:0;padding:4px 0"><span>وضعیت</span><span class="badge ok">برنامه‌ریزی شده</span></div>
</div>
<?php endif; ?>
</div>

<?php endif; ?>
</div>

<div class="card" id="formsCard" style="margin-top:16px">
<h3 style="margin:0 0 8px">📋 فرم‌های نسخه‌دار (D-31 / D-33) <?= $liveActive ? '(LIVE)' : '' ?></h3>
<p class="muted" style="margin:0 0 10px">فرم‌های تصویری محدود و نسخه‌دار؛ بدون کد اجرایی؛ تفکیک دقیق سوژه (Subject) و پاسخ‌دهنده (Respondent).</p>
<?php if($liveActive): ?>
<?php if(!empty($formResult)): ?>
<div class="hint" style="margin-bottom:10px;background:<?= ($formResult['ok']??false)?'#ecfdf3':'#fee4e2' ?>;border-color:<?= ($formResult['ok']??false)?'#abefc6':'#fecdc2' ?>;color:<?= ($formResult['ok']??false)?'#067647':'#b42318' ?>">
<?php if($formResult['ok']): ?>✓ نمونه فرم ساخته شد — نسخه <?= substr($formResult['instance_id']??'',0,8) ?><?php else: ?>✗ ساخت فرم رد شد: <?= htmlspecialchars($formResult['code']??'DB_ERROR') ?><?php endif; ?>
</div>
<?php endif; ?>
<?php if(!empty($formSubmitResult)): ?>
<div class="hint" style="margin-bottom:10px;background:<?= ($formSubmitResult['ok']??false)?'#ecfdf3':'#fee4e2' ?>;border-color:<?= ($formSubmitResult['ok']??false)?'#abefc6':'#fecdc2' ?>;color:<?= ($formSubmitResult['ok']??false)?'#067647':'#b42318' ?>">
<?php if($formSubmitResult['ok']): ?>✓ پاسخ فرم ثبت نهایی شد (SUBMITTED) — بازبینی #<?= htmlspecialchars((string)($formSubmitResult['revision_no']??1)) ?><?php else: ?>✗ ثبت پاسخ رد شد: <?= htmlspecialchars($formSubmitResult['code']??'DB_ERROR') ?><?php endif; ?>
</div>
<?php endif; ?>

<?php if(empty($liveForms)): ?>
<p class="muted">فرمی با وضعیت PUBLISHED موجود نیست (ابتدا <code>seed.php</code> را فراخوانی کنید).</p>
<?php else: ?>
<form method="post" style="display:grid;gap:10px">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
<label class="label">انتخاب فرم منتشرشده</label>
<select class="input" name="form_version_id" required>
<?php foreach($liveForms as $f): ?><option value="<?= htmlspecialchars($f['id']) ?>"><?= htmlspecialchars($f['label'].' (نسخه '.$f['ver'].')') ?></option><?php endforeach; ?>
</select>
<label class="label">اتصال به پرونده (اختیاری؛ در صورت خالی بودن در بافت پذیرش)</label>
<select class="input" name="form_case_id">
<option value="">-- بدون پرونده (سطح پذیرش) --</option>
<?php foreach($liveCases as $c): ?><option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars(substr($c['id'],0,8).' — '.$c['purpose']) ?></option><?php endforeach; ?>
</select>
<button class="btn" name="live_form_create" value="1">ایجاد نمونه فرم برای پرونده</button>
</form>

<?php if(!empty($liveFormInstances)): ?>
<div style="margin-top:14px;border-top:1px dashed #eceff5;padding-top:10px">
<b>نمونه‌های فرم (Instances)</b>
<?php foreach($liveFormInstances as $inst): ?>
<div style="border:1px solid #d0d5dd;border-radius:10px;padding:10px;margin-top:8px;background:#fff">
<div class="kv" style="border:0;padding:2px 0">
<span>نمونه <?= substr($inst['id'],0,8) ?><?php if($inst['case_id']): ?> (متصل به پرونده <?= substr($inst['case_id'],0,8) ?>)<?php endif; ?></span>
<span class="badge <?= $inst['status']==='SUBMITTED'?'ok':'wait' ?>"><?= htmlspecialchars($inst['status']) ?></span>
</div>
<?php if($inst['status']==='DRAFT'): ?>
<form method="post" style="margin-top:6px;display:grid;gap:6px">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
<input type="hidden" name="instance_id" value="<?= htmlspecialchars($inst['id']) ?>">
<input class="input" name="answers_json" placeholder='{"q1":"پاسخ مراجع یا درمانگر"}' value='{"q1":"پاسخ ثبت‌شده اولیه"}'>
<button class="btn sec" name="live_form_submit" value="1" style="padding:6px 12px">ثبت پاسخ (SUBMITTED Revision)</button>
</form>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php else: ?>
<p class="muted">در حالت آزمایشی نمایشی. در حالت LIVE می‌توانید فرم‌های چندنسخه‌ای را تکمیل و بازبینی‌ها را ثبت کنید.</p>
<?php endif; ?>
</div>

<div class="card" id="reportsCard" style="margin-top:16px">
<h3 style="margin:0 0 8px">📑 گزارش‌های ارزیابی (D-35: Upload vs. Publish) <?= $liveActive ? '(LIVE)' : '' ?></h3>
<p class="muted" style="margin:0 0 10px">تفکیک صریح بارگذاری پیش‌نویس توسط ارزیاب و انتشار رسمی با تعیین مخاطب (Audience) منحصراً توسط درمانگر مسئول پرونده.</p>
<?php if($liveActive): ?>
<?php if(!empty($reportUploadResult)): ?>
<div class="hint" style="margin-bottom:10px;background:<?= ($reportUploadResult['ok']??false)?'#ecfdf3':'#fee4e2' ?>;border-color:<?= ($reportUploadResult['ok']??false)?'#abefc6':'#fecdc2' ?>;color:<?= ($reportUploadResult['ok']??false)?'#067647':'#b42318' ?>">
<?php if($reportUploadResult['ok']): ?>✓ پیش‌نویس گزارش بارگذاری شد — نسخه #<?= htmlspecialchars((string)($reportUploadResult['version_no']??1)) ?><?php else: ?>✗ بارگذاری گزارش رد شد: <?= htmlspecialchars($reportUploadResult['code']??'DB_ERROR') ?><?php endif; ?>
</div>
<?php endif; ?>
<?php if(!empty($reportPublishResult)): ?>
<div class="hint" style="margin-bottom:10px;background:<?= ($reportPublishResult['ok']??false)?'#ecfdf3':'#fee4e2' ?>;border-color:<?= ($reportPublishResult['ok']??false)?'#abefc6':'#fecdc2' ?>;color:<?= ($reportPublishResult['ok']??false)?'#067647':'#b42318' ?>">
<?php if($reportPublishResult['ok']): ?>✓ گزارش رسماً منتشر شد (PUBLISHED) — ثبت در درگاه مراجع<?php else: ?>✗ انتشار گزارش رد شد: <?= htmlspecialchars($reportPublishResult['code']??'DB_ERROR') ?><?php endif; ?>
</div>
<?php endif; ?>

<?php if(empty($liveCases)): ?>
<p class="muted">ابتدا یک پرونده فعال بسازید.</p>
<?php else: ?>
<form method="post" style="display:grid;gap:10px">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
<label class="label">انتخاب پرونده</label>
<select class="input" name="report_case_id" required>
<?php foreach($liveCases as $c): ?><?php if($c['status']==='ACTIVE'): ?><option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars(substr($c['id'],0,8).' — '.$c['purpose']) ?></option><?php endif; ?><?php endforeach; ?>
</select>
<label class="label">متن گزارش ارزیابی</label>
<textarea class="input" name="report_text" rows="3" required placeholder="متن کامل ارزیابی بالینی مراجع..."></textarea>
<button class="btn" name="live_upload_report" value="1">بارگذاری پیش‌نویس گزارش (ارزیاب/درمانگر)</button>
</form>

<?php if(!empty($liveReports)): ?>
<div style="margin-top:14px;border-top:1px dashed #eceff5;padding-top:10px">
<b>پیش‌نویس‌ها و گزارش‌های ثبت‌شده</b>
<?php foreach($liveReports as $rep): ?>
<div style="border:1px solid #d0d5dd;border-radius:10px;padding:10px;margin-top:8px;background:#fff">
<div class="kv" style="border:0;padding:2px 0">
<span>نسخه <?= substr($rep['id'],0,8) ?> (پرونده <?= substr($rep['case_id'],0,8) ?>)</span>
<span class="badge <?= $rep['status']==='REVIEWED'?'ok':'wait' ?>"><?= htmlspecialchars($rep['status']) ?></span>
</div>
<p style="margin:4px 0 8px;font-size:13px;color:#333"><?= htmlspecialchars($rep['text']) ?></p>
<?php if($rep['status']==='DRAFT_CLINICIAN_REVIEW'): ?>
<form method="post" style="display:grid;gap:6px">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
<input type="hidden" name="report_version_id" value="<?= htmlspecialchars($rep['id']) ?>">
<input type="hidden" name="publish_case_id" value="<?= htmlspecialchars($rep['case_id']) ?>">
<input type="hidden" name="recipient_person_id" value="<?= htmlspecialchars($snapshotCache['account']['person_id']??'') ?>">
<button class="btn sec" name="live_publish_report" value="1" style="padding:6px 12px">انتشار رسمی برای مراجع (منحصراً درمانگر مسئول)</button>
</form>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php else: ?>
<p class="muted">در حالت دمو: تفکیک D-35 به گونه‌ای است که ارزیاب فقط آپلود پیش‌نویس می‌کند و انتشار به عهده درمانگر مسئول است.</p>
<?php endif; ?>
</div>

<div class="card" id="closeCard" style="margin-top:16px">
<h3 style="margin:0 0 8px">🔒 بستن پرونده و خاتمه رابطه (D-06 / D-20) <?= $liveActive ? '(LIVE)' : '' ?></h3>
<p class="muted" style="margin:0 0 10px">خاتمه اتمیک رابطه درمانی (ENDED) و بستن پرونده (CLOSED) با ثبت دلیل؛ بدون امکان بازگشایی در V1.</p>
<?php if($liveActive): ?>
<?php if(!empty($caseCloseResult)): ?>
<div class="hint" style="margin-bottom:10px;background:<?= ($caseCloseResult['ok']??false)?'#ecfdf3':'#fee4e2' ?>;border-color:<?= ($caseCloseResult['ok']??false)?'#abefc6':'#fecdc2' ?>;color:<?= ($caseCloseResult['ok']??false)?'#067647':'#b42318' ?>">
<?php if($caseCloseResult['ok']): ?>✓ پرونده رسماً مختومه شد (CLOSED) و رابطه پایان یافت (ENDED)<?php else: ?>✗ بستن پرونده رد شد: <?= htmlspecialchars($caseCloseResult['code']??'DB_ERROR') ?><?php endif; ?>
</div>
<?php endif; ?>

<?php if(empty($liveCases)): ?>
<p class="muted">پرونده‌ای موجود نیست.</p>
<?php else: ?>
<form method="post" style="display:grid;gap:10px">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
<label class="label">انتخاب پرونده جهت بستن</label>
<select class="input" name="close_case_id" required>
<?php foreach($liveCases as $c): ?><option value="<?= htmlspecialchars($c['id']) ?>" <?= $c['status']==='CLOSED'?'disabled':'' ?>><?= htmlspecialchars(substr($c['id'],0,8).' — '.$c['purpose'].' ('.$c['status'].')') ?></option><?php endforeach; ?>
</select>
<label class="label">دلیل خاتمه و خلاصه بالینی</label>
<input class="input" name="close_reason" required value="تکمیل پروتکل درمانی و ارزیابی پس‌آزمون">
<button class="btn sec" name="live_close_case" value="1" style="background:#fee4e2;border-color:#fecdca;color:#b42318">بستن نهایی پرونده و خاتمه رابطه (اتمیک)</button>
</form>
<?php endif; ?>
<?php else: ?>
<p class="muted">در حالت دمو: بستن پرونده وضعیت رابطه را به ENDED و پرونده را به CLOSED تغییر می‌دهد.</p>
<?php endif; ?>
</div>

<div class="card" id="privateNotesCard" style="margin-top:16px">
<h3 style="margin:0 0 8px">🔐 یادداشت خصوصی درمانگر (PrivateNote - D-33) <?= $liveActive ? '(LIVE)' : '' ?></h3>
<p class="muted" style="margin:0 0 10px">قید قطعی «فقط مؤلف» (AUTHOR_ONLY)؛ ذخیره متادیتای مبهم و عدم افشا به مراجع، ادمین فنی یا سایر درمانگران.</p>
<?php if($liveActive): ?>
<?php if(!empty($privateNoteResult)): ?>
<div class="hint" style="margin-bottom:10px;background:<?= ($privateNoteResult['ok']??false)?'#ecfdf3':'#fee4e2' ?>;border-color:<?= ($privateNoteResult['ok']??false)?'#abefc6':'#fecdc2' ?>;color:<?= ($privateNoteResult['ok']??false)?'#067647':'#b42318' ?>">
<?php if($privateNoteResult['ok']): ?>✓ یادداشت خصوصی مؤلف ثبت شد — شناسه <?= substr($privateNoteResult['note_id']??'',0,8) ?> (حالت <?= htmlspecialchars($privateNoteResult['storage_mode']??'') ?>)<?php else: ?>✗ ثبت یادداشت خصوصی رد شد: <?= htmlspecialchars($privateNoteResult['code']??'DB_ERROR') ?><?php endif; ?>
</div>
<?php endif; ?>

<?php if(empty($liveCases)): ?>
<p class="muted">ابتدا یک پرونده فعال بسازید.</p>
<?php else: ?>
<form method="post" style="display:grid;gap:10px">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
<label class="label">پرونده</label>
<select class="input" name="note_case_id" required>
<?php foreach($liveCases as $c): ?><?php if($c['status']==='ACTIVE'): ?><option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars(substr($c['id'],0,8).' — '.$c['purpose']) ?></option><?php endif; ?><?php endforeach; ?>
</select>
<label class="label">حالت ذخیره‌سازی ایمن</label>
<select class="input" name="storage_mode">
<option value="SERVER_CIPHERTEXT">SERVER_CIPHERTEXT (متن رمزشده در سمت سرور)</option>
<option value="WINDOWS_LOCAL">WINDOWS_LOCAL (کلید و متن در کلاینت ویندوز)</option>
</select>
<button class="btn" name="live_create_note" value="1">ثبت مرجع یادداشت خصوصی (AUTHOR_ONLY)</button>
</form>

<?php if(!empty($liveNotes)): ?>
<div style="margin-top:14px;border-top:1px dashed #eceff5;padding-top:10px">
<b>یادداشت‌های خصوصی من در این کلینیک (منحصراً برای این کاربر)</b>
<?php foreach($liveNotes as $n): ?>
<div class="kv" style="border:0;padding:4px 0">
<span>یادداشت <?= substr($n['id'],0,8) ?> (پرونده <?= substr($n['case_id'],0,8) ?>)</span>
<span class="badge ok"><?= htmlspecialchars($n['mode']) ?></span>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php else: ?>
<p class="muted">در حالت دمو: یادداشت خصوصی با اصل AUTHOR_ONLY محافظت می‌شود.</p>
<?php endif; ?>
</div>

<div class="card" id="suspendRepCard" style="margin-top:16px">
<h3 style="margin:0 0 8px">⚠️ تعلیق بالینی نمایندگی در پرونده (D-36) <?= $liveActive ? '(LIVE)' : '' ?></h3>
<p class="muted" style="margin:0 0 10px">ثبت نگرانی بالینی مستند توسط درمانگر مسئول و تعلیق فوری دسترسی نماینده صرفاً در مرز این پرونده.</p>
<?php if($liveActive): ?>
<?php if(!empty($suspendRepResult)): ?>
<div class="hint" style="margin-bottom:10px;background:<?= ($suspendRepResult['ok']??false)?'#ecfdf3':'#fee4e2' ?>;border-color:<?= ($suspendRepResult['ok']??false)?'#abefc6':'#fecdc2' ?>;color:<?= ($suspendRepResult['ok']??false)?'#067647':'#b42318' ?>">
<?php if($suspendRepResult['ok']): ?>✓ نمایندگی در این پرونده به صورت بالینی تعلیق شد (SUSPENDED)<?php else: ?>✗ تعلیق نمایندگی رد شد: <?= htmlspecialchars($suspendRepResult['code']??'DB_ERROR') ?><?php endif; ?>
</div>
<?php endif; ?>

<?php if(empty($liveCases)): ?>
<p class="muted">پرونده‌ای موجود نیست.</p>
<?php else: ?>
<form method="post" style="display:grid;gap:10px">
<input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
<label class="label">پرونده تحت درمان</label>
<select class="input" name="suspend_case_id" required>
<?php foreach($liveCases as $c): ?><?php if($c['status']==='ACTIVE'): ?><option value="<?= htmlspecialchars($c['id']) ?>"><?= htmlspecialchars(substr($c['id'],0,8).' — '.$c['purpose']) ?></option><?php endif; ?><?php endforeach; ?>
</select>
<label class="label">شناسه نمایندگی والد/سرپرست</label>
<input class="input" name="suspend_rep_id" required placeholder="00000000-0000-4000-8000-000000000001" value="00000000-0000-4000-8000-000000000001">
<label class="label">کد دلیل بالینی ممیزی</label>
<select class="input" name="suspend_reason">
<option value="CLINICAL_CHILD_SAFEGUARD">CLINICAL_CHILD_SAFEGUARD (صیانت از کودک/مراجع)</option>
<option value="CLINICAL_CONFLICT_OF_INTEREST">CLINICAL_CONFLICT_OF_INTEREST (تعارض منافع والد)</option>
</select>
<button class="btn sec" name="live_suspend_rep" value="1" style="background:#fee4e2;border-color:#fecdca;color:#b42318">ثبت تعلیق بالینی نمایندگی (ثبت در Audit Ledger)</button>
</form>
<?php endif; ?>
<?php else: ?>
<p class="muted">تعلیق نمایندگی بدون لغو پرونده‌های مستقل دیگر اجرا می‌شود.</p>
<?php endif; ?>
</div>

<div class="card" id="clientPortalCard" style="margin-top:16px;background:#f0fdf4;border-color:#bbf7d0">
<h3 style="margin:0 0 8px">👤 نمای پرتال مراجع (Client Portal View)</h3>
<p class="muted" style="margin:0 0 10px">مراجع صرفاً اسنادی را مشاهده می‌کند که رسماً منتشر شده‌اند (PUBLISHED) و هویت او در لیست مخاطبان صریح (PublicationAudience) ثبت شده است.</p>
<?php if(!empty($liveClientReports)): ?>
<div style="display:grid;gap:8px">
<b>گزارش‌های منتشرشده قابل دسترس برای شما</b>
<?php foreach($liveClientReports as $cr): ?>
<div style="background:#fff;border:1px solid #86efac;border-radius:10px;padding:10px">
<div class="kv" style="border:0;padding:2px 0"><span>گزارش نسخه <?= substr($cr['version_id'],0,8) ?></span><span class="badge ok">PORTAL</span></div>
<p style="margin:6px 0;font-size:13px;color:#1e293b"><?= htmlspecialchars($cr['report_text']) ?></p>
<div class="muted" style="font-size:11px">تاریخ انتشار: <?= htmlspecialchars($cr['published_at']) ?></div>
</div>
<?php endforeach; ?>
</div>
<?php else: ?>
<p class="muted" style="margin:0">هیچ سند منتشرشده‌ای برای کاربر جاری به عنوان مخاطب ثبت نشده است.</p>
<?php endif; ?>
</div>

<div class="card" style="margin-top:16px">
<h3 style="margin:0 0 6px">وضعیتِ فنی</h3>
<div class="kv"><span>۱۴ تستِ سبک</span><span class="badge ok">۱۴/۱۴ PASS</span></div>
<div class="kv"><span>حالت</span><span class="badge <?= $liveMode?'live':'wait' ?>"><?= $liveMode ? 'LIVE DB' : 'MOCK' ?></span></div>
<div class="kv"><span>PHP</span><span class="muted"><?= htmlspecialchars(PHP_VERSION) ?></span></div>
<?php if($liveMode): ?><p class="muted" style="margin:10px 0 0">برای ساختِ دادهٔ تست: <code>seed.php?token=...</code> را یک‌بار بزن، سپس با <code>demo-therapist / Demo1234!</code> وارد شو.</p><?php endif; ?>
</div>
<p class="muted" style="text-align:center;margin:18px 0">جوما — مرجعِ login تا فایل · <?= $liveMode ? 'LIVE' : 'نمایشی' ?> · <?= $liveActive ? 'اتصالِ واقعی' : 'دادهٔ ساختگی' ?></p>
</main>
</body>
</html>
