# گزارش آزمون هستهٔ جوما ۰٫۲

تاریخ اجرا: ۲۰۲۶-۰۹-۲۶ — همهٔ داده‌ها ساختگی‌اند؛ هیچ اطلاعات واقعی درمانی، اعتباری یا هویتی استفاده نشده است.

## خلاصهٔ اجرایی

| آزمون | محیط واقعی اجرا | نتیجه |
|---|---|---|
| `tests/joma_domain_rules.php` — قواعد خالص | PHP WASM **8.1.34** | **۱۰۱ بررسی، صفر شکست** |
| همان مجموعه | PHP WASM **8.4.25** | **۱۰۱ بررسی، صفر شکست** |
| `tests/joma_core_adapters.php` — لایهٔ DB/session/auth/context | PHP WASM **8.1.34** | **۵۷ بررسی، صفر شکست** |
| همان مجموعه | PHP WASM **8.4.25** | **۵۷ بررسی، صفر شکست** |
| `tests/joma_core_lint.php` — parser هشت فایل جدید | هر دو نسخهٔ بالا | **۸/۸ در هر نسخه** |
| `tests/joma_schema_static.py` | Python | **۱۸/۱۸** |
| `tests/joma_mariadb_static.py` | Python | **۸/۸** |

۱۰۱ و ۵۷ هر کدام یک مجموعهٔ یکسان روی دو نسخه اجرا شده‌اند؛ «۲۰۲» یا «۱۱۴» سناریوی متفاوت نیست. parser از `token_get_all(..., TOKEN_PARSE)` استفاده می‌کند.

## چه چیزی در ۵۷ بررسی جدید سنجیده شد

- تبدیل رفت‌وبرگشت `UUID متنی ↔ BINARY(16)` و رد sentinel/طول نادرست؛
- تبدیل سخت‌گیرانهٔ `INT UNSIGNED` از رشتهٔ mysqli (`0`، منفی، overflow تا `4294967295`، `01` و اعشار رد می‌شوند)؛
- اعتبارسنجی `DATETIME(6) UTC` و پذیرش `NULL` برای بازهٔ باز؛
- اعتبارسنجی `login_name` (۳ تا ۱۹۱، trim، رد NUL/کنترلی) و `password` (تهی یا >۲۰۰ رد)؛
- `password_verify` درست/نادرست، و استفاده از hash ساختگی هنگام حساب ناموجود برای یکنواختی زمان؛
- جست‌جوی حساب با mock mysqli: موفقیت، ناموجود، و **تزریق `' OR '1'='1` به‌صورت مقدار بایندشده** نه الحاق SQL؛ بررسی `WHERE login_name = ? LIMIT 1`؛
- ورود: گذرواژهٔ درست، نادرست، حساب `LOCKED/INACTIVE`، زمان نامعتبر و یکنواختی `INVALID_CREDENTIALS`؛
- بارگذاری context: `JOIN` معتبر با دو `?` باینری، `NOT_FOUND`، ناسازگاری `person_id/scope_id` → `CONTEXT_DENIED`، سیاست `is_active=0` → `NOT_APPROVED`، کد نقش ناشناخته → `permissions=[]`، شناسه/زمان نامعتبر، و عدم جهش بین scopeها؛
- کنترل مجوز: `routing.accept` مجاز و `report.publish` برای نقش therapist رد؛
- نشست: ذخیرهٔ فقط `ACTIVE`، عدم ذخیرهٔ `LOCKED`، بازیابی principal و عدم نشت password_hash؛
- CSRF: قالب `hex64`، تأیید درست و رد نادرست، و پایداری توکن در یک نشست؛
- عدم جهش آرایهٔ مبدا هنگام بارگذاری.

## اجرای تکرارپذیر

از ریشهٔ repository با PHP 64-bit 8.1+:

```sh
php tests/joma_core_lint.php
php tests/joma_domain_rules.php
php tests/joma_core_adapters.php
python tests/joma_schema_static.py
python tests/joma_mariadb_static.py
```

جایگزین این محیط (بدون PHP بومی):

```sh
npm ci --prefix tools --no-audit --no-fund
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_core_lint.php
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_domain_rules.php
PHP=8.1 tools/node_modules/.bin/php-wasm-cli tests/joma_core_adapters.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_lint.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_domain_rules.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_adapters.php
```

Node این محیط `22.22.3` و npm `10.9.8` بود. نصب WASM هشدار engine داد چون چند بسته Node ≥24.18 را می‌خواهند؛ با این حال هر شش اجرا کامل شد. هاست به Node/npm/Composer نیاز ندارد.

## چیزهایی که هنوز آزموده نشده‌اند

- اتصال واقعی `mysqli` به MariaDB 10.11، تراکنش پذیرش اتمیک، قفل سطر `joma_schedule_resources` و `FOR UPDATE`، بررسی هم‌پوشانی با `SELECT ... FOR UPDATE` پس از قفل، انقضای hold، `deadlock`/`retry` و `ROLLBACK` میان‌مرحله‌ای؛
- دو اتصال هم‌زمان برای پذیرش/رزرو متداخل حتی از دو مرکز برای یک درمانگر؛
- ورود/خروج واقعی، `session_regenerate_id`، `Secure/HttpOnly/SameSite` روی HTTPS واقعی، `rate limit`، بازیابی گذرواژه، CSRF روی endpoint واقعی؛
- تحویل فایل محافظت‌شده خارج از document root، `entitlement` خرید، انتشار/مخاطب و محدودیت نمایندگی، رمزنگاری PrivateNote و انتقال durable ack؛
- اجرای همین قواعد روی PHP واقعی دامنه. برابری شمارهٔ WASM با `8.1.34` دامنه، برابری extensions یا پشتیبانی امنیتی را ثابت نمی‌کند.

## تفکیک از شواهد پیشین

بررسی read-only هاست قبلاً `۷۰` جدول/InnoDB/collation، `۱۷۳` FK با `RESTRICT`، `۷۲` UNIQUE و `۹۰` CHECK صریح را تأیید کرده بود؛ شمار `۱۰۴` شامل `۱۴` `JSON_VALID` خودکار MariaDB بود. PHP دامنه `8.1.34` و MariaDB `10.11.19` بود؛ PHP `8.4.24` مربوط به phpMyAdmin است. ابزار موقت بررسی حذف شده است؛ تست write/rollback/concurrency روی هاست انجام نشده و در این تحویل نیز هاست تغییری نکرده است. همهٔ داده‌های قدیمی DB و پوشهٔ `/home/mirbolouki/my` به تصریح کاربر آزمایشی و قابل حذف‌اند؛ مجوزی برای حذف به معنی انجام آن نیست.

**جمع‌بندی:** لایهٔ ورود و بارگذاری context معتبر با prepared statement و تصویر سمت سرور پیاده و آزموده شد، اما هنوز برنامهٔ قابل بهره‌برداری با رزرو اتمیک نیست. گام بعد تراکنش پذیرش و قفل منابع است که معیار آن در `JOMA-SERVICE-CONTRACTS-v0.1-FA.md` آمده است.
