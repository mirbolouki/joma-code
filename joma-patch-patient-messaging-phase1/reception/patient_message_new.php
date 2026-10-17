<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — ارسال پیامِ امن به مراجع
 *  منشی/مدیر: هدف از شناسهٔ عمومیِ مراجع؛ درمانگر: فقط پروندهٔ متعلق به خودش.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN, ROLE_SECRETARY, ROLE_THERAPIST));

$me = (int)$_SESSION['person_id'];
$my_role = $_SESSION['active_role_code'];
$is_therapist = ($my_role === ROLE_THERAPIST);
$case_public_id = '';
$patient_public_id = '';
$case = null;
$patient = null;
$admission_id = null;
$error_message = '';
$subject = '';
$body = '';

if ($is_therapist) {
    $case_public_id = isset($_GET['case_id']) ? trim((string)$_GET['case_id']) : '';
    if ($case_public_id === '' && isset($_POST['case_id'])) {
        $case_public_id = trim((string)$_POST['case_id']);
    }
} else {
    $patient_public_id = isset($_GET['p']) ? trim((string)$_GET['p']) : '';
    if ($patient_public_id === '' && isset($_POST['patient_public_id'])) {
        $patient_public_id = trim((string)$_POST['patient_public_id']);
    }
}

if (!patient_messages_ready($db)) {
    $page_title = 'ارسال پیام به مراجع';
    $active_menu = 'patients';
    require __DIR__ . '/../templates/header.php';
    ?>
    <h1>✉️ ارسال پیام به مراجع</h1>
    <div class="alert alert-info">قابلیت پیام‌رسانی هنوز توسط مدیر سامانه فعال نشده است.</div>
    <?php if ($my_role === ROLE_ADMIN) { ?>
      <a class="btn btn-primary" href="<?php echo e(APP_BASE_URL); ?>/upgrade_patient_messages.php">رفتن به صفحهٔ فعال‌سازی</a>
    <?php } ?>
    <a class="btn btn-secondary" href="<?php echo e(APP_BASE_URL); ?>/reception/patients.php">بازگشت</a>
    <?php
    require __DIR__ . '/../templates/footer.php';
    exit;
}

try {
    if ($is_therapist) {
        if ($case_public_id === '') {
            throw new Exception('پرونده مشخص نشده است.');
        }
        $case = clinical_case_find_for_therapist($db, $case_public_id, $me);
        if ($case['status'] !== 'ACTIVE') {
            throw new Exception('از پروندهٔ بایگانی‌شده نمی‌توان پیام تازه فرستاد.');
        }
        $patient = array(
            'id' => (int)$case['patient_person_id'],
            'public_id' => $case['patient_public_id'],
            'first_name' => $case['first_name'],
            'last_name' => $case['last_name'],
            'mobile_number' => $case['mobile_number'],
            'status' => 'ACTIVE',
        );
        $admission_id = (int)$case['admission_id'];
    } else {
        if ($patient_public_id === '') {
            throw new Exception('شناسهٔ مراجع مشخص نشده است.');
        }
        $patient = patient_directory_fetch($db, $patient_public_id, null);
        if (!$patient || $patient['status'] !== 'ACTIVE'
            || !role_assignment_is_active($db, (int)$patient['id'], ROLE_PATIENT)) {
            throw new Exception('مراجعِ فعال پیدا نشد.');
        }
    }
} catch (Exception $ex) {
    $error_message = $ex->getMessage();
}

