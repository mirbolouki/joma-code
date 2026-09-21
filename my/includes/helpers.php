<?php
function e($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function joma_now() {
    return date('Y-m-d H:i:s');
}

function joma_compute_base() {
    if (!empty($_SERVER['SCRIPT_NAME'])) {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
        $dir = rtrim(dirname($script), '/');
        if ($dir === '' || $dir === '.' || $dir === '/') return '';
        return $dir;
    }
    if (!empty($GLOBALS['JOMA_CONFIG']['base_url'])) {
        return rtrim($GLOBALS['JOMA_CONFIG']['base_url'], '/');
    }
    return '';
}

function joma_needs_sid() {
    $name = session_name();
    return empty($_COOKIE[$name]);
}

function joma_append_sid($url) {
    if ($url === '' || strpos($url, 'joma_sid=') !== false) return $url;
    if (strpos($url, 'javascript:') === 0) return $url;
    $sid = session_id();
    if ($sid === '' || !joma_needs_sid()) return $url;
    $sep = (strpos($url, '?') !== false) ? '&' : '?';
    return $url . $sep . 'joma_sid=' . rawurlencode($sid);
}

/**
 * Hidden input that keeps the session alive across a GET form.
 *
 * The application runs with `session.use_only_cookies = 0` and its cookie is
 * sent as `SameSite=None` without `Secure`, so browsers in an embedded
 * context (or over plain HTTP) drop it. Every LINK then carries `joma_sid`
 * (see joma_append_sid), but a GET form rebuilds the query string from its
 * own fields only — so without this input, submitting any filter or the
 * period/compare selectors silently logs the member out.
 */
function joma_sid_field() {
    if (!joma_needs_sid()) return '';
    $sid = session_id();
    if ($sid === '') return '';
    return '<input type="hidden" name="joma_sid" value="' . e($sid) . '">';
}

function joma_redirect($path) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Location: ' . joma_url($path));
    exit;
}

function joma_url($path) {
    $base = $GLOBALS['JOMA_BASE'];
    $url = rtrim($base, '/') . '/' . ltrim($path, '/');
    if (strpos($path, 'assets/') === 0) return $url;
    return joma_append_sid($url);
}

function joma_asset($path) {
    return joma_url('assets/' . ltrim($path, '/'));
}

function csrf_token() {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'
        . '<input type="hidden" name="joma_sid" value="' . e(session_id()) . '">';
}

function csrf_check() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $t = isset($_POST['csrf']) ? $_POST['csrf'] : '';
        if (!$t || !isset($_SESSION['csrf']) || $t !== $_SESSION['csrf']) {
            http_response_code(400);
            die('درخواست نامعتبر است.');
        }
    }
}

function current_user() {
    return isset($_SESSION['user']) ? $_SESSION['user'] : null;
}

function require_login() {
    if (!current_user()) {
        joma_redirect('index.php?p=login');
    }
}

function has_perm($perm) {
    $u = current_user();
    if (!$u) return false;
    $map = store_role_permissions($u['role_key']);
    return in_array($perm, $map, true);
}

function require_perm($perm) {
    require_login();
    if (!has_perm($perm)) {
        die('دسترسی مجاز نیست.');
    }
}

function current_period_key() {
    if (!empty($_GET['period']) && preg_match('/^\d{4}-\d{2}$/', $_GET['period'])) {
        $_SESSION['period_key'] = $_GET['period'];
        return $_GET['period'];
    }
    if (!empty($_SESSION['period_key']) && preg_match('/^\d{4}-\d{2}$/', $_SESSION['period_key'])) {
        return $_SESSION['period_key'];
    }
    return jalali_period_key(jalali_today());
}

function set_current_period_key($key) {
    if (preg_match('/^\d{4}-\d{2}$/', $key)) {
        $_SESSION['period_key'] = $key;
    }
}

