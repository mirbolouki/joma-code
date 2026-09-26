<?php
declare(strict_types=1);

/**
 * JOMA audit — append-only, no secrets, no clinical text.
 * Decision: ALLOWED/DENIED/CONFLICT/FAILED ; reason_code is allowlisted.
 */
require_once __DIR__ . '/domain_rules.php';
require_once __DIR__ . '/db.php';

function joma_audit_validate_reason(string $code): bool {
    return (bool) preg_match('/\A[A-Z_]{1,64}\z/', $code);
}
function joma_audit_log($db, ?string $commandId, ?string $actorPersonId, ?string $systemActorCode, ?string $scopeId, string $actionCode, string $resourceKind, string $resourceRef, string $decision, string $reasonCode, ?string $nowUtc = null, ?array $redactedMeta = null): bool {
    if (!in_array($decision,['ALLOWED','DENIED','CONFLICT','FAILED'],true)) { return false; }
    if (!joma_audit_validate_reason($reasonCode)) { return false; }
    if (!preg_match('/\A[A-Za-z0-9_.]{1,100}\z/', $actionCode)) { return false; }
    if (!preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $resourceKind)) { return false; }
    if (strlen($resourceRef) > 191 || $resourceRef === '') { return false; }
    if (strpos($resourceRef, "\0") !== false) { return false; }
    // Reject secrets in metadata: simple check for password/token/key substrings
    $metaJson = null;
    if ($redactedMeta !== null) {
        $json = json_encode($redactedMeta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($json===false || strlen($json)>2000) { return false; }
        $low = strtolower($json);
        foreach (['password','passwd','secret','token','key'] as $bad) {
            if (strpos($low,$bad)!==false) { return false; }
        }
        $metaJson=$json;
    }
    $nowUtc = $nowUtc ?? gmdate('Y-m-d H:i:s.000000');
    if (joma_rule_utc_us($nowUtc)===null) { return false; }
    $commandBin = $commandId!==null ? joma_db_uuid_to_bin($commandId) : null;
    if ($commandId!==null && $commandBin===null) { return false; }
    $actorBin = $actorPersonId!==null ? joma_db_uuid_to_bin($actorPersonId) : null;
    if ($actorPersonId!==null && $actorBin===null) { return false; }
    $scopeBin = $scopeId!==null ? joma_db_uuid_to_bin($scopeId) : null;
    if ($scopeId!==null && $scopeBin===null) { return false; }
    if (($actorBin===null) === ($systemActorCode===null)) {
        // Exactly one of actorPerson or system code must be set, unless both null for system? But audit requires one.
        // Allow both null? No, per schema: (actor IS NOT NULL AND system IS NULL) OR (actor IS NULL AND system IS NOT NULL)
        // So require exactly one.
        return false;
    }
    if ($systemActorCode!==null && !preg_match('/\A[A-Z_]{1,64}\z/', $systemActorCode)) { return false; }
    if (!is_object($db)||!method_exists($db,'prepare')) { return false; }

    $auditId = joma_uuid_v4();
    $auditBin=joma_db_uuid_to_bin($auditId);
    $stmt=joma_db_prepare($db,'INSERT INTO joma_audit_entries (id, command_id, actor_person_id, system_actor_code, scope_id, action_code, resource_kind, resource_reference, decision, reason_code, redacted_metadata_json, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    if ($stmt===null) { return false; }
    // Bind: auditBin, commandBin, actorBin, systemCode, scopeBin, action, kind, ref, decision, reason, metaJson, now
    $params=[$auditBin,$commandBin,$actorBin,$systemActorCode,$scopeBin,$actionCode,$resourceKind,$resourceRef,$decision,$reasonCode,$metaJson,$nowUtc];
    // Use s types for all (nulls allowed)
    joma_db_bind_params($stmt,str_repeat('s',12),$params);
    $ok=$stmt->execute();
    @$stmt->close();
    return (bool)$ok;
}

/**
 * Map internal domain codes to audit decision/reason and HTTP status.
 */
function joma_http_map(string $internalCode): array {
    $map=[
        'AUTHENTICATION_REQUIRED'=>[401,'AUTH_REQUIRED'],
        'CONTEXT_DENIED'=>[403,'FORBIDDEN'],
        'PERMISSION_DENIED'=>[403,'FORBIDDEN'],
        'ASSIGNED_THERAPIST_REQUIRED'=>[403,'FORBIDDEN'],
        'STATE_CONFLICT'=>[409,'CONFLICT'],
        'CAPACITY_CONFLICT'=>[409,'CONFLICT'],
        'HOLD_CONFLICT'=>[409,'CONFLICT'],
        'HOLD_EXPIRED_OR_NOT_STARTED'=>[409,'CONFLICT'],
        'IDEMPOTENCY_CONFLICT'=>[409,'CONFLICT'],
        'COMMAND_IN_PROGRESS'=>[409,'CONFLICT'],
        'INVALID_ID'=>[422,'INVALID_INPUT'],
        'INVALID_TIME'=>[422,'INVALID_INPUT'],
        'INVALID_TIME_WINDOW'=>[422,'INVALID_INPUT'],
        'INVALID_PURPOSE'=>[422,'INVALID_INPUT'],
        'INVALID_RESOURCE'=>[422,'INVALID_INPUT'],
        'INVALID_COMMAND'=>[422,'INVALID_INPUT'],
        'INVALID_HASH'=>[422,'INVALID_INPUT'],
        'NOT_FOUND'=>[404,'NOT_FOUND'],
        'RESOURCE_NOT_AVAILABLE'=>[404,'NOT_FOUND'],
        'DATA_INTEGRITY'=>[500,'SERVER_ERROR'],
        'DB_ERROR'=>[503,'RETRYABLE'],
        'ACTIVE_ENGAGEMENT_REQUIRED'=>[422,'INVALID_STATE'],
    ];
    return $map[$internalCode] ?? [500,'SERVER_ERROR'];
}
