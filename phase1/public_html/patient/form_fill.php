<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۴: تکمیل فرم توسط مراجع (با پیش‌نویس، P8)
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
$me = patient_require_portal_session($db);
$my_role = ROLE_PATIENT;

$assignment_public_id = isset($_GET['a']) ? trim($_GET['a']) : '';
if ($assignment_public_id === '' && isset($_POST['a'])) {
    $assignment_public_id = trim($_POST['a']);
}

$fatal = null;
$error = null;
$notice = null;
$assignment = null;
$submission = null;
$fields = array();
$inputs = array();
$errors = array();
$is_edit = false;
$draft = null;

try {
    if (!phase4_ready($db)) {
        throw new Exception('بخش فرم‌ها هنوز روی این سامانه نصب نشده است.');
    }
    $assignment = form_assignment_find_for_patient($db, $assignment_public_id, $me);
    $fields = form_fields_fetch($db, (int)$assignment['template_id']);
    $submission = form_submission_find_by_assignment($db, (int)$assignment['id']);

    if ($submission) {
        $is_edit = true;
        if ((int)$submission['submitted_by_person_id'] !== $me) {
            throw new Exception(FORM_DENY_MESSAGE);
        }
        if ($submission['status'] !== 'ACTIVE') {
            throw new Exception('این پاسخ باطل شده و ویرایش نمی‌شود.');
        }
        if (!form_submission_in_edit_window($submission)) {
            throw new Exception('مهلت ' . to_persian_digits(FORM_EDIT_WINDOW_HOURS)
                . ' ساعتهٔ ویرایش این فرم به پایان رسیده است.');
        }
    } elseif ($assignment['status'] !== 'PENDING') {
        throw new Exception('این فرم در وضعیت «در انتظار تکمیل» نیست.');
    }
} catch (Exception $ex) {
    $fatal = $ex->getMessage();
}

if ($fatal === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : 'submit';
    $inputs = form_input_keep($fields, $_POST);

    if ($action === 'draft') {
        try {
            form_draft_save($db, $assignment, $fields, $_POST, $me, $my_role);
            $notice = 'پیش‌نویس ذخیره شد. هر وقت خواستید برگردید و ادامه دهید.';
        } catch (Exception $ex) {
            $error = $ex->getMessage();
        }
    } elseif ($action === 'discard') {
        try {
            form_draft_discard($db, $assignment, $me, $my_role);
            $notice = 'پیش‌نویس کنار گذاشته شد.';
            $inputs = array();
        } catch (Exception $ex) {
            $error = $ex->getMessage();
        }
    } else {
        $check = form_values_validate_all($fields, $_POST);
        if (!$check['ok']) {
            $errors = $check['errors'];
            $error = 'چند پاسخ نیاز به اصلاح دارد؛ موارد مشخص‌شده را بررسی کنید.';
        } else {
            try {
                if ($is_edit) {
                    form_submission_edit($db, $assignment, $submission, $fields,
                        $check['values'], $me, $my_role);
                    flash_set('form_success', 'ویرایش شما ثبت شد.');
                } else {
                    form_submission_create($db, $assignment, $fields, $check['values'],
                        $me, $my_role);
                    flash_set('form_success', 'فرم با موفقیت ثبت شد. سپاسگزاریم.');
                }
                redirect('form_view.php?a=' . rawurlencode($assignment['public_id']));
            } catch (Exception $ex) {
                $error = $ex->getMessage();
            }
        }
    }
}

if ($fatal === null) {
    $draft = form_draft_allowed($assignment) ? form_draft_find($db, (int)$assignment['id']) : null;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if ($is_edit) {
            $inputs = form_values_to_inputs($fields,
                form_submission_values_map($db, (int)$submission['id']));
        } elseif ($draft) {
            $inputs = form_draft_load($db, $assignment, $fields, $me, $my_role);
            $notice = 'پیش‌نویس ذخیره‌شدهٔ شما بارگذاری شد ('
                . jalali_display($draft['saved_at']) . ').';
        }
    }
}

$page_title = 'تکمیل فرم';
$active_menu = 'patient_forms';
require __DIR__ . '/../templates/header.php';
?>
<h1>🧾 <?php echo $is_edit ? 'ویرایش فرم' : 'تکمیل فرم'; ?></h1>

<?php if ($fatal !== null) { ?>
  <div class="alert alert-danger"><?php echo e($fatal); ?></div>
  <a class="btn btn-secondary" href="forms.php">بازگشت به فرم‌های من</a>
<?php } else { ?>

<?php if ($notice !== null) { ?>
  <div class="alert alert-info"><?php echo e($notice); ?></div>
<?php } ?>
<?php if ($error !== null) { ?>
  <div class="alert alert-danger"><?php echo e($error); ?></div>
<?php } ?>

<div class="card">
  <div class="card-header"><?php echo e($assignment['template_title']); ?></div>
  <div class="card-body">
    <?php if ($assignment['template_description'] !== null && $assignment['template_description'] !== '') { ?>
      <p class="text-muted"><?php echo e($assignment['template_description']); ?></p>
    <?php } ?>

    <div class="alert alert-info">
      پاسخ‌های شما فقط برای درمانگر مسئول پروندهٔ شما قابل مشاهده است.
      پس از ثبت، تا <?php echo e(to_persian_digits(FORM_EDIT_WINDOW_HOURS)); ?> ساعت
      می‌توانید آن را ویرایش کنید.
    </div>

    <form method="post" action="form_fill.php" class="form-grid">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="a" value="<?php echo e($assignment['public_id']); ?>">
      <?php form_render_fields($fields, $inputs, $errors); ?>

      <div class="form-actions">
        <button type="submit" name="action" value="submit" class="btn btn-primary">
          <?php echo $is_edit ? '💾 ذخیرهٔ ویرایش' : '✅ ثبت نهایی فرم'; ?>
        </button>
        <?php if (!$is_edit && form_draft_allowed($assignment)) { ?>
          <button type="submit" name="action" value="draft" class="btn btn-secondary">
            📝 ذخیرهٔ پیش‌نویس
          </button>
        <?php } ?>
        <a class="btn btn-secondary" href="forms.php">بازگشت</a>
      </div>
    </form>

    <?php if (!$is_edit && $draft) { ?>
      <form method="post" action="form_fill.php" class="inline-form"
            data-confirm="پیش‌نویس ذخیره‌شده پاک شود؟">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="a" value="<?php echo e($assignment['public_id']); ?>">
        <input type="hidden" name="action" value="discard">
        <button type="submit" class="btn btn-sm btn-danger">🗑️ کنار گذاشتن پیش‌نویس</button>
      </form>
    <?php } ?>
  </div>
</div>

<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
