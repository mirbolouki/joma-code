<?php
// نوبت‌ها — نمای روزانه + ثبت/ویرایش + یادآوری
clinic_require_login();
$role = clinic_role();
if (!in_array($role, array('admin', 'doctor', 'head_secretary', 'secretary', 'client'), true)) {
    joma_redirect('index.php?p=dashboard');
}
$types = clinic_appt_types();
$statuses = clinic_appt_statuses();

// ---- نمای مراجع: فقط نوبت‌های خودش ----
if ($role === 'client') {
    joma_header('نوبت‌های من', array(array('label' => 'مدیریت مطب', 'href' => joma_url('index.php?p=clinic_dashboard')), array('label' => 'نوبت‌ها')));
    $mine = clinic_get_client_by_user(clinic_my_id());
    $up = $mine ? clinic_list_appointments(array('client_id' => (int) $mine['id'], 'upcoming' => true)) : array();
    $past = array();
    if ($mine) {
        $all = clinic_list_appointments(array('client_id' => (int) $mine['id']));
        foreach ($all as $a) {
            if ($a['date'] < clinic_today() || !in_array($a['status'], clinic_appt_active_statuses(), true)) $past[] = $a;
        }
        usort($past, function ($x, $y) { return strcmp($y['date'], $x['date']); });
    }
    echo '<div class="card"><h1>📅 نوبت‌های من</h1>';
    echo '<h3>نوبت‌های آینده</h3>';
    if (!$up) echo '<p class="hint">نوبت فعالی ندارید.</p>';
    else {
        echo '<div class="table-wrap"><table class="clinic-table"><tr><th>تاریخ</th><th>ساعت</th><th>نوع</th><th>وضعیت</th></tr>';
        foreach ($up as $a) {
            echo '<tr><td>' . clinic_h(clinic_fa_date($a['date'])) . '</td><td>' . clinic_h(fa_num($a['start'])) . '</td><td>' . clinic_h($types[$a['type']]) . '</td><td>' . clinic_status_badge($a['status']) . '</td></tr>';
        }
        echo '</table></div>';
    }
    echo '<h3>گذشته</h3>';
    if (!$past) echo '<p class="hint">—</p>';
    else {
        echo '<div class="table-wrap"><table class="clinic-table"><tr><th>تاریخ</th><th>ساعت</th><th>نوع</th><th>وضعیت</th></tr>';
        foreach (array_slice($past, 0, 30) as $a) {
            echo '<tr><td>' . clinic_h(clinic_fa_date($a['date'])) . '</td><td>' . clinic_h(fa_num($a['start'])) . '</td><td>' . clinic_h($types[$a['type']]) . '</td><td>' . clinic_status_badge($a['status']) . '</td></tr>';
        }
        echo '</table></div>';
    }
    echo '</div>';
    joma_footer();
    return;
}

