"""Test fresh installation, legacy timestamps, MySQL import and concurrent reservations."""
import datetime as dt
import hashlib
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
import zipfile
from http_test import ROOT, PHP, BOOT, DOMAIN, SETUP, QUERY, Client, PHPConnection, PASSWORD

def php(code,env):
    return subprocess.run([PHP],input=('<?php '+code).encode('utf8'),env=env,capture_output=True,check=True).stdout

def config(file,dsn,storage,token='',username=''):
    values={'dsn':dsn,'username':username,'password':'','secure_cookie':False,'storage':str(storage).replace('\\','/'),'setup_token':token,'timezone':'Asia/Seoul'}
    # PHP's JSON decoder consumes the literal; backslashes/quotes are escaped for PHP single quotes.
    literal=json.dumps(values).replace('\\','\\\\').replace("'","\\'")
    file.write_text("<?php return json_decode('"+literal+"',true);",encoding='utf8')
    return {**os.environ,'MNM_CONFIG':str(file)}

def run():
    with tempfile.TemporaryDirectory(prefix='mnm-deployment-') as temp:
        work=Path(temp);store=work/'storage';store.mkdir();token='local-test-install-token-'+'a'*32
        env=config(work/'config.php','sqlite:'+(work/'install.db').as_posix(),store,token)
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        log=open(work/'server.log','w+',encoding='utf8')
        server=subprocess.Popen([PHP,'-S',f'127.0.0.1:{port}','-t',str(ROOT/'public')],env=env,stdout=log,stderr=log)
        try:
            c=Client(f'http://127.0.0.1:{port}')
            for _ in range(50):
                try:c.get('setup');break
                except OSError:time.sleep(.1)
            assert c.get('setup')[0]==200
            assert c.post('setup',page='setup',setup_token='wrong',email='admin',name='Administrator',password=PASSWORD)[0]==403
            assert c.post('setup',page='setup',setup_token=token,email='admin',name='Administrator',password=PASSWORD)[0]==200
            assert c.get('setup')[0]==403
            c.login('admin')
            assert '비밀번호 변경'.encode() in c.get()[1]
            print('PASS token-protected fresh installation, replay prevention and initial password change')
        finally:server.terminate();server.wait(timeout=10);log.close()

        legacy=work/'legacy.db';legacy_store=work/'legacy-storage';legacy_store.mkdir();(legacy_store/'receipts').mkdir()
        schema=(ROOT/'database/sqlite.sql').read_text(encoding='utf8')
        dates=['joinDate','createdAt','updatedAt','passwordChangedAt','leaveBalanceAdjustedAt','usedAt','decidedAt','effectiveDate','startDate','endDate','deletedAt','teamLeaderApprovedAt','directorApprovedAt','ceoApprovedAt','date','sentAt','expiresAt']
        for field in dates:schema=schema.replace('`'+field+'` TEXT','`'+field+'` DATETIME')
        conn=sqlite3.connect(legacy);conn.executescript(schema);conn.close()
        legacy_env=config(work/'legacy.php','sqlite:'+legacy.as_posix(),legacy_store)
        php(SETUP,legacy_env)
        # Preserve compatibility with actual bcryptjs-generated hashes without using original credentials.
        node=Path(__file__).resolve().parents[2]/'node_modules/bcryptjs'
        if node.exists():
            code='process.stdout.write(require('+json.dumps(str(node))+').hashSync("Test-password1!",10))'
            hashed=subprocess.check_output(['node','-e',code],text=True)
            conn=sqlite3.connect(legacy);conn.execute('UPDATE User SET passwordHash=? WHERE id=?',(hashed,'staff'));conn.commit();conn.close()
            assert php(BOOT+'if(!password_verify("Test-password1!",one("SELECT * FROM `User` WHERE id=?",["staff"])["passwordHash"]))throw new RuntimeException("bcrypt compatibility");echo "ok";',legacy_env)==b'ok'
            print('PASS existing bcryptjs password hashes work in PHP')
        conn=sqlite3.connect(legacy)
        tables=[r[0] for r in conn.execute("SELECT name FROM sqlite_master WHERE type='table'")]
        for table in tables:
            columns=[r[1] for r in conn.execute('PRAGMA table_info(`'+table+'`)')]
            for field in set(columns)&set(dates):
                for id,value in list(conn.execute('SELECT id,`'+field+'` FROM `'+table+'` WHERE `'+field+'` IS NOT NULL')):
                    timestamp=int(dt.datetime.fromisoformat(value).replace(tzinfo=dt.timezone.utc).timestamp()*1000)
                    conn.execute('UPDATE `'+table+'` SET `'+field+'`=? WHERE id=?',(timestamp,id))
        conn.commit();conn.close()
        before=hashlib.sha256(legacy.read_bytes()).hexdigest();archive=work/'legacy.zip'
        subprocess.run(['python',str(ROOT/'tools/export_legacy.py'),'--database',str(legacy),'--receipts',str(legacy_store/'receipts'),'--output',str(archive)],check=True)
        assert before==hashlib.sha256(legacy.read_bytes()).hexdigest()
        with zipfile.ZipFile(archive) as z:
            exported=json.loads(z.read('data.json'))
            assert next(u for u in exported['User'] if u['id']=='staff')['joinDate']=='2020-01-01 00:00:00.000'
            data_sql=z.read('data.sql')
        print('PASS millisecond timestamps normalized, source byte-for-byte preserved')

        dsn=os.environ.get('MNM_DEPLOY_MYSQL_DSN')
        if dsn:
            if 'dbname=mnm_php_test_' not in dsn:raise RuntimeError('Disposable test DB required')
            target_store=work/'target-storage';target_store.mkdir();target_env=config(work/'target.php',dsn,target_store,username='root')
            php(BOOT+'require '+json.dumps((ROOT/'app/setup.php').as_posix())+';installSchema();',target_env)
            subprocess.run([PHP,str(ROOT/'tools/import.php'),str(archive)],env=target_env,check=True)
            c=PHPConnection(target_env)
            assert c.execute('SELECT joinDate FROM User WHERE id=?',['staff']).fetchone()[0]=='2020-01-01 00:00:00.000'
            assert c.execute('SELECT COUNT(*) FROM Session').fetchone()[0]==0
            print('PASS legacy export imported into MariaDB with IDs and timestamps preserved')
            action_require='require '+json.dumps((ROOT/'app/actions.php').as_posix())+';'
            create=BOOT+DOMAIN+action_require+r'''$u=one('SELECT * FROM `User` WHERE id=?',['staff']);try{handleAction('leave_create',$u,['leaveType'=>'ANNUAL','startDate'=>'2026-10-01','endDate'=>'2026-10-01','reason'=>'Concurrent test']);echo 'OK';}catch(AppError $e){echo $e->status;}'''
            jobs=[subprocess.Popen([PHP],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,env=target_env) for _ in range(2)]
            for job in jobs:job.stdin.write(('<?php '+create).encode());job.stdin.close();job.stdin=None
            results=sorted(job.communicate(timeout=20)[0].decode() for job in jobs)
            assert results==['409','OK'],results
            assert c.execute('SELECT COUNT(*) FROM LeaveRequest').fetchone()[0]==1
            print('PASS concurrent identical leave requests create exactly one row')
            report=work/'report.xlsx';php(BOOT+DOMAIN+'require '+json.dumps((ROOT/'app/downloads.php').as_posix())+';writeReport(2026,'+json.dumps(report.as_posix())+');',target_env)
            try:
                import openpyxl
                book=openpyxl.load_workbook(report)
                assert book.active['A3'].value=='이름' and book.active['A4'].value=='staff'
                book.close();print('PASS XLSX opens with spreadsheet parser')
            except ImportError:print('SKIP optional openpyxl parser (ZIP/XML covered by HTTP tests)')
        sql_dsn=os.environ.get('MNM_SQL_IMPORT_DSN')
        if sql_dsn:
            if 'dbname=mnm_php_test_' not in sql_dsn:raise RuntimeError('Disposable test DB required')
            sql_store=work/'sql-storage';sql_store.mkdir();sql_env=config(work/'sql.php',sql_dsn,sql_store,username='root')
            php(BOOT+'require '+json.dumps((ROOT/'app/setup.php').as_posix())+';installSchema();',sql_env)
            sqlfile=work/'data.sql';sqlfile.write_bytes(data_sql)
            php(BOOT+'$text=file_get_contents('+json.dumps(sqlfile.as_posix())+');foreach(explode(";",$text)as $q)if(trim($q)!=="")db()->exec($q);',sql_env)
            assert PHPConnection(sql_env).execute('SELECT COUNT(*) FROM User').fetchone()[0]==8
            print('PASS phpMyAdmin-compatible SQL import on MariaDB')

if __name__=='__main__':run()