function maybe_mood_gate($page) {
    $u = current_user();
    if (!$u) return;
    // CLINIC: صفحات مطب و کاربران مطب از گیت خلق‌وخوی جوما معاف‌اند
    if (strpos($page, 'clinic_') === 0) return;
    if (isset($u['role_key']) && in_array($u['role_key'], array('doctor', 'head_secretary', 'secretary', 'client'), true)) return;
    $skip = array('mood', 'logout', 'about', 'login', 'register', 'forgot', 'home', 'learn');
    if (in_array($page, $skip, true)) return;
    if (!get_mood($u['id'], jalali_today())) {
        joma_redirect('index.php?p=mood');
    }
}

function jobs_list() {
    return array('دانش‌آموز', 'دانشجو', 'کارمند', 'مدیر', 'کارآفرین', 'پزشک', 'روانشناس', 'مهندس', 'معلم', 'وکیل', 'حسابدار', 'فروشنده', 'فریلنسر', 'خانه‌دار', 'بازنشسته', 'پژوهشگر', 'مشاغل آزاد', 'سایر');
}

function categories_list() {
    return array('سلامت جسم', 'خواب و استراحت', 'سلامت روان', 'تمرکز و ذهن', 'یادگیری', 'رشد فردی', 'روابط', 'زوج درمانی', 'طرحواره درمانی', 'خانواده', 'ذهن‌آگاهی', 'مراقبت از خود', 'بهره‌وری', 'سبک زندگی');
}

function frequencies_list() {
    return array('DAILY' => 'روزانه', 'WEEKLY' => 'هفتگی', 'MONTHLY' => 'ماهانه');
}

function datatypes_list() {
    return array('DURATION' => 'مدت‌زمان', 'NUMERIC' => 'عددی', 'BOOLEAN' => 'انجام / عدم انجام', 'RATING' => 'امتیاز ۱ تا ۵');
}

function units_list() {
    return array(
        'UNIT_MIN' => 'دقیقه',
        'UNIT_HOUR' => 'ساعت',
        'UNIT_GLASS' => 'لیوان',
        'UNIT_GLA' => 'لیوان',
        'UNIT_TIMES' => 'مرتبه',
        'UNIT_TIM' => 'مرتبه',
        'UNIT_COUNT' => 'عدد',
        'UNIT_COU' => 'عدد',
        'UNIT_SCORE' => 'امتیاز',
        'UNIT_SCO' => 'امتیاز',
        'UNIT_NONE' => '—',
        'UNIT_HOU' => 'ساعت',
    );
}

/**
 * Alias (short) keys of the unit catalogue. They exist only in units_list()
 * and are never stored in real data, so they must not be offered in a
 * selector — they would show the same label twice (e.g. two "ليوان").
 */
function unit_alias_map() {
    return array(
        'UNIT_GLA' => 'UNIT_GLASS',
        'UNIT_TIM' => 'UNIT_TIMES',
        'UNIT_COU' => 'UNIT_COUNT',
        'UNIT_SCO' => 'UNIT_SCORE',
        'UNIT_HOU' => 'UNIT_HOUR',
    );
}

/** Resolve an alias key to its canonical key. */
function unit_canonical($unit) {
    $map = unit_alias_map();
    return isset($map[$unit]) ? $map[$unit] : $unit;
}

/** Canonical catalogue, alias keys removed — no duplicated labels. */
function units_primary_list() {
    $alias = unit_alias_map();
    $out = array();
    foreach (units_list() as $key => $label) {
        if (isset($alias[$key])) continue;
        $out[$key] = $label;
    }
    return $out;
}

/**
 * Official mapping data_type -> allowed units (Part 2 of the refactoring task).
 */
function unit_map_data_type() {
    return array(
        'DURATION' => array('UNIT_MIN' => 'دقیقه', 'UNIT_HOUR' => 'ساعت'),
        'NUMERIC'  => array('UNIT_COUNT' => 'عدد', 'UNIT_GLASS' => 'لیوان', 'UNIT_TIMES' => 'مرتبه'),
        'BOOLEAN'  => array('UNIT_NONE' => '—'),
        'RATING'   => array('UNIT_SCORE' => 'امتیاز'),
    );
}

/** Allowed units for one data_type (empty array for an unknown type). */
function units_for_data_type($data_type) {
    $map = unit_map_data_type();
    if (!isset($map[$data_type])) return array();
    $labels = units_list();          // labels still come from the catalogue
    $out = array();
    foreach ($map[$data_type] as $key => $label) {
        $out[$key] = isset($labels[$key]) ? $labels[$key] : $label;
    }
    return $out;
}

