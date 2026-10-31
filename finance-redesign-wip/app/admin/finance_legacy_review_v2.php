<?php
/* DRAFT ONLY: not installed. Center choices require independent evidence. */
require_once __DIR__.'/../includes/bootstrap.php';
require_once __DIR__.'/../includes/finance_v2/money.php';
require_once __DIR__.'/../includes/finance_v2/legacy_center_review.php';
require_once __DIR__.'/../includes/finance_v2/storage/mysqli_legacy_center_review_store.php';
$db=$GLOBALS['db']; auth_require_active_session($db); require_role(array(ROLE_ADMIN));
if (!defined('FINANCE_V2_ACTIVE') || FINANCE_V2_ACTIVE!==true) { http_response_code(404); exit; }
function legacy_review_id($v,$zero=false) {
    if (!is_string($v) || !preg_match($zero?'/^(0|[1-9][0-9]{0,17})$/D':'/^[1-9][0-9]{0,17}$/D',$v)) throw new InvalidArgumentException('Invalid identifier');
    return (int)$v;
}
function legacy_center_choice($value) {
    if ($value==='pending') return array(null,false);
    if ($value==='none') return array(null,true);
    return array(legacy_review_id($value),true);
}
$error=''; $viewId=null; $before=null;
try { if (isset($_GET['view'])) $viewId=legacy_review_id($_GET['view']); if (isset($_GET['before'])) $before=legacy_review_id($_GET['before']); } catch (InvalidArgumentException $ex) { $error='شناسهٔ پرداخت نامعتبر است.'; }
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    try {
        $viewId=legacy_review_id(isset($_POST['payment_id'])?$_POST['payment_id']:null);
        $d=legacy_center_choice(isset($_POST['deposit_center'])?$_POST['deposit_center']:null);
        $b=legacy_center_choice(isset($_POST['balance_center'])?$_POST['balance_center']:null);
        $input=array('payment_id'=>$viewId,'deposit_center_id'=>$d[0],'deposit_center_confirmed'=>$d[1],
            'balance_center_id'=>$b[0],'balance_center_confirmed'=>$b[1],
            'fingerprint'=>isset($_POST['fingerprint'])?$_POST['fingerprint']:null,
            'expected_review_id'=>legacy_review_id(isset($_POST['expected_review_id'])?$_POST['expected_review_id']:null,true),
            'reason'=>isset($_POST['reason'])?$_POST['reason']:null);
        joma_v2_review_legacy_center(new JomaV2MysqliLegacyCenterReviewStore($db),$input,
            array('person_id'=>(int)$_SESSION['person_id'],'role'=>$_SESSION['active_role_code']));
        flash_set('v2_legacy_review_ok','بررسی با حفظ سابقه ثبت شد.'); redirect(APP_BASE_URL.'/admin/finance_legacy_review_v2.php?view='.(int)$viewId);
    } catch (InvalidArgumentException $ex) {
        $error='ثبت انجام نشد. اطلاعات پرداخت ممکن است تغییر کرده باشد یا با دفتر جدید هم‌پوشانی داشته باشد؛ دوباره بررسی کنید.';
    } catch (Throwable $ex) {
        $ref=log_system_error('FINANCE_V2_LEGACY_REVIEW',$ex); $error='ثبت انجام نشد. کد پیگیری: '.$ref;
    }
}
try {
    $store=new JomaV2MysqliLegacyCenterReviewStore($db);
    $rows=$store->listPayments($before); $hasMore=count($rows)>100; if ($hasMore) array_pop($rows); $centers=$store->centers();
    $view=$viewId ? $store->findPayment($viewId) : null; $history=$view ? $store->history($viewId) : array();
} catch (Throwable $ex) { $ref=log_system_error('FINANCE_V2_LEGACY_LIST',$ex); render_error_page($ref); }
function legacy_status($row) {
    if ((int)$row['has_new_history']) return 'هم‌پوشانی با دفتر جدید؛ بررسی جداگانه لازم است';
    if (!(int)$row['review_id']) return 'بررسی نشده';
    return joma_v2_legacy_review_is_current($row,array('payment_fingerprint'=>$row['payment_fingerprint']),false) ? 'تأیید ثبت شده' : 'پرداخت تغییر کرده؛ بررسی دوباره لازم است';
}
function legacy_money($v) { return $v===null ? 'ثبت نشده' : joma_v2_rial_to_toman_string((int)$v).' تومان'; }
function legacy_center_select($name,$status,$centers) {
    if ($status!=='PAID') { echo '<input type="hidden" name="'.e($name).'" value="pending">پرداخت نشده؛ فعلاً مرکز تعیین نمی‌شود.'; return; }
    ?><select name="<?php echo e($name); ?>" required><option value="">ابتدا انتخاب کنید</option><option value="none">با بررسی مدارک، بدون مرکز</option><?php foreach ($centers as $c) { ?><option value="<?php echo (int)$c['id']; ?>"><?php echo e($c['name']); ?><?php echo $c['status']==='INACTIVE'?' (غیرفعال)':''; ?></option><?php } ?></select><?php
}
$page_title='بررسی مرکز پرداخت‌های قبلی'; $active_menu='finance_legacy_review_v2'; require __DIR__.'/../templates/header.php';
?>
<h1>بررسی مرکز پرداخت‌های قبلی</h1>
<?php echo flash_render('v2_legacy_review_ok','success'); ?><?php if ($error!=='') { ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php } ?>
<div class="alert alert-warning">مرکز زمان پرداخت را از مدارک بررسی کنید؛ مرکز فعلی اتاق الزاماً مرکز آن زمان نیست. بیعانه و الباقی را جدا بررسی کنید. اگر مدارک کافی نیست، تأیید نکنید. این صفحه پرداخت قدیمی را تغییر نمی‌دهد و به‌تنهایی تسویه را فعال نمی‌کند.</div>
<?php if ($view) { ?><div class="card"><div class="card-header">پرداخت <?php echo (int)$viewId; ?> — نوبت <?php echo e($view['appointment_public']); ?></div><div class="card-body">
<p>وضعیت بررسی: <?php echo e(legacy_status($view)); ?></p>
<p>بیعانه: <?php echo $view['deposit_status']==='PAID'?'پرداخت‌شده':'در انتظار پرداخت'; ?>، <?php echo e(legacy_money($view['deposit_amount_rial'])); ?>، تاریخ <?php echo e($view['deposit_paid_at']===null?'ثبت نشده':jalali_display($view['deposit_paid_at'],'datetime')); ?>، حساب شمارهٔ <?php echo $view['deposit_bank_account_id']===null?'نامشخص':(int)$view['deposit_bank_account_id']; ?>.</p>
<p>الباقی: <?php echo $view['balance_status']==='PAID'?'پرداخت‌شده':'در انتظار پرداخت'; ?>، <?php echo e(legacy_money($view['balance_amount_rial'])); ?>، تاریخ <?php echo e($view['balance_paid_at']===null?'ثبت نشده':jalali_display($view['balance_paid_at'],'datetime')); ?>، حساب شمارهٔ <?php echo $view['balance_bank_account_id']===null?'نامشخص':(int)$view['balance_bank_account_id']; ?>.</p>
<?php if (!(int)$view['has_new_history']) { ?><form method="post" class="form-grid"><?php echo csrf_field(); ?><input type="hidden" name="payment_id" value="<?php echo (int)$viewId; ?>"><input type="hidden" name="fingerprint" value="<?php echo e(joma_v2_legacy_payment_fingerprint($view)); ?>"><input type="hidden" name="expected_review_id" value="<?php echo (int)$view['review_id']; ?>">
<div class="form-row"><label>مرکز بیعانه در زمان پرداخت</label><?php legacy_center_select('deposit_center',$view['deposit_status'],$centers); ?></div>
<div class="form-row"><label>مرکز الباقی در زمان پرداخت</label><?php legacy_center_select('balance_center',$view['balance_status'],$centers); ?></div>
<div class="form-row"><label>دلیل و مستند بررسی</label><input name="reason" maxlength="255" required></div><div class="form-actions"><button class="btn btn-primary">ثبت تأیید</button></div></form><?php } ?>
<h2>سابقهٔ بررسی‌ها</h2><ul><?php foreach ($history as $h) { ?><li>زمان ثبت <?php echo e($h['recorded_at']); ?>، ثبت‌کننده شمارهٔ <?php echo (int)$h['recorded_by_person_id']; ?>، دلیل <?php echo e($h['reason']); ?>؛ مرکز بیعانه: <?php echo e($h['deposit_center_name']===null?'بدون مرکز یا پرداخت‌نشده':$h['deposit_center_name']); ?>، مرکز الباقی: <?php echo e($h['balance_center_name']===null?'بدون مرکز یا پرداخت‌نشده':$h['balance_center_name']); ?>. <?php $snapshot=json_decode($h['payment_snapshot_json'],true); if (is_array($snapshot)) { ?>بیعانهٔ آن زمان: <?php echo e(legacy_money($snapshot['deposit_amount_rial'])); ?> (<?php echo $snapshot['deposit_status']==='PAID'?'پرداخت‌شده':'در انتظار پرداخت'; ?>)، الباقی آن زمان: <?php echo e(legacy_money($snapshot['balance_amount_rial'])); ?> (<?php echo $snapshot['balance_status']==='PAID'?'پرداخت‌شده':'در انتظار پرداخت'; ?>).<?php } else { ?>تصویر پرداخت نامعتبر است؛ نیاز به بررسی دارد.<?php } ?></li><?php } ?></ul>
</div></div><?php } ?>
<div class="card"><div class="card-header">آخرین پرداخت‌های قدیمی</div><div class="card-body"><div class="table-wrap"><table class="table table-card"><thead><tr><th>نوبت</th><th>بیعانه</th><th>الباقی</th><th>وضعیت</th><th>بررسی</th></tr></thead><tbody>
<?php foreach ($rows as $row) { ?><tr><td><?php echo e($row['appointment_public']); ?></td><td><?php echo e(legacy_money($row['deposit_amount_rial'])); ?></td><td><?php echo e(legacy_money($row['balance_amount_rial'])); ?></td><td><?php echo e(legacy_status($row)); ?></td><td><a href="?view=<?php echo (int)$row['id']; ?>">مشاهده و بررسی</a></td></tr><?php } ?>
</tbody></table></div><?php if ($hasMore) { ?><p><a href="?before=<?php echo (int)$rows[count($rows)-1]['id']; ?>">پرداخت‌های قدیمی‌تر</a></p><?php } ?><?php if ($before!==null) { ?><p><a href="?">بازگشت به جدیدترین پرداخت‌ها</a></p><?php } ?></div></div><?php require __DIR__.'/../templates/footer.php'; ?>
