"""Two edits to the same version in an explicitly named local test DB."""
import os, re, subprocess
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
import pymysql
name=os.environ.get('JOMA_TEST_DATABASE',''); host=os.environ.get('JOMA_TEST_HOST','')
if not re.fullmatch(r'joma_v2_test_[a-z0-9_]+',name) or host not in ('127.0.0.1','localhost') or os.environ.get('JOMA_TEST_ALLOW_WRITES')!='YES':
    raise SystemExit('Refusing non-test database')
conn=pymysql.connect(host=host,port=int(os.environ['JOMA_TEST_PORT']),user=os.environ['JOMA_TEST_USER'],password=os.environ.get('JOMA_TEST_PASSWORD',''),database=name,autocommit=True)
with conn.cursor() as cursor:
    cursor.execute("INSERT INTO finance_v2_expenses (operation_key,expense_date,category,amount_rial,account_id,recorded_by_person_id,recorded_at) VALUES ('race_expense_0000001','2026-10-20','original',1000,1,2,NOW())")
    expense_id=cursor.lastrowid
php=os.environ['JOMA_TEST_PHP']; worker=Path(__file__).resolve().parent/'mysql_expense_race_worker.php'
def run(key):
    env=os.environ.copy(); env.update(JOMA_RACE_EXPENSE=str(expense_id),JOMA_RACE_KEY=key)
    result=subprocess.run([php,str(worker)],env=env,capture_output=True,text=True,check=True)
    return result.stdout.strip()
with ThreadPoolExecutor(max_workers=2) as pool:
    futures=[pool.submit(run,'race_expense_edit_001'),pool.submit(run,'race_expense_edit_002')]
    results=[future.result() for future in futures]
if sorted(results)!=['ACCEPT','REJECT']: raise SystemExit('Unexpected expense race results: '+repr(results))
with conn.cursor() as cursor:
    cursor.execute('SELECT COUNT(*) FROM finance_v2_expense_revisions WHERE expense_id=%s',(expense_id,))
    if cursor.fetchone()[0]!=1: raise SystemExit('Wrong number of revisions')
    cursor.execute('SELECT amount_rial FROM finance_v2_expenses WHERE id=%s',(expense_id,))
    if int(cursor.fetchone()[0])!=1000: raise SystemExit('Original expense was changed')
print('Concurrent expense edits test passed.')
