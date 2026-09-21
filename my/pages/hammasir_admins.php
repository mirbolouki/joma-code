<?php
/*
 * ماژول «هم‌مسیر» — صفحه‌ی مستقل «مدیران سیستم» (D-ADMIN-2 — حکم PO بخش دو)
 * ------------------------------------------------------------------
 * Route مجاز فقط از Router مرکزی: index.php?p=hammasir_admins (D29).
 * فقط ADMIN_ACCESS. به‌جای «جدول کامل کاربران»، فرم «انتخاب کاربر» با <select>
 * بومی (PC-1)؛ متن گزینه: «نام خانوادگی، نام (نام‌کاربری)».
 * عملیات: ارتقا به مدیر (role_key=admin) / حذف از مدیریت (role_key=member) —
 * قفل آخرین مدیر در Backend (hammasir_user_set_role).
 * سلب مدیریت از خودِ کاربر جاری → نقش نشست فوراً به‌روز + ریدایرکت داشبورد.
 */

// ۱) گارد مستقیم — دسترسی مستقیم هرگز مشروع نیست → 403 خشک بدون هیچ خروجی
if (!defined('JOMA_IN_APP')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

// ۲) گارد ورود
require_login();

// ۳) گارد Feature Flag — fail-closed
if (!function_exists('hammasir_enabled') || !hammasir_enabled()) {
    joma_redirect('index.php?p=dashboard');
}

header('Cache-Control: private, no-store');

// ۴) گارد محیط (fail-closed)
if (!hammasir_env_ok()) {
    joma_header('مدیران سیستم', array(array('label' => 'هم‌مسیر', 'href' => joma_url('index.php?p=hammasir')), array('label' => 'مدیران سیستم')));
    ?>
    <section class="card">
      <h1>مدیران سیستم</h1>
      <p>ماژول هم‌مسیر در حال حاضر در دسترس نیست.</p>
    </section>
    <?php
    joma_footer();
    return;
}

// ۵) گارد مدیر — فقط ADMIN_ACCESS
if (!has_perm('ADMIN_ACCESS')) {
    joma_redirect('index.php?p=hammasir');
}

// ===== پردازش POST — CSRF اجباری؛ مهار کامل Throwable (D-1)؛ PRG =====
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hammasir_am_action = isset($_POST['hammasir_action']) ? (string) $_POST['hammasir_action'] : '';
    $hammasir_am_uid = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $hammasir_am_me = 0;
    $hammasir_am_u = current_user();
    if ($hammasir_am_u) $hammasir_am_me = (int) $hammasir_am_u['id'];

    if ($hammasir_am_action === 'admins_pick') {
        $_SESSION['hammasir_adm_sel'] = $hammasir_am_uid;
        joma_redirect('index.php?p=hammasir_admins');
    }

    if ($hammasir_am_action === 'admin_promote' || $hammasir_am_action === 'admin_demote') {
        $hammasir_am_result = 'error';
        try {
            if ($hammasir_am_action === 'admin_promote') {
                $hammasir_am_result = hammasir_user_set_role($hammasir_am_uid, 'admin', $hammasir_am_me);
                // ارتقای خودم؟ نقش نشست همین حالا تازه شود تا منو بی‌درنگ بیاید (باگ ۶)
                if ($hammasir_am_result === 'ok' && $hammasir_am_uid === $hammasir_am_me && isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                    $_SESSION['user']['role_key'] = 'admin';
                }
            } else { // admin_demote
                $hammasir_am_result = hammasir_user_set_role($hammasir_am_uid, 'member', $hammasir_am_me);
            }
        } catch (Throwable $e) {
            $hammasir_am_result = 'error'; // D-1
        }
        // سلبِ خودمدیریتی — نقش نشست فوراً کم می‌شود، mode به «کاربر» برمی‌گردد و
        // ریدایرکت به داشبورد اصلی (منوی مدیریت بی‌درنگ ناپدید — باگ ۶)
        if ($hammasir_am_action === 'admin_demote' && $hammasir_am_result === 'ok' && $hammasir_am_uid === $hammasir_am_me) {
            if (isset($_SESSION['user']) && is_array($_SESSION['user'])) $_SESSION['user']['role_key'] = 'member';
            unset($_SESSION['hammasir_mode'], $_SESSION['hammasir_mode_chosen']);
            try { hammasir_mode_set('client'); } catch (Throwable $e) { /* پیش‌فرض امن: client */ }
            flash_set('ok', 'نقش کاربر به کاربر عادی بازگشت.');
            joma_redirect('index.php?p=dashboard');
        }
        $hammasir_am_msgs_ok = array(
            'admin_promote' => 'نقش کاربر به مدیر ارتقا یافت.',
            'admin_demote' => 'نقش کاربر به کاربر عادی بازگشت.',
        );
        $hammasir_am_msgs_err = array(
            'not_found' => 'کاربر انتخاب‌شده معتبر نیست.',
            'invalid_role' => 'امکان انجام این عملیات در حال حاضر وجود ندارد.',
            'last_admin' => 'آخرین مدیر سیستم قابل حذف از مدیریت نیست.',
            'error' => 'امکان انجام این عملیات در حال حاضر وجود ندارد.',
        );
        if ($hammasir_am_result === 'ok') {
            flash_set('ok', $hammasir_am_msgs_ok[$hammasir_am_action]);
        } else {
            flash_set('err', isset($hammasir_am_msgs_err[$hammasir_am_result]) ? $hammasir_am_msgs_err[$hammasir_am_result] : $hammasir_am_msgs_err['error']);
        }
        joma_redirect('index.php?p=hammasir_admins');
    }
    joma_redirect('index.php?p=hammasir_admins');
}

