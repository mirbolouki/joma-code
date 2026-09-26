<?php
declare(strict_types=1);
session_start();
$demoUser = $_SESSION['joma_demo_user'] ?? null;
if (isset($_GET['logout'])) { unset($_SESSION['joma_demo_user']); header('Location: index.php'); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['login'])) {
    $u = trim($_POST['username'] ?? '');
    $p = trim($_POST['password'] ?? '');
    // demo accept any non-empty, role based on name
    if ($u !== '' && $p !== '') {
        $role = (strpos($u,'therapist')!==false || $u==='alice') ? 'therapist' : (strpos($u,'patient')!==false ? 'patient' : 'therapist');
        $_SESSION['joma_demo_user'] = ['name'=>$u,'role'=>$role];
        header('Location: index.php'); exit;
    }
}
$role = $demoUser['role'] ?? null;
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>جوما — نمایش login تا پورتال</title>
<style>
:root{--c:#0b5fff;--bg:#f6f8fb;--card:#fff;--muted:#667085;--ok:#067647;--warn:#b42318}
*{box-sizing:border-box}html,body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,IRANSans,Vazirmatn,Tahoma,sans-serif;background:var(--bg);color:#101828}
a{color:var(--c);text-decoration:none}
header{position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;z-index:10}
.wrap{max-width:840px;margin:0 auto;padding:16px}
.brand{display:flex;align-items:center;gap:10px;font-weight:800}
.brand i{width:36px;height:36px;border-radius:10px;background:var(--c);color:#fff;display:grid;place-items:center;font-style:normal}
.card{background:var(--card);border:1px solid #e5e7eb;border-radius:16px;padding:20px;box-shadow:0 1px 2px rgba(0,0,0,.06)}
.steps{display:flex;gap:8px;overflow:auto;padding:8px 0}
.step{flex:0 0 auto;display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;border:1px solid #e5e7eb;background:#fff;font-size:13px}
.step.active{border-color:var(--c);background:#eff4ff;color:var(--c);font-weight:700}
.step.ok{border-color:#abefc6;background:#ecfdf3;color:var(--ok)}
.btn{appearance:none;border:0;background:var(--c);color:#fff;padding:12px 16px;border-radius:12px;font-weight:700;width:100%;cursor:pointer}
.btn:disabled{opacity:.6;cursor:not-allowed}
.btn.sec{background:#fff;color:var(--c);border:1px solid var(--c)}
.input{width:100%;padding:12px 14px;border:1px solid #d0d5dd;border-radius:12px;background:#fff}
.label{font-size:13px;color:var(--muted);margin:6px 2px 6px 0;display:block}
.grid{display:grid;gap:16px}
@media(min-width:720px){.grid{grid-template-columns:1.2fr .8fr}}
.kv{display:flex;justify-content:space-between;border-bottom:1px dashed #e5e7eb;padding:10px 0;font-size:14px}
.badge{display:inline-block;padding:4px 8px;border-radius:999px;font-size:12px;font-weight:700}
.badge.ok{background:#ecfdf3;color:var(--ok);border:1px solid #abefc6}
.badge.wait{background:#fffaeb;color:#b54708;border:1px solid #fedf89}
.muted{color:var(--muted);font-size:13px}
.hint{background:#eff4ff;border:1px solid #c7d7fe;padding:12px;border-radius:12px;font-size:13px}
</style>
</head>
<body>
<header><div class="wrap" style="display:flex;justify-content:space-between;align-items:center">
<div class="brand"><i>ج</i><span>جوما — نمایشِ login تا پورتال</span></div>
<?php if($demoUser): ?><a href="?logout=1">خروج</a><?php endif; ?>
</div></header>
<main class="wrap" style="padding-top:18px">
<?php if(!$demoUser): ?>
<div class="grid">
<div class="card">
<h2 style="margin:0 0 6px">ورود نمایشی</h2>
<p class="muted" style="margin:0 0 14px">بدون دیتابیس واقعی — هر نام/رمزی را بزنید. برای دیدن نقش‌ها: <code>alice</code> درمانگر، <code>patient</code> بیمار.</p>
<form method="post">
<label class="label">نام کاربری</label>
<input class="input" name="username" placeholder="alice یا patient" required>
<label class="label">رمز</label>
<input class="input" name="password" type="password" placeholder="هر رمزی" required>
<div style="height:12px"></div>
<button class="btn" name="login" value="1">ورود</button>
<p class="muted" style="margin:10px 0 0">این فقط نمایشِ UI است — لاگین واقعی با `joma-core/auth.php` و دیتابیسِ واقعی در نسخهٔ بعدی وصل می‌شود.</p>
</form>
</div>
<div class="card">
<h3 style="margin:0 0 8px">مسیر ۴ قدم</h3>
<div class="steps" style="flex-wrap:wrap">
<span class="step active">۱ ورود</span><span class="step">۲ ساخت پرونده</span><span class="step">۳ رزرو ۱۵دقیقه</span><span class="step">۴ پورتال</span>
</div>
<div class="hint">نسخهٔ نمایشی با دادهٔ ساختگی است. هاستِ شما ۱۴ تستِ دیتابیس را PASS کرد — اینجا فقط ظاهرِ موبایل-اول را می‌بینید.</div>
<ul class="muted" style="margin:12px 0 0;padding:0 18px">
<li>RTL، یک CTA اصلی، حالت‌های خالی/خطا/عدم دسترسی</li>
<li>بدون نیاز به `config` — فقط آپلود و باز کردن</li>
<li>برای اتصال واقعی: `joma-demo-config.php` بسازید (اختیاری)</li>
</ul>
</div>
</div>
<?php else: ?>
<div class="card" style="margin-bottom:12px">
<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
<div><b>سلام <?=htmlspecialchars($demoUser['name'],ENT_QUOTES,'UTF-8')?></b> <span class="badge <?= $role==='therapist'?'ok':'wait'?>"><?= $role==='therapist'?'درمانگر':'بیمار'?></span></div>
<div class="muted">نقش نمایشی — سندِ مادر ۴۰بخش</div>
</div>
<div class="steps">
<span class="step ok">۱ ورود ✓</span><span class="step active">۲ پرونده</span><span class="step">۳ رزرو</span><span class="step">۴ پورتال</span>
</div>
</div>

<div class="card" id="caseListCard">
<div style="display:flex;justify-content:space-between;align-items:center">
<h3 style="margin:0">📁 پرونده‌های من</h3>
<span class="muted">۳ پرونده — طبق سند: فردی/زوج/کودک</span>
</div>
<div style="height:10px"></div>
<div style="display:grid;gap:10px">
<div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;cursor:pointer" onclick="document.getElementById('caseDetail').scrollIntoView({behavior:'smooth'})">
<div style="display:flex;justify-content:space-between"><b>پرونده #C-1001 — خانم احمدی</b><span class="badge ok">ACTIVE</span></div>
<div class="muted" style="margin:4px 0">فردی بزرگسال · هدف: اضطراب · درمانگر مسئول: alice · امروز</div>
<div class="muted">یک CTA: دیدن پرونده → رزرو/یادداشت/گزارش</div>
</div>
<div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;opacity:.9;cursor:pointer" onclick="alert('نمایشی: پروندهٔ زوج — افراد: آقای حسینی + خانم حسینی — یک پرونده، دو شرکت‌کننده، یک درمانگرِ مسئول')">
<div style="display:flex;justify-content:space-between"><b>پرونده #C-1002 — حسینی (زوج)</b><span class="badge ok">ACTIVE</span></div>
<div class="muted" style="margin:4px 0">زوج · هدف: تعارضِ زوجی · شرکت‌کنندگان: ۲ نفر · جلسهٔ فردیِ درونِ زوج با همین پرونده</div>
</div>
<div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;opacity:.7">
<div style="display:flex;justify-content:space-between"><b>پرونده #C-1003 — کودک علی (۹ ساله)</b><span class="badge wait" style="background:#fee4e2;color:#b42318;border-color:#fecdc2">CLOSED</span></div>
<div class="muted" style="margin:4px 0">کودک · ولی: مادر (نماینده/پرداخت) · مستند: مادر پاسخ‌دهنده است نه شرکت‌کنندهٔ بالینی</div>
<div class="muted">بسته شده — باز نمی‌شود؛ بازگشت = پروندهٔ جدید (سند)</div>
</div>
</div>
<div class="hint" style="margin-top:12px">طبق سند: پرونده مرزِ هدف+بافت+افراد+رابطه است، نه برچسبِ خدمت. تغییرِ درمانگر = پروندهٔ جدید با حفظِ تاریخچه.</div>
</div>

<div style="height:12px"></div>
<div class="grid">
<div class="card" style="border:2px solid var(--c)">
<h3 style="margin:0 0 4px">📁 ساخت پرونده — پذیرش</h3>
<p class="muted" style="margin:0 0 12px">در جوما «پذیرش = ساخت پرونده». با یک کلیک، <b>Relationship + Case (پرونده)</b> با هم و اتمیک ساخته می‌شود — نه جدا.</p>
<div class="hint" style="margin-bottom:10px">پرونده = هدف + بافت + افراد + رابطهٔ درمانی — نه برچسبِ خدمت. یک درمانگرِ مسئول / پرونده.</div>
<div class="kv"><span>درخواستِ پذیرش</span><b>#A-9001 — AWAITING_THERAPIST</b></div>
<div class="kv"><span>متقاضی</span><b>خانم احمدی — فردی بزرگسال</b></div>
<div class="kv"><span>درمانگر مسئول</span><b>alice (شما)</b></div>
<div class="kv"><span>بافت/هدف</span><b>درمان فردی — اضطراب</b></div>
<div class="kv"><span>وضعیت</span><span class="badge wait">پرونده هنوز ساخته نشده</span></div>
<div style="height:12px"></div>
<button class="btn" onclick="document.getElementById('accept').style.display='block';this.style.display='none'">✓ پذیرش و ساختِ پروندهٔ فعال</button>
<div id="accept" style="display:none">
<div class="hint" style="margin-bottom:10px">✓ پرونده ساخته شد — ۵ ردیفِ اتمیک: Relationship + Case + عضویت + کانتکست + Audit. از این به بعد <b>رزروِ ۱۵دقیقه و جلسات</b> فعال می‌شود.</div>
<div id="caseDetail" style="background:#ecfdf3;border:1px solid #abefc6;border-radius:12px;padding:12px">
<div style="display:flex;justify-content:space-between;align-items:center"><b>📁 پرونده #C-1001 — جزئیات</b><span class="badge ok">ACTIVE</span></div>
<div class="kv" style="border:0;padding:6px 0 0"><span>شماره پرونده</span><b>C-1001 / R-9001</b></div>
<div class="kv" style="border:0;padding:4px 0"><span>تاریخ ساخت</span><b>امروز — توسط alice</b></div>
<div class="kv" style="border:0;padding:4px 0"><span>افرادِ پرونده</span><b>خانم احمدی (مراجع) + alice (مسئول)</b></div>
<div class="kv" style="border:0;padding:4px 0"><span>یادداشتِ خصوصی</span><span class="muted">فقط نویسنده می‌بیند — حتی مدیر هم نه</span></div>
<div class="kv" style="border:0;padding:4px 0 0"><span>وضعیتِ مالی/رضایت</span><span class="muted">دروازهٔ خدمت — نه شرطِ ساختِ پرونده</span></div>
</div>
<div style="height:10px"></div>
<button class="btn sec" onclick="document.getElementById('holdCard').scrollIntoView({behavior:'smooth'})">رفتن به رزروِ پرونده</button>
</div>
</div>
<div class="card" style="border:1px dashed #d0d5dd">
<h3 style="margin:0 0 8px">📝 یادداشتِ خصوصی — فقط نویسنده</h3>
<p class="muted" style="margin:0 0 10px">حتی مدیر با نقشِ درمانی هم نمی‌بیند؛ بیمار/ولی/درمانگرِ دیگر هم نه. جدا از گزارش.</p>
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:12px">
<b>یادداشتِ خصوصیِ alice — امروز ۱۰:۳۰</b>
<div class="muted" style="margin:6px 0">«مراجع اضطرابِ موقعیتی گزارش کرد...»</div>
<span class="badge wait">Author-only</span>
</div>
<div style="height:8px"></div>
<button class="btn sec" onclick="alert('نمایشی: تلاشِ بیمار/مدیر برای دیدنِ این یادداشت → 404 یکنواخت (joma-core/files.php + portal)')">تلاشِ بیمار برای دیدن → باید 404</button>
</div>
<div class="card">
<h3 style="margin:0 0 8px">📄 گزارش — انتشار با Audience صریح</h3>
<p class="muted" style="margin:0 0 10px">Draft فقط برای درمانگر؛ انتشار با Audience صریح برای بیمار.</p>
<div class="kv"><span>گزارش #R-201</span><span class="badge wait">DRAFT</span></div>
<div class="kv"><span>دسترسی بیمار</span><span class="muted">ندارد</span></div>
<div style="height:8px"></div>
<button class="btn sec" onclick="alert('نمایشی: در واقعی joma-core/portal.php با JOIN انتشار+Audience چک می‌کند')">تلاش برای دانلود (باید 404 بدهد)</button>
<div style="height:10px"></div>
<div class="kv"><span>گزارش #R-202</span><span class="badge ok">PUBLISHED — AUDIENCE: patient</span></div>
<div class="kv"><span>دسترسی بیمار</span><span class="badge ok">دارد</span></div>
<button class="btn" onclick="alert('نمایشی: دانلود با هدرهای private,no-store و ETag')">دانلود مجاز (نمایشی)</button>
</div>
</div>

<div class="card" id="holdCard" style="margin-top:16px">
<h3 style="margin:0 0 8px">📅 نوبت و جلسه — ۱:۱ اختیاری</h3>
<p class="muted" style="margin:0 0 10px">Session ↔ Appointment اختیاریِ ۱:۱؛ جلسهٔ بدون نوبت با Case/درمانگرِ معتبر مجاز است؛ یک نوبت چند جلسه ندارد.</p>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
<div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px"><b>۱۰:۰۰–۱۰:۱۵</b><div class="muted">نوبتِ hold — خالی</div><button class="btn" style="margin-top:8px" onclick="this.textContent='رزرو شد ✓';this.disabled=true;document.getElementById('holdOk').style.display='block';document.getElementById('sessionOk').style.display='block'">رزروِ نوبت</button></div>
<div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px;opacity:.6"><b>۱۰:۱۰–۱۰:۲۵</b><div class="muted">متداخل — باید رد شود</div><button class="btn sec" disabled>رد (CAPACITY_CONFLICT)</button></div>
</div>
<div id="holdOk" style="display:none;margin-top:12px" class="hint">✓ hold ساخته شد — انقضا ۱۵ دقیقه. در واقعی قفلِ سطر ۲ثانیه.</div>
<div id="sessionOk" style="display:none;margin-top:10px;background:#ecfdf3;border:1px solid #abefc6;border-radius:12px;padding:12px">
<b>جلسه #S-301 — پرونده #C-1001</b><div class="muted" style="margin:4px 0">بدون نوبت هم می‌شود؛ با نوبت، یک جلسه ↔ یک نوبت (nullable UNIQUE)</div>
<div class="kv" style="border:0;padding:4px 0"><span>وضعیت</span><span class="badge ok">برنامه‌ریزی شده</span></div>
</div>
</div>

<div class="card" style="margin-top:16px">
<h3 style="margin:0 0 6px">وضعیتِ فنیِ هاستِ شما</h3>
<div class="kv"><span>۱۴ تستِ سبک</span><span class="badge ok">۱۴/۱۴ PASS</span></div>
<div class="kv"><span>قفلِ سطر ۲ثانیه</span><span class="badge ok">PASS</span></div>
<div class="kv"><span>PHP هاست</span><span class="muted">7.1.33 — برای نسخهٔ نهایی 8.1 کنید</span></div>
<p class="muted" style="margin:10px 0 0">این نمایش بدون دیتابیس است. برای اتصال واقعی، فایل `joma-demo-config.php` را از `example` بسازید — خودکار به `joma-core` وصل می‌شود.</p>
</div>
<?php endif; ?>
<p class="muted" style="text-align:center;margin:18px 0">جوما — مرجعِ login تا فایل · نسخهٔ نمایشی · دادهٔ ساختگی</p>
</main>
</body>
</html>
