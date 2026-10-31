<?php
/* DRAFT ONLY. Requires the future migration, includes and security review. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/finance_v2/money.php';
require_once __DIR__ . '/../includes/finance_v2/expenses.php';
require_once __DIR__ . '/../includes/finance_v2/storage/mysqli_expense_store.php';
$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

function expense_v2_ui_id($value)
{
    if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,17}$/D', $value)) return null;
    $id = (int)$value;
    return $id > 0 ? $id : null;
}

$error = '';
$default = finance_default_period_jalali('DAILY');
$selected_date = $default['start_j'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $selected_date = finance_jalali_date3_compose(isset($_POST['date_y']) ? $_POST['date_y'] : '',
            isset($_POST['date_m']) ? $_POST['date_m'] : '', isset($_POST['date_d']) ? $_POST['date_d'] : '');
        $gregorian = jalali_input_to_gregorian($selected_date);
        if ($gregorian === null) throw new InvalidArgumentException('Invalid date');
        $account_id = expense_v2_ui_id(isset($_POST['account_id']) ? $_POST['account_id'] : null);
        $expense = array('key'=>isset($_POST['operation_key']) ? $_POST['operation_key'] : null,
            'date'=>$gregorian, 'category'=>isset($_POST['category']) ? $_POST['category'] : null,
            'amount_rial'=>joma_v2_toman_to_rial(isset($_POST['amount_toman']) ? $_POST['amount_toman'] : null),
            'account_id'=>$account_id, 'description'=>isset($_POST['description']) ? $_POST['description'] : '');
        joma_v2_record_expense(new JomaV2MysqliExpenseStore($db), $expense,
            array('person_id'=>(int)$_SESSION['person_id'], 'role'=>$_SESSION['active_role_code']));
        flash_set('v2_expense_ok', 'هزینه ثبت شد.');
        redirect(APP_BASE_URL . '/admin/finance_expenses_v2.php');
    } catch (InvalidArgumentException $ex) {
        $error = 'ثبت انجام نشد. تاریخ، مبلغ، دسته، حساب و تکراری‌نبودن عملیات را بررسی کنید.';
    } catch (OverflowException $ex) {
        $error = 'مبلغ واردشده بیش از حد مجاز است.';
    } catch (Throwable $ex) {
        $ref = log_system_error('FINANCE_V2_EXPENSE_SAVE', $ex);
        $error = 'ثبت انجام نشد. کد پیگیری: ' . $ref;
    }
}
try {
    $accounts = finance_accounts_fetch_active($db);
    $expenses = db_select_all($db, 'SELECT ex.expense_date, ex.category, ex.amount_rial, ex.description, acc.label AS account_label
      FROM finance_v2_expenses ex JOIN finance_bank_accounts acc ON acc.id = ex.account_id ORDER BY ex.id DESC LIMIT 100');
} catch (Throwable $ex) {
    $ref = log_system_error('FINANCE_V2_EXPENSE_LIST', $ex);
    render_error_page($ref);
}
$page_title = 'هزینه‌های عمومی';
$active_menu = 'finance_expenses_v2';
require __DIR__ . '/../templates/header.php';
?>
<h1>هزینه‌های عمومی کلینیک</h1>
<?php echo flash_render('v2_expense_ok', 'success'); ?>
<?php if ($error !== '') { ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php } ?>
<div class="alert alert-info">هزینه‌هایی مانند اجاره و لوازم را به تومان ثبت کنید. توضیح، درمانی یا محرمانه نباشد.</div>
<div class="card"><div class="card-header">ثبت هزینه</div><div class="card-body">
<form method="post" action="finance_expenses_v2.php" class="form-grid">
<?php echo csrf_field(); ?><input type="hidden" name="operation_key" value="<?php echo e(bin2hex(random_bytes(16))); ?>">
<div class="form-row"><label>تاریخ</label><?php echo finance_jalali_date3_html('date', $selected_date); ?></div>
<div class="form-row"><label for="category">دسته</label><input id="category" name="category" maxlength="100" required placeholder="مثلاً اجاره"></div>
<div class="form-row"><label for="amount_toman">مبلغ (تومان)</label><input id="amount_toman" name="amount_toman" inputmode="numeric" dir="ltr" maxlength="80" required></div>
<div class="form-row"><label for="account_id">حساب پرداخت</label><select id="account_id" name="account_id" required><option value="">انتخاب کنید</option>
<?php foreach ($accounts as $account) { ?><option value="<?php echo (int)$account['id']; ?>"><?php echo e($account['label']); ?></option><?php } ?>
</select></div>
<div class="form-row"><label for="description">توضیح اختیاری</label><input id="description" name="description" maxlength="255"></div>
<div class="form-actions"><button class="btn btn-primary" type="submit">ثبت هزینه</button></div>
</form></div></div>
<div class="card"><div class="card-header">آخرین هزینه‌های ثبت‌شده</div><div class="card-body"><div class="table-wrap"><table class="table table-card"><thead><tr><th>تاریخ</th><th>دسته</th><th>مبلغ</th><th>حساب</th><th>توضیح</th></tr></thead><tbody>
<?php foreach ($expenses as $row) { ?><tr><td><?php echo e(jalali_value($row['expense_date'])); ?></td><td><?php echo e($row['category']); ?></td><td><?php echo e(joma_v2_rial_to_toman_string((int)$row['amount_rial'])); ?> تومان</td><td><?php echo e($row['account_label']); ?></td><td><?php echo e($row['description'] === null ? '' : $row['description']); ?></td></tr><?php } ?>
</tbody></table></div></div></div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
