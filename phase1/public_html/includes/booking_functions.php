<?php
/* ═════════════════════════════════════════════════════════════════════
 *  جوما — وصلهٔ ۴.۲.۰: موتور «درخواست نوبت اینترنتی»
 *
 *  اصل حاکم بر کل این فایل:
 *      «درخواست نوبت» ≠ «نوبت».
 *
 *  هیچ تابعی در این فایل شخص نمی‌سازد، پذیرش نمی‌سازد و نوبت نمی‌دهد.
 *  تنها کاری که می‌کند، نوشتن یک ردیف در جدول قرنطینهٔ booking_requests
 *  است. تبدیل آن به پذیرش، کارِ یک انسان است و از مسیر موجود
 *  reception/admission_new.php انجام می‌شود.
 *
 *  سه لایهٔ سقف، از تنگ به گشاد:
 *      ۱) هر موبایل      ۳ کد در ۱۰ دقیقه   (قاعدهٔ موجود فاز ۲)
 *      ۲) هر IP         ۱۰ کد در ۱ ساعت    (تازه — CGNAT اپراتورها)
 *      ۳) سراسری       ۱۰۰ پیامک در شبانه‌روز (تازه — سقف هزینه)
 *
 *  هر سه پیش از ارسال پیامک و داخل یک تراکنش بررسی می‌شوند.
 * ═══════════════════════════════════════════════════════════════════ */

/* کاربرد کد یک‌بارمصرف در ENUM ستون otp_codes.purpose */
define('BOOKING_OTP_PURPOSE', 'BOOKING_REQUEST');

/* سقف‌ها */
define('BOOKING_MOBILE_CAP',       3);     /* در ۱۰ دقیقه */
define('BOOKING_MOBILE_WINDOW',    600);   /* ثانیه */
define('BOOKING_IP_CAP',           10);    /* در ۱ ساعت */
define('BOOKING_IP_WINDOW',        3600);  /* ثانیه */
define('BOOKING_GLOBAL_DAILY_CAP', 100);   /* پیامک در شبانه‌روز */

/* نگهداری: درخواست‌های رسیدگی‌نشده پس از ۱۸۰ روز EXPIRED می‌شوند */
define('BOOKING_RETENTION_DAYS', 180);

/* فهرست‌های مرجع (ADR-010) */
define('BOOKING_SERVICE_LIST', 'booking_service_option');
define('BOOKING_REJECT_LIST',  'booking_reject_reason');

/* حداکثر طول یادداشت آزاد بازدیدکننده — با ستون دیتابیس یکی است */
define('BOOKING_NOTE_MAX', 300);


/* ═══════════════════════════════════════════════════════════════════
 *  آمادگی
 * ═══════════════════════════════════════════════════════════════════ */

/** آیا مهاجرت ۴.۲.۰ روی این پایگاه داده اجرا شده است؟ */
function phase4_2_ready($db)
{
    static $ready = false;
    if ($ready) {
        return true;
    }
    $res = @mysqli_query($db, "SHOW TABLES LIKE 'booking_requests'");
    if ($res) {
        $ready = (mysqli_num_rows($res) > 0);
        mysqli_free_result($res);
    }
    return $ready;
}

/** شمارهٔ تماس کلینیک برای نمایش در پیام‌های «الان نمی‌توانیم» */
function booking_clinic_phone()
{
    return defined('CLINIC_PHONE') ? CLINIC_PHONE : '';
}


/* ═══════════════════════════════════════════════════════════════════
 *  نشانی شبکه
 * ═══════════════════════════════════════════════════════════════════ */

