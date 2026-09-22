<?php
/**
 * View layer of the «تحلیل موفقیت» tab — JOMA_SUCCESS_V1.
 *
 * Pure rendering: it receives the output of build_report() (already loaded by
 * reports.php) and prints HTML. No queries, no writes, no session access, no
 * change to the success engine. Everything is server-rendered; the per-activity
 * cycle tables use native <details>, so no JavaScript is added either.
 */

// The bootstrap loads functions/joma.php but not the success engine, and this
// view cannot render without it, so it is loaded here (require_once is safe
// because the test harnesses load it too).
require_once dirname(__FILE__) . '/../functions/success.php';
// Analytical add-ons (daily / weekly views, mood correlation). They only READ
// what success_report() already produced; the frozen metric is untouched.
require_once dirname(__FILE__) . '/analytics.php';
require_once dirname(__FILE__) . '/analytics_view.php';

if (!function_exists('success_ui_pct')) {

/* ------------------------------------------------------------------ */
/* Display helpers                                                     */
/* ------------------------------------------------------------------ */

/**
 * Percentage for the UI: integer, "—" for a missing value, "< ۱٪" for a
 * positive value below one percent, and "۰٪" only for a real recorded zero.
 */
function success_ui_pct($value) {
    if ($value === null) return '—';
    $v = (float) $value;
    if ($v < 0) $v = 0.0;
    if ($v > 0 && $v < 0.01) return '&lt; ۱٪';
    return fa_num(round($v * 100)) . '٪';
}

/** Persian text of an activity / plan status. */
function success_ui_status_text($status) {
    $map = array(
        'OK'                     => 'محاسبه‌شده از دادهٔ واقعی',
        'NO_DATA'                => 'ثبتی انجام نشده',
        'NO_ELIGIBLE_CYCLES'     => 'هنوز یک دورهٔ کامل ندارد (چرخهٔ اول ناقص است)',
        'NOT_STARTED'            => 'برنامه هنوز شروع نشده است',
        'IN_PROGRESS'            => 'در جریان',
        'INSUFFICIENT_DATA'      => 'هیچ فعالیت وزن‌داری برای محاسبه وجود ندارد',
        'NO_ELIGIBLE_ACTIVITIES' => 'هیچ فعالیت قابل‌تحلیلی در این برنامه نیست',
        'INVALID_CONFIG'         => 'تنظیمات این فعالیت ناقص است (هدف یا واحد نامعتبر)',
        'UNSUPPORTED'            => 'نیازمند بازبینی تنظیمات',
        'EXCLUDED'               => 'صرفاً گزارش تحلیلی',
        'AMBIGUOUS_DUPLICATE'    => 'بیش از یک ثبت برای این دوره وجود دارد',
    );
    return isset($map[$status]) ? $map[$status] : (string) $status;
}

/** Persian text of a cycle status. */
function success_ui_cycle_text($status) {
    $map = array(
        'COMPLETED'                    => 'کامل',
        'IN_PROGRESS'                  => 'در جریان',
        'NOT_STARTED'                  => 'شروع نشده',
        'NOT_ELIGIBLE'                 => 'خارج از محاسبه',
        'NOT_ELIGIBLE_PARTIAL_START'   => 'شروع در میانه بازه',
        'NOT_ELIGIBLE_PARTIAL_ARCHIVE' => 'پایان پیش از موعد',
        'NOT_ELIGIBLE_YET'             => 'هنوز نرسیده',
    );
    return isset($map[$status]) ? $map[$status] : (string) $status;
}

/** True when a cycle row must be shown muted (future / not assessable). */
function success_ui_cycle_muted($status) {
    return in_array($status, array('NOT_ELIGIBLE_YET', 'NOT_ELIGIBLE', 'NOT_STARTED'), true);
}

/** Small chip with the status text. */
function success_ui_chip($status, $extra_style = '') {
    return '<span class="chip" style="font-size:11px' . ($extra_style ? ';' . $extra_style : '') . '">'
        . e(success_ui_status_text($status)) . '</span>';
}

/** Badge of the plan status, reusing the existing badge classes. */
function success_ui_plan_badge($status) {
    $cls = 'badge';
    if ($status === 'RUNNING') $cls .= ' badge-running';
    elseif ($status === 'ARCHIVED') $cls .= ' badge-archived';
    elseif ($status === 'PLANNING') $cls .= ' badge-planning';
    else $cls .= ' badge-draft';
    return '<span class="' . $cls . '">' . e(status_label($status)) . '</span>';
}

/** A bar with a percentage width. */
function success_ui_bar($pct) {
    $w = ($pct === null) ? 0 : max(0, min(100, round((float) $pct * 100)));
    return '<div class="bar"><i style="width:' . $w . '%"></i></div>';
}

/** The card that replaces the old "formula not defined" notices. */
function success_guide_card($href = '') {
    $html = '<div class="card"><h3 style="margin:0 0 6px">ارزیابی موفقیت</h3>';
    $html .= '<p class="lede" style="margin:0 0 10px">برای ارزیابی دقیق، مشاهدهٔ درصد موفقیت وزنی و تداوم ثبت، به تب «تحلیل موفقیت» مراجعه کنید.</p>';
    if ($href !== '') $html .= '<a class="btn sec" href="' . e($href) . '">رفتن به تحلیل موفقیت</a>';
    $html .= '</div>';
    return $html;
}

/* ------------------------------------------------------------------ */
/* Sections                                                            */
/* ------------------------------------------------------------------ */

function render_success_context($s) {
    echo '<div class="card" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">';
    echo success_ui_plan_badge($s['plan_status']);
    echo '<span class="chip">دوره ' . e(jalali_period_label($s['period_key'])) . '</span>';
    echo '<span class="chip">شروع: ' . e($s['started_jalali'] ? $s['started_jalali'] : '—') . '</span>';
    if (!empty($s['archived_jalali'])) echo '<span class="chip">بایگانی: ' . e($s['archived_jalali']) . '</span>';
    echo '<span class="chip">مرجع زمان: ' . e($s['today']) . ' (تاریخ امروزِ سرور)</span>';
    echo '<span class="chip">' . e($s['metric_version']) . '</span>';
    echo '</div>';
}

function render_success_overall($s) {
    $m = (int) $s['weighted_eligible_activities'];
    $n = (int) $s['weighted_observed_activities'];
    echo '<div class="card"><div class="kicker" style="color:var(--muted);font-size:13px">موفقیت این دوره</div>';
    echo '<div class="stat"><div class="v">' . success_ui_pct($s['overall_success']) . '</div></div>';
    echo success_ui_bar($s['overall_success']);
    // Rule: the headline number is never shown alone.
    if ($m > 0) {
        echo '<p class="meta" style="margin-top:8px">بر اساس ' . fa_num($n) . ' از ' . fa_num($m) . ' فعالیت</p>';
    }
    echo '<p class="meta">پوشش ' . success_ui_pct($s['overall_coverage']) . '</p>';
    echo '<p class="meta">' . e(success_ui_status_text($s['overall_status'])) . '</p>';
    echo '</div>';
}

function render_success_coverage($s) {
    $sumEligible = 0;
    $sumObserved = 0;
    foreach ($s['activities'] as $a) {
        if (!$a['in_weighted_eligible']) continue;
        $sumEligible += (int) $a['eligible_cycle_count'];
        $sumObserved += (int) $a['observed_cycle_count'];
    }
    echo '<div class="card"><div class="kicker" style="color:var(--muted);font-size:13px">تداوم ثبت (پوشش)</div>';
    echo '<div class="stat"><div class="v">' . success_ui_pct($s['overall_coverage']) . '</div></div>';
    echo success_ui_bar($s['overall_coverage']);
    echo '<p class="meta" style="margin-top:8px">' . fa_num($sumObserved) . ' چرخهٔ ثبت‌شده از '
        . fa_num($sumEligible) . ' چرخهٔ واجد شرط</p>';
    echo '<p class="hint">فقط فعالیت‌های وزن‌دار (مجموعهٔ M) در این عدد حساب می‌شوند.</p>';
    echo '</div>';
}

function render_success_cycles($a) {
    echo '<table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:8px">';
    echo '<tr style="color:var(--muted)"><th style="text-align:right;padding:4px">بازه</th>'
        . '<th style="text-align:right;padding:4px">وضعیت</th>'
        . '<th style="text-align:right;padding:4px">مقدار خام</th>'
        . '<th style="text-align:right;padding:4px">تحقق</th>'
        . '<th style="text-align:right;padding:4px">رویداد</th></tr>';
    foreach ($a['cycles'] as $c) {
        $muted = success_ui_cycle_muted($c['status']);
        $style = 'border-top:1px solid #efeaf6;padding:4px' . ($muted ? ';opacity:.45' : '');
        $label = success_ui_cycle_text($c['status']);
        if ($c['cycle_status'] === 'AMBIGUOUS_DUPLICATE') $label = 'تکراری — مبهم';
        $achv = $c['achievement'] === null ? '—' : success_ui_pct($c['achievement']);
        if ($c['status'] === 'IN_PROGRESS') $achv = 'زنده';
        $raw = ($c['raw_actual'] === null) ? '—' : e(format_value($a['data_type'], $c['raw_actual'], $a['unit']));
        $range = ($c['start'] === $c['end']) ? $c['start'] : ($c['start'] . ' … ' . $c['end']);
        echo '<tr>';
        echo '<td style="' . $style . '" dir="ltr">' . e($range) . '</td>';
        echo '<td style="' . $style . '">' . e($label) . '</td>';
        echo '<td style="' . $style . '">' . $raw . '</td>';
        echo '<td style="' . $style . '">' . $achv . '</td>';
        echo '<td style="' . $style . '">' . fa_num($c['raw_event_count']) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    // Raw events stay visible even for cycles that are excluded from the score.
    $hasEvents = false;
    foreach ($a['cycles'] as $c) if (!empty($c['events'])) { $hasEvents = true; break; }
    if ($hasEvents) {
        echo '<p class="hint">رویدادهای خام (حتی برای چرخه‌های خارج از محاسبه):</p><ul style="margin:0;padding-inline-start:18px">';
        foreach ($a['cycles'] as $c) {
            foreach ($c['events'] as $ev) {
                echo '<li>' . e(jalali_format($ev['performance_date'])) . ' ← '
                    . e(format_value($a['data_type'], $ev['actual_value'], $a['unit'])) . '</li>';
            }
        }
        echo '</ul>';
    }
}

/* ------------------------------------------------------------------ */
/* Charts (Chart.js, bundled locally)                                  */
/* ------------------------------------------------------------------ */

/** Vibrant, well-separated palette for the doughnut charts. */
function success_chart_palette() {
    return array('#7c5cbf', '#2fa8a0', '#f2a03d', '#e2568c', '#4f7ff5',
                 '#5fb865', '#e05a45', '#9a7be0', '#2fb3c9', '#f0c33c');
}

/** A finite float for Chart.js — never a string, never NaN/INF. */
function success_chart_num($v, $default = 0.0) {
    if ($v === null || $v === '' || is_array($v) || is_object($v)) return (float) $default;
    if (!is_numeric($v)) return (float) $default;
    $n = (float) $v;
    return is_finite($n) ? $n : (float) $default;
}

/** A finite integer for Chart.js (counts, day numbers). */
function success_chart_int($v, $default = 0) {
    return (int) round(success_chart_num($v, $default));
}

/** '#rrggbb' + alpha -> 'rgba(r,g,b,a)'. Falls back to the brand violet. */
function success_chart_rgba($hex, $alpha) {
    $hex = is_string($hex) ? trim($hex) : '';
    if ($hex !== '' && $hex[0] === '#') $hex = substr($hex, 1);
    if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) $hex = '7c5cbf';
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $a = success_chart_num($alpha, 1);
    if ($a < 0) $a = 0;
    if ($a > 1) $a = 1;
    return 'rgba(' . $r . ',' . $g . ',' . $b . ',' . rtrim(rtrim(number_format($a, 2, '.', ''), '0'), '.') . ')';
}

/** Everything the four charts need, computed once and handed to Chart.js. */
function success_chart_data($s) {
    $pct = function ($v) {
        if ($v === null) return 0.0;
        return round(success_chart_num((float) $v * 100, 0.0), 1);
    };

    // 1) Headline donut: success + the remainder, plus a coverage ring.
    $overall = $pct($s['overall_success']);
    $coverage = $pct($s['overall_coverage']);

    // 2) Horizontal bars: one per weighted activity, in the activity's colour.
    $bars = array();
    foreach ($s['activities'] as $a) {
        if (!$a['in_weighted_eligible']) continue;
        $color = !empty($a['color']) ? $a['color'] : '#7c5cbf';
        $bars[] = array(
            'label' => (string) $a['activity_title'],
            'value' => $pct($a['activity_achievement']),          // always a float
            'fill' => success_chart_rgba($color, 0.85),           // the activity's own colour
            'border' => $color,
            'coverage' => $pct($a['coverage']),
        );
    }
    usort($bars, function ($x, $y) { return ($y['value'] - $x['value']) ? ($y['value'] - $x['value']) : 0; });

    // 3) Daily Success Rate (JOMA_ANALYTICS_V1) — one point per day of the
    //    period; days without any registration stay NULL so the line connects
    //    the days that do have data instead of dropping to zero.
    $b = jalali_period_bounds($s['period_key']);
    $days = isset($b['days']) ? (int) $b['days'] : 30;
    if ($days < 1) $days = 30;                 // never hand Chart.js an empty axis
    if ($days > 31) $days = 31;
    $labels = array();
    $rate = array();
    $scored = 0;
    $daily = function_exists('analytics_daily_rows') ? analytics_daily_rows($s) : null;
    if ($daily) {
        foreach ($daily['rows'] as $r) {
            $labels[] = fa_num($r['day']);
            if ($r['success'] === null) {
                $rate[] = null;
            } else {
                $rate[] = round($r['success'] * 100, 1);
                $scored++;
            }
        }
    } else {
        for ($d = 1; $d <= $days; $d++) { $labels[] = fa_num($d); $rate[] = null; }
    }

    // 4) Share of each category, by weighted contribution.
    //    `category` is a display field that build_report() attaches; when the
    //    tab is rendered from the engine alone it may be absent.
    $catOf = function ($a) {
        $c = isset($a['category']) ? trim((string) $a['category']) : '';
        return $c !== '' ? $c : 'بدون دسته';
    };
    $byCat = array();
    foreach ($s['activities'] as $a) {
        if (!$a['in_weighted_eligible']) continue;
        $cat = $catOf($a);
        if (!isset($byCat[$cat])) $byCat[$cat] = 0;
        $byCat[$cat] += (float) ($a['weighted_contribution'] === null ? 0 : $a['weighted_contribution']);
    }
    if ($byCat) {
        $sum = array_sum($byCat);
        if ($sum <= 0) { // fall back to the number of activities per category
            $byCat = array();
            foreach ($s['activities'] as $a) {
                if (!$a['in_weighted_eligible']) continue;
                $cat = $catOf($a);
                if (!isset($byCat[$cat])) $byCat[$cat] = 0;
                $byCat[$cat] += 1;
            }
        }
    }
    arsort($byCat);

    $catValues = array();
    foreach (array_values($byCat) as $v) $catValues[] = success_chart_num($v, 0.0);

    return array(
        'overall' => success_chart_num($overall, 0.0),
        'coverage' => success_chart_num($coverage, 0.0),
        'has_overall' => $s['overall_success'] !== null,
        'bars' => $bars,
        'has_bars' => count($bars) > 0,
        'days' => $labels,
        'rate' => $rate,
        'has_trend' => $scored > 0,
        'categories' => array_keys($byCat),
        'category_values' => $catValues,
        // A doughnut whose slices are all 0 draws nothing at all — the box
        // would stay dead white. Treat "nothing achieved" as "no data".
        'has_cats' => count($byCat) > 0 && array_sum($catValues) > 0,
        'barThickness' => count($bars) > 10 ? 'flex' : 18,
        'palette' => success_chart_palette(),
    );
}

/** The four canvases plus the inline script that draws them. */
/**
 * The four canvases plus the inline script that draws them.
 *
 * Layout rule (learned the hard way): every <canvas> lives inside a
 * .chart-box with a FIXED height, and every chart runs with
 * `maintainAspectRatio: false`. Without the fixed-height parent the bar
 * chart feeds its own size back into Chart.js and grows down the whole
 * page, and the resize storm also blanks the doughnuts.
 */
function render_success_charts($s) {
    $d = success_chart_data($s);
    $json = function_exists('analytics_json') ? analytics_json($d) : '{}';
    // One <script> for the whole page: loading the bundle twice replaces
    // window.Chart with a second copy whose registry no longer knows the
    // charts already drawn.
    // The fixed-height box rules live in one place so every page that draws a
    // chart (dashboard, mood, comparison) uses exactly the same constraint.
    echo function_exists('analytics_chart_css') ? analytics_chart_css() : '';
    // Roomier boxes for the dashboard only: the doughnuts need 320px so the
    // full ring (and its legend) is never clipped by overflow:hidden.
    echo '<style>.success-charts .chart-box{height:320px;padding:6px 0 10px;'
       . 'box-sizing:border-box}.success-charts .chart-box.tall{height:340px}</style>';
    echo '<div class="card"><h3 style="margin:0 0 4px">نگاهِ تصویری این دوره</h3>';
    echo '<p class="hint" style="margin-top:0">چهار نمودارِ زیر همان اعدادِ پایینِ صفحه هستند؛ فقط خلاصه‌تر.</p>';

    echo '<div class="success-charts">';

    // Row 1 (right to left): headline donut + category share.
    echo '  <div class="chart-card"><h4>شاخص کل و تداوم ثبت</h4>';
    echo '    <div class="chart-box"><canvas id="ch-overall"></canvas></div>';
    echo '  </div>';

    echo '  <div class="chart-card"><h4>سهم هر حوزه از موفقیت</h4>';
    if ($d['has_cats']) {
        echo '    <div class="chart-box"><canvas id="ch-cats"></canvas></div>';
    } else {
        echo '    <div class="chart-box"><p class="chart-empty">ثبتی برای دسته‌ها وجود ندارد</p></div>';
    }
    echo '  </div>';

    // Row 2 (right to left): per-activity bars + daily trend.
    echo '  <div class="chart-card"><h4>تحقق هر فعالیت (درصد)</h4>';
    if ($d['has_bars']) {
        echo '    <div class="chart-box tall"><canvas id="ch-bars"></canvas></div>';
    } else {
        echo '    <div class="chart-box tall"><p class="chart-empty">فعالیتِ وزن‌داری برای مقایسه نیست.</p></div>';
    }
    echo '  </div>';

    echo '  <div class="chart-card"><h4>درصد موفقیتِ هر روزِ ماه</h4>';
    if ($d['has_trend']) {
        echo '    <div class="chart-box"><canvas id="ch-trend"></canvas></div>';
    } else {
        echo '    <div class="chart-box"><p class="chart-empty">برای روندِ روزانه هنوز داده‌ای ثبت نشده است.</p></div>';
    }
    echo '  </div>';

    echo '</div>'; // .success-charts

    if (!$d['has_bars'] && !$d['has_cats']) {
        echo '<p class="hint">داده‌ای برای نمودارها نیست؛ بعد از ثبتِ چند عملکرد، نمودارها پر می‌شوند.</p>';
    }
    echo '</div>'; // .card
    ?>
<?php echo function_exists('analytics_chartjs_tag') ? analytics_chartjs_tag() : ('<script src="' . e(joma_url('assets/js/chart.min.js')) . '"></script>'); ?>
<script>
(function () {
  var d = <?php echo $json; ?>;
  var grid = 'rgba(124,92,191,0.08)';
  var textColor = '#6b6478';

  function build() {
    if (typeof Chart === 'undefined') return;

    Chart.defaults.font.family = 'inherit';
    Chart.defaults.color = textColor;
    Chart.defaults.responsive = true;
    Chart.defaults.maintainAspectRatio = false;  // the parent box owns the height
    // Set the duration as a PROPERTY. Assigning a whole new object deletes
    // `easing`, and Chart.js then builds animations whose _fn is undefined:
    // it throws "this._fn is not a function" inside the shared
    // requestAnimationFrame loop — AFTER new Chart() has returned, so no
    // try/catch can see it, and the frame that would paint the canvas aborts.
    // The chart object exists but nothing is ever drawn (blank box).
    if (Chart.defaults.animation) {
      Chart.defaults.animation.duration = 400;
      if (!Chart.defaults.animation.easing) Chart.defaults.animation.easing = 'easeOutQuart';
    } else {
      Chart.defaults.animation = { duration: 400, easing: 'easeOutQuart' };
    }
    if (!Chart.defaults.datasets) Chart.defaults.datasets = {};

    // Center text inside the headline donut.
    var centerText = {
      id: 'successCenterText',
      beforeDraw: function (chart) {
        if (!chart.chartArea) return;
        if (!chart.canvas || chart.canvas.id !== 'ch-overall') return;
        var ctx = chart.ctx;
        if (!ctx) return;
        var txt = d.has_overall ? (d.overall + '%') : '—';
        ctx.save();
        ctx.font = 'bold 26px sans-serif';
        ctx.fillStyle = d.has_overall ? '#4c3a6b' : textColor;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(txt,
          chart.chartArea.left + (chart.chartArea.right - chart.chartArea.left) / 2,
          chart.chartArea.top + (chart.chartArea.bottom - chart.chartArea.top) / 2);
        ctx.restore();
      }
    };

    // One place that creates a chart: reuses the canvas, never throws.
    function make(id, config) {
      var el = document.getElementById(id);
      if (!el) return null;
      try {
        var old = (typeof Chart.getChart === 'function') ? Chart.getChart(el) : null;
        if (old) old.destroy();
        return new Chart(el, config);
      } catch (err) {
        if (window.console && console.error) console.error('joma chart ' + id, err);
        return null;
      }
    }

    function num(v, fallback) {
      var n = typeof v === 'number' ? v : parseFloat(v);
      return isFinite(n) ? n : (fallback || 0);
    }
    function nums(arr) {
      var out = [], src = (arr && arr.length) ? arr : [];
      for (var i = 0; i < src.length; i++) out.push(num(src[i], 0));
      return out;
    }
    // Like nums(), but a null stays null: Chart.js then draws a gap instead of
    // a fake zero for days without any registration.
    function nulls(arr) {
      var out = [], src = (arr && arr.length) ? arr : [];
      for (var i = 0; i < src.length; i++) out.push(src[i] === null ? null : num(src[i], 0));
      return out;
    }

    // ---- 1) overall donut + coverage ring -------------------------------
    make('ch-overall', {
      type: 'doughnut',
      data: {
        labels: d.has_overall ? ['موفقیت', 'باقی‌مانده'] : ['بدون مقدار'],
        datasets: [
          { data: d.has_overall ? [num(d.overall), Math.max(0, 100 - num(d.overall))] : [100],
            backgroundColor: d.has_overall ? ['#7c5cbf', '#efeaf6'] : ['#efeaf6'],
            borderWidth: 0, cutout: '62%' },
          { data: d.has_overall ? [num(d.coverage), Math.max(0, 100 - num(d.coverage))] : [0],
            backgroundColor: d.has_overall ? ['#2fa8a0', '#f4f1f8'] : ['#f4f1f8'],
            borderWidth: 0, cutout: '86%' }
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false, resizeDelay: 80,
        cutout: '62%', circumference: 360, rotation: 0,
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
          tooltip: { callbacks: { label: function (c) { return c.label + ': ' + c.parsed + '%'; } } }
        }
      },
      plugins: [centerText]
    });

    // ---- 2) horizontal bars, each in its own activity colour ------------
    var bars = (d.bars && d.bars.length) ? d.bars : [];
    make('ch-bars', {
      type: 'bar',
      data: {
        labels: bars.map(function (b) { return b.label; }),
        datasets: [{
          label: 'تحقق (درصد)',
          data: bars.map(function (b) { return num(b.value); }),
          backgroundColor: bars.map(function (b) { return b.fill; }),
          borderColor: bars.map(function (b) { return b.border; }),
          borderWidth: 1,
          borderRadius: 5,
          barThickness: d.barThickness,
          maxBarThickness: 16
        }]
      },
      options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false, resizeDelay: 80,
        scales: {
          x: { beginAtZero: true, min: 0, max: 100, grid: { color: grid },
               ticks: { callback: function (v) { return v + '%'; } } },
          y: { grid: { display: false }, ticks: { font: { size: 11 } } }
        },
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: function (c) {
            var b = bars[c.dataIndex] || { coverage: 0 };
            return 'تحقق ' + c.parsed.x + '% · پوشش ' + b.coverage + '%';
          } } }
        }
      }
    });

    // ---- 3) daily success rate: one smooth area, gaps are left as gaps ---
    var days = (d.days && d.days.length) ? d.days : [];
    if (days.length) {
      make('ch-trend', {
        type: 'line',
        data: {
          labels: days,
          datasets: [
            { type: 'line', label: 'درصد موفقیت روزانه', data: nulls(d.rate),
              borderColor: '#5b3fa8', borderWidth: 2, pointRadius: 2,
              fill: true, tension: 0.35, spanGaps: true,
              backgroundColor: function (ctx) {
                var chart = ctx.chart, c = chart.ctx, area = chart.chartArea;
                if (!area || !c || typeof c.createLinearGradient !== 'function') return 'rgba(91,63,168,0.18)';
                var g = c.createLinearGradient(0, area.top, 0, area.bottom);
                g.addColorStop(0, 'rgba(124,92,191,0.42)');
                g.addColorStop(1, 'rgba(124,92,191,0.02)');
                return g;
              } }
          ]
        },
        options: {
          responsive: true, maintainAspectRatio: false, resizeDelay: 80,
          interaction: { mode: 'index', intersect: false },
          scales: {
            x: { grid: { display: false }, ticks: { maxTicksLimit: 12, font: { size: 10 } } },
            y: { beginAtZero: true, max: 100, grid: { color: grid },
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
    }

    // ---- 4) category share ---------------------------------------------
    var cats = (d.categories && d.categories.length) ? d.categories : [];
    if (cats.length) {
      make('ch-cats', {
        type: 'doughnut',
        data: {
          labels: cats,
          datasets: [{
            data: nums(d.category_values),
            backgroundColor: cats.map(function (_, i) {
              return (d.palette && d.palette.length) ? d.palette[i % d.palette.length] : '#7c5cbf';
            }),
            borderWidth: 2, borderColor: '#fff', circumference: 360, rotation: 0
          }]
        },
        options: {
          responsive: true, maintainAspectRatio: false, resizeDelay: 80,
          cutout: '45%', circumference: 360, rotation: 0,
          plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
            tooltip: { callbacks: { label: function (c) {
              var vals = nums(d.category_values), total = 0, i;
              for (i = 0; i < vals.length; i++) total += vals[i];
              var share = total > 0 ? Math.round((vals[c.dataIndex] / total) * 1000) / 10 : 0;
              return c.label + ' · ' + share + '%';
            } } }
          }
        }
      });
    }
  }

  // Draw only once the page (and the fixed-height boxes) really exist.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', build);
  } else {
    build();
  }
})();
</script>
<?php
}

