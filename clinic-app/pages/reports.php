<?php
require_login();
require_perm('VIEW_REPORT');
$u = current_user();
require_once dirname(__FILE__) . '/../includes/success_view.php';
$list = list_periods($u['id']);
$key = isset($_GET['period']) ? $_GET['period'] : current_period_key();
if (!preg_match('/^\d{4}-\d{2}$/', $key)) $key = jalali_period_key(jalali_today());
$wp = ensure_period($u['id'], $key);
$rep = build_report($u['id'], $wp['plan']);
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'overview';
$tabs = array(
    'overview' => 'خلاصه',
    'success' => 'تحلیل موفقیت',
    'acts' => 'فعالیت‌ها',
    'weight' => 'وزن',
    'cal' => 'تقویم',
    'trend' => 'روند',
    'mood' => 'خلق',
    'cmp' => 'مقایسه',
    'det' => 'جزئیات',
);
if (!isset($tabs[$tab])) $tab = 'overview';
$metrics = array(
    'energy' => 'انرژی',
    'general_mood' => 'حال عمومی',
    'focus' => 'تمرکز',
    'sleep_quality' => 'خواب',
    'stress' => 'استرس',
);
joma_header('گزارش‌ها', array(array('label' => 'داشبورد', 'href' => joma_url('index.php?p=dashboard')), array('label' => 'گزارش')));
?>
<div class="page-head">
  <div>
    <h1>گزارش‌ها</h1>
    <p class="lede">فقط دادهٔ همان دوره انتخاب‌شده.</p>
  </div>
  <form method="get">
    <input type="hidden" name="p" value="reports">
    <input type="hidden" name="tab" value="<?php echo e($tab); ?>">
    <?php echo function_exists('joma_sid_field') ? joma_sid_field() : ''; ?>
    <select name="period" onchange="this.form.submit()">
      <?php foreach ($list as $p) echo '<option value="'.e($p['period_key']).'" '.($p['period_key'] === $key ? 'selected' : '').'>'.e(jalali_period_label($p['period_key'])).'</option>'; ?>
    </select>
  </form>
