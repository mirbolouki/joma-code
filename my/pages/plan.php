<?php
require_login();
require_perm('EDIT_PLAN');
$u = current_user();
$key = current_period_key();
$wp = ensure_period($u['id'], $key);
$plan = $wp['plan'];
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = isset($_POST['action']) ? $_POST['action'] : '';
    if ($act === 'add') {
        $a = get_activity(isset($_POST['activity_id']) ? $_POST['activity_id'] : 0, $u['id']);
        if ($a) $msg = add_plan_activity($u['id'], $plan, $a, array(
            'frequency' => isset($_POST['frequency']) ? $_POST['frequency'] : '',
            'target_value' => isset($_POST['target_value']) ? $_POST['target_value'] : '',
            'weight' => isset($_POST['weight']) ? $_POST['weight'] : '',
        ));
        $wp = ensure_period($u['id'], $key);
        $plan = $wp['plan'];
    } elseif ($act === 'savepa') {
        $msg = update_plan_activity($u['id'], $plan, $_POST['pa_id'], array(
            'frequency' => $_POST['frequency'],
            'target_value' => (float) $_POST['target_value'],
            'weight' => (int) $_POST['weight'],
            'sort_order' => (int) $_POST['sort_order'],
        ));
    } elseif ($act === 'up') {
        $pas = list_plan_activities($plan['id'], $u['id']);
        $idx = -1;
        foreach ($pas as $i => $row) if ((int) $row['id'] === (int) $_POST['pa_id']) $idx = $i;
        if ($idx > 0) {
            $cur = $pas[$idx];
            $prev = $pas[$idx - 1];
            update_plan_activity($u['id'], $plan, $cur['id'], array('sort_order' => $prev['sort_order'], 'frequency' => $cur['frequency'], 'target_value' => $cur['target_value'], 'weight' => $cur['weight']));
            update_plan_activity($u['id'], $plan, $prev['id'], array('sort_order' => $cur['sort_order'], 'frequency' => $prev['frequency'], 'target_value' => $prev['target_value'], 'weight' => $prev['weight']));
        }
    } elseif ($act === 'del' && !empty($_POST['confirm'])) {
        $msg = remove_plan_activity($u['id'], $plan, $_POST['pa_id']);
    } elseif ($act === 'next') {
        $msg = transition_plan($u['id'], $plan, $_POST['next']);
        $wp = ensure_period($u['id'], $key);
        $plan = $wp['plan'];
    }
}
$acts = list_user_activities($u['id']);
$pas = list_plan_activities($plan['id'], $u['id']);
$freq = frequencies_list();
$sumW = weight_sum($pas);
$editable = plan_editable($plan['status']);
joma_header('برنامه من', array(array('label' => 'داشبورد', 'href' => joma_url('index.php?p=dashboard')), array('label' => 'برنامه')));
?>
<div class="page-head">
  <div>
    <h1>برنامه <?php echo e(jalali_period_label($plan['period_key'])); ?></h1>
    <p class="lede">هدف و وزن را برای همین ماه می‌توانید عوض کنید. پس از نهایی‌سازی، تصویر ثابت قفل می‌شود.</p>
  </div>
  <?php echo status_badge($plan['status']); ?>
</div>
<?php if ($msg) echo '<p class="toast bad">'.e($msg).'</p>'; ?>
<div class="card btn-row">
<?php if ($plan['status'] === 'DRAFT') { ?>
  <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="next"><input type="hidden" name="next" value="PLANNING"><button class="btn" <?php echo $pas ? '' : 'disabled'; ?>>نهایی‌سازی</button></form>
<?php } elseif ($plan['status'] === 'PLANNING') { ?>
  <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="next"><input type="hidden" name="next" value="RUNNING"><button class="btn" <?php echo $pas ? '' : 'disabled'; ?>>شروع اجرا</button></form>
<?php } elseif ($plan['status'] === 'RUNNING') { ?>
  <p class="ok" style="margin:0">برنامه قفل است تا تاریخچه حفظ شود.</p>
  <form method="post"><?php echo csrf_field(); ?><input type="hidden" name="action" value="next"><input type="hidden" name="next" value="ARCHIVED"><button class="btn sec">بایگانی دوره</button></form>
<?php } else echo '<p class="lede" style="margin:0">این دوره بایگانی شده است.</p>'; ?>
  <span class="chip" style="margin-right:auto">جمع وزن: <?php echo fa_num($sumW); ?></span>
