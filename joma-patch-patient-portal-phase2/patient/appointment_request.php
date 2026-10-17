<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — پورتال مراجع، فاز ۲: ثبت درخواست نوبت
 *
 *  این فرم فقط درخواست را ثبت می‌کند. تا وقتی پرسنل کلینیک رسیدگی
 *  نکرده‌اند، نوبت تازه قطعی نمی‌شود و نوبت فعلی تغییر نمی‌کند.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
$me = patient_require_portal_session($db);

$allowed_types = array('new', 'cancel', 'reschedule');
$type = isset($_GET['type']) ? trim((string)$_GET['type']) : 'new';
if (!in_array($type, $allowed_types, true)) {
    $type = 'new';
}

$form = array(
    'admission_public_id' => isset($_GET['case']) ? trim((string)$_GET['case']) : '',
    'appointment_public_id' => isset($_GET['appointment']) ? trim((string)$_GET['appointment']) : '',
    'day_code' => 'any',
    'window_code' => 'any',
    'patient_note' => '',
);
$error_message = '';
$target_error = '';
$cases = array();
$selected_case = null;
$appointment = null;
$ready = patient_appointment_requests_ready($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $posted_type = isset($_POST['request_type']) ? trim((string)$_POST['request_type']) : '';
    if (in_array($posted_type, $allowed_types, true)) {
        $type = $posted_type;
    }
    foreach (array_keys($form) as $key) {
        if (isset($_POST[$key])) {
            $form[$key] = trim((string)$_POST[$key]);
        }
    }
}

if ($ready) {
    try {
        if ($type === 'new') {
            $cases = patient_appointment_request_active_cases($db, $me);
            if ($form['admission_public_id'] !== '') {
                $selected_case = patient_appointment_request_case_find(
                    $db, $form['admission_public_id'], $me);
            } elseif (count($cases) === 1) {
                $selected_case = $cases[0];
                $form['admission_public_id'] = $selected_case['public_id'];
            }
        } else {
            $appointment = patient_appointment_request_appointment_find(
                $db, $form['appointment_public_id'], $me);
            if (!$appointment) {
                $target_error = 'نوبت پیدا نشد یا به این حساب تعلق ندارد.';
            } elseif ($appointment['status'] !== 'SCHEDULED'
                || $appointment['appointment_start_utc'] <= now_dt()) {
                $target_error = 'فقط نوبت آیندهٔ رزروشده قابل درخواست است.';
            } elseif ($type === 'reschedule' && $appointment['case_status'] !== 'ACTIVE') {
                $target_error = 'برای پروندهٔ بایگانی‌شده درخواست جابه‌جایی پذیرفته نمی‌شود.';
            }
        }
    } catch (Exception $ex) {
        $ref = log_system_error('PATIENT_APPOINTMENT_REQUEST_LOAD', $ex);
        render_error_page($ref);
    }
}

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && $target_error === '') {
    try {
        if ($type === 'new' && !$selected_case) {
            throw new Exception('پروندهٔ فعال انتخاب نشده است. لطفاً دوباره انتخاب کنید.');
        }
        $saved_type = array(
            'new' => 'NEW_APPOINTMENT',
            'cancel' => 'CANCEL_APPOINTMENT',
            'reschedule' => 'RESCHEDULE_APPOINTMENT',
        );
        $public_id = patient_appointment_request_create(
            $db,
            $me,
            $saved_type[$type],
            $form['admission_public_id'],
            $form['appointment_public_id'],
            $form['day_code'],
            $form['window_code'],
            '',
            $form['patient_note']
        );
        flash_set('patient_request_success', 'درخواست شما ثبت شد؛ نوبت تا رسیدگی کلینیک قطعی یا تغییر داده نمی‌شود.');
        redirect(APP_BASE_URL . '/patient/requests.php');
    } catch (Exception $ex) {
        $error_message = $ex->getMessage();
    }
}

$day_options = booking_day_options();
$window_options = booking_window_options();
$type_titles = array(
    'new' => 'درخواست نوبت تازه',
    'cancel' => 'درخواست لغو نوبت',
    'reschedule' => 'درخواست جابه‌جایی نوبت',
);
$page_title = isset($type_titles[$type]) ? $type_titles[$type] : 'درخواست نوبت';
$active_menu = 'patient_appointments';
require __DIR__ . '/../templates/header.php';
?>
<h1>📨 <?php echo e($page_title); ?></h1>

