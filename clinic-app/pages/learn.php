<?php
require_login();
$learnLib = dirname(__FILE__) . '/../content/learn_lib.php';
if (is_file($learnLib)) require $learnLib;
$data = function_exists('learn_data') ? learn_data() : array();
$code = isset($_GET['code']) ? strtoupper(trim($_GET['code'])) : '';
$g = isset($_GET['g']) ? $_GET['g'] : '';
if ($g !== 'joma' && $g !== 'couple' && $g !== 'schema') $g = '';

$guide = ($code && function_exists('learn_guide')) ? learn_guide($code) : null;

$crumbs = array(
    array('label' => 'داشبورد', 'href' => joma_url('index.php?p=dashboard')),
    array('label' => 'آموزش', 'href' => joma_url('index.php?p=learn')),
);
if ($guide) $crumbs[] = array('label' => $guide['name']);
elseif ($g === 'joma') $crumbs[] = array('label' => 'استفاده از جوما');
elseif ($g === 'couple') $crumbs[] = array('label' => 'زوج درمانی');
elseif ($g === 'schema') $crumbs[] = array('label' => 'طرحواره درمانی');

joma_header('آموزش', $crumbs);
?>
<style>
.learn-wrap{max-width:820px}
.learn-hero{
  background:linear-gradient(125deg,#f4ebff 0%,#fff 42%,#ffe8d4 100%);
  padding:32px 28px 28px;margin-bottom:18px;position:relative;overflow:hidden;
}
.learn-hero:after{
  content:"";position:absolute;left:-40px;bottom:-50px;width:180px;height:180px;border-radius:50%;
  background:radial-gradient(circle,rgba(196,167,231,.35),transparent 70%);
}
.learn-kicker{letter-spacing:.18em;font-size:11px;font-weight:800;color:var(--primary);margin:0 0 10px}
.learn-quote{
  margin:18px 0 0;padding:16px 18px 16px 16px;border-right:3px solid #c4a7e7;
  background:rgba(255,255,255,.55);border-radius:0 18px 18px 0;font-size:17px;line-height:2;font-weight:600;
}
.learn-tile{
  display:block;text-decoration:none;color:inherit;min-height:148px;transition:transform .18s ease,box-shadow .18s;
  padding:22px;
}
.learn-tile:hover{transform:translateY(-3px);box-shadow:0 22px 50px rgba(92,70,130,.16)}
.learn-tile .sticker{margin-bottom:4px}
.learn-ex{
  display:block;text-decoration:none;color:inherit;padding:0;overflow:hidden;transition:transform .18s ease,box-shadow .18s;
}
.learn-ex:hover{transform:translateY(-2px);box-shadow:0 20px 44px rgba(92,70,130,.14)}
.learn-ex-h{display:flex;gap:14px;align-items:center;padding:16px 18px;background:linear-gradient(90deg,rgba(255,255,255,.4),rgba(244,235,255,.65))}
.learn-ex-b{padding:0 18px 16px}
.learn-block{margin:18px 0}
.learn-block h2{font-size:18px;margin:0 0 10px;font-weight:800}
.learn-step{
  display:flex;gap:14px;align-items:flex-start;padding:14px 16px;margin:0 0 10px;
  background:linear-gradient(180deg,#fff,#faf7ff);border-radius:18px;border:1px solid rgba(196,167,231,.25);
}
.learn-n{
  width:32px;height:32px;border-radius:50%;flex:none;display:grid;place-items:center;
  background:linear-gradient(145deg,#d9c6f5,#7c5cbf);color:#fff;font-weight:900;font-size:13px;
}
.learn-reg{
  background:linear-gradient(90deg,#ecf8f1,#fff);border:1px solid #cfe9d8;border-radius:20px;padding:16px 18px;margin:16px 0;
}
.learn-reg strong{display:block;margin-bottom:6px;color:var(--ok)}
.learn-mist{margin:0;padding:0;list-style:none}
.learn-mist li{padding:8px 0 8px 0;border-bottom:1px solid #f1ecf6}
.learn-mist li:before{content:"— ";color:#c4a7e7;font-weight:800}
.learn-sign{font-size:13px;color:var(--muted);margin-top:22px}
</style>
<?php
function learn_hub_card($href, $ico, $title, $desc) {
    echo '<a class="card learn-tile" href="' . e($href) . '">';
    echo '<div style="font-size:36px;margin-bottom:10px">' . $ico . '</div>';
    echo '<h2 style="margin:0 0 8px;font-size:22px">' . e($title) . '</h2>';
    echo '<p class="lede" style="margin:0">' . e($desc) . '</p></a>';
}
?>
<?php if ($guide) { ?>
  <article class="learn-wrap">
    <section class="card learn-hero">
      <p class="learn-kicker"><?php echo e($guide['category']); ?>  ·  <?php echo e($guide['frequency']); ?></p>
      <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap">
        <div class="sticker" style="width:72px;height:72px;font-size:36px;border-radius:24px"><?php echo e($guide['sticker']); ?></div>
        <div>
          <h1 style="margin:0"><?php echo e($guide['name']); ?></h1>
          <p class="meta" dir="ltr"><?php echo e($guide['code']); ?></p>
        </div>
      </div>
      <?php if (!empty($guide['voice'])) echo '<p class="learn-quote">' . e($guide['voice']) . '</p>'; ?>
    </section>
    <?php if (!empty($guide['warn'])) echo unspecified_notice(e($guide['warn'])); ?>
    <?php if (!empty($guide['why'])) { ?>
      <div class="card learn-block"><h2>چرا این تمرین؟</h2><p style="margin:0"><?php echo e($guide['why']); ?></p></div>
    <?php } ?>
    <?php if (!empty($guide['before'])) { ?>
      <div class="card learn-block"><h2>قبل از شروع</h2><p style="margin:0"><?php echo e($guide['before']); ?></p></div>
    <?php } ?>
    <div class="card learn-block">
      <h2>قدم‌به‌قدم، همان‌طور که در جلسه می‌گویم</h2>
      <?php $n = 1; foreach ($guide['steps'] as $s) {
          echo '<div class="learn-step"><div class="learn-n">' . fa_num($n++) . '</div><div>' . e($s) . '</div></div>';
      } ?>
    </div>
    <?php if (!empty($guide['after'])) { ?>
      <div class="card learn-block"><h2>وقتی تمام شد</h2><p style="margin:0"><?php echo e($guide['after']); ?></p></div>
    <?php } ?>
    <?php if (!empty($guide['mistakes'])) { ?>
      <div class="card learn-block"><h2>این‌ها تمرین نیست</h2>
        <ul class="learn-mist"><?php foreach ($guide['mistakes'] as $m) echo '<li>' . e($m) . '</li>'; ?></ul>
      </div>
    <?php } ?>
    <div class="learn-reg">
      <strong>در جوما چه ثبت کنید</strong>
      <?php echo e($guide['register']); ?>
      <p class="meta" style="margin-top:8px">نوع ثبت: <?php echo e($guide['how']); ?></p>
    </div>
    <div class="btn-row">
      <a class="btn" href="<?php echo e(joma_url('index.php?p=today')); ?>">ثبت در امروز</a>
      <a class="btn sec" href="<?php echo e(joma_url('index.php?p=plan')); ?>">افزودن به برنامه</a>
      <a class="btn ghost" href="<?php echo e(joma_url('index.php?p=learn&g=' . $guide['group'])); ?>">بقیهٔ تمرین‌ها</a>
    </div>
    <p class="learn-sign">جواد میربلوکی  ·  جوما؛ جغد دانا</p>
  </article>
<?php } elseif ($g === 'joma' && !empty($data['joma'])) { $j = $data['joma']; ?>
  <article class="learn-wrap">
    <section class="card learn-hero">
      <p class="learn-kicker">شروع با جوما</p>
      <h1><?php echo e($j['title']); ?></h1>
      <p class="lede" style="margin-top:10px;font-size:17px"><?php echo e($j['lede']); ?></p>
      <?php if (!empty($j['quote'])) echo '<p class="learn-quote">' . e($j['quote']) . '</p>'; ?>
    </section>
    <?php foreach ($j['sections'] as $sec) {
        echo '<div class="card learn-block"><h2>' . e($sec['h']) . '</h2><p style="margin:0">' . e($sec['p']) . '</p></div>';
    } ?>
    <p class="learn-sign">جواد میربلوکی  ·  جوما؛ جغد دانا</p>
    <p><a class="btn sec" href="<?php echo e(joma_url('index.php?p=learn')); ?>">بازگشت به آموزش</a></p>
  </article>
<?php } elseif ($g === 'couple' || $g === 'schema') {
    $meta = isset($data['groups'][$g]) ? $data['groups'][$g] : array('title' => 'آموزش', 'intro' => '');
    echo '<section class="card learn-hero"><p class="learn-kicker">تمرین‌ها</p>';
    echo '<h1>' . e($meta['title']) . '</h1>';
    echo '<p class="lede" style="margin-top:10px;font-size:17px">' . e($meta['intro']) . '</p>';
    if (!empty($meta['quote'])) echo '<p class="learn-quote">' . e($meta['quote']) . '</p>';
    echo '</section>';
    if (!empty($meta['safety'])) echo unspecified_notice(e($meta['safety']));
    echo '<div class="grid grid-2">';
    foreach (learn_group_codes($g) as $item) {
        echo '<a class="card learn-ex" href="' . e(joma_url('index.php?p=learn&code=' . $item['code'])) . '">';
        echo '<div class="learn-ex-h"><div class="sticker">' . e($item['sticker']) . '</div>';
        echo '<div><strong>' . e($item['name']) . '</strong><p class="meta">' . e($item['frequency']);
        if (!empty($item['voice'])) echo ' · لمس کنید و کامل بخوانید';
        echo '</p></div></div>';
        if ($g === 'schema' && !empty($item['how_short'])) {
            echo '<div class="learn-ex-b"><p class="lede" style="margin:12px 0 0;font-size:14px;line-height:2">' . e($item['how_short']) . '</p></div>';
        }
        echo '</a>';
    }
    echo '</div>';
    echo '<p style="margin-top:18px"><a class="btn sec" href="' . e(joma_url('index.php?p=learn')) . '">بازگشت</a></p>';
} else { ?>
  <section class="card learn-hero">
    <p class="learn-kicker">جغد دانا</p>
    <h1>آموزش جوما</h1>
    <p class="lede" style="margin-top:10px;font-size:17px">اینجا من کنار شما می‌نشینم و قدم‌به‌قدم می‌گویم هر تمرین یعنی چه، چطور انجامش بدهید، و در جوما چه چیزی را ثبت کنید. کارت را باز کنید؛ عجله نکنید.</p>
    <p class="learn-quote">قرار نیست کامل باشید. قرار است ببینید، انتخاب کنید و ادامه بدهید.</p>
  </section>
  <div class="grid grid-3">
    <?php
    learn_hub_card(joma_url('index.php?p=learn&g=joma'), '🦉', 'نحوه استفاده از جوما', 'از ورود تا گزارش؛ فلسفه و قواعد ثبت.');
    learn_hub_card(joma_url('index.php?p=learn&g=couple'), '💗', 'زوج درمانی', '۳۵ تمرین رابطه؛ هر کدام یک جلسهٔ کوتاه نوشتاری.');
    learn_hub_card(joma_url('index.php?p=learn&g=schema'), '📒', 'طرحواره درمانی', '۹ تکنیک هسته و ۱۸ طرحوارهٔ انتخابی.');
    ?>
  </div>
<?php } ?>
<?php joma_footer(); ?>
