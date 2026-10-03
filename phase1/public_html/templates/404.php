<?php
/* جوما — صفحهٔ «یافت نشد» */
$page_title = 'صفحه یافت نشد';
$layout = 'auth';
$brand_subtitle = '';
$back_url = isset($_SESSION['active_role_code'])
    ? dashboard_url_for_role($_SESSION['active_role_code'])
    : APP_BASE_URL . '/login.php';
require __DIR__ . '/header.php';
?>
<div class="error-page">
  <span class="error-icon">🔎</span>
  <h1>صفحه یافت نشد</h1>
  <p>موردی که دنبال آن هستید وجود ندارد یا حذف شده است.</p>
  <a href="<?php echo e($back_url); ?>" class="btn btn-primary mt-2">بازگشت</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
