<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — پورتال مراجع، فاز ۳: نمایش فقط‌خواندنی اطلاعات مالی شخصی
 *
 *  همهٔ پرس‌وجوها به شناسهٔ شخصِ نشست محدودند. این صفحه پرداختی ثبت
 *  نمی‌کند و گزارش مالی کلینیک یا تسویه با مراکز را نشان نمی‌دهد.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
$me = patient_require_portal_session($db);
$payments_ready = function_exists('phase5_2_ready') && phase5_2_ready($db);
$sales_ready = function_exists('phase5_4_ready') && phase5_4_ready($db);
$session_payments = array();
$test_sales = array();
$package_sales = array();
$paid_total_rial = 0;
$pending_total_rial = 0;

try {
    if ($payments_ready) {
        $session_payments = db_select_all($db,
            "SELECT fsp.id, fsp.session_fee_rial,
                    fsp.deposit_amount_rial, fsp.deposit_status, fsp.deposit_paid_at,
                    fsp.balance_amount_rial, fsp.balance_status, fsp.balance_paid_at,
                    fsp.created_at,
                    ap.public_id AS appointment_public_id,
                    ap.status AS appointment_status, ap.appointment_start_utc,
                    st.title AS service_title
               FROM finance_session_payments fsp
               INNER JOIN appointments ap ON ap.id = fsp.appointment_id
               INNER JOIN admissions ad ON ad.id = ap.admission_id
               INNER JOIN service_types st ON st.id = ap.service_type_id
              WHERE ad.patient_person_id = ?
              ORDER BY ap.appointment_start_utc DESC, fsp.id DESC
              LIMIT 200",
            'i', array((int)$me));
        $session_totals = db_select_one($db,
            "SELECT COALESCE(SUM(
                       CASE WHEN fsp.deposit_status = 'PAID' THEN COALESCE(fsp.deposit_amount_rial, 0) ELSE 0 END
                     + CASE WHEN fsp.balance_status = 'PAID' THEN COALESCE(fsp.balance_amount_rial, 0) ELSE 0 END
                   ), 0) AS paid_rial,
                    COALESCE(SUM(
                       CASE WHEN fsp.deposit_status = 'PENDING' THEN COALESCE(fsp.deposit_amount_rial, 0) ELSE 0 END
                     + CASE WHEN fsp.balance_status = 'PENDING' THEN COALESCE(fsp.balance_amount_rial, 0) ELSE 0 END
                   ), 0) AS pending_rial
               FROM finance_session_payments fsp
               INNER JOIN appointments ap ON ap.id = fsp.appointment_id
               INNER JOIN admissions ad ON ad.id = ap.admission_id
              WHERE ad.patient_person_id = ?",
            'i', array((int)$me));
        if ($session_totals) {
            $paid_total_rial += (int)$session_totals['paid_rial'];
            $pending_total_rial += (int)$session_totals['pending_rial'];
        }
    }

    if ($sales_ready) {
        $test_sales = db_select_all($db,
            "SELECT test_name, amount_rial, status, paid_at, created_at
               FROM finance_test_sales
              WHERE patient_person_id = ?
              ORDER BY created_at DESC, id DESC
              LIMIT 200",
            'i', array((int)$me));
        $package_sales = db_select_all($db,
            "SELECT package_name, amount_rial, period_start, period_end,
                    status, paid_at, created_at
               FROM finance_package_sales
              WHERE patient_person_id = ?
              ORDER BY created_at DESC, id DESC
              LIMIT 200",
            'i', array((int)$me));
        foreach (array('finance_test_sales', 'finance_package_sales') as $sales_table) {
            $sale_totals = db_select_one($db,
                "SELECT COALESCE(SUM(CASE WHEN status = 'PAID' THEN amount_rial ELSE 0 END), 0) AS paid_rial,
                        COALESCE(SUM(CASE WHEN status = 'PENDING' THEN amount_rial ELSE 0 END), 0) AS pending_rial
                   FROM " . $sales_table . "
                  WHERE patient_person_id = ?",
                'i', array((int)$me));
            if ($sale_totals) {
                $paid_total_rial += (int)$sale_totals['paid_rial'];
                $pending_total_rial += (int)$sale_totals['pending_rial'];
            }
        }
    }
} catch (Exception $ex) {
    $ref = log_system_error('PATIENT_FINANCES', $ex);
    render_error_page($ref);
}

