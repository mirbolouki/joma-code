<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فهرست و جست‌وجوی مراجعان (منشی و مدیر)
 *  نسخهٔ ۴.۱
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_SECRETARY));   /* مدیر به‌واسطهٔ ارث‌بری نقش */

$term = isset($_GET['q']) ? trim($_GET['q']) : '';
$rows = array();
$fatal = null;

try {
    $rows = patient_directory_search($db, $term, null);
} catch (Exception $ex) {
    $ref = log_system_error('PATIENT_DIRECTORY_LIST', $ex);
    render_error_page($ref);
}

$page_title = 'مراجعان';
$active_menu = 'patients';
require __DIR__ . '/../templates/header.php';
?>
<h1>👥 مراجعان</h1>

<div class="card">
  <div class="card-header">جست‌وجو</div>
  <div class="card-body">
    <form method="get" action="patients.php">
      <div class="form-row">
        <label for="q">نام، شمارهٔ موبایل یا کد ملی</label>
        <input type="text" id="q" name="q" maxlength="60"
               value="<?php echo e($term); ?>"
               placeholder="مثلاً: احمدی یا ۰۹۱۲۳۴۵۶۷۸۹">
        <p class="form-hint">
          خالی بگذارید تا آخرین مراجعان نمایش داده شوند. جست‌وجوی عددی با
          دست‌کم چهار رقم روی موبایل و کد ملی انجام می‌شود.
        </p>
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
  <div class="card-header">
    نتیجه (<?php echo e(to_persian_digits(count($rows))); ?>)
  </div>
  <div class="card-body">
    <?php if (count($rows) === 0) { ?>
      <div class="empty-state">
        <?php echo $term === ''
          ? 'هنوز مراجعی با پذیرش ثبت‌شده وجود ندارد.'
          : 'هیچ مراجعی با این مشخصات یافت نشد.'; ?>
      </div>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead>
            <tr><th>نام</th><th>موبایل</th><th>کد ملی</th>
                <th>پذیرش‌ها</th><th>آخرین پذیرش</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r) { ?>
            <tr>
              <td data-label="نام"><?php echo e($r['first_name'] . ' ' . $r['last_name']); ?>
                <?php if ($r['status'] !== 'ACTIVE') { ?>
                  <span class="badge badge-muted">غیرفعال</span>
                <?php } ?>
              </td>
              <td data-label="موبایل" class="mono"><?php
                echo e(to_persian_digits($r['mobile_number'])); ?></td>
              <td data-label="کد ملی" class="mono"><?php
                echo $r['national_code'] ? e(to_persian_digits($r['national_code'])) : '—'; ?></td>
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
      <?php if (count($rows) >= PATIENT_DIR_PAGE_SIZE) { ?>
        <p class="form-hint">
          فقط <?php echo e(to_persian_digits(PATIENT_DIR_PAGE_SIZE)); ?> مورد نخست
          نمایش داده شد. برای یافتن مراجع خاص، جست‌وجو را دقیق‌تر کنید.
        </p>
      <?php } ?>
    <?php } ?>
  </div>
</div>

<?php require __DIR__ . '/../templates/footer.php'; ?>
