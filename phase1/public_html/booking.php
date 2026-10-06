<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — وصلهٔ ۴.۲.۰: درخواست نوبت اینترنتی، گام ۱ از ۳
 *
 *  این تنها صفحهٔ سامانه است که یک غریبهٔ بدون نشست آن را می‌بیند.
 *  عمداً هیچ نگهبانی ندارد؛ این «فراموشی» نیست، یک تصمیم است و در
 *  phase1/tools/lint/public_pages.txt اعلام و با آزمون خودکار قفل
 *  شده است (الزام ا-۱). تابع auth_bypass() ساخته نشد.
 *
 *  در این گام هیچ‌چیز در دیتابیس نوشته نمی‌شود جز یک ردیف کد
 *  یک‌بارمصرف. ردیف درخواست تنها پس از تأیید پیامکی ساخته می‌شود (ت-۲).
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/includes/bootstrap.php';

$db = $GLOBALS['db'];

$ready = phase4_2_ready($db) && defined('IP_HASH_KEY');
$error_message = '';
$services = $ready ? lookup_items_fetch_active($db, BOOKING_SERVICE_LIST) : array();

/* مقدارهای فرم؛ پس از خطا دوباره نمایش داده می‌شوند تا کاربر از نو ننویسد */
$form = array(
    'first_name' => '', 'last_name' => '', 'gender' => '',
    'service'    => '', 'day' => 'any', 'window' => 'any',
    'free_time'  => '', 'note' => '', 'mobile_number' => '',
);
foreach ($form as $k => $v) {
    if (isset($_POST[$k])) {
        $form[$k] = trim((string)$_POST[$k]);
    }
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $mobile = normalize_mobile_number($form['mobile_number']);

        /* ── اعتبارسنجی ───────────────────────────────────────── */
        $service_label = '';
        foreach ($services as $s) {
            if ($s['item_code'] === $form['service']) {
                $service_label = $s['label'];
                break;
            }
        }

        if ($form['first_name'] === '' || $form['last_name'] === '') {
            $error_message = 'نام و نام خانوادگی را وارد کنید.';
        } elseif (mb_strlen($form['first_name'], 'UTF-8') > 100
                  || mb_strlen($form['last_name'], 'UTF-8') > 100) {
            $error_message = 'نام یا نام خانوادگی بیش از حد طولانی است.';
        } elseif ($service_label === '') {
            $error_message = 'نوع خدمت را از فهرست انتخاب کنید.';
        } elseif (!mobile_number_valid($mobile)) {
            $error_message = 'شمارهٔ موبایل را درست وارد کنید؛ مثل ۰۹۱۲۱۲۳۴۵۶۷.';
        } elseif (mb_strlen($form['note'], 'UTF-8') > BOOKING_NOTE_MAX) {
            $error_message = 'توضیح شما بیش از حد طولانی است.';
        } else {
            $ip   = booking_client_ip();
            $send = booking_otp_send($db, $mobile, $ip);

            if ($send['ok']) {
                /* دادهٔ گام ۱ در نشست می‌ماند تا کاربر دوباره تایپ نکند.
                   هیچ ردیفی هنوز ساخته نشده است. */
                $_SESSION['booking_draft'] = array(
                    'first_name'     => $form['first_name'],
                    'last_name'      => $form['last_name'],
                    'gender'         => $form['gender'],
                    'service_label'  => $service_label,
                    'preferred_text' => booking_preferred_text(
                                            $form['day'], $form['window'], $form['free_time']),
                    'note'           => $form['note'],
                    'mobile'         => $mobile,
                    'started_at'     => time(),
                );
                redirect(APP_BASE_URL . '/booking_verify.php');
            }

            $error_message = $send['message'];
            if (in_array($send['reason'], array('mobile', 'ip', 'global'), true)) {
                booking_audit_rate_limited($db, $send['reason'], $ip);
            }
        }
    } catch (Exception $ex) {
        $ref = log_system_error('BOOKING_REQUEST', $ex);
        render_error_page($ref);
    }
}

$page_title = 'درخواست نوبت';
$layout = 'auth';
$wide_card = true;
$brand_subtitle = 'درخواست نوبت مشاوره';
require __DIR__ . '/templates/header.php';
?>
<h2>📅 درخواست نوبت مشاوره</h2>

