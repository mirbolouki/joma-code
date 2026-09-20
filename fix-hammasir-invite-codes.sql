-- ==========================================================================
-- جوما — جدول «کد دعوت یک‌بارمصرف» هم‌مسیر (بستهٔ v10)
-- ==========================================================================
-- این فایل فقط و فقط یک جدول تازه می‌سازد. هیچ جدول و هیچ دادهٔ موجودی
-- تغییر نمی‌کند: نه UPDATE، نه DELETE، نه ALTER، نه حذف ستون.
--
-- آیا لازم است اجرا شود؟
--   · اگر کپی تست تو با «فایل» کار می‌کند (حالت پیش‌فرض این بسته): لازم نیست.
--     کدها در همان storage ماژول می‌نشینند (data/hammasir/store.json).
--   · اگر نصب با MySQL کار می‌کند: یک‌بار در phpMyAdmin → SQL این را اجرا کن.
--     اگر اجرا نشود، مسیر «کد دعوت دارم» کار نمی‌کند ولی بقیهٔ برنامه سالم است.
--
-- کدهای کنترل: فقط CREATE TABLE IF NOT EXISTS — اگر جدول باشد، هیچ کاری نمی‌کند.
-- ==========================================================================

CREATE TABLE IF NOT EXISTS `joma_hammasir_invite_codes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider_user_id` INT UNSIGNED NOT NULL,
  `code` VARCHAR(16) NOT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  `created_by_user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  `used_by_user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `used_at` DATETIME NULL,
  `link_id` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invite_code` (`code`),
  KEY `ix_invite_provider` (`provider_user_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- چه چیزی «کی با کد آمد» را نگه می‌دارد؟
--   used_by_user_id  ← کاربری که کد را وارد کرده (فقط شمارهٔ کاربر)
--   used_at          ← لحظهٔ وارد کردن کد
--   link_id          ← ارتباطی که از آن ساخته شد
-- هیچ دادهٔ درمانی در این جدول نیست.