</div>
<?php if ($editable) { ?>
<form class="card grid grid-2" method="post">
  <?php echo csrf_field(); ?><input type="hidden" name="action" value="add">
  <div style="grid-column:1/-1">
    <label>دسته‌بندی</label>
    <select data-plan-cat-filter>
      <option value="">همه فعالیت‌ها</option>
      <?php foreach (categories_list() as $c) echo '<option value="'.e($c).'">'.e($c).'</option>'; ?>
    </select>
  </div>
  <div style="grid-column:1/-1">
    <label>فعالیت کتابخانه</label>
    <select name="activity_id" data-plan-activity-select>
      <?php foreach ($acts as $a) if ($a['status'] !== 'INACTIVE') {
          // The library defaults travel with the option so the form can show
          // the real value behind "خالی = پیش‌فرض".
          echo '<option value="'.e($a['id']).'"'
            . ' data-category="'.e($a['category']).'"'
            . ' data-target="'.e(format_value($a['data_type'], target_of($a), $a['unit'])).'"'
            . ' data-freq="'.e(isset($freq[$a['frequency']]) ? $freq[$a['frequency']] : $a['frequency']).'"'
            . ' data-weight="'.e(weight_label($a['weight'])).'"'
            . ' data-unit="'.e($a['unit']).'"'
            . '>'.e($a['sticker'].' '.$a['name']).'</option>';
      } ?>
    </select>
  </div>
  <div>
    <label>تناوب این دوره</label>
    <select name="frequency" id="plan-frequency"><option value="">پیش‌فرض کتابخانه</option><?php foreach ($freq as $k => $v) echo '<option value="'.$k.'">'.$v.'</option>'; ?></select>
  </div>
  <div><label>هدف این دوره</label><input name="target_value" id="plan-target" dir="ltr" placeholder="خالی = پیش‌فرض کتابخانه"></div>
  <div>
    <label>میزان اهمیت این فعالیت در این دوره</label>
    <select name="weight" id="plan-weight">
      <option value="">پیش‌فرض کتابخانه</option>
      <?php foreach (weights_list() as $w => $lbl) echo '<option value="'.$w.'">'.e($lbl).'</option>'; ?>
    </select>
  </div>
  <div><label>&nbsp;</label><button class="btn" type="submit">افزودن به برنامه این دوره</button></div>
</form>
<script>
(function () {
  var filter = document.querySelector('[data-plan-cat-filter]');
  var sel = document.querySelector('[data-plan-activity-select]');
  var freqEl = document.getElementById('plan-frequency');
  var targetEl = document.getElementById('plan-target');
  var weightEl = document.getElementById('plan-weight');
  if (!filter || !sel) return;

  // Show the real library value instead of a vague "پیش‌فرض".
  function selectedOption() {
    return sel.options[sel.selectedIndex];
  }
  function showDefaults() {
    var opt = selectedOption();
    if (!opt) return;
    var t = opt.getAttribute('data-target') || '';
    var f = opt.getAttribute('data-freq') || '';
    var w = opt.getAttribute('data-weight') || '';
    targetEl.placeholder = t ? ('خالی = ' + t + ' (مقدار فعلی در کتابخانه)') : 'خالی = پیش‌فرض کتابخانه';
    weightEl.options[0].textContent = w ? ('خالی = ' + w + ' (مقدار فعلی در کتابخانه)') : 'پیش‌فرض کتابخانه';
    if (freqEl) freqEl.options[0].textContent = f ? ('خالی = ' + f + ' (مقدار فعلی در کتابخانه)') : 'پیش‌فرض کتابخانه';
    targetEl.title = t ? ('مقدار فعلی در کتابخانه: ' + t) : '';
  }
  sel.addEventListener('change', showDefaults);
  showDefaults();
  var all = [];
  var i;
  var opt;
  for (i = 0; i < sel.options.length; i++) {
    all.push({
      value: sel.options[i].value,
      text: sel.options[i].text,
      category: sel.options[i].getAttribute('data-category') || ''
    });
  }
  function applyFilter(resetIfMissing) {
    var cat = filter.value;
    var prev = sel.value;
    sel.options.length = 0;
    var keep = false;
    for (i = 0; i < all.length; i++) {
      if (cat && all[i].category !== cat) continue;
      opt = document.createElement('option');
      opt.value = all[i].value;
      opt.text = all[i].text;
      opt.setAttribute('data-category', all[i].category);
      sel.appendChild(opt);
      if (all[i].value === prev) keep = true;
    }
    if (!sel.options.length) {
      opt = document.createElement('option');
      opt.value = '';
      opt.text = 'فعالیتی در این دسته نیست';
      sel.appendChild(opt);
      sel.value = '';
      return;
    }
    if (keep) {
      sel.value = prev;
    } else if (resetIfMissing) {
      opt = document.createElement('option');
      opt.value = '';
      opt.text = 'یک فعالیت انتخاب کنید';
      sel.insertBefore(opt, sel.firstChild);
      sel.value = '';
    }
  }
  filter.addEventListener('change', function () {
    applyFilter(true);
  });
})();
</script>
<?php } ?>
<?php if (!$pas) {
    echo empty_state('برنامه خالی است', 'از کتابخانه یک فعالیت اضافه کنید.', joma_url('index.php?p=library'), 'کتابخانه');
} else {
    echo '<div class="grid grid-3">';
    foreach ($pas as $a) {
        echo '<article class="card act-card">';
        echo '<div class="act-head" style="background:'.$a['color'].'33"><div class="sticker">'.e($a['sticker']).'</div><span class="chip">'.e($freq[$a['frequency']]).'</span></div>';
        echo '<div class="act-body"><h3>'.e($a['name']).'</h3><p class="meta">'.e($a['category']).'</p><div class="grow">';
        if ($editable) {
            echo '<form method="post">'.csrf_field();
            echo '<input type="hidden" name="action" value="savepa"><input type="hidden" name="pa_id" value="'.e($a['id']).'">';
            echo '<input type="hidden" name="sort_order" value="'.e($a['sort_order']).'">';
            echo '<select name="frequency">';
            foreach ($freq as $k => $v) echo '<option value="'.$k.'" '.($a['frequency'] === $k ? 'selected' : '').'>'.$v.'</option>';
            echo '</select>';
            echo '<label>هدف</label><input name="target_value" dir="ltr" value="'.e($a['target_value']).'">';
            echo '<label>میزان اهمیت</label><select name="weight">';
            foreach (weights_list() as $w => $lbl) echo '<option value="'.$w.'" '.((int) $a['weight'] === (int) $w ? 'selected' : '').'>'.e($lbl).'</option>';
            echo '</select>';
            echo '<div class="btn-row" style="margin-top:10px"><button class="btn sec" type="submit">ذخیره</button></div></form>';
            echo '<div class="btn-row">';
            echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="up"><input type="hidden" name="pa_id" value="'.e($a['id']).'"><button class="btn ghost" type="submit">بالا</button></form>';
            echo '<form method="post" onsubmit="return confirm(\'حذف از برنامه این دوره؟\');">'.csrf_field().'<input type="hidden" name="action" value="del"><input type="hidden" name="confirm" value="1"><input type="hidden" name="pa_id" value="'.e($a['id']).'"><button class="btn ghost" type="submit">حذف</button></form>';
            echo '</div>';
        } else {
            echo '<p>هدف '.e(format_value($a['data_type'], $a['target_value'], $a['unit'])).'</p>';
            echo '<p>اهمیت '.e(weight_label($a['weight'])).'</p>';
            echo '<a class="btn btn-block" href="'.e(joma_url('index.php?p=today')).'">ثبت عملکرد</a>';
        }
        echo '</div></div></article>';
    }
    echo '</div>';
} ?>
<?php joma_footer(); ?>
