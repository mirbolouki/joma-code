<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — مرکز دسترسی به تنظیمات کلینیک
 *  این صفحه پیوندهای تنظیماتی را که از قبل در سامانه فعال‌اند یکجا می‌کند.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));
if (!clinic_admin_modules_ready($db)) {
    redirect(APP_BASE_URL . '/upgrade_admin_modules.php');
}

$settings = clinic_settings_fetch($db);
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $clinic_name = isset($_POST['clinic_name']) ? trim((string)$_POST['clinic_name']) : '';
    $clinic_phone = isset($_POST['clinic_phone']) ? trim(to_latin_digits((string)$_POST['clinic_phone'])) : '';
    $clinic_address = isset($_POST['clinic_address']) ? trim((string)$_POST['clinic_address']) : '';
    $clinic_phone = preg_replace('/\s+/', ' ', $clinic_phone);

    $settings['clinic_name'] = $clinic_name;
    $settings['clinic_phone'] = $clinic_phone;
    $settings['clinic_address'] = $clinic_address;

    if ($clinic_name === '') {
        $error_message = 'نام کلینیک را وارد کنید.';
    } elseif (clinic_text_length($clinic_name) > 150) {
        $error_message = 'نام کلینیک باید حداکثر ۱۵۰ نویسه باشد.';
    } elseif ($clinic_phone !== '' && !preg_match('/^[0-9+() \-]{7,30}$/D', $clinic_phone)) {
        $error_message = 'شمارهٔ تماس را با عدد و نشانه‌های +، فاصله، پرانتز یا خط تیره وارد کنید.';
    } elseif (clinic_text_length($clinic_address) > 500) {
        $error_message = 'نشانی کلینیک باید حداکثر ۵۰۰ نویسه باشد.';
    } else {
        try {
            clinic_settings_save(
                $db,
                $clinic_name,
                $clinic_phone,
                $clinic_address,
                (int)$_SESSION['person_id'],
                ROLE_ADMIN
            );
            flash_set('clinic_settings_success', 'مشخصات کلینیک ذخیره شد.');
            redirect(APP_BASE_URL . '/admin/clinic_settings.php');
        } catch (Exception $ex) {
            $ref = log_system_error('CLINIC_PROFILE_SAVE', $ex);
            $error_message = 'ذخیرهٔ مشخصات انجام نشد. کد پیگیری: ' . $ref;
        }
    }
}

$p2 = function_exists('phase2_ready') && phase2_ready($db);
$p3 = function_exists('phase3_ready') && phase3_ready($db);
$p4 = function_exists('phase4_ready') && phase4_ready($db);
$p42 = function_exists('phase4_2_ready') && phase4_2_ready($db);
$p5 = function_exists('phase5_ready') && phase5_ready($db);
$p52 = function_exists('phase5_2_ready') && phase5_2_ready($db);
$p53 = function_exists('phase5_3_ready') && phase5_3_ready($db);
$p54 = function_exists('phase5_4_ready') && phase5_4_ready($db);
$p55 = function_exists('phase5_5_ready') && phase5_5_ready($db);

$base = APP_BASE_URL;
$groups = array();

$groups[] = array(
    'title' => 'کاربران و دسترسی‌ها',
    'description' => 'ساخت حساب کارکنان و مدیریت نقش و وضعیت دسترسی.',
    'links' => array(
        array('label' => '➕ ایجاد کاربر', 'url' => $base . '/admin/user_create.php'),
        array('label' => '👥 فهرست کاربران و نقش‌ها', 'url' => $base . '/admin/users_list.php'),
    ),
);

$groups[] = array(
    'title' => 'پیامک و ارتباطات',
    'description' => 'مشاهده و ویرایش تنظیمات پیامک سامانه.',
    'links' => array(
        array('label' => '📨 تنظیمات پیامک', 'url' => $base . '/admin/sms_settings.php'),
    ),
);

if ($p2) {
    $groups[] = array(
        'title' => 'نوبت‌دهی و اتاق‌ها',
        'description' => 'مدیریت اتاق‌های قابل رزرو، تعرفه‌ها و تقویم نوبت‌ها.',
        'links' => array(
            array('label' => '🏠 اتاق‌ها', 'url' => $base . '/admin/rooms.php'),
            array('label' => '💰 تعرفه‌ها', 'url' => $base . '/admin/tariffs.php'),
            array('label' => '📅 تقویم نوبت‌ها', 'url' => $base . '/reception/appointments.php'),
        ),
    );
} else {
    $groups[] = array(
        'title' => 'نوبت‌دهی و اتاق‌ها',
        'description' => 'برای فعال‌شدن تنظیمات نوبت‌دهی، ارتقای مربوط را اجرا کنید.',
        'links' => array(
            array('label' => '🧩 ارتقا به فاز ۲', 'url' => $base . '/upgrade_phase2.php'),
        ),
    );
}

