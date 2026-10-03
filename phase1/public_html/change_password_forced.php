<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — تغییر اجباری رمز عبور در نخستین ورود
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/includes/bootstrap.php';

$db = $GLOBALS['db'];
require_login();

$account = account_find_by_person_id($db, (int)$_SESSION['person_id']);
if (!$account || $account['status'] !== 'ACTIVE') {
    auth_logout_session();
    flash_set('login_error', 'حساب کاربری شما غیرفعال شده است.');
    redirect(APP_BASE_URL . '/login.php');
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $current = isset($_POST['current_password']) ? (string)$_POST['current_password'] : '';
    $new1 = isset($_POST['new_password']) ? (string)$_POST['new_password'] : '';
    $new2 = isset($_POST['new_password_confirm']) ? (string)$_POST['new_password_confirm'] : '';

    if (!password_verify($current, $account['password_hash'])) {
        $error_message = 'رمز فعلی نادرست است.';
    } elseif (!password_strength_valid($new1)) {
        $error_message = 'رمز جدید باید حداقل ۸ نویسه و شامل حرف و عدد باشد.';
    } elseif ($new1 !== $new2) {
        $error_message = 'رمز جدید و تکرار آن یکسان نیستند.';
    } elseif (password_verify($new1, $account['password_hash'])) {
        $error_message = 'رمز جدید نباید با رمز فعلی یکسان باشد.';
    } else {
        try {
            account_update_password($db, (int)$account['id'], password_hash($new1, PASSWORD_DEFAULT));
            audit_log_write($db, (int)$_SESSION['person_id'],
                isset($_SESSION['active_role_code']) ? $_SESSION['active_role_code'] : null,
                'PASSWORD_CHANGED', 'account', (int)$account['id'], null);

            $_SESSION['last_activity_at'] = time();
            flash_set('dashboard_success', 'رمز عبور شما با موفقیت تغییر کرد.');
            redirect(APP_BASE_URL . '/index.php');
        } catch (Exception $ex) {
            $ref = log_system_error('CHANGE_PASSWORD', $ex);
            render_error_page($ref);
        }
    }
}

$page_title = 'تغییر رمز عبور';
$layout = 'auth';
$brand_subtitle = '';
require __DIR__ . '/templates/header.php';
?>
<?php if ((int)$account['must_change_password'] === 1) { ?>
  <div class="alert alert-warning">
    <strong>⚠️ باید رمز خود را تغییر دهید</strong>
    این نخستین ورود شماست. برای امنیت بیشتر، یک رمز قوی انتخاب کنید.
  </div>
<?php } ?>

<?php if ($error_message !== '') { ?>
  <div class="alert alert-error">✗ <?php echo e($error_message); ?></div>
<?php } ?>

<form method="post" action="change_password_forced.php" data-guard>
  <?php echo csrf_field(); ?>
  <div class="form-group">
    <label for="current_password">رمز فعلی</label>
    <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
  </div>
  <div class="form-group">
    <label for="new_password">رمز جدید</label>
    <input type="password" id="new_password" name="new_password" autocomplete="new-password" required>
    <div data-strength-for="new_password"></div>
  </div>
  <div class="form-group">
    <label for="new_password_confirm">تکرار رمز جدید</label>
    <input type="password" id="new_password_confirm" name="new_password_confirm" autocomplete="new-password" required>
  </div>
  <button type="submit" class="btn btn-primary btn-block">تغییر رمز</button>
</form>
<div class="alert alert-info mt-3">💡 نکته: حداقل ۸ نویسه، شامل حداقل یک حرف و یک عدد.</div>
<p class="text-center mt-2"><a href="logout.php" class="text-small text-muted">خروج از سامانه</a></p>
<?php require __DIR__ . '/templates/footer.php'; ?>
