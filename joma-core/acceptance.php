<?php
declare(strict_types=1);

/**
 * JOMA acceptance transaction — atomic creation of Relationship + Case.
 * Requires mysqli with InnoDB. No ORM, no PDO.
 * All IDs are BINARY(16) random UUIDv4 in DB, textual lower-case in PHP.
 * Contract: one transaction, row locks, rollback on any failure.
 */
require_once __DIR__ . '/domain_rules.php';
require_once __DIR__ . '/db.php';

function joma_acceptance_validate_purpose(string $raw): ?string {
    // Trim and check length 1..500 characters (Unicode aware), reject NUL/control except newline/tab.
    if (strpos($raw, "\0") !== false) { return null; }
    $trim = trim($raw);
    if ($trim === '') { return null; }
    // Count Unicode characters, fallback to bytes if mbstring unavailable.
    $len = function_exists('mb_strlen') ? mb_strlen($trim, 'UTF-8') : strlen($trim);
    if ($len < 1 || $len > 500) { return null; }
    // Reject other control chars (except \n \r \t)
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $trim)) { return null; }
    return $trim;
}

// Transaction helpers are in db.php (joma_db_begin/commit/rollback)

/**
 * Execute acceptance atomically.
 *
 * @param $db mysqli or mock supporting prepare/begin_transaction/commit/rollback
 * @param array $snapshot trusted snapshot from joma_context_load
 * @param string $assignmentId textual UUID of therapist_assignments.id
 * @param string $purposeSummary 1..500 chars, Persian allowed
 * @param string $nowUtc Y-m-d H:i:s.u UTC
 * @param string $commandId textual UUID for command_receipts.id (caller must have inserted receipt as PROCESSING)
 * @param array|null $ids optional deterministic ids for testing: keys acceptance_id, relationship_id, case_id, participant_id, context_id
 * @return array ['ok'=>bool,'code'=>string,'acceptance_id'=>...,'relationship_id'=>...,'case_id'=>...]
 */
