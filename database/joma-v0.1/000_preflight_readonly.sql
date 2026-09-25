-- JOMA v0.1: READ-ONLY environment inspection. Select the intended TEST database first.
-- This does not install or modify anything and does not certify compatibility.
-- Do not paste credentials, connection strings or patient data into chat.
SELECT
    VERSION() AS server_version,
    @@version_comment AS server_distribution,
    @@sql_mode AS session_sql_mode,
    @@character_set_database AS database_charset,
    @@collation_database AS database_collation,
    @@session.time_zone AS session_time_zone;

SELECT ENGINE, SUPPORT, TRANSACTIONS, XA, SAVEPOINTS
FROM information_schema.ENGINES
WHERE ENGINE = 'InnoDB';

SELECT CHARACTER_SET_NAME, COLLATION_NAME
FROM information_schema.COLLATIONS
WHERE COLLATION_NAME = 'utf8mb4_unicode_ci';

SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND LEFT(TABLE_NAME, 5) = 'joma_'
ORDER BY TABLE_NAME;
