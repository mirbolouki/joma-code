"""Exercise disabled WIP entry points with a fake bootstrap and no V2 files.
This is not an authentication, browser or production deployment test.
Set JOMA_TEST_PHP to a PHP CLI; writes only a temporary local directory.
"""
import os, subprocess, tempfile
from pathlib import Path
repo=Path(__file__).resolve().parents[1]
php=os.environ['JOMA_TEST_PHP']
pages=['admin/finance_center_basis_v2.php','admin/finance_expenses_v2.php',
       'admin/finance_minute_rates.php','admin/finance_report_v2.php',
       'admin/finance_legacy_review_v2.php','admin/finance_late_review_v2.php',
       'reception/finance_ledger_v2.php']
bootstrap="""<?php
const ROLE_ADMIN='admin'; const ROLE_SECRETARY='secretary';
$GLOBALS['db']=null;
function auth_require_active_session($db) {}
function require_role($roles) {}
if (getenv('FAKE_V2_FLAG')==='false') define('FINANCE_V2_ACTIVE',false);
if (getenv('FAKE_V2_FLAG')==='true') define('FINANCE_V2_ACTIVE',true);
if (getenv('FAKE_V2_FLAG')==='integer') define('FINANCE_V2_ACTIVE',1);
if (getenv('FAKE_V2_FLAG')==='string') define('FINANCE_V2_ACTIVE','true');
register_shutdown_function(function() { echo 'HTTP_CODE:'.http_response_code()."\\n"; });
"""
with tempfile.TemporaryDirectory(prefix='joma-disabled-pages-') as tmp:
    root=Path(tmp); (root/'includes').mkdir(); (root/'admin').mkdir(); (root/'reception').mkdir()
    (root/'includes/bootstrap.php').write_text(bootstrap,encoding='utf-8')
    for page in pages:
        (root/page).write_text((repo/'app'/page).read_text(encoding='utf-8'),encoding='utf-8')
        # Non-boolean-true flag values must all stop before loading any
        # nonexistent V2 service or running page code, for GET and POST.
        for flag in ('undefined','false','integer','string'):
            for method in ('GET','POST'):
                env=os.environ.copy(); env['FAKE_V2_FLAG']=flag; env['REQUEST_METHOD']=method
                r=subprocess.run([php,str(root/page)],env=env,capture_output=True,text=True)
                if r.returncode or r.stdout.strip()!='HTTP_CODE:404' or r.stderr.strip():
                    raise SystemExit(f'Gate failed {page} {flag} {method}: {r.stdout} {r.stderr}')
    # Late percentage-review page also needs its second flag, even if V2 is on.
    env=os.environ.copy(); env['FAKE_V2_FLAG']='true'; env['REQUEST_METHOD']='GET'
    r=subprocess.run([php,str(root/'admin/finance_late_review_v2.php')],env=env,capture_output=True,text=True)
    if r.returncode or r.stdout.strip()!='HTTP_CODE:404' or r.stderr.strip():
        raise SystemExit('Percentage gate failed: '+r.stdout+' '+r.stderr)
print('Disabled WIP entry points reject GET and POST before V2 code (fake bootstrap).')
