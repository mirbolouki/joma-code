<?php
declare(strict_types=1);
/**
 * JOMA Quick Setup — One-click setup that guarantees an open admission and active test data.
 * Run: https://my.mirbolouki.com/quick-setup.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$configFile = __DIR__ . '/joma-config.php';
if (!is_file($configFile)) {
    die("خطا: فایل joma-config.php پیدا نشد.");
}
require $configFile;
$cfg = $joma_config;

$host = $cfg['host'] ?? 'localhost';
$user = $cfg['user'] ?? ($cfg['username'] ?? '');
$pass = $cfg['pass'] ?? ($cfg['password'] ?? '');
$dbName = $cfg['name'] ?? ($cfg['database'] ?? '');

$db = new mysqli($host, $user, $pass, $dbName);
if ($db->connect_error) {
    die("خطا در اتصال دیتابیس: " . $db->connect_error);
}
$db->set_charset('utf8mb4');

function gen_uuid(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 15) | 64);
    $b[8] = chr((ord($b[8]) & 63) | 128);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
}
function to_bin(string $uuid): string {
    return hex2bin(str_replace('-', '', $uuid));
}

echo "<div style='font-family:tahoma,sans-serif;direction:rtl;padding:20px;line-height:2'>";
echo "<h2>🛠️ راه‌اندازی سریع و تضمینی داده‌های کلینیک (Quick Setup)</h2>";

// 1. Role
$r = $db->query("SELECT id FROM joma_role_definitions WHERE code='therapist' LIMIT 1");
if ($r && $row = $r->fetch_assoc()) { $roleId = (int)$row['id']; }
else { $db->query("INSERT INTO joma_role_definitions (code,label,is_active) VALUES ('therapist','Therapist',1)"); $roleId = (int)$db->insert_id; }

// 2. Service
$r = $db->query("SELECT id FROM joma_service_definitions WHERE code='individual' LIMIT 1");
if ($r && $row = $r->fetch_assoc()) { $serviceId = (int)$row['id']; }
else { $db->query("INSERT INTO joma_service_definitions (code,label,is_active) VALUES ('individual','Individual',1)"); $serviceId = (int)$db->insert_id; }

// 3. Scope
$r = $db->query("SELECT id FROM joma_work_scopes WHERE label='کلینیک مرکزی' LIMIT 1");
if ($r && $row = $r->fetch_assoc()) { $scopeBin = $row['id']; }
else { $scopeBin = to_bin(gen_uuid()); $stmt = $db->prepare("INSERT INTO joma_work_scopes (id,kind,label,status) VALUES (?,'CENTER','کلینیک مرکزی','ACTIVE')"); $stmt->bind_param('s', $scopeBin); $stmt->execute(); $stmt->close(); }

// 4. Persons
function getOrCreatePerson(mysqli $db, string $given, string $family): string {
    $stmt = $db->prepare("SELECT id FROM joma_persons WHERE given_name=? AND family_name=? LIMIT 1");
    $stmt->bind_param('ss', $given, $family); $stmt->execute(); $res = $stmt->get_result(); $row = $res ? $res->fetch_assoc() : null; $stmt->close();
    if ($row) return $row['id'];
    $bin = to_bin(gen_uuid());
    $stmt = $db->prepare("INSERT INTO joma_persons (id,given_name,family_name) VALUES (?,?,?)");
    $stmt->bind_param('sss', $bin, $given, $family); $stmt->execute(); $stmt->close();
    return $bin;
}
$therapistBin = getOrCreatePerson($db, 'درمانگر', 'تست');
$subjectBin = getOrCreatePerson($db, 'مریم', 'احمدی (مراجع تست)');
$adminBin = getOrCreatePerson($db, 'ادمین', 'تست');

// 5. Account demo-therapist / Demo1234!
$stmt = $db->prepare("SELECT id FROM joma_accounts WHERE login_name='demo-therapist' LIMIT 1");
$stmt->execute(); $res = $stmt->get_result(); $row = $res ? $res->fetch_assoc() : null; $stmt->close();
$pwdHash = password_hash('Demo1234!', PASSWORD_DEFAULT);
if ($row) {
    $accountBin = $row['id'];
    $stmt = $db->prepare("UPDATE joma_accounts SET password_hash=?, status='ACTIVE', person_id=? WHERE id=?");
    $stmt->bind_param('sss', $pwdHash, $therapistBin, $accountBin); $stmt->execute(); $stmt->close();
} else {
    $accountBin = to_bin(gen_uuid());
    $stmt = $db->prepare("INSERT INTO joma_accounts (id,person_id,login_name,password_hash,status) VALUES (?,?,'demo-therapist',?,'ACTIVE')");
    $stmt->bind_param('sss', $accountBin, $therapistBin, $pwdHash); $stmt->execute(); $stmt->close();
}

// 6. Membership
$stmt = $db->prepare("SELECT id FROM joma_memberships WHERE person_id=? AND scope_id=? LIMIT 1");
$stmt->bind_param('ss', $therapistBin, $scopeBin); $stmt->execute(); $res = $stmt->get_result(); $row = $res ? $res->fetch_assoc() : null; $stmt->close();
if ($row) { $membershipBin = $row['id']; }
else {
    $membershipBin = to_bin(gen_uuid()); $now = gmdate('Y-m-d H:i:s.000000');
    $stmt = $db->prepare("INSERT INTO joma_memberships (id,person_id,scope_id,status,valid_from) VALUES (?,?,?,'ACTIVE',?)");
    $stmt->bind_param('ssss', $membershipBin, $therapistBin, $scopeBin, $now); $stmt->execute(); $stmt->close();
}

// 7. Role Assignment
$stmt = $db->prepare("SELECT id FROM joma_role_assignments WHERE account_id=? AND scope_id=? AND role_id=? LIMIT 1");
$stmt->bind_param('ssi', $accountBin, $scopeBin, $roleId); $stmt->execute(); $res = $stmt->get_result(); $row = $res ? $res->fetch_assoc() : null; $stmt->close();
if (!$row) {
    $raBin = to_bin(gen_uuid()); $now = gmdate('Y-m-d H:i:s.000000');
    $stmt = $db->prepare("INSERT INTO joma_role_assignments (id,account_id,person_id,membership_id,scope_id,role_id,valid_from) VALUES (?,?,?,?,?,?,?)");
    $stmt->bind_param('sssssis', $raBin, $accountBin, $therapistBin, $membershipBin, $scopeBin, $roleId, $now); $stmt->execute(); $stmt->close();
}

// 8. Schedule Resource (Therapist Room)
$stmt = $db->prepare("SELECT id FROM joma_schedule_resources WHERE therapist_person_id=? LIMIT 1");
$stmt->bind_param('s', $therapistBin); $stmt->execute(); $res = $stmt->get_result(); $row = $res ? $res->fetch_assoc() : null; $stmt->close();
if (!$row) {
    $resBin = to_bin(gen_uuid());
    $stmt = $db->prepare("INSERT INTO joma_schedule_resources (id,therapist_person_id,kind,label) VALUES (?,?,'THERAPIST','اتاق درمانگر تست')");
    $stmt->bind_param('ss', $resBin, $therapistBin); $stmt->execute(); $stmt->close();
}

// 9. Policy Version
$stmt = $db->prepare("SELECT id FROM joma_service_policy_versions WHERE service_id=? AND version_no=1 LIMIT 1");
$stmt->bind_param('i', $serviceId); $stmt->execute(); $res = $stmt->get_result(); $row = $res ? $res->fetch_assoc() : null; $stmt->close();
if ($row) { $policyBin = $row['id']; }
else {
    $policyBin = to_bin(gen_uuid()); $now = gmdate('Y-m-d H:i:s.000000');
    $policyJson = json_encode(['v'=>1], JSON_UNESCAPED_UNICODE);
    $ver = 1; $status = 'PUBLISHED';
    $stmt = $db->prepare("INSERT INTO joma_service_policy_versions (id,service_id,version_no,status,policy_json,author_person_id,approved_by_person_id,effective_at) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->bind_param('siisssss', $policyBin, $serviceId, $ver, $status, $policyJson, $adminBin, $adminBin, $now);
    $stmt->execute(); $stmt->close();
}

// 10. Offering
$stmt = $db->prepare("SELECT id FROM joma_service_offerings WHERE scope_id=? LIMIT 1");
$stmt->bind_param('s', $scopeBin); $stmt->execute(); $res = $stmt->get_result(); $row = $res ? $res->fetch_assoc() : null; $stmt->close();
if ($row) { $offeringBin = $row['id']; }
else {
    $offeringBin = to_bin(gen_uuid());
    $stmt = $db->prepare("INSERT INTO joma_service_offerings (id,scope_id,service_id,policy_version_id,duration_minutes,status) VALUES (?,?,?,?,60,'ACTIVE')");
    $stmt->bind_param('ssis', $offeringBin, $scopeBin, $serviceId, $policyBin); $stmt->execute(); $stmt->close();
}

// 11. Form Template & Version
$stmt = $db->prepare("SELECT id FROM joma_form_templates WHERE label='فرم پذیرش اولیه' LIMIT 1");
$stmt->execute(); $res = $stmt->get_result(); $row = $res ? $res->fetch_assoc() : null; $stmt->close();
if ($row) { $formTplBin = $row['id']; }
else {
    $formTplBin = to_bin(gen_uuid());
    $stmt = $db->prepare("INSERT INTO joma_form_templates (id,scope_id,label,form_kind) VALUES (?,?,?,'ADMINISTRATIVE')");
    $lbl = 'فرم پذیرش اولیه';
    $stmt->bind_param('sss', $formTplBin, $scopeBin, $lbl); $stmt->execute(); $stmt->close();
}
$stmt = $db->prepare("SELECT id FROM joma_form_versions WHERE template_id=? AND version_no=1 LIMIT 1");
$stmt->bind_param('s', $formTplBin); $stmt->execute(); $res = $stmt->get_result(); $row = $res ? $res->fetch_assoc() : null; $stmt->close();
if (!$row) {
    $formVerBin = to_bin(gen_uuid());
    $schemaJson = json_encode(['q1'=>['type'=>'text','label'=>'شکایت اصلی'],'q2'=>['type'=>'text','label'=>'سابقه درمان']], JSON_UNESCAPED_UNICODE);
    $now = gmdate('Y-m-d H:i:s.000000');
    $stmt = $db->prepare("INSERT INTO joma_form_versions (id,template_id,version_no,schema_json,status,author_person_id,published_at) VALUES (?,?,1,?,'PUBLISHED',?,?)");
    $stmt->bind_param('sssss', $formVerBin, $formTplBin, $schemaJson, $adminBin, $now);
    $stmt->execute(); $stmt->close();
}

// 12. ALWAYS CREATE A FRESH OPEN ADMISSION & ASSIGNMENT FOR THIS THERAPIST!
$admissionBin = to_bin(gen_uuid());
$stmt = $db->prepare("INSERT INTO joma_admissions (id,primary_subject_id,scope_id,offering_id,recorded_by_person_id,status) VALUES (?,?,?,?,?,'AWAITING_THERAPIST')");
$stmt->bind_param('sssss', $admissionBin, $subjectBin, $scopeBin, $offeringBin, $adminBin);
$stmt->execute(); $stmt->close();

$assignBin = to_bin(gen_uuid());
$stmt = $db->prepare("INSERT INTO joma_therapist_assignments (id,admission_id,therapist_person_id,assigned_by_person_id,status) VALUES (?,?,?,?,'ASSIGNED')");
$stmt->bind_param('ssss', $assignBin, $admissionBin, $therapistBin, $adminBin);
$stmt->execute(); $stmt->close();

echo "<p style='color:green;font-weight:bold;font-size:16px;'>✓ داده‌های اولیه با موفقیت آماده شدند!</p>";
echo "<p>یک درخواست پذیرش جدید برای مراجع <b>مریم احمدی</b> با وضعیت <code>AWAITING_THERAPIST</code> ثبت شد.</p>";
echo "<p><a href='index.php' style='display:inline-block;background:#0f62fe;color:#fff;padding:12px 24px;border-radius:10px;text-decoration:none;font-weight:bold;'>← ورود به پنل اصلی کلینیک (اکنون دکمه‌ها فعال هستند)</a></p>";
echo "</div>";
