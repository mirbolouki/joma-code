<?php
declare(strict_types=1);

/**
 * JOMA trusted context loader — mysqli, prepared statements only.
 * Loads account + membership + role_assignment via server-side join.
 * Permissions are derived from joma_role_definitions.is_active + allowlist map.
 * All snapshots are server projections; request JSON is never trusted.
 */
require_once __DIR__ . '/domain_rules.php';
require_once __DIR__ . '/db.php';

/**
 * Provisional allowlist map from role code to permissions.
 * REAL mapping must come from reviewed policy table; this is only for tests and bootstrap.
 * Unknown codes yield empty permissions (deny).
 */
function joma_context_role_permissions_map(): array {
    return [
        // normalized lower-case code => permissions
        'therapist' => ['routing.accept', 'scheduling.hold', 'scheduling.confirm'],
        'doctor' => ['routing.accept', 'scheduling.hold', 'scheduling.confirm'],
        'head_secretary' => ['scheduling.hold', 'scheduling.confirm'],
        'secretary' => ['scheduling.hold'],
        'admin' => ['routing.accept', 'scheduling.hold', 'scheduling.confirm', 'report.publish', 'form.publish'],
        'psychometrist' => ['report.upload'],
        'client' => [],
        // Aliases for english/persian seeds:
        'center_admin' => ['routing.accept', 'scheduling.hold', 'scheduling.confirm', 'report.publish', 'form.publish'],
    ];
}

function joma_context_resolve_policy(?string $roleCode = null, $isActive = null): array {
    $code = is_string($roleCode) ? strtolower(trim($roleCode)) : '';
    $active = ($isActive === 1 || $isActive === '1' || $isActive === true);
    if ($code === '' || !$active) {
        // Not configured or revoked role definition.
        return ['policy_status' => 'NOT_APPROVED', 'permissions' => []];
    }
    $map = joma_context_role_permissions_map();
    $perms = $map[$code] ?? [];
    // Empty map is still APPROVED status but no permissions — deny via permission check.
    // We keep APPROVED only if role is active; permissions control actual allow.
    return ['policy_status' => 'APPROVED', 'permissions' => $perms];
}

/**
 * Load trusted snapshot for the selected role_assignment.
 * @param $db mysqli or mock with prepare(string):stmt
 * @param string $accountId textual UUID of logged-in account
 * @param string $roleAssignmentId textual UUID of selected role
 * @param string $nowUtc "Y-m-d H:i:s.u" UTC
 * @return array ['ok'=>bool, 'snapshot'=>array, 'code'=>string]
 */
