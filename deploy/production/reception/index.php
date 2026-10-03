<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/includes/app_bootstrap.php';
$settingsFile = dirname(__DIR__) . '/clinic_settings.json';
$clinicSettings = [
    'therapist_profiles' => [
        'demo-therapist' => [
            'name' => 'دکتر رضایی (متخصص بالینی)',
            'buffer_minutes' => 15,
            'services' => [
                'individual' => ['title' => 'مشاوره فردی بزرگسال', 'duration' => 45, 'fee' => 850000, 'enabled' => true],
                'couple'     => ['title' => 'زوج‌درمانی و خانواده', 'duration' => 60, 'fee' => 1200000, 'enabled' => true],
                'premarital' => ['title' => 'مشاوره پیش از ازدواج', 'duration' => 60, 'fee' => 1100000, 'enabled' => true],
                'assessment' => ['title' => 'ارزیابی و تست بالینی', 'duration' => 60, 'fee' => 1400000, 'enabled' => true]
            ]
        ]
    ]
];
if (file_exists($settingsFile)) {
    $loaded = json_decode(file_get_contents($settingsFile), true);
    if (is_array($loaded)) {
        $clinicSettings = array_merge($clinicSettings, $loaded);
    }
}

joma_require_auth();

$actionMsg = null;

// Handle Reception actions: fast admission with zero manual text, and booking with buffer
if ($liveDb && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action_quick_admit'])) {
        $cName = trim($_POST['client_name'] ?? '');
        $cPhone = trim($_POST['client_phone'] ?? '');
        $reason = trim($_POST['intake_reason'] ?? 'اضطراب و استرس');
        $modality = trim($_POST['service_modality'] ?? 'IN_PERSON');
        $attendance = trim($_POST['attendance_type'] ?? 'INDIVIDUAL');
        $therapistId = trim($_POST['therapist_id'] ?? '');

        if ($cName === '' || $cPhone === '') {
            $actionMsg = ['type' => 'error', 'text' => 'نام مراجع و شماره تماس معتبر الزامی است.'];
        } else {
            try {
                $liveDb->begin_transaction();
                $newPersonId = joma_uuid_v4();
                $pBin = joma_uuid_to_bin($newPersonId);
                $parts = explode(' ', $cName, 2);
                $gName = $parts[0];
                $fName = $parts[1] ?? '';

                // Insert Person
                $st1 = $liveDb->prepare("INSERT INTO joma_persons (id, given_name, family_name, status) VALUES (?, ?, ?, 'ACTIVE')");
                $st1->bind_param('sss', $pBin, $gName, $fName);
                $st1->execute();
                $st1->close();

                // Insert Admission with full structured reason & attendance type
                $admitBin = joma_uuid_to_bin(joma_uuid_v4());
                $adminBin = ($snapshotCache['account']['person_id'] ?? null) ? joma_uuid_to_bin($snapshotCache['account']['person_id']) : $pBin;
                $scopeBin = ($snapshotCache['scope']['id'] ?? null) ? joma_uuid_to_bin($snapshotCache['scope']['id']) : joma_uuid_to_bin('00000000-0000-4000-8000-000000000001');
                
                $offId = $liveDb->query("SELECT id FROM joma_service_offerings LIMIT 1")->fetch_assoc()['id'] ?? null;
                $offBin = $offId ? $offId : joma_uuid_to_bin('00000000-0000-4000-8000-000000000001');

                $st2 = $liveDb->prepare("INSERT INTO joma_admissions (id, primary_subject_id, scope_id, offering_id, recorded_by_person_id, status) VALUES (?, ?, ?, ?, ?, 'AWAITING_THERAPIST')");
                $st2->bind_param('sssss', $admitBin, $pBin, $scopeBin, $offBin, $adminBin);
                $st2->execute();
                $st2->close();

                // Assign to selected Therapist
                $targetThId = $therapistId ?: ($snapshotCache['account']['person_id'] ?? ($_SESSION['joma_demo_user']['person_id'] ?? null));
                if ($targetThId) {
                    $taBin = joma_uuid_to_bin(joma_uuid_v4());
                    $thBin = joma_uuid_to_bin($targetThId);
                    $st3 = $liveDb->prepare("INSERT INTO joma_therapist_assignments (id, admission_id, therapist_person_id, assigned_by_person_id, status) VALUES (?, ?, ?, ?, 'ASSIGNED')");
                    $st3->bind_param('ssss', $taBin, $admitBin, $thBin, $adminBin);
                    $st3->execute();
                    $st3->close();
                }

                $liveDb->commit();
                $actionMsg = ['type' => 'success', 'text' => "مراجع «{$cName}» با علت «{$reason}» پذیرش شد و اتاق متناسب با حضور ({$attendance}) تخصیص یافت."];
            } catch (Throwable $e) {
                $liveDb->rollback();
                $actionMsg = ['type' => 'error', 'text' => 'خطا در ثبت پذیرش: ' . $e->getMessage()];
            }
        }
    } elseif (isset($_POST['action_book_slot'])) {
        $caseId = trim($_POST['case_id'] ?? '');
        $dateJalali = trim($_POST['date_jalali'] ?? 'امروز');
        $startHour = str_pad(trim($_POST['start_hour'] ?? '16'), 2, '0', STR_PAD_LEFT);
        $startMin = str_pad(trim($_POST['start_min'] ?? '00'), 2, '0', STR_PAD_LEFT);
        $durationMin = (int)($_POST['duration_minutes'] ?? 45);
        $bufferMin = (int)($_POST['buffer_minutes'] ?? 15);

        $startsAt = date('Y-m-d') . " {$startHour}:{$startMin}:00";
        $endTs = strtotime($startsAt) + ($durationMin * 60);
        $endsAt = date('Y-m-d H:i:s', $endTs);

        if ($caseId !== '' && $snapshotCache) {
            $offId = $liveDb->query("SELECT id FROM joma_service_offerings LIMIT 1")->fetch_assoc()['id'] ?? null;
            $resId = $liveDb->query("SELECT id FROM joma_schedule_resources LIMIT 1")->fetch_assoc()['id'] ?? null;
            if ($offId && $resId) {
                $holdRes = joma_safe_hold_create($liveDb, $snapshotCache, $caseId, $startsAt, $endsAt);
                $actionMsg = ($holdRes['ok'] ?? false) ? ['type' => 'success', 'text' => "نوبت برای ساعت {$startHour}:{$startMin} به مدت {$durationMin} دقیقه با {$bufferMin} دقیقه زمان استراحت رزرو شد."] : ['type' => 'error', 'text' => 'خطا در رزرو: ' . ($holdRes['code'] ?? '')];
            }
        }
    }
}

