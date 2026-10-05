<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — داشبورد مدیر
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

try {
    $stat_users = count_staff_users($db);
    $stat_admissions_today = count_admissions_today($db);
    $stat_active_cases = count_active_cases($db);
    $forms_ready = phase4_ready($db);
    $intake_missing = $forms_ready ? form_intake_template_missing($db) : false;
    $auto_failures = $forms_ready ? form_auto_assign_failures_recent($db, 7) : 0;
} catch (Exception $ex) {
    $ref = log_system_error('ADMIN_DASHBOARD', $ex);
    render_error_page($ref);
}

$sms_mode = sms_provider_mode();
$sms_badge = array(
    'smsir'    => array('badge-success', 'فعال (SMS.ir)'),
    'dev'      => array('badge-warning', 'حالت آزمایشی (فقط فایل لاگ)'),
    'disabled' => array('badge-danger',  'غیرفعال'),
);
$badge = isset($sms_badge[$sms_mode]) ? $sms_badge[$sms_mode] : array('badge-muted', '—');

$page_title = 'داشبورد مدیر';
$active_menu = 'admin_home';
require __DIR__ . '/../templates/header.php';
?>
<h1>📊 داشبورد مدیر</h1>
<?php echo flash_render('dashboard_success', 'success'); ?>

<?php if ($forms_ready && $intake_missing) { ?>
  <div class="alert alert-warning">
    <strong>⚠️ هیچ نسخهٔ فعالی از «فرم پذیرش اولیه» وجود ندارد.</strong><br>
    تا وقتی این قالب فعال نباشد، فرم پذیرش به مراجعان تازه تخصیص داده نمی‌شود.
    <a href="forms.php">رفتن به قالب‌های فرم</a>
  </div>
<?php } ?>

<?php if ($forms_ready && $auto_failures > 0) { ?>
  <div class="alert alert-warning">
    <strong>⚠️ <?php echo to_persian_digits($auto_failures); ?> مورد تخصیص خودکار فرم پذیرش
    در هفتهٔ گذشته ناموفق بوده است.</strong><br>
    نوبت‌ها سالم ثبت شده‌اند، اما فرم پذیرش برای آن مراجعان ساخته نشده است؛
    می‌توانید فرم را دستی تخصیص دهید. جزئیات در گزارش حسابرسی با کد
    <span class="mono">FORM_AUTO_ASSIGN_FAILED</span> ثبت شده است.
  </div>
<?php } ?>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-card-icon">👥</div>
    <div class="stat-card-value"><?php echo to_persian_digits($stat_users); ?></div>
    <div class="stat-card-label">کاربران پرسنلی فعال</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-icon">📋</div>
    <div class="stat-card-value"><?php echo to_persian_digits($stat_admissions_today); ?></div>
    <div class="stat-card-label">پذیرش‌های امروز</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-icon">📂</div>
    <div class="stat-card-value"><?php echo to_persian_digits($stat_active_cases); ?></div>
    <div class="stat-card-label">پرونده‌های فعال</div>
  </div>
</div>

<div class="action-panel">
  <a href="user_create.php" class="btn btn-primary">➕ ایجاد کاربر جدید</a>
  <a href="users_list.php" class="btn btn-secondary">👥 فهرست کاربران</a>
  <a href="<?php echo e(APP_BASE_URL); ?>/reception/index.php" class="btn btn-secondary">📋 پذیرش‌ها</a>
  <a href="sms_settings.php" class="btn btn-secondary">📨 تنظیمات پیامک</a>
<?php if ($forms_ready) { ?>
  <a href="forms.php" class="btn btn-secondary">🧾 قالب‌های فرم</a>
  <a href="form_responses.php" class="btn btn-secondary">📑 پاسخ‌های فرم</a>
<?php } ?>
<?php if (phase2_ready($db)) { ?>
  <a href="<?php echo e(APP_BASE_URL); ?>/reception/appointments.php" class="btn btn-secondary">📅 تقویم نوبت‌ها</a>
  <a href="rooms.php" class="btn btn-secondary">🏠 اتاق‌ها</a>
  <a href="tariffs.php" class="btn btn-secondary">💰 تعرفه‌ها</a>
<?php } else { ?>
  <a href="<?php echo e(APP_BASE_URL); ?>/upgrade_phase2.php" class="btn btn-warning">🧩 ارتقا به فاز ۲</a>
<?php } ?>
</div>

<div class="card">
  <div class="card-header">وضعیت سامانه</div>
  <div class="card-body">
    <div class="check-row">
      <span>تاریخ امروز</span>
      <span class="fw-bold"><?php echo e(jalali_today_long()); ?></span>
    </div>
    <div class="check-row">
      <span>وضعیت پیامک</span>
      <span class="badge <?php echo e($badge[0]); ?>"><?php echo e($badge[1]); ?></span>
    </div>
    <div class="check-row">
      <span>نسخهٔ سامانه</span>
      <span class="badge badge-primary"><?php echo phase2_ready($db) ? '۲.۰ — فاز ۲' : '۱.۰ — فاز ۱'; ?></span>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
