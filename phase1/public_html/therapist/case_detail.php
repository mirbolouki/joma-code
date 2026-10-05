<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۳: نمای پروندهٔ بالینی (درمانگر)
 *  مشخصات مراجع + پذیرش + نوبت‌ها (فقط نمایشی) + یادداشت‌های خودِ درمانگر
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_THERAPIST));

$me = (int)$_SESSION['person_id'];
$case_public_id = isset($_GET['case_id']) ? trim($_GET['case_id']) : '';

$case = null;
$notes = array();
$retracted = array();
$appointments = array();
$forms = array();
$access_error = null;

try {
    if (!phase3_ready($db)) {
        throw new Exception('بخش پروندهٔ بالینی هنوز روی این سامانه نصب نشده است.');
    }
    if ($case_public_id === '') {
        throw new Exception('شمارهٔ پرونده مشخص نشده است.');
    }
    $case = clinical_case_find_for_therapist($db, $case_public_id, $me);
    $notes = notes_fetch_for_case($db, (int)$case['id'], $me, false);
    $all = notes_fetch_for_case($db, (int)$case['id'], $me, true);
    foreach ($all as $n) {
        if ($n['status'] === 'RETRACTED') {
            $retracted[] = $n;
        }
    }
    $appointments = case_appointments_for_therapist($db, (int)$case['admission_id'], $me);
    if (phase4_ready($db)) {
        $forms = form_assignments_for_admission($db, (int)$case['admission_id']);
    }
} catch (Exception $ex) {
    $access_error = $ex->getMessage();
}

$page_title = 'پروندهٔ بالینی';
$active_menu = 'therapist_home';
require __DIR__ . '/../templates/header.php';
?>
<h1>📂 پروندهٔ بالینی</h1>

