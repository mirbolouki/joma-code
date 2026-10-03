# -*- coding: utf-8 -*-
"""سازندهٔ پیش‌نمایش استاتیک رابط کاربری جوما — فاز ۱
خروجی: phase1/preview/*.html  (فقط برای تأیید ظاهر؛ بخشی از بستهٔ تحویل نیست)
"""
import os, io

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(BASE, 'preview')
ASSETS = '../public_html/assets'

PREVIEW_BAR = (
    '<div style="background:#2C3E50;color:#fff;padding:8px 16px;font-size:13px;'
    'display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">'
    '<span>🔍 پیش‌نمایش استاتیک رابط کاربری — جوما فاز ۱ (داده‌ها نمونه است)</span>'
    '<a href="index.html" style="color:#9FC4F0">⬅ فهرست صفحات</a></div>'
)


def head(title, body_class=''):
    return f"""<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title} | جوما</title>
<link rel="stylesheet" href="{ASSETS}/css/style.css">
</head>
<body{(' class="' + body_class + '"') if body_class else ''}>
{PREVIEW_BAR}
"""


FOOT = f"""<script src="{ASSETS}/js/app.js"></script>
</body>
</html>
"""

SIDEBARS = {
    'admin': [
        ('📊 داشبورد', 'admin-index.html', ''),
        ('➕ ایجاد کاربر', 'admin-user_create.html', ''),
        ('👥 فهرست کاربران', 'admin-users_list.html', ''),
        ('📋 پذیرش‌ها (منشی)', 'reception-index.html', ''),
        ('📨 تنظیمات پیامک', 'admin-sms_settings.html', ''),
        ('📂 پرونده‌ها', '#', 'disabled'),
        ('⚙️ تنظیمات کلینیک', '#', 'disabled'),
    ],
    'reception': [
        ('📋 داشبورد', 'reception-index.html', ''),
        ('➕ ثبت پذیرش جدید', 'reception-admission_new.html', ''),
        ('📅 تقویم نوبت‌ها', '#', 'disabled'),
    ],
    'therapist': [
        ('🩺 کارتابل', 'therapist-index.html', ''),
        ('📂 پرونده‌های من', 'therapist-index.html#cases', ''),
        ('📅 تقویم من', '#', 'disabled'),
    ],
}

ROLE_LABEL = {'admin': 'مدیر', 'reception': 'منشی', 'therapist': 'درمانگر'}
ROLE_USER = {'admin': 'علی احمدی', 'reception': 'فرهاد رضایی', 'therapist': 'دکتر نوری'}


def header_bar(role):
    return f"""<header class="header">
  <div style="display:flex;align-items:center;gap:12px">
    <button class="menu-toggle" type="button" aria-label="باز کردن منو">☰</button>
    <a class="header-logo" href="{SIDEBARS[role][0][1]}">
      <img src="{ASSETS}/img/logo.jpg" alt="لوگوی کلینیک جوما">
      <span>کلینیک جوما</span>
    </a>
  </div>
  <div class="header-right">
    <span class="header-role">{ROLE_LABEL[role]}</span>
    <span class="header-user">⬤ {ROLE_USER[role]}</span>
    <a class="header-user" href="role_select.html" title="تغییر نقش">🔄 <span class="sr-only">تغییر نقش</span></a>
    <a class="header-user" href="login.html">🚪 خروج</a>
  </div>
</header>
"""


def sidebar(role, active):
    items = ''
    for label, href, cls in SIDEBARS[role]:
        klass = []
        if href == active:
            klass.append('active')
        if cls == 'disabled':
            klass.append('disabled-link')
            href = '#'
        c = (' class="%s"' % ' '.join(klass)) if klass else ''
        extra = ' <span class="badge badge-muted">فاز بعد</span>' if cls == 'disabled' else ''
        items += f'    <li><a href="{href}"{c}>{label}{extra}</a></li>\n'
    return f"""<nav class="sidebar" aria-label="منوی اصلی">
  <div class="sidebar-title">منوی {ROLE_LABEL[role]}</div>
  <ul>
{items}  </ul>
</nav>
"""


FOOTER_BAR = ('<footer class="footer">سامانهٔ مدیریت کلینیک جوما — نسخهٔ ۱.۰ (فاز ۱) '
              '| <a href="#">راهنما</a> | <a href="#">پشتیبانی</a></footer>')


def app_page(role, active, content, title):
    return (head(title) + '<div class="page">' + header_bar(role) +
            '<div class="container-with-sidebar">' + sidebar(role, active) +
            '<main class="content">' + content + '</main></div>' + FOOTER_BAR +
            '</div>' + FOOT)


def auth_page(title, inner, wide=False, brand_sub='سامانهٔ مدیریت مراجعات'):
    w = ' auth-card-wide' if wide else ''
    return (head(title) + f"""<div class="auth-page">
  <div class="auth-brand">
    <img src="{ASSETS}/img/logo.jpg" alt="لوگوی کلینیک جوما">
    <h1>کلینیک جوما</h1>
    <p>{brand_sub}</p>
  </div>
  <div class="auth-card{w}">
{inner}
  </div>
  <div class="auth-footer">نسخهٔ ۱.۰ | <a href="#">تماس با پشتیبانی</a></div>
</div>
""" + FOOT)


pages = {}

