"""Read-only legacy SQLite export. Never edits the React/Node application or source DB."""
import argparse
import datetime as dt
import hashlib
import json
from pathlib import Path
import sqlite3
import zipfile

TABLES = ['User', 'CompanyHoliday', 'LeaveRequest', 'LeaveHistory', 'LeaveBalanceAdjustment', 'CardExpense', 'ReportDelivery']
DATES = {'joinDate', 'createdAt', 'updatedAt', 'passwordChangedAt', 'leaveBalanceAdjustedAt', 'usedAt', 'decidedAt', 'effectiveDate', 'startDate', 'endDate', 'deletedAt', 'teamLeaderApprovedAt', 'directorApprovedAt', 'ceoApprovedAt', 'date', 'sentAt'}

def normalize(key, value):
    if key in DATES and value is not None:
        if isinstance(value, (int, float)):
            value = dt.datetime.fromtimestamp(value / 1000, dt.timezone.utc)
        else:
            value = dt.datetime.fromisoformat(str(value).replace('Z', '+00:00'))
            if value.tzinfo:
                value = value.astimezone(dt.timezone.utc)
        return value.strftime('%Y-%m-%d %H:%M:%S.%f')[:-3]
    return value

def sql_value(value):
    if value is None: return 'NULL'
    if isinstance(value, (int, float)): return str(value)
    # Hex avoids dependence on MySQL SQL mode, escaping and connection encoding.
    return "CONVERT(X'" + str(value).encode('utf8').hex() + "' USING utf8mb4)"

def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--database', type=Path, required=True)
    p.add_argument('--receipts', type=Path, required=True)
    p.add_argument('--output', type=Path)
    p.add_argument('--check', action='store_true')
    args = p.parse_args()
    dbpath = args.database.resolve(strict=True)
    source = sqlite3.connect(dbpath.as_uri() + '?mode=ro', uri=True)
    source.row_factory = sqlite3.Row
    source.execute('BEGIN')  # Consistent read snapshot even if the source uses WAL.
    data = {table: [{k: normalize(k, v) for k, v in dict(row).items()} for row in source.execute(f'SELECT * FROM "{table}"')] for table in TABLES}
    integrity = source.execute('PRAGMA integrity_check').fetchone()[0]
    foreign_errors = list(source.execute('PRAGMA foreign_key_check'))
    source.close()
    if integrity != 'ok' or foreign_errors:
        raise SystemExit('Source database integrity check failed; export stopped.')
    if not any(u['role'] == 'ADMIN' and u['isActive'] for u in data['User']):
        raise SystemExit('No active administrator. Export stopped.')
    files = {}
    receipt_root = args.receipts.resolve()
    for card in data['CardExpense']:
        name = card.get('receiptFilePath')
        if not name: continue
        if Path(name).name != name or '\\' in name or '/' in name:
            raise SystemExit('Unexpected receipt path. Export stopped.')
        file = (receipt_root / name).resolve()
        if file.parent != receipt_root or not file.is_file():
            raise SystemExit('A referenced receipt is missing. Export stopped.')
        files[name] = file
    manifest = {'format': 1, 'createdAt': dt.datetime.now(dt.timezone.utc).isoformat(), 'counts': {k: len(v) for k, v in data.items()}, 'sessionsMigrated': False, 'receipts': {k: hashlib.sha256(v.read_bytes()).hexdigest() for k, v in files.items()}}
    if args.check:
        print(json.dumps({'integrity': 'ok', 'counts': manifest['counts'], 'receiptCount': len(files), 'sourceModified': False}))
        return
    if not args.output: p.error('--output is required without --check')
    if args.output.exists(): raise SystemExit('Output already exists; refusing to overwrite.')
    args.output.parent.mkdir(parents=True, exist_ok=True)
    statements = ['-- Import ONLY into a new, empty PHP application database after mysql.sql.', '-- Existing login sessions are deliberately excluded.', 'SET NAMES utf8mb4;', 'START TRANSACTION;']
    for table, entries in data.items():
        for row in entries:
            cols = ','.join(f'`{k}`' for k in row)
            statements.append(f'INSERT INTO `{table}` ({cols}) VALUES (' + ','.join(sql_value(v) for v in row.values()) + ');')
    statements.append('COMMIT;')
    with zipfile.ZipFile(args.output, 'x', zipfile.ZIP_DEFLATED) as archive:
        archive.writestr('manifest.json', json.dumps(manifest, ensure_ascii=False, indent=2))
        archive.writestr('data.json', json.dumps(data, ensure_ascii=False))
        archive.writestr('data.sql', '\n'.join(statements))
        for name, file in files.items(): archive.write(file, 'receipts/' + name)
    print(json.dumps({'exported': True, 'counts': manifest['counts'], 'receiptCount': len(files), 'sourceModified': False}))

if __name__ == '__main__': main()
