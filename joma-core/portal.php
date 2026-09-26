<?php
declare(strict_types=1);

/**
 * JOMA portal read — DB-backed audience/representation checks.
 * Uses mysqli prepared statements only. No public file exposure.
 * Resource itself is a trusted server projection (kind/id/version/case/scope/subjects).
 * Publication, audience, representation, restrictions, entitlement are loaded via DB.
 */
require_once __DIR__ . '/domain_rules.php';
require_once __DIR__ . '/db.php';

function joma_portal_parse_actions($json): ?array {
    if (!is_string($json)) return null;
    $arr = json_decode($json, true);
    if (!is_array($arr)) return null;
    // Ensure all entries are strings.
    foreach ($arr as $v) { if (!is_string($v)) return null; }
    return $arr;
}

/**
 * Check report/form portal access by loading publication/audience etc. from DB.
 *
 * @param $db mysqli/mock
 * @param array $snapshot trusted context snapshot
 * @param array $resource trusted resource projection: kind,id,version_id,case_id,scope_id,subject_person_ids,service_id,required_product_id,policy_status,revision_state
 * @param string $nowUtc
 * @return array ['allowed'=>bool,'code'=>string]
 */
function joma_portal_check($db, array $snapshot, array $resource, string $nowUtc): array {
    $principal = joma_rule_principal($snapshot);
    if (!$principal['allowed']) { return $principal; }
    $now = joma_rule_utc_us($nowUtc);
    if ($now===null) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
    if (!is_object($db) || !method_exists($db,'prepare')) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
    // Validate resource projection basics (fail closed).
    if (!in_array($resource['kind']??null,['REPORT','FORM_RESPONSE'],true)) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
    foreach (['id','version_id','case_id','scope_id'] as $k){ if(joma_rule_uuid($resource[$k]??null)===null) return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
    if (!is_array($resource['subject_person_ids']??null) || !$resource['subject_person_ids']) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
    if (!array_key_exists('required_product_id',$resource)) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
    // Determine tables by kind.
    $isReport = ($resource['kind']==='REPORT');
    $pubTable = $isReport ? 'joma_report_publications' : 'joma_form_publications';
    $audTable = $isReport ? 'joma_report_publication_audiences' : 'joma_form_publication_audiences';
    $pubVersionCol = $isReport ? 'report_version_id' : 'submission_revision_id';
    $versionId = (string)$resource['version_id'];
    $resourceId = (string)$resource['id'];
    $caseId = (string)$resource['case_id'];
    $scopeId = (string)$resource['scope_id'];
    $versionBin = joma_db_uuid_to_bin($versionId);
    $resourceBin = joma_db_uuid_to_bin($resourceId);
    $caseBin = joma_db_uuid_to_bin($caseId);
    $scopeBin = joma_db_uuid_to_bin($scopeId);
    if ($versionBin===null||$resourceBin===null||$caseBin===null||$scopeBin===null) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
    $recipientPerson = $snapshot['account']['person_id'] ?? null;
    if (joma_rule_uuid($recipientPerson)===null) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
    $recipientBin = joma_db_uuid_to_bin($recipientPerson);
    if ($recipientBin===null) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }

    // Load publication + audience via join (only PORTAL publications are portal-visible).
    // We look for audience for this recipient. If not found, deny.
    // For reports: publication.resource_id == resource.id, version_id == resource.version_id
    // For forms: similar.
    try {
        // Build query: join publication and audience
        if ($isReport) {
            $sql = 'SELECT rp.id AS pub_id, rp.report_version_id AS pub_version_id, rp.case_id AS pub_case_id, rp.published_by_person_id, rp.channel, rp.published_at, rp.revoked_at,'
                 . ' rpa.id AS aud_id, rpa.publication_id, rpa.recipient_person_id, rpa.access_subject_person_id, rpa.scope_id AS aud_scope_id, rpa.basis_kind, rpa.representation_id, rpa.revoked_at AS aud_revoked'
                 . ' FROM joma_report_publications rp JOIN joma_report_publication_audiences rpa ON rpa.publication_id = rp.id'
                 . ' WHERE rp.report_version_id = ? AND rp.case_id = ? AND rpa.recipient_person_id = ? AND rp.channel = ? AND rp.revoked_at IS NULL AND rpa.revoked_at IS NULL LIMIT 1';
            $stmt = joma_db_prepare($db,$sql);
            if ($stmt===null) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
            $chan='PORTAL';
            // Need 4 params: versionBin, caseBin, recipientBin, chan
            // Use helper for variable count
            joma_db_bind_params($stmt,'ssss',[$versionBin,$caseBin,$recipientBin,$chan]);
        } else {
            // Form: publication is per instance/case but we join on submission_revision_id == version_id and case
            $sql = 'SELECT fp.id AS pub_id, fp.submission_revision_id AS pub_version_id, fp.case_id AS pub_case_id, fp.published_at, fp.revoked_at,'
                 . ' fpa.id AS aud_id, fpa.publication_id, fpa.recipient_person_id, fpa.access_subject_person_id, fpa.scope_id AS aud_scope_id, fpa.basis_kind, fpa.representation_id, fpa.revoked_at AS aud_revoked'
                 . ' FROM joma_form_publications fp JOIN joma_form_publication_audiences fpa ON fpa.publication_id = fp.id'
                 . ' WHERE fp.submission_revision_id = ? AND fp.case_id = ? AND fpa.recipient_person_id = ? AND fpa.revoked_at IS NULL AND fp.revoked_at IS NULL LIMIT 1';
            $stmt = joma_db_prepare($db,$sql);
            if ($stmt===null) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
            joma_db_bind_params($stmt,'sss',[$versionBin,$caseBin,$recipientBin]);
        }
        $stmt->execute();
        $res=$stmt->get_result();
        $row=$res?$res->fetch_assoc():null;
        @$stmt->close();
        if (!$row) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }

        // Build publication and audience arrays as domain rule expects
        $pubId = joma_db_bin_to_uuid((string)($row['pub_id']??''));
        $pubVersion = joma_db_bin_to_uuid((string)($row['pub_version_id']??''));
        $pubCase = joma_db_bin_to_uuid((string)($row['pub_case_id']??''));
        $pubScope = $scopeId; // For report, publication scope == resource scope; for form, use aud_scope
        // For report, we have channel; for form, assume PORTAL implicitly (no channel column in form_publications, but we treat as PORTAL)
        $pubChannel = $isReport ? (string)($row['channel']??'') : 'PORTAL';
        $pubPublished = (string)($row['published_at']??'');
        $pubRevoked = $row['revoked_at'] ?? null;
        // Normalize null revocation: if string empty, treat as null
        if ($pubRevoked === '') $pubRevoked = null;
        if ($pubId===null||$pubVersion===null||$pubCase===null) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }

        $audId = joma_db_bin_to_uuid((string)($row['aud_id']??''));
        $audPub = joma_db_bin_to_uuid((string)($row['publication_id']??''));
        $audRecipient = joma_db_bin_to_uuid((string)($row['recipient_person_id']??''));
        $audSubject = joma_db_bin_to_uuid((string)($row['access_subject_person_id']??''));
        $audScope = joma_db_bin_to_uuid((string)($row['aud_scope_id']??''));
        $audBasis = (string)($row['basis_kind']??'');
        $audRepRaw = $row['representation_id'] ?? null;
        $audRep = null;
        if ($audRepRaw !== null && $audRepRaw !== '') {
            $audRep = joma_db_bin_to_uuid((string)$audRepRaw);
            if ($audRep===null) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
        }
        $audRevoked = $row['aud_revoked'] ?? null;
        if ($audRevoked==='') $audRevoked=null;
        if ($audId===null||$audPub===null||$audRecipient===null||$audSubject===null||$audScope===null) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }

        $publication = [
            'id'=>$pubId,
            'resource_id'=>$resourceId,
            'version_id'=>$pubVersion,
            'scope_id'=>$pubScope,
            'channel'=>$pubChannel,
            'published_at'=>$pubPublished,
            'revoked_at'=>$pubRevoked,
        ];
        $audience = [
            'publication_id'=>$audPub,
            'recipient_person_id'=>$audRecipient,
            'access_subject_person_id'=>$audSubject,
            'scope_id'=>$audScope,
            'basis_kind'=>$audBasis,
            'representation_id'=>$audRep,
            'revoked_at'=>$audRevoked,
        ];

        // Load representation if needed
        $representation = null;
        $restrictions = null;
        if ($audBasis === 'REPRESENTATIVE') {
            if ($audRep===null) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
            $repBin = joma_db_uuid_to_bin($audRep);
            $stmt = joma_db_prepare($db,'SELECT id, representative_person_id, subject_person_id, scope_id, status, allowed_actions_json, valid_from, valid_until, revoked_at FROM joma_representations WHERE id = ? LIMIT 1');
            if ($stmt===null) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
            joma_db_bind_params($stmt,'s',[$repBin]);
            $stmt->execute();
            $res=$stmt->get_result();
            $repRow=$res?$res->fetch_assoc():null;
            @$stmt->close();
            if (!$repRow) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
            $repId = joma_db_bin_to_uuid((string)($repRow['id']??''));
            $repRep = joma_db_bin_to_uuid((string)($repRow['representative_person_id']??''));
            $repSub = joma_db_bin_to_uuid((string)($repRow['subject_person_id']??''));
            $repScope = joma_db_bin_to_uuid((string)($repRow['scope_id']??''));
            $repStatus = (string)($repRow['status']??'');
            $repActionsJson = (string)($repRow['allowed_actions_json']??'[]');
            $repValidFrom = $repRow['valid_from'] ?? null;
            $repValidUntil = $repRow['valid_until'] ?? null;
            $repRevoked = $repRow['revoked_at'] ?? null;
            if ($repId===null||$repRep===null||$repSub===null||$repScope===null) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
            $actions = joma_portal_parse_actions($repActionsJson);
            if ($actions===null) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
            $representation = [
                'id'=>$repId,
                'representative_person_id'=>$repRep,
                'subject_person_id'=>$repSub,
                'scope_id'=>$repScope,
                'status'=>$repStatus,
                'allowed_actions'=>$actions,
                'valid_from'=>$repValidFrom,
                'valid_until'=>$repValidUntil,
                'revoked_at'=>$repRevoked,
            ];
            // Load restrictions for this representation+case
            $repBin2 = joma_db_uuid_to_bin($audRep);
            $caseBin2 = joma_db_uuid_to_bin($caseId);
            $stmt = joma_db_prepare($db,'SELECT representation_id, case_id, service_id, status FROM joma_case_representation_restrictions WHERE representation_id = ? AND case_id = ?');
            if ($stmt===null) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
            joma_db_bind_params($stmt,'ss',[$repBin2,$caseBin2]);
            $stmt->execute();
            $res=$stmt->get_result();
            $restrictions=[];
            if ($res){
                while($rr=$res->fetch_assoc()){
                    $rRep=joma_db_bin_to_uuid((string)($rr['representation_id']??''));
                    $rCase=joma_db_bin_to_uuid((string)($rr['case_id']??''));
                    $rServiceRaw=$rr['service_id'] ?? null;
                    $rService=null;
                    if ($rServiceRaw!==null && $rServiceRaw!==''){
                        $rService=joma_db_int_unsigned((string)$rServiceRaw);
                        // If malformed, keep as string to trigger fail-closed in domain rule
                        if ($rService===null) { $rService=$rServiceRaw; }
                    }
                    $restrictions[]=[
                        'representation_id'=>$rRep?? (string)($rr['representation_id']??''),
                        'case_id'=>$rCase?? (string)($rr['case_id']??''),
                        'service_id'=>$rService,
                        'status'=>(string)($rr['status']??''),
                    ];
                }
            }
            @$stmt->close();
            // If no rows, restrictions = [] (empty set, not null)
        } elseif ($audBasis === 'DIRECT_PERSON') {
            $representation = null;
            $restrictions = null; // not needed for direct
        } else {
            return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE');
        }

        // Load entitlement if required
        $entitlement = null;
        $reqProd = $resource['required_product_id'] ?? null;
        if ($reqProd !== null) {
            if (!is_int($reqProd) || $reqProd <=0) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
            // Need to find entitlement for beneficiary == audSubject
            $subjectBin = joma_db_uuid_to_bin($audSubject);
            $stmt = joma_db_prepare($db,'SELECT product_id, beneficiary_person_id, source_verified, valid_from, valid_until, revoked_at FROM joma_product_entitlements WHERE product_id = ? AND beneficiary_person_id = ? LIMIT 1');
            // But product_id is INT, beneficiary is BINARY(16). Our mock expects s types but we can handle int as string.
            // We'll query with both s types for simplicity (mysqli will coerce).
            if ($stmt===null) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
            // For product_id, pass as string
            $prodStr=(string)$reqProd;
            joma_db_bind_params($stmt,'ss',[$prodStr,$subjectBin]);
            $stmt->execute();
            $res=$stmt->get_result();
            $entRow=$res?$res->fetch_assoc():null;
            @$stmt->close();
            if (!$entRow) { // no entitlement -> will be denied by domain rule, but we return deny now
                return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE');
            }
            $eProd = joma_db_int_unsigned((string)($entRow['product_id']??''));
            $eBenef = joma_db_bin_to_uuid((string)($entRow['beneficiary_person_id']??''));
            $eVerifiedRaw = $entRow['source_verified'] ?? $entRow['source_verified'] ?? null;
            // Support boolean as 1/0 or true
            $eVerified = ($eVerifiedRaw===1 || $eVerifiedRaw==='1' || $eVerifiedRaw===true);
            $eValidFrom = $entRow['valid_from'] ?? null;
            $eValidUntil = $entRow['valid_until'] ?? null;
            $eRevoked = $entRow['revoked_at'] ?? null;
            if ($eProd===null||$eBenef===null) { return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE'); }
            $entitlement = [
                'product_id'=>$eProd,
                'beneficiary_person_id'=>$eBenef,
                'source_verified'=>$eVerified,
                'valid_from'=>$eValidFrom,
                'valid_until'=>$eValidUntil,
                'revoked_at'=>$eRevoked,
            ];
        }

        // Delegate to domain rule for final decision (includes time, revocation, etc.)
        return joma_rule_portal_read($snapshot,$resource,$publication,$audience,$representation,$restrictions,$entitlement,$nowUtc);
    } catch (Throwable $e) {
        return joma_rule_result(false,'RESOURCE_NOT_AVAILABLE');
    }
}

function joma_private_note_check($snapshot, array $note): array {
    // Simple wrapper; no DB needed but we keep signature for consistency.
    return joma_rule_private_note_author($snapshot,$note);
}
