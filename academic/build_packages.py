"""Build deployment-safe academic artifacts; never bundle runtime secrets or data."""
from pathlib import Path
import hashlib
import zipfile

ROOT = Path(__file__).resolve().parents[1]


def build(name, folder, prefix):
    folder = ROOT / folder
    files = {}
    for path in sorted(folder.rglob('*')):
        if not path.is_file():
            continue
        relative = path.relative_to(folder)
        if path.name in {'config.php', 'state.php', 'key.php'} or '__pycache__' in relative.parts:
            continue
        files[prefix + '/' + relative.as_posix()] = path.read_bytes()
    output = ROOT / name
    with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for name, content in files.items():
            entry = zipfile.ZipInfo(name, (2026, 9, 8, 12, 0, 0))
            entry.compress_type = zipfile.ZIP_DEFLATED
            entry.external_attr = 0o100644 << 16
            archive.writestr(entry, content, compresslevel=9)
    with zipfile.ZipFile(output) as archive:
        assert archive.testzip() is None
        assert all(archive.read(name) == content for name, content in files.items())
        assert all(name.startswith(prefix + '/') for name in archive.namelist())
        assert not any(name.endswith('/config.php') or name.endswith('/state.php') for name in archive.namelist())
    print(output.name, output.stat().st_size, 'bytes; SHA256', hashlib.sha256(output.read_bytes()).hexdigest())


if __name__ == '__main__':
    build('fix28-thesis-evaluation.zip', 'academic/evaluation', 'thesis-evaluation')
    build('fix28-defense-source.zip', 'academic/defense-src', 'defense-version')
