<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — ثبت پذیرش مراجع
 *  مرحلهٔ ۱: یافتن یا ساختن مراجع با شمارهٔ موبایل
 *  مرحلهٔ ۲: جزئیات پذیرش و ارجاع به درمانگر
 *
 *  جست‌وجوی موبایل هم با AJAX کار می‌کند و هم بدون جاوااسکریپت
 *  (ارسال معمولی فرم) — هیچ قابلیتی به جاوااسکریپت وابسته نیست.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_SECRETARY));

/* ── پاسخ AJAX جست‌وجوی مراجع ───────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_ajax_request()
    && !isset($_POST['action'])) {
    csrf_check();
    $mobile = normalize_mobile_number(isset($_POST['mobile_number']) ? $_POST['mobile_number'] : '');
    if (!mobile_number_valid($mobile)) {
        json_response(array('ok' => false, 'message' => 'شمارهٔ موبایل معتبر نیست.'));
    }
    try {
        $person = person_find_by_mobile($db, $mobile);
    } catch (Exception $ex) {
        $ref = log_system_error('PERSON_LOOKUP', $ex);
        json_response(array('ok' => false, 'message' => 'خطایی رخ داده است. (کد: REF-' . $ref . ')'));
    }
    if ($person) {
        json_response(array(
            'ok' => true, 'found' => true,
            'first_name' => $person['first_name'],
            'last_name'  => $person['last_name'],
            'full_name'  => person_full_name($person),
        ));
    }
    json_response(array('ok' => true, 'found' => false));
}

$errors = array();
$general_error = '';
$lookup_message = '';
$lookup_class = 'info';

$form = array(
    'mobile_number' => '', 'first_name' => '', 'last_name' => '', 'national_code' => '',
    'service_type_id' => '', 'companion_count' => 0, 'referral_reason_id' => '',
    'referred_therapist_person_id' => '',
);

/* ── جست‌وجوی بدون جاوااسکریپت ──────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'lookup') {
    csrf_check();
    $form['mobile_number'] = isset($_POST['mobile_number']) ? $_POST['mobile_number'] : '';
    $mobile = normalize_mobile_number($form['mobile_number']);
    if (!mobile_number_valid($mobile)) {
        $errors['mobile_number'] = 'شمارهٔ موبایل معتبر نیست. قالب درست: 09xxxxxxxxx';
    } else {
        $form['mobile_number'] = $mobile;
        try {
            $person = person_find_by_mobile($db, $mobile);
        } catch (Exception $ex) {
            $ref = log_system_error('PERSON_LOOKUP', $ex);
            render_error_page($ref);
        }
        if ($person) {
            $form['first_name'] = $person['first_name'];
            $form['last_name'] = $person['last_name'];
            $form['national_code'] = $person['national_code'] !== null ? $person['national_code'] : '';
            $lookup_message = '✓ این شماره قبلاً ثبت شده است: ' . person_full_name($person);
            $lookup_class = 'success';
        } else {
            $lookup_message = 'ℹ️ مراجع جدید است. لطفاً نام و نام خانوادگی را وارد کنید.';
            $lookup_class = 'info';
        }
    }
}

/* ── ثبت نهایی پذیرش ────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    csrf_check();

    foreach (array('mobile_number', 'first_name', 'last_name', 'national_code',
                   'service_type_id', 'companion_count', 'referral_reason_id',
                   'referred_therapist_person_id') as $f) {
        $form[$f] = isset($_POST[$f]) ? trim((string)$_POST[$f]) : '';
    }

    $person_check = person_intake_validate($form);
    if (!$person_check['ok']) {
        $errors = $person_check['errors'];
    }

    $admission_check = admission_validate_input($db, array(
        'patient_person_id' => 1, /* موقت؛ پس از ساخت/یافتن شخص بازبینی می‌شود */
        'service_type_id' => $form['service_type_id'],
        'companion_count' => $form['companion_count'],
        'referral_reason_id' => $form['referral_reason_id'],
        'referred_therapist_person_id' => $form['referred_therapist_person_id'],
    ));
    if (!$admission_check['ok']) {
        $errors = array_merge($errors, $admission_check['errors']);
    }

    if (empty($errors)) {
        mysqli_begin_transaction($db);
        try {
            $person = person_find_by_mobile($db, $person_check['mobile']);
            if ($person) {
                $patient_person_id = (int)$person['id'];
                if ($person['status'] !== 'ACTIVE') {
                    throw new Exception('این مراجع در سامانه غیرفعال است.');
                }
            } else {
                $patient_person_id = person_insert(
                    $db, $form['first_name'], $form['last_name'],
                    $person_check['mobile'], $person_check['national_code']
                );
                audit_log_write($db, (int)$_SESSION['person_id'], ROLE_SECRETARY, 'PERSON_CREATED',
                    'person', $patient_person_id, null);
            }

            $admission_id = admission_insert($db, array(
                'patient_person_id' => $patient_person_id,
                'referred_therapist_person_id' => (int)$form['referred_therapist_person_id'],
                'service_type_id' => (int)$form['service_type_id'],
                'companion_count' => (int)$admission_check['companion_count'],
                'referral_reason_id' => (int)$form['referral_reason_id'],
            ), (int)$_SESSION['person_id']);

            mysqli_commit($db);

            flash_set('reception_success',
                'پذیرش با موفقیت ثبت شد و برای تصمیم‌گیری به کارتابل درمانگر ارسال گردید.');
            redirect(APP_BASE_URL . '/reception/index.php');
        } catch (Exception $ex) {
            mysqli_rollback($db);
            $message = $ex->getMessage();
            if (strpos($message, 'DB_') === 0) {
                $ref = log_system_error('ADMISSION_CREATE', $ex);
                $general_error = 'خطایی رخ داده است. (کد پیگیری: REF-' . $ref . ')';
            } else {
                $general_error = $message;
            }
        }
    }
}

