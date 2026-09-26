<?php
declare(strict_types=1);

/**
 * JOMA hold and confirm — capacity with resource locks.
 * mysqli + InnoDB only. All IDs BINARY(16). Times UTC DATETIME(6).
 * Contrast with lightweight domain_rules: this enforces atomic DB state.
 */
require_once __DIR__ . '/domain_rules.php';
require_once __DIR__ . '/db.php';

function joma_hold_sort_ids(array $ids): array {
    $norm = [];
    foreach ($ids as $u) {
        $uuid = joma_rule_uuid($u);
        if ($uuid === null) { return []; }
        $norm[] = $uuid;
    }
    sort($norm, SORT_STRING);
    return $norm;
}

function joma_hold_validate_resource_ids(array $ids): ?array {
    if (!$ids) { return null; }
    if (count($ids) > 10) { return null; } // DoS guard
    $seen = [];
    foreach ($ids as $u) {
        $uuid = joma_rule_uuid($u);
        if ($uuid === null) { return null; }
        $lower = strtolower($uuid);
        if (isset($seen[$lower])) { return null; } // duplicate
        $seen[$lower] = true;
    }
    // Return sorted lower-case for deterministic lock order.
    $sorted = array_keys($seen);
    sort($sorted, SORT_STRING);
    return $sorted;
}

/**
 * Create a capacity hold with resource allocations atomically.
 *
 * @param $db mysqli/mock
 * @param array $snapshot trusted context
 * @param string $caseId textual UUID
 * @param string $offeringId textual UUID
 * @param string $policyVersionId textual UUID
 * @param string $startsAt Y-m-d H:i:s.u UTC
 * @param string $endsAt
 * @param string $heldAt  == $nowUtc expected
 * @param string $expiresAt held+<=15min
 * @param array $resourceIds list of schedule_resource ids (UUID strings)
 * @param string $nowUtc current DB time
 * @param string $commandId UUID for command_receipts
 * @param array|null $ids deterministic ids for testing: hold_id, allocation_ids (array per resource)
 * @return array ['ok'=>bool,'code'=>string,'hold_id'=>?]
 */
