"""Rebuild a LOCAL synthetic database from repository schema files only.

No application data is loaded. DELETES the explicitly named test database.
"""
import os, re, zipfile
from pathlib import Path
import pymysql
name=os.environ.get('JOMA_TEST_DATABASE',''); host=os.environ.get('JOMA_TEST_HOST','')
if not re.fullmatch(r'joma_v2_test_[a-z0-9_]+',name) or host not in ('127.0.0.1','localhost') or os.environ.get('JOMA_TEST_ALLOW_RESET')!='YES':
    raise SystemExit('Refusing to reset a non-local or non-test database')
repo=Path(__file__).resolve().parents[2]
with zipfile.ZipFile(repo/'my (4).zip') as z:
    sources=[(f,z.read('my/database/'+f).decode('utf-8')) for f in ('phase1_schema.sql','phase2_schema.sql','phase3_schema.sql','phase4_schema.sql','phase4_2_schema.sql')]
for path in ('joma-patch-finance-stage1/database/phase5_finance_schema.sql','joma-patch-finance-stage2/database/phase5_2_payments_schema.sql','joma-patch-finance-stage3/database/phase5_3_settlements_schema.sql','joma-patch-finance-stage4/database/phase5_4_sales_schema.sql'):
    sources.append((path,(repo/path).read_text(encoding='utf-8')))
sources.append(('synthetic rooms upgrade',"ALTER TABLE rooms ADD COLUMN center_id BIGINT UNSIGNED NULL, ADD COLUMN room_type ENUM('SINGLE','COUPLE') NULL, ADD COLUMN hourly_rate DECIMAL(12,2) NULL"))
sources.append(('design-only.sql',(repo/'finance-redesign-wip/database/design-only.sql').read_text(encoding='utf-8')))
conn=pymysql.connect(host=host,port=int(os.environ['JOMA_TEST_PORT']),user=os.environ['JOMA_TEST_USER'],password=os.environ.get('JOMA_TEST_PASSWORD',''),charset='utf8mb4',autocommit=True)
with conn.cursor() as c:
    c.execute('DROP DATABASE IF EXISTS `'+name+'`')
    c.execute('CREATE DATABASE `'+name+'` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
    c.execute('USE `'+name+'`')
    for name,sql in sources:
        sql=re.sub(r'^\s*--.*$','',sql,flags=re.M)
        for statement in sql.split(';'):
            if not statement.strip(): continue
            try: c.execute(statement)
            except Exception as exc: raise RuntimeError('Schema failed: '+name+'; '+statement.strip()[:100]) from exc
print('Repository base schemas and WIP design installed in an empty LOCAL synthetic database.')
# Entirely fabricated sample records. No personal/site data.
with conn.cursor() as c:
    for i in range(1,5):
        c.execute("INSERT INTO persons (id,public_id,first_name,last_name,mobile_number,created_at) VALUES (%s,%s,'Synthetic','Sample',%s,NOW())",(i,'P'+str(i),'900000000'+str(i)))
    c.execute("INSERT INTO role_assignments (public_id,person_id,role_code,valid_from) VALUES ('ROLE1',1,'therapist',NOW())")
    c.execute("INSERT INTO service_types (id,public_id,code,title) VALUES (1,'S1','S1','Synthetic service')")
    c.execute("INSERT INTO lookup_lists (id,list_code,list_title) VALUES (1,'reason','Synthetic')")
    c.execute("INSERT INTO lookup_items (id,lookup_list_id,item_code,label) VALUES (1,1,'reason','Synthetic')")
    c.execute("INSERT INTO admissions (id,public_id,patient_person_id,referred_therapist_person_id,service_type_id,referral_reason_id,status,created_by_person_id,created_at) VALUES (1,'AD1',4,1,1,1,'ACCEPTED',2,NOW())")
    c.execute("INSERT INTO finance_centers (id,public_id,name,commission_model,created_at) VALUES (1,'C1','Synthetic center','HOURLY',NOW())")
    c.execute("INSERT INTO rooms (id,public_id,name,created_at,center_id,hourly_rate) VALUES (1,'R1','Synthetic room',NOW(),1,60000.00)")
    c.execute("INSERT INTO finance_bank_accounts (id,public_id,label,created_at) VALUES (1,'B1','Synthetic account',NOW())")
    c.execute("INSERT INTO booking_requests (id,public_id,first_name,last_name,mobile_number,mobile_verified_at,status,converted_admission_id,created_at,expires_at) VALUES (1,'BR1','Synthetic','Sample','9000000004',NOW(),'CONVERTED',1,NOW(),'2099-01-01')")
    c.execute("INSERT INTO appointments (id,public_id,admission_id,therapist_person_id,service_type_id,room_id,appointment_start_utc,appointment_end_utc,duration_minutes,status,created_by_person_id,created_by_role_code,created_at) VALUES (1,'AP1',1,1,1,1,'2026-10-20 09:00:00','2026-10-20 10:00:00',60,'COMPLETED',2,'admin',NOW())")
print('Fabricated records inserted; no real clinic data used.')
