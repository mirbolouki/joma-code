<?php
// فهرست پرونده‌ها (بایگانی مطب) + پرونده دستی + لینک پذیرش
clinic_require_login();
$role = clinic_role();
if ($role === 'client') {
    joma_redirect('index.php?p=clinic_client');
}
if (!clinic_is_staff()) {
    joma_redirect('index.php?p=dashboard');
}

$msg = '';
$msg_ok = false;
$new_invite_link = '';

// ---- ثبت پرونده دستی ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clinic_action']) && $_POST['clinic_action'] === 'create_client') {
    csrf_check();
    $doctor_id = (int) (isset($_POST['doctor_id']) ? $_POST['doctor_id'] : 0);
    if ($role === 'doctor') $doctor_id = clinic_my_id();
    if ($role === 'secretary') $doctor_id = clinic_my_doctor_id();
    $res = clinic_create_client(array(
        'first_name' => isset($_POST['first_name']) ? $_POST['first_name'] : '',
        'last_name' => isset($_POST['last_name']) ? $_POST['last_name'] : '',
        'mobile' => isset($_POST['mobile']) ? $_POST['mobile'] : '',
        'birth_date' => isset($_POST['birth_date']) ? $_POST['birth_date'] : '',
        'job' => isset($_POST['job']) ? $_POST['job'] : '',
        'education' => isset($_POST['education']) ? $_POST['education'] : '',
        'marital' => isset($_POST['marital']) ? $_POST['marital'] : '',
        'emergency_contact' => isset($_POST['emergency_contact']) ? $_POST['emergency_contact'] : '',
        'referrer' => isset($_POST['referrer']) ? $_POST['referrer'] : '',
        'first_visit_date' => isset($_POST['first_visit_date']) ? $_POST['first_visit_date'] : '',
        'doctor_id' => $doctor_id,
    ), clinic_my_id());
    if (isset($res['id'])) {
        clinic_audit('ساخت پرونده دستی', (int) $res['id'], 'شماره ' . $res['file_no']);
        flash_set('ok', 'پرونده با شماره ' . $res['file_no'] . ' ساخته شد.');
        joma_redirect('index.php?p=clinic_client&id=' . (int) $res['id']);
    } else {
        $msg = $res['error'];
        if (isset($res['dup_id'])) {
            $msg .= ' <a href="' . e(joma_url('index.php?p=clinic_client&id=' . (int) $res['dup_id'])) . '">مشاهده پرونده</a>';
        }
    }
}

// ---- ساخت لینک پذیرش ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clinic_action']) && $_POST['clinic_action'] === 'make_invite') {
    csrf_check();
    $doctor_id = (int) (isset($_POST['doctor_id']) ? $_POST['doctor_id'] : 0);
    if ($role === 'doctor') $doctor_id = clinic_my_id();
    if ($role === 'secretary') $doctor_id = clinic_my_doctor_id();
    $client_id = (int) (isset($_POST['client_id']) ? $_POST['client_id'] : 0);
    $mobile = isset($_POST['mobile']) ? $_POST['mobile'] : '';
    if ($doctor_id <= 0) {
        $msg = 'دکتر را انتخاب کنید.';
    } else {
        $tok = clinic_create_invite($doctor_id, $client_id, $mobile, clinic_my_id());
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        $new_invite_link = $scheme . '://' . $host . clinic_invite_url($tok);
        clinic_audit($client_id > 0 ? 'ساخت لینک تکمیل فرم' : 'ساخت لینک پذیرش', $client_id, '');
        $msg_ok = true;
        $msg = 'لینک ساخته شد (۷ روز اعتبار، یک‌بارمصرف). آن را برای مراجع بفرستید:';
    }
}

// ---- بایگانی / فعال‌سازی ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clinic_action']) && ($_POST['clinic_action'] === 'archive' || $_POST['clinic_action'] === 'unarchive')) {
    csrf_check();
    $cid = (int) (isset($_POST['client_id']) ? $_POST['client_id'] : 0);
    $c = clinic_get_client($cid);
    if ($c && clinic_can_access_client($c) && in_array($role, array('admin', 'doctor', 'head_secretary'), true)) {
        $patch = array('status' => $_POST['clinic_action'] === 'archive' ? 'archived' : 'active');
        if ($patch['status'] === 'archived') $patch['archived_at'] = joma_now();
        clinic_update_client($cid, $patch, clinic_my_id());
        clinic_audit($patch['status'] === 'archived' ? 'بایگانی پرونده' : 'خروج از بایگانی', $cid, '');
        flash_set('ok', 'وضعیت پرونده به‌روز شد.');
    }
    joma_redirect('index.php?p=clinic_clients');
}

