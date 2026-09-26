# گزارش آزمون هستهٔ جوما ۰٫۷

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
| `joma_core_files` — جدید | **۲۲/۲۲** | **۲۲/۲۲** |
| `joma_core_lint` ۱۶ فایل | ۱۶/۱۶ | ۱۶/۱۶ |
| `joma_schema_static` | ۱۸/۱۸ | — |
| `joma_mariadb_static` | ۸/۸ | — |

جمع ۲۸۶ بررسی روی دو نسخه (۵۷۲ اجرا). `token_get_all(..., TOKEN_PARSE)`.

## پوشش جدید ۲۲ بررسی فایل محافظت‌شده

- اعتبارسنجی `state=READY`، `storage_key` (`[a-zA-Z0-9_\\-./]`، `..` ممنوع)، `byte_length>0`، `sha256` دقیق ۳۲ بایت، و `media_type`.
- بارگذاری `joma_protected_files WHERE id = ?` با `?` و بدون درون‌گذاری؛ `NOT_FOUND` → `null`.
- مجوز: پیوند `report_versions.protected_file_id = file.id` باید `FOR UPDATE` وجود داشته باشد؛ نبود → رد؛ نبود `audience` portal → رد؛ `FORM` برای فایل فعلاً رد.
- نمایندگان با `document.read` بدون محدودیت → مجاز (بازاستفاده از `portal_check`).
- هدرها: `Content-Type` از `media_type`، `Content-Disposition: attachment` با نام پاک‌سازی‌شده (کنترل حذف)، `Cache-Control: private, no-store`، `ETag` از `sha256 hex` و `X-Content-Type-Options: nosniff`؛ `inline` نیز آزموده.
- مسیر: `baseDir + '/' + storage_key` خارج از `document root`، `..` → `null`.
- عدم جهش `snapshot` و استفاده صرف از `?`.

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
python tests/joma_schema_static.py
python tests/joma_mariadb_static.py
```

یا با WASM (Node ۲۲٫۲۲٫۳/۱۰٫۹٫۸؛ هشدار engine ≥۲۴٫۱۸ مانع نشد):

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

## هنوز باز

- استریم واقعی `readfile`/`fpassthru` با بررسی `byte_length` و محدودیت سرعت روی هاست.
- آزمون دو اتصالی `hold/receipt` روی MariaDB واقعی و `deadlock/retry`.
- سیاست نهایی محصول/مالی.

## تفکیک از هاست

هاست ۷۰ جدول و ۹ بررسی read-only را قبلاً تأیید کرده؛ این تحویل هاست را تغییر نمی‌دهد.

**جمع‌بندی:** لایهٔ فایل محافظت‌شده با اعتبارسنجی خارج از دسترس عمومی و تفویض به `portal` با mock آزموده شد.