/**
 * نشانی IP بازدیدکننده.
 *
 * عمداً هیچ هدر HTTP خوانده نمی‌شود. پروب ۱۴۰۵/۰۷/۱۴ روی
 * my.mirbolouki.com ثابت کرد هیچ پروکسی معکوس یا CDN ای جلوی سرور
 * نیست (REMOTE_ADDR عمومی، صفر هدر پروکسی، LiteSpeed مستقیم).
 * در چنین وضعی هر X-Forwarded-For که برسد ساختهٔ خودِ فرستنده است و
 * باور کردنش سقف IP را به‌کلی بی‌اثر می‌کند: مهاجم در هر درخواست یک
 * مقدار تازه می‌گذارد و هیچ‌وقت به سقف نمی‌خورد.
 *
 * ★ اگر روزی آروان‌کلاد یا کلادفلر جلوی دامنه رفت، پیش از آن باید
 *   این تابع تغییر کند. booking_ip_trustworthy() پایین‌تر این حالت
 *   را تشخیص می‌دهد و سقف را «باز» می‌کند نه «بسته».
 */
function booking_client_ip()
{
    return isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
}

/**
 * آیا می‌توان روی این IP برای سقف‌گذاری حساب کرد؟
 *
 * اگر REMOTE_ADDR خصوصی باشد یعنی پروکسی‌ای ظاهر شده که در طراحی
 * دیده نشده بود. آن‌وقت همهٔ بازدیدکننده‌ها یک نشانی مشترک دارند و
 * اعمال سقف IP، به‌جای یک مهاجم، کل مراجعان کلینیک را با هم قفل
 * می‌کند. در آن حالت این لایه کنار گذاشته می‌شود — دو لایهٔ دیگر
 * (موبایل و سقف روزانه) سر جایشان‌اند.
 */
function booking_ip_trustworthy($ip)
{
    if ($ip === '') {
        return false;
    }
    return (filter_var($ip, FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false);
}

/**
 * هش برگشت‌ناپذیر نشانی IP.
 *
 * چرا HMAC و نه SHA-256 ساده: فضای IPv4 تنها ۲^۳۲ حالت دارد؛ یک
 * جدول رنگین‌کمانی کامل در چند ساعت ساخته می‌شود و هش ساده عملاً
 * همان IP خام است. کلید مخفی این حمله را بی‌معنا می‌کند.
 *
 * برای IPv6 پیش از هش، نشانی به پیشوند /64 کوتاه می‌شود؛ وگرنه
 * یک نفر با عوض‌کردن ۶۴ بیت پایانی بی‌نهایت «کاربر تازه» می‌سازد.
 * محاسبه حتماً دودویی است: explode(':') روی نشانی فشرده مثل
 * «2001:db8::1» نتیجهٔ غلط می‌دهد.
 */
function booking_ip_hash($ip)
{
    $bin = @inet_pton($ip);
    if ($bin !== false && strlen($bin) === 16) {
        $prefix = @inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8));
        if ($prefix !== false) {
            $ip = $prefix;
        }
    }
    return hash_hmac('sha256', $ip, IP_HASH_KEY);
}

/** هش مرورگر — فقط برای تشخیص الگوی ربات؛ خودِ رشته ذخیره نمی‌شود. */
function booking_user_agent_hash()
{
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
    if ($ua === '') {
        return null;
    }
    return hash_hmac('sha256', $ua, IP_HASH_KEY);
}


/* ═══════════════════════════════════════════════════════════════════
 *  گزینه‌های زمان — ت-۱
 *
 *  تقویم واقعی هیچ‌وقت به بیرون نشان داده نمی‌شود. بازدیدکننده فقط
 *  «ترجیح» می‌دهد؛ وقت قطعی را منشی طی تماس تلفنی اعلام می‌کند.
 * ═══════════════════════════════════════════════════════════════════ */

/** روزهای هفته، با شروع از شنبه. پنجشنبه آخرین روز کاری است. */
function booking_day_options()
{
    return array(
        'any'       => 'فرقی ندارد',
        'saturday'  => 'شنبه',
        'sunday'    => 'یک‌شنبه',
        'monday'    => 'دوشنبه',
        'tuesday'   => 'سه‌شنبه',
        'wednesday' => 'چهارشنبه',
        'thursday'  => 'پنج‌شنبه',
    );
}

/**
 * بازه‌های زمانی.
 *
 * «شب» وجود ندارد: کلینیک ساعت ۲۰ بسته می‌شود، پس گزینه‌ای که
 * هیچ‌وقت قابل تحقق نیست نباید به کاربر نشان داده شود.
 */
