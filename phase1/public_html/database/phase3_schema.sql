-- ============================================================
--  Joma Clinic Management System - Phase 3 schema
--  Clinical file: confidential therapist notes
--
--  Rules enforced here:
--    * One new table only. Archiving is deferred to a later phase.
--    * All foreign keys RESTRICT. Nothing is ever physically deleted.
--    * Notes are soft-retracted (status = 'RETRACTED'), never removed.
--    * No encryption at rest (owner decision, risk P1/P2 accepted).
--    * Access control lives in the application layer: every read must
--      filter on BOTH clinical_case_id AND author_person_id.
--
--  This file is parsed by upgrade_phase3.php, which creates the table
--  only when it does not already exist. Running it twice is safe.
-- ============================================================

CREATE TABLE confidential_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,

    clinical_case_id BIGINT UNSIGNED NOT NULL,
    author_person_id BIGINT UNSIGNED NOT NULL,

    note_text MEDIUMTEXT NOT NULL,
    character_count INT UNSIGNED NOT NULL,

    status ENUM('ACTIVE','RETRACTED') NOT NULL DEFAULT 'ACTIVE',

    created_at DATETIME NOT NULL,
    edited_at DATETIME NULL,
    edit_count INT UNSIGNED NOT NULL DEFAULT 0,
    retracted_at DATETIME NULL,
    retraction_reason_id BIGINT UNSIGNED NULL,

    last_accessed_at DATETIME NULL,
    view_count INT UNSIGNED NOT NULL DEFAULT 0,

    CONSTRAINT fk_note_case FOREIGN KEY (clinical_case_id)
        REFERENCES clinical_cases(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_note_author FOREIGN KEY (author_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_note_retract_reason FOREIGN KEY (retraction_reason_id)
        REFERENCES lookup_items(id) ON DELETE RESTRICT ON UPDATE RESTRICT,

    KEY idx_note_case_author (clinical_case_id, author_person_id, status),
    KEY idx_note_author_created (author_person_id, created_at),
    KEY idx_note_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
