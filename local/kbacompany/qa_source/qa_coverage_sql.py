"""Standalone SQLite semantic QA for the exact SQL fragment built by the PHP service.
Not a substitute for Moodle DB / PHPUnit integration acceptance.
"""
import subprocess,json,sqlite3,re,time,datetime
php='''define('MOODLE_INTERNAL', true);
require 'classes/local/position_coverage_service.php';
list($sql,$params)=\\local_kbacompany\\local\\position_coverage_service::effective_executor_sql('t','smoke');
echo json_encode(['sql'=>$sql,'params'=>$params]);'''
p=subprocess.run(['php','-r',php],cwd='/mnt/data/bms_rc29_1634/kbacompany',capture_output=True,text=True,check=True)
item=json.loads(p.stdout)
stmt=item['sql'].replace('{local_kbacompany_poscover}','poscover').replace('{local_kbacompany_posassign}','posassign')
db=sqlite3.connect(':memory:')
db.executescript('''CREATE TABLE poscover (id INTEGER,companyuuid TEXT,positionuuid TEXT,primaryid INTEGER,delegateid INTEGER,status TEXT,scope TEXT,startdate INTEGER,enddate INTEGER);
CREATE TABLE posassign (id INTEGER,companyuuid TEXT,positionuuid TEXT,userid INTEGER,assignmenttype TEXT,active INTEGER,effectivefrom INTEGER,effectiveto INTEGER);
CREATE TABLE task (id INTEGER,companyuuid TEXT,assigneeid INTEGER,dutypositionuuid TEXT,status TEXT);''')
now=int(time.time());day=int(datetime.datetime.now(datetime.timezone.utc).replace(hour=0,minute=0,second=0,microsecond=0).timestamp())
db.executemany('INSERT INTO task VALUES(?,?,?,?,?)',[
(1,'A',10,'POS1','open'), (2,'A',10,'','open'),(3,'A',10,'POS1','done'),(4,'B',10,'POS1','open'),
(5,'A',10,'POS2','open'), (6,'A',11,'POS1','open'), (7,'A',10,'POS1','review')])
db.executemany('INSERT INTO posassign VALUES(?,?,?,?,?,?,?,?)',[(1,'A','POS1',10,'primary',1,0,0),(2,'B','POS1',10,'primary',1,0,0),(3,'A','POS2',10,'primary',0,0,0)])
db.executemany('INSERT INTO poscover VALUES(?,?,?,?,?,?,?,?,?)',[
(1,'A','POS1',10,20,'approved','position_work',day,day+86400),
(2,'B','POS1',10,30,'requested','position_work',day,day+86400),
(3,'A','POS2',10,40,'approved','position_work',day,day+86400),])
q='SELECT t.id, '+stmt+' AS effective FROM task t ORDER BY t.id'
rows=db.execute(q,item['params']).fetchall()
assert rows==[(1,20),(2,10),(3,10),(4,10),(5,10),(6,11),(7,10)],rows
# A coverage expires today -> original assignee, without updating any task row.
db.execute('UPDATE poscover SET enddate=? WHERE id=1',(day-86400,))
assert db.execute('SELECT '+stmt+' FROM task t WHERE t.id=1',item['params']).fetchone()[0]==10
# A cancelled coverage is never active.
db.execute('UPDATE poscover SET enddate=?,status=? WHERE id=1',(day+86400,'cancelled'))
assert db.execute('SELECT '+stmt+' FROM task t WHERE t.id=1',item['params']).fetchone()[0]==10
# Security: any other company never sees A's covering employee, original stays intact.
assert db.execute('SELECT '+stmt+' FROM task t WHERE t.id=4',item['params']).fetchone()[0]==10
print('PASS: position binding, personal task isolation, historical done/review, company isolation, inactive primary, cancellation, expiry')
