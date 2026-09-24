<?php
function joma_header($title, $crumbs = array(), $opts = array()) {
    $u = current_user();
    $page = isset($_GET['p']) ? $_GET['p'] : 'home';
    $compact = false;
    $prefs = array();
    if ($u) {
        $prefs = get_prefs($u['id']);
        $compact = !empty($prefs['compact_cards']);
    }
    $bodyClass = array();
    if (strpos($page, 'clinic_') === 0) $bodyClass[] = 'clinic-page'; // CLINIC: اسکوپ استایل مطب
    if ($u) $bodyClass[] = 'authed';
    if ($compact) $bodyClass[] = 'compact';
    if (!empty($opts['public'])) $bodyClass[] = 'is-public';
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="theme-color" content="#fbf7f2">';
    echo '<title>' . e($title) . ' | جوما</title>';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="stylesheet" href="' . e(joma_url('assets/css/joma.css')) . '">';
    echo '<link rel="stylesheet" href="' . e(joma_url('assets/css/clinic.css')) . '">';
    echo '</head><body class="' . e(implode(' ', $bodyClass)) . '">';
    if ($u && empty($opts['public'])) {
        echo '<div class="topbar"></div>';
        echo '<aside class="side">';
        echo joma_logo(52);
        echo '<nav class="side-nav">';
        // CLINIC: منوی «مدیریت مطب» برای نقش‌های مطب (قبل از آیتم‌های جوما)
        $__clinic_role = isset($u['role_key']) ? $u['role_key'] : '';
        $__clinic_dedicated = true; // CLINIC-DEDICATED: ساب‌دامین فقط مطب است؛ منوهای جوما/هم‌مسیر مخفی
        $__is_clinic = in_array($__clinic_role, array('admin', 'doctor', 'head_secretary', 'secretary', 'client'), true);
        $__clinic_menu = array();
        if ($__is_clinic) {
            if ($__clinic_role === 'client') {
                $__clinic_menu = array(
                    array('clinic_dashboard', 'پیشخوان من', '🏛️'),
                    array('clinic_client', 'پرونده من', '🗂️'),
                    array('clinic_appointments', 'نوبت‌های من', '📅'),
                    array('clinic_finance', 'پرداخت‌های من', '💰'),
                );
            } else {
                $__clinic_menu = array(
                    array('clinic_dashboard', 'پیشخوان مطب', '🏛️'),
                    array('clinic_clients', 'پرونده‌ها', '🗂️'),
                    array('clinic_appointments', 'نوبت‌ها', '📅'),
                    array('clinic_finance', 'مالی', '💰'),
                );
                if (in_array($__clinic_role, array('admin', 'head_secretary', 'secretary'), true)) {
                    $__clinic_menu[] = array('clinic_import', 'ورود گروهی', '📥');
                }
                if ($__clinic_role === 'admin') {
                    $__clinic_menu[] = array('clinic_users', 'کاربران مطب', '👥');
                }
                if ($__clinic_role === 'admin' || $__clinic_role === 'head_secretary') {
                    $__clinic_menu[] = array('clinic_settings', 'تنظیمات مطب', '⚙️');
                }
            }
            echo '<div class="clinic-menu-title">مدیریت مطب</div>';
            foreach ($__clinic_menu as $__cm) {
                $__ccls = $page === $__cm[0] ? 'nav-link active' : 'nav-link';
                echo '<a class="' . $__ccls . '" href="' . e(joma_url('index.php?p=' . $__cm[0])) . '"><span>' . $__cm[2] . '</span>' . e($__cm[1]) . '</a>';
            }
            echo '<div class="clinic-menu-sep"></div>';
        }
        if (($__clinic_role !== 'client' && empty($__clinic_dedicated))) {
        foreach (nav_items() as $k => $item) {
            $cls = $page === $k ? 'nav-link active' : 'nav-link';
            echo '<a class="' . $cls . '" href="' . e(joma_url('index.php?p=' . $k)) . '"><span>' . $item[1] . '</span>' . e($item[0]) . '</a>';
        }
        }
        // JOMA-HAMMASIR-BEGIN (منوی هم‌مسیر + Badge + سوییچ حالت — Tier B با flag؛ fail-closed)
        // با Flag خاموش: فقط خواندن config ماژول؛ هیچ خروجی/رفتاری (D39/AC6.8).
        try {
            $__hcfg = dirname(__FILE__) . '/../config/hammasir_config.php';
            if (is_file($__hcfg)) {
                $HAMMASIR_CONFIG = array();
                include $__hcfg;
                if (!empty($HAMMASIR_CONFIG['hammasir_enabled']) && $u && ($__clinic_role !== 'client' && empty($__clinic_dedicated))) { // CLINIC: مخفی برای مراجع مطب
                    $__hfn = dirname(__FILE__) . '/../functions/hammasir.php';
                    if (is_file($__hfn)) {
                        include_once $__hfn;
                        $__hmode = hammasir_active_mode();
                        $__hcap = hammasir_capabilities();
                        $__hbadge = hammasir_unread_badge((int) $u['id']);
                        $__hlabel = hammasir_mode_labels();
                        $__hcards = hammasir_mode_card_labels();
                        $__hcls = ($page === 'hammasir') ? 'nav-link active' : 'nav-link';
                        // باگ ۷ (گزارش PO): بدون استیکر/آیکون — فقط متن ساده «هم‌مسیر» (+ عدد Badge طبق D10)
                        echo '<a class="' . $__hcls . '" href="' . e(joma_url('index.php?p=hammasir')) . '"><span>' . (($__hbadge['total'] > 0) ? 'هم‌مسیر (' . (int) $__hbadge['total'] . ')' : 'هم‌مسیر') . '</span></a>';
                        // جعبه‌ی سوییچ فقط برای کاربر چندقابلیتی (حکم PO 2.2):
                        // کاربر تک‌قابلیتی فقط آیتم «هم‌مسیر» را می‌بیند.
                        if ($__hcap['can_provider'] || $__hcap['can_admin']) {
                        echo '<div class="hammasir-modebox" style="padding:6px 10px;display:flex;flex-direction:column;gap:4px;">';
                        echo '<small style="opacity:.7">حالت: ' . e($__hlabel[$__hmode]) . '</small>';
                        foreach (array('client', 'provider', 'admin') as $__hm) {
                            if ($__hm === $__hmode) continue;
                            if ($__hm === 'provider' && !$__hcap['can_provider']) continue;
                            if ($__hm === 'admin' && !$__hcap['can_admin']) continue;
                            echo '<form method="post" action="' . e(joma_url('index.php?p=hammasir')) . '">';
                            echo csrf_field();
                            echo '<input type="hidden" name="hammasir_action" value="mode_set">';
                            echo '<input type="hidden" name="mode" value="' . $__hm . '">';
                            echo '<button class="btn btn-ghost btn-block" type="submit">' . e($__hcards[$__hm]) . '</button>';
                            echo '</form>';
                        }
                        echo '</div>';
                        }
                    }
                }
                unset($HAMMASIR_CONFIG);
            }
            unset($__hcfg);
        } catch (Throwable $e) {
            // fail-closed: هیچ خروجی منو؛ فقط لاگ امن با پیام ثابت (PC-4)
            error_log('hammasir menu kept silent: safe load failed.');
        }
        // JOMA-HAMMASIR-END
        echo '</nav>';
        echo '<div class="side-foot">';
        echo '<div class="who"><strong>' . e($u['full_name']) . '</strong><small>@' . e($u['username']) . '</small></div>';
        // JOMA-HAMMASIR-BEGIN (چیپ حالت فعال کنار نام کاربر — فقط mode=admin/provider و flag روشن)
        try {
            if (isset($__hmode) && ($__hmode === 'provider' || $__hmode === 'admin')) {
                echo '<span class="chip">حالت ' . e($__hlabel[$__hmode]) . '</span>';
            }
        } catch (Throwable $e) {
            error_log('hammasir mode chip kept silent: safe load failed.');
        }
        // JOMA-HAMMASIR-END
        echo '<a class="btn btn-ghost btn-block" href="' . e(joma_url('index.php?p=logout')) . '">خروج</a>';
        echo '</div></aside>';
        echo '<header class="mob-head">';
        echo joma_logo(40, true);
        // data-more-toggle به دکمه‌ی شناور (FAB) منتقل شد — joma.js فقط «اولین» مورد را
        // بایند می‌کند؛ دکمه‌ی قدیمی داخل هدرِ مخفی‌شده دیگر صاحب این اتریبیوت نیست.
        echo '<button class="icon-btn" type="button" aria-label="منو">⋯</button>';
        echo '</header>';
        // MOBILE-MENU-LUXURY (حکم PO — منوی لوکس + FAB شناور + شیشه‌ای + آیکون‌های SVG خطی):
        // فقط استایل محلی و SVG اینلاین — بدون دست‌اندازی به joma.css/joma.js (Add-Only — PC-1).
        // نکته‌ی breakpoint: حالت موبایل JOMA از max-width:980px فعال می‌شود (joma.css)؛
        // همان باند برای مخفی‌کردن نوار قدیمی و نمایش FAB به کار رفت تا در باند 769-980px
        // دکمه‌ی منو غیب نشود. دسکتاپ (>980px) هیچ تغییری نمی‌بیند.
        $__micons = array(
            'menu' => '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" aria-hidden="true"><path d="M7 9h10"/><path d="M7 13h10"/><path d="M7 17h10"/></svg>',
            'home' => '<span class="mi" style="background:#4A7C9E1A;"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#4A7C9E" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11 12 3l9 8"/><path d="M5 10v10h5v-6h4v6h5V10"/></svg></span>',
            'today' => '<span class="mi" style="background:#D68C451A;"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#D68C45" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="5" width="16" height="16" rx="3"/><path d="M8 3v4"/><path d="M16 3v4"/><path d="M9 13.5l2.2 2.2 3.8-4"/></svg></span>',
            'mood' => '<span class="mi" style="background:#C97B941A;"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#C97B94" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9 10h.01"/><path d="M15 10h.01"/><path d="M8.5 14.5c1 1.2 2.2 1.8 3.5 1.8s2.5-0.6 3.5-1.8"/></svg></span>',
            'report' => '<span class="mi" style="background:#7C6AA81A;"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#7C6AA8" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M5 20v-6"/><path d="M11 20V8"/><path d="M17 20v-10"/><path d="M3 21h18"/></svg></span>',
            'plan' => '<span class="mi" style="background:#4E9B941A;"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#4E9B94" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M9 6h11"/><path d="M9 12h11"/><path d="M9 18h11"/><path d="M4 6h.01"/><path d="M4 12h.01"/><path d="M4 18h.01"/></svg></span>',
            'periods' => '<span class="mi" style="background:#96745C1A;"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#96745C" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 3 8l9 5 9-5-9-5z"/><path d="M3 13l9 5 9-5"/></svg></span>',
            'book' => '<span class="mi" style="background:#6B8E5A1A;"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#6B8E5A" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 5a2 2 0 0 1 2-2h12v16H7a2 2 0 0 0-2 2z"/><path d="M17 3v16"/></svg></span>',
            'learn' => '<span class="mi" style="background:#C4A34A1A;"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#C4A34A" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3a6 6 0 0 1 3.5 10.9c-.7.5-1 1.3-1 2.1h-5c0-.8-.3-1.6-1-2.1A6 6 0 0 1 12 3z"/><path d="M10 19h4"/><path d="M10.5 21.5h3"/></svg></span>',
            'user' => '<span class="mi" style="background:#5C7A991A;"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#5C7A99" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c1.4-3.4 3.9-5 7-5s5.6 1.6 7 5"/></svg></span>',
            'settings' => '<span class="mi" style="background:#6E7A8A1A;"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#6E7A8A" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M4 7h9"/><path d="M17 7h3"/><circle cx="15" cy="7" r="2"/><path d="M4 17h3"/><path d="M11 17h9"/><circle cx="9" cy="17" r="2"/></svg></span>',
            'info' => '<span class="mi" style="background:#5B9BBF1A;"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#5B9BBF" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/></svg></span>',
            'support' => '<span class="mi" style="background:#D07A6B1A;"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#D07A6B" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.5"/><path d="M12 3v3.5"/><path d="M12 17.5V21"/><path d="M3 12h3.5"/><path d="M17.5 12H21"/></svg></span>',
            'logout' => '<span class="mi" style="background:#B85C5C1A;"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#B85C5C" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3"/><path d="M15 8l4 4-4 4"/><path d="M19 12H10"/></svg></span>',
            'hammasir' => '<span class="mi" style="background:#2F7A521A;"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#2F7A52" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 20c.7-3 2.8-4.6 5.5-4.6s4.8 1.6 5.5 4.6"/><path d="M15.5 5.2a3.2 3.2 0 0 1 0 5.9"/><path d="M16 15.7c1.9.5 3.2 1.9 3.8 4.3"/></svg></span>',
        );
        echo '<style>';
        echo '.hammasir-fab{display:none;}';
        echo '@media (max-width: 980px){';
        echo '  .mob-head{display:none !important;}';
        echo '  .hammasir-fab{display:flex;position:fixed;top:16px;right:16px;flex-direction:column;align-items:center;gap:6px;z-index:9998;border:none;cursor:pointer;padding:0;margin:0;background:none;color:inherit;}';
        echo '  .hammasir-fab .fab-img{display:block;width:56px;height:56px;border-radius:50%;overflow:hidden;border:2px solid rgba(255,255,255,0.9);box-shadow:0 4px 15px rgba(0,0,0,0.15);background:#fff;box-sizing:content-box;}';
        echo '  .hammasir-fab .fab-img img{width:100%;height:100%;object-fit:cover;border-radius:50%;display:block;}';
        echo '  .hammasir-fab .fab-label{font-size:11px;color:#6b6257;background:rgba(255,255,255,0.82);padding:2px 10px;border-radius:999px;line-height:1.6;box-shadow:0 1px 4px rgba(0,0,0,0.06);}';
        echo '  .hammasir-fab .fab-img + svg{display:none;}';
        echo '  .hammasir-fab.noimg .fab-img{display:none;}';
        echo '  .hammasir-fab.noimg svg{display:flex;width:56px;height:56px;align-items:center;justify-content:center;border-radius:50%;background:#fff;border:2px solid rgba(255,255,255,0.9);box-shadow:0 4px 15px rgba(0,0,0,0.15);}';
        echo '  .mob-more .mi{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex:none;}';
        echo '  .hammasir-fab:active{transform:scale(0.94);}';
        echo '  .mob-more{position:fixed;top:0;left:0;width:100vw;height:100vh;max-height:100vh;z-index:10000;background:rgba(255,255,255,0.85);-webkit-backdrop-filter:blur(15px);backdrop-filter:blur(15px);overflow-y:auto;box-sizing:border-box;padding:82px 20px 30px;display:flex;flex-direction:column;gap:8px;border-bottom:none;animation:hammasirMobIn 0.28s ease;}';
        echo '  @keyframes hammasirMobIn{from{opacity:0;transform:translateY(16px);}to{opacity:1;transform:none;}}';
        echo '  .mob-more .mob-close{position:fixed;top:18px;left:18px;width:46px;height:46px;font-size:28px;line-height:44px;text-align:center;border:1px solid rgba(0,0,0,0.06);border-radius:50%;background:rgba(255,255,255,0.92);box-shadow:0 2px 10px rgba(0,0,0,0.08);color:inherit;z-index:10001;box-sizing:border-box;text-decoration:none;}';
        echo '  .mob-more a:not(.mob-close){display:flex;align-items:center;gap:14px;padding:12px 16px;min-height:48px;border-radius:16px;background:transparent;color:var(--fg, inherit);font-size:15px;box-sizing:border-box;border:1px solid transparent;text-decoration:none;}';
        echo '  .mob-more a:not(.mob-close):hover,.mob-more a:not(.mob-close):active{background:#f6f3ee;}';
        echo '  .mob-more a:not(.mob-close) span{flex:1;}';
        echo '  .mob-more a.mob-hammasir{background:#eef6f0;border:1px solid #dcebe0;}';
        echo '  .mob-more a.mob-hammasir svg{color:#2f7a52;}';
        echo '  .mob-more a.danger{color:#b42318;}';
        echo '  .mob-more form{margin:2px 0;}';
        echo '  .mob-more:not([hidden]) ~ .hammasir-fab{display:none;}';
        echo '}';
        echo '</style>';
        echo '<div class="mob-more" hidden>';
        // دکمه‌ی بستن (×): بزرگ و ظریف؛ با JS روشن منو را می‌بندد؛ بدون JS به صفحه‌ی جاری برمی‌گردد
        echo '<a class="mob-close" href="' . e(joma_url('index.php?p=' . $page)) . '" onclick="this.parentNode.hidden=true; return false;" aria-label="بستن منو">&times;</a>';
        // CLINIC: لینک‌های مطب اول منوی موبایل
        if (!empty($__clinic_menu)) {
            foreach ($__clinic_menu as $__cm) {
                echo '<a href="' . e(joma_url('index.php?p=' . $__cm[0])) . '"><span>' . $__cm[2] . '</span><span>' . e($__cm[1]) . '</span></a>';
            }
        }
        if (($__clinic_role !== 'client' && empty($__clinic_dedicated))) {
        // آیتم‌های اصلی (برچسب‌های موجود نوار پایین JOMA) + آیکون خطی
        $__mmain = array(
            array('dashboard', 'خانه', 'home'),
            array('today', 'امروز', 'today'),
            array('mood', 'خلق', 'mood'),
            array('reports', 'گزارش', 'report'),
        );
        foreach ($__mmain as $__mi) {
            echo '<a href="' . e(joma_url('index.php?p=' . $__mi[0])) . '">' . $__micons[$__mi[2]] . '<span>' . e($__mi[1]) . '</span></a>';
        }
        $more = array('plan', 'periods', 'library', 'learn', 'profile', 'settings', 'about', 'support');
        $__miconmap = array('plan' => 'plan', 'periods' => 'periods', 'library' => 'book', 'learn' => 'learn', 'profile' => 'user', 'settings' => 'settings', 'about' => 'info', 'support' => 'support');
        foreach ($more as $k) {
            $item = nav_items();
            echo '<a href="' . e(joma_url('index.php?p=' . $k)) . '">' . $__micons[$__miconmap[$k]] . '<span>' . e($item[$k][0]) . '</span></a>';
        }
        } // CLINIC: پایان آیتم‌های جوما (برای مراجع مخفی)
        // JOMA-HAMMASIR-BEGIN (منوی موبایل هم‌مسیر: لینک + Badge + سوییچ حالت — فقط با flag؛ fail-closed)
        // همان سوییچ سایدبار دسکتاپ (حکم PO — سوییچ نقش در منوی موبایل): POST + CSRF +
        // hammasir_action=mode_set به index.php?p=hammasir؛ فقط حالت‌های مجازِ همان کاربر؛
        // کاربر تک‌قابلیتی فقط لینک «هم‌مسیر» را می‌بیند. با flag خاموش: هیچ خروجی (D39).
        try {
            $__hcfg2 = dirname(__FILE__) . '/../config/hammasir_config.php';
            if (is_file($__hcfg2)) {
                $HAMMASIR_CONFIG = array();
                include $__hcfg2;
                if (!empty($HAMMASIR_CONFIG['hammasir_enabled']) && ($__clinic_role !== 'client' && empty($__clinic_dedicated))) { // CLINIC: مخفی برای مراجع مطب
                    $__hfn2 = dirname(__FILE__) . '/../functions/hammasir.php';
                    if (is_file($__hfn2)) {
                        include_once $__hfn2;
                        // لینک + Badge نخوانده (D10) — همان قالب دسکتاپ
                        $__hbadge2 = hammasir_unread_badge((int) $u['id']);
                        echo '<a class="mob-hammasir" href="' . e(joma_url('index.php?p=hammasir')) . '">' . $__micons['hammasir'] . '<span>' . (($__hbadge2['total'] > 0) ? 'هم‌مسیر (' . (int) $__hbadge2['total'] . ')' : 'هم‌مسیر') . '</span></a>';
                        // سوییچ حالت — فقط کاربر چندقابلیتی (همان گیت دسکتاپ)
                        $__hcap2 = hammasir_capabilities();
                        if ($__hcap2['can_provider'] || $__hcap2['can_admin']) {
                            $__hmode2 = hammasir_active_mode();
                            $__hcards2 = hammasir_mode_card_labels();
                            echo '<div style="display:flex;flex-direction:column;gap:8px;padding:8px 4px;">';
                            foreach (array('client', 'provider', 'admin') as $__hm2) {
                                if ($__hm2 === $__hmode2) continue;
                                if ($__hm2 === 'provider' && !$__hcap2['can_provider']) continue;
                                if ($__hm2 === 'admin' && !$__hcap2['can_admin']) continue;
                                echo '<form method="post" action="' . e(joma_url('index.php?p=hammasir')) . '">';
                                echo csrf_field();
                                echo '<input type="hidden" name="hammasir_action" value="mode_set">';
                                echo '<input type="hidden" name="mode" value="' . $__hm2 . '">';
                                echo '<button class="btn btn-ghost btn-block" type="submit">' . e($__hcards2[$__hm2]) . '</button>';
                                echo '</form>';
                            }
                            echo '</div>';
                        }
                    } else {
                        // توابع ماژول نبود → فقط لینک ساده (fail-closed مثل قبل)
                        echo '<a class="mob-hammasir" href="' . e(joma_url('index.php?p=hammasir')) . '">' . $__micons['hammasir'] . '<span>هم‌مسیر</span></a>';
                    }
                }
                unset($HAMMASIR_CONFIG);
            }
            unset($__hcfg2);
        } catch (Throwable $e) {
            error_log('hammasir mobile menu kept silent: safe load failed.');
        }
        // JOMA-HAMMASIR-END
        echo '<a class="danger" href="' . e(joma_url('index.php?p=logout')) . '">' . $__micons['logout'] . '<span>خروج</span></a>';
        echo '</div>';
        // دکمه‌ی شناور = لوگوی برند جوما (حکم PO): همان مسیر/منطق لوگوی هدر موبایل (joma_logo)
        // عیناً تکرار شده — candidates یکسان؛ هیچ مسیری حدس زده نشد. کل مجموعه (لوگو + برچسب «منو»)
        // یک ناحیه‌ی لمسی واحد است؛ data-more-toggle اینجا بایند می‌شود؛ اگر تصویر لود نشد
        // (onerror) همان همبرگر SVG قبلی جایگزین می‌شود (بدون شکستن layout).
        $__fab_logo_src = '';
        if (is_file(dirname(__FILE__) . '/../assets/images/logo.jpg')) {
            $__fab_logo_src = joma_asset('images/logo.jpg');
        } elseif (is_file(dirname(__FILE__) . '/../../public/logo.jpg')) {
            $__fab_logo_src = joma_url('../public/logo.jpg');
        }
        echo '<button class="hammasir-fab" type="button" data-more-toggle aria-label="منو">';
        if ($__fab_logo_src !== '') {
            echo '<span class="fab-img"><img src="' . e($__fab_logo_src) . '" alt="لوگوی جوما" width="56" height="56" onerror="this.closest(&#39;.hammasir-fab&#39;).classList.add(&#39;noimg&#39;);"></span>';
        }
        echo $__micons['menu'];
        echo '<span class="fab-label">منو</span>';
        echo '</button>';
        unset($__fab_logo_src);
        echo '<main class="wrap">';
        echo '<div class="crumbs">';
        echo '<a class="back" href="javascript:history.back()">بازگشت</a>';
        foreach ($crumbs as $c) {
            echo '<span class="sep">/</span>';
            if (!empty($c['href'])) echo '<a href="' . e($c['href']) . '">' . e($c['label']) . '</a>';
            else echo '<span>' . e($c['label']) . '</span>';
        }
        echo '</div>';
        echo flash_get();
    } else {
        echo '<main class="public-shell">';
        echo flash_get();
    }
}

