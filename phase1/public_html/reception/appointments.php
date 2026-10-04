<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما فاز ۲ — فهرست و مدیریت نوبت‌ها (منشی؛ مدیر هم دسترسی دارد)
 *
 *  فقط بازهٔ انتخاب‌شده از پایگاه داده خوانده می‌شود (نه یک سال کامل).
 *  نوبت هرگز حذف فیزیکی نمی‌شود؛ فقط وضعیتش تغییر می‌کند.
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

try {
    $therapists = therapists_fetch_active($db);
    $cancel_reasons = lookup_items_fetch_active($db, 'cancellation_reason');
} catch (Exception $ex) {
    $ref = log_system_error('APPT_LIST_LOOKUP', $ex);
    render_error_page($ref);
}

/* ── عملیات روی یک نوبت ──────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $public_id = isset($_POST['appointment_public_id']) ? trim((string)$_POST['appointment_public_id']) : '';

    try {
        $appt = appointment_find_by_public_id($db, $public_id);
        if (!$appt) {
            throw new Exception('نوبت موردنظر پیدا نشد.');
        }

        if ($action === 'cancel') {
            $reason_id = isset($_POST['reason_id']) ? $_POST['reason_id'] : '';
            appointment_cancel($db, (int)$appt['id'], $reason_id, $actor_person_id, $actor_role_code);
            flash_set('appt_ok', 'نوبت لغو شد.');
        } elseif ($action === 'completed' || $action === 'no_show') {
            appointment_set_outcome($db, (int)$appt['id'],
                ($action === 'completed' ? 'COMPLETED' : 'NO_SHOW'),
                $actor_person_id, $actor_role_code);
            flash_set('appt_ok', 'وضعیت نوبت ثبت شد.');
        } else {
            throw new Exception('عملیات نامعتبر است.');
        }

        $qs = isset($_POST['return_query']) ? (string)$_POST['return_query'] : '';
        redirect(APP_BASE_URL . '/reception/appointments.php' . ($qs !== '' ? '?' . $qs : ''));
    } catch (Exception $ex) {
        $general_error = $ex->getMessage();
    }
}

/* ── بازهٔ نمایش ─────────────────────────────────────────────────── */
$today = clinic_today();
$from_j = isset($_GET['from']) ? trim((string)$_GET['from']) : gregorian_to_jalali_input($today);
$to_j   = isset($_GET['to'])   ? trim((string)$_GET['to'])   : gregorian_to_jalali_input(date_add_days($today, 7));
$filter_therapist = isset($_GET['therapist']) ? (int)$_GET['therapist'] : 0;
$filter_status = isset($_GET['status']) ? trim((string)$_GET['status']) : '';

$from_g = jalali_input_to_gregorian($from_j);
$to_g   = jalali_input_to_gregorian($to_j);
if ($from_g === null) { $from_g = $today; $from_j = gregorian_to_jalali_input($today); }
if ($to_g === null || $to_g < $from_g) { $to_g = date_add_days($from_g, 7); $to_j = gregorian_to_jalali_input($to_g); }
if (date_diff_days($from_g, $to_g) > 60) {
    $to_g = date_add_days($from_g, 60);
    $to_j = gregorian_to_jalali_input($to_g);
    $general_error = 'بازهٔ نمایش به ۶۰ روز محدود شد.';
}

$valid_status = array('SCHEDULED', 'COMPLETED', 'CANCELLED', 'NO_SHOW');
if (!in_array($filter_status, $valid_status, true)) {
    $filter_status = '';
}

try {
    $appointments = appointments_fetch_range($db, $from_g, $to_g,
        ($filter_therapist > 0 ? $filter_therapist : null),
        ($filter_status !== '' ? $filter_status : null));
} catch (Exception $ex) {
    $ref = log_system_error('APPT_RANGE', $ex);
    render_error_page($ref);
}

$return_query = http_build_query(array(
    'from' => $from_j, 'to' => $to_j,
    'therapist' => ($filter_therapist > 0 ? $filter_therapist : ''),
    'status' => $filter_status,
));

/* گروه‌بندی بر پایهٔ روزِ تهران */
$by_day = array();
foreach ($appointments as $a) {
    $day = substr(utc_to_local($a['appointment_start_utc']), 0, 10);
    if (!isset($by_day[$day])) {
        $by_day[$day] = array();
    }
    $by_day[$day][] = $a;
}

$page_title = 'تقویم نوبت‌ها';
$active_menu = 'appointments';
require __DIR__ . '/../templates/header.php';
?>
<h1>📅 تقویم نوبت‌ها</h1>
<?php echo flash_render('appt_ok', 'success'); ?>
<?php if ($general_error !== '') { ?>
  <div class="alert alert-danger"><?php echo e($general_error); ?></div>
<?php } ?>

