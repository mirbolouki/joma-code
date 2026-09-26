<?php
declare(strict_types=1);

/**
 * JOMA rate limit — simple sliding window, no Redis required.
 * For production, bucket should be stored in DB/Redis; for tests, in-memory array is used.
 * Key is typically "login:ip" or "login:username".
 */

function joma_rate_limit_check(array &$store, string $key, int $limit, int $windowSeconds, string $nowUtc): bool {
    if ($limit <=0 || $windowSeconds <=0) { return false; }
    $now = joma_rule_utc_us($nowUtc);
    if ($now===null) { return false; }
    $nowSec = intdiv($now, 1000000);
    if (!isset($store[$key]) || !is_array($store[$key])) { $store[$key]=[]; }
    // Prune old entries
    $cut = $nowSec - $windowSeconds;
    $store[$key] = array_values(array_filter($store[$key], fn($t)=>$t > $cut));
    if (count($store[$key]) >= $limit) { return false; }
    $store[$key][] = $nowSec;
    return true;
}

function joma_rate_limit_remaining(array $store, string $key, int $limit, int $windowSeconds, string $nowUtc): int {
    $now = joma_rule_utc_us($nowUtc);
    if ($now===null) return 0;
    $nowSec=intdiv($now,1000000);
    $cut=$nowSec - $windowSeconds;
    $list=$store[$key] ?? [];
    $list=array_values(array_filter($list, fn($t)=>$t > $cut));
    return max(0, $limit - count($list));
}

function joma_rate_limit_reset(array &$store, string $key): void {
    unset($store[$key]);
}
