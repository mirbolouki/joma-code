<?php
/**
 * JOMA — کنسول مدیر (SPEC/23 admin + 24-roles)
 * فقط برای نقش مدیر (ADMIN_ACCESS). همهٔ اعداد از دادهٔ واقعی همین نصب می‌آید.
 * قواعد سند: مدیر نمی‌تواند حساب کاربر را حذف کند (فقط پشتیبانی/خود کاربر)،
 * و هیچ دادهٔ خصوصی کاربران (یادداشت‌ها) اینجا نمایش داده نمی‌شود.
 */
require_perm('ADMIN_ACCESS');
$u = current_user();
$msg = '';
$err = '';

/* ---------- دادهٔ واقعی کاربران ---------- */
$users = array();
$stats = array('users' => 0, 'events' => 0, 'moods' => 0, 'plans' => 0, 'notes' => 0, 'links' => 0);
if (store_mode() === 'mysql') {
    $raw = joma_query('SELECT id, first_name, last_name, username, email, phone, job, role_key, access_level, created_at FROM joma_users ORDER BY id', '', array());
    $users = is_array($raw) ? $raw : array();
    $e = joma_query_one('SELECT COUNT(*) AS c FROM joma_performance_events', '', array());
    $stats['events'] = $e ? (int) $e['c'] : 0;
    $m = joma_query_one('SELECT COUNT(*) AS c FROM joma_mood_records', '', array());
    $stats['moods'] = $m ? (int) $m['c'] : 0;
    $n = joma_query_one('SELECT COUNT(*) AS c FROM joma_mood_records WHERE note IS NOT NULL AND note <> ?', 's', array(''));
    $stats['notes'] = $n ? (int) $n['c'] : 0;
    $p = joma_query_one('SELECT COUNT(*) AS c FROM joma_plans', '', array());
    $stats['plans'] = $p ? (int) $p['c'] : 0;
} else {
    $data = store_load();
    foreach ($data['users'] as $row) $users[] = $row;
    $stats['events'] = count($data['events']);
    $stats['moods'] = count($data['moods']);
    $stats['plans'] = count($data['plans']);
    foreach ($data['moods'] as $m) if (isset($m['note']) && trim((string) $m['note']) !== '') $stats['notes']++;
}
$stats['users'] = count($users);
try {
    if (function_exists('hammasir_admin_count')) {
        $stats['links'] = (int) hammasir_admin_count();
    } elseif (function_exists('hammasir_links_any_by_client')) {
        $stats['links'] = 0;
    }
} catch (Throwable $e) { $stats['links'] = 0; }

/* ---------- درخواست‌های پاک‌کردن حساب (نمایشی · منتظر بک‌اند برای اجرا) ---------- */
$delReqs = array();
if (function_exists('joma_kv_get_json')) {
    foreach ($users as $row) {
        $r = joma_kv_get_json('account_delete_request_' . (int) $row['id']);
        if (is_array($r)) $delReqs[] = array('user' => $row, 'req' => $r);
    }
}

/* ---------- بزرگ‌ترین فعالیت‌ها در کل سیستم (بدون دادهٔ خصوصی) ---------- */
$topActs = array();
if (store_mode() === 'mysql') {
    // (FIX-AC1) ستون activity_code در جدول رویدادها وجود ندارد و هرگز نوشته هم
    // نمی‌شود؛ کد فعالیت از جداول برنامه‌های کاربر (JOIN) گرفته می‌شود تا با
    // دیتابیس‌های موجود سازگار باشد — بدون هیچ تغییر ساختار دیتابیس.
    $raw = joma_query("SELECT COALESCE(pa.activity_code, '-') AS activity_code, COUNT(*) AS c FROM joma_performance_events ev LEFT JOIN joma_plan_activities pa ON pa.id = ev.plan_activity_id GROUP BY COALESCE(pa.activity_code, '-') ORDER BY c DESC LIMIT 8", '', array());
    $topActs = is_array($raw) ? $raw : array();
} else {
    $data = store_load();
    $codes = array();
    $byId = array();
    foreach ($data['plan_activities'] as $pa) $byId[(int) $pa['id']] = isset($pa['activity_code']) ? $pa['activity_code'] : '—';
    foreach ($data['events'] as $ev) {
        $c = isset($byId[(int) $ev['plan_activity_id']]) ? $byId[(int) $ev['plan_activity_id']] : '—';
        if (!isset($codes[$c])) $codes[$c] = 0;
        $codes[$c]++;
    }
    arsort($codes);
    $i = 0;
    foreach ($codes as $c => $n) { $topActs[] = array('activity_code' => $c, 'c' => $n); if (++$i >= 8) break; }
}

