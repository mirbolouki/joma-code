<?php
/* ═══════════════════════════════════════════════════════════════════
 *  جوما فاز ۵ — فروشِ پکیج‌های تمرینیِ یک‌ماهه (مدیر و منشی)
 *
 *  تمرین‌ها خارج از سیستم انجام می‌شوند (PDF/لینک)؛ سیستم فقط فروش +
 *  بازهٔ اعتبارِ یک‌ماهه را ثبت می‌کند (مراجع، نامِ پکیج، مبلغ، حسابِ
 *  بانکی، وضعیت، بازهٔ اعتبار).
 *  همهٔ انتخاب‌ها (مراجع، حسابِ بانکی، وضعیت) از فهرست‌های کشویی است
 *  و تاریخ‌ها با انتخابگرِ سه‌بخشیِ سال/ماه/روز وارد می‌شوند (بدون تایپ).
 *  مبالغ به تومان وارد می‌شوند و به ریال ذخیره می‌شوند.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_SECRETARY, ROLE_ADMIN));

if (!phase5_4_ready($db)) {
    redirect(APP_BASE_URL . '/upgrade_phase5_4.php');
}

$actor_person_id = (int)$_SESSION['person_id'];
$actor_role_code = $_SESSION['active_role_code'];

$errors = array();
$general_error = '';

/* ── حالتِ فرم ── */
$edit_sale = null;   /* فروشِ در حالِ ویرایشِ وضعیت */
$def_period = finance_default_package_period();
$form = array(
    'patient_person_id' => '',
    'package_name' => 'بستهٔ تمرینِ یک‌ماهه',
    'amount' => '',
    'period_start' => $def_period['start_j'],
    'period_end' => $def_period['end_j'],
    'status' => 'PENDING',
    'paid_bank_account_id' => '',
    'note' => '',
);

$patients = finance_patients_fetch_active($db);
$accounts = finance_accounts_fetch_active($db);

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    try {
        $edit_sale = finance_package_sale_find($db, $_GET['edit']);
    } catch (Exception $ex) {
        $edit_sale = null;
    }
    if ($edit_sale) {
        $form['status'] = $edit_sale['status'];
        $form['paid_bank_account_id'] = $edit_sale['paid_bank_account_id'] !== null
            ? (string)(int)$edit_sale['paid_bank_account_id'] : '';
        $form['note'] = $edit_sale['note'] !== null ? $edit_sale['note'] : '';
    }
}

