<?php
/*
 * ماژول «هم‌مسیر» — partial جریان مراجع (Phase 2/4)
 * ------------------------------------------------------------------
 * فقط از pages/hammasir.php include می‌شود؛ route عمومی ندارد (D29/D30).
 * متغیر مورد انتظار: $hammasir_me (user_id کاربر جاری)
 *
 * بدون لینک باز: سؤال رسمی + فهرست همراهان ACTIVE + فرم رضایت (متن مصوب)
 * با لینک باز: وضعیت + ویرایش مجوزهای مشاهده (فقط مراجع — D19) +
 *   توگل واحد پیام (D37) + گفتگو + لغو/قطع (D20)
 */

// گارد مستقیم — partial هرگز مستقیماً از وب اجرا نمی‌شود → 403 خشک (AC6.3)
if (!defined('JOMA_IN_APP')) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

// خواندها با مهار خطای PHP 8.1 (D-1): خطا → حالت خالی/امن
$hammasir_c_open = null;
$hammasir_c_pick = array();
$hammasir_c_prov = null;
$hammasir_c_perms = null;
$hammasir_c_msg_on = false;
// مدل state واحد Onboarding (حکم PO — بخش ۶)؛ پیش‌فرض امن fail-closed:
// seen=1 و intent=0 → هیچ کارت دعوت/فرمی خودکار رندر نمی‌شود
$hammasir_c_state = array('seen' => 1, 'intent' => 0);
$hammasir_c_latest = null;   // آخرین لینک با هر وضعیت (بخش ۴)
try {
    $hammasir_c_open = hammasir_link_open_by_client($hammasir_me);
    $hammasir_c_pick = hammasir_provider_list(true);
    if (!is_array($hammasir_c_pick)) $hammasir_c_pick = array();
    if (!$hammasir_c_open) {
        $hammasir_c_state = hammasir_onboarding_state($hammasir_me);
        $hammasir_c_latest = hammasir_latest_link_by_client($hammasir_me);
    }
} catch (Throwable $e) {
    $hammasir_c_open = null;
    $hammasir_c_pick = array();
    $hammasir_c_state = array('seen' => 1, 'intent' => 0);
    $hammasir_c_latest = null;
}

