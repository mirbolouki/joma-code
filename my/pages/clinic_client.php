<?php
// پرونده مراجع — نمای زبانه‌ای
clinic_require_login();
$role = clinic_role();
$u = current_user();

$cid = (int) (isset($_GET['id']) ? $_GET['id'] : 0);
if ($role === 'client') {
    $mine = clinic_get_client_by_user($u['id']);
    if (!$mine) {
        joma_header('پرونده من');
        echo '<div class="card"><p class="bad">پرونده‌ای برای شما ثبت نشده است.</p></div>';
        joma_footer();
        return;
    }
    $cid = (int) $mine['id'];
}
$c = clinic_get_client($cid);
if (!$c) clinic_deny('پرونده پیدا نشد.');
if (!clinic_can_access_client($c)) clinic_deny('به این پرونده دسترسی ندارید.');

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'summary';
$allowed_tabs = array('summary', 'sessions', 'intake', 'finance', 'private', 'log');
if (!in_array($tab, $allowed_tabs, true)) $tab = 'summary';
if ($tab === 'private' && !clinic_can_edit_clinical($c)) $tab = 'summary';
if ($tab === 'log' && !clinic_is_staff()) $tab = 'summary';

$msg = '';
$go = function ($t) use ($cid) { joma_redirect('index.php?p=clinic_client&id=' . $cid . '&tab=' . $t); };

