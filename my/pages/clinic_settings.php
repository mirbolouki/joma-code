<?php
// تنظیمات مطب — یادآوری، تعرفه‌ها، پیامک، سیاست‌ها
clinic_require_login();
if (!clinic_can_manage_tariffs()) clinic_deny('این صفحه فقط برای مدیر و منشی ارشد است.');
$role = clinic_role();
$msg = '';
$msg_ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clinic_action'])) {
    csrf_check();
    $act = $_POST['clinic_action'];
    if ($act === 'save_general') {
        clinic_save_settings(array(
            'clinic_name' => isset($_POST['clinic_name']) ? $_POST['clinic_name'] : '',
            'clinic_contact' => isset($_POST['clinic_contact']) ? $_POST['clinic_contact'] : '',
            'cancel_hours' => isset($_POST['cancel_hours']) ? $_POST['cancel_hours'] : 24,
            'accuracy_text' => isset($_POST['accuracy_text']) ? $_POST['accuracy_text'] : '',
            'crisis_text' => isset($_POST['crisis_text']) ? $_POST['crisis_text'] : '',
        ));
        clinic_audit('ویرایش تنظیمات مطب', 0, 'عمومی');
        $msg = 'تنظیمات ذخیره شد.';
        $msg_ok = true;
    }
    if ($act === 'save_reminder') {
        clinic_save_settings(array(
            'reminder_mode' => isset($_POST['reminder_mode']) ? $_POST['reminder_mode'] : 'manual',
            'reminder_template' => isset($_POST['reminder_template']) ? $_POST['reminder_template'] : '',
        ));
        clinic_audit('ویرایش تنظیمات مطب', 0, 'یادآوری');
        $msg = 'تنظیمات یادآوری ذخیره شد.';
        $msg_ok = true;
    }
    if ($act === 'save_sms') {
        clinic_save_settings(array(
            'sms_api_url' => isset($_POST['sms_api_url']) ? $_POST['sms_api_url'] : '',
            'sms_ok_contains' => isset($_POST['sms_ok_contains']) ? $_POST['sms_ok_contains'] : '',
        ));
        clinic_audit('ویرایش تنظیمات مطب', 0, 'پیامک');
        $msg = 'تنظیمات پیامک ذخیره شد.';
        $msg_ok = true;
    }
    if ($act === 'sms_test') {
        $to = clinic_norm_mobile(isset($_POST['test_to']) ? $_POST['test_to'] : '');
        if ($to === '') {
            $msg = 'شماره مقصد معتبر نیست.';
        } else {
            $r = clinic_send_sms($to, 'تست سامانه پیامکی مطب ✅');
            $msg = $r['msg'];
            $msg_ok = $r['ok'];
        }
    }
    if ($act === 'tariff_update') {
        $tid = (int) (isset($_POST['tariff_id']) ? $_POST['tariff_id'] : 0);
        clinic_save_tariff($tid,
            isset($_POST['title']) ? trim($_POST['title']) : '',
            clinic_norm_money(isset($_POST['amount']) ? $_POST['amount'] : ''),
            !empty($_POST['active']));
        clinic_audit('ویرایش تعرفه', 0, '');
        $msg = 'تعرفه به‌روز شد.';
        $msg_ok = true;
    }
    if ($act === 'override_add') {
        $res = clinic_save_override(
            (int) (isset($_POST['doctor_id']) ? $_POST['doctor_id'] : 0),
            (int) (isset($_POST['client_id']) ? $_POST['client_id'] : 0),
            isset($_POST['session_type']) ? $_POST['session_type'] : '',
            clinic_norm_money(isset($_POST['amount']) ? $_POST['amount'] : ''));
        if (isset($res['ok'])) {
            clinic_audit('ثبت استثنای تعرفه', (int) $_POST['client_id'], '');
            $msg = 'استثنا ثبت شد.';
            $msg_ok = true;
        } else {
            $msg = $res['error'];
        }
    }
    if ($act === 'override_delete') {
        clinic_delete_override((int) (isset($_POST['override_id']) ? $_POST['override_id'] : 0));
        clinic_audit('حذف استثنای تعرفه', 0, '');
        $msg = 'استثنا حذف شد.';
        $msg_ok = true;
    }
}

