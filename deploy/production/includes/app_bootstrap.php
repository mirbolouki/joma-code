<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure database bootstrap
$liveDb = null; 
$liveMode = false;
$configFile = dirname(__DIR__) . '/joma-config.php';
if (is_file($configFile)) {
    require $configFile;
    if (isset($joma_config) && is_array($joma_config)) {
        require_once dirname(__DIR__) . '/joma-core/db.php';
        require_once dirname(__DIR__) . '/joma-core/domain_rules.php';
        require_once dirname(__DIR__) . '/joma-core/auth.php';
        require_once dirname(__DIR__) . '/joma-core/context.php';
        require_once dirname(__DIR__) . '/joma-core/acceptance.php';
        require_once dirname(__DIR__) . '/joma-core/hold.php';
        require_once dirname(__DIR__) . '/joma-core/forms.php';
        require_once dirname(__DIR__) . '/joma-core/clinical_session.php';
        require_once dirname(__DIR__) . '/joma-core/reports.php';
        require_once dirname(__DIR__) . '/joma-core/case_closure.php';
        require_once dirname(__DIR__) . '/joma-core/private_notes.php';
        require_once dirname(__DIR__) . '/joma-core/session.php';
        $tmp = joma_db_connect($joma_config);
        if ($tmp instanceof mysqli) {
            $liveDb = $tmp;
            $liveMode = true;
        }
    }
}

$demoUser = $_SESSION['joma_demo_user'] ?? null;
$principal = $_SESSION['joma_principal'] ?? null;
$snapshotCache = $_SESSION['joma_snapshot'] ?? null;

function joma_require_auth(): void {
    global $demoUser;
    if (!$demoUser) {
        header('Location: /login.php');
        exit;
    }
}

function joma_render_sidebar(string $activeRole = 'therapist'): void {
    global $demoUser;
    $name = htmlspecialchars($demoUser['name'] ?? 'کادر درمان');
?>
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="brand-icon">ج</div>
      <div class="brand-text">
        <h1>کلینیک جوما</h1>
        <p>مدیریت جامع سلامت روان</p>
      </div>
    </div>

    <nav class="sidebar-nav">
      <a href="/therapist/index.php" class="nav-item <?= $activeRole === 'therapist' ? 'active' : '' ?>">
        <span>🩺</span>
        <span>میز کار درمانگر</span>
      </a>
      <a href="/reception/index.php" class="nav-item <?= $activeRole === 'reception' ? 'active' : '' ?>">
        <span>📋</span>
        <span>پذیرش و تقویم</span>
      </a>
      <a href="/settings/index.php" class="nav-item <?= $activeRole === 'settings' ? 'active' : '' ?>">
        <span>⚙️</span>
        <span>تنظیمات کلینیک</span>
      </a>
      <a href="/portal/index.php" class="nav-item <?= $activeRole === 'portal' ? 'active' : '' ?>">
        <span>👤</span>
        <span>پورتال مراجعین</span>
      </a>
    </nav>

    <div class="sidebar-user">
      <div style="display:flex;align-items:center;gap:10px">
        <div class="user-avatar"><?= mb_substr($name, 0, 1, 'UTF-8') ?></div>
        <div class="user-details">
          <div style="font-size:13px;font-weight:700"><?= $name ?></div>
          <div style="font-size:11px;color:var(--text-muted)">آنلاین در سامانه</div>
        </div>
      </div>
      <a href="/login.php?logout=1" style="color:var(--danger);font-size:12px;text-decoration:none;font-weight:600">خروج</a>
    </div>
  </aside>
<?php
}

// Core Helper Aliases for robust UI operations
if (!function_exists('joma_uuid_to_bin')) {
    function joma_uuid_to_bin(string $uuid): ?string {
        if (function_exists('joma_db_uuid_to_bin')) return joma_db_uuid_to_bin($uuid);
        $clean = str_replace('-', '', $uuid);
        return (strlen($clean) === 32) ? hex2bin($clean) : null;
    }
}

if (!function_exists('joma_bin_to_uuid')) {
    function joma_bin_to_uuid(?string $bin): ?string {
        if ($bin === null || strlen($bin) !== 16) return null;
        if (function_exists('joma_db_bin_to_uuid')) {
            $res = joma_db_bin_to_uuid($bin);
            if ($res !== null) return $res;
        }
        $h = bin2hex($bin);
        return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20);
    }
}

