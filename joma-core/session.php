<?php
declare(strict_types=1);

/**
 * JOMA session helpers — no DB, no crypto payload.
 * Session holds only principal references (account_id, person_id).
 * Cookie must be HttpOnly + Secure (when HTTPS) + SameSite=Lax.
 */

function joma_session_is_secure_context(): bool {
    // Detect HTTPS via standard server vars; fallback false for CLI/tests.
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') { return true; }
    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) { return true; }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') { return true; }
    return false;
}

function joma_session_start(?bool $secure = null): void {
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    // In CLI/tests where output already started, we cannot send cookies; keep $_SESSION as array.
    if (headers_sent()) {
        if (!isset($_SESSION) || !is_array($_SESSION)) { $_SESSION = []; }
        return;
    }
    if ($secure === null) { $secure = joma_session_is_secure_context(); }
    // Must be before session_start and before headers sent.
    if (!headers_sent()) {
        // PHP 7.3+ array form is preferred; ini fallback for older.
        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            // Fallback: best-effort.
            ini_set('session.cookie_httponly', '1');
            if ($secure) { ini_set('session.cookie_secure', '1'); }
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_cookies', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        if ($secure) { ini_set('session.cookie_secure', '1'); }
        // Prevent transparent sid.
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_samesite', 'Lax');
    }
    // For CLI/tests, allow starting without cookies.
    @session_start();
}

function joma_session_regenerate(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) { return; }
    // Delete old session file.
    @session_regenerate_id(true);
}

function joma_session_store_principal(array $account): void {
    // Validate before storing: only ACTIVE account may establish session.
    if (joma_rule_uuid($account['id'] ?? null) === null || joma_rule_uuid($account['person_id'] ?? null) === null) { return; }
    if (($account['status'] ?? null) !== 'ACTIVE') { return; }
    joma_session_start();
    $_SESSION['joma_principal'] = [
        'account_id' => strtolower((string) $account['id']),
        'person_id' => strtolower((string) $account['person_id']),
        'login_name' => (string) ($account['login_name'] ?? ''),
        'established_at' => gmdate('Y-m-d H:i:s.000000'),
    ];
    joma_session_regenerate();
}

function joma_session_get_principal(): ?array {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Allow reading from $_SESSION even if session not started (tests).
        $p = $_SESSION['joma_principal'] ?? null;
    } else {
        $p = $_SESSION['joma_principal'] ?? null;
    }
    if (!is_array($p)) { return null; }
    if (joma_rule_uuid($p['account_id'] ?? null) === null || joma_rule_uuid($p['person_id'] ?? null) === null) { return null; }
    return $p;
}

function joma_session_clear(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        if (ini_get('session.use_cookies') && !headers_sent()) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? true));
        }
        @session_destroy();
    } else {
        unset($_SESSION['joma_principal']);
        unset($_SESSION['joma_csrf']);
    }
}

function joma_csrf_token(): string {
    joma_session_start();
    $existing = $_SESSION['joma_csrf'] ?? null;
    if (is_string($existing) && preg_match('/\A[0-9a-f]{64}\z/', $existing)) {
        return $existing;
    }
    $token = bin2hex(random_bytes(32));
    $_SESSION['joma_csrf'] = $token;
    return $token;
}

function joma_csrf_verify(?string $token): bool {
    if (!is_string($token) || $token === '') { return false; }
    joma_session_start();
    $expected = $_SESSION['joma_csrf'] ?? null;
    if (!is_string($expected) || $expected === '') { return false; }
    return hash_equals($expected, $token);
}
