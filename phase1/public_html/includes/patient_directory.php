<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — دفترچهٔ مراجعان (نسخهٔ ۴.۱)
 *
 *  جست‌وجو، نمایش و ویرایش اطلاعات هویتی و اداری مراجع.
 *
 *  مرز سختِ این فایل: هیچ تابعی در اینجا **محتوای بالینی** برنمی‌گرداند.
 *  نه متن یادداشت، نه پاسخ فرم. فقط هویت، پذیرش، نوبت و «وضعیت» فرم‌ها.
 *  شمارهٔ موبایل از این مسیر تغییر نمی‌کند (ADR-015: فرآیند دومرحله‌ای، فاز ۵).
 * ═══════════════════════════════════════════════════════════════════ */

/** حداکثر ردیف در فهرست مراجعان */
define('PATIENT_DIR_PAGE_SIZE', 50);

/** پیام یکسان برای «نیست» و «مال شما نیست» — مثل فرم‌ها */
define('PATIENT_DIR_DENY_MESSAGE',
    'مراجع یافت نشد یا شما اجازهٔ دسترسی به آن را ندارید.');


/* ─────────────────────────── جست‌وجو ─────────────────────────── */

/**
 * جست‌وجوی مراجعان بر اساس نام، موبایل یا کد ملی.
 * فقط اشخاصی که دست‌کم یک پذیرش دارند «مراجع» شمرده می‌شوند؛
 * پرسنلِ بدون پرونده در این فهرست نمی‌آیند.
 *
 * @param string   $term         عبارت جست‌وجو (خالی = آخرین مراجعان)
 * @param int|null $therapist_id اگر داده شود، فقط مراجعان همان درمانگر
 */
function patient_directory_search($db, $term, $therapist_id = null)
{
    $term = trim((string)$term);
    $where = array();
    $types = '';
    $params = array();

    /* بند درمانگر نخست می‌آید تا ترتیب ? با ترتیب پارامترها یکی بماند */
    if ($therapist_id !== null) {
        $where[] = '(a.referred_therapist_person_id = ?'
                 . ' OR EXISTS (SELECT 1 FROM clinical_cases cc'
                 . '             WHERE cc.admission_id = a.id'
                 . '               AND cc.responsible_therapist_person_id = ?))';
        $types .= 'ii';
        $params[] = (int)$therapist_id;
        $params[] = (int)$therapist_id;
    }

    if ($term !== '') {
        $digits = preg_replace('/\D/', '', to_latin_digits($term));
        if ($digits !== '' && strlen($digits) >= 4) {
            /* عدد: موبایل یا کد ملی */
            $where[] = '(p.mobile_number LIKE ? OR p.national_code LIKE ?)';
            $types .= 'ss';
            $params[] = '%' . $digits . '%';
            $params[] = '%' . $digits . '%';
        } else {
            $where[] = "(p.first_name LIKE ? OR p.last_name LIKE ?"
                     . " OR CONCAT(p.first_name, ' ', p.last_name) LIKE ?)";
            $types .= 'sss';
            $params[] = '%' . $term . '%';
            $params[] = '%' . $term . '%';
            $params[] = '%' . $term . '%';
        }
    }

    $sql =
        "SELECT p.id, p.public_id, p.first_name, p.last_name, p.mobile_number,
                p.national_code, p.status,
                COUNT(DISTINCT a.id) AS admission_count,
                MAX(a.created_at)    AS last_admission_at
           FROM persons p
           INNER JOIN admissions a ON a.patient_person_id = p.id
          " . (count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '') . "
          GROUP BY p.id, p.public_id, p.first_name, p.last_name,
                   p.mobile_number, p.national_code, p.status
          ORDER BY last_admission_at DESC
          LIMIT " . (int)PATIENT_DIR_PAGE_SIZE;

    if ($types === '') {
        return db_select_all($db, $sql, '', array());
    }
    return db_select_all($db, $sql, $types, $params);
}


/* ─────────────────────── خواندن یک مراجع ─────────────────────── */

/**
 * پروفایل مراجع. اگر $therapist_id داده شود و آن مراجع به این درمانگر
 * مربوط نباشد، null برمی‌گردد (تفکیک‌ناپذیر از «وجود ندارد»).
 */
function patient_directory_fetch($db, $public_id, $therapist_id = null)
{
    $person = db_select_one($db,
        "SELECT id, public_id, first_name, last_name, mobile_number,
                national_code, birth_date, gender, status, created_at
           FROM persons
          WHERE public_id = ?
          LIMIT 1",
        's', array((string)$public_id));

    if (!$person) {
        return null;
    }

    /* فقط کسی که پذیرش دارد در این دفترچه دیده می‌شود */
    $has = db_select_one($db,
        "SELECT id FROM admissions WHERE patient_person_id = ? LIMIT 1",
        'i', array((int)$person['id']));
    if (!$has) {
        return null;
    }

    if ($therapist_id !== null && !patient_directory_therapist_may_view(
            $db, (int)$therapist_id, (int)$person['id'])) {
        return null;
    }

    return $person;
}