function booking_window_options()
{
    return array(
        'any'       => 'فرقی ندارد',
        'morning'   => 'صبح (۹ تا ۱۳)',
        'afternoon' => 'عصر (۱۳ تا ۲۰)',
    );
}

/** ترکیب روز، بازه و متن آزاد در یک رشتهٔ کوتاه برای منشی */
function booking_preferred_text($day_code, $window_code, $free_text)
{
    $days = booking_day_options();
    $wins = booking_window_options();

    $parts = array();
    if ($day_code !== 'any' && isset($days[$day_code])) {
        $parts[] = $days[$day_code];
    }
    if ($window_code !== 'any' && isset($wins[$window_code])) {
        $parts[] = $wins[$window_code];
    }
    if ($parts === array()) {
        $parts[] = 'فرقی ندارد';
    }

    $text = implode(' — ', $parts);
    $free_text = trim((string)$free_text);
    if ($free_text !== '') {
        $text .= ' («' . $free_text . '»)';
    }
    if (mb_strlen($text, 'UTF-8') > 100) {
        $text = mb_substr($text, 0, 100, 'UTF-8');
    }
    return $text;
}


/* ═══════════════════════════════════════════════════════════════════
 *  سقف‌ها و ارسال کد — الزام ا-۶ (اتمیک بودن)
 * ═══════════════════════════════════════════════════════════════════ */

/**
 * بررسی سه سقف و ساخت ردیف کد، همه داخل یک تراکنش.
 *
 * چرا تراکنش لازم است: اگر شمارش و درج جدا باشند، ده درخواست هم‌زمان
 * هر ده‌تا «۹ تا دیده‌ام، پس جا هست» می‌گویند و ده پیامک می‌رود.
 * قفل FOR UPDATE بازهٔ نمایه را می‌بندد تا رقیب‌ها پشت سر هم اجرا شوند.
 *
 * پیامک عمداً *پس از* commit فرستاده می‌شود — همان ترتیبی که
 * form_auto_assign_intake دارد. اگر پیش از commit بفرستیم و تراکنش
 * برگردد، کاربر پیامکی دارد که هیچ ردیفی پشتش نیست.
 *
 * خروجی: array('ok', 'message', 'reason')
 *   reason ∈ '', 'mobile', 'ip', 'global', 'cooldown', 'sms'
 */
