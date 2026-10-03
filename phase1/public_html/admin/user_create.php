<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — ایجاد کاربر پرسنلی
 *  شامل جریان تأیید شمارهٔ موبایل تکراری و نمایش یک‌بارهٔ اطلاعات ورود.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

$errors = array();
$general_error = '';
$needs_confirmation = false;
$existing_person = null;
$created = null;

$form = array(
    'first_name' => '', 'last_name' => '', 'mobile_number' => '', 'national_code' => '',
    'login_identifier' => '', 'password' => '', 'role_codes' => array(ROLE_SECRETARY),
);

$selectable_roles = array(
    ROLE_ADMIN         => '🧑‍💼 مدیر',
    ROLE_SECRETARY     => '📋 منشی',
    ROLE_THERAPIST     => '🩺 درمانگر',
    ROLE_PSYCHOMETRIST => '🧪 روان‌سنج',
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $form['first_name']       = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
    $form['last_name']        = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
    $form['mobile_number']    = isset($_POST['mobile_number']) ? trim($_POST['mobile_number']) : '';
    $form['national_code']    = isset($_POST['national_code']) ? trim($_POST['national_code']) : '';
    $form['login_identifier'] = isset($_POST['login_identifier']) ? strtolower(trim($_POST['login_identifier'])) : '';
    $form['password']         = isset($_POST['password']) ? (string)$_POST['password'] : '';
    $form['role_codes']       = (isset($_POST['role_codes']) && is_array($_POST['role_codes']))
                                ? $_POST['role_codes'] : array();

    $confirm_existing = (isset($_POST['confirm_existing_person']) && $_POST['confirm_existing_person'] === '1');

    $check = staff_create_validate($form);
    if (!$check['ok']) {
        $errors = $check['errors'];
    } else {
        try {
            $person_data = array(
                'first_name'    => $form['first_name'],
                'last_name'     => $form['last_name'],
                'mobile_number' => $check['mobile'],
                'national_code' => $check['national_code'],
            );
            $result = staff_account_create(
                $db, $person_data, $form['login_identifier'], $form['password'],
                $form['role_codes'], (int)$_SESSION['person_id'], $confirm_existing
            );

            if (!empty($result['needs_confirmation'])) {
                $needs_confirmation = true;
                $existing_person = $result['existing_person'];
            } else {
                $created = array(
                    'name'     => $form['first_name'] . ' ' . $form['last_name'],
                    'username' => $form['login_identifier'],
                    'password' => $form['password'],
                    'roles'    => $form['role_codes'],
                );
                /* فرم برای ورود بعدی پاک می‌شود */
                $form = array(
                    'first_name' => '', 'last_name' => '', 'mobile_number' => '', 'national_code' => '',
                    'login_identifier' => '', 'password' => '', 'role_codes' => array(ROLE_SECRETARY),
                );
            }
        } catch (Exception $ex) {
            /* پیام‌های دامنه‌ای (نام کاربری تکراری و…) مستقیم نمایش داده می‌شوند */
            $message = $ex->getMessage();
            if (strpos($message, 'DB_') === 0) {
                $ref = log_system_error('STAFF_CREATE', $ex);
                $general_error = 'خطایی رخ داده است. (کد پیگیری: REF-' . $ref . ')';
            } else {
                $general_error = $message;
            }
        }
    }
}

$page_title = 'ایجاد کاربر پرسنل';
$active_menu = 'user_create';
require __DIR__ . '/../templates/header.php';
?>
<h1>➕ ایجاد کاربر پرسنل جدید</h1>

<?php if ($general_error !== '') { ?>
  <div class="alert alert-error">✗ <?php echo e($general_error); ?></div>
<?php } ?>