// ---------------- POST ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clinic_action'])) {
    csrf_check();
    $act = $_POST['clinic_action'];

    if ($act === 'update_base' && clinic_can_edit_client_base($c)) {
        $patch = array(
            'first_name' => trim(isset($_POST['first_name']) ? $_POST['first_name'] : ''),
            'last_name' => trim(isset($_POST['last_name']) ? $_POST['last_name'] : ''),
            'birth_date' => clinic_valid_jdate(isset($_POST['birth_date']) ? $_POST['birth_date'] : ''),
            'job' => trim(isset($_POST['job']) ? $_POST['job'] : ''),
            'education' => trim(isset($_POST['education']) ? $_POST['education'] : ''),
            'marital' => trim(isset($_POST['marital']) ? $_POST['marital'] : ''),
            'mobile' => isset($_POST['mobile']) ? $_POST['mobile'] : $c['mobile'],
            'emergency_contact' => trim(isset($_POST['emergency_contact']) ? $_POST['emergency_contact'] : ''),
            'referrer' => trim(isset($_POST['referrer']) ? $_POST['referrer'] : ''),
            'first_visit_date' => clinic_valid_jdate(isset($_POST['first_visit_date']) ? $_POST['first_visit_date'] : ''),
        );
        if (in_array($role, array('admin', 'head_secretary'), true) && isset($_POST['doctor_id'])) {
            $patch['doctor_id'] = (int) $_POST['doctor_id'];
        }
        $res = clinic_update_client($cid, $patch, clinic_my_id());
        if (isset($res['ok'])) {
            clinic_audit('ویرایش اطلاعات پایه', $cid, '');
            flash_set('ok', 'اطلاعات پایه به‌روز شد.');
        } else {
            flash_set('bad', $res['error']);
        }
        $go('summary');
    }

    if (($act === 'archive' || $act === 'unarchive') && in_array($role, array('admin', 'doctor', 'head_secretary'), true)) {
        $patch = array('status' => $act === 'archive' ? 'archived' : 'active');
        if ($patch['status'] === 'archived') $patch['archived_at'] = joma_now();
        clinic_update_client($cid, $patch, clinic_my_id());
        clinic_audit($patch['status'] === 'archived' ? 'بایگانی پرونده' : 'خروج از بایگانی', $cid, '');
        flash_set('ok', 'وضعیت پرونده به‌روز شد.');
        $go('summary');
    }

    if ($act === 'intake_save_doctor' && clinic_can_edit_clinical($c)) {
        $in = clinic_intake_from_post();
        // موبایل پرونده تغییر نکند
        $in['s1']['mobile'] = clinic_norm_mobile($c['mobile']);
        clinic_intake_save($cid, $in, 'doctor');
        clinic_audit('ویرایش فرم جلسه اول (درمانگر)', $cid, '');
        flash_set('ok', 'فرم جلسه اول ذخیره شد.');
        $go('intake');
    }

    if ($act === 'mark_reviewed' && clinic_can_edit_clinical($c)) {
        clinic_mark_reviewed($cid, clinic_my_id());
        clinic_audit('تأیید بررسی فرم جلسه اول', $cid, '');
        flash_set('ok', 'فرم «بررسی‌شده توسط درمانگر» شد. ✅');
        $go('intake');
    }

    if ($act === 'note_save' && clinic_can_edit_clinical($c)) {
        $res = clinic_create_note(array(
            'client_id' => $cid,
            'appointment_id' => isset($_POST['appointment_id']) ? $_POST['appointment_id'] : 0,
            'text' => isset($_POST['text']) ? $_POST['text'] : '',
            'duration' => isset($_POST['duration']) ? $_POST['duration'] : 0,
            'tags' => isset($_POST['tags']) ? $_POST['tags'] : '',
            'progress' => isset($_POST['progress']) ? $_POST['progress'] : 0,
            'next_plan' => isset($_POST['next_plan']) ? $_POST['next_plan'] : '',
        ), clinic_my_id());
        if (isset($res['id'])) {
            clinic_audit('ثبت خلاصه جلسه', $cid, '');
            flash_set('ok', 'خلاصه جلسه ثبت شد.');
        } else {
            flash_set('bad', $res['error']);
        }
        $go('sessions');
    }

    if ($act === 'note_update' && clinic_can_edit_clinical($c)) {
        $res = clinic_update_note((int) (isset($_POST['note_id']) ? $_POST['note_id'] : 0), array(
            'text' => isset($_POST['text']) ? $_POST['text'] : '',
            'duration' => isset($_POST['duration']) ? $_POST['duration'] : 0,
            'tags' => isset($_POST['tags']) ? $_POST['tags'] : '',
            'progress' => isset($_POST['progress']) ? $_POST['progress'] : 0,
            'next_plan' => isset($_POST['next_plan']) ? $_POST['next_plan'] : '',
        ), clinic_my_id());
        flash_set(isset($res['ok']) ? 'ok' : 'bad', isset($res['ok']) ? 'خلاصه به‌روز شد.' : $res['error']);
        $go('sessions');
    }

    if ($act === 'private_save' && clinic_can_edit_clinical($c)) {
        clinic_save_private_note($cid, isset($_POST['private_note']) ? $_POST['private_note'] : '', clinic_my_id());
        clinic_audit('ویرایش یادداشت خصوصی', $cid, '');
        flash_set('ok', 'یادداشت خصوصی ذخیره شد.');
        $go('private');
    }

    if ($act === 'txn_add' && clinic_can_manage_finance()) {
        $res = clinic_create_transaction(array(
            'client_id' => $cid,
            'kind' => isset($_POST['kind']) ? $_POST['kind'] : 'payment',
            'amount' => isset($_POST['amount']) ? $_POST['amount'] : '',
            'method' => isset($_POST['method']) ? $_POST['method'] : 'card',
            'ref_no' => isset($_POST['ref_no']) ? $_POST['ref_no'] : '',
            'date' => isset($_POST['date']) ? $_POST['date'] : '',
            'appointment_id' => isset($_POST['appointment_id']) ? $_POST['appointment_id'] : 0,
            'note' => isset($_POST['note']) ? $_POST['note'] : '',
        ), clinic_my_id());
        if (isset($res['id'])) {
            clinic_audit('ثبت تراکنش مالی', $cid, clinic_money((int) $_POST['amount']));
            flash_set('ok', 'تراکنش ثبت شد.');
        } else {
            flash_set('bad', $res['error']);
        }
        $go('finance');
    }

    if ($act === 'txn_delete' && in_array($role, array('admin', 'head_secretary'), true)) {
        $res = clinic_delete_transaction((int) (isset($_POST['txn_id']) ? $_POST['txn_id'] : 0), clinic_my_id());
        flash_set(isset($res['ok']) ? 'ok' : 'bad', isset($res['ok']) ? 'تراکنش حذف شد.' : $res['error']);
        $go('finance');
    }

    if ($act === 'set_password' && clinic_can_edit_client_base($c)) {
        $pass = isset($_POST['new_password']) ? (string) $_POST['new_password'] : '';
        $res = clinic_client_user_ensure($cid, $pass);
        if (isset($res['user_id'])) {
            clinic_audit('تعیین رمز ورود مراجع', $cid, '');
            flash_set('ok', 'حساب ورود مراجع فعال شد (نام کاربری = موبایل).');
        } else {
            flash_set('bad', $res['error']);
        }
        $go('summary');
    }

    if ($act === 'appt_status' && clinic_is_staff()) {
        $res = clinic_set_appointment_status(
            (int) (isset($_POST['appt_id']) ? $_POST['appt_id'] : 0),
            isset($_POST['status']) ? $_POST['status'] : '',
            clinic_my_id(),
            isset($_POST['reason']) ? $_POST['reason'] : ''
        );
        if (isset($res['ok'])) {
            clinic_audit('تغییر وضعیت نوبت', $cid, '');
            flash_set('ok', $res['late'] ? 'وضعیت ثبت شد ⚠️ (لغو دیرهنگام — کمتر از ۲۴ ساعت مانده بود)' : 'وضعیت نوبت به‌روز شد.');
        } else {
            flash_set('bad', $res['error']);
        }
        $go('sessions');
    }
}