// ===== خواندها (مهار Throwable — D-1) =====
$hammasir_am_options = array();
$hammasir_am_overflow = false;
$hammasir_am_admins = array();
try {
    $hammasir_am_all = hammasir_users_list_roles(500);
    if (!is_array($hammasir_am_all)) $hammasir_am_all = array();
    $hammasir_am_options = hammasir_registry_user_options();
    if (!is_array($hammasir_am_options)) $hammasir_am_options = array();
    $hammasir_am_overflow = (count($hammasir_am_options) > hammasir_user_select_max());
    foreach ($hammasir_am_all as $hammasir_am_u2) {
        if ($hammasir_am_u2['role_key'] === 'admin') $hammasir_am_admins[] = $hammasir_am_u2;
    }
} catch (Throwable $e) {
    $hammasir_am_options = array();
    $hammasir_am_admins = array();
}

// کاربرِ انتخاب‌شده (session) + وضعیت او
$hammasir_am_sel_id = isset($_SESSION['hammasir_adm_sel']) ? (int) $_SESSION['hammasir_adm_sel'] : 0;
$hammasir_am_sel_user = null;
if ($hammasir_am_sel_id > 0) {
    try {
        $hammasir_am_sel_user = get_user($hammasir_am_sel_id);
    } catch (Throwable $e) {
        $hammasir_am_sel_user = null;
    }
}

joma_header('مدیران سیستم', array(array('label' => 'هم‌مسیر', 'href' => joma_url('index.php?p=hammasir')), array('label' => 'مدیران سیستم')));
echo flash_get();
?>
<section class="card">
  <h1>مدیران سیستم</h1>
  <p>ارتقا یا سلب نقش مدیر برای کاربران موجود جوما. پس از تغییر نقش، آن کاربر باید یک‌بار خارج و وارد شود تا دسترسی جدید اعمال گردد. آخرین مدیر سیستم قابل حذف از مدیریت نیست.</p>
  <form class="card" method="post">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="hammasir_action" value="admins_pick">
    <label>انتخاب کاربر</label>
    <?php if ($hammasir_am_overflow) { ?>
      <!-- سرریز سقف نمایش (C-2): ورودی عددی به‌جای select؛ اعتبارسنجی سرور یکسان است -->
      <input type="number" name="user_id" min="1" required>
    <?php } else { ?>
      <select name="user_id" required>
        <option value="" disabled selected>انتخاب کنید</option>
        <?php foreach ($hammasir_am_options as $hammasir_am_opt) { ?>
          <option value="<?php echo (int) $hammasir_am_opt['id']; ?>"<?php if ((int) $hammasir_am_opt['id'] === $hammasir_am_sel_id) echo ' selected'; ?>><?php echo e($hammasir_am_opt['display']); ?></option>
        <?php } ?>
      </select>
    <?php } ?>
    <div class="btn-row">
      <button class="btn" type="submit">نمایش</button>
    </div>
  </form>
