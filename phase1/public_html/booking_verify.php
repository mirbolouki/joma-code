<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — وصلهٔ ۴.۲.۰: درخواست نوبت اینترنتی، گام ۲ از ۳
 *
 *  تأیید شماره. ت-۲: ردیف درخواست *اینجا* ساخته می‌شود، نه زودتر —
 *  یعنی هیچ ردیفی با شمارهٔ تأییدنشده در جدول قرنطینه وجود ندارد.
 *
 *  صفحهٔ عمومی و عمداً بدون نگهبان (الزام ا-۱، اعلام‌شده در
 *  phase1/tools/lint/public_pages.txt).
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/includes/bootstrap.php';

$db = $GLOBALS['db'];

$ready = phase4_2_ready($db) && defined('IP_HASH_KEY');
if (!$ready) {
    redirect(APP_BASE_URL . '/booking.php');
}

/* بدون پیش‌نویس گام ۱، این صفحه معنا ندارد */
if (!isset($_SESSION['booking_draft']) || !is_array($_SESSION['booking_draft'])) {
    redirect(APP_BASE_URL . '/booking.php');
}
$draft = $_SESSION['booking_draft'];

/* پیش‌نویس کهنه (بیش از ۳۰ دقیقه) دور ریخته می‌شود */
if (!isset($draft['started_at']) || (time() - (int)$draft['started_at']) > 1800) {
    unset($_SESSION['booking_draft']);
    flash_set('error', 'زمان تکمیل درخواست به پایان رسید؛ لطفاً دوباره شروع کنید.');
    redirect(APP_BASE_URL . '/booking.php');
}

$error_message = '';
$info_message  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    try {
        if ($action === 'resend') {
            $ip   = booking_client_ip();
            $send = booking_otp_send($db, $draft['mobile'], $ip);
            if ($send['ok']) {
                $info_message = 'کد تازه برایتان پیامک شد.';
            } else {
                $error_message = $send['message'];
                if (in_array($send['reason'], array('mobile', 'ip', 'global'), true)) {
                    booking_audit_rate_limited($db, $send['reason'], $ip);
                }
            }
        } elseif ($action === 'verify') {
            $code   = isset($_POST['code']) ? trim((string)$_POST['code']) : '';
            $result = otp_verify($db, $draft['mobile'], BOOKING_OTP_PURPOSE, $code);

            if ($result['ok']) {
                $created = booking_request_create($db, $draft, $draft['mobile'], booking_client_ip());

                /* پیش‌نویس مصرف شد؛ رفرش صفحه نباید ردیف دوم بسازد */
                unset($_SESSION['booking_draft']);
                $_SESSION['booking_done'] = $created['public_id'];

                redirect(APP_BASE_URL . '/booking_success.php');
            }
            $error_message = $result['message'];
        }
    } catch (Exception $ex) {
        $ref = log_system_error('BOOKING_VERIFY', $ex);
        render_error_page($ref);
    }
}

$masked = $draft['mobile'];
if (strlen($masked) === 11) {
    $masked = substr($masked, 0, 4) . '***' . substr($masked, 7);
}

$page_title = 'تأیید شماره';
$layout = 'auth';
$brand_subtitle = 'درخواست نوبت مشاوره';
require __DIR__ . '/templates/header.php';
?>
<h2>📱 تأیید شمارهٔ موبایل</h2>

<p class="text-muted text-small mb-3">
  کد ۶ رقمی به شمارهٔ
  <span class="mono" dir="ltr"><?php echo e(to_persian_digits($masked)); ?></span>
  پیامک شد. کد تا ۲ دقیقه معتبر است.
</p>

<?php if ($info_message !== '') { ?>
  <div class="alert alert-info"><?php echo e($info_message); ?></div>
<?php } ?>
<?php if ($error_message !== '') { ?>
  <div class="alert alert-error">✗ <?php echo e($error_message); ?></div>
<?php } ?>

<form method="post" action="booking_verify.php" data-guard>
  <?php echo csrf_field(); ?>
  <input type="hidden" name="action" value="verify">
  <div class="form-group">
    <label for="code">کد ۶ رقمی</label>
    <input type="text" id="code" name="code" class="otp-input" maxlength="6"
           data-digits="en" inputmode="numeric" autocomplete="one-time-code" required>
  </div>
  <button type="submit" class="btn btn-primary btn-block">تأیید و ثبت درخواست</button>
</form>

<form method="post" action="booking_verify.php" class="inline-form mt-2">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="action" value="resend">
  <button type="submit" class="btn btn-secondary btn-sm">ارسال دوبارهٔ کد</button>
</form>

<p class="text-center mt-2">
  <a href="booking.php" class="text-small text-muted">تغییر شماره یا اطلاعات</a>
</p>
<?php require __DIR__ . '/templates/footer.php'; ?>
