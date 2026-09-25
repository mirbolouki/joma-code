#!/usr/bin/env python3
"""Generate review-only JOMA MySQL 8.0.16+ DDL. Never connects to a database."""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
TABLES = {}
FKS = []
B = 'BINARY(16)'
TS = 'created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)'

def table(name, fields, *, unique=(), indexes=(), checks=(), note=''):
    cols = [x.strip() for x in fields.strip().splitlines() if x.strip()]
    cols.append(TS)
    TABLES[name] = dict(cols=cols, unique=list(unique), indexes=list(indexes), checks=list(checks), note=note)

def fk(source, columns, target, references=('id',)):
    if isinstance(columns, str): columns=(columns,)
    FKS.append((source, tuple(columns), target, tuple(references)))

def lookup(name, extra=''):
    table(name, f'''id INT UNSIGNED NOT NULL AUTO_INCREMENT
code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
label VARCHAR(191) NOT NULL
is_active BOOLEAN NOT NULL DEFAULT TRUE
{extra}''', unique=[('code',)], checks=['is_active IN (0,1)'])

lookup('role_definitions')
lookup('service_definitions')
lookup('payment_methods', 'is_bank_method BOOLEAN NOT NULL DEFAULT FALSE')
lookup('product_definitions')

table('persons', '''id BINARY(16) NOT NULL
given_name VARCHAR(120) NULL
family_name VARCHAR(120) NULL
birth_date DATE NULL
sex_code VARCHAR(32) NULL
status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ACTIVE' ''', checks=["status IN ('ACTIVE','INACTIVE')", "given_name IS NOT NULL OR family_name IS NOT NULL"], note='No required phone, account, or national identifier. No automatic identity merging.')
table('accounts', '''id BINARY(16) NOT NULL
person_id BINARY(16) NOT NULL
login_name VARCHAR(191) NOT NULL
password_hash VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
active_person_id BINARY(16) GENERATED ALWAYS AS (CASE WHEN status = 'ACTIVE' THEN person_id ELSE NULL END) STORED''', unique=[('login_name',),('active_person_id',),('id','person_id')], checks=["status IN ('ACTIVE','INACTIVE','LOCKED')"], note='At most one ACTIVE or LOCKED account per Person; inactive history is retained. Phone is not the Person key.')
# Locked existing login must not allow another simultaneously live account.
TABLES['accounts']['cols'][5]="active_person_id BINARY(16) GENERATED ALWAYS AS (CASE WHEN status IN ('ACTIVE','LOCKED') THEN person_id ELSE NULL END) STORED"
fk('accounts','person_id','persons')
table('contact_points', '''id BINARY(16) NOT NULL
kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
normalized_value VARCHAR(255) NOT NULL''', unique=[('kind','normalized_value')], checks=["kind IN ('PHONE','EMAIL','OTHER')"])
table('person_contacts', '''id BINARY(16) NOT NULL
person_id BINARY(16) NOT NULL
contact_id BINARY(16) NOT NULL
relationship_label VARCHAR(80) NULL
verified_at DATETIME(6) NULL''', unique=[('person_id','contact_id')]);fk('person_contacts','person_id','persons');fk('person_contacts','contact_id','contact_points')
table('work_scopes', '''id BINARY(16) NOT NULL
kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
parent_scope_id BINARY(16) NULL
independent_practitioner_id BINARY(16) NULL
label VARCHAR(191) NOT NULL
status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL''', checks=["kind IN ('CENTER','BRANCH','INDEPENDENT')", "status IN ('ACTIVE','INACTIVE')", "parent_scope_id IS NULL OR parent_scope_id <> id", "(kind = 'INDEPENDENT' AND independent_practitioner_id IS NOT NULL AND parent_scope_id IS NULL) OR (kind <> 'INDEPENDENT' AND independent_practitioner_id IS NULL)"], note='Cycles and valid parent kind require command validation; no implicit scope inheritance.');fk('work_scopes','parent_scope_id','work_scopes');fk('work_scopes','independent_practitioner_id','persons')
table('memberships', '''id BINARY(16) NOT NULL
person_id BINARY(16) NOT NULL
scope_id BINARY(16) NOT NULL
status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
valid_from DATETIME(6) NOT NULL
valid_until DATETIME(6) NULL''', unique=[('person_id','scope_id'),('id','person_id','scope_id')], checks=["status IN ('ACTIVE','INACTIVE','ENDED')",'valid_until IS NULL OR valid_until > valid_from']);fk('memberships','person_id','persons');fk('memberships','scope_id','work_scopes')
table('role_assignments', '''id BINARY(16) NOT NULL
account_id BINARY(16) NOT NULL
person_id BINARY(16) NOT NULL
membership_id BINARY(16) NOT NULL
scope_id BINARY(16) NOT NULL
role_id INT UNSIGNED NOT NULL
valid_from DATETIME(6) NOT NULL
valid_until DATETIME(6) NULL
revoked_at DATETIME(6) NULL''', unique=[('id','person_id','scope_id')], checks=['valid_until IS NULL OR valid_until > valid_from']);fk('role_assignments',('account_id','person_id'),'accounts',('id','person_id'));fk('role_assignments',('membership_id','person_id','scope_id'),'memberships',('id','person_id','scope_id'));fk('role_assignments','role_id','role_definitions')
table('professional_profiles', '''id BINARY(16) NOT NULL
person_id BINARY(16) NOT NULL
professional_type VARCHAR(100) NOT NULL
specialties_json JSON NULL''');fk('professional_profiles','person_id','persons')
table('professional_credentials', '''id BINARY(16) NOT NULL
profile_id BINARY(16) NOT NULL
credential_type VARCHAR(100) NOT NULL
issuer VARCHAR(191) NULL
credential_reference VARCHAR(191) NULL
valid_until DATE NULL''');fk('professional_credentials','profile_id','professional_profiles')

table('command_receipts', '''id BINARY(16) NOT NULL
scope_id BINARY(16) NOT NULL
actor_person_id BINARY(16) NULL
system_actor_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
command_name VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
idempotency_key BINARY(32) NOT NULL
payload_hash BINARY(32) NOT NULL
status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
result_reference_json JSON NULL
completed_at DATETIME(6) NULL''', unique=[('scope_id','idempotency_key')], checks=["status IN ('PROCESSING','SUCCEEDED','REJECTED')", '(actor_person_id IS NOT NULL AND system_actor_code IS NULL) OR (actor_person_id IS NULL AND system_actor_code IS NOT NULL)'], note='Never return a previous result without rechecking current authorization. No clinical payload in result_reference_json.');fk('command_receipts','scope_id','work_scopes');fk('command_receipts','actor_person_id','persons')

