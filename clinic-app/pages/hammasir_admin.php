<?php
/*
 * ماژول «هم‌مسیر» — لندینگ مدیریت (حکم PO — بخش دو-۱)
 * ------------------------------------------------------------------
 * فقط از pages/hammasir.php در حالت admin include می‌شود؛ route عمومی ندارد.
 * این صفحه فقط دو لینک جدا به دو صفحه‌ی مستقل می‌دهد:
 *   «مدیریت مشاوران»  → index.php?p=hammasir_providers
 *   «مدیران سیستم»    → index.php?p=hammasir_admins
 * هیچ فرم/عملیاتی داخل خودش نیست (بدون تودرتو).
 */

// گارد مستقیم — partial هرگز مستقیماً از وب اجرا نمی‌شود → 403 خشک بدون خروجی
if (!defined('JOMA_IN_APP')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

// گارد دوگانه — فقط ADMIN_ACCESS (گیت دوم؛ گیت اول در صفحه‌ی میزبان است)
if (!function_exists('has_perm') || !has_perm('ADMIN_ACCESS')) {
    return;
}
?>
<section class="card">
  <h2>مدیریت هم‌مسیر</h2>
  <div class="btn-row">
    <a class="btn" href="<?php echo e(joma_url('index.php?p=hammasir_providers')); ?>">مدیریت مشاوران</a>
    <a class="btn" href="<?php echo e(joma_url('index.php?p=hammasir_admins')); ?>">مدیران سیستم</a>
  </div>
</section>
