<?php
/**
 * JOMA Success Engine — JOMA_SUCCESS_V1
 *
 * ADD-ONLY file. It changes nothing by itself.
 *
 * Contract (official spec + 4 amendments + 12 formal decisions):
 *  - Pure functions only: no queries, no writes, no session, no side effects.
 *  - Consumes ONLY the arrays already loaded by list_plan_activities() / list_events().
 *  - Works identically for storage=file and storage=mysql.
 *  - Does NOT touch: build_report(), transition_plan(), can_register_performance(),
 *    add_plan_activity(), store_role_permissions(), target_of().
 *  - Missing values are NULL + status. Never a fake zero (spec §2, §26).
 *
 * Amendment 1 (§10): BOOLEAN is counted for WEEKLY/MONTHLY -> MIN(SUM(actual)/Target, 1).
 *                    DAILY BOOLEAN is valid ONLY when Target == 1, otherwise UNSUPPORTED.
 * Amendment 2 (§5):  Weekly cycle = intersection of the Saturday-Friday week with the
 *                    period window of the SAME plan. Cross-plan aggregation forbidden.
 * Amendment 3 (§12): Coverage numerator = observed AND completed; denominator = eligible
 *                    AND completed. The current incomplete cycle is in NEITHER.
 * Amendment 4 (§11/§16): Final ActivityAchievement uses completed observed cycles only.
 *
 * Decision 1:  Duplicate DAILY -> only that cycle is AMBIGUOUS_DUPLICATE. It leaves the
 *              Achievement mean and the Coverage NUMERATOR, but stays in the Coverage
 *              DENOMINATOR. Other healthy cycles stay computable. If no healthy cycle
 *              remains, Achievement = NULL.
 * Decision 2:  Overall Success is computed for ONE plan / ONE period only. No user-level
 *              or cross-period overall in V1.
 * Decision 3:  Completed cycle  <=>  cycle_end < jalali_today()  (server time only).
 * Decision 4:  Current cycle -> live data only (live_actual / live_target /
 *              live_raw_progress). Never a final score.
 * Decision 5:  M = valid, supported, computable activities with weight > 0.
 *              N = subset of M whose final ActivityAchievement is not NULL.
 *              RATING / weight=0 / Unsupported / InvalidConfig are outside N and M.
 * Decision 6:  Overall Coverage = SUM(observed completed) / SUM(eligible completed)
 *              over set M only.
 * Decision 7:  RATING is outside Overall and N/M; analysed separately
 *              (average / min / max / trend / distribution).
 * Decision 8:  weight = 0 -> the activity stays and shows its own Achievement/Coverage,
 *              but is outside Overall and N/M.
 * Decision 9:  Unsupported / InvalidConfig are outside Overall, N/M and Overall Coverage;
 *              they are NOT hidden and must show a user-friendly notice.
 * Decision 10: UI: 0% only for a recorded Actual = 0; NULL is shown as "—";
 *              No Data / In Progress / Not Started / Invalid Config need distinct texts.
 * Decision 11: First partial WEEKLY or MONTHLY cycle of a mid-cycle start = NOT_ELIGIBLE.
 *              No prorating, no target splitting. DAILY is assessable from the start date.
 * Decision 12: Cycles after the archive date = NOT_ELIGIBLE. The archive day itself, when
 *              incomplete, does not enter Final Success.
 */

if (!defined('JOMA_METRIC_VERSION')) {
    define('JOMA_METRIC_VERSION', 'JOMA_SUCCESS_V1');
}

function success_metric_version() {
    return JOMA_METRIC_VERSION;
}

/* ------------------------------------------------------------------ */
/* Date helpers                                                        */
/* ------------------------------------------------------------------ */

function success_jalali_add_days($date, $days) {
    $p = explode('-', $date);
    $g = jalali_to_gregorian((int) $p[0], (int) $p[1], (int) $p[2]);
    $ts = mktime(0, 0, 0, $g[1], $g[2], $g[0]) + ($days * 86400);
    $j = gregorian_to_jalali((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));
    return $j[0] . '-' . jalali_pad($j[1]) . '-' . jalali_pad($j[2]);
}

