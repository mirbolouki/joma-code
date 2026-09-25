# جوما — Physical ERD v0.1

این نمودارها از همان منبع DDL تولید شده‌اند؛ سند اصلی توضیحات: JOMA-PHYSICAL-SCHEMA-v0.1-FA.md.
نوع‌های Mermaid خلاصه‌اند؛ طول دقیق، UNSIGNED، collation، generated column و CHECKها در 001_core.sql مرجع قطعی‌اند.
جدول بیرونی در هر نما فقط با id نشان داده می‌شود. UNIQUE مرکب و شروط مجوز از خطوط نمودار به‌تنهایی قابل استنباط نیستند.
این طرح مرورپذیر است، نه گزارش اجرای موفق روی MySQL یا تضمین امنیت.

## هویت و نقش

```mermaid
erDiagram
    joma_accounts {
        BINARY16 id PK
        BINARY16 person_id FK
        VARCHAR login_name UK
        VARCHAR password_hash
        VARCHAR status
        BINARY16 active_person_id UK
        DATETIME created_at
    }
    joma_contact_points {
        BINARY16 id PK
        VARCHAR kind
        VARCHAR normalized_value
        DATETIME created_at
    }
    joma_memberships {
        BINARY16 id PK
        BINARY16 person_id FK
        BINARY16 scope_id FK
        VARCHAR status
        DATETIME valid_from
        DATETIME valid_until
        DATETIME created_at
    }
    joma_person_contacts {
        BINARY16 id PK
        BINARY16 person_id FK
        BINARY16 contact_id FK
        VARCHAR relationship_label
        DATETIME verified_at
        DATETIME created_at
    }
    joma_persons {
        BINARY16 id PK
        VARCHAR given_name
        VARCHAR family_name
        DATE birth_date
        VARCHAR sex_code
        VARCHAR status
        DATETIME created_at
    }
    joma_professional_credentials {
        BINARY16 id PK
        BINARY16 profile_id FK
        VARCHAR credential_type
        VARCHAR issuer
        VARCHAR credential_reference
        DATE valid_until
        DATETIME created_at
    }
    joma_professional_profiles {
        BINARY16 id PK
        BINARY16 person_id FK
        VARCHAR professional_type
        JSON specialties_json
        DATETIME created_at
    }
    joma_role_assignments {
        BINARY16 id PK
        BINARY16 account_id FK
        BINARY16 person_id FK
        BINARY16 membership_id FK
        BINARY16 scope_id FK
        INT role_id FK
        DATETIME valid_from
        DATETIME valid_until
        DATETIME revoked_at
        DATETIME created_at
    }
    joma_role_definitions {
        INT id PK
        VARCHAR code UK
        VARCHAR label
        BOOLEAN is_active
        DATETIME created_at
    }
    joma_work_scopes {
        BINARY16 id PK
        VARCHAR kind
        BINARY16 parent_scope_id FK
        BINARY16 independent_practitioner_id FK
        VARCHAR label
        VARCHAR status
        DATETIME created_at
    }
    joma_persons ||--o{ joma_accounts : person_id
    joma_persons ||--o{ joma_person_contacts : person_id
    joma_contact_points ||--o{ joma_person_contacts : contact_id
    joma_work_scopes o|--o{ joma_work_scopes : parent_scope_id
    joma_persons o|--o{ joma_work_scopes : independent_practitioner_id
    joma_persons ||--o{ joma_memberships : person_id
    joma_work_scopes ||--o{ joma_memberships : scope_id
    joma_accounts ||--o{ joma_role_assignments : account_id
    joma_memberships ||--o{ joma_role_assignments : membership_id
    joma_role_definitions ||--o{ joma_role_assignments : role_id
    joma_persons ||--o{ joma_professional_profiles : person_id
    joma_professional_profiles ||--o{ joma_professional_credentials : profile_id
```

## خدمات و پرونده

