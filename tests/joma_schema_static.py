#!/usr/bin/env python3
"""Static checks only. Does not connect to MySQL or prove database behavior."""
import importlib.util
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('schema', ROOT/'tools/generate-joma-schema.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
SQL = m.render()

def columns(table):
    return {c.split()[0]: c for c in m.TABLES[table]['cols']}

def storage_type(definition):
    return re.match(r'\w+\s+([A-Z]+(?:\([^)]*\))?(?: UNSIGNED)?)', definition).group(1)

class SchemaStructure(unittest.TestCase):
    def test_generated_file_matches(self):
        self.assertEqual(SQL, (ROOT/'database/joma-v0.1/001_core.sql').read_text())
        for slug,(_,diagram) in m.physical_views().items():
            self.assertEqual(diagram,(ROOT/'database/joma-v0.1/diagrams'/(slug+'.mmd')).read_text())
        self.assertEqual((ROOT/'docs/JOMA-PHYSICAL-ERD-v0.1-FA.md').read_text().count('```mermaid'),9)
    def test_all_tables_innodb_utf8mb4(self):
        self.assertEqual(SQL.count('ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'), len(m.TABLES))
    def test_ids_and_names(self):
        for name,t in m.TABLES.items():
            self.assertLessEqual(len('joma_'+name),64)
            self.assertEqual(len(columns(name)),len(t['cols']))
            self.assertRegex(columns(name)['id'],r'^id (BINARY\(16\) NOT NULL|INT UNSIGNED NOT NULL AUTO_INCREMENT)$')
        for name in re.findall(r'(?:CONSTRAINT|KEY) `([^`]+)`', SQL): self.assertLessEqual(len(name),64)
    def test_fk_types_and_candidate_keys(self):
        for source,cols,target,refs in m.FKS:
            self.assertEqual(len(cols),len(refs))
            self.assertIn(refs,[('id',)]+m.TABLES[target]['unique'])
            for c,r in zip(cols,refs):
                self.assertEqual(storage_type(columns(source)[c]),storage_type(columns(target)[r]),(source,c,target,r))
    def test_every_fk_has_source_index(self):
        for source,cols,_,_ in m.FKS:
            self.assertTrue(any(k[:len(cols)]==cols for k in m.keys(m.TABLES[source])))
    def test_index_columns_exist(self):
        for name,t in m.TABLES.items():
            for key in m.keys(t):
                for c in key:self.assertIn(c,columns(name))
    def test_safe_actions(self):
        self.assertEqual(SQL.count('ON DELETE RESTRICT ON UPDATE RESTRICT;'),len(m.FKS))
        for forbidden in ['DROP TABLE','TRUNCATE TABLE','ON DELETE CASCADE','FOREIGN_KEY_CHECKS=0','CREATE DATABASE']:
            self.assertNotIn(forbidden,SQL)
    def test_no_silent_rerun(self):
        self.assertNotIn('CREATE TABLE IF NOT EXISTS',SQL)
    def test_precase_nullable(self):
        for name in ['admissions','appointment_requests','form_instances']:
            self.assertEqual(columns(name)['case_id'],'case_id BINARY(16) NULL')
    def test_session_optional_unique_and_consistent_case(self):
        self.assertIn(('appointment_id',),m.TABLES['clinical_sessions']['unique'])
        self.assertEqual(columns('clinical_sessions')['appointment_id'],'appointment_id BINARY(16) NULL')
        self.assertIn(('clinical_sessions',('appointment_id','case_id','clinician_person_id'),'appointments',('id','case_id','therapist_person_id')),m.FKS)
    def test_authority_xor_and_identity(self):
        checks=' '.join(m.TABLES['verification_authority_bases']['checks'])
        self.assertIn('direct_role_assignment_id IS NOT NULL AND delegation_id IS NULL',checks)
        self.assertIn('direct_role_assignment_id IS NULL AND delegation_id IS NOT NULL',checks)
        self.assertEqual(sum(1 for s,_,_,_ in m.FKS if s=='verification_authority_bases'),2)
    def test_active_uniqueness(self):
        self.assertIn(('active_person_id',),m.TABLES['accounts']['unique'])
        self.assertIn("'LOCKED'",columns('accounts')['active_person_id'])
        self.assertIn(('open_admission_id',),m.TABLES['therapist_assignments']['unique'])
    def test_json_and_private_note_separation(self):
        self.assertEqual(columns('form_versions')['schema_json'],'schema_json JSON NOT NULL')
        self.assertEqual(columns('form_submission_revisions')['answers_json'],'answers_json JSON NOT NULL')
        for col in columns('private_note_references'):
            self.assertNotIn(col, ['body','answers_json','report_text','key','private_key','ciphertext'])
        self.assertFalse(any(s.startswith('private_note') and t.endswith('audiences') for s,_,t,_ in m.FKS))
    def test_typed_audiences(self):
        for name,parent in [('form_publication_audiences','form_publications'),('report_publication_audiences','report_publications')]:
            self.assertIn((name,('publication_id',),parent,('id',)),m.FKS)
            self.assertIn('recipient_person_id',columns(name))
            self.assertNotIn('resource_type',columns(name))
    def test_interval_query_indexes_not_exclusion(self):
        for name,owner in [('hold_allocations','hold_id'),('appointment_allocations','appointment_id')]:
            self.assertIn(('resource_id','starts_at','ends_at',owner),m.TABLES[name]['indexes'])
            self.assertNotIn(('resource_id','starts_at','ends_at'),m.TABLES[name]['unique'])
    def test_bank_method_and_space_consistency(self):
        self.assertIn(('payment_receipts',('method_id','method_is_bank'),'payment_methods',('id','is_bank_method')),m.FKS)
        self.assertIn('method_is_bank = 0 OR destination_account_id IS NOT NULL',m.TABLES['payment_receipts']['checks'])
        self.assertIn(('payment_receipts',('destination_account_id','financial_space_id'),'destination_accounts',('id','financial_space_id')),m.FKS)
    def test_no_unknown_crypto_payload_or_wallet(self):
        for name in ['wallets','ledger_entries','private_note_plaintext','raw_test_answers']:
            self.assertNotIn(name,m.TABLES)
    def test_no_implicit_privacy_seed(self):
        self.assertNotIn('INSERT INTO',SQL)
        self.assertNotIn('permission=true',SQL)

if __name__ == '__main__':
    unittest.main(verbosity=2)
