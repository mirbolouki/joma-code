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

/* ═══════════════════════════════════════════════════════════════════
 *  مرحلهٔ ۲ — پرداختِ جلسات (بیعانه + الباقی)
 * ═══════════════════════════════════════════════════════════════════ */

/** آیا مهاجرتِ مرحلهٔ ۲ (پرداختِ جلسات) اجرا شده است؟ */
function phase5_2_ready($db)
{
    $res = @mysqli_query($db, "SHOW TABLES LIKE 'finance_session_payments'");
    return ($res && mysqli_num_rows($res) > 0);
}

/** تبدیلِ ورودیِ «تومان» (با ارقام فارسی یا لاتین) به ریال (عدد صحیح) */
function finance_toman_input_to_rial($toman_str)
{
    $raw = trim(to_latin_digits((string)$toman_str));
    if ($raw === '') {
        return null;
    }
    if (!is_numeric($raw)) {
        return null;
    }
    $toman = (float)$raw;
    if ($toman < 0) {
        return null;
    }
    return (int)round($toman * 10);
}

/**
 * اعتبارسنجیِ فرمِ پرداختِ جلسه.
 * مبالغِ ورودی به «تومان» هستند؛ خروجی به «ریال» تبدیل می‌شود.
 * قانونِ اصلی: اگر وضعیتی «پرداخت‌شده» است، مبلغِ بزرگ‌تر از صفر و
 * حسابِ بانکیِ واریزشده الزامی است.
 */
function finance_payment_validate($form)
{
    $errors = array();

    $deposit_amount_rial = finance_toman_input_to_rial(isset($form['deposit_amount']) ? $form['deposit_amount'] : '');
    $deposit_account_raw = isset($form['deposit_bank_account_id']) ? trim((string)$form['deposit_bank_account_id']) : '';
    $deposit_account_id = ($deposit_account_raw === '') ? null : (int)$deposit_account_raw;
    $deposit_status = (isset($form['deposit_status']) && $form['deposit_status'] === 'PAID') ? 'PAID' : 'PENDING';

    $balance_amount_rial = finance_toman_input_to_rial(isset($form['balance_amount']) ? $form['balance_amount'] : '');
    $balance_account_raw = isset($form['balance_bank_account_id']) ? trim((string)$form['balance_bank_account_id']) : '';
    $balance_account_id = ($balance_account_raw === '') ? null : (int)$balance_account_raw;
    $balance_status = (isset($form['balance_status']) && $form['balance_status'] === 'PAID') ? 'PAID' : 'PENDING';

    $note = trim(isset($form['note']) ? $form['note'] : '');
    if (mb_strlen($note, 'UTF-8') > 255) {
        $errors['note'] = 'یادداشت بیش از حد طولانی است.';
    }

    /* بیعانه الزامی است: مدلِ نوبت‌دهیِ کلینیک این است که ثبتِ رزرو
       منوط به واریزِ بیعانه است؛ پس مبلغِ بیعانه حتماً باید وارد شود
       و وضعیتِ آن «پرداخت‌شده» باشد. */
    if ($deposit_amount_rial === null || $deposit_amount_rial <= 0) {
        $errors['deposit_amount'] = 'مبلغِ بیعانه الزامی است — ثبتِ رزرو منوط به واریزِ بیعانه است.';
    }
    if ($deposit_status !== 'PAID') {
        $errors['deposit_status'] = 'بیعانه باید «پرداخت‌شده» باشد — ثبتِ رزرو منوط به واریزِ بیعانه است.';
    }
    if ($deposit_status === 'PAID') {
        if ($deposit_amount_rial === null || $deposit_amount_rial <= 0) {
            $errors['deposit_amount'] = 'برای بیعانهٔ «پرداخت‌شده»، مبلغ را وارد کنید.';
        }
        if ($deposit_account_id === null) {
            $errors['deposit_bank_account_id'] = 'برای بیعانهٔ «پرداخت‌شده»، حسابِ بانکیِ واریزشده را انتخاب کنید.';
        }
    }
    if ($balance_status === 'PAID') {
        if ($balance_amount_rial === null || $balance_amount_rial <= 0) {
            $errors['balance_amount'] = 'برای الباقیِ «پرداخت‌شده»، مبلغ را وارد کنید.';
        }
        if ($balance_account_id === null) {
            $errors['balance_bank_account_id'] = 'برای الباقیِ «پرداخت‌شده»، حسابِ بانکیِ واریزشده را انتخاب کنید.';
        }
    }

    return array(
        'ok' => (count($errors) === 0),
        'errors' => $errors,
        'deposit_amount_rial' => $deposit_amount_rial,
        'deposit_bank_account_id' => $deposit_account_id,
        'deposit_status' => $deposit_status,
        'balance_amount_rial' => $balance_amount_rial,
        'balance_bank_account_id' => $balance_account_id,
        'balance_status' => $balance_status,
        'note' => ($note === '' ? null : $note),
    );
}

/** ثبتِ پرداختِ جلسه (بیعانه + الباقی) — یک رکورد برای هر جلسه */
function finance_payment_create($db, $appointment_id, $session_fee_rial, $data,
                                $actor_person_id, $actor_role_code)
{
    // Draft safety gate: once the new ledger is active the old two-field
    // writer becomes read-only. Existing records remain untouched.
    if (defined('FINANCE_V2_ACTIVE') && FINANCE_V2_ACTIVE === true) {
        return array('ok' => false, 'error' => 'ثبت و ویرایش در مالی قدیم غیرفعال است؛ از دفتر مالی جدید استفاده کنید.');
    }

    $appt = db_select_one($db, "SELECT id, public_id, status, price_rial FROM appointments WHERE id = ?",
        'i', array((int)$appointment_id));
    if (!$appt) {
        return array('ok' => false, 'error' => 'نوبتِ موردنظر پیدا نشد.');
    }
    if (!in_array($appt['status'], array('SCHEDULED', 'COMPLETED'), true)) {
        return array('ok' => false, 'error' => 'نوبت لغوشده یا «عدم مراجعه» است؛ پرداخت ثبت نمی‌شود.');
    }
    $existing = db_select_one($db, "SELECT id FROM finance_session_payments WHERE appointment_id = ?",
        'i', array((int)$appointment_id));
    if ($existing) {
        return array('ok' => false, 'error' => 'برای این نوبت قبلاً پرداخت ثبت شده است؛ آن را ویرایش کنید.');
    }
    /* بیعانه الزامی است — ثبتِ رزرو منوط به واریزِ بیعانه است. */
    if ($data['deposit_status'] !== 'PAID'
        || $data['deposit_amount_rial'] === null || $data['deposit_amount_rial'] <= 0) {
        return array('ok' => false, 'error' => 'بیعانه الزامی است و باید «پرداخت‌شده» باشد — ثبتِ رزرو منوط به واریزِ بیعانه است.');
    }

    $public_id = generate_public_id('PAY');
    $fee = ($session_fee_rial === null) ? (int)$appt['price_rial'] : (int)$session_fee_rial;
    if ($fee < 0) {
        $fee = 0;
    }
    $deposit_paid_at = ($data['deposit_status'] === 'PAID') ? now_dt() : null;
    $balance_paid_at = ($data['balance_status'] === 'PAID') ? now_dt() : null;

    $res = db_execute($db,
        "INSERT INTO finance_session_payments
            (public_id, appointment_id, session_fee_rial,
             deposit_amount_rial, deposit_bank_account_id, deposit_status, deposit_paid_at,
             balance_amount_rial, balance_bank_account_id, balance_status, balance_paid_at,
             recorded_by_person_id, note, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        'siidissdississ',
        array(
            $public_id,
            (int)$appointment_id,
            $fee,
            $data['deposit_amount_rial'],
            $data['deposit_bank_account_id'],
            $data['deposit_status'],
            $deposit_paid_at,
            $data['balance_amount_rial'],
            $data['balance_bank_account_id'],
            $data['balance_status'],
            $balance_paid_at,
            $actor_person_id,
            $data['note'],
            now_dt(),
        )
    );
    $payment_id = (int)$res['insert_id'];

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FINANCE_PAYMENT_CREATED',
        'finance_session_payment', $payment_id, array(
            'public_id' => $public_id,
            'appointment_id' => (int)$appointment_id,
            'session_fee_rial' => $fee,
            'deposit_status' => $data['deposit_status'],
            'balance_status' => $data['balance_status'],
        ));

    return array('ok' => true, 'payment_id' => $payment_id);
}