```mermaid
erDiagram
    joma_admission_participants {
        BINARY16 id PK
        BINARY16 admission_id FK
        BINARY16 person_id FK
        VARCHAR participation_role
        DATETIME created_at
    }
    joma_admissions {
        BINARY16 id PK
        BINARY16 primary_subject_id FK
        BINARY16 scope_id FK
        BINARY16 offering_id FK
        BINARY16 recorded_by_person_id FK
        BINARY16 case_id FK
        VARCHAR status
        DATETIME created_at
    }
    joma_case_operational_contexts {
        BINARY16 id PK
        BINARY16 case_id FK
        BINARY16 scope_id FK
        DATETIME valid_from
        DATETIME valid_until
        VARCHAR basis_reference
        DATETIME created_at
    }
    joma_case_participants {
        BINARY16 id PK
        BINARY16 case_id FK
        BINARY16 person_id FK
        VARCHAR clinical_role
        DATETIME valid_from
        DATETIME valid_until
        DATETIME created_at
    }
    joma_clinical_cases {
        BINARY16 id PK
        BINARY16 relationship_id FK, UK
        BINARY16 responsible_therapist_id FK
        VARCHAR purpose_summary
        VARCHAR status
        DATETIME opened_at
        DATETIME closed_at
        DATETIME created_at
    }
    joma_command_receipts {
        BINARY16 id PK
    }
    joma_memberships {
        BINARY16 id PK
    }
    joma_persons {
        BINARY16 id PK
    }
    joma_responsibility_acceptances {
        BINARY16 id PK
        BINARY16 admission_id FK
        BINARY16 assignment_id FK, UK
        BINARY16 therapist_person_id FK
        BINARY16 actor_person_id
        BINARY16 command_id FK, UK
        DATETIME accepted_at
        DATETIME created_at
    }
    joma_service_definitions {
        INT id PK
        VARCHAR code UK
        VARCHAR label
        BOOLEAN is_active
        DATETIME created_at
    }
    joma_service_offerings {
        BINARY16 id PK
        BINARY16 scope_id FK
        INT service_id FK
        BINARY16 policy_version_id FK
        SMALLINT duration_minutes
        VARCHAR status
        DATETIME created_at
    }
    joma_service_policy_versions {
        BINARY16 id PK
        INT service_id FK
        INT version_no
        VARCHAR status
        JSON policy_json
        BINARY16 author_person_id FK
        BINARY16 approved_by_person_id FK
        DATETIME effective_at
        DATETIME created_at
    }
    joma_service_providers {
        BINARY16 id PK
        BINARY16 offering_id FK
        BINARY16 scope_id FK
        BINARY16 membership_id FK
        BINARY16 person_id FK
        DATETIME valid_from
        DATETIME valid_until
        DATETIME created_at
    }
    joma_therapeutic_relationships {
        BINARY16 id PK
        BINARY16 acceptance_id FK, UK
        BINARY16 therapist_person_id FK
        VARCHAR status
        DATETIME activated_at
        DATETIME ended_at
        DATETIME created_at
    }
    joma_therapist_assignments {
        BINARY16 id PK
        BINARY16 admission_id FK
        BINARY16 therapist_person_id FK
        BINARY16 assigned_by_person_id FK
        VARCHAR status
        BINARY16 open_admission_id UK
        DATETIME created_at
    }
    joma_work_scopes {
        BINARY16 id PK
    }
    joma_service_definitions ||--o{ joma_service_policy_versions : service_id
    joma_persons ||--o{ joma_service_policy_versions : author_person_id
    joma_persons o|--o{ joma_service_policy_versions : approved_by_person_id
    joma_work_scopes ||--o{ joma_service_offerings : scope_id
    joma_service_policy_versions ||--o{ joma_service_offerings : policy_version_id
    joma_service_offerings ||--o{ joma_service_providers : offering_id
    joma_memberships ||--o{ joma_service_providers : membership_id
    joma_persons ||--o{ joma_admissions : primary_subject_id
    joma_persons ||--o{ joma_admissions : recorded_by_person_id
    joma_service_offerings ||--o{ joma_admissions : offering_id
    joma_admissions ||--o{ joma_admission_participants : admission_id
    joma_persons ||--o{ joma_admission_participants : person_id
    joma_admissions ||--o{ joma_therapist_assignments : admission_id
    joma_persons ||--o{ joma_therapist_assignments : therapist_person_id
    joma_persons ||--o{ joma_therapist_assignments : assigned_by_person_id
    joma_admissions ||--o{ joma_responsibility_acceptances : admission_id
    joma_therapist_assignments o|--o{ joma_responsibility_acceptances : assignment_id
    joma_persons ||--o{ joma_responsibility_acceptances : therapist_person_id
    joma_command_receipts ||--o| joma_responsibility_acceptances : command_id
    joma_responsibility_acceptances ||--o{ joma_therapeutic_relationships : acceptance_id
    joma_therapeutic_relationships ||--o{ joma_clinical_cases : relationship_id
    joma_clinical_cases o|--o{ joma_admissions : case_id
    joma_clinical_cases ||--o{ joma_case_participants : case_id
    joma_persons ||--o{ joma_case_participants : person_id
    joma_clinical_cases ||--o{ joma_case_operational_contexts : case_id
    joma_work_scopes ||--o{ joma_case_operational_contexts : scope_id
```

