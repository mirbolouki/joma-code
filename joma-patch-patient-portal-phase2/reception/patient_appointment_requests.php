<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — میز رسیدگی مدیر/منشی به درخواست‌های نوبت مراجعان
 *
 *  «رسیدگی شد» را فقط پس از انجام کار واقعی در تقویم انتخاب کنید.
 *  این صفحه به‌تنهایی هیچ نوبتی را ثبت، لغو یا جابه‌جا نمی‌کند.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN, ROLE_SECRETARY));

$actor_person_id = (int)$_SESSION['person_id'];
$actor_role_code = isset($_SESSION['active_role_code']) ? $_SESSION['active_role_code'] : ROLE_SECRETARY;
$ready = patient_appointment_requests_ready($db);
$allowed_filters = array('OPEN', 'NEW', 'CONTACTED', 'COMPLETED', 'REJECTED', 'WITHDRAWN', 'ALL');
$status_filter = isset($_GET['status']) ? trim((string)$_GET['status']) : 'OPEN';
if (!in_array($status_filter, $allowed_filters, true)) {
    $status_filter = 'OPEN';
}
$public_id = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$error_message = '';

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
    $public_id = isset($_POST['request_public_id']) ? trim((string)$_POST['request_public_id']) : '';
    $patient_response = isset($_POST['patient_response']) ? trim((string)$_POST['patient_response']) : '';
    $staff_note = isset($_POST['staff_note']) ? trim((string)$_POST['staff_note']) : '';
    try {
        if ($action === 'complete'
            && (!isset($_POST['confirmed_action_done']) || $_POST['confirmed_action_done'] !== '1')) {
            throw new Exception('پیش از بستن درخواست، انجام کار واقعی در تقویم را تأیید کنید.');
        }
        $status_map = array(
            'contacted' => 'CONTACTED',
            'complete' => 'COMPLETED',
            'reject' => 'REJECTED',
        );
        if (!isset($status_map[$action])) {
            throw new Exception('عملیات نامعتبر است.');
        }
        patient_appointment_request_staff_set_status(
            $db, $public_id, $status_map[$action], $patient_response,
            $staff_note, $actor_person_id, $actor_role_code);
        flash_set('patient_request_staff_success', 'وضعیت درخواست به «'
            . patient_appointment_request_status_label($status_map[$action]) . '» تغییر کرد.');
        redirect(APP_BASE_URL . '/reception/patient_appointment_requests.php?status='
            . rawurlencode($status_filter) . '&id=' . rawurlencode($public_id));
    } catch (Exception $ex) {
        $error_message = $ex->getMessage();
    }
}

$rows = array();
$selected = null;
if ($ready) {
    try {
        $rows = patient_appointment_requests_staff_list($db,
            ($status_filter === 'ALL' ? 'ALL' : $status_filter), 100);
        if ($public_id !== '') {
            $selected = patient_appointment_request_staff_find($db, $public_id);
            if (!$selected) {
                $error_message = 'درخواست پیدا نشد.';
            }
        }
    } catch (Exception $ex) {
        $ref = log_system_error('PATIENT_APPOINTMENT_REQUESTS_STAFF', $ex);
        render_error_page($ref);
    }
}

$page_title = 'درخواست‌های نوبت مراجعان';
$active_menu = 'patient_appointment_requests';
require __DIR__ . '/../templates/header.php';
?>
<h1>📥 درخواست‌های نوبت مراجعان</h1>
<?php echo flash_render('patient_request_staff_success', 'success'); ?>
<?php if ($error_message !== '') { ?>
  <div class="alert alert-error">✗ <?php echo e($error_message); ?></div>
<?php } ?>

