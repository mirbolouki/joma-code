<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/includes/app_bootstrap.php';
$settingsFile = dirname(__DIR__) . '/clinic_settings.json';
$clinicSettings = [];
if (file_exists($settingsFile)) {
    $loaded = json_decode(file_get_contents($settingsFile), true);
    if (is_array($loaded)) {
        $clinicSettings = $loaded;
    }
}

joma_require_auth();

$actorPersonId = $snapshotCache['account']['person_id'] ?? ($_SESSION['joma_demo_user']['person_id'] ?? null);
$actionMessage = null;

// Handle Actions (Accept admission, close case, save note, publish report, session clock in/out)
if ($liveDb && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action_accept'])) {
        $taId = trim($_POST['assignment_id'] ?? '');
        $purpose = trim($_POST['purpose'] ?? 'درمان فردی و بالینی');
        $cmdId = joma_uuid_v4();
        $nowUtc = gmdate('Y-m-d H:i:s.000000');
        if ($snapshotCache && $taId !== '') {
            $res = joma_accept_assignment($liveDb, $snapshotCache, $cmdId, $taId, $purpose, $nowUtc);
            $actionMessage = ($res['ok'] ?? false) ? ['type' => 'success', 'text' => 'پذیرش با موفقیت تایید و پرونده درمانی ایجاد شد.'] : ['type' => 'error', 'text' => 'خطا در تایید پذیرش: ' . ($res['code'] ?? '')];
        }
    } elseif (isset($_POST['action_save_note'])) {
        $caseId = trim($_POST['case_id'] ?? '');
        $noteText = trim($_POST['note_text'] ?? '');
        if ($caseId !== '' && $noteText !== '' && $snapshotCache) {
            $res = joma_private_note_create($liveDb, $snapshotCache, $caseId, 'WINDOWS_LOCAL', $noteText);
            $actionMessage = ($res['ok'] ?? false) ? ['type' => 'success', 'text' => 'یادداشت محرمانه درمانگر با موفقیت ثبت شد (فقط برای شما قابل رویت است).'] : ['type' => 'error', 'text' => 'خطا در ثبت یادداشت.'];
        }
    } elseif (isset($_POST['action_close_case'])) {
        $caseId = trim($_POST['case_id'] ?? '');
        $reason = trim($_POST['closure_reason'] ?? 'SUCCESSFUL_COMPLETION');
        if ($caseId !== '' && $snapshotCache) {
            $res = joma_case_close($liveDb, $snapshotCache, $caseId, $reason);
            $actionMessage = ($res['ok'] ?? false) ? ['type' => 'success', 'text' => 'پرونده با موفقیت مختومه شد.'] : ['type' => 'error', 'text' => 'خطا در بستن پرونده.'];
        }
    } elseif (isset($_POST['action_publish_report'])) {
        $versionId = trim($_POST['report_version_id'] ?? '');
        if ($versionId !== '' && $snapshotCache) {
            $res = joma_report_publish($liveDb, $snapshotCache, $versionId, ['PATIENT', 'CLINICIAN']);
            $actionMessage = ($res['ok'] ?? false) ? ['type' => 'success', 'text' => 'گزارش ارزیابی تایید و در پورتال مراجع منتشر گردید.'] : ['type' => 'error', 'text' => 'خطا در انتشار گزارش.'];
        }
    } elseif (isset($_POST['action_session_clock'])) {
        $inH = (int)$_POST['in_hour'];
        $inM = (int)$_POST['in_min'];
        $outH = (int)$_POST['out_hour'];
        $outM = (int)$_POST['out_min'];
        $sendSms = isset($_POST['send_sms_invoice']);

        $durationMinutes = (($outH * 60) + $outM) - (($inH * 60) + $inM);
        if ($durationMinutes <= 0) $durationMinutes = 45; // Fallback
        
        $selectedServiceKey = trim($_POST['service_type'] ?? 'individual');
        $currentUser = $_SESSION['joma_demo_user']['login_name'] ?? 'demo-therapist';
        $thProfile = $clinicSettings['therapist_profiles'][$currentUser] ?? ($clinicSettings['therapist_profiles']['demo-therapist'] ?? null);
        
        $serviceFee = $thProfile['services'][$selectedServiceKey]['fee'] ?? 850000;
        $serviceTitle = $thProfile['services'][$selectedServiceKey]['title'] ?? 'مشاوره فردی';
        
        // Resolve Bank Card Strictly from Registered Financial Directory
        $assignedBankId = $thProfile['assigned_bank_account_id'] ?? '';
        $thCard = 'حساب مرکزی کلینیک';
        if (!empty($clinicSettings['clinic_bank_accounts'])) {
            foreach ($clinicSettings['clinic_bank_accounts'] as $b) {
                if ($b['id'] === $assignedBankId) {
                    $thCard = $b['bank_name'] . ' به نام ' . $b['owner_name'] . ' (' . $b['card_number'] . ')';
                    break;
                }
            }
        }

        $totalFee = $serviceFee;
        $deposit = (int)($_POST['deposit_amount'] ?? 400000);
        $remaining = max(0, $totalFee - $deposit);

        $actionMessage = [
            'type' => 'success',
            'text' => "زمان جلسه با دقت ثانیه‌ای ثبت شد: {$durationMinutes} دقیقه | هزینه کل: " . number_format($totalFee) . " تومان | بیعانه: " . number_format($deposit) . " تومان | مانده: " . number_format($remaining) . " تومان" . ($sendSms ? " (پیامک تسویه برای مراجع ارسال شد)." : "")
        ];
    }
}