/** آیا این مراجع به این درمانگر مربوط است؟ */
function patient_directory_therapist_may_view($db, $therapist_id, $person_id)
{
    $row = db_select_one($db,
        "SELECT a.id
           FROM admissions a
          WHERE a.patient_person_id = ?
            AND (a.referred_therapist_person_id = ?
                 OR EXISTS (SELECT 1 FROM clinical_cases cc
                             WHERE cc.admission_id = a.id
                               AND cc.responsible_therapist_person_id = ?))
          LIMIT 1",
        'iii', array((int)$person_id, (int)$therapist_id, (int)$therapist_id));
    return $row !== null;
}


/* ───────────────── پذیرش‌ها، نوبت‌ها، وضعیت فرم‌ها ───────────────── */

function patient_directory_admissions($db, $person_id)
{
    return db_select_all($db,
        "SELECT a.id, a.public_id, a.status, a.created_at, a.decided_at,
                a.companion_count,
                st.title            AS service_title,
                reason.label        AS referral_reason,
                decline.label       AS decline_reason,
                CONCAT(t.first_name, ' ', t.last_name) AS therapist_name,
                CONCAT(c.first_name, ' ', c.last_name) AS created_by_name
           FROM admissions a
           INNER JOIN service_types st ON st.id = a.service_type_id
           INNER JOIN persons t        ON t.id = a.referred_therapist_person_id
           INNER JOIN persons c        ON c.id = a.created_by_person_id
           LEFT  JOIN lookup_items reason  ON reason.id  = a.referral_reason_id
           LEFT  JOIN lookup_items decline ON decline.id = a.decline_reason_id
          WHERE a.patient_person_id = ?
          ORDER BY a.created_at DESC",
        'i', array((int)$person_id));
}

function patient_directory_appointments($db, $person_id, $limit = 30)
{
    if (!function_exists('phase2_ready') || !phase2_ready($db)) {
        return array();
    }
    return db_select_all($db,
        "SELECT ap.id, ap.public_id, ap.status,
                ap.appointment_start_utc, ap.appointment_end_utc,
                st.title AS service_title,
                r.name   AS room_title,
                CONCAT(t.first_name, ' ', t.last_name) AS therapist_name
           FROM appointments ap
           INNER JOIN admissions a   ON a.id = ap.admission_id
           INNER JOIN service_types st ON st.id = ap.service_type_id
           INNER JOIN persons t      ON t.id = ap.therapist_person_id
           LEFT  JOIN rooms r        ON r.id = ap.room_id
          WHERE a.patient_person_id = ?
          ORDER BY ap.appointment_start_utc DESC
          LIMIT " . (int)$limit,
        'i', array((int)$person_id));
}

/**
 * وضعیت فرم‌های مراجع — **فقط وضعیت، بدون هیچ پاسخی**.
 * منشی حق دیدن محتوا ندارد؛ مدیر هم فقط از مسیر admin/form_responses.php
 * که هر مشاهده را حسابرسی می‌کند.
 */
function patient_directory_forms($db, $person_id)
{
    if (!function_exists('phase4_ready') || !phase4_ready($db)) {
        return array();
    }
    return db_select_all($db,
        "SELECT fa.id, fa.public_id, fa.status, fa.assigned_at,
                fa.assignee_role, fa.assignment_source,
                ft.title AS template_title, ft.version,
                fs.submitted_at,
                CONCAT(pa.first_name, ' ', pa.last_name) AS assignee_name
           FROM form_assignments fa
           INNER JOIN form_templates ft ON ft.id = fa.template_id
           INNER JOIN persons pa        ON pa.id = fa.assignee_person_id
           LEFT  JOIN form_submissions fs ON fs.assignment_id = fa.id
          WHERE fa.patient_person_id = ?
          ORDER BY fa.assigned_at DESC",
        'i', array((int)$person_id));
}


/* ──────────────────────────── ویرایش ──────────────────────────── */

/**
 * ویرایش اطلاعات هویتی. موبایل عمداً در این تابع نیست.
 * هر تغییر در audit_log ثبت می‌شود — با نام فیلد و مقدار پیش و پس.
 * (نام و کد ملی دادهٔ هویتی‌اند، نه بالینی؛ ثبتشان در حسابرسی مجاز است.)
 *
 * @return array('changed' => آرایهٔ فیلدهای تغییرکرده)
 * @throws Exception با پیام فارسی قابل نمایش
 */
