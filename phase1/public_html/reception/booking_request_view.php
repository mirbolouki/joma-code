<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — وصلهٔ ۴.۲.۰: جزئیات یک درخواست نوبت و تصمیم دربارهٔ آن
 *
 *  چهار کار ممکن است: «تماس گرفتم»، «تبدیل به پذیرش»، «رد»، «اسپم».
 *
 *  تبدیل، پذیرش نمی‌سازد. فقط قصد را در نشست می‌گذارد و منشی را به
 *  reception/admission_new.php می‌فرستد — همان فرمی که هر روز با آن
 *  کار می‌کند و همهٔ اعتبارسنجی‌هایش آزموده شده است.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_SECRETARY));

if (!phase4_2_ready($db)) {
    redirect(APP_BASE_URL . '/reception/index.php');
}

$public_id = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$actor_person_id = (int)$_SESSION['person_id'];
$actor_role = isset($_SESSION['active_role_code']) ? $_SESSION['active_role_code'] : ROLE_SECRETARY;

$error_message = '';
$row = null;

try {
    $row = booking_request_find($db, $public_id);
    if (!$row) {
        /* همان پیام و همان رفتارِ «پیدا نشد» و «مال شما نیست» */
        flash_set('booking_error', 'درخواست یافت نشد.');
        redirect(APP_BASE_URL . '/reception/booking_requests.php');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

        if ($action === 'convert') {
            if ($row['status'] === 'CONVERTED') {
                throw new Exception('این درخواست قبلاً به پذیرش تبدیل شده است.');
            }
            booking_convert_start($row);
            redirect(APP_BASE_URL . '/reception/admission_new.php?from=booking');
        } elseif ($action === 'contacted' || $action === 'reject' || $action === 'spam') {
            $map = array('contacted' => 'CONTACTED', 'reject' => 'REJECTED', 'spam' => 'SPAM');
            $reason_id = isset($_POST['reject_reason_id']) ? (int)$_POST['reject_reason_id'] : 0;

            booking_request_set_status($db, (int)$row['id'], $map[$action],
                $reason_id, $actor_person_id, $actor_role);

            flash_set('booking_success', 'وضعیت درخواست به «'
                . booking_status_label($map[$action]) . '» تغییر کرد.');
            redirect(APP_BASE_URL . '/reception/booking_request_view.php?id=' . urlencode($public_id));
        }
    }

    /* ── آیا این شماره از قبل در سامانه هست؟ ─────────────────────
       بررسی ابهام: اگر هست، منشی باید *پیش از* تبدیل بداند، وگرنه
       ممکن است برای یک مراجع قدیمی، شخص تکراری بسازد. ساخت شخص
       تکراری با UNIQUE بودن moblie جلویش گرفته می‌شود، ولی بهتر
       است منشی از اول بداند با چه کسی طرف است. */
    $existing_person = person_find_by_mobile($db, $row['mobile_number']);

    /* سابقهٔ همین شماره در خودِ صف درخواست‌ها */
    $same_mobile = db_select_all($db,
        "SELECT public_id, status, created_at FROM booking_requests
          WHERE mobile_number = ? AND id <> ? ORDER BY created_at DESC LIMIT 5",
        'si', array($row['mobile_number'], (int)$row['id']));

    $reject_reasons = lookup_items_fetch_active($db, BOOKING_REJECT_LIST);
} catch (Exception $ex) {
    $message = $ex->getMessage();
    if (strpos($message, 'DB_') === 0) {
        $ref = log_system_error('BOOKING_TRIAGE_VIEW', $ex);
        render_error_page($ref);
    }
    $error_message = $message;
    if (!isset($existing_person)) { $existing_person = null; }
    if (!isset($same_mobile)) { $same_mobile = array(); }
    if (!isset($reject_reasons)) { $reject_reasons = array(); }
}

$is_open = in_array($row['status'], array('NEW', 'CONTACTED'), true);

$page_title = 'درخواست نوبت';
$active_menu = 'booking_requests';
require __DIR__ . '/../templates/header.php';
?>
<h1>📨 درخواست نوبت</h1>

<p class="text-small">
  <a href="booking_requests.php" class="text-muted">← بازگشت به فهرست درخواست‌ها</a>
</p>

<?php
echo flash_render('booking_success', 'success');
echo flash_render('booking_error', 'error');
?>
<?php if ($error_message !== '') { ?>
  <div class="alert alert-error">✗ <?php echo e($error_message); ?></div>
<?php } ?>

