"""Concurrency test: overlapping settlement periods on local synthetic DB only."""
import os, re, subprocess
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import pymysql
name=os.environ.get('JOMA_TEST_DATABASE',''); host=os.environ.get('JOMA_TEST_HOST','')
if not re.fullmatch(r'joma_v2_test_[a-z0-9_]+',name) or host not in ('127.0.0.1','localhost') or os.environ.get('JOMA_TEST_ALLOW_WRITES')!='YES':
    raise SystemExit('Refusing non-test database')
conn=pymysql.connect(host=host,port=int(os.environ['JOMA_TEST_PORT']),user=os.environ['JOMA_TEST_USER'],password=os.environ.get('JOMA_TEST_PASSWORD',''),database=name,autocommit=True)
with conn.cursor() as cursor:
    cursor.execute("INSERT INTO appointments VALUES (5,1,1,1,'COMPLETED',NULL,'2026-10-22 09:00:00',1,60)")
php=os.environ['JOMA_TEST_PHP']; worker=Path(__file__).resolve().parent/'mysql_center_race_worker.php'
def run(end):
    env=os.environ.copy(); env['JOMA_RACE_END']=end
    result=subprocess.run([php,str(worker)],env=env,capture_output=True,text=True,check=True)
    return result.stdout.strip()
with ThreadPoolExecutor(max_workers=2) as pool:
    futures=[pool.submit(run,'2026-10-22'),pool.submit(run,'2026-10-23')]
    results=[future.result() for future in futures]
if sorted(results)!=['ACCEPT','REJECT']:
    raise SystemExit('Unexpected center race results: '+repr(results))
with conn.cursor() as cursor:
    cursor.execute("SELECT COUNT(*) FROM finance_center_settlements WHERE center_id=1 AND period_start='2026-10-22'")
    if cursor.fetchone()[0]!=1: raise SystemExit('Duplicate center settlements persisted')
print('Concurrent overlapping settlement test passed.')
