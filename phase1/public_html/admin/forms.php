<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۴: فهرست و ساخت قالب‌های فرم (مدیر)
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

$me = (int)$_SESSION['person_id'];
$my_role = $_SESSION['active_role_code'];

$fatal = null;
$error = null;
$templates = array();
$form = array(
    'template_code' => '',
    'title' => '',
    'description' => '',
    'default_assignee_role' => 'THERAPIST',
    'admin_can_view' => '1',
    'patient_can_have_draft' => '1',
);

try {
    if (!phase4_ready($db)) {
        throw new Exception('بخش فرم‌ها هنوز روی این سامانه نصب نشده است. '
            . 'نخست فایل upgrade_phase4.php را اجرا کنید.');
    }
} catch (Exception $ex) {
    $fatal = $ex->getMessage();
}

if ($fatal === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    try {
        if ($action === 'create') {
            foreach (array_keys($form) as $k) {
                if ($k === 'admin_can_view' || $k === 'patient_can_have_draft') {
                    $form[$k] = isset($_POST[$k]) ? '1' : '0';
                } else {
                    $form[$k] = isset($_POST[$k]) ? trim((string)$_POST[$k]) : '';
                }
            }
            if ($form['default_assignee_role'] !== 'PATIENT') {
                $form['default_assignee_role'] = 'THERAPIST';
            }
            $new_id = form_template_create($db, $form, $me, $my_role);
            $created = form_template_find($db, $new_id);
            flash_set('form_success', 'قالب ساخته شد. اکنون پرسش‌های آن را اضافه کنید.');
            redirect('form_builder.php?t=' . rawurlencode($created['public_id']));

        } elseif ($action === 'activate' || $action === 'archive' || $action === 'version') {
            $t = form_template_find_by_public_id($db, isset($_POST['t']) ? $_POST['t'] : '');
            if (!$t) {
                throw new Exception('قالب یافت نشد.');
            }
            if ($action === 'activate') {
                form_template_activate($db, (int)$t['id'], $me, $my_role);
                flash_set('form_success', 'قالب فعال شد.');
            } elseif ($action === 'archive') {
                form_template_archive($db, (int)$t['id'], $me, $my_role);
                flash_set('form_success', 'قالب بایگانی شد.');
            } else {
                $new_id = form_template_new_version($db, (int)$t['id'], $me, $my_role);
                $created = form_template_find($db, $new_id);
                flash_set('form_success', 'نسخهٔ تازه ساخته شد. پاسخ‌های نسخهٔ قبلی دست‌نخورده است.');
                redirect('form_builder.php?t=' . rawurlencode($created['public_id']));
            }
            redirect('forms.php');
        }
    } catch (Exception $ex) {
        $error = $ex->getMessage();
    }
}

if ($fatal === null) {
    try {
        $templates = form_templates_fetch_all($db);
    } catch (Exception $ex) {
        $fatal = $ex->getMessage();
    }
}

$flash_success = flash_get('form_success');

$page_title = 'قالب‌های فرم';
$active_menu = 'admin_forms';
require __DIR__ . '/../templates/header.php';
?>
<h1>🧾 قالب‌های فرم</h1>