<div class="card">
  <div class="card-header">بازه و فیلتر</div>
  <div class="card-body">
    <form method="get" action="appointments.php" class="form-inline">
      <label for="from">از تاریخ</label>
      <input type="text" dir="ltr" id="from" name="from" value="<?php echo e($from_j); ?>">
      <label for="to">تا تاریخ</label>
      <input type="text" dir="ltr" id="to" name="to" value="<?php echo e($to_j); ?>">
      <label for="therapist">درمانگر</label>
      <select id="therapist" name="therapist">
        <option value="">همه</option>
        <?php foreach ($therapists as $t) { ?>
          <option value="<?php echo e($t['id']); ?>" <?php echo ($filter_therapist === (int)$t['id']) ? 'selected' : ''; ?>>
            <?php echo e($t['first_name'] . ' ' . $t['last_name']); ?>
          </option>
        <?php } ?>
      </select>
      <label for="status">وضعیت</label>
      <select id="status" name="status">
        <option value="">همه</option>
        <?php foreach ($valid_status as $s) { ?>
          <option value="<?php echo e($s); ?>" <?php echo ($filter_status === $s) ? 'selected' : ''; ?>>
            <?php echo e(appointment_status_label($s)); ?>
          </option>
        <?php } ?>
      </select>
      <button type="submit" class="btn btn-primary">نمایش</button>
      <a class="btn btn-secondary" href="appointment_new.php">➕ ثبت نوبت</a>
    </form>
    <p class="form-hint">فقط نوبت‌های همین بازه از پایگاه داده خوانده می‌شوند.</p>
  </div>
</div>

<?php if (count($appointments) === 0) { ?>
  <p class="empty-state">در این بازه نوبتی ثبت نشده است.</p>
<?php } ?>

<?php foreach ($by_day as $day => $items) { ?>
<div class="card day-card">
  <div class="card-header">
    <?php echo e(to_persian_digits(gregorian_to_jalali_input($day))); ?>
    — <?php echo to_persian_digits(count($items)); ?> نوبت
  </div>
  <div class="card-body">
    <div class="table-wrap">
      <table class="table table-card">
        <thead>
          <tr><th>ساعت</th><th>مراجع</th><th>درمانگر</th><th>خدمت</th><th>اتاق</th>
              <th>مبلغ</th><th>وضعیت</th><th>عملیات</th></tr>
        </thead>
        <tbody>
        <?php foreach ($items as $a) {
              $local = utc_to_local($a['appointment_start_utc']);
              $local_end = utc_to_local($a['appointment_end_utc']); ?>
          <tr>
            <td data-label="ساعت" dir="ltr">
              <?php echo to_persian_digits(substr($local, 11, 5) . '–' . substr($local_end, 11, 5)); ?>
            </td>
            <td data-label="مراجع"><?php echo e($a['patient_first_name'] . ' ' . $a['patient_last_name']); ?></td>
            <td data-label="درمانگر"><?php echo e($a['therapist_first_name'] . ' ' . $a['therapist_last_name']); ?></td>
            <td data-label="خدمت"><?php echo e($a['service_title']); ?></td>
            <td data-label="اتاق"><?php echo e($a['room_name']); ?></td>
            <td data-label="مبلغ"><?php echo tariff_rial_to_toman_display($a['price_rial']); ?></td>
            <td data-label="وضعیت">
              <span class="badge <?php echo e(appointment_status_class($a['status'])); ?>">
                <?php echo e(appointment_status_label($a['status'])); ?>
              </span>
            </td>
            <td data-label="عملیات">
              <?php if ($a['status'] === 'SCHEDULED') { ?>
                <a class="btn btn-sm btn-secondary"
                   href="appointment_reschedule.php?id=<?php echo e($a['public_id']); ?>">🔁 جابه‌جایی</a>

                <form method="post" action="appointments.php" class="inline-form">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="completed">
                  <input type="hidden" name="appointment_public_id" value="<?php echo e($a['public_id']); ?>">
                  <input type="hidden" name="return_query" value="<?php echo e($return_query); ?>">
                  <button type="submit" class="btn btn-sm btn-success">✅ انجام شد</button>
                </form>

                <form method="post" action="appointments.php" class="inline-form">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="no_show">
                  <input type="hidden" name="appointment_public_id" value="<?php echo e($a['public_id']); ?>">
                  <input type="hidden" name="return_query" value="<?php echo e($return_query); ?>">
                  <button type="submit" class="btn btn-sm btn-warning">🚫 عدم مراجعه</button>
                </form>

                <form method="post" action="appointments.php" class="inline-form">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="cancel">
                  <input type="hidden" name="appointment_public_id" value="<?php echo e($a['public_id']); ?>">
                  <input type="hidden" name="return_query" value="<?php echo e($return_query); ?>">
                  <select name="reason_id" required aria-label="دلیل لغو">
                    <option value="">دلیل لغو…</option>
                    <?php foreach ($cancel_reasons as $reason) { ?>
                      <option value="<?php echo e($reason['id']); ?>"><?php echo e($reason['label']); ?></option>
                    <?php } ?>
                  </select>
                  <button type="submit" class="btn btn-sm btn-danger">✖️ لغو</button>
                </form>
              <?php } else { ?>
                <?php if ($a['cancellation_reason_label'] !== null) { ?>
                  <span class="form-hint"><?php echo e($a['cancellation_reason_label']); ?></span>
                <?php } else { ?>—<?php } ?>
              <?php } ?>
            </td>
          </tr>
        <?php } ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php } ?>

<p class="form-hint">
  لغو نوبت تا <?php echo to_persian_digits(CANCEL_LOCK_MINUTES); ?> دقیقه پیش از شروع جلسه ممکن است.
  «انجام شد» و «عدم مراجعه» فقط پس از رسیدن زمان جلسه فعال می‌شوند.
</p>
<?php require __DIR__ . '/../templates/footer.php'; ?>
