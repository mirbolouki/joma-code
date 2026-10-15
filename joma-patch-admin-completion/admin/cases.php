<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فهرست مدیریتی پرونده‌ها
 *  فقط اطلاعات اداری پرونده نمایش داده می‌شود؛ یادداشت درمانی، پاسخ فرم‌ها
 *  و اسناد آزمون در این صفحه خوانده نمی‌شوند.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));
if (!clinic_admin_modules_ready($db)) {
    redirect(APP_BASE_URL . '/upgrade_admin_modules.php');
}

$action_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
    $case_public_id = isset($_POST['case_public_id']) ? trim((string)$_POST['case_public_id']) : '';
    $new_status = isset($_POST['new_status']) ? (string)$_POST['new_status'] : '';
    $reason_text = isset($_POST['reason_text']) ? trim((string)$_POST['reason_text']) : '';
    if ($action !== 'change_status') {
        $action_error = 'درخواست معتبر نیست.';
    } else {
        try {
            clinic_case_admin_change_status(
                $db,
                $case_public_id,
                $new_status,
                $reason_text,
                (int)$_SESSION['person_id'],
                ROLE_ADMIN
            );
            flash_set('case_admin_success', $new_status === 'ARCHIVED'
                ? 'پرونده بایگانی شد و علت آن در تاریخچه ثبت شد.'
                : 'پرونده بازگشایی شد و علت آن در تاریخچه ثبت شد.');
            redirect(APP_BASE_URL . '/admin/cases.php');
        } catch (Exception $ex) {
            $message = $ex->getMessage();
            if ((class_exists('mysqli_sql_exception') && $ex instanceof mysqli_sql_exception)
                || strpos($message, 'DB_') === 0) {
                $ref = log_system_error('ADMIN_CASE_STATUS_CHANGE', $ex);
                $action_error = 'تغییر وضعیت انجام نشد. کد پیگیری: ' . $ref;
            } else {
                $action_error = $message;
            }
        }
    }
}

$status_filter = isset($_GET['status']) ? strtoupper(trim((string)$_GET['status'])) : 'ALL';
if (!in_array($status_filter, array('ALL', 'ACTIVE', 'ARCHIVED'), true)) {
    $status_filter = 'ALL';
}
$term = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
if (strlen($term) > 120) {
    $term = substr($term, 0, 120);
}
$therapist_public_id = isset($_GET['therapist'])
    ? trim((string)$_GET['therapist']) : '';
if (strlen($therapist_public_id) > 40) {
    $therapist_public_id = '';
}

try {
    $summary = db_select_one(
        $db,
        "SELECT COUNT(*) AS total_cases,
                COALESCE(SUM(status = 'ACTIVE'), 0) AS active_cases,
                COALESCE(SUM(status = 'ARCHIVED'), 0) AS archived_cases
           FROM clinical_cases",
        '',
        array()
    );

    $therapists = db_select_all(
        $db,
        "SELECT p.public_id, p.first_name, p.last_name
           FROM persons p
          WHERE EXISTS (
                SELECT 1 FROM role_assignments ra
                 WHERE ra.person_id = p.id AND ra.role_code = 'therapist' AND ra.status = 'ACTIVE'
            ) OR EXISTS (
                SELECT 1 FROM clinical_cases old_case
                 WHERE old_case.responsible_therapist_person_id = p.id
            )
          ORDER BY p.last_name ASC, p.first_name ASC",
        '',
        array()
    );

    $where = array('1 = 1');
    $types = '';
    $params = array();

    if ($status_filter !== 'ALL') {
        $where[] = 'c.status = ?';
        $types .= 's';
        $params[] = $status_filter;
    }
    if ($therapist_public_id !== '') {
        $where[] = 'tp.public_id = ?';
        $types .= 's';
        $params[] = $therapist_public_id;
    }
    if ($term !== '') {
        $where[] = "(CONCAT(p.first_name, ' ', p.last_name) LIKE ?
                     OR p.mobile_number LIKE ?
                     OR c.public_id LIKE ?)";
        $like = '%' . $term . '%';
        $types .= 'sss';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $rows = db_select_all(
        $db,
        "SELECT c.public_id AS case_public_id, c.status, c.opened_at,
                p.public_id AS patient_public_id,
                p.first_name AS patient_first_name,
                p.last_name AS patient_last_name,
                p.mobile_number AS patient_mobile,
                tp.first_name AS therapist_first_name,
                tp.last_name AS therapist_last_name,
                a.public_id AS admission_public_id,
                st.title AS service_title
           FROM clinical_cases c
           INNER JOIN persons p ON p.id = c.patient_person_id
           INNER JOIN persons tp ON tp.id = c.responsible_therapist_person_id
           INNER JOIN admissions a ON a.id = c.admission_id
           LEFT JOIN service_types st ON st.id = a.service_type_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY c.opened_at DESC
          LIMIT 200",
        $types,
        $params
    );
} catch (Exception $ex) {
    $ref = log_system_error('ADMIN_CASE_DIRECTORY', $ex);
    render_error_page($ref);
}