if (!function_exists('joma_accept_assignment')) {
    function joma_accept_assignment($db, array $snapshot, string $cmdId, string $taId, string $purpose, string $nowUtc): array {
        if (function_exists('joma_acceptance_execute')) {
            return joma_acceptance_execute($db, $snapshot, $taId, $purpose, $nowUtc, $cmdId);
        }
        return ['ok' => false, 'code' => 'FUNCTION_NOT_FOUND'];
    }
}

// Safe Helper for hold creation matching exact 12-argument contract
if (!function_exists('joma_safe_hold_create')) {
    function joma_safe_hold_create($db, array $snapshot, string $caseId, string $startsAt, string $endsAt): array {
        if (!function_exists('joma_hold_create')) {
            return ['ok' => false, 'code' => 'HOLD_FUNCTION_NOT_FOUND', 'message' => 'تابع joma_hold_create یافت نشد.'];
        }

        $offRow = $db->query("SELECT id, scope_id FROM joma_service_offerings WHERE status='ACTIVE' LIMIT 1")->fetch_assoc();
        $offId = $offRow ? joma_bin_to_uuid($offRow['id']) : '00000000-0000-4000-8000-000000000001';
        $scopeBin = $offRow ? $offRow['scope_id'] : joma_uuid_to_bin('00000000-0000-4000-8000-000000000001');

        $polRow = $db->query("SELECT id FROM joma_service_policy_versions WHERE status='PUBLISHED' LIMIT 1")->fetch_assoc();
        if (!$polRow) {
            $polRow = $db->query("SELECT id FROM joma_service_policy_versions LIMIT 1")->fetch_assoc();
        }
        $polId = $polRow ? joma_bin_to_uuid($polRow['id']) : '00000000-0000-4000-8000-000000000001';

        $resRow = $db->query("SELECT id FROM joma_schedule_resources LIMIT 1")->fetch_assoc();
        $resId = $resRow ? joma_bin_to_uuid($resRow['id']) : '00000000-0000-4000-8000-000000000001';

        $nowTs = time();
        $heldAt = gmdate('Y-m-d H:i:s.000000', $nowTs);
        $expiresAt = gmdate('Y-m-d H:i:s.000000', $nowTs + 900); // exactly 15 minutes
        $nowUtc = $heldAt;
        $commandId = joma_uuid_v4();
        $cmdBin = joma_uuid_to_bin($commandId);

        // Pre-insert Command Receipt to satisfy foreign key constraint fk_joma_071:
        // FOREIGN KEY (`command_id`) REFERENCES `joma_command_receipts` (`id`)
        $actorId = $snapshot['account']['person_id'] ?? null;
        $actorBin = $actorId ? joma_uuid_to_bin($actorId) : null;
        $cmdKey = hash('sha256', $commandId . 'scheduling.hold', true);
        $cmdPayload = hash('sha256', $caseId . $startsAt . $endsAt, true);
        $cmdName = 'scheduling.hold';

        $stCmd = $db->prepare("INSERT INTO joma_command_receipts (id, scope_id, actor_person_id, command_name, idempotency_key, payload_hash, status, completed_at) VALUES (?, ?, ?, ?, ?, ?, 'SUCCEEDED', ?)");
        if ($stCmd) {
            $stCmd->bind_param('sssssss', $cmdBin, $scopeBin, $actorBin, $cmdName, $cmdKey, $cmdPayload, $nowUtc);
            @$stCmd->execute();
            $stCmd->close();
        }

        $sUtc = gmdate('Y-m-d H:i:s.000000', strtotime($startsAt));
        $eUtc = gmdate('Y-m-d H:i:s.000000', strtotime($endsAt));

        return joma_hold_create(
            $db,
            $snapshot,
            $caseId,
            $offId,
            $polId,
            $sUtc,
            $eUtc,
            $heldAt,
            $expiresAt,
            [$resId],
            $nowUtc,
            $commandId
        );
    }
}

