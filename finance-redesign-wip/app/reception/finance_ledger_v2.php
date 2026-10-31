<?php
/* DRAFT ONLY: Requires migration, integration and a complete security review. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/finance_v2/money.php';
require_once __DIR__ . '/../includes/finance_v2/record_event.php';
require_once __DIR__ . '/../includes/finance_v2/request_wallet.php';
require_once __DIR__ . '/../includes/finance_v2/storage/mysqli_store.php';
require_once __DIR__ . '/../includes/finance_v2/storage/mysqli_request_wallet_store.php';
$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_SECRETARY, ROLE_ADMIN));
function ledger_v2_id($value) {
    if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,17}$/D', $value)) throw new InvalidArgumentException('Invalid identifier');
    return (int)$value;
}
function ledger_v2_string($key) { return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : ''; }
function ledger_v2_option($wallet) {
    if ($wallet['request_id'] !== null) return 'درخواست ' . $wallet['request_public'] . ' — ' . $wallet['request_first'] . ' ' . $wallet['request_last'];
    return 'نوبت ' . $wallet['appt_public'] . ' — ' . $wallet['patient_first'] . ' ' . $wallet['patient_last'] . ' — ' . jalali_display($wallet['appointment_start_utc'], 'datetime');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $actor = array('person_id'=>(int)$_SESSION['person_id'], 'role'=>$_SESSION['active_role_code']);
        $action = ledger_v2_string('action');
        $base = array('key'=>ledger_v2_string('operation_key'));
        if ($action === 'deposit') {
            $request_id = ledger_v2_id(ledger_v2_string('request_id'));
            $wallet = joma_v2_request_wallet(new JomaV2MysqliRequestWalletStore($db), $request_id, $actor);
            $base += array('type'=>'RECEIPT','wallet'=>$wallet,'amount_rial'=>joma_v2_toman_precise_to_rial(ledger_v2_string('amount_toman')),
                'account_id'=>ledger_v2_id(ledger_v2_string('account_id')),'receipt_kind'=>'DEPOSIT');
        } elseif ($action === 'receipt' || $action === 'refund') {
            $base += array('type'=>$action === 'receipt' ? 'RECEIPT' : 'REFUND','wallet'=>ledger_v2_id(ledger_v2_string('wallet_id')),
                'amount_rial'=>joma_v2_toman_precise_to_rial(ledger_v2_string('amount_toman')),'account_id'=>ledger_v2_id(ledger_v2_string('account_id')),
                'reason'=>ledger_v2_string('reason'),'receipt_kind'=>'PAYMENT');
        } elseif ($action === 'transfer') {
            $base += array('type'=>'TRANSFER','wallet'=>ledger_v2_id(ledger_v2_string('wallet_id')),'to'=>ledger_v2_id(ledger_v2_string('destination_id')),
                'amount_rial'=>joma_v2_toman_precise_to_rial(ledger_v2_string('amount_toman')),'reason'=>ledger_v2_string('reason'));
        } elseif ($action === 'charge') {
            $minutes = ledger_v2_id(ledger_v2_string('actual_minutes'));
            $actual_j = finance_jalali_date3_compose(ledger_v2_string('actual_date_y'),ledger_v2_string('actual_date_m'),ledger_v2_string('actual_date_d'));
            $actual_date = jalali_input_to_gregorian($actual_j);
            $base += array('type'=>'CHARGE','wallet'=>ledger_v2_id(ledger_v2_string('wallet_id')),'actual_minutes'=>$minutes,'actual_session_date'=>$actual_date,'reason'=>ledger_v2_string('reason'));
            $override = trim(ledger_v2_string('override_toman'));
            if ($override !== '') $base['override_rial'] = joma_v2_toman_precise_to_rial($override);
        } else throw new InvalidArgumentException('Unknown action');
        joma_v2_record_event(new JomaV2MysqliStore($db),$base,$actor);
        flash_set('v2_ledger_ok','عملیات مالی ثبت شد.');
        redirect(APP_BASE_URL . '/reception/finance_ledger_v2.php');
    } catch (InvalidArgumentException $ex) {
        $error = 'عملیات انجام نشد. اطلاعات، مجوز، موجودی اعتبار، وضعیت نوبت و تکراری‌نبودن ثبت را بررسی کنید.';
    } catch (OverflowException $ex) {
        $error = 'مبلغ از حد مجاز بیشتر است.';
    } catch (Throwable $ex) {
        $ref = log_system_error('FINANCE_V2_LEDGER_SAVE',$ex);
        $error = 'عملیات انجام نشد. کد پیگیری: ' . $ref;
    }
}
try {
    $requests = db_select_all($db,"SELECT id,public_id,first_name,last_name,status FROM booking_requests ORDER BY created_at DESC LIMIT 200");
    $wallets = db_select_all($db,"SELECT w.id, w.request_id, br.public_id AS request_public, br.first_name AS request_first, br.last_name AS request_last,
        ap.public_id AS appt_public, ap.appointment_start_utc, p.first_name AS patient_first, p.last_name AS patient_last
        FROM finance_v2_wallets w LEFT JOIN booking_requests br ON br.id=w.request_id
        LEFT JOIN appointments ap ON ap.id=w.appointment_id LEFT JOIN admissions ad ON ad.id=ap.admission_id
        LEFT JOIN persons p ON p.id=ad.patient_person_id ORDER BY w.id DESC LIMIT 200");
    $accounts = finance_accounts_fetch_active($db);
    $events = db_select_all($db,"SELECT ev.id,ev.event_type,ev.receipt_kind,ev.wallet_id,ev.destination_wallet_id,ev.amount_rial,ev.actual_minutes,ev.actual_session_date,ev.reason,ev.occurred_at,ba.label AS account_label
        FROM finance_v2_events ev LEFT JOIN finance_bank_accounts ba ON ba.id=ev.account_id ORDER BY ev.id DESC LIMIT 100");
} catch (Throwable $ex) { $ref=log_system_error('FINANCE_V2_LEDGER_LIST',$ex); render_error_page($ref); }
$page_title='دفتر مالی جدید'; $active_menu='finance_ledger_v2';
require __DIR__ . '/../templates/header.php';
function ledger_v2_wallet_select($wallets,$name) { ?>
<select name="<?php echo e($name); ?>" required><option value="">انتخاب کنید</option>
<?php foreach ($wallets as $w) { ?><option value="<?php echo (int)$w['id']; ?>"><?php echo e(ledger_v2_option($w)); ?></option><?php } ?></select>
<?php }
function ledger_v2_account_select($accounts) { ?><select name="account_id" required><option value="">انتخاب کنید</option><?php foreach ($accounts as $a) { ?><option value="<?php echo (int)$a['id']; ?>"><?php echo e($a['label']); ?></option><?php } ?></select><?php }
function ledger_v2_key() { return '<input type="hidden" name="operation_key" value="' . e(bin2hex(random_bytes(16))) . '">'; }
?>
<h1>دفتر مالی جدید</h1>
<?php echo flash_render('v2_ledger_ok','success'); ?>
<?php if ($error !== '') { ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php } ?>
<div class="alert alert-info">همهٔ مبالغ به تومان است و تا یک رقم اعشار (یک ریال) پذیرفته می‌شود. صورتحساب فقط پس از ثبت وضعیت «انجام‌شده» و تعیین نرخ نوبت قابل ثبت است. انتقال و استرداد را موردی ثبت کنید.</div>
<div class="card"><div class="card-header">ثبت بیعانه برای درخواست</div><div class="card-body"><form method="post" class="form-grid">
<?php echo csrf_field(),ledger_v2_key(); ?><input type="hidden" name="action" value="deposit">
<div class="form-row"><label>درخواست رزرو</label><select name="request_id" required><option value="">انتخاب کنید</option><?php foreach ($requests as $r) { ?><option value="<?php echo (int)$r['id']; ?>"><?php echo e($r['public_id'].' — '.$r['first_name'].' '.$r['last_name'].' — '.$r['status']); ?></option><?php } ?></select></div>
<div class="form-row"><label>مبلغ بیعانه</label><input name="amount_toman" inputmode="decimal" required></div><div class="form-row"><label>حساب</label><?php ledger_v2_account_select($accounts); ?></div>
<div class="form-actions"><button class="btn btn-primary">ثبت بیعانه</button></div></form></div></div>
<div class="card"><div class="card-header">پرداخت تکمیلی یا استرداد</div><div class="card-body"><form method="post" class="form-grid">
<?php echo csrf_field(),ledger_v2_key(); ?><div class="form-row"><label>نوع</label><select name="action"><option value="receipt">دریافت</option><option value="refund">استرداد</option></select></div>
<div class="form-row"><label>درخواست یا نوبت</label><?php ledger_v2_wallet_select($wallets,'wallet_id'); ?></div><div class="form-row"><label>مبلغ</label><input name="amount_toman" inputmode="numeric" required></div>
<div class="form-row"><label>حساب</label><?php ledger_v2_account_select($accounts); ?></div><div class="form-row"><label>دلیل استرداد</label><input name="reason" maxlength="255"></div>
<div class="form-actions"><button class="btn btn-primary">ثبت</button></div></form></div></div>
<div class="card"><div class="card-header">انتقال اعتبار</div><div class="card-body"><form method="post" class="form-grid">
<?php echo csrf_field(),ledger_v2_key(); ?><input type="hidden" name="action" value="transfer"><div class="form-row"><label>مبدأ</label><?php ledger_v2_wallet_select($wallets,'wallet_id'); ?></div>
<div class="form-row"><label>مقصد</label><?php ledger_v2_wallet_select($wallets,'destination_id'); ?></div><div class="form-row"><label>مبلغ</label><input name="amount_toman" inputmode="numeric" required></div>
<div class="form-row"><label>دلیل</label><input name="reason" maxlength="255" required></div><div class="form-actions"><button class="btn btn-primary">انتقال</button></div></form></div></div>
<div class="card"><div class="card-header">ثبت مدت واقعی و صورتحساب</div><div class="card-body"><form method="post" class="form-grid">
<?php echo csrf_field(),ledger_v2_key(); ?><input type="hidden" name="action" value="charge"><div class="form-row"><label>نوبت</label><?php ledger_v2_wallet_select($wallets,'wallet_id'); ?></div>
<div class="form-row"><label>تاریخ واقعی برگزاری</label><?php echo finance_jalali_date3_html('actual_date',''); ?></div>
<div class="form-row"><label>مدت واقعی (دقیقهٔ صحیح)</label><input name="actual_minutes" inputmode="numeric" required></div>
<div class="form-row"><label>مبلغ نهایی دستی (اختیاری)</label><input name="override_toman" inputmode="decimal"></div><div class="form-row"><label>دلیل اصلاح مبلغ یا صورتحساب قبلی</label><input name="reason" maxlength="255"></div>
<div class="form-actions"><button class="btn btn-primary">ثبت صورتحساب</button></div></form></div></div>
<div class="card"><div class="card-header">آخرین گردش‌ها</div><div class="card-body"><div class="table-wrap"><table class="table table-card"><thead><tr><th>زمان</th><th>نوع</th><th>مبدأ / مقصد</th><th>مبلغ</th><th>دقیقه</th><th>تاریخ واقعی</th><th>حساب</th><th>دلیل</th></tr></thead><tbody>
<?php foreach ($events as $ev) { ?><tr><td><?php echo e(jalali_display($ev['occurred_at'],'datetime')); ?></td><td><?php echo e($ev['event_type']); ?></td><td><?php echo (int)$ev['wallet_id']; ?><?php if ($ev['destination_wallet_id'] !== null) { ?> ← <?php echo (int)$ev['destination_wallet_id']; ?><?php } ?></td><td><?php echo e(joma_v2_rial_to_toman_string((int)$ev['amount_rial'])); ?> تومان</td><td><?php echo $ev['actual_minutes'] === null ? '—' : (int)$ev['actual_minutes']; ?></td><td><?php echo $ev['actual_session_date'] === null ? '—' : e(jalali_value($ev['actual_session_date'])); ?></td><td><?php echo e($ev['account_label'] === null ? '—' : $ev['account_label']); ?></td><td><?php echo e($ev['reason'] === null ? '' : $ev['reason']); ?></td></tr><?php } ?>
</tbody></table></div></div></div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
