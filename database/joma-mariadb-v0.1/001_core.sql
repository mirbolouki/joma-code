-- JOMA Physical Schema v0.1 — REVIEW CANDIDATE; NOT A LIVE UPGRADE.
-- Target: MariaDB 10.11.x, InnoDB, utf8mb4_unicode_ci. Runtime import must be verified.
-- Reviewed for MariaDB 10.11: JSON is LONGTEXT utf8mb4_bin + automatic JSON_VALID CHECK. Not MySQL binary JSON.
-- All BINARY(16) entity IDs are application-generated cryptographically random UUIDv4.
-- SQL does not enforce authorization, immutable history, minimum-child counts or interval exclusion.
-- No DROP, TRUNCATE, seed permissions, cascade deletes, or FOREIGN_KEY_CHECKS disabling.
-- DDL auto-commits in MySQL; this file is NOT a rollbackable transaction or idempotent migration.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION time_zone = '+00:00';
SET SESSION foreign_key_checks = 1;
SET SESSION check_constraint_checks = ON;

CREATE TABLE `joma_role_definitions` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  label VARCHAR(191) NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_definitions_1` (`code`),
  CONSTRAINT `ck_role_definitions_1` CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_service_definitions` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  label VARCHAR(191) NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_definitions_1` (`code`),
  CONSTRAINT `ck_service_definitions_1` CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_payment_methods` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  label VARCHAR(191) NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  is_bank_method BOOLEAN NOT NULL DEFAULT FALSE,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_methods_1` (`code`),
  UNIQUE KEY `uq_payment_methods_2` (`id`, `is_bank_method`),
  CONSTRAINT `ck_payment_methods_1` CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_product_definitions` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  label VARCHAR(191) NOT NULL,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_definitions_1` (`code`),
  CONSTRAINT `ck_product_definitions_1` CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- No required phone, account, or national identifier. No automatic identity merging.
CREATE TABLE `joma_persons` (
  id BINARY(16) NOT NULL,
  given_name VARCHAR(120) NULL,
  family_name VARCHAR(120) NULL,
  birth_date DATE NULL,
  sex_code VARCHAR(32) NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ACTIVE',
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  CONSTRAINT `ck_persons_1` CHECK (status IN ('ACTIVE','INACTIVE')),
  CONSTRAINT `ck_persons_2` CHECK (given_name IS NOT NULL OR family_name IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- At most one ACTIVE or LOCKED account per Person; inactive history is retained. Phone is not the Person key.
CREATE TABLE `joma_accounts` (
  id BINARY(16) NOT NULL,
  person_id BINARY(16) NOT NULL,
  login_name VARCHAR(191) NOT NULL,
  password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  active_person_id BINARY(16) GENERATED ALWAYS AS (CASE WHEN status IN ('ACTIVE','LOCKED') THEN person_id ELSE NULL END) STORED,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_accounts_1` (`login_name`),
  UNIQUE KEY `uq_accounts_2` (`active_person_id`),
  UNIQUE KEY `uq_accounts_3` (`id`, `person_id`),
  KEY `ix_accounts_1` (`person_id`),
  CONSTRAINT `ck_accounts_1` CHECK (status IN ('ACTIVE','INACTIVE','LOCKED'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_contact_points` (
  id BINARY(16) NOT NULL,
  kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  normalized_value VARCHAR(255) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_contact_points_1` (`kind`, `normalized_value`),
  CONSTRAINT `ck_contact_points_1` CHECK (kind IN ('PHONE','EMAIL','OTHER'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_person_contacts` (
  id BINARY(16) NOT NULL,
  person_id BINARY(16) NOT NULL,
  contact_id BINARY(16) NOT NULL,
  relationship_label VARCHAR(80) NULL,
  verified_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_person_contacts_1` (`person_id`, `contact_id`),
  KEY `ix_person_contacts_1` (`contact_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Cycles and valid parent kind require command validation; no implicit scope inheritance.
CREATE TABLE `joma_work_scopes` (
  id BINARY(16) NOT NULL,
  kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  parent_scope_id BINARY(16) NULL,
  independent_practitioner_id BINARY(16) NULL,
  label VARCHAR(191) NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `ix_work_scopes_1` (`parent_scope_id`),
  KEY `ix_work_scopes_2` (`independent_practitioner_id`),
  CONSTRAINT `ck_work_scopes_1` CHECK (kind IN ('CENTER','BRANCH','INDEPENDENT')),
  CONSTRAINT `ck_work_scopes_2` CHECK (status IN ('ACTIVE','INACTIVE')),
  CONSTRAINT `ck_work_scopes_3` CHECK (parent_scope_id IS NULL OR parent_scope_id <> id),
  CONSTRAINT `ck_work_scopes_4` CHECK ((kind = 'INDEPENDENT' AND independent_practitioner_id IS NOT NULL AND parent_scope_id IS NULL) OR (kind <> 'INDEPENDENT' AND independent_practitioner_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_memberships` (
  id BINARY(16) NOT NULL,
  person_id BINARY(16) NOT NULL,
  scope_id BINARY(16) NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  valid_from DATETIME(6) NOT NULL,
  valid_until DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_memberships_1` (`person_id`, `scope_id`),
  UNIQUE KEY `uq_memberships_2` (`id`, `person_id`, `scope_id`),
  KEY `ix_memberships_1` (`scope_id`),
  CONSTRAINT `ck_memberships_1` CHECK (status IN ('ACTIVE','INACTIVE','ENDED')),
  CONSTRAINT `ck_memberships_2` CHECK (valid_until IS NULL OR valid_until > valid_from)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_role_assignments` (
  id BINARY(16) NOT NULL,
  account_id BINARY(16) NOT NULL,
  person_id BINARY(16) NOT NULL,
  membership_id BINARY(16) NOT NULL,
  scope_id BINARY(16) NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  valid_from DATETIME(6) NOT NULL,
  valid_until DATETIME(6) NULL,
  revoked_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_assignments_1` (`id`, `person_id`, `scope_id`),
  KEY `ix_role_assignments_1` (`account_id`, `person_id`),
  KEY `ix_role_assignments_2` (`membership_id`, `person_id`, `scope_id`),
  KEY `ix_role_assignments_3` (`role_id`),
  CONSTRAINT `ck_role_assignments_1` CHECK (valid_until IS NULL OR valid_until > valid_from)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_professional_profiles` (
  id BINARY(16) NOT NULL,
  person_id BINARY(16) NOT NULL,
  professional_type VARCHAR(100) NOT NULL,
  specialties_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `ix_professional_profiles_1` (`person_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_professional_credentials` (
  id BINARY(16) NOT NULL,
  profile_id BINARY(16) NOT NULL,
  credential_type VARCHAR(100) NOT NULL,
  issuer VARCHAR(191) NULL,
  credential_reference VARCHAR(191) NULL,
  valid_until DATE NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `ix_professional_credentials_1` (`profile_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Never return a previous result without rechecking current authorization. No clinical payload in result_reference_json.
CREATE TABLE `joma_command_receipts` (
  id BINARY(16) NOT NULL,
  scope_id BINARY(16) NOT NULL,
  actor_person_id BINARY(16) NULL,
  system_actor_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  command_name VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  idempotency_key BINARY(32) NOT NULL,
  payload_hash BINARY(32) NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  result_reference_json JSON NULL,
  completed_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_command_receipts_1` (`scope_id`, `idempotency_key`),
  KEY `ix_command_receipts_1` (`actor_person_id`),
  CONSTRAINT `ck_command_receipts_1` CHECK (status IN ('PROCESSING','SUCCEEDED','REJECTED')),
  CONSTRAINT `ck_command_receipts_2` CHECK ((actor_person_id IS NOT NULL AND system_actor_code IS NULL) OR (actor_person_id IS NULL AND system_actor_code IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Published contents are immutable by command contract, not by this CHECK.
CREATE TABLE `joma_service_policy_versions` (
  id BINARY(16) NOT NULL,
  service_id INT UNSIGNED NOT NULL,
  version_no INT UNSIGNED NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  policy_json JSON NOT NULL,
  author_person_id BINARY(16) NOT NULL,
  approved_by_person_id BINARY(16) NULL,
  effective_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_policy_versions_1` (`service_id`, `version_no`),
  UNIQUE KEY `uq_service_policy_versions_2` (`id`, `service_id`),
  KEY `ix_service_policy_versions_1` (`author_person_id`),
  KEY `ix_service_policy_versions_2` (`approved_by_person_id`),
  CONSTRAINT `ck_service_policy_versions_1` CHECK (version_no > 0),
  CONSTRAINT `ck_service_policy_versions_2` CHECK (status IN ('DRAFT','PUBLISHED','RETIRED')),
  CONSTRAINT `ck_service_policy_versions_3` CHECK (status = 'DRAFT' OR (approved_by_person_id IS NOT NULL AND effective_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_service_offerings` (
  id BINARY(16) NOT NULL,
  scope_id BINARY(16) NOT NULL,
  service_id INT UNSIGNED NOT NULL,
  policy_version_id BINARY(16) NOT NULL,
  duration_minutes SMALLINT UNSIGNED NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_offerings_1` (`id`, `scope_id`),
  KEY `ix_service_offerings_1` (`scope_id`),
  KEY `ix_service_offerings_2` (`policy_version_id`, `service_id`),
  CONSTRAINT `ck_service_offerings_1` CHECK (duration_minutes > 0),
  CONSTRAINT `ck_service_offerings_2` CHECK (status IN ('ACTIVE','INACTIVE'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_service_providers` (
  id BINARY(16) NOT NULL,
  offering_id BINARY(16) NOT NULL,
  scope_id BINARY(16) NOT NULL,
  membership_id BINARY(16) NOT NULL,
  person_id BINARY(16) NOT NULL,
  valid_from DATETIME(6) NOT NULL,
  valid_until DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `ix_service_providers_1` (`offering_id`, `scope_id`),
  KEY `ix_service_providers_2` (`membership_id`, `person_id`, `scope_id`),
  CONSTRAINT `ck_service_providers_1` CHECK (valid_until IS NULL OR valid_until > valid_from)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_admissions` (
  id BINARY(16) NOT NULL,
  primary_subject_id BINARY(16) NOT NULL,
  scope_id BINARY(16) NOT NULL,
  offering_id BINARY(16) NOT NULL,
  recorded_by_person_id BINARY(16) NOT NULL,
  case_id BINARY(16) NULL,
  status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `ix_admissions_1` (`scope_id`, `status`, `created_at`),
  KEY `ix_admissions_2` (`primary_subject_id`),
  KEY `ix_admissions_3` (`recorded_by_person_id`),
  KEY `ix_admissions_4` (`offering_id`, `scope_id`),
  KEY `ix_admissions_5` (`case_id`),
  CONSTRAINT `ck_admissions_1` CHECK (status IN ('DRAFT','SUBMITTED','NEEDS_INFO','READY_FOR_ASSIGNMENT','AWAITING_THERAPIST','LINKED_TO_CASE','WITHDRAWN','DECLINED')),
  CONSTRAINT `ck_admissions_2` CHECK (status <> 'LINKED_TO_CASE' OR case_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_admission_participants` (
  id BINARY(16) NOT NULL,
  admission_id BINARY(16) NOT NULL,
  person_id BINARY(16) NOT NULL,
  participation_role VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admission_participants_1` (`admission_id`, `person_id`, `participation_role`),
  KEY `ix_admission_participants_1` (`person_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_therapist_assignments` (
  id BINARY(16) NOT NULL,
  admission_id BINARY(16) NOT NULL,
  therapist_person_id BINARY(16) NOT NULL,
  assigned_by_person_id BINARY(16) NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  open_admission_id BINARY(16) GENERATED ALWAYS AS (CASE WHEN status = 'ASSIGNED' THEN admission_id ELSE NULL END) STORED,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_therapist_assignments_1` (`open_admission_id`),
  UNIQUE KEY `uq_therapist_assignments_2` (`id`, `admission_id`, `therapist_person_id`),
  KEY `ix_therapist_assignments_1` (`admission_id`),
  KEY `ix_therapist_assignments_2` (`therapist_person_id`),
  KEY `ix_therapist_assignments_3` (`assigned_by_person_id`),
  CONSTRAINT `ck_therapist_assignments_1` CHECK (status IN ('ASSIGNED','ACCEPTED','DECLINED','CANCELLED'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_responsibility_acceptances` (
  id BINARY(16) NOT NULL,
  admission_id BINARY(16) NOT NULL,
  assignment_id BINARY(16) NULL,
  therapist_person_id BINARY(16) NOT NULL,
  actor_person_id BINARY(16) NOT NULL,
  command_id BINARY(16) NOT NULL,
  accepted_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_responsibility_acceptances_1` (`assignment_id`),
  UNIQUE KEY `uq_responsibility_acceptances_2` (`command_id`),
  UNIQUE KEY `uq_responsibility_acceptances_3` (`id`, `therapist_person_id`),
  KEY `ix_responsibility_acceptances_1` (`admission_id`),
  KEY `ix_responsibility_acceptances_2` (`assignment_id`, `admission_id`, `therapist_person_id`),
  KEY `ix_responsibility_acceptances_3` (`therapist_person_id`),
  CONSTRAINT `ck_responsibility_acceptances_1` CHECK (actor_person_id = therapist_person_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_therapeutic_relationships` (
  id BINARY(16) NOT NULL,
  acceptance_id BINARY(16) NOT NULL,
  therapist_person_id BINARY(16) NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  activated_at DATETIME(6) NOT NULL,
  ended_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_therapeutic_relationships_1` (`acceptance_id`),
  UNIQUE KEY `uq_therapeutic_relationships_2` (`id`, `therapist_person_id`),
  KEY `ix_therapeutic_relationships_1` (`acceptance_id`, `therapist_person_id`),
  CONSTRAINT `ck_therapeutic_relationships_1` CHECK ((status = 'ACTIVE' AND ended_at IS NULL) OR (status = 'ENDED' AND ended_at IS NOT NULL AND ended_at >= activated_at))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Relationship/Case status coherence and mandatory creation of BOTH are enforced by atomic commands; FK cannot guarantee the reverse 1:1.
CREATE TABLE `joma_clinical_cases` (
  id BINARY(16) NOT NULL,
  relationship_id BINARY(16) NOT NULL,
  responsible_therapist_id BINARY(16) NOT NULL,
  purpose_summary VARCHAR(500) NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  opened_at DATETIME(6) NOT NULL,
  closed_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_clinical_cases_1` (`relationship_id`),
  UNIQUE KEY `uq_clinical_cases_2` (`id`, `responsible_therapist_id`),
  KEY `ix_clinical_cases_1` (`relationship_id`, `responsible_therapist_id`),
  CONSTRAINT `ck_clinical_cases_1` CHECK ((status = 'ACTIVE' AND closed_at IS NULL) OR (status = 'CLOSED' AND closed_at IS NOT NULL AND closed_at >= opened_at))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_case_participants` (
  id BINARY(16) NOT NULL,
  case_id BINARY(16) NOT NULL,
  person_id BINARY(16) NOT NULL,
  clinical_role VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  valid_from DATETIME(6) NOT NULL,
  valid_until DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `ix_case_participants_1` (`case_id`),
  KEY `ix_case_participants_2` (`person_id`),
  CONSTRAINT `ck_case_participants_1` CHECK (valid_until IS NULL OR valid_until > valid_from)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Historical scope link is not a current clinical grant.
CREATE TABLE `joma_case_operational_contexts` (
  id BINARY(16) NOT NULL,
  case_id BINARY(16) NOT NULL,
  scope_id BINARY(16) NOT NULL,
  valid_from DATETIME(6) NOT NULL,
  valid_until DATETIME(6) NULL,
  basis_reference VARCHAR(191) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_case_operational_contexts_1` (`id`, `case_id`, `scope_id`),
  KEY `ix_case_operational_contexts_1` (`case_id`),
  KEY `ix_case_operational_contexts_2` (`scope_id`),
  CONSTRAINT `ck_case_operational_contexts_1` CHECK (valid_until IS NULL OR valid_until > valid_from)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- VERIFIED is administrative evidence, not universal legal authority. Allowed action codes are server allowlisted.
CREATE TABLE `joma_representations` (
  id BINARY(16) NOT NULL,
  representative_person_id BINARY(16) NOT NULL,
  subject_person_id BINARY(16) NOT NULL,
  scope_id BINARY(16) NOT NULL,
  status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  allowed_actions_json JSON NOT NULL,
  valid_from DATETIME(6) NULL,
  valid_until DATETIME(6) NULL,
  revoked_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_representations_1` (`id`, `representative_person_id`, `subject_person_id`, `scope_id`),
  KEY `ix_representations_1` (`representative_person_id`),
  KEY `ix_representations_2` (`subject_person_id`),
  KEY `ix_representations_3` (`scope_id`),
  CONSTRAINT `ck_representations_1` CHECK (representative_person_id <> subject_person_id),
  CONSTRAINT `ck_representations_2` CHECK (status IN ('PENDING_VERIFICATION','VERIFIED','REVOKED')),
  CONSTRAINT `ck_representations_3` CHECK (valid_until IS NULL OR valid_from IS NULL OR valid_until > valid_from),
  CONSTRAINT `ck_representations_4` CHECK ((status = 'REVOKED' AND revoked_at IS NOT NULL) OR (status <> 'REVOKED' AND revoked_at IS NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_administrative_delegations` (
  id BINARY(16) NOT NULL,
  grantor_role_assignment_id BINARY(16) NOT NULL,
  grantor_person_id BINARY(16) NOT NULL,
  grantee_membership_id BINARY(16) NOT NULL,
  grantee_person_id BINARY(16) NOT NULL,
  scope_id BINARY(16) NOT NULL,
  kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  target_admission_id BINARY(16) NULL,
  target_representation_id BINARY(16) NULL,
  allowed_actions_json JSON NOT NULL,
  valid_from DATETIME(6) NULL,
  valid_until DATETIME(6) NULL,
  revoked_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_administrative_delegations_1` (`id`, `grantee_person_id`, `scope_id`),
  KEY `ix_administrative_delegations_1` (`grantor_role_assignment_id`, `grantor_person_id`, `scope_id`),
  KEY `ix_administrative_delegations_2` (`grantee_membership_id`, `grantee_person_id`, `scope_id`),
  KEY `ix_administrative_delegations_3` (`target_admission_id`),
  KEY `ix_administrative_delegations_4` (`target_representation_id`),
  CONSTRAINT `ck_administrative_delegations_1` CHECK (kind IN ('STANDING','SPECIFIC')),
  CONSTRAINT `ck_administrative_delegations_2` CHECK ((kind = 'STANDING' AND target_admission_id IS NULL AND target_representation_id IS NULL) OR (kind = 'SPECIFIC' AND ((target_admission_id IS NOT NULL AND target_representation_id IS NULL) OR (target_admission_id IS NULL AND target_representation_id IS NOT NULL)))),
  CONSTRAINT `ck_administrative_delegations_3` CHECK (valid_until IS NULL OR valid_from IS NULL OR valid_until > valid_from)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_verification_authority_bases` (
  id BINARY(16) NOT NULL,
  actor_person_id BINARY(16) NOT NULL,
  scope_id BINARY(16) NOT NULL,
  direct_role_assignment_id BINARY(16) NULL,
  delegation_id BINARY(16) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_verification_authority_bases_1` (`id`, `actor_person_id`, `scope_id`),
  KEY `ix_verification_authority_bases_1` (`direct_role_assignment_id`, `actor_person_id`, `scope_id`),
  KEY `ix_verification_authority_bases_2` (`delegation_id`, `actor_person_id`, `scope_id`),
  CONSTRAINT `ck_verification_authority_bases_1` CHECK ((direct_role_assignment_id IS NOT NULL AND delegation_id IS NULL) OR (direct_role_assignment_id IS NULL AND delegation_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_representation_verifications` (
  id BINARY(16) NOT NULL,
  representation_id BINARY(16) NOT NULL,
  representative_person_id BINARY(16) NOT NULL,
  subject_person_id BINARY(16) NOT NULL,
  scope_id BINARY(16) NOT NULL,
  authority_basis_id BINARY(16) NOT NULL,
  actor_person_id BINARY(16) NOT NULL,
  evidence_type VARCHAR(64) NOT NULL,
  policy_reference VARCHAR(191) NOT NULL,
  result VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  checked_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `ix_representation_verifications_1` (`representation_id`, `representative_person_id`, `subject_person_id`, `scope_id`),
  KEY `ix_representation_verifications_2` (`authority_basis_id`, `actor_person_id`, `scope_id`),
  CONSTRAINT `ck_representation_verifications_1` CHECK (result IN ('VERIFIED','NEEDS_REVIEW','REJECTED'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_representation_events` (
  id BINARY(16) NOT NULL,
  representation_id BINARY(16) NOT NULL,
  command_id BINARY(16) NOT NULL,
  actor_person_id BINARY(16) NOT NULL,
  scope_id BINARY(16) NOT NULL,
  event_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  reason_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `ix_representation_events_1` (`representation_id`),
  KEY `ix_representation_events_2` (`command_id`),
  KEY `ix_representation_events_3` (`actor_person_id`),
  KEY `ix_representation_events_4` (`scope_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- No restore/expiry command is implied. Sensitive clinical reason belongs in an authorized protected source, never this audit reason code.
CREATE TABLE `joma_case_representation_restrictions` (
  id BINARY(16) NOT NULL,
  representation_id BINARY(16) NOT NULL,
  case_id BINARY(16) NOT NULL,
  clinician_person_id BINARY(16) NOT NULL,
  service_id INT UNSIGNED NULL,
  command_id BINARY(16) NOT NULL,
  suspended_at DATETIME(6) NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'SUSPENDED',
  reason_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_case_representation_restrictions_1` (`command_id`),
  KEY `ix_case_representation_restrictions_1` (`representation_id`),
  KEY `ix_case_representation_restrictions_2` (`case_id`, `clinician_person_id`),
  KEY `ix_case_representation_restrictions_3` (`service_id`),
  CONSTRAINT `ck_case_representation_restrictions_1` CHECK (status = 'SUSPENDED')
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_appointment_requests` (
  id BINARY(16) NOT NULL,
  subject_person_id BINARY(16) NOT NULL,
  admission_id BINARY(16) NULL,
  case_id BINARY(16) NULL,
  offering_id BINARY(16) NOT NULL,
  preferred_start_at DATETIME(6) NULL,
  status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  fulfilled_appointment_id BINARY(16) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_appointment_requests_1` (`fulfilled_appointment_id`),
  KEY `ix_appointment_requests_1` (`subject_person_id`),
  KEY `ix_appointment_requests_2` (`admission_id`),
  KEY `ix_appointment_requests_3` (`case_id`),
  KEY `ix_appointment_requests_4` (`offering_id`),
  CONSTRAINT `ck_appointment_requests_1` CHECK (admission_id IS NOT NULL OR case_id IS NOT NULL),
  CONSTRAINT `ck_appointment_requests_2` CHECK (status IN ('REQUESTED','CHANGE_PROPOSED','FULFILLED','DECLINED','WITHDRAWN')),
  CONSTRAINT `ck_appointment_requests_3` CHECK ((status = 'FULFILLED' AND fulfilled_appointment_id IS NOT NULL) OR (status <> 'FULFILLED' AND fulfilled_appointment_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- V1 exclusive capacity per resource. Global therapist resource prevents cross-location double booking; never grants cross-scope visibility. Resource row is the transaction mutex.
CREATE TABLE `joma_schedule_resources` (
  id BINARY(16) NOT NULL,
  scope_id BINARY(16) NULL,
  therapist_person_id BINARY(16) NULL,
  kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  label VARCHAR(191) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_schedule_resources_1` (`therapist_person_id`),
  KEY `ix_schedule_resources_1` (`scope_id`),
  CONSTRAINT `ck_schedule_resources_1` CHECK ((kind = 'THERAPIST' AND therapist_person_id IS NOT NULL) OR (kind IN ('ROOM','OTHER') AND therapist_person_id IS NULL AND scope_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 15 minute approved V1 upper bound; increasing it requires a schema/policy revision, not a hidden feature flag. Admission acceptance and policy/offering coherence require command validation.
CREATE TABLE `joma_capacity_holds` (
  id BINARY(16) NOT NULL,
  case_id BINARY(16) NOT NULL,
  offering_id BINARY(16) NOT NULL,
  policy_version_id BINARY(16) NOT NULL,
  request_id BINARY(16) NULL,
  actor_person_id BINARY(16) NOT NULL,
  starts_at DATETIME(6) NOT NULL,
  ends_at DATETIME(6) NOT NULL,
  held_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  command_id BINARY(16) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_capacity_holds_1` (`command_id`),
  UNIQUE KEY `uq_capacity_holds_2` (`id`, `case_id`),
  KEY `ix_capacity_holds_1` (`status`, `expires_at`),
  KEY `ix_capacity_holds_2` (`case_id`),
  KEY `ix_capacity_holds_3` (`offering_id`),
  KEY `ix_capacity_holds_4` (`policy_version_id`),
  KEY `ix_capacity_holds_5` (`request_id`),
  KEY `ix_capacity_holds_6` (`actor_person_id`),
  CONSTRAINT `ck_capacity_holds_1` CHECK (ends_at > starts_at),
  CONSTRAINT `ck_capacity_holds_2` CHECK (expires_at > held_at),
  CONSTRAINT `ck_capacity_holds_3` CHECK (expires_at <= held_at + INTERVAL 15 MINUTE),
  CONSTRAINT `ck_capacity_holds_4` CHECK (status IN ('HELD','CONSUMED','EXPIRED','RELEASED'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_hold_allocations` (
  id BINARY(16) NOT NULL,
  hold_id BINARY(16) NOT NULL,
  resource_id BINARY(16) NOT NULL,
  starts_at DATETIME(6) NOT NULL,
  ends_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hold_allocations_1` (`hold_id`, `resource_id`),
  KEY `ix_hold_allocations_1` (`resource_id`, `starts_at`, `ends_at`, `hold_id`),
  CONSTRAINT `ck_hold_allocations_1` CHECK (ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- cancellation_notice_minutes has no implicit default: capture approved policy when confirming. Overlap is NOT prevented by these indexes.
CREATE TABLE `joma_appointments` (
  id BINARY(16) NOT NULL,
  case_id BINARY(16) NOT NULL,
  therapist_person_id BINARY(16) NOT NULL,
  scope_id BINARY(16) NOT NULL,
  offering_id BINARY(16) NOT NULL,
  hold_id BINARY(16) NULL,
  rebooked_from_appointment_id BINARY(16) NULL,
  starts_at DATETIME(6) NOT NULL,
  ends_at DATETIME(6) NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  origin VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  cancellation_notice_minutes INT UNSIGNED NOT NULL,
  command_id BINARY(16) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_appointments_1` (`hold_id`),
  UNIQUE KEY `uq_appointments_2` (`command_id`),
  UNIQUE KEY `uq_appointments_3` (`id`, `case_id`, `therapist_person_id`),
  KEY `ix_appointments_1` (`scope_id`, `starts_at`, `status`),
  KEY `ix_appointments_2` (`therapist_person_id`, `starts_at`, `ends_at`),
  KEY `ix_appointments_3` (`case_id`, `therapist_person_id`),
  KEY `ix_appointments_4` (`offering_id`, `scope_id`),
  KEY `ix_appointments_5` (`hold_id`, `case_id`),
  KEY `ix_appointments_6` (`rebooked_from_appointment_id`),
  CONSTRAINT `ck_appointments_1` CHECK (ends_at > starts_at),
  CONSTRAINT `ck_appointments_2` CHECK (status IN ('CONFIRMED','CANCELLED','COMPLETED','NO_SHOW')),
  CONSTRAINT `ck_appointments_3` CHECK (origin IN ('CLIENT_REQUEST','STAFF_MANUAL','PRACTITIONER_DIRECT')),
  CONSTRAINT `ck_appointments_4` CHECK (rebooked_from_appointment_id IS NULL OR rebooked_from_appointment_id <> id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_appointment_allocations` (
  id BINARY(16) NOT NULL,
  appointment_id BINARY(16) NOT NULL,
  resource_id BINARY(16) NOT NULL,
  starts_at DATETIME(6) NOT NULL,
  ends_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_appointment_allocations_1` (`appointment_id`, `resource_id`),
  KEY `ix_appointment_allocations_1` (`resource_id`, `starts_at`, `ends_at`, `appointment_id`),
  CONSTRAINT `ck_appointment_allocations_1` CHECK (ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Operational history only; no session narrative/private notes in JSON.
CREATE TABLE `joma_appointment_changes` (
  id BINARY(16) NOT NULL,
  appointment_id BINARY(16) NOT NULL,
  command_id BINARY(16) NOT NULL,
  actor_person_id BINARY(16) NOT NULL,
  change_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  before_json JSON NOT NULL,
  after_json JSON NOT NULL,
  reason_code VARCHAR(64) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_appointment_changes_1` (`command_id`),
  KEY `ix_appointment_changes_1` (`appointment_id`),
  KEY `ix_appointment_changes_2` (`actor_person_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_attendance_observations` (
  id BINARY(16) NOT NULL,
  appointment_id BINARY(16) NOT NULL,
  observed_person_id BINARY(16) NOT NULL,
  recorded_by_person_id BINARY(16) NOT NULL,
  observed_at DATETIME(6) NOT NULL,
  observation_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `ix_attendance_observations_1` (`appointment_id`),
  KEY `ix_attendance_observations_2` (`observed_person_id`),
  KEY `ix_attendance_observations_3` (`recorded_by_person_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- UNIQUE nullable appointment_id enforces max one session per appointment and allows multiple unrelated unbooked sessions. Unbooked service does not waive consent, authority or financial prerequisite policies.
CREATE TABLE `joma_clinical_sessions` (
  id BINARY(16) NOT NULL,
  case_id BINARY(16) NOT NULL,
  clinician_person_id BINARY(16) NOT NULL,
  appointment_id BINARY(16) NULL,
  started_at DATETIME(6) NOT NULL,
  ended_at DATETIME(6) NULL,
  command_id BINARY(16) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_clinical_sessions_1` (`appointment_id`),
  UNIQUE KEY `uq_clinical_sessions_2` (`command_id`),
  KEY `ix_clinical_sessions_1` (`case_id`, `clinician_person_id`),
  KEY `ix_clinical_sessions_2` (`appointment_id`, `case_id`, `clinician_person_id`),
  CONSTRAINT `ck_clinical_sessions_1` CHECK (ended_at IS NULL OR ended_at >= started_at)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Raw psychometric protocols remain in the assessment subsystem, not a portal-publishable general form. Private notes never use this plaintext response store.
CREATE TABLE `joma_form_templates` (
  id BINARY(16) NOT NULL,
  scope_id BINARY(16) NOT NULL,
  label VARCHAR(191) NOT NULL,
  form_kind VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `ix_form_templates_1` (`scope_id`),
  CONSTRAINT `ck_form_templates_1` CHECK (form_kind IN ('ADMINISTRATIVE','CLINICAL','CONSENT'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_form_versions` (
  id BINARY(16) NOT NULL,
  template_id BINARY(16) NOT NULL,
  version_no INT UNSIGNED NOT NULL,
  schema_json JSON NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  author_person_id BINARY(16) NOT NULL,
  published_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_form_versions_1` (`template_id`, `version_no`),
  KEY `ix_form_versions_1` (`author_person_id`),
  CONSTRAINT `ck_form_versions_1` CHECK (version_no > 0),
  CONSTRAINT `ck_form_versions_2` CHECK (status IN ('DRAFT','PUBLISHED','RETIRED')),
  CONSTRAINT `ck_form_versions_3` CHECK (status = 'DRAFT' OR published_at IS NOT NULL),
  CONSTRAINT `ck_form_versions_4` CHECK (JSON_TYPE(schema_json) = 'OBJECT')
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_form_approvals` (
  id BINARY(16) NOT NULL,
  form_version_id BINARY(16) NOT NULL,
  approver_person_id BINARY(16) NOT NULL,
  role_assignment_id BINARY(16) NOT NULL,
  scope_id BINARY(16) NOT NULL,
  definition_hash BINARY(32) NOT NULL,
  decision VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  reviewed_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `ix_form_approvals_1` (`form_version_id`),
  KEY `ix_form_approvals_2` (`role_assignment_id`, `approver_person_id`, `scope_id`),
  CONSTRAINT `ck_form_approvals_1` CHECK (decision IN ('APPROVED','REJECTED'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_service_form_requirements` (
  id BINARY(16) NOT NULL,
  policy_version_id BINARY(16) NOT NULL,
  form_version_id BINARY(16) NOT NULL,
  stage_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  respondent_role_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  is_required BOOLEAN NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `ix_service_form_requirements_1` (`policy_version_id`),
  KEY `ix_service_form_requirements_2` (`form_version_id`),
  CONSTRAINT `ck_service_form_requirements_1` CHECK (is_required IN (0,1))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Allowlisted semantic rules, not executable scripts. Unknown policy never means ALLOW.
CREATE TABLE `joma_service_prerequisites` (
  id BINARY(16) NOT NULL,
  policy_version_id BINARY(16) NOT NULL,
  action_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  prerequisite_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  parameters_json JSON NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service_prerequisites_1` (`policy_version_id`, `action_code`, `prerequisite_code`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Validate consistency of all non-null contexts and FormSubjects at submit time. No anonymous/public access implied.
CREATE TABLE `joma_form_instances` (
  id BINARY(16) NOT NULL,
  form_version_id BINARY(16) NOT NULL,
  scope_id BINARY(16) NOT NULL,
  admission_id BINARY(16) NULL,
  case_id BINARY(16) NULL,
  appointment_id BINARY(16) NULL,
  designated_respondent_id BINARY(16) NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_form_instances_1` (`id`, `case_id`),
  KEY `ix_form_instances_1` (`form_version_id`),
  KEY `ix_form_instances_2` (`scope_id`),
  KEY `ix_form_instances_3` (`admission_id`),
  KEY `ix_form_instances_4` (`case_id`),
  KEY `ix_form_instances_5` (`appointment_id`),
  KEY `ix_form_instances_6` (`designated_respondent_id`),
  CONSTRAINT `ck_form_instances_1` CHECK (admission_id IS NOT NULL OR case_id IS NOT NULL OR appointment_id IS NOT NULL),
  CONSTRAINT `ck_form_instances_2` CHECK (status IN ('DRAFT','SUBMITTED','WITHDRAWN'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_form_subjects` (
  id BINARY(16) NOT NULL,
  instance_id BINARY(16) NOT NULL,
  subject_person_id BINARY(16) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_form_subjects_1` (`instance_id`, `subject_person_id`),
  KEY `ix_form_subjects_1` (`subject_person_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- DRAFT access only within the explicit recorder/responsible clinician rule, with current authority. Representation scope, raw-test exclusion, private-note exclusion and schema validation are server guards.
CREATE TABLE `joma_form_submission_revisions` (
  id BINARY(16) NOT NULL,
  instance_id BINARY(16) NOT NULL,
  revision_no INT UNSIGNED NOT NULL,
  respondent_person_id BINARY(16) NOT NULL,
  recorded_by_person_id BINARY(16) NOT NULL,
  representation_id BINARY(16) NULL,
  answers_json JSON NOT NULL,
  state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  submitted_at DATETIME(6) NULL,
  command_id BINARY(16) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_form_submission_revisions_1` (`instance_id`, `revision_no`),
  UNIQUE KEY `uq_form_submission_revisions_2` (`command_id`),
  UNIQUE KEY `uq_form_submission_revisions_3` (`id`, `instance_id`),
  KEY `ix_form_submission_revisions_1` (`respondent_person_id`),
  KEY `ix_form_submission_revisions_2` (`recorded_by_person_id`),
  KEY `ix_form_submission_revisions_3` (`representation_id`),
  CONSTRAINT `ck_form_submission_revisions_1` CHECK (revision_no > 0),
  CONSTRAINT `ck_form_submission_revisions_2` CHECK (state IN ('DRAFT','SUBMITTED','CORRECTION')),
  CONSTRAINT `ck_form_submission_revisions_3` CHECK ((state = 'DRAFT' AND submitted_at IS NULL) OR (state IN ('SUBMITTED','CORRECTION') AND submitted_at IS NOT NULL)),
  CONSTRAINT `ck_form_submission_revisions_4` CHECK (JSON_TYPE(answers_json) = 'OBJECT')
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_consent_evidence` (
  id BINARY(16) NOT NULL,
  submission_revision_id BINARY(16) NOT NULL,
  subject_person_id BINARY(16) NOT NULL,
  consenting_person_id BINARY(16) NOT NULL,
  representation_id BINARY(16) NULL,
  statement_hash BINARY(32) NOT NULL,
  accepted_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `ix_consent_evidence_1` (`submission_revision_id`),
  KEY `ix_consent_evidence_2` (`subject_person_id`),
  KEY `ix_consent_evidence_3` (`consenting_person_id`),
  KEY `ix_consent_evidence_4` (`representation_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Clinical/joint form sharing uses typed PublicationAudience pattern, never a polymorphic resource_id. Pre-case forms cannot be portal-published through this table until a valid case/authority exists. Exact publisher authority remains a review gate; no broad admin permission.
CREATE TABLE `joma_form_publications` (
  id BINARY(16) NOT NULL,
  submission_revision_id BINARY(16) NOT NULL,
  instance_id BINARY(16) NOT NULL,
  case_id BINARY(16) NOT NULL,
  published_by_person_id BINARY(16) NOT NULL,
  published_at DATETIME(6) NOT NULL,
  revoked_at DATETIME(6) NULL,
  command_id BINARY(16) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_form_publications_1` (`command_id`),
  KEY `ix_form_publications_1` (`submission_revision_id`, `instance_id`),
  KEY `ix_form_publications_2` (`instance_id`, `case_id`),
  KEY `ix_form_publications_3` (`published_by_person_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Instrument engine/raw-response physical schema waits for the approved final test package.
CREATE TABLE `joma_assessments` (
  id BINARY(16) NOT NULL,
  case_id BINARY(16) NOT NULL,
  subject_person_id BINARY(16) NOT NULL,
  instrument_reference VARCHAR(191) NULL,
  status_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assessments_1` (`id`, `case_id`),
  KEY `ix_assessments_1` (`case_id`),
  KEY `ix_assessments_2` (`subject_person_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_assessment_respondents` (
  id BINARY(16) NOT NULL,
  assessment_id BINARY(16) NOT NULL,
  respondent_person_id BINARY(16) NOT NULL,
  representation_id BINARY(16) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assessment_respondents_1` (`assessment_id`, `respondent_person_id`),
  KEY `ix_assessment_respondents_1` (`respondent_person_id`),
  KEY `ix_assessment_respondents_2` (`representation_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Null valid_until is not automatic indefinite permission: assignment termination policy is still a release gate.
CREATE TABLE `joma_assessment_assignments` (
  id BINARY(16) NOT NULL,
  assessment_id BINARY(16) NOT NULL,
  assessor_person_id BINARY(16) NOT NULL,
  authorized_by_person_id BINARY(16) NOT NULL,
  scope_id BINARY(16) NOT NULL,
  valid_from DATETIME(6) NOT NULL,
  valid_until DATETIME(6) NULL,
  revoked_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `ix_assessment_assignments_1` (`assessment_id`),
  KEY `ix_assessment_assignments_2` (`assessor_person_id`),
  KEY `ix_assessment_assignments_3` (`authorized_by_person_id`),
  KEY `ix_assessment_assignments_4` (`scope_id`),
  CONSTRAINT `ck_assessment_assignments_1` CHECK (valid_until IS NULL OR valid_until > valid_from)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Stored outside public web access; MIME/name do not prove safe content. No private-note plaintext here.
CREATE TABLE `joma_protected_files` (
  id BINARY(16) NOT NULL,
  storage_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  media_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  byte_length BIGINT UNSIGNED NOT NULL,
  sha256 BINARY(32) NOT NULL,
  uploaded_by_person_id BINARY(16) NOT NULL,
  state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_protected_files_1` (`storage_key`),
  KEY `ix_protected_files_1` (`uploaded_by_person_id`),
  CONSTRAINT `ck_protected_files_1` CHECK (state IN ('QUARANTINED','READY','REJECTED')),
  CONSTRAINT `ck_protected_files_2` CHECK (byte_length > 0)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_assessment_reports` (
  id BINARY(16) NOT NULL,
  assessment_id BINARY(16) NOT NULL,
  case_id BINARY(16) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_assessment_reports_1` (`id`, `case_id`),
  KEY `ix_assessment_reports_1` (`assessment_id`, `case_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Report text is shareable clinical content, NEVER a PrivateNote. Published versions are immutable by command contract.
CREATE TABLE `joma_report_versions` (
  id BINARY(16) NOT NULL,
  report_id BINARY(16) NOT NULL,
  case_id BINARY(16) NOT NULL,
  version_no INT UNSIGNED NOT NULL,
  author_person_id BINARY(16) NOT NULL,
  uploaded_by_person_id BINARY(16) NOT NULL,
  report_text MEDIUMTEXT NULL,
  protected_file_id BINARY(16) NULL,
  status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_report_versions_1` (`report_id`, `version_no`),
  UNIQUE KEY `uq_report_versions_2` (`id`, `case_id`),
  KEY `ix_report_versions_1` (`report_id`, `case_id`),
  KEY `ix_report_versions_2` (`author_person_id`),
  KEY `ix_report_versions_3` (`uploaded_by_person_id`),
  KEY `ix_report_versions_4` (`protected_file_id`),
  CONSTRAINT `ck_report_versions_1` CHECK (version_no > 0),
  CONSTRAINT `ck_report_versions_2` CHECK ((report_text IS NOT NULL AND protected_file_id IS NULL) OR (report_text IS NULL AND protected_file_id IS NOT NULL)),
  CONSTRAINT `ck_report_versions_3` CHECK (status IN ('DRAFT_CLINICIAN_REVIEW','REVIEWED','WITHDRAWN'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- IN_PERSON records authorized delivery; it never grants portal access.
CREATE TABLE `joma_report_publications` (
  id BINARY(16) NOT NULL,
  report_version_id BINARY(16) NOT NULL,
  case_id BINARY(16) NOT NULL,
  published_by_person_id BINARY(16) NOT NULL,
  channel VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  published_at DATETIME(6) NOT NULL,
  revoked_at DATETIME(6) NULL,
  command_id BINARY(16) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_report_publications_1` (`command_id`),
  KEY `ix_report_publications_1` (`report_version_id`, `case_id`),
  KEY `ix_report_publications_2` (`case_id`, `published_by_person_id`),
  CONSTRAINT `ck_report_publications_1` CHECK (channel IN ('PORTAL','IN_PERSON'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Explicit recipient snapshot, not dynamic case membership. Additional legal bases require a reviewed policy/schema change; unknown basis fails closed.
CREATE TABLE `joma_form_publication_audiences` (
  id BINARY(16) NOT NULL,
  publication_id BINARY(16) NOT NULL,
  recipient_person_id BINARY(16) NOT NULL,
  access_subject_person_id BINARY(16) NOT NULL,
  scope_id BINARY(16) NOT NULL,
  basis_kind VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  representation_id BINARY(16) NULL,
  revoked_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_form_publication_audiences_1` (`publication_id`, `recipient_person_id`),
  KEY `ix_form_publication_audiences_1` (`recipient_person_id`),
  KEY `ix_form_publication_audiences_2` (`access_subject_person_id`),
  KEY `ix_form_publication_audiences_3` (`scope_id`),
  KEY `ix_form_publication_audiences_4` (`representation_id`, `recipient_person_id`, `access_subject_person_id`, `scope_id`),
  CONSTRAINT `ck_form_publication_audiences_1` CHECK ((basis_kind = 'DIRECT_PERSON' AND representation_id IS NULL AND recipient_person_id = access_subject_person_id) OR (basis_kind = 'REPRESENTATIVE' AND representation_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Explicit recipient snapshot, not dynamic case membership. Additional legal bases require a reviewed policy/schema change; unknown basis fails closed.
CREATE TABLE `joma_report_publication_audiences` (
  id BINARY(16) NOT NULL,
  publication_id BINARY(16) NOT NULL,
  recipient_person_id BINARY(16) NOT NULL,
  access_subject_person_id BINARY(16) NOT NULL,
  scope_id BINARY(16) NOT NULL,
  basis_kind VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  representation_id BINARY(16) NULL,
  revoked_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_report_publication_audiences_1` (`publication_id`, `recipient_person_id`),
  KEY `ix_report_publication_audiences_1` (`recipient_person_id`),
  KEY `ix_report_publication_audiences_2` (`access_subject_person_id`),
  KEY `ix_report_publication_audiences_3` (`scope_id`),
  KEY `ix_report_publication_audiences_4` (`representation_id`, `recipient_person_id`, `access_subject_person_id`, `scope_id`),
  CONSTRAINT `ck_report_publication_audiences_1` CHECK ((basis_kind = 'DIRECT_PERSON' AND representation_id IS NULL AND recipient_person_id = access_subject_person_id) OR (basis_kind = 'REPRESENTATIVE' AND representation_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Optional minimum metadata only, itself author-restricted. No body, answers_json, key, public file FK, audience or patient grant. Payload/transfer crypto tables intentionally deferred.
CREATE TABLE `joma_private_note_references` (
  id BINARY(16) NOT NULL,
  case_id BINARY(16) NOT NULL,
  author_person_id BINARY(16) NOT NULL,
  storage_mode VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  opaque_author_reference BINARY(32) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_private_note_references_1` (`author_person_id`, `opaque_author_reference`),
  KEY `ix_private_note_references_1` (`case_id`, `author_person_id`),
  CONSTRAINT `ck_private_note_references_1` CHECK (storage_mode IN ('WINDOWS_LOCAL','SERVER_CIPHERTEXT'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- One-way protected messaging is distinct from client-encrypted private notes. No internal transfer/export implied.
CREATE TABLE `joma_practitioner_messages` (
  id BINARY(16) NOT NULL,
  case_id BINARY(16) NULL,
  sender_person_id BINARY(16) NOT NULL,
  recipient_person_id BINARY(16) NOT NULL,
  message_body MEDIUMTEXT NOT NULL,
  sent_at DATETIME(6) NOT NULL,
  command_id BINARY(16) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_practitioner_messages_1` (`command_id`),
  KEY `ix_practitioner_messages_1` (`case_id`),
  KEY `ix_practitioner_messages_2` (`sender_person_id`),
  KEY `ix_practitioner_messages_3` (`recipient_person_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_financial_spaces` (
  id BINARY(16) NOT NULL,
  scope_id BINARY(16) NOT NULL,
  label VARCHAR(191) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `ix_financial_spaces_1` (`scope_id`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Deactivate instead of silently changing the destination of historical receipts. Bank reference changes require audited command.
CREATE TABLE `joma_destination_accounts` (
  id BINARY(16) NOT NULL,
  financial_space_id BINARY(16) NOT NULL,
  bank_reference VARCHAR(191) NOT NULL,
  label VARCHAR(191) NOT NULL,
  is_active BOOLEAN NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_destination_accounts_1` (`id`, `financial_space_id`),
  KEY `ix_destination_accounts_1` (`financial_space_id`),
  CONSTRAINT `ck_destination_accounts_1` CHECK (is_active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- No assumed Rial/Toman conversion. Currency/unit must be explicitly agreed before using money. No refund, wallet, revenue or share logic is implied.
CREATE TABLE `joma_payment_receipts` (
  id BINARY(16) NOT NULL,
  financial_space_id BINARY(16) NOT NULL,
  payer_person_id BINARY(16) NOT NULL,
  method_id INT UNSIGNED NOT NULL,
  method_is_bank BOOLEAN NOT NULL,
  destination_account_id BINARY(16) NULL,
  amount DECIMAL(18,2) NOT NULL,
  currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  received_at DATETIME(6) NOT NULL,
  recorded_by_person_id BINARY(16) NOT NULL,
  provider_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  provider_transaction_reference VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
  command_id BINARY(16) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_receipts_1` (`command_id`),
  UNIQUE KEY `uq_payment_receipts_2` (`financial_space_id`, `provider_code`, `provider_transaction_reference`),
  KEY `ix_payment_receipts_1` (`payer_person_id`),
  KEY `ix_payment_receipts_2` (`destination_account_id`, `financial_space_id`),
  KEY `ix_payment_receipts_3` (`recorded_by_person_id`),
  KEY `ix_payment_receipts_4` (`method_id`, `method_is_bank`),
  CONSTRAINT `ck_payment_receipts_1` CHECK (amount > 0),
  CONSTRAINT `ck_payment_receipts_2` CHECK (method_is_bank IN (0,1)),
  CONSTRAINT `ck_payment_receipts_3` CHECK (method_is_bank = 0 OR destination_account_id IS NOT NULL),
  CONSTRAINT `ck_payment_receipts_4` CHECK ((provider_code IS NULL AND provider_transaction_reference IS NULL) OR (provider_code IS NOT NULL AND provider_transaction_reference IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Source verification contract pending final assessment integration. Never infer entitlement from any clinic payment.
CREATE TABLE `joma_product_entitlements` (
  id BINARY(16) NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  beneficiary_person_id BINARY(16) NOT NULL,
  source_system_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  source_reference VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  valid_from DATETIME(6) NOT NULL,
  valid_until DATETIME(6) NULL,
  revoked_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_entitlements_1` (`source_system_code`, `source_reference`, `product_id`, `beneficiary_person_id`),
  KEY `ix_product_entitlements_1` (`product_id`),
  KEY `ix_product_entitlements_2` (`beneficiary_person_id`),
  CONSTRAINT `ck_product_entitlements_1` CHECK (valid_until IS NULL OR valid_until > valid_from)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Server allowlist; no arbitrary rule execution or privacy bypass flags.
CREATE TABLE `joma_config_definitions` (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  key_code VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  value_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  validation_json JSON NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_config_definitions_1` (`key_code`)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Never store plaintext provider secrets here; encrypted secret storage/deployment is a separate design. Effect on existing operations is a policy gate.
CREATE TABLE `joma_config_versions` (
  id BINARY(16) NOT NULL,
  definition_id INT UNSIGNED NOT NULL,
  scope_id BINARY(16) NOT NULL,
  version_no INT UNSIGNED NOT NULL,
  value_json JSON NOT NULL,
  effective_at DATETIME(6) NOT NULL,
  changed_by_person_id BINARY(16) NOT NULL,
  command_id BINARY(16) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_config_versions_1` (`definition_id`, `scope_id`, `version_no`),
  UNIQUE KEY `uq_config_versions_2` (`command_id`),
  KEY `ix_config_versions_1` (`scope_id`),
  KEY `ix_config_versions_2` (`changed_by_person_id`),
  CONSTRAINT `ck_config_versions_1` CHECK (version_no > 0)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Append-only command contract, not tamper-proof against host owner. Resource references are diagnostic, NEVER authorization grants or polymorphic clinical FKs. Invalid/nonexistent input may be audited safely without a target FK.
CREATE TABLE `joma_audit_entries` (
  id BINARY(16) NOT NULL,
  command_id BINARY(16) NULL,
  actor_person_id BINARY(16) NULL,
  system_actor_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  scope_id BINARY(16) NULL,
  action_code VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  resource_kind VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  resource_reference VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  decision VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  reason_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  redacted_metadata_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  KEY `ix_audit_entries_1` (`scope_id`, `created_at`),
  KEY `ix_audit_entries_2` (`command_id`),
  KEY `ix_audit_entries_3` (`actor_person_id`),
  CONSTRAINT `ck_audit_entries_1` CHECK (decision IN ('ALLOWED','DENIED','CONFLICT','FAILED')),
  CONSTRAINT `ck_audit_entries_2` CHECK ((actor_person_id IS NOT NULL AND system_actor_code IS NULL) OR (actor_person_id IS NULL AND system_actor_code IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- No clinical report content or credentials. Recheck current authority before delivery. Intent records are not business-state truth.
CREATE TABLE `joma_notification_intents` (
  id BINARY(16) NOT NULL,
  command_id BINARY(16) NOT NULL,
  recipient_person_id BINARY(16) NOT NULL,
  template_code VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  scheduled_at DATETIME(6) NOT NULL,
  redacted_parameters_json JSON NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notification_intents_1` (`command_id`, `recipient_person_id`, `template_code`),
  KEY `ix_notification_intents_1` (`status`, `scheduled_at`),
  KEY `ix_notification_intents_2` (`recipient_person_id`),
  CONSTRAINT `ck_notification_intents_1` CHECK (status IN ('PENDING','SENT','FAILED','CANCELLED'))
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `joma_notification_attempts` (
  id BINARY(16) NOT NULL,
  intent_id BINARY(16) NOT NULL,
  attempt_no INT UNSIGNED NOT NULL,
  provider_reference VARCHAR(191) NULL,
  result_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  attempted_at DATETIME(6) NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notification_attempts_1` (`intent_id`, `attempt_no`),
  CONSTRAINT `ck_notification_attempts_1` CHECK (attempt_no > 0)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Add foreign keys after all tables exist. Existing data is never silently bypassed.
ALTER TABLE `joma_accounts` ADD CONSTRAINT `fk_joma_001` FOREIGN KEY (`person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_person_contacts` ADD CONSTRAINT `fk_joma_002` FOREIGN KEY (`person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_person_contacts` ADD CONSTRAINT `fk_joma_003` FOREIGN KEY (`contact_id`) REFERENCES `joma_contact_points` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_work_scopes` ADD CONSTRAINT `fk_joma_004` FOREIGN KEY (`parent_scope_id`) REFERENCES `joma_work_scopes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_work_scopes` ADD CONSTRAINT `fk_joma_005` FOREIGN KEY (`independent_practitioner_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_memberships` ADD CONSTRAINT `fk_joma_006` FOREIGN KEY (`person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_memberships` ADD CONSTRAINT `fk_joma_007` FOREIGN KEY (`scope_id`) REFERENCES `joma_work_scopes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_role_assignments` ADD CONSTRAINT `fk_joma_008` FOREIGN KEY (`account_id`, `person_id`) REFERENCES `joma_accounts` (`id`, `person_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_role_assignments` ADD CONSTRAINT `fk_joma_009` FOREIGN KEY (`membership_id`, `person_id`, `scope_id`) REFERENCES `joma_memberships` (`id`, `person_id`, `scope_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_role_assignments` ADD CONSTRAINT `fk_joma_010` FOREIGN KEY (`role_id`) REFERENCES `joma_role_definitions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_professional_profiles` ADD CONSTRAINT `fk_joma_011` FOREIGN KEY (`person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_professional_credentials` ADD CONSTRAINT `fk_joma_012` FOREIGN KEY (`profile_id`) REFERENCES `joma_professional_profiles` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_command_receipts` ADD CONSTRAINT `fk_joma_013` FOREIGN KEY (`scope_id`) REFERENCES `joma_work_scopes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_command_receipts` ADD CONSTRAINT `fk_joma_014` FOREIGN KEY (`actor_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_service_policy_versions` ADD CONSTRAINT `fk_joma_015` FOREIGN KEY (`service_id`) REFERENCES `joma_service_definitions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_service_policy_versions` ADD CONSTRAINT `fk_joma_016` FOREIGN KEY (`author_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_service_policy_versions` ADD CONSTRAINT `fk_joma_017` FOREIGN KEY (`approved_by_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_service_offerings` ADD CONSTRAINT `fk_joma_018` FOREIGN KEY (`scope_id`) REFERENCES `joma_work_scopes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_service_offerings` ADD CONSTRAINT `fk_joma_019` FOREIGN KEY (`policy_version_id`, `service_id`) REFERENCES `joma_service_policy_versions` (`id`, `service_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_service_providers` ADD CONSTRAINT `fk_joma_020` FOREIGN KEY (`offering_id`, `scope_id`) REFERENCES `joma_service_offerings` (`id`, `scope_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_service_providers` ADD CONSTRAINT `fk_joma_021` FOREIGN KEY (`membership_id`, `person_id`, `scope_id`) REFERENCES `joma_memberships` (`id`, `person_id`, `scope_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_admissions` ADD CONSTRAINT `fk_joma_022` FOREIGN KEY (`primary_subject_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_admissions` ADD CONSTRAINT `fk_joma_023` FOREIGN KEY (`recorded_by_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_admissions` ADD CONSTRAINT `fk_joma_024` FOREIGN KEY (`offering_id`, `scope_id`) REFERENCES `joma_service_offerings` (`id`, `scope_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_admission_participants` ADD CONSTRAINT `fk_joma_025` FOREIGN KEY (`admission_id`) REFERENCES `joma_admissions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_admission_participants` ADD CONSTRAINT `fk_joma_026` FOREIGN KEY (`person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_therapist_assignments` ADD CONSTRAINT `fk_joma_027` FOREIGN KEY (`admission_id`) REFERENCES `joma_admissions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_therapist_assignments` ADD CONSTRAINT `fk_joma_028` FOREIGN KEY (`therapist_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_therapist_assignments` ADD CONSTRAINT `fk_joma_029` FOREIGN KEY (`assigned_by_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_responsibility_acceptances` ADD CONSTRAINT `fk_joma_030` FOREIGN KEY (`admission_id`) REFERENCES `joma_admissions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_responsibility_acceptances` ADD CONSTRAINT `fk_joma_031` FOREIGN KEY (`assignment_id`, `admission_id`, `therapist_person_id`) REFERENCES `joma_therapist_assignments` (`id`, `admission_id`, `therapist_person_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_responsibility_acceptances` ADD CONSTRAINT `fk_joma_032` FOREIGN KEY (`therapist_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_responsibility_acceptances` ADD CONSTRAINT `fk_joma_033` FOREIGN KEY (`command_id`) REFERENCES `joma_command_receipts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_therapeutic_relationships` ADD CONSTRAINT `fk_joma_034` FOREIGN KEY (`acceptance_id`, `therapist_person_id`) REFERENCES `joma_responsibility_acceptances` (`id`, `therapist_person_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_clinical_cases` ADD CONSTRAINT `fk_joma_035` FOREIGN KEY (`relationship_id`, `responsible_therapist_id`) REFERENCES `joma_therapeutic_relationships` (`id`, `therapist_person_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_admissions` ADD CONSTRAINT `fk_joma_036` FOREIGN KEY (`case_id`) REFERENCES `joma_clinical_cases` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_case_participants` ADD CONSTRAINT `fk_joma_037` FOREIGN KEY (`case_id`) REFERENCES `joma_clinical_cases` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_case_participants` ADD CONSTRAINT `fk_joma_038` FOREIGN KEY (`person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_case_operational_contexts` ADD CONSTRAINT `fk_joma_039` FOREIGN KEY (`case_id`) REFERENCES `joma_clinical_cases` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_case_operational_contexts` ADD CONSTRAINT `fk_joma_040` FOREIGN KEY (`scope_id`) REFERENCES `joma_work_scopes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_representations` ADD CONSTRAINT `fk_joma_041` FOREIGN KEY (`representative_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_representations` ADD CONSTRAINT `fk_joma_042` FOREIGN KEY (`subject_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_representations` ADD CONSTRAINT `fk_joma_043` FOREIGN KEY (`scope_id`) REFERENCES `joma_work_scopes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_administrative_delegations` ADD CONSTRAINT `fk_joma_044` FOREIGN KEY (`grantor_role_assignment_id`, `grantor_person_id`, `scope_id`) REFERENCES `joma_role_assignments` (`id`, `person_id`, `scope_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_administrative_delegations` ADD CONSTRAINT `fk_joma_045` FOREIGN KEY (`grantee_membership_id`, `grantee_person_id`, `scope_id`) REFERENCES `joma_memberships` (`id`, `person_id`, `scope_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_administrative_delegations` ADD CONSTRAINT `fk_joma_046` FOREIGN KEY (`target_admission_id`) REFERENCES `joma_admissions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_administrative_delegations` ADD CONSTRAINT `fk_joma_047` FOREIGN KEY (`target_representation_id`) REFERENCES `joma_representations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_verification_authority_bases` ADD CONSTRAINT `fk_joma_048` FOREIGN KEY (`direct_role_assignment_id`, `actor_person_id`, `scope_id`) REFERENCES `joma_role_assignments` (`id`, `person_id`, `scope_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_verification_authority_bases` ADD CONSTRAINT `fk_joma_049` FOREIGN KEY (`delegation_id`, `actor_person_id`, `scope_id`) REFERENCES `joma_administrative_delegations` (`id`, `grantee_person_id`, `scope_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_representation_verifications` ADD CONSTRAINT `fk_joma_050` FOREIGN KEY (`representation_id`, `representative_person_id`, `subject_person_id`, `scope_id`) REFERENCES `joma_representations` (`id`, `representative_person_id`, `subject_person_id`, `scope_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_representation_verifications` ADD CONSTRAINT `fk_joma_051` FOREIGN KEY (`authority_basis_id`, `actor_person_id`, `scope_id`) REFERENCES `joma_verification_authority_bases` (`id`, `actor_person_id`, `scope_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_representation_events` ADD CONSTRAINT `fk_joma_052` FOREIGN KEY (`representation_id`) REFERENCES `joma_representations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_representation_events` ADD CONSTRAINT `fk_joma_053` FOREIGN KEY (`command_id`) REFERENCES `joma_command_receipts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_representation_events` ADD CONSTRAINT `fk_joma_054` FOREIGN KEY (`actor_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_representation_events` ADD CONSTRAINT `fk_joma_055` FOREIGN KEY (`scope_id`) REFERENCES `joma_work_scopes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_case_representation_restrictions` ADD CONSTRAINT `fk_joma_056` FOREIGN KEY (`representation_id`) REFERENCES `joma_representations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_case_representation_restrictions` ADD CONSTRAINT `fk_joma_057` FOREIGN KEY (`case_id`, `clinician_person_id`) REFERENCES `joma_clinical_cases` (`id`, `responsible_therapist_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_case_representation_restrictions` ADD CONSTRAINT `fk_joma_058` FOREIGN KEY (`service_id`) REFERENCES `joma_service_definitions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_case_representation_restrictions` ADD CONSTRAINT `fk_joma_059` FOREIGN KEY (`command_id`) REFERENCES `joma_command_receipts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_appointment_requests` ADD CONSTRAINT `fk_joma_060` FOREIGN KEY (`subject_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_appointment_requests` ADD CONSTRAINT `fk_joma_061` FOREIGN KEY (`admission_id`) REFERENCES `joma_admissions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_appointment_requests` ADD CONSTRAINT `fk_joma_062` FOREIGN KEY (`case_id`) REFERENCES `joma_clinical_cases` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_appointment_requests` ADD CONSTRAINT `fk_joma_063` FOREIGN KEY (`offering_id`) REFERENCES `joma_service_offerings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_schedule_resources` ADD CONSTRAINT `fk_joma_064` FOREIGN KEY (`scope_id`) REFERENCES `joma_work_scopes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_schedule_resources` ADD CONSTRAINT `fk_joma_065` FOREIGN KEY (`therapist_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_capacity_holds` ADD CONSTRAINT `fk_joma_066` FOREIGN KEY (`case_id`) REFERENCES `joma_clinical_cases` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_capacity_holds` ADD CONSTRAINT `fk_joma_067` FOREIGN KEY (`offering_id`) REFERENCES `joma_service_offerings` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_capacity_holds` ADD CONSTRAINT `fk_joma_068` FOREIGN KEY (`policy_version_id`) REFERENCES `joma_service_policy_versions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_capacity_holds` ADD CONSTRAINT `fk_joma_069` FOREIGN KEY (`request_id`) REFERENCES `joma_appointment_requests` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_capacity_holds` ADD CONSTRAINT `fk_joma_070` FOREIGN KEY (`actor_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_capacity_holds` ADD CONSTRAINT `fk_joma_071` FOREIGN KEY (`command_id`) REFERENCES `joma_command_receipts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_hold_allocations` ADD CONSTRAINT `fk_joma_072` FOREIGN KEY (`hold_id`) REFERENCES `joma_capacity_holds` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_hold_allocations` ADD CONSTRAINT `fk_joma_073` FOREIGN KEY (`resource_id`) REFERENCES `joma_schedule_resources` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_appointments` ADD CONSTRAINT `fk_joma_074` FOREIGN KEY (`case_id`, `therapist_person_id`) REFERENCES `joma_clinical_cases` (`id`, `responsible_therapist_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_appointments` ADD CONSTRAINT `fk_joma_075` FOREIGN KEY (`offering_id`, `scope_id`) REFERENCES `joma_service_offerings` (`id`, `scope_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_appointments` ADD CONSTRAINT `fk_joma_076` FOREIGN KEY (`hold_id`, `case_id`) REFERENCES `joma_capacity_holds` (`id`, `case_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_appointments` ADD CONSTRAINT `fk_joma_077` FOREIGN KEY (`rebooked_from_appointment_id`) REFERENCES `joma_appointments` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_appointments` ADD CONSTRAINT `fk_joma_078` FOREIGN KEY (`command_id`) REFERENCES `joma_command_receipts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_appointment_requests` ADD CONSTRAINT `fk_joma_079` FOREIGN KEY (`fulfilled_appointment_id`) REFERENCES `joma_appointments` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_appointment_allocations` ADD CONSTRAINT `fk_joma_080` FOREIGN KEY (`appointment_id`) REFERENCES `joma_appointments` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_appointment_allocations` ADD CONSTRAINT `fk_joma_081` FOREIGN KEY (`resource_id`) REFERENCES `joma_schedule_resources` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_appointment_changes` ADD CONSTRAINT `fk_joma_082` FOREIGN KEY (`appointment_id`) REFERENCES `joma_appointments` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_appointment_changes` ADD CONSTRAINT `fk_joma_083` FOREIGN KEY (`command_id`) REFERENCES `joma_command_receipts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_appointment_changes` ADD CONSTRAINT `fk_joma_084` FOREIGN KEY (`actor_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_attendance_observations` ADD CONSTRAINT `fk_joma_085` FOREIGN KEY (`appointment_id`) REFERENCES `joma_appointments` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_attendance_observations` ADD CONSTRAINT `fk_joma_086` FOREIGN KEY (`observed_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_attendance_observations` ADD CONSTRAINT `fk_joma_087` FOREIGN KEY (`recorded_by_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_clinical_sessions` ADD CONSTRAINT `fk_joma_088` FOREIGN KEY (`case_id`, `clinician_person_id`) REFERENCES `joma_clinical_cases` (`id`, `responsible_therapist_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_clinical_sessions` ADD CONSTRAINT `fk_joma_089` FOREIGN KEY (`appointment_id`, `case_id`, `clinician_person_id`) REFERENCES `joma_appointments` (`id`, `case_id`, `therapist_person_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_clinical_sessions` ADD CONSTRAINT `fk_joma_090` FOREIGN KEY (`command_id`) REFERENCES `joma_command_receipts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_templates` ADD CONSTRAINT `fk_joma_091` FOREIGN KEY (`scope_id`) REFERENCES `joma_work_scopes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_versions` ADD CONSTRAINT `fk_joma_092` FOREIGN KEY (`template_id`) REFERENCES `joma_form_templates` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_versions` ADD CONSTRAINT `fk_joma_093` FOREIGN KEY (`author_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_approvals` ADD CONSTRAINT `fk_joma_094` FOREIGN KEY (`form_version_id`) REFERENCES `joma_form_versions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_approvals` ADD CONSTRAINT `fk_joma_095` FOREIGN KEY (`role_assignment_id`, `approver_person_id`, `scope_id`) REFERENCES `joma_role_assignments` (`id`, `person_id`, `scope_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_service_form_requirements` ADD CONSTRAINT `fk_joma_096` FOREIGN KEY (`policy_version_id`) REFERENCES `joma_service_policy_versions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_service_form_requirements` ADD CONSTRAINT `fk_joma_097` FOREIGN KEY (`form_version_id`) REFERENCES `joma_form_versions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_service_prerequisites` ADD CONSTRAINT `fk_joma_098` FOREIGN KEY (`policy_version_id`) REFERENCES `joma_service_policy_versions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_instances` ADD CONSTRAINT `fk_joma_099` FOREIGN KEY (`form_version_id`) REFERENCES `joma_form_versions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_instances` ADD CONSTRAINT `fk_joma_100` FOREIGN KEY (`scope_id`) REFERENCES `joma_work_scopes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_instances` ADD CONSTRAINT `fk_joma_101` FOREIGN KEY (`admission_id`) REFERENCES `joma_admissions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_instances` ADD CONSTRAINT `fk_joma_102` FOREIGN KEY (`case_id`) REFERENCES `joma_clinical_cases` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_instances` ADD CONSTRAINT `fk_joma_103` FOREIGN KEY (`appointment_id`) REFERENCES `joma_appointments` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_instances` ADD CONSTRAINT `fk_joma_104` FOREIGN KEY (`designated_respondent_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_subjects` ADD CONSTRAINT `fk_joma_105` FOREIGN KEY (`instance_id`) REFERENCES `joma_form_instances` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_subjects` ADD CONSTRAINT `fk_joma_106` FOREIGN KEY (`subject_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_submission_revisions` ADD CONSTRAINT `fk_joma_107` FOREIGN KEY (`instance_id`) REFERENCES `joma_form_instances` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_submission_revisions` ADD CONSTRAINT `fk_joma_108` FOREIGN KEY (`respondent_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_submission_revisions` ADD CONSTRAINT `fk_joma_109` FOREIGN KEY (`recorded_by_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_submission_revisions` ADD CONSTRAINT `fk_joma_110` FOREIGN KEY (`representation_id`) REFERENCES `joma_representations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_submission_revisions` ADD CONSTRAINT `fk_joma_111` FOREIGN KEY (`command_id`) REFERENCES `joma_command_receipts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_consent_evidence` ADD CONSTRAINT `fk_joma_112` FOREIGN KEY (`submission_revision_id`) REFERENCES `joma_form_submission_revisions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_consent_evidence` ADD CONSTRAINT `fk_joma_113` FOREIGN KEY (`subject_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_consent_evidence` ADD CONSTRAINT `fk_joma_114` FOREIGN KEY (`consenting_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_consent_evidence` ADD CONSTRAINT `fk_joma_115` FOREIGN KEY (`representation_id`) REFERENCES `joma_representations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_publications` ADD CONSTRAINT `fk_joma_116` FOREIGN KEY (`submission_revision_id`, `instance_id`) REFERENCES `joma_form_submission_revisions` (`id`, `instance_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_publications` ADD CONSTRAINT `fk_joma_117` FOREIGN KEY (`instance_id`, `case_id`) REFERENCES `joma_form_instances` (`id`, `case_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_publications` ADD CONSTRAINT `fk_joma_118` FOREIGN KEY (`published_by_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_publications` ADD CONSTRAINT `fk_joma_119` FOREIGN KEY (`command_id`) REFERENCES `joma_command_receipts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_assessments` ADD CONSTRAINT `fk_joma_120` FOREIGN KEY (`case_id`) REFERENCES `joma_clinical_cases` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_assessments` ADD CONSTRAINT `fk_joma_121` FOREIGN KEY (`subject_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_assessment_respondents` ADD CONSTRAINT `fk_joma_122` FOREIGN KEY (`assessment_id`) REFERENCES `joma_assessments` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_assessment_respondents` ADD CONSTRAINT `fk_joma_123` FOREIGN KEY (`respondent_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_assessment_respondents` ADD CONSTRAINT `fk_joma_124` FOREIGN KEY (`representation_id`) REFERENCES `joma_representations` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_assessment_assignments` ADD CONSTRAINT `fk_joma_125` FOREIGN KEY (`assessment_id`) REFERENCES `joma_assessments` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_assessment_assignments` ADD CONSTRAINT `fk_joma_126` FOREIGN KEY (`assessor_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_assessment_assignments` ADD CONSTRAINT `fk_joma_127` FOREIGN KEY (`authorized_by_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_assessment_assignments` ADD CONSTRAINT `fk_joma_128` FOREIGN KEY (`scope_id`) REFERENCES `joma_work_scopes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_protected_files` ADD CONSTRAINT `fk_joma_129` FOREIGN KEY (`uploaded_by_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_assessment_reports` ADD CONSTRAINT `fk_joma_130` FOREIGN KEY (`assessment_id`, `case_id`) REFERENCES `joma_assessments` (`id`, `case_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_report_versions` ADD CONSTRAINT `fk_joma_131` FOREIGN KEY (`report_id`, `case_id`) REFERENCES `joma_assessment_reports` (`id`, `case_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_report_versions` ADD CONSTRAINT `fk_joma_132` FOREIGN KEY (`author_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_report_versions` ADD CONSTRAINT `fk_joma_133` FOREIGN KEY (`uploaded_by_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_report_versions` ADD CONSTRAINT `fk_joma_134` FOREIGN KEY (`protected_file_id`) REFERENCES `joma_protected_files` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_report_publications` ADD CONSTRAINT `fk_joma_135` FOREIGN KEY (`report_version_id`, `case_id`) REFERENCES `joma_report_versions` (`id`, `case_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_report_publications` ADD CONSTRAINT `fk_joma_136` FOREIGN KEY (`case_id`, `published_by_person_id`) REFERENCES `joma_clinical_cases` (`id`, `responsible_therapist_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_report_publications` ADD CONSTRAINT `fk_joma_137` FOREIGN KEY (`command_id`) REFERENCES `joma_command_receipts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_publication_audiences` ADD CONSTRAINT `fk_joma_138` FOREIGN KEY (`publication_id`) REFERENCES `joma_form_publications` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_publication_audiences` ADD CONSTRAINT `fk_joma_139` FOREIGN KEY (`recipient_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_publication_audiences` ADD CONSTRAINT `fk_joma_140` FOREIGN KEY (`access_subject_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_publication_audiences` ADD CONSTRAINT `fk_joma_141` FOREIGN KEY (`scope_id`) REFERENCES `joma_work_scopes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_form_publication_audiences` ADD CONSTRAINT `fk_joma_142` FOREIGN KEY (`representation_id`, `recipient_person_id`, `access_subject_person_id`, `scope_id`) REFERENCES `joma_representations` (`id`, `representative_person_id`, `subject_person_id`, `scope_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_report_publication_audiences` ADD CONSTRAINT `fk_joma_143` FOREIGN KEY (`publication_id`) REFERENCES `joma_report_publications` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_report_publication_audiences` ADD CONSTRAINT `fk_joma_144` FOREIGN KEY (`recipient_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_report_publication_audiences` ADD CONSTRAINT `fk_joma_145` FOREIGN KEY (`access_subject_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_report_publication_audiences` ADD CONSTRAINT `fk_joma_146` FOREIGN KEY (`scope_id`) REFERENCES `joma_work_scopes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_report_publication_audiences` ADD CONSTRAINT `fk_joma_147` FOREIGN KEY (`representation_id`, `recipient_person_id`, `access_subject_person_id`, `scope_id`) REFERENCES `joma_representations` (`id`, `representative_person_id`, `subject_person_id`, `scope_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_private_note_references` ADD CONSTRAINT `fk_joma_148` FOREIGN KEY (`case_id`, `author_person_id`) REFERENCES `joma_clinical_cases` (`id`, `responsible_therapist_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_private_note_references` ADD CONSTRAINT `fk_joma_149` FOREIGN KEY (`author_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_practitioner_messages` ADD CONSTRAINT `fk_joma_150` FOREIGN KEY (`case_id`) REFERENCES `joma_clinical_cases` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_practitioner_messages` ADD CONSTRAINT `fk_joma_151` FOREIGN KEY (`sender_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_practitioner_messages` ADD CONSTRAINT `fk_joma_152` FOREIGN KEY (`recipient_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_practitioner_messages` ADD CONSTRAINT `fk_joma_153` FOREIGN KEY (`command_id`) REFERENCES `joma_command_receipts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_financial_spaces` ADD CONSTRAINT `fk_joma_154` FOREIGN KEY (`scope_id`) REFERENCES `joma_work_scopes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_destination_accounts` ADD CONSTRAINT `fk_joma_155` FOREIGN KEY (`financial_space_id`) REFERENCES `joma_financial_spaces` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_payment_receipts` ADD CONSTRAINT `fk_joma_156` FOREIGN KEY (`financial_space_id`) REFERENCES `joma_financial_spaces` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_payment_receipts` ADD CONSTRAINT `fk_joma_157` FOREIGN KEY (`payer_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_payment_receipts` ADD CONSTRAINT `fk_joma_158` FOREIGN KEY (`destination_account_id`, `financial_space_id`) REFERENCES `joma_destination_accounts` (`id`, `financial_space_id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_payment_receipts` ADD CONSTRAINT `fk_joma_159` FOREIGN KEY (`recorded_by_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_payment_receipts` ADD CONSTRAINT `fk_joma_160` FOREIGN KEY (`command_id`) REFERENCES `joma_command_receipts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_payment_receipts` ADD CONSTRAINT `fk_joma_161` FOREIGN KEY (`method_id`, `method_is_bank`) REFERENCES `joma_payment_methods` (`id`, `is_bank_method`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_product_entitlements` ADD CONSTRAINT `fk_joma_162` FOREIGN KEY (`product_id`) REFERENCES `joma_product_definitions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_product_entitlements` ADD CONSTRAINT `fk_joma_163` FOREIGN KEY (`beneficiary_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_config_versions` ADD CONSTRAINT `fk_joma_164` FOREIGN KEY (`definition_id`) REFERENCES `joma_config_definitions` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_config_versions` ADD CONSTRAINT `fk_joma_165` FOREIGN KEY (`scope_id`) REFERENCES `joma_work_scopes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_config_versions` ADD CONSTRAINT `fk_joma_166` FOREIGN KEY (`changed_by_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_config_versions` ADD CONSTRAINT `fk_joma_167` FOREIGN KEY (`command_id`) REFERENCES `joma_command_receipts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_audit_entries` ADD CONSTRAINT `fk_joma_168` FOREIGN KEY (`command_id`) REFERENCES `joma_command_receipts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_audit_entries` ADD CONSTRAINT `fk_joma_169` FOREIGN KEY (`actor_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_audit_entries` ADD CONSTRAINT `fk_joma_170` FOREIGN KEY (`scope_id`) REFERENCES `joma_work_scopes` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_notification_intents` ADD CONSTRAINT `fk_joma_171` FOREIGN KEY (`command_id`) REFERENCES `joma_command_receipts` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_notification_intents` ADD CONSTRAINT `fk_joma_172` FOREIGN KEY (`recipient_person_id`) REFERENCES `joma_persons` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE `joma_notification_attempts` ADD CONSTRAINT `fk_joma_173` FOREIGN KEY (`intent_id`) REFERENCES `joma_notification_intents` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT;