/** به‌روزرسانیِ پرداختِ جلسه */
function finance_payment_update($db, $payment_id, $data, $actor_person_id, $actor_role_code)
{
    // Draft safety gate: once the new ledger is active the old two-field
    // writer becomes read-only. Existing records remain untouched.
    if (defined('FINANCE_V2_ACTIVE') && FINANCE_V2_ACTIVE === true) {
        return array('ok' => false, 'error' => 'ثبت و ویرایش در مالی قدیم غیرفعال است؛ از دفتر مالی جدید استفاده کنید.');
    }

    $existing = finance_payment_find($db, (int)$payment_id);
    if (!$existing) {
        return array('ok' => false, 'error' => 'پرداختِ موردنظر پیدا نشد.');
    }

    /* اگر وضعیت از «در انتظار» به «پرداخت‌شده» تغییر کرد، تاریخ پرداخت = اکنون;
       اگر قبلاً پرداخت‌شده بود، تاریخِ اصلی حفظ می‌شود. */
    $deposit_paid_at = $existing['deposit_paid_at'];
    if ($data['deposit_status'] === 'PAID') {
        $deposit_paid_at = ($existing['deposit_status'] === 'PAID' && $existing['deposit_paid_at'])
            ? $existing['deposit_paid_at'] : now_dt();
    } else {
        $deposit_paid_at = null;
    }
    $balance_paid_at = $existing['balance_paid_at'];
    if ($data['balance_status'] === 'PAID') {
        $balance_paid_at = ($existing['balance_status'] === 'PAID' && $existing['balance_paid_at'])
            ? $existing['balance_paid_at'] : now_dt();
    } else {
        $balance_paid_at = null;
    }

    db_execute($db,
        "UPDATE finance_session_payments
            SET deposit_amount_rial = ?, deposit_bank_account_id = ?, deposit_status = ?, deposit_paid_at = ?,
                balance_amount_rial = ?, balance_bank_account_id = ?, balance_status = ?, balance_paid_at = ?,
                note = ?, updated_at = ?
          WHERE id = ?",
        'dissdissssi',
        array(
            $data['deposit_amount_rial'],
            $data['deposit_bank_account_id'],
            $data['deposit_status'],
            $deposit_paid_at,
            $data['balance_amount_rial'],
            $data['balance_bank_account_id'],
            $data['balance_status'],
            $balance_paid_at,
            $data['note'],
            now_dt(),
            (int)$payment_id,
        )
    );

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FINANCE_PAYMENT_UPDATED',
        'finance_session_payment', (int)$payment_id, array(
            'deposit_status' => $data['deposit_status'],
            'balance_status' => $data['balance_status'],
        ));

    return array('ok' => true);
}

/** یافتنِ یک پرداخت با id یا public_id */
function finance_payment_find($db, $id_or_public)
{
    if (ctype_digit((string)$id_or_public)) {
        return db_select_one($db, "SELECT * FROM finance_session_payments WHERE id = ?",
            'i', array((int)$id_or_public));
    }
    return db_select_one($db, "SELECT * FROM finance_session_payments WHERE public_id = ?",
        's', array((string)$id_or_public));
}

/** پرداختِ ثبت‌شده برای یک نوبت (یا null) */
function finance_payment_find_by_appointment($db, $appointment_id)
{
    return db_select_one($db, "SELECT * FROM finance_session_payments WHERE appointment_id = ?",
        'i', array((int)$appointment_id));
}

/** نوبتِ یک پرداخت (با JOINِ کاملِ نام‌ها) — برای نمایش در فرمِ پرداخت */
function finance_payment_appointment($db, $appointment_id)
{
    return db_select_one($db, appointment_select_sql() . " WHERE ap.id = ?",
        'i', array((int)$appointment_id));
}

/**
 * فهرستِ نوبت‌ها برای ثبت پرداخت — به‌همراهِ اطلاعاتِ پرداختِ احتمالی.
 * نوبت‌های لغوشده/عدم‌مراجعه نمایش داده نمی‌شوند.
 */
function finance_appointments_for_payment($db, $limit = 100, $only_unpaid = false)
{
    $sql = "SELECT ap.id, ap.public_id, ap.status, ap.appointment_start_utc,
                   ap.price_rial,
                   pt.first_name AS patient_first_name, pt.last_name AS patient_last_name,
                   th.first_name AS therapist_first_name, th.last_name AS therapist_last_name,
                   st.title AS service_title,
                   r.name AS room_name,
                   fsp.public_id AS payment_public_id,
                   fsp.session_fee_rial,
                   fsp.deposit_amount_rial, fsp.deposit_status, fsp.deposit_paid_at,
                   fsp.balance_amount_rial, fsp.balance_status, fsp.balance_paid_at
              FROM appointments ap
             INNER JOIN admissions ad ON ad.id = ap.admission_id
             INNER JOIN persons pt ON pt.id = ad.patient_person_id
             INNER JOIN persons th ON th.id = ap.therapist_person_id
             INNER JOIN service_types st ON st.id = ap.service_type_id
             INNER JOIN rooms r ON r.id = ap.room_id
              LEFT JOIN finance_session_payments fsp ON fsp.appointment_id = ap.id
             WHERE ap.status IN ('SCHEDULED','COMPLETED')";
    if ($only_unpaid) {
        $sql .= " AND (
                    fsp.id IS NULL
                    OR (CASE WHEN fsp.deposit_status = 'PAID' THEN COALESCE(fsp.deposit_amount_rial, 0) ELSE 0 END
                      + CASE WHEN fsp.balance_status = 'PAID' THEN COALESCE(fsp.balance_amount_rial, 0) ELSE 0 END)
                       < fsp.session_fee_rial
                 )";
    }
    $sql .= " ORDER BY ap.appointment_start_utc DESC LIMIT " . (int)$limit;
    return db_select_all($db, $sql);
}

/**
 * وضعیتِ کلیِ پرداختِ یک جلسه:
 * PAID = تمامِ مبلغِ جلسه پرداخت شده، PARTIAL = بخشی پرداخت شده، PENDING = هیچ.
 */
function finance_payment_overall($payment)
{
    $fee = (int)$payment['session_fee_rial'];
    $paid = 0;
    if ($payment['deposit_status'] === 'PAID') {
        $paid += (int)$payment['deposit_amount_rial'];
    }
    if ($payment['balance_status'] === 'PAID') {
        $paid += (int)$payment['balance_amount_rial'];
    }
    if ($paid <= 0) {
        return array('status' => 'PENDING', 'paid_rial' => 0,
            'label' => 'در انتظار پرداخت', 'class' => 'badge-muted');
    }
    if ($fee > 0 && $paid >= $fee) {
        return array('status' => 'PAID', 'paid_rial' => $paid,
            'label' => 'پرداخت‌شده', 'class' => 'badge-success');
    }
    return array('status' => 'PARTIAL', 'paid_rial' => $paid,
        'label' => 'پرداختِ جزئی', 'class' => 'badge-warning');
}

/** برچسبِ وضعیتِ یک بخش (بیعانه/الباقی) */
function finance_part_status_label($status)
{
    return ($status === 'PAID') ? 'پرداخت‌شده' : 'در انتظار';
}

