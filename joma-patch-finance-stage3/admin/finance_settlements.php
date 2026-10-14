<?php
/* ═══════════════════════════════════════════════════════════════════
 *  جوما فاز ۵ — تسویه با مراکز مشاوره (فقط مدیر)
 *
 *  مبلغِ قابل‌پرداخت به هر مرکز به‌صورت خودکار محاسبه می‌شود:
 *    - مرکزِ درصدی:  درصد × مجموعِ پرداخت‌های «پرداخت‌شده»ِ جلساتِ
 *      اتاق‌های آن مرکز در بازهٔ تسویه
 *    - مرکزِ ساعتی:  مجموع (ساعت × تعرفهٔ اتاق) — نوبتِ زوج/خانواده
 *      خودکار با تعرفهٔ اتاقِ زوج حساب می‌شود
 *  بازهٔ تسویهٔ هر مرکز (روزانه/ماهیانه) در مرحلهٔ ۱ مشخص شده است.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

if (!phase5_3_ready($db)) {
    redirect(APP_BASE_URL . '/upgrade_phase5_3.php');
}

$actor_person_id = (int)$_SESSION['person_id'];
$actor_role_code = $_SESSION['active_role_code'];

$errors = array();
$general_error = '';

/* ── حالتِ فرم ── */
$edit_settlement = null;   /* تسویهٔ در حالِ ثبتِ پرداخت */
$form = array(
    'center_id' => '',
    'period_start' => '',
    'period_end' => '',
    'amount_paid' => '',
    'paid_bank_account_id' => '',
    'note' => '',
);

$centers = finance_centers_fetch_active($db);
$centers_by_id = array();
foreach ($centers as $c) {
    $centers_by_id[(int)$c['id']] = $c;
}

/* پیش‌فرض: مرکز از ?center= یا اولین مرکزِ فعال */
$default_center_id = 0;
if (isset($_GET['center']) && isset($centers_by_id[(int)$_GET['center']])) {
    $default_center_id = (int)$_GET['center'];
} elseif (count($centers) > 0) {
    $default_center_id = (int)$centers[0]['id'];
}
if ($default_center_id > 0) {
    $def_period = finance_default_period_jalali($centers_by_id[$default_center_id]['settlement_period']);
    $form['center_id'] = (string)$default_center_id;
    $form['period_start'] = $def_period['start_j'];
    $form['period_end'] = $def_period['end_j'];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    try {
        $edit_settlement = finance_settlement_find($db, $_GET['edit']);
    } catch (Exception $ex) {
        $edit_settlement = null;
    }
    if ($edit_settlement) {
        $form['amount_paid'] = $edit_settlement['amount_paid_rial'] !== null
            ? tariff_rial_to_toman_value($edit_settlement['amount_paid_rial']) : '';
        $form['paid_bank_account_id'] = $edit_settlement['paid_bank_account_id'] !== null
            ? (string)(int)$edit_settlement['paid_bank_account_id'] : '';
        $form['note'] = $edit_settlement['note'] !== null ? $edit_settlement['note'] : '';
    }
}

