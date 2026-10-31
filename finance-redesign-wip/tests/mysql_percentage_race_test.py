"""A simultaneous receipt must be in the settlement or flagged late."""
import os,re,subprocess
from datetime import datetime,timezone
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
import pymysql
name=os.environ.get('JOMA_TEST_DATABASE',''); host=os.environ.get('JOMA_TEST_HOST','')
if not re.fullmatch(r'joma_v2_test_[a-z0-9_]+',name) or host not in ('127.0.0.1','localhost') or os.environ.get('JOMA_TEST_ALLOW_WRITES')!='YES':
    raise SystemExit('Refusing non-test database')
conn=pymysql.connect(host=host,port=int(os.environ['JOMA_TEST_PORT']),user=os.environ['JOMA_TEST_USER'],password=os.environ.get('JOMA_TEST_PASSWORD',''),database=name,autocommit=True)
with conn.cursor() as c:
    c.execute("UPDATE finance_centers SET commission_model='PERCENTAGE',commission_percent=10 WHERE id=1")
    c.execute("INSERT INTO finance_v2_wallets (id,appointment_id,created_at) VALUES (1,1,%s)",(datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S'),))
    now=datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
    c.execute("INSERT INTO finance_v2_events (operation_key,event_type,receipt_kind,wallet_id,center_snapshot_recorded,source_center_id,amount_rial,account_id,occurred_at,recorded_at,recorded_by_person_id,recorded_by_role_code) VALUES ('percentage_initial_00001','RECEIPT','PAYMENT',1,1,1,1000,1,%s,%s,2,'admin')",(now,now))
worker=Path(__file__).resolve().parent/'mysql_percentage_race_worker.php'; php=os.environ['JOMA_TEST_PHP']
def run(mode):
    env=os.environ.copy(); env['JOMA_MODE']=mode
    result=subprocess.run([php,str(worker)],env=env,capture_output=True,text=True,check=True)
    if not result.stdout.strip().startswith(mode+('D:' if mode=='SETTLE' else ':')): raise RuntimeError(result.stdout+'\n'+result.stderr)
    return int(result.stdout.strip().split(':')[1])
with ThreadPoolExecutor(max_workers=2) as pool:
    sf=pool.submit(run,'SETTLE'); rf=pool.submit(run,'RECEIPT'); settlement=sf.result(); event=rf.result()
with conn.cursor() as c:
    c.execute('SELECT gross_rial FROM finance_v2_percentage_details WHERE settlement_id=%s',(settlement,)); gross=int(c.fetchone()[0])
    c.execute('SELECT COUNT(*) FROM finance_v2_percentage_items WHERE settlement_id=%s AND event_id=%s',(settlement,event)); included=c.fetchone()[0]
    c.execute('SELECT COUNT(*) FROM finance_v2_percentage_late_events WHERE settlement_id=%s AND event_id=%s',(settlement,event)); late=c.fetchone()[0]
if not ((gross==3000 and included==1 and late==0) or (gross==1000 and included==0 and late==1)):
    raise SystemExit('Receipt was not correctly included or flagged: '+repr((gross,included,late)))
print('Concurrent percentage settlement and receipt test passed.')
