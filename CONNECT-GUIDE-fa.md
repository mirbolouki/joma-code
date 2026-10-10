# 🔗 راهنمای اتصال سایت mirbolouki.com به سامانهٔ نوبت‌دهی جوما

**تاریخ:** ۹ اکتبر ۲۰۲۶
**سطح سختی:** صفر — فقط با سی‌پنل (cPanel)، بدون نیاز به برنامه‌نویسی

---

## 🎯 هدف

وصل کردن صفحهٔ رزرو نوبت سایت (`mirbolouki.com/form/reservation`) به فرم درخواست نوبت اینترنتی سامانه:

```
https://my.mirbolouki.com/booking.php
```

---

## 🥇 روش اول: Redirect (پیشنهادی — ساده‌ترین و امن‌ترین)

**یعنی چی؟** هر کسی صفحهٔ قدیمی رزرو را باز کند، خودبه‌خود به فرم درخواست نوبت آنلاین می‌رود. مثل «تغییر آدرس» — همهٔ لینک‌های قدیمی خودکار درست کار می‌کنند.

### مراحل در سی‌پنل:

1. وارد سی‌پنل شو
2. در کادر جستجوی بالا تایپ کن: `Redirects` → رویش کلیک کن (بخش Domains)
3. روی دکمهٔ **Add Redirect** بزن
4. فرم را این‌طور پر کن:

| فیلد | مقدار |
|---|---|
| Type | **Permanent (301)** |
| دامنه | **mirbolouki.com** |
| مسیر (path) | `form/reservation` |
| Redirects to | `https://my.mirbolouki.com/booking.php` |
| www | «Redirect with or without www» |

5. روی **Add** بزن
6. تست: در مرورگر باز کن: `mirbolouki.com/form/reservation` → باید فرم نوبت (`my.mirbolouki.com/booking.php`) باز شود

### لغو (برگشت به حالت قبل):

سی‌پنل → Redirects → روبروی ریدایرکت روی **Delete** بزن.

---

## 🥈 روش دوم: اضافه کردن دکمه روی صفحهٔ قدیمی رزرو

**کی استفاده کنی؟** وقتی می‌خواهی صفحهٔ قدیمی بماند و فقط یک دکمهٔ «رزرو آنلاین» بالای آن اضافه شود.

⚠️ **اول از فایل کپی (بکاپ) بگیر!**

### مراحل:

1. سی‌پنل → **File Manager** → پوشهٔ `public_html`
2. بالا سمت راست روی **Search** بزن و تایپ کن: `reservation`
   - فایل احتمالی: `form/reservation.php` یا `form/reservation.html` یا پوشهٔ `form/reservation/` با فایل `index.php` داخلش
3. **بکاپ**: راست‌کلیک روی فایل → **Copy** → نام را بگذار `reservation.php.backup` → Save
4. راست‌کلیک روی فایل اصلی → **Edit** (ویرایشگر کد باز می‌شود)
5. کلیدهای **Ctrl + F** را بزن و تایپ کن: `<form` → Enter
6. نشانگر را ابتدای همان خط `<form` ببر و **Enter** بزن (یک خط جدید ساخته می‌شود)
7. کد دکمه را از فایل `booking-button.html` کپی کن و اینجا بچسبان (Ctrl+V)
8. بالا سمت راست روی **Save Changes** بزن
9. تست: `mirbolouki.com/form/reservation` را باز کن → دکمهٔ آبی را بالای فرم می‌بینی

### کد دکمه (آمادهٔ کپی):

```html
<a href="https://my.mirbolouki.com/booking.php" style="display:inline-block;padding:15px 40px;background:#2E5090;color:#fff;text-decoration:none;border-radius:8px;font-size:18px;font-weight:bold;">📅 درخواست نوبت مشاوره</a>
```

⚠️ **اگر فایل reservation را پیدا نکردی** → صفحهٔ رزرو یک صفحهٔ Drupal است (محتوا داخل پایگاه داده ذخیره شده) → از **روش اول (Redirect)** استفاده کن و به فایل‌ها دست نزن.

---

## 🧪 تست نهایی (بعد از هر دو روش)

