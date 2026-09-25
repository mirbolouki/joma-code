<?php
// ============================================================================
// CLINIC — ماژول «مدیریت مطب» (my.mirbolouki.com)
// نسخه ۲.۰ — ذخیره‌سازی MySQL با mysqli (بدون PDO)، سازگار PHP 7.0، تابع‌محور
// امضای توابع نسبت به ۱.۰ ثابت مانده؛ فقط لایه ذخیره‌سازی عوض شده است.
// ============================================================================

if (defined('CLINIC_PHP_LOADED')) return;
define('CLINIC_PHP_LOADED', true);

require_once dirname(__FILE__) . '/clinic_db.php';

// ---------------------------------------------------------------- نقش‌ها ---
function clinic_roles() {
    return array(
        'admin' => 'مدیر سیستم',
        'doctor' => 'دکتر',
        'head_secretary' => 'منشی ارشد',
        'secretary' => 'منشی دکتر',
        'client' => 'مراجع',
    );
}

function clinic_role() {
    $u = current_user();
    return $u ? $u['role_key'] : '';
}

function clinic_is_staff() {
    return in_array(clinic_role(), array('admin', 'doctor', 'head_secretary', 'secretary'), true);
}

function clinic_is_admin() {
    return clinic_role() === 'admin';
}

function clinic_my_id() {
    $u = current_user();
    return $u ? (int) $u['id'] : 0;
}

// شناسه دکترِ منتسب به کاربر جاری (برای منشی دکتر)
function clinic_my_doctor_id() {
    $u = current_user();
    if (!$u) return 0;
    if ($u['role_key'] === 'doctor') return (int) $u['id'];
    if ($u['role_key'] === 'secretary') {
        $full = clinic_get_user((int) $u['id']);
        return $full && isset($full['doctor_id']) ? (int) $full['doctor_id'] : 0;
    }
    return 0;
}

// محدوده دکترهای قابل مشاهده: null = همه (مدیر/منشی ارشد)
function clinic_scope_doctor_ids() {
    $r = clinic_role();
    if ($r === 'admin' || $r === 'head_secretary') return null;
    if ($r === 'doctor') return array(clinic_my_id());
    if ($r === 'secretary') {
        $d = clinic_my_doctor_id();
        return $d > 0 ? array($d) : array(-1);
    }
    return array(-1); // مراجع: فقط پرونده خودش (با user_id چک می‌شود)
}

function clinic_deny($msg) {
    joma_header('دسترسی محدود');
    echo '<div class="card"><h1>دسترسی محدود است</h1><p class="bad">' . e($msg) . '</p>';
    echo '<p><a class="btn" href="' . e(joma_url('index.php?p=clinic_dashboard')) . '">بازگشت به پیشخوان مطب</a></p></div>';
    joma_footer();
    exit;
}

function clinic_require_login() {
    require_login();
}

function clinic_require_staff() {
    clinic_require_login();
    if (!clinic_is_staff()) clinic_deny('این صفحه فقط برای کادر مطب است.');
}

function clinic_require_admin() {
    clinic_require_login();
    if (!clinic_is_admin()) clinic_deny('این صفحه فقط برای مدیر سیستم است.');
}

// داده بالینی (بخش ۲ تا ۵ + خلاصه جلسات + یادداشت خصوصی): فقط دکتر معالج و مدیر
function clinic_can_clinical() {
    return in_array(clinic_role(), array('admin', 'doctor'), true);
}

// مدیریت مالی (ثبت تراکنش/تعرفه): مدیر + منشی ارشد + منشی
function clinic_can_manage_finance() {
    return in_array(clinic_role(), array('admin', 'head_secretary', 'secretary'), true);
}

function clinic_can_manage_tariffs() {
    return in_array(clinic_role(), array('admin', 'head_secretary'), true);
}

// آیا کاربر جاری اجازه مشاهده این پرونده را دارد؟
function clinic_can_access_client($client) {
    if (!$client) return false;
    $r = clinic_role();
    if ($r === 'admin' || $r === 'head_secretary') return true;
    if ($r === 'doctor') return (int) $client['doctor_id'] === clinic_my_id();
    if ($r === 'secretary') {
        $d = clinic_my_doctor_id();
        return $d > 0 && (int) $client['doctor_id'] === $d;
    }
    if ($r === 'client') {
        return isset($client['user_id']) && (int) $client['user_id'] === clinic_my_id();
    }
    return false;
}

function clinic_can_edit_client_base($client) {
    if (!clinic_can_access_client($client)) return false;
    return in_array(clinic_role(), array('admin', 'doctor', 'head_secretary', 'secretary'), true);
}

// ویرایش بالینی پرونده: دکترِ همان پرونده + مدیر
function clinic_can_edit_clinical($client) {
    if (!$client) return false;
    if (clinic_role() === 'admin') return true;
    return clinic_role() === 'doctor' && (int) $client['doctor_id'] === clinic_my_id();
}

function clinic_user_display($u) {
    if (!$u) return '—';
    $n = trim((isset($u['first_name']) ? $u['first_name'] : '') . ' ' . (isset($u['last_name']) ? $u['last_name'] : ''));
    if ($n === '') $n = isset($u['username']) ? $u['username'] : '—';
    return $n;
}

// ------------------------------------------------------------- کاربران ---
function clinic_user_row($u) {
    if (!$u) return null;
    $u['id'] = (int) $u['id'];
    $u['access_level'] = (int) (isset($u['access_level']) ? $u['access_level'] : 1);
    $u['doctor_id'] = (int) (isset($u['doctor_id']) ? $u['doctor_id'] : 0);
    $u['active'] = (int) (isset($u['active']) ? $u['active'] : 1);
    return $u;
}

function clinic_get_user($id) {
    $row = clinic_db_one('SELECT * FROM `clinic_users` WHERE `id`=? LIMIT 1', 'i', array((int) $id));
    return $row ? clinic_user_row($row) : null;
}

function clinic_user_by_phone($mobile) {
    $m = clinic_norm_mobile($mobile);
    if ($m === '') return null;
    $row = clinic_db_one('SELECT * FROM `clinic_users` WHERE `phone`=? OR `username`=? LIMIT 1', 'ss', array($m, $m));
    return $row ? clinic_user_row($row) : null;
}

// ورود با نام‌کاربری یا موبایل
function clinic_user_by_login($identifier) {
    $idn = strtolower(trim((string) $identifier));
    if ($idn === '') return null;
    $m = clinic_norm_mobile($idn);
    if ($m !== '') {
        $u = clinic_user_by_phone($m);
        if ($u) return $u;
    }
    $row = clinic_db_one('SELECT * FROM `clinic_users` WHERE `username`=? LIMIT 1', 's', array($idn));
    return $row ? clinic_user_row($row) : null;
}

function clinic_username_taken($username, $except = 0) {
    $username = strtolower(trim((string) $username));
    $row = clinic_db_one('SELECT `id` FROM `clinic_users` WHERE `username`=? AND `id`<>? LIMIT 1', 'si', array($username, (int) $except));
    return (bool) $row;
}

function clinic_email_taken($email, $except = 0) {
    $email = strtolower(trim((string) $email));
    $row = clinic_db_one('SELECT `id` FROM `clinic_users` WHERE `email`=? AND `id`<>? LIMIT 1', 'si', array($email, (int) $except));
    return (bool) $row;
}

function clinic_create_user($in) {
    $ok = clinic_db_exec(
        'INSERT INTO `clinic_users` (`first_name`,`last_name`,`username`,`email`,`phone`,`job`,`password_hash`,`role_key`,`access_level`,`doctor_id`,`active`,`created_at`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        'ssssssssiiis',
        array(
            isset($in['first_name']) ? $in['first_name'] : '',
            isset($in['last_name']) ? $in['last_name'] : '',
            isset($in['username']) ? $in['username'] : '',
            isset($in['email']) ? $in['email'] : '',
            isset($in['phone']) ? $in['phone'] : '',
            isset($in['job']) ? $in['job'] : '',
            isset($in['password_hash']) ? $in['password_hash'] : '',
            isset($in['role_key']) ? $in['role_key'] : 'client',
            1,
            (int) (isset($in['doctor_id']) ? $in['doctor_id'] : 0),
            1,
            joma_now(),
        )
    );
    return $ok ? clinic_db_insert_id() : 0;
}

function clinic_update_user($id, $patch) {
    $allow = array('first_name', 'last_name', 'phone', 'job', 'password_hash', 'role_key', 'doctor_id', 'active', 'email');
    $sets = array();
    $types = '';
    $vals = array();
    foreach ($allow as $k) {
        if (!isset($patch[$k])) continue;
        $sets[] = '`' . $k . '`=?';
        if ($k === 'doctor_id' || $k === 'active') {
            $types .= 'i';
            $vals[] = (int) $patch[$k];
        } else {
            $types .= 's';
            $vals[] = (string) $patch[$k];
        }
    }
    if (!$sets) return true;
    $types .= 'i';
    $vals[] = (int) $id;
    return clinic_db_exec('UPDATE `clinic_users` SET ' . implode(',', $sets) . ' WHERE `id`=?', $types, $vals);
}

function clinic_doctors_list() {
    $rows = clinic_db_q("SELECT * FROM `clinic_users` WHERE `role_key`='doctor' AND `active`=1 ORDER BY `first_name`,`last_name`", '', array());
    $out = array();
    foreach ($rows as $r) $out[] = clinic_user_row($r);
    return $out;
}

function clinic_staff_list() {
    $rows = clinic_db_q("SELECT * FROM `clinic_users` WHERE `role_key` IN ('admin','doctor','head_secretary','secretary') ORDER BY `role_key`,`username`", '', array());
    $out = array();
    foreach ($rows as $r) $out[] = clinic_user_row($r);
    return $out;
}

// ------------------------------------------------------------- ابزارها ---
function clinic_norm_digits($s) {
    $s = (string) $s;
    $fa = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٫', '٬', '،');
    $en = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '.', ',', ',');
    return str_replace($fa, $en, $s);
}

function clinic_norm_mobile($m) {
    $m = clinic_norm_digits(trim((string)$m));
    $m = str_replace(array(' ', '-', '(', ')'), '', $m);
    if (strpos($m, '+98') === 0) $m='0'.substr($m,3);
    elseif (strpos($m, '0098') === 0) $m='0'.substr($m,4);
    elseif (strlen($m)===12 && strpos($m,'98')===0) $m='0'.substr($m,2);
    elseif (strlen($m)===10 && strpos($m,'9')===0) $m='0'.$m;
    return preg_match('/^09[0-9]{9}$/',$m) ? $m : '';
}

function clinic_norm_money($v) {
    $v = clinic_norm_digits(trim((string) $v));
    $v = str_replace(array(',', ' ', 'تومان'), '', $v);
    if (!preg_match('/^[0-9]+$/', $v)) return null;
    return (int) $v;
}

function clinic_money($n) {
    return fa_num(number_format((int) $n)) . ' تومان';
}

function clinic_money_short($n) {
    return fa_num(number_format((int) $n));
}

function clinic_today() {
    return jalali_today();
}

function clinic_fa_date($jdate) {
    if (!$jdate) return '—';
    try {
        return fa_num(jalali_format($jdate));
    } catch (Exception $ex) {
        return $jdate;
    }
}

function clinic_fa_datetime($dt) {
    if (!$dt) return '—';
    $p = explode(' ', $dt);
    $d = isset($p[0]) ? $p[0] : '';
    $t = isset($p[1]) ? substr($p[1], 0, 5) : '';
    // اگر تاریخ شمسی بود مستقیم، اگر میلادی بود تبدیل
    if (preg_match('/^14[0-9]{2}-[0-9]{2}-[0-9]{2}$/', $d)) {
        $out = clinic_fa_date($d);
    } else {
        $pp = explode('-', $d);
        if (count($pp) === 3) {
            $j = gregorian_to_jalali((int) $pp[0], (int) $pp[1], (int) $pp[2]);
            $out = fa_num($j[2] . ' ' . jalali_month_name($j[1]) . ' ' . $j[0]);
        } else {
            $out = $d;
        }
    }
    if ($t !== '') $out .= ' — ساعت ' . fa_num($t);
    return $out;
}

function clinic_valid_jdate($d) {
    $d = clinic_norm_digits(trim((string) $d));
    if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $d)) return '';
    if (function_exists('jalali_is_valid') && !jalali_is_valid($d)) return '';
    return $d;
}

