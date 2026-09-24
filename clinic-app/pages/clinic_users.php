<?php
// مدیریت کاربران مطب (مدیر سیستم)
clinic_require_admin();
$msg = '';
$msg_ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clinic_action'])) {
    csrf_check();
    $act = $_POST['clinic_action'];
    if ($act === 'user_create') {
        $username = strtolower(trim(isset($_POST['username']) ? $_POST['username'] : ''));
        $phone = clinic_norm_mobile(isset($_POST['phone']) ? $_POST['phone'] : '');
        $pass = isset($_POST['password']) ? (string) $_POST['password'] : '';
        $r = isset($_POST['role_key']) ? $_POST['role_key'] : '';
        $roles = clinic_roles();
        if ($username === '' || !preg_match('/^[a-z0-9_\.]{3,30}$/', $username)) {
            $msg = 'نام کاربری لاتین معتبر (حداقل ۳ حرف) وارد کنید.';
        } elseif (clinic_username_taken($username)) {
            $msg = 'این نام کاربری قبلاً گرفته شده است.';
        } elseif (strlen($pass) < 6) {
            $msg = 'رمز عبور حداقل ۶ نویسه باشد.';
        } elseif (!isset($roles[$r]) || $r === 'client') {
            $msg = 'نقش نامعتبر است. (حساب مراجع از داخل پرونده ساخته می‌شود)';
        } else {
            $email = strtolower(trim(isset($_POST['email']) ? $_POST['email'] : ''));
            if ($email === '') $email = $username . '@clinic.local';
            if (clinic_email_taken($email)) $email = $username . '+' . time() . '@clinic.local';
            $doctor_id = (int) (isset($_POST['doctor_id']) ? $_POST['doctor_id'] : 0);
            if ($r === 'secretary' && $doctor_id <= 0) {
                $msg = 'برای منشی دکتر، دکتر منتسب را انتخاب کنید.';
            } else {
                $uid = clinic_create_user(array(
                    'first_name' => trim(isset($_POST['first_name']) ? $_POST['first_name'] : ''),
                    'last_name' => trim(isset($_POST['last_name']) ? $_POST['last_name'] : ''),
                    'username' => $username, 'email' => $email, 'phone' => $phone,
                    'job' => $roles[$r], 'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                    'role_key' => $r, 'doctor_id' => $r === 'secretary' ? $doctor_id : 0,
                ));
                if (!$uid) {
                    $msg = 'خطا در ساخت کاربر. دوباره تلاش کنید.';
                } else {
                    clinic_audit('ساخت کاربر مطب', 0, $username . ' — ' . $roles[$r]);
                    $msg = 'کاربر ساخته شد: ' . $username;
                    $msg_ok = true;
                }
            }
        }
    }
    if ($act === 'user_update') {
        $uid = (int) (isset($_POST['user_id']) ? $_POST['user_id'] : 0);
        $target = clinic_get_user($uid);
        if (!$target) {
            $msg = 'کاربر پیدا نشد.';
        } else {
            $patch = array(
                'first_name' => trim(isset($_POST['first_name']) ? $_POST['first_name'] : $target['first_name']),
                'last_name' => trim(isset($_POST['last_name']) ? $_POST['last_name'] : $target['last_name']),
                'active' => !empty($_POST['active']) ? 1 : 0,
            );
            $ph = clinic_norm_mobile(isset($_POST['phone']) ? $_POST['phone'] : '');
            if ($ph !== '') $patch['phone'] = $ph;
            if ($target['role_key'] === 'secretary' && isset($_POST['doctor_id'])) {
                $patch['doctor_id'] = (int) $_POST['doctor_id'];
            }
            $np = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
            if ($np !== '') {
                if (strlen($np) < 6) {
                    $msg = 'رمز جدید حداقل ۶ نویسه باشد.';
                } else {
                    $patch['password_hash'] = password_hash($np, PASSWORD_DEFAULT);
                }
            }
            if ($msg === '') {
                clinic_update_user($uid, $patch);
                clinic_audit('ویرایش کاربر مطب', 0, $target['username']);
                $msg = 'کاربر به‌روز شد.';
                $msg_ok = true;
            }
        }
    }
}

joma_header('کاربران مطب', array(array('label' => 'مدیریت مطب', 'href' => joma_url('index.php?p=clinic_dashboard')), array('label' => 'کاربران')));
echo '<div class="card"><h1>👥 کاربران مطب</h1>';
echo '<p class="hint">بعد از تغییر نقش/غیرفعال‌سازی، کاربر باید دوباره وارد شود. حساب مراجعین از داخل پرونده ساخته می‌شود.</p>';
if ($msg !== '') echo $msg_ok ? '<div class="clinic-alert green">' . clinic_h($msg) . '</div>' : '<p class="bad">' . clinic_h($msg) . '</p>';
echo '</div>';