/** کلاسِ badge برای وضعیتِ یک بخش */
function finance_part_status_class($status)
{
    return ($status === 'PAID') ? 'badge-success' : 'badge-muted';
}

/**
 * رندرِ بخش‌های مشترکِ فرمِ پرداخت (بیعانه + الباقی + یادداشت + دکمه).
 * هم در فرمِ ثبت و هم در فرمِ ویرایش استفاده می‌شود.
 */
function finance_render_payment_sections($form, $errors, $accounts, $submit_label)
{
    ?>
      <h3 style="grid-column:1/-1;margin:8px 0 0;">💰 بخشِ بیعانه (پیش‌پرداخت) — الزامی</h3>

      <div class="form-row">
        <label for="deposit_amount">مبلغِ بیعانه (تومان) <span class="required-star">*</span></label>
        <input type="text" id="deposit_amount" name="deposit_amount"
               value="<?php echo e($form['deposit_amount']); ?>" placeholder="مثلاً ۵۰۰۰۰۰"
               class="<?php echo isset($errors['deposit_amount']) ? 'is-invalid' : ''; ?>">
        <?php if (isset($errors['deposit_amount'])) { ?><span class="field-error"><?php echo e($errors['deposit_amount']); ?></span><?php } ?>
      </div>

      <div class="form-row">
        <label for="deposit_bank_account_id">حسابِ بانکیِ واریزِ بیعانه <span class="required-star">*</span></label>
        <select id="deposit_bank_account_id" name="deposit_bank_account_id"
                class="<?php echo isset($errors['deposit_bank_account_id']) ? 'is-invalid' : ''; ?>">
          <option value="">— انتخاب حساب —</option>
          <?php foreach ($accounts as $account) { ?>
            <option value="<?php echo (int)$account['id']; ?>"
              <?php echo ($form['deposit_bank_account_id'] === (string)(int)$account['id']) ? 'selected' : ''; ?>>
              <?php echo e(finance_account_label($account)); ?>
            </option>
          <?php } ?>
        </select>
        <?php if (isset($errors['deposit_bank_account_id'])) { ?><span class="field-error"><?php echo e($errors['deposit_bank_account_id']); ?></span><?php } ?>
      </div>

      <div class="form-row">
        <label for="deposit_status">وضعیتِ بیعانه (الزامی: «پرداخت‌شده») <span class="required-star">*</span></label>
        <select id="deposit_status" name="deposit_status"
                class="<?php echo isset($errors['deposit_status']) ? 'is-invalid' : ''; ?>">
          <option value="PAID" <?php echo $form['deposit_status'] === 'PAID' ? 'selected' : ''; ?>>پرداخت‌شده</option>
          <option value="PENDING" <?php echo $form['deposit_status'] === 'PENDING' ? 'selected' : ''; ?>>در انتظار پرداخت</option>
        </select>
        <?php if (isset($errors['deposit_status'])) { ?><span class="field-error"><?php echo e($errors['deposit_status']); ?></span><?php } ?>
      </div>

      <h3 style="grid-column:1/-1;margin:8px 0 0;">🧾 بخشِ الباقی (بخشِ دومِ وجه)</h3>

      <div class="form-row">
        <label for="balance_amount">مبلغِ الباقی (تومان)</label>
        <input type="text" id="balance_amount" name="balance_amount"
               value="<?php echo e($form['balance_amount']); ?>" placeholder="مثلاً ۱۵۰۰۰۰۰"
               class="<?php echo isset($errors['balance_amount']) ? 'is-invalid' : ''; ?>">
        <?php if (isset($errors['balance_amount'])) { ?><span class="field-error"><?php echo e($errors['balance_amount']); ?></span><?php } ?>
      </div>

      <div class="form-row">
        <label for="balance_bank_account_id">حسابِ بانکیِ واریزِ الباقی</label>
        <select id="balance_bank_account_id" name="balance_bank_account_id"
                class="<?php echo isset($errors['balance_bank_account_id']) ? 'is-invalid' : ''; ?>">
          <option value="">— انتخاب حساب —</option>
          <?php foreach ($accounts as $account) { ?>
            <option value="<?php echo (int)$account['id']; ?>"
              <?php echo ($form['balance_bank_account_id'] === (string)(int)$account['id']) ? 'selected' : ''; ?>>
              <?php echo e(finance_account_label($account)); ?>
            </option>
          <?php } ?>
        </select>
        <?php if (isset($errors['balance_bank_account_id'])) { ?><span class="field-error"><?php echo e($errors['balance_bank_account_id']); ?></span><?php } ?>
      </div>

      <div class="form-row">
        <label for="balance_status">وضعیتِ الباقی</label>
        <select id="balance_status" name="balance_status">
          <option value="PENDING" <?php echo $form['balance_status'] === 'PENDING' ? 'selected' : ''; ?>>در انتظار پرداخت</option>
          <option value="PAID" <?php echo $form['balance_status'] === 'PAID' ? 'selected' : ''; ?>>پرداخت‌شده</option>
        </select>
      </div>

      <div class="form-row" style="grid-column:1/-1;">
        <label for="note">یادداشت (اختیاری)</label>
        <input type="text" id="note" name="note" value="<?php echo e($form['note']); ?>"
               class="<?php echo isset($errors['note']) ? 'is-invalid' : ''; ?>">
        <?php if (isset($errors['note'])) { ?><span class="field-error"><?php echo e($errors['note']); ?></span><?php } ?>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?php echo e($submit_label); ?></button>
        <a href="finance_payments.php" class="btn btn-secondary">انصراف</a>
      </div>
    <?php
}

/* ═══════════════════════════════════════════════════════════════════
 *  مرحلهٔ ۳ — تسویه با مراکز مشاوره (محاسبهٔ خودکار + ثبت پرداخت)
 * ═══════════════════════════════════════════════════════════════════ */

/* ═══════════════════════════════════════════════════════════════════
 *  الگوی استانداردِ ورودِ تاریخ در کلِ سایت (تصمیمِ قفل‌شده):
 *  هیچ‌جا تاریخ تایپ نمی‌شود. تاریخِ دلخواه ( مثل بازهٔ تسویه ) با
 *  سه فهرستِ کشوییِ «سال / ماه / روز» انتخاب می‌شود. تاریخ‌های
 *  آینده‌گرد (رزرو، فیلترِ بازه) از فهرستِ تکیِ jalali_date_choices
 *  استفاده می‌کنند که از قبل در سیستم موجود است.
 *  بازهٔ سال‌ها: ۱۴۰۵ تا ۱۴۱۰ (قابلِ تنظیم با ثابت‌های زیر).
 * ═══════════════════════════════════════════════════════════════════ */

if (!defined('JALALI_YEAR_FROM')) { define('JALALI_YEAR_FROM', 1405); }
if (!defined('JALALI_YEAR_TO'))   { define('JALALI_YEAR_TO', 1410); }

/**
 * تولیدِ HTMLِ انتخابگرِ تاریخِ شمسیِ سه‌بخشی (سال/ماه/روز) — بدون تایپ.
 * ترتیبِ DOM: سال، ماه، روز (در RTL از راست به چپ: سال، ماه، روز —
 * همان ترتیبِ «۱۴۰۵/۰۷/۰۱»).
 *
 * $name_prefix: پیشوندِ نامِ فیلدها؛ خروجیِ POST:
 *   {$prefix}_y و {$prefix}_m و {$prefix}_d (مقادیرِ لاتین)
 * $jalali_ymd: مقدارِ فعلی به شکل «1405/07/01» (لاتین یا فارسی) یا خالی
 */
function finance_jalali_date3_html($name_prefix, $jalali_ymd = '',
                                   $year_from = null, $year_to = null)
{
    return jalali_date3_html($name_prefix, $jalali_ymd, $year_from, $year_to);
}

/** ترکیبِ سه فیلدِ انتخابگرِ تاریخ به «1405/07/01» (لاتین). */
function finance_jalali_date3_compose($y, $m, $d)
{
    return jalali_date3_compose($y, $m, $d);
}