if ($p4) {
    $groups[] = array(
        'title' => 'فرم‌های مراجعان',
        'description' => 'مدیریت قالب‌های فرم و پاسخ‌های ثبت‌شده.',
        'links' => array(
            array('label' => '🧾 قالب‌های فرم', 'url' => $base . '/admin/forms.php'),
            array('label' => '📑 پاسخ‌های فرم', 'url' => $base . '/admin/form_responses.php'),
        ),
    );
} elseif ($p3) {
    $groups[] = array(
        'title' => 'فرم‌های مراجعان',
        'description' => 'برای فعال‌شدن مدیریت فرم‌ها، ارتقای فاز ۴ را اجرا کنید.',
        'links' => array(
            array('label' => '🧩 ارتقا به فاز ۴', 'url' => $base . '/upgrade_phase4.php'),
        ),
    );
}

if ($p42) {
    $groups[] = array(
        'title' => 'پذیرش اینترنتی',
        'description' => 'رسیدگی به درخواست‌هایی که از فرم رزرو اینترنتی آمده‌اند.',
        'links' => array(
            array('label' => '📨 درخواست‌های نوبت', 'url' => $base . '/reception/booking_requests.php'),
        ),
    );
}

if ($p5) {
    $finance_links = array(
        array('label' => '🏢 مراکز مشاوره', 'url' => $base . '/admin/finance_centers.php'),
        array('label' => '🏦 حساب‌های بانکی', 'url' => $base . '/admin/finance_bank_accounts.php'),
        array('label' => '🚪 تنظیمات مالی اتاق‌ها', 'url' => $base . '/admin/finance_rooms.php'),
    );
    if ($p52) {
        $finance_links[] = array('label' => '💳 پرداخت‌های جلسات', 'url' => $base . '/reception/finance_payments.php');
    }
    if ($p53) {
        $finance_links[] = array('label' => '🤝 تسویه با مراکز', 'url' => $base . '/admin/finance_settlements.php');
    }
    if ($p54) {
        $finance_links[] = array('label' => '🧪 فروش تست‌ها', 'url' => $base . '/reception/finance_test_sales.php');
        $finance_links[] = array('label' => '📦 فروش پکیج‌ها', 'url' => $base . '/reception/finance_package_sales.php');
    }
    if ($p55) {
        $finance_links[] = array('label' => '📊 گزارش مالی', 'url' => $base . '/admin/finance_report.php');
    }
    $groups[] = array(
        'title' => 'تنظیمات و ابزارهای مالی',
        'description' => 'مدیریت مراکز، حساب‌ها و دیگر صفحه‌های مالی فعال.',
        'links' => $finance_links,
    );
}

$page_title = 'تنظیمات کلینیک';
$active_menu = 'clinic_settings';
require __DIR__ . '/../templates/header.php';
?>
<h1>⚙️ تنظیمات کلینیک</h1>
<?php echo flash_render('clinic_settings_success', 'success'); ?>
<?php if ($error_message !== '') { ?>
  <div class="alert alert-error"><?php echo e($error_message); ?></div>
<?php } ?>
<p class="form-hint">
  نام، شمارهٔ تماس و نشانی در سامانه ذخیره می‌شوند؛ نام کلینیک در سربرگ صفحات داخلی هم نمایش داده خواهد شد.
  سایر دکمه‌ها، صفحهٔ اصلی همان تنظیم را باز می‌کنند. هیچ یادداشت یا محتوای درمانی نمایش داده نمی‌شود.
</p>

<div class="card">
  <div class="card-header">مشخصات اصلی کلینیک</div>
  <div class="card-body">
    <form method="post" action="clinic_settings.php" data-guard>
      <?php echo csrf_field(); ?>
      <div class="form-row">
        <label for="clinic_name">نام کلینیک <span class="required-star">*</span></label>
        <input type="text" id="clinic_name" name="clinic_name" maxlength="150" required
               value="<?php echo e($settings['clinic_name']); ?>">
      </div>
      <div class="form-row">
        <label for="clinic_phone">شمارهٔ تماس</label>
        <input type="text" id="clinic_phone" name="clinic_phone" maxlength="30" dir="ltr"
               value="<?php echo e($settings['clinic_phone']); ?>" placeholder="اختیاری">
      </div>
      <div class="form-row">
        <label for="clinic_address">نشانی کلینیک</label>
        <textarea id="clinic_address" name="clinic_address" rows="3" maxlength="500"
                  placeholder="اختیاری"><?php echo e($settings['clinic_address']); ?></textarea>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">💾 ذخیرهٔ مشخصات</button>
      </div>
      <?php if (!empty($settings['updated_at'])) { ?>
        <p class="form-hint">آخرین به‌روزرسانی: <?php echo e(jalali_display($settings['updated_at'])); ?></p>
      <?php } ?>
    </form>
  </div>
</div>

<?php foreach ($groups as $group) { ?>
  <div class="card">
    <div class="card-header"><?php echo e($group['title']); ?></div>
    <div class="card-body">
      <p><?php echo e($group['description']); ?></p>
      <div class="action-panel">
        <?php foreach ($group['links'] as $link) { ?>
          <a class="btn btn-secondary" href="<?php echo e($link['url']); ?>">
            <?php echo e($link['label']); ?>
          </a>
        <?php } ?>
      </div>
    </div>
  </div>
<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
