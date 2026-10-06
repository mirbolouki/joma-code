<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — پروندهٔ اداری مراجع (منشی و مدیر)
 *  نسخهٔ ۴.۱ — هویت، پذیرش‌ها، نوبت‌ها، وضعیت فرم‌ها.
 *  هیچ محتوای بالینی در این صفحه نیست.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_SECRETARY));   /* مدیر به‌واسطهٔ ارث‌بری نقش */

$me = (int)$_SESSION['person_id'];
$my_role = $_SESSION['active_role_code'];

$public_id = isset($_GET['p']) ? trim($_GET['p']) : '';
if ($public_id === '' && isset($_POST['p'])) {
    $public_id = trim($_POST['p']);
}

$person = null;
$fatal = null;
$form_error = null;

try {
    $person = patient_directory_fetch($db, $public_id, null);
    if (!$person) {
        audit_log_write($db, $me, $my_role, 'PATIENT_DIRECTORY_DENIED',
            'person', null, array('public_id' => $public_id));
        $fatal = PATIENT_DIR_DENY_MESSAGE;
    }
} catch (Exception $ex) {
    $ref = log_system_error('PATIENT_DIRECTORY_VIEW', $ex);
    render_error_page($ref);
}

/* ─────────────────────────── ویرایش ─────────────────────────── */
if ($fatal === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if ($action === 'update_identity') {
        try {
            $result = patient_directory_update($db, (int)$person['id'], $_POST, $me, $my_role);
            if (count($result['changed']) === 0) {
                flash_set('patient_success', 'تغییری داده نشد.');
            } else {
                flash_set('patient_success', 'اطلاعات مراجع به‌روز شد.');
            }
            redirect(APP_BASE_URL . '/reception/patient_view.php?p=' . urlencode($person['public_id']));
        } catch (Exception $ex) {
            $message = $ex->getMessage();
            if (strpos($message, 'DB_') === 0) {
                $ref = log_system_error('PATIENT_IDENTITY_UPDATE', $ex);
                $form_error = 'خطایی رخ داده است. (کد پیگیری: REF-' . $ref . ')';
            } else {
                $form_error = $message;
            }
        }
    }
}

$admissions = array();
$appointments = array();
$forms = array();

if ($fatal === null) {
    try {
        $admissions   = patient_directory_admissions($db, (int)$person['id']);
        $appointments = patient_directory_appointments($db, (int)$person['id']);
        $forms        = patient_directory_forms($db, (int)$person['id']);
    } catch (Exception $ex) {
        $ref = log_system_error('PATIENT_DIRECTORY_DETAIL', $ex);
        render_error_page($ref);
    }
}

$can_edit = true;
$back_url = 'patients.php';
$edit_input = array(
    'first_name'    => $fatal === null ? $person['first_name'] : '',
    'last_name'     => $fatal === null ? $person['last_name'] : '',
    'national_code' => $fatal === null ? (string)$person['national_code'] : '',
    'birth_date'    => $fatal === null ? patient_directory_birth_input($person['birth_date']) : '',
    'gender'        => $fatal === null ? (string)$person['gender'] : '',
);
/* اگر ارسال ناموفق بود، ورودی کاربر را نگه دار */
if ($form_error !== null) {
    foreach (array_keys($edit_input) as $k) {
        if (isset($_POST[$k])) { $edit_input[$k] = trim($_POST[$k]); }
    }
}

$page_title = 'پروندهٔ اداری مراجع';
$active_menu = 'patients';
require __DIR__ . '/../templates/header.php';
?>
<h1>🗂️ پروندهٔ اداری مراجع</h1>

<?php echo flash_render('patient_success', 'alert-success'); ?>

<?php if ($fatal !== null) { ?>
  <div class="alert alert-danger"><?php echo e($fatal); ?></div>
  <a class="btn btn-secondary" href="patients.php">بازگشت به فهرست مراجعان</a>
<?php } else { ?>
  <?php require __DIR__ . '/../templates/patient_profile_body.php'; ?>
<?php } ?>

<?php require __DIR__ . '/../templates/footer.php'; ?>
