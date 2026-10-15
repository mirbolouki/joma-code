-- افزایشی و قابل اجرای مجدد: فقط دو جدول جدید ایجاد می‌کند.
CREATE TABLE IF NOT EXISTS `clinic_settings` (
  `id` TINYINT UNSIGNED NOT NULL,
  `clinic_name` VARCHAR(150) NOT NULL DEFAULT 'کلینیک جوما',
  `clinic_phone` VARCHAR(30) NOT NULL DEFAULT '',
  `clinic_address` VARCHAR(500) NOT NULL DEFAULT '',
  `updated_by_person_id` BIGINT UNSIGNED DEFAULT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_clinic_settings_updated_by`
    FOREIGN KEY (`updated_by_person_id`) REFERENCES `persons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `clinical_case_status_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` VARCHAR(30) NOT NULL,
  `clinical_case_id` BIGINT UNSIGNED NOT NULL,
  `from_status` ENUM('ACTIVE','ARCHIVED') NOT NULL,
  `to_status` ENUM('ACTIVE','ARCHIVED') NOT NULL,
  `reason_text` VARCHAR(500) NOT NULL,
  `changed_by_person_id` BIGINT UNSIGNED NOT NULL,
  `changed_by_role_code` VARCHAR(20) NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_case_status_events_public_id` (`public_id`),
  KEY `idx_case_status_events_case_created` (`clinical_case_id`, `created_at`),
  CONSTRAINT `fk_case_status_events_case`
    FOREIGN KEY (`clinical_case_id`) REFERENCES `clinical_cases` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_case_status_events_actor`
    FOREIGN KEY (`changed_by_person_id`) REFERENCES `persons` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