joma_header('کنسول مدیر', array());
?>
<?php joma_v2_pagehead('کنسول مدیر', 'کنسول مدیر', 'وضعیت واقعی همین نصب: کاربران، حجم دادهٔ ثبت‌شده و ابزارهای مدیریتی.', '<span>نقش: مدیر</span>'); ?>

<?php if ($msg) echo '<p class="toast ok">' . e($msg) . '</p>'; ?>
<?php if ($err) echo '<p class="toast bad">' . e($err) . '</p>'; ?>

<div class="rkpis">
  <div class="rkpi"><div class="k">کاربران</div><div class="v"><?php echo fa_num($stats['users']); ?></div><div class="t">همهٔ نقش‌ها</div></div>
  <div class="rkpi"><div class="k">رخدادهای ثبت‌شده</div><div class="v"><?php echo fa_num($stats['events']); ?></div><div class="t">کل نصب</div></div>
  <div class="rkpi"><div class="k">روزهای ثبت حال</div><div class="v"><?php echo fa_num($stats['moods']); ?></div><div class="t"><?php echo fa_num($stats['notes']); ?> یادداشت خصوصی</div></div>
  <div class="rkpi"><div class="k">برنامه‌ها</div><div class="v"><?php echo fa_num($stats['plans']); ?></div><div class="t">دوره‌های ساخته‌شده</div></div>
</div>

<div class="grid grid-2">
  <section class="card">
    <div class="k">وضعیت نصب</div>
    <div class="profile-list" style="margin-top:8px">
      <div class="hd-item"><div class="row" style="justify-content:space-between"><b style="font-size:12.5px">حالت ذخیره‌سازی</b>
        <span class="chip <?php echo store_mode() === 'mysql' ? 'g' : 's'; ?>"><?php echo store_mode() === 'mysql' ? 'MySQL' : 'فایل (بدون دیتابیس)'; ?></span></div></div>
      <div class="hd-item"><div class="row" style="justify-content:space-between"><b style="font-size:12.5px">کتابخانهٔ فعالیت‌ها</b>
        <span class="chip n"><?php echo fa_num(count(official_library())); ?> فعالیت</span></div></div>
      <div class="hd-item"><div class="row" style="justify-content:space-between"><b style="font-size:12.5px">موتور موفقیت</b>
        <span class="chip n" dir="ltr"><?php echo function_exists('success_metric_version') ? e(success_metric_version()) : '—'; ?></span></div></div>
      <div class="hd-item"><div class="row" style="justify-content:space-between"><b style="font-size:12.5px">دفترچه و بینش</b>
        <span class="chip n" dir="ltr"><?php echo function_exists('joma_insight_rule_version') ? e(joma_insight_rule_version()) : '—'; ?></span></div></div>
      <div class="hd-item"><div class="row" style="justify-content:space-between"><b style="font-size:12.5px">آب (پیش‌نویس/قطعی)</b>
        <span class="chip n"><?php echo function_exists('joma_water_state') ? 'فعال' : 'غیرفعال'; ?></span></div></div>
    </div>
  </section>

  <section class="card">
    <div class="k">ابزارهای مدیریتی</div>
    <p class="lede" style="margin-top:6px">کارهایی که مدیر می‌تواند انجام دهد — و کارهایی که نمی‌تواند.</p>
    <div class="btn-row" style="margin-top:8px">
      <a class="btn sm" href="<?php echo e(joma_url('index.php?p=admin_recovery')); ?>"><?php echo joma_v2_icon('i-lock'); ?>صدور کد بازیابی رمز</a>
      <?php if (function_exists('joma_v2_hammasir_active') && joma_v2_hammasir_active()) { ?>
        <a class="btn sec sm" href="<?php echo e(joma_url('index.php?p=hammasir_providers')); ?>">مدیریت مشاوران</a>
        <a class="btn sec sm" href="<?php echo e(joma_url('index.php?p=hammasir_admins')); ?>">مدیران سیستم</a>
      <?php } ?>
    </div>
    <p class="tiny" style="margin-top:8px">🔴 مدیر <b>نمی‌تواند</b> حساب کاربر را حذف کند (فقط کاربر خودش، با مهلت ۳۰ روز). یادداشت‌های خصوصی کاربران هم در این کنسول دیده نمی‌شوند.</p>
  </section>