function clinic_valid_time($t) {
    $t = clinic_norm_digits(trim((string) $t));
    if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $t)) return '';
    return $t;
}

// فاصله ساعت تا تاریخ+ساعت شمسی (برای سیاست لغو ۲۴ ساعته)
function clinic_hours_until($jdate, $time) {
    $p = explode('-', $jdate);
    if (count($p) !== 3) return 9999;
    $g = jalali_to_gregorian((int) $p[0], (int) $p[1], (int) $p[2]);
    $ts = @mktime((int) substr($time, 0, 2), (int) substr($time, 3, 2), 0, $g[1], $g[2], $g[0]);
    if (!$ts) return 9999;
    return ($ts - time()) / 3600;
}

function clinic_age_from_birth($birth) {
    $p = explode('-', (string) $birth);
    if (count($p) !== 3) return '';
    $jy = (int) $p[0];
    if ($jy < 1300 || $jy > 1500) return '';
    $now = explode('-', jalali_today());
    $age = (int) $now[0] - $jy;
    if ((int) $now[1] < (int) $p[1] || ((int) $now[1] === (int) $p[1] && (int) $now[2] < (int) $p[2])) $age--;
    return $age >= 0 ? $age : '';
}

// ساخت لیست IN امن از شناسه‌ها (عدد)
function clinic_sql_in($ids) {
    $clean = array();
    foreach ((array) $ids as $v) $clean[] = (int) $v;
    if (!$clean) $clean[] = -1;
    return implode(',', $clean);
}

// ------------------------------------------------------------- پرونده‌ها ---
function clinic_client_statuses() {
    return array('active' => 'فعال', 'archived' => 'بایگانی‌شده');
}

function clinic_intake_statuses() {
    return array(
        'none' => 'تکمیل‌نشده',
        'self' => 'خوداظهاری ثبت شد',
        'reviewed' => 'بررسی‌شده توسط درمانگر ✅',
    );
}

function clinic_client_row($c) {
    if (!$c) return null;
    $c['id'] = (int) $c['id'];
    $c['user_id'] = (int) $c['user_id'];
    $c['doctor_id'] = (int) $c['doctor_id'];
    $c['created_by'] = (int) $c['created_by'];
    $c['private_updated_at'] = clinic_db_n(isset($c['private_updated_at']) ? $c['private_updated_at'] : '');
    $c['archived_at'] = clinic_db_n(isset($c['archived_at']) ? $c['archived_at'] : '');
    $raw = isset($c['intake']) ? $c['intake'] : '';
    if ($raw === null || $raw === '') {
        $c['intake'] = array();
    } else {
        $d = json_decode($raw, true);
        $c['intake'] = is_array($d) ? $d : array();
    }
    return $c;
}

function clinic_next_file_no() {
    $year = substr(jalali_today(), 0, 4);
    clinic_db_exec('INSERT INTO clinic_file_seq (yy,seq) VALUES (?,LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE seq=LAST_INSERT_ID(seq+1)','s',array($year));
    $n=(int)mysqli_insert_id(clinic_db());
    return $year . '-' . str_pad((string)$n,4,'0',STR_PAD_LEFT);
}

function clinic_create_client($in, $by_user_id) {
    $mobile = clinic_norm_mobile(isset($in['mobile']) ? $in['mobile'] : '');
    if ($mobile === '') return array('error' => 'شماره موبایل معتبر وارد کنید (مثل 09123456789).');
    $dup = clinic_db_one('SELECT `id`,`file_no` FROM `clinic_clients` WHERE `mobile`=? LIMIT 1', 's', array($mobile));
    if ($dup) {
        return array('error' => 'این شماره موبایل قبلاً پرونده دارد (' . $dup['file_no'] . ').', 'dup_id' => (int) $dup['id']);
    }
    $first = trim(isset($in['first_name']) ? $in['first_name'] : '');
    $last = trim(isset($in['last_name']) ? $in['last_name'] : '');
    if ($first === '' && $last === '') return array('error' => 'نام و نام خانوادگی را وارد کنید.');
    $doctor_id = (int) (isset($in['doctor_id']) ? $in['doctor_id'] : 0);
    if ($doctor_id <= 0 && empty($in['verified_registration'])) return array('error' => 'دکتر معالج را انتخاب کنید.');
    $file_no = clinic_next_file_no();
    $now = joma_now();
    $ok = clinic_db_exec(
        'INSERT INTO `clinic_clients` (`file_no`,`doctor_id`,`first_name`,`last_name`,`birth_date`,`job`,`education`,`marital`,`mobile`,`emergency_contact`,`referrer`,`referrer_other`,`first_visit_date`,`status`,`intake_status`,`created_at`,`created_by`,`updated_at`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        'sissssssssssssssis',
        array(
            $file_no, $doctor_id, $first, $last,
            clinic_valid_jdate(isset($in['birth_date']) ? $in['birth_date'] : ''),
            trim(isset($in['job']) ? $in['job'] : ''),
            trim(isset($in['education']) ? $in['education'] : ''),
            trim(isset($in['marital']) ? $in['marital'] : ''),
            $mobile,
            trim(isset($in['emergency_contact']) ? $in['emergency_contact'] : ''),
            trim(isset($in['referrer']) ? $in['referrer'] : ''),
            trim(isset($in['referrer_other']) ? $in['referrer_other'] : ''),
            clinic_valid_jdate(isset($in['first_visit_date']) ? $in['first_visit_date'] : ''),
            'active', 'none', $now, (int) $by_user_id, $now,
        )
    );
    if (!$ok) return array('error' => 'خطا در ساخت پرونده. دوباره تلاش کنید.');
    return array('id' => clinic_db_insert_id(), 'file_no' => $file_no);
}

function clinic_get_client($id) {
    $row = clinic_db_one('SELECT * FROM `clinic_clients` WHERE `id`=? LIMIT 1', 'i', array((int) $id));
    return $row ? clinic_client_row($row) : null;
}

function clinic_get_client_by_mobile($mobile) {
    $m = clinic_norm_mobile($mobile);
    if ($m === '') return null;
    $row = clinic_db_one('SELECT * FROM `clinic_clients` WHERE `mobile`=? LIMIT 1', 's', array($m));
    return $row ? clinic_client_row($row) : null;
}

function clinic_get_client_by_user($user_id) {
    $row = clinic_db_one('SELECT * FROM `clinic_clients` WHERE `user_id`=? LIMIT 1', 'i', array((int) $user_id));
    return $row ? clinic_client_row($row) : null;
}

function clinic_update_client($id, $patch, $by_user_id) {
    $id = (int) $id;
    if (isset($patch['mobile'])) {
        $nm = clinic_norm_mobile($patch['mobile']);
        if ($nm === '') return array('error' => 'شماره موبایل معتبر نیست.');
        $dup = clinic_db_one('SELECT `id`,`file_no` FROM `clinic_clients` WHERE `mobile`=? AND `id`<>? LIMIT 1', 'si', array($nm, $id));
        if ($dup) return array('error' => 'این شماره موبایل متعلق به پرونده دیگری است (' . $dup['file_no'] . ').');
        $patch['mobile'] = $nm;
    }
    $str_keys = array('first_name', 'last_name', 'birth_date', 'job', 'education', 'marital', 'mobile', 'emergency_contact', 'referrer', 'referrer_other', 'first_visit_date', 'status', 'intake_status', 'intake', 'private_note', 'private_updated_at', 'archived_at');
    $int_keys = array('user_id', 'doctor_id');
    $sets = array();
    $types = '';
    $vals = array();
    foreach ($str_keys as $k) {
        if (!isset($patch[$k])) continue;
        // در strict mode مقدار '' برای DATETIME خطاست؛ NULL می‌گذاریم
        if (($k === 'archived_at' || $k === 'private_updated_at') && $patch[$k] === '') {
            $sets[] = '`' . $k . '`=NULL';
            continue;
        }
        $sets[] = '`' . $k . '`=?';
        $types .= 's';
        $vals[] = (string) $patch[$k];
    }
    foreach ($int_keys as $k) {
        if (!isset($patch[$k])) continue;
        $sets[] = '`' . $k . '`=?';
        $types .= 'i';
        $vals[] = (int) $patch[$k];
    }
    $sets[] = '`updated_at`=?';
    $types .= 's';
    $vals[] = joma_now();
    $types .= 'i';
    $vals[] = $id;
    $ok = clinic_db_exec('UPDATE `clinic_clients` SET ' . implode(',', $sets) . ' WHERE `id`=?', $types, $vals);
    return $ok ? array('ok' => true) : array('error' => 'پرونده پیدا نشد یا به‌روزرسانی ناموفق بود.');
}

function clinic_client_display_name($c) {
    $n = trim($c['first_name'] . ' ' . $c['last_name']);
    return $n !== '' ? $n : 'بدون نام';
}

// فهرست پرونده‌ها با فیلتر و رعایت محدوده دسترسی نقش جاری
function clinic_list_clients($filters) {
    $scope = clinic_scope_doctor_ids();
    $q = clinic_norm_digits(trim(isset($filters['q']) ? $filters['q'] : ''));
    $status = isset($filters['status']) ? $filters['status'] : 'active';
    $doctor_id = (int) (isset($filters['doctor_id']) ? $filters['doctor_id'] : 0);
    $flag = isset($filters['flag']) ? $filters['flag'] : '';
    $where = array();
    $types = '';
    $vals = array();
    if ($scope !== null) $where[] = '`doctor_id` IN (' . clinic_sql_in($scope) . ')';
    if (clinic_role() === 'client') {
        $where[] = '`user_id`=?';
        $types .= 'i';
        $vals[] = clinic_my_id();
    }
    if ($status !== '' && $status !== 'all') {
        $where[] = '`status`=?';
        $types .= 's';
        $vals[] = $status;
    }
    if ($doctor_id > 0) {
        $where[] = '`doctor_id`=?';
        $types .= 'i';
        $vals[] = $doctor_id;
    }
    if ($q !== '') {
        $qn = str_replace(' ', '', $q);
        $qn = str_replace(array('%', '_'), array('\\%', '\\_'), $qn);
        $where[] = "REPLACE(CONCAT(`first_name`,`last_name`,`mobile`,`file_no`),' ','') LIKE ?";
        $types .= 's';
        $vals[] = '%' . $qn . '%';
    }
    if ($flag === 'nointake') $where[] = "`intake_status`='none'";
    if ($flag === 'unreviewed') $where[] = "`intake_status`='self'";
    $sql = 'SELECT * FROM `clinic_clients`';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY `file_no` DESC LIMIT 500';
    $rows = clinic_db_q($sql, $types, $vals);
    $out = array();
    foreach ($rows as $r) {
        $c = clinic_client_row($r);
        if ($flag === 'risk') {
            $fl = isset($c['intake']['flags']) ? $c['intake']['flags'] : array();
            $has = (!empty($fl['violence']) || !empty($fl['risks']));
            if (!$has) continue;
        }
        $out[] = $c;
    }
    return $out;
}

// ------------------------------------------------------------- دعوت‌نامه ---
function clinic_create_invite($doctor_id, $client_id, $mobile, $by_user_id) {
    $doctor=clinic_get_user((int)$doctor_id);
    if (!$doctor || $doctor['role_key']!=='doctor' || !$doctor['active']) throw new RuntimeException('پزشک معتبر انتخاب کنید.');
    $scope=clinic_scope_doctor_ids();
    if (!clinic_is_staff() || ($scope!==null && !in_array((int)$doctor_id,$scope,true))) throw new RuntimeException('دسترسی به پزشک ندارید.');
    if ((int)$client_id>0) {
        $client=clinic_get_client($client_id);
        if (!$client || !clinic_can_access_client($client) || (int)$client['doctor_id']!==(int)$doctor_id) throw new RuntimeException('پرونده با پزشک مطابقت ندارد.');
        $mobile=$client['mobile'];
    }
    if (clinic_norm_mobile($mobile)==='') throw new RuntimeException('شماره همراه مراجع برای دعوت الزامی است.');
    $token = bin2hex(random_bytes(12)); // 96 bits; fits SMS.ir 25-character limit.
    clinic_db_exec(
        'INSERT INTO `clinic_invites` (`token`,`doctor_id`,`client_id`,`mobile`,`created_by`,`created_at`,`expires_at`) VALUES (?,?,?,?,?,?,?)',
        'siisiss',
        array($token, (int) $doctor_id, (int) $client_id, clinic_norm_mobile($mobile), (int) $by_user_id, joma_now(), date('Y-m-d H:i:s', time() + 7 * 86400))
    );
    return $token;
}

