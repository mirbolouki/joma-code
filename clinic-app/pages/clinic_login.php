<?php
// ورود اختصاصی «مدیریت مطب» — منبع احراز: clinic_users در MySQL
if (current_user()) joma_redirect('index.php?p=clinic_dashboard');
$set = clinic_get_settings();
$clinic_name = isset($set['clinic_name']) && $set['clinic_name'] !== '' ? $set['clinic_name'] : 'مدیریت مطب';
$err = '';
$identifier = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $identifier = trim(isset($_POST['identifier']) ? (string) $_POST['identifier'] : '');
    $pass = isset($_POST['password']) ? $_POST['password'] : '';
    if (!cs_limit(array(array('login:ip:'.cs_ip(),30,900),array('login:account:'.strtolower($identifier),10,900)))) {
        $err='تعداد تلاش‌ها زیاد است؛ ۱۵ دقیقه بعد امتحان کنید.';
    } elseif ($identifier === '' || $pass === '') {
        $err = 'نام کاربری و رمز عبور را وارد کنید.';
    } else {
        $u = clinic_user_by_login($identifier);
        if (!$u) {
            $err = 'نام کاربری یا رمز عبور نادرست است.';
        } elseif ((int) $u['active'] === 0) {
            $err = 'این حساب غیرفعال شده است. با مدیر سیستم تماس بگیرید.';
        } elseif (!password_verify($pass, $u['password_hash'])) {
            $err = 'نام کاربری یا رمز عبور نادرست است.';
        } else {
            cs_login($u);
            joma_redirect('index.php?p=clinic_dashboard');
        }
    }
}
joma_header('ورود به ' . $clinic_name, array(), array('public' => 1));
?>
<div class="card auth-card clinic-login">
  <div style="display:flex;justify-content:center;align-items:center;margin-bottom:8px">
    <?php echo joma_logo(56); ?>
  </div>
  <h1 style="text-align:center"><?php echo clinic_h($clinic_name); ?></h1>
  <p class="lede" style="text-align:center">ورود کادر مطب و مراجعان</p>
  <form method="post" action="<?php echo e(joma_url('index.php?p=clinic_login')); ?>">
    <?php echo csrf_field(); ?>
    <label>نام کاربری یا موبایل</label>
    <input name="identifier" dir="ltr" value="<?php echo e($identifier); ?>" required autocomplete="username">
    <label>رمز عبور</label>
    <input type="password" name="password" required autocomplete="current-password">
    <?php if ($err) echo '<p class="bad">' . e($err) . '</p>'; ?>
    <p><button class="btn btn-block" type="submit">ورود</button></p>
  </form>
  <p><a class="btn" href="index.php?p=clinic_auth">ثبت‌نام مراجع با تأیید پیامکی</a></p>
  <p><a href="index.php?p=clinic_auth&amp;mode=reset">فراموشی رمز مراجع / بازیابی پیامکی</a></p>
  <p class="hint" style="text-align:center">مراجعان: نام کاربری شما همان شماره موبایل‌تان است.</p>
</div>
<?php joma_footer(); ?>