/**
 * Hardened Defensive Helper: Ensures Work Scope, Membership, and Role Assignment
 * strictly adhering to composite foreign key CONSTRAINT `fk_joma_009`:
 * FOREIGN KEY (`membership_id`, `person_id`, `scope_id`) REFERENCES `joma_memberships` (`id`, `person_id`, `scope_id`)
 *
 * @param mysqli $liveDb
 * @param string $personBin   16-byte binary UUID of the person
 * @param string $accountBin  16-byte binary UUID of the account
 * @param string $roleCode    e.g. 'therapist', 'secretary', 'admin', 'psychometrist'
 * @return array ['ok' => bool, 'code' => string, 'role_assignment_id' => ?string, 'membership_id' => ?string]
 */
function joma_ensure_membership_and_assign_role(mysqli $liveDb, string $personBin, string $accountBin, string $roleCode): array {
    if (strlen($personBin) !== 16 || strlen($accountBin) !== 16) {
        throw new InvalidArgumentException('شناسه باینری فرد یا حساب کاربری معتبر نیست (باید ۱۶ بایت باشد).');
    }

    $cleanRole = strtolower(trim($roleCode));
    if ($cleanRole === '') {
        $cleanRole = 'therapist';
    }

    $roleLabels = [
        'admin' => 'مدیر ارشد',
        'secretary' => 'منشی و پذیرش',
        'psychometrist' => 'روان‌سنج / آزمون‌گر',
        'therapist' => 'درمانگر بالینی'
    ];
    $roleLabel = $roleLabels[$cleanRole] ?? 'درمانگر بالینی';

    // 1. Resolve or Create Role Definition via Prepared Statement
    $roleId = null;
    $stRole = $liveDb->prepare("SELECT id FROM joma_role_definitions WHERE code = ?");
    if (!$stRole) {
        throw new RuntimeException('خطای سیستمی در آماده‌سازی کوئری بررسی نقش: ' . $liveDb->error);
    }
    $stRole->bind_param('s', $cleanRole);
    $stRole->execute();
    $rRow = $stRole->get_result()->fetch_assoc();
    $stRole->close();

    if ($rRow && !empty($rRow['id'])) {
        $roleId = (int)$rRow['id'];
    } else {
        $stInsRole = $liveDb->prepare("INSERT INTO joma_role_definitions (code, label, is_active) VALUES (?, ?, 1)");
        if (!$stInsRole) {
            throw new RuntimeException('خطای سیستمی در آماده‌سازی ایجاد نقش جدید: ' . $liveDb->error);
        }
        $stInsRole->bind_param('ss', $cleanRole, $roleLabel);
        if (!$stInsRole->execute()) {
            $err = $stInsRole->error;
            $stInsRole->close();
            throw new RuntimeException('عدم موفقیت در ثبت نقش سیستمی جدید: ' . $err);
        }
        $roleId = (int)$liveDb->insert_id;
        $stInsRole->close();
    }

    // 2. Resolve or Create Work Scope (Center Scope) via Prepared Statement
    // Explicit initialization of $scBin to prevent undefined variable state
    $scBin = null;
    $scopeKind = 'CENTER';
    $stSc = $liveDb->prepare("SELECT id FROM joma_work_scopes WHERE kind = ?");
    if (!$stSc) {
        throw new RuntimeException('خطای سیستمی در جستجوی دامنه کاری کلینیک: ' . $liveDb->error);
    }
    $stSc->bind_param('s', $scopeKind);
    $stSc->execute();
    $scRow = $stSc->get_result()->fetch_assoc();
    $stSc->close();

    if ($scRow && !empty($scRow['id'])) {
        $scBin = $scRow['id'];
    } else {
        $scBin = joma_uuid_to_bin('00000000-0000-4000-8000-000000000001');
        $scLabel = 'کلینیک مرکزی ژوما';
        $insSc = $liveDb->prepare("INSERT INTO joma_work_scopes (id, kind, label, status) VALUES (?, ?, ?, 'ACTIVE')");
        if (!$insSc) {
            throw new RuntimeException('خطای سیستمی در ایجاد دامنه کاری کلینیک: ' . $liveDb->error);
        }
        // Note: 's' type in bind_param is standard for raw 16-byte binary strings in mysqli
        $insSc->bind_param('sss', $scBin, $scopeKind, $scLabel);
        if (!$insSc->execute()) {
            $err = $insSc->error;
            $insSc->close();
            throw new RuntimeException('عدم موفقیت در ثبت دامنه کاری کلینیک: ' . $err);
        }
        $insSc->close();
    }

    if ($scBin === null || strlen($scBin) !== 16) {
        throw new RuntimeException('خطای تعیین شناسه دامنه سازمانی کلینیک.');
    }

    // 3. Defensive Membership Resolution & Foreign Key Compliance (fk_joma_009)
    // CONSTRAINT `fk_joma_009` FOREIGN KEY (`membership_id`, `person_id`, `scope_id`)
    // REFERENCES `joma_memberships` (`id`, `person_id`, `scope_id`)
    $mBin = null;
    $stMem = $liveDb->prepare("SELECT id FROM joma_memberships WHERE person_id = ? AND scope_id = ?");
    if (!$stMem) {
        throw new RuntimeException('خطای سیستمی در جستجوی عضویت سازمانی پرسنل: ' . $liveDb->error);
    }
    $stMem->bind_param('ss', $personBin, $scBin);
    $stMem->execute();
    $memRow = $stMem->get_result()->fetch_assoc();
    $stMem->close();

    if ($memRow && !empty($memRow['id'])) {
        $mBin = $memRow['id'];
    } else {
        $mBin = joma_uuid_to_bin(joma_uuid_v4());
        $insM = $liveDb->prepare("INSERT INTO joma_memberships (id, person_id, scope_id, status, valid_from) VALUES (?, ?, ?, 'ACTIVE', NOW(6))");
        if (!$insM) {
            throw new RuntimeException('خطای سیستمی در آماده‌سازی عضویت پرسنل: ' . $liveDb->error);
        }
        $insM->bind_param('sss', $mBin, $personBin, $scBin);
        if (!$insM->execute()) {
            $err = $insM->error;
            $insM->close();
            throw new RuntimeException('خطای پایگاه داده در ایجاد عضویت سازمانی پرسنل: ' . $err);
        }
        $insM->close();
    }

    if ($mBin === null || strlen($mBin) !== 16) {
        throw new RuntimeException('خطای امنیتی: ایجاد یا یافتن شناسه عضویت معتبر برای انتساب نقش ناموفق بود.');
    }

    // 4. Check for Existing Role Assignment to avoid duplicate records
    $stCheckRa = $liveDb->prepare("SELECT id FROM joma_role_assignments WHERE account_id = ? AND role_id = ? AND scope_id = ?");
    if ($stCheckRa) {
        $stCheckRa->bind_param('sis', $accountBin, $roleId, $scBin);
        $stCheckRa->execute();
        $existingRa = $stCheckRa->get_result()->fetch_assoc();
        $stCheckRa->close();
        if ($existingRa && !empty($existingRa['id'])) {
            return [
                'ok' => true,
                'code' => 'ROLE_ALREADY_ASSIGNED',
                'role_assignment_id' => joma_bin_to_uuid($existingRa['id']),
                'membership_id' => joma_bin_to_uuid($mBin)
            ];
        }
    }

    // 5. Insert Role Assignment strictly satisfying composite FK fk_joma_009
    $raBin = joma_uuid_to_bin(joma_uuid_v4());
    $nowDt = date('Y-m-d H:i:s');
    $st3 = $liveDb->prepare("INSERT INTO joma_role_assignments (id, account_id, person_id, membership_id, scope_id, role_id, valid_from) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$st3) {
        throw new RuntimeException('خطای سیستمی در آماده‌سازی انتساب نقش: ' . $liveDb->error);
    }
    // Parameters: id (binary), account_id (binary), person_id (binary), membership_id (binary), scope_id (binary), role_id (int), valid_from (string)
    $st3->bind_param('sssssis', $raBin, $accountBin, $personBin, $mBin, $scBin, $roleId, $nowDt);
    if (!$st3->execute()) {
        $err = $st3->error;
        $st3->close();
        throw new RuntimeException('خطای پایگاه داده در انتساب نقش سازمانی به پرسنل: ' . $err);
    }
    $st3->close();

    return [
        'ok' => true,
        'code' => 'SUCCESS',
        'role_assignment_id' => joma_bin_to_uuid($raBin),
        'membership_id' => joma_bin_to_uuid($mBin)
    ];
}