$set = clinic_get_settings();
$tariffs = clinic_list_tariffs();
$overrides = clinic_list_overrides();
$types = clinic_appt_types();

joma_header('تنظیمات مطب', array(array('label' => 'مدیریت مطب', 'href' => joma_url('index.php?p=clinic_dashboard')), array('label' => 'تنظیمات')));
echo '<div class="card"><h1>⚙️ تنظیمات مطب</h1>';
if ($msg !== '') echo $msg_ok ? '<div class="clinic-alert green">' . clinic_h($msg) . '</div>' : '<p class="bad">' . clinic_h($msg) . '</p>';
echo '</div>';

echo '<div class="card"><h2>🏥 اطلاعات مطب و سیاست‌ها</h2>';
echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_settings')) . '">';
echo csrf_field() . '<input type="hidden" name="clinic_action" value="save_general">';
echo clinic_field_text('clinic_name', 'نام مطب (در رسید و پیامک)', $set['clinic_name'], '', '');
echo clinic_field_textarea('clinic_contact', 'اطلاعات تماس مطب (نمایش به مراجع)', $set['clinic_contact'], 2, 'آدرس، تلفن، ساعت کاری...');
echo clinic_field_text('cancel_hours', 'مهلت لغو بدون جریمه (ساعت)', $set['cancel_hours'], '24', 'ltr');
echo clinic_field_text('accuracy_text', 'متن تیک تأیید صحت اطلاعات', $set['accuracy_text'], '', '');
echo clinic_field_textarea('crisis_text', 'متن حمایتی بعد از ثبت فرم (در صورت شاخص خطر)', $set['crisis_text'], 3, '');
echo '<p><button class="btn" type="submit">ذخیره</button></p></form></div>';

echo '<div class="card"><h2>🔔 یادآوری نوبت</h2>';
echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_settings')) . '">';
echo csrf_field() . '<input type="hidden" name="clinic_action" value="save_reminder">';
echo '<div class="clinic-opts"><span class="clinic-opts-label">حالت یادآوری</span>';
echo '<label class="check clinic-pill"><input type="radio" name="reminder_mode" value="manual"' . ($set['reminder_mode'] !== 'auto' ? ' checked' : '') . '><span>دستی (نمایش متن آماده به منشی)</span></label>';
echo '<label class="check clinic-pill"><input type="radio" name="reminder_mode" value="auto"' . ($set['reminder_mode'] === 'auto' ? ' checked' : '') . '><span>خودکار (ارسال پیامک)</span></label></div>';
echo clinic_field_textarea('reminder_template', 'متن یادآوری', $set['reminder_template'], 5, '');
echo '<p class="hint">جای‌نگهدارها: <code class="ltr">{name}</code> نام مراجع، <code class="ltr">{date}</code> تاریخ، <code class="ltr">{time}</code> ساعت، <code class="ltr">{doctor}</code> درمانگر، <code class="ltr">{file}</code> شماره پرونده، <code class="ltr">{clinic}</code> نام مطب</p>';
echo '<p><button class="btn" type="submit">ذخیره</button></p></form></div>';

echo '<div class="card"><h2>📲 سامانه پیامکی (حالت خودکار)</h2>';
echo '<p class="hint">آدرس HTTP سامانه پیامکی خود را با جای‌نگهدار <code class="ltr">{to}</code> (شماره) و <code class="ltr">{text}</code> (متن) وارد کنید. مثال:<br><code class="ltr">https://api.example.com/send?user=...&pass=...&to={to}&text={text}</code></p>';
echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_settings')) . '">';
echo csrf_field() . '<input type="hidden" name="clinic_action" value="save_sms">';
echo '<label>آدرس API (متد GET)</label><input name="sms_api_url" dir="ltr" value="' . clinic_h($set['sms_api_url']) . '">';
echo '<label>عبارت موفقیت در پاسخ (اختیاری)</label><input name="sms_ok_contains" dir="ltr" value="' . clinic_h($set['sms_ok_contains']) . '">';
echo '<p><button class="btn" type="submit">ذخیره</button></p></form>';
echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_settings')) . '">';
echo csrf_field() . '<input type="hidden" name="clinic_action" value="sms_test">';
echo '<div class="clinic-row"><div class="grow"><label>تست ارسال به شماره</label><input name="test_to" dir="ltr" placeholder="09123456789"></div>';
echo '<div><label>&nbsp;</label><button class="btn btn-ghost" type="submit">ارسال تست</button></div></div></form></div>';

