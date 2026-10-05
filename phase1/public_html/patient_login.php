<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۴: ورود مراجع با کد پیامکی
 *
 *  این صفحه فقط «نشست پورتال» می‌سازد: نقش patient، قفل‌شده روی
 *  پوشهٔ patient/ (تصمیم D4-3). هیچ مسیری از اینجا به پنل پرسنل نیست.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/includes/bootstrap.php';

$db = $GLOBALS['db'];

$error_message = '';
$info_message = '';
$step = 1;
$mobile_value = '';

/* اگر نشست پورتال فعال است، مستقیم به پورتال */
if (isset($_SESSION['person_id']) && patient_session_is_portal()
    && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_BASE_URL . '/patient/index.php');
}

$phase4 = phase4_ready($db);

if ($phase4 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $mobile_value = isset($_POST['mobile_number']) ? trim((string)$_POST['mobile_number']) : '';

    try {
        if ($action === 'request_code') {
            $result = patient_login_start($db, $mobile_value);
            if ($result['ok']) {
                $step = 2;
                $info_message = $result['message'];
            } else {
                $error_message = $result['message'];
            }
        } elseif ($action === 'verify_code') {
            $code = isset($_POST['code']) ? trim((string)$_POST['code']) : '';
            $result = patient_login_verify($db, $mobile_value, $code);
            if ($result['ok']) {
                redirect(APP_BASE_URL . '/patient/index.php');
            }
            $step = 2;
            $error_message = $result['message'];
        }
    } catch (Exception $ex) {
        $ref = log_system_error('PATIENT_LOGIN', $ex);
        render_error_page($ref);
    }
}

$page_title = 'ورود مراجع';
$layout = 'auth';
$brand_subtitle = 'ورود مراجعان با کد پیامکی';
require __DIR__ . '/templates/header.php';
?>
<div class="tabs" role="tablist">
  <a class="tab" href="login.php" role="tab" aria-selected="false">پرسنل</a>
  <button class="tab active" type="button" role="tab" aria-selected="true">مراجع</button>
</div>

<?php if (!$phase4) { ?>
  <div class="alert alert-warning">پورتال مراجعان هنوز روی این سامانه فعال نشده است.</div>
  <p class="text-center mt-2"><a href="login.php" class="text-small text-muted">بازگشت به ورود پرسنل</a></p>
<?php } else { ?>

<?php if ($info_message !== '') { ?>
  <div class="alert alert-info"><?php echo e($info_message); ?></div>
<?php } ?>
<?php if ($error_message !== '') { ?>
  <div class="alert alert-error">✗ <?php echo e($error_message); ?></div>
<?php } ?>

<?php if ($step === 1) { ?>
  <p class="text-muted text-small mb-3">
    شمارهٔ موبایلی را که هنگام پذیرش در کلینیک ثبت کرده‌اید وارد کنید؛
    یک کد ۶ رقمی برایتان پیامک می‌شود.
  </p>
  <form method="post" action="patient_login.php" data-guard>
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="request_code">
    <div class="form-group">
      <label for="mobile_number">شمارهٔ موبایل</label>
      <input type="text" id="mobile_number" name="mobile_number" dir="ltr"
             data-digits="en" inputmode="numeric" placeholder="09121234567"
             value="<?php echo e($mobile_value); ?>" required>
    </div>
    <button type="submit" class="btn btn-primary btn-block">ارسال کد ورود</button>
    <p class="text-center mt-2">
      <a href="login.php" class="text-small text-muted">من از پرسنل کلینیک هستم</a>
    </p>
  </form>
<?php } else { ?>
  <form method="post" action="patient_login.php" data-guard>
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="verify_code">
    <input type="hidden" name="mobile_number" value="<?php echo e($mobile_value); ?>">
    <div class="form-group">
      <label for="code">کد ۶ رقمی</label>
      <input type="text" id="code" name="code" class="otp-input" maxlength="6"
             data-digits="en" inputmode="numeric" autocomplete="one-time-code" required>
      <span class="form-hint">کد تا ۲ دقیقه معتبر است.</span>
    </div>
    <button type="submit" class="btn btn-primary btn-block">ورود به پورتال</button>
    <p class="text-center mt-2">
      <a href="patient_login.php" class="text-small text-muted">تغییر شماره یا درخواست کد جدید</a>
    </p>
  </form>
<?php } ?>

<?php } ?>
<?php require __DIR__ . '/templates/footer.php'; ?>
