<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — پورتال مراجع، فاز ۱: فهرست نوبت‌های آینده و سوابق
 *
 *  این صفحه فقط‌خواندنی است. تمام ردیف‌ها با person_id نشست مراجع
 *  محدود می‌شوند؛ هیچ شناسه‌ای از مرورگر برای تعیین صاحب پرونده پذیرفته
 *  نمی‌شود.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
$me = patient_require_portal_session($db);

$allowed_views = array('upcoming', 'past', 'all');
$view = isset($_GET['view']) ? trim((string)$_GET['view']) : 'upcoming';
if (!in_array($view, $allowed_views, true)) {
    $view = 'upcoming';
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) {
    $page = 1;
}
$per_page = 20;
$appointments = array();
$total = 0;
$total_pages = 1;
$fatal = null;

try {
    if (!phase2_ready($db)) {
        $fatal = 'بخش نوبت‌دهی هنوز روی این سامانه فعال نشده است.';
    } else {
        $where = 'ad.patient_person_id = ?';
        $types = 'i';
        $params = array((int)$me);

        if ($view === 'upcoming') {
            $where .= ' AND ap.appointment_start_utc >= ?';
            $types .= 's';
            $params[] = now_dt();
        } elseif ($view === 'past') {
            $where .= ' AND ap.appointment_start_utc < ?';
            $types .= 's';
            $params[] = now_dt();
        }

        $count_row = db_select_one(
            $db,
            "SELECT COUNT(*) AS total
               FROM appointments ap
               INNER JOIN admissions ad ON ad.id = ap.admission_id
              WHERE " . $where,
            $types,
            $params
        );
        $total = $count_row ? (int)$count_row['total'] : 0;
        $total_pages = max(1, (int)ceil($total / $per_page));
        if ($page > $total_pages) {
            $page = $total_pages;
        }
        $offset = ($page - 1) * $per_page;
        $direction = ($view === 'upcoming') ? 'ASC' : 'DESC';

        $appointments = db_select_all(
            $db,
            "SELECT ap.public_id, ap.appointment_start_utc, ap.appointment_end_utc,
                    ap.status,
                    st.title AS service_title,
                    CONCAT(tp.first_name, ' ', tp.last_name) AS therapist_name,
                    r.name AS room_name
               FROM appointments ap
               INNER JOIN admissions ad ON ad.id = ap.admission_id
               INNER JOIN service_types st ON st.id = ap.service_type_id
               INNER JOIN persons tp ON tp.id = ap.therapist_person_id
               LEFT JOIN rooms r ON r.id = ap.room_id
              WHERE " . $where . "
              ORDER BY ap.appointment_start_utc " . $direction . ", ap.id " . $direction . "
              LIMIT " . (int)$per_page . " OFFSET " . (int)$offset,
            $types,
            $params
        );
    }
} catch (Exception $ex) {
    $ref = log_system_error('PATIENT_APPOINTMENTS', $ex);
    render_error_page($ref);
}

$page_title = 'نوبت‌های من';
$active_menu = 'patient_appointments';
require __DIR__ . '/../templates/header.php';
?>
<h1>📅 نوبت‌های من</h1>

<?php if ($fatal !== null) { ?>
  <div class="alert alert-warning"><?php echo e($fatal); ?></div>
<?php } else { ?>

<div class="card">
  <div class="card-header">فهرست نوبت‌ها</div>
  <div class="card-body">
    <div class="form-actions">
      <a class="btn btn-sm <?php echo $view === 'upcoming' ? 'btn-primary' : 'btn-secondary'; ?>"
         href="appointments.php?view=upcoming">آینده</a>
      <a class="btn btn-sm <?php echo $view === 'past' ? 'btn-primary' : 'btn-secondary'; ?>"
         href="appointments.php?view=past">سوابق</a>
      <a class="btn btn-sm <?php echo $view === 'all' ? 'btn-primary' : 'btn-secondary'; ?>"
         href="appointments.php?view=all">همه</a>
    </div>
    <p class="form-hint">نوبت‌های لغوشده نیز در فهرست دیده می‌شوند و وضعیتشان مشخص است.</p>

    <?php if (count($appointments) === 0) { ?>
      <p class="empty-state">
        <?php
          if ($view === 'upcoming') {
              echo 'نوبت آینده‌ای ثبت نشده است.';
          } elseif ($view === 'past') {
              echo 'سابقه‌ای از نوبت‌ها وجود ندارد.';
          } else {
              echo 'نوبتی برای نمایش وجود ندارد.';
          }
        ?>
      </p>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead>
            <tr><th>زمان</th><th>خدمت</th><th>درمانگر</th><th>اتاق</th><th>وضعیت</th></tr>
          </thead>
          <tbody>
          <?php foreach ($appointments as $ap) {
              $local_end = utc_to_local($ap['appointment_end_utc']); ?>
            <tr>
              <td data-label="زمان">
                <?php echo e(appointment_display($ap['appointment_start_utc'])); ?>
                <span class="text-muted">تا <?php echo e(time_display($local_end)); ?></span>
              </td>
              <td data-label="خدمت"><?php echo e($ap['service_title']); ?></td>
              <td data-label="درمانگر"><?php echo e($ap['therapist_name']); ?></td>
              <td data-label="اتاق"><?php echo $ap['room_name'] !== null && $ap['room_name'] !== ''
                  ? e($ap['room_name']) : '—'; ?></td>
              <td data-label="وضعیت">
                <span class="badge <?php echo e(appointment_status_class($ap['status'])); ?>">
                  <?php echo e(appointment_status_label($ap['status'])); ?>
                </span>
              </td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
      </div>

      <div class="form-actions">
        <span class="form-hint">
          <?php echo e(to_persian_digits($total)); ?> نوبت — صفحهٔ
          <?php echo e(to_persian_digits($page)); ?> از <?php echo e(to_persian_digits($total_pages)); ?>
        </span>
        <?php if ($page > 1) { ?>
          <a class="btn btn-sm btn-secondary"
             href="appointments.php?view=<?php echo e($view); ?>&amp;page=<?php echo e($page - 1); ?>">صفحهٔ قبل</a>
        <?php } ?>
        <?php if ($page < $total_pages) { ?>
          <a class="btn btn-sm btn-secondary"
             href="appointments.php?view=<?php echo e($view); ?>&amp;page=<?php echo e($page + 1); ?>">صفحهٔ بعد</a>
        <?php } ?>
      </div>
    <?php } ?>
  </div>
</div>

<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
