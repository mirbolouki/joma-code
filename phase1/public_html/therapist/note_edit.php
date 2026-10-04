<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۳: ویرایش یادداشت محرمانه (فقط در پنجرهٔ ۲۴ ساعته)
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_THERAPIST));

$me = (int)$_SESSION['person_id'];
$case_public_id = isset($_GET['case_id']) ? trim($_GET['case_id']) : '';
$note_public_id = isset($_GET['note_id']) ? trim($_GET['note_id']) : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $case_public_id = isset($_POST['case_id']) ? trim($_POST['case_id']) : $case_public_id;
    $note_public_id = isset($_POST['note_id']) ? trim($_POST['note_id']) : $note_public_id;
}

$case = null;
$note = null;
$fatal = null;
$field_error = null;
$text_value = null;

try {
    if (!phase3_ready($db)) {
        throw new Exception('بخش پروندهٔ بالینی هنوز روی این سامانه نصب نشده است.');
    }
    $case = clinical_case_find_for_therapist($db, $case_public_id, $me);
    $note = note_fetch($db, (int)$case['id'], $note_public_id, $me, false);

    if ($note['status'] !== 'ACTIVE') {
        throw new Exception('یادداشت باطل‌شده قابل ویرایش نیست.');
    }
    if (!note_is_editable($note)) {
        throw new Exception('مهلت ' . to_persian_digits(NOTE_EDIT_WINDOW_HOURS)
            . ' ساعتهٔ ویرایش این یادداشت به پایان رسیده است. '
            . 'می‌توانید آن را باطل کنید و یادداشت تازه بنویسید.');
    }
    $text_value = $note['note_text'];
} catch (Exception $ex) {
    $fatal = $ex->getMessage();
}

if ($fatal === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $text_value = isset($_POST['note_text']) ? (string)$_POST['note_text'] : '';
    try {
        note_edit($db, (int)$case['id'], $note['public_id'], $text_value, $me);
        flash_set('note_success', 'یادداشت ویرایش شد.');
        redirect('note_view.php?case_id=' . rawurlencode($case['public_id'])
            . '&note_id=' . rawurlencode($note['public_id']));
    } catch (Exception $ex) {
        $field_error = $ex->getMessage();
    }
}

$page_title = 'ویرایش یادداشت';
$active_menu = 'therapist_home';
require __DIR__ . '/../templates/header.php';
?>
<h1>✏️ ویرایش یادداشت محرمانه</h1>

<?php if ($fatal !== null) { ?>
  <div class="alert alert-danger"><?php echo e($fatal); ?></div>
  <?php if ($case !== null) { ?>
    <a class="btn btn-secondary"
       href="case_detail.php?case_id=<?php echo e($case['public_id']); ?>">بازگشت به پرونده</a>
  <?php } else { ?>
    <a class="btn btn-secondary" href="index.php">بازگشت به کارتابل</a>
  <?php } ?>
<?php } else { ?>

<div class="card">
  <div class="card-header">
    مراجع: <?php echo e($case['first_name'] . ' ' . $case['last_name']); ?>
    — ثبت‌شده در <?php echo e(jalali_display($note['created_at'])); ?>
  </div>
  <div class="card-body">

    <?php if ($field_error !== null) { ?>
      <div class="alert alert-danger"><?php echo e($field_error); ?></div>
    <?php } ?>

    <div class="alert alert-info">
      ⏳ مهلت باقی‌ماندهٔ ویرایش: <strong><?php echo e(note_edit_window_left($note)); ?></strong><br>
      هر ویرایش در پرونده ثبت می‌شود و شمارندهٔ ویرایش افزایش می‌یابد
      (تاکنون <?php echo to_persian_digits((int)$note['edit_count']); ?> بار).
    </div>

    <form method="post" action="note_edit.php">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="case_id" value="<?php echo e($case['public_id']); ?>">
      <input type="hidden" name="note_id" value="<?php echo e($note['public_id']); ?>">

      <label for="note_text">متن یادداشت <span class="required-star">*</span></label>
      <textarea id="note_text" name="note_text" rows="14"
                class="note-textarea<?php echo $field_error !== null ? ' is-invalid' : ''; ?>"
                data-maxchars="<?php echo (int)NOTE_MAX_CHARS; ?>"
                data-counter="note_counter"
                required><?php echo e($text_value); ?></textarea>
      <div class="form-hint">
        نویسه‌های استفاده‌شده: <span id="note_counter" class="note-counter">۰</span>
        از <?php echo to_persian_digits(NOTE_MAX_CHARS); ?>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">💾 ذخیرهٔ تغییرات</button>
        <a class="btn btn-secondary"
           href="note_view.php?case_id=<?php echo e($case['public_id']); ?>&amp;note_id=<?php echo e($note['public_id']); ?>">
          انصراف
        </a>
      </div>
    </form>
  </div>
</div>

<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
