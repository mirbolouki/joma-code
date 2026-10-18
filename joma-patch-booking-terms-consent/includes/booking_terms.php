<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — ثبتِ نسخه و پذیرش ضوابطِ درخواست نوبت
 *
 *  متن همراه این پچ عمداً پیش‌نویس است و تا تأیید صریح صاحب سامانه
 *  فعال نمی‌شود. برای فعال‌سازی باید متن بازبینی، نسخه عوض و ثابت
 *  BOOKING_TERMS_APPROVED به true تغییر داده شود.
 * ═══════════════════════════════════════════════════════════════════ */

if (!defined('BOOKING_TERMS_MIGRATION_VERSION')) {
    define('BOOKING_TERMS_MIGRATION_VERSION', '6.3.0');
}
if (!defined('BOOKING_TERMS_VERSION')) {
    define('BOOKING_TERMS_VERSION', 'draft-2026-10-18-01');
}
if (!defined('BOOKING_TERMS_APPROVED')) {
    define('BOOKING_TERMS_APPROVED', false);
}

/** متن ثابت ضوابط به‌همراه hash آن؛ snapshot دقیقاً همین بایت‌هاست. */
function booking_terms_current_document()
{
    static $document = null;
    if ($document !== null) {
        return $document;
    }

    $path = __DIR__ . '/booking_terms_draft_fa.txt';
    $text = @file_get_contents($path);
    if ($text === false || trim($text) === '' || !preg_match('//u', $text)) {
        throw new Exception('متن ضوابط درخواست نوبت پیدا نشد یا UTF-8 معتبر نیست.');
    }

    $document = array(
        'version' => BOOKING_TERMS_VERSION,
        'text' => $text,
        'sha256' => hash('sha256', $text),
    );
    return $document;
}

/** متن فقط پس از تأیید انسانی و تغییر نسخه می‌تواند در سایت فعال شود. */
function booking_terms_document_is_approved()
{
    return BOOKING_TERMS_APPROVED === true
        && strpos(BOOKING_TERMS_VERSION, 'draft-') !== 0;
}

