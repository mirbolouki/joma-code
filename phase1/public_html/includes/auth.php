<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — احراز هویت، نقش‌ها و کنترل نشست
 * ═══════════════════════════════════════════════════════════════════ */

define('ROLE_ADMIN', 'admin');
define('ROLE_SECRETARY', 'secretary');
define('ROLE_THERAPIST', 'therapist');
define('ROLE_PSYCHOMETRIST', 'psychometrist');
define('ROLE_PATIENT', 'patient');

$GLOBALS['VALID_ROLE_CODES'] = array(
    ROLE_ADMIN, ROLE_SECRETARY, ROLE_THERAPIST, ROLE_PSYCHOMETRIST, ROLE_PATIENT
);

/* ADR-004: مدیر، اختیارات اداری منشی را به ارث می‌برد.
   این ارث‌بری هرگز به داده‌های بالینی تسری پیدا نمی‌کند. */
$GLOBALS['ROLE_INHERITANCE'] = array(
    ROLE_ADMIN => array(ROLE_ADMIN, ROLE_SECRETARY),
);

/** آیا نقش فعال، نقش موردنیاز را پوشش می‌دهد؟ */
function current_role_satisfies($required_role)
{
    if (!isset($_SESSION['active_role_code'])) {
        return false;
    }
    $active = $_SESSION['active_role_code'];
    if ($active === $required_role) {
        return true;
    }
    if (isset($GLOBALS['ROLE_INHERITANCE'][$active])
        && in_array($required_role, $GLOBALS['ROLE_INHERITANCE'][$active], true)) {
        return true;
    }
    return false;
}

function require_login()
{
    if (!isset($_SESSION['person_id'])) {
        redirect(APP_BASE_URL . '/login.php');
    }
}

/**
 * نگهبان کامل هر صفحهٔ محافظت‌شده:
 *   • ورود انجام شده باشد
 *   • نشست منقضی نشده باشد (۳۰ دقیقه بی‌فعالیتی)
 *   • حساب هنوز فعال باشد (ADR-013: غیرفعال‌سازی آنی)
 *   • نقش‌های فعال از دیتابیس بازخوانی شوند (نه صرفاً از نشست)
 *   • اگر رمز اجباری تغییر نکرده، به همان صفحه هدایت شود
 */
function auth_require_active_session($db)
{
    require_login();

    /* ۱) خروج خودکار پس از بی‌فعالیتی */
    $timeout = defined('SESSION_IDLE_TIMEOUT') ? (int)SESSION_IDLE_TIMEOUT : 1800;
    if (isset($_SESSION['last_activity_at']) && (time() - (int)$_SESSION['last_activity_at']) > $timeout) {
        auth_logout_session();
        flash_set('login_error', 'به دلیل بی‌فعالیتی طولانی، از سامانه خارج شدید. لطفاً دوباره وارد شوید.');
        redirect(APP_BASE_URL . '/login.php');
    }
    $_SESSION['last_activity_at'] = time();

    /* ۲) وضعیت حساب در هر درخواست */
    $account = db_select_one(
        $db,
        "SELECT a.id AS account_id, a.status, a.must_change_password, p.status AS person_status
           FROM accounts a
           INNER JOIN persons p ON p.id = a.person_id
          WHERE a.person_id = ? LIMIT 1",
        'i',
        array($_SESSION['person_id'])
    );

    if (!$account || $account['status'] !== 'ACTIVE' || $account['person_status'] !== 'ACTIVE') {
        auth_logout_session();
        flash_set('login_error', 'حساب کاربری شما غیرفعال شده است. برای پیگیری با مدیر کلینیک تماس بگیرید.');
        redirect(APP_BASE_URL . '/login.php');
    }

    /* ۳) بازخوانی نقش‌های فعال از دیتابیس */
    $roles = role_assignments_fetch_active($db, $_SESSION['person_id']);
    $role_codes = array();
    foreach ($roles as $r) {
        $role_codes[] = $r['role_code'];
    }
    if (count($role_codes) === 0) {
        auth_logout_session();
        flash_set('login_error', 'هیچ نقش فعالی برای حساب شما تعریف نشده است.');
        redirect(APP_BASE_URL . '/login.php');
    }
    $_SESSION['available_roles'] = $role_codes;

    if (isset($_SESSION['active_role_code'])
        && !in_array($_SESSION['active_role_code'], $role_codes, true)) {
        unset($_SESSION['active_role_code']);
    }

    /* ۴) تغییر اجباری رمز در نخستین ورود */
    $script = basename($_SERVER['SCRIPT_NAME']);
    if ((int)$account['must_change_password'] === 1 && $script !== 'change_password_forced.php') {
        redirect(APP_BASE_URL . '/change_password_forced.php');
    }

    /* ۵) انتخاب نقش برای کاربران چندنقشی */
    if (!isset($_SESSION['active_role_code'])
        && $script !== 'role_select.php' && $script !== 'change_password_forced.php') {
        if (count($role_codes) === 1) {
            $_SESSION['active_role_code'] = $role_codes[0];
        } else {
            redirect(APP_BASE_URL . '/role_select.php');
        }
    }

    return $account;
}

