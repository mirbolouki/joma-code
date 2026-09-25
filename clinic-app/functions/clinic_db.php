<?php
// ============================================================================
// CLINIC-DB — لایه اتصال MySQL با mysqli برای «مدیریت مطب»
// - فقط mysqli (نه PDO)، بدون composer، سازگار با PHP 7.0 و MySQL 5.6+
// - بدون هیچ وابستگی به فایل‌های دیگر (تا install.php مستقل کار کند)
// ============================================================================

if (defined('CLINIC_DB_PHP_LOADED')) return;
define('CLINIC_DB_PHP_LOADED', true);

function clinic_db_config_path() {
    return dirname(__FILE__) . '/../config/clinic_db.php';
}

function clinic_db_lock_path() {
    return dirname(__FILE__) . '/../data/clinic_installed.lock';
}

function clinic_db_is_installed() {
    return is_file(clinic_db_lock_path());
}

// خواندن کانفیگ: فایل باید return array(...) کند
function clinic_db_config() {
    static $done = false;
    static $cfg = null;
    if ($done) return $cfg;
    $done = true;
    $f = clinic_db_config_path();
    if (!is_file($f)) return null;
    $cfg = include $f;
    if (!is_array($cfg)) {
        $cfg = null;
        return null;
    }
    foreach (array('db_host', 'db_name', 'db_user', 'db_pass') as $k) {
        if (!isset($cfg[$k])) $cfg[$k] = '';
    }
    return $cfg;
}

function clinic_db_connect_cfg($cfg) {
    if (!function_exists('mysqli_connect')) return null;
    $m = @mysqli_connect($cfg['db_host'], $cfg['db_user'], $cfg['db_pass'], $cfg['db_name']);
    if (!$m) return null;
    @mysqli_set_charset($m, 'utf8mb4');
    return $m;
}

// اتصال مشترک برنامه (fail با پیام فارسی)
function clinic_db() {
    static $m = null;
    if ($m) return $m;
    $cfg = clinic_db_config();
    if (!$cfg) {
        http_response_code(500);
        die('<meta charset="utf-8"><p>اتصال دیتابیس تنظیم نشده است. ابتدا نصب را انجام دهید: <a href="install.php">install.php</a></p>');
    }
    $m = clinic_db_connect_cfg($cfg);
    if (!$m) {
        http_response_code(500);
        die('<meta charset="utf-8"><p>اتصال به دیتابیس برقرار نشد. لطفاً بعداً تلاش کنید یا با مدیر تماس بگیرید.</p>');
    }
    return $m;
}

function clinic_db_error() {
    $cfg = clinic_db_config();
    if (!$cfg) return 'config missing';
    $m = @mysqli_connect($cfg['db_host'], $cfg['db_user'], $cfg['db_pass'], $cfg['db_name']);
    if (!$m) return @mysqli_connect_error();
    return '';
}

// ---- prepared statements (الگوی اثبات‌شده joma، مستقل) ----
function clinic_db_stmt_bind($stmt, $types, $values) {
    if (!$stmt) return false;
    if ($types === '' || !$values) return true;
    $refs = array();
    $args = array($stmt, $types);
    $n = count($values);
    for ($i = 0; $i < $n; $i++) {
        $refs[$i] = $values[$i];
        $args[] = &$refs[$i];
    }
    return call_user_func_array('mysqli_stmt_bind_param', $args);
}

function clinic_db_stmt_fetch_all($stmt) {
    if (function_exists('mysqli_stmt_get_result')) {
        $res = @mysqli_stmt_get_result($stmt);
        if ($res) {
            $out = array();
            while ($row = mysqli_fetch_assoc($res)) $out[] = $row;
            return $out;
        }
    }
    // fallback بدون mysqlnd
    $meta = mysqli_stmt_result_metadata($stmt);
    if (!$meta) return array();
    $fields = array();
    $row = array();
    $bind = array($stmt);
    while ($field = mysqli_fetch_field($meta)) {
        $fields[] = $field->name;
        $row[$field->name] = null;
    }
    mysqli_free_result($meta);
    foreach ($fields as $name) {
        $bind[] = &$row[$name];
    }
    call_user_func_array('mysqli_stmt_bind_result', $bind);
    $out = array();
    while (mysqli_stmt_fetch($stmt)) {
        $copy = array();
        foreach ($fields as $name) $copy[$name] = $row[$name];
        $out[] = $copy;
    }
    return $out;
}

function clinic_db_q($sql, $types, $values) {
    $stmt = mysqli_prepare(clinic_db(), $sql);
    if (!$stmt) return array();
    clinic_db_stmt_bind($stmt, $types, $values);
    mysqli_stmt_execute($stmt);
    $rows = clinic_db_stmt_fetch_all($stmt);
    mysqli_stmt_close($stmt);
    return $rows;
}

function clinic_db_one($sql, $types, $values) {
    $rows = clinic_db_q($sql, $types, $values);
    return $rows ? $rows[0] : null;
}

