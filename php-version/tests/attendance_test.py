"""Attendance HTTP acceptance tests; uses a disposable SQLite database only."""
import datetime as dt
import os
import json
from pathlib import Path
import socket
import sqlite3
import subprocess
import tempfile
import time
from http_test import ROOT, PHP, SETUP, Client
from deployment_test import config

with tempfile.TemporaryDirectory(prefix='mnm-attendance-') as temp:
    work=Path(temp); (work/'storage').mkdir()
    env=config(work/'config.php','sqlite:'+(work/'test.db').as_posix(),work/'storage')
    cfg=work/'config.php';cfg.write_text(cfg.read_text(encoding='utf8').replace('"timezone":', '"attendance_office_ips": ["8.8.8.8"], "timezone":'),encoding='utf8')
    subprocess.run([PHP],input=('<?php '+SETUP).encode(),env=env,check=True)
    clock_checks = "<?php " + "require " + json.dumps((ROOT/'app/bootstrap.php').as_posix()) + ";require " + json.dumps((ROOT/'app/domain.php').as_posix()) + ";" + r"""
$cases=[
 ['2026-09-14 07:46:30','2026-09-14 07:46:00','2026-09-14 07:46:45','2026-09-14 07:46:45'],
 ['2026-09-14 07:46:30','2026-09-14 07:45:00','2026-09-14 07:46:45','2026-09-14 07:45:00'],
 ['2026-09-14 07:46:30','2026-09-14 07:46:00','2026-09-14 07:47:10','2026-09-14 07:46:00'],
 ['2026-09-14 07:46:30','2026-09-14 07:47:00','2026-09-14 07:47:10','2026-09-14 07:47:00'],
 ['2026-09-14 07:46:30','2026-09-14 07:46:00','2026-09-14 07:46:30','2026-09-14 07:46:00'],
 ['2026-09-13 07:46:30','2026-09-13 07:46:00','2026-09-14 07:46:45','2026-09-13 07:46:00']
];
foreach($cases as [$start,$input,$submitted,$expected])if(attendanceManualCheckoutTime($start,$input,$submitted)!==$expected)throw new RuntimeException('Minute precision regression');
echo "PASS current-minute checkout precision and historical/future boundaries\n";
"""
    subprocess.run([PHP],input=clock_checks.encode(),env=env,check=True)
    with socket.socket() as sock:
        sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    with open(work/'server.log','w+',encoding='utf8') as log:
        server=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',str(ROOT/'public')],env=env,stdout=log,stderr=log)
        try:
            for _ in range(50):
                try: Client(f'http://127.0.0.1:{port}').get();break
                except OSError:time.sleep(.1)
            admin,staff,other=[Client(f'http://127.0.0.1:{port}').login(n) for n in ['admin','staff','other']]
            def check(ok,label):
                assert ok,label
                print('PASS',label)
            check(staff.get('attendance')[0]==200,'additive upgrade and personal page')
            pc=Client(f'http://127.0.0.1:{port}').login('staff')
            def revision(client,scope='attendance'):
                code,body=client.get('attendance-revision',scope=scope)
                assert code==200,body
                return json.loads(body)['revision']
            before=revision(pc); admin_before=revision(admin,'admin-attendance');other_before=revision(other)
            check(Client(f'http://127.0.0.1:{port}').get('attendance-revision')[0]==401,'sync requires login')
            check(staff.get('attendance-revision',scope='admin-attendance')[0]==403,'sync admin scope protected')
            check(staff.get('attendance-revision',scope='employees')[0]==400,'sync rejects unknown scope')
            check(b'data-attendance-sync' in pc.get('attendance')[1],'PC attendance enables live sync')
            check(b'data-attendance-sync' in admin.get('admin-attendance')[1],'admin attendance enables live sync')
            check(b'data-attendance-sync' in pc.get()[1],'dashboard enables live sync')
            db=sqlite3.connect(work/'test.db');db.row_factory=sqlite3.Row
            def row(query,args=()):return db.execute(query,args).fetchone()
            today=dt.datetime.now(dt.timezone(dt.timedelta(hours=9))).date()
            gps=dict(latitude=37.3796517,longitude=126.6659428,accuracy=10,capturedAt=int(time.time()*1000),policyVersion='songdo-ait-v1',punchMode='office')
            punch=dict(**gps,page='attendance',workDate=str(today),version='0',kind='in')
            check(staff.post('attendance_punch',csrf=False,**punch)[0]==403,'CSRF rejected')
            check(staff.post('attendance_punch',page='attendance',workDate=str(today),version='0',kind='in')[0]==400,'missing GPS blocked on server')
            for values,label in [({'latitude':37.40},'outside office'),({'accuracy':101},'inaccurate'),({'capturedAt':int(time.time()*1000)-180000},'stale GPS'),({'capturedAt':int(time.time()*1000)+90000},'future GPS'),({'latitude':37.38095,'accuracy':30},'boundary uncertainty'),({'latitude':'nan'},'invalid coordinates'),({'policyVersion':'old'},'old policy'),({'punchMode':'business_trip','tripReason':'test visit'},'trip inside office')]:
                check(staff.post('attendance_punch',**{**punch,**values})[0]>=400,label+' rejected')
            check(row('SELECT COUNT(*) FROM AttendanceDay')[0]==0,'failed location checks do not create records')
            code,failed_trip=staff.post('attendance_punch',**{**punch,'latitude':37.38095,'accuracy':30,'punchMode':'business_trip','tripReason':'QA <client> visit'})
            check(code==422 and b'value="business_trip" selected' in failed_trip and b'value="QA &lt;client&gt; visit"' in failed_trip,'failed trip validation preserves mode and escaped reason')
            check(b'name="latitude"' not in failed_trip and b'name="capturedAt"' not in failed_trip,'failed trip does not reuse stale GPS coordinates')
            fresh_trip=staff.get('attendance')[1]
            check(b'value="office" selected' in fresh_trip and b'QA &lt;client&gt; visit' not in fresh_trip,'fresh attendance form does not retain previous trip input')
            check(staff.post('attendance_punch',**punch)[0]==200,'server timestamp check-in')
            check(revision(pc)!=before,'GPS punch changes independent PC session revision')
            check(revision(admin,'admin-attendance')!=admin_before,'GPS punch changes administrator revision')
            check(revision(other)==other_before,'personal revision does not reveal other employees activity')
            check('회사 반경 확인'.encode() in pc.get('attendance')[1],'independent PC session sees GPS evidence')
            check(revision(pc)==revision(staff),'same employee on two devices sees same revision')
            checked_in=revision(pc)
            check(staff.post('attendance_punch',**punch)[0]==409,'duplicate or replayed check-in rejected')
            check(revision(pc)==checked_in,'rejected duplicate does not trigger sync')
            check(row('SELECT count(*) FROM AttendanceDay')[0]==1,'duplicate leaves one record')
            check(staff.post('attendance_punch',**gps,page='attendance',workDate=str(today),version='1',kind='out')[0]==200,'check-out')
            check(revision(pc)!=checked_in,'checkout also reaches independent PC session')
            check(staff.post('attendance_punch',**gps,page='attendance',workDate=str(today),version='1',kind='out')[0]==409,'duplicate checkout rejected')
            check(staff.get('admin-attendance')[0]==403 and staff.get('attendance-export')[0]==403,'admin views and export protected')
            old=str(today-dt.timedelta(days=2))
            req=dict(page='attendance',workDate=old,checkIn='09:00',checkOut='18:00',reason='missing <script>alert(1)</script>')
            check(staff.post('attendance_request',**req)[0]==200,'fully missing day request accepted')
            check(staff.post('attendance_request',**req)[0]==409,'duplicate pending request rejected')
            check('처리할 근태 정정 <strong>1건</strong>'.encode() in admin.get()[1],'dashboard counts pending corrections')
            check(b'#attendance-requests' in admin.get()[1] and b'id="attendance-requests"' in admin.get('admin-attendance')[1],'dashboard links directly to correction section')
            check('처리할 근태 정정'.encode() not in staff.get()[1],'employee dashboard hides admin queue')
            db.execute("UPDATE AttendanceRequest SET workDate=? WHERE userId='staff'",(str(today-dt.timedelta(days=40)),));db.commit()
            check('처리할 근태 정정 <strong>1건</strong>'.encode() in admin.get()[1],'dashboard includes previous-month pending requests')
            db.execute("UPDATE AttendanceRequest SET workDate=? WHERE userId='staff'",(old,));db.commit()
            request=row('SELECT * FROM AttendanceRequest WHERE userId=?',('staff',))
            check(other.post('attendance_decide',page='attendance',id=request['id'],decision='APPROVE')[0]==403,'staff cannot approve')
            check(b'missing' not in other.get('attendance')[1],'other employee cannot read private requests')
            check(b'&lt;script&gt;' in admin.get('admin-attendance')[1],'reason HTML escaped')
            check(admin.post('attendance_decide',page='admin-attendance',id=request['id'],decision='APPROVE')[0]==200,'admin approves missing day')
            check('처리할 근태 정정'.encode() not in admin.get()[1],'dashboard clears processed corrections')
            check(row('SELECT checkIn FROM AttendanceDay WHERE workDate=?',(old,))[0].endswith('00:00:00'),'KST input converted to UTC')
            check(admin.post('attendance_decide',page='admin-attendance',id=request['id'],decision='APPROVE')[0]==409,'double decision rejected')
            check(staff.post('attendance_request',**{**req,'checkOut':'19:00','reason':'correct checkout'})[0]==200,'correction submitted')
            pending=row("SELECT * FROM AttendanceRequest WHERE status='PENDING'")
            db.execute('UPDATE AttendanceDay SET version=version+1 WHERE workDate=?',(old,));db.commit()
            check(admin.post('attendance_decide',page='admin-attendance',id=pending['id'],decision='APPROVE')[0]==409,'stale correction rejected')
            check(admin.post('attendance_decide',page='admin-attendance',id=pending['id'],decision='REJECT',reason='record changed')[0]==200,'stale request can be rejected')
            check(admin.post('attendance_request',**{**req,'reason':'admin own request'})[0]==200,'admin own request')
            check('처리할 근태 정정'.encode() not in admin.get()[1],'dashboard excludes own and rejected requests')
            own=row("SELECT id FROM AttendanceRequest WHERE userId='admin'")[0]
            check(admin.post('attendance_decide',page='admin-attendance',id=own,decision='APPROVE')[0]==403,'self approval blocked')
            tomorrow=str(today+dt.timedelta(days=1))
            check(staff.post('attendance_request',**{**req,'workDate':tomorrow})[0]==400,'future correction rejected')
            check(staff.post('attendance_request',**{**req,'workDate':str(today-dt.timedelta(days=3)),'checkIn':'18:00','checkOut':'09:00'})[0]==400,'inverted times rejected')
            code,csv=admin.get('attendance-export',month=old[:7]);check(code==200 and csv.startswith(b'\xef\xbb\xbf') and b'staff' in csv,'monthly CSV export')
            check(row('SELECT count(*) FROM AttendanceHistory')[0]>=6,'punch and correction history retained')
            # Overnight closing uses the original work date.
            yesterday=str(today-dt.timedelta(days=1));stamp=dt.datetime.combine(today-dt.timedelta(days=1),dt.time(23),tzinfo=dt.timezone(dt.timedelta(hours=9))).astimezone(dt.timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
            db.execute("INSERT INTO AttendanceDay VALUES ('overnight','other',?,?,NULL,1,?)",(yesterday,stamp,stamp));db.commit()
            night_personal=other.get('attendance')[1]
            check('<h2>근무 중</h2>'.encode() in night_personal and '근무 시작일'.encode() in night_personal,'overnight personal page shows working and original date')
            check('출근 완료 · 근무 중'.encode() in other.get()[1],'overnight dashboard uses open shift')
            check(b'<td>other<small>' in admin.get('admin-attendance',tab='monthly',q='other',month=yesterday[:7],workStatus='working')[1],'overnight shift included by working filter')
            expired_stamp=(dt.datetime.now(dt.timezone.utc)-dt.timedelta(hours=25)).strftime('%Y-%m-%d %H:%M:%S')
            db.execute("UPDATE AttendanceDay SET checkIn=? WHERE id='overnight'",(expired_stamp,));db.commit()
            expired_page=other.get('attendance')[1]
            check('<h2>퇴근 누락</h2>'.encode() in expired_page and b'class="attendance-punch-form"' not in expired_page,'expired shift shows missing and removes unusable punch form')
            check('퇴근 누락'.encode() in other.get()[1],'expired shift dashboard agrees with personal page')
            db.execute("UPDATE AttendanceDay SET checkIn=? WHERE id='overnight'",(stamp,));db.commit()
            check(other.post('attendance_punch',**gps,page='attendance',workDate=yesterday,version='1',kind='out')[0]==200,'overnight checkout retains work date')
            # A full-day approved leave blocks check-in; half days remain allowed.
            db.execute("INSERT INTO LeaveRequest (id,userId,leaveType,startDate,endDate,days,reason,status,createdAt,updatedAt) VALUES ('att-leave','admin','ANNUAL',?,?,1,'test','APPROVED',?,?)",(str(today)+' 00:00:00',str(today)+' 00:00:00',stamp,stamp));db.commit()
            leave_revision=revision(admin);global_revision=revision(admin,'admin-attendance');private_revision=revision(staff)
            db.execute("UPDATE LeaveRequest SET status='CANCELLED' WHERE id='att-leave'");db.commit()
            check(revision(admin)!=leave_revision and revision(admin,'admin-attendance')!=global_revision,'leave cancellation changes personal and admin attendance revision even in same second')
            check(revision(staff)==private_revision,'another employee leave change does not affect private attendance revision')
            db.execute("UPDATE LeaveRequest SET status='APPROVED' WHERE id='att-leave'");db.commit()
            check(revision(admin)==leave_revision,'restored leave state restores matching attendance revision')
            check(admin.post('attendance_punch',**punch)[0]==409,'approved full-day leave blocks check-in')
            db.execute("UPDATE LeaveRequest SET leaveType='AM_HALF' WHERE id='att-leave'");db.commit()
            check(admin.post('attendance_punch',**punch)[0]==200,'approved half-day permits check-in')
            check('14:00–18:00'.encode() in admin.get('attendance')[1],'half-day schedule shown')
            trip={**gps,'latitude':37.40,'punchMode':'business_trip','tripReason':'고객사 방문'}
            check(other.post('attendance_punch',page='attendance',workDate=str(today),version='0',kind='in',**{**trip,'tripReason':''})[0]==400,'trip needs reason')
            check(other.post('attendance_punch',page='attendance',workDate=str(today),version='0',kind='in',**trip)[0]==200,'outside office trip accepted')
            check(row("SELECT punchMode FROM AttendanceLocation WHERE userId='other' AND kind='in'")[0]=='business_trip','trip evidence stored')
            check(row("SELECT COUNT(*) FROM AttendanceLocation WHERE userId='staff'")[0]==2,'separate check-in and checkout evidence')
            check('GPS 미확인'.encode() in staff.get('attendance',month=old[:7])[1],'corrected records are not labeled GPS verified')
            check(staff.post('attendance_punch',**{**punch,'verificationMethod':'network'})[0]==400,'retired network mode rejected')
            direct=Client(f'http://127.0.0.1:{port}').login('director')
            manual=dict(page='attendance',workDate=str(today),version='0',kind='in',verificationMethod='manual',entryTime='00:00',confirmSave='1')
            check(direct.post('attendance_punch',**{**manual,'confirmSave':''})[0]==400,'manual input requires confirmation')
            check(direct.post('attendance_punch',**{**manual,'entryTime':'25:00'})[0]==400,'invalid manual time rejected')
            check(direct.post('attendance_punch',**manual)[0]==200,'PC manual check-in accepted without GPS')
            check(direct.post('attendance_punch',**{**manual,'entryTime':'00:02','version':'1'})[0]==409,'saved check-in cannot be overwritten')
            check(direct.post('attendance_punch',**{**manual,'kind':'out','version':'1','entryTime':'00:00'})[0]==400,'manual checkout must follow check-in')
            check(direct.post('attendance_punch',**{**manual,'kind':'out','version':'1','entryTime':'00:01'})[0]==200,'PC may add missing checkout')
            check(direct.post('attendance_punch',**{**manual,'kind':'out','version':'2','entryTime':'00:02'})[0]==409,'saved checkout cannot be overwritten')
            check(row("SELECT COUNT(*) FROM AttendanceManual WHERE userId='director'")[0]==2,'manual input provenance retained')
            check('직접 입력'.encode() in direct.get('attendance')[1],'manual label visible')
            from http_test import BOOT, DOMAIN
            code=BOOT+DOMAIN+'require '+json.dumps((ROOT/'app/actions.php').as_posix())+';'+r'''
$_SERVER['HTTP_USER_AGENT']='Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Mobile';
$u=one('SELECT * FROM `User` WHERE id=?',['ceo']);
try{handleAction('attendance_punch',$u,['verificationMethod'=>'manual','entryTime'=>'00:00','confirmSave'=>'1','kind'=>'in','workDate'=>attendanceToday(),'version'=>'0']);throw new RuntimeException('mobile manual accepted');}catch(AppError $e){if($e->status!==403)throw $e;}
'''
            subprocess.run([PHP],input=('<?php '+code).encode(),env=env,check=True,capture_output=True)
            check(row("SELECT COUNT(*) FROM AttendanceDay WHERE userId='ceo'")[0]==0,'mobile cannot submit manual time')
            # Owners may change only pending requests; stale forms cannot decide a newer revision.
            import re
            editor=Client(f'http://127.0.0.1:{port}').login('leader')
            editday=str(today-dt.timedelta(days=10))
            edit=dict(page='attendance',workDate=editday,checkIn='09:00',checkOut='18:00',reason='original')
            check(editor.post('attendance_request',**edit)[0]==200,'editable pending request created')
            rid=row("SELECT id FROM AttendanceRequest WHERE userId='leader'")[0]
            def revision():
                return re.search(rb'name="requestToken" value="([a-f0-9]+)"',editor.get('attendance')[1])[1].decode()
            first=revision()
            check(other.post('attendance_request_edit',id=rid,**edit)[0]==403,'other employee cannot edit request')
            check(admin.post('attendance_request_cancel',page='admin-attendance',id=rid)[0]==403,'admin cannot cancel another owner request')
            check(editor.post('attendance_request_edit',csrf=False,id=rid,**edit)[0]==403,'edit CSRF rejected')
            check(editor.post('attendance_request_cancel',page='attendance',csrf=False,id=rid)[0]==403,'cancel CSRF rejected')
            check(editor.post('attendance_request_edit',id=rid,**{**edit,'checkOut':'08:00','reason':'keep this input'})[0]==400,'invalid edit rejected')
            check(row('SELECT reason FROM AttendanceRequest WHERE id=?',(rid,))[0]=='original','failed edit is atomic')
            code,body=editor.post('attendance_request_edit',id=rid,**{**edit,'checkOut':'19:00','reason':'updated'})
            check(code==200 and b'updated' in body,'pending request edited')
            check(row('SELECT checkOut FROM AttendanceRequest WHERE id=?',(rid,))[0].endswith('10:00:00'),'edit stores KST as UTC')
            check(row('SELECT count(*) FROM AttendanceDay WHERE userId=?',('leader',))[0]==0,'edit does not change attendance before approval')
            check(b'updated' in admin.get('admin-attendance')[1],'admin sees edited reason')
            check(admin.post('attendance_decide',page='admin-attendance',id=rid,decision='APPROVE',requestToken=first)[0]==409,'stale admin approval blocked after edit')
            check(editor.post('attendance_request_edit',id=rid,requestToken=first,**edit)[0]==409,'stale owner edit blocked')
            check(editor.post('attendance_request_cancel',page='attendance',id=rid,requestToken=first)[0]==409,'stale owner cancellation blocked')
            moved=str(today-dt.timedelta(days=11))
            check(editor.post('attendance_request_edit',id=rid,**{**edit,'workDate':moved,'checkIn':'22:00','checkOut':'06:00','nextDay':'1'})[0]==200,'date and overnight times editable')
            check(editor.post('attendance_request',**edit)[0]==200,'old date available after moving request')
            check(editor.post('attendance_request_edit',id=rid,**edit)[0]==409,'edit cannot collide with another pending request')
            check(editor.post('attendance_request_cancel',page='attendance',id=rid)[0]==200,'owner cancels pending request')
            check(row('SELECT status FROM AttendanceRequest WHERE id=?',(rid,))[0]=='CANCELLED','cancellation retained as status')
            check(editor.post('attendance_request_cancel',page='attendance',id=rid)[0]==409,'repeat cancellation rejected')
            check(admin.post('attendance_decide',page='admin-attendance',id=rid,decision='APPROVE')[0]==409,'cancelled request cannot be approved')
            check(editor.post('attendance_request',**{**edit,'workDate':moved})[0]==200,'cancelled date can be resubmitted')
            fresh=row("SELECT id FROM AttendanceRequest WHERE userId='leader' AND workDate=? AND status='PENDING'",(moved,))[0]
            check(admin.post('attendance_decide',page='admin-attendance',id=fresh,decision='APPROVE')[0]==200,'updated workflow remains approvable')
            check(editor.post('attendance_request_edit',id=fresh,**edit)[0]==409,'approved request cannot be edited')
            check(editor.post('attendance_request_cancel',page='attendance',id=fresh)[0]==409,'approved request cannot be cancelled')
            audit=row("SELECT beforeData,afterData FROM AttendanceHistory WHERE userId='leader' AND action='정정 신청 수정' AND reason='updated'")
            check(audit is not None and json.loads(audit[0])['reason']=='original','request edit audit preserves before snapshot')
            # Filtered monthly rows and CSV use identical person/state predicates.
            import csv,io
            filterday=str(today-dt.timedelta(days=20));filtermonth=filterday[:7]
            db.execute("INSERT INTO AttendanceDay VALUES ('filter-missing','viral',?,?,NULL,1,?)",(filterday,filterday+' 00:00:00',filterday+' 00:00:00'))
            db.commit()
            def exported(**filters):
                code,body=admin.get('attendance-export',**filters)
                assert code==200
                return list(csv.reader(io.StringIO(body.decode('utf-8-sig'))))[1:]
            narrowed=exported(month=filtermonth,q='VIR',department='바이럴팀',workStatus='missing')
            check(len(narrowed)==1 and narrowed[0][0]==filterday and narrowed[0][1]=='viral','CSV combines partial name department and missing status')
            check(not exported(month=filtermonth,q='viral',department='개발팀'),'department mismatch excludes rows')
            check(not exported(month=filtermonth,q='%'),'search wildcard is literal')
            check(not exported(month=filtermonth,q='viral',workStatus='complete'),'completed filter excludes missing checkout')
            check(not exported(month=filtermonth,workStatus='none'),'monthly CSV never fabricates no-record dates')
            view=admin.get('admin-attendance',tab='monthly',month=filtermonth,day=filterday,q='viral',department='바이럴팀',workStatus='missing')[1].decode()
            check('<td>viral<small>' in view and '조회 결과 CSV 다운로드' in view,'monthly tab renders matching person and CSV')
            check('workStatus=missing' in view and 'q=viral' in view,'CSV link retains active filters')
            check(view.count('name="q" value="viral"')>=1 and 'name="day" value="'+filterday+'"' in view,'date and month forms retain filter context')
            no_record=admin.get('admin-attendance',tab='daily',day=filterday,q='other',workStatus='none')[1].decode()
            check('<td>other</td>' in no_record,'daily no-record filter includes employee without record')
            check('<td>viral</td>' not in admin.get('admin-attendance',tab='daily',day=filterday,q='viral',workStatus='none')[1].decode(),'daily no-record filter excludes missing checkout')
            for tab in ['requests','daily','monthly','history']:
                code,body=admin.get('admin-attendance',tab=tab,q='viral',department='바이럴팀',month=filtermonth,day=filterday)
                html=body.decode()
                check(code==200 and 'aria-current="page"' in html,'admin attendance tab renders '+tab)
                check(('날짜별 직원 현황</h2>' in html)==(tab=='daily'),'daily roster isolated '+tab)
                check(('조회 결과 CSV 다운로드' in html)==(tab=='monthly'),'CSV isolated to month '+tab)
                check(('출퇴근·변경 이력 (' in html)==(tab=='history'),'history isolated '+tab)
                check('tab='+tab in html and 'q=viral' in html and 'department=' in html,'tabs retain employee context '+tab)
                check(staff.get('admin-attendance',tab=tab)[0]==403,'admin tab protected '+tab)
            check(admin.get('admin-attendance',tab='invalid')[0]==400,'unknown attendance tab rejected')
            for state in ['none','leave']:
                monthly=admin.get('admin-attendance',tab='monthly',month=filtermonth,q='viral',department='바이럴팀',workStatus=state)[1].decode()
                check('<td>viral<small>' in monthly,'daily-only state no longer empties monthly records '+state)
                check('<option value="'+state+'"' not in monthly and 'workStatus='+state not in monthly,'monthly selector and CSV omit daily-only state '+state)
            for bad in ['unknown','APPROVED']:
                check(admin.get('attendance-export',workStatus=bad)[0]==400,'invalid work status rejected '+bad)
            check(admin.get('admin-attendance',q='a'*101)[0]==400,'oversized name filter rejected')
            check(staff.get('attendance-export',q='viral',workStatus='missing')[0]==403,'filtered export remains admin only')
            check(filterday.encode() not in staff.get('attendance',q='viral',workStatus='missing')[1],'personal attendance cannot widen scope with filters')
            db.execute("UPDATE LeaveRequest SET leaveType='ANNUAL' WHERE id='att-leave'");db.commit()
            check(len(exported(month=str(today)[:7],q='테스트 관리자',workStatus='conflict'))==1,'holiday conflict remains separate from other states')
            # A filtered decision returns to the same month, day and employee selection.
            remaining=row("SELECT id FROM AttendanceRequest WHERE userId='leader' AND status='PENDING'")[0]
            code,returned=admin.post('attendance_decide',page='admin-attendance',id=remaining,decision='REJECT',reason='filter test',return_page='admin-attendance',return_tab='requests',return_q='leader',return_department='개발팀',return_workStatus='missing',return_day=filterday,return_month=filtermonth)
            check(code==200 and b'value="leader"' in returned and filtermonth.encode() in returned and filterday.encode() in returned,'decision preserves employee status and date filters')
        finally:
            if 'db' in locals(): db.close()
            server.terminate();server.wait(timeout=10)
