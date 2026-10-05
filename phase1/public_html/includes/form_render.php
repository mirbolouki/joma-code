<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۴: نمایش فرم‌ها (ورودی و فقط‌خواندنی)
 *
 *  همان نشانه‌گذاری و کلاس‌های CSS فازهای پیشین استفاده می‌شود؛
 *  هیچ واژگان تازه‌ای جز .form-field* و .form-scale* اضافه نشده است.
 *
 *  نکته: این لایه هیچ تصمیم امنیتی نمی‌گیرد. هر خروجی از e() می‌گذرد.
 * ═══════════════════════════════════════════════════════════════════ */

/** مقدار ذخیره‌شده را به شکل «ورودی فرم» برمی‌گرداند (برای ویرایش) */
function form_value_to_input($field, $value_text)
{
    if ($value_text === null || $value_text === '') {
        return ($field['field_type'] === 'MULTI_CHOICE') ? array() : '';
    }
    switch ($field['field_type']) {
        case 'MULTI_CHOICE':
            $codes = json_decode($value_text, true);
            return is_array($codes) ? array_map('strval', $codes) : array();
        case 'DATE':
            return gregorian_to_jalali_input($value_text);
    }
    return (string)$value_text;
}

/** نگاشت مقادیر ذخیره‌شده به ورودی‌های فرم */
function form_values_to_inputs($fields, $value_map)
{
    $out = array();
    foreach ($fields as $field) {
        $fid = (int)$field['id'];
        if (array_key_exists($fid, $value_map)) {
            $out[$fid] = form_value_to_input($field, $value_map[$fid]);
        }
    }
    return $out;
}

/** حداکثر نویسهٔ مجاز یک فیلد متنی */
function form_field_max_length($field)
{
    $max = ($field['max_length'] !== null) ? (int)$field['max_length'] : FORM_TEXT_DEFAULT_MAX;
    if ($max > FORM_TEXT_ABSOLUTE_MAX) { $max = FORM_TEXT_ABSOLUTE_MAX; }
    if ($max < 1) { $max = FORM_TEXT_DEFAULT_MAX; }
    return $max;
}

/**
 * یک پرسش را به‌صورت ورودیِ قابل پرکردن چاپ می‌کند.
 *
 * @param array        $field
 * @param string|array $value  مقدار خام (رشته یا آرایه برای چندانتخابی)
 * @param string|null  $error  پیام خطای همین فیلد
 * @param int          $index  شمارهٔ ترتیبی برای نمایش
 */