// ---- کادر مطب ----
$msg = '';
$date = clinic_valid_jdate(isset($_GET['date']) ? $_GET['date'] : '');
if ($date === '') $date = clinic_today();
$f_doctor = (int) (isset($_GET['doctor_id']) ? $_GET['doctor_id'] : 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clinic_action'])) {
    csrf_check();
    $act = $_POST['clinic_action'];
    if ($act === 'appt_create') {
        $res = clinic_create_appointment(array(
            'client_id' => isset($_POST['client_id']) ? $_POST['client_id'] : 0,
            'date' => isset($_POST['date']) ? $_POST['date'] : '',
            'start' => isset($_POST['start']) ? $_POST['start'] : '',
            'end' => isset($_POST['end']) ? $_POST['end'] : '',
            'type' => isset($_POST['type']) ? $_POST['type'] : 'present',
            'note' => isset($_POST['note']) ? $_POST['note'] : '',
        ), clinic_my_id());
        if (isset($res['id'])) {
            $na = clinic_get_appointment($res['id']);
            clinic_audit('ثبت نوبت', (int) $na['client_id'], clinic_fa_date($na['date']) . ' ' . $na['start']);
            // یادآوری خودکار در صورت فعال بودن
            $set = clinic_get_settings();
            if ($set['reminder_mode'] === 'auto' && trim($set['sms_api_url']) !== '') {
                $nc = clinic_get_client($na['client_id']);
                $nd = get_user($na['doctor_id']);
                $txt = clinic_reminder_text($na, $nc, $nd ? clinic_user_display($nd) : '');
                $sr = clinic_send_sms($nc['mobile'], $txt);
                if ($sr['ok']) {
                    clinic_mark_reminded((int) $na['id'], clinic_my_id());
                    clinic_audit('ارسال خودکار یادآوری', (int) $na['client_id'], '');
                }
            }
            flash_set('ok', 'نوبت ثبت شد.');
            joma_redirect('index.php?p=clinic_appointments&date=' . $na['date']);
        } else {
            $msg = $res['error'];
        }
    }
    if ($act === 'appt_update') {
        $res = clinic_update_appointment((int) (isset($_POST['appt_id']) ? $_POST['appt_id'] : 0), array(
            'date' => isset($_POST['date']) ? $_POST['date'] : '',
            'start' => isset($_POST['start']) ? $_POST['start'] : '',
            'end' => isset($_POST['end']) ? $_POST['end'] : '',
            'type' => isset($_POST['type']) ? $_POST['type'] : 'present',
            'note' => isset($_POST['note']) ? $_POST['note'] : '',
            'fee' => isset($_POST['fee']) ? $_POST['fee'] : null,
        ), clinic_my_id());
        if (isset($res['ok'])) {
            flash_set('ok', 'نوبت به‌روز شد.');
            $a = clinic_get_appointment((int) $_POST['appt_id']);
            joma_redirect('index.php?p=clinic_appointments&date=' . $a['date']);
        } else {
            $msg = $res['error'];
        }
    }
    if ($act === 'appt_status') {
        $res = clinic_set_appointment_status(
            (int) (isset($_POST['appt_id']) ? $_POST['appt_id'] : 0),
            isset($_POST['status']) ? $_POST['status'] : '',
            clinic_my_id(),
            isset($_POST['reason']) ? $_POST['reason'] : ''
        );
        if (isset($res['ok'])) {
            $a = clinic_get_appointment((int) $_POST['appt_id']);
            clinic_audit('تغییر وضعیت نوبت', (int) $a['client_id'], $statuses[$_POST['status']]);
            flash_set('ok', $res['late'] ? 'ثبت شد ⚠️ (لغو دیرهنگام — کمتر از ۲۴ ساعت مانده بود)' : 'وضعیت نوبت به‌روز شد.');
            joma_redirect('index.php?p=clinic_appointments&date=' . $a['date']);
        } else {
            $msg = $res['error'];
        }
    }
    if ($act === 'remind_manual') {
        $aid = (int) (isset($_POST['appt_id']) ? $_POST['appt_id'] : 0);
        clinic_mark_reminded($aid, clinic_my_id());
        $a = clinic_get_appointment($aid);
        if ($a) clinic_audit('ثبت یادآوری دستی', (int) $a['client_id'], '');
        flash_set('ok', 'یادآوری ثبت شد.');
        joma_redirect('index.php?p=clinic_appointments&date=' . ($a ? $a['date'] : $date));
    }
    if ($act === 'remind_auto') {
        $aid = (int) (isset($_POST['appt_id']) ? $_POST['appt_id'] : 0);
        $a = clinic_get_appointment($aid);
        if ($a) {
            $ac = clinic_get_client($a['client_id']);
            $ad = get_user($a['doctor_id']);
            $sr = clinic_send_sms($ac['mobile'], clinic_reminder_text($a, $ac, $ad ? clinic_user_display($ad) : ''));
            if ($sr['ok']) {
                clinic_mark_reminded($aid, clinic_my_id());
                clinic_audit('ارسال یادآوری پیامکی', (int) $a['client_id'], '');
                flash_set('ok', 'پیامک یادآوری ارسال شد. ✅');
            } else {
                flash_set('bad', $sr['msg']);
            }
            joma_redirect('index.php?p=clinic_appointments&date=' . $a['date']);
        }
    }
}

