<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما فاز ۲ — تقویم درمانگر (فقط نوبت‌های خودِ کاربر)
 *
 *  درمانگر تقویم هیچ درمانگر دیگری را نمی‌بیند؛ شناسهٔ درمانگر از نشست
 *  خوانده می‌شود و هرگز از پارامتر نشانی پذیرفته نمی‌شود.
 *  فقط بازهٔ انتخاب‌شده خوانده می‌شود، نه یک سال کامل.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_THERAPIST));

if (!phase2_ready($db)) {
    $page_title = 'تقویم من';
    require __DIR__ . '/../templates/header.php';
    echo '<h1>📅 تقویم من</h1><div class="alert alert-info">'
       . 'بخش نوبت‌دهی هنوز روی این نصب فعال نشده است. از مدیر سامانه بخواهید ارتقای فاز ۲ را اجرا کند.'
       . '</div>';
    require __DIR__ . '/../templates/footer.php';
    exit;
}

$me = (int)$_SESSION['person_id'];

$today = clinic_today();
$from_j = isset($_GET['from']) ? trim((string)$_GET['from']) : jalali_value($today);
$to_j   = isset($_GET['to'])   ? trim((string)$_GET['to'])   : jalali_value(date_add_days($today, 7));
$date_choices = jalali_date_choices(date_add_days($today, -60), 120);

$from_g = jalali_input_to_gregorian($from_j);
$to_g   = jalali_input_to_gregorian($to_j);
$notice = '';
if ($from_g === null) { $from_g = $today; $from_j = jalali_value($today); }
if ($to_g === null || $to_g < $from_g) { $to_g = date_add_days($from_g, 7); $to_j = jalali_value($to_g); }
if (date_diff_days($from_g, $to_g) > 60) {
    $to_g = date_add_days($from_g, 60);
    $to_j = jalali_value($to_g);
    $notice = 'بازهٔ نمایش به ۶۰ روز محدود شد.';
}

try {
    /* فیلتر درمانگر همیشه برابر کاربر جاری است */
    $appointments = appointments_fetch_range($db, $from_g, $to_g, $me, null);
    $today_count = appointments_count_today($db, $me);
} catch (Exception $ex) {
    $ref = log_system_error('THERAPIST_CALENDAR', $ex);
    render_error_page($ref);
}

$by_day = array();
foreach ($appointments as $a) {
    $day = substr(utc_to_local($a['appointment_start_utc']), 0, 10);
    if (!isset($by_day[$day])) {
        $by_day[$day] = array();
    }
    $by_day[$day][] = $a;
}

$page_title = 'تقویم من';
$active_menu = 'therapist_calendar';
require __DIR__ . '/../templates/header.php';
?>
<h1>📅 تقویم من</h1>
<?php if ($notice !== '') { ?><div class="alert alert-info"><?php echo e($notice); ?></div><?php } ?>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-card-icon">📌</div>
    <div class="stat-card-value"><?php echo to_persian_digits($today_count); ?></div>
    <div class="stat-card-label">نوبت رزروشدهٔ امروز</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-icon">🗓️</div>
    <div class="stat-card-value"><?php echo to_persian_digits(count($appointments)); ?></div>
    <div class="stat-card-label">نوبت در بازهٔ انتخابی</div>
  </div>
</div>

<div class="card">
  <div class="card-header">بازهٔ نمایش</div>
  <div class="card-body">
    <form method="get" action="calendar.php" class="form-inline">
      <label for="from">از تاریخ</label>
      <select id="from" name="from">
        <?php foreach ($date_choices as $dc) { ?>
          <option value="<?php echo e($dc['value']); ?>"
            <?php echo ((string)$from_j === $dc['value']) ? 'selected' : ''; ?>>
            <?php echo e($dc['label']); ?>
          </option>
        <?php } ?>
      </select>
      <label for="to">تا تاریخ</label>
      <select id="to" name="to">
        <?php foreach ($date_choices as $dc) { ?>
          <option value="<?php echo e($dc['value']); ?>"
            <?php echo ((string)$to_j === $dc['value']) ? 'selected' : ''; ?>>
            <?php echo e($dc['label']); ?>
          </option>
        <?php } ?>
      </select>
      <button type="submit" class="btn btn-primary">نمایش</button>
      <a class="btn btn-secondary" href="absences.php">🏖️ اعلام عدم حضور</a>
    </form>
  </div>
</div>

<?php if (count($appointments) === 0) { ?>
  <p class="empty-state">در این بازه نوبتی برای شما ثبت نشده است.</p>
<?php } ?>

<?php foreach ($by_day as $day => $items) { ?>
<div class="card day-card">
  <div class="card-header">
    <?php echo e(jalali_long_label($day)); ?>
    — <?php echo to_persian_digits(count($items)); ?> نوبت
  </div>
  <div class="card-body">
    <div class="table-wrap">
      <table class="table table-card">
        <thead><tr><th>ساعت</th><th>مراجع</th><th>خدمت</th><th>اتاق</th><th>وضعیت</th></tr></thead>
        <tbody>
        <?php foreach ($items as $a) {
              $local = utc_to_local($a['appointment_start_utc']);
              $local_end = utc_to_local($a['appointment_end_utc']); ?>
          <tr>
            <td data-label="ساعت" dir="ltr">
              <?php echo to_persian_digits(substr($local, 11, 5) . '–' . substr($local_end, 11, 5)); ?></td>
            <td data-label="مراجع"><?php echo e($a['patient_first_name'] . ' ' . $a['patient_last_name']); ?></td>
            <td data-label="خدمت"><?php echo e($a['service_title']); ?></td>
            <td data-label="اتاق"><?php echo e($a['room_name']); ?></td>
            <td data-label="وضعیت">
              <span class="badge <?php echo e(appointment_status_class($a['status'])); ?>">
                <?php echo e(appointment_status_label($a['status'])); ?></span>
            </td>
          </tr>
        <?php } ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php } ?>

<p class="form-hint">ثبت، لغو و جابه‌جایی نوبت از طریق «منشی» یا «مدیر» انجام می‌شود.</p>
<?php require __DIR__ . '/../templates/footer.php'; ?>
