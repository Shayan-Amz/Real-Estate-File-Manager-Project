"""Package recovery runtime files only. Never bundle host config, ACLs or user uploads."""
from pathlib import Path
import hashlib
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
files = [
    'stt-recovery-29.php', 'api-recovery-29.php',
    'stt.php', 'api.php',
    'assets/jalalidatepicker.min.js', 'assets/jalalidatepicker.min.css',
    'assets/chart.umd.js', 'assets/Vazirmatn.woff2', 'assets/logo.png',
    'assets/images/marker-icon.png', 'assets/images/marker-icon-2x.png', 'assets/images/marker-shadow.png',
    'tools/health_check.php', 'sw.js', 'index.html', 'recover29.html',
]
for relative in ['api.php', 'stt.php', 'tools/health_check.php']:
    assert (ROOT / 'src' / relative).read_bytes() == subprocess.check_output(
        ['git', 'show', 'a24348e:src/' + relative], cwd=ROOT
    )
assert (ROOT / 'src/index.html').read_bytes() == (ROOT / 'src/recover29.html').read_bytes()
contents = {name: (ROOT / 'src' / name).read_bytes() for name in files}
contents['README.fa.txt'] = (ROOT / 'recovery/README.fa.txt').read_bytes()
out = ROOT / 'fix29-safe-rollback.zip'
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for name, body in contents.items():
        info = zipfile.ZipInfo(name, (2026, 9, 8, 12, 0, 0))
        info.external_attr = 0o100644 << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        archive.writestr(info, body, compresslevel=9)
with zipfile.ZipFile(out) as archive:
    assert archive.testzip() is None
    assert archive.namelist() == list(contents)
    for name, body in contents.items():
        assert archive.read(name) == body
    assert not any(name == 'config.php' or name.endswith('.htaccess') or name.startswith('uploads/') for name in archive.namelist())
print(out.name, out.stat().st_size, 'bytes; SHA256', hashlib.sha256(out.read_bytes()).hexdigest())
