<?php
/* جوما — صفحهٔ «دسترسی مجاز نیست» */
$page_title = 'دسترسی مجاز نیست';
$layout = 'auth';
$brand_subtitle = '';
$back_url = isset($_SESSION['active_role_code'])
    ? dashboard_url_for_role($_SESSION['active_role_code'])
    : APP_BASE_URL . '/login.php';
require __DIR__ . '/header.php';
?>
<div class="error-page">
  <span class="error-icon">🔒</span>
  <h1>دسترسی مجاز نیست</h1>
  <p>شما اجازهٔ دسترسی به این صفحه را ندارید.</p>
  <p class="text-small text-muted">اگر فکر می‌کنید اشتباهی رخ داده است، با مدیر سامانه تماس بگیرید.</p>
  <a href="<?php echo e($back_url); ?>" class="btn btn-primary mt-2">بازگشت</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
