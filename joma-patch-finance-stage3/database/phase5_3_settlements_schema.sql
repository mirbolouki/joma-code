-- ═══════════════════════════════════════════════════════════════════
--  جوما — فاز ۵ (مرحلهٔ ۳): تسویه با مراکز مشاوره
--  این فایل توسط اسکریپت upgrade_phase5_3.php خوانده و اجرا می‌شود.
--
--  یک رکوردِ تسویه برای هر «مرکز × بازهٔ زمانی»:
--    - amount_due_rial: مبلغِ قابل‌پرداخت به مرکز (محاسبهٔ خودکار)
--        * مدل درصدی: درصد × مجموعِ پرداخت‌های «پرداخت‌شده»ِ جلساتِ
--          اتاق‌های آن مرکز که تاریخِ پرداختشان در بازه است
--        * مدل ساعتی: مجموع (ساعتِ جلسه × تعرفهٔ ساعتیِ اتاق)
--          برای نوبت‌های «انجام‌شده/رزوروشده»ِ آن بازه
--    - amount_paid_rial: مبلغِ واریزشده به مرکز
--  مبالغ به «ریال» ذخیره می‌شوند.
-- ═══════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

CREATE TABLE finance_center_settlements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    center_id BIGINT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    amount_due_rial DECIMAL(12,0) NOT NULL DEFAULT 0,
    amount_paid_rial DECIMAL(12,0) NULL,
    paid_bank_account_id BIGINT UNSIGNED NULL,
    paid_at DATETIME NULL,
    note VARCHAR(255) NULL,
    recorded_by_person_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    CONSTRAINT fk_fcs_center FOREIGN KEY (center_id)
        REFERENCES finance_centers(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_fcs_paid_account FOREIGN KEY (paid_bank_account_id)
        REFERENCES finance_bank_accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_fcs_recorder FOREIGN KEY (recorded_by_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    UNIQUE KEY uq_fcs_center_period (center_id, period_start, period_end),
    KEY idx_fcs_center (center_id),
    KEY idx_fcs_period (period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
