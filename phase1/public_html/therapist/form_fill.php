<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۴: تکمیل و ویرایش فرم توسط درمانگر
 *  درمانگر پیش‌نویس ندارد (P8): فرم یا ثبت می‌شود یا رها.
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
$fields = array();
$inputs = array();
$errors = array();
$is_edit = false;

try {
    if (!phase4_ready($db)) {
        throw new Exception('بخش فرم‌ها هنوز روی این سامانه نصب نشده است.');
    }
    $assignment = form_assignment_find_for_therapist($db, $assignment_public_id, $me);
    $fields = form_fields_fetch($db, (int)$assignment['template_id']);
    $submission = form_submission_find_by_assignment($db, (int)$assignment['id']);

    if ($submission) {
        $is_edit = true;
        if ((int)$submission['submitted_by_person_id'] !== $me) {
            throw new Exception('این فرم را شخص دیگری پر کرده است و فقط او می‌تواند آن را ویرایش کند.');
        }
        if ($submission['status'] !== 'ACTIVE') {
            throw new Exception('این پاسخ باطل شده و ویرایش نمی‌شود.');
        }
        if (!form_submission_in_edit_window($submission)) {
            throw new Exception('مهلت ' . to_persian_digits(FORM_EDIT_WINDOW_HOURS)
                . ' ساعتهٔ ویرایش این پاسخ به پایان رسیده است.');
        }
    } else {
        if ($assignment['status'] !== 'PENDING') {
            throw new Exception('این فرم در وضعیت «در انتظار تکمیل» نیست.');
        }
        if ((int)$assignment['assignee_person_id'] !== $me) {
            throw new Exception('پرکنندهٔ این فرم شما نیستید.');
        }
    }
} catch (Exception $ex) {
    $fatal = $ex->getMessage();
}

if ($fatal === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $check = form_values_validate_all($fields, $_POST);
    $inputs = form_input_keep($fields, $_POST);
    if (!$check['ok']) {
        $errors = $check['errors'];
        $error = 'چند پاسخ نیاز به اصلاح دارد؛ موارد مشخص‌شده را بررسی کنید.';
    } else {
        try {
            if ($is_edit) {
                form_submission_edit($db, $assignment, $submission, $fields,
                    $check['values'], $me, $my_role);
                flash_set('form_success', 'ویرایش پاسخ ثبت شد. نسخهٔ پیشین در تاریخچه نگه داشته شد.');
            } else {
                form_submission_create($db, $assignment, $fields, $check['values'],
                    $me, $my_role);
                flash_set('form_success', 'پاسخ فرم ثبت شد. تا '
                    . to_persian_digits(FORM_EDIT_WINDOW_HOURS) . ' ساعت امکان ویرایش دارید.');
            }
            redirect('form_view.php?a=' . rawurlencode($assignment['public_id']));
        } catch (Exception $ex) {
            $error = $ex->getMessage();
        }
    }
} elseif ($fatal === null && $is_edit) {
    $inputs = form_values_to_inputs($fields,
        form_submission_values_map($db, (int)$submission['id']));
}

$page_title = $is_edit ? 'ویرایش فرم' : 'تکمیل فرم';
$active_menu = 'therapist_forms';
require __DIR__ . '/../templates/header.php';
?>
<h1>🧾 <?php echo $is_edit ? 'ویرایش پاسخ فرم' : 'تکمیل فرم'; ?></h1>

<?php if ($fatal !== null) { ?>
  <div class="alert alert-danger"><?php echo e($fatal); ?></div>
  <a class="btn btn-secondary" href="forms.php">بازگشت به فرم‌های من</a>
<?php } else { ?>

<?php if ($error !== null) { ?>
  <div class="alert alert-danger"><?php echo e($error); ?></div>
<?php } ?>

<div class="card">
  <div class="card-header">
    <?php echo e($assignment['template_title']); ?>
    — مراجع: <?php echo e($assignment['patient_first_name'] . ' ' . $assignment['patient_last_name']); ?>
  </div>
  <div class="card-body">
    <?php if ($assignment['template_description'] !== null && $assignment['template_description'] !== '') { ?>
      <p class="text-muted"><?php echo e($assignment['template_description']); ?></p>
    <?php } ?>
    <div class="alert alert-info">
      پس از ثبت، تا <?php echo e(to_persian_digits(FORM_EDIT_WINDOW_HOURS)); ?> ساعت
      می‌توانید پاسخ را ویرایش کنید؛ هر ویرایش در تاریخچه می‌ماند.
      پس از آن تنها راه، ابطال پاسخ است.
    </div>

    <form method="post" action="form_fill.php" class="form-grid">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="a" value="<?php echo e($assignment['public_id']); ?>">
      <?php form_render_fields($fields, $inputs, $errors); ?>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">
          <?php echo $is_edit ? '💾 ذخیرهٔ ویرایش' : '💾 ثبت پاسخ'; ?>
        </button>
        <a class="btn btn-secondary" href="forms.php">انصراف</a>
      </div>
    </form>
  </div>
</div>

<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
