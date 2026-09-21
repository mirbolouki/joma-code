<?php
// مالی مطب — تراکنش‌ها، بدهکاران، گزارش‌ها
clinic_require_login();
$role = clinic_role();
if (!in_array($role, array('admin', 'doctor', 'head_secretary', 'secretary', 'client'), true)) {
    joma_redirect('index.php?p=dashboard');
}
$kinds = clinic_txn_kinds();
$methods = clinic_pay_methods();
$today = clinic_today();

// ---- نمای مراجع ----
if ($role === 'client') {
    $mine = clinic_get_client_by_user(clinic_my_id());
    joma_header('پرداخت‌های من', array(array('label' => 'مدیریت مطب', 'href' => joma_url('index.php?p=clinic_dashboard')), array('label' => 'مالی')));
    echo '<div class="card"><h1>💰 پرداخت‌های من</h1>';
    if (!$mine) {
        echo '<p class="bad">پرونده‌ای برای شما ثبت نشده است.</p></div>';
        joma_footer();
        return;
    }
    $tot = clinic_client_totals((int) $mine['id']);
    echo '<div class="clinic-grid">';
    echo '<div class="clinic-stat"><small>جمع هزینه‌ها</small><b>' . clinic_h(clinic_money_short($tot['expected'] + $tot['charge'])) . '</b></div>';
    echo '<div class="clinic-stat"><small>پرداخت‌شده</small><b>' . clinic_h(clinic_money_short($tot['paid'] + $tot['prepay'])) . '</b></div>';
    echo '<div class="clinic-stat"><small>مانده حساب</small><b>' . clinic_h(clinic_money_short($tot['debt'])) . '</b></div>';
    echo '</div>';
    $txns = clinic_list_transactions(array('client_id' => (int) $mine['id']));
    if ($txns) {
        echo '<div class="table-wrap"><table class="clinic-table"><tr><th>تاریخ</th><th>نوع</th><th>مبلغ</th><th></th></tr>';
        foreach ($txns as $t) {
            echo '<tr><td>' . clinic_h(clinic_fa_date($t['date'])) . '</td><td>' . clinic_h($kinds[$t['kind']]) . '</td><td>' . clinic_h(clinic_money($t['amount'])) . '</td><td>';
            if ($t['kind'] === 'payment' || $t['kind'] === 'prepay') {
                echo '<a class="btn btn-small" href="' . e(joma_url('index.php?p=clinic_receipt&id=' . (int) $t['id'])) . '">رسید</a>';
            }
            echo '</td></tr>';
        }
        echo '</table></div>';
    } else {
        echo '<p class="hint">تراکنشی ثبت نشده است.</p>';
    }
    echo '</div>';
    joma_footer();
    return;
}

// ---- کادر ----
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clinic_action']) && $_POST['clinic_action'] === 'txn_add' && clinic_can_manage_finance()) {
    csrf_check();
    $res = clinic_create_transaction(array(
        'client_id' => isset($_POST['client_id']) ? $_POST['client_id'] : 0,
        'kind' => isset($_POST['kind']) ? $_POST['kind'] : 'payment',
        'amount' => isset($_POST['amount']) ? $_POST['amount'] : '',
        'method' => isset($_POST['method']) ? $_POST['method'] : 'card',
        'ref_no' => isset($_POST['ref_no']) ? $_POST['ref_no'] : '',
        'date' => isset($_POST['date']) ? $_POST['date'] : '',
        'note' => isset($_POST['note']) ? $_POST['note'] : '',
    ), clinic_my_id());
    if (isset($res['id'])) {
        clinic_audit('ثبت تراکنش مالی', (int) $_POST['client_id'], '');
        flash_set('ok', 'تراکنش ثبت شد.');
        joma_redirect('index.php?p=clinic_finance');
    } else {
        $msg = $res['error'];
    }
}

$f_from = clinic_valid_jdate(isset($_GET['from']) ? $_GET['from'] : '');
$f_to = clinic_valid_jdate(isset($_GET['to']) ? $_GET['to'] : '');
$f_doctor = (int) (isset($_GET['doctor_id']) ? $_GET['doctor_id'] : 0);
if ($role === 'doctor') $f_doctor = clinic_my_id();
if ($role === 'secretary') $f_doctor = clinic_my_doctor_id();

