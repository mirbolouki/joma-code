<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — کارتابل درمانگر
 *  پذیرش‌های در انتظار تصمیم + پرونده‌های خودِ درمانگر
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_THERAPIST));

$me = (int)$_SESSION['person_id'];

try {
    $awaiting = admissions_fetch_awaiting_for_therapist($db, $me);
    $cases = clinical_cases_fetch_for_therapist($db, $me);
    $orphaned = phase3_ready($db) ? notes_orphaned_cases_for_therapist($db, $me) : array();
    $my_pending_forms = phase4_ready($db)
        ? form_assignments_pending_for_person($db, $me) : array();
} catch (Exception $ex) {
    $ref = log_system_error('THERAPIST_DASHBOARD', $ex);
    render_error_page($ref);
}

$page_title = 'کارتابل درمانگر';
$active_menu = 'therapist_home';
require __DIR__ . '/../templates/header.php';
?>
<h1>🩺 کارتابل درمانگر</h1>
<?php if (phase2_ready($db)) { ?>
<div class="action-panel">
  <a href="calendar.php" class="btn btn-primary">📅 تقویم من</a>
  <a href="absences.php" class="btn btn-secondary">🏖️ عدم حضور</a>
  <a href="tariffs_view.php" class="btn btn-secondary">💰 تعرفهٔ من</a>
</div>
<?php } ?>
<?php echo flash_render('therapist_success', 'success'); ?>

<?php if (count($orphaned) > 0) { ?>
  <div class="alert alert-warning">
    <strong>⚠️ یادآوری دربارهٔ پرونده‌هایی که دیگر مسئول آن‌ها نیستید</strong><br>
    شما روی <?php echo to_persian_digits(count($orphaned)); ?> پرونده یادداشت محرمانه دارید
    که اکنون به درمانگر دیگری سپرده شده است. طبق قاعدهٔ محرمانگی سامانه،
    <strong>این یادداشت‌ها برای درمانگر جدید و هیچ‌کس دیگر قابل خواندن نیست</strong>
    و شما هم دیگر به صفحهٔ آن پرونده‌ها دسترسی ندارید.
    <ul class="hint-list">
      <?php foreach ($orphaned as $o) { ?>
        <li>
          <?php echo e($o['first_name'] . ' ' . $o['last_name']); ?>
          — پرونده <span class="mono"><?php echo e($o['public_id']); ?></span>
          (<?php echo to_persian_digits((int)$o['note_count']); ?> یادداشت)
        </li>
      <?php } ?>
    </ul>
  </div>
<?php } ?>

<?php if (phase4_ready($db) && count($my_pending_forms) > 0) { ?>
  <div class="alert alert-info">
    <strong>🧾 <?php echo to_persian_digits(count($my_pending_forms)); ?> فرم در انتظار تکمیل توسط شماست.</strong>
    <ul class="hint-list">
      <?php foreach ($my_pending_forms as $pf) { ?>
        <li>
          <?php echo e($pf['template_title']); ?> —
          <?php echo e($pf['patient_first_name'] . ' ' . $pf['patient_last_name']); ?>
          <a href="form_fill.php?a=<?php echo e($pf['public_id']); ?>">تکمیل</a>
        </li>
      <?php } ?>
    </ul>
    <a class="btn btn-sm btn-secondary" href="forms.php">همهٔ فرم‌های من</a>
  </div>
<?php } ?>

<h2>📥 پذیرش‌های در انتظار تصمیم (<?php echo to_persian_digits(count($awaiting)); ?> مورد)</h2>
<?php if (count($awaiting) === 0) { ?>
  <div class="card"><div class="card-body text-muted text-center">
    پذیرشی در انتظار تصمیم شما نیست. ✓
  </div></div>
<?php } else { ?>
  <div class="item-list mb-3">
    <?php foreach ($awaiting as $a) { ?>
      <div class="item-card">
        <div class="item-card-main">
          <div class="item-card-title">
            <span class="status status-waiting"><?php echo e($a['first_name'] . ' ' . $a['last_name']); ?></span>
          </div>
          <div class="item-card-meta">
            <span>خدمت: <?php echo e($a['service_title']); ?></span>
            <span>دلیل مراجعه: <?php echo e($a['reason_label']); ?></span>
            <span>موبایل: <span class="mono"><?php echo e($a['mobile_number']); ?></span></span>
            <span>ثبت: <?php echo e(jalali_display($a['created_at'])); ?></span>
          </div>
        </div>
        <a class="btn btn-primary"
           href="admission_review.php?admission_id=<?php echo e($a['public_id']); ?>">بررسی و تصمیم</a>
      </div>
    <?php } ?>
  </div>
<?php } ?>

<h2 id="cases">📂 پرونده‌های من (<?php echo to_persian_digits(count($cases)); ?> مورد)</h2>
<?php if (count($cases) === 0) { ?>
  <div class="card"><div class="card-body text-muted text-center">
    هنوز پرونده‌ای برای شما گشوده نشده است.
  </div></div>
<?php } else { ?>
  <div class="item-list">
    <?php foreach ($cases as $c) { ?>
      <div class="item-card">
        <div class="item-card-main">
          <div class="item-card-title">
            <span class="status status-active"><?php echo e($c['first_name'] . ' ' . $c['last_name']); ?></span>
          </div>
          <div class="item-card-meta">
            <span>شمارهٔ پرونده: <span class="mono"><?php echo e($c['public_id']); ?></span></span>
            <span>تاریخ گشایش: <?php echo e(jalali_display($c['opened_at'], 'date')); ?></span>
          </div>
        </div>
        <?php if (phase3_ready($db)) { ?>
          <a class="btn btn-primary"
             href="case_detail.php?case_id=<?php echo e($c['public_id']); ?>">
            مشاهدهٔ پرونده
          </a>
        <?php } else { ?>
          <button class="btn btn-secondary" disabled title="در فاز بعدی فعال می‌شود">
            مشاهدهٔ پرونده — فاز بعد
          </button>
        <?php } ?>
      </div>
    <?php } ?>
  </div>
<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
