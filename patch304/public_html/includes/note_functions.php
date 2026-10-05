<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — فاز ۳: یادداشت‌های محرمانهٔ درمانگر
 *
 *  ⚠️ قاعدهٔ بنیادی معماری — بدون استثنا:
 *      هر پرس‌وجوی خواندن یادداشت باید هم‌زمان دو شرط داشته باشد:
 *          WHERE clinical_case_id = ?  AND  author_person_id = ?
 *      مقدار شرط دوم همیشه از نشست کاربر واردشده می‌آید،
 *      هرگز از پارامتر ورودی (GET/POST).
 *
 *      در این فایل هیچ تابعی وجود ندارد که یادداشت را تنها با شناسه و
 *      بدون شرط نویسنده برگرداند — حتی برای مصرف داخلی. اگر روزی چنین
 *      تابعی لازم شد، یعنی قاعدهٔ دسترسی تغییر کرده و نیازمند تصمیم
 *      تازهٔ مالک است.
 *
 *  رمزنگاری: ندارد (تصمیم مالک). ریسک‌های P1 و P2 در سند پذیرفته شده‌اند.
 * ═══════════════════════════════════════════════════════════════════ */

define('NOTE_MAX_CHARS', 20000);
define('NOTE_MIN_CHARS', 1);
define('NOTE_EDIT_WINDOW_HOURS', 24);
define('NOTE_RETRACTION_LIST', 'note_retraction_reason');

/** فاز ۳ روی این پایگاه داده نصب شده است؟ */
function phase3_ready($db)
{
    static $ready = null;
    if ($ready === null) {
        $ready = false;
        $res = @mysqli_query($db, "SHOW TABLES LIKE 'confidential_notes'");
        if ($res) {
            $ready = (mysqli_num_rows($res) > 0);
            mysqli_free_result($res);
        }
    }
    return $ready;
}

/** ثانیه‌های سپری‌شده از یک زمان UTC ذخیره‌شده در پایگاه داده */
function note_seconds_since($stored_utc)
{
    $ts = strtotime($stored_utc . ' UTC');
    if ($ts === false) {
        return PHP_INT_MAX;   /* زمان نامعتبر ⇒ پنجرهٔ ویرایش بسته فرض می‌شود */
    }
    return time() - $ts;
}

/** آیا یادداشت هنوز در پنجرهٔ ۲۴ ساعتهٔ ویرایش است؟ */
function note_is_editable($note)
{
    if (!$note || $note['status'] !== 'ACTIVE') {
        return false;
    }
    return note_seconds_since($note['created_at']) <= (NOTE_EDIT_WINDOW_HOURS * 3600);
}

