<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — وصلهٔ ۴.۲.۰: میز تریاژ درخواست‌های نوبت اینترنتی
 *
 *  این صفحه «نوبت» نشان نمی‌دهد؛ درخواست‌های قرنطینه‌شده را نشان
 *  می‌دهد. هیچ‌کدام از این ردیف‌ها هنوز مراجع یا پذیرش نیستند.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_SECRETARY));   /* مدیر به‌واسطهٔ ارث‌بری نقش */

if (!phase4_2_ready($db)) {
    $page_title = 'درخواست‌های نوبت';
    $active_menu = 'booking_requests';
    require __DIR__ . '/../templates/header.php';
    echo '<h1>📨 درخواست‌های نوبت</h1>'
       . '<div class="alert alert-warning">وصلهٔ ۴.۲.۰ هنوز روی این سامانه اجرا نشده است.</div>';
    require __DIR__ . '/../templates/footer.php';
    exit;
}

$status = isset($_GET['status']) ? (string)$_GET['status'] : 'NEW';
$term   = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$rows = array();
$counts = array();
try {
    /* درخواست‌های از یاد رفته، پیش از نمایش فهرست منقضی می‌شوند تا
       میز منشی با ردیف‌های یک‌سالهٔ بی‌فایده شلوغ نشود. */
    booking_expire_overdue($db);

    $counts = booking_status_counts($db);
    $rows   = booking_requests_search($db, $status, $term);
} catch (Exception $ex) {
    $ref = log_system_error('BOOKING_TRIAGE_LIST', $ex);
    render_error_page($ref);
}

$tabs = array(
    'NEW'       => 'تازه',
    'CONTACTED' => 'تماس گرفته شد',
    'CONVERTED' => 'تبدیل‌شده',
    'REJECTED'  => 'رد شده',
    'SPAM'      => 'اسپم',
    'EXPIRED'   => 'منقضی',
    'ALL'       => 'همه',
);

$page_title = 'درخواست‌های نوبت';
$active_menu = 'booking_requests';
require __DIR__ . '/../templates/header.php';
?>
<h1>📨 درخواست‌های نوبت اینترنتی</h1>

<?php
echo flash_render('booking_success', 'success');
echo flash_render('booking_error', 'error');
?>

<div class="alert alert-info">
  این‌ها <strong>درخواست</strong>اند، نه نوبت. هیچ‌کدام هنوز در تقویم کلینیک
  جایی ندارند و برای هیچ‌کدام پرونده‌ای ساخته نشده است.
</div>

<div class="tabs" role="tablist">
  <?php foreach ($tabs as $code => $label) {
      $is_active = ($code === $status) || ($code === 'ALL' && !isset($tabs[$status]));
      $badge = ($code !== 'ALL' && isset($counts[$code]) && $counts[$code] > 0)
             ? ' (' . to_persian_digits($counts[$code]) . ')' : '';
  ?>
    <a class="tab<?php echo $is_active ? ' active' : ''; ?>"
       href="booking_requests.php?status=<?php echo e($code); ?><?php
             echo $term !== '' ? '&amp;q=' . urlencode($term) : ''; ?>"
       role="tab" aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
      <?php echo e($label . $badge); ?>
    </a>
  <?php } ?>
</div>

<div class="card">
  <div class="card-header">جست‌وجو</div>
  <div class="card-body">
    <form method="get" action="booking_requests.php">
      <input type="hidden" name="status" value="<?php echo e($status); ?>">
      <div class="form-row">
        <label for="q">نام، شمارهٔ موبایل یا شناسهٔ پیگیری</label>
        <input type="text" id="q" name="q" maxlength="60" value="<?php echo e($term); ?>"
               placeholder="مثلاً: احمدی یا ۰۹۱۲۳۴۵۶۷۸۹">
        <p class="form-hint">جست‌وجوی عددی با دست‌کم چهار رقم روی شمارهٔ موبایل انجام می‌شود.</p>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">🔍 جست‌وجو</button>
        <?php if ($term !== '') { ?>
          <a class="btn btn-secondary"
             href="booking_requests.php?status=<?php echo e($status); ?>">پاک کردن</a>
        <?php } ?>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    نتیجه (<?php echo e(to_persian_digits(count($rows))); ?>)
  </div>
  <div class="card-body">
    <?php if (count($rows) === 0) { ?>
      <div class="empty-state">
        <?php echo $term === ''
            ? 'در این وضعیت درخواستی وجود ندارد.'
            : 'هیچ درخواستی با این جست‌وجو پیدا نشد.'; ?>
      </div>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead>
            <tr>
              <th>نام</th><th>موبایل</th><th>خدمت درخواستی</th>
              <th>زمان ترجیحی</th><th>تاریخ ثبت</th><th>وضعیت</th><th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r) { ?>
            <tr>
              <td data-label="نام"><?php echo e($r['first_name'] . ' ' . $r['last_name']); ?></td>
              <td data-label="موبایل">
                <span class="mono" dir="ltr"><?php echo e(to_persian_digits($r['mobile_number'])); ?></span>
              </td>
              <td data-label="خدمت درخواستی"><?php echo e($r['requested_service_label']); ?></td>
              <td data-label="زمان ترجیحی" class="text-small"><?php echo e($r['preferred_text']); ?></td>
              <td data-label="تاریخ ثبت" class="text-small">
                <?php echo e(jalali_display($r['created_at'], 'datetime')); ?>
              </td>
              <td data-label="وضعیت">
                <span class="badge <?php echo e(booking_status_badge($r['status'])); ?>">
                  <?php echo e(booking_status_label($r['status'])); ?>
                </span>
              </td>
              <td data-label="">
                <a class="btn btn-sm btn-secondary"
                   href="booking_request_view.php?id=<?php echo e($r['public_id']); ?>">جزئیات</a>
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