<?php if ($fatal !== null) { ?>
  <div class="alert alert-danger"><?php echo e($fatal); ?></div>
  <a class="btn btn-secondary" href="index.php">بازگشت به داشبورد</a>
<?php } else { ?>

<?php if ($flash_success) { ?>
  <div class="alert alert-success"><?php echo e($flash_success); ?></div>
<?php } ?>
<?php if ($error !== null) { ?>
  <div class="alert alert-danger"><?php echo e($error); ?></div>
<?php } ?>

<div class="alert alert-info">
  قالبی که نخستین پاسخش ثبت شود، قفل می‌شود. برای تغییر آن «نسخهٔ جدید» بسازید
  تا پاسخ‌های ثبت‌شده همان‌طور که بوده‌اند خوانده شوند.
</div>

<div class="card">
  <div class="card-header">قالب‌های موجود (<?php echo to_persian_digits(count($templates)); ?>)</div>
  <div class="card-body">
    <?php if (count($templates) === 0) { ?>
      <p class="empty-state">هنوز قالبی ساخته نشده است.</p>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead>
            <tr>
              <th>عنوان</th><th>شناسه</th><th>نسخه</th><th>پرکننده</th>
              <th>پرسش‌ها</th><th>تخصیص‌ها</th><th>وضعیت</th><th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($templates as $t) { ?>
            <tr>
              <td data-label="عنوان"><?php echo e($t['title']); ?></td>
              <td data-label="شناسه" class="mono"><?php echo e($t['template_code']); ?></td>
              <td data-label="نسخه"><?php echo e(to_persian_digits((int)$t['version'])); ?></td>
              <td data-label="پرکننده"><?php echo e(form_assignee_role_label($t['default_assignee_role'])); ?></td>
              <td data-label="پرسش‌ها"><?php echo e(to_persian_digits((int)$t['field_count'])); ?></td>
              <td data-label="تخصیص‌ها"><?php echo e(to_persian_digits((int)$t["assignment_count"])); ?></td>
              <td data-label="وضعیت">
                <span class="badge <?php
                  echo $t['status'] === 'ACTIVE' ? 'badge-success'
                     : ($t['status'] === 'DRAFT' ? 'badge-warning' : 'badge-muted'); ?>">
                  <?php echo e(form_template_status_label($t['status'])); ?>
                </span>
                <?php if (form_template_is_locked($t)) { ?>
                  <span class="badge badge-muted">قفل‌شده</span>
                <?php } ?>
              </td>
              <td data-label="اقدام">
                <a class="btn btn-sm btn-secondary"
                   href="form_builder.php?t=<?php echo e($t['public_id']); ?>">
                  <?php echo form_template_is_locked($t) ? 'مشاهده' : 'ویرایش پرسش‌ها'; ?>
                </a>
                <?php if ($t['status'] === 'DRAFT') { ?>
                  <form method="post" action="forms.php" class="inline-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="activate">
                    <input type="hidden" name="t" value="<?php echo e($t['public_id']); ?>">
                    <button type="submit" class="btn btn-sm btn-primary">فعال‌سازی</button>
                  </form>
                <?php } ?>
                <?php if ($t['status'] === 'ACTIVE') { ?>
                  <form method="post" action="forms.php" class="inline-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="t" value="<?php echo e($t['public_id']); ?>">
                    <button type="submit" class="btn btn-sm btn-secondary">بایگانی</button>
                  </form>
                <?php } ?>
                <?php if ($t['status'] !== 'DRAFT') { ?>
                  <form method="post" action="forms.php" class="inline-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="version">
                    <input type="hidden" name="t" value="<?php echo e($t['public_id']); ?>">
                    <button type="submit" class="btn btn-sm btn-secondary">نسخهٔ جدید</button>
                  </form>
                <?php } ?>
              </td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
      </div>
    <?php } ?>
  </div>
</div>

<div class="card">
  <div class="card-header">ساخت قالب تازه</div>
  <div class="card-body">
    <form method="post" action="forms.php" class="form-grid">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="create">

      <div class="form-row">
        <label for="title">عنوان قالب <span class="required-star">*</span></label>
        <input type="text" id="title" name="title" maxlength="150"
               value="<?php echo e($form['title']); ?>" required>
      </div>

      <div class="form-row">
        <label for="template_code">شناسهٔ فنی <span class="required-star">*</span></label>
        <input type="text" id="template_code" name="template_code" dir="ltr" maxlength="50"
               value="<?php echo e($form['template_code']); ?>" required>
        <div class="form-hint">
          فقط حرف کوچک انگلیسی، عدد و زیرخط؛ مثل <span class="mono">patient_intake</span>.
          این شناسه هرگز تغییر نمی‌کند و پایهٔ گزارش‌گیری فازهای بعد است.
        </div>
      </div>

      <div class="form-row">
        <label for="description">توضیح کوتاه</label>
        <input type="text" id="description" name="description" maxlength="1000"
               value="<?php echo e($form['description']); ?>">
      </div>

      <div class="form-row">
        <label for="default_assignee_role">پرکنندهٔ پیش‌فرض</label>
        <select id="default_assignee_role" name="default_assignee_role">
          <option value="THERAPIST" <?php echo $form['default_assignee_role'] === 'THERAPIST' ? 'selected' : ''; ?>>درمانگر</option>
          <option value="PATIENT" <?php echo $form['default_assignee_role'] === 'PATIENT' ? 'selected' : ''; ?>>مراجع</option>
        </select>
      </div>

      <div class="form-row">
        <label class="checkbox-label">
          <input type="checkbox" name="admin_can_view" value="1"
            <?php echo $form['admin_can_view'] === '1' ? 'checked' : ''; ?>>
          مدیر بتواند پاسخ‌های این فرم را فقط‌خواندنی ببیند
        </label>
        <div class="form-hint">هر بار مشاهده توسط مدیر در گزارش حسابرسی ثبت می‌شود.</div>
      </div>

      <div class="form-row">
        <label class="checkbox-label">
          <input type="checkbox" name="patient_can_have_draft" value="1"
            <?php echo $form['patient_can_have_draft'] === '1' ? 'checked' : ''; ?>>
          مراجع بتواند پیش‌نویس ذخیره کند
        </label>
        <div class="form-hint">
          پیش‌نویس فقط برای مراجع است و پس از
          <?php echo e(to_persian_digits(FORM_DRAFT_RETENTION_DAYS)); ?> روز پاک می‌شود.
        </div>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">➕ ساخت قالب</button>
      </div>
    </form>
  </div>
</div>

<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
