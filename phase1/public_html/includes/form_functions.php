<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۴: هستهٔ مشترک فرم‌ها
 *
 *  این فایل قواعدی را نگه می‌دارد که همهٔ لایه‌های دیگر فرم به آن تکیه
 *  می‌کنند: ثابت‌ها، انواع فیلد، کدگذاری گزینه‌ها و اعتبارسنجی مقدار.
 *
 *  قاعدهٔ طلایی فاز ۴ (P5/P7):
 *      فرم  = سند پرونده  → درمانگر مسئولِ فعلی می‌بیند
 *      یادداشت = سند نویسنده → فقط نویسنده (فاز ۳، دست‌نخورده)
 * ═══════════════════════════════════════════════════════════════════ */

define('FORM_MAX_FIELDS', 50);
define('FORM_EDIT_WINDOW_HOURS', 24);
define('FORM_TEXT_DEFAULT_MAX', 2000);
define('FORM_TEXT_ABSOLUTE_MAX', 5000);
define('FORM_DRAFT_RETENTION_DAYS', 30);
define('FORM_DRAFT_SWEEP_LIMIT', 50);
define('FORM_RETRACTION_LIST', 'form_retraction_reason');
define('FORM_INTAKE_CODE', 'patient_intake');

/** پیام یکسان برای «نبودن» و «مال شما نبودن» — درس نقص D4 فاز ۳ */
define('FORM_DENY_MESSAGE', 'فرم یافت نشد یا شما اجازهٔ دسترسی به آن را ندارید.');

/** جدول‌های فاز ۴ نصب شده‌اند؟ */
function phase4_ready($db)
{
    /* فقط پاسخ مثبت نگه داشته می‌شود؛ اگر در همین درخواست ارتقا اجرا شود
       (upgrade_phase4.php)، پاسخ منفیِ کهنه نباید باقی بماند. */
    static $ready = false;
    if ($ready) {
        return true;
    }
    $res = @mysqli_query($db, "SHOW TABLES LIKE 'form_templates'");
    if ($res) {
        $ready = (mysqli_num_rows($res) > 0);
        mysqli_free_result($res);
    }
    return $ready;
}

/* ───────────────────────── انواع فیلد ───────────────────────── */

function form_field_types()
{
    return array(
        'SINGLE_CHOICE'    => 'تک‌انتخابی',
        'MULTI_CHOICE'     => 'چندانتخابی',
        'YES_NO'           => 'بله / خیر',
        'SCALE'            => 'مقیاس عددی',
        'NUMBER'           => 'عدد',
        'DATE'             => 'تاریخ شمسی',
        'DESCRIPTIVE_TEXT' => 'متن توضیحی (آزاد)',
    );
}

function form_field_type_label($type)
{
    $all = form_field_types();
    return isset($all[$type]) ? $all[$type] : $type;
}

function form_field_type_valid($type)
{
    $all = form_field_types();
    return isset($all[$type]);
}

/** آیا این نوع فیلد فهرست گزینه لازم دارد؟ */
function form_field_needs_options($type)
{
    return ($type === 'SINGLE_CHOICE' || $type === 'MULTI_CHOICE');
}

/** آیا این نوع، بازهٔ عددی دارد؟ */
function form_field_has_range($type)
{
    return ($type === 'NUMBER' || $type === 'SCALE');
}

/* ───────────────────────── گزینه‌ها ───────────────────────── */

/**
 * گزینه‌ها در ستون TEXT به‌صورت JSON نگهداری می‌شوند:
 *   [ {"code":"single","label":"مجرد"}, ... ]
 * نوع ستونی JSON عمداً استفاده نشده (سازگاری MySQL قدیمی).
 */
function form_options_decode($options_text)
{
    if ($options_text === null || trim((string)$options_text) === '') {
        return array();
    }
    $data = json_decode((string)$options_text, true);
    if (!is_array($data)) {
        return array();
    }
    $out = array();
    foreach ($data as $row) {
        if (is_array($row) && isset($row['code']) && isset($row['label'])) {
            $out[] = array('code' => (string)$row['code'], 'label' => (string)$row['label']);
        }
    }
    return $out;
}

function form_options_encode($options)
{
    return json_encode(array_values($options), JSON_UNESCAPED_UNICODE);
}

/** برچسب یک گزینه بر پایهٔ کد آن */
function form_option_label($options, $code)
{
    foreach ($options as $o) {
        if ($o['code'] === (string)$code) {
            return $o['label'];
        }
    }
    return (string)$code;
}

/**
 * متن چندخطی کاربر («مجرد» در هر خط) را به فهرست گزینه تبدیل می‌کند.
 * کد هر گزینه از روی ترتیب ساخته می‌شود تا با تغییر برچسب نشکند.
 * @return array('ok'=>bool,'options'=>array,'error'=>string)
 */