table('service_policy_versions', '''id BINARY(16) NOT NULL
service_id INT UNSIGNED NOT NULL
version_no INT UNSIGNED NOT NULL
status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
policy_json JSON NOT NULL
author_person_id BINARY(16) NOT NULL
approved_by_person_id BINARY(16) NULL
effective_at DATETIME(6) NULL''', unique=[('service_id','version_no'),('id','service_id')], checks=['version_no > 0',"status IN ('DRAFT','PUBLISHED','RETIRED')", "status = 'DRAFT' OR (approved_by_person_id IS NOT NULL AND effective_at IS NOT NULL)"], note='Published contents are immutable by command contract, not by this CHECK.');fk('service_policy_versions','service_id','service_definitions');fk('service_policy_versions','author_person_id','persons');fk('service_policy_versions','approved_by_person_id','persons')
table('service_offerings', '''id BINARY(16) NOT NULL
scope_id BINARY(16) NOT NULL
service_id INT UNSIGNED NOT NULL
policy_version_id BINARY(16) NOT NULL
duration_minutes SMALLINT UNSIGNED NOT NULL
status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL''',unique=[('id','scope_id')],checks=['duration_minutes > 0',"status IN ('ACTIVE','INACTIVE')"]);fk('service_offerings','scope_id','work_scopes');fk('service_offerings',('policy_version_id','service_id'),'service_policy_versions',('id','service_id'))
table('service_providers', '''id BINARY(16) NOT NULL
offering_id BINARY(16) NOT NULL
scope_id BINARY(16) NOT NULL
membership_id BINARY(16) NOT NULL
person_id BINARY(16) NOT NULL
valid_from DATETIME(6) NOT NULL
valid_until DATETIME(6) NULL''', checks=['valid_until IS NULL OR valid_until > valid_from']);fk('service_providers',('offering_id','scope_id'),'service_offerings',('id','scope_id'));fk('service_providers',('membership_id','person_id','scope_id'),'memberships',('id','person_id','scope_id'))

table('admissions', '''id BINARY(16) NOT NULL
primary_subject_id BINARY(16) NOT NULL
scope_id BINARY(16) NOT NULL
offering_id BINARY(16) NOT NULL
recorded_by_person_id BINARY(16) NOT NULL
case_id BINARY(16) NULL
status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL''', indexes=[('scope_id','status','created_at')], checks=["status IN ('DRAFT','SUBMITTED','NEEDS_INFO','READY_FOR_ASSIGNMENT','AWAITING_THERAPIST','LINKED_TO_CASE','WITHDRAWN','DECLINED')", "status <> 'LINKED_TO_CASE' OR case_id IS NOT NULL"]);fk('admissions','primary_subject_id','persons');fk('admissions','recorded_by_person_id','persons');fk('admissions',('offering_id','scope_id'),'service_offerings',('id','scope_id'))
table('admission_participants', '''id BINARY(16) NOT NULL
admission_id BINARY(16) NOT NULL
person_id BINARY(16) NOT NULL
participation_role VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL''',unique=[('admission_id','person_id','participation_role')]);fk('admission_participants','admission_id','admissions');fk('admission_participants','person_id','persons')
table('therapist_assignments', '''id BINARY(16) NOT NULL
admission_id BINARY(16) NOT NULL
therapist_person_id BINARY(16) NOT NULL
assigned_by_person_id BINARY(16) NOT NULL
status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
open_admission_id BINARY(16) GENERATED ALWAYS AS (CASE WHEN status = 'ASSIGNED' THEN admission_id ELSE NULL END) STORED''',unique=[('open_admission_id',),('id','admission_id','therapist_person_id')],checks=["status IN ('ASSIGNED','ACCEPTED','DECLINED','CANCELLED')"]);fk('therapist_assignments','admission_id','admissions');fk('therapist_assignments','therapist_person_id','persons');fk('therapist_assignments','assigned_by_person_id','persons')
table('responsibility_acceptances', '''id BINARY(16) NOT NULL
admission_id BINARY(16) NOT NULL
assignment_id BINARY(16) NULL
therapist_person_id BINARY(16) NOT NULL
actor_person_id BINARY(16) NOT NULL
command_id BINARY(16) NOT NULL
accepted_at DATETIME(6) NOT NULL''',unique=[('assignment_id',),('command_id',),('id','therapist_person_id')],checks=['actor_person_id = therapist_person_id']);fk('responsibility_acceptances','admission_id','admissions');fk('responsibility_acceptances',('assignment_id','admission_id','therapist_person_id'),'therapist_assignments',('id','admission_id','therapist_person_id'));fk('responsibility_acceptances','therapist_person_id','persons');fk('responsibility_acceptances','command_id','command_receipts')
table('therapeutic_relationships', '''id BINARY(16) NOT NULL
acceptance_id BINARY(16) NOT NULL
therapist_person_id BINARY(16) NOT NULL
status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
activated_at DATETIME(6) NOT NULL
ended_at DATETIME(6) NULL''',unique=[('acceptance_id',),('id','therapist_person_id')],checks=["(status = 'ACTIVE' AND ended_at IS NULL) OR (status = 'ENDED' AND ended_at IS NOT NULL AND ended_at >= activated_at)"]);fk('therapeutic_relationships',('acceptance_id','therapist_person_id'),'responsibility_acceptances',('id','therapist_person_id'))
table('clinical_cases', '''id BINARY(16) NOT NULL
relationship_id BINARY(16) NOT NULL
responsible_therapist_id BINARY(16) NOT NULL
purpose_summary VARCHAR(500) NOT NULL
status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
opened_at DATETIME(6) NOT NULL
closed_at DATETIME(6) NULL''',unique=[('relationship_id',),('id','responsible_therapist_id')],checks=["(status = 'ACTIVE' AND closed_at IS NULL) OR (status = 'CLOSED' AND closed_at IS NOT NULL AND closed_at >= opened_at)"],note='Relationship/Case status coherence and mandatory creation of BOTH are enforced by atomic commands; FK cannot guarantee the reverse 1:1.');fk('clinical_cases',('relationship_id','responsible_therapist_id'),'therapeutic_relationships',('id','therapist_person_id'));fk('admissions','case_id','clinical_cases')
table('case_participants', '''id BINARY(16) NOT NULL
case_id BINARY(16) NOT NULL
person_id BINARY(16) NOT NULL
clinical_role VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
valid_from DATETIME(6) NOT NULL
valid_until DATETIME(6) NULL''',checks=['valid_until IS NULL OR valid_until > valid_from']);fk('case_participants','case_id','clinical_cases');fk('case_participants','person_id','persons')
table('case_operational_contexts', '''id BINARY(16) NOT NULL
case_id BINARY(16) NOT NULL
scope_id BINARY(16) NOT NULL
valid_from DATETIME(6) NOT NULL
valid_until DATETIME(6) NULL
basis_reference VARCHAR(191) NOT NULL''',unique=[('id','case_id','scope_id')],checks=['valid_until IS NULL OR valid_until > valid_from'],note='Historical scope link is not a current clinical grant.');fk('case_operational_contexts','case_id','clinical_cases');fk('case_operational_contexts','scope_id','work_scopes')