/* ── پردازش POST ────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    try {
        if ($action === 'create') {
            $form['center_id'] = isset($_POST['center_id']) ? $_POST['center_id'] : '';
            $form['period_start'] = isset($_POST['period_start']) ? $_POST['period_start'] : '';
            $form['period_end'] = isset($_POST['period_end']) ? $_POST['period_end'] : '';

            if ($form['center_id'] === '' || !isset($centers_by_id[(int)$form['center_id']])) {
                $errors['center_id'] = 'مرکز را از فهرستِ کشویی انتخاب کنید.';
            } else {
                $result = finance_settlement_create($db, (int)$form['center_id'],
                    $form['period_start'], $form['period_end'],
                    $actor_person_id, $actor_role_code);
                if (!$result['ok']) {
                    throw new Exception($result['error']);
                }
                flash_set('settlements_ok',
                    'تسویه ثبت شد. مبلغِ قابل‌پرداخت: '
                    . tariff_rial_to_toman_display($result['amount_due_rial'])
                    . ' — ' . $result['detail']);
                redirect(APP_BASE_URL . '/admin/finance_settlements.php');
            }
        } elseif ($action === 'pay') {
            $target = finance_settlement_find($db, isset($_POST['settlement_public_id']) ? $_POST['settlement_public_id'] : '');
            if (!$target) {
                throw new Exception('تسویهٔ موردنظر پیدا نشد.');
            }
            $form['amount_paid'] = isset($_POST['amount_paid']) ? $_POST['amount_paid'] : '';
            $form['paid_bank_account_id'] = isset($_POST['paid_bank_account_id']) ? $_POST['paid_bank_account_id'] : '';
            $form['note'] = isset($_POST['note']) ? $_POST['note'] : '';

            $check = finance_settlement_validate_payment($_POST, $target['amount_due_rial']);
            if (!$check['ok']) {
                $errors = $check['errors'];
                $edit_settlement = $target;
            } else {
                $result = finance_settlement_update_payment($db, (int)$target['id'], $check,
                    $actor_person_id, $actor_role_code);
                if (!$result['ok']) {
                    throw new Exception($result['error']);
                }
                flash_set('settlements_ok', 'پرداختِ تسویه ثبت شد.');
                redirect(APP_BASE_URL . '/admin/finance_settlements.php');
            }
        }
    } catch (Exception $ex) {
        $general_error = $ex->getMessage();
    }
}

try {
    $settlements = finance_settlements_fetch_all($db);
    $summary = finance_settlements_summary($db);
    $accounts = finance_accounts_fetch_active($db);
} catch (Exception $ex) {
    $ref = log_system_error('FINANCE_SETTLEMENTS_LIST', $ex);
    render_error_page($ref);
}

$page_title = 'تسویه با مراکز';
$active_menu = 'finance_settlements';
require __DIR__ . '/../templates/header.php';
?>
<h1>🤝 تسویه با مراکز مشاوره</h1>
<?php echo flash_render('settlements_ok', 'success'); ?>
<?php if ($general_error !== '') { ?>
  <div class="alert alert-danger"><?php echo e($general_error); ?></div>
<?php } ?>

<div class="alert alert-info">
  مبلغِ قابل‌پرداخت به هر مرکز <strong>خودکار</strong> محاسبه می‌شود:
  مرکزِ <strong>درصدی</strong> → درصد × مجموعِ پرداخت‌های «پرداخت‌شده»ِ جلساتِ اتاق‌های آن مرکز در بازه،
  و مرکزِ <strong>ساعتی</strong> → مجموعِ ساعت × تعرفهٔ اتاق (نوبتِ زوج/خانواده با تعرفهٔ اتاقِ زوج).
  تاریخ‌ها را به‌صورت <strong>شمسی</strong> وارد کنید (مثلاً ۱۴۰۵/۰۷/۰۱).
</div>

<div class="card">
  <div class="card-header">خلاصهٔ تسویه‌ها</div>
  <div class="card-body">
    <strong><?php echo to_persian_digits($summary['cnt']); ?></strong> تسویهٔ ثبت‌شده &nbsp;|&nbsp;
    مجموعِ قابل‌پرداخت: <strong><?php echo tariff_rial_to_toman_display($summary['total_due_rial']); ?></strong> &nbsp;|&nbsp;
    مجموعِ پرداخت‌شده: <strong><?php echo tariff_rial_to_toman_display($summary['total_paid_rial']); ?></strong>
  </div>
</div>

<?php if (count($centers) === 0) { ?>
  <div class="alert alert-warning">هیچ مرکزِ فعالی ثبت نشده است. ابتدا در صفحهٔ «💰 مراکز مشاوره» مرکز تعریف کنید.</div>
<?php } else { ?>
<div class="card">
  <div class="card-header">ایجاد تسویهٔ جدید (محاسبهٔ خودکار)</div>
  <div class="card-body">
    <form method="post" action="finance_settlements.php" class="form-grid">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="create">

      <div class="form-row">
        <label for="center_id">مرکز <span class="required-star">*</span></label>
        <select id="center_id" name="center_id"
                class="<?php echo isset($errors['center_id']) ? 'is-invalid' : ''; ?>">
          <option value="">— انتخاب مرکز —</option>
          <?php foreach ($centers as $c) { ?>
            <option value="<?php echo (int)$c['id']; ?>"
              <?php echo ($form['center_id'] === (string)(int)$c['id']) ? 'selected' : ''; ?>>
              <?php echo e(finance_center_label($c)); ?>
              (<?php echo finance_settlement_period_label($c['settlement_period']); ?>)
            </option>
          <?php } ?>
        </select>
        <?php if (isset($errors['center_id'])) { ?><span class="field-error"><?php echo e($errors['center_id']); ?></span><?php } ?>
      </div>

      <div class="form-row">
        <label for="period_start">از تاریخ (شمسی) <span class="required-star">*</span></label>
        <input type="text" id="period_start" name="period_start" dir="ltr"
               value="<?php echo e($form['period_start']); ?>" placeholder="۱۴۰۵/۰۷/۰۱"
               class="<?php echo isset($errors['period_start']) ? 'is-invalid' : ''; ?>">
        <?php if (isset($errors['period_start'])) { ?><span class="field-error"><?php echo e($errors['period_start']); ?></span><?php } ?>
      </div>

      <div class="form-row">
        <label for="period_end">تا تاریخ (شمسی) <span class="required-star">*</span></label>
        <input type="text" id="period_end" name="period_end" dir="ltr"
               value="<?php echo e($form['period_end']); ?>" placeholder="۱۴۰۵/۰۷/۳۰"
               class="<?php echo isset($errors['period_end']) ? 'is-invalid' : ''; ?>">
        <?php if (isset($errors['period_end'])) { ?><span class="field-error"><?php echo e($errors['period_end']); ?></span><?php } ?>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">🧮 محاسبه و ثبت تسویه</button>
      </div>
    </form>
  </div>
</div>
<?php } ?>

<?php if ($edit_settlement) { ?>
<div class="card">
  <div class="card-header">ثبت پرداختِ تسویه</div>
  <div class="card-body">
    <div class="alert alert-info">
      <strong>مرکز:</strong> <?php echo e($edit_settlement['center_name'] ?? ''); ?> &nbsp;|&nbsp;
      <strong>بازه:</strong> <?php echo to_persian_digits(jalali_value($edit_settlement['period_start'])); ?>
      تا <?php echo to_persian_digits(jalali_value($edit_settlement['period_end'])); ?> &nbsp;|&nbsp;
      <strong>مبلغِ قابل‌پرداخت:</strong> <?php echo tariff_rial_to_toman_display($edit_settlement['amount_due_rial']); ?>
    </div>
    <form method="post" action="finance_settlements.php" class="form-grid">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="pay">
      <input type="hidden" name="settlement_public_id" value="<?php echo e($edit_settlement['public_id']); ?>">

      <div class="form-row">
        <label for="amount_paid">مبلغِ واریزی به مرکز (تومان)</label>
        <input type="text" id="amount_paid" name="amount_paid"
               value="<?php echo e($form['amount_paid']); ?>" placeholder="مثلاً ۱۵۰۰۰۰۰"
               class="<?php echo isset($errors['amount_paid']) ? 'is-invalid' : ''; ?>">
        <?php if (isset($errors['amount_paid'])) { ?><span class="field-error"><?php echo e($errors['amount_paid']); ?></span><?php } ?>
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
        <button type="submit" class="btn btn-primary">💾 ثبت پرداخت</button>
        <a href="finance_settlements.php" class="btn btn-secondary">انصراف</a>
      </div>
    </form>
  </div>
</div>
<?php } ?>

<div class="card">
  <div class="card-header">فهرست تسویه‌ها (<?php echo to_persian_digits(count($settlements)); ?>)</div>
  <div class="card-body">
    <?php if (count($settlements) === 0) { ?>
      <p class="empty-state">هنوز تسویه‌ای ثبت نشده است.</p>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead>
            <tr>
              <th>مرکز</th><th>بازهٔ تسویه</th><th>مدل</th><th>قابل‌پرداخت</th>
              <th>پرداخت‌شده</th><th>وضعیت</th><th>عملیات</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($settlements as $s) {
              $st = finance_settlement_status($s);
          ?>
            <tr>
              <td data-label="مرکز"><?php echo e($s['center_name']); ?></td>
              <td data-label="بازهٔ تسویه">
                <?php echo to_persian_digits(jalali_value($s['period_start'])); ?>
                تا <?php echo to_persian_digits(jalali_value($s['period_end'])); ?>
                <br><small><?php echo finance_settlement_period_label($s['settlement_period']); ?></small>
              </td>
              <td data-label="مدل">
                <?php if ($s['commission_model'] === 'PERCENTAGE') { ?>
                  درصدی — <?php echo str_replace('.', '٫', to_persian_digits(rtrim(rtrim(number_format((float)$s['commission_percent'], 2, '.', ''), '0'), '.'))); ?>٪
                <?php } else { ?>
                  ساعتی
                <?php } ?>
              </td>
              <td data-label="قابل‌پرداخت"><?php echo tariff_rial_to_toman_display($s['amount_due_rial']); ?></td>
              <td data-label="پرداخت‌شده">
                <?php echo $s['amount_paid_rial'] !== null
                    ? tariff_rial_to_toman_display($s['amount_paid_rial']) : '—'; ?>
                <?php if ($s['paid_account_label']) { ?>
                  <br><small><?php echo e($s['paid_account_label']); ?></small>
                <?php } ?>
              </td>
              <td data-label="وضعیت">
                <span class="badge <?php echo $st['class']; ?>"><?php echo $st['label']; ?></span>
              </td>
              <td data-label="عملیات">
                <a class="btn btn-sm btn-secondary"
                   href="finance_settlements.php?edit=<?php echo e($s['public_id']); ?>">💰 ثبت پرداخت</a>
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
