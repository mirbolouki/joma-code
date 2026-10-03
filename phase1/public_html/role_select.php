<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — انتخاب و تغییر نقش (کاربران چندنقشی)
 *  نقش‌ها در هر بار، از دیتابیس بازخوانی می‌شوند.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/includes/bootstrap.php';

$db = $GLOBALS['db'];
require_login();

/* بازخوانی نقش‌های فعال از دیتابیس — نه صرفاً از نشست */
$rows = role_assignments_fetch_active($db, (int)$_SESSION['person_id']);
$roles = array();
foreach ($rows as $r) {
    if ($r['role_code'] !== ROLE_PATIENT) {
        $roles[] = $r['role_code'];
    }
}
$_SESSION['available_roles'] = $roles;

if (count($roles) === 0) {
    auth_logout_session();
    flash_set('login_error', 'هیچ نقش فعالی برای حساب شما تعریف نشده است.');
    redirect(APP_BASE_URL . '/login.php');
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $selected = isset($_POST['role_code']) ? (string)$_POST['role_code'] : '';
    if (!in_array($selected, $roles, true)) {
        $error_message = 'نقش انتخاب‌شده معتبر نیست.';
    } else {
        $_SESSION['active_role_code'] = $selected;
        $_SESSION['last_activity_at'] = time();
        audit_log_write($db, (int)$_SESSION['person_id'], $selected, 'ROLE_SWITCHED',
            'account', isset($_SESSION['account_id']) ? (int)$_SESSION['account_id'] : null,
            array('role_code' => $selected));
        redirect(dashboard_url_for_role($selected));
    }
}

/* اگر فقط یک نقش دارد، نیازی به این صفحه نیست */
if (count($roles) === 1 && !isset($_SESSION['active_role_code'])) {
    $_SESSION['active_role_code'] = $roles[0];
    redirect(dashboard_url_for_role($roles[0]));
}

$role_icons = array(
    'admin' => '🧑‍💼', 'secretary' => '📋', 'therapist' => '🩺', 'psychometrist' => '🧪',
);

$page_title = 'انتخاب نقش';
$layout = 'auth';
$brand_subtitle = 'انتخاب نقش کاری';
require __DIR__ . '/templates/header.php';
?>
<h2 style="font-size:20px;color:var(--color-primary);margin-bottom:8px">انتخاب نقش</h2>
<p class="text-muted text-small mb-3">
  شما بیش از یک نقش دارید. با کدام نقش ادامه می‌دهید؟
  در طول کار هم می‌توانید از دکمهٔ 🔄 در نوار بالا نقش را عوض کنید.
</p>

<?php if ($error_message !== '') { ?>
  <div class="alert alert-error">✗ <?php echo e($error_message); ?></div>
<?php } ?>

<form method="post" action="role_select.php" data-guard>
  <?php echo csrf_field(); ?>
  <?php foreach ($roles as $i => $role) { ?>
    <label class="radio">
      <input type="radio" name="role_code" value="<?php echo e($role); ?>"<?php echo $i === 0 ? ' checked' : ''; ?>>
      <?php echo isset($role_icons[$role]) ? $role_icons[$role] : '•'; ?> <?php echo e(role_label($role)); ?>
    </label>
  <?php } ?>
  <button type="submit" class="btn btn-primary btn-block mt-3">ورود با این نقش</button>
</form>
<p class="text-center mt-2"><a href="logout.php" class="text-small text-muted">خروج از سامانه</a></p>
<?php require __DIR__ . '/templates/footer.php'; ?>
