<?php
/**
 * Analytical layer — JOMA_ANALYTICS_V1.
 *
 * An ADD-ON on top of the frozen JOMA_SUCCESS_V1 engine. It never writes,
 * never queries and never changes how Final Success is computed: it only
 * re-reads the cycles and events that success_report() already produced and
 * derives day-level and week-level views from them (charts, heatmap, weekly
 * table, period comparison).
 *
 * Definitions used here (they are display-level, not part of the frozen
 * metric, and every screen that uses them states them in Persian):
 *
 *  - Daily Success Rate: over the DAILY activities that carry a weight,
 *    Σ(weight × min(actual that day / target, 1)) / Σ(weight).
 *    A day with no registration at all is NULL (never a fake zero) — the
 *    line connects the days that do have data, and the heatmap paints it grey.
 *    WEEKLY / MONTHLY activities cannot be attributed to one day, so they are
 *    out of the daily index by definition (the monthly score still includes
 *    them through the engine).
 *  - Day Coverage: how many of those DAILY activities were logged that day.
 *  - Week: calendar chunks of 7 days starting on day 1 (week 1 = days 1-7).
 *    Week Success = Σ(daily numerators) / (Σweights × active days of week),
 *    Week Coverage = logged (activity, day) pairs / expected pairs.
 *  - Mood index: the mean of the four positive indicators (energy, general
 *    mood, focus, sleep quality). Stress is reported on its own because it is
 *    not "more is better".
 */