if (!$hammasir_c_open) {
    // ----- رندر بدون لینک باز — ترتیب دقیق حکم PO (بخش ۶)؛ هیچ شرط دیگری فرم را باز نمی‌کند -----
    if ($hammasir_c_latest && $hammasir_c_state['intent'] === 0) {
        // قاعده ۲: لینک بسته + intent=0 → فقط وضعیت قبلی + دکمه‌ی آگاهانه‌ی «درخواست همراهی جدید»
        $hammasir_c_sl = hammasir_link_status_labels();
        $hammasir_c_status = isset($hammasir_c_sl[$hammasir_c_latest['status']]) ? $hammasir_c_sl[$hammasir_c_latest['status']] : $hammasir_c_latest['status'];
        $hammasir_c_lprov = hammasir_provider_by_user_id((int) $hammasir_c_latest['provider_user_id']);
        $hammasir_c_ltitle = $hammasir_c_lprov ? $hammasir_c_lprov['title'] : ('همراه #' . (int) $hammasir_c_latest['provider_user_id']);
        ?>
        <section class="card">
          <h2>همراه شما در این مسیر</h2>
          <div class="btn-row">
            <span class="chip"><?php echo e($hammasir_c_ltitle); ?></span>
            <span class="chip"><?php echo e($hammasir_c_status); ?></span>
          </div>
          <?php if ($hammasir_c_latest['status'] === 'DECLINED') { ?>
            <p>درخواست شما از سوی همراه رد شده است.</p>
          <?php } else { ?>
            <p>این ارتباط قبلاً قطع شده است.</p>
          <?php } ?>
          <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="hammasir_action" value="client_request_new">
            <div class="btn-row">
              <button class="btn sec" type="submit">درخواست همراهی جدید</button>
            </div>
          </form>
        </section>
        <?php
    } elseif ($hammasir_c_state['seen'] === 0 && !$hammasir_c_latest) {
        // قاعده ۳: دعوت یک‌باره (Onboarding) — فقط کاربرِ بدون هیچ لینک و دیده‌نشده (بخش ۴)
        ?>
        <section class="card">
          <h2>آیا مایلید در این مسیر یک همراه داشته باشید؟</h2>
          <p><?php echo e(hammasir_onboarding_text()); ?></p>
          <div class="btn-row">
            <form method="post">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="hammasir_action" value="onboarding_accept">
              <button class="btn" type="submit">بله، انتخاب می‌کنم</button>
            </form>
            <form method="post">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="hammasir_action" value="onboarding_dismiss">
              <button class="btn sec" type="submit">فعلاً نه</button>
            </form>
          </div>
        </section>
        <?php
    } elseif ($hammasir_c_state['intent'] === 1) {
        // قاعده ۴: فرم انتخاب + رضایت — فقط با intent=1 (پذیرش دعوت یا «انتخاب همراه» یا «درخواست جدید»)
        $hammasir_c_labels = hammasir_perm_view_labels();
        ?>
        <section class="card">
          <h2>همراه شما در این مسیر کیست؟</h2>
          <?php if (count($hammasir_c_pick) === 0) { ?>
            <p>فعلاً همراهی برای انتخاب ثبت نشده است.</p>
          <?php } else { ?>
          <form class="card" method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="hammasir_action" value="link_request">
            <label>انتخاب همراه</label>
            <!-- حکم PO (C): فهرست همراهان ACTIVE فقط با «عنوان همراه» — بدون ایمیل/موبایل/نام کاربری؛
                 select بومی مرورگر → فرم بدون JS هم کار می‌کند (PC-1) -->
            <select name="provider_user_id" required>
              <option value="" disabled selected>انتخاب کنید</option>
              <?php foreach ($hammasir_c_pick as $hammasir_c_p) { ?>
                <?php if ((int) $hammasir_c_p['user_id'] === $hammasir_me) continue; // self-link ممنوع (D20) ?>
                <option value="<?php echo (int) $hammasir_c_p['user_id']; ?>"><?php echo e($hammasir_c_p['title']); ?></option>
              <?php } ?>
            </select>
            <p>با ارسال این درخواست، همراه انتخاب‌شده می‌تواند مواردی را که در زیر اجازه می‌دهید مشاهده کند. شما هر زمان بخواهید می‌توانید دسترسی را کاهش دهید یا ارتباط را قطع کنید.</p>
            <label>اجازه‌ی مشاهده</label>
            <div><label><input type="checkbox" checked disabled> <?php echo e($hammasir_c_labels['VIEW_SUMMARY']); ?> (پایه — همیشه فعال)</label></div>
            <div><label><input type="checkbox" name="perm_VIEW_PROGRESS" value="1" checked> <?php echo e($hammasir_c_labels['VIEW_PROGRESS']); ?></label></div>
            <div><label><input type="checkbox" name="perm_VIEW_ACTIVITY_DETAILS" value="1"> <?php echo e($hammasir_c_labels['VIEW_ACTIVITY_DETAILS']); ?></label></div>
            <div>
              <label><input type="checkbox" name="perm_VIEW_MOOD" value="1"> <?php echo e($hammasir_c_labels['VIEW_MOOD']); ?></label>
              <small>داده‌ی حساس است؛ فقط با انتخاب آگاهانه‌ی شما فعال می‌شود و هر زمان می‌توانید غیرفعالش کنید.</small>
            </div>
            <div class="btn-row">
              <button class="btn" type="submit">ارسال درخواست</button>
            </div>
          </form>
          <?php } ?>
          <?php if ($hammasir_c_latest) { ?>
          <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="hammasir_action" value="client_request_cancel">
            <div class="btn-row">
              <button class="btn sec" type="submit">انصراف</button>
            </div>
          </form>
          <?php } ?>
        </section>
        <?php
    } else {
        // قاعده ۵: کارت آرام — «فعلاً همراهی انتخاب نکرده‌اید.» + دکمه‌ی آگاهانه‌ی «انتخاب همراه»
        ?>
        <section class="card">
          <h2>همراه شما در این مسیر کیست؟</h2>
          <p>فعلاً همراهی انتخاب نکرده‌اید.</p>
          <form method="post">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="hammasir_action" value="onboarding_reopen">
            <div class="btn-row">
              <button class="btn sec" type="submit">انتخاب همراه</button>
            </div>
          </form>
        </section>
        <?php
    }
} else {
    // ----- حالت ۲: لینک باز — وضعیت + مجوزها + پیام + اقدام‌ها -----
    $hammasir_c_sl = hammasir_link_status_labels(); // برچسب وضعیت از یک نقطه (D4)
    $hammasir_c_status = isset($hammasir_c_sl[$hammasir_c_open['status']]) ? $hammasir_c_sl[$hammasir_c_open['status']] : $hammasir_c_open['status'];
    $hammasir_c_title = $hammasir_c_prov ? $hammasir_c_prov['title'] : ('همراه #' . (int) $hammasir_c_open['provider_user_id']);
    $hammasir_c_labels = hammasir_perm_view_labels();
    ?>
    <section class="card">
      <h2>همراه شما در این مسیر</h2>
      <div class="btn-row">
        <span class="chip"><?php echo e($hammasir_c_title); ?></span>
        <span class="chip"><?php echo e($hammasir_c_status); ?></span>
      </div>
      <?php if ($hammasir_c_open['status'] === 'PENDING') { ?>
        <p>تا قبل از پذیرش همراه، هیچ اطلاعاتی از شما برای او نمایش داده نمی‌شود.</p>
        <form class="card" method="post">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="hammasir_action" value="link_cancel">
          <input type="hidden" name="link_id" value="<?php echo (int) $hammasir_c_open['id']; ?>">
          <div class="btn-row">
            <button class="btn sec" type="submit">لغو درخواست</button>
          </div>
        </form>
      <?php } else {
      // حالت ACTIVE (حکم PO — پنج-۷): کارت وضعیت + ویرایش مجوزها + تب گفتگو (جدا) + قطع ارتباط؛
      // توگل پیام‌رسانی فقط دست همراه است (D37) — از سمت مراجع حذف شد.
      ?>
      <details>
        <summary>ویرایش مجوزها</summary>
        <form class="card" method="post">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="hammasir_action" value="perms_update">
          <input type="hidden" name="link_id" value="<?php echo (int) $hammasir_c_open['id']; ?>">
          <label>اجازه‌ی مشاهده</label>
          <div><label><input type="checkbox" checked disabled> <?php echo e($hammasir_c_labels['VIEW_SUMMARY']); ?> (پایه — همیشه فعال)</label></div>
          <div><label><input type="checkbox" name="perm_VIEW_PROGRESS" value="1" <?php if (isset($hammasir_c_perms['VIEW_PROGRESS']) && (int) $hammasir_c_perms['VIEW_PROGRESS'] === 1) echo 'checked'; ?>> <?php echo e($hammasir_c_labels['VIEW_PROGRESS']); ?></label></div>
          <div><label><input type="checkbox" name="perm_VIEW_ACTIVITY_DETAILS" value="1" <?php if (isset($hammasir_c_perms['VIEW_ACTIVITY_DETAILS']) && (int) $hammasir_c_perms['VIEW_ACTIVITY_DETAILS'] === 1) echo 'checked'; ?>> <?php echo e($hammasir_c_labels['VIEW_ACTIVITY_DETAILS']); ?></label></div>
          <div><label><input type="checkbox" name="perm_VIEW_MOOD" value="1" <?php if (isset($hammasir_c_perms['VIEW_MOOD']) && (int) $hammasir_c_perms['VIEW_MOOD'] === 1) echo 'checked'; ?>> <?php echo e($hammasir_c_labels['VIEW_MOOD']); ?></label></div>
          <div class="btn-row">
            <button class="btn" type="submit">ذخیره‌ی دسترسی‌ها</button>
          </div>
        </form>
      </details>
      <details>
        <summary>گفتگو</summary>
        <?php if ($hammasir_c_msg_on) { ?>
          <?php
          $hammasir_link = $hammasir_c_open;
          $hammasir_other_label = $hammasir_c_title;
          $hammasir_msg_on = true;
          include dirname(__FILE__) . '/hammasir_chat.php';
          ?>
        <?php } else { ?>
          <p>همراه شما در حال حاضر دریافت پیام را فعال نکرده است.</p>
        <?php } ?>
      </details>
      <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="hammasir_action" value="link_revoke">
        <input type="hidden" name="link_id" value="<?php echo (int) $hammasir_c_open['id']; ?>">
        <div class="btn-row">
          <button class="btn sec" type="submit">قطع ارتباط</button>
        </div>
      </form>
      <?php } ?>
    </section>
    <?php
}