# ══════════════════════════ ۱) نصب‌کننده — مرحلهٔ ۱ ══════════════════════════
pages['install-1.html'] = auth_page('نصب — مرحلهٔ ۱', """
    <div class="steps">
      <div class="step active">۱) اتصال دیتابیس</div>
      <div class="step">۲) ساخت جدول‌ها</div>
      <div class="step">۳) مدیر سامانه</div>
    </div>
    <div class="alert alert-warning">
      <strong>⚠️ هشدار امنیتی</strong>
      این صفحه با پروتکل http باز شده است. توصیهٔ اکید می‌شود نصب را با https انجام دهید.
      <label class="checkbox mt-1"><input type="checkbox"> با وجود هشدار، ادامه می‌دهم.</label>
    </div>
    <h2 style="font-size:20px;color:var(--color-primary);margin-bottom:16px">اطلاعات دیتابیس</h2>
    <p class="text-muted text-small mb-3">این چهار مقدار را از بخش MySQL Databases در cPanel بردارید.</p>
    <form data-guard>
      <div class="form-group">
        <label for="db_host">میزبان دیتابیس <span class="required-star">*</span></label>
        <input type="text" id="db_host" value="localhost">
        <span class="form-hint">در اغلب هاست‌های اشتراکی همان localhost است.</span>
      </div>
      <div class="form-group">
        <label for="db_name">نام دیتابیس <span class="required-star">*</span></label>
        <input type="text" id="db_name" placeholder="mirbolouki_joma">
      </div>
      <div class="form-group">
        <label for="db_user">نام کاربری دیتابیس <span class="required-star">*</span></label>
        <input type="text" id="db_user" placeholder="mirbolouki_jomauser">
      </div>
      <div class="form-group">
        <label for="db_pass">رمز عبور دیتابیس <span class="required-star">*</span></label>
        <input type="password" id="db_pass">
      </div>
      <div class="form-group">
        <label for="base_url">نشانی سامانه</label>
        <input type="url" id="base_url" value="https://my.mirbolouki.com/joma" dir="ltr">
        <span class="form-hint">به‌صورت خودکار تشخیص داده شد؛ در صورت نیاز اصلاح کنید.</span>
      </div>
      <hr class="section-divider">
      <div class="form-group">
        <label>تنظیمات پیامک (برای بازیابی رمز عبور)</label>
        <select id="sms_provider" data-toggle-target="#smsirBox" data-toggle-value="smsir">
          <option value="smsir" selected>ارسال واقعی با سامانهٔ SMS.ir</option>
          <option value="dev">حالت آزمایشی (کد فقط در فایل لاگ نوشته می‌شود)</option>
        </select>
      </div>
      <div id="smsirBox" class="conditional-block">
        <div class="form-group">
          <label for="api_key">کلید API سامانهٔ SMS.ir</label>
          <input type="text" id="api_key" dir="ltr">
        </div>
        <div class="form-group">
          <label for="tpl">شناسهٔ قالب کد تأیید (Template ID)</label>
          <input type="text" id="tpl" dir="ltr" data-digits="en">
          <span class="form-hint">نام پارامتر قالب باید دقیقاً CODE باشد.</span>
        </div>
      </div>
      <button type="button" class="btn btn-primary btn-block">آزمایش اتصال و ادامه ⬅</button>
    </form>
""", wide=True, brand_sub='نصب سامانه — نسخهٔ ۱.۰')

# ══════════════════════════ نصب — مرحلهٔ ۲ ══════════════════════════
pages['install-2.html'] = auth_page('نصب — مرحلهٔ ۲', """
    <div class="steps">
      <div class="step done">۱) اتصال دیتابیس ✓</div>
      <div class="step active">۲) ساخت جدول‌ها</div>
      <div class="step">۳) مدیر سامانه</div>
    </div>
    <div class="alert alert-success"><strong>✓ اتصال به دیتابیس برقرار شد</strong>
      فایل تنظیمات <span class="mono">includes/config.php</span> با موفقیت ساخته شد.</div>
    <h2 style="font-size:20px;color:var(--color-primary);margin-bottom:16px">ساخت جدول‌ها و داده‌های اولیه</h2>
    <div class="table-wrap mb-3">
      <table class="table">
        <thead><tr><th>مورد</th><th>وضعیت</th></tr></thead>
        <tbody>
          <tr><td>۱۱ جدول سامانه</td><td><span class="status status-active">ساخته شد</span></td></tr>
          <tr><td>فهرست «دلیل مراجعه» (۷ گزینه)</td><td><span class="status status-active">ثبت شد</span></td></tr>
          <tr><td>فهرست «دلیل عدم پذیرش» (۳ گزینه)</td><td><span class="status status-active">ثبت شد</span></td></tr>
          <tr><td>۵ نوع خدمت کلینیک</td><td><span class="status status-active">ثبت شد</span></td></tr>
        </tbody>
      </table>
    </div>
    <a href="install-3.html" class="btn btn-primary btn-block">ادامه به ساخت حساب مدیر ⬅</a>
""", wide=True, brand_sub='نصب سامانه — نسخهٔ ۱.۰')

# ══════════════════════════ نصب — مرحلهٔ ۳ ══════════════════════════
pages['install-3.html'] = auth_page('نصب — مرحلهٔ ۳', """
    <div class="steps">
      <div class="step done">۱) اتصال دیتابیس ✓</div>
      <div class="step done">۲) ساخت جدول‌ها ✓</div>
      <div class="step active">۳) مدیر سامانه</div>
    </div>
    <h2 style="font-size:20px;color:var(--color-primary);margin-bottom:16px">ساخت حساب مدیر سامانه</h2>
    <form data-guard>
      <div class="form-section-title">اطلاعات شخصی</div>
      <div class="form-row">
        <div class="form-group">
          <label for="fn">نام <span class="required-star">*</span></label>
          <input type="text" id="fn">
        </div>
        <div class="form-group">
          <label for="ln">نام خانوادگی <span class="required-star">*</span></label>
          <input type="text" id="ln">
        </div>
      </div>
      <div class="form-group">
        <label for="mb">شمارهٔ موبایل <span class="required-star">*</span></label>
        <input type="tel" id="mb" dir="ltr" placeholder="09121234567" data-digits="en" maxlength="11">
        <span class="form-hint">کد بازیابی رمز به همین شماره پیامک می‌شود.</span>
      </div>
      <div class="form-section-title">اطلاعات حساب</div>
      <div class="form-group">
        <label for="un">نام کاربری <span class="required-star">*</span></label>
        <input type="text" id="un" dir="ltr" placeholder="admin">
        <span class="form-hint">فقط حروف کوچک انگلیسی، عدد و زیرخط — حداقل ۴ نویسه.</span>
      </div>
      <div class="form-group">
        <label for="pw">رمز عبور <span class="required-star">*</span></label>
        <input type="password" id="pw">
        <div data-strength-for="pw"></div>
        <span class="form-hint">حداقل ۸ نویسه، شامل حرف و عدد.</span>
      </div>
      <div class="form-group">
        <label for="pw2">تکرار رمز عبور <span class="required-star">*</span></label>
        <input type="password" id="pw2">
      </div>
      <button type="button" class="btn btn-success btn-block">پایان نصب و ساخت حساب مدیر ✓</button>
    </form>
    <div class="alert alert-info mt-3">
      پس از پایان نصب، فایل <span class="mono">install.lock</span> ساخته می‌شود و این صفحه دیگر باز نخواهد شد.
    </div>
""", wide=True, brand_sub='نصب سامانه — نسخهٔ ۱.۰')