function success_gregorian_to_jalali_date($datetime) {
    if (!$datetime) return null;
    $ts = strtotime($datetime);
    if (!$ts) return null;
    $j = gregorian_to_jalali((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));
    return $j[0] . '-' . jalali_pad($j[1]) . '-' . jalali_pad($j[2]);
}

/* ------------------------------------------------------------------ */
/* Cycles                                                              */
/* ------------------------------------------------------------------ */

function success_cycles($frequency, $bounds) {
    $out = array();
    $start = $bounds['start'];
    $end = $bounds['end'];

    if ($frequency === 'DAILY') {
        for ($d = 1; $d <= (int) $bounds['days']; $d++) {
            $key = $bounds['year'] . '-' . jalali_pad($bounds['month']) . '-' . jalali_pad($d);
            $out[] = array('key' => $key, 'start' => $key, 'end' => $key);
        }
        return $out;
    }

    if ($frequency === 'MONTHLY') {
        $out[] = array('key' => substr($start, 0, 7), 'start' => $start, 'end' => $end);
        return $out;
    }

    $cursor = jalali_week_start($start);
    $guard = 0;
    while (strcmp($cursor, $end) <= 0 && $guard++ < 60) {
        $weekEnd = success_jalali_add_days($cursor, 6);
        $cStart = (strcmp($cursor, $start) < 0) ? $start : $cursor;
        $cEnd = (strcmp($weekEnd, $end) > 0) ? $end : $weekEnd;
        $out[] = array('key' => $cursor, 'start' => $cStart, 'end' => $cEnd);
        $cursor = success_jalali_add_days($cursor, 7);
    }
    return $out;
}

/**
 * Decision 3 / 11 / 12.
 * @return string NOT_STARTED|AFTER_ARCHIVE|CUT_BY_ARCHIVE|NOT_ELIGIBLE|NOT_ELIGIBLE_YET|IN_PROGRESS|COMPLETED
 */
function success_cycle_status($cycle, $frequency, $today, $started_jalali, $archived_jalali, $plan_started) {
    if (!$plan_started) return 'NOT_STARTED';

    // Decision (Archive): after the archive date a cycle is simply not eligible.
    // A cycle that the archive cut short is incomplete -> never in Final Success.
    if ($archived_jalali !== null) {
        if (strcmp($cycle['start'], $archived_jalali) > 0) return 'NOT_ELIGIBLE';
        if (strcmp($cycle['end'], $archived_jalali) >= 0) return 'NOT_ELIGIBLE_PARTIAL_ARCHIVE';
    }

    // Decision (Partial Start): a WEEKLY/MONTHLY cycle that began before the plan
    // started is partial -> NOT eligible, no prorating of the target.
    // A DAILY cycle is one day, so a day before the start date is simply out of range.
    if ($started_jalali !== null && strcmp($cycle['start'], $started_jalali) < 0) {
        return ($frequency === 'DAILY') ? 'NOT_ELIGIBLE' : 'NOT_ELIGIBLE_PARTIAL_START';
    }
    if (strcmp($cycle['start'], $today) > 0) return 'NOT_ELIGIBLE_YET';
    if (strcmp($cycle['end'], $today) < 0) return 'COMPLETED'; // Decision 3
    return 'IN_PROGRESS';
}

/* ------------------------------------------------------------------ */
/* Aggregation                                                         */
/* ------------------------------------------------------------------ */

function success_cycle_actual($pa, $related_events, $cycle) {
    $freq = $pa['frequency'];

    // Amendment 2: only events inside [cycle.start, cycle.end] may be merged.
    $inWindow = array();
    foreach ($related_events as $e) {
        $d = $e['performance_date'];
        if (strcmp($d, $cycle['start']) >= 0 && strcmp($d, $cycle['end']) <= 0) $inWindow[] = $e;
    }

    if ($freq === 'WEEKLY') {
        $sum = weekly_actual($inWindow, $cycle['start']);
        $count = 0;
        foreach ($inWindow as $e) {
            if (jalali_same_week($e['performance_date'], $cycle['start'])) $count++;
        }
        return array('has_data' => $count > 0, 'actual' => (float) $sum, 'count' => $count, 'events' => $inWindow);
    }
    if ($freq === 'MONTHLY') {
        return array('has_data' => count($inWindow) > 0, 'actual' => (float) sum_actual($inWindow), 'count' => count($inWindow), 'events' => $inWindow);
    }
    $count = 0;
    $sum = 0.0;
    foreach ($inWindow as $e) {
        if ($e['performance_date'] === $cycle['key']) {
            $count++;
            $sum += (float) $e['actual_value'];
        }
    }
    return array('has_data' => $count > 0, 'actual' => (float) $sum, 'count' => $count, 'events' => $inWindow);
}