function joma_footer() {
    $u = current_user();
    echo '</main>';
    if ($u) {
        $page = isset($_GET['p']) ? $_GET['p'] : 'dashboard';
        $__fr = isset($u['role_key']) ? $u['role_key'] : '';
        // CLINIC: نوار پایین موبایل برای نقش‌های مطب
        if (in_array($__fr, array('doctor', 'head_secretary', 'secretary', 'admin', 'client'), true)) {
            $items = array(
                'clinic_dashboard' => array('مطب', '🏛️'),
                'clinic_clients' => array('پرونده‌ها', '🗂️'),
                'clinic_appointments' => array('نوبت‌ها', '📅'),
                'clinic_finance' => array('مالی', '💰'),
            );
            if ($__fr === 'client') {
                $items = array(
                    'clinic_dashboard' => array('مطب', '🏛️'),
                    'clinic_client' => array('پرونده', '🗂️'),
                    'clinic_appointments' => array('نوبت‌ها', '📅'),
                    'clinic_finance' => array('مالی', '💰'),
                );
            }
        } else {
        $items = array(
            'dashboard' => array('خانه', '🏠'),
            'today' => array('امروز', '📝'),
            'mood' => array('خلق', '💗'),
            'reports' => array('گزارش', '📊'),
        );
        }
        echo '<nav class="mobile">';
        foreach ($items as $k => $item) {
            $cls = $page === $k ? 'on' : '';
            echo '<a class="' . $cls . '" href="' . e(joma_url('index.php?p=' . $k)) . '"><span>' . $item[1] . '</span>' . e($item[0]) . '</a>';
        }
        echo '</nav>';
    }
    echo '<script src="' . e(joma_url('assets/js/joma.js')) . '"></script></body></html>';
}
