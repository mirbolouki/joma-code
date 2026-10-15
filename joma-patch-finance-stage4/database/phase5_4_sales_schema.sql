-- ═══════════════════════════════════════════════════════════════════
--  جوما — فاز ۵ (مرحلهٔ ۴): فروشِ تست‌ها و پکیج‌های تمرینی
--  این فایل توسط اسکریپت upgrade_phase5_4.php خوانده و اجرا می‌شود.
--
--  فروشِ تست: پول از درگاهِ خودِ شما می‌آید؛ سیستم فقط فروش را ثبت
--  می‌کند (مراجع، نامِ تست، مبلغ، حسابِ بانکی، وضعیت).
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- فروشِ تست‌ها (فقط ثبت؛ وجه از درگاهِ شما می‌آید)
CREATE TABLE finance_test_sales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    patient_person_id BIGINT UNSIGNED NOT NULL,
    test_name VARCHAR(150) NOT NULL,
    amount_rial DECIMAL(12,0) NOT NULL DEFAULT 0,
    paid_bank_account_id BIGINT UNSIGNED NULL,
    status ENUM('PENDING','PAID') NOT NULL DEFAULT 'PENDING',
    paid_at DATETIME NULL,
    note VARCHAR(255) NULL,
    recorded_by_person_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    CONSTRAINT fk_fts_patient FOREIGN KEY (patient_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_fts_account FOREIGN KEY (paid_bank_account_id)
        REFERENCES finance_bank_accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_fts_recorder FOREIGN KEY (recorded_by_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    KEY idx_fts_patient (patient_person_id),
    KEY idx_fts_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- فروشِ پکیج‌های تمرینیِ یک‌ماهه (تمرین‌ها خارج از سیستم انجام می‌شوند؛
--  سیستم فقط فروش + بازهٔ اعتبارِ یک‌ماهه را ثبت می‌کند)
CREATE TABLE finance_package_sales (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    patient_person_id BIGINT UNSIGNED NOT NULL,
    package_name VARCHAR(150) NOT NULL,
    amount_rial DECIMAL(12,0) NOT NULL DEFAULT 0,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    paid_bank_account_id BIGINT UNSIGNED NULL,
    status ENUM('PENDING','PAID') NOT NULL DEFAULT 'PENDING',
    paid_at DATETIME NULL,
    note VARCHAR(255) NULL,
    recorded_by_person_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    CONSTRAINT fk_fps_patient FOREIGN KEY (patient_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_fps_account FOREIGN KEY (paid_bank_account_id)
        REFERENCES finance_bank_accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_fps_recorder FOREIGN KEY (recorded_by_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    KEY idx_fps_patient (patient_person_id),
    KEY idx_fps_status (status),
    KEY idx_fps_period (period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
