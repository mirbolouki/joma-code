-- ════════════════════════════════════════════════════════════════════
--  جوما — پرس‌وجوهای بررسی دستی (phpMyAdmin)
--  هر کدام را جداگانه کپی و اجرا کنید. همه فقط SELECT هستند و
--  هیچ داده‌ای را تغییر نمی‌دهند.
-- ════════════════════════════════════════════════════════════════════

-- ① آیا نقشِ برداشته‌شده باطل شده یا حذف؟
--    انتظار: ردیف‌های قدیمی با status='REVOKED' و revoked_at پرشده
--    سر جایشان باشند. اگر ردیفی اصلاً نیست، یعنی حذف فیزیکی رخ داده (اشتباه).
SELECT ra.id,
       ra.public_id,
       CONCAT(p.first_name, ' ', p.last_name) AS person_name,
       ra.role_code,
       ra.status,
       ra.valid_from,
       ra.revoked_at
  FROM role_assignments ra
  INNER JOIN persons p ON p.id = ra.person_id
 ORDER BY ra.id DESC
 LIMIT 40;

-- ② آیا اعطا و ابطال نقش در گزارش حسابرسی ثبت شده؟
--    انتظار: برای هر تغییری که امروز دادید یک ردیف ROLE_GRANTED یا
--    ROLE_REVOKED با شناسهٔ مدیر انجام‌دهنده و کد نقش در metadata_json.
SELECT al.id,
       al.created_at,
       al.action_code,
       CONCAT(actor.first_name, ' ', actor.last_name) AS actor_name,
       al.actor_role_code,
       al.entity_type,
       al.entity_id,
       al.metadata_json
  FROM audit_log al
  LEFT JOIN persons actor ON actor.id = al.actor_person_id
 WHERE al.action_code IN ('ROLE_GRANTED', 'ROLE_REVOKED')
 ORDER BY al.id DESC
 LIMIT 40;

-- ③ آزمون ۱۳ فاز ۳ — متن یادداشت نباید هرگز وارد audit_log شده باشد.
--    «٪٪ یک تکهٔ یکتا از متن یادداشت خودتان ٪٪» را با چند کلمهٔ واقعی از
--    یکی از یادداشت‌هایتان جایگزین کنید.
--    انتظار: صفر ردیف. هر ردیفی اینجا یعنی نشت متن محرمانه.
SELECT id, created_at, action_code, metadata_json
  FROM audit_log
 WHERE metadata_json LIKE '%٪٪ یک تکهٔ یکتا از متن یادداشت خودتان ٪٪%';

-- ④ وضعیت کلی یادداشت‌ها (بدون نمایش متن).
SELECT n.public_id,
       CONCAT(author.first_name, ' ', author.last_name) AS author_name,
       n.status,
       n.character_count,
       n.edit_count,
       n.view_count,
       n.created_at,
       n.edited_at,
       n.retracted_at
  FROM confidential_notes n
  INNER JOIN persons author ON author.id = n.author_person_id
 ORDER BY n.id DESC
 LIMIT 40;

-- ⑤ شمارش مدیرهای فعال — همان عددی که نگهبان «آخرین مدیر» استفاده می‌کند.
SELECT COUNT(DISTINCT p.id) AS active_admins
  FROM persons p
  INNER JOIN accounts a ON a.person_id = p.id
  INNER JOIN role_assignments r ON r.person_id = p.id
 WHERE a.account_type = 'STAFF'
   AND a.status = 'ACTIVE'
   AND p.status  = 'ACTIVE'
   AND r.role_code = 'admin'
   AND r.status = 'ACTIVE';
