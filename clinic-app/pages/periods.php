<?php
require_login();
$u = current_user();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['year'])) {
    csrf_check();
    $key = sprintf('%04d-%02d', (int) $_POST['year'], (int) $_POST['month']);
    ensure_period($u['id'], $key);
    set_current_period_key($key);
    if (!empty($_POST['work'])) joma_redirect('index.php?p=dashboard');
    joma_redirect('index.php?p=period&period=' . $key);
}
$list = list_periods($u['id']);
$t = explode('-', jalali_today());
joma_header('دوره‌های من', array(array('label' => 'داشبورد', 'href' => joma_url('index.php?p=dashboard')), array('label' => 'دوره‌ها')));
?>
<div class="page-head">
  <div>
    <h1>دوره‌های من</h1>
    <p class="lede">هر دوره تاریخچه مستقل خودش را حفظ می‌کند.</p>
  </div>
</div>
<form class="card grid grid-3" method="post">
  <?php echo csrf_field(); ?>
  <div><label>سال شمسی</label><input name="year" dir="ltr" value="<?php echo e($t[0]); ?>"></div>
  <div>
    <label>ماه</label>
    <select name="month"><?php for ($i = 1; $i <= 12; $i++) echo '<option value="'.$i.'" '.((int) $t[1] === $i ? 'selected' : '').'>'.jalali_month_name($i).'</option>'; ?></select>
  </div>
  <div><label>&nbsp;</label><button class="btn btn-block">ایجاد / انتخاب دوره</button></div>
</form>
<?php
if (!$list) echo empty_state('دوره‌ای نیست', 'هنوز دوره‌ای ساخته نشده است.');
foreach ($list as $p) {
    echo '<div class="card" style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px">';
    echo '<a href="'.e(joma_url('index.php?p=period&period='.$p['period_key'])).'"><h3 style="margin:0">'.e(jalali_period_label($p['period_key'])).'</h3><p class="meta" dir="ltr">'.$p['start_date'].' — '.$p['end_date'].'</p></a>';
    echo '<div class="btn-row">'.status_badge($p['plan_status']);
    echo '<a class="btn sec" href="'.e(joma_url('index.php?p=period&period='.$p['period_key'])).'">جزئیات</a>';
    echo '<a class="btn" href="'.e(joma_url('index.php?p=dashboard&period='.$p['period_key'])).'">کار روی این دوره</a>';
    echo '</div></div>';
}
joma_footer();
