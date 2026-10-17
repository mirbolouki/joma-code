-- جوما — پیام امنِ پورتال مراجع و وضعیت اعلان پیامکی
CREATE TABLE patient_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    patient_person_id BIGINT UNSIGNED NOT NULL,
    admission_id BIGINT UNSIGNED NULL,
    message_type ENUM('MANUAL','ADMISSION_ACCEPTED','APPOINTMENT_CONFIRMED','FORM_ASSIGNED') NOT NULL,
    event_key VARCHAR(120) NULL,
    subject VARCHAR(160) NOT NULL,
    body TEXT NOT NULL,
    created_by_person_id BIGINT UNSIGNED NULL,
    created_by_role_code VARCHAR(32) NULL,
    sms_status ENUM('PENDING','SENT','FAILED','DISABLED','DEV_LOGGED') NOT NULL DEFAULT 'PENDING',
    sms_error VARCHAR(64) NULL,
    sms_sent_at DATETIME NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_patient_messages_patient FOREIGN KEY (patient_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_patient_messages_admission FOREIGN KEY (admission_id)
        REFERENCES admissions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_patient_messages_creator FOREIGN KEY (created_by_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    UNIQUE KEY uq_patient_messages_event_key (event_key),
    KEY idx_patient_messages_patient_created (patient_person_id, created_at, id),
    KEY idx_patient_messages_patient_read (patient_person_id, read_at),
    KEY idx_patient_messages_admission_created (admission_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
