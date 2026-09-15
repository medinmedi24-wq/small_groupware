"""Isolated HTTP acceptance tests. PHP_BIN points to a PHP 8.2+ CLI with required extensions."""
import contextlib
import html as html_module
import http.cookiejar
import io
import json
import os
from pathlib import Path
import re
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile
import xml.etree.ElementTree as ET

os.environ['MNM_FINAL_APPROVER_ID']='admin'
ROOT=Path(__file__).resolve().parents[1]
PHP=os.environ.get('PHP_BIN','php')
MYSQL_DSN=os.environ.get('MNM_TEST_MYSQL_DSN')
if MYSQL_DSN and 'dbname=mnm_php_test_' not in MYSQL_DSN: raise RuntimeError('Tests require an explicitly disposable mnm_php_test_ database')
PASSWORD='Test-password1!'

BOOT = "require " + json.dumps((ROOT/'app/bootstrap.php').as_posix()) + ";"
DOMAIN = "require " + json.dumps((ROOT/'app/domain.php').as_posix()) + ";"
SETUP = BOOT + DOMAIN + "require " + json.dumps((ROOT/'app/setup.php').as_posix()) + ";" + r"""
installSchema();
$people=[['admin','ADMIN','경영지원팀'],['staff','USER','개발팀'],['other','USER','영업팀'],['leader','TEAM_LEADER','개발팀'],['director','DIRECTOR','경영진'],['ceo','CEO','경영진'],['newhire','USER','개발팀'],['viral','USER','바이럴팀']];
foreach($people as [$id,$role,$dept])insert('User',['id'=>$id,'email'=>$id,'name'=>$id==='admin'?'테스트 관리자':$id,'department'=>$dept,'position'=>ROLES[$role],'role'=>$role,'joinDate'=>$id==='newhire'?gmdate('Y-m-d',strtotime('-6 months')).' 00:00:00':'2020-01-01 00:00:00','annualLeave'=>15,'annualLeaveOverride'=>$id==='newhire'?null:30,'passwordHash'=>password_hash('Test-password1!',PASSWORD_BCRYPT),'mustChangePassword'=>0,'createdAt'=>now(),'updatedAt'=>now()]);
insert('CompanyHoliday',['id'=>'holiday','date'=>'2026-12-25 00:00:00','name'=>'성탄절','createdAt'=>now()]);
echo "Fixtures prepared in isolated test database.\n";
"""
DOMAIN_CHECKS=BOOT+DOMAIN+r"""
function expect($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$u=['id'=>'staff','joinDate'=>'2024-02-29','annualLeaveOverride'=>null,'leaveBalanceAdjustment'=>0];
foreach(['2024-03-28'=>0.0,'2024-03-29'=>1.0,'2025-02-28'=>15.0,'2027-02-28'=>16.0,'2050-02-28'=>25.0,'2024-01-01'=>0.0] as $date=>$value)expect(allowance($u,$date)===$value,'accrual '.$date);
$x=leaveInput(['leaveType'=>'ANNUAL','startDate'=>'2026-12-24','endDate'=>'2026-12-28','reason'=>'holiday span']);expect($x['days']===2,'weekends and holidays excluded');
foreach([['ANNUAL','2026-12-25','2026-12-25'],['ANNUAL','2026-02-30','2026-02-30'],['ANNUAL','2026-12-31','2027-01-01']] as [$type,$start,$end]){try{leaveInput(['leaveType'=>$type,'startDate'=>$start,'endDate'=>$end,'reason'=>'invalid']);throw new RuntimeException('invalid leave accepted');}catch(AppError $e){expect(true,'invalid date or span rejected');}}
$monthly=leaveInput(['leaveType'=>'MONTHLY','startDate'=>'2026-12-24','endDate'=>'2026-12-28','reason'=>'monthly span']);expect($monthly['days']===2&&$monthly['leaveType']==='ANNUAL','monthly supports multiple days excluding weekends and holidays');
foreach(['AM_HALF','PM_HALF'] as $half){$data=leaveInput(['leaveType'=>$half,'startDate'=>'2026-12-24','endDate'=>'2027-01-02','reason'=>'half day']);expect($data['days']===0.5&&$data['startDate']===$data['endDate'],'half day normalizes end date '.$half);}
expect(validPassword('Test-password1!')&&!validPassword('short'),'password policy');
expect(koreanTime('2026-09-09 04:10:56')==='2026-09-09 13:10:56 KST','UTC timestamps display in Korean time');
expect(koreanTime('2026-12-31 18:30:00.000')==='2027-01-01 03:30:00 KST','Korean time crosses year boundary');
expect(koreanTime('2026-09-09T04:10:56.000Z')==='2026-09-09 13:10:56 KST','legacy ISO timestamps display in Korean time');
expect(koreanTime(null)===''&&koreanTime('')==='','missing timestamps remain empty');
"""
QUERY=BOOT+r"""$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);echo json_encode(rows($in['query'],$in['args']),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);"""


class Client:
    def __init__(self,base):
        self.base=base
        self.opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    def get(self,page='dashboard',**params):
        return self.request('index.php?'+urllib.parse.urlencode({'page':page,**params}))
    def request(self,path,data=None,headers=None):
        req=urllib.request.Request(self.base+'/'+path,data=data,headers=headers or {})
        try:
            with self.opener.open(req,timeout=20) as res:return res.status,res.read()
        except urllib.error.HTTPError as res:return res.code,res.read()
    def post(self,action,page='dashboard',csrf=True,**fields):
        params={'id':fields['id']} if page in ['employee','leave-edit','leave-cancel','card-edit'] and 'id'in fields else {}
        if page=='approvals' and 'stage'in fields:params['stage']=fields['stage']
        code,html=self.get(page,**params)
        token=re.search(rb'name="csrf" value="([a-f0-9]+)"',html)
        if action=='card_create' and 'registrationToken' not in fields:
            registration=re.search(rb'name="registrationToken" value="([a-f0-9]+)"',html)
            if registration:fields['registrationToken']=registration[1].decode()
        if action in ['card_edit','card_decide','card_cancel','card_receipt'] and 'id' in fields and 'reviewToken' not in fields:
            target='admin-cards' if action=='card_decide' else 'cards'
            token_page=self.get(target)[1]
            for form in re.findall(rb'<form\b[^>]*>.*?</form>',token_page,re.S):
                if ('name="id" value="'+fields['id']+'"').encode() in form:
                    review=re.search(rb'name="reviewToken" value="([a-f0-9]+)"',form)
                    if review: fields['reviewToken']=review[1].decode();break
        if action in ['leave_edit','leave_admin_cancel','leave_cancel','leave_decide'] and 'id' in fields and 'reviewToken' not in fields:
            target={'leave_edit':'leave-edit','leave_admin_cancel':'leave-cancel','leave_cancel':'leaves','leave_decide':'approvals'}[action]
            query={'id':fields['id']} if action in ['leave_edit','leave_admin_cancel'] else ({'stage':fields.get('stage','team')} if action=='leave_decide' else {})
            token_page=self.get(target,**query)[1]
            for form in re.findall(rb'<form\b[^>]*>.*?</form>',token_page,re.S):
                if ('name="id" value="'+fields['id']+'"').encode() in form:
                    review=re.search(rb'name="reviewToken" value="([a-f0-9]+)"',form)
                    if review: fields['reviewToken']=review[1].decode();break
        if action.startswith('attendance_') and 'id' in fields and 'requestToken' not in fields:
            for form in re.findall(rb'<form\b[^>]*>.*?</form>',html,re.S):
                if ('name="id" value="'+fields['id']+'"').encode() in form:
                    revision=re.search(rb'name="requestToken" value="([a-f0-9]+)"',form)
                    if revision: fields['requestToken']=revision[1].decode();break
        fields={'action':action,**fields,'csrf':token[1].decode() if csrf and token else 'invalid'}
        return self.request('index.php?'+urllib.parse.urlencode({'page':page,**params}),urllib.parse.urlencode(fields).encode())
    def login(self,name):
        code,html=self.post('login',page='login',email=name,password=PASSWORD)
        assert code==200,(name,code,html.decode()[:300])
        return self

class PHPRow(dict):
    def __getitem__(self,key):
        return list(self.values())[key] if isinstance(key,int) else super().__getitem__(key)
class PHPConnection:
    def __init__(self,env):self.env=env
    def execute(self,query,args=()):
        result=subprocess.run([PHP,'-r',QUERY],input=json.dumps({'query':query,'args':list(args)}),text=True,encoding='utf8',capture_output=True,env=self.env,check=True)
        self.result=json.loads(result.stdout)
        return self
    def fetchone(self):return PHPRow(self.result[0]) if self.result else None
    def fetchall(self):return [PHPRow(r) for r in self.result]
    def close(self):pass

