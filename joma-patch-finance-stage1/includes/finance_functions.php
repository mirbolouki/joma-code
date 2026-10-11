<?php
/* ═══════════════════════════════════════════════════════════════════
 *  جوما — فاز ۵: مدیریت مالی (توابعِ زیرساختی)
 *
 *  این فایل زیرساختِ لازم را برای همهٔ صفحه‌های مالی فراهم می‌کند:
 *    - مراکز مشاوره (finance_centers) — با مدلِ کمیسیون و بازهٔ تسویه
 *    - حساب‌های بانکی (finance_bank_accounts)
 *    - تنظیماتِ مالیِ اتاق‌ها (اتصال به مرکز + نوع اتاق + تعرفهٔ ساعتی)
 *
 *  جدول‌های داده در database/phase5_finance_schema.sql و
 *  ارتقای جدول rooms در upgrade_phase5.php تعریف شده‌اند.
 * ═══════════════════════════════════════════════════════════════════ */

/** آیا مهاجرتِ فاز ۵ (زیرساختِ مالی) اجرا شده است؟ */
function phase5_ready($db)
{
    $res = @mysqli_query($db, "SHOW TABLES LIKE 'finance_centers'");
    return ($res && mysqli_num_rows($res) > 0);
}

/* ═══════════════════════════════════════════════════════════════════
 *  مراکز مشاوره
 * ═══════════════════════════════════════════════════════════════════ */

/** اعتبارسنجیِ فرمِ ثبت/ویرایشِ مرکز مشاوره */
function finance_center_validate($form)
{
    $errors = array();

    $name = trim(isset($form['name']) ? $form['name'] : '');
    $address = trim(isset($form['address']) ? $form['address'] : '');
    $phone = trim(to_latin_digits(isset($form['phone']) ? $form['phone'] : ''));
    $model = isset($form['commission_model']) ? $form['commission_model'] : 'PERCENTAGE';
    if (!in_array($model, array('PERCENTAGE', 'HOURLY'), true)) {
        $model = 'PERCENTAGE';
    }
    $percent_raw = trim(to_latin_digits(isset($form['commission_percent']) ? $form['commission_percent'] : ''));
    $period = isset($form['settlement_period']) ? $form['settlement_period'] : 'MONTHLY';
    if (!in_array($period, array('DAILY', 'MONTHLY'), true)) {
        $period = 'MONTHLY';
    }

    if ($name === '' || mb_strlen($name, 'UTF-8') < 2) {
        $errors['name'] = 'نام مرکز را وارد کنید (حداقل ۲ نویسه).';
    } elseif (mb_strlen($name, 'UTF-8') > 150) {
        $errors['name'] = 'نام مرکز بیش از حد طولانی است.';
    }

    if ($address !== '' && mb_strlen($address, 'UTF-8') > 255) {
        $errors['address'] = 'آدرس بیش از حد طولانی است.';
    }

    if ($phone !== '' && !preg_match('/^[0-9]{6,20}$/', $phone)) {
        $errors['phone'] = 'شمارهٔ تماس معتبر نیست.';
    }

    $percent = null;
    if ($model === 'PERCENTAGE') {
        if ($percent_raw === '' || !is_numeric($percent_raw)) {
            $errors['commission_percent'] = 'برای مرکزِ درصدی، درصد را وارد کنید (مثلاً ۳۰).';
        } else {
            $percent = (float)$percent_raw;
            if ($percent < 0 || $percent > 100) {
                $errors['commission_percent'] = 'درصد باید بین ۰ تا ۱۰۰ باشد.';
            }
        }
    }

    return array(
        'ok' => (count($errors) === 0),
        'errors' => $errors,
        'name' => $name,
        'address' => ($address === '' ? null : $address),
        'phone' => ($phone === '' ? null : $phone),
        'commission_model' => $model,
        'commission_percent' => $percent,
        'settlement_period' => $period,
    );
}

/** ثبتِ مرکزِ جدید */
function finance_center_create($db, $data, $actor_person_id, $actor_role_code)
{
    $public_id = generate_public_id('CTR');
    $res = db_execute($db,
        "INSERT INTO finance_centers
            (public_id, name, address, phone, commission_model, commission_percent,
             settlement_period, status, created_at)
         VALUES (?,?,?,?,?,?,?,?,?)",
        'sssssdsss',
        array(
            $public_id,
            $data['name'],
            $data['address'],
            $data['phone'],
            $data['commission_model'],
            $data['commission_percent'],
            $data['settlement_period'],
            'ACTIVE',
            now_dt(),
        )
    );
    $center_id = (int)$res['insert_id'];

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FINANCE_CENTER_CREATED',
        'finance_center', $center_id, array(
            'public_id' => $public_id,
            'name' => $data['name'],
            'commission_model' => $data['commission_model'],
            'settlement_period' => $data['settlement_period'],
        ));

    return $center_id;
}

