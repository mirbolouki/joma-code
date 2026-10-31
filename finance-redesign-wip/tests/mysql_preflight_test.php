<?php
/* Test uses DDL only in a fresh explicitly named LOCAL test database. */
$name=getenv('JOMA_TEST_DATABASE');
if (!is_string($name) || !preg_match('/^joma_v2_test_[a-z0-9_]+$/D',$name) || getenv('JOMA_TEST_ALLOW_WRITES') !== 'YES' || !in_array(getenv('JOMA_TEST_HOST'),array('127.0.0.1','localhost'),true)) throw new Exception('Refusing non-test database');
require getenv('JOMA_TEST_DB_HELPERS');
require __DIR__ . '/../src/schema_preflight.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db=mysqli_connect(getenv('JOMA_TEST_HOST'),getenv('JOMA_TEST_USER'),getenv('JOMA_TEST_PASSWORD'),$name,(int)getenv('JOMA_TEST_PORT'));
mysqli_set_charset($db,'utf8mb4');
$result=joma_v2_schema_preflight($db);
if ($result['issues'] || $result['existing_v2_tables'] || $result['ready_for_install'] !== false) throw new Exception('Expected clean baseline, not installation approval: '.json_encode($result));
mysqli_query($db,'ALTER TABLE rooms DROP COLUMN hourly_rate');
$result=joma_v2_schema_preflight($db);
if (!in_array('missing_column:rooms.hourly_rate',$result['issues'],true)) throw new Exception('Missing column not detected');
mysqli_query($db,'ALTER TABLE rooms ADD COLUMN hourly_rate DECIMAL(12,2) NULL');
mysqli_query($db,'CREATE TABLE finance_v2_events (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
$result=joma_v2_schema_preflight($db);
if ($result['existing_v2_tables']!==array('finance_v2_events')) throw new Exception('Existing WIP table not detected');
if ($result['ready_for_install']!==false) throw new Exception('Must not signal installation readiness');
echo "Read-only schema preflight tests passed (not installation approval).\n";