## نمایندگی و اختیار

```mermaid
erDiagram
    joma_administrative_delegations {
        BINARY16 id PK
        BINARY16 grantor_role_assignment_id FK
        BINARY16 grantor_person_id FK
        BINARY16 grantee_membership_id FK
        BINARY16 grantee_person_id FK
        BINARY16 scope_id FK
        VARCHAR kind
        BINARY16 target_admission_id FK
        BINARY16 target_representation_id FK
        JSON allowed_actions_json
        DATETIME valid_from
        DATETIME valid_until
        DATETIME revoked_at
        DATETIME created_at
    }
    joma_admissions {
        BINARY16 id PK
    }
    joma_case_representation_restrictions {
        BINARY16 id PK
        BINARY16 representation_id FK
        BINARY16 case_id FK
        BINARY16 clinician_person_id FK
        INT service_id FK
        BINARY16 command_id FK, UK
        DATETIME suspended_at
        VARCHAR status
        VARCHAR reason_code
        DATETIME created_at
    }
    joma_clinical_cases {
        BINARY16 id PK
    }
    joma_command_receipts {
        BINARY16 id PK
    }
    joma_memberships {
        BINARY16 id PK
    }
    joma_persons {
        BINARY16 id PK
    }
    joma_representation_events {
        BINARY16 id PK
        BINARY16 representation_id FK
        BINARY16 command_id FK
        BINARY16 actor_person_id FK
        BINARY16 scope_id FK
        VARCHAR event_code
        VARCHAR reason_code
        DATETIME created_at
    }
    joma_representation_verifications {
        BINARY16 id PK
        BINARY16 representation_id FK
        BINARY16 representative_person_id FK
        BINARY16 subject_person_id FK
        BINARY16 scope_id FK
        BINARY16 authority_basis_id FK
        BINARY16 actor_person_id FK
        VARCHAR evidence_type
        VARCHAR policy_reference
        VARCHAR result
        DATETIME checked_at
        DATETIME created_at
    }
    joma_representations {
        BINARY16 id PK
        BINARY16 representative_person_id FK
        BINARY16 subject_person_id FK
        BINARY16 scope_id FK
        VARCHAR status
        JSON allowed_actions_json
        DATETIME valid_from
        DATETIME valid_until
        DATETIME revoked_at
        DATETIME created_at
    }
    joma_role_assignments {
        BINARY16 id PK
    }
    joma_service_definitions {
        INT id PK
    }
    joma_verification_authority_bases {
        BINARY16 id PK
        BINARY16 actor_person_id FK
        BINARY16 scope_id FK
        BINARY16 direct_role_assignment_id FK
        BINARY16 delegation_id FK
        DATETIME created_at
    }
    joma_work_scopes {
        BINARY16 id PK
    }
    joma_persons ||--o{ joma_representations : representative_person_id
    joma_persons ||--o{ joma_representations : subject_person_id
    joma_work_scopes ||--o{ joma_representations : scope_id
    joma_role_assignments ||--o{ joma_administrative_delegations : grantor_role_assignment_id
    joma_memberships ||--o{ joma_administrative_delegations : grantee_membership_id
    joma_admissions o|--o{ joma_administrative_delegations : target_admission_id
    joma_representations o|--o{ joma_administrative_delegations : target_representation_id
    joma_role_assignments o|--o{ joma_verification_authority_bases : direct_role_assignment_id
    joma_administrative_delegations o|--o{ joma_verification_authority_bases : delegation_id
    joma_representations ||--o{ joma_representation_verifications : representation_id
    joma_verification_authority_bases ||--o{ joma_representation_verifications : authority_basis_id
    joma_representations ||--o{ joma_representation_events : representation_id
    joma_command_receipts ||--o{ joma_representation_events : command_id
    joma_persons ||--o{ joma_representation_events : actor_person_id
    joma_work_scopes ||--o{ joma_representation_events : scope_id
    joma_representations ||--o{ joma_case_representation_restrictions : representation_id
    joma_clinical_cases ||--o{ joma_case_representation_restrictions : case_id
    joma_service_definitions o|--o{ joma_case_representation_restrictions : service_id
    joma_command_receipts ||--o| joma_case_representation_restrictions : command_id
```

## نوبت و جلسه

