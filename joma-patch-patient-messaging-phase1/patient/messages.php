<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — صندوق پیام‌های امن مراجع
 *  هر پرس‌وجو به شناسهٔ شخصِ نشست پورتال محدود است.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
$me = patient_require_portal_session($db);

if (!patient_messages_ready($db)) {
    $page_title = 'پیام‌های من';
    $active_menu = 'patient_messages';
    require __DIR__ . '/../templates/header.php';
    ?>
    <h1>✉️ پیام‌های من</h1>
    <div class="alert alert-info">بخش پیام‌های امن هنوز توسط مدیر سامانه فعال نشده است.</div>
    <a class="btn btn-secondary" href="index.php">بازگشت به صفحهٔ من</a>
    <?php
    require __DIR__ . '/../templates/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
        $public_id = isset($_POST['message_public_id'])
            ? trim((string)$_POST['message_public_id']) : '';
        if ($action !== 'mark_read' || $public_id === '') {
            throw new Exception('درخواست معتبر نیست.');
        }
        patient_message_mark_read($db, (int)$me, $public_id);
        flash_set('patient_message_success', 'پیام به‌عنوان خوانده‌شده ثبت شد.');
        redirect(APP_BASE_URL . '/patient/messages.php');
    } catch (Exception $ex) {
        $form_error = $ex->getMessage();
    }
}

try {
    $messages = patient_messages_fetch_for_person($db, (int)$me, 100);
    $unread_count = patient_messages_unread_count($db, (int)$me);
} catch (Exception $ex) {
    $ref = log_system_error('PATIENT_MESSAGES_FETCH', $ex);
    render_error_page($ref);
}

$page_title = 'پیام‌های من';
$active_menu = 'patient_messages';
require __DIR__ . '/../templates/header.php';
?>
<h1>✉️ پیام‌های من</h1>
<p class="form-hint">متن کامل پیام‌ها فقط پس از ورود به پورتال نمایش داده می‌شود.</p>
<?php echo flash_render('patient_message_success', 'success'); ?>
<?php if (!empty($form_error)) { ?>
  <div class="alert alert-danger"><?php echo e($form_error); ?></div>
<?php } ?>

<div class="card">
  <div class="card-header">
    پیام‌های دریافتی
    <span class="badge <?php echo $unread_count > 0 ? 'badge-warning' : 'badge-muted'; ?>">
      خوانده‌نشده: <?php echo e(to_persian_digits($unread_count)); ?>
    </span>
  </div>
  <div class="card-body">
    <?php if (count($messages) === 0) { ?>
      <p class="empty-state">هنوز پیامی برای شما ثبت نشده است.</p>
    <?php } else { ?>
      <div class="item-list">
        <?php foreach ($messages as $message) { ?>
          <article class="item-card">
            <div class="item-card-main">
              <div class="item-card-title">
                <?php echo e($message['subject']); ?>
                <?php if ($message['read_at'] === null) { ?>
                  <span class="badge badge-warning">جدید</span>
                <?php } else { ?>
                  <span class="badge badge-muted">خوانده‌شده</span>
                <?php } ?>
              </div>
              <div class="item-card-meta">
                <?php echo e(patient_message_type_label($message['message_type'])); ?>
                — <?php echo e(jalali_display($message['created_at'])); ?>
              </div>
              <div class="message-body" style="white-space:pre-wrap; margin-top:12px;">
                <?php echo e($message['body']); ?>
              </div>
            </div>
            <?php if ($message['read_at'] === null) { ?>
              <form method="post" action="messages.php" class="item-card-actions">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="mark_read">
                <input type="hidden" name="message_public_id" value="<?php echo e($message['public_id']); ?>">
                <button type="submit" class="btn btn-sm btn-secondary">خواندم</button>
              </form>
            <?php } ?>
          </article>
        <?php } ?>
      </div>
      <?php if (count($messages) >= 100) { ?>
        <p class="form-hint">در این صفحه ۱۰۰ پیام تازه‌تر نمایش داده می‌شود.</p>
      <?php } ?>
    <?php } ?>
  </div>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