if ($patient && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $subject = isset($_POST['subject']) ? trim((string)$_POST['subject']) : '';
    $body = isset($_POST['body']) ? trim((string)$_POST['body']) : '';
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';

    try {
        if ($action !== 'send') {
            throw new Exception('درخواست معتبر نیست.');
        }
        $result = patient_message_create($db, (int)$patient['id'], 'MANUAL',
            $subject, $body, $admission_id, null, $me, $my_role);

        if ($result['sms_status'] === 'SENT') {
            $notice = 'پیام در پورتال ثبت شد و پیامک اطلاع‌رسانی ارسال شد.';
        } elseif ($result['sms_status'] === 'DEV_LOGGED') {
            $notice = 'پیام در پورتال ثبت شد؛ حالت پیامک آزمایشی است و پیامکی واقعاً ارسال نشد.';
        } elseif ($result['sms_status'] === 'DISABLED') {
            $notice = 'پیام در پورتال ثبت شد؛ ارسال پیامک در تنظیمات غیرفعال است.';
        } else {
            $notice = 'پیام در پورتال ثبت شد، اما پیامک ارسال نشد. تنظیمات SMS.ir را بررسی کنید.';
        }
        flash_set('patient_message_success', $notice);

        if ($is_therapist) {
            redirect(APP_BASE_URL . '/therapist/case_detail.php?case_id=' . rawurlencode($case_public_id));
        }
        redirect(APP_BASE_URL . '/reception/patient_view.php?p=' . rawurlencode($patient_public_id));
    } catch (Exception $ex) {
        $error_message = $ex->getMessage();
    }
}

$page_title = 'ارسال پیام به مراجع';
$active_menu = $is_therapist ? 'therapist_home' : 'patients';
require __DIR__ . '/../templates/header.php';
?>
<h1>✉️ ارسال پیام به مراجع</h1>
<?php if ($error_message !== '') { ?>
  <div class="alert alert-danger"><?php echo e($error_message); ?></div>
<?php } ?>

<?php if ($patient) { ?>
<div class="card">
  <div class="card-header">گیرنده</div>
  <div class="card-body">
    <strong><?php echo e($patient['first_name'] . ' ' . $patient['last_name']); ?></strong>
    <span class="form-hint"> — موبایل: <?php echo e(to_persian_digits($patient['mobile_number'])); ?></span>
  </div>
</div>

<div class="alert alert-info">
  متن کامل پیام در پورتال مراجع نمایش داده می‌شود. پیامک فقط اطلاع می‌دهد که پیام تازه‌ای دارید و لینک ورود را می‌فرستد؛ متن پیامک هیچ‌وقت شامل متن محرمانه نیست.
</div>

<div class="card">
  <div class="card-header">متن پیام</div>
  <div class="card-body">
    <form method="post" data-guard>
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="send">
      <?php if ($is_therapist) { ?>
        <input type="hidden" name="case_id" value="<?php echo e($case_public_id); ?>">
      <?php } else { ?>
        <input type="hidden" name="patient_public_id" value="<?php echo e($patient_public_id); ?>">
      <?php } ?>
      <div class="form-group">
        <label for="subject">عنوان پیام</label>
        <input type="text" id="subject" name="subject" maxlength="160" required
               value="<?php echo e($subject); ?>">
      </div>
      <div class="form-group">
        <label for="body">متن پیام</label>
        <textarea id="body" name="body" rows="7" maxlength="3000" required><?php echo e($body); ?></textarea>
        <span class="form-hint">حداکثر ۳۰۰۰ نویسه. یادداشت درمانگر در این صفحه نمایش داده یا ارسال نمی‌شود.</span>
      </div>
      <div class="btn-row">
        <?php if ($is_therapist) { ?>
          <a class="btn btn-secondary" href="<?php echo e(APP_BASE_URL); ?>/therapist/case_detail.php?case_id=<?php echo rawurlencode($case_public_id); ?>">بازگشت به پرونده</a>
        <?php } else { ?>
          <a class="btn btn-secondary" href="<?php echo e(APP_BASE_URL); ?>/reception/patient_view.php?p=<?php echo rawurlencode($patient_public_id); ?>">بازگشت به پروندهٔ اداری</a>
        <?php } ?>
        <button type="submit" class="btn btn-primary">ارسال پیام و اعلان</button>
      </div>
    </form>
  </div>
</div>
<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