function render_success_activities($s) {
    $rows = $s['activities'];
    if (!$rows) {
        echo empty_state('فعالیتی برای تحلیل نیست', 'برای این دوره فعالیتی در برنامه ثبت نشده است.');
        return;
    }
    // Weighted activities first (by weight desc), then the excluded ones.
    $inM = array();
    $out = array();
    foreach ($rows as $a) {
        if ($a['in_weighted_eligible']) $inM[] = $a; else $out[] = $a;
    }
    usort($inM, function ($x, $y) {
        if ((int) $y['weight'] === (int) $x['weight']) return strcmp($x['activity_title'], $y['activity_title']);
        return ((int) $y['weight']) - ((int) $x['weight']);
    });
    foreach (array_merge($inM, $out) as $a) {
        $sticker = isset($a['sticker']) ? $a['sticker'] : '•';
        $color = isset($a['color']) ? $a['color'] : '#e8e2f2';
        echo '<div class="card weight-row">';
        echo '<div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap">';
        echo '<span><span class="sticker" style="background:' . e($color) . '">' . e($sticker) . '</span> '
            . e($a['activity_title']) . ' <span class="meta">' . e($a['activity_code']) . '</span></span>';
        echo '<span class="meta">' . e(frequencies_list()[$a['frequency']]) . ' · اهمیت ' . e(weight_label($a['weight'])) . '</span>';
        echo '</div>';
        echo '<p class="meta">پوشش ' . fa_num($a['observed_cycle_count']) . '/' . fa_num($a['eligible_cycle_count'])
            . ' · تحقق ' . success_ui_pct($a['activity_achievement']) . '</p>';
        echo success_ui_bar($a['activity_achievement']);
        echo '<p style="margin:8px 0 0">' . success_ui_chip($a['data_status']) . '</p>';
        if ($a['exclusion_reason'] === 'WEIGHT_ZERO') {
            echo '<p class="hint">وزن این فعالیت صفر است: عدد خودش را می‌بیند ولی در موفقیت کلّی حساب نمی‌شود.</p>';
        }
        echo '<details style="margin-top:8px"><summary class="meta">جزئیات چرخه‌ها</summary>';
        render_success_cycles($a);
        echo '</details>';
        echo '</div>';
    }
}

