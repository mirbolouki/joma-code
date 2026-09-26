<?php
declare(strict_types=1);

/**
 * JOMA DB helpers — mysqli only, no PDO.
 * All IDs in DB are BINARY(16) UUIDv4 random. Text form is lower-case.
 * This file has no side effects; connection is created by caller.
 */
require_once __DIR__ . '/domain_rules.php';

function joma_db_uuid_to_bin(string $uuid): ?string {
    $norm = joma_rule_uuid($uuid);
    if ($norm === null) { return null; }
    $hex = str_replace('-', '', $norm);
    $bin = @hex2bin($hex);
    return ($bin !== false && strlen($bin) === 16) ? $bin : null;
}

function joma_db_bin_to_uuid(string $bin): ?string {
    if (strlen($bin) !== 16) { return null; }
    $hex = bin2hex($bin);
    $uuid = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    return joma_rule_uuid($uuid);
}

/**
 * Strict unsigned INT conversion for mysqli string results.
 * Returns null for 0, negative, overflow, leading zeros, float, etc.
 */
function joma_db_int_unsigned($value): ?int {
    if (is_int($value)) {
        return ($value >= 1 && $value <= 4294967295) ? $value : null;
    }
    if (!is_string($value)) { return null; }
    if (!preg_match('/\A[1-9][0-9]*\z/', $value)) { return null; }
    if (strlen($value) > 10) { return null; }
    // Avoid overflow: "4294967295" is max.
    if (strlen($value) === 10 && strcmp($value, '4294967295') > 0) { return null; }
    $int = (int) $value;
    return ((string) $int === $value) ? $int : null;
}

/**
 * Validate DATETIME(6) string is UTC and within MySQL range.
 * Wrapper around domain rule to keep DB layer independent.
 */
function joma_db_is_utc_us(?string $value): bool {
    if ($value === null) { return true; } // NULL is allowed for valid_until/revoked_at
    return joma_rule_utc_us($value) !== null;
}

/**
 * Create mysqli handle with safe defaults. Caller provides secrets.
 * Example $cfg: ['host'=>'127.0.0.1','user'=>'u','pass'=>'p','name'=>'db','port'=>3306]
 * Returns mysqli on success or null on failure (no exception leak).
 */
function joma_db_connect(array $cfg): ?mysqli {
    $host = $cfg['host'] ?? null;
    $user = $cfg['user'] ?? null;
    $pass = $cfg['pass'] ?? null;
    $name = $cfg['name'] ?? null;
    $port = isset($cfg['port']) ? (int) $cfg['port'] : 3306;
    if (!is_string($host) || $host === '' || !is_string($user) || $user === '' || !is_string($name) || $name === '') {
        return null;
    }
    // Strict error reporting for caller to catch, but we convert to null here.
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $db = new mysqli($host, $user, $pass, $name, $port);
        $db->set_charset('utf8mb4');
        // Enforce UTC and strict SQL mode consistent with 001_core.sql expectation.
        $db->query("SET time_zone = '+00:00'");
        $db->query("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        return $db;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Helper: prepare or return null without leaking SQL.
 * Always use prepared statements; never interpolate IDs.
 * Accepts real mysqli or test doubles; any object with prepare/bind/execute is allowed.
 */
function joma_db_prepare($db, string $sql) {
    if (!is_object($db) || !method_exists($db, 'prepare')) { return null; }
    try {
        $stmt = $db->prepare($sql);
        if (!is_object($stmt)) { return null; }
        // Accept real mysqli_stmt or test double that implements execute/get_result.
        if (!method_exists($stmt, 'execute') || !method_exists($stmt, 'bind_param')) { return null; }
        return $stmt;
    } catch (Throwable $e) {
        return null;
    }
}
