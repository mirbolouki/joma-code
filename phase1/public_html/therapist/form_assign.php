<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۴: تخصیص فرم به یک پرونده (درمانگر)
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_THERAPIST));

$me = (int)$_SESSION['person_id'];
$my_role = $_SESSION['active_role_code'];

$case_public_id = isset($_GET['case_id']) ? trim($_GET['case_id']) : '';
if ($case_public_id === '' && isset($_POST['case_id'])) {
    $case_public_id = trim($_POST['case_id']);
}

$fatal = null;
$error = null;
$duplicate_warning = false;
$case = null;
$admission = null;
$templates = array();
$assignments = array();
$selected_template = isset($_POST['template_id']) ? trim($_POST['template_id']) : '';
$selected_role = isset($_POST['assignee_role']) ? trim($_POST['assignee_role']) : '';

try {
    if (!phase4_ready($db)) {
        throw new Exception('بخش فرم‌ها هنوز روی این سامانه نصب نشده است.');
    }
    $case = clinical_case_find_for_therapist($db, $case_public_id, $me);
    $admission = db_select_one($db,
        "SELECT * FROM admissions WHERE id = ? LIMIT 1",
        'i', array((int)$case['admission_id']));
    if (!$admission) {
        throw new Exception('پذیرش این پرونده یافت نشد.');
    }
} catch (Exception $ex) {
    $fatal = $ex->getMessage();
}

if ($fatal === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    try {
        if ($action === 'assign') {
            $template = form_template_find_by_public_id($db, $selected_template);
            if (!$template) {
                throw new Exception('قالب انتخاب‌شده یافت نشد.');
            }
            if ($selected_role !== 'PATIENT') {
                $selected_role = 'THERAPIST';
            }
            $confirm = isset($_POST['confirm_duplicate']);
            try {
                form_assignment_create($db, $admission, $template, $selected_role,
                    $me, $my_role, $confirm);
                flash_set('form_success', 'فرم تخصیص یافت.');
                redirect('form_assign.php?case_id=' . rawurlencode($case['public_id']));
            } catch (Exception $inner) {
                if ($inner->getMessage() === 'DUPLICATE_PENDING') {
                    $duplicate_warning = true;
                    $error = 'یک نسخهٔ در انتظار تکمیل از همین فرم برای این پذیرش وجود دارد. '
                        . 'اگر واقعاً می‌خواهید نسخهٔ دیگری هم تخصیص دهید، تیک تأیید را بزنید.';
                } else {
                    throw $inner;
                }
            }
        } elseif ($action === 'cancel') {
            $a = form_assignment_find_for_therapist($db,
                isset($_POST['a']) ? $_POST['a'] : '', $me);
            form_assignment_cancel($db, $a, $me, $my_role);
            flash_set('form_success', 'تخصیص فرم لغو شد.');
            redirect('form_assign.php?case_id=' . rawurlencode($case['public_id']));
        }
    } catch (Exception $ex) {
        $error = $ex->getMessage();
    }
}

if ($fatal === null) {
    try {
        $templates = form_templates_fetch_active($db);
        $assignments = form_assignments_for_admission($db, (int)$admission['id']);
    } catch (Exception $ex) {
        $fatal = $ex->getMessage();
    }
}

$page_title = 'فرم‌های پرونده';
$active_menu = 'therapist_home';
require __DIR__ . '/../templates/header.php';
?>
<h1>🧾 فرم‌های پرونده</h1>

