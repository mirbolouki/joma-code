# بررسی دوم — سند «تصمیم نهایی، بازبینی و تصحیح»

**تاریخ:** ۱۴۰۵/۰۷/۱۴ · **روش:** مقابلهٔ هر ادعا با اسکیما و کد زنده.

---

## ۰. حکم

| موضوع | حکم |
|---|---|
| پذیرش سه ایراد بحرانی و برگرداندن تصمیم ۲ | ✅ **درست و ارزشمند** |
| نام‌گذاری ۴.۲.۰ و «فاز ۲ وابستگی نیست» | ✅ **تأیید** |
| ادعای «R11 حل شد» | ❌ **حل نشده. بار دوم.** |
| ادعای «۱۷ نکتهٔ اصلاح در میانه» | ❌ از ۱۴ ایراد ساختاری، **۱ مورد** اصلاح شده |
| جدول پیشنهادی | ⚠️ **دو ایراد تازهٔ بحرانی** + یک پس‌رفت جدی |

سند دور قبل را درست فهمیده و شجاعانه برگشته. ولی **کد تقریباً دست‌نخورده
کپی شده** و دو مشکل تازه هم وارد شده است.

---

## ۱. ایرادهای تازهٔ بحرانی

### ج-۱ — کد تأیید به‌صورت **متن خام** ذخیره می‌شود

```sql
otp_verification_code VARCHAR(6),
```

سامانه از فاز ۱ کد را **هرگز خام نگه نمی‌دارد**:

```php
$code_hash = password_hash($code, PASSWORD_BCRYPT);   // otp_functions.php:116
```

ستون پیشنهادی یعنی کدهای تأیید **زنده** در یک جدول دوم، بدون رمز، در
دسترس هر نسخهٔ پشتیبان و هر خطای نشت داده. ضمناً دو منبع حقیقت برای یک
چیز می‌سازد.

**درست:** این ستون حذف شود. `otp_codes` از قبل این کار را امن انجام می‌دهد
و `otp_verify()` هم موجود است.

### ج-۲ — سقف IP باز هم کار نمی‌کند؛ **ستونی که می‌خواند وجود ندارد**

```php
SELECT COUNT(*) FROM otp_codes WHERE ip_address = ? AND purpose = 'BOOKING_REQUEST' ...
```

ستون‌های واقعی `otp_codes`:

```
id · mobile_number · code_hash · purpose · expires_at · attempts · consumed_at · created_at
```

**`ip_address` وجود ندارد.** این پرس‌وجو خطای SQL می‌دهد.

پس جملهٔ «✅ R11 حل شد» نادرست است. دور قبل سقف روی جدول اشتباه بود؛ این
بار روی ستون ناموجود است. **R11 هنوز باز است.**

و حتی اگر ستون اضافه شود، **سقف فقط به ازای IP کافی نیست**: یک ربات با
چرخاندن IP آن را دور می‌زند. **سقف کل روزانه** هم لازم است — همان که در
سند طراحی به‌صورت سطل `global:<روز>` آمده بود و اینجا حذف شده.

---

## ۲. یک پس‌رفت جدی نسبت به نسخهٔ قبل خودِ سند

### ج-۳ — ستون‌های پیگیری تبدیل **حذف شده‌اند**

نسخهٔ قبل این سه ستون را داشت:

```sql
converted_to_admission_id, converted_by_person_id, converted_at
```

نسخهٔ تازه هر سه را برداشته و افتخار می‌کند: «✅ هیچ FK به `admissions`».

**این سوءبرداشت از نقد من است.** حرف من این بود که جدول نباید FK به
`persons` داشته باشد، چون آلودگی نباید به جدول هویت بالینی سرایت کند.
`converted_admission_id` دقیقاً برعکس است: **تنها پل کنترل‌شده** میان دو
دنیا، که فقط پس از تصمیم انسانی پر می‌شود.

با حذف آن:

- معلوم نیست کدام درخواست به کدام پذیرش تبدیل شد؛
- **نرخ تبدیل — که هدف اعلام‌شدهٔ کل پروژه بود — قابل محاسبه نیست؛**
- وضعیت `CONVERTED` در ENUM می‌ماند ولی هیچ‌جا نمی‌گوید به چه چیزی.

ضمناً `rejection_by_person_id` اضافه شده ولی `converted_by_person_id` نه —
یعنی می‌دانیم چه کسی رد کرد، ولی نمی‌دانیم چه کسی پذیرفت.

---

## ۳. ایرادهایی که اصلاح نشدند

