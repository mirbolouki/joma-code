<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۳: مشاهدهٔ یادداشت محرمانه + ابطال با دلیل
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
$action_error = null;
$reasons = array();

try {
    if (!phase3_ready($db)) {
        throw new Exception('بخش پروندهٔ بالینی هنوز روی این سامانه نصب نشده است.');
    }
    $case = clinical_case_find_for_therapist($db, $case_public_id, $me);
    $reasons = lookup_items_fetch_active($db, NOTE_RETRACTION_LIST);
} catch (Exception $ex) {
    $fatal = $ex->getMessage();
}

/* ابطال */
if ($fatal === null && $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action']) && $_POST['action'] === 'retract') {
    csrf_check();
    try {
        $reason_id = isset($_POST['reason_id']) ? (int)$_POST['reason_id'] : 0;
        if ($reason_id <= 0) {
            throw new Exception('دلیل ابطال را از فهرست انتخاب کنید.');
        }
        note_retract($db, (int)$case['id'], $note_public_id, $reason_id, $me);
        flash_set('note_success', 'یادداشت باطل شد. متن آن حذف نشده و در پرونده باقی است.');
        redirect('case_detail.php?case_id=' . rawurlencode($case['public_id']));
    } catch (Exception $ex) {
        $action_error = $ex->getMessage();
    }
}

if ($fatal === null) {
    try {
        $note = note_fetch($db, (int)$case['id'], $note_public_id, $me, true);
    } catch (Exception $ex) {
        $fatal = $ex->getMessage();
    }
}

$retraction_label = null;
if ($note !== null && $note['retraction_reason_id'] !== null) {
    $r = lookup_item_find($db, NOTE_RETRACTION_LIST, (int)$note['retraction_reason_id']);
    $retraction_label = $r ? $r['label'] : null;
}

$page_title = 'مشاهدهٔ یادداشت';
$active_menu = 'therapist_home';
require __DIR__ . '/../templates/header.php';
?>
<h1>🔒 یادداشت محرمانه</h1>

<?php if ($fatal !== null) { ?>
  <div class="alert alert-danger"><?php echo e($fatal); ?></div>
  <a class="btn btn-secondary" href="index.php">بازگشت به کارتابل</a>
<?php } else { ?>

<?php if ($action_error !== null) { ?>
  <div class="alert alert-danger"><?php echo e($action_error); ?></div>
<?php } ?>

<div class="action-panel">
  <a class="btn btn-secondary"
     href="case_detail.php?case_id=<?php echo e($case['public_id']); ?>">↩️ بازگشت به پرونده</a>
  <?php if (note_is_editable($note)) { ?>
    <a class="btn btn-primary"
       href="note_edit.php?case_id=<?php echo e($case['public_id']); ?>&amp;note_id=<?php echo e($note['public_id']); ?>">
      ✏️ ویرایش
    </a>
  <?php } ?>
</div>

<div class="card">
  <div class="card-header">
    مراجع: <?php echo e($case['first_name'] . ' ' . $case['last_name']); ?>
    <span class="badge <?php echo e(note_status_class($note['status'])); ?>">
      <?php echo e(note_status_label($note['status'])); ?></span>
  </div>
  <div class="card-body">
    <div class="form-grid">
      <div><span class="form-hint">تاریخ ثبت</span><br>
        <?php echo e(jalali_display($note['created_at'])); ?></div>
      <div><span class="form-hint">آخرین ویرایش</span><br>
        <?php echo $note['edited_at'] === null ? '—' : e(jalali_display($note['edited_at'])); ?></div>
      <div><span class="form-hint">تعداد ویرایش</span><br>
        <?php echo to_persian_digits((int)$note['edit_count']); ?></div>
      <div><span class="form-hint">تعداد مشاهده</span><br>
        <?php echo to_persian_digits((int)$note['view_count']); ?> بار</div>
      <div><span class="form-hint">طول متن</span><br>
        <?php echo to_persian_digits((int)$note['character_count']); ?> نویسه</div>
      <div><span class="form-hint">شناسهٔ یادداشت</span><br>
        <span class="mono"><?php echo e($note['public_id']); ?></span></div>
    </div>

    <?php if ($note['status'] === 'RETRACTED') { ?>
      <div class="alert alert-warning">
        این یادداشت در تاریخ <?php echo e(jalali_display($note['retracted_at'])); ?> باطل شده است.
        <?php if ($retraction_label !== null) { ?>
          دلیل: <strong><?php echo e($retraction_label); ?></strong>
        <?php } ?>
      </div>
    <?php } elseif (!note_is_editable($note)) { ?>
      <div class="alert alert-info">
        مهلت <?php echo to_persian_digits(NOTE_EDIT_WINDOW_HOURS); ?> ساعتهٔ ویرایش این یادداشت
        به پایان رسیده است. برای اصلاح، آن را باطل کنید و یادداشت تازه بنویسید.
      </div>
    <?php } ?>

    <h3>متن یادداشت</h3>
    <div class="note-body<?php echo $note['status'] === 'RETRACTED' ? ' note-body-retracted' : ''; ?>"><?php
      echo nl2br(e($note['note_text'])); ?></div>
  </div>
</div>

<?php if ($note['status'] === 'ACTIVE') { ?>
<div class="card">
  <div class="card-header">🚫 ابطال یادداشت</div>
  <div class="card-body">
    <p class="form-hint">
      ابطال، یادداشت را حذف نمی‌کند. متن در پرونده باقی می‌ماند و با نشان «باطل‌شده» دیده می‌شود.
      این اقدام بازگشت‌پذیر نیست.
    </p>
    <?php if (count($reasons) === 0) { ?>
      <div class="alert alert-warning">
        فهرست «دلیل ابطال یادداشت» خالی است. از مدیر بخواهید آن را تکمیل کند.
      </div>
    <?php } else { ?>
    <form method="post" action="note_view.php"
          onsubmit="return confirm('آیا از ابطال این یادداشت مطمئن هستید؟ این اقدام بازگشت‌پذیر نیست.');">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="retract">
      <input type="hidden" name="case_id" value="<?php echo e($case['public_id']); ?>">
      <input type="hidden" name="note_id" value="<?php echo e($note['public_id']); ?>">

      <label for="reason_id">دلیل ابطال <span class="required-star">*</span></label>
      <select id="reason_id" name="reason_id" required>
        <option value="">— انتخاب کنید —</option>
        <?php foreach ($reasons as $r) { ?>
          <option value="<?php echo (int)$r['id']; ?>"><?php echo e($r['label']); ?></option>
        <?php } ?>
      </select>

      <div class="form-actions">
        <button type="submit" class="btn btn-danger">🚫 ابطال یادداشت</button>
      </div>
    </form>
    <?php } ?>
  </div>
</div>
<?php } ?>

<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
