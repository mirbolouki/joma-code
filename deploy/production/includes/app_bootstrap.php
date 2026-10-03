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
            return ['ok' => false, 'code' => 'HOLD_FUNCTION_NOT_FOUND'];
        }

        $offRow = $db->query("SELECT id FROM joma_service_offerings LIMIT 1")->fetch_assoc();
        $offId = $offRow ? joma_bin_to_uuid($offRow['id']) : '00000000-0000-4000-8000-000000000001';

        $polRow = $db->query("SELECT id FROM joma_service_policy_versions WHERE status='PUBLISHED' LIMIT 1")->fetch_assoc();
        $polId = $polRow ? joma_bin_to_uuid($polRow['id']) : '00000000-0000-4000-8000-000000000001';

        $resRow = $db->query("SELECT id FROM joma_schedule_resources LIMIT 1")->fetch_assoc();
        $resId = $resRow ? joma_bin_to_uuid($resRow['id']) : '00000000-0000-4000-8000-000000000001';

        $nowTs = time();
        $heldAt = gmdate('Y-m-d H:i:s.000000', $nowTs);
        $expiresAt = gmdate('Y-m-d H:i:s.000000', $nowTs + 900); // 15 mins
        $nowUtc = $heldAt;
        $commandId = joma_uuid_v4();

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
