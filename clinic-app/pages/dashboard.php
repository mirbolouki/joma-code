<?php
require_login();
require_perm('VIEW_DASHBOARD');
$u = current_user();
$today = jalali_today();
$key = current_period_key();
$wp = ensure_period($u['id'], $key);
$plan = $wp['plan'];
$acts = list_plan_activities($plan['id'], $u['id']);
$evs = list_events($plan['id'], $u['id']);
$mood = get_mood($u['id'], $today);
$metrics = array(
    'energy' => array('😴', '😐', '🙂', '😄', '⚡'),
    'general_mood' => array('😞', '😐', '🙂', '😊', '🤩'),
    'focus' => array('🌫️', '😐', '🙂', '🎯', '🧠'),
    'sleep_quality' => array('😫', '😐', '🙂', '😴', '✨'),
    'stress' => array('😌', '🙂', '😐', '😟', '😣'),
);
$remaining = 0;
foreach ($acts as $a) {
    $rel = events_for($evs, $a['id']);
    if ($a['frequency'] !== 'DAILY' || !has_daily_registration($rel, $today)) $remaining++;
}
joma_header('داشبورد', array(array('label' => 'داشبورد')));
?>
<section class="card hero-card">
  <p class="lede"><?php echo e(jalali_format($today)); ?></p>
  <h1>سلام <?php echo e($u['full_name'] ? $u['full_name'] : $u['username']); ?></h1>
  <div class="btn-row">
    <span class="chip"><?php echo e(jalali_period_label($plan['period_key'])); ?></span>
    <?php echo status_badge($plan['status']); ?>
  </div>
</section>
<div class="grid grid-3">
  <a class="card stat" href="<?php echo e(joma_url('index.php?p=mood')); ?>">
    <div class="k">حال امروز</div>
    <?php if ($mood) {
        echo '<div class="v" style="font-size:28px">';
        foreach ($metrics as $k => $stickers) {
            $sc = (int) $mood[$k];
            echo $sc ? $stickers[$sc - 1] . ' ' : '';
        }
        echo '</div>';
    } else {
        echo '<p class="lede">هنوز ثبت نشده — ثبت حال</p>';
    } ?>
  </a>
  <div class="card stat"><div class="k">فعالیت‌های برنامه</div><div class="v"><?php echo fa_num(count($acts)); ?></div></div>
  <div class="card stat"><div class="k">قابل ثبت امروز</div><div class="v"><?php echo fa_num($remaining); ?></div></div>
