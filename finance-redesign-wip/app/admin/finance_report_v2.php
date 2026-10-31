<?php
/* DRAFT ONLY. Requires integration, migration and MySQL/browser tests. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/finance_v2/money.php';
require_once __DIR__ . '/../includes/finance_v2/storage/mysqli_report_store.php';
$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));
$default = finance_default_period_jalali('MONTHLY');
$from_j = $default['start_j']; $to_j = $default['end_j'];
$warning = '';
if (isset($_GET['from_y'])) {
    $from_j = finance_jalali_date3_compose(isset($_GET['from_y']) ? $_GET['from_y'] : '', isset($_GET['from_m']) ? $_GET['from_m'] : '', isset($_GET['from_d']) ? $_GET['from_d'] : '');
    $to_j = finance_jalali_date3_compose(isset($_GET['to_y']) ? $_GET['to_y'] : '', isset($_GET['to_m']) ? $_GET['to_m'] : '', isset($_GET['to_d']) ? $_GET['to_d'] : '');
}
$from_g = jalali_input_to_gregorian($from_j); $to_g = jalali_input_to_gregorian($to_j);
if ($from_g === null || $to_g === null || $to_g < $from_g) {
    $warning = 'تاریخ نامعتبر است؛ بازهٔ پیش‌فرض نمایش داده می‌شود.';
    $from_j = $default['start_j']; $to_j = $default['end_j'];
    $from_g = jalali_input_to_gregorian($from_j); $to_g = jalali_input_to_gregorian($to_j);
}
$end_local = date_add_days($to_g, 1);
$begin = local_day_bounds_utc($from_g); $end = local_day_bounds_utc($end_local);
try {
    $report = (new JomaV2MysqliReportStore($db))->build($begin['start'], $end['start'], $from_g, $end_local);
} catch (Throwable $ex) {
    $ref = log_system_error('FINANCE_V2_REPORT', $ex);
    render_error_page($ref);
}
function report_v2_money($value) { return to_persian_digits(joma_v2_rial_to_toman_signed($value)) . ' تومان'; }
$page_title = 'گزارش مالی جدید'; $active_menu = 'finance_report_v2';
require __DIR__ . '/../templates/header.php';
?>
<h1>گزارش مالی جدید</h1>
<div class="alert alert-warning">این گزارش فقط دفتر مالی جدید را نشان می‌دهد؛ پرداخت‌های قدیمی، فروش تست و بسته و تسویهٔ مراکز در آن نیستند. انتقال داخلی اعتبار، دریافت تازه حساب نمی‌شود.</div>
<?php if ($warning !== '') { ?><div class="alert alert-warning"><?php echo e($warning); ?></div><?php } ?>
<div class="card"><div class="card-header">بازهٔ گزارش</div><div class="card-body">
<form method="get" action="finance_report_v2.php" class="form-grid">
<div class="form-row"><label>از تاریخ</label><?php echo finance_jalali_date3_html('from', $from_j); ?></div>
<div class="form-row"><label>تا تاریخ</label><?php echo finance_jalali_date3_html('to', $to_j); ?></div>
<div class="form-actions"><button class="btn btn-primary" type="submit">نمایش</button></div></form></div></div>
<div class="card"><div class="card-header">جریان بازه</div><div class="card-body"><div class="item-list">
<div class="item-card">دریافت‌ها: <strong><?php echo e(report_v2_money($report['cash_received_rial'])); ?></strong></div>
<div class="item-card">استردادها: <strong><?php echo e(report_v2_money($report['cash_refunded_rial'])); ?></strong></div>
<div class="item-card">دریافت خالص پیش از هزینه‌ها: <strong><?php echo e(report_v2_money($report['net_cash_rial'])); ?></strong></div>
<div class="item-card">تغییر خالص صورتحساب‌های ثبت یا اصلاح‌شده در بازه: <strong><?php echo e(report_v2_money($report['session_charge_changes_rial'])); ?></strong></div>
<div class="item-card">هزینه‌های عمومی بر اساس تاریخ هزینه: <strong><?php echo e(report_v2_money($report['expenses_rial'])); ?></strong></div>
</div></div></div>
<div class="card"><div class="card-header">جلسات و مانده‌ها تا پایان بازه</div><div class="card-body"><div class="item-list">
<div class="item-card">مبلغ صورتحساب‌های ثبت‌شده تا پایان بازه برای جلسات با <strong>تاریخ برنامه‌ریزی‌شده</strong> در بازه: <strong><?php echo e(report_v2_money($report['scheduled_session_charges_rial'])); ?></strong></div>
<div class="item-card">اعتبار درخواست‌ها: <strong><?php echo e(report_v2_money($report['request_credit_rial'])); ?></strong></div>
<div class="item-card">اعتبار نوبت‌ها: <strong><?php echo e(report_v2_money($report['appointment_credit_rial'])); ?></strong></div>
<div class="item-card">بدهی نوبت‌های صورتحساب‌شده: <strong><?php echo e(report_v2_money($report['appointment_due_rial'])); ?></strong></div>
</div><p>تاریخ برنامه‌ریزی‌شده لزوماً تاریخ واقعی برگزاری نیست. صورتحساب‌های ثبت‌شده بعد از پایان بازه در مبلغ فوق لحاظ نمی‌شوند؛ هزینه‌های عمومی بر اساس تاریخ واردشده و آخرین اصلاح‌های موجود گزارش می‌شوند؛ حتی اگر اصلاح بعد از پایان بازه ثبت شده باشد. هزینه‌های ابطال‌شده در جمع نیستند.</p>
<?php if ($report['undated_invoiced_count'] > 0) { ?><p class="alert alert-warning">تعداد <?php echo e(to_persian_digits($report['undated_invoiced_count'])); ?> صورتحساب فاقد تاریخ نوبت در جمع جلسات قرار نگرفته است.</p><?php } ?>
</div></div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
