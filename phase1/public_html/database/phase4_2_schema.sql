-- ═══════════════════════════════════════════════════════════════════
--  جوما — وصلهٔ ۴.۲.۰ «درخواست نوبت اینترنتی»
--  نسخهٔ مهاجرت: 4.2.0
--
--  این فایل سند مرجع است. اجرای واقعی از راه upgrade_phase4_2.php
--  انجام می‌شود که هر گام را idempotent بررسی می‌کند. اگر مستقیم در
--  phpMyAdmin اجرا شد، اجرای دوباره خطا می‌دهد (و باید بدهد).
--
--  اصل حاکم بر این مهاجرت:
--    «درخواست نوبت» ≠ «نوبت».
--    جدول زیر یک قرنطینه است. هیچ کلید خارجی‌ای به persons برای
--    خودِ متقاضی ندارد — چون متقاضی هنوز «شخص» کلینیک نیست و شاید
--    هرگز نشود. تنها پل به دنیای واقعی، converted_admission_id است
--    که فقط وقتی پر می‌شود که یک انسان تصمیم گرفته باشد.
--    handled_by_person_id استثنا نیست: آن، کارمندِ رسیدگی‌کننده است،
--    نه متقاضی.
--
--  دامنهٔ تغییر: ۱ جدول تازه · ۲ تغییر افزایشی روی otp_codes ·
--                ۲ فهرست مرجع (داده، نه ساختار) · ۰ صفحهٔ مدیریتی تازه
-- ═══════════════════════════════════════════════════════════════════


