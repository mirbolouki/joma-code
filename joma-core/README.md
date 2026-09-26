# JOMA core — مرجع قواعد و لایهٔ ورود/پذیرش/نگه‌داشت/portal، نسخهٔ ۰٫۵

این پوشه مستقل از `clinic-app` است. **برنامهٔ آمادهٔ قابل نصب نیست؛ تحویل خارج از portal، انتقال کلید یادداشت و اتصال مالی هنوز باز است.**

## فایل‌ها

- `domain_rules.php` — قواعد خالص (۱۰۱).
- `db.php` — `UUID↔BINARY(16)`، `INT UNSIGNED` سخت‌گیرانه، `prepare` با `?`، `begin/commit/rollback` و `bind_params`.
- `session.php` — `HttpOnly/Secure/SameSite=Lax`، فقط `account_id/person_id`، CSRF `hex64`.
- `auth.php` — `WHERE login_name = ?` + `password_verify` با hash ساختگی.
- `context.php` — `JOIN` معتبر `accounts/memberships/role_assignments/role_definitions`.
- `acceptance.php` — پذیرش اتمیک `FOR UPDATE` → ۵ درج → `ACCEPTED/LINKED_TO_CASE`.
- `hold.php` — نگه‌داشت ≤۱۵ دقیقه و تأیید با قفل `resources ORDER BY id FOR UPDATE` و بررسی `half-open`.
- `portal.php` — **خوانش portal با مخاطب صریح**: بارگذاری `publication + audience` با `JOIN` و `?`، سپس `representation` (`VERIFIED` + `document.read` + بازه)، `case_representation_restrictions` و `product_entitlements` (`source_verified`) و تفویض به `joma_rule_portal_read`؛ `PRIVATE_NOTE` فقط نویسنده.
- `../tests/joma_domain_rules.php` — ۱۰۱.
- `../tests/joma_core_adapters.php` — ۵۷.
- `../tests/joma_core_acceptance.php` — ۳۳.
- `../tests/joma_core_hold.php` — ۲۵.
- `../tests/joma_core_portal.php` — ۲۰ بررسی portal با mock (مخاطب، نمایندگی، محدودیت، entitlement، DRAFT، فرم).
- `../docs/JOMA-SERVICE-CONTRACTS-v0.1-FA.md` — قرارداد و ترتیب قفل.
- `../docs/JOMA-CORE-TEST-REPORT-v0.5-FA.md` — نتایج و محدودیت.

## اجرا

PHP 64-bit 8.1+؛ بدون DB:

```sh
php tests/joma_core_lint.php
php tests/joma_domain_rules.php
php tests/joma_core_adapters.php
php tests/joma_core_acceptance.php
php tests/joma_core_hold.php
php tests/joma_core_portal.php
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
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_lint.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_domain_rules.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_adapters.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_acceptance.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_hold.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_portal.php
```

خروج صفر موفق؛ هشدار → شکست.

## مرز

- همهٔ SQLها با `?`؛ `FOR UPDATE` و عدم درون‌گذاری در آزمون‌ها سنجیده می‌شود.
- `portal` در این مرحله با mock لایهٔ audience آزموده شد؛ **فایل محافظت‌شده خارج از document root، هدر `Content-Disposition`، و عدم cache مشترک هنوز روی هاستِ واقعی آزموده نشده**؛ همچنین دو اتصال هم‌زمان `hold` روی DB واقعی.
- `permissions` و `entitlement` از جدول‌های موقت/allowlist می‌آید؛ سیاست نهایی باید مصوب شود.
- هاست بی‌نیاز از Node/npm/Composer.