/* ------------------------------------------------------------------ */
/* Config validity                                                     */
/* ------------------------------------------------------------------ */

function success_config_status($pa) {
    $type = $pa['data_type'];
    if ($type === 'RATING') return 'EXCLUDED';
    if ($type !== 'NUMERIC' && $type !== 'DURATION' && $type !== 'BOOLEAN') return 'UNSUPPORTED';
    if ($type === 'NUMERIC' || $type === 'DURATION') {
        $units = units_list();
        if (empty($pa['unit']) || !isset($units[$pa['unit']])) return 'INVALID_CONFIG';
        if ((float) $pa['target_value'] <= 0) return 'INVALID_CONFIG';
    }
    if ($type === 'BOOLEAN' && $pa['frequency'] === 'DAILY' && (float) $pa['target_value'] != 1.0) {
        return 'UNSUPPORTED'; // Amendment 1 — keeps the ACT041 protection
    }
    if ($type === 'BOOLEAN' && $pa['frequency'] !== 'DAILY' && (float) $pa['target_value'] <= 0) {
        return 'INVALID_CONFIG';
    }
    return 'OK';
}

/* ------------------------------------------------------------------ */
/* One cycle achievement                                               */
/* ------------------------------------------------------------------ */

function success_cycle_achievement($pa, $cycleActual) {
    $type = $pa['data_type'];
    $target = (float) $pa['target_value'];

    if ($type === 'RATING') return array('achievement' => null, 'status' => 'EXCLUDED');
    if ($type !== 'NUMERIC' && $type !== 'DURATION' && $type !== 'BOOLEAN') {
        return array('achievement' => null, 'status' => 'UNSUPPORTED');
    }
    if ($type === 'NUMERIC' || $type === 'DURATION') {
        $units = units_list();
        if (empty($pa['unit']) || !isset($units[$pa['unit']])) return array('achievement' => null, 'status' => 'INVALID_CONFIG');
        if ($target <= 0) return array('achievement' => null, 'status' => 'INVALID_CONFIG');
    }
    if ($type === 'BOOLEAN') {
        if ($pa['frequency'] === 'DAILY') {
            if ($target != 1.0) return array('achievement' => null, 'status' => 'UNSUPPORTED');
        } elseif ($target <= 0) {
            return array('achievement' => null, 'status' => 'INVALID_CONFIG');
        }
    }

    // Decision 1: a duplicated DAILY cycle is ambiguous — never selected, summed or dropped.
    if ($pa['frequency'] === 'DAILY' && $cycleActual['count'] > 1) {
        return array('achievement' => null, 'status' => 'AMBIGUOUS_DUPLICATE');
    }
    if (!$cycleActual['has_data']) {
        return array('achievement' => null, 'status' => 'NO_DATA');
    }

    $actual = (float) $cycleActual['actual'];
    if ($type === 'BOOLEAN' && $pa['frequency'] === 'DAILY') {
        return array('achievement' => ($actual >= 1 ? 1.0 : 0.0), 'status' => 'OK');
    }
    return array('achievement' => min($actual / $target, 1.0), 'status' => 'OK');
}

/* ------------------------------------------------------------------ */
/* RATING side-analysis (Decision 7)                                   */
/* ------------------------------------------------------------------ */

function success_rating_summary($pa, $related_events, $cycles) {
    $values = array();
    $trend = array();
    foreach ($cycles as $cycle) {
        $agg = success_cycle_actual($pa, $related_events, $cycle);
        if ($agg['has_data']) {
            $v = (float) $agg['actual'];
            $values[] = $v;
            $trend[] = array('cycle' => $cycle['key'], 'value' => $v);
        }
    }
    if (!$values) {
        return array('count' => 0, 'average' => null, 'min' => null, 'max' => null, 'distribution' => array(), 'trend' => array());
    }
    $dist = array();
    foreach ($values as $v) {
        $k = (string) $v;
        if (!isset($dist[$k])) $dist[$k] = 0;
        $dist[$k]++;
    }
    return array(
        'count' => count($values),
        'average' => array_sum($values) / count($values),
        'min' => min($values),
        'max' => max($values),
        'distribution' => $dist,
        'trend' => $trend,
    );
}