/** آیا مهاجرتِ مرحلهٔ ۳ (تسویه با مراکز) اجرا شده است؟ */
function phase5_3_ready($db)
{
    $res = @mysqli_query($db, "SHOW TABLES LIKE 'finance_center_settlements'");
    return ($res && mysqli_num_rows($res) > 0);
}

/** مرزهای میلادیِ یک ماهِ شمسی ( associative array('start','end') به‌صورت Y-m-d ) */
function finance_jalali_month_bounds($jy, $jm)
{
    list($gy, $gm, $gd) = jalali_to_gregorian((int)$jy, (int)$jm, 1);
    $start = sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    if ((int)$jm < 12) {
        list($ny, $nm, $nd) = jalali_to_gregorian((int)$jy, (int)$jm + 1, 1);
    } else {
        list($ny, $nm, $nd) = jalali_to_gregorian((int)$jy + 1, 1, 1);
    }
    $next = sprintf('%04d-%02d-%02d', $ny, $nm, $nd);
    $end = date_add_days($next, -1);
    return array('start' => $start, 'end' => $end);
}

/**
 * بازهٔ پیش‌فرضِ تسویه برای فرم (تاریخ‌های شمسیِ Y/m/d):
 * مرکزِ ماهیانه → ماهِ شمسیِ جاری؛ مرکزِ روزانه → امروز.
 */
function finance_default_period_jalali($settlement_period)
{
    $today_g = clinic_today();
    if ($settlement_period === 'DAILY') {
        $j = jalali_value($today_g);
        return array('start_j' => $j, 'end_j' => $j);
    }
    $p = explode('-', $today_g);
    $j = gregorian_to_jalali((int)$p[0], (int)$p[1], (int)$p[2]);
    $b = finance_jalali_month_bounds($j[0], $j[1]);
    return array('start_j' => jalali_value($b['start']), 'end_j' => jalali_value($b['end']));
}

/**
 * محاسبهٔ مبلغِ قابل‌پرداخت به یک مرکز برای یک بازهٔ زمانی.
 *
 * مدلِ درصدی:  درصد × مجموعِ پرداخت‌های «پرداخت‌شده»ِ جلساتِ اتاق‌های
 *   آن مرکز که تاریخِ پرداختِ بیعانه/الباقی‌شان در بازه افتاده است.
 * مدلِ ساعتی:  مجموع (ساعتِ جلسه × تعرفهٔ ساعتیِ اتاق × ۱۰) برای نوبت‌های
 *   «انجام‌شده/رزروشده»ِ بازه — تعرفهٔ ساعتی به «تومان» ذخیره می‌شود و
 *   خروجی به «ریال» است. نوبتِ زوج/خانواده خودکار با تعرفهٔ اتاقِ زوج
 *   محاسبه می‌شود (اتاقِ نوبت همان اتاقِ زوج است).
 *
 * $period_start_g و $period_end_g: تاریخ‌های میلادیِ Y-m-d (محلی).
 */
function finance_center_settlement_compute($db, $center_id, $period_start_g, $period_end_g)
{
    $center = finance_center_find($db, (int)$center_id);
    if (!$center || $center['status'] !== 'ACTIVE') {
        return array('ok' => false, 'error' => 'مرکزِ موردنظر یافت نشد یا غیرفعال است.');
    }
    if ($period_start_g === null || $period_end_g === null || $period_end_g < $period_start_g) {
        return array('ok' => false, 'error' => 'بازهٔ زمانی نامعتبر است.');
    }

    /* مرزهای UTCِ بازه (بر اساس روزهای محلیِ کلینیک) */
    $b1 = local_day_bounds_utc($period_start_g);
    $b2 = local_day_bounds_utc(date_add_days($period_end_g, 1));
    $from_utc = $b1['start'];
    $to_utc = $b2['start']; /* exklusiv */

    if ($center['commission_model'] === 'PERCENTAGE') {
        $percent = (float)$center['commission_percent'];
        $row = db_select_one($db,
            "SELECT COALESCE(SUM(
                     CASE WHEN fsp.deposit_status = 'PAID'
                            AND fsp.deposit_paid_at >= ? AND fsp.deposit_paid_at < ?
                          THEN COALESCE(fsp.deposit_amount_rial, 0) ELSE 0 END
                   + CASE WHEN fsp.balance_status = 'PAID'
                            AND fsp.balance_paid_at >= ? AND fsp.balance_paid_at < ?
                          THEN COALESCE(fsp.balance_amount_rial, 0) ELSE 0 END
                   ), 0) AS collected_rial
               FROM finance_session_payments fsp
               INNER JOIN appointments ap ON ap.id = fsp.appointment_id
               INNER JOIN rooms r ON r.id = ap.room_id
              WHERE r.center_id = ?",
            'ssssi',
            array($from_utc, $to_utc, $from_utc, $to_utc, (int)$center_id));
        $collected = (int)$row['collected_rial'];
        $due = (int)round($collected * $percent / 100);
        $percent_txt = str_replace('.', '٫', to_persian_digits(rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.')));
        return array(
            'ok' => true,
            'amount_due_rial' => $due,
            'collected_rial' => $collected,
            'percent' => $percent,
            'hours' => null,
            'detail' => to_persian_digits(number_format($collected)) . ' تومان (واریزی) '
                . '× ' . $percent_txt . '٪',
        );
    }

    /* مدلِ ساعتی — تعرفهٔ ساعتیِ اتاق به «تومان» ذخیره می‌شود (مرحلهٔ ۱);
       اینجا ×۱۰ می‌شود تا خروجی به «ریال» باشد، مثل همهٔ مبالغِ این ماژول. */
    $row = db_select_one($db,
        "SELECT COALESCE(SUM((ap.duration_minutes / 60) * COALESCE(r.hourly_rate, 0) * 10), 0) AS due_rial,
                COALESCE(SUM(ap.duration_minutes / 60), 0) AS hours,
                COUNT(*) AS appt_count
           FROM appointments ap
           INNER JOIN rooms r ON r.id = ap.room_id
          WHERE r.center_id = ?
            AND ap.status IN ('COMPLETED','SCHEDULED')
            AND ap.appointment_start_utc >= ? AND ap.appointment_start_utc < ?",
        'iss',
        array((int)$center_id, $from_utc, $to_utc));
    $due = (int)round($row['due_rial']);
    $hours = (float)$row['hours'];
    return array(
        'ok' => true,
        'amount_due_rial' => $due,
        'collected_rial' => null,
        'percent' => null,
        'hours' => $hours,
        'appt_count' => (int)$row['appt_count'],
        'detail' => to_persian_digits(number_format($hours, 2)) . ' ساعت × تعرفهٔ اتاق',
    );
}