def run():
    with tempfile.TemporaryDirectory(prefix='mnm-php-tests-') as tmp:
        work=Path(tmp);(work/'storage').mkdir();cfg=work/'config.php';db=work/'test.db'
        cfg.write_text("<?php return ['dsn'=>'"+(MYSQL_DSN or ('sqlite:'+db.as_posix()))+"','username'=>'"+('root' if MYSQL_DSN else '')+"','password'=>'','secure_cookie'=>false,'storage'=>'"+(work/'storage').as_posix()+"','setup_token'=>'','timezone'=>'Asia/Seoul'];",encoding='utf8')
        env={**os.environ,'MNM_CONFIG':str(cfg)}
        subprocess.run([PHP],input=('<?php '+SETUP).encode('utf8'),env=env,check=True)
        subprocess.run([PHP],input=('<?php '+DOMAIN_CHECKS).encode('utf8'),env=env,check=True)
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        with open(work/'server.log','w+',encoding='utf8') as log:
            server=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',str(ROOT/'public')],env=env,stdout=log,stderr=log)
            connection=None
            try:
                base=f'http://127.0.0.1:{port}'
                for _ in range(50):
                    try:Client(base).get();break
                    except OSError:time.sleep(.1)
                clients={name:Client(base).login(name) for name in ['admin','staff','other','leader','director','ceo','newhire','viral']}
                checks=[]
                def ok(condition,label):
                    assert condition,label
                    checks.append(label);print('PASS',label)
                for page,key in [('employee','id'),('approvals','stage'),('dashboard','page')]:
                    code,body=clients['admin'].request('index.php?'+urllib.parse.urlencode({'page':page,key+'[]':'invalid'}))
                    ok(code==400 and '주소의 요청 값을 확인해주세요.'.encode() in body,'array query rejected: '+key)
                a,s,o,l,d,c,n,v=[clients[k] for k in ['admin','staff','other','leader','director','ceo','newhire','viral']]
                for page in ['dashboard','leaves','leave-apply','cards','employees','employee','admin-leaves','admin-cards','settings','admin-history','admin-approvals','admin-card-history','password']:
                    code,html=a.get(page);ok(code==200 and b'React' not in html,'PHP page '+page)
                def preview(client, **params):
                    code, body=client.get('leave-preview', **params)
                    assert code==200, (code, body[:300])
                    return json.loads(body)
                preview_dates={'leaveType':'ANNUAL','startDate':'2026-12-24','endDate':'2026-12-28'}
                estimate=preview(s, **preview_dates)
                ok(estimate['days']==2 and estimate['deduction']==2 and estimate['available']==30 and estimate['after']==28,'preview excludes holidays and weekends with current capacity')
                half=preview(s, **{**preview_dates,'leaveType':'AM_HALF','endDate':'2027-01-02'})
                ok(half['days']==0.5 and half['after']==29.5,'preview normalizes half day before year validation')
                free=preview(s, **{**preview_dates,'leaveType':'PUBLIC_DUTY'})
                ok(free['deduction']==0 and free['after']==30 and free['shortage']==0,'non-deducting preview preserves available leave')
                shortage=preview(s, leaveType='ANNUAL',startDate='2026-01-01',endDate='2026-03-31')
                ok(shortage['shortage']==shortage['days']-30 and shortage['after']==0,'preview reports insufficient balance without negative availability')
                future=preview(s, leaveType='ANNUAL',startDate='2027-01-04',endDate='2027-01-04')
                ok(future['available']==30 and future['after']==29,'preview calculates selected year separately')
                for dates in [('2026-12-25','2026-12-25'),('2026-12-28','2026-12-24'),('2026-12-24','2027-01-04')]:
                    ok(s.get('leave-preview',leaveType='ANNUAL',startDate=dates[0],endDate=dates[1])[0]==400,'preview rejects invalid or nonworking span '+str(dates))
                ok(preview(s, **preview_dates, userId='other')==estimate,'preview cannot select another owner through userId')
                ok(b'name="action" value="login"' in Client(base).get('leave-preview',**preview_dates)[1],'preview requires authenticated session')
                stale=Client(base)
                code,html=stale.post('login',page='login',csrf=False,email='admin',password=PASSWORD)
                ok(code==200 and b'name="action" value="login"' in html and b'name="action" value="logout"' not in html,'expired login token rejected and fresh login form shown')
                stale.login('admin')
                ok(b'name="action" value="logout"' in stale.get()[1],'login succeeds after expired token recovery')
                ok(s.get('employees')[0]==403,'employee administration forbidden to staff')
                def employee_ids(**filters):
                    status,body=a.get('employees',**filters)
                    assert status==200
                    desktop=re.search(r'<div class="tablewrap admin-desktop-table"[^>]*>(.*?)</table>',body.decode(),re.S)[1]
                    mobile=re.search(r'<div class="admin-mobile-list">(.*?)</section>',body.decode(),re.S)[1]
                    pattern=r'class="employee-name" href="index.php\?page=employee&amp;id=([^"&]+)'
                    ids=re.findall(pattern,desktop)
                    assert ids==re.findall(pattern,mobile),'mobile and desktop employee results differ'
                    return ids
                ok(employee_ids(q='STAFF')==['staff'],'employee search matches ID ignoring case')
                ok(employee_ids(q='테스트 관리자')==['admin'],'employee search matches Korean name')
                ok(set(employee_ids(department='개발팀',active='1'))=={'staff','leader','newhire'},'employee filters combine department and active state')
                ok(employee_ids(q='staff',department='영업팀')==[],'employee filters use intersection')
                ok(employee_ids(active='0')==[],'inactive filter excludes active employees')
                ok(employee_ids(q='%')==[] and employee_ids(q='_')==[],'employee search treats wildcard characters literally')
                code,empty=a.get('employees',q='no-such-person')
                ok('검색 결과 0명 / 전체 8명'.encode() in empty and '조건에 맞는 직원이 없습니다'.encode() in empty,'employee empty results explain filters and count')
                ok(len(employee_ids())==8,'clearing filters restores all employees')

                staff_form=s.get('leave-apply')[1].decode()
                ok('신청 후 결재 순서' not in staff_form and '본인 수정·취소는 팀장 승인 대기 중에만 가능합니다.' in staff_form,'staff form shows edit deadline without approval route')
                ok('value="MONTHLY"' not in staff_form and 'value="SICK"' in staff_form,'leave options unify annual and include sick leave')
                leader_form=l.get('leave-apply')[1].decode()
                ok('신청 후 결재 순서' not in leader_form and '관리자 결재 전까지 본인 신청을 수정·취소할 수 있습니다.' in leader_form,'leader form explains changes before final review')
                ok('담당자 미지정' not in a.get('leave-apply')[1].decode(),'application form omits approver routing information')
                team_view=a.get('approvals',stage='team')[1].decode()
                ok('조회 전용' in team_view and '본인이 담당하는 신청을 승인' not in team_view,'administrator team stage explains read only access')

                ok(s.post('holiday_add',csrf=False,date='2026-12-24',name='test')[0]==403,'CSRF enforced')
                ok(s.post('employee_save',name='attack')[0]==403,'server enforces admin writes')
                def apply(client,start,end=None,typ='ANNUAL',reason='테스트 휴가'):
                    return client.post('leave_create',page='leave-apply',leaveType=typ,startDate=start,endDate=end or start,reason=reason)
                ok(apply(n,'2026-09-08')[0]==200,'new hire annual leave accepted')
                ok(apply(s,'2026-12-25')[0]==400,'holiday request rejected')
                ok(apply(s,'2026-09-08',reason='<script>alert(1)</script>')[0]==200,'create leave')
                connection=PHPConnection(env) if MYSQL_DSN else sqlite3.connect(db)
                if not MYSQL_DSN: connection.row_factory=sqlite3.Row
                def row(q,*args):return connection.execute(q,args).fetchone()
                leave=row("SELECT * FROM LeaveRequest WHERE userId='staff'")
                ok(s.post('leave_edit',id=leave['id'],leaveType='SPECIAL',startDate='2026-09-08',endDate='2026-09-08',reason='bypass')[0]==403,'staff cannot edit into special leave')
                ok(apply(n,'2026-09-09',typ='MONTHLY')[0]==200,'new hire monthly application')
                monthly=row("SELECT * FROM LeaveRequest WHERE userId='newhire' AND startDate LIKE '2026-09-09%'")
                ok(n.post('leave_edit',id=monthly['id'],leaveType='ANNUAL',startDate='2026-09-09',endDate='2026-09-09',reason='bypass')[0]==200,'new hire can edit annual leave')
                ok(row('SELECT leaveType FROM LeaveRequest WHERE id=?',monthly['id'])[0]=='ANNUAL','legacy monthly input saved as annual leave')
                ok(n.post('leave_edit',id=monthly['id'],leaveType='MONTHLY',startDate='2026-09-09',endDate='2026-09-10',reason='two days monthly')[0]==200 and row('SELECT days FROM LeaveRequest WHERE id=?',monthly['id'])[0]==2,'monthly edit accepts multiday period')
                ok(a.post('employee_reset',id='admin')[0]==400,'self reset cannot lock out administrator')
                ok(b'name="action" value="logout"' in a.get()[1],'self reset rejection preserves session')

                code,html=s.get('leaves');ok(b'&lt;script&gt;' in html and b'<script>alert' not in html,'stored text escaped')
                ok('팀장 승인 대기' in html.decode() and '현재 담당:' not in html.decode(),'pending leave shows status without approver caption')
                ok(apply(s,'2026-09-08')[0]==409,'overlap prevented')
                ok(o.post('leave_cancel',id=leave['id'])[0]==409,'other user cannot cancel')
                ok(o.get('leave-edit',id=leave['id'])[0]==403,'other user cannot edit')
                own_estimate=preview(s, **preview_dates, id=leave['id'])
                new_estimate=preview(s, **preview_dates)
                ok(own_estimate['available']==new_estimate['available']+float(leave['days']),'preview excludes the pending request being edited')
                ok(preview(a, **preview_dates, id=leave['id'])==own_estimate,'admin edit preview uses request owner balance')
                ok(o.get('leave-preview',**preview_dates,id=leave['id'])[0]==403,'preview denies another employees request')
                ok(s.post('leave_decide',id=leave['id'],stage='team',decision='APPROVE')[0]==403,'staff cannot approve')
                filtered=l.get('approvals',stage='team',q='STAFF',**{'from':'2026-09-08','to':'2026-09-08'})[1].decode()
                ok('검색 결과 1건' in filtered and 'name="return_q" value="STAFF"' in filtered,'team name and date filters combine and preserve form context')
                ok('page=team-processed&amp;q=STAFF&amp;from=2026-09-08&amp;to=2026-09-08' in filtered,'team tab preserves filters')
                ok('검색 결과 0건' in l.get('approvals',stage='team',q='%')[1].decode(),'team search treats percent literally')
                ok('검색 결과 1건' in l.get('approvals',stage='team',q='newhire',**{'from':'2026-09-10','to':'2026-09-10'})[1].decode(),'team date filter includes overlapping final day')
                ok('검색 결과 0건' in l.get('approvals',stage='team',q='staff',**{'from':'2026-09-09'})[1].decode(),'team open date range excludes earlier leave')
                ok('종료는 시작보다' in l.get('approvals',stage='team',**{'from':'2026-09-10','to':'2026-09-08'})[1].decode(),'team invalid date order explained')
                ok(l.get('approvals',stage='team',q='x'*101)[0]==400,'team excessive query rejected')
                ok('검색 결과 0건' in l.get('approvals',stage='team',q='other')[1].decode(),'team filter cannot expose other department')
                code,after=l.post('leave_decide',page='approvals',id=leave['id'],stage='team',decision='APPROVE',return_page='approvals',return_q='STAFF',return_from='2026-09-08',return_to='2026-09-08')
                ok(code==200,'team approves')
                ok('name="q" type="search" value="STAFF"' in after.decode() and '검색 결과 0건' in after.decode() and '내가 처리한 신청에서 확인' in after.decode(),'team approval retains filter and explains removed result')
                ok(l.post('leave_decide',page='approvals',id=leave['id'],stage='team',decision='APPROVE')[0]==409,'stale decision rejected')
                ok(row('SELECT status FROM LeaveRequest WHERE id=?',leave['id'])[0]=='PENDING_CEO','team approval routes directly to final approver')
                ok('관리자 승인 대기' in s.get('leaves')[1].decode() and '현재 담당:' not in s.get('leaves')[1].decode(),'team approval updates status without approver caption')
                ok(s.get('leave-edit',id=leave['id'])[0]==403 and s.post('leave_edit',id=leave['id'],leaveType='ANNUAL',startDate='2026-09-08',endDate='2026-09-08',reason='after team approval')[0]==403 and s.post('leave_cancel',id=leave['id'])[0]==409,'employee cannot alter request after team approval')
                ok(d.post('leave_decide',page='approvals',id=leave['id'],stage='director',decision='APPROVE')[0]==400,'removed director stage cannot approve')
                ok(d.post('leave_decide',page='approvals',id=leave['id'],stage='ceo',decision='APPROVE')[0]==403,'director cannot act as final approver')
                ok(c.post('leave_decide',page='approvals',id=leave['id'],stage='ceo',decision='APPROVE')[0]==403,'former CEO cannot approve')
                ok(a.post('employee_save',page='employee',email='secondadmin',password=PASSWORD,name='Secondary admin',department='test',position='test',role='ADMIN',joinDate='2020-01-01',leaveAccrual='MANUAL',annualLeave='30',isActive='1')[0]==200,'create nondesignated administrator')
                for role,active in [('USER','1'),('ADMIN','0')]:
                    ok(a.post('employee_save',id='admin',name='Test admin',department='test',position='test',role=role,joinDate='2020-01-01',leaveAccrual='MANUAL',annualLeave='30',isActive=active)[0]==400,'designated approver protected '+role+active)
                secondary=Client(base).login('secondadmin')
                secondary.post('password',page='password',currentPassword=PASSWORD,newPassword='Secondary-password2!',confirmPassword='Secondary-password2!')
                ok(secondary.post('leave_decide',page='approvals',id=leave['id'],stage='ceo',decision='APPROVE')[0]==403,'other administrator cannot give final approval')
                ok(a.post('leave_decide',page='approvals',id=leave['id'],stage='ceo',decision='APPROVE')[0]==200,'designated admin final approval')
                ok(row('SELECT status FROM LeaveRequest WHERE id=?',leave['id'])[0]=='APPROVED','approved status persisted')
                ok(row('SELECT COUNT(*) FROM LeaveHistory WHERE leaveRequestId=?',leave['id'])[0]==3,'full two-stage approval audit trail')
                ok(apply(v,'2026-09-09')[0]==200 and row("SELECT status FROM LeaveRequest WHERE userId='viral'")[0]=='PENDING_TEAM_LEADER','all employee departments require team approval')
                ok(apply(l,'2026-09-09')[0]==200 and row("SELECT status FROM LeaveRequest WHERE userId='leader'")[0]=='PENDING_CEO','team leader routes directly to final approver')
                ok(apply(d,'2026-09-09')[0]==200,'director application succeeds')
                director_leave=row("SELECT * FROM LeaveRequest WHERE userId='director'")
                ok((director_leave['status'],director_leave['teamLeaderStatus'],director_leave['directorStatus'])==('PENDING_CEO','SKIPPED','SKIPPED'),'director skips team approval and awaits final approver')
                ok(l.post('leave_decide',page='approvals',id=director_leave['id'],stage='team',decision='APPROVE')[0] in (403,409),'team leader cannot approve director request')
                ok(a.post('leave_decide',page='approvals',id=director_leave['id'],stage='ceo',decision='APPROVE')[0]==200,'designated admin approves director request directly')
                ok(apply(s,'2026-09-10',typ='AM_HALF')[0]==200,'half-day application')
                half=row("SELECT * FROM LeaveRequest WHERE userId='staff' AND leaveType='AM_HALF'")
                ok(s.post('leave_cancel',id=half['id'])[0]==200,'owner cancels pending request')
                ok(apply(s,'2026-09-11',reason='reject me')[0]==200,'request for rejection')
                reject=row("SELECT * FROM LeaveRequest WHERE reason='reject me'")
                ok(l.post('leave_decide',page='approvals',id=reject['id'],stage='team',decision='REJECT',reason='')[0]==400,'rejection requires reason')
                ok(l.post('leave_decide',page='approvals',id=reject['id'],stage='team',decision='REJECT',reason='일정 조정')[0]==200,'rejection with reason')
                ok(a.post('employee_balance',id='staff',targetBalance='12.5',effectiveDate='2026-09-07',reason='이전 잔여 반영')[0]==200,'leave balance adjustment')
                ok(row("SELECT COUNT(*) FROM LeaveBalanceAdjustment WHERE userId='staff'")[0]==1,'adjustment audit recorded')
                ok(s.post('card_create',page='cards',usedAt='2026-09-07',merchant='테스트 상점',amount='12000',purpose='업무 식사',isFixed='',hasReceipt='1',hasApprovalDocument='')[0]==200,'card registration')
                card=row('SELECT * FROM CardExpense')
                ok((card['isFixed'],card['hasReceipt'],card['hasApprovalDocument'])==(0,1,0),'card O/X choices stored correctly')
                ok(o.post('card_cancel',id=card['id'])[0]==403,'other user cannot cancel card')
                # Exercise real multipart uploads; file name and MIME cannot bypass server checks.
                def upload(client,filename,content,card_id=None):
                    token=re.search(rb'name="csrf" value="([a-f0-9]+)"',client.get('cards')[1])[1].decode()
                    boundary='----MNMTestBoundary'
                    chunks=[]
                    review_page=client.get('cards')[1]
                    review_form=next(f for f in re.findall(rb'<form\b[^>]*>.*?</form>',review_page,re.S) if ('name="id" value="'+(card_id or card['id'])+'"').encode() in f)
                    review=re.search(rb'name="reviewToken" value="([a-f0-9]+)"',review_form)[1].decode()
                    for key,value in {'csrf':token,'action':'card_receipt','id':card_id or card['id'],'reviewToken':review}.items():chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
                    chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="receipt"; filename="{filename}"\r\nContent-Type: application/pdf\r\n\r\n'.encode()+content+b'\r\n')
                    chunks.append(f'--{boundary}--\r\n'.encode())
                    return client.request('index.php?page=cards',b''.join(chunks),{'Content-Type':f'multipart/form-data; boundary={boundary}'})
                ok(upload(s,'fake.pdf',b'<?php echo "unsafe";')[0]==400,'file content MIME validated')
                pdf=b'%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF'
                if not MYSQL_DSN:
                    connection.execute('UPDATE CardExpense SET hasReceipt=0 WHERE id=?',(card['id'],))
                    connection.commit()
                ok(upload(s,'receipt.pdf',pdf)[0]==200,'PDF upload')
                ok(row('SELECT hasReceipt FROM CardExpense WHERE id=?',card['id'])[0]==1,'receipt upload synchronizes receipt flag')
                ok(s.get('receipt',id=card['id'])[1]==pdf,'owner receipt download')
                ok(o.get('receipt',id=card['id'])[0]==404,'receipt privacy enforced')
                ok(a.get('receipt',id=card['id'])[1]==pdf,'administrator receipt download')
                previous_receipt=row('SELECT receiptFilePath FROM CardExpense WHERE id=?',card['id'])[0]
                receipt_files=set((work/'storage/receipts').iterdir())
                ok(upload(s,'invalid.pdf',b'not a PDF')[0]==400 and s.get('receipt',id=card['id'])[1]==pdf and set((work/'storage/receipts').iterdir())==receipt_files,'failed additional upload retains original receipt and leaves no new file')
                replacement_pdf=pdf+b'\n% replacement'
                if not MYSQL_DSN:
                    with contextlib.closing(sqlite3.connect(db)) as fail_db:
                        fail_db.execute("CREATE TRIGGER qa_receipt_update_failure BEFORE UPDATE OF receiptAttachments ON CardExpense BEGIN SELECT RAISE(ABORT,'QA rollback'); END")
                    try:
                        failed_code=upload(s,'rollback.pdf',replacement_pdf)[0]
                        ok(failed_code>=400 and s.get('receipt',id=card['id'])[1]==pdf and set((work/'storage/receipts').iterdir())==receipt_files,'DB rollback removes new upload while preserving original receipt')
                    finally:
                        with contextlib.closing(sqlite3.connect(db)) as fail_db:fail_db.execute('DROP TRIGGER qa_receipt_update_failure')
                ok(upload(s,'additional.pdf',replacement_pdf)[0]==200,'additional receipt succeeds')
                attachments=json.loads(row('SELECT receiptAttachments FROM CardExpense WHERE id=?',card['id'])[0])
                extra=attachments[0]['receiptFilePath']
                ok((work/'storage/receipts'/previous_receipt).exists() and len(set((work/'storage/receipts').iterdir()))==len(receipt_files)+1 and s.get('receipt',id=card['id'])[1]==pdf,'additional upload preserves original file and default download')
                ok(s.get('receipt',id=card['id'],attachment=extra)[1]==replacement_pdf and a.get('receipt',id=card['id'],attachment=extra)[1]==replacement_pdf,'owner and administrator download additional proof')
                ok(o.get('receipt',id=card['id'],attachment=extra)[0]==404 and s.get('receipt',id=card['id'],attachment='../'+extra)[0]==404,'additional proof rejects other owners and invalid paths')
                listing=s.get('cards')[1]
                ok(b'additional.pdf' in listing and b'receipt.pdf' in listing and '추가 증빙 첨부'.encode() in listing,'all proofs and additive label visible')
                assert upload(s,'third.pdf',pdf)[0]==200
                ok(len(json.loads(row('SELECT receiptAttachments FROM CardExpense WHERE id=?',card['id'])[0]))==2,'third proof accumulates without replacing either earlier proof')
                ok(a.post('card_decide',id=card['id'],decision='APPROVE')[0]==200,'administrator card approval')
                ok(s.post('card_cancel',id=card['id'])[0]==409,'approved card immutable')
                code,xlsx=a.get('report',year=2026);ok(code==200 and xlsx[:2]==b'PK','real XLSX download')
                with zipfile.ZipFile(io.BytesIO(xlsx)) as archive:
                    for name in archive.namelist():ET.fromstring(archive.read(name))
                    xml=archive.read('xl/worksheets/sheet1.xml');ok(b'<f>' not in xml,'spreadsheet values stored without formula execution')
                ok(s.get('report',year=2026)[0]==403,'report restricted to administrator')
                ok(a.post('employee_save',page='employee',email='fresh',password=PASSWORD,name='신규 직원',department='개발팀',position='사원',role='USER',joinDate='2026-03-01',leaveAccrual='AUTOMATIC',isActive='1')[0]==200,'create employee')
                fresh=Client(base).login('fresh');ok('비밀번호 변경'.encode() in fresh.get()[1],'first login password change required')
                ok(apply(fresh,'2026-09-14')[0]==403,'forced password change blocks writes')
                ok(fresh.post('password',page='password',currentPassword=PASSWORD,newPassword='Changed-password2!',confirmPassword='Changed-password2!')[0]==200,'password change')
                ok(a.post('employee_reset',id='other')[0]==200,'administrator password reset')
                ok(b'name="action" value="login"' in o.get()[1],'password reset invalidates existing sessions')
                for amount,state in [(2000,'CANCELLED'),(3000,'PENDING'),(4000,'REJECTED')]:
                    assert s.post('card_create',page='cards',usedAt='2026-11-03',merchant='Filter Shop',amount=str(amount),purpose='filter fixture')[0]==200
                    new_card=row('SELECT id FROM CardExpense WHERE amount=?',amount)[0]
                    if state=='CANCELLED':assert s.post('card_cancel',id=new_card)[0]==200
                    if state=='REJECTED':assert a.post('card_decide',id=new_card,decision='REJECT',reason='filter fixture')[0]==200
                def card_result(**filters):
                    status,body=a.get('admin-cards',**filters)
                    assert status==200
                    text=body.decode()
                    match=re.search(r'검색 결과 (\d+)건 / 전체 (\d+)건',text)
                    amount=re.search(r'검색 결과 금액 합계: ([\d,]+)원',text)
                    assert match and amount,text[:300]
                    return int(match[1]),int(amount[1].replace(',',''))
                ok(card_result(q='STAFF',merchant='filter',status='PENDING',**{'from':'2026-11-03','to':'2026-11-03'})==(1,3000),'card filters combine employee merchant dates and status')
                ok(card_result(merchant='FILTER SHOP',**{'from':'2026-11-03','to':'2026-11-03'})==(3,9000),'card date boundaries inclusive and total includes rejected cancelled')
                ok(card_result(merchant='Filter',**{'from':'2026-11-04'})==(0,0),'card lower date filter excludes earlier expenses')
                ok(card_result(merchant='Filter',**{'to':'2026-11-02'})==(0,0),'card upper date filter excludes later expenses')
                ok(card_result(status='CANCELLED')==(1,2000),'card cancelled status and sum')
                ok(card_result(status='REJECTED')==(1,4000),'card rejected status and sum')
                ok(card_result(status='APPROVED')==(1,12000),'card approved amount excludes other states')
                ok(card_result(merchant='%')==(0,0) and card_result(q='_')==(0,0),'card filter escapes wildcard characters')
                ok(card_result()==(4,21000),'card reset restores full count and total')
                code,invalid=a.get('admin-cards',**{'from':'2026-11-04','to':'2026-11-03'})
                ok('종료는 시작보다 빠를 수 없습니다'.encode() in invalid and b'name="from"' in invalid,'card invalid date range retains editable filters')
                ok(s.get('admin-cards',q='staff')[0]==403,'card filters preserve admin access restriction')
                employee_html=a.get('employees',q='staff',department='개발팀',active='1')[1]
                ok(b'return_page=employees' in employee_html and b'return_q=staff' in employee_html,'employee links carry list context without session globals')
                code,employee_saved=a.post('employee_save',page='employee',id='staff',name='Updated Staff',department='개발팀',position='직원',role='USER',joinDate='2020-01-01',leaveAccrual='MANUAL',annualLeave='30',isActive='1',return_page='employees',return_q='staff',return_department='개발팀',return_active='1')
                ok(code==200 and b'name="q" type="search" value="staff"' in employee_saved and '검색 결과 1명'.encode() in employee_saved,'employee save returns to same combined filters')
                leave_directory=a.get('admin-leaves',q='staff',department='개발팀',status='APPROVED')[1].decode()
                ok('data-preserve-list-scroll' in leave_directory and 'page=employee&amp;id=staff&amp;return_page=admin-leaves' in leave_directory,'leave list links employee with original filter context')
                leave_context={'return_page':'admin-leaves','return_q':'staff','return_department':'개발팀','return_status':'APPROVED','return_scroll':'1240'}
                detail=a.get('employee',id='staff',**leave_context)[1].decode()
                ok('← 연차 관리 목록' in detail and 'page=admin-leaves&amp;q=staff&amp;department=' in detail and '&amp;scroll=1240' in detail and 'name="return_scroll" value="1240"' in detail,'employee detail retains leave filters and position in links and forms')
                code,detail_saved=a.post('employee_save',page='employee',id='staff',name='Updated Staff',department='개발팀',position='직원',role='USER',joinDate='2020-01-01',leaveAccrual='MANUAL',annualLeave='30',isActive='1',**leave_context)
                ok(code==200 and '← 연차 관리 목록'.encode() in detail_saved and b'employee-identity' in detail_saved and b'scroll=1240' in detail_saved,'save from leave list stays on employee detail with return context')
                balance_route=BOOT+"require "+json.dumps((ROOT/'app/actions.php').as_posix())+"; echo json_encode(returnToFilteredList(['employee','saved',['id'=>'staff']],'employee_balance',['role'=>'ADMIN'],['id'=>'staff','return_page'=>'admin-leaves','return_q'=>'staff','return_scroll'=>'1240']));"
                balance_return=json.loads(subprocess.check_output([PHP,'-r',balance_route],env=env))
                ok(balance_return[0]=='employee' and balance_return[2]['return_scroll']=='1240' and balance_return[2]['return_q']=='staff','balance save retains leave list return context')
                bad_scroll=a.get('employee',id='staff',**{**leave_context,'return_scroll':'-10'})[1]
                ok(b'return_scroll' not in bad_scroll and b'scroll=-10' not in bad_scroll,'invalid list position is not propagated')
                pending_card=row("SELECT id FROM CardExpense WHERE amount=3000")[0]
                code,card_saved=a.post('card_decide',page='admin-cards',id=pending_card,decision='APPROVE',return_page='admin-cards',return_q='staff',return_merchant='Filter',return_status='PENDING',return_from='2026-11-03',return_to='2026-11-03')
                ok(code==200 and '검색 결과 0건'.encode() in card_saved and '선택한 상태와 달라 목록에서 제외'.encode() in card_saved and b'value="Filter"' in card_saved,'card decision preserves filters and explains removed row')
                code,leave_saved=a.post('leave_edit',page='leave-edit',id=leave['id'],leaveType='ANNUAL',startDate='2026-09-08',endDate='2026-09-08',reason='edited from filtered list',return_page='admin-leaves',return_q='staff',return_status='APPROVED',return_from='2026-09-08',return_to='2026-09-08')
                ok(code==200 and '검색 결과 1건'.encode() in leave_saved and b'value="2026-09-08"' in leave_saved and '검색 조건을 유지'.encode() in leave_saved,'leave edit returns to original date and status filters')
                safe_code=BOOT+"require "+json.dumps((ROOT/'app/actions.php').as_posix())+"; echo json_encode(returnToFilteredList(['employees','saved'],'employee_save',['role'=>'ADMIN'],['return_page'=>'https://example.invalid','return_q'=>'x']));"
                safe=json.loads(subprocess.check_output([PHP,'-r',safe_code],env=env))
                ok(safe==['employees','saved'],'unapproved return destination is ignored')
                ok(apply(s,'2026-11-02','2026-11-04')[0]==200,'create spanning leave for filter regression')
                def leave_result_count(**filters):
                    status,body=a.get('admin-leaves',**filters)
                    assert status==200
                    match=re.search(r'검색 결과 (\d+)건 / 전체 (\d+)건',body.decode())
                    assert match,body.decode()[:300]
                    return int(match[1])
                ok(leave_result_count(q='STAFF',department='개발팀',status='PENDING_TEAM_LEADER',**{'from':'2026-11-03','to':'2026-11-03'})==1,'leave filters combine employee department state and overlapping date')
                ok(leave_result_count(q='staff',**{'from':'2026-11-04','to':'2026-11-04'})==1,'leave date upper and lower boundaries are inclusive')
                ok(leave_result_count(q='staff',**{'from':'2026-11-05','to':'2026-11-05'})==0,'leave filter excludes nonoverlapping period')
                ok(leave_result_count(q='staff',department='영업팀')==0,'leave department filter intersects employee search')
                ok(leave_result_count(q='%',status='CANCELLED')==0,'leave search escapes wildcard characters')
                cancelled=row("SELECT COUNT(*) FROM LeaveRequest WHERE isDeleted=0 AND status='CANCELLED'")[0]
                ok(leave_result_count(status='CANCELLED')==cancelled,'leave status filter includes cancelled records only')
                total=row('SELECT COUNT(*) FROM LeaveRequest WHERE isDeleted=0')[0]
                ok(leave_result_count()==total,'leave filter reset returns full list')
                progress_count=row("SELECT COUNT(*) FROM LeaveRequest WHERE isDeleted=0 AND status IN ('PENDING_TEAM_LEADER','PENDING_DIRECTOR','PENDING_CEO')")[0]
                ok(leave_result_count(status='IN_PROGRESS')==progress_count,'dashboard progress filter matches all pending stages only')
                dashboard_links=html_module.unescape(a.get()[1].decode())
                for label,target in [('재직 중 직원','index.php?page=employees&active=1'),('전체 휴가 신청','index.php?page=admin-leaves'),('휴가 결재 진행','index.php?page=admin-leaves&status=IN_PROGRESS'),('휴가 승인 완료','index.php?page=admin-leaves&status=APPROVED'),('휴가 반려','index.php?page=admin-leaves&status=REJECTED')]:
                    ok(f'<a class="card dashboard-metric-link" href="{target}"><span>{label}</span>' in dashboard_links,'dashboard card links to matching list: '+label)
                # Follow rendered card shortcuts: period/status and resulting counts must agree.
                for year,month,last in [(2026,9,30),(2026,8,31),(2025,12,31),(2024,2,29)]:
                    rendered=html_module.unescape(a.get('dashboard',year=str(year),month=str(month))[1].decode())
                    for label,status in [('검토 대기','PENDING'),('승인 완료','APPROVED')]:
                        match=re.search(r'<a class="card dashboard-metric-link" href="([^"]+)"><span>'+label+r'</span><strong>(\d+)<small>건</small></strong>(.*?)</a>',rendered,re.S)
                        assert match is not None,(year,month,label)
                        query=dict(urllib.parse.parse_qsl(urllib.parse.urlsplit(match[1]).query))
                        start=f'{year}-{month:02d}-01';end=f'{year}-{month:02d}-{last}'
                        ok(query==dict(page='admin-cards',**{'from':start,'to':end,'status':status}) and 'dashboard-metric-arrow' in match[3],'card shortcut preserves selected month status and visible arrow '+start+' '+status)
                        code,destination=a.request(match[1])
                        expected=row('SELECT COUNT(*) FROM CardExpense WHERE usedAt>=? AND usedAt<? AND status=?',start,end+' 23:59:59',status)[0]
                        ok(code==200 and int(match[2])==expected and ('검색 결과 '+str(expected)+'건').encode() in destination,'card metric and destination counts agree '+start+' '+status)
                ok(s.get('admin-cards',**{'from':'2026-09-01','to':'2026-09-30','status':'PENDING'})[0]==403,'card shortcut filters do not grant employee access')
                code,invalid=a.get('admin-leaves',**{'from':'2026-11-05','to':'2026-11-02'})
                ok('종료는 시작보다 빠를 수 없습니다'.encode() in invalid and b'name="from"' in invalid,'invalid leave range retains editable filters')
                ok(s.get('admin-leaves',q='staff')[0]==403,'leave filters preserve administrator access restriction')
                direct_id=row("SELECT id FROM LeaveRequest WHERE userId='staff' AND startDate='2026-11-02 00:00:00'")[0]
                direct_filters={'q':'STAFF','department':'개발팀','from':'2026-11-03','to':'2026-11-03','status':'PENDING_CEO'}
                ok(b'name="action" value="leave_decide"' not in a.get('admin-leaves',status='PENDING_TEAM_LEADER')[1],'admin list cannot bypass team approval')
                assert l.post('leave_decide',id=direct_id,stage='team',decision='APPROVE')[0]==200
                direct_body=a.get('admin-leaves',**direct_filters)[1]
                direct_forms=re.findall(rb'<form\b[^>]*>.*?</form>',direct_body,re.S)
                decision_forms=[f for f in direct_forms if b'name="action" value="leave_decide"' in f]
                ok(len(decision_forms)==2 and all(b'name="stage" value="ceo"' in f and b'name="return_q" value="STAFF"' in f and b'name="return_status" value="PENDING_CEO"' in f for f in decision_forms),'admin search row exposes final decisions with filter context')
                ok(b'data-confirm=' in decision_forms[0] and b'name="reason" required' in decision_forms[1] and b'page=leave-edit' in direct_body,'inline decisions retain approval confirmation rejection reason and edit link')
                ok(b'name="action" value="leave_decide"' not in secondary.get('admin-leaves',**direct_filters)[1],'nondesignated admin sees no inline decision controls')
                direct_context={'return_page':'admin-leaves',**{'return_'+k:v for k,v in direct_filters.items()}}
                ok(secondary.post('leave_decide',page='admin-leaves',id=direct_id,stage='ceo',decision='APPROVE',**direct_context)[0]==403,'inline return context cannot grant final approval authority')
                code,direct_saved=a.post('leave_decide',page='admin-leaves',id=direct_id,stage='ceo',decision='APPROVE',**direct_context)
                ok(code==200 and row('SELECT status FROM LeaveRequest WHERE id=?',direct_id)[0]=='APPROVED' and '검색 결과 0건'.encode() in direct_saved and b'value="STAFF"' in direct_saved and b'value="2026-11-03"' in direct_saved and '선택한 상태와 달라 목록에서 제외'.encode() in direct_saved,'inline approval preserves search and explains removed pending result')
                ok(a.post('leave_decide',page='admin-leaves',id=direct_id,stage='ceo',decision='APPROVE',**direct_context)[0]==409,'inline duplicate decision rejected')
                ok(b'name="action" value="leave_decide"' not in a.get('admin-leaves',status='APPROVED')[1],'completed admin rows have no decision buttons')
                assert apply(l,'2026-11-09')[0]==200
                reject_id=row("SELECT id FROM LeaveRequest WHERE userId='leader' AND startDate='2026-11-09 00:00:00'")[0]
                reject_context={'return_page':'admin-leaves','return_q':'leader','return_status':'PENDING_CEO'}
                ok(a.post('leave_decide',page='admin-leaves',id=reject_id,stage='ceo',decision='REJECT',reason='',**reject_context)[0]==400 and row('SELECT status FROM LeaveRequest WHERE id=?',reject_id)[0]=='PENDING_CEO','inline rejection requires reason without changing request')
                code,rejected=a.post('leave_decide',page='admin-leaves',id=reject_id,stage='ceo',decision='REJECT',reason='일정 재확인 요청',**reject_context)
                ok(code==200 and row('SELECT ceoRejectReason FROM LeaveRequest WHERE id=?',reject_id)[0]=='일정 재확인 요청' and b'value="leader"' in rejected and '선택한 상태와 달라 목록에서 제외'.encode() in rejected,'inline rejection saves reason and returns to filtered list')
                ok(s.post('logout')[0]==200,'logout')
                ok(b'name="action" value="login"' in s.get()[1],'logged out session blocked')
                # Regression: cancelled requests are not pending; balance separates reservations.
                pending_count=row("SELECT COUNT(*) FROM LeaveRequest WHERE isDeleted=0 AND status IN ('PENDING_TEAM_LEADER','PENDING_DIRECTOR','PENDING_CEO')")[0]
                dashboard=a.get('dashboard')[1].decode()
                ok(re.search(r'<span>휴가 결재 진행</span><strong>'+str(pending_count)+r'<small>건</small></strong>',dashboard) is not None,'dashboard excludes cancelled requests from progress')
                summary_code=BOOT+DOMAIN+"echo json_encode(balance(one('SELECT * FROM `User` WHERE id=?',['staff'])));"
                summary=json.loads(subprocess.check_output([PHP,'-r',summary_code],env=env).decode())
                reserved=row("SELECT COALESCE(SUM(days),0) FROM LeaveRequest WHERE userId='staff' AND isDeleted=0 AND status IN ('PENDING_TEAM_LEADER','PENDING_DIRECTOR','PENDING_CEO') AND leaveType IN ('ANNUAL','MONTHLY','AM_HALF','PM_HALF') AND startDate>=? AND startDate<?",time.strftime('%Y')+'-01-01',str(int(time.strftime('%Y'))+1)+'-01-01')[0]
                ok(summary['pending']==reserved and summary['available']==max(0,summary['remaining']-reserved),'balance separates pending from remaining and clamps availability')
                current_year=int(time.strftime('%Y'))
                code,detail=a.get('employee',id='staff',year=current_year)
                ok(code==200 and b'name="action" value="employee_balance"' in detail and b'employee-identity' in detail,'current employee detail identifies target and permits adjustment')
                for historical_year in [current_year-1,current_year+1]:
                    code,detail=a.get('employee',id='staff',year=historical_year)
                    ok(code==200 and b'name="action" value="employee_balance"' not in detail,'noncurrent year is read only '+str(historical_year))
                token=re.search(rb'name="csrf" value="([a-f0-9]+)"',a.get('employee',id='staff')[1])[1].decode()
                before=row("SELECT leaveBalanceAdjustment FROM User WHERE id='staff'")[0]
                code,_=a.request('index.php?'+urllib.parse.urlencode({'page':'employee','id':'staff','year':current_year-1}),urllib.parse.urlencode({'action':'employee_balance','csrf':token,'id':'staff','targetBalance':'99','effectiveDate':time.strftime('%Y-%m-%d'),'reason':'past year blocked'}).encode())
                ok(code==409 and row("SELECT leaveBalanceAdjustment FROM User WHERE id='staff'")[0]==before,'past year adjustment rejected without mutation')
                for role,label,day in [('ASSISTANT_MANAGER','대리','07'),('MANAGER','과장','08'),('GENERAL_MANAGER','부장','09')]:
                    code,_=a.post('employee_save',page='employee',id='staff',name='직급 테스트',department='개발팀',position=label,role=role,joinDate='2020-01-01',leaveAccrual='MANUAL',annualLeave='30',isActive='1')
                    ok(code==200 and row("SELECT role FROM User WHERE id='staff'")[0]==role,'save employee rank '+role)
                    rank_client=Client(base).login('staff')
                    ok(rank_client.get('employees')[0]==403 and rank_client.get('approvals',stage='team')[0]==403,'rank retains employee permissions '+role)
                    start='2026-12-'+day
                    expected='PENDING_CEO' if role=='GENERAL_MANAGER' else 'PENDING_TEAM_LEADER'
                    ok(apply(rank_client,start)[0]==200 and row("SELECT status FROM LeaveRequest WHERE userId='staff' AND startDate=?",start+' 00:00:00')[0]==expected,'rank application follows configured route '+role)
                    if role=='GENERAL_MANAGER':
                        request=row("SELECT * FROM LeaveRequest WHERE userId='staff' AND startDate=?",start+' 00:00:00')
                        ok(request['teamLeaderStatus']=='SKIPPED','general manager skips team stage')
                        ok(l.post('leave_decide',page='approvals',id=request['id'],stage='team',decision='APPROVE')[0]==409,'team leader cannot process direct final request')
                        ok(a.post('leave_decide',page='approvals',id=request['id'],stage='ceo',decision='APPROVE')[0]==200 and row('SELECT status FROM LeaveRequest WHERE id=?',request['id'])[0]=='APPROVED','designated administrator approves general manager directly')
                def staff_balance():
                    code=BOOT+DOMAIN+"echo json_encode(balance(one(\"SELECT * FROM `User` WHERE id='staff'\")));"
                    return json.loads(subprocess.check_output([PHP,'-r',code],env=env))
                before_sick=staff_balance()
                ok(apply(rank_client,'2026-12-14','2026-12-16',typ='SICK')[0]==200,'multiday sick leave application')
                sick=row("SELECT * FROM LeaveRequest WHERE userId='staff' AND leaveType='SICK'")
                ok(sick['days']==3 and staff_balance()==before_sick,'pending sick leave does not reserve annual balance')
                ok(a.post('leave_decide',page='approvals',id=sick['id'],stage='ceo',decision='APPROVE')[0]==200,'sick leave follows approval flow')
                ok(staff_balance()==before_sick,'approved sick leave does not deduct annual balance')
                # Approved cancellation and immutable historical display use only isolated fixtures.
                original_reason='최초 신청 <script>original()</script>'
                revised_reason='변경 신청 <script>revised()</script>'
                ok(apply(rank_client,'2026-12-21',reason=original_reason)[0]==200,'create history regression fixture')
                historical=row("SELECT * FROM LeaveRequest WHERE userId='staff' AND startDate='2026-12-21 00:00:00'")
                hid=historical['id']
                ok(a.post('leave_decide',id=hid,stage='ceo',decision='APPROVE')[0]==200,'approve history regression fixture')
                saved_events=connection.execute('SELECT id,action,afterData FROM LeaveHistory WHERE leaveRequestId=?',(hid,)).fetchall()
                ok(a.post('leave_edit',page='leave-edit',id=hid,leaveType='AM_HALF',startDate='2026-12-22',endDate='2026-12-22',reason=revised_reason)[0]==200,'edit approved reason dates type and days')
                def history_article(body,event_id):
                    match=re.search(r'<article data-history-id="'+re.escape(event_id)+r'">(.*?)</article>',body.decode(),re.S)
                    assert match,event_id
                    return match[1]
                for client,page in [(a,'admin-history'),(rank_client,'history')]:
                    body=client.get(page)[1]
                    for event in saved_events:
                        article=history_article(body,event['id'])
                        ok(html_module.escape(original_reason) in article and html_module.escape(revised_reason) not in article and '2026-12-21' in article and '2026-12-22' not in article and '1일' in article,'history renders original snapshot '+page+' '+event['action'])
                        ok(row('SELECT afterData FROM LeaveHistory WHERE id=?',event['id'])[0]==event['afterData'],'editing preserves stored snapshot '+page+' '+event['action'])
                    edited_event=row("SELECT id FROM LeaveHistory WHERE leaveRequestId=? AND action='ADMIN_UPDATED'",hid)
                    article=history_article(body,edited_event['id'])
                    ok('변경 내용' in article and html_module.escape(original_reason) in article and html_module.escape(revised_reason) in article and '오전 반차' in article and '0.5일' in article,'history shows before and after changes '+page)
                    ok('<script>original()' not in article and '<script>revised()' not in article,'snapshot text is escaped '+page)
                cancelled_form=a.get('leave-cancel',id=hid)[1]
                ok('2026년 잔여 연차에 0.5일이 복원됩니다.'.encode() in cancelled_form and b'name="cancellationReason" required' in cancelled_form,'cancellation form explains restoration and requires reason')
                admin_list=a.get('admin-leaves',q='staff',status='APPROVED')[1]
                ok(b'page=leave-cancel' in admin_list and b'return_status=APPROVED' in admin_list and '휴가 취소'.encode() in admin_list,'approved admin row exposes cancellation with filter context')
                ok(b'page=leave-cancel' not in rank_client.get('leaves')[1],'owner approved row has no admin cancellation control')
                for client,label in [(rank_client,'employee'),(l,'leader'),(d,'director'),(c,'CEO')]:
                    ok(client.get('leave-cancel',id=hid)[0]==403 and client.post('leave_admin_cancel',id=hid,cancellationReason='forbidden')[0]==403,'admin cancellation role restriction '+label)
                before_cancel=row('SELECT * FROM LeaveRequest WHERE id=?',hid)
                count_before=row('SELECT COUNT(*) FROM LeaveHistory WHERE leaveRequestId=?',hid)[0]
                for reason in ['', '   ', 'x'*501]:
                    code,body=a.post('leave_admin_cancel',page='leave-cancel',id=hid,cancellationReason=reason)
                    ok(code==400 and b'name="cancellationReason"' in body,'invalid cancellation reason retains form '+str(len(reason)))
                ok(a.post('leave_admin_cancel',id=hid,cancellationReason='csrf test',csrf=False)[0]==403,'admin cancellation enforces CSRF')
                ok(row('SELECT * FROM LeaveRequest WHERE id=?',hid)==before_cancel and row('SELECT COUNT(*) FROM LeaveHistory WHERE leaveRequestId=?',hid)[0]==count_before,'invalid cancellation does not mutate request or history')
                ok(a.post('leave_admin_cancel',id='missing',cancellationReason='missing')[0]==404,'missing cancellation target rejected')
                for state in ['PENDING_TEAM_LEADER','REJECTED','CANCELLED']:
                    target=row('SELECT id FROM LeaveRequest WHERE status=?',state)
                    ok(target and a.post('leave_admin_cancel',id=target['id'],cancellationReason='wrong status')[0]==409,'admin cannot cancel nonapproved state '+state)
                def annual_balance(year=2026):
                    code=BOOT+DOMAIN+"echo json_encode(balance(one(\"SELECT * FROM `User` WHERE id='staff'\"),"+str(year)+"));"
                    return json.loads(subprocess.check_output([PHP,'-r',code],env=env))
                before_balance=annual_balance()
                cancellation_reason='직원 요청으로 일정 취소 <script>cancel()</script>'
                code,body=a.post('leave_admin_cancel',page='leave-cancel',id=hid,cancellationReason=cancellation_reason,return_page='admin-leaves',return_q='staff',return_status='APPROVED',return_from='2026-12-22',return_to='2026-12-22')
                cancelled_request=row('SELECT * FROM LeaveRequest WHERE id=?',hid)
                ok(code==200 and '검색 결과 0건'.encode() in body and b'value="2026-12-22"' in body and '선택한 상태와 달라 목록에서 제외'.encode() in body,'admin cancellation preserves filters and explains removed result')
                ok(cancelled_request['status']=='CANCELLED' and cancelled_request['requestFingerprint'] is None and cancelled_request['reason']==revised_reason and cancelled_request['ceoApprovedBy']==before_cancel['ceoApprovedBy'] and cancelled_request['ceoApprovedAt']==before_cancel['ceoApprovedAt'],'cancellation clears reservation and preserves original reason and approval')
                after_balance=annual_balance()
                ok(after_balance['remaining']==before_balance['remaining']+.5 and after_balance['used']==before_balance['used']-.5 and after_balance['adjustment']==before_balance['adjustment'] and after_balance['pending']==before_balance['pending'],'half day cancellation restores only approved usage')
                cancellation=row("SELECT * FROM LeaveHistory WHERE leaveRequestId=? AND action='ADMIN_CANCELLED'",hid)
                ok(cancellation['actorId']=='admin' and json.loads(cancellation['beforeData'])['status']=='APPROVED' and json.loads(cancellation['afterData'])['status']=='CANCELLED' and json.loads(cancellation['afterData'])['cancellationReason']==cancellation_reason,'cancellation audit stores actor original snapshot cancelled snapshot and reason')
                for client,page in [(a,'admin-history'),(rank_client,'history')]:
                    article=history_article(client.get(page)[1],cancellation['id'])
                    ok('관리자 휴가 취소' in article and html_module.escape(cancellation_reason) in article and '<script>cancel()' not in article,'cancellation audit visible and escaped '+page)
                ok(a.post('leave_admin_cancel',id=hid,cancellationReason='repeat')[0]==409 and row('SELECT COUNT(*) FROM LeaveHistory WHERE leaveRequestId=?',hid)[0]==count_before+1 and annual_balance()==after_balance,'repeated cancellation cannot restore twice or duplicate history')
                ok(apply(rank_client,'2026-12-22',typ='AM_HALF')[0]==200,'cancelled dates can be requested again')
                pending_final=row("SELECT id FROM LeaveRequest WHERE userId='staff' AND startDate='2026-12-22 00:00:00' AND status='PENDING_CEO'")
                ok(a.post('leave_admin_cancel',id=pending_final['id'],cancellationReason='pending')[0]==409,'admin cancellation rejects pending final approval')
                balance_before_sick_cancel=annual_balance()
                ok('잔여 연차는 변하지 않습니다.'.encode() in a.get('leave-cancel',id=sick['id'])[1] and a.post('leave_admin_cancel',id=sick['id'],cancellationReason='병가 취소')[0]==200 and annual_balance()==balance_before_sick_cancel,'sick cancellation leaves annual balance unchanged')
                ok(apply(rank_client,'2026-12-23')[0]==200,'create annual cancellation fixture')
                annual_id=row("SELECT id FROM LeaveRequest WHERE userId='staff' AND startDate='2026-12-23 00:00:00'")[0]
                assert a.post('leave_decide',id=annual_id,stage='ceo',decision='APPROVE')[0]==200
                before_annual_cancel=annual_balance()
                ok(secondary.post('leave_admin_cancel',id=annual_id,cancellationReason='관리자 취소')[0]==200 and annual_balance()['remaining']==before_annual_cancel['remaining']+1,'any administrator can cancel approved annual leave and restore one day')
                # Legacy imports can contain partial, missing or malformed snapshots.
                for label,snapshot in [('partial',{'startDate':'2026-01-02','endDate':'2026-01-02','days':1,'leaveType':'ANNUAL','status':'APPROVED'}),('missing',None),('invalid','{broken'),('nested',{'reason':{'unexpected':'object'}})]:
                    event_id='legacy-'+label
                    payload=json.dumps(snapshot,ensure_ascii=False) if isinstance(snapshot,dict) else snapshot
                    connection.execute('INSERT INTO LeaveHistory (id,leaveRequestId,userId,action,actorId,afterData,createdAt) VALUES (?,?,?,?,?,?,?)',(event_id,hid,'staff','ADMIN_IMPORTED','admin',payload,'2026-01-01 00:00:00'))
                    if not MYSQL_DSN:connection.commit()
                    article=history_article(a.get('admin-history')[1],event_id)
                    ok('기록되지 않았습니다.' in article and revised_reason not in article and html_module.escape(revised_reason) not in article,'legacy history never fills gaps with current request '+label)
                grouped=a.get('admin-history',request=hid)[1].decode()
                ok(re.findall(r'data-request-id="([^"]+)"',grouped)==[hid] and 'data-request-id="'+hid+'" open' in grouped,'direct history link opens only the selected application')
                ok('현재 상태' in grouped and '취소' in grouped and '최근 처리:' in grouped,'group summary distinguishes current state and latest event')
                filtered_history=a.get('admin-history',q='STAFF',**{'from':'2026-01-01','to':'2026-01-01'})[1].decode()
                ok(hid in re.findall(r'data-request-id="([^"]+)"',filtered_history) and cancellation['id'] in filtered_history,'history date match retains complete application timeline')
                ok('data-request-id=' not in a.get('admin-history',q='%')[1].decode(),'history search treats percent literally')
                ok('data-request-id=' not in a.get('admin-history',request=hid,**{'to':'2025-12-31'})[1].decode(),'history period excludes applications without matching events')
                invalid_history=a.get('admin-history',**{'from':'2026-01-03','to':'2026-01-01'})[1].decode()
                ok('처리 기간 종료는 시작보다 빠를 수 없습니다.' in invalid_history and 'value="2026-01-03"' in invalid_history,'invalid history dates retain editable filters')
                ok(hid.encode() not in n.get('history',request=hid,q='staff')[1].split(b'<div class="leave-history-page">')[-1].split(b'</form>')[-1],'personal history request filter cannot expose another employee')
                ok(n.get('admin-history',request=hid)[0]==403,'grouped admin history remains administrator only')
                ok(('page=admin-history&amp;request='+hid).encode() in a.get('admin-leaves')[1],'admin application links directly to its history')
                private_history=n.get('history')[1]
                ok(b'legacy-partial' not in private_history and cancellation['id'].encode() not in private_history,'staff history remains limited to own requests')
                # A direct route is editable until its first decision, with the same reservation checks.
                def user_balance(user_id):
                    code=BOOT+DOMAIN+"echo json_encode(balance(one('SELECT * FROM `User` WHERE id=?',["+json.dumps(user_id)+"]),2026));"
                    return json.loads(subprocess.check_output([PHP,'-r',code],env=env))
                ok(apply(l,'2026-10-20',reason='leader original')[0]==200,'leader creates own request for self service')
                own=row("SELECT * FROM LeaveRequest WHERE userId='leader' AND startDate='2026-10-20 00:00:00'")
                own_id=own['id']
                for page in ['leaves','dashboard']:
                    body=l.get(page)[1]
                    ok(('page=leave-edit&amp;id='+own_id).encode() in body and b'name="action" value="leave_cancel"' in body,'leader sees own edit and cancel '+page)
                ok(l.get('leave-edit',id=own_id)[0]==200,'leader can open own pending final edit form')
                ok(d.get('leave-edit',id=own_id)[0]==403 and d.post('leave_edit',id=own_id,leaveType='ANNUAL',startDate='2026-10-20',endDate='2026-10-20',reason='other')[0]==403 and d.post('leave_cancel',id=own_id)[0]==409,'direct route self service remains owner only')
                before_own=user_balance('leader')
                ok(l.post('leave_edit',page='leave-edit',id=own_id,leaveType='AM_HALF',startDate='2026-10-21',endDate='2026-10-23',reason='leader revised <script>safe()</script>')[0]==200,'leader edits own dates type and reason before administrator review')
                own_edited=row('SELECT * FROM LeaveRequest WHERE id=?',own_id)
                ok(own_edited['days']==.5 and own_edited['startDate']==own_edited['endDate']=='2026-10-21 00:00:00' and own_edited['status']=='PENDING_CEO' and own_edited['teamLeaderStatus']=='SKIPPED','leader edit retains direct approval route and normalizes half day')
                ok(user_balance('leader')['pending']==before_own['pending']-.5 and user_balance('leader')['remaining']==before_own['remaining'],'leader edit updates pending reservation without deducting approved usage')
                own_event=row("SELECT * FROM LeaveHistory WHERE leaveRequestId=? AND action='UPDATED'",own_id)
                ok(own_event['actorId']=='leader' and json.loads(own_event['beforeData'])['reason']=='leader original' and json.loads(own_event['afterData'])['days']==.5,'leader edit records before and after snapshot')
                ok(b'leader revised &lt;script&gt;safe()&lt;/script&gt;' in a.get('approvals',stage='ceo')[1],'administrator sees the revised application awaiting review')
                ok(l.post('leave_edit',id=own_id,leaveType='ANNUAL',startDate='2026-10-01',endDate='2026-11-30',reason='excess')[0]==409,'leader edit enforces remaining leave limit')
                ok(l.post('leave_edit',id=own_id,leaveType='ANNUAL',startDate='2026-02-30',endDate='2026-02-30',reason='invalid date')[0]==400,'leader edit validates dates')
                ok(apply(l,'2026-10-22')[0]==200,'leader creates overlapping date fixture')
                overlap_id=row("SELECT id FROM LeaveRequest WHERE userId='leader' AND startDate='2026-10-22 00:00:00'")[0]
                ok(l.post('leave_edit',id=own_id,leaveType='ANNUAL',startDate='2026-10-22',endDate='2026-10-22',reason='overlap')[0]==409 and row('SELECT * FROM LeaveRequest WHERE id=?',own_id)==own_edited,'invalid self edits preserve the previous request')
                ok(l.post('leave_cancel',id=own_id,csrf=False)[0]==403 and l.post('leave_edit',id=own_id,csrf=False)[0]==403,'leader self service enforces CSRF')
                before_own_cancel=user_balance('leader')
                ok(l.post('leave_cancel',id=own_id)[0]==200,'leader cancels own pending final request')
                own_cancelled=row('SELECT * FROM LeaveRequest WHERE id=?',own_id)
                ok(own_cancelled['status']=='CANCELLED' and own_cancelled['requestFingerprint'] is None and user_balance('leader')['pending']==before_own_cancel['pending']-.5 and user_balance('leader')['remaining']==before_own_cancel['remaining'],'self cancellation releases reserved half day without increasing remaining balance')
                own_cancel_event=row("SELECT * FROM LeaveHistory WHERE leaveRequestId=? AND action='CANCELLED'",own_id)
                ok(own_cancel_event['actorId']=='leader' and json.loads(own_cancel_event['beforeData'])['status']=='PENDING_CEO' and json.loads(own_cancel_event['afterData'])['status']=='CANCELLED','leader self cancellation creates audit record')
                ok(l.post('leave_cancel',id=own_id)[0]==409 and row("SELECT COUNT(*) FROM LeaveHistory WHERE leaveRequestId=? AND action='CANCELLED'",own_id)[0]==1,'repeated self cancellation is rejected')
                ok(a.post('leave_decide',id=own_id,stage='ceo',decision='APPROVE')[0]==409,'stale administrator approval cannot revive cancelled request')
                ok(l.get('leave-edit',id=own_id)[0]==403 and l.post('leave_edit',id=own_id,leaveType='ANNUAL',startDate='2026-10-21',endDate='2026-10-21',reason='stale')[0]==403,'cancelled leader request cannot be edited')
                ok(apply(l,'2026-10-21',typ='AM_HALF')[0]==200,'leader can reapply for cancelled dates')
                reapplied=row("SELECT id FROM LeaveRequest WHERE userId='leader' AND startDate='2026-10-21 00:00:00' AND status='PENDING_CEO'")[0]
                assert a.post('leave_decide',id=reapplied,stage='ceo',decision='APPROVE')[0]==200
                approved_before=row('SELECT * FROM LeaveRequest WHERE id=?',reapplied)
                ok(l.get('leave-edit',id=reapplied)[0]==403 and l.post('leave_edit',id=reapplied,leaveType='ANNUAL',startDate='2026-10-21',endDate='2026-10-21',reason='stale')[0]==403 and l.post('leave_cancel',id=reapplied)[0]==409 and row('SELECT * FROM LeaveRequest WHERE id=?',reapplied)==approved_before,'administrator approval closes leader self service and rejects stale forms')
                approved_body=l.get('leaves')[1]
                ok(('page=leave-edit&amp;id='+reapplied).encode() not in approved_body and ('name="id" value="'+reapplied+'"').encode() not in approved_body,'approved leader row hides self service controls')
                assert a.post('leave_decide',id=overlap_id,stage='ceo',decision='REJECT',reason='final rejection')[0]==200
                ok(l.get('leave-edit',id=overlap_id)[0]==403 and l.post('leave_cancel',id=overlap_id)[0]==409,'administrator rejection closes leader self service')
                for client,user_id in [(d,'director'),(rank_client,'staff')]:
                    assert apply(client,'2026-10-26')[0]==200
                    direct_id=row('SELECT id FROM LeaveRequest WHERE userId=? AND startDate=?',user_id,'2026-10-26 00:00:00')[0]
                    ok(client.post('leave_edit',id=direct_id,leaveType='AM_HALF',startDate='2026-10-26',endDate='2026-10-26',reason='direct edit')[0]==200 and client.post('leave_cancel',id=direct_id)[0]==200,'same first review rule applies to direct route '+user_id)
                # Completed team decisions remain visible, scoped to the actual deciding team leader.
                ok('내가 처리한 신청'.encode() in l.get('approvals',stage='team')[1],'team approval queue links to processed applications')
                def processed_article(body,leave_id):
                    match=re.search(r'<article data-leave-id="'+re.escape(leave_id)+r'">(.*?)</article>',body.decode(),re.S)
                    assert match,leave_id
                    return match[1]
                processed=l.get('team-processed')[1]
                ok('승인 완료' in processed_article(processed,leave['id']) and '반려' in processed_article(processed,reject['id']),'previously approved and rejected team requests are available')
                for client,label in [(a,'admin'),(rank_client,'staff'),(d,'director')]:
                    ok(client.get('team-processed')[0]==403,'team processed page role restriction '+label)
                ok(apply(n,'2026-10-20',reason='tracking current state')[0]==200,'create team progress fixture')
                tracked_id=row("SELECT id FROM LeaveRequest WHERE userId='newhire' AND startDate='2026-10-20 00:00:00'")[0]
                ok(tracked_id.encode() not in l.get('team-processed')[1],'unprocessed request is excluded from processed tab')
                assert l.post('leave_decide',id=tracked_id,stage='team',decision='APPROVE')[0]==200
                tracked_body=l.get('team-processed')[1]
                processed_filtered=l.get('team-processed',q='newhire',**{'from':'2026-10-20','to':'2026-10-20'})[1]
                ok(tracked_id.encode() in processed_filtered and leave['id'].encode() not in processed_filtered,'processed team filter combines name and leave dates')
                ok('검색 결과 0건' in l.get('team-processed',q='no match')[1].decode(),'processed team search has empty state')
                ok('stage=team&amp;q=newhire&amp;from=2026-10-20&amp;to=2026-10-20' in processed_filtered.decode(),'processed tab returns to filtered pending list')
                article=processed_article(tracked_body,tracked_id)
                ok('관리자 승인 대기' in article and '현재 담당:' not in article and '내 처리: 승인' in article and 'KST' in article,'processed view shows pending final state without approver caption')
                ok(b'name="action" value="leave_decide"' not in tracked_body and b'page=leave-edit' not in tracked_body and b'value="leave_cancel"' not in tracked_body,'processed applications are read only')
                ok(n.post('leave_edit',id=tracked_id,leaveType='ANNUAL',startDate='2026-10-20',endDate='2026-10-20',reason='after team')[0]==403 and n.post('leave_cancel',id=tracked_id)[0]==409,'team approved staff request remains locked during final review')
                assert a.post('leave_decide',id=tracked_id,stage='ceo',decision='APPROVE')[0]==200
                article=processed_article(l.get('team-processed')[1],tracked_id)
                ok('승인 완료' in article and '최종 승인: 테스트 관리자' in article,'processed view follows final approval')
                assert a.post('leave_admin_cancel',id=tracked_id,cancellationReason='tracking cancellation <script>safe()</script>')[0]==200
                article=processed_article(l.get('team-processed')[1],tracked_id)
                ok('취소' in article and '관리자 취소 사유: tracking cancellation &lt;script&gt;safe()&lt;/script&gt;' in article and '<script>safe()' not in article,'processed view follows administrator cancellation with escaped reason')
                ok(apply(n,'2026-10-21',reason='tracking rejection')[0]==200,'create final rejection tracking fixture')
                tracked_reject=row("SELECT id FROM LeaveRequest WHERE userId='newhire' AND startDate='2026-10-21 00:00:00'")[0]
                assert l.post('leave_decide',id=tracked_reject,stage='team',decision='APPROVE')[0]==200
                assert a.post('leave_decide',id=tracked_reject,stage='ceo',decision='REJECT',reason='final reason <script>safe()</script>')[0]==200
                article=processed_article(l.get('team-processed')[1],tracked_reject)
                ok('최종 반려: 테스트 관리자' in article and '반려 사유: final reason &lt;script&gt;safe()&lt;/script&gt;' in article,'processed view follows final rejection with its reason')
                assert a.post('employee_save',page='employee',email='secondleader',password=PASSWORD,name='다른 팀장',department='개발팀',position='팀장',role='TEAM_LEADER',joinDate='2020-01-01',leaveAccrual='MANUAL',annualLeave='30',isActive='1')[0]==200
                second_leader=Client(base).login('secondleader')
                assert second_leader.post('password',page='password',currentPassword=PASSWORD,newPassword='Leader-password2!',confirmPassword='Leader-password2!')[0]==200
                other_processed=second_leader.get('team-processed',userId='leader',id=tracked_id)[1]
                ok('아직 처리한 신청이 없습니다.'.encode() in other_processed and tracked_id.encode() not in other_processed,'same department leader cannot retrieve another leaders decisions by changing query parameters')
                assert apply(n,'2026-10-22',reason='other leader tracking')[0]==200
                other_tracked=row("SELECT id FROM LeaveRequest WHERE userId='newhire' AND startDate='2026-10-22 00:00:00'")[0]
                assert second_leader.post('leave_decide',id=other_tracked,stage='team',decision='APPROVE')[0]==200
                ok(other_tracked.encode() in second_leader.get('team-processed')[1] and other_tracked.encode() not in l.get('team-processed')[1],'processed results are scoped to the actual deciding leader')
                assert a.post('employee_save',page='employee',id='newhire',name='Transferred employee',department='영업팀',position='직원',role='USER',joinDate='2026-03-01',leaveAccrual='MANUAL',annualLeave='30',isActive='1')[0]==200
                ok(tracked_id.encode() in l.get('team-processed')[1] and other_tracked.encode() in second_leader.get('team-processed')[1],'employee transfer preserves access to the leaders own earlier decisions')
                # Pending card corrections keep the original entry, receipt, and approval boundary.
                edit_fields={'usedAt':'2026-09-11','merchant':'오타 상점','amount':'15700','purpose':'수정 전 내역','isFixed':'','hasReceipt':'','hasApprovalDocument':'','note':'수정 전 비고'}
                ok(rank_client.post('card_create',page='cards',**edit_fields)[0]==200,'create pending card for editing')
                edit_id=row("SELECT id FROM CardExpense WHERE merchant='오타 상점'")[0]
                ok(upload(rank_client,'keep-receipt.pdf',pdf,edit_id)[0]==200,'attach receipt before card correction')
                before_card=row('SELECT * FROM CardExpense WHERE id=?',edit_id)
                before_count=row('SELECT COUNT(*) FROM CardExpense')[0]
                body=rank_client.get('cards')[1]
                edit_link=('page=card-edit&amp;id='+edit_id).encode()
                ok(edit_link in body,'pending owner card exposes edit link')
                code,body=rank_client.get('card-edit',id=edit_id)
                ok(code==200 and '오타 상점'.encode() in body and '수정 전 내역'.encode() in body and b'keep-receipt.pdf' in body,'card edit form preloads data and existing receipt')
                for client,label in [(l,'other employee'),(a,'administrator of another owners card')]:
                    ok(client.get('card-edit',id=edit_id)[0]==403 and client.post('card_edit',id=edit_id,**edit_fields)[0]==403,'card correction ownership enforced for '+label)
                ok(rank_client.get('card-edit',id='missing-card')[0]==404 and rank_client.post('card_edit',id='missing-card',**edit_fields)[0]==404,'missing card correction returns not found')
                ok(rank_client.post('card_edit',page='card-edit',id=edit_id,csrf=False,**edit_fields)[0]==403,'card correction requires CSRF')
                changes={**edit_fields,'usedAt':'2026-09-12','merchant':'정정 상점 <script>safe()</script>','amount':'18500','purpose':'수정한 업무 식사','isFixed':'1','hasApprovalDocument':'1','note':'카드 내역 정정'}
                code,body=rank_client.post('card_edit',page='card-edit',id=edit_id,**changes,userId='admin',status='APPROVED',createdAt='2000-01-01',receiptFilePath='bad.pdf',decidedById='admin')
                after_card=row('SELECT * FROM CardExpense WHERE id=?',edit_id)
                ok(code==200 and '법인카드 사용 내역을 수정했습니다.'.encode() in body and row('SELECT COUNT(*) FROM CardExpense')[0]==before_count,'card correction updates original record without cancellation or reentry')
                ok(after_card['usedAt']=='2026-09-12 00:00:00' and after_card['merchant']==changes['merchant'] and after_card['amount']==18500 and after_card['purpose']==changes['purpose'] and after_card['note']==changes['note'] and after_card['isFixed']==1 and after_card['hasApprovalDocument']==1,'all editable card fields save')
                preserved=['id','userId','status','category','createdAt','receiptFilePath','receiptFileName','receiptMimeType','decidedById','decidedAt','decisionReason']
                ok(all(after_card[key]==before_card[key] for key in preserved) and after_card['hasReceipt']==1 and rank_client.get('receipt',id=edit_id)[1]==pdf,'card correction preserves receipt bytes and protected metadata even with forged fields')
                ok(b'&lt;script&gt;safe()&lt;/script&gt;' in body and b'<script>safe()' not in body,'corrected text is escaped in card list')
                code,body=a.get('admin-cards',merchant='정정 상점',status='PENDING',**{'from':'2026-09-12','to':'2026-09-12'})
                ok(code==200 and '18,500원'.encode() in body and edit_id.encode() in body,'administrator filtered list and total reflect corrected date and amount')
                for field,value in [('amount','1.5'),('amount','0'),('amount','1000000001'),('amount','abc'),('usedAt','2026-02-30'),('merchant',' '),('merchant','가'*121),('purpose',''),('purpose','가'*501),('note','가'*301)]:
                    code,body=rank_client.post('card_edit',page='card-edit',id=edit_id,**{**changes,field:value})
                    ok(code==400 and row('SELECT * FROM CardExpense WHERE id=?',edit_id)==after_card,'invalid card correction is atomic: '+field+' '+str(value)[:12])
                    if field=='amount' and value=='1.5':ok(b'value="1.5"' in body and changes['purpose'].encode() in body and b'keep-receipt.pdf' in body,'validation failure retains attempted form values and receipt')
                for state in ['APPROVED','REJECTED','CANCELLED']:
                    if state=='APPROVED':locked_id=edit_id
                    else:
                        fixture={**edit_fields,'merchant':'locked '+state}
                        assert rank_client.post('card_create',page='cards',**fixture)[0]==200
                        locked_id=row('SELECT id FROM CardExpense WHERE merchant=?',fixture['merchant'])[0]
                    assert rank_client.get('card-edit',id=locked_id)[0]==200
                    if state=='CANCELLED':assert rank_client.post('card_cancel',id=locked_id)[0]==200
                    else:assert a.post('card_decide',id=locked_id,decision='APPROVE' if state=='APPROVED' else 'REJECT',reason='reviewed')[0]==200
                    locked=row('SELECT * FROM CardExpense WHERE id=?',locked_id)
                    ok(rank_client.get('card-edit',id=locked_id)[0]==409 and rank_client.post('card_edit',page='card-edit',id=locked_id,**changes)[0]==409 and row('SELECT * FROM CardExpense WHERE id=?',locked_id)==locked,'stale correction blocked after '+state)
                    ok(('page=card-edit&amp;id='+locked_id).encode() not in rank_client.get('cards')[1],'edit link hidden after '+state)
                assert l.post('card_create',page='cards',**{**edit_fields,'merchant':'팀장 자체 수정'})[0]==200
                no_receipt_id=row("SELECT id FROM CardExpense WHERE merchant='팀장 자체 수정'")[0]
                ok(l.post('card_edit',page='card-edit',id=no_receipt_id,**{**edit_fields,'merchant':'팀장 수정 완료','note':'','hasReceipt':'','isFixed':''})[0]==200 and row('SELECT note,hasReceipt,receiptFilePath FROM CardExpense WHERE id=?',no_receipt_id)['note'] is None,'team leader edits own card without receipt and clears optional fields')
                assert a.post('card_create',page='cards',**{**edit_fields,'merchant':'관리자 자체 수정'})[0]==200
                own_admin_id=row("SELECT id FROM CardExpense WHERE merchant='관리자 자체 수정'")[0]
                ok(a.post('card_edit',page='card-edit',id=own_admin_id,**{**edit_fields,'merchant':'관리자 수정 완료'})[0]==200,'administrator can correct own pending card')
                # An old review must never approve newer money, text, or evidence.
                version_fields={**edit_fields,'merchant':'versioned QA','amount':'10000'}
                assert rank_client.post('card_create',page='cards',**version_fields)[0]==200
                version_id=row("SELECT id FROM CardExpense WHERE merchant='versioned QA'")[0]
                def card_token(client):
                    form=client.get('card-edit',id=version_id)[1]
                    return re.search(rb'name="reviewToken" value="([a-f0-9]+)"',form)[1].decode()
                seen=card_token(rank_client)
                assert rank_client.post('card_edit',page='card-edit',id=version_id,**{**version_fields,'amount':'900000'})[0]==200
                for decision in ['APPROVE','REJECT']:
                    ok(a.post('card_decide',id=version_id,decision=decision,reason='old review',reviewToken=seen)[0]==409,'stale card '+decision+' cannot process changed amount')
                code,conflict=rank_client.post('card_edit',page='card-edit',id=version_id,reviewToken=seen,**version_fields)
                ok(code==409 and row('SELECT amount FROM CardExpense WHERE id=?',version_id)[0]==900000,'stale card editor cannot overwrite newer amount')
                ok(('name="reviewToken" value="'+seen+'"').encode() in conflict,'conflict rerender keeps stale token until explicit reload')
                ok(rank_client.post('card_cancel',id=version_id,reviewToken=seen)[0]==409,'stale card cancellation requires recheck')
                fresh=card_token(rank_client)
                ok(a.post('card_decide',id=version_id,decision='APPROVE',reviewToken='')[0]==409,'missing card review token is rejected')
                assert upload(rank_client,'new-proof.pdf',pdf,version_id)[0]==200
                ok(a.post('card_decide',id=version_id,decision='APPROVE',reviewToken=fresh)[0]==409,'new receipt invalidates old administrator review')
                current=card_token(rank_client)
                ok(a.post('card_decide',id=version_id,decision='APPROVE',reviewToken=current,csrf=False)[0]==403,'review token never bypasses CSRF')
                ok(a.post('card_decide',id=version_id,decision='APPROVE',reviewToken=current)[0]==200 and row('SELECT amount FROM CardExpense WHERE id=?',version_id)[0]==900000,'fresh review approves precisely the updated amount')
                # Replaying a submitted form must not create a second expense or file.
                s=rank_client
                form=s.get('cards')[1]
                registration=re.search(rb'name="registrationToken" value="([a-f0-9]+)"',form)[1].decode()
                fields=dict(usedAt='2026-09-14',merchant='Idempotent card QA',amount='12000',purpose='same submitted form',registrationToken=registration)
                invalid=s.post('card_create',page='cards',**{**fields,'amount':'0'})
                ok(invalid[0]==400 and ('name="registrationToken" value="'+registration+'"').encode() in invalid[1],'failed validation preserves registration token for retry')
                token=re.search(rb'name="csrf" value="([a-f0-9]+)"',form)[1].decode()
                boundary='----CardReplayBoundary';chunks=[]
                for key,value in {'action':'card_create','csrf':token,**fields}.items():chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode())
                chunks.append(f'--{boundary}\r\nContent-Disposition: form-data; name="receipt"; filename="replay.pdf"\r\nContent-Type: application/pdf\r\n\r\n'.encode()+pdf+b'\r\n')
                chunks.append(f'--{boundary}--\r\n'.encode());payload=b''.join(chunks)
                def replay_card():return s.request('index.php?page=cards',payload,{'Content-Type':f'multipart/form-data; boundary={boundary}'})
                ok(replay_card()[0]==200,'registration with receipt succeeds after validation retry')
                original=row("SELECT * FROM CardExpense WHERE merchant='Idempotent card QA'")
                files_before=sorted(str(p) for p in (work/'storage/receipts').rglob('*') if p.is_file())
                code,replayed=replay_card()
                ok(code==200 and '이미 등록된 요청입니다'.encode() in replayed and row("SELECT COUNT(*) FROM CardExpense WHERE merchant='Idempotent card QA'")[0]==1,'identical multipart replay creates only one expense')
                ok(files_before==sorted(str(p) for p in (work/'storage/receipts').rglob('*') if p.is_file()) and s.get('receipt',id=original['id'])[1]==pdf,'replayed upload creates no extra file and preserves receipt')
                assert a.post('card_decide',page='admin-cards',id=original['id'],decision='APPROVE')[0]==200
                ok(replay_card()[0]==200 and row('SELECT status FROM CardExpense WHERE id=?',original['id'])[0]=='APPROVED','replay after approval does not recreate or change expense')
                fresh_fields={k:v for k,v in fields.items() if k!='registrationToken'}
                ok(s.post('card_create',page='cards',**fresh_fields)[0]==200 and row("SELECT COUNT(*) FROM CardExpense WHERE merchant='Idempotent card QA'")[0]==2,'new form permits legitimate identical expense')
                ok(l.post('card_create',page='cards',**fields)[0]==200 and row("SELECT COUNT(*) FROM CardExpense WHERE merchant='Idempotent card QA' AND userId='leader'")[0]==1,'registration token is scoped to owner')
                ok(s.post('card_create',page='cards',**{**fields,'registrationToken':''})[0]==400,'missing registration token rejected')
                ok(s.post('card_create',page='cards',csrf=False,**fields)[0]==403,'duplicate registration still requires CSRF')
                # Leave review/edit conflicts retain the reviewed snapshot under the owner lock.
                assert a.post('employee_save',page='employee',email='leaveversion',password=PASSWORD,name='Leave version QA',department='개발팀',position='직원',role='USER',joinDate='2020-01-01',leaveAccrual='MANUAL',annualLeave='30',isActive='1')[0]==200
                leave_client=Client(base).login('leaveversion')
                assert leave_client.post('password',page='password',currentPassword=PASSWORD,newPassword='Leave-version2!',confirmPassword='Leave-version2!')[0]==200
                leave_fields=dict(leaveType='SICK',startDate='2027-01-11',endDate='2027-01-11',reason='leave version fixture')
                assert leave_client.post('leave_create',page='leaves',**leave_fields)[0]==200
                lid=row("SELECT id FROM LeaveRequest WHERE reason='leave version fixture'")[0]
                def leave_token():
                    return re.search(rb'name="reviewToken" value="([a-f0-9]+)"',a.get('leave-edit',id=lid)[1])[1].decode()
                seen=leave_token()
                changed={**leave_fields,'endDate':'2027-01-15','reason':'five days'}
                assert leave_client.post('leave_edit',page='leave-edit',id=lid,**changed)[0]==200
                snapshot=row('SELECT * FROM LeaveRequest WHERE id=?',lid)
                count=row('SELECT COUNT(*) FROM LeaveHistory WHERE leaveRequestId=?',lid)[0]
                for decision in ['APPROVE','REJECT']:
                    ok(l.post('leave_decide',id=lid,stage='team',decision=decision,reason='old review',reviewToken=seen)[0]==409,'stale team '+decision+' rejects changed leave dates')
                code,conflict=leave_client.post('leave_edit',page='leave-edit',id=lid,reviewToken=seen,**leave_fields)
                ok(code==409 and ('name="reviewToken" value="'+seen+'"').encode() in conflict,'stale leave edit rejects overwrite and retains original token')
                ok(leave_client.post('leave_cancel',id=lid,reviewToken=seen)[0]==409,'stale own leave cancellation rejected')
                ok(row('SELECT * FROM LeaveRequest WHERE id=?',lid)==snapshot and row('SELECT COUNT(*) FROM LeaveHistory WHERE leaveRequestId=?',lid)[0]==count,'rejected leave conflicts change neither record nor history')
                current=leave_token()
                ok(l.post('leave_decide',id=lid,stage='team',decision='APPROVE',reviewToken='')[0]==409,'missing leave token rejected')
                ok(l.post('leave_decide',id=lid,stage='team',decision='APPROVE',reviewToken=current,csrf=False)[0]==403,'leave review token cannot bypass CSRF')
                ok(l.post('leave_decide',id=lid,stage='team',decision='APPROVE',reviewToken=current)[0]==200,'fresh team review accepts changed leave')
                seen=leave_token()
                assert a.post('leave_edit',page='leave-edit',id=lid,**{**changed,'reason':'new reason only'})[0]==200
                for decision in ['APPROVE','REJECT']:
                    ok(a.post('leave_decide',id=lid,stage='ceo',decision=decision,reason='old review',reviewToken=seen)[0]==409,'reason-only change invalidates final '+decision)
                ok(a.post('leave_decide',id=lid,stage='ceo',decision='APPROVE',reviewToken=leave_token())[0]==200,'fresh final review succeeds')
                seen=leave_token()
                assert a.post('leave_edit',page='leave-edit',id=lid,**{**changed,'endDate':'2027-01-13'})[0]==200
                code,conflict=a.post('leave_admin_cancel',page='leave-cancel',id=lid,cancellationReason='stale cancel',reviewToken=seen)
                ok(code==409 and ('name="reviewToken" value="'+seen+'"').encode() in conflict,'stale admin cancellation retains original token')
                ok(a.post('leave_admin_cancel',id=lid,cancellationReason='reviewed cancel',reviewToken=leave_token())[0]==200,'fresh administrator cancellation succeeds')
                # Calendar changes cannot silently alter pending or approved leave deductions.
                calendar_fields=dict(leaveType='ANNUAL',startDate='2027-02-01',endDate='2027-02-02',reason='calendar guard fixture')
                assert leave_client.post('leave_create',page='leaves',**calendar_fields)[0]==200
                calendar_id=row("SELECT id FROM LeaveRequest WHERE reason='calendar guard fixture'")[0]
                code,impact=a.post('holiday_add',page='settings',date='2027-02-02',name='Calendar QA')
                ok(code==409 and ('page=leave-edit&amp;id='+calendar_id).encode() in impact,'holiday add conflict shows affected request link')
                ok(row("SELECT COUNT(*) FROM CompanyHoliday WHERE date='2027-02-02 00:00:00'")[0]==0 and row('SELECT days FROM LeaveRequest WHERE id=?',calendar_id)[0]==2,'blocked calendar change preserves holiday and leave data')
                ok(leave_client.post('holiday_add',date='2027-02-02',name='forbidden')[0]==403 and a.post('holiday_add',date='2027-02-02',name='csrf',csrf=False)[0]==403,'calendar guard preserves role and CSRF restrictions')
                # Simulate an old database whose calendar was changed before this fix.
                connection.execute('INSERT INTO CompanyHoliday (id,date,name,createdAt) VALUES (?,?,?,?)',('calendar-legacy','2027-02-02 00:00:00','Legacy holiday','2026-09-14 00:00:00'))
                if not MYSQL_DSN:connection.commit()
                count=row('SELECT COUNT(*) FROM LeaveHistory WHERE leaveRequestId=?',calendar_id)[0]
                ok(l.post('leave_decide',id=calendar_id,stage='team',decision='APPROVE')[0]==409 and row('SELECT COUNT(*) FROM LeaveHistory WHERE leaveRequestId=?',calendar_id)[0]==count,'legacy calendar mismatch blocks team approval without history')
                ok('휴일 기준 재확인 필요'.encode() in a.get('admin-leaves',q='leaveversion')[1],'leave list identifies calendar mismatch')
                ok(a.post('leave_edit',page='leave-edit',id=calendar_id,**calendar_fields)[0]==200 and row('SELECT days FROM LeaveRequest WHERE id=?',calendar_id)[0]==1,'explicit edit recalculates old leave using current holidays')
                assert l.post('leave_decide',id=calendar_id,stage='team',decision='APPROVE')[0]==200
                ok(a.post('holiday_delete',page='settings',id='calendar-legacy')[0]==409,'pending final leave blocks holiday removal')
                connection.execute("DELETE FROM CompanyHoliday WHERE id='calendar-legacy'")
                if not MYSQL_DSN:connection.commit()
                ok(a.post('leave_decide',id=calendar_id,stage='ceo',decision='APPROVE')[0]==409,'legacy holiday removal blocks undercounted final approval')
                assert a.post('leave_edit',page='leave-edit',id=calendar_id,**calendar_fields)[0]==200
                ok(a.post('leave_decide',id=calendar_id,stage='ceo',decision='APPROVE')[0]==200 and row('SELECT days FROM LeaveRequest WHERE id=?',calendar_id)[0]==2,'corrected final approval preserves reviewed two day deduction')
                ok(a.post('holiday_add',page='settings',date='2027-02-02',name='approved conflict')[0]==409,'approved leave blocks holiday addition')
                assert a.post('leave_admin_cancel',id=calendar_id,cancellationReason='calendar reschedule')[0]==200
                ok(a.post('holiday_add',page='settings',date='2027-02-02',name='after cancellation')[0]==200,'cancelled leave no longer prevents calendar correction')
                added=row("SELECT id FROM CompanyHoliday WHERE date='2027-02-02 00:00:00'")[0]
                ok(a.post('holiday_add',page='settings',date='2027-02-02',name='duplicate')[0]==409,'duplicate holiday produces useful conflict')
                ok(a.post('holiday_delete',page='settings',id=added)[0]==200 and a.post('holiday_delete',page='settings',id=added)[0]==404,'holiday removal succeeds once and rejects stale deletion')
                half_fields=dict(leaveType='AM_HALF',startDate='2027-02-08',endDate='2027-02-08',reason='calendar half fixture')
                assert leave_client.post('leave_create',page='leaves',**half_fields)[0]==200
                half_id=row("SELECT id FROM LeaveRequest WHERE reason='calendar half fixture'")[0]
                ok(a.post('holiday_add',page='settings',date='2027-02-08',name='half conflict')[0]==409,'half day request also blocks affected holiday')
                connection.execute('INSERT INTO CompanyHoliday (id,date,name,createdAt) VALUES (?,?,?,?)',('calendar-half','2027-02-08 00:00:00','Legacy half holiday','2026-09-14 00:00:00'))
                if not MYSQL_DSN:connection.commit()
                ok(l.post('leave_decide',id=half_id,stage='team',decision='APPROVE')[0]==409,'zero working days cannot approve half day')
                ok(l.post('leave_decide',id=half_id,stage='team',decision='REJECT',reason='holiday conflict')[0]==200 and a.post('holiday_delete',id='calendar-half')[0]==200,'invalid calendar request can still be rejected and holiday removed')
                weekend_fields=dict(leaveType='SICK',startDate='2027-02-12',endDate='2027-02-15',reason='calendar weekend fixture')
                assert leave_client.post('leave_create',page='leaves',**weekend_fields)[0]==200
                ok(a.post('holiday_add',date='2027-02-13',name='Saturday')[0]==200,'weekend holiday allowed when spanning leave days are unaffected')
                weekend_id=row("SELECT id FROM CompanyHoliday WHERE date='2027-02-13 00:00:00'")[0]
                ok(a.post('holiday_delete',id=weekend_id)[0]==200,'unaffected weekend holiday removal allowed')
                # Personal summer leave must never hide an approver's work queue.
                for reviewer,user_id,stage in [(l,'leader','team'),(a,'admin','ceo')]:
                    before=reviewer.get()[1]
                    summary_before=re.search(rb'<p class="dashboard-approval-summary">(.*?)</p>',before,re.S)
                    ok(summary_before is not None,'authorized reviewer sees queue before summer fixture '+stage)
                    summer_id='dashboard-summer-'+user_id
                    summer_date=time.strftime('%Y')+'-07-01 00:00:00'
                    connection.execute('INSERT INTO LeaveRequest (id,userId,leaveType,startDate,endDate,days,reason,status,createdAt,updatedAt) VALUES (?,?,?,?,?,?,?,?,?,?)',(summer_id,user_id,'SUMMER_ADVANCE',summer_date,summer_date,1,'dashboard summer fixture','APPROVED',summer_date,summer_date))
                    if not MYSQL_DSN:connection.commit()
                    after=reviewer.get()[1]
                    summary_after=re.search(rb'<p class="dashboard-approval-summary">(.*?)</p>',after,re.S)
                    ok(summary_after is not None and summary_after[1]==summary_before[1],'approved summer leave preserves queue count and link '+stage)
                    ok(('page=approvals&amp;stage='+stage).encode() in summary_after[1] and reviewer.get('approvals',stage=stage)[0]==200,'queue shortcut reaches authorized approval stage '+stage)
                    connection.execute('DELETE FROM LeaveRequest WHERE id=?',(summer_id,))
                    if not MYSQL_DSN:connection.commit()
                for nonreviewer,label in [(leave_client,'employee'),(d,'director'),(c,'former CEO'),(secondary,'nondesignated administrator')]:
                    ok(b'dashboard-approval-summary' not in nonreviewer.get()[1],'no misleading review shortcut for '+label)
                # Mobile list navigation must not drop filters, pages or records.
                page_ids=[]
                total=row('SELECT COUNT(*) AS n FROM LeaveRequest WHERE isDeleted=0')['n']
                for number in range(1,(total+9)//10+1):
                    listing=a.get('admin-leaves',listPage=str(number))[1].decode()
                    ids=re.findall(r'data-record-id="([^"]+)"',listing)
                    ok(len(ids)==min(10,total-(number-1)*10),'leave pagination size '+str(number))
                    page_ids.extend(ids)
                ok(len(set(page_ids))==total,'leave pagination has no duplicates or missing records')
                last=a.get('admin-leaves',listPage='999999')[1].decode()
                ok(bool(re.findall(r'data-record-id="([^"]+)"',last)),'out of range page clamps to last populated page')
                ok(a.get('admin-leaves',listPage='-1')[0]==400,'invalid list page rejected')
                listing=a.get('admin-leaves',q='staff',listPage='1')[1].decode()
                history_link=html_module.unescape(re.search(r'href="([^"]*page=admin-history[^\"]*request=[^\"]+)"',listing).group(1))
                params=dict(urllib.parse.parse_qsl(urllib.parse.urlsplit(history_link).query));params.pop('page')
                ok(params.get('return_q')=='staff' and params.get('return_listPage')=='1','history link carries original filter and page')
                history_page=a.get('admin-history',**params,return_scroll='600')[1].decode()
                ok('← 연차 관리 목록' in history_page and 'page=admin-leaves&amp;q=staff&amp;listPage=1&amp;scroll=600' in history_page,'history back link and tab retain list position')
                ok('name="return_q" value="staff"' in history_page and 'name="return_listPage" value="1"' in history_page,'history search retains return context')
                connection.close()
                if not MYSQL_DSN:
                    # Export/import round trip uses the isolated fixture DB and copies no real user data.
                    export=work/'export.zip'
                    subprocess.run(['python',str(ROOT/'tools/export_legacy.py'),'--database',str(db),'--receipts',str(work/'storage/receipts'),'--output',str(export)],check=True)
                    target=work/'import.db';target_config=work/'import.php';target_store=work/'import-storage';target_store.mkdir()
                    target_config.write_text("<?php return ['dsn'=>'sqlite:"+target.as_posix()+"','storage'=>'"+target_store.as_posix()+"'];",encoding='utf8')
                    dest=sqlite3.connect(target);dest.executescript((ROOT/'database/sqlite.sql').read_text());dest.close()
                    subprocess.run([PHP,str(ROOT/'tools/import.php'),str(export)],env={**env,'MNM_CONFIG':str(target_config)},check=True)
                    dest=sqlite3.connect(target);ok((target_store/'receipts'/extra).read_bytes()==replacement_pdf and len(json.loads(dest.execute('SELECT receiptAttachments FROM CardExpense WHERE id=?',(card['id'],)).fetchone()[0]))==2,'export/import preserves all additional receipts');ok(dest.execute('SELECT COUNT(*) FROM LeaveRequest WHERE id=?',(leave['id'],)).fetchone()[0]==1,'migration preserves IDs and relationships');ok(dest.execute('SELECT COUNT(*) FROM Session').fetchone()[0]==0,'migration discards old login sessions');dest.close()
                    second=subprocess.run([PHP,str(ROOT/'tools/import.php'),str(export)],env={**env,'MNM_CONFIG':str(target_config)},capture_output=True)
                    ok(second.returncode!=0,'import refuses nonempty database')
                print(json.dumps({'passed':len(checks),'database':'isolated MariaDB' if MYSQL_DSN else 'isolated SQLite','original_data_used':False}))
            finally:
                if connection is not None: connection.close()
                server.terminate();server.wait(timeout=10)
                log.seek(0);errors=[line for line in log if 'Fatal error' in line or 'MNM error:' in line or 'MNM view error:' in line]
                if errors:raise AssertionError('Server runtime errors: '+''.join(errors))

if __name__=='__main__':run()