<div class="card">
  <div class="card-header">
    اطلاعات متقاضی
    <span class="badge <?php echo e(booking_status_badge($row['status'])); ?>">
      <?php echo e(booking_status_label($row['status'])); ?>
    </span>
  </div>
  <div class="card-body">
    <div class="item-list">
      <div class="item-card">
        <span class="text-muted text-small">نام و نام خانوادگی</span>
        <div><?php echo e($row['first_name'] . ' ' . $row['last_name']); ?></div>
      </div>
      <div class="item-card">
        <span class="text-muted text-small">شمارهٔ موبایل (تأییدشده با پیامک)</span>
        <div class="mono" dir="ltr"><?php echo e(to_persian_digits($row['mobile_number'])); ?></div>
      </div>
      <div class="item-card">
        <span class="text-muted text-small">جنسیت</span>
        <div><?php
          echo $row['gender'] === 'MALE' ? 'مرد' : ($row['gender'] === 'FEMALE' ? 'زن' : '—');
        ?></div>
      </div>
      <div class="item-card">
        <span class="text-muted text-small">خدمت درخواستی</span>
        <div><?php echo e($row['requested_service_label']); ?></div>
      </div>
      <div class="item-card">
        <span class="text-muted text-small">زمان ترجیحی</span>
        <div><?php echo e($row['preferred_text']); ?></div>
      </div>
      <div class="item-card">
        <span class="text-muted text-small">تاریخ ثبت</span>
        <div><?php echo e(jalali_display($row['created_at'], 'datetime')); ?></div>
      </div>
      <div class="item-card">
        <span class="text-muted text-small">شناسهٔ پیگیری</span>
        <div class="mono" dir="ltr"><?php echo e($row['public_id']); ?></div>
      </div>
      <div class="item-card">
        <span class="text-muted text-small">اعتبار تا</span>
        <div><?php echo e(jalali_display($row['expires_at'], 'date')); ?></div>
      </div>
    </div>

    <?php if ($row['visitor_note'] !== null && $row['visitor_note'] !== '') { ?>
      <div class="form-row">
        <span class="text-muted text-small">توضیح متقاضی</span>
        <p><?php echo e($row['visitor_note']); ?></p>
      </div>
    <?php } ?>
  </div>
</div>

<?php if ($existing_person) { ?>
  <div class="alert alert-info">
    <strong>این شماره از قبل در سامانه ثبت شده است:</strong>
    <?php echo e($existing_person['first_name'] . ' ' . $existing_person['last_name']); ?>.<br>
    <span class="text-small">
      هنگام تبدیل، پذیرش برای همین شخص ثبت می‌شود و شخص تازه‌ای ساخته نمی‌شود.
      اگر نام متقاضی با نام بالا نمی‌خواند، پیش از تبدیل تلفنی بررسی کنید.
    </span>
  </div>
<?php } ?>

<?php if (count($same_mobile) > 0) { ?>
  <div class="alert alert-warning">
    <strong>از این شماره <?php echo e(to_persian_digits(count($same_mobile))); ?>
    درخواست دیگر هم ثبت شده است:</strong>
    <ul class="hint-list">
      <?php foreach ($same_mobile as $m) { ?>
        <li>
          <a href="booking_request_view.php?id=<?php echo e($m['public_id']); ?>">
            <?php echo e(jalali_display($m['created_at'], 'datetime')); ?></a>
          — <?php echo e(booking_status_label($m['status'])); ?>
        </li>
      <?php } ?>
    </ul>
  </div>
<?php } ?>

<?php if ($row['status'] === 'CONVERTED') { ?>
  <div class="alert alert-success">
    این درخواست در <?php echo e(jalali_display($row['handled_at'], 'datetime')); ?>
    به پذیرش تبدیل شده است.
  </div>
<?php } elseif ($is_open) { ?>

<div class="card">
  <div class="card-header">تصمیم</div>
  <div class="card-body">

    <div class="action-panel">
      <form method="post" class="inline-form"
            action="booking_request_view.php?id=<?php echo e($public_id); ?>">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="convert">
        <button type="submit" class="btn btn-primary">✅ تبدیل به پذیرش</button>
      </form>

      <?php if ($row['status'] === 'NEW') { ?>
        <form method="post" class="inline-form"
              action="booking_request_view.php?id=<?php echo e($public_id); ?>">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="contacted">
          <button type="submit" class="btn btn-secondary">📞 تماس گرفتم</button>
        </form>
      <?php } ?>

      <form method="post" class="inline-form"
            action="booking_request_view.php?id=<?php echo e($public_id); ?>"
            onsubmit="return confirm('این درخواست به‌عنوان اسپم علامت بخورد؟');">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="spam">
        <button type="submit" class="btn btn-danger btn-sm">🚫 اسپم</button>
      </form>
    </div>

    <p class="form-hint">
      «تبدیل به پذیرش» شما را به فرم ثبت پذیرش می‌برد؛ اطلاعات متقاضی از پیش
      پر شده است. پذیرش فقط وقتی ساخته می‌شود که آن فرم را تأیید کنید.
    </p>

    <hr>

    <form method="post" action="booking_request_view.php?id=<?php echo e($public_id); ?>">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="reject">
      <div class="form-row">
        <label for="reject_reason_id">دلیل رد درخواست</label>
        <select id="reject_reason_id" name="reject_reason_id" required>
          <option value="">انتخاب کنید…</option>
          <?php foreach ($reject_reasons as $rr) { ?>
            <option value="<?php echo (int)$rr['id']; ?>"><?php echo e($rr['label']); ?></option>
          <?php } ?>
        </select>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-danger">رد درخواست</button>
      </div>
    </form>

  </div>
</div>

<?php } else { ?>
  <div class="alert alert-info">
    این درخواست بسته شده است و تصمیم تازه‌ای برایش ثبت نمی‌شود.
  </div>
<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
