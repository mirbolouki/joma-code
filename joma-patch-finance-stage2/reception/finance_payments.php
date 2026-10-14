<?php
/* ═══════════════════════════════════════════════════════════════════
 *  جوما فاز ۵ — پرداختِ جلسات (مدیر و منشی)
 *
 *  هر جلسه یک رکوردِ پرداخت دارد با دو بخش:
 *    - بیعانه (پیش‌پرداخت): الزامی است — ثبتِ رزرو منوط به واریزِ بیعانه است
 *    - الباقی: وجهِ باقی‌مانده، هنگام/پس از جلسه
 *  همهٔ انتخاب‌ها (نوبت، حسابِ بانکی، وضعیت) از فهرست‌های کشویی هستند.
 *  مبالغ به تومان وارد می‌شوند و به ریال ذخیره می‌شوند.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_SECRETARY, ROLE_ADMIN));

if (!phase5_2_ready($db)) {
    redirect(APP_BASE_URL . '/upgrade_phase5_2.php');
}

$actor_person_id = (int)$_SESSION['person_id'];
$actor_role_code = $_SESSION['active_role_code'];

$errors = array();
$general_error = '';

/* ── حالتِ فرم ── */
$edit_payment = null;       /* رکوردِ پرداختِ در حالِ ویرایش */
$form_appt = null;          /* نوبتِ انتخاب‌شده */
$selected_appt_public = ''; /* نوبتِ انتخاب‌شده در دراپ‌داون */
$form = array(
    'deposit_amount' => '',
    'deposit_bank_account_id' => '',
    'deposit_status' => 'PAID',
    'balance_amount' => '',
    'balance_bank_account_id' => '',
    'balance_status' => 'PENDING',
    'note' => '',
);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    try {
        $edit_payment = finance_payment_find($db, $_GET['edit']);
    } catch (Exception $ex) {
        $edit_payment = null;
    }
    if ($edit_payment) {
        $form_appt = finance_payment_appointment($db, $edit_payment['appointment_id']);
        $form['deposit_amount'] = $edit_payment['deposit_amount_rial'] !== null
            ? tariff_rial_to_toman_value($edit_payment['deposit_amount_rial']) : '';
        $form['deposit_bank_account_id'] = $edit_payment['deposit_bank_account_id'] !== null
            ? (string)(int)$edit_payment['deposit_bank_account_id'] : '';
        $form['deposit_status'] = $edit_payment['deposit_status'];
        $form['balance_amount'] = $edit_payment['balance_amount_rial'] !== null
            ? tariff_rial_to_toman_value($edit_payment['balance_amount_rial']) : '';
        $form['balance_bank_account_id'] = $edit_payment['balance_bank_account_id'] !== null
            ? (string)(int)$edit_payment['balance_bank_account_id'] : '';
        $form['balance_status'] = $edit_payment['balance_status'];
        $form['note'] = $edit_payment['note'] !== null ? $edit_payment['note'] : '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['appt']) && !$edit_payment) {
    try {
        $form_appt = appointment_find_by_public_id($db, $_GET['appt']);
    } catch (Exception $ex) {
        $form_appt = null;
    }
    if ($form_appt) {
        $existing = finance_payment_find_by_appointment($db, (int)$form_appt['id']);
        if ($existing) {
            redirect(APP_BASE_URL . '/reception/finance_payments.php?edit=' . $existing['public_id']);
        }
        $selected_appt_public = $form_appt['public_id'];
        /* پیش‌فرض: الباقی = کلِ مبلغِ جلسه */
        $form['balance_amount'] = tariff_rial_to_toman_value($form_appt['price_rial']);
    }
}

