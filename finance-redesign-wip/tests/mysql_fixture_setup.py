"""Create synthetic fixtures ONLY in an explicitly authorised local test DB.

Requires PyMySQL. Drops the named test database; never use on a real site.
"""
import os
import re
from pathlib import Path
import pymysql
name = os.environ.get('JOMA_TEST_DATABASE', '')
host = os.environ.get('JOMA_TEST_HOST', '')
if (not re.fullmatch(r'joma_v2_test_[a-z0-9_]+', name)
        or os.environ.get('JOMA_TEST_ALLOW_RESET') != 'YES'
        or host not in ('127.0.0.1', 'localhost')):
    raise SystemExit('Refusing to reset: explicitly allow a local joma_v2_test_* database only')
root = Path(__file__).resolve().parent.parent
connection = pymysql.connect(host=host, port=int(os.environ['JOMA_TEST_PORT']),
    user=os.environ['JOMA_TEST_USER'], password=os.environ.get('JOMA_TEST_PASSWORD', ''),
    charset='utf8mb4', autocommit=True)
with connection.cursor() as cursor:
    cursor.execute('DROP DATABASE IF EXISTS `' + name + '`')
    cursor.execute('CREATE DATABASE `' + name + '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')
    cursor.execute('USE `' + name + '`')
    for path in (root/'tests/fixtures/minimal-mysql.sql', root/'database/design-only.sql'):
        sql = re.sub(r'^\s*--.*$', '', path.read_text(encoding='utf-8'), flags=re.M)
        for statement in sql.split(';'):
            if statement.strip():
                cursor.execute(statement)
print('Synthetic test database initialized.')