# ══════════════════════════ ۲) ورود ══════════════════════════
pages['login.html'] = auth_page('ورود', """
    <div class="tabs" role="tablist">
      <button class="tab active" type="button" role="tab" aria-selected="true">پرسنل</button>
      <button class="tab disabled" type="button" role="tab" aria-selected="false" disabled>
        مراجع <small>به‌زودی فعال می‌شود</small>
      </button>
    </div>
    <form data-guard data-remember-username>
      <div class="form-group">
        <label for="login_identifier">نام کاربری</label>
        <input type="text" id="login_identifier" name="login_identifier" dir="ltr" autocomplete="username">
      </div>
      <div class="form-group">
        <label for="password">رمز عبور</label>
        <input type="password" id="password" name="password" autocomplete="current-password">
      </div>
      <label class="checkbox">
        <input type="checkbox" name="remember_username"> نام کاربری مرا به یاد داشته باش
      </label>
      <button type="button" class="btn btn-primary btn-block mt-2">ورود</button>
      <p class="text-center mt-2"><a href="forgot-1.html" class="text-small text-muted">رمز عبور خود را فراموش کرده‌ام</a></p>
    </form>
""")

pages['login-error.html'] = auth_page('ورود — خطا', """
    <div class="tabs" role="tablist">
      <button class="tab active" type="button">پرسنل</button>
      <button class="tab disabled" type="button" disabled>مراجع <small>به‌زودی فعال می‌شود</small></button>
    </div>
    <div class="alert alert-error">✗ نام کاربری یا رمز عبور نادرست است. (۲ تلاش ناموفق از ۵)</div>
    <form data-guard data-remember-username>
      <div class="form-group">
        <label for="login_identifier">نام کاربری</label>
        <input type="text" id="login_identifier" name="login_identifier" dir="ltr" value="secretary1" class="is-invalid">
      </div>
      <div class="form-group">
        <label for="password">رمز عبور</label>
        <input type="password" id="password" name="password" class="is-invalid">
      </div>
      <label class="checkbox"><input type="checkbox" name="remember_username" checked> نام کاربری مرا به یاد داشته باش</label>
      <button type="button" class="btn btn-primary btn-block mt-2">ورود</button>
      <p class="text-center mt-2"><a href="forgot-1.html" class="text-small text-muted">رمز عبور خود را فراموش کرده‌ام</a></p>
    </form>
""")

# ══════════════════════════ ۳) انتخاب نقش ══════════════════════════
pages['role_select.html'] = auth_page('انتخاب نقش', """
    <h2 style="font-size:20px;color:var(--color-primary);margin-bottom:8px">انتخاب نقش</h2>
    <p class="text-muted text-small mb-3">شما بیش از یک نقش دارید. با کدام نقش وارد می‌شوید؟
      در طول کار هم می‌توانید از دکمهٔ 🔄 در نوار بالا نقش را عوض کنید.</p>
    <form data-guard>
      <label class="radio"><input type="radio" name="role" checked> 🧑‍💼 مدیر</label>
      <label class="radio"><input type="radio" name="role"> 📋 منشی</label>
      <label class="radio"><input type="radio" name="role"> 🩺 درمانگر</label>
      <button type="button" class="btn btn-primary btn-block mt-3">ورود با این نقش</button>
    </form>
""")

# ══════════════════════════ ۴) تغییر اجباری رمز ══════════════════════════
pages['change_password_forced.html'] = auth_page('تغییر رمز عبور', """
    <div class="alert alert-warning">
      <strong>⚠️ باید رمز خود را تغییر دهید</strong>
      این نخستین ورود شماست. برای امنیت بیشتر، یک رمز قوی انتخاب کنید.
    </div>
    <form data-guard>
      <div class="form-group">
        <label for="cur">رمز فعلی</label>
        <input type="password" id="cur" autocomplete="current-password">
      </div>
      <div class="form-group">
        <label for="np">رمز جدید</label>
        <input type="password" id="np" autocomplete="new-password">
        <div data-strength-for="np"></div>
      </div>
      <div class="form-group">
        <label for="np2">تکرار رمز جدید</label>
        <input type="password" id="np2" autocomplete="new-password">
      </div>
      <button type="button" class="btn btn-primary btn-block">تغییر رمز</button>
    </form>
    <div class="alert alert-info mt-3">💡 نکته: حداقل ۸ نویسه، شامل حداقل یک حرف و یک عدد.</div>
""")

# ══════════════════════════ ۵) فراموشی رمز — دو مرحله ══════════════════════════
pages['forgot-1.html'] = auth_page('بازیابی رمز — مرحلهٔ ۱', """
    <h2 style="font-size:20px;color:var(--color-primary);margin-bottom:8px">بازیابی رمز عبور پرسنل</h2>
    <p class="text-muted text-small mb-3">نام کاربری خود را وارد کنید. کد تأیید ۶ رقمی به شمارهٔ موبایل ثبت‌شدهٔ شما پیامک می‌شود.</p>
    <form data-guard>
      <div class="form-group">
        <label for="u">نام کاربری</label>
        <input type="text" id="u" dir="ltr">
      </div>
      <button type="button" class="btn btn-primary btn-block">ارسال کد تأیید</button>
      <p class="text-center mt-2"><a href="login.html" class="text-small text-muted">بازگشت به صفحهٔ ورود</a></p>
    </form>
""")

pages['forgot-2.html'] = auth_page('بازیابی رمز — مرحلهٔ ۲', """
    <div class="alert alert-info">در صورت صحت نام کاربری، کد تأیید ارسال شد.</div>
    <form data-guard>
      <div class="form-group">
        <label for="code">کد تأیید ۶ رقمی</label>
        <input type="text" id="code" class="otp-input" maxlength="6" data-digits="en" inputmode="numeric">
        <span class="form-hint">کد تا ۲ دقیقه معتبر است. حداکثر ۵ بار می‌توانید آن را وارد کنید.</span>
      </div>
      <div class="form-group">
        <label for="n1">رمز عبور جدید</label>
        <input type="password" id="n1">
        <div data-strength-for="n1"></div>
      </div>
      <div class="form-group">
        <label for="n2">تکرار رمز عبور جدید</label>
        <input type="password" id="n2">
      </div>
      <button type="button" class="btn btn-primary btn-block">ثبت رمز جدید</button>
      <p class="countdown" data-countdown="90" data-countdown-enable="#resend"></p>
      <p class="text-center"><a href="#" id="resend" class="text-small">ارسال دوبارهٔ کد</a></p>
    </form>
""")

