# JOMA core — مرجع قواعد و لایهٔ ورود/پذیرش/نگه‌داشت، نسخهٔ ۰٫۴

این پوشه مستقل از `clinic-app` است. **برنامهٔ آمادهٔ قابل نصب نیست؛ تحویل فایل محافظت‌شده/portal، entitlement و رمزنگاری یادداشت هنوز تکمیل نشده.**

## فایل‌ها

- `domain_rules.php` — قواعد خالص (۱۰۱ بررسی).
- `db.php` — `UUID↔BINARY(16)`، `INT UNSIGNED` سخت‌گیرانه، `prepare` فقط با `?`، و `begin/commit/rollback` + `bind_params` برای `IN (...)`.
- `session.php` — `HttpOnly/Secure/SameSite=Lax`، `use_strict_mode`، فقط `account_id/person_id`، CSRF `hex64`.
- `auth.php` — `WHERE login_name = ?` + `password_verify` با hash ساختگی.
- `context.php` — `JOIN` معتبر `accounts/memberships/role_assignments/role_definitions`.
- `acceptance.php` — پذیرش اتمیک: `FOR UPDATE` روی `assignments/admissions` → درج ۵ جدول → `ACCEPTED/LINKED_TO_CASE` → `COMMIT/ROLLBACK`.
- `hold.php` — **نگه‌داشت و تأیید با قفل منابع**: `hold_create` قفل `case` و `resources ORDER BY id FOR UPDATE` → بررسی هم‌پوشانی `HELD` و `CONFIRMED` با `starts_at < ? AND ends_at > ?` → درج `capacity_holds + hold_allocations`؛ `hold_confirm` قفل `hold+allocations+case+resources` → بررسی انقضا/مجوز → درج `appointments + appointment_allocations` → `CONSUMED`. TTL ≤۱۵ دقیقه و `half-open` مجاور مجاز.
- `../tests/joma_domain_rules.php` — ۱۰۱.
- `../tests/joma_core_adapters.php` — ۵۷.
- `../tests/joma_core_acceptance.php` — ۳۳.
- `../tests/joma_core_hold.php` — ۲۵ بررسی نگه‌داشت/تأیید با mock تراکنش.
- `../docs/JOMA-SERVICE-CONTRACTS-v0.1-FA.md` — قرارداد و ترتیب قفل.
- `../docs/JOMA-CORE-TEST-REPORT-v0.4-FA.md` — نتایج و محدودیت.

## اجرا

PHP 64-bit 8.1+؛ بدون DB:

```sh
php tests/joma_core_lint.php
php tests/joma_domain_rules.php
php tests/joma_core_adapters.php
php tests/joma_core_acceptance.php
php tests/joma_core_hold.php
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
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_lint.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_domain_rules.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_adapters.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_acceptance.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_hold.php
```

خروج صفر موفق؛ هشدار → شکست (استثنای `@` محترم).

## مرز

- همهٔ SQLها با `?`؛ `no uuid interpolation` و `FOR UPDATE` در آزمون‌ها سنجیده می‌شود.
- `hold`/`confirm` در این مرحله با mock تراکنش آزموده شده؛ **MariaDB واقعی با دو اتصال، `deadlock/retry`، و قفل `offering/policy` هنوز اجرا نشده** و معیار `JOMA-SERVICE-CONTRACTS` باز است.
- `permissions` از allowlist موقت می‌آید؛ نگاشت واقعی از سیاست مصوب DB باید بیاید.
- هاست نیازی به Node/npm/Composer ندارد.