</div>
<div class="tabs">
<?php foreach ($tabs as $k => $lab) {
    $cls = $tab === $k ? 'tab on' : 'tab';
    echo '<a class="'.$cls.'" href="'.e(joma_url('index.php?p=reports&period='.$key.'&tab='.$k)).'">'.$lab.'</a>';
} ?>
</div>
<?php
if ($tab === 'overview') {
    echo success_guide_card(joma_url('index.php?p=reports&period=' . $key . '&tab=success'));
    echo '<div class="grid grid-3">';
    echo '<div class="card stat"><div class="k">رویدادها</div><div class="v">'.fa_num($rep['source_event_count']).'</div></div>';
    echo '<div class="card stat"><div class="k">فعالیت‌ها</div><div class="v">'.fa_num(count($rep['activities'])).'</div></div>';
    echo '<div class="card stat"><div class="k">روزهای ثبت خلق</div><div class="v">'.fa_num(count($rep['moods'])).'</div></div>';
    echo '</div>';
} elseif ($tab === 'success') {
    render_success_tab($rep, joma_url('index.php?p=reports&period=' . $key . '&tab=success'));
} elseif ($tab === 'acts') {
    if (!$rep['activities']) echo empty_state('فعالیتی نیست', 'برای این دوره برنامه‌ای ثبت نشده.');
    foreach ($rep['activities'] as $a) {
        echo '<article class="card" style="display:flex;gap:12px;align-items:center">';
        echo '<div class="sticker" style="background:'.$a['color'].'">'.e($a['sticker']).'</div>';
        echo '<div><strong>'.e($a['name']).'</strong><p class="meta">'.e(frequencies_list()[$a['frequency']]).' · '.e(format_value($a['data_type'], $a['actual'], $a['unit'])).' / '.e(format_value($a['data_type'], $a['target_value'], $a['unit'])).'</p></div>';
        echo '</article>';
    }
} elseif ($tab === 'weight') {
    echo '<p class="lede">وزن ذخیره‌شدهٔ همین دوره — فرمول موفقیت کلی محاسبه نمی‌شود.</p>';
    if (!$rep['activities']) echo empty_state('وزنی برای نمایش نیست', 'ابتدا فعالیت به برنامه اضافه کنید.');
    $max = max(1, $rep['weight_sum']);
    foreach ($rep['activities'] as $a) {
        $pct = min(100, ((int) $a['weight'] / $max) * 100);
        echo '<div class="card weight-row"><div style="display:flex;justify-content:space-between"><span>'.e($a['sticker'].' '.$a['name']).'</span><span>اهمیت '.e(weight_label($a['weight'])).'</span></div><div class="bar"><i style="width:'.$pct.'%"></i></div></div>';
    }
} elseif ($tab === 'cal') {
    // Heatmap calendar (JOMA_ANALYTICS_V1): each day is coloured by its own
    // daily success rate, the percentage is printed inside the cell and a dot
    // shows the mood that was recorded that day. The old flower mark is gone.
    $calS = success_report($rep['plan'], $rep['bounds'], $rep['activities'], $rep['events'], jalali_today());
    render_analytics_heatmap($calS, $rep['moods']);
} elseif ($tab === 'trend') {
    // Same series as the success tab: the daily success rate, not a raw sum.
    $trendS = success_report($rep['plan'], $rep['bounds'], $rep['activities'], $rep['events'], jalali_today());
    render_analytics_daily_chart($trendS, 'ch-daily');
} elseif ($tab === 'mood') {
    if (!$rep['moods']) echo empty_state('خلق این دوره ثبت نشده است', 'هر روز می‌توانید پنج شاخص را ثبت کنید.');
    else {
        echo '<div class="card"><h3>میانگین شاخص‌ها (فقط داده واقعی)</h3><div class="mood-bars">';
        foreach ($metrics as $mk => $lab) {
            $s = 0; $n = 0;
            foreach ($rep['moods'] as $m) { $s += (int) $m[$mk]; $n++; }
            $avg = $n ? $s / $n : 0;
            echo '<div class="row"><span>'.e($lab).'</span><div class="bar"><i style="width:'.($avg / 5 * 100).'%"></i></div><strong>'.fa_num(round($avg, 1)).'</strong></div>';
        }
        echo '</div></div>';
        // The dedicated mood chart (same one as the success tab), so the mood
        // tab is a chart and not only averages.
        $moodS = success_report($rep['plan'], $rep['bounds'], $rep['activities'], $rep['events'], jalali_today());
        render_analytics_mood($moodS, $rep['moods'], array('canvas' => 'ch-mood-tab', 'toggle' => 'mood-toggle-tab'));
        foreach ($rep['moods'] as $m) {
            echo '<div class="card">'.e(jalali_format($m['jalali_date'])).' · انرژی '.fa_num($m['energy']).' · حال '.fa_num($m['general_mood']).' · تمرکز '.fa_num($m['focus']).' · خواب '.fa_num($m['sleep_quality']).' · استرس '.fa_num($m['stress']);
            if ($m['note']) echo '<p class="lede">'.e($m['note']).'</p>';
            echo '</div>';
        }
    }
} elseif ($tab === 'cmp') {
    $other = isset($_GET['other']) ? $_GET['other'] : '';
    echo '<form method="get" class="card"><input type="hidden" name="p" value="reports"><input type="hidden" name="tab" value="cmp"><input type="hidden" name="period" value="'.e($key).'">'
        . (function_exists('joma_sid_field') ? joma_sid_field() : '');
    echo '<label>دوره دوم</label><select name="other">';
    foreach ($list as $p) if ($p['period_key'] !== $key) echo '<option value="'.e($p['period_key']).'" '.($other === $p['period_key'] ? 'selected' : '').'>'.e(jalali_period_label($p['period_key'])).'</option>';
    echo '</select><p><button class="btn sec">مقایسه</button></p></form>';
    if (count($list) < 2) echo empty_state('برای مقایسه حداقل دو دوره لازم است', 'یک دوره دیگر بسازید.');
    elseif ($other) {
        $w2 = ensure_period($u['id'], $other);
        $r2 = build_report($u['id'], $w2['plan']);
        $todayJ = jalali_today();
        $sumA = analytics_period_summary($rep['plan'], $rep['bounds'], $rep['activities'], $rep['events'], $rep['moods'], $todayJ);
        $sumB = analytics_period_summary($r2['plan'], $r2['bounds'], $r2['activities'], $r2['events'], $r2['moods'], $todayJ);
        render_analytics_compare($sumA, $sumB, jalali_period_label($key), jalali_period_label($other));
        echo '<p class="hint">برای اطلاع: تعداد رویدادهای خام '
            . fa_num($rep['source_event_count']) . ' ('.e(jalali_period_label($key)).') در برابر '
            . fa_num($r2['source_event_count']) . ' ('.e(jalali_period_label($other)).') — شمارشِ خام معیارِ برتری نیست.</p>';
        echo success_guide_card(joma_url('index.php?p=reports&period=' . $key . '&tab=success'));
    }
} else {
    foreach ($rep['activities'] as $a) {
        echo '<div class="card"><h3>'.e($a['sticker'].' '.$a['name']).'</h3>';
        echo '<p class="lede">رویداد خام ← مقدار واقعی ← تحقق (تعریف‌نشده) ← اهمیت '.e(weight_label($a['weight'])).'</p><ul>';
        foreach ($a['events'] as $ev) echo '<li>'.e(jalali_format($ev['performance_date'])).' · '.e($ev['actual_value']).'</li>';
        if (!$a['events']) echo '<li class="lede">رویدادی نیست.</li>';
        echo '</ul></div>';
    }
    if (!$rep['activities']) echo empty_state('جزئیاتی نیست', 'فعالیتی در این دوره نیست.');
}
joma_footer();
