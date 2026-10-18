<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — وصلهٔ ۴.۲.۰: درخواست نوبت اینترنتی، گام ۳ از ۳
 *
 *  تنها کارش نمایش رسید است. هیچ چیزی نمی‌نویسد و هیچ پرس‌وجویی به
 *  دیتابیس ندارد؛ شناسهٔ پیگیری از نشست خوانده می‌شود، نه از URL،
 *  تا با عوض‌کردن آدرس نشود رسید دیگران را دید.
 *
 *  صفحهٔ عمومی و عمداً بدون نگهبان (الزام ا-۱، اعلام‌شده در
 *  phase1/tools/lint/public_pages.txt).
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/includes/bootstrap.php';

if (!isset($_SESSION['booking_done']) || $_SESSION['booking_done'] === '') {
    redirect(APP_BASE_URL . '/booking.php');
}
$tracking = (string)$_SESSION['booking_done'];

$page_title = 'درخواست ثبت شد';
$layout = 'auth';
$brand_subtitle = 'درخواست نوبت مشاوره';
require __DIR__ . '/templates/header.php';
?>
<h2>✅ درخواست شما ثبت شد</h2>

<div class="alert alert-success">
  درخواست شما با موفقیت دریافت شد و در نوبت بررسی قرار گرفت.
</div>
<?php if (!empty($_SESSION['booking_terms_done'])) { ?>
  <div class="alert alert-info">
    گزینه‌های پذیرشِ ضوابط عمومی همراه با نسخهٔ متن، زمان و شمارهٔ تأییدشده ثبت شد؛ کد خام پیامک ذخیره نشده است.
    این ثبت، وقت قطعی یا پذیرشِ مبلغ بیعانه‌ای را که در این فرم اعلام نشده ایجاد نمی‌کند.
    اگر درخواست به پذیرش تبدیل و حساب پورتال برایتان فعال شود، نسخهٔ ثبت‌شده در بخش «ضوابط پذیرفته‌شده» دیده می‌شود.
  </div>
<?php } ?>

<div class="card">
  <div class="card-body">
    <p><strong>مرحلهٔ بعد چیست؟</strong></p>
    <ul class="hint-list">
      <li>همکاران ما درخواست شما را بررسی می‌کنند.</li>
      <li><strong>وقت قطعی را منشی طی تماس تلفنی با شما هماهنگ و اعلام می‌کند.</strong></li>
      <li>تا پیش از آن تماس، هیچ نوبتی برای شما رزرو نشده است.</li>
    </ul>
    <p class="text-small text-muted">
      شناسهٔ پیگیری:
      <span class="mono" dir="ltr"><?php echo e($tracking); ?></span><br>
      اگر تماس گرفتید، این شناسه کار همکاران ما را آسان‌تر می‌کند.
    </p>
    <?php if (booking_clinic_phone() !== '') { ?>
      <p class="text-small">
        شمارهٔ تماس کلینیک:
        <span class="mono" dir="ltr"><?php echo e(booking_clinic_phone()); ?></span>
      </p>
    <?php } ?>
  </div>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
