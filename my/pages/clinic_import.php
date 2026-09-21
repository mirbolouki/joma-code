<?php
// ورود گروهی پرونده‌ها (اکسل/CSV)
clinic_require_login();
if (!in_array(clinic_role(), array('admin', 'head_secretary', 'secretary'), true)) {
    clinic_deny('این صفحه فقط برای کادر اجرایی مطب است.');
}
$role = clinic_role();

// دانلود قالب
if (isset($_GET['download']) && $_GET['download'] === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="clinic-import-template.csv"');
    echo clinic_import_template_csv();
    exit;
}

$msg = '';
$msg_ok = false;
$import_errors = array();
$import_created = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $doctor_id = (int) (isset($_POST['doctor_id']) ? $_POST['doctor_id'] : 0);
    if ($role === 'secretary') $doctor_id = clinic_my_doctor_id();
    if ($doctor_id <= 0) {
        $msg = 'دکتر معالج را انتخاب کنید.';
    } elseif (empty($_FILES['imp_file']) || $_FILES['imp_file']['error'] !== UPLOAD_ERR_OK) {
        $msg = 'فایل انتخاب نشده یا خطا در آپلود.';
    } else {
        $tmp = $_FILES['imp_file']['tmp_name'];
        $name = strtolower((string) $_FILES['imp_file']['name']);
        if (substr($name, -5) === '.xlsx') {
            $pr = clinic_parse_xlsx_file($tmp);
        } else {
            $pr = clinic_parse_csv_file($tmp);
        }
        if (isset($pr['error'])) {
            $msg = $pr['error'];
        } else {
            $res = clinic_import_rows($pr['rows'], $doctor_id, clinic_my_id());
            $import_created = $res['created'];
            $import_errors = $res['errors'];
            clinic_audit('ورود گروهی پرونده', 0, fa_num($import_created) . ' پرونده');
            $msg = $import_created . ' پرونده ساخته شد.' . ($import_errors ? ' ' . count($import_errors) . ' سطر خطا داشت.' : '');
            $msg_ok = true;
        }
    }
}

joma_header('ورود گروهی', array(array('label' => 'مدیریت مطب', 'href' => joma_url('index.php?p=clinic_dashboard')), array('label' => 'ورود گروهی')));
echo '<div class="card"><h1>📥 ورود گروهی پرونده‌ها</h1>';
echo '<p class="hint">برای مراجعین قبلی: قالب را دانلود کنید، در اکسل پر کنید و همین‌جا آپلود کنید (فرمت <code class="ltr">.xlsx</code> یا <code class="ltr">.csv</code>). ستون‌ها: ' . clinic_h(implode('، ', clinic_import_headers())) . '</p>';
echo '<p><a class="btn btn-ghost" href="' . e(joma_url('index.php?p=clinic_import&download=template')) . '">⬇ دانلود قالب اکسل (CSV)</a></p>';
if ($msg !== '') echo $msg_ok ? '<div class="clinic-alert green">' . clinic_h($msg) . '</div>' : '<p class="bad">' . clinic_h($msg) . '</p>';
if ($import_errors) {
    echo '<div class="clinic-alert orange"><ul>';
    foreach (array_slice($import_errors, 0, 50) as $er) echo '<li>' . clinic_h($er) . '</li>';
    echo '</ul></div>';
}
echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_import')) . '" enctype="multipart/form-data">';
echo csrf_field();
if ($role === 'admin' || $role === 'head_secretary') {
    $dmap = array();
    foreach (clinic_doctors_list() as $d) $dmap[$d['id']] = clinic_user_display($d);
    echo clinic_field_select('doctor_id', 'دکتر معالج این دسته *', $dmap, '', '— انتخاب —');
} else {
    $d = get_user(clinic_my_doctor_id());
    echo '<p>دکتر: <strong>' . clinic_h($d ? clinic_user_display($d) : '—') . '</strong></p>';
}
echo '<label>فایل اکسل/CSV *</label><input type="file" name="imp_file" accept=".xlsx,.csv">';
echo '<p><button class="btn" type="submit">آپلود و ساخت پرونده‌ها</button></p></form></div>';
joma_footer();
