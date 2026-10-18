<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — منوی کناری (در چیدمان راست‌به‌چپ، سمت راست صفحه)
 *  آیتم‌ها بر اساس نقش فعال ساخته می‌شوند.
 * ═══════════════════════════════════════════════════════════════════ */
$base = defined('APP_BASE_URL') ? APP_BASE_URL : '';
$role = isset($_SESSION['active_role_code']) ? $_SESSION['active_role_code'] : '';
if (!isset($active_menu)) { $active_menu = ''; }

$menu = array();
$p2 = (isset($GLOBALS['db']) && function_exists('phase2_ready')) ? phase2_ready($GLOBALS['db']) : false;
$p3 = (isset($GLOBALS['db']) && function_exists('phase3_ready')) ? phase3_ready($GLOBALS['db']) : false;
$p4 = (isset($GLOBALS['db']) && function_exists('phase4_ready')) ? phase4_ready($GLOBALS['db']) : false;
$p42 = (isset($GLOBALS['db']) && function_exists('phase4_2_ready')) ? phase4_2_ready($GLOBALS['db']) : false;
$p5 = (isset($GLOBALS['db']) && function_exists('phase5_ready')) ? phase5_ready($GLOBALS['db']) : false;
$p52 = (isset($GLOBALS['db']) && function_exists('phase5_2_ready')) ? phase5_2_ready($GLOBALS['db']) : false;
$p53 = (isset($GLOBALS['db']) && function_exists('phase5_3_ready')) ? phase5_3_ready($GLOBALS['db']) : false;
$p54 = (isset($GLOBALS['db']) && function_exists('phase5_4_ready')) ? phase5_4_ready($GLOBALS['db']) : false;
$p55 = (isset($GLOBALS['db']) && function_exists('phase5_5_ready')) ? phase5_5_ready($GLOBALS['db']) : false;
$patient_requests_ready = (isset($GLOBALS['db']) && function_exists('patient_appointment_requests_ready'))
    ? patient_appointment_requests_ready($GLOBALS['db']) : false;
$patient_messages_ready = (isset($GLOBALS['db']) && function_exists('patient_messages_ready'))
    ? patient_messages_ready($GLOBALS['db']) : false;
$admin_modules_ready = ($role === ROLE_ADMIN && isset($GLOBALS['db'])
    && function_exists('clinic_admin_modules_ready'))
    ? clinic_admin_modules_ready($GLOBALS['db']) : false;
$patient_message_unread = 0;
if ($role === ROLE_PATIENT && $patient_messages_ready
    && function_exists('patient_messages_unread_count')) {
    $patient_message_unread = patient_messages_unread_count(
        $GLOBALS['db'], (int)$_SESSION['person_id']);
}
$patient_messages_label = '✉️ پیام‌های من'
    . ($patient_message_unread > 0
        ? ' (' . to_persian_digits($patient_message_unread) . ')' : '');

/* شمار درخواست‌های تازهٔ نوبت اینترنتی — روی خودِ آیتم منو دیده می‌شود،
   تا صفی که هیچ‌کس سر نمی‌زند بی‌صدا رشد نکند. */
$booking_new = 0;
if ($p42 && function_exists('booking_status_counts')
    && ($role === ROLE_SECRETARY || $role === ROLE_ADMIN)) {
    $bc = booking_status_counts($GLOBALS['db']);
    $booking_new = isset($bc['NEW']) ? (int)$bc['NEW'] : 0;
}
$booking_label = '📨 درخواست‌های نوبت'
    . ($booking_new > 0 ? ' (' . to_persian_digits($booking_new) . ')' : '');

