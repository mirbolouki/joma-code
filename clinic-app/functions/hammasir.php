<?php
/*
 * ماژول «هم‌مسیر» (hammasir) — لایه‌ی داده — Phase 1A (Tier A / Add-Only)
 * ------------------------------------------------------------------
 * الزامات فاز 1A (مصوب PO):
 * - این فایل در زمان include فقط «تعریف» است؛ هیچ اتصال DB، query،
 *   mutation، ساخت پوشه یا نوشتن فایل انجام نمی‌شود.
 * - در Phase 1A هیچ بخشی از JOMA این فایل را include نمی‌کند؛
 *   ادغام (route/flag) مربوط به Phase 1B و با مجوز جداگانه است.
 * - سازگار با PHP 7.x — بدون قابلیت‌های نسخه‌های جدیدتر.
 * - ارجاع به joma_users فقط «منطقی» است؛ بدون FK فیزیکی (D25).
 * - جدول تنظیمات نداریم (D40)؛ policyها فقط از config مستقل + پیش‌فرض یک‌نقطه‌ای (D9).
 * - بدون mbstring ماژول fail-closed می‌ماند؛ fallback به strlen ممنوع (AC1.7/R18).
 * - مبنای زمانی: timezone صریح Asia/Tehran (D35)؛
 *   jalali_today() و includes/jalali.php فعلی JOMA تغییر نمی‌کنند.
 *
 * پیش‌نیاز include (در 1B): توابع فعلی JOMA از bootstrap لود شده باشند
 * (store_mode, db, joma_query, joma_query_one, joma_exec).
 *
 * نکته برای 1B (D28 fail-closed): db() فعلی JOMA در خطای اتصال die می‌کند؛
 * گارد Flag و در دسترس‌بودن باید «قبل از» هر فراخوانی عملیات هم‌مسیر باشد.
 *
 * نکته file mode (R7): قفل فایل روی فایل‌سیستم شبکه‌ای (NFS) تضمین نیست؛
 * در Preflight استقرار بررسی می‌شود.
 *
 * ارجاع مستندات: docs/HAMMASIR-PHASE0.md (D0 تا D40، ACها، Risk Register)
 */

if (defined('HAMMASIR_PHP')) return;
define('HAMMASIR_PHP', '1a');
define('HAMMASIR_TZ', 'Asia/Tehran');          // D35 — timezone صریح ماژول
define('HAMMASIR_MESSAGE_MAX_LEN', 2000);      // D11 — تنها نقطه‌ی تعریف حد پیام
define('HAMMASIR_LOCK_TIMEOUT', 2);            // D14 — مهلت قفل canonical (ثانیه)
define('HAMMASIR_PAGE_SIZE', 100);             // D36 — صفحه‌بندی پیش‌فرض پیام‌ها

/* ==================================================================
 * بخش ۱) Whitelistهای Backend (AC6.6)
 * ================================================================== */

function hammasir_perm_keys() {
    return array(
        'VIEW_SUMMARY',
        'VIEW_PROGRESS',
        'VIEW_ACTIVITY_DETAILS',
        'VIEW_MOOD',
        'CLIENT_CAN_MESSAGE_COMPANION',
        'COMPANION_CAN_MESSAGE_CLIENT',
    );
}

function hammasir_is_valid_perm_key($key) {
    return in_array($key, hammasir_perm_keys(), true);
}

function hammasir_link_statuses() {
    return array('PENDING', 'ACTIVE', 'DECLINED', 'REVOKED');
}

function hammasir_is_valid_link_status($status) {
    return in_array($status, hammasir_link_statuses(), true);
}

function hammasir_provider_statuses() {
    return array('ACTIVE', 'INACTIVE');
}

function hammasir_is_valid_provider_status($status) {
    return in_array($status, hammasir_provider_statuses(), true);
}

function hammasir_event_types() {
    return array('LINK_REQUESTED', 'LINK_ACCEPTED', 'LINK_DECLINED', 'LINK_REVOKED', 'LINK_CANCELLED');
}

function hammasir_is_valid_event_type($type) {
    return in_array($type, hammasir_event_types(), true);
}

function hammasir_change_sources() {
    return array('INITIAL_CONSENT', 'CLIENT_UPDATE', 'PROVIDER_MESSAGE_SETTING', 'SYSTEM');
}

function hammasir_is_valid_change_source($source) {
    return in_array($source, hammasir_change_sources(), true);
}

/* ==================================================================
 * بخش ۲) Config مستقل / Feature Flag / Policyها (D26/D28/D9/D40)
 * ================================================================== */

function hammasir_config() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = array();
    $file = dirname(__FILE__) . '/../config/hammasir_config.php';
    if (is_file($file)) {
        $HAMMASIR_CONFIG = null;
        include $file;
        if (is_array($HAMMASIR_CONFIG)) $cache = $HAMMASIR_CONFIG;
    }
    return $cache;
}

function hammasir_enabled() {
    $cfg = hammasir_config();
    // fail-closed (D28): نبود فایل یا هر مقداری غیر true → خاموش
    return isset($cfg['hammasir_enabled']) && $cfg['hammasir_enabled'] === true;
}

function hammasir_policy_defaults() {
    // تنها نقطه‌ی تعریف مقادیر پیش‌فرض policyها (D9/D40)
    return array(
        'daily_message_limit' => 3,
        'companion_daily_message_limit' => 20,
        'message_cooldown_seconds' => 5,
        're_request_cooldown_hours' => 24,
    );
}

function hammasir_policy($key) {
    // زنجیره‌ی واحد (D40): config مستقل هم‌مسیر → پیش‌فرض یک‌نقطه‌ای
    $cfg = hammasir_config();
    if (isset($cfg[$key]) && is_int($cfg[$key]) && $cfg[$key] > 0) return $cfg[$key];
    $defaults = hammasir_policy_defaults();
    if (isset($defaults[$key]) && $defaults[$key] > 0) return $defaults[$key];
    return null;
}

function hammasir_message_daily_limit() {
    return hammasir_policy('daily_message_limit');
}

function hammasir_companion_daily_limit() {
    return hammasir_policy('companion_daily_message_limit');
}

function hammasir_message_cooldown_seconds() {
    return hammasir_policy('message_cooldown_seconds');
}

function hammasir_re_request_cooldown_hours() {
    return hammasir_policy('re_request_cooldown_hours');
}

/* ==================================================================
 * بخش ۳) زمان (D35) و پیش‌نیاز محیط (AC1.7 — fail-closed)
 * ================================================================== */

function hammasir_now() {
    // زمان سرور ماژول با TZ صریح Asia/Tehran — مستقل از TZ پیش‌فرض سرور (D35)
    $dt = new DateTime('now', new DateTimeZone(HAMMASIR_TZ));
    return $dt->format('Y-m-d H:i:s');
}

function hammasir_jalali_today() {
    // روز جاری شمسی با TZ صریح Asia/Tehran (D35).
    // تبدیل شمسی با توابع فعلی JOMA (بدون تغییر آن‌ها) انجام می‌شود؛
    // اگر توابع JOMA در دسترس نباشند، fail-closed (null) — بدون fallback به date() سرور.
    if (!function_exists('gregorian_to_jalali') || !function_exists('jalali_pad')) {
        return null;
    }
    $dt = new DateTime('now', new DateTimeZone(HAMMASIR_TZ));
    $p = gregorian_to_jalali((int) $dt->format('Y'), (int) $dt->format('n'), (int) $dt->format('j'));
    return $p[0] . '-' . jalali_pad($p[1]) . '-' . jalali_pad($p[2]);
}

function hammasir_mbstring_available() {
    return function_exists('mb_strlen');
}

function hammasir_mb_strlen($s) {
    // wrapper واحد طول متن (حکم PO — بستن 1C):
    // تمام اعتبارسنجی‌های طول (title/body) فقط از همین تابع انجام می‌شوند.
    // fail-closed: بدون mbstring هرگز strlen جایگزین نمی‌شود (AC1.7/R18 — استثنای مستند PC).
    // خروجی: طول صحیح | false در نبود mbstring → عملیات رد می‌شود.
    if (!hammasir_mbstring_available()) return false;
    return mb_strlen((string) $s);
}

function hammasir_env_ok() {
    // پیش‌نیاز محیطی ماژول؛ فعال‌سازی Feature تا رفع پیش‌نیاز مجاز نیست (AC1.7/R18).
    // صریح: اگر function_exists('mb_strlen') برقرار نباشد → env_ok = false → fail-closed کامل.
    return function_exists('mb_strlen');
}

function hammasir_validate_message_body($body) {
    // اعتبارسنجی بدنه‌ی پیام (D11) — سمت سرور؛ طول فقط از طریق hammasir_mb_strlen (fail-closed)
    $body = trim((string) $body);
    if ($body === '') {
        return array('ok' => false, 'error' => 'empty');
    }
    $len = hammasir_mb_strlen($body);
    if ($len === false) {
        return array('ok' => false, 'error' => 'env');
    }
    if ($len > HAMMASIR_MESSAGE_MAX_LEN) {
        return array('ok' => false, 'error' => 'too_long');
    }
    return array('ok' => true, 'body' => $body);
}

/* ==================================================================
 * بخش ۴) File mode — storage مستقل (D16/D24 — گزینه A)
 * ------------------------------------------------------------------
 * - storage فقط data/hammasir/store.json ؛ قفل فقط data/hammasir/store.lock
 * - هیچ خواندن/نوشتی روی data/store.json یا writerهای فعلی JOMA انجام نمی‌شود.
 * - ساخت پوشه/فایل فقط «lazy» و در اولین mutation واقعی انجام می‌شود
 *   (در Phase 1A هیچ mutation واقعی اجرا نمی‌شود).
 * - شکست load → هیچ نوشتی انجام نمی‌شود.
 * - ذخیره: فایل موقت در همان دایرکتوری + rename اتمیک؛
 *   JSON پیش از rename اعتبارسنجی می‌شود.
 * - پیش از اولین بازنویسی store.json، نسخه‌ی ابتدای عمر داده در
 *   store.json.bak حفظ می‌شود (backup پیش از اولین ذخیره ماژول).
 * ================================================================== */

function hammasir_store_dir() {
    return dirname(__FILE__) . '/../data/hammasir';
}

function hammasir_store_path() {
    return hammasir_store_dir() . '/store.json';
}

function hammasir_store_lock_path() {
    return hammasir_store_dir() . '/store.lock';
}

function hammasir_store_empty() {
    // ۶ موجودیت کسب‌وکاری + seq — بدون کلید settings (D40).
    // user_flags: فراداده‌ی داخلی ماژول (per-user flag مانند onboarding_seen/mode)
    // — موجودیت کسب‌وکاری نیست و در شمارش D40 نمی‌آید (حکم PO — بخش ۲/۳).
    return array(
        'providers' => array(),
        'links' => array(),
        'permissions' => array(),
        'permission_history' => array(),
        'messages' => array(),
        'system_events' => array(),
        'user_flags' => array(),
        'seq' => 1,
    );
}

function hammasir_next_id(&$store) {
    $id = isset($store['seq']) ? (int) $store['seq'] : 1;
    $store['seq'] = $id + 1;
    return $id;
}

function hammasir_store_load() {
    // خواندن بدون هیچ نوشتن/ساخت پوشه‌ای.
    // خطا/خرابی JSON → null (fail-closed)؛ فراخواننده نباید در این حالت بنویسد.
    $path = hammasir_store_path();
    if (!is_file($path)) return hammasir_store_empty();
    $raw = @file_get_contents($path);
    // فایل ۰ بایت = «هنوز داده‌ای نیست» (نوشتن قبلی ناتمام/خراب) → مثل نبود فایل،
    // store خالی برمی‌گردد تا اولین mutation بتواند نجات دهد (رفع بن‌بست خاموش نوشتن).
    // JSON نامعتبرِ «نامخالی» همچنان null می‌ماند (fail-closed واقعی — D14/D15).
    if ($raw === false) return null;
    if ($raw === '') return hammasir_store_empty();
    $data = json_decode($raw, true);
    if (!is_array($data)) return null;
    $base = hammasir_store_empty();
    foreach ($base as $k => $v) {
        if (!array_key_exists($k, $data)) $data[$k] = $v;
    }
    return $data;
}