-- ───────────────────────────────────────────────────────────────────
--  ۱) جدول قرنطینه
-- ───────────────────────────────────────────────────────────────────
CREATE TABLE booking_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,          -- generate_public_id('br')

    first_name  VARCHAR(100) NOT NULL,
    last_name   VARCHAR(100) NOT NULL,
    mobile_number VARCHAR(15) NOT NULL,             -- عمداً UNIQUE نیست:
                                                    -- یک نفر می‌تواند دوبار
                                                    -- درخواست بدهد، و دو نفر
                                                    -- می‌توانند یک شماره بدهند
    gender ENUM('MALE','FEMALE') NULL,

    requested_service_label VARCHAR(150) NULL,      -- عین متنی که کاربر دید
    service_type_id BIGINT UNSIGNED NULL,           -- نگاشت، توسط منشی
    preferred_text VARCHAR(100) NULL,               -- «سه‌شنبه صبح»
    visitor_note VARCHAR(300) NULL,                 -- غیربالینی

    mobile_verified_at DATETIME NOT NULL,           -- ت-۲: همیشه پر است،
                                                    -- چون ردیف فقط پس از
                                                    -- تأیید پیامکی ساخته می‌شود
    status ENUM('NEW','CONTACTED','CONVERTED','REJECTED','SPAM','EXPIRED')
           NOT NULL DEFAULT 'NEW',

    converted_admission_id BIGINT UNSIGNED NULL,    -- تنها پل
    reject_reason_id BIGINT UNSIGNED NULL,
    handled_by_person_id BIGINT UNSIGNED NULL,      -- کارمند، نه متقاضی
    handled_at DATETIME NULL,

    source VARCHAR(30) NOT NULL DEFAULT 'website',
    ip_hash CHAR(64) NULL,                          -- HMAC-SHA256، نه IP خام
    user_agent_hash CHAR(64) NULL,

    created_at DATETIME NOT NULL,                   -- now_dt() از PHP
    expires_at DATETIME NOT NULL,                   -- +۱۸۰ روز، از PHP

    CONSTRAINT fk_br_service  FOREIGN KEY (service_type_id)
        REFERENCES service_types(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_br_admission FOREIGN KEY (converted_admission_id)
        REFERENCES admissions(id)  ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_br_reason   FOREIGN KEY (reject_reason_id)
        REFERENCES lookup_items(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_br_handler  FOREIGN KEY (handled_by_person_id)
        REFERENCES persons(id)     ON DELETE RESTRICT ON UPDATE RESTRICT,

    KEY idx_br_status  (status, created_at),
    KEY idx_br_mobile  (mobile_number),
    KEY idx_br_ip      (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ───────────────────────────────────────────────────────────────────
--  ۲) دو تغییر افزایشی روی otp_codes
--
--  هر دو افزایشی‌اند: هیچ ستونی حذف یا تنگ‌تر نمی‌شود و هیچ ردیف
--  موجودی تغییر نمی‌کند. چهار مقدار قبلی ENUM دست‌نخورده می‌مانند.
-- ───────────────────────────────────────────────────────────────────

ALTER TABLE otp_codes MODIFY purpose
    ENUM('REGISTER','RESET_PASSWORD','CONFIRM_MOBILE_CHANGE',
         'PATIENT_LOGIN','BOOKING_REQUEST') NOT NULL;

-- چرا ip_hash در otp_codes و نه یک جدول شمارنده؟ چون سقف باید پیش از
-- ساخته‌شدن ردیف درخواست اعمال شود، و در آن لحظه تنها چیزی که وجود
-- دارد همین ردیف کد یک‌بارمصرف است. ضمناً سقف IP را به‌طور رایگان
-- برای ورود مراجع هم فعال می‌کند که امروز هیچ سقف IP ندارد.
ALTER TABLE otp_codes ADD COLUMN ip_hash CHAR(64) NULL AFTER purpose;
ALTER TABLE otp_codes ADD KEY idx_otp_ip (ip_hash, created_at);


-- ───────────────────────────────────────────────────────────────────
--  ۳) دادهٔ مرجع — طبق ADR-010
--
--  هیچ جدول اختصاصی‌ای برای خدمات ساخته نمی‌شود. هر دو فهرست زیر
--  از همان صفحهٔ مشترک مدیریت فهرست‌ها ویرایش می‌شوند و هیچ‌وقت حذف
--  نمی‌شوند، فقط غیرفعال.
--
--  این بخش به‌صورت SQL خام اجرا نمی‌شود؛ upgrade_phase4_2.php آن را
--  با تابع‌های ensure وارد می‌کند تا اجرای دوباره چیزی را تکرار نکند.
--  متن اینجا فقط برای مرور است.
--
--  فهرست ۱ — booking_service_option (۱۲ قلم)
--    عیناً از فرم فعلی mirbolouki.com/form/reservation برداشته شده،
--    به‌جز یک غلط تایپی که اصلاح شد: «طزحواره» ← «طرحواره».
--
--      sex_therapy             سکس تراپی
--      couple_therapy          زوج درمانی
--      premarital              مشاوره پیش از ازدواج
--      marital_conflict        حل تعارضات زناشویی
--      infidelity              خیانت
--      divorce                 مشاوره طلاق
--      psychotherapy           روان درمانی
--      individual_coaching     مشاوره فردی و کوچینگ
--      family_therapy          خانواده درمانی
--      schema_therapy          طرحواره درمانی
--      anxiety                 تشخیص و درمان اختلالات اضطرابی
--      sexual_conflict         تشخیص و حل تعارضات جنسی
--
--  فهرست ۲ — booking_reject_reason (۵ قلم)
--    بدون این فهرست، ستون reject_reason_id هیچ‌وقت پر نمی‌شود و
--    دکمهٔ «رد درخواست» در صفحهٔ منشی کار نمی‌کند.
--
--      duplicate       درخواست تکراری
--      unreachable     پاسخگو نبود / شماره نادرست
--      out_of_scope    خارج از حوزهٔ خدمات کلینیک
--      withdrawn       انصراف متقاضی
--      spam            اسپم یا درخواست آزمایشی
-- ───────────────────────────────────────────────────────────────────


-- ───────────────────────────────────────────────────────────────────
--  آنچه در این مهاجرت نیست — و عمداً نیست
--
--  • جدول public_rate_limits — حذف شد. شمارش از otp_codes خوانده
--    می‌شود؛ یک جدول کمتر برای نگهداری و پاک‌سازی.
--  • جدول booking_service_options — حذف شد. ADR-010 می‌گوید هر
--    فهرست ویرایش‌پذیر باید در lookup_items باشد.
--  • هیچ FK از booking_requests به persons برای متقاضی.
--  • هیچ تغییری در persons، admissions، appointments، clinical_cases.
-- ───────────────────────────────────────────────────────────────────
