<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — اعتبارسنجی ورودی‌ها
 * ═══════════════════════════════════════════════════════════════════ */

/** نام و نام خانوادگی: ۲ تا ۱۰۰ نویسهٔ فارسی */
function person_name_valid($name)
{
    $name = trim((string)$name);
    $len = mb_strlen($name, 'UTF-8');
    if ($len < 2 || $len > 100) {
        return false;
    }
    return (bool)preg_match('/^[\x{0600}-\x{06FF}\s\x{200C}]+$/u', $name);
}

/** شمارهٔ موبایل ایران */
function mobile_number_valid($mobile)
{
    return (bool)preg_match('/^09\d{9}$/', (string)$mobile);
}

/** نام کاربری: حروف کوچک انگلیسی، عدد و زیرخط */
function username_valid($username)
{
    return (bool)preg_match('/^[a-z0-9_]{4,50}$/', (string)$username);
}

/** رمز عبور: حداقل ۸ نویسه، شامل حرف و عدد */
function password_strength_valid($password)
{
    return (bool)preg_match('/^(?=.*[A-Za-z])(?=.*\d).{8,}$/', (string)$password);
}

/** کد ملی اختیاری است؛ اگر وارد شد باید ۱۰ رقم و از نظر ریاضی معتبر باشد */
function national_code_valid($code)
{
    $code = to_latin_digits($code);
    $code = preg_replace('/\D/', '', (string)$code);
    if ($code === '') {
        return true; /* اختیاری */
    }
    if (!preg_match('/^\d{10}$/', $code)) {
        return false;
    }
    if (preg_match('/^(\d)\1{9}$/', $code)) {
        return false;
    }
    $sum = 0;
    for ($i = 0; $i < 9; $i++) {
        $sum += ((int)$code[$i]) * (10 - $i);
    }
    $remainder = $sum % 11;
    $check = (int)$code[9];
    return ($remainder < 2) ? ($check === $remainder) : ($check === (11 - $remainder));
}

/** اعتبارسنجی اطلاعات پایهٔ یک شخص */
function person_intake_validate($data)
{
    $errors = array();

    if (!person_name_valid(isset($data['first_name']) ? $data['first_name'] : '')) {
        $errors['first_name'] = 'نام را به‌درستی و فقط با حروف فارسی وارد کنید.';
    }
    if (!person_name_valid(isset($data['last_name']) ? $data['last_name'] : '')) {
        $errors['last_name'] = 'نام خانوادگی را به‌درستی و فقط با حروف فارسی وارد کنید.';
    }

    $mobile = normalize_mobile_number(isset($data['mobile_number']) ? $data['mobile_number'] : '');
    if (!mobile_number_valid($mobile)) {
        $errors['mobile_number'] = 'شمارهٔ موبایل معتبر نیست. قالب درست: 09xxxxxxxxx';
    }

    $national_code = isset($data['national_code']) ? to_latin_digits($data['national_code']) : '';
    $national_code = preg_replace('/\D/', '', (string)$national_code);
    if (!national_code_valid($national_code)) {
        $errors['national_code'] = 'کد ملی وارد‌شده معتبر نیست. (وارد‌کردن آن اختیاری است)';
    }

    return array(
        'ok' => empty($errors),
        'errors' => $errors,
        'mobile' => $mobile,
        'national_code' => ($national_code === '' ? null : $national_code),
    );
}

/** اعتبارسنجی فرم ساخت کاربر پرسنلی */
function staff_create_validate($data)
{
    $errors = array();

    $person_check = person_intake_validate($data);
    if (!$person_check['ok']) {
        $errors = array_merge($errors, $person_check['errors']);
    }
    if (!username_valid(isset($data['login_identifier']) ? $data['login_identifier'] : '')) {
        $errors['login_identifier'] = 'نام کاربری فقط می‌تواند شامل حروف کوچک انگلیسی، عدد و زیرخط باشد (حداقل ۴ نویسه).';
    }
    if (!password_strength_valid(isset($data['password']) ? $data['password'] : '')) {
        $errors['password'] = 'رمز عبور باید حداقل ۸ نویسه و شامل حرف و عدد باشد.';
    }
    if (empty($data['role_codes']) || !is_array($data['role_codes'])) {
        $errors['role_codes'] = 'حداقل یک نقش باید انتخاب شود.';
    }

    return array(
        'ok' => empty($errors),
        'errors' => $errors,
        'mobile' => $person_check['mobile'],
        'national_code' => $person_check['national_code'],
    );
}
