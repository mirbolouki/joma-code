"""Build a non-destructive overlay against the uploaded v2 baseline."""
from pathlib import Path
import zipfile, hashlib, json
root=Path(__file__).resolve().parent.parent
base=zipfile.ZipFile(root/'clinic-v2-upload.zip')
entries=[]
for p in sorted((root/'clinic-app').rglob('*')):
    if not p.is_file(): continue
    name=p.relative_to(root/'clinic-app').as_posix()
    if name.startswith('data/') and name!='data/.htaccess': continue
    if name in ('install.php','config/clinic_db.php','config/clinic_sms.php','config/config.php'): continue
    if name not in base.namelist() or p.read_bytes()!=base.read(name):entries.append((name,p.read_bytes()))
out=root/'deliverables';out.mkdir(exist_ok=True)
with zipfile.ZipFile(out/'clinic-sms-update-v2.1.zip','w',zipfile.ZIP_DEFLATED) as z:
    for name,data in entries:z.writestr(name,data)
manifest={name:hashlib.sha256(data).hexdigest() for name,data in entries}
(out/'clinic-sms-update-manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
(out/'راهنمای-آپدیت-پیامکی.md').write_bytes((root/'docs/CLINIC-SMS-UPDATE-FA.md').read_bytes())
print('Packaged',len(entries),'files, no installer, SQL, existing config, state or test tools')
for n,_ in entries:print(n)
