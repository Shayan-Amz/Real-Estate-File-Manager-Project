<?php
/**
 * 🩹 بررسی سلامت config.php — بدون چاپ هیچ رازی
 * ===============================================
 * گزارش می‌دهد:
 *   - آیا config.php هست؟ (حجم + زمان تغییر)
 *   - آیا بدون خطا require می‌شود؟
 *   - آیا اتصال دیتابیس برقرار است؟
 *   - چند ثابت تعریف شده؟
 *
 * 🔑 اجرا:  https://دامنه/zz_cfg.php?key=zzcfg-9f3c71ab60d54e8f
 */

const ZZCFG_KEY = 'zzcfg-9f3c71ab60d54e8f';

if (($_GET['key'] ?? '') !== ZZCFG_KEY) {
    http_response_code(403);
    exit("403 — کلید لازم است.\n");
}

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '0');

function zz_line($k, $v) { echo str_pad($k, 26) . ' : ' . $v . "\n"; }

$cfgPath = __DIR__ . '/config.php';
echo "════════════════════════════════════\n";
echo " 🩹 بررسی config.php — " . date('Y-m-d H:i:s') . "\n";
echo "════════════════════════════════════\n";

if (!is_file($cfgPath)) {
    zz_line('config.php', '❌ وجود ندارد');
    exit;
}
zz_line('config.php', sprintf('✅ موجود | %s بایت | mtime=%s', number_format(filesize($cfgPath)), date('m-d H:i', filemtime($cfgPath))));

/* آیا PHP می‌تواند فایل را بدون fatal اجرا کند؟ */
try {
    require_once $cfgPath;
    zz_line('require config.php', '✅ بدون خطا');
} catch (Throwable $e) {
    zz_line('require config.php', '❌ ' . $e->getMessage());
    exit;
}

$constants = ['DB_HOST','DB_NAME','DB_USER','DB_PASS','APP_SALT','MASTER_PASSWORD_HASH','ALLOWED_ORIGIN','STT_PROVIDER','STT_HF_TOKEN','OPENROUTER_API_KEY'];
$ok = 0; $missing = [];
foreach ($constants as $c) {
    if (defined($c)) $ok++; else $missing[] = $c;
}
zz_line('ثابت‌های تعریف‌شده', "$ok / " . count($constants) . ($missing ? ' — MISSING: ' . implode(', ', $missing) : ''));

/* اتصال واقعی دیتابیس */
try {
    $t = microtime(true);
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $pdo->query("SELECT 1");
    zz_line('اتصال دیتابیس', '✅ ' . round((microtime(true) - $t) * 1000) . ' ms');
} catch (Throwable $e) {
    zz_line('اتصال دیتابیس', '❌ ' . $e->getMessage());
}

echo "\n✅ پایان.\n";