</div>
<?php
// JOMA-HAMMASIR-BEGIN (کارت‌های هم‌مسیر در داشبورد — باگ ۸؛ فقط mode=admin/provider و flag روشن؛
// با Flag خاموش: فقط خواندن config ماژول، هیچ خروجی — D39/AC6.8)
try {
    $__hcfg = dirname(__FILE__) . '/../config/hammasir_config.php';
    if (is_file($__hcfg)) {
        $HAMMASIR_CONFIG = array();
        include $__hcfg;
        if (!empty($HAMMASIR_CONFIG['hammasir_enabled'])) {
            $__hfn = dirname(__FILE__) . '/../functions/hammasir.php';
            if (is_file($__hfn)) {
                include_once $__hfn;
                $__hmode = hammasir_active_mode();
                if ($__hmode === 'admin') {
                    echo '<section class="card"><h2>پنل مدیریت هم‌مسیر</h2>';
                    echo '<div class="btn-row">';
                    echo '<a class="btn" href="' . e(joma_url('index.php?p=hammasir')) . '">مدیریت همراهان</a>';
                    echo '<a class="btn sec" href="' . e(joma_url('index.php?p=hammasir')) . '">مدیران سیستم</a>';
                    echo '</div></section>';
                } elseif ($__hmode === 'provider') {
                    $__hpend = hammasir_links_by_provider((int) $u['id'], 'PENDING');
                    $__hact = hammasir_links_by_provider((int) $u['id'], 'ACTIVE');
                    $__hnp = is_array($__hpend) ? count($__hpend) : 0;
                    $__hna = is_array($__hact) ? count($__hact) : 0;
                    echo '<section class="card"><h2>میز کار مشاور</h2>';
                    echo '<div class="btn-row">';
                    echo '<span class="chip">درخواست‌های در انتظار: ' . (int) $__hnp . '</span>';
                    echo '<span class="chip">مراجعان فعال: ' . (int) $__hna . '</span>';
                    echo '<a class="btn" href="' . e(joma_url('index.php?p=hammasir')) . '">ورود به میز کار</a>';
                    echo '</div></section>';
                } elseif ($__hmode === 'client') {
                    // کارت دعوت هم‌مسیر در داشبورد (حکم PO — اصلاح نهایی Onboarding بخش ۱):
                    // فقط seen=0 و بدون «هیچ» لینک (باز یا بسته — بخش ۴)؛ فرم‌ها POST به p=hammasir.
                    // fail-closed: خطای خواندن → دعوت رندر نمی‌شود.
                    $__hstate = array('seen' => 1, 'intent' => 0);
                    $__hany = true;
                    try {
                        $__hstate = hammasir_onboarding_state((int) $u['id']);
                        $__hany = (hammasir_links_any_by_client((int) $u['id']) === true);
                    } catch (Throwable $e) {
                        $__hstate = array('seen' => 1, 'intent' => 0);
                        $__hany = true;
                    }
                    if ($__hstate['seen'] === 0 && !$__hany) {
                        echo '<section class="card">';
                        echo '<h2>آیا مایلید در این مسیر یک همراه داشته باشید؟</h2>';
                        echo '<p>' . e(hammasir_onboarding_text()) . '</p>';
                        echo '<div class="btn-row">';
                        echo '<form method="post" action="' . e(joma_url('index.php?p=hammasir')) . '">';
                        echo csrf_field();
                        echo '<input type="hidden" name="hammasir_action" value="onboarding_accept">';
                        echo '<button class="btn" type="submit">بله، انتخاب می‌کنم</button>';
                        echo '</form>';
                        echo '<form method="post" action="' . e(joma_url('index.php?p=hammasir')) . '">';
                        echo csrf_field();
                        echo '<input type="hidden" name="hammasir_action" value="onboarding_dismiss">';
                        echo '<button class="btn sec" type="submit">فعلاً نه</button>';
                        echo '</form>';
                        echo '</div></section>';
                    }
                }
            }
        }
        unset($HAMMASIR_CONFIG);
    }
    unset($__hcfg);
} catch (Throwable $e) {
    // fail-closed: هیچ کارتی؛ فقط لاگ امن با پیام ثابت (PC-4)
    error_log('hammasir dashboard kept silent: safe load failed.');
}
// JOMA-HAMMASIR-END
?>
<div class="grid grid-2" style="margin:8px 0 18px">
  <a class="btn" href="<?php echo e(joma_url('index.php?p=today')); ?>">ثبت عملکرد</a>
  <a class="btn sec" href="<?php echo e(joma_url('index.php?p=plan')); ?>">برنامه دوره</a>
  <a class="btn sec" href="<?php echo e(joma_url('index.php?p=reports')); ?>">گزارش‌ها</a>
  <a class="btn sec" href="<?php echo e(joma_url('index.php?p=periods')); ?>">دوره‌های من</a>
</div>
<h2>فعالیت‌های دوره</h2>
<?php if (!$acts) {
    echo empty_state('هنوز فعالیتی به این دوره اضافه نشده', 'از کتابخانه به برنامه این ماه اضافه کنید.', joma_url('index.php?p=plan'), 'ساخت برنامه');
} else {
    echo '<div class="grid grid-2">';
    $n = 0;
    foreach ($acts as $a) {
        if ($n++ >= 6) break;
        $sum = displayed_actual($a, $evs, $today);
        echo '<article class="card" style="display:flex;gap:12px;align-items:center">';
        echo '<div class="sticker" style="background:'.$a['color'].'">'.e($a['sticker']).'</div>';
        echo '<div><strong>'.e($a['name']).'</strong><p class="meta">'.e(frequencies_list()[$a['frequency']]).' · '.e(format_value($a['data_type'], $sum, $a['unit'])).' / '.e(format_value($a['data_type'], $a['target_value'], $a['unit'])).'</p></div>';
        echo '</article>';
    }
    echo '</div>';
} ?>
<?php joma_footer(); ?>
