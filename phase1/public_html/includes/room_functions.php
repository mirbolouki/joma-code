<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما فاز ۲ — مدیریت اتاق‌ها
 *  اتاق هرگز حذف فیزیکی نمی‌شود؛ فقط وضعیتش INACTIVE می‌شود.
 * ═══════════════════════════════════════════════════════════════════ */

/** اعتبارسنجی ورودی فرم اتاق. خروجی: array('ok'=>bool,'errors'=>array,...) */
function room_validate($form)
{
    $errors = array();

    $name = trim(isset($form['name']) ? $form['name'] : '');
    $room_number = trim(to_latin_digits(isset($form['room_number']) ? $form['room_number'] : ''));
    $capacity = (int)to_latin_digits(isset($form['capacity']) ? $form['capacity'] : '1');

    if ($name === '' || mb_strlen($name, 'UTF-8') < 2) {
        $errors['name'] = 'نام اتاق را وارد کنید (حداقل ۲ نویسه).';
    } elseif (mb_strlen($name, 'UTF-8') > 100) {
        $errors['name'] = 'نام اتاق بیش از حد طولانی است.';
    }

    if ($room_number !== '' && mb_strlen($room_number, 'UTF-8') > 20) {
        $errors['room_number'] = 'شمارهٔ اتاق بیش از حد طولانی است.';
    }

    if ($capacity < 1 || $capacity > 20) {
        $errors['capacity'] = 'ظرفیت باید عددی بین ۱ تا ۲۰ باشد.';
    }

    return array(
        'ok' => (count($errors) === 0),
        'errors' => $errors,
        'name' => $name,
        'room_number' => ($room_number === '' ? null : $room_number),
        'capacity' => $capacity,
    );
}

/** ساخت اتاق جدید. خروجی: شناسهٔ داخلی */
function room_create($db, $name, $room_number, $capacity, $actor_person_id, $actor_role_code)
{
    $public_id = generate_public_id('rm');
    $created_at = now_dt();

    $res = db_execute(
        $db,
        "INSERT INTO rooms (public_id, name, room_number, capacity, status, created_at)
         VALUES (?,?,?,?,'ACTIVE',?)",
        'sssis',
        array($public_id, $name, $room_number, (int)$capacity, $created_at)
    );
    $room_id = (int)$res['insert_id'];

    audit_log_write($db, $actor_person_id, $actor_role_code, 'ROOM_CREATED', 'room', $room_id,
        array('name' => $name, 'room_number' => $room_number, 'capacity' => (int)$capacity));

    return $room_id;
}

/** ویرایش اتاق */
function room_update($db, $room_id, $name, $room_number, $capacity, $actor_person_id, $actor_role_code)
{
    db_execute(
        $db,
        "UPDATE rooms SET name = ?, room_number = ?, capacity = ? WHERE id = ?",
        'ssii',
        array($name, $room_number, (int)$capacity, (int)$room_id)
    );

    audit_log_write($db, $actor_person_id, $actor_role_code, 'ROOM_UPDATED', 'room', (int)$room_id,
        array('name' => $name, 'room_number' => $room_number, 'capacity' => (int)$capacity));
}

/**
 * فعال/غیرفعال کردن اتاق (حذف نرم).
 * اتاقی که نوبت آیندهٔ فعال دارد غیرفعال نمی‌شود.
 */
function room_set_status($db, $room_id, $status, $actor_person_id, $actor_role_code)
{
    $status = ($status === 'INACTIVE') ? 'INACTIVE' : 'ACTIVE';

    if ($status === 'INACTIVE') {
        $row = db_select_one(
            $db,
            "SELECT COUNT(*) AS cnt FROM appointments
             WHERE room_id = ? AND status = 'SCHEDULED' AND appointment_start_utc >= ?",
            'is',
            array((int)$room_id, now_dt())
        );
        if ($row && (int)$row['cnt'] > 0) {
            throw new Exception('این اتاق ' . to_persian_digits((int)$row['cnt'])
                . ' نوبت آیندهٔ ثبت‌شده دارد؛ ابتدا آن نوبت‌ها را جابه‌جا یا لغو کنید.');
        }
    }

    db_execute($db, "UPDATE rooms SET status = ? WHERE id = ?", 'si', array($status, (int)$room_id));

    audit_log_write($db, $actor_person_id, $actor_role_code,
        ($status === 'ACTIVE' ? 'ROOM_ENABLED' : 'ROOM_DISABLED'), 'room', (int)$room_id,
        array('new_status' => $status));
}

/** همهٔ اتاق‌ها (برای صفحهٔ مدیریت) */
function rooms_fetch_all($db)
{
    return db_select_all(
        $db,
        "SELECT id, public_id, name, room_number, capacity, status, created_at
         FROM rooms ORDER BY status ASC, room_number ASC, name ASC",
        '',
        array()
    );
}

/** اتاق‌های فعال (برای فهرست انتخابی) */
function rooms_fetch_active($db)
{
    return db_select_all(
        $db,
        "SELECT id, public_id, name, room_number, capacity
         FROM rooms WHERE status = 'ACTIVE' ORDER BY room_number ASC, name ASC",
        '',
        array()
    );
}

/** یافتن اتاق با شناسهٔ داخلی یا عمومی */
function room_find($db, $id_or_public)
{
    if (preg_match('/^rm_[0-9a-f]{20}$/', (string)$id_or_public)) {
        return db_select_one(
            $db,
            "SELECT id, public_id, name, room_number, capacity, status FROM rooms WHERE public_id = ?",
            's',
            array($id_or_public)
        );
    }
    return db_select_one(
        $db,
        "SELECT id, public_id, name, room_number, capacity, status FROM rooms WHERE id = ?",
        'i',
        array((int)$id_or_public)
    );
}

/** برچسب نمایشی اتاق */
function room_label($room)
{
    if (!$room) {
        return '—';
    }
    $label = $room['name'];
    if (!empty($room['room_number'])) {
        $label .= ' (' . to_persian_digits($room['room_number']) . ')';
    }
    return $label;
}