// ---- فیلترها ----
$f = array(
    'q' => isset($_GET['q']) ? $_GET['q'] : '',
    'status' => isset($_GET['status']) ? $_GET['status'] : 'active',
    'doctor_id' => isset($_GET['doctor_id']) ? (int) $_GET['doctor_id'] : 0,
    'flag' => isset($_GET['flag']) ? $_GET['flag'] : '',
);
$clients = clinic_list_clients($f);
$doctors = clinic_doctors_list();
$opt = clinic_intake_options();
$show_new = isset($_GET['new']);
$show_invite = isset($_GET['invite']);

joma_header('پرونده‌ها', array(array('label' => 'مدیریت مطب', 'href' => joma_url('index.php?p=clinic_dashboard')), array('label' => 'پرونده‌ها')));

echo '<div class="card"><h1>🗂️ پرونده‌ها (بایگانی مطب)</h1>';
if ($msg !== '') echo $msg_ok ? '<div class="clinic-alert green">' . $msg . '</div>' : '<p class="bad">' . $msg . '</p>';
if ($new_invite_link !== '') {
    echo '<div class="clinic-alert blue"><code class="ltr">' . clinic_h($new_invite_link) . '</code><br><br>';
    echo '<button class="btn btn-small" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(this.getAttribute(\'data-l\'));this.textContent=\'کپی شد ✅\';" data-l="' . clinic_h($new_invite_link) . '">کپی لینک</button></div>';
}

// جستجو و فیلتر
echo '<form method="get" action="index.php">';
echo '<input type="hidden" name="p" value="clinic_clients">';
echo '<div class="clinic-search"><input name="q" value="' . clinic_h($f['q']) . '" placeholder="نام، موبایل یا شماره پرونده..." autocomplete="off"><button class="btn" type="submit">جستجو</button></div>';
echo '<div class="clinic-filters">';
echo '<select name="status" onchange="this.form.submit()">';
foreach (array('active' => 'فعال', 'archived' => 'بایگانی‌شده', 'all' => 'همه') as $k => $v) {
    echo '<option value="' . $k . '"' . ($f['status'] === $k ? ' selected' : '') . '>' . $v . '</option>';
}
echo '</select>';
if ($role === 'admin' || $role === 'head_secretary') {
    echo '<select name="doctor_id" onchange="this.form.submit()"><option value="0">همه دکترها</option>';
    foreach ($doctors as $d) {
        echo '<option value="' . (int) $d['id'] . '"' . ($f['doctor_id'] === (int) $d['id'] ? ' selected' : '') . '>' . clinic_h(clinic_user_display($d)) . '</option>';
    }
    echo '</select>';
}
echo '<select name="flag" onchange="this.form.submit()">';
$flags = array('' => 'همه وضعیت‌های فرم', 'nointake' => 'فرم تکمیل‌نشده', 'unreviewed' => 'در انتظار بررسی', 'risk' => '🚨 دارای شاخص خطر');
foreach ($flags as $k => $v) {
    echo '<option value="' . $k . '"' . ($f['flag'] === $k ? ' selected' : '') . '>' . $v . '</option>';
}
echo '</select>';
echo '</div></form>';
echo '<p><a class="btn" href="' . e(joma_url('index.php?p=clinic_clients&new=1')) . '">+ پرونده دستی جدید</a> ';
echo '<a class="btn btn-ghost" href="' . e(joma_url('index.php?p=clinic_clients&invite=1')) . '">🔗 ساخت لینک پذیرش</a> ';
if ($role !== 'doctor') echo '<a class="btn btn-ghost" href="' . e(joma_url('index.php?p=clinic_import')) . '">📥 ورود گروهی</a>';
echo '</p>';
echo '<p class="hint">تعداد: ' . clinic_h(fa_num(count($clients))) . '</p>';
echo '</div>';

