# JOMA core — مرجع قواعد و لایهٔ ورود/پذیرش/نگه‌داشت/portal/idempotency، نسخهٔ ۰٫۶

این پوشه مستقل از `clinic-app` است. **برنامهٔ آمادهٔ قابل نصب نیست؛ استریم فایل و اتصال مالی هنوز باز است.**

## فایل‌ها

- `domain_rules.php` — قواعد خالص (۱۰۱).
- `db.php` — `UUID↔BINARY(16)`، `INT UNSIGNED`، `prepare ?`، `begin/commit/rollback`، `bind_params`.
- `session.php` — `HttpOnly/Secure/SameSite=Lax`، فقط `account_id/person_id`، CSRF `hex64`.
- `auth.php` — `WHERE login_name = ?` + `password_verify` با hash ساختگی.
- `context.php` — `JOIN` معتبر `accounts/memberships/role_assignments`.
- `acceptance.php` — پذیرش اتمیک `FOR UPDATE` → ۵ درج → `ACCEPTED/LINKED_TO_CASE`.
- `hold.php` — نگه‌داشت ≤۱۵ دقیقه و تأیید با قفل `resources ORDER BY id FOR UPDATE` و `half-open`.
- `portal.php` — خوانش portal با مخاطب صریح، نمایندگی و `entitlement`.
- `receipt.php` — **idempotency**: `canonical JSON (sorted keys)` → `sha256 hex64 → BINARY(32)`، کلید تصادفی `BINARY(32)` با `UNIQUE(scope_id,idempotency_key)`، `claim` با `INSERT PROCESSING` و `SELECT … FOR UPDATE` و تفویض به `joma_rule_retry` (`REPLAY_REQUIRES_CURRENT_AUTHORIZATION` / `COMMAND_IN_PROGRESS` / `IDEMPOTENCY_CONFLICT`).
- `audit.php` — **ممیزی append-only**: `ALLOWED/DENIED/CONFLICT/FAILED`، `reason_code`allowlist، رد `password/secret/token` در `redacted_metadata_json`، و نگاشت `internal→HTTP 401/403/404/409/422/503`.
- `../tests/joma_domain_rules.php` — ۱۰۱.
- `../tests/joma_core_adapters.php` — ۵۷.
- `../tests/joma_core_acceptance.php` — ۳۳.
- `../tests/joma_core_hold.php` — ۲۵.
- `../tests/joma_core_portal.php` — ۲۰.
- `../tests/joma_core_receipt_audit.php` — ۲۸ بررسی `canonical/hash`، `claim/replay`، `audit` و `HTTP map`.
- `../docs/JOMA-SERVICE-CONTRACTS-v0.1-FA.md` — قرارداد.
- `../docs/JOMA-CORE-TEST-REPORT-v0.6-FA.md` — نتایج و محدودیت.

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
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_lint.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_domain_rules.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_adapters.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_acceptance.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_hold.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_portal.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_receipt_audit.php
```

خروج صفر موفق.

## مرز

- همهٔ SQLها با `?`; `FOR UPDATE` و `UNIQUE` در آزمون mock سنجیده شد.
- `receipt/audit` در این مرحله با mock آزموده شد؛ **تراکنش واقعی با `INSERT … FOR UPDATE` هم‌زمان و `deadlock/retry` روی MariaDB ۱۰٫۱۱ واقعی هنوز باز است.**
- هاست بی‌نیاز از Node/npm/Composer.
