<?php
// ============================================================================
// CLINIC — ماژول «مدیریت مطب» (my.mirbolouki.com)
// نسخه ۱.۰ — فاز اول: پرونده + فرم جلسه اول + نوبت + مالی + یادآوری + ورود گروهی
// ذخیره‌سازی: file-mode (سازگار با config فعلی)؛ در حالت mysql پیغام راهنما می‌دهد.
// ============================================================================

if (defined('CLINIC_PHP_LOADED')) return;
define('CLINIC_PHP_LOADED', true);

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
        $full = get_user((int) $u['id']);
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

function clinic_doctors_list() {
    $data = store_load();
    $out = array();
    if (!isset($data['users'])) return $out;
    foreach ($data['users'] as $u) {
        if (isset($u['role_key']) && $u['role_key'] === 'doctor' && (!isset($u['active']) || (int) $u['active'] === 1)) {
            $out[] = $u;
        }
    }
    return $out;
}

function clinic_staff_list() {
    $data = store_load();
    $out = array();
    if (!isset($data['users'])) return $out;
    foreach ($data['users'] as $u) {
        if (isset($u['role_key']) && in_array($u['role_key'], array('admin', 'doctor', 'head_secretary', 'secretary'), true)) {
            $out[] = $u;
        }
    }
    return $out;
}

function clinic_user_by_phone($mobile) {
    $m = clinic_norm_mobile($mobile);
    if ($m === '') return null;
    $data = store_load();
    if (!isset($data['users'])) return null;
    foreach ($data['users'] as $u) {
        $up = isset($u['phone']) ? clinic_norm_mobile($u['phone']) : '';
        $un = isset($u['username']) ? clinic_norm_mobile($u['username']) : '';
        if (($up !== '' && $up === $m) || ($un !== '' && $un === $m)) return $u;
    }
    return null;
}

// ------------------------------------------------------------- ابزارها ---
function clinic_norm_digits($s) {
    $s = (string) $s;
    $fa = array('۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٫', '٬', '،');
    $en = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '.', ',', ',');
    return str_replace($fa, $en, $s);
}

