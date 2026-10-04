<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما فاز ۲ — مدیریت اتاق‌ها (فقط مدیر)
 *
 *  ظرفیت اتاق «اطلاعاتی» است: هرگز مانع ثبت نوبت نمی‌شود.
 *  هم‌زمانی دو جلسه در یک اتاق فقط هشدار می‌دهد.
 *  اتاق هیچ‌گاه حذف فیزیکی نمی‌شود؛ فقط غیرفعال می‌شود.
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

$errors = array();
$general_error = '';
$form = array('name' => '', 'room_number' => '', 'capacity' => '1');
$edit_room = null;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    try {
        $edit_room = room_find($db, $_GET['edit']);
    } catch (Exception $ex) {
        $edit_room = null;
    }
    if ($edit_room) {
        $form['name'] = $edit_room['name'];
        $form['room_number'] = $edit_room['room_number'] !== null ? $edit_room['room_number'] : '';
        $form['capacity'] = (string)$edit_room['capacity'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';

    try {
        if ($action === 'create' || $action === 'update') {
            $form['name'] = isset($_POST['name']) ? $_POST['name'] : '';
            $form['room_number'] = isset($_POST['room_number']) ? $_POST['room_number'] : '';
            $form['capacity'] = isset($_POST['capacity']) ? $_POST['capacity'] : '1';

            $check = room_validate($form);
            if (!$check['ok']) {
                $errors = $check['errors'];
                if ($action === 'update') {
                    $edit_room = room_find($db, isset($_POST['room_public_id']) ? $_POST['room_public_id'] : '');
                }
            } elseif ($action === 'create') {
                room_create($db, $check['name'], $check['room_number'], $check['capacity'],
                    $actor_person_id, $actor_role_code);
                flash_set('rooms_ok', 'اتاق «' . $check['name'] . '» ثبت شد.');
                redirect(APP_BASE_URL . '/admin/rooms.php');
            } else {
                $target = room_find($db, isset($_POST['room_public_id']) ? $_POST['room_public_id'] : '');
                if (!$target) {
                    throw new Exception('اتاق موردنظر پیدا نشد.');
                }
                room_update($db, (int)$target['id'], $check['name'], $check['room_number'],
                    $check['capacity'], $actor_person_id, $actor_role_code);
                flash_set('rooms_ok', 'اتاق «' . $check['name'] . '» به‌روزرسانی شد.');
                redirect(APP_BASE_URL . '/admin/rooms.php');
            }
        } elseif ($action === 'status') {
            $target = room_find($db, isset($_POST['room_public_id']) ? $_POST['room_public_id'] : '');
            if (!$target) {
                throw new Exception('اتاق موردنظر پیدا نشد.');
            }
            $new_status = ($target['status'] === 'ACTIVE') ? 'INACTIVE' : 'ACTIVE';
            room_set_status($db, (int)$target['id'], $new_status, $actor_person_id, $actor_role_code);
            flash_set('rooms_ok', 'وضعیت اتاق «' . $target['name'] . '» تغییر کرد.');
            redirect(APP_BASE_URL . '/admin/rooms.php');
        }
    } catch (Exception $ex) {
        $general_error = $ex->getMessage();
    }
}

try {
    $rooms = rooms_fetch_all($db);
} catch (Exception $ex) {
    $ref = log_system_error('ROOMS_LIST', $ex);
    render_error_page($ref);
}

$page_title = 'اتاق‌ها';
$active_menu = 'rooms';
require __DIR__ . '/../templates/header.php';
?>
<h1>🏠 اتاق‌های کلینیک</h1>
<?php echo flash_render('rooms_ok', 'success'); ?>
<?php if ($general_error !== '') { ?>
  <div class="alert alert-danger"><?php echo e($general_error); ?></div>
<?php } ?>

<div class="alert alert-info">
  ظرفیت اتاق فقط جنبهٔ اطلاع‌رسانی دارد و هرگز مانع ثبت نوبت نمی‌شود؛
  اگر دو جلسه در یک اتاق هم‌پوشانی داشته باشند، سامانه فقط «هشدار» می‌دهد.
  تنها تداخلی که ثبت نوبت را رد می‌کند، تداخل زمان خودِ درمانگر است.
</div>

<div class="card">
  <div class="card-header"><?php echo $edit_room ? 'ویرایش اتاق' : 'افزودن اتاق تازه'; ?></div>
  <div class="card-body">
    <form method="post" action="rooms.php" class="form-grid">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="<?php echo $edit_room ? 'update' : 'create'; ?>">
      <?php if ($edit_room) { ?>
        <input type="hidden" name="room_public_id" value="<?php echo e($edit_room['public_id']); ?>">
      <?php } ?>

      <div class="form-row">
        <label for="name">نام اتاق <span class="required-star">*</span></label>
        <input type="text" id="name" name="name" value="<?php echo e($form['name']); ?>"
               class="<?php echo isset($errors['name']) ? 'is-invalid' : ''; ?>" required>
        <?php if (isset($errors['name'])) { ?><span class="field-error"><?php echo e($errors['name']); ?></span><?php } ?>
      </div>

      <div class="form-row">
        <label for="room_number">شمارهٔ اتاق</label>
        <input type="text" id="room_number" name="room_number" value="<?php echo e($form['room_number']); ?>"
               class="<?php echo isset($errors['room_number']) ? 'is-invalid' : ''; ?>">
        <?php if (isset($errors['room_number'])) { ?><span class="field-error"><?php echo e($errors['room_number']); ?></span><?php } ?>
      </div>

      <div class="form-row">
        <label for="capacity">ظرفیت (اطلاعاتی)</label>
        <select id="capacity" name="capacity"
                class="<?php echo isset($errors['capacity']) ? 'is-invalid' : ''; ?>">
          <?php for ($c = 1; $c <= 20; $c++) { ?>
            <option value="<?php echo $c; ?>"
              <?php echo ((string)$form['capacity'] === (string)$c) ? 'selected' : ''; ?>>
              <?php echo to_persian_digits($c); ?> نفر</option>
          <?php } ?>
        </select>
        <?php if (isset($errors['capacity'])) { ?><span class="field-error"><?php echo e($errors['capacity']); ?></span><?php } ?>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?php echo $edit_room ? '💾 ذخیرهٔ تغییرات' : '➕ ثبت اتاق'; ?></button>
        <?php if ($edit_room) { ?>
          <a href="rooms.php" class="btn btn-secondary">انصراف</a>
        <?php } ?>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">فهرست اتاق‌ها (<?php echo to_persian_digits(count($rooms)); ?>)</div>
  <div class="card-body">
    <?php if (count($rooms) === 0) { ?>
      <p class="empty-state">هنوز اتاقی ثبت نشده است. برای ثبت نوبت، دست‌کم یک اتاق فعال لازم است.</p>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead>
            <tr><th>نام</th><th>شماره</th><th>ظرفیت</th><th>وضعیت</th><th>عملیات</th></tr>
          </thead>
          <tbody>
          <?php foreach ($rooms as $room) { ?>
            <tr>
              <td data-label="نام"><?php echo e($room['name']); ?></td>
              <td data-label="شماره"><?php echo $room['room_number'] !== null
                    ? to_persian_digits(e($room['room_number'])) : '—'; ?></td>
              <td data-label="ظرفیت"><?php echo to_persian_digits($room['capacity']); ?></td>
              <td data-label="وضعیت">
                <span class="badge <?php echo $room['status'] === 'ACTIVE' ? 'badge-success' : 'badge-muted'; ?>">
                  <?php echo $room['status'] === 'ACTIVE' ? 'فعال' : 'غیرفعال'; ?>
                </span>
              </td>
              <td data-label="عملیات">
                <a class="btn btn-sm btn-secondary"
                   href="rooms.php?edit=<?php echo e($room['public_id']); ?>">✏️ ویرایش</a>
                <form method="post" action="rooms.php" class="inline-form">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="room_public_id" value="<?php echo e($room['public_id']); ?>">
                  <button type="submit" class="btn btn-sm <?php
                      echo $room['status'] === 'ACTIVE' ? 'btn-warning' : 'btn-success'; ?>">
                    <?php echo $room['status'] === 'ACTIVE' ? '⏸ غیرفعال' : '▶️ فعال'; ?>
                  </button>
                </form>
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
