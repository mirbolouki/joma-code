<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/includes/app_bootstrap.php';

// Patient Portal — Clean & Calming UX
$clientAppointments = [];
$clientForms = [];
$clientReports = [];

if ($liveDb) {
    try {
        // Fetch appointments for patient
        $resA = $liveDb->query("
            SELECT a.id, a.starts_at, a.ends_at, a.status
            FROM joma_appointments a
            WHERE a.status = 'CONFIRMED'
            ORDER BY a.starts_at ASC LIMIT 5
        ");
        if ($resA) {
            while ($ra = $resA->fetch_assoc()) {
                $clientAppointments[] = [
                    'id' => joma_bin_to_uuid($ra['id']),
                    'starts' => $ra['starts_at'],
                    'ends' => $ra['ends_at'],
                    'status' => $ra['status']
                ];
            }
        }

        // Available published form templates
        $resF = $liveDb->query("
            SELECT v.id as vid, t.label as title, v.version_no
            FROM joma_form_versions v
            JOIN joma_form_templates t ON t.id = v.template_id
            WHERE v.status = 'PUBLISHED' LIMIT 5
        ");
        if ($resF) {
            while ($rf = $resF->fetch_assoc()) {
                $clientForms[] = [
                    'id' => joma_bin_to_uuid($rf['vid']),
                    'title' => $rf['title'],
                    'ver' => $rf['version_no']
                ];
            }
        }

        // Published reports for patient
        $resR = $liveDb->query("
            SELECT rv.id, rv.version_no, rv.report_text
            FROM joma_report_versions rv
            WHERE rv.status = 'PUBLISHED' LIMIT 5
        ");
        if ($resR) {
            while ($rr = $resR->fetch_assoc()) {
                $clientReports[] = [
                    'id' => joma_bin_to_uuid($rr['id']),
                    'text' => $rr['report_text']
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
<title>پورتال مراجعین — سامانه کلینیک جوما</title>
<link rel="stylesheet" href="/assets/css/joma-theme.css">
<style>
body {
  background-color: #fdfbf7; /* Gentle Warm Linen */
}
.portal-wrap {
  max-width: 680px;
  margin: 0 auto;
  padding: 24px 16px 60px 16px;
}
.portal-header {
  text-align: center;
  margin-bottom: 32px;
}
.portal-brand {
  width: 48px;
  height: 48px;
  background: var(--accent);
  color: #fff;
  border-radius: var(--radius-md);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
  font-weight: 800;
  margin-bottom: 12px;
}
.portal-card {
  background: #ffffff;
  border: 1px solid #f1ece1;
  border-radius: var(--radius-xl);
  padding: 24px;
  margin-bottom: 20px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.03);
}
.portal-title {
  font-size: 16px;
  font-weight: 800;
  color: #1e293b;
  margin-bottom: 14px;
  display: flex;
  align-items: center;
  gap: 8px;
}
</style>
</head>
<body>

<div class="portal-wrap">
  
  <div class="portal-header">
    <div class="portal-brand">ج</div>
    <h2 style="font-size:20px;font-weight:800;color:#0f172a">پورتال اختصاصی مراجعین کلینیک</h2>
    <p style="font-size:13px;color:var(--text-muted)">پیگیری نوبت‌ها، تکمیل پرسشنامه‌ها و بازخورد درمانگر</p>
    <div style="margin-top:10px">
      <a href="/login.php" style="font-size:12px;color:var(--primary);text-decoration:none">ورود کادر درمان / خروج</a>
    </div>
  </div>

  <!-- Upcoming Appointments Card -->
  <div class="portal-card" style="background:#f0fdf4;border-color:#bbf7d0">
    <div class="portal-title" style="color:#166534">
      <span>🗓️</span>
      <span>نوبت مشاوره پیش‌روی شما</span>
    </div>
    <?php if (empty($clientAppointments)): ?>
      <div style="text-align:center;padding:20px 0;color:#166534;font-size:13px">
        در حال حاضر نوبت تایید شده فعالی ندارید. نوبت‌های شما پس از هماهنگی منشی در اینجا قرار می‌گیرند.
      </div>
    <?php else: ?>
      <?php foreach ($clientAppointments as $ap): ?>
        <div style="background:#fff;border:1px solid #86efac;border-radius:var(--radius-md);padding:16px;margin-bottom:10px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <strong style="color:#0f172a">جلسه مشاوره فردی</strong>
            <span class="pill pill-success">تایید شده</span>
          </div>
          <div style="font-size:13px;color:var(--text-muted);margin-bottom:12px">
            زمان: <?= htmlspecialchars($ap['starts']) ?>
          </div>
          <div style="background:#f8fafc;padding:10px;border-radius:8px;font-size:12px;color:var(--text-muted);line-height:1.7">
            💡 <strong>قانون تغییر نوبت:</strong> لغو مستقیم تا ۲۴ ساعت پیش از شروع جلسه امکان‌پذیر است. در صورت نیاز به هماهنگی با منشی تماس حاصل فرمایید.
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Forms to complete -->
  <div class="portal-card">
    <div class="portal-title">
      <span>📝</span>
      <span>پرسشنامه‌ها و خودارزیابی‌ها</span>
    </div>
    <?php if (empty($clientForms)): ?>
      <div style="text-align:center;padding:20px 0;color:var(--text-muted);font-size:13px">
        تمامی پرسشنامه‌های درخواستی درمانگر توسط شما تکمیل شده است.
      </div>
    <?php else: ?>
      <?php foreach ($clientForms as $f): ?>
        <div style="border:1px solid var(--border);border-radius:var(--radius-md);padding:16px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center">
          <div>
            <div style="font-weight:700;font-size:14px;color:#0f172a"><?= htmlspecialchars($f['title']) ?></div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:4px">مدت زمان تقریبی: ۵ الی ۱۰ دقیقه</div>
          </div>
          <button class="btn btn-primary" onclick="alert('فرم باز شد. شما می‌توانید پاسخ‌های خود را ثبت کنید.')" style="padding:8px 16px;font-size:13px">
            شروع پاسخ
          </button>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Diagnostic Reports for Client -->
  <div class="portal-card">
    <div class="portal-title">
      <span>📄</span>
      <span>گزارش‌ها و بازخوردهای منتشرشده درمانگر</span>
    </div>
    <?php if (empty($clientReports)): ?>
      <div style="text-align:center;padding:20px 0;color:var(--text-muted);font-size:13px">
        🔒 نتایج ارزیابی‌ها پس از تایید نهایی توسط درمانگر محترم در این بخش قرار خواهد گرفت.
      </div>
    <?php else: ?>
      <?php foreach ($clientReports as $cr): ?>
        <div style="background:#f8fafc;border:1px solid var(--border);border-radius:var(--radius-md);padding:16px;margin-bottom:10px">
          <div style="font-size:14px;color:#0f172a;line-height:1.8"><?= htmlspecialchars($cr['text']) ?></div>
          <div style="font-size:11px;color:var(--success-text);margin-top:8px;font-weight:600">✓ تایید و منتشر شده توسط درمانگر</div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

</body>
</html>
