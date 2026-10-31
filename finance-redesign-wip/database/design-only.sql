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