| کد | مورد | وضعیت |
|---|---|---|
| ب-۴ | `gender ENUM('M','F','O')` ↔ `persons.gender` = `ENUM('MALE','FEMALE')` | بدون تغییر |
| ب-۵ | `public_id CHAR(26)` ↔ قرارداد `VARCHAR(30)` + `generate_public_id()` | بدون تغییر |
| ب-۶ | `DEFAULT CURRENT_TIMESTAMP` و `DEFAULT (DATE_ADD(...))` ↔ قاعدهٔ UTC با `now_dt()` | بدون تغییر |
| ب-۷ | `ip_address`/`user_agent` خام | بدون تغییر — **و حالا روی IP خام ایندکس هم ساخته می‌شود** |
| ب-۸ | `rejection_reason TEXT` ↔ FK به `lookup_items` | بدون تغییر |
| ب-۹ | وضعیت‌های `PENDING`/`OTP_SENT` ↔ تصمیم ت-۲ | بدون تغییر، و حالا تشدید شده (بند ۴) |
| ب-۱۰ | نبود `service_type_id` | بدون تغییر |
| ب-۱۱ | `ON UPDATE RESTRICT` قید نشده | بدون تغییر |
| ب-۱۲ | `mobile_number CHAR(11)` ↔ `VARCHAR(15)` | بدون تغییر |
| ب-۱۳ | نبود `mobile_verified_at`، `handled_by/at`، `source` | ناقص |
| ب-۱۶ | دروازهٔ درمانگر | ✅ **اصلاح شد** — `AWAITING_THERAPIST` و تصمیم درمانگر برگشت |
| ب-۱۷ | ابهام شمارهٔ تکراری | ✅ تا حدی — «با تحقق ابهام» آمده ولی تعریف نشده |

---

## ۴. تصمیم ت-۲ بی‌اعلام نقض شده است

مالک تصمیم گرفت: **تأیید پیامکی پیش از ثبت درخواست.**

جریان تازه در گام ۲ می‌نویسد:

```
INSERT INTO booking_requests (status='PENDING', ...)
INSERT INTO otp_codes (...)
```

یعنی ردیف **پیش از** تأیید ساخته می‌شود. نتیجه همان چیزی است که ت-۲ برای
جلوگیری از آن وضع شد: انباشت ردیف‌های تأییدنشده.

**و این تناقض لازم نیست.** برای سقف‌گذاری نیازی به نوشتن در
`booking_requests` نیست؛ شمارنده در جدول مستقل `public_rate_limits`
می‌نشیند و دادهٔ فرم تا لحظهٔ تأیید در `$_SESSION` می‌ماند. ت-۲ حفظ
می‌شود و سقف هم کار می‌کند.

---

## ۵. چند ایراد کوچک‌تر

| کد | مورد |
|---|---|
| ج-۴ | `UPDATE otp_codes SET consumed_at=NOW() WHERE mobile=? AND code=?` — ستون `code` وجود ندارد (`code_hash` است) و این کار `attempts` و `OTP_MAX_ATTEMPTS` را دور می‌زند. تابع `otp_verify()` از قبل هست و باید استفاده شود. |
| ج-۵ | `/book/step-1` و `/admin/booking-requests` — پروژه **بازنویسی مسیر ندارد** (هیچ `.htaccess`). همهٔ صفحه‌ها فایل `.php` تخت‌اند: `reception/patients.php`. |
| ج-۶ | کارتابل «پانل مدیر» نامیده شده. این کارِ **منشی** است. در `reception/` بگذاریم تا طبق `ROLE_INHERITANCE` مدیر هم ببیند. |
| ج-۷ | `NOW()` و `DATE_SUB(NOW())` منطقهٔ زمانی سرور را وارد اسکیمای تماماً UTC می‌کنند. |
| ج-۸ | گام‌های ۲ و ۳ هر دو «کد را وارد کنید» را نشان می‌دهند — یک گام اضافه. |
| ج-۹ | «وب‌هوک `test.mirbolouki.com`» در خط‌زمانی ظاهر شده. **اصلاحیهٔ شمارهٔ ۱ محیط آزمایشی جداگانه را لغو کرده است.** |

---

## ۶. جدول صحیح — نسخهٔ قابل اجرا