try {
    $services = service_types_fetch_active($db);
    $reasons = lookup_items_fetch_active($db, 'referral_reason');
    $therapists = therapists_fetch_active($db);
} catch (Exception $ex) {
    $ref = log_system_error('ADMISSION_FORM_DATA', $ex);
    render_error_page($ref);
}

/* کدهای خدماتی که چندنفره‌اند، برای نمایش شرطی تعداد همراه */
$multi_service_ids = array();
foreach ($services as $s) {
    if ((int)$s['is_multi_person'] === 1) {
        $multi_service_ids[] = (int)$s['id'];
    }
}
$show_companion = in_array((int)$form['service_type_id'], $multi_service_ids, true);

$page_title = 'ثبت پذیرش جدید';
$active_menu = 'admission_new';
require __DIR__ . '/../templates/header.php';
?>
<h1>➕ ثبت پذیرش مراجع جدید</h1>

<?php if ($general_error !== '') { ?>
  <div class="alert alert-error">✗ <?php echo e($general_error); ?></div>
<?php } ?>

<form method="post" action="admission_new.php" data-guard>
  <?php echo csrf_field(); ?>

  <div class="card">
    <div class="card-header">مرحلهٔ ۱ — یافتن یا ایجاد مراجع</div>
    <div class="card-body">
      <div class="form-group">
        <label for="mobile_number">شمارهٔ موبایل مراجع <span class="required-star">*</span></label>
        <div class="input-group">
          <input type="tel" id="mobile_number" name="mobile_number" dir="ltr" maxlength="13"
                 data-digits="en" inputmode="numeric" placeholder="09121234567"
                 value="<?php echo e($form['mobile_number']); ?>"
                 class="<?php echo isset($errors['mobile_number']) ? 'is-invalid' : ''; ?>" required>
          <button type="submit" name="action" value="lookup" class="btn btn-secondary"
                  id="btnLookup" data-endpoint="admission_new.php">🔍 جست‌وجو</button>
        </div>
        <span class="form-hint">شمارهٔ موبایل، شناسهٔ اصلی مراجع در سامانه است.</span>
        <?php if (isset($errors['mobile_number'])) { ?>
          <span class="field-error"><?php echo e($errors['mobile_number']); ?></span>
        <?php } ?>
      </div>

      <div id="lookupResult" class="alert alert-<?php echo e($lookup_class); ?><?php
        echo $lookup_message === '' ? ' hidden' : ''; ?>"><?php echo e($lookup_message); ?></div>

      <div id="personFields">
        <div class="form-row">
          <div class="form-group">
            <label for="first_name">نام <span class="required-star">*</span></label>
            <input type="text" id="first_name" name="first_name" value="<?php echo e($form['first_name']); ?>"
                   class="<?php echo isset($errors['first_name']) ? 'is-invalid' : ''; ?>" required>
            <?php if (isset($errors['first_name'])) { ?>
              <span class="field-error"><?php echo e($errors['first_name']); ?></span>
            <?php } ?>
          </div>
          <div class="form-group">
            <label for="last_name">نام خانوادگی <span class="required-star">*</span></label>
            <input type="text" id="last_name" name="last_name" value="<?php echo e($form['last_name']); ?>"
                   class="<?php echo isset($errors['last_name']) ? 'is-invalid' : ''; ?>" required>
            <?php if (isset($errors['last_name'])) { ?>
              <span class="field-error"><?php echo e($errors['last_name']); ?></span>
            <?php } ?>
          </div>
        </div>
        <div class="form-group mb-0">
          <label for="national_code">کد ملی (اختیاری)</label>
          <input type="text" id="national_code" name="national_code" dir="ltr" maxlength="10"
                 data-digits="en" value="<?php echo e($form['national_code']); ?>"
                 class="<?php echo isset($errors['national_code']) ? 'is-invalid' : ''; ?>">
          <?php if (isset($errors['national_code'])) { ?>
            <span class="field-error"><?php echo e($errors['national_code']); ?></span>
          <?php } ?>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">مرحلهٔ ۲ — جزئیات پذیرش</div>
    <div class="card-body">
      <div class="form-group">
        <label for="service_type_id">خدمت موردنظر <span class="required-star">*</span></label>
        <select id="service_type_id" name="service_type_id" required
                class="<?php echo isset($errors['service_type_id']) ? 'is-invalid' : ''; ?>">
          <option value="">-- انتخاب کنید --</option>
          <?php foreach ($services as $s) { ?>
            <option value="<?php echo (int)$s['id']; ?>"
              data-multi="<?php echo (int)$s['is_multi_person']; ?>"
              <?php echo ((int)$form['service_type_id'] === (int)$s['id']) ? ' selected' : ''; ?>>
              <?php echo e($s['title']); ?>
            </option>
          <?php } ?>
        </select>
        <?php if (isset($errors['service_type_id'])) { ?>
          <span class="field-error"><?php echo e($errors['service_type_id']); ?></span>
        <?php } ?>
      </div>

      <div class="form-group conditional-block<?php echo $show_companion ? '' : ' hidden'; ?>" id="companionBox">
        <label for="companion_count">تعداد همراه</label>
        <input type="number" id="companion_count" name="companion_count" min="1" max="10"
               value="<?php echo (int)$form['companion_count'] > 0 ? (int)$form['companion_count'] : 1; ?>"
               class="<?php echo isset($errors['companion_count']) ? 'is-invalid' : ''; ?>">
        <span class="form-hint">برای خدمات چندنفره، تعداد همراهان بین ۱ تا ۱۰ نفر.</span>
        <?php if (isset($errors['companion_count'])) { ?>
          <span class="field-error"><?php echo e($errors['companion_count']); ?></span>
        <?php } ?>
      </div>

      <div class="form-group">
        <label for="referral_reason_id">دلیل مراجعه <span class="required-star">*</span></label>
        <select id="referral_reason_id" name="referral_reason_id" required
                class="<?php echo isset($errors['referral_reason_id']) ? 'is-invalid' : ''; ?>">
          <option value="">-- انتخاب کنید --</option>
          <?php foreach ($reasons as $r) { ?>
            <option value="<?php echo (int)$r['id']; ?>"
              <?php echo ((int)$form['referral_reason_id'] === (int)$r['id']) ? ' selected' : ''; ?>>
              <?php echo e($r['label']); ?>
            </option>
          <?php } ?>
        </select>
        <?php if (isset($errors['referral_reason_id'])) { ?>
          <span class="field-error"><?php echo e($errors['referral_reason_id']); ?></span>
        <?php } ?>
      </div>

      <div class="form-group">
        <label for="referred_therapist_person_id">درمانگر مسئول <span class="required-star">*</span></label>
        <select id="referred_therapist_person_id" name="referred_therapist_person_id" required
                class="<?php echo isset($errors['referred_therapist_person_id']) ? 'is-invalid' : ''; ?>">
          <option value="">-- انتخاب کنید --</option>
          <?php foreach ($therapists as $t) { ?>
            <option value="<?php echo (int)$t['id']; ?>"
              <?php echo ((int)$form['referred_therapist_person_id'] === (int)$t['id']) ? ' selected' : ''; ?>>
              <?php echo e($t['first_name'] . ' ' . $t['last_name']); ?>
            </option>
          <?php } ?>
        </select>
        <span class="form-hint">پذیرش برای تصمیم‌گیری به کارتابل این درمانگر فرستاده می‌شود.</span>
        <?php if (isset($errors['referred_therapist_person_id'])) { ?>
          <span class="field-error"><?php echo e($errors['referred_therapist_person_id']); ?></span>
        <?php } ?>
      </div>

      <?php if (count($therapists) === 0) { ?>
        <div class="alert alert-warning">
          هیچ درمانگر فعالی در سامانه تعریف نشده است؛ ابتدا مدیر باید کاربر با نقش «درمانگر» بسازد.
        </div>
      <?php } ?>

      <div class="btn-row">
        <button type="submit" name="action" value="create" class="btn btn-primary">ثبت پذیرش</button>
        <a href="index.php" class="btn btn-secondary">انصراف</a>
      </div>
    </div>
  </div>
</form>

<script>
/* نمایش شرطی «تعداد همراه» بر اساس چندنفره‌بودن خدمت */
(function () {
  var sel = document.getElementById('service_type_id');
  var box = document.getElementById('companionBox');
  if (!sel || !box) { return; }
  var sync = function () {
    var opt = sel.options[sel.selectedIndex];
    var multi = opt && opt.getAttribute('data-multi') === '1';
    box.classList.toggle('hidden', !multi);
  };
  sel.addEventListener('change', sync);
  sync();
})();
</script>
<?php require __DIR__ . '/../templates/footer.php'; ?>
