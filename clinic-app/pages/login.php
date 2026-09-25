<?php
if (current_user()) joma_redirect('index.php?p=dashboard');
$err = '';
$identifier = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $identifier = strtolower(trim(isset($_POST['identifier']) ? $_POST['identifier'] : ''));
    $pass = isset($_POST['password']) ? $_POST['password'] : '';
    if ($identifier === '' || $pass === '') {
        $err = 'نام کاربری و رمز عبور را وارد کنید.';
    } else {
        $u = user_by_username($identifier);
        if (!$u) {
            $err = 'حسابی با این نام کاربری یا ایمیل پیدا نشد.';
        } elseif (isset($u['active']) && (int) $u['active'] === 0) {
            // CLINIC: حساب غیرفعال‌شده توسط مدیر
            $err = 'این حساب غیرفعال شده است. با مدیر سیستم تماس بگیرید.';
        } elseif (!password_verify($pass, $u['password_hash'])) {
            $err = 'نام کاربری/ایمیل یا رمز عبور نادرست است.';
        } else {
            $_SESSION['user'] = session_user_array($u);
            // CLINIC: سید کتابخانه جوما فقط برای کاربران جوما؛ کاربران مطب سید نمی‌خواهند
            if (!in_array($u['role_key'], array('doctor', 'head_secretary', 'secretary', 'client'), true)) {
                copy_seed_to_user($u['id']);
            }
            // CLINIC: کاربران مطب مستقیم به پیشخوان مطب می‌روند
            if (in_array($u['role_key'], array('doctor', 'head_secretary', 'secretary', 'client'), true)) {
                joma_redirect('index.php?p=clinic_dashboard');
            }
            // (حکم PO — باگ ۹ گزینه A) مقصد پس از ورود داشبورد است؛ کاربرِ بدون خلقِ امروز
            // همچنان توسط maybe_mood_gate از همان داشبورد به p=mood هدایت می‌شود (رفتار gate دست‌نخورده).
            joma_redirect('index.php?p=dashboard');
        }
    }
}
joma_header('ورود', array(), array('public' => 1));
?>
<div class="card auth-card">
  <div style="display:flex;justify-content:space-between;align-items:center">
    <?php echo joma_logo(56); ?>
    <a href="<?php echo e(joma_url('index.php?p=home')); ?>">بازگشت به معرفی</a>
  </div>
  <h1>ورود به جوما</h1>
  <p class="lede"></p>
  <div class="mode-tabs">
    <a class="on" href="<?php echo e(joma_url('index.php?p=login')); ?>">ورود</a>
    <a href="<?php echo e(joma_url('index.php?p=register')); ?>">ثبت‌نام</a>
    <a href="<?php echo e(joma_url('index.php?p=forgot')); ?>">فراموشی</a>
  </div>
  <form method="post" action="<?php echo e(joma_url('index.php?p=login')); ?>">
    <?php echo csrf_field(); ?>
    <label>نام کاربری یا ایمیل</label>
    <input name="identifier" dir="ltr" value="<?php echo e($identifier); ?>" required>
    <label>رمز عبور</label>
    <input type="password" name="password" required>
    <?php if ($err) echo '<p class="bad">'.e($err).'</p>'; ?>
    <p><button class="btn btn-block" type="submit">ورود</button></p>
  </form>
  <p style="text-align:center"><a href="<?php echo e(joma_url('index.php?p=about')); ?>">درباره جوما</a></p>
</div>
<?php joma_footer(); ?>
