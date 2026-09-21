<?php
if (current_user()) joma_redirect('index.php?p=dashboard');
joma_header('جوما', array(), array('public' => 1));
?>
<style>
.home-spec{max-width:1120px;margin:0 auto;padding:0 20px 80px}
.home-spec-h{margin:8px 0 18px}
.home-spec-h .kicker{margin-bottom:6px}
.home-spec-grid{display:grid;gap:16px}
@media(min-width:800px){.home-spec-grid{grid-template-columns:1fr 1fr}}
.home-spec-card{
  display:flex;flex-direction:column;min-height:280px;padding:28px;margin:0;
  text-decoration:none;color:inherit;transition:transform .18s ease,box-shadow .18s;
  position:relative;overflow:hidden;
}
.home-spec-card:hover{transform:translateY(-3px);box-shadow:0 22px 50px rgba(92,70,130,.16)}
.home-spec-a{background:linear-gradient(145deg,#f4ebff 0%,#fff 48%,#f3e8ff 100%)}
.home-spec-b{background:linear-gradient(145deg,#ffe8d8 0%,#fff 48%,#fde8ef 100%)}
.home-spec-card .ico{font-size:40px;margin-bottom:12px}
.home-spec-card h2{margin:0 0 10px;font-size:26px;font-weight:900}
.home-spec-card p{margin:0 0 18px;color:var(--muted);flex:1}
.home-spec-card .chip{margin-inline-end:6px;margin-bottom:6px}
</style>
<div class="public-top">
  <?php echo joma_logo(56); ?>
  <div class="btn-row">
    <a class="btn ghost" href="<?php echo e(joma_url('index.php?p=login')); ?>">ورود</a>
    <a class="btn" href="<?php echo e(joma_url('index.php?p=register')); ?>">شروع</a>
  </div>
</div>
<section class="landing">
  <div>
    <p class="kicker">محصول مستقل خودمدیریتی</p>
    <h1>جوما</h1>
    <p class="lede" style="font-size:20px">برنامه‌ریزی → اجرا → اندازه‌گیری → فهمیدن → بهبود</p>
    <p class="lede">جوما برای ثبت فعالیت‌ها، عملکرد واقعی و حال روزانه شماست. هر ماه شمسی تاریخچهٔ مستقل خودش را نگه می‌دارد.</p>
    <div class="btn-row" style="margin-top:22px">
      <a class="btn" href="<?php echo e(joma_url('index.php?p=register')); ?>">شروع استفاده</a>
      <a class="btn sec" href="<?php echo e(joma_url('index.php?p=login')); ?>">ورود</a>
      <a class="btn ghost" href="<?php echo e(joma_url('index.php?p=about')); ?>">درباره جوما</a>
    </div>
  </div>
  <div class="grid">
    <?php
    $bits = array(
      array('🌸', 'حال امروز را با استیکر ثبت کن'),
      array('📚', 'کتابخانه ۱۰۷ فعالیت آماده'),
      array('📅', 'دوره‌های شمسی مستقل'),
      array('📊', 'گزارش فقط از داده واقعی'),
    );
    foreach ($bits as $b) {
        echo '<div class="card" style="display:flex;align-items:center;gap:14px;margin:0"><span style="font-size:30px">'.$b[0].'</span><strong>'.e($b[1]).'</strong></div>';
    }
    ?>
  </div>
</section>
<section class="home-spec">
  <div class="home-spec-h">
    <p class="kicker">تمرین‌های تخصصی</p>
    <h2 style="margin:0;font-size:clamp(22px,3vw,32px);font-weight:900">از کتابخانه تا مسیر متمرکز</h2>
    <p class="lede" style="margin-top:8px">بعد از ورود، آموزش هر تمرین را کامل می‌خوانید و همان را در برنامهٔ ماه ثبت می‌کنید.</p>
  </div>
  <div class="home-spec-grid">
    <a class="card home-spec-card home-spec-a" href="<?php echo e(joma_url('index.php?p=learn&g=schema')); ?>">
      <div class="ico">📒</div>
      <h2>تمرینات تخصصی طرحواره درمانی</h2>
      <p>۹ تکنیک هسته (دفتر، فلش‌کارت، شواهد، مقابله، رفتار جدید، سه صندلی) و ۱۸ طرحوارهٔ انتخابی. آموزش قدم‌به‌قدم؛ ثبت کوتاه در جوما.</p>
      <div>
        <span class="chip">۲۷ تمرین</span>
        <span class="chip">آموزش عمیق</span>
      </div>
    </a>
    <a class="card home-spec-card home-spec-b" href="<?php echo e(joma_url('index.php?p=learn&g=couple')); ?>">
      <div class="ico">💗</div>
      <h2>تمرینات تخصصی زوج درمانی</h2>
      <p>گفت‌وگو، شنیدن، مرز، ترمیم، قرار هفتگی و اتصال روزانه — ۳۵ تمرین رابطه با راهنمای جلسه مانند.</p>
      <div>
        <span class="chip">۳۵ تمرین</span>
        <span class="chip">آموزش عمیق</span>
      </div>
    </a>
  </div>
</section>
<?php joma_footer(); ?>