function booking_terms_table_exists($db)
{
    return db_select_one($db,
        "SELECT 1 AS present FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_terms_acceptances'
          LIMIT 1") !== null;
}

/** آماده‌بودنِ مهاجرتِ جدید؛ نسخهٔ پیش‌نیاز دوباره اجرا نمی‌شود. */
function booking_terms_ready($db)
{
    if (!function_exists('migration_applied')
        || !booking_terms_table_exists($db)
        || !db_select_one($db,
            "SELECT 1 AS present FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migrations' LIMIT 1")) {
        return false;
    }
    if (!migration_applied($db, BOOKING_TERMS_MIGRATION_VERSION)) {
        return false;
    }

    $columns = db_select_one($db,
        "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_terms_acceptances'
            AND COLUMN_NAME IN ('id','public_id','booking_request_id','terms_version',
                'terms_sha256','terms_snapshot','ack_general','ack_privacy_recording',
                'ack_deposit_cancellation','ack_adult_independence','accepted_mobile','accepted_at')");
    $indexes = db_select_one($db,
        "SELECT COUNT(DISTINCT INDEX_NAME) AS n FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_terms_acceptances'
            AND INDEX_NAME IN ('PRIMARY','uq_booking_terms_public_id',
                'uq_booking_terms_request','idx_booking_terms_accepted_at')");
    return $columns && (int)$columns['n'] === 12
        && $indexes && (int)$indexes['n'] === 4;
}

function booking_terms_ack_labels()
{
    return array(
        'ack_general' => 'متن کامل ضوابط عمومی درخواست نوبت را مطالعه کردم و می‌پذیرم.',
        'ack_privacy_recording' => 'بندهای حریم خصوصی و ضبط را خواندم و می‌پذیرم.',
        'ack_deposit_cancellation' => 'بندهای بیعانه، لغو و عدم حضور را خواندم و می‌پذیرم؛ می‌دانم مبلغ دقیق بیعانه در این فرم اعلام نشده است.',
        'ack_adult_independence' => 'بند استقلال تصمیم‌گیری مراجعان بالغِ دارای اهلیت را خواندم و می‌پذیرم.',
    );
}

/**
 * ساخت پیش‌نویس پذیرش در نشست از چک‌باکس‌های سرور-اعتبارسنجی‌شده.
 * زمان پذیرش فقط پس از OTP موفق و ثبت درخواست ایجاد می‌شود.
 */
function booking_terms_acceptance_payload_from_post($post)
{
    if (!booking_terms_document_is_approved()) {
        throw new Exception('متن ضوابط هنوز برای استفادهٔ زنده تأیید نشده است.');
    }

    $document = booking_terms_current_document();
    $acks = array();
    foreach (booking_terms_ack_labels() as $key => $label) {
        $acks[$key] = isset($post[$key]) && !is_array($post[$key])
            && (string)$post[$key] === '1' ? 1 : 0;
    }
    if (in_array(0, $acks, true)) {
        throw new Exception('برای ادامه، همهٔ گزینه‌های پذیرش ضوابط را جداگانه علامت بزنید.');
    }

    return array(
        'version' => $document['version'],
        'sha256' => $document['sha256'],
        'snapshot' => $document['text'],
        'acks' => $acks,
    );
}

/** بررسیِ ساختار payload نشست؛ هرگز کد OTP را نمی‌پذیرد یا ذخیره نمی‌کند. */
function booking_terms_acceptance_payload_is_valid($payload)
{
    if (!is_array($payload)
        || !isset($payload['version'], $payload['sha256'], $payload['snapshot'], $payload['acks'])
        || !is_array($payload['acks'])) {
        return false;
    }

    $version = (string)$payload['version'];
    $sha256 = strtolower((string)$payload['sha256']);
    $snapshot = (string)$payload['snapshot'];
    if ($version === '' || strlen($snapshot) === 0 || strlen($snapshot) > 100000
        || !preg_match('/^[a-f0-9]{64}$/', $sha256)
        || !preg_match('//u', $snapshot)) {
        return false;
    }
    if (!hash_equals(hash('sha256', $snapshot), $sha256)) {
        return false;
    }
    foreach (booking_terms_ack_labels() as $key => $label) {
        if (!isset($payload['acks'][$key]) || (int)$payload['acks'][$key] !== 1) {
            return false;
        }
    }
    return true;
}

/**
 * درج سابقهٔ پذیرش؛ باید در تراکنشِ ساخت booking_request فراخوانی شود.
 * شمارهٔ موبایل، نسخه، hash، snapshot، همهٔ تیک‌ها و زمان ذخیره می‌شوند.
 */
function booking_terms_acceptance_create($db, $booking_request_id, $mobile, $payload)
{
    if (!booking_terms_ready($db)) {
        throw new Exception('مهاجرتِ ثبت ضوابط هنوز اجرا نشده است.');
    }
    if (!booking_terms_document_is_approved()) {
        throw new Exception('متن ضوابط هنوز برای استفادهٔ زنده تأیید نشده است.');
    }
    if (!booking_terms_acceptance_payload_is_valid($payload)) {
        throw new Exception('اطلاعات پذیرش ضوابط معتبر نیست؛ درخواست دوباره ثبت نشد.');
    }

    $accepted_at = now_dt();
    $public_id = generate_public_id('ta');
    $acks = $payload['acks'];
    db_execute($db,
        "INSERT INTO booking_terms_acceptances
           (public_id, booking_request_id, terms_version, terms_sha256,
            terms_snapshot, ack_general, ack_privacy_recording,
            ack_deposit_cancellation, ack_adult_independence,
            accepted_mobile, accepted_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)",
        'sisssiiiiss',
        array($public_id, (int)$booking_request_id, (string)$payload['version'],
              strtolower((string)$payload['sha256']), (string)$payload['snapshot'],
              (int)$acks['ack_general'], (int)$acks['ack_privacy_recording'],
              (int)$acks['ack_deposit_cancellation'], (int)$acks['ack_adult_independence'],
              (string)$mobile, $accepted_at));

    return array('public_id' => $public_id, 'accepted_at' => $accepted_at);
}

/** سابقهٔ ضوابطِ متصل به یک درخواستِ اینترنتی. */
function booking_terms_acceptance_find_for_request($db, $booking_request_id)
{
    if (!booking_terms_ready($db)) {
        return null;
    }
    return db_select_one($db,
        "SELECT ta.*, br.public_id AS booking_request_public_id,
                br.converted_admission_id
           FROM booking_terms_acceptances ta
           INNER JOIN booking_requests br ON br.id = ta.booking_request_id
          WHERE ta.booking_request_id = ? LIMIT 1",
        'i', array((int)$booking_request_id));
}

/** سابقه‌های متصل به پذیرش‌های همین مراجع؛ برای پرونده و پورتال فقط‌خواندنی. */
function booking_terms_acceptances_for_person($db, $person_id, $limit = 100)
{
    if (!booking_terms_ready($db)) {
        return array();
    }
    $limit = max(1, min(200, (int)$limit));
    return db_select_all($db,
        "SELECT ta.*, br.public_id AS booking_request_public_id,
                br.requested_service_label, br.converted_admission_id
           FROM booking_terms_acceptances ta
           INNER JOIN booking_requests br ON br.id = ta.booking_request_id
           INNER JOIN admissions a ON a.id = br.converted_admission_id
          WHERE a.patient_person_id = ?
          ORDER BY ta.accepted_at DESC, ta.id DESC
          LIMIT " . $limit,
        'i', array((int)$person_id));
}