<?php if ($fatal !== null) { ?>
  <div class="alert alert-danger"><?php echo e($fatal); ?></div>
  <a class="btn btn-secondary" href="index.php">بازگشت به کارتابل</a>
<?php } else { ?>

<?php echo flash_render('form_success', 'success'); ?>
<?php if ($error !== null) { ?>
  <div class="alert alert-<?php echo $duplicate_warning ? 'warning' : 'danger'; ?>">
    <?php echo e($error); ?>
  </div>
<?php } ?>

<div class="action-panel">
  <a class="btn btn-secondary"
     href="case_detail.php?case_id=<?php echo e($case['public_id']); ?>">↩️ بازگشت به پرونده</a>
</div>

<div class="card">
  <div class="card-header">
    مراجع: <?php echo e($case['first_name'] . ' ' . $case['last_name']); ?>
    — پرونده <span class="mono"><?php echo e($case['public_id']); ?></span>
  </div>
  <div class="card-body">
    <?php if (count($assignments) === 0) { ?>
      <p class="empty-state">هنوز فرمی برای این پرونده ثبت نشده است.</p>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead>
            <tr><th>فرم</th><th>پرکننده</th><th>منبع</th><th>وضعیت</th><th>زمان</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($assignments as $a) { ?>
            <tr>
              <td data-label="فرم"><?php echo e($a['template_title']); ?></td>
              <td data-label="پرکننده"><?php echo e(form_assignee_role_label($a['assignee_role'])); ?></td>
              <td data-label="منبع">
                <?php echo ($a['assignment_source'] === 'AUTO_FIRST_APPOINTMENT')
                    ? 'خودکار (نخستین نوبت)' : 'دستی'; ?>
              </td>
              <td data-label="وضعیت">
                <span class="badge <?php echo e(form_assignment_status_class($a['status'])); ?>">
                  <?php echo e(form_assignment_status_label($a['status'])); ?>
                </span>
              </td>
              <td data-label="زمان">
                <?php echo e(jalali_display($a['submitted_at'] !== null
                    ? $a['submitted_at'] : $a['assigned_at'])); ?>
              </td>
              <td data-label="اقدام">
                <?php if ($a['status'] === 'PENDING' && (int)$a['assignee_person_id'] === $me) { ?>
                  <a class="btn btn-sm btn-primary"
                     href="form_fill.php?a=<?php echo e($a['public_id']); ?>">تکمیل</a>
                <?php } elseif ($a['status'] !== 'PENDING' && $a['status'] !== 'CANCELLED') { ?>
                  <a class="btn btn-sm btn-secondary"
                     href="form_view.php?a=<?php echo e($a['public_id']); ?>">مشاهده</a>
                <?php } ?>
                <?php if ($a['status'] === 'PENDING') { ?>
                  <form method="post" action="form_assign.php" class="inline-form"
                        data-confirm="این تخصیص لغو شود؟">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="case_id" value="<?php echo e($case['public_id']); ?>">
                    <input type="hidden" name="a" value="<?php echo e($a['public_id']); ?>">
                    <input type="hidden" name="action" value="cancel">
                    <button type="submit" class="btn btn-sm btn-danger">لغو</button>
                  </form>
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

<div class="card">
  <div class="card-header">تخصیص فرم تازه</div>
  <div class="card-body">
    <?php if (count($templates) === 0) { ?>
      <p class="empty-state">هیچ قالب فعالی برای تخصیص وجود ندارد.</p>
    <?php } else { ?>
      <form method="post" action="form_assign.php" class="form-grid">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="case_id" value="<?php echo e($case['public_id']); ?>">
        <input type="hidden" name="action" value="assign">

        <div class="form-row">
          <label for="template_id">قالب فرم <span class="required-star">*</span></label>
          <select id="template_id" name="template_id" required>
            <option value="">— انتخاب کنید —</option>
            <?php foreach ($templates as $t) { ?>
              <option value="<?php echo e($t['public_id']); ?>"
                data-role="<?php echo e($t['default_assignee_role']); ?>"
                <?php echo ($selected_template === $t['public_id']) ? 'selected' : ''; ?>>
                <?php echo e($t['title'] . ' — '
                    . to_persian_digits((int)$t['field_count']) . ' پرسش'); ?>
              </option>
            <?php } ?>
          </select>
        </div>

        <div class="form-row">
          <label for="assignee_role">چه کسی آن را پر می‌کند؟ <span class="required-star">*</span></label>
          <select id="assignee_role" name="assignee_role" required>
            <option value="THERAPIST" <?php echo ($selected_role === 'THERAPIST') ? 'selected' : ''; ?>>خودم (درمانگر)</option>
            <option value="PATIENT" <?php echo ($selected_role === 'PATIENT') ? 'selected' : ''; ?>>مراجع (در پورتال خودش)</option>
          </select>
          <div class="form-hint">
            فرم مراجع در پورتال او دیده می‌شود؛ ورود مراجع با کد پیامکی انجام می‌شود.
          </div>
        </div>

        <?php if ($duplicate_warning) { ?>
          <div class="form-row">
            <label class="checkbox-label">
              <input type="checkbox" name="confirm_duplicate" value="1">
              می‌دانم نسخهٔ در انتظاری از این فرم هست و باز هم تخصیص می‌دهم
            </label>
          </div>
        <?php } ?>

        <div class="form-actions">
          <button type="submit" class="btn btn-primary">➕ تخصیص فرم</button>
        </div>
      </form>
    <?php } ?>
  </div>
</div>

<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