// ---- فرم پرونده دستی ----
if ($show_new) {
    echo '<div class="card"><h2>📝 پرونده دستی جدید (مراجعین قبلی)</h2>';
    echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_clients')) . '">';
    echo csrf_field() . '<input type="hidden" name="clinic_action" value="create_client">';
    echo '<div class="field-row"><div>' . clinic_field_text('first_name', 'نام *', '', '', '') . '</div>';
    echo '<div>' . clinic_field_text('last_name', 'نام خانوادگی *', '', '', '') . '</div></div>';
    echo '<div class="field-row"><div>' . clinic_field_text('mobile', 'موبایل *', '', '09123456789', 'ltr') . '</div>';
    echo '<div>' . clinic_field_text('birth_date', 'تاریخ تولد (شمسی)', '', '1370-05-12', 'ltr') . '</div></div>';
    echo '<div class="field-row"><div>' . clinic_field_text('job', 'شغل', '', '', '') . '</div>';
    echo '<div>' . clinic_field_text('education', 'تحصیلات', '', '', '') . '</div></div>';
    $marmap = array();
    foreach ($opt['marital'] as $m) $marmap[$m] = $m;
    echo clinic_field_select('marital', 'وضعیت تأهل', $marmap, '', '— انتخاب —');
    echo clinic_field_text('emergency_contact', 'تماس اضطراری (نام و نسبت)', '', '', '');
    $refmap = array();
    foreach ($opt['referrer'] as $m) $refmap[$m] = $m;
    echo clinic_field_select('referrer', 'معرف', $refmap, '', '— انتخاب —');
    echo clinic_field_text('first_visit_date', 'تاریخ اولین مراجعه واقعی', clinic_today(), '', 'ltr');
    if ($role === 'admin' || $role === 'head_secretary') {
        $dmap = array();
        foreach ($doctors as $d) $dmap[$d['id']] = clinic_user_display($d);
        echo clinic_field_select('doctor_id', 'دکتر معالج *', $dmap, '', '— انتخاب —');
    }
    echo '<p><button class="btn" type="submit">ساخت پرونده</button></p></form></div>';
}

// ---- فرم لینک پذیرش ----
if ($show_invite) {
    echo '<div class="card"><h2>🔗 ساخت لینک پذیرش</h2>';
    echo '<p class="hint">لینک جدید: مراجع هر ۵ بخش فرم را خودش پر می‌کند. اگر پرونده دستی ساخته‌اید، همان پرونده را انتخاب کنید تا بخش ۱ از قبل پر باشد.</p>';
    echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_clients')) . '">';
    echo csrf_field() . '<input type="hidden" name="clinic_action" value="make_invite">';
    if ($role === 'admin' || $role === 'head_secretary') {
        $dmap = array();
        foreach ($doctors as $d) $dmap[$d['id']] = clinic_user_display($d);
        echo clinic_field_select('doctor_id', 'دکتر *', $dmap, '', '— انتخاب —');
    }
    $mine = clinic_list_clients(array('status' => 'active', 'flag' => 'nointake'));
    if ($mine) {
        $cmap = array();
        foreach (array_slice($mine, 0, 200) as $c) $cmap[$c['id']] = clinic_client_display_name($c) . ' — ' . $c['file_no'];
        echo clinic_field_select('client_id', 'اتصال به پرونده موجود (اختیاری)', $cmap, '', '— پرونده جدید —');
    }
    echo clinic_field_text('mobile', 'موبایل مراجع (اختیاری، برای یادداشت)', '', '09123456789', 'ltr');
    echo '<p><button class="btn" type="submit">ساخت لینک</button></p></form></div>';
}

// ---- جدول ----
echo '<div class="card"><div class="table-wrap"><table class="clinic-table">';
echo '<tr><th>شماره</th><th>نام</th><th>موبایل</th><th>دکتر</th><th>فرم</th><th>نوبت بعدی</th><th>مانده</th><th></th></tr>';
foreach (array_slice($clients, 0, 300) as $c) {
    $doc = get_user((int) $c['doctor_id']);
    $next = clinic_client_next_appointment((int) $c['id']);
    $tot = clinic_client_totals((int) $c['id']);
    echo '<tr>';
    echo '<td>' . clinic_h(fa_num($c['file_no'])) . '</td>';
    echo '<td><a href="' . e(joma_url('index.php?p=clinic_client&id=' . (int) $c['id'])) . '"><strong>' . clinic_h(clinic_client_display_name($c)) . '</strong></a>';
    $fl = clinic_client_flags($c);
    if ($fl['violence'] || $fl['risks']) echo ' ' . clinic_badge('🚨', 'b-red');
    echo '</td>';
    echo '<td>' . clinic_h(fa_num($c['mobile'])) . '</td>';
    echo '<td>' . clinic_h($doc ? clinic_user_display($doc) : '—') . '</td>';
    echo '<td>' . clinic_intake_badge($c['intake_status']) . '</td>';
    echo '<td>' . ($next ? clinic_h(clinic_fa_date($next['date'])) : '<span class="hint">—</span>') . '</td>';
    echo '<td>' . ($tot['debt'] > 0 ? clinic_h(clinic_money($tot['debt'])) : '<span class="hint">تسویه</span>') . '</td>';
    echo '<td><a class="btn btn-small" href="' . e(joma_url('index.php?p=clinic_client&id=' . (int) $c['id'])) . '">پرونده</a></td>';
    echo '</tr>';
}
echo '</table></div></div>';

joma_footer();