/** First allowed unit of a data_type = its default. */
function unit_default_for_data_type($data_type) {
    $allowed = units_for_data_type($data_type);
    if (!$allowed) return 'UNIT_NONE';
    $keys = array_keys($allowed);
    return $keys[0];
}

/** True when the unit is allowed for the data_type (alias keys accepted). */
function unit_allowed_for_data_type($unit, $data_type) {
    $allowed = units_for_data_type($data_type);
    return isset($allowed[unit_canonical($unit)]);
}

/**
 * Options for the "unit" selector of the form: the units allowed for the data
 * type, plus — when the stored row uses a unit the mapping no longer allows
 * (the seed ACT041 is BOOLEAN with UNIT_MIN) — that very unit, so opening and
 * saving the form never silently rewrites existing data.
 */
function activity_form_unit_options($data_type, $current_unit = '') {
    $opts = units_for_data_type($data_type);
    $all = units_list();
    if ($current_unit !== '' && !isset($opts[$current_unit]) && isset($all[$current_unit])) {
        $opts[$current_unit] = $all[$current_unit];
    }
    return $opts;
}

/** The 5 official importance levels (replaces the old 1..8 weight picker). */
function weights_list() {
    // Labels only — the levels 1..5 are an internal scale and are never shown
    // to the user anywhere (plan form, tables, cards or reports).
    return array(
        1 => 'عادی',
        2 => 'قابل‌توجه',
        3 => 'مهم',
        4 => 'بسیار مهم',
        5 => 'تعیین‌کننده',
    );
}

function weight_label($weight) {
    $list = weights_list();
    $w = (int) $weight;
    return isset($list[$w]) ? $list[$w] : 'عادی';
}

/** Which target column belongs to a frequency. */
function frequency_target_column($frequency) {
    if ($frequency === 'WEEKLY') return 'weekly_target';
    if ($frequency === 'MONTHLY') return 'monthly_target';
    return 'daily_target';
}

function activity_target_label($frequency) {
    if ($frequency === 'WEEKLY') return 'هدف هفتگی';
    if ($frequency === 'MONTHLY') return 'هدف ماهانه';
    return 'هدف روزانه';
}

function activity_target_hint($frequency) {
    if ($frequency === 'WEEKLY') return 'مجموعاً چه مقدار در کل هفته؟';
    if ($frequency === 'MONTHLY') return 'مجموعاً چه مقدار در کل ماه؟';
    return 'چه مقدار در هر روز؟';
}

/**
 * Target limits for one (data type, unit, frequency) combination.
 *
 * It is the single source of truth: the form uses it for labels, hints and
 * min/max attributes, and the validator uses it to reject impossible values.
 *
 * @return array hidden, label, hint, min, max, integer, step
 */
function activity_target_rule($data_type, $unit, $frequency) {
    $hint = activity_target_hint($frequency);
    $out = array(
        'hidden' => false,
        'label' => activity_target_label($frequency),
        'hint' => $hint,
        'min' => 0,
        'max' => null,
        'integer' => false,
        'step' => 'any',
    );

    // A daily checkbox is one tick per day — asking for a number makes no sense.
    if ($data_type === 'BOOLEAN') {
        if ($frequency === 'DAILY') {
            return array(
                'hidden' => true, 'label' => '', 'step' => '1',
                'hint' => 'برای کارِ تیک‌زدنیِ روزانه هدف همان «یک انجام در روز» است و سیستم عدد ۱ را ذخیره می‌کند.',
                'min' => 1, 'max' => 1, 'integer' => true,
            );
        }
        if ($frequency === 'WEEKLY') {
            return array(
                'hidden' => false, 'label' => 'چند روز در هفته؟', 'step' => '1',
                'hint' => 'مشخص کنید در طول ۷ روز هفته، این کار چند بار باید انجام شود.',
                'min' => 1, 'max' => 7, 'integer' => true,
            );
        }
        return array(
            'hidden' => false, 'label' => 'چند روز در ماه؟', 'step' => '1',
            'hint' => 'مشخص کنید در طول ماه، این کار چند بار باید انجام شود.',
            'min' => 1, 'max' => 31, 'integer' => true,
        );
    }

    if ($data_type === 'RATING') {
        $out['min'] = 1;
        $out['max'] = 5;
        $out['step'] = '0.5';
        $out['hint'] = $hint . ' امتیاز هدف باید بین ۱ تا ۵ باشد.';
        return $out;
    }

    if ($data_type === 'DURATION') {
        $caps = ($unit === 'UNIT_HOUR')
            ? array('DAILY' => 24, 'WEEKLY' => 168, 'MONTHLY' => 744)
            : array('DAILY' => 1440, 'WEEKLY' => 10080, 'MONTHLY' => 44640);
        $out['max'] = isset($caps[$frequency]) ? $caps[$frequency] : null;
        return $out;
    }

    // NUMERIC (and anything unknown): any positive number.
    return $out;
}

