<?php
/* DRAFT ONLY: does not update old settlement calculations. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/finance_v2/center_basis.php';
require_once __DIR__ . '/../includes/finance_v2/storage/mysqli_center_basis_store.php';
$db=$GLOBALS['db']; auth_require_active_session($db); require_role(array(ROLE_ADMIN));
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    try {
        $raw=isset($_POST['center_id']) && is_string($_POST['center_id']) ? $_POST['center_id'] : '';
        if (!preg_match('/^[1-9][0-9]{0,17}$/D',$raw)) throw new InvalidArgumentException('Invalid center');
        $basis=isset($_POST['basis']) && is_string($_POST['basis']) ? $_POST['basis'] : '';
        joma_v2_set_center_basis(new JomaV2MysqliCenterBasisStore($db),(int)$raw,$basis,
            array('person_id'=>(int)$_SESSION['person_id'],'role'=>$_SESSION['active_role_code']));
        flash_set('v2_basis_ok','مبنای زمان مرکز ذخیره شد.'); redirect(APP_BASE_URL . '/admin/finance_center_basis_v2.php');
    } catch (InvalidArgumentException $ex) { $error='مرکز فعال و مبنای معتبر انتخاب کنید.'; }
      catch (Throwable $ex) { $ref=log_system_error('FINANCE_V2_CENTER_BASIS',$ex); $error='ذخیره انجام نشد. کد پیگیری: '.$ref; }
}
try { $centers=db_select_all($db,"SELECT c.id,c.name,c.commission_model,b.time_basis FROM finance_centers c LEFT JOIN finance_v2_center_basis b ON b.center_id=c.id WHERE c.status='ACTIVE' ORDER BY c.name"); }
catch (Throwable $ex) { $ref=log_system_error('FINANCE_V2_CENTERS_LIST',$ex); render_error_page($ref); }
$page_title='مبنای زمان مراکز'; $active_menu='finance_center_basis_v2'; require __DIR__ . '/../templates/header.php';
?>
<h1>مبنای زمان مراکز</h1><?php echo flash_render('v2_basis_ok','success'); ?>
<?php if ($error !== '') { ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php } ?>
<div class="alert alert-warning">این تنظیم برای محاسبهٔ هزینهٔ ساعتی است؛ روش محاسبهٔ مراکز درصدی جداگانه بررسی می‌شود. زمان واقعیِ ثبت‌نشده حدس زده نمی‌شود.</div>
<div class="card"><div class="card-header">تعیین مبنا</div><div class="card-body"><form method="post" class="form-grid">
<?php echo csrf_field(); ?><div class="form-row"><label>مرکز</label><select name="center_id" required><option value="">انتخاب کنید</option><?php foreach ($centers as $c) { ?><option value="<?php echo (int)$c['id']; ?>"><?php echo e($c['name']); ?></option><?php } ?></select></div>
<div class="form-row"><label>مبنای زمان</label><select name="basis" required><option value="">انتخاب کنید</option><option value="ACTUAL">مدت واقعی جلسه</option><option value="RESERVED">مدت رزروشده</option></select></div>
<div class="form-actions"><button class="btn btn-primary">ذخیره</button></div></form></div></div>
<div class="card"><div class="card-header">تنظیم‌های فعلی</div><div class="card-body"><table class="table table-card"><thead><tr><th>مرکز</th><th>نوع قرارداد</th><th>مبنای زمان</th></tr></thead><tbody>
<?php foreach ($centers as $c) { ?><tr><td><?php echo e($c['name']); ?></td><td><?php echo $c['commission_model']==='HOURLY' ? 'ساعتی' : 'درصدی'; ?></td><td><?php echo $c['time_basis']==='ACTUAL' ? 'واقعی' : ($c['time_basis']==='RESERVED' ? 'رزروشده' : 'تعیین نشده'); ?></td></tr><?php } ?>
</tbody></table></div></div><?php require __DIR__ . '/../templates/footer.php'; ?>
