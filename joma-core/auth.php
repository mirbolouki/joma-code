<?php
declare(strict_types=1);

/**
 * JOMA authentication — password login + session binding.
 * No SMS OTP login; SMS is only for password reset (not implemented here).
 * All DB access via prepared statements; no string-interpolated SQL.
 */
require_once __DIR__ . '/domain_rules.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/session.php';

const JOMA_LOGIN_MIN_LEN = 3;
const JOMA_LOGIN_MAX_LEN = 191;
const JOMA_PASSWORD_MAX_LEN = 200; // DoS guard; bcrypt handles 72

function joma_auth_validate_login_name(string $raw): ?string {
    $trim = trim($raw);
    $len = strlen($trim);
    if ($len < JOMA_LOGIN_MIN_LEN || $len > JOMA_LOGIN_MAX_LEN) { return null; }
    // Reject control characters, NUL, newline.
    if (preg_match('/[\x00-\x1F\x7F]/', $trim)) { return null; }
    return $trim;
}

function joma_auth_find_account_by_login($db, string $loginName): ?array {
    // Caller must have validated loginName length but we re-check.
    if (joma_auth_validate_login_name($loginName) === null && strlen($loginName) < 500) {
        // Still attempt lookup to keep timing uniform? But reject obviously bad.
        // We treat validation failure as not-found to avoid leak, but this helper strictly requires valid form.
        return null;
    }
    $stmt = joma_db_prepare($db, 'SELECT id, person_id, login_name, password_hash, status FROM joma_accounts WHERE login_name = ? LIMIT 1');
    if ($stmt === null) { return null; }
    try {
        // mysqli bind_param requires variable reference.
        $stmt->bind_param('s', $loginName);
        $stmt->execute();
        $res = $stmt->get_result();
        if (!$res) { return null; }
        $row = $res->fetch_assoc();
        if (!$row) { return null; }
        // Binary columns come as 16-byte strings; convert to textual UUID.
        $id = joma_db_bin_to_uuid((string) ($row['id'] ?? ''));
        $personId = joma_db_bin_to_uuid((string) ($row['person_id'] ?? ''));
        if ($id === null || $personId === null) { return null; }
        $status = (string) ($row['status'] ?? '');
        if (!in_array($status, ['ACTIVE','INACTIVE','LOCKED'], true)) { return null; }
        $hash = (string) ($row['password_hash'] ?? '');
        if ($hash === '' || strlen($hash) > 255) { return null; }
        return [
            'id' => $id,
            'person_id' => $personId,
            'login_name' => (string) ($row['login_name'] ?? $loginName),
            'password_hash' => $hash,
            'status' => $status,
        ];
    } catch (Throwable $e) {
        return null;
    } finally {
        @$stmt->close();
    }
}

function joma_auth_verify_password(string $password, string $hash): bool {
    if ($password === '' || strlen($password) > JOMA_PASSWORD_MAX_LEN) { return false; }
    // Prevent warning on malformed hash: password_verify returns false.
    try {
        return password_verify($password, $hash);
    } catch (Throwable $e) {
        return false;
    }
}

// Internal dummy hash to keep timing similar when account not found.
// Precomputed bcrypt for "joma_dummy_not_found______" (cost 10).
const JOMA_DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

/**
 * Attempt login. Returns ['ok'=>true,'account'=>...] or ['ok'=>false,'code'=>...].
 * Codes are internal: INVALID_CREDENTIALS, ACCOUNT_NOT_ACTIVE, DB_ERROR.
 * HTTP layer must map all to generic message to avoid enumeration.
 */
function joma_auth_login($db, string $loginNameRaw, string $passwordRaw, ?string $nowUtc = null): array {
    $nowUtc = $nowUtc ?? gmdate('Y-m-d H:i:s.000000');
    if (joma_rule_utc_us($nowUtc) === null) {
        return ['ok' => false, 'code' => 'INVALID_TIME'];
    }
    $loginName = joma_auth_validate_login_name($loginNameRaw);
    if ($loginName === null) {
        // Uniform timing: still do dummy verify.
        joma_auth_verify_password($passwordRaw, JOMA_DUMMY_HASH);
        return ['ok' => false, 'code' => 'INVALID_CREDENTIALS'];
    }
    if ($passwordRaw === '' || strlen($passwordRaw) > JOMA_PASSWORD_MAX_LEN) {
        joma_auth_verify_password('dummy', JOMA_DUMMY_HASH);
        return ['ok' => false, 'code' => 'INVALID_CREDENTIALS'];
    }
    $account = joma_auth_find_account_by_login($db, $loginName);
    if ($account === null) {
        // Dummy verify to mitigate timing side-channel.
        joma_auth_verify_password($passwordRaw, JOMA_DUMMY_HASH);
        return ['ok' => false, 'code' => 'INVALID_CREDENTIALS'];
    }
    if (($account['status'] ?? null) !== 'ACTIVE') {
        // Distinguish internally but HTTP should not reveal existence.
        // Perform verify anyway to keep timing similar.
        joma_auth_verify_password($passwordRaw, $account['password_hash']);
        $code = ($account['status'] === 'LOCKED') ? 'ACCOUNT_LOCKED' : 'ACCOUNT_INACTIVE';
        return ['ok' => false, 'code' => $code];
    }
    if (!joma_auth_verify_password($passwordRaw, $account['password_hash'])) {
        return ['ok' => false, 'code' => 'INVALID_CREDENTIALS'];
    }
    // Optional rehash check — caller may persist new hash via separate command.
    // Do not auto-write here to avoid side-effect in read path.
    return ['ok' => true, 'account' => $account];
}

function joma_auth_login_and_establish($db, string $loginNameRaw, string $passwordRaw, ?string $nowUtc = null): array {
    $res = joma_auth_login($db, $loginNameRaw, $passwordRaw, $nowUtc);
    if (!($res['ok'] ?? false)) { return $res; }
    joma_session_store_principal($res['account']);
    return $res;
}
