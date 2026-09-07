"""Recreate the emergency UI from the last user-approved application snapshot.
No config.php, database, uploads, or academic sources are changed.
"""
from pathlib import Path
import subprocess


def old(rel):
    return subprocess.check_output(['git', 'show', 'a24348e:' + rel])


s = old('src/index.html').decode()
boot = Path('recovery/startup29.js').read_text()

def replace(a, b, count=1):
    global s
    assert s.count(a) == count, (s.count(a), a[:100])
    s = s.replace(a, b, 1)

for source in [
    '    <link rel="stylesheet" href="https://static.neshan.org/sdk/leaflet/v1.9.4/neshan-sdk/v1.0.8/index.css"/>',
    '    <script src="https://static.neshan.org/sdk/leaflet/v1.9.4/neshan-sdk/v1.0.8/index.js"></script>',
    '    <link rel="stylesheet" href="assets/jalalidatepicker.min.css" />',
    '    <script src="assets/jalalidatepicker.min.js"></script>',
    '    <script src="assets/chart.umd.js"></script>',
]:
    replace(source, '    <!-- fix29: optional resource loaded without blocking startup -->')
replace('<title>سیستم یکپارچه املاک</title>', '<title>املاک من — بازیابی ۲۹</title>\n    <meta name="amlak-build" content="fix29-baseline26">\n    <script>\n' + boot + '\n    </script>')
replace("url('assets/Vazirmatn.woff2')", "url('assets/Vazirmatn.woff2?v=fix29')")
replace('font-display: block;', 'font-display: swap;')
panel = '''
    <details id="recovery29-panel" style="position:fixed;left:10px;bottom:10px;z-index:20000;max-width:calc(100vw - 20px);width:420px;background:#fff;color:#172b3d;border:1px solid #217b83;border-radius:10px;padding:9px 12px;box-shadow:0 2px 18px #0002;font:13px/1.8 Tahoma,sans-serif;direction:rtl;">
        <summary style="cursor:pointer;font-weight:bold;">بازیابی ۲۹ · نسخهٔ پایه ۲۶ · گزارش بارگذاری</summary>
        <pre id="recovery29-log" style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:190px;overflow:auto;font:12px/1.9 Tahoma,sans-serif;">در حال راه‌اندازی صفحه؛ این نسخه منتظر سرویس نقشه نمی‌ماند.</pre>
        <button id="recovery29-copy" type="button" style="padding:5px 10px;cursor:pointer;">کپی گزارش بدون اطلاعات حساب</button>
        <noscript>اجرای جاوااسکریپت مرورگر غیرفعال است.</noscript>
    </details>
'''
# The later body tags belong to printable catalog templates. Only the first is the page.
assert s.find('<body>') < s.find('id="landing-view"')
s = s.replace('<body>', '<body>' + panel, 1)
for area, replacement in [('localStorage', 'AmlakRecovery.local'), ('sessionStorage', 'AmlakRecovery.session')]:
    for method in ['getItem', 'setItem', 'removeItem']:
        s = s.replace(area + '.' + method + '(', replacement + '.' + method + '(')
a = s.index("        let userProfile = JSON.parse(AmlakRecovery.local.getItem('amlakProfile'))")
b = s.index('        let properties = [], demands = [], membersList = [];', a)
s = s[:a] + '''        const recoveryLocalProfile = AmlakRecovery.readObject('local', 'amlakProfile');
        const recoveryGuestProfile = AmlakRecovery.readObject('session', 'guestProfile');
        let userProfile = recoveryLocalProfile && recoveryLocalProfile.role !== 'مهمان' ? recoveryLocalProfile : recoveryGuestProfile;
        if (userProfile && (!['مدیر','مشاور','مهمان'].includes(userProfile.role)
            || (userProfile.role !== 'مهمان' && (typeof userProfile.token !== 'string' || !userProfile.token
                || typeof userProfile.name !== 'string' || typeof userProfile.agencyId !== 'string')))) {
            userProfile = null;
            AmlakRecovery.report('نشست ذخیره‌شده کامل نیست؛ دوباره وارد حساب شوید. داده‌ای حذف نشده است.');
        }
''' + s[b:]
replace("        const API_URL = 'api.php';", "        const API_URL = 'api-recovery-29.php'; // Fresh compiled copy of the approved fix26 API.")
replace("navigator.serviceWorker.register('sw.js')", "navigator.serviceWorker.register('sw.js?v=fix29')")
replace("            setTimeout(() => { try { if(typeof jalaliDatepicker !== 'undefined') jalaliDatepicker.startWatch({ time: false, hasSecond: false }); } catch(e){} }, 500);", '            // fix29: datepicker starts after its optional local files have loaded.')
replace('''        const res = await fetch(`${API_URL}?action=${action}&_t=${Date.now()}`, options);
        
        const textContent = await res.text();''', '''        const transport = await AmlakRecovery.fetchText(`${API_URL}?action=${action}&_t=${Date.now()}`, options, action);
        const res = transport.response;
        const textContent = transport.text;''')
