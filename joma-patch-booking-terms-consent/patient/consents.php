<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — سابقهٔ فقط‌خواندنیِ ضوابط پذیرفته‌شده در پورتال مراجع
 *  جست‌وجو فقط با شناسهٔ شخصِ نشستِ همین حساب انجام می‌شود.
 * ═══════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/bootstrap.php';

$db = $GLOBALS['db'];
$me = patient_require_portal_session($db);
$ready = booking_terms_ready($db) && booking_terms_document_is_approved();
$acceptances = array();

if ($ready) {
    try {
        $acceptances = booking_terms_acceptances_for_person($db, (int)$me);
    } catch (Exception $ex) {
        $ref = log_system_error('PATIENT_BOOKING_TERMS_FETCH', $ex);
        render_error_page($ref);
    }
}

$page_title = 'ضوابط پذیرفته‌شده';
$active_menu = 'patient_consents';
require __DIR__ . '/../templates/header.php';
?>
<h1>📜 ضوابط پذیرفته‌شده</h1>

<?php if (!$ready) { ?>
  <div class="alert alert-info">این بخش هنوز فعال نشده است.</div>
<?php } else { ?>
  <div class="alert alert-info">
    این صفحه سابقهٔ فنیِ نسخهٔ متن، گزینه‌های انتخاب‌شده و تأیید شمارهٔ همراه را نشان می‌دهد.
    کد خام پیامک ذخیره نشده است. این ثبت به‌تنهایی اثبات نمی‌کند که متن واقعاً خوانده شده یا هویت حقوقی دارندهٔ تلفن احراز شده است.
    فرم اولیه نیز فقط بازهٔ زمانی ترجیحی می‌گیرد و پذیرش مبلغ مشخص یا وقت قطعی نیست.
  </div>

  <?php if (count($acceptances) === 0) { ?>
    <div class="card"><div class="card-body">
      <p class="empty-state">هنوز سابقهٔ ضوابطِ متصل به پذیرشِ پروندهٔ شما ثبت نشده است.</p>
    </div></div>
  <?php } else { ?>
    <div class="item-list">
      <?php foreach ($acceptances as $acceptance) { ?>
        <article class="card">
          <div class="card-header">
            نسخهٔ <?php echo e($acceptance['terms_version']); ?>
            <span class="badge badge-success">ثبت‌شده</span>
          </div>
          <div class="card-body">
            <div class="item-list">
              <div class="item-card">
                <span class="text-muted text-small">زمان ثبت (به وقت تهران)</span>
                <div><?php echo e(jalali_display($acceptance['accepted_at'], 'datetime')); ?></div>
              </div>
              <div class="item-card">
                <span class="text-muted text-small">شمارهٔ تأییدشده</span>
                <div class="mono" dir="ltr"><?php echo e(to_persian_digits($acceptance['accepted_mobile'])); ?></div>
              </div>
              <div class="item-card">
                <span class="text-muted text-small">شناسهٔ درخواست</span>
                <div class="mono" dir="ltr"><?php echo e($acceptance['booking_request_public_id']); ?></div>
              </div>
              <?php if (!empty($acceptance['requested_service_label'])) { ?>
                <div class="item-card">
                  <span class="text-muted text-small">خدمت درخواستی</span>
                  <div><?php echo e($acceptance['requested_service_label']); ?></div>
                </div>
              <?php } ?>
            </div>

            <h3>گزینه‌های ثبت‌شده</h3>
            <ul class="hint-list">
              <?php foreach (booking_terms_ack_labels() as $ack_key => $ack_label) { ?>
                <li><?php echo !empty($acceptance[$ack_key]) ? '✅ ' : '⬜ '; ?><?php echo e($ack_label); ?></li>
              <?php } ?>
            </ul>

            <p class="text-small">
              اثرانگشت SHA-256 متن:
              <span class="mono" dir="ltr" style="word-break:break-all;"><?php echo e($acceptance['terms_sha256']); ?></span>
            </p>
            <details>
              <summary>نمایش متن دقیقِ ثبت‌شده</summary>
              <div style="white-space:pre-wrap;border:1px solid #d7dce2;border-radius:8px;padding:14px;margin-top:10px;line-height:1.9;"><?php echo e($acceptance['terms_snapshot']); ?></div>
            </details>
          </div>
        </article>
      <?php } ?>
    </div>
    <?php if (count($acceptances) >= 100) { ?>
      <p class="form-hint">در این صفحه حداکثر ۱۰۰ سابقهٔ تازه‌تر نمایش داده می‌شود.</p>
    <?php } ?>
  <?php } ?>
<?php } ?>

<?php require __DIR__ . '/../templates/footer.php'; ?>
