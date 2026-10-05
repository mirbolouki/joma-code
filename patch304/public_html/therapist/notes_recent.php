<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۳: میان‌بر «آخرین یادداشت‌های من»
 *
 *  فهرست یادداشت‌های خودِ درمانگر در همهٔ پرونده‌هایش، تازه‌ترین بالا.
 *  هیچ مسیر دسترسی تازه‌ای باز نمی‌کند: همان دو فیلترِ همیشگی
 *  (نویسنده = بیننده، و پرونده هنوز مال بیننده) در خودِ پرس‌وجو اعمال شده و
 *  متن کامل فقط از راه note_view.php دیده می‌شود.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_THERAPIST));

$me = (int)$_SESSION['person_id'];

$notes = array();
$fatal = null;

try {
    if (!phase3_ready($db)) {
        throw new Exception('بخش پروندهٔ بالینی هنوز روی این سامانه نصب نشده است.');
    }
    $notes = notes_recent_for_therapist($db, $me, 30);
} catch (Exception $ex) {
    $fatal = $ex->getMessage();
    log_system_error('notes_recent', $ex);
}

$page_title = 'آخرین یادداشت‌های من';
$active_menu = 'notes_recent';
require __DIR__ . '/../templates/header.php';
?>
<h1>🗒️ آخرین یادداشت‌های من</h1>

<?php if ($fatal !== null) { ?>
  <div class="alert alert-danger"><?php echo e($fatal); ?></div>
  <a class="btn btn-secondary" href="index.php">بازگشت به کارتابل</a>
<?php } else { ?>

<div class="action-panel">
  <a href="index.php" class="btn btn-secondary">↩️ بازگشت به کارتابل</a>
</div>

<div class="card">
  <div class="card-header">
    ۳۰ یادداشت اخیر — تازه‌ترین بالا
  </div>
  <div class="card-body">

    <div class="alert alert-info">
      این فهرست فقط یادداشت‌های <strong>خودِ شما</strong> را نشان می‌دهد. اگر پرونده‌ای
      به درمانگر دیگری ارجاع شده باشد، یادداشت‌های آن از این فهرست بیرون می‌رود،
      چون دسترسی شما به آن پرونده پایان یافته است.
    </div>

    <?php if (count($notes) === 0) { ?>
      <div class="table-empty">
        هنوز یادداشتی ننوشته‌اید. از کارتابل، پرونده‌ای را باز کنید و «افزودن یادداشت تازه» را بزنید.
      </div>
    <?php } else { ?>
      <div class="item-list">
      <?php foreach ($notes as $n) { ?>
        <?php $is_retracted = ($n['status'] === 'RETRACTED'); ?>
        <div class="item-card<?php echo $is_retracted ? ' note-retracted' : ''; ?>">
          <div class="item-card-main">
            <div class="item-card-title">
              <?php echo e($n['first_name'] . ' ' . $n['last_name']); ?>
              <span class="text-muted">— <?php echo e(jalali_display($n['created_at'])); ?></span>
              <?php if ($is_retracted) { ?>
                <span class="badge badge-muted">باطل‌شده</span>
              <?php } elseif ((int)$n['edit_count'] > 0) { ?>
                <span class="badge badge-muted">ویرایش‌شده</span>
              <?php } ?>
            </div>

            <?php if (!$is_retracted) { ?>
              <div class="note-preview"><?php echo e($n['preview_text']); ?><?php
                echo ((int)$n['character_count'] > 160) ? '…' : ''; ?></div>
            <?php } ?>

            <div class="item-card-meta">
              <?php if ($is_retracted) { ?>
                <span>تاریخ ابطال: <?php echo e(jalali_display($n['retracted_at'])); ?></span>
              <?php } else { ?>
                <span><?php echo to_persian_digits((int)$n['character_count']); ?> نویسه</span>
                <span>مشاهده: <?php echo to_persian_digits((int)$n['view_count']); ?> بار</span>
                <?php if (note_is_editable($n)) { ?>
                  <span class="text-success">مهلت ویرایش: <?php echo e(note_edit_window_left($n)); ?></span>
                <?php } else { ?>
                  <span class="text-muted">مهلت ویرایش گذشته</span>
                <?php } ?>
              <?php } ?>
            </div>
          </div>

          <a class="btn <?php echo $is_retracted ? 'btn-secondary' : 'btn-primary'; ?>"
             href="note_view.php?case_id=<?php echo e($n['case_public_id']); ?>&amp;note_id=<?php echo e($n['public_id']); ?>">
            مشاهده
          </a>
        </div>
      <?php } ?>
      </div>
    <?php } ?>

  </div>
</div>

<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