1. برو به `mirbolouki.com/form/reservation` (یا روی دکمه کلیک کن)
2. فرم را با شمارهٔ موبایل خودت پر کن → کد پیامکی می‌آید → وارد کن
3. صفحهٔ «✅ درخواست شما ثبت شد» + شناسهٔ پیگیری را می‌بینی
4. وارد `https://my.mirbolouki.com/login.php` شو (با حساب منشی)
5. منوی **📨 درخواست‌های نوبت** را باز کن → درخواست تو با وضعیت «تازه» آنجاست
6. (اختیاری) رویش کلیک کن → «تبدیل به پذیرش» → فرم پذیرش را کامل کن → نوبت ثبت کن

---

## 🆘 اگر مشکلی پیش آمد

| مشکل | راه‌حل |
|---|---|
| بعد از ویرایش فایل، سایت باز نمی‌شود | File Manager → فایل `.backup` را پیدا کن → فایل خراب را پاک کن → نام بکاپ را به نام اصلی تغییر بده |
| Redirect کار نمی‌کند | ۱-۲ دقیقه صبر کن؛ با **پنجرهٔ ناشناس** (Ctrl+Shift+N) تست کن (برای رد کردن کش مرورگر) |
| آدرس با اسلش آخر (`/form/reservation/`) ریدایرکت نمی‌شود | یک Redirect دوم اضافه کن با مسیر `form/reservation/` |
| دکمه ظاهرش به‌هم‌ریخته است | مهم نیست — لینک کار می‌کند |

---

## ✅ انتخاب روش

- می‌خواهی **خیلی ساده و امن** باشد → **روش اول (Redirect)** ⭐
- می‌خواهی **صفحهٔ قدیمی بماند + دکمه اضافه شود** → **روش دوم** (فقط اگر فایل را پیدا کردی)

---

## 🚨 عیب‌یابی: ریدایرکت اجرا نمی‌شود (سایت Drupal)

### تشخیص (بر اساس اسکرین‌شات سی‌پنل):

1. ریدایرکت در سی‌پنل **وجود دارد** ✅ — `mirbolouki.com` → `/form/reservation` → `https://my.mirbolouki.com/booking.php` (301)
2. **ولی تیک «Wild Card Redirect» خورده** ❌ — با این تیک، ریدایرکت فقط برای `/form/reservation/xxx` کار می‌کند و برای `/form/reservation` (بدون اسلش) اجرا نمی‌شود
3. سایت **Drupal** است ❌ — `.htaccess`ِ Drupal همهٔ آدرس‌ها را به `index.php` می‌فرستد و روی سرورهای LiteSpeed، ریدایرکت سی‌پنل اصلاً فرصت اجرا پیدا نمی‌کند

### راه‌حل تضمینی (یک خط در `.htaccess`):

**قدم ۱ — پاک کردن ریدایرکت سی‌پنل:**
- سی‌پنل → Redirects → **Delete** روی ردیف `mirbolouki.com /form/reservation`
- ⚠️ ردیف `(.+)` را پاک نکن (احتمالاً قانون هاست است)

**قدم ۲ — اضافه کردن یک خط به `.htaccess`:**
1. File Manager → `public_html`
2. Settings → تیک «Show Hidden Files» → Save
3. `.htaccess` → Copy → `.htaccess-backup` (بکاپ!)
4. `.htaccess` → Edit
5. زیر خط `RewriteEngine on` اضافه کن:
```
RewriteRule ^form/reservation/?$ https://my.mirbolouki.com/booking.php [R=301,L]
```
6. Save Changes

**قدم ۳ — تست:**
- Purge All (اگر LiteSpeed Cache هست)
- پنجرهٔ ناشناس → تست هر دو آدرس:
  - `mirbolouki.com/form/reservation`
  - `mirbolouki.com/form/reservation/`

**اگر سایت خطای 500 داد:** `.htaccess` را پاک کن → `.htaccess-backup` را به `.htaccess` تغییر نام بده.

### چرا این روش کار می‌کند:
- خط داخل موتور بازنویسی Drupal است و قبل از قانون «catch-all» اجرا می‌شود
- `/?$` هر دو نسخهٔ آدرس (با/بی اسلش) را پوشش می‌دهد
- ریدایرکت سی‌پنل حذف شد → تداخل ندارد
