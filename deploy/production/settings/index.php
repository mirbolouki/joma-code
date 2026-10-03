<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/includes/app_bootstrap.php';
joma_require_auth();

$msg = null;

// Settings store for clinic bank accounts, per-therapist services, rates, buffer intervals, and absence rules
$settingsFile = dirname(__DIR__) . '/clinic_settings.json';

$weekDaysList = [
    'sat' => 'شنبه',
    'sun' => 'یک‌شنبه',
    'mon' => 'دوشنبه',
    'tue' => 'سه‌شنبه',
    'wed' => 'چهارشنبه',
    'thu' => 'پنج‌شنبه',
    'fri' => 'جمعه'
];

$defaultSettings = [
    'clinic_bank_accounts' => [
        [
            'id' => 'bank_pasargad_rezaei',
            'bank_name' => 'بانک پاسارگاد',
            'owner_name' => 'دکتر رضایی',
            'card_number' => '۵۰۲۲-۲۹۱۰-۸۸۷۲-۱۱۴۲',
            'shaba' => 'IR120570000000000000000001',
            'active' => true
        ],
        [
            'id' => 'bank_mellat_central',
            'bank_name' => 'بانک ملت',
            'owner_name' => 'کلینیک روان‌شناسی (مرکزی)',
            'card_number' => '۶۱۰۴-۳۳۷۸-۹۹۰۱-۲۳۴۵',
            'shaba' => 'IR980120000000000000000002',
            'active' => true
        ],
        [
            'id' => 'bank_melli_kazemi',
            'bank_name' => 'بانک ملی',
            'owner_name' => 'دکتر کاظمی',
            'card_number' => '۶۰۳۷-۹۹۷۵-۴۴۳۱-۹۹۸۰',
            'shaba' => 'IR550170000000000000000003',
            'active' => true
        ]
    ],
    'intake_reasons' => [
        ['id' => '1', 'title' => 'اضطراب، استرس و حملات پنیک', 'active' => true],
        ['id' => '2', 'title' => 'افسردگی، بی‌انگیزگی و خلق پایین', 'active' => true],
        ['id' => '3', 'title' => 'تعارضات زناشویی و روابط عاطفی', 'active' => true],
        ['id' => '4', 'title' => 'مشاوره تخصصی پیش از ازدواج', 'active' => true],
        ['id' => '5', 'title' => 'مسائل والدگری، کودک و نوجوان', 'active' => true],
        ['id' => '6', 'title' => 'وسواس فکری و عملی (OCD)', 'active' => true],
        ['id' => '7', 'title' => 'بحران سوگ، فقدان و تروما', 'active' => true],
        ['id' => '8', 'title' => 'ارزیابی روان‌شناختی و تست شخصیت', 'active' => true]
    ],
    'therapist_profiles' => [
        'demo-therapist' => [
            'name' => 'دکتر رضایی (متخصص بالینی)',
            'buffer_minutes' => 15,
            'assigned_bank_account_id' => 'bank_pasargad_rezaei',
            'absence_rules' => [
                ['day' => 'thu', 'from_hour' => '16', 'from_min' => '00', 'to_hour' => '21', 'to_min' => '00']
            ],
            'services' => [
                'individual' => ['title' => 'مشاوره فردی بزرگسال', 'duration' => 45, 'fee' => 850000, 'enabled' => true],
                'couple'     => ['title' => 'زوج‌درمانی و خانواده', 'duration' => 60, 'fee' => 1200000, 'enabled' => true],
                'premarital' => ['title' => 'مشاوره پیش از ازدواج', 'duration' => 60, 'fee' => 1100000, 'enabled' => true],
                'child'      => ['title' => 'روان‌شناسی کودک و نوجوان', 'duration' => 45, 'fee' => 900000, 'enabled' => false],
                'assessment' => ['title' => 'ارزیابی و تفسیر تست بالینی', 'duration' => 60, 'fee' => 1400000, 'enabled' => true]
            ]
        ]
    ]
];

$settings = $defaultSettings;
if (file_exists($settingsFile)) {
    $loaded = json_decode(file_get_contents($settingsFile), true);
    if (is_array($loaded)) {
        $settings = array_merge($defaultSettings, $loaded);
        if (!empty($loaded['clinic_bank_accounts'])) {
            $settings['clinic_bank_accounts'] = $loaded['clinic_bank_accounts'];
        }
    }
}

