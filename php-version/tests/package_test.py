"""Exercise the actual extracted upload archive using disposable local data."""
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import zipfile
from deployment_test import config
from http_test import ROOT, PHP, Client, PASSWORD

with tempfile.TemporaryDirectory(prefix='mnm-package-') as temp:
    work = Path(temp)
    with zipfile.ZipFile(ROOT/'release/mnm-php-cafe24.zip') as archive:
        names = archive.namelist()
        assert 'mnm-private/app/bootstrap.php' in names
        assert 'mnm-private/tools/import.php' in names
        assert not any(n.endswith(('.db', '.env', 'local.php', '.pyc')) or '/tests/' in n for n in names)
        archive.extractall(work)
    private = work/'mnm-private'
    token = 'package-test-' + 'a'*40
    config(private/'config/local.php', 'sqlite:'+(private/'storage/test.db').as_posix(), private/'storage', token)
    env = dict(os.environ)
    env.pop('MNM_CONFIG', None)  # Exercise the packaged default config path.
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    with open(work/'server.log', 'w+', encoding='utf8') as log:
        server = subprocess.Popen([PHP, '-S', f'127.0.0.1:{port}', '-t', str(work/'www')], env=env, stdout=log, stderr=log)
        try:
            client = Client(f'http://127.0.0.1:{port}')
            for _ in range(50):
                try:
                    client.get('setup')
                    break
                except OSError:
                    time.sleep(.1)
            assert client.get('setup')[0] == 200
            assert client.post('setup', page='setup', setup_token=token, email='admin', name='Administrator', password=PASSWORD)[0] == 200
            client.login('admin')
            assert client.get()[0] == 200
            assert client.post('password',page='password',currentPassword=PASSWORD,newPassword='Changed-password2!',confirmPassword='Changed-password2!')[0]==200
            assert '출퇴근 기록'.encode() in client.get('attendance')[1]
            assert '날짜별 직원 현황'.encode() in client.get('admin-attendance')[1]
            assert client.get('attendance-export')[1].startswith(b'\xef\xbb\xbf')
        finally:
            server.terminate()
            server.wait(timeout=10)
print('PASS extracted upload ZIP: private config resolution, installation, login and page rendering; no local data included')