/** ویرایشِ مرکز */
function finance_center_update($db, $center_id, $data, $actor_person_id, $actor_role_code)
{
    db_execute($db,
        "UPDATE finance_centers
            SET name = ?, address = ?, phone = ?, commission_model = ?,
                commission_percent = ?, settlement_period = ?
          WHERE id = ?",
        'ssssdsi',
        array(
            $data['name'],
            $data['address'],
            $data['phone'],
            $data['commission_model'],
            $data['commission_percent'],
            $data['settlement_period'],
            (int)$center_id,
        )
    );

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FINANCE_CENTER_UPDATED',
        'finance_center', (int)$center_id, array(
            'name' => $data['name'],
            'commission_model' => $data['commission_model'],
            'settlement_period' => $data['settlement_period'],
        ));

    return true;
}

/** فعال/غیرفعال‌سازیِ مرکز */
function finance_center_set_status($db, $center_id, $status, $actor_person_id, $actor_role_code)
{
    if (!in_array($status, array('ACTIVE', 'INACTIVE'), true)) {
        return false;
    }
    db_execute($db, "UPDATE finance_centers SET status = ? WHERE id = ?",
        'si', array($status, (int)$center_id));

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FINANCE_CENTER_STATUS_CHANGED',
        'finance_center', (int)$center_id, array('status' => $status));

    return true;
}

/** فهرستِ همهٔ مراکز (برای صفحهٔ مدیریت) */
function finance_centers_fetch_all($db)
{
    return db_select_all($db,
        "SELECT c.*,
                (SELECT COUNT(*) FROM rooms r WHERE r.center_id = c.id) AS rooms_count
           FROM finance_centers c
          ORDER BY c.name ASC");
}

/** فقط مراکزِ فعال (برای selectها) */
function finance_centers_fetch_active($db)
{
    return db_select_all($db,
        "SELECT id, public_id, name, commission_model, settlement_period
           FROM finance_centers
          WHERE status = 'ACTIVE'
          ORDER BY name ASC");
}

/** یافتنِ یک مرکز با id یا public_id */
function finance_center_find($db, $id_or_public)
{
    if (ctype_digit((string)$id_or_public)) {
        return db_select_one($db, "SELECT * FROM finance_centers WHERE id = ?",
            'i', array((int)$id_or_public));
    }
    return db_select_one($db, "SELECT * FROM finance_centers WHERE public_id = ?",
        's', array((string)$id_or_public));
}

/** برچسبِ مرکز برای نمایش در selectها */
function finance_center_label($center)
{
    if (!$center) {
        return '—';
    }
    $label = $center['name'];
    if (!empty($center['commission_model']) && $center['commission_model'] === 'PERCENTAGE'
        && $center['commission_percent'] !== null) {
        $label .= ' — ' . to_persian_digits($center['commission_percent']) . '٪';
    } elseif (!empty($center['commission_model']) && $center['commission_model'] === 'HOURLY') {
        $label .= ' — ساعتی';
    }
    return $label;
}

/* ═══════════════════════════════════════════════════════════════════
 *  حساب‌های بانکی
 * ═══════════════════════════════════════════════════════════════════ */

/** اعتبارسنجیِ فرمِ ثبت/ویرایشِ حسابِ بانکی */
function finance_account_validate($form)
{
    $errors = array();

    $label = trim(isset($form['label']) ? $form['label'] : '');
    $bank_name = trim(isset($form['bank_name']) ? $form['bank_name'] : '');
    $account_number = trim(to_latin_digits(isset($form['account_number']) ? $form['account_number'] : ''));
    $card_number = trim(to_latin_digits(isset($form['card_number']) ? $form['card_number'] : ''));

    if ($label === '' || mb_strlen($label, 'UTF-8') < 2) {
        $errors['label'] = 'نام/برچسب حساب را وارد کنید (مثلاً «حساب اصلی» یا «کارت-to-کارت»).';
    } elseif (mb_strlen($label, 'UTF-8') > 100) {
        $errors['label'] = 'برچسب حساب بیش از حد طولانی است.';
    }

    if ($bank_name !== '' && mb_strlen($bank_name, 'UTF-8') > 100) {
        $errors['bank_name'] = 'نام بانک بیش از حد طولانی است.';
    }

    if ($account_number !== '' && !preg_match('/^[0-9\- ]{4,40}$/', $account_number)) {
        $errors['account_number'] = 'شمارهٔ حساب معتبر نیست.';
    }

    if ($card_number !== '' && !preg_match('/^[0-9 ]{16,19}$/', $card_number)) {
        $errors['card_number'] = 'شمارهٔ کارت معتبر نیست (۱۶ رقم).';
    }

    if ($account_number === '' && $card_number === '') {
        $errors['account_number'] = 'حداقل یکی از شمارهٔ حساب یا شمارهٔ کارت را وارد کنید.';
    }

    return array(
        'ok' => (count($errors) === 0),
        'errors' => $errors,
        'label' => $label,
        'bank_name' => ($bank_name === '' ? null : $bank_name),
        'account_number' => ($account_number === '' ? null : $account_number),
        'card_number' => ($card_number === '' ? null : $card_number),
    );
}

/** ثبتِ حسابِ بانکیِ جدید */
function finance_account_create($db, $data, $actor_person_id, $actor_role_code)
{
    $public_id = generate_public_id('BNK');
    $res = db_execute($db,
        "INSERT INTO finance_bank_accounts
            (public_id, label, bank_name, account_number, card_number, status, created_at)
         VALUES (?,?,?,?,?,?,?)",
        'sssssss',
        array(
            $public_id,
            $data['label'],
            $data['bank_name'],
            $data['account_number'],
            $data['card_number'],
            'ACTIVE',
            now_dt(),
        )
    );
    $account_id = (int)$res['insert_id'];

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FINANCE_ACCOUNT_CREATED',
        'finance_bank_account', $account_id, array(
            'public_id' => $public_id,
            'label' => $data['label'],
        ));

    return $account_id;
}

/** ویرایشِ حسابِ بانکی */
function finance_account_update($db, $account_id, $data, $actor_person_id, $actor_role_code)
{
    db_execute($db,
        "UPDATE finance_bank_accounts
            SET label = ?, bank_name = ?, account_number = ?, card_number = ?
          WHERE id = ?",
        'ssssi',
        array(
            $data['label'],
            $data['bank_name'],
            $data['account_number'],
            $data['card_number'],
            (int)$account_id,
        )
    );

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FINANCE_ACCOUNT_UPDATED',
        'finance_bank_account', (int)$account_id, array('label' => $data['label']));

    return true;
}