```sql
CREATE TABLE booking_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,          -- generate_public_id('br')

    first_name  VARCHAR(100) NOT NULL,
    last_name   VARCHAR(100) NOT NULL,
    mobile_number VARCHAR(15) NOT NULL,             -- عمداً UNIQUE نیست
    gender ENUM('MALE','FEMALE') NULL,

    requested_service_label VARCHAR(150) NULL,      -- عین متنی که کاربر دید
    service_type_id BIGINT UNSIGNED NULL,           -- نگاشت، توسط منشی
    preferred_text VARCHAR(100) NULL,               -- «سه‌شنبه صبح»
    visitor_note VARCHAR(300) NULL,                 -- غیربالینی

    mobile_verified_at DATETIME NOT NULL,           -- ت-۲: همیشه پر
    status ENUM('NEW','CONTACTED','CONVERTED','REJECTED','SPAM','EXPIRED')
           NOT NULL DEFAULT 'NEW',

    converted_admission_id BIGINT UNSIGNED NULL,    -- تنها پل
    reject_reason_id BIGINT UNSIGNED NULL,
    handled_by_person_id BIGINT UNSIGNED NULL,
    handled_at DATETIME NULL,

    source VARCHAR(30) NOT NULL DEFAULT 'website',
    ip_hash CHAR(64) NULL,
    user_agent_hash CHAR(64) NULL,

    created_at DATETIME NOT NULL,                   -- now_dt() از PHP
    expires_at DATETIME NOT NULL,                   -- +۱۸۰ روز، از PHP

    CONSTRAINT fk_br_service  FOREIGN KEY (service_type_id)
        REFERENCES service_types(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_br_admission FOREIGN KEY (converted_admission_id)
        REFERENCES admissions(id)  ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_br_reason   FOREIGN KEY (reject_reason_id)
        REFERENCES lookup_items(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_br_handler  FOREIGN KEY (handled_by_person_id)
        REFERENCES persons(id)     ON DELETE RESTRICT ON UPDATE RESTRICT,

    KEY idx_br_status  (status, created_at),
    KEY idx_br_mobile  (mobile_number),
    KEY idx_br_ip      (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE public_rate_limits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket_key  VARCHAR(100) NOT NULL,    -- 'ip:<hash>' | 'global:<YYYY-MM-DD>'
    window_start DATETIME NOT NULL,
    counter INT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_bucket (bucket_key, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- افزایشی، با حفظ هر چهار مقدار موجود
ALTER TABLE otp_codes MODIFY purpose
  ENUM('REGISTER','RESET_PASSWORD','CONFIRM_MOBILE_CHANGE',
       'PATIENT_LOGIN','BOOKING_REQUEST') NOT NULL;
```

`handled_by_person_id` برای هر دو مسیر (تبدیل و رد) یکی است — نیازی به دو
ستون جدا نیست.

---

## ۷. جریان صحیح، سازگار با ت-۲

```
گام ۱  فرم  → اعتبارسنجی → دادهٔ فرم در $_SESSION
         ↓
       سقف:  public_rate_limits  (ip:<hash>  و  global:<روز>)
         ↓   ← شمارنده همین‌جا، پیش از ارسال پیامک، افزایش می‌یابد
       otp_create_and_send($db, $mobile, 'BOOKING_REQUEST')
         ↓
گام ۲  otp_verify($db, $mobile, 'BOOKING_REQUEST', $code)
         ↓  فقط در صورت موفقیت:
       INSERT INTO booking_requests (... mobile_verified_at = now_dt(), status='NEW')
         ↓
گام ۳  صفحهٔ موفقیت + کد پیگیری
```

هیچ ردیف تأییدنشده‌ای ساخته نمی‌شود، و شمارنده پیش از خرج شدن پیامک
بالا می‌رود.

---

## ۸. پاسخ به «کدام یک اول؟»

**هیچ‌کدام.** سند تأیید مدیر ارشد از قبل نوشته شده
(`phase5/BUILD_APPROVAL_REQUEST_FA.md`) و فقط یک اصلاحیهٔ کوچک می‌خواهد:
نام ۴.۲.۰ و حذف وابستگی به فاز ۲.

ترتیب درست:

۱. مالک چهار تصمیم باقی‌مانده را بگوید (بند ۹)؛
۲. اصلاحیهٔ سند تأیید، یک صفحه؛
۳. کدنویسی از مهاجرت `4.2.0`.

---

## ۹. چهار تصمیم باقی‌مانده

۱. **ترجیح زمانی:** متن آزاد (`preferred_text`) ساده‌تر است ولی گزارش‌گیری
   ندارد؛ `preferred_date` + `صبح/عصر` ساختاریافته است. یا هر دو.
۲. **سقف کل روزانهٔ پیامک:** چند تا؟ پیشنهاد ۱۰۰ در شبانه‌روز.
۳. **سقف هر IP:** پیشنهاد ۵ در ساعت (همان عدد سند).
۴. **نمایش ۱۲ خدمت سایت:** از جدول `service_types` خوانده شود یا فهرست
   ثابت سایت با نگاشت دستی منشی؟
