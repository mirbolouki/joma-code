<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۴: مشاهدهٔ پاسخ ثبت‌شدهٔ خودِ مراجع
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
$me = patient_require_portal_session($db);

$assignment_public_id = isset($_GET['a']) ? trim($_GET['a']) : '';

$fatal = null;
$assignment = null;
$submission = null;
$values = array();

try {
    if (!phase4_ready($db)) {
        throw new Exception('بخش فرم‌ها هنوز روی این سامانه نصب نشده است.');
    }
    $assignment = form_assignment_find_for_patient($db, $assignment_public_id, $me);
    $submission = form_submission_find_by_assignment($db, (int)$assignment['id']);
    if (!$submission) {
        throw new Exception('برای این فرم هنوز پاسخی ثبت نشده است.');
    }
    $values = form_submission_values_current($db, (int)$submission['id']);
} catch (Exception $ex) {
    $fatal = $ex->getMessage();
}

$page_title = 'پاسخ فرم من';
$active_menu = 'patient_forms';
require __DIR__ . '/../templates/header.php';
?>
<h1>📄 پاسخ فرم من</h1>

<?php echo flash_render('form_success', 'success'); ?>

<?php if ($fatal !== null) { ?>
  <div class="alert alert-danger"><?php echo e($fatal); ?></div>
  <a class="btn btn-secondary" href="forms.php">بازگشت به فرم‌های من</a>
<?php } else { ?>

<div class="card">
  <div class="card-header">
    <?php echo e($assignment['template_title']); ?>
    <span class="badge <?php echo e(form_assignment_status_class($assignment['status'])); ?>">
      <?php echo e(form_assignment_status_label($assignment['status'])); ?>
    </span>
  </div>
  <div class="card-body">
    <p class="text-muted">ثبت در <?php echo e(jalali_display($submission['submitted_at'])); ?></p>

    <?php if ($submission['status'] === 'ACTIVE' && form_submission_in_edit_window($submission)) { ?>
      <div class="alert alert-info">
        ⏳ تا <?php echo e(form_edit_window_left($submission)); ?> دیگر می‌توانید پاسخ‌ها را ویرایش کنید.
      </div>
      <a class="btn btn-primary"
         href="form_fill.php?a=<?php echo e($assignment['public_id']); ?>">✏️ ویرایش پاسخ‌ها</a>
    <?php } ?>
    <a class="btn btn-secondary" href="forms.php">بازگشت به فرم‌های من</a>
  </div>
</div>

<?php if ($submission['status'] === 'RETRACTED') { ?>
  <div class="alert alert-warning">این پاسخ باطل شده است.</div>
<?php } ?>

<div class="card">
  <div class="card-header">پاسخ‌های شما</div>
  <div class="card-body">
    <?php form_render_answers($values); ?>
  </div>
</div>

<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