// تازه‌سازی پرونده بعد از عملیات
$c = clinic_get_client($cid);
$doc = get_user((int) $c['doctor_id']);
$opt = clinic_intake_options();
$tot = clinic_client_totals($cid);
$fl = clinic_client_flags($c);

joma_header('پرونده ' . clinic_client_display_name($c), array(
    array('label' => 'مدیریت مطب', 'href' => joma_url('index.php?p=clinic_dashboard')),
    array('label' => 'پرونده‌ها', 'href' => joma_url('index.php?p=clinic_clients')),
    array('label' => clinic_client_display_name($c)),
));

// ---- سربرگ پرونده ----
echo '<div class="card"><div class="clinic-row"><div class="grow">';
echo '<h1 style="margin:0">🗂️ ' . clinic_h(clinic_client_display_name($c)) . '</h1>';
echo '<p class="hint">شماره پرونده: <strong>' . clinic_h(fa_num($c['file_no'])) . '</strong> | درمانگر: <strong>' . clinic_h($doc ? clinic_user_display($doc) : '—') . '</strong></p>';
echo '</div><div>';
echo ($c['status'] === 'active' ? clinic_badge('فعال', 'b-green') : clinic_badge('بایگانی‌شده', 'b-gray')) . ' ';
echo clinic_intake_badge($c['intake_status']);
echo '</div></div>';

// هشدارهای بالینی — فقط درمانگر و مدیر
if (clinic_can_clinical() && ($fl['violence'] || $fl['risks'])) {
    if ($fl['violence']) {
        $needs5 = ($c['intake_status'] !== 'reviewed');
        echo '<div class="clinic-alert orange">⚠️ خشونت در رابطه گزارش شده است.' . ($needs5 ? ' <strong>ارزیابی ایمنی (بخش ۵) را با اولویت بالا تکمیل/بازبینی کنید.</strong>' : '') . '</div>';
    }
    if ($fl['risks']) {
        $names = array();
        foreach ($fl['risks'] as $rk) $names[] = clinic_risk_label($rk);
        echo '<div class="clinic-alert red">🚨 شاخص خطر: <strong>' . clinic_h(implode('، ', $names)) . '</strong> — اقدام فوری و ارجاع لازم است؛ صرف ثبت در پرونده کافی نیست.</div>';
    }
}

// زبانه‌ها
$tabs = array('summary' => 'خلاصه', 'sessions' => 'جلسات', 'intake' => 'فرم جلسه اول', 'finance' => 'مالی');
if (clinic_can_edit_clinical($c)) $tabs['private'] = 'یادداشت خصوصی 🔒';
if (clinic_is_staff()) $tabs['log'] = 'تاریخچه';
echo '<div class="clinic-tabs">';
foreach ($tabs as $k => $v) {
    $cls = $tab === $k ? 'on' : '';
    echo '<a class="' . $cls . '" href="' . e(joma_url('index.php?p=clinic_client&id=' . $cid . '&tab=' . $k)) . '">' . clinic_h($v) . '</a>';
}
echo '</div></div>';

if ($msg !== '') echo '<div class="card"><p class="bad">' . $msg . '</p></div>';

