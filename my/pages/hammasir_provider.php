<?php
/*
 * ماژول «هم‌مسیر» — میز کار مدیریتی مشاور (فاز A — حکم PO؛ بازنویسی کامل)
 * ------------------------------------------------------------------
 * فقط از pages/hammasir.php include می‌شود؛ route عمومی ندارد (D29/D30).
 * متغیر مورد انتظار: $hammasir_me (user_id کاربر جاری — همراه ثبت‌شده)
 *
 * سطح ۱ (بدون انتخاب): داشبورد ۷ بخشی — هدر/امروز/KPI/نیازمند توجه/
 *   حال‌وهوای کلینیک/مراجعان من/گفتگوهای اخیر.
 * سطح ۲ (انتخاب لینک در session با provider_open — بدون id در Query String):
 *   جزئیات همان مراجع (تب‌های بومی پرونده/گزارش‌ها/گفتگو) + بازگشت به فهرست.
 * همه‌ی داده‌های JOMA فقط از لایه‌ی read-only/DTO؛ خروجی‌ها e()؛ اعداد فارسی.
 */

// گارد مستقیم — partial هرگز مستقیماً از وب اجرا نمی‌شود → 403 خشک (AC6.3)
if (!defined('JOMA_IN_APP')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

// ---------- خواندهای پایه (مهار Throwable — D-1) ----------
$hammasir_p_row = null;
$hammasir_p_links = array();
try {
    $hammasir_p_row = hammasir_provider_by_user_id($hammasir_me);
    $tmp = hammasir_links_by_provider($hammasir_me);
    if (is_array($tmp)) $hammasir_p_links = $tmp;
} catch (Throwable $e) {
    $hammasir_p_row = null;
    $hammasir_p_links = array();
}
$hammasir_p_pending = array();
$hammasir_p_active = array();
foreach ($hammasir_p_links as $hammasir_p_l0) {
    if ($hammasir_p_l0['status'] === 'PENDING') $hammasir_p_pending[] = $hammasir_p_l0;
    elseif ($hammasir_p_l0['status'] === 'ACTIVE') $hammasir_p_active[] = $hammasir_p_l0;
}

$hammasir_p_status_chips = array(
    'PENDING' => 'در انتظار',
    'ACTIVE' => 'فعال',
    'DECLINED' => 'رد',
    'REVOKED' => 'قطع',
);

// ---------- لینکِ انتخاب‌شده (سطح ۲) — فقط از session؛ اعتبارسنجی مالکیت ----------
$hammasir_p_open = null;
if (isset($_SESSION['hammasir_prov_open'])) {
    $hammasir_p_want = (int) $_SESSION['hammasir_prov_open'];
    foreach ($hammasir_p_links as $hammasir_p_l1) {
        if ((int) $hammasir_p_l1['id'] === $hammasir_p_want) {
            $hammasir_p_open = $hammasir_p_l1;
            break;
        }
    }
    if ($hammasir_p_open === null) unset($_SESSION['hammasir_prov_open']);
}

if ($hammasir_p_open !== null) {
    // ============================================================
    // سطح ۲ — جزئیات یک مراجع (تب‌های بومی؛ گفتگو با پیام نخوانده خودکار باز)
    // ============================================================
    $hammasir_p_name = '';
    $hammasir_p_perms = null;
    $hammasir_p_msg_on = false;
    $hammasir_p_unread = 0;
    try {
        $hammasir_p_name = hammasir_user_display((int) $hammasir_p_open['client_user_id']);
        $hammasir_p_perms = hammasir_perm_map((int) $hammasir_p_open['id']);
        $hammasir_p_msg_on = (hammasir_messaging_enabled((int) $hammasir_p_open['id']) === true);
        if (store_mode() === 'mysql') {
            $r = joma_query_one('SELECT COUNT(*) AS c FROM joma_hammasir_messages WHERE link_id=? AND recipient_user_id=? AND is_read=0', 'ii', array((int) $hammasir_p_open['id'], $hammasir_me));
            if ($r) $hammasir_p_unread = (int) $r['c'];
        } else {
            $st = hammasir_store_load();
            if ($st !== null) {
                foreach ($st['messages'] as $msg) {
                    if ((int) $msg['link_id'] === (int) $hammasir_p_open['id'] && (int) $msg['recipient_user_id'] === $hammasir_me && (int) $msg['is_read'] === 0) $hammasir_p_unread++;
                }
            }
        }
    } catch (Throwable $e) {
        $hammasir_p_name = '';
        $hammasir_p_perms = null;
        $hammasir_p_msg_on = false;
        $hammasir_p_unread = 0;
    }
    $hammasir_p_labels = hammasir_perm_view_labels();
    $hammasir_p_on = array(
        'VIEW_SUMMARY' => ($hammasir_p_perms !== null && isset($hammasir_p_perms['VIEW_SUMMARY']) && (int) $hammasir_p_perms['VIEW_SUMMARY'] === 1),
        'VIEW_PROGRESS' => ($hammasir_p_perms !== null && isset($hammasir_p_perms['VIEW_PROGRESS']) && (int) $hammasir_p_perms['VIEW_PROGRESS'] === 1),
        'VIEW_ACTIVITY_DETAILS' => ($hammasir_p_perms !== null && isset($hammasir_p_perms['VIEW_ACTIVITY_DETAILS']) && (int) $hammasir_p_perms['VIEW_ACTIVITY_DETAILS'] === 1),
        'VIEW_MOOD' => ($hammasir_p_perms !== null && isset($hammasir_p_perms['VIEW_MOOD']) && (int) $hammasir_p_perms['VIEW_MOOD'] === 1),
    );
    ?>
    <section class="card">
      <div class="btn-row">
        <span class="chip"><?php echo e($hammasir_p_name); ?></span>
        <span class="chip"><?php echo e(isset($hammasir_p_status_chips[$hammasir_p_open['status']]) ? $hammasir_p_status_chips[$hammasir_p_open['status']] : $hammasir_p_open['status']); ?></span>
        <form method="post">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="hammasir_action" value="provider_close">
          <button class="btn sec" type="submit">بازگشت به فهرست</button>
        </form>
      </div>
      <?php if ($hammasir_p_open['status'] === 'PENDING') { ?>
        <p>کاربری درخواست کرده شما همراه او باشید.</p>
        <p><?php echo e(hammasir_consent_text()); ?></p>
        <label>سطح دسترسی درخواست‌شده</label>
        <div class="btn-row">
          <?php foreach (array('VIEW_SUMMARY', 'VIEW_PROGRESS', 'VIEW_ACTIVITY_DETAILS', 'VIEW_MOOD') as $hammasir_p_k) { ?>
            <?php $hammasir_p_onk = ($hammasir_p_perms !== null && isset($hammasir_p_perms[$hammasir_p_k]) && (int) $hammasir_p_perms[$hammasir_p_k] === 1); ?>
            <span class="chip"><?php echo e($hammasir_p_labels[$hammasir_p_k] . ($hammasir_p_onk ? ' ✓' : ' ✗')); ?></span>
          <?php } ?>
        </div>
        <div class="btn-row">
          <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="hammasir_action" value="link_accept">
            <input type="hidden" name="link_id" value="<?php echo (int) $hammasir_p_open['id']; ?>">
            <button class="btn" type="submit">قبول</button>
          </form>
          <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="hammasir_action" value="link_decline">
            <input type="hidden" name="link_id" value="<?php echo (int) $hammasir_p_open['id']; ?>">
            <button class="btn sec" type="submit">رد</button>
          </form>
        </div>
      <?php } elseif ($hammasir_p_open['status'] === 'ACTIVE') { ?>
        <details open>
          <summary>پرونده</summary>
          <?php if ($hammasir_p_on['VIEW_SUMMARY']) {
              $hammasir_p_dto = null;
              try { $hammasir_p_dto = hammasir_dto_summary((int) $hammasir_p_open['client_user_id']); } catch (Throwable $e) { $hammasir_p_dto = null; }
              ?>
              <?php if ($hammasir_p_dto !== null) { ?>
                <div class="btn-row">
                  <span class="chip">دوره: <?php echo e(jalali_period_label($hammasir_p_dto['period_key'])); ?></span>
                  <span class="chip">وضعیت برنامه: <?php echo e($hammasir_p_dto['plan_status']); ?></span>
                  <span class="chip">فعالیت‌ها: <?php echo fa_num((int) $hammasir_p_dto['activity_count']); ?></span>
                  <span class="chip">رویدادهای ثبت‌شده: <?php echo fa_num((int) $hammasir_p_dto['event_count']); ?></span>
                  <span class="chip">جمع وزن‌ها: <?php echo fa_num((int) $hammasir_p_dto['weight_sum']); ?></span>
                  <span class="chip">روزهای ثبت‌شده: <?php echo fa_num((int) $hammasir_p_dto['days_with_events']); ?></span>
                </div>
              <?php } else { ?>
                <p>هنوز برنامه‌ای برای نمایش وجود ندارد.</p>
              <?php } ?>
          <?php } ?>
          <?php if ($hammasir_p_on['VIEW_PROGRESS']) {
              $hammasir_p_dto = null;
              try { $hammasir_p_dto = hammasir_dto_progress((int) $hammasir_p_open['client_user_id']); } catch (Throwable $e) { $hammasir_p_dto = null; }
              ?>
              <?php if ($hammasir_p_dto !== null && (count($hammasir_p_dto['activities']) > 0 || $hammasir_p_dto['overall_coverage'] !== null)) { ?>
                <div class="btn-row">
                  <?php if ($hammasir_p_dto['overall_coverage'] !== null) { ?><span class="chip">پوشش کل: <?php echo e(fa_num(round((float) $hammasir_p_dto['overall_coverage'] * 100)) . '٪'); ?></span><?php } ?>
                  <?php if ($hammasir_p_dto['overall_success'] !== '') { ?><span class="chip">وضعیت کلی: <?php echo e(hammasir_achievement_label($hammasir_p_dto['overall_success'])); ?></span><?php } ?>
                </div>
                <?php foreach ($hammasir_p_dto['activities'] as $hammasir_p_a) { ?>
                <div class="btn-row">
                  <span class="chip"><?php echo e($hammasir_p_a['title']); ?></span>
                  <?php if ($hammasir_p_a['coverage'] !== null) { ?><span class="chip"><?php echo e(fa_num(round((float) $hammasir_p_a['coverage'] * 100)) . '٪'); ?></span><?php } ?>
                  <span class="chip"><?php echo e(hammasir_achievement_label($hammasir_p_a['achievement'])); ?></span>
                </div>
                <?php } ?>
              <?php } else { ?>
                <p>داده‌ی پیشرفت برای نمایش وجود ندارد.</p>
              <?php } ?>
          <?php } ?>
          <?php if (!$hammasir_p_on['VIEW_SUMMARY'] && !$hammasir_p_on['VIEW_PROGRESS']) { ?>
            <p>برای این مراجع دسترسی مشاهده‌ی پرونده داده نشده است.</p>
          <?php } ?>
        </details>
        <details>
          <summary>گزارش‌ها</summary>
          <?php if ($hammasir_p_on['VIEW_ACTIVITY_DETAILS']) {
              $hammasir_p_dto = null;
              try { $hammasir_p_dto = hammasir_dto_activity_details((int) $hammasir_p_open['client_user_id']); } catch (Throwable $e) { $hammasir_p_dto = null; }
              ?>
              <?php if ($hammasir_p_dto !== null) { ?>
                <?php if (count($hammasir_p_dto['activities']) > 0) { ?>
                <h4>فعالیت‌های برنامه</h4>
                <?php foreach ($hammasir_p_dto['activities'] as $hammasir_p_a) { ?>
                <div class="btn-row">
                  <span class="chip"><?php echo e($hammasir_p_a['title']); ?></span>
                  <span class="chip">هدف: <?php echo e($hammasir_p_a['target'] . ' ' . $hammasir_p_a['unit']); ?></span>
                </div>
                <?php } ?>
                <?php } ?>
                <?php if (count($hammasir_p_dto['events']) > 0) { ?>
                <h4>آخرین ثبت‌ها</h4>
                <?php foreach ($hammasir_p_dto['events'] as $hammasir_p_e) { ?>
                <div class="btn-row">
                  <span class="chip"><?php echo e(jalali_format($hammasir_p_e['date'])); ?></span>
                  <span><?php echo e($hammasir_p_e['title'] . ': ' . $hammasir_p_e['value'] . ' ' . $hammasir_p_e['unit']); ?></span>
                </div>
                <?php } ?>
                <?php } ?>
                <?php if (count($hammasir_p_dto['activities']) === 0 && count($hammasir_p_dto['events']) === 0) { ?>
                <p>هنوز فعالیتی ثبت نشده است.</p>
                <?php } ?>
              <?php } ?>
          <?php } ?>
          <?php if ($hammasir_p_on['VIEW_MOOD']) {
              $hammasir_p_dto = null;
              try { $hammasir_p_dto = hammasir_dto_mood((int) $hammasir_p_open['client_user_id']); } catch (Throwable $e) { $hammasir_p_dto = null; }
              ?>
              <?php if (is_array($hammasir_p_dto) && count($hammasir_p_dto) > 0) { ?>
                <h4>حال و احوال (۳۰ روز اخیر)</h4>
                <?php foreach ($hammasir_p_dto as $hammasir_p_m) { ?>
                <div class="btn-row">
                  <span class="chip"><?php echo e(jalali_format($hammasir_p_m['date'])); ?></span>
                  <span>حال کلی: <?php echo e((string) fa_num((int) $hammasir_p_m['general_mood'])); ?> — انرژی: <?php echo e((string) fa_num((int) $hammasir_p_m['energy'])); ?> — استرس: <?php echo e((string) fa_num((int) $hammasir_p_m['stress'])); ?></span>
                  <?php if ($hammasir_p_m['note'] !== '') { ?><small><?php echo e($hammasir_p_m['note']); ?></small><?php } ?>
                </div>
                <?php } ?>
              <?php } else { ?>
                <p>حال و احوالی ثبت نشده است.</p>
              <?php } ?>
          <?php } ?>
          <?php if (!$hammasir_p_on['VIEW_ACTIVITY_DETAILS'] && !$hammasir_p_on['VIEW_MOOD']) { ?>
            <p>برای این مراجع دسترسی مشاهده‌ی گزارش‌ها داده نشده است.</p>
          <?php } ?>
        </details>
        <details<?php if ($hammasir_p_unread > 0) echo ' open'; ?>>
          <summary>گفتگو<?php if ($hammasir_p_unread > 0) echo ' (' . fa_num($hammasir_p_unread) . ')'; ?></summary>
          <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="hammasir_action" value="messaging_set">
            <input type="hidden" name="link_id" value="<?php echo (int) $hammasir_p_open['id']; ?>">
            <input type="hidden" name="enabled" value="<?php echo $hammasir_p_msg_on ? '0' : '1'; ?>">
            <label>مایل هستم از این مراجع پیام دریافت کنم.</label>
            <div class="btn-row">
              <button class="btn sec" type="submit"><?php echo $hammasir_p_msg_on ? 'غیرفعال‌سازی پیام‌رسانی' : 'فعال‌سازی پیام‌رسانی'; ?></button>
            </div>
          </form>
          <?php if ($hammasir_p_msg_on) { ?>
            <?php
            $hammasir_link = $hammasir_p_open;
            $hammasir_other_label = $hammasir_p_name;
            $hammasir_msg_on = true;
            include dirname(__FILE__) . '/hammasir_chat.php';
            ?>
          <?php } ?>
        </details>
        <form method="post">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="hammasir_action" value="link_revoke">
          <input type="hidden" name="link_id" value="<?php echo (int) $hammasir_p_open['id']; ?>">
          <div class="btn-row">
            <button class="btn sec" type="submit">قطع ارتباط</button>
          </div>
        </form>
      <?php } else { ?>
        <?php if ($hammasir_p_open['status'] === 'DECLINED') { ?>
          <p>این درخواست رد شده است.</p>
        <?php } else { ?>
          <p>این ارتباط قطع شده است.</p>
        <?php } ?>
      <?php } ?>
    </section>
    <?php
    return;
}

// ============================================================
// سطح ۱ — داشبورد مدیریتی مشاور (۷ بخش)
// ============================================================
$hammasir_p_name_me = '';
try { $hammasir_p_name_me = hammasir_user_display($hammasir_me); } catch (Throwable $e) { $hammasir_p_name_me = ''; }

$hammasir_p_kpis = array('active_count' => 0, 'pending_count' => 0, 'unread_count' => 0, 'week_performance_count' => 0, 'has_progress_view' => false);
try { $hammasir_p_kpis = hammasir_dashboard_kpis($hammasir_me); if (!is_array($hammasir_p_kpis)) $hammasir_p_kpis = array('active_count' => 0, 'pending_count' => 0, 'unread_count' => 0, 'week_performance_count' => 0, 'has_progress_view' => false); } catch (Throwable $e) { }

$hammasir_p_attention = array();
try { $hammasir_p_attention = hammasir_attention_items($hammasir_me); if (!is_array($hammasir_p_attention)) $hammasir_p_attention = array(); } catch (Throwable $e) { $hammasir_p_attention = array(); }

$hammasir_p_strip = array('rows' => array(), 'more' => 0);
$hammasir_p_strip_on = false;
try {
    $hammasir_p_strip = hammasir_clinic_mood_strip($hammasir_me);
    if (!is_array($hammasir_p_strip)) $hammasir_p_strip = array('rows' => array(), 'more' => 0);
    $hammasir_p_strip_on = (count($hammasir_p_strip['rows']) > 0 || $hammasir_p_strip['more'] > 0);
} catch (Throwable $e) { $hammasir_p_strip = array('rows' => array(), 'more' => 0); }

// کارت‌های مراجعان (ACTIVE + PENDING) — داده‌های هر کارت با مهار خطا
$hammasir_p_cards = array();
foreach ($hammasir_p_active as $hammasir_p_l2) {
    $card = array('link' => $hammasir_p_l2, 'status' => 'ACTIVE', 'name' => '', 'last' => null, 'last_lbl' => '', 'low' => false, 'unread' => 0, 'coverage' => null, 'dots' => null, 'has_prog' => false, 'has_mood' => false);
    try {
        $card['name'] = hammasir_user_display((int) $hammasir_p_l2['client_user_id']);
        $card['last'] = hammasir_client_last_activity((int) $hammasir_p_l2['id']);
        $card['last_lbl'] = hammasir_time_ago($card['last']);
        if ($card['last'] === null) { $card['low'] = true; $card['last_lbl'] = 'بدون فعالیت'; }
        else {
            $d = (int) floor((strtotime(hammasir_now()) - strtotime($card['last'])) / 86400);
            if ($d >= 5) $card['low'] = true;
        }
        $perms = hammasir_perm_map((int) $hammasir_p_l2['id']);
        $card['has_prog'] = ($perms !== null && isset($perms['VIEW_PROGRESS']) && (int) $perms['VIEW_PROGRESS'] === 1);
        $card['has_mood'] = ($perms !== null && isset($perms['VIEW_MOOD']) && (int) $perms['VIEW_MOOD'] === 1);
        if ($card['has_prog']) {
            $dto = hammasir_dto_progress((int) $hammasir_p_l2['client_user_id']);
            if (is_array($dto) && $dto['overall_coverage'] !== null) $card['coverage'] = (int) round((float) $dto['overall_coverage'] * 100);
        }
        if ($card['has_mood']) {
            $dots = hammasir_mood_dots((int) $hammasir_p_l2['id']);
            if (is_array($dots)) $card['dots'] = $dots;
        }
        if (store_mode() === 'mysql') {
            $r = joma_query_one('SELECT COUNT(*) AS c FROM joma_hammasir_messages WHERE link_id=? AND recipient_user_id=? AND is_read=0', 'ii', array((int) $hammasir_p_l2['id'], $hammasir_me));
            if ($r) $card['unread'] = (int) $r['c'];
        } else {
            $st = hammasir_store_load();
            if ($st !== null) {
                foreach ($st['messages'] as $msg) {
                    if ((int) $msg['link_id'] === (int) $hammasir_p_l2['id'] && (int) $msg['recipient_user_id'] === $hammasir_me && (int) $msg['is_read'] === 0) $card['unread']++;
                }
            }
        }
    } catch (Throwable $e) {
        // کارت با داده‌ی حداقلی ادامه می‌دهد (fail-safe بخش)
    }
    $hammasir_p_cards[] = $card;
}
foreach ($hammasir_p_pending as $hammasir_p_l3) {
    $card = array('link' => $hammasir_p_l3, 'status' => 'PENDING', 'name' => '', 'last' => null, 'last_lbl' => '', 'low' => false, 'unread' => 0, 'coverage' => null, 'dots' => null, 'has_prog' => false, 'has_mood' => false);
    try {
        $card['name'] = hammasir_user_display((int) $hammasir_p_l3['client_user_id']);
        $card['last_lbl'] = hammasir_time_ago(isset($hammasir_p_l3['requested_at']) ? $hammasir_p_l3['requested_at'] : null);
    } catch (Throwable $e) { }
    $hammasir_p_cards[] = $card;
}
// ترتیب: فعال‌ترین‌ها اول (آخرین فعالیت نزولی)؛ سپس درخواست‌های در انتظار
usort($hammasir_p_cards, function ($a, $b) {
    if ($a['status'] !== $b['status']) return ($a['status'] === 'ACTIVE') ? -1 : 1;
    $ta = $a['last'] ? strtotime($a['last']) : 0;
    $tb = $b['last'] ? strtotime($b['last']) : 0;
    if ($ta === $tb) return 0;
    return ($ta > $tb) ? -1 : 1;
});

// گفتگوهای اخیر: ۳ پیام آخر از همه‌ی لینک‌های ACTIVE
$hammasir_p_recent = array();
try {
    $cand = array();
    if (store_mode() === 'mysql') {
        foreach ($hammasir_p_active as $hammasir_p_l4) {
            $rows = joma_query('SELECT * FROM joma_hammasir_messages WHERE link_id=? ORDER BY created_at DESC, id DESC LIMIT 3', 'i', array((int) $hammasir_p_l4['id']));
            foreach ($rows as $r) $cand[] = $r;
        }
    } else {
        $st = hammasir_store_load();
        if ($st !== null) {
            $ids = array();
            foreach ($hammasir_p_active as $hammasir_p_l4) $ids[] = (int) $hammasir_p_l4['id'];
            foreach ($st['messages'] as $msg) {
                if (in_array((int) $msg['link_id'], $ids, true)) $cand[] = $msg;
            }
        }
    }
    usort($cand, function ($a, $b) {
        $ta = strtotime((string) $a['created_at']);
        $tb = strtotime((string) $b['created_at']);
        if ($ta === $tb) return ((int) $a['id'] > (int) $b['id']) ? -1 : 1;
        return ($ta > $tb) ? -1 : 1;
    });
    $hammasir_p_recent = array_slice($cand, 0, 3);
} catch (Throwable $e) {
    $hammasir_p_recent = array();
}

// آیکون‌های SVG خطی داشبورد (اینلاین — PC-1؛ بدون کتابخانه)
$hammasir_hd_icons = array(
    'users' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#2f7a52" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 20c.7-3 2.8-4.6 5.5-4.6s4.8 1.6 5.5 4.6"/><path d="M15.5 5.2a3.2 3.2 0 0 1 0 5.9"/><path d="M16 15.7c1.9.5 3.2 1.9 3.8 4.3"/></svg>',
    'clock' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#C49A3C" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    'bubble' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#7C6AA8" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6a3 3 0 0 1 3-3h10a3 3 0 0 1 3 3v7a3 3 0 0 1-3 3H9l-5 4z"/></svg>',
    'check' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#4E9B94" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.5 2.5 5.5-6"/></svg>',
    'flag' => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#C49A3C" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 21V4"/><path d="M6 5h11l-2.5 3.5L17 12H6"/></svg>',
    'sun' => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#C4A34A" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 3v2"/><path d="M12 19v2"/><path d="M3 12h2"/><path d="M19 12h2"/><path d="M6 6l1.5 1.5"/><path d="M16.5 16.5 18 18"/><path d="M18 6l-1.5 1.5"/><path d="M7.5 16.5 6 18"/></svg>',
    'chat' => '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="#7C6AA8" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6a3 3 0 0 1 3-3h10a3 3 0 0 1 3 3v7a3 3 0 0 1-3 3H9l-5 4z"/></svg>',
    'users40' => '<svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="#b9aede" stroke-width="1.4" stroke-linecap="round" aria-hidden="true"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 20c.7-3 2.8-4.6 5.5-4.6s4.8 1.6 5.5 4.6"/><path d="M15.5 5.2a3.2 3.2 0 0 1 0 5.9"/><path d="M16 15.7c1.9.5 3.2 1.9 3.8 4.3"/></svg>',
);

$hammasir_hd_dotcolor = function ($v) {
    if ($v === null) return '';
    $v = (int) $v;
    if ($v >= 7) return '#7BC49A';
    if ($v >= 5) return '#B0B0B0';
    if ($v >= 3) return '#D4A0A0';
    return '#C97B94';
};
?>
<style>
.hd-sec{margin-bottom:24px;animation:hdFadeIn 0.3s ease both;}
.hd-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;}
.hd-kpi{background:#fff;border-radius:16px;box-shadow:0 2px 12px rgba(0,0,0,0.06);padding:20px;display:flex;align-items:center;gap:12px;}
.hd-kpi .v{font-size:28px;font-weight:700;line-height:1.2;}
.hd-kpi .k{font-size:12px;color:#888;}
.hd-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;}
.hd-card{background:#fff;border-radius:16px;box-shadow:0 2px 12px rgba(0,0,0,0.06);padding:20px;}
.hd-bar{height:6px;border-radius:3px;background:#eee7dd;overflow:hidden;margin:10px 0 6px;}
.hd-bar i{display:block;height:100%;border-radius:3px;background:#4E9B94;}
.hd-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-left:3px;border:1px solid transparent;vertical-align:middle;box-sizing:border-box;}
.hd-dot.empty{border:1px solid #d8d2c8;background:transparent;}
.hd-dot.big{width:12px;height:12px;}
.hd-item{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 0;border-bottom:1px solid #f1ece4;}
.hd-item:last-child{border-bottom:none;}
.hd-pulse{display:inline-block;width:8px;height:8px;border-radius:50%;flex:none;}
.hd-strip{display:flex;flex-wrap:wrap;gap:10px 22px;}
@keyframes hdFadeIn{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:none;}}
@media (max-width: 768px){
  .hd-kpis{grid-template-columns:repeat(2,1fr);}
  .hd-cards{grid-template-columns:1fr;}
}
</style>

<section class="card hd-sec" style="animation-delay:0s">
  <div class="btn-row" style="justify-content:space-between;width:100%;">
    <div style="display:flex;align-items:center;gap:12px;">
      <?php echo joma_logo(40, true); ?>
      <div>
        <h2 style="margin:0;">سلام، <?php echo e($hammasir_p_name_me); ?></h2>
        <?php if ($hammasir_p_row) { ?><small style="opacity:.75;"><?php echo e($hammasir_p_row['title']); ?></small><?php } ?>
      </div>
    </div>
    <span class="chip">حالت مشاور</span>
  </div>
</section>

<?php
// بخش ۲ — نمای امروز (حذف بخش‌های صفر)
$hammasir_p_today_parts = array();
if ($hammasir_p_kpis['active_count'] > 0) $hammasir_p_today_parts[] = fa_num($hammasir_p_kpis['active_count']) . ' مراجع فعال';
if ($hammasir_p_kpis['unread_count'] > 0) $hammasir_p_today_parts[] = fa_num($hammasir_p_kpis['unread_count']) . ' پیام جدید';
if ($hammasir_p_kpis['pending_count'] > 0) $hammasir_p_today_parts[] = fa_num($hammasir_p_kpis['pending_count']) . ' درخواست در انتظار';
if (count($hammasir_p_today_parts) > 0) {
    ?>
    <section class="card hd-sec" style="animation-delay:.05s;">
      <p style="font-size:14px;color:#6b6257;margin:0;"><?php echo e(implode(' · ', $hammasir_p_today_parts)); ?></p>
    </section>
    <?php
}
?>

<section class="hd-sec" style="animation-delay:.1s;">
  <div class="hd-kpis">
    <div class="hd-kpi"><?php echo $hammasir_hd_icons['users']; ?><div><div class="v" style="color:#2f7a52;"><?php echo fa_num($hammasir_p_kpis['active_count']); ?></div><div class="k">مراجع فعال</div></div></div>
    <div class="hd-kpi"><?php echo $hammasir_hd_icons['clock']; ?><div><div class="v" style="color:#C49A3C;"><?php echo fa_num($hammasir_p_kpis['pending_count']); ?></div><div class="k">در انتظار پاسخ</div></div></div>
    <div class="hd-kpi"><?php echo $hammasir_hd_icons['bubble']; ?><div><div class="v" style="color:#7C6AA8;"><?php echo fa_num($hammasir_p_kpis['unread_count']); ?></div><div class="k">پیام نخوانده</div></div></div>
    <div class="hd-kpi"><?php echo $hammasir_hd_icons['check']; ?><div>
      <?php if ($hammasir_p_kpis['has_progress_view']) { ?>
        <div class="v" style="color:#4E9B94;"><?php echo fa_num($hammasir_p_kpis['week_performance_count']); ?></div><div class="k">ثبت عملکرد هفته</div>
      <?php } else { ?>
        <div class="v" style="color:#4E9B94;">—</div><div class="k">با اجازه‌ی مراجع</div>
      <?php } ?>
    </div></div>
  </div>
</section>

<section class="card hd-sec" style="animation-delay:.15s;">
  <h2><?php echo $hammasir_hd_icons['flag']; ?> نیازمند توجه</h2>
  <?php if (count($hammasir_p_attention) === 0) { ?>
    <p style="color:#2f7a52;">✓ همه‌چیز مرتب است.</p>
  <?php } else { ?>
    <?php foreach ($hammasir_p_attention as $hammasir_p_ai) { ?>
      <?php
      $hammasir_p_ai_color = '#C49A3C';
      if ($hammasir_p_ai['type'] === 'unread') $hammasir_p_ai_color = '#7C6AA8';
      if ($hammasir_p_ai['type'] === 'mood_decline') $hammasir_p_ai_color = '#C97B94';
      ?>
      <div class="hd-item">
        <span class="hd-pulse" style="background:<?php echo e($hammasir_p_ai_color); ?>;"></span>
        <span>
          <?php if ($hammasir_p_ai['type'] === 'pending') { ?>
            <strong>درخواست همراهی جدید — <?php echo e($hammasir_p_ai['client_name']); ?><?php if ($hammasir_p_ai['time_ago'] !== '') { ?> (<?php echo e($hammasir_p_ai['time_ago']); ?>)<?php } ?></strong>
            <?php
            $hammasir_p_ai_lbls = hammasir_perm_view_labels();
            $hammasir_p_ai_parts = array();
            foreach ($hammasir_p_ai['perms'] as $hammasir_p_pk) {
                $hammasir_p_ai_parts[] = isset($hammasir_p_ai_lbls[$hammasir_p_pk]) ? $hammasir_p_ai_lbls[$hammasir_p_pk] : $hammasir_p_pk;
            }
            ?>
            <?php if (count($hammasir_p_ai_parts) > 0) { ?><small style="display:block;opacity:.75;">دسترسی: <?php echo e(implode('، ', $hammasir_p_ai_parts)); ?></small><?php } ?>
          <?php } elseif ($hammasir_p_ai['type'] === 'unread') { ?>
            <strong>پیام خوانده‌نشده — <?php echo e($hammasir_p_ai['client_name']); ?></strong>
            <small style="display:block;opacity:.75;"><?php echo fa_num((int) $hammasir_p_ai['detail']); ?> پیام<?php if ($hammasir_p_ai['time_ago'] !== '') { ?> · <?php echo e($hammasir_p_ai['time_ago']); ?><?php } ?></small>
          <?php } else { ?>
            <strong>روند نزولی خلق — <?php echo e($hammasir_p_ai['client_name']); ?> (<?php echo fa_num((int) $hammasir_p_ai['detail']); ?> روز متوالی)</strong>
          <?php } ?>
        </span>
        <span style="flex:1;"></span>
        <?php if ($hammasir_p_ai['type'] === 'pending') { ?>
          <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="hammasir_action" value="link_accept"><input type="hidden" name="link_id" value="<?php echo (int) $hammasir_p_ai['link_id']; ?>"><button class="btn" type="submit">قبول</button></form>
          <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="hammasir_action" value="link_decline"><input type="hidden" name="link_id" value="<?php echo (int) $hammasir_p_ai['link_id']; ?>"><button class="btn sec" type="submit">رد</button></form>
        <?php } elseif ($hammasir_p_ai['type'] === 'unread') { ?>
          <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="hammasir_action" value="provider_open"><input type="hidden" name="link_id" value="<?php echo (int) $hammasir_p_ai['link_id']; ?>"><button class="btn sec" type="submit">گفتگو</button></form>
        <?php } else { ?>
          <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="hammasir_action" value="provider_open"><input type="hidden" name="link_id" value="<?php echo (int) $hammasir_p_ai['link_id']; ?>"><button class="btn sec" type="submit">پرونده</button></form>
        <?php } ?>
      </div>
    <?php } ?>
  <?php } ?>
</section>

<?php if ($hammasir_p_strip_on) { ?>
<section class="card hd-sec" style="animation-delay:.2s;">
  <h2><?php echo $hammasir_hd_icons['sun']; ?> حال‌وهوای کلینیک · ۷ روز اخیر</h2>
  <div class="hd-strip">
    <?php foreach ($hammasir_p_strip['rows'] as $hammasir_p_sr) { ?>
      <span style="display:flex;align-items:center;gap:8px;font-size:13px;">
        <span><?php echo e($hammasir_p_sr['client_name']); ?>:</span>
        <?php foreach ($hammasir_p_sr['dots'] as $hammasir_p_dv) { ?>
          <?php $hammasir_p_dc = $hammasir_hd_dotcolor($hammasir_p_dv); ?>
          <?php if ($hammasir_p_dc === '') { ?>
            <span class="hd-dot empty"></span>
          <?php } else { ?>
            <span class="hd-dot" style="background:<?php echo e($hammasir_p_dc); ?>;"></span>
          <?php } ?>
        <?php } ?>
      </span>
    <?php } ?>
    <?php if ($hammasir_p_strip['more'] > 0) { ?>
      <span style="font-size:13px;opacity:.75;">+<?php echo fa_num((int) $hammasir_p_strip['more']); ?> نفر دیگر</span>
    <?php } ?>
  </div>
</section>
<?php } elseif (count($hammasir_p_active) > 0) { ?>
<section class="card hd-sec" style="animation-delay:.2s;">
  <h2><?php echo $hammasir_hd_icons['sun']; ?> حال‌وهوای کلینیک · ۷ روز اخیر</h2>
  <p style="font-size:13px;color:#888;margin:0;">خلق: با اجازه‌ی مراجع</p>
</section>
<?php } ?>

<section class="hd-sec" style="animation-delay:.25s;">
  <h2 style="margin-bottom:12px;">مراجعان من</h2>
  <?php if (count($hammasir_p_cards) === 0) { ?>
    <section class="card" style="text-align:center;padding:34px 20px;">
      <div style="display:flex;justify-content:center;"><?php echo $hammasir_hd_icons['users40']; ?></div>
      <p style="color:#6b6257;">مرجعان شما اینجا فهرست می‌شوند. آن‌ها شما را از منوی «هم‌مسیر» انتخاب می‌کنند و شما درخواست را اینجا می‌پذیرید.</p>
    </section>
  <?php } else { ?>
  <div class="hd-cards">
    <?php foreach ($hammasir_p_cards as $hammasir_p_c) { ?>
      <div class="hd-card">
        <div class="btn-row" style="justify-content:space-between;width:100%;">
          <strong><?php echo e($hammasir_p_c['name']); ?></strong>
          <?php if ($hammasir_p_c['status'] === 'ACTIVE') { ?>
            <?php if ($hammasir_p_c['low']) { ?>
              <span class="chip" style="color:#6E7A8A;">● کم‌فعال ⚠️</span>
            <?php } else { ?>
              <span class="chip" style="color:#2f7a52;">● فعال</span>
            <?php } ?>
          <?php } else { ?>
            <span class="chip" style="color:#C49A3C;">● در انتظار</span>
          <?php } ?>
        </div>
        <?php if ($hammasir_p_c['status'] === 'ACTIVE') { ?>
          <?php if ($hammasir_p_c['has_prog']) { ?>
            <?php $hammasir_p_cov = ($hammasir_p_c['coverage'] !== null) ? (int) $hammasir_p_c['coverage'] : 0; ?>
            <div class="hd-bar"><i style="width:<?php echo (int) $hammasir_p_cov; ?>%;"></i></div>
            <small style="color:#888;"><?php echo fa_num($hammasir_p_cov); ?>٪</small>
          <?php } ?>
          <?php if (is_array($hammasir_p_c['dots'])) { ?>
            <div style="margin:8px 0 2px;font-size:12px;color:#6b6257;">خلق:
              <?php foreach ($hammasir_p_c['dots'] as $hammasir_p_dv) { ?>
                <?php $hammasir_p_dc = $hammasir_hd_dotcolor($hammasir_p_dv); ?>
                <?php if ($hammasir_p_dc === '') { ?>
                  <span class="hd-dot big empty"></span>
                <?php } else { ?>
                  <span class="hd-dot big" style="background:<?php echo e($hammasir_p_dc); ?>;"></span>
                <?php } ?>
              <?php } ?>
            </div>
          <?php } elseif (!$hammasir_p_c['has_mood']) { ?>
            <small style="display:block;margin-top:8px;color:#888;">خلق: با اجازه‌ی مراجع</small>
          <?php } ?>
          <small style="display:block;margin-top:8px;color:#888;">آخرین: <?php echo e($hammasir_p_c['last_lbl']); ?></small>
          <div class="btn-row" style="justify-content:space-between;width:100%;margin-top:10px;">
            <span style="font-size:13px;"><?php if ($hammasir_p_c['unread'] > 0) { ?>💬 <?php echo fa_num($hammasir_p_c['unread']); ?><?php } ?></span>
            <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="hammasir_action" value="provider_open"><input type="hidden" name="link_id" value="<?php echo (int) $hammasir_p_c['link']['id']; ?>"><button class="btn sec" type="submit">پرونده</button></form>
          </div>
        <?php } else { ?>
          <small style="display:block;margin-top:10px;color:#888;">درخواست: <?php echo e($hammasir_p_c['last_lbl']); ?></small>
        <?php } ?>
      </div>
    <?php } ?>
  </div>
  <?php } ?>
</section>

<?php if (count($hammasir_p_recent) > 0) { ?>
<section class="card hd-sec" style="animation-delay:.3s;">
  <h2><?php echo $hammasir_hd_icons['chat']; ?> گفتگوهای اخیر</h2>
  <?php foreach ($hammasir_p_recent as $hammasir_p_rm) { ?>
    <?php
    $hammasir_p_rm_name = '';
    $hammasir_p_rm_unread = 0;
    try {
        foreach ($hammasir_p_active as $hammasir_p_l5) {
            if ((int) $hammasir_p_l5['id'] === (int) $hammasir_p_rm['link_id']) {
                $hammasir_p_rm_name = hammasir_user_display((int) $hammasir_p_l5['client_user_id']);
                break;
            }
        }
        if ((int) $hammasir_p_rm['recipient_user_id'] === $hammasir_me && (int) $hammasir_p_rm['is_read'] === 0) $hammasir_p_rm_unread = 1;
    } catch (Throwable $e) { }
    $hammasir_p_rm_body = (string) $hammasir_p_rm['body'];
    if (function_exists('hammasir_mb_strlen') && hammasir_mb_strlen($hammasir_p_rm_body) > 60) {
        $hammasir_p_rm_body = substr($hammasir_p_rm_body, 0, 60) . '…';
    }
    ?>
    <div class="hd-item">
      <form method="post" style="display:flex;align-items:center;gap:10px;flex:1;flex-wrap:wrap;"><?php echo csrf_field(); ?><input type="hidden" name="hammasir_action" value="provider_open"><input type="hidden" name="link_id" value="<?php echo (int) $hammasir_p_rm['link_id']; ?>">
        <span><strong><?php echo e($hammasir_p_rm_name); ?>:</strong> «<?php echo e($hammasir_p_rm_body); ?>»</span>
        <?php if ($hammasir_p_rm_unread > 0) { ?><span class="chip" style="color:#7C6AA8;">نخوانده</span><?php } ?>
        <span style="flex:1;"></span>
        <button class="btn sec" type="submit">گفتگو</button>
      </form>
    </div>
  <?php } ?>
</section>
<?php } ?>