</section>
<?php if ($hammasir_am_sel_id > 0) { ?>
  <?php if (!$hammasir_am_sel_user) { ?>
    <section class="card">
      <h2>وضعیت کاربر انتخاب‌شده</h2>
      <p>کاربر انتخاب‌شده معتبر نیست.</p>
    </section>
  <?php } else { ?>
    <?php
    $hammasir_am_name = trim($hammasir_am_sel_user['last_name'] . '، ' . $hammasir_am_sel_user['first_name']);
    if ($hammasir_am_name === '' || $hammasir_am_name === '، ') $hammasir_am_name = $hammasir_am_sel_user['username'];
    $hammasir_am_sel_is_admin = ($hammasir_am_sel_user['role_key'] === 'admin');
    ?>
    <section class="card">
      <h2>وضعیت کاربر انتخاب‌شده</h2>
      <div class="btn-row">
        <span class="chip"><?php echo e($hammasir_am_name . ' (' . $hammasir_am_sel_user['username'] . ')'); ?></span>
        <span class="chip">نقش جوما: <?php echo $hammasir_am_sel_is_admin ? 'مدیر' : 'کاربر عادی'; ?></span>
      </div>
      <?php if (!$hammasir_am_sel_is_admin) { ?>
        <form method="post">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="hammasir_action" value="admin_promote">
          <input type="hidden" name="user_id" value="<?php echo (int) $hammasir_am_sel_id; ?>">
          <div class="btn-row">
            <button class="btn" type="submit">ارتقا به مدیر</button>
          </div>
        </form>
      <?php } else { ?>
        <form method="post">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="hammasir_action" value="admin_demote">
          <input type="hidden" name="user_id" value="<?php echo (int) $hammasir_am_sel_id; ?>">
          <div class="btn-row">
            <button class="btn sec" type="submit">حذف از مدیریت</button>
          </div>
        </form>
      <?php } ?>
    </section>
  <?php } ?>
<?php } ?>
<?php
// فهرست خلاصه‌ی اختیاری (بخش دو-۷): فقط مدیران فعلی — بدون دکمه؛ بیش از ۵۰ → فقط شمارش
if (count($hammasir_am_admins) > 50) {
    ?>
    <section class="card">
      <h2>مدیران فعلی</h2>
      <p>تعداد مدیران فعلی: <?php echo (int) count($hammasir_am_admins); ?></p>
    </section>
    <?php
} elseif (count($hammasir_am_admins) > 0) {
    ?>
    <section class="card">
      <h2>مدیران فعلی</h2>
      <?php foreach ($hammasir_am_admins as $hammasir_am_a) { ?>
        <?php $hammasir_an = trim($hammasir_am_a['first_name'] . ' ' . $hammasir_am_a['last_name']); ?>
        <div class="btn-row">
          <span class="chip"><?php echo e($hammasir_an !== '' ? $hammasir_an : $hammasir_am_a['username']); ?></span>
          <span class="chip"><?php echo e($hammasir_am_a['username']); ?></span>
        </div>
      <?php } ?>
    </section>
    <?php
} else {
    ?>
    <section class="card">
      <h2>مدیران فعلی</h2>
      <p>مدیری وجود ندارد.</p>
    </section>
    <?php
}
joma_footer();