<?php if (!$ready) { ?>
  <div class="alert alert-warning">بخش درخواست‌های نوبت هنوز فعال نشده است. لطفاً بعداً دوباره بررسی کنید.</div>
<?php } elseif ($target_error !== '') { ?>
  <div class="alert alert-danger"><?php echo e($target_error); ?></div>
  <a class="btn btn-secondary" href="appointments.php">بازگشت به نوبت‌های من</a>
<?php } else { ?>

<div class="alert alert-info">
  <strong>این فرم فقط درخواست است، نه نوبت قطعی.</strong>
  تا رسیدگی کلینیک، نوبت تازه ثبت نمی‌شود و نوبت فعلی شما لغو یا جابه‌جا نخواهد شد.
</div>
<?php if ($error_message !== '') { ?>
  <div class="alert alert-danger">✗ <?php echo e($error_message); ?></div>
<?php } ?>

<?php if ($type === 'new' && count($cases) === 0) { ?>
  <div class="alert alert-warning">
    برای این حساب پروندهٔ فعالی پیدا نشد؛ برای راهنمایی با کلینیک تماس بگیرید.
    <?php if (booking_clinic_phone() !== '') { ?>
      شمارهٔ تماس: <span class="mono" dir="ltr"><?php echo e(booking_clinic_phone()); ?></span>
    <?php } ?>
  </div>
<?php } else { ?>

<?php if ($type === 'new' && $selected_case) { ?>
<div class="card">
  <div class="card-header">پروندهٔ انتخاب‌شده</div>
  <div class="card-body">
    <p><strong><?php echo e($selected_case['service_title']); ?></strong></p>
    <p>درمانگر: <?php echo e($selected_case['therapist_name']); ?></p>
  </div>
</div>
<?php } elseif ($type === 'new') { ?>
<div class="card">
  <div class="card-header">انتخاب پرونده</div>
  <div class="card-body">
    <form method="get" action="appointment_request.php">
      <input type="hidden" name="type" value="new">
      <div class="form-row">
        <label for="case_preview">پرونده‌های فعال شما</label>
        <select id="case_preview" name="case" required>
          <option value="">انتخاب کنید…</option>
          <?php foreach ($cases as $case) { ?>
            <option value="<?php echo e($case['public_id']); ?>"<?php echo $form['admission_public_id'] === $case['public_id'] ? ' selected' : ''; ?>>
              <?php echo e($case['service_title'] . ' — ' . $case['therapist_name']); ?>
            </option>
          <?php } ?>
        </select>
      </div>
      <button type="submit" class="btn btn-secondary">ادامه</button>
    </form>
    <p class="form-hint">برای ادامه، پرونده را انتخاب کنید.</p>
  </div>
</div>
<?php } else { ?>
<div class="card">
  <div class="card-header">نوبت موردنظر</div>
  <div class="card-body">
    <div class="check-row"><span>زمان فعلی</span><span><?php echo e(appointment_display($appointment['appointment_start_utc'])); ?></span></div>
    <div class="check-row"><span>خدمت</span><span><?php echo e($appointment['service_title']); ?></span></div>
    <div class="check-row"><span>درمانگر</span><span><?php echo e($appointment['therapist_name']); ?></span></div>
    <div class="check-row"><span>اتاق</span><span><?php echo $appointment['room_name'] !== null && $appointment['room_name'] !== ''
        ? e($appointment['room_name']) : '—'; ?></span></div>
  </div>
</div>
<?php } ?>

<?php if ($type !== 'new' || $selected_case) { ?>
<form method="post" action="appointment_request.php" class="form-grid" data-guard>
  <?php echo csrf_field(); ?>
  <input type="hidden" name="request_type" value="<?php echo e($type); ?>">
  <input type="hidden" name="admission_public_id" value="<?php echo e($form['admission_public_id']); ?>">
  <input type="hidden" name="appointment_public_id" value="<?php echo e($form['appointment_public_id']); ?>">

  <?php if ($type === 'new' || $type === 'reschedule') { ?>
    <div class="form-grid">
      <div class="form-row">
        <label for="day_code">روز ترجیحی</label>
        <select id="day_code" name="day_code" required>
          <?php foreach ($day_options as $code => $label) { ?>
            <option value="<?php echo e($code); ?>"<?php echo $form['day_code'] === $code ? ' selected' : ''; ?>>
              <?php echo e($label); ?>
            </option>
          <?php } ?>
        </select>
      </div>
      <div class="form-row">
        <label for="window_code">بازهٔ زمانی ترجیحی</label>
        <select id="window_code" name="window_code" required>
          <?php foreach ($window_options as $code => $label) { ?>
            <option value="<?php echo e($code); ?>"<?php echo $form['window_code'] === $code ? ' selected' : ''; ?>>
              <?php echo e($label); ?>
            </option>
          <?php } ?>
        </select>
      </div>
    </div>
    <p class="form-hint">زمان قطعی را کلینیک پس از بررسی هماهنگ می‌کند.</p>
  <?php } ?>

  <div class="form-row">
    <label for="patient_note">توضیح کوتاه (اختیاری)</label>
    <textarea id="patient_note" name="patient_note" rows="3" maxlength="300"><?php echo e($form['patient_note']); ?></textarea>
    <p class="form-hint">لطفاً اطلاعات پزشکی یا خصوصی ننویسید؛ این فرم برای هماهنگی نوبت است.</p>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary">ارسال درخواست</button>
    <a class="btn btn-secondary" href="appointments.php">بازگشت</a>
  </div>
</form>
<?php } ?>
<?php } ?>
<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
