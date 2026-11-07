"""Local synthetic test after mysql_percentage_settlement_test.php only."""
import os,re,subprocess
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
import pymysql
name=os.environ.get('JOMA_TEST_DATABASE',''); host=os.environ.get('JOMA_TEST_HOST','')
if not re.fullmatch(r'joma_v2_test_[a-z0-9_]+',name) or host not in ('127.0.0.1','localhost') or os.environ.get('JOMA_TEST_ALLOW_WRITES')!='YES':
    raise SystemExit('Refusing non-test database')
conn=pymysql.connect(host=host,port=int(os.environ['JOMA_TEST_PORT']),user=os.environ['JOMA_TEST_USER'],password=os.environ.get('JOMA_TEST_PASSWORD',''),database=name,autocommit=True)
with conn.cursor() as c:
    c.execute("SELECT l.id,l.settlement_id,e.wallet_id FROM finance_v2_percentage_late_events l JOIN finance_v2_events e ON e.id=l.event_id JOIN finance_v2_percentage_details d ON d.settlement_id=l.settlement_id WHERE d.manual_reason='manual decision'")
    row=c.fetchone()
    if not row: raise SystemExit('Missing synthetic percentage flag')
    late,settlement,wallet=row
worker=Path(__file__).resolve().parent/'mysql_center_balance_race_worker.php'; php=os.environ['JOMA_TEST_PHP']
def run(mode,amount=20000):
    env=os.environ.copy(); env.update(JOMA_MODE=mode,JOMA_LATE_ID=str(late),JOMA_SETTLEMENT_ID=str(settlement),JOMA_WALLET_ID=str(wallet),JOMA_AMOUNT=str(amount))
    r=subprocess.run([php,str(worker)],env=env,capture_output=True,text=True,check=True)
    if r.stderr.strip(): raise RuntimeError(r.stderr)
    return r.stdout.strip()
def race(mode):
    with ThreadPoolExecutor(max_workers=2) as pool:
        fs=[pool.submit(run,mode),pool.submit(run,mode)]; values=[f.result() for f in fs]
    if sorted(values)!=['ACCEPT','REJECT']: raise SystemExit(mode+' unexpected results: '+repr(values))
race('RESOLVE')
with conn.cursor() as c:
    c.execute('SELECT COUNT(*),SUM(delta_rial) FROM finance_v2_percentage_late_resolutions WHERE late_event_id=%s',(late,))
    count,total=c.fetchone()
    if count!=1 or total!=5000: raise SystemExit('Unexpected resolutions')
race('PAYMENT')
with conn.cursor() as c:
    c.execute('SELECT COUNT(*),SUM(amount_rial) FROM finance_v2_center_payment_events WHERE settlement_id=%s',(settlement,))
    count,total=c.fetchone()
    if count!=1 or total!=20000: raise SystemExit('Overpayment or missing payment')
# A cash receipt and center payment race under the same ledger mutex: if the
# receipt wins the payment is rejected; otherwise payment precedes the flag.
with ThreadPoolExecutor(max_workers=2) as pool:
    pf=pool.submit(run,'PAYMENT',10000); rf=pool.submit(run,'RECEIPT')
    payment=pf.result(); receipt=rf.result()
if receipt!='ACCEPT' or payment not in ('ACCEPT','REJECT'):
    raise SystemExit('Unexpected receipt/payment result: '+repr((receipt,payment)))
with conn.cursor() as c:
    c.execute('SELECT COUNT(*) FROM finance_v2_percentage_late_events l LEFT JOIN finance_v2_percentage_late_resolutions r ON r.late_event_id=l.id WHERE l.settlement_id=%s AND r.id IS NULL',(settlement,))
    if c.fetchone()[0]!=1: raise SystemExit('Concurrent receipt was not flagged')
    c.execute('SELECT SUM(amount_rial) FROM finance_v2_center_payment_events WHERE settlement_id=%s',(settlement,))
    total=int(c.fetchone()[0])
    if total!=(30000 if payment=='ACCEPT' else 20000): raise SystemExit('Unexpected concurrent payment total')
print('Concurrent resolution, center payment and late receipt tests passed (synthetic database).')