$page_title = 'پرونده‌ها';
$active_menu = 'admin_cases';
require __DIR__ . '/../templates/header.php';
?>
<h1>📂 پرونده‌ها</h1>
<?php echo flash_render('case_admin_success', 'success'); ?>
<?php if ($action_error !== '') { ?>
  <div class="alert alert-error"><?php echo e($action_error); ?></div>
<?php } ?>
<p class="form-hint">
  این صفحه فقط برای پیگیری اداری است. یادداشت‌های درمانی، پاسخ‌های بالینی و فایل‌های آزمون
  در دسترس مدیر نیستند و اینجا نمایش داده نمی‌شوند. بایگانی، نوبت تازه و جابه‌جایی را متوقف می‌کند؛ نوبت‌های قبلی را لغو یا حذف نمی‌کند.
</p>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-card-icon">📂</div>
    <div class="stat-card-value"><?php echo e(to_persian_digits((int)$summary['total_cases'])); ?></div>
    <div class="stat-card-label">همهٔ پرونده‌ها</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-icon">✅</div>
    <div class="stat-card-value"><?php echo e(to_persian_digits((int)$summary['active_cases'])); ?></div>
    <div class="stat-card-label">پرونده‌های فعال</div>
  </div>
  <div class="stat-card">
    <div class="stat-card-icon">🗃️</div>
    <div class="stat-card-value"><?php echo e(to_persian_digits((int)$summary['archived_cases'])); ?></div>
    <div class="stat-card-label">پرونده‌های بایگانی‌شده</div>
  </div>
</div>

