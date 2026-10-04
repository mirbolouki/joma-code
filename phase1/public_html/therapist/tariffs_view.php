<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما فاز ۲ — مشاهدهٔ تعرفهٔ خودم (درمانگر، فقط خواندنی)
 *
 *  تعرفه را «مدیر» تعیین می‌کند. این صفحه هیچ امکان ویرایشی ندارد و
 *  فقط تعرفهٔ کاربر واردشده را نشان می‌دهد (شناسه از نشست خوانده می‌شود).
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
auth_require_active_session($db);
require_role(array(ROLE_THERAPIST));

if (!phase2_ready($db)) {
    redirect(APP_BASE_URL . '/therapist/calendar.php');
}

$me = (int)$_SESSION['person_id'];

try {
    $rows = tariffs_fetch_for_therapist($db, $me);
} catch (Exception $ex) {
    $ref = log_system_error('THERAPIST_TARIFF_VIEW', $ex);
    render_error_page($ref);
}

$page_title = 'تعرفهٔ من';
$active_menu = 'therapist_tariffs';
require __DIR__ . '/../templates/header.php';
?>
<h1>💰 تعرفهٔ من</h1>

<div class="alert alert-info">
  این صفحه فقط برای اطلاع شماست. تعیین و تغییر تعرفه بر عهدهٔ «مدیر» کلینیک است.
  مبلغ هر نوبت در لحظهٔ ثبت روی همان نوبت تثبیت می‌شود.
</div>

<div class="card">
  <div class="card-header">خدمات و مبالغ</div>
  <div class="card-body">
    <?php if (count($rows) === 0) { ?>
      <p class="empty-state">خدمتی تعریف نشده است.</p>
    <?php } else { ?>
      <div class="table-wrap">
        <table class="table table-card">
          <thead><tr><th>خدمت</th><th>مدت</th><th>مبلغ هر جلسه</th><th>وضعیت</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r) { ?>
            <tr>
              <td data-label="خدمت"><?php echo e($r['service_title']); ?></td>
              <td data-label="مدت"><?php echo to_persian_digits($r['default_duration_minutes']); ?> دقیقه</td>
              <td data-label="مبلغ">
                <?php echo ($r['tariff_id'] !== null && (int)$r['is_active'] === 1)
                      ? tariff_rial_to_toman_display($r['price_per_session']) : '—'; ?>
              </td>
              <td data-label="وضعیت">
                <?php if ($r['tariff_id'] === null) { ?>
                  <span class="badge badge-muted">تعیین نشده</span>
                <?php } else { ?>
                  <span class="badge <?php echo (int)$r['is_active'] === 1 ? 'badge-success' : 'badge-muted'; ?>">
                    <?php echo (int)$r['is_active'] === 1 ? 'فعال' : 'غیرفعال'; ?></span>
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
<?php require __DIR__ . '/../templates/footer.php'; ?>
