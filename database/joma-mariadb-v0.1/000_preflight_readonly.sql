-- READ ONLY. This file does not create, modify, or drop anything.
SELECT DATABASE() AS selected_database, VERSION() AS server_version,
       @@version_comment AS distribution, @@sql_mode AS sql_mode,
       @@foreign_key_checks AS foreign_keys_enabled,
       @@check_constraint_checks AS checks_enabled;
SELECT COUNT(*) AS existing_base_tables FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE';
SELECT ENGINE,SUPPORT,TRANSACTIONS FROM information_schema.ENGINES WHERE ENGINE='InnoDB';
