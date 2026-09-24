<?php
// پیشخوان «مدیریت مطب» — نمای متفاوت برای هر نقش
clinic_require_login();
$role = clinic_role();
$roles = clinic_roles();
if (!isset($roles[$role])) {
    joma_redirect('index.php?p=dashboard');
}
$u = current_user();
joma_header('پیشخوان مطب', array(array('label' => 'مدیریت مطب')));

echo '<div class="card">';
echo '<h1>🏛️ پیشخوان مطب</h1>';
echo '<p class="hint">' . clinic_h(clinic_user_display($u)) . ' — ' . clinic_h($roles[$role]) . ' | امروز ' . clinic_h(clinic_fa_date(clinic_today())) . '</p>';
echo '</div>';

if ($role !== 'client') echo '<div class="card"><a class="btn" href="index.php?p=clinic_sms">پیامک، دعوت‌نامه و تنظیمات sms.ir</a></div>';

if ($role === 'client') {
    $c = clinic_get_client_by_user($u['id']);
    if (!$c) {
        echo '<div class="card"><p class="bad">پرونده‌ای برای شما ثبت نشده است. لطفاً با مطب تماس بگیرید.</p></div>';
        joma_footer();
        return;
    }
    $tot = clinic_client_totals((int) $c['id']);
    $next = clinic_client_next_appointment((int) $c['id']);
    $doc = clinic_get_user((int) $c['doctor_id']);
    echo '<div class="card"><h2>🗂️ پرونده من</h2>';
    echo '<div class="clinic-grid">';
    echo '<div class="clinic-stat"><small>شماره پرونده</small><b>' . clinic_h(fa_num($c['file_no'])) . '</b></div>';
    echo '<div class="clinic-stat"><small>درمانگر</small><b>' . clinic_h($doc ? clinic_user_display($doc) : '—') . '</b></div>';
    echo '<div class="clinic-stat"><small>جلسات برگزار شده</small><b>' . clinic_h(fa_num(clinic_client_done_count((int) $c['id']))) . '</b></div>';
    echo '<div class="clinic-stat"><small>مانده حساب</small><b>' . clinic_h(clinic_money($tot['debt'])) . '</b></div>';
    echo '</div>';
    echo '<p>وضعیت فرم جلسه اول: ' . clinic_intake_badge($c['intake_status']) . '</p>';
    if ($c['intake_status'] === 'none') {
        echo '<div class="clinic-alert blue">فرم جلسه اول را هنوز تکمیل نکرده‌اید. <a href="' . e(joma_url('index.php?p=clinic_intake')) . '"><strong>تکمیل فرم</strong></a></div>';
    }
    if ($next) {
        $types = clinic_appt_types();
        echo '<div class="clinic-alert green">📅 نوبت بعدی شما: <strong>' . clinic_h(clinic_fa_date($next['date'])) . '</strong> — ساعت <strong>' . clinic_h(fa_num($next['start'])) . '</strong> (' . clinic_h($types[$next['type']]) . ')</div>';
    } else {
        echo '<div class="clinic-alert orange">نوبت بعدی برای شما ثبت نشده است. برای رزرو با مطب هماهنگ کنید.</div>';
    }
    echo '<p><a class="btn" href="' . e(joma_url('index.php?p=clinic_client')) . '">مشاهده پرونده من</a> ';
    echo '<a class="btn btn-ghost" href="' . e(joma_url('index.php?p=clinic_finance')) . '">سوابق پرداخت</a></p>';
    echo '</div>';
    echo '<div class="card"><h2>قدم‌های بعدی شما</h2><p><a class="btn" href="https://test.mirbolouki.com" target="_blank" rel="noopener noreferrer">سایت تست میربلوکی</a> <a class="btn" href="https://joma.mirbolouki.com" target="_blank" rel="noopener noreferrer">پلنر هوشمند جوما</a></p><p>با راهنمایی پزشک استفاده کنید. در این نسخه، حساب و نتایج این سایت‌ها هنوز به پرونده متصل نیستند.</p></div>';
    $set = clinic_get_settings();
    if ($set['clinic_contact'] !== '') {
        echo '<div class="card"><h2>📞 تماس با مطب</h2><p>' . clinic_h($set['clinic_contact']) . '</p></div>';
    }
    joma_footer();
    return;
}

