<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۴: مشاهدهٔ یک پاسخ توسط مدیر (فقط‌خواندنی، حسابرسی‌شده)
 *
 *  این صفحه آگاهانه از ADR-004 فاصله می‌گیرد (تصمیم مالک، Q2 = ب، ریسک P4):
 *  مدیر پاسخ فرم را می‌بیند، اما هیچ دکمهٔ تغییری ندارد و هر بازدید
 *  با نام او ثبت می‌شود.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

$me = (int)$_SESSION['person_id'];
$assignment_public_id = isset($_GET['a']) ? trim($_GET['a']) : '';

$fatal = null;
$assignment = null;
$submission = null;
$values = array();

try {
    if (!phase4_ready($db)) {
        throw new Exception('بخش فرم‌ها هنوز روی این سامانه نصب نشده است.');
    }
    $assignment = form_assignment_find_by_public_id($db, $assignment_public_id);
    if (!$assignment) {
        form_access_denied_log($db, $me, 'admin_not_found', $assignment_public_id);
        throw new Exception(FORM_DENY_MESSAGE);
    }
    if (!form_admin_can_view($assignment)) {
        form_access_denied_log($db, $me, 'admin_view_disabled', $assignment_public_id,
            (int)$assignment['id']);
        throw new Exception('برای این قالب، دسترسی مدیر به پاسخ‌ها خاموش است.');
    }
    $submission = form_submission_find_by_assignment($db, (int)$assignment['id']);
    if (!$submission) {
        throw new Exception('برای این فرم هنوز پاسخی ثبت نشده است.');
    }
    $values = form_submission_values_current($db, (int)$submission['id']);

    /* ثبت حسابرسی پیش از نمایش محتوا */
    form_admin_view_log($db, $assignment, (int)$submission['id'], $me);
} catch (Exception $ex) {
    $fatal = $ex->getMessage();
}

$page_title = 'مشاهدهٔ پاسخ فرم';
$active_menu = 'admin_form_responses';
require __DIR__ . '/../templates/header.php';
?>
<h1>📄 پاسخ فرم</h1>

<?php if ($fatal !== null) { ?>
  <div class="alert alert-danger"><?php echo e($fatal); ?></div>
  <a class="btn btn-secondary" href="form_responses.php">بازگشت به فهرست پاسخ‌ها</a>
<?php } else { ?>

<div class="alert alert-info">
  👁️ این مشاهده در گزارش حسابرسی ثبت شد. دسترسی شما فقط‌خواندنی است.
</div>

<div class="card">
  <div class="card-header">
    <?php echo e($assignment['template_title']); ?>
    — نسخهٔ <?php echo e(to_persian_digits((int)$assignment['template_version'])); ?>
    <span class="badge <?php echo e(form_assignment_status_class($assignment['status'])); ?>">
      <?php echo e(form_assignment_status_label($assignment['status'])); ?>
    </span>
  </div>
  <div class="card-body">
    <div class="table-wrap">
      <table class="table table-card">
        <tbody>
          <tr>
            <th>مراجع</th>
            <td><?php echo e($assignment['patient_first_name'] . ' ' . $assignment['patient_last_name']); ?>
              <span class="mono"><?php echo e($assignment['patient_public_id']); ?></span></td>
          </tr>
          <tr><th>پرکننده</th>
              <td><?php echo e(form_assignee_role_label($assignment['assignee_role'])); ?></td></tr>
          <tr><th>زمان ثبت</th>
              <td><?php echo e(jalali_display($submission['submitted_at'])); ?></td></tr>
          <tr><th>شمار ویرایش</th>
              <td><?php echo e(to_persian_digits((int)$submission['edit_count'])); ?></td></tr>
          <?php if ($submission['status'] === 'RETRACTED') { ?>
            <tr><th>ابطال</th>
                <td><?php echo e(jalali_display($submission['retracted_at'])); ?></td></tr>
          <?php } ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($submission['status'] === 'RETRACTED') { ?>
  <div class="alert alert-warning">این پاسخ باطل شده است و صرفاً برای سابقه نگهداری می‌شود.</div>
<?php } ?>

<div class="card">
  <div class="card-header">پاسخ‌ها</div>
  <div class="card-body">
    <?php form_render_answers($values); ?>
  </div>
</div>

<a class="btn btn-secondary" href="form_responses.php">بازگشت به فهرست پاسخ‌ها</a>

<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
