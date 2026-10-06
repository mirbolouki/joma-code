<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۴: فرم‌ساز (مدیر)
 *  افزودن/ویرایش/جابه‌جایی پرسش‌ها + تنظیمات قالب + پیش‌نمایش
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

$me = (int)$_SESSION['person_id'];
$my_role = $_SESSION['active_role_code'];

$template_public_id = isset($_GET['t']) ? trim($_GET['t']) : '';
if ($template_public_id === '' && isset($_POST['t'])) {
    $template_public_id = trim($_POST['t']);
}

$fatal = null;
$error = null;
$template = null;
$fields = array();
$edit_field = null;

$new_field = array(
    'field_code' => '',
    'label' => '',
    'help_text' => '',
    'field_type' => 'SINGLE_CHOICE',
    'is_required' => '1',
    'options_raw' => '',
    'min_value' => '',
    'max_value' => '',
    'max_length' => '',
);

try {
    if (!phase4_ready($db)) {
        throw new Exception('بخش فرم‌ها هنوز روی این سامانه نصب نشده است.');
    }
    $template = form_template_find_by_public_id($db, $template_public_id);
    if (!$template) {
        throw new Exception('قالب یافت نشد.');
    }
} catch (Exception $ex) {
    $fatal = $ex->getMessage();
}

if ($fatal === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    try {
        if ($action === 'settings') {
            $data = array(
                'title' => isset($_POST['title']) ? trim((string)$_POST['title']) : '',
                'description' => isset($_POST['description']) ? trim((string)$_POST['description']) : '',
                'default_assignee_role' => (isset($_POST['default_assignee_role'])
                    && $_POST['default_assignee_role'] === 'PATIENT') ? 'PATIENT' : 'THERAPIST',
                'admin_can_view' => isset($_POST['admin_can_view']) ? 1 : 0,
                'patient_can_have_draft' => isset($_POST['patient_can_have_draft']) ? 1 : 0,
            );
            form_template_update($db, $template, $data, $me, $my_role);
            flash_set('form_success', 'تنظیمات قالب به‌روز شد.');
            redirect('form_builder.php?t=' . rawurlencode($template['public_id']));

        } elseif ($action === 'field_add' || $action === 'field_update') {
            foreach (array_keys($new_field) as $k) {
                $new_field[$k] = isset($_POST[$k]) ? (string)$_POST[$k] : '';
            }
            $new_field['is_required'] = isset($_POST['is_required']) ? '1' : '0';
            $data = $new_field;
            $data['is_required'] = (int)$new_field['is_required'];

            if ($action === 'field_add') {
                $ack = isset($_POST['free_text_ack']);
                form_field_add($db, $template, $data, $ack, $me, $my_role);
                flash_set('form_success', 'پرسش اضافه شد.');
            } else {
                $field = form_field_find($db, (int)$template['id'],
                    isset($_POST['f']) ? $_POST['f'] : '');
                if (!$field) {
                    throw new Exception('پرسش یافت نشد.');
                }
                form_field_update($db, $template, $field, $data, $me, $my_role);
                flash_set('form_success', 'پرسش به‌روز شد.');
            }
            redirect('form_builder.php?t=' . rawurlencode($template['public_id']));

        } elseif ($action === 'field_remove' || $action === 'field_up' || $action === 'field_down') {
            $field = form_field_find($db, (int)$template['id'],
                isset($_POST['f']) ? $_POST['f'] : '');
            if (!$field) {
                throw new Exception('پرسش یافت نشد.');
            }
            if ($action === 'field_remove') {
                form_field_deactivate($db, $template, $field, $me, $my_role);
                flash_set('form_success', 'پرسش از قالب برداشته شد.');
            } else {
                form_field_move($db, $template, $field,
                    ($action === 'field_up' ? 'up' : 'down'), $me, $my_role);
            }
            redirect('form_builder.php?t=' . rawurlencode($template['public_id']));
        }
    } catch (Exception $ex) {
        $error = $ex->getMessage();
    }
}

if ($fatal === null) {
    try {
        $template = form_template_find_by_public_id($db, $template_public_id);
        $fields = form_fields_fetch($db, (int)$template['id']);
        if (isset($_GET['edit'])) {
            $edit_field = form_field_find($db, (int)$template['id'], trim($_GET['edit']));
        }
    } catch (Exception $ex) {
        $fatal = $ex->getMessage();
    }
}

$flash_success = flash_get('form_success');
$locked = ($template !== null) ? form_template_is_locked($template) : false;
$editable = ($template !== null && !$locked && $template['status'] !== 'ARCHIVED');