if (!function_exists('analytics_daily_rows')) {

/**
 * Make a payload safe for json_encode: NAN / INF become 0, objects are
 * dropped, everything else (including Persian labels and real NULLs, which
 * Chart.js needs for gaps) is kept. A single NAN anywhere in the payload
 * makes json_encode fail and hands the browser an invalid script.
 */
function analytics_json_safe($value) {
    if (is_array($value)) {
        foreach ($value as $k => $v) $value[$k] = analytics_json_safe($v);
        return $value;
    }
    if ($value === null || is_bool($value) || is_int($value) || is_string($value)) return $value;
    if (is_float($value)) return is_finite($value) ? $value : 0.0;
    if (is_object($value)) return 0.0;
    return is_numeric($value) ? (float) $value : 0.0;
}

/** json_encode for the charts: never returns false, never emits NAN/INF. */
function analytics_json($data) {
    $json = json_encode(analytics_json_safe($data), JSON_UNESCAPED_UNICODE);
    return (is_string($json) && $json !== '') ? $json : '{}';
}

/** The five mood indicators, in the order the UI shows them. */
function analytics_mood_metrics() {
    return array(
        'energy' => array('label' => 'انرژی', 'color' => '#f2a03d'),
        'general_mood' => array('label' => 'حال عمومی', 'color' => '#7c5cbf'),
        'focus' => array('label' => 'تمرکز', 'color' => '#2fb3c9'),
        'sleep_quality' => array('label' => 'خواب', 'color' => '#4f7ff5'),
        'stress' => array('label' => 'استرس', 'color' => '#e2568c'),
    );
}

/** Map moods (rows with jalali_date) onto day numbers of this period. */
function analytics_mood_by_day($moods, $prefix) {
    $out = array();
    if (!is_array($moods)) return $out;
    foreach ($moods as $m) {
        if (!isset($m['jalali_date'])) continue;
        $d = (string) $m['jalali_date'];
        if (substr($d, 0, 7) !== substr($prefix, 0, 7)) continue;
        $out[(int) substr($d, 8, 2)] = $m;
    }
    return $out;
}

/**
 * Day-by-day rows for one period.
 *
 * @param array $s      output of success_report()
 * @param array $moods mood rows of the same period (may be empty)
 * @return array('meta' => ..., 'rows' => array of day rows)
 */
function analytics_daily_rows($s, $moods = array()) {
    $b = jalali_period_bounds($s['period_key']);
    $days = isset($b['days']) ? (int) $b['days'] : 30;
    if ($days < 1) $days = 30;
    if ($days > 31) $days = 31;
    $prefix = $b['year'] . '-' . jalali_pad($b['month']) . '-';
    $inPeriod = function ($date) use ($prefix) {
        return is_string($date) && substr($date, 0, 7) === substr($prefix, 0, 7);
    };

    // ---- active window: from the plan start until today / the archive ----
    $started = isset($s['started_jalali']) ? $s['started_jalali'] : null;
    $archived = isset($s['archived_jalali']) ? $s['archived_jalali'] : null;
    $today = isset($s['today']) ? $s['today'] : null;
    $startDay = 1;
    $endDay = $days;
    if (!$inPeriod($started) && !empty($s['plan_status']) && $s['plan_status'] === 'PLANNING') {
        $endDay = 0;                                   // not started yet
    }
    if ($inPeriod($started)) $startDay = max(1, (int) substr($started, 8, 2));
    if ($inPeriod($archived)) $endDay = min($endDay, (int) substr($archived, 8, 2));
    if ($inPeriod($today)) $endDay = min($endDay, (int) substr($today, 8, 2));
    if ($endDay < $startDay) $endDay = $startDay - 1;

    // ---- DAILY activities that carry a weight ----
    $acts = array();
    foreach ($s['activities'] as $a) {
        if (empty($a['in_weighted_eligible']) || empty($a['in_valid_analytic'])) continue;
        if ($a['frequency'] !== 'DAILY') continue;
        $type = $a['data_type'];
        $target = (float) $a['target'];
        if ($type === 'BOOLEAN') {
            if ($target != 1.0) continue;              // mirrors the engine's UNSUPPORTED rule
        } elseif ($target <= 0) {
            continue;
        }
        $perDay = array();
        foreach ($a['cycles'] as $c) {
            $day = (int) substr($c['key'], 8, 2);
            $count = (int) $c['raw_event_count'];
            $perDay[$day] = array(
                'actual' => $count > 0 ? (float) $c['raw_actual'] : 0.0,
                'count' => $count,
                'ambiguous' => ($count > 1),            // Decision 1: ambiguous, never scored
            );
        }
        $acts[] = array(
            'id' => $a['plan_activity_id'],
            'title' => $a['activity_title'],
            'weight' => max(1, (int) $a['weight']),
            'target' => $target,
            'type' => $type,
            'per_day' => $perDay,
        );
    }
    $totalWeight = 0;
    foreach ($acts as $a) $totalWeight += $a['weight'];

    $moodByDay = analytics_mood_by_day($moods, $prefix);

    $rows = array();
    for ($d = 1; $d <= $days; $d++) {
        $active = ($d >= $startDay && $d <= $endDay);
        $num = 0.0;
        $recorded = 0;
        $events = 0;
        foreach ($acts as $a) {
            if (!isset($a['per_day'][$d])) continue;
            $rec = $a['per_day'][$d];
            if ($rec['count'] > 0) {
                $events += $rec['count'];
                $recorded++;
            }
            if ($rec['ambiguous'] || $rec['count'] < 1) continue;
            if ($a['type'] === 'BOOLEAN') {
                $ratio = ($rec['actual'] >= 1) ? 1.0 : 0.0;
            } else {
                $ratio = min($rec['actual'] / $a['target'], 1.0);
            }
            $num += $a['weight'] * $ratio;
        }
        $hasData = ($events > 0);
        $success = ($active && $totalWeight > 0 && $hasData) ? ($num / $totalWeight) : null;
        $rows[$d] = array(
            'day' => $d,
            'date' => $prefix . jalali_pad($d),
            'active' => $active,
            'future' => (!$active && $d > $endDay),
            'success' => $success,
            'num' => $num,
            'den' => $totalWeight,
            'recorded' => $recorded,
            'expected' => count($acts),
            'events' => $events,
            'coverage' => ($active && count($acts) > 0) ? ($recorded / count($acts)) : null,
            'mood' => isset($moodByDay[$d]) ? $moodByDay[$d] : null,
        );
    }

    return array(
        'meta' => array(
            'days' => $days,
            'start_day' => $startDay,
            'end_day' => $endDay,
            'activity_count' => count($acts),
            'weight_sum' => $totalWeight,
            'prefix' => $prefix,
        ),
        'rows' => $rows,
    );
}

/** Calendar weeks (7-day chunks from day 1) built from the daily rows. */
function analytics_weeks($daily) {
    $rows = $daily['rows'];
    $weeks = array();
    $chunk = array();
    foreach ($rows as $r) {
        $chunk[] = $r;
        if (count($chunk) === 7) { $weeks[] = $chunk; $chunk = array(); }
    }
    if ($chunk) $weeks[] = $chunk;

    $out = array();
    foreach ($weeks as $i => $chunk) {
        $num = 0.0;
        $den = 0.0;
        $recorded = 0;
        $expected = 0;
        $activeDays = 0;
        $dataDays = 0;
        foreach ($chunk as $r) {
            if (!$r['active']) continue;
            $activeDays++;
            $num += $r['num'];
            $den += $r['den'];
            $recorded += $r['recorded'];
            $expected += $r['expected'];
            if ($r['events'] > 0) $dataDays++;
        }
        $out[] = array(
            'index' => $i + 1,
            'from' => $chunk[0]['day'],
            'to' => $chunk[count($chunk) - 1]['day'],
            'success' => ($den > 0) ? ($num / $den) : null,
            'coverage' => ($expected > 0) ? ($recorded / $expected) : null,
            'active_days' => $activeDays,
            'data_days' => $dataDays,
        );
    }
    return $out;
}

/** Per-day series for the mood chart, plus the parallel success series. */
function analytics_mood_series($daily) {
    $metrics = analytics_mood_metrics();
    $labels = array();
    $success = array();
    $series = array();
    foreach ($metrics as $key => $m) $series[$key] = array();
    $index = array();

    foreach ($daily['rows'] as $r) {
        $labels[] = fa_num($r['day']);
        $success[] = ($r['success'] === null) ? null : round($r['success'] * 100, 1);
        foreach ($metrics as $key => $m) {
            $v = ($r['mood'] && isset($r['mood'][$key]) && $r['mood'][$key] !== '') ? (float) $r['mood'][$key] : null;
            $series[$key][] = $v;
        }
        // Mood index: mean of the four positive indicators (stress excluded).
        $sum = 0.0;
        $n = 0;
        if ($r['mood']) {
            foreach (array('energy', 'general_mood', 'focus', 'sleep_quality') as $key) {
                if (isset($r['mood'][$key]) && $r['mood'][$key] !== '') { $sum += (float) $r['mood'][$key]; $n++; }
            }
        }
        $index[] = ($n > 0) ? round($sum / $n, 2) : null;
    }
    return array(
        'labels' => $labels,
        'success' => $success,
        'series' => $series,
        'index' => $index,
        'metrics' => $metrics,
    );
}

/**
 * Pearson correlation between daily mood index and daily success, over the
 * days where BOTH exist. Returns null when fewer than 3 shared days exist.
 */
function analytics_correlation($moodSeries) {
    $xs = array();
    $ys = array();
    foreach ($moodSeries['index'] as $i => $m) {
        $s = isset($moodSeries['success'][$i]) ? $moodSeries['success'][$i] : null;
        if ($m === null || $s === null) continue;
        $xs[] = (float) $m;
        $ys[] = (float) $s;
    }
    $n = count($xs);
    if ($n < 3) return array('r' => null, 'n' => $n);
    $mx = array_sum($xs) / $n;
    $my = array_sum($ys) / $n;
    $num = 0.0;
    $dx = 0.0;
    $dy = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $a = $xs[$i] - $mx;
        $b = $ys[$i] - $my;
        $num += $a * $b;
        $dx += $a * $a;
        $dy += $b * $b;
    }
    if ($dx <= 0 || $dy <= 0) return array('r' => null, 'n' => $n);
    $r = $num / sqrt($dx * $dy);
    if ($r > 1) $r = 1.0;
    if ($r < -1) $r = -1.0;
    return array('r' => round($r, 2), 'n' => $n);
}

