<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — صفحهٔ خطای عمومی
 *  هیچ‌گاه پیام فنی یا خطای SQL نمایش داده نمی‌شود؛ فقط کد پیگیری.
 *  متغیر ورودی: $error_ref
 * ═══════════════════════════════════════════════════════════════════ */
$page_title = 'خطا';
$layout = 'auth';
$brand_subtitle = '';
if (!isset($error_ref)) { $error_ref = '--------'; }
$back_url = isset($_SESSION['active_role_code'])
    ? dashboard_url_for_role($_SESSION['active_role_code'])
    : APP_BASE_URL . '/login.php';
require __DIR__ . '/header.php';
?>
<div class="error-page">
  <span class="error-icon">⚠️</span>
  <h1>خطایی رخ داده است</h1>
  <p>متأسفیم؛ درخواست شما کامل نشد. جزئیات فنی برای تیم پشتیبانی ثبت شد.</p>
  <div class="ref-code">REF-<?php echo e($error_ref); ?></div>
  <p class="text-small text-muted">اگر مشکل ادامه داشت، این کد پیگیری را به پشتیبانی اعلام کنید.</p>
  <a href="<?php echo e($back_url); ?>" class="btn btn-primary mt-2">بازگشت</a>
</div>
<?php require __DIR__ . '/footer.php'; ?>