function hammasir_store_save_atomic($data) {
    $dir = hammasir_store_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return false;
    $path = hammasir_store_path();
    $bak = $path . '.bak';
    // backup فقط یک‌بار، پیش از اولین بازنویسی (حفظ ابتدای عمر داده)
    if (is_file($path) && !is_file($bak)) {
        if (!@copy($path, $bak)) return false;
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($json === false || $json === '') return false;
    // اعتبارسنجی JSON پیش از rename
    if (!is_array(json_decode($json, true))) return false;
    $tmp = $path . '.tmp.' . uniqid('', true);
    if (@file_put_contents($tmp, $json) === false) {
        @unlink($tmp);
        return false;
    }
    // rename در همان دایرکتوری = اتمیک روی همان فایل‌سیستم
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function hammasir_store_mutate($callback) {
    // الگوی واحد mutation در file mode (D14/D15 — معادل قفل canonical + تراکنش):
    //   قفل فایل انحصاری (مهلت HAMMASIR_LOCK_TIMEOUT ثانیه) → خواندن →
    //   callback(&$store) → ذخیره‌ی اتمیک → آزادسازی قفل در هر مسیر.
    //
    // قرارداد callback:
    //   function (&$store) use (...) { ... }
    //   - return false → لغو کامل بدون ذخیره
    //   - return array/مقدار → ذخیره و همان نتیجه برگردانده می‌شود
    //   - return null → ذخیره و true برگردانده می‌شود
    $dir = hammasir_store_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return false;
    $lock = @fopen(hammasir_store_lock_path(), 'c');
    if (!$lock) return false;
    $deadline = microtime(true) + (float) HAMMASIR_LOCK_TIMEOUT;
    $locked = false;
    while (true) {
        $locked = @flock($lock, LOCK_EX | LOCK_NB);
        if ($locked) break;
        if (microtime(true) >= $deadline) break;
        usleep(50000);
    }
    if (!$locked) {
        @fclose($lock);
        return false; // معادل خطای timeout قفل: «لطفاً دوباره تلاش کنید.»
    }
    try {
        $store = hammasir_store_load();
        if ($store === null) return false; // شکست load → هیچ نوشتی
        $result = $callback($store);
        if ($result === false) return false;
        if (!hammasir_store_save_atomic($store)) return false;
        return ($result === null) ? true : $result;
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

/* ==================================================================
 * بخش ۵) قفل canonical و تراکنش MySQL (D14/D15)
 * ------------------------------------------------------------------
 * - قفل نامدار joma_hammasir_client_{client_user_id} با مهلت ۲ ثانیه
 *   روی «همان اتصال» مشترک JOMA (db())؛ بدون اتصال جدید.
 * - ترتیب: GET_LOCK → BEGIN → callback($mysqli) → COMMIT / ROLLBACK
 *   → آزادسازی تضمین‌شده‌ی قفل در finally (هر دو مسیر موفق/خطا).
 * - در file mode این تابع کاربرد ندارد؛ قفل canonical همان قفل فایل
 *   داخل hammasir_store_mutate است (D14/D22) و false برمی‌گردد.
 * ================================================================== */

function hammasir_lock_name($client_user_id) {
    // R6: joma_hammasir_client_ + id — بسیار کوتاه‌تر از سقف طول نام قفل MySQL
    return 'joma_hammasir_client_' . (int) $client_user_id;
}

function hammasir_with_lock($client_user_id, $fn) {
    // D-1 (PHP 8.1): خطاهای mysqli به‌صورت exception پرتاب می‌شوند؛ کل مسیر — از جمله
    // GET_LOCK و db() — داخل try مهار می‌شود و نتیجه به کد داخلی (false/نتیجه) تبدیل می‌گردد.
    // آزادسازی قفل در finally با مهار خودش؛ rollback در catch با مهار خودش (بدون پرتاب به بیرون).
    if (store_mode() !== 'mysql') {
        // در file mode از hammasir_store_mutate استفاده شود
        return false;
    }
    $mysqli = null;
    $name = hammasir_lock_name($client_user_id);
    $lock_acquired = false;
    $in_txn = false;
    try {
        $mysqli = db();
        if (!$mysqli) return false;
        $row = joma_query_one('SELECT GET_LOCK(?, ' . (int) HAMMASIR_LOCK_TIMEOUT . ')', 's', array($name));
        // فاز ۲ (AC4.5): تفکیک timeout قفل از خطا — همان الگوی D41 در registry_with_lock.
        // caller این تابع تا پیش از فاز ۲ وجود نداشت؛ تغییر رفتار بدون اثر روی کد موجود است.
        if (!$row || (int) current($row) !== 1) return '__LOCK_TIMEOUT__'; // timeout → «لطفاً دوباره تلاش کنید.»
        $lock_acquired = true;
        if (!mysqli_begin_transaction($mysqli)) return false;
        $in_txn = true;
        $result = call_user_func($fn, $mysqli);
        if ($result === false) {
            mysqli_rollback($mysqli);
            $in_txn = false;
            return false;
        }
        if (!mysqli_commit($mysqli)) {
            mysqli_rollback($mysqli);
            $in_txn = false;
            return false;
        }
        $in_txn = false;
        return ($result === null) ? true : $result;
    } catch (Throwable $e) {
        if ($in_txn && $mysqli) {
            try { @mysqli_rollback($mysqli); } catch (Throwable $e2) { /* rollback ناموفق؛ اتصال بسته می‌شود */ }
        }
        return false;
    } finally {
        if ($lock_acquired) {
            try {
                joma_exec('SELECT RELEASE_LOCK(?)', 's', array($name));
            } catch (Throwable $e3) {
                // آزادسازی صریح ناموفق؛ ایمنی آخر: آزادسازی خودکار MySQL در پایان اتصال (D1)
            }
        }
    }
}

/* ==================================================================
 * بخش ۶) موجودیت‌ها — خواندن (دو شاخه mysql/file)
 * ------------------------------------------------------------------
 * خواندن‌ها بدون قفل (الگوی فعلی JOMA)؛ همه‌ی نوشتن‌ها فقط از طریق
 * عملیات مرکب فازهای بعد و داخل قفل canonical انجام می‌شوند.
 * در شاخه file، خرابی storage → null (fail-closed، نه آرایه‌ی خالی).
 * در شاخه mysql، خطای query توسط helperهای فعلی JOMA به نتیجه‌ی خالی
 * تبدیل می‌شود؛ تفکیک خطای DB در محیط test بررسی می‌شود.
 * ================================================================== */

function hammasir_provider_by_user_id($user_id) {
    if (store_mode() === 'mysql') {
        return joma_query_one('SELECT * FROM joma_hammasir_providers WHERE user_id=?', 'i', array((int) $user_id));
    }
    $store = hammasir_store_load();
    if ($store === null) return null;
    foreach ($store['providers'] as $p) {
        if ((int) $p['user_id'] === (int) $user_id) return $p;
    }
    return null;
}

function hammasir_provider_list($only_active = true) {
    if (store_mode() === 'mysql') {
        $sql = 'SELECT * FROM joma_hammasir_providers';
        if ($only_active) $sql .= " WHERE status = 'ACTIVE'";
        $sql .= ' ORDER BY title ASC, user_id ASC';
        return joma_query($sql, '', array());
    }
    $store = hammasir_store_load();
    if ($store === null) return null;
    $out = array();
    foreach ($store['providers'] as $p) {
        if ($only_active && $p['status'] !== 'ACTIVE') continue;
        $out[] = $p;
    }
    usort($out, function ($a, $b) {
        $c = strcmp($a['title'], $b['title']);
        if ($c !== 0) return $c;
        return (int) $a['user_id'] - (int) $b['user_id'];
    });
    return $out;
}

function hammasir_link_get($link_id) {
    if (store_mode() === 'mysql') {
        return joma_query_one('SELECT * FROM joma_hammasir_links WHERE id=?', 'i', array((int) $link_id));
    }
    $store = hammasir_store_load();
    if ($store === null) return null;
    foreach ($store['links'] as $l) {
        if ((int) $l['id'] === (int) $link_id) return $l;
    }
    return null;
}

function hammasir_link_open_by_client($client_user_id) {
    // D6: حداکثر یک لینک باز (PENDING/ACTIVE) — اعمال در کد داخل قفل؛
    // این خواندن فقط «وجود/عدم وجود» لینک باز را برمی‌گرداند.
    if (store_mode() === 'mysql') {
        return joma_query_one(
            "SELECT * FROM joma_hammasir_links WHERE client_user_id=? AND status IN ('PENDING','ACTIVE') ORDER BY id DESC LIMIT 1",
            'i',
            array((int) $client_user_id)
        );
    }
    $store = hammasir_store_load();
    if ($store === null) return null;
    $found = null;
    foreach ($store['links'] as $l) {
        if ((int) $l['client_user_id'] === (int) $client_user_id
            && ($l['status'] === 'PENDING' || $l['status'] === 'ACTIVE')) {
            if ($found === null || (int) $l['id'] > (int) $found['id']) $found = $l;
        }
    }
    return $found;
}

function hammasir_links_by_provider($provider_user_id, $status = null) {
    $want = null;
    if ($status !== null) {
        if (!hammasir_is_valid_link_status($status)) return array();
        $want = $status;
    }
    if (store_mode() === 'mysql') {
        if ($want !== null) {
            return joma_query(
                'SELECT * FROM joma_hammasir_links WHERE provider_user_id=? AND status=? ORDER BY id DESC',
                'is',
                array((int) $provider_user_id, $want)
            );
        }
        return joma_query('SELECT * FROM joma_hammasir_links WHERE provider_user_id=? ORDER BY id DESC', 'i', array((int) $provider_user_id));
    }
    $store = hammasir_store_load();
    if ($store === null) return null;
    $out = array();
    foreach ($store['links'] as $l) {
        if ((int) $l['provider_user_id'] !== (int) $provider_user_id) continue;
        if ($want !== null && $l['status'] !== $want) continue;
        $out[] = $l;
    }
    return array_reverse($out);
}

function hammasir_links_closed_between($client_user_id, $provider_user_id) {
    // همه‌ی ارتباط‌های بسته (DECLINED/REVOKED) بین یک جفت مراجع/همراه — مبنای D34
    if (store_mode() === 'mysql') {
        return joma_query(
            "SELECT * FROM joma_hammasir_links WHERE client_user_id=? AND provider_user_id=? AND status IN ('DECLINED','REVOKED') ORDER BY id DESC",
            'ii',
            array((int) $client_user_id, (int) $provider_user_id)
        );
    }
    $store = hammasir_store_load();
    if ($store === null) return null;
    $out = array();
    foreach ($store['links'] as $l) {
        if ((int) $l['client_user_id'] === (int) $client_user_id
            && (int) $l['provider_user_id'] === (int) $provider_user_id
            && ($l['status'] === 'DECLINED' || $l['status'] === 'REVOKED')) {
            $out[] = $l;
        }
    }
    return array_reverse($out);
}

function hammasir_re_request_block_until($client_user_id, $provider_user_id) {
    // D34: لحظه‌ی بازشدن مجوز درخواست مجدد (unix timestamp) برای همان جفت؛
    // null = بدون محدودیت. مبنای محاسبه: declined_at / revoked_at سرور
    // (شامل REVOKED توسط هر طرف و لغو PENDING توسط مراجع — PENDING→REVOKED طبق D20).
    $rows = hammasir_links_closed_between($client_user_id, $provider_user_id);
    if ($rows === null) return null;
    $max = null;
    foreach ($rows as $l) {
        $t = null;
        if ($l['status'] === 'DECLINED' && isset($l['declined_at']) && $l['declined_at'] !== '') {
            $t = $l['declined_at'];
        } elseif (isset($l['revoked_at']) && $l['revoked_at'] !== '') {
            $t = $l['revoked_at'];
        }
        if ($t !== null && ($max === null || strcmp($t, $max) > 0)) $max = $t;
    }
    if ($max === null) return null;
    $hours = hammasir_policy('re_request_cooldown_hours');
    if ($hours === null || $hours < 1) return null;
    $ts = strtotime($max . ' ' . HAMMASIR_TZ);
    if ($ts === false) return null;
    return $ts + $hours * 3600;
}

function hammasir_permissions_for_link($link_id) {
    if (store_mode() === 'mysql') {
        $rows = joma_query('SELECT perm_key, enabled FROM joma_hammasir_permissions WHERE link_id=?', 'i', array((int) $link_id));
        $map = array();
        foreach ($rows as $r) {
            $map[$r['perm_key']] = ((int) $r['enabled'] === 1);
        }
        return $map;
    }
    $store = hammasir_store_load();
    if ($store === null) return null;
    $map = array();
    foreach ($store['permissions'] as $p) {
        if ((int) $p['link_id'] === (int) $link_id) {
            $map[$p['perm_key']] = ((int) $p['enabled'] === 1);
        }
    }
    return $map;
}

function hammasir_permission_history_for_link($link_id) {
    if (store_mode() === 'mysql') {
        return joma_query('SELECT * FROM joma_hammasir_permission_history WHERE link_id=? ORDER BY id ASC', 'i', array((int) $link_id));
    }
    $store = hammasir_store_load();
    if ($store === null) return null;
    $out = array();
    foreach ($store['permission_history'] as $h) {
        if ((int) $h['link_id'] === (int) $link_id) $out[] = $h;
    }
    return $out;
}

function hammasir_messages_count_today($link_id, $sender_user_id, $jalali_date) {
    // شمارش سقف روزانه: link + sender + تاریخ شمسی (D32/P7 — تاریخ فقط از hammasir_jalali_today)
    if (store_mode() === 'mysql') {
        $row = joma_query_one(
            'SELECT COUNT(*) AS c FROM joma_hammasir_messages WHERE link_id=? AND sender_user_id=? AND jalali_date=?',
            'iis',
            array((int) $link_id, (int) $sender_user_id, (string) $jalali_date)
        );
        return $row ? (int) $row['c'] : null;
    }
    $store = hammasir_store_load();
    if ($store === null) return null;
    $n = 0;
    foreach ($store['messages'] as $m) {
        if ((int) $m['link_id'] === (int) $link_id
            && (int) $m['sender_user_id'] === (int) $sender_user_id
            && $m['jalali_date'] === $jalali_date) {
            $n++;
        }
    }
    return $n;
}

function hammasir_messages_last_sender_time($link_id, $sender_user_id) {
    // آخرین زمان ارسال همان link+sender — مبنای کول‌داون (P3)
    if (store_mode() === 'mysql') {
        $row = joma_query_one(
            'SELECT created_at FROM joma_hammasir_messages WHERE link_id=? AND sender_user_id=? ORDER BY created_at DESC, id DESC LIMIT 1',
            'ii',
            array((int) $link_id, (int) $sender_user_id)
        );
        return $row ? $row['created_at'] : null;
    }
    $store = hammasir_store_load();
    if ($store === null) return null;
    $max = null;
    foreach ($store['messages'] as $m) {
        if ((int) $m['link_id'] === (int) $link_id && (int) $m['sender_user_id'] === (int) $sender_user_id) {
            if ($max === null || strcmp($m['created_at'], $max) > 0) $max = $m['created_at'];
        }
    }
    return $max;
}

function hammasir_messages_page($link_id, $before_id = 0, $limit = 0) {
    // D36/P6/AC4.9: پیش‌فرض آخرین ۱۰۰ پیام + امکان قدیمی‌تر (id < before_id)؛
    // خروجی صعودی (قدیمی بالا، جدید پایین)؛ ترتیب پایدار created_at سپس id.
    if ($limit < 1) $limit = HAMMASIR_PAGE_SIZE;
    if (store_mode() === 'mysql') {
        $sql = 'SELECT * FROM joma_hammasir_messages WHERE link_id=?';
        $types = 'i';
        $values = array((int) $link_id);
        if ($before_id > 0) {
            $sql .= ' AND id < ?';
            $types .= 'i';
            $values[] = (int) $before_id;
        }
        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ?';
        $types .= 'i';
        $values[] = (int) $limit;
        return array_reverse(joma_query($sql, $types, $values));
    }
    $store = hammasir_store_load();
    if ($store === null) return null;
    $out = array();
    foreach ($store['messages'] as $m) {
        if ((int) $m['link_id'] !== (int) $link_id) continue;
        if ($before_id > 0 && (int) $m['id'] >= (int) $before_id) continue;
        $out[] = $m;
    }
    if (count($out) > $limit) $out = array_slice($out, -$limit);
    return $out;
}

function hammasir_messages_unread_count($recipient_user_id) {
    // Badge پیام متنی: فقط لینک‌های ACTIVE (D21/AC5.4)
    if (store_mode() === 'mysql') {
        $row = joma_query_one(
            "SELECT COUNT(*) AS c FROM joma_hammasir_messages m INNER JOIN joma_hammasir_links l ON m.link_id = l.id WHERE m.recipient_user_id=? AND m.is_read=0 AND l.status='ACTIVE'",
            'i',
            array((int) $recipient_user_id)
        );
        return $row ? (int) $row['c'] : null;
    }
    $store = hammasir_store_load();
    if ($store === null) return null;
    $active = array();
    foreach ($store['links'] as $l) {
        if ($l['status'] === 'ACTIVE') $active[(int) $l['id']] = true;
    }
    $n = 0;
    foreach ($store['messages'] as $m) {
        if ((int) $m['recipient_user_id'] === (int) $recipient_user_id
            && (int) $m['is_read'] === 0
            && isset($active[(int) $m['link_id']])) {
            $n++;
        }
    }
    return $n;
}

function hammasir_events_unread_count($recipient_user_id) {
    // Badge رویداد سیستمی: مستقل از وضعیت لینک (D21/AC5.5)
    if (store_mode() === 'mysql') {
        $row = joma_query_one(
            'SELECT COUNT(*) AS c FROM joma_hammasir_system_events WHERE recipient_user_id=? AND is_read=0',
            'i',
            array((int) $recipient_user_id)
        );
        return $row ? (int) $row['c'] : null;
    }
    $store = hammasir_store_load();
    if ($store === null) return null;
    $n = 0;
    foreach ($store['system_events'] as $ev) {
        if ((int) $ev['recipient_user_id'] === (int) $recipient_user_id && (int) $ev['is_read'] === 0) $n++;
    }
    return $n;
}

function hammasir_events_list($recipient_user_id, $limit = 0) {
    // رویدادهای سیستمی recipient — جدیدترین اول؛ ترتیب پایدار created_at سپس id (D32)
    if ($limit < 1) $limit = HAMMASIR_PAGE_SIZE;
    if (store_mode() === 'mysql') {
        return joma_query(
            'SELECT * FROM joma_hammasir_system_events WHERE recipient_user_id=? ORDER BY created_at DESC, id DESC LIMIT ?',
            'ii',
            array((int) $recipient_user_id, (int) $limit)
        );
    }
    $store = hammasir_store_load();
    if ($store === null) return null;
    $out = array();
    foreach ($store['system_events'] as $ev) {
        if ((int) $ev['recipient_user_id'] === (int) $recipient_user_id) $out[] = $ev;
    }
    if (count($out) > $limit) $out = array_slice($out, -$limit);
    return array_reverse($out);
}

/* ==================================================================
 * بخش ۷) موجودیت‌ها — نوشتن (primitiveهای دو شاخه)
 * ------------------------------------------------------------------
 * قواعد مهم (D14/D15):
 * - این توابع هرگز مستقیماً از UI/route صدا زده نمی‌شوند.
 * - شاخه db_*: فقط داخل callbackِ hammasir_with_lock
 *   (قفل نامدار + تراکنش روی همان اتصال).
 * - شاخه file_*: فقط داخل callbackِ hammasir_store_mutate روی &$store؛
 *   نوشتن‌های چندگانه در یک callback یکجا و اتمیک اعمال می‌شوند.
 * - عملیات‌های مرکب (ساخت درخواست، ارسال پیام و…) در فازهای بعد
 *   روی همین primitiveها ساخته می‌شوند.
 * ================================================================== */

function hammasir_db_last_insert_id() {
    $mysqli = db();
    return $mysqli ? (int) mysqli_insert_id($mysqli) : 0;
}

/* ---------- providers (D30) ---------- */

function hammasir_db_provider_upsert($user_id, $title, $status) {
    if (!hammasir_is_valid_provider_status($status)) return false;
    $now = hammasir_now();
    return joma_exec(
        'INSERT INTO joma_hammasir_providers (user_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title = VALUES(title), status = VALUES(status), updated_at = VALUES(updated_at)',
        'issss',
        array((int) $user_id, (string) $title, $status, $now, $now)
    );
}

function hammasir_file_provider_upsert(&$store, $user_id, $title, $status) {
    if (!hammasir_is_valid_provider_status($status)) return false;
    $now = hammasir_now();
    foreach ($store['providers'] as $i => $p) {
        if ((int) $p['user_id'] === (int) $user_id) {
            $store['providers'][$i]['title'] = (string) $title;
            $store['providers'][$i]['status'] = $status;
            $store['providers'][$i]['updated_at'] = $now;
            return (int) $p['id'];
        }
    }
    $id = hammasir_next_id($store);
    $store['providers'][] = array(
        'id' => $id,
        'user_id' => (int) $user_id,
        'status' => $status,
        'title' => (string) $title,
        'created_at' => $now,
        'updated_at' => $now,
    );
    return $id;
}

/* ---------- links (D6/D20/D34) ---------- */

function hammasir_db_link_insert($provider_user_id, $client_user_id, $consent_text, $consent_version) {
    // لینک جدید همیشه PENDING است (D20)؛ درخواست = فاز ۲
    $now = hammasir_now();
    $ok = joma_exec(
        'INSERT INTO joma_hammasir_links (provider_user_id, client_user_id, status, consent_text, consent_version, requested_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        'iissssss',
        array((int) $provider_user_id, (int) $client_user_id, 'PENDING', (string) $consent_text, (string) $consent_version, $now, $now, $now)
    );
    return $ok ? hammasir_db_last_insert_id() : 0;
}

function hammasir_file_link_insert(&$store, $provider_user_id, $client_user_id, $consent_text, $consent_version) {
    $now = hammasir_now();
    $id = hammasir_next_id($store);
    $store['links'][] = array(
        'id' => $id,
        'provider_user_id' => (int) $provider_user_id,
        'client_user_id' => (int) $client_user_id,
        'status' => 'PENDING',
        'consent_text' => (string) $consent_text,
        'consent_version' => (string) $consent_version,
        'requested_at' => $now,
        'accepted_at' => null,
        'declined_at' => null,
        'revoked_at' => null,
        'revoked_by_user_id' => null,
        'created_at' => $now,
        'updated_at' => $now,
    );
    return $id;
}

function hammasir_link_patch_fields() {
    // ستون‌های مجاز تغییر لینک — whitelist ثابت (بدون ورودی کاربر در نام ستون)
    return array('status', 'accepted_at', 'declined_at', 'revoked_at', 'revoked_by_user_id', 'updated_at');
}

function hammasir_db_link_patch($link_id, $patch) {
    if (isset($patch['status']) && !hammasir_is_valid_link_status($patch['status'])) return false;
    $sets = array();
    $types = '';
    $values = array();
    foreach (hammasir_link_patch_fields() as $f) {
        if (!array_key_exists($f, $patch)) continue;
        $sets[] = $f . ' = ?';
        if ($f === 'revoked_by_user_id') {
            $types .= 'i';
            $values[] = ($patch[$f] === null) ? null : (int) $patch[$f];
        } else {
            $types .= 's';
            $values[] = (string) $patch[$f];
        }
    }
    if (count($sets) === 0) return false;
    if (!array_key_exists('updated_at', $patch)) {
        $sets[] = 'updated_at = ?';
        $types .= 's';
        $values[] = hammasir_now();
    }
    $types .= 'i';
    $values[] = (int) $link_id;
    return joma_exec('UPDATE joma_hammasir_links SET ' . implode(', ', $sets) . ' WHERE id = ?', $types, $values);
}

function hammasir_file_link_patch(&$store, $link_id, $patch) {
    if (isset($patch['status']) && !hammasir_is_valid_link_status($patch['status'])) return false;
    foreach ($store['links'] as $i => $l) {
        if ((int) $l['id'] !== (int) $link_id) continue;
        foreach (hammasir_link_patch_fields() as $f) {
            if (!array_key_exists($f, $patch)) continue;
            if ($f === 'revoked_by_user_id') {
                $store['links'][$i][$f] = ($patch[$f] === null) ? null : (int) $patch[$f];
            } else {
                $store['links'][$i][$f] = $patch[$f];
            }
        }
        if (!array_key_exists('updated_at', $patch)) {
            $store['links'][$i]['updated_at'] = hammasir_now();
        }
        return true;
    }
    return false;
}

/* ---------- permissions (D5/D18/D37) ---------- */

function hammasir_db_permission_set($link_id, $perm_key, $enabled, $changed_by_user_id) {
    if (!hammasir_is_valid_perm_key($perm_key)) return false;
    $now = hammasir_now();
    return joma_exec(
        'INSERT INTO joma_hammasir_permissions (link_id, perm_key, enabled, changed_by_user_id, changed_at) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), changed_by_user_id = VALUES(changed_by_user_id), changed_at = VALUES(changed_at)',
        'isiis',
        array((int) $link_id, $perm_key, $enabled ? 1 : 0, (int) $changed_by_user_id, $now)
    );
}

function hammasir_file_permission_set(&$store, $link_id, $perm_key, $enabled, $changed_by_user_id) {
    if (!hammasir_is_valid_perm_key($perm_key)) return false;
    $now = hammasir_now();
    foreach ($store['permissions'] as $i => $p) {
        if ((int) $p['link_id'] === (int) $link_id && $p['perm_key'] === $perm_key) {
            $store['permissions'][$i]['enabled'] = $enabled ? 1 : 0;
            $store['permissions'][$i]['changed_by_user_id'] = (int) $changed_by_user_id;
            $store['permissions'][$i]['changed_at'] = $now;
            return true;
        }
    }
    $store['permissions'][] = array(
        'id' => hammasir_next_id($store),
        'link_id' => (int) $link_id,
        'perm_key' => $perm_key,
        'enabled' => $enabled ? 1 : 0,
        'changed_by_user_id' => (int) $changed_by_user_id,
        'changed_at' => $now,
    );
    return true;
}

/* ---------- permission_history (D18 — append-only) ---------- */

function hammasir_db_permission_history_add($link_id, $perm_key, $previous_enabled, $enabled, $changed_by_user_id, $change_source) {
    if (!hammasir_is_valid_perm_key($perm_key)) return false;
    if (!hammasir_is_valid_change_source($change_source)) return false;
    return joma_exec(
        'INSERT INTO joma_hammasir_permission_history (link_id, perm_key, previous_enabled, enabled, changed_by_user_id, change_source, changed_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        'isiiiss',
        array((int) $link_id, $perm_key, (int) $previous_enabled, (int) $enabled, (int) $changed_by_user_id, $change_source, hammasir_now())
    );
}

function hammasir_file_permission_history_add(&$store, $link_id, $perm_key, $previous_enabled, $enabled, $changed_by_user_id, $change_source) {
    if (!hammasir_is_valid_perm_key($perm_key)) return false;
    if (!hammasir_is_valid_change_source($change_source)) return false;
    $store['permission_history'][] = array(
        'id' => hammasir_next_id($store),
        'link_id' => (int) $link_id,
        'perm_key' => $perm_key,
        'previous_enabled' => (int) $previous_enabled,
        'enabled' => (int) $enabled,
        'changed_by_user_id' => (int) $changed_by_user_id,
        'change_source' => $change_source,
        'changed_at' => hammasir_now(),
    );
    return true;
}

/* ---------- messages (D11/P3/P6) ---------- */

function hammasir_db_message_insert($link_id, $sender_user_id, $recipient_user_id, $jalali_date, $body) {
    // jalali_date فقط server-generated (hammasir_jalali_today) — هرگز از client (P7)
    return joma_exec(
        'INSERT INTO joma_hammasir_messages (link_id, sender_user_id, recipient_user_id, jalali_date, body, is_read, read_at, created_at) VALUES (?, ?, ?, ?, ?, 0, NULL, ?)',
        'iiisss',
        array((int) $link_id, (int) $sender_user_id, (int) $recipient_user_id, (string) $jalali_date, (string) $body, hammasir_now())
    );
}

function hammasir_file_message_insert(&$store, $link_id, $sender_user_id, $recipient_user_id, $jalali_date, $body) {
    $store['messages'][] = array(
        'id' => hammasir_next_id($store),
        'link_id' => (int) $link_id,
        'sender_user_id' => (int) $sender_user_id,
        'recipient_user_id' => (int) $recipient_user_id,
        'jalali_date' => (string) $jalali_date,
        'body' => (string) $body,
        'is_read' => 0,
        'read_at' => null,
        'created_at' => hammasir_now(),
    );
    return true;
}

function hammasir_db_messages_mark_read($recipient_user_id) {
    // فقط پیام‌های recipient جاری (D11)
    return joma_exec(
        'UPDATE joma_hammasir_messages SET is_read = 1, read_at = ? WHERE recipient_user_id = ? AND is_read = 0',
        'si',
        array(hammasir_now(), (int) $recipient_user_id)
    );
}

function hammasir_file_messages_mark_read(&$store, $recipient_user_id) {
    $now = hammasir_now();
    foreach ($store['messages'] as $i => $m) {
        if ((int) $m['recipient_user_id'] === (int) $recipient_user_id && (int) $m['is_read'] === 0) {
            $store['messages'][$i]['is_read'] = 1;
            $store['messages'][$i]['read_at'] = $now;
        }
    }
    return true;
}

/* ---------- system_events (D17/D21) ---------- */

function hammasir_db_event_add($link_id, $event_type, $actor_user_id, $recipient_user_id) {
    // متن رویداد از template ثابت UI تولید می‌شود؛ body آزاد ذخیره نمی‌شود (D17)
    if (!hammasir_is_valid_event_type($event_type)) return false;
    return joma_exec(
        'INSERT INTO joma_hammasir_system_events (link_id, event_type, actor_user_id, recipient_user_id, is_read, read_at, created_at) VALUES (?, ?, ?, ?, 0, NULL, ?)',
        'isiis',
        array((int) $link_id, $event_type, (int) $actor_user_id, (int) $recipient_user_id, hammasir_now())
    );
}

function hammasir_file_event_add(&$store, $link_id, $event_type, $actor_user_id, $recipient_user_id) {
    if (!hammasir_is_valid_event_type($event_type)) return false;
    $store['system_events'][] = array(
        'id' => hammasir_next_id($store),
        'link_id' => (int) $link_id,
        'event_type' => $event_type,
        'actor_user_id' => (int) $actor_user_id,
        'recipient_user_id' => (int) $recipient_user_id,
        'is_read' => 0,
        'read_at' => null,
        'created_at' => hammasir_now(),
    );
    return true;
}

function hammasir_db_events_mark_read($recipient_user_id) {
    // فقط رویدادهای recipient جاری (D17/D21)
    return joma_exec(
        'UPDATE joma_hammasir_system_events SET is_read = 1, read_at = ? WHERE recipient_user_id = ? AND is_read = 0',
        'si',
        array(hammasir_now(), (int) $recipient_user_id)
    );
}

function hammasir_file_events_mark_read(&$store, $recipient_user_id) {
    $now = hammasir_now();
    foreach ($store['system_events'] as $i => $ev) {
        if ((int) $ev['recipient_user_id'] === (int) $recipient_user_id && (int) $ev['is_read'] === 0) {
            $store['system_events'][$i]['is_read'] = 1;
            $store['system_events'][$i]['read_at'] = $now;
        }
    }
    return true;
}

/* ==================================================================
 * بخش ۸) Provider Registry (D30/D41) — Phase 1C
 * ------------------------------------------------------------------
 * - قفل مستقل عملیات Registry: joma_hammasir_provider_{user_id} (D41)
 *   در MySQL داخل تراکنش (D15) روی همان اتصال مشترک؛ در file داخل
 *   hammasir_store_mutate (قفل فایل سراسری storage مستقل — گزینه A).
 * - یکتایی user_id و وضعیت لینک‌های باز، داخل قفل/تراکنش re-check می‌شوند (D20/D22).
 * - همه‌ی نوشتن‌ها فقط روی joma_hammasir_providers / storage مستقل؛
 *   خواندن کاربران JOMA فقط read-only و بدون هیچ نوشتن (ارجاع منطقی — D25).
 * - کدهای نتیجه (نگاشت به پیام‌های مصوب در UI):
 *   ok | invalid_user | duplicate | invalid_title | open_link | lock_timeout | error
 * ================================================================== */

function hammasir_provider_lock_name($user_id) {
    // D41 — قفل نام‌دار مستقل Registry (R6: بسیار کوتاه‌تر از سقف طول نام قفل MySQL)
    return 'joma_hammasir_provider_' . (int) $user_id;
}

function hammasir_registry_with_lock($provider_user_id, $fn) {
    // D41 + D15: قفل نام‌دار مستقل Registry + تراکنش روی همان اتصال.
    // خروجی: '__LOCK_TIMEOUT__' = نرسیدن به قفل در مهلت؛ false = خطا؛
    // در غیر این صورت نتیجه‌ی callback (false → rollback کامل).
    // D-1 (PHP 8.1): کل مسیر — از جمله GET_LOCK و db() — داخل try مهار می‌شود؛
    // آزادسازی قفل در finally با مهار خودش؛ rollback در catch با مهار خودش.
    if (store_mode() !== 'mysql') return false;
    $mysqli = null;
    $name = hammasir_provider_lock_name($provider_user_id);
    $lock_acquired = false;
    $in_txn = false;
    try {
        $mysqli = db();
        if (!$mysqli) return false;
        $row = joma_query_one('SELECT GET_LOCK(?, ' . (int) HAMMASIR_LOCK_TIMEOUT . ')', 's', array($name));
        if (!$row || (int) current($row) !== 1) return '__LOCK_TIMEOUT__';
        $lock_acquired = true;
        if (!mysqli_begin_transaction($mysqli)) return false;
        $in_txn = true;
        $result = call_user_func($fn, $mysqli);
        if ($result === false) {
            mysqli_rollback($mysqli);
            $in_txn = false;
            return false;
        }
        if (!mysqli_commit($mysqli)) {
            mysqli_rollback($mysqli);
            $in_txn = false;
            return false;
        }
        $in_txn = false;
        return ($result === null) ? true : $result;
    } catch (Throwable $e) {
        if ($in_txn && $mysqli) {
            try { @mysqli_rollback($mysqli); } catch (Throwable $e2) { /* rollback ناموفق؛ اتصال بسته می‌شود */ }
        }
        return false;
    } finally {
        if ($lock_acquired) {
            try {
                joma_exec('SELECT RELEASE_LOCK(?)', 's', array($name));
            } catch (Throwable $e3) {
                // آزادسازی صریح ناموفق؛ ایمنی آخر: آزادسازی خودکار MySQL در پایان اتصال (D1)
            }
        }
    }
}

function hammasir_user_select_max() {
    // سقف نمایش <select> کاربران (حکم PO — بستن 1C، بخش C):
    // زنجیره‌ی config مستقل هم‌مسیر → پیش‌فرض یک‌نقطه‌ای (همان الگوی D9/D40)
    $cfg = hammasir_config();
    if (isset($cfg['user_select_max']) && is_int($cfg['user_select_max']) && $cfg['user_select_max'] > 0) {
        return $cfg['user_select_max'];
    }
    return 200;
}

function hammasir_registry_user_options() {
    // کاربران موجود JOMA — فقط id + نام نمایشی/نام کاربری (بدون ایمیل/داده‌ی اضافی)؛ read-only.
    // LIMIT (max+1): تشخیص ارزانِ «از حد گذشت» → در آن حالت UI به‌جای select ورودی عددی می‌دهد (C-2).
    $out = array();
    $max = hammasir_user_select_max();
    if (store_mode() === 'mysql') {
        $rows = joma_query('SELECT id, first_name, last_name, username FROM joma_users ORDER BY username ASC LIMIT ' . (int) ($max + 1), '', array());
        foreach ($rows as $r) {
            // قالب مصوب PO (بخش دو-۳): «نام خانوادگی، نام (نام‌کاربری)» — تفکیک هم‌نام‌ها
            $out[] = array(
                'id' => (int) $r['id'],
                'display' => hammasir_user_picker_label($r['first_name'], $r['last_name'], $r['username']),
            );
        }
        return $out;
    }
    $data = store_load();
    $users = isset($data['users']) ? $data['users'] : array();
    usort($users, function ($a, $b) { return strcmp($a['username'], $b['username']); });
    if (count($users) > $max + 1) {
        $users = array_slice($users, 0, $max + 1);
    }
    foreach ($users as $u) {
        $out[] = array(
            'id' => (int) $u['id'],
            'display' => hammasir_user_picker_label($u['first_name'], $u['last_name'], $u['username']),
        );
    }
    return $out;
}

function hammasir_user_picker_label($first_name, $last_name, $username) {
    // برچسب یکسان گزینه‌های «انتخاب کاربر» (حکم PO — بخش دو-۳؛ متن دقیق):
    // «نام خانوادگی، نام (نام‌کاربری)» — بدون ایمیل/موبایل؛ fallback به نام‌کاربری.
    $first_name = trim((string) $first_name);
    $last_name = trim((string) $last_name);
    $username = trim((string) $username);
    $name = trim($last_name . '، ' . $first_name);
    return ($name !== '' && $name !== '،') ? ($name . ' (' . $username . ')') : $username;
}

function hammasir_registry_display_map() {
    // نگاشت user_id → نام نمایشی (فقط برای فهرست ادمین) — read-only
    $map = array();
    foreach (hammasir_registry_user_options() as $o) {
        $map[$o['id']] = $o['display'];
    }
    return $map;
}

function hammasir_registry_validate_user($user_id) {
    // اعتبارسنجی read-only وجود کاربر در منبع فعلی JOMA (ارجاع منطقی — D25/D30)
    $user_id = (int) $user_id;
    if ($user_id < 1) return false;
    if (store_mode() === 'mysql') {
        $row = joma_query_one('SELECT id FROM joma_users WHERE id=?', 'i', array($user_id));
        return $row ? true : false;
    }
    $data = store_load();
    foreach ($data['users'] as $u) {
        if ((int) $u['id'] === $user_id) return true;
    }
    return false;
}

function hammasir_registry_validate_title($title) {
    // عنوان همراه — اعتبارسنجی نهایی سمت سرور (نه فقط maxlength در UI):
    // trim + غیرخالی + حداکثر ۱۲۸ کاراکتر؛ طول فقط از طریق hammasir_mb_strlen (fail-closed — AC1.7)
    $title = trim((string) $title);
    if ($title === '') {
        return array('ok' => false, 'error' => 'invalid_title');
    }
    $len = hammasir_mb_strlen($title);
    if ($len === false) {
        return array('ok' => false, 'error' => 'env');
    }
    if ($len > 128) {
        return array('ok' => false, 'error' => 'invalid_title');
    }
    return array('ok' => true, 'title' => $title);
}

function hammasir_registry_open_link_exists($provider_user_id) {
    // D20: آیا لینک PENDING/ACTIVE برای این provider هست؟ (re-check داخل قفل/تراکنش)
    if (store_mode() === 'mysql') {
        $row = joma_query_one(
            "SELECT id FROM joma_hammasir_links WHERE provider_user_id=? AND status IN ('PENDING','ACTIVE') LIMIT 1",
            'i',
            array((int) $provider_user_id)
        );
        return $row ? true : false;
    }
    $store = hammasir_store_load();
    if ($store === null) return true; // شکست خواندن → محافظه‌کارانه مانع INACTIVE (fail-closed)
    foreach ($store['links'] as $l) {
        if ((int) $l['provider_user_id'] === (int) $provider_user_id
            && ($l['status'] === 'PENDING' || $l['status'] === 'ACTIVE')) {
            return true;
        }
    }
    return false;
}

function hammasir_registry_list() {
    // فهرست همه‌ی همراهان + نام نمایشی کاربر (join منطقی در PHP؛ read-only از JOMA)
    $display = hammasir_registry_display_map();
    $providers = hammasir_provider_list(false);
    if ($providers === null) return null;
    $out = array();
    foreach ($providers as $p) {
        $uid = (int) $p['user_id'];
        $out[] = array(
            'id' => (int) $p['id'],
            'user_id' => $uid,
            'title' => (string) $p['title'],
            'status' => (string) $p['status'],
            'display_name' => isset($display[$uid]) ? $display[$uid] : 'کاربر', // برچسب عمومی مجاز (D33)
        );
    }
    return $out;
}

/* ---------- نوشتن‌های Registry (فقط داخل قفل D41) ---------- */

function hammasir_db_provider_insert($user_id, $title) {
    // ثبت جدید — وضعیت اولیه ACTIVE (D30: پس از ثبت، همراه در فهرست انتخاب پدیدار می‌شود)
    $now = hammasir_now();
    return joma_exec(
        'INSERT INTO joma_hammasir_providers (user_id, title, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
        'issss',
        array((int) $user_id, (string) $title, 'ACTIVE', $now, $now)
    );
}

function hammasir_db_provider_set_title($user_id, $title) {
    return joma_exec(
        'UPDATE joma_hammasir_providers SET title = ?, updated_at = ? WHERE user_id = ?',
        'ssi',
        array((string) $title, hammasir_now(), (int) $user_id)
    );
}

function hammasir_db_provider_set_status($user_id, $status) {
    return joma_exec(
        'UPDATE joma_hammasir_providers SET status = ?, updated_at = ? WHERE user_id = ?',
        'ssi',
        array($status, hammasir_now(), (int) $user_id)
    );
}

function hammasir_file_provider_set_title(&$store, $user_id, $title) {
    foreach ($store['providers'] as $i => $p) {
        if ((int) $p['user_id'] === (int) $user_id) {
            $store['providers'][$i]['title'] = (string) $title;
            $store['providers'][$i]['updated_at'] = hammasir_now();
            return true;
        }
    }
    return false;
}

function hammasir_file_provider_set_status(&$store, $user_id, $status) {
    foreach ($store['providers'] as $i => $p) {
        if ((int) $p['user_id'] === (int) $user_id) {
            $store['providers'][$i]['status'] = $status;
            $store['providers'][$i]['updated_at'] = hammasir_now();
            return true;
        }
    }
    return false;
}

/* ---------- عملیات‌های Registry (گیت ADMIN_ACCESS در UI؛ قفل D41 اینجا) ---------- */

function hammasir_provider_register($user_id, $title) {
    // ثبت همراه: کاربر موجود و معتبر + عنوان معتبر + یکتایی user_id (D30)
    $user_id = (int) $user_id;
    // D-1 (PHP 8.1): validate_user قبل از قفل است — خطای mysqli آن هم مهار و به کد داخلی تبدیل می‌شود
    try {
        $hammasir_user_valid = hammasir_registry_validate_user($user_id);
    } catch (Throwable $e) {
        return 'error';
    }
    if (!$hammasir_user_valid) return 'invalid_user';
    $t = hammasir_registry_validate_title($title);
    if (!$t['ok']) return ($t['error'] === 'env') ? 'env' : 'invalid_title';
    if (store_mode() === 'mysql') {
        $res = hammasir_registry_with_lock($user_id, function ($mysqli) use ($user_id, $t) {
            // re-check یکتایی داخل قفل و تراکنش (D41/D15)
            if (hammasir_provider_by_user_id($user_id)) return 'duplicate';
            if (!hammasir_db_provider_insert($user_id, $t['title'])) return false;
            return 'ok';
        });
        if ($res === '__LOCK_TIMEOUT__') return 'lock_timeout';
        if ($res === 'ok' || $res === 'duplicate') return $res;
        return 'error';
    }
    $res = hammasir_store_mutate(function (&$store) use ($user_id, $t) {
        foreach ($store['providers'] as $p) {
            if ((int) $p['user_id'] === $user_id) return 'duplicate';
        }
        return hammasir_file_provider_upsert($store, $user_id, $t['title'], 'ACTIVE') ? 'ok' : false;
    });
    if ($res === 'ok' || $res === 'duplicate') return $res;
    // در file mode تفکیک timeout قفل از خطای IO در این سطح ممکن نیست → پیام عمومی مصوب
    return 'error';
}

function hammasir_provider_set_title($user_id, $title) {
    $user_id = (int) $user_id;
    $t = hammasir_registry_validate_title($title);
    if (!$t['ok']) return ($t['error'] === 'env') ? 'env' : 'invalid_title';
    if (store_mode() === 'mysql') {
        $res = hammasir_registry_with_lock($user_id, function ($mysqli) use ($user_id, $t) {
            if (!hammasir_provider_by_user_id($user_id)) return false;
            if (!hammasir_db_provider_set_title($user_id, $t['title'])) return false;
            return 'ok';
        });
        if ($res === '__LOCK_TIMEOUT__') return 'lock_timeout';
        return ($res === 'ok') ? 'ok' : 'error';
    }
    $res = hammasir_store_mutate(function (&$store) use ($user_id, $t) {
        return hammasir_file_provider_set_title($store, $user_id, $t['title']) ? 'ok' : false;
    });
    return ($res === 'ok') ? 'ok' : 'error';
}

function hammasir_provider_set_status($user_id, $status) {
    // ACTIVE/INACTIVE — قید D20: INACTIVE فقط بدون لینک باز (re-check داخل قفل/تراکنش — D22/D41)
    $user_id = (int) $user_id;
    if (!hammasir_is_valid_provider_status($status)) return 'error';
    if (store_mode() === 'mysql') {
        $res = hammasir_registry_with_lock($user_id, function ($mysqli) use ($user_id, $status) {
            if (!hammasir_provider_by_user_id($user_id)) return false;
            if ($status === 'INACTIVE' && hammasir_registry_open_link_exists($user_id)) return 'open_link';
            if (!hammasir_db_provider_set_status($user_id, $status)) return false;
            return 'ok';
        });
        if ($res === '__LOCK_TIMEOUT__') return 'lock_timeout';
        if ($res === 'ok' || $res === 'open_link') return $res;
        return 'error';
    }
    $res = hammasir_store_mutate(function (&$store) use ($user_id, $status) {
        $exists = false;
        foreach ($store['providers'] as $p) {
            if ((int) $p['user_id'] === $user_id) { $exists = true; break; }
        }
        if (!$exists) return false;
        if ($status === 'INACTIVE') {
            foreach ($store['links'] as $l) {
                if ((int) $l['provider_user_id'] === $user_id
                    && ($l['status'] === 'PENDING' || $l['status'] === 'ACTIVE')) {
                    return 'open_link';
                }
            }
        }
        return hammasir_file_provider_set_status($store, $user_id, $status) ? 'ok' : false;
    });
    if ($res === 'ok' || $res === 'open_link') return $res;
    return 'error';
}


/* ==================================================================
 * بخش ۹) عملیات مرکب فازهای ۲ تا ۵ (لینک/رضایت/پاسخ/توگل/پیام/Badge)
 * ------------------------------------------------------------------
 * قواعد (D1/D14/D15/D20/D22/D34/D37 + AC2.x..AC5.x):
 * - این توابع تنها نقطه‌ی فراخوانی از UI هستند؛ primitiveهای بخش ۶/۷
 *   هرگز مستقیم از صفحه صدا زده نمی‌شوند.
 * - MySQL: قفل canonical مراجع (hammasir_with_lock) یا قفل Registry
 *   همراه (hammasir_registry_with_lock) + تراکنش؛ re-validate داخل قفل (AC4.10).
 * - file: hammasir_store_mutate (اتمیک روی storage مستقل).
 * - کدهای نتیجه به پیام‌های UI نگاشت می‌شوند؛ هیچ exception به صفحه
 *   راه پیدا نمی‌کند (D-1) و هیچ rethrow وجود ندارد.
 * ================================================================== */

/* ---------- متن‌ها/برچسب‌های ثابت UI (یک نقطه) ---------- */

function hammasir_consent_text() {
    // متن رضایت مصوب PO — عیناً (D11)
    return 'با ارسال این درخواست، همراه انتخاب‌شده می‌تواند مواردی را که در زیر اجازه می‌دهید مشاهده کند. شما هر زمان بخواهید می‌توانید دسترسی را کاهش دهید یا ارتباط را قطع کنید.';
}

function hammasir_consent_version() {
    return 'v1';
}

function hammasir_dt_to_jalali($dt) {
    // تبدیل 'Y-m-d H:i:s' میلادی به 'Y-m-d' شمسی برای نمایش (D35 — توابع JOMA؛ fail → همان تاریخ میلادی)
    $d = substr((string) $dt, 0, 10);
    $p = explode('-', $d);
    if (count($p) !== 3) return '';
    if (!function_exists('gregorian_to_jalali') || !function_exists('jalali_pad')) return $d;
    $j = gregorian_to_jalali((int) $p[0], (int) $p[1], (int) $p[2]);
    return $j[0] . '-' . jalali_pad($j[1]) . '-' . jalali_pad($j[2]);
}

function hammasir_perm_view_labels() {
    // برچسب‌های نمایشی مجوزها (template ثابت UI — بدون ورودی کاربر)
    return array(
        'VIEW_SUMMARY' => 'خلاصه‌ی داشبورد',
        'VIEW_PROGRESS' => 'روند پیشرفت',
        'VIEW_ACTIVITY_DETAILS' => 'جزئیات فعالیت‌ها',
        'VIEW_MOOD' => 'حال و احوال روزانه',
        'CLIENT_CAN_MESSAGE_COMPANION' => 'پیام‌رسانی',
        'COMPANION_CAN_MESSAGE_CLIENT' => 'پیام‌رسانی',
    );
}

function hammasir_event_label($event_type) {
    // متن ثابت رویداد سیستمی از template امن — بدون body آزاد (D17/D21)
    $map = array(
        'LINK_REQUESTED' => 'کاربری درخواست کرده شما همراه او باشید.',
        'LINK_ACCEPTED' => 'درخواست همراهی شما پذیرفته شد.',
        'LINK_DECLINED' => 'درخواست همراهی شما رد شد.',
        'LINK_REVOKED' => 'ارتباط هم‌مسیر قطع شد.',
        'LINK_CANCELLED' => 'درخواست همراهی لغو شد.',
    );
    return isset($map[$event_type]) ? $map[$event_type] : 'رویداد هم‌مسیر.';
}

/* ---------- خواندهای کمکی (read-only) ---------- */

function hammasir_user_display($user_id) {
    // نام نمایشی کاربر JOMA فقط برای شناسایی طرف مقابل — read-only (D25)
    $user_id = (int) $user_id;
    if (store_mode() === 'mysql') {
        $u = joma_query_one('SELECT first_name, last_name, username FROM joma_users WHERE id=?', 'i', array($user_id));
        if (!$u) return '#' . $user_id;
        $name = trim($u['first_name'] . ' ' . $u['last_name']);
        return ($name !== '') ? $name : $u['username'];
    }
    $data = store_load();
    if (!is_array($data)) return '#' . $user_id;
    foreach ($data['users'] as $u) {
        if ((int) $u['id'] === $user_id) {
            $name = trim($u['first_name'] . ' ' . $u['last_name']);
            return ($name !== '') ? $name : $u['username'];
        }
    }
    return '#' . $user_id;
}

function hammasir_perm_map($link_id) {
    // نگاشت نرمال‌شده‌ی perm_key => 1/0 برای یک لینک.
    // ریشه‌ی باگ پیام‌رسانی (گزارش PO): hammasir_permissions_for_link از ابتدا
    // «نگاشت perm_key => bool» برمی‌گرداند (نه ردیف‌های خام)؛ خواندن قبلی به‌صورت
    // $r['perm_key'] روی مقدار bool انجام می‌شد و همه‌ی کلیدها گم می‌شدند →
    // hammasir_messaging_enabled همیشه false → پیام خاموشی پیام همیشه نمایش می‌یافت.
    $raw = hammasir_permissions_for_link($link_id);
    if (!is_array($raw)) return null;
    $map = array();
    foreach ($raw as $k => $v) {
        $map[(string) $k] = ($v === true || (int) $v === 1) ? 1 : 0;
    }
    return $map;
}

function hammasir_link_status_labels() {
    // برچسب وضعیت لینک برای UI مراجع (D4)
    return array(
        'PENDING' => 'در انتظار پذیرش همراه',
        'ACTIVE' => 'فعال',
        'DECLINED' => 'رد شده',
        'REVOKED' => 'قطع شده',
    );
}

function hammasir_messaging_enabled($link_id) {
    // توگل واحد D37: هر دو کلید هم‌مقدارند؛ خاموشی هر یک = خاموشی هر دو
    $map = hammasir_perm_map($link_id);
    if ($map === null) return null;
    $a = isset($map['CLIENT_CAN_MESSAGE_COMPANION']) ? (int) $map['CLIENT_CAN_MESSAGE_COMPANION'] : 0;
    $b = isset($map['COMPANION_CAN_MESSAGE_CLIENT']) ? (int) $map['COMPANION_CAN_MESSAGE_CLIENT'] : 0;
    return ($a === 1 && $b === 1) ? true : false;
}

/* ---------- فاز ۲: ثبت درخواست همراهی + رضایت (AC2.x) ---------- */

function hammasir_link_request($client_user_id, $provider_user_id, $view_perms) {
    // ارسال درخواست توسط مراجع: همه‌ی قیدها داخل قفل canonical مراجع re-check می‌شوند.
    // کدها: ok | self_link | invalid_provider | open_link_exists | request_cooldown | lock_timeout | error
    $client_user_id = (int) $client_user_id;
    $provider_user_id = (int) $provider_user_id;
    if ($provider_user_id === $client_user_id) return 'self_link'; // D20/AC2.4
    // مجوزهای انتخابی مراجع (whitelist سخت)؛ VIEW_SUMMARY پایه = 1؛ کلیدهای پیام = 0 (D37)
    $perms = array(
        'VIEW_SUMMARY' => 1,
        'VIEW_PROGRESS' => (isset($view_perms['VIEW_PROGRESS']) && $view_perms['VIEW_PROGRESS']) ? 1 : 0,
        'VIEW_ACTIVITY_DETAILS' => (isset($view_perms['VIEW_ACTIVITY_DETAILS']) && $view_perms['VIEW_ACTIVITY_DETAILS']) ? 1 : 0,
        'VIEW_MOOD' => (isset($view_perms['VIEW_MOOD']) && $view_perms['VIEW_MOOD']) ? 1 : 0,
        'CLIENT_CAN_MESSAGE_COMPANION' => 0,
        'COMPANION_CAN_MESSAGE_CLIENT' => 0,
    );
    $consent = hammasir_consent_text();
    $version = hammasir_consent_version();
    if (store_mode() === 'mysql') {
        $res = hammasir_with_lock($client_user_id, function () use ($client_user_id, $provider_user_id, $perms, $consent, $version) {
            $prov = hammasir_provider_by_user_id($provider_user_id); // re-read داخل قفل (D22)
            if (!$prov || $prov['status'] !== 'ACTIVE') return 'invalid_provider'; // AC2.1
            if (hammasir_link_open_by_client($client_user_id)) return 'open_link_exists'; // D6/AC2.5
            $block_until = hammasir_re_request_block_until($client_user_id, $provider_user_id); // D34/AC2.8
            if ($block_until !== null && time() < $block_until) return 'request_cooldown';
            $link_id = hammasir_db_link_insert($provider_user_id, $client_user_id, $consent, $version);
            if (!$link_id) return false;
            foreach ($perms as $k => $v) {
                if (!hammasir_db_permission_set($link_id, $k, $v, $client_user_id)) return false;
                if (!hammasir_db_permission_history_add($link_id, $k, 0, $v, $client_user_id, 'INITIAL_CONSENT')) return false; // AC2.7
            }
            if (!hammasir_db_event_add($link_id, 'LINK_REQUESTED', $client_user_id, $provider_user_id)) return false;
            return 'ok';
        });
        if ($res === '__LOCK_TIMEOUT__') return 'lock_timeout';
        return ($res === 'ok') ? 'ok' : (is_string($res) ? $res : 'error');
    }
    $res = hammasir_store_mutate(function (&$store) use ($client_user_id, $provider_user_id, $perms, $consent, $version) {
        $prov = null;
        foreach ($store['providers'] as $p) {
            if ((int) $p['user_id'] === $provider_user_id) { $prov = $p; break; }
        }
        if (!$prov || $prov['status'] !== 'ACTIVE') return 'invalid_provider';
        foreach ($store['links'] as $l) {
            if ((int) $l['client_user_id'] === $client_user_id
                && ($l['status'] === 'PENDING' || $l['status'] === 'ACTIVE')) {
                return 'open_link_exists';
            }
        }
        $block_until = hammasir_re_request_block_until($client_user_id, $provider_user_id);
        if ($block_until !== null && time() < $block_until) return 'request_cooldown';
        $link_id = hammasir_file_link_insert($store, $provider_user_id, $client_user_id, $consent, $version);
        foreach ($perms as $k => $v) {
            hammasir_file_permission_set($store, $link_id, $k, $v, $client_user_id);
            hammasir_file_permission_history_add($store, $link_id, $k, 0, $v, $client_user_id, 'INITIAL_CONSENT');
        }
        hammasir_file_event_add($store, $link_id, 'LINK_REQUESTED', $client_user_id, $provider_user_id);
        return 'ok';
    });
    return ($res === 'ok') ? 'ok' : (is_string($res) ? $res : 'error');
}

/* ---------- فاز ۳: پاسخ همراه (قبول/رد) و چرخه‌ی حیات لینک (AC3.x/D20) ---------- */

function hammasir_link_respond($provider_user_id, $link_id, $decision) {
    // پاسخ همراه به درخواست PENDING: ACTIVE یا DECLINED (D20).
    // کدها: ok | not_found | not_pending | inactive_provider | lock_timeout | error
    $provider_user_id = (int) $provider_user_id;
    $link_id = (int) $link_id;
    if ($decision !== 'ACTIVE' && $decision !== 'DECLINED') return 'error';
    if (store_mode() === 'mysql') {
        // قفل Registry همراه (D41/D22): هم‌زمانی با تغییر وضعیت همراه مهار می‌شود
        $res = hammasir_registry_with_lock($provider_user_id, function () use ($provider_user_id, $link_id, $decision) {
            $link = hammasir_link_get($link_id);
            if (!$link || (int) $link['provider_user_id'] !== $provider_user_id) return 'not_found'; // IDOR gate
            if ($link['status'] !== 'PENDING') return 'not_pending'; // فقط PENDING → ACTIVE/DECLINED (D20)
            $prov = hammasir_provider_by_user_id($provider_user_id);
            if (!$prov || $prov['status'] !== 'ACTIVE') return 'inactive_provider'; // AC3.5
            $now = hammasir_now();
            $patch = array('status' => $decision);
            if ($decision === 'ACTIVE') {
                $patch['accepted_at'] = $now; // AC3.1: تاریخ پذیرش ثبت می‌شود
            } else {
                $patch['declined_at'] = $now;
            }
            if (!hammasir_db_link_patch($link_id, $patch)) return false;
            $event = ($decision === 'ACTIVE') ? 'LINK_ACCEPTED' : 'LINK_DECLINED';
            if (!hammasir_db_event_add($link_id, $event, $provider_user_id, (int) $link['client_user_id'])) return false;
            return 'ok';
        });
        if ($res === '__LOCK_TIMEOUT__') return 'lock_timeout';
        return ($res === 'ok') ? 'ok' : (is_string($res) ? $res : 'error');
    }
    $res = hammasir_store_mutate(function (&$store) use ($provider_user_id, $link_id, $decision) {
        $link = null;
        foreach ($store['links'] as $l) {
            if ((int) $l['id'] === $link_id) { $link = $l; break; }
        }
        if (!$link || (int) $link['provider_user_id'] !== $provider_user_id) return 'not_found';
        if ($link['status'] !== 'PENDING') return 'not_pending';
        $prov = null;
        foreach ($store['providers'] as $p) {
            if ((int) $p['user_id'] === $provider_user_id) { $prov = $p; break; }
        }
        if (!$prov || $prov['status'] !== 'ACTIVE') return 'inactive_provider';
        $now = hammasir_now();
        $patch = array('status' => $decision);
        if ($decision === 'ACTIVE') {
            $patch['accepted_at'] = $now;
        } else {
            $patch['declined_at'] = $now;
        }
        if (!hammasir_file_link_patch($store, $link_id, $patch)) return false;
        $event = ($decision === 'ACTIVE') ? 'LINK_ACCEPTED' : 'LINK_DECLINED';
        hammasir_file_event_add($store, $link_id, $event, $provider_user_id, (int) $link['client_user_id']);
        return 'ok';
    });
    return ($res === 'ok') ? 'ok' : (is_string($res) ? $res : 'error');
}

function hammasir_link_revoke($actor_user_id, $link_id) {
    // چرخه‌ی حیات (D20): PENDING→REVOKED فقط توسط مراجع (لغو درخواست)؛
    // ACTIVE→REVOKED توسط هر دو طرف (قطع ارتباط). رویداد برای طرف مقابل (D17).
    // کدها: ok | not_found | invalid_state | lock_timeout | error
    $actor_user_id = (int) $actor_user_id;
    $link_id = (int) $link_id;
    $pre = hammasir_link_get($link_id); // فقط برای یافتن قفل canonical مراجع
    if (!$pre) return 'not_found';
    $client_user_id = (int) $pre['client_user_id'];
    if (store_mode() === 'mysql') {
        $res = hammasir_with_lock($client_user_id, function () use ($actor_user_id, $link_id, $client_user_id) {
            $link = hammasir_link_get($link_id); // re-read داخل قفل (AC4.10)
            if (!$link) return 'not_found';
            $is_client = ((int) $link['client_user_id'] === $actor_user_id);
            $is_provider = ((int) $link['provider_user_id'] === $actor_user_id);
            if (!$is_client && !$is_provider) return 'not_found'; // IDOR gate
            if ($link['status'] === 'PENDING') {
                if (!$is_client) return 'invalid_state'; // لغو PENDING فقط توسط مراجع (D20)
                $patch = array('status' => 'REVOKED', 'revoked_at' => hammasir_now(), 'revoked_by_user_id' => $actor_user_id);
                if (!hammasir_db_link_patch($link_id, $patch)) return false;
                if (!hammasir_db_event_add($link_id, 'LINK_CANCELLED', $actor_user_id, (int) $link['provider_user_id'])) return false;
                return 'ok';
            }
            if ($link['status'] === 'ACTIVE') {
                $other = $is_client ? (int) $link['provider_user_id'] : $client_user_id;
                $patch = array('status' => 'REVOKED', 'revoked_at' => hammasir_now(), 'revoked_by_user_id' => $actor_user_id);
                if (!hammasir_db_link_patch($link_id, $patch)) return false;
                if (!hammasir_db_event_add($link_id, 'LINK_REVOKED', $actor_user_id, $other)) return false;
                return 'ok';
            }
            return 'invalid_state'; // DECLINED/REVOKED هیچ transition ندارند (D20)
        });
        if ($res === '__LOCK_TIMEOUT__') return 'lock_timeout';
        return ($res === 'ok') ? 'ok' : (is_string($res) ? $res : 'error');
    }
    $res = hammasir_store_mutate(function (&$store) use ($actor_user_id, $link_id, $client_user_id) {
        $link = null;
        foreach ($store['links'] as $l) {
            if ((int) $l['id'] === $link_id) { $link = $l; break; }
        }
        if (!$link) return 'not_found';
        $is_client = ((int) $link['client_user_id'] === $actor_user_id);
        $is_provider = ((int) $link['provider_user_id'] === $actor_user_id);
        if (!$is_client && !$is_provider) return 'not_found';
        if ($link['status'] === 'PENDING') {
            if (!$is_client) return 'invalid_state';
            $patch = array('status' => 'REVOKED', 'revoked_at' => hammasir_now(), 'revoked_by_user_id' => $actor_user_id);
            if (!hammasir_file_link_patch($store, $link_id, $patch)) return false;
            hammasir_file_event_add($store, $link_id, 'LINK_CANCELLED', $actor_user_id, (int) $link['provider_user_id']);
            return 'ok';
        }
        if ($link['status'] === 'ACTIVE') {
            $other = $is_client ? (int) $link['provider_user_id'] : $client_user_id;
            $patch = array('status' => 'REVOKED', 'revoked_at' => hammasir_now(), 'revoked_by_user_id' => $actor_user_id);
            if (!hammasir_file_link_patch($store, $link_id, $patch)) return false;
            hammasir_file_event_add($store, $link_id, 'LINK_REVOKED', $actor_user_id, $other);
            return 'ok';
        }
        return 'invalid_state';
    });
    return ($res === 'ok') ? 'ok' : (is_string($res) ? $res : 'error');
}

/* ---------- توگل واحد پیام‌رسانی (D37/AC4.11) و مجوزهای مشاهده (D19) ---------- */

function hammasir_messaging_set($actor_user_id, $link_id, $enabled) {
    // توگل واحد: هر دو کلید پیام هم‌زمان و هم‌مقدار تغییر می‌کنند؛
    // فقط لینک ACTIVE؛ هر دو طرف مجازند؛ history هر دو کلید در همان عمل (D18/D37).
    // کدها: ok | not_found | not_active | lock_timeout | error
    $actor_user_id = (int) $actor_user_id;
    $link_id = (int) $link_id;
    $enabled = $enabled ? 1 : 0;
    $pre = hammasir_link_get($link_id);
    if (!$pre) return 'not_found';
    $client_user_id = (int) $pre['client_user_id'];
    if (store_mode() === 'mysql') {
        $res = hammasir_with_lock($client_user_id, function () use ($actor_user_id, $link_id, $enabled, $client_user_id) {
            $link = hammasir_link_get($link_id); // re-read داخل قفل (AC4.10)
            if (!$link) return 'not_found';
            $is_client = ((int) $link['client_user_id'] === $actor_user_id);
            $is_provider = ((int) $link['provider_user_id'] === $actor_user_id);
            if (!$is_client && !$is_provider) return 'not_found';
            if ($link['status'] !== 'ACTIVE') return 'not_active'; // فقط ACTIVE
            $source = $is_client ? 'CLIENT_UPDATE' : 'PROVIDER_MESSAGE_SETTING';
            $map = hammasir_perm_map($link_id);
            if ($map === null) return false;
            $prev_c = isset($map['CLIENT_CAN_MESSAGE_COMPANION']) ? (int) $map['CLIENT_CAN_MESSAGE_COMPANION'] : 0;
            $prev_p = isset($map['COMPANION_CAN_MESSAGE_CLIENT']) ? (int) $map['COMPANION_CAN_MESSAGE_CLIENT'] : 0;
            if (!hammasir_db_permission_set($link_id, 'CLIENT_CAN_MESSAGE_COMPANION', $enabled, $actor_user_id)) return false;
            if (!hammasir_db_permission_set($link_id, 'COMPANION_CAN_MESSAGE_CLIENT', $enabled, $actor_user_id)) return false;
            if (!hammasir_db_permission_history_add($link_id, 'CLIENT_CAN_MESSAGE_COMPANION', $prev_c, $enabled, $actor_user_id, $source)) return false;
            if (!hammasir_db_permission_history_add($link_id, 'COMPANION_CAN_MESSAGE_CLIENT', $prev_p, $enabled, $actor_user_id, $source)) return false;
            return 'ok';
        });
        if ($res === '__LOCK_TIMEOUT__') return 'lock_timeout';
        return ($res === 'ok') ? 'ok' : (is_string($res) ? $res : 'error');
    }
    $res = hammasir_store_mutate(function (&$store) use ($actor_user_id, $link_id, $enabled) {
        $link = null;
        foreach ($store['links'] as $l) {
            if ((int) $l['id'] === $link_id) { $link = $l; break; }
        }
        if (!$link) return 'not_found';
        $is_client = ((int) $link['client_user_id'] === $actor_user_id);
        $is_provider = ((int) $link['provider_user_id'] === $actor_user_id);
        if (!$is_client && !$is_provider) return 'not_found';
        if ($link['status'] !== 'ACTIVE') return 'not_active';
        $source = $is_client ? 'CLIENT_UPDATE' : 'PROVIDER_MESSAGE_SETTING';
        $prev_c = 0;
        $prev_p = 0;
        foreach ($store['permissions'] as $p) {
            if ((int) $p['link_id'] !== $link_id) continue;
            if ($p['perm_key'] === 'CLIENT_CAN_MESSAGE_COMPANION') $prev_c = (int) $p['enabled'];
            if ($p['perm_key'] === 'COMPANION_CAN_MESSAGE_CLIENT') $prev_p = (int) $p['enabled'];
        }
        hammasir_file_permission_set($store, $link_id, 'CLIENT_CAN_MESSAGE_COMPANION', $enabled, $actor_user_id);
        hammasir_file_permission_set($store, $link_id, 'COMPANION_CAN_MESSAGE_CLIENT', $enabled, $actor_user_id);
        hammasir_file_permission_history_add($store, $link_id, 'CLIENT_CAN_MESSAGE_COMPANION', $prev_c, $enabled, $actor_user_id, $source);
        hammasir_file_permission_history_add($store, $link_id, 'COMPANION_CAN_MESSAGE_CLIENT', $prev_p, $enabled, $actor_user_id, $source);
        return 'ok';
    });
    return ($res === 'ok') ? 'ok' : (is_string($res) ? $res : 'error');
}

function hammasir_perms_update_view($client_user_id, $link_id, $view_perms) {
    // تغییر مجوزهای مشاهده فقط توسط مراجع (D19)؛ VIEW_SUMMARY پایه و همیشه 1؛
    // فقط لینک باز (PENDING/ACTIVE)؛ history فقط برای تغییر واقعی (D18/AC2.7).
    // کدها: ok | not_found | not_open | lock_timeout | error
    $client_user_id = (int) $client_user_id;
    $link_id = (int) $link_id;
    $new = array(
        'VIEW_PROGRESS' => (isset($view_perms['VIEW_PROGRESS']) && $view_perms['VIEW_PROGRESS']) ? 1 : 0,
        'VIEW_ACTIVITY_DETAILS' => (isset($view_perms['VIEW_ACTIVITY_DETAILS']) && $view_perms['VIEW_ACTIVITY_DETAILS']) ? 1 : 0,
        'VIEW_MOOD' => (isset($view_perms['VIEW_MOOD']) && $view_perms['VIEW_MOOD']) ? 1 : 0,
    );
    if (store_mode() === 'mysql') {
        $res = hammasir_with_lock($client_user_id, function () use ($client_user_id, $link_id, $new) {
            $link = hammasir_link_get($link_id);
            if (!$link || (int) $link['client_user_id'] !== $client_user_id) return 'not_found'; // IDOR gate
            if ($link['status'] !== 'PENDING' && $link['status'] !== 'ACTIVE') return 'not_open';
            $map = hammasir_perm_map($link_id);
            if ($map === null) return false;
            foreach ($new as $k => $v) {
                $prev = isset($map[$k]) ? (int) $map[$k] : 0;
                if ($prev === $v) continue; // بدون تغییر واقعی → بدون history
                if (!hammasir_db_permission_set($link_id, $k, $v, $client_user_id)) return false;
                if (!hammasir_db_permission_history_add($link_id, $k, $prev, $v, $client_user_id, 'CLIENT_UPDATE')) return false;
            }
            return 'ok';
        });
        if ($res === '__LOCK_TIMEOUT__') return 'lock_timeout';
        return ($res === 'ok') ? 'ok' : (is_string($res) ? $res : 'error');
    }
    $res = hammasir_store_mutate(function (&$store) use ($client_user_id, $link_id, $new) {
        $link = null;
        foreach ($store['links'] as $l) {
            if ((int) $l['id'] === $link_id) { $link = $l; break; }
        }
        if (!$link || (int) $link['client_user_id'] !== $client_user_id) return 'not_found';
        if ($link['status'] !== 'PENDING' && $link['status'] !== 'ACTIVE') return 'not_open';
        foreach ($new as $k => $v) {
            $prev = 0;
            foreach ($store['permissions'] as $p) {
                if ((int) $p['link_id'] === $link_id && $p['perm_key'] === $k) { $prev = (int) $p['enabled']; break; }
            }
            if ($prev === $v) continue;
            hammasir_file_permission_set($store, $link_id, $k, $v, $client_user_id);
            hammasir_file_permission_history_add($store, $link_id, $k, $prev, $v, $client_user_id, 'CLIENT_UPDATE');
        }
        return 'ok';
    });
    return ($res === 'ok') ? 'ok' : (is_string($res) ? $res : 'error');
}

/* ---------- فاز ۴: ارسال پیام (D0/D1/D8/D9/D11/AC4.x) ---------- */

function hammasir_message_send($sender_user_id, $link_id, $body) {
    // ارسال پیام متنی — همه‌ی کنترل‌ها سمت Backend و داخل قفل canonical مراجع:
    //   متن معتبر (۲۰۰۰ کاراکتر — hammasir_mb_strlen) → لینک ACTIVE → طرف لینک →
    //   توگل واحد روشن (D37) → سقف روزانه (مراجع ۳ / همراه ۲۰ — D9/D8) →
    //   کول‌داون همان فرستنده (P3) → درج.
    // کدها: ok | invalid_body | env | not_found | not_active | messaging_off |
    //       client_limit | companion_limit | cooldown | lock_timeout | error
    $sender_user_id = (int) $sender_user_id;
    $link_id = (int) $link_id;
    $v = hammasir_validate_message_body($body);
    if (!$v['ok']) return ($v['error'] === 'env') ? 'env' : 'invalid_body';
    $body = $v['body'];
    $today = hammasir_jalali_today(); // مرز روز شمسی — server-generated (D35/P7/AC4.7)
    if ($today === null) return 'error';
    $pre = hammasir_link_get($link_id);
    if (!$pre) return 'not_found';
    $client_user_id = (int) $pre['client_user_id'];
    if (store_mode() === 'mysql') {
        $res = hammasir_with_lock($client_user_id, function () use ($sender_user_id, $link_id, $body, $today, $client_user_id) {
            $link = hammasir_link_get($link_id); // re-validate کامل داخل قفل (AC4.10)
            if (!$link) return 'not_found';
            $is_client = ((int) $link['client_user_id'] === $sender_user_id);
            $is_provider = ((int) $link['provider_user_id'] === $sender_user_id);
            if (!$is_client && !$is_provider) return 'not_found';
            if ($link['status'] !== 'ACTIVE') return 'not_active';
            if (!hammasir_messaging_enabled($link_id)) return 'messaging_off'; // D37/AC4.1/AC4.2
            $limit = $is_client ? hammasir_message_daily_limit() : hammasir_companion_daily_limit(); // D9/D8
            if ($limit === null) return false;
            $count = hammasir_messages_count_today($link_id, $sender_user_id, $today);
            if ($count === null) return false;
            if ($count >= $limit) return $is_client ? 'client_limit' : 'companion_limit'; // AC4.3
            $last = hammasir_messages_last_sender_time($link_id, $sender_user_id);
            if ($last !== null) {
                $ts = strtotime($last . ' ' . HAMMASIR_TZ);
                $cool = hammasir_message_cooldown_seconds();
                if ($ts !== false && $cool !== null && (time() - $ts) < $cool) return 'cooldown'; // P3/AC4.5
            }
            $recipient = $is_client ? (int) $link['provider_user_id'] : $client_user_id;
            if (!hammasir_db_message_insert($link_id, $sender_user_id, $recipient, $today, $body)) return false;
            return 'ok';
        });
        if ($res === '__LOCK_TIMEOUT__') return 'lock_timeout';
        return ($res === 'ok') ? 'ok' : (is_string($res) ? $res : 'error');
    }
    $res = hammasir_store_mutate(function (&$store) use ($sender_user_id, $link_id, $body, $today) {
        $link = null;
        foreach ($store['links'] as $l) {
            if ((int) $l['id'] === $link_id) { $link = $l; break; }
        }
        if (!$link) return 'not_found';
        $is_client = ((int) $link['client_user_id'] === $sender_user_id);
        $is_provider = ((int) $link['provider_user_id'] === $sender_user_id);
        if (!$is_client && !$is_provider) return 'not_found';
        if ($link['status'] !== 'ACTIVE') return 'not_active';
        // توگل واحد D37 — همان قاعده‌ی شاخه‌ی MySQL: هر دو کلید داخل قفل re-read می‌شوند
        // و ارسال فقط وقتی مجاز است که «هر دو» 1 باشند (اصلاح باگ: قبلاً فقط یک کلید چک می‌شد)
        $key_c = 0;
        $key_p = 0;
        foreach ($store['permissions'] as $p) {
            if ((int) $p['link_id'] !== $link_id) continue;
            if ($p['perm_key'] === 'CLIENT_CAN_MESSAGE_COMPANION') $key_c = (int) $p['enabled'];
            if ($p['perm_key'] === 'COMPANION_CAN_MESSAGE_CLIENT') $key_p = (int) $p['enabled'];
        }
        if (!($key_c === 1 && $key_p === 1)) return 'messaging_off';
        $limit = $is_client ? hammasir_message_daily_limit() : hammasir_companion_daily_limit();
        if ($limit === null) return false;
        $count = 0;
        $last = null;
        foreach ($store['messages'] as $m) {
            if ((int) $m['link_id'] !== $link_id || (int) $m['sender_user_id'] !== $sender_user_id) continue;
            if ($m['jalali_date'] === $today) $count++;
            if ($last === null || strcmp($m['created_at'], $last) > 0) $last = $m['created_at'];
        }
        if ($count >= $limit) return $is_client ? 'client_limit' : 'companion_limit';
        if ($last !== null) {
            $ts = strtotime($last . ' ' . HAMMASIR_TZ);
            $cool = hammasir_message_cooldown_seconds();
            if ($ts !== false && $cool !== null && (time() - $ts) < $cool) return 'cooldown';
        }
        $recipient = $is_client ? (int) $link['provider_user_id'] : (int) $link['client_user_id'];
        hammasir_file_message_insert($store, $link_id, $sender_user_id, $recipient, $today, $body);
        return 'ok';
    });
    return ($res === 'ok') ? 'ok' : (is_string($res) ? $res : 'error');
}

/* ---------- فاز ۵: خوانده‌شدن (AC4.8/AC5.3) ---------- */

function hammasir_mark_messages_read($user_id) {
    // فقط پیام‌های recipient جاری — هنگام باز کردن گفتگو (D11/AC4.8)
    $user_id = (int) $user_id;
    if (store_mode() === 'mysql') {
        $res = hammasir_with_lock($user_id, function () use ($user_id) {
            return hammasir_db_messages_mark_read($user_id) ? 'ok' : false;
        });
        return ($res === 'ok');
    }
    $res = hammasir_store_mutate(function (&$store) use ($user_id) {
        hammasir_file_messages_mark_read($store, $user_id);
        return 'ok';
    });
    return ($res === 'ok');
}

function hammasir_mark_events_read($user_id) {
    // فقط رویدادهای recipient جاری — هنگام مشاهده‌ی اعلان‌ها (D17/D21/AC5.3)
    $user_id = (int) $user_id;
    if (store_mode() === 'mysql') {
        $res = hammasir_with_lock($user_id, function () use ($user_id) {
            return hammasir_db_events_mark_read($user_id) ? 'ok' : false;
        });
        return ($res === 'ok');
    }
    $res = hammasir_store_mutate(function (&$store) use ($user_id) {
        hammasir_file_events_mark_read($store, $user_id);
        return 'ok';
    });
    return ($res === 'ok');
}

/* ==================================================================
 * بخش ۱۰) نقش‌ها و حالت فعال (حکم PO — بخش ۱/۲/۳)
 * ------------------------------------------------------------------
 * سه قابلیت مستقل: can_admin / can_provider / can_client (همیشه).
 * یک «حالت فعال» در هر لحظه: admin | provider | client.
 * ذخیره‌ی حالت: نشست + persist در storage ماژول (فایل مود؛ در mysql
 * نشست‌محور — محدودیت مستند در گزارش، بدون دست زدن به joma_users).
 * ================================================================== */

/* ---------- پرچم‌های per-user — موجودیت فراداده‌ای هفتم (D40 اصلاح‌شده) ---------- */

function hammasir_db_flag_get($user_id, $key) {
    // شاخه‌ی MySQL: joma_hammasir_user_flags با یکتایی (user_id, flag_key)
    $row = joma_query_one(
        'SELECT flag_value FROM joma_hammasir_user_flags WHERE user_id=? AND flag_key=?',
        'is',
        array((int) $user_id, (string) $key)
    );
    return $row ? (int) $row['flag_value'] : 0;
}

function hammasir_db_flag_set($user_id, $key, $value) {
    // شاخه‌ی MySQL: upsert روی کلید یکتا؛ updated_at صریح (بدون DEFAULT CURRENT_TIMESTAMP)
    $now = hammasir_now();
    return joma_exec(
        'INSERT INTO joma_hammasir_user_flags (user_id, flag_key, flag_value, updated_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE flag_value = VALUES(flag_value), updated_at = VALUES(updated_at)',
        'isis',
        array((int) $user_id, (string) $key, $value ? 1 : 0, $now)
    );
}

function hammasir_user_flag_get($user_id, $key) {
    // پرچم per-user فقط در فضای ماژول (3.4)؛ user_id=0 = پرچم سیستمی ماژول.
    // پایدار در هر دو حالت (حکم PO — رفع backlog): mysql = جدول فراداده‌ی هفتم؛
    // file = کلید user_flags در storage مستقل. رفتار دو حالت یکسان است.
    $user_id = (int) $user_id;
    if (store_mode() === 'mysql') {
        return hammasir_db_flag_get($user_id, $key);
    }
    $store = hammasir_store_load();
    if ($store === null) return 0;
    if (!isset($store['user_flags'][$user_id][$key])) return 0;
    return (int) $store['user_flags'][$user_id][$key];
}

function hammasir_user_flag_set($user_id, $key, $value) {
    $user_id = (int) $user_id;
    $value = $value ? 1 : 0;
    if (store_mode() === 'mysql') {
        return hammasir_db_flag_set($user_id, $key, $value);
    }
    $res = hammasir_store_mutate(function (&$store) use ($user_id, $key, $value) {
        if (!isset($store['user_flags'][$user_id]) || !is_array($store['user_flags'][$user_id])) $store['user_flags'][$user_id] = array();
        $store['user_flags'][$user_id][$key] = $value;
        return 'ok';
    });
    return ($res === 'ok');
}

function hammasir_capabilities() {
    // قابلیت‌های کاربر جاری (2.2) — admin از مجوز JOMA؛ provider از Registry ماژول
    $cap = array('can_admin' => false, 'can_provider' => false, 'can_client' => true);
    $cap['can_admin'] = has_perm('ADMIN_ACCESS');
    try {
        $u = current_user();
        if ($u) {
            // باگ ۱ (گزارش PO): نقش در $_SESSION['user'] هنگام ورود ثبت می‌شود؛ اگر کاربر
            // «هنگام وارد بودن» ادمین شده باشد (ارتقا/ویرایش دستی)، snapshot کهنه می‌ماند و
            // can_admin=false → mode در client می‌ماند و Registry دیده نمی‌شود.
            // اصلاح: اگر سطر زنده‌ی کاربر admin است، نقش نشست تازه می‌شود (فقط مسیر ارتقا).
            if (!$cap['can_admin'] && $u['role_key'] !== 'admin' && isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                $fresh = get_user((int) $u['id']);
                if ($fresh && $fresh['role_key'] === 'admin') {
                    $_SESSION['user']['role_key'] = 'admin';
                    $cap['can_admin'] = has_perm('ADMIN_ACCESS');
                }
            }
            $p = hammasir_provider_by_user_id((int) $u['id']);
            if ($p && $p['status'] === 'ACTIVE') $cap['can_provider'] = true;
        }
    } catch (Throwable $e) {
        $cap['can_provider'] = false;
    }
    return $cap;
}

function hammasir_active_mode() {
    // حالت فعال با اعتبارسنجی در برابر قابلیت‌ها؛ اولویت پیش‌فرض: admin > provider > client (2.4)
    $cap = hammasir_capabilities();
    $mode = (isset($_SESSION['hammasir_mode'])) ? (string) $_SESSION['hammasir_mode'] : '';
    if (!in_array($mode, array('admin', 'provider', 'client'), true)) $mode = '';
    if ($mode === 'admin' && !$cap['can_admin']) $mode = '';
    if ($mode === 'provider' && !$cap['can_provider']) $mode = '';
    if ($mode === '') {
        $u = current_user();
        if ($u) {
            // بازیابی حالت ذخیره‌شده‌ی کاربر (فایل مود) اگر نشست تازه است
            $m = hammasir_user_flag_get((int) $u['id'], 'mode');
            $cand = ($m === 1) ? 'client' : (($m === 2) ? 'provider' : (($m === 3) ? 'admin' : ''));
            if ($cand === 'admin' && !$cap['can_admin']) $cand = '';
            if ($cand === 'provider' && !$cap['can_provider']) $cand = '';
            if ($cand !== '') $mode = $cand;
        }
        if ($mode === '') {
            if ($cap['can_admin']) $mode = 'admin';
            elseif ($cap['can_provider']) $mode = 'provider';
            else $mode = 'client';
        }
        $_SESSION['hammasir_mode'] = $mode;
    }
    return $mode;
}

function hammasir_mode_chosen() {
    // آیا کاربر صریحاً حالت را انتخاب کرده؟ (برای نمایش کارت‌های انتخاب — 2.3)
    if (isset($_SESSION['hammasir_mode_chosen']) && $_SESSION['hammasir_mode_chosen'] === true) return true;
    $u = current_user();
    if ($u && hammasir_user_flag_get((int) $u['id'], 'mode_chosen') === 1) {
        $_SESSION['hammasir_mode_chosen'] = true;
        return true;
    }
    return false;
}

function hammasir_mode_set($mode) {
    // سوییچ حالت (2.3) — فقط به حالت‌های مجاز؛ POST + CSRF در صفحه‌ی مرکزی
    $cap = hammasir_capabilities();
    if (!in_array($mode, array('admin', 'provider', 'client'), true)) return 'invalid';
    if ($mode === 'admin' && !$cap['can_admin']) return 'invalid';
    if ($mode === 'provider' && !$cap['can_provider']) return 'invalid';
    $_SESSION['hammasir_mode'] = $mode;
    $_SESSION['hammasir_mode_chosen'] = true;
    $u = current_user();
    if ($u) {
        $code = ($mode === 'client') ? 1 : (($mode === 'provider') ? 2 : 3);
        try { hammasir_user_flag_set((int) $u['id'], 'mode', $code); } catch (Throwable $e) { /* نشست کافی است */ }
        try { hammasir_user_flag_set((int) $u['id'], 'mode_chosen', 1); } catch (Throwable $e) { /* نشست کافی است */ }
    }
    return 'ok';
}

function hammasir_mode_labels() {
    // برچسب‌های ثابت حالت‌ها (template امن UI)
    return array(
        'client' => 'کاربر',
        'provider' => 'مشاور',
        'admin' => 'مدیر',
    );
}

function hammasir_mode_card_labels() {
    // عنوان کارت‌های انتخاب حالت — عین عبارت‌های حکم PO (2.3)
    return array(
        'client' => 'ورود به‌عنوان کاربر',
        'provider' => 'ورود به‌عنوان مشاور',
        'admin' => 'ورود به‌عنوان مدیر',
    );
}

function hammasir_unread_badge($user_id) {
    // Badge منو: مجموع پیام متنی + رویداد سیستمی نخوانده (D10) — در file با یک خواندن
    $user_id = (int) $user_id;
    if (store_mode() === 'mysql') {
        $m = hammasir_messages_unread_count($user_id);
        $e = hammasir_events_unread_count($user_id);
        $msgs = ($m === null) ? 0 : (int) $m;
        $evs = ($e === null) ? 0 : (int) $e;
        return array('msgs' => $msgs, 'events' => $evs, 'total' => $msgs + $evs);
    }
    $store = hammasir_store_load();
    if ($store === null) return array('msgs' => 0, 'events' => 0, 'total' => 0);
    $active = array();
    foreach ($store['links'] as $l) {
        if ($l['status'] === 'ACTIVE') $active[(int) $l['id']] = true;
    }
    $msgs = 0;
    $evs = 0;
    foreach ($store['messages'] as $m) {
        if ((int) $m['recipient_user_id'] === $user_id && (int) $m['is_read'] === 0 && isset($active[(int) $m['link_id']])) $msgs++;
    }
    foreach ($store['system_events'] as $ev) {
        if ((int) $ev['recipient_user_id'] === $user_id && (int) $ev['is_read'] === 0) $evs++;
    }
    return array('msgs' => $msgs, 'events' => $evs, 'total' => $msgs + $evs);
}

function hammasir_links_any_by_client($client_user_id) {
    // آیا کاربر تا حالا هیچ لینکی به‌عنوان مراجع داشته؟ (شرط Onboarding — 3.1)
    $client_user_id = (int) $client_user_id;
    if (store_mode() === 'mysql') {
        $r = joma_query_one('SELECT id FROM joma_hammasir_links WHERE client_user_id=? LIMIT 1', 'i', array($client_user_id));
        return $r ? true : false;
    }
    $store = hammasir_store_load();
    if ($store === null) return false;
    foreach ($store['links'] as $l) {
        if ((int) $l['client_user_id'] === $client_user_id) return true;
    }
    return false;
}

function hammasir_latest_link_by_client($client_user_id) {
    // باگ ۴ (گزارش PO): آخرین لینک مراجع با «هر وضعیتی» — برای نمایش وضعیت
    // به‌جای فرم انتخاب؛ ترتیب: جدیدترین بر اساس id.
    $client_user_id = (int) $client_user_id;
    if (store_mode() === 'mysql') {
        return joma_query_one('SELECT * FROM joma_hammasir_links WHERE client_user_id=? ORDER BY id DESC LIMIT 1', 'i', array($client_user_id));
    }
    $store = hammasir_store_load();
    if ($store === null) return null;
    $found = null;
    foreach ($store['links'] as $l) {
        if ((int) $l['client_user_id'] !== $client_user_id) continue;
        if ($found === null || (int) $l['id'] > (int) $found['id']) $found = $l;
    }
    return $found;
}

function hammasir_onboarding_text() {
    // متن توضیح کارت دعوت — یک نقطه‌ی قابل ویرایش (حکم PO — اصلاح نهایی Onboarding؛ متن مصوب عیناً)
    return 'همراه، مشاور یا روان‌شناسی است که با انتخاب و اجازه‌ی شما می‌تواند روند پیشرفت‌تان را ببیند، با شما گفتگو کند و در این مسیر کنارتان باشد. انتخاب همراه کاملاً اختیاری است و هر زمان بخواهید می‌توانید دسترسی او را تغییر دهید یا ارتباط را قطع کنید.';
}

function hammasir_onboarding_state($user_id) {
    // مدل state واحد Onboarding (حکم PO — بخش ۶):
    //   onboarding_seen   = 0|1 — دیده‌شدن کارت دعوت (پایدار per-user؛ هرگز خودکار صفر نمی‌شود)
    //   onboarding_intent = 0|1 — فقط تعیین‌کننده‌ی بازشدنِ «آگاهانه‌ی» فرم انتخاب همراه
    // مهاجرت مدل قدیمی (onboarding_choice نسخه‌های قبل): 1=پذیرش → intent=1؛
    // seen قدیمی بدون choice → intent=0 (محافظه‌کارانه — فرم خودکار باز نمی‌شود).
    // fail-closed: خطای خواندن → seen=1 (هیچ کارت/فرمی خودکار رندر نمی‌شود).
    $user_id = (int) $user_id;
    $seen = 1;
    $intent = 0;
    try {
        $seen = (int) hammasir_user_flag_get($user_id, 'onboarding_seen');
        $intent = (int) hammasir_user_flag_get($user_id, 'onboarding_intent');
        if ($intent !== 1 && (int) hammasir_user_flag_get($user_id, 'onboarding_choice') === 1) {
            $intent = 1; // مهاجرت: پذیرشِ ثبت‌شده با مدل قدیمی
        }
    } catch (Throwable $e) {
        return array('seen' => 1, 'intent' => 0);
    }
    return array('seen' => ($seen === 1) ? 1 : 0, 'intent' => ($intent === 1) ? 1 : 0);
}

/* ==================================================================
 * بخش ۱۱) مدیران و مالک سیستم (حکم PO — بخش ۴: D-ADMIN-2 / D-OWNER-BOOTSTRAP)
 * ------------------------------------------------------------------
 * ارتقا/سلب نقش مدیر فقط از کاربران موجود JOMA (نه یوزر موازی)؛
 * قفل ایمنی: آخرین مدیر قابل سلب نیست. Bootstrap مالک: یک‌بار، بدون رمز،
 * بدون دستکاری دستی store.json — فقط وقتی هیچ مدیری در سیستم نیست.
 * ================================================================== */

function hammasir_admin_count() {
    // تعداد مدیرهای سیستم (role_key='admin') — مبنای قفل آخرین مدیر
    if (store_mode() === 'mysql') {
        $r = joma_query_one("SELECT COUNT(*) AS c FROM joma_users WHERE role_key='admin'", '', array());
        return $r ? (int) $r['c'] : null;
    }
    $data = store_load();
    if (!is_array($data)) return null;
    $n = 0;
    foreach ($data['users'] as $u) {
        if ($u['role_key'] === 'admin') $n++;
    }
    return $n;
}

function hammasir_users_list_roles($limit = 500) {
    // فهرست کاربران برای مدیریت مدیران — فقط id/نام/نام‌کاربری/نقش (بدون ایمیل/موبایل)
    $limit = (int) $limit;
    if ($limit < 1) $limit = 500;
    if (store_mode() === 'mysql') {
        return joma_query('SELECT id, first_name, last_name, username, role_key FROM joma_users ORDER BY username ASC LIMIT ' . $limit, '', array());
    }
    $data = store_load();
    if (!is_array($data)) return array();
    $out = array();
    foreach ($data['users'] as $u) {
        $out[] = array(
            'id' => (int) $u['id'],
            'first_name' => $u['first_name'],
            'last_name' => $u['last_name'],
            'username' => $u['username'],
            'role_key' => $u['role_key'],
        );
    }
    usort($out, function ($a, $b) { return strcmp($a['username'], $b['username']); });
    if (count($out) > $limit) $out = array_slice($out, 0, $limit);
    return $out;
}

function hammasir_user_set_role($user_id, $role, $acting_user_id) {
    // ارتقا به مدیر / بازگشت به کاربر عادی — حداقل تغییر (فقط فیلد نقش).
    // قفل ایمنی: اگر هدف مدیر است و تنها مدیر سیستم است → سلب ممنوع (D-ADMIN-2).
    // کدها: ok | not_found | invalid_role | last_admin | error
    $user_id = (int) $user_id;
    $acting_user_id = (int) $acting_user_id;
    if (!in_array($role, array('admin', 'member'), true)) return 'invalid_role';
    $target = get_user($user_id);
    if (!$target) return 'not_found';
    if ($target['role_key'] === $role) return 'ok'; // بدون تغییر واقعی
    if ($target['role_key'] === 'admin' && $role !== 'admin') {
        $count = hammasir_admin_count();
        if ($count === null) return 'error';
        if ($count <= 1) return 'last_admin';
    }
    if (store_mode() === 'mysql') {
        $ok = joma_exec('UPDATE joma_users SET role_key=? WHERE id=?', 'si', array($role, $user_id));
        return $ok ? 'ok' : 'error';
    }
    // file: همان الگوی writer خود JOMA (store_load → patch → store_save)
    $data = store_load();
    if (!is_array($data)) return 'error';
    $found = false;
    foreach ($data['users'] as $i => $u) {
        if ((int) $u['id'] === $user_id) {
            $data['users'][$i]['role_key'] = $role;
            $found = true;
            break;
        }
    }
    if (!$found) return 'not_found';
    store_save($data);
    return 'ok';
}

function hammasir_config_mark_bootstrap_done() {
    // به‌روزرسانی یک‌کلیدی config ماژول (tmp + rename)؛ شکست ⇒ پرچم storage کافی است
    $file = dirname(__FILE__) . '/../config/hammasir_config.php';
    if (!is_file($file)) return false;
    $raw = @file_get_contents($file);
    if ($raw === false) return false;
    $old = "'bootstrap_owner_done' => false";
    $new = "'bootstrap_owner_done' => true";
    if (substr_count($raw, $old) !== 1) return false;
    $tmp = $file . '.tmp-' . uniqid('', true);
    if (@file_put_contents($tmp, str_replace($old, $new, $raw)) === false) return false;
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function hammasir_bootstrap_owner() {
    // D-OWNER-BOOTSTRAP: ارتقای یک‌باره‌ی مالک وقتی هیچ مدیری نیست (حکم PO 4.2).
    // خروجی status: done | admin_exists | need_username | user_missing | promoted | error
    $cfg = hammasir_config();
    if (isset($cfg['bootstrap_owner_done']) && $cfg['bootstrap_owner_done'] === true) return array('status' => 'done');
    if (hammasir_user_flag_get(0, 'bootstrap_owner_done') === 1) return array('status' => 'done');
    $count = hammasir_admin_count();
    if ($count === null) return array('status' => 'error');
    if ($count > 0) return array('status' => 'admin_exists'); // از قبل مدیر هست → هیچ ارتقای خودکار (4.2)
    $username = isset($cfg['bootstrap_owner_username']) ? trim((string) $cfg['bootstrap_owner_username']) : '';
    if ($username === '') return array('status' => 'need_username');
    $u = user_by_username($username);
    if (!$u) return array('status' => 'user_missing');
    $res = hammasir_user_set_role((int) $u['id'], 'admin', (int) $u['id']);
    if ($res !== 'ok') return array('status' => 'error');
    try { hammasir_user_flag_set(0, 'bootstrap_owner_done', 1); } catch (Throwable $e) { /* config نیز تلاش می‌شود */ }
    try { hammasir_config_mark_bootstrap_done(); } catch (Throwable $e) { /* پرچم storage کافی است */ }
    return array('status' => 'promoted', 'username' => $username);
}

/* ==================================================================
 * بخش ۱۲) لایه‌ی DTO — مشاهده‌ی پرونده‌ی مراجع توسط همراه (حکم PO — بخش ۵)
 * ------------------------------------------------------------------
 * خواندن داده‌ی JOMA فقط read-only و از طریق توابع موجود؛ بدون نوشتن
 * (ensure_period عمداً صدا زده نمی‌شود)؛ خروجی خام build_report/success_report
 * هرگز مستقیم به UI نمی‌رود — فقط فیلدهای allowlist‌شده‌ی زیر (AC6.4).
 * ================================================================== */

function hammasir_latest_plan($client_user_id) {
    // آخرین دوره/برنامه‌ی مراجع بدون هیچ نوشتن (ensure_period صدا زده نمی‌شود)
    $client_user_id = (int) $client_user_id;
    if (store_mode() === 'mysql') {
        return joma_query_one(
            'SELECT pe.*, pl.id AS plan_id, pl.status AS plan_status FROM joma_periods pe JOIN joma_plans pl ON pl.period_id=pe.id WHERE pe.user_id=? ORDER BY pe.period_key DESC LIMIT 1',
            'i',
            array($client_user_id)
        );
    }
    $data = store_load();
    if (!is_array($data)) return null;
    $best = null;
    foreach ($data['periods'] as $pe) {
        if ((int) $pe['user_id'] !== $client_user_id) continue;
        foreach ($data['plans'] as $pl) {
            if ((int) $pl['period_id'] !== (int) $pe['id']) continue;
            $row = $pe;
            $row['plan_id'] = $pl['id'];
            $row['plan_status'] = $pl['status'];
            if ($best === null || strcmp($row['period_key'], $best['period_key']) > 0) $best = $row;
        }
    }
    return $best;
}

function hammasir_dto_summary($client_user_id) {
    // VIEW_SUMMARY: شمارش‌ها + وضعیت برنامه + جمع وزن — بدون جزئیات عددی خام
    $client_user_id = (int) $client_user_id;
    $row = hammasir_latest_plan($client_user_id);
    if (!$row) return null;
    $plan_id = (int) $row['plan_id'];
    $acts = list_plan_activities($plan_id, $client_user_id);
    $evs = list_events($plan_id, $client_user_id);
    $weight = 0;
    foreach ($acts as $a) $weight += (int) $a['weight'];
    $days = array();
    foreach ($evs as $e) $days[$e['performance_date']] = true;
    return array(
        'period_key' => $row['period_key'],
        'plan_status' => isset($row['plan_status']) ? $row['plan_status'] : '',
        'activity_count' => count($acts),
        'event_count' => count($evs),
        'weight_sum' => $weight,
        'days_with_events' => count($days),
    );
}

function hammasir_dto_progress($client_user_id) {
    // VIEW_PROGRESS: خروجی success_report به‌صورت تجمیعی/allowlist‌شده (نه خروجی خام)
    $client_user_id = (int) $client_user_id;
    if (!function_exists('success_report') || !function_exists('jalali_period_bounds')) return null;
    $row = hammasir_latest_plan($client_user_id);
    if (!$row) return null;
    $plan = get_plan((int) $row['plan_id'], $client_user_id);
    if (!$plan) return null;
    $bounds = jalali_period_bounds($plan['period_key']);
    $acts = list_plan_activities((int) $plan['id'], $client_user_id);
    $evs = list_events((int) $plan['id'], $client_user_id);
    $today = hammasir_jalali_today();
    if ($today === null) return null;
    $rep = success_report($plan, $bounds, $acts, $evs, $today);
    $items = array();
    if (isset($rep['activities']) && is_array($rep['activities'])) {
        foreach ($rep['activities'] as $a) {
            $items[] = array(
                'title' => isset($a['activity_title']) ? $a['activity_title'] : '',
                'coverage' => isset($a['coverage']) ? $a['coverage'] : null,
                'achievement' => isset($a['activity_achievement']) ? $a['activity_achievement'] : '',
                'in_valid' => !empty($a['in_valid_analytic']),
            );
        }
    }
    return array(
        'overall_coverage' => isset($rep['overall_coverage']) ? $rep['overall_coverage'] : null,
        'overall_success' => isset($rep['overall_success']) ? $rep['overall_success'] : '',
        'activities' => $items,
    );
}

function hammasir_achievement_label($key) {
    // نگاشت برچسب سطح تحقق (enum انگلیسی → برچسب ثابت فارسی UI)
    $map = array(
        'HIGH' => 'خوب',
        'MEDIUM' => 'متوسط',
        'LOW' => 'ضعیف',
        'UNSPECIFIED' => 'نامشخص',
    );
    return isset($map[$key]) ? $map[$key] : 'نامشخص';
}

function hammasir_dto_activity_details($client_user_id, $max_events = 20) {
    // VIEW_ACTIVITY_DETAILS: فهرست فعالیت‌های برنامه + آخرین رویدادهای ثبت‌شده
    $client_user_id = (int) $client_user_id;
    $row = hammasir_latest_plan($client_user_id);
    if (!$row) return null;
    $plan_id = (int) $row['plan_id'];
    $acts = list_plan_activities($plan_id, $client_user_id);
    $evs = list_events($plan_id, $client_user_id);
    $titles = array();
    $units = array();
    $act_list = array();
    foreach ($acts as $a) {
        $titles[(int) $a['id']] = $a['name'];
        $units[(int) $a['id']] = isset($a['unit']) ? $a['unit'] : '';
        $act_list[] = array(
            'title' => $a['name'],
            'target' => isset($a['target_value']) ? $a['target_value'] : '',
            'unit' => isset($a['unit']) ? $a['unit'] : '',
            'frequency' => isset($a['frequency']) ? $a['frequency'] : '',
        );
    }
    $events = array();
    if (is_array($evs)) {
        $picked = array_slice($evs, 0, (int) $max_events);
        foreach ($picked as $e) {
            $events[] = array(
                'date' => $e['performance_date'],
                'title' => isset($titles[(int) $e['plan_activity_id']]) ? $titles[(int) $e['plan_activity_id']] : '',
                'value' => isset($e['actual_value']) ? $e['actual_value'] : '',
                'unit' => isset($units[(int) $e['plan_activity_id']]) ? $units[(int) $e['plan_activity_id']] : '',
            );
        }
    }
    return array('activities' => $act_list, 'events' => $events);
}

function hammasir_dto_mood($client_user_id, $days = 30) {
    // VIEW_MOOD: خلق ۳۰ روز اخیر — فقط با رضایت صریح مراجع (پیش‌فرض خاموش)
    $client_user_id = (int) $client_user_id;
    $today = hammasir_jalali_today();
    if ($today === null) return null;
    $start = $today;
    if (function_exists('success_jalali_add_days')) {
        $start = success_jalali_add_days($today, -((int) $days));
    }
    $rows = list_moods($client_user_id, $start, $today);
    if (!is_array($rows)) return null;
    $out = array();
    foreach ($rows as $m) {
        $out[] = array(
            'date' => isset($m['jalali_date']) ? $m['jalali_date'] : '',
            'general_mood' => isset($m['general_mood']) ? $m['general_mood'] : '',
            'energy' => isset($m['energy']) ? $m['energy'] : '',
            'stress' => isset($m['stress']) ? $m['stress'] : '',
            'note' => isset($m['note']) ? $m['note'] : '',
        );
    }
    return $out;
}


/* ---------- بخش ۱۳: میز کار مدیریتی مشاور (فاز A — حکم PO) — همه read-only ---------- */

function hammasir_mood_wellbeing($row) {
    // نمره‌ی ترکیبی خلق از ۵ شاخص JOMA (هرکدام ۱..۵): میانگین energy+general+focus+sleep+(6-stress)
    // → دامنه 1..5؛ سپس نگاشت خطی به مقیاس ۸ نقطه‌ای UI حکم (1..8): round(score*8/5).
    $s = 0.0;
    $n = 0;
    foreach (array('energy', 'general_mood', 'focus', 'sleep_quality') as $k) {
        if (isset($row[$k]) && (int) $row[$k] > 0) { $s += (int) $row[$k]; $n++; }
    }
    if (isset($row['stress']) && (int) $row['stress'] > 0) { $s += (6 - (int) $row['stress']); $n++; }
    if ($n === 0) return null;
    $score = $s / $n; // 1..5
    $v = (int) round($score * 8 / 5); // 2..8
    if ($v < 1) $v = 1;
    if ($v > 8) $v = 8;
    return $v;
}

function hammasir_mood_dots($link_id, $days = 7) {
    // ۷ نقطه‌ی خلق مراجعِ یک لینک (فقط با VIEW_MOOD) — مقدار 1..8 یا null برای ثبت‌نشده.
    // مبنای روز: hammasir_jalali_today (D35)؛ روزهای قبلی با success_jalali_add_days.
    $link_id = (int) $link_id;
    $days = (int) $days;
    if ($days < 1) $days = 7;
    $link = hammasir_link_get($link_id);
    if (!$link) return null;
    $perms = hammasir_perm_map($link_id);
    if (!isset($perms['VIEW_MOOD']) || (int) $perms['VIEW_MOOD'] !== 1) return null;
    $today = hammasir_jalali_today();
    if ($today === null || !function_exists('success_jalali_add_days')) return null;
    $client = (int) $link['client_user_id'];
    // پنجره: today-(days-1) .. today (قدیمی → جدید)
    $want = array();
    for ($i = $days - 1; $i >= 0; $i--) {
        $want[] = ($i === 0) ? $today : success_jalali_add_days($today, -$i);
    }
    $by_date = array();
    if (store_mode() === 'mysql') {
        $rows = joma_query(
            'SELECT jalali_date, energy, general_mood, focus, sleep_quality, stress FROM joma_mood_records WHERE user_id=? AND jalali_date>=? AND jalali_date<=?',
            'iss',
            array($client, $want[0], $today)
        );
        foreach ($rows as $r) $by_date[$r['jalali_date']] = $r;
    } else {
        $store = hammasir_store_load();
        if ($store === null) return null;
        foreach ($store['moods'] as $m) {
            if ((int) $m['user_id'] !== $client) continue;
            $by_date[$m['jalali_date']] = $m;
        }
    }
    $out = array();
    foreach ($want as $d) {
        $out[] = isset($by_date[$d]) ? hammasir_mood_wellbeing($by_date[$d]) : null;
    }
    return $out;
}

function hammasir_client_last_activity($link_id) {
    // آخرین فعالیت مراجعِ لینک (پیام این لینک / رویداد عملکرد / خلق) — 'Y-m-d H:i:s' یا null
    $link_id = (int) $link_id;
    $link = hammasir_link_get($link_id);
    if (!$link) return null;
    $client = (int) $link['client_user_id'];
    $best = null;
    if (store_mode() === 'mysql') {
        $r = joma_query_one('SELECT MAX(created_at) AS m FROM joma_hammasir_messages WHERE link_id=?', 'i', array($link_id));
        if ($r && $r['m'] !== null) $best = $r['m'];
        $r = joma_query_one('SELECT MAX(created_at) AS m FROM joma_performance_events WHERE user_id=?', 'i', array($client));
        if ($r && $r['m'] !== null && ($best === null || strcmp($r['m'], $best) > 0)) $best = $r['m'];
        $r = joma_query_one('SELECT MAX(created_at) AS m FROM joma_mood_records WHERE user_id=?', 'i', array($client));
        if ($r && $r['m'] !== null && ($best === null || strcmp($r['m'], $best) > 0)) $best = $r['m'];
        return $best;
    }
    $store = hammasir_store_load();
    if ($store === null) return null;
    foreach ($store['messages'] as $msg) {
        if ((int) $msg['link_id'] !== $link_id) continue;
        if ($best === null || strcmp((string) $msg['created_at'], $best) > 0) $best = (string) $msg['created_at'];
    }
    foreach ($store['events'] as $ev) {
        if ((int) $ev['user_id'] !== $client) continue;
        if ($best === null || strcmp((string) $ev['created_at'], $best) > 0) $best = (string) $ev['created_at'];
    }
    foreach ($store['moods'] as $m) {
        if ((int) $m['user_id'] !== $client) continue;
        if ($best === null || strcmp((string) $m['created_at'], $best) > 0) $best = (string) $m['created_at'];
    }
    return $best;
}

function hammasir_time_ago($dt) {
    // زمان نسبی فارسی (برای هشدارها/کارت‌ها) — بدون وابستگی به TZ سرور: اختلاف از joma_now()
    if (!$dt) return '';
    $now = hammasir_now();
    if (!$now) return '';
    $t1 = strtotime($now);
    $t2 = strtotime((string) $dt);
    if ($t1 === false || $t2 === false) return '';
    $diff = $t1 - $t2;
    if ($diff < 0) $diff = 0;
    $fa = function_exists('fa_num') ? 'fa_num' : 'strval';
    if ($diff < 60) return 'همین حالا';
    if ($diff < 3600) return call_user_func($fa, (int) floor($diff / 60)) . ' دقیقه پیش';
    if ($diff < 86400) return call_user_func($fa, (int) floor($diff / 3600)) . ' ساعت پیش';
    $d = (int) floor($diff / 86400);
    if ($d === 1) return 'دیروز';
    return call_user_func($fa, $d) . ' روز پیش';
}

function hammasir_dashboard_kpis($provider_user_id) {
    // ۴ شاخص داشبورد مشاور — read-only؛ خطا → مقادیر صفر و پرچم‌های false (بخش «با اجازه‌ی مراجع»)
    $provider_user_id = (int) $provider_user_id;
    $out = array(
        'active_count' => 0,
        'pending_count' => 0,
        'unread_count' => 0,
        'week_performance_count' => 0,
        'has_progress_view' => false,
    );
    $active = hammasir_links_by_provider($provider_user_id, 'ACTIVE');
    $pending = hammasir_links_by_provider($provider_user_id, 'PENDING');
    $out['active_count'] = is_array($active) ? count($active) : 0;
    $out['pending_count'] = is_array($pending) ? count($pending) : 0;
    $badge = hammasir_unread_badge($provider_user_id);
    if (is_array($badge) && isset($badge['msgs'])) $out['unread_count'] = (int) $badge['msgs'];
    // ثبت عملکرد هفته (۷ روز اخیر) — فقط مراجع‌های ACTIVE با VIEW_PROGRESS
    $today = hammasir_jalali_today();
    if ($today === null || !function_exists('success_jalali_add_days')) return $out;
    $from = success_jalali_add_days($today, -6);
    $clients = array();
    if (is_array($active)) {
        foreach ($active as $l) {
            $perms = hammasir_perm_map((int) $l['id']);
            if (isset($perms['VIEW_PROGRESS']) && (int) $perms['VIEW_PROGRESS'] === 1) {
                $clients[] = (int) $l['client_user_id'];
            }
        }
    }
    if (count($clients) === 0) return $out;
    $out['has_progress_view'] = true;
    if (store_mode() === 'mysql') {
        foreach ($clients as $c) {
            $r = joma_query_one('SELECT COUNT(*) AS c FROM joma_performance_events WHERE user_id=? AND performance_date>=? AND performance_date<=?', 'iss', array($c, $from, $today));
            if ($r) $out['week_performance_count'] += (int) $r['c'];
        }
        return $out;
    }
    $store = hammasir_store_load();
    if ($store === null) return $out;
    foreach ($store['events'] as $ev) {
        if (!in_array((int) $ev['user_id'], $clients, true)) continue;
        $d = isset($ev['performance_date']) ? (string) $ev['performance_date'] : '';
        if ($d === '' || strcmp($d, $from) < 0 || strcmp($d, $today) > 0) continue;
        $out['week_performance_count']++;
    }
    return $out;
}

function hammasir_attention_items($provider_user_id) {
    // «نیازمند توجه» — ترتیب: pending > unread > mood_decline؛ حداکثر ۵ مورد
    $provider_user_id = (int) $provider_user_id;
    $items = array();
    $active = array();
    $pending = array();
    $tmp = hammasir_links_by_provider($provider_user_id, 'ACTIVE');
    if (is_array($tmp)) $active = $tmp;
    $tmp = hammasir_links_by_provider($provider_user_id, 'PENDING');
    if (is_array($tmp)) $pending = $tmp;
    // ۱) درخواست‌های همراهی
    foreach ($pending as $l) {
        $perms = hammasir_perm_map((int) $l['id']);
        $labels = array();
        if (is_array($perms)) {
            foreach (array('VIEW_SUMMARY', 'VIEW_PROGRESS', 'VIEW_ACTIVITY_DETAILS', 'VIEW_MOOD') as $k) {
                if (isset($perms[$k]) && (int) $perms[$k] === 1) $labels[] = $k;
            }
        }
        $items[] = array(
            'type' => 'pending',
            'link_id' => (int) $l['id'],
            'client_name' => hammasir_user_display((int) $l['client_user_id']),
            'time_ago' => hammasir_time_ago(isset($l['requested_at']) ? $l['requested_at'] : (isset($l['created_at']) ? $l['created_at'] : null)),
            'detail' => '',
            'perms' => $labels,
        );
    }
    // ۲) پیام‌های خوانده‌نشده (به ازای هر لینک ACTIVE)
    foreach ($active as $l) {
        $cnt = 0;
        $last = null;
        if (store_mode() === 'mysql') {
            $r = joma_query_one('SELECT COUNT(*) AS c, MAX(created_at) AS m FROM joma_hammasir_messages WHERE link_id=? AND recipient_user_id=? AND is_read=0', 'ii', array((int) $l['id'], $provider_user_id));
            if ($r) { $cnt = (int) $r['c']; $last = $r['m']; }
        } else {
            $store = hammasir_store_load();
            if ($store !== null) {
                foreach ($store['messages'] as $msg) {
                    if ((int) $msg['link_id'] !== (int) $l['id']) continue;
                    if ((int) $msg['recipient_user_id'] !== $provider_user_id || (int) $msg['is_read'] !== 0) continue;
                    $cnt++;
                    if ($last === null || strcmp((string) $msg['created_at'], $last) > 0) $last = (string) $msg['created_at'];
                }
            }
        }
        if ($cnt > 0) {
            $items[] = array(
                'type' => 'unread',
                'link_id' => (int) $l['id'],
                'client_name' => hammasir_user_display((int) $l['client_user_id']),
                'time_ago' => hammasir_time_ago($last),
                'detail' => $cnt,
                'perms' => array(),
            );
        }
    }
    // ۳) روند نزولی خلق (فقط VIEW_MOOD): میانگین ۳ روز اخیر < میانگین ۳ روز قبل + طول نزول
    foreach ($active as $l) {
        $dots = hammasir_mood_dots((int) $l['id']);
        if (!is_array($dots)) continue;
        $vals = array();
        foreach ($dots as $v) if ($v !== null) $vals[] = $v;
        if (count($vals) < 4) continue; // برای مقایسه‌ی دو سه‌تایی حداقل ۴ نقطه لازم است
        $last3 = array_slice($vals, -3);
        $prev3 = array_slice($vals, -6, 3);
        if (count($prev3) < 3) continue;
        $avg_last = ($last3[0] + $last3[1] + $last3[2]) / 3;
        $avg_prev = ($prev3[0] + $prev3[1] + $prev3[2]) / 3;
        if ($avg_last >= $avg_prev) continue;
        // طول نزول متوالی از انتها
        $streak = 0;
        for ($i = count($vals) - 1; $i > 0; $i--) {
            if ($vals[$i] < $vals[$i - 1]) $streak++;
            else break;
        }
        if ($streak < 1) $streak = 1;
        $items[] = array(
            'type' => 'mood_decline',
            'link_id' => (int) $l['id'],
            'client_name' => hammasir_user_display((int) $l['client_user_id']),
            'time_ago' => '',
            'detail' => $streak,
            'perms' => array(),
        );
    }
    if (count($items) > 5) $items = array_slice($items, 0, 5);
    return $items;
}

function hammasir_clinic_mood_strip($provider_user_id) {
    // نوار حال‌وهوای کلینیک — تا ۵ مراجع ACTIVE با VIEW_MOOD؛ بقیه به‌صورت شمارش
    $provider_user_id = (int) $provider_user_id;
    $out = array('rows' => array(), 'more' => 0);
    $active = hammasir_links_by_provider($provider_user_id, 'ACTIVE');
    if (!is_array($active)) return $out;
    $all = array();
    foreach ($active as $l) {
        $dots = hammasir_mood_dots((int) $l['id']);
        if (!is_array($dots)) continue;
        $u = get_user((int) $l['client_user_id']);
        $short = '';
        if ($u && isset($u['first_name']) && $u['first_name'] !== '') $short = $u['first_name'];
        if ($short === '') $short = hammasir_user_display((int) $l['client_user_id']);
        $all[] = array('client_name' => $short, 'dots' => $dots);
    }
    $n = count($all);
    if ($n > 5) {
        $out['rows'] = array_slice($all, 0, 5);
        $out['more'] = $n - 5;
    } else {
        $out['rows'] = $all;
    }
    return $out;
}
