# گزارش آزمون هستهٔ جوما ۰٫۹

تاریخ ۲۰۲۶-۰۹-۲۷ — داده‌های synthetic ۱۰۰٪ ساختگی؛ هاست بی‌تغییر مگر اجرای اختیاری `joma-concurrency-check`.

## خلاصهٔ synthetic (WASM)

| مجموعه | 8.1.34 | 8.4.25 |
|---|---|---|
| `joma_domain_rules` | **۱۰۱/۱۰۱** | **۱۰۱/۱۰۱** |
| `joma_core_adapters` | **۵۷/۵۷** | **۵۷/۵۷** |
| `joma_core_acceptance` | **۳۳/۳۳** | **۳۳/۳۳** |
| `joma_core_hold` | **۲۵/۲۵** | **۲۵/۲۵** |
| `joma_core_portal` | **۲۰/۲۰** | **۲۰/۲۰** |
| `joma_core_receipt_audit` | **۲۸/۲۸** | **۲۸/۲۸** |
| `joma_core_files` | **۲۲/۲۲** | **۲۲/۲۲** |
| `joma_core_e2e` | **۲۰/۲۰** | **۲۰/۲۰** |
| `joma_core_lint` ۱۸ فایل | ۱۸/۱۸ | ۱۸/۱۸ |
| `joma_schema_static` | ۱۸/۱۸ | — |
| `joma_mariadb_static` | ۸/۸ | — |

جمع ۳۰۶ بررسی روی دو نسخه (۶۱۲ اجرا). `token_get_all(..., TOKEN_PARSE)` برای lint.

## ابزار هاست — ۱۴ بررسی واقعی

`deploy/joma-concurrency-check/joma-concurrency-check.php` (نیاز به `joma-concurrency-check-config.php` با `token` ۳۲+ و `allow_write_tests`):

- **۹ خواندنی**: ۷۰ جدول، ۱۷۳ FK، ۷۲ UNIQUE، ۱۰۴ CHECK (۹۰ صریح + ۱۴ `JSON_VALID`)، `sql_mode` حاوی `STRICT_TRANS_TABLES` و `time_zone +00:00`. با `allow_write_tests=false` (پیش‌فرض) فقط همین‌ها اجرا می‌شوند.
- **۵ نوشتنی سبک** (فقط با `allow_write_tests=true` و پاک‌سازی خودکار): `PK` تکراری → رد، `ROLLBACK` بدون باقی‌مانده، `CHECK (given_name OR family_name)` → رد، `CHECK hold TTL 15min` وجود دارد، و **قفل سطر `FOR UPDATE` با `innodb_lock_wait_timeout=2`** → دومین اتصال ۲ ثانیه صبر و `1205`، اثبات قفل.

خروجی JSON با `ok` و `duration_ms`؛ در HTTP با `?token=` و هدر `no-store` محافظت می‌شود.

## نگاشت ۷ آزمون قرارداد

| # | آزمون قرارداد | وضعیت |
|---|---|---|
| ۱ | دو پذیرش هم‌زمان | `acceptance.php` آماده؛ آزمون synthetic با mock گذشت؛ آزمون واقعی دواتصالی در `JOMA-CONCURRENCY-PLAN` شرح داده شده (نیاز به `admission/assignment` واقعی). |
| ۲ | دو `hold` متداخل از دو مرکز | `hold.php` با `ORDER BY id FOR UPDATE` و `half-open` آماده؛ نوشتنیِ `row lock wait` خودکار است؛ هم‌پوشانی واقعی نیازمند `offering/resource` کامل. |
| ۳ | `confirm` هم‌زمان/مرز انقضا | `hold_confirm` با `now < expires` آماده؛ مرز در mock گذشت. |
| ۴ | رقابت `Case`/`role`/`representation` | `portal` زنده‌خوان دارد؛ آزمون دستی. |
| ۵ | `deadlock/retry` | `receipt` با `UNIQUE` و `FOR UPDATE` آماده؛ آزمون دستی با `timeout=2`. |
| ۶ | `list/search/download` نشت | `portal/files` با `?` آماده؛ آزمون دستی با دو نقش. |
| ۷ | ورود/CSRF/`rate limit` | `auth/session/http/rate_limit` با mock گذشت (۵ در ۶۰ ثانیه)؛ آزمون مرورگر دستی. |

## اجرا

```sh
# synthetic
php tests/joma_core_lint.php
php tests/joma_domain_rules.php
php tests/joma_core_adapters.php
php tests/joma_core_acceptance.php
php tests/joma_core_hold.php
php tests/joma_core_portal.php
php tests/joma_core_receipt_audit.php
php tests/joma_core_files.php
php tests/joma_core_e2e.php
python tests/joma_schema_static.py
python tests/joma_mariadb_static.py
# host (اختیاری)
# 1) کپی deploy/joma-concurrency-check به هاست، پر کردن config با token 32+ 
# 2) php joma-concurrency-check.php   (CLI) یا https://…/joma-concurrency-check.php?token=… (HTTP)
```

## هنوز باز

- اجرای ۷ آزمون کامل با دادهٔ کامل دامنه و دو اتصال هم‌زمان روی `mirbolouki_clinic` (دادهٔ فعلی به تصریح شما آزمایشی و قابل حذف است).
- استریم واقعی `readfile` و اتصال مالی.

**جمع‌بندی:** هسته تا فایل با ۳۰۶ بررسی synthetic و ۱۴ بررسی سبکِ واقعیِ قابل اجرا روی هاست آماده است؛ ۷ آزمون کاملِ قرارداد نقشه و ابزار دارند و منتظر اجرای دستی دواتصالی هستند.