joma_header('نوبت‌ها', array(array('label' => 'مدیریت مطب', 'href' => joma_url('index.php?p=clinic_dashboard')), array('label' => 'نوبت‌ها')));
echo '<div class="card"><h1>📅 نوبت‌ها</h1>';
if ($msg !== '') echo '<p class="bad">' . clinic_h($msg) . '</p>';

// ناوبری روز
$g = jalali_to_gregorian((int) substr($date, 0, 4), (int) substr($date, 5, 2), (int) substr($date, 8, 2));
$mk = function ($off) use ($g) {
    $ts = mktime(0, 0, 0, $g[1], $g[2] + $off, $g[0]);
    $j = gregorian_to_jalali((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));
    return $j[0] . '-' . str_pad($j[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($j[2], 2, '0', STR_PAD_LEFT);
};
echo '<div class="clinic-row"><div class="grow">';
echo '<a class="btn btn-small" href="' . e(joma_url('index.php?p=clinic_appointments&date=' . $mk(-1) . '&doctor_id=' . $f_doctor)) . '">→ روز قبل</a> ';
echo '<strong>' . clinic_h(clinic_fa_date($date)) . '</strong> ';
echo '<a class="btn btn-small" href="' . e(joma_url('index.php?p=clinic_appointments&date=' . $mk(1) . '&doctor_id=' . $f_doctor)) . '">روز بعد ←</a> ';
echo '<a class="btn btn-small btn-ghost" href="' . e(joma_url('index.php?p=clinic_appointments&date=' . clinic_today())) . '">امروز</a>';
echo '</div><div>';
echo '<form method="get" action="index.php" style="display:inline"><input type="hidden" name="p" value="clinic_appointments">';
echo '<input type="hidden" name="doctor_id" value="' . $f_doctor . '">';
echo '<input name="date" value="' . clinic_h($date) . '" dir="ltr" size="10" placeholder="1405-07-01"> <button class="btn btn-small" type="submit">برو</button></form>';
echo '</div></div>';
if ($role === 'admin' || $role === 'head_secretary') {
    echo '<form method="get" action="index.php"><input type="hidden" name="p" value="clinic_appointments"><input type="hidden" name="date" value="' . clinic_h($date) . '">';
    echo '<div class="clinic-filters"><select name="doctor_id" onchange="this.form.submit()"><option value="0">همه دکترها</option>';
    foreach (clinic_doctors_list() as $d) {
        echo '<option value="' . (int) $d['id'] . '"' . ($f_doctor === (int) $d['id'] ? ' selected' : '') . '>' . clinic_h(clinic_user_display($d)) . '</option>';
    }
    echo '</select></div></form>';
}
echo '<p><a class="btn" href="' . e(joma_url('index.php?p=clinic_appointments&date=' . $date . '&new=1')) . '">+ نوبت جدید</a></p>';
echo '</div>';

// ---- فرم نوبت جدید ----
if (isset($_GET['new'])) {
    $pre_client = (int) (isset($_GET['client_id']) ? $_GET['client_id'] : 0);
    $clients = clinic_list_clients(array('status' => 'active'));
    echo '<div class="card"><h2>➕ نوبت جدید</h2>';
    if (!$clients) {
        echo '<p class="hint">پرونده فعالی در محدوده شما نیست. اول پرونده بسازید.</p>';
    } else {
        echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_appointments&date=' . $date)) . '">';
        echo csrf_field() . '<input type="hidden" name="clinic_action" value="appt_create">';
        $cmap = array();
        foreach ($clients as $c) $cmap[$c['id']] = clinic_client_display_name($c) . ' — ' . $c['file_no'] . ' (' . fa_num($c['mobile']) . ')';
        echo clinic_field_select('client_id', 'مراجع *', $cmap, (string) $pre_client, '— انتخاب —');
        echo '<div class="field-row"><div>' . clinic_field_text('date', 'تاریخ (شمسی) *', $date, '1405-07-01', 'ltr') . '</div><div></div></div>';
        echo '<div class="field-row"><div>' . clinic_field_text('start', 'ساعت شروع *', '', '16:00', 'ltr') . '</div>';
        echo '<div>' . clinic_field_text('end', 'ساعت پایان *', '', '17:00', 'ltr') . '</div></div>';
        echo clinic_field_select('type', 'نوع جلسه', $types, 'present', null);
        echo clinic_field_text('note', 'یادداشت نوبت', '', '', '');
        echo '<p><button class="btn" type="submit">ثبت نوبت</button></p></form>';
    }
    echo '</div>';
}

// ---- فرم ویرایش ----
$edit_id = (int) (isset($_GET['edit']) ? $_GET['edit'] : 0);
if ($edit_id > 0) {
    $ea = clinic_get_appointment($edit_id);
    $ec = $ea ? clinic_get_client($ea['client_id']) : null;
    if ($ea && $ec && clinic_can_access_client($ec)) {
        echo '<div class="card"><h2>ویرایش نوبت — ' . clinic_h(clinic_client_display_name($ec)) . '</h2>';
        echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_appointments&date=' . $date)) . '">';
        echo csrf_field() . '<input type="hidden" name="clinic_action" value="appt_update"><input type="hidden" name="appt_id" value="' . $edit_id . '">';
        echo '<div class="field-row"><div>' . clinic_field_text('date', 'تاریخ', $ea['date'], '', 'ltr') . '</div><div></div></div>';
        echo '<div class="field-row"><div>' . clinic_field_text('start', 'ساعت شروع', $ea['start'], '', 'ltr') . '</div>';
        echo '<div>' . clinic_field_text('end', 'ساعت پایان', $ea['end'], '', 'ltr') . '</div></div>';
        echo clinic_field_select('type', 'نوع جلسه', $types, $ea['type'], null);
        if (clinic_can_manage_finance()) {
            echo clinic_field_text('fee', 'مبلغ جلسه (تومان)', $ea['fee'], '', 'ltr');
        }
        echo clinic_field_text('note', 'یادداشت', $ea['note'], '', '');
        echo '<p><button class="btn" type="submit">ذخیره</button></p></form></div>';
    }
}

// ---- فهرست روز ----
$list = clinic_list_appointments(array('date' => $date, 'doctor_id' => $f_doctor));
echo '<div class="card"><h2>جلسات ' . clinic_h(clinic_fa_date($date)) . ' (' . clinic_h(fa_num(count($list))) . ')</h2>';
if (!$list) {
    echo '<p class="hint">نوبتی ثبت نشده است.</p>';
} else {
    echo '<div class="table-wrap"><table class="clinic-table"><tr><th>ساعت</th><th>مراجع</th><th>دکتر</th><th>نوع</th><th>مبلغ</th><th>وضعیت</th><th>یادآوری</th><th>عملیات</th></tr>';
    foreach ($list as $a) {
        $ac = clinic_get_client($a['client_id']);
        $ad = get_user((int) $a['doctor_id']);
        echo '<tr><td>' . clinic_h(fa_num($a['start'] . '–' . $a['end'])) . '</td>';
        echo '<td><a href="' . e(joma_url('index.php?p=clinic_client&id=' . (int) $a['client_id'])) . '">' . clinic_h($ac ? clinic_client_display_name($ac) : '—') . '</a></td>';
        echo '<td>' . clinic_h($ad ? clinic_user_display($ad) : '—') . '</td>';
        echo '<td>' . clinic_h($types[$a['type']]) . '</td>';
        echo '<td>' . clinic_h(clinic_money_short($a['fee'])) . '</td>';
        echo '<td>' . clinic_status_badge($a['status']) . '</td>';
        echo '<td>' . ($a['remind_sent_at'] !== '' ? clinic_badge('ارسال شد ✅', 'b-green') : '<span class="hint">—</span>') . '</td>';
        echo '<td style="white-space:nowrap">';
        echo '<a class="btn btn-small" href="' . e(joma_url('index.php?p=clinic_appointments&date=' . $date . '&doctor_id=' . $f_doctor . '&edit=' . (int) $a['id'])) . '">ویرایش</a> ';
        if (in_array($a['status'], array('reserved', 'confirmed'), true)) {
            echo '<a class="btn btn-small" href="' . e(joma_url('index.php?p=clinic_appointments&date=' . $date . '&doctor_id=' . $f_doctor . '&status_id=' . (int) $a['id'])) . '">وضعیت</a> ';
        }
        if ($a['remind_sent_at'] === '' && in_array($a['status'], array('reserved', 'confirmed'), true)) {
            echo '<a class="btn btn-small" href="' . e(joma_url('index.php?p=clinic_appointments&date=' . $date . '&doctor_id=' . $f_doctor . '&remind=' . (int) $a['id'])) . '">🔔</a>';
        }
        echo '</td></tr>';
    }
    echo '</table></div>';
}
echo '</div>';

// ---- تغییر وضعیت ----
$status_id = (int) (isset($_GET['status_id']) ? $_GET['status_id'] : 0);
if ($status_id > 0) {
    $sa = clinic_get_appointment($status_id);
    $sc = $sa ? clinic_get_client($sa['client_id']) : null;
    if ($sa && $sc && clinic_can_access_client($sc)) {
        echo '<div class="card"><h2>تغییر وضعیت نوبت</h2>';
        echo '<p>' . clinic_h(clinic_client_display_name($sc)) . ' — ' . clinic_h(clinic_fa_date($sa['date'])) . ' ساعت ' . clinic_h(fa_num($sa['start'])) . '</p>';
        echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_appointments&date=' . $date)) . '">';
        echo csrf_field() . '<input type="hidden" name="clinic_action" value="appt_status"><input type="hidden" name="appt_id" value="' . $status_id . '">';
        echo clinic_field_select('status', 'وضعیت جدید', $statuses, $sa['status'], null);
        echo clinic_field_text('reason', 'دلیل (برای لغو)', '', '', '');
        echo '<p class="hint">سیاست لغو: حداقل ۲۴ ساعت قبل (قابل تنظیم). لغو دیرهنگام علامت‌گذاری می‌شود.</p>';
        echo '<p><button class="btn" type="submit">ثبت وضعیت</button></p></form></div>';
    }
}

// ---- یادآوری ----
$remind_id = (int) (isset($_GET['remind']) ? $_GET['remind'] : 0);
if ($remind_id > 0) {
    $ra = clinic_get_appointment($remind_id);
    $rc = $ra ? clinic_get_client($ra['client_id']) : null;
    if ($ra && $rc && clinic_can_access_client($rc)) {
        $rd = get_user((int) $ra['doctor_id']);
        $txt = clinic_reminder_text($ra, $rc, $rd ? clinic_user_display($rd) : '');
        $set = clinic_get_settings();
        echo '<div class="card"><h2>🔔 یادآوری نوبت</h2>';
        echo '<p>' . clinic_h(clinic_client_display_name($rc)) . ' — ' . clinic_h(fa_num($rc['mobile'])) . '</p>';
        echo '<div class="clinic-kvbox"><p>' . nl2br(clinic_h($txt)) . '</p></div>';
        echo '<p><button class="btn btn-small" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById(\'rmt\').value);this.textContent=\'کپی شد ✅\';">کپی متن</button></p>';
        echo '<textarea id="rmt" style="display:none">' . clinic_h($txt) . '</textarea>';
        if ($set['reminder_mode'] === 'auto' && trim($set['sms_api_url']) !== '') {
            echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_appointments&date=' . $date)) . '" style="display:inline">';
            echo csrf_field() . '<input type="hidden" name="clinic_action" value="remind_auto"><input type="hidden" name="appt_id" value="' . $remind_id . '">';
            echo '<button class="btn" type="submit">📲 ارسال خودکار پیامک</button></form> ';
        }
        echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_appointments&date=' . $date)) . '" style="display:inline">';
        echo csrf_field() . '<input type="hidden" name="clinic_action" value="remind_manual"><input type="hidden" name="appt_id" value="' . $remind_id . '">';
        echo '<button class="btn btn-ghost" type="submit">ثبت «ارسال شد» (دستی)</button></form>';
        echo '</div>';
    }
}

joma_footer();
