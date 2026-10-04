<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما فاز ۲ — اعلام عدم حضور درمانگر
 *
 *  نکتهٔ بسیار مهم: ثبت عدم حضور، نوبت‌های موجود را «خودکار لغو نمی‌کند».
 *  سامانه فهرست نوبت‌های متأثر را نشان می‌دهد تا منشی یا مدیر دربارهٔ
 *  هر نوبت جداگانه تصمیم بگیرد.
 *
 *  این صفحه «برنامهٔ حضور هفتگی» نیست؛ چنین قابلیتی در فاز ۲ وجود ندارد.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_THERAPIST));

if (!phase2_ready($db)) {
    redirect(APP_BASE_URL . '/therapist/calendar.php');
}

$me = (int)$_SESSION['person_id'];
$actor_role_code = $_SESSION['active_role_code'];

$general_error = '';
$affected = array();
$form = array('from' => '', 'to' => '', 'reason_id' => '', 'repeating' => '');

try {
    $reasons = lookup_items_fetch_active($db, 'absence_reason');
} catch (Exception $ex) {
    $ref = log_system_error('ABSENCE_REASONS', $ex);
    render_error_page($ref);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    foreach (array('from', 'to', 'reason_id', 'repeating') as $f) {
        $form[$f] = isset($_POST[$f]) ? trim((string)$_POST[$f]) : '';
    }

    try {
        if ($action === 'create') {
            $from_g = jalali_input_to_gregorian($form['from']);
            $to_g = jalali_input_to_gregorian($form['to']);
            if ($from_g === null || $to_g === null) {
                throw new Exception('تاریخ‌ها را به قالب ۱۴۰۴/۰۷/۱۲ وارد کنید.');
            }
            $out = absence_rule_create($db, $me, $from_g, $to_g,
                ($form['reason_id'] !== '' ? (int)$form['reason_id'] : null),
                ($form['repeating'] === '1'), $me, $actor_role_code);
            $affected = $out['affected'];
            if (count($affected) === 0) {
                flash_set('absence_ok', 'بازهٔ عدم حضور ثبت شد. هیچ نوبت رزروشده‌ای در این بازه نبود.');
                redirect(APP_BASE_URL . '/therapist/absences.php');
            }
        } elseif ($action === 'remove') {
            absence_rule_remove($db, isset($_POST['rule_public_id']) ? $_POST['rule_public_id'] : '',
                $me, $me, $actor_role_code);
            flash_set('absence_ok', 'بازهٔ عدم حضور برداشته شد.');
            redirect(APP_BASE_URL . '/therapist/absences.php');
        }
    } catch (Exception $ex) {
        $general_error = $ex->getMessage();
    }
}

try {
    $rules = absence_rules_fetch($db, $me);
} catch (Exception $ex) {
    $ref = log_system_error('ABSENCE_LIST', $ex);
    render_error_page($ref);
}

$page_title = 'عدم حضور';
$active_menu = 'therapist_absences';
require __DIR__ . '/../templates/header.php';
?>
<h1>🏖️ اعلام عدم حضور</h1>
<?php echo flash_render('absence_ok', 'success'); ?>
<?php if ($general_error !== '') { ?>
  <div class="alert alert-danger"><?php echo e($general_error); ?></div>
<?php } ?>

<?php if (count($affected) > 0) { ?>
<div class="alert alert-warning">
  <strong>⚠️ بازهٔ عدم حضور ثبت شد، اما <?php echo to_persian_digits(count($affected)); ?>
  نوبت رزروشده در این بازه وجود دارد.</strong><br>
  این نوبت‌ها به‌صورت خودکار لغو <em>نشده‌اند</em>. لطفاً با منشی یا مدیر هماهنگ کنید.
</div>
<div class="card">
  <div class="card-header">نوبت‌های متأثر</div>
  <div class="card-body">
    <div class="table-wrap">
      <table class="table table-card">
        <thead><tr><th>زمان</th><th>مراجع</th><th>خدمت</th><th>اتاق</th></tr></thead>
        <tbody>
        <?php foreach ($affected as $a) { ?>
          <tr>
            <td data-label="زمان"><?php echo appointment_display($a['appointment_start_utc']); ?></td>
            <td data-label="مراجع"><?php echo e($a['patient_first_name'] . ' ' . $a['patient_last_name']); ?></td>
            <td data-label="خدمت"><?php echo e($a['service_title']); ?></td>
            <td data-label="اتاق"><?php echo e($a['room_name']); ?></td>
          </tr>
        <?php } ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php } ?>

<div class="card">
  <div class="card-header">ثبت بازهٔ تازه</div>
  <div class="card-body">
    <form method="post" action="absences.php" class="form-grid">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="create">
      <div class="form-row">
        <label for="from">از تاریخ <span class="required-star">*</span></label>
        <input type="text" dir="ltr" id="from" name="from" value="<?php echo e($form['from']); ?>"
               placeholder="1404/07/12" required>
      </div>
      <div class="form-row">
        <label for="to">تا تاریخ <span class="required-star">*</span></label>
        <input type="text" dir="ltr" id="to" name="to" value="<?php echo e($form['to']); ?>"
               placeholder="1404/07/15" required>
      </div>
      <div class="form-row">
        <label for="reason_id">دلیل</label>
        <select id="reason_id" name="reason_id">
          <option value="">— بدون ذکر دلیل —</option>
          <?php foreach ($reasons as $r) { ?>
            <option value="<?php echo e($r['id']); ?>"
              <?php echo ((string)$form['reason_id'] === (string)$r['id']) ? 'selected' : ''; ?>>
              <?php echo e($r['label']); ?></option>
          <?php } ?>
        </select>
      </div>
      <div class="form-row">
        <label class="checkbox-label">
          <input type="checkbox" name="repeating" value="1"
            <?php echo ($form['repeating'] === '1') ? 'checked' : ''; ?>>
          هر سال در همین روزها تکرار شود
        </label>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">➕ ثبت عدم حضور</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">بازه‌های ثبت‌شده (<?php echo to_persian_digits(count($rules)); ?>)</div>
  <div class="card-body">
    <?php if (count($rules) === 0) { ?>
      <p class="empty-state">بازهٔ عدم حضوری ثبت نشده است.</p>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead><tr><th>از</th><th>تا</th><th>دلیل</th><th>تکرار سالانه</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($rules as $r) { ?>
            <tr>
              <td data-label="از"><?php echo e(to_persian_digits(gregorian_to_jalali_input($r['start_date']))); ?></td>
              <td data-label="تا"><?php echo e(to_persian_digits(gregorian_to_jalali_input($r['end_date']))); ?></td>
              <td data-label="دلیل"><?php echo $r['reason_label'] !== null ? e($r['reason_label']) : '—'; ?></td>
              <td data-label="تکرار">
                <span class="badge <?php echo (int)$r['is_repeating'] === 1 ? 'badge-primary' : 'badge-muted'; ?>">
                  <?php echo (int)$r['is_repeating'] === 1 ? 'بله' : 'خیر'; ?></span>
              </td>
              <td>
                <form method="post" action="absences.php" class="inline-form">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="remove">
                  <input type="hidden" name="rule_public_id" value="<?php echo e($r['public_id']); ?>">
                  <button type="submit" class="btn btn-sm btn-secondary">🗑 برداشتن</button>
                </form>
              </td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
      </div>
      <p class="form-hint">«برداشتن» فقط بازه را غیرفعال می‌کند؛ هیچ رکوردی حذف فیزیکی نمی‌شود.</p>
    <?php } ?>
  </div>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
