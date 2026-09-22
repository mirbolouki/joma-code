<?php
/*
 * ماژول «هم‌مسیر» — partial گفتگو (Phase 4)
 * ------------------------------------------------------------------
 * فقط از pages/hammasir.php include می‌شود؛ route عمومی ندارد (D29/D30).
 * متغیرهای مورد انتظار (ست‌شده توسط caller):
 *   $hammasir_link         — ردیف لینک (ACTIVE)
 *   $hammasir_me           — user_id کاربر جاری
 *   $hammasir_other_label  — نام نمایشی مخاطب (رشته؛ هنگام خروجی e() می‌شود)
 * نمایش: آخرین ۱۰۰ پیام (D36) + فرم ارسال (فقط با توگل روشن — D37).
 * با باز شدن گفتگو، پیام‌های مخاطب برای کاربر جاری خوانده‌شده می‌شوند (AC4.8).
 */

// گارد مستقیم — partial هرگز مستقیماً از وب اجرا نمی‌شود → 403 خشک (AC6.3)
if (!defined('JOMA_IN_APP')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

$hammasir_chat_msgs = array();
$hammasir_chat_count = 0;
$hammasir_chat_limit = 0;
// مهار خطای خواندن در PHP 8.1 (D-1): خطا → فهرست خالی
try {
    $hammasir_chat_msgs = hammasir_messages_page((int) $hammasir_link['id']);
    if (!is_array($hammasir_chat_msgs)) $hammasir_chat_msgs = array();
    // باز کردن گفتگو = خواندن پیام‌های من (فقط recipient جاری — AC4.8/AC5.3)
    hammasir_mark_messages_read($hammasir_me);
    // سهمیه‌ی پیام امروز (حکم PO): عدد واقعی از Backend با همان مبنای سقف
    // (link_id + sender_user_id + jalali_date) — هرگز hardcode در UI.
    $hammasir_chat_is_client = ((int) $hammasir_link['client_user_id'] === $hammasir_me);
    $hammasir_chat_limit = $hammasir_chat_is_client ? (int) hammasir_message_daily_limit() : (int) hammasir_companion_daily_limit();
    $hammasir_chat_today = hammasir_jalali_today();
    if ($hammasir_chat_today !== null) {
        $hammasir_chat_n = hammasir_messages_count_today((int) $hammasir_link['id'], $hammasir_me, $hammasir_chat_today);
        $hammasir_chat_count = ($hammasir_chat_n === null) ? 0 : (int) $hammasir_chat_n;
    }
} catch (Throwable $e) {
    $hammasir_chat_msgs = array();
    $hammasir_chat_count = 0;
    $hammasir_chat_limit = 0;
}
$hammasir_chat_full = ($hammasir_chat_limit > 0 && $hammasir_chat_count >= $hammasir_chat_limit);
?>
<style>
/* استایل محلی مینیمال گفتگو (PC-1 — بدون دست زدن به joma.css) */
.hammasir-msgs { display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px; }
.hammasir-msg { border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 8px 12px; display: flex; flex-direction: column; gap: 4px; }
.hammasir-msg small { opacity: 0.7; }
</style>
<section class="card">
  <h2>گفتگو با <?php echo e($hammasir_other_label); ?></h2>
  <?php if (count($hammasir_chat_msgs) === 0) { ?>
    <p>هنوز پیامی رد و بدل نشده است.</p>
  <?php } else { ?>
    <div class="hammasir-msgs">
      <?php foreach ($hammasir_chat_msgs as $hammasir_m) { ?>
        <div class="hammasir-msg">
          <div class="chip"><?php echo e(((int) $hammasir_m['sender_user_id'] === $hammasir_me) ? 'من' : $hammasir_other_label); ?></div>
          <div><?php echo e($hammasir_m['body']); ?></div>
          <small><?php echo e(jalali_format($hammasir_m['jalali_date']) . ' — ' . substr((string) $hammasir_m['created_at'], 11, 5)); ?></small>
        </div>
      <?php } ?>
    </div>
  <?php } ?>
  <?php if ($hammasir_msg_on) { ?>
    <?php if ($hammasir_chat_full) { ?>
      <?php if (((int) $hammasir_link['client_user_id'] === $hammasir_me)) { ?>
        <p>سقف پیام‌های امروز تکمیل شده است. امکان ارسال پیام جدید از فردا فعال می‌شود.</p>
      <?php } else { ?>
        <small>سقف روزانه‌ی پیام شما تکمیل شده است.</small>
      <?php } ?>
    <?php } ?>
    <?php if (((int) $hammasir_link['client_user_id'] === $hammasir_me)) { ?>
      <p>پیام‌های امروز: <?php echo (int) $hammasir_chat_count; ?> از <?php echo (int) $hammasir_chat_limit; ?></p>
    <?php } else { ?>
      <small>پیام‌های امروز: <?php echo (int) $hammasir_chat_count; ?> از <?php echo (int) $hammasir_chat_limit; ?></small>
    <?php } ?>
    <form class="card" method="post">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="hammasir_action" value="message_send">
      <input type="hidden" name="link_id" value="<?php echo (int) $hammasir_link['id']; ?>">
      <label>متن پیام</label>
      <textarea name="body" rows="3" maxlength="2000"<?php if ($hammasir_chat_full) echo ' disabled'; ?>></textarea>
      <div class="btn-row">
        <button class="btn" type="submit"<?php if ($hammasir_chat_full) echo ' disabled'; ?>>ارسال پیام</button>
      </div>
    </form>
  <?php } else { ?>
    <p>دریافت پیام برای این ارتباط فعال نیست.</p>
  <?php } ?>
</section>
