<?php
require_once 'config.php';

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // اضافه کردن ستون نمایش به عموم
    $pdo->exec("ALTER TABLE properties ADD COLUMN IF NOT EXISTS showToGuest TINYINT(1) DEFAULT 1 AFTER showPriceGuest");
    
    // اضافه کردن ستون نمایش عکس به عموم
    $pdo->exec("ALTER TABLE properties ADD COLUMN IF NOT EXISTS showPhotosGuest TINYINT(1) DEFAULT 1 AFTER showToGuest");

    echo "دیتابیس با موفقیت آپدیت شد! حالا می‌توانید این فایل را حذف کنید.";
} catch(PDOException $e) {
    die("خطا در آپدیت دیتابیس: " . $e->getMessage());
}
?>