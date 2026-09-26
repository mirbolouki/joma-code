<?php
declare(strict_types=1);

/**
 * JOMA idempotency receipt — BINARY(32) key+hash, UNIQUE (scope_id,idempotency_key).
 * canonical JSON is sorted keys, no whitespace, to ensure stable payload_hash.
 * All SQL via prepared statements. No clinical payload in result_reference_json.
 */
require_once __DIR__ . '/domain_rules.php';
require_once __DIR__ . '/db.php';

function joma_receipt_canonical($payload): ?string {
    if (!is_array($payload)) { return null; }
    // Reject non-UTF8 or resource etc. by json_encode check.
    $sorted = joma_receipt_sort_recursive($payload);
    $json = json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { return null; }
    // Forbid payload containing secrets-like keys? We allow but caller must not include secrets.
    // Ensure canonical does not contain NUL
    if (strpos($json, "\0") !== false) { return null; }
    return $json;
}
function joma_receipt_sort_recursive($data) {
    if (!is_array($data)) { return $data; }
    // Associative vs list: check if keys are 0..n-1
    $isList = array_keys($data) === range(0, count($data)-1);
    if ($isList) {
        return array_map('joma_receipt_sort_recursive', $data);
    }
    ksort($data, SORT_STRING);
    foreach ($data as $k=>$v) { $data[$k]=joma_receipt_sort_recursive($v); }
    return $data;
}
function joma_receipt_hash(string $canonical): string {
    // Returns hex 64, lower-case
    return hash('sha256', $canonical);
}
function joma_receipt_bin_from_hex(string $hex): ?string {
    if (!preg_match('/\A[0-9a-f]{64}\z/', $hex)) { return null; }
    $bin = @hex2bin($hex);
    return ($bin!==false && strlen($bin)===32) ? $bin : null;
}
function joma_receipt_hex_from_bin(string $bin): ?string {
    if (strlen($bin)!==32) { return null; }
    $hex = bin2hex($bin);
    return preg_match('/\A[0-9a-f]{64}\z/',$hex) ? $hex : null;
}
function joma_receipt_generate_key(): string {
    // 32 random bytes, hex for transport, bin for DB
    return bin2hex(random_bytes(32));
}

/**
 * Try to insert a PROCESSING receipt. On duplicate, fetch existing and validate.
 * Returns ['status'=>'INSERTED','id'=>uuid] or ['status'=>'EXISTS','row'=>array] or ['status'=>'CONFLICT','code'=>...]
 */
