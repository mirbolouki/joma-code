<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — بررسی پذیرش و ثبت تصمیم درمانگر
 *  یک فرم واحد: پذیرش مسئولیت یا عدم پذیرش با ذکر دلیل.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_THERAPIST));

$me = (int)$_SESSION['person_id'];
$public_id = isset($_GET['admission_id']) ? trim((string)$_GET['admission_id']) : '';

if ($public_id === '' && isset($_POST['admission_public_id'])) {
    $public_id = trim((string)$_POST['admission_public_id']);
}

try {
    $admission = ($public_id !== '') ? admission_find_by_public_id($db, $public_id) : null;
} catch (Exception $ex) {
    $ref = log_system_error('ADMISSION_REVIEW_FETCH', $ex);
    render_error_page($ref);
}

if (!$admission) {
    http_response_code(404);
    require __DIR__ . '/../templates/404.php';
    exit;
}

/* کنترل مالکیت: فقط درمانگری که پذیرش به او ارجاع شده */
if ((int)$admission['referred_therapist_person_id'] !== $me) {
    http_response_code(403);
    require __DIR__ . '/../templates/403.php';
    exit;
}

$general_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $decision = isset($_POST['decision']) ? (string)$_POST['decision'] : '';
    $reason_id = isset($_POST['decline_reason_id']) ? (int)$_POST['decline_reason_id'] : 0;

    try {
        if ($decision === 'accept') {
            clinical_case_open_from_admission($db, (int)$admission['id'], $me, $me);
            flash_set('therapist_success', 'پذیرش تأیید شد و پروندهٔ بالینی گشوده گردید.');
            redirect(APP_BASE_URL . '/therapist/index.php');
        } elseif ($decision === 'decline') {
            admission_decline($db, (int)$admission['id'], $me, $reason_id, $me);
            flash_set('therapist_success', 'عدم پذیرش ثبت شد و پذیرش برای ارجاع مجدد به منشی بازگشت.');
            redirect(APP_BASE_URL . '/therapist/index.php');
        } else {
            $general_error = 'لطفاً یکی از دو گزینهٔ تصمیم را انتخاب کنید.';
        }
    } catch (Exception $ex) {
        $message = $ex->getMessage();
        if (strpos($message, 'DB_') === 0) {
            $ref = log_system_error('ADMISSION_DECISION', $ex);
            $general_error = 'خطایی رخ داده است. (کد پیگیری: REF-' . $ref . ')';
        } else {
            $general_error = $message;
        }
    }
}

try {
    $patient = person_find_by_id($db, (int)$admission['patient_person_id']);
    $service = service_type_find($db, (int)$admission['service_type_id']);
    $reason = lookup_item_find($db, 'referral_reason', (int)$admission['referral_reason_id']);
    $creator = person_find_by_id($db, (int)$admission['created_by_person_id']);
    $decline_reasons = lookup_items_fetch_active($db, 'admission_decline_reason');
} catch (Exception $ex) {
    $ref = log_system_error('ADMISSION_REVIEW_DATA', $ex);
    render_error_page($ref);
}

$is_open = ($admission['status'] === 'AWAITING_THERAPIST');

$page_title = 'بررسی پذیرش';
$active_menu = 'therapist_home';
require __DIR__ . '/../templates/header.php';
?>
<h1>🔍 بررسی پذیرش</h1>

<?php if ($general_error !== '') { ?>
  <div class="alert alert-error">✗ <?php echo e($general_error); ?></div>
<?php } ?>

<div class="card">
  <div class="card-header">👤 اطلاعات مراجع</div>
  <div class="card-body">
    <dl class="kv">
      <dt>نام و نام خانوادگی</dt><dd><?php echo e(person_full_name($patient)); ?></dd>
      <dt>شمارهٔ موبایل</dt><dd><span class="mono"><?php echo e($patient['mobile_number']); ?></span></dd>
      <dt>کد ملی</dt>
      <dd><?php echo ($patient['national_code'] !== null && $patient['national_code'] !== '')
          ? '<span class="mono">' . e($patient['national_code']) . '</span>'
          : '<span class="text-muted">ثبت نشده</span>'; ?></dd>
    </dl>
  </div>
</div>

<div class="card">
  <div class="card-header">📋 جزئیات پذیرش</div>
  <div class="card-body">
    <dl class="kv">
      <dt>شمارهٔ پذیرش</dt><dd><span class="mono"><?php echo e($admission['public_id']); ?></span></dd>
      <dt>خدمت درخواستی</dt><dd><?php echo e($service ? $service['title'] : '—'); ?></dd>
      <dt>دلیل مراجعه</dt><dd><?php echo e($reason ? $reason['label'] : '—'); ?></dd>
      <dt>تعداد همراه</dt>
      <dd><?php echo ((int)$admission['companion_count'] > 0)
          ? to_persian_digits((int)$admission['companion_count']) . ' نفر'
          : 'بدون همراه'; ?></dd>
      <dt>ثبت‌کننده</dt><dd><?php echo e(person_full_name($creator)); ?></dd>
      <dt>تاریخ درخواست</dt><dd><?php echo e(jalali_display($admission['created_at'])); ?></dd>
      <dt>وضعیت</dt>
      <dd><span class="status <?php echo e(admission_status_class($admission['status'])); ?>">
        <?php echo e(admission_status_label($admission['status'])); ?></span></dd>
    </dl>
  </div>
</div>

<?php if (!$is_open) { ?>
  <div class="alert alert-info">
    این پذیرش قبلاً نهایی شده است و امکان تغییر تصمیم وجود ندارد.
  </div>
  <a href="index.php" class="btn btn-secondary">بازگشت به کارتابل</a>
<?php } else { ?>
  <div class="card">
    <div class="card-header">🎯 تصمیم شما</div>
    <div class="card-body">
      <form method="post" action="admission_review.php?admission_id=<?php echo e($admission['public_id']); ?>" data-guard>
        <?php echo csrf_field(); ?>
        <input type="hidden" name="admission_public_id" value="<?php echo e($admission['public_id']); ?>">

        <label class="radio">
          <input type="radio" name="decision" value="accept" checked data-toggle-target="#acceptNote">
          ✅ پذیرش مسئولیت — پروندهٔ بالینی گشوده شود
        </label>
        <label class="radio">
          <input type="radio" name="decision" value="decline" data-toggle-target="#declineBox">
          ❌ عدم پذیرش — بازگشت به منشی برای ارجاع مجدد
        </label>

        <div id="acceptNote" class="alert alert-info mt-2">
          با پذیرش، یک پروندهٔ بالینی فعال به نام شما گشوده می‌شود.
        </div>

        <div id="declineBox" class="form-group conditional-block mt-2 hidden">
          <label for="decline_reason_id">دلیل عدم پذیرش <span class="required-star">*</span></label>
          <select id="decline_reason_id" name="decline_reason_id">
            <option value="">-- انتخاب کنید --</option>
            <?php foreach ($decline_reasons as $dr) { ?>
              <option value="<?php echo (int)$dr['id']; ?>"><?php echo e($dr['label']); ?></option>
            <?php } ?>
          </select>
        </div>

        <div class="btn-row mt-3">
          <button type="submit" class="btn btn-success">تأیید و ثبت تصمیم</button>
          <a href="index.php" class="btn btn-secondary">بازگشت</a>
        </div>
      </form>
    </div>
  </div>
<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
