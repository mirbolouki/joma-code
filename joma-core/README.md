# JOMA core — مرجع سرتاسری ورود تا فایل، نسخهٔ ۰٫۹

این پوشه مستقل از `clinic-app` است. **برنامهٔ آمادهٔ قابل نصب نیست؛ آزمون واقعی دو اتصالی کامل و اتصال مالی باز است.**

## فایل‌ها

- `domain_rules.php` — قواعد خالص (۱۰۱).
- `db.php` — `UUID↔BINARY(16)`، `INT UNSIGNED`، `prepare ?`، `begin/commit/rollback`، `bind_params`.
- `session.php` — `HttpOnly/Secure/SameSite=Lax`، فقط `account_id/person_id`، CSRF `hex64`.
- `auth.php` — `WHERE login_name = ?` + `password_verify` با hash ساختگی.
- `context.php` — `JOIN` معتبر `accounts/memberships/role_assignments`.
- `acceptance.php` — پذیرش اتمیک `FOR UPDATE` → ۵ درج.
- `hold.php` — نگه‌داشت ≤۱۵ دقیقه و تأیید با `resources ORDER BY id FOR UPDATE` و `half-open`.
- `portal.php` — خوانش portal با مخاطب صریح/نمایندگی/`entitlement`.
- `receipt.php` — idempotency `canonical→sha256` + `UNIQUE(scope,key)` + `FOR UPDATE`.
- `audit.php` — ممیزی `ALLOWED/DENIED/CONFLICT/FAILED` و نگاشت `HTTP 401/403/404/409/422/503` با پیام فارسی بدون نشت `stack`.
- `files.php` — فایل محافظت‌شده `READY + storage_key + sha256`، پیوند `report_versions` و هدر `private, no-store`.
- `http.php` — **پاسخ JSON یکنواخت** `Content-Type: application/json`، `Cache-Control: no-store`، `X-Content-Type-Options` و پیام فارسی بدون `stack`.
- `rate_limit.php` — **محدودسازی لغزشی** `sliding window`؛ `login:ip` ۵ در ۶۰ ثانیه، بدون Redis؛ `remaining/reset` برای تست.
- `../tests/joma_domain_rules.php` — ۱۰۱.
- `../tests/joma_core_adapters.php` — ۵۷.
- `../tests/joma_core_acceptance.php` — ۳۳.
- `../tests/joma_core_hold.php` — ۲۵.
- `../tests/joma_core_portal.php` — ۲۰.
- `../tests/joma_core_receipt_audit.php` — ۲۸.
- `../tests/joma_core_files.php` — ۲۲.
- `../tests/joma_core_e2e.php` — ۲۰ بررسی سرتاسری `login→context→acceptance→hold→confirm→portal→file` + `rate limit` و `http map`.
- `../docs/JOMA-SERVICE-CONTRACTS-v0.1-FA.md` — قرارداد.
- `../docs/JOMA-CONCURRENCY-PLAN-v0.1-FA.md` — نقشهٔ ۷ آزمون هم‌زمانی روی MariaDB واقعی.
- `../deploy/joma-concurrency-check/joma-concurrency-check.php` — ۱۴ بررسی (۹ خواندنی + ۵ نوشتنی سبک با `PK/rollback/CHECK/قفل ۲ثانیه`) برای هاست.
- `../docs/JOMA-CORE-TEST-REPORT-v0.9-FA.md` — نتایج و محدودیت.

## اجرا

PHP 64-bit 8.1+؛ بدون DB:

```sh
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
```

بدون PHP بومی:

```sh
npm ci --prefix tools --no-audit --no-fund
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_core_lint.php
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_domain_rules.php
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_core_adapters.php
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_core_acceptance.php
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_core_hold.php
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_core_portal.php
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_core_receipt_audit.php
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_core_files.php
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_core_e2e.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_lint.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_domain_rules.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_adapters.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_acceptance.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_hold.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_portal.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_receipt_audit.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_files.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_e2e.php
```

خروج صفر موفق.

## مرز

- همهٔ SQLها با `?`; `FOR UPDATE` و `UNIQUE` در mock؛ `E2E` با یک `MockMysqli` ترتیبی کل جریان `login→file` را آزموده.
- `rate_limit` در این مرحله حافظه‌ای است؛ تولید باید DB/Redis با `FOR UPDATE` باشد.
- `hold`/`receipt` روی MariaDB واقعی با دو اتصال و `deadlock/retry` هنوز به‌صورت دستی با دو ترمینال (راهنما در `JOMA-CONCURRENCY-PLAN`) باز است؛ اسکریپت `joma-concurrency-check` ۱۴ بررسی خودکارِ سبک را فراهم می‌کند.
- هاست بی‌نیاز از Node/npm/Composer.