function clinic_get_invite_by_token($t) {
    $t = trim((string) $t);
    if ($t === '' || !preg_match('/^[a-f0-9]{16,64}$/', $t)) return null;
    $inv = clinic_db_one('SELECT * FROM `clinic_invites` WHERE `token`=? LIMIT 1', 's', array($t));
    if (!$inv) return null;
    if ($inv['used_at'] !== null && $inv['used_at'] !== '') return null;
    if ($inv['expires_at'] !== '' && $inv['expires_at'] < joma_now()) return null;
    $inv['id'] = (int) $inv['id'];
    $inv['doctor_id'] = (int) $inv['doctor_id'];
    $inv['client_id'] = (int) $inv['client_id'];
    $inv['created_by'] = (int) $inv['created_by'];
    return $inv;
}

function clinic_burn_invite($id) {
    return clinic_db_exec('UPDATE `clinic_invites` SET `used_at`=? WHERE `id`=?', 'si', array(joma_now(), (int) $id));
}

function clinic_invite_url($token) {
    return joma_url('index.php?i=' . $token);
}

// ----------------------------------------------- فرم جلسه اول (اینتیک) ---
function clinic_intake_options() {
    return array(
        'marital' => array('مجرد', 'متأهل', 'جدا شده', 'در شرف طلاق', 'داغدیده'),
        'referrer' => array('اینستاگرام', 'وب‌سایت', 'معرفی دوستان', 'سایر'),
        'duration' => array('کمتر از ۳ ماه', '۳ تا ۱۲ ماه', 'بیش از ۱ سال'),
        'mood' => array(
            'anxiety' => 'اضطراب',
            'depressed' => 'خلق افسرده و بی‌حوصلگی',
            'anger' => 'خشم و ضعف در کنترل تکانه',
            'rumination' => 'نشخوار فکری و وسواس فکری',
            'sleep_appetite' => 'اختلال در خواب یا اشتها',
            'low_energy' => 'افت سطح انرژی',
            'grief' => 'سوگ یا فقدان',
            'social_anxiety' => 'اضطراب اجتماعی و کمرویی شدید',
        ),
        'rel_status' => array('در رابطه', 'متأهل', 'بدون رابطه', 'در فرآیند جدایی'),
        'family_mood' => array('صمیمی', 'سرد', 'پرخاشگر', 'حمایتگر', 'آشفته'),
        'rel_issues' => array(
            'cheat_received' => 'خیانت دریافت‌شده',
            'cheat_done' => 'خیانت انجام‌شده',
            'comm' => 'اختلال در ارتباط کلامی و گفتگوی سازنده',
            'cold' => 'سردی عاطفی و دوری‌گزینی',
            'divorce' => 'تصمیم به جدایی یا طلاق',
            'violence' => 'خشونت عاطفی، کلامی یا فیزیکی',
            'inlaws' => 'تعارض با خانواده همسر',
            'sexual' => 'اختلال در رابطه جنسی',
            'conflict_cycle' => 'تعارض مکرر و چرخه‌های بحث و قهر',
        ),
        'lifestyle' => array(
            'procrast' => 'اهمال‌کاری',
            'phone' => 'استفاده مفرط از گوشی یا فضای مجازی',
            'sleep' => 'بی‌نظمی در خواب یا بیداری',
            'no_sport' => 'فقدان فعالیت بدنی منظم',
            'food' => 'تغذیه نامنظم یا ناسالم',
            'no_goal' => 'اهداف شخصی نامشخص',
            'perf_drop' => 'افت عملکرد شغلی یا تحصیلی',
            'caffeine' => 'مصرف بیش از حد کافئین یا نیکوتین',
        ),
        'risks' => array(
            'self_harm_thought' => 'افکار آسیب به خود',
            'other_harm_thought' => 'افکار آسیب به دیگران',
            'suicide_history' => 'سابقه اقدام به خودکشی',
            'self_harm' => 'آسیب رساندن به خود (Self-Harm)',
            'psych_meds' => 'مصرف داروهای روان‌پزشکی',
            'substance' => 'سوءمصرف مواد یا الکل',
            'hospital' => 'سابقه بستری روان‌پزشکی',
            'trauma' => 'سابقه تروما یا سوءاستفاده',
        ),
        'sleep_quality' => array('خوب', 'متوسط', 'ضعیف'),
        'yesno' => array('بله', 'خیر'),
    );
}

function clinic_consent_texts() {
    return array(
        't1' => 'تمام گفته‌های مراجع نزد درمانگر باقی می‌ماند، مگر در موارد خطر جانی برای خود یا دیگران و طبق الزام قانونی.',
        't2' => 'هزینه جلسه، مدت هر جلسه و شرایط کنسلی (حداقل ۲۴ ساعت قبل) به اطلاع مراجع رسیده است.',
        't3' => 'تعداد، فاصله زمانی و روند کلی جلسات توضیح داده شده است.',
    );
}

function clinic_intake_empty() {
    return array(
        's1' => array('first_name' => '', 'last_name' => '', 'birth_date' => '', 'job' => '', 'education' => '', 'marital' => '', 'mobile' => '', 'emergency_contact' => '', 'referrer' => '', 'referrer_other' => ''),
        's2' => array('reason' => '', 'duration' => '', 'prev_therapy' => '', 'prev_therapy_note' => '', 'mood' => array(), 'goal' => ''),
        's3' => array('rel_status' => '', 'partner_aware' => '', 'family_mix' => '', 'family_mood' => '', 'rel_issues' => array(), 'biggest_challenge' => ''),
        's4' => array('lifestyle' => array(), 'routine' => '', 'values' => ''),
        's5' => array('risks' => array(), 'psych_meds_note' => '', 'under_doctor' => '', 'under_doctor_note' => '', 'sleep_quality' => '', 'physical' => '', 'physical_note' => ''),
        'consent' => array('t1' => 0, 't2' => 0, 't3' => 0, 'accuracy' => 0),
        'flags' => array('violence' => 0, 'risks' => array()),
        'submitted_at' => '', 'reviewed_at' => '', 'reviewed_by' => 0,
    );
}

// خواندن فرم از POST (بخش‌بندی‌شده)
function clinic_intake_from_post() {
    $opt = clinic_intake_options();
    $g = function ($k) { return trim(isset($_POST[$k]) ? (string) $_POST[$k] : ''); };
    $ga = function ($k) {
        $v = isset($_POST[$k]) ? $_POST[$k] : array();
        if (!is_array($v)) return array();
        return array_values(array_unique(array_map('strval', $v)));
    };
    $inone = function ($v, $list) { return in_array($v, $list, true) ? $v : ''; };
    $inkeys = function ($arr, $map) {
        $out = array();
        foreach ($arr as $v) { if (isset($map[$v])) $out[] = $v; }
        return $out;
    };
    $in = clinic_intake_empty();
    $in['s1'] = array(
        'first_name' => $g('s1_first_name'), 'last_name' => $g('s1_last_name'),
        'birth_date' => clinic_valid_jdate($g('s1_birth_date')),
        'job' => $g('s1_job'), 'education' => $g('s1_education'),
        'marital' => $inone($g('s1_marital'), $opt['marital']),
        'mobile' => clinic_norm_mobile($g('s1_mobile')),
        'emergency_contact' => $g('s1_emergency'),
        'referrer' => $inone($g('s1_referrer'), $opt['referrer']),
        'referrer_other' => $g('s1_referrer_other'),
    );
    $in['s2'] = array(
        'reason' => $g('s2_reason'),
        'duration' => $inone($g('s2_duration'), $opt['duration']),
        'prev_therapy' => $inone($g('s2_prev'), $opt['yesno']),
        'prev_therapy_note' => $g('s2_prev_note'),
        'mood' => $inkeys($ga('s2_mood'), $opt['mood']),
        'goal' => $g('s2_goal'),
    );
    $in['s3'] = array(
        'rel_status' => $inone($g('s3_rel'), $opt['rel_status']),
        'partner_aware' => $inone($g('s3_aware'), $opt['yesno']),
        'family_mix' => $g('s3_family'),
        'family_mood' => $inone($g('s3_mood'), $opt['family_mood']),
        'rel_issues' => $inkeys($ga('s3_issues'), $opt['rel_issues']),
        'biggest_challenge' => $g('s3_challenge'),
    );
    $in['s4'] = array(
        'lifestyle' => $inkeys($ga('s4_life'), $opt['lifestyle']),
        'routine' => $g('s4_routine'), 'values' => $g('s4_values'),
    );
    $in['s5'] = array(
        'risks' => $inkeys($ga('s5_risks'), $opt['risks']),
        'psych_meds_note' => $g('s5_meds'),
        'under_doctor' => $inone($g('s5_under'), $opt['yesno']),
        'under_doctor_note' => $g('s5_under_note'),
        'sleep_quality' => $inone($g('s5_sleep'), $opt['sleep_quality']),
        'physical' => $inone($g('s5_phys'), $opt['yesno']),
        'physical_note' => $g('s5_phys_note'),
    );
    $in['consent'] = array(
        't1' => !empty($_POST['c_t1']) ? 1 : 0,
        't2' => !empty($_POST['c_t2']) ? 1 : 0,
        't3' => !empty($_POST['c_t3']) ? 1 : 0,
        'accuracy' => !empty($_POST['c_acc']) ? 1 : 0,
    );
    $in['flags'] = array(
        'violence' => in_array('violence', $in['s3']['rel_issues'], true) ? 1 : 0,
        'risks' => $in['s5']['risks'],
    );
    return $in;
}

// اعتبارسنجی ثبت نهایی توسط مراجع
function clinic_intake_validate_final($in) {
    $errs = array();
    if ($in['s1']['first_name'] === '' && $in['s1']['last_name'] === '') $errs[] = 'نام و نام خانوادگی را وارد کنید.';
    if ($in['s1']['mobile'] === '') $errs[] = 'شماره موبایل معتبر وارد کنید.';
    if (!$in['consent']['t1'] || !$in['consent']['t2'] || !$in['consent']['t3']) $errs[] = 'هر سه بند توافق‌نامه درمانی باید تأیید شود.';
    if (!$in['consent']['accuracy']) $errs[] = 'تیک «صحت اطلاعات واردشده را تأیید می‌کنم» الزامی است.';
    return $errs;
}

// ذخیره اینتیک روی پرونده + همگام‌سازی فیلدهای پایه + وضعیت
function clinic_intake_save($client_id, $in, $as_role) {
    $client_id = (int) $client_id;
    $c = clinic_get_client($client_id);
    if (!$c) return false;
    $old = isset($c['intake']) && is_array($c['intake']) ? $c['intake'] : array();
    // حفظ مهرهای قبلی
    if (isset($old['submitted_at'])) $in['submitted_at'] = $old['submitted_at'];
    if (isset($old['reviewed_at'])) $in['reviewed_at'] = $old['reviewed_at'];
    if (isset($old['reviewed_by'])) $in['reviewed_by'] = $old['reviewed_by'];
    if ($as_role === 'client' && $in['submitted_at'] === '') $in['submitted_at'] = joma_now();
    $s1 = $in['s1'];
    $patch = array('intake' => json_encode($in, JSON_UNESCAPED_UNICODE));
    // همگام‌سازی بخش ۱ با فیلدهای پایه پرونده
    if ($s1['first_name'] !== '') $patch['first_name'] = $s1['first_name'];
    if ($s1['last_name'] !== '') $patch['last_name'] = $s1['last_name'];
    if ($s1['birth_date'] !== '') $patch['birth_date'] = $s1['birth_date'];
    if ($s1['job'] !== '') $patch['job'] = $s1['job'];
    if ($s1['education'] !== '') $patch['education'] = $s1['education'];
    if ($s1['marital'] !== '') $patch['marital'] = $s1['marital'];
    if ($s1['emergency_contact'] !== '') $patch['emergency_contact'] = $s1['emergency_contact'];
    if ($s1['referrer'] !== '') $patch['referrer'] = $s1['referrer'];
    if ($s1['referrer_other'] !== '') $patch['referrer_other'] = $s1['referrer_other'];
    if ($c['first_visit_date'] === '' && $in['submitted_at'] !== '') {
        $patch['first_visit_date'] = substr(jalali_today(), 0, 10);
    }
    if ($as_role === 'client' && $c['intake_status'] === 'none') $patch['intake_status'] = 'self';
    $res = clinic_update_client($client_id, $patch, clinic_my_id());
    return isset($res['ok']);
}