table('representations', '''id BINARY(16) NOT NULL
representative_person_id BINARY(16) NOT NULL
subject_person_id BINARY(16) NOT NULL
scope_id BINARY(16) NOT NULL
status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
allowed_actions_json JSON NOT NULL
valid_from DATETIME(6) NULL
valid_until DATETIME(6) NULL
revoked_at DATETIME(6) NULL''',unique=[('id','representative_person_id','subject_person_id','scope_id')],checks=['representative_person_id <> subject_person_id',"status IN ('PENDING_VERIFICATION','VERIFIED','REVOKED')",'valid_until IS NULL OR valid_from IS NULL OR valid_until > valid_from',"(status = 'REVOKED' AND revoked_at IS NOT NULL) OR (status <> 'REVOKED' AND revoked_at IS NULL)"],note='VERIFIED is administrative evidence, not universal legal authority. Allowed action codes are server allowlisted.');fk('representations','representative_person_id','persons');fk('representations','subject_person_id','persons');fk('representations','scope_id','work_scopes')
table('administrative_delegations', '''id BINARY(16) NOT NULL
grantor_role_assignment_id BINARY(16) NOT NULL
grantor_person_id BINARY(16) NOT NULL
grantee_membership_id BINARY(16) NOT NULL
grantee_person_id BINARY(16) NOT NULL
scope_id BINARY(16) NOT NULL
kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
target_admission_id BINARY(16) NULL
target_representation_id BINARY(16) NULL
allowed_actions_json JSON NOT NULL
valid_from DATETIME(6) NULL
valid_until DATETIME(6) NULL
revoked_at DATETIME(6) NULL''',unique=[('id','grantee_person_id','scope_id')],checks=["kind IN ('STANDING','SPECIFIC')", "(kind = 'STANDING' AND target_admission_id IS NULL AND target_representation_id IS NULL) OR (kind = 'SPECIFIC' AND ((target_admission_id IS NOT NULL AND target_representation_id IS NULL) OR (target_admission_id IS NULL AND target_representation_id IS NOT NULL)))",'valid_until IS NULL OR valid_from IS NULL OR valid_until > valid_from']);fk('administrative_delegations',('grantor_role_assignment_id','grantor_person_id','scope_id'),'role_assignments',('id','person_id','scope_id'));fk('administrative_delegations',('grantee_membership_id','grantee_person_id','scope_id'),'memberships',('id','person_id','scope_id'));fk('administrative_delegations','target_admission_id','admissions');fk('administrative_delegations','target_representation_id','representations')
table('verification_authority_bases', '''id BINARY(16) NOT NULL
actor_person_id BINARY(16) NOT NULL
scope_id BINARY(16) NOT NULL
direct_role_assignment_id BINARY(16) NULL
delegation_id BINARY(16) NULL''',unique=[('id','actor_person_id','scope_id')],checks=['(direct_role_assignment_id IS NOT NULL AND delegation_id IS NULL) OR (direct_role_assignment_id IS NULL AND delegation_id IS NOT NULL)']);fk('verification_authority_bases',('direct_role_assignment_id','actor_person_id','scope_id'),'role_assignments',('id','person_id','scope_id'));fk('verification_authority_bases',('delegation_id','actor_person_id','scope_id'),'administrative_delegations',('id','grantee_person_id','scope_id'))
table('representation_verifications', '''id BINARY(16) NOT NULL
representation_id BINARY(16) NOT NULL
representative_person_id BINARY(16) NOT NULL
subject_person_id BINARY(16) NOT NULL
scope_id BINARY(16) NOT NULL
authority_basis_id BINARY(16) NOT NULL
actor_person_id BINARY(16) NOT NULL
evidence_type VARCHAR(64) NOT NULL
policy_reference VARCHAR(191) NOT NULL
result VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
checked_at DATETIME(6) NOT NULL''',checks=["result IN ('VERIFIED','NEEDS_REVIEW','REJECTED')"]);fk('representation_verifications',('representation_id','representative_person_id','subject_person_id','scope_id'),'representations',('id','representative_person_id','subject_person_id','scope_id'));fk('representation_verifications',('authority_basis_id','actor_person_id','scope_id'),'verification_authority_bases',('id','actor_person_id','scope_id'))
table('representation_events', '''id BINARY(16) NOT NULL
representation_id BINARY(16) NOT NULL
command_id BINARY(16) NOT NULL
actor_person_id BINARY(16) NOT NULL
scope_id BINARY(16) NOT NULL
event_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
reason_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL''');fk('representation_events','representation_id','representations');fk('representation_events','command_id','command_receipts');fk('representation_events','actor_person_id','persons');fk('representation_events','scope_id','work_scopes')
table('case_representation_restrictions', '''id BINARY(16) NOT NULL
representation_id BINARY(16) NOT NULL
case_id BINARY(16) NOT NULL
clinician_person_id BINARY(16) NOT NULL
service_id INT UNSIGNED NULL
command_id BINARY(16) NOT NULL
suspended_at DATETIME(6) NOT NULL
status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'SUSPENDED'
reason_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL''',unique=[('command_id',)],checks=["status = 'SUSPENDED'"],note='No restore/expiry command is implied. Sensitive clinical reason belongs in an authorized protected source, never this audit reason code.');fk('case_representation_restrictions','representation_id','representations');fk('case_representation_restrictions',('case_id','clinician_person_id'),'clinical_cases',('id','responsible_therapist_id'));fk('case_representation_restrictions','service_id','service_definitions');fk('case_representation_restrictions','command_id','command_receipts')