/* ------------------------------------------------------------------ */
/* Activity level (§26)                                                */
/* ------------------------------------------------------------------ */

function success_activity($pa, $events, $bounds, $today, $started_jalali, $archived_jalali, $plan_started) {
    $cfg = success_config_status($pa);
    $base = array(
        'activity_id' => $pa['activity_id'],
        'plan_activity_id' => $pa['id'],
        'activity_code' => isset($pa['activity_code']) ? $pa['activity_code'] : '',
        'activity_title' => $pa['name'],
        'frequency' => $pa['frequency'],
        'data_type' => $pa['data_type'],
        'unit' => $pa['unit'],
        'target' => (float) $pa['target_value'],
        'weight' => (int) $pa['weight'],
        'cycles' => array(),
        'cycle_count' => 0,
        'observed_cycle_count' => 0,
        'eligible_cycle_count' => 0,
        'ambiguous_cycle_count' => 0,
        'actual' => null,
        'cycle_achievement' => null,
        'activity_achievement' => null,
        'coverage' => null,
        'weighted_contribution' => null,
        'live_actual' => null,
        'live_target' => null,
        'live_raw_progress' => null,
        'in_valid_analytic' => false,
        'in_weighted_eligible' => false,
        'exclusion_reason' => null,
        'rating_summary' => null,
        'data_status' => 'OK',
        'status_message_fa' => '',
        'metric_version' => success_metric_version(),
    );

    $related = events_for($events, $pa['id']);
    $cycles = success_cycles($pa['frequency'], $bounds);

    $sumFinal = 0.0;
    $countFinal = 0;
    $observedCompleted = 0;
    $eligibleCompleted = 0;
    $ambiguous = 0;
    $actualTotal = 0.0;
    $liveActual = null;

    foreach ($cycles as $cycle) {
        $status = success_cycle_status($cycle, $pa['frequency'], $today, $started_jalali, $archived_jalali, $plan_started);
        $agg = success_cycle_actual($pa, $related, $cycle);

        // A score belongs to an assessable cycle only. Excluded cycles
        // (partial start, archive, out of range, future) keep their raw
        // events for the details view but never carry a score.
        $assessable = ($status === 'COMPLETED' || $status === 'IN_PROGRESS');
        $res = $assessable
            ? success_cycle_achievement($pa, $agg)
            : array('achievement' => null, 'status' => null);

        $base['cycles'][] = array(
            'key' => $cycle['key'], 'start' => $cycle['start'], 'end' => $cycle['end'],
            'status' => $status, 'has_data' => $agg['has_data'],
            'actual' => $agg['has_data'] ? (float) $agg['actual'] : null,
            'event_count' => $agg['count'],
            'achievement' => $res['achievement'], 'cycle_status' => $res['status'],
            // Raw events stay visible in the details even when the cycle is
            // excluded from Achievement / Coverage / Overall.
            'raw_event_count' => $agg['count'],
            'raw_actual' => $agg['has_data'] ? (float) $agg['actual'] : null,
            'events' => $agg['events'],
        );

        // Decision 4 — live data of the current cycle (display only)
        if ($status === 'IN_PROGRESS') {
            if ($agg['has_data']) $liveActual = (float) $agg['actual'];
        }

        if ($status === 'COMPLETED') {
            $configOk = ($cfg === 'OK');
            if ($configOk) $eligibleCompleted++;                    // denominator (Decision 1: keeps ambiguous cycles)
            if ($res['status'] === 'AMBIGUOUS_DUPLICATE') $ambiguous++;
            if ($agg['has_data'] && $res['achievement'] !== null) {
                $observedCompleted++;                               // numerator excludes ambiguous
                $actualTotal += (float) $agg['actual'];
                $sumFinal += $res['achievement'];
                $countFinal++;
            }
        }
    }

    $base['cycle_count'] = count($cycles);
    $base['observed_cycle_count'] = $observedCompleted;
    $base['eligible_cycle_count'] = $eligibleCompleted;
    $base['ambiguous_cycle_count'] = $ambiguous;
    if ($countFinal > 0) $base['actual'] = (float) $actualTotal;
    if ($countFinal > 0) $base['activity_achievement'] = $sumFinal / $countFinal;
    if ($eligibleCompleted > 0) $base['coverage'] = $observedCompleted / $eligibleCompleted;

    // Live fields (Decision 4)
    if ($liveActual !== null) {
        $base['live_actual'] = $liveActual;
        $base['live_target'] = (float) $pa['target_value'];
        $base['live_raw_progress'] = ((float) $pa['target_value'] > 0) ? ($liveActual / (float) $pa['target_value']) : null;
    }

    if ($pa['data_type'] === 'RATING') {
        $base['rating_summary'] = success_rating_summary($pa, $related, $cycles);
    }

    // ---- Status resolution: never guess, never a fake zero (§2) ----
    if (!$plan_started) {
        $base['data_status'] = 'NOT_STARTED';
    } elseif ($cfg !== 'OK') {
        $base['data_status'] = $cfg;                       // UNSUPPORTED / INVALID_CONFIG / EXCLUDED
    } elseif ($ambiguous > 0 && $countFinal === 0) {
        $base['data_status'] = 'AMBIGUOUS_DUPLICATE';      // Decision 1 — no healthy cycle left
    } elseif ($eligibleCompleted === 0) {
        $base['data_status'] = 'NO_ELIGIBLE_CYCLES';
    } elseif ($observedCompleted === 0) {
        $base['data_status'] = 'NO_DATA';
    } else {
        $base['data_status'] = 'OK';
    }

    // ---- Membership in M (Decision 5, 7, 8, 9) ----
    // valid_analytic_activities: valid + supported + computable, BEFORE the weight filter.
    // weighted_eligible_activities (M): the subset with weight > 0.
    if ($cfg === 'EXCLUDED') {                       // RATING -> out of valid analytic
        $base['exclusion_reason'] = 'RATING_EXCLUDED';
    } elseif ($cfg === 'UNSUPPORTED') {
        $base['exclusion_reason'] = 'UNSUPPORTED';
    } elseif ($cfg === 'INVALID_CONFIG') {
        $base['exclusion_reason'] = 'INVALID_CONFIG';
    } else {
        $base['in_valid_analytic'] = true;
        if ((int) $pa['weight'] <= 0) {
            $base['exclusion_reason'] = 'WEIGHT_ZERO';   // Decision 8 — shows its own numbers
        } else {
            $base['in_weighted_eligible'] = true;        // M
        }
    }

    $base['weighted_contribution'] = ($base['activity_achievement'] === null)
        ? null
        : $base['activity_achievement'] * $base['weight'];

    $base['status_message_fa'] = success_status_message_fa($base['data_status'], $pa['data_type']);

    return $base;
}

