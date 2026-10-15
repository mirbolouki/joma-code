<?php
/* ═══════════════════════════════════════════════════════════════════
 *  جوما فاز ۵ — گزارشِ مالی (فقط مدیر)
 *
 *  خلاصهٔ درآمد (جلسات/تست/پکیج)، بدهیِ مراکز، خالصِ بازه.
 *  فقط خواندنی؛ تاریخِ گزارش با انتخابگرِ سه‌بخشیِ سال/ماه/روز
 *  انتخاب می‌شود (بدون تایپ). پیش‌فرض: ماهِ شمسیِ جاری.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

$all_ready = phase5_5_ready($db);

/* بازهٔ پیش‌فرض: ماهِ شمسیِ جاری */
$def = finance_default_period_jalali('MONTHLY');
$from_j = $def['start_j'];
$to_j = $def['end_j'];
$warning = '';

if ($all_ready && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['from_y'])) {
    $from_j = finance_jalali_date3_compose(
        isset($_GET['from_y']) ? $_GET['from_y'] : '',
        isset($_GET['from_m']) ? $_GET['from_m'] : '',
        isset($_GET['from_d']) ? $_GET['from_d'] : '');
    $to_j = finance_jalali_date3_compose(
        isset($_GET['to_y']) ? $_GET['to_y'] : '',
        isset($_GET['to_m']) ? $_GET['to_m'] : '',
        isset($_GET['to_d']) ? $_GET['to_d'] : '');
}

$from_g = jalali_input_to_gregorian($from_j);
$to_g = jalali_input_to_gregorian($to_j);
if ($from_g === null || $to_g === null || $to_g < $from_g) {
    $warning = 'بازهٔ انتخاب‌شده نامعتبر است؛ بازهٔ پیش‌فرض (ماهِ جاری) نمایش داده می‌شود.';
    $def = finance_default_period_jalali('MONTHLY');
    $from_j = $def['start_j'];
    $to_j = $def['end_j'];
    $from_g = jalali_input_to_gregorian($from_j);
    $to_g = jalali_input_to_gregorian($to_j);
}

$income = null;
$settlements = null;
if ($all_ready) {
    /* کرانه‌های UTCِ بازه (بر اساس روزهای محلیِ کلینیک) */
    $b1 = local_day_bounds_utc($from_g);
    $b2 = local_day_bounds_utc(date_add_days($to_g, 1));
    $from_utc = $b1['start'];
    $to_utc = $b2['start'];
    try {
        $income = finance_report_income($db, $from_utc, $to_utc);
        $settlements = finance_report_settlements($db, $from_g, $to_g);
    } catch (Exception $ex) {
        $ref = log_system_error('FINANCE_REPORT', $ex);
        render_error_page($ref);
    }
}

$net_rial = $all_ready ? ($income['total_paid_rial'] - $settlements['total_due_rial']) : 0;

$page_title = 'گزارشِ مالی';
$active_menu = 'finance_report';
require __DIR__ . '/../templates/header.php';
?>
<h1>📊 گزارشِ مالی</h1>

