<?php
/* ═══════════════════════════════════════════════════════════════════
 *  جوما فاز ۵ — مدیریت حساب‌های بانکی (فقط مدیر)
 *
 *  کلینیک دو حساب بانکی دارد. هنگام ثبت پرداختِ مراجع، منشی/مدیر
 *  مشخص می‌کند وجه به کدام حساب واریز شده است.
 *  حساب هیچ‌گاه حذف فیزیکی نمی‌شود؛ فقط غیرفعال می‌شود.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

if (!phase5_ready($db)) {
    redirect(APP_BASE_URL . '/upgrade_phase5.php');
}

$actor_person_id = (int)$_SESSION['person_id'];
$actor_role_code = $_SESSION['active_role_code'];

$errors = array();
$general_error = '';
$form = array(
    'label' => '',
    'bank_name' => '',
    'account_number' => '',
    'card_number' => '',
);
$edit_account = null;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    try {
        $edit_account = finance_account_find($db, $_GET['edit']);
    } catch (Exception $ex) {
        $edit_account = null;
    }
    if ($edit_account) {
        $form['label'] = $edit_account['label'];
        $form['bank_name'] = $edit_account['bank_name'] !== null ? $edit_account['bank_name'] : '';
        $form['account_number'] = $edit_account['account_number'] !== null ? $edit_account['account_number'] : '';
        $form['card_number'] = $edit_account['card_number'] !== null ? $edit_account['card_number'] : '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    try {
        if ($action === 'create' || $action === 'update') {
            $form['label'] = isset($_POST['label']) ? $_POST['label'] : '';
            $form['bank_name'] = isset($_POST['bank_name']) ? $_POST['bank_name'] : '';
            $form['account_number'] = isset($_POST['account_number']) ? $_POST['account_number'] : '';
            $form['card_number'] = isset($_POST['card_number']) ? $_POST['card_number'] : '';

            $check = finance_account_validate($_POST);
            if (!$check['ok']) {
                $errors = $check['errors'];
                if ($action === 'update') {
                    $edit_account = finance_account_find($db, isset($_POST['account_public_id']) ? $_POST['account_public_id'] : '');
                }
            } elseif ($action === 'create') {
                finance_account_create($db, $check, $actor_person_id, $actor_role_code);
                flash_set('accounts_ok', 'حساب «' . $check['label'] . '» ثبت شد.');
                redirect(APP_BASE_URL . '/admin/finance_bank_accounts.php');
            } else {
                $target = finance_account_find($db, isset($_POST['account_public_id']) ? $_POST['account_public_id'] : '');
                if (!$target) {
                    throw new Exception('حساب موردنظر پیدا نشد.');
                }
                finance_account_update($db, (int)$target['id'], $check, $actor_person_id, $actor_role_code);
                flash_set('accounts_ok', 'حساب «' . $check['label'] . '» به‌روزرسانی شد.');
                redirect(APP_BASE_URL . '/admin/finance_bank_accounts.php');
            }
        } elseif ($action === 'status') {
            $target = finance_account_find($db, isset($_POST['account_public_id']) ? $_POST['account_public_id'] : '');
            if (!$target) {
                throw new Exception('حساب موردنظر پیدا نشد.');
            }
            $new_status = ($target['status'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE';
            finance_account_set_status($db, (int)$target['id'], $new_status, $actor_person_id, $actor_role_code);
            flash_set('accounts_ok', 'وضعیت حساب «' . $target['label'] . '» تغییر کرد.');
            redirect(APP_BASE_URL . '/admin/finance_bank_accounts.php');
        }
    } catch (Exception $ex) {
        $general_error = $ex->getMessage();
    }
}

try {
    $accounts = finance_accounts_fetch_all($db);
} catch (Exception $ex) {
    $ref = log_system_error('FINANCE_ACCOUNTS_LIST', $ex);
    render_error_page($ref);
}

$page_title = 'حساب‌های بانکی';
$active_menu = 'finance_bank_accounts';
require __DIR__ . '/../templates/header.php';
?>
<h1>🏦 حساب‌های بانکی</h1>
<?php echo flash_render('accounts_ok', 'success'); ?>
<?php if ($general_error !== '') { ?>
  <div class="alert alert-danger"><?php echo e($general_error); ?></div>
<?php } ?>

<div class="alert alert-info">
  در این صفحه شمارهٔ کارت یا حساب بانکیِ کلینیک را ثبت می‌کنید.
  هنگام ثبت پرداختِ مراجع، مشخص می‌شود وجه به کدام حساب واریز شده است.
</div>

<div class="card">
  <div class="card-header"><?php echo $edit_account ? 'ویرایش حساب' : 'افزودن حساب تازه'; ?></div>
  <div class="card-body">
    <form method="post" action="finance_bank_accounts.php" class="form-grid">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="<?php echo $edit_account ? 'update' : 'create'; ?>">
      <?php if ($edit_account) { ?>
        <input type="hidden" name="account_public_id" value="<?php echo e($edit_account['public_id']); ?>">
      <?php } ?>

      <div class="form-row">
        <label for="label">برچسب حساب <span class="required-star">*</span></label>
        <input type="text" id="label" name="label" value="<?php echo e($form['label']); ?>"
               placeholder="مثلاً حساب اصلی / کارت-to-کارت"
               class="<?php echo isset($errors['label']) ? 'is-invalid' : ''; ?>" required>
        <?php if (isset($errors['label'])) { ?><span class="field-error"><?php echo e($errors['label']); ?></span><?php } ?>
      </div>

      <div class="form-row">
        <label for="bank_name">نام بانک</label>
        <input type="text" id="bank_name" name="bank_name" value="<?php echo e($form['bank_name']); ?>"
               class="<?php echo isset($errors['bank_name']) ? 'is-invalid' : ''; ?>">
        <?php if (isset($errors['bank_name'])) { ?><span class="field-error"><?php echo e($errors['bank_name']); ?></span><?php } ?>
      </div>

      <div class="form-row">
        <label for="account_number">شمارهٔ حساب</label>
        <input type="text" id="account_number" name="account_number" value="<?php echo e($form['account_number']); ?>"
               class="<?php echo isset($errors['account_number']) ? 'is-invalid' : ''; ?>">
        <?php if (isset($errors['account_number'])) { ?><span class="field-error"><?php echo e($errors['account_number']); ?></span><?php } ?>
      </div>

      <div class="form-row">
        <label for="card_number">شمارهٔ کارت (۱۶ رقم)</label>
        <input type="text" id="card_number" name="card_number" value="<?php echo e($form['card_number']); ?>"
               class="<?php echo isset($errors['card_number']) ? 'is-invalid' : ''; ?>">
        <?php if (isset($errors['card_number'])) { ?><span class="field-error"><?php echo e($errors['card_number']); ?></span><?php } ?>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?php echo $edit_account ? '💾 ذخیرهٔ تغییرات' : '➕ ثبت حساب'; ?></button>
        <?php if ($edit_account) { ?>
          <a href="finance_bank_accounts.php" class="btn btn-secondary">انصراف</a>
        <?php } ?>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">فهرست حساب‌ها (<?php echo to_persian_digits(count($accounts)); ?>)</div>
  <div class="card-body">
    <?php if (count($accounts) === 0) { ?>
      <p class="empty-state">هنوز حساب بانکی ثبت نشده است. ابتدا دست‌کم یک حساب تعریف کنید.</p>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead>
            <tr>
              <th>برچسب</th><th>بانک</th><th>شمارهٔ حساب</th>
              <th>شمارهٔ کارت</th><th>وضعیت</th><th>عملیات</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($accounts as $account) { ?>
            <tr>
              <td data-label="برچسب"><?php echo e($account['label']); ?></td>
              <td data-label="بانک"><?php echo $account['bank_name'] !== null ? e($account['bank_name']) : '—'; ?></td>
              <td data-label="شمارهٔ حساب"><?php echo $account['account_number'] !== null
                    ? to_persian_digits(e($account['account_number'])) : '—'; ?></td>
              <td data-label="شمارهٔ کارت"><?php echo $account['card_number'] !== null
                    ? to_persian_digits(e($account['card_number'])) : '—'; ?></td>
              <td data-label="وضعیت">
                <span class="badge <?php echo $account['status'] === 'ACTIVE' ? 'badge-success' : 'badge-muted'; ?>">
                  <?php echo $account['status'] === 'ACTIVE' ? 'فعال' : 'غیرفعال'; ?>
                </span>
              </td>
              <td data-label="عملیات">
                <a class="btn btn-sm btn-secondary"
                   href="finance_bank_accounts.php?edit=<?php echo e($account['public_id']); ?>">✏️ ویرایش</a>
                <form method="post" action="finance_bank_accounts.php" class="inline-form">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="account_public_id" value="<?php echo e($account['public_id']); ?>">
                  <button type="submit" class="btn btn-sm <?php
                      echo $account['status'] === 'ACTIVE' ? 'btn-warning' : 'btn-success'; ?>">
                    <?php echo $account['status'] === 'ACTIVE' ? '⏸ غیرفعال' : '▶️ فعال'; ?>
                  </button>
                </form>
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