/**
 * One-period summary used by the comparison screen: final success, coverage
 * and the mood averages. Read-only — success_report() does the heavy lifting.
 */
function analytics_period_summary($plan, $bounds, $activities, $events, $moods, $today) {
    $s = success_report($plan, $bounds, $activities, $events, $today);
    $sum = array('success' => null, 'coverage' => null, 'mood' => null, 'stress' => null,
                 'mood_days' => 0, 'status' => $s['overall_status'], 'n' => null, 'm' => null);
    $sum['success'] = ($s['overall_success'] === null) ? null : (float) $s['overall_success'];
    $sum['coverage'] = ($s['overall_coverage'] === null) ? null : (float) $s['overall_coverage'];
    $sum['n'] = (int) $s['weighted_observed_activities'];
    $sum['m'] = (int) $s['weighted_eligible_activities'];

    $pos = array('energy', 'general_mood', 'focus', 'sleep_quality');
    $sp = 0.0;
    $np = 0;
    $ss = 0.0;
    $ns = 0;
    $days = 0;
    $prefix = substr($bounds['start'], 0, 7);   // moods of THIS period only
    foreach ($moods as $m) {
        if (!isset($m['jalali_date']) || substr((string) $m['jalali_date'], 0, 7) !== $prefix) continue;
        $days++;
        foreach ($pos as $k) {
            if (isset($m[$k]) && $m[$k] !== '') { $sp += (float) $m[$k]; $np++; }
        }
        if (isset($m['stress']) && $m['stress'] !== '') { $ss += (float) $m['stress']; $ns++; }
    }
    $sum['mood_days'] = $days;
    $sum['mood'] = ($np > 0) ? ($sp / $np) : null;
    $sum['stress'] = ($ns > 0) ? ($ss / $ns) : null;
    return $sum;
}