function render_success_live($s) {
    $any = false;
    foreach ($s['activities'] as $a) if ($a['live_actual'] !== null) { $any = true; break; }
    if (!$any) return;
    echo '<div class="card"><h3 style="margin:0 0 8px">چرخهٔ جاری (زنده)</h3>';
    echo '<p class="hint" style="margin-top:0">این اعداد غیرنهایی هستند و در موفقیت نهایی این دوره حساب نمی‌شوند.</p>';
    foreach ($s['activities'] as $a) {
        if ($a['live_actual'] === null) continue;
        echo '<div class="weight-row"><div style="display:flex;justify-content:space-between">';
        echo '<span>' . e($a['activity_title']) . '</span>';
        echo '<span>' . e(format_value($a['data_type'], $a['live_actual'], $a['unit']))
            . ' از ' . e(format_value($a['data_type'], $a['live_target'], $a['unit'])) . '</span>';
        echo '</div>' . success_ui_bar($a['live_raw_progress']) . '</div>';
    }
    echo '</div>';
}

function render_success_ratings($s) {
    if (!$s['rating_analyses']) return;
    echo '<div class="card"><h3 style="margin:0 0 8px">امتیازدهی (RATING)</h3>';
    echo '<p class="hint" style="margin-top:0">این فعالیت‌ها صرفاً گزارش تحلیلی دارند و در عددِ موفقیت کلّی حساب نمی‌شوند.</p>';
    foreach ($s['rating_analyses'] as $r) {
        $sum = $r['summary'];
        if (!$sum) continue;
        echo '<div class="weight-row"><div style="display:flex;justify-content:space-between">';
        echo '<span>' . e($r['activity_code']) . '</span>';
        echo '<span class="meta">میانگین ' . fa_num(round($sum['average'], 1))
            . ' · کمینه ' . fa_num($sum['min']) . ' · بیشینه ' . fa_num($sum['max'])
            . ' · ' . fa_num($sum['count']) . ' رای</span>';
        echo '</div>';
        if (!empty($sum['distribution'])) {
            echo '<div class="mood-bars">';
            foreach ($sum['distribution'] as $score => $cnt) {
                $pct = ($sum['count'] > 0) ? ($cnt / $sum['count'] * 100) : 0;
                echo '<div class="row"><span>' . fa_num($score) . '</span><div class="bar"><i style="width:' . round($pct) . '%"></i></div><strong>' . fa_num($cnt) . '</strong></div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</div>';
}

function render_success_warnings($s) {
    $lines = array();
    foreach ($s['excluded'] as $ex) {
        if ($ex['reason'] === 'WEIGHT_ZERO') continue; // already noted under its own row
        $lines[] = '⚠ ' . $ex['activity_code'] . ' — ' . success_ui_status_text($ex['data_status']);
    }
    foreach ($s['activities'] as $a) {
        if ($a['ambiguous_cycle_count'] > 0) {
            $lines[] = '⚠ ' . fa_num($a['ambiguous_cycle_count']) . ' دوره از «' . $a['activity_title']
                . '» ثبت تکراری دارد؛ آن دوره‌ها از محاسبه خارج شده‌اند ولی رویدادها محفوظ‌اند.';
        }
    }
    if (!$lines) return;
    echo '<div class="card"><h3 style="margin:0 0 8px">هشدارها</h3><ul style="margin:0;padding-inline-start:18px">';
    foreach ($lines as $l) echo '<li>' . e($l) . '</li>';
    echo '</ul></div>';
}

function render_success_footer($s) {
    echo '<div class="card"><p class="meta" style="margin:0">نسخهٔ متریک: ' . e($s['metric_version'])
        . ' · مرجع زمان: تاریخ امروزِ سرور (' . e($s['today']) . ')</p>';
    echo '<p class="hint">«—» یعنی مقدار محاسبه نشده است و با صفر متفاوت است. «۰٪» فقط برای مقدار واقعیِ صفر نمایش داده می‌شود.</p>';
    echo '</div>';
}

/* ------------------------------------------------------------------ */
/* Entry point                                                         */
/* ------------------------------------------------------------------ */

/**
 * @param array  $rep        output of build_report() for the selected plan
 * @param string $guide_href optional link used by the guide card
 */
function render_success_tab($rep, $guide_href = '') {
    $s = success_report($rep['plan'], $rep['bounds'], $rep['activities'], $rep['events'], jalali_today());

    // The engine returns only metric fields; the display wants the sticker and
    // colour that build_report() already loaded with each plan activity.
    foreach ($s['activities'] as $i => $a) {
        foreach ($rep['activities'] as $orig) {
            if ((int) $orig['id'] !== (int) $a['plan_activity_id']) continue;
            if (isset($orig['sticker'])) $s['activities'][$i]['sticker'] = $orig['sticker'];
            if (isset($orig['color'])) $s['activities'][$i]['color'] = $orig['color'];
            if (isset($orig['category'])) $s['activities'][$i]['category'] = $orig['category'];
            break;
        }
    }

    render_success_context($s);                                   // A
    echo '<div class="grid grid-2">';
    render_success_overall($s);                                   // B
    render_success_coverage($s);                                  // C
    echo '</div>';
    render_success_charts($s);                                    // charts (Chart.js)
    // Analytical add-ons (JOMA_ANALYTICS_V1): mood trends + week-by-week.
    if (function_exists('render_analytics_sections')) {
        render_analytics_sections($s, isset($rep['moods']) ? $rep['moods'] : array());
    }
    render_success_activities($s);                                // D + G
    render_success_live($s);                                      // E
    render_success_ratings($s);                                   // F
    render_success_warnings($s);                                  // H
    render_success_footer($s);                                    // I
}

}
