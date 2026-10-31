"""Racing reviews must reject one stale form. Local synthetic database only."""
import os,re,subprocess
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
import pymysql
name=os.environ.get('JOMA_TEST_DATABASE',''); host=os.environ.get('JOMA_TEST_HOST','')
if not re.fullmatch(r'joma_v2_test_[a-z0-9_]+',name) or host not in ('127.0.0.1','localhost') or os.environ.get('JOMA_TEST_ALLOW_WRITES')!='YES':
    raise SystemExit('Refusing non-test database')
conn=pymysql.connect(host=host,port=int(os.environ['JOMA_TEST_PORT']),user=os.environ['JOMA_TEST_USER'],password=os.environ.get('JOMA_TEST_PASSWORD',''),database=name,autocommit=True)
with conn.cursor() as c:
    c.execute("INSERT INTO finance_session_payments (id,public_id,appointment_id,session_fee_rial,deposit_amount_rial,deposit_bank_account_id,deposit_status,deposit_paid_at,created_at) VALUES (1,'LEGACY1',1,1000,500,1,'PAID','2026-10-01 12:00:00',NOW())")
worker=Path(__file__).resolve().parent/'mysql_legacy_review_race_worker.php'; php=os.environ['JOMA_TEST_PHP']
def run():
    r=subprocess.run([php,str(worker)],capture_output=True,text=True,check=True)
    return r.stdout.strip()
with ThreadPoolExecutor(max_workers=2) as pool:
    futures=[pool.submit(run),pool.submit(run)]; results=[f.result() for f in futures]
if sorted(results)!=['ACCEPT','REJECT']: raise SystemExit('Unexpected review race results: '+repr(results))
with conn.cursor() as c:
    c.execute('SELECT COUNT(*) FROM finance_v2_legacy_center_reviews WHERE payment_id=1')
    if c.fetchone()[0]!=1: raise SystemExit('Unexpected persisted review count')
print('Concurrent legacy reviews test passed.')