/** Relative change between two periods, in percentage points and in percent. */
function analytics_delta($from, $to) {
    if ($from === null || $to === null) return array('points' => null, 'percent' => null, 'has_data' => false);
    $points = ($to - $from) * 100;
    $percent = null;
    if (abs($from) > 0.0000001) $percent = (($to - $from) / abs($from)) * 100;
    return array(
        'points' => $points,
        'percent' => $percent,
        'has_data' => true,
        'up' => $points > 0.0001,
        'down' => $points < -0.0001,
        'flat' => abs($points) <= 0.0001,
    );
}

/** Heatmap colour band for a daily success value (0..1 or null). */
function analytics_heat_level($value, $hasData) {
    if ($value === null || !$hasData) return 'none';
    $pct = $value * 100;
    if ($pct >= 80) return 'high';
    if ($pct >= 50) return 'mid';
    return 'low';
}

/** Human text for a heat level (used by the calendar legend and titles). */
function analytics_heat_label($level) {
    $map = array(
        'high' => 'موفقیت ۸۰٪ تا ۱۰۰٪',
        'mid' => 'موفقیت ۵۰٪ تا ۷۹٪',
        'low' => 'موفقیت کمتر از ۵۰٪',
        'none' => 'بدون ثبت داده',
    );
    return isset($map[$level]) ? $map[$level] : $level;
}

/** Small dot colour for a mood index of 1..5. */
function analytics_mood_dot($index) {
    if ($index === null) return '#d9d3e6';
    if ($index >= 4.5) return '#2e7d5b';
    if ($index >= 3.5) return '#5fb865';
    if ($index >= 2.5) return '#f0c33c';
    if ($index >= 1.5) return '#e08a4f';
    return '#d2564f';
}

} // function_exists
