<?php
// 🔴 این فایل نباید در دسترس عموم باشد
define('DB_HOST', 'localhost');             
define('DB_NAME', 'owihpfoa_Amlak-E-Man_Database'); 
define('DB_USER', 'owihpfoa_Amlak-E-Man_Database'); 
define('DB_PASS', 'Mx3eu4rR7ydyVyynfnGM');  

// رمز عبور مدیریت کل که هش (Hash) شده است (این هش معادل کلمه 'MyStrongPassword#2026' است)
// برای تغییر آن در آینده باید هش جدید را اینجا بگذارید
//define('MASTER_PASSWORD_HASH', '$2y$10$WqB.r8c9H0M.Q7.mK8sT2eZ7yX4uR9Y8W/3wA9/xT8eH9mF.wG3rK');
define('MASTER_PASSWORD_HASH', password_hash('ShayanRealState', PASSWORD_DEFAULT));

define('APP_SALT', 'Change_This_To_Any_Random_Text_Like_XyZ123!@#');

// دامنه سایت خود را اینجا وارد کنید (مثلا https://amlak-e-man.ir) برای تنظیم CORS
define('ALLOWED_ORIGIN', '*'); 
?>