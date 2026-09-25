#!/usr/bin/env python3
"""Package/guard checks, not execution on MariaDB or of PHP endpoints."""
from pathlib import Path
import json,re,unittest
ROOT=Path(__file__).resolve().parents[1]
SQL=(ROOT/'database/joma-mariadb-v0.1/001_core.sql').read_text()
M=json.loads((ROOT/'database/joma-mariadb-v0.1/expected-schema.json').read_text())
PHP=(ROOT/'deploy/joma-db-check/joma-db-check.php').read_text()
class MariaDBPackage(unittest.TestCase):
    def test_expected_counts(self):
        self.assertEqual(len(M['tables']),70)
        self.assertEqual(len(M['foreign_keys']),173)
        self.assertEqual(len(M['explicit_checks']),90)
        self.assertEqual(len(M['unique_indexes']),72)
        self.assertEqual(len(M['json_columns']),14)
    def test_manifest_matches_ddl(self):
        self.assertEqual(set(re.findall(r'CREATE TABLE `([^`]+)`',SQL)),set(M['tables']))
        self.assertEqual(set(re.findall(r'ADD CONSTRAINT `(fk_[^`]+)`',SQL)),set(M['foreign_keys']))
        self.assertEqual(set(re.findall(r'CONSTRAINT `(ck_[^`]+)` CHECK',SQL)),set(M['explicit_checks']))
    def test_constraints_not_bypassed(self):
        self.assertIn('SET SESSION check_constraint_checks = ON;',SQL)
        self.assertIn('SET SESSION foreign_key_checks = 1;',SQL)
        self.assertEqual(SQL.count('ON DELETE RESTRICT ON UPDATE RESTRICT;'),173)
        self.assertNotIn('DROP TABLE',SQL)
        self.assertNotIn('CREATE TABLE IF NOT EXISTS',SQL)
    def test_manifests_same_and_foreign_key_shapes(self):
        self.assertEqual(M,json.loads((ROOT/'deploy/joma-db-check/expected-schema.json').read_text()))
        self.assertEqual(len(M['foreign_key_details']),173)
        for fk in M['foreign_key_details']:
            self.assertIn(fk['table'],M['tables']);self.assertIn(fk['referenced_table'],M['tables'])
            self.assertEqual(len(fk['columns']),len(fk['referenced_columns']))
    def test_diagnostic_gates(self):
        for guard in ['hash_equals','HTTPS required','test_database_confirmed','allow_rollback_tests','begin_transaction','finally','->rollback()','mirbolouki_clinic']:
            self.assertIn(guard,PHP)
        self.assertNotIn('->getMessage()',PHP)
        self.assertNotIn('multi_query',PHP)
        self.assertNotIn('$_GET[',PHP)
        config=(ROOT/'deploy/joma-db-check/joma-db-check-config.example.php').read_text()
        for flag in ['enabled','test_database_confirmed','allow_rollback_tests']:
            self.assertIn("'"+flag+"' => false",config)
        self.assertIn("'password' => ''",config)
    def test_verification_is_readonly(self):
        for f in ['000_preflight_readonly.sql','002_verify_readonly.sql']:
            sql=(ROOT/'database/joma-mariadb-v0.1'/f).read_text()
            sql=re.sub(r'--[^\n]*','',sql)
            self.assertIsNone(re.search(r'\b(?:INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE)\b',sql.replace('SHOW CREATE TABLE','SHOW_TABLE_DEFINITION'),re.I))
    def test_verification_target_not_connection_default(self):
        for name in ['000_preflight_readonly.sql','002_verify_readonly.sql']:
            text=(ROOT/'database/joma-mariadb-v0.1'/name).read_text()
            self.assertNotRegex(text,r'(?:TABLE|CONSTRAINT)_SCHEMA\s*=\s*DATABASE\(\)')
            self.assertIn("TABLE_SCHEMA='mirbolouki_clinic'",text)
        verify=(ROOT/'database/joma-mariadb-v0.1/002_verify_readonly.sql').read_text()
        shows=re.findall(r'SHOW CREATE TABLE ([^;]+);',verify)
        self.assertEqual(len(shows),4)
        self.assertTrue(all(x.startswith('`mirbolouki_clinic`.') for x in shows))
    def test_write_tests_block_existing_non_joma_tables(self):
        self.assertIn("|| $nonJomaTables",PHP)
        self.assertIn("strpos($name, 'joma_') !== 0",PHP)
if __name__=='__main__':unittest.main(verbosity=2)
