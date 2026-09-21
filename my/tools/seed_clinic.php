<?php
// ابزار ساخت داده اولیه «مدیریت مطب» — فقط CLI
// اجرا (از داخل پوشه my روی هاست/لوکال با PHP):
//   php tools/seed_clinic.php
// این اسکریپت کاربران نمایشی می‌سازد؛ اگر نام‌کاربری‌ای تکراری بود رد می‌شود.
// ⚠️ بعد از ورود اول، حتماً رمزها را عوض کنید.
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTPS'] = 'off';
require dirname(__FILE__) . '/../includes/bootstrap.php';
require dirname(__FILE__) . '/../functions/clinic.php';

function seed_user($username, $first, $last, $phone, $pass, $role, $doctor_id) {
    foreach (store_load()['users'] as $ex) {
        if ($ex['username'] === $username) {
            echo "skip (exists): $username\n";
            return (int) $ex['id'];
        }
    }
    $data = store_load();
    $uid = store_next_id($data);
    $data['users'][] = array(
        'id' => $uid, 'first_name' => $first, 'last_name' => $last,
        'username' => $username, 'email' => $username . '@clinic.local', 'phone' => $phone,
        'job' => $role, 'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
        'role_key' => $role, 'access_level' => 1, 'doctor_id' => (int) $doctor_id,
        'mobile_verified' => 0, 'active' => 1, 'created_at' => joma_now(),
    );
    $data['preferences'][] = array('user_id' => $uid, 'compact_cards' => 0, 'notifications_enabled' => 0);
    store_save($data);
    echo "created: $username / $pass ($role)\n";
    return $uid;
}

echo "== Clinic seed ==\n";
$admin = seed_user('matab_admin', 'مدیر', 'مطب', '09120000001', 'Admin123', 'admin', 0);
$doc1 = seed_user('dr.nouri', 'دکتر', 'نوری', '09120000002', 'Doctor123', 'doctor', 0);
$doc2 = seed_user('dr.karimi', 'دکتر', 'کریمی', '09120000003', 'Doctor123', 'doctor', 0);
seed_user('head.mina', 'مینا', 'احمدی', '09120000004', 'Head1234', 'head_secretary', 0);
seed_user('sec.sara', 'سارا', 'محمدی', '09120000005', 'Sec12345', 'secretary', $doc1);

// تعرفه‌های پیش‌فرض
clinic_ensure_tariffs();
echo "tariffs ok\n";

// تنظیمات اولیه
clinic_save_settings(array('clinic_name' => 'مطب', 'cancel_hours' => 24, 'reminder_mode' => 'manual'));
echo "settings ok\n";

// مراجع نمونه
if (!clinic_get_client_by_mobile('09129998888')) {
    $r = clinic_create_client(array(
        'first_name' => 'مراجع', 'last_name' => 'نمونه', 'mobile' => '09129998888',
        'birth_date' => '1370-01-01', 'job' => 'کارمند', 'education' => 'کارشناسی',
        'marital' => 'متأهل', 'referrer' => 'اینستاگرام', 'doctor_id' => $doc1,
        'first_visit_date' => clinic_today(),
    ), $admin);
    if (isset($r['id'])) echo "sample client: " . $r['file_no'] . "\n";
} else {
    echo "skip (exists): sample client\n";
}

// لینک پذیرش نمونه
$tok = clinic_create_invite($doc1, 0, '', $admin);
echo "sample invite: index.php?p=clinic_intake&t=$tok\n";
echo "DONE — وارد شوید و رمزها را تغییر دهید.\n";
