<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — اطلاعات تماس و سابقهٔ اداری مراجع (درمانگر)
 *  نسخهٔ ۴.۱ — فقط خواندنی. ویرایش هویت کار منشی و مدیر است.
 *  محتوای بالینی اینجا نیست؛ آن در صفحهٔ پروندهٔ بالینی است.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_THERAPIST));

$me = (int)$_SESSION['person_id'];
$my_role = $_SESSION['active_role_code'];

$public_id = isset($_GET['p']) ? trim($_GET['p']) : '';

$person = null;
$fatal = null;

try {
    $person = patient_directory_fetch($db, $public_id, $me);
    if (!$person) {
        audit_log_write($db, $me, $my_role, 'PATIENT_DIRECTORY_DENIED',
            'person', null, array('public_id' => $public_id));
        $fatal = PATIENT_DIR_DENY_MESSAGE;
    }
} catch (Exception $ex) {
    $ref = log_system_error('THERAPIST_PATIENT_VIEW', $ex);
    render_error_page($ref);
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
        $ref = log_system_error('THERAPIST_PATIENT_DETAIL', $ex);
        render_error_page($ref);
    }
}

$can_edit = false;
$back_url = 'patients.php';
$form_error = null;
$edit_input = array('first_name' => '', 'last_name' => '',
                    'national_code' => '', 'birth_date' => '', 'gender' => '');

$page_title = 'اطلاعات مراجع';
$active_menu = 'therapist_patients';
require __DIR__ . '/../templates/header.php';
?>
<h1>🗂️ اطلاعات مراجع</h1>

<?php if ($fatal !== null) { ?>
  <div class="alert alert-danger"><?php echo e($fatal); ?></div>
  <a class="btn btn-secondary" href="patients.php">بازگشت به فهرست مراجعان من</a>
<?php } else { ?>
  <?php require __DIR__ . '/../templates/patient_profile_body.php'; ?>
<?php } ?>

<?php require __DIR__ . '/../templates/footer.php'; ?>