table('appointment_requests', '''id BINARY(16) NOT NULL
subject_person_id BINARY(16) NOT NULL
admission_id BINARY(16) NULL
case_id BINARY(16) NULL
offering_id BINARY(16) NOT NULL
preferred_start_at DATETIME(6) NULL
status VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
fulfilled_appointment_id BINARY(16) NULL''',unique=[('fulfilled_appointment_id',)],checks=['admission_id IS NOT NULL OR case_id IS NOT NULL',"status IN ('REQUESTED','CHANGE_PROPOSED','FULFILLED','DECLINED','WITHDRAWN')","(status = 'FULFILLED' AND fulfilled_appointment_id IS NOT NULL) OR (status <> 'FULFILLED' AND fulfilled_appointment_id IS NULL)"]);fk('appointment_requests','subject_person_id','persons');fk('appointment_requests','admission_id','admissions');fk('appointment_requests','case_id','clinical_cases');fk('appointment_requests','offering_id','service_offerings')
table('schedule_resources', '''id BINARY(16) NOT NULL
scope_id BINARY(16) NULL
therapist_person_id BINARY(16) NULL
kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
label VARCHAR(191) NOT NULL''',unique=[('therapist_person_id',)],checks=["(kind = 'THERAPIST' AND therapist_person_id IS NOT NULL) OR (kind IN ('ROOM','OTHER') AND therapist_person_id IS NULL AND scope_id IS NOT NULL)"],note='V1 exclusive capacity per resource. Global therapist resource prevents cross-location double booking; never grants cross-scope visibility. Resource row is the transaction mutex.');fk('schedule_resources','scope_id','work_scopes');fk('schedule_resources','therapist_person_id','persons')
table('capacity_holds', '''id BINARY(16) NOT NULL
case_id BINARY(16) NOT NULL
offering_id BINARY(16) NOT NULL
policy_version_id BINARY(16) NOT NULL
request_id BINARY(16) NULL
actor_person_id BINARY(16) NOT NULL
starts_at DATETIME(6) NOT NULL
ends_at DATETIME(6) NOT NULL
held_at DATETIME(6) NOT NULL
expires_at DATETIME(6) NOT NULL
status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
command_id BINARY(16) NOT NULL''',unique=[('command_id',),('id','case_id')],indexes=[('status','expires_at')],checks=['ends_at > starts_at','expires_at > held_at','expires_at <= held_at + INTERVAL 15 MINUTE',"status IN ('HELD','CONSUMED','EXPIRED','RELEASED')"],note='15 minute approved V1 upper bound; increasing it requires a schema/policy revision, not a hidden feature flag. Admission acceptance and policy/offering coherence require command validation.');fk('capacity_holds','case_id','clinical_cases');fk('capacity_holds','offering_id','service_offerings');fk('capacity_holds','policy_version_id','service_policy_versions');fk('capacity_holds','request_id','appointment_requests');fk('capacity_holds','actor_person_id','persons');fk('capacity_holds','command_id','command_receipts')
table('hold_allocations', '''id BINARY(16) NOT NULL
hold_id BINARY(16) NOT NULL
resource_id BINARY(16) NOT NULL
starts_at DATETIME(6) NOT NULL
ends_at DATETIME(6) NOT NULL''',unique=[('hold_id','resource_id')],indexes=[('resource_id','starts_at','ends_at','hold_id')],checks=['ends_at > starts_at']);fk('hold_allocations','hold_id','capacity_holds');fk('hold_allocations','resource_id','schedule_resources')
table('appointments', '''id BINARY(16) NOT NULL
case_id BINARY(16) NOT NULL
therapist_person_id BINARY(16) NOT NULL
scope_id BINARY(16) NOT NULL
offering_id BINARY(16) NOT NULL
hold_id BINARY(16) NULL
rebooked_from_appointment_id BINARY(16) NULL
starts_at DATETIME(6) NOT NULL
ends_at DATETIME(6) NOT NULL
status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
origin VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
cancellation_notice_minutes INT UNSIGNED NOT NULL
command_id BINARY(16) NOT NULL''',unique=[('hold_id',),('command_id',),('id','case_id','therapist_person_id')],indexes=[('scope_id','starts_at','status'),('therapist_person_id','starts_at','ends_at')],checks=['ends_at > starts_at',"status IN ('CONFIRMED','CANCELLED','COMPLETED','NO_SHOW')","origin IN ('CLIENT_REQUEST','STAFF_MANUAL','PRACTITIONER_DIRECT')",'rebooked_from_appointment_id IS NULL OR rebooked_from_appointment_id <> id'],note='cancellation_notice_minutes has no implicit default: capture approved policy when confirming. Overlap is NOT prevented by these indexes.');fk('appointments',('case_id','therapist_person_id'),'clinical_cases',('id','responsible_therapist_id'));fk('appointments',('offering_id','scope_id'),'service_offerings',('id','scope_id'));fk('appointments',('hold_id','case_id'),'capacity_holds',('id','case_id'));fk('appointments','rebooked_from_appointment_id','appointments');fk('appointments','command_id','command_receipts');fk('appointment_requests','fulfilled_appointment_id','appointments')
table('appointment_allocations', '''id BINARY(16) NOT NULL
appointment_id BINARY(16) NOT NULL
resource_id BINARY(16) NOT NULL
starts_at DATETIME(6) NOT NULL
ends_at DATETIME(6) NOT NULL''',unique=[('appointment_id','resource_id')],indexes=[('resource_id','starts_at','ends_at','appointment_id')],checks=['ends_at > starts_at']);fk('appointment_allocations','appointment_id','appointments');fk('appointment_allocations','resource_id','schedule_resources')
table('appointment_changes', '''id BINARY(16) NOT NULL
appointment_id BINARY(16) NOT NULL
command_id BINARY(16) NOT NULL
actor_person_id BINARY(16) NOT NULL
change_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
before_json JSON NOT NULL
after_json JSON NOT NULL
reason_code VARCHAR(64) NOT NULL''',unique=[('command_id',)],note='Operational history only; no session narrative/private notes in JSON.');fk('appointment_changes','appointment_id','appointments');fk('appointment_changes','command_id','command_receipts');fk('appointment_changes','actor_person_id','persons')
table('attendance_observations', '''id BINARY(16) NOT NULL
appointment_id BINARY(16) NOT NULL
observed_person_id BINARY(16) NOT NULL
recorded_by_person_id BINARY(16) NOT NULL
observed_at DATETIME(6) NOT NULL
observation_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL''');fk('attendance_observations','appointment_id','appointments');fk('attendance_observations','observed_person_id','persons');fk('attendance_observations','recorded_by_person_id','persons')
table('clinical_sessions', '''id BINARY(16) NOT NULL
case_id BINARY(16) NOT NULL
clinician_person_id BINARY(16) NOT NULL
appointment_id BINARY(16) NULL
started_at DATETIME(6) NOT NULL
ended_at DATETIME(6) NULL
command_id BINARY(16) NOT NULL''',unique=[('appointment_id',),('command_id',)],checks=['ended_at IS NULL OR ended_at >= started_at'],note='UNIQUE nullable appointment_id enforces max one session per appointment and allows multiple unrelated unbooked sessions. Unbooked service does not waive consent, authority or financial prerequisite policies.');fk('clinical_sessions',('case_id','clinician_person_id'),'clinical_cases',('id','responsible_therapist_id'));fk('clinical_sessions',('appointment_id','case_id','clinician_person_id'),'appointments',('id','case_id','therapist_person_id'));fk('clinical_sessions','command_id','command_receipts')

