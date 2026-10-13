-- ═══════════════════════════════════════════════════════════════════
--  جوما — فاز ۵ (مرحلهٔ ۲): پرداختِ جلسات (بیعانه + الباقی)
--  این فایل توسط اسکریپت upgrade_phase5_2.php خوانده و اجرا می‌شود.
--
--  یک رکورد برای هر جلسه (UNIQUE appointment_id):
--    - بیعانه (deposit): مبلغِ پیش‌پرداختِ مراجع هنگام نوبت‌گیری
--    - الباقی (balance): الباقیِ وجه هنگام/پس از جلسه
--  مبالغ به «ریال» ذخیره می‌شوند (هم‌چون appointments.price_rial)
--  و در صفحه‌ها به «تومان» نمایش داده می‌شوند.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

CREATE TABLE finance_session_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    appointment_id BIGINT UNSIGNED NOT NULL,
    session_fee_rial DECIMAL(12,0) NOT NULL DEFAULT 0,
    deposit_amount_rial DECIMAL(12,0) NULL,
    deposit_bank_account_id BIGINT UNSIGNED NULL,
    deposit_status ENUM('PENDING','PAID') NOT NULL DEFAULT 'PENDING',
    deposit_paid_at DATETIME NULL,
    balance_amount_rial DECIMAL(12,0) NULL,
    balance_bank_account_id BIGINT UNSIGNED NULL,
    balance_status ENUM('PENDING','PAID') NOT NULL DEFAULT 'PENDING',
    balance_paid_at DATETIME NULL,
    recorded_by_person_id BIGINT UNSIGNED NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    CONSTRAINT fk_fsp_appointment FOREIGN KEY (appointment_id)
        REFERENCES appointments(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_fsp_deposit_account FOREIGN KEY (deposit_bank_account_id)
        REFERENCES finance_bank_accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_fsp_balance_account FOREIGN KEY (balance_bank_account_id)
        REFERENCES finance_bank_accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_fsp_recorder FOREIGN KEY (recorded_by_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    UNIQUE KEY uq_fsp_appointment (appointment_id),
    KEY idx_fsp_deposit_status (deposit_status),
    KEY idx_fsp_balance_status (balance_status),
    KEY idx_fsp_paid_at (deposit_paid_at, balance_paid_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
