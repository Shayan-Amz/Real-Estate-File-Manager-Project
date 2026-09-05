<?php
// 🔴 این فایل نباید در دسترس عموم باشد
define('DB_HOST', 'localhost');             
define('DB_NAME', 'DATABASE_NAME_HERE'); 
define('DB_USER', 'DATABASE_NAME_HERE'); 
define('DB_PASS', 'DATABASE_PASSWORD_HERE');  

// رمز عبور مدیریت کل — به‌صورت هشِ از پیش محاسبه‌شده ذخیره می‌شود.
// ⚠️ قبلاً اینجا password_hash('ShayanRealState', ...) بود که دو مشکل داشت:
//    ۱) رمز به‌صورت plaintext داخل سورس و داخل گیت بود
//    ۲) چون config.php در هر درخواست include می‌شود، در هر درخواست یک
//       bcrypt کامل اجرا می‌شد (≈۵۰ تا ۱۰۰ میلی‌ثانیه اتلاف در هر call)
// برای تغییر رمز، هش جدید را با این دستور بساز و همین‌جا بگذار:
//    php -r "echo password_hash('رمز_جدید', PASSWORD_DEFAULT), PHP_EOL;"
define('MASTER_PASSWORD_HASH', '$2y$10$REPLACE_WITH_output_of_password_hash');

// ⚡ کلید امضای توکن‌ها — چرخش یافت (قبلاً روی مقدار نمونهٔ README بود).
// ⚠️ با تغییر این مقدار همهٔ نشست‌های فعال باطل می‌شوند و کاربران باید
//    دوباره وارد شوند. این انتظار درست است.
define('APP_SALT', 'REPLACE_WITH_64_random_hex_chars');

// دامنه سایت خود را اینجا وارد کنید (مثلا https://amlak-e-man.ir) برای تنظیم CORS
define('ALLOWED_ORIGIN', '*'); 

// ======================================================================
// 🎙️ تنظیمات تبدیل گفتار به متن (STT) — موتور جارویس
// ----------------------------------------------------------------------
// STT_PROVIDER یکی از اینهاست:
//   'hf'     → Hugging Face Router (پیش‌فرض؛ فقط STT_HF_TOKEN لازم است)
//   'groq'   → Groq (سریع و رایگان؛ STT_GROQ_KEY لازم است) — پیشنهادی برای فارسی
//   'openai' → OpenAI Whisper (کیفیت بالا؛ STT_OPENAI_KEY لازم است)
//
// ⚠️ برای فهمیدن اینکه کدام ارائه‌دهنده روی هاست شما واقعاً کار می‌کند،
//    فایل tools/stt_probe.php را یک‌بار اجرا کنید.
// ======================================================================
define('STT_PROVIDER', 'hf');

// توکن Hugging Face — باید دسترسی «Make calls to Inference Providers» داشته باشد
define('STT_HF_TOKEN', 'hf_REPLACE_ME');
define('STT_MODEL', 'openai/whisper-large-v3');

// در صورت انتخاب 'groq' یا 'openai' اینها را پر کنید
define('STT_GROQ_KEY', '');
define('STT_MODEL_GROQ', 'whisper-large-v3-turbo');
define('STT_OPENAI_KEY', '');
define('STT_MODEL_OPENAI', 'whisper-1');

define('STT_LANGUAGE', 'fa');                 // زبان گفتار (ISO-639-1)
define('STT_MAX_BYTES', 8 * 1024 * 1024);     // سقف حجم فایل صوتی
define('STT_TIMEOUT', 45);                    // سقف زمان کل درخواست (ثانیه)
define('STT_CONNECT_TIMEOUT', 10);            // سقف زمان اتصال (ثانیه)

// ======================================================================
// 🧠 مغز جارویس (OpenRouter)
// ======================================================================
define('OPENROUTER_API_KEY', 'sk-or-v1-REPLACE_ME');
define('OPENROUTER_URL', 'https://ai.shayan-api.ir/api/v1/chat/completions');
define('OPENROUTER_MODEL', 'laguna-xs-2.1:free');
?>