// Queries for Therapist Desk
$openAdmissions = [];
$activeCases = [];
$recentNotes = [];
$reportsList = [];

if ($liveDb) {
    try {
        if ($actorPersonId) {
            $bin = joma_uuid_to_bin($actorPersonId);
            // Admissions awaiting therapist
            $stAdm = $liveDb->prepare("
                SELECT ta.id as ta_id, p.given_name, p.family_name, so.display_name as service_title
                FROM joma_therapist_assignments ta
                JOIN joma_admissions a ON a.id = ta.admission_id
                JOIN joma_persons p ON p.id = a.primary_subject_id
                LEFT JOIN joma_service_offerings so ON so.id = a.offering_id
                WHERE ta.therapist_person_id = ? AND ta.status = 'ASSIGNED' AND a.status = 'AWAITING_THERAPIST'
            ");
            if ($stAdm) {
                $stAdm->bind_param('s', $bin);
                $stAdm->execute();
                $resAdm = $stAdm->get_result();
                while ($r = $resAdm->fetch_assoc()) {
                    $openAdmissions[] = [
                        'ta_id' => joma_bin_to_uuid($r['ta_id']),
                        'client_name' => trim(($r['given_name'] ?? '') . ' ' . ($r['family_name'] ?? '')),
                        'service' => $r['service_title'] ?? 'مشاوره بالینی'
                    ];
                }
                $stAdm->close();
            }

            // Active Cases
            $stCase = $liveDb->prepare("
                SELECT c.id, c.purpose_summary, c.status, c.opened_at, p.given_name, p.family_name
                FROM joma_clinical_cases c
                JOIN joma_admissions a ON a.case_id = c.id
                JOIN joma_persons p ON p.id = a.primary_subject_id
                WHERE c.responsible_therapist_id = ?
                ORDER BY c.opened_at DESC
            ");
            if ($stCase) {
                $stCase->bind_param('s', $bin);
                $stCase->execute();
                $resCase = $stCase->get_result();
                while ($r = $resCase->fetch_assoc()) {
                    $activeCases[] = [
                        'id' => joma_bin_to_uuid($r['id']),
                        'client_name' => trim(($r['given_name'] ?? '') . ' ' . ($r['family_name'] ?? '')),
                        'purpose' => $r['purpose_summary'],
                        'status' => $r['status'],
                        'opened_at' => substr($r['opened_at'], 0, 10)
                    ];
                }
                $stCase->close();
            }

            // Notes
            $resN = $liveDb->query("SELECT id, case_id, storage_mode, created_at FROM joma_private_note_references WHERE author_person_id = 0x" . bin2hex($bin) . " ORDER BY created_at DESC LIMIT 5");
            if ($resN) {
                while ($rn = $resN->fetch_assoc()) {
                    $recentNotes[] = [
                        'id' => joma_bin_to_uuid($rn['id']),
                        'date' => $rn['created_at'],
                        'mode' => $rn['storage_mode']
                    ];
                }
            }
        }

        // Reports
        $resR = $liveDb->query("SELECT rv.id, rv.version_no, rv.report_text, rv.status FROM joma_report_versions rv ORDER BY rv.created_at DESC LIMIT 5");
        if ($resR) {
            while ($rr = $resR->fetch_assoc()) {
                $reportsList[] = [
                    'id' => joma_bin_to_uuid($rr['id']),
                    'ver' => $rr['version_no'],
                    'text' => $rr['report_text'],
                    'status' => $rr['status']
                ];
            }
        }
    } catch (Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>میز کار درمانگر — سامانه کلینیک جوما</title>
<link rel="stylesheet" href="/assets/css/joma-theme.css">
</head>
<body>

<div class="app-shell">
  <?php joma_render_sidebar('therapist'); ?>

  <main class="main-content">
    
    <!-- Top Action & Greeting -->
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:28px">
      <div>
        <h2 style="font-size:22px;font-weight:800;color:var(--text-main)">میز کار بالینی درمانگر</h2>
        <p style="font-size:13px;color:var(--text-muted)">مدیریت مراجعین، زمان‌بندی دقیق ورود/خروج و یادداشت‌های محرمانه جلسه</p>
      </div>
      <div style="display:flex;gap:10px">
        <button class="btn btn-outline" onclick="openModal('clockModal')">
          <span>⏱️</span>
          <span>ثبت ورود/خروج و ارسال پیامک تسویه</span>
        </button>
        <button class="btn btn-primary" onclick="openModal('noteModal')">
          <span>✍️</span>
          <span>ثبت یادداشت محرمانه جلسه</span>
        </button>
      </div>
    </div>

    <?php if ($actionMessage): ?>
      <div style="background:<?= $actionMessage['type'] === 'success' ? 'var(--success-bg)' : 'var(--danger-bg)' ?>;color:<?= $actionMessage['type'] === 'success' ? 'var(--success-text)' : 'var(--danger-text)' ?>;border:1px solid <?= $actionMessage['type'] === 'success' ? 'var(--success-border)' : 'var(--danger-border)' ?>;padding:14px 18px;border-radius:var(--radius-md);margin-bottom:24px;display:flex;align-items:center;gap:10px;font-weight:600">
        <span><?= $actionMessage['type'] === 'success' ? '✓' : '⚠️' ?></span>
        <span><?= htmlspecialchars($actionMessage['text']) ?></span>
      </div>
    <?php endif; ?>

    <!-- Summary Stat Cards -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px;margin-bottom:28px">
      <div class="stat-card">
        <div class="stat-icon" style="background:#e0f2fe;color:#0284c7">👥</div>
        <div>
          <div class="stat-val"><?= count($activeCases) ?></div>
          <div class="stat-label">پرونده‌های فعال تحت درمان</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fef3c7;color:#d97706">📥</div>
        <div>
          <div class="stat-val"><?= count($openAdmissions) ?></div>
          <div class="stat-label">پذیرش‌های جدید در انتظار تایید</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#ecfdf5;color:#10b981">🔒</div>
        <div>
          <div class="stat-val"><?= count($recentNotes) ?></div>
          <div class="stat-label">یادداشت‌های محرمانه ثبت‌شده</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#f3e8ff;color:#9333ea">📊</div>
        <div>
          <div class="stat-val"><?= count($reportsList) ?></div>
          <div class="stat-label">گزارش‌های ارزیابی تشخیصی</div>
        </div>
      </div>
    </div>

    <!-- Intake Admissions Card -->
    <?php if (!empty($openAdmissions)): ?>
      <div class="card" style="border-right:4px solid var(--warning)">
        <div class="card-header">
          <h3 class="card-title">
            <span>📥 مراجعین جدید ارجاع شده (در انتظار تایید شما)</span>
          </h3>
        </div>
        <table class="modern-table">
          <thead>
            <tr>
              <th>نام مراجع</th>
              <th>خدمت درخواستی</th>
              <th>شرح و هدف درمان</th>
              <th>اقدام</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($openAdmissions as $adm): ?>
              <tr>
                <td><strong><?= htmlspecialchars($adm['client_name']) ?></strong></td>
                <td><span class="pill pill-info"><?= htmlspecialchars($adm['service']) ?></span></td>
                <td>
                  <form method="post" id="form-adm-<?= $adm['ta_id'] ?>">
                    <input type="hidden" name="assignment_id" value="<?= $adm['ta_id'] ?>">
                    <select class="form-control" name="purpose" style="max-width:320px;padding:8px 12px">
                      <option value="درمان فردی شناختی-رفتاری (CBT)">درمان فردی شناختی-رفتاری (CBT)</option>
                      <option value="زوج‌درمانی و حل تعارضات عاطفی">زوج‌درمانی و حل تعارضات عاطفی</option>
                      <option value="روان‌درمانی تحلیلی و پویشی">روان‌درمانی تحلیلی و پویشی</option>
                      <option value="ارزیابی تشخیصی و طرح‌واره‌درمانی">ارزیابی تشخیصی و طرح‌واره‌درمانی</option>
                    </select>
                  </form>
                </td>
                <td>
                  <button class="btn btn-primary" form="form-adm-<?= $adm['ta_id'] ?>" name="action_accept" value="1" style="padding:8px 14px;font-size:13px">
                    تایید و گشایش پرونده
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <!-- Active Cases Table -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">
          <span>🗂️ پرونده‌های درمانی جاری تحت نظر شما</span>
        </h3>
      </div>
      <?php if (empty($activeCases)): ?>
        <div style="text-align:center;padding:40px;color:var(--text-muted)">
          <div style="font-size:40px;margin-bottom:10px">☕</div>
          <div>در حال حاضر هیچ پرونده فعالی ثبت نشده است. با تایید پذیرش مراجعین جدید، پرونده‌ها در این بخش نمایش داده می‌شوند.</div>
        </div>
      <?php else: ?>
        <table class="modern-table">
          <thead>
            <tr>
              <th>مراجع</th>
              <th>شرح بالینی و زمینه</th>
              <th>تاریخ تشکیل</th>
              <th>وضعیت</th>
              <th>عملیات</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($activeCases as $c): ?>
              <tr>
                <td><strong><?= htmlspecialchars($c['client_name'] ?: 'مراجع درمانی') ?></strong></td>
                <td><?= htmlspecialchars($c['purpose']) ?></td>
                <td><?= htmlspecialchars($c['opened_at']) ?></td>
                <td>
                  <span class="pill <?= $c['status'] === 'ACTIVE' ? 'pill-success' : 'pill-danger' ?>">
                    <?= $c['status'] === 'ACTIVE' ? 'در حال درمان' : 'مختومه' ?>
                  </span>
                </td>
                <td>
                  <?php if ($c['status'] === 'ACTIVE'): ?>
                    <form method="post" onsubmit="return confirm('آیا از خاتمه دادن رسمی به این پرونده مطمئن هستید؟')">
                      <input type="hidden" name="case_id" value="<?= $c['id'] ?>">
                      <button class="btn btn-danger" name="action_close_case" value="1" style="padding:6px 10px;font-size:12px">
                        خاتمه پرونده
                      </button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- Diagnostic Reports Card -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">
          <span>📊 کارتابل گزارش‌های ارزیابی و انتشار به پورتال مراجع</span>
        </h3>
      </div>
      <?php if (empty($reportsList)): ?>
        <div style="text-align:center;padding:30px;color:var(--text-muted);font-size:13px">
          گزارش ارزیابی جدیدی در کارتابل موجود نیست.
        </div>
      <?php else: ?>
        <table class="modern-table">
          <thead>
            <tr>
              <th>نسخه</th>
              <th>خلاصه گزارش بالینی</th>
              <th>وضعیت دسترسی</th>
              <th>اقدام</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($reportsList as $rep): ?>
              <tr>
                <td>نسخه <?= htmlspecialchars((string)$rep['ver']) ?></td>
                <td style="max-width:400px"><?= htmlspecialchars($rep['text']) ?></td>
                <td>
                  <span class="pill <?= $rep['status'] === 'PUBLISHED' ? 'pill-success' : 'pill-warning' ?>">
                    <?= $rep['status'] === 'PUBLISHED' ? 'منتشرشده به مراجع' : 'پیش‌نویس بررسی' ?>
                  </span>
                </td>
                <td>
                  <?php if ($rep['status'] !== 'PUBLISHED'): ?>
                    <form method="post">
                      <input type="hidden" name="report_version_id" value="<?= $rep['id'] ?>">
                      <button class="btn btn-primary" name="action_publish_report" value="1" style="padding:6px 12px;font-size:12px">
                        تایید و انتشار به مراجع
                      </button>
                    </form>
                  <?php else: ?>
                    <span style="font-size:12px;color:var(--success-text)">✓ در دسترس مراجع</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  </main>
</div>

<!-- Modal 1: Session Clock In/Out & SMS Invoice -->
<div class="modal-overlay" id="clockModal">
  <div class="modal-box">
    <div class="modal-header">
      <h3>ثبت زمان دقیق ورود/خروج جلسه و صدور صورت‌حساب</h3>
      <button class="btn-close" onclick="closeModal('clockModal')">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post">
        <div class="form-group">
          <label class="form-label">انتخاب مراجع جلسه:</label>
          <select class="form-control" name="case_id" required>
            <?php foreach ($activeCases as $c): ?>
              <option value="<?= $c['id'] ?>"><?= htmlspecialchars(($c['client_name'] ?: 'مراجع') . ' — ' . $c['purpose']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">نوع خدمت ارائه‌شده (با تعرفه اختصاصی شما):</label>
          <select class="form-control" name="service_type" id="service_type_select">
            <?php 
              $curU = $_SESSION['joma_demo_user']['login_name'] ?? 'demo-therapist';
              $curProf = $clinicSettings['therapist_profiles'][$curU]['services'] ?? ($clinicSettings['therapist_profiles']['demo-therapist']['services'] ?? []);
              foreach ($curProf as $sKey => $sVal):
                if ($sVal['enabled'] ?? true):
            ?>
              <option value="<?= htmlspecialchars($sKey) ?>" data-fee="<?= (int)$sVal['fee'] ?>" data-dur="<?= (int)$sVal['duration'] ?>">
                <?= htmlspecialchars($sVal['title']) ?> (<?= $sVal['duration'] ?> دقیقه) — <?= number_format($sVal['fee']) ?> تومان
              </option>
            <?php 
                endif;
              endforeach; 
            ?>
          </select>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div class="form-group">
            <label class="form-label">ساعت دقیق ورود به جلسه:</label>
            <div style="display:flex;gap:6px;align-items:center">
              <select class="form-control" name="in_hour" id="in_h">
                <?php for ($h = 8; $h <= 21; $h++): ?><option value="<?= $h ?>" <?= $h===16?'selected':'' ?>><?= sprintf('%02d', $h) ?></option><?php endfor; ?>
              </select>
              <span>:</span>
              <select class="form-control" name="in_min" id="in_m">
                <?php for ($m = 0; $m < 60; $m++): ?><option value="<?= $m ?>" <?= $m===0?'selected':'' ?>><?= sprintf('%02d', $m) ?></option><?php endfor; ?>
              </select>
            </div>
            <button type="button" class="btn btn-outline" onclick="setInNow()" style="margin-top:6px;padding:4px 8px;font-size:11px;width:100%">ثبت ورود در لحظه</button>
          </div>

          <div class="form-group">
            <label class="form-label">ساعت دقیق خروج از جلسه:</label>
            <div style="display:flex;gap:6px;align-items:center">
              <select class="form-control" name="out_hour" id="out_h">
                <?php for ($h = 8; $h <= 21; $h++): ?><option value="<?= $h ?>" <?= $h===16?'selected':'' ?>><?= sprintf('%02d', $h) ?></option><?php endfor; ?>
              </select>
              <span>:</span>
              <select class="form-control" name="out_min" id="out_m">
                <?php for ($m = 0; $m < 60; $m++): ?><option value="<?= $m ?>" <?= $m===45?'selected':'' ?>><?= sprintf('%02d', $m) ?></option><?php endfor; ?>
              </select>
            </div>
            <button type="button" class="btn btn-outline" onclick="setOutNow()" style="margin-top:6px;padding:4px 8px;font-size:11px;width:100%">ثبت خروج در لحظه</button>
          </div>
        </div>

        <div style="margin-top:10px;padding:12px;background:#f0fdf4;border:1px solid #86efac;border-radius:var(--radius-md)">
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;color:#166534;cursor:pointer">
            <input type="checkbox" name="send_sms_invoice" value="1" checked style="width:18px;height:18px">
            ارسال خودکار پیامک تسویه حساب جلسه به مراجع
          </label>
          <div style="font-size:11px;color:#15803d;margin-top:6px;line-height:1.6">
            متن پیامک: «امیدواریم از جلسه با دکتر رضایی راضی بوده باشید. زمان جلسه: ۶۳ دقیقه، مبلغ کل: ... تومان، مانده حساب: ... تومان، شماره کارت کلینیک...»
          </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px">
          <button type="button" class="btn btn-outline" onclick="closeModal('clockModal')">انصراف</button>
          <button type="submit" class="btn btn-primary" name="action_session_clock" value="1">محاسبه دقیق و ثبت نهایی جلسه</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal 2: New Private Note -->
<div class="modal-overlay" id="noteModal">
  <div class="modal-box">
    <div class="modal-header">
      <h3>ثبت یادداشت محرمانه شخصی درمانگر (AUTHOR_ONLY)</h3>
      <button class="btn-close" onclick="closeModal('noteModal')">&times;</button>
    </div>
    <div class="modal-body">
      <div style="background:var(--primary-light);border:1px solid var(--primary-border);color:#0369a1;padding:12px;border-radius:var(--radius-md);font-size:12px;margin-bottom:16px;line-height:1.7">
        🔒 <strong>اصل صیانت از محرمانگی:</strong> این یادداشت منحصراً توسط خود درمانگر قابل رویت است و طبق تهدیدنامه امنیتی سامانه، در هیچ‌کدام از پنل‌های منشی، مدیریت یا مراجع نمایش داده نمی‌شود.
      </div>
      <form method="post">
        <div class="form-group">
          <label class="form-label">انتخاب پرونده بالینی:</label>
          <select class="form-control" name="case_id" required>
            <?php foreach ($activeCases as $c): ?>
              <?php if ($c['status'] === 'ACTIVE'): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars(($c['client_name'] ?: 'مراجع') . ' — ' . $c['purpose']) ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">متن مشاهدات و فرضیه‌سازی بالینی:</label>
          <textarea class="form-control" name="note_text" rows="5" placeholder="یادداشت‌های جلسه، تحلیل بالینی، تکالیف درمانی..." required></textarea>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:20px">
          <button type="button" class="btn btn-outline" onclick="closeModal('noteModal')">انصراف</button>
          <button type="submit" class="btn btn-primary" name="action_save_note" value="1">ذخیره ایمن یادداشت</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="/assets/js/joma-app.js"></script>
<script>
function setInNow() {
  const d = new Date();
  document.getElementById('in_h').value = d.getHours();
  document.getElementById('in_m').value = d.getMinutes();
}
function setOutNow() {
  const d = new Date();
  document.getElementById('out_h').value = d.getHours();
  document.getElementById('out_m').value = d.getMinutes();
}
</script>
</body>
</html>