/**
 * Human sentence describing the limits, used by both the hint and the errors.
 */
function activity_target_rule_sentence($rule, $unit = '') {
    $units = units_list();
    $unitLabel = ($unit !== '' && isset($units[$unit])) ? $units[$unit] : '';
    if (!empty($rule['hidden'])) return '';
    if (!empty($rule['integer'])) {
        return 'فقط عدد صحیح بین ' . fa_num($rule['min']) . ' تا ' . fa_num($rule['max']) . ' مجاز است.';
    }
    if ($rule['max'] !== null) {
        return 'مقدار هدف نمی‌تواند بیشتر از ' . fa_num($rule['max'])
            . ($unitLabel !== '' ? ' ' . $unitLabel : '') . ' باشد.';
    }
    if ($rule['min'] !== null && (float) $rule['min'] > 0) {
        return 'مقدار هدف باید بین ' . fa_num($rule['min']) . ' تا ۵ باشد.';
    }
    return 'مقدار هدف باید عددی بزرگ‌تر از صفر باشد.';
}

/**
 * Single-target normalisation (Part 3 of the task).
 *
 *  - new record: the column of the selected frequency takes the value,
 *    the two others are stored as 0.00 (same pattern as the official seeds).
 *  - existing record, frequency unchanged: only that column is updated,
 *    the two others keep the value they already have.
 *  - existing record, frequency changed: the new column takes the value,
 *    the two others are reset to 0.00 (never a x7 / x30 formula).
 */
function normalize_activity_targets($frequency, $value, $existing = null) {
    $out = array('daily_target' => 0.0, 'weekly_target' => 0.0, 'monthly_target' => 0.0);
    if (is_array($existing) && isset($existing['frequency']) && $existing['frequency'] === $frequency) {
        $out['daily_target']   = (float) $existing['daily_target'];
        $out['weekly_target']  = (float) $existing['weekly_target'];
        $out['monthly_target'] = (float) $existing['monthly_target'];
    }
    $out[frequency_target_column($frequency)] = (float) $value;
    return $out;
}

/** 30.00 -> "30", 1.50 -> "1.5" (Part 6: no useless decimals in inputs). */
function clean_number($value) {
    if ($value === null || $value === '') return '';
    if (!is_numeric($value)) return $value;
    $f = (float) $value;
    if ($f == (int) $f) return (string) (int) $f;
    return rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.');
}

function stickers_list() {
    return array('🏃', '💧', '🚶', '🍎', '🌙', '⏰', '🧘', '🌬️', '📓', '🎯', '📵', '📝', '📚', '🛠️', '⭐', '💑', '🙏', '☕', '📞', '🏠', '✨', '🌸', '🛁', '🎨', '✅', '🔥', '📅', '📖', '📗', '📘', '🥗', '🍬', '🤸', '💭', '🌿', '💬', '🎧', '🖥️', '🧴', '🌇', '🔍', '🌈', '🪞', '🧭', '💗', '🗣️', '👂', '🤲', '🗨️', '🧯', '⚖️', '🩹', '🔐', '🧱', '🔁', '🧩', '💝', '💘', '🤝', '🏁', '💰', '🛡️', '📱', '💞', '💚', '☁️', '🧺', '🌨️', '🕊️', '🏡', '👤', '🌀', '💪', '💍', '💐', '📒', '🍷', '🔗', '📋', '🃏', '⏸️', '🦋', '🪑', '🧾', '📊', '🪢', '👁️', '🤍', '🎭', '🏝️', '🛟', '⚠️', '🧵', '📉', '👑', '⏳', '🙇', '🕯️', '👏', '⛈️', '🤐', '📏', '🔨');
}

