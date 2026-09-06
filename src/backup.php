<?php
/**
 * 💾 بک‌آپ‌گیری خودکار (کرون‌جاب)
 * ------------------------------------------------------------------
 * ⚠️ رفع باگ مهلک: این فایل از $pdo استفاده می‌کرد ولی هرگز آن را نمی‌ساخت
 *    (config.php هم $pdo نمی‌سازد) → همیشه با خطای
 *    "Call to a member function query() on null" می‌مرد و هیچ بک‌آپی
 *    گرفته نمی‌شد. اتصال PDO اکنون صریحاً ساخته می‌شود.
 *
 * اجرای دستی:  php backup.php
 * کرون‌جاب:     0 3 * * * /usr/bin/php /path/to/public_html/backup.php
 */

// ⚡ قفل هوشمند: اگر درخواست از طرف مرورگر (کاربر) باشد مسدود می‌شود، اما کرون‌جابِ سرور اجازه عبور دارد
if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REQUEST_URI'])) {
    http_response_code(403);
    die("❌ دسترسی غیرمجاز! این فایل فقط توسط سیستم خودکار سرور قابل اجراست.");
}

require_once __DIR__ . '/config.php';

// ⚡ قفل همپوشانی: اگر کرون‌جاب کوتاه‌تر از خودِ بک‌آپ اجرا شود (مثلاً هر دقیقه)،
//    اجرای دوم فوراً رد می‌شود تا دیتابیس/دیسک قفل نشود و سرور کند نشود.
$lockPath = sys_get_temp_dir() . '/amlak_backup.lock';
$lockFile = fopen($lockPath, 'c');
if ($lockFile === false || !flock($lockFile, LOCK_EX | LOCK_NB)) {
    exit("⏭️ یک بک‌آپ دیگر در حال اجراست؛ این اجرا رد شد.\n");
}
register_shutdown_function(function () use ($lockFile) { @flock($lockFile, LOCK_UN); @fclose($lockFile); });

$backupDir = __DIR__ . '/backups/';
if (!is_dir($backupDir)) {
    if (!@mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
        error_log('[backup] ساخت پوشهٔ بک‌آپ ناموفق بود: ' . $backupDir);
        exit(1);
    }
}

$fileName = 'backup_' . date('Y-m-d_H-i') . '.json';
$filePath = $backupDir . $fileName;

try {
    // ⚡ اتصال واقعی به دیتابیس (قبلاً وجود نداشت)
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // ⚡ همهٔ جداولی که برای بازیابی لازم‌اند — قبلاً فقط properties و demands
    //    گرفته می‌شد و بدون agencies/members بازیابی ممکن نبود.
    $tables = ['agencies', 'properties', 'demands', 'members', 'personal_notes'];
    $dump   = ['date' => date('Y-m-d H:i:s'), 'tables' => []];
    $counts = [];

    foreach ($tables as $table) {
        // جدول‌های اختیاری ممکن است هنوز ساخته نشده باشند
        $check = $pdo->prepare("SHOW TABLES LIKE ?");
        $check->execute([$table]);
        if ($check->fetchColumn() === false) {
            $counts[$table] = 'skipped';
            continue;
        }
        $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll();
        $dump['tables'][$table] = $rows;
        $counts[$table] = count($rows);
    }

    $backupData = json_encode($dump, JSON_UNESCAPED_UNICODE);
    if ($backupData === false) {
        throw new Exception('json_encode ناموفق بود: ' . json_last_error_msg());
    }

    // ⚡ نوشتن اتمیک: اول در فایل موقت، بعد rename — تا بک‌آپ نیمه‌نوشته نماند
    $tmpPath = $filePath . '.tmp';
    if (file_put_contents($tmpPath, $backupData) === false) {
        throw new Exception('نوشتن فایل بک‌آپ ناموفق بود (احتمالاً پر بودن دیسک).');
    }
    if (!rename($tmpPath, $filePath)) {
        @unlink($tmpPath);
        throw new Exception('انتقال فایل بک‌آپ به مسیر نهایی ناموفق بود.');
    }
    @chmod($filePath, 0640);

    // پاک کردن بک‌آپ‌های قدیمی‌تر از ۷ روز (برای پر نشدن هاست)
    $now = time();
    foreach (glob($backupDir . 'backup_*.json') ?: [] as $file) {
        if (is_file($file) && ($now - filemtime($file) >= 7 * 24 * 60 * 60)) {
            @unlink($file);
        }
    }

    $summary = [];
    foreach ($counts as $t => $c) $summary[] = "{$t}={$c}";
    echo "✅ بک‌آپ گرفته شد: {$fileName} (" . round(strlen($backupData) / 1024) . " KB) — " . implode('، ', $summary) . "\n";
    exit(0);

} catch (Throwable $e) {
    // ⚡ هم در لاگ سرور ثبت می‌شود هم روی خروجی، تا خرابی کرون‌جاب بی‌صدا نماند
    error_log('[backup] خطا: ' . $e->getMessage());
    echo "❌ خطا در بک‌آپ‌گیری: " . $e->getMessage() . "\n";
    exit(1);
}