// ---- کادر مطب ----
$scope = clinic_scope_doctor_ids();
$today = clinic_today();
$today_appts = clinic_list_appointments(array('date' => $today));
$week_new = clinic_count_new_week($scope);
$active_clients = clinic_list_clients(array('status' => 'active'));
$tomorrow_m = jalali_to_gregorian((int) substr($today, 0, 4), (int) substr($today, 5, 2), (int) substr($today, 8, 2));
$tm_ts = mktime(0, 0, 0, $tomorrow_m[1], $tomorrow_m[2] + 1, $tomorrow_m[0]);
$tm_j = gregorian_to_jalali((int) date('Y', $tm_ts), (int) date('n', $tm_ts), (int) date('j', $tm_ts));
$tomorrow = $tm_j[0] . '-' . str_pad($tm_j[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($tm_j[2], 2, '0', STR_PAD_LEFT);
$tomorrow_appts = clinic_list_appointments(array('date' => $tomorrow));

echo '<div class="card"><h2>📌 نمای امروز</h2>';
echo '<div class="clinic-grid">';
echo '<div class="clinic-stat"><small>جلسات امروز</small><b>' . clinic_h(fa_num(count($today_appts))) . '</b></div>';
echo '<div class="clinic-stat"><small>جلسات فردا</small><b>' . clinic_h(fa_num(count($tomorrow_appts))) . '</b></div>';
echo '<div class="clinic-stat"><small>پرونده‌های فعال</small><b>' . clinic_h(fa_num(count($active_clients))) . '</b></div>';
echo '<div class="clinic-stat"><small>مراجعین جدید هفته</small><b>' . clinic_h(fa_num($week_new)) . '</b></div>';
echo '</div>';
// جستجوی سریع پرونده
echo '<form method="get" action="index.php" class="clinic-search">';
echo '<input type="hidden" name="p" value="clinic_clients">';
echo '<input name="q" placeholder="جستجوی سریع: نام، موبایل یا شماره پرونده..." autocomplete="off">';
echo '<button class="btn" type="submit">جستجو</button></form>';
echo '</div>';

// جلسات امروز
echo '<div class="card"><h2>📅 جلسات امروز</h2>';
if (!$today_appts) {
    echo '<p class="hint">امروز جلسه‌ای ثبت نشده است.</p>';
} else {
    $types = clinic_appt_types();
    echo '<div class="table-wrap"><table class="clinic-table"><tr><th>ساعت</th><th>مراجع</th><th>نوع</th><th>وضعیت</th><th></th></tr>';
    foreach ($today_appts as $a) {
        $c = clinic_get_client($a['client_id']);
        echo '<tr><td>' . clinic_h(fa_num($a['start'])) . '</td>';
        echo '<td><a href="' . e(joma_url('index.php?p=clinic_client&id=' . (int) $a['client_id'])) . '">' . clinic_h($c ? clinic_client_display_name($c) : '—') . '</a></td>';
        echo '<td>' . clinic_h($types[$a['type']]) . '</td>';
        echo '<td>' . clinic_status_badge($a['status']) . '</td>';
        echo '<td><a class="btn btn-small" href="' . e(joma_url('index.php?p=clinic_appointments&date=' . $a['date'])) . '">مدیریت</a></td></tr>';
    }
    echo '</table></div>';
}
echo '<p><a class="btn btn-ghost" href="' . e(joma_url('index.php?p=clinic_appointments')) . '">همه نوبت‌ها</a> ';
if ($role !== 'doctor') echo '<a class="btn" href="' . e(joma_url('index.php?p=clinic_appointments&new=1')) . '">+ نوبت جدید</a>';
echo '</p></div>';

// ---- ویژه دکتر ----
if ($role === 'doctor') {
    $missing = clinic_done_without_note($u['id']);
    $risky = clinic_list_clients(array('status' => 'active', 'flag' => 'risk'));
    $unrev = clinic_list_clients(array('status' => 'active', 'flag' => 'unreviewed'));
    echo '<div class="card"><h2>🩺 یادآورهای درمانی</h2>';
    if ($missing) {
        echo '<div class="clinic-alert orange">✍️ <strong>' . clinic_h(fa_num(count($missing))) . ' جلسه</strong> برگزار شده ولی خلاصه ندارد:</div>';
        echo '<div class="table-wrap"><table class="clinic-table">';
        foreach (array_slice($missing, 0, 10) as $a) {
            $c = clinic_get_client($a['client_id']);
            echo '<tr><td>' . clinic_h(clinic_fa_date($a['date'])) . '</td>';
            echo '<td>' . clinic_h($c ? clinic_client_display_name($c) : '—') . '</td>';
            echo '<td><a class="btn btn-small" href="' . e(joma_url('index.php?p=clinic_client&id=' . (int) $a['client_id'] . '&tab=sessions&note_appt=' . (int) $a['id'])) . '">ثبت خلاصه</a></td></tr>';
        }
        echo '</table></div>';
    }
    if ($unrev) {
        echo '<p>📝 فرم‌های در انتظار بررسی شما: <strong>' . clinic_h(fa_num(count($unrev))) . '</strong> — <a href="' . e(joma_url('index.php?p=clinic_clients&flag=unreviewed')) . '">مشاهده</a></p>';
    }
    if ($risky) {
        echo '<div class="clinic-alert red">🚨 <strong>' . clinic_h(fa_num(count($risky))) . ' پرونده</strong> شاخص خطر/خشونت دارد:</div>';
        echo '<div class="table-wrap"><table class="clinic-table">';
        foreach (array_slice($risky, 0, 10) as $c) {
            $fl = clinic_client_flags($c);
            $lbl = array();
            if ($fl['violence']) $lbl[] = 'خشونت';
            foreach ($fl['risks'] as $rk) $lbl[] = clinic_risk_label($rk);
            echo '<tr><td><a href="' . e(joma_url('index.php?p=clinic_client&id=' . (int) $c['id'] . '&tab=intake')) . '">' . clinic_h(clinic_client_display_name($c)) . '</a></td>';
            echo '<td>' . clinic_h(implode('، ', $lbl)) . '</td></tr>';
        }
        echo '</table></div>';
    }
    if (!$missing && !$unrev && !$risky) echo '<p class="hint">همه‌چیز به‌روز است. ✅</p>';
    echo '</div>';
}

// ---- ویژه منشی / مدیر ----
if (in_array($role, array('head_secretary', 'secretary', 'admin'), true)) {
    $doc_filter = ($role === 'secretary') ? clinic_my_doctor_id() : 0;
    $debtors = clinic_debtors($doc_filter);
    $no_next = clinic_clients_without_next($scope);
    $month_start = substr($today, 0, 7) . '-01';
    $income_month = clinic_income_sum($month_start, $today, $doc_filter);
    $income_today = clinic_income_sum($today, $today, $doc_filter);
    echo '<div class="card"><h2>💰 نمای اجرایی</h2>';
    echo '<div class="clinic-grid">';
    echo '<div class="clinic-stat"><small>دریافتی امروز</small><b>' . clinic_h(clinic_money_short($income_today)) . '</b></div>';
    echo '<div class="clinic-stat"><small>دریافتی این ماه</small><b>' . clinic_h(clinic_money_short($income_month)) . '</b></div>';
    echo '<div class="clinic-stat"><small>بدهکاران</small><b>' . clinic_h(fa_num(count($debtors))) . '</b></div>';
    echo '<div class="clinic-stat"><small>بدون نوبت بعدی</small><b>' . clinic_h(fa_num(count($no_next))) . '</b></div>';
    echo '</div>';
    echo '<p><a class="btn" href="' . e(joma_url('index.php?p=clinic_finance')) . '">ثبت پرداخت</a> ';
    echo '<a class="btn btn-ghost" href="' . e(joma_url('index.php?p=clinic_clients&new=1')) . '">+ پرونده دستی</a></p>';
    if ($debtors) {
        echo '<h3>بدهکاران (۵ نفر اول)</h3><div class="table-wrap"><table class="clinic-table">';
        foreach (array_slice($debtors, 0, 5) as $c) {
            echo '<tr><td><a href="' . e(joma_url('index.php?p=clinic_client&id=' . (int) $c['id'] . '&tab=finance')) . '">' . clinic_h(clinic_client_display_name($c)) . '</a></td>';
            echo '<td>' . clinic_h(clinic_money($c['_debt'])) . '</td></tr>';
        }
        echo '</table></div>';
    }
    echo '</div>';
}

// ---- ویژه مدیر ----
if ($role === 'admin') {
    $docs = clinic_doctors_list();
    echo '<div class="card"><h2>👥 نمای مدیر</h2>';
    echo '<p>تعداد دکترها: <strong>' . clinic_h(fa_num(count($docs))) . '</strong></p>';
    $audit = clinic_list_audit(0, 8);
    if ($audit) {
        echo '<h3>آخرین رویدادها</h3><div class="table-wrap"><table class="clinic-table">';
        foreach ($audit as $a) {
            $au = clinic_get_user($a['user_id']);
            echo '<tr><td>' . clinic_h(clinic_fa_datetime($a['at'])) . '</td><td>' . clinic_h($au ? clinic_user_display($au) : 'سیستم') . '</td><td>' . clinic_h($a['action'] . ($a['detail'] !== '' ? ' — ' . $a['detail'] : '')) . '</td></tr>';
        }
        echo '</table></div>';
    }
    echo '<p><a class="btn btn-ghost" href="' . e(joma_url('index.php?p=clinic_users')) . '">مدیریت کاربران</a> ';
    echo '<a class="btn btn-ghost" href="' . e(joma_url('index.php?p=clinic_settings')) . '">تنظیمات مطب</a></p>';
    echo '</div>';
}

joma_footer();