<?php if (!$ready) { ?>
  <div class="alert alert-warning">
    ثبت درخواست اینترنتی در حال حاضر فعال نیست.
    <?php if (booking_clinic_phone() !== '') { ?>
      لطفاً با شمارهٔ <span class="mono" dir="ltr"><?php echo e(booking_clinic_phone()); ?></span> تماس بگیرید.
    <?php } ?>
  </div>
<?php } else { ?>

<div class="alert alert-info">
  <strong>این فرم، نوبت قطعی نیست.</strong><br>
  شما ترجیح زمانی‌تان را اعلام می‌کنید و همکاران ما پس از بررسی با شما تماس
  می‌گیرند. <strong>وقت قطعی را منشی طی تماس تلفنی اعلام می‌کند.</strong>
</div>

<?php if ($error_message !== '') { ?>
  <div class="alert alert-error">✗ <?php echo e($error_message); ?>
    <?php if (booking_clinic_phone() !== '') { ?>
      <br><span class="text-small">در صورت نیاز فوری:
      <span class="mono" dir="ltr"><?php echo e(booking_clinic_phone()); ?></span></span>
    <?php } ?>
  </div>
<?php } ?>

<form method="post" action="booking.php" data-guard>
  <?php echo csrf_field(); ?>

  <div class="form-grid">
    <div class="form-group">
      <label for="first_name">نام <span class="text-danger">*</span></label>
      <input type="text" id="first_name" name="first_name" maxlength="100"
             value="<?php echo e($form['first_name']); ?>" required>
    </div>
    <div class="form-group">
      <label for="last_name">نام خانوادگی <span class="text-danger">*</span></label>
      <input type="text" id="last_name" name="last_name" maxlength="100"
             value="<?php echo e($form['last_name']); ?>" required>
    </div>
  </div>

  <div class="form-group">
    <label for="gender">جنسیت</label>
    <select id="gender" name="gender">
      <option value="">اعلام نمی‌کنم</option>
      <option value="MALE"   <?php echo $form['gender'] === 'MALE'   ? 'selected' : ''; ?>>مرد</option>
      <option value="FEMALE" <?php echo $form['gender'] === 'FEMALE' ? 'selected' : ''; ?>>زن</option>
    </select>
  </div>

  <div class="form-group">
    <label for="service">نوع خدمت <span class="text-danger">*</span></label>
    <select id="service" name="service" required>
      <option value="">انتخاب کنید…</option>
      <?php foreach ($services as $s) { ?>
        <option value="<?php echo e($s['item_code']); ?>"
          <?php echo $form['service'] === $s['item_code'] ? 'selected' : ''; ?>>
          <?php echo e($s['label']); ?>
        </option>
      <?php } ?>
    </select>
  </div>

  <div class="form-grid">
    <div class="form-group">
      <label for="day">روز ترجیحی</label>
      <select id="day" name="day">
        <?php foreach (booking_day_options() as $code => $label) { ?>
          <option value="<?php echo e($code); ?>"
            <?php echo $form['day'] === $code ? 'selected' : ''; ?>><?php echo e($label); ?></option>
        <?php } ?>
      </select>
    </div>
    <div class="form-group">
      <label for="window">بازهٔ ترجیحی</label>
      <select id="window" name="window">
        <?php foreach (booking_window_options() as $code => $label) { ?>
          <option value="<?php echo e($code); ?>"
            <?php echo $form['window'] === $code ? 'selected' : ''; ?>><?php echo e($label); ?></option>
        <?php } ?>
      </select>
    </div>
  </div>

  <div class="form-group">
    <label for="free_time">توضیح زمانی (اختیاری)</label>
    <input type="text" id="free_time" name="free_time" maxlength="60"
           placeholder="مثلاً: بعد از ساعت ۱۶ راحت‌ترم"
           value="<?php echo e($form['free_time']); ?>">
    <span class="form-hint">ساعت کاری کلینیک ۹ تا ۲۰ است.</span>
  </div>

  <div class="form-group">
    <label for="note">توضیح کوتاه (اختیاری)</label>
    <textarea id="note" name="note" rows="3"
              maxlength="<?php echo (int)BOOKING_NOTE_MAX; ?>"><?php echo e($form['note']); ?></textarea>
    <span class="form-hint">
      لطفاً اطلاعات پزشکی یا خصوصی ننویسید؛ این فرم برای هماهنگی اولیه است.
    </span>
  </div>

  <div class="form-group">
    <label for="mobile_number">شمارهٔ موبایل <span class="text-danger">*</span></label>
    <input type="text" id="mobile_number" name="mobile_number" dir="ltr"
           data-digits="en" inputmode="numeric" placeholder="09121234567"
           value="<?php echo e($form['mobile_number']); ?>" required>
    <span class="form-hint">یک کد ۶ رقمی برای تأیید شماره برایتان پیامک می‌شود.</span>
  </div>

  <button type="submit" class="btn btn-primary btn-block">ارسال کد تأیید</button>
</form>

<?php } ?>
<?php require __DIR__ . '/templates/footer.php'; ?>
