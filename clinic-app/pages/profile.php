<?php
require_login();
$u = current_user();
joma_header('پروفایل', array(array('label' => 'داشبورد', 'href' => joma_url('index.php?p=dashboard')), array('label' => 'پروفایل')));
$rows = array(
    'نام' => $u['first_name'],
    'نام خانوادگی' => $u['last_name'],
    'نام کاربری' => $u['username'],
    'موبایل' => $u['phone'],
    'ایمیل' => $u['email'],
    'شغل' => $u['job'],
    'سطح دسترسی' => access_label($u['access_level']),
    'نقش' => role_label($u['role_key']),
    'تاریخ عضویت' => substr($u['created_at'], 0, 10),
);
?>
<div class="page-head">
  <div>
    <h1>پروفایل من</h1>
    <p class="lede">امروز <?php echo e(jalali_format(jalali_today())); ?></p>
  </div>
</div>
<div class="card profile-list">
<?php foreach ($rows as $k => $v) echo '<div class="row"><span class="k">'.e($k).'</span><span>'.e($v ? $v : '—').'</span></div>'; ?>
</div>
<p><a class="btn sec" href="<?php echo e(joma_url('index.php?p=settings')); ?>">ویرایش در تنظیمات</a></p>
<?php joma_footer(); ?>