<?php if (!$ready) { ?>
  <div class="alert alert-warning">این بخش هنوز فعال نشده است. مدیر سامانه باید مهاجرت جدید پورتال مراجع را اجرا کند.</div>
<?php } else { ?>

<div class="card">
  <div class="card-header">فیلتر درخواست‌ها</div>
  <div class="card-body">
    <div class="form-actions">
      <?php
        $filter_labels = array(
            'OPEN' => 'باز', 'ALL' => 'همه', 'NEW' => 'در انتظار',
            'CONTACTED' => 'در حال هماهنگی', 'COMPLETED' => 'رسیدگی‌شده',
            'REJECTED' => 'ردشده', 'WITHDRAWN' => 'پس‌گرفته‌شده',
        );
        foreach ($filter_labels as $filter_code => $filter_label) {
            $button_class = ($status_filter === $filter_code) ? 'btn-primary' : 'btn-secondary';
      ?>
        <a class="btn btn-sm <?php echo e($button_class); ?>"
           href="patient_appointment_requests.php?status=<?php echo e($filter_code); ?>">
          <?php echo e($filter_label); ?>
        </a>
      <?php } ?>
    </div>
  </div>
</div>

<?php if ($selected) {
    $request_type = $selected['request_type'];
    $is_open = in_array($selected['status'], array('NEW', 'CONTACTED'), true);
    $schedule_url = APP_BASE_URL . '/reception/appointment_new.php';
    if ($request_type === 'RESCHEDULE_APPOINTMENT' && $selected['appointment_public_id']) {
        $schedule_url = APP_BASE_URL . '/reception/appointment_reschedule.php?id='
            . rawurlencode($selected['appointment_public_id']);
    } elseif ($request_type === 'CANCEL_APPOINTMENT' && $selected['appointment_start_utc']) {
        $local_date = substr(utc_to_local($selected['appointment_start_utc']), 0, 10);
        $date_value = jalali_value($local_date);
        $schedule_url = APP_BASE_URL . '/reception/appointments.php?'
            . http_build_query(array('from' => $date_value, 'to' => $date_value, 'status' => 'SCHEDULED'));
    }
?>
<div class="card">
  <div class="card-header">
    جزئیات درخواست
    <span class="badge <?php echo e(patient_appointment_request_status_class($selected['status'])); ?>">
      <?php echo e(patient_appointment_request_status_label($selected['status'])); ?>
    </span>
  </div>
  <div class="card-body">
    <div class="item-list">
      <div class="item-card"><span class="text-muted text-small">مراجع</span>
        <div><?php echo e($selected['patient_first_name'] . ' ' . $selected['patient_last_name']); ?></div></div>
      <div class="item-card"><span class="text-muted text-small">شمارهٔ تماس</span>
        <div class="mono" dir="ltr"><?php echo e(to_persian_digits($selected['mobile_number'])); ?></div></div>
      <div class="item-card"><span class="text-muted text-small">نوع درخواست</span>
        <div><?php echo e(patient_appointment_request_type_label($request_type)); ?></div></div>
      <div class="item-card"><span class="text-muted text-small">خدمت</span>
        <div><?php echo e($selected['appointment_service_title']
            ? $selected['appointment_service_title'] : $selected['admission_service_title']); ?></div></div>
      <?php if ($selected['appointment_start_utc']) { ?>
        <div class="item-card"><span class="text-muted text-small">نوبت فعلی</span>
          <div><?php echo e(appointment_display($selected['appointment_start_utc'])); ?>
            — <?php echo e($selected['appointment_therapist_name']); ?>
            (<?php echo e(appointment_status_label($selected['appointment_status'])); ?>)</div></div>
      <?php } ?>
      <?php if ($selected['preferred_text']) { ?>
        <div class="item-card"><span class="text-muted text-small">زمان ترجیحی</span>
          <div><?php echo e($selected['preferred_text']); ?></div></div>
      <?php } ?>
      <div class="item-card"><span class="text-muted text-small">زمان ثبت</span>
        <div><?php echo e(jalali_display($selected['created_at'], 'datetime')); ?></div></div>
      <?php if ($selected['patient_note']) { ?>
        <div class="item-card"><span class="text-muted text-small">توضیح مراجع</span>
          <div><?php echo e($selected['patient_note']); ?></div></div>
      <?php } ?>
      <?php if ($selected['patient_response']) { ?>
        <div class="item-card"><span class="text-muted text-small">پیام قابل‌مشاهده برای مراجع</span>
          <div><?php echo e($selected['patient_response']); ?></div></div>
      <?php } ?>
      <?php if ($selected['staff_note']) { ?>
        <div class="item-card"><span class="text-muted text-small">یادداشت داخلی</span>
          <div><?php echo nl2br(e($selected['staff_note'])); ?></div></div>
      <?php } ?>
    </div>

    <?php if ($is_open) { ?>
      <div class="alert alert-info">
        درخواست را در تقویم معمول سامانه پردازش کنید. برای نوبت تازه از ثبت نوبت استفاده کنید؛
        برای جابه‌جایی یا لغو، نوبت مربوط را در ابزار نوبت‌دهی پردازش کنید.
        این صفحه به‌تنهایی هیچ نوبتی را تغییر نمی‌دهد.
      </div>
      <p><a class="btn btn-sm btn-secondary" href="<?php echo e($schedule_url); ?>">باز کردن ابزار نوبت‌دهی</a></p>

      <div class="card">
        <div class="card-header">به‌روزرسانی درخواست</div>
        <div class="card-body">
          <form method="post" action="patient_appointment_requests.php?status=<?php echo e($status_filter); ?>&amp;id=<?php echo e($selected['public_id']); ?>">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="request_public_id" value="<?php echo e($selected['public_id']); ?>">
            <div class="form-row">
              <label for="patient_response">پیام برای مراجع</label>
              <textarea id="patient_response" name="patient_response" rows="2" maxlength="300"></textarea>
              <p class="form-hint">این پیام در حساب مراجع نمایش داده می‌شود. برای رد درخواست، نوشتن پیام لازم است.</p>
            </div>
            <div class="form-row">
              <label for="staff_note">یادداشت داخلی پرسنل (برای مراجع نمایش داده نمی‌شود)</label>
              <textarea id="staff_note" name="staff_note" rows="2" maxlength="2000"></textarea>
            </div>
            <div class="form-actions">
              <button type="submit" name="action" value="contacted" class="btn btn-secondary">📞 تماس گرفته شد / در حال هماهنگی</button>
              <button type="submit" name="action" value="reject" class="btn btn-danger">رد درخواست</button>
            </div>
            <div class="form-row">
              <label class="checkbox-label">
                <input type="checkbox" name="confirmed_action_done" value="1">
                کار در تقویم معمول سامانه انجام شده است.
              </label>
              <button type="submit" name="action" value="complete" class="btn btn-success">✅ بستن به‌عنوان رسیدگی‌شده</button>
            </div>
          </form>
        </div>
      </div>
    <?php } ?>
  </div>
</div>
<?php } ?>

<div class="card">
  <div class="card-header">فهرست درخواست‌ها (تا ۱۰۰ مورد اخیر)</div>
  <div class="card-body">
    <?php if (count($rows) === 0) { ?>
      <p class="empty-state">درخواستی برای این فیلتر پیدا نشد.</p>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead><tr><th>مراجع</th><th>درخواست</th><th>زمان</th><th>وضعیت</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($rows as $row) { ?>
            <tr>
              <td data-label="مراجع"><?php echo e($row['patient_first_name'] . ' ' . $row['patient_last_name']); ?></td>
              <td data-label="درخواست"><?php echo e(patient_appointment_request_type_label($row['request_type'])); ?></td>
              <td data-label="زمان"><?php echo e(jalali_display($row['created_at'], 'datetime')); ?></td>
              <td data-label="وضعیت"><span class="badge <?php echo e(patient_appointment_request_status_class($row['status'])); ?>">
                <?php echo e(patient_appointment_request_status_label($row['status'])); ?></span></td>
              <td data-label="جزئیات"><a class="btn btn-sm btn-secondary"
                href="patient_appointment_requests.php?status=<?php echo e($status_filter); ?>&amp;id=<?php echo e($row['public_id']); ?>">بازکردن</a></td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
      </div>
    <?php } ?>
  </div>
</div>

<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