```mermaid
erDiagram
    joma_admissions {
        BINARY16 id PK
    }
    joma_appointment_allocations {
        BINARY16 id PK
        BINARY16 appointment_id FK
        BINARY16 resource_id FK
        DATETIME starts_at
        DATETIME ends_at
        DATETIME created_at
    }
    joma_appointment_changes {
        BINARY16 id PK
        BINARY16 appointment_id FK
        BINARY16 command_id FK, UK
        BINARY16 actor_person_id FK
        VARCHAR change_code
        JSON before_json
        JSON after_json
        VARCHAR reason_code
        DATETIME created_at
    }
    joma_appointment_requests {
        BINARY16 id PK
        BINARY16 subject_person_id FK
        BINARY16 admission_id FK
        BINARY16 case_id FK
        BINARY16 offering_id FK
        DATETIME preferred_start_at
        VARCHAR status
        BINARY16 fulfilled_appointment_id FK, UK
        DATETIME created_at
    }
    joma_appointments {
        BINARY16 id PK
        BINARY16 case_id FK
        BINARY16 therapist_person_id FK
        BINARY16 scope_id FK
        BINARY16 offering_id FK
        BINARY16 hold_id FK, UK
        BINARY16 rebooked_from_appointment_id FK
        DATETIME starts_at
        DATETIME ends_at
        VARCHAR status
        VARCHAR origin
        INT cancellation_notice_minutes
        BINARY16 command_id FK, UK
        DATETIME created_at
    }
    joma_attendance_observations {
        BINARY16 id PK
        BINARY16 appointment_id FK
        BINARY16 observed_person_id FK
        BINARY16 recorded_by_person_id FK
        DATETIME observed_at
        VARCHAR observation_code
        DATETIME created_at
    }
    joma_capacity_holds {
        BINARY16 id PK
        BINARY16 case_id FK
        BINARY16 offering_id FK
        BINARY16 policy_version_id FK
        BINARY16 request_id FK
        BINARY16 actor_person_id FK
        DATETIME starts_at
        DATETIME ends_at
        DATETIME held_at
        DATETIME expires_at
        VARCHAR status
        BINARY16 command_id FK, UK
        DATETIME created_at
    }
    joma_clinical_cases {
        BINARY16 id PK
    }
    joma_clinical_sessions {
        BINARY16 id PK
        BINARY16 case_id FK
        BINARY16 clinician_person_id FK
        BINARY16 appointment_id FK, UK
        DATETIME started_at
        DATETIME ended_at
        BINARY16 command_id FK, UK
        DATETIME created_at
    }
    joma_command_receipts {
        BINARY16 id PK
    }
    joma_hold_allocations {
        BINARY16 id PK
        BINARY16 hold_id FK
        BINARY16 resource_id FK
        DATETIME starts_at
        DATETIME ends_at
        DATETIME created_at
    }
    joma_persons {
        BINARY16 id PK
    }
    joma_schedule_resources {
        BINARY16 id PK
        BINARY16 scope_id FK
        BINARY16 therapist_person_id FK, UK
        VARCHAR kind
        VARCHAR label
        DATETIME created_at
    }
    joma_service_offerings {
        BINARY16 id PK
    }
    joma_service_policy_versions {
        BINARY16 id PK
    }
    joma_work_scopes {
        BINARY16 id PK
    }
    joma_persons ||--o{ joma_appointment_requests : subject_person_id
    joma_admissions o|--o{ joma_appointment_requests : admission_id
    joma_clinical_cases o|--o{ joma_appointment_requests : case_id
    joma_service_offerings ||--o{ joma_appointment_requests : offering_id
    joma_work_scopes o|--o{ joma_schedule_resources : scope_id
    joma_persons o|--o| joma_schedule_resources : therapist_person_id
    joma_clinical_cases ||--o{ joma_capacity_holds : case_id
    joma_service_offerings ||--o{ joma_capacity_holds : offering_id
    joma_service_policy_versions ||--o{ joma_capacity_holds : policy_version_id
    joma_appointment_requests o|--o{ joma_capacity_holds : request_id
    joma_persons ||--o{ joma_capacity_holds : actor_person_id
    joma_command_receipts ||--o| joma_capacity_holds : command_id
    joma_capacity_holds ||--o{ joma_hold_allocations : hold_id
    joma_schedule_resources ||--o{ joma_hold_allocations : resource_id
    joma_clinical_cases ||--o{ joma_appointments : case_id
    joma_service_offerings ||--o{ joma_appointments : offering_id
    joma_capacity_holds o|--o{ joma_appointments : hold_id
    joma_appointments o|--o{ joma_appointments : rebooked_from_appointment_id
    joma_command_receipts ||--o| joma_appointments : command_id
    joma_appointments o|--o| joma_appointment_requests : fulfilled_appointment_id
    joma_appointments ||--o{ joma_appointment_allocations : appointment_id
    joma_schedule_resources ||--o{ joma_appointment_allocations : resource_id
    joma_appointments ||--o{ joma_appointment_changes : appointment_id
    joma_command_receipts ||--o| joma_appointment_changes : command_id
    joma_persons ||--o{ joma_appointment_changes : actor_person_id
    joma_appointments ||--o{ joma_attendance_observations : appointment_id
    joma_persons ||--o{ joma_attendance_observations : observed_person_id
    joma_persons ||--o{ joma_attendance_observations : recorded_by_person_id
    joma_clinical_cases ||--o{ joma_clinical_sessions : case_id
    joma_appointments o|--o{ joma_clinical_sessions : appointment_id
    joma_command_receipts ||--o| joma_clinical_sessions : command_id
```

