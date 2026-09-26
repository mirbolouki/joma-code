# JOMA core — مرجع قواعد و لایهٔ ورود/پذیرش/نگه‌داشت/portal/idempotency/files، نسخهٔ ۰٫۷

این پوشه مستقل از `clinic-app` است. **برنامهٔ آمادهٔ قابل نصب نیست؛ اتصال مالی و آزمون واقعی دو اتصالی باز است.**

## فایل‌ها

- `domain_rules.php` — قواعد خالص (۱۰۱).
- `db.php` — `UUID↔BINARY(16)`، `INT UNSIGNED`، `prepare ?`، `begin/commit/rollback`، `bind_params`.
- `session.php` — `HttpOnly/Secure/SameSite=Lax`، فقط `account_id/person_id`، CSRF `hex64`.
- `auth.php` — `WHERE login_name = ?` + `password_verify`.
- `context.php` — `JOIN` معتبر `accounts/memberships/role_assignments`.
- `acceptance.php` — پذیرش اتمیک `FOR UPDATE` → ۵ درج.
- `hold.php` — نگه‌داشت ≤۱۵ دقیقه و تأیید با قفل `resources ORDER BY id FOR UPDATE`.
- `portal.php` — خوانش portal با مخاطب صریح، نمایندگی و `entitlement`.
- `receipt.php` — idempotency `canonical→sha256` + `UNIQUE(scope, key)` + `FOR UPDATE`.
- `audit.php` — ممیزی `ALLOWED/DENIED/CONFLICT/FAILED` و نگاشت `HTTP`.
- `files.php` — **فایل محافظت‌شده**: اعتبارسنجی `READY + storage_key + byte_length + sha256 32`، جلوگیری از `..`، بارگذاری `joma_protected_files` با `?`، بررسی پیوند `report_versions.protected_file_id = file.id`، تفویض به `portal_check`، و تولید هدر `Content-Type/Disposition/Cache-Control: private, no-store/ETag` + مسیر خارج از `document root`.
- `../tests/joma_domain_rules.php` — ۱۰۱.
- `../tests/joma_core_adapters.php` — ۵۷.
- `../tests/joma_core_acceptance.php` — ۳۳.
- `../tests/joma_core_hold.php` — ۲۵.
- `../tests/joma_core_portal.php` — ۲۰.
- `../tests/joma_core_receipt_audit.php` — ۲۸.
- `../tests/joma_core_files.php` — ۲۲ بررسی فایل محافظت‌شده (اعتبارسنجی، بارگذاری، پیوند، هدر، مسیر).
- `../docs/JOMA-SERVICE-CONTRACTS-v0.1-FA.md` — قرارداد.
- `../docs/JOMA-CORE-TEST-REPORT-v0.7-FA.md` — نتایج و محدودیت.

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
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_lint.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_domain_rules.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_adapters.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_acceptance.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_hold.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_portal.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_receipt_audit.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_files.php
```

خروج صفر موفق.

## مرز

- همهٔ SQLها با `?`; `FOR UPDATE` و `UNIQUE` در mock سنجیده شد.
- فایل‌ها با `storage_key` خارج از `document root` فرض شده؛ **استریم واقعی با `readfile`/`X-Sendfile` و بررسی `byte_length` روی هاست واقعی باز است.**
- هاست بی‌نیاز از Node/npm/Composer.