// خروجی اکسل (CSV)
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $role !== 'doctor') {
    $txns = clinic_list_transactions(array('from' => $f_from, 'to' => $f_to, 'doctor_id' => $f_doctor));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="clinic-finance.csv"');
    echo "\xEF\xBB\xBF";
    echo "تاریخ,مراجع,شماره پرونده,نوع,مبلغ (تومان),روش,پیگیری,یادداشت\n";
    foreach ($txns as $t) {
        $tc = clinic_get_client($t['client_id']);
        $cells = array(
            $t['date'], $tc ? clinic_client_display_name($tc) : '', $tc ? $tc['file_no'] : '',
            $kinds[$t['kind']], $t['amount'], isset($methods[$t['method']]) ? $methods[$t['method']] : $t['method'],
            str_replace(',', ' ', $t['ref_no']), str_replace(array(',', "\n", "\r"), ' ', $t['note']),
        );
        echo '"' . implode('","', $cells) . "\"\n";
    }
    exit;
}

$month_start = substr($today, 0, 7) . '-01';
$sum_today = clinic_income_sum($today, $today, $f_doctor);
$sum_month = clinic_income_sum($month_start, $today, $f_doctor);
$debtors = clinic_debtors($f_doctor);
$txns = clinic_list_transactions(array('from' => $f_from, 'to' => $f_to, 'doctor_id' => $f_doctor));
$per_doc = ($role === 'admin' || $role === 'head_secretary') ? clinic_per_doctor_income($month_start, $today) : array();

joma_header('مالی', array(array('label' => 'مدیریت مطب', 'href' => joma_url('index.php?p=clinic_dashboard')), array('label' => 'مالی')));
echo '<div class="card"><h1>💰 مالی مطب</h1>';
if ($msg !== '') echo '<p class="bad">' . clinic_h($msg) . '</p>';
echo '<div class="clinic-grid">';
echo '<div class="clinic-stat"><small>دریافتی امروز</small><b>' . clinic_h(clinic_money_short($sum_today)) . '</b></div>';
echo '<div class="clinic-stat"><small>دریافتی این ماه</small><b>' . clinic_h(clinic_money_short($sum_month)) . '</b></div>';
echo '<div class="clinic-stat"><small>تعداد بدهکاران</small><b>' . clinic_h(fa_num(count($debtors))) . '</b></div>';
echo '</div>';
if ($role === 'admin' || $role === 'head_secretary') {
    echo '<form method="get" action="index.php"><input type="hidden" name="p" value="clinic_finance">';
    echo '<div class="clinic-filters"><select name="doctor_id" onchange="this.form.submit()"><option value="0">همه دکترها</option>';
    foreach (clinic_doctors_list() as $d) {
        echo '<option value="' . (int) $d['id'] . '"' . ($f_doctor === (int) $d['id'] ? ' selected' : '') . '>' . clinic_h(clinic_user_display($d)) . '</option>';
    }
    echo '</select></div></form>';
}
echo '</div>';

if (clinic_can_manage_finance()) {
    $clients = clinic_list_clients(array('status' => 'active'));
    echo '<div class="card"><h2>➕ ثبت سریع تراکنش</h2>';
    echo '<form method="post" action="' . e(joma_url('index.php?p=clinic_finance')) . '">';
    echo csrf_field() . '<input type="hidden" name="clinic_action" value="txn_add">';
    $cmap = array();
    foreach ($clients as $c) $cmap[$c['id']] = clinic_client_display_name($c) . ' — ' . $c['file_no'];
    echo clinic_field_select('client_id', 'مراجع *', $cmap, '', '— انتخاب —');
    echo clinic_field_select('kind', 'نوع', $kinds, 'payment', null);
    echo '<div class="field-row"><div>' . clinic_field_text('amount', 'مبلغ (تومان) *', '', '', 'ltr') . '</div>';
    echo '<div>' . clinic_field_text('date', 'تاریخ', $today, '', 'ltr') . '</div></div>';
    echo clinic_field_select('method', 'روش', $methods, 'card', null);
    echo '<div class="field-row"><div>' . clinic_field_text('ref_no', 'شماره پیگیری', '', '', 'ltr') . '</div>';
    echo '<div>' . clinic_field_text('note', 'یادداشت', '', '', '') . '</div></div>';
    echo '<p><button class="btn" type="submit">ثبت</button></p></form></div>';
}