// Fetch Staff & Roles from Database
$staffList = [];
if ($liveDb) {
    try {
        $resStaff = $liveDb->query("
            SELECT p.id as person_id, p.given_name, p.family_name, a.login_name as username, a.status as acc_status, rd.code as role_code, rd.label as role_label
            FROM joma_accounts a
            JOIN joma_persons p ON p.id = a.person_id
            LEFT JOIN joma_role_assignments ra ON ra.account_id = a.id
            LEFT JOIN joma_role_definitions rd ON rd.id = ra.role_id
            ORDER BY a.created_at DESC
        ");
        if ($resStaff) {
            while ($st = $resStaff->fetch_assoc()) {
                $staffList[] = [
                    'person_id' => joma_bin_to_uuid($st['person_id']),
                    'name' => trim(($st['given_name'] ?? '') . ' ' . ($st['family_name'] ?? '')) ?: 'کاربر کلینیک',
                    'username' => $st['username'],
                    'role_code' => $st['role_code'] ?? 'therapist',
                    'role_label' => $st['role_label'] ?? 'درمانگر بالینی',
                    'status' => $st['acc_status']
                ];
            }
        }
    } catch (Throwable $e) {}
}

// Helper to label exact absence rules
function format_absence_rules(array $rules, array $weekDaysList): string {
    if (empty($rules)) {
        return 'حاضر در تمامی ساعات کاری';
    }
    $items = [];
    foreach ($rules as $r) {
        $dName = $weekDaysList[$r['day'] ?? ''] ?? ($r['day'] ?? 'روز');
        $fromH = str_pad((string)($r['from_hour'] ?? '08'), 2, '0', STR_PAD_LEFT);
        $fromM = str_pad((string)($r['from_min'] ?? '00'), 2, '0', STR_PAD_LEFT);
        $toH = str_pad((string)($r['to_hour'] ?? '21'), 2, '0', STR_PAD_LEFT);
        $toM = str_pad((string)($r['to_min'] ?? '00'), 2, '0', STR_PAD_LEFT);
        $items[] = "{$dName} ({$fromH}:{$fromM} تا {$toH}:{$toM})";
    }
    return implode(' ، ', $items);
}

// Helper to get bank account details by ID
function get_bank_details(string $accId, array $bankList): ?array {
    foreach ($bankList as $b) {
        if ($b['id'] === $accId) return $b;
    }
    return null;
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Create Staff / Therapist with Bank Account Selected Strictly from Dropdown
    if (isset($_POST['save_staff_user'])) {
        $fullName = trim($_POST['staff_name'] ?? '');
        $username = trim($_POST['staff_username'] ?? '');
        $password = trim($_POST['staff_password'] ?? '');
        $roleCode = trim($_POST['staff_role'] ?? 'therapist');

        if ($fullName === '' || $username === '' || $password === '') {
            $msg = ['type' => 'error', 'text' => 'تمامی فیلدها شامل نام، نام کاربری و رمز عبور الزامی هستند.'];
        } else {
            try {
                if ($liveDb) {
                    $liveDb->begin_transaction();
                    $pId = joma_uuid_v4();
                    $pBin = joma_uuid_to_bin($pId);
                    $parts = explode(' ', $fullName, 2);
                    $gName = $parts[0];
                    $fName = $parts[1] ?? '';

                    // Insert Person
                    $st1 = $liveDb->prepare("INSERT INTO joma_persons (id, given_name, family_name, status) VALUES (?, ?, ?, 'ACTIVE')");
                    $st1->bind_param('sss', $pBin, $gName, $fName);
                    $st1->execute();
                    $st1->close();

                    // Insert Account
                    $accId = joma_uuid_v4();
                    $accBin = joma_uuid_to_bin($accId);
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $st2 = $liveDb->prepare("INSERT INTO joma_accounts (id, person_id, login_name, password_hash, status) VALUES (?, ?, ?, ?, 'ACTIVE')");
                    $st2->bind_param('ssss', $accBin, $pBin, $username, $hash);
                    $st2->execute();
                    $st2->close();

                    // 3. Delegate Scope, Membership, and Role Assignment to Hardened Helper
                    // Enforces composite foreign key constraint `fk_joma_009` atomically
                    $assignRes = joma_ensure_membership_and_assign_role($liveDb, $pBin, $accBin, $roleCode);
                    if (!($assignRes['ok'] ?? false)) {
                        throw new Exception($assignRes['message'] ?? 'خطا در انتساب نقش سازمانی.');
                    }

                    // Dedicated Per-Service Fees from Dropdowns
                    $srvIndividual = (int)($_POST['fee_individual'] ?? 850000);
                    $durIndividual = (int)($_POST['dur_individual'] ?? 45);
                    $srvCouple     = (int)($_POST['fee_couple'] ?? 1200000);
                    $durCouple     = (int)($_POST['dur_couple'] ?? 60);
                    $srvPremarital = (int)($_POST['fee_premarital'] ?? 1100000);
                    $durPremarital = (int)($_POST['dur_premarital'] ?? 60);
                    $srvChild      = (int)($_POST['fee_child'] ?? 900000);
                    $durChild      = (int)($_POST['dur_child'] ?? 45);
                    $srvAssess     = (int)($_POST['fee_assessment'] ?? 1400000);
                    $durAssess     = (int)($_POST['dur_assessment'] ?? 60);

                    // Absence rule if selected
                    $absenceRules = [];
                    if (!empty($_POST['init_absence_enable'])) {
                        $absenceRules[] = [
                            'day' => $_POST['init_abs_day'] ?? 'thu',
                            'from_hour' => $_POST['init_abs_from_h'] ?? '16',
                            'from_min' => $_POST['init_abs_from_m'] ?? '00',
                            'to_hour' => $_POST['init_abs_to_h'] ?? '21',
                            'to_min' => $_POST['init_abs_to_m'] ?? '00'
                        ];
                    }

                    $chosenBankAccId = trim($_POST['therapist_bank_id'] ?? '');

                    $settings['therapist_profiles'][$username] = [
                        'name' => $fullName,
                        'buffer_minutes' => (int)($_POST['therapist_buffer'] ?? 15),
                        'assigned_bank_account_id' => $chosenBankAccId,
                        'absence_rules' => $absenceRules,
                        'services' => [
                            'individual' => ['title' => 'مشاوره فردی بزرگسال', 'duration' => $durIndividual, 'fee' => $srvIndividual, 'enabled' => isset($_POST['enable_individual'])],
                            'couple'     => ['title' => 'زوج‌درمانی و خانواده', 'duration' => $durCouple, 'fee' => $srvCouple, 'enabled' => isset($_POST['enable_couple'])],
                            'premarital' => ['title' => 'مشاوره پیش از ازدواج', 'duration' => $durPremarital, 'fee' => $srvPremarital, 'enabled' => isset($_POST['enable_premarital'])],
                            'child'      => ['title' => 'روان‌شناسی کودک و نوجوان', 'duration' => $durChild, 'fee' => $srvChild, 'enabled' => isset($_POST['enable_child'])],
                            'assessment' => ['title' => 'ارزیابی و تفسیر تست بالینی', 'duration' => $durAssess, 'fee' => $srvAssess, 'enabled' => isset($_POST['enable_assessment'])]
                        ]
                    ];
                    file_put_contents($settingsFile, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

                    $liveDb->commit();
                    $msg = ['type' => 'success', 'text' => "درمانگر «{$fullName}» با موفقیت ثبت و نقش او تخصیص یافت."];
                }
            } catch (Throwable $e) {
                if ($liveDb) $liveDb->rollback();
                $msg = ['type' => 'error', 'text' => 'خطا در ثبت کاربر: ' . $e->getMessage()];
            }
        }
    }

    // 2. Add New Clinic / Therapist Bank Account to the Master Financial Directory
    elseif (isset($_POST['save_new_bank_account'])) {
        $bName = trim($_POST['bank_select_name'] ?? 'بانک پاسارگاد');
        $bOwner = trim($_POST['bank_owner_name'] ?? '');
        $c1 = str_pad(trim($_POST['card_p1'] ?? '0000'), 4, '0', STR_PAD_LEFT);
        $c2 = str_pad(trim($_POST['card_p2'] ?? '0000'), 4, '0', STR_PAD_LEFT);
        $c3 = str_pad(trim($_POST['card_p3'] ?? '0000'), 4, '0', STR_PAD_LEFT);
        $c4 = str_pad(trim($_POST['card_p4'] ?? '0000'), 4, '0', STR_PAD_LEFT);
        $fullCard = "{$c1}-{$c2}-{$c3}-{$c4}";

        if ($bOwner === '') {
            $msg = ['type' => 'error', 'text' => 'نام صاحب حساب بانکی الزامی است.'];
        } else {
            $newAccId = 'bank_' . time() . '_' . mt_rand(100, 999);
            $settings['clinic_bank_accounts'][] = [
                'id' => $newAccId,
                'bank_name' => $bName,
                'owner_name' => $bOwner,
                'card_number' => $fullCard,
                'active' => true
            ];
            file_put_contents($settingsFile, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $msg = ['type' => 'success', 'text' => "حساب مالی جدید به نام «{$bOwner}» در دایرکتوری مالی ثبت شد و در دراپ‌داون تمام درمانگران قرار گرفت."];
        }
    }

    // 3. Add Absence Rule to Selected Therapist
    elseif (isset($_POST['add_absence_rule'])) {
        $thUser = $_POST['target_therapist_user'] ?? '';
        if ($thUser !== '' && isset($settings['therapist_profiles'][$thUser])) {
            $newRule = [
                'day' => $_POST['abs_day'] ?? 'sat',
                'from_hour' => $_POST['abs_from_hour'] ?? '08',
                'from_min' => $_POST['abs_from_min'] ?? '00',
                'to_hour' => $_POST['abs_to_hour'] ?? '14',
                'to_min' => $_POST['abs_to_min'] ?? '00'
            ];
            if (!isset($settings['therapist_profiles'][$thUser]['absence_rules'])) {
                $settings['therapist_profiles'][$thUser]['absence_rules'] = [];
            }
            $settings['therapist_profiles'][$thUser]['absence_rules'][] = $newRule;
            file_put_contents($settingsFile, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $msg = ['type' => 'success', 'text' => 'بازه زمانی عدم حضور با موفقیت اضافه شد.'];
        }
    }

    // 4. Remove an Absence Rule
    elseif (isset($_POST['remove_absence_rule'])) {
        $thUser = $_POST['target_therapist_user'] ?? '';
        $rIdx = (int)($_POST['rule_index'] ?? -1);
        if ($thUser !== '' && isset($settings['therapist_profiles'][$thUser]['absence_rules'][$rIdx])) {
            array_splice($settings['therapist_profiles'][$thUser]['absence_rules'], $rIdx, 1);
            file_put_contents($settingsFile, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $msg = ['type' => 'success', 'text' => 'بازه زمانی عدم حضور حذف گردید.'];
        }
    }

    // 5. Update Existing Specific Therapist Configuration (Zero Typing - Bank strictly from Dropdown)
    elseif (isset($_POST['save_specific_therapist'])) {
        $thUser = $_POST['target_therapist_user'] ?? '';
        if ($thUser !== '' && isset($settings['therapist_profiles'][$thUser])) {
            $settings['therapist_profiles'][$thUser]['buffer_minutes'] = (int)$_POST['th_buffer'];
            $settings['therapist_profiles'][$thUser]['assigned_bank_account_id'] = trim($_POST['th_bank_account_id'] ?? '');

            // Per-Service Fees Update
            $postedServices = $_POST['services'] ?? [];
            foreach (['individual', 'couple', 'premarital', 'child', 'assessment'] as $sKey) {
                if (isset($postedServices[$sKey])) {
                    $settings['therapist_profiles'][$thUser]['services'][$sKey]['fee'] = (int)($postedServices[$sKey]['fee'] ?? 0);
                    $settings['therapist_profiles'][$thUser]['services'][$sKey]['duration'] = (int)($postedServices[$sKey]['duration'] ?? 45);
                    $settings['therapist_profiles'][$thUser]['services'][$sKey]['enabled'] = isset($postedServices[$sKey]['enabled']);
                }
            }

            file_put_contents($settingsFile, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $msg = ['type' => 'success', 'text' => "تنظیمات مالی و تعرفه درمانگر «{$thUser}» به‌روزرسانی شد."];
        }
    }

    // 6. Reasons Toggle & Save
    elseif (isset($_POST['save_reason'])) {
        $newTitle = trim($_POST['reason_title'] ?? '');
        if ($newTitle !== '') {
            $settings['intake_reasons'][] = [
                'id' => (string)time(),
                'title' => $newTitle,
                'active' => true
            ];
            file_put_contents($settingsFile, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $msg = ['type' => 'success', 'text' => "علت جدید «{$newTitle}» افزوده شد."];
        }
    } elseif (isset($_POST['toggle_reason'])) {
        $rid = $_POST['reason_id'] ?? '';
        foreach ($settings['intake_reasons'] as &$r) {
            if ($r['id'] === $rid) {
                $r['active'] = !$r['active'];
                break;
            }
        }
        file_put_contents($settingsFile, json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $msg = ['type' => 'success', 'text' => 'وضعیت گزینه با موفقیت تغییر کرد.'];
    }
}

// Current Selected Therapist for View/Edit Tab
$selectedTherapistKey = $_GET['th'] ?? array_key_first($settings['therapist_profiles']) ?? 'demo-therapist';
$currentTh = $settings['therapist_profiles'][$selectedTherapistKey] ?? null;
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تنظیمات کلینیک و اطلاعات مالی درمانگران — جوما</title>
<link rel="stylesheet" href="/assets/css/joma-theme.css">
<style>
.service-row {
  display: flex;
  align-items: center;
  gap: 12px;
  background: var(--bg-card);
  border: 1px solid var(--border);
  padding: 10px 14px;
  border-radius: var(--radius-md);
  margin-bottom: 8px;
}
.service-row.disabled {
  opacity: 0.6;
  background: #f8fafc;
}
.rule-pill-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #fff;
  border: 1px solid var(--border);
  padding: 10px 14px;
  border-radius: var(--radius-sm);
  margin-bottom: 8px;
}
</style>
</head>
<body>

<div class="app-shell">
  <?php joma_render_sidebar('settings'); ?>

  <main class="main-content">
    
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:28px">
      <div>
        <h2 style="font-size:22px;font-weight:800;color:var(--text-main)">تنظیمات کلینیک و اطلاعات مالی درمانگران</h2>
        <p style="font-size:13px;color:var(--text-muted)">مدیریت حساب‌های بانکی معتبر، تخصیص انحصاری به درمانگران از طریق دراپ‌داون، و ماتریس تعرفه‌ها</p>
      </div>
      <div style="display:flex;gap:10px">
        <button class="btn btn-primary" onclick="openModal('addStaffModal')">
          <span>🩺</span>
          <span>افزودن درمانگر جدید با تعرفه خدمات</span>
        </button>
        <button class="btn btn-outline" onclick="openModal('addBankModal')">
          <span>💳</span>
          <span>افزودن حساب بانکی به دایرکتوری مالی</span>
        </button>
      </div>
    </div>

    <?php if ($msg): ?>
      <div style="background:<?= $msg['type'] === 'success' ? 'var(--success-bg)' : 'var(--danger-bg)' ?>;color:<?= $msg['type'] === 'success' ? 'var(--success-text)' : 'var(--danger-text)' ?>;border:1px solid <?= $msg['type'] === 'success' ? 'var(--success-border)' : 'var(--danger-border)' ?>;padding:14px 18px;border-radius:var(--radius-md);margin-bottom:24px;font-weight:600">
        <?= htmlspecialchars($msg['text']) ?>
      </div>
    <?php endif; ?>

    <!-- 1. Registered Bank Accounts Directory (Single Source of Truth) -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">
          <span>💳 دایرکتوری حساب‌ها و کارت‌های بانکی ثبت‌شده (منبع دراپ‌داون‌ها)</span>
        </h3>
        <span class="pill pill-info"><?= count($settings['clinic_bank_accounts']) ?> حساب معتبر</span>
      </div>
      <table class="modern-table">
        <thead>
          <tr>
            <th>نام بانک</th>
            <th>صاحب حساب</th>
            <th>شماره کارت</th>
            <th>شناسه سیستمی</th>
            <th>وضعیت جهت انتخاب</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($settings['clinic_bank_accounts'] as $b): ?>
            <tr>
              <td><strong><?= htmlspecialchars($b['bank_name']) ?></strong></td>
              <td><?= htmlspecialchars($b['owner_name']) ?></td>
              <td><code style="font-size:13px;font-weight:700"><?= htmlspecialchars($b['card_number']) ?></code></td>
              <td><span style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($b['id']) ?></span></td>
              <td><span class="pill pill-success">فعال در دراپ‌داون‌ها</span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- 2. Staff & Therapist Management Table -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">
          <span>👥 لیست درمانگران و پرسنل کلینیک</span>
        </h3>
        <span class="pill pill-info"><?= count($staffList) ?> کاربر فعال</span>
      </div>
      <table class="modern-table">
        <thead>
          <tr>
            <th>نام درمانگر / پرسنل</th>
            <th>نام کاربری</th>
            <th>حساب بانکی متصل (خوانده‌شده از دایرکتوری)</th>
            <th>تعرفه‌های فعال درمانگر</th>
            <th>زمان استراحت و ساعات عدم حضور</th>
            <th>عملیات</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($staffList)): ?>
            <tr>
              <td colspan="6" style="text-align:center;padding:20px;color:var(--text-muted)">هیچ کاربری یافت نشد. با دکمه بالا درمانگر جدید ثبت فرمایید.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($staffList as $st): ?>
              <?php 
                $thProf = $settings['therapist_profiles'][$st['username']] ?? null; 
                $bankInfo = $thProf ? get_bank_details($thProf['assigned_bank_account_id'] ?? '', $settings['clinic_bank_accounts']) : null;
              ?>
              <tr>
                <td><strong><?= htmlspecialchars($st['name']) ?></strong></td>
                <td><code><?= htmlspecialchars($st['username']) ?></code></td>
                <td>
                  <?php if ($bankInfo): ?>
                    <div style="font-size:12px;line-height:1.6">
                      🏦 <strong><?= htmlspecialchars($bankInfo['bank_name']) ?></strong> (<?= htmlspecialchars($bankInfo['owner_name']) ?>)<br>
                      💳 <code style="font-size:11px;font-weight:700"><?= htmlspecialchars($bankInfo['card_number']) ?></code>
                    </div>
                  <?php else: ?>
                    <span style="font-size:12px;color:var(--text-muted)">حساب عمومی کلینیک / نامشخص</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($thProf && !empty($thProf['services'])): ?>
                    <div style="font-size:12px;line-height:1.6">
                      <?php foreach ($thProf['services'] as $s): ?>
                        <?php if ($s['enabled'] ?? true): ?>
                          <div>• <?= htmlspecialchars($s['title']) ?> (<?= $s['duration'] ?> دقیقه): <strong><?= number_format($s['fee']) ?> تومان</strong></div>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <span style="font-size:12px;color:var(--text-muted)">پرسنل اداری</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($thProf): ?>
                    <div style="font-size:12px;line-height:1.6">
                      ⏱️ استراحت: <strong><?= $thProf['buffer_minutes'] ?> دقیقه</strong><br>
                      ⛔ عدم حضور: <span class="pill pill-warning" style="font-size:11px"><?= format_absence_rules($thProf['absence_rules'] ?? [], $weekDaysList) ?></span>
                    </div>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($thProf): ?>
                    <a href="?th=<?= urlencode($st['username']) ?>#editSection" class="btn btn-outline" style="padding:6px 12px;font-size:12px">
                      ✏️ مدیریت مالی و تعرفه
                    </a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- 3. Individual Therapist Configuration (Bank Account Strictly Dropdown) -->
    <?php if ($currentTh): ?>
    <div class="card" id="editSection">
      <div class="card-header">
        <h3 class="card-title">
          <span>🩺 اتصال حساب بانکی و تعرفه خدمات «<?= htmlspecialchars($currentTh['name'] ?? $selectedTherapistKey) ?>»</span>
        </h3>
        <span class="pill pill-success">نام کاربری: <?= htmlspecialchars($selectedTherapistKey) ?></span>
      </div>

      <!-- Absence Periods Management -->
      <div style="background:#f8fafc;padding:16px;border:1px solid var(--border);border-radius:var(--radius-md);margin-bottom:24px">
        <div style="font-weight:700;color:var(--text-main);font-size:14px;margin-bottom:4px">
          ⛔ روزها و ساعات مشخص عدم حضور درمانگر در کلینیک:
        </div>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:12px">این بازه‌ها در تقویم نوبت‌دهی مسدود (خاکستری ⚪) می‌شوند.</p>
        
        <?php $rules = $currentTh['absence_rules'] ?? []; ?>
        <?php if (empty($rules)): ?>
          <div style="font-size:12px;color:var(--text-muted);padding:8px 0">درمانگر در تمامی ساعات کاری حاضر است.</div>
        <?php else: ?>
          <?php foreach ($rules as $idx => $r): ?>
            <div class="rule-pill-item">
              <div>
                🗓️ <strong><?= $weekDaysList[$r['day'] ?? ''] ?? $r['day'] ?></strong> : 
                از ساعت <strong><?= str_pad((string)$r['from_hour'], 2, '0', STR_PAD_LEFT) ?>:<?= str_pad((string)$r['from_min'], 2, '0', STR_PAD_LEFT) ?></strong>
                تا ساعت <strong><?= str_pad((string)$r['to_hour'], 2, '0', STR_PAD_LEFT) ?>:<?= str_pad((string)$r['to_min'], 2, '0', STR_PAD_LEFT) ?></strong>
              </div>
              <form method="post" style="margin:0">
                <input type="hidden" name="target_therapist_user" value="<?= htmlspecialchars($selectedTherapistKey) ?>">
                <input type="hidden" name="rule_index" value="<?= $idx ?>">
                <button type="submit" name="remove_absence_rule" value="1" class="btn btn-outline" style="padding:4px 10px;font-size:11px;color:var(--danger)">
                  حذف این بازه
                </button>
              </form>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <!-- Form to Add Exact Absence Period -->
        <form method="post" style="margin-top:14px;background:#fff;padding:12px;border:1px solid var(--border);border-radius:var(--radius-sm)">
          <input type="hidden" name="target_therapist_user" value="<?= htmlspecialchars($selectedTherapistKey) ?>">
          <div style="font-size:12px;font-weight:700;margin-bottom:8px;color:var(--primary)">➕ ثبت بازه زمانی عدم حضور:</div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <div style="flex:1;min-width:130px">
              <label style="font-size:11px;color:var(--text-muted)">روز هفته:</label>
              <select class="form-control" name="abs_day" style="padding:6px">
                <?php foreach ($weekDaysList as $dK => $dV): ?><option value="<?= $dK ?>"><?= $dV ?></option><?php endforeach; ?>
              </select>
            </div>
            <div style="display:flex;align-items:center;gap:4px">
              <label style="font-size:11px;color:var(--text-muted)">از:</label>
              <select class="form-control" name="abs_from_hour" style="padding:6px;width:70px">
                <?php for ($h = 8; $h <= 22; $h++): ?><option value="<?= $h ?>" <?= $h===16?'selected':'' ?>><?= sprintf('%02d', $h) ?></option><?php endfor; ?>
              </select>
              <span>:</span>
              <select class="form-control" name="abs_from_min" style="padding:6px;width:70px">
                <option value="00">۰۰</option><option value="15">۱۵</option><option value="30">۳۰</option><option value="45">۴۵</option>
              </select>
            </div>
            <div style="display:flex;align-items:center;gap:4px">
              <label style="font-size:11px;color:var(--text-muted)">تا:</label>
              <select class="form-control" name="abs_to_hour" style="padding:6px;width:70px">
                <?php for ($h = 8; $h <= 22; $h++): ?><option value="<?= $h ?>" <?= $h===21?'selected':'' ?>><?= sprintf('%02d', $h) ?></option><?php endfor; ?>
              </select>
              <span>:</span>
              <select class="form-control" name="abs_to_min" style="padding:6px;width:70px">
                <option value="00">۰۰</option><option value="15">۱۵</option><option value="30">۳۰</option><option value="45">۴۵</option>
              </select>
            </div>
            <button type="submit" name="add_absence_rule" value="1" class="btn btn-outline" style="padding:7px 14px;font-size:12px;margin-top:16px">
              ثبت بازه
            </button>
          </div>
        </form>
      </div>

      <!-- Financial Account & Services Form -->
      <form method="post">
        <input type="hidden" name="target_therapist_user" value="<?= htmlspecialchars($selectedTherapistKey) ?>">
        
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px">
          <div class="form-group">
            <label class="form-label">فاصله استراحت اختصاصی بین جلسات (بافر هوشمند):</label>
            <select class="form-control" name="th_buffer">
              <option value="0" <?= ($currentTh['buffer_minutes']??15)===0?'selected':'' ?>>بدون فاصله (پشت سر هم)</option>
              <option value="10" <?= ($currentTh['buffer_minutes']??15)===10?'selected':'' ?>>۱۰ دقیقه استراحت</option>
              <option value="15" <?= ($currentTh['buffer_minutes']??15)===15?'selected':'' ?>>۱۵ دقیقه استراحت (استاندارد کلینیک)</option>
              <option value="20" <?= ($currentTh['buffer_minutes']??15)===20?'selected':'' ?>>۲۰ دقیقه استراحت</option>
              <option value="30" <?= ($currentTh['buffer_minutes']??15)===30?'selected':'' ?>>۳۰ دقیقه استراحت</option>
            </select>
          </div>

          <!-- Pure Dropdown for Bank Selection (Strictly Read from Directory) -->
          <div class="form-group">
            <label class="form-label">حساب بانکی متصل جهت پیامک تسویه مراجع (انتخاب از دراپ‌داون):</label>
            <select class="form-control" name="th_bank_account_id" style="font-weight:700">
              <?php foreach ($settings['clinic_bank_accounts'] as $b): ?>
                <option value="<?= htmlspecialchars($b['id']) ?>" <?= ($currentTh['assigned_bank_account_id']??'')===$b['id']?'selected':'' ?>>
                  <?= htmlspecialchars($b['bank_name']) ?> — به نام <?= htmlspecialchars($b['owner_name']) ?> (کارت: <?= htmlspecialchars($b['card_number']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <h4 style="font-size:15px;font-weight:700;color:var(--text-main);margin:24px 0 12px;border-bottom:1px solid var(--border);padding-bottom:8px">
          💵 جدول خدمات و تعرفه‌های مصوب این درمانگر:
        </h4>

        <?php
          $servicesTemplate = [
            'individual' => ['title' => 'مشاوره فردی بزرگسال', 'default_dur' => 45, 'default_fee' => 850000],
            'couple'     => ['title' => 'زوج‌درمانی و خانواده‌درمانی', 'default_dur' => 60, 'default_fee' => 1200000],
            'premarital' => ['title' => 'مشاوره تخصصی پیش از ازدواج', 'default_dur' => 60, 'default_fee' => 1100000],
            'child'      => ['title' => 'روان‌شناسی کودک و بازی‌درمانی', 'default_dur' => 45, 'default_fee' => 900000],
            'assessment' => ['title' => 'ارزیابی جامع و تفسیر تست بالینی', 'default_dur' => 60, 'default_fee' => 1400000],
          ];
        ?>

        <?php foreach ($servicesTemplate as $sKey => $sDef): ?>
          <?php 
            $sVal = $currentTh['services'][$sKey] ?? [
              'title' => $sDef['title'], 
              'duration' => $sDef['default_dur'], 
              'fee' => $sDef['default_fee'], 
              'enabled' => true
            ]; 
          ?>
          <div class="service-row <?= empty($sVal['enabled']) ? 'disabled' : '' ?>">
            <div style="flex:0 0 40px;text-align:center">
              <input type="checkbox" name="services[<?= $sKey ?>][enabled]" value="1" <?= !empty($sVal['enabled']) ? 'checked' : '' ?> style="transform:scale(1.2)">
            </div>
            <div style="flex:1">
              <strong><?= htmlspecialchars($sDef['title']) ?></strong>
            </div>
            <div style="flex:0 0 160px;display:flex;align-items:center;gap:6px">
              <label style="font-size:12px;color:var(--text-muted)">مدت جلسه:</label>
              <select class="form-control" name="services[<?= $sKey ?>][duration]" style="padding:6px">
                <option value="30" <?= ($sVal['duration']??45)==30?'selected':'' ?>>۳۰ دقیقه</option>
                <option value="45" <?= ($sVal['duration']??45)==45?'selected':'' ?>>۴۵ دقیقه</option>
                <option value="60" <?= ($sVal['duration']??45)==60?'selected':'' ?>>۶۰ دقیقه</option>
                <option value="90" <?= ($sVal['duration']??45)==90?'selected':'' ?>>۹۰ دقیقه</option>
              </select>
            </div>
            <div style="flex:0 0 240px;display:flex;align-items:center;gap:6px">
              <label style="font-size:12px;color:var(--text-muted)">تعرفه خدمت:</label>
              <select class="form-control" name="services[<?= $sKey ?>][fee]" style="padding:6px;font-weight:700">
                <?php 
                  $feesOptions = [600000, 750000, 850000, 950000, 1000000, 1100000, 1200000, 1300000, 1400000, 1500000, 1600000, 1800000, 2000000];
                  $currFee = (int)($sVal['fee'] ?? $sDef['default_fee']);
                  if (!in_array($currFee, $feesOptions)) $feesOptions[] = $currFee;
                  sort($feesOptions);
                  foreach ($feesOptions as $fOpt):
                ?>
                  <option value="<?= $fOpt ?>" <?= $currFee===$fOpt?'selected':'' ?>><?= number_format($fOpt) ?> تومان</option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
        <?php endforeach; ?>

        <button class="btn btn-primary" name="save_specific_therapist" value="1" style="margin-top:16px">
          💾 ذخیره تنظیمات مالی و تعرفه این درمانگر
        </button>
      </form>
    </div>
    <?php endif; ?>

  </main>
</div>

<!-- Modal 1: Add Staff with Bank Strictly from Directory Dropdown -->
<div class="modal-overlay" id="addStaffModal">
  <div class="modal-box" style="max-width:760px">
    <div class="modal-header">
      <h3>افزودن درمانگر جدید و اتصال به حساب بانکی</h3>
      <button class="btn-close" onclick="closeModal('addStaffModal')">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="form-group">
            <label class="form-label">نام و نام خانوادگی درمانگر:</label>
            <input class="form-control" name="staff_name" placeholder="مثال: دکتر مهدی کاظمی" required autofocus>
          </div>
          <div class="form-group">
            <label class="form-label">نام کاربری ورود (انگلیسی):</label>
            <input class="form-control" name="staff_username" placeholder="dr-kazemi" required>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="form-group">
            <label class="form-label">رمز عبور ورود:</label>
            <input class="form-control" name="staff_password" type="password" placeholder="••••••••" required>
          </div>
          <div class="form-group">
            <label class="form-label">نقش در کلینیک:</label>
            <select class="form-control" name="staff_role" required>
              <option value="therapist">🩺 درمانگر بالینی (دارای تقویم، استراحت و تعرفه مجزا)</option>
              <option value="secretary">📋 منشی و مسئول پذیرش</option>
              <option value="psychometrist">📊 روان‌سنج / ارزیاب بالینی</option>
              <option value="admin">⚙️ مدیر ارشد کلینیک</option>
            </select>
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;background:#f8fafc;padding:12px;border-radius:var(--radius-md);margin-bottom:14px">
          <div>
            <label class="form-label" style="font-size:11px">فاصله استراحت اختصاصی بین جلسات:</label>
            <select class="form-control" name="therapist_buffer" style="padding:6px">
              <option value="0">بدون فاصله</option>
              <option value="10">۱۰ دقیقه</option>
              <option value="15" selected>۱۵ دقیقه استراحت</option>
              <option value="20">۲۰ دقیقه</option>
              <option value="30">۳۰ دقیقه</option>
            </select>
          </div>
          <div>
            <label class="form-label" style="font-size:11px">حساب بانکی تسویه مراجع (انتخاب از دراپ‌داون):</label>
            <select class="form-control" name="therapist_bank_id" style="padding:6px;font-weight:700">
              <?php foreach ($settings['clinic_bank_accounts'] as $b): ?>
                <option value="<?= htmlspecialchars($b['id']) ?>">
                  <?= htmlspecialchars($b['bank_name']) ?> — <?= htmlspecialchars($b['owner_name']) ?> (<?= htmlspecialchars($b['card_number']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Absence Picker -->
        <div style="background:#f1f5f9;border:1px solid #cbd5e1;padding:12px;border-radius:var(--radius-md);margin-bottom:14px">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
            <input type="checkbox" name="init_absence_enable" id="init_abs_chk" value="1" style="transform:scale(1.2)">
            <label for="init_abs_chk" style="font-weight:700;color:var(--text-main);font-size:12px;cursor:pointer">
              تعیین روز و ساعات عدم حضور درمانگر در کلینیک:
            </label>
          </div>
          
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <div style="flex:1;min-width:110px">
              <select class="form-control" name="init_abs_day" style="padding:6px">
                <?php foreach ($weekDaysList as $dK => $dV): ?><option value="<?= $dK ?>"><?= $dV ?></option><?php endforeach; ?>
              </select>
            </div>
            <div style="display:flex;align-items:center;gap:4px">
              <span style="font-size:11px;color:var(--text-muted)">از:</span>
              <select class="form-control" name="init_abs_from_h" style="padding:6px;width:65px">
                <?php for ($h = 8; $h <= 22; $h++): ?><option value="<?= $h ?>" <?= $h===16?'selected':'' ?>><?= sprintf('%02d', $h) ?></option><?php endfor; ?>
              </select>
              <span>:</span>
              <select class="form-control" name="init_abs_from_m" style="padding:6px;width:65px">
                <option value="00">۰۰</option><option value="15">۱۵</option><option value="30">۳۰</option><option value="45">۴۵</option>
              </select>
            </div>
            <div style="display:flex;align-items:center;gap:4px">
              <span style="font-size:11px;color:var(--text-muted)">تا:</span>
              <select class="form-control" name="init_abs_to_h" style="padding:6px;width:65px">
                <?php for ($h = 8; $h <= 22; $h++): ?><option value="<?= $h ?>" <?= $h===21?'selected':'' ?>><?= sprintf('%02d', $h) ?></option><?php endfor; ?>
              </select>
              <span>:</span>
              <select class="form-control" name="init_abs_to_m" style="padding:6px;width:65px">
                <option value="00">۰۰</option><option value="15">۱۵</option><option value="30">۳۰</option><option value="45">۴۵</option>
              </select>
            </div>
          </div>
        </div>

        <div style="background:var(--primary-light);border:1px solid var(--primary-border);padding:14px;border-radius:var(--radius-md)">
          <div style="font-weight:800;color:var(--primary);font-size:13px;margin-bottom:8px">
            💵 تعرفه و مدت هر نوع خدمت (انتخاب از دراپ‌داون):
          </div>

          <!-- Individual -->
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
            <input type="checkbox" name="enable_individual" value="1" checked style="transform:scale(1.2)">
            <span style="flex:1;font-size:13px;font-weight:600">مشاوره فردی بزرگسال</span>
            <select class="form-control" name="dur_individual" style="width:105px;padding:4px">
              <option value="30">۳۰ دقیقه</option>
              <option value="45" selected>۴۵ دقیقه</option>
              <option value="60">۶۰ دقیقه</option>
            </select>
            <select class="form-control" name="fee_individual" style="width:160px;padding:4px;font-weight:700">
              <option value="600000">۶۰۰,۰۰۰ تومان</option>
              <option value="750000">۷۵۰,۰۰۰ تومان</option>
              <option value="850000" selected>۸۵۰,۰۰۰ تومان</option>
              <option value="950000">۹۵۰,۰۰۰ تومان</option>
              <option value="1100000">۱,۱۰۰,۰۰۰ تومان</option>
            </select>
          </div>

          <!-- Couple -->
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
            <input type="checkbox" name="enable_couple" value="1" checked style="transform:scale(1.2)">
            <span style="flex:1;font-size:13px;font-weight:600">زوج‌درمانی و خانواده</span>
            <select class="form-control" name="dur_couple" style="width:105px;padding:4px">
              <option value="45">۴۵ دقیقه</option>
              <option value="60" selected>۶۰ دقیقه</option>
              <option value="90">۹۰ دقیقه</option>
            </select>
            <select class="form-control" name="fee_couple" style="width:160px;padding:4px;font-weight:700">
              <option value="950000">۹۵۰,۰۰۰ تومان</option>
              <option value="1100000">۱,۱۰۰,۰۰۰ تومان</option>
              <option value="1200000" selected>۱,۲۰۰,۰۰۰ تومان</option>
              <option value="1400000">۱,۴۰۰,۰۰۰ تومان</option>
              <option value="1600000">۱,۶۰۰,۰۰۰ تومان</option>
            </select>
          </div>

          <!-- Premarital -->
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
            <input type="checkbox" name="enable_premarital" value="1" checked style="transform:scale(1.2)">
            <span style="flex:1;font-size:13px;font-weight:600">مشاوره پیش از ازدواج</span>
            <select class="form-control" name="dur_premarital" style="width:105px;padding:4px">
              <option value="45">۴۵ دقیقه</option>
              <option value="60" selected>۶۰ دقیقه</option>
            </select>
            <select class="form-control" name="fee_premarital" style="width:160px;padding:4px;font-weight:700">
              <option value="950000">۹۵۰,۰۰۰ تومان</option>
              <option value="1100000" selected>۱,۱۰۰,۰۰۰ تومان</option>
              <option value="1200000">۱,۲۰۰,۰۰۰ تومان</option>
              <option value="1400000">۱,۴۰۰,۰۰۰ تومان</option>
            </select>
          </div>

          <!-- Child -->
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
            <input type="checkbox" name="enable_child" value="1" style="transform:scale(1.2)">
            <span style="flex:1;font-size:13px;font-weight:600">روان‌شناسی کودک و نوجوان</span>
            <select class="form-control" name="dur_child" style="width:105px;padding:4px">
              <option value="30">۳۰ دقیقه</option>
              <option value="45" selected>۴۵ دقیقه</option>
              <option value="60">۶۰ دقیقه</option>
            </select>
            <select class="form-control" name="fee_child" style="width:160px;padding:4px;font-weight:700">
              <option value="800000">۸۰۰,۰۰۰ تومان</option>
              <option value="900000" selected>۹۰۰,۰۰۰ تومان</option>
              <option value="1000000">۱,۰۰۰,۰۰۰ تومان</option>
              <option value="1200000">۱,۲۰۰,۰۰۰ تومان</option>
            </select>
          </div>

          <!-- Assessment -->
          <div style="display:flex;align-items:center;gap:10px">
            <input type="checkbox" name="enable_assessment" value="1" checked style="transform:scale(1.2)">
            <span style="flex:1;font-size:13px;font-weight:600">ارزیابی و تست تشخیصی</span>
            <select class="form-control" name="dur_assessment" style="width:105px;padding:4px">
              <option value="45">۴۵ دقیقه</option>
              <option value="60" selected>۶۰ دقیقه</option>
              <option value="90">۹۰ دقیقه</option>
            </select>
            <select class="form-control" name="fee_assessment" style="width:160px;padding:4px;font-weight:700">
              <option value="1100000">۱,۱۰۰,۰۰۰ تومان</option>
              <option value="1250000">۱,۲۵۰,۰۰۰ تومان</option>
              <option value="1400000" selected>۱,۴۰۰,۰۰۰ تومان</option>
              <option value="1600000">۱,۶۰۰,۰۰۰ تومان</option>
              <option value="1800000">۱,۸۰۰,۰۰۰ تومان</option>
            </select>
          </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px">
          <button type="button" class="btn btn-outline" onclick="closeModal('addStaffModal')">انصراف</button>
          <button type="submit" class="btn btn-primary" name="save_staff_user" value="1">ثبت درمانگر و اتصال حساب بانکی</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal 2: Add Bank Account to Clinic Financial Directory -->
<div class="modal-overlay" id="addBankModal">
  <div class="modal-box">
    <div class="modal-header">
      <h3>افزودن حساب بانکی جدید به دایرکتوری مالی کلینیک</h3>
      <button class="btn-close" onclick="closeModal('addBankModal')">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post">
        <div class="form-group">
          <label class="form-label">نام بانک:</label>
          <select class="form-control" name="bank_select_name" required>
            <option value="بانک پاسارگاد">بانک پاسارگاد</option>
            <option value="بانک ملت">بانک ملت</option>
            <option value="بانک ملی">بانک ملی</option>
            <option value="بانک سامان">بانک سامان</option>
            <option value="بانک پارسیان">بانک پارسیان</option>
            <option value="بانک تجارت">بانک تجارت</option>
            <option value="بانک صادرات">بانک صادرات</option>
            <option value="بانک آینده">بانک آینده</option>
            <option value="بانک رسالت">بانک قرض‌الحسنه رسالت</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">صاحب حساب (انتخاب از بین پرسنل و درمانگران فعال کلینیک):</label>
          <select class="form-control" name="bank_owner_name" required>
            <option value="حساب مرکزی کلینیک">🏥 حساب مرکزی کلینیک</option>
            <?php foreach ($staffList as $st): ?>
              <option value="<?= htmlspecialchars($st['name']) ?> (<?= htmlspecialchars($st['role_label']) ?>)">
                👤 <?= htmlspecialchars($st['name']) ?> — <?= htmlspecialchars($st['role_label']) ?> (<?= htmlspecialchars($st['username']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">شماره ۱۶ رقمی کارت بانکی:</label>
          <div style="display:flex;gap:6px;direction:ltr">
            <input class="form-control" name="card_p1" maxlength="4" placeholder="۶۰۳۷" style="text-align:center;font-weight:700" required>
            <input class="form-control" name="card_p2" maxlength="4" placeholder="۹۹۷۵" style="text-align:center;font-weight:700" required>
            <input class="form-control" name="card_p3" maxlength="4" placeholder="۴۴۳۱" style="text-align:center;font-weight:700" required>
            <input class="form-control" name="card_p4" maxlength="4" placeholder="۹۹۸۰" style="text-align:center;font-weight:700" required>
          </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px">
          <button type="button" class="btn btn-outline" onclick="closeModal('addBankModal')">انصراف</button>
          <button type="submit" class="btn btn-primary" name="save_new_bank_account" value="1">ثبت در دایرکتوری مالی</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="/assets/js/joma-app.js"></script>
</body>
</html>
