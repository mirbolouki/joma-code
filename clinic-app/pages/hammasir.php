<?php
/*
 * ماژول «هم‌مسیر» — صفحه‌ی مرکزی (چندنقشی: admin | provider | client)
 * ------------------------------------------------------------------
 * مسیر مجاز فقط Router مرکزی: index.php?p=hammasir (D29).
 *
 * گاردها (به ترتیب):
 *   ۱) گارد مستقیم (AC6.3/R13) → 403 خشک.
 *   ۲) گارد ورود: require_login().
 *   ۳) گارد Feature Flag (fail-closed — D28/D39).
 *   ۴) گارد محیط (AC1.7): بدون mbstring فقط پیام ثابت.
 *
 * مدل حالت (حکم PO — بخش ۲): یک کاربر می‌تواند هم‌زمان چند قابلیت داشته باشد؛
 * در هر لحظه یک حالت فعال: admin (فقط مدیریت سیستم هم‌مسیر) | provider
 * (داشبورد مشاور) | client (تجربه‌ی مراجع). سوییچ با POST + CSRF (mode_set).
 *
 * Bootstrap مالک (D-OWNER-BOOTSTRAP) و Onboarding یک‌باره نیز از همین صفحه
 * مدیریت می‌شوند (بخش ۳/۴ حکم).
 */