function target_of($row) {
    if ($row['frequency'] === 'WEEKLY') return (float) $row['weekly_target'];
    if ($row['frequency'] === 'MONTHLY') return (float) $row['monthly_target'];
    return (float) $row['daily_target'];
}

function format_value($type, $value, $unit) {
    if ($type === 'BOOLEAN') return ((float) $value >= 1) ? 'انجام شد' : 'انجام نشد';
    if ($type === 'RATING') return 'امتیاز ' . fa_num($value);
    $u = units_list();
    $label = isset($u[$unit]) ? $u[$unit] : '';
    return fa_num($value) . ($label && $label !== '—' ? ' ' . $label : '');
}

function plan_editable($status) {
    return $status === 'DRAFT' || $status === 'PLANNING';
}

function status_label($s) {
    $m = array('DRAFT' => 'پیش‌نویس', 'PLANNING' => 'آماده‌سازی', 'RUNNING' => 'در حال اجرا', 'ARCHIVED' => 'بایگانی');
    return isset($m[$s]) ? $m[$s] : $s;
}

function status_badge($s) {
    $cls = 'badge badge-' . strtolower($s);
    return '<span class="' . e($cls) . '">' . e(status_label($s)) . '</span>';
}

function role_label($k) {
    $m = array('member' => 'عضو', 'plus' => 'پلاس', 'coach' => 'مربی', 'admin' => 'مدیر');
    return isset($m[$k]) ? $m[$k] : $k;
}

function access_label($n) {
    $m = array(1 => 'عضو', 2 => 'پلاس', 3 => 'مربی', 4 => 'مدیر');
    return isset($m[(int) $n]) ? $m[(int) $n] : (string) $n;
}

function greeting_fa() {
    $h = (int) date('G');
    if ($h < 12) return 'صبح بخیر';
    if ($h < 18) return 'وقت بخیر';
    return 'عصر بخیر';
}

function joma_contains($hay, $needle) {
    if ($needle === '' || $needle === null) return true;
    if (function_exists('mb_stripos')) {
        return mb_stripos((string) $hay, (string) $needle, 0, 'UTF-8') !== false;
    }
    return stripos((string) $hay, (string) $needle) !== false;
}

function unspecified_notice($text) {
    return '<div class="notice-unspecified">' . $text . '</div>';
}

function empty_state($title, $desc, $href = '', $cta = '') {
    $html = '<div class="empty card"><div class="empty-ico">🌱</div><h3>' . e($title) . '</h3><p>' . e($desc) . '</p>';
    if ($href !== '') {
        $html .= '<a class="btn" href="' . e($href) . '">' . e($cta) . '</a>';
    }
    $html .= '</div>';
    return $html;
}

function joma_logo($size = 56, $compact = false) {
    $candidates = array(
        dirname(__FILE__) . '/../assets/images/logo.jpg',
        dirname(__FILE__) . '/../../public/logo.jpg',
    );
    $src = '';
    foreach ($candidates as $file) {
        if (file_exists($file)) {
            if (strpos($file, '/public/') !== false) {
                $src = joma_url('../public/logo.jpg');
            } else {
                $src = joma_asset('images/logo.jpg');
            }
            break;
        }
    }
    $html = '<a class="logo" href="' . e(joma_url(current_user() ? 'index.php?p=dashboard' : 'index.php?p=home')) . '">';
    if ($src !== '') {
        $html .= '<img src="' . e($src) . '" alt="لوگوی جوما" width="' . (int) $size . '" height="' . (int) $size . '">';
    } else {
        $html .= '<span class="logo-mark" style="width:' . (int) $size . 'px;height:' . (int) $size . 'px">ج</span>';
    }
    if (!$compact) {
        $html .= '<span class="logo-txt"><strong>جوما</strong><small>برنامه. اجرا. فهم.</small></span>';
    }
    $html .= '</a>';
    return $html;
}

