<?php
/**
 * View layer of the analytical add-ons — JOMA_ANALYTICS_V1.
 *
 * Everything here is additive: it renders NEW blocks (mood chart, weekly
 * table, heatmap calendar, period comparison) next to the frozen success tab.
 * It reads from includes/analytics.php and never touches the success engine.
 *
 * Chart rules are the same ones that fixed the 2x2 dashboard: every canvas
 * lives in a fixed-height .chart-box, every chart runs with
 * responsive:true + maintainAspectRatio:false, and creation is wrapped so one
 * failure cannot take the others down.
 */

if (!function_exists('render_analytics_sections')) {

require_once dirname(__FILE__) . '/analytics.php';

/** Percentage text for the analytics screens (null -> em dash). */
function an_pct($value, $digits = 0) {
    if ($value === null) return '—';
    $v = (float) $value * 100;
    if ($v < 0) $v = 0;
    if ($digits > 0) return fa_num(number_format($v, $digits, '.', '')) . '٪';
    if ($v > 0 && $v < 1) return '&lt; ۱٪';
    return fa_num(round($v)) . '٪';
}

/**
 * The fixed-height chart boxes, emitted once per request.
 *
 * These rules are what keep a canvas from feeding its own size back into
 * Chart.js, so they must be present on EVERY page that draws a chart — the
 * success dashboard, the mood chart and the period comparison alike.
 */
function analytics_chart_css() {
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<style>'
       . '.success-charts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:10px}' . "\n"
       . '@media (max-width:760px){.success-charts{grid-template-columns:1fr}}' . "\n"
       . '.chart-card{border:1px solid #efeaf6;border-radius:14px;background:#fffdfa;padding:10px 12px;min-width:0}' . "\n"
       . '.chart-card h4{margin:0 0 6px;font-size:13px;font-weight:700;color:#4c3a6b}' . "\n"
       . '.chart-box{position:relative;width:100%;height:260px;overflow:hidden}' . "\n"
       . '.chart-box.tall{height:280px}' . "\n"
       . '.chart-box canvas{display:block}' . "\n"
       . '.chart-empty{margin:0;height:100%;display:flex;align-items:center;justify-content:center;'
       . 'text-align:center;color:#8a8296;font-size:13px;background:#fbf9fd;border-radius:12px}' . "\n"
       . '</style>';
}

/** Emit the local Chart.js bundle once per request. */
function analytics_chartjs_tag() {
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<script src="' . e(joma_url('assets/js/chart.min.js')) . '" id="joma-chartjs"></script>';
}

/* ------------------------------------------------------------------ */
/* 1) Mood trends + visual correlation                                  */
/* ------------------------------------------------------------------ */

function render_analytics_mood($s, $moods, $opts = array()) {
    $canvasId = isset($opts['canvas']) ? $opts['canvas'] : 'ch-mood';
    $toggleId = isset($opts['toggle']) ? $opts['toggle'] : 'mood-toggle';
    $daily = analytics_daily_rows($s, $moods);
    $ms = analytics_mood_series($daily);
    $corr = analytics_correlation($ms);
    $moodDays = 0;
    foreach ($daily['rows'] as $r) if ($r['mood']) $moodDays++;

    if (!$moodDays && $corr['n'] === 0 && !$daily['meta']['activity_count']) {
        echo '<div class="card"><h3 style="margin:0 0 4px">نوسان خلق‌وخو و پیوند آن با انجام کارها</h3>';
        echo '<p class="hint" style="margin:0">برای این دوره هنوز داده‌ای ثبت نشده است.</p></div>';
        return;
    }

    $payload = array(
        'labels' => $ms['labels'],
        'success' => $ms['success'],
        'index' => $ms['index'],
        'series' => $ms['series'],
        'metrics' => array(),
    );
    foreach ($ms['metrics'] as $key => $m) {
        $payload['metrics'][] = array('key' => $key, 'label' => $m['label'], 'color' => $m['color']);
    }
    $json = function_exists('analytics_json') ? analytics_json($payload) : '{}';

    echo analytics_chart_css();
    echo '<div class="card"><h3 style="margin:0 0 4px">نوسان خلق‌وخو و پیوند آن با انجام کارها</h3>';
    echo '<p class="hint" style="margin:0 0 8px">پنج شاخصِ حالِ روزانه در طول ماه؛ برای مقایسه با درصد موفقیتِ همان روز، '
       . 'خط بنفشِ «درصد موفقیت» را با دکمه‌ی زیر روشن و خاموش کنید.</p>';

    if ($corr['r'] === null) {
        echo '<p class="meta" style="margin:0 0 8px">همبستگی: ' . ($corr['n'] < 3
            ? 'دادهٔ مشترکِ کافی نیست (کمتر از ۳ روزِ مشترک)'
            : 'قابل محاسبه نیست') . '</p>';
    } else {
        $dir = $corr['r'] > 0.15 ? 'مثبت' : ($corr['r'] < -0.15 ? 'معکوس' : 'نزدیک به صفر');
        echo '<p class="meta" style="margin:0 0 8px">همبستگیِ حال و موفقیت: '
           . '<strong>' . fa_num(number_format($corr['r'], 2, '.', '')) . '</strong> (' . $dir
           . ') — بر اساس ' . fa_num($corr['n']) . ' روز که هم خلق و هم عملکرد ثبت شده است.</p>';
    }

    echo '<p style="margin:0 0 8px"><button type="button" class="btn sec" id="' . e($toggleId) . '" '
       . 'aria-pressed="true">پنهان کردن خطِ موفقیت</button></p>';
    echo '<div class="chart-box tall"><canvas id="' . e($canvasId) . '"></canvas></div>';
    echo '</div>';
    echo analytics_chartjs_tag();
    ?>
<script>
(function () {
  var d = <?php echo $json; ?>;
  var canvasId = <?php echo json_encode($canvasId, JSON_UNESCAPED_UNICODE); ?>;
  var toggleId = <?php echo json_encode($toggleId, JSON_UNESCAPED_UNICODE); ?>;
  function build() {
    if (typeof Chart === 'undefined') return;
    if (!document.getElementById(canvasId)) return;
    var old = (typeof Chart.getChart === 'function') ? Chart.getChart(document.getElementById(canvasId)) : null;
    if (old) old.destroy();

    var sets = [];
    for (var i = 0; i < d.metrics.length; i++) {
      sets.push({
        type: 'line',
        label: d.metrics[i].label,
        data: (d.series[d.metrics[i].key] || []),
        borderColor: d.metrics[i].color,
        backgroundColor: d.metrics[i].color,
        yAxisID: 'y',
        tension: 0.35, pointRadius: 2, borderWidth: 2, fill: false, spanGaps: true
      });
    }
    var successIndex = sets.length;
    sets.push({
      type: 'line',
      label: 'درصد موفقیت روزانه',
      data: (d.success || []),
      borderColor: '#5b3fa8',
      backgroundColor: 'rgba(91,63,168,0.10)',
      yAxisID: 'y1',
      tension: 0.35, pointRadius: 2, borderWidth: 2, borderDash: [6, 4], fill: false, spanGaps: true
    });

    var chart;
    try {
      chart = new Chart(document.getElementById(canvasId), {
        type: 'line',
        data: { labels: d.labels, datasets: sets },
        options: {
          responsive: true, maintainAspectRatio: false, resizeDelay: 80,
          interaction: { mode: 'index', intersect: false },
          scales: {
            y: { position: 'right', min: 1, max: 5, title: { display: true, text: 'شاخص‌های حال (۱ تا ۵)' },
                 grid: { color: 'rgba(124,92,191,0.08)' }, ticks: { stepSize: 1 } },
            y1: { position: 'left', min: 0, max: 100, title: { display: true, text: 'درصد موفقیت روزانه' },
                  grid: { display: false }, ticks: { callback: function (v) { return v + '%'; } } },
            x: { grid: { display: false }, ticks: { maxTicksLimit: 12, font: { size: 10 } } }
          },
          plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
            tooltip: { callbacks: { label: function (c) {
              if (c.dataset.yAxisID === 'y1') return c.dataset.label + ': ' + c.parsed.y + '%';
              return c.dataset.label + ': ' + c.parsed.y;
            } } }
          }
        }
      });
    } catch (err) {
      if (window.console && console.error) console.error('joma chart ' + canvasId, err);
      return;
    }

    var btn = document.getElementById(toggleId);
    if (btn) {
      btn.addEventListener('click', function () {
        var vis = !chart.isDatasetVisible(successIndex);
        chart.setDatasetVisibility(successIndex, vis);
        chart.update();
        btn.setAttribute('aria-pressed', vis ? 'true' : 'false');
        btn.textContent = vis ? 'پنهان کردن خطِ موفقیت' : 'نمایش خطِ موفقیت';
      });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', build);
  else build();
})();
</script>
<?php
}

/* ------------------------------------------------------------------ */
/* 1b) The daily success rate on its own (the reports «روند» tab)       */
/* ------------------------------------------------------------------ */

/**
 * The daily success rate as a standalone smooth area chart — the very same
 * series the success tab shows, so the «روند» tab no longer draws a raw sum.
 */
function render_analytics_daily_chart($s, $canvasId = 'ch-daily') {
    $daily = analytics_daily_rows($s);
    $labels = array();
    $rate = array();
    $scored = 0;
    foreach ($daily['rows'] as $r) {
        $labels[] = fa_num($r['day']);
        if ($r['success'] === null) {
            $rate[] = null;
        } else {
            $rate[] = round($r['success'] * 100, 1);
            $scored++;
        }
    }
    $payload = array('labels' => $labels, 'rate' => $rate);
    $json = function_exists('analytics_json') ? analytics_json($payload) : '{}';

    echo analytics_chart_css();
    echo '<div class="card"><h3 style="margin:0 0 4px">درصد موفقیتِ هر روزِ ماه</h3>';
    echo '<p class="hint" style="margin:0 0 8px">هر نقطه یعنی آن روز چند درصد از هدف‌های روزانه محقق شده است؛ '
       . 'روزهای بدون ثبت، صفر حساب نشده‌اند و خط آن‌ها را رد می‌کند.</p>';
    if (!$scored) {
        echo '<div class="chart-box"><p class="chart-empty">برای روندِ روزانه هنوز داده‌ای ثبت نشده است.</p></div>';
        echo '</div>';
        return;
    }
    echo '<div class="chart-box"><canvas id="' . e($canvasId) . '"></canvas></div>';
    echo '</div>';
    echo analytics_chartjs_tag();
    ?>
<script>
(function () {
  var d = <?php echo $json; ?>;
  var canvasId = <?php echo json_encode($canvasId, JSON_UNESCAPED_UNICODE); ?>;
  function build() {
    if (typeof Chart === 'undefined') return;
    var el = document.getElementById(canvasId);
    if (!el) return;
    var old = (typeof Chart.getChart === 'function') ? Chart.getChart(el) : null;
    if (old) old.destroy();
    try {
      new Chart(el, {
        type: 'line',
        data: {
          labels: d.labels,
          datasets: [{
            label: 'درصد موفقیت روزانه', data: d.rate,
            borderColor: '#5b3fa8', borderWidth: 2, pointRadius: 2,
            fill: true, tension: 0.35, spanGaps: true,
            backgroundColor: function (ctx) {
              var chart = ctx.chart, c = chart.ctx, area = chart.chartArea;
              if (!area || !c || typeof c.createLinearGradient !== 'function') return 'rgba(91,63,168,0.18)';
              var g = c.createLinearGradient(0, area.top, 0, area.bottom);
              g.addColorStop(0, 'rgba(124,92,191,0.42)');
              g.addColorStop(1, 'rgba(124,92,191,0.02)');
              return g;
            }
          }]
        },
        options: {
          responsive: true, maintainAspectRatio: false, resizeDelay: 80,
          interaction: { mode: 'index', intersect: false },
          scales: {
            x: { grid: { display: false }, ticks: { maxTicksLimit: 15, font: { size: 10 } } },
            y: { beginAtZero: true, max: 100, grid: { color: 'rgba(124,92,191,0.08)' },
                 ticks: { callback: function (v) { return v + '%'; } } }
          },
          plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
            tooltip: { callbacks: { label: function (c) {
              return (c.parsed.y === null) ? 'ثبتی ندارد' : ('موفقیت ' + c.parsed.y + '%');
            } } }
          }
        }
      });
    } catch (err) {
      if (window.console && console.error) console.error('joma chart ' + canvasId, err);
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', build);
  else build();
})();
</script>
<?php
}

/* ------------------------------------------------------------------ */
/* 2) Week-by-week evaluation                                          */
/* ------------------------------------------------------------------ */

function render_analytics_weeks($s) {
    $daily = analytics_daily_rows($s);
    $weeks = analytics_weeks($daily);
    if (!$weeks) return;

    $best = null;
    foreach ($weeks as $w) {
        if ($w['success'] === null) continue;
        if ($best === null || $w['success'] > $weeks[$best]['success']) $best = $w['index'] - 1;
    }

    echo '<div class="card"><h3 style="margin:0 0 4px">ارزیابی هفته‌به‌هفته</h3>';
    echo '<p class="hint" style="margin:0 0 10px">هر هفته = ۷ روزِ تقویمی از ابتدای ماه. موفقیتِ هفته یعنی '
       . 'چقدر از هدف‌های روزانه‌ی آن هفته محقق شده؛ تداوم یعنی چند درصدِ ثبت‌های مورد انتظار انجام شده است.</p>';
    echo '<div class="table-wrap"><table class="tbl"><thead><tr>'
       . '<th>هفته</th><th>روزها</th><th>موفقیت</th><th>تداوم</th><th>روزهای دارای ثبت</th><th>تفسیر</th>'
       . '</tr></thead><tbody>';
    foreach ($weeks as $i => $w) {
        $range = fa_num($w['from']) . ' تا ' . fa_num($w['to']) . ' ' . e(jalali_month_name((int) substr($s['period_key'], 5, 2)));
        if ($w['active_days'] === 0) {
            $note = 'هنوز در این بازه روزی شروع نشده است';
        } elseif ($w['success'] === null) {
            $note = 'ثبتی در این هفته نیست';
        } elseif ($w['data_days'] === 0) {
            $note = 'در این هفته ثبتی انجام نشده است';
        } elseif ($best !== null && $i === $best) {
            $note = 'قوی‌ترین هفته‌ی این دوره';
        } else {
            $note = '';
        }
        $isBest = ($best !== null && $i === $best);
        echo '<tr' . ($isBest ? ' style="background:#f3fbf6"' : '') . '>';
        echo '<td>هفتهٔ ' . fa_num($w['index']) . '</td>';
        echo '<td>' . $range . '</td>';
        echo '<td><strong>' . an_pct($w['success']) . '</strong></td>';
        echo '<td>' . an_pct($w['coverage']) . '</td>';
        echo '<td>' . fa_num($w['data_days']) . ' از ' . fa_num($w['active_days']) . '</td>';
        echo '<td>' . ($isBest ? '<span class="chip" style="background:#e6f6ec">🏆 ' . e($note) . '</span>' : e($note)) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div></div>';
}

/* ------------------------------------------------------------------ */
/* 3) Heatmap calendar                                                 */
/* ------------------------------------------------------------------ */

function render_analytics_heatmap($s, $moods) {
    $daily = analytics_daily_rows($s, $moods);
    $b = jalali_period_bounds($s['period_key']);
    $pad = function_exists('jalali_weekday_index') ? jalali_weekday_index($b['start']) : 0;

    echo '<style>'
       . '.hm-wrap{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px;margin-top:8px}'
       . '.hm-head{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px}'
       . '.hm-head div{text-align:center;font-size:12px;color:#8a8296;padding:2px 0}'
       . '.hm-cell{border-radius:10px;min-height:58px;padding:4px;text-align:center;'
       . 'display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;font-size:12px}'
       . '.hm-cell .hm-day{font-weight:700;font-size:12px}'
       . '.hm-cell .hm-val{font-size:13px;font-weight:700}'
       . '.hm-cell .hm-dot{width:7px;height:7px;border-radius:50%;margin-top:2px}'
       . '.hm-high{background:#2e7d5b;color:#fff}'
       . '.hm-mid{background:#7cc79b;color:#14432f}'
       . '.hm-low{background:#f7c777;color:#5c4208}'
       . '.hm-none{background:#f1eefa;color:#8a8296}'
       . '.hm-future{background:#faf8fd;color:#c3bcd0;border:1px dashed #e5dfef}'
       . '.hm-legend{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;font-size:12px;color:#6b6478}'
       . '.hm-legend span{display:inline-flex;align-items:center;gap:5px}'
       . '.hm-legend i{width:12px;height:12px;border-radius:4px;display:inline-block}'
       . '</style>';

    echo '<div class="card"><h3 style="margin:0 0 4px">تقویمِ حرارتیِ ماه</h3>';
    echo '<p class="hint" style="margin:0">رنگِ هر خانه = درصد موفقیتِ همان روز؛ عددِ داخل خانه همان درصد است و '
       . 'نقطه‌ی زیرِ آن میانگینِ حالِ ثبت‌شده‌ی آن روز را نشان می‌دهد.</p>';
    echo '<div class="hm-head">';
    foreach (array('ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج') as $d) echo '<div>' . $d . '</div>';
    echo '</div><div class="hm-wrap">';
    for ($i = 0; $i < $pad; $i++) echo '<div></div>';

    foreach ($daily['rows'] as $r) {
        if ($r['future']) {
            echo '<div class="hm-cell hm-future" title="روزِ آینده"><span class="hm-day">' . fa_num($r['day']) . '</span></div>';
            continue;
        }
        $level = analytics_heat_level($r['success'], $r['events'] > 0);
        $moodIndex = null;
        if ($r['mood']) {
            $sum = 0.0;
            $n = 0;
            foreach (array('energy', 'general_mood', 'focus', 'sleep_quality') as $k) {
                if (isset($r['mood'][$k]) && $r['mood'][$k] !== '') { $sum += (float) $r['mood'][$k]; $n++; }
            }
            $moodIndex = ($n > 0) ? $sum / $n : null;
        }
        $title = fa_num($r['day']) . ' — ';
        if (!$r['active'] && !$r['future']) {
            $title .= 'پیش از شروع برنامه (خارج از محاسبه)';
        } else {
            $title .= analytics_heat_label($level);
        }
        if ($r['success'] !== null) $title .= ' (' . fa_num(round($r['success'] * 100)) . '٪)';
        if ($moodIndex !== null) $title .= ' · میانگین حال ' . fa_num(number_format($moodIndex, 1, '.', ''));
        echo '<div class="hm-cell hm-' . $level . '" title="' . e($title) . '">';
        echo '<span class="hm-day">' . fa_num($r['day']) . '</span>';
        echo '<span class="hm-val">' . ($r['success'] === null ? '—' : fa_num(round($r['success'] * 100)) . '٪') . '</span>';
        if ($moodIndex !== null) {
            echo '<span class="hm-dot" style="background:' . analytics_mood_dot($moodIndex) . '"></span>';
        }
        echo '</div>';
    }
    echo '</div>';
    echo '<div class="hm-legend">';
    echo '<span><i style="background:#2e7d5b"></i>۸۰٪ تا ۱۰۰٪</span>';
    echo '<span><i style="background:#7cc79b"></i>۵۰٪ تا ۷۹٪</span>';
    echo '<span><i style="background:#f7c777"></i>۱٪ تا ۴۹٪</span>';
    echo '<span><i style="background:#f1eefa"></i>بدون ثبت داده</span>';
    echo '<span><i style="background:#faf8fd;border:1px dashed #e5dfef"></i>روزِ آینده</span>';
    echo '<span><i style="background:' . analytics_mood_dot(5) . ';border-radius:50%"></i>نقطه = میانگین حالِ روز</span>';
    echo '</div></div>';
}

/* ------------------------------------------------------------------ */
/* 4) Period comparison                                                */
/* ------------------------------------------------------------------ */

function render_analytics_compare($a, $b, $labelA, $labelB) {
    $rows = array(
        array('label' => 'درصد موفقیت نهایی', 'key' => 'success', 'mode' => 'pct'),
        array('label' => 'نرخ پوشش (تداوم ثبت)', 'key' => 'coverage', 'mode' => 'pct'),
        array('label' => 'میانگین حال (۴ شاخص مثبت)', 'key' => 'mood', 'mode' => 'scale'),
        array('label' => 'میانگین استرس', 'key' => 'stress', 'mode' => 'scale'),
    );

    echo analytics_chart_css();
    echo '<div class="card"><h3 style="margin:0 0 4px">مقایسه‌ی دو دوره</h3>';
    echo '<p class="hint" style="margin:0 0 10px">مقایسه بر اساس درصد واقعی موفقیت، نرخ پوشش و میانگین شاخص‌های حال — '
       . 'نه شمارشِ خامِ رویدادها.</p>';
    echo '<div class="table-wrap"><table class="tbl"><thead><tr>'
       . '<th>شاخص</th><th>' . e($labelA) . '</th><th>' . e($labelB) . '</th><th>تغییر</th>'
       . '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $va = $a[$r['key']];
        $vb = $b[$r['key']];
        $dl = analytics_delta($va, $vb);
        if ($r['mode'] === 'pct') {
            $ta = an_pct($va);
            $tb = an_pct($vb);
        } else {
            $ta = ($va === null) ? '—' : fa_num(number_format((float) $va, 1, '.', '')) . ' از ۵';
            $tb = ($vb === null) ? '—' : fa_num(number_format((float) $vb, 1, '.', '')) . ' از ۵';
        }
        if (!$dl['has_data']) {
            $change = '—';
        } elseif ($dl['flat']) {
            $change = 'بدون تغییر';
        } else {
            $sign = $dl['up'] ? '+' : '−';
            $points = abs($dl['points']);
            $txt = $sign . fa_num(number_format($points, 1, '.', '')) . ' واحد';
            if ($r['mode'] === 'pct') $txt .= ' درصد';
            if ($dl['percent'] !== null) {
                $txt .= ' (' . ($dl['up'] ? 'رشد ' : 'افت ') . fa_num(round(abs($dl['percent']))) . '٪)';
            }
            $color = $r['key'] === 'stress'
                ? ($dl['up'] ? '#c0392b' : '#2e7d5b')     // more stress is worse
                : ($dl['up'] ? '#2e7d5b' : '#c0392b');
            $arrow = $dl['up'] ? '▲' : '▼';
            $change = '<span style="color:' . $color . ';font-weight:700">' . $arrow . ' ' . e($txt) . '</span>';
        }
        echo '<tr><td>' . e($r['label']) . '</td><td><strong>' . $ta . '</strong></td>'
           . '<td><strong>' . $tb . '</strong></td><td>' . $change . '</td></tr>';
    }
    echo '<tr><td>روزهای ثبتِ خلق</td><td>' . fa_num($a['mood_days']) . '</td><td>' . fa_num($b['mood_days'])
       . '</td><td>—</td></tr>';
    echo '</tbody></table></div>';

    $payload = array(
        'labels' => array('موفقیت نهایی', 'نرخ پوشش', 'میانگین حال (×۲۰)'),
        'a' => array(
            $a['success'] === null ? null : round($a['success'] * 100, 1),
            $a['coverage'] === null ? null : round($a['coverage'] * 100, 1),
            $a['mood'] === null ? null : round($a['mood'] * 20, 1),
        ),
        'b' => array(
            $b['success'] === null ? null : round($b['success'] * 100, 1),
            $b['coverage'] === null ? null : round($b['coverage'] * 100, 1),
            $b['mood'] === null ? null : round($b['mood'] * 20, 1),
        ),
        'nameA' => $labelA,
        'nameB' => $labelB,
    );
    $json = function_exists('analytics_json') ? analytics_json($payload) : '{}';

    echo '<p class="hint" style="margin:10px 0 0">برای اینکه «میانگین حال» (مقیاس ۱ تا ۵) کنارِ دو درصدِ دیگر '
       . 'دیده شود، در نمودار ضرب در ۲۰ شده است (مثلاً ۴ از ۵ می‌شود ۸۰).</p>';
    echo '<div class="chart-box"><canvas id="ch-cmp"></canvas></div>';
    echo '</div>';
    echo analytics_chartjs_tag();
    ?>
<script>
(function () {
  var d = <?php echo $json; ?>;
  function build() {
    if (typeof Chart === 'undefined') return;
    var el = document.getElementById('ch-cmp');
    if (!el) return;
    var old = (typeof Chart.getChart === 'function') ? Chart.getChart(el) : null;
    if (old) old.destroy();
    try {
      new Chart(el, {
        type: 'bar',
        data: {
          labels: d.labels,
          datasets: [
            { label: d.nameA, data: d.a, backgroundColor: 'rgba(124,92,191,0.85)', borderColor: '#7c5cbf',
              borderWidth: 1, borderRadius: 6, maxBarThickness: 46 },
            { label: d.nameB, data: d.b, backgroundColor: 'rgba(47,168,160,0.85)', borderColor: '#2fa8a0',
              borderWidth: 1, borderRadius: 6, maxBarThickness: 46 }
          ]
        },
        options: {
          responsive: true, maintainAspectRatio: false, resizeDelay: 80,
          scales: {
            y: { beginAtZero: true, min: 0, max: 100, grid: { color: 'rgba(124,92,191,0.08)' },
                 ticks: { callback: function (v) { return v + '%'; } } },
            x: { grid: { display: false } }
          },
          plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
            tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + c.parsed.y; } } }
          }
        }
      });
    } catch (err) {
      if (window.console && console.error) console.error('joma chart ch-cmp', err);
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', build);
  else build();
})();
</script>
<?php
}

/* ------------------------------------------------------------------ */
/* Bundle used by the success tab                                      */
/* ------------------------------------------------------------------ */

function render_analytics_sections($s, $moods = array()) {
    render_analytics_mood($s, $moods);
    render_analytics_weeks($s);
}

} // function_exists