<?php if (!$all_ready) { ?>
  <div class="alert alert-warning">
    برای دیدنِ این گزارش، ابتدا مراحل ۱ تا ۴ (زیرساخت، پرداختِ جلسات،
    تسویه با مراکز، فروشِ تست و پکیج) را نصب کنید.
  </div>
<?php } else { ?>

<div class="card">
  <div class="card-header">بازهٔ گزارش</div>
  <div class="card-body">
    <form method="get" action="finance_report.php" class="form-grid">
      <div class="form-row">
        <label>از تاریخ</label>
        <?php echo finance_jalali_date3_html('from', $from_j); ?>
      </div>
      <div class="form-row">
        <label>تا تاریخ</label>
        <?php echo finance_jalali_date3_html('to', $to_j); ?>
        <div class="form-hint">سال / ماه / روز — انتخاب از فهرست، بدون تایپ</div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">اعمال فیلتر</button>
      </div>
    </form>
  </div>
</div>

<?php if ($warning !== '') { ?>
  <div class="alert alert-warning"><?php echo e($warning); ?></div>
<?php } ?>

<div class="card">
  <div class="card-header">
    خلاصهٔ بازه: <?php echo to_persian_digits(jalali_value($from_g)); ?>
    تا <?php echo to_persian_digits(jalali_value($to_g)); ?>
  </div>
  <div class="card-body">
    کلِ درآمدِ بازه: <strong><?php echo tariff_rial_to_toman_display($income['total_paid_rial']); ?></strong>
    &nbsp;|&nbsp;
    بدهیِ مراکز (قابل‌پرداخت): <strong><?php echo tariff_rial_to_toman_display($settlements['total_due_rial']); ?></strong>
    &nbsp;|&nbsp;
    پرداخت‌شده به مراکز: <strong><?php echo tariff_rial_to_toman_display($settlements['total_paid_rial']); ?></strong>
    &nbsp;|&nbsp;
    <strong>خالصِ بازه: <?php echo tariff_rial_to_toman_display($net_rial); ?></strong>
  </div>
</div>

<div class="card">
  <div class="card-header">درآمد بر اساسِ منبع</div>
  <div class="card-body">
    <div class="table-wrap">
      <table class="table table-card">
        <thead>
          <tr>
            <th>منبع</th><th>پرداخت‌شده (تعداد)</th><th>مبلغِ وصول‌شده</th>
            <th>در انتظار (تعداد)</th><th>مبلغِ در انتظار</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $sources = array(
              array('💳 پرداختِ جلسات', $income['sessions']),
              array('🧪 فروشِ تست‌ها', $income['tests']),
              array('📦 فروشِ پکیج‌ها', $income['packages']),
          );
          $sum_cnt = 0; $sum_paid = 0; $sum_pcnt = 0; $sum_pend = 0;
          foreach ($sources as $src) {
              $sum_cnt += $src[1]['cnt'];
              $sum_paid += $src[1]['paid_rial'];
              $sum_pcnt += $src[1]['pending_cnt'];
              $sum_pend += $src[1]['pending_rial'];
          ?>
            <tr>
              <td data-label="منبع"><?php echo e($src[0]); ?></td>
              <td data-label="پرداخت‌شده (تعداد)"><?php echo to_persian_digits($src[1]['cnt']); ?></td>
              <td data-label="مبلغِ وصول‌شده"><?php echo tariff_rial_to_toman_display($src[1]['paid_rial']); ?></td>
              <td data-label="در انتظار (تعداد)"><?php echo to_persian_digits($src[1]['pending_cnt']); ?></td>
              <td data-label="مبلغِ در انتظار"><?php echo tariff_rial_to_toman_display($src[1]['pending_rial']); ?></td>
            </tr>
          <?php } ?>
          <tr>
            <td data-label="منبع"><strong>جمع</strong></td>
            <td data-label="پرداخت‌شده (تعداد)"><strong><?php echo to_persian_digits($sum_cnt); ?></strong></td>
            <td data-label="مبلغِ وصول‌شده"><strong><?php echo tariff_rial_to_toman_display($sum_paid); ?></strong></td>
            <td data-label="در انتظار (تعداد)"><strong><?php echo to_persian_digits($sum_pcnt); ?></strong></td>
            <td data-label="مبلغِ در انتظار"><strong><?php echo tariff_rial_to_toman_display($sum_pend); ?></strong></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">تسویه با مراکز در بازه</div>
  <div class="card-body">
    <?php if (count($settlements['rows']) === 0) { ?>
      <p class="empty-state">در این بازه تسویه‌ای ثبت نشده است.</p>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead>
            <tr>
              <th>مرکز</th><th>مدل</th><th>تعداد تسویه</th>
              <th>قابل‌پرداخت</th><th>پرداخت‌شده</th><th>باقی‌مانده</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($settlements['rows'] as $r) { ?>
              <tr>
                <td data-label="مرکز"><?php echo e($r['center_name']); ?></td>
                <td data-label="مدل"><?php echo finance_commission_model_label($r['commission_model']); ?></td>
                <td data-label="تعداد تسویه"><?php echo to_persian_digits($r['cnt']); ?></td>
                <td data-label="قابل‌پرداخت"><?php echo tariff_rial_to_toman_display($r['due_rial']); ?></td>
                <td data-label="پرداخت‌شده"><?php echo tariff_rial_to_toman_display($r['paid_rial']); ?></td>
                <td data-label="باقی‌مانده"><?php echo tariff_rial_to_toman_display($r['remaining_rial']); ?></td>
              </tr>
            <?php } ?>
            <tr>
              <td data-label="مرکز"><strong>جمع</strong></td>
              <td data-label="مدل">—</td>
              <td data-label="تعداد تسویه"><strong><?php echo to_persian_digits(count($settlements['rows'])); ?></strong></td>
              <td data-label="قابل‌پرداخت"><strong><?php echo tariff_rial_to_toman_display($settlements['total_due_rial']); ?></strong></td>
              <td data-label="پرداخت‌شده"><strong><?php echo tariff_rial_to_toman_display($settlements['total_paid_rial']); ?></strong></td>
              <td data-label="باقی‌مانده"><strong><?php echo tariff_rial_to_toman_display($settlements['total_remaining_rial']); ?></strong></td>
            </tr>
          </tbody>
        </table>
      </div>
    <?php } ?>
  </div>
</div>

<div class="alert alert-info">
  <strong>خالصِ بازه</strong> = کلِ درآمدِ وصول‌شده − بدهیِ قابل‌پرداختِ مراکز در بازه.
  «در انتظار» = وضعیتِ فعلی (مستقل از بازهٔ انتخاب‌شده).
  تسویه‌ها: هر تسویه‌ای که بازه‌اش با بازهٔ گزارش هم‌پوشانی داشته باشد، حساب می‌شود.
</div>

<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