<div class="card">
  <div class="card-header">جست‌وجو و فیلتر</div>
  <div class="card-body">
    <form method="get" action="cases.php">
      <div class="form-row">
        <label for="q">نام مراجع، شمارهٔ موبایل یا کد پرونده</label>
        <input type="text" id="q" name="q" maxlength="120"
               value="<?php echo e($term); ?>"
               placeholder="نام، موبایل یا کد پرونده">
      </div>
      <div class="form-row">
        <label for="therapist">درمانگر مسئول</label>
        <select id="therapist" name="therapist">
          <option value="">همهٔ درمانگران</option>
          <?php foreach ($therapists as $therapist) { ?>
            <option value="<?php echo e($therapist['public_id']); ?>"<?php
              echo $therapist_public_id === $therapist['public_id'] ? ' selected' : ''; ?>><?php
              echo e($therapist['first_name'] . ' ' . $therapist['last_name']); ?></option>
          <?php } ?>
        </select>
      </div>
      <div class="form-row">
        <label for="status">وضعیت پرونده</label>
        <select id="status" name="status">
          <option value="ALL"<?php echo $status_filter === 'ALL' ? ' selected' : ''; ?>>همه</option>
          <option value="ACTIVE"<?php echo $status_filter === 'ACTIVE' ? ' selected' : ''; ?>>فعال</option>
          <option value="ARCHIVED"<?php echo $status_filter === 'ARCHIVED' ? ' selected' : ''; ?>>بایگانی‌شده</option>
        </select>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">🔍 اعمال فیلتر</button>
        <?php if ($term !== '' || $status_filter !== 'ALL' || $therapist_public_id !== '') { ?>
          <a class="btn btn-secondary" href="cases.php">پاک‌کردن فیلترها</a>
        <?php } ?>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">نتیجهٔ جست‌وجو (<?php echo e(to_persian_digits(count($rows))); ?>)</div>
  <div class="card-body">
    <?php if (count($rows) === 0) { ?>
      <div class="empty-state">پرونده‌ای با این مشخصات پیدا نشد.</div>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead>
            <tr>
              <th>کد پرونده</th><th>مراجع</th><th>درمانگر مسئول</th>
              <th>نوع خدمت</th><th>تاریخ تشکیل</th><th>وضعیت</th><th>عملیات</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $row) {
              $is_active = $row['status'] === 'ACTIVE';
          ?>
            <tr>
              <td data-label="کد پرونده"><span class="mono"><?php echo e($row['case_public_id']); ?></span></td>
              <td data-label="مراجع">
                <?php echo e($row['patient_first_name'] . ' ' . $row['patient_last_name']); ?>
                <div class="form-hint mono"><?php echo e(to_persian_digits($row['patient_mobile'])); ?></div>
              </td>
              <td data-label="درمانگر مسئول"><?php
                echo e($row['therapist_first_name'] . ' ' . $row['therapist_last_name']); ?></td>
              <td data-label="نوع خدمت"><?php echo $row['service_title'] !== null
                ? e($row['service_title']) : '—'; ?></td>
              <td data-label="تاریخ تشکیل"><?php echo e(jalali_display($row['opened_at'], 'date')); ?></td>
              <td data-label="وضعیت">
                <span class="badge <?php echo $is_active ? 'badge-success' : 'badge-muted'; ?>">
                  <?php echo $is_active ? 'فعال' : 'بایگانی‌شده'; ?>
                </span>
              </td>
              <td data-label="عملیات">
                <a class="btn btn-sm btn-secondary"
                   href="<?php echo e(APP_BASE_URL); ?>/reception/patient_view.php?p=<?php
                     echo e($row['patient_public_id']); ?>">مشخصات مراجع</a>
                <a class="btn btn-sm btn-secondary"
                   href="case_status_history.php?case_id=<?php echo e($row['case_public_id']); ?>">تاریخچه</a>
                <details class="case-status-action">
                  <summary><?php echo $is_active ? 'بایگانی پرونده' : 'بازگشایی پرونده'; ?></summary>
                  <form method="post" action="cases.php" data-guard>
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="case_public_id" value="<?php echo e($row['case_public_id']); ?>">
                    <input type="hidden" name="new_status" value="<?php echo $is_active ? 'ARCHIVED' : 'ACTIVE'; ?>">
                    <label for="reason_<?php echo e($row['case_public_id']); ?>">علت اداری (الزامی؛ بدون جزئیات درمانی)</label>
                    <textarea id="reason_<?php echo e($row['case_public_id']); ?>" name="reason_text"
                              rows="2" maxlength="500" required></textarea>
                    <button type="submit" class="btn btn-sm <?php echo $is_active ? 'btn-warning' : 'btn-success'; ?>"
                            data-confirm="آیا از <?php echo $is_active ? 'بایگانی' : 'بازگشایی'; ?> این پرونده مطمئن هستید؟">
                      <?php echo $is_active ? 'ثبت بایگانی' : 'ثبت بازگشایی'; ?>
                    </button>
                  </form>
                </details>
              </td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
      </div>
      <?php if (count($rows) >= 200) { ?>
        <p class="form-hint">
          حداکثر ۲۰۰ نتیجهٔ اخیر نمایش داده شده است؛ برای محدودکردن نتایج، جست‌وجو یا فیلتر را دقیق‌تر کنید.
        </p>
      <?php } ?>
    <?php } ?>
  </div>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