// ۱) گارد مستقیم — دسترسی مستقیم هرگز مشروع نیست → 403 خشک بدون هیچ خروجی
if (!defined('JOMA_IN_APP')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

// ۲) گارد ورود (احراز هویت با زیرساخت موجود JOMA)
require_login();

// ۳) گارد Feature Flag — دوگانه و fail-closed
if (!function_exists('hammasir_enabled') || !hammasir_enabled()) {
    joma_redirect('index.php?p=dashboard');
}

// هدر امنیتی صفحات داده‌دار هم‌مسیر (AC6.5)
header('Cache-Control: private, no-store');

// ۴) گارد محیط (fail-closed — AC1.7): در محیط ناسالم فقط پیام ثابت؛ بدون فرم/عملیات
if (!hammasir_env_ok()) {
    joma_header('هم‌مسیر', array(array('label' => 'هم‌مسیر')));
    ?>
    <section class="card">
      <h1>هم‌مسیر</h1>
      <p>ماژول هم‌مسیر در حال حاضر در دسترس نیست.</p>
    </section>
    <?php
    joma_footer();
    return;
}

// ===== پردازش POST — قبل از هر خروجی؛ CSRF اجباری؛ مهار کامل Throwable (D-1) =====
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hammasir_action = isset($_POST['hammasir_action']) ? (string) $_POST['hammasir_action'] : '';
    $hammasir_u_post = current_user();
    $hammasir_me_post = $hammasir_u_post ? (int) $hammasir_u_post['id'] : 0;
    $hammasir_link_id = isset($_POST['link_id']) ? (int) $_POST['link_id'] : 0;

    // --- Onboarding — مدل state واحد (حکم PO — اصلاح نهایی Onboarding):
    //     هر دو دکمه: seen=1 پایدار؛ «بله»/«انتخاب همراه» → intent=1؛ «فعلاً نه» → intent=0.
    //     شکست نوشتن پرچم هرگز «موفق» فرض نمی‌شود: پیام امن + لاگ ثابت؛ state عوض نشده
    //     → کارت درست دوباره رندر می‌شود (دعوت/فرم به‌عنوان موفق رندر نمی‌شود).
    if ($hammasir_action === 'onboarding_accept' || $hammasir_action === 'onboarding_dismiss' || $hammasir_action === 'onboarding_reopen') {
        $hammasir_intent = ($hammasir_action === 'onboarding_dismiss') ? 0 : 1;
        $hammasir_flag_ok = true;
        try {
            if (!hammasir_user_flag_set($hammasir_me_post, 'onboarding_seen', 1)) $hammasir_flag_ok = false;
            if (!hammasir_user_flag_set($hammasir_me_post, 'onboarding_intent', $hammasir_intent)) $hammasir_flag_ok = false;
        } catch (Throwable $e) {
            $hammasir_flag_ok = false;
        }
        if (!$hammasir_flag_ok) {
            error_log('hammasir onboarding flag write failed: store not writable.');
            flash_set('err', 'لطفاً دوباره تلاش کنید.');
        }
        // مقصد PRG (حکم PO بخش ۲): «فعلاً نه» → داشبورد؛ «بله، انتخاب می‌کنم» / «انتخاب همراه» → هم‌مسیر (فرم انتخاب)
        joma_redirect(($hammasir_action === 'onboarding_dismiss') ? 'index.php?p=dashboard' : 'index.php?p=hammasir');
    }

    // --- دارندگان لینک بسته (چرخه‌ی D20/D34): بازشدن آگاهانه‌ی فرم با همان intent per-user ---
    //     (پیش از این session-flag بود — با مدل state واحد حکم جدید جایگزین شد)
    if ($hammasir_action === 'client_request_new') {
        $hammasir_flag_ok = true;
        try {
            if (!hammasir_user_flag_set($hammasir_me_post, 'onboarding_seen', 1)) $hammasir_flag_ok = false;
            if (!hammasir_user_flag_set($hammasir_me_post, 'onboarding_intent', 1)) $hammasir_flag_ok = false;
        } catch (Throwable $e) {
            $hammasir_flag_ok = false;
        }
        if (!$hammasir_flag_ok) {
            error_log('hammasir onboarding flag write failed: store not writable.');
            flash_set('err', 'لطفاً دوباره تلاش کنید.');
        }
        joma_redirect('index.php?p=hammasir');
    }
    if ($hammasir_action === 'client_request_cancel') {
        try { hammasir_user_flag_set($hammasir_me_post, 'onboarding_intent', 0); } catch (Throwable $e) { /* بی‌اثر؛ وضعیت بسته دوباره می‌آید */ }
        unset($_SESSION['hammasir_show_request_form']); // پاک‌سازی Defensive نسخه‌های قبل
        joma_redirect('index.php?p=hammasir');
    }

    // --- میز کار مشاور — سطح دوم (بخش سه-۲): انتخاب لینک در session؛ بدون id در Query String ---
    if ($hammasir_action === 'provider_open') {
        $hammasir_open_ok = false;
        try {
            $hammasir_my_links = hammasir_links_by_provider($hammasir_me_post);
            if (is_array($hammasir_my_links)) {
                foreach ($hammasir_my_links as $hammasir_l) {
                    if ((int) $hammasir_l['id'] === $hammasir_link_id) {
                        $_SESSION['hammasir_prov_open'] = $hammasir_link_id;
                        $hammasir_open_ok = true;
                        break;
                    }
                }
            }
        } catch (Throwable $e) {
            $hammasir_open_ok = false;
        }
        if (!$hammasir_open_ok) flash_set('err', 'امکان انجام این عملیات در حال حاضر وجود ندارد.');
        joma_redirect('index.php?p=hammasir');
    }
    if ($hammasir_action === 'provider_close') {
        unset($_SESSION['hammasir_prov_open']);
        joma_redirect('index.php?p=hammasir');
    }

    // --- سوییچ حالت (2.3) ---
    if ($hammasir_action === 'mode_set') {
        $hammasir_mode_req = isset($_POST['mode']) ? (string) $_POST['mode'] : '';
        try {
            $hammasir_result = hammasir_mode_set($hammasir_mode_req);
        } catch (Throwable $e) {
            $hammasir_result = 'error';
        }
        if ($hammasir_result === 'ok') {
            flash_set('ok', 'حالت هم‌مسیر تغییر کرد.');
            // باگ ۲ (گزارش PO): حالت «کاربر» → داشبورد اصلی جوما؛ «مشاور/مدیر» → صفحه‌ی هم‌مسیر
            joma_redirect(($hammasir_mode_req === 'client') ? 'index.php?p=dashboard' : 'index.php?p=hammasir');
        }
        flash_set('err', 'امکان انجام این عملیات در حال حاضر وجود ندارد.');
        joma_redirect('index.php?p=hammasir');
    }

    $hammasir_result = 'error';
    if ($hammasir_action === 'provider_register' || $hammasir_action === 'provider_set_title' || $hammasir_action === 'provider_set_status' || $hammasir_action === 'admin_promote' || $hammasir_action === 'admin_demote') {
        // --- عملیات Registry (1C) + مدیریت مدیران (D-ADMIN-2) — فقط ADMIN_ACCESS ---
        if (has_perm('ADMIN_ACCESS')) {
            $hammasir_uid = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
            try {
                if ($hammasir_action === 'provider_register') {
                    $hammasir_result = hammasir_provider_register($hammasir_uid, isset($_POST['title']) ? $_POST['title'] : '');
                } elseif ($hammasir_action === 'provider_set_title') {
                    $hammasir_result = hammasir_provider_set_title($hammasir_uid, isset($_POST['title']) ? $_POST['title'] : '');
                } elseif ($hammasir_action === 'provider_set_status') {
                    $hammasir_status = isset($_POST['status']) ? (string) $_POST['status'] : '';
                    $hammasir_result = in_array($hammasir_status, array('ACTIVE', 'INACTIVE'), true)
                        ? hammasir_provider_set_status($hammasir_uid, $hammasir_status)
                        : 'error';
                } elseif ($hammasir_action === 'admin_promote') {
                    $hammasir_result = hammasir_user_set_role($hammasir_uid, 'admin', $hammasir_me_post);
                    // باگ ۶: ارتقای خودم؟ نقش نشست همین حالا تازه شود تا منو بی‌درنگ بیاید
                    if ($hammasir_result === 'ok' && $hammasir_uid === $hammasir_me_post && isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                        $_SESSION['user']['role_key'] = 'admin';
                    }
                } else { // admin_demote
                    $hammasir_result = hammasir_user_set_role($hammasir_uid, 'member', $hammasir_me_post);
                }
            } catch (Throwable $e) {
                $hammasir_result = 'error'; // D-1: هیچ exception به صفحه راه نمی‌یابد
            }
        }
        // باگ ۶ (گزارش PO): سلبِ خودمدیریتی — نقش نشست فوراً کم می‌شود، mode به «کاربر»
        // برمی‌گردد و ریدایرکت به داشبورد اصلی انجام می‌شود (منوی مدیریت بی‌درنگ ناپدید).
        if ($hammasir_action === 'admin_demote' && $hammasir_result === 'ok' && $hammasir_uid === $hammasir_me_post) {
            if (isset($_SESSION['user']) && is_array($_SESSION['user'])) $_SESSION['user']['role_key'] = 'member';
            unset($_SESSION['hammasir_mode'], $_SESSION['hammasir_mode_chosen']);
            try { hammasir_mode_set('client'); } catch (Throwable $e) { /* پیش‌فرض امن: client */ }
            flash_set('ok', 'نقش کاربر به کاربر عادی بازگشت.');
            joma_redirect('index.php?p=dashboard');
        }
    } elseif (in_array($hammasir_action, array('link_request', 'link_cancel', 'link_revoke', 'link_accept', 'link_decline', 'perms_update', 'messaging_set', 'message_send'), true)) {
        // --- عملیات فاز ۲..۵ — هر کاربر واردشده (گیت‌های مالکیت داخل توابع) ---
        try {
            if ($hammasir_action === 'link_request') {
                $hammasir_pv = array(
                    'VIEW_PROGRESS' => isset($_POST['perm_VIEW_PROGRESS']),
                    'VIEW_ACTIVITY_DETAILS' => isset($_POST['perm_VIEW_ACTIVITY_DETAILS']),
                    'VIEW_MOOD' => isset($_POST['perm_VIEW_MOOD']),
                );
                $hammasir_result = hammasir_link_request($hammasir_me_post, isset($_POST['provider_user_id']) ? (int) $_POST['provider_user_id'] : 0, $hammasir_pv);
                if ($hammasir_result === 'ok') {
                    // فرم مصرف شد: intent خاموش تا بعد از بسته‌شدن لینک (رد/قطع)، فرم خودکار باز نشود (حکم PO بخش ۴)
                    try { hammasir_user_flag_set($hammasir_me_post, 'onboarding_intent', 0); } catch (Throwable $e) { /* بی‌اثر؛ rule 1 رندر را تعیین می‌کند */ }
                    unset($_SESSION['hammasir_show_request_form']); // پاک‌سازی Defensive نسخه‌های قبل
                }
            } elseif ($hammasir_action === 'link_cancel' || $hammasir_action === 'link_revoke') {
                $hammasir_result = hammasir_link_revoke($hammasir_me_post, $hammasir_link_id);
            } elseif ($hammasir_action === 'link_accept') {
                $hammasir_result = hammasir_link_respond($hammasir_me_post, $hammasir_link_id, 'ACTIVE');
            } elseif ($hammasir_action === 'link_decline') {
                $hammasir_result = hammasir_link_respond($hammasir_me_post, $hammasir_link_id, 'DECLINED');
            } elseif ($hammasir_action === 'perms_update') {
                $hammasir_pv = array(
                    'VIEW_PROGRESS' => isset($_POST['perm_VIEW_PROGRESS']),
                    'VIEW_ACTIVITY_DETAILS' => isset($_POST['perm_VIEW_ACTIVITY_DETAILS']),
                    'VIEW_MOOD' => isset($_POST['perm_VIEW_MOOD']),
                );
                $hammasir_result = hammasir_perms_update_view($hammasir_me_post, $hammasir_link_id, $hammasir_pv);
            } elseif ($hammasir_action === 'messaging_set') {
                $hammasir_enabled_val = (isset($_POST['enabled']) && $_POST['enabled'] === '1');
                $hammasir_result = hammasir_messaging_set($hammasir_me_post, $hammasir_link_id, $hammasir_enabled_val);
            } else { // message_send
                $hammasir_result = hammasir_message_send($hammasir_me_post, $hammasir_link_id, isset($_POST['body']) ? $_POST['body'] : '');
            }
        } catch (Throwable $e) {
            $hammasir_result = 'error'; // D-1: مهار کامل؛ بدون rethrow
        }
    }

    // نگاشت نتیجه به پیام‌ها (مصوب‌های PO عیناً + رشته‌های جدید — گزارش می‌شود)
    $hammasir_msgs_ok = array(
        'provider_register' => 'همراه ثبت شد.',
        'provider_set_title' => 'عنوان به‌روزرسانی شد.',
        'provider_set_status' => 'وضعیت همراه تغییر کرد.',
        'admin_promote' => 'نقش کاربر به مدیر ارتقا یافت.',
        'admin_demote' => 'نقش کاربر به کاربر عادی بازگشت.',
        'link_request' => 'درخواست همراهی ارسال شد.',
        'link_cancel' => 'درخواست همراهی لغو شد.',
        'link_revoke' => 'ارتباط قطع شد.',
        'link_accept' => 'درخواست پذیرفته شد.',
        'link_decline' => 'درخواست رد شد.',
        'perms_update' => 'دسترسی‌ها به‌روزرسانی شد.',
        'messaging_set' => 'تنظیم پیام‌رسانی تغییر کرد.',
        'message_send' => 'پیام ارسال شد.',
    );
    $hammasir_msgs_err = array(
        'invalid_user' => 'کاربر انتخاب‌شده معتبر نیست.',
        'duplicate' => 'این کاربر قبلاً به‌عنوان همراه ثبت شده است.',
        'invalid_title' => 'عنوان همراه الزامی است و حداکثر ۱۲۸ کاراکتر.',
        'open_link' => 'این همراه ارتباط باز (در انتظار یا فعال) دارد و نمی‌توان غیرفعالش کرد.',
        'self_link' => 'نمی‌توانید خودتان را همراه انتخاب کنید.',
        'invalid_provider' => 'همراه انتخاب‌شده در دسترس نیست.',
        'open_link_exists' => 'شما هم‌اکنون یک درخواست یا ارتباط باز دارید.',
        'request_cooldown' => 'برای درخواست مجدد به این همراه باید کمی صبر کنید.',
        'not_active' => 'در حال حاضر امکان ارسال پیام نیست.',
        'invalid_body' => 'متن پیام الزامی است و حداکثر ۲۰۰۰ کاراکتر.',
        'messaging_off' => 'همراه شما در حال حاضر دریافت پیام را فعال نکرده است.',
        'client_limit' => 'سقف پیام‌های امروز تکمیل شده است. امکان ارسال پیام جدید از فردا فعال می‌شود.',
        'companion_limit' => 'سقف روزانه‌ی پیام شما تکمیل شده است.',
        'cooldown' => 'لطفاً چند ثانیه دیگر تلاش کنید.',
        'lock_timeout' => 'لطفاً دوباره تلاش کنید.',
        'last_admin' => 'آخرین مدیر سیستم قابل حذف از مدیریت نیست.',
        'env' => 'امکان پردازش متن در حال حاضر وجود ندارد.',
        'error' => 'امکان انجام این عملیات در حال حاضر وجود ندارد.',
    );
    if ($hammasir_result === 'ok' && isset($hammasir_msgs_ok[$hammasir_action])) {
        flash_set('ok', $hammasir_msgs_ok[$hammasir_action]);
    } else {
        flash_set('err', isset($hammasir_msgs_err[$hammasir_result]) ? $hammasir_msgs_err[$hammasir_result] : $hammasir_msgs_err['error']);
    }
    // PRG: بازگشت به همان route — بدون هیچ شناسه در Query String (D29)
    joma_redirect('index.php?p=hammasir');
}

