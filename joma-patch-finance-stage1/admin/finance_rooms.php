<?php
/* ═══════════════════════════════════════════════════════════════════
 *  جوما فاز ۵ — تنظیمات مالی اتاق‌ها (فقط مدیر)
 *
 *  در این صفحه مشخص می‌شود هر اتاق:
 *    - به کدام مرکز مشاوره متصل است (یا «اتاقِ خودِ کلینیک» است)
 *    - نوع آن چیست: «تکی» یا «زوج/خانواده»
 *    - تعرفهٔ ساعتیِ مرکز برای آن اتاق چقدر است (برای مدل ساعتی)
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

if (!phase5_ready($db)) {
    redirect(APP_BASE_URL . '/upgrade_phase5.php');
}

$actor_person_id = (int)$_SESSION['person_id'];
$actor_role_code = $_SESSION['active_role_code'];

$general_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    try {
        if ($action === 'finance_update') {
            $room_public_id = isset($_POST['room_public_id']) ? $_POST['room_public_id'] : '';
            $room = db_select_one($db, "SELECT id, name FROM rooms WHERE public_id = ?",
                's', array($room_public_id));
            if (!$room) {
                throw new Exception('اتاق موردنظر پیدا نشد.');
            }
            $center_id_raw = isset($_POST['center_id']) ? $_POST['center_id'] : '';
            $room_type = isset($_POST['room_type']) ? $_POST['room_type'] : '';
            $hourly_rate_raw = isset($_POST['hourly_rate']) ? $_POST['hourly_rate'] : '';

            $result = finance_room_update_finance($db, (int)$room['id'], $center_id_raw,
                $room_type, $hourly_rate_raw, $actor_person_id, $actor_role_code);
            if (!$result['ok']) {
                throw new Exception(implode(' ', $result['errors']));
            }
            flash_set('rooms_fin_ok', 'تنظیمات مالیِ اتاق «' . $room['name'] . '» ذخیره شد.');
            redirect(APP_BASE_URL . '/admin/finance_rooms.php');
        }
    } catch (Exception $ex) {
        $general_error = $ex->getMessage();
    }
}

try {
    $rooms = finance_rooms_fetch($db);
    $centers = finance_centers_fetch_active($db);
} catch (Exception $ex) {
    $ref = log_system_error('FINANCE_ROOMS_LIST', $ex);
    render_error_page($ref);
}

$page_title = 'تنظیمات مالی اتاق‌ها';
$active_menu = 'finance_rooms';
require __DIR__ . '/../templates/header.php';
?>
<h1>🚪 تنظیمات مالی اتاق‌ها</h1>
<?php echo flash_render('rooms_fin_ok', 'success'); ?>
<?php if ($general_error !== '') { ?>
  <div class="alert alert-danger"><?php echo e($general_error); ?></div>
<?php } ?>

<div class="alert alert-info">
  هر اتاق را به مرکزِ مربوطه‌اش متصل کنید. اگر اتاقی «زوج/خانواده» علامت زده شود،
  نوبت‌های زوج‌درمانی/خانواده‌درمانی به‌صورت خودکار با تعرفهٔ ساعتیِ همان اتاق محاسبه می‌شوند.
  تعرفهٔ ساعتی فقط برای «مدل ساعتی» کاربرد دارد.
</div>

<div class="card">
  <div class="card-header">اتاق‌ها (<?php echo to_persian_digits(count($rooms)); ?>)</div>
  <div class="card-body">
    <?php if (count($rooms) === 0) { ?>
      <p class="empty-state">هنوز اتاقی ثبت نشده است. ابتدا از صفحهٔ «اتاق‌ها» اتاق تعریف کنید.</p>
    <?php } elseif (count($centers) === 0) { ?>
      <p class="empty-state">ابتدا حداقل یک مرکز مشاوره در صفحهٔ «مراکز مشاوره» ثبت کنید.</p>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead>
            <tr>
              <th>نام اتاق</th><th>مرکز</th><th>نوع اتاق</th>
              <th>تعرفهٔ ساعتی (تومان)</th><th>عملیات</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rooms as $room) { ?>
            <tr>
              <form method="post" action="finance_rooms.php">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="finance_update">
              <input type="hidden" name="room_public_id" value="<?php echo e($room['public_id']); ?>">
              <td data-label="نام اتاق">
                <?php echo e($room['name']); ?>
                <span class="badge <?php echo $room['status'] === 'ACTIVE' ? 'badge-success' : 'badge-muted'; ?>">
                  <?php echo $room['status'] === 'ACTIVE' ? 'فعال' : 'غیرفعال'; ?>
                </span>
              </td>
              <td data-label="مرکز">
                <select name="center_id" class="table-input">
                  <option value="" <?php echo $room['center_id'] === null ? 'selected' : ''; ?>>— خودِ کلینیک —</option>
                  <?php foreach ($centers as $center) { ?>
                    <option value="<?php echo (int)$center['id']; ?>"
                      <?php echo ((int)$room['center_id'] === (int)$center['id']) ? 'selected' : ''; ?>>
                      <?php echo e(finance_center_label($center)); ?>
                    </option>
                  <?php } ?>
                </select>
              </td>
              <td data-label="نوع اتاق">
                <select name="room_type" class="table-input">
                  <option value="" <?php echo $room['room_type'] === null ? 'selected' : ''; ?>>— انتخاب نشده —</option>
                  <option value="SINGLE" <?php echo $room['room_type'] === 'SINGLE' ? 'selected' : ''; ?>>تکی</option>
                  <option value="COUPLE" <?php echo $room['room_type'] === 'COUPLE' ? 'selected' : ''; ?>>زوج/خانواده</option>
                </select>
              </td>
              <td data-label="تعرفهٔ ساعتی">
                <input type="text" name="hourly_rate" class="table-input" style="width:140px"
                       value="<?php echo $room['hourly_rate'] !== null ? to_persian_digits($room['hourly_rate']) : ''; ?>"
                       placeholder="مثلاً ۵۰۰۰۰۰">
              </td>
              <td data-label="عملیات">
                <button type="submit" class="btn btn-sm btn-primary">💾 ذخیره</button>
              </td>
              </form>
            </tr>
          <?php } ?>
          </tbody>
        </table>
      </div>
    <?php } ?>
  </div>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
