<?php
/**
 * 🔬 ابزار تشخیصی کندی/خطای پنل‌ها
 * ---------------------------------------------------
 * وقتی «صفحهٔ اصلی» به‌جای پنل مدیریت می‌آید، معمولاً یکی از این‌هاست:
 *   ۱) سرور/PHP کند یا در حال کرش (تایم‌اوت) — و Service Worker با index.html کش‌شده
 *      آن را قایم می‌کند (با sw.js جدید دیگر قایم نمی‌شود).
 *   ۲) فایل پنل روی هاست نسخهٔ جدید نیست / HTML کش قدیمی.
 *   ۳) کرون‌جاب بک‌آپ یا اسکریپت دیگری دیتابیس را قفل کرده.
 *   ۴) خطای PHP که در error_log ثبت می‌شود.
 *
 * این ابزار همهٔ این‌ها را با زمان‌سنجی واقعی نشان می‌دهد.
 *
 * 🔑 اجرا:
 *   https://دامنه/diag.php?key=diag-amlak-9f3c71ab60d54e8f
 *
 * ⚠️ بعد از استفاده، این فایل را از public_html حذف کن.
 */

const DIAG_KEY = 'diag-amlak-9f3c71ab60d54e8f';
if (($_GET['key'] ?? '') !== DIAG_KEY) {
    http_response_code(403);
    exit("403 — کلید لازم است.\n");
}

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '0');

$t0 = microtime(true);
$w = function ($k, $v) { echo str_pad($k, 34) . ' : ' . $v . "\n"; };
$sec = function ($t) { return round((microtime(true) - $t) * 1000) . ' ms'; };

echo "════════════════════════════════════════════\n";
echo " 🔬 تشخیص کندی سرور — " . date('Y-m-d H:i:s') . "\n";
echo "════════════════════════════════════════════\n";

/* ── ۱) محیط ── */
$w('کل زمان اجرا (تا اینجا)', $sec($t0));
$w('PHP', PHP_VERSION);
$w('SAPI', PHP_SAPI);
$w('max_execution_time', ini_get('max_execution_time'));
$w('memory_limit', ini_get('memory_limit'));
$w('error_log path', ini_get('error_log') !== '' ? ini_get('error_log') : '(پیش‌فرض سرور)');
if (function_exists('sys_getloadavg')) { $la = sys_getloadavg(); $w('Load average (1/5/15)', implode(' / ', array_map(fn($x) => number_format($x, 2), $la))); }
$w('Disk free (root)', function_exists('disk_free_space') ? number_format(disk_free_space(__DIR__) / 1048576, 1) . ' MB' : 'n/a');

/* ── ۲) فایل‌ها ── */
echo "\n── فایل‌های مهم (وجود + حجم + زمان تغییر) ──\n";
foreach ([
    'config.php', 'api.php', 'index.html', 'sw.js', '.htaccess',
    'super_admin_license_x_382967.php', 'amlak_creator_b_1687.php', 'backup.php',
    'fix_pin.php', 'set_keys.php', 'stt_test.php', 'tools/health_check.php',
] as $f) {
    $p = __DIR__ . '/' . $f;
    if (is_file($p)) {
        $w($f, sprintf('%s | %s | mtime=%s', number_format(filesize($p)), $sec(filemtime($p)), date('m-d H:i', filemtime($p))));
    } else {
        $w($f, '❌ موجود نیست');
    }
}

/* ── ۳) time تست فایل PHP بدون DB (کشف کندی خود PHP/هاست) ── */
echo "\n── زمان‌سنجی پایهٔ PHP ──\n";
$tt = microtime(true);
for ($i = 0; $i < 20000; $i++) { $x = $i * 2; }
$w('حلقهٔ ۲۰هزار (CPU)', $sec($tt));
$tt = microtime(true);
$c = @file_get_contents(__DIR__ . '/index.html');
$w('خواندن index.html از دیسک', $sec($tt) . ' (' . strlen((string)$c) . ' بایت)');

