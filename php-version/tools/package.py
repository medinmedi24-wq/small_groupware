"""Build a Cafe24 upload archive without credentials, data, tests or Node dependencies."""
from pathlib import Path
import zipfile

root=Path(__file__).resolve().parents[1]
required=['app/attendance-network.php','app/attendance-location.php','public/assets/attendance-gps.js','app/attendance.php','app/attendance-views.php','database/attendance-mysql.sql','database/attendance-sqlite.sql','app/bootstrap.php','app/domain.php','app/approval-policy.php','app/actions.php','app/views.php','app/ui.php','app/downloads.php','app/setup.php','tools/import.php','public/index.php','database/mysql.sql','config/example.php']
missing=[name for name in required if not (root/name).is_file()]
if missing:
    raise SystemExit('Package blocked: required source files are missing: '+', '.join(missing))
output=root/'release'/'mnm-php-cafe24.zip'
output.parent.mkdir(exist_ok=True)
with zipfile.ZipFile(output,'w',zipfile.ZIP_DEFLATED) as archive:
    for file in (root/'public').rglob('*'):
        if not file.is_file():continue
        relative=file.relative_to(root/'public').as_posix()
        if relative=='index.php':
            text=file.read_text(encoding='utf8').replace("dirname(__DIR__).'/app/", "dirname(__DIR__).'/mnm-private/app/")
            archive.writestr('www/index.php',text)
        else:archive.write(file,'www/'+relative)
    for directory in ['app','database','tools']:
        for file in (root/directory).glob('*'):
            if file.is_file() and file.name!='package.py':archive.write(file,'mnm-private/'+directory+'/'+file.name)
    for file in ['config/example.php','storage/.htaccess','storage/.gitkeep','.htaccess']:
        archive.write(root/file,'mnm-private/'+file)
    for file in ['README.md','DEPLOY-CAFE24.md','VALIDATION.md','APPROVAL-CHANGE.md','ATTENDANCE.md']:
        if (root/file).is_file():archive.write(root/file,file)
print(f'Package: {output} ({output.stat().st_size} bytes)')