# The public form-response store deliberately excludes PRIVATE_NOTE.
table('form_templates', '''id BINARY(16) NOT NULL
scope_id BINARY(16) NOT NULL
label VARCHAR(191) NOT NULL
form_kind VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL''',checks=["form_kind IN ('ADMINISTRATIVE','CLINICAL','CONSENT')"],note='Raw psychometric protocols remain in the assessment subsystem, not a portal-publishable general form. Private notes never use this plaintext response store.');fk('form_templates','scope_id','work_scopes')
table('form_versions', '''id BINARY(16) NOT NULL
template_id BINARY(16) NOT NULL
version_no INT UNSIGNED NOT NULL
schema_json JSON NOT NULL
status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
author_person_id BINARY(16) NOT NULL
published_at DATETIME(6) NULL''',unique=[('template_id','version_no')],checks=['version_no > 0',"status IN ('DRAFT','PUBLISHED','RETIRED')","status = 'DRAFT' OR published_at IS NOT NULL","JSON_TYPE(schema_json) = 'OBJECT'"]);fk('form_versions','template_id','form_templates');fk('form_versions','author_person_id','persons')
table('form_approvals', '''id BINARY(16) NOT NULL
form_version_id BINARY(16) NOT NULL
approver_person_id BINARY(16) NOT NULL
role_assignment_id BINARY(16) NOT NULL
scope_id BINARY(16) NOT NULL
definition_hash BINARY(32) NOT NULL
decision VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
reviewed_at DATETIME(6) NOT NULL''',checks=["decision IN ('APPROVED','REJECTED')"]);fk('form_approvals','form_version_id','form_versions');fk('form_approvals',('role_assignment_id','approver_person_id','scope_id'),'role_assignments',('id','person_id','scope_id'))
table('service_form_requirements', '''id BINARY(16) NOT NULL
policy_version_id BINARY(16) NOT NULL
form_version_id BINARY(16) NOT NULL
stage_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
respondent_role_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
is_required BOOLEAN NOT NULL''',checks=['is_required IN (0,1)']);fk('service_form_requirements','policy_version_id','service_policy_versions');fk('service_form_requirements','form_version_id','form_versions')
table('service_prerequisites', '''id BINARY(16) NOT NULL
policy_version_id BINARY(16) NOT NULL
action_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
prerequisite_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
parameters_json JSON NOT NULL''',unique=[('policy_version_id','action_code','prerequisite_code')],note='Allowlisted semantic rules, not executable scripts. Unknown policy never means ALLOW.');fk('service_prerequisites','policy_version_id','service_policy_versions')
table('form_instances', '''id BINARY(16) NOT NULL
form_version_id BINARY(16) NOT NULL
scope_id BINARY(16) NOT NULL
admission_id BINARY(16) NULL
case_id BINARY(16) NULL
appointment_id BINARY(16) NULL
designated_respondent_id BINARY(16) NULL
status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL''',unique=[('id','case_id')],checks=['admission_id IS NOT NULL OR case_id IS NOT NULL OR appointment_id IS NOT NULL',"status IN ('DRAFT','SUBMITTED','WITHDRAWN')"],note='Validate consistency of all non-null contexts and FormSubjects at submit time. No anonymous/public access implied.');fk('form_instances','form_version_id','form_versions');fk('form_instances','scope_id','work_scopes');fk('form_instances','admission_id','admissions');fk('form_instances','case_id','clinical_cases');fk('form_instances','appointment_id','appointments');fk('form_instances','designated_respondent_id','persons')
table('form_subjects', '''id BINARY(16) NOT NULL
instance_id BINARY(16) NOT NULL
subject_person_id BINARY(16) NOT NULL''',unique=[('instance_id','subject_person_id')]);fk('form_subjects','instance_id','form_instances');fk('form_subjects','subject_person_id','persons')
table('form_submission_revisions', '''id BINARY(16) NOT NULL
instance_id BINARY(16) NOT NULL
revision_no INT UNSIGNED NOT NULL
respondent_person_id BINARY(16) NOT NULL
recorded_by_person_id BINARY(16) NOT NULL
representation_id BINARY(16) NULL
answers_json JSON NOT NULL
state VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
submitted_at DATETIME(6) NULL
command_id BINARY(16) NOT NULL''',unique=[('instance_id','revision_no'),('command_id',),('id','instance_id')],checks=['revision_no > 0',"state IN ('DRAFT','SUBMITTED','CORRECTION')","(state = 'DRAFT' AND submitted_at IS NULL) OR (state IN ('SUBMITTED','CORRECTION') AND submitted_at IS NOT NULL)","JSON_TYPE(answers_json) = 'OBJECT'"],note='DRAFT access only within the explicit recorder/responsible clinician rule, with current authority. Representation scope, raw-test exclusion, private-note exclusion and schema validation are server guards.');fk('form_submission_revisions','instance_id','form_instances');fk('form_submission_revisions','respondent_person_id','persons');fk('form_submission_revisions','recorded_by_person_id','persons');fk('form_submission_revisions','representation_id','representations');fk('form_submission_revisions','command_id','command_receipts')
table('consent_evidence', '''id BINARY(16) NOT NULL
submission_revision_id BINARY(16) NOT NULL
subject_person_id BINARY(16) NOT NULL
consenting_person_id BINARY(16) NOT NULL
representation_id BINARY(16) NULL
statement_hash BINARY(32) NOT NULL
accepted_at DATETIME(6) NOT NULL''');fk('consent_evidence','submission_revision_id','form_submission_revisions');fk('consent_evidence','subject_person_id','persons');fk('consent_evidence','consenting_person_id','persons');fk('consent_evidence','representation_id','representations')
table('form_publications', '''id BINARY(16) NOT NULL
submission_revision_id BINARY(16) NOT NULL
instance_id BINARY(16) NOT NULL
case_id BINARY(16) NOT NULL
published_by_person_id BINARY(16) NOT NULL
published_at DATETIME(6) NOT NULL
revoked_at DATETIME(6) NULL
command_id BINARY(16) NOT NULL''',unique=[('command_id',)],note='Clinical/joint form sharing uses typed PublicationAudience pattern, never a polymorphic resource_id. Pre-case forms cannot be portal-published through this table until a valid case/authority exists. Exact publisher authority remains a review gate; no broad admin permission.');fk('form_publications',('submission_revision_id','instance_id'),'form_submission_revisions',('id','instance_id'));fk('form_publications',('instance_id','case_id'),'form_instances',('id','case_id'));fk('form_publications','published_by_person_id','persons');fk('form_publications','command_id','command_receipts')