if ($role === ROLE_ADMIN) {
    $menu = array(
        array('label' => '📊 داشبورد',        'url' => $base . '/admin/index.php',        'key' => 'admin_home'),
        array('label' => '➕ ایجاد کاربر',     'url' => $base . '/admin/user_create.php',  'key' => 'user_create'),
        array('label' => '👥 فهرست کاربران',   'url' => $base . '/admin/users_list.php',   'key' => 'users_list'),
        array('label' => '📋 پذیرش‌ها',        'url' => $base . '/reception/index.php',    'key' => 'reception_home'),
        array('label' => '👥 مراجعان',         'url' => $base . '/reception/patients.php', 'key' => 'patients'),
        array('label' => '📨 تنظیمات پیامک',   'url' => $base . '/admin/sms_settings.php', 'key' => 'sms_settings'),
    );
    if ($p2) {
        $menu[] = array('label' => '📅 تقویم نوبت‌ها', 'url' => $base . '/reception/appointments.php', 'key' => 'appointments');
        $menu[] = array('label' => '🏠 اتاق‌ها',       'url' => $base . '/admin/rooms.php',            'key' => 'rooms');
        $menu[] = array('label' => '💰 تعرفه‌ها',      'url' => $base . '/admin/tariffs.php',          'key' => 'tariffs');
    } else {
        $menu[] = array('label' => '🧩 ارتقا به فاز ۲', 'url' => $base . '/upgrade_phase2.php', 'key' => 'upgrade2');
    }
    if ($p4) {
        $menu[] = array('label' => '🧾 قالب‌های فرم', 'url' => $base . '/admin/forms.php',          'key' => 'admin_forms');
        $menu[] = array('label' => '📑 پاسخ‌های فرم', 'url' => $base . '/admin/form_responses.php', 'key' => 'admin_form_responses');
    } elseif ($p3) {
        $menu[] = array('label' => '🧩 ارتقا به فاز ۴', 'url' => $base . '/upgrade_phase4.php', 'key' => 'upgrade4');
    }
    if ($p42) {
        $menu[] = array('label' => $booking_label, 'url' => $base . '/reception/booking_requests.php', 'key' => 'booking_requests');
    }
    if ($p5) {
        $menu[] = array('label' => '💰 مراکز مشاوره',         'url' => $base . '/admin/finance_centers.php',       'key' => 'finance_centers');
        $menu[] = array('label' => '🏦 حساب‌های بانکی',      'url' => $base . '/admin/finance_bank_accounts.php', 'key' => 'finance_bank_accounts');
        $menu[] = array('label' => '🚪 تنظیمات مالی اتاق‌ها', 'url' => $base . '/admin/finance_rooms.php',         'key' => 'finance_rooms');
    }
    if ($p52) {
        $menu[] = array('label' => '💳 پرداختِ جلسات', 'url' => $base . '/reception/finance_payments.php', 'key' => 'finance_payments');
    }
    if ($p53) {
        $menu[] = array('label' => '🤝 تسویه با مراکز', 'url' => $base . '/admin/finance_settlements.php', 'key' => 'finance_settlements');
    }
    if ($p54) {
        $menu[] = array('label' => '🧪 فروشِ تست‌ها', 'url' => $base . '/reception/finance_test_sales.php', 'key' => 'finance_test_sales');
        $menu[] = array('label' => '📦 فروشِ پکیج‌ها', 'url' => $base . '/reception/finance_package_sales.php', 'key' => 'finance_package_sales');
    }
    if ($p55) {
        $menu[] = array('label' => '📊 گزارشِ مالی', 'url' => $base . '/admin/finance_report.php', 'key' => 'finance_report');
    }
    if ($admin_modules_ready) {
        $menu[] = array('label' => '📂 پرونده‌ها',      'url' => $base . '/admin/cases.php', 'key' => 'admin_cases');
        $menu[] = array('label' => '⚙️ تنظیمات کلینیک', 'url' => $base . '/admin/clinic_settings.php', 'key' => 'clinic_settings');
    } else {
        $menu[] = array('label' => '🧩 فعال‌سازی پرونده‌ها و تنظیمات',
                        'url' => $base . '/upgrade_admin_modules.php', 'key' => 'admin_modules_upgrade');
    }
    if ($patient_requests_ready) {
        $menu[] = array('label' => '📥 درخواست‌های مراجعان',
                        'url' => $base . '/reception/patient_appointment_requests.php',
                        'key' => 'patient_appointment_requests');
    } elseif ($p2 && $p4 && $admin_modules_ready) {
        $menu[] = array('label' => '🧩 فعال‌سازی درخواست‌های نوبت مراجع',
                        'url' => $base . '/upgrade_patient_appointment_requests.php',
                        'key' => 'patient_requests_upgrade');
    }
    if (!$patient_messages_ready && $p2 && $p4 && $admin_modules_ready) {
        $menu[] = array('label' => '🧩 فعال‌سازی پیام‌های امن مراجع',
                        'url' => $base . '/upgrade_patient_messages.php',
                        'key' => 'patient_messages_upgrade');
    }
} elseif ($role === ROLE_SECRETARY) {
    $menu = array(
        array('label' => '📋 داشبورد',          'url' => $base . '/reception/index.php',        'key' => 'reception_home'),
        array('label' => '➕ ثبت پذیرش جدید',    'url' => $base . '/reception/admission_new.php', 'key' => 'admission_new'),
        array('label' => '👥 مراجعان',           'url' => $base . '/reception/patients.php',      'key' => 'patients'),
    );
    if ($p42) {
        $menu[] = array('label' => $booking_label, 'url' => $base . '/reception/booking_requests.php', 'key' => 'booking_requests');
    }
    if ($patient_requests_ready) {
        $menu[] = array('label' => '📥 درخواست‌های مراجعان',
                        'url' => $base . '/reception/patient_appointment_requests.php',
                        'key' => 'patient_appointment_requests');
    }
    if ($p2) {
        $menu[] = array('label' => '📅 تقویم نوبت‌ها', 'url' => $base . '/reception/appointments.php',    'key' => 'appointments');
        $menu[] = array('label' => '🗓️ ثبت نوبت',      'url' => $base . '/reception/appointment_new.php', 'key' => 'appointment_new');
    } else {
        $menu[] = array('label' => '📅 تقویم نوبت‌ها', 'url' => '', 'key' => '', 'soon' => true);
    }
    if ($p52) {
        $menu[] = array('label' => '💳 پرداختِ جلسات', 'url' => $base . '/reception/finance_payments.php', 'key' => 'finance_payments');
    }
    if ($p54) {
        $menu[] = array('label' => '🧪 فروشِ تست‌ها', 'url' => $base . '/reception/finance_test_sales.php', 'key' => 'finance_test_sales');
        $menu[] = array('label' => '📦 فروشِ پکیج‌ها', 'url' => $base . '/reception/finance_package_sales.php', 'key' => 'finance_package_sales');
    }
} elseif ($role === ROLE_THERAPIST) {
    $menu = array(
        array('label' => '🩺 کارتابل',         'url' => $base . '/therapist/index.php', 'key' => 'therapist_home'),
        array('label' => '📂 پرونده‌های من',    'url' => $base . '/therapist/index.php#cases', 'key' => ''),
        array('label' => '👥 مراجعان من',      'url' => $base . '/therapist/patients.php', 'key' => 'therapist_patients'),
    );
    if ($p3) {
        $menu[] = array('label' => '🗒️ آخرین یادداشت‌های من',
                        'url' => $base . '/therapist/notes_recent.php', 'key' => 'notes_recent');
    }
    if ($p4) {
        $menu[] = array('label' => '🧾 فرم‌های من', 'url' => $base . '/therapist/forms.php', 'key' => 'therapist_forms');
    }
    if ($p2) {
        $menu[] = array('label' => '📅 تقویم من',    'url' => $base . '/therapist/calendar.php',     'key' => 'therapist_calendar');
        $menu[] = array('label' => '🏖️ عدم حضور',    'url' => $base . '/therapist/absences.php',     'key' => 'therapist_absences');
        $menu[] = array('label' => '💰 تعرفهٔ من',   'url' => $base . '/therapist/tariffs_view.php', 'key' => 'therapist_tariffs');
    } else {
        $menu[] = array('label' => '📅 تقویم من', 'url' => '', 'key' => '', 'soon' => true);
    }
} elseif ($role === ROLE_PATIENT) {
    $menu = array(
        array('label' => '🏠 صفحهٔ من', 'url' => $base . '/patient/index.php', 'key' => 'patient_home'),
    );
    if ($p2) {
        $menu[] = array('label' => '📅 نوبت‌های من', 'url' => $base . '/patient/appointments.php', 'key' => 'patient_appointments');
    }
    if ($patient_requests_ready) {
        $menu[] = array('label' => '📨 درخواست‌های من', 'url' => $base . '/patient/requests.php', 'key' => 'patient_requests');
    }
    if ($patient_messages_ready) {
        $menu[] = array('label' => $patient_messages_label,
                        'url' => $base . '/patient/messages.php', 'key' => 'patient_messages');
    }
    if ($p52 || $p54) {
        $menu[] = array('label' => '💳 اطلاعات مالی من', 'url' => $base . '/patient/finances.php', 'key' => 'patient_finances');
    }
    $menu[] = array('label' => '🧾 فرم‌های من', 'url' => $base . '/patient/forms.php', 'key' => 'patient_forms');
} elseif ($role === ROLE_PSYCHOMETRIST) {
    $menu = array(
        array('label' => '🧪 بخش روان‌سنجی', 'url' => '', 'key' => '', 'soon' => true),
    );
}
/* دسته‌بندی منو؛ لینک‌ها و کنترل دسترسیِ قبلی دست‌نخورده می‌مانند. */
$sidebar_direct_keys = array();
$sidebar_group_defs = array();
$sidebar_key_to_group = array();
$sidebar_direct_all = false;

