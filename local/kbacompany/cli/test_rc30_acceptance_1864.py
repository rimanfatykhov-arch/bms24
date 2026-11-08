#!/usr/bin/env python3
"""Local, dependency-free acceptance contract plus canonical-key SQL regression."""
from pathlib import Path
import re
import sqlite3

root = Path(__file__).resolve().parents[1]
cli = (root / 'cli/rc30_acceptance.php').read_text()
svc = (root / 'classes/local/task_rc30_acceptance_service.php').read_text()
integrity = (root / 'classes/local/task_rc30_integrity_service.php').read_text()
upgrade = (root / 'db/upgrade.php').read_text()
assert "--strict" in cli and "--company=UUID" in cli and "--format=text|json" in cli
assert "return [" in svc and "'rc30_promotion_approved'=>false" in svc
assert len(re.findall(r"\['local_kbacompany_(?:task|tasklog|bplanitem)'", svc)) == 4
assert "if ($oldversion < 2026101864)" in upgrade
start = integrity.index("'duplicate_bms_plan_items' => [")
end = integrity.index("'missing_bms_task_link' => [", start)
frag = integrity[start:end]
assert "COALESCE(NULLIF(older.externalid,''),older.taskuuid)" in frag
assert "COALESCE(NULLIF(bi.externalid,''),bi.taskuuid)" in frag
assert 'older.planuuid=bi.planuuid' in frag
assert 'older.companyuuid=bi.companyuuid' in frag

c = sqlite3.connect(':memory:')
c.execute('CREATE TABLE item(id INTEGER PRIMARY KEY,companyuuid TEXT,planuuid TEXT,provider TEXT,externalid TEXT,taskuuid TEXT)')
# Mixed legacy and new references to the same task in the same plan -> duplicate.
# Different company and plan are NOT duplicates. Non-BMS references ignored.
items = [
 (1,'a','plan1','bms','','taskx'),
 (2,'a','plan1','bms','taskx','taskx'),
 (3,'a','plan1','bms','','taskx'),
 (4,'a','plan2','bms','','taskx'),
 (5,'b','plan1','bms','taskx','taskx'),
 (6,'a','plan1','external','taskx',''),
 (7,'a','plan1','bms','',''),
 (8,'a','plan1','bms','tasky','tasky'),
]
c.executemany('INSERT INTO item VALUES (?,?,?,?,?,?)',items)
query = '''SELECT COUNT(bi.id) FROM item bi
WHERE bi.provider='bms' AND COALESCE(NULLIF(bi.externalid,''),bi.taskuuid)<>''
AND EXISTS(SELECT 1 FROM item older WHERE older.companyuuid=bi.companyuuid
AND older.planuuid=bi.planuuid AND older.provider=bi.provider
AND COALESCE(NULLIF(older.externalid,''),older.taskuuid)=COALESCE(NULLIF(bi.externalid,''),bi.taskuuid)
AND older.id<bi.id) AND bi.companyuuid=?'''
assert c.execute(query,('a',)).fetchone()[0] == 2
assert c.execute(query,('b',)).fetchone()[0] == 0
c.close()
print('PASS: build1864 CLI contract, upgrade checkpoint and mixed legacy/modern link isolation')