# ══════════════════════════ ۶) داشبورد مدیر ══════════════════════════
pages['admin-index.html'] = app_page('admin', 'admin-index.html', """
  <h1>📊 داشبورد مدیر</h1>
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-card-icon">👥</div>
      <div class="stat-card-value">۳</div>
      <div class="stat-card-label">کاربران پرسنلی فعال</div>
    </div>
    <div class="stat-card">
      <div class="stat-card-icon">📋</div>
      <div class="stat-card-value">۱۲</div>
      <div class="stat-card-label">پذیرش‌های امروز</div>
    </div>
    <div class="stat-card">
      <div class="stat-card-icon">📂</div>
      <div class="stat-card-value">۸</div>
      <div class="stat-card-label">پرونده‌های فعال</div>
    </div>
  </div>
  <div class="action-panel">
    <a href="admin-user_create.html" class="btn btn-primary">➕ ایجاد کاربر جدید</a>
    <a href="admin-users_list.html" class="btn btn-secondary">👥 فهرست کاربران</a>
    <a href="reception-index.html" class="btn btn-secondary">📋 پذیرش‌ها</a>
    <a href="admin-sms_settings.html" class="btn btn-secondary">📨 تنظیمات پیامک</a>
  </div>
  <div class="card">
    <div class="card-header">وضعیت سامانه</div>
    <div class="card-body">
      <div class="check-row"><span>تاریخ امروز</span><span class="fw-bold">شنبه ۱۱ مهر ۱۴۰۴</span></div>
      <div class="check-row"><span>وضعیت پیامک</span><span class="badge badge-success">فعال (SMS.ir)</span></div>
      <div class="check-row"><span>نسخهٔ سامانه</span><span class="badge badge-primary">۱.۰ — فاز ۱</span></div>
    </div>
  </div>
""", 'داشبورد مدیر')

# ══════════════════════════ ۷) ایجاد کاربر ══════════════════════════
USER_CREATE_FORM = """
  <h1>➕ ایجاد کاربر پرسنل جدید</h1>
  <div class="card">
    <div class="card-body">
      <form data-guard>
        <div class="form-section-title">اطلاعات شخصی</div>
        <div class="form-row">
          <div class="form-group">
            <label for="first_name">نام <span class="required-star">*</span></label>
            <input type="text" id="first_name">
          </div>
          <div class="form-group">
            <label for="last_name">نام خانوادگی <span class="required-star">*</span></label>
            <input type="text" id="last_name">
          </div>
        </div>
        <div class="form-group">
          <label for="mobile">شمارهٔ موبایل <span class="required-star">*</span></label>
          <input type="tel" id="mobile" dir="ltr" placeholder="09121234567" maxlength="11" data-digits="en">
        </div>
        <div class="form-group">
          <label for="nid">کد ملی (اختیاری)</label>
          <input type="text" id="nid" dir="ltr" maxlength="10" data-digits="en">
        </div>

        <div class="form-section-title">اطلاعات حساب</div>
        <div class="form-group">
          <label for="uname">نام کاربری <span class="required-star">*</span></label>
          <input type="text" id="uname" dir="ltr">
          <span class="form-hint">فقط حروف کوچک انگلیسی، عدد و زیرخط — حداقل ۴ نویسه.</span>
        </div>
        <div class="form-group">
          <label for="pass">رمز عبور اولیه <span class="required-star">*</span></label>
          <input type="password" id="pass">
          <div data-strength-for="pass"></div>
          <span class="form-hint">کاربر در نخستین ورود مجبور به تغییر آن می‌شود.</span>
        </div>

        <div class="form-section-title">نقش‌ها (می‌توانید چند مورد انتخاب کنید)</div>
        <div class="checkbox-list mb-3">
          <label class="checkbox"><input type="checkbox"> 🧑‍💼 مدیر</label>
          <label class="checkbox"><input type="checkbox" checked> 📋 منشی</label>
          <label class="checkbox"><input type="checkbox"> 🩺 درمانگر</label>
          <label class="checkbox"><input type="checkbox"> 🧪 روان‌سنج</label>
        </div>

        <div class="btn-row">
          <button type="button" class="btn btn-primary">ایجاد کاربر</button>
          <a href="admin-index.html" class="btn btn-secondary">انصراف</a>
        </div>
      </form>
    </div>
  </div>
"""
pages['admin-user_create.html'] = app_page('admin', 'admin-user_create.html', USER_CREATE_FORM, 'ایجاد کاربر')

pages['admin-user_create-duplicate.html'] = app_page('admin', 'admin-user_create.html', """
  <h1>➕ ایجاد کاربر پرسنل جدید</h1>
  <div class="alert alert-warning">
    <strong>ℹ️ این شمارهٔ موبایل قبلاً در سامانه ثبت شده است</strong>
    شمارهٔ <span class="mono">09121234567</span> متعلق به «سارا کریمی» است.
  </div>
  <div class="card">
    <div class="card-header card-header-light">آیا همان شخص است؟</div>
    <div class="card-body">
      <dl class="kv mb-3">
        <dt>نام ثبت‌شده</dt><dd>سارا کریمی</dd>
        <dt>شمارهٔ موبایل</dt><dd><span class="mono">09121234567</span></dd>
        <dt>وضعیت</dt><dd><span class="status status-active">فعال</span></dd>
        <dt>حساب کاربری</dt><dd>ندارد (فقط به‌عنوان شخص ثبت شده)</dd>
      </dl>
      <div class="btn-row">
        <button type="button" class="btn btn-success">بله، همین شخص است — حساب برایش بساز</button>
        <a href="admin-user_create.html" class="btn btn-secondary">خیر، اشتباه تایپی بود — اصلاح می‌کنم</a>
      </div>
    </div>
  </div>
""", 'ایجاد کاربر — شمارهٔ تکراری')

pages['admin-user_create-success.html'] = app_page('admin', 'admin-user_create.html', """
  <h1>➕ ایجاد کاربر پرسنل جدید</h1>
  <div class="alert alert-success">✓ کاربر «فرهاد رضایی» با موفقیت ساخته شد.</div>
  <div class="credentials-box">
    <strong>⚠️ این اطلاعات فقط همین یک‌بار نمایش داده می‌شود</strong>
    <p class="text-small">آن را یادداشت کنید و حضوری به کاربر تحویل دهید. رمز در سامانه قابل بازیابی نیست.</p>
    <dl>
      <dt>نام کاربری</dt><dd>farhad_r</dd>
      <dt>رمز عبور اولیه</dt><dd>Joma@1404</dd>
      <dt>نقش‌ها</dt><dd style="font-family:inherit;font-size:16px">منشی</dd>
    </dl>
    <p class="text-small">کاربر در نخستین ورود، به‌صورت خودکار به صفحهٔ تغییر رمز هدایت می‌شود.</p>
  </div>
  <div class="btn-row">
    <a href="admin-user_create.html" class="btn btn-primary">ایجاد کاربر دیگر</a>
    <a href="admin-users_list.html" class="btn btn-secondary">فهرست کاربران</a>
  </div>
""", 'ایجاد کاربر — موفق')