## فرم و مخاطب پاسخ

```mermaid
erDiagram
    joma_admissions {
        BINARY16 id PK
    }
    joma_appointments {
        BINARY16 id PK
    }
    joma_clinical_cases {
        BINARY16 id PK
    }
    joma_command_receipts {
        BINARY16 id PK
    }
    joma_consent_evidence {
        BINARY16 id PK
        BINARY16 submission_revision_id FK
        BINARY16 subject_person_id FK
        BINARY16 consenting_person_id FK
        BINARY16 representation_id FK
        BINARY16 statement_hash
        DATETIME accepted_at
        DATETIME created_at
    }
    joma_form_approvals {
        BINARY16 id PK
        BINARY16 form_version_id FK
        BINARY16 approver_person_id FK
        BINARY16 role_assignment_id FK
        BINARY16 scope_id FK
        BINARY16 definition_hash
        VARCHAR decision
        DATETIME reviewed_at
        DATETIME created_at
    }
    joma_form_instances {
        BINARY16 id PK
        BINARY16 form_version_id FK
        BINARY16 scope_id FK
        BINARY16 admission_id FK
        BINARY16 case_id FK
        BINARY16 appointment_id FK
        BINARY16 designated_respondent_id FK
        VARCHAR status
        DATETIME created_at
    }
    joma_form_publication_audiences {
        BINARY16 id PK
        BINARY16 publication_id FK
        BINARY16 recipient_person_id FK
        BINARY16 access_subject_person_id FK
        BINARY16 scope_id FK
        VARCHAR basis_kind
        BINARY16 representation_id FK
        DATETIME revoked_at
        DATETIME created_at
    }
    joma_form_publications {
        BINARY16 id PK
        BINARY16 submission_revision_id FK
        BINARY16 instance_id FK
        BINARY16 case_id FK
        BINARY16 published_by_person_id FK
        DATETIME published_at
        DATETIME revoked_at
        BINARY16 command_id FK, UK
        DATETIME created_at
    }
    joma_form_subjects {
        BINARY16 id PK
        BINARY16 instance_id FK
        BINARY16 subject_person_id FK
        DATETIME created_at
    }
    joma_form_submission_revisions {
        BINARY16 id PK
        BINARY16 instance_id FK
        INT revision_no
        BINARY16 respondent_person_id FK
        BINARY16 recorded_by_person_id FK
        BINARY16 representation_id FK
        JSON answers_json
        VARCHAR state
        DATETIME submitted_at
        BINARY16 command_id FK, UK
        DATETIME created_at
    }
    joma_form_templates {
        BINARY16 id PK
        BINARY16 scope_id FK
        VARCHAR label
        VARCHAR form_kind
        DATETIME created_at
    }
    joma_form_versions {
        BINARY16 id PK
        BINARY16 template_id FK
        INT version_no
        JSON schema_json
        VARCHAR status
        BINARY16 author_person_id FK
        DATETIME published_at
        DATETIME created_at
    }
    joma_persons {
        BINARY16 id PK
    }
    joma_representations {
        BINARY16 id PK
    }
    joma_role_assignments {
        BINARY16 id PK
    }
    joma_service_form_requirements {
        BINARY16 id PK
        BINARY16 policy_version_id FK
        BINARY16 form_version_id FK
        VARCHAR stage_code
        VARCHAR respondent_role_code
        BOOLEAN is_required
        DATETIME created_at
    }
    joma_service_policy_versions {
        BINARY16 id PK
    }
    joma_service_prerequisites {
        BINARY16 id PK
        BINARY16 policy_version_id FK
        VARCHAR action_code
        VARCHAR prerequisite_code
        JSON parameters_json
        DATETIME created_at
    }
    joma_work_scopes {
        BINARY16 id PK
    }
    joma_work_scopes ||--o{ joma_form_templates : scope_id
    joma_form_templates ||--o{ joma_form_versions : template_id
    joma_persons ||--o{ joma_form_versions : author_person_id
    joma_form_versions ||--o{ joma_form_approvals : form_version_id
    joma_role_assignments ||--o{ joma_form_approvals : role_assignment_id
    joma_service_policy_versions ||--o{ joma_service_form_requirements : policy_version_id
    joma_form_versions ||--o{ joma_service_form_requirements : form_version_id
    joma_service_policy_versions ||--o{ joma_service_prerequisites : policy_version_id
    joma_form_versions ||--o{ joma_form_instances : form_version_id
    joma_work_scopes ||--o{ joma_form_instances : scope_id
    joma_admissions o|--o{ joma_form_instances : admission_id
    joma_clinical_cases o|--o{ joma_form_instances : case_id
    joma_appointments o|--o{ joma_form_instances : appointment_id
    joma_persons o|--o{ joma_form_instances : designated_respondent_id
    joma_form_instances ||--o{ joma_form_subjects : instance_id
    joma_persons ||--o{ joma_form_subjects : subject_person_id
    joma_form_instances ||--o{ joma_form_submission_revisions : instance_id
    joma_persons ||--o{ joma_form_submission_revisions : respondent_person_id
    joma_persons ||--o{ joma_form_submission_revisions : recorded_by_person_id
    joma_representations o|--o{ joma_form_submission_revisions : representation_id
    joma_command_receipts ||--o| joma_form_submission_revisions : command_id
    joma_form_submission_revisions ||--o{ joma_consent_evidence : submission_revision_id
    joma_persons ||--o{ joma_consent_evidence : subject_person_id
    joma_persons ||--o{ joma_consent_evidence : consenting_person_id
    joma_representations o|--o{ joma_consent_evidence : representation_id
    joma_form_submission_revisions ||--o{ joma_form_publications : submission_revision_id
    joma_form_instances ||--o{ joma_form_publications : instance_id
    joma_persons ||--o{ joma_form_publications : published_by_person_id
    joma_command_receipts ||--o| joma_form_publications : command_id
    joma_form_publications ||--o{ joma_form_publication_audiences : publication_id
    joma_persons ||--o{ joma_form_publication_audiences : recipient_person_id
    joma_persons ||--o{ joma_form_publication_audiences : access_subject_person_id
    joma_work_scopes ||--o{ joma_form_publication_audiences : scope_id
    joma_representations o|--o{ joma_form_publication_audiences : representation_id
```

