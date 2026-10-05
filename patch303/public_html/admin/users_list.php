<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فهرست کاربران پرسنلی + فعال/غیرفعال کردن
 *  غیرفعال‌سازی بلافاصله اثر می‌کند (ADR-013).
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

$general_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $account_id = isset($_POST['account_id']) ? (int)$_POST['account_id'] : 0;
    $new_status = isset($_POST['new_status']) ? (string)$_POST['new_status'] : '';

    try {
        $target = db_select_one($db,
            "SELECT id, person_id, status FROM accounts WHERE id = ? AND account_type = 'STAFF' LIMIT 1",
            'i', array($account_id));

        if (!$target) {
            $general_error = 'کاربر موردنظر یافت نشد.';
        } elseif ((int)$target['person_id'] === (int)$_SESSION['person_id']) {
            $general_error = 'نمی‌توانید حساب خودتان را غیرفعال کنید.';
        } else {
            staff_account_toggle_status($db, $account_id, $new_status, (int)$_SESSION['person_id']);
            flash_set('users_success', $new_status === 'ACTIVE'
                ? 'کاربر با موفقیت فعال شد.'
                : 'کاربر غیرفعال شد و جلسهٔ کاری او بلافاصله بسته می‌شود.');
            redirect(APP_BASE_URL . '/admin/users_list.php');
        }
    } catch (Exception $ex) {
        $ref = log_system_error('USERS_LIST_TOGGLE', $ex);
        $general_error = 'خطایی رخ داده است. (کد پیگیری: REF-' . $ref . ')';
    }
}

try {
    $users = staff_users_fetch_all($db);
} catch (Exception $ex) {
    $ref = log_system_error('USERS_LIST_FETCH', $ex);
    render_error_page($ref);
}

$page_title = 'فهرست کاربران';
$active_menu = 'users_list';
require __DIR__ . '/../templates/header.php';
?>
<h1>👥 فهرست کاربران پرسنلی</h1>
<?php echo flash_render('users_success', 'success'); ?>
<?php if ($general_error !== '') { ?>
  <div class="alert alert-error">✗ <?php echo e($general_error); ?></div>
<?php } ?>

<div class="action-panel">
  <a href="user_create.php" class="btn btn-primary">➕ ایجاد کاربر جدید</a>
</div>

<div class="card">
  <div class="table-wrap">
    <table class="table table-card">
      <thead>
        <tr>
          <th>نام</th><th>نام کاربری</th><th>نقش‌ها</th>
          <th>موبایل</th><th>آخرین ورود</th><th>وضعیت</th><th>عملیات</th>
        </tr>
      </thead>
      <tbody>
      <?php if (count($users) === 0) { ?>
        <tr><td colspan="7" class="table-empty">هنوز کاربری ثبت نشده است.</td></tr>
      <?php } ?>
      <?php foreach ($users as $u) { ?>
        <?php
          $role_labels = array();
          foreach ($u['roles'] as $rc) { $role_labels[] = role_label($rc); }
          $is_active = ($u['status'] === 'ACTIVE');
          $is_self = ((int)$u['person_id'] === (int)$_SESSION['person_id']);
        ?>
        <tr>
          <td data-label="نام"><?php echo e($u['first_name'] . ' ' . $u['last_name']); ?></td>
          <td data-label="نام کاربری"><span class="mono"><?php echo e($u['login_identifier']); ?></span></td>
          <td data-label="نقش‌ها"><?php echo e(implode('، ', $role_labels)); ?></td>
          <td data-label="موبایل"><span class="mono"><?php echo e($u['mobile_number']); ?></span></td>
          <td data-label="آخرین ورود"><?php echo e(jalali_display($u['last_login_at'])); ?></td>
          <td data-label="وضعیت">
            <span class="status status-<?php echo $is_active ? 'active' : 'off'; ?>">
              <?php echo $is_active ? 'فعال' : 'غیرفعال'; ?>
            </span>
          </td>
          <td data-label="عملیات" class="col-actions">
            <a class="btn btn-sm btn-secondary"
               href="user_roles.php?person_id=<?php echo e($u['person_public_id']); ?>">🔑 نقش‌ها</a>
            <?php if ($is_self) { ?>
              <span class="badge badge-muted">حساب شما</span>
            <?php } else { ?>
              <form method="post" action="users_list.php" style="display:inline">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="account_id" value="<?php echo (int)$u['account_id']; ?>">
                <input type="hidden" name="new_status" value="<?php echo $is_active ? 'DISABLED' : 'ACTIVE'; ?>">
                <?php if ($is_active) { ?>
                  <button type="submit" class="btn btn-sm btn-danger"
                          data-confirm="آیا از غیرفعال‌کردن این کاربر مطمئن هستید؟ جلسهٔ فعال او بلافاصله بسته می‌شود.">
                    غیرفعال‌سازی
                  </button>
                <?php } else { ?>
                  <button type="submit" class="btn btn-sm btn-success">فعال‌سازی</button>
                <?php } ?>
              </form>
            <?php } ?>
          </td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