# ══════════════════════════ ۸) فهرست کاربران ══════════════════════════
rows = [
    ('علی احمدی', 'admin', 'مدیر', '09121111111', 'active', 'فعال'),
    ('فرهاد رضایی', 'farhad_r', 'منشی', '09122222222', 'active', 'فعال'),
    ('دکتر مریم نوری', 'dr_nouri', 'درمانگر', '09123333333', 'active', 'فعال'),
    ('سارا کریمی', 'sara_k', 'منشی، روان‌سنج', '09124444444', 'off', 'غیرفعال'),
]
trs = ''
for name, u, role, mob, st, stl in rows:
    btn = ('<button class="btn btn-sm btn-danger" data-confirm="آیا از غیرفعال‌کردن این کاربر مطمئن هستید؟ '
           'جلسهٔ فعال او بلافاصله بسته می‌شود.">غیرفعال‌سازی</button>'
           if st == 'active' else
           '<button class="btn btn-sm btn-success">فعال‌سازی</button>')
    trs += f"""        <tr>
          <td data-label="نام">{name}</td>
          <td data-label="نام کاربری"><span class="mono">{u}</span></td>
          <td data-label="نقش‌ها">{role}</td>
          <td data-label="موبایل"><span class="mono">{mob}</span></td>
          <td data-label="وضعیت"><span class="status status-{st}">{stl}</span></td>
          <td data-label="عملیات" class="col-actions">{btn}</td>
        </tr>\n"""

pages['admin-users_list.html'] = app_page('admin', 'admin-users_list.html', f"""
  <h1>👥 فهرست کاربران پرسنلی</h1>
  <div class="alert alert-success">✓ عملیات با موفقیت انجام شد.</div>
  <div class="action-panel"><a href="admin-user_create.html" class="btn btn-primary">➕ ایجاد کاربر جدید</a></div>
  <div class="card">
    <div class="table-wrap">
      <table class="table table-card">
        <thead>
          <tr><th>نام</th><th>نام کاربری</th><th>نقش‌ها</th><th>موبایل</th><th>وضعیت</th><th>عملیات</th></tr>
        </thead>
        <tbody>
{trs}        </tbody>
      </table>
    </div>
  </div>
""", 'فهرست کاربران')

# ══════════════════════════ ۹) تنظیمات پیامک ══════════════════════════
pages['admin-sms_settings.html'] = app_page('admin', 'admin-sms_settings.html', """
  <h1>📨 تنظیمات پیامک</h1>
  <div class="alert alert-info">
    این صفحه فقط کلیدهای سامانهٔ پیامک را در فایل تنظیمات به‌روز می‌کند؛ نیازی به ویرایش دستی فایل ندارید.
  </div>
  <div class="card">
    <div class="card-header">سامانهٔ ارسال پیامک</div>
    <div class="card-body">
      <form data-guard>
        <div class="form-group">
          <label for="prov">وضعیت ارسال</label>
          <select id="prov" data-toggle-target="#keys" data-toggle-value="smsir">
            <option value="smsir" selected>فعال — ارسال واقعی با SMS.ir</option>
            <option value="dev">آزمایشی — کد فقط در فایل لاگ نوشته می‌شود</option>
            <option value="off">غیرفعال — هیچ پیامکی ارسال نمی‌شود</option>
          </select>
          <span class="form-hint">در حالت غیرفعال، بازیابی رمز عبور پرسنل کار نخواهد کرد.</span>
        </div>
        <div id="keys" class="conditional-block">
          <div class="form-group">
            <label for="k">کلید API</label>
            <input type="text" id="k" dir="ltr" value="••••••••••••••••••••••••7Qx2">
            <span class="form-hint">برای حفظ مقدار فعلی، این فیلد را دست‌نخورده بگذارید.</span>
          </div>
          <div class="form-group">
            <label for="t">شناسهٔ قالب کد تأیید</label>
            <input type="text" id="t" dir="ltr" value="123456" data-digits="en">
            <span class="form-hint">نام پارامتر قالب باید دقیقاً CODE باشد.</span>
          </div>
        </div>
        <div class="btn-row">
          <button type="button" class="btn btn-primary">ذخیرهٔ تنظیمات</button>
          <button type="button" class="btn btn-secondary">ارسال پیامک آزمایشی به خودم</button>
        </div>
      </form>
    </div>
  </div>
""", 'تنظیمات پیامک')

# ══════════════════════════ ۱۰) داشبورد منشی ══════════════════════════
recent = [
    ('علی احمدی', 'مشاورهٔ فردی بزرگسال', 'دکتر نوری', '۱۴۰۴/۰۷/۱۱ — ۰۹:۲۰', 'waiting', 'در انتظار درمانگر'),
    ('فاطمه خسروی', 'زوج‌درمانی و خانواده', 'دکتر امینی', '۱۴۰۴/۰۷/۱۱ — ۱۰:۰۵', 'active', 'پذیرفته شد'),
    ('رضا مرادی', 'روان‌شناسی کودک و نوجوان', 'دکتر نوری', '۱۴۰۴/۰۷/۱۱ — ۱۱:۴۰', 'active', 'پذیرفته شد'),
    ('مریم سلطانی', 'ارزیابی و تفسیر تست بالینی', 'دکتر امینی', '۱۴۰۴/۰۷/۱۰ — ۱۶:۱۵', 'waiting', 'در انتظار درمانگر'),
]
rec_rows = ''
for n, s, t, d, st, stl in recent:
    rec_rows += f"""          <tr>
            <td data-label="مراجع">{n}</td>
            <td data-label="خدمت">{s}</td>
            <td data-label="درمانگر">{t}</td>
            <td data-label="تاریخ ثبت">{d}</td>
            <td data-label="وضعیت"><span class="status status-{st}">{stl}</span></td>
          </tr>\n"""

