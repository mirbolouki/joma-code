# جوما — پیوست ب: مشخصات اجرایی فاز ۱ (خلاصه)

## ۱. ساختار نهایی پوشه

```
public_html/
├─ includes/ (۱۶ فایل PHP)
│  ├─ bootstrap.php
│  ├─ config.php.example
│  ├─ db.php
│  ├─ helpers.php
│  ├─ auth.php
│  ├─ validate.php
│  ├─ sms.php
│  ├─ otp_functions.php
│  ├─ person_functions.php
│  ├─ account_functions.php
│  ├─ staff_functions.php
│  ├─ admission_functions.php
│  ├─ case_functions.php
│  ├─ lookup_functions.php
│  ├─ audit_functions.php
│  └─ jalali.php
│
├─ templates/ (۶ فایل)
│  ├─ header.php
│  ├─ footer.php
│  ├─ sidebar.php
│  ├─ 403.php
│  ├─ 404.php
│  └─ error_generic.php
│
├─ admin/ (۴ فایل)
│  ├─ index.php
│  ├─ user_create.php
│  ├─ users_list.php
│  └─ sms_settings.php
│
├─ reception/ (۲ فایل)
│  ├─ index.php
│  └─ admission_new.php
│
├─ therapist/ (۲ فایل)
│  ├─ index.php
│  └─ admission_review.php
│
├─ assets/
│  ├─ css/style.css
│  ├─ js/app.js
│  ├─ fonts/ (Vazirmatn + OFL)
│  └─ img/ (لوگو)
│
├─ database/phase1_schema.sql
├─ storage/logs/ (+ .htaccess + README.txt)
├─ install.php
├─ health.php
├─ login.php
├─ role_select.php
├─ logout.php
├─ change_password_forced.php
├─ forgot_password_staff.php
└─ index.php
```

## ۲. اسکیمای کامل SQL (۱۱ جدول)

[SQL schema کامل از فایل `phase1_schema.sql`]

## ۳. دادهٔ اولیهٔ Seed

**فهرست دلیل مراجعه:**
- rr_anxiety: اضطراب و نگرانی
- rr_depression: افسردگی
- rr_marital: مشکلات زناشویی
- rr_family: مشکلات خانوادگی
- rr_child: مشکلات کودک و نوجوان
- rr_assessment: ارزیابی روان‌شناختی
- rr_other: سایر

**فهرست دلیل عدم پذیرش:**
- dr_fit: عدم تناسب تخصصی
- dr_capacity: تکمیل ظرفیت
- dr_other: سایر

**انواع خدمات:**
- individual: مشاورهٔ فردی بزرگسال (۴۵ دقیقه)
- couple: زوج‌درمانی و خانواده (۶۰ دقیقه)
- premarital: مشاورهٔ پیش از ازدواج (۶۰ دقیقه)
- child: روان‌شناسی کودک و نوجوان (۴۵ دقیقه)
- assessment: ارزیابی و تفسیر تست (۶۰ دقیقه)

## ۴. امنیت

✓ Bcrypt برای رمز عبور
✓ CSRF Token روی تمام فرم‌ها
✓ SQL Injection prevention (Prepared Statements)
✓ XSS prevention (htmlspecialchars)
✓ OTP Rate Limiting (۳/۱۰min)
✓ Session timeout (۳۰ دقیقه)
✓ Audit log کامل
✓ .htaccess برای `includes/`, `database/`, `storage/logs/`, `templates/`

## ۵. تاریخ و زمان

- **ذخیره:** UTC (gmdate)
- **نمایش:** شمسی (تابع `to_jalali()` در `jalali.php`)

## ۶. محدودیت‌های نرخ

- **OTP:** ۳ درخواست در ۱۰ دقیقه برای هر شماره
- **ورود:** ۵ تلاش ناموفق = ۱۵ دقیقه قفل

## ۷. متغیرهای محیطی (config.php)

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'joma_clinic_db');
define('DB_USER', 'joma_user');
define('DB_PASS', 'password');
define('APP_BASE_URL', 'https://my-domain.com');
define('SMS_PROVIDER', 'dev'); // یا 'smsir'
define('SMSIR_API_KEY', '');
define('SMSIR_OTP_TEMPLATE_ID', '');
define('SMSIR_LINE', '');
define('OTP_TTL_SECONDS', 120);
define('OTP_CODE_LENGTH', 6);
```

---

**این پیوست برای توسعه‌دهندگان بعدی مرجع فنی است.**
