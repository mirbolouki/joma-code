# گزارش آزمون هستهٔ جوما ۰٫۸

تاریخ ۲۰۲۶-۰۹-۲۷ — داده‌ها ۱۰۰٪ ساختگی.

## خلاصه

| مجموعه | 8.1.34 WASM | 8.4.25 WASM |
|---|---|---|
| `joma_domain_rules` | **۱۰۱/۱۰۱** | **۱۰۱/۱۰۱** |
| `joma_core_adapters` | **۵۷/۵۷** | **۵۷/۵۷** |
| `joma_core_acceptance` | **۳۳/۳۳** | **۳۳/۳۳** |
| `joma_core_hold` | **۲۵/۲۵** | **۲۵/۲۵** |
| `joma_core_portal` | **۲۰/۲۰** | **۲۰/۲۰** |
| `joma_core_receipt_audit` | **۲۸/۲۸** | **۲۸/۲۸** |
| `joma_core_files` | **۲۲/۲۲** | **۲۲/۲۲** |
| `joma_core_e2e` — جدید | **۲۰/۲۰** | **۲۰/۲۰** |
| `joma_core_lint` ۱۸ فایل | ۱۸/۱۸ | ۱۸/۱۸ |
| `joma_schema_static` | ۱۸/۱۸ | — |
| `joma_mariadb_static` | ۸/۸ | — |

جمع **۳۰۶** بررسی روی دو نسخه (۶۱۲ اجرا). `token_get_all(..., TOKEN_PARSE)` برای lint.

## پوشش جدید ۲۰ بررسی سرتاسری

- **جریان کامل با یک `MockMysqli` ترتیبی**: `auth_login (WHERE login_name = ?)` → `context_load (JOIN + ?)` → `acceptance (FOR UPDATE + 5 INSERT + 2 UPDATE)` → `hold_create (FOR UPDATE + overlap)` → `hold_confirm` → `portal_check (JOIN publication+audience)` → `files_load + files_authorize` — همه با `?` و بدون درون‌گذاری؛ هر مرحله `COMMIT`/`ROLLBACK` سنجیده شد.
- **مسیر خطا**: درمانگر اشتباه با `snapshot` سازگار → `ASSIGNED_THERAPIST_REQUIRED` و `ROLLBACK`.
- **محدودسازی**: `sliding window` ۵ در ۶۰ ثانیه؛ ۵ مجاز، ششم رد، کلید دیگر مجاز، پس از ۲ دقیقه (لغزش پنجره) دوباره مجاز، و `remaining`.
- **HTTP**: نگاشت `CAPACITY_CONFLICT→409`، پیام فارسی بدون `stack`، و هدرهای `Content-Type/Cache-Control/X-Content-Type-Options`.
- **Receipt canonical** پایدار و عدم جهش `snapshot` پس از کل جریان.

## اجرا

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

یا

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

Node ۲۲٫۲۲٫۳/۱۰٫۹٫۸؛ هشدار engine ≥۲۴٫۱۸ مانع نشد.

## هنوز باز

- اجرای `hold/receipt` با دو اتصال هم‌زمان روی MariaDB ۱۰٫۱۱ واقعی و `deadlock/retry`.
- استریم واقعی `readfile` و `list/search` با همین مجوزها؛ و اتصال مالی.
- `rate_limit` تولید باید در DB/Redis با `FOR UPDATE` پیاده شود (این نسخه حافظه‌ای و تست‌محور است).

## تفکیک از هاست

هاست ۷۰ جدول و ۹ بررسی read-only را قبلاً تأیید کرده؛ این تحویل هاست را تغییر نمی‌دهد.

**جمع‌بندی:** جریان سرتاسری `login→file` با مخاطب صریح و قفل منابع با mock ترتیبی آزموده شد؛ گام بعد آزمون واقعی دو اتصالی است.
