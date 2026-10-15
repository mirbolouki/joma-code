<?php
/* ═════════════════════════════════════════════════════════════════════
 *  تاریخچهٔ اداریِ بایگانی و بازگشایی پرونده
 *  این صفحه نیز هیچ محتوای درمانی یا آزمونی را نمی‌خواند.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));
if (!clinic_admin_modules_ready($db)) {
    redirect(APP_BASE_URL . '/upgrade_admin_modules.php');
}

$case_public_id = isset($_GET['case_id']) ? trim((string)$_GET['case_id']) : '';
if (!preg_match('/^cs_[a-f0-9]{20}$/', $case_public_id)) {
    http_response_code(404);
    $case = null;
    $history = array();
} else {
    try {
        $case = db_select_one(
            $db,
            "SELECT c.public_id, c.status, c.opened_at,
                    p.first_name AS patient_first_name, p.last_name AS patient_last_name,
                    tp.first_name AS therapist_first_name, tp.last_name AS therapist_last_name,
                    st.title AS service_title
               FROM clinical_cases c
               INNER JOIN persons p ON p.id = c.patient_person_id
               INNER JOIN persons tp ON tp.id = c.responsible_therapist_person_id
               INNER JOIN admissions a ON a.id = c.admission_id
               LEFT JOIN service_types st ON st.id = a.service_type_id
              WHERE c.public_id = ? LIMIT 1",
            's',
            array($case_public_id)
        );
        $history = $case ? clinic_case_admin_status_history($db, $case_public_id) : array();
        if (!$case) {
            http_response_code(404);
        }
    } catch (Exception $ex) {
        $ref = log_system_error('ADMIN_CASE_STATUS_HISTORY', $ex);
        render_error_page($ref);
    }
}

$page_title = 'تاریخچهٔ وضعیت پرونده';
$active_menu = 'admin_cases';
require __DIR__ . '/../templates/header.php';
?>
<h1>🕘 تاریخچهٔ وضعیت پرونده</h1>
<?php if (!$case) { ?>
  <div class="alert alert-error">پرونده پیدا نشد یا نشانی آن معتبر نیست.</div>
<?php } else { ?>
  <div class="card">
    <div class="card-header">اطلاعات اداری پرونده</div>
    <div class="card-body">
      <p><strong>کد پرونده:</strong> <span class="mono"><?php echo e($case['public_id']); ?></span></p>
      <p><strong>مراجع:</strong> <?php echo e($case['patient_first_name'] . ' ' . $case['patient_last_name']); ?></p>
      <p><strong>درمانگر مسئول:</strong> <?php echo e($case['therapist_first_name'] . ' ' . $case['therapist_last_name']); ?></p>
      <p><strong>نوع خدمت:</strong> <?php echo $case['service_title'] !== null ? e($case['service_title']) : '—'; ?></p>
      <p><strong>وضعیت فعلی:</strong> <?php echo $case['status'] === 'ACTIVE' ? 'فعال' : 'بایگانی‌شده'; ?></p>
      <p><strong>تاریخ تشکیل:</strong> <?php echo e(jalali_display($case['opened_at'], 'date')); ?></p>
    </div>
  </div>

  <div class="card">
    <div class="card-header">سوابق بایگانی و بازگشایی</div>
    <div class="card-body">
      <?php if (count($history) === 0) { ?>
        <div class="empty-state">
          برای این پرونده هنوز تغییر وضعیتی در این بخش ثبت نشده است.
          اگر پرونده پیش از نصب این بخش بایگانی شده باشد، رویدادهای قدیمی در این تاریخچه وجود ندارند.
        </div>
      <?php } else { ?>
        <div class="table-wrap">
          <table class="table table-card">
            <thead>
              <tr><th>زمان</th><th>عملیات</th><th>انجام‌دهنده</th><th>علت ثبت‌شده</th></tr>
            </thead>
            <tbody>
            <?php foreach ($history as $event) { ?>
              <tr>
                <td data-label="زمان"><?php echo e(jalali_display($event['created_at'])); ?></td>
                <td data-label="عملیات"><?php echo $event['to_status'] === 'ARCHIVED' ? 'بایگانی' : 'بازگشایی'; ?></td>
                <td data-label="انجام‌دهنده"><?php
                  echo e($event['actor_first_name'] . ' ' . $event['actor_last_name']); ?></td>
                <td data-label="علت ثبت‌شده"><?php echo nl2br(e($event['reason_text'])); ?></td>
              </tr>
            <?php } ?>
            </tbody>
          </table>
        </div>
      <?php } ?>
    </div>
  </div>
<?php } ?>
<p><a class="btn btn-secondary" href="<?php echo e(APP_BASE_URL); ?>/admin/cases.php">بازگشت به فهرست پرونده‌ها</a></p>
<?php require __DIR__ . '/../templates/footer.php'; ?>
