<?php
/* DRAFT ONLY: NOT A DEPLOYABLE PATCH. Requires the future migration and
 * finance_v2 includes, which have not yet been integrated or tested on MySQL. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/finance_v2/money.php';
require_once __DIR__ . '/../includes/finance_v2/rates.php';
require_once __DIR__ . '/../includes/finance_v2/assign_rate.php';
require_once __DIR__ . '/../includes/finance_v2/storage/mysqli_store.php';
require_once __DIR__ . '/../includes/finance_v2/storage/mysqli_rate_store.php';
$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

function v2_ui_id($value)
{
    if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,17}$/D', $value)) return null;
    $id = (int)$value;
    return $id > 0 ? $id : null;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $amount = joma_v2_toman_to_rial(isset($_POST['amount_toman']) ? $_POST['amount_toman'] : null);
        $actor = array('person_id'=>(int)$_SESSION['person_id'], 'role'=>$_SESSION['active_role_code']);
        $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
        if ($action === 'save') {
            $therapist = v2_ui_id(isset($_POST['therapist_id']) ? $_POST['therapist_id'] : null);
            $service = v2_ui_id(isset($_POST['service_id']) ? $_POST['service_id'] : null);
            $active = isset($_POST['active']) && is_string($_POST['active']) ? $_POST['active'] : '';
            if (!$therapist || !$service || !in_array($active, array('1', '0'), true)) throw new InvalidArgumentException('Invalid selection');
            joma_v2_set_rate(new JomaV2MysqliRateStore($db), $therapist, $service, $amount, $active === '1', $actor);
        } elseif ($action === 'assign') {
            $wallet_id = v2_ui_id(isset($_POST['wallet_id']) ? $_POST['wallet_id'] : null);
            $reason = isset($_POST['reason']) && is_string($_POST['reason']) ? $_POST['reason'] : '';
            if (!$wallet_id) throw new InvalidArgumentException('Invalid wallet');
            joma_v2_assign_missing_rate(new JomaV2MysqliStore($db), $wallet_id, $amount, $reason, $actor);
        } else throw new InvalidArgumentException('Invalid action');
        flash_set('v2_rate_ok', 'تعرفهٔ دقیقه‌ای ذخیره شد.');
        redirect(APP_BASE_URL . '/admin/finance_minute_rates.php');
    } catch (InvalidArgumentException $ex) {
        $error = 'اطلاعات معتبر نیست. درمانگر و خدمت فعال و مبلغ صحیح را بررسی کنید.';
    } catch (OverflowException $ex) {
        $error = 'مبلغ واردشده بیش از حد مجاز است.';
    } catch (Throwable $ex) {
        $ref = log_system_error('FINANCE_V2_RATE_SAVE', $ex);
        $error = 'ذخیره انجام نشد. کد پیگیری: ' . $ref;
    }
}
try {
    $therapists = therapists_fetch_active($db);
    $services = db_select_all($db, 'SELECT id, title FROM service_types WHERE is_active = 1 ORDER BY title');
    $unpriced = db_select_all($db, "SELECT w.id, ap.public_id, ap.appointment_start_utc, p.first_name, p.last_name
        FROM finance_v2_wallets w JOIN appointments ap ON ap.id=w.appointment_id
        JOIN admissions ad ON ad.id=ap.admission_id JOIN persons p ON p.id=ad.patient_person_id
        WHERE w.rate_rial_per_minute IS NULL AND NOT EXISTS
        (SELECT 1 FROM finance_session_payments fsp WHERE fsp.appointment_id=ap.id)
        ORDER BY ap.appointment_start_utc DESC LIMIT 200");
    $rates = db_select_all($db, "SELECT p.first_name, p.last_name, st.title, r.rate_rial_per_minute, r.active
        FROM finance_v2_rates r JOIN persons p ON p.id = r.therapist_person_id
        JOIN service_types st ON st.id = r.service_type_id
        ORDER BY p.last_name, p.first_name, st.title LIMIT 250");
} catch (Throwable $ex) {
    $ref = log_system_error('FINANCE_V2_RATE_LIST', $ex);
    render_error_page($ref);
}
$page_title = 'تعرفهٔ دقیقه‌ای';
$active_menu = 'finance_minute_rates';
require __DIR__ . '/../templates/header.php';
?>
<h1>تعرفهٔ دقیقه‌ای خدمت و درمانگر</h1>
<?php echo flash_render('v2_rate_ok', 'success'); ?>
<?php if ($error !== '') { ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php } ?>
<div class="alert alert-info">مبلغ را به تومان برای <strong>هر دقیقه</strong> بنویسید. تعرفه‌های جلسه‌ای قبلی به‌صورت خودکار تبدیل نمی‌شوند.</div>
<div class="card"><div class="card-header">ثبت یا تغییر تعرفه</div><div class="card-body">
<form method="post" action="finance_minute_rates.php" class="form-grid">
<?php echo csrf_field(); ?><input type="hidden" name="action" value="save">
<div class="form-row"><label for="therapist_id">درمانگر</label><select id="therapist_id" name="therapist_id" required><option value="">انتخاب کنید</option>
<?php foreach ($therapists as $t) { ?><option value="<?php echo (int)$t['id']; ?>"><?php echo e($t['first_name'] . ' ' . $t['last_name']); ?></option><?php } ?>
</select></div>
<div class="form-row"><label for="service_id">خدمت</label><select id="service_id" name="service_id" required><option value="">انتخاب کنید</option>
<?php foreach ($services as $s) { ?><option value="<?php echo (int)$s['id']; ?>"><?php echo e($s['title']); ?></option><?php } ?>
</select></div>
<div class="form-row"><label for="amount_toman">مبلغ هر دقیقه (تومان)</label><input id="amount_toman" name="amount_toman" type="text" inputmode="numeric" dir="ltr" maxlength="80" required></div>
<div class="form-row"><label for="active">وضعیت</label><select id="active" name="active"><option value="1">فعال</option><option value="0">غیرفعال</option></select></div>
<div class="form-actions"><button type="submit" class="btn btn-primary">ذخیره</button></div>
</form></div></div>
<div class="card"><div class="card-header">تعیین نرخ برای نوبت فاقد نرخ</div><div class="card-body">
<p>فقط وقتی نرخ دقیق این نوبت را می‌دانید، آن را با ذکر دلیل ثبت کنید؛ سیستم مقدار قدیمی را حدس نمی‌زند.</p>
<form method="post" action="finance_minute_rates.php" class="form-grid">
<?php echo csrf_field(); ?><input type="hidden" name="action" value="assign">
<div class="form-row"><label>نوبت</label><select name="wallet_id" required><option value="">انتخاب کنید</option><?php foreach ($unpriced as $u) { ?><option value="<?php echo (int)$u['id']; ?>"><?php echo e($u['public_id'].' — '.$u['first_name'].' '.$u['last_name'].' — '.jalali_display($u['appointment_start_utc'],'datetime')); ?></option><?php } ?></select></div>
<div class="form-row"><label>نرخ دقیق هر دقیقه (تومان)</label><input name="amount_toman" inputmode="numeric" required></div>
<div class="form-row"><label>دلیل و مبنای تعیین نرخ</label><input name="reason" maxlength="255" required></div>
<div class="form-actions"><button type="submit" class="btn btn-primary">ثبت نرخ این نوبت</button></div></form></div></div>
<div class="card"><div class="card-header">تعرفه‌های ثبت‌شده (حداکثر ۲۵۰ مورد)</div><div class="card-body"><div class="table-wrap"><table class="table table-card">
<thead><tr><th>درمانگر</th><th>خدمت</th><th>هر دقیقه</th><th>وضعیت</th></tr></thead><tbody>
<?php foreach ($rates as $r) { ?>
<tr><td><?php echo e($r['first_name'] . ' ' . $r['last_name']); ?></td><td><?php echo e($r['title']); ?></td><td><?php echo e(joma_v2_rial_to_toman_string((int)$r['rate_rial_per_minute'])); ?> تومان</td><td><?php echo (int)$r['active'] === 1 ? 'فعال' : 'غیرفعال'; ?></td></tr>
<?php } ?>
</tbody></table></div></div></div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