$staff = clinic_staff_list();
$roles = clinic_roles();
echo '<div class="card"><h2>کادر فعلی</h2>';
echo '<div class="table-wrap"><table class="clinic-table"><tr><th>نام</th><th>نام کاربری</th><th>نقش</th><th>منتسب</th><th>وضعیت</th><th></th></tr>';
foreach ($staff as $s) {
    $assign = '';
    if ($s['role_key'] === 'secretary' && !empty($s['doctor_id'])) {
        $dd = clinic_get_user((int) $s['doctor_id']);
        $assign = $dd ? clinic_user_display($dd) : '';
    }
    $active = !isset($s['active']) || (int) $s['active'] === 1;
    echo '<tr><td>' . clinic_h(clinic_user_display($s)) . '</td><td>' . clinic_h($s['username']) . '</td>';
    echo '<td>' . clinic_h(isset($roles[$s['role_key']]) ? $roles[$s['role_key']] : $s['role_key']) . '</td>';
    echo '<td>' . clinic_h($assign) . '</td>';
    echo '<td>' . ($active ? clinic_badge('فعال', 'b-green') : clinic_badge('غیرفعال', 'b-gray')) . '</td>';
    echo '<td><a class="btn btn-small" href="' . e(joma_url('index.php?p=clinic_users&edit=' . (int) $s['id'])) . '">ویرایش</a></td></tr>';
}
echo '</table></div></div>';

$edit_id = (int) (isset($_GET['edit']) ? $_GET['edit'] : 0);
if ($edit_id > 0) {
    $eu = clinic_get_user($edit_id);
    if ($eu) {
        $eu_active = !isset($eu['active']) || (int) $eu['active'] === 1;
        echo '<div class="card"><h2>ویرایش — ' . clinic_h(clinic_user_display($eu)) . '</h2>';
        echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_users')) . '">';
        echo csrf_field() . '<input type="hidden" name="clinic_action" value="user_update"><input type="hidden" name="user_id" value="' . $edit_id . '">';
        echo '<div class="field-row"><div>' . clinic_field_text('first_name', 'نام', $eu['first_name'], '', '') . '</div>';
        echo '<div>' . clinic_field_text('last_name', 'نام خانوادگی', $eu['last_name'], '', '') . '</div></div>';
        echo clinic_field_text('phone', 'موبایل', isset($eu['phone']) ? $eu['phone'] : '', '', 'ltr');
        if ($eu['role_key'] === 'secretary') {
            $dmap = array();
            foreach (clinic_doctors_list() as $d) $dmap[$d['id']] = clinic_user_display($d);
            echo clinic_field_select('doctor_id', 'دکتر منتسب', $dmap, isset($eu['doctor_id']) ? $eu['doctor_id'] : '', null);
        }
        echo '<label class="check"><input type="checkbox" name="active" value="1"' . ($eu_active ? ' checked' : '') . '><span>حساب فعال باشد</span></label>';
        echo clinic_field_text('new_password', 'رمز جدید (خالی = بدون تغییر)', '', '', 'ltr');
        echo '<p><button class="btn" type="submit">ذخیره</button></p></form></div>';
    }
}

echo '<div class="card"><h2>➕ کاربر جدید (دکتر / منشی)</h2>';
echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_users')) . '">';
echo csrf_field() . '<input type="hidden" name="clinic_action" value="user_create">';
echo '<div class="field-row"><div>' . clinic_field_text('first_name', 'نام', '', '', '') . '</div>';
echo '<div>' . clinic_field_text('last_name', 'نام خانوادگی', '', '', '') . '</div></div>';
echo '<div class="field-row"><div>' . clinic_field_text('username', 'نام کاربری (لاتین)', '', 'dr.ahmadi', 'ltr') . '</div>';
echo '<div>' . clinic_field_text('phone', 'موبایل', '', '09123456789', 'ltr') . '</div></div>';
echo clinic_field_text('password', 'رمز عبور', '', '', 'ltr');
$rmap = array('doctor' => 'دکتر', 'head_secretary' => 'منشی ارشد', 'secretary' => 'منشی دکتر', 'admin' => 'مدیر سیستم');
echo clinic_field_select('role_key', 'نقش', $rmap, 'doctor', null);
$dmap = array('0' => '—');
foreach (clinic_doctors_list() as $d) $dmap[$d['id']] = clinic_user_display($d);
echo clinic_field_select('doctor_id', 'دکتر منتسب (فقط برای منشی دکتر)', $dmap, '0', null);
echo '<p><button class="btn" type="submit">ساخت کاربر</button></p></form></div>';

joma_footer();
