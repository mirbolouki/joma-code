<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما فاز ۲ — ثبت نوبت (منشی؛ مدیر هم به‌واسطهٔ ارث‌بری اداری)
 *
 *  مسیر کار:
 *    ۱) جست‌وجوی مراجع با شمارهٔ موبایل
 *    ۲) انتخاب پذیرش (پذیرفته‌شده یا در انتظار تصمیم درمانگر)
 *    ۳) انتخاب تاریخ شمسی، اتاق و یکی از زمان‌های آزاد
 *    ۴) «نگه‌داشتن موقت زمان» (قفل ۵ دقیقه‌ای) و سپس «تثبیت نوبت»
 *
 *  قفل موقت، اعلام «برنامهٔ حضور» نیست؛ فقط جلوی رزرو هم‌زمان دو کاربر
 *  را می‌گیرد و پس از ۵ دقیقه خودبه‌خود باطل می‌شود (بدون Cron).
 *  زدن دوبارهٔ «تثبیت» روی یک قفل، نوبت دوم نمی‌سازد.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_SECRETARY));

if (!phase2_ready($db)) {
    redirect(APP_BASE_URL . '/upgrade_phase2.php');
}

$actor_person_id = (int)$_SESSION['person_id'];
$actor_role_code = $_SESSION['active_role_code'];

$general_error = '';
$warnings = array();
$results = array();
$admission = null;
$slots = array();
$hold = null;
$hold_price_rial = null;

$form = array(
    'mobile_number' => '',
    'admission_id'  => '',
    'jalali_date'   => '',
    'room_id'       => '',
);

try {
    $rooms = rooms_fetch_active($db);
} catch (Exception $ex) {
    $ref = log_system_error('APPT_ROOMS', $ex);
    render_error_page($ref);
}

/** بارگذاری پذیرش انتخاب‌شده با همهٔ اطلاعات لازم */
function appt_load_admission($db, $admission_id)
{
    return db_select_one(
        $db,
        "SELECT a.id, a.public_id, a.status, a.service_type_id, a.referred_therapist_person_id,
                p.first_name, p.last_name, p.mobile_number,
                th.first_name AS therapist_first_name, th.last_name AS therapist_last_name,
                st.title AS service_title, st.default_duration_minutes
         FROM admissions a
         INNER JOIN persons p ON p.id = a.patient_person_id
         INNER JOIN persons th ON th.id = a.referred_therapist_person_id
         INNER JOIN service_types st ON st.id = a.service_type_id
         WHERE a.id = ?",
        'i',
        array((int)$admission_id)
    );
}

$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    foreach (array('mobile_number', 'admission_id', 'jalali_date', 'room_id') as $f) {
        if (isset($_POST[$f])) {
            $form[$f] = trim((string)$_POST[$f]);
        }
    }
}

