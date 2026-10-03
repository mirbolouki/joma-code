<?php
/* جوما — خروج از سامانه */
require_once __DIR__ . '/includes/bootstrap.php';

if (isset($_SESSION['person_id'])) {
    audit_log_write(
        $GLOBALS['db'],
        (int)$_SESSION['person_id'],
        isset($_SESSION['active_role_code']) ? $_SESSION['active_role_code'] : null,
        'LOGOUT', 'account',
        isset($_SESSION['account_id']) ? (int)$_SESSION['account_id'] : null,
        null
    );
}

auth_logout_session();
flash_set('login_success', 'با موفقیت از سامانه خارج شدید.');
redirect(APP_BASE_URL . '/login.php');