function clinic_mark_reviewed($client_id, $doctor_id) {
    $client_id = (int) $client_id;
    $c = clinic_get_client($client_id);
    if (!$c) return false;
    $in = isset($c['intake']) && is_array($c['intake']) && $c['intake'] ? $c['intake'] : clinic_intake_empty();
    $in['reviewed_at'] = joma_now();
    $in['reviewed_by'] = (int) $doctor_id;
    $res = clinic_update_client($client_id, array(
        'intake' => json_encode($in, JSON_UNESCAPED_UNICODE),
        'intake_status' => 'reviewed',
    ), (int) $doctor_id);
    return isset($res['ok']);
}

function clinic_client_flags($client) {
    $fl = isset($client['intake']['flags']) ? $client['intake']['flags'] : array();
    return array(
        'violence' => !empty($fl['violence']),
        'risks' => isset($fl['risks']) && is_array($fl['risks']) ? $fl['risks'] : array(),
    );
}

function clinic_risk_label($key) {
    $opt = clinic_intake_options();
    return isset($opt['risks'][$key]) ? $opt['risks'][$key] : $key;
}

// ساخت/اتصال حساب ورود مراجع (نام کاربری = موبایل)
function clinic_client_user_ensure($client_id, $password) {
    $c = clinic_get_client($client_id);
    if (!$c) return array('error' => 'پرونده پیدا نشد.');
    if (strlen((string) $password) < 6) return array('error' => 'رمز عبور باید حداقل ۶ نویسه باشد.');
    $mobile = clinic_norm_mobile($c['mobile']);
    if ($mobile === '') return array('error' => 'موبایل پرونده معتبر نیست.');
    $u = clinic_user_by_phone($mobile);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($u) {
        if ($u['role_key'] !== 'client' || !(int)$u['active']) return array('error'=>'حساب کارکنان یا غیرفعال قابل تغییر نیست.');
        $linked=clinic_get_client_by_user($u['id']);
        if ($linked && (int)$linked['id'] !== (int)$client_id) return array('error'=>'این حساب به پرونده دیگری متصل است.');
        $uid = (int) $u['id'];
        clinic_update_user($uid, array('password_hash' => $hash, 'role_key' => 'client', 'phone' => $mobile, 'doctor_id' => (int) $c['doctor_id']));
    } else {
        $uid = clinic_create_user(array(
            'first_name' => $c['first_name'], 'last_name' => $c['last_name'],
            'username' => $mobile, 'email' => $mobile . '@clinic.local', 'phone' => $mobile,
            'job' => 'مراجع', 'password_hash' => $hash,
            'role_key' => 'client', 'doctor_id' => (int) $c['doctor_id'],
        ));
        if (!$uid) return array('error' => 'خطا در ساخت حساب ورود.');
    }
    clinic_update_client((int) $client_id, array('user_id' => $uid), clinic_my_id());
    return array('user_id' => $uid);
}

// ------------------------------------------------------------- نوبت‌ها ---
function clinic_appt_types() {
    return array('present' => 'حضوری', 'online' => 'آنلاین', 'phone' => 'تلفنی');
}

function clinic_appt_statuses() {
    return array(
        'reserved' => 'رزرو شده', 'confirmed' => 'تأیید شده', 'done' => 'برگزار شد',
        'cancel_client' => 'لغو توسط مراجع', 'cancel_clinic' => 'لغو توسط مطب', 'no_show' => 'عدم مراجعه',
    );
}

function clinic_appt_active_statuses() {
    return array('reserved', 'confirmed');
}

function clinic_appt_row($a) {
    if (!$a) return null;
    $a['id'] = (int) $a['id'];
    $a['client_id'] = (int) $a['client_id'];
    $a['doctor_id'] = (int) $a['doctor_id'];
    $a['fee'] = (int) $a['fee'];
    $a['fee_manual'] = (int) $a['fee_manual'];
    $a['late_cancel'] = (int) $a['late_cancel'];
    $a['created_by'] = (int) $a['created_by'];
    $a['remind_sent_at'] = clinic_db_n(isset($a['remind_sent_at']) ? $a['remind_sent_at'] : '');
    return $a;
}

function clinic_check_overlap($doctor_id, $date, $start, $end, $except_id) {
    $row = clinic_db_one(
        "SELECT * FROM `clinic_appointments` WHERE `doctor_id`=? AND `date`=? AND `id`<>? AND `status` IN ('reserved','confirmed') AND `start`<? AND `end`>? LIMIT 1",
        'issss',
        array((int) $doctor_id, $date, (int) $except_id, $end, $start)
    );
    return $row ? clinic_appt_row($row) : null;
}

function clinic_create_appointment($in, $by_user_id) {
    $client = clinic_get_client(isset($in['client_id']) ? $in['client_id'] : 0);
    if (!$client) return array('error' => 'پرونده انتخاب نشده است.');
    if (!clinic_can_access_client($client)) return array('error' => 'به این پرونده دسترسی ندارید.');
    $types = clinic_appt_types();
    $type = isset($in['type']) ? $in['type'] : 'present';
    if (!isset($types[$type])) $type = 'present';
    $date = clinic_valid_jdate(isset($in['date']) ? $in['date'] : '');
    $start = clinic_valid_time(isset($in['start']) ? $in['start'] : '');
    $end = clinic_valid_time(isset($in['end']) ? $in['end'] : '');
    if ($date === '' || $start === '' || $end === '') return array('error' => 'تاریخ و ساعت شروع/پایان معتبر وارد کنید.');
    if ($end <= $start) return array('error' => 'ساعت پایان باید بعد از ساعت شروع باشد.');
    $doctor_id = (int) $client['doctor_id'];
    $ov = clinic_check_overlap($doctor_id, $date, $start, $end, 0);
    if ($ov) {
        $oc = clinic_get_client($ov['client_id']);
        $who = $oc ? clinic_client_display_name($oc) : '';
        return array('error' => 'تداخل ساعت با نوبت ' . $who . ' (' . fa_num($ov['start']) . ' تا ' . fa_num($ov['end']) . ').');
    }
    $fee = clinic_fee_for($doctor_id, $type, (int) $client['id']);
    $now = joma_now();
    $ok = clinic_db_exec(
        'INSERT INTO `clinic_appointments` (`client_id`,`doctor_id`,`date`,`start`,`end`,`type`,`status`,`fee`,`note`,`created_by`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        'iissssssisss',
        array((int) $client['id'], $doctor_id, $date, $start, $end, $type, 'reserved', $fee, trim(isset($in['note']) ? $in['note'] : ''), (int) $by_user_id, $now, $now)
    );
    if (!$ok) return array('error' => 'خطا در ثبت نوبت.');
    return array('id' => clinic_db_insert_id());
}

function clinic_get_appointment($id) {
    $row = clinic_db_one('SELECT * FROM `clinic_appointments` WHERE `id`=? LIMIT 1', 'i', array((int) $id));
    return $row ? clinic_appt_row($row) : null;
}

function clinic_update_appointment($id, $patch, $by_user_id) {
    $id = (int) $id;
    $a = clinic_get_appointment($id);
    if (!$a) return array('error' => 'نوبت پیدا نشد.');
    $client = clinic_get_client($a['client_id']);
    if (!$client || !clinic_can_access_client($client)) return array('error' => 'دسترسی ندارید.');
    $date = isset($patch['date']) ? clinic_valid_jdate($patch['date']) : $a['date'];
    $start = isset($patch['start']) ? clinic_valid_time($patch['start']) : $a['start'];
    $end = isset($patch['end']) ? clinic_valid_time($patch['end']) : $a['end'];
    if ($date === '' || $start === '' || $end === '' || $end <= $start) return array('error' => 'تاریخ/ساعت معتبر نیست.');
    $ov = clinic_check_overlap((int) $a['doctor_id'], $date, $start, $end, $id);
    if ($ov) return array('error' => 'تداخل ساعت با نوبت دیگری از همین دکتر.');
    $type = $a['type'];
    if (isset($patch['type']) && isset(clinic_appt_types()[$patch['type']])) $type = $patch['type'];
    $fee = (int) $a['fee'];
    $fee_manual = (int) $a['fee_manual'];
    if ($type !== $a['type'] && empty($a['fee_manual'])) {
        $fee = clinic_fee_for((int) $a['doctor_id'], $type, (int) $a['client_id']);
    }
    if (isset($patch['fee']) && $patch['fee'] !== null && clinic_can_manage_finance()) {
        $f = clinic_norm_money($patch['fee']);
        if ($f !== null) {
            $fee = $f;
            $fee_manual = 1;
        }
    }
    $note = isset($patch['note']) ? trim($patch['note']) : $a['note'];
    $ok = clinic_db_exec(
        'UPDATE `clinic_appointments` SET `date`=?,`start`=?,`end`=?,`type`=?,`fee`=?,`fee_manual`=?,`note`=?,`updated_at`=? WHERE `id`=?',
        'ssssiiisi',
        array($date, $start, $end, $type, $fee, $fee_manual, $note, joma_now(), $id)
    );
    return $ok ? array('ok' => true) : array('error' => 'به‌روزرسانی ناموفق بود.');
}

function clinic_set_appointment_status($id, $status, $by_user_id, $reason) {
    $sts = clinic_appt_statuses();
    if (!isset($sts[$status])) return array('error' => 'وضعیت نامعتبر است.');
    $id = (int) $id;
    $a = clinic_get_appointment($id);
    if (!$a) return array('error' => 'نوبت پیدا نشد.');
    $client = clinic_get_client($a['client_id']);
    if (!$client || !clinic_can_access_client($client)) return array('error' => 'دسترسی ندارید.');
    // ثبت وضعیت فقط برای کادر مطب
    if (!clinic_is_staff()) return array('error' => 'فقط کادر مطب می‌تواند وضعیت نوبت را تغییر دهد.');
    $late = 0;
    if (($status === 'cancel_client' || $status === 'cancel_clinic')) {
        $set = clinic_get_settings();
        $hours = isset($set['cancel_hours']) ? (int) $set['cancel_hours'] : 24;
        if (clinic_hours_until($a['date'], $a['start']) < $hours && clinic_hours_until($a['date'], $a['start']) > -72) {
            $late = 1; // لغو دیرهنگام (کمتر از ۲۴ ساعت مانده)
        }
    }
    $ok = clinic_db_exec(
        'UPDATE `clinic_appointments` SET `status`=?,`cancel_reason`=?,`late_cancel`=?,`updated_at`=? WHERE `id`=?',
        'ssisi',
        array($status, trim((string) $reason), $late, joma_now(), $id)
    );
    if (!$ok) return array('error' => 'به‌روزرسانی ناموفق بود.');
    return array('ok' => true, 'late' => $late);
}

function clinic_list_appointments($filters) {
    $scope = clinic_scope_doctor_ids();
    $date = isset($filters['date']) ? $filters['date'] : '';
    $from = isset($filters['from']) ? $filters['from'] : '';
    $to = isset($filters['to']) ? $filters['to'] : '';
    $doctor_id = (int) (isset($filters['doctor_id']) ? $filters['doctor_id'] : 0);
    $client_id = (int) (isset($filters['client_id']) ? $filters['client_id'] : 0);
    $status = isset($filters['status']) ? $filters['status'] : '';
    $upcoming = !empty($filters['upcoming']);
    $today = clinic_today();
    $where = array();
    $types = '';
    $vals = array();
    if ($scope !== null) $where[] = '`doctor_id` IN (' . clinic_sql_in($scope) . ')';
    if (clinic_role() === 'client') {
        $mine = clinic_get_client_by_user(clinic_my_id());
        $where[] = '`client_id`=?';
        $types .= 'i';
        $vals[] = $mine ? (int) $mine['id'] : -1;
    }
    if ($date !== '') {
        $where[] = '`date`=?';
        $types .= 's';
        $vals[] = $date;
    }
    if ($from !== '') {
        $where[] = '`date`>=?';
        $types .= 's';
        $vals[] = $from;
    }
    if ($to !== '') {
        $where[] = '`date`<=?';
        $types .= 's';
        $vals[] = $to;
    }
    if ($doctor_id > 0) {
        $where[] = '`doctor_id`=?';
        $types .= 'i';
        $vals[] = $doctor_id;
    }
    if ($client_id > 0) {
        $where[] = '`client_id`=?';
        $types .= 'i';
        $vals[] = $client_id;
    }
    if ($status !== '') {
        $where[] = '`status`=?';
        $types .= 's';
        $vals[] = $status;
    }
    if ($upcoming) {
        $where[] = "`date`>=? AND `status` IN ('reserved','confirmed')";
        $types .= 's';
        $vals[] = $today;
    }
    $sql = 'SELECT * FROM `clinic_appointments`';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY `date`,`start` LIMIT 1000';
    $rows = clinic_db_q($sql, $types, $vals);
    $out = array();
    foreach ($rows as $r) $out[] = clinic_appt_row($r);
    return $out;
}

