<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — منوی کناری (در چیدمان راست‌به‌چپ، سمت راست صفحه)
 *  آیتم‌ها بر اساس نقش فعال ساخته می‌شوند.
 * ═══════════════════════════════════════════════════════════════════ */
$base = defined('APP_BASE_URL') ? APP_BASE_URL : '';
$role = isset($_SESSION['active_role_code']) ? $_SESSION['active_role_code'] : '';
if (!isset($active_menu)) { $active_menu = ''; }

$menu = array();
$p2 = (isset($GLOBALS['db']) && function_exists('phase2_ready')) ? phase2_ready($GLOBALS['db']) : false;
$p3 = (isset($GLOBALS['db']) && function_exists('phase3_ready')) ? phase3_ready($GLOBALS['db']) : false;

if ($role === ROLE_ADMIN) {
    $menu = array(
        array('label' => '📊 داشبورد',        'url' => $base . '/admin/index.php',        'key' => 'admin_home'),
        array('label' => '➕ ایجاد کاربر',     'url' => $base . '/admin/user_create.php',  'key' => 'user_create'),
        array('label' => '👥 فهرست کاربران',   'url' => $base . '/admin/users_list.php',   'key' => 'users_list'),
        array('label' => '📋 پذیرش‌ها',        'url' => $base . '/reception/index.php',    'key' => 'reception_home'),
        array('label' => '📨 تنظیمات پیامک',   'url' => $base . '/admin/sms_settings.php', 'key' => 'sms_settings'),
    );
    if ($p2) {
        $menu[] = array('label' => '📅 تقویم نوبت‌ها', 'url' => $base . '/reception/appointments.php', 'key' => 'appointments');
        $menu[] = array('label' => '🏠 اتاق‌ها',       'url' => $base . '/admin/rooms.php',            'key' => 'rooms');
        $menu[] = array('label' => '💰 تعرفه‌ها',      'url' => $base . '/admin/tariffs.php',          'key' => 'tariffs');
    } else {
        $menu[] = array('label' => '🧩 ارتقا به فاز ۲', 'url' => $base . '/upgrade_phase2.php', 'key' => 'upgrade2');
    }
    $menu[] = array('label' => '📂 پرونده‌ها',      'url' => '', 'key' => '', 'soon' => true);
    $menu[] = array('label' => '⚙️ تنظیمات کلینیک', 'url' => '', 'key' => '', 'soon' => true);
} elseif ($role === ROLE_SECRETARY) {
    $menu = array(
        array('label' => '📋 داشبورد',          'url' => $base . '/reception/index.php',        'key' => 'reception_home'),
        array('label' => '➕ ثبت پذیرش جدید',    'url' => $base . '/reception/admission_new.php', 'key' => 'admission_new'),
    );
    if ($p2) {
        $menu[] = array('label' => '📅 تقویم نوبت‌ها', 'url' => $base . '/reception/appointments.php',    'key' => 'appointments');
        $menu[] = array('label' => '🗓️ ثبت نوبت',      'url' => $base . '/reception/appointment_new.php', 'key' => 'appointment_new');
    } else {
        $menu[] = array('label' => '📅 تقویم نوبت‌ها', 'url' => '', 'key' => '', 'soon' => true);
    }
} elseif ($role === ROLE_THERAPIST) {
    $menu = array(
        array('label' => '🩺 کارتابل',         'url' => $base . '/therapist/index.php', 'key' => 'therapist_home'),
        array('label' => '📂 پرونده‌های من',    'url' => $base . '/therapist/index.php#cases', 'key' => ''),
    );
    if ($p3) {
        $menu[] = array('label' => '🗒️ آخرین یادداشت‌های من',
                        'url' => $base . '/therapist/notes_recent.php', 'key' => 'notes_recent');
    }
    if ($p2) {
        $menu[] = array('label' => '📅 تقویم من',    'url' => $base . '/therapist/calendar.php',     'key' => 'therapist_calendar');
        $menu[] = array('label' => '🏖️ عدم حضور',    'url' => $base . '/therapist/absences.php',     'key' => 'therapist_absences');
        $menu[] = array('label' => '💰 تعرفهٔ من',   'url' => $base . '/therapist/tariffs_view.php', 'key' => 'therapist_tariffs');
    } else {
        $menu[] = array('label' => '📅 تقویم من', 'url' => '', 'key' => '', 'soon' => true);
    }
} elseif ($role === ROLE_PSYCHOMETRIST) {
    $menu = array(
        array('label' => '🧪 بخش روان‌سنجی', 'url' => '', 'key' => '', 'soon' => true),
    );
}
?>
<nav class="sidebar" aria-label="منوی اصلی">
  <div class="sidebar-title">منوی <?php echo e(role_label($role)); ?></div>
  <ul>
    <?php foreach ($menu as $item) { ?>
      <?php if (!empty($item['soon'])) { ?>
        <li><a href="#" class="disabled-link" aria-disabled="true"><?php echo $item['label']; ?>
          <span class="badge badge-muted">فاز بعد</span></a></li>
      <?php } else { ?>
        <li><a href="<?php echo e($item['url']); ?>"<?php
          echo ($item['key'] !== '' && $item['key'] === $active_menu) ? ' class="active"' : ''; ?>>
          <?php echo $item['label']; ?></a></li>
      <?php } ?>
    <?php } ?>
  </ul>
</nav>
