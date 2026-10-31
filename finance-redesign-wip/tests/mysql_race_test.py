"""Runs two independent PHP workers against a synthetic MySQL database."""
import json, os, re, subprocess, sys
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
name = os.environ.get('JOMA_TEST_DATABASE', '')
if not re.fullmatch(r'joma_v2_test_[a-z0-9_]+', name) or os.environ.get('JOMA_TEST_ALLOW_WRITES') != 'YES':
    raise SystemExit('Refusing non-test database')
php = os.environ['JOMA_TEST_PHP']
here = Path(__file__).resolve().parent
setup = subprocess.run([php, str(here/'mysql_race_setup.php')], capture_output=True, text=True, check=True)
ids = json.loads(setup.stdout.strip())
def run(target, key):
    env=os.environ.copy()
    env.update(JOMA_RACE_SOURCE=str(ids['source']),JOMA_RACE_TARGET=str(target),JOMA_RACE_KEY=key)
    result=subprocess.run([php,str(here/'mysql_race_worker.php')],env=env,capture_output=True,text=True,check=True)
    return result.stdout.strip()
with ThreadPoolExecutor(max_workers=2) as pool:
    futures=[pool.submit(run,ids['target1'],'race_transfer_000001'),pool.submit(run,ids['target2'],'race_transfer_000002')]
    results=[future.result() for future in futures]
if sorted(results) != ['ACCEPT','REJECT']:
    raise SystemExit('Unexpected race results: ' + repr(results))
print('Concurrent transfer test passed: one accepted, one rejected.')