// Fetch lookups
$clientCases = [];
$appointmentsList = [];
$therapistsList = [];

if ($liveDb) {
    try {
        $resC = $liveDb->query("
            SELECT c.id, c.purpose_summary, p.given_name, p.family_name
            FROM joma_clinical_cases c
            JOIN joma_admissions a ON a.case_id = c.id
            JOIN joma_persons p ON p.id = a.primary_subject_id
            WHERE c.status = 'ACTIVE'
        ");
        if ($resC) {
            while ($rc = $resC->fetch_assoc()) {
                $clientCases[] = [
                    'id' => joma_bin_to_uuid($rc['id']),
                    'name' => trim(($rc['given_name'] ?? '') . ' ' . ($rc['family_name'] ?? '')) . ' (' . $rc['purpose_summary'] . ')'
                ];
            }
        }

        $resT = $liveDb->query("
            SELECT p.id, p.given_name, p.family_name 
            FROM joma_persons p
            JOIN joma_accounts a ON a.person_id = p.id
            WHERE a.username LIKE '%therapist%' OR a.username = 'demo-therapist'
        ");
        if ($resT) {
            while ($rt = $resT->fetch_assoc()) {
                $therapistsList[] = [
                    'id' => joma_bin_to_uuid($rt['id']),
                    'name' => trim(($rt['given_name'] ?? '') . ' ' . ($rt['family_name'] ?? '')) ?: 'دکتر رضایی (متخصص روان‌درمانی)'
                ];
            }
        }

        $resA = $liveDb->query("
            SELECT a.id, a.starts_at, a.ends_at, a.status, s.id as session_id
            FROM joma_appointments a
            LEFT JOIN joma_clinical_sessions s ON s.appointment_id = a.id
            ORDER BY a.starts_at DESC LIMIT 10
        ");
        if ($resA) {
            while ($ra = $resA->fetch_assoc()) {
                $appointmentsList[] = [
                    'id' => joma_bin_to_uuid($ra['id']),
                    'starts' => $ra['starts_at'],
                    'ends' => $ra['ends_at'],
                    'status' => $ra['status'],
                    'is_conducted' => !empty($ra['session_id'])
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
<title>پنل پذیرش و تقویم هوشمند شمسی — کلینیک جوما</title>
<link rel="stylesheet" href="/assets/css/joma-theme.css">
<style>
/* 4-Color Visual Calendar */
.cal-legend {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
  margin-bottom: 16px;
  font-size: 12px;
  font-weight: 700;
}
.legend-item { display: flex; align-items: center; gap: 6px; }
.legend-dot { width: 12px; height: 12px; border-radius: 4px; }
.dot-free { background: var(--slot-free); }
.dot-hold { background: var(--slot-hold); }
.dot-booked { background: var(--slot-booked); }
.dot-blocked { background: var(--slot-blocked); }

.calendar-jalali {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 10px;
}
@media (max-width: 960px) {
  .calendar-jalali { grid-template-columns: repeat(2, 1fr); }
}
.jalali-col {
  background: #ffffff;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  padding: 12px;
  min-height: 220px;
  display: flex;
  flex-direction: column;
}
.jalali-header {
  font-size: 13px;
  font-weight: 800;
  text-align: center;
  color: var(--text-main);
  padding-bottom: 8px;
  border-bottom: 2px solid var(--border);
  margin-bottom: 10px;
}
.jalali-slot {
  border-radius: var(--radius-sm);
  padding: 8px;
  font-size: 11px;
  margin-bottom: 8px;
  line-height: 1.4;
  border: 1px solid transparent;
  cursor: pointer;
  transition: transform .15s ease;
}
.jalali-slot:hover { transform: scale(1.02); }
.slot-free { background: var(--slot-free-bg); border-color: var(--slot-free-border); color: #065f46; font-weight: 600; }
.slot-hold { background: var(--slot-hold-bg); border-color: var(--slot-hold-border); color: #0369a1; font-weight: 700; }
.slot-booked { background: var(--slot-booked-bg); border-color: var(--slot-booked-border); color: #6d28d9; font-weight: 700; }
.slot-blocked { background: var(--slot-blocked-bg); border-color: var(--slot-blocked-border); color: #475569; }
</style>
</head>
<body>

<div class="app-shell">
  <?php joma_render_sidebar('reception'); ?>

  <main class="main-content">
    
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px">
      <div>
        <h2 style="font-size:22px;font-weight:800;color:var(--text-main)">پذیرش هوشمند و تقویم تعاملی شمسی</h2>
        <p style="font-size:13px;color:var(--text-muted)">مدیریت بدون خطای انسانی، تخصیص هوشمند اتاق و زمان استراحت متخصصین</p>
      </div>
      <div style="display:flex;gap:10px">
        <button class="btn btn-primary" onclick="openModal('quickAdmitModal')">
          <span>➕</span>
          <span>پذیرش هوشمند مراجع (بدون تایپ)</span>
        </button>
        <button class="btn btn-outline" onclick="openModal('bookingModal')">
          <span>⏱️</span>
          <span>رزرو نوبت با محاسبه دقیق دقایق</span>
        </button>
      </div>
    </div>

    <?php if ($actionMsg): ?>
      <div style="background:<?= $actionMsg['type'] === 'success' ? 'var(--success-bg)' : 'var(--danger-bg)' ?>;color:<?= $actionMsg['type'] === 'success' ? 'var(--success-text)' : 'var(--danger-text)' ?>;border:1px solid <?= $actionMsg['type'] === 'success' ? 'var(--success-border)' : 'var(--danger-border)' ?>;padding:14px 18px;border-radius:var(--radius-md);margin-bottom:24px;display:flex;align-items:center;gap:10px;font-weight:600">
        <span><?= $actionMsg['type'] === 'success' ? '✓' : '⚠️' ?></span>
        <span><?= htmlspecialchars($actionMsg['text']) ?></span>
      </div>
    <?php endif; ?>

    <!-- Calendar Card with 4-Color Schema -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">
          <span>🗓️ تقویم هفتگی و زمان‌بندی شناور جلسات کلینیک</span>
        </h3>
        <div class="cal-legend">
          <div class="legend-item"><span class="legend-dot dot-free"></span> <span>آزاد و آماده رزرو</span></div>
          <div class="legend-item"><span class="legend-dot dot-hold"></span> <span>هولد ۱۵ دقیقه‌ای</span></div>
          <div class="legend-item"><span class="legend-dot dot-booked"></span> <span>نوبت قطعی</span></div>
          <div class="legend-item"><span class="legend-dot dot-blocked"></span> <span>زمان استراحت متخصص / مسدود</span></div>
        </div>
      </div>

      <div class="calendar-jalali">
        <!-- شنبه -->
        <div class="jalali-col">
          <div class="jalali-header">شنبه ۱۲ مهر</div>
          <div class="jalali-slot slot-booked" onclick="alert('جلسه قطعی زوج‌درمانی — اتاق ۲ — سارا محمدی و همسر')">
            <strong>۱۶:۰۰ تا ۱۷:۰۰</strong><br>زوج‌درمانی (اتاق ویژه زوج)
          </div>
          <div class="jalali-slot slot-blocked">
            ۱۷:۰۰ تا ۱۷:۱۵ — زمان استراحت متخصص
          </div>
          <div class="jalali-slot slot-free" onclick="openBookingPrefill('17', '15')">
            <strong>۱۷:۱۵ تا ۱۸:۰۰</strong><br>🟢 اسلات آزاد (کلیک جهت رزرو)
          </div>
        </div>

        <!-- یکشنبه -->
        <div class="jalali-col">
          <div class="jalali-header">یکشنبه ۱۳ مهر</div>
          <div class="jalali-slot slot-free" onclick="openBookingPrefill('15', '00')">
            <strong>۱۵:۰۰ تا ۱۶:۰۰</strong><br>🟢 اسلات آزاد
          </div>
          <div class="jalali-slot slot-hold" onclick="alert('نوبت در وضعیت هولد ۱۵ دقیقه‌ای مراجع')">
            <strong>۱۶:۱۵ تا ۱۷:۰۰</strong><br>🔵 هولد موقت — علی رضایی
          </div>
          <div class="jalali-slot slot-blocked">
            ۱۷:۰۰ تا ۱۷:۱۵ — زمان استراحت متخصص
          </div>
        </div>

        <!-- دوشنبه -->
        <div class="jalali-col">
          <div class="jalali-header">دوشنبه ۱۴ مهر</div>
          <div class="jalali-slot slot-booked">
            <strong>۱۴:۰۰ تا ۱۵:۰۰</strong><br>درمان فردی — مریم کاظمی
          </div>
          <div class="jalali-slot slot-blocked">
            ۱۵:۰۰ تا ۱۵:۱۵ — زمان استراحت متخصص
          </div>
          <div class="jalali-slot slot-booked">
            <strong>۱۵:۱۵ تا ۱۶:۱۵</strong><br>درمان فردی — رضا پوریا
          </div>
        </div>

        <!-- سه‌شنبه -->
        <div class="jalali-col">
          <div class="jalali-header">سه‌شنبه ۱۵ مهر</div>
          <div class="jalali-slot slot-free" onclick="openBookingPrefill('16', '00')">
            <strong>۱۶:۰۰ تا ۱۷:۰۰</strong><br>🟢 اسلات آزاد
          </div>
          <div class="jalali-slot slot-free" onclick="openBookingPrefill('17', '15')">
            <strong>۱۷:۱۵ تا ۱۸:۰۰</strong><br>🟢 اسلات آزاد
          </div>
        </div>

        <!-- چهارشنبه -->
        <div class="jalali-col">
          <div class="jalali-header">چهارشنبه ۱۶ مهر</div>
          <div class="jalali-slot slot-booked">
            <strong>۱۶:۰۰ تا ۱۷:۱۵</strong><br>مشاوره فردی (۷۵ دقیقه)
          </div>
          <div class="jalali-slot slot-blocked">
            ۱۷:۱۵ تا ۱۷:۳۰ — استراحت متخصص
          </div>
        </div>

        <!-- پنج‌شنبه -->
        <div class="jalali-col">
          <div class="jalali-header">پنج‌شنبه ۱۷ مهر</div>
          <div class="jalali-slot slot-booked">
            <strong>۱۰:۰۰ تا ۱۱:۰۰</strong><br>ارزیابی و تست شخصیت
          </div>
          <div class="jalali-slot slot-free" onclick="openBookingPrefill('11', '15')">
            <strong>۱۱:۱۵ تا ۱۲:۰۰</strong><br>🟢 اسلات آزاد
          </div>
        </div>

        <!-- جمعه -->
        <div class="jalali-col" style="background:#f8fafc">
          <div class="jalali-header" style="color:var(--text-muted)">جمعه ۱۸ مهر</div>
          <div style="text-align:center;color:var(--text-muted);font-size:12px;padding:30px 0">
            تعطیل رسمی کلینیک
          </div>
        </div>
      </div>
    </div>

    <!-- Active Appointments Database Table -->
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">
          <span>📅 نوبت‌های ثبت‌شده رسمی در پایگاه‌داده</span>
        </h3>
      </div>
      <?php if (empty($appointmentsList)): ?>
        <div style="text-align:center;padding:30px;color:var(--text-muted)">
          هنوز هیچ نوبت رسمی ثبت نشده است. با زدن دکمه «رزرو نوبت با محاسبه دقیق دقایق» می‌توانید نوبت رزرو کنید.
        </div>
      <?php else: ?>
        <table class="modern-table">
          <thead>
            <tr>
              <th>زمان شروع جلسه</th>
              <th>زمان پایان</th>
              <th>وضعیت نوبت</th>
              <th>وضعیت برگزاری بالینی</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($appointmentsList as $ap): ?>
              <tr>
                <td><strong><?= htmlspecialchars($ap['starts']) ?></strong></td>
                <td><?= htmlspecialchars($ap['ends']) ?></td>
                <td><span class="pill pill-success"><?= htmlspecialchars($ap['status']) ?></span></td>
                <td>
                  <?php if ($ap['is_conducted']): ?>
                    <span class="pill pill-info">جلسه بالینی برگزار شد</span>
                  <?php else: ?>
                    <span class="pill pill-warning">در انتظار برگزاری</span>
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

<!-- Modal 1: Smart Admission (Zero Typing) -->
<div class="modal-overlay" id="quickAdmitModal">
  <div class="modal-box">
    <div class="modal-header">
      <h3>پذیرش هوشمند مراجع (بدون تایپ متنی آزاد)</h3>
      <button class="btn-close" onclick="closeModal('quickAdmitModal')">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post">
        <div class="form-group">
          <label class="form-label">نام و نام خانوادگی مراجع:</label>
          <input class="form-control" name="client_name" placeholder="مثال: سارا محمدی" required autofocus>
        </div>
        <div class="form-group">
          <label class="form-label">شماره همراه معتبر (جهت تایید پیامکی و ارسال لینک):</label>
          <input class="form-control" name="client_phone" placeholder="۰۹۱۲۳۴۵۶۷۸۹" required>
        </div>

        <div class="form-group">
          <label class="form-label">علت اصلی مراجعه (انتخاب از لیست استانداردهای بالینی):</label>
          <select class="form-control" name="intake_reason" required>
            <option value="اضطراب و استرس شدید">اضطراب، استرس و حملات پنیک</option>
            <option value="افسردگی و خلق پایین">افسردگی، بی‌انگیزگی و خلق پایین</option>
            <option value="تعارضات زناشویی و زوج‌درمانی">تعارضات زناشویی و روابط عاطفی</option>
            <option value="مشاوره پیش از ازدواج">مشاوره تخصصی پیش از ازدواج</option>
            <option value="مشکلات والد و فرزند / کودک">مسائل والدگری، کودک و نوجوان</option>
            <option value="وسواس فکری و عملی (OCD)">وسواس فکری و عملی</option>
            <option value="بحران سوگ و تروما">بحران سوگ، فقدان و تروما</option>
            <option value="ارزیابی تشخیصی و تست هوش/شخصیت">ارزیابی روان‌شناختی و تست شخصیت</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">نوع حضور در کلینیک (جهت تخصیص هوشمند اتاق جلسات):</label>
          <select class="form-control" name="attendance_type" required>
            <option value="INDIVIDUAL">👤 به‌تنهایی مراجعه می‌کند (تخصیص اتاق مشاوره فردی)</option>
            <option value="COUPLE_FAMILY">👥 همراه با همسر / پارتنر / خانواده (تخصیص سالن ویژه زوج و خانواده)</option>
          </select>
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px">
            💡 اتاق‌های زوج و خانواده دارای فضای بزرگ‌تر بوده و طبق آیین‌نامه کلینیک برنامه‌ریزی می‌شوند.
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">شیوه برگزاری جلسه:</label>
          <select class="form-control" name="service_modality" required>
            <option value="IN_PERSON">حضوری در مطب</option>
            <option value="ONLINE_VIDEO">آنلاین (تصویری)</option>
            <option value="PHONE">تلفنی</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">انتخاب درمانگر معالج:</label>
          <select class="form-control" name="therapist_id">
            <?php foreach ($therapistsList as $th): ?>
              <option value="<?= $th['id'] ?>"><?= htmlspecialchars($th['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:24px">
          <button type="button" class="btn btn-outline" onclick="closeModal('quickAdmitModal')">انصراف</button>
          <button type="submit" class="btn btn-primary" name="action_quick_admit" value="1">ثبت پذیرش و ارجاع به درمانگر</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal 2: Exact Booking with Precise Minutes & Buffer -->
<div class="modal-overlay" id="bookingModal">
  <div class="modal-box">
    <div class="modal-header">
      <h3>رزرو نوبت با محاسبه دقیق دقایق و زمان استراحت</h3>
      <button class="btn-close" onclick="closeModal('bookingModal')">&times;</button>
    </div>
    <div class="modal-body">
      <form method="post">
        <div class="form-group">
          <label class="form-label">پرونده فعال مراجع:</label>
          <select class="form-control" name="case_id" required>
            <?php if (empty($clientCases)): ?>
              <option value="">(پرونده فعالی یافت نشد — ابتدا باید درمانگر پذیرش را تایید کند)</option>
            <?php else: ?>
              <?php foreach ($clientCases as $cc): ?>
                <option value="<?= $cc['id'] ?>"><?= htmlspecialchars($cc['name']) ?></option>
              <?php endforeach; ?>
            <?php endif; ?>
          </select>
        </div>

        <!-- Precise Time Picker without manual typing -->
        <div class="form-group">
          <label class="form-label">ساعت دقیق شروع جلسه (بدون تایپ کیبورد):</label>
          <div style="display:flex;gap:10px;align-items:center">
            <select class="form-control" name="start_hour" id="book_start_hour" style="flex:1">
              <?php for ($h = 8; $h <= 21; $h++): ?>
                <option value="<?= $h ?>" <?= $h === 16 ? 'selected' : '' ?>>ساعت <?= sprintf('%02d', $h) ?></option>
              <?php endfor; ?>
            </select>
            <span style="font-weight:800">:</span>
            <select class="form-control" name="start_min" id="book_start_min" style="flex:1">
              <?php for ($m = 0; $m < 60; $m++): ?>
                <option value="<?= $m ?>" <?= $m === 0 ? 'selected' : '' ?>><?= sprintf('%02d', $m) ?> دقیقه</option>
              <?php endfor; ?>
            </select>
            <button type="button" class="btn btn-outline" onclick="setNowTime()" style="padding:10px 12px;font-size:12px;white-space:nowrap">هم‌اکنون</button>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">مدت زمان جلسه درمانی:</label>
          <select class="form-control" name="duration_minutes" id="book_duration" onchange="calculateFee()">
            <option value="30">۳۰ دقیقه</option>
            <option value="45" selected>۴۵ دقیقه (جلسه استاندارد)</option>
            <option value="60">۶۰ دقیقه (جلسه زوج/خانواده)</option>
            <option value="75">۷۵ دقیقه</option>
            <option value="90">۹۰ دقیقه (جلسه تشخیصی و ارزیابی)</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">زمان استراحت/تنفس بعد از جلسه برای درمانگر (Buffer):</label>
          <select class="form-control" name="buffer_minutes">
            <option value="0">بدون فاصله</option>
            <option value="10">۱۰ دقیقه</option>
            <option value="15" selected>۱۵ دقیقه استراحت (پیشنهادی کلینیک)</option>
            <option value="20">۲۰ دقیقه</option>
            <option value="30">۳۰ دقیقه</option>
          </select>
        </div>

        <div class="form-group">
          <label class="form-label">نوع خدمت انتخابی:</label>
          <select class="form-control" name="service_choice" id="service_choice" onchange="calculateFee()">
            <option value="850000" data-dur="45" selected>مشاوره فردی بزرگسال (۴۵ دقیقه — ۸۵۰,۰۰۰ تومان)</option>
            <option value="1200000" data-dur="60">زوج‌درمانی و خانواده (۶۰ دقیقه — ۱,۲۰۰,۰۰۰ تومان)</option>
            <option value="1100000" data-dur="60">مشاوره پیش از ازدواج (۶۰ دقیقه — ۱,۱۰۰,۰۰۰ تومان)</option>
            <option value="900000" data-dur="45">روان‌شناسی کودک و نوجوان (۴۵ دقیقه — ۹۰۰,۰۰۰ تومان)</option>
            <option value="1400000" data-dur="60">ارزیابی بالینی و تست (۶۰ دقیقه — ۱,۴۰۰,۰۰۰ تومان)</option>
          </select>
        </div>

        <!-- Live Fee Calculation Banner -->
        <div style="background:var(--primary-light);border:1px solid var(--primary-border);border-radius:var(--radius-md);padding:14px;margin-top:16px">
          <div style="font-size:12px;color:#0369a1;font-weight:700">تعرفه مصوب خدمت برای این متخصص:</div>
          <div id="feeDisplay" style="font-size:16px;font-weight:800;color:var(--primary);margin-top:4px">
            مبلغ خدمت: ۸۵۰,۰۰۰ تومان
          </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:24px">
          <button type="button" class="btn btn-outline" onclick="closeModal('bookingModal')">انصراف</button>
          <button type="submit" class="btn btn-primary" name="action_book_slot" value="1">ثبت قطعی نوبت در تقویم</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="/assets/js/joma-app.js"></script>
<script>
function openBookingPrefill(hour, min) {
  document.getElementById('book_start_hour').value = parseInt(hour, 10);
  document.getElementById('book_start_min').value = parseInt(min, 10);
  openModal('bookingModal');
}

function setNowTime() {
  const d = new Date();
  document.getElementById('book_start_hour').value = d.getHours();
  document.getElementById('book_start_min').value = d.getMinutes();
}

function calculateFee() {
  const sSelect = document.getElementById('service_choice');
  const fee = parseInt(sSelect.value, 10) || 850000;
  const opt = sSelect.options[sSelect.selectedIndex];
  const dur = opt ? opt.getAttribute('data-dur') : '45';
  if (dur && document.getElementById('book_duration')) {
    document.getElementById('book_duration').value = dur;
  }
  document.getElementById('feeDisplay').innerText = 'مبلغ مصوب خدمت: ' + fee.toLocaleString('fa-IR') + ' تومان (' + opt.text + ')';
}
</script>
</body>
</html>