function clinic_db_exec($sql, $types, $values) {
    $stmt = mysqli_prepare(clinic_db(), $sql);
    if (!$stmt) return false;
    clinic_db_stmt_bind($stmt, $types, $values);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok ? true : false;
}

function clinic_db_insert_id() {
    return mysqli_insert_id(clinic_db());
}

// نرمال‌سازی NULL به رشته خالی برای تاریخ‌های اختیاری
function clinic_db_n($v) {
    return $v === null ? '' : $v;
}

// ================================================================== اسکیما ===
function clinic_db_schema() {
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return array(
        'clinic_users' => "CREATE TABLE IF NOT EXISTS `clinic_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name` VARCHAR(100) NOT NULL DEFAULT '',
  `last_name` VARCHAR(100) NOT NULL DEFAULT '',
  `username` VARCHAR(60) NOT NULL DEFAULT '',
  `email` VARCHAR(150) NOT NULL DEFAULT '',
  `phone` VARCHAR(20) NOT NULL DEFAULT '',
  `job` VARCHAR(100) NOT NULL DEFAULT '',
  `password_hash` VARCHAR(255) NOT NULL DEFAULT '',
  `role_key` VARCHAR(20) NOT NULL DEFAULT 'client',
  `access_level` INT NOT NULL DEFAULT 1,
  `doctor_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `active` TINYINT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_username` (`username`),
  KEY `ix_role` (`role_key`),
  KEY `ix_phone` (`phone`)
) $t",
        'clinic_clients' => "CREATE TABLE IF NOT EXISTS `clinic_clients` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_no` VARCHAR(20) NOT NULL DEFAULT '',
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `doctor_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `first_name` VARCHAR(100) NOT NULL DEFAULT '',
  `last_name` VARCHAR(100) NOT NULL DEFAULT '',
  `birth_date` VARCHAR(10) NOT NULL DEFAULT '',
  `job` VARCHAR(150) NOT NULL DEFAULT '',
  `education` VARCHAR(150) NOT NULL DEFAULT '',
  `marital` VARCHAR(30) NOT NULL DEFAULT '',
  `mobile` VARCHAR(20) NOT NULL DEFAULT '',
  `emergency_contact` VARCHAR(200) NOT NULL DEFAULT '',
  `referrer` VARCHAR(50) NOT NULL DEFAULT '',
  `referrer_other` VARCHAR(200) NOT NULL DEFAULT '',
  `first_visit_date` VARCHAR(10) NOT NULL DEFAULT '',
  `status` VARCHAR(10) NOT NULL DEFAULT 'active',
  `intake_status` VARCHAR(10) NOT NULL DEFAULT 'none',
  `intake` MEDIUMTEXT,
  `private_note` TEXT,
  `private_updated_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL,
  `archived_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_file_no` (`file_no`),
  UNIQUE KEY `ux_mobile` (`mobile`),
  KEY `ix_doctor` (`doctor_id`, `status`)
) $t",
        'clinic_invites' => "CREATE TABLE IF NOT EXISTS `clinic_invites` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `token` VARCHAR(64) NOT NULL DEFAULT '',
  `doctor_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `mobile` VARCHAR(20) NOT NULL DEFAULT '',
  `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL DEFAULT NULL,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_token` (`token`)
) $t",
        'clinic_appointments' => "CREATE TABLE IF NOT EXISTS `clinic_appointments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `doctor_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `date` VARCHAR(10) NOT NULL DEFAULT '',
  `start` VARCHAR(5) NOT NULL DEFAULT '',
  `end` VARCHAR(5) NOT NULL DEFAULT '',
  `type` VARCHAR(10) NOT NULL DEFAULT 'present',
  `status` VARCHAR(15) NOT NULL DEFAULT 'reserved',
  `fee` INT NOT NULL DEFAULT 0,
  `fee_manual` TINYINT NOT NULL DEFAULT 0,
  `note` TEXT,
  `remind_sent_at` DATETIME NULL DEFAULT NULL,
  `cancel_reason` VARCHAR(255) NOT NULL DEFAULT '',
  `late_cancel` TINYINT NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_client_date` (`client_id`, `date`),
  KEY `ix_doctor_date` (`doctor_id`, `date`)
) $t",
        'clinic_notes' => "CREATE TABLE IF NOT EXISTS `clinic_notes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `doctor_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `appointment_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `kind` VARCHAR(10) NOT NULL DEFAULT 'session',
  `session_no` INT NOT NULL DEFAULT 0,
  `date` VARCHAR(10) NOT NULL DEFAULT '',
  `duration` INT NOT NULL DEFAULT 0,
  `text` MEDIUMTEXT,
  `tags` TEXT,
  `progress` TINYINT NOT NULL DEFAULT 0,
  `next_plan` TEXT,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_client` (`client_id`),
  KEY `ix_appt` (`appointment_id`)
) $t",
        'clinic_tariffs' => "CREATE TABLE IF NOT EXISTS `clinic_tariffs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tkey` VARCHAR(30) NOT NULL DEFAULT '',
  `title` VARCHAR(100) NOT NULL DEFAULT '',
  `session_type` VARCHAR(10) NOT NULL DEFAULT 'present',
  `amount` INT NOT NULL DEFAULT 0,
  `active` TINYINT NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_tkey` (`tkey`)
) $t",
        'clinic_overrides' => "CREATE TABLE IF NOT EXISTS `clinic_overrides` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `doctor_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `session_type` VARCHAR(10) NOT NULL DEFAULT 'present',
  `amount` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ix_scope` (`doctor_id`, `client_id`, `session_type`)
) $t",
        'clinic_transactions' => "CREATE TABLE IF NOT EXISTS `clinic_transactions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `doctor_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `appointment_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `kind` VARCHAR(10) NOT NULL DEFAULT 'payment',
  `amount` INT NOT NULL DEFAULT 0,
  `method` VARCHAR(10) NOT NULL DEFAULT 'card',
  `ref_no` VARCHAR(100) NOT NULL DEFAULT '',
  `date` VARCHAR(10) NOT NULL DEFAULT '',
  `note` VARCHAR(255) NOT NULL DEFAULT '',
  `created_by` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_client_date` (`client_id`, `date`),
  KEY `ix_doctor_date` (`doctor_id`, `date`)
) $t",
        'clinic_settings' => "CREATE TABLE IF NOT EXISTS `clinic_settings` (
  `skey` VARCHAR(50) NOT NULL DEFAULT '',
  `svalue` TEXT,
  PRIMARY KEY (`skey`)
) $t",
        'clinic_audit' => "CREATE TABLE IF NOT EXISTS `clinic_audit` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `at` DATETIME NOT NULL,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `action` VARCHAR(100) NOT NULL DEFAULT '',
  `client_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `detail` VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `ix_client` (`client_id`)
) $t",
        'clinic_file_seq' => "CREATE TABLE IF NOT EXISTS `clinic_file_seq` (
  `yy` VARCHAR(4) NOT NULL DEFAULT '',
  `seq` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`yy`)
) $t",
    );
}