/* ── ۴) DB ── */
echo "\n── دیتابیس ──\n";
try {
    $tt = microtime(true);
    require_once __DIR__ . '/config.php';
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $w('اتصال PDO', $sec($tt));
    $tt = microtime(true); $pdo->query('SELECT 1'); $w('SELECT 1', $sec($tt));

    foreach (['agencies','properties','demands','members','personal_notes','rate_limits','sys_config'] as $tbl) {
        try {
            $tt = microtime(true);
            $n = (int)$pdo->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
            $w("COUNT($tbl)", number_format($n) . ' ردیف — ' . $sec($tt));
        } catch (Throwable $e) { $w("COUNT($tbl)", 'خطا: ' . $e->getMessage()); }
    }

    try {
        $sv = $pdo->query("SELECT conf_val FROM sys_config WHERE conf_key='schema_version'")->fetchColumn();
        $w('schema_version', $sv !== false ? $sv : '(ثبت نشده)');
    } catch (Throwable $e) { $w('schema_version', 'خطا'); }

    // قفل/کوئری‌های طولانی (اگر مجوز SHOW PROCESSLIST باشد)
    try {
        $proc = $pdo->query("SHOW PROCESSLIST")->fetchAll();
        $slow = array_filter($proc, fn($r) => (int)($r['Time'] ?? 0) >= 2);
        if ($slow) {
            echo "\n── کوئری‌های فعال > ۲ ثانیه ──\n";
            foreach ($slow as $r) {
                printf("  [%s] %ss | %s | %s\n", $r['Id'] ?? '?', $r['Time'] ?? '?', $r['State'] ?? '', substr((string)($r['Info'] ?? ''), 0, 120));
            }
        } else {
            $w('کوئری طولانی', 'ندارد ✓');
        }
    } catch (Throwable $e) { $w('SHOW PROCESSLIST', 'ممنوع: ' . $e->getMessage()); }
} catch (Throwable $e) {
    echo "❌ اتصال DB ناموفق: " . $e->getMessage() . "\n";
}

/* ── ۵) تست HTTP محلی (پنل واقعاً پاسخ می‌دهد؟) ── */
echo "\n── تست HTTP محلی (دقیقاً همان پنل) ──\n";
if (function_exists('curl_init')) {
    $host = $_SERVER['HTTP_HOST'] ?? 'test.amlak-e-man.ir';
    foreach (['super_admin_license_x_382967.php', 'amlak_creator_b_1687.php', 'api.php', '/'] as $path) {
        $url = 'https://' . $host . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Host: ' . $host],
        ]);
        $tt = microtime(true);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $ms = $sec($tt);
        $size = strlen((string)$body);
        $head = preg_replace('/\s+/u', ' ', trim(substr(strip_tags((string)$body), 0, 80)));
        $w($path, "HTTP $code | $ms | {$size}B | curl=" . ($err ?: 'بدون') . ($code === 200 ? " | head: " . mb_substr($head, 0, 60) : ''));
    }
} else {
    $w('curl', 'نصب نیست');
}

/* ── ۶) error_log اخیر (خطاهای واقعی PHP) ── */
echo "\n── error_log (آخرین ۴۰ خط) ──\n";
$log = ini_get('error_log');
$found = false;
foreach ([$log, __DIR__ . '/error_log', dirname(__DIR__) . '/error_log', '/var/log/apache2/error.log', '/var/log/httpd/error_log'] as $p) {
    if ($p && is_file($p) && is_readable($p)) {
        $lines = @file($p);
        if ($lines) {
            $tail = implode('', array_slice($lines, -40));
            echo $tail . "\n";
            $found = true;
            break;
        }
    }
}
if (!$found) echo "(قابل خواندن نیست — در File Manager دنبال error_log بگرد)\n";

echo "\n────────────────────────────────────────────\n";
echo "✅ پایان. کل این خروجی را برایم بفرست.\n";
