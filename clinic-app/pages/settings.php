<?php
require_login();
$u = current_user();
$prefs = get_prefs($u['id']);
$ok = '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $phone = trim($_POST['phone']);
    if ($phone && !is_valid_iran_mobile($phone)) {
        $err = 'شماره موبایل باید مانند 09123456789 باشد.';
    } elseif (!in_array($_POST['job'], jobs_list(), true)) {
        $err = 'شغل را از فهرست انتخاب کنید.';
    } else {
        $nu = update_user($u['id'], array(
            'first_name' => trim($_POST['first_name']),
            'last_name' => trim($_POST['last_name']),
            'phone' => $phone,
            'job' => trim($_POST['job']),
        ));
        save_prefs($u['id'], !empty($_POST['compact']), !empty($_POST['notify']));
        $_SESSION['user'] = session_user_array($nu);
        $u = current_user();
        $prefs = get_prefs($u['id']);
        $ok = 'ذخیره شد.';
    }
}
joma_header('تنظیمات', array(array('label' => 'داشبورد', 'href' => joma_url('index.php?p=dashboard')), array('label' => 'تنظیمات')));
?>
<div class="page-head">
  <div>
    <h1>تنظیمات</h1>
    <p class="lede">حساب، نمایش و اعلان‌ها از همین‌جا گسترش می‌یابند.</p>
  </div>
</div>
<form class="card" method="post">
  <?php echo csrf_field(); ?>
  <h2>تنظیمات حساب</h2>
  <div class="field-row">
    <div><label>نام</label><input name="first_name" value="<?php echo e($u['first_name']); ?>"></div>
    <div><label>نام خانوادگی</label><input name="last_name" value="<?php echo e($u['last_name']); ?>"></div>
  </div>
  <label>موبایل</label><input name="phone" dir="ltr" value="<?php echo e($u['phone']); ?>">
  <label>شغل</label>
  <select name="job"><?php foreach (jobs_list() as $j) echo '<option '.($u['job'] === $j ? 'selected' : '').'>'.e($j).'</option>'; ?></select>
  <h2>نمایش</h2>
  <label class="check"><input type="checkbox" name="compact" <?php echo !empty($prefs['compact_cards']) ? 'checked' : ''; ?>> کارت‌های فشرده</label>
  <h2>اعلان‌ها</h2>
  <label class="check"><input type="checkbox" name="notify" <?php echo !empty($prefs['notifications_enabled']) ? 'checked' : ''; ?>> اعلان‌ها (فعلاً فقط ترجیح ذخیره می‌شود و SMS ارسال نمی‌شود)</label>
  <button class="btn">ذخیره</button>
  <?php if ($ok) echo '<p class="ok">'.e($ok).'</p>'; ?>
  <?php if ($err) echo '<p class="bad">'.e($err).'</p>'; ?>
</form>
<?php joma_footer(); ?>