if ($role === ROLE_ADMIN) {
    $sidebar_direct_keys = array('admin_home');
    $sidebar_group_defs = array(
        'people' => array('title' => 'مراجعان و پذیرش', 'icon' => '👥'),
        'schedule' => array('title' => 'نوبت و منابع', 'icon' => '📅'),
        'clinical' => array('title' => 'پرونده و فرم‌ها', 'icon' => '🩺'),
        'finance' => array('title' => 'امور مالی', 'icon' => '💰'),
        'system' => array('title' => 'مدیریت سامانه', 'icon' => '⚙️'),
        'setup' => array('title' => 'راه‌اندازی امکانات', 'icon' => '🧩'),
    );
    $sidebar_key_to_group = array(
        'reception_home' => 'people', 'patients' => 'people',
        'booking_requests' => 'people', 'patient_appointment_requests' => 'people',
        'appointments' => 'schedule', 'rooms' => 'schedule', 'tariffs' => 'schedule',
        'admin_cases' => 'clinical', 'admin_forms' => 'clinical',
        'admin_form_responses' => 'clinical',
        'finance_centers' => 'finance', 'finance_bank_accounts' => 'finance',
        'finance_rooms' => 'finance', 'finance_payments' => 'finance',
        'finance_settlements' => 'finance', 'finance_test_sales' => 'finance',
        'finance_package_sales' => 'finance', 'finance_report' => 'finance',
        'user_create' => 'system', 'users_list' => 'system',
        'clinic_settings' => 'system', 'sms_settings' => 'system',
        'upgrade2' => 'setup', 'upgrade4' => 'setup',
        'admin_modules_upgrade' => 'setup', 'patient_requests_upgrade' => 'setup',
        'patient_messages_upgrade' => 'setup',
    );
} elseif ($role === ROLE_SECRETARY) {
    $sidebar_direct_keys = array('reception_home');
    $sidebar_group_defs = array(
        'people' => array('title' => 'مراجعان و پذیرش', 'icon' => '👥'),
        'schedule' => array('title' => 'نوبت‌ها', 'icon' => '📅'),
        'finance' => array('title' => 'امور مالی', 'icon' => '💰'),
    );
    $sidebar_key_to_group = array(
        'admission_new' => 'people', 'patients' => 'people',
        'booking_requests' => 'people', 'patient_appointment_requests' => 'people',
        'appointments' => 'schedule', 'appointment_new' => 'schedule',
        'finance_payments' => 'finance', 'finance_test_sales' => 'finance',
        'finance_package_sales' => 'finance',
    );
} elseif ($role === ROLE_THERAPIST) {
    $sidebar_direct_keys = array('therapist_home');
    $sidebar_group_defs = array(
        'cases' => array('title' => 'پرونده و مراجعان', 'icon' => '📂'),
        'clinical' => array('title' => 'کار درمانی و فرم‌ها', 'icon' => '🧾'),
        'schedule' => array('title' => 'برنامهٔ کاری', 'icon' => '🗓️'),
    );
    $sidebar_key_to_group = array(
        'therapist_patients' => 'cases',
        'notes_recent' => 'clinical', 'therapist_forms' => 'clinical',
        'therapist_calendar' => 'schedule', 'therapist_absences' => 'schedule',
        'therapist_tariffs' => 'schedule',
    );
} elseif ($role === ROLE_PATIENT) {
    $sidebar_direct_keys = array('patient_home');
    $sidebar_group_defs = array(
        'services' => array('title' => 'خدمات من', 'icon' => '✨'),
    );
    $sidebar_key_to_group = array(
        'patient_appointments' => 'services', 'patient_requests' => 'services',
        'patient_messages' => 'services', 'patient_finances' => 'services',
        'patient_forms' => 'services',
    );
} elseif ($role === ROLE_PSYCHOMETRIST) {
    $sidebar_direct_all = true;
}

