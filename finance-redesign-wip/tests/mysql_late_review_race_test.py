"""Two reviews of the same version: only one may be saved. LOCAL test DB only.
Run after mysql_percentage_settlement_test.php in a freshly created test DB.
"""
import os,re,subprocess
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
import pymysql
name=os.environ.get('JOMA_TEST_DATABASE',''); host=os.environ.get('JOMA_TEST_HOST','')
if not re.fullmatch(r'joma_v2_test_[a-z0-9_]+',name) or host not in ('127.0.0.1','localhost') or os.environ.get('JOMA_TEST_ALLOW_WRITES')!='YES':
    raise SystemExit('Refusing non-test database')
conn=pymysql.connect(host=host,port=int(os.environ['JOMA_TEST_PORT']),user=os.environ['JOMA_TEST_USER'],password=os.environ.get('JOMA_TEST_PASSWORD',''),database=name,autocommit=True)
with conn.cursor() as c:
    c.execute('SELECT l.id FROM finance_v2_percentage_late_events l WHERE NOT EXISTS (SELECT 1 FROM finance_v2_percentage_late_reviews r WHERE r.late_event_id=l.id) LIMIT 1')
    row=c.fetchone()
    if not row: raise SystemExit('No unreviewed synthetic late event')
    late_id=row[0]
worker=Path(__file__).resolve().parent/'mysql_late_review_race_worker.php'; php=os.environ['JOMA_TEST_PHP']
def run():
    env=os.environ.copy(); env['JOMA_TEST_LATE_ID']=str(late_id)
    r=subprocess.run([php,str(worker)],env=env,capture_output=True,text=True,check=True)
    return r.stdout.strip()
with ThreadPoolExecutor(max_workers=2) as pool:
    futures=[pool.submit(run),pool.submit(run)]; results=[f.result() for f in futures]
if sorted(results)!=['ACCEPT','REJECT']: raise SystemExit('Unexpected review race results: '+repr(results))
with conn.cursor() as c:
    c.execute('SELECT COUNT(*) FROM finance_v2_percentage_late_reviews WHERE late_event_id=%s',(late_id,))
    if c.fetchone()[0]!=1: raise SystemExit('Unexpected persisted review count')
print('Concurrent late reviews test passed (synthetic database).')
