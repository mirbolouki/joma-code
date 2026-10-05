<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — ورود پرسنل
 *  تب «مراجع» پس از نصب فاز ۴ فعال می‌شود (ورود با کد پیامکی).
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/includes/bootstrap.php';

$db = $GLOBALS['db'];
$error_message = '';
$login_identifier = '';

/* اگر از قبل وارد شده است */
if (isset($_SESSION['person_id']) && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(APP_BASE_URL . '/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $login_identifier = isset($_POST['login_identifier']) ? trim($_POST['login_identifier']) : '';
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';

    if ($login_identifier === '' || $password === '') {
        $error_message = 'نام کاربری و رمز عبور را وارد کنید.';
    } else {
        try {
            $result = staff_login($db, $login_identifier, $password);
            if ($result['ok']) {
                if (!empty($result['needs_role_selection'])) {
                    redirect(APP_BASE_URL . '/role_select.php');
                }
                redirect(APP_BASE_URL . '/index.php');
            }
            $error_message = $result['message'];
        } catch (Exception $ex) {
            $ref = log_system_error('LOGIN', $ex);
            render_error_page($ref);
        }
    }
}

$flash_error = flash_get('login_error');
$flash_success = flash_get('login_success');

$page_title = 'ورود به سامانه';
$layout = 'auth';
$brand_subtitle = 'سامانهٔ مدیریت مراجعات';
require __DIR__ . '/templates/header.php';
?>
<div class="tabs" role="tablist">
  <button class="tab active" type="button" role="tab" aria-selected="true">پرسنل</button>
  <?php if (phase4_ready($db)) { ?>
    <a class="tab" href="patient_login.php" role="tab" aria-selected="false">مراجع</a>
  <?php } else { ?>
    <button class="tab disabled" type="button" role="tab" aria-selected="false" disabled>
      مراجع <small>به‌زودی فعال می‌شود</small>
    </button>
  <?php } ?>
</div>

<?php if ($flash_success) { ?>
  <div class="alert alert-success"><?php echo e($flash_success); ?></div>
<?php } ?>
<?php if ($flash_error) { ?>
  <div class="alert alert-warning"><?php echo e($flash_error); ?></div>
<?php } ?>
<?php if ($error_message !== '') { ?>
  <div class="alert alert-error">✗ <?php echo e($error_message); ?></div>
<?php } ?>

<form method="post" action="login.php" data-guard data-remember-username>
  <?php echo csrf_field(); ?>
  <div class="form-group">
    <label for="login_identifier">نام کاربری</label>
    <input type="text" id="login_identifier" name="login_identifier" dir="ltr"
           autocomplete="username" value="<?php echo e($login_identifier); ?>" required>
  </div>
  <div class="form-group">
    <label for="password">رمز عبور</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>
  </div>
  <label class="checkbox">
    <input type="checkbox" name="remember_username" value="1"> نام کاربری مرا به یاد داشته باش
  </label>
  <button type="submit" class="btn btn-primary btn-block mt-2">ورود</button>
  <p class="text-center mt-2">
    <a href="forgot_password_staff.php" class="text-small text-muted">رمز عبور خود را فراموش کرده‌ام</a>
  </p>
</form>
<?php require __DIR__ . '/templates/footer.php'; ?>
