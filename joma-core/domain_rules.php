<?php
declare(strict_types=1);

/**
 * JOMA domain-rule reference implementation. No HTTP entry point, DB writes,
 * session authentication, ciphertext decryption or complete authorization service.
 * All snapshots MUST be loaded by trusted server adapters, never accepted from
 * request JSON. ALLOW here only passes THIS rule; transaction and access checks
 * described in the service contracts remain mandatory.
 */
function joma_rule_result(bool $allowed, string $code): array {
    return ['allowed' => $allowed, 'code' => $code];
}
function joma_rule_uuid($value): ?string {
    if (!is_string($value) || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $value)) {
        return null;
    }
    return strtolower($value);
}
function joma_rule_same_id($a, $b): bool {
    $id = joma_rule_uuid($a);
    return $id !== null && $id === joma_rule_uuid($b);
}
function joma_uuid_v4(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
    $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
    $h = bin2hex($bytes);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
}
function joma_rule_utc_us($value): ?int {
    if (PHP_INT_SIZE < 8 || !is_string($value) ||
        !preg_match('/\A[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}\z/', $value)) { return null; }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) ||
        $date->format('Y-m-d H:i:s.u') !== $value) { return null; }
    return ((int) $date->format('U')) * 1000000 + (int) $date->format('u');
}
function joma_rule_window(array $row, int $now): bool {
    foreach (['valid_from','valid_until','revoked_at'] as $key) {
        if (!array_key_exists($key, $row)) { return false; }
    }
    if ($row['revoked_at'] !== null) { return false; }
    $start = $row['valid_from'] === null ? null : joma_rule_utc_us($row['valid_from']);
    $end = $row['valid_until'] === null ? null : joma_rule_utc_us($row['valid_until']);
    if (($row['valid_from'] !== null && $start === null) || ($row['valid_until'] !== null && $end === null)) { return false; }
    if ($start !== null && $end !== null && $end <= $start) { return false; }
    return ($start === null || $now >= $start) && ($end === null || $now < $end);
}
function joma_rule_principal(array $snapshot): array {
    $account = $snapshot['account'] ?? null;
    if (!is_array($account) || ($account['status'] ?? null) !== 'ACTIVE' ||
        joma_rule_uuid($account['id'] ?? null) === null || joma_rule_uuid($account['person_id'] ?? null) === null) {
        return joma_rule_result(false, 'AUTHENTICATION_REQUIRED');
    }
    return joma_rule_result(true, 'PRINCIPAL_VALID');
}
function joma_rule_context(array $snapshot, string $scopeId, string $permission, string $nowUtc): array {
    $principal = joma_rule_principal($snapshot);
    if (!$principal['allowed']) { return $principal; }
    $now = joma_rule_utc_us($nowUtc);
    $membership = $snapshot['membership'] ?? null;
    $role = $snapshot['role_assignment'] ?? null;
    if ($now === null || !is_array($membership) || !is_array($role) ||
        ($membership['status'] ?? null) !== 'ACTIVE' || joma_rule_uuid($role['id'] ?? null) === null ||
        !joma_rule_same_id($membership['person_id'] ?? null, $snapshot['account']['person_id']) ||
        !joma_rule_same_id($membership['scope_id'] ?? null, $scopeId) ||
        !joma_rule_same_id($role['account_id'] ?? null, $snapshot['account']['id']) ||
        !joma_rule_same_id($role['person_id'] ?? null, $snapshot['account']['person_id']) ||
        !joma_rule_same_id($role['membership_id'] ?? null, $membership['id'] ?? null) ||
        !joma_rule_same_id($role['scope_id'] ?? null, $scopeId) ||
        !joma_rule_window($membership, $now) || !joma_rule_window($role, $now)) {
        return joma_rule_result(false, 'CONTEXT_DENIED');
    }
    // This allowlist belongs to the selected role/policy, not all roles on the account.
    if (($snapshot['policy_status'] ?? null) !== 'APPROVED' ||
        !is_array($snapshot['permissions'] ?? null) ||
        !in_array($permission, $snapshot['permissions'], true)) {
        return joma_rule_result(false, 'PERMISSION_DENIED');
    }
    return joma_rule_result(true, 'CONTEXT_ALLOWED');
}
function joma_rule_accept_assignment(array $snapshot, array $assignment, string $nowUtc): array {
    $scope = $assignment['scope_id'] ?? null;
    if (joma_rule_uuid($scope) === null) { return joma_rule_result(false, 'INVALID_ASSIGNMENT'); }
    $context = joma_rule_context($snapshot, $scope, 'routing.accept', $nowUtc);
    if (!$context['allowed']) { return $context; }
    if (!joma_rule_same_id($snapshot['account']['person_id'], $assignment['therapist_person_id'] ?? null)) {
        return joma_rule_result(false, 'ASSIGNED_THERAPIST_REQUIRED');
    }
    if (joma_rule_uuid($assignment['id'] ?? null) === null || joma_rule_uuid($assignment['admission_id'] ?? null) === null ||
        ($assignment['status'] ?? null) !== 'ASSIGNED' || ($assignment['admission_status'] ?? null) !== 'AWAITING_THERAPIST' ||
        !array_key_exists('linked_case_id', $assignment) || $assignment['linked_case_id'] !== null) {
        return joma_rule_result(false, 'STATE_CONFLICT');
    }
    return joma_rule_result(true, 'ACCEPTANCE_GUARD_PASSED');
}
function joma_rule_active_engagement(array $case): bool {
    return joma_rule_uuid($case['id'] ?? null) !== null &&
        ($case['status'] ?? null) === 'ACTIVE' && ($case['relationship_status'] ?? null) === 'ACTIVE' &&
        joma_rule_same_id($case['responsible_therapist_id'] ?? null, $case['accepted_therapist_id'] ?? null) &&
        joma_rule_same_id($case['responsible_therapist_id'] ?? null, $case['relationship_therapist_id'] ?? null);
}
function joma_rule_hold_terms(array $case, string $startsAt, string $endsAt, string $heldAt, string $expiresAt): array {
    if (!joma_rule_active_engagement($case)) { return joma_rule_result(false, 'ACTIVE_ENGAGEMENT_REQUIRED'); }
    $start = joma_rule_utc_us($startsAt); $end = joma_rule_utc_us($endsAt);
    $held = joma_rule_utc_us($heldAt); $expires = joma_rule_utc_us($expiresAt);
    if ($start === null || $end === null || $held === null || $expires === null || $end <= $start ||
        $expires <= $held || $expires - $held > 900000000) {
        return joma_rule_result(false, 'INVALID_TIME_WINDOW');
    }
    // Not a capacity decision, permission check, or authorization to extend a hold.
    return joma_rule_result(true, 'HOLD_TERMS_VALID');
}
function joma_rule_intervals_overlap(string $startA, string $endA, string $startB, string $endB): ?bool {
    $a = joma_rule_utc_us($startA); $b = joma_rule_utc_us($endA);
    $c = joma_rule_utc_us($startB); $d = joma_rule_utc_us($endB);
    if ($a === null || $b === null || $c === null || $d === null || $b <= $a || $d <= $c) { return null; }
    return $a < $d && $c < $b;
}
function joma_rule_confirm_hold(array $case, array $hold, string $offeringId, string $nowUtc): array {
    if (!joma_rule_active_engagement($case)) { return joma_rule_result(false, 'ACTIVE_ENGAGEMENT_REQUIRED'); }
    foreach (['starts_at','ends_at','held_at','expires_at'] as $key) {
        if (!is_string($hold[$key] ?? null)) { return joma_rule_result(false, 'INVALID_HOLD'); }
    }
    $terms = joma_rule_hold_terms($case, $hold['starts_at'], $hold['ends_at'], $hold['held_at'], $hold['expires_at']);
    $now = joma_rule_utc_us($nowUtc);
    if (!$terms['allowed'] || $now === null || ($hold['status'] ?? null) !== 'HELD' ||
        joma_rule_uuid($hold['id'] ?? null) === null || !joma_rule_same_id($hold['case_id'] ?? null, $case['id']) ||
        !joma_rule_same_id($hold['offering_id'] ?? null, $offeringId)) {
        return joma_rule_result(false, 'HOLD_CONFLICT');
    }
    if ($now < joma_rule_utc_us($hold['held_at']) || $now >= joma_rule_utc_us($hold['expires_at'])) {
        return joma_rule_result(false, 'HOLD_EXPIRED_OR_NOT_STARTED');
    }
    return joma_rule_result(true, 'HOLD_GUARD_PASSED');
}
function joma_rule_retry(array $record, string $commandName, string $scopeId, string $actorId, string $payloadHash): array {
    if (!preg_match('/\A[0-9a-f]{64}\z/', $payloadHash) ||
        ($record['command_name'] ?? null) !== $commandName ||
        !joma_rule_same_id($record['scope_id'] ?? null, $scopeId) ||
        !joma_rule_same_id($record['actor_person_id'] ?? null, $actorId) ||
        !is_string($record['payload_hash'] ?? null) || !hash_equals($record['payload_hash'], $payloadHash)) {
        return joma_rule_result(false, 'IDEMPOTENCY_CONFLICT');
    }
    if (($record['status'] ?? null) === 'SUCCEEDED') {
        return joma_rule_result(true, 'REPLAY_REQUIRES_CURRENT_AUTHORIZATION');
    }
    return joma_rule_result(false, ($record['status'] ?? null) === 'PROCESSING' ? 'COMMAND_IN_PROGRESS' : 'COMMAND_NOT_REPLAYABLE');
}
function joma_rule_private_note_author(array $snapshot, array $note): array {
    $principal = joma_rule_principal($snapshot);
    if (!$principal['allowed']) { return $principal; }
    if (($note['kind'] ?? null) !== 'PRIVATE_NOTE' ||
        !joma_rule_same_id($note['author_person_id'] ?? null, $snapshot['account']['person_id'])) {
        return joma_rule_result(false, 'AUTHOR_ONLY');
    }
    // Membership in a former clinic is NOT required to keep one's own history.
    // This predicate provides neither a key nor decrypted content.
    return joma_rule_result(true, 'AUTHOR_MATCH_ONLY');
}
function joma_rule_portal_read(array $snapshot, array $resource, array $publication, array $audience,
    ?array $representation, ?array $restrictions, ?array $entitlement, string $nowUtc): array {
    $principal = joma_rule_principal($snapshot);
    if (!$principal['allowed']) { return $principal; }
    $deny = joma_rule_result(false, 'RESOURCE_NOT_AVAILABLE');
    $now = joma_rule_utc_us($nowUtc);
    if ($now === null || ($resource['policy_status'] ?? null) !== 'APPROVED' ||
        !in_array($resource['kind'] ?? null, ['REPORT','FORM_RESPONSE'], true)) { return $deny; }
    foreach (['id','version_id','case_id','scope_id'] as $key) {
        if (joma_rule_uuid($resource[$key] ?? null) === null) { return $deny; }
    }
    if (!is_array($resource['subject_person_ids'] ?? null) || !$resource['subject_person_ids']) { return $deny; }
    if (($resource['kind'] === 'FORM_RESPONSE' && !in_array($resource['revision_state'] ?? null, ['SUBMITTED','CORRECTION'], true)) ||
        ($resource['kind'] === 'REPORT' && ($resource['revision_state'] ?? null) !== 'REVIEWED')) { return $deny; }
    if (($publication['channel'] ?? null) !== 'PORTAL' ||
        !array_key_exists('revoked_at', $publication) || $publication['revoked_at'] !== null ||
        !joma_rule_same_id($publication['resource_id'] ?? null, $resource['id']) ||
        !joma_rule_same_id($publication['version_id'] ?? null, $resource['version_id']) ||
        !joma_rule_same_id($publication['scope_id'] ?? null, $resource['scope_id']) ||
        !joma_rule_same_id($audience['publication_id'] ?? null, $publication['id'] ?? null) ||
        !joma_rule_same_id($audience['recipient_person_id'] ?? null, $snapshot['account']['person_id']) ||
        !joma_rule_same_id($audience['scope_id'] ?? null, $resource['scope_id']) ||
        !array_key_exists('revoked_at', $audience) || $audience['revoked_at'] !== null) { return $deny; }
    $published = joma_rule_utc_us($publication['published_at'] ?? null);
    if ($published === null || $published > $now) { return $deny; }
    $subject = $audience['access_subject_person_id'] ?? null;
    $basis = $audience['basis_kind'] ?? null;
    if (!array_key_exists('representation_id', $audience)) { return $deny; }
    if ($basis === 'DIRECT_PERSON') {
        if ($audience['representation_id'] !== null || !joma_rule_same_id($subject, $snapshot['account']['person_id'])) { return $deny; }
    } elseif ($basis === 'REPRESENTATIVE') {
        if ($representation === null || $restrictions === null || ($representation['status'] ?? null) !== 'VERIFIED' ||
            !joma_rule_same_id($representation['id'] ?? null, $audience['representation_id']) ||
            !joma_rule_same_id($representation['representative_person_id'] ?? null, $snapshot['account']['person_id']) ||
            !joma_rule_same_id($representation['subject_person_id'] ?? null, $subject) ||
            !joma_rule_same_id($representation['scope_id'] ?? null, $resource['scope_id']) ||
            !joma_rule_window($representation, $now) || !is_array($representation['allowed_actions'] ?? null) ||
            !in_array('document.read', $representation['allowed_actions'], true)) { return $deny; }
        $matchesSubject = false;
        foreach ($resource['subject_person_ids'] as $id) { $matchesSubject = $matchesSubject || joma_rule_same_id($id, $subject); }
        if (!$matchesSubject) { return $deny; }
        if (!array_key_exists('service_id', $resource) || ($resource['service_id'] !== null && (!is_int($resource['service_id']) || $resource['service_id'] <= 0))) { return $deny; }
        foreach ($restrictions as $restriction) {
            if (!is_array($restriction) || !array_key_exists('service_id', $restriction) ||
                ($restriction['service_id'] !== null && (!is_int($restriction['service_id']) || $restriction['service_id'] <= 0)) ||
                ($restriction['status'] ?? null) !== 'SUSPENDED' || joma_rule_uuid($restriction['representation_id'] ?? null) === null ||
                joma_rule_uuid($restriction['case_id'] ?? null) === null) { return $deny; }
            if (joma_rule_same_id($restriction['representation_id'], $representation['id']) && joma_rule_same_id($restriction['case_id'], $resource['case_id'])) {
                // Unknown service context cannot bypass a service-specific restriction.
                if ($restriction['service_id'] === null || $resource['service_id'] === null || $restriction['service_id'] === $resource['service_id']) { return $deny; }
            }
        }
    } else { return $deny; }
    if (!array_key_exists('required_product_id', $resource)) { return $deny; }
    $product = $resource['required_product_id'];
    if ($product !== null) {
        if (!is_int($product) || $product <= 0 || $entitlement === null ||
            ($entitlement['source_verified'] ?? null) !== true || ($entitlement['product_id'] ?? null) !== $product ||
            !joma_rule_same_id($entitlement['beneficiary_person_id'] ?? null, $subject) ||
            !joma_rule_window($entitlement, $now)) { return $deny; }
    }
    return joma_rule_result(true, 'PORTAL_GUARDS_PASSED');
}
