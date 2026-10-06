<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — بدنهٔ مشترک «پروندهٔ اداری مراجع»
 *
 *  انتظار دارد این متغیرها از پیش تعریف شده باشند:
 *    $person       سطر persons
 *    $admissions   آرایه
 *    $appointments آرایه
 *    $forms        آرایه (ممکن است خالی باشد)
 *    $can_edit     bool — آیا فرم ویرایش نمایش داده شود
 *    $back_url     string
 *    $form_error   string|null
 *    $edit_input   آرایهٔ مقادیر فرم ویرایش
 *
 *  این فایل هیچ محتوای بالینی چاپ نمی‌کند: نه یادداشت، نه پاسخ فرم.
 * ═══════════════════════════════════════════════════════════════════ */
$age = case_patient_age($person['birth_date']);
?>

<div class="card">
  <div class="card-header">
    👤 <?php echo e(person_full_name($person)); ?>
    <?php if ($person['status'] !== 'ACTIVE') { ?>
      <span class="badge badge-muted">غیرفعال</span>
    <?php } ?>
  </div>
  <div class="card-body">
    <div class="form-grid">
      <div class="form-row">
        <label>شمارهٔ موبایل</label>
        <div class="mono"><?php echo e(to_persian_digits($person['mobile_number'])); ?></div>
        <p class="form-hint">تغییر شماره از این صفحه ممکن نیست (نیازمند تأیید پیامکی — فاز بعد).</p>
      </div>
      <div class="form-row">
        <label>کد ملی</label>
        <div class="mono"><?php
          echo $person['national_code']
             ? e(to_persian_digits($person['national_code'])) : '—'; ?></div>
      </div>
      <div class="form-row">
        <label>تاریخ تولد</label>
        <div><?php
          $b = patient_directory_birth_input($person['birth_date']);
          echo $b === '' ? '—' : e(to_persian_digits($b));
          if ($age !== null) { echo ' <span class="text-muted">('
              . e(to_persian_digits($age)) . ' ساله)</span>'; } ?></div>
      </div>
      <div class="form-row">
        <label>جنسیت</label>
        <div><?php echo e(case_gender_label($person['gender'])); ?></div>
      </div>
      <div class="form-row">
        <label>شناسهٔ مراجع</label>
        <div class="mono text-small"><?php echo e($person['public_id']); ?></div>
      </div>
      <div class="form-row">
        <label>تاریخ ثبت در سامانه</label>
        <div><?php echo e(jalali_display($person['created_at'], 'date')); ?></div>
      </div>
    </div>
    <div class="form-actions">
      <a class="btn btn-secondary" href="<?php echo e($back_url); ?>">بازگشت به فهرست مراجعان</a>
    </div>
  </div>
</div>

<?php if ($can_edit) { ?>
<div class="card">
  <div class="card-header">✏️ ویرایش اطلاعات هویتی</div>
  <div class="card-body">
    <?php if (!empty($form_error)) { ?>
      <div class="alert alert-danger"><?php echo e($form_error); ?></div>
    <?php } ?>
    <form method="post">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="update_identity">
      <div class="form-grid">
        <div class="form-row">
          <label for="first_name">نام <span class="required-star">*</span></label>
          <input type="text" id="first_name" name="first_name" maxlength="100" required
                 value="<?php echo e($edit_input['first_name']); ?>">
        </div>
        <div class="form-row">
          <label for="last_name">نام خانوادگی <span class="required-star">*</span></label>
          <input type="text" id="last_name" name="last_name" maxlength="100" required
                 value="<?php echo e($edit_input['last_name']); ?>">
        </div>
        <div class="form-row">
          <label for="national_code">کد ملی</label>
          <input type="text" id="national_code" name="national_code" maxlength="10"
                 class="mono" data-digits="en" inputmode="numeric"
                 value="<?php echo e($edit_input['national_code']); ?>">
          <p class="form-hint">اختیاری. ۱۰ رقم، با رقم کنترلی معتبر.</p>
        </div>
        <div class="form-row">
          <label for="birth_date">تاریخ تولد</label>
          <input type="text" id="birth_date" name="birth_date" maxlength="10"
                 class="mono" placeholder="۱۳۷۰/۰۵/۱۲"
                 value="<?php echo e($edit_input['birth_date']); ?>">
          <p class="form-hint">شمسی، به شکل ۱۳۷۰/۰۵/۱۲. خالی بگذارید اگر نمی‌دانید.</p>
        </div>
        <div class="form-row">
          <label for="gender">جنسیت</label>
          <select id="gender" name="gender">
            <option value="">— نامشخص —</option>
            <option value="MALE"<?php
              echo $edit_input['gender'] === 'MALE' ? ' selected' : ''; ?>>مرد</option>
            <option value="FEMALE"<?php
              echo $edit_input['gender'] === 'FEMALE' ? ' selected' : ''; ?>>زن</option>
          </select>
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">💾 ذخیرهٔ تغییرات</button>
      </div>
      <p class="form-hint">هر تغییر در گزارش حسابرسی با نام شما ثبت می‌شود.</p>
    </form>
  </div>
</div>
<?php } ?>

