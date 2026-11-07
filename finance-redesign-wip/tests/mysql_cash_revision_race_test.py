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
def run(mode,payment_id=0,expected=0,action='CORRECT',amount=20000):
    env=os.environ.copy(); env.update(JOMA_MODE=mode,JOMA_LATE_ID=str(late),JOMA_SETTLEMENT_ID=str(settlement),JOMA_PAYMENT_ID=str(payment_id),JOMA_EXPECTED_VERSION=str(expected),JOMA_ACTION=action,JOMA_AMOUNT=str(amount))
    r=subprocess.run([php,str(worker)],env=env,capture_output=True,text=True,check=True)
    if r.stderr.strip() or r.stdout.strip() not in ('ACCEPT','REJECT'): raise RuntimeError(r.stdout+' '+r.stderr)
    return r.stdout.strip()
if run('RESOLVE')!='ACCEPT' or run('PAYMENT')!='ACCEPT': raise SystemExit('Synthetic setup failed')
with conn.cursor() as c:
    c.execute('SELECT id FROM finance_v2_center_payment_events WHERE settlement_id=%s',(settlement,)); payment_id=c.fetchone()[0]
with ThreadPoolExecutor(max_workers=2) as pool:
    fs=[pool.submit(run,'CASH_REVISION',payment_id,0,'CORRECT',10000),pool.submit(run,'CASH_REVISION',payment_id,0,'CORRECT',10000)]
    results=[f.result() for f in fs]
if sorted(results)!=['ACCEPT','REJECT']: raise SystemExit('Unexpected cash revision race: '+repr(results))
with conn.cursor() as c:
    c.execute('SELECT id,action,amount_rial FROM finance_v2_center_payment_revisions WHERE payment_event_id=%s',(payment_id,)); rows=c.fetchall()
    if len(rows)!=1 or rows[0][1]!='CORRECT' or int(rows[0][2])!=10000: raise SystemExit('Incorrect correction history')
    version=rows[0][0]
# Only after voiding the mistaken entry is a 25,000-rial payment allowable;
# if the payment wins the lock first it must be rejected and not recorded.
with ThreadPoolExecutor(max_workers=2) as pool:
    a=pool.submit(run,'CASH_REVISION',payment_id,version,'VOID',0)
    b=pool.submit(run,'PAYMENT',0,0,'CORRECT',25000)
    void,pay=a.result(),b.result()
if void!='ACCEPT' or pay not in ('ACCEPT','REJECT'): raise SystemExit('Unexpected void/payment result')
with conn.cursor() as c:
    c.execute('SELECT action FROM finance_v2_center_payment_revisions WHERE payment_event_id=%s ORDER BY id',(payment_id,)); actions=[r[0] for r in c.fetchall()]
    if actions!=['CORRECT','VOID']: raise SystemExit('Cash history corrupted')
    c.execute('SELECT amount_rial FROM finance_v2_center_payment_events WHERE id=%s',(payment_id,))
    if int(c.fetchone()[0])!=20000: raise SystemExit('Original payment overwritten')
    c.execute('SELECT COUNT(*),COALESCE(SUM(amount_rial),0) FROM finance_v2_center_payment_events WHERE settlement_id=%s AND id<>%s',(settlement,payment_id))
    count,total=c.fetchone()
    if (count,int(total))!=((1,25000) if pay=='ACCEPT' else (0,0)): raise SystemExit('Unexpected second payment')
print('Concurrent center cash corrections and payment test passed (synthetic database).')
