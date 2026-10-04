<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما فاز ۲ — تعرفهٔ خدمات درمانگر
 *
 *  • مدیر: ایجاد، ویرایش و فعال/غیرفعال‌سازی تعرفه‌ها.
 *  • درمانگر: فقط مشاهدهٔ تعرفهٔ خودش؛ بدون ویرایش.
 *  • منشی: فقط مشاهدهٔ مبلغ لازم هنگام رزرو.
 *  • هر تغییر در audit_log ثبت می‌شود.
 *  • واحد ذخیره: ریال (currency = 'IRR'); نمایش به تومان.
 * ═══════════════════════════════════════════════════════════════════ */

if (!defined('TARIFF_MAX_RIAL')) { define('TARIFF_MAX_RIAL', 999999999); }

/** تبدیل ورودی تومان (با ارقام فارسی و جداکنندهٔ هزارگان) به ریال */
function tariff_toman_input_to_rial($value)
{
    $value = to_latin_digits((string)$value);
    $value = str_replace(array(',', '،', ' ', "\xC2\xA0"), '', $value);
    if ($value === '' || !preg_match('/^\d+$/', $value)) {
        return null;
    }
    $toman = (int)$value;
    if ($toman < 0 || $toman > TARIFF_MAX_RIAL / 10) {
        return null;
    }
    return $toman * 10;
}

/** ریال → رشتهٔ تومان با جداکنندهٔ هزارگان و ارقام فارسی */
function tariff_rial_to_toman_display($rial)
{
    if ($rial === null || $rial === '') {
        return '—';
    }
    $toman = (int)round(((float)$rial) / 10);
    return to_persian_digits(number_format($toman)) . ' تومان';
}

/** ریال → عدد خام تومان برای مقدار فیلد ورودی */
function tariff_rial_to_toman_value($rial)
{
    if ($rial === null || $rial === '') {
        return '';
    }
    return (string)(int)round(((float)$rial) / 10);
}

/**
 * ثبت یا به‌روزرسانی تعرفهٔ یک خدمت برای یک درمانگر.
 * $price_rial = null یعنی «تعرفه‌ای تعیین نشده» → رکورد غیرفعال می‌شود.
 */
function tariff_save($db, $therapist_person_id, $service_type_id, $price_rial,
                     $actor_person_id, $actor_role_code)
{
    $therapist_person_id = (int)$therapist_person_id;
    $service_type_id = (int)$service_type_id;

    $existing = db_select_one(
        $db,
        "SELECT id, price_per_session, is_active FROM therapist_service_tariffs
         WHERE therapist_person_id = ? AND service_type_id = ?",
        'ii',
        array($therapist_person_id, $service_type_id)
    );

    $now = now_dt();

    if ($price_rial === null) {
        if ($existing) {
            db_execute(
                $db,
                "UPDATE therapist_service_tariffs SET is_active = 0, updated_at = ? WHERE id = ?",
                'si',
                array($now, (int)$existing['id'])
            );
            audit_log_write($db, $actor_person_id, $actor_role_code, 'TARIFF_CLEARED',
                'therapist_service_tariff', (int)$existing['id'],
                array('service_type_id' => $service_type_id));
        }
        return;
    }

    if ($existing) {
        if ((int)$existing['price_per_session'] === (int)$price_rial && (int)$existing['is_active'] === 1) {
            return;
        }
        db_execute(
            $db,
            "UPDATE therapist_service_tariffs
             SET price_per_session = ?, is_active = 1, updated_at = ? WHERE id = ?",
            'dsi',
            array((float)$price_rial, $now, (int)$existing['id'])
        );
        audit_log_write($db, $actor_person_id, $actor_role_code, 'TARIFF_UPDATED',
            'therapist_service_tariff', (int)$existing['id'],
            array('service_type_id' => $service_type_id,
                  'old_price_rial' => (int)$existing['price_per_session'],
                  'new_price_rial' => (int)$price_rial));
        return;
    }

    $public_id = generate_public_id('tf');
    $res = db_execute(
        $db,
        "INSERT INTO therapist_service_tariffs
         (public_id, therapist_person_id, service_type_id, price_per_session, currency, is_active, created_at)
         VALUES (?,?,?,?,'IRR',1,?)",
        'siids',
        array($public_id, $therapist_person_id, $service_type_id, (float)$price_rial, $now)
    );
    audit_log_write($db, $actor_person_id, $actor_role_code, 'TARIFF_CREATED',
        'therapist_service_tariff', (int)$res['insert_id'],
        array('service_type_id' => $service_type_id, 'price_rial' => (int)$price_rial));
}

