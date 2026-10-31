-- EXPERIMENTAL DESIGN ONLY. DO NOT EXECUTE OR INSTALL.
-- Requires compatibility review and migration/integration tests on MySQL.
-- No existing data is read, changed, migrated or guessed by this draft.

CREATE TABLE finance_v2_rates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    therapist_person_id BIGINT UNSIGNED NOT NULL,
    service_type_id BIGINT UNSIGNED NOT NULL,
    rate_rial_per_minute DECIMAL(18,0) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    UNIQUE KEY uq_fv2_rate_pair (therapist_person_id, service_type_id),
    CONSTRAINT fk_fv2_rate_therapist FOREIGN KEY (therapist_person_id) REFERENCES persons(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_rate_service FOREIGN KEY (service_type_id) REFERENCES service_types(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exactly one of request_id or appointment_id must be non-NULL. The application
-- must enforce this: older MySQL versions may ignore CHECK constraints.
-- The appointment's rate is captured once, never recomputed from today's rate.
CREATE TABLE finance_v2_wallets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_id BIGINT UNSIGNED NULL,
    appointment_id BIGINT UNSIGNED NULL,
    rate_rial_per_minute DECIMAL(18,0) NULL,
    rate_service_type_id BIGINT UNSIGNED NULL,
    rate_therapist_person_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_fv2_wallet_request (request_id),
    UNIQUE KEY uq_fv2_wallet_appointment (appointment_id),
    CONSTRAINT fk_fv2_wallet_request FOREIGN KEY (request_id) REFERENCES booking_requests(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_wallet_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_wallet_service FOREIGN KEY (rate_service_type_id) REFERENCES service_types(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_wallet_therapist FOREIGN KEY (rate_therapist_person_id) REFERENCES persons(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only events. TRANSFER is a single atomic event from wallet_id to
-- destination_wallet_id, never a cash receipt. A CHARGE event replaces the
-- previous charge for that appointment but never changes cash. Events must be
-- validated under a transaction with both wallet rows locked in ID order.
CREATE TABLE finance_v2_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    operation_key VARCHAR(100) NOT NULL,
    event_type ENUM('RECEIPT','REFUND','TRANSFER','CHARGE') NOT NULL,
    receipt_kind ENUM('DEPOSIT','PAYMENT') NULL,
    wallet_id BIGINT UNSIGNED NOT NULL,
    destination_wallet_id BIGINT UNSIGNED NULL,
    amount_rial DECIMAL(18,0) NOT NULL,
    account_id BIGINT UNSIGNED NULL,
    actual_minutes INT UNSIGNED NULL,
    actual_session_date DATE NULL, -- clinic-local date explicitly supplied, not inferred
    calculated_rial DECIMAL(18,0) NULL,
    reason VARCHAR(255) NULL,
    occurred_at DATETIME NOT NULL,
    recorded_at DATETIME NOT NULL,
    recorded_by_person_id BIGINT UNSIGNED NOT NULL,
    recorded_by_role_code VARCHAR(30) NOT NULL,
    UNIQUE KEY uq_fv2_event_operation (operation_key),
    KEY idx_fv2_event_wallet (wallet_id, id),
    KEY idx_fv2_event_destination (destination_wallet_id, id),
    KEY idx_fv2_event_occurred (occurred_at),
    CONSTRAINT fk_fv2_event_wallet FOREIGN KEY (wallet_id) REFERENCES finance_v2_wallets(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_event_destination FOREIGN KEY (destination_wallet_id) REFERENCES finance_v2_wallets(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_event_account FOREIGN KEY (account_id) REFERENCES finance_bank_accounts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_event_recorder FOREIGN KEY (recorded_by_person_id) REFERENCES persons(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate history is append-only, separate from per-appointment snapshots.
CREATE TABLE finance_v2_rate_changes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    rate_id BIGINT UNSIGNED NOT NULL,
    old_rate_rial DECIMAL(18,0) NULL,
    old_active TINYINT(1) NULL,
    new_rate_rial DECIMAL(18,0) NOT NULL,
    new_active TINYINT(1) NOT NULL,
    recorded_by_person_id BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL,
    KEY idx_fv2_rate_change_rate (rate_id, id),
    CONSTRAINT fk_fv2_rate_change_rate FOREIGN KEY (rate_id) REFERENCES finance_v2_rates(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_rate_change_actor FOREIGN KEY (recorded_by_person_id) REFERENCES persons(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- General operating expense records; no automatic import of historic data.
-- Later corrections/voids are stored separately; originals are never changed.
CREATE TABLE finance_v2_expenses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    operation_key VARCHAR(100) NOT NULL,
    expense_date DATE NOT NULL,
    category VARCHAR(100) NOT NULL,
    amount_rial DECIMAL(18,0) NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    description VARCHAR(255) NULL,
    recorded_by_person_id BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL,
    UNIQUE KEY uq_fv2_expense_operation (operation_key),
    KEY idx_fv2_expense_date (expense_date),
    CONSTRAINT fk_fv2_expense_account FOREIGN KEY (account_id) REFERENCES finance_bank_accounts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_expense_actor FOREIGN KEY (recorded_by_person_id) REFERENCES persons(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every revision is append-only. A VOID corrects an erroneous expense record,
-- not an actual refund; accounting for real repayments is a separate workflow.
CREATE TABLE finance_v2_expense_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    expense_id BIGINT UNSIGNED NOT NULL,
    operation_key VARCHAR(100) NOT NULL,
    action ENUM('CORRECT','VOID') NOT NULL,
    expense_date DATE NULL,
    category VARCHAR(100) NULL,
    amount_rial DECIMAL(18,0) NULL,
    account_id BIGINT UNSIGNED NULL,
    description VARCHAR(255) NULL,
    reason VARCHAR(255) NOT NULL,
    recorded_by_person_id BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL,
    UNIQUE KEY uq_fv2_expense_revision_operation (operation_key),
    KEY idx_fv2_expense_revision (expense_id,id),
    CONSTRAINT fk_fv2_expense_revision_expense FOREIGN KEY (expense_id) REFERENCES finance_v2_expenses(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_expense_revision_account FOREIGN KEY (account_id) REFERENCES finance_bank_accounts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_expense_revision_actor FOREIGN KEY (recorded_by_person_id) REFERENCES persons(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One explicit, audited assignment for an appointment that was created without
-- a configured rate. A rate that was already snapshotted is never overwritten.
CREATE TABLE finance_v2_rate_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    wallet_id BIGINT UNSIGNED NOT NULL,
    rate_rial_per_minute DECIMAL(18,0) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    recorded_by_person_id BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL,
    UNIQUE KEY uq_fv2_rate_assignment_wallet (wallet_id),
    CONSTRAINT fk_fv2_rate_assignment_wallet FOREIGN KEY (wallet_id) REFERENCES finance_v2_wallets(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_rate_assignment_actor FOREIGN KEY (recorded_by_person_id) REFERENCES persons(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Time basis of each center; absent means not configured, never guessed.
CREATE TABLE finance_v2_center_basis (
    center_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    time_basis ENUM('ACTUAL','RESERVED') NOT NULL,
    updated_by_person_id BIGINT UNSIGNED NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_fv2_basis_center FOREIGN KEY (center_id) REFERENCES finance_centers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_basis_actor FOREIGN KEY (updated_by_person_id) REFERENCES persons(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE finance_v2_center_basis_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    center_id BIGINT UNSIGNED NOT NULL,
    old_basis ENUM('ACTUAL','RESERVED') NULL,
    new_basis ENUM('ACTUAL','RESERVED') NOT NULL,
    recorded_by_person_id BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL,
    KEY idx_fv2_center_basis_event (center_id,id),
    CONSTRAINT fk_fv2_basis_event_center FOREIGN KEY (center_id) REFERENCES finance_centers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_basis_event_actor FOREIGN KEY (recorded_by_person_id) REFERENCES persons(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- New hourly settlement audit: retain the actual calculation and source rows.
CREATE TABLE finance_v2_center_settlement_details (
    settlement_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    time_basis ENUM('ACTUAL','RESERVED') NOT NULL,
    exact_whole_rial DECIMAL(18,0) NOT NULL,
    exact_remainder SMALLINT UNSIGNED NOT NULL,
    exact_denominator SMALLINT UNSIGNED NOT NULL,
    total_minutes BIGINT UNSIGNED NOT NULL,
    manual_reason VARCHAR(255) NULL,
    CONSTRAINT fk_fv2_settlement_detail FOREIGN KEY (settlement_id) REFERENCES finance_center_settlements(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE finance_v2_center_settlement_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    settlement_id BIGINT UNSIGNED NOT NULL,
    appointment_id BIGINT UNSIGNED NOT NULL,
    minutes INT UNSIGNED NOT NULL,
    hourly_rate_toman DECIMAL(12,2) NOT NULL,
    source_charge_event_id BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_fv2_settlement_appointment (appointment_id),
    CONSTRAINT fk_fv2_item_settlement FOREIGN KEY (settlement_id) REFERENCES finance_center_settlements(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_item_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_item_event FOREIGN KEY (source_charge_event_id) REFERENCES finance_v2_events(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
