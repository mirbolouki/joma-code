<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۳: ثبت یادداشت محرمانهٔ تازه
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_THERAPIST));

$me = (int)$_SESSION['person_id'];
$case_public_id = isset($_GET['case_id']) ? trim($_GET['case_id']) : '';
if ($case_public_id === '' && isset($_POST['case_id'])) {
    $case_public_id = trim($_POST['case_id']);
}

$case = null;
$fatal = null;
$field_error = null;
$text_value = '';

try {
    if (!phase3_ready($db)) {
        throw new Exception('بخش پروندهٔ بالینی هنوز روی این سامانه نصب نشده است.');
    }
    $case = clinical_case_find_for_therapist($db, $case_public_id, $me);
    if ($case['status'] !== 'ACTIVE') {
        throw new Exception('این پرونده فعال نیست و یادداشت تازه نمی‌پذیرد.');
    }
} catch (Exception $ex) {
    $fatal = $ex->getMessage();
}

if ($fatal === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $text_value = isset($_POST['note_text']) ? (string)$_POST['note_text'] : '';
    try {
        note_create($db, (int)$case['id'], $text_value, $me);
        flash_set('note_success', 'یادداشت با موفقیت ثبت شد.');
        redirect('case_detail.php?case_id=' . rawurlencode($case['public_id']));
    } catch (Exception $ex) {
        $field_error = $ex->getMessage();
    }
}

$page_title = 'یادداشت تازه';
$active_menu = 'therapist_home';
require __DIR__ . '/../templates/header.php';
?>
<h1>📝 یادداشت محرمانهٔ تازه</h1>

<?php if ($fatal !== null) { ?>
  <div class="alert alert-danger"><?php echo e($fatal); ?></div>
  <a class="btn btn-secondary" href="index.php">بازگشت به کارتابل</a>
<?php } else { ?>

<div class="card">
  <div class="card-header">
    مراجع: <?php echo e($case['first_name'] . ' ' . $case['last_name']); ?>
    — پرونده <span class="mono"><?php echo e($case['public_id']); ?></span>
  </div>
  <div class="card-body">

    <?php if ($field_error !== null) { ?>
      <div class="alert alert-danger"><?php echo e($field_error); ?></div>
    <?php } ?>

    <div class="alert alert-info">
      🔒 این یادداشت فقط برای شما قابل مشاهده خواهد بود.
      پس از ثبت، تا <?php echo to_persian_digits(NOTE_EDIT_WINDOW_HOURS); ?> ساعت
      امکان ویرایش دارید؛ پس از آن فقط می‌توانید آن را باطل کنید.
    </div>

    <form method="post" action="note_create.php">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="case_id" value="<?php echo e($case['public_id']); ?>">

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
        <button type="submit" class="btn btn-primary">💾 ذخیرهٔ یادداشت</button>
        <a class="btn btn-secondary"
           href="case_detail.php?case_id=<?php echo e($case['public_id']); ?>">انصراف</a>
      </div>
    </form>
  </div>
</div>

<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
