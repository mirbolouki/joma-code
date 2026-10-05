<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — داشبورد منشی
 *  پذیرش‌های ردشده (برای ارجاع مجدد) + ۲۰ پذیرش اخیر
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_SECRETARY));   /* مدیر هم به‌واسطهٔ ارث‌بری نقش دسترسی دارد */

$general_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $admission_id = isset($_POST['admission_id']) ? (int)$_POST['admission_id'] : 0;
    $new_therapist = isset($_POST['new_therapist_person_id']) ? (int)$_POST['new_therapist_person_id'] : 0;

    if ($admission_id <= 0 || $new_therapist <= 0) {
        $general_error = 'برای ارجاع مجدد، درمانگر جدید را انتخاب کنید.';
    } else {
        try {
            admission_reassign_therapist($db, $admission_id, $new_therapist, (int)$_SESSION['person_id']);
            flash_set('reception_success', 'پذیرش با موفقیت به درمانگر جدید ارجاع شد.');
            redirect(APP_BASE_URL . '/reception/index.php');
        } catch (Exception $ex) {
            $message = $ex->getMessage();
            if (strpos($message, 'DB_') === 0) {
                $ref = log_system_error('ADMISSION_REASSIGN', $ex);
                $general_error = 'خطایی رخ داده است. (کد پیگیری: REF-' . $ref . ')';
            } else {
                $general_error = $message;
            }
        }
    }
}

try {
    $declined = admissions_fetch_declined($db);
    $recent = admissions_fetch_recent($db, 20);
    $therapists = therapists_fetch_active($db);
} catch (Exception $ex) {
    $ref = log_system_error('RECEPTION_DASHBOARD', $ex);
    render_error_page($ref);
}

$page_title = 'داشبورد منشی';
$active_menu = 'reception_home';
require __DIR__ . '/../templates/header.php';
?>
<h1>📋 داشبورد منشی</h1>
<?php echo flash_render('reception_success', 'success'); ?>
<?php if ($general_error !== '') { ?>
  <div class="alert alert-error">✗ <?php echo e($general_error); ?></div>
<?php } ?>

<div class="action-panel">
<?php if (phase2_ready($db)) { ?>
  <a href="appointment_new.php" class="btn btn-primary">🗓️ ثبت نوبت</a>
  <a href="appointments.php" class="btn btn-secondary">📅 تقویم نوبت‌ها</a>
<?php } ?>
  <a href="admission_new.php" class="btn btn-primary">➕ ثبت پذیرش جدید</a>
</div>

<h2>❌ پذیرش‌های ردشده — نیازمند ارجاع مجدد
  (<?php echo to_persian_digits(count($declined)); ?> مورد)</h2>
<div class="card">
  <div class="table-wrap">
    <table class="table table-card">
      <thead>
        <tr><th>مراجع</th><th>خدمت</th><th>درمانگر قبلی</th><th>دلیل عدم پذیرش</th><th>ارجاع مجدد</th></tr>
      </thead>
      <tbody>
      <?php if (count($declined) === 0) { ?>
        <tr><td colspan="5" class="table-empty">پذیرش ردشده‌ای وجود ندارد. ✓</td></tr>
      <?php } ?>
      <?php foreach ($declined as $row) { ?>
        <tr>
          <td data-label="مراجع"><?php echo e($row['first_name'] . ' ' . $row['last_name']); ?></td>
          <td data-label="خدمت"><?php echo e($row['service_title']); ?></td>
          <td data-label="درمانگر قبلی">
            <?php echo e($row['therapist_first_name'] . ' ' . $row['therapist_last_name']); ?>
          </td>
          <td data-label="دلیل">
            <span class="badge badge-danger"><?php echo e($row['decline_reason_label'] !== null
              ? $row['decline_reason_label'] : 'ثبت نشده'); ?></span>
          </td>
          <td data-label="ارجاع مجدد">
            <form method="post" action="index.php" class="input-group">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="admission_id" value="<?php echo (int)$row['id']; ?>">
              <select name="new_therapist_person_id" aria-label="انتخاب درمانگر جدید" required>
                <option value="">-- انتخاب درمانگر --</option>
                <?php foreach ($therapists as $t) { ?>
                  <option value="<?php echo (int)$t['id']; ?>">
                    <?php echo e($t['first_name'] . ' ' . $t['last_name']); ?>
                  </option>
                <?php } ?>
              </select>
              <button type="submit" class="btn btn-sm btn-primary">↻ ارجاع</button>
            </form>
          </td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
  </div>
</div>

<h2 id="recent">📌 ۲۰ پذیرش اخیر</h2>
<div class="card">
  <div class="table-wrap table-scroll">
    <table class="table table-card">
      <thead>
        <tr><th>مراجع</th><th>خدمت</th><th>درمانگر</th><th>تاریخ ثبت</th><th>وضعیت</th>
          <?php if (phase4_ready($db)) { ?><th>فرم پذیرش</th><?php } ?></tr>
      </thead>
      <tbody>
      <?php if (count($recent) === 0) { ?>
        <tr><td colspan="<?php echo phase4_ready($db) ? 6 : 5; ?>" class="table-empty">هنوز پذیرشی ثبت نشده است.</td></tr>
      <?php } ?>
      <?php foreach ($recent as $row) { ?>
        <tr>
          <td data-label="مراجع"><?php echo e($row['first_name'] . ' ' . $row['last_name']); ?></td>
          <td data-label="خدمت"><?php echo e($row['service_title']); ?></td>
          <td data-label="درمانگر">
            <?php echo e($row['therapist_first_name'] . ' ' . $row['therapist_last_name']); ?>
          </td>
          <td data-label="تاریخ ثبت"><?php echo e(jalali_display($row['created_at'])); ?></td>
          <td data-label="وضعیت">
            <span class="status <?php echo e(admission_status_class($row['status'])); ?>">
              <?php echo e(admission_status_label($row['status'])); ?>
            </span>
          </td>
          <?php if (phase4_ready($db)) {
              /* فقط وضعیت فرم دیده می‌شود، نه محتوای آن (ADR-004 / P4) */
              $intake_state = form_intake_status_for_admission($db, (int)$row['id']); ?>
            <td data-label="فرم پذیرش">
              <?php if ($intake_state === null) { ?>
                <span class="text-muted">—</span>
              <?php } else { ?>
                <span class="badge <?php echo e(form_assignment_status_class($intake_state)); ?>">
                  <?php echo e(form_assignment_status_label($intake_state)); ?>
                </span>
              <?php } ?>
            </td>
          <?php } ?>
        </tr>
      <?php } ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