/* ── پردازش POST ────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    try {
        if ($action === 'create' || $action === 'update') {
            $form['deposit_amount'] = isset($_POST['deposit_amount']) ? $_POST['deposit_amount'] : '';
            $form['deposit_bank_account_id'] = isset($_POST['deposit_bank_account_id']) ? $_POST['deposit_bank_account_id'] : '';
            $form['deposit_status'] = isset($_POST['deposit_status']) ? $_POST['deposit_status'] : 'PAID';
            $form['balance_amount'] = isset($_POST['balance_amount']) ? $_POST['balance_amount'] : '';
            $form['balance_bank_account_id'] = isset($_POST['balance_bank_account_id']) ? $_POST['balance_bank_account_id'] : '';
            $form['balance_status'] = isset($_POST['balance_status']) ? $_POST['balance_status'] : 'PENDING';
            $form['note'] = isset($_POST['note']) ? $_POST['note'] : '';

            if ($action === 'create') {
                $appt_public = isset($_POST['appointment_public_id']) ? $_POST['appointment_public_id'] : '';
                if ($appt_public === '') {
                    $errors['appointment_public_id'] = 'نوبت را از فهرستِ کشویی انتخاب کنید.';
                }
                $appt = $appt_public !== '' ? appointment_find_by_public_id($db, $appt_public) : null;
                if ($appt_public !== '' && !$appt) {
                    throw new Exception('نوبتِ موردنظر پیدا نشد.');
                }
                if ($appt) {
                    $existing = finance_payment_find_by_appointment($db, (int)$appt['id']);
                    if ($existing) {
                        $general_error = 'برای این نوبت قبلاً پرداخت ثبت شده است؛ '
                            . 'از فهرست نوبت‌ها (پایین صفحه) روی «✏️ ویرایش پرداخت» کلیک کنید.';
                    } else {
                        $check = finance_payment_validate($_POST);
                        if (!$check['ok']) {
                            $errors = array_merge($errors, $check['errors']);
                        } else {
                            $result = finance_payment_create($db, (int)$appt['id'], $appt['price_rial'],
                                $check, $actor_person_id, $actor_role_code);
                            if (!$result['ok']) {
                                throw new Exception($result['error']);
                            }
                            flash_set('payments_ok', 'پرداختِ جلسه ثبت شد.');
                            redirect(APP_BASE_URL . '/reception/finance_payments.php');
                        }
                    }
                    $form_appt = $appt;
                    $selected_appt_public = $appt['public_id'];
                }
            } else {
                $target = finance_payment_find($db, isset($_POST['payment_public_id']) ? $_POST['payment_public_id'] : '');
                if (!$target) {
                    throw new Exception('پرداختِ موردنظر پیدا نشد.');
                }
                $check = finance_payment_validate($_POST);
                if (!$check['ok']) {
                    $errors = $check['errors'];
                    $edit_payment = $target;
                    $form_appt = finance_payment_appointment($db, $target['appointment_id']);
                } else {
                    $result = finance_payment_update($db, (int)$target['id'], $check,
                        $actor_person_id, $actor_role_code);
                    if (!$result['ok']) {
                        throw new Exception($result['error']);
                    }
                    flash_set('payments_ok', 'پرداختِ جلسه به‌روزرسانی شد.');
                    redirect(APP_BASE_URL . '/reception/finance_payments.php');
                }
            }
        }
    } catch (Exception $ex) {
        $general_error = $ex->getMessage();
    }
}

$only_unpaid = isset($_GET['only_unpaid']);

try {
    $appointments = finance_appointments_for_payment($db, 100, $only_unpaid);
    $accounts = finance_accounts_fetch_active($db);
} catch (Exception $ex) {
    $ref = log_system_error('FINANCE_PAYMENTS_LIST', $ex);
    render_error_page($ref);
}

$page_title = 'پرداختِ جلسات';
$active_menu = 'finance_payments';
require __DIR__ . '/../templates/header.php';
?>
<h1>💳 پرداختِ جلسات</h1>
<?php echo flash_render('payments_ok', 'success'); ?>
<?php if ($general_error !== '') { ?>
  <div class="alert alert-danger"><?php echo e($general_error); ?></div>
<?php } ?>

<div class="alert alert-info">
  <strong>بیعانه الزامی است:</strong> در مدلِ نوبت‌دهیِ شما ثبتِ رزرو منوط به واریزِ بیعانه است؛
  پس بخشِ بیعانه را حتماً «پرداخت‌شده» ثبت کنید (مبلغ + حسابِ بانکیِ واریزشده).
  <strong>الباقی</strong> (مبلغِ کاملِ جلسه منهای بیعانه) را می‌توانید «در انتظار پرداخت» بگذارید
  و پس از جلسه «پرداخت‌شده» کنید.
  مبالغ را به <strong>تومان</strong> وارد کنید.
  همهٔ انتخاب‌ها — نوبت، حسابِ بانکی، وضعیت — از <strong>فهرست‌های کشویی</strong> هستند.
</div>

<?php if ($edit_payment) { ?>
<div class="card">
  <div class="card-header">ویرایش پرداختِ جلسه</div>
  <div class="card-body">
    <?php if ($form_appt) { ?>
    <div class="alert alert-info">
      <strong>مراجع:</strong> <?php echo e($form_appt['patient_first_name'] . ' ' . $form_appt['patient_last_name']); ?>
      &nbsp;|&nbsp; <strong>درمانگر:</strong> <?php echo e($form_appt['therapist_first_name'] . ' ' . $form_appt['therapist_last_name']); ?>
      &nbsp;|&nbsp; <strong>خدمت:</strong> <?php echo e($form_appt['service_title']); ?>
      &nbsp;|&nbsp; <strong>اتاق:</strong> <?php echo e($form_appt['room_name']); ?>
      <br>
      <strong>زمان:</strong> <?php echo jalali_display($form_appt['appointment_start_utc'], 'datetime'); ?>
      &nbsp;|&nbsp; <strong>مبلغِ جلسه:</strong> <?php echo tariff_rial_to_toman_display($form_appt['price_rial']); ?>
      &nbsp;|&nbsp; <strong>وضعیتِ نوبت:</strong> <?php echo appointment_status_label($form_appt['status']); ?>
    </div>
    <?php } ?>

    <form method="post" action="finance_payments.php" class="form-grid">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="payment_public_id" value="<?php echo e($edit_payment['public_id']); ?>">
      <?php finance_render_payment_sections($form, $errors, $accounts, '💾 ذخیرهٔ تغییرات'); ?>
    </form>
  </div>
</div>
<?php } else { ?>
<div class="card">
  <div class="card-header">ثبت پرداختِ جلسهٔ جدید</div>
  <div class="card-body">
    <form method="post" action="finance_payments.php" class="form-grid">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="create">

      <div class="form-row" style="grid-column:1/-1;">
        <label for="appointment_public_id">انتخاب نوبت <span class="required-star">*</span></label>
        <select id="appointment_public_id" name="appointment_public_id"
                class="<?php echo isset($errors['appointment_public_id']) ? 'is-invalid' : ''; ?>">
          <option value="">— انتخاب نوبت —</option>
          <?php foreach ($appointments as $a) {
              if ($a['payment_public_id']) { continue; } ?>
            <option value="<?php echo e($a['public_id']); ?>"
              <?php echo ($selected_appt_public === $a['public_id']) ? 'selected' : ''; ?>>
              <?php echo e($a['patient_first_name'] . ' ' . $a['patient_last_name']); ?>
              — <?php echo jalali_display($a['appointment_start_utc'], 'date'); ?>
              — <?php echo e($a['service_title']); ?>
              — <?php echo tariff_rial_to_toman_display($a['price_rial']); ?>
            </option>
          <?php } ?>
        </select>
        <?php if (isset($errors['appointment_public_id'])) { ?><span class="field-error"><?php echo e($errors['appointment_public_id']); ?></span><?php } ?>
      </div>

      <?php if ($form_appt) { ?>
      <div class="alert alert-info" style="grid-column:1/-1;">
        <strong>مراجع:</strong> <?php echo e($form_appt['patient_first_name'] . ' ' . $form_appt['patient_last_name']); ?>
        &nbsp;|&nbsp; <strong>درمانگر:</strong> <?php echo e($form_appt['therapist_first_name'] . ' ' . $form_appt['therapist_last_name']); ?>
        &nbsp;|&nbsp; <strong>خدمت:</strong> <?php echo e($form_appt['service_title']); ?>
        &nbsp;|&nbsp; <strong>اتاق:</strong> <?php echo e($form_appt['room_name']); ?>
        <br>
        <strong>زمان:</strong> <?php echo jalali_display($form_appt['appointment_start_utc'], 'datetime'); ?>
        &nbsp;|&nbsp; <strong>مبلغِ جلسه:</strong> <?php echo tariff_rial_to_toman_display($form_appt['price_rial']); ?>
      </div>
      <?php } ?>

      <?php finance_render_payment_sections($form, $errors, $accounts, '➕ ثبت پرداخت'); ?>
    </form>
  </div>
</div>
<?php } ?>

<div class="card">
  <div class="card-header">
    فهرست نوبت‌ها
    (<?php echo to_persian_digits(count($appointments)); ?>)
  </div>
  <div class="card-body">
    <form method="get" action="finance_payments.php" class="inline-form" style="margin-bottom:12px;">
      <label>
        <input type="checkbox" name="only_unpaid" value="1" <?php echo $only_unpaid ? 'checked' : ''; ?>>
        فقط نوبت‌های پرداخت‌نشده
      </label>
      <button type="submit" class="btn btn-sm btn-secondary">اعمال فیلتر</button>
      <?php if ($only_unpaid) { ?>
        <a href="finance_payments.php" class="btn btn-sm btn-secondary">نمایشِ همه</a>
      <?php } ?>
    </form>

    <?php if (count($appointments) === 0) { ?>
      <p class="empty-state">نوبتی برای نمایش نیست.</p>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead>
            <tr>
              <th>زمانِ نوبت</th><th>مراجع</th><th>درمانگر</th><th>خدمت</th>
              <th>اتاق</th><th>مبلغِ جلسه</th><th>بیعانه</th><th>الباقی</th>
              <th>وضعیتِ کل</th><th>عملیات</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($appointments as $a) {
              $overall = $a['payment_public_id']
                  ? finance_payment_overall(array(
                      'session_fee_rial' => $a['session_fee_rial'],
                      'deposit_status' => $a['deposit_status'],
                      'deposit_amount_rial' => $a['deposit_amount_rial'],
                      'balance_status' => $a['balance_status'],
                      'balance_amount_rial' => $a['balance_amount_rial'],
                  ))
                  : array('status' => 'PENDING', 'paid_rial' => 0,
                         'label' => 'در انتظار پرداخت', 'class' => 'badge-muted');
          ?>
            <tr>
              <td data-label="زمانِ نوبت"><?php echo jalali_display($a['appointment_start_utc'], 'datetime'); ?></td>
              <td data-label="مراجع"><?php echo e($a['patient_first_name'] . ' ' . $a['patient_last_name']); ?></td>
              <td data-label="درمانگر"><?php echo e($a['therapist_first_name'] . ' ' . $a['therapist_last_name']); ?></td>
              <td data-label="خدمت"><?php echo e($a['service_title']); ?></td>
              <td data-label="اتاق"><?php echo e($a['room_name']); ?></td>
              <td data-label="مبلغِ جلسه"><?php echo tariff_rial_to_toman_display($a['price_rial']); ?></td>
              <td data-label="بیعانه">
                <?php if ($a['payment_public_id']) { ?>
                  <span class="badge <?php echo finance_part_status_class($a['deposit_status']); ?>">
                    <?php echo finance_part_status_label($a['deposit_status']); ?>
                  </span>
                  <?php if ($a['deposit_status'] === 'PAID') { ?>
                    <br><small><?php echo tariff_rial_to_toman_display($a['deposit_amount_rial']); ?></small>
                  <?php } ?>
                <?php } else { ?>
                  <span class="badge badge-muted">ثبت‌نشده</span>
                <?php } ?>
              </td>
              <td data-label="الباقی">
                <?php if ($a['payment_public_id']) { ?>
                  <span class="badge <?php echo finance_part_status_class($a['balance_status']); ?>">
                    <?php echo finance_part_status_label($a['balance_status']); ?>
                  </span>
                  <?php if ($a['balance_status'] === 'PAID') { ?>
                    <br><small><?php echo tariff_rial_to_toman_display($a['balance_amount_rial']); ?></small>
                  <?php } ?>
                <?php } else { ?>
                  <span class="badge badge-muted">ثبت‌نشده</span>
                <?php } ?>
              </td>
              <td data-label="وضعیتِ کل">
                <span class="badge <?php echo $overall['class']; ?>"><?php echo $overall['label']; ?></span>
              </td>
              <td data-label="عملیات">
                <?php if ($a['payment_public_id']) { ?>
                  <a class="btn btn-sm btn-secondary"
                     href="finance_payments.php?edit=<?php echo e($a['payment_public_id']); ?>">✏️ ویرایش پرداخت</a>
                <?php } else { ?>
                  <a class="btn btn-sm btn-primary"
                     href="finance_payments.php?appt=<?php echo e($a['public_id']); ?>">💳 ثبت پرداخت</a>
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
<?php require __DIR__ . '/../templates/footer.php'; ?>
