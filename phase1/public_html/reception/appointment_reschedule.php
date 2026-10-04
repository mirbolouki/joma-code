<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما فاز ۲ — جابه‌جایی نوبت (منشی؛ مدیر هم دسترسی دارد)
 *
 *  جابه‌جایی «اتمیک» است: لغو نوبت قبلی و ثبت نوبت تازه در یک تراکنش
 *  انجام می‌شود. اگر هر مرحله شکست بخورد، نوبت قبلی دست‌نخورده می‌ماند.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_SECRETARY));

if (!phase2_ready($db)) {
    redirect(APP_BASE_URL . '/upgrade_phase2.php');
}

$actor_person_id = (int)$_SESSION['person_id'];
$actor_role_code = $_SESSION['active_role_code'];

$general_error = '';
$warnings = array();
$slots = array();

$public_id = '';
if (isset($_GET['id'])) {
    $public_id = trim((string)$_GET['id']);
} elseif (isset($_POST['appointment_public_id'])) {
    $public_id = trim((string)$_POST['appointment_public_id']);
}

try {
    $appt = appointment_find_by_public_id($db, $public_id);
    $rooms = rooms_fetch_active($db);
    $cancel_reasons = lookup_items_fetch_active($db, 'cancellation_reason');
    $date_choices = jalali_date_choices(clinic_today(), BOOKING_HORIZON_DAYS);
} catch (Exception $ex) {
    $ref = log_system_error('RESCHEDULE_LOAD', $ex);
    render_error_page($ref);
}

if (!$appt) {
    $page_title = 'جابه‌جایی نوبت';
    require __DIR__ . '/../templates/header.php';
    echo '<h1>🔁 جابه‌جایی نوبت</h1><div class="alert alert-danger">نوبت موردنظر پیدا نشد.</div>';
    echo '<a class="btn btn-secondary" href="appointments.php">بازگشت به فهرست نوبت‌ها</a>';
    require __DIR__ . '/../templates/footer.php';
    exit;
}

$appt_local_date = substr(utc_to_local($appt['appointment_start_utc']), 0, 10);
$form = array(
    'jalali_date' => jalali_value($appt_local_date < clinic_today() ? clinic_today() : $appt_local_date),
    'room_id'     => $appt['room_id'],
    'reason_id'   => '',
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    foreach (array('jalali_date', 'room_id', 'reason_id') as $f) {
        if (isset($_POST[$f])) {
            $form[$f] = trim((string)$_POST[$f]);
        }
    }

    try {
        if ($appt['status'] !== 'SCHEDULED') {
            throw new Exception('فقط نوبت «رزروشده» قابل جابه‌جایی است.');
        }
        $local_date = jalali_input_to_gregorian($form['jalali_date']);
        if ($local_date === null) {
            throw new Exception('تاریخ انتخاب‌شده معتبر نیست.');
        }

        if ($action === 'slots') {
            $slots = bookable_slots($db, (int)$appt['therapist_person_id'], $local_date,
                (int)$appt['duration_minutes']);
        } elseif ($action === 'move') {
            $start_clock = isset($_POST['start_clock']) ? trim((string)$_POST['start_clock']) : '';
            if ($form['room_id'] === '') {
                throw new Exception('اتاق را انتخاب کنید.');
            }
            if ($form['reason_id'] === '') {
                throw new Exception('دلیل جابه‌جایی را انتخاب کنید.');
            }
            $out = appointment_reschedule($db, (int)$appt['id'], $form['room_id'],
                $local_date, $start_clock, (int)$form['reason_id'],
                $actor_person_id, $actor_role_code);
            $msg = 'نوبت جابه‌جا شد. شناسهٔ نوبت تازه: ' . $out['appointment']['public_id'];
            foreach ($out['warnings'] as $w) {
                $msg .= ' | ' . $w;
            }
            flash_set('appt_ok', $msg);
            redirect(APP_BASE_URL . '/reception/appointments.php');
        }
    } catch (Exception $ex) {
        $general_error = $ex->getMessage();
    }
}

$page_title = 'جابه‌جایی نوبت';
$active_menu = 'appointments';
require __DIR__ . '/../templates/header.php';
?>
<h1>🔁 جابه‌جایی نوبت</h1>
<?php if ($general_error !== '') { ?>
  <div class="alert alert-danger"><?php echo e($general_error); ?></div>
  <div class="alert alert-info">نوبت قبلی دست‌نخورده باقی مانده است.</div>
<?php } ?>