function flash_set($type, $msg) {
    $_SESSION['flash'] = array('type' => $type, 'msg' => $msg);
}

function flash_get() {
    if (empty($_SESSION['flash'])) return '';
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $cls = $f['type'] === 'ok' ? 'toast ok' : 'toast bad';
    return '<div class="' . $cls . '">' . e($f['msg']) . '</div>';
}

function nav_items() {
    return array(
        'dashboard' => array('داشبورد', '🏠'),
        'today' => array('امروز', '📝'),
        'mood' => array('خلق من', '💗'),
        'reports' => array('گزارش‌ها', '📊'),
        'plan' => array('برنامه من', '🗂️'),
        'periods' => array('دوره‌های من', '📅'),
        'library' => array('کتابخانه فعالیت‌ها', '📚'),
        'learn' => array('آموزش', '🎓'),
        'profile' => array('پروفایل من', '👤'),
        'settings' => array('تنظیمات', '⚙️'),
        'about' => array('درباره جوما', '🌸'),
        'support' => array('پشتیبانی', '💬'),
    );
}

function sparkline_svg($points, $w = 640, $h = 180) {
    $clean = array();
    foreach ($points as $p) $clean[] = (float) $p;
    if (!$clean) return '';
    $max = max($clean);
    $min = min($clean);
    if ($max === $min) {
        $max = $min + 1;
    }
    $n = count($clean);
    $step = $n > 1 ? ($w - 24) / ($n - 1) : 0;
    $parts = array();
    $circles = '';
    for ($i = 0; $i < $n; $i++) {
        $x = 12 + $i * $step;
        $y = 16 + ($h - 32) * (1 - (($clean[$i] - $min) / ($max - $min)));
        $parts[] = round($x, 1) . ',' . round($y, 1);
        $circles .= '<circle cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="4" fill="#7c5cbf"></circle>';
    }
    $line = implode(' ', $parts);
    return '<svg class="spark" viewBox="0 0 ' . (int) $w . ' ' . (int) $h . '" preserveAspectRatio="none" role="img" aria-label="روند">'
        . '<polyline fill="none" stroke="#c4a7e7" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" points="' . $line . '"></polyline>'
        . '<polyline fill="none" stroke="#7c5cbf" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" points="' . $line . '"></polyline>'
        . $circles . '</svg>';
}

function is_valid_username($v) {
    return (bool) preg_match('/^[a-zA-Z][a-zA-Z0-9._]{2,19}$/', trim($v));
}

function is_valid_email_addr($v) {
    return (bool) filter_var(trim($v), FILTER_VALIDATE_EMAIL);
}

function is_valid_iran_mobile($v) {
    return (bool) preg_match('/^09[0-9]{9}$/', trim($v));
}

function validate_registration($in) {
    if (trim($in['first_name']) === '' || trim($in['last_name']) === '') return 'نام و نام خانوادگی را وارد کنید.';
    if (!is_valid_username($in['username'])) return 'نام کاربری باید با حرف انگلیسی شروع شود و ۳ تا ۲۰ نویسه باشد.';
    if (username_taken($in['username'])) return 'این نام کاربری قبلاً استفاده شده است.';
    if (!is_valid_email_addr($in['email'])) return 'ایمیل معتبر نیست.';
    if (email_taken($in['email'])) return 'این ایمیل قبلاً ثبت شده است.';
    if (!is_valid_iran_mobile($in['phone'])) return 'شماره موبایل باید مانند 09123456789 باشد.';
    if (!in_array($in['job'], jobs_list(), true)) return 'شغل را از فهرست انتخاب کنید.';
    if (strlen($in['password']) < 6) return 'رمز عبور باید حداقل ۶ نویسه باشد.';
    if ($in['password'] !== $in['confirm']) return 'رمز عبور و تکرار آن یکسان نیستند.';
    if (empty($in['accept'])) return 'پذیرش قوانین برای ساخت حساب لازم است.';
    return '';
}