table('assessments', '''id BINARY(16) NOT NULL
case_id BINARY(16) NOT NULL
subject_person_id BINARY(16) NOT NULL
instrument_reference VARCHAR(191) NULL
status_code VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL''',unique=[('id','case_id')],note='Instrument engine/raw-response physical schema waits for the approved final test package.');fk('assessments','case_id','clinical_cases');fk('assessments','subject_person_id','persons')
table('assessment_respondents', '''id BINARY(16) NOT NULL
assessment_id BINARY(16) NOT NULL
respondent_person_id BINARY(16) NOT NULL
representation_id BINARY(16) NULL''',unique=[('assessment_id','respondent_person_id')]);fk('assessment_respondents','assessment_id','assessments');fk('assessment_respondents','respondent_person_id','persons');fk('assessment_respondents','representation_id','representations')
table('assessment_assignments', '''id BINARY(16) NOT NULL
assessment_id BINARY(16) NOT NULL
assessor_person_id BINARY(16) NOT NULL
authorized_by_person_id BINARY(16) NOT NULL
scope_id BINARY(16) NOT NULL
valid_from DATETIME(6) NOT NULL
valid_until DATETIME(6) NULL
revoked_at DATETIME(6) NULL''',checks=['valid_until IS NULL OR valid_until > valid_from'],note='Null valid_until is not automatic indefinite permission: assignment termination policy is still a release gate.');fk('assessment_assignments','assessment_id','assessments');fk('assessment_assignments','assessor_person_id','persons');fk('assessment_assignments','authorized_by_person_id','persons');fk('assessment_assignments','scope_id','work_scopes')
table('protected_files', '''id BINARY(16) NOT NULL
storage_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
original_name VARCHAR(255) NOT NULL
media_type VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
byte_length BIGINT UNSIGNED NOT NULL
sha256 BINARY(32) NOT NULL
uploaded_by_person_id BINARY(16) NOT NULL
state VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL''',unique=[('storage_key',)],checks=["state IN ('QUARANTINED','READY','REJECTED')",'byte_length > 0'],note='Stored outside public web access; MIME/name do not prove safe content. No private-note plaintext here.');fk('protected_files','uploaded_by_person_id','persons')
table('assessment_reports', '''id BINARY(16) NOT NULL
assessment_id BINARY(16) NOT NULL
case_id BINARY(16) NOT NULL''',unique=[('id','case_id')]);fk('assessment_reports',('assessment_id','case_id'),'assessments',('id','case_id'))
table('report_versions', '''id BINARY(16) NOT NULL
report_id BINARY(16) NOT NULL
case_id BINARY(16) NOT NULL
version_no INT UNSIGNED NOT NULL
author_person_id BINARY(16) NOT NULL
uploaded_by_person_id BINARY(16) NOT NULL
report_text MEDIUMTEXT NULL
protected_file_id BINARY(16) NULL
status VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL''',unique=[('report_id','version_no'),('id','case_id')],checks=['version_no > 0', '(report_text IS NOT NULL AND protected_file_id IS NULL) OR (report_text IS NULL AND protected_file_id IS NOT NULL)',"status IN ('DRAFT_CLINICIAN_REVIEW','REVIEWED','WITHDRAWN')"],note='Report text is shareable clinical content, NEVER a PrivateNote. Published versions are immutable by command contract.');fk('report_versions',('report_id','case_id'),'assessment_reports',('id','case_id'));fk('report_versions','author_person_id','persons');fk('report_versions','uploaded_by_person_id','persons');fk('report_versions','protected_file_id','protected_files')
table('report_publications', '''id BINARY(16) NOT NULL
report_version_id BINARY(16) NOT NULL
case_id BINARY(16) NOT NULL
published_by_person_id BINARY(16) NOT NULL
channel VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
published_at DATETIME(6) NOT NULL
revoked_at DATETIME(6) NULL
command_id BINARY(16) NOT NULL''',unique=[('command_id',)],checks=["channel IN ('PORTAL','IN_PERSON')"],note='IN_PERSON records authorized delivery; it never grants portal access.');fk('report_publications',('report_version_id','case_id'),'report_versions',('id','case_id'));fk('report_publications',('case_id','published_by_person_id'),'clinical_cases',('id','responsible_therapist_id'));fk('report_publications','command_id','command_receipts')
for parent, audience in [('form_publications','form_publication_audiences'),('report_publications','report_publication_audiences')]:
    table(audience, '''id BINARY(16) NOT NULL
publication_id BINARY(16) NOT NULL
recipient_person_id BINARY(16) NOT NULL
access_subject_person_id BINARY(16) NOT NULL
scope_id BINARY(16) NOT NULL
basis_kind VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
representation_id BINARY(16) NULL
revoked_at DATETIME(6) NULL''',unique=[('publication_id','recipient_person_id')],checks=["(basis_kind = 'DIRECT_PERSON' AND representation_id IS NULL AND recipient_person_id = access_subject_person_id) OR (basis_kind = 'REPRESENTATIVE' AND representation_id IS NOT NULL)"],note='Explicit recipient snapshot, not dynamic case membership. Additional legal bases require a reviewed policy/schema change; unknown basis fails closed.')
    fk(audience,'publication_id',parent);fk(audience,'recipient_person_id','persons');fk(audience,'access_subject_person_id','persons');fk(audience,'scope_id','work_scopes');fk(audience,('representation_id','recipient_person_id','access_subject_person_id','scope_id'),'representations',('id','representative_person_id','subject_person_id','scope_id'))

# No private-note payload schema is invented while crypto envelope/key recovery remain open.
table('private_note_references', '''id BINARY(16) NOT NULL
case_id BINARY(16) NOT NULL
author_person_id BINARY(16) NOT NULL
storage_mode VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
opaque_author_reference BINARY(32) NOT NULL''',unique=[('author_person_id','opaque_author_reference')],checks=["storage_mode IN ('WINDOWS_LOCAL','SERVER_CIPHERTEXT')"],note='Optional minimum metadata only, itself author-restricted. No body, answers_json, key, public file FK, audience or patient grant. Payload/transfer crypto tables intentionally deferred.');fk('private_note_references',('case_id','author_person_id'),'clinical_cases',('id','responsible_therapist_id'));fk('private_note_references','author_person_id','persons')
table('practitioner_messages', '''id BINARY(16) NOT NULL
case_id BINARY(16) NULL
sender_person_id BINARY(16) NOT NULL
recipient_person_id BINARY(16) NOT NULL
message_body MEDIUMTEXT NOT NULL
sent_at DATETIME(6) NOT NULL
command_id BINARY(16) NOT NULL''',unique=[('command_id',)],note='One-way protected messaging is distinct from client-encrypted private notes. No internal transfer/export implied.');fk('practitioner_messages','case_id','clinical_cases');fk('practitioner_messages','sender_person_id','persons');fk('practitioner_messages','recipient_person_id','persons');fk('practitioner_messages','command_id','command_receipts')