## ارزیابی و گزارش

```mermaid
erDiagram
    joma_assessment_assignments {
        BINARY16 id PK
        BINARY16 assessment_id FK
        BINARY16 assessor_person_id FK
        BINARY16 authorized_by_person_id FK
        BINARY16 scope_id FK
        DATETIME valid_from
        DATETIME valid_until
        DATETIME revoked_at
        DATETIME created_at
    }
    joma_assessment_reports {
        BINARY16 id PK
        BINARY16 assessment_id FK
        BINARY16 case_id FK
        DATETIME created_at
    }
    joma_assessment_respondents {
        BINARY16 id PK
        BINARY16 assessment_id FK
        BINARY16 respondent_person_id FK
        BINARY16 representation_id FK
        DATETIME created_at
    }
    joma_assessments {
        BINARY16 id PK
        BINARY16 case_id FK
        BINARY16 subject_person_id FK
        VARCHAR instrument_reference
        VARCHAR status_code
        DATETIME created_at
    }
    joma_clinical_cases {
        BINARY16 id PK
    }
    joma_command_receipts {
        BINARY16 id PK
    }
    joma_persons {
        BINARY16 id PK
    }
    joma_protected_files {
        BINARY16 id PK
        VARCHAR storage_key UK
        VARCHAR original_name
        VARCHAR media_type
        BIGINT byte_length
        BINARY16 sha256
        BINARY16 uploaded_by_person_id FK
        VARCHAR state
        DATETIME created_at
    }
    joma_report_publication_audiences {
        BINARY16 id PK
        BINARY16 publication_id FK
        BINARY16 recipient_person_id FK
        BINARY16 access_subject_person_id FK
        BINARY16 scope_id FK
        VARCHAR basis_kind
        BINARY16 representation_id FK
        DATETIME revoked_at
        DATETIME created_at
    }
    joma_report_publications {
        BINARY16 id PK
        BINARY16 report_version_id FK
        BINARY16 case_id FK
        BINARY16 published_by_person_id FK
        VARCHAR channel
        DATETIME published_at
        DATETIME revoked_at
        BINARY16 command_id FK, UK
        DATETIME created_at
    }
    joma_report_versions {
        BINARY16 id PK
        BINARY16 report_id FK
        BINARY16 case_id FK
        INT version_no
        BINARY16 author_person_id FK
        BINARY16 uploaded_by_person_id FK
        MEDIUMTEXT report_text
        BINARY16 protected_file_id FK
        VARCHAR status
        DATETIME created_at
    }
    joma_representations {
        BINARY16 id PK
    }
    joma_work_scopes {
        BINARY16 id PK
    }
    joma_clinical_cases ||--o{ joma_assessments : case_id
    joma_persons ||--o{ joma_assessments : subject_person_id
    joma_assessments ||--o{ joma_assessment_respondents : assessment_id
    joma_persons ||--o{ joma_assessment_respondents : respondent_person_id
    joma_representations o|--o{ joma_assessment_respondents : representation_id
    joma_assessments ||--o{ joma_assessment_assignments : assessment_id
    joma_persons ||--o{ joma_assessment_assignments : assessor_person_id
    joma_persons ||--o{ joma_assessment_assignments : authorized_by_person_id
    joma_work_scopes ||--o{ joma_assessment_assignments : scope_id
    joma_persons ||--o{ joma_protected_files : uploaded_by_person_id
    joma_assessments ||--o{ joma_assessment_reports : assessment_id
    joma_assessment_reports ||--o{ joma_report_versions : report_id
    joma_persons ||--o{ joma_report_versions : author_person_id
    joma_persons ||--o{ joma_report_versions : uploaded_by_person_id
    joma_protected_files o|--o{ joma_report_versions : protected_file_id
    joma_report_versions ||--o{ joma_report_publications : report_version_id
    joma_clinical_cases ||--o{ joma_report_publications : case_id
    joma_command_receipts ||--o| joma_report_publications : command_id
    joma_report_publications ||--o{ joma_report_publication_audiences : publication_id
    joma_persons ||--o{ joma_report_publication_audiences : recipient_person_id
    joma_persons ||--o{ joma_report_publication_audiences : access_subject_person_id
    joma_work_scopes ||--o{ joma_report_publication_audiences : scope_id
    joma_representations o|--o{ joma_report_publication_audiences : representation_id
```

