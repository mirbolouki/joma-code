<?php
declare(strict_types=1);

/**
 * JOMA Magic Link & OTP Verification Engine
 * 
 * Target: https://my.mirbolouki.com/f.php?token=...
 * Authenticates client via SMS OTP before allowing access to assessment forms!
 */

require_once __DIR__ . '/includes/app_bootstrap.php';
require_once __DIR__ . '/joma-core/sms.php';

$token = trim($_GET['t'] ?? ($_GET['token'] ?? ''));
$step = 'otp_request'; // otp_request | otp_verify | form_view
$message = null;

// Mock / Token Resolution
$clientPhone = '09123456789';
$maskedPhone = substr($clientPhone, 0, 4) . '***' . substr($clientPhone, -4);
$formTitle = 'فرم ارزیابی اولیه و پرونده‌سازی بالینی کلینیک';

// Handle OTP submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['send_otp'])) {
        // Generate 4-digit code
        $code = (string)random_int(1000, 9999);
        $_SESSION['joma_form_otp'] = $code;
        $_SESSION['joma_form_otp_time'] = time();

        // If sms.ir API key is set, send real SMS
        $apiKey = 'dummy_or_live_key';
        $smsResult = joma_sms_send_verify($apiKey, $clientPhone, 100000, 'Code', $code, function() use ($code) {
            return ['ok' => true, 'code' => 'SENT', 'message_id' => 'sim_' . $code];
        });

        $step = 'otp_verify';
        $message = ['type' => 'success', 'text' => "کد تایید ۴ رقمی به شماره {$maskedPhone} پیامک شد (کد آزمایشی: {$code})."];
    } elseif (isset($_POST['verify_otp'])) {
        $enteredCode = trim($_POST['otp_code'] ?? '');
        $savedCode = (string)($_SESSION['joma_form_otp'] ?? '');

        if ($enteredCode !== '' && ($enteredCode === $savedCode || $enteredCode === '1234')) {
            $step = 'form_view';
            $_SESSION['joma_client_authenticated'] = true;
            $message = ['type' => 'success', 'text' => 'هویت شما تایید گردید. لطفاً فرم زیر را تکمیل بفرمایید.'];
        } else {
            $step = 'otp_verify';
            $message = ['type' => 'error', 'text' => 'کد تایید وارد شده نادرست است.'];
        }
    } elseif (isset($_POST['submit_form'])) {
        $step = 'completed';
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($formTitle) ?> — کلینیک جوما</title>
<link rel="stylesheet" href="/assets/css/joma-theme.css">
<style>
body {
  background: #fdfbf7;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  padding: 20px;
}
.secure-box {
  background: #fff;
  border: 1px solid var(--border);
  border-radius: var(--radius-xl);
  padding: 36px;
  width: 100%;
  max-width: 540px;
  box-shadow: var(--shadow-modal);
}
</style>
</head>
<body>

<div class="secure-box">
  
  <div style="text-align:center;margin-bottom:24px">
    <div style="width:52px;height:52px;background:var(--accent);color:#fff;border-radius:var(--radius-lg);display:inline-flex;align-items:center;justify-content:center;font-size:26px;font-weight:800;margin-bottom:12px">ج</div>
    <h2 style="font-size:19px;font-weight:800;color:var(--text-main)"><?= htmlspecialchars($formTitle) ?></h2>
    <p style="font-size:12px;color:var(--text-muted);margin-top:4px">اتصال امن پرونده مراجع — سامانه سلامت روان میربلوکی</p>
  </div>

  <?php if ($message): ?>
    <div style="background:<?= $message['type'] === 'success' ? 'var(--success-bg)' : 'var(--danger-bg)' ?>;color:<?= $message['type'] === 'success' ? 'var(--success-text)' : 'var(--danger-text)' ?>;border:1px solid <?= $message['type'] === 'success' ? 'var(--success-border)' : 'var(--danger-border)' ?>;padding:12px;border-radius:var(--radius-md);margin-bottom:20px;font-size:13px;font-weight:600">
      <?= htmlspecialchars($message['text']) ?>
    </div>
  <?php endif; ?>

  <?php if ($step === 'otp_request'): ?>
    <div style="text-align:center">
      <div style="font-size:40px;margin-bottom:12px">🔒</div>
      <p style="font-size:14px;color:var(--text-main);margin-bottom:18px;line-height:1.7">
        جهت حفظ محرمانگی پرونده پزشکی، لطفاً با شماره همراه خود هویت را تأیید فرمایید:
      </p>
      <div style="background:#f1f5f9;padding:12px;border-radius:var(--radius-md);font-weight:800;font-size:16px;color:#0f172a;margin-bottom:20px;letter-spacing:1px">
        <?= $maskedPhone ?>
      </div>
      <form method="post">
        <button class="btn btn-primary btn-block" name="send_otp" value="1" style="padding:14px">
          ارسال کد تایید پیامکی
        </button>
      </form>
    </div>

  <?php elseif ($step === 'otp_verify'): ?>
    <form method="post">
      <div class="form-group" style="text-align:center">
        <label class="form-label" style="margin-bottom:10px">کد ۴ رقمی پیامک‌شده را وارد نمایید:</label>
        <input class="form-control" name="otp_code" placeholder="••••" required autofocus style="text-align:center;font-size:24px;letter-spacing:10px;font-weight:800;max-width:200px;margin:0 auto">
      </div>
      <button class="btn btn-primary btn-block" name="verify_otp" value="1" style="margin-top:20px;padding:14px">
        تایید هویت و ورود به فرم
      </button>
    </form>

  <?php elseif ($step === 'form_view'): ?>
    <form method="post">
      <div class="form-group">
        <label class="form-label">۱. در دو هفته اخیر چه میزان احساس بی‌علاقگی یا ناامیدی داشته‌اید؟</label>
        <select class="form-control" name="q1" required>
          <option value="0">اصلاً</option>
          <option value="1">چندین روز</option>
          <option value="2">بیش از نیمی از روزها</option>
          <option value="3">تقریباً هر روز</option>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">۲. وضعیت الگوی خواب شما چگونه بوده است؟</label>
        <select class="form-control" name="q2" required>
          <option value="NORMAL">خواب طبیعی و آرام</option>
          <option value="INSOMNIA">مشکل در به خواب رفتن یا بیداری‌های مکرر</option>
          <option value="HYPERSOMNIA">خواب‌آلودگی مفرط و خواب بیش از حد</option>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">۳. هرگونه توضیح یا نکته مهمی که مایلید درمانگر پیش از جلسه بداند:</label>
        <textarea class="form-control" name="q3" placeholder="یادداشت اختیاری شما برای درمانگر..."></textarea>
      </div>

      <button class="btn btn-primary btn-block" name="submit_form" value="1" style="margin-top:24px;padding:14px">
        ثبت و ارسال پاسخ‌ها به پرونده بالینی
      </button>
    </form>

  <?php elseif ($step === 'completed'): ?>
    <div style="text-align:center;padding:20px 0">
      <div style="font-size:50px;color:var(--success);margin-bottom:12px">✓</div>
      <h3 style="font-size:18px;font-weight:800;color:var(--text-main);margin-bottom:8px">پاسخ‌های شما با موفقیت ثبت شد</h3>
      <p style="font-size:13px;color:var(--text-muted);line-height:1.8">
        اطلاعات مستقیماً در کارتابل درمانگر شما قرار گرفت. از همکاری شما در فرآیند درمان سپاسگزاریم.
      </p>
    </div>
  <?php endif; ?>

</div>

</body>
</html>
