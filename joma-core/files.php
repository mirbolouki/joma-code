<?php
declare(strict_types=1);

/**
 * JOMA protected files — storage outside document root, no public URL.
 * Authorization reuses portal read (explicit publication + audience).
 * File metadata never grants access alone; SHA256 and byte_length are integrity checks.
 */
require_once __DIR__ . '/domain_rules.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/portal.php';

function joma_files_validate_row(array $row): bool {
    // Must have READY state, valid storage_key, byte_length >0, sha256 32 bytes
    if (($row['state'] ?? null) !== 'READY') { return false; }
    $key = (string)($row['storage_key'] ?? '');
    if ($key === '' || strlen($key) > 191 || preg_match('/\A[a-zA-Z0-9_\-.\/]+\z/', $key) !== 1) { return false; }
    // Forbid directory traversal
    if (strpos($key,'..')!==false) { return false; }
    $byteLen = $row['byte_length'] ?? null;
    // mysqli may return string
    $lenInt = is_int($byteLen) ? $byteLen : (is_string($byteLen) ? (int)$byteLen : null);
    if ($lenInt===null || $lenInt <=0) { return false; }
    $sha = $row['sha256'] ?? null;
    if (!is_string($sha) || strlen($sha)!==32) { return false; }
    $media = (string)($row['media_type'] ?? '');
    if ($media==='' || strlen($media)>100) { return false; }
    return true;
}

function joma_files_load($db, string $fileId): ?array {
    if (joma_rule_uuid($fileId)===null) { return null; }
    if (!is_object($db)||!method_exists($db,'prepare')) { return null; }
    $bin=joma_db_uuid_to_bin($fileId);
    $stmt=joma_db_prepare($db,'SELECT id, storage_key, original_name, media_type, byte_length, sha256, state FROM joma_protected_files WHERE id = ? LIMIT 1');
    if ($stmt===null) { return null; }
    joma_db_bind_params($stmt,'s',[$bin]);
    $stmt->execute();
    $res=$stmt->get_result();
    $row=$res?$res->fetch_assoc():null;
    @$stmt->close();
    if (!$row) { return null; }
    $fid=joma_db_bin_to_uuid((string)($row['id']??''));
    if ($fid===null) { return null; }
    // Keep raw row but normalize id to textual for callers
    $row['id']=$fid;
    // Keep sha256 as binary 32, not hex
    return $row;
}

/**
 * Authorize file download: file must be READY and caller must have portal read for the linked resource.
 * For reports: file is linked via report_versions.protected_file_id
 * For this layer, caller provides resource projection (as in portal) that is already linked to this file.
 * We verify fileRow matches resource linkage via DB join: SELECT rv.protected_file_id FROM joma_report_versions rv WHERE rv.id = ? AND rv.protected_file_id = ?
 */
function joma_files_authorize($db, array $snapshot, array $fileRow, array $resource, string $nowUtc): array {
    if (!joma_files_validate_row($fileRow)) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
    $principal=joma_rule_principal($snapshot);
    if (!$principal['allowed']) { return $principal; }
    if (joma_rule_utc_us($nowUtc)===null) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
    // Verify linkage: for REPORT kind, check report_versions row links file
    if (($resource['kind']??null)==='REPORT') {
        $versionId=$resource['version_id'] ?? null;
        $fileId=$fileRow['id'] ?? null;
        if (joma_rule_uuid($versionId)===null || joma_rule_uuid($fileId)===null) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
        $vBin=joma_db_uuid_to_bin($versionId);
        $fBin=joma_db_uuid_to_bin($fileId);
        $stmt=joma_db_prepare($db,'SELECT id FROM joma_report_versions WHERE id = ? AND protected_file_id = ? LIMIT 1');
        if ($stmt===null) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
        joma_db_bind_params($stmt,'ss',[$vBin,$fBin]);
        $stmt->execute();
        $res=$stmt->get_result();
        $ok=$res && $res->fetch_assoc();
        @$stmt->close();
        if (!$ok) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
    } elseif (($resource['kind']??null)==='FORM_RESPONSE') {
        // Forms use file? Not yet: forms have no protected_file linkage in this schema; deny file for form for now
        return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE');
    } else {
        return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE');
    }
    // Delegate to portal check for actual audience/permission/entitlement
    return joma_portal_check($db,$snapshot,$resource,$nowUtc);
}

function joma_files_headers(array $fileRow, bool $inline=false): array {
    // Generate safe headers for download; caller must have already authorized.
    if (!joma_files_validate_row($fileRow)) { return []; }
    $media=(string)($fileRow['media_type']??'application/octet-stream');
    $orig=(string)($fileRow['original_name']??'file');
    // Sanitize original_name for header: remove control, limit length
    $origSafe=preg_replace('/[\x00-\x1F\x7F]/u','_', $orig);
    $origSafe=mb_substr($origSafe,0,100,'UTF-8');
    $disposition = ($inline ? 'inline' : 'attachment') . '; filename="'.addcslashes($origSafe,'"\\').'"';
    // Use hex sha for integrity header? Not standard, but we can expose as ETag
    $shaBin=(string)($fileRow['sha256']??'');
    $etag='"'.bin2hex($shaBin).'"';
    return [
        'Content-Type'=>$media,
        'Content-Length'=>(string)($fileRow['byte_length']??''),
        'Content-Disposition'=>$disposition,
        'Cache-Control'=>'private, no-store, max-age=0',
        'Pragma'=>'no-cache',
        'ETag'=>$etag,
        'X-Content-Type-Options'=>'nosniff',
    ];
}

function joma_files_storage_path(string $baseDir, array $fileRow): ?string {
    if (!joma_files_validate_row($fileRow)) { return null; }
    $key=(string)($fileRow['storage_key']??'');
    // Prevent traversal: baseDir + '/' + key must stay inside baseDir
    $base=rtrim($baseDir,'/');
    if ($base==='' || $key==='') { return null; }
    // Simple join, caller must ensure baseDir is outside document root
    $path=$base.'/'.$key;
    // Forbid .. already checked
    return $path;
}