/**
 * Server-side validation of the library form (Part 5 of the task).
 *
 * @param array      $in      posted fields (name, category, frequency, data_type, unit, target, weight, ...)
 * @param array|null $current the stored row when editing, or null for a new record.
 *                            It lets a legacy row keep a unit that the official
 *                            mapping no longer allows (e.g. the seed ACT041 is
 *                            BOOLEAN with UNIT_MIN) instead of being rejected.
 * @return string error message, or '' when the input is valid.
 */
function validate_activity_input($in, $current = null) {
    if (!isset($in['name']) || trim($in['name']) === '') return 'نام فعالیت را وارد کنید.';

    $freqs = frequencies_list();
    if (!isset($in['frequency']) || !isset($freqs[$in['frequency']])) {
        return 'تناوب انجام باید دقیقاً یکی از این‌ها باشد: روزانه، هفتگی، ماهانه.';
    }

    $types = datatypes_list();
    if (!isset($in['data_type']) || !isset($types[$in['data_type']])) {
        return 'نوع اندازه‌گیری باید دقیقاً یکی از این‌ها باشد: مدت‌زمان، عددی، بله/خیر، امتیازی.';
    }

    $unit = isset($in['unit']) ? (string) $in['unit'] : '';
    $allUnits = units_list();
    if ($unit === '' || !isset($allUnits[$unit])) return 'واحد اندازه‌گیری انتخاب‌شده معتبر نیست.';
    $unchangedLegacy = ($current && isset($current['unit'], $current['data_type'])
        && (string) $current['unit'] === $unit
        && (string) $current['data_type'] === (string) $in['data_type']);
    if (!unit_allowed_for_data_type($unit, $in['data_type']) && !$unchangedLegacy) {
        return 'واحد انتخاب‌شده با نوع اندازه‌گیری همخوان نیست.';
    }

    $col = frequency_target_column($in['frequency']);
    $target = isset($in['target']) ? $in['target'] : (isset($in[$col]) ? $in[$col] : null);
    $rule = activity_target_rule($in['data_type'], $unit, $in['frequency']);

    // Hidden field (a daily checkbox): the system stores 1.00 by itself.
    if (!empty($rule['hidden'])) {
        $target = 1;
    } elseif ($target === null || $target === '' || !is_numeric($target) || (float) $target <= 0) {
        return 'مقدار هدف باید عددی بزرگ‌تر از صفر باشد.';
    } else {
        $t = (float) $target;
        $sentence = activity_target_rule_sentence($rule, $unit);
        if (!empty($rule['integer']) && abs($t - round($t)) > 1e-9) {
            return 'مقدار هدف باید یک عدد صحیح باشد (بدون اعشار). ' . $sentence;
        }
        if ($rule['min'] !== null && $t < (float) $rule['min']) {
            return 'مقدار هدف نمی‌تواند کمتر از ' . fa_num($rule['min']) . ' باشد. ' . $sentence;
        }
        if ($rule['max'] !== null && $t > (float) $rule['max']) {
            return $sentence;
        }
    }

    if (!isset($in['weight']) || $in['weight'] === '' || $in['weight'] === null) {
        return 'میزان اهمیت این فعالیت را انتخاب کنید.';
    }
    if (!is_numeric($in['weight']) || (float) $in['weight'] != (int) $in['weight']) {
        return 'میزان اهمیت باید یک عدد صحیح بین ۱ تا ۵ باشد.';
    }
    $w = (int) $in['weight'];
    if ($w < 1 || $w > 5) {
        return 'میزان اهمیت باید یکی از گزینه‌های عادی، قابل‌توجه، مهم، بسیار مهم یا تعیین‌کننده باشد.';
    }

    return '';
}

function validate_performance_value($type, $value) {
    if (!is_numeric($value)) return 'مقدار معتبر نیست.';
    $value = (float) $value;
    if ($type === 'RATING' && ($value < 1 || $value > 5)) return 'امتیاز باید بین ۱ و ۵ باشد.';
    if ($type === 'BOOLEAN' && $value !== 0.0 && $value !== 1.0) return 'وضعیت انجام فقط می‌تواند انجام‌شده یا نشده باشد.';
    if ($value < 0) return 'مقدار نمی‌تواند منفی باشد.';
    return '';
}
