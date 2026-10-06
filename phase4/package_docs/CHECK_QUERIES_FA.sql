-- ════════════════════════════════════════════════════════════════════
--  جوما — فاز ۴: پرس‌وجوهای بررسی دستی (phpMyAdmin)
--  هر کدام را جداگانه کپی و اجرا کنید.
--  همهٔ این‌ها فقط SELECT هستند و هیچ داده‌ای را تغییر نمی‌دهند.
--  شماره‌ها با ارجاع‌های داخل PHASE4_TEST_PLAN_FA.md یکی است.
-- ════════════════════════════════════════════════════════════════════


-- ⓪ سلامت نصب: آیا مهاجرت ثبت شده و هر شش جدول ساخته شده؟
--    انتظار: یک سطر با version = '4.0.0'، و عدد ۶ در ستون دوم.
SELECT (SELECT COUNT(*) FROM migrations WHERE version = '4.0.0')     AS migration_4_0_0,
       (SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name IN ('form_templates','form_fields','form_assignments',
                              'form_submissions','form_submission_values',
                              'form_draft_saves'))                   AS new_tables_found;

-- ⓪-ب قالب پذیرش اولیه درست کاشته شده؟
--    انتظار: یک سطر، status='ACTIVE'، نقش گیرنده 'PATIENT'، و دقیقاً ۱۰ پرسش.
SELECT ft.id, ft.template_code, ft.version, ft.status,
       ft.default_assignee_role, ft.admin_can_view, ft.patient_can_have_draft,
       (SELECT COUNT(*) FROM form_fields ff WHERE ff.template_id = ft.id) AS field_count
  FROM form_templates ft
 WHERE ft.template_code = 'patient_intake';


-- ① آزمون ۸ — افزودن پرسش «متن توضیحی» باید در حسابرسی ثبت شده باشد.
--    انتظار: برای هر پرسش متن‌باز که ساختید یک سطر.
SELECT al.id, al.created_at, al.action_code,
       CONCAT(p.first_name, ' ', p.last_name) AS actor_name,
       al.entity_type, al.entity_id, al.metadata_json
  FROM audit_log al
  LEFT JOIN persons p ON p.id = al.actor_person_id
 WHERE al.action_code = 'FORM_FIELD_FREE_TEXT_ADDED'
 ORDER BY al.id DESC
 LIMIT 20;


-- ② آزمون ۱۹ و ۲۱ — تخصیص خودکار: موفق و ناموفق.
--    انتظار آزمون ۱۹: سطر FORM_AUTO_ASSIGNED.
--    انتظار آزمون ۲۱: سطر FORM_AUTO_ASSIGN_FAILED با دلیل در metadata_json.
SELECT al.id, al.created_at, al.action_code, al.entity_type, al.entity_id,
       al.metadata_json
  FROM audit_log al
 WHERE al.action_code IN ('FORM_AUTO_ASSIGNED', 'FORM_AUTO_ASSIGN_FAILED')
 ORDER BY al.id DESC
 LIMIT 20;


-- ③ آزمون ۱۹ و ۲۰ — فهرست تخصیص‌های یک مراجع.
--    «٪٪ نام خانوادگی مراجع ٪٪» را با نام واقعی جایگزین کنید.
--    انتظار آزمون ۲۰: پس از نوبت دوم، همچنان فقط یک سطر با
--    assignment_source = 'AUTO_FIRST_APPOINTMENT'.
SELECT fa.id, fa.public_id, fa.status, fa.assignment_source,
       fa.assignee_role, fa.assigned_at, fa.trigger_appointment_id,
       ft.template_code, ft.version,
       CONCAT(pt.first_name, ' ', pt.last_name) AS patient_name,
       CONCAT(pa.first_name, ' ', pa.last_name) AS assignee_name
  FROM form_assignments fa
  INNER JOIN form_templates ft ON ft.id = fa.template_id
  INNER JOIN persons pt        ON pt.id = fa.patient_person_id
  INNER JOIN persons pa        ON pa.id = fa.assignee_person_id
 WHERE pt.last_name LIKE '%% نام خانوادگی مراجع %%'
 ORDER BY fa.id DESC;


-- ④ آزمون ۲۲ (تصمیم D4-4) — پس از باز شدن پرونده، clinical_case_id پر شود.
--    انتظار: ستون clinical_case_id دیگر NULL نباشد و شمارهٔ پرونده کنارش بیاید.
SELECT fa.id, fa.public_id, fa.admission_id, fa.clinical_case_id,
       cc.public_id AS case_public_id, cc.status AS case_status,
       fa.status AS assignment_status, fa.assignment_source
  FROM form_assignments fa
  LEFT JOIN clinical_cases cc ON cc.id = fa.clinical_case_id
 ORDER BY fa.id DESC
 LIMIT 30;


-- ⑤ آزمون ۱۸ — تلاش درمانگر دیگر برای دیدن فرمی که مال او نیست.
--    انتظار: سطر FORM_ACCESS_DENIED با شناسهٔ درمانگر ب.
--    نکته: متن پیام کاربر و این سطر باید یکسان باشند برای «یافت نشد» و «مال شما نیست».
SELECT al.id, al.created_at, al.action_code,
       CONCAT(p.first_name, ' ', p.last_name) AS actor_name,
       al.actor_role_code, al.entity_type, al.entity_id, al.metadata_json
  FROM audit_log al
  LEFT JOIN persons p ON p.id = al.actor_person_id
 WHERE al.action_code IN ('FORM_ACCESS_DENIED', 'FORM_DENY_MESSAGE')
 ORDER BY al.id DESC
 LIMIT 30;


