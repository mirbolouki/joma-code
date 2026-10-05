<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۴: فهرست پاسخ‌های فرم برای مدیر (فقط‌خواندنی)
 *
 *  این صفحه محتوای پاسخ را نشان نمی‌دهد؛ فقط فراداده. برای دیدن محتوا
 *  باید وارد صفحهٔ پاسخ شد و همان لحظه حسابرسی ثبت می‌شود.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_ADMIN));

$fatal = null;
$rows = array();
$templates = array();
$selected = isset($_GET['t']) ? trim($_GET['t']) : '';
$selected_id = null;

try {
    if (!phase4_ready($db)) {
        throw new Exception('بخش فرم‌ها هنوز روی این سامانه نصب نشده است.');
    }
    $templates = form_templates_fetch_all($db);
    if ($selected !== '') {
        $t = form_template_find_by_public_id($db, $selected);
        if ($t) {
            $selected_id = (int)$t['id'];
        }
    }
    $rows = form_assignments_for_admin($db, $selected_id, 100);
} catch (Exception $ex) {
    $fatal = $ex->getMessage();
}

$page_title = 'پاسخ‌های فرم';
$active_menu = 'admin_form_responses';
require __DIR__ . '/../templates/header.php';
?>
<h1>📑 پاسخ‌های فرم</h1>

<?php if ($fatal !== null) { ?>
  <div class="alert alert-danger"><?php echo e($fatal); ?></div>
  <a class="btn btn-secondary" href="index.php">بازگشت به داشبورد</a>
<?php } else { ?>

<div class="alert alert-warning">
  دسترسی مدیر به پاسخ‌ها فقط‌خواندنی است و تنها برای قالب‌هایی فعال است که
  گزینهٔ «مدیر ببیند» برایشان روشن است. هر بار باز کردن یک پاسخ، در گزارش
  حسابرسی با نام شما ثبت می‌شود.
</div>

<div class="card">
  <div class="card-header">صافی</div>
  <div class="card-body">
    <form method="get" action="form_responses.php" class="inline-form">
      <select name="t">
        <option value="">— همهٔ قالب‌ها —</option>
        <?php foreach ($templates as $t) { ?>
          <?php if ((int)$t['admin_can_view'] !== 1) { continue; } ?>
          <option value="<?php echo e($t['public_id']); ?>"
            <?php echo ($selected === $t['public_id']) ? 'selected' : ''; ?>>
            <?php echo e($t['title'] . ' — نسخهٔ ' . to_persian_digits((int)$t['version'])); ?>
          </option>
        <?php } ?>
      </select>
      <button type="submit" class="btn btn-sm btn-primary">نمایش</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">پاسخ‌های ثبت‌شده (<?php echo e(to_persian_digits(count($rows))); ?>)</div>
  <div class="card-body">
    <?php if (count($rows) === 0) { ?>
      <p class="empty-state">پاسخی برای نمایش وجود ندارد.</p>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead>
            <tr><th>فرم</th><th>مراجع</th><th>پرکننده</th><th>زمان ثبت</th>
                <th>ویرایش</th><th>وضعیت</th><th></th></tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r) { ?>
            <tr>
              <td data-label="فرم"><?php echo e($r['template_title']); ?>
                <span class="text-muted">(نسخهٔ <?php echo e(to_persian_digits((int)$r['template_version'])); ?>)</span>
              </td>
              <td data-label="مراجع"><?php echo e($r['patient_first_name'] . ' ' . $r['patient_last_name']); ?></td>
              <td data-label="پرکننده"><?php echo e(form_assignee_role_label($r['assignee_role'])); ?></td>
              <td data-label="زمان ثبت"><?php echo e(jalali_display($r['submitted_at'])); ?></td>
              <td data-label="ویرایش"><?php echo e(to_persian_digits((int)$r['edit_count'])); ?></td>
              <td data-label="وضعیت">
                <span class="badge <?php echo e(form_assignment_status_class($r['status'])); ?>">
                  <?php echo e(form_assignment_status_label($r['status'])); ?>
                </span>
              </td>
              <td data-label="اقدام">
                <a class="btn btn-sm btn-secondary"
                   href="form_response_view.php?a=<?php echo e($r['public_id']); ?>">مشاهدهٔ پاسخ</a>
              </td>
            </tr>
          <?php } ?>
          </tbody>
        </table>
      </div>
    <?php } ?>
  </div>
</div>

<?php } ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
