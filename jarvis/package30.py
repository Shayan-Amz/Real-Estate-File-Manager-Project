"""Build the tested fix30 delta and a rollback that retains the accepted fix29 recovery."""
from pathlib import Path
import hashlib
import subprocess
import zipfile

ROOT=Path(__file__).resolve().parents[1]
files=['jarvis-prompt30.txt','api.php','api-recovery-29.php','index.html','recover29.html']
html=(ROOT/'src/index.html').read_text()
assert html==(ROOT/'src/recover29.html').read_text()
for source in ['jarvis/fields30.js','jarvis/bridge30.js']:
    assert (ROOT/source).read_text() in html

def pack(name,contents):
    p=ROOT/name
    with zipfile.ZipFile(p,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as archive:
        for path,content in contents.items():
            info=zipfile.ZipInfo(path,(2026,9,8,12,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16
            archive.writestr(info,content,compresslevel=9)
    with zipfile.ZipFile(p) as archive:
        assert archive.testzip() is None
        assert archive.namelist()==list(contents)
        for path,content in contents.items():assert archive.read(path)==content
        assert not any(path.endswith('config.php') or path.endswith('.htaccess') or path.startswith('uploads/') for path in archive.namelist())
    print(name,p.stat().st_size,'bytes; SHA256',hashlib.sha256(p.read_bytes()).hexdigest())

current={f:(ROOT/'src'/f).read_bytes() for f in files}
current['README.fa.txt']=(ROOT/'jarvis/README.fa.txt').read_bytes()
pack('fix30-jarvis-form.zip',current)
prior={f:subprocess.check_output(['git','show','f6b8687:src/'+f],cwd=ROOT) for f in files if f!='jarvis-prompt30.txt'}
prior['README.fa.txt']='''برگشت فقط در صورت مشکلِ نسخهٔ ۳۰
================================
این بسته چهار فایل برنامه را با هم به نسخهٔ بازیابی ۲۹ که تأیید کرده بودی
برمی‌گرداند. در public_html استخراج و جایگزینی فایل‌های هم‌نام را تأیید کن.
سپس recover29.html?api.php=29 را دوباره بارگذاری کن.
config.php، sw.js، stt و دیتابیس تغییر نمی‌کنند. jarvis-prompt30.txt اگر
باقی بماند در کد نسخهٔ برگشت استفاده نمی‌شود.
این بسته را همزمان با بستهٔ اصلی نصب نکن؛ فقط برای برگشت در صورت مشکل است.
'''.encode()
pack('fix30-rollback.zip',prior)
