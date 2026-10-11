<?php
/* ═══════════════════════════════════════════════════════════════════
 *  جوما فاز ۵ — مدیریت مراکز مشاوره (فقط مدیر)
 *
 *  هر مرکز مشاوره، ساختمانی است که کلینیک در آن اتاق اجاره کرده است.
 *  دو مدل کمیسیون برای مرکزها:
 *    - درصدی: مرکز، درصدی از مجموع وجوه مراجعان را می‌گیرد
 *    - ساعتی: مرکز، به‌ازای هر ساعت استفاده از اتاق، مبلغ ثابت می‌گیرد
 *  بازهٔ تسویه با هر مرکز (روزانه/ماهیانه) مستقل تنظیم می‌شود.
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
    'name' => '',
    'address' => '',
    'phone' => '',
    'commission_model' => 'PERCENTAGE',
    'commission_percent' => '',
    'settlement_period' => 'MONTHLY',
);
$edit_center = null;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    try {
        $edit_center = finance_center_find($db, $_GET['edit']);
    } catch (Exception $ex) {
        $edit_center = null;
    }
    if ($edit_center) {
        $form['name'] = $edit_center['name'];
        $form['address'] = $edit_center['address'] !== null ? $edit_center['address'] : '';
        $form['phone'] = $edit_center['phone'] !== null ? $edit_center['phone'] : '';
        $form['commission_model'] = $edit_center['commission_model'];
        $form['commission_percent'] = $edit_center['commission_percent'] !== null
            ? to_persian_digits($edit_center['commission_percent']) : '';
        $form['settlement_period'] = $edit_center['settlement_period'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    try {
        if ($action === 'create' || $action === 'update') {
            $form['name'] = isset($_POST['name']) ? $_POST['name'] : '';
            $form['address'] = isset($_POST['address']) ? $_POST['address'] : '';
            $form['phone'] = isset($_POST['phone']) ? $_POST['phone'] : '';
            $form['commission_model'] = isset($_POST['commission_model']) ? $_POST['commission_model'] : 'PERCENTAGE';
            $form['commission_percent'] = isset($_POST['commission_percent']) ? $_POST['commission_percent'] : '';
            $form['settlement_period'] = isset($_POST['settlement_period']) ? $_POST['settlement_period'] : 'MONTHLY';

            $check = finance_center_validate($_POST);
            if (!$check['ok']) {
                $errors = $check['errors'];
                if ($action === 'update') {
                    $edit_center = finance_center_find($db, isset($_POST['center_public_id']) ? $_POST['center_public_id'] : '');
                }
            } elseif ($action === 'create') {
                finance_center_create($db, $check, $actor_person_id, $actor_role_code);
                flash_set('centers_ok', 'مرکز «' . $check['name'] . '» ثبت شد.');
                redirect(APP_BASE_URL . '/admin/finance_centers.php');
            } else {
                $target = finance_center_find($db, isset($_POST['center_public_id']) ? $_POST['center_public_id'] : '');
                if (!$target) {
                    throw new Exception('مرکز موردنظر پیدا نشد.');
                }
                finance_center_update($db, (int)$target['id'], $check, $actor_person_id, $actor_role_code);
                flash_set('centers_ok', 'مرکز «' . $check['name'] . '» به‌روزرسانی شد.');
                redirect(APP_BASE_URL . '/admin/finance_centers.php');
            }
        } elseif ($action === 'status') {
            $target = finance_center_find($db, isset($_POST['center_public_id']) ? $_POST['center_public_id'] : '');
            if (!$target) {
                throw new Exception('مرکز موردنظر پیدا نشد.');
            }
            $new_status = ($target['status'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE';
            finance_center_set_status($db, (int)$target['id'], $new_status, $actor_person_id, $actor_role_code);
            flash_set('centers_ok', 'وضعیت مرکز «' . $target['name'] . '» تغییر کرد.');
            redirect(APP_BASE_URL . '/admin/finance_centers.php');
        }
    } catch (Exception $ex) {
        $general_error = $ex->getMessage();
    }
}

try {
    $centers = finance_centers_fetch_all($db);
} catch (Exception $ex) {
    $ref = log_system_error('FINANCE_CENTERS_LIST', $ex);
    render_error_page($ref);
}

$page_title = 'مراکز مشاوره';
$active_menu = 'finance_centers';
require __DIR__ . '/../templates/header.php';
?>
<h1>💰 مراکز مشاوره</h1>
<?php echo flash_render('centers_ok', 'success'); ?>
<?php if ($general_error !== '') { ?>
  <div class="alert alert-danger"><?php echo e($general_error); ?></div>
<?php } ?>

<div class="alert alert-info">
  در «مدل درصدی»، مرکز درصدی از مجموع وجوه مراجعان خود را طلب می‌کند (مثلاً ۳۰٪).
  در «مدل ساعتی»، مرکز به‌ازای هر ساعت استفاده از اتاق، مبلغ ثابت می‌گیرد.
  بازهٔ تسویه با هر مرکز را جداگانه مشخص کنید؛ مثلاً یک مرکز ماهیانه و یک مرکز روزانه تسویه شود.
  اتاق‌های هر مرکز در صفحهٔ «تنظیمات مالی اتاق‌ها» مشخص می‌شوند.
</div>

<div class="card">
  <div class="card-header"><?php echo $edit_center ? 'ویرایش مرکز' : 'افزودن مرکز تازه'; ?></div>
  <div class="card-body">
    <form method="post" action="finance_centers.php" class="form-grid">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="<?php echo $edit_center ? 'update' : 'create'; ?>">
      <?php if ($edit_center) { ?>
        <input type="hidden" name="center_public_id" value="<?php echo e($edit_center['public_id']); ?>">
      <?php } ?>

      <div class="form-row">
        <label for="name">نام مرکز <span class="required-star">*</span></label>
        <input type="text" id="name" name="name" value="<?php echo e($form['name']); ?>"
               class="<?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" required>
        <?php if (isset($errors['name'])) { ?><span class="field-error"><?php echo e($errors['name']); ?></span><?php } ?>
      </div>

      <div class="form-row">
        <label for="address">آدرس</label>
        <input type="text" id="address" name="address" value="<?php echo e($form['address']); ?>"
               class="<?php echo isset($errors['address']) ? 'is-invalid' : ''; ?>">
        <?php if (isset($errors['address'])) { ?><span class="field-error"><?php echo e($errors['address']); ?></span><?php } ?>
      </div>

      <div class="form-row">
        <label for="phone">شمارهٔ تماس</label>
        <input type="text" id="phone" name="phone" value="<?php echo e($form['phone']); ?>"
               class="<?php echo isset($errors['phone']) ? 'is-invalid' : ''; ?>">
        <?php if (isset($errors['phone'])) { ?><span class="field-error"><?php echo e($errors['phone']); ?></span><?php } ?>
      </div>

      <div class="form-row">
        <label for="commission_model">مدل کمیسیون <span class="required-star">*</span></label>
        <select id="commission_model" name="commission_model">
          <option value="PERCENTAGE" <?php echo $form['commission_model'] === 'PERCENTAGE' ? 'selected' : ''; ?>>درصدی (درصد از وجوه مراجعان)</option>
          <option value="HOURLY" <?php echo $form['commission_model'] === 'HOURLY' ? 'selected' : ''; ?>>ساعتی (مبلغ ثابت به‌ازای هر ساعت)</option>
        </select>
      </div>

      <div class="form-row">
        <label for="commission_percent">درصد کمیسیون (برای مدل درصدی)</label>
        <input type="text" id="commission_percent" name="commission_percent"
               value="<?php echo e($form['commission_percent']); ?>" placeholder="مثلاً ۳۰"
               class="<?php echo isset($errors['commission_percent']) ? 'is-invalid' : ''; ?>">
        <?php if (isset($errors['commission_percent'])) { ?><span class="field-error"><?php echo e($errors['commission_percent']); ?></span><?php } ?>
      </div>

      <div class="form-row">
        <label for="settlement_period">بازهٔ تسویه با این مرکز <span class="required-star">*</span></label>
        <select id="settlement_period" name="settlement_period">
          <option value="MONTHLY" <?php echo $form['settlement_period'] === 'MONTHLY' ? 'selected' : ''; ?>>ماهیانه</option>
          <option value="DAILY" <?php echo $form['settlement_period'] === 'DAILY' ? 'selected' : ''; ?>>روزانه</option>
        </select>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?php echo $edit_center ? '💾 ذخیرهٔ تغییرات' : '➕ ثبت مرکز'; ?></button>
        <?php if ($edit_center) { ?>
          <a href="finance_centers.php" class="btn btn-secondary">انصراف</a>
        <?php } ?>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">فهرست مراکز (<?php echo to_persian_digits(count($centers)); ?>)</div>
  <div class="card-body">
    <?php if (count($centers) === 0) { ?>
      <p class="empty-state">هنوز مرکزی ثبت نشده است. ابتدا حداقل یک مرکز مشاوره تعریف کنید.</p>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead>
            <tr>
              <th>نام مرکز</th><th>تماس</th><th>مدل کمیسیون</th>
              <th>تسویه</th><th>تعداد اتاق</th><th>وضعیت</th><th>عملیات</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($centers as $center) { ?>
            <tr>
              <td data-label="نام مرکز"><?php echo e($center['name']); ?></td>
              <td data-label="تماس"><?php echo $center['phone'] !== null
                    ? to_persian_digits(e($center['phone'])) : '—'; ?></td>
              <td data-label="مدل کمیسیون">
                <?php if ($center['commission_model'] === 'PERCENTAGE') { ?>
                  درصدی — <?php echo to_persian_digits($center['commission_percent']); ?>٪
                <?php } else { ?>
                  ساعتی
                <?php } ?>
              </td>
              <td data-label="تسویه"><?php echo finance_settlement_period_label($center['settlement_period']); ?></td>
              <td data-label="تعداد اتاق"><?php echo to_persian_digits($center['rooms_count']); ?></td>
              <td data-label="وضعیت">
                <span class="badge <?php echo $center['status'] === 'ACTIVE' ? 'badge-success' : 'badge-muted'; ?>">
                  <?php echo $center['status'] === 'ACTIVE' ? 'فعال' : 'غیرفعال'; ?>
                </span>
              </td>
              <td data-label="عملیات">
                <a class="btn btn-sm btn-secondary"
                   href="finance_centers.php?edit=<?php echo e($center['public_id']); ?>">✏️ ویرایش</a>
                <form method="post" action="finance_centers.php" class="inline-form">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="center_public_id" value="<?php echo e($center['public_id']); ?>">
                  <button type="submit" class="btn btn-sm <?php
                      echo $center['status'] === 'ACTIVE' ? 'btn-warning' : 'btn-success'; ?>">
                    <?php echo $center['status'] === 'ACTIVE' ? '⏸ غیرفعال' : '▶️ فعال'; ?>
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
