-- READ ONLY. Select mirbolouki_clinic in phpMyAdmin before running.
SELECT DATABASE() AS selected_database, VERSION() AS server_version,
       @@foreign_key_checks AS foreign_keys_enabled,
       @@check_constraint_checks AS checks_enabled, @@sql_mode AS sql_mode;
SELECT COUNT(*) AS joma_table_count,
       SUM(ENGINE='InnoDB') AS innodb_count,
       SUM(TABLE_COLLATION='utf8mb4_unicode_ci') AS table_collation_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND LEFT(TABLE_NAME,5)='joma_';
SELECT CONSTRAINT_TYPE, COUNT(*) AS constraint_count
FROM information_schema.TABLE_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,5)='joma_'
GROUP BY CONSTRAINT_TYPE;
-- Expected: 70 tables, 70 PK, 173 FK, 72 UNIQUE. CHECK count can exceed 90
-- because MariaDB adds JSON_VALID constraints automatically.
SELECT DELETE_RULE, UPDATE_RULE, COUNT(*) AS fk_count
FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,5)='joma_'
GROUP BY DELETE_RULE,UPDATE_RULE;
-- Both rules must be RESTRICT for all 173 FKs.

-- Missing expected tables: expected ZERO rows.
SELECT e.expected_name FROM (
SELECT 'joma_role_definitions' AS expected_name
UNION ALL
SELECT 'joma_service_definitions' AS expected_name
UNION ALL
SELECT 'joma_payment_methods' AS expected_name
UNION ALL
SELECT 'joma_product_definitions' AS expected_name
UNION ALL
SELECT 'joma_persons' AS expected_name
UNION ALL
SELECT 'joma_accounts' AS expected_name
UNION ALL
SELECT 'joma_contact_points' AS expected_name
UNION ALL
SELECT 'joma_person_contacts' AS expected_name
UNION ALL
SELECT 'joma_work_scopes' AS expected_name
UNION ALL
SELECT 'joma_memberships' AS expected_name
UNION ALL
SELECT 'joma_role_assignments' AS expected_name
UNION ALL
SELECT 'joma_professional_profiles' AS expected_name
UNION ALL
SELECT 'joma_professional_credentials' AS expected_name
UNION ALL
SELECT 'joma_command_receipts' AS expected_name
UNION ALL
SELECT 'joma_service_policy_versions' AS expected_name
UNION ALL
SELECT 'joma_service_offerings' AS expected_name
UNION ALL
SELECT 'joma_service_providers' AS expected_name
UNION ALL
SELECT 'joma_admissions' AS expected_name
UNION ALL
SELECT 'joma_admission_participants' AS expected_name
UNION ALL
SELECT 'joma_therapist_assignments' AS expected_name
UNION ALL
SELECT 'joma_responsibility_acceptances' AS expected_name
UNION ALL
SELECT 'joma_therapeutic_relationships' AS expected_name
UNION ALL
SELECT 'joma_clinical_cases' AS expected_name
UNION ALL
SELECT 'joma_case_participants' AS expected_name
UNION ALL
SELECT 'joma_case_operational_contexts' AS expected_name
UNION ALL
SELECT 'joma_representations' AS expected_name
UNION ALL
SELECT 'joma_administrative_delegations' AS expected_name
UNION ALL
SELECT 'joma_verification_authority_bases' AS expected_name
UNION ALL
SELECT 'joma_representation_verifications' AS expected_name
UNION ALL
SELECT 'joma_representation_events' AS expected_name
UNION ALL
SELECT 'joma_case_representation_restrictions' AS expected_name
UNION ALL
SELECT 'joma_appointment_requests' AS expected_name
UNION ALL
SELECT 'joma_schedule_resources' AS expected_name
UNION ALL
SELECT 'joma_capacity_holds' AS expected_name
UNION ALL
SELECT 'joma_hold_allocations' AS expected_name
UNION ALL
SELECT 'joma_appointments' AS expected_name
UNION ALL
SELECT 'joma_appointment_allocations' AS expected_name
UNION ALL
SELECT 'joma_appointment_changes' AS expected_name
UNION ALL
SELECT 'joma_attendance_observations' AS expected_name
UNION ALL
SELECT 'joma_clinical_sessions' AS expected_name
UNION ALL
SELECT 'joma_form_templates' AS expected_name
UNION ALL
SELECT 'joma_form_versions' AS expected_name
UNION ALL
SELECT 'joma_form_approvals' AS expected_name
UNION ALL
SELECT 'joma_service_form_requirements' AS expected_name
UNION ALL
SELECT 'joma_service_prerequisites' AS expected_name
UNION ALL
SELECT 'joma_form_instances' AS expected_name
UNION ALL
SELECT 'joma_form_subjects' AS expected_name
UNION ALL
SELECT 'joma_form_submission_revisions' AS expected_name
UNION ALL
SELECT 'joma_consent_evidence' AS expected_name
UNION ALL
SELECT 'joma_form_publications' AS expected_name
UNION ALL
SELECT 'joma_assessments' AS expected_name
UNION ALL
SELECT 'joma_assessment_respondents' AS expected_name
UNION ALL
SELECT 'joma_assessment_assignments' AS expected_name
UNION ALL
SELECT 'joma_protected_files' AS expected_name
UNION ALL
SELECT 'joma_assessment_reports' AS expected_name
UNION ALL
SELECT 'joma_report_versions' AS expected_name
UNION ALL
SELECT 'joma_report_publications' AS expected_name
UNION ALL
SELECT 'joma_form_publication_audiences' AS expected_name
UNION ALL
SELECT 'joma_report_publication_audiences' AS expected_name
UNION ALL
SELECT 'joma_private_note_references' AS expected_name
UNION ALL
SELECT 'joma_practitioner_messages' AS expected_name
UNION ALL
SELECT 'joma_financial_spaces' AS expected_name
UNION ALL
SELECT 'joma_destination_accounts' AS expected_name
UNION ALL
SELECT 'joma_payment_receipts' AS expected_name
UNION ALL
SELECT 'joma_product_entitlements' AS expected_name
UNION ALL
SELECT 'joma_config_definitions' AS expected_name
UNION ALL
SELECT 'joma_config_versions' AS expected_name
UNION ALL
SELECT 'joma_audit_entries' AS expected_name
UNION ALL
SELECT 'joma_notification_intents' AS expected_name
UNION ALL
SELECT 'joma_notification_attempts' AS expected_name
) e WHERE NOT EXISTS (SELECT 1 FROM information_schema.TABLES a WHERE a.TABLE_NAME=e.expected_name AND TABLE_SCHEMA=DATABASE());

