-- ════════════════════════════════════════════════════════════════════
--  جوما — فاز ۴: فرم‌های بالینی و پورتال مراجع
--  نسخهٔ مهاجرت: 4.0.0 — شش جدول تازه (مجموع: ۲۳ جدول)
--
--  قواعد: همهٔ FKها RESTRICT؛ هیچ CASCADE. هیچ حذف فیزیکی.
--  گزینه‌ها و دادهٔ پیش‌نویس به‌صورت JSON کدشده در TEXT ذخیره می‌شوند،
--  نه با نوع ستونی JSON (سازگاری با MySQL 5.6/5.7 هاست اشتراکی).
--  این فایل مرجع مستندسازی است؛ اجرای واقعی با upgrade_phase4.php
--  انجام می‌شود که idempotent است.
-- ════════════════════════════════════════════════════════════════════

CREATE TABLE form_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    template_code VARCHAR(50) NOT NULL,
    version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    parent_template_id BIGINT UNSIGNED NULL,
    title VARCHAR(150) NOT NULL,
    description VARCHAR(1000) NULL,
    default_assignee_role ENUM('THERAPIST','PATIENT') NOT NULL DEFAULT 'THERAPIST',
    admin_can_view TINYINT(1) NOT NULL DEFAULT 1,
    patient_can_have_draft TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('DRAFT','ACTIVE','ARCHIVED') NOT NULL DEFAULT 'DRAFT',
    locked_at DATETIME NULL,
    created_by_person_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    CONSTRAINT fk_ft_parent FOREIGN KEY (parent_template_id)
        REFERENCES form_templates(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_ft_creator FOREIGN KEY (created_by_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    UNIQUE KEY uq_ft_code_version (template_code, version),
    KEY idx_ft_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE form_fields (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    template_id BIGINT UNSIGNED NOT NULL,
    field_code VARCHAR(50) NOT NULL,
    label VARCHAR(300) NOT NULL,
    help_text VARCHAR(500) NULL,
    field_type ENUM('NUMBER','DATE','YES_NO','SINGLE_CHOICE','MULTI_CHOICE','SCALE','DESCRIPTIVE_TEXT') NOT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    options_text TEXT NULL,
    min_value DECIMAL(12,2) NULL,
    max_value DECIMAL(12,2) NULL,
    max_length SMALLINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_ff_template FOREIGN KEY (template_id)
        REFERENCES form_templates(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    UNIQUE KEY uq_ff_code (template_id, field_code),
    KEY idx_ff_order (template_id, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE form_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    admission_id BIGINT UNSIGNED NOT NULL,
    clinical_case_id BIGINT UNSIGNED NULL,
    patient_person_id BIGINT UNSIGNED NOT NULL,
    template_id BIGINT UNSIGNED NOT NULL,
    assignee_role ENUM('THERAPIST','PATIENT') NOT NULL,
    assignee_person_id BIGINT UNSIGNED NOT NULL,
    assignment_source ENUM('MANUAL','AUTO_FIRST_APPOINTMENT') NOT NULL DEFAULT 'MANUAL',
    trigger_appointment_id BIGINT UNSIGNED NULL,
    assigned_by_person_id BIGINT UNSIGNED NULL,
    assigned_by_role_code VARCHAR(20) NULL,
    status ENUM('PENDING','SUBMITTED','RETRACTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
    assigned_at DATETIME NOT NULL,
    cancelled_at DATETIME NULL,
    cancelled_by_person_id BIGINT UNSIGNED NULL,
    CONSTRAINT fk_fa_admission FOREIGN KEY (admission_id)
        REFERENCES admissions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_fa_case FOREIGN KEY (clinical_case_id)
        REFERENCES clinical_cases(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_fa_patient FOREIGN KEY (patient_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_fa_template FOREIGN KEY (template_id)
        REFERENCES form_templates(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_fa_assignee FOREIGN KEY (assignee_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_fa_trigger FOREIGN KEY (trigger_appointment_id)
        REFERENCES appointments(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_fa_assigner FOREIGN KEY (assigned_by_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_fa_canceller FOREIGN KEY (cancelled_by_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    KEY idx_fa_admission_status (admission_id, status),
    KEY idx_fa_case (clinical_case_id),
    KEY idx_fa_assignee_status (assignee_person_id, status),
    KEY idx_fa_patient (patient_person_id),
    KEY idx_fa_template (template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE form_submissions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    assignment_id BIGINT UNSIGNED NOT NULL UNIQUE,
    submitted_by_person_id BIGINT UNSIGNED NOT NULL,
    submitted_by_role_code VARCHAR(20) NOT NULL,
    submitted_at DATETIME NOT NULL,
    current_revision SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    edit_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_edited_at DATETIME NULL,
    status ENUM('ACTIVE','RETRACTED') NOT NULL DEFAULT 'ACTIVE',
    retracted_at DATETIME NULL,
    retracted_by_person_id BIGINT UNSIGNED NULL,
    retraction_reason_id BIGINT UNSIGNED NULL,
    CONSTRAINT fk_fs_assignment FOREIGN KEY (assignment_id)
        REFERENCES form_assignments(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_fs_submitter FOREIGN KEY (submitted_by_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_fs_retractor FOREIGN KEY (retracted_by_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_fs_reason FOREIGN KEY (retraction_reason_id)
        REFERENCES lookup_items(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    KEY idx_fs_submitted_at (submitted_at),
    KEY idx_fs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE form_submission_values (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    submission_id BIGINT UNSIGNED NOT NULL,
    field_id BIGINT UNSIGNED NOT NULL,
    revision SMALLINT UNSIGNED NOT NULL,
    value_text TEXT NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_fsv_submission FOREIGN KEY (submission_id)
        REFERENCES form_submissions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_fsv_field FOREIGN KEY (field_id)
        REFERENCES form_fields(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    UNIQUE KEY uq_fsv (submission_id, field_id, revision),
    KEY idx_fsv_current (submission_id, is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- پیش‌نویس فقط برای مراجع (P8). یک ردیف به‌ازای هر تخصیص؛ بازنویسی می‌شود.
CREATE TABLE form_draft_saves (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    assignment_id BIGINT UNSIGNED NOT NULL UNIQUE,
    saved_by_person_id BIGINT UNSIGNED NOT NULL,
    saved_by_role_code VARCHAR(20) NOT NULL,
    draft_data_json LONGTEXT NOT NULL,
    saved_at DATETIME NOT NULL,
    CONSTRAINT fk_fd_assignment FOREIGN KEY (assignment_id)
        REFERENCES form_assignments(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_fd_saver FOREIGN KEY (saved_by_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    KEY idx_fd_saved_at (saved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تنها تغییر در جدول‌های قدیمی: افزودن یک مقدار به ENUM. افزایشی و برگشت‌پذیر.
ALTER TABLE otp_codes MODIFY purpose
  ENUM('REGISTER','RESET_PASSWORD','CONFIRM_MOBILE_CHANGE','PATIENT_LOGIN') NOT NULL;
