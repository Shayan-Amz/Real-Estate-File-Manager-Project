<?php
// ⚡ قفل هوشمند: اگر درخواست از طرف مرورگر (کاربر) باشد مسدود می‌شود، اما کرون‌جابِ سرور اجازه عبور دارد
if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REQUEST_URI'])) {
    http_response_code(403);
    die("❌ دسترسی غیرمجاز! این فایل فقط توسط سیستم خودکار سرور قابل اجراست.");
}

require_once 'config.php';

$backupDir = __DIR__ . '/backups/';
if (!is_dir($backupDir)) mkdir($backupDir, 0777, true);

// ساخت نام فایل با تاریخ امروز
$fileName = 'backup_' . date('Y-m-d_H-i') . '.json';
$filePath = $backupDir . $fileName;

try {
    // گرفتن خروجی از فایل‌ها و مشتریان
    $stmtProp = $pdo->query("SELECT * FROM properties");
    $properties = $stmtProp->fetchAll(PDO::FETCH_ASSOC);
    
    $stmtDem = $pdo->query("SELECT * FROM demands");
    $demands = $stmtDem->fetchAll(PDO::FETCH_ASSOC);

    $backupData = json_encode([
        'date' => date('Y-m-d H:i:s'),
        'properties' => $properties,
        'demands' => $demands
    ], JSON_UNESCAPED_UNICODE);

    file_put_contents($filePath, $backupData);
    
    // پاک کردن بک‌آپ‌های قدیمی‌تر از ۷ روز (برای پر نشدن هاست)
    $files = glob($backupDir . '*.json');
    $now = time();
    foreach ($files as $file) {
        if (is_file($file) && ($now - filemtime($file) >= 7 * 24 * 60 * 60)) {
            unlink($file);
        }
    }
    
    echo "بک‌آپ با موفقیت گرفته شد!";
} catch (Exception $e) {
    echo "خطا در بک‌آپ گیری: " . $e->getMessage();
}
?>