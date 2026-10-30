<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — سربرگ مشترک همهٔ صفحات
 *  متغیرهای ورودی اختیاری:
 *    $page_title  عنوان صفحه
 *    $layout      'app'  = صفحهٔ داخلی با هدر و منو (پیش‌فرض)
 *                 'auth' = صفحهٔ تک‌کارتی (ورود، نصب، خطا)
 *    $active_menu نشانی آیتم فعال منو
 * ═══════════════════════════════════════════════════════════════════ */
if (!isset($page_title))  { $page_title = 'سامانهٔ مدیریت'; }
if (!isset($layout))      { $layout = 'app'; }
if (!isset($active_menu)) { $active_menu = ''; }

$asset_base = defined('APP_BASE_URL') ? APP_BASE_URL : '';
$active_role = isset($_SESSION['active_role_code']) ? $_SESSION['active_role_code'] : '';
/* نام ثابت برند در سربرگ؛ تنظیمات کلینیک و پایگاه داده تغییر نمی‌کنند. */
$header_brand_primary = 'کلینیک هوشمند جواد میربلوکی';
$header_brand_secondary = 'جوما';
$header_brand_full = $header_brand_primary . ' — ' . $header_brand_secondary;
$display_name = isset($_SESSION['display_name']) ? $_SESSION['display_name'] : '';
$available_roles = isset($_SESSION['available_roles']) ? $_SESSION['available_roles'] : array();
?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo e($page_title); ?> | <?php echo e($header_brand_full); ?></title>
<link rel="stylesheet" href="<?php echo e($asset_base); ?>/assets/css/style.css">
<link rel="icon" href="<?php echo e($asset_base); ?>/assets/img/logo.jpg">
<style>
  .clinic-brand-lines { display:flex; flex-direction:column; align-items:flex-start; line-height:1.2; }
  .clinic-brand-primary { font-size:13px; font-weight:700; }
  .clinic-brand-secondary { font-size:11px; font-weight:600; opacity:.88; }
  .auth-brand .clinic-brand-secondary { color:var(--color-primary); font-size:18px; opacity:1; margin:0 0 8px; }
  @media (max-width:768px) {
    .clinic-brand-primary { font-size:11px; white-space:normal; }
    .clinic-brand-secondary { font-size:10px; }
  }
</style>
</head>
<body>
<?php if ($layout === 'auth') { ?>
<div class="auth-page">
  <div class="auth-brand">
    <img src="<?php echo e($asset_base); ?>/assets/img/logo.jpg" alt="لوگوی <?php echo e($header_brand_full); ?>">
    <h1><?php echo e($header_brand_primary); ?></h1>
    <p class="clinic-brand-secondary"><?php echo e($header_brand_secondary); ?></p>
    <?php if (isset($brand_subtitle) && $brand_subtitle !== '') { ?>
      <p><?php echo e($brand_subtitle); ?></p>
    <?php } ?>
  </div>
  <div class="auth-card<?php echo (isset($wide_card) && $wide_card) ? ' auth-card-wide' : ''; ?>">
<?php } else { ?>
<div class="page">
  <header class="header">
    <div style="display:flex;align-items:center;gap:12px">
      <button class="menu-toggle" type="button" aria-label="باز کردن منو">☰</button>
      <a class="header-logo" href="<?php echo e(dashboard_url_for_role($active_role)); ?>">
        <img src="<?php echo e($asset_base); ?>/assets/img/logo.jpg" alt="لوگوی <?php echo e($header_brand_full); ?>">
        <span class="clinic-brand-lines">
          <span class="clinic-brand-primary"><?php echo e($header_brand_primary); ?></span>
          <small class="clinic-brand-secondary"><?php echo e($header_brand_secondary); ?></small>
        </span>
      </a>
    </div>
    <div class="header-right">
      <span class="header-role"><?php echo e(role_label($active_role)); ?></span>
      <span class="header-user">⬤ <?php echo e($display_name); ?></span>
      <?php if (count($available_roles) > 1) { ?>
        <a class="header-user" href="<?php echo e($asset_base); ?>/role_select.php" title="تغییر نقش">
          🔄 <span class="sr-only">تغییر نقش</span>
        </a>
      <?php } ?>
      <a class="header-user" href="<?php echo e($asset_base); ?>/logout.php">🚪 خروج</a>
    </div>
  </header>
  <div class="container-with-sidebar">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <main class="content">
<?php } ?>