pages['reception-index.html'] = app_page('reception', 'reception-index.html', f"""
  <h1>📋 داشبورد منشی</h1>
  <div class="action-panel">
    <a href="reception-admission_new.html" class="btn btn-primary">➕ ثبت پذیرش جدید</a>
  </div>

  <h2>❌ پذیرش‌های ردشده — نیازمند ارجاع مجدد (۲ مورد)</h2>
  <div class="card">
    <div class="table-wrap">
      <table class="table table-card">
        <thead><tr><th>مراجع</th><th>خدمت</th><th>درمانگر قبلی</th><th>دلیل عدم پذیرش</th><th>ارجاع مجدد</th></tr></thead>
        <tbody>
          <tr>
            <td data-label="مراجع">محمد حسنی</td>
            <td data-label="خدمت">مشاورهٔ فردی بزرگسال</td>
            <td data-label="درمانگر قبلی">دکتر نوری</td>
            <td data-label="دلیل"><span class="badge badge-danger">عدم تناسب تخصصی</span></td>
            <td data-label="ارجاع مجدد">
              <form class="input-group" data-guard>
                <select aria-label="انتخاب درمانگر جدید">
                  <option>-- انتخاب درمانگر --</option>
                  <option>دکتر مریم نوری</option>
                  <option>دکتر سعید امینی</option>
                </select>
                <button type="button" class="btn btn-sm btn-primary">↻ ارجاع</button>
              </form>
            </td>
          </tr>
          <tr>
            <td data-label="مراجع">سارا جهانی</td>
            <td data-label="خدمت">زوج‌درمانی و خانواده</td>
            <td data-label="درمانگر قبلی">دکتر امینی</td>
            <td data-label="دلیل"><span class="badge badge-danger">تکمیل ظرفیت</span></td>
            <td data-label="ارجاع مجدد">
              <form class="input-group" data-guard>
                <select aria-label="انتخاب درمانگر جدید">
                  <option>-- انتخاب درمانگر --</option>
                  <option>دکتر مریم نوری</option>
                  <option>دکتر سعید امینی</option>
                </select>
                <button type="button" class="btn btn-sm btn-primary">↻ ارجاع</button>
              </form>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <h2 id="recent">📌 ۲۰ پذیرش اخیر</h2>
  <div class="card">
    <div class="table-wrap table-scroll">
      <table class="table table-card">
        <thead><tr><th>مراجع</th><th>خدمت</th><th>درمانگر</th><th>تاریخ ثبت</th><th>وضعیت</th></tr></thead>
        <tbody>
{rec_rows}        </tbody>
      </table>
    </div>
  </div>
""", 'داشبورد منشی')

# ══════════════════════════ ۱۱) ثبت پذیرش ══════════════════════════
pages['reception-admission_new.html'] = app_page('reception', 'reception-admission_new.html', """
  <h1>➕ ثبت پذیرش مراجع جدید</h1>

  <div class="card">
    <div class="card-header">مرحلهٔ ۱ — یافتن یا ایجاد مراجع</div>
    <div class="card-body">
      <form data-guard>
        <div class="form-group">
          <label for="mobile_number">شمارهٔ موبایل مراجع <span class="required-star">*</span></label>
          <div class="input-group">
            <input type="tel" id="mobile_number" dir="ltr" placeholder="09121234567"
                   maxlength="11" data-digits="en" inputmode="numeric">
            <button type="button" class="btn btn-secondary" id="btnLookup" data-endpoint="#">🔍 جست‌وجو</button>
          </div>
          <span class="form-hint">شمارهٔ موبایل، شناسهٔ اصلی مراجع در سامانه است.</span>
        </div>
        <div id="lookupResult" class="alert alert-info hidden"></div>
        <div id="personFields">
          <div class="form-row">
            <div class="form-group">
              <label for="first_name">نام <span class="required-star">*</span></label>
              <input type="text" id="first_name">
            </div>
            <div class="form-group">
              <label for="last_name">نام خانوادگی <span class="required-star">*</span></label>
              <input type="text" id="last_name">
            </div>
          </div>
          <div class="form-group mb-0">
            <label for="nid2">کد ملی (اختیاری)</label>
            <input type="text" id="nid2" dir="ltr" maxlength="10" data-digits="en">
          </div>
        </div>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-header">مرحلهٔ ۲ — جزئیات پذیرش</div>
    <div class="card-body">
      <form data-guard>
        <div class="form-group">
          <label for="svc">خدمت مورد نظر <span class="required-star">*</span></label>
          <select id="svc" data-toggle-target="#companionBox" data-toggle-value="couple">
            <option value="">-- انتخاب کنید --</option>
            <option value="individual" selected>مشاورهٔ فردی بزرگسال</option>
            <option value="couple">زوج‌درمانی و خانواده</option>
            <option value="premarital">مشاورهٔ پیش از ازدواج</option>
            <option value="child">روان‌شناسی کودک و نوجوان</option>
            <option value="assessment">ارزیابی و تفسیر تست بالینی</option>
          </select>
        </div>
        <div id="companionBox" class="form-group conditional-block hidden">
          <label for="comp">تعداد همراه</label>
          <input type="number" id="comp" min="1" max="10" value="1">
          <span class="form-hint">برای خدمات چندنفره، تعداد همراهان بین ۱ تا ۱۰ نفر.</span>
        </div>
        <div class="form-group">
          <label for="reason">دلیل مراجعه <span class="required-star">*</span></label>
          <select id="reason">
            <option value="">-- انتخاب کنید --</option>
            <option>اضطراب</option><option>افسردگی</option>
            <option>مشکلات زناشویی</option><option>مشکلات خانوادگی</option>
            <option>مشکلات کودک و نوجوان</option><option>ارزیابی روان‌سنجی</option>
            <option>سایر</option>
          </select>
        </div>
        <div class="form-group">
          <label for="th">درمانگر مسئول <span class="required-star">*</span></label>
          <select id="th">
            <option value="">-- انتخاب کنید --</option>
            <option>دکتر مریم نوری</option>
            <option>دکتر سعید امینی</option>
          </select>
          <span class="form-hint">پذیرش برای تصمیم‌گیری به کارتابل این درمانگر فرستاده می‌شود.</span>
        </div>
        <div class="form-group">
          <label for="note">توضیح کوتاه (اختیاری)</label>
          <textarea id="note" placeholder="یادداشت اداری کوتاه…"></textarea>
        </div>
        <div class="btn-row">
          <button type="button" class="btn btn-primary">ثبت پذیرش</button>
          <a href="reception-index.html" class="btn btn-secondary">انصراف</a>
        </div>
      </form>
    </div>
  </div>
""", 'ثبت پذیرش')