function clinic_client_next_appointment($client_id) {
    $list = clinic_list_appointments(array('client_id' => $client_id, 'upcoming' => true));
    return $list ? $list[0] : null;
}

function clinic_client_done_count($client_id) {
    $row = clinic_db_one("SELECT COUNT(*) AS c FROM `clinic_appointments` WHERE `client_id`=? AND `status`='done'", 'i', array((int) $client_id));
    return $row ? (int) $row['c'] : 0;
}

// شماره جلسه برای یک نوبت (ترتیب زمانی بین جلسات)
function clinic_session_no_for($appt) {
    $rows = clinic_db_q(
        "SELECT `id` FROM `clinic_appointments` WHERE `client_id`=? AND `status` NOT IN ('cancel_client','cancel_clinic') ORDER BY `date`,`start`,`id`",
        'i',
        array((int) $appt['client_id'])
    );
    $n = 0;
    foreach ($rows as $r) {
        $n++;
        if ((int) $r['id'] === (int) $appt['id']) return $n;
    }
    return $n;
}

// ------------------------------------------------------------- خلاصه‌ها ---
function clinic_note_row($n) {
    if (!$n) return null;
    $n['id'] = (int) $n['id'];
    $n['client_id'] = (int) $n['client_id'];
    $n['doctor_id'] = (int) $n['doctor_id'];
    $n['appointment_id'] = (int) $n['appointment_id'];
    $n['session_no'] = (int) $n['session_no'];
    $n['duration'] = (int) $n['duration'];
    $n['progress'] = (int) $n['progress'];
    $t = isset($n['tags']) ? $n['tags'] : '';
    if ($t === null || $t === '') $n['tags'] = array();
    else {
        $d = json_decode($t, true);
        $n['tags'] = is_array($d) ? $d : array();
    }
    return $n;
}

function clinic_create_note($in, $by_user_id) {
    $client = clinic_get_client(isset($in['client_id']) ? $in['client_id'] : 0);
    if (!$client || !clinic_can_edit_clinical($client)) return array('error' => 'فقط درمانگرِ پرونده می‌تواند خلاصه ثبت کند.');
    $text = trim(isset($in['text']) ? $in['text'] : '');
    if ($text === '') return array('error' => 'متن خلاصه خالی است.');
    $appt_id = (int) (isset($in['appointment_id']) ? $in['appointment_id'] : 0);
    if ($appt_id > 0) {
        $ex = clinic_note_for_appointment($appt_id);
        if ($ex) return array('error' => 'برای این جلسه قبلاً خلاصه ثبت شده است (آن را ویرایش کنید).');
    }
    $tags = array();
    $raw_tags = trim(isset($in['tags']) ? $in['tags'] : '');
    if ($raw_tags !== '') {
        foreach (explode(',', str_replace('،', ',', $raw_tags)) as $t) {
            $t = trim($t);
            if ($t !== '') $tags[] = $t;
        }
    }
    $progress = (int) (isset($in['progress']) ? $in['progress'] : 0);
    if ($progress < 0 || $progress > 5) $progress = 0;
    $session_no = 0;
    if ($appt_id > 0) {
        $appt = clinic_get_appointment($appt_id);
        if ($appt) $session_no = clinic_session_no_for($appt);
    }
    $now = joma_now();
    $ok = clinic_db_exec(
        'INSERT INTO `clinic_notes` (`client_id`,`doctor_id`,`appointment_id`,`kind`,`session_no`,`date`,`duration`,`text`,`tags`,`progress`,`next_plan`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
        'iiiisisssisss',
        array((int) $client['id'], clinic_my_id(), $appt_id, 'session', $session_no, clinic_today(), (int) (isset($in['duration']) ? $in['duration'] : 0), $text, json_encode($tags, JSON_UNESCAPED_UNICODE), $progress, trim(isset($in['next_plan']) ? $in['next_plan'] : ''), $now, $now)
    );
    if (!$ok) return array('error' => 'خطا در ثبت خلاصه.');
    return array('id' => clinic_db_insert_id());
}

function clinic_update_note($id, $patch, $by_user_id) {
    $id = (int) $id;
    $n = clinic_get_note($id);
    if (!$n) return array('error' => 'خلاصه پیدا نشد.');
    $client = clinic_get_client($n['client_id']);
    if (!$client || !clinic_can_edit_clinical($client)) return array('error' => 'دسترسی ندارید.');
    $text = isset($patch['text']) ? trim($patch['text']) : $n['text'];
    if ($text === '') return array('error' => 'متن خلاصه خالی است.');
    $tags = $n['tags'];
    if (isset($patch['tags'])) {
        $tags = array();
        foreach (explode(',', str_replace('،', ',', (string) $patch['tags'])) as $t) {
            $t = trim($t);
            if ($t !== '') $tags[] = $t;
        }
    }
    $progress = isset($patch['progress']) ? (int) $patch['progress'] : (int) $n['progress'];
    if ($progress < 0 || $progress > 5) $progress = 0;
    $ok = clinic_db_exec(
        'UPDATE `clinic_notes` SET `text`=?,`next_plan`=?,`duration`=?,`progress`=?,`tags`=?,`updated_at`=? WHERE `id`=?',
        'ssiissi',
        array($text, isset($patch['next_plan']) ? trim($patch['next_plan']) : $n['next_plan'], isset($patch['duration']) ? max(0, (int) $patch['duration']) : (int) $n['duration'], $progress, json_encode($tags, JSON_UNESCAPED_UNICODE), joma_now(), $id)
    );
    return $ok ? array('ok' => true) : array('error' => 'به‌روزرسانی ناموفق بود.');
}

function clinic_get_note($id) {
    $row = clinic_db_one('SELECT * FROM `clinic_notes` WHERE `id`=? LIMIT 1', 'i', array((int) $id));
    return $row ? clinic_note_row($row) : null;
}

function clinic_note_for_appointment($appt_id) {
    $row = clinic_db_one('SELECT * FROM `clinic_notes` WHERE `appointment_id`=? LIMIT 1', 'i', array((int) $appt_id));
    return $row ? clinic_note_row($row) : null;
}

function clinic_list_notes($client_id) {
    $rows = clinic_db_q('SELECT * FROM `clinic_notes` WHERE `client_id`=? ORDER BY `date` DESC, `id` DESC', 'i', array((int) $client_id));
    $out = array();
    foreach ($rows as $r) $out[] = clinic_note_row($r);
    return $out;
}

function clinic_done_without_note($doctor_id) {
    $rows = clinic_db_q(
        "SELECT a.* FROM `clinic_appointments` a LEFT JOIN `clinic_notes` n ON n.`appointment_id`=a.`id` WHERE a.`doctor_id`=? AND a.`status`='done' AND n.`id` IS NULL ORDER BY a.`date` DESC LIMIT 100",
        'i',
        array((int) $doctor_id)
    );
    $out = array();
    foreach ($rows as $r) $out[] = clinic_appt_row($r);
    return $out;
}

function clinic_save_private_note($client_id, $text, $by_user_id) {
    $client = clinic_get_client($client_id);
    if (!$client || !clinic_can_edit_clinical($client)) return array('error' => 'دسترسی ندارید.');
    $res = clinic_update_client((int) $client_id, array('private_note' => trim((string) $text), 'private_updated_at' => joma_now()), (int) $by_user_id);
    return isset($res['ok']) ? array('ok' => true) : $res;
}

// ------------------------------------------------------------- مالی ---
function clinic_txn_kinds() {
    return array(
        'payment' => 'پرداخت', 'prepay' => 'پیش‌پرداخت',
        'discount' => 'تخفیف', 'charge' => 'بدهکاری دستی',
    );
}

function clinic_pay_methods() {
    return array('cash' => 'نقد', 'card' => 'کارت', 'transfer' => 'انتقال/حواله');
}

function clinic_tariff_defaults() {
    return array(
        array('key' => 'first_present', 'title' => 'ویزیت اول حضوری', 'session_type' => 'present', 'amount' => 0),
        array('key' => 'present', 'title' => 'جلسه حضوری', 'session_type' => 'present', 'amount' => 0),
        array('key' => 'online', 'title' => 'جلسه آنلاین', 'session_type' => 'online', 'amount' => 0),
        array('key' => 'phone', 'title' => 'جلسه تلفنی', 'session_type' => 'phone', 'amount' => 0),
        array('key' => 'test', 'title' => 'تست / خدمات جانبی', 'session_type' => 'other', 'amount' => 0),
    );
}

function clinic_ensure_tariffs() {
    $row = clinic_db_one('SELECT COUNT(*) AS c FROM `clinic_tariffs`', '', array());
    if ($row && (int) $row['c'] > 0) return;
    foreach (clinic_tariff_defaults() as $t) {
        clinic_db_exec(
            'INSERT IGNORE INTO `clinic_tariffs` (`tkey`,`title`,`session_type`,`amount`,`active`) VALUES (?,?,?,?,1)',
            'sssi',
            array($t['key'], $t['title'], $t['session_type'], (int) $t['amount'])
        );
    }
}

function clinic_list_tariffs() {
    clinic_ensure_tariffs();
    $rows = clinic_db_q('SELECT * FROM `clinic_tariffs` ORDER BY `id`', '', array());
    foreach ($rows as $i => $r) {
        $rows[$i]['id'] = (int) $r['id'];
        $rows[$i]['amount'] = (int) $r['amount'];
        $rows[$i]['active'] = (int) $r['active'];
    }
    return $rows;
}

function clinic_save_tariff($id, $title, $amount, $active) {
    if ($title !== '') {
        clinic_db_exec('UPDATE `clinic_tariffs` SET `title`=? WHERE `id`=?', 'si', array($title, (int) $id));
    }
    if ($amount !== null) {
        clinic_db_exec('UPDATE `clinic_tariffs` SET `amount`=? WHERE `id`=?', 'ii', array((int) $amount, (int) $id));
    }
    clinic_db_exec('UPDATE `clinic_tariffs` SET `active`=? WHERE `id`=?', 'ii', array($active ? 1 : 0, (int) $id));
    return true;
}

// حل تعرفه: استثنای مراجع > استثنای دکتر > پایه نوع جلسه
function clinic_fee_for($doctor_id, $session_type, $client_id) {
    $tariffs = clinic_db_q('SELECT * FROM `clinic_tariffs` WHERE `session_type`=? AND `active`=1', 's', array($session_type));
    $base = 0;
    foreach ($tariffs as $t) {
        if ($t['tkey'] === $session_type) $base = (int) $t['amount'];
    }
    $fee = $base;
    $ov = clinic_db_q('SELECT * FROM `clinic_overrides` WHERE `session_type`=?', 's', array($session_type));
    foreach ($ov as $o) {
        if ((int) $o['client_id'] === (int) $client_id && (int) $client_id > 0) return (int) $o['amount'];
    }
    foreach ($ov as $o) {
        if ((int) $o['client_id'] === 0 && (int) $o['doctor_id'] === (int) $doctor_id) $fee = (int) $o['amount'];
    }
    return $fee;
}

function clinic_list_overrides() {
    $rows = clinic_db_q('SELECT * FROM `clinic_overrides` ORDER BY `id` DESC LIMIT 500', '', array());
    foreach ($rows as $i => $r) {
        $rows[$i]['id'] = (int) $r['id'];
        $rows[$i]['doctor_id'] = (int) $r['doctor_id'];
        $rows[$i]['client_id'] = (int) $r['client_id'];
        $rows[$i]['amount'] = (int) $r['amount'];
    }
    return $rows;
}

