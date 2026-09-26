# گزارش آزمون هستهٔ جوما ۰٫۴

تاریخ ۲۰۲۶-۰۹-۲۶ — داده‌ها ۱۰۰٪ ساختگی؛ هیچ دادهٔ واقعی درمانی/مالی استفاده نشده.

## خلاصه

| مجموعه | 8.1.34 WASM | 8.4.25 WASM |
|---|---|---|
| `joma_domain_rules` | **۱۰۱/۱۰۱** | **۱۰۱/۱۰۱** |
| `joma_core_adapters` | **۵۷/۵۷** | **۵۷/۵۷** |
| `joma_core_acceptance` | **۳۳/۳۳** | **۳۳/۳۳** |
| `joma_core_hold` — جدید | **۲۵/۲۵** | **۲۵/۲۵** |
| `joma_core_lint` ۱۲ فایل | ۱۲/۱۲ | ۱۲/۱۲ |
| `joma_schema_static` | ۱۸/۱۸ | — |
| `joma_mariadb_static` | ۸/۸ | — |

جمع ۲۱۶ بررسی ساختگی روی دو نسخه تکرار شد (۴۳۲ اجرای یکسان، نه سناریوی جدید). parser: `token_get_all(..., TOKEN_PARSE)`.

## پوشش جدید ۲۵ بررسی hold

- **ساخت hold موفق** با یک منبع، قفل `case+resources ORDER BY id FOR UPDATE`، عدم وجود `overlap`، درج `capacity_holds + hold_allocations` و `COMMIT` بدون درون‌گذاری UUID.
- **هم‌پوشانی** `HELD` فعال → `CAPACITY_CONFLICT` و `ROLLBACK` بدون `INSERT`؛ مجاور `half-open` (`ends==starts`) مجاز؛ `HELD` منقضی (`expires_at <= now`) مانع نیست.
- **TTL** بیش از ۹۰۰ ثانیه (۱۵ دقیقه + ۱ میکروثانیه) → `INVALID_TIME_WINDOW` قبل از تراکنش؛ `starts/ends` نامعتبر نیز رد.
- **منبع تکراری/تهی** → `INVALID_RESOURCE`؛ پرونده غیرفعال → `ACTIVE_ENGAGEMENT_REQUIRED`.
- **تأیید موفق** `HELD → CONFIRMED`: قفل `hold+allocations+case+resources`، بررسی `joma_rule_confirm_hold` (انقضا/مجوز/`offering` یکسان)، نبود هم‌پوشانی با `HELD` دیگر و `CONFIRMED`، درج `appointments + appointment_allocations` و `UPDATE HELD→CONSUMED`.
- **تأیید در مرز انقضا** (`now == expires`) و **قبل از `held_at`** → `HOLD_EXPIRED_OR_NOT_STARTED`؛ `offering` اشتباه → `HOLD_CONFLICT`؛ قبلاً `CONSUMED` → `HOLD_CONFLICT`.
- **رقابت تأیید** با hold دیگر یا appointment → `CAPACITY_CONFLICT` و `ROLLBACK`.
- **عدم جهش** `snapshot`، استفادهٔ صرف از `?` و وجود `FOR UPDATE` در `preparedSqls`.

## اجرا

```sh
php tests/joma_core_lint.php
php tests/joma_domain_rules.php
php tests/joma_core_adapters.php
php tests/joma_core_acceptance.php
php tests/joma_core_hold.php
python tests/joma_schema_static.py
python tests/joma_mariadb_static.py
```

یا

```sh
npm ci --prefix tools --no-audit --no-fund
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_core_lint.php
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_domain_rules.php
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_core_adapters.php
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_core_acceptance.php
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_core_hold.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_lint.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_domain_rules.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_adapters.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_acceptance.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_hold.php
```

Node این محیط ۲۲٫۲۲٫۳/۱۰٫۹٫۸؛ هشدار engine (≥۲۴٫۱۸) مانع نشد. هاست بی‌نیاز از Node/npm/Composer.

## هنوز باز (طبق قرارداد)

- اجرای `hold/confirm` روی MariaDB ۱۰٫۱۱ واقعی با **دو اتصال هم‌زمان**، `deadlock/retry`، `REPEATABLE READ`، و قفل `offering/policy`.
- `request_id`، `idempotency`، `command_receipts/audit`، و مسیر `solo` بدون واگذاری؛ اعتبارسنجی `offering↔policy↔service` و `cancellation_notice_minutes` از سیاست.
- تحویل فایل محافظت‌شده/portal، `entitlement` و رمزنگاری `PrivateNote`.
- اجرای روی PHP واقعی دامنه (برابری شمارهٔ WASM با ۸٫۱٫۳۴ دامنه برابری extensions را ثابت نمی‌کند).

## تفکیک از هاست

هاست قبلاً ۷۰ جدول/InnoDB، ۱۷۳ FK با `RESTRICT`، ۷۲ `UNIQUE` و ۹۰ `CHECK` (۱۰۴ با `JSON_VALID`) را با ۹ بررسی read-only تأیید کرده بود؛ PHP دامنه ۸٫۱٫۳۴، MariaDB ۱۰٫۱۱٫۱۹. ابزار موقت حذف شده؛ تست `write/rollback/concurrency` روی هاست انجام نشده و این تحویل هاست را تغییر نمی‌دهد. همهٔ داده‌های قدیمی به تصریح کاربر آزمایشی و قابل حذف‌اند.

**جمع‌بندی:** نگه‌داشت ≤۱۵ دقیقه و تأیید با قفل منابع و آزمون هم‌پوشانی `half-open` با mock آزموده شد؛ گام بعد آزمون واقعی دو اتصالی و سپس تحویل فایل است.
