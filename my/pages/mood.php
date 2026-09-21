<?php
require_login();
$u = current_user();
$today = jalali_today();
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $scores = array(
        'energy' => (int) (isset($_POST['energy']) ? $_POST['energy'] : 0),
        'general' => (int) (isset($_POST['general']) ? $_POST['general'] : 0),
        'focus' => (int) (isset($_POST['focus']) ? $_POST['focus'] : 0),
        'sleep' => (int) (isset($_POST['sleep']) ? $_POST['sleep'] : 0),
        'stress' => (int) (isset($_POST['stress']) ? $_POST['stress'] : 0),
    );
    foreach ($scores as $v) {
        if ($v < 1 || $v > 5) { $err = 'هر پنج شاخص را انتخاب کنید.'; break; }
    }
    if ($err === '') {
        save_mood($u['id'], $today, $scores, isset($_POST['note']) ? $_POST['note'] : '');
        joma_redirect('index.php?p=dashboard');
    }
}
$ex = get_mood($u['id'], $today);
$metrics = array(
    'energy' => array('انرژی', array('😴', '😐', '🙂', '😄', '⚡')),
    'general' => array('حال عمومی', array('😞', '😐', '🙂', '😊', '🤩')),
    'focus' => array('تمرکز', array('🌫️', '😐', '🙂', '🎯', '🧠')),
    'sleep' => array('کیفیت خواب', array('😫', '😐', '🙂', '😴', '✨')),
    'stress' => array('سطح استرس', array('😌', '🙂', '😐', '😟', '😣')),
);
$map = array('general' => 'general_mood', 'sleep' => 'sleep_quality');
$first = $u['first_name'] ? $u['first_name'] : '';
joma_header('خلق من', array(array('label' => 'داشبورد', 'href' => joma_url('index.php?p=dashboard')), array('label' => 'خلق')));
?>
<div class="page-head">
  <div>
    <h1><?php echo e(greeting_fa()); ?><?php echo $first ? ' ' . e($first) : ''; ?></h1>
    <p class="lede">امروز چطوری؟</p>
    <p class="lede"><?php echo e(jalali_format($today)); ?></p>
  </div>
  <?php echo joma_logo(64, true); ?>
</div>
<form method="post">
<?php
echo csrf_field();
foreach ($metrics as $key => $m) {
    $field = isset($map[$key]) ? $map[$key] : $key;
    $cur = $ex ? (int) $ex[$field] : 0;
    echo '<section class="card mood-card"><h3>'.e($m[0]).'</h3><div class="mood-row">';
    for ($i = 1; $i <= 5; $i++) {
        echo '<label class="mood-opt"><input type="radio" name="'.e($key).'" value="'.$i.'" '.($cur === $i ? 'checked' : '').'>';
        echo '<span class="moodbtn '.($cur === $i ? 'on' : '').'">'.$m[1][$i - 1].'</span></label>';
    }
    echo '</div></section>';
}
?>
<section class="card">
  <h3>یادداشت امروز (اختیاری)</h3>
  <textarea name="note" placeholder="اگر چیزی روی دلت است بنویس..."><?php echo $ex ? e($ex['note']) : ''; ?></textarea>
</section>
<?php if ($err) echo '<p class="bad">'.e($err).'</p>'; ?>
<button class="btn btn-block" type="submit">ادامه به داشبورد</button>
</form>
<?php joma_footer(); ?>