/** کنترل دسترسی نقشی؛ در صورت نداشتن مجوز، صفحهٔ ۴۰۳ نمایش داده می‌شود */
function require_role($allowed_roles)
{
    require_login();
    foreach ($allowed_roles as $role) {
        if (current_role_satisfies($role)) {
            return true;
        }
    }
    http_response_code(403);
    require __DIR__ . '/../templates/403.php';
    exit;
}

/** پایان دادن کامل به نشست */
function auth_logout_session()
{
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']
        );
    }
    @session_destroy();
    @session_start();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/** شمارش تلاش‌های ناموفق ورود در بازهٔ زمانی مشخص (دقیقه) */
function login_attempt_failed_count($db, $login_identifier, $minutes)
{
    $since = gmdate('Y-m-d H:i:s', time() - ((int)$minutes * 60));
    $row = db_select_one(
        $db,
        "SELECT COUNT(*) AS cnt FROM audit_log
          WHERE action_code = 'LOGIN_FAILED' AND metadata_json LIKE ? AND created_at > ?",
        'ss',
        array('%"identifier":"' . $login_identifier . '"%', $since)
    );
    return $row ? (int)$row['cnt'] : 0;
}

/** ورود پرسنل با نام کاربری و رمز عبور */
function staff_login($db, $login_identifier, $password)
{
    if (login_attempt_failed_count($db, $login_identifier, 15) >= 5) {
        return array('ok' => false, 'message' => 'تعداد تلاش‌های ناموفق زیاد است؛ لطفاً ۱۵ دقیقه صبر کنید.');
    }

    $account = db_select_one(
        $db,
        "SELECT id AS account_id, person_id, password_hash, status
           FROM accounts WHERE login_identifier = ? AND account_type = 'STAFF' LIMIT 1",
        's',
        array($login_identifier)
    );

    $generic_fail = array('ok' => false, 'message' => 'نام کاربری یا رمز عبور نادرست است.');

    if (!$account || !password_verify($password, $account['password_hash'])) {
        audit_log_write($db, null, null, 'LOGIN_FAILED', 'account', null,
            array('identifier' => $login_identifier));
        return $generic_fail;
    }
    if ($account['status'] !== 'ACTIVE') {
        return array('ok' => false, 'message' => 'حساب کاربری شما غیرفعال شده است.');
    }

    $person = person_find_by_id($db, (int)$account['person_id']);
    if (!$person || $person['status'] !== 'ACTIVE') {
        return array('ok' => false, 'message' => 'حساب کاربری شما غیرفعال شده است.');
    }

    $roles = role_assignments_fetch_active($db, $account['person_id']);
    $role_codes = array();
    foreach ($roles as $r) {
        $role_codes[] = $r['role_code'];
    }
    if (count($role_codes) === 0) {
        return array('ok' => false, 'message' => 'هیچ نقشی برای این حساب تعریف نشده است.');
    }

    session_regenerate_id(true);
    $_SESSION['person_id'] = (int)$account['person_id'];
    $_SESSION['account_id'] = (int)$account['account_id'];
    $_SESSION['available_roles'] = $role_codes;
    $_SESSION['display_name'] = $person['first_name'] . ' ' . $person['last_name'];
    $_SESSION['last_activity_at'] = time();

    db_execute($db, "UPDATE accounts SET last_login_at = ? WHERE id = ?",
        'si', array(now_dt(), $account['account_id']));

    audit_log_write($db, (int)$account['person_id'], null, 'LOGIN_SUCCESS', 'account',
        (int)$account['account_id'], null);

    if (count($role_codes) === 1) {
        $_SESSION['active_role_code'] = $role_codes[0];
    }

    return array('ok' => true, 'needs_role_selection' => count($role_codes) > 1);
}