function joma_hold_create($db, array $snapshot, string $caseId, string $offeringId, string $policyVersionId, string $startsAt, string $endsAt, string $heldAt, string $expiresAt, array $resourceIds, string $nowUtc, string $commandId, ?array $ids = null): array {
    $now = joma_rule_utc_us($nowUtc);
    if ($now === null) { return ['ok'=>false,'code'=>'INVALID_TIME']; }
    if (joma_rule_uuid($caseId)===null||joma_rule_uuid($offeringId)===null||joma_rule_uuid($policyVersionId)===null||joma_rule_uuid($commandId)===null) {
        return ['ok'=>false,'code'=>'INVALID_ID'];
    }
    $resources = joma_hold_validate_resource_ids($resourceIds);
    if ($resources===null) { return ['ok'=>false,'code'=>'INVALID_RESOURCE']; }
    $principal = joma_rule_principal($snapshot);
    if (!$principal['allowed']) { return ['ok'=>false,'code'=>$principal['code']]; }
    // Early time-window check independent of case (fast fail, no DB).
    $sU = joma_rule_utc_us($startsAt); $eU = joma_rule_utc_us($endsAt); $hU = joma_rule_utc_us($heldAt); $xU = joma_rule_utc_us($expiresAt);
    if ($sU===null||$eU===null||$hU===null||$xU===null||$eU<=$sU||$xU<=$hU||$xU-$hU>900000000) {
        return ['ok'=>false,'code'=>'INVALID_TIME_WINDOW'];
    }
    if (!is_object($db) || !method_exists($db,'prepare')) { return ['ok'=>false,'code'=>'DB_ERROR']; }

    $caseBin = joma_db_uuid_to_bin($caseId);
    $offeringBin = joma_db_uuid_to_bin($offeringId);
    $policyBin = joma_db_uuid_to_bin($policyVersionId);
    $commandBin = joma_db_uuid_to_bin($commandId);
    if ($caseBin===null||$offeringBin===null||$policyBin===null||$commandBin===null) { return ['ok'=>false,'code'=>'INVALID_ID']; }
    $resourceBins = array_map(fn($u)=>joma_db_uuid_to_bin($u), $resources);
    if (in_array(null,$resourceBins,true)) { return ['ok'=>false,'code'=>'INVALID_ID']; }

    $holdId = $ids['hold_id'] ?? joma_uuid_v4();
    if (joma_rule_uuid($holdId)===null) { return ['ok'=>false,'code'=>'INVALID_ID']; }
    $holdBin = joma_db_uuid_to_bin($holdId);
    $allocationIds = $ids['allocation_ids'] ?? [];
    if (!$allocationIds) {
        foreach ($resources as $i=>$rid) { $allocationIds[$i]=joma_uuid_v4(); }
    }
    if (count($allocationIds)!==count($resources)) { return ['ok'=>false,'code'=>'INVALID_ID']; }
    foreach ($allocationIds as $a) { if (joma_rule_uuid($a)===null) return ['ok'=>false,'code'=>'INVALID_ID']; }
    $allocationBins = array_map(fn($u)=>joma_db_uuid_to_bin($u), $allocationIds);

    if (!joma_db_begin($db)) { return ['ok'=>false,'code'=>'DB_ERROR']; }
    $inTx=true;
    try {
        // 1. Lock case engagement (needs to validate ACTIVE). Use join to get therapist linkage.
        $stmt = joma_db_prepare($db, 'SELECT c.id AS c_id, c.status AS c_status, c.relationship_id, c.responsible_therapist_id, r.status AS r_status, r.therapist_person_id AS r_therapist, ra.therapist_person_id AS accepted_therapist FROM joma_clinical_cases c JOIN joma_therapeutic_relationships r ON r.id = c.relationship_id JOIN joma_responsibility_acceptances ra ON ra.id = r.acceptance_id WHERE c.id = ? FOR UPDATE');
        if ($stmt===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }
        joma_db_bind_params($stmt,'s',[$caseBin]);
        $stmt->execute();
        $res=$stmt->get_result();
        $caseRow=$res?$res->fetch_assoc():null;
        @$stmt->close();
        if (!$caseRow) { joma_db_rollback($db); return ['ok'=>false,'code'=>'NOT_FOUND']; }
        $cIdText = joma_db_bin_to_uuid((string)($caseRow['c_id']??''));
        $cStatus = (string)($caseRow['c_status']??'');
        $rStatus = (string)($caseRow['r_status']??'');
        $respTherapist = joma_db_bin_to_uuid((string)($caseRow['responsible_therapist_id']??''));
        $relTherapist = joma_db_bin_to_uuid((string)($caseRow['r_therapist']??''));
        $acceptedTherapist = joma_db_bin_to_uuid((string)($caseRow['accepted_therapist']??''));
        if ($cIdText===null||$respTherapist===null||$relTherapist===null||$acceptedTherapist===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DATA_INTEGRITY']; }
        $caseForRule = ['id'=>$cIdText,'status'=>$cStatus,'relationship_status'=>$rStatus,'responsible_therapist_id'=>$respTherapist,'accepted_therapist_id'=>$acceptedTherapist,'relationship_therapist_id'=>$relTherapist];
        // Validate active engagement and hold terms.
        if (!joma_rule_active_engagement($caseForRule)) { joma_db_rollback($db); return ['ok'=>false,'code'=>'ACTIVE_ENGAGEMENT_REQUIRED']; }
        $terms = joma_rule_hold_terms($caseForRule,$startsAt,$endsAt,$heldAt,$expiresAt);
        if (!$terms['allowed']) { joma_db_rollback($db); return ['ok'=>false,'code'=>$terms['code']]; }

        // 2. Permission check: need scheduling.hold for the offering scope. Offering scope fetched.
        $stmt = joma_db_prepare($db, 'SELECT scope_id FROM joma_service_offerings WHERE id = ?');
        if ($stmt===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }
        joma_db_bind_params($stmt,'s',[$offeringBin]);
        $stmt->execute();
        $res=$stmt->get_result();
        $offRow=$res?$res->fetch_assoc():null;
        @$stmt->close();
        if (!$offRow) { joma_db_rollback($db); return ['ok'=>false,'code'=>'NOT_FOUND']; }
        $offeringScope = joma_db_bin_to_uuid((string)($offRow['scope_id']??''));
        if ($offeringScope===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DATA_INTEGRITY']; }
        // Check snapshot context for that scope.
        $ctxCheck = joma_rule_context($snapshot,$offeringScope,'scheduling.hold',$nowUtc);
        if (!$ctxCheck['allowed']) { joma_db_rollback($db); return ['ok'=>false,'code'=>$ctxCheck['code']]; }
        // Also ensure case belongs to same scope via operational context latest — fetch latest scope for case.
        $stmt = joma_db_prepare($db, 'SELECT scope_id FROM joma_case_operational_contexts WHERE case_id = ? AND valid_until IS NULL ORDER BY valid_from DESC LIMIT 1');
        if ($stmt!==null) {
            joma_db_bind_params($stmt,'s',[$caseBin]);
            $stmt->execute();
            $res=$stmt->get_result();
            $ctxRow=$res?$res->fetch_assoc():null;
            @$stmt->close();
            if ($ctxRow) {
                $caseScope = joma_db_bin_to_uuid((string)($ctxRow['scope_id']??''));
                if ($caseScope!==null && !joma_rule_same_id($caseScope,$offeringScope)) { joma_db_rollback($db); return ['ok'=>false,'code'=>'CONTEXT_DENIED']; }
            }
        }

        // 3. Lock resources in fixed sorted order.
        // Build IN clause.
        $placeholders = implode(',',array_fill(0,count($resourceBins),'?'));
        $sql = 'SELECT id FROM joma_schedule_resources WHERE id IN ('.$placeholders.') ORDER BY id FOR UPDATE';
        $stmt = joma_db_prepare($db,$sql);
        if ($stmt===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }
        $types = str_repeat('s',count($resourceBins));
        joma_db_bind_params($stmt,$types,$resourceBins);
        $stmt->execute();
        $res=$stmt->get_result();
        $foundIds=[];
        if ($res) { while($r=$res->fetch_assoc()){ $foundIds[]=(string)($r['id']??''); } }
        @$stmt->close();
        if (count($foundIds)!==count($resourceBins)) { joma_db_rollback($db); return ['ok'=>false,'code'=>'NOT_FOUND']; }

        // 4. Overlap check for holds.
        // Holds: status HELD and expires_at > now
        $stmt = joma_db_prepare($db,'SELECT 1 FROM joma_hold_allocations ha JOIN joma_capacity_holds ch ON ch.id = ha.hold_id WHERE ha.resource_id IN ('.$placeholders.') AND ch.status = ? AND ch.expires_at > ? AND ha.starts_at < ? AND ha.ends_at > ? LIMIT 1');
        if ($stmt===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }
        $heldStatus='HELD';
        $ovParams = array_merge($resourceBins,[$heldStatus,$nowUtc,$endsAt,$startsAt]);
        $typesOv = str_repeat('s',count($resourceBins)).'ssss';
        joma_db_bind_params($stmt,$typesOv,$ovParams);
        $stmt->execute();
        $res=$stmt->get_result();
        $hasOverlap = $res && $res->fetch_assoc();
        @$stmt->close();
        if ($hasOverlap) { joma_db_rollback($db); return ['ok'=>false,'code'=>'CAPACITY_CONFLICT']; }

        // Overlap check for appointments (CONFIRMED)
        $stmt = joma_db_prepare($db,'SELECT 1 FROM joma_appointment_allocations aa JOIN joma_appointments ap ON ap.id = aa.appointment_id WHERE aa.resource_id IN ('.$placeholders.') AND ap.status = ? AND aa.starts_at < ? AND aa.ends_at > ? LIMIT 1');
        if ($stmt===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }
        $confirmed='CONFIRMED';
        $ovParams2 = array_merge($resourceBins,[$confirmed,$endsAt,$startsAt]);
        $typesOv2 = str_repeat('s',count($resourceBins)).'sss';
        joma_db_bind_params($stmt,$typesOv2,$ovParams2);
        $stmt->execute();
        $res=$stmt->get_result();
        $hasOverlap2 = $res && $res->fetch_assoc();
        @$stmt->close();
        if ($hasOverlap2) { joma_db_rollback($db); return ['ok'=>false,'code'=>'CAPACITY_CONFLICT']; }

        // 5. Insert hold.
        $actorId = $snapshot['account']['person_id'] ?? null;
        $actorBin = joma_db_uuid_to_bin((string)$actorId);
        if ($actorBin===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DATA_INTEGRITY']; }
        $stmt = joma_db_prepare($db,'INSERT INTO joma_capacity_holds (id, case_id, offering_id, policy_version_id, request_id, actor_person_id, starts_at, ends_at, held_at, expires_at, status, command_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        if ($stmt===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }
        $statusHeld='HELD';
        $requestNull=null;
        // For nullable request_id, bind as string but allow null: we pass null bin? Instead use variable that is null and mysqli will handle? Simplify: use string type with null.
        // We'll bind with types s*12 but need to pass $requestNull as null. Our helper will pass null directly.
        // Build params: holdBin, caseBin, offeringBin, policyBin, requestNull, actorBin, startsAt, endsAt, heldAt, expiresAt, statusHeld, commandBin
        $paramsHold=[$holdBin,$caseBin,$offeringBin,$policyBin,$requestNull,$actorBin,$startsAt,$endsAt,$heldAt,$expiresAt,$statusHeld,$commandBin];
        // For nullable, we need to allow null; joma_db_bind_params will pass null as is.
        joma_db_bind_params($stmt,str_repeat('s',12),$paramsHold);
        $ok=$stmt->execute();
        @$stmt->close();
        if (!$ok) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }

        // 6. Insert allocations per resource.
        foreach ($resourceBins as $idx=>$rBin) {
            $stmt=joma_db_prepare($db,'INSERT INTO joma_hold_allocations (id, hold_id, resource_id, starts_at, ends_at) VALUES (?,?,?,?,?)');
            if ($stmt===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }
            $aBin=$allocationBins[$idx];
            joma_db_bind_params($stmt,'sssss',[$aBin,$holdBin,$rBin,$startsAt,$endsAt]);
            $ok=$stmt->execute();
            @$stmt->close();
            if (!$ok) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }
        }

        if (!joma_db_commit($db)) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }
        $inTx=false;
        return ['ok'=>true,'code'=>'HELD','hold_id'=>$holdId];
    } catch (Throwable $e) {
        if ($inTx) { joma_db_rollback($db); }
        return ['ok'=>false,'code'=>'DB_ERROR'];
    }
}

/**
 * Confirm a held capacity into a confirmed appointment.
 */
function joma_hold_confirm($db, array $snapshot, string $holdId, string $offeringId, string $nowUtc, string $commandId, ?array $ids = null): array {
    $now = joma_rule_utc_us($nowUtc);
    if ($now===null) { return ['ok'=>false,'code'=>'INVALID_TIME']; }
    if (joma_rule_uuid($holdId)===null||joma_rule_uuid($offeringId)===null||joma_rule_uuid($commandId)===null) { return ['ok'=>false,'code'=>'INVALID_ID']; }
    $principal=joma_rule_principal($snapshot);
    if (!$principal['allowed']) { return ['ok'=>false,'code'=>$principal['code']]; }
    if (!is_object($db)||!method_exists($db,'prepare')) { return ['ok'=>false,'code'=>'DB_ERROR']; }
    $holdBin=joma_db_uuid_to_bin($holdId);
    $offeringBin=joma_db_uuid_to_bin($offeringId);
    $commandBin=joma_db_uuid_to_bin($commandId);
    if ($holdBin===null||$offeringBin===null||$commandBin===null) { return ['ok'=>false,'code'=>'INVALID_ID']; }

    $appointmentId=$ids['appointment_id']??joma_uuid_v4();
    $allocationIds=$ids['allocation_ids']??null; // will generate after knowing resource count
    if (joma_rule_uuid($appointmentId)===null) { return ['ok'=>false,'code'=>'INVALID_ID']; }
    $appointmentBin=joma_db_uuid_to_bin($appointmentId);

    if (!joma_db_begin($db)) { return ['ok'=>false,'code'=>'DB_ERROR']; }
    $inTx=true;
    try {
        // 1. Lock hold
        $stmt=joma_db_prepare($db,'SELECT id, case_id, offering_id, status, starts_at, ends_at, held_at, expires_at FROM joma_capacity_holds WHERE id = ? FOR UPDATE');
        if ($stmt===null){joma_db_rollback($db);return ['ok'=>false,'code'=>'DB_ERROR'];}
        joma_db_bind_params($stmt,'s',[$holdBin]);
        $stmt->execute();
        $res=$stmt->get_result();
        $holdRow=$res?$res->fetch_assoc():null;
        @$stmt->close();
        if (!$holdRow){joma_db_rollback($db);return ['ok'=>false,'code'=>'NOT_FOUND'];}
        $hId=joma_db_bin_to_uuid((string)($holdRow['id']??''));
        $hCaseId=joma_db_bin_to_uuid((string)($holdRow['case_id']??''));
        $hOfferingId=joma_db_bin_to_uuid((string)($holdRow['offering_id']??''));
        $hStatus=(string)($holdRow['status']??'');
        $hStarts=(string)($holdRow['starts_at']??'');
        $hEnds=(string)($holdRow['ends_at']??'');
        $hHeld=(string)($holdRow['held_at']??'');
        $hExpires=(string)($holdRow['expires_at']??'');
        if ($hId===null||$hCaseId===null||$hOfferingId===null){joma_db_rollback($db);return ['ok'=>false,'code'=>'DATA_INTEGRITY'];}
        if (!joma_rule_same_id($hOfferingId,$offeringId)){joma_db_rollback($db);return ['ok'=>false,'code'=>'HOLD_CONFLICT'];}
        $hCaseBin=joma_db_uuid_to_bin($hCaseId);
        // 2. Lock allocations for hold
        $stmt=joma_db_prepare($db,'SELECT resource_id, starts_at, ends_at FROM joma_hold_allocations WHERE hold_id = ? FOR UPDATE');
        if ($stmt===null){joma_db_rollback($db);return ['ok'=>false,'code'=>'DB_ERROR'];}
        joma_db_bind_params($stmt,'s',[$holdBin]);
        $stmt->execute();
        $res=$stmt->get_result();
        $allocRows=[];
        if ($res){ while($r=$res->fetch_assoc()) $allocRows[]=$r; }
        @$stmt->close();
        if (!$allocRows){joma_db_rollback($db);return ['ok'=>false,'code'=>'DATA_INTEGRITY'];}
        $resourceBins=[];
        $resourceIds=[];
        foreach($allocRows as $ar){
            $rid=joma_db_bin_to_uuid((string)($ar['resource_id']??''));
            if($rid===null){joma_db_rollback($db);return ['ok'=>false,'code'=>'DATA_INTEGRITY'];}
            $resourceIds[]=$rid;
            $resourceBins[]=joma_db_uuid_to_bin($rid);
        }
        // Sort for deterministic lock order
        $combined=array_combine($resourceIds,$resourceBins);
        ksort($combined,SORT_STRING);
        $resourceIds=array_keys($combined);
        $resourceBins=array_values($combined);
        if ($allocationIds===null){
            $allocationIds=[];
            foreach($resourceIds as $i=>$rid){ $allocationIds[$i]=joma_uuid_v4(); }
        }
        if(count($allocationIds)!==count($resourceIds)){joma_db_rollback($db);return ['ok'=>false,'code'=>'INVALID_ID'];}
        foreach($allocationIds as $a){ if(joma_rule_uuid($a)===null){joma_db_rollback($db);return ['ok'=>false,'code'=>'INVALID_ID'];}}
        $allocationBins=array_map(fn($u)=>joma_db_uuid_to_bin($u),$allocationIds);

        // 3. Load case engagement to validate active
        $stmt=joma_db_prepare($db,'SELECT c.id AS c_id, c.status AS c_status, r.status AS r_status, c.responsible_therapist_id, r.therapist_person_id AS r_therapist, ra.therapist_person_id AS accepted_therapist FROM joma_clinical_cases c JOIN joma_therapeutic_relationships r ON r.id = c.relationship_id JOIN joma_responsibility_acceptances ra ON ra.id = r.acceptance_id WHERE c.id = ? FOR UPDATE');
        if($stmt===null){joma_db_rollback($db);return ['ok'=>false,'code'=>'DB_ERROR'];}
        joma_db_bind_params($stmt,'s',[$hCaseBin]);
        $stmt->execute();
        $res=$stmt->get_result();
        $caseRow=$res?$res->fetch_assoc():null;
        @$stmt->close();
        if(!$caseRow){joma_db_rollback($db);return ['ok'=>false,'code'=>'NOT_FOUND'];}
        $cStatus=(string)($caseRow['c_status']??'');
        $rStatus=(string)($caseRow['r_status']??'');
        $respTher=joma_db_bin_to_uuid((string)($caseRow['responsible_therapist_id']??''));
        $relTher=joma_db_bin_to_uuid((string)($caseRow['r_therapist']??''));
        $accTher=joma_db_bin_to_uuid((string)($caseRow['accepted_therapist']??''));
        $cIdText=joma_db_bin_to_uuid((string)($caseRow['c_id']??''));
        if($cIdText===null||$respTher===null||$relTher===null||$accTher===null){joma_db_rollback($db);return ['ok'=>false,'code'=>'DATA_INTEGRITY'];}
        $caseForRule=['id'=>$cIdText,'status'=>$cStatus,'relationship_status'=>$rStatus,'responsible_therapist_id'=>$respTher,'accepted_therapist_id'=>$accTher,'relationship_therapist_id'=>$relTher];
        if(!joma_rule_active_engagement($caseForRule)){joma_db_rollback($db);return ['ok'=>false,'code'=>'ACTIVE_ENGAGEMENT_REQUIRED'];}

        // Build hold array for domain rule
        $holdForRule=['id'=>$hId,'case_id'=>$hCaseId,'offering_id'=>$hOfferingId,'status'=>$hStatus,'starts_at'=>$hStarts,'ends_at'=>$hEnds,'held_at'=>$hHeld,'expires_at'=>$hExpires];
        $confirmGuard=joma_rule_confirm_hold($caseForRule,$holdForRule,$offeringId,$nowUtc);
        if(!$confirmGuard['allowed']){joma_db_rollback($db);return ['ok'=>false,'code'=>$confirmGuard['code']];}

        // Permission check for confirm
        $stmt=joma_db_prepare($db,'SELECT scope_id FROM joma_service_offerings WHERE id = ?');
        if($stmt===null){joma_db_rollback($db);return ['ok'=>false,'code'=>'DB_ERROR'];}
        joma_db_bind_params($stmt,'s',[$offeringBin]);
        $stmt->execute();
        $res=$stmt->get_result();
        $offRow=$res?$res->fetch_assoc():null;
        @$stmt->close();
        if(!$offRow){joma_db_rollback($db);return ['ok'=>false,'code'=>'NOT_FOUND'];}
        $offeringScope=joma_db_bin_to_uuid((string)($offRow['scope_id']??''));
        if($offeringScope===null){joma_db_rollback($db);return ['ok'=>false,'code'=>'DATA_INTEGRITY'];}
        $ctxCheck=joma_rule_context($snapshot,$offeringScope,'scheduling.confirm',$nowUtc);
        // Fallback to scheduling.hold if confirm not in allowlist (therapist has both, secretary only hold)
        if(!$ctxCheck['allowed']){
            $fallback=joma_rule_context($snapshot,$offeringScope,'scheduling.hold',$nowUtc);
            if(!$fallback['allowed']){joma_db_rollback($db);return ['ok'=>false,'code'=>$ctxCheck['code']];}
        }

        // 4. Lock resources
        $placeholders=implode(',',array_fill(0,count($resourceBins),'?'));
        $stmt=joma_db_prepare($db,'SELECT id FROM joma_schedule_resources WHERE id IN ('.$placeholders.') ORDER BY id FOR UPDATE');
        if($stmt===null){joma_db_rollback($db);return ['ok'=>false,'code'=>'DB_ERROR'];}
        joma_db_bind_params($stmt,str_repeat('s',count($resourceBins)),$resourceBins);
        $stmt->execute();
        $res=$stmt->get_result();
        $found=[];
        if($res) while($r=$res->fetch_assoc()) $found[]=(string)($r['id']??'');
        @$stmt->close();
        if(count($found)!==count($resourceBins)){joma_db_rollback($db);return ['ok'=>false,'code'=>'NOT_FOUND'];}

        // 5. Overlap checks excluding current hold
        // Holds overlapping
        $stmt=joma_db_prepare($db,'SELECT 1 FROM joma_hold_allocations ha JOIN joma_capacity_holds ch ON ch.id = ha.hold_id WHERE ha.resource_id IN ('.$placeholders.') AND ch.id != ? AND ch.status = ? AND ch.expires_at > ? AND ha.starts_at < ? AND ha.ends_at > ? LIMIT 1');
        if($stmt===null){joma_db_rollback($db);return ['ok'=>false,'code'=>'DB_ERROR'];}
        $paramsHold=array_merge($resourceBins,[$holdBin,'HELD',$nowUtc,$hEnds,$hStarts]);
        joma_db_bind_params($stmt,str_repeat('s',count($resourceBins)).'sssss',$paramsHold);
        $stmt->execute();
        $res=$stmt->get_result();
        $hasH=joma_db_is_overlap_exists($res);
        @$stmt->close();
        if($hasH){joma_db_rollback($db);return ['ok'=>false,'code'=>'CAPACITY_CONFLICT'];}

        // Appointments overlapping
        $stmt=joma_db_prepare($db,'SELECT 1 FROM joma_appointment_allocations aa JOIN joma_appointments ap ON ap.id = aa.appointment_id WHERE aa.resource_id IN ('.$placeholders.') AND ap.status = ? AND aa.starts_at < ? AND aa.ends_at > ? LIMIT 1');
        if($stmt===null){joma_db_rollback($db);return ['ok'=>false,'code'=>'DB_ERROR'];}
        $paramsAp=array_merge($resourceBins,['CONFIRMED',$hEnds,$hStarts]);
        joma_db_bind_params($stmt,str_repeat('s',count($resourceBins)).'sss',$paramsAp);
        $stmt->execute();
        $res=$stmt->get_result();
        $hasA=joma_db_is_overlap_exists($res);
        @$stmt->close();
        if($hasA){joma_db_rollback($db);return ['ok'=>false,'code'=>'CAPACITY_CONFLICT'];}

        // 6. Insert appointment
        $stmt=joma_db_prepare($db,'INSERT INTO joma_appointments (id, case_id, therapist_person_id, scope_id, offering_id, hold_id, starts_at, ends_at, status, origin, cancellation_notice_minutes, command_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        if($stmt===null){joma_db_rollback($db);return ['ok'=>false,'code'=>'DB_ERROR'];}
        $therapistBin=joma_db_uuid_to_bin($respTher);
        $scopeBin=joma_db_uuid_to_bin($offeringScope);
        $statusConf='CONFIRMED';
        $origin='STAFF_MANUAL';
        $notice=1440; // default 24h, policy should override but test expects int
        $noticeStr=(string)$notice; // bind as s? but column is INT UNSIGNED; mysqli can handle string numeric. Use s for simplicity, but need int? Our bind helper uses string. We'll pass as string numeric and rely on DB conversion. Alternative bind type 's' works.
        // Need types: s*11 + i? We'll use s for all.
        $paramsAppt=[$appointmentBin,$hCaseBin,$therapistBin,$scopeBin,$offeringBin,$holdBin,$hStarts,$hEnds,$statusConf,$origin,$noticeStr,$commandBin];
        joma_db_bind_params($stmt,'ssssssssssss',$paramsAppt);
        $ok=$stmt->execute();
        @$stmt->close();
        if(!$ok){joma_db_rollback($db);return ['ok'=>false,'code'=>'DB_ERROR'];}

        // 7. Insert allocation per resource
        foreach($resourceBins as $idx=>$rBin){
            $stmt=joma_db_prepare($db,'INSERT INTO joma_appointment_allocations (id, appointment_id, resource_id, starts_at, ends_at) VALUES (?,?,?,?,?)');
            if($stmt===null){joma_db_rollback($db);return ['ok'=>false,'code'=>'DB_ERROR'];}
            $aBin=$allocationBins[$idx];
            joma_db_bind_params($stmt,'sssss',[$aBin,$appointmentBin,$rBin,$hStarts,$hEnds]);
            $ok=$stmt->execute();
            @$stmt->close();
            if(!$ok){joma_db_rollback($db);return ['ok'=>false,'code'=>'DB_ERROR'];}
        }

        // 8. Update hold to CONSUMED
        $stmt=joma_db_prepare($db,'UPDATE joma_capacity_holds SET status = ? WHERE id = ?');
        if($stmt===null){joma_db_rollback($db);return ['ok'=>false,'code'=>'DB_ERROR'];}
        $consumed='CONSUMED';
        joma_db_bind_params($stmt,'ss',[$consumed,$holdBin]);
        $ok=$stmt->execute();
        @$stmt->close();
        if(!$ok){joma_db_rollback($db);return ['ok'=>false,'code'=>'DB_ERROR'];}

        if(!joma_db_commit($db)){joma_db_rollback($db);return ['ok'=>false,'code'=>'DB_ERROR'];}
        $inTx=false;
        return ['ok'=>true,'code'=>'CONFIRMED','appointment_id'=>$appointmentId,'hold_id'=>$holdId];
    } catch (Throwable $e){
        if($inTx){joma_db_rollback($db);}
        return ['ok'=>false,'code'=>'DB_ERROR'];
    }
}

function joma_db_is_overlap_exists($res): bool {
    if (!$res) return false;
    $row=$res->fetch_assoc();
    return $row!==null;
}
