<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۴: فرم‌های درمانگر
 *  P7: فرم سند پرونده است؛ درمانگر مسئول همهٔ فرم‌های پرونده را می‌بیند،
 *  صرف‌نظر از اینکه چه کسی آن را پر کرده است.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_THERAPIST));

$me = (int)$_SESSION['person_id'];
$fatal = null;
$rows = array();

try {
    if (!phase4_ready($db)) {
        throw new Exception('بخش فرم‌ها هنوز روی این سامانه نصب نشده است.');
    }
    $rows = form_assignments_for_therapist($db, $me, null, 200);
} catch (Exception $ex) {
    $fatal = $ex->getMessage();
}

$page_title = 'فرم‌های من';
$active_menu = 'therapist_forms';
require __DIR__ . '/../templates/header.php';
?>
<h1>🧾 فرم‌های من</h1>

<?php if ($fatal !== null) { ?>
  <div class="alert alert-danger"><?php echo e($fatal); ?></div>
  <a class="btn btn-secondary" href="index.php">بازگشت به کارتابل</a>
<?php } else { ?>

<?php echo flash_render('form_success', 'success'); ?>
<?php echo flash_render('form_error', 'danger'); ?>

<div class="card">
  <div class="card-header">همهٔ فرم‌های پرونده‌های من (<?php echo e(to_persian_digits(count($rows))); ?>)</div>
  <div class="card-body">
    <?php if (count($rows) === 0) { ?>
      <p class="empty-state">هنوز فرمی برای پرونده‌های شما ثبت نشده است.</p>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead>
            <tr><th>فرم</th><th>مراجع</th><th>پرکننده</th><th>وضعیت</th>
                <th>زمان</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r) { ?>
            <tr>
              <td data-label="فرم"><?php echo e($r['template_title']); ?></td>
              <td data-label="مراجع"><?php echo e($r['patient_first_name'] . ' ' . $r['patient_last_name']); ?></td>
              <td data-label="پرکننده"><?php echo e(form_assignee_role_label($r['assignee_role'])); ?></td>
              <td data-label="وضعیت">
                <span class="badge <?php echo e(form_assignment_status_class($r['status'])); ?>">
                  <?php echo e(form_assignment_status_label($r['status'])); ?>
                </span>
              </td>
              <td data-label="زمان">
                <?php echo e(jalali_display($r['submitted_at'] !== null
                    ? $r['submitted_at'] : $r['assigned_at'])); ?>
              </td>
              <td data-label="اقدام">
                <?php if ($r['status'] === 'PENDING'
                        && (int)$r['assignee_person_id'] === $me) { ?>
                  <a class="btn btn-sm btn-primary"
                     href="form_fill.php?a=<?php echo e($r['public_id']); ?>">تکمیل فرم</a>
                <?php } elseif ($r['status'] === 'PENDING') { ?>
                  <span class="text-muted">در انتظار مراجع</span>
                <?php } else { ?>
                  <a class="btn btn-sm btn-secondary"
                     href="form_view.php?a=<?php echo e($r['public_id']); ?>">مشاهده</a>
                <?php } ?>
              </td>
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
