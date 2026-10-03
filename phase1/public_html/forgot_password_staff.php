<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — بازیابی رمز عبور پرسنل (دو مرحله‌ای، با کد پیامکی)
 *
 *  مرحلهٔ ۱: نام کاربری → ارسال کد ۶ رقمی به موبایل ثبت‌شدهٔ همان شخص
 *  مرحلهٔ ۲: کد + رمز جدید
 *
 *  پیام‌ها هرگز فاش نمی‌کنند که نام کاربری وجود دارد یا نه.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/includes/bootstrap.php';

$db = $GLOBALS['db'];
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error_message = '';
$info_message = '';
$resend_wait = 0;

/* ── مرحلهٔ ۱: درخواست کد ───────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_code') {
    csrf_check();
    $identifier = isset($_POST['login_identifier']) ? trim($_POST['login_identifier']) : '';

    if ($identifier === '') {
        $error_message = 'نام کاربری را وارد کنید.';
    } else {
        try {
            $account = account_find_by_identifier($db, $identifier, 'STAFF');
            $sent_ok = true;

            if ($account && $account['status'] === 'ACTIVE') {
                $person = person_find_by_id($db, (int)$account['person_id']);
                if ($person && $person['status'] === 'ACTIVE') {
                    $result = otp_create_and_send($db, $person['mobile_number'], 'RESET_PASSWORD',
                        (int)$person['id']);
                    $sent_ok = $result['ok'];
                    if ($result['ok']) {
                        $_SESSION['reset_identifier'] = $identifier;
                        $_SESSION['reset_mobile'] = $person['mobile_number'];
                        $_SESSION['reset_requested_at'] = time();
                    } elseif (strpos($result['message'], 'تنظیمات پیامک') !== false
                           || strpos($result['message'], 'غیرفعال') !== false) {
                        /* خطای پیکربندی باید به مدیر گزارش شود، نه پنهان بماند */
                        $error_message = $result['message'];
                    } else {
                        $error_message = $result['message'];
                    }
                }
            }

            if ($error_message === '') {
                /* پیام یکسان، چه نام کاربری درست باشد چه نباشد */
                $info_message = 'در صورت صحت نام کاربری، کد تأیید ارسال شد.';
                $step = 2;
                if (isset($_SESSION['reset_mobile'])) {
                    $resend_wait = otp_resend_wait_seconds($db, $_SESSION['reset_mobile'], 'RESET_PASSWORD');
                } else {
                    $resend_wait = defined('OTP_RESEND_COOLDOWN') ? (int)OTP_RESEND_COOLDOWN : 90;
                }
            }
        } catch (Exception $ex) {
            $ref = log_system_error('FORGOT_PASSWORD_REQUEST', $ex);
            render_error_page($ref);
        }
    }
}

