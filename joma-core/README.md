# JOMA core — مرجع قواعد و لایهٔ ورود/پذیرش، نسخهٔ ۰٫۳

این پوشه مستقل از برنامهٔ قدیمی `clinic-app` است. **برنامهٔ آمادهٔ قابل نصب نیست؛ هیچ endpoint عمومیِ رزرو، تراکنش hold/confirm یا رمزنگاری یادداشت در آن تکمیل نشده است.**

## فایل‌ها

- `domain_rules.php` — قواعد خالص و تابع‌محور (۱۰۱ بررسی).
- `db.php` — تبدیل `UUID ↔ BINARY(16)`، تبدیل سخت‌گیرانهٔ `INT UNSIGNED`، و `joma_db_prepare` فقط با `?`.
- `session.php` — `HttpOnly/Secure/SameSite=Lax`، `use_strict_mode`، فقط `account_id/person_id`، و CSRF `hex64`.
- `auth.php` — `login_name` با `WHERE login_name = ?`، `password_verify` با hash ساختگی برای یکنواختی زمان.
- `context.php` — بارگذاری `accounts+memberships+role_assignments+role_definitions` با `JOIN` و `?` باینری.
- `acceptance.php` — **تراکنش اتمیک پذیرش**: `BEGIN` → `SELECT ... FOR UPDATE` روی `therapist_assignments` و `admissions` → بررسی `joma_rule_accept_assignment` → درج `responsibility_acceptances + therapeutic_relationships + clinical_cases + case_participants + case_operational_contexts` → به‌روزرسانی `ASSIGNED→ACCEPTED` و `AWAITING_THERAPIST→LINKED_TO_CASE` → `COMMIT`؛ هر شکست `ROLLBACK`.
- `../tests/joma_domain_rules.php` — ۱۰۱ بررسی قواعد خالص.
- `../tests/joma_core_adapters.php` — ۵۷ بررسی DB/session/auth/context.
- `../tests/joma_core_acceptance.php` — ۳۳ بررسی تراکنش پذیرش با mock mysqli (قفل، rollback/commit، عدم درون‌گذاری).
- `../docs/JOMA-SERVICE-CONTRACTS-v0.1-FA.md` — قرارداد خدمات و ترتیب قفل پیشنهادی.
- `../docs/JOMA-CORE-TEST-REPORT-v0.3-FA.md` — نتایج و محدودیت این مرحله.

## اجرای محلی برای توسعه‌دهنده

PHP 64-bit 8.1+؛ بدون دیتابیس:

```sh
php tests/joma_core_lint.php
php tests/joma_domain_rules.php
php tests/joma_core_adapters.php
php tests/joma_core_acceptance.php
python tests/joma_schema_static.py
python tests/joma_mariadb_static.py
```

جایگزین بدون PHP بومی:

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

خروج صفر موفق است؛ هشدار به شکست تبدیل می‌شود (استثنای `@` برای `session_start` محترم است).

## مرز مهم

- `snapshot`‌ها تصویر معتبر سمت سرورند، نه JSON مرورگر. `allowed=true` فقط همان قاعده است.
- همهٔ SQLها با `?` و `bind_param` هستند؛ آزمون `injection treated as literal` و `no uuid interpolation` این را می‌سنجند. `SELECT ... FOR UPDATE` برای قفل سطر استفاده می‌شود.
- `acceptance` در این تحویل با دادهٔ ساختگی و mock تراکنش آزموده شده؛ **MariaDB واقعی، دو اتصال هم‌زمان، deadlock/retry، و اتصال به `command_receipts/audit` هنوز اجرا نشده** و در `JOMA-SERVICE-CONTRACTS` به‌عنوان معیار باز مانده است.
- `policy_status/permissions` از `role_definitions.is_active` و allowlist موقت می‌آید؛ نگاشت واقعی باید از سیاست مصوب بیاید.
- هاست به Node/npm/Composer نیاز ندارد.