function joma_receipt_claim($db, string $scopeId, ?string $actorPersonId, ?string $systemActorCode, string $commandName, string $idempotencyHex, string $payloadHex, string $nowUtc): array {
    if (joma_rule_uuid($scopeId)===null) { return ['status'=>'CONFLICT','code'=>'INVALID_ID']; }
    if ($actorPersonId!==null && joma_rule_uuid($actorPersonId)===null) { return ['status'=>'CONFLICT','code'=>'INVALID_ID']; }
    if ($systemActorCode!==null && !preg_match('/\A[A-Z_]{1,64}\z/', $systemActorCode)) { return ['status'=>'CONFLICT','code'=>'INVALID_ID']; }
    if (($actorPersonId===null) === ($systemActorCode===null)) { return ['status'=>'CONFLICT','code'=>'INVALID_ACTOR']; }
    if (!preg_match('/\A[a-z_]+\.[a-z_]+\z/', $commandName) && !preg_match('/\A[a-z_]+_[a-z_]+\z/', $commandName)) {
        // Allow simple names like accept_assignment
        if (!preg_match('/\A[A-Za-z0-9_.]{1,100}\z/', $commandName)) { return ['status'=>'CONFLICT','code'=>'INVALID_COMMAND']; }
    }
    if (!preg_match('/\A[0-9a-f]{64}\z/', $idempotencyHex) || !preg_match('/\A[0-9a-f]{64}\z/', $payloadHex)) { return ['status'=>'CONFLICT','code'=>'INVALID_HASH']; }
    if (joma_rule_utc_us($nowUtc)===null) { return ['status'=>'CONFLICT','code'=>'INVALID_TIME']; }
    $scopeBin=joma_db_uuid_to_bin($scopeId);
    $actorBin=$actorPersonId!==null ? joma_db_uuid_to_bin($actorPersonId) : null;
    $idempBin=joma_receipt_bin_from_hex($idempotencyHex);
    $payloadBin=joma_receipt_bin_from_hex($payloadHex);
    if ($scopeBin===null||$idempBin===null||$payloadBin===null) { return ['status'=>'CONFLICT','code'=>'INVALID_ID']; }
    if ($actorBin===null && $actorPersonId!==null) { return ['status'=>'CONFLICT','code'=>'INVALID_ID']; }
    if (!is_object($db)||!method_exists($db,'prepare')) { return ['status'=>'CONFLICT','code'=>'DB_ERROR']; }

    $receiptId=joma_uuid_v4();
    $receiptBin=joma_db_uuid_to_bin($receiptId);
    // Try insert
    $stmt=joma_db_prepare($db,'INSERT INTO joma_command_receipts (id, scope_id, actor_person_id, system_actor_code, command_name, idempotency_key, payload_hash, status, created_at) VALUES (?,?,?,?,?,?,?,?,?)');
    if ($stmt===null) { return ['status'=>'CONFLICT','code'=>'DB_ERROR']; }
    $statusProcessing='PROCESSING';
    // Bind: id, scope, actor, system_code, command, idemp, payload, status, now
    // Use types sssssssss : 9 params (actor may be null, system may be null)
    // For null, bind as string type with null value; mysqli will handle.
    $params=[$receiptBin,$scopeBin,$actorBin,$systemActorCode,$commandName,$idempBin,$payloadBin,$statusProcessing,$nowUtc];
    // Need to handle null actor: we still pass null string? Our helper will pass null.
    joma_db_bind_params($stmt,'sssssssss',$params);
    $ok=false;
    try { $ok=$stmt->execute(); } catch(Throwable $e){ $ok=false; }
    @$stmt->close();
    if ($ok) {
        return ['status'=>'INSERTED','id'=>$receiptId,'idempotency_hex'=>$idempotencyHex,'payload_hex'=>$payloadHex];
    }
    // Duplicate -> fetch existing row for this scope+key
    $stmt=joma_db_prepare($db,'SELECT id, scope_id, actor_person_id, system_actor_code, command_name, idempotency_key, payload_hash, status FROM joma_command_receipts WHERE scope_id = ? AND idempotency_key = ? LIMIT 1 FOR UPDATE');
    if ($stmt===null) { return ['status'=>'CONFLICT','code'=>'DB_ERROR']; }
    joma_db_bind_params($stmt,'ss',[$scopeBin,$idempBin]);
    $stmt->execute();
    $res=$stmt->get_result();
    $row=$res?$res->fetch_assoc():null;
    @$stmt->close();
    if (!$row) { return ['status'=>'CONFLICT','code'=>'DB_ERROR']; }
    $existingScope=joma_db_bin_to_uuid((string)($row['scope_id']??''));
    $existingActor=$row['actor_person_id']!==null ? joma_db_bin_to_uuid((string)$row['actor_person_id']) : null;
    $existingSystem=$row['system_actor_code'] ?? null;
    $existingCommand=(string)($row['command_name']??'');
    $existingPayloadBin=(string)($row['payload_hash']??'');
    $existingPayloadHex=joma_receipt_hex_from_bin($existingPayloadBin);
    $existingStatus=(string)($row['status']??'');
    $existingId=joma_db_bin_to_uuid((string)($row['id']??''));
    if ($existingScope===null||$existingPayloadHex===null||$existingId===null) { return ['status'=>'CONFLICT','code'=>'DATA_INTEGRITY']; }
    // Validate via domain rule joma_rule_retry expects hex payload_hash
    $checkActor = $actorPersonId ?? $existingActor; // for rule, need actor_person_id; but domain rule expects actor_person_id UUID
    // If system actor, domain rule expects actor_person_id same as scope? Actually receipt table allows system_actor_code. For retry check we need actor_person_id when human, else system code path not covered by domain rule. For now handle human actors.
    if ($actorPersonId!==null) {
        $retry=joma_rule_retry(['command_name'=>$existingCommand,'scope_id'=>$existingScope,'actor_person_id'=>$existingActor,'payload_hash'=>$existingPayloadHex,'status'=>$existingStatus],$commandName,$scopeId,$actorPersonId,$payloadHex);
        if (!$retry['allowed'] && $retry['code']==='IDEMPOTENCY_CONFLICT') {
            return ['status'=>'CONFLICT','code'=>'IDEMPOTENCY_CONFLICT'];
        }
        if ($retry['code']==='COMMAND_IN_PROGRESS') {
            return ['status'=>'EXISTS','code'=>'COMMAND_IN_PROGRESS','row'=>$row,'id'=>$existingId];
        }
        if ($retry['code']==='REPLAY_REQUIRES_CURRENT_AUTHORIZATION') {
            return ['status'=>'EXISTS','code'=>'REPLAY_REQUIRES_CURRENT_AUTHORIZATION','row'=>$row,'id'=>$existingId];
        }
        return ['status'=>'CONFLICT','code'=>$retry['code']];
    } else {
        // System actor path: just compare command and payload
        if ($existingCommand!==$commandName || $existingPayloadHex!==$payloadHex || $existingSystem!==$systemActorCode) {
            return ['status'=>'CONFLICT','code'=>'IDEMPOTENCY_CONFLICT'];
        }
        if ($existingStatus==='PROCESSING') { return ['status'=>'EXISTS','code'=>'COMMAND_IN_PROGRESS','row'=>$row,'id'=>$existingId]; }
        if ($existingStatus==='SUCCEEDED') { return ['status'=>'EXISTS','code'=>'REPLAY_REQUIRES_CURRENT_AUTHORIZATION','row'=>$row,'id'=>$existingId]; }
        return ['status'=>'CONFLICT','code'=>'COMMAND_NOT_REPLAYABLE'];
    }
}

function joma_receipt_complete($db, string $receiptId, string $status, ?string $resultJson, string $nowUtc): bool {
    if (joma_rule_uuid($receiptId)===null) { return false; }
    if (!in_array($status,['SUCCEEDED','REJECTED'],true)) { return false; }
    if (joma_rule_utc_us($nowUtc)===null) { return false; }
    $receiptBin=joma_db_uuid_to_bin($receiptId);
    if ($receiptBin===null) { return false; }
    if (!is_object($db)||!method_exists($db,'prepare')) { return false; }
    $stmt=joma_db_prepare($db,'UPDATE joma_command_receipts SET status = ?, result_reference_json = ?, completed_at = ? WHERE id = ?');
    if ($stmt===null) { return false; }
    joma_db_bind_params($stmt,'ssss',[$status,$resultJson,$nowUtc,$receiptBin]);
    $ok=$stmt->execute();
    @$stmt->close();
    return (bool)$ok;
}
