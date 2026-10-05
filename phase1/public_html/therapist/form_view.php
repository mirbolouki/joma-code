<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۴: مشاهدهٔ پاسخ فرم (درمانگر مسئول)، با تاریخچه و ابطال
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_THERAPIST));

$me = (int)$_SESSION['person_id'];
$my_role = $_SESSION['active_role_code'];

$assignment_public_id = isset($_GET['a']) ? trim($_GET['a']) : '';
if ($assignment_public_id === '' && isset($_POST['a'])) {
    $assignment_public_id = trim($_POST['a']);
}

$fatal = null;
$error = null;
$assignment = null;
$submission = null;
$values = array();
$revisions = array();
$reasons = array();
$show_revision = null;
$revision_values = array();

try {
    if (!phase4_ready($db)) {
        throw new Exception('بخش فرم‌ها هنوز روی این سامانه نصب نشده است.');
    }
    $assignment = form_assignment_find_for_therapist($db, $assignment_public_id, $me);
    $submission = form_submission_find_by_assignment($db, (int)$assignment['id']);
    if (!$submission) {
        throw new Exception('برای این فرم هنوز پاسخی ثبت نشده است.');
    }
} catch (Exception $ex) {
    $fatal = $ex->getMessage();
}

if ($fatal === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['action']) && $_POST['action'] === 'retract') {
        try {
            form_submission_retract($db, $assignment, $submission,
                isset($_POST['reason_id']) ? $_POST['reason_id'] : 0, $me, $my_role);
            flash_set('form_success', 'پاسخ باطل شد. محتوای آن برای سابقه نگه داشته می‌شود.');
            redirect('form_view.php?a=' . rawurlencode($assignment['public_id']));
        } catch (Exception $ex) {
            $error = $ex->getMessage();
        }
    }
}

if ($fatal === null) {
    try {
        $submission = form_submission_find_by_assignment($db, (int)$assignment['id']);
        $values = form_submission_values_current($db, (int)$submission['id']);
        $revisions = form_submission_revisions($db, (int)$submission['id']);
        $reasons = form_retraction_reasons($db);
        if (isset($_GET['rev'])) {
            $rev = (int)to_latin_digits(trim($_GET['rev']));
            if ($rev > 0 && $rev < (int)$submission['current_revision']) {
                $show_revision = $rev;
                $revision_values = form_submission_values_revision($db,
                    (int)$submission['id'], $rev);
            }
        }
    } catch (Exception $ex) {
        $fatal = $ex->getMessage();
    }
}

$page_title = 'پاسخ فرم';
$active_menu = 'therapist_forms';
require __DIR__ . '/../templates/header.php';
?>
<h1>📄 پاسخ فرم</h1>

<?php if ($fatal !== null) { ?>
  <div class="alert alert-danger"><?php echo e($fatal); ?></div>
  <a class="btn btn-secondary" href="forms.php">بازگشت به فرم‌های من</a>
<?php } else { ?>

<?php echo flash_render('form_success', 'success'); ?>
<?php if ($error !== null) { ?>
  <div class="alert alert-danger"><?php echo e($error); ?></div>
<?php } ?>

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
          <tr><th>مراجع</th>
              <td><?php echo e($assignment['patient_first_name'] . ' ' . $assignment['patient_last_name']); ?></td></tr>
          <tr><th>پرکننده</th>
              <td><?php echo e(form_assignee_role_label($assignment['assignee_role'])); ?></td></tr>
          <tr><th>زمان ثبت</th>
              <td><?php echo e(jalali_display($submission['submitted_at'])); ?></td></tr>
          <tr><th>شمار ویرایش</th>
              <td><?php echo e(to_persian_digits((int)$submission['edit_count'])); ?></td></tr>
        </tbody>
      </table>
    </div>

    <?php if ($submission['status'] === 'ACTIVE'
            && (int)$submission['submitted_by_person_id'] === $me
            && form_submission_in_edit_window($submission)) { ?>
      <div class="alert alert-info">
        ⏳ مهلت ویرایش: <?php echo e(form_edit_window_left($submission)); ?> دیگر.
      </div>
      <a class="btn btn-primary"
         href="form_fill.php?a=<?php echo e($assignment['public_id']); ?>">✏️ ویرایش پاسخ</a>
    <?php } ?>
    <a class="btn btn-secondary" href="forms.php">بازگشت به فرم‌های من</a>
  </div>
</div>

<?php if ($submission['status'] === 'RETRACTED') { ?>
  <div class="alert alert-warning">
    این پاسخ در <?php echo e(jalali_display($submission['retracted_at'])); ?> باطل شده است.
  </div>
<?php } ?>

<div class="card">
  <div class="card-header">پاسخ‌های جاری</div>
  <div class="card-body">
    <?php form_render_answers($values); ?>
  </div>
</div>

<?php if (count($revisions) > 1) { ?>
<div class="card">
  <div class="card-header">تاریخچهٔ ویرایش‌ها</div>
  <div class="card-body">
    <div class="table-wrap">
      <table class="table table-card">
        <thead><tr><th>بازنگری</th><th>زمان</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($revisions as $rv) { ?>
          <tr>
            <td data-label="بازنگری"><?php echo e(to_persian_digits((int)$rv['revision'])); ?>
              <?php if ((int)$rv['revision'] === (int)$submission['current_revision']) { ?>
                <span class="badge badge-success">جاری</span>
              <?php } ?>
            </td>
            <td data-label="زمان"><?php echo e(jalali_display($rv['created_at'])); ?></td>
            <td data-label="اقدام">
              <?php if ((int)$rv['revision'] < (int)$submission['current_revision']) { ?>
                <a class="btn btn-sm btn-secondary"
                   href="form_view.php?a=<?php echo e($assignment['public_id']); ?>&amp;rev=<?php echo (int)$rv['revision']; ?>">
                  مشاهدهٔ این بازنگری</a>
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

<?php if ($show_revision !== null) { ?>
<div class="card">
  <div class="card-header">بازنگری <?php echo e(to_persian_digits($show_revision)); ?> (تاریخی)</div>
  <div class="card-body">
    <?php form_render_answers($revision_values); ?>
  </div>
</div>
<?php } ?>

<?php if ($submission['status'] === 'ACTIVE') { ?>
<div class="card">
  <div class="card-header">ابطال پاسخ</div>
  <div class="card-body">
    <div class="alert alert-warning">
      ابطال، پاسخ را پاک نمی‌کند؛ آن را با نشان «باطل‌شده» نگه می‌دارد.
      این کار برگشت‌پذیر نیست.
    </div>
    <form method="post" action="form_view.php" class="form-grid"
          data-confirm="این پاسخ باطل شود؟">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="a" value="<?php echo e($assignment['public_id']); ?>">
      <input type="hidden" name="action" value="retract">
      <div class="form-row">
        <label for="reason_id">دلیل ابطال <span class="required-star">*</span></label>
        <select id="reason_id" name="reason_id" required>
          <option value="">— انتخاب کنید —</option>
          <?php foreach ($reasons as $r) { ?>
            <option value="<?php echo (int)$r['id']; ?>"><?php echo e($r['label']); ?></option>
          <?php } ?>
        </select>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-danger">🚫 ابطال پاسخ</button>
      </div>
    </form>
  </div>
</div>
<?php } ?>

<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