/**
 * Decision 10 — distinct, user-facing texts. 0% is reserved for a recorded Actual = 0.
 * NOTE: these strings are a PROPOSAL and must be approved in the UI phase.
 */
function success_status_message_fa($status, $data_type) {
    $map = array(
        'OK'                  => 'محاسبه‌شده از داده واقعی',
        'NO_DATA'             => 'هنوز ثبتی ندارد',
        'NO_ELIGIBLE_CYCLES'  => 'هنوز دوره‌ای برای ارزیابی کامل نشده',
        'NOT_STARTED'         => 'برنامه هنوز شروع نشده',
        'IN_PROGRESS'         => 'در جریان',
        'INVALID_CONFIG'      => 'تنظیمات این فعالیت ناقص است (هدف یا واحد نامعتبر)',
        'UNSUPPORTED'         => 'این نوع فعالیت در نسخه فعلی محاسبه نمی‌شود',
        'EXCLUDED'            => 'امتیازدهی — فقط نمودار و میانگین',
        'AMBIGUOUS_DUPLICATE' => 'برای این روز بیش از یک ثبت وجود دارد و محاسبه ممکن نیست',
    );
    return isset($map[$status]) ? $map[$status] : $status;
}

/* ------------------------------------------------------------------ */
/* Plan level (Decision 2, 5, 6, 18-20)                                */
/* ------------------------------------------------------------------ */