/* ── مرحلهٔ ۲: تأیید کد و ثبت رمز جدید ──────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_code') {
    csrf_check();
    $step = 2;
    $code = isset($_POST['code']) ? trim($_POST['code']) : '';
    $new1 = isset($_POST['new_password']) ? (string)$_POST['new_password'] : '';
    $new2 = isset($_POST['new_password_confirm']) ? (string)$_POST['new_password_confirm'] : '';

    if (!isset($_SESSION['reset_mobile']) || !isset($_SESSION['reset_identifier'])) {
        $error_message = 'درخواست شما معتبر نیست؛ لطفاً از ابتدا شروع کنید.';
        $step = 1;
    } elseif (!password_strength_valid($new1)) {
        $error_message = 'رمز جدید باید حداقل ۸ نویسه و شامل حرف و عدد باشد.';
    } elseif ($new1 !== $new2) {
        $error_message = 'رمز جدید و تکرار آن یکسان نیستند.';
    } else {
        try {
            $verify = otp_verify($db, $_SESSION['reset_mobile'], 'RESET_PASSWORD', $code);
            if (!$verify['ok']) {
                $error_message = $verify['message'];
            } else {
                $account = account_find_by_identifier($db, $_SESSION['reset_identifier'], 'STAFF');
                if (!$account || $account['status'] !== 'ACTIVE') {
                    $error_message = 'امکان تغییر رمز برای این حساب وجود ندارد.';
                } else {
                    account_update_password($db, (int)$account['id'], password_hash($new1, PASSWORD_DEFAULT));
                    audit_log_write($db, (int)$account['person_id'], null, 'PASSWORD_RESET_BY_OTP',
                        'account', (int)$account['id'], null);

                    unset($_SESSION['reset_identifier'], $_SESSION['reset_mobile'], $_SESSION['reset_requested_at']);
                    flash_set('login_success', 'رمز عبور شما با موفقیت تغییر کرد؛ اکنون وارد شوید.');
                    redirect(APP_BASE_URL . '/login.php');
                }
            }
        } catch (Exception $ex) {
            $ref = log_system_error('FORGOT_PASSWORD_VERIFY', $ex);
            render_error_page($ref);
        }
    }
}

$page_title = 'بازیابی رمز عبور';
$layout = 'auth';
$brand_subtitle = 'بازیابی رمز عبور پرسنل';
require __DIR__ . '/templates/header.php';
?>
<?php if ($info_message !== '') { ?>
  <div class="alert alert-info"><?php echo e($info_message); ?></div>
<?php } ?>
<?php if ($error_message !== '') { ?>
  <div class="alert alert-error">✗ <?php echo e($error_message); ?></div>
<?php } ?>

<?php if ($step === 1) { ?>
  <h2 style="font-size:20px;color:var(--color-primary);margin-bottom:8px">بازیابی رمز عبور</h2>
  <p class="text-muted text-small mb-3">
    نام کاربری خود را وارد کنید. کد تأیید ۶ رقمی به شمارهٔ موبایل ثبت‌شدهٔ شما پیامک می‌شود.
  </p>
  <form method="post" action="forgot_password_staff.php" data-guard>
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="request_code">
    <div class="form-group">
      <label for="login_identifier">نام کاربری</label>
      <input type="text" id="login_identifier" name="login_identifier" dir="ltr" required>
    </div>
    <button type="submit" class="btn btn-primary btn-block">ارسال کد تأیید</button>
    <p class="text-center mt-2"><a href="login.php" class="text-small text-muted">بازگشت به صفحهٔ ورود</a></p>
  </form>
<?php } else { ?>
  <form method="post" action="forgot_password_staff.php?step=2" data-guard>
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="verify_code">
    <div class="form-group">
      <label for="code">کد تأیید ۶ رقمی</label>
      <input type="text" id="code" name="code" class="otp-input" maxlength="6"
             data-digits="en" inputmode="numeric" autocomplete="one-time-code" required>
      <span class="form-hint">
        کد تا ۲ دقیقه معتبر است و حداکثر ۵ بار می‌توانید آن را وارد کنید.
      </span>
    </div>
    <div class="form-group">
      <label for="new_password">رمز عبور جدید</label>
      <input type="password" id="new_password" name="new_password" autocomplete="new-password" required>
      <div data-strength-for="new_password"></div>
    </div>
    <div class="form-group">
      <label for="new_password_confirm">تکرار رمز عبور جدید</label>
      <input type="password" id="new_password_confirm" name="new_password_confirm"
             autocomplete="new-password" required>
    </div>
    <button type="submit" class="btn btn-primary btn-block">ثبت رمز جدید</button>
  </form>
  <p class="countdown" data-countdown="<?php echo (int)$resend_wait; ?>" data-countdown-enable="#resendLink"></p>
  <p class="text-center">
    <a href="forgot_password_staff.php" id="resendLink" class="text-small">درخواست کد جدید</a>
  </p>
<?php } ?>
<?php require __DIR__ . '/templates/footer.php'; ?>
