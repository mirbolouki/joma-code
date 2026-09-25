#!/usr/bin/env python3
"""Build MariaDB review/import files from the unchanged logical schema source."""
from pathlib import Path
import importlib.util,json
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('base',ROOT/'tools/generate-joma-schema.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
out=ROOT/'database/joma-mariadb-v0.1';out.mkdir(exist_ok=True)
s=m.render().replace('-- Requires Oracle MySQL >= 8.0.16 (enforced CHECK), InnoDB, utf8mb4_unicode_ci.', '-- Target: MariaDB 10.11.x, InnoDB, utf8mb4_unicode_ci. Runtime import must be verified.')
s=s.replace('-- MariaDB/MySQL 5.7 are NOT claimed compatible. Run only in a NEW disposable database first.', '-- Reviewed for MariaDB 10.11: JSON is LONGTEXT utf8mb4_bin + automatic JSON_VALID CHECK. Not MySQL binary JSON.')
s=s.replace("SET SESSION time_zone = '+00:00';", "SET SESSION time_zone = '+00:00';\nSET SESSION foreign_key_checks = 1;\nSET SESSION check_constraint_checks = ON;")
(out/'001_core.sql').write_text(s)
manifest={'target':'MariaDB 10.11.x','tables': ['joma_'+n for n in m.TABLES], 'foreign_keys':[f'fk_joma_{i:03d}' for i in range(1,len(m.FKS)+1)], 'explicit_checks':[f'ck_{n}_{i}' for n,t in m.TABLES.items() for i in range(1,len(t['checks'])+1)], 'unique_indexes':[{'table':'joma_'+n,'name':f'uq_{n}_{i}','columns':list(cols)} for n,t in m.TABLES.items() for i,cols in enumerate(t['unique'],1)],'json_columns':[{'table':'joma_'+n,'column':c.split()[0]} for n,t in m.TABLES.items() for c in t['cols'] if c.split()[1]=='JSON']}
manifest['foreign_key_details']=[{'name':f'fk_joma_{i:03d}','table':'joma_'+src,'columns':list(cols),'referenced_table':'joma_'+dst,'referenced_columns':list(refs)} for i,(src,cols,dst,refs) in enumerate(m.FKS,1)]
(out/'expected-schema.json').write_text(json.dumps(manifest,indent=2)+'\n')
(ROOT/'deploy/joma-db-check/expected-schema.json').write_text(json.dumps(manifest,indent=2)+'\n')
verify="""-- READ ONLY. Select mirbolouki_clinic in phpMyAdmin before running.
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
"""
for label,names,source,key,predicate in [
 ('Missing expected tables',manifest['tables'],'information_schema.TABLES','TABLE_NAME','TABLE_SCHEMA=DATABASE()'),
 ('Missing expected foreign keys',manifest['foreign_keys'],'information_schema.TABLE_CONSTRAINTS','CONSTRAINT_NAME',"CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='FOREIGN KEY'"),
 ('Missing explicit checks',manifest['explicit_checks'],'information_schema.TABLE_CONSTRAINTS','CONSTRAINT_NAME',"CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='CHECK'")]:
 union='\nUNION ALL\n'.join("SELECT '"+name+"' AS expected_name" for name in names)
 verify+=f"\n-- {label}: expected ZERO rows.\nSELECT e.expected_name FROM (\n{union}\n) e WHERE NOT EXISTS (SELECT 1 FROM {source} a WHERE a.{key}=e.expected_name AND {predicate});\n"
verify+="""
-- MariaDB JSON columns normally appear as LONGTEXT / utf8mb4_bin. This is expected.
SHOW CREATE TABLE joma_form_versions;
SHOW CREATE TABLE joma_form_submission_revisions;
SHOW CREATE TABLE joma_accounts;
SHOW CREATE TABLE joma_clinical_sessions;
"""
(out/'002_verify_readonly.sql').write_text(verify)
(out/'000_preflight_readonly.sql').write_text("""-- READ ONLY. This file does not create, modify, or drop anything.
SELECT DATABASE() AS selected_database, VERSION() AS server_version,
       @@version_comment AS distribution, @@sql_mode AS sql_mode,
       @@foreign_key_checks AS foreign_keys_enabled,
       @@check_constraint_checks AS checks_enabled;
SELECT COUNT(*) AS existing_base_tables FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE';
SELECT ENGINE,SUPPORT,TRANSACTIONS FROM information_schema.ENGINES WHERE ENGINE='InnoDB';
""")
print('MariaDB candidate generated:',len(manifest['tables']),'tables,',len(manifest['foreign_keys']),'FK,',len(manifest['json_columns']),'JSON columns')
