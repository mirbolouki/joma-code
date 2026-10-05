<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۴: صفحهٔ نخست پورتال مراجع
 *  نشست این صفحه فقط با کد پیامکی ساخته می‌شود (D4-3).
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
$me = patient_require_portal_session($db);

$pending = array();
$done = array();
$appointments = array();
$fatal = null;

try {
    if (!phase4_ready($db)) {
        throw new Exception('بخش فرم‌ها هنوز روی این سامانه نصب نشده است.');
    }
    form_drafts_cleanup_lazy($db);
    $pending = form_assignments_pending_for_person($db, $me);
    $done = form_assignments_submitted_by_person($db, $me, 20);
    $appointments = patient_upcoming_appointments($db, $me, 5);
} catch (Exception $ex) {
    $fatal = $ex->getMessage();
}

$page_title = 'صفحهٔ من';
$active_menu = 'patient_home';
require __DIR__ . '/../templates/header.php';
?>
<h1>🏠 خوش آمدید</h1>

<?php echo flash_render('form_success', 'success'); ?>

<?php if ($fatal !== null) { ?>
  <div class="alert alert-danger"><?php echo e($fatal); ?></div>
<?php } else { ?>

<div class="card">
  <div class="card-header">فرم‌های در انتظار تکمیل (<?php echo e(to_persian_digits(count($pending))); ?>)</div>
  <div class="card-body">
    <?php if (count($pending) === 0) { ?>
      <p class="empty-state">فرمی در انتظار تکمیل ندارید.</p>
    <?php } else { ?>
      <div class="item-list">
        <?php foreach ($pending as $p) { ?>
          <div class="item-card">
            <div class="item-card-main">
              <div class="item-card-title"><?php echo e($p['template_title']); ?></div>
              <div class="item-card-meta">
                ارسال‌شده در <?php echo e(jalali_display($p['assigned_at'])); ?>
              </div>
            </div>
            <a class="btn btn-sm btn-primary"
               href="form_fill.php?a=<?php echo e($p['public_id']); ?>">تکمیل فرم</a>
          </div>
        <?php } ?>
      </div>
    <?php } ?>
  </div>
</div>

<div class="card">
  <div class="card-header">نوبت‌های پیش‌رو</div>
  <div class="card-body">
    <?php if (count($appointments) === 0) { ?>
      <p class="empty-state">نوبت ثبت‌شده‌ای در آینده ندارید.</p>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead><tr><th>زمان</th><th>خدمت</th><th>درمانگر</th></tr></thead>
          <tbody>
          <?php foreach ($appointments as $ap) { ?>
            <tr>
              <td data-label="زمان"><?php echo e(appointment_display($ap['appointment_start_utc'])); ?></td>
              <td data-label="خدمت"><?php echo e($ap['service_title']); ?></td>
              <td data-label="درمانگر"><?php echo e($ap['therapist_first_name'] . ' ' . $ap['therapist_last_name']); ?></td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
      </div>
    <?php } ?>
  </div>
</div>

<div class="card">
  <div class="card-header">فرم‌های تکمیل‌شدهٔ من (<?php echo e(to_persian_digits(count($done))); ?>)</div>
  <div class="card-body">
    <?php if (count($done) === 0) { ?>
      <p class="empty-state">هنوز فرمی تکمیل نکرده‌اید.</p>
    <?php } else { ?>
      <div class="item-list">
        <?php foreach ($done as $d) { ?>
          <div class="item-card">
            <div class="item-card-main">
              <div class="item-card-title"><?php echo e($d['template_title']); ?></div>
              <div class="item-card-meta">
                ثبت در <?php echo e(jalali_display($d['submitted_at'])); ?>
                <span class="badge <?php echo e(form_assignment_status_class($d['status'])); ?>">
                  <?php echo e(form_assignment_status_label($d['status'])); ?>
                </span>
              </div>
            </div>
            <a class="btn btn-sm btn-secondary"
               href="form_view.php?a=<?php echo e($d['public_id']); ?>">مشاهده</a>
          </div>
        <?php } ?>
      </div>
    <?php } ?>
  </div>
</div>

<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