# ══════════════════════════ ۱۲) کارتابل درمانگر ══════════════════════════
pages['therapist-index.html'] = app_page('therapist', 'therapist-index.html', """
  <h1>🩺 کارتابل درمانگر</h1>

  <h2>📥 پذیرش‌های در انتظار تصمیم (۲ مورد)</h2>
  <div class="item-list mb-3">
    <div class="item-card">
      <div class="item-card-main">
        <div class="item-card-title"><span class="status status-waiting">علی احمدی</span></div>
        <div class="item-card-meta">
          <span>خدمت: مشاورهٔ فردی بزرگسال</span>
          <span>موبایل: <span class="mono">09121111111</span></span>
          <span>ثبت: ۱۴۰۴/۰۷/۱۱ — ۰۹:۲۰</span>
        </div>
      </div>
      <a href="therapist-admission_review.html" class="btn btn-primary">بررسی و تصمیم</a>
    </div>
    <div class="item-card">
      <div class="item-card-main">
        <div class="item-card-title"><span class="status status-waiting">فاطمه خسروی</span></div>
        <div class="item-card-meta">
          <span>خدمت: زوج‌درمانی و خانواده</span>
          <span>موبایل: <span class="mono">09129999999</span></span>
          <span>ثبت: ۱۴۰۴/۰۷/۱۱ — ۱۰:۰۵</span>
        </div>
      </div>
      <a href="therapist-admission_review.html" class="btn btn-primary">بررسی و تصمیم</a>
    </div>
  </div>

  <h2 id="cases">📂 پرونده‌های من (۲ مورد)</h2>
  <div class="item-list">
    <div class="item-card">
      <div class="item-card-main">
        <div class="item-card-title"><span class="status status-active">رضا مرادی</span></div>
        <div class="item-card-meta">
          <span>شمارهٔ پرونده: <span class="mono">cs_8f2a…</span></span>
          <span>تاریخ گشایش: ۱۴۰۴/۰۶/۲۵</span>
        </div>
      </div>
      <button class="btn btn-secondary" disabled>مشاهدهٔ پرونده — فاز بعد</button>
    </div>
    <div class="item-card">
      <div class="item-card-main">
        <div class="item-card-title"><span class="status status-active">نرگس بهرامی</span></div>
        <div class="item-card-meta">
          <span>شمارهٔ پرونده: <span class="mono">cs_3b71…</span></span>
          <span>تاریخ گشایش: ۱۴۰۴/۰۷/۰۲</span>
        </div>
      </div>
      <button class="btn btn-secondary" disabled>مشاهدهٔ پرونده — فاز بعد</button>
    </div>
  </div>
""", 'کارتابل درمانگر')

# ══════════════════════════ ۱۳) بررسی پذیرش ══════════════════════════
pages['therapist-admission_review.html'] = app_page('therapist', 'therapist-index.html', """
  <h1>🔍 بررسی پذیرش</h1>

  <div class="card">
    <div class="card-header">👤 اطلاعات مراجع</div>
    <div class="card-body">
      <dl class="kv">
        <dt>نام و نام خانوادگی</dt><dd>علی احمدی</dd>
        <dt>شمارهٔ موبایل</dt><dd><span class="mono">09121111111</span></dd>
        <dt>کد ملی</dt><dd class="text-muted">ثبت نشده</dd>
      </dl>
    </div>
  </div>

  <div class="card">
    <div class="card-header">📋 جزئیات پذیرش</div>
    <div class="card-body">
      <dl class="kv">
        <dt>خدمت درخواستی</dt><dd>مشاورهٔ فردی بزرگسال</dd>
        <dt>دلیل مراجعه</dt><dd>اضطراب</dd>
        <dt>تعداد همراه</dt><dd>بدون همراه</dd>
        <dt>ثبت‌کننده</dt><dd>فرهاد رضایی (منشی)</dd>
        <dt>تاریخ درخواست</dt><dd>۱۴۰۴/۰۷/۱۱ — ۰۹:۲۰</dd>
        <dt>وضعیت</dt><dd><span class="status status-waiting">در انتظار تصمیم شما</span></dd>
      </dl>
    </div>
  </div>

  <div class="card">
    <div class="card-header">🎯 تصمیم شما</div>
    <div class="card-body">
      <form data-guard>
        <label class="radio">
          <input type="radio" name="decision" checked data-toggle-target="#acceptNote"> ✅ پذیرش مسئولیت — پرونده گشوده شود
        </label>
        <label class="radio">
          <input type="radio" name="decision" data-toggle-target="#declineBox"> ❌ عدم پذیرش — بازگشت به منشی برای ارجاع مجدد
        </label>

        <div id="acceptNote" class="alert alert-info mt-2">
          با پذیرش، یک پروندهٔ بالینی فعال به نام شما گشوده می‌شود.
        </div>

        <div id="declineBox" class="form-group conditional-block mt-2 hidden">
          <label for="dr">دلیل عدم پذیرش <span class="required-star">*</span></label>
          <select id="dr">
            <option value="">-- انتخاب کنید --</option>
            <option>عدم تناسب تخصصی</option>
            <option>تکمیل ظرفیت</option>
            <option>سایر</option>
          </select>
        </div>

        <div class="btn-row mt-3">
          <button type="button" class="btn btn-success">تأیید و ثبت تصمیم</button>
          <a href="therapist-index.html" class="btn btn-secondary">بازگشت</a>
        </div>
      </form>
    </div>
  </div>
""", 'بررسی پذیرش')

# ══════════════════════════ ۱۴) صفحات خطا ══════════════════════════
pages['error-generic.html'] = auth_page('خطا', """
    <div class="error-page">
      <span class="error-icon">⚠️</span>
      <h1>خطایی رخ داده است</h1>
      <p>متأسفیم؛ درخواست شما کامل نشد. جزئیات فنی برای تیم پشتیبانی ثبت شد.</p>
      <div class="ref-code">REF-a1b2c3d4</div>
      <p class="text-small text-muted">اگر مشکل ادامه داشت، این کد پیگیری را به پشتیبانی اعلام کنید.</p>
      <a href="login.html" class="btn btn-primary mt-2">بازگشت به صفحهٔ اول</a>
    </div>
""", brand_sub='')

pages['error-403.html'] = auth_page('دسترسی مجاز نیست', """
    <div class="error-page">
      <span class="error-icon">🔒</span>
      <h1>دسترسی مجاز نیست</h1>
      <p>شما اجازهٔ دسترسی به این صفحه را ندارید.</p>
      <p class="text-small text-muted">اگر فکر می‌کنید اشتباهی رخ داده، با مدیر سامانه تماس بگیرید.</p>
      <a href="admin-index.html" class="btn btn-primary mt-2">بازگشت به داشبورد</a>
    </div>
""", brand_sub='')

pages['error-404.html'] = auth_page('صفحه یافت نشد', """
    <div class="error-page">
      <span class="error-icon">🔎</span>
      <h1>صفحه یافت نشد</h1>
      <p>نشانی‌ای که وارد کرده‌اید در سامانه وجود ندارد.</p>
      <a href="admin-index.html" class="btn btn-primary mt-2">بازگشت به داشبورد</a>
    </div>
""", brand_sub='')

pages['error-disabled.html'] = auth_page('حساب غیرفعال', """
    <div class="error-page">
      <span class="error-icon">⛔</span>
      <h1>حساب شما غیرفعال شده است</h1>
      <p>دسترسی شما توسط مدیر سامانه بسته شد و جلسهٔ کاری‌تان پایان یافت.</p>
      <p class="text-small text-muted">برای پیگیری با مدیر کلینیک تماس بگیرید.</p>
      <a href="login.html" class="btn btn-primary mt-2">بازگشت به صفحهٔ ورود</a>
    </div>
""", brand_sub='')