try {
    /* ── گام ۱: جست‌وجوی مراجع ───────────────────────────────────── */
    if ($action === 'search') {
        $mobile = normalize_mobile_number($form['mobile_number']);
        if (!mobile_number_valid($mobile)) {
            $general_error = 'شمارهٔ موبایل معتبر نیست. قالب درست: 09xxxxxxxxx';
        } else {
            $form['mobile_number'] = $mobile;
            $results = admissions_searchable_for_appointment($db, $mobile);
            if (count($results) === 0) {
                $general_error = 'پذیرش قابل نوبت‌دهی برای این شماره پیدا نشد. '
                    . 'پذیرش «ردشده» باید نخست به درمانگر دیگری ارجاع مجدد شود.';
            }
        }
    }

    /* ── گام ۲ و ۳: انتخاب پذیرش و نمایش زمان‌های آزاد ───────────── */
    if ($action === 'pick' || $action === 'slots' || $action === 'hold') {
        $admission = appt_load_admission($db, $form['admission_id']);
        if (!$admission) {
            throw new Exception('پذیرش موردنظر پیدا نشد.');
        }
        if ($admission['status'] === 'DECLINED') {
            throw new Exception('این پذیرش رد شده است؛ ابتدا باید ارجاع مجدد شود.');
        }
        if ($form['jalali_date'] === '') {
            $form['jalali_date'] = gregorian_to_jalali_input(clinic_today());
        }
    }

    if ($action === 'slots' || $action === 'hold') {
        $local_date = jalali_input_to_gregorian($form['jalali_date']);
        if ($local_date === null) {
            throw new Exception('تاریخ را به قالب ۱۴۰۴/۰۷/۱۲ وارد کنید.');
        }
        $duration = (int)$admission['default_duration_minutes'];
        $slots = bookable_slots($db, (int)$admission['referred_therapist_person_id'],
            $local_date, $duration);
    }

    /* ── گام ۴: ساخت قفل موقت ────────────────────────────────────── */
    if ($action === 'hold') {
        $start_clock = isset($_POST['start_clock']) ? trim((string)$_POST['start_clock']) : '';
        if ($form['room_id'] === '') {
            throw new Exception('اتاق را انتخاب کنید.');
        }
        $res = hold_create($db, (int)$admission['id'], $form['room_id'],
            jalali_input_to_gregorian($form['jalali_date']), $start_clock,
            (int)$admission['default_duration_minutes'],
            $actor_person_id, $actor_role_code);
        $hold = $res['hold'];
        $warnings = $res['warnings'];
    }

    /* ── نمایش صفحهٔ تأیید یک قفل موجود ──────────────────────────── */
    if ($action === 'review' || $action === 'confirm' || $action === 'release') {
        $hold_public_id = isset($_POST['hold_public_id']) ? trim((string)$_POST['hold_public_id']) : '';
        $hold = hold_find_by_public_id($db, $hold_public_id);
        if (!$hold) {
            throw new Exception('قفل رزرو پیدا نشد.');
        }
        $admission = appt_load_admission($db, $hold['admission_id']);

        if ($action === 'release') {
            hold_release($db, $hold_public_id, $actor_person_id, $actor_role_code);
            flash_set('appt_ok', 'زمان نگه‌داشته‌شده آزاد شد.');
            redirect(APP_BASE_URL . '/reception/appointment_new.php');
        }

        if ($action === 'confirm') {
            $notes = isset($_POST['notes']) ? $_POST['notes'] : '';
            $out = appointment_confirm_hold($db, $hold_public_id, $notes,
                $actor_person_id, $actor_role_code);
            $appt = $out['appointment'];
            $msg = !empty($out['already'])
                ? 'این نوبت پیش‌تر ثبت شده بود؛ نوبت تکراری ساخته نشد.'
                : 'نوبت با موفقیت ثبت شد.';
            $msg .= ' شناسه: ' . $appt['public_id'];
            foreach ($out['warnings'] as $w) {
                $msg .= ' | ' . $w;
            }
            flash_set('appt_ok', $msg);
            redirect(APP_BASE_URL . '/reception/appointments.php');
        }
    }

    /* مبلغ پیشنهادی برای نمایش کنار قفل */
    if ($hold && $admission) {
        $tariff = tariff_find_active($db, (int)$admission['referred_therapist_person_id'],
            (int)$admission['service_type_id']);
        $hold_price_rial = $tariff ? $tariff['price_per_session'] : null;
    }
} catch (Exception $ex) {
    $general_error = $ex->getMessage();
}

$page_title = 'ثبت نوبت';
$active_menu = 'appointment_new';
require __DIR__ . '/../templates/header.php';
?>
<h1>📅 ثبت نوبت تازه</h1>
<?php echo flash_render('appt_ok', 'success'); ?>
<?php if ($general_error !== '') { ?>
  <div class="alert alert-danger"><?php echo e($general_error); ?></div>
<?php } ?>
<?php foreach ($warnings as $w) { ?>
  <div class="alert alert-warning">⚠️ <?php echo e($w); ?></div>
<?php } ?>

<?php if (count($rooms) === 0) { ?>
  <div class="alert alert-warning">
    هنوز هیچ اتاق فعالی ثبت نشده است. تا پیش از آن ثبت نوبت ممکن نیست؛
    از مدیر بخواهید در صفحهٔ «اتاق‌ها» دست‌کم یک اتاق فعال تعریف کند.
  </div>
<?php } ?>