replace('        if (data.error) throw new Error(data.error);', "        if (data.error) { AmlakRecovery.report(action + ': ' + data.error); throw new Error(data.error); }")
replace('        console.error("خطای API:", err.message);', "        AmlakRecovery.report('API ' + action + ': ' + err.message);\n        console.error(\"خطای API:\", err.message);")
replace("            catch (e) { isOnline = false; const cached = AmlakRecovery.local.getItem('amlakDataCache'); if (cached) processData(JSON.parse(cached)); }", "            catch (e) { isOnline = false; AmlakRecovery.report('دریافت اطلاعات ناموفق بود: ' + e.message); }")
replace("            catch(e) { const cached = AmlakRecovery.local.getItem('amlakDataCache'); if (cached) { document.getElementById('landing-view').classList.add('hidden'); document.getElementById('auth-view').classList.add('hidden'); document.getElementById('main-app').classList.remove('hidden'); setupAppUI(); startListeningToData(); } else { window.goToAuth(); } }", "            catch(e) { AmlakRecovery.report('بررسی ورود ناموفق بود: ' + e.message); document.getElementById('main-app').classList.add('hidden'); window.goToAuth(); }")
replace('        boot();', '        // fix29: boot is scheduled only after every handler below is defined.')
replace('        \n</script>\n  <button id="jarvis-btn"', '''        
        setTimeout(function () {
            try { boot(); } catch (error) { AmlakRecovery.report('راه‌اندازی: ' + error.message); }
            AmlakRecovery.optionalAssets();
        }, 0);
</script>
  <button id="jarvis-btn"''')
replace('window.renderDashboard = function() {', "window.renderDashboard = function() { if (typeof Chart === 'undefined') return;")
replace("    if (typeof L === 'undefined') return;", '''    if (!AmlakRecovery.mapReady) {
        AmlakRecovery.loadMap().then(function () {
            if (!document.getElementById('map-modal').classList.contains('hidden')) window.openMapModal();
        }).catch(function (error) { window.showToast(error.message); });
        return;
    }''', count=2)
replace("    if (typeof L === 'undefined') return;", '''    if (!AmlakRecovery.mapReady) {
        AmlakRecovery.loadMap().then(function () {
            if (!document.getElementById('map-view').classList.contains('hidden')) window.renderMainMap();
        }).catch(function (error) { window.showToast(error.message); });
        return;
    }''')
replace('        const observer = new IntersectionObserver((entries, observer) => {', "        if (typeof IntersectionObserver === 'undefined') { cards.forEach(card => card.classList.add('is-visible')); return; }\n        const observer = new IntersectionObserver((entries, observer) => {")
Path('src/index.html').write_text(s)
Path('src/recover29.html').write_text(s)
for filename in ['src/api.php', 'src/stt.php', 'src/tools/health_check.php', 'tests/test_get_data_scope.py']:
    Path(filename).write_bytes(old(filename))
api = old('src/api.php').decode()
assert api.startswith('<?php\n')
api = api.replace('<?php\n', "<?php\n// Recovery 29: approved fix26 API under a fresh opcode-cache path.\nheader('X-Amlak-Recovery: fix29-baseline26');\ndefine('AMLAK_RECOVERY_29', true);\n", 1)
api = api.replace("require_once __DIR__ . '/stt.php';", "require_once __DIR__ . '/stt-recovery-29.php';", 1)
Path('src/api-recovery-29.php').write_text(api)
Path('src/stt-recovery-29.php').write_text(old('src/stt.php').decode().replace('<?php', "<?php\nif (!defined('AMLAK_RECOVERY_29')) { http_response_code(404); exit; }\n", 1))
print('Restored fix26 server logic; recovery UI has no blocking map dependency.')