function joma_context_load($db, string $accountId, string $roleAssignmentId, string $nowUtc): array {
    $now = joma_rule_utc_us($nowUtc);
    if ($now === null) { return ['ok' => false, 'code' => 'INVALID_TIME']; }
    if (joma_rule_uuid($accountId) === null || joma_rule_uuid($roleAssignmentId) === null) {
        return ['ok' => false, 'code' => 'INVALID_ID'];
    }
    $accountBin = joma_db_uuid_to_bin($accountId);
    $roleBin = joma_db_uuid_to_bin($roleAssignmentId);
    if ($accountBin === null || $roleBin === null) {
        return ['ok' => false, 'code' => 'INVALID_ID'];
    }
    if (!is_object($db) || !method_exists($db, 'prepare')) {
        return ['ok' => false, 'code' => 'DB_ERROR'];
    }
    $sql = 'SELECT '
        . 'a.id AS a_id, a.person_id AS a_person_id, a.status AS a_status, '
        . 'm.id AS m_id, m.person_id AS m_person_id, m.scope_id AS m_scope_id, m.status AS m_status, m.valid_from AS m_valid_from, m.valid_until AS m_valid_until, '
        . 'ra.id AS ra_id, ra.account_id AS ra_account_id, ra.person_id AS ra_person_id, ra.membership_id AS ra_membership_id, ra.scope_id AS ra_scope_id, ra.role_id AS ra_role_id, ra.valid_from AS ra_valid_from, ra.valid_until AS ra_valid_until, ra.revoked_at AS ra_revoked_at, '
        . 'rd.code AS rd_code, rd.is_active AS rd_is_active '
        . 'FROM joma_role_assignments ra '
        . 'JOIN joma_accounts a ON a.id = ra.account_id '
        . 'JOIN joma_memberships m ON m.id = ra.membership_id '
        . 'LEFT JOIN joma_role_definitions rd ON rd.id = ra.role_id '
        . 'WHERE ra.id = ? AND a.id = ? LIMIT 1';
    $stmt = joma_db_prepare($db, $sql);
    if ($stmt === null) { return ['ok' => false, 'code' => 'DB_ERROR']; }
    try {
        $stmt->bind_param('ss', $roleBin, $accountBin);
        $stmt->execute();
        $res = $stmt->get_result();
        if (!$res) { return ['ok' => false, 'code' => 'DB_ERROR']; }
        $row = $res->fetch_assoc();
        if (!$row) { return ['ok' => false, 'code' => 'NOT_FOUND']; }

        // Convert binaries to textual UUIDs.
        $aId = joma_db_bin_to_uuid((string) ($row['a_id'] ?? ''));
        $aPersonId = joma_db_bin_to_uuid((string) ($row['a_person_id'] ?? ''));
        $mId = joma_db_bin_to_uuid((string) ($row['m_id'] ?? ''));
        $mPersonId = joma_db_bin_to_uuid((string) ($row['m_person_id'] ?? ''));
        $mScopeId = joma_db_bin_to_uuid((string) ($row['m_scope_id'] ?? ''));
        $raId = joma_db_bin_to_uuid((string) ($row['ra_id'] ?? ''));
        $raAccountId = joma_db_bin_to_uuid((string) ($row['ra_account_id'] ?? ''));
        $raPersonId = joma_db_bin_to_uuid((string) ($row['ra_person_id'] ?? ''));
        $raMembershipId = joma_db_bin_to_uuid((string) ($row['ra_membership_id'] ?? ''));
        $raScopeId = joma_db_bin_to_uuid((string) ($row['ra_scope_id'] ?? ''));
        if ($aId === null || $aPersonId === null || $mId === null || $mPersonId === null || $mScopeId === null
            || $raId === null || $raAccountId === null || $raPersonId === null || $raMembershipId === null || $raScopeId === null) {
            return ['ok' => false, 'code' => 'DATA_INTEGRITY'];
        }
        // Integrity: all person links must match.
        if (!joma_rule_same_id($aPersonId, $mPersonId) || !joma_rule_same_id($aPersonId, $raPersonId) || !joma_rule_same_id($aId, $raAccountId)) {
            return ['ok' => false, 'code' => 'CONTEXT_DENIED'];
        }
        if (!joma_rule_same_id($mScopeId, $raScopeId)) {
            return ['ok' => false, 'code' => 'CONTEXT_DENIED'];
        }
        // Validate datetimes.
        $mValidFrom = (string) ($row['m_valid_from'] ?? '');
        $raValidFrom = (string) ($row['ra_valid_from'] ?? '');
        if (joma_rule_utc_us($mValidFrom) === null || joma_rule_utc_us($raValidFrom) === null) {
            return ['ok' => false, 'code' => 'DATA_INTEGRITY'];
        }
        $mValidUntil = $row['m_valid_until'] ?? null;
        $raValidUntil = $row['ra_valid_until'] ?? null;
        $raRevokedAt = $row['ra_revoked_at'] ?? null;
        if ($mValidUntil !== null && joma_rule_utc_us((string) $mValidUntil) === null) { return ['ok' => false, 'code' => 'DATA_INTEGRITY']; }
        if ($raValidUntil !== null && joma_rule_utc_us((string) $raValidUntil) === null) { return ['ok' => false, 'code' => 'DATA_INTEGRITY']; }
        if ($raRevokedAt !== null && joma_rule_utc_us((string) $raRevokedAt) === null) { return ['ok' => false, 'code' => 'DATA_INTEGRITY']; }
        // Normalize empty string to null for optional fields.
        if ($mValidUntil === '') { $mValidUntil = null; }
        if ($raValidUntil === '') { $raValidUntil = null; }
        if ($raRevokedAt === '') { $raRevokedAt = null; }

        // Role integer and definition.
        $raRoleIdRaw = $row['ra_role_id'] ?? null;
        $raRoleId = joma_db_int_unsigned($raRoleIdRaw);
        if ($raRoleId === null) { return ['ok' => false, 'code' => 'DATA_INTEGRITY']; }

        $rdCode = isset($row['rd_code']) ? (string) $row['rd_code'] : null;
        $rdActive = $row['rd_is_active'] ?? null;
        $policy = joma_context_resolve_policy($rdCode, $rdActive);

        $snapshot = [
            'account' => ['id' => $aId, 'person_id' => $aPersonId, 'status' => (string) ($row['a_status'] ?? '')],
            'membership' => [
                'id' => $mId,
                'person_id' => $mPersonId,
                'scope_id' => $mScopeId,
                'status' => (string) ($row['m_status'] ?? ''),
                'valid_from' => $mValidFrom,
                'valid_until' => $mValidUntil,
                'revoked_at' => null,
            ],
            'role_assignment' => [
                'id' => $raId,
                'account_id' => $raAccountId,
                'person_id' => $raPersonId,
                'membership_id' => $raMembershipId,
                'scope_id' => $raScopeId,
                'role_id' => $raRoleId,
                'valid_from' => $raValidFrom,
                'valid_until' => $raValidUntil,
                'revoked_at' => $raRevokedAt,
            ],
            'policy_status' => $policy['policy_status'],
            'permissions' => $policy['permissions'],
        ];
        // Snapshot must be usable by domain rules; if principal itself invalid, fail closed.
        $principal = joma_rule_principal($snapshot);
        if (!$principal['allowed']) {
            return ['ok' => false, 'code' => $principal['code']];
        }
        return ['ok' => true, 'snapshot' => $snapshot];
    } catch (Throwable $e) {
        return ['ok' => false, 'code' => 'DB_ERROR'];
    } finally {
        @$stmt->close();
    }
}

/**
 * Convenience: load and immediately check permission for scope.
 */
function joma_context_require($db, string $accountId, string $roleAssignmentId, string $scopeId, string $permission, string $nowUtc): array {
    $loaded = joma_context_load($db, $accountId, $roleAssignmentId, $nowUtc);
    if (!($loaded['ok'] ?? false)) { return $loaded; }
    $snapshot = $loaded['snapshot'];
    // Ensure requested scope matches the role's scope — prevents cross-scope use.
    if (!joma_rule_same_id($snapshot['role_assignment']['scope_id'] ?? null, $scopeId)) {
        return ['ok' => false, 'code' => 'CONTEXT_DENIED'];
    }
    $check = joma_rule_context($snapshot, $scopeId, $permission, $nowUtc);
    if (!$check['allowed']) {
        return ['ok' => false, 'code' => $check['code']];
    }
    return ['ok' => true, 'snapshot' => $snapshot];
}
