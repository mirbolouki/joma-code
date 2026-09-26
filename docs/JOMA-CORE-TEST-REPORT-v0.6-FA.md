# گزارش آزمون هستهٔ جوما ۰٫۶

تاریخ ۲۰۲۶-۰۹-۲۶ — داده‌ها ۱۰۰٪ ساختگی.

## خلاصه

| مجموعه | 8.1.34 WASM | 8.4.25 WASM |
|---|---|---|
| `joma_domain_rules` | **۱۰۱/۱۰۱** | **۱۰۱/۱۰۱** |
| `joma_core_adapters` | **۵۷/۵۷** | **۵۷/۵۷** |
| `joma_core_acceptance` | **۳۳/۳۳** | **۳۳/۳۳** |
| `joma_core_hold` | **۲۵/۲۵** | **۲۵/۲۵** |
| `joma_core_portal` | **۲۰/۲۰** | **۲۰/۲۰** |
| `joma_core_receipt_audit` — جدید | **۲۸/۲۸** | **۲۸/۲۸** |
| `joma_core_lint` ۱۵ فایل | ۱۵/۱۵ | ۱۵/۱۵ |
| `joma_schema_static` | ۱۸/۱۸ | — |
| `joma_mariadb_static` | ۸/۸ | — |

جمع ۲۶۴ بررسی روی دو نسخه (۵۲۸ اجرای یکسان). parser `token_get_all(..., TOKEN_PARSE)`.

## پوشش جدید ۲۸ بررسی `receipt/audit`

- **Canonical**: مرتب‌سازی بازگشتی کلیدها، `{"a":1,"b":2,"c":{"y":2,"z":3}}` پایدار، رد غیرآرایه و وجود `NUL`.
- **Hash**: `sha256` hex۶۴ قطعی، کلید تصادفی `hex64` ↔ `BINARY(32)` رفت‌وبرگشت، رد `hex` بد.
- **Claim**: `INSERT PROCESSING` موفق → `INSERTED`؛ تکراری با `payload` یکسان و `SUCCEEDED` → `REPLAY_REQUIRES_CURRENT_AUTHORIZATION` (نیاز به مجوز فعلی)؛ `payload` متفاوت/actor متفاوت → `IDEMPOTENCY_CONFLICT`؛ `PROCESSING` → `COMMAND_IN_PROGRESS`؛ `system actor` نیز آزموده؛ کلید نامعتبر بدون `prepare`.
- **Complete**: `UPDATE … SUCCEEDED` موفق، `id` نامعتبر رد.
- **Audit**: `ALLOWED` درج با `?`، رد `secret/password/token` در `redacted_metadata_json`، رد `reason` بد، الزام دقیقاً یک `actor` (`person` یا `system`)، نگاشت `CAPACITY_CONFLICT→409`، `AUTH→401`، ناشناخته → `500`.
- همهٔ `INSERT/SELECT`ها با `?` و بدون درون‌گذاری؛ `UNIQUE(scope_id,idempotency_key)` در توضیح قرارداد.

## اجرا

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
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_lint.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_domain_rules.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_adapters.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_acceptance.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_hold.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_portal.php
PHP=8.4 tools/node_modules/.bin/php-wasm-cli tests/joma_core_receipt_audit.php
```

Node ۲۲٫۲۲٫۳/۱۰٫۹٫۸؛ هشدار engine (≥۲۴٫۱۸) مانع نشد.

## هنوز باز

- تراکنش واقعی `receipt` با `INSERT …` و `SELECT … FOR UPDATE` هم‌زمان، `deadlock/retry` و `ROLLBACK` روی `UNIQUE` روی MariaDB ۱۰٫۱۱.
- استریم فایل محافظت‌شده و `list/search` با همین مجوزها.
- سیاست نهایی محصول و اتصال مالی.

## تفکیک از هاست

هاست ۷۰ جدول و ۹ بررسی read-only را قبلاً تأیید کرده؛ این تحویل هاست را تغییر نمی‌دهد.

**جمع‌بندی:** لایهٔ idempotency/audit با `canonical` پایدار و `SELECT FOR UPDATE` روی `UNIQUE` با mock آزموده شد؛ گام بعد آزمون واقعی دو اتصالی است.