function clinic_save_override($doctor_id, $client_id, $session_type, $amount) {
    $types = clinic_appt_types();
    if (!isset($types[$session_type])) return array('error' => 'نوع جلسه نامعتبر است.');
    if ($amount === null || $amount < 0) return array('error' => 'مبلغ نامعتبر است.');
    $ex = clinic_db_one(
        'SELECT `id` FROM `clinic_overrides` WHERE `doctor_id`=? AND `client_id`=? AND `session_type`=? LIMIT 1',
        'iis',
        array((int) $doctor_id, (int) $client_id, $session_type)
    );
    if ($ex) {
        clinic_db_exec('UPDATE `clinic_overrides` SET `amount`=? WHERE `id`=?', 'ii', array((int) $amount, (int) $ex['id']));
    } else {
        clinic_db_exec(
            'INSERT INTO `clinic_overrides` (`doctor_id`,`client_id`,`session_type`,`amount`) VALUES (?,?,?,?)',
            'iisi',
            array((int) $doctor_id, (int) $client_id, $session_type, (int) $amount)
        );
    }
    return array('ok' => true);
}

function clinic_delete_override($id) {
    return clinic_db_exec('DELETE FROM `clinic_overrides` WHERE `id`=?', 'i', array((int) $id));
}

function clinic_txn_row($t) {
    if (!$t) return null;
    $t['id'] = (int) $t['id'];
    $t['client_id'] = (int) $t['client_id'];
    $t['doctor_id'] = (int) $t['doctor_id'];
    $t['appointment_id'] = (int) $t['appointment_id'];
    $t['amount'] = (int) $t['amount'];
    $t['created_by'] = (int) $t['created_by'];
    return $t;
}

function clinic_get_transaction($id) {
    $row = clinic_db_one('SELECT * FROM `clinic_transactions` WHERE `id`=? LIMIT 1', 'i', array((int) $id));
    return $row ? clinic_txn_row($row) : null;
}

function clinic_create_transaction($in, $by_user_id) {
    $client = clinic_get_client(isset($in['client_id']) ? $in['client_id'] : 0);
    if (!$client || !clinic_can_access_client($client)) return array('error' => 'دسترسی ندارید.');
    if (!clinic_can_manage_finance() && clinic_role() !== 'doctor') return array('error' => 'فقط کادر اجرایی می‌تواند تراکنش ثبت کند.');
    if (clinic_role() === 'doctor') return array('error' => 'ثبت مالی بر عهده منشی است.');
    $kinds = clinic_txn_kinds();
    $kind = isset($in['kind']) ? $in['kind'] : 'payment';
    if (!isset($kinds[$kind])) return array('error' => 'نوع تراکنش نامعتبر است.');
    $amount = clinic_norm_money(isset($in['amount']) ? $in['amount'] : '');
    if ($amount === null || $amount <= 0) return array('error' => 'مبلغ معتبر وارد کنید.');
    $methods = clinic_pay_methods();
    $method = isset($in['method']) ? $in['method'] : 'card';
    if (!isset($methods[$method])) $method = 'card';
    $date = clinic_valid_jdate(isset($in['date']) ? $in['date'] : '');
    if ($date === '') $date = clinic_today();
    $appt_id = (int) (isset($in['appointment_id']) ? $in['appointment_id'] : 0);
    $ok = clinic_db_exec(
        'INSERT INTO `clinic_transactions` (`client_id`,`doctor_id`,`appointment_id`,`kind`,`amount`,`method`,`ref_no`,`date`,`note`,`created_by`,`created_at`) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        'iiisissssis',
        array((int) $client['id'], (int) $client['doctor_id'], $appt_id, $kind, $amount, $method, trim(isset($in['ref_no']) ? $in['ref_no'] : ''), $date, trim(isset($in['note']) ? $in['note'] : ''), (int) $by_user_id, joma_now())
    );
    if (!$ok) return array('error' => 'خطا در ثبت تراکنش.');
    return array('id' => clinic_db_insert_id());
}

function clinic_delete_transaction($id, $by_user_id) {
    if (!in_array(clinic_role(), array('admin', 'head_secretary'), true)) {
        return array('error' => 'فقط مدیر و منشی ارشد می‌توانند تراکنش را حذف کنند.');
    }
    $id = (int) $id;
    $t = clinic_get_transaction($id);
    if (!$t) return array('error' => 'تراکنش پیدا نشد.');
    $client = clinic_get_client($t['client_id']);
    if ($client && !clinic_can_access_client($client)) return array('error' => 'دسترسی ندارید.');
    clinic_db_exec('DELETE FROM `clinic_transactions` WHERE `id`=?', 'i', array($id));
    clinic_audit('حذف تراکنش مالی', (int) $t['client_id'], 'مبلغ ' . clinic_money($t['amount']) . ' — ' . $t['kind']);
    return array('ok' => true);
}

function clinic_list_transactions($filters) {
    $scope = clinic_scope_doctor_ids();
    $client_id = (int) (isset($filters['client_id']) ? $filters['client_id'] : 0);
    $doctor_id = (int) (isset($filters['doctor_id']) ? $filters['doctor_id'] : 0);
    $from = isset($filters['from']) ? $filters['from'] : '';
    $to = isset($filters['to']) ? $filters['to'] : '';
    $where = array();
    $types = '';
    $vals = array();
    if ($scope !== null) $where[] = '`doctor_id` IN (' . clinic_sql_in($scope) . ')';
    if (clinic_role() === 'client') {
        $mine = clinic_get_client_by_user(clinic_my_id());
        $where[] = '`client_id`=?';
        $types .= 'i';
        $vals[] = $mine ? (int) $mine['id'] : -1;
    }
    if ($client_id > 0) {
        $where[] = '`client_id`=?';
        $types .= 'i';
        $vals[] = $client_id;
    }
    if ($doctor_id > 0) {
        $where[] = '`doctor_id`=?';
        $types .= 'i';
        $vals[] = $doctor_id;
    }
    if ($from !== '') {
        $where[] = '`date`>=?';
        $types .= 's';
        $vals[] = $from;
    }
    if ($to !== '') {
        $where[] = '`date`<=?';
        $types .= 's';
        $vals[] = $to;
    }
    $sql = 'SELECT * FROM `clinic_transactions`';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY `date` DESC, `id` DESC LIMIT 1000';
    $rows = clinic_db_q($sql, $types, $vals);
    $out = array();
    foreach ($rows as $r) $out[] = clinic_txn_row($r);
    return $out;
}

// جمع‌بندی مالی یک مراجع
function clinic_client_totals($client_id) {
    $client_id = (int) $client_id;
    $e = clinic_db_one("SELECT COALESCE(SUM(`fee`),0) AS s FROM `clinic_appointments` WHERE `client_id`=? AND `status` NOT IN ('cancel_client','cancel_clinic')", 'i', array($client_id));
    $expected = $e ? (int) $e['s'] : 0;
    $rows = clinic_db_q('SELECT `kind`, COALESCE(SUM(`amount`),0) AS s FROM `clinic_transactions` WHERE `client_id`=? GROUP BY `kind`', 'i', array($client_id));
    $paid = 0;
    $prepay = 0;
    $discount = 0;
    $charge = 0;
    foreach ($rows as $r) {
        if ($r['kind'] === 'payment') $paid = (int) $r['s'];
        elseif ($r['kind'] === 'prepay') $prepay = (int) $r['s'];
        elseif ($r['kind'] === 'discount') $discount = (int) $r['s'];
        elseif ($r['kind'] === 'charge') $charge = (int) $r['s'];
    }
    return array(
        'expected' => $expected, 'paid' => $paid, 'prepay' => $prepay,
        'discount' => $discount, 'charge' => $charge,
        'debt' => ($expected + $charge) - ($paid + $prepay + $discount),
    );
}

function clinic_debtors($doctor_id) {
    $clients = clinic_list_clients(array('status' => 'active', 'doctor_id' => $doctor_id));
    $out = array();
    foreach ($clients as $c) {
        $t = clinic_client_totals((int) $c['id']);
        if ($t['debt'] > 0) {
            $c['_debt'] = $t['debt'];
            $c['_totals'] = $t;
            $out[] = $c;
        }
    }
    usort($out, function ($x, $y) { return $y['_debt'] - $x['_debt']; });
    return $out;
}

function clinic_income_sum($from, $to, $doctor_id) {
    $txns = clinic_list_transactions(array('from' => $from, 'to' => $to, 'doctor_id' => $doctor_id));
    $sum = 0;
    foreach ($txns as $t) {
        if ($t['kind'] === 'payment' || $t['kind'] === 'prepay') $sum += (int) $t['amount'];
    }
    return $sum;
}

function clinic_per_doctor_income($from, $to) {
    $out = array();
    foreach (clinic_doctors_list() as $d) {
        $out[] = array('doctor' => $d, 'sum' => clinic_income_sum($from, $to, (int) $d['id']));
    }
    return $out;
}

// ------------------------------------------------------------- تنظیمات ---
function clinic_default_settings() {
    return array(
        'reminder_mode' => 'manual', // manual | auto
        'reminder_template' => "سلام {name} عزیز\nیادآوری نوبت {doctor}:\n{date} — ساعت {time}\nلطفاً در صورت نیاز به جابه‌جایی، حداقل ۲۴ ساعت قبل اطلاع دهید.",
        'crisis_text' => 'اگر احساس خطر فوری برای خودتان یا دیگران دارید، لطفاً سریعاً با اورژانس (۱۱۵) یا اورژانس اجتماعی (۱۲۳) تماس بگیرید.',
        'accuracy_text' => 'صحت اطلاعات واردشده را تأیید می‌کنم.',
        'cancel_hours' => 24,
        'sms_api_url' => '',
        'sms_ok_contains' => '',
        'clinic_name' => 'مطب',
        'clinic_contact' => '',
    );
}

function clinic_get_settings() {
    $base = clinic_default_settings();
    $rows = clinic_db_q('SELECT `skey`,`svalue` FROM `clinic_settings`', '', array());
    foreach ($rows as $r) {
        if (isset($base[$r['skey']])) {
            $base[$r['skey']] = $r['svalue'] === null ? '' : $r['svalue'];
        }
    }
    $base['cancel_hours'] = (int) $base['cancel_hours'];
    if ($base['cancel_hours'] < 1) $base['cancel_hours'] = 24;
    return $base;
}

function clinic_save_settings($patch) {
    $allow = array('reminder_mode', 'reminder_template', 'crisis_text', 'accuracy_text', 'cancel_hours', 'sms_api_url', 'sms_ok_contains', 'clinic_name', 'clinic_contact');
    foreach ($allow as $k) {
        if (!isset($patch[$k])) continue;
        if ($k === 'reminder_mode') {
            $v = ($patch[$k] === 'auto') ? 'auto' : 'manual';
        } elseif ($k === 'cancel_hours') {
            $v = (string) max(1, min(168, (int) $patch[$k]));
        } else {
            $v = trim((string) $patch[$k]);
        }
        clinic_db_exec('INSERT INTO `clinic_settings` (`skey`,`svalue`) VALUES (?,?) ON DUPLICATE KEY UPDATE `svalue`=VALUES(`svalue`)', 'ss', array($k, $v));
    }
}

function clinic_reminder_text($appt, $client, $doctor_name) {
    $set = clinic_get_settings();
    $tpl = $set['reminder_template'];
    $rep = array(
        '{name}' => clinic_client_display_name($client),
        '{date}' => clinic_fa_date($appt['date']),
        '{time}' => fa_num($appt['start']),
        '{doctor}' => $doctor_name,
        '{file}' => $client['file_no'],
        '{clinic}' => $set['clinic_name'],
    );
    return str_replace(array_keys($rep), array_values($rep), $tpl);
}

function clinic_mark_reminded($appt_id, $by_user_id) {
    return clinic_db_exec('UPDATE `clinic_appointments` SET `remind_sent_at`=? WHERE `id`=?', 'si', array(joma_now(), (int) $appt_id));
}

// ارسال پیامک خودکار (قالب URL قابل تنظیم با {to} و {text})
function clinic_send_sms($to, $text) {
    $set = clinic_get_settings();
    $url = trim($set['sms_api_url']);
    if ($url === '') return array('ok' => false, 'msg' => 'آدرس سامانه پیامکی در تنظیمات وارد نشده است.');
    $url = str_replace(array('{to}', '{text}'), array(urlencode($to), urlencode($text)), $url);
    $body = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return array('ok' => false, 'msg' => 'خطا در اتصال به سامانه پیامکی.');
        }
    } else {
        $ctx = stream_context_create(array('http' => array('timeout' => 12)));
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) return array('ok' => false, 'msg' => 'خطا در اتصال به سامانه پیامکی.');
    }
    $need = trim($set['sms_ok_contains']);
    if ($need !== '' && strpos((string) $body, $need) === false) {
        return array('ok' => false, 'msg' => 'پاسخ سامانه پیامکی ناموفق بود.');
    }
    return array('ok' => true, 'msg' => 'پیامک ارسال شد.');
}

