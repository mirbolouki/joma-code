# گزارش آزمون هستهٔ جوما ۰٫۵

تاریخ ۲۰۲۶-۰۹-۲۶ — داده‌ها کاملاً ساختگی؛ هیچ دادهٔ واقعی استفاده نشده.

## خلاصه

| مجموعه | 8.1.34 WASM | 8.4.25 WASM |
|---|---|---|
| `joma_domain_rules` | **۱۰۱/۱۰۱** | **۱۰۱/۱۰۱** |
| `joma_core_adapters` | **۵۷/۵۷** | **۵۷/۵۷** |
| `joma_core_acceptance` | **۳۳/۳۳** | **۳۳/۳۳** |
| `joma_core_hold` | **۲۵/۲۵** | **۲۵/۲۵** |
| `joma_core_portal` — جدید | **۲۰/۲۰** | **۲۰/۲۰** |
| `joma_core_lint` ۱۳ فایل | ۱۳/۱۳ | ۱۳/۱۳ |
| `joma_schema_static` | ۱۸/۱۸ | — |
| `joma_mariadb_static` | ۸/۸ | — |

جمع ۲۳۶ بررسی روی دو نسخه تکرار (۴۷۲ اجرای یکسان). parser `token_get_all(..., TOKEN_PARSE)`.

## پوشش جدید ۲۰ بررسی portal

- **مخاطب صریح مستقیم**: `REPORT` با `publication PORTAL` و `audience DIRECT_PERSON` → `PORTAL_GUARDS_PASSED`؛ نبود `audience`، `publication` آینده/ابطالی، و `revision_state DRAFT` → `RESOURCE_NOT_AVAILABLE`.
- **نمایندگی**: `REPRESENTATIVE` با `VERIFIED + document.read` و بدون `restrictions` → مجاز؛ `SUSPENDED` همان `case` → رد؛ `case` دیگر → مجاز؛ `REVOKED` یا `allowed_actions=[]` → رد.
- **Entitlement**: محصول مورد نیاز با `source_verified` و بازهٔ معتبر → مجاز؛ نبود، `unverified`، یا انقضا در مرز (`valid_until == now`) → رد.
- **فرم**: `FORM_RESPONSE SUBMITTED` با مخاطب مستقیم → مجاز؛ `DRAFT` → رد.
- **جای‌گذاری**: همهٔ `SELECT`ها با `?` و بدون درون‌گذاری `UUID`؛ `publication+audience` با `JOIN` و `WHERE … ?` بارگذاری می‌شود.
- **یادداشت خصوصی**: `PRIVATE_NOTE` فقط نویسنده → `AUTHOR_MATCH_ONLY`، دیگری حتی با نقش بالینی → `AUTHOR_ONLY`.

## اجرا

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

یا

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

Node این محیط ۲۲٫۲۲٫۳/۱۰٫۹٫۸؛ هشدار engine (≥۲۴٫۱۸) مانع نشد.

## هنوز باز

- تحویل فایل محافظت‌شده خارج از `document root` با `X-Sendfile`/استریم، هدرهای `Cache-Control: private, no-store` و آزمون `list/search/download` با نقش‌های مختلف.
- دو اتصال هم‌زمان `hold` روی MariaDB واقعی و `deadlock/retry`.
- سیاست نهایی محصول/خدمت، هزینه، و بازگشت مالی؛ و رمزنگاری `PrivateNote`.

## تفکیک از هاست

هاست ۷۰ جدول/۱۷۳ FK/`RESTRICT`، ۷۲ `UNIQUE` و ۹۰ `CHECK` (۱۰۴ با `JSON_VALID`) را با ۹ بررسی read-only تأیید کرده بود؛ PHP ۸٫۱٫۳۴ و MariaDB ۱۰٫۱۱٫۱۹. ابزار موقت حذف شده؛ تست `write/concurrency` روی هاست انجام نشده و این تحویل هاست را تغییر نمی‌دهد.

**جمع‌بندی:** لایهٔ portal با مخاطب صریح، نمایندگی و entitlement با mock DB آزموده شد؛ گام بعد آزمون واقعی دو اتصالی و استریم فایل است.