<?php if ($created !== null) { ?>
  <div class="alert alert-success">✓ کاربر «<?php echo e($created['name']); ?>» با موفقیت ساخته شد.</div>
  <div class="credentials-box">
    <strong>⚠️ این اطلاعات فقط همین یک‌بار نمایش داده می‌شود</strong>
    <p class="text-small">
      آن را یادداشت کنید و حضوری به کاربر تحویل دهید. رمز عبور در سامانه قابل بازیابی نیست.
    </p>
    <dl>
      <dt>نام کاربری</dt><dd><?php echo e($created['username']); ?></dd>
      <dt>رمز عبور اولیه</dt><dd><?php echo e($created['password']); ?></dd>
      <dt>نقش‌ها</dt>
      <dd style="font-family:inherit;font-size:16px">
        <?php
        $labels = array();
        foreach ($created['roles'] as $rc) { $labels[] = role_label($rc); }
        echo e(implode('، ', $labels));
        ?>
      </dd>
    </dl>
    <p class="text-small">کاربر در نخستین ورود، به‌صورت خودکار به صفحهٔ تغییر رمز هدایت می‌شود.</p>
  </div>
  <div class="btn-row mb-3">
    <a href="user_create.php" class="btn btn-primary">ایجاد کاربر دیگر</a>
    <a href="users_list.php" class="btn btn-secondary">فهرست کاربران</a>
  </div>

<?php } elseif ($needs_confirmation) { ?>
  <div class="alert alert-warning">
    <strong>ℹ️ این شمارهٔ موبایل قبلاً در سامانه ثبت شده است</strong>
    شمارهٔ <span class="mono"><?php echo e($existing_person['mobile_number']); ?></span>
    متعلق به «<?php echo e(person_full_name($existing_person)); ?>» است.
  </div>
  <div class="card">
    <div class="card-header card-header-light">آیا همان شخص است؟</div>
    <div class="card-body">
      <dl class="kv mb-3">
        <dt>نام ثبت‌شده</dt><dd><?php echo e(person_full_name($existing_person)); ?></dd>
        <dt>شمارهٔ موبایل</dt><dd><span class="mono"><?php echo e($existing_person['mobile_number']); ?></span></dd>
        <dt>وضعیت</dt>
        <dd><span class="status status-<?php echo $existing_person['status'] === 'ACTIVE' ? 'active' : 'off'; ?>">
          <?php echo $existing_person['status'] === 'ACTIVE' ? 'فعال' : 'غیرفعال'; ?></span></dd>
      </dl>
      <form method="post" action="user_create.php" data-guard>
        <?php echo csrf_field(); ?>
        <input type="hidden" name="confirm_existing_person" value="1">
        <?php foreach (array('first_name','last_name','mobile_number','national_code','login_identifier','password') as $f) { ?>
          <input type="hidden" name="<?php echo e($f); ?>" value="<?php echo e($_POST[$f]); ?>">
        <?php } ?>
        <?php foreach ((isset($_POST['role_codes']) ? $_POST['role_codes'] : array()) as $rc) { ?>
          <input type="hidden" name="role_codes[]" value="<?php echo e($rc); ?>">
        <?php } ?>
        <div class="btn-row">
          <button type="submit" class="btn btn-success">بله، همین شخص است — حساب برایش بساز</button>
          <a href="user_create.php" class="btn btn-secondary">خیر، اشتباه تایپی بود — اصلاح می‌کنم</a>
        </div>
      </form>
    </div>
  </div>

<?php } else { ?>
  <div class="card">
    <div class="card-body">
      <form method="post" action="user_create.php" data-guard>
        <?php echo csrf_field(); ?>

        <div class="form-section-title">اطلاعات شخصی</div>
        <div class="form-row">
          <div class="form-group">
            <label for="first_name">نام <span class="required-star">*</span></label>
            <input type="text" id="first_name" name="first_name" value="<?php echo e($form['first_name']); ?>"
                   class="<?php echo isset($errors['first_name']) ? 'is-invalid' : ''; ?>" required>
            <?php if (isset($errors['first_name'])) { ?>
              <span class="field-error"><?php echo e($errors['first_name']); ?></span>
            <?php } ?>
          </div>
          <div class="form-group">
            <label for="last_name">نام خانوادگی <span class="required-star">*</span></label>
            <input type="text" id="last_name" name="last_name" value="<?php echo e($form['last_name']); ?>"
                   class="<?php echo isset($errors['last_name']) ? 'is-invalid' : ''; ?>" required>
            <?php if (isset($errors['last_name'])) { ?>
              <span class="field-error"><?php echo e($errors['last_name']); ?></span>
            <?php } ?>
          </div>
        </div>

        <div class="form-group">
          <label for="mobile_number">شمارهٔ موبایل <span class="required-star">*</span></label>
          <input type="tel" id="mobile_number" name="mobile_number" dir="ltr" maxlength="13"
                 data-digits="en" placeholder="09121234567" value="<?php echo e($form['mobile_number']); ?>"
                 class="<?php echo isset($errors['mobile_number']) ? 'is-invalid' : ''; ?>" required>
          <?php if (isset($errors['mobile_number'])) { ?>
            <span class="field-error"><?php echo e($errors['mobile_number']); ?></span>
          <?php } ?>
        </div>

        <div class="form-group">
          <label for="national_code">کد ملی (اختیاری)</label>
          <input type="text" id="national_code" name="national_code" dir="ltr" maxlength="10"
                 data-digits="en" value="<?php echo e($form['national_code']); ?>"
                 class="<?php echo isset($errors['national_code']) ? 'is-invalid' : ''; ?>">
          <?php if (isset($errors['national_code'])) { ?>
            <span class="field-error"><?php echo e($errors['national_code']); ?></span>
          <?php } ?>
        </div>

        <div class="form-section-title">اطلاعات حساب</div>
        <div class="form-group">
          <label for="login_identifier">نام کاربری <span class="required-star">*</span></label>
          <input type="text" id="login_identifier" name="login_identifier" dir="ltr"
                 value="<?php echo e($form['login_identifier']); ?>"
                 class="<?php echo isset($errors['login_identifier']) ? 'is-invalid' : ''; ?>" required>
          <span class="form-hint">فقط حروف کوچک انگلیسی، عدد و زیرخط — حداقل ۴ نویسه.</span>
          <?php if (isset($errors['login_identifier'])) { ?>
            <span class="field-error"><?php echo e($errors['login_identifier']); ?></span>
          <?php } ?>
        </div>

        <div class="form-group">
          <label for="password">رمز عبور اولیه <span class="required-star">*</span></label>
          <input type="password" id="password" name="password" autocomplete="new-password"
                 class="<?php echo isset($errors['password']) ? 'is-invalid' : ''; ?>" required>
          <div data-strength-for="password"></div>
          <span class="form-hint">کاربر در نخستین ورود مجبور به تغییر آن می‌شود.</span>
          <?php if (isset($errors['password'])) { ?>
            <span class="field-error"><?php echo e($errors['password']); ?></span>
          <?php } ?>
        </div>

        <div class="form-section-title">نقش‌ها (می‌توانید چند مورد انتخاب کنید)</div>
        <div class="checkbox-list mb-3">
          <?php foreach ($selectable_roles as $code => $label) { ?>
            <label class="checkbox">
              <input type="checkbox" name="role_codes[]" value="<?php echo e($code); ?>"
                <?php echo in_array($code, $form['role_codes'], true) ? ' checked' : ''; ?>>
              <?php echo $label; ?>
            </label>
          <?php } ?>
        </div>
        <?php if (isset($errors['role_codes'])) { ?>
          <div class="alert alert-error"><?php echo e($errors['role_codes']); ?></div>
        <?php } ?>

        <div class="btn-row">
          <button type="submit" class="btn btn-primary">ایجاد کاربر</button>
          <a href="index.php" class="btn btn-secondary">انصراف</a>
        </div>
      </form>
    </div>
  </div>
<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