## مرز خصوصی و پیام

```mermaid
erDiagram
    joma_clinical_cases {
        BINARY16 id PK
    }
    joma_command_receipts {
        BINARY16 id PK
    }
    joma_persons {
        BINARY16 id PK
    }
    joma_practitioner_messages {
        BINARY16 id PK
        BINARY16 case_id FK
        BINARY16 sender_person_id FK
        BINARY16 recipient_person_id FK
        MEDIUMTEXT message_body
        DATETIME sent_at
        BINARY16 command_id FK, UK
        DATETIME created_at
    }
    joma_private_note_references {
        BINARY16 id PK
        BINARY16 case_id FK
        BINARY16 author_person_id FK
        VARCHAR storage_mode
        BINARY16 opaque_author_reference
        DATETIME created_at
    }
    joma_clinical_cases ||--o{ joma_private_note_references : case_id
    joma_persons ||--o{ joma_private_note_references : author_person_id
    joma_clinical_cases o|--o{ joma_practitioner_messages : case_id
    joma_persons ||--o{ joma_practitioner_messages : sender_person_id
    joma_persons ||--o{ joma_practitioner_messages : recipient_person_id
    joma_command_receipts ||--o| joma_practitioner_messages : command_id
```

## دریافت و استحقاق

```mermaid
erDiagram
    joma_command_receipts {
        BINARY16 id PK
    }
    joma_destination_accounts {
        BINARY16 id PK
        BINARY16 financial_space_id FK
        VARCHAR bank_reference
        VARCHAR label
        BOOLEAN is_active
        DATETIME created_at
    }
    joma_financial_spaces {
        BINARY16 id PK
        BINARY16 scope_id FK
        VARCHAR label
        DATETIME created_at
    }
    joma_payment_methods {
        INT id PK
        VARCHAR code UK
        VARCHAR label
        BOOLEAN is_active
        BOOLEAN is_bank_method
        DATETIME created_at
    }
    joma_payment_receipts {
        BINARY16 id PK
        BINARY16 financial_space_id FK
        BINARY16 payer_person_id FK
        INT method_id FK
        BOOLEAN method_is_bank FK
        BINARY16 destination_account_id FK
        DECIMAL amount
        CHAR currency_code
        DATETIME received_at
        BINARY16 recorded_by_person_id FK
        VARCHAR provider_code
        VARCHAR provider_transaction_reference
        BINARY16 command_id FK, UK
        DATETIME created_at
    }
    joma_persons {
        BINARY16 id PK
    }
    joma_product_definitions {
        INT id PK
        VARCHAR code UK
        VARCHAR label
        BOOLEAN is_active
        DATETIME created_at
    }
    joma_product_entitlements {
        BINARY16 id PK
        INT product_id FK
        BINARY16 beneficiary_person_id FK
        VARCHAR source_system_code
        VARCHAR source_reference
        DATETIME valid_from
        DATETIME valid_until
        DATETIME revoked_at
        DATETIME created_at
    }
    joma_work_scopes {
        BINARY16 id PK
    }
    joma_work_scopes ||--o{ joma_financial_spaces : scope_id
    joma_financial_spaces ||--o{ joma_destination_accounts : financial_space_id
    joma_financial_spaces ||--o{ joma_payment_receipts : financial_space_id
    joma_persons ||--o{ joma_payment_receipts : payer_person_id
    joma_destination_accounts o|--o{ joma_payment_receipts : destination_account_id
    joma_persons ||--o{ joma_payment_receipts : recorded_by_person_id
    joma_command_receipts ||--o| joma_payment_receipts : command_id
    joma_payment_methods ||--o{ joma_payment_receipts : method_id
    joma_product_definitions ||--o{ joma_product_entitlements : product_id
    joma_persons ||--o{ joma_product_entitlements : beneficiary_person_id
```