/** ثبتِ تسویهٔ جدید (مبلغِ قابل‌پرداخت به‌صورت خودکار محاسبه می‌شود) */
function finance_settlement_create($db, $center_id, $period_start_j, $period_end_j,
                                   $actor_person_id, $actor_role_code)
{
    $center = finance_center_find($db, (int)$center_id);
    if (!$center || $center['status'] !== 'ACTIVE') {
        return array('ok' => false, 'error' => 'مرکزِ موردنظر یافت نشد یا غیرفعال است.');
    }
    if (trim((string)$period_start_j) === '') {
        return array('ok' => false, 'error' => 'تاریخِ شروعِ بازه را انتخاب کنید.');
    }
    if (trim((string)$period_end_j) === '') {
        return array('ok' => false, 'error' => 'تاریخِ پایانِ بازه را انتخاب کنید.');
    }
    $start_g = jalali_input_to_gregorian($period_start_j);
    $end_g = jalali_input_to_gregorian($period_end_j);
    if ($start_g === null) {
        return array('ok' => false, 'error' => 'تاریخِ شروعِ بازه نامعتبر است (چنین روزی در آن ماه وجود ندارد).');
    }
    if ($end_g === null) {
        return array('ok' => false, 'error' => 'تاریخِ پایانِ بازه نامعتبر است (چنین روزی در آن ماه وجود ندارد).');
    }
    if ($end_g < $start_g) {
        return array('ok' => false, 'error' => 'تاریخِ پایانِ بازه نباید قبل از تاریخِ شروع باشد.');
    }
    $existing = db_select_one($db,
        "SELECT id FROM finance_center_settlements
          WHERE center_id = ? AND period_start = ? AND period_end = ?",
        'iss', array((int)$center_id, $start_g, $end_g));
    if ($existing) {
        return array('ok' => false, 'error' => 'برای این مرکز و این بازه، قبلاً تسویه ثبت شده است.');
    }

    $compute = finance_center_settlement_compute($db, (int)$center_id, $start_g, $end_g);
    if (!$compute['ok']) {
        return array('ok' => false, 'error' => $compute['error']);
    }

    $public_id = generate_public_id('STL');
    $res = db_execute($db,
        "INSERT INTO finance_center_settlements
            (public_id, center_id, period_start, period_end, amount_due_rial,
             recorded_by_person_id, created_at)
         VALUES (?,?,?,?,?,?,?)",
        'sissdis',
        array(
            $public_id,
            (int)$center_id,
            $start_g,
            $end_g,
            $compute['amount_due_rial'],
            $actor_person_id,
            now_dt(),
        )
    );
    $settlement_id = (int)$res['insert_id'];

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FINANCE_SETTLEMENT_CREATED',
        'finance_center_settlement', $settlement_id, array(
            'public_id' => $public_id,
            'center_id' => (int)$center_id,
            'period_start' => $start_g,
            'period_end' => $end_g,
            'amount_due_rial' => $compute['amount_due_rial'],
        ));

    return array('ok' => true, 'settlement_id' => $settlement_id,
        'amount_due_rial' => $compute['amount_due_rial'], 'detail' => $compute['detail']);
}

/** اعتبارسنجیِ فرمِ ثبتِ پرداختِ تسویه */
function finance_settlement_validate_payment($form, $amount_due_rial)
{
    $errors = array();
    $paid_rial = finance_toman_input_to_rial(isset($form['amount_paid']) ? $form['amount_paid'] : '');
    $account_raw = isset($form['paid_bank_account_id']) ? trim((string)$form['paid_bank_account_id']) : '';
    $account_id = ($account_raw === '') ? null : (int)$account_raw;
    $note = trim(isset($form['note']) ? $form['note'] : '');

    if (mb_strlen($note, 'UTF-8') > 255) {
        $errors['note'] = 'یادداشت بیش از حد طولانی است.';
    }
    if ($paid_rial !== null && $paid_rial > (int)$amount_due_rial) {
        $errors['amount_paid'] = 'مبلغِ واریزی نباید بیشتر از مبلغِ قابل‌پرداخت باشد.';
    }
    if ($paid_rial !== null && $paid_rial > 0 && $account_id === null) {
        $errors['paid_bank_account_id'] = 'برای مبلغِ واریزی، حسابِ بانکیِ پرداخت را انتخاب کنید.';
    }

    return array(
        'ok' => (count($errors) === 0),
        'errors' => $errors,
        'amount_paid_rial' => $paid_rial,
        'paid_bank_account_id' => $account_id,
        'note' => ($note === '' ? null : $note),
    );
}

/** ثبت/ویرایشِ پرداختِ تسویه به مرکز */
function finance_settlement_update_payment($db, $settlement_id, $data,
                                           $actor_person_id, $actor_role_code)
{
    $existing = finance_settlement_find($db, (int)$settlement_id);
    if (!$existing) {
        return array('ok' => false, 'error' => 'تسویهٔ موردنظر یافت نشد.');
    }
    $due = (int)$existing['amount_due_rial'];
    $paid = $data['amount_paid_rial'];

    $paid_at = $existing['paid_at'];
    if ($paid !== null && $due > 0 && $paid >= $due) {
        $paid_at = ($existing['paid_at']) ? $existing['paid_at'] : now_dt();
    } elseif ($paid === null || $paid <= 0) {
        $paid_at = null;
    }

    db_execute($db,
        "UPDATE finance_center_settlements
            SET amount_paid_rial = ?, paid_bank_account_id = ?, paid_at = ?,
                note = ?, updated_at = ?
          WHERE id = ?",
        'disssi',
        array(
            $paid,
            $data['paid_bank_account_id'],
            $paid_at,
            $data['note'],
            now_dt(),
            (int)$settlement_id,
        )
    );

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FINANCE_SETTLEMENT_PAID',
        'finance_center_settlement', (int)$settlement_id, array(
            'amount_paid_rial' => $paid,
            'amount_due_rial' => $due,
        ));

    return array('ok' => true);
}

/** یافتنِ یک تسویه با id یا public_id (به‌همراهِ نامِ مرکز) */
function finance_settlement_find($db, $id_or_public)
{
    $sql = "SELECT s.*, c.name AS center_name
              FROM finance_center_settlements s
              LEFT JOIN finance_centers c ON c.id = s.center_id
             WHERE ";
    if (ctype_digit((string)$id_or_public)) {
        return db_select_one($db, $sql . "s.id = ?", 'i', array((int)$id_or_public));
    }
    return db_select_one($db, $sql . "s.public_id = ?", 's', array((string)$id_or_public));
}