function joma_acceptance_execute($db, array $snapshot, string $assignmentId, string $purposeSummary, string $nowUtc, string $commandId, ?array $ids = null): array {
    $now = joma_rule_utc_us($nowUtc);
    if ($now === null) { return ['ok'=>false,'code'=>'INVALID_TIME']; }
    if (joma_rule_uuid($assignmentId) === null || joma_rule_uuid($commandId) === null) {
        return ['ok'=>false,'code'=>'INVALID_ID'];
    }
    $purpose = joma_acceptance_validate_purpose($purposeSummary);
    if ($purpose === null) { return ['ok'=>false,'code'=>'INVALID_PURPOSE']; }
    // Snapshot must have principal.
    $principal = joma_rule_principal($snapshot);
    if (!$principal['allowed']) { return ['ok'=>false,'code'=>$principal['code']]; }
    // We will validate assignment ownership via domain rule after locking, but quick check for snapshot structure.
    if (!isset($snapshot['role_assignment']['scope_id'])) { return ['ok'=>false,'code'=>'CONTEXT_DENIED']; }

    $assignmentBin = joma_db_uuid_to_bin($assignmentId);
    $commandBin = joma_db_uuid_to_bin($commandId);
    if ($assignmentBin === null || $commandBin === null) { return ['ok'=>false,'code'=>'INVALID_ID']; }
    if (!is_object($db) || !method_exists($db,'prepare')) { return ['ok'=>false,'code'=>'DB_ERROR']; }

    // Generate ids if not provided.
    $acceptanceId = $ids['acceptance_id'] ?? joma_uuid_v4();
    $relationshipId = $ids['relationship_id'] ?? joma_uuid_v4();
    $caseId = $ids['case_id'] ?? joma_uuid_v4();
    $participantId = $ids['participant_id'] ?? joma_uuid_v4();
    $contextId = $ids['context_id'] ?? joma_uuid_v4();
    foreach ([$acceptanceId,$relationshipId,$caseId,$participantId,$contextId] as $u) {
        if (joma_rule_uuid($u) === null) { return ['ok'=>false,'code'=>'INVALID_ID']; }
    }
    $acceptanceBin = joma_db_uuid_to_bin($acceptanceId);
    $relationshipBin = joma_db_uuid_to_bin($relationshipId);
    $caseBin = joma_db_uuid_to_bin($caseId);
    $participantBin = joma_db_uuid_to_bin($participantId);
    $contextBin = joma_db_uuid_to_bin($contextId);
    if ($acceptanceBin===null||$relationshipBin===null||$caseBin===null||$participantBin===null||$contextBin===null) {
        return ['ok'=>false,'code'=>'INVALID_ID'];
    }

    if (!joma_db_begin($db)) { return ['ok'=>false,'code'=>'DB_ERROR']; }
    $inTransaction = true;
    try {
        // 1. Lock therapist_assignments row.
        $stmt = joma_db_prepare($db, 'SELECT id, admission_id, therapist_person_id, status FROM joma_therapist_assignments WHERE id = ? FOR UPDATE');
        if ($stmt===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }
        $stmt->bind_param('s', $assignmentBin);
        $stmt->execute();
        $res = $stmt->get_result();
        $assignRow = $res ? $res->fetch_assoc() : null;
        @$stmt->close();
        if (!$assignRow) { joma_db_rollback($db); return ['ok'=>false,'code'=>'NOT_FOUND']; }
        $rowAssignId = joma_db_bin_to_uuid((string) ($assignRow['id'] ?? ''));
        $admissionIdFromAssign = joma_db_bin_to_uuid((string) ($assignRow['admission_id'] ?? ''));
        $therapistPersonId = joma_db_bin_to_uuid((string) ($assignRow['therapist_person_id'] ?? ''));
        $assignStatus = (string) ($assignRow['status'] ?? '');
        if ($rowAssignId===null||$admissionIdFromAssign===null||$therapistPersonId===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DATA_INTEGRITY']; }
        $admissionBin = joma_db_uuid_to_bin($admissionIdFromAssign);
        if ($admissionBin===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DATA_INTEGRITY']; }

        // 2. Lock admissions row (fixed order: assignment then admission consistently).
        $stmt = joma_db_prepare($db, 'SELECT id, primary_subject_id, scope_id, status, case_id FROM joma_admissions WHERE id = ? FOR UPDATE');
        if ($stmt===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }
        $stmt->bind_param('s', $admissionBin);
        $stmt->execute();
        $res = $stmt->get_result();
        $admissionRow = $res ? $res->fetch_assoc() : null;
        @$stmt->close();
        if (!$admissionRow) { joma_db_rollback($db); return ['ok'=>false,'code'=>'NOT_FOUND']; }
        $admissionIdText = joma_db_bin_to_uuid((string) ($admissionRow['id'] ?? ''));
        $primarySubjectId = joma_db_bin_to_uuid((string) ($admissionRow['primary_subject_id'] ?? ''));
        $admissionScopeId = joma_db_bin_to_uuid((string) ($admissionRow['scope_id'] ?? ''));
        $admissionStatus = (string) ($admissionRow['status'] ?? '');
        $admissionCaseRaw = $admissionRow['case_id'] ?? null;
        $admissionCaseId = null;
        if ($admissionCaseRaw !== null && $admissionCaseRaw !== '') {
            $admissionCaseId = joma_db_bin_to_uuid((string) $admissionCaseRaw);
            if ($admissionCaseId===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DATA_INTEGRITY']; }
        }
        if ($admissionIdText===null||$primarySubjectId===null||$admissionScopeId===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DATA_INTEGRITY']; }

        // Build assignment snapshot for domain rule.
        $assignmentForRule = [
            'id' => $rowAssignId,
            'admission_id' => $admissionIdText,
            'scope_id' => $admissionScopeId,
            'therapist_person_id' => $therapistPersonId,
            'status' => $assignStatus,
            'admission_status' => $admissionStatus,
            'linked_case_id' => $admissionCaseId,
        ];
        // Domain guard — checks permission, assigned therapist, state.
        $guard = joma_rule_accept_assignment($snapshot, $assignmentForRule, $nowUtc);
        if (!$guard['allowed']) { joma_db_rollback($db); return ['ok'=>false,'code'=>$guard['code']]; }

        // Derive therapist binary and actor binary (must be same per CHECK).
        $therapistBin = joma_db_uuid_to_bin($therapistPersonId);
        $actorPersonId = $snapshot['account']['person_id'] ?? null;
        $actorBin = joma_db_uuid_to_bin((string) $actorPersonId);
        if ($therapistBin===null||$actorBin===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DATA_INTEGRITY']; }
        // Strict: actor must equal therapist (domain rule already, but DB CHECK enforces).
        if (!hash_equals($therapistBin, $actorBin)) { joma_db_rollback($db); return ['ok'=>false,'code'=>'ASSIGNED_THERAPIST_REQUIRED']; }

        $scopeBin = joma_db_uuid_to_bin($admissionScopeId);
        $subjectBin = joma_db_uuid_to_bin($primarySubjectId);
        if ($scopeBin===null||$subjectBin===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DATA_INTEGRITY']; }

        // 3. Insert responsibility_acceptances
        $stmt = joma_db_prepare($db, 'INSERT INTO joma_responsibility_acceptances (id, admission_id, assignment_id, therapist_person_id, actor_person_id, command_id, accepted_at) VALUES (?,?,?,?,?,?,?)');
        if ($stmt===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }
        $stmt->bind_param('sssssss', $acceptanceBin, $admissionBin, $assignmentBin, $therapistBin, $actorBin, $commandBin, $nowUtc);
        $ok = $stmt->execute();
        @$stmt->close();
        if (!$ok) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }

        // 4. Insert therapeutic_relationships
        $stmt = joma_db_prepare($db, 'INSERT INTO joma_therapeutic_relationships (id, acceptance_id, therapist_person_id, status, activated_at) VALUES (?,?,?,?,?)');
        if ($stmt===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }
        $active = 'ACTIVE';
        $stmt->bind_param('sssss', $relationshipBin, $acceptanceBin, $therapistBin, $active, $nowUtc);
        $ok = $stmt->execute();
        @$stmt->close();
        if (!$ok) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }

        // 5. Insert clinical_cases
        $stmt = joma_db_prepare($db, 'INSERT INTO joma_clinical_cases (id, relationship_id, responsible_therapist_id, purpose_summary, status, opened_at) VALUES (?,?,?,?,?,?)');
        if ($stmt===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }
        $caseActive = 'ACTIVE';
        $stmt->bind_param('ssssss', $caseBin, $relationshipBin, $therapistBin, $purpose, $caseActive, $nowUtc);
        $ok = $stmt->execute();
        @$stmt->close();
        if (!$ok) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }

        // 6. Insert case_participant for primary subject (clinical_role PRIMARY)
        $stmt = joma_db_prepare($db, 'INSERT INTO joma_case_participants (id, case_id, person_id, clinical_role, valid_from) VALUES (?,?,?,?,?)');
        if ($stmt===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }
        $rolePrimary = 'PRIMARY';
        $stmt->bind_param('sssss', $participantBin, $caseBin, $subjectBin, $rolePrimary, $nowUtc);
        $ok = $stmt->execute();
        @$stmt->close();
        if (!$ok) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }

        // 7. Insert case_operational_contexts
        $stmt = joma_db_prepare($db, 'INSERT INTO joma_case_operational_contexts (id, case_id, scope_id, valid_from, basis_reference) VALUES (?,?,?,?,?)');
        if ($stmt===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }
        $basis = 'acceptance:' . $acceptanceId;
        $stmt->bind_param('sssss', $contextBin, $caseBin, $scopeBin, $nowUtc, $basis);
        $ok = $stmt->execute();
        @$stmt->close();
        if (!$ok) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }

        // 8. Update assignment to ACCEPTED
        $stmt = joma_db_prepare($db, 'UPDATE joma_therapist_assignments SET status = ? WHERE id = ?');
        if ($stmt===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }
        $accepted = 'ACCEPTED';
        $stmt->bind_param('ss', $accepted, $assignmentBin);
        $ok = $stmt->execute();
        @$stmt->close();
        if (!$ok) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }

        // 9. Update admission to LINKED_TO_CASE
        $stmt = joma_db_prepare($db, 'UPDATE joma_admissions SET status = ?, case_id = ? WHERE id = ?');
        if ($stmt===null) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }
        $linked = 'LINKED_TO_CASE';
        $stmt->bind_param('sss', $linked, $caseBin, $admissionBin);
        $ok = $stmt->execute();
        @$stmt->close();
        if (!$ok) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }

        // Commit
        if (!joma_db_commit($db)) { joma_db_rollback($db); return ['ok'=>false,'code'=>'DB_ERROR']; }
        $inTransaction = false;
        return ['ok'=>true,'code'=>'ACCEPTED','acceptance_id'=>$acceptanceId,'relationship_id'=>$relationshipId,'case_id'=>$caseId];
    } catch (Throwable $e) {
        if ($inTransaction) { joma_db_rollback($db); }
        return ['ok'=>false,'code'=>'DB_ERROR'];
    }
}
