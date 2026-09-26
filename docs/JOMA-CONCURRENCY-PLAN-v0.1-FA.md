# برنامهٔ آزمون هم‌زمانی جوما — روی MariaDB واقعی

تاریخ ۲۰۲۶-۰۹-۲۷ — این سند نقشهٔ ۷ آزمون اجباری قرارداد را به ابزار قابل اجرا تبدیل می‌کند. آزمون‌های ۱-۳ با اسکریپت `deploy/joma-concurrency-check` روی هاستِ واقعی قابل اجرا هستند؛ ۴-۷ نیازمند دادهٔ کامل دامنه و دو اتصالِ انسانی هستند.

## ابزار آماده

- `deploy/joma-concurrency-check/joma-concurrency-check.php` — ۹ بررسی فقط‌خواندنی + ۵ بررسی نوشتنیِ سبک (تکرار PK، rollback، CHECK نام، قفل سطر ۲ ثانیه‌ای). با `allow_write_tests=false` (پیش‌فرض) فقط خواندنی است.
- `deploy/joma-concurrency-check/joma-concurrency-check-config.example.php` — نمونهٔ ۳۲+ کاراکتر `token`.
- اجرای CLI: `php joma-concurrency-check.php` (خروجی JSON).
- اجرای HTTP: `https://…/joma-concurrency-check.php?token=…` (محافظت با token، هدر `no-store`).

## نصب امن

1. پوشهٔ `deploy/joma-concurrency-check` را موقتاً در هاست آپلود کنید (ترجیحاً خارج از `public`).
2. `joma-concurrency-check-config.example.php` را به `joma-concurrency-check-config.php` کپی و مقادیر `host/user/pass/name/token` را پر کنید؛ `allow_write_tests` را فعلاً `false` بگذارید.
3. `php joma-concurrency-check.php` را اجرا کنید؛ ۹ بررسی باید `PASS` باشد (۷۰ جدول، ۱۷۳ FK، ۷۲ UNIQUE، ۱۰۴ CHECK، `sql_mode` و `time_zone`).
4. برای ۵ بررسی نوشتنی، `allow_write_tests=true` کنید؛ اسکریپت ردیف‌های تست با `DELETE` پاک می‌کند و `clinic_*` را دست نمی‌زند. پس از اجرا `allow_write_tests` را دوباره `false` کنید و فایل و پوشه را حذف کنید.

## نگاشت ۷ آزمون قرارداد به وضعیت فعلی

| # | شرح قرارداد | وضعیت ابزار |
|---|---|---|
| ۱ | دو پذیرش هم‌زمان با کلید یکسان/متفاوت → فقط یک `engagement`، صفر نیمه‌کاره | اسکلت `acceptance.php` با `FOR UPDATE` آماده؛ آزمون واقعی دواتصالی نیازمند دادهٔ `admission/assignment` کامل و اجرای دستی با دو `mysqli` (نمونه در `tests/joma_core_acceptance.php` با mock). |
| ۲ | دو `hold` متداخل حتی از دو مرکز برای یک درمانگر → فقط یکی، مجاور نصف‌باز مجاز | `hold.php` با `ORDER BY id FOR UPDATE` و بررسی `half-open` آماده؛ آزمون نوشتنیِ `row lock wait` در اسکریپت، هم‌پوشانی واقعی با دادهٔ `offering/resource` کامل باید دستی انجام شود (نمونه mock در `joma_core_hold.php`). |
| ۳ | `confirm` هم‌زمان، مرز انقضا، انتظار تا بعد انقضا → بدون مصرف دوباره | `hold_confirm` با بررسی `now < expires` آماده؛ آزمون مرز در mock گذشت؛ آزمون قفل تا بعد انقضا نیازمند `sleep` و دو اتصال است. |
| ۴ | رقابت پایان `Case`/ابطال `role`/تعلیق `representation` با `hold/read` | `portal.php` بارگذاری زنده دارد؛ آزمون نیازمند ساخت `case_representation_restrictions` و اجرای هم‌زمان `UPDATE` و `SELECT FOR UPDATE`. |
| ۵ | `deadlock/retry` با همان کلید؛ قطع نزدیک `commit` و بازیابی بدون تکرار | `receipt.php` با `UNIQUE(scope,key)` و `SELECT FOR UPDATE` آماده؛ آزمون `deadlock` نیازمند `innodb_lock_wait_timeout` و `retry` حلقهٔ تراکنش است. |
| ۶ | `list/search/count/download/API` با `role/scope/audience/ENTITLEMENT` | `portal` و `files` با `?` آماده؛ آزمون نشت نیازمند `curl` با دو نقش مختلف و بررسی `404` یکنواخت است. |
| ۷ | ورود/خروج، بازیابی، `CSRF`، `rate limit`، `session fixation`، ابطال و خطای عمومی | `auth/session/http/rate_limit` با mock آماده؛ آزمون `rate_limit` ۵ در ۶۰ ثانیه در `joma_core_e2e` گذشت؛ آزمون `CSRF` و `session fixation` روی endpoint واقعی باید با مرورگر انجام شود. |

## اجرای دستی دو اتصالی (نمونه برای ۱ و ۲)

برای هر آزمون، دو ترمینال `mysql` یا دو اسکریپت `php` با `mysqli` باز کنید:

```sql
-- ترمینال A
START TRANSACTION;
SELECT id FROM joma_therapist_assignments WHERE id = ? FOR UPDATE; -- یا resource
-- نگه دارید، کامیت نکنید
```

```sql
-- ترمینال B (در ۲ ثانیه باید wait کند)
SET innodb_lock_wait_timeout=2;
START TRANSACTION;
SELECT ... FOR UPDATE; -- همان سطر — باید 2 ثانیه صبر و سپس خطای 1205 دهد
```

اسکریپت فعلی همین را با دو `mysqli` و `microtime` می‌سنجد.

## پاک‌سازی

اسکریپت فقط ردیف‌های با `id` تصادفی تست را `DELETE` می‌کند؛ `clinic_*` دست نمی‌خورد. با این حال به تصریح شما همهٔ داده‌های هاست آزمایشی و قابل حذف است؛ در صورت نیاز کل `mirbolouki_clinic` را می‌توان با `DROP` ساختِ تمیز تست کرد.

## گام بعد

پس از `PASS` شدن ۹+۵ بررسیِ خودکار، برای ۷ آزمون کامل باید دادهٔ واقعی `person/account/membership/role/offering/resource/admission` در DB تستی ساخته شود و هر آزمون با دو اتصال اجرا و `ROLLBACK` شود. این داده‌سازی در `tools/generate-joma-schema.py` و `joma-core/acceptance|hold` مستند است.