function success_overall($activity_rows) {
    $num = 0.0;
    $den = 0.0;
    $observed = 0;     // N  = weighted_observed_activities
    $inM = 0;          // M  = weighted_eligible_activities
    $validAnalytic = 0;
    $excluded = array();
    $sumObserved = 0;
    $sumEligible = 0;
    $ratings = array();

    foreach ($activity_rows as $r) {
        if ($r['data_type'] === 'RATING') {
            $ratings[] = array('plan_activity_id' => $r['plan_activity_id'], 'activity_code' => $r['activity_code'], 'summary' => $r['rating_summary']);
        }
        if ($r['in_valid_analytic']) $validAnalytic++;
    if (!$r['in_weighted_eligible']) {
            $excluded[] = array(
                'plan_activity_id' => $r['plan_activity_id'],
                'activity_code' => $r['activity_code'],
                'data_status' => $r['data_status'],
                'reason' => $r['exclusion_reason'],
            );
            continue;
        }
        $inM++;
        $sumObserved += (int) $r['observed_cycle_count'];
        $sumEligible += (int) $r['eligible_cycle_count'];
        if ($r['activity_achievement'] === null) continue;
        $num += $r['activity_achievement'] * $r['weight'];
        $den += $r['weight'];
        $observed++;
    }

    $overall = null;
    if ($validAnalytic === 0) {
        // Nothing valid, supported and computable at all.
        $status = 'NO_ELIGIBLE_ACTIVITIES';
    } elseif ($inM === 0) {
        // Valid activities exist, but none carries a weight -> nothing to weight.
        $status = 'INSUFFICIENT_DATA';
    } elseif ($observed === 0) {
        // Weighted activities exist, but no final ActivityAchievement yet.
        $status = ($sumEligible === 0) ? 'NO_ELIGIBLE_CYCLES' : 'NO_DATA';
    } else {
        $overall = $num / $den;
        $status = 'OK';
    }

    return array(
        'overall_success' => $overall,
        'overall_status' => $status,
        'metric_version' => success_metric_version(),
        'valid_analytic_activities' => $validAnalytic,
        'weighted_observed_activities' => $observed,   // N
        'weighted_eligible_activities' => $inM,        // M
        'excluded' => $excluded,
        'overall_coverage' => ($sumEligible > 0) ? ($sumObserved / $sumEligible) : null,
        'rating_analyses' => $ratings,
    );
}

/* ------------------------------------------------------------------ */
/* Entry point                                                         */
/* ------------------------------------------------------------------ */

function success_report($plan, $bounds, $activities, $events, $today) {
    // Decision (ARCH-01): a plan that really started is either RUNNING or
    // ARCHIVED. An archived plan is a *finished* period, so it must keep its
    // final score for good; the archive rules below then exclude every cycle
    // that the archive date cut off, and no prorating is ever applied.
    $plan_started = !empty($plan['started_at'])
        && in_array($plan['status'], array('RUNNING', 'ARCHIVED'), true);
    $started_jalali = $plan_started ? success_gregorian_to_jalali_date($plan['started_at']) : null;
    $archived_jalali = (!empty($plan['archived_at'])) ? success_gregorian_to_jalali_date($plan['archived_at']) : null;

    $rows = array();
    foreach ($activities as $pa) {
        $rows[] = success_activity($pa, $events, $bounds, $today, $started_jalali, $archived_jalali, $plan_started);
    }
    $overall = success_overall($rows);

    return array(
        'metric_version' => success_metric_version(),
        'plan_id' => $plan['id'],
        'plan_status' => $plan['status'],
        'period_key' => $plan['period_key'],
        'started_jalali' => $started_jalali,
        'archived_jalali' => $archived_jalali,
        'today' => $today,
        'activities' => $rows,
        'overall_success' => $overall['overall_success'],
        'overall_status' => $overall['overall_status'],
        'valid_analytic_activities' => $overall['valid_analytic_activities'],
        'weighted_observed_activities' => $overall['weighted_observed_activities'],
        'weighted_eligible_activities' => $overall['weighted_eligible_activities'],
        'overall_coverage' => $overall['overall_coverage'],
        'excluded' => $overall['excluded'],
        'rating_analyses' => $overall['rating_analyses'],
    );
}
