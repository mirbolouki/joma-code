<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — پورتال مراجع، فهرست و پیگیری درخواست‌های نوبت
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
$me = patient_require_portal_session($db);
$ready = patient_appointment_requests_ready($db);
$error_message = '';

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
    $public_id = isset($_POST['request_public_id']) ? trim((string)$_POST['request_public_id']) : '';
    try {
        if ($action !== 'withdraw') {
            throw new Exception('عملیات نامعتبر است.');
        }
        patient_appointment_request_withdraw($db, $public_id, $me);
        flash_set('patient_request_success', 'درخواستِ در انتظار بررسی پس گرفته شد.');
        redirect(APP_BASE_URL . '/patient/requests.php');
    } catch (Exception $ex) {
        $error_message = $ex->getMessage();
    }
}

$rows = array();
if ($ready) {
    try {
        $rows = patient_appointment_requests_for_person($db, $me, 100);
    } catch (Exception $ex) {
        $ref = log_system_error('PATIENT_APPOINTMENT_REQUESTS', $ex);
        render_error_page($ref);
    }
}

$page_title = 'درخواست‌های من';
$active_menu = 'patient_requests';
require __DIR__ . '/../templates/header.php';
?>
<h1>📨 درخواست‌های من</h1>
<?php echo flash_render('patient_request_success', 'success'); ?>
<?php if ($error_message !== '') { ?>
  <div class="alert alert-danger">✗ <?php echo e($error_message); ?></div>
<?php } ?>

<?php if (!$ready) { ?>
  <div class="alert alert-warning">بخش درخواست‌های نوبت هنوز فعال نشده است.</div>
<?php } elseif (count($rows) === 0) { ?>
  <div class="card"><div class="card-body">
    <p class="empty-state">هنوز درخواستی ثبت نکرده‌اید.</p>
    <a class="btn btn-primary" href="appointment_request.php?type=new">درخواست نوبت تازه</a>
  </div></div>
<?php } else { ?>
  <?php foreach ($rows as $row) {
      $target_service = $row['appointment_service_title']
          ? $row['appointment_service_title'] : $row['admission_service_title']; ?>
    <div class="card">
      <div class="card-header">
        <?php echo e(patient_appointment_request_type_label($row['request_type'])); ?>
        <span class="badge <?php echo e(patient_appointment_request_status_class($row['status'])); ?>">
          <?php echo e(patient_appointment_request_status_label($row['status'])); ?>
        </span>
      </div>
      <div class="card-body">
        <div class="item-list">
          <div class="item-card">
            <span class="text-muted text-small">ثبت درخواست</span>
            <div><?php echo e(jalali_display($row['created_at'], 'datetime')); ?></div>
          </div>
          <div class="item-card">
            <span class="text-muted text-small">خدمت</span>
            <div><?php echo e($target_service); ?></div>
          </div>
          <?php if ($row['appointment_start_utc'] !== null) { ?>
            <div class="item-card">
              <span class="text-muted text-small">نوبت مربوط</span>
              <div><?php echo e(appointment_display($row['appointment_start_utc'])); ?>
                — <?php echo e($row['appointment_therapist_name']); ?></div>
            </div>
          <?php } ?>
          <?php if ($row['preferred_text'] !== null && $row['preferred_text'] !== '') { ?>
            <div class="item-card">
              <span class="text-muted text-small">زمان ترجیحی</span>
              <div><?php echo e($row['preferred_text']); ?></div>
            </div>
          <?php } ?>
          <?php if ($row['patient_note'] !== null && $row['patient_note'] !== '') { ?>
            <div class="item-card">
              <span class="text-muted text-small">توضیح شما</span>
              <div><?php echo e($row['patient_note']); ?></div>
            </div>
          <?php } ?>
          <?php if ($row['patient_response'] !== null && $row['patient_response'] !== '') { ?>
            <div class="item-card">
              <span class="text-muted text-small">پیام کلینیک</span>
              <div><?php echo e($row['patient_response']); ?></div>
            </div>
          <?php } ?>
        </div>

        <?php if ($row['status'] === 'NEW') { ?>
          <form method="post" action="requests.php" class="form-actions"
                data-confirm="این درخواست پس گرفته شود؟">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="withdraw">
            <input type="hidden" name="request_public_id" value="<?php echo e($row['public_id']); ?>">
            <button type="submit" class="btn btn-sm btn-secondary">پس گرفتن درخواست</button>
          </form>
        <?php } ?>
      </div>
    </div>
  <?php } ?>
<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