function clinic_norm_mobile($m) {
    $m = clinic_norm_digits(trim((string) $m));
    $m = preg_replace('/[^0-9]/', '', $m);
    if (strlen($m) === 10 && substr($m, 0, 1) === '9') $m = '0' . $m;
    if (substr($m, 0, 3) === '980' && strlen($m) === 12) $m = substr($m, 2);
    if (substr($m, 0, 4) === '+980' ) $m = substr($m, 3);
    if (!preg_match('/^09[0-9]{9}$/', $m)) return '';
    return $m;
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

function clinic_next_file_no(&$data) {
    $year = substr(jalali_today(), 0, 4);
    if (!isset($data['clinic_file_seq']) || !is_array($data['clinic_file_seq'])) $data['clinic_file_seq'] = array();
    $n = isset($data['clinic_file_seq'][$year]) ? (int) $data['clinic_file_seq'][$year] : 0;
    $n++;
    $data['clinic_file_seq'][$year] = $n;
    return $year . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

function clinic_empty_client() {
    return array(
        'id' => 0, 'file_no' => '', 'user_id' => 0, 'doctor_id' => 0,
        'first_name' => '', 'last_name' => '', 'birth_date' => '', 'job' => '', 'education' => '',
        'marital' => '', 'mobile' => '', 'emergency_contact' => '', 'referrer' => '', 'referrer_other' => '',
        'first_visit_date' => '', 'status' => 'active',
        'intake_status' => 'none', 'intake' => array(),
        'private_note' => '', 'private_updated_at' => '',
        'created_at' => '', 'created_by' => 0, 'updated_at' => '', 'archived_at' => '',
    );
}

function clinic_create_client($in, $by_user_id) {
    $data = store_load();
    $mobile = clinic_norm_mobile(isset($in['mobile']) ? $in['mobile'] : '');
    if ($mobile === '') return array('error' => 'شماره موبایل معتبر وارد کنید (مثل 09123456789).');
    foreach ($data['clinic_clients'] as $c) {
        if (clinic_norm_mobile($c['mobile']) === $mobile) {
            return array('error' => 'این شماره موبایل قبلاً پرونده دارد (' . $c['file_no'] . ').', 'dup_id' => $c['id']);
        }
    }
    $id = store_next_id($data);
    $c = clinic_empty_client();
    $c['id'] = $id;
    $c['file_no'] = clinic_next_file_no($data);
    $c['first_name'] = trim(isset($in['first_name']) ? $in['first_name'] : '');
    $c['last_name'] = trim(isset($in['last_name']) ? $in['last_name'] : '');
    $c['birth_date'] = clinic_valid_jdate(isset($in['birth_date']) ? $in['birth_date'] : '');
    $c['job'] = trim(isset($in['job']) ? $in['job'] : '');
    $c['education'] = trim(isset($in['education']) ? $in['education'] : '');
    $c['marital'] = trim(isset($in['marital']) ? $in['marital'] : '');
    $c['mobile'] = $mobile;
    $c['emergency_contact'] = trim(isset($in['emergency_contact']) ? $in['emergency_contact'] : '');
    $c['referrer'] = trim(isset($in['referrer']) ? $in['referrer'] : '');
    $c['referrer_other'] = trim(isset($in['referrer_other']) ? $in['referrer_other'] : '');
    $c['doctor_id'] = (int) (isset($in['doctor_id']) ? $in['doctor_id'] : 0);
    $c['first_visit_date'] = clinic_valid_jdate(isset($in['first_visit_date']) ? $in['first_visit_date'] : '');
    if ($c['first_name'] === '' && $c['last_name'] === '') return array('error' => 'نام و نام خانوادگی را وارد کنید.');
    if ($c['doctor_id'] <= 0) return array('error' => 'دکتر معالج را انتخاب کنید.');
    $c['created_at'] = joma_now();
    $c['created_by'] = (int) $by_user_id;
    $c['updated_at'] = joma_now();
    $data['clinic_clients'][] = $c;
    store_save($data);
    return array('id' => $id, 'file_no' => $c['file_no']);
}

function clinic_get_client($id) {
    $data = store_load();
    foreach ($data['clinic_clients'] as $c) {
        if ((int) $c['id'] === (int) $id) return $c;
    }
    return null;
}

function clinic_get_client_by_mobile($mobile) {
    $m = clinic_norm_mobile($mobile);
    if ($m === '') return null;
    $data = store_load();
    foreach ($data['clinic_clients'] as $c) {
        if (clinic_norm_mobile($c['mobile']) === $m) return $c;
    }
    return null;
}

function clinic_get_client_by_user($user_id) {
    $data = store_load();
    foreach ($data['clinic_clients'] as $c) {
        if (isset($c['user_id']) && (int) $c['user_id'] === (int) $user_id) return $c;
    }
    return null;
}

function clinic_update_client($id, $patch, $by_user_id) {
    $data = store_load();
    foreach ($data['clinic_clients'] as $i => $c) {
        if ((int) $c['id'] === (int) $id) {
            // جلوگیری از موبایل تکراری
            if (isset($patch['mobile'])) {
                $nm = clinic_norm_mobile($patch['mobile']);
                if ($nm === '') return array('error' => 'شماره موبایل معتبر نیست.');
                foreach ($data['clinic_clients'] as $o) {
                    if ((int) $o['id'] !== (int) $id && clinic_norm_mobile($o['mobile']) === $nm) {
                        return array('error' => 'این شماره موبایل متعلق به پرونده دیگری است (' . $o['file_no'] . ').');
                    }
                }
                $patch['mobile'] = $nm;
            }
            foreach ($patch as $k => $v) {
                if ($k === 'id' || $k === 'file_no') continue;
                $c[$k] = $v;
            }
            $c['updated_at'] = joma_now();
            $data['clinic_clients'][$i] = $c;
            store_save($data);
            return array('ok' => true);
        }
    }
    return array('error' => 'پرونده پیدا نشد.');
}

function clinic_client_display_name($c) {
    $n = trim($c['first_name'] . ' ' . $c['last_name']);
    return $n !== '' ? $n : 'بدون نام';
}

// فهرست پرونده‌ها با فیلتر و رعایت محدوده دسترسی نقش جاری
function clinic_list_clients($filters) {
    $data = store_load();
    $scope = clinic_scope_doctor_ids();
    $q = clinic_norm_digits(trim(isset($filters['q']) ? $filters['q'] : ''));
    $status = isset($filters['status']) ? $filters['status'] : 'active';
    $doctor_id = (int) (isset($filters['doctor_id']) ? $filters['doctor_id'] : 0);
    $flag = isset($filters['flag']) ? $filters['flag'] : '';
    $out = array();
    foreach ($data['clinic_clients'] as $c) {
        if ($scope !== null && !in_array((int) $c['doctor_id'], $scope, true)) continue;
        if (clinic_role() === 'client') {
            if (!isset($c['user_id']) || (int) $c['user_id'] !== clinic_my_id()) continue;
        }
        if ($status !== '' && $status !== 'all' && $c['status'] !== $status) continue;
        if ($doctor_id > 0 && (int) $c['doctor_id'] !== $doctor_id) continue;
        if ($q !== '') {
            $qn = str_replace(' ', '', $q);
            $hay = str_replace(' ', '', clinic_norm_digits($c['first_name'] . $c['last_name'] . $c['mobile'] . $c['file_no']));
            if (strpos($hay, $qn) === false) continue;
        }
        if ($flag === 'risk') {
            $fl = isset($c['intake']['flags']) ? $c['intake']['flags'] : array();
            $has = (!empty($fl['violence']) || !empty($fl['risks']));
            if (!$has) continue;
        }
        if ($flag === 'nointake') {
            if ($c['intake_status'] !== 'none') continue;
        }
        if ($flag === 'unreviewed') {
            if ($c['intake_status'] !== 'self') continue;
        }
        $out[] = $c;
    }
    usort($out, function ($a, $b) { return strcmp($b['file_no'], $a['file_no']); });
    return $out;
}

// ------------------------------------------------------------- دعوت‌نامه ---
function clinic_create_invite($doctor_id, $client_id, $mobile, $by_user_id) {
    $data = store_load();
    $token = '';
    if (function_exists('openssl_random_pseudo_bytes')) {
        $rnd = openssl_random_pseudo_bytes(16);
        if ($rnd !== false) $token = bin2hex($rnd);
    }
    if ($token === '') $token = md5(uniqid((string) mt_rand(), true) . microtime(true));
    $id = store_next_id($data);
    $data['clinic_invites'][] = array(
        'id' => $id, 'token' => $token,
        'doctor_id' => (int) $doctor_id, 'client_id' => (int) $client_id,
        'mobile' => clinic_norm_mobile($mobile),
        'created_by' => (int) $by_user_id, 'created_at' => joma_now(),
        'used_at' => '', 'expires_at' => date('Y-m-d H:i:s', time() + 7 * 86400),
    );
    store_save($data);
    return $token;
}

function clinic_get_invite_by_token($t) {
    $t = trim((string) $t);
    if ($t === '' || !preg_match('/^[a-f0-9]{16,64}$/', $t)) return null;
    $data = store_load();
    foreach ($data['clinic_invites'] as $inv) {
        if ($inv['token'] === $t) {
            if ($inv['used_at'] !== '') return null;
            if ($inv['expires_at'] !== '' && $inv['expires_at'] < joma_now()) return null;
            return $inv;
        }
    }
    return null;
}

function clinic_burn_invite($id) {
    $data = store_load();
    foreach ($data['clinic_invites'] as $i => $inv) {
        if ((int) $inv['id'] === (int) $id) {
            $data['clinic_invites'][$i]['used_at'] = joma_now();
            store_save($data);
            return true;
        }
    }
    return false;
}

function clinic_invite_url($token) {
    return joma_url('index.php?p=clinic_intake&t=' . $token);
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
    $data = store_load();
    foreach ($data['clinic_clients'] as $i => $c) {
        if ((int) $c['id'] === (int) $client_id) {
            $old = isset($c['intake']) && is_array($c['intake']) ? $c['intake'] : array();
            // حفظ مهرهای قبلی
            if (isset($old['submitted_at'])) $in['submitted_at'] = $old['submitted_at'];
            if (isset($old['reviewed_at'])) $in['reviewed_at'] = $old['reviewed_at'];
            if (isset($old['reviewed_by'])) $in['reviewed_by'] = $old['reviewed_by'];
            if ($as_role === 'client' && $in['submitted_at'] === '') $in['submitted_at'] = joma_now();
            $c['intake'] = $in;
            // همگام‌سازی بخش ۱ با فیلدهای پایه پرونده
            $s1 = $in['s1'];
            if ($s1['first_name'] !== '') $c['first_name'] = $s1['first_name'];
            if ($s1['last_name'] !== '') $c['last_name'] = $s1['last_name'];
            if ($s1['birth_date'] !== '') $c['birth_date'] = $s1['birth_date'];
            if ($s1['job'] !== '') $c['job'] = $s1['job'];
            if ($s1['education'] !== '') $c['education'] = $s1['education'];
            if ($s1['marital'] !== '') $c['marital'] = $s1['marital'];
            if ($s1['emergency_contact'] !== '') $c['emergency_contact'] = $s1['emergency_contact'];
            if ($s1['referrer'] !== '') $c['referrer'] = $s1['referrer'];
            if ($s1['referrer_other'] !== '') $c['referrer_other'] = $s1['referrer_other'];
            if ($c['first_visit_date'] === '' && $in['submitted_at'] !== '') {
                $c['first_visit_date'] = substr(jalali_today(), 0, 10);
            }
            if ($as_role === 'client') {
                if ($c['intake_status'] === 'none') $c['intake_status'] = 'self';
            }
            $c['updated_at'] = joma_now();
            $data['clinic_clients'][$i] = $c;
            store_save($data);
            return true;
        }
    }
    return false;
}

function clinic_mark_reviewed($client_id, $doctor_id) {
    $data = store_load();
    foreach ($data['clinic_clients'] as $i => $c) {
        if ((int) $c['id'] === (int) $client_id) {
            if (!isset($c['intake']) || !is_array($c['intake'])) $c['intake'] = clinic_intake_empty();
            $c['intake']['reviewed_at'] = joma_now();
            $c['intake']['reviewed_by'] = (int) $doctor_id;
            $c['intake_status'] = 'reviewed';
            $c['updated_at'] = joma_now();
            $data['clinic_clients'][$i] = $c;
            store_save($data);
            return true;
        }
    }
    return false;
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
    $data = store_load();
    if ($u) {
        foreach ($data['users'] as $i => $row) {
            if ((int) $row['id'] === (int) $u['id']) {
                $data['users'][$i]['password_hash'] = $hash;
                $data['users'][$i]['role_key'] = 'client';
                $data['users'][$i]['phone'] = $mobile;
                $data['users'][$i]['doctor_id'] = (int) $c['doctor_id'];
                $uid = (int) $u['id'];
                break;
            }
        }
    } else {
        $uid = store_next_id($data);
        $data['users'][] = array(
            'id' => $uid,
            'first_name' => $c['first_name'], 'last_name' => $c['last_name'],
            'username' => $mobile, 'email' => $mobile . '@clinic.local', 'phone' => $mobile,
            'job' => 'مراجع', 'password_hash' => $hash,
            'role_key' => 'client', 'access_level' => 1, 'doctor_id' => (int) $c['doctor_id'],
            'mobile_verified' => 0, 'active' => 1, 'created_at' => joma_now(),
        );
        $data['preferences'][] = array('user_id' => $uid, 'compact_cards' => 0, 'notifications_enabled' => 0);
    }
    foreach ($data['clinic_clients'] as $i => $row) {
        if ((int) $row['id'] === (int) $client_id) {
            $data['clinic_clients'][$i]['user_id'] = $uid;
            $data['clinic_clients'][$i]['updated_at'] = joma_now();
        }
    }
    store_save($data);
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

function clinic_check_overlap($doctor_id, $date, $start, $end, $except_id) {
    $data = store_load();
    foreach ($data['clinic_appointments'] as $a) {
        if ((int) $a['doctor_id'] !== (int) $doctor_id) continue;
        if ($a['date'] !== $date) continue;
        if ((int) $a['id'] === (int) $except_id) continue;
        if (!in_array($a['status'], clinic_appt_active_statuses(), true)) continue;
        if ($start < $a['end'] && $a['start'] < $end) return $a;
    }
    return null;
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
    $data = store_load();
    $id = store_next_id($data);
    $fee = clinic_fee_for($doctor_id, $type, (int) $client['id']);
    $data['clinic_appointments'][] = array(
        'id' => $id, 'client_id' => (int) $client['id'], 'doctor_id' => $doctor_id,
        'date' => $date, 'start' => $start, 'end' => $end, 'type' => $type,
        'status' => 'reserved', 'fee' => $fee,
        'note' => trim(isset($in['note']) ? $in['note'] : ''),
        'remind_sent_at' => '', 'cancel_reason' => '', 'late_cancel' => 0,
        'created_by' => (int) $by_user_id, 'created_at' => joma_now(), 'updated_at' => joma_now(),
    );
    store_save($data);
    return array('id' => $id);
}

function clinic_get_appointment($id) {
    $data = store_load();
    foreach ($data['clinic_appointments'] as $a) {
        if ((int) $a['id'] === (int) $id) return $a;
    }
    return null;
}

function clinic_update_appointment($id, $patch, $by_user_id) {
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
    $data = store_load();
    foreach ($data['clinic_appointments'] as $i => $row) {
        if ((int) $row['id'] === (int) $id) {
            $data['clinic_appointments'][$i]['date'] = $date;
            $data['clinic_appointments'][$i]['start'] = $start;
            $data['clinic_appointments'][$i]['end'] = $end;
            if (isset($patch['type']) && isset(clinic_appt_types()[$patch['type']])) {
                $data['clinic_appointments'][$i]['type'] = $patch['type'];
                // به‌روزرسانی مبلغ طبق تعرفه نوع جدید (فقط اگر دستی تغییر نکرده باشد)
                if (empty($row['fee_manual'])) {
                    $data['clinic_appointments'][$i]['fee'] = clinic_fee_for((int) $row['doctor_id'], $patch['type'], (int) $row['client_id']);
                }
            }
            if (isset($patch['note'])) $data['clinic_appointments'][$i]['note'] = trim($patch['note']);
            if (isset($patch['fee']) && clinic_can_manage_finance()) {
                $fee = clinic_norm_money($patch['fee']);
                if ($fee !== null) {
                    $data['clinic_appointments'][$i]['fee'] = $fee;
                    $data['clinic_appointments'][$i]['fee_manual'] = 1;
                }
            }
            $data['clinic_appointments'][$i]['updated_at'] = joma_now();
            store_save($data);
            return array('ok' => true);
        }
    }
    return array('error' => 'نوبت پیدا نشد.');
}

function clinic_set_appointment_status($id, $status, $by_user_id, $reason) {
    $sts = clinic_appt_statuses();
    if (!isset($sts[$status])) return array('error' => 'وضعیت نامعتبر است.');
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
    $data = store_load();
    foreach ($data['clinic_appointments'] as $i => $row) {
        if ((int) $row['id'] === (int) $id) {
            $data['clinic_appointments'][$i]['status'] = $status;
            $data['clinic_appointments'][$i]['cancel_reason'] = trim((string) $reason);
            $data['clinic_appointments'][$i]['late_cancel'] = $late;
            $data['clinic_appointments'][$i]['updated_at'] = joma_now();
            store_save($data);
            return array('ok' => true, 'late' => $late);
        }
    }
    return array('error' => 'نوبت پیدا نشد.');
}

function clinic_list_appointments($filters) {
    $data = store_load();
    $scope = clinic_scope_doctor_ids();
    $date = isset($filters['date']) ? $filters['date'] : '';
    $from = isset($filters['from']) ? $filters['from'] : '';
    $to = isset($filters['to']) ? $filters['to'] : '';
    $doctor_id = (int) (isset($filters['doctor_id']) ? $filters['doctor_id'] : 0);
    $client_id = (int) (isset($filters['client_id']) ? $filters['client_id'] : 0);
    $status = isset($filters['status']) ? $filters['status'] : '';
    $upcoming = !empty($filters['upcoming']);
    $today = clinic_today();
    $out = array();
    foreach ($data['clinic_appointments'] as $a) {
        if ($scope !== null && !in_array((int) $a['doctor_id'], $scope, true)) continue;
        if (clinic_role() === 'client') {
            $c = clinic_get_client($a['client_id']);
            if (!$c || !isset($c['user_id']) || (int) $c['user_id'] !== clinic_my_id()) continue;
        }
        if ($date !== '' && $a['date'] !== $date) continue;
        if ($from !== '' && $a['date'] < $from) continue;
        if ($to !== '' && $a['date'] > $to) continue;
        if ($doctor_id > 0 && (int) $a['doctor_id'] !== $doctor_id) continue;
        if ($client_id > 0 && (int) $a['client_id'] !== $client_id) continue;
        if ($status !== '' && $a['status'] !== $status) continue;
        if ($upcoming && ($a['date'] < $today || !in_array($a['status'], clinic_appt_active_statuses(), true))) continue;
        $out[] = $a;
    }
    usort($out, function ($x, $y) {
        if ($x['date'] === $y['date']) return strcmp($x['start'], $y['start']);
        return strcmp($x['date'], $y['date']);
    });
    return $out;
}

function clinic_client_next_appointment($client_id) {
    $list = clinic_list_appointments(array('client_id' => $client_id, 'upcoming' => true));
    return $list ? $list[0] : null;
}

function clinic_client_done_count($client_id) {
    $n = 0;
    $data = store_load();
    foreach ($data['clinic_appointments'] as $a) {
        if ((int) $a['client_id'] === (int) $client_id && $a['status'] === 'done') $n++;
    }
    return $n;
}

// شماره جلسه برای یک نوبت (ترتیب زمانی بین جلسات برگزار/فعال)
function clinic_session_no_for($appt) {
    $data = store_load();
    $list = array();
    foreach ($data['clinic_appointments'] as $a) {
        if ((int) $a['client_id'] !== (int) $appt['client_id']) continue;
        if (in_array($a['status'], array('cancel_client', 'cancel_clinic'), true)) continue;
        $list[] = $a;
    }
    usort($list, function ($x, $y) {
        if ($x['date'] === $y['date']) return strcmp($x['start'], $y['start']);
        return strcmp($x['date'], $y['date']);
    });
    $n = 0;
    foreach ($list as $a) {
        $n++;
        if ((int) $a['id'] === (int) $appt['id']) return $n;
    }
    return $n;
}

// ------------------------------------------------------------- خلاصه‌ها ---
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
    $data = store_load();
    $id = store_next_id($data);
    $session_no = 0;
    if ($appt_id > 0) {
        $appt = clinic_get_appointment($appt_id);
        if ($appt) $session_no = clinic_session_no_for($appt);
    }
    $data['clinic_notes'][] = array(
        'id' => $id, 'client_id' => (int) $client['id'], 'doctor_id' => clinic_my_id(),
        'appointment_id' => $appt_id, 'kind' => 'session', 'session_no' => $session_no,
        'date' => clinic_today(), 'duration' => (int) (isset($in['duration']) ? $in['duration'] : 0),
        'text' => $text, 'tags' => $tags, 'progress' => $progress,
        'next_plan' => trim(isset($in['next_plan']) ? $in['next_plan'] : ''),
        'created_at' => joma_now(), 'updated_at' => joma_now(),
    );
    store_save($data);
    return array('id' => $id);
}

function clinic_update_note($id, $patch, $by_user_id) {
    $data = store_load();
    foreach ($data['clinic_notes'] as $i => $n) {
        if ((int) $n['id'] === (int) $id) {
            $client = clinic_get_client($n['client_id']);
            if (!$client || !clinic_can_edit_clinical($client)) return array('error' => 'دسترسی ندارید.');
            if (isset($patch['text'])) {
                $t = trim($patch['text']);
                if ($t === '') return array('error' => 'متن خلاصه خالی است.');
                $data['clinic_notes'][$i]['text'] = $t;
            }
            if (isset($patch['next_plan'])) $data['clinic_notes'][$i]['next_plan'] = trim($patch['next_plan']);
            if (isset($patch['duration'])) $data['clinic_notes'][$i]['duration'] = max(0, (int) $patch['duration']);
            if (isset($patch['progress'])) {
                $p = (int) $patch['progress'];
                $data['clinic_notes'][$i]['progress'] = ($p >= 0 && $p <= 5) ? $p : 0;
            }
            if (isset($patch['tags'])) {
                $tags = array();
                foreach (explode(',', str_replace('،', ',', (string) $patch['tags'])) as $t) {
                    $t = trim($t);
                    if ($t !== '') $tags[] = $t;
                }
                $data['clinic_notes'][$i]['tags'] = $tags;
            }
            $data['clinic_notes'][$i]['updated_at'] = joma_now();
            store_save($data);
            return array('ok' => true);
        }
    }
    return array('error' => 'خلاصه پیدا نشد.');
}

function clinic_note_for_appointment($appt_id) {
    $data = store_load();
    foreach ($data['clinic_notes'] as $n) {
        if ((int) $n['appointment_id'] === (int) $appt_id) return $n;
    }
    return null;
}

function clinic_list_notes($client_id) {
    $data = store_load();
    $out = array();
    foreach ($data['clinic_notes'] as $n) {
        if ((int) $n['client_id'] === (int) $client_id) $out[] = $n;
    }
    usort($out, function ($x, $y) { return strcmp($y['date'] . $y['id'], $x['date'] . $x['id']); });
    return $out;
}

function clinic_done_without_note($doctor_id) {
    $data = store_load();
    $noted = array();
    foreach ($data['clinic_notes'] as $n) {
        if ((int) $n['appointment_id'] > 0) $noted[(int) $n['appointment_id']] = true;
    }
    $out = array();
    foreach ($data['clinic_appointments'] as $a) {
        if ((int) $a['doctor_id'] !== (int) $doctor_id) continue;
        if ($a['status'] !== 'done') continue;
        if (isset($noted[(int) $a['id']])) continue;
        $out[] = $a;
    }
    usort($out, function ($x, $y) { return strcmp($y['date'], $x['date']); });
    return $out;
}

function clinic_save_private_note($client_id, $text, $by_user_id) {
    $client = clinic_get_client($client_id);
    if (!$client || !clinic_can_edit_clinical($client)) return array('error' => 'دسترسی ندارید.');
    $data = store_load();
    foreach ($data['clinic_clients'] as $i => $c) {
        if ((int) $c['id'] === (int) $client_id) {
            $data['clinic_clients'][$i]['private_note'] = trim((string) $text);
            $data['clinic_clients'][$i]['private_updated_at'] = joma_now();
            $data['clinic_clients'][$i]['updated_at'] = joma_now();
            store_save($data);
            return array('ok' => true);
        }
    }
    return array('error' => 'پرونده پیدا نشد.');
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
    $data = store_load();
    if (!empty($data['clinic_tariffs'])) return;
    foreach (clinic_tariff_defaults() as $t) {
        $t['id'] = store_next_id($data);
        $t['active'] = 1;
        $data['clinic_tariffs'][] = $t;
    }
    store_save($data);
}

function clinic_list_tariffs() {
    clinic_ensure_tariffs();
    $data = store_load();
    return $data['clinic_tariffs'];
}

function clinic_save_tariff($id, $title, $amount, $active) {
    $data = store_load();
    foreach ($data['clinic_tariffs'] as $i => $t) {
        if ((int) $t['id'] === (int) $id) {
            if ($title !== '') $data['clinic_tariffs'][$i]['title'] = $title;
            if ($amount !== null) $data['clinic_tariffs'][$i]['amount'] = $amount;
            $data['clinic_tariffs'][$i]['active'] = $active ? 1 : 0;
            store_save($data);
            return true;
        }
    }
    return false;
}

// حل تعرفه: استثنای مراجع > استثنای دکتر > پایه نوع جلسه
function clinic_fee_for($doctor_id, $session_type, $client_id) {
    $data = store_load();
    $base = 0;
    if (isset($data['clinic_tariffs'])) {
        foreach ($data['clinic_tariffs'] as $t) {
            if ($t['session_type'] === $session_type && !empty($t['active'])) {
                // ویزیت اول جداست؛ مبلغ پایه = ردیف هم‌نام نوع جلسه
                if ($t['key'] === $session_type) $base = (int) $t['amount'];
            }
        }
    }
    $fee = $base;
    if (isset($data['clinic_overrides'])) {
        foreach ($data['clinic_overrides'] as $o) {
            if ($o['session_type'] !== $session_type) continue;
            if ((int) $o['client_id'] === (int) $client_id && (int) $client_id > 0) return (int) $o['amount'];
        }
        foreach ($data['clinic_overrides'] as $o) {
            if ($o['session_type'] !== $session_type) continue;
            if ((int) $o['client_id'] === 0 && (int) $o['doctor_id'] === (int) $doctor_id) $fee = (int) $o['amount'];
        }
    }
    return $fee;
}

function clinic_list_overrides() {
    $data = store_load();
    return isset($data['clinic_overrides']) ? $data['clinic_overrides'] : array();
}

function clinic_save_override($doctor_id, $client_id, $session_type, $amount) {
    $types = clinic_appt_types();
    if (!isset($types[$session_type])) return array('error' => 'نوع جلسه نامعتبر است.');
    if ($amount === null || $amount < 0) return array('error' => 'مبلغ نامعتبر است.');
    $data = store_load();
    // اگر مشابه بود به‌روزرسانی
    foreach ($data['clinic_overrides'] as $i => $o) {
        if ((int) $o['doctor_id'] === (int) $doctor_id && (int) $o['client_id'] === (int) $client_id && $o['session_type'] === $session_type) {
            $data['clinic_overrides'][$i]['amount'] = $amount;
            store_save($data);
            return array('ok' => true);
        }
    }
    $data['clinic_overrides'][] = array(
        'id' => store_next_id($data), 'doctor_id' => (int) $doctor_id, 'client_id' => (int) $client_id,
        'session_type' => $session_type, 'amount' => $amount,
    );
    store_save($data);
    return array('ok' => true);
}

function clinic_delete_override($id) {
    $data = store_load();
    foreach ($data['clinic_overrides'] as $i => $o) {
        if ((int) $o['id'] === (int) $id) {
            array_splice($data['clinic_overrides'], $i, 1);
            store_save($data);
            return true;
        }
    }
    return false;
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
    $data = store_load();
    $id = store_next_id($data);
    $data['clinic_transactions'][] = array(
        'id' => $id, 'client_id' => (int) $client['id'], 'doctor_id' => (int) $client['doctor_id'],
        'appointment_id' => $appt_id, 'kind' => $kind, 'amount' => $amount, 'method' => $method,
        'ref_no' => trim(isset($in['ref_no']) ? $in['ref_no'] : ''),
        'date' => $date, 'note' => trim(isset($in['note']) ? $in['note'] : ''),
        'created_by' => (int) $by_user_id, 'created_at' => joma_now(),
    );
    store_save($data);
    return array('id' => $id);
}

function clinic_delete_transaction($id, $by_user_id) {
    if (!in_array(clinic_role(), array('admin', 'head_secretary'), true)) {
        return array('error' => 'فقط مدیر و منشی ارشد می‌توانند تراکنش را حذف کنند.');
    }
    $data = store_load();
    foreach ($data['clinic_transactions'] as $i => $t) {
        if ((int) $t['id'] === (int) $id) {
            $client = clinic_get_client($t['client_id']);
            if ($client && !clinic_can_access_client($client)) return array('error' => 'دسترسی ندارید.');
            array_splice($data['clinic_transactions'], $i, 1);
            store_save($data);
            clinic_audit('حذف تراکنش مالی', (int) $t['client_id'], 'مبلغ ' . clinic_money($t['amount']) . ' — ' . $t['kind']);
            return array('ok' => true);
        }
    }
    return array('error' => 'تراکنش پیدا نشد.');
}

function clinic_list_transactions($filters) {
    $data = store_load();
    $scope = clinic_scope_doctor_ids();
    $client_id = (int) (isset($filters['client_id']) ? $filters['client_id'] : 0);
    $doctor_id = (int) (isset($filters['doctor_id']) ? $filters['doctor_id'] : 0);
    $from = isset($filters['from']) ? $filters['from'] : '';
    $to = isset($filters['to']) ? $filters['to'] : '';
    $out = array();
    if (!isset($data['clinic_transactions'])) return $out;
    foreach ($data['clinic_transactions'] as $t) {
        if ($scope !== null && !in_array((int) $t['doctor_id'], $scope, true)) continue;
        if (clinic_role() === 'client') {
            $c = clinic_get_client($t['client_id']);
            if (!$c || !isset($c['user_id']) || (int) $c['user_id'] !== clinic_my_id()) continue;
        }
        if ($client_id > 0 && (int) $t['client_id'] !== $client_id) continue;
        if ($doctor_id > 0 && (int) $t['doctor_id'] !== $doctor_id) continue;
        if ($from !== '' && $t['date'] < $from) continue;
        if ($to !== '' && $t['date'] > $to) continue;
        $out[] = $t;
    }
    usort($out, function ($x, $y) {
        if ($x['date'] === $y['date']) return $y['id'] - $x['id'];
        return strcmp($y['date'], $x['date']);
    });
    return $out;
}

// جمع‌بندی مالی یک مراجع
function clinic_client_totals($client_id) {
    $data = store_load();
    $expected = 0;
    if (isset($data['clinic_appointments'])) {
        foreach ($data['clinic_appointments'] as $a) {
            if ((int) $a['client_id'] !== (int) $client_id) continue;
            if (in_array($a['status'], array('cancel_client', 'cancel_clinic'), true)) continue;
            $expected += (int) (isset($a['fee']) ? $a['fee'] : 0);
        }
    }
    $paid = 0;
    $prepay = 0;
    $discount = 0;
    $charge = 0;
    if (isset($data['clinic_transactions'])) {
        foreach ($data['clinic_transactions'] as $t) {
            if ((int) $t['client_id'] !== (int) $client_id) continue;
            if ($t['kind'] === 'payment') $paid += (int) $t['amount'];
            elseif ($t['kind'] === 'prepay') $prepay += (int) $t['amount'];
            elseif ($t['kind'] === 'discount') $discount += (int) $t['amount'];
            elseif ($t['kind'] === 'charge') $charge += (int) $t['amount'];
        }
    }
    $total_debt_value = $expected + $charge;
    $total_paid_value = $paid + $prepay + $discount;
    return array(
        'expected' => $expected, 'paid' => $paid, 'prepay' => $prepay,
        'discount' => $discount, 'charge' => $charge,
        'debt' => $total_debt_value - $total_paid_value,
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
        'reminder_template' => "سلام {name} عزیز 🌸\nیادآوری نوبت {doctor}:\n📅 {date} — ساعت {time}\nلطفاً در صورت نیاز به جابه‌جایی، حداقل ۲۴ ساعت قبل اطلاع دهید.",
        'crisis_text' => "اگر احساس خطر فوری برای خودتان یا دیگران دارید، لطفاً سریعاً با اورژانس (۱۱۵) یا اورژانس اجتماعی (۱۲۳) تماس بگیرید.",
        'accuracy_text' => 'صحت اطلاعات واردشده را تأیید می‌کنم.',
        'cancel_hours' => 24,
        'sms_api_url' => '',
        'sms_ok_contains' => '',
        'clinic_name' => 'مطب',
        'clinic_contact' => '',
    );
}

function clinic_get_settings() {
    $data = store_load();
    $base = clinic_default_settings();
    if (isset($data['clinic_settings']) && is_array($data['clinic_settings'])) {
        foreach ($base as $k => $v) {
            if (isset($data['clinic_settings'][$k])) $base[$k] = $data['clinic_settings'][$k];
        }
    }
    return $base;
}

function clinic_save_settings($patch) {
    $data = store_load();
    if (!isset($data['clinic_settings']) || !is_array($data['clinic_settings'])) {
        $data['clinic_settings'] = clinic_default_settings();
    }
    $allow = array('reminder_mode', 'reminder_template', 'crisis_text', 'accuracy_text', 'cancel_hours', 'sms_api_url', 'sms_ok_contains', 'clinic_name', 'clinic_contact');
    foreach ($allow as $k) {
        if (!isset($patch[$k])) continue;
        if ($k === 'reminder_mode') {
            $data['clinic_settings'][$k] = ($patch[$k] === 'auto') ? 'auto' : 'manual';
        } elseif ($k === 'cancel_hours') {
            $data['clinic_settings'][$k] = max(1, min(168, (int) $patch[$k]));
        } else {
            $data['clinic_settings'][$k] = trim((string) $patch[$k]);
        }
    }
    store_save($data);
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
    $data = store_load();
    foreach ($data['clinic_appointments'] as $i => $a) {
        if ((int) $a['id'] === (int) $appt_id) {
            $data['clinic_appointments'][$i]['remind_sent_at'] = joma_now();
            store_save($data);
            return true;
        }
    }
    return false;
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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
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
    $data = store_load();
    $id = store_next_id($data);
    $data['clinic_audit'][] = array(
        'id' => $id, 'at' => joma_now(), 'user_id' => clinic_my_id(),
        'action' => (string) $action, 'client_id' => (int) $client_id, 'detail' => (string) $detail,
    );
    // سقف لاگ برای سبکی فایل
    if (count($data['clinic_audit']) > 3000) {
        $data['clinic_audit'] = array_slice($data['clinic_audit'], -3000);
    }
    store_save($data);
}

function clinic_list_audit($client_id, $limit) {
    $data = store_load();
    $out = array();
    if (!isset($data['clinic_audit'])) return $out;
    foreach (array_reverse($data['clinic_audit']) as $a) {
        if ($client_id > 0 && (int) $a['client_id'] !== (int) $client_id) continue;
        // محدوده دکتر برای لاگ پرونده‌ها
        if ($client_id === 0 && clinic_role() === 'doctor') {
            if ((int) $a['client_id'] > 0) {
                $c = clinic_get_client($a['client_id']);
                if (!$c || (int) $c['doctor_id'] !== clinic_my_id()) continue;
            }
        }
        if ($client_id === 0 && clinic_role() === 'secretary') {
            if ((int) $a['client_id'] > 0) {
                $c = clinic_get_client($a['client_id']);
                $d = clinic_my_doctor_id();
                if (!$c || (int) $c['doctor_id'] !== $d) continue;
            }
        }
        $out[] = $a;
        if (count($out) >= $limit) break;
    }
    return $out;
}

// ------------------------------------------------- داشبورد: آمارها ---
function clinic_count_new_week($doctor_ids) {
    $today = clinic_today();
    $week_ago = date('Y-m-d H:i:s', time() - 7 * 86400);
    $data = store_load();
    $n = 0;
    foreach ($data['clinic_clients'] as $c) {
        if ($doctor_ids !== null && !in_array((int) $c['doctor_id'], $doctor_ids, true)) continue;
        if ($c['created_at'] >= $week_ago) $n++;
    }
    return $n;
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
