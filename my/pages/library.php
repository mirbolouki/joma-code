<?php
require_login();
require_perm('MANAGE_ACTIVITY_LIBRARY');
$u = current_user();
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'];
    if ($act === 'save') {
        $in = array(
            'name' => trim($_POST['name']),
            'category' => $_POST['category'],
            'frequency' => $_POST['frequency'],
            'data_type' => $_POST['data_type'],
            'unit' => $_POST['unit'],
            // One target only: the one that belongs to the selected frequency.
            'target' => isset($_POST['target']) ? trim($_POST['target']) : '',
            'weight' => isset($_POST['weight']) ? $_POST['weight'] : '',
            'sticker' => $_POST['sticker'],
            'color' => $_POST['color'],
            'status' => $_POST['status'],
        );
        // The stored row (when editing) lets validation accept a legacy unit
        // that the official mapping no longer allows, instead of rejecting it.
        $current = ((int) $_POST['id']) ? get_activity((int) $_POST['id'], $u['id']) : null;
        $msg = validate_activity_input($in, $current);
        if ($msg === '') save_activity($u['id'], $in, (int) $_POST['id']);
    } elseif ($act === 'del' && !empty($_POST['confirm'])) {
        delete_activity($_POST['id'], $u['id']);
    } elseif ($act === 'toggle') {
        $a = get_activity($_POST['id'], $u['id']);
        if ($a) {
            $a['status'] = $a['status'] === 'INACTIVE' ? 'ACTIVE' : 'INACTIVE';
            save_activity($u['id'], $a, $a['id']);
        }
    }
}
$q = isset($_GET['q']) ? $_GET['q'] : '';
$cat = isset($_GET['cat']) ? $_GET['cat'] : '';
$fr = isset($_GET['fr']) ? $_GET['fr'] : '';
$list = list_user_activities($u['id']);
$edit = isset($_GET['edit']) ? get_activity($_GET['edit'], $u['id']) : null;
joma_header('کتابخانه', array(array('label' => 'داشبورد', 'href' => joma_url('index.php?p=dashboard')), array('label' => 'کتابخانه')));
?>
<div class="page-head">
  <div>
    <h1>کتابخانه فعالیت‌ها</h1>
    <p class="lede">۱۰۷ فعالیت رسمی جدول JOMA. Frequency / Target / Weight از همان جدول است نه seed موقت.</p>
  </div>
  <a class="btn" href="<?php echo e(joma_url('index.php?p=library&new=1')); ?>">+ فعالیت جدید</a>
</div>
<form class="card grid grid-3" method="get">
  <input type="hidden" name="p" value="library">
  <?php echo function_exists('joma_sid_field') ? joma_sid_field() : ''; ?>
  <input name="q" placeholder="جستجو نام یا ACT..." value="<?php echo e($q); ?>">
  <select name="cat"><option value="">همه دسته‌ها</option><?php foreach (categories_list() as $c) echo '<option '.($cat === $c ? 'selected' : '').'>'.e($c).'</option>'; ?></select>
  <select name="fr"><option value="">همه تناوب‌ها</option><?php foreach (frequencies_list() as $k => $v) echo '<option value="'.$k.'" '.($fr === $k ? 'selected' : '').'>'.$v.'</option>'; ?></select>
  <button class="btn sec">فیلتر</button>