# ══════════════════════════ ۱۵) خودآزمون ══════════════════════════
checks = [
    ('اتصال به دیتابیس', 'ok', 'برقرار'),
    ('نسخهٔ PHP', 'ok', '8.1.27 (حداقل لازم: 7.4)'),
    ('نسخهٔ MySQL', 'ok', '10.11.6-MariaDB (حداقل لازم: 5.7.20)'),
    ('افزونهٔ mysqli', 'ok', 'فعال'),
    ('توابع json / openssl / curl', 'ok', 'هر سه در دسترس'),
    ('قابل نوشتن بودن storage/logs', 'ok', 'بله'),
    ('محافظت پوشهٔ includes', 'ok', 'دسترسی مستقیم بسته است'),
    ('محافظت پوشهٔ database', 'ok', 'دسترسی مستقیم بسته است'),
    ('فایل install.lock', 'ok', 'موجود — نصب‌کننده قفل است'),
    ('اتصال امن (HTTPS)', 'warn', 'صفحه با http باز شده است'),
    ('تنظیمات پیامک', 'ok', 'SMS.ir — کلید و شناسهٔ قالب ثبت شده'),
    ('۱۱ جدول سامانه', 'ok', 'همه موجودند'),
]
crows = ''
for label, st, val in checks:
    badge = ('<span class="badge badge-success">✓ سالم</span>' if st == 'ok'
             else '<span class="badge badge-warning">⚠ بررسی شود</span>')
    crows += (f'      <div class="check-row"><span class="fw-bold">{label}</span>'
              f'<span class="text-small text-muted">{val}</span>{badge}</div>\n')

pages['health.html'] = auth_page('خودآزمون سامانه', f"""
    <h2 style="font-size:20px;color:var(--color-primary);margin-bottom:8px">🩻 خودآزمون سامانه</h2>
    <p class="text-muted text-small mb-3">این صفحه وضعیت فنی نصب را بررسی می‌کند. پس از اطمینان از سلامت نصب،
      می‌توانید فایل <span class="mono">health.php</span> را حذف کنید.</p>
{crows}
    <div class="alert alert-info mt-3">نتیجهٔ کلی: سامانه آمادهٔ استفاده است (۱ هشدار غیربحرانی).</div>
""", wide=True, brand_sub='ابزار بررسی سلامت نصب')

# ══════════════════════════ فهرست پیش‌نمایش ══════════════════════════
INDEX_GROUPS = [
    ('نصب سامانه', [
        ('install-1.html', 'نصب — مرحلهٔ ۱: اتصال دیتابیس و تنظیمات پیامک'),
        ('install-2.html', 'نصب — مرحلهٔ ۲: ساخت جدول‌ها و داده‌های اولیه'),
        ('install-3.html', 'نصب — مرحلهٔ ۳: ساخت حساب مدیر'),
        ('health.html', 'خودآزمون سامانه (health.php)'),
    ]),
    ('ورود و حساب کاربری', [
        ('login.html', 'صفحهٔ ورود (حالت عادی)'),
        ('login-error.html', 'صفحهٔ ورود (حالت خطا)'),
        ('role_select.html', 'انتخاب نقش (کاربر چندنقشی)'),
        ('change_password_forced.html', 'تغییر اجباری رمز در نخستین ورود'),
        ('forgot-1.html', 'بازیابی رمز — مرحلهٔ ۱ (نام کاربری)'),
        ('forgot-2.html', 'بازیابی رمز — مرحلهٔ ۲ (کد ۶ رقمی)'),
    ]),
    ('مدیر', [
        ('admin-index.html', 'داشبورد مدیر'),
        ('admin-user_create.html', 'ایجاد کاربر پرسنل'),
        ('admin-user_create-duplicate.html', 'ایجاد کاربر — هشدار شمارهٔ تکراری'),
        ('admin-user_create-success.html', 'ایجاد کاربر — نمایش یک‌بارهٔ رمز'),
        ('admin-users_list.html', 'فهرست کاربران (فعال/غیرفعال)'),
        ('admin-sms_settings.html', 'تنظیمات پیامک'),
    ]),
    ('منشی', [
        ('reception-index.html', 'داشبورد منشی (ردشده‌ها + ۲۰ پذیرش اخیر)'),
        ('reception-admission_new.html', 'ثبت پذیرش جدید (دو مرحله)'),
    ]),
    ('درمانگر', [
        ('therapist-index.html', 'کارتابل درمانگر'),
        ('therapist-admission_review.html', 'بررسی پذیرش و ثبت تصمیم'),
    ]),
    ('حالت‌های خطا', [
        ('error-generic.html', 'خطای عمومی با کد پیگیری'),
        ('error-403.html', 'دسترسی مجاز نیست (۴۰۳)'),
        ('error-404.html', 'صفحه یافت نشد (۴۰۴)'),
        ('error-disabled.html', 'حساب غیرفعال‌شده (خروج آنی)'),
    ]),
]

groups_html = ''
for gtitle, items in INDEX_GROUPS:
    lis = ''.join(
        f'<li style="padding:10px 0;border-bottom:1px solid var(--color-border)">'
        f'<a href="{href}">{label}</a></li>' for href, label in items)
    groups_html += (f'<div class="card"><div class="card-header">{gtitle}</div>'
                    f'<div class="card-body"><ul style="list-style:none">{lis}</ul></div></div>')

with io.open(os.path.join(OUT, 'index.html'), 'w', encoding='utf-8') as f:
    f.write(head('پیش‌نمایش رابط کاربری') + f"""
<div style="max-width:900px;margin:0 auto;padding:32px 16px">
  <div class="auth-brand">
    <img src="{ASSETS}/img/logo.jpg" alt="لوگوی کلینیک جوما" style="width:100px;border-radius:6px;background:#fff">
    <h1>پیش‌نمایش رابط کاربری — جوما فاز ۱</h1>
    <p>۲۳ صفحه و حالت مختلف، مطابق پیوست ج و د. داده‌ها نمونه است و هیچ عملیاتی واقعی نیست.</p>
  </div>
  <div class="alert alert-info">
    برای آزمودن حالت موبایل، پنجرهٔ مرورگر را باریک کنید؛ منو به حالت همبرگری و جدول‌ها به کارت تبدیل می‌شوند.
  </div>
{groups_html}
  <div class="footer" style="border-radius:6px">سامانهٔ مدیریت کلینیک جوما — نسخهٔ ۱.۰ (فاز ۱)</div>
</div>
""" + FOOT)

for name, html in pages.items():
    with io.open(os.path.join(OUT, name), 'w', encoding='utf-8') as f:
        f.write(html)

print('ساخته شد: %d صفحه' % (len(pages) + 1))