table('financial_spaces', '''id BINARY(16) NOT NULL
scope_id BINARY(16) NOT NULL
label VARCHAR(191) NOT NULL''');fk('financial_spaces','scope_id','work_scopes')
table('destination_accounts', '''id BINARY(16) NOT NULL
financial_space_id BINARY(16) NOT NULL
bank_reference VARCHAR(191) NOT NULL
label VARCHAR(191) NOT NULL
is_active BOOLEAN NOT NULL''',unique=[('id','financial_space_id')],checks=['is_active IN (0,1)'],note='Deactivate instead of silently changing the destination of historical receipts. Bank reference changes require audited command.');fk('destination_accounts','financial_space_id','financial_spaces')
table('payment_receipts', '''id BINARY(16) NOT NULL
financial_space_id BINARY(16) NOT NULL
payer_person_id BINARY(16) NOT NULL
method_id INT UNSIGNED NOT NULL
method_is_bank BOOLEAN NOT NULL
destination_account_id BINARY(16) NULL
amount DECIMAL(18,2) NOT NULL
currency_code CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
received_at DATETIME(6) NOT NULL
recorded_by_person_id BINARY(16) NOT NULL
provider_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
provider_transaction_reference VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL
command_id BINARY(16) NOT NULL''',unique=[('command_id',),('financial_space_id','provider_code','provider_transaction_reference')],checks=['amount > 0','method_is_bank IN (0,1)','method_is_bank = 0 OR destination_account_id IS NOT NULL','(provider_code IS NULL AND provider_transaction_reference IS NULL) OR (provider_code IS NOT NULL AND provider_transaction_reference IS NOT NULL)'],note='No assumed Rial/Toman conversion. Currency/unit must be explicitly agreed before using money. No refund, wallet, revenue or share logic is implied.');fk('payment_receipts','financial_space_id','financial_spaces');fk('payment_receipts','payer_person_id','persons');fk('payment_receipts',('destination_account_id','financial_space_id'),'destination_accounts',('id','financial_space_id'));fk('payment_receipts','recorded_by_person_id','persons');fk('payment_receipts','command_id','command_receipts')
TABLES['payment_methods']['unique'].append(('id','is_bank_method'))
fk('payment_receipts',('method_id','method_is_bank'),'payment_methods',('id','is_bank_method'))
table('product_entitlements', '''id BINARY(16) NOT NULL
product_id INT UNSIGNED NOT NULL
beneficiary_person_id BINARY(16) NOT NULL
source_system_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
source_reference VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
valid_from DATETIME(6) NOT NULL
valid_until DATETIME(6) NULL
revoked_at DATETIME(6) NULL''',unique=[('source_system_code','source_reference','product_id','beneficiary_person_id')],checks=['valid_until IS NULL OR valid_until > valid_from'],note='Source verification contract pending final assessment integration. Never infer entitlement from any clinic payment.');fk('product_entitlements','product_id','product_definitions');fk('product_entitlements','beneficiary_person_id','persons')

table('config_definitions', '''id INT UNSIGNED NOT NULL AUTO_INCREMENT
key_code VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
value_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
validation_json JSON NOT NULL''',unique=[('key_code',)],note='Server allowlist; no arbitrary rule execution or privacy bypass flags.')
table('config_versions', '''id BINARY(16) NOT NULL
definition_id INT UNSIGNED NOT NULL
scope_id BINARY(16) NOT NULL
version_no INT UNSIGNED NOT NULL
value_json JSON NOT NULL
effective_at DATETIME(6) NOT NULL
changed_by_person_id BINARY(16) NOT NULL
command_id BINARY(16) NOT NULL''',unique=[('definition_id','scope_id','version_no'),('command_id',)],checks=['version_no > 0'],note='Never store plaintext provider secrets here; encrypted secret storage/deployment is a separate design. Effect on existing operations is a policy gate.');fk('config_versions','definition_id','config_definitions');fk('config_versions','scope_id','work_scopes');fk('config_versions','changed_by_person_id','persons');fk('config_versions','command_id','command_receipts')
table('audit_entries', '''id BINARY(16) NOT NULL
command_id BINARY(16) NULL
actor_person_id BINARY(16) NULL
system_actor_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL
scope_id BINARY(16) NULL
action_code VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
resource_kind VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
resource_reference VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
decision VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
reason_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
redacted_metadata_json JSON NULL''',indexes=[('scope_id','created_at'),('command_id',)],checks=["decision IN ('ALLOWED','DENIED','CONFLICT','FAILED')",'(actor_person_id IS NOT NULL AND system_actor_code IS NULL) OR (actor_person_id IS NULL AND system_actor_code IS NOT NULL)'],note='Append-only command contract, not tamper-proof against host owner. Resource references are diagnostic, NEVER authorization grants or polymorphic clinical FKs. Invalid/nonexistent input may be audited safely without a target FK.');fk('audit_entries','command_id','command_receipts');fk('audit_entries','actor_person_id','persons');fk('audit_entries','scope_id','work_scopes')
table('notification_intents', '''id BINARY(16) NOT NULL
command_id BINARY(16) NOT NULL
recipient_person_id BINARY(16) NOT NULL
template_code VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
scheduled_at DATETIME(6) NOT NULL
redacted_parameters_json JSON NULL''',unique=[('command_id','recipient_person_id','template_code')],indexes=[('status','scheduled_at')],checks=["status IN ('PENDING','SENT','FAILED','CANCELLED')"],note='No clinical report content or credentials. Recheck current authority before delivery. Intent records are not business-state truth.');fk('notification_intents','command_id','command_receipts');fk('notification_intents','recipient_person_id','persons')
table('notification_attempts', '''id BINARY(16) NOT NULL
intent_id BINARY(16) NOT NULL
attempt_no INT UNSIGNED NOT NULL
provider_reference VARCHAR(191) NULL
result_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL
attempted_at DATETIME(6) NOT NULL''',unique=[('intent_id','attempt_no')],checks=['attempt_no > 0']);fk('notification_attempts','intent_id','notification_intents')

def keys(t):
    return [('id',)] + t['unique'] + t['indexes']

def prepare():
    # Explicit source indexes; target FK references always have a candidate UNIQUE key.
    for source, cols, target, refs in FKS:
        assert source in TABLES and target in TABLES
        if not any(k[:len(cols)] == cols for k in keys(TABLES[source])):
            TABLES[source]['indexes'].append(cols)
        assert refs in [('id',)] + TABLES[target]['unique'], (source, target, refs)