-- ⑥ آزمون ۲۷ — ویرایش باید بازنگری تازه بسازد، نه جایگزینی.
--    «٪٪ شناسهٔ عددی پاسخ ٪٪» را از ستون id جدول form_submissions بردارید.
--    انتظار: برای پرسشی که تغییر دادید دو سطر — revision=1 با is_current=0
--    و revision=2 با is_current=1. مقدار قدیمی باید هنوز قابل خواندن باشد.
SELECT fsv.id, fsv.revision, fsv.is_current, fsv.created_at,
       ff.field_code, ff.label, fsv.value_text
  FROM form_submission_values fsv
  INNER JOIN form_fields ff ON ff.id = fsv.field_id
 WHERE fsv.submission_id = 0 /* ٪٪ شناسهٔ عددی پاسخ ٪٪ */
 ORDER BY ff.display_order, fsv.revision;


-- ⑦ آزمون ۲۶، ۲۹، ۳۲ و ۱۲ — پاسخ‌های جاری یک فرم.
--    انتظار آزمون ۲۹: حتی پس از ابطال، این سطرها سر جایشان باشند
--    (وضعیت پاسخ RETRACTED می‌شود ولی محتوا هرگز پاک نمی‌شود).
SELECT fs.id AS submission_id, fs.public_id, fs.status, fs.current_revision,
       fs.edit_count, fs.submitted_at, fs.retracted_at,
       li.label AS retraction_reason,
       ff.field_code, ff.label, fsv.value_text
  FROM form_submissions fs
  INNER JOIN form_submission_values fsv
          ON fsv.submission_id = fs.id AND fsv.is_current = 1
  INNER JOIN form_fields ff ON ff.id = fsv.field_id
  LEFT  JOIN lookup_items li ON li.id = fs.retraction_reason_id
 ORDER BY fs.id DESC, ff.display_order
 LIMIT 100;


-- ⑧ آزمون ۳۰ تا ۳۳ — پیش‌نویس‌ها. فقط مراجع حق پیش‌نویس دارد (P8).
--    انتظار پس از آزمون ۳۱ یا ۳۲: هیچ سطری برای آن تخصیص نماند.
--    انتظار همیشگی: ستون saved_by_role_code هرگز 'therapist' نباشد.
SELECT fd.id, fd.assignment_id, fd.saved_by_role_code, fd.saved_at,
       CONCAT(p.first_name, ' ', p.last_name) AS saved_by,
       CHAR_LENGTH(fd.draft_data_json) AS draft_size
  FROM form_draft_saves fd
  INNER JOIN persons p ON p.id = fd.saved_by_person_id
 ORDER BY fd.id DESC;


-- ⑨ آزمون امنیتی ۲ (ریسک P4) — هر بار که مدیر پاسخی را می‌بیند باید ثبت شود.
--    انتظار: یک سطر به‌ازای هر بار باز کردن، با نام مدیر.
SELECT al.id, al.created_at,
       CONCAT(p.first_name, ' ', p.last_name) AS admin_name,
       al.entity_type, al.entity_id, al.metadata_json
  FROM audit_log al
  LEFT JOIN persons p ON p.id = al.actor_person_id
 WHERE al.action_code = 'FORM_SUBMISSION_VIEWED_BY_ADMIN'
 ORDER BY al.id DESC
 LIMIT 30;


-- ⑩ آزمون امنیتی ۳ — متن پاسخ هرگز نباید وارد audit_log شود.
--    «زرافهٔ بنفش ۷۷» را با همان عبارت یکتایی که در پاسخ نوشتید جایگزین کنید.
--    انتظار: صفر سطر. هر سطری یعنی نشت محتوای بالینی.
SELECT al.id, al.created_at, al.action_code, al.metadata_json
  FROM audit_log al
 WHERE al.metadata_json LIKE '%زرافهٔ بنفش ۷۷%'
 ORDER BY al.id DESC;


-- ⑩-ب همان بررسی، ولی عمومی‌تر: آیا هیچ سطر حسابرسی غیرعادی بزرگ هست؟
--    متادیتای سالم کوتاه است (شناسه و شمارش). سطر بسیار بزرگ مشکوک است.
SELECT al.id, al.created_at, al.action_code,
       CHAR_LENGTH(al.metadata_json) AS meta_size,
       LEFT(al.metadata_json, 200) AS meta_preview
  FROM audit_log al
 WHERE al.action_code LIKE 'FORM%'
 ORDER BY meta_size DESC
 LIMIT 20;


-- ⑪ نمای کلی پایان کار: شمارش همه چیز.
SELECT (SELECT COUNT(*) FROM form_templates)                                  AS templates,
       (SELECT COUNT(*) FROM form_templates WHERE status = 'ACTIVE')          AS templates_active,
       (SELECT COUNT(*) FROM form_fields)                                     AS fields,
       (SELECT COUNT(*) FROM form_assignments)                                AS assignments,
       (SELECT COUNT(*) FROM form_assignments WHERE status = 'PENDING')       AS pending,
       (SELECT COUNT(*) FROM form_submissions)                                AS submissions,
       (SELECT COUNT(*) FROM form_submissions WHERE status = 'RETRACTED')     AS retracted,
       (SELECT COUNT(*) FROM form_submission_values)                          AS value_rows,
       (SELECT COUNT(*) FROM form_draft_saves)                                AS drafts;