/* ── پردازش POST ────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    try {
        if ($action === 'create') {
            $form['patient_person_id'] = isset($_POST['patient_person_id']) ? $_POST['patient_person_id'] : '';
            $form['package_name'] = isset($_POST['package_name']) ? $_POST['package_name'] : '';
            $form['amount'] = isset($_POST['amount']) ? $_POST['amount'] : '';
            $form['period_start'] = finance_jalali_date3_compose(
                isset($_POST['period_start_y']) ? $_POST['period_start_y'] : '',
                isset($_POST['period_start_m']) ? $_POST['period_start_m'] : '',
                isset($_POST['period_start_d']) ? $_POST['period_start_d'] : '');
            $form['period_end'] = finance_jalali_date3_compose(
                isset($_POST['period_end_y']) ? $_POST['period_end_y'] : '',
                isset($_POST['period_end_m']) ? $_POST['period_end_m'] : '',
                isset($_POST['period_end_d']) ? $_POST['period_end_d'] : '');
            $form['status'] = isset($_POST['status']) ? $_POST['status'] : 'PENDING';
            $form['paid_bank_account_id'] = isset($_POST['paid_bank_account_id']) ? $_POST['paid_bank_account_id'] : '';
            $form['note'] = isset($_POST['note']) ? $_POST['note'] : '';

            $check = finance_package_sale_validate($_POST);
            if (!$check['ok']) {
                $errors = $check['errors'];
                /* برای نمایشِ دوبارهٔ فرم، مقدارِ ترکیب‌شدهٔ تاریخ را نگه می‌داریم */
                $form['period_start'] = finance_jalali_date3_compose(
                    isset($_POST['period_start_y']) ? $_POST['period_start_y'] : '',
                    isset($_POST['period_start_m']) ? $_POST['period_start_m'] : '',
                    isset($_POST['period_start_d']) ? $_POST['period_start_d'] : '');
                $form['period_end'] = finance_jalali_date3_compose(
                    isset($_POST['period_end_y']) ? $_POST['period_end_y'] : '',
                    isset($_POST['period_end_m']) ? $_POST['period_end_m'] : '',
                    isset($_POST['period_end_d']) ? $_POST['period_end_d'] : '');
            } else {
                $result = finance_package_sale_create($db, $check, $actor_person_id, $actor_role_code);
                if (!$result['ok']) {
                    throw new Exception($result['error']);
                }
                flash_set('sales_ok', 'فروشِ پکیج ثبت شد. بازهٔ اعتبار: '
                    . to_persian_digits(jalali_value($check['period_start_g']))
                    . ' تا ' . to_persian_digits(jalali_value($check['period_end_g'])));
                redirect(APP_BASE_URL . '/reception/finance_package_sales.php');
            }
        } elseif ($action === 'update') {
            $target = finance_package_sale_find($db, isset($_POST['sale_public_id']) ? $_POST['sale_public_id'] : '');
            if (!$target) {
                throw new Exception('فروشِ موردنظر پیدا نشد.');
            }
            $form['status'] = isset($_POST['status']) ? $_POST['status'] : 'PENDING';
            $form['paid_bank_account_id'] = isset($_POST['paid_bank_account_id']) ? $_POST['paid_bank_account_id'] : '';
            $form['note'] = isset($_POST['note']) ? $_POST['note'] : '';

            $status = ($form['status'] === 'PAID') ? 'PAID' : 'PENDING';
            $account_raw = trim((string)$form['paid_bank_account_id']);
            $account_id = ($account_raw === '') ? null : (int)$account_raw;
            $note = trim((string)$form['note']);
            if ($status === 'PAID' && $account_id === null) {
                $errors['paid_bank_account_id'] = 'برای وضعیتِ «پرداخت‌شده»، حسابِ بانکی را انتخاب کنید.';
                $edit_sale = $target;
            } elseif (mb_strlen($note, 'UTF-8') > 255) {
                $errors['note'] = 'یادداشت بیش از حد طولانی است.';
                $edit_sale = $target;
            } else {
                $result = finance_package_sale_update_status($db, (int)$target['id'], array(
                    'status' => $status,
                    'paid_bank_account_id' => $account_id,
                    'note' => ($note === '' ? null : $note),
                ), $actor_person_id, $actor_role_code);
                if (!$result['ok']) {
                    throw new Exception($result['error']);
                }
                flash_set('sales_ok', 'وضعیتِ فروش به‌روز شد.');
                redirect(APP_BASE_URL . '/reception/finance_package_sales.php');
            }
        }
    } catch (Exception $ex) {
        $general_error = $ex->getMessage();
    }
}

try {
    $sales = finance_package_sales_fetch_all($db);
    $summary = finance_package_sales_summary($db);
} catch (Exception $ex) {
    $ref = log_system_error('FINANCE_PACKAGE_SALES_LIST', $ex);
    render_error_page($ref);
}

$page_title = 'فروشِ پکیج‌ها';
$active_menu = 'finance_package_sales';
require __DIR__ . '/../templates/header.php';
?>
<h1>📦 فروشِ پکیج‌های تمرینی</h1>
<?php echo flash_render('sales_ok', 'success'); ?>
<?php if ($general_error !== '') { ?>
  <div class="alert alert-danger"><?php echo e($general_error); ?></div>
<?php } ?>

<div class="alert alert-info">
  تمرین‌ها <strong>خارج از سیستم</strong> انجام می‌شوند (PDF/لینک)؛ اینجا فقط فروش +
  بازهٔ اعتبارِ یک‌ماهه ثبت می‌شود. همهٔ انتخاب‌ها از فهرست‌های کشویی است
  و تاریخ‌ها با انتخابگرِ <strong>سال / ماه / روز</strong> وارد می‌شوند (بدون تایپ).
</div>

<div class="card">
  <div class="card-header">خلاصهٔ فروشِ پکیج‌ها</div>
  <div class="card-body">
    <strong><?php echo to_persian_digits($summary['cnt']); ?></strong> فروشِ ثبت‌شده &nbsp;|&nbsp;
    مجموع: <strong><?php echo tariff_rial_to_toman_display($summary['total_rial']); ?></strong> &nbsp;|&nbsp;
    پرداخت‌شده: <strong><?php echo tariff_rial_to_toman_display($summary['paid_rial']); ?></strong>
  </div>
</div>