function form_render_field($field, $value, $error, $index)
{
    $name = 'f_' . $field['public_id'];
    $id = 'field_' . $field['public_id'];
    $type = $field['field_type'];
    $required = ((int)$field['is_required'] === 1);
    $has_error = ($error !== null && $error !== '');

    if ($type === 'MULTI_CHOICE') {
        $value = is_array($value) ? array_map('strval', $value) : array();
    } else {
        $value = is_array($value) ? '' : (string)$value;
    }

    echo '<div class="form-row form-field' . ($has_error ? ' is-invalid' : '') . '">';
    echo '<label for="' . e($id) . '">';
    echo e(to_persian_digits($index) . '. ' . $field['label']);
    if ($required) {
        echo ' <span class="required-star">*</span>';
    }
    echo '</label>';

    if ($field['help_text'] !== null && $field['help_text'] !== '') {
        echo '<div class="form-hint">' . e($field['help_text']) . '</div>';
    }

    switch ($type) {
        case 'YES_NO':
            $yn = array('1' => 'بله', '0' => 'خیر');
            echo '<div class="checkbox-list">';
            foreach ($yn as $code => $label) {
                $checked = ($value === (string)$code) ? ' checked' : '';
                echo '<label class="checkbox-label">';
                echo '<input type="radio" name="' . e($name) . '" value="' . e($code) . '"'
                    . $checked . '> ' . e($label);
                echo '</label>';
            }
            echo '</div>';
            break;

        case 'SINGLE_CHOICE':
            echo '<div class="checkbox-list">';
            foreach (form_options_decode($field['options_text']) as $opt) {
                $checked = ($value === $opt['code']) ? ' checked' : '';
                echo '<label class="checkbox-label">';
                echo '<input type="radio" name="' . e($name) . '" value="' . e($opt['code']) . '"'
                    . $checked . '> ' . e($opt['label']);
                echo '</label>';
            }
            echo '</div>';
            break;

        case 'MULTI_CHOICE':
            echo '<div class="checkbox-list">';
            foreach (form_options_decode($field['options_text']) as $opt) {
                $checked = in_array($opt['code'], $value, true) ? ' checked' : '';
                echo '<label class="checkbox-label">';
                echo '<input type="checkbox" name="' . e($name) . '[]" value="'
                    . e($opt['code']) . '"' . $checked . '> ' . e($opt['label']);
                echo '</label>';
            }
            echo '</div>';
            break;

        case 'SCALE':
            $min = ($field['min_value'] !== null) ? (int)$field['min_value'] : 0;
            $max = ($field['max_value'] !== null) ? (int)$field['max_value'] : 10;
            if ($max - $min <= 20) {
                echo '<div class="form-scale">';
                for ($i = $min; $i <= $max; $i++) {
                    $checked = ($value !== '' && (int)to_latin_digits($value) === $i
                        && is_numeric(to_latin_digits($value))) ? ' checked' : '';
                    echo '<label class="form-scale-step">';
                    echo '<input type="radio" name="' . e($name) . '" value="' . $i . '"'
                        . $checked . '>';
                    echo '<span>' . e(to_persian_digits($i)) . '</span>';
                    echo '</label>';
                }
                echo '</div>';
            } else {
                echo '<input type="text" inputmode="numeric" dir="ltr" id="' . e($id) . '"'
                    . ' name="' . e($name) . '" data-digits="en" value="'
                    . e(to_latin_digits($value)) . '">';
            }
            break;

        case 'NUMBER':
            echo '<input type="text" inputmode="decimal" dir="ltr" id="' . e($id) . '"'
                . ' name="' . e($name) . '" data-digits="en" value="'
                . e(to_latin_digits($value)) . '">';
            $range = array();
            if ($field['min_value'] !== null) {
                $range[] = 'کمینه ' . to_persian_digits(form_number_display($field['min_value']));
            }
            if ($field['max_value'] !== null) {
                $range[] = 'بیشینه ' . to_persian_digits(form_number_display($field['max_value']));
            }
            if (count($range) > 0) {
                echo '<div class="form-hint">' . e(implode(' — ', $range)) . '</div>';
            }
            break;

        case 'DATE':
            echo '<input type="text" dir="ltr" id="' . e($id) . '" name="' . e($name) . '"'
                . ' placeholder="۱۴۰۵/۰۷/۱۳" value="' . e($value) . '">';
            echo '<div class="form-hint">تاریخ شمسی، به شکل سال/ماه/روز.</div>';
            break;

        case 'DESCRIPTIVE_TEXT':
            $max = form_field_max_length($field);
            $counter_id = 'counter_' . $field['public_id'];
            echo '<textarea id="' . e($id) . '" name="' . e($name) . '" rows="5"'
                . ' class="note-textarea" data-maxchars="' . (int)$max . '"'
                . ' data-counter="' . e($counter_id) . '">' . e($value) . '</textarea>';
            echo '<div class="form-hint">نویسه‌های استفاده‌شده: '
                . '<span id="' . e($counter_id) . '" class="note-counter">۰</span> از '
                . e(to_persian_digits($max)) . '</div>';
            break;

        default:
            echo '<div class="alert alert-warning">نوع این پرسش پشتیبانی نمی‌شود.</div>';
    }

    if ($has_error) {
        echo '<div class="form-error">' . e($error) . '</div>';
    }
    echo '</div>';
}

/**
 * کل فرم قابل پرکردن.
 *
 * @param array $inputs نگاشت field_id ⇐ مقدار خام
 * @param array $errors نگاشت field_id ⇐ پیام خطا
 */
function form_render_fields($fields, $inputs, $errors)
{
    $i = 1;
    foreach ($fields as $field) {
        $fid = (int)$field['id'];
        $value = array_key_exists($fid, $inputs) ? $inputs[$fid]
            : (($field['field_type'] === 'MULTI_CHOICE') ? array() : '');
        $error = isset($errors[$fid]) ? $errors[$fid] : null;
        form_render_field($field, $value, $error, $i);
        $i++;
    }
}

/**
 * نمایش فقط‌خواندنی پاسخ‌ها.
 * سطرهای form_submission_values_* را می‌گیرد (برچسب فیلد با خودشان است).
 */
function form_render_answers($value_rows)
{
    if (count($value_rows) === 0) {
        echo '<p class="empty-state">پاسخی ثبت نشده است.</p>';
        return;
    }
    echo '<div class="item-list form-answers">';
    $i = 1;
    foreach ($value_rows as $row) {
        echo '<div class="item-card">';
        echo '<div class="item-card-main">';
        echo '<div class="item-card-title">'
            . e(to_persian_digits($i) . '. ' . $row['label']);
        if (isset($row['is_active']) && (int)$row['is_active'] === 0) {
            echo ' <span class="badge badge-muted">حذف‌شده از قالب</span>';
        }
        echo '</div>';
        echo '<div class="form-answer-value">'
            . nl2br(e(form_value_display($row, $row['value_text']))) . '</div>';
        echo '</div>';
        echo '</div>';
        $i++;
    }
    echo '</div>';
}

/** مقایسهٔ دو بازنگری: برمی‌گرداند کدام فیلدها عوض شده‌اند */
function form_revision_diff($older_rows, $newer_rows)
{
    $old = array();
    foreach ($older_rows as $r) {
        $old[(int)$r['field_id']] = (string)$r['value_text'];
    }
    $changed = array();
    foreach ($newer_rows as $r) {
        $fid = (int)$r['field_id'];
        $before = array_key_exists($fid, $old) ? $old[$fid] : null;
        if ($before !== (string)$r['value_text']) {
            $changed[] = array(
                'field' => $r,
                'before' => $before,
                'after' => $r['value_text'],
            );
        }
    }
    return $changed;
}