def render():
    prepare()
    out = ['-- JOMA Physical Schema v0.1 — REVIEW CANDIDATE; NOT A LIVE UPGRADE.',
           '-- Requires Oracle MySQL >= 8.0.16 (enforced CHECK), InnoDB, utf8mb4_unicode_ci.',
           '-- MariaDB/MySQL 5.7 are NOT claimed compatible. Run only in a NEW disposable database first.',
           '-- All BINARY(16) entity IDs are application-generated cryptographically random UUIDv4.',
           '-- SQL does not enforce authorization, immutable history, minimum-child counts or interval exclusion.',
           '-- No DROP, TRUNCATE, seed permissions, cascade deletes, or FOREIGN_KEY_CHECKS disabling.',
           '-- DDL auto-commits in MySQL; this file is NOT a rollbackable transaction or idempotent migration.',
           "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;", "SET SESSION time_zone = '+00:00';", '']
    for name,t in TABLES.items():
        if t['note']: out.append('-- '+t['note'])
        lines=['  '+c for c in t['cols']]+['  PRIMARY KEY (`id`)']
        for i,k in enumerate(t['unique'],1): lines.append(f"  UNIQUE KEY `uq_{name}_{i}` ({', '.join('`'+x+'`' for x in k)})")
        for i,k in enumerate(t['indexes'],1): lines.append(f"  KEY `ix_{name}_{i}` ({', '.join('`'+x+'`' for x in k)})")
        for i,check in enumerate(t['checks'],1): lines.append(f'  CONSTRAINT `ck_{name}_{i}` CHECK ({check})')
        out.append(f"CREATE TABLE `joma_{name}` (\n"+',\n'.join(lines)+'\n) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n')
    out.append('-- Add foreign keys after all tables exist. Existing data is never silently bypassed.')
    for i,(source,cols,target,refs) in enumerate(FKS,1):
        out.append(f"ALTER TABLE `joma_{source}` ADD CONSTRAINT `fk_joma_{i:03d}` FOREIGN KEY ({', '.join('`'+x+'`' for x in cols)}) REFERENCES `joma_{target}` ({', '.join('`'+x+'`' for x in refs)}) ON DELETE RESTRICT ON UPDATE RESTRICT;")
    return '\n'.join(out)+'\n'

DOMAIN_GROUPS = {
    '01-identity': ('هویت و نقش', 'role_definitions persons accounts contact_points person_contacts work_scopes memberships role_assignments professional_profiles professional_credentials'),
    '02-services-clinical': ('خدمات و پرونده', 'service_definitions service_policy_versions service_offerings service_providers admissions admission_participants therapist_assignments responsibility_acceptances therapeutic_relationships clinical_cases case_participants case_operational_contexts'),
    '03-representation': ('نمایندگی و اختیار', 'representations administrative_delegations verification_authority_bases representation_verifications representation_events case_representation_restrictions'),
    '04-scheduling': ('نوبت و جلسه', 'appointment_requests schedule_resources capacity_holds hold_allocations appointments appointment_allocations appointment_changes attendance_observations clinical_sessions'),
    '05-forms': ('فرم و مخاطب پاسخ', 'form_templates form_versions form_approvals service_form_requirements service_prerequisites form_instances form_subjects form_submission_revisions consent_evidence form_publications form_publication_audiences'),
    '06-reports': ('ارزیابی و گزارش', 'assessments assessment_respondents assessment_assignments protected_files assessment_reports report_versions report_publications report_publication_audiences'),
    '07-private-messages': ('مرز خصوصی و پیام', 'private_note_references practitioner_messages'),
    '08-finance': ('دریافت و استحقاق', 'payment_methods product_definitions financial_spaces destination_accounts payment_receipts product_entitlements'),
    '09-operations': ('پیکربندی و ممیزی', 'command_receipts config_definitions config_versions audit_entries notification_intents notification_attempts'),
}

def physical_views():
    prepare()
    covered=[n for _,names in DOMAIN_GROUPS.values() for n in names.split()]
    assert len(covered)==len(set(covered)) and set(covered)==set(TABLES)
    diagrams={}
    for slug,(title,names) in DOMAIN_GROUPS.items():
        local=set(names.split())
        edges=[f for f in FKS if f[0] in local]
        external={t for _,_,t,_ in edges}-local
        lines=['erDiagram']
        for name in sorted(local|external):
            lines.append('    joma_'+name+' {')
            fk_cols={c for src,cols,_,_ in edges if src==name for c in cols}
            cols=TABLES[name]['cols'] if name in local else [TABLES[name]['cols'][0]]
            for c in cols:
                key=c.split()[0]
                typ=re.match(r'\w+\s+([A-Z]+)',c).group(1)
                if typ=='BINARY':typ='BINARY16'
                tags=[]
                if key=='id':tags.append('PK')
                if key in fk_cols:tags.append('FK')
                if (key,) in TABLES[name]['unique']:tags.append('UK')
                lines.append('        '+typ+' '+key+(' '+', '.join(tags) if tags else ''))
            lines.append('    }')
        for source,cols,target,refs in edges:
            defs={c.split()[0]:c for c in TABLES[source]['cols']}
            nullable=any(' NOT NULL' not in defs[c] for c in cols)
            left='o|' if nullable else '||'
            right='o|' if cols in [('id',)]+TABLES[source]['unique'] else 'o{'
            lines.append(f'    joma_{target} {left}--{right} joma_{source} : {cols[0]}')
        diagrams[slug]=(title,'\n'.join(lines)+'\n')
    return diagrams

if __name__ == '__main__':
    output=ROOT/'database/joma-v0.1/001_core.sql'
    output.parent.mkdir(parents=True,exist_ok=True)
    output.write_text(render(),encoding='utf-8')
    viewdir=ROOT/'database/joma-v0.1/diagrams';viewdir.mkdir(exist_ok=True)
    md=['# جوما — Physical ERD v0.1', '',
        'این نمودارها از همان منبع DDL تولید شده‌اند؛ سند اصلی توضیحات: JOMA-PHYSICAL-SCHEMA-v0.1-FA.md.',
        'نوع‌های Mermaid خلاصه‌اند؛ طول دقیق، UNSIGNED، collation، generated column و CHECKها در 001_core.sql مرجع قطعی‌اند.',
        'جدول بیرونی در هر نما فقط با id نشان داده می‌شود. UNIQUE مرکب و شروط مجوز از خطوط نمودار به‌تنهایی قابل استنباط نیستند.',
        'این طرح مرورپذیر است، نه گزارش اجرای موفق روی MySQL یا تضمین امنیت.', '']
    for slug,(title,diagram) in physical_views().items():
        (viewdir/(slug+'.mmd')).write_text(diagram,encoding='utf-8')
        md+=['## '+title,'','```mermaid',diagram.rstrip(),'```','']
    (ROOT/'docs/JOMA-PHYSICAL-ERD-v0.1-FA.md').write_text('\n'.join(md),encoding='utf-8')
    print(f'{output}: {len(TABLES)} tables, {len(FKS)} foreign keys, 9 physical ER diagrams')
