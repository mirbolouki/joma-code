"""Synthetic DB only. Run after mysql_percentage_settlement_test.php."""
import os,re,subprocess
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor
import pymysql
name=os.environ.get('JOMA_TEST_DATABASE',''); host=os.environ.get('JOMA_TEST_HOST','')
if not re.fullmatch(r'joma_v2_test_[a-z0-9_]+',name) or host not in ('127.0.0.1','localhost') or os.environ.get('JOMA_TEST_ALLOW_WRITES')!='YES':
    raise SystemExit('Refusing non-test database')
conn=pymysql.connect(host=host,port=int(os.environ['JOMA_TEST_PORT']),user=os.environ['JOMA_TEST_USER'],password=os.environ.get('JOMA_TEST_PASSWORD',''),database=name,autocommit=True)
with conn.cursor() as c:
    c.execute("SELECT l.id,l.settlement_id FROM finance_v2_percentage_late_events l JOIN finance_v2_percentage_details d ON d.settlement_id=l.settlement_id WHERE d.manual_reason='manual decision'")
    row=c.fetchone()
    if not row: raise SystemExit('Missing synthetic percentage flag')
    late,settlement=row
php=os.environ['JOMA_TEST_PHP']; worker=Path(__file__).resolve().parent/'mysql_center_balance_race_worker.php'
def run(mode,expected=0,delta=0,amount=20000):
    env=os.environ.copy(); env.update(JOMA_MODE=mode,JOMA_LATE_ID=str(late),JOMA_SETTLEMENT_ID=str(settlement),JOMA_AMOUNT=str(amount),JOMA_EXPECTED_VERSION=str(expected),JOMA_DELTA=str(delta))
    r=subprocess.run([php,str(worker)],env=env,capture_output=True,text=True,check=True)
    if r.stderr.strip() or r.stdout.strip() not in ('ACCEPT','REJECT'): raise RuntimeError(r.stdout+' '+r.stderr)
    return r.stdout.strip()
if run('RESOLVE')!='ACCEPT': raise SystemExit('Initial resolution failed')
# A reduction races a center payment. Whichever goes first determines whether
# the payment is allowed; the signed balance must preserve the receivable.
with ThreadPoolExecutor(max_workers=2) as pool:
    a=pool.submit(run,'REVISION',0,-30000); b=pool.submit(run,'PAYMENT'); revised,paid=a.result(),b.result()
if revised!='ACCEPT' or paid not in ('ACCEPT','REJECT'): raise SystemExit('Unexpected reduction/payment outcome')
with conn.cursor() as c:
    c.execute('SELECT r.id,v.id,v.delta_rial FROM finance_v2_percentage_late_resolutions r JOIN finance_v2_percentage_resolution_revisions v ON v.resolution_id=r.id WHERE r.late_event_id=%s',(late,))
    rows=c.fetchall()
    if len(rows)!=1 or rows[0][2]!=-30000: raise SystemExit('Revision missing')
    resolution,version,_=rows[0]
    c.execute('SELECT COUNT(*),COALESCE(SUM(amount_rial),0) FROM finance_v2_center_payment_events WHERE settlement_id=%s',(settlement,))
    count,total=c.fetchone(); total=int(total)
    if (count,total)!=((1,20000) if paid=='ACCEPT' else (0,0)): raise SystemExit('Unexpected payment')
    # Principal 25,000 and -30,000 adjustment = -5,000 due.
    if -5000-total != (-25000 if paid=='ACCEPT' else -5000): raise SystemExit('Incorrect credit sign')
# Two writers of the same revision version must not silently overwrite one
# another; one succeeds, the other must be rejected as stale.
with ThreadPoolExecutor(max_workers=2) as pool:
    fs=[pool.submit(run,'REVISION',version,6000),pool.submit(run,'REVISION',version,6000)]
    results=[f.result() for f in fs]
if sorted(results)!=['ACCEPT','REJECT']: raise SystemExit('Unexpected simultaneous revisions: '+repr(results))
with conn.cursor() as c:
    c.execute('SELECT delta_rial FROM finance_v2_percentage_resolution_revisions WHERE resolution_id=%s ORDER BY id',(resolution,))
    deltas=[int(row[0]) for row in c.fetchall()]
    if deltas!=[-30000,6000]: raise SystemExit('Revision history corrupted: '+repr(deltas))
    c.execute('SELECT delta_rial FROM finance_v2_percentage_late_resolutions WHERE id=%s',(resolution,))
    if int(c.fetchone()[0])!=5000: raise SystemExit('Original resolution overwritten')
print('Concurrent resolution revisions and payment test passed (synthetic database).')