<div class="card">
  <div class="card-header">ثبت فروشِ پکیجِ جدید</div>
  <div class="card-body">
    <form method="post" action="finance_package_sales.php" class="form-grid">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="create">

      <div class="form-row">
        <label for="patient_person_id">مراجع <span class="required-star">*</span></label>
        <select id="patient_person_id" name="patient_person_id"
                class="<?php echo isset($errors['patient_person_id']) ? 'is-invalid' : ''; ?>">
          <option value="">— انتخاب مراجع —</option>
          <?php foreach ($patients as $p) { ?>
            <option value="<?php echo (int)$p['id']; ?>"
              <?php echo ($form['patient_person_id'] === (string)(int)$p['id']) ? 'selected' : ''; ?>>
              <?php echo e(finance_patient_label($p)); ?>
            </option>
          <?php } ?>
        </select>
        <?php if (isset($errors['patient_person_id'])) { ?><span class="field-error"><?php echo e($errors['patient_person_id']); ?></span><?php } ?>
      </div>

      <div class="form-row">
        <label for="package_name">نامِ پکیج <span class="required-star">*</span></label>
        <input type="text" id="package_name" name="package_name"
               value="<?php echo e($form['package_name']); ?>"
               class="<?php echo isset($errors['package_name']) ? 'is-invalid' : ''; ?>">
        <?php if (isset($errors['package_name'])) { ?><span class="field-error"><?php echo e($errors['package_name']); ?></span><?php } ?>
      </div>

      <div class="form-row">
        <label for="amount">مبلغِ فروش (تومان) <span class="required-star">*</span></label>
        <input type="text" id="amount" name="amount" dir="ltr"
               value="<?php echo e($form['amount']); ?>" placeholder="مثلاً ۲۰۰۰۰۰۰"
               class="<?php echo isset($errors['amount']) ? 'is-invalid' : ''; ?>">
        <?php if (isset($errors['amount'])) { ?><span class="field-error"><?php echo e($errors['amount']); ?></span><?php } ?>
      </div>

      <div class="form-row">
        <label>شروعِ اعتبار <span class="required-star">*</span></label>
        <?php echo finance_jalali_date3_html('period_start', $form['period_start']); ?>
        <div class="form-hint">سال / ماه / روز — انتخاب از فهرست، بدون تایپ</div>
      </div>

      <div class="form-row">
        <label>پایانِ اعتبار <span class="required-star">*</span></label>
        <?php echo finance_jalali_date3_html('period_end', $form['period_end']); ?>
        <div class="form-hint">سال / ماه / روز — انتخاب از فهرست، بدون تایپ (پیش‌فرض: یک‌ماهه)</div>
      </div>

      <div class="form-row">
        <label for="status">وضعیتِ پرداخت <span class="required-star">*</span></label>
        <select id="status" name="status">
          <option value="PENDING" <?php echo ($form['status'] === 'PENDING') ? 'selected' : ''; ?>>در انتظارِ پرداخت</option>
          <option value="PAID" <?php echo ($form['status'] === 'PAID') ? 'selected' : ''; ?>>پرداخت‌شده</option>
        </select>
      </div>

      <div class="form-row">
        <label for="paid_bank_account_id">حسابِ بانکیِ پرداخت (از کدام حساب)</label>
        <select id="paid_bank_account_id" name="paid_bank_account_id"
                class="<?php echo isset($errors['paid_bank_account_id']) ? 'is-invalid' : ''; ?>">
          <option value="">— انتخاب حساب —</option>
          <?php foreach ($accounts as $account) { ?>
            <option value="<?php echo (int)$account['id']; ?>"
              <?php echo ($form['paid_bank_account_id'] === (string)(int)$account['id']) ? 'selected' : ''; ?>>
              <?php echo e(finance_account_label($account)); ?>
            </option>
          <?php } ?>
        </select>
        <?php if (isset($errors['paid_bank_account_id'])) { ?><span class="field-error"><?php echo e($errors['paid_bank_account_id']); ?></span><?php } ?>
      </div>

      <div class="form-row" style="grid-column:1/-1;">
        <label for="note">یادداشت (اختیاری)</label>
        <input type="text" id="note" name="note" value="<?php echo e($form['note']); ?>"
               class="<?php echo isset($errors['note']) ? 'is-invalid' : ''; ?>">
        <?php if (isset($errors['note'])) { ?><span class="field-error"><?php echo e($errors['note']); ?></span><?php } ?>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">💾 ثبت فروش</button>
      </div>
    </form>
  </div>
</div>