// ===== نمایش =====
$hammasir_u = current_user();
$hammasir_me = (int) $hammasir_u['id'];
$hammasir_today = hammasir_jalali_today();
$hammasir_today = ($hammasir_today !== null) ? jalali_format($hammasir_today) : '';
$hammasir_is_admin = has_perm('ADMIN_ACCESS');

// Bootstrap مالک (4.2) — فقط وقتی هیچ مدیری نیست و config خواسته؛ fail-closed
$hammasir_boot = array('status' => 'done');
try {
    $hammasir_boot = hammasir_bootstrap_owner();
} catch (Throwable $e) {
    $hammasir_boot = array('status' => 'error');
}

// قابلیت‌ها + حالت فعال (2.2/2.3/2.4) — با مهار خطای PHP 8.1 (D-1)
$hammasir_cap = array('can_admin' => false, 'can_provider' => false, 'can_client' => true);
$hammasir_mode = 'client';
$hammasir_mode_chosen = true;
$hammasir_my_provider = null;
$hammasir_unread = array('msgs' => 0, 'events' => 0, 'total' => 0);
$hammasir_events = array();
$hammasir_pending_count = 0;
try {
    $hammasir_cap = hammasir_capabilities();
    $hammasir_mode = hammasir_active_mode();
    $hammasir_mode_chosen = hammasir_mode_chosen();
    $hammasir_my_provider = hammasir_provider_by_user_id($hammasir_me);
    $hammasir_unread = hammasir_unread_badge($hammasir_me);
    $hammasir_events = hammasir_events_list($hammasir_me);
    if (!is_array($hammasir_events)) $hammasir_events = array();
    if ($hammasir_mode === 'provider') {
        $hammasir_p_list = hammasir_links_by_provider($hammasir_me, 'PENDING');
        $hammasir_pending_count = is_array($hammasir_p_list) ? count($hammasir_p_list) : 0;
    }
} catch (Throwable $e) {
    // حالت پیش‌فرض امن: client با Badge صفر
    $hammasir_cap = array('can_admin' => false, 'can_provider' => false, 'can_client' => true);
    $hammasir_mode = 'client';
    $hammasir_mode_chosen = true;
    $hammasir_my_provider = null;
    $hammasir_unread = array('msgs' => 0, 'events' => 0, 'total' => 0);
    $hammasir_events = array();
    $hammasir_pending_count = 0;
}