if ($per_doc) {
    echo '<div class="card"><h2>دریافتی این ماه هر دکتر</h2><div class="table-wrap"><table class="clinic-table">';
    foreach ($per_doc as $r) {
        echo '<tr><td>' . clinic_h(clinic_user_display($r['doctor'])) . '</td><td>' . clinic_h(clinic_money($r['sum'])) . '</td></tr>';
    }
    echo '</table></div></div>';
}

echo '<div class="card"><h2>بدهکاران</h2>';
if (!$debtors) {
    echo '<p class="hint">بدهکاری ثبت نشده است. ✅</p>';
} else {
    echo '<div class="table-wrap"><table class="clinic-table"><tr><th>مراجع</th><th>موبایل</th><th>مانده</th><th></th></tr>';
    foreach (array_slice($debtors, 0, 100) as $c) {
        echo '<tr><td><a href="' . e(joma_url('index.php?p=clinic_client&id=' . (int) $c['id'] . '&tab=finance')) . '">' . clinic_h(clinic_client_display_name($c)) . '</a></td>';
        echo '<td>' . clinic_h(fa_num($c['mobile'])) . '</td><td>' . clinic_h(clinic_money($c['_debt'])) . '</td>';
        echo '<td><a class="btn btn-small" href="' . e(joma_url('index.php?p=clinic_client&id=' . (int) $c['id'] . '&tab=finance')) . '">پرونده</a></td></tr>';
    }
    echo '</table></div>';
}
echo '</div>';

echo '<div class="card"><h2>تراکنش‌ها</h2>';
echo '<form method="get" action="index.php"><input type="hidden" name="p" value="clinic_finance"><input type="hidden" name="doctor_id" value="' . $f_doctor . '">';
echo '<div class="clinic-filters">';
echo '<input name="from" value="' . clinic_h($f_from) . '" dir="ltr" size="10" placeholder="از تاریخ">';
echo '<input name="to" value="' . clinic_h($f_to) . '" dir="ltr" size="10" placeholder="تا تاریخ">';
echo '<button class="btn btn-small" type="submit">اعمال</button> ';
if ($role !== 'doctor') echo '<a class="btn btn-small btn-ghost" href="' . e(joma_url('index.php?p=clinic_finance&export=csv&from=' . $f_from . '&to=' . $f_to . '&doctor_id=' . $f_doctor)) . '">⬇ خروجی اکسل (CSV)</a>';
echo '</div></form>';
if (!$txns) {
    echo '<p class="hint">تراکنشی در این بازه نیست.</p>';
} else {
    echo '<div class="table-wrap"><table class="clinic-table"><tr><th>تاریخ</th><th>مراجع</th><th>نوع</th><th>مبلغ</th><th>روش</th><th></th></tr>';
    foreach (array_slice($txns, 0, 300) as $t) {
        $tc = clinic_get_client($t['client_id']);
        echo '<tr><td>' . clinic_h(clinic_fa_date($t['date'])) . '</td>';
        echo '<td>' . ($tc ? '<a href="' . e(joma_url('index.php?p=clinic_client&id=' . (int) $tc['id'] . '&tab=finance')) . '">' . clinic_h(clinic_client_display_name($tc)) . '</a>' : '—') . '</td>';
        echo '<td>' . clinic_h($kinds[$t['kind']]) . '</td><td>' . clinic_h(clinic_money($t['amount'])) . '</td>';
        echo '<td>' . clinic_h(isset($methods[$t['method']]) ? $methods[$t['method']] : $t['method']) . '</td><td>';
        if ($t['kind'] === 'payment' || $t['kind'] === 'prepay') {
            echo '<a class="btn btn-small" href="' . e(joma_url('index.php?p=clinic_receipt&id=' . (int) $t['id'])) . '">رسید</a>';
        }
        echo '</td></tr>';
    }
    echo '</table></div>';
}
echo '</div>';

joma_footer();
