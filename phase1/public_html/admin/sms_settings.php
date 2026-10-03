<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — تنظیمات پیامک
 *  فقط چهار مقدار SMS_ENABLED / SMS_PROVIDER / SMSIR_API_KEY /
 *  SMSIR_OTP_TEMPLATE_ID را در includes/config.php بازنویسی می‌کند
 *  تا مدیر مجبور به ویرایش دستی فایل نشود.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

$config_path = __DIR__ . '/../includes/config.php';
$error_message = '';
$success_message = '';

/* مقدارهای فعلی */
$current_mode = sms_provider_mode();
$current_template = defined('SMSIR_OTP_TEMPLATE_ID') ? (string)SMSIR_OTP_TEMPLATE_ID : '';
$api_key_set = (defined('SMSIR_API_KEY') && trim((string)SMSIR_API_KEY) !== '');

/** جایگزینی امن مقدار یک ثابت در متن فایل تنظیمات */
function config_replace_define($content, $name, $php_literal)
{
    $pattern = "/define\(\s*'" . preg_quote($name, '/') . "'\s*,\s*[^;]*\);/";
    $replacement = "define('" . $name . "', " . $php_literal . ");";
    if (preg_match($pattern, $content)) {
        return preg_replace($pattern, $replacement, $content, 1);
    }
    /* اگر ثابت در فایل نبود، به انتها افزوده می‌شود */
    return rtrim($content) . "\n" . $replacement . "\n";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : 'save';

    /* ── ارسال پیامک آزمایشی ──────────────────────────────────── */
    if ($action === 'test') {
        $me = person_find_by_id($db, (int)$_SESSION['person_id']);
        if (!$me) {
            $error_message = 'اطلاعات حساب شما یافت نشد.';
        } else {
            $result = otp_create_and_send($db, $me['mobile_number'], 'RESET_PASSWORD', (int)$me['id']);
            if ($result['ok']) {
                $success_message = ($result['mode'] === 'dev')
                    ? 'حالت آزمایشی فعال است؛ کد در فایل storage/logs/sms_debug.log نوشته شد.'
                    : 'پیامک آزمایشی به شمارهٔ ' . $me['mobile_number'] . ' ارسال شد.';
            } else {
                $error_message = $result['message'];
            }
        }

    /* ── ذخیرهٔ تنظیمات ───────────────────────────────────────── */
    } else {
        $mode = isset($_POST['sms_mode']) ? (string)$_POST['sms_mode'] : 'dev';
        $api_key = isset($_POST['smsir_api_key']) ? trim((string)$_POST['smsir_api_key']) : '';
        $template_id = isset($_POST['smsir_template_id'])
            ? preg_replace('/\D/', '', to_latin_digits($_POST['smsir_template_id'])) : '';

        if (!in_array($mode, array('smsir', 'dev', 'off'), true)) {
            $error_message = 'حالت انتخاب‌شده معتبر نیست.';
        } elseif (!is_writable($config_path)) {
            $error_message = 'فایل تنظیمات قابل نوشتن نیست. از File Manager هاست، '
                . 'دسترسی فایل includes/config.php را روی 644 و پوشهٔ includes را روی 755 بگذارید.';
        } else {
            $content = file_get_contents($config_path);
            if ($content === false) {
                $error_message = 'خواندن فایل تنظیمات ممکن نشد.';
            } else {
                $enabled = ($mode === 'off') ? 'false' : 'true';
                $provider = ($mode === 'smsir') ? 'smsir' : 'dev';

                $content = config_replace_define($content, 'SMS_ENABLED', $enabled);
                $content = config_replace_define($content, 'SMS_PROVIDER', "'" . $provider . "'");
                $content = config_replace_define($content, 'SMSIR_OTP_TEMPLATE_ID', "'" . $template_id . "'");

                /* کلید خالی یعنی «مقدار فعلی حفظ شود» */
                if ($api_key !== '' && strpos($api_key, '•') === false) {
                    $safe_key = str_replace(array('\\', "'"), array('\\\\', "\\'"), $api_key);
                    $content = config_replace_define($content, 'SMSIR_API_KEY', "'" . $safe_key . "'");
                }

                if (@file_put_contents($config_path, $content) === false) {
                    $error_message = 'نوشتن فایل تنظیمات ناموفق بود.';
                } else {
                    audit_log_write($db, (int)$_SESSION['person_id'], ROLE_ADMIN, 'SMS_SETTINGS_UPDATED',
                        'config', null, array('mode' => $mode, 'template_id' => $template_id));
                    flash_set('sms_success', 'تنظیمات پیامک ذخیره شد.');
                    redirect(APP_BASE_URL . '/admin/sms_settings.php');
                }
            }
        }
    }
}

