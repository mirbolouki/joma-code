<?php
ini_set('display_errors','0');
ini_set('zend.exception_ignore_args','1');
$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$sessionDir = __DIR__ . '/../data/sessions';
if (!is_dir($sessionDir)) mkdir($sessionDir,0750,true);
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode','1');
    ini_set('session.use_only_cookies','1');
    ini_set('session.use_trans_sid','0');
    ini_set('session.cookie_httponly','1');
    ini_set('session.cookie_secure',$https?'1':'0');
    ini_set('session.cookie_samesite','Lax');
    ini_set('session.cookie_path','/');
    ini_set('session.save_path',$sessionDir);
    session_start();
}
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
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
require dirname(__FILE__) . '/layout.php';
