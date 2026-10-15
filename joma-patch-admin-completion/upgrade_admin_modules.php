<?php
/* ═════════════════════════════════════════════════════════════════════
 *  ارتقای افزایشی: پرونده‌های مدیریتی + مشخصات کلینیک
 *
 *  ایمن برای اجرای مجدد: فقط دو جدول تازه می‌سازد و هیچ جدول یا داده‌ای
 *  را حذف/بازنویسی نمی‌کند. اجرا فقط با مدیر واردشده، POST و CSRF ممکن است.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

$success_message = flash_get('admin_modules_upgrade_success');
$error_message = '';
$error_ref = '';
$base_tables = array('persons', 'clinical_cases', 'migrations', 'audit_log');
$missing_tables = array();
foreach ($base_tables as $table_name) {
    if (!clinic_admin_table_exists($db, $table_name)) {
        $missing_tables[] = $table_name;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (count($missing_tables) > 0) {
        $error_message = 'پیش‌نیازهای سامانه کامل نیستند. لطفاً مطمئن شوید فازهای اصلی و پروندهٔ بالینی قبلاً نصب شده‌اند.';
    } else {
        try {
            $schema_path = __DIR__ . '/database/admin_modules_schema.sql';
            $schema = @file_get_contents($schema_path);
            if ($schema === false) {
                throw new Exception('فایل schema برای ارتقا خوانده نشد.');
            }
            $schema = preg_replace('/^\s*--.*$/m', '', $schema);
            $statements = array_filter(array_map('trim', explode(';', $schema)));
            if (count($statements) !== 2) {
                throw new Exception('ساختار فایل schema برای ارتقا معتبر نیست.');
            }
            foreach ($statements as $statement) {
                mysqli_query($db, $statement);
            }

            /* یک ردیف اولیه می‌سازیم؛ اطلاعاتی که وارد نشده، خالی می‌ماند.
               اجرای دوباره هرگز نام/تلفن/نشانی ذخیره‌شده را پاک نمی‌کند. */
            db_execute(
                $db,
                "INSERT INTO clinic_settings
                    (id, clinic_name, clinic_phone, clinic_address,
                     updated_by_person_id, updated_at)
                 VALUES (1, 'کلینیک جوما', '', '', NULL, ?)
                 ON DUPLICATE KEY UPDATE id = VALUES(id)",
                's',
                array(now_dt())
            );

            db_execute(
                $db,
                "INSERT INTO migrations (version, applied_at) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE version = VALUES(version)",
                'ss',
                array(CLINIC_ADMIN_MODULES_MIGRATION_VERSION, now_dt())
            );
            if (!clinic_admin_modules_ready($db)) {
                throw new Exception('راستی‌آزمایی جدول‌ها، ردیف تنظیمات یا نسخهٔ مهاجرت ناموفق بود.');
            }
            audit_log_write(
                $db,
                (int)$_SESSION['person_id'],
                ROLE_ADMIN,
                'ADMIN_MODULES_UPGRADED',
                'migration',
                null,
                array('version' => CLINIC_ADMIN_MODULES_MIGRATION_VERSION)
            );
            flash_set('admin_modules_upgrade_success', 'ارتقا با موفقیت انجام شد؛ «پرونده‌ها» و «تنظیمات کلینیک» فعال شدند.');
            redirect(APP_BASE_URL . '/upgrade_admin_modules.php');
        } catch (Exception $ex) {
            $error_ref = log_system_error('ADMIN_MODULES_UPGRADE', $ex);
            $error_message = 'ارتقا کامل نشد. هیچ فایل یا جدول موجودی حذف نشده است. کد پیگیری را برای پشتیبانی بفرستید.';
        }
    }
}

$ready = clinic_admin_modules_ready($db);
$page_title = 'فعال‌سازی پرونده‌ها و تنظیمات کلینیک';
$active_menu = $ready ? 'clinic_settings' : 'admin_modules_upgrade';
require __DIR__ . '/templates/header.php';
?>
<h1>🧩 فعال‌سازی پرونده‌ها و تنظیمات کلینیک</h1>
<?php if ($success_message !== null) { ?>
  <div class="alert alert-success">✓ <?php echo e($success_message); ?></div>
<?php } ?>
<?php if ($error_message !== '') { ?>
  <div class="alert alert-error">
    ✗ <?php echo e($error_message); ?>
    <?php if ($error_ref !== '') { ?><br>کد پیگیری: <span class="mono"><?php echo e($error_ref); ?></span><?php } ?>
  </div>
<?php } ?>

<div class="card">
  <div class="card-header">وضعیت نصب</div>
  <div class="card-body">
    <?php if ($ready) { ?>
      <p class="status status-active">بخش‌ها فعال هستند.</p>
      <p>نیازی به اجرای دوبارهٔ ارتقا نیست.</p>
      <a class="btn btn-primary" href="<?php echo e(APP_BASE_URL); ?>/admin/cases.php">رفتن به پرونده‌ها</a>
      <a class="btn btn-secondary" href="<?php echo e(APP_BASE_URL); ?>/admin/clinic_settings.php">رفتن به تنظیمات کلینیک</a>
    <?php } elseif (count($missing_tables) > 0) { ?>
      <div class="alert alert-error">
        پیش‌نیازهای لازم در پایگاه داده پیدا نشدند؛ ارتقای قبلی را دوباره اجرا نکنید.
        لطفاً این پیام را برای پشتیبانی بفرستید.
      </div>
    <?php } else { ?>
      <p>
        این ارتقا فقط دو جدول تازه برای تاریخچهٔ بایگانی/بازگشایی و مشخصات کلینیک می‌سازد.
        هیچ دادهٔ فعلی حذف یا بازنویسی نمی‌شود.
      </p>
      <ul>
        <li>مدیر فقط اطلاعات اداری پرونده را می‌بیند؛ یادداشت‌های درمانی، پاسخ‌های بالینی و اسناد آزمون محرمانه می‌مانند.</li>
        <li>تغییر وضعیت پرونده با علت ثبت می‌شود و در تاریخچه باقی می‌ماند.</li>
        <li>نام اولیهٔ کلینیک «کلینیک جوما» است؛ تلفن و نشانی تا زمان واردکردن شما خالی می‌مانند.</li>
      </ul>
      <p class="form-hint">پیش از اجرا، یک نسخهٔ پشتیبان از پایگاه داده داشته باشید.</p>
      <form method="post" action="upgrade_admin_modules.php" data-guard>
        <?php echo csrf_field(); ?>
        <button type="submit" class="btn btn-primary"
                data-confirm="آیا آماده‌اید فقط جدول‌های جدید این ارتقا ساخته شوند؟">
          اجرای ارتقا و فعال‌سازی دو بخش
        </button>
      </form>
    <?php } ?>
  </div>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
