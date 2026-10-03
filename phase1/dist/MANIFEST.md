# 📦 فهرست فایل‌های بسته — جوما فاز ۱ نسخهٔ ۱.۰

## الف) فایل‌هایی که روی هاست آپلود می‌شوند (`public_html/`)

### ریشه — ۸ فایل
| فایل | کار |
|---|---|
| `install.php` | نصب‌کنندهٔ سه مرحله‌ای — **پس از نصب حذف شود** |
| `health.php` | خودآزمون سلامت نصب — پس از بررسی حذف شود |
| `index.php` | هدایت به داشبورد نقش یا صفحهٔ ورود |
| `login.php` | ورود پرسنل (تب «مراجع» غیرفعال) |
| `logout.php` | خروج |
| `role_select.php` | انتخاب نقش برای کاربران چندنقشی |
| `change_password_forced.php` | تغییر اجباری رمز در نخستین ورود |
| `forgot_password_staff.php` | بازیابی رمز با کد پیامکی |

### `admin/` — ۴ فایل
`index.php` (داشبورد با ۳ کارت آماری) · `user_create.php` (ساخت کاربر با ۴ نقش) · `users_list.php` (فهرست و فعال/غیرفعال‌سازی) · `sms_settings.php` (ویرایش کلید API و شناسهٔ قالب)

### `reception/` — ۲ فایل
`index.php` (پذیرش‌های ردشده + ۲۰ ردیف اخیر) · `admission_new.php` (ثبت پذیرش دو مرحله‌ای با جست‌وجوی موبایل)

### `therapist/` — ۲ فایل
`index.php` (کارتابل 🔴 در انتظار / 🟢 پرونده‌های من) · `admission_review.php` (تصمیم پذیرش یا عدم پذیرش)

### `includes/` — ۱۶ فایل + `.htaccess`
`bootstrap.php` · `config.php.example` · `db.php` · `helpers.php` · `auth.php` · `validate.php` · `sms.php` · `otp_functions.php` · `person_functions.php` · `account_functions.php` · `staff_functions.php` · `admission_functions.php` · `case_functions.php` · `lookup_functions.php` · `audit_functions.php` · `jalali.php`

### `templates/` — ۶ فایل + `.htaccess`
`header.php` · `footer.php` · `sidebar.php` · `403.php` · `404.php` · `error_generic.php`

### `database/` — ۱ فایل + `.htaccess`
`phase1_schema.sql` — تعریف ۱۱ جدول (نصب‌کننده خودش آن را اجرا می‌کند)

### `assets/`
`css/style.css` · `js/app.js` · `fonts/Vazirmatn-Regular.woff2` · `fonts/Vazirmatn-Bold.woff2` · `fonts/Vazirmatn-LICENSE.txt` · `img/logo.jpg` · `img/joma-owl-mirbolouki.jpg`

### `storage/logs/`
`.htaccess` (مسدودسازی دسترسی وب) · `README.txt` (توضیح + نگه‌داشتن پوشه در ZIP)

> **۴ فایل `.htaccess`** در `includes/`، `database/`، `templates/` و `storage/logs/` قرار دارند. این فایل‌ها مخفی‌اند؛ در File Manager گزینهٔ «Show Hidden Files» را فعال کنید تا دیده شوند.

---

## ب) مستندات (آپلود نمی‌شوند)

| فایل | محتوا |
|---|---|
| `README.md` | معرفی، پیش‌نیازها، نصب در ۵ گام |
| `INSTALLATION_GUIDE.md` | راهنمای کامل گام‌به‌گام برای فرد غیرفنی |
| `INSTALLATION_GUIDE_FA.html` | همان راهنما، قابل باز شدن در مرورگر |
| `TECH_SPECS.md` | مشخصات فنی، اسکیما، امنیت، ظرفیت |
| `CONFIG_TEMPLATE.txt` | توضیح تک‌تک تنظیمات `config.php` |
| `SUPPORT_CONTACT.txt` | اطلاعات تماس (با مقدارهای جایگزین، برای تکمیل مالک) |
| `VERSION.txt` | نسخه، محتوا، نقشهٔ راه |
| `MANIFEST.md` | همین فایل |
| `docs/PHASE1_FINAL_DECISIONS.md` | تصمیم‌ها، انحراف‌ها و محدودیت‌های شناخته‌شده |

---

## ج) چه چیزی در بسته **نیست**

- فایل `includes/config.php` واقعی (نصب‌کننده می‌سازد)
- `vendor/`، `composer.json`، `package.json`
- هرگونه بارگذاری از CDN یا دامنهٔ بیرونی
- `logo-bale.png`، `rubika.png`
- جدول `sms_outbox`، `users`، `test_results`، صفحهٔ `offer.php`
- پیش‌نمایش ایستای توسعه (`preview/`) و ابزارهای ساخت (`tools/`)
