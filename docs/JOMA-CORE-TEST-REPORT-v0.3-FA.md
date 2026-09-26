# گزارش آزمون هستهٔ جوما ۰٫۳

تاریخ: ۲۰۲۶-۰۹-۲۶ — همهٔ داده‌ها ساختگی؛ هیچ اطلاعات واقعی درمانی/اعتباری استفاده نشده.

## خلاصهٔ اجرایی

| آزمون | 8.1.34 (WASM) | 8.4.25 (WASM) |
|---|---|---|
| `joma_domain_rules` — قواعد خالص | **۱۰۱/۱۰۱** | **۱۰۱/۱۰۱** |
| `joma_core_adapters` — DB/session/auth/context | **۵۷/۵۷** | **۵۷/۵۷** |
| `joma_core_acceptance` — تراکنش پذیرش | **۳۳/۳۳** | **۳۳/۳۳** |
| `joma_core_lint` — parser ۱۰ فایل | **۱۰/۱۰** | **۱۰/۱۰** |
| `joma_schema_static` | ۱۸/۱۸ | — |
| `joma_mariadb_static` | ۸/۸ | — |

هر ردیف یک مجموعهٔ یکسان روی دو نسخه اجرا شده؛ جمع «۱۹۱×۲» سناریوی متفاوت نیست. parser با `token_get_all(..., TOKEN_PARSE)` است.

## پوشش جدید ۳۳ بررسی پذیرش

- اعتبارسنجی `purpose_summary` فارسی (۱..۵۰۰ حرف، trim، رد تهی/NUL/کنترل/۵۰۰+).
- موفقیت اتمیک: `BEGIN` → دو `SELECT ... FOR UPDATE` → پنج `INSERT` (`acceptances/relationships/cases/participants/contexts`) → دو `UPDATE` → `COMMIT`؛ بررسی `?` بدون درون‌گذاری UUID و وجود `FOR UPDATE`.
- رد درمانگر اشتباه (`ASSIGNED_THERAPIST_REQUIRED`)، واگذاری `ACCEPTED/DECLINED`، پذیرش با `case_id` موجود، وضعیت پذیرش `LINKED_TO_CASE` (`STATE_CONFLICT`)، ناسازگاری scope و نبود permission (`CONTEXT_DENIED`/`PERMISSION_DENIED`) همگی `ROLLBACK` بدون `COMMIT` و بدون `INSERT` اضافی.
- عدم وجود `assignment/admission` → `NOT_FOUND` با `ROLLBACK`.
- ورودی نامعتبر (`assignmentId` نادرست، `commandId` نادرست، زمان بد، purpose تهی) بدون شروع تراکنش.
- شکست `INSERT` میانی (شبیه‌سازی `DB_ERROR`) → `ROLLBACK` کامل، عدم `COMMIT`.
- تولید خودکار `UUIDv4` برای پنج شناسهٔ جدید (lower-case، معتبر) و عدم جهش `snapshot`.

## اجرای تکرارپذیر

```sh
php tests/joma_core_lint.php
php tests/joma_domain_rules.php
php tests/joma_core_adapters.php
php tests/joma_core_acceptance.php
python tests/joma_schema_static.py
python tests/joma_mariadb_static.py
```

یا با WASM:

```sh
npm ci --prefix tools --no-audit --no-fund
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_core_lint.php
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_domain_rules.php
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_core_adapters.php
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_core_acceptance.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_lint.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_domain_rules.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_adapters.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_acceptance.php
```

Node این محیط `22.22.3`، npm `10.9.8`؛ هشدار engine (نیاز ≥24.18) مانع اجرا نشد. هاست نیازی به Node/npm/Composer ندارد.

## هنوز آزموده نشده

- اجرای همین تراکنش روی MariaDB ۱۰٫۱۱ واقعی با دو اتصال هم‌زمان، `deadlock`/`retry`، `REPEATABLE READ`، و قفل منابع `hold`.
- `command_receipts` واقعی، `idempotency_key`، `audit_entries` و `notification_intents`؛ مسیر solo بدون واگذاری؛ و policy خدمت/صلاحیت درمانگر.
- ورود/خروج واقعی، `Secure/HttpOnly/SameSite` روی HTTPS، `rate limit`، بازیابی و CSRF روی endpoint واقعی.
- اجرای روی PHP واقعی دامنه (برابری شمارهٔ WASM با `8.1.34` دامنه برابری extensions/پشتیبانی را ثابت نمی‌کند).

## تفکیک از هاست

بررسی read-only قبلی ۷۰ جدول/InnoDB، ۱۷۳ FK با `RESTRICT`، ۷۲ `UNIQUE` و ۹۰ `CHECK` صریح (۱۰۴ با `JSON_VALID`) را تأیید کرده بود. PHP دامنه `8.1.34`، MariaDB `10.11.19`؛ ابزار موقت حذف شده؛ تست `write/rollback/concurrency` روی هاست انجام نشده و این تحویل هاست را تغییر نمی‌دهد. همهٔ داده‌های قدیمی بنا بر تصریح کاربر آزمایشی و قابل حذف‌اند.

**جمع‌بندی:** پذیرش مسئولیت با تراکنش اتمیک و قفل سطر، با mock آزموده شد؛ گام بعد `hold/confirm` با قفل منابع و آزمون هم‌زمانی روی DB واقعی است.