/** فعال/غیرفعال‌سازیِ حسابِ بانکی */
function finance_account_set_status($db, $account_id, $status, $actor_person_id, $actor_role_code)
{
    if (!in_array($status, array('ACTIVE', 'INACTIVE'), true)) {
        return false;
    }
    db_execute($db, "UPDATE finance_bank_accounts SET status = ? WHERE id = ?",
        'si', array($status, (int)$account_id));

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FINANCE_ACCOUNT_STATUS_CHANGED',
        'finance_bank_account', (int)$account_id, array('status' => $status));

    return true;
}

/** فهرستِ همهٔ حساب‌های بانکی */
function finance_accounts_fetch_all($db)
{
    return db_select_all($db,
        "SELECT * FROM finance_bank_accounts ORDER BY label ASC");
}

/** فقط حساب‌های فعال (برای selectها) */
function finance_accounts_fetch_active($db)
{
    return db_select_all($db,
        "SELECT id, public_id, label, bank_name, account_number, card_number
           FROM finance_bank_accounts
          WHERE status = 'ACTIVE'
          ORDER BY label ASC");
}

/** یافتنِ یک حساب با id یا public_id */
function finance_account_find($db, $id_or_public)
{
    if (ctype_digit((string)$id_or_public)) {
        return db_select_one($db, "SELECT * FROM finance_bank_accounts WHERE id = ?",
            'i', array((int)$id_or_public));
    }
    return db_select_one($db, "SELECT * FROM finance_bank_accounts WHERE public_id = ?",
        's', array((string)$id_or_public));
}

/** برچسبِ حساب برای نمایش در selectها */
function finance_account_label($account)
{
    if (!$account) {
        return '—';
    }
    $label = $account['label'];
    if (!empty($account['card_number'])) {
        $label .= ' — کارت ' . to_persian_digits(preg_replace('/\D/', '', $account['card_number']));
    } elseif (!empty($account['account_number'])) {
        $label .= ' — حساب ' . to_persian_digits($account['account_number']);
    }
    return $label;
}

/* ═══════════════════════════════════════════════════════════════════
 *  تنظیماتِ مالیِ اتاق‌ها
 * ═══════════════════════════════════════════════════════════════════ */

/** فهرستِ اتاق‌ها به‌همراهِ تنظیماتِ مالی (مرکز، نوع، تعرفهٔ ساعتی) */
function finance_rooms_fetch($db)
{
    return db_select_all($db,
        "SELECT r.id, r.public_id, r.name, r.room_number, r.capacity, r.status,
                r.center_id, r.room_type, r.hourly_rate,
                c.name AS center_name,
                c.commission_model AS center_commission_model,
                c.settlement_period AS center_settlement_period
           FROM rooms r
           LEFT JOIN finance_centers c ON c.id = r.center_id
          ORDER BY r.name ASC");
}

/**
 * به‌روزرسانیِ تنظیماتِ مالیِ یک اتاق.
 * $center_id تهی = «اتاقِ خودِ کلینیک» (بدون مرکز).
 */
function finance_room_update_finance($db, $room_id, $center_id_raw, $room_type, $hourly_rate_raw,
                                     $actor_person_id, $actor_role_code)
{
    $room = db_select_one($db, "SELECT id, public_id, name FROM rooms WHERE id = ?",
        'i', array((int)$room_id));
    if (!$room) {
        return array('ok' => false, 'errors' => array('room' => 'اتاق یافت نشد.'));
    }

    $center_id = null;
    if ($center_id_raw !== '' && $center_id_raw !== null) {
        $center_id = (int)$center_id_raw;
        $c = db_select_one($db,
            "SELECT id FROM finance_centers WHERE id = ? AND status = 'ACTIVE'",
            'i', array($center_id));
        if (!$c) {
            return array('ok' => false, 'errors' => array('center_id' => 'مرکزِ انتخاب‌شده معتبر نیست.'));
        }
    }

    $room_type = in_array($room_type, array('SINGLE', 'COUPLE'), true) ? $room_type : null;

    $hourly_rate = null;
    if ($hourly_rate_raw !== '' && $hourly_rate_raw !== null) {
        $rate_raw = trim(to_latin_digits($hourly_rate_raw));
        if ($rate_raw === '' || !is_numeric($rate_raw)) {
            return array('ok' => false, 'errors' => array('hourly_rate' => 'تعرفهٔ ساعتی معتبر نیست.'));
        }
        $rate = (float)$rate_raw;
        if ($rate < 0) {
            return array('ok' => false, 'errors' => array('hourly_rate' => 'تعرفهٔ ساعتی نمی‌تواند منفی باشد.'));
        }
        $hourly_rate = $rate;
    }

    db_execute($db,
        "UPDATE rooms SET center_id = ?, room_type = ?, hourly_rate = ? WHERE id = ?",
        'isdi',
        array($center_id, $room_type, $hourly_rate, (int)$room_id)
    );

    audit_log_write($db, $actor_person_id, $actor_role_code, 'ROOM_FINANCE_UPDATED',
        'room', (int)$room_id, array(
            'room' => $room['name'],
            'center_id' => $center_id,
            'room_type' => $room_type,
            'hourly_rate' => $hourly_rate,
        ));

    return array('ok' => true, 'errors' => array());
}

/* ═══════════════════════════════════════════════════════════════════
 *  قالب‌های نمایشی
 * ═══════════════════════════════════════════════════════════════════ */

/** قالبِ مبلغ با جداکنندهٔ هزارگان و پسوند «تومان» */
function finance_format_amount($amount)
{
    $amount = (float)$amount;
    return to_persian_digits(number_format($amount, 0)) . ' تومان';
}

/** برچسبِ نوع اتاق */
function finance_room_type_label($room_type)
{
    if ($room_type === 'COUPLE') {
        return 'اتاق زوج/خانواده';
    }
    if ($room_type === 'SINGLE') {
        return 'اتاق تکی';
    }
    return '—';
}

/** برچسبِ مدل کمیسیون */
function finance_commission_model_label($model)
{
    if ($model === 'HOURLY') {
        return 'ساعتی';
    }
    return 'درصدی';
}

/** برچسبِ بازهٔ تسویه */
function finance_settlement_period_label($period)
{
    if ($period === 'DAILY') {
        return 'روزانه';
    }
    return 'ماهیانه';
}
