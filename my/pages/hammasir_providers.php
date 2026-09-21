<?php
/*
 * ماژول «هم‌مسیر» — صفحه‌ی مستقل «مدیریت مشاوران» (حکم PO — بخش دو)
 * ------------------------------------------------------------------
 * Route مجاز فقط از Router مرکزی: index.php?p=hammasir_providers (D29).
 * فقط ADMIN_ACCESS. به‌جای «جدول کامل کاربران»، یک فرم «انتخاب کاربر» با
 * <select> بومی (بدون JS — PC-1)؛ متن گزینه: «نام خانوادگی، نام (نام‌کاربری)».
 * «نقش مشاور» = همان رکورد Registry (joma_hammasir_providers) — بدون سیستم
 * نقش موازی/فیلد جدید روی joma_users/role_key جدید (قاعده‌ی قطعی PO).
 * عملیات: دادن/فعال‌کردن/غیرفعال‌کردن نقش مشاور + ویرایش عنوان (همه POST+CSRF+PRG).
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
    joma_header('مدیریت مشاوران', array(array('label' => 'هم‌مسیر', 'href' => joma_url('index.php?p=hammasir')), array('label' => 'مدیریت مشاوران')));
    ?>
    <section class="card">
      <h1>مدیریت مشاوران</h1>
      <p>ماژول هم‌مسیر در حال حاضر در دسترس نیست.</p>
    </section>
    <?php
    joma_footer();
    return;
}

// ۵) گارد مدیر — فقط ADMIN_ACCESS (حکم PO بخش دو-۸: کاربر عادی هرگز)
if (!has_perm('ADMIN_ACCESS')) {
    joma_redirect('index.php?p=hammasir');
}

// ===== پردازش POST — CSRF اجباری؛ مهار کامل Throwable (D-1)؛ PRG =====
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hammasir_pr_action = isset($_POST['hammasir_action']) ? (string) $_POST['hammasir_action'] : '';
    $hammasir_pr_uid = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
    $hammasir_pr_result = 'error';

    if ($hammasir_pr_action === 'providers_pick') {
        // انتخاب کاربر برای نمایش وضعیت — فقط ذخیره در session و PRG (بدون id در Query String)
        $_SESSION['hammasir_prov_sel'] = $hammasir_pr_uid;
        joma_redirect('index.php?p=hammasir_providers');
    }

    if (in_array($hammasir_pr_action, array('provider_register', 'provider_set_title', 'provider_set_status'), true)) {
        try {
            if ($hammasir_pr_action === 'provider_register') {
                $hammasir_pr_result = hammasir_provider_register($hammasir_pr_uid, isset($_POST['title']) ? $_POST['title'] : '');
            } elseif ($hammasir_pr_action === 'provider_set_title') {
                $hammasir_pr_result = hammasir_provider_set_title($hammasir_pr_uid, isset($_POST['title']) ? $_POST['title'] : '');
            } else { // provider_set_status
                $hammasir_pr_status = isset($_POST['status']) ? (string) $_POST['status'] : '';
                $hammasir_pr_result = in_array($hammasir_pr_status, array('ACTIVE', 'INACTIVE'), true)
                    ? hammasir_provider_set_status($hammasir_pr_uid, $hammasir_pr_status)
                    : 'error';
            }
        } catch (Throwable $e) {
            $hammasir_pr_result = 'error'; // D-1
        }
        $hammasir_pr_msgs_ok = array(
            'provider_register' => 'همراه ثبت شد.',
            'provider_set_title' => 'عنوان به‌روزرسانی شد.',
            'provider_set_status' => 'وضعیت همراه تغییر کرد.',
        );
        $hammasir_pr_msgs_err = array(
            'invalid_user' => 'کاربر انتخاب‌شده معتبر نیست.',
            'duplicate' => 'این کاربر قبلاً به‌عنوان همراه ثبت شده است.',
            'invalid_title' => 'عنوان همراه الزامی است و حداکثر ۱۲۸ کاراکتر.',
            'open_link' => 'این همراه ارتباط باز (در انتظار یا فعال) دارد و نمی‌توان غیرفعالش کرد.',
            'lock_timeout' => 'لطفاً دوباره تلاش کنید.',
            'env' => 'امکان پردازش متن در حال حاضر وجود ندارد.',
            'error' => 'امکان انجام این عملیات در حال حاضر وجود ندارد.',
        );
        if ($hammasir_pr_result === 'ok') {
            flash_set('ok', $hammasir_pr_msgs_ok[$hammasir_pr_action]);
        } else {
            flash_set('err', isset($hammasir_pr_msgs_err[$hammasir_pr_result]) ? $hammasir_pr_msgs_err[$hammasir_pr_result] : $hammasir_pr_msgs_err['error']);
        }
        joma_redirect('index.php?p=hammasir_providers');
    }
    joma_redirect('index.php?p=hammasir_providers');
}

// ===== خواندها (مهار Throwable — D-1) =====
$hammasir_pr_options = array();
$hammasir_pr_overflow = false;
$hammasir_pr_registry = array();
try {
    $hammasir_pr_options = hammasir_registry_user_options();
    if (!is_array($hammasir_pr_options)) $hammasir_pr_options = array();
    $hammasir_pr_overflow = (count($hammasir_pr_options) > hammasir_user_select_max());
    $hammasir_pr_registry = hammasir_registry_list();
    if (!is_array($hammasir_pr_registry)) $hammasir_pr_registry = array();
} catch (Throwable $e) {
    $hammasir_pr_options = array();
    $hammasir_pr_registry = array();
}

// کاربرِ انتخاب‌شده (session) + وضعیت او
$hammasir_pr_sel_id = isset($_SESSION['hammasir_prov_sel']) ? (int) $_SESSION['hammasir_prov_sel'] : 0;
$hammasir_pr_sel_user = null;
$hammasir_pr_sel_provider = null;
$hammasir_pr_sel_open = 0;
if ($hammasir_pr_sel_id > 0) {
    try {
        $hammasir_pr_sel_user = get_user($hammasir_pr_sel_id);
        if ($hammasir_pr_sel_user) {
            $hammasir_pr_sel_provider = hammasir_provider_by_user_id($hammasir_pr_sel_id);
            $hammasir_pr_pend = hammasir_links_by_provider($hammasir_pr_sel_id, 'PENDING');
            $hammasir_pr_act = hammasir_links_by_provider($hammasir_pr_sel_id, 'ACTIVE');
            $hammasir_pr_sel_open = (is_array($hammasir_pr_pend) ? count($hammasir_pr_pend) : 0) + (is_array($hammasir_pr_act) ? count($hammasir_pr_act) : 0);
        }
    } catch (Throwable $e) {
        $hammasir_pr_sel_user = null;
        $hammasir_pr_sel_provider = null;
        $hammasir_pr_sel_open = 0;
    }
}

joma_header('مدیریت مشاوران', array(array('label' => 'هم‌مسیر', 'href' => joma_url('index.php?p=hammasir')), array('label' => 'مدیریت مشاوران')));
echo flash_get();
?>
<section class="card">
  <h1>مدیریت مشاوران</h1>
  <p>دادن یا گرفتن نقش مشاور به کاربران و مدیریت وضعیت و عنوان. نقش مشاور همان رکورد همراه در هم‌مسیر است؛ غیرفعال‌کردن هیچ داده‌ای حذف نمی‌کند و همراه فقط از فهرست انتخاب مراجعان خارج می‌شود.</p>
  <form class="card" method="post">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="hammasir_action" value="providers_pick">
    <label>انتخاب کاربر</label>
    <?php if ($hammasir_pr_overflow) { ?>
      <!-- سرریز سقف نمایش (C-2): ورودی عددی به‌جای select؛ اعتبارسنجی سرور یکسان است -->
      <input type="number" name="user_id" min="1" required>
    <?php } else { ?>
      <select name="user_id" required>
        <option value="" disabled selected>انتخاب کنید</option>
        <?php foreach ($hammasir_pr_options as $hammasir_pr_opt) { ?>
          <option value="<?php echo (int) $hammasir_pr_opt['id']; ?>"<?php if ((int) $hammasir_pr_opt['id'] === $hammasir_pr_sel_id) echo ' selected'; ?>><?php echo e($hammasir_pr_opt['display']); ?></option>
        <?php } ?>
      </select>
    <?php } ?>
    <div class="btn-row">
      <button class="btn" type="submit">نمایش</button>
    </div>
  </form>
</section>
<?php if ($hammasir_pr_sel_id > 0) { ?>
  <?php if (!$hammasir_pr_sel_user) { ?>
    <section class="card">
      <h2>وضعیت کاربر انتخاب‌شده</h2>
      <p>کاربر انتخاب‌شده معتبر نیست.</p>
    </section>
  <?php } else { ?>
    <?php
    $hammasir_pr_name = trim($hammasir_pr_sel_user['last_name'] . '، ' . $hammasir_pr_sel_user['first_name']);
    if ($hammasir_pr_name === '' || $hammasir_pr_name === '، ') $hammasir_pr_name = $hammasir_pr_sel_user['username'];
    $hammasir_pr_is_admin = ($hammasir_pr_sel_user['role_key'] === 'admin');
    ?>
    <section class="card">
      <h2>وضعیت کاربر انتخاب‌شده</h2>
      <div class="btn-row">
        <span class="chip"><?php echo e($hammasir_pr_name . ' (' . $hammasir_pr_sel_user['username'] . ')'); ?></span>
        <span class="chip">نقش جوما: <?php echo $hammasir_pr_is_admin ? 'مدیر' : 'کاربر عادی'; ?></span>
        <span class="chip">نقش مشاور: <?php echo $hammasir_pr_sel_provider ? (($hammasir_pr_sel_provider['status'] === 'ACTIVE') ? 'فعال' : 'غیرفعال') : 'ندارد'; ?></span>
        <?php if ($hammasir_pr_sel_provider) { ?>
          <span class="chip">عنوان: <?php echo e($hammasir_pr_sel_provider['title']); ?></span>
          <span class="chip">ارتباط باز: <?php echo (int) $hammasir_pr_sel_open; ?></span>
        <?php } ?>
      </div>
      <?php if (!$hammasir_pr_sel_provider) { ?>
        <!-- دادن نقش مشاور: ثبت در Registry با عنوان الزامی + وضعیت ACTIVE -->
        <form class="card" method="post">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="hammasir_action" value="provider_register">
          <input type="hidden" name="user_id" value="<?php echo (int) $hammasir_pr_sel_id; ?>">
          <label>عنوان همراه</label>
          <input type="text" name="title" maxlength="128" required>
          <div class="btn-row">
            <button class="btn" type="submit">دادن نقش مشاور</button>
          </div>
        </form>
      <?php } elseif ($hammasir_pr_sel_provider['status'] === 'INACTIVE') { ?>
        <form method="post">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="hammasir_action" value="provider_set_status">
          <input type="hidden" name="user_id" value="<?php echo (int) $hammasir_pr_sel_id; ?>">
          <input type="hidden" name="status" value="ACTIVE">
          <div class="btn-row">
            <button class="btn" type="submit">فعال‌کردن نقش مشاور</button>
          </div>
        </form>
      <?php } else { ?>
        <form method="post">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="hammasir_action" value="provider_set_status">
          <input type="hidden" name="user_id" value="<?php echo (int) $hammasir_pr_sel_id; ?>">
          <input type="hidden" name="status" value="INACTIVE">
          <div class="btn-row">
            <button class="btn sec" type="submit">غیرفعال‌کردن نقش مشاور</button>
          </div>
        </form>
      <?php } ?>
      <?php if ($hammasir_pr_sel_provider) { ?>
        <details>
          <summary>ویرایش عنوان مشاور</summary>
          <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="hammasir_action" value="provider_set_title">
            <input type="hidden" name="user_id" value="<?php echo (int) $hammasir_pr_sel_id; ?>">
            <label>عنوان همراه</label>
            <input type="text" name="title" maxlength="128" value="<?php echo e($hammasir_pr_sel_provider['title']); ?>" required>
            <div class="btn-row">
              <button class="btn" type="submit">ذخیره</button>
            </div>
          </form>
        </details>
      <?php } ?>
    </section>
  <?php } ?>
<?php } ?>
<?php
// فهرست خلاصه‌ی اختیاری (بخش دو-۷): فقط مشاوران فعلی — بدون دکمه؛ بیش از ۵۰ → فقط شمارش
if (count($hammasir_pr_registry) > 50) {
    ?>
    <section class="card">
      <h2>مشاوران فعلی</h2>
      <p>تعداد مشاوران فعلی: <?php echo (int) count($hammasir_pr_registry); ?></p>
    </section>
    <?php
} elseif (count($hammasir_pr_registry) > 0) {
    ?>
    <section class="card">
      <h2>مشاوران فعلی</h2>
      <?php foreach ($hammasir_pr_registry as $hammasir_pr_r) { ?>
        <div class="btn-row">
          <span class="chip"><?php echo e($hammasir_pr_r['display_name']); ?></span>
          <span class="chip"><?php echo e($hammasir_pr_r['title']); ?></span>
          <span class="chip"><?php echo ($hammasir_pr_r['status'] === 'ACTIVE') ? 'فعال' : 'غیرفعال'; ?></span>
        </div>
      <?php } ?>
    </section>
    <?php
}
joma_footer();