/** فهرستِ همهٔ تسویه‌ها به‌همراهِ نامِ مرکز */
function finance_settlements_fetch_all($db)
{
    return db_select_all($db,
        "SELECT s.*, c.name AS center_name, c.commission_model, c.commission_percent,
                c.settlement_period,
                b.label AS paid_account_label
           FROM finance_center_settlements s
           INNER JOIN finance_centers c ON c.id = s.center_id
           LEFT JOIN finance_bank_accounts b ON b.id = s.paid_bank_account_id
          ORDER BY s.period_start DESC, c.name ASC");
}

/** جمعِ کلِ تسویه‌ها (برای کارت‌های بالای صفحه) */
function finance_settlements_summary($db)
{
    return db_select_one($db,
        "SELECT COUNT(*) AS cnt,
                COALESCE(SUM(amount_due_rial), 0) AS total_due_rial,
                COALESCE(SUM(COALESCE(amount_paid_rial, 0)), 0) AS total_paid_rial
           FROM finance_center_settlements");
}

/** وضعیتِ یک تسویه (پرداخت‌نشده / جزئی / پرداخت‌شده) */
function finance_settlement_status($s)
{
    $due = (int)$s['amount_due_rial'];
    $paid = ($s['amount_paid_rial'] !== null) ? (int)$s['amount_paid_rial'] : 0;
    if ($paid <= 0) {
        return array('status' => 'UNPAID', 'label' => 'پرداخت‌نشده', 'class' => 'badge-danger');
    }
    if ($due > 0 && $paid >= $due) {
        return array('status' => 'PAID', 'label' => 'پرداخت‌شده', 'class' => 'badge-success');
    }
    return array('status' => 'PARTIAL', 'label' => 'پرداختِ جزئی', 'class' => 'badge-warning');
}

/* ═══════════════════════════════════════════════════════════════════
 *  مرحلهٔ ۴ — فروشِ تست‌ها و پکیج‌های تمرینیِ یک‌ماهه
 *  (ثبتِ فروش + وضعیتِ پرداخت؛ وجه از درگاهِ شما یا واریزِ دستی می‌آید)
 * ═══════════════════════════════════════════════════════════════════ */

/** آیا مهاجرتِ مرحلهٔ ۴ (فروشِ تست و پکیج) اجرا شده است؟ */
function phase5_4_ready($db)
{
    $a = @mysqli_query($db, "SHOW TABLES LIKE 'finance_test_sales'");
    $b = @mysqli_query($db, "SHOW TABLES LIKE 'finance_package_sales'");
    $ok = ($a && mysqli_num_rows($a) > 0 && $b && mysqli_num_rows($b) > 0);
    if ($a) { mysqli_free_result($a); }
    if ($b) { mysqli_free_result($b); }
    return $ok;
}

/** فهرستِ مراجعینِ فعال (برای فهرستِ کشویی) */
function finance_patients_fetch_active($db)
{
    return db_select_all($db,
        "SELECT p.id, p.first_name, p.last_name, p.mobile_number
           FROM persons p
           INNER JOIN role_assignments ra ON ra.person_id = p.id
          WHERE ra.role_code = 'patient' AND ra.status = 'ACTIVE' AND p.status = 'ACTIVE'
          ORDER BY p.first_name, p.last_name, p.id");
}

/** برچسبِ یک مراجع برای فهرستِ کشویی: «نام نام‌خانوادگی — موبایل» */
function finance_patient_label($p)
{
    $name = trim($p['first_name'] . ' ' . $p['last_name']);
    return $name . ' — ' . to_persian_digits($p['mobile_number']);
}

/** برچسبِ وضعیتِ فروش (PENDING/PAID) */
function finance_sale_status_label($status)
{
    return ($status === 'PAID') ? 'پرداخت‌شده' : 'در انتظارِ پرداخت';
}

/** کلاسِ badge برای وضعیتِ فروش */
function finance_sale_status_class($status)
{
    return ($status === 'PAID') ? 'badge-success' : 'badge-warning';
}

/** بازهٔ پیش‌فرضِ پکیجِ یک‌ماهه: از امروز تا یک ماهِ بعد، منهایِ یک روز */
function finance_default_package_period()
{
    $start_j = jalali_value(clinic_today());
    $g = jalali_input_to_gregorian($start_j);
    if ($g === null) {
        return array('start_j' => $start_j, 'end_j' => $start_j);
    }
    $dt = new DateTime($g . ' 00:00:00', new DateTimeZone('UTC'));
    $dt->modify('+1 month');
    $dt->modify('-1 day');
    return array('start_j' => $start_j, 'end_j' => jalali_value($dt->format('Y-m-d')));
}

/**
 * اعتبارسنجیِ فرمِ فروشِ تست.
 * خروجی: array('ok','errors','patient_person_id','test_name','amount_rial',
 *              'status','paid_bank_account_id','note')
 */
function finance_test_sale_validate($form)
{
    $errors = array();

    $patient_raw = isset($form['patient_person_id']) ? trim((string)$form['patient_person_id']) : '';
    $patient_id = ($patient_raw === '') ? null : (int)$patient_raw;
    if ($patient_id === null) {
        $errors['patient_person_id'] = 'مراجع را از فهرستِ کشویی انتخاب کنید.';
    }

    $test_name = trim(isset($form['test_name']) ? (string)$form['test_name'] : '');
    if ($test_name === '') {
        $errors['test_name'] = 'نامِ تست را وارد کنید.';
    } elseif (mb_strlen($test_name, 'UTF-8') > 150) {
        $errors['test_name'] = 'نامِ تست بیش از حد طولانی است.';
    }

    $amount_rial = finance_toman_input_to_rial(isset($form['amount']) ? $form['amount'] : '');
    if ($amount_rial === null || $amount_rial <= 0) {
        $errors['amount'] = 'مبلغِ فروش را به تومان وارد کنید (باید بزرگ‌تر از صفر باشد).';
    }

    $status = (isset($form['status']) && $form['status'] === 'PAID') ? 'PAID' : 'PENDING';

    $account_raw = isset($form['paid_bank_account_id']) ? trim((string)$form['paid_bank_account_id']) : '';
    $account_id = ($account_raw === '') ? null : (int)$account_raw;
    if ($status === 'PAID' && $account_id === null) {
        $errors['paid_bank_account_id'] = 'برای وضعیتِ «پرداخت‌شده»، حسابِ بانکی را انتخاب کنید را انتخاب کنید.';
    }

    $note = trim(isset($form['note']) ? (string)$form['note'] : '');
    if (mb_strlen($note, 'UTF-8') > 255) {
        $errors['note'] = 'یادداشت بیش از حد طولانی است.';
    }

    return array(
        'ok' => (count($errors) === 0),
        'errors' => $errors,
        'patient_person_id' => $patient_id,
        'test_name' => ($test_name === '' ? null : $test_name),
        'amount_rial' => $amount_rial,
        'status' => $status,
        'paid_bank_account_id' => $account_id,
        'note' => ($note === '' ? null : $note),
    );
}

/** ثبتِ فروشِ تستِ جدید */
function finance_test_sale_create($db, $data, $actor_person_id, $actor_role_code)
{
    $patient_id = (int)$data['patient_person_id'];
    $patient = db_select_one($db,
        "SELECT id FROM persons WHERE id = ? AND status = 'ACTIVE'", 'i', array($patient_id));
    if (!$patient) {
        return array('ok' => false, 'error' => 'مراجعِ انتخاب‌شده یافت نشد یا غیرفعال است.');
    }

    $paid_at = ($data['status'] === 'PAID') ? now_dt() : null;
    $public_id = generate_public_id('TST');
    $res = db_execute($db,
        "INSERT INTO finance_test_sales
            (public_id, patient_person_id, test_name, amount_rial,
             paid_bank_account_id, status, paid_at, note,
             recorded_by_person_id, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?)",
        'sisdisssis',
        array(
            $public_id,
            $patient_id,
            $data['test_name'],
            $data['amount_rial'],
            $data['paid_bank_account_id'],
            $data['status'],
            $paid_at,
            $data['note'],
            $actor_person_id,
            now_dt(),
        )
    );
    $sale_id = (int)$res['insert_id'];

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FINANCE_TEST_SALE_CREATED',
        'finance_test_sale', $sale_id, array(
            'public_id' => $public_id,
            'patient_person_id' => $patient_id,
            'test_name' => $data['test_name'],
            'amount_rial' => $data['amount_rial'],
            'status' => $data['status'],
        ));

    return array('ok' => true, 'sale_id' => $sale_id, 'public_id' => $public_id);
}

/** یافتنِ یک فروشِ تست با id یا public_id */
function finance_test_sale_find($db, $id_or_public)
{
    if (ctype_digit((string)$id_or_public)) {
        return db_select_one($db, "SELECT * FROM finance_test_sales WHERE id = ?",
            'i', array((int)$id_or_public));
    }
    return db_select_one($db, "SELECT * FROM finance_test_sales WHERE public_id = ?",
        's', array((string)$id_or_public));
}

/** فهرستِ همهٔ فروشِ تست‌ها (به‌همراهِ نامِ مراجع و حساب) */
function finance_test_sales_fetch_all($db)
{
    return db_select_all($db,
        "SELECT s.*, p.first_name, p.last_name, p.mobile_number,
                b.label AS paid_account_label
           FROM finance_test_sales s
           INNER JOIN persons p ON p.id = s.patient_person_id
           LEFT JOIN finance_bank_accounts b ON b.id = s.paid_bank_account_id
          ORDER BY s.created_at DESC, s.id DESC");
}

/** جمعِ کلِ فروشِ تست‌ها (برای کارتِ خلاصه) */
function finance_test_sales_summary($db)
{
    return db_select_one($db,
        "SELECT COUNT(*) AS cnt,
                COALESCE(SUM(amount_rial), 0) AS total_rial,
                COALESCE(SUM(CASE WHEN status = 'PAID' THEN amount_rial ELSE 0 END), 0) AS paid_rial
           FROM finance_test_sales");
}

/** به‌روزرسانیِ وضعیتِ پرداختِ فروشِ تست (status + حساب + یادداشت) */
function finance_test_sale_update_status($db, $sale_id, $data, $actor_person_id, $actor_role_code)
{
    $existing = finance_test_sale_find($db, (int)$sale_id);
    if (!$existing) {
        return array('ok' => false, 'error' => 'فروشِ موردنظر یافت نشد.');
    }
    $paid_at = $existing['paid_at'];
    if ($data['status'] === 'PAID') {
        $paid_at = ($existing['paid_at']) ? $existing['paid_at'] : now_dt();
    } else {
        $paid_at = null;
    }

    db_execute($db,
        "UPDATE finance_test_sales
            SET status = ?, paid_bank_account_id = ?, paid_at = ?, note = ?, updated_at = ?
          WHERE id = ?",
        'sisssi',
        array(
            $data['status'],
            $data['paid_bank_account_id'],
            $paid_at,
            $data['note'],
            now_dt(),
            (int)$sale_id,
        )
    );

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FINANCE_TEST_SALE_STATUS_UPDATED',
        'finance_test_sale', (int)$sale_id, array(
            'status' => $data['status'],
        ));

    return array('ok' => true);
}

/**
 * اعتبارسنجیِ فرمِ فروشِ پکیجِ یک‌ماهه (به‌علاوهٔ بازهٔ اعتبار).
 * خروجی: array('ok','errors','patient_person_id','package_name','amount_rial',
 *              'period_start_g','period_end_g','status','paid_bank_account_id','note')
 */
function finance_package_sale_validate($form)
{
    $errors = array();

    $patient_raw = isset($form['patient_person_id']) ? trim((string)$form['patient_person_id']) : '';
    $patient_id = ($patient_raw === '') ? null : (int)$patient_raw;
    if ($patient_id === null) {
        $errors['patient_person_id'] = 'مراجع را از فهرستِ کشویی انتخاب کنید.';
    }

    $package_name = trim(isset($form['package_name']) ? (string)$form['package_name'] : '');
    if ($package_name === '') {
        $errors['package_name'] = 'نامِ پکیج را وارد کنید.';
    } elseif (mb_strlen($package_name, 'UTF-8') > 150) {
        $errors['package_name'] = 'نامِ پکیج بیش از حد طولانی است.';
    }

    $amount_rial = finance_toman_input_to_rial(isset($form['amount']) ? $form['amount'] : '');
    if ($amount_rial === null || $amount_rial <= 0) {
        $errors['amount'] = 'مبلغِ فروش را به تومان وارد کنید (باید بزرگ‌تر از صفر باشد).';
    }

    if (trim((string)(isset($form['period_start']) ? $form['period_start'] : '')) === '') {
        $errors['period_start'] = 'تاریخِ شروعِ اعتبار را انتخاب کنید.';
    }
    if (trim((string)(isset($form['period_end']) ? $form['period_end'] : '')) === '') {
        $errors['period_end'] = 'تاریخِ پایانِ اعتبار را انتخاب کنید.';
    }
    $start_g = jalali_input_to_gregorian(isset($form['period_start']) ? $form['period_start'] : '');
    $end_g = jalali_input_to_gregorian(isset($form['period_end']) ? $form['period_end'] : '');
    if (!isset($errors['period_start']) && $start_g === null) {
        $errors['period_start'] = 'تاریخِ شروعِ اعتبار نامعتبر است (چنین روزی در آن ماه وجود ندارد).';
    }
    if (!isset($errors['period_end']) && $end_g === null) {
        $errors['period_end'] = 'تاریخِ پایانِ اعتبار نامعتبر است (چنین روزی در آن ماه وجود ندارد).';
    }
    if ($start_g !== null && $end_g !== null && $end_g < $start_g) {
        $errors['period_end'] = 'تاریخِ پایانِ اعتبار نباید قبل از تاریخِ شروع باشد.';
    }

    $status = (isset($form['status']) && $form['status'] === 'PAID') ? 'PAID' : 'PENDING';

    $account_raw = isset($form['paid_bank_account_id']) ? trim((string)$form['paid_bank_account_id']) : '';
    $account_id = ($account_raw === '') ? null : (int)$account_raw;
    if ($status === 'PAID' && $account_id === null) {
        $errors['paid_bank_account_id'] = 'برای وضعیتِ «پرداخت‌شده»، حسابِ بانکی را انتخاب کنید را انتخاب کنید.';
    }

    $note = trim(isset($form['note']) ? (string)$form['note'] : '');
    if (mb_strlen($note, 'UTF-8') > 255) {
        $errors['note'] = 'یادداشت بیش از حد طولانی است.';
    }

    return array(
        'ok' => (count($errors) === 0),
        'errors' => $errors,
        'patient_person_id' => $patient_id,
        'package_name' => ($package_name === '' ? null : $package_name),
        'amount_rial' => $amount_rial,
        'period_start_g' => $start_g,
        'period_end_g' => $end_g,
        'status' => $status,
        'paid_bank_account_id' => $account_id,
        'note' => ($note === '' ? null : $note),
    );
}

/** ثبتِ فروشِ پکیجِ جدید */
function finance_package_sale_create($db, $data, $actor_person_id, $actor_role_code)
{
    $patient_id = (int)$data['patient_person_id'];
    $patient = db_select_one($db,
        "SELECT id FROM persons WHERE id = ? AND status = 'ACTIVE'", 'i', array($patient_id));
    if (!$patient) {
        return array('ok' => false, 'error' => 'مراجعِ انتخاب‌شده یافت نشد یا غیرفعال است.');
    }

    $paid_at = ($data['status'] === 'PAID') ? now_dt() : null;
    $public_id = generate_public_id('PKG');
    $res = db_execute($db,
        "INSERT INTO finance_package_sales
            (public_id, patient_person_id, package_name, amount_rial,
             period_start, period_end, paid_bank_account_id, status, paid_at, note,
             recorded_by_person_id, created_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
        'sissdsssisss',
        array(
            $public_id,
            $patient_id,
            $data['package_name'],
            $data['amount_rial'],
            $data['period_start_g'],
            $data['period_end_g'],
            $data['paid_bank_account_id'],
            $data['status'],
            $paid_at,
            $data['note'],
            $actor_person_id,
            now_dt(),
        )
    );
    $sale_id = (int)$res['insert_id'];

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FINANCE_PACKAGE_SALE_CREATED',
        'finance_package_sale', $sale_id, array(
            'public_id' => $public_id,
            'patient_person_id' => $patient_id,
            'package_name' => $data['package_name'],
            'amount_rial' => $data['amount_rial'],
            'period_start' => $data['period_start_g'],
            'period_end' => $data['period_end_g'],
            'status' => $data['status'],
        ));

    return array('ok' => true, 'sale_id' => $sale_id, 'public_id' => $public_id);
}

/** یافتنِ یک فروشِ پکیج با id یا public_id */
function finance_package_sale_find($db, $id_or_public)
{
    if (ctype_digit((string)$id_or_public)) {
        return db_select_one($db, "SELECT * FROM finance_package_sales WHERE id = ?",
            'i', array((int)$id_or_public));
    }
    return db_select_one($db, "SELECT * FROM finance_package_sales WHERE public_id = ?",
        's', array((string)$id_or_public));
}

/** فهرستِ همهٔ فروشِ پکیج‌ها (به‌همراهِ نامِ مراجع و حساب) */
function finance_package_sales_fetch_all($db)
{
    return db_select_all($db,
        "SELECT s.*, p.first_name, p.last_name, p.mobile_number,
                b.label AS paid_account_label
           FROM finance_package_sales s
           INNER JOIN persons p ON p.id = s.patient_person_id
           LEFT JOIN finance_bank_accounts b ON b.id = s.paid_bank_account_id
          ORDER BY s.created_at DESC, s.id DESC");
}

/** جمعِ کلِ فروشِ پکیج‌ها (برای کارتِ خلاصه) */
function finance_package_sales_summary($db)
{
    return db_select_one($db,
        "SELECT COUNT(*) AS cnt,
                COALESCE(SUM(amount_rial), 0) AS total_rial,
                COALESCE(SUM(CASE WHEN status = 'PAID' THEN amount_rial ELSE 0 END), 0) AS paid_rial
           FROM finance_package_sales");
}

/** به‌روزرسانیِ وضعیتِ پرداختِ فروشِ پکیج (status + حساب + یادداشت) */
function finance_package_sale_update_status($db, $sale_id, $data, $actor_person_id, $actor_role_code)
{
    $existing = finance_package_sale_find($db, (int)$sale_id);
    if (!$existing) {
        return array('ok' => false, 'error' => 'فروشِ موردنظر یافت نشد.');
    }
    $paid_at = $existing['paid_at'];
    if ($data['status'] === 'PAID') {
        $paid_at = ($existing['paid_at']) ? $existing['paid_at'] : now_dt();
    } else {
        $paid_at = null;
    }

    db_execute($db,
        "UPDATE finance_package_sales
            SET status = ?, paid_bank_account_id = ?, paid_at = ?, note = ?, updated_at = ?
          WHERE id = ?",
        'sisssi',
        array(
            $data['status'],
            $data['paid_bank_account_id'],
            $paid_at,
            $data['note'],
            now_dt(),
            (int)$sale_id,
        )
    );

    audit_log_write($db, $actor_person_id, $actor_role_code, 'FINANCE_PACKAGE_SALE_STATUS_UPDATED',
        'finance_package_sale', (int)$sale_id, array(
            'status' => $data['status'],
        ));

    return array('ok' => true);
}

/* ═══════════════════════════════════════════════════════════════════
 *  مرحلهٔ ۵ — گزارشِ مالی (خلاصهٔ درآمد، بدهیِ مراکز، خالص)
 *  فقط خواندنی؛ هیچ تغییری در پایگاه داده ایجاد نمی‌کند.
 * ═══════════════════════════════════════════════════════════════════ */

/** آیا همهٔ زیرساخت‌های ماژول مالی (مراحل ۱ تا ۴) آماده‌اند؟ */
function phase5_5_ready($db)
{
    return phase5_ready($db) && phase5_2_ready($db) && phase5_3_ready($db) && phase5_4_ready($db);
}

/**
 * گزارشِ درآمدِ بازه (کرانه‌های UTC):
 * پرداخت‌هایی که «پرداخت‌شده»اند و تاریخِ پرداختشان در بازه افتاده است.
 * «در انتظار» = وضعیتِ فعلی (مستقل از بازهٔ انتخاب‌شده).
 *
 * خروجی: array(
 *   'sessions' => array('cnt','paid_rial','pending_cnt','pending_rial'),
 *   'tests'    => array('cnt','paid_rial','pending_cnt','pending_rial'),
 *   'packages' => array('cnt','paid_rial','pending_cnt','pending_rial'),
 *   'total_paid_rial' => int,
 * )
 */
function finance_report_income($db, $from_utc, $to_utc)
{
    /* جلسات: بیعانه + الباقیِ «پرداخت‌شده» در بازه */
    $sessions = db_select_one($db,
        "SELECT COUNT(DISTINCT fsp.appointment_id) AS cnt,
                COALESCE(SUM(
                    CASE WHEN fsp.deposit_status = 'PAID'
                          AND fsp.deposit_paid_at >= ? AND fsp.deposit_paid_at < ?
                         THEN COALESCE(fsp.deposit_amount_rial, 0) ELSE 0 END
                  + CASE WHEN fsp.balance_status = 'PAID'
                          AND fsp.balance_paid_at >= ? AND fsp.balance_paid_at < ?
                         THEN COALESCE(fsp.balance_amount_rial, 0) ELSE 0 END
                ), 0) AS paid_rial
           FROM finance_session_payments fsp
          WHERE (fsp.deposit_status = 'PAID'
                 AND fsp.deposit_paid_at >= ? AND fsp.deposit_paid_at < ?)
             OR (fsp.balance_status = 'PAID'
                 AND fsp.balance_paid_at >= ? AND fsp.balance_paid_at < ?)",
        'ssssssss',
        array($from_utc, $to_utc, $from_utc, $to_utc, $from_utc, $to_utc, $from_utc, $to_utc));
    $sessions_pending = db_select_one($db,
        "SELECT COUNT(*) AS cnt,
                COALESCE(SUM(COALESCE(deposit_amount_rial, 0)), 0) AS pending_rial
           FROM finance_session_payments
          WHERE deposit_status = 'PENDING'");

    /* فروشِ تست‌ها */
    $tests = db_select_one($db,
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_rial), 0) AS paid_rial
           FROM finance_test_sales
          WHERE status = 'PAID' AND paid_at >= ? AND paid_at < ?",
        'ss', array($from_utc, $to_utc));
    $tests_pending = db_select_one($db,
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_rial), 0) AS pending_rial
           FROM finance_test_sales
          WHERE status = 'PENDING'");

    /* فروشِ پکیج‌ها */
    $packages = db_select_one($db,
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_rial), 0) AS paid_rial
           FROM finance_package_sales
          WHERE status = 'PAID' AND paid_at >= ? AND paid_at < ?",
        'ss', array($from_utc, $to_utc));
    $packages_pending = db_select_one($db,
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount_rial), 0) AS pending_rial
           FROM finance_package_sales
          WHERE status = 'PENDING'");

    $mk = function ($row, $pend) {
        return array(
            'cnt' => (int)$row['cnt'],
            'paid_rial' => (int)$row['paid_rial'],
            'pending_cnt' => (int)$pend['cnt'],
            'pending_rial' => (int)$pend['pending_rial'],
        );
    };
    $out = array(
        'sessions' => $mk($sessions, $sessions_pending),
        'tests' => $mk($tests, $tests_pending),
        'packages' => $mk($packages, $packages_pending),
    );
    $out['total_paid_rial'] = $out['sessions']['paid_rial']
        + $out['tests']['paid_rial'] + $out['packages']['paid_rial'];
    return $out;
}

