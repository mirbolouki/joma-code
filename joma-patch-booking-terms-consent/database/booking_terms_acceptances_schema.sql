-- جوما — ثبتِ نسخه و پذیرش ضوابطِ درخواست نوبت (نسخهٔ ۶.۳.۰)
-- یک ردیف برای هر درخواستِ تأییدشده با OTP؛ OTP خام هرگز ذخیره نمی‌شود.
CREATE TABLE booking_terms_acceptances (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id VARCHAR(32) NOT NULL,
    booking_request_id BIGINT UNSIGNED NOT NULL,
    terms_version VARCHAR(64) NOT NULL,
    terms_sha256 CHAR(64) NOT NULL,
    terms_snapshot LONGTEXT NOT NULL,
    ack_general TINYINT UNSIGNED NOT NULL,
    ack_privacy_recording TINYINT UNSIGNED NOT NULL,
    ack_deposit_cancellation TINYINT UNSIGNED NOT NULL,
    ack_adult_independence TINYINT UNSIGNED NOT NULL,
    accepted_mobile VARCHAR(20) NOT NULL,
    accepted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_booking_terms_public_id (public_id),
    UNIQUE KEY uq_booking_terms_request (booking_request_id),
    KEY idx_booking_terms_accepted_at (accepted_at),
    CONSTRAINT fk_booking_terms_request
        FOREIGN KEY (booking_request_id) REFERENCES booking_requests (id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