$page_title = 'فرم‌ساز';
$active_menu = 'admin_forms';
require __DIR__ . '/../templates/header.php';
?>
<h1>🧰 فرم‌ساز</h1>

<?php if ($fatal !== null) { ?>
  <div class="alert alert-danger"><?php echo e($fatal); ?></div>
  <a class="btn btn-secondary" href="forms.php">بازگشت به فهرست قالب‌ها</a>
<?php } else { ?>

<?php if ($flash_success) { ?>
  <div class="alert alert-success"><?php echo e($flash_success); ?></div>
<?php } ?>
<?php if ($error !== null) { ?>
  <div class="alert alert-danger"><?php echo e($error); ?></div>
<?php } ?>

<div class="card">
  <div class="card-header">
    <?php echo e($template['title']); ?>
    — <span class="mono"><?php echo e($template['template_code']); ?></span>
    — نسخهٔ <?php echo e(to_persian_digits((int)$template['version'])); ?>
    <span class="badge <?php
      echo $template['status'] === 'ACTIVE' ? 'badge-success'
         : ($template['status'] === 'DRAFT' ? 'badge-warning' : 'badge-muted'); ?>">
      <?php echo e(form_template_status_label($template['status'])); ?>
    </span>
  </div>
  <div class="card-body">
    <?php if ($locked) { ?>
      <div class="alert alert-warning">
        🔒 این قالب پاسخ ثبت‌شده دارد و پرسش‌هایش قفل است.
        برای تغییر، از صفحهٔ فهرست قالب‌ها «نسخهٔ جدید» بسازید.
      </div>
    <?php } elseif ($template['status'] === 'ARCHIVED') { ?>
      <div class="alert alert-warning">این قالب بایگانی شده و ویرایش نمی‌شود.</div>
    <?php } ?>
    <a class="btn btn-secondary" href="forms.php">بازگشت به فهرست قالب‌ها</a>
    <?php if (count($fields) > 0) { ?>
      <a class="btn btn-secondary" href="#preview">👁 دیدن پیش‌نمایش فرم</a>
    <?php } ?>
  </div>
</div>

<div class="card">
  <div class="card-header">پرسش‌ها (<?php echo e(to_persian_digits(count($fields))); ?>
    از <?php echo e(to_persian_digits(FORM_MAX_FIELDS)); ?>)</div>
  <div class="card-body">
    <?php if (count($fields) === 0) { ?>
      <p class="empty-state">هنوز پرسشی اضافه نشده است.</p>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead>
            <tr><th>#</th><th>پرسش</th><th>شناسه</th><th>نوع</th><th>الزامی</th><th>ترتیب</th><th></th></tr>
          </thead>
          <tbody>
          <?php $i = 1; foreach ($fields as $f) { ?>
            <tr>
              <td data-label="#"><?php echo e(to_persian_digits($i)); ?></td>
              <td data-label="پرسش"><?php echo e($f['label']); ?></td>
              <td data-label="شناسه" class="mono"><?php echo e($f['field_code']); ?></td>
              <td data-label="نوع"><?php echo e(form_field_type_label($f['field_type'])); ?></td>
              <td data-label="الزامی"><?php echo ((int)$f['is_required'] === 1) ? 'بله' : 'خیر'; ?></td>
              <td data-label="ترتیب">
                <?php if ($editable) { ?>
                  <form method="post" action="form_builder.php" class="inline-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="t" value="<?php echo e($template['public_id']); ?>">
                    <input type="hidden" name="f" value="<?php echo e($f['public_id']); ?>">
                    <input type="hidden" name="action" value="field_up">
                    <button type="submit" class="btn btn-sm btn-secondary" title="بالاتر">▲</button>
                  </form>
                  <form method="post" action="form_builder.php" class="inline-form">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="t" value="<?php echo e($template['public_id']); ?>">
                    <input type="hidden" name="f" value="<?php echo e($f['public_id']); ?>">
                    <input type="hidden" name="action" value="field_down">
                    <button type="submit" class="btn btn-sm btn-secondary" title="پایین‌تر">▼</button>
                  </form>
                <?php } else { echo '—'; } ?>
              </td>
              <td data-label="اقدام">
                <?php if ($editable) { ?>
                  <a class="btn btn-sm btn-secondary"
                     href="form_builder.php?t=<?php echo e($template['public_id']); ?>&amp;edit=<?php echo e($f['public_id']); ?>">ویرایش</a>
                  <form method="post" action="form_builder.php" class="inline-form"
                        data-confirm="این پرسش از قالب برداشته شود؟">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="t" value="<?php echo e($template['public_id']); ?>">
                    <input type="hidden" name="f" value="<?php echo e($f['public_id']); ?>">
                    <input type="hidden" name="action" value="field_remove">
                    <button type="submit" class="btn btn-sm btn-danger">برداشتن</button>
                  </form>
                <?php } ?>
              </td>
            </tr>
          <?php $i++; } ?>
          </tbody>
        </table>
      </div>
    <?php } ?>
  </div>
</div>

<?php if ($editable) {
    $is_edit = ($edit_field !== null);
    $v = $new_field;
    if ($is_edit) {
        $v = array(
            'field_code' => $edit_field['field_code'],
            'label' => $edit_field['label'],
            'help_text' => (string)$edit_field['help_text'],
            'field_type' => $edit_field['field_type'],
            'is_required' => ((int)$edit_field['is_required'] === 1) ? '1' : '0',
            'options_raw' => form_options_to_textarea(form_options_decode($edit_field['options_text'])),
            'min_value' => ($edit_field['min_value'] === null) ? '' : form_number_display($edit_field['min_value']),
            'max_value' => ($edit_field['max_value'] === null) ? '' : form_number_display($edit_field['max_value']),
            'max_length' => ($edit_field['max_length'] === null) ? '' : (string)(int)$edit_field['max_length'],
        );
    }
?>
<div class="card">
  <div class="card-header"><?php echo $is_edit ? 'ویرایش پرسش' : 'افزودن پرسش'; ?></div>
  <div class="card-body">
    <form method="post" action="form_builder.php" class="form-grid">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="t" value="<?php echo e($template['public_id']); ?>">
      <input type="hidden" name="action" value="<?php echo $is_edit ? 'field_update' : 'field_add'; ?>">
      <?php if ($is_edit) { ?>
        <input type="hidden" name="f" value="<?php echo e($edit_field['public_id']); ?>">
        <input type="hidden" name="field_code" value="<?php echo e($v['field_code']); ?>">
      <?php } ?>

      <div class="form-row">
        <label for="label">متن پرسش <span class="required-star">*</span></label>
        <input type="text" id="label" name="label" maxlength="300"
               value="<?php echo e($v['label']); ?>" required>
      </div>

      <div class="form-row">
        <label for="help_text">راهنمای زیر پرسش</label>
        <input type="text" id="help_text" name="help_text" maxlength="500"
               value="<?php echo e($v['help_text']); ?>">
      </div>

      <?php if (!$is_edit) { ?>
        <div class="form-row">
          <label for="field_code">شناسهٔ فنی پرسش</label>
          <input type="text" id="field_code" name="field_code" dir="ltr" maxlength="50"
                 value="<?php echo e($v['field_code']); ?>">
          <div class="form-hint">
            خالی بگذارید تا خودکار ساخته شود. این شناسه پس از ساخت تغییر نمی‌کند
            و پایهٔ گزارش‌گیری است.
          </div>
        </div>
        <div class="form-row">
          <label for="field_type">نوع پرسش <span class="required-star">*</span></label>
          <select id="field_type" name="field_type">
            <?php foreach (form_field_types() as $code => $label) { ?>
              <option value="<?php echo e($code); ?>"
                <?php echo ($v['field_type'] === $code) ? 'selected' : ''; ?>>
                <?php echo e($label); ?></option>
            <?php } ?>
          </select>
          <div class="form-hint">نوع پرسش پس از ساخت قابل تغییر نیست.</div>
        </div>
      <?php } else { ?>
        <input type="hidden" name="field_type" value="<?php echo e($v['field_type']); ?>">
        <div class="form-row">
          <label>نوع پرسش</label>
          <p class="text-muted"><?php echo e(form_field_type_label($v['field_type'])); ?>
            (پس از ساخت تغییر نمی‌کند)</p>
        </div>
      <?php } ?>

      <div class="form-row">
        <label class="checkbox-label">
          <input type="checkbox" name="is_required" value="1"
            <?php echo ($v['is_required'] === '1') ? 'checked' : ''; ?>>
          پاسخ به این پرسش الزامی است
        </label>
      </div>

      <div class="form-row">
        <label for="options_raw">گزینه‌ها (برای تک‌انتخابی و چندانتخابی)</label>
        <textarea id="options_raw" name="options_raw" rows="5"
                  placeholder="هر گزینه در یک خط"><?php echo e($v['options_raw']); ?></textarea>
        <div class="form-hint">
          هر خط یک گزینه. اگر گزینه‌ای را حذف و دوباره بنویسید، همان کد قبلی برایش
          نگه داشته می‌شود تا پاسخ‌های قدیمی معنادار بمانند.
        </div>
      </div>

      <div class="form-row">
        <label for="min_value">کمینه (عدد / مقیاس)</label>
        <input type="text" id="min_value" name="min_value" dir="ltr" data-digits="en"
               value="<?php echo e($v['min_value']); ?>">
      </div>
      <div class="form-row">
        <label for="max_value">بیشینه (عدد / مقیاس)</label>
        <input type="text" id="max_value" name="max_value" dir="ltr" data-digits="en"
               value="<?php echo e($v['max_value']); ?>">
      </div>
      <div class="form-row">
        <label for="max_length">حداکثر نویسه (متن توضیحی)</label>
        <input type="text" id="max_length" name="max_length" dir="ltr" data-digits="en"
               value="<?php echo e($v['max_length']); ?>">
        <div class="form-hint">پیش‌فرض <?php echo e(to_persian_digits(FORM_TEXT_DEFAULT_MAX)); ?>
          نویسه، سقف <?php echo e(to_persian_digits(FORM_TEXT_ABSOLUTE_MAX)); ?> نویسه.</div>
      </div>

      <?php if (!$is_edit) { ?>
        <div class="form-row">
          <div class="alert alert-warning">
            طبق ADR-007 متن آزاد در فرم‌های بالینی پیش‌فرض نیست؛ داده‌ای که ساختار ندارد
            بعداً گزارش‌پذیر نیست. اگر نوع «متن توضیحی» را انتخاب کرده‌اید، تیک زیر الزامی است.
          </div>
          <label class="checkbox-label">
            <input type="checkbox" name="free_text_ack" value="1">
            می‌دانم و می‌پذیرم که این پرسش، متن آزاد است
          </label>
        </div>
      <?php } ?>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">
          <?php echo $is_edit ? '💾 ذخیرهٔ پرسش' : '➕ افزودن پرسش'; ?>
        </button>
        <?php if ($is_edit) { ?>
          <a class="btn btn-secondary"
             href="form_builder.php?t=<?php echo e($template['public_id']); ?>">انصراف</a>
        <?php } ?>
      </div>
    </form>
  </div>
</div>
<?php } ?>

<div class="card">
  <div class="card-header">تنظیمات قالب</div>
  <div class="card-body">
    <form method="post" action="form_builder.php" class="form-grid">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="t" value="<?php echo e($template['public_id']); ?>">
      <input type="hidden" name="action" value="settings">

      <div class="form-row">
        <label for="t_title">عنوان <span class="required-star">*</span></label>
        <input type="text" id="t_title" name="title" maxlength="150"
               value="<?php echo e($template['title']); ?>" required>
      </div>
      <div class="form-row">
        <label for="t_description">توضیح</label>
        <input type="text" id="t_description" name="description" maxlength="1000"
               value="<?php echo e((string)$template['description']); ?>">
      </div>
      <div class="form-row">
        <label for="t_role">پرکنندهٔ پیش‌فرض</label>
        <select id="t_role" name="default_assignee_role">
          <option value="THERAPIST" <?php echo $template['default_assignee_role'] === 'THERAPIST' ? 'selected' : ''; ?>>درمانگر</option>
          <option value="PATIENT" <?php echo $template['default_assignee_role'] === 'PATIENT' ? 'selected' : ''; ?>>مراجع</option>
        </select>
      </div>
      <div class="form-row">
        <label class="checkbox-label">
          <input type="checkbox" name="admin_can_view" value="1"
            <?php echo ((int)$template['admin_can_view'] === 1) ? 'checked' : ''; ?>>
          مدیر بتواند پاسخ‌ها را فقط‌خواندنی ببیند (هر مشاهده حسابرسی می‌شود)
        </label>
      </div>
      <div class="form-row">
        <label class="checkbox-label">
          <input type="checkbox" name="patient_can_have_draft" value="1"
            <?php echo ((int)$template['patient_can_have_draft'] === 1) ? 'checked' : ''; ?>>
          مراجع بتواند پیش‌نویس ذخیره کند
        </label>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">💾 ذخیرهٔ تنظیمات</button>
      </div>
    </form>
  </div>
</div>

<?php if (count($fields) > 0) { ?>
<div class="card" id="preview">
  <div class="card-header">👁 پیش‌نمایش فرم</div>
  <div class="card-body">
    <div class="form-grid form-preview">
      <?php form_render_fields($fields, array(), array()); ?>
    </div>
    <p class="form-hint">
      این دقیقاً همان چیزی است که پرکنندهٔ فرم می‌بیند. پیش‌نمایش ذخیره نمی‌شود
      و هر وقت بخواهید از فهرست قالب‌ها با دکمهٔ «پیش‌نمایش» دوباره در دسترس است.
    </p>
  </div>
</div>
<?php } ?>

<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