// چند‌قابلیتی؟ (برای کارت‌های انتخاب حالت — 2.3)
$hammasir_multi = 0;
if ($hammasir_cap['can_admin']) $hammasir_multi++;
if ($hammasir_cap['can_provider']) $hammasir_multi++;
if ($hammasir_multi < 2) $hammasir_multi = 0; // can_client همیشه هست؛ فقط وقتی کارت بده که >1 قابلیت هم‌مسیر باشد

$hammasir_mode_labels = hammasir_mode_labels();
$hammasir_mode_cards = hammasir_mode_card_labels();

joma_header('هم‌مسیر', array(array('label' => 'هم‌مسیر')));
echo flash_get();
?>
<section class="card hero-card">
  <?php if ($hammasir_today !== '') { ?><p class="lede"><?php echo e($hammasir_today); ?></p><?php } ?>
  <h1>هم‌مسیر</h1>
  <div class="btn-row">
    <span class="chip"><?php echo e($hammasir_u['full_name'] ? $hammasir_u['full_name'] : $hammasir_u['username']); ?></span>
    <span class="chip">حالت: <?php echo e($hammasir_mode_labels[$hammasir_mode]); ?></span>
  </div>
  <?php if ($hammasir_unread['msgs'] > 0 || $hammasir_unread['events'] > 0 || $hammasir_pending_count > 0) { ?>
  <div class="btn-row">
    <?php if ($hammasir_unread['msgs'] > 0) { ?><span class="chip">پیام جدید: <?php echo (int) $hammasir_unread['msgs']; ?></span><?php } ?>
    <?php if ($hammasir_unread['events'] > 0) { ?><span class="chip">رویداد جدید: <?php echo (int) $hammasir_unread['events']; ?></span><?php } ?>
    <?php if ($hammasir_pending_count > 0) { ?><span class="chip">درخواست نیازمند اقدام: <?php echo (int) $hammasir_pending_count; ?></span><?php } ?>
  </div>
  <?php } ?>
