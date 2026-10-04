-- ---------------------------------------------------------------
-- Joma Clinic Management System - Phase 2 schema (version 2.0.0)
-- Migration 002: appointments and calendar.
-- 5 new tables. Engine: InnoDB. Charset: utf8mb4_unicode_ci.
-- This file is executed automatically by upgrade_phase2.php.
-- No Phase 1 table or column is modified.
-- All foreign keys use ON DELETE RESTRICT (no physical deletion),
-- consistent with ADR-009 of Phase 1.
-- All DATETIME values are stored in UTC.
-- hold_date / hold_start_time / hold_end_time are clinic local
-- wall-clock values (Asia/Tehran) because they describe a weekly
-- schedule, not an instant in time.
-- ---------------------------------------------------------------

SET NAMES utf8mb4;

CREATE TABLE rooms (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    room_number VARCHAR(20) NULL,
    capacity TINYINT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    created_at DATETIME NOT NULL,
    KEY idx_rooms_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE therapist_service_tariffs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    therapist_person_id BIGINT UNSIGNED NOT NULL,
    service_type_id BIGINT UNSIGNED NOT NULL,
    price_per_session DECIMAL(12,0) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'IRR',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    CONSTRAINT fk_tariff_therapist FOREIGN KEY (therapist_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_tariff_service FOREIGN KEY (service_type_id)
        REFERENCES service_types(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    UNIQUE KEY uq_tariff (therapist_person_id, service_type_id),
    KEY idx_tariff_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointment_holds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    therapist_person_id BIGINT UNSIGNED NOT NULL,
    room_id BIGINT UNSIGNED NULL,
    hold_date DATE NOT NULL,
    hold_start_time TIME NOT NULL,
    hold_end_time TIME NOT NULL,
    hold_start_utc DATETIME NOT NULL,
    hold_end_utc DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_hold_therapist FOREIGN KEY (therapist_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_hold_room FOREIGN KEY (room_id)
        REFERENCES rooms(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    UNIQUE KEY uq_hold_slot (therapist_person_id, hold_date, hold_start_time),
    KEY idx_hold_therapist_date (therapist_person_id, hold_date),
    KEY idx_hold_start_utc (hold_start_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    admission_id BIGINT UNSIGNED NOT NULL,
    therapist_person_id BIGINT UNSIGNED NOT NULL,
    service_type_id BIGINT UNSIGNED NOT NULL,
    room_id BIGINT UNSIGNED NOT NULL,
    appointment_start_utc DATETIME NOT NULL,
    appointment_end_utc DATETIME NOT NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL,
    status ENUM('SCHEDULED','COMPLETED','CANCELLED','NO_SHOW') NOT NULL DEFAULT 'SCHEDULED',
    notes TEXT NULL,
    cancellation_reason_id BIGINT UNSIGNED NULL,
    cancelled_by_role_code VARCHAR(20) NULL,
    cancelled_at DATETIME NULL,
    created_by_person_id BIGINT UNSIGNED NOT NULL,
    created_by_role_code VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_appt_admission FOREIGN KEY (admission_id)
        REFERENCES admissions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_appt_therapist FOREIGN KEY (therapist_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_appt_service FOREIGN KEY (service_type_id)
        REFERENCES service_types(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_appt_room FOREIGN KEY (room_id)
        REFERENCES rooms(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_appt_cancel_reason FOREIGN KEY (cancellation_reason_id)
        REFERENCES lookup_items(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_appt_creator FOREIGN KEY (created_by_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    KEY idx_appt_status (status),
    KEY idx_appt_start (appointment_start_utc),
    KEY idx_appt_room_start (room_id, appointment_start_utc),
    KEY idx_appt_therapist_start (therapist_person_id, appointment_start_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE therapist_absence_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    therapist_person_id BIGINT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason_id BIGINT UNSIGNED NULL,
    is_repeating TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_absence_therapist FOREIGN KEY (therapist_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_absence_reason FOREIGN KEY (reason_id)
        REFERENCES lookup_items(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    KEY idx_absence_therapist_date (therapist_person_id, start_date, end_date),
    KEY idx_absence_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
