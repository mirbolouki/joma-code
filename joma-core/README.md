# JOMA core — مرجع قواعد و لایهٔ ورود، نسخهٔ ۰٫۲

این پوشه مستقل از برنامهٔ قدیمی `clinic-app` است. **برنامهٔ آمادهٔ قابل نصب نیست؛ هیچ endpoint عمومی، تراکنش رزرو یا رمزنگاری یادداشت در آن تکمیل نشده است.**

## فایل‌ها

- `domain_rules.php` — قواعد خالص و تابع‌محور (۱۰۱ بررسی مستقل).
- `db.php` — تبدیل UUID متنی↔BINARY(16)، تبدیل عدد unsigned از رشتهٔ mysqli، و `joma_db_prepare` فقط با prepared statement.
- `session.php` — `session_set_cookie_params` با `HttpOnly/Secure/SameSite=Lax`، `use_strict_mode`، ذخیرهٔ فقط `account_id/person_id`، و توکن CSRF.
- `auth.php` — اعتبارسنجی `login_name`، جست‌جوی حساب با `WHERE login_name = ?` (بدون درون‌گذاری رشته)، `password_verify` و hash ساختگی برای یکنواختی زمان پاسخ.
- `context.php` — بارگذاری تصویر معتبر سمت سرور از `joma_accounts + joma_memberships + joma_role_assignments + joma_role_definitions` با یک `JOIN` و دو `?` باینری، بررسی یکپارچگی person/scope و استخراج `policy_status/permissions` از allowlist ثبت‌شده.
- `../tests/joma_domain_rules.php` — ۱۰۱ بررسی قواعد خالص.
- `../tests/joma_core_adapters.php` — ۵۷ بررسی لایهٔ DB/session/auth/context با دادهٔ ساختگی و mock mysqli.
- `../docs/JOMA-SERVICE-CONTRACTS-v0.1-FA.md` — قرارداد خدمات و ترتیب قفل/تراکنش پیشنهادی.
- `../docs/JOMA-CORE-TEST-REPORT-v0.2-FA.md` — نتایج و محدودیت این مرحله (جایگزین گزارش ۰٫۱).

## اجرای محلی برای توسعه‌دهنده

PHP 64-bit 8.1+؛ بدون دیتابیس و بدون اتصال شبکه:

```sh
php tests/joma_core_lint.php
php tests/joma_domain_rules.php
php tests/joma_core_adapters.php
python tests/joma_schema_static.py
python tests/joma_mariadb_static.py
```

جایگزین توسعه‌ای (بدون PHP بومی):

```sh
npm ci --prefix tools --no-audit --no-fund
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_core_lint.php
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_domain_rules.php
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_core_adapters.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_lint.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_domain_rules.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_adapters.php
```

خروج صفر یعنی موفق؛ هشدار PHP در آزمون به شکست تبدیل می‌شود (تست adapter استثنای `@` را محترم می‌شمارد تا `session_start` پس از خروجی خطا ندهد).

## مرز مهم

- آرایه‌های `snapshot` **تصویر معتبر سمت سرور** هستند، نه JSON مرورگر. `allowed=true` فقط همان قاعده است؛ به‌تنهایی مجوز کامل، ظرفیت آزاد یا موفقیت تراکنش نیست.
- جست‌جوی ورود و بارگذاری context هیچ‌گاه UUID یا نام کاربری را در SQL درون‌گذاری نمی‌کنند؛ آزمون `injection treated as literal` این را می‌سنجد.
- `policy_status/permissions` در این تحویل از جدول `joma_role_definitions.is_active` و یک allowlist موقت (`therapist/doctor → routing.accept, scheduling.hold` و ...) می‌آید. نگاشت واقعی باید از سیاست مصوب DB بیاید؛ این allowlist مجوز باز تلقی نمی‌شود.
- هیچ‌کدام از آزمون‌های این پوشه به MariaDB واقعی، تراکنش هم‌زمان، قفل سطر منبع، deadlock/retry، تحویل فایل محافظت‌شده، entitlement خرید، یا رمزنگاری PrivateNote متصل نشده‌اند. معیار آن‌ها در `JOMA-SERVICE-CONTRACTS` فهرست شده است.
- هاست برای این کد به Node، npm یا Composer نیاز ندارد. ابزار WASM فقط برای توسعهٔ محلی است و نسخهٔ Node باید با engine بسته‌ها سازگار باشد.