// ================= خلاصه =================
if ($tab === 'summary') {
    echo '<div class="card"><h2>مشخصات</h2><div class="clinic-kvbox">';
    $kv = function ($l, $v) { echo '<div class="clinic-kv"><span>' . clinic_h($l) . '</span><strong>' . clinic_h($v !== '' ? $v : '—') . '</strong></div>'; };
    $kv('نام و نام خانوادگی', clinic_client_display_name($c));
    $kv('تاریخ تولد', $c['birth_date'] !== '' ? clinic_fa_date($c['birth_date']) . (clinic_age_from_birth($c['birth_date']) !== '' ? ' (' . fa_num(clinic_age_from_birth($c['birth_date'])) . ' ساله)' : '') : '');
    $kv('شغل', $c['job']);
    $kv('تحصیلات', $c['education']);
    $kv('وضعیت تأهل', $c['marital']);
    $kv('موبایل', fa_num($c['mobile']));
    $kv('تماس اضطراری', $c['emergency_contact']);
    $kv('معرف', $c['referrer'] . ($c['referrer_other'] !== '' ? ' — ' . $c['referrer_other'] : ''));
    $kv('اولین مراجعه', $c['first_visit_date'] !== '' ? clinic_fa_date($c['first_visit_date']) : '');
    $kv('تعداد جلسات برگزار شده', fa_num(clinic_client_done_count($cid)));
    $next = clinic_client_next_appointment($cid);
    $kv('نوبت بعدی', $next ? clinic_fa_date($next['date']) . ' — ساعت ' . fa_num($next['start']) : 'ثبت نشده');
    echo '</div>';
    echo '<div class="clinic-grid">';
    echo '<div class="clinic-stat"><small>جمع هزینه جلسات</small><b>' . clinic_h(clinic_money_short($tot['expected'] + $tot['charge'])) . '</b></div>';
    echo '<div class="clinic-stat"><small>پرداخت‌شده</small><b>' . clinic_h(clinic_money_short($tot['paid'] + $tot['prepay'])) . '</b></div>';
    echo '<div class="clinic-stat"><small>تخفیف</small><b>' . clinic_h(clinic_money_short($tot['discount'])) . '</b></div>';
    echo '<div class="clinic-stat"><small>مانده حساب</small><b>' . clinic_h(clinic_money_short($tot['debt'])) . '</b></div>';
    echo '</div></div>';

    if (clinic_can_edit_client_base($c)) {
        echo '<div class="card"><h2>✏️ ویرایش اطلاعات پایه</h2>';
        echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_client&id=' . $cid . '&tab=summary')) . '">';
        echo csrf_field() . '<input type="hidden" name="clinic_action" value="update_base">';
        echo '<div class="field-row"><div>' . clinic_field_text('first_name', 'نام', $c['first_name'], '', '') . '</div>';
        echo '<div>' . clinic_field_text('last_name', 'نام خانوادگی', $c['last_name'], '', '') . '</div></div>';
        echo '<div class="field-row"><div>' . clinic_field_text('mobile', 'موبایل', $c['mobile'], '', 'ltr') . '</div>';
        echo '<div>' . clinic_field_text('birth_date', 'تاریخ تولد', $c['birth_date'], '1370-05-12', 'ltr') . '</div></div>';
        echo '<div class="field-row"><div>' . clinic_field_text('job', 'شغل', $c['job'], '', '') . '</div>';
        echo '<div>' . clinic_field_text('education', 'تحصیلات', $c['education'], '', '') . '</div></div>';
        $marmap = array('' => '—');
        foreach ($opt['marital'] as $m) $marmap[$m] = $m;
        echo clinic_field_select('marital', 'وضعیت تأهل', $marmap, $c['marital'], null);
        echo clinic_field_text('emergency_contact', 'تماس اضطراری', $c['emergency_contact'], '', '');
        $refmap = array('' => '—');
        foreach ($opt['referrer'] as $m) $refmap[$m] = $m;
        echo clinic_field_select('referrer', 'معرف', $refmap, $c['referrer'], null);
        echo clinic_field_text('first_visit_date', 'تاریخ اولین مراجعه', $c['first_visit_date'], '', 'ltr');
        if (in_array($role, array('admin', 'head_secretary'), true)) {
            $dmap = array();
            foreach (clinic_doctors_list() as $d) $dmap[$d['id']] = clinic_user_display($d);
            echo clinic_field_select('doctor_id', 'دکتر معالج', $dmap, $c['doctor_id'], null);
        }
        echo '<p><button class="btn" type="submit">ذخیره</button></p></form></div>';

        echo '<div class="card"><h2>🔐 حساب ورود مراجع</h2>';
        if ((int) $c['user_id'] > 0) {
            echo '<p class="hint">حساب ورود فعال است (نام کاربری = موبایل). برای تغییر رمز، رمز جدید را وارد کنید:</p>';
        } else {
            echo '<p class="hint">هنوز حساب ورود ساخته نشده. با تعیین رمز، حساب ساخته می‌شود (نام کاربری = موبایل):</p>';
        }
        echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_client&id=' . $cid . '&tab=summary')) . '">';
        echo csrf_field() . '<input type="hidden" name="clinic_action" value="set_password">';
        echo '<label>رمز جدید (حداقل ۶ نویسه)</label><input type="text" name="new_password" dir="ltr" autocomplete="off">';
        echo '<p><button class="btn" type="submit">تعیین رمز</button></p></form></div>';

        if (in_array($role, array('admin', 'doctor', 'head_secretary'), true)) {
            echo '<div class="card"><h2>📦 بایگانی</h2>';
            echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_client&id=' . $cid . '&tab=summary')) . '" onsubmit="return confirm(\'مطمئن هستید؟\');">';
            echo csrf_field();
            if ($c['status'] === 'active') {
                echo '<input type="hidden" name="clinic_action" value="archive">';
                echo '<p><button class="btn btn-ghost" type="submit">بایگانی این پرونده</button></p>';
            } else {
                echo '<input type="hidden" name="clinic_action" value="unarchive">';
                echo '<p><button class="btn" type="submit">خروج از بایگانی (فعال‌سازی)</button></p>';
            }
            echo '</form></div>';
        }
    }
}

// ================= جلسات =================
if ($tab === 'sessions') {
    $appts = clinic_list_appointments(array('client_id' => $cid));
    usort($appts, function ($x, $y) {
        if ($x['date'] === $y['date']) return strcmp($y['start'], $x['start']);
        return strcmp($y['date'], $x['date']);
    });
    $types = clinic_appt_types();
    $can_clin = clinic_can_edit_clinical($c);
    echo '<div class="card"><h2>تایم‌لاین جلسات</h2>';
    if (clinic_is_staff()) {
        echo '<p><a class="btn btn-small" href="' . e(joma_url('index.php?p=clinic_appointments&new=1&client_id=' . $cid)) . '">+ نوبت جدید برای این مراجع</a></p>';
    }
    if (!$appts) {
        echo '<p class="hint">هنوز جلسه‌ای ثبت نشده است.</p>';
    } else {
        echo '<div class="clinic-timeline">';
        foreach ($appts as $a) {
            $sno = clinic_session_no_for($a);
            $note = clinic_note_for_appointment((int) $a['id']);
            echo '<div class="clinic-tl-item clinic-card">';
            echo '<h4>جلسه ' . clinic_h(fa_num($sno)) . ' — ' . clinic_h(clinic_fa_date($a['date'])) . ' <small class="hint">' . clinic_h(fa_num($a['start'] . ' تا ' . $a['end'])) . '</small></h4>';
            echo '<p>' . clinic_status_badge($a['status']) . ' ' . clinic_badge($types[$a['type']], 'b-gray') . ' ';
            echo clinic_badge(clinic_money($a['fee']), 'b-blue');
            if (!empty($a['late_cancel'])) echo ' ' . clinic_badge('لغو دیرهنگام', 'b-orange');
            echo '</p>';
            if ($a['note'] !== '' && clinic_is_staff()) echo '<p class="hint">یادداشت نوبت: ' . clinic_h($a['note']) . '</p>';
            // خلاصه
            if ($note && $can_clin) {
                echo '<div class="clinic-kvbox"><div class="clinic-kv"><span>خلاصه جلسه</span><strong>' . nl2br(clinic_h($note['text'])) . '</strong></div>';
                if ($note['next_plan'] !== '') echo '<div class="clinic-kv"><span>برنامه جلسه بعد</span><strong>' . nl2br(clinic_h($note['next_plan'])) . '</strong></div>';
                $meta = array();
                if ((int) $note['duration'] > 0) $meta[] = 'مدت: ' . fa_num($note['duration']) . ' دقیقه';
                if ((int) $note['progress'] > 0) $meta[] = 'پیشرفت: ' . fa_num($note['progress']) . ' از ۵';
                if (!empty($note['tags'])) $meta[] = '🏷 ' . implode('، ', array_map('clinic_h', $note['tags']));
                if ($meta) echo '<p class="hint">' . implode(' | ', $meta) . '</p>';
                echo '<p><a class="btn btn-small" href="' . e(joma_url('index.php?p=clinic_client&id=' . $cid . '&tab=sessions&note_edit=' . (int) $note['id'])) . '">ویرایش خلاصه</a></p></div>';
            } elseif ($note && !$can_clin) {
                echo '<p class="hint">خلاصه جلسه ثبت شده ✅ (فقط درمانگر)</p>';
            } elseif ($a['status'] === 'done' && $can_clin) {
                echo '<p><a class="btn btn-small" href="' . e(joma_url('index.php?p=clinic_client&id=' . $cid . '&tab=sessions&note_appt=' . (int) $a['id'])) . '">✍️ ثبت خلاصه این جلسه</a></p>';
            } elseif ($a['status'] === 'done') {
                echo '<p class="hint">خلاصه ثبت نشده.</p>';
            }
            // تغییر وضعیت سریع (کادر)
            if (clinic_is_staff() && in_array($a['status'], array('reserved', 'confirmed'), true)) {
                echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_client&id=' . $cid . '&tab=sessions')) . '" style="display:inline">';
                echo csrf_field() . '<input type="hidden" name="clinic_action" value="appt_status"><input type="hidden" name="appt_id" value="' . (int) $a['id'] . '">';
                echo '<input type="hidden" name="status" value="done"><button class="btn btn-small" type="submit">برگزار شد ✅</button></form> ';
            }
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</div>';

    // فرم ثبت خلاصه
    $note_appt = (int) (isset($_GET['note_appt']) ? $_GET['note_appt'] : 0);
    if ($note_appt > 0 && $can_clin) {
        $na = clinic_get_appointment($note_appt);
        if ($na && (int) $na['client_id'] === $cid && !clinic_note_for_appointment($note_appt)) {
            echo '<div class="card"><h2>✍️ ثبت خلاصه جلسه ' . clinic_h(fa_num(clinic_session_no_for($na))) . ' (' . clinic_h(clinic_fa_date($na['date'])) . ')</h2>';
            echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_client&id=' . $cid . '&tab=sessions')) . '">';
            echo csrf_field() . '<input type="hidden" name="clinic_action" value="note_save"><input type="hidden" name="appointment_id" value="' . $note_appt . '">';
            echo clinic_field_textarea('text', 'متن خلاصه جلسه *', '', 5, '');
            echo '<div class="field-row"><div>' . clinic_field_text('duration', 'مدت جلسه (دقیقه)', '', '', 'ltr') . '</div>';
            $pmap = array('0' => '—', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵');
            echo '<div>' . clinic_field_select('progress', 'ارزیابی پیشرفت (۱ تا ۵)', $pmap, '0', null) . '</div></div>';
            echo clinic_field_text('tags', 'برچسب‌ها (با ویرگول جدا کنید)', '', '', '');
            echo clinic_field_textarea('next_plan', 'برنامه جلسه بعد / تکلیف مراجع', '', 2, '');
            echo '<p><button class="btn" type="submit">ثبت خلاصه</button></p></form></div>';
        }
    }
    // فرم ویرایش خلاصه
    $note_edit = (int) (isset($_GET['note_edit']) ? $_GET['note_edit'] : 0);
    if ($note_edit > 0 && $can_clin) {
        $data = store_load();
        $ne = null;
        foreach ($data['clinic_notes'] as $n) {
            if ((int) $n['id'] === $note_edit && (int) $n['client_id'] === $cid) $ne = $n;
        }
        if ($ne) {
            echo '<div class="card"><h2>ویرایش خلاصه</h2>';
            echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_client&id=' . $cid . '&tab=sessions')) . '">';
            echo csrf_field() . '<input type="hidden" name="clinic_action" value="note_update"><input type="hidden" name="note_id" value="' . $note_edit . '">';
            echo clinic_field_textarea('text', 'متن خلاصه جلسه *', $ne['text'], 5, '');
            echo '<div class="field-row"><div>' . clinic_field_text('duration', 'مدت جلسه (دقیقه)', $ne['duration'], '', 'ltr') . '</div>';
            $pmap = array('0' => '—', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵');
            echo '<div>' . clinic_field_select('progress', 'ارزیابی پیشرفت', $pmap, (string) $ne['progress'], null) . '</div></div>';
            echo clinic_field_text('tags', 'برچسب‌ها', implode('، ', $ne['tags']), '', '');
            echo clinic_field_textarea('next_plan', 'برنامه جلسه بعد', $ne['next_plan'], 2, '');
            echo '<p><button class="btn" type="submit">ذخیره</button></p></form></div>';
        }
    }
}

// ================= فرم جلسه اول =================
if ($tab === 'intake') {
    $in = isset($c['intake']) && is_array($c['intake']) && $c['intake'] ? $c['intake'] : null;
    $can_clin = clinic_can_clinical();
    $can_edit = clinic_can_edit_clinical($c);
    $is_owner = ($role === 'client');
    echo '<div class="card"><h2>📋 فرم ارزیابی بالینی جلسه اول</h2>';
    echo '<p>وضعیت: ' . clinic_intake_badge($c['intake_status']) . ' ';
    if ($in && !empty($in['submitted_at'])) echo '<span class="hint">ثبت: ' . clinic_h(clinic_fa_datetime($in['submitted_at'])) . '</span> ';
    if ($in && !empty($in['reviewed_at'])) echo '<span class="hint">بررسی: ' . clinic_h(clinic_fa_datetime($in['reviewed_at'])) . '</span>';
    echo '</p>';

    if (!$can_clin && !$is_owner) {
        // منشی: فقط وضعیت
        echo '<div class="clinic-alert blue">🔒 محتوای بالینی این فرم فقط برای درمانگر قابل مشاهده است.</div>';
        if ($c['intake_status'] === 'none') {
            echo '<p><a class="btn btn-small" href="' . e(joma_url('index.php?p=clinic_clients&invite=1')) . '">🔗 ساخت لینک تکمیل فرم برای این مراجع</a></p>';
        }
        echo '</div>';
    } else {
        $edit_mode = isset($_GET['intake_edit']) && $can_edit;
        if ($edit_mode) {
            if (!$in) $in = clinic_intake_empty();
            // پیش‌پر کردن بخش ۱ از پرونده اگر خالی بود
            if ($in['s1']['first_name'] === '') $in['s1']['first_name'] = $c['first_name'];
            if ($in['s1']['last_name'] === '') $in['s1']['last_name'] = $c['last_name'];
            if ($in['s1']['mobile'] === '') $in['s1']['mobile'] = $c['mobile'];
            echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_client&id=' . $cid . '&tab=intake')) . '">';
            echo csrf_field() . '<input type="hidden" name="clinic_action" value="intake_save_doctor">';
            echo clinic_intake_form_html($in, $opt, 'full');
            echo '<p><button class="btn" type="submit">ذخیره فرم</button></p></form>';
        } else {
            echo clinic_intake_view_html($in, $opt);
            if ($can_edit) {
                echo '<p><a class="btn" href="' . e(joma_url('index.php?p=clinic_client&id=' . $cid . '&tab=intake&intake_edit=1')) . '">✏️ ویرایش فرم</a> ';
                if ($c['intake_status'] === 'self') {
                    echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_client&id=' . $cid . '&tab=intake')) . '" style="display:inline" onsubmit="return confirm(\'فرم بازبینی و تأیید شود؟\');">';
                    echo csrf_field() . '<input type="hidden" name="clinic_action" value="mark_reviewed">';
                    echo '<button class="btn" type="submit">✅ تأیید بررسی درمانگر</button></form>';
                }
                echo '</p>';
            }
            if ($is_owner && $c['intake_status'] === 'none') {
                echo '<p><a class="btn" href="' . e(joma_url('index.php?p=clinic_intake')) . '">تکمیل فرم</a></p>';
            }
        }
        echo '</div>';
    }
}

// ================= مالی =================
if ($tab === 'finance') {
    $txns = clinic_list_transactions(array('client_id' => $cid));
    echo '<div class="card"><h2>💰 وضعیت مالی</h2>';
    echo '<div class="clinic-grid">';
    echo '<div class="clinic-stat"><small>هزینه جلسات + بدهکاری دستی</small><b>' . clinic_h(clinic_money_short($tot['expected'] + $tot['charge'])) . '</b></div>';
    echo '<div class="clinic-stat"><small>پرداخت + پیش‌پرداخت</small><b>' . clinic_h(clinic_money_short($tot['paid'] + $tot['prepay'])) . '</b></div>';
    echo '<div class="clinic-stat"><small>تخفیف</small><b>' . clinic_h(clinic_money_short($tot['discount'])) . '</b></div>';
    echo '<div class="clinic-stat"><small>مانده حساب</small><b>' . clinic_h(clinic_money_short($tot['debt'])) . '</b></div>';
    echo '</div>';
    if ($txns) {
        $kinds = clinic_txn_kinds();
        $methods = clinic_pay_methods();
        echo '<div class="table-wrap"><table class="clinic-table"><tr><th>تاریخ</th><th>نوع</th><th>مبلغ</th><th>روش</th><th>پیگیری/یادداشت</th><th></th></tr>';
        foreach ($txns as $t) {
            echo '<tr><td>' . clinic_h(clinic_fa_date($t['date'])) . '</td>';
            echo '<td>' . clinic_h($kinds[$t['kind']]) . '</td>';
            echo '<td>' . clinic_h(clinic_money($t['amount'])) . '</td>';
            echo '<td>' . clinic_h(isset($methods[$t['method']]) ? $methods[$t['method']] : $t['method']) . '</td>';
            echo '<td>' . clinic_h(trim($t['ref_no'] . ' ' . $t['note'])) . '</td><td>';
            if ($t['kind'] === 'payment' || $t['kind'] === 'prepay') {
                echo '<a class="btn btn-small" href="' . e(joma_url('index.php?p=clinic_receipt&id=' . (int) $t['id'])) . '">رسید</a> ';
            }
            if (in_array($role, array('admin', 'head_secretary'), true)) {
                echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_client&id=' . $cid . '&tab=finance')) . '" style="display:inline" onsubmit="return confirm(\'حذف شود؟\');">';
                echo csrf_field() . '<input type="hidden" name="clinic_action" value="txn_delete"><input type="hidden" name="txn_id" value="' . (int) $t['id'] . '">';
                echo '<button class="btn btn-small btn-ghost" type="submit">حذف</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</table></div>';
    } else {
        echo '<p class="hint">تراکنشی ثبت نشده است.</p>';
    }
    echo '</div>';

    if (clinic_can_manage_finance()) {
        echo '<div class="card"><h2>➕ ثبت تراکنش</h2>';
        echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_client&id=' . $cid . '&tab=finance')) . '">';
        echo csrf_field() . '<input type="hidden" name="clinic_action" value="txn_add">';
        $kinds = clinic_txn_kinds();
        echo clinic_field_select('kind', 'نوع', $kinds, 'payment', null);
        echo '<div class="field-row"><div>' . clinic_field_text('amount', 'مبلغ (تومان) *', '', '', 'ltr') . '</div>';
        echo '<div>' . clinic_field_text('date', 'تاریخ', clinic_today(), '', 'ltr') . '</div></div>';
        $methods = clinic_pay_methods();
        echo clinic_field_select('method', 'روش پرداخت', $methods, 'card', null);
        $appts = clinic_list_appointments(array('client_id' => $cid));
        if ($appts) {
            $amap = array('0' => '— بدون اتصال به جلسه —');
            foreach ($appts as $a) $amap[$a['id']] = clinic_fa_date($a['date']) . ' — ' . $a['start'];
            echo clinic_field_select('appointment_id', 'اتصال به جلسه (اختیاری)', $amap, '0', null);
        }
        echo clinic_field_text('ref_no', 'شماره پیگیری', '', '', 'ltr');
        echo clinic_field_text('note', 'یادداشت', '', '', '');
        echo '<p><button class="btn" type="submit">ثبت</button></p></form></div>';
    } elseif ($role === 'doctor') {
        echo '<div class="card"><p class="hint">ثبت مالی بر عهده منشی است. (نمایش فقط خواندنی)</p></div>';
    }
}

// ================= یادداشت خصوصی =================
if ($tab === 'private') {
    echo '<div class="card"><h2>🔒 یادداشت خصوصی درمانگر</h2>';
    echo '<p class="hint">فقط شما (و مدیر سیستم) این یادداشت را می‌بینید. در چاپ و گزارش‌ها نمی‌آید.</p>';
    echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_client&id=' . $cid . '&tab=private')) . '">';
    echo csrf_field() . '<input type="hidden" name="clinic_action" value="private_save">';
    echo clinic_field_textarea('private_note', 'یادداشت', $c['private_note'], 8, 'فرضیه‌ها، برنامه درمان، موارد قابل پیگیری...');
    if ($c['private_updated_at'] !== '') echo '<p class="hint">آخرین ویرایش: ' . clinic_h(clinic_fa_datetime($c['private_updated_at'])) . '</p>';
    echo '<p><button class="btn" type="submit">ذخیره</button></p></form></div>';
}

// ================= تاریخچه =================
if ($tab === 'log') {
    $audit = clinic_list_audit($cid, 200);
    echo '<div class="card"><h2>تاریخچه تغییرات پرونده</h2>';
    if (!$audit) {
        echo '<p class="hint">رویدادی ثبت نشده است.</p>';
    } else {
        echo '<div class="table-wrap"><table class="clinic-table"><tr><th>زمان</th><th>کاربر</th><th>رویداد</th></tr>';
        foreach ($audit as $a) {
            $au = get_user($a['user_id']);
            echo '<tr><td>' . clinic_h(clinic_fa_datetime($a['at'])) . '</td><td>' . clinic_h($au ? clinic_user_display($au) : 'سیستم') . '</td><td>' . clinic_h($a['action'] . ($a['detail'] !== '' ? ' — ' . $a['detail'] : '')) . '</td></tr>';
        }
        echo '</table></div>';
    }
    echo '</div>';
}

joma_footer();
