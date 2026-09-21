<?php
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strpos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') !== false)
    || (strpos($host, 'e2b.app') !== false);
$preview = (getenv('JOMA_PREVIEW') === '1') || (strpos($host, 'e2b.app') !== false);
$sessionDir = dirname(__FILE__) . '/../data/sessions';
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0775, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST)) {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $parsed = array();
        parse_str($raw, $parsed);
        if ($parsed) $_POST = $parsed;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_cookies', 1);
    ini_set('session.use_only_cookies', 0);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_path', '/');
    ini_set('session.save_path', $sessionDir);
    if ($preview || $https) {
        ini_set('session.cookie_secure', $https ? '1' : '0');
        ini_set('session.cookie_samesite', 'None');
    }
    $sid = '';
    $name = session_name();
    if (!empty($_COOKIE[$name])) {
        $sid = $_COOKIE[$name];
    } elseif (!empty($_POST['joma_sid'])) {
        $sid = $_POST['joma_sid'];
    } elseif (!empty($_GET['joma_sid'])) {
        $sid = $_GET['joma_sid'];
    }
    if ($sid !== '' && preg_match('/^[A-Za-z0-9,-]{16,128}$/', $sid)) {
        session_id($sid);
    }
    session_start();
    if ($preview || $https) {
        $cookie = $name . '=' . session_id() . '; Path=/; HttpOnly; SameSite=None';
        if ($https) $cookie .= '; Secure; Partitioned';
        header('Set-Cookie: ' . $cookie, false);
    }
}
$configFile = dirname(__FILE__) . '/../config/config.php';
if (file_exists($configFile)) {
    require $configFile;
} else {
    $JOMA_CONFIG = array('storage' => 'file', 'base_url' => '/joma');
}
$GLOBALS['JOMA_CONFIG'] = $JOMA_CONFIG;
require dirname(__FILE__) . '/jalali.php';
require dirname(__FILE__) . '/helpers.php';
$_joma_reg_gate = dirname(__FILE__) . '/registration_gate.php';
if (is_file($_joma_reg_gate)) {
    require $_joma_reg_gate;
}
unset($_joma_reg_gate);
$GLOBALS['JOMA_BASE'] = joma_compute_base();
require dirname(__FILE__) . '/store.php';
require dirname(__FILE__) . '/../functions/joma.php';
require dirname(__FILE__) . '/../functions/clinic.php';
require dirname(__FILE__) . '/layout.php';