function form_options_parse_input($raw, $existing = array())
{
    $lines = preg_split('/\r\n|\r|\n/', (string)$raw);
    $options = array();
    $seen = array();
    $index = 0;

    foreach ($lines as $line) {
        $label = trim($line);
        if ($label === '') {
            continue;
        }
        if (mb_strlen($label) > 150) {
            return array('ok' => false, 'options' => array(),
                'error' => 'هر گزینه حداکثر ۱۵۰ نویسه می‌تواند باشد.');
        }
        /* اگر برچسب از قبل وجود داشته، کدش حفظ می‌شود */
        $code = null;
        foreach ($existing as $o) {
            if ($o['label'] === $label) {
                $code = $o['code'];
                break;
            }
        }
        if ($code === null) {
            $index++;
            do {
                $code = 'o' . $index;
                $index++;
            } while (isset($seen[$code]));
        }
        if (isset($seen[$code])) {
            continue;
        }
        $seen[$code] = true;
        $options[] = array('code' => $code, 'label' => $label);
    }

    if (count($options) < 2) {
        return array('ok' => false, 'options' => array(),
            'error' => 'برای این نوع فیلد دست‌کم دو گزینه لازم است (هر گزینه در یک خط).');
    }
    if (count($options) > 30) {
        return array('ok' => false, 'options' => array(),
            'error' => 'حداکثر ۳۰ گزینه برای هر فیلد مجاز است.');
    }
    return array('ok' => true, 'options' => $options, 'error' => '');
}

function form_options_to_textarea($options)
{
    $lines = array();
    foreach ($options as $o) {
        $lines[] = $o['label'];
    }
    return implode("\n", $lines);
}

/* ───────────────── اعتبارسنجی مقدار یک فیلد ───────────────── */

/**
 * مقدار خام ورودی کاربر را برای یک فیلد می‌سنجد و شکل ذخیره‌شدنی را
 * برمی‌گرداند. **همیشه سمت سرور اجرا می‌شود**؛ JavaScript فقط کمک‌حال است.
 *
 * @return array('ok'=>bool, 'value'=>string|null, 'error'=>string)
 */
function form_value_validate($field, $raw)
{
    $type = $field['field_type'];
    $required = ((int)$field['is_required'] === 1);
    $label = $field['label'];

    /* چندانتخابی آرایه می‌گیرد، بقیه رشته */
    if ($type === 'MULTI_CHOICE') {
        $selected = is_array($raw) ? $raw : array();
        $options = form_options_decode($field['options_text']);
        $valid_codes = array();
        foreach ($options as $o) { $valid_codes[] = $o['code']; }

        $clean = array();
        foreach ($selected as $code) {
            $code = (string)$code;
            if (in_array($code, $valid_codes, true) && !in_array($code, $clean, true)) {
                $clean[] = $code;
            }
        }
        if (count($clean) === 0) {
            if ($required) {
                return array('ok' => false, 'value' => null,
                    'error' => 'برای «' . $label . '» دست‌کم یک گزینه انتخاب کنید.');
            }
            return array('ok' => true, 'value' => null, 'error' => '');
        }
        return array('ok' => true, 'value' => json_encode($clean, JSON_UNESCAPED_UNICODE), 'error' => '');
    }

    $value = is_array($raw) ? '' : trim((string)$raw);

    if ($value === '') {
        if ($required) {
            return array('ok' => false, 'value' => null,
                'error' => 'پاسخ «' . $label . '» الزامی است.');
        }
        return array('ok' => true, 'value' => null, 'error' => '');
    }

    switch ($type) {
        case 'NUMBER':
        case 'SCALE':
            $num = to_latin_digits($value);
            $num = str_replace(array('٫', ','), array('.', ''), $num);
            if (!is_numeric($num)) {
                return array('ok' => false, 'value' => null,
                    'error' => 'پاسخ «' . $label . '» باید عدد باشد.');
            }
            $num_f = (float)$num;
            if ($field['min_value'] !== null && $num_f < (float)$field['min_value']) {
                return array('ok' => false, 'value' => null,
                    'error' => 'پاسخ «' . $label . '» نباید کمتر از '
                        . to_persian_digits(form_number_display($field['min_value'])) . ' باشد.');
            }
            if ($field['max_value'] !== null && $num_f > (float)$field['max_value']) {
                return array('ok' => false, 'value' => null,
                    'error' => 'پاسخ «' . $label . '» نباید بیشتر از '
                        . to_persian_digits(form_number_display($field['max_value'])) . ' باشد.');
            }
            if ($type === 'SCALE' && floor($num_f) != $num_f) {
                return array('ok' => false, 'value' => null,
                    'error' => 'پاسخ «' . $label . '» باید عدد صحیح باشد.');
            }
            return array('ok' => true, 'value' => (string)$num, 'error' => '');

        case 'DATE':
            $g = jalali_input_to_gregorian($value);
            if ($g === null) {
                return array('ok' => false, 'value' => null,
                    'error' => 'تاریخ «' . $label . '» معتبر نیست. نمونهٔ درست: ۱۴۰۵/۰۷/۱۳');
            }
            return array('ok' => true, 'value' => $g, 'error' => '');

        case 'YES_NO':
            $v = to_latin_digits($value);
            if ($v !== '0' && $v !== '1') {
                return array('ok' => false, 'value' => null,
                    'error' => 'پاسخ «' . $label . '» باید «بله» یا «خیر» باشد.');
            }
            return array('ok' => true, 'value' => $v, 'error' => '');

        case 'SINGLE_CHOICE':
            $options = form_options_decode($field['options_text']);
            foreach ($options as $o) {
                if ($o['code'] === $value) {
                    return array('ok' => true, 'value' => $value, 'error' => '');
                }
            }
            return array('ok' => false, 'value' => null,
                'error' => 'گزینهٔ انتخاب‌شده برای «' . $label . '» معتبر نیست.');

        case 'DESCRIPTIVE_TEXT':
            $max = $field['max_length'] !== null ? (int)$field['max_length'] : FORM_TEXT_DEFAULT_MAX;
            if ($max > FORM_TEXT_ABSOLUTE_MAX) { $max = FORM_TEXT_ABSOLUTE_MAX; }
            if (mb_strlen($value) > $max) {
                return array('ok' => false, 'value' => null,
                    'error' => 'پاسخ «' . $label . '» از ' . to_persian_digits($max) . ' نویسه بیشتر است.');
            }
            return array('ok' => true, 'value' => $value, 'error' => '');
    }

    return array('ok' => false, 'value' => null, 'error' => 'نوع فیلد ناشناخته است.');
}