$sidebar_direct_items = array();
$sidebar_group_items = array();
foreach ($sidebar_group_defs as $group_key => $group_definition) {
    $sidebar_group_items[$group_key] = array();
}
foreach ($menu as $item) {
    $item_key = isset($item['key']) ? (string)$item['key'] : '';
    if ($sidebar_direct_all || in_array($item_key, $sidebar_direct_keys, true)) {
        $sidebar_direct_items[] = $item;
        continue;
    }

    if (isset($sidebar_key_to_group[$item_key])) {
        $group_key = $sidebar_key_to_group[$item_key];
    } elseif ($role === ROLE_THERAPIST && $item_key === '' && empty($item['soon'])) {
        /* پیوندِ پرونده‌های من به بخش پرونده تعلق دارد. */
        $group_key = 'cases';
    } elseif (!empty($item['soon'])
        && ($role === ROLE_SECRETARY || $role === ROLE_THERAPIST)) {
        /* آیتم‌های «به‌زودی» نیز کنار دستهٔ مرتبط باقی می‌مانند. */
        $group_key = 'schedule';
    } else {
        $group_key = 'other';
    }

    if (!isset($sidebar_group_defs[$group_key])) {
        $sidebar_group_defs[$group_key] = array('title' => 'سایر امکانات', 'icon' => '•');
        $sidebar_group_items[$group_key] = array();
    }
    $sidebar_group_items[$group_key][] = $item;
}