<?php if ($access_error !== null) { ?>
  <div class="alert alert-danger"><?php echo e($access_error); ?></div>
  <a class="btn btn-secondary" href="index.php">بازگشت به کارتابل</a>
<?php } else { ?>

<?php echo flash_render('note_success', 'success'); ?>
<?php echo flash_render('note_error', 'danger'); ?>

<div class="action-panel">
  <a href="index.php" class="btn btn-secondary">↩️ بازگشت به کارتابل</a>
  <?php if ($case['status'] === 'ACTIVE') { ?>
    <a href="note_create.php?case_id=<?php echo e($case['public_id']); ?>" class="btn btn-primary">
      ➕ افزودن یادداشت تازه
    </a>
  <?php } ?>
  <?php if (phase4_ready($db)) { ?>
    <a href="form_assign.php?case_id=<?php echo e($case['public_id']); ?>" class="btn btn-secondary">
      🧾 فرم‌های این پرونده
    </a>
  <?php } ?>
</div>

<?php if (phase4_ready($db)) { ?>
<div class="card">
  <div class="card-header">🧾 فرم‌های پرونده (<?php echo e(to_persian_digits(count($forms))); ?>)</div>
  <div class="card-body">
    <?php if (count($forms) === 0) { ?>
      <p class="empty-state">فرمی برای این پرونده ثبت نشده است.</p>
    <?php } else { ?>
      <div class="item-list">
        <?php foreach ($forms as $fa) { ?>
          <div class="item-card">
            <div class="item-card-main">
              <div class="item-card-title"><?php echo e($fa['template_title']); ?></div>
              <div class="item-card-meta">
                پرکننده: <?php echo e(form_assignee_role_label($fa['assignee_role'])); ?>
                —
                <span class="badge <?php echo e(form_assignment_status_class($fa['status'])); ?>">
                  <?php echo e(form_assignment_status_label($fa['status'])); ?>
                </span>
              </div>
            </div>
            <?php if ($fa['status'] === 'PENDING' && (int)$fa['assignee_person_id'] === $me) { ?>
              <a class="btn btn-sm btn-primary"
                 href="form_fill.php?a=<?php echo e($fa['public_id']); ?>">تکمیل</a>
            <?php } elseif ($fa['status'] === 'SUBMITTED' || $fa['status'] === 'RETRACTED') { ?>
              <a class="btn btn-sm btn-secondary"
                 href="form_view.php?a=<?php echo e($fa['public_id']); ?>">مشاهده</a>
            <?php } ?>
          </div>
        <?php } ?>
      </div>
    <?php } ?>
  </div>
</div>
<?php } ?>

<div class="card">
  <div class="card-header">👤 مشخصات مراجع</div>
  <div class="card-body">
    <div class="form-grid">
      <div><span class="form-hint">نام و نام خانوادگی</span><br>
        <strong><?php echo e($case['first_name'] . ' ' . $case['last_name']); ?></strong></div>
      <div><span class="form-hint">موبایل</span><br>
        <span class="mono"><?php echo e($case['mobile_number']); ?></span></div>
      <div><span class="form-hint">جنسیت</span><br>
        <?php echo e(case_gender_label($case['gender'])); ?></div>
      <div><span class="form-hint">سن</span><br>
        <?php
          $age = case_patient_age($case['birth_date']);
          echo $age === null ? '—' : e(to_persian_digits($age) . ' سال');
        ?></div>
      <div><span class="form-hint">شمارهٔ پرونده</span><br>
        <span class="mono"><?php echo e($case['public_id']); ?></span></div>
      <div><span class="form-hint">تاریخ گشایش پرونده</span><br>
        <?php echo e(jalali_display($case['opened_at'], 'date')); ?></div>
      <div><span class="form-hint">خدمت</span><br>
        <?php echo e($case['service_title'] !== null ? $case['service_title'] : '—'); ?></div>
      <div><span class="form-hint">دلیل مراجعه</span><br>
        <?php echo e($case['reason_label'] !== null ? $case['reason_label'] : '—'); ?></div>
      <div><span class="form-hint">وضعیت پرونده</span><br>
        <span class="badge <?php echo $case['status'] === 'ACTIVE' ? 'badge-success' : 'badge-muted'; ?>">
          <?php echo $case['status'] === 'ACTIVE' ? 'فعال' : 'بایگانی‌شده'; ?></span></div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">📅 نوبت‌های این پرونده (<?php echo to_persian_digits(count($appointments)); ?> مورد)</div>
  <div class="card-body">
    <?php if (!phase2_ready($db)) { ?>
      <div class="table-empty">بخش نوبت‌دهی روی این سامانه نصب نشده است.</div>
    <?php } elseif (count($appointments) === 0) { ?>
      <div class="table-empty">برای این پرونده نوبتی ثبت نشده است.</div>
    <?php } else { ?>
      <table class="table table-card">
        <thead><tr>
          <th>زمان</th><th>مدت</th><th>خدمت</th><th>اتاق</th><th>وضعیت</th>
        </tr></thead>
        <tbody>
        <?php foreach ($appointments as $ap) { ?>
          <tr>
            <td data-label="زمان"><?php
              echo e(appointment_display($ap['appointment_start_utc'])
                   . ' تا ' . time_display(utc_to_local($ap['appointment_end_utc'])));
            ?></td>
            <td data-label="مدت"><?php echo e(to_persian_digits((int)$ap['duration_minutes']) . ' دقیقه'); ?></td>
            <td data-label="خدمت"><?php echo e($ap['service_title'] !== null ? $ap['service_title'] : '—'); ?></td>
            <td data-label="اتاق"><?php echo e($ap['room_name'] !== null ? $ap['room_name'] : '—'); ?></td>
            <td data-label="وضعیت">
              <span class="badge <?php echo e(appointment_status_class($ap['status'])); ?>">
                <?php echo e(appointment_status_label($ap['status'])); ?></span>
            </td>
          </tr>
        <?php } ?>
        </tbody>
      </table>
      <p class="form-hint">
        لغو و جابه‌جایی نوبت از اختیارات پذیرش و مدیریت است؛ در این صفحه فقط نمایش داده می‌شود.
      </p>
    <?php } ?>
  </div>
</div>

<div class="card">
  <div class="card-header">
    📝 یادداشت‌های محرمانهٔ من (<?php echo to_persian_digits(count($notes)); ?> مورد فعال)
  </div>
  <div class="card-body">
    <div class="alert alert-info">
      🔒 این یادداشت‌ها فقط برای شما قابل مشاهده‌اند. مدیر، منشی و سایر درمانگران
      هیچ مسیری برای دیدن آن‌ها در سامانه ندارند.
    </div>

    <?php if (count($notes) === 0) { ?>
      <div class="table-empty">هنوز یادداشتی برای این پرونده ننوشته‌اید.</div>
    <?php } else { ?>
      <div class="item-list">
        <?php foreach ($notes as $n) { ?>
          <div class="item-card">
            <div class="item-card-main">
              <div class="item-card-title">
                <?php echo e(jalali_display($n['created_at'])); ?>
                <?php if ((int)$n['edit_count'] > 0) { ?>
                  <span class="badge badge-muted">ویرایش‌شده</span>
                <?php } ?>
              </div>
              <div class="note-preview"><?php echo e($n['preview_text']); ?><?php
                echo ((int)$n['character_count'] > 160) ? '…' : ''; ?></div>
              <div class="item-card-meta">
                <span><?php echo to_persian_digits((int)$n['character_count']); ?> نویسه</span>
                <span>مشاهده: <?php echo to_persian_digits((int)$n['view_count']); ?> بار</span>
                <?php if (note_is_editable($n)) { ?>
                  <span class="text-success">مهلت ویرایش: <?php echo e(note_edit_window_left($n)); ?></span>
                <?php } else { ?>
                  <span class="text-muted">مهلت ویرایش گذشته</span>
                <?php } ?>
              </div>
            </div>
            <a class="btn btn-primary"
               href="note_view.php?case_id=<?php echo e($case['public_id']); ?>&amp;note_id=<?php echo e($n['public_id']); ?>">
              مشاهده
            </a>
          </div>
        <?php } ?>
      </div>
    <?php } ?>

    <?php if (count($retracted) > 0) { ?>
      <h3 class="mt-3">یادداشت‌های باطل‌شده (<?php echo to_persian_digits(count($retracted)); ?> مورد)</h3>
      <div class="item-list">
        <?php foreach ($retracted as $n) { ?>
          <div class="item-card note-retracted">
            <div class="item-card-main">
              <div class="item-card-title">
                <?php echo e(jalali_display($n['created_at'])); ?>
                <span class="badge badge-muted">باطل‌شده</span>
              </div>
              <div class="item-card-meta">
                <span>تاریخ ابطال: <?php echo e(jalali_display($n['retracted_at'])); ?></span>
              </div>
            </div>
            <a class="btn btn-secondary"
               href="note_view.php?case_id=<?php echo e($case['public_id']); ?>&amp;note_id=<?php echo e($n['public_id']); ?>">
              مشاهده
            </a>
          </div>
        <?php } ?>
      </div>
    <?php } ?>
  </div>
</div>

<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
