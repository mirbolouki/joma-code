<?php
require_login();
require_perm('VIEW_HISTORY');
$u = current_user();
$key = isset($_GET['period']) ? $_GET['period'] : current_period_key();
if (!preg_match('/^\d{4}-\d{2}$/', $key)) $key = jalali_period_key(jalali_today());
$wp = ensure_period($u['id'], $key);
$plan = $wp['plan'];
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['archive'])) {
        $msg = transition_plan($u['id'], $plan, 'ARCHIVED');
        $wp = ensure_period($u['id'], $key);
        $plan = $wp['plan'];
    } elseif (isset($_POST['work'])) {
        set_current_period_key($key);
        joma_redirect('index.php?p=dashboard');
    }
}
$acts = list_plan_activities($plan['id'], $u['id']);
$rep = build_report($u['id'], $plan);
joma_header('جزئیات دوره', array(
    array('label' => 'داشبورد', 'href' => joma_url('index.php?p=dashboard')),
    array('label' => 'دوره‌ها', 'href' => joma_url('index.php?p=periods')),
    array('label' => jalali_period_label($key)),
));
?>
<div class="page-head">
  <div>
    <a href="<?php echo e(joma_url('index.php?p=periods')); ?>">بازگشت به دوره‌ها</a>
    <h1><?php echo e(jalali_period_label($key)); ?></h1>
  </div>
  <?php echo status_badge($plan['status']); ?>
</div>
<div class="btn-row">
  <form method="post"><?php echo csrf_field(); ?><button class="btn sec" name="work" value="1">کار روی این دوره</button></form>
  <?php if ($plan['status'] === 'RUNNING') { ?>
  <form method="post"><?php echo csrf_field(); ?><button class="btn" name="archive" value="1">بایگانی دوره</button></form>
  <?php } ?>
  <a class="btn ghost" href="<?php echo e(joma_url('index.php?p=reports&period='.$key)); ?>">گزارش کامل</a>
</div>
<?php if ($msg) echo '<p class="toast bad">'.e($msg).'</p>'; ?>
<section class="card">
  <h2>برنامه این دوره</h2>
  <?php if (!$acts) echo '<p class="lede">فعالیتی در این دوره نیست.</p>';
  foreach ($acts as $a) {
      echo '<div style="background:#f6f1ea;border-radius:16px;padding:12px 16px;margin:8px 0">'.e($a['sticker'].' '.$a['name']).' · هدف '.e(format_value($a['data_type'], $a['target_value'], $a['unit'])).' · وزن '.fa_num($a['weight']).'</div>';
  } ?>
</section>
<div class="grid grid-3">
  <div class="card stat"><div class="k">رویدادها</div><div class="v"><?php echo fa_num($rep['source_event_count']); ?></div></div>
  <div class="card stat"><div class="k">فعالیت‌ها</div><div class="v"><?php echo fa_num(count($rep['activities'])); ?></div></div>
  <div class="card stat"><div class="k">روزهای خلق</div><div class="v"><?php echo fa_num(count($rep['moods'])); ?></div></div>
</div>
<?php echo unspecified_notice('فرمول Achievement و موفقیت کلی تعریف نشده است. درصد ساختگی نشان داده نمی‌شود.'); ?>
<?php joma_footer(); ?>