## پیکربندی و ممیزی

```mermaid
erDiagram
    joma_audit_entries {
        BINARY16 id PK
        BINARY16 command_id FK
        BINARY16 actor_person_id FK
        VARCHAR system_actor_code
        BINARY16 scope_id FK
        VARCHAR action_code
        VARCHAR resource_kind
        VARCHAR resource_reference
        VARCHAR decision
        VARCHAR reason_code
        JSON redacted_metadata_json
        DATETIME created_at
    }
    joma_command_receipts {
        BINARY16 id PK
        BINARY16 scope_id FK
        BINARY16 actor_person_id FK
        VARCHAR system_actor_code
        VARCHAR command_name
        BINARY16 idempotency_key
        BINARY16 payload_hash
        VARCHAR status
        JSON result_reference_json
        DATETIME completed_at
        DATETIME created_at
    }
    joma_config_definitions {
        INT id PK
        VARCHAR key_code UK
        VARCHAR value_type
        JSON validation_json
        DATETIME created_at
    }
    joma_config_versions {
        BINARY16 id PK
        INT definition_id FK
        BINARY16 scope_id FK
        INT version_no
        JSON value_json
        DATETIME effective_at
        BINARY16 changed_by_person_id FK
        BINARY16 command_id FK, UK
        DATETIME created_at
    }
    joma_notification_attempts {
        BINARY16 id PK
        BINARY16 intent_id FK
        INT attempt_no
        VARCHAR provider_reference
        VARCHAR result_code
        DATETIME attempted_at
        DATETIME created_at
    }
    joma_notification_intents {
        BINARY16 id PK
        BINARY16 command_id FK
        BINARY16 recipient_person_id FK
        VARCHAR template_code
        VARCHAR status
        DATETIME scheduled_at
        JSON redacted_parameters_json
        DATETIME created_at
    }
    joma_persons {
        BINARY16 id PK
    }
    joma_work_scopes {
        BINARY16 id PK
    }
    joma_work_scopes ||--o{ joma_command_receipts : scope_id
    joma_persons o|--o{ joma_command_receipts : actor_person_id
    joma_config_definitions ||--o{ joma_config_versions : definition_id
    joma_work_scopes ||--o{ joma_config_versions : scope_id
    joma_persons ||--o{ joma_config_versions : changed_by_person_id
    joma_command_receipts ||--o| joma_config_versions : command_id
    joma_command_receipts o|--o{ joma_audit_entries : command_id
    joma_persons o|--o{ joma_audit_entries : actor_person_id
    joma_work_scopes o|--o{ joma_audit_entries : scope_id
    joma_command_receipts ||--o{ joma_notification_intents : command_id
    joma_persons ||--o{ joma_notification_intents : recipient_person_id
    joma_notification_intents ||--o{ joma_notification_attempts : intent_id
```
