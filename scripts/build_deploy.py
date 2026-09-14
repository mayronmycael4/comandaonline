"""Build the PHP release without customer folders, test data or local secrets."""
from pathlib import Path
import base64
import os
import shutil
import subprocess

root = Path(__file__).resolve().parents[1]
target = root / 'deploy'
if target.exists():
    raise SystemExit('deploy already exists; use a clean checkout')
excluded = {'.github', '.git', 'clientes', 'backups', 'logs', 'tests', 'scripts',
            'node_modules', 'saas-app', 'app', 'components', 'lib', 'database', 'migrations'}
schemas = {'admin/schema_legacy.sql', 'admin/schema_saas.sql'}
files = subprocess.check_output(['git', 'ls-files', '-z'], cwd=root).decode().split('\0')
for name in filter(None, files):
    path = Path(name)
    if any(part in excluded for part in path.parts):
        continue
    if name.startswith('admin/storage/') or path.name.startswith(('tmp_', '.env')):
        continue
    if path.suffix in {'.md', '.sql'} and name not in schemas:
        continue
    if 'db_runtime_config' in path.name or ' (1)' in path.name:
        continue
    source = root / path
    if not source.is_file() or source.is_symlink():
        continue
    destination = target / path
    destination.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(source, destination)

password = os.environ.get('SAAS_DB_PASSWORD')
if not password:
    raise SystemExit('Missing SAAS_DB_PASSWORD secret; no upload permitted')
encoded = base64.b64encode(password.encode()).decode()
(target / 'admin/db_runtime_config.php').write_text("""<?php
return [
    'host' => 'sql111.infinityfree.com',
    'port' => 3306,
    'dbname' => 'if0_40322863_saascomanda',
    'user' => 'if0_40322863',
    'pass' => base64_decode('%s'),
    'shared_tenant_db' => 'if0_40322863_comanda_online',
];
""" % encoded, encoding='utf-8')
for schema in schemas:
    if not (target / schema).is_file():
        raise SystemExit('Provisioning schema missing: ' + schema)
print('PHP release prepared; customer folders excluded; provisioning schemas included')