/** تعرفه‌های یک درمانگر، کنار همهٔ خدمات فعال (حتی خدماتی که تعرفه ندارند) */
function tariffs_fetch_for_therapist($db, $therapist_person_id, $only_active = false)
{
    $sql = "SELECT st.id AS service_type_id, st.code AS service_code, st.title AS service_title,
                   st.default_duration_minutes,
                   t.id AS tariff_id, t.public_id AS tariff_public_id,
                   t.price_per_session, t.is_active, t.updated_at
            FROM service_types st
            LEFT JOIN therapist_service_tariffs t
                   ON t.service_type_id = st.id AND t.therapist_person_id = ?
            WHERE st.is_active = 1
            ORDER BY st.id";
    $rows = db_select_all($db, $sql, 'i', array((int)$therapist_person_id));

    if (!$only_active) {
        return $rows;
    }
    $out = array();
    foreach ($rows as $r) {
        if ($r['tariff_id'] !== null && (int)$r['is_active'] === 1) {
            $out[] = $r;
        }
    }
    return $out;
}

/** همهٔ تعرفه‌های ثبت‌شده در کلینیک (نمای مدیر) */
function tariffs_fetch_all($db)
{
    return db_select_all(
        $db,
        "SELECT t.id, t.public_id, t.price_per_session, t.is_active, t.updated_at, t.created_at,
                p.first_name, p.last_name, p.public_id AS therapist_public_id,
                st.title AS service_title
         FROM therapist_service_tariffs t
         INNER JOIN persons p ON p.id = t.therapist_person_id
         INNER JOIN service_types st ON st.id = t.service_type_id
         ORDER BY p.last_name ASC, p.first_name ASC, st.id ASC",
        '',
        array()
    );
}

/** مدیر: فعال/غیرفعال کردن یک تعرفه */
function tariff_set_active($db, $tariff_id, $is_active, $actor_person_id, $actor_role_code)
{
    $is_active = $is_active ? 1 : 0;
    db_execute(
        $db,
        "UPDATE therapist_service_tariffs SET is_active = ?, updated_at = ? WHERE id = ?",
        'isi',
        array($is_active, now_dt(), (int)$tariff_id)
    );
    audit_log_write($db, $actor_person_id, $actor_role_code,
        ($is_active ? 'TARIFF_ENABLED' : 'TARIFF_DISABLED'),
        'therapist_service_tariff', (int)$tariff_id, array('is_active' => $is_active));
}

/** تعرفهٔ فعالِ یک خدمت برای یک درمانگر (یا null) */
function tariff_find_active($db, $therapist_person_id, $service_type_id)
{
    return db_select_one(
        $db,
        "SELECT id, public_id, price_per_session FROM therapist_service_tariffs
         WHERE therapist_person_id = ? AND service_type_id = ? AND is_active = 1",
        'ii',
        array((int)$therapist_person_id, (int)$service_type_id)
    );
}

/** یافتن تعرفه با شناسهٔ عمومی */
function tariff_find_by_public_id($db, $public_id)
{
    return db_select_one(
        $db,
        "SELECT t.id, t.public_id, t.therapist_person_id, t.service_type_id,
                t.price_per_session, t.is_active
         FROM therapist_service_tariffs t WHERE t.public_id = ?",
        's',
        array($public_id)
    );
}
