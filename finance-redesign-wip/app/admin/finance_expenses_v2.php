<?php
/* DRAFT ONLY. Requires the future migration, includes and security review. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/finance_v2/money.php';
require_once __DIR__ . '/../includes/finance_v2/expenses.php';
require_once __DIR__ . '/../includes/finance_v2/storage/mysqli_expense_store.php';
$db=$GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));
function expense_v2_ui_id($value, $zero=false) {
    if (!is_string($value) || !preg_match($zero ? '/^(0|[1-9][0-9]{0,17})$/D' : '/^[1-9][0-9]{0,17}$/D', $value)) return null;
    return (int)$value;
}
$error=''; $viewId=expense_v2_ui_id(isset($_GET['view']) ? $_GET['view'] : null);
$default=finance_default_period_jalali('DAILY'); $selected_date=$default['start_j'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $action=isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
        $actor=array('person_id'=>(int)$_SESSION['person_id'],'role'=>$_SESSION['active_role_code']);
        $data=array('key'=>isset($_POST['operation_key']) ? $_POST['operation_key'] : null);
        if ($action === 'CORRECT' || $action === 'VOID') {
            $viewId=expense_v2_ui_id(isset($_POST['expense_id']) ? $_POST['expense_id'] : null);
            $data['expense_id']=$viewId;
            $data['expected_revision_id']=expense_v2_ui_id(isset($_POST['expected_revision_id']) ? $_POST['expected_revision_id'] : null,true);
            $data['action']=$action; $data['reason']=isset($_POST['reason']) ? $_POST['reason'] : null;
        } elseif ($action !== 'CREATE') throw new InvalidArgumentException('Invalid action');
        if ($action !== 'VOID') {
            $selected_date=finance_jalali_date3_compose(isset($_POST['date_y']) ? $_POST['date_y'] : '',
                isset($_POST['date_m']) ? $_POST['date_m'] : '',isset($_POST['date_d']) ? $_POST['date_d'] : '');
            $data['date']=jalali_input_to_gregorian($selected_date);
            $data['category']=isset($_POST['category']) ? $_POST['category'] : null;
            $data['amount_rial']=joma_v2_toman_precise_to_rial(isset($_POST['amount_toman']) ? $_POST['amount_toman'] : null);
            $data['account_id']=expense_v2_ui_id(isset($_POST['account_id']) ? $_POST['account_id'] : null);
            $data['description']=isset($_POST['description']) ? $_POST['description'] : '';
        }
        $store=new JomaV2MysqliExpenseStore($db);
        if ($action === 'CREATE') $viewId=joma_v2_record_expense($store,$data,$actor);
        else joma_v2_revise_expense($store,$data,$actor);
        flash_set('v2_expense_ok', 'عملیات ثبت شد و سابقه حفظ شد.');
        redirect(APP_BASE_URL . '/admin/finance_expenses_v2.php?view=' . (int)$viewId);
    } catch (InvalidArgumentException $ex) {
        $error='عملیات انجام نشد. اطلاعات و دلیل را بررسی کنید؛ اگر این هزینه هم‌زمان تغییر کرده است، اطلاعات تازه را دوباره بررسی کنید.';
    } catch (OverflowException $ex) {
        $error='مبلغ واردشده بیش از حد مجاز است.';
    } catch (Throwable $ex) {
        $ref=log_system_error('FINANCE_V2_EXPENSE_SAVE',$ex); $error='عملیات انجام نشد. کد پیگیری: '.$ref;
    }
}
try {
    $accounts=finance_accounts_fetch_active($db);
    $store=new JomaV2MysqliExpenseStore($db);
    $expenses=$store->listExpenses();
    $view=$viewId ? $store->findExpense($viewId) : null;
    $original=$view ? $store->originalExpense($viewId) : null;
    $history=$view ? $store->expenseHistory($viewId) : array();
} catch (Throwable $ex) {
    $ref=log_system_error('FINANCE_V2_EXPENSE_LIST',$ex); render_error_page($ref);
}
$page_title='هزینه‌های عمومی'; $active_menu='finance_expenses_v2';
require __DIR__ . '/../templates/header.php';
?>
<h1>هزینه‌های عمومی کلینیک</h1>
<?php echo flash_render('v2_expense_ok','success'); ?>
<?php if ($error !== '') { ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php } ?>
<div class="alert alert-info">هزینه‌ها را به تومان ثبت کنید. اصلاح و ابطال، سابقه را پاک نمی‌کند. ابطال فقط برای ثبت اشتباه است و به معنی دریافت وجه بازگشتی نیست. توضیحات درمانی یا محرمانه وارد نکنید.</div>
<div class="card"><div class="card-header">ثبت هزینه</div><div class="card-body"><form method="post" action="finance_expenses_v2.php" class="form-grid">
<?php echo csrf_field(); ?><input type="hidden" name="action" value="CREATE"><input type="hidden" name="operation_key" value="<?php echo e(bin2hex(random_bytes(16))); ?>">
<div class="form-row"><label>تاریخ</label><?php echo finance_jalali_date3_html('date',$selected_date); ?></div>
<div class="form-row"><label>دسته</label><input name="category" maxlength="100" required></div>
<div class="form-row"><label>مبلغ به تومان (حداکثر یک رقم اعشار)</label><input name="amount_toman" inputmode="decimal" dir="ltr" required></div>
<div class="form-row"><label>حساب پرداخت</label><select name="account_id" required><option value="">انتخاب کنید</option><?php foreach ($accounts as $account) { ?><option value="<?php echo (int)$account['id']; ?>"><?php echo e($account['label']); ?></option><?php } ?></select></div>
<div class="form-row"><label>توضیح اختیاری</label><input name="description" maxlength="255"></div><div class="form-actions"><button class="btn btn-primary">ثبت هزینه</button></div>
</form></div></div>
<?php if ($view) { ?>
<div class="card"><div class="card-header">سابقهٔ هزینهٔ شمارهٔ <?php echo (int)$viewId; ?></div><div class="card-body">
<p>ثبت اولیه: <?php echo e(jalali_value($original['expense_date'])); ?>، <?php echo e($original['category']); ?>، <?php echo e(joma_v2_rial_to_toman_string((int)$original['amount_rial'])); ?> تومان، حساب <?php echo e($original['account_label']); ?>؛ ثبت‌کننده شمارهٔ <?php echo (int)$original['recorded_by_person_id']; ?>، زمان ثبت <?php echo e($original['recorded_at']); ?>، توضیح: <?php echo e((string)$original['description']); ?>.</p>
<ul><?php foreach ($history as $revision) { ?><li><?php echo $revision['action'] === 'VOID' ? 'ابطال ثبت اشتباه' : 'اصلاح'; ?> — دلیل: <?php echo e($revision['reason']); ?>؛ ثبت‌کننده شمارهٔ <?php echo (int)$revision['recorded_by_person_id']; ?>، زمان ثبت <?php echo e($revision['recorded_at']); ?><?php if ($revision['action'] === 'CORRECT') { ?>؛ <?php echo e(jalali_value($revision['expense_date'])); ?>، <?php echo e($revision['category']); ?>، <?php echo e(joma_v2_rial_to_toman_string((int)$revision['amount_rial'])); ?> تومان، حساب <?php echo e($revision['account_label']); ?>، توضیح: <?php echo e((string)$revision['description']); ?><?php } ?></li><?php } ?></ul>
<?php if ($view['action'] !== 'VOID') { ?>
<form method="post" action="finance_expenses_v2.php" class="form-grid">
<?php echo csrf_field(); ?><input type="hidden" name="action" value="CORRECT"><input type="hidden" name="expense_id" value="<?php echo (int)$viewId; ?>"><input type="hidden" name="expected_revision_id" value="<?php echo (int)$view['revision_id']; ?>"><input type="hidden" name="operation_key" value="<?php echo e(bin2hex(random_bytes(16))); ?>">
<div class="form-row"><label>تاریخ اصلاح‌شده</label><?php echo finance_jalali_date3_html('date',jalali_value($view['expense_date'])); ?></div>
<div class="form-row"><label>دسته</label><input name="category" maxlength="100" value="<?php echo e($view['category']); ?>" required></div>
<div class="form-row"><label>مبلغ به تومان</label><input name="amount_toman" inputmode="decimal" dir="ltr" value="<?php echo e(joma_v2_rial_to_toman_string((int)$view['amount_rial'])); ?>" required></div>
<div class="form-row"><label>حساب پرداخت</label><select name="account_id" required><?php if ($view['account_status'] !== 'ACTIVE') { ?><option value="<?php echo (int)$view['account_id']; ?>" selected><?php echo e($view['account_label']); ?> (غیرفعال، حساب فعلی)</option><?php } ?><?php foreach ($accounts as $account) { ?><option value="<?php echo (int)$account['id']; ?>" <?php if ((int)$account['id'] === (int)$view['account_id']) echo 'selected'; ?>><?php echo e($account['label']); ?></option><?php } ?></select></div>
<div class="form-row"><label>توضیح</label><input name="description" maxlength="255" value="<?php echo e((string)$view['description']); ?>"></div><div class="form-row"><label>دلیل اصلاح</label><input name="reason" maxlength="255" required></div><div class="form-actions"><button class="btn btn-primary">ثبت اصلاح</button></div>
</form>
<form method="post" action="finance_expenses_v2.php" class="form-grid"><?php echo csrf_field(); ?><input type="hidden" name="action" value="VOID"><input type="hidden" name="expense_id" value="<?php echo (int)$viewId; ?>"><input type="hidden" name="expected_revision_id" value="<?php echo (int)$view['revision_id']; ?>"><input type="hidden" name="operation_key" value="<?php echo e(bin2hex(random_bytes(16))); ?>"><div class="form-row"><label>دلیل ابطال ثبت اشتباه</label><input name="reason" maxlength="255" required></div><div class="form-actions"><button class="btn btn-danger" type="submit">ابطال ثبت اشتباه</button></div></form>
<?php } else { ?><p>این ثبت ابطال شده است.</p><?php } ?></div></div>
<?php } ?>
<div class="card"><div class="card-header">آخرین هزینه‌های ثبت‌شده</div><div class="card-body"><div class="table-wrap"><table class="table table-card"><thead><tr><th>تاریخ</th><th>دسته</th><th>مبلغ</th><th>حساب</th><th>وضعیت و سابقه</th></tr></thead><tbody>
<?php foreach ($expenses as $row) { ?><tr><td><?php echo e(jalali_value($row['expense_date'])); ?></td><td><?php echo e($row['category']); ?></td><td><?php echo e(joma_v2_rial_to_toman_string((int)$row['amount_rial'])); ?> تومان</td><td><?php echo e($row['account_label']); ?></td><td><?php echo $row['action'] === 'VOID' ? 'باطل‌شده' : 'معتبر'; ?> — <a href="?view=<?php echo (int)$row['id']; ?>">مشاهدهٔ سابقه و اصلاح</a></td></tr><?php } ?>
</tbody></table></div></div></div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
