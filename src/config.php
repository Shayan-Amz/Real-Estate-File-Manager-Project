<?php
// 🔴 این فایل نباید در دسترس عموم باشد
define('DB_HOST', 'localhost');             
define('DB_NAME', 'owihpfoa_test_db'); 
define('DB_USER', 'owihpfoa_test_db'); 
define('DB_PASS', 'JY8FLFa48fQmVFbnprAP');  

// رمز عبور مدیریت کل که هش (Hash) شده است (این هش معادل کلمه 'MyStrongPassword#2026' است)
// برای تغییر آن در آینده باید هش جدید را اینجا بگذارید
//define('MASTER_PASSWORD_HASH', '$2y$10$WqB.r8c9H0M.Q7.mK8sT2eZ7yX4uR9Y8W/3wA9/xT8eH9mF.wG3rK');
define('MASTER_PASSWORD_HASH', password_hash('ShayanRealState', PASSWORD_DEFAULT));

define('APP_SALT', 'Change_This_To_Any_Random_Text_Like_XyZ123!@#');

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
define('STT_HF_TOKEN', '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');
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
define('OPENROUTER_API_KEY', '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');
define('OPENROUTER_URL', 'https://ai.shayan-api.ir/api/v1/chat/completions');
define('OPENROUTER_MODEL', 'laguna-xs-2.1:free');
?>