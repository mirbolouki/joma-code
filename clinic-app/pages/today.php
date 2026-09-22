<?php
require_login();
require_perm('RECORD_PERFORMANCE');
$u = current_user();
$key = current_period_key();
$wp = ensure_period($u['id'], $key);
$plan = $wp['plan'];
$b = jalali_period_bounds($key);
$date = jalali_in_period(jalali_today(), $key) ? jalali_today() : $wp['period']['start_date'];
if (isset($_GET['date']) && jalali_is_valid($_GET['date'])) $date = $_GET['date'];
if (isset($_POST['date']) && jalali_is_valid($_POST['date'])) $date = $_POST['date'];
$msg = '';
$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pa_id'])) {
    csrf_check();
    $pas = list_plan_activities($plan['id'], $u['id']);
    $pa = null;
    foreach ($pas as $x) if ((int) $x['id'] === (int) $_POST['pa_id']) $pa = $x;
    if ($pa) $msg = register_performance($u['id'], $plan, $pa, $_POST['date'], isset($_POST['value']) ? $_POST['value'] : '');
    if ($msg === '') { $ok = 'ثبت شد.'; $msg = ''; }
}
$pas = list_plan_activities($plan['id'], $u['id']);
$evs = list_events($plan['id'], $u['id']);
$groups = array('DAILY' => array(), 'WEEKLY' => array(), 'MONTHLY' => array());
foreach ($pas as $a) $groups[$a['frequency']][] = $a;
$labels = array('DAILY' => 'روزانه', 'WEEKLY' => 'هفتگی', 'MONTHLY' => 'ماهانه');
joma_header('امروز', array(array('label' => 'داشبورد', 'href' => joma_url('index.php?p=dashboard')), array('label' => 'امروز')));
?>
<div class="page-head">
  <div>
    <h1>فعالیت‌های قابل ثبت</h1>
    <p class="lede">دوره <?php echo e(jalali_period_label($plan['period_key'])); ?></p>
  </div>
</div>
<form method="get" class="card" style="max-width:360px">
  <input type="hidden" name="p" value="today">
  <?php echo function_exists('joma_sid_field') ? joma_sid_field() : ''; ?>
  <label>تاریخ عملکرد</label>
  <select name="date" onchange="this.form.submit()">
    <?php for ($d = 1; $d <= $b['days']; $d++) {
        $ds = $b['year'].'-'.jalali_pad($b['month']).'-'.jalali_pad($d);
        echo '<option value="'.$ds.'" '.($ds === $date ? 'selected' : '').'>'.e(jalali_weekday_name($ds).' '.jalali_format($ds)).'</option>';
    } ?>
  </select>
</form>
<?php
if ($plan['status'] !== 'RUNNING') echo empty_state('دوره در حال اجرا نیست', 'ابتدا برنامه را نهایی و شروع کنید.', joma_url('index.php?p=plan'), 'رفتن به برنامه');
if ($ok) echo '<p class="toast ok">'.e($ok).'</p>';
if ($msg) echo '<p class="toast bad">'.e($msg).'</p>';
foreach ($groups as $fk => $list) {
    echo '<h2>'.$labels[$fk].'</h2>';
    if (!$list) { echo '<p class="lede">فعالیتی با این تناوب نیست.</p>'; continue; }
    foreach ($list as $a) {
        $related = events_for($evs, $a['id']);
        $sum = displayed_actual($a, $evs, $date);
        $dailyLocked = $a['frequency'] === 'DAILY' && has_daily_registration($related, $date);
        $last = null;
        foreach ($related as $e) if ($e['performance_date'] === $date) $last = $e;
        echo '<article class="card perf">';
        echo '<div class="perf-head" style="background:'.$a['color'].'55">';
        echo '<div style="display:flex;gap:12px;align-items:center"><div class="sticker">'.e($a['sticker']).'</div><div><h3 style="margin:0">'.e($a['name']).'</h3><p class="meta">'.e($a['category']).' · '.e($labels[$a['frequency']]).' · '.e(datatypes_list()[$a['data_type']]).'</p></div></div>';
        echo '<span class="chip">اهمیت '.e(weight_label($a['weight'])).'</span></div>';
        echo '<div style="padding:18px">';
        echo '<p class="lede">مقدار ثبت‌شده: '.e(format_value($a['data_type'], $sum, $a['unit'])).' از هدف '.e(format_value($a['data_type'], $a['target_value'], $a['unit'])).'</p>';
        if ($plan['status'] === 'RUNNING') {
            if ($dailyLocked) {
                echo '<div class="done-box">ثبت شد ✓ '.($last ? e(format_value($a['data_type'], $last['actual_value'], $a['unit'])) : '').'</div>';
            } else {
                echo '<form method="post">'.csrf_field().'<input type="hidden" name="pa_id" value="'.e($a['id']).'"><input type="hidden" name="date" value="'.e($date).'">';
                if ($a['data_type'] === 'BOOLEAN') {
                    echo '<div class="bool-grid"><label><input type="radio" name="value" value="1" checked><span>انجام شد</span></label><label><input type="radio" name="value" value="0"><span>انجام نشد</span></label></div>';
                } elseif ($a['data_type'] === 'RATING') {
                    echo '<div class="rate-grid">';
                    $faces = array(1=>'😞',2=>'😐',3=>'🙂',4=>'😊',5=>'🤩');
                    foreach ($faces as $n => $f) echo '<label><input type="radio" name="value" value="'.$n.'" '.($n===3?'checked':'').'><span>'.$f.'</span></label>';
                    echo '</div>';
                } else {
                    echo '<input name="value" type="number" min="0" step="any" dir="ltr" required placeholder="'.($a['data_type']==='DURATION'?'مدت':'مقدار').'">';
                }
                echo '<p><button class="btn btn-block" type="submit">ثبت عملکرد</button></p></form>';
            }
        }
        if ($related) {
            echo '<details style="margin-top:10px"><summary class="lede">تاریخچه این فعالیت</summary><ul>';
            foreach ($related as $e) echo '<li>'.e(jalali_format($e['performance_date'])).' · '.e(format_value($a['data_type'], $e['actual_value'], $a['unit'])).'</li>';
            echo '</ul></details>';
        }
        echo '</div></article>';
    }
}
joma_footer();
