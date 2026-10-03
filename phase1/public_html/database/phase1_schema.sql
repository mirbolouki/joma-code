-- ════════════════════════════════════════════════════════════════════
--  جوما — سامانهٔ مدیریت کلینیک
--  اسکیمای فاز ۱ — ۱۱ جدول
--
--  این فایل به‌صورت خودکار توسط install.php اجرا می‌شود.
--  نیازی به اجرای دستی آن در phpMyAdmin نیست.
--
--  قاعده‌ها:
--   • همهٔ جدول‌ها InnoDB و utf8mb4
--   • هر جدول یک id داخلی (فقط برای کلید خارجی) و یک public_id
--     غیرقابل‌حدس برای استفاده در نشانی صفحات دارد
--   • همهٔ تاریخ‌ها به وقت جهانی (UTC) ذخیره می‌شوند
-- ════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

CREATE TABLE migrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(20) NOT NULL UNIQUE,
    applied_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE persons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    national_code VARCHAR(10) NULL,
    mobile_number VARCHAR(15) NOT NULL UNIQUE,
    birth_date DATE NULL,
    gender ENUM('MALE','FEMALE') NULL,
    status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    created_at DATETIME NOT NULL,
    KEY idx_persons_mobile (mobile_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    person_id BIGINT UNSIGNED NOT NULL UNIQUE,
    login_identifier VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    account_type ENUM('STAFF','PATIENT') NOT NULL,
    status ENUM('ACTIVE','DISABLED') NOT NULL DEFAULT 'ACTIVE',
    must_change_password TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_accounts_person FOREIGN KEY (person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    person_id BIGINT UNSIGNED NOT NULL,
    role_code VARCHAR(20) NOT NULL,
    status ENUM('ACTIVE','REVOKED') NOT NULL DEFAULT 'ACTIVE',
    valid_from DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    CONSTRAINT fk_role_assignments_person FOREIGN KEY (person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    KEY idx_role_assignments_lookup (person_id, role_code, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE otp_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mobile_number VARCHAR(15) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    purpose ENUM('REGISTER','RESET_PASSWORD','CONFIRM_MOBILE_CHANGE') NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    KEY idx_otp_lookup (mobile_number, purpose, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lookup_lists (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    list_code VARCHAR(50) NOT NULL UNIQUE,
    list_title VARCHAR(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE lookup_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lookup_list_id INT UNSIGNED NOT NULL,
    item_code VARCHAR(50) NOT NULL,
    label VARCHAR(200) NOT NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    CONSTRAINT fk_lookup_items_list FOREIGN KEY (lookup_list_id)
        REFERENCES lookup_lists(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    UNIQUE KEY uq_lookup_item (lookup_list_id, item_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE service_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    code VARCHAR(30) NOT NULL UNIQUE,
    title VARCHAR(150) NOT NULL,
    is_multi_person TINYINT(1) NOT NULL DEFAULT 0,
    default_duration_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 45,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    patient_person_id BIGINT UNSIGNED NOT NULL,
    referred_therapist_person_id BIGINT UNSIGNED NOT NULL,
    service_type_id BIGINT UNSIGNED NOT NULL,
    companion_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    referral_reason_id BIGINT UNSIGNED NOT NULL,
    decline_reason_id BIGINT UNSIGNED NULL,
    status ENUM('AWAITING_THERAPIST','ACCEPTED','DECLINED') NOT NULL DEFAULT 'AWAITING_THERAPIST',
    created_by_person_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    decided_at DATETIME NULL,
    CONSTRAINT fk_admissions_patient FOREIGN KEY (patient_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_admissions_therapist FOREIGN KEY (referred_therapist_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_admissions_service FOREIGN KEY (service_type_id)
        REFERENCES service_types(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_admissions_reason FOREIGN KEY (referral_reason_id)
        REFERENCES lookup_items(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_admissions_decline_reason FOREIGN KEY (decline_reason_id)
        REFERENCES lookup_items(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_admissions_creator FOREIGN KEY (created_by_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    KEY idx_admissions_status (status),
    KEY idx_admissions_therapist_status (referred_therapist_person_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clinical_cases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    admission_id BIGINT UNSIGNED NOT NULL UNIQUE,
    patient_person_id BIGINT UNSIGNED NOT NULL,
    responsible_therapist_person_id BIGINT UNSIGNED NOT NULL,
    status ENUM('ACTIVE','ARCHIVED') NOT NULL DEFAULT 'ACTIVE',
    opened_at DATETIME NOT NULL,
    CONSTRAINT fk_cases_admission FOREIGN KEY (admission_id)
        REFERENCES admissions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_cases_patient FOREIGN KEY (patient_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_cases_therapist FOREIGN KEY (responsible_therapist_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    KEY idx_cases_therapist_status (responsible_therapist_person_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_person_id BIGINT UNSIGNED NULL,
    actor_role_code VARCHAR(20) NULL,
    action_code VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    metadata_json TEXT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