<?php if ($hold && hold_seconds_left($hold) > 0) { /* ── مرحلهٔ تأیید ── */ ?>
<div class="card hold-card">
  <div class="card-header">⏳ این زمان موقتاً برای شما نگه داشته شد</div>
  <div class="card-body">
    <p class="hold-timer" data-hold-seconds="<?php echo (int)hold_seconds_left($hold); ?>">
      زمان باقی‌مانده برای تثبیت:
      <strong class="hold-countdown"><?php echo to_persian_digits(ceil(hold_seconds_left($hold) / 60)); ?> دقیقه</strong>
    </p>
    <div class="check-row"><span>مراجع</span><span class="fw-bold">
      <?php echo e($admission['first_name'] . ' ' . $admission['last_name']); ?></span></div>
    <div class="check-row"><span>درمانگر</span><span class="fw-bold">
      <?php echo e($admission['therapist_first_name'] . ' ' . $admission['therapist_last_name']); ?></span></div>
    <div class="check-row"><span>خدمت</span><span><?php echo e($admission['service_title']); ?></span></div>
    <div class="check-row"><span>زمان</span><span class="fw-bold">
      <?php echo appointment_display($hold['hold_start_utc']); ?>
      (<?php echo to_persian_digits($hold['duration_minutes']); ?> دقیقه)</span></div>
    <div class="check-row"><span>مبلغ جلسه</span><span>
      <?php echo $hold_price_rial !== null
            ? tariff_rial_to_toman_display($hold_price_rial)
            : '<span class="badge badge-warning">تعرفه‌ای ثبت نشده است</span>'; ?></span></div>

    <form method="post" action="appointment_new.php" class="form-grid">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="confirm">
      <input type="hidden" name="hold_public_id" value="<?php echo e($hold['public_id']); ?>">
      <div class="form-row">
        <label for="notes">یادداشت (اختیاری)</label>
        <textarea id="notes" name="notes" rows="2" maxlength="1000"></textarea>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">✅ تثبیت نوبت</button>
      </div>
    </form>
    <form method="post" action="appointment_new.php">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="release">
      <input type="hidden" name="hold_public_id" value="<?php echo e($hold['public_id']); ?>">
      <button type="submit" class="btn btn-secondary btn-sm">✖️ انصراف و آزاد کردن زمان</button>
    </form>
  </div>
</div>

<?php } else { /* ── مرحلهٔ جست‌وجو و انتخاب ── */ ?>

<div class="card">
  <div class="card-header">گام ۱ — جست‌وجوی مراجع</div>
  <div class="card-body">
    <form method="post" action="appointment_new.php" class="form-inline">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="search">
      <label for="mobile_number">شمارهٔ موبایل</label>
      <input type="text" inputmode="numeric" dir="ltr" id="mobile_number" name="mobile_number"
             value="<?php echo e($form['mobile_number']); ?>" placeholder="09xxxxxxxxx" required>
      <button type="submit" class="btn btn-primary">🔍 جست‌وجو</button>
    </form>
  </div>
</div>

<?php if (count($results) > 0) { ?>
<div class="card">
  <div class="card-header">گام ۲ — انتخاب پذیرش</div>
  <div class="card-body">
    <div class="table-wrap">
      <table class="table table-card">
        <thead><tr><th>مراجع</th><th>خدمت</th><th>درمانگر</th><th>وضعیت</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($results as $r) { ?>
          <tr>
            <td data-label="مراجع"><?php echo e($r['first_name'] . ' ' . $r['last_name']); ?></td>
            <td data-label="خدمت"><?php echo e($r['service_title']); ?>
              (<?php echo to_persian_digits($r['default_duration_minutes']); ?> دقیقه)</td>
            <td data-label="درمانگر"><?php echo e($r['therapist_first_name'] . ' ' . $r['therapist_last_name']); ?></td>
            <td data-label="وضعیت">
              <span class="badge <?php echo e(admission_status_class($r['status'])); ?>">
                <?php echo e(admission_status_label($r['status'])); ?></span>
            </td>
            <td>
              <form method="post" action="appointment_new.php">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action" value="pick">
                <input type="hidden" name="mobile_number" value="<?php echo e($form['mobile_number']); ?>">
                <input type="hidden" name="admission_id" value="<?php echo e($r['id']); ?>">
                <button type="submit" class="btn btn-sm btn-primary">انتخاب</button>
              </form>
            </td>
          </tr>
        <?php } ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php } ?>

<?php if ($admission) { ?>
<div class="card">
  <div class="card-header">گام ۳ — تاریخ، اتاق و زمان</div>
  <div class="card-body">
    <div class="check-row"><span>مراجع</span><span class="fw-bold">
      <?php echo e($admission['first_name'] . ' ' . $admission['last_name']); ?></span></div>
    <div class="check-row"><span>درمانگر</span><span class="fw-bold">
      <?php echo e($admission['therapist_first_name'] . ' ' . $admission['therapist_last_name']); ?></span></div>
    <div class="check-row"><span>مدت جلسه (از روی خدمت)</span><span class="fw-bold">
      <?php echo to_persian_digits($admission['default_duration_minutes']); ?> دقیقه</span></div>

    <form method="post" action="appointment_new.php" class="form-inline">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="slots">
      <input type="hidden" name="admission_id" value="<?php echo e($admission['id']); ?>">
      <input type="hidden" name="mobile_number" value="<?php echo e($form['mobile_number']); ?>">
      <label for="jalali_date">تاریخ (شمسی)</label>
      <input type="text" dir="ltr" id="jalali_date" name="jalali_date"
             value="<?php echo e($form['jalali_date']); ?>" placeholder="1404/07/12" required>
      <label for="room_id">اتاق</label>
      <select id="room_id" name="room_id" required>
        <option value="">— انتخاب اتاق —</option>
        <?php foreach ($rooms as $room) { ?>
          <option value="<?php echo e($room['public_id']); ?>"
            <?php echo ($form['room_id'] === $room['public_id']) ? 'selected' : ''; ?>>
            <?php echo e(room_label($room)); ?>
          </option>
        <?php } ?>
      </select>
      <button type="submit" class="btn btn-secondary">🕒 نمایش زمان‌های آزاد</button>
    </form>

    <p class="form-hint">
      ساعات کاری کلینیک: <?php echo to_persian_digits(CLINIC_DAY_START_HOUR); ?>:۰۰ تا
      <?php echo to_persian_digits(CLINIC_DAY_END_HOUR); ?>:۰۰ به وقت تهران.
      حداکثر تا <?php echo to_persian_digits(BOOKING_HORIZON_DAYS); ?> روز آینده می‌توان نوبت ثبت کرد.
    </p>
  </div>
</div>
<?php } ?>

<?php if ($admission && ($action === 'slots' || ($action === 'hold' && $general_error !== ''))) { ?>
<div class="card">
  <div class="card-header">زمان‌های آزاد <?php echo e(to_persian_digits($form['jalali_date'])); ?></div>
  <div class="card-body">
    <?php if (count($slots) === 0) { ?>
      <p class="empty-state">در این تاریخ زمان آزادی وجود ندارد
        (ممکن است درمانگر مرخصی باشد یا همهٔ ساعت‌ها پر شده باشند).</p>
    <?php } else { ?>
      <div class="slot-grid">
        <?php foreach ($slots as $slot) { ?>
          <form method="post" action="appointment_new.php" class="slot-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="hold">
            <input type="hidden" name="admission_id" value="<?php echo e($admission['id']); ?>">
            <input type="hidden" name="mobile_number" value="<?php echo e($form['mobile_number']); ?>">
            <input type="hidden" name="jalali_date" value="<?php echo e($form['jalali_date']); ?>">
            <input type="hidden" name="room_id" value="<?php echo e($form['room_id']); ?>">
            <input type="hidden" name="start_clock" value="<?php echo e($slot['clock']); ?>">
            <button type="submit" class="slot-btn"><?php echo e($slot['label']); ?></button>
          </form>
        <?php } ?>
      </div>
      <p class="form-hint">با انتخاب هر زمان، آن بازه فقط
        <?php echo to_persian_digits((int)(HOLD_TTL_SECONDS / 60)); ?> دقیقه برای شما نگه داشته می‌شود.</p>
    <?php } ?>
  </div>
</div>
<?php } ?>

<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
