<?php
declare(strict_types=1);

/**
 * JOMA Webhook Endpoint: Test Subdomain Integration Bridge
 * 
 * Target: https://my.mirbolouki.com/api/test_webhook.php
 * Receives psychometric assessment results from test.mirbolouki.com
 * Automatically matches or creates subject via phone number,
 * and links the diagnostic report directly into the client's clinical case!
 */

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/includes/app_bootstrap.php';

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    // Also support multipart/form-data
    $data = $_POST;
}

$clientMobile = trim($data['mobile'] ?? ($data['phone'] ?? ''));
$testName = trim($data['test_name'] ?? 'تست روان‌سنجی');
$scoreRaw = $data['score'] ?? null;
$interpretation = trim($data['interpretation'] ?? ($data['summary'] ?? ''));
$authToken = trim($_SERVER['HTTP_X_JOMA_TOKEN'] ?? ($data['token'] ?? ''));

// Validate Mobile
$cleanMobile = preg_replace('/[^\d]/', '', $clientMobile);
if (strlen($cleanMobile) === 10 && str_starts_with($cleanMobile, '9')) {
    $cleanMobile = '0' . $cleanMobile;
}

if (!preg_match('/^09\d{9}$/', $cleanMobile)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'code' => 'INVALID_MOBILE', 'message' => 'شماره همراه مراجع نامعتبر است.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$liveDb) {
    echo json_encode([
        'ok' => true,
        'simulated' => true,
        'message' => 'نتیجه تست مراجع دریافت و به صورت شبیه‌سازی ثبت شد (دیتابیس در دسترس نبود).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $liveDb->begin_transaction();

    // 1. Locate Person by mobile
    $stmtP = $liveDb->prepare("
        SELECT p.id, p.given_name, p.family_name 
        FROM joma_persons p
        WHERE p.family_name = ?
        LIMIT 1
    ");
    $stmtP->bind_param('s', $cleanMobile);
    $stmtP->execute();
    $personRow = $stmtP->get_result()->fetch_assoc();
    $stmtP->close();

    $personId = null;
    if ($personRow) {
        $personId = joma_bin_to_uuid($personRow['id']);
    } else {
        // Create new person record for this test taker
        $personId = joma_uuid_v4();
        $pBin = joma_uuid_to_bin($personId);
        $insP = $liveDb->prepare("INSERT INTO joma_persons (id, given_name, family_name, status) VALUES (?, 'مراجع آزمون', ?, 'ACTIVE')");
        $insP->bind_param('ss', $pBin, $cleanMobile);
        $insP->execute();
        $insP->close();
    }

    // 2. Find active clinical case or create intake record
    $caseId = null;
    $pBin = joma_uuid_to_bin($personId);
    $stmtC = $liveDb->prepare("
        SELECT c.id 
        FROM joma_clinical_cases c
        JOIN joma_admissions a ON a.case_id = c.id
        WHERE a.primary_subject_id = ? AND c.status = 'ACTIVE'
        ORDER BY c.opened_at DESC LIMIT 1
    ");
    $stmtC->bind_param('s', $pBin);
    $stmtC->execute();
    $caseRow = $stmtC->get_result()->fetch_assoc();
    $stmtC->close();

    if ($caseRow) {
        $caseId = joma_bin_to_uuid($caseRow['id']);
    }

    // 3. Store Diagnostic Assessment Report Version
    $reportText = "【نتیجه خودکار ساب‌دامین تست mirbolouki.com】\n" .
                  "عنوان آزمون: {$testName}\n" .
                  "نمره مراجع: " . json_encode($scoreRaw, JSON_UNESCAPED_UNICODE) . "\n" .
                  "تفسیر اولیه سامانه: {$interpretation}\n" .
                  "تاریخ ثبت سیستمی: " . date('Y-m-d H:i:s');

    $caseBin = $caseId ? joma_uuid_to_bin($caseId) : null;

    // 1. Ensure Assessment Record
    $asBin = joma_uuid_to_bin(joma_uuid_v4());
    $insAs = $liveDb->prepare("INSERT INTO joma_assessments (id, case_id, subject_person_id, instrument_reference, status_code) VALUES (?, ?, ?, ?, 'COMPLETED')");
    $insAs->bind_param('ssss', $asBin, $caseBin, $pBin, $testName);
    $insAs->execute();
    $insAs->close();

    // 2. Ensure Assessment Report Parent Record
    $repBin = joma_uuid_to_bin(joma_uuid_v4());
    $insRep = $liveDb->prepare("INSERT INTO joma_assessment_reports (id, assessment_id, case_id) VALUES (?, ?, ?)");
    $insRep->bind_param('sss', $repBin, $asBin, $caseBin);
    $insRep->execute();
    $insRep->close();

    // 3. Insert Version
    $reportVersionId = joma_uuid_v4();
    $rvBin = joma_uuid_to_bin($reportVersionId);
    $authorBin = $pBin;

    $stmtR = $liveDb->prepare("INSERT INTO joma_report_versions (id, report_id, case_id, version_no, author_person_id, uploaded_by_person_id, report_text, status) VALUES (?, ?, ?, 1, ?, ?, ?, 'REVIEWED')");
    $stmtR->bind_param('ssssss', $rvBin, $repBin, $caseBin, $authorBin, $authorBin, $reportText);
    $stmtR->execute();
    $stmtR->close();

    $liveDb->commit();

    echo json_encode([
        'ok' => true,
        'code' => 'REPORT_LINKED',
        'person_id' => $personId,
        'case_id' => $caseId,
        'report_version_id' => $reportVersionId,
        'message' => 'پاسخ تست روان‌شناسی با موفقیت دریافت و مستقیماً در پرونده مراجع ثبت گردید.'
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    $liveDb->rollback();
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'code' => 'DATABASE_ERROR',
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
