<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما فاز ۲ — تعرفهٔ خدمات (فقط مدیر)
 *
 *  مالکیت تعرفه:
 *    • مدیر   → ایجاد، ویرایش، فعال/غیرفعال‌سازی
 *    • درمانگر → فقط مشاهدهٔ تعرفهٔ خودش (صفحهٔ therapist/tariffs_view.php)
 *    • منشی   → فقط دیدن مبلغ هنگام ثبت نوبت
 *
 *  مبلغ‌ها در پایگاه داده به «ریال» ذخیره و در صفحه به «تومان» نمایش
 *  داده می‌شوند؛ واحد همیشه کنار عدد نوشته می‌شود.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

if (!phase2_ready($db)) {
    redirect(APP_BASE_URL . '/upgrade_phase2.php');
}

$actor_person_id = (int)$_SESSION['person_id'];
$actor_role_code = $_SESSION['active_role_code'];

$general_error = '';
$selected_therapist = isset($_GET['therapist']) ? trim($_GET['therapist']) : '';

try {
    $therapists = therapists_fetch_active($db);
} catch (Exception $ex) {
    $ref = log_system_error('TARIFF_THERAPISTS', $ex);
    render_error_page($ref);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    try {
        if ($action === 'save') {
            $therapist_id = (int)(isset($_POST['therapist_person_id']) ? $_POST['therapist_person_id'] : 0);
            if ($therapist_id <= 0) {
                throw new Exception('درمانگر انتخاب نشده است.');
            }
            $prices = isset($_POST['price']) && is_array($_POST['price']) ? $_POST['price'] : array();
            $saved = 0;
            foreach ($prices as $service_type_id => $raw) {
                $raw_clean = str_replace(array(',', '،', ' '), '', to_latin_digits(trim((string)$raw)));
                $rial = tariff_toman_input_to_rial($raw);
                if ($raw_clean !== '' && $rial === null) {
                    throw new Exception('مبلغ واردشده معتبر نیست؛ فقط عدد (تومان) بنویسید.');
                }
                tariff_save($db, $therapist_id, (int)$service_type_id, $rial,
                    $actor_person_id, $actor_role_code);
                $saved++;
            }
            flash_set('tariff_ok', 'تعرفه‌ها ذخیره شد (' . to_persian_digits($saved) . ' خدمت بررسی شد).');
            redirect(APP_BASE_URL . '/admin/tariffs.php?therapist=' . urlencode($therapist_id));
        } elseif ($action === 'toggle') {
            $tariff = tariff_find_by_public_id($db, isset($_POST['tariff_public_id']) ? $_POST['tariff_public_id'] : '');
            if (!$tariff) {
                throw new Exception('تعرفهٔ موردنظر پیدا نشد.');
            }
            tariff_set_active($db, (int)$tariff['id'], ((int)$tariff['is_active'] === 1 ? 0 : 1),
                $actor_person_id, $actor_role_code);
            flash_set('tariff_ok', 'وضعیت تعرفه تغییر کرد.');
            redirect(APP_BASE_URL . '/admin/tariffs.php?therapist=' . urlencode((int)$tariff['therapist_person_id']));
        }
    } catch (Exception $ex) {
        $general_error = $ex->getMessage();
    }
}

$rows = array();
$therapist_row = null;
if ($selected_therapist !== '') {
    foreach ($therapists as $t) {
        if ((string)$t['id'] === (string)$selected_therapist) {
            $therapist_row = $t;
        }
    }
    if ($therapist_row) {
        try {
            $rows = tariffs_fetch_for_therapist($db, (int)$therapist_row['id']);
        } catch (Exception $ex) {
            $ref = log_system_error('TARIFF_ROWS', $ex);
            render_error_page($ref);
        }
    }
}

try {
    $all_tariffs = tariffs_fetch_all($db);
} catch (Exception $ex) {
    $all_tariffs = array();
}

$page_title = 'تعرفهٔ خدمات';
$active_menu = 'tariffs';
require __DIR__ . '/../templates/header.php';
?>
<h1>💰 تعرفهٔ خدمات</h1>
<?php echo flash_render('tariff_ok', 'success'); ?>
<?php if ($general_error !== '') { ?>
  <div class="alert alert-danger"><?php echo e($general_error); ?></div>
<?php } ?>

<div class="alert alert-info">
  تعرفه را «مدیر» تعیین می‌کند. درمانگر فقط تعرفهٔ خودش را می‌بیند و اجازهٔ تغییر ندارد.
  مبلغ‌ها را به <strong>تومان</strong> وارد کنید؛ سامانه آن‌ها را به ریال ذخیره می‌کند.
  مبلغ هر نوبت در لحظهٔ ثبت، روی همان نوبت «تثبیت» می‌شود و تغییر بعدی تعرفه آن را عوض نمی‌کند.