</section>
<?php
// راهنمای Bootstrap مالک (4.2) — فقط حالت‌های راه‌اندازی
if ($hammasir_boot['status'] === 'need_username' || $hammasir_boot['status'] === 'user_missing' || $hammasir_boot['status'] === 'promoted') {
    ?>
    <section class="card">
      <h2>راه‌اندازی مالک سیستم</h2>
      <?php if ($hammasir_boot['status'] === 'promoted') { ?>
        <p>کاربر «<?php echo e(isset($hammasir_boot['username']) ? $hammasir_boot['username'] : ''); ?>» به‌عنوان مالک سیستم به مدیر ارتقا یافت. برای اعمال دسترسی مدیریتی، یک‌بار خارج و دوباره وارد شوید.</p>
      <?php } elseif ($hammasir_boot['status'] === 'need_username') { ?>
        <p>سیستم هم‌مسیر آماده‌ی راه‌اندازی است. برای تعیین مالک سیستم، در فایل <code>config/hammasir_config.php</code> مقدار <code>bootstrap_owner_username</code> را با نام کاربری مورداعتماد پر کنید. تا آن زمان هیچ ارتقای خودکاری انجام نمی‌شود.</p>
      <?php } else { ?>
        <p>کاربر تعیین‌شده در <code>bootstrap_owner_username</code> یافت نشد؛ نام کاربری را در فایل config بررسی کنید.</p>
      <?php } ?>
    </section>
    <?php
}
// کارت‌های انتخاب حالت (2.3) — فقط برای کاربر چندقابلیتی که هنوز انتخاب صریح نکرده
if ($hammasir_multi > 0 && !$hammasir_mode_chosen) {
    ?>
    <section class="card">
      <h2>انتخاب حالت هم‌مسیر</h2>
      <p>شما در هم‌مسیر بیش از یک نقش دارید؛ حالت مورد نظرتان را انتخاب کنید. هر زمان از منوی کناری می‌توانید تغییرش دهید.</p>
      <div class="btn-row">
        <?php foreach (array('client', 'provider', 'admin') as $hammasir_m) { ?>
          <?php
          if ($hammasir_m === 'provider' && !$hammasir_cap['can_provider']) continue;
          if ($hammasir_m === 'admin' && !$hammasir_cap['can_admin']) continue;
          ?>
          <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="hammasir_action" value="mode_set">
            <input type="hidden" name="mode" value="<?php echo e($hammasir_m); ?>">
            <button class="btn" type="submit"><?php echo e($hammasir_mode_cards[$hammasir_m]); ?></button>
          </form>
        <?php } ?>
      </div>
    </section>
    <?php
}
?>
<?php if (count($hammasir_events) > 0) { ?>
<section class="card">
  <h2>اعلان‌ها</h2>
  <?php foreach ($hammasir_events as $hammasir_ev) { ?>
    <div class="btn-row">
      <span class="chip"><?php echo e(((int) $hammasir_ev['is_read'] === 0) ? 'جدید' : 'دیده‌شده'); ?></span>
      <span><?php echo e(hammasir_event_label($hammasir_ev['event_type'])); ?></span>
      <small><?php echo e(jalali_format(hammasir_dt_to_jalali($hammasir_ev['created_at'])) . ' — ' . substr((string) $hammasir_ev['created_at'], 11, 5)); ?></small>
    </div>
  <?php } ?>
</section>
<?php
// مشاهده‌ی اعلان‌ها = خوانده‌شده برای کاربر جاری (فقط recipient خودم — AC5.3)
try {
    hammasir_mark_events_read($hammasir_me);
} catch (Throwable $e) {
    // بی‌اثر برای نمایش؛ دفعه‌ی بعد دوباره تلاش می‌شود
}
} ?>
<?php
// رندر بر اساس حالت فعال (2.5) — دقیقاً یک جریان:
if ($hammasir_mode === 'admin') {
    // فقط لندینگ مدیریت با دو لینک جدا (حکم PO — بخش دو-۱: صفحه‌های مستقل، بدون تودرتو)
    include dirname(__FILE__) . '/hammasir_admin.php';
} elseif ($hammasir_mode === 'provider') {
    // داشبورد مشاور (5.1) — بدون جریان مراجع و بدون فهرست انتخاب
    include dirname(__FILE__) . '/hammasir_provider.php';
} else {
    // تجربه‌ی مراجع (بخش ۶) — Onboarding یک‌باره/انتخاب/وضعیت
    include dirname(__FILE__) . '/hammasir_client.php';
}
joma_footer();