<div class="card">
  <div class="card-header">📋 پذیرش‌ها (<?php echo e(to_persian_digits(count($admissions))); ?>)</div>
  <div class="card-body">
    <?php if (count($admissions) === 0) { ?>
      <div class="empty-state">پذیرشی ثبت نشده است.</div>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead>
            <tr><th>تاریخ</th><th>خدمت</th><th>درمانگر ارجاع</th>
                <th>دلیل مراجعه</th><th>همراه</th><th>وضعیت</th><th>ثبت توسط</th></tr>
          </thead>
          <tbody>
          <?php foreach ($admissions as $a) { ?>
            <tr>
              <td data-label="تاریخ"><?php echo e(jalali_display($a['created_at'], 'datetime')); ?></td>
              <td data-label="خدمت"><?php echo e($a['service_title']); ?></td>
              <td data-label="درمانگر ارجاع"><?php echo e($a['therapist_name']); ?></td>
              <td data-label="دلیل مراجعه"><?php echo e($a['referral_reason'] ? $a['referral_reason'] : '—'); ?></td>
              <td data-label="همراه"><?php echo e(to_persian_digits((int)$a['companion_count'])); ?></td>
              <td data-label="وضعیت">
                <span class="badge <?php echo e(patient_directory_admission_class($a['status'])); ?>">
                  <?php echo e(patient_directory_admission_label($a['status'])); ?>
                </span>
                <?php if ($a['status'] === 'DECLINED' && $a['decline_reason']) { ?>
                  <div class="text-small text-muted"><?php echo e($a['decline_reason']); ?></div>
                <?php } ?>
              </td>
              <td data-label="ثبت توسط"><?php echo e($a['created_by_name']); ?></td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
      </div>
    <?php } ?>
  </div>
</div>

<div class="card">
  <div class="card-header">📅 نوبت‌ها (<?php echo e(to_persian_digits(count($appointments))); ?>)</div>
  <div class="card-body">
    <?php if (count($appointments) === 0) { ?>
      <div class="empty-state">نوبتی ثبت نشده است.</div>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead>
            <tr><th>زمان</th><th>خدمت</th><th>درمانگر</th><th>اتاق</th><th>وضعیت</th></tr>
          </thead>
          <tbody>
          <?php foreach ($appointments as $ap) { ?>
            <tr>
              <td data-label="زمان"><?php echo e(appointment_display($ap['appointment_start_utc'])); ?></td>
              <td data-label="خدمت"><?php echo e($ap['service_title']); ?></td>
              <td data-label="درمانگر"><?php echo e($ap['therapist_name']); ?></td>
              <td data-label="اتاق"><?php echo e($ap['room_title'] ? $ap['room_title'] : '—'); ?></td>
              <td data-label="وضعیت"><?php echo e(appointment_status_label($ap['status'])); ?></td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
      </div>
    <?php } ?>
  </div>
</div>

<?php if (count($forms) > 0) { ?>
<div class="card">
  <div class="card-header">🧾 فرم‌ها — فقط وضعیت</div>
  <div class="card-body">
    <div class="table-wrap">
      <table class="table table-card">
        <thead>
          <tr><th>فرم</th><th>پرکننده</th><th>تخصیص</th><th>وضعیت</th><th>زمان ثبت</th></tr>
        </thead>
        <tbody>
        <?php foreach ($forms as $f) { ?>
          <tr>
            <td data-label="فرم"><?php echo e($f['template_title']); ?>
              <span class="text-muted text-small">نسخهٔ <?php
                echo e(to_persian_digits((int)$f['version'])); ?></span></td>
            <td data-label="پرکننده"><?php echo e($f['assignee_name']); ?>
              <span class="text-muted text-small">(<?php
                echo e(form_assignee_role_label($f['assignee_role'])); ?>)</span></td>
            <td data-label="تخصیص"><?php echo e(jalali_display($f['assigned_at'], 'date')); ?></td>
            <td data-label="وضعیت">
              <span class="badge <?php echo e(form_assignment_status_class($f['status'])); ?>">
                <?php echo e(form_assignment_status_label($f['status'])); ?>
              </span>
            </td>
            <td data-label="زمان ثبت"><?php
              echo $f['submitted_at'] ? e(jalali_display($f['submitted_at'], 'datetime')) : '—'; ?></td>
          </tr>
        <?php } ?>
        </tbody>
      </table>
    </div>
    <p class="form-hint">
      محتوای پاسخ‌ها از این صفحه دیده نمی‌شود. دسترسی به پاسخ‌ها فقط از مسیر
      ویژهٔ خودش ممکن است و هر مشاهده حسابرسی می‌شود.
    </p>
  </div>
</div>
<?php } ?>