$sidebar_render_item = function ($item, $active_menu) {
    if (!empty($item['soon'])) {
        echo '<li><a href="#" class="disabled-link" aria-disabled="true">'
            . e($item['label']) . ' <span class="badge badge-muted">فاز بعد</span></a></li>';
        return;
    }

    $item_key = isset($item['key']) ? (string)$item['key'] : '';
    $is_active = ($item_key !== '' && $item_key === $active_menu);
    echo '<li><a href="' . e($item['url']) . '"'
        . ($is_active ? ' class="active" aria-current="page"' : '') . '>'
        . e($item['label']) . '</a></li>';
};
?>
<style>
.sidebar .sidebar-nav-hint {
  margin: -4px 24px 12px;
  color: var(--color-muted);
  font-size: 11px;
  line-height: 1.7;
}
.sidebar .sidebar-direct-links,
.sidebar .sidebar-submenu {
  list-style: none;
  margin: 0;
  padding: 0;
}
.sidebar .sidebar-direct-links {
  margin-bottom: 8px;
}
.sidebar .sidebar-direct-links a {
  margin: 2px 12px;
  padding: 10px 14px;
  border-radius: 8px;
}
.sidebar .sidebar-categories {
  padding: 0 8px 8px;
}
.sidebar details.sidebar-category {
  margin: 2px 0;
  border-radius: 10px;
}
.sidebar details.sidebar-category[open] {
  background: rgba(0, 0, 0, 0.025);
}
.sidebar .sidebar-category > summary {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 44px;
  padding: 9px 14px;
  border-radius: 8px;
  color: var(--color-text);
  cursor: pointer;
  font-size: 13px;
  font-weight: 700;
  list-style: none;
  user-select: none;
  transition: background-color 0.15s ease, color 0.15s ease;
}
.sidebar .sidebar-category > summary::-webkit-details-marker {
  display: none;
}
.sidebar .sidebar-category > summary::marker {
  content: '';
}
.sidebar .sidebar-category > summary:hover,
.sidebar .sidebar-category > summary:focus-visible {
  background: var(--color-bg);
  color: var(--color-primary);
}
.sidebar .sidebar-category > summary:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: 2px;
}
.sidebar .sidebar-category[open] > summary {
  color: var(--color-primary);
}
.sidebar .sidebar-category-icon {
  display: inline-flex;
  width: 22px;
  justify-content: center;
  flex: 0 0 22px;
  font-size: 16px;
}
.sidebar .sidebar-category-title {
  flex: 1 1 auto;
}
.sidebar .sidebar-category-chevron {
  margin-right: auto;
  color: var(--color-muted);
  font-size: 17px;
  line-height: 1;
  transition: transform 0.15s ease;
}
.sidebar .sidebar-category[open] .sidebar-category-chevron {
  transform: rotate(180deg);
}
.sidebar .sidebar-submenu {
  padding: 0 0 7px;
}
.sidebar .sidebar-submenu a {
  min-height: 39px;
  margin: 2px 7px;
  padding: 8px 18px;
  border-right: 3px solid transparent;
  border-radius: 7px;
  font-size: 13px;
  line-height: 1.5;
}
.sidebar .sidebar-submenu a.active {
  background: var(--color-white);
  border-right-color: var(--color-primary);
  color: var(--color-primary);
  font-weight: 700;
}
.sidebar .sidebar-submenu a.disabled-link {
  cursor: not-allowed;
  opacity: 0.62;
}
@media (prefers-reduced-motion: reduce) {
  .sidebar .sidebar-category > summary,
  .sidebar .sidebar-category-chevron {
    transition: none;
  }
}
</style>
<nav class="sidebar" aria-label="منوی اصلی">
  <div class="sidebar-title">منوی <?php echo e(role_label($role)); ?></div>
  <div class="sidebar-nav-hint">برای دیدن زیرمنو، دسته را باز کنید</div>

  <?php if (count($sidebar_direct_items) > 0) { ?>
    <ul class="sidebar-direct-links">
      <?php foreach ($sidebar_direct_items as $item) { $sidebar_render_item($item, $active_menu); } ?>
    </ul>
  <?php } ?>

  <div class="sidebar-categories">
    <?php foreach ($sidebar_group_defs as $group_key => $group_definition) { ?>
      <?php if (empty($sidebar_group_items[$group_key])) { continue; } ?>
      <?php
        $group_is_active = false;
        foreach ($sidebar_group_items[$group_key] as $group_item) {
            $group_item_key = isset($group_item['key']) ? (string)$group_item['key'] : '';
            if ($group_item_key !== '' && $group_item_key === $active_menu) {
                $group_is_active = true;
                break;
            }
        }
        $submenu_id = 'sidebar-submenu-' . preg_replace('/[^a-z0-9_-]/i', '-', $group_key);
      ?>
      <details class="sidebar-category"<?php echo $group_is_active ? ' open' : ''; ?>>
        <summary aria-controls="<?php echo e($submenu_id); ?>">
          <span class="sidebar-category-icon" aria-hidden="true"><?php echo e($group_definition['icon']); ?></span>
          <span class="sidebar-category-title"><?php echo e($group_definition['title']); ?></span>
          <span class="sidebar-category-chevron" aria-hidden="true">⌄</span>
        </summary>
        <ul class="sidebar-submenu" id="<?php echo e($submenu_id); ?>">
          <?php foreach ($sidebar_group_items[$group_key] as $item) { $sidebar_render_item($item, $active_menu); } ?>
        </ul>
      </details>
    <?php } ?>
  </div>
</nav>