function booking_otp_send($db, $mobile, $ip)
{
    $now        = now_dt();
    $ip_hash    = booking_ip_hash($ip);
    $ip_usable  = booking_ip_trustworthy($ip);
    $code       = otp_generate_code();
    $code_hash  = password_hash($code, PASSWORD_BCRYPT);
    $ttl        = defined('OTP_TTL_SECONDS') ? (int)OTP_TTL_SECONDS : 120;

    mysqli_begin_transaction($db);
    try {
        /* ── لایهٔ ۱: همین شماره، ۳ کد در ۱۰ دقیقه ─────────────────
           بدون قید purpose: اگر کسی با یک شماره هم‌زمان ورود مراجع و
           درخواست نوبت بزند، باید در یک سبد شمرده شود. */
        $since_m = gmdate('Y-m-d H:i:s', time() - BOOKING_MOBILE_WINDOW);
        $row = db_select_one($db,
            "SELECT COUNT(*) AS cnt FROM otp_codes
              WHERE mobile_number = ? AND created_at > ? FOR UPDATE",
            'ss', array($mobile, $since_m));
        if ($row && (int)$row['cnt'] >= BOOKING_MOBILE_CAP) {
            mysqli_rollback($db);
            return array('ok' => false, 'reason' => 'mobile',
                'message' => 'برای این شماره به‌تازگی چند کد فرستاده شده است. '
                    . 'لطفاً ۱۰ دقیقه صبر کنید.');
        }

        /* ── لایهٔ ۲: همین IP، ۱۰ کد در ۱ ساعت ─────────────────────
           فقط وقتی IP قابل اتکاست. اگر پروکسی ناشناخته‌ای ظاهر شده
           باشد، این لایه کنار می‌رود تا همه با هم قفل نشوند. */
        if ($ip_usable) {
            $since_ip = gmdate('Y-m-d H:i:s', time() - BOOKING_IP_WINDOW);
            $row = db_select_one($db,
                "SELECT COUNT(*) AS cnt FROM otp_codes
                  WHERE ip_hash = ? AND created_at > ? FOR UPDATE",
                'ss', array($ip_hash, $since_ip));
            if ($row && (int)$row['cnt'] >= BOOKING_IP_CAP) {
                mysqli_rollback($db);
                return array('ok' => false, 'reason' => 'ip',
                    'message' => 'از این اتصال اینترنتی درخواست‌های زیادی ثبت شده است. '
                        . 'لطفاً یک ساعت دیگر تلاش کنید.');
            }
        }

        /* ── لایهٔ ۳: سقف هزینهٔ پیامک، ۱۰۰ در شبانه‌روز ────────────
           همهٔ پیامک‌ها شمرده می‌شوند (ورود مراجع، بازیابی رمز، …)
           چون سقف دربارهٔ هزینه است. ولی فقط *درخواست نوبت* را
           می‌بندد؛ ورود مراجع و پرسنل هیچ‌وقت از این راه قفل نمی‌شود. */
        $since_d = gmdate('Y-m-d H:i:s', time() - 86400);
        $row = db_select_one($db,
            "SELECT COUNT(*) AS cnt FROM otp_codes WHERE created_at > ? FOR UPDATE",
            's', array($since_d));
        if ($row && (int)$row['cnt'] >= BOOKING_GLOBAL_DAILY_CAP) {
            mysqli_rollback($db);
            return array('ok' => false, 'reason' => 'global',
                'message' => 'ظرفیت ثبت درخواست اینترنتی برای امروز تکمیل شده است.');
        }

        /* ── فاصلهٔ ارسال دوباره ───────────────────────────────── */
        $row = db_select_one($db,
            "SELECT created_at FROM otp_codes
              WHERE mobile_number = ? AND purpose = ?
              ORDER BY id DESC LIMIT 1",
            'ss', array($mobile, BOOKING_OTP_PURPOSE));
        $cooldown = defined('OTP_RESEND_COOLDOWN') ? (int)OTP_RESEND_COOLDOWN : 90;
        if ($row) {
            $elapsed = time() - strtotime($row['created_at'] . ' UTC');
            if ($elapsed < $cooldown) {
                mysqli_rollback($db);
                return array('ok' => false, 'reason' => 'cooldown',
                    'message' => 'برای ارسال دوبارهٔ کد، '
                        . to_persian_digits($cooldown - $elapsed) . ' ثانیهٔ دیگر صبر کنید.');
            }
        }

        /* کدهای استفاده‌نشدهٔ قبلیِ همین شماره و همین کاربرد باطل می‌شوند */
        db_execute($db,
            "UPDATE otp_codes SET consumed_at = ?
              WHERE mobile_number = ? AND purpose = ? AND consumed_at IS NULL",
            'sss', array($now, $mobile, BOOKING_OTP_PURPOSE));

        db_execute($db,
            "INSERT INTO otp_codes
               (mobile_number, code_hash, purpose, ip_hash, expires_at, attempts, created_at)
             VALUES (?,?,?,?,?,0,?)",
            'ssssss',
            array($mobile, $code_hash, BOOKING_OTP_PURPOSE, $ip_hash,
                  utc_dt_offset($ttl), $now));

        mysqli_commit($db);
    } catch (Exception $ex) {
        mysqli_rollback($db);
        throw $ex;
    }

    /* ── پیامک، پس از commit ───────────────────────────────────── */
    $send = sms_send_otp_code($mobile, $code);

    audit_log_write($db, null, null,
        $send['ok'] ? 'BOOKING_REQUEST_OTP_SENT' : 'OTP_SEND_FAILED',
        'otp_code', null,
        array('purpose' => BOOKING_OTP_PURPOSE, 'mode' => $send['mode'],
              'ip_trusted' => $ip_usable ? 1 : 0));

    if (!$send['ok']) {
        return array('ok' => false, 'reason' => 'sms', 'message' => $send['message']);
    }
    return array('ok' => true, 'reason' => '', 'message' => '');
}