$page_title = 'اطلاعات مالی من';
$active_menu = 'patient_finances';
require __DIR__ . '/../templates/header.php';
?>
<h1>💳 اطلاعات مالی من</h1>
<p class="form-hint">مبلغ‌ها به تومان نمایش داده شده‌اند. این صفحه فقط اطلاعات ثبت‌شده توسط کلینیک را نشان می‌دهد و امکان پرداخت یا ویرایش ندارد.</p>

<?php if (!$payments_ready && !$sales_ready) { ?>
  <div class="alert alert-warning">
    اطلاعات مالی هنوز برای نمایش در پورتال فعال نشده است. لطفاً با کلینیک تماس بگیرید.
  </div>
<?php } else { ?>
  <div class="card">
    <div class="card-header">خلاصهٔ ثبت‌های مالی</div>
    <div class="card-body">
      <div class="check-row">
        <span>مجموعِ ثبت‌شده به‌عنوان پرداخت‌شده</span>
        <strong><?php echo e(tariff_rial_to_toman_display($paid_total_rial)); ?></strong>
      </div>
      <div class="check-row">
        <span>مجموعِ مبالغِ ثبت‌شده با وضعیت «در انتظار پرداخت»</span>
        <strong><?php echo e(tariff_rial_to_toman_display($pending_total_rial)); ?></strong>
      </div>
      <p class="form-hint">
        این خلاصه بر اساس وضعیت فعلیِ ثبت‌شده است و به‌تنهایی صورت‌حساب قطعی یا تأیید بازپرداخت نیست.
        اگر نوبتی لغو شده یا مبلغی با اطلاعات شما تطبیق ندارد، از کلینیک پیگیری کنید.
      </p>
    </div>
  </div>

  <?php if ($payments_ready) { ?>
  <div class="card">
    <div class="card-header">پرداخت‌های جلسات (تا ۲۰۰ مورد اخیر)</div>
    <div class="card-body">
      <?php if (count($session_payments) === 0) { ?>
        <p class="empty-state">پرداختی برای جلسه‌های شما در سامانه ثبت نشده است.</p>
      <?php } else { ?>
        <div class="item-list">
          <?php foreach ($session_payments as $payment) { ?>
            <div class="item-card">
              <div class="item-card-title">
                <?php echo e(appointment_display($payment['appointment_start_utc'])); ?>
                — <?php echo e($payment['service_title']); ?>
                <span class="badge <?php echo e(appointment_status_class($payment['appointment_status'])); ?>">
                  <?php echo e(appointment_status_label($payment['appointment_status'])); ?>
                </span>
              </div>
              <div class="item-card-meta">
                مبلغ ثبت‌شدهٔ جلسه:
                <strong><?php echo e(tariff_rial_to_toman_display($payment['session_fee_rial'])); ?></strong>
                <span class="text-muted text-small"> — ثبت مالی: <?php echo e(jalali_display($payment['created_at'])); ?></span>
              </div>
              <div class="check-row">
                <span>بیعانه</span>
                <span>
                  <span class="badge <?php echo e(finance_part_status_class($payment['deposit_status'])); ?>">
                    <?php echo e(finance_part_status_label($payment['deposit_status'])); ?>
                  </span>
                  <?php echo e(tariff_rial_to_toman_display($payment['deposit_amount_rial'])); ?>
                </span>
              </div>
              <?php if ($payment['deposit_status'] === 'PAID' && $payment['deposit_paid_at']) { ?>
                <div class="text-muted text-small">تاریخ ثبت پرداخت بیعانه: <?php echo e(jalali_display($payment['deposit_paid_at'])); ?></div>
              <?php } ?>
              <div class="check-row">
                <span>باقی‌مانده —
                  <span class="badge <?php echo e(finance_part_status_class($payment['balance_status'])); ?>">
                    <?php echo e(finance_part_status_label($payment['balance_status'])); ?>
                  </span>
                </span>
                <span><?php echo e(tariff_rial_to_toman_display($payment['balance_amount_rial'])); ?></span>
              </div>
              <?php if ($payment['balance_status'] === 'PAID' && $payment['balance_paid_at']) { ?>
                <div class="text-muted text-small">تاریخ ثبت پرداخت باقی‌مانده: <?php echo e(jalali_display($payment['balance_paid_at'])); ?></div>
              <?php } ?>
            </div>
          <?php } ?>
        </div>
      <?php } ?>
    </div>
  </div>
  <?php } ?>

  <?php if ($sales_ready) { ?>
  <div class="card">
    <div class="card-header">فروش‌های تست (تا ۲۰۰ مورد اخیر)</div>
    <div class="card-body">
      <?php if (count($test_sales) === 0) { ?>
        <p class="empty-state">فروشی برای تست‌های شما ثبت نشده است.</p>
      <?php } else { ?>
        <div class="table-wrap">
          <table class="table table-card">
            <thead><tr><th>تست</th><th>مبلغ</th><th>وضعیت</th><th>تاریخ پرداخت/ثبت</th></tr></thead>
            <tbody>
            <?php foreach ($test_sales as $sale) { ?>
              <tr>
                <td data-label="تست"><?php echo e($sale['test_name']); ?></td>
                <td data-label="مبلغ"><?php echo e(tariff_rial_to_toman_display($sale['amount_rial'])); ?></td>
                <td data-label="وضعیت"><span class="badge <?php echo $sale['status'] === 'PAID' ? 'badge-success' : 'badge-warning'; ?>">
                  <?php echo e(finance_sale_status_label($sale['status'])); ?></span></td>
                <td data-label="تاریخ پرداخت/ثبت"><?php echo e(jalali_display($sale['paid_at'] ? $sale['paid_at'] : $sale['created_at'])); ?></td>
              </tr>
            <?php } ?>
            </tbody>
          </table>
        </div>
      <?php } ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header">فروش‌های پکیج (تا ۲۰۰ مورد اخیر)</div>
    <div class="card-body">
      <?php if (count($package_sales) === 0) { ?>
        <p class="empty-state">فروشی برای پکیج‌های شما ثبت نشده است.</p>
      <?php } else { ?>
        <div class="table-wrap">
          <table class="table table-card">
            <thead><tr><th>پکیج</th><th>بازهٔ اعتبار</th><th>مبلغ</th><th>وضعیت</th><th>تاریخ پرداخت/ثبت</th></tr></thead>
            <tbody>
            <?php foreach ($package_sales as $sale) { ?>
              <tr>
                <td data-label="پکیج"><?php echo e($sale['package_name']); ?></td>
                <td data-label="بازهٔ اعتبار"><?php echo e(jalali_display($sale['period_start'], 'date')); ?>
                  تا <?php echo e(jalali_display($sale['period_end'], 'date')); ?></td>
                <td data-label="مبلغ"><?php echo e(tariff_rial_to_toman_display($sale['amount_rial'])); ?></td>
                <td data-label="وضعیت"><span class="badge <?php echo $sale['status'] === 'PAID' ? 'badge-success' : 'badge-warning'; ?>">
                  <?php echo e(finance_sale_status_label($sale['status'])); ?></span></td>
                <td data-label="تاریخ پرداخت/ثبت"><?php echo e(jalali_display($sale['paid_at'] ? $sale['paid_at'] : $sale['created_at'])); ?></td>
              </tr>
            <?php } ?>
            </tbody>
          </table>
        </div>
      <?php } ?>
    </div>
  </div>
  <?php } ?>
<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
