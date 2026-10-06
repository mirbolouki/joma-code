# بررسی سوم — «چهار تصمیم، جواب سریع»

**تاریخ:** ۱۴۰۵/۰۷/۱۴

---

## ۰. حکم

**هر چهار تصمیم از نظر محتوا درست است و می‌پذیرم.** یکی‌شان از پیشنهاد
خودم بهتر است (بند ۲). ولی قطعه‌کدها باز هم ستونی را می‌خوانند که وجود
ندارد — **بار سوم، همان ریسک R11**.

---

## ۱. د-۱ — سقف IP: سومین تلاش، همان شکست

```php
SELECT COUNT(*) FROM otp_codes WHERE ip_hash = ? AND purpose = 'BOOKING_REQUEST' ...
```

ستون‌های واقعی `otp_codes`:

```
id · mobile_number · code_hash · purpose · expires_at · attempts · consumed_at · created_at
```

**`ip_hash` هم وجود ندارد.** فقط نام `ip_address` به `ip_hash` تغییر کرده؛
ستون همچنان ساخته نشده.

تاریخچهٔ این ریسک:

| تلاش | راه‌حل پیشنهادی | چرا کار نکرد |
|---|---|---|
| ۱ | شمارش از `booking_requests` | طبق ت-۲ هنگام ارسال پیامک هنوز ردیفی نیست |
| ۲ | شمارش از `otp_codes.ip_address` | ستون وجود ندارد |
| ۳ | شمارش از `otp_codes.ip_hash` | ستون وجود ندارد |

**تصمیم لازم — دو راه، هر دو درست:**

**راه الف (ساده‌تر، توصیهٔ من):** یک ستون به `otp_codes` اضافه شود.

```sql
ALTER TABLE otp_codes ADD COLUMN ip_hash CHAR(64) NULL AFTER purpose;
ALTER TABLE otp_codes ADD KEY idx_otp_ip (ip_hash, created_at);
```

افزودن ستون **nullable** روی جدول موجود، برخلاف آن `MODIFY` روی ENUM،
بی‌خطر است: هیچ ردیفی تغییر نمی‌کند و هیچ کدی نمی‌شکند.

مزیت پنهانش از خود رزرو بزرگ‌تر است: سقف IP آنگاه برای **همهٔ** جریان‌های
کد یک‌بارمصرف کار می‌کند، از جمله ورود مراجع به پورتال — که امروز هیچ
سقف IP ندارد.

**در این صورت جدول `public_rate_limits` که خودم پیشنهاد داده بودم لازم
نیست و حذف می‌شود.** یک جدول کمتر، یک منبع حقیقت.

**راه ب:** جدول شمارندهٔ مستقل `public_rate_limits`. مزیتش: هیچ تغییری در
جدول‌های موجود. عیبش: یک جدول اضافه و سقف فقط برای رزرو.

> این تغییر یک **`ALTER` دوم** است و سند مجوز فقط یکی را برای تأیید برده
> بود. باید در اصلاحیه صریحاً ذکر شود.

---

## ۲. د-۲ — گزینهٔ «شب» قابل ارائه نیست

تصمیم ۱ این بازه‌ها را می‌دهد: `MORNING / AFTERNOON / EVENING / FLEXIBLE`.

ساعت کاری سامانه از فاز ۲ ثابت است:

```php
CLINIC_DAY_START_HOUR = 9
CLINIC_DAY_END_HOUR   = 20
```

**هیچ نوبتی بعد از ۲۰ وجود ندارد.** پیشنهاد «شب» دقیقاً همان انتظار
نادرستی را می‌سازد که تصمیم ت-۱ برای حذفش گرفته شد.

**درست:** سه گزینه با ساعت صریح —
`صبح (۹ تا ۱۳)` · `عصر (۱۳ تا ۲۰)` · `فرقی ندارد`

---

## ۳. د-۳ — سقف روزانه یک کلید قطع سرویس هم هست

«۱۰۰ پیامک در شبانه‌روز» عدد معقولی است. ولی وقتی پر شود، **مراجع واقعی
هم تا پایان روز نمی‌تواند درخواست بدهد** — و شما خبردار نمی‌شوید.

سه الزام:

۱. رسیدن به سقف، رویداد حسابرسی `BOOKING_RATE_LIMIT_HIT` بسازد؛
۲. در داشبورد منشی هشدار دیده شود؛
۳. پیام خطا بن‌بست نباشد: «امکان ثبت درخواست اینترنتی موقتاً فراهم نیست؛
   لطفاً با شمارهٔ ۰۹۹۶۷۹۷۹۴۷ تماس بگیرید.»

---

## ۴. د-۴ — جدول نگاشت: دو اصلاح

```sql
website_service_label VARCHAR(255) PRIMARY KEY
```

- **کلید اصلی متن فارسی خطرناک است.** اصلاح یک نیم‌فاصله یا یک غلط
  املایی، نگاشت را بی‌صدا می‌شکند.
- `VARCHAR(255)` با `utf8mb4` برابر ۱۰۲۰ بایت است و به سقف ایندکس
  InnoDB نزدیک می‌شود.

**درست:** کلید جانشین عددی + ستون کد کوتاه و پایدار:

```sql
CREATE TABLE booking_service_options (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    option_code VARCHAR(50) NOT NULL UNIQUE,      -- 'couples_therapy'
    website_label VARCHAR(150) NOT NULL,          -- «زوج درمانی»
    service_type_id BIGINT UNSIGNED NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_bso_service FOREIGN KEY (service_type_id)
        REFERENCES service_types(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

و یک نکتهٔ مهم: **منوی کشویی صفحهٔ عمومی از همین جدول رندر شود**، نه از
فهرست ثابت داخل کد. آنگاه افزودن یا حذف خدمت، کار پایگاه داده است نه
کدنویسی. `service_type_id` را `NULL` بگذارید تا نگاشت‌نشده‌ها معلوم باشند.

---

## ۵. یک باگ واقعی در کد

```php
$clinic_service_id = (int)$_POST['override_service_type_id'] ?? $clinic_service_id;
```

`(int)` هرگز `null` برنمی‌گرداند؛ کلید غایب به `0` تبدیل می‌شود. پس `??`
**هیچ‌وقت فعال نمی‌شود** و مقدار پیش‌فرض همیشه با صفر بازنویسی می‌شود.

```php
$clinic_service_id = isset($_POST['override_service_type_id'])
    ? (int)$_POST['override_service_type_id']
    : $clinic_service_id;
```

---

## ۶. مواردی که از دور قبل باقی مانده‌اند

| کد | مورد |
|---|---|
| ج-۵ | مسیرها: پروژه `.htaccess` و بازنویسی مسیر **ندارد**. `/pages/booking/step-1.php` و `/admin/booking-requests/list.php` کار نمی‌کنند. نام‌های پیشنهادی در بند ۷. |
| ج-۶ | کارتابل باز هم «منوی مدیر» است. این کارِ **منشی** است؛ در `reception/` که طبق `ROLE_INHERITANCE` مدیر هم می‌بیند. |
| ج-۷ | `NOW()`، `CURDATE()`، `DATE_SUB(NOW(), ...)` منطقهٔ زمانی سرور را وارد اسکیمای تماماً UTC می‌کنند. بازه‌ها در PHP با `now_dt()` ساخته شوند. ضمناً `DATE(created_at) = CURDATE()` ایندکس را بی‌اثر می‌کند. |
| — | `preferred_note TEXT` و `visitor_note` هر دو متن آزادند. یکی کافی است: `preferred_note VARCHAR(300)`. |
| — | ترتیب روزهای هفته از شنبه شروع شود، نه یکشنبه. |

---

## ۷. نام‌گذاری فایل‌ها، هم‌خوان با پروژه

```
includes/booking_functions.php
booking.php                      ← عمومی، سه گام با POST
reception/booking_requests.php   ← کارتابل (منشی + مدیر)
reception/booking_request_view.php
database/phase4_2_schema.sql
upgrade_phase4_2.php
```

---

## ۸. پاسخ به «موازی یا ترتیبی؟»

**ترتیبی.** موازی‌کاری اینجا سود ندارد و ریسک دارد: اگر مدیر ارشد یکی از
دو `ALTER` را رد کند، کد نوشته‌شده باید دور ریخته شود.

۱. اصلاحیهٔ سند مجوز (نوشته شد: `BUILD_APPROVAL_AMENDMENT_01_FA.md`)
۲. پاسخ مدیر ارشد
۳. مهاجرت `4.2.0`
۴. توابع، سپس صفحهٔ عمومی، سپس کارتابل