<?php if ($edit_sale) { ?>
<div class="card">
  <div class="card-header">ویرایشِ وضعیتِ فروش</div>
  <div class="card-body">
    <div class="alert alert-info">
      <strong>پکیج:</strong> <?php echo e($edit_sale['package_name']); ?> &nbsp;|&nbsp;
      <strong>مبلغ:</strong> <?php echo tariff_rial_to_toman_display($edit_sale['amount_rial']); ?> &nbsp;|&nbsp;
      <strong>اعتبار:</strong> <?php echo to_persian_digits(jalali_value($edit_sale['period_start'])); ?>
      تا <?php echo to_persian_digits(jalali_value($edit_sale['period_end'])); ?>
    </div>
    <form method="post" action="finance_package_sales.php" class="form-grid">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="sale_public_id" value="<?php echo e($edit_sale['public_id']); ?>">

      <div class="form-row">
        <label for="edit_status">وضعیتِ پرداخت <span class="required-star">*</span></label>
        <select id="edit_status" name="status">
          <option value="PENDING" <?php echo ($form['status'] === 'PENDING') ? 'selected' : ''; ?>>در انتظارِ پرداخت</option>
          <option value="PAID" <?php echo ($form['status'] === 'PAID') ? 'selected' : ''; ?>>پرداخت‌شده</option>
        </select>
      </div>

      <div class="form-row">
        <label for="edit_account">حسابِ بانکیِ پرداخت (از کدام حساب)</label>
        <select id="edit_account" name="paid_bank_account_id"
                class="<?php echo isset($errors['paid_bank_account_id']) ? 'is-invalid' : ''; ?>">
          <option value="">— انتخاب حساب —</option>
          <?php foreach ($accounts as $account) { ?>
            <option value="<?php echo (int)$account['id']; ?>"
              <?php echo ($form['paid_bank_account_id'] === (string)(int)$account['id']) ? 'selected' : ''; ?>>
              <?php echo e(finance_account_label($account)); ?>
            </option>
          <?php } ?>
        </select>
        <?php if (isset($errors['paid_bank_account_id'])) { ?><span class="field-error"><?php echo e($errors['paid_bank_account_id']); ?></span><?php } ?>
      </div>

      <div class="form-row" style="grid-column:1/-1;">
        <label for="edit_note">یادداشت (اختیاری)</label>
        <input type="text" id="edit_note" name="note" value="<?php echo e($form['note']); ?>"
               class="<?php echo isset($errors['note']) ? 'is-invalid' : ''; ?>">
        <?php if (isset($errors['note'])) { ?><span class="field-error"><?php echo e($errors['note']); ?></span><?php } ?>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">💾 ثبت وضعیت</button>
        <a href="finance_package_sales.php" class="btn btn-secondary">انصراف</a>
      </div>
    </form>
  </div>
</div>
<?php } ?>

<div class="card">
  <div class="card-header">فهرست فروشِ پکیج‌ها (<?php echo to_persian_digits(count($sales)); ?>)</div>
  <div class="card-body">
    <?php if (count($sales) === 0) { ?>
      <p class="empty-state">هنوز فروشی ثبت نشده است.</p>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead>
            <tr>
              <th>مراجع</th><th>پکیج</th><th>بازهٔ اعتبار</th><th>مبلغ</th><th>حساب</th><th>وضعیت</th><th>عملیات</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($sales as $s) { ?>
            <tr>
              <td data-label="مراجع"><?php echo e(trim($s['first_name'] . ' ' . $s['last_name'])); ?><br><small><?php echo to_persian_digits($s['mobile_number']); ?></small></td>
              <td data-label="پکیج"><?php echo e($s['package_name']); ?></td>
              <td data-label="بازهٔ اعتبار">
                <?php echo to_persian_digits(jalali_value($s['period_start'])); ?>
                تا <?php echo to_persian_digits(jalali_value($s['period_end'])); ?>
              </td>
              <td data-label="مبلغ"><?php echo tariff_rial_to_toman_display($s['amount_rial']); ?></td>
              <td data-label="حساب"><?php echo $s['paid_account_label'] ? e($s['paid_account_label']) : '—'; ?></td>
              <td data-label="وضعیت">
                <span class="badge <?php echo finance_sale_status_class($s['status']); ?>"><?php echo finance_sale_status_label($s['status']); ?></span>
              </td>
              <td data-label="عملیات">
                <a class="btn btn-sm btn-secondary"
                   href="finance_package_sales.php?edit=<?php echo e($s['public_id']); ?>">✏️ ویرایش</a>
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
