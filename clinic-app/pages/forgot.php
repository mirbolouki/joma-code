<?php
if (current_user()) joma_redirect('index.php?p=dashboard');
$err = '';
$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $err = reset_user_password(
        isset($_POST['identifier']) ? $_POST['identifier'] : '',
        isset($_POST['password']) ? $_POST['password'] : '',
        isset($_POST['confirm']) ? $_POST['confirm'] : ''
    );
    if ($err === '') {
        $ok = 'رمز جدید ذخیره شد. حالا وارد شوید.';
    }
}
joma_header('بازیابی رمز', array(), array('public' => 1));
?>
<div class="card auth-card">
  <div style="display:flex;justify-content:space-between;align-items:center">
    <?php echo joma_logo(56); ?>
    <a href="<?php echo e(joma_url('index.php?p=home')); ?>">بازگشت به معرفی</a>
  </div>
  <h1>بازیابی رمز عبور</h1>
  <p class="lede"></p>
  <div class="mode-tabs">
    <a href="<?php echo e(joma_url('index.php?p=login')); ?>">ورود</a>
    <a href="<?php echo e(joma_url('index.php?p=register')); ?>">ثبت‌نام</a>
    <a class="on" href="<?php echo e(joma_url('index.php?p=forgot')); ?>">فراموشی</a>
  </div>
  <form method="post" action="<?php echo e(joma_url('index.php?p=forgot')); ?>">
    <?php echo csrf_field(); ?>
    <label>نام کاربری یا ایمیل</label>
    <input name="identifier" dir="ltr" required>
    <label>رمز عبور جدید</label>
    <input type="password" name="password" required>
    <label>تکرار رمز عبور</label>
    <input type="password" name="confirm" required>
    <div id="pass-hints" class="hint"></div>
    <?php if ($err) echo '<p class="bad">'.e($err).'</p>'; ?>
    <?php if ($ok) echo '<p class="ok">'.e($ok).'</p>'; ?>
    <p><button class="btn btn-block" type="submit">ذخیره رمز جدید</button></p>
  </form>
</div>
<?php joma_footer(); ?>