echo '<div class="card"><h2>💰 تعرفه‌های پایه (مشترک)</h2>';
echo '<p class="hint">مبلغ هر نوع جلسه برای همه دکترها. برای استثنا (دکتر/مراجع خاص) از بخش بعدی استفاده کنید.</p>';
foreach ($tariffs as $t) {
    echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_settings')) . '">';
    echo csrf_field() . '<input type="hidden" name="clinic_action" value="tariff_update"><input type="hidden" name="tariff_id" value="' . (int) $t['id'] . '">';
    echo '<div class="clinic-row"><div class="grow"><label>' . clinic_h($t['title']) . (isset($types[$t['session_type']]) ? ' <small class="hint">(' . clinic_h($types[$t['session_type']]) . ')</small>' : '') . '</label>';
    echo '<input name="amount" dir="ltr" value="' . clinic_h($t['amount']) . '" placeholder="تومان"></div>';
    echo '<div><label>فعال</label><input type="checkbox" name="active" value="1"' . (!empty($t['active']) ? ' checked' : '') . '></div>';
    echo '<div><label>&nbsp;</label><button class="btn btn-small" type="submit">ذخیره</button></div></div>';
    echo '<input type="hidden" name="title" value="' . clinic_h($t['title']) . '"></form>';
}
echo '</div>';

echo '<div class="card"><h2>⚖️ استثناهای تعرفه (دکتر/مراجع خاص)</h2>';
echo '<p class="hint">اولویت: استثنای مراجع ← استثنای دکتر ← تعرفه پایه.</p>';
if ($overrides) {
    echo '<div class="table-wrap"><table class="clinic-table"><tr><th>دکتر</th><th>مراجع</th><th>نوع</th><th>مبلغ</th><th></th></tr>';
    foreach ($overrides as $o) {
        $od = (int) $o['doctor_id'] > 0 ? clinic_get_user((int) $o['doctor_id']) : null;
        $oc = (int) $o['client_id'] > 0 ? clinic_get_client((int) $o['client_id']) : null;
        echo '<tr><td>' . clinic_h($od ? clinic_user_display($od) : '—') . '</td>';
        echo '<td>' . clinic_h($oc ? clinic_client_display_name($oc) . ' (' . $oc['file_no'] . ')' : '—') . '</td>';
        echo '<td>' . clinic_h(isset($types[$o['session_type']]) ? $types[$o['session_type']] : $o['session_type']) . '</td>';
        echo '<td>' . clinic_h(clinic_money($o['amount'])) . '</td><td>';
        echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_settings')) . '" style="display:inline" onsubmit="return confirm(\'حذف شود؟\');">';
        echo csrf_field() . '<input type="hidden" name="clinic_action" value="override_delete"><input type="hidden" name="override_id" value="' . (int) $o['id'] . '">';
        echo '<button class="btn btn-small btn-ghost" type="submit">حذف</button></form></td></tr>';
    }
    echo '</table></div>';
}
echo '<h3>➕ استثنای جدید</h3>';
echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_settings')) . '">';
echo csrf_field() . '<input type="hidden" name="clinic_action" value="override_add">';
$dmap = array('0' => '— همه دکترها —');
foreach (clinic_doctors_list() as $d) $dmap[$d['id']] = clinic_user_display($d);
echo clinic_field_select('doctor_id', 'دکتر', $dmap, '0', null);
$cmap = array('0' => '— همه مراجعین این دکتر —');
foreach (clinic_list_clients(array('status' => 'active')) as $c) $cmap[$c['id']] = clinic_client_display_name($c) . ' — ' . $c['file_no'];
echo clinic_field_select('client_id', 'مراجع خاص (اختیاری)', $cmap, '0', null);
echo clinic_field_select('session_type', 'نوع جلسه', $types, 'present', null);
echo clinic_field_text('amount', 'مبلغ (تومان)', '', '', 'ltr');
echo '<p><button class="btn" type="submit">ثبت استثنا</button></p></form></div>';

joma_footer();
