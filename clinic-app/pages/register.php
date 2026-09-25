<?php
if (current_user()) joma_redirect('index.php?p=dashboard');
$err = '';
$old = array('first_name'=>'','last_name'=>'','username'=>'','email'=>'','phone'=>'','job'=>'سایر');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $in = array(
        'first_name' => trim(isset($_POST['first_name']) ? $_POST['first_name'] : ''),
        'last_name' => trim(isset($_POST['last_name']) ? $_POST['last_name'] : ''),
        'username' => strtolower(trim(isset($_POST['username']) ? $_POST['username'] : '')),
        'email' => strtolower(trim(isset($_POST['email']) ? $_POST['email'] : '')),
        'phone' => trim(isset($_POST['phone']) ? $_POST['phone'] : ''),
        'job' => trim(isset($_POST['job']) ? $_POST['job'] : ''),
        'password' => isset($_POST['password']) ? $_POST['password'] : '',
        'confirm' => isset($_POST['confirm']) ? $_POST['confirm'] : '',
        'accept' => !empty($_POST['accept']),
    );
    $old = $in;
    $err = '';
    if (function_exists('joma_registration_gate_check')) {
        $posted_code = function_exists('joma_registration_gate_posted_code')
            ? joma_registration_gate_posted_code()
            : (isset($_POST['security_code']) ? $_POST['security_code'] : null);
        $err = joma_registration_gate_check($posted_code);
    }
    if ($err === '') {
        $err = validate_registration($in);
    }
    if ($err === '') {
        $u = create_user($in);
        $_SESSION['user'] = session_user_array($u);
        joma_redirect('index.php?p=mood');
    }
}
joma_header('ثبت‌نام', array(), array('public' => 1));
?>
<div class="card auth-card">
  <div style="display:flex;justify-content:space-between;align-items:center">
    <?php echo joma_logo(56); ?>
    <a href="<?php echo e(joma_url('index.php?p=home')); ?>">بازگشت به معرفی</a>
  </div>
  <h1>ساخت حساب جوما</h1>
  <p class="lede">با پذیرش قوانین، حساب محلی خود را می‌سازید.</p>
  <div class="mode-tabs">
    <a href="<?php echo e(joma_url('index.php?p=login')); ?>">ورود</a>
    <a class="on" href="<?php echo e(joma_url('index.php?p=register')); ?>">ثبت‌نام</a>
    <a href="<?php echo e(joma_url('index.php?p=forgot')); ?>">فراموشی</a>
  </div>
  <form method="post" action="<?php echo e(joma_url('index.php?p=register')); ?>">
    <?php echo csrf_field(); ?>
    <div class="field-row">
      <div><label>نام</label><input name="first_name" value="<?php echo e($old['first_name']); ?>" required></div>
      <div><label>نام خانوادگی</label><input name="last_name" value="<?php echo e($old['last_name']); ?>" required></div>
    </div>
    <label>نام کاربری</label>
    <input name="username" data-live-username dir="ltr" value="<?php echo e($old['username']); ?>" required>
    <div id="user-hints" class="hint"></div>
    <label>شماره موبایل</label>
    <input name="phone" data-live-phone dir="ltr" placeholder="09123456789" value="<?php echo e($old['phone']); ?>" required>
    <div id="phone-hints" class="hint"></div>
    <label>ایمیل</label>
    <input type="email" name="email" data-live-email dir="ltr" value="<?php echo e($old['email']); ?>" required>
    <div id="email-hints" class="hint"></div>
    <label>شغل</label>
    <select name="job"><?php foreach (jobs_list() as $j) echo '<option'.($old['job']===$j?' selected':'').'>'.e($j).'</option>'; ?></select>
    <label>رمز عبور</label><input type="password" name="password" required>
    <label>تکرار رمز عبور</label><input type="password" name="confirm" required>
    <div id="pass-hints" class="hint"></div>
    <label class="check">
      <input type="checkbox" name="accept" value="1" required>
      <span>قوانین و فلسفه جوما را می‌پذیرم. جوما محصول خودمدیریتی است: برنامه‌ریزی → اجرا → اندازه‌گیری → فهمیدن → بهبود. <a href="<?php echo e(joma_url('index.php?p=about')); ?>">درباره جوما</a></span>
    </label>
    <?php
    if (function_exists('joma_registration_gate_ui')) {
        echo joma_registration_gate_ui(isset($_POST['security_code']) && is_string($_POST['security_code']) ? $_POST['security_code'] : '');
    }
    ?>
    <?php if ($err) echo '<p class="bad">'.e($err).'</p>'; ?>
    <p><button class="btn btn-block" type="submit">ساخت حساب</button></p>
  </form>
</div>
<?php joma_footer(); ?>
