-- EXPERIMENTAL DESIGN ONLY. DO NOT EXECUTE OR INSTALL.
-- Requires compatibility review and migration/integration tests on MySQL.
-- No existing data is read, changed, migrated or guessed by this draft.

-- WIP serialization point. It must exist before any finance V2 writes.
CREATE TABLE finance_v2_locks (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO finance_v2_locks (id) VALUES (1);

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
    -- NULL may mean an appointment had no center. The flag distinguishes
    -- that known fact from historical rows with no center snapshot.
    center_snapshot_recorded TINYINT(1) NOT NULL DEFAULT 0,
    source_center_id BIGINT UNSIGNED NULL,
    destination_center_id BIGINT UNSIGNED NULL,
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
    CONSTRAINT fk_fv2_event_source_center FOREIGN KEY (source_center_id) REFERENCES finance_centers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_event_destination_center FOREIGN KEY (destination_center_id) REFERENCES finance_centers(id) ON DELETE RESTRICT,
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

-- Explicit history of human-verified center attribution for legacy payments.
-- Each assertion applies only to the immutable fingerprint of the old record.
-- For each PAID component, NULL means explicitly verified as no center.
CREATE TABLE finance_v2_legacy_center_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT UNSIGNED NOT NULL,
    deposit_center_id BIGINT UNSIGNED NULL,
    balance_center_id BIGINT UNSIGNED NULL,
    payment_fingerprint CHAR(64) NOT NULL,
    payment_snapshot_json TEXT NOT NULL,
    reason VARCHAR(255) NOT NULL,
    recorded_by_person_id BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL,
    KEY idx_fv2_legacy_review (payment_id,id),
    CONSTRAINT fk_fv2_legacy_review_payment FOREIGN KEY (payment_id) REFERENCES finance_session_payments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_legacy_review_dep_center FOREIGN KEY (deposit_center_id) REFERENCES finance_centers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_legacy_review_bal_center FOREIGN KEY (balance_center_id) REFERENCES finance_centers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_legacy_review_actor FOREIGN KEY (recorded_by_person_id) REFERENCES persons(id) ON DELETE RESTRICT
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

-- WIP percentage settlement audit; old settlements are left untouched.
CREATE TABLE finance_v2_percentage_details (
    settlement_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    percent_basis_points SMALLINT UNSIGNED NOT NULL,
    gross_rial DECIMAL(18,0) NOT NULL,
    exact_whole_rial DECIMAL(18,0) NOT NULL,
    exact_remainder SMALLINT UNSIGNED NOT NULL,
    exact_denominator SMALLINT UNSIGNED NOT NULL,
    manual_item_count INT UNSIGNED NOT NULL,
    manual_reason VARCHAR(255) NULL,
    CONSTRAINT fk_fv2_percentage_settlement FOREIGN KEY (settlement_id) REFERENCES finance_center_settlements(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE finance_v2_percentage_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    settlement_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('DIRECT_RECEIPT','REQUEST_TRANSFER','LEGACY_DEPOSIT','LEGACY_BALANCE','REFUND','APPOINTMENT_TRANSFER') NOT NULL,
    event_id BIGINT UNSIGNED NULL,
    legacy_payment_id BIGINT UNSIGNED NULL,
    legacy_review_id BIGINT UNSIGNED NULL,
    amount_rial DECIMAL(18,0) NOT NULL,
    occurred_at DATETIME NOT NULL,
    UNIQUE KEY uq_fv2_percentage_event (settlement_id,event_id),
    UNIQUE KEY uq_fv2_percentage_legacy (settlement_id,legacy_payment_id,kind),
    CONSTRAINT fk_fv2_percentage_item_settle FOREIGN KEY (settlement_id) REFERENCES finance_center_settlements(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_percentage_item_event FOREIGN KEY (event_id) REFERENCES finance_v2_events(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_percentage_item_payment FOREIGN KEY (legacy_payment_id) REFERENCES finance_session_payments(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_percentage_item_review FOREIGN KEY (legacy_review_id) REFERENCES finance_v2_legacy_center_reviews(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An event may be entered after a same-day percentage settlement. Do not
-- prevent recording real cash movements; flag them for a future adjustment.
CREATE TABLE finance_v2_percentage_late_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    settlement_id BIGINT UNSIGNED NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    detected_at DATETIME NOT NULL,
    UNIQUE KEY uq_fv2_percentage_late_event (settlement_id,event_id),
    CONSTRAINT fk_fv2_percentage_late_settle FOREIGN KEY (settlement_id) REFERENCES finance_center_settlements(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_percentage_late_event FOREIGN KEY (event_id) REFERENCES finance_v2_events(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Review notes are evidence only: they do not discharge or modify a debt,
-- nor do they remove the late-event block. A future financial adjustment must
-- be designed together with payments, balances and reporting.
CREATE TABLE finance_v2_percentage_late_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    late_event_id BIGINT UNSIGNED NOT NULL,
    review_kind VARCHAR(24) NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    recorded_by_person_id BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL,
    KEY idx_fv2_late_review_history (late_event_id,id),
    CONSTRAINT fk_fv2_late_review_event FOREIGN KEY (late_event_id) REFERENCES finance_v2_percentage_late_events(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_late_review_person FOREIGN KEY (recorded_by_person_id) REFERENCES persons(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Experimental financial resolution of late percentage events. The manually
-- agreed signed delta belongs to the original new percentage settlement;
-- old settlement records are never rewritten. This is not an installer.
CREATE TABLE finance_v2_percentage_late_resolutions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    late_event_id BIGINT UNSIGNED NOT NULL,
    operation_key VARCHAR(100) NOT NULL,
    delta_rial DECIMAL(18,0) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    recorded_by_person_id BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL,
    UNIQUE KEY uq_fv2_late_resolution_event (late_event_id),
    UNIQUE KEY uq_fv2_late_resolution_operation (operation_key),
    CONSTRAINT fk_fv2_late_resolution_event FOREIGN KEY (late_event_id) REFERENCES finance_v2_percentage_late_events(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_late_resolution_actor FOREIGN KEY (recorded_by_person_id) REFERENCES persons(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cash movements with a center are separate entries, not overwrites of the
-- legacy amount_paid_rial field. RETURN means money received from the center.
CREATE TABLE finance_v2_center_payment_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    settlement_id BIGINT UNSIGNED NOT NULL,
    operation_key VARCHAR(100) NOT NULL,
    kind ENUM('PAYMENT','RETURN') NOT NULL,
    amount_rial DECIMAL(18,0) NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(255) NOT NULL,
    recorded_by_person_id BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL,
    UNIQUE KEY uq_fv2_center_payment_operation (operation_key),
    KEY idx_fv2_center_payment_settlement (settlement_id,id),
    CONSTRAINT fk_fv2_center_payment_settlement FOREIGN KEY (settlement_id) REFERENCES finance_center_settlements(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_center_payment_account FOREIGN KEY (account_id) REFERENCES finance_bank_accounts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_center_payment_actor FOREIGN KEY (recorded_by_person_id) REFERENCES persons(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An agreed late-event effect can itself be corrected without rewriting its
-- original value or earlier corrections. The latest version replaces (does
-- not add to) the preceding agreed delta for that same resolution.
CREATE TABLE finance_v2_percentage_resolution_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    resolution_id BIGINT UNSIGNED NOT NULL,
    operation_key VARCHAR(100) NOT NULL,
    delta_rial DECIMAL(18,0) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    recorded_by_person_id BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL,
    UNIQUE KEY uq_fv2_resolution_revision_operation (operation_key),
    KEY idx_fv2_resolution_revision_history (resolution_id,id),
    CONSTRAINT fk_fv2_resolution_revision_parent FOREIGN KEY (resolution_id) REFERENCES finance_v2_percentage_late_resolutions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_resolution_revision_actor FOREIGN KEY (recorded_by_person_id) REFERENCES persons(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Corrections of incorrectly entered cash records are not real repayments.
-- They append an audited version; VOID only marks the entry as erroneous.
CREATE TABLE finance_v2_center_payment_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    payment_event_id BIGINT UNSIGNED NOT NULL,
    operation_key VARCHAR(100) NOT NULL,
    action ENUM('CORRECT','VOID') NOT NULL,
    kind ENUM('PAYMENT','RETURN') NULL,
    amount_rial DECIMAL(18,0) NULL,
    account_id BIGINT UNSIGNED NULL,
    reason VARCHAR(255) NOT NULL,
    recorded_by_person_id BIGINT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL,
    UNIQUE KEY uq_fv2_center_payment_revision_operation (operation_key),
    KEY idx_fv2_center_payment_revision_history (payment_event_id,id),
    CONSTRAINT fk_fv2_center_payment_revision_parent FOREIGN KEY (payment_event_id) REFERENCES finance_v2_center_payment_events(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_center_payment_revision_account FOREIGN KEY (account_id) REFERENCES finance_bank_accounts(id) ON DELETE RESTRICT,
    CONSTRAINT fk_fv2_center_payment_revision_actor FOREIGN KEY (recorded_by_person_id) REFERENCES persons(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