function patient_directory_update($db, $person_id, $input, $actor_person_id, $actor_role)
{
    /* عمداً person_find_by_id() را صدا نمی‌زنیم: آن تابع birth_date و gender
       را برنمی‌گرداند و مقایسهٔ «تغییر کرده یا نه» را خراب می‌کند. */
    $person = db_select_one($db,
        "SELECT id, first_name, last_name, national_code, birth_date, gender
           FROM persons WHERE id = ? LIMIT 1",
        'i', array((int)$person_id));
    if (!$person) {
        throw new Exception(PATIENT_DIR_DENY_MESSAGE);
    }

    $first = isset($input['first_name']) ? trim($input['first_name']) : '';
    $last  = isset($input['last_name'])  ? trim($input['last_name'])  : '';
    $code  = isset($input['national_code']) ? trim(to_latin_digits($input['national_code'])) : '';
    $code  = preg_replace('/\D/', '', $code);
    $gender = isset($input['gender']) ? trim($input['gender']) : '';
    $birth_raw = isset($input['birth_date']) ? trim($input['birth_date']) : '';

    if (!person_name_valid($first) || !person_name_valid($last)) {
        throw new Exception('نام و نام خانوادگی باید دست‌کم دو نویسه باشند.');
    }
    if ($code !== '' && !national_code_valid($code)) {
        throw new Exception('کد ملی معتبر نیست.');
    }
    if ($gender !== '' && $gender !== 'MALE' && $gender !== 'FEMALE') {
        throw new Exception('مقدار جنسیت معتبر نیست.');
    }

    $birth = patient_directory_parse_birth_date($birth_raw);
    if ($birth === false) {
        throw new Exception('تاریخ تولد باید به شکل ۱۳۷۰/۰۵/۱۲ و معتبر باشد.');
    }

    /* کد ملی تکراری؟ */
    if ($code !== '') {
        $dup = db_select_one($db,
            "SELECT id FROM persons WHERE national_code = ? AND id <> ? LIMIT 1",
            'si', array($code, (int)$person_id));
        if ($dup) {
            throw new Exception('این کد ملی برای شخص دیگری ثبت شده است.');
        }
    }

    $new = array(
        'first_name'    => $first,
        'last_name'     => $last,
        'national_code' => ($code === '' ? null : $code),
        'birth_date'    => $birth,
        'gender'        => ($gender === '' ? null : $gender),
    );

    $changed = array();
    foreach ($new as $field => $value) {
        $old = isset($person[$field]) ? $person[$field] : null;
        if ((string)$old !== (string)$value) {
            $changed[$field] = array('from' => $old, 'to' => $value);
        }
    }

    if (count($changed) === 0) {
        return array('changed' => array());
    }

    db_execute($db,
        "UPDATE persons
            SET first_name = ?, last_name = ?, national_code = ?,
                birth_date = ?, gender = ?
          WHERE id = ?",
        'sssssi',
        array($new['first_name'], $new['last_name'], $new['national_code'],
              $new['birth_date'], $new['gender'], (int)$person_id));

    audit_log_write($db, (int)$actor_person_id, $actor_role,
        'PATIENT_IDENTITY_UPDATED', 'person', (int)$person_id,
        array('fields' => array_keys($changed), 'detail' => $changed));

    return array('changed' => array_keys($changed));
}

/**
 * تاریخ تولد شمسی «۱۳۷۰/۰۵/۱۲» → میلادی «1991-08-03».
 * خالی → null. نامعتبر → false.
 */
function patient_directory_parse_birth_date($raw)
{
    $raw = trim(to_latin_digits((string)$raw));
    if ($raw === '') {
        return null;
    }
    $raw = str_replace(array('-', '.'), '/', $raw);
    if (!preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $raw, $m)) {
        return false;
    }
    $jy = (int)$m[1];
    $jm = (int)$m[2];
    $jd = (int)$m[3];

    if ($jy < 1250 || $jy > 1500 || $jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) {
        return false;
    }
    if ($jm > 6 && $jd > 30) {
        return false;
    }

    list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
    if (!checkdate($gm, $gd, $gy)) {
        return false;
    }
    $iso = sprintf('%04d-%02d-%02d', $gy, $gm, $gd);

    /* آینده قابل قبول نیست */
    if ($iso > gmdate('Y-m-d')) {
        return false;
    }
    return $iso;
}

/** میلادی «1991-08-03» → شمسی «۱۳۷۰/۰۵/۱۲» برای نمایش در فرم */
function patient_directory_birth_input($iso)
{
    if (!$iso || $iso === '0000-00-00') {
        return '';
    }
    $parts = explode('-', $iso);
    if (count($parts) !== 3) {
        return '';
    }
    list($jy, $jm, $jd) = gregorian_to_jalali((int)$parts[0], (int)$parts[1], (int)$parts[2]);
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}

/** برچسب وضعیت پذیرش */
function patient_directory_admission_label($status)
{
    if ($status === 'ACCEPTED')  { return 'پذیرفته شد'; }
    if ($status === 'DECLINED')  { return 'رد شد'; }
    return 'در انتظار درمانگر';
}

function patient_directory_admission_class($status)
{
    if ($status === 'ACCEPTED')  { return 'badge-success'; }
    if ($status === 'DECLINED')  { return 'badge-danger'; }
    return 'badge-warning';
}