</form>
<?php if ($msg) echo '<p class="toast bad">'.e($msg).'</p>'; ?>
<?php
if ($edit || isset($_GET['new'])) {
    $row = $edit;
    // ---- initial values -------------------------------------------------
    $selType = $row ? $row['data_type'] : 'DURATION';
    $selFreq = $row ? $row['frequency'] : 'DAILY';
    $selUnit = $row ? $row['unit'] : unit_default_for_data_type($selType);
    // A legacy row whose unit the official mapping no longer allows (e.g. the
    // seed ACT041: BOOLEAN with UNIT_MIN) keeps its own value selectable, so
    // opening and saving the form never silently rewrites it.
    $allUnits = units_list();
    $unitOpts = activity_form_unit_options($selType, $selUnit);
    $targetVal = $row ? clean_number(target_of($row)) : '1';
    $selWeight = $row ? (int) $row['weight'] : 3;
    if ($selWeight < 1 || $selWeight > 5) $selWeight = 3;

    // ---- data for the JavaScript filters --------------------------------
    $jsUnits = array();
    foreach (unit_map_data_type() as $t => $pairs) {
        // NOTE: a local name is mandatory here — `$list` holds the activity
        // cards rendered further down this page.
        $unitList = array();
        foreach ($pairs as $k => $lbl) {
            $unitList[] = array('key' => $k, 'label' => isset($allUnits[$k]) ? $allUnits[$k] : $lbl);
        }
        $jsUnits[$t] = $unitList;
    }
    $jsTarget = array();
    foreach (array('DAILY', 'WEEKLY', 'MONTHLY') as $f) {
        $jsTarget[$f] = array('label' => activity_target_label($f), 'hint' => activity_target_hint($f));
    }
    // One rule per (type, unit, frequency) — the same rules the validator uses.
    $jsRules = array();
    foreach (datatypes_list() as $t => $tl) {
        foreach (units_list() as $unitKey => $ul) {
            if (!unit_allowed_for_data_type($unitKey, $t)) continue;
            foreach (frequencies_list() as $f => $fl) {
                $r = activity_target_rule($t, $unitKey, $f);
                $r['sentence'] = activity_target_rule_sentence($r, $unitKey);
                $jsRules[$t][$unitKey][$f] = $r;
            }
        }
    }
?>
<form class="card" method="post" id="activity-form">
  <?php echo csrf_field(); ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?php echo $row ? e($row['id']) : '0'; ?>">
  <h2><?php echo $row ? 'ویرایش فعالیت' : 'فعالیت جدید'; ?></h2>

  <label for="f-name">عنوان فعالیت</label>
  <input id="f-name" name="name" value="<?php echo $row ? e($row['name']) : ''; ?>" required>
  <p class="hint">یک نام کوتاه و واضح بنویسید (مثلاً: پیاده‌روی، نوشیدن آب، مطالعه).</p>

  <label for="f-category">دسته‌بندی</label>
  <select id="f-category" name="category"><?php foreach (categories_list() as $c) echo '<option '.(($row && $row['category'] === $c) ? 'selected' : '').'>'.e($c).'</option>'; ?></select>
  <p class="hint">این فعالیت به کدام بخش از زندگی شما مربوط است؟</p>

  <label for="frequency">تناوب انجام</label>
  <select id="frequency" name="frequency"><?php foreach (frequencies_list() as $k => $v) echo '<option value="'.$k.'" '.(($row && $row['frequency'] === $k) ? 'selected' : '').'>'.e($v).'</option>'; ?></select>
  <p class="hint">این فعالیت را در چه بازه زمانی ارزیابی می‌کنید؟ (روزانه، هفتگی یا ماهانه)</p>

  <label for="data_type">نوع اندازه‌گیری</label>
  <select id="data_type" name="data_type"><?php foreach (datatypes_list() as $k => $v) echo '<option value="'.$k.'" '.(($row && $row['data_type'] === $k) ? 'selected' : '').'>'.e($v).'</option>'; ?></select>
  <p class="hint">نحوه ثبت عملکرد را مشخص کنید: بر اساس زمان، تعداد، تیک بله/خیر، یا امتیاز کیفی.</p>

  <label for="unit">واحد اندازه‌گیری</label>
  <select id="unit" name="unit"><?php foreach ($unitOpts as $k => $v) echo '<option value="'.e($k).'" '.(($selUnit === $k) ? 'selected' : '').'>'.e($v).'</option>'; ?></select>
  <p class="hint">واحدی که با آن فعالیت را ثبت می‌کنید (مثلاً دقیقه، لیوان، بار).</p>

  <div id="target-row">
    <label for="target" id="target-label"><?php echo e(activity_target_label($selFreq)); ?></label>
    <input id="target" name="target" dir="ltr" value="<?php echo e($targetVal); ?>" required>
    <p class="hint" id="target-hint"><?php echo e(activity_target_hint($selFreq)); ?></p>
    <p class="toast bad" id="target-error" style="display:none"></p>
  </div>

  <label for="f-weight">میزان اهمیت در برنامه</label>
  <select id="f-weight" name="weight"><?php foreach (weights_list() as $w => $lbl) echo '<option value="'.$w.'" '.((int) $selWeight === (int) $w ? 'selected' : '').'>'.e($lbl).'</option>'; ?></select>
  <p class="hint">نقش این فعالیت در موفقیت برنامه این دوره: عادی، قابل‌توجه، مهم، بسیار مهم یا تعیین‌کننده.</p>

  <label for="f-sticker">استیکر</label>
  <select id="f-sticker" name="sticker"><?php foreach (stickers_list() as $st) echo '<option '.(($row && $row['sticker'] === $st) ? 'selected' : '').'>'.e($st).'</option>'; ?></select>

  <label for="f-color">رنگ</label>
  <input id="f-color" name="color" dir="ltr" value="<?php echo $row ? e($row['color']) : '#B8D4F0'; ?>">

  <label for="f-status">وضعیت</label>
  <select id="f-status" name="status"><option value="ACTIVE">فعال</option><option value="INACTIVE" <?php echo ($row && $row['status'] === 'INACTIVE') ? 'selected' : ''; ?>>غیرفعال</option></select>

  <div class="btn-row" style="margin-top:14px">
    <button class="btn">ذخیره</button>
    <a class="btn sec" href="<?php echo e(joma_url('index.php?p=library')); ?>">انصراف</a>
  </div>
</form>
<script>
(function () {
  var unitsByType = <?php echo json_encode($jsUnits, JSON_UNESCAPED_UNICODE); ?>;
  var targetText  = <?php echo json_encode($jsTarget, JSON_UNESCAPED_UNICODE); ?>;
  var rules       = <?php echo json_encode($jsRules, JSON_UNESCAPED_UNICODE); ?>;
  var form   = document.getElementById('activity-form');
  var typeEl = document.getElementById('data_type');
  var unitEl = document.getElementById('unit');
  var freqEl = document.getElementById('frequency');
  var labelEl = document.getElementById('target-label');
  var hintEl  = document.getElementById('target-hint');
  var rowEl   = document.getElementById('target-row');
  var targetEl = document.getElementById('target');
  var errEl   = document.getElementById('target-error');

  function currentRule() {
    var byUnit = rules[typeEl.value] || {};
    var byFreq = byUnit[unitEl.value] || {};
    return byFreq[freqEl.value] || { hidden: false, label: 'هدف', hint: '', min: 0, max: null, integer: false, step: 'any', sentence: '' };
  }

  function showError(msg) {
    if (!msg) { errEl.style.display = 'none'; errEl.textContent = ''; return true; }
    errEl.textContent = msg;
    errEl.style.display = '';
    targetEl.focus();
    return false;
  }

  function renderUnits(keep) {
    var list = unitsByType[typeEl.value] || [];
    unitEl.innerHTML = '';
    for (var i = 0; i < list.length; i++) {
      var opt = document.createElement('option');
      opt.value = list[i].key;
      opt.textContent = list[i].label;
      if (keep && list[i].key === keep) opt.selected = true;
      unitEl.appendChild(opt);
    }
    if (!keep && list.length) unitEl.value = list[0].key;
    // BOOLEAN has no unit: lock the field on UNIT_NONE.
    unitEl.disabled = (typeEl.value === 'BOOLEAN');
  }

  function renderTarget() {
    var r = currentRule();
    if (r.hidden) {
      rowEl.style.display = 'none';
      targetEl.disabled = true;
      targetEl.removeAttribute('required');
      targetEl.value = '1';
      return;
    }
    rowEl.style.display = '';
    targetEl.disabled = false;
    targetEl.setAttribute('required', 'required');
    labelEl.textContent = r.label;
    hintEl.textContent = (r.hint ? r.hint + ' ' : '') + (r.sentence || 'مقدار هدف: عددی بزرگ‌تر از صفر.');
    if (r.min !== null && r.min !== 0) targetEl.min = r.min; else targetEl.removeAttribute('min');
    if (r.max !== null) targetEl.max = r.max; else targetEl.removeAttribute('max');
    targetEl.step = r.step || 'any';
    showError('');
  }

  // Block impossible values before the request is even sent.
  function validateTarget() {
    var r = currentRule();
    if (r.hidden) return showError('');
    var raw = (targetEl.value || '').trim();
    if (raw === '' || isNaN(Number(raw))) return showError('مقدار هدف باید یک عدد باشد.');
    var v = Number(raw);
    if (v <= 0) return showError('مقدار هدف باید عددی بزرگ‌تر از صفر باشد.');
    if (r.integer && Math.abs(v - Math.round(v)) > 1e-9) return showError('مقدار هدف باید یک عدد صحیح باشد (بدون اعشار). ' + (r.sentence || ''));
    if (r.min !== null && v < Number(r.min)) return showError('مقدار هدف نمی‌تواند کمتر از ' + r.min + ' باشد. ' + (r.sentence || ''));
    if (r.max !== null && v > Number(r.max)) return showError(r.sentence || ('مقدار هدف نمی‌تواند بیشتر از ' + r.max + ' باشد.'));
    return showError('');
  }

  typeEl.addEventListener('change', function () { renderUnits(null); renderTarget(); });
  unitEl.addEventListener('change', renderTarget);
  freqEl.addEventListener('change', renderTarget);
  targetEl.addEventListener('input', function () { if (errEl.style.display !== 'none') validateTarget(); });
  form.addEventListener('submit', function (ev) {
    // A disabled field is not submitted — re-enable it just before sending.
    unitEl.disabled = false;
    if (!validateTarget()) ev.preventDefault();
  });

  renderUnits(<?php echo json_encode($selUnit, JSON_UNESCAPED_UNICODE); ?>);
  renderTarget();
})();
</script>
<?php } ?>
<div class="grid grid-2">
<?php
$shown = 0;
foreach ($list as $a) {
    if ($q && !joma_contains($a['name'] . $a['code'], $q)) continue;
    if ($cat && $a['category'] !== $cat) continue;
    if ($fr && $a['frequency'] !== $fr) continue;
    $shown++;
    $dim = $a['status'] === 'INACTIVE' ? 'opacity:.6' : '';
    echo '<article class="card" style="padding:0;'.$dim.'">';
    echo '<div class="act-head" style="background:'.$a['color'].'66"><div class="sticker">'.e($a['sticker']).'</div><span class="chip" dir="ltr">'.e($a['code']).'</span></div>';
    echo '<div class="act-body"><h3>'.e($a['name']).'</h3>';
    echo '<p class="meta">'.e($a['category']).'</p>';
    echo '<p class="meta">'.e(frequencies_list()[$a['frequency']]).' · '.e(datatypes_list()[$a['data_type']]).' · اهمیت '.e(weight_label($a['weight'])).'</p>';
    echo '<p class="meta">هدف روز '.fa_num($a['daily_target']).' · هفته '.fa_num($a['weekly_target']).' · ماه '.fa_num($a['monthly_target']).'</p>';
    echo '<div class="btn-row grow">';
    $learnFile = dirname(__FILE__) . '/../content/learn_lib.php';
    if (is_file($learnFile)) {
        if (!function_exists('learn_guide')) require $learnFile;
        if (learn_guide($a['code'])) {
            echo '<a class="btn" href="'.e(joma_url('index.php?p=learn&code='.$a['code'])).'">آموزش</a>';
        }
    }
    echo '<a class="btn sec" href="'.e(joma_url('index.php?p=library&edit='.$a['id'])).'">ویرایش</a>';
    echo '<form method="post">'.csrf_field().'<input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="'.$a['id'].'"><button class="btn ghost">'.($a['status'] === 'INACTIVE' ? 'فعال‌سازی' : 'غیرفعال').'</button></form>';
    echo '<form method="post" onsubmit="return confirm(\'حذف از کتابخانه؟ تصویر دوره‌های قبلی می‌ماند.\')">'.csrf_field().'<input type="hidden" name="action" value="del"><input type="hidden" name="confirm" value="1"><input type="hidden" name="id" value="'.$a['id'].'"><button class="btn ghost">حذف</button></form>';
    echo '</div></div></article>';
}
if (!$shown) echo empty_state('موردی نیست', 'فیلتر را عوض کنید یا فعالیت جدید بسازید.');
?>
</div>
<?php joma_footer(); ?>
