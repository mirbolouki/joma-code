<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — ویرایش نقش‌های یک کاربر پرسنلی (مدیر)
 *
 *  نقش‌ها فیزیکی حذف نمی‌شوند؛ باطل می‌شوند و تاریخچه می‌ماند.
 *  تغییر بلافاصله اثر می‌کند، چون auth_require_active_session() در هر
 *  درخواست نقش‌ها را از پایگاه داده بازمی‌خواند.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

$me = (int)$_SESSION['person_id'];
$my_role = isset($_SESSION['active_role_code']) ? $_SESSION['active_role_code'] : ROLE_ADMIN;

$role_options = array(
    ROLE_ADMIN         => array('🧑‍💼 مدیر', 'مدیریت کاربران، اتاق‌ها، تعرفه‌ها و تنظیمات. اختیارات اداری منشی را هم دارد، ولی هرگز به دادهٔ بالینی دسترسی ندارد.'),
    ROLE_SECRETARY     => array('📋 منشی', 'ثبت پذیرش، جست‌وجوی مراجع، ثبت و جابه‌جایی نوبت.'),
    ROLE_THERAPIST     => array('🩺 درمانگر', 'کارتابل، تصمیم پذیرش، تقویم شخصی، پروندهٔ بالینی و یادداشت محرمانه.'),
    ROLE_PSYCHOMETRIST => array('🧪 روان‌سنج', 'در فازهای فعلی صفحهٔ فعالی ندارد؛ برای آینده نگه داشته شده است.'),
);

$person_public_id = isset($_GET['person_id']) ? trim($_GET['person_id']) : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['person_id'])) {
    $person_public_id = trim($_POST['person_id']);
}

$target = null;
$fatal = null;
$form_error = null;
$current_roles = array();

try {
    $target = db_select_one(
        $db,
        "SELECT p.id, p.public_id, p.first_name, p.last_name, p.mobile_number,
                a.id AS account_id, a.login_identifier, a.status AS account_status
           FROM persons p
           INNER JOIN accounts a ON a.person_id = p.id
          WHERE p.public_id = ? AND a.account_type = 'STAFF'
          LIMIT 1",
        's',
        array($person_public_id)
    );
    if (!$target) {
        throw new Exception('کاربر موردنظر یافت نشد.');
    }
    foreach (role_assignments_fetch_active($db, (int)$target['id']) as $r) {
        $current_roles[] = $r['role_code'];
    }
} catch (Exception $ex) {
    $fatal = $ex->getMessage();
}

$selected = $current_roles;

if ($fatal === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $selected = (isset($_POST['role_codes']) && is_array($_POST['role_codes']))
                  ? $_POST['role_codes'] : array();
    try {
        $result = staff_roles_update($db, (int)$target['id'], $selected, $me, $my_role);

        if (count($result['granted']) === 0 && count($result['revoked']) === 0) {
            flash_set('users_success', 'تغییری در نقش‌ها داده نشد.');
        } else {
            $parts = array();
            if (count($result['granted']) > 0) {
                $labels = array();
                foreach ($result['granted'] as $c) { $labels[] = role_label($c); }
                $parts[] = 'افزوده شد: ' . implode('، ', $labels);
            }
            if (count($result['revoked']) > 0) {
                $labels = array();
                foreach ($result['revoked'] as $c) { $labels[] = role_label($c); }
                $parts[] = 'برداشته شد: ' . implode('، ', $labels);
            }
            flash_set('users_success', 'نقش‌های «' . $target['first_name'] . ' ' . $target['last_name']
                . '» به‌روز شد. ' . implode(' — ', $parts)
                . '. این تغییر بلافاصله اعمال شد.');
        }
        redirect(APP_BASE_URL . '/admin/users_list.php');
    } catch (Exception $ex) {
        $form_error = $ex->getMessage();
    }
}

$page_title = 'ویرایش نقش‌های کاربر';
$active_menu = 'users_list';
require __DIR__ . '/../templates/header.php';
?>
<h1>🔑 ویرایش نقش‌های کاربر</h1>

<?php if ($fatal !== null) { ?>
  <div class="alert alert-danger"><?php echo e($fatal); ?></div>
  <a class="btn btn-secondary" href="users_list.php">بازگشت به فهرست کاربران</a>
<?php } else { ?>

<div class="card">
  <div class="card-header">
    <?php echo e($target['first_name'] . ' ' . $target['last_name']); ?>
    — نام کاربری <span class="mono"><?php echo e($target['login_identifier']); ?></span>
    <?php if ($target['account_status'] !== 'ACTIVE') { ?>
      <span class="badge badge-muted">حساب غیرفعال</span>
    <?php } ?>
  </div>
  <div class="card-body">

    <?php if ($form_error !== null) { ?>
      <div class="alert alert-error"><?php echo e($form_error); ?></div>
    <?php } ?>

    <div class="alert alert-info">
      نقش‌های انتخاب‌شده <strong>بلافاصله</strong> اعمال می‌شوند؛ کاربر لازم نیست خارج و دوباره وارد شود.
      اگر نقشی که هم‌اکنون با آن کار می‌کند برداشته شود، در نخستین صفحهٔ بعدی از او خواسته می‌شود
      نقش دیگری انتخاب کند.
    </div>

    <form method="post" action="user_roles.php">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="person_id" value="<?php echo e($target['public_id']); ?>">

      <div class="form-section-title">نقش‌های کاربر (می‌توانید چند مورد انتخاب کنید)</div>
      <div class="checkbox-list mb-3">
      <?php foreach ($role_options as $code => $meta) { ?>
        <label class="checkbox">
          <input type="checkbox" name="role_codes[]" value="<?php echo e($code); ?>"
            <?php echo in_array($code, $selected, true) ? ' checked' : ''; ?>>
          <span>
            <strong><?php echo e($meta[0]); ?></strong>
            <span class="form-hint"><?php echo e($meta[1]); ?></span>
          </span>
        </label>
      <?php } ?>
      </div>

      <div class="alert alert-warning">
        <strong>قواعد ایمنی:</strong>
        <ul class="hint-list">
          <li>دست‌کم یک نقش باید انتخاب شود. برای قطع کامل دسترسی، از «غیرفعال‌سازی» در فهرست کاربران استفاده کنید.</li>
          <li>اگر این کاربر تنها مدیر فعال سامانه باشد، نقش مدیر از او گرفته نمی‌شود — حتی اگر خودتان باشید. ابتدا مدیر دیگری بسازید.</li>
          <li>وقتی بیش از یک مدیر هست، باز هم نمی‌توانید نقش مدیر را از حساب خودتان بردارید؛ مدیر دیگری باید این کار را بکند.</li>
          <li>نقش‌ها حذف فیزیکی نمی‌شوند؛ تاریخچهٔ اعطا و ابطال در سامانه می‌ماند.</li>
        </ul>
      </div>

      <div class="btn-row">
        <button type="submit" class="btn btn-primary">ذخیرهٔ نقش‌ها</button>
        <a class="btn btn-secondary" href="users_list.php">انصراف</a>
      </div>
    </form>
  </div>
</div>

<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