// ------------------------------------------------------------- ورود گروهی ---
function clinic_import_headers() {
    return array('نام', 'نام خانوادگی', 'موبایل', 'تاریخ تولد (1405-07-01)', 'شغل', 'تحصیلات', 'وضعیت تأهل', 'تماس اضطراری', 'معرف', 'تاریخ اولین مراجعه');
}

function clinic_import_template_csv() {
    $h = clinic_import_headers();
    $out = "\xEF\xBB\xBF" . implode(',', $h) . "\n";
    $out .= "سارا,رضایی,09123456789,1370-05-12,کارمند,کارشناسی,متأهل,09120000000,اینستاگرام,1405-06-01\n";
    return $out;
}

function clinic_parse_csv_file($path) {
    $rows = array();
    $fh = @fopen($path, 'r');
    if (!$fh) return array('error' => 'فایل خوانده نشد.');
    $first = true;
    while (($r = fgetcsv($fh, 8192)) !== false) {
        // اکسل فارسی گاهی با سمی‌کالن جدا می‌کند
        if (count($r) === 1 && strpos($r[0], ';') !== false) $r = str_getcsv($r[0], ';');
        if ($first) {
            $first = false;
            if (isset($r[0])) $r[0] = preg_replace('/^\xEF\xBB\xBF/', '', $r[0]);
            // سطر سرستون را رد کن
            if (isset($r[0]) && strpos($r[0], 'نام') !== false) continue;
        }
        $rows[] = $r;
    }
    fclose($fh);
    return array('rows' => $rows);
}

// خوانش حداقلی xlsx (شیت اول، بدون کتابخانه خارجی)
function clinic_col_to_index($col) {
    $n = 0;
    $len = strlen($col);
    for ($i = 0; $i < $len; $i++) $n = $n * 26 + (ord($col[$i]) - 64);
    return $n - 1;
}

function clinic_parse_xlsx_file($path) {
    if (!class_exists('ZipArchive')) return array('error' => 'روی این سرور ZipArchive فعال نیست؛ لطفاً فایل CSV بدهید.');
    if (!function_exists('simplexml_load_string')) return array('error' => 'روی این سرور SimpleXML فعال نیست؛ لطفاً فایل CSV بدهید.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return array('error' => 'فایل اکسل باز نشد.');
    $strings = array();
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        $xml = @simplexml_load_string($ss);
        if ($xml) {
            foreach ($xml->si as $si) {
                $txt = '';
                foreach ($si->xpath('.//t') as $t) $txt .= (string) $t;
                $strings[] = $txt;
            }
        }
    }
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheet === false) {
        // تلاش برای اولین شیت موجود
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (strpos($name, 'xl/worksheets/sheet') === 0) {
                $sheet = $zip->getFromIndex($i);
                break;
            }
        }
    }
    $zip->close();
    if ($sheet === false) return array('error' => 'شیت اکسل پیدا نشد.');
    $xml = @simplexml_load_string($sheet);
    if (!$xml) return array('error' => 'ساختار اکسل خوانده نشد.');
    $rows = array();
    foreach ($xml->sheetData->row as $row) {
        $cells = array();
        $max = -1;
        foreach ($row->c as $c) {
            $ref = (string) $c['r'];
            $col = preg_replace('/[0-9]/', '', $ref);
            $idx = clinic_col_to_index($col);
            if ($idx > $max) $max = $idx;
            $t = (string) $c['t'];
            if ($t === 's') {
                $vi = (int) $c->v;
                $cells[$idx] = isset($strings[$vi]) ? $strings[$vi] : '';
            } elseif ($t === 'inlineStr') {
                $cells[$idx] = (string) $c->is->t;
            } else {
                $cells[$idx] = isset($c->v) ? (string) $c->v : '';
            }
        }
        $line = array();
        for ($i = 0; $i <= $max; $i++) $line[] = isset($cells[$i]) ? $cells[$i] : '';
        $rows[] = $line;
    }
    // حذف سطر سرستون اگر بود
    if (isset($rows[0][0]) && strpos($rows[0][0], 'نام') !== false) array_shift($rows);
    return array('rows' => $rows);
}

function clinic_import_rows($rows, $doctor_id, $by_user_id) {
    $created = 0;
    $errors = array();
    $line = 1;
    foreach ($rows as $r) {
        $line++;
        $get = function ($i) use ($r) { return trim(isset($r[$i]) ? (string) $r[$i] : ''); };
        if ($get(0) === '' && $get(1) === '' && $get(2) === '') continue; // سطر خالی
        $res = clinic_create_client(array(
            'first_name' => $get(0), 'last_name' => $get(1), 'mobile' => $get(2),
            'birth_date' => $get(3), 'job' => $get(4), 'education' => $get(5),
            'marital' => $get(6), 'emergency_contact' => $get(7), 'referrer' => $get(8),
            'first_visit_date' => $get(9), 'doctor_id' => $doctor_id,
        ), $by_user_id);
        if (isset($res['id'])) {
            $created++;
        } else {
            $errors[] = 'سطر ' . fa_num($line) . ': ' . $res['error'];
        }
    }
    return array('created' => $created, 'errors' => $errors);
}

// ------------------------------------------------------------- لاگ ---
function clinic_audit($action, $client_id, $detail) {
    clinic_db_exec(
        'INSERT INTO `clinic_audit` (`at`,`user_id`,`action`,`client_id`,`detail`) VALUES (?,?,?,?,?)',
        'sisis',
        array(joma_now(), clinic_my_id(), (string) $action, (int) $client_id, (string) $detail)
    );
    // سقف لاگ برای سبکی جدول
    $row = clinic_db_one('SELECT COUNT(*) AS c FROM `clinic_audit`', '', array());
    if ($row && (int) $row['c'] > 3200) {
        $m = clinic_db();
        @mysqli_query($m, 'DELETE FROM `clinic_audit` ORDER BY `id` ASC LIMIT 200');
    }
}

function clinic_list_audit($client_id, $limit) {
    $client_id = (int) $client_id;
    $limit = max(1, min(500, (int) $limit));
    if ($client_id > 0) {
        $rows = clinic_db_q('SELECT * FROM `clinic_audit` WHERE `client_id`=? ORDER BY `id` DESC LIMIT ' . $limit, 'i', array($client_id));
    } else {
        $rows = clinic_db_q('SELECT * FROM `clinic_audit` ORDER BY `id` DESC LIMIT ' . $limit, '', array());
    }
    $out = array();
    foreach ($rows as $a) {
        $a['id'] = (int) $a['id'];
        $a['user_id'] = (int) $a['user_id'];
        $a['client_id'] = (int) $a['client_id'];
        // محدوده دکتر برای لاگ پرونده‌ها
        if ($client_id === 0 && clinic_role() === 'doctor' && $a['client_id'] > 0) {
            $c = clinic_get_client($a['client_id']);
            if (!$c || (int) $c['doctor_id'] !== clinic_my_id()) continue;
        }
        if ($client_id === 0 && clinic_role() === 'secretary' && $a['client_id'] > 0) {
            $c = clinic_get_client($a['client_id']);
            $d = clinic_my_doctor_id();
            if (!$c || (int) $c['doctor_id'] !== $d) continue;
        }
        $out[] = $a;
    }
    return $out;
}

// ------------------------------------------------- داشبورد: آمارها ---
function clinic_count_new_week($doctor_ids) {
    $week_ago = date('Y-m-d H:i:s', time() - 7 * 86400);
    $sql = 'SELECT COUNT(*) AS c FROM `clinic_clients` WHERE `created_at`>=?';
    $types = 's';
    $vals = array($week_ago);
    if ($doctor_ids !== null) {
        $sql .= ' AND `doctor_id` IN (' . clinic_sql_in($doctor_ids) . ')';
    }
    $row = clinic_db_one($sql, $types, $vals);
    return $row ? (int) $row['c'] : 0;
}

function clinic_clients_without_next($doctor_ids) {
    $clients = clinic_list_clients(array('status' => 'active'));
    $out = array();
    foreach ($clients as $c) {
        if ($doctor_ids !== null && !in_array((int) $c['doctor_id'], $doctor_ids, true)) continue;
        $nx = clinic_client_next_appointment((int) $c['id']);
        if (!$nx) $out[] = $c;
        if (count($out) >= 50) break;
    }
    return $out;
}

// ------------------------------------------------- رندر فرم ---
function clinic_h($s) {
    return e((string) $s);
}

function clinic_field_text($name, $label, $value, $placeholder, $dir) {
    $d = $dir ? ' dir="' . $dir . '"' : '';
    return '<label>' . clinic_h($label) . '</label>'
        . '<input name="' . clinic_h($name) . '" value="' . clinic_h($value) . '"'
        . ($placeholder !== '' ? ' placeholder="' . clinic_h($placeholder) . '"' : '') . $d . '>';
}

function clinic_field_textarea($name, $label, $value, $rows, $placeholder) {
    return '<label>' . clinic_h($label) . '</label>'
        . '<textarea name="' . clinic_h($name) . '" rows="' . (int) $rows . '"'
        . ($placeholder !== '' ? ' placeholder="' . clinic_h($placeholder) . '"' : '') . '>'
        . clinic_h($value) . '</textarea>';
}

function clinic_field_radio($name, $label, $options, $value) {
    $h = '<div class="clinic-opts"><span class="clinic-opts-label">' . clinic_h($label) . '</span>';
    foreach ($options as $op) {
        $checked = ($value === $op) ? ' checked' : '';
        $h .= '<label class="check clinic-pill"><input type="radio" name="' . clinic_h($name) . '" value="' . clinic_h($op) . '"' . $checked . '><span>' . clinic_h($op) . '</span></label>';
    }
    $h .= '</div>';
    return $h;
}

function clinic_field_checks($name, $label, $map, $values) {
    if (!is_array($values)) $values = array();
    $h = '<div class="clinic-opts"><span class="clinic-opts-label">' . clinic_h($label) . '</span>';
    foreach ($map as $k => $op) {
        $checked = in_array($k, $values, true) ? ' checked' : '';
        $h .= '<label class="check clinic-pill"><input type="checkbox" name="' . clinic_h($name) . '[]" value="' . clinic_h($k) . '"' . $checked . '><span>' . clinic_h($op) . '</span></label>';
    }
    $h .= '</div>';
    return $h;
}

function clinic_field_select($name, $label, $map, $value, $empty_label) {
    $h = '<label>' . clinic_h($label) . '</label><select name="' . clinic_h($name) . '">';
    if ($empty_label !== null) $h .= '<option value="">' . clinic_h($empty_label) . '</option>';
    foreach ($map as $k => $op) {
        $sel = ((string) $value === (string) $k) ? ' selected' : '';
        $h .= '<option value="' . clinic_h($k) . '"' . $sel . '>' . clinic_h($op) . '</option>';
    }
    $h .= '</select>';
    return $h;
}

function clinic_badge($text, $kind) {
    return '<span class="clinic-badge ' . clinic_h($kind) . '">' . clinic_h($text) . '</span>';
}

function clinic_status_badge($status) {
    $map = array(
        'reserved' => 'b-blue', 'confirmed' => 'b-green', 'done' => 'b-gray',
        'cancel_client' => 'b-red', 'cancel_clinic' => 'b-orange', 'no_show' => 'b-red',
    );
    $sts = clinic_appt_statuses();
    $label = isset($sts[$status]) ? $sts[$status] : $status;
    $cls = isset($map[$status]) ? $map[$status] : 'b-gray';
    return clinic_badge($label, $cls);
}

function clinic_intake_badge($st) {
    $map = array('none' => 'b-orange', 'self' => 'b-blue', 'reviewed' => 'b-green');
    $sts = clinic_intake_statuses();
    $label = isset($sts[$st]) ? $sts[$st] : $st;
    $cls = isset($map[$st]) ? $map[$st] : 'b-gray';
    return clinic_badge($label, $cls);
}