-- Missing expected foreign keys: expected ZERO rows.
SELECT e.expected_name FROM (
SELECT 'fk_joma_001' AS expected_name
UNION ALL
SELECT 'fk_joma_002' AS expected_name
UNION ALL
SELECT 'fk_joma_003' AS expected_name
UNION ALL
SELECT 'fk_joma_004' AS expected_name
UNION ALL
SELECT 'fk_joma_005' AS expected_name
UNION ALL
SELECT 'fk_joma_006' AS expected_name
UNION ALL
SELECT 'fk_joma_007' AS expected_name
UNION ALL
SELECT 'fk_joma_008' AS expected_name
UNION ALL
SELECT 'fk_joma_009' AS expected_name
UNION ALL
SELECT 'fk_joma_010' AS expected_name
UNION ALL
SELECT 'fk_joma_011' AS expected_name
UNION ALL
SELECT 'fk_joma_012' AS expected_name
UNION ALL
SELECT 'fk_joma_013' AS expected_name
UNION ALL
SELECT 'fk_joma_014' AS expected_name
UNION ALL
SELECT 'fk_joma_015' AS expected_name
UNION ALL
SELECT 'fk_joma_016' AS expected_name
UNION ALL
SELECT 'fk_joma_017' AS expected_name
UNION ALL
SELECT 'fk_joma_018' AS expected_name
UNION ALL
SELECT 'fk_joma_019' AS expected_name
UNION ALL
SELECT 'fk_joma_020' AS expected_name
UNION ALL
SELECT 'fk_joma_021' AS expected_name
UNION ALL
SELECT 'fk_joma_022' AS expected_name
UNION ALL
SELECT 'fk_joma_023' AS expected_name
UNION ALL
SELECT 'fk_joma_024' AS expected_name
UNION ALL
SELECT 'fk_joma_025' AS expected_name
UNION ALL
SELECT 'fk_joma_026' AS expected_name
UNION ALL
SELECT 'fk_joma_027' AS expected_name
UNION ALL
SELECT 'fk_joma_028' AS expected_name
UNION ALL
SELECT 'fk_joma_029' AS expected_name
UNION ALL
SELECT 'fk_joma_030' AS expected_name
UNION ALL
SELECT 'fk_joma_031' AS expected_name
UNION ALL
SELECT 'fk_joma_032' AS expected_name
UNION ALL
SELECT 'fk_joma_033' AS expected_name
UNION ALL
SELECT 'fk_joma_034' AS expected_name
UNION ALL
SELECT 'fk_joma_035' AS expected_name
UNION ALL
SELECT 'fk_joma_036' AS expected_name
UNION ALL
SELECT 'fk_joma_037' AS expected_name
UNION ALL
SELECT 'fk_joma_038' AS expected_name
UNION ALL
SELECT 'fk_joma_039' AS expected_name
UNION ALL
SELECT 'fk_joma_040' AS expected_name
UNION ALL
SELECT 'fk_joma_041' AS expected_name
UNION ALL
SELECT 'fk_joma_042' AS expected_name
UNION ALL
SELECT 'fk_joma_043' AS expected_name
UNION ALL
SELECT 'fk_joma_044' AS expected_name
UNION ALL
SELECT 'fk_joma_045' AS expected_name
UNION ALL
SELECT 'fk_joma_046' AS expected_name
UNION ALL
SELECT 'fk_joma_047' AS expected_name
UNION ALL
SELECT 'fk_joma_048' AS expected_name
UNION ALL
SELECT 'fk_joma_049' AS expected_name
UNION ALL
SELECT 'fk_joma_050' AS expected_name
UNION ALL
SELECT 'fk_joma_051' AS expected_name
UNION ALL
SELECT 'fk_joma_052' AS expected_name
UNION ALL
SELECT 'fk_joma_053' AS expected_name
UNION ALL
SELECT 'fk_joma_054' AS expected_name
UNION ALL
SELECT 'fk_joma_055' AS expected_name
UNION ALL
SELECT 'fk_joma_056' AS expected_name
UNION ALL
SELECT 'fk_joma_057' AS expected_name
UNION ALL
SELECT 'fk_joma_058' AS expected_name
UNION ALL
SELECT 'fk_joma_059' AS expected_name
UNION ALL
SELECT 'fk_joma_060' AS expected_name
UNION ALL
SELECT 'fk_joma_061' AS expected_name
UNION ALL
SELECT 'fk_joma_062' AS expected_name
UNION ALL
SELECT 'fk_joma_063' AS expected_name
UNION ALL
SELECT 'fk_joma_064' AS expected_name
UNION ALL
SELECT 'fk_joma_065' AS expected_name
UNION ALL
SELECT 'fk_joma_066' AS expected_name
UNION ALL
SELECT 'fk_joma_067' AS expected_name
UNION ALL
SELECT 'fk_joma_068' AS expected_name
UNION ALL
SELECT 'fk_joma_069' AS expected_name
UNION ALL
SELECT 'fk_joma_070' AS expected_name
UNION ALL
SELECT 'fk_joma_071' AS expected_name
UNION ALL
SELECT 'fk_joma_072' AS expected_name
UNION ALL
SELECT 'fk_joma_073' AS expected_name
UNION ALL
SELECT 'fk_joma_074' AS expected_name
UNION ALL
SELECT 'fk_joma_075' AS expected_name
UNION ALL
SELECT 'fk_joma_076' AS expected_name
UNION ALL
SELECT 'fk_joma_077' AS expected_name
UNION ALL
SELECT 'fk_joma_078' AS expected_name
UNION ALL
SELECT 'fk_joma_079' AS expected_name
UNION ALL
SELECT 'fk_joma_080' AS expected_name
UNION ALL
SELECT 'fk_joma_081' AS expected_name
UNION ALL
SELECT 'fk_joma_082' AS expected_name
UNION ALL
SELECT 'fk_joma_083' AS expected_name
UNION ALL
SELECT 'fk_joma_084' AS expected_name
UNION ALL
SELECT 'fk_joma_085' AS expected_name
UNION ALL
SELECT 'fk_joma_086' AS expected_name
UNION ALL
SELECT 'fk_joma_087' AS expected_name
UNION ALL
SELECT 'fk_joma_088' AS expected_name
UNION ALL
SELECT 'fk_joma_089' AS expected_name
UNION ALL
SELECT 'fk_joma_090' AS expected_name
UNION ALL
SELECT 'fk_joma_091' AS expected_name
UNION ALL
SELECT 'fk_joma_092' AS expected_name
UNION ALL
SELECT 'fk_joma_093' AS expected_name
UNION ALL
SELECT 'fk_joma_094' AS expected_name
UNION ALL
SELECT 'fk_joma_095' AS expected_name
UNION ALL
SELECT 'fk_joma_096' AS expected_name
UNION ALL
SELECT 'fk_joma_097' AS expected_name
UNION ALL
SELECT 'fk_joma_098' AS expected_name
UNION ALL
SELECT 'fk_joma_099' AS expected_name
UNION ALL
SELECT 'fk_joma_100' AS expected_name
UNION ALL
SELECT 'fk_joma_101' AS expected_name
UNION ALL
SELECT 'fk_joma_102' AS expected_name
UNION ALL
SELECT 'fk_joma_103' AS expected_name
UNION ALL
SELECT 'fk_joma_104' AS expected_name
UNION ALL
SELECT 'fk_joma_105' AS expected_name
UNION ALL
SELECT 'fk_joma_106' AS expected_name
UNION ALL
SELECT 'fk_joma_107' AS expected_name
UNION ALL
SELECT 'fk_joma_108' AS expected_name
UNION ALL
SELECT 'fk_joma_109' AS expected_name
UNION ALL
SELECT 'fk_joma_110' AS expected_name
UNION ALL
SELECT 'fk_joma_111' AS expected_name
UNION ALL
SELECT 'fk_joma_112' AS expected_name
UNION ALL
SELECT 'fk_joma_113' AS expected_name
UNION ALL
SELECT 'fk_joma_114' AS expected_name
UNION ALL
SELECT 'fk_joma_115' AS expected_name
UNION ALL
SELECT 'fk_joma_116' AS expected_name
UNION ALL
SELECT 'fk_joma_117' AS expected_name
UNION ALL
SELECT 'fk_joma_118' AS expected_name
UNION ALL
SELECT 'fk_joma_119' AS expected_name
UNION ALL
SELECT 'fk_joma_120' AS expected_name
UNION ALL
SELECT 'fk_joma_121' AS expected_name
UNION ALL
SELECT 'fk_joma_122' AS expected_name
UNION ALL
SELECT 'fk_joma_123' AS expected_name
UNION ALL
SELECT 'fk_joma_124' AS expected_name
UNION ALL
SELECT 'fk_joma_125' AS expected_name
UNION ALL
SELECT 'fk_joma_126' AS expected_name
UNION ALL
SELECT 'fk_joma_127' AS expected_name
UNION ALL
SELECT 'fk_joma_128' AS expected_name
UNION ALL
SELECT 'fk_joma_129' AS expected_name
UNION ALL
SELECT 'fk_joma_130' AS expected_name
UNION ALL
SELECT 'fk_joma_131' AS expected_name
UNION ALL
SELECT 'fk_joma_132' AS expected_name
UNION ALL
SELECT 'fk_joma_133' AS expected_name
UNION ALL
SELECT 'fk_joma_134' AS expected_name
UNION ALL
SELECT 'fk_joma_135' AS expected_name
UNION ALL
SELECT 'fk_joma_136' AS expected_name
UNION ALL
SELECT 'fk_joma_137' AS expected_name
UNION ALL
SELECT 'fk_joma_138' AS expected_name
UNION ALL
SELECT 'fk_joma_139' AS expected_name
UNION ALL
SELECT 'fk_joma_140' AS expected_name
UNION ALL
SELECT 'fk_joma_141' AS expected_name
UNION ALL
SELECT 'fk_joma_142' AS expected_name
UNION ALL
SELECT 'fk_joma_143' AS expected_name
UNION ALL
SELECT 'fk_joma_144' AS expected_name
UNION ALL
SELECT 'fk_joma_145' AS expected_name
UNION ALL
SELECT 'fk_joma_146' AS expected_name
UNION ALL
SELECT 'fk_joma_147' AS expected_name
UNION ALL
SELECT 'fk_joma_148' AS expected_name
UNION ALL
SELECT 'fk_joma_149' AS expected_name
UNION ALL
SELECT 'fk_joma_150' AS expected_name
UNION ALL
SELECT 'fk_joma_151' AS expected_name
UNION ALL
SELECT 'fk_joma_152' AS expected_name
UNION ALL
SELECT 'fk_joma_153' AS expected_name
UNION ALL
SELECT 'fk_joma_154' AS expected_name
UNION ALL
SELECT 'fk_joma_155' AS expected_name
UNION ALL
SELECT 'fk_joma_156' AS expected_name
UNION ALL
SELECT 'fk_joma_157' AS expected_name
UNION ALL
SELECT 'fk_joma_158' AS expected_name
UNION ALL
SELECT 'fk_joma_159' AS expected_name
UNION ALL
SELECT 'fk_joma_160' AS expected_name
UNION ALL
SELECT 'fk_joma_161' AS expected_name
UNION ALL
SELECT 'fk_joma_162' AS expected_name
UNION ALL
SELECT 'fk_joma_163' AS expected_name
UNION ALL
SELECT 'fk_joma_164' AS expected_name
UNION ALL
SELECT 'fk_joma_165' AS expected_name
UNION ALL
SELECT 'fk_joma_166' AS expected_name
UNION ALL
SELECT 'fk_joma_167' AS expected_name
UNION ALL
SELECT 'fk_joma_168' AS expected_name
UNION ALL
SELECT 'fk_joma_169' AS expected_name
UNION ALL
SELECT 'fk_joma_170' AS expected_name
UNION ALL
SELECT 'fk_joma_171' AS expected_name
UNION ALL
SELECT 'fk_joma_172' AS expected_name
UNION ALL
SELECT 'fk_joma_173' AS expected_name
) e WHERE NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS a WHERE a.CONSTRAINT_NAME=e.expected_name AND CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='FOREIGN KEY');

