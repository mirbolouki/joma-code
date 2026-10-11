-- ═══════════════════════════════════════════════════════════════════
--  جوما — فاز ۵ (مرحلهٔ ۱): مدیریت مالی — زیرساخت
--  این فایل توسط اسکریپت upgrade_phase5.php خوانده و اجرا می‌شود.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- مراکز مشاوره‌ای که کلینیک در آن‌ها اتاق اجاره می‌کند.
-- commission_model:
--   PERCENTAGE = مرکز، درصدی از مجموع وجوه مراجعان را می‌گیرد
--   HOURLY     = مرکز، به‌ازای هر ساعت استفاده از اتاق، مبلغ ثابت می‌گیرد
-- settlement_period:
--   MONTHLY = تسویه با مرکز، ماهیانه
--   DAILY   = تسویه با مرکز، روزانه
CREATE TABLE finance_centers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    address VARCHAR(255) NULL,
    phone VARCHAR(20) NULL,
    commission_model ENUM('PERCENTAGE','HOURLY') NOT NULL DEFAULT 'PERCENTAGE',
    commission_percent DECIMAL(5,2) NULL,
    settlement_period ENUM('DAILY','MONTHLY') NOT NULL DEFAULT 'MONTHLY',
    status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    created_at DATETIME NOT NULL,
    KEY idx_finance_centers_status (status),
    KEY idx_finance_centers_model (commission_model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- حساب‌های بانکیِ کلینیک (شمارهٔ کارت یا حساب برای واریز وجه توسط مراجع)
CREATE TABLE finance_bank_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    label VARCHAR(100) NOT NULL,
    bank_name VARCHAR(100) NULL,
    account_number VARCHAR(50) NULL,
    card_number VARCHAR(30) NULL,
    status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    created_at DATETIME NOT NULL,
    KEY idx_finance_bank_accounts_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