// نمایش فقط‌خوانای فرم اینتیک (برای بازبینی دکتر)
function clinic_intake_view_html($in, $opt) {
    if (!$in || !is_array($in)) return '<p class="hint">فرم جلسه اول هنوز تکمیل نشده است.</p>';
    $row = function ($label, $val) {
        if ($val === '' || $val === null) $val = '—';
        return '<div class="clinic-kv"><span>' . clinic_h($label) . '</span><strong>' . clinic_h($val) . '</strong></div>';
    };
    $rowlist = function ($label, $keys, $map) {
        if (!$keys) $v = '—';
        else {
            $names = array();
            foreach ($keys as $k) $names[] = isset($map[$k]) ? $map[$k] : $k;
            $v = implode('، ', $names);
        }
        return '<div class="clinic-kv"><span>' . clinic_h($label) . '</span><strong>' . clinic_h($v) . '</strong></div>';
    };
    $s1 = isset($in['s1']) ? $in['s1'] : array();
    $s2 = isset($in['s2']) ? $in['s2'] : array();
    $s3 = isset($in['s3']) ? $in['s3'] : array();
    $s4 = isset($in['s4']) ? $in['s4'] : array();
    $s5 = isset($in['s5']) ? $in['s5'] : array();
    $g = function ($a, $k) { return isset($a[$k]) ? $a[$k] : ''; };
    $h = '<div class="clinic-review">';
    $h .= '<h3>۱ — مشخصات مراجع</h3><div class="clinic-kvbox">';
    $h .= $row('نام و نام خانوادگی', trim($g($s1, 'first_name') . ' ' . $g($s1, 'last_name')));
    $h .= $row('تاریخ تولد', $g($s1, 'birth_date') !== '' ? clinic_fa_date($g($s1, 'birth_date')) : '');
    $h .= $row('شغل', $g($s1, 'job')) . $row('تحصیلات', $g($s1, 'education'));
    $h .= $row('وضعیت تأهل', $g($s1, 'marital')) . $row('موبایل', fa_num($g($s1, 'mobile')));
    $h .= $row('تماس اضطراری', $g($s1, 'emergency_contact'));
    $h .= $row('معرف', $g($s1, 'referrer') . ($g($s1, 'referrer_other') !== '' ? ' — ' . $g($s1, 'referrer_other') : ''));
    $h .= '</div>';
    $h .= '<h3>۲ — دلیل مراجعه و وضعیت روانی</h3><div class="clinic-kvbox">';
    $h .= $row('دلیل اصلی مراجعه', $g($s2, 'reason')) . $row('مدت مسئله', $g($s2, 'duration'));
    $h .= $row('سابقه روان‌درمانی', $g($s2, 'prev_therapy') . ($g($s2, 'prev_therapy_note') !== '' ? ' — ' . $g($s2, 'prev_therapy_note') : ''));
    $h .= $rowlist('نشانه‌ها', $g($s2, 'mood'), $opt['mood']);
    $h .= $row('هدف نهایی از درمان', $g($s2, 'goal'));
    $h .= '</div>';
    $h .= '<h3>۳ — روابط عاطفی و زوجی</h3><div class="clinic-kvbox">';
    $h .= $row('وضعیت رابطه', $g($s3, 'rel_status')) . $row('شریک در جریان است؟', $g($s3, 'partner_aware'));
    $h .= $row('ترکیب خانواده اصلی', $g($s3, 'family_mix')) . $row('فضای عاطفی کودکی', $g($s3, 'family_mood'));
    $h .= $rowlist('چالش‌ها', $g($s3, 'rel_issues'), $opt['rel_issues']);
    $h .= $row('بزرگ‌ترین چالش فعلی', $g($s3, 'biggest_challenge'));
    $h .= '</div>';
    $h .= '<h3>۴ — سبک زندگی</h3><div class="clinic-kvbox">';
    $h .= $rowlist('موارد', $g($s4, 'lifestyle'), $opt['lifestyle']);
    $h .= $row('روتین روزانه', $g($s4, 'routine')) . $row('ارزش‌های مهم', $g($s4, 'values'));
    $h .= '</div>';
    $h .= '<h3>۵ — ریسک، ایمنی و توافق‌نامه</h3><div class="clinic-kvbox">';
    $h .= $rowlist('شاخص‌های خطر', $g($s5, 'risks'), $opt['risks']);
    $h .= $row('داروهای روان‌پزشکی', $g($s5, 'psych_meds_note'));
    $h .= $row('تحت نظر پزشک؟', $g($s5, 'under_doctor') . ($g($s5, 'under_doctor_note') !== '' ? ' — ' . $g($s5, 'under_doctor_note') : ''));
    $h .= $row('خواب و اشتها', $g($s5, 'sleep_quality'));
    $h .= $row('بیماری جسمی/جراحی', $g($s5, 'physical') . ($g($s5, 'physical_note') !== '' ? ' — ' . $g($s5, 'physical_note') : ''));
    $h .= '</div>';
    $h .= '</div>';
    return $h;
}

// فرم ویرایش/تکمیل اینتیک (بخش‌بندی‌شده با جزئیات کامل)
function clinic_intake_form_html($in, $opt, $mode) {
    // mode: full (هر ۵ بخش) یا s1 (فقط بخش ۱ برای پرونده دستی)
    $s1 = $in['s1'];
    $h = '';
    $h .= '<fieldset class="clinic-fs"><legend>بخش ۱ — مشخصات مراجع و اطلاعات پایه</legend>';
    $h .= '<div class="field-row"><div>' . clinic_field_text('s1_first_name', 'نام', $s1['first_name'], '', '') . '</div>';
    $h .= '<div>' . clinic_field_text('s1_last_name', 'نام خانوادگی', $s1['last_name'], '', '') . '</div></div>';
    $h .= '<div class="field-row"><div>' . clinic_field_text('s1_birth_date', 'تاریخ تولد (شمسی: ۱۳۷۰-۰۵-۱۲)', $s1['birth_date'], '1370-05-12', 'ltr') . '</div>';
    $h .= '<div>' . clinic_field_text('s1_mobile', 'شماره موبایل *', $s1['mobile'], '09123456789', 'ltr') . '</div></div>';
    $h .= '<div class="field-row"><div>' . clinic_field_text('s1_job', 'شغل', $s1['job'], '', '') . '</div>';
    $h .= '<div>' . clinic_field_text('s1_education', 'سطح تحصیلات', $s1['education'], '', '') . '</div></div>';
    $h .= clinic_field_radio('s1_marital', 'وضعیت تأهل', $opt['marital'], $s1['marital']);
    $h .= clinic_field_text('s1_emergency', 'فرد قابل تماس در شرایط اضطراری (نام و نسبت)', $s1['emergency_contact'], '', '');
    $h .= clinic_field_radio('s1_referrer', 'معرف مراجعه', $opt['referrer'], $s1['referrer']);
    $h .= clinic_field_text('s1_referrer_other', 'توضیح معرف (اگر سایر)', $s1['referrer_other'], '', '');
    $h .= '</fieldset>';
    if ($mode !== 'full') return $h;
    $s2 = $in['s2'];
    $h .= '<fieldset class="clinic-fs"><legend>بخش ۲ — دلیل مراجعه و وضعیت روانی</legend>';
    $h .= clinic_field_text('s2_reason', 'دلیل اصلی مراجعه (به اختصار)', $s2['reason'], '', '');
    $h .= clinic_field_radio('s2_duration', 'از چه زمانی این مسئله آغاز شده؟', $opt['duration'], $s2['duration']);
    $h .= clinic_field_radio('s2_prev', 'آیا قبلاً تجربه روان‌درمانی یا مشاوره داشته‌اید؟', $opt['yesno'], $s2['prev_therapy']);
    $h .= clinic_field_text('s2_prev_note', 'توضیح سابقه درمان (کجا، کی، چه مدت)', $s2['prev_therapy_note'], '', '');
    $h .= clinic_field_checks('s2_mood', 'نشانه‌های فعلی (هر مورد که صدق می‌کند)', $opt['mood'], $s2['mood']);
    $h .= clinic_field_textarea('s2_goal', 'هدف نهایی شما از درمان — «چه تغییری رخ دهد تا احساس کنید مشاوره موفق بوده؟»', $s2['goal'], 2, '');
    $h .= '</fieldset>';
    $s3 = $in['s3'];
    $h .= '<fieldset class="clinic-fs"><legend>بخش ۳ — روابط عاطفی و چالش‌های زوجی</legend>';
    $h .= clinic_field_radio('s3_rel', 'وضعیت رابطه فعلی', $opt['rel_status'], $s3['rel_status']);
    $h .= clinic_field_radio('s3_aware', 'آیا همسر یا شریک عاطفی در جریان مراجعه است؟', $opt['yesno'], $s3['partner_aware']);
    $h .= clinic_field_text('s3_family', 'ترکیب خانواده اصلی (والدین و خواهر/برادر)', $s3['family_mix'], '', '');
    $h .= clinic_field_radio('s3_mood', 'فضای عاطفی خانواده در کودکی', $opt['family_mood'], $s3['family_mood']);
    $h .= clinic_field_checks('s3_issues', 'چالش‌های رابطه‌ای (هر مورد که صدق می‌کند)', $opt['rel_issues'], $s3['rel_issues']);
    $h .= clinic_field_text('s3_challenge', 'بزرگ‌ترین چالش فعلی در رابطه (یک خط)', $s3['biggest_challenge'], '', '');
    $h .= '</fieldset>';
    $s4 = $in['s4'];
    $h .= '<fieldset class="clinic-fs"><legend>بخش ۴ — سبک زندگی و روتین‌ها</legend>';
    $h .= clinic_field_checks('s4_life', 'موارد سبک زندگی (هر مورد که صدق می‌کند)', $opt['lifestyle'], $s4['lifestyle']);
    $h .= clinic_field_textarea('s4_routine', 'روتین روزانه (کار، استراحت، تفریح)', $s4['routine'], 2, '');
    $h .= clinic_field_text('s4_values', 'مهم‌ترین ارزش‌های زندگی (رشد فردی، آرامش، موفقیت مالی، رابطه صمیمانه، ...)', $s4['values'], '', '');
    $h .= '</fieldset>';
    $s5 = $in['s5'];
    $h .= '<fieldset class="clinic-fs"><legend>بخش ۵ — ایمنی و توافق‌نامه درمانی</legend>';
    $h .= clinic_field_checks('s5_risks', 'موارد ایمنی (لطفاً با دقت؛ هر مورد که صدق می‌کند)', $opt['risks'], $s5['risks']);
    $h .= clinic_field_text('s5_meds', 'داروهای روان‌پزشکی (نام و دوز)', $s5['psych_meds_note'], '', '');
    $h .= clinic_field_radio('s5_under', 'آیا تحت نظر پزشک یا روان‌پزشک هستید؟', $opt['yesno'], $s5['under_doctor']);
    $h .= clinic_field_text('s5_under_note', 'توضیح', $s5['under_doctor_note'], '', '');
    $h .= clinic_field_radio('s5_sleep', 'کیفیت خواب و اشتها در ماه گذشته', $opt['sleep_quality'], $s5['sleep_quality']);
    $h .= clinic_field_radio('s5_phys', 'آیا بیماری جسمی، جراحی یا حادثه مهمی داشته‌اید؟', $opt['yesno'], $s5['physical']);
    $h .= clinic_field_text('s5_phys_note', 'توضیح بیماری/جراحی', $s5['physical_note'], '', '');
    $h .= '</fieldset>';
    return $h;
}

function clinic_consent_form_html($consent) {
    $ct = clinic_consent_texts();
    $set = clinic_get_settings();
    $acc = $set['accuracy_text'];
    $ck = function ($name, $on) { return '<input type="checkbox" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . '>'; };
    $h = '<fieldset class="clinic-fs"><legend>توافق‌نامه درمانی</legend>';
    $h .= '<label class="check">' . $ck('c_t1', !empty($consent['t1'])) . '<span><strong>محرمانگی:</strong> ' . clinic_h($ct['t1']) . '</span></label>';
    $h .= '<label class="check">' . $ck('c_t2', !empty($consent['t2'])) . '<span><strong>سیاست مالی و لغو جلسه:</strong> ' . clinic_h($ct['t2']) . '</span></label>';
    $h .= '<label class="check">' . $ck('c_t3', !empty($consent['t3'])) . '<span><strong>فرآیند جلسات:</strong> ' . clinic_h($ct['t3']) . '</span></label>';
    $h .= '<label class="check clinic-acc">' . $ck('c_acc', !empty($consent['accuracy'])) . '<span><strong>' . clinic_h($acc) . '</strong></span></label>';
    $h .= '</fieldset>';
    return $h;
}