$page_title = 'تنظیمات پیامک';
$active_menu = 'sms_settings';
require __DIR__ . '/../templates/header.php';
?>
<h1>📨 تنظیمات پیامک</h1>
<?php echo flash_render('sms_success', 'success'); ?>
<?php if ($success_message !== '') { ?>
  <div class="alert alert-success">✓ <?php echo e($success_message); ?></div>
<?php } ?>
<?php if ($error_message !== '') { ?>
  <div class="alert alert-error">✗ <?php echo e($error_message); ?></div>
<?php } ?>

<div class="alert alert-info">
  این صفحه فقط کلیدهای سامانهٔ پیامک را در فایل تنظیمات به‌روز می‌کند؛ نیازی به ویرایش دستی فایل ندارید.
</div>

<div class="card">
  <div class="card-header">سامانهٔ ارسال پیامک</div>
  <div class="card-body">
    <form method="post" action="sms_settings.php" data-guard>
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="save">

      <div class="form-group">
        <label for="sms_mode">وضعیت ارسال</label>
        <select id="sms_mode" name="sms_mode" data-toggle-target="#keysBox" data-toggle-value="smsir">
          <option value="smsir"<?php echo $current_mode === 'smsir' ? ' selected' : ''; ?>>
            فعال — ارسال واقعی با SMS.ir</option>
          <option value="dev"<?php echo $current_mode === 'dev' ? ' selected' : ''; ?>>
            آزمایشی — کد فقط در فایل لاگ نوشته می‌شود</option>
          <option value="off"<?php echo $current_mode === 'disabled' ? ' selected' : ''; ?>>
            غیرفعال — هیچ پیامکی ارسال نمی‌شود</option>
        </select>
        <span class="form-hint">در حالت غیرفعال، بازیابی رمز عبور پرسنل کار نخواهد کرد.</span>
      </div>

      <div id="keysBox" class="conditional-block">
        <div class="form-group">
          <label for="smsir_api_key">کلید API</label>
          <input type="text" id="smsir_api_key" name="smsir_api_key" dir="ltr"
                 placeholder="<?php echo $api_key_set ? 'ثبت شده — برای حفظ مقدار فعلی خالی بگذارید' : 'کلید API را وارد کنید'; ?>">
          <span class="form-hint">اگر این فیلد را خالی بگذارید، کلید فعلی تغییر نمی‌کند.</span>
        </div>
        <div class="form-group">
          <label for="smsir_template_id">شناسهٔ قالب کد تأیید</label>
          <input type="text" id="smsir_template_id" name="smsir_template_id" dir="ltr" data-digits="en"
                 value="<?php echo e($current_template); ?>">
          <span class="form-hint">نام پارامتر قالب در پنل SMS.ir باید دقیقاً CODE باشد.</span>
        </div>
      </div>

      <div class="btn-row">
        <button type="submit" class="btn btn-primary">ذخیرهٔ تنظیمات</button>
      </div>
    </form>

    <hr class="section-divider">

    <form method="post" action="sms_settings.php" data-guard>
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="test">
      <p class="text-small text-muted mb-2">
        برای آزمایش، یک کد تأیید به شمارهٔ موبایل خودتان ارسال می‌شود.
      </p>
      <button type="submit" class="btn btn-secondary">ارسال پیامک آزمایشی به خودم</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