/** ثبت در دفتر وقایع وقتی کسی به سقف خورد — برای هشدار میز منشی */
function booking_audit_rate_limited($db, $reason, $ip)
{
    audit_log_write($db, null, null, 'BOOKING_REQUEST_RATE_LIMITED',
        'booking_request', null,
        array('reason' => $reason, 'ip_trusted' => booking_ip_trustworthy($ip) ? 1 : 0));
}


/* ═══════════════════════════════════════════════════════════════════
 *  ساخت ردیف قرنطینه — ت-۲: فقط پس از تأیید پیامکی
 * ═══════════════════════════════════════════════════════════════════ */

/**
 * ثبت درخواست.
 *
 * پیش‌شرطی که هیچ‌وقت نباید دور زده شود: این تابع تنها زمانی صدا
 * زده می‌شود که otp_verify() همین شماره را تأیید کرده باشد. ستون
 * mobile_verified_at با NOT NULL همین را در سطح دیتابیس تضمین می‌کند.
 *
 * $data: first_name, last_name, gender, service_label, preferred_text, note
 */
function booking_request_create($db, $data, $mobile, $ip)
{
    $now       = now_dt();
    $public_id = generate_public_id('br');
    $expires   = gmdate('Y-m-d H:i:s', time() + (BOOKING_RETENTION_DAYS * 86400));

    $gender = (isset($data['gender']) && in_array($data['gender'], array('MALE', 'FEMALE'), true))
        ? $data['gender'] : null;
    $note = isset($data['note']) ? trim((string)$data['note']) : '';
    if ($note === '') {
        $note = null;
    } elseif (mb_strlen($note, 'UTF-8') > BOOKING_NOTE_MAX) {
        $note = mb_substr($note, 0, BOOKING_NOTE_MAX, 'UTF-8');
    }

    $res = db_execute($db,
        "INSERT INTO booking_requests
           (public_id, first_name, last_name, mobile_number, gender,
            requested_service_label, service_type_id, preferred_text, visitor_note,
            mobile_verified_at, status, source, ip_hash, user_agent_hash,
            created_at, expires_at)
         VALUES (?,?,?,?,?,?,NULL,?,?,?,'NEW','website',?,?,?,?)",
        'sssssssssssss',
        array($public_id,
              $data['first_name'], $data['last_name'], $mobile, $gender,
              $data['service_label'], $data['preferred_text'], $note,
              $now,
              booking_ip_hash($ip), booking_user_agent_hash(),
              $now, $expires));

    $id = (int)$res['insert_id'];

    audit_log_write($db, null, null, 'BOOKING_REQUEST_CREATED',
        'booking_request', $id,
        array('public_id' => $public_id, 'source' => 'website'));

    return array('id' => $id, 'public_id' => $public_id);
}


/* ═══════════════════════════════════════════════════════════════════
 *  نگهداری — ا-۲
 * ═══════════════════════════════════════════════════════════════════ */

/**
 * درخواست‌های رسیدگی‌نشده‌ای که از ۱۸۰ روز گذشته‌اند، EXPIRED می‌شوند.
 *
 * هیچ ردیفی پاک نمی‌شود (قاعدهٔ «بدون حذف فیزیکی»). فقط وضعیت عوض
 * می‌شود تا میز منشی شلوغ نماند.
 *
 * یادآوری دائمی: هر پاک‌سازی آیندهٔ otp_codes نباید ردیف‌های کمتر از
 * ۴۸ ساعت را حذف کند، وگرنه پنجره‌های شمارش سقف خالی می‌شوند.
 */
function booking_expire_overdue($db)
{
    $res = db_execute($db,
        "UPDATE booking_requests SET status = 'EXPIRED'
          WHERE status IN ('NEW','CONTACTED') AND expires_at < ?",
        's', array(now_dt()));
    return (int)$res['affected'];
}