-- Missing explicit checks: expected ZERO rows.
SELECT e.expected_name FROM (
SELECT 'ck_role_definitions_1' AS expected_name
UNION ALL
SELECT 'ck_service_definitions_1' AS expected_name
UNION ALL
SELECT 'ck_payment_methods_1' AS expected_name
UNION ALL
SELECT 'ck_product_definitions_1' AS expected_name
UNION ALL
SELECT 'ck_persons_1' AS expected_name
UNION ALL
SELECT 'ck_persons_2' AS expected_name
UNION ALL
SELECT 'ck_accounts_1' AS expected_name
UNION ALL
SELECT 'ck_contact_points_1' AS expected_name
UNION ALL
SELECT 'ck_work_scopes_1' AS expected_name
UNION ALL
SELECT 'ck_work_scopes_2' AS expected_name
UNION ALL
SELECT 'ck_work_scopes_3' AS expected_name
UNION ALL
SELECT 'ck_work_scopes_4' AS expected_name
UNION ALL
SELECT 'ck_memberships_1' AS expected_name
UNION ALL
SELECT 'ck_memberships_2' AS expected_name
UNION ALL
SELECT 'ck_role_assignments_1' AS expected_name
UNION ALL
SELECT 'ck_command_receipts_1' AS expected_name
UNION ALL
SELECT 'ck_command_receipts_2' AS expected_name
UNION ALL
SELECT 'ck_service_policy_versions_1' AS expected_name
UNION ALL
SELECT 'ck_service_policy_versions_2' AS expected_name
UNION ALL
SELECT 'ck_service_policy_versions_3' AS expected_name
UNION ALL
SELECT 'ck_service_offerings_1' AS expected_name
UNION ALL
SELECT 'ck_service_offerings_2' AS expected_name
UNION ALL
SELECT 'ck_service_providers_1' AS expected_name
UNION ALL
SELECT 'ck_admissions_1' AS expected_name
UNION ALL
SELECT 'ck_admissions_2' AS expected_name
UNION ALL
SELECT 'ck_therapist_assignments_1' AS expected_name
UNION ALL
SELECT 'ck_responsibility_acceptances_1' AS expected_name
UNION ALL
SELECT 'ck_therapeutic_relationships_1' AS expected_name
UNION ALL
SELECT 'ck_clinical_cases_1' AS expected_name
UNION ALL
SELECT 'ck_case_participants_1' AS expected_name
UNION ALL
SELECT 'ck_case_operational_contexts_1' AS expected_name
UNION ALL
SELECT 'ck_representations_1' AS expected_name
UNION ALL
SELECT 'ck_representations_2' AS expected_name
UNION ALL
SELECT 'ck_representations_3' AS expected_name
UNION ALL
SELECT 'ck_representations_4' AS expected_name
UNION ALL
SELECT 'ck_administrative_delegations_1' AS expected_name
UNION ALL
SELECT 'ck_administrative_delegations_2' AS expected_name
UNION ALL
SELECT 'ck_administrative_delegations_3' AS expected_name
UNION ALL
SELECT 'ck_verification_authority_bases_1' AS expected_name
UNION ALL
SELECT 'ck_representation_verifications_1' AS expected_name
UNION ALL
SELECT 'ck_case_representation_restrictions_1' AS expected_name
UNION ALL
SELECT 'ck_appointment_requests_1' AS expected_name
UNION ALL
SELECT 'ck_appointment_requests_2' AS expected_name
UNION ALL
SELECT 'ck_appointment_requests_3' AS expected_name
UNION ALL
SELECT 'ck_schedule_resources_1' AS expected_name
UNION ALL
SELECT 'ck_capacity_holds_1' AS expected_name
UNION ALL
SELECT 'ck_capacity_holds_2' AS expected_name
UNION ALL
SELECT 'ck_capacity_holds_3' AS expected_name
UNION ALL
SELECT 'ck_capacity_holds_4' AS expected_name
UNION ALL
SELECT 'ck_hold_allocations_1' AS expected_name
UNION ALL
SELECT 'ck_appointments_1' AS expected_name
UNION ALL
SELECT 'ck_appointments_2' AS expected_name
UNION ALL
SELECT 'ck_appointments_3' AS expected_name
UNION ALL
SELECT 'ck_appointments_4' AS expected_name
UNION ALL
SELECT 'ck_appointment_allocations_1' AS expected_name
UNION ALL
SELECT 'ck_clinical_sessions_1' AS expected_name
UNION ALL
SELECT 'ck_form_templates_1' AS expected_name
UNION ALL
SELECT 'ck_form_versions_1' AS expected_name
UNION ALL
SELECT 'ck_form_versions_2' AS expected_name
UNION ALL
SELECT 'ck_form_versions_3' AS expected_name
UNION ALL
SELECT 'ck_form_versions_4' AS expected_name
UNION ALL
SELECT 'ck_form_approvals_1' AS expected_name
UNION ALL
SELECT 'ck_service_form_requirements_1' AS expected_name
UNION ALL
SELECT 'ck_form_instances_1' AS expected_name
UNION ALL
SELECT 'ck_form_instances_2' AS expected_name
UNION ALL
SELECT 'ck_form_submission_revisions_1' AS expected_name
UNION ALL
SELECT 'ck_form_submission_revisions_2' AS expected_name
UNION ALL
SELECT 'ck_form_submission_revisions_3' AS expected_name
UNION ALL
SELECT 'ck_form_submission_revisions_4' AS expected_name
UNION ALL
SELECT 'ck_assessment_assignments_1' AS expected_name
UNION ALL
SELECT 'ck_protected_files_1' AS expected_name
UNION ALL
SELECT 'ck_protected_files_2' AS expected_name
UNION ALL
SELECT 'ck_report_versions_1' AS expected_name
UNION ALL
SELECT 'ck_report_versions_2' AS expected_name
UNION ALL
SELECT 'ck_report_versions_3' AS expected_name
UNION ALL
SELECT 'ck_report_publications_1' AS expected_name
UNION ALL
SELECT 'ck_form_publication_audiences_1' AS expected_name
UNION ALL
SELECT 'ck_report_publication_audiences_1' AS expected_name
UNION ALL
SELECT 'ck_private_note_references_1' AS expected_name
UNION ALL
SELECT 'ck_destination_accounts_1' AS expected_name
UNION ALL
SELECT 'ck_payment_receipts_1' AS expected_name
UNION ALL
SELECT 'ck_payment_receipts_2' AS expected_name
UNION ALL
SELECT 'ck_payment_receipts_3' AS expected_name
UNION ALL
SELECT 'ck_payment_receipts_4' AS expected_name
UNION ALL
SELECT 'ck_product_entitlements_1' AS expected_name
UNION ALL
SELECT 'ck_config_versions_1' AS expected_name
UNION ALL
SELECT 'ck_audit_entries_1' AS expected_name
UNION ALL
SELECT 'ck_audit_entries_2' AS expected_name
UNION ALL
SELECT 'ck_notification_intents_1' AS expected_name
UNION ALL
SELECT 'ck_notification_attempts_1' AS expected_name
) e WHERE NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS a WHERE a.CONSTRAINT_NAME=e.expected_name AND CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='CHECK');

-- MariaDB JSON columns normally appear as LONGTEXT / utf8mb4_bin. This is expected.
SHOW CREATE TABLE joma_form_versions;
SHOW CREATE TABLE joma_form_submission_revisions;
SHOW CREATE TABLE joma_accounts;
SHOW CREATE TABLE joma_clinical_sessions;
