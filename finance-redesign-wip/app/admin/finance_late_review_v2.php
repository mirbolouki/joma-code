<?php
/* DRAFT ONLY: a note does not change money or release the settlement block. */
require_once __DIR__.'/../includes/bootstrap.php';
$db=$GLOBALS['db']; auth_require_active_session($db); require_role(array(ROLE_ADMIN));
if (!defined('FINANCE_V2_ACTIVE') || FINANCE_V2_ACTIVE!==true || !defined('FINANCE_V2_PERCENTAGE_EXPERIMENTAL') || FINANCE_V2_PERCENTAGE_EXPERIMENTAL!==true) { http_response_code(404); exit; }
require_once __DIR__.'/../includes/finance_v2/money.php';
require_once __DIR__.'/../includes/finance_v2/percentage_late_review.php';
require_once __DIR__.'/../includes/finance_v2/storage/mysqli_percentage_late_review_store.php';
function late_review_id($value,$zero=false) {
    if (!is_string($value) || !preg_match($zero?'/^(0|[1-9][0-9]{0,17})$/D':'/^[1-9][0-9]{0,17}$/D',$value)) throw new InvalidArgumentException('شناسه نامعتبر است.');
    return (int)$value;
}
function late_review_kind_label($kind) {
    if ($kind==='INVESTIGATING') return 'در حال بررسی';
    if ($kind==='AGREEMENT_NOTED') return 'توافق یادداشت شده، اصلاح مالی در انتظار';
    return 'نوع نامعتبر؛ بررسی لازم است';
}
$error=''; $id=null;
try { if (isset($_GET['id'])) $id=late_review_id($_GET['id']); } catch (InvalidArgumentException $ex) { $error=$ex->getMessage(); }
if ($_SERVER['REQUEST_METHOD']==='POST') {
    csrf_check();
    try {
        $id=late_review_id(isset($_POST['id'])?$_POST['id']:null);
        $input=array('late_event_id'=>$id,'expected_review_id'=>late_review_id(isset($_POST['expected_review_id'])?$_POST['expected_review_id']:null,true),
            'kind'=>isset($_POST['kind'])?$_POST['kind']:null,'reason'=>isset($_POST['reason'])?$_POST['reason']:null);
        joma_v2_review_percentage_late(new JomaV2MysqliPercentageLateReviewStore($db),$input,
            array('person_id'=>(int)$_SESSION['person_id'],'role'=>$_SESSION['active_role_code']));
        flash_set('v2_late_review_ok','یادداشت بررسی ثبت شد؛ مبلغ تسویه تغییر نکرده و مسدودیت همچنان برقرار است.');
        redirect(APP_BASE_URL.'/admin/finance_late_review_v2.php?id='.$id);
    } catch (InvalidArgumentException $ex) { $error=$ex->getMessage(); }
      catch (Throwable $ex) { $ref=log_system_error('FINANCE_V2_LATE_REVIEW',$ex); $error='ثبت انجام نشد. کد پیگیری: '.$ref; }
}
try {
    $store=new JomaV2MysqliPercentageLateReviewStore($db);
    $view=$id ? $store->findLateEvent($id) : null;
    $history=$view ? $store->history($id) : array();
    $version=$history ? (int)$history[count($history)-1]['id'] : 0;
} catch (Throwable $ex) { $ref=log_system_error('FINANCE_V2_LATE_REVIEW_LIST',$ex); render_error_page($ref); }
$page_title='بررسی گردش بعد از تسویه'; $active_menu='finance_settlements'; require __DIR__.'/../templates/header.php';
?>
<h1>بررسی گردش بعد از تسویه</h1>
<?php echo flash_render('v2_late_review_ok','success'); ?>
<?php if ($error!=='') { ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php } ?>
<div class="alert alert-warning">این صفحه آزمایشی است. یادداشت یا ثبت توافق فقط سابقهٔ بررسی است؛ هیچ مبلغی را اصلاح یا پرداخت نمی‌کند، بدهی یا بستانکاری را تعیین نمی‌کند و مسدودیت تسویهٔ بعدی را برنمی‌دارد.</div>
<p><a href="finance_settlements.php">بازگشت به تسویه‌ها</a></p>
<?php if (!$view) { ?><p>گردش انتخاب‌شده یافت نشد.</p><?php } else { ?>
<div class="card"><div class="card-header">مرکز <?php echo e($view['center_name']); ?>، تسویه <?php echo e($view['public_id']); ?></div><div class="card-body">
<p>بازه: <?php echo e($view['period_start']); ?> تا <?php echo e($view['period_end']); ?>؛ گردش شمارهٔ <?php echo (int)$view['event_id']; ?> (<?php echo e($view['event_type']); ?>)، مبلغ <?php echo e(joma_v2_rial_to_toman_string((int)$view['amount_rial'])); ?> تومان، زمان <?php echo e(jalali_display($view['occurred_at'],'datetime')); ?>.</p>
<form method="post" class="form-grid"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$id; ?>"><input type="hidden" name="expected_review_id" value="<?php echo $version; ?>">
<div class="form-row"><label>مرحلهٔ بررسی</label><select name="kind" required><option value="">انتخاب کنید</option><option value="INVESTIGATING">در حال بررسی</option><option value="AGREEMENT_NOTED">توافق یادداشت شده، اصلاح مالی در انتظار</option></select></div>
<div class="form-row"><label>شرح و دلیل بررسی یا توافق</label><textarea name="reason" maxlength="1000" required></textarea><div class="form-hint">از ثبت اطلاعات درمانی یا محرمانه خودداری کنید.</div></div>
<div class="form-actions"><button class="btn btn-primary">ثبت یادداشت بررسی</button></div></form>
<h2>سابقهٔ بررسی‌ها</h2><?php if (!$history) { ?><p>هنوز یادداشتی ثبت نشده است.</p><?php } else { ?><ul><?php foreach ($history as $row) { ?><li><?php echo e(late_review_kind_label($row['review_kind'])); ?>؛ ثبت‌کننده شمارهٔ <?php echo (int)$row['recorded_by_person_id']; ?>؛ زمان <?php echo e(jalali_display($row['recorded_at'],'datetime')); ?>؛ شرح: <?php echo e($row['reason']); ?></li><?php } ?></ul><?php } ?>
</div></div><?php } ?>
<?php require __DIR__.'/../templates/footer.php'; ?>
