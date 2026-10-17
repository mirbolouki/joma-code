-- جوما — پورتال مراجع، فاز ۲: درخواست نوبت، لغو یا جابه‌جایی
-- این جدول فقط درخواست‌ها را نگه می‌دارد؛ خودش نوبت را تغییر نمی‌دهد.
CREATE TABLE patient_appointment_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_id VARCHAR(30) NOT NULL UNIQUE,
    patient_person_id BIGINT UNSIGNED NOT NULL,
    admission_id BIGINT UNSIGNED NOT NULL,
    appointment_id BIGINT UNSIGNED NULL,
    request_type ENUM('NEW_APPOINTMENT','CANCEL_APPOINTMENT','RESCHEDULE_APPOINTMENT') NOT NULL,
    preferred_day_code VARCHAR(20) NULL,
    preferred_window_code VARCHAR(20) NULL,
    preferred_text VARCHAR(100) NULL,
    patient_note VARCHAR(300) NULL,
    status ENUM('NEW','CONTACTED','COMPLETED','REJECTED','WITHDRAWN') NOT NULL DEFAULT 'NEW',
    patient_response VARCHAR(300) NULL,
    staff_note TEXT NULL,
    handled_by_person_id BIGINT UNSIGNED NULL,
    handled_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    CONSTRAINT fk_par_patient FOREIGN KEY (patient_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_par_admission FOREIGN KEY (admission_id)
        REFERENCES admissions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_par_appointment FOREIGN KEY (appointment_id)
        REFERENCES appointments(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_par_handler FOREIGN KEY (handled_by_person_id)
        REFERENCES persons(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    KEY idx_par_patient_created (patient_person_id, created_at),
    KEY idx_par_patient_status (patient_person_id, status),
    KEY idx_par_status_created (status, created_at),
    KEY idx_par_appointment_status (appointment_id, status),
    KEY idx_par_admission_status (admission_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