<div class="card">
  <div class="card-header">نوبت فعلی</div>
  <div class="card-body">
    <div class="check-row"><span>مراجع</span><span class="fw-bold">
      <?php echo e($appt['patient_first_name'] . ' ' . $appt['patient_last_name']); ?></span></div>
    <div class="check-row"><span>درمانگر</span><span class="fw-bold">
      <?php echo e($appt['therapist_first_name'] . ' ' . $appt['therapist_last_name']); ?></span></div>
    <div class="check-row"><span>زمان کنونی</span><span class="fw-bold">
      <?php echo appointment_display($appt['appointment_start_utc']); ?></span></div>
    <div class="check-row"><span>مدت</span><span>
      <?php echo to_persian_digits($appt['duration_minutes']); ?> دقیقه (ثابت می‌ماند)</span></div>
    <div class="check-row"><span>مبلغ تثبیت‌شده</span><span>
      <?php echo tariff_rial_to_toman_display($appt['price_rial']); ?></span></div>
    <div class="check-row"><span>وضعیت</span>
      <span class="badge <?php echo e(appointment_status_class($appt['status'])); ?>">
        <?php echo e(appointment_status_label($appt['status'])); ?></span></div>
  </div>
</div>

<div class="card">
  <div class="card-header">زمان تازه</div>
  <div class="card-body">
    <form method="post" action="appointment_reschedule.php" class="form-inline">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="slots">
      <input type="hidden" name="appointment_public_id" value="<?php echo e($appt['public_id']); ?>">
      <label for="jalali_date">تاریخ</label>
      <select id="jalali_date" name="jalali_date">
        <?php foreach ($date_choices as $dc) { ?>
          <option value="<?php echo e($dc['value']); ?>"
            <?php echo ((string)$form['jalali_date'] === $dc['value']) ? 'selected' : ''; ?>>
            <?php echo e($dc['label']); ?>
          </option>
        <?php } ?>
      </select>
      <label for="room_id">اتاق</label>
      <select id="room_id" name="room_id" required>
        <?php foreach ($rooms as $room) { ?>
          <option value="<?php echo e($room['public_id']); ?>"
            <?php echo ((string)$form['room_id'] === (string)$room['id']
                      || (string)$form['room_id'] === $room['public_id']) ? 'selected' : ''; ?>>
            <?php echo e(room_label($room)); ?>
          </option>
        <?php } ?>
      </select>
      <label for="reason_id">دلیل</label>
      <select id="reason_id" name="reason_id" required>
        <option value="">— انتخاب کنید —</option>
        <?php foreach ($cancel_reasons as $reason) { ?>
          <option value="<?php echo e($reason['id']); ?>"
            <?php echo ((string)$form['reason_id'] === (string)$reason['id']) ? 'selected' : ''; ?>>
            <?php echo e($reason['label']); ?>
          </option>
        <?php } ?>
      </select>
      <button type="submit" class="btn btn-secondary">🕒 نمایش زمان‌های آزاد</button>
    </form>

    <?php if (count($slots) > 0) { ?>
      <div class="slot-grid">
        <?php foreach ($slots as $slot) { ?>
          <form method="post" action="appointment_reschedule.php" class="slot-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="move">
            <input type="hidden" name="appointment_public_id" value="<?php echo e($appt['public_id']); ?>">
            <input type="hidden" name="jalali_date" value="<?php echo e($form['jalali_date']); ?>">
            <input type="hidden" name="room_id" value="<?php echo e($form['room_id']); ?>">
            <input type="hidden" name="reason_id" value="<?php echo e($form['reason_id']); ?>">
            <input type="hidden" name="start_clock" value="<?php echo e($slot['clock']); ?>">
            <button type="submit" class="slot-btn"><?php echo e($slot['label']); ?></button>
          </form>
        <?php } ?>
      </div>
      <p class="form-hint">با انتخاب زمان تازه، نوبت قبلی لغو و نوبت تازه در همان لحظه ثبت می‌شود.
        اگر ثبت نوبت تازه ممکن نباشد، هیچ تغییری انجام نخواهد شد.</p>
    <?php } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $general_error === '') { ?>
      <div class="alert alert-warning">
        <strong>در این تاریخ زمان آزادی نیست.</strong><br>
        <?php echo e(slots_empty_reason($db, (int)$appt['therapist_person_id'],
              jalali_input_to_gregorian($form['jalali_date']), (int)$appt['duration_minutes'])); ?>
      </div>
    <?php } ?>
  </div>
</div>

<a class="btn btn-secondary" href="appointments.php">بازگشت به فهرست نوبت‌ها</a>
<?php require __DIR__ . '/../templates/footer.php'; ?>
