<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — درِ ورودی سامانه
 *  کاربر را بسته به وضعیتش به صفحهٔ درست می‌فرستد.
 * ═══════════════════════════════════════════════════════════════════ */

if (!file_exists(__DIR__ . '/includes/config.php')) {
    if (file_exists(__DIR__ . '/install.php')) {
        header('Location: install.php');
        exit;
    }
    die('سامانه هنوز نصب نشده است.');
}

require_once __DIR__ . '/includes/bootstrap.php';

if (!isset($_SESSION['person_id'])) {
    redirect(APP_BASE_URL . '/login.php');
}

auth_require_active_session($GLOBALS['db']);

redirect(dashboard_url_for_role($_SESSION['active_role_code']));
