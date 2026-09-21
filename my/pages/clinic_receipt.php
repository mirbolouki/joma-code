<?php
// رسید چاپی پرداخت
clinic_require_login();
$role = clinic_role();
$id = (int) (isset($_GET['id']) ? $_GET['id'] : 0);
$t = clinic_get_transaction($id);
if (!$t) clinic_deny('رسید پیدا نشد.');
$c = clinic_get_client($t['client_id']);
if (!$c || !clinic_can_access_client($c)) clinic_deny('دسترسی ندارید.');
$kinds = clinic_txn_kinds();
$methods = clinic_pay_methods();
$doc = clinic_get_user((int) $t['doctor_id']);
$set = clinic_get_settings();
$by = clinic_get_user((int) $t['created_by']);

joma_header('رسید پرداخت', array(array('label' => 'مالی', 'href' => joma_url('index.php?p=clinic_finance')), array('label' => 'رسید')));
echo '<div class="card no-print"><p><button class="btn" onclick="window.print()">🖨️ چاپ رسید</button> ';
echo '<a class="btn btn-ghost" href="' . e(joma_url('index.php?p=clinic_client&id=' . (int) $c['id'] . '&tab=finance')) . '">بازگشت به پرونده</a></p></div>';
echo '<div class="card"><div class="receipt">';
echo '<h2>رسید ' . clinic_h($kinds[$t['kind']]) . ' — ' . clinic_h($set['clinic_name']) . '</h2>';
echo '<table>';
echo '<tr><td>نام مراجع</td><td><strong>' . clinic_h(clinic_client_display_name($c)) . '</strong></td></tr>';
echo '<tr><td>شماره پرونده</td><td>' . clinic_h(fa_num($c['file_no'])) . '</td></tr>';
echo '<tr><td>درمانگر</td><td>' . clinic_h($doc ? clinic_user_display($doc) : '—') . '</td></tr>';
echo '<tr><td>مبلغ</td><td><strong>' . clinic_h(clinic_money($t['amount'])) . '</strong></td></tr>';
echo '<tr><td>روش پرداخت</td><td>' . clinic_h(isset($methods[$t['method']]) ? $methods[$t['method']] : $t['method']) . '</td></tr>';
if ($t['ref_no'] !== '') echo '<tr><td>شماره پیگیری</td><td>' . clinic_h(fa_num($t['ref_no'])) . '</td></tr>';
echo '<tr><td>تاریخ</td><td>' . clinic_h(clinic_fa_date($t['date'])) . '</td></tr>';
if ($t['note'] !== '') echo '<tr><td>توضیح</td><td>' . clinic_h($t['note']) . '</td></tr>';
echo '<tr><td>ثبت‌کننده</td><td>' . clinic_h($by ? clinic_user_display($by) : '—') . '</td></tr>';
echo '</table>';
echo '<p style="text-align:center" class="hint">شماره رسید: ' . clinic_h(fa_num($t['id'])) . '</p>';
echo '</div></div>';
joma_footer();