</div>

<?php if ($delReqs) { ?>
<section class="card" style="border:1.5px solid var(--coral)">
  <div class="k">درخواست‌های پاک‌کردن حساب</div>
  <div class="table-wrap" style="margin-top:8px"><table class="tbl">
    <thead><tr><th>کاربر</th><th>تاریخ درخواست</th><th>وضعیت</th></tr></thead>
    <tbody>
    <?php foreach ($delReqs as $d) { ?>
      <tr><td>@<?php echo e($d['user']['username']); ?></td>
        <td><?php echo e(isset($d['req']['jalali']) ? jalali_format($d['req']['jalali']) : '—'); ?></td>
        <td>در انتظار مهلت ۳۰ روزه</td></tr>
    <?php } ?>
    </tbody></table></div>
  <p class="tiny" style="margin-top:6px">کاربر خودش می‌تواند درخواست را لغو کند؛ تا آن روز داده‌ای پاک نمی‌شود.</p>
</section>
<?php } ?>

<section class="card">
  <div class="k">کاربران</div>
  <div class="table-wrap" style="margin-top:8px">
    <table class="tbl">
      <thead><tr><th>#</th><th>نام</th><th>نام کاربری</th><th>نقش</th><th>شغل</th><th>عضویت</th><th>عملیات</th></tr></thead>
      <tbody>
      <?php foreach ($users as $row) { ?>
        <tr>
          <td><?php echo fa_num((int) $row['id']); ?></td>
          <td><?php echo e(trim($row['first_name'] . ' ' . $row['last_name'])); ?></td>
          <td dir="ltr">@<?php echo e($row['username']); ?></td>
          <td><span class="chip <?php echo $row['role_key'] === 'admin' ? 'g' : 'n'; ?>"><?php echo e(role_label($row['role_key'])); ?></span></td>
          <td><?php echo e($row['job']); ?></td>
          <td><?php echo e(substr((string) $row['created_at'], 0, 10)); ?></td>
          <td><a class="tiny" href="<?php echo e(joma_url('index.php?p=admin_recovery&u=' . urlencode($row['username']))); ?>">کد بازیابی رمز</a></td>
        </tr>
      <?php } ?>
      </tbody>
    </table>
  </div>
  <p class="tiny" style="margin-top:6px">رمزها هش‌شده‌اند و اینجا دیده نمی‌شوند. برای کمک به ورود کاربر، از «کد بازیابی رمز» استفاده کن.</p>
</section>

<?php if ($topActs) { ?>
<section class="card">
  <div class="k">پرثبت‌ترین فعالیت‌ها در کل نصب</div>
  <p class="tiny" style="margin-top:4px">فقط کد فعالیت و شمارش کل — بدون هیچ دادهٔ شخصی.</p>
  <div class="table-wrap" style="margin-top:8px"><table class="tbl">
    <thead><tr><th>کد</th><th>تعداد رخداد</th></tr></thead>
    <tbody><?php foreach ($topActs as $t) { ?>
      <tr><td dir="ltr"><?php echo e($t['activity_code']); ?></td><td><?php echo fa_num((int) $t['c']); ?></td></tr>
    <?php } ?></tbody>
  </table></div>
</section>
<?php } ?>
<?php joma_footer(); ?>