/** مهلت باقی‌مانده تا پایان پنجرهٔ ویرایش، به‌صورت متن فارسی */
function note_edit_window_left($note)
{
    $left = (NOTE_EDIT_WINDOW_HOURS * 3600) - note_seconds_since($note['created_at']);
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

/** اعتبارسنجی متن یادداشت؛ بازگشت: پیام خطا یا null */
function note_validate_text($text)
{
    $trimmed = trim($text);
    if ($trimmed === '') {
        return 'متن یادداشت را بنویسید.';
    }
    $len = mb_strlen($trimmed, 'UTF-8');
    if ($len > NOTE_MAX_CHARS) {
        return 'یادداشت نمی‌تواند بیش از ' . to_persian_digits(NOTE_MAX_CHARS)
            . ' نویسه باشد. متن کنونی ' . to_persian_digits($len) . ' نویسه است.';
    }
    return null;
}

/* ─────────────────────────────────────────────────────────────────────
 *  ۱) ساخت یادداشت
 * ─────────────────────────────────────────────────────────────────── */

/**
 * یادداشت تازه روی پروندهٔ خودِ درمانگر.
 * پیش‌شرط: پرونده ACTIVE باشد و درمانگر مسئولش همین کاربر باشد.
 * بازگشت: آرایهٔ یادداشت ساخته‌شده.
 */
function note_create($db, $clinical_case_id, $text, $author_person_id)
{
    $clinical_case_id = (int)$clinical_case_id;
    $author_person_id = (int)$author_person_id;

    $error = note_validate_text($text);
    if ($error !== null) {
        throw new Exception($error);
    }
    $text = trim($text);

    /* پرونده باید مال همین درمانگر و فعال باشد */
    $case = db_select_one(
        $db,
        "SELECT id, public_id, status, responsible_therapist_person_id
           FROM clinical_cases WHERE id = ? LIMIT 1",
        'i',
        array($clinical_case_id)
    );
    if (!$case) {
        throw new Exception('پرونده یافت نشد.');
    }
    if ((int)$case['responsible_therapist_person_id'] !== $author_person_id) {
        throw new Exception('شما درمانگر مسئول این پرونده نیستید.');
    }
    if ($case['status'] !== 'ACTIVE') {
        throw new Exception('این پرونده فعال نیست و یادداشت تازه نمی‌پذیرد.');
    }

    $char_count = mb_strlen($text, 'UTF-8');
    $public_id = generate_public_id('nt');
    $created_at = now_dt();

    /* ترتیب پارامترها: s=public_id, i=case, i=author, s=text, i=count, s=created_at */
    $res = db_execute(
        $db,
        "INSERT INTO confidential_notes
            (public_id, clinical_case_id, author_person_id, note_text, character_count, created_at)
         VALUES (?,?,?,?,?,?)",
        'siisis',
        array($public_id, $clinical_case_id, $author_person_id, $text, $char_count, $created_at)
    );

    $note_id = (int)$res['insert_id'];

    audit_log_write(
        $db, $author_person_id, ROLE_THERAPIST, 'NOTE_CREATED', 'confidential_note', $note_id,
        array('note_public_id' => $public_id,
              'case_public_id' => $case['public_id'],
              'char_count'     => $char_count)
    );

    return note_fetch($db, $clinical_case_id, $public_id, $author_person_id, false);
}

/* ─────────────────────────────────────────────────────────────────────
 *  ۲) خواندن یادداشت — تنها نقطهٔ ورود مجاز
 * ─────────────────────────────────────────────────────────────────── */

/**
 * خواندن یک یادداشت با شناسهٔ عمومی.
 *
 * دو شرط دسترسی هم‌زمان اعمال می‌شود. اگر یادداشت وجود نداشته باشد یا
 * متعلق به شخص دیگری باشد، در هر دو حالت یک پیام یکسان برگردانده می‌شود
 * تا از افشای وجود/عدم‌وجود یادداشتِ دیگران جلوگیری شود.
 *
 * @param bool $count_view آیا این خواندن به‌عنوان «مشاهده» شمرده شود؟
 */
function note_fetch($db, $clinical_case_id, $note_public_id, $viewer_person_id, $count_view = true)
{
    $clinical_case_id = (int)$clinical_case_id;
    $viewer_person_id = (int)$viewer_person_id;

    $note = db_select_one(
        $db,
        "SELECT * FROM confidential_notes
          WHERE public_id = ? AND clinical_case_id = ? AND author_person_id = ?
          LIMIT 1",
        'sii',
        array((string)$note_public_id, $clinical_case_id, $viewer_person_id)
    );

    if (!$note) {
        audit_log_write(
            $db, $viewer_person_id,
            isset($_SESSION['active_role_code']) ? $_SESSION['active_role_code'] : null,
            'NOTE_ACCESS_DENIED', 'confidential_note', null,
            array('requested_public_id' => (string)$note_public_id,
                  'case_id'             => $clinical_case_id)
        );
        throw new Exception('یادداشت یافت نشد یا شما اجازهٔ دسترسی به آن را ندارید.');
    }

    if ($count_view) {
        db_execute(
            $db,
            "UPDATE confidential_notes
                SET last_accessed_at = ?, view_count = view_count + 1
              WHERE id = ?",
            'si',
            array(now_dt(), (int)$note['id'])
        );
        $note['view_count'] = (int)$note['view_count'] + 1;
        $note['last_accessed_at'] = now_dt();
    }

    return $note;
}

/* ─────────────────────────────────────────────────────────────────────
 *  ۳) فهرست یادداشت‌های یک پرونده — فقط یادداشت‌های خودِ بیننده
 * ─────────────────────────────────────────────────────────────────── */

/**
 * @param bool $include_retracted یادداشت‌های باطل‌شده هم بیایند؟
 *        متن یادداشت در این فهرست خوانده نمی‌شود (فقط فراداده).
 */
function notes_fetch_for_case($db, $clinical_case_id, $author_person_id, $include_retracted = false)
{
    $sql = "SELECT id, public_id, status, character_count, view_count,
                   created_at, edited_at, edit_count, retracted_at, retraction_reason_id,
                   LEFT(note_text, 160) AS preview_text
              FROM confidential_notes
             WHERE clinical_case_id = ? AND author_person_id = ?";
    if (!$include_retracted) {
        $sql .= " AND status = 'ACTIVE'";
    }
    $sql .= " ORDER BY created_at DESC, id DESC";

    return db_select_all($db, $sql, 'ii',
        array((int)$clinical_case_id, (int)$author_person_id));
}

/** شمارش یادداشت‌های فعال یک نویسنده روی یک پرونده */
function notes_count_for_case($db, $clinical_case_id, $author_person_id)
{
    $row = db_select_one(
        $db,
        "SELECT COUNT(*) AS cnt FROM confidential_notes
          WHERE clinical_case_id = ? AND author_person_id = ? AND status = 'ACTIVE'",
        'ii',
        array((int)$clinical_case_id, (int)$author_person_id)
    );
    return $row ? (int)$row['cnt'] : 0;
}

/* ─────────────────────────────────────────────────────────────────────
 *  ۴) ویرایش — تنها در پنجرهٔ ۲۴ ساعته
 * ─────────────────────────────────────────────────────────────────── */

function note_edit($db, $clinical_case_id, $note_public_id, $new_text, $editor_person_id)
{
    $note = note_fetch($db, $clinical_case_id, $note_public_id, $editor_person_id, false);

    if ($note['status'] !== 'ACTIVE') {
        throw new Exception('یادداشت باطل‌شده قابل ویرایش نیست.');
    }
    if (!note_is_editable($note)) {
        throw new Exception('مهلت ویرایش این یادداشت به پایان رسیده است. '
            . 'یادداشت فقط تا ' . to_persian_digits(NOTE_EDIT_WINDOW_HOURS)
            . ' ساعت پس از ثبت قابل ویرایش است. می‌توانید آن را باطل کنید و یادداشت تازه بنویسید.');
    }

    $error = note_validate_text($new_text);
    if ($error !== null) {
        throw new Exception($error);
    }
    $new_text = trim($new_text);
    $char_count = mb_strlen($new_text, 'UTF-8');

    db_execute(
        $db,
        "UPDATE confidential_notes
            SET note_text = ?, character_count = ?, edited_at = ?, edit_count = edit_count + 1
          WHERE id = ? AND author_person_id = ?",
        'sisii',
        array($new_text, $char_count, now_dt(), (int)$note['id'], (int)$editor_person_id)
    );

    audit_log_write(
        $db, (int)$editor_person_id, ROLE_THERAPIST, 'NOTE_EDITED', 'confidential_note', (int)$note['id'],
        array('note_public_id'  => $note['public_id'],
              'old_char_count'  => (int)$note['character_count'],
              'new_char_count'  => $char_count)
    );

    return true;
}

/* ─────────────────────────────────────────────────────────────────────
 *  ۵) ابطال — حذف منطقی، با دلیل اجباری از فهرست قابل‌ویرایش
 * ─────────────────────────────────────────────────────────────────── */

function note_retract($db, $clinical_case_id, $note_public_id, $reason_id, $actor_person_id)
{
    $note = note_fetch($db, $clinical_case_id, $note_public_id, $actor_person_id, false);

    if ($note['status'] === 'RETRACTED') {
        throw new Exception('این یادداشت پیش‌تر باطل شده است.');
    }

    $reason = lookup_item_find($db, NOTE_RETRACTION_LIST, (int)$reason_id);
    if (!$reason) {
        throw new Exception('دلیل ابطال را از فهرست انتخاب کنید.');
    }

    db_execute(
        $db,
        "UPDATE confidential_notes
            SET status = 'RETRACTED', retracted_at = ?, retraction_reason_id = ?
          WHERE id = ? AND author_person_id = ? AND status = 'ACTIVE'",
        'siii',
        array(now_dt(), (int)$reason['id'], (int)$note['id'], (int)$actor_person_id)
    );

    audit_log_write(
        $db, (int)$actor_person_id, ROLE_THERAPIST, 'NOTE_RETRACTED', 'confidential_note', (int)$note['id'],
        array('note_public_id' => $note['public_id'],
              'reason_code'    => $reason['item_code'],
              'reason_label'   => $reason['label'])
    );

    return true;
}

/* ─────────────────────────────────────────────────────────────────────
 *  ۶) هشدار ارجاع — پرونده‌هایی که از درمانگر گرفته شده‌اند
 *
 *  تصمیم مالک: هم هشدار دیدنی، هم ثبت در حسابرسی.
 *  هیچ جدول اعلان تازه‌ای ساخته نمی‌شود؛ وضعیت از روی داده استنتاج
 *  می‌شود: پرونده‌هایی که من روی آن‌ها یادداشت فعال دارم ولی دیگر
 *  درمانگر مسئولشان نیستم.
 * ─────────────────────────────────────────────────────────────────── */

function notes_orphaned_cases_for_therapist($db, $therapist_person_id)
{
    if (!phase3_ready($db)) {
        return array();
    }
    return db_select_all(
        $db,
        "SELECT c.id, c.public_id,
                p.first_name, p.last_name,
                COUNT(n.id) AS note_count
           FROM confidential_notes n
           INNER JOIN clinical_cases c ON c.id = n.clinical_case_id
           INNER JOIN persons p ON p.id = c.patient_person_id
          WHERE n.author_person_id = ?
            AND n.status = 'ACTIVE'
            AND c.responsible_therapist_person_id <> ?
          GROUP BY c.id, c.public_id, p.first_name, p.last_name
          ORDER BY c.public_id",
        'ii',
        array((int)$therapist_person_id, (int)$therapist_person_id)
    );
}

/**
 * هنگام ارجاع مجدد پرونده فراخوانی می‌شود: رویداد را برای درمانگر پیشین
 * در حسابرسی ثبت می‌کند. هیچ متنی از یادداشت ثبت نمی‌شود.
 */
function therapist_case_transfer_warning($db, $clinical_case_id, $old_therapist_id, $new_therapist_id)
{
    if (!phase3_ready($db)) {
        return 0;
    }
    $count = notes_count_for_case($db, $clinical_case_id, $old_therapist_id);
    if ($count > 0) {
        audit_log_write(
            $db, (int)$old_therapist_id, ROLE_THERAPIST, 'CASE_TRANSFERRED_AWAY',
            'clinical_case', (int)$clinical_case_id,
            array('note_count' => $count, 'new_therapist_person_id' => (int)$new_therapist_id)
        );
    }
    return $count;
}

/* ─────────────────────────────────────────────────────────────────────
 *  کمکی‌های نمایشی
 * ─────────────────────────────────────────────────────────────────── */

function note_status_label($status)
{
    return ($status === 'RETRACTED') ? 'باطل‌شده' : 'فعال';
}

function note_status_class($status)
{
    return ($status === 'RETRACTED') ? 'badge-muted' : 'badge-success';
}

/**
 * ۳۰ یادداشت اخیرِ خودِ این درمانگر، در همهٔ پرونده‌هایش — برای میان‌بر
 * «آخرین یادداشت‌های من».
 *
 * دو فیلتر هم‌زمان، مثل همهٔ خواندن‌های فاز ۳:
 *   • `author_person_id` = خودِ بیننده (یادداشت مال خودش باشد)
 *   • `responsible_therapist_person_id` = خودِ بیننده (پرونده هنوز مال او باشد)
 *
 * فیلتر دوم عمدی است: اگر پرونده‌ای ارجاع مجدد شده باشد، یادداشت‌های آن از این
 * فهرست بیرون می‌روند تا پیوندِ شکسته ساخته نشود — چون `note_view.php` هم به
 * همان دلیل اجازهٔ ورود نمی‌دهد. درمانگر پیشین از کادر هشدار
 * `therapist_case_transfer_warning()` در کارتابل از این موضوع باخبر می‌شود.
 *
 * متن کامل برگردانده نمی‌شود؛ فقط ۱۶۰ نویسهٔ نخست برای پیش‌نمایش.
 * این تابع `view_count` را بالا نمی‌برد — شمارش فقط در `note_fetch()` انجام
 * می‌شود، یعنی وقتی متن کامل واقعاً دیده شود.
 */
function notes_recent_for_therapist($db, $author_person_id, $limit = 30)
{
    $limit = (int)$limit;
    if ($limit < 1) { $limit = 1; }
    if ($limit > 100) { $limit = 100; }

    return db_select_all(
        $db,
        "SELECT n.public_id, n.status, n.character_count, n.view_count,
                n.created_at, n.edited_at, n.edit_count, n.retracted_at,
                LEFT(n.note_text, 160) AS preview_text,
                c.public_id AS case_public_id,
                p.first_name, p.last_name
           FROM confidential_notes n
           INNER JOIN clinical_cases c ON c.id = n.clinical_case_id
           INNER JOIN persons p ON p.id = c.patient_person_id
          WHERE n.author_person_id = ?
            AND c.responsible_therapist_person_id = ?
          ORDER BY n.created_at DESC, n.id DESC
          LIMIT " . $limit,
        'ii',
        array((int)$author_person_id, (int)$author_person_id)
    );
}
