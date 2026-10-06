<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — مراجعان من (درمانگر)
 *  نسخهٔ ۴.۱ — فقط مراجعانی که پذیرششان به این درمانگر ارجاع شده
 *  یا پروندهٔ بالینی‌شان در اختیار اوست.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_THERAPIST));

$me = (int)$_SESSION['person_id'];
$term = isset($_GET['q']) ? trim($_GET['q']) : '';
$rows = array();

try {
    $rows = patient_directory_search($db, $term, $me);
} catch (Exception $ex) {
    $ref = log_system_error('THERAPIST_PATIENT_LIST', $ex);
    render_error_page($ref);
}

$page_title = 'مراجعان من';
$active_menu = 'therapist_patients';
require __DIR__ . '/../templates/header.php';
?>
<h1>👥 مراجعان من</h1>

<div class="card">
  <div class="card-header">جست‌وجو</div>
  <div class="card-body">
    <form method="get" action="patients.php">
      <div class="form-row">
        <label for="q">نام، شمارهٔ موبایل یا کد ملی</label>
        <input type="text" id="q" name="q" maxlength="60"
               value="<?php echo e($term); ?>"
               placeholder="مثلاً: احمدی یا ۰۹۱۲۳۴۵۶۷۸۹">
        <p class="form-hint">فقط مراجعان خودتان جست‌وجو می‌شوند.</p>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">🔍 جست‌وجو</button>
        <?php if ($term !== '') { ?>
          <a class="btn btn-secondary" href="patients.php">پاک کردن</a>
        <?php } ?>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">نتیجه (<?php echo e(to_persian_digits(count($rows))); ?>)</div>
  <div class="card-body">
    <?php if (count($rows) === 0) { ?>
      <div class="empty-state">
        <?php echo $term === ''
          ? 'هنوز مراجعی به شما ارجاع نشده است.'
          : 'هیچ مراجعی از مراجعان شما با این مشخصات یافت نشد.'; ?>
      </div>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead>
            <tr><th>نام</th><th>موبایل</th><th>پذیرش‌ها</th>
                <th>آخرین پذیرش</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r) { ?>
            <tr>
              <td data-label="نام"><?php echo e($r['first_name'] . ' ' . $r['last_name']); ?></td>
              <td data-label="موبایل" class="mono"><?php
                echo e(to_persian_digits($r['mobile_number'])); ?></td>
              <td data-label="پذیرش‌ها"><?php
                echo e(to_persian_digits((int)$r['admission_count'])); ?></td>
              <td data-label="آخرین پذیرش"><?php
                echo e(jalali_display($r['last_admission_at'], 'date')); ?></td>
              <td data-label="اقدام">
                <a class="btn btn-sm btn-secondary"
                   href="patient_view.php?p=<?php echo e($r['public_id']); ?>">مشاهده</a>
              </td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
      </div>
    <?php } ?>
  </div>
</div>

<?php require __DIR__ . '/../templates/footer.php'; ?>
