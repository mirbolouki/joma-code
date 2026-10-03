<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — منوی کناری (در چیدمان راست‌به‌چپ، سمت راست صفحه)
 *  آیتم‌ها بر اساس نقش فعال ساخته می‌شوند.
 * ═══════════════════════════════════════════════════════════════════ */
$base = defined('APP_BASE_URL') ? APP_BASE_URL : '';
$role = isset($_SESSION['active_role_code']) ? $_SESSION['active_role_code'] : '';
if (!isset($active_menu)) { $active_menu = ''; }

$menu = array();

if ($role === ROLE_ADMIN) {
    $menu = array(
        array('label' => '📊 داشبورد',        'url' => $base . '/admin/index.php',        'key' => 'admin_home'),
        array('label' => '➕ ایجاد کاربر',     'url' => $base . '/admin/user_create.php',  'key' => 'user_create'),
        array('label' => '👥 فهرست کاربران',   'url' => $base . '/admin/users_list.php',   'key' => 'users_list'),
        array('label' => '📋 پذیرش‌ها',        'url' => $base . '/reception/index.php',    'key' => 'reception_home'),
        array('label' => '📨 تنظیمات پیامک',   'url' => $base . '/admin/sms_settings.php', 'key' => 'sms_settings'),
        array('label' => '📂 پرونده‌ها',       'url' => '',                                'key' => '', 'soon' => true),
        array('label' => '⚙️ تنظیمات کلینیک',  'url' => '',                                'key' => '', 'soon' => true),
    );
} elseif ($role === ROLE_SECRETARY) {
    $menu = array(
        array('label' => '📋 داشبورد',          'url' => $base . '/reception/index.php',        'key' => 'reception_home'),
        array('label' => '➕ ثبت پذیرش جدید',    'url' => $base . '/reception/admission_new.php', 'key' => 'admission_new'),
        array('label' => '📅 تقویم نوبت‌ها',     'url' => '',                                     'key' => '', 'soon' => true),
    );
} elseif ($role === ROLE_THERAPIST) {
    $menu = array(
        array('label' => '🩺 کارتابل',         'url' => $base . '/therapist/index.php', 'key' => 'therapist_home'),
        array('label' => '📂 پرونده‌های من',    'url' => $base . '/therapist/index.php#cases', 'key' => ''),
        array('label' => '📅 تقویم من',        'url' => '',                              'key' => '', 'soon' => true),
    );
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