// اجرای اسکیما روی یک اتصال (برای installer) — خروجی: خطاها
function clinic_db_run_schema($m) {
    $errs = array();
    foreach (clinic_db_schema() as $name => $sql) {
        if (!mysqli_query($m, $sql)) {
            $errs[] = $name . ': ' . mysqli_error($m);
        }
    }
    return $errs;
}

// مقادیر پیش‌فرض تعرفه‌ها و تنظیمات (برای installer)
function clinic_db_seed_defaults($m) {
    $errs = array();
    $tariffs = array(
        array('first_present', 'ویزیت اول حضوری', 'present', 0),
        array('present', 'جلسه حضوری', 'present', 0),
        array('online', 'جلسه آنلاین', 'online', 0),
        array('phone', 'جلسه تلفنی', 'phone', 0),
        array('test', 'تست / خدمات جانبی', 'other', 0),
    );
    foreach ($tariffs as $t) {
        $stmt = mysqli_prepare($m, 'INSERT IGNORE INTO `clinic_tariffs` (`tkey`,`title`,`session_type`,`amount`,`active`) VALUES (?,?,?,?,1)');
        if (!$stmt) {
            $errs[] = 'tariffs: ' . mysqli_error($m);
            continue;
        }
        mysqli_stmt_bind_param($stmt, 'sssi', $t[0], $t[1], $t[2], $t[3]);
        if (!mysqli_stmt_execute($stmt)) $errs[] = 'tariffs: ' . mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
    }
    $settings = array(
        'reminder_mode' => 'manual',
        'reminder_template' => "سلام {name} عزیز\nیادآوری نوبت {doctor}:\n{date} — ساعت {time}\nلطفاً در صورت نیاز به جابه‌جایی، حداقل ۲۴ ساعت قبل اطلاع دهید.",
        'crisis_text' => 'اگر احساس خطر فوری برای خودتان یا دیگران دارید، لطفاً سریعاً با اورژانس (۱۱۵) یا اورژانس اجتماعی (۱۲۳) تماس بگیرید.',
        'accuracy_text' => 'صحت اطلاعات واردشده را تأیید می‌کنم.',
        'cancel_hours' => '24',
        'sms_api_url' => '',
        'sms_ok_contains' => '',
        'clinic_name' => 'مطب',
        'clinic_contact' => '',
    );
    foreach ($settings as $k => $v) {
        $stmt = mysqli_prepare($m, 'INSERT IGNORE INTO `clinic_settings` (`skey`,`svalue`) VALUES (?,?)');
        if (!$stmt) {
            $errs[] = 'settings: ' . mysqli_error($m);
            continue;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $k, $v);
        if (!mysqli_stmt_execute($stmt)) $errs[] = 'settings: ' . mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
    }
    return $errs;
}