</div>

<div class="card">
  <div class="card-header">انتخاب درمانگر</div>
  <div class="card-body">
    <form method="get" action="tariffs.php" class="form-inline">
      <label for="therapist">درمانگر</label>
      <select id="therapist" name="therapist" onchange="this.form.submit()">
        <option value="">— انتخاب کنید —</option>
        <?php foreach ($therapists as $t) { ?>
          <option value="<?php echo e($t['id']); ?>"
            <?php echo ((string)$t['id'] === (string)$selected_therapist) ? 'selected' : ''; ?>>
            <?php echo e($t['first_name'] . ' ' . $t['last_name']); ?>
          </option>
        <?php } ?>
      </select>
      <noscript><button type="submit" class="btn btn-secondary btn-sm">نمایش</button></noscript>
    </form>
  </div>
</div>

<?php if ($therapist_row) { ?>
<div class="card">
  <div class="card-header">
    تعرفه‌های <?php echo e($therapist_row['first_name'] . ' ' . $therapist_row['last_name']); ?>
  </div>
  <div class="card-body">
    <form method="post" action="tariffs.php">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="therapist_person_id" value="<?php echo e($therapist_row['id']); ?>">
      <div class="table-wrap">
        <table class="table table-card">
          <thead>
            <tr><th>خدمت</th><th>مدت پیش‌فرض</th><th>مبلغ هر جلسه (تومان)</th><th>وضعیت</th></tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r) { ?>
            <tr>
              <td data-label="خدمت"><?php echo e($r['service_title']); ?></td>
              <td data-label="مدت"><?php echo to_persian_digits($r['default_duration_minutes']); ?> دقیقه</td>
              <td data-label="مبلغ">
                <input type="text" inputmode="numeric" dir="ltr" class="price-input"
                       name="price[<?php echo e($r['service_type_id']); ?>]"
                       value="<?php echo e(((int)$r['is_active'] === 1 && $r['tariff_id'] !== null)
                                 ? tariff_rial_to_toman_value($r['price_per_session']) : ''); ?>"
                       placeholder="خالی = بدون تعرفه">
              </td>
              <td data-label="وضعیت">
                <?php if ($r['tariff_id'] === null) { ?>
                  <span class="badge badge-muted">تعیین نشده</span>
                <?php } else { ?>
                  <span class="badge <?php echo (int)$r['is_active'] === 1 ? 'badge-success' : 'badge-muted'; ?>">
                    <?php echo (int)$r['is_active'] === 1 ? 'فعال' : 'غیرفعال'; ?>
                  </span>
                  <form method="post" action="tariffs.php" class="inline-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="tariff_public_id" value="<?php echo e($r['tariff_public_id']); ?>">
                    <button type="submit" class="btn btn-sm btn-secondary">
                      <?php echo (int)$r['is_active'] === 1 ? '⏸' : '▶️'; ?>
                    </button>
                  </form>
                <?php } ?>
              </td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">💾 ذخیرهٔ تعرفه‌ها</button>
      </div>
      <p class="form-hint">اگر خانهٔ مبلغ را خالی بگذارید، تعرفهٔ آن خدمت «غیرفعال» می‌شود
        (رکورد حذف فیزیکی نمی‌شود).</p>
    </form>
  </div>
</div>
<?php } ?>

<div class="card">
  <div class="card-header">همهٔ تعرفه‌های ثبت‌شده (<?php echo to_persian_digits(count($all_tariffs)); ?>)</div>
  <div class="card-body">
    <?php if (count($all_tariffs) === 0) { ?>
      <p class="empty-state">هنوز تعرفه‌ای ثبت نشده است.</p>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead><tr><th>درمانگر</th><th>خدمت</th><th>مبلغ</th><th>وضعیت</th></tr></thead>
          <tbody>
          <?php foreach ($all_tariffs as $t) { ?>
            <tr>
              <td data-label="درمانگر"><?php echo e($t['first_name'] . ' ' . $t['last_name']); ?></td>
              <td data-label="خدمت"><?php echo e($t['service_title']); ?></td>
              <td data-label="مبلغ"><?php echo tariff_rial_to_toman_display($t['price_per_session']); ?></td>
              <td data-label="وضعیت">
                <span class="badge <?php echo (int)$t['is_active'] === 1 ? 'badge-success' : 'badge-muted'; ?>">
                  <?php echo (int)$t['is_active'] === 1 ? 'فعال' : 'غیرفعال'; ?>
                </span>
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