/** عدد DECIMAL را بدون صفرهای اضافی نشان می‌دهد */
function form_number_display($value)
{
    if ($value === null) { return ''; }
    $f = (float)$value;
    if (floor($f) == $f) {
        return (string)(int)$f;
    }
    return rtrim(rtrim((string)$f, '0'), '.');
}

/** مقدار ذخیره‌شده را برای نمایش به انسان تبدیل می‌کند */
function form_value_display($field, $value_text)
{
    if ($value_text === null || $value_text === '') {
        return '—';
    }
    switch ($field['field_type']) {
        case 'YES_NO':
            return ($value_text === '1') ? 'بله' : 'خیر';
        case 'DATE':
            return gregorian_to_jalali_input($value_text);
        case 'NUMBER':
        case 'SCALE':
            return to_persian_digits(form_number_display($value_text));
        case 'SINGLE_CHOICE':
            return form_option_label(form_options_decode($field['options_text']), $value_text);
        case 'MULTI_CHOICE':
            $codes = json_decode($value_text, true);
            if (!is_array($codes) || count($codes) === 0) { return '—'; }
            $options = form_options_decode($field['options_text']);
            $labels = array();
            foreach ($codes as $c) { $labels[] = form_option_label($options, $c); }
            return implode('، ', $labels);
    }
    return $value_text;
}

/* ───────────────── پنجرهٔ ویرایش ───────────────── */

function form_seconds_since($datetime_utc)
{
    return time() - strtotime($datetime_utc . ' UTC');
}

/** آیا این ثبت هنوز در پنجرهٔ ۲۴ ساعته است؟ */
function form_submission_in_edit_window($submission)
{
    if ($submission['status'] !== 'ACTIVE') {
        return false;
    }
    return form_seconds_since($submission['submitted_at']) < (FORM_EDIT_WINDOW_HOURS * 3600);
}

/** متن «۳ ساعت و ۱۲ دقیقه» از مهلت باقی‌مانده، یا null */
function form_edit_window_left($submission)
{
    $left = (FORM_EDIT_WINDOW_HOURS * 3600) - form_seconds_since($submission['submitted_at']);
    if ($left <= 0) {
        return null;
    }
    $hours = (int)floor($left / 3600);
    $minutes = (int)floor(($left % 3600) / 60);
    if ($hours > 0) {
        return to_persian_digits($hours) . ' ساعت و ' . to_persian_digits($minutes) . ' دقیقه';
    }
    return to_persian_digits($minutes) . ' دقیقه';
}

/* ───────────────── برچسب‌های وضعیت ───────────────── */

function form_assignment_status_label($status)
{
    switch ($status) {
        case 'PENDING':   return 'در انتظار تکمیل';
        case 'SUBMITTED': return 'ثبت‌شده';
        case 'RETRACTED': return 'باطل‌شده';
        case 'CANCELLED': return 'لغوشده';
    }
    return $status;
}

function form_assignment_status_class($status)
{
    switch ($status) {
        case 'PENDING':   return 'badge-warning';
        case 'SUBMITTED': return 'badge-success';
        case 'RETRACTED': return 'badge-muted';
        case 'CANCELLED': return 'badge-muted';
    }
    return 'badge-muted';
}

function form_template_status_label($status)
{
    switch ($status) {
        case 'DRAFT':    return 'پیش‌نویس';
        case 'ACTIVE':   return 'فعال';
        case 'ARCHIVED': return 'بایگانی‌شده';
    }
    return $status;
}

function form_assignee_role_label($role)
{
    return ($role === 'PATIENT') ? 'مراجع' : 'درمانگر';
}
