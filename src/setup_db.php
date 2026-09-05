<?php
/**
 * ⚙️ ساخت/به‌روزرسانی ساختار دیتابیس — فقط از خط فرمان.
 *
 * اجرا:  php setup_db.php
 *
 * 🛡️ قبلاً این فایل از مرورگر هم قابل اجرا بود و خطای خام دیتابیس را
 *    چاپ می‌کرد. ضمناً از «ADD COLUMN IF NOT EXISTS» استفاده می‌کرد که
 *    روی MySQL وجود ندارد (فقط MariaDB) و همیشه خطا می‌داد.
 *
 * نکته: api.php هم در هر اجرا migration خودش را انجام می‌دهد، پس این فایل
 *       فقط برای راه‌اندازی اولیهٔ یک دیتابیس خالی است.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('این فایل فقط از خط فرمان قابل اجرا است.'); }

require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (PDOException $e) {
    error_log('[setup_db] اتصال ناموفق: ' . $e->getMessage());
    fwrite(STDERR, "❌ اتصال به دیتابیس ناموفق بود. error_log را ببینید.\n");
    exit(1);
}

$ddl = [
    'rate_limits' => "CREATE TABLE IF NOT EXISTS rate_limits (
        id INT(11) NOT NULL AUTO_INCREMENT,
        ip VARCHAR(64) NOT NULL,
        request_time INT(11) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_ip (ip),
        KEY idx_time (request_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'personal_notes' => "CREATE TABLE IF NOT EXISTS personal_notes (
        id INT(11) NOT NULL AUTO_INCREMENT,
        agencyId VARCHAR(50) NOT NULL,
        username VARCHAR(191) NOT NULL,
        note_text MEDIUMTEXT,
        updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_agency_user (agencyId, username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

$failed = 0;
foreach ($ddl as $name => $sql) {
    try { $pdo->exec($sql); echo "✅ جدول $name آماده است.\n"; }
    catch (PDOException $e) { $failed++; error_log("[setup_db] $name: " . $e->getMessage()); echo "❌ جدول $name ناموفق بود.\n"; }
}

// ستون‌های نمایش به عموم — با بررسی وجود ستون، نه با نحوِ نامعتبر
if ($pdo->query("SHOW TABLES LIKE 'properties'")->rowCount() > 0) {
    $existing = [];
    foreach ($pdo->query("SHOW COLUMNS FROM properties") as $c) { $existing[$c['Field']] = true; }
    $wanted = [
        'showToGuest'      => "ALTER TABLE properties ADD COLUMN showToGuest TINYINT(1) DEFAULT 0",
        'showImagesGuest'  => "ALTER TABLE properties ADD COLUMN showImagesGuest TINYINT(1) DEFAULT 0",
        'showPriceGuest'   => "ALTER TABLE properties ADD COLUMN showPriceGuest TINYINT(1) DEFAULT 0",
    ];
    foreach ($wanted as $col => $sql) {
        if (isset($existing[$col])) { echo "ℹ️  ستون $col از قبل هست.\n"; continue; }
        try { $pdo->exec($sql); echo "✅ ستون $col اضافه شد.\n"; }
        catch (PDOException $e) { $failed++; error_log("[setup_db] $col: " . $e->getMessage()); echo "❌ ستون $col ناموفق بود.\n"; }
    }
} else {
    echo "⚠️ جدول properties وجود ندارد؛ فقط جدول‌های جانبی ساخته شدند.\n";
}

exit($failed > 0 ? 1 : 0);