/**
 * گزارشِ تسویه با مراکز در بازه: تسویه‌هایی که بازه‌شان با بازهٔ انتخاب‌شده
 * هم‌پوشانی دارد (period_start <= end AND period_end >= start)، به تفکیکِ مرکز.
 * خروجی: array('rows' => [...], 'total_due_rial', 'total_paid_rial', 'total_remaining_rial')
 */
function finance_report_settlements($db, $start_g, $end_g)
{
    $rows = db_select_all($db,
        "SELECT c.id AS center_id, c.name AS center_name, c.commission_model,
                COUNT(s.id) AS cnt,
                COALESCE(SUM(s.amount_due_rial), 0) AS due_rial,
                COALESCE(SUM(COALESCE(s.amount_paid_rial, 0)), 0) AS paid_rial
           FROM finance_center_settlements s
           INNER JOIN finance_centers c ON c.id = s.center_id
          WHERE s.period_start <= ? AND s.period_end >= ?
          GROUP BY c.id, c.name, c.commission_model
          ORDER BY c.name",
        'ss', array($end_g, $start_g));
    $total_due = 0;
    $total_paid = 0;
    foreach ($rows as $i => $r) {
        $rows[$i]['cnt'] = (int)$r['cnt'];
        $rows[$i]['due_rial'] = (int)$r['due_rial'];
        $rows[$i]['paid_rial'] = (int)$r['paid_rial'];
        $rows[$i]['remaining_rial'] = $rows[$i]['due_rial'] - $rows[$i]['paid_rial'];
        $total_due += $rows[$i]['due_rial'];
        $total_paid += $rows[$i]['paid_rial'];
    }
    return array(
        'rows' => $rows,
        'total_due_rial' => $total_due,
        'total_paid_rial' => $total_paid,
        'total_remaining_rial' => $total_due - $total_paid,
    );
}
