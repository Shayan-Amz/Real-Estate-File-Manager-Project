<?php
/**
 * 🔑 ابزار موقت: گذاشتن کلیدهای فعلی/همان قبلی در config.php
 * ============================================================
 * چرا؟  برای استفاده از توکن‌ها/کلیدهایی که همین حالا داری، نه ساخت کلید جدید.
 * این صفحه فقط دو مقدار را در config.php جایگزین می‌کند:
 *   STT_HF_TOKEN        (Hugging Face)
 *   OPENROUTER_API_KEY  (مغز متنی جارویس)
 *
 * 🔒 فقط با کلید زیر باز می‌شود؛ مقادیر با فرم POST ارسال می‌شوند تا در
 *    لاگ سرور/تاریخچه نیفتند. هیچ مقدار واقعی‌ای چاپ نمی‌شود (فقط ماسک).
 *
 * آدرس:
 *   https://دامنه/set_keys.php?key=setkeys-9f3c71ab60d54e8f
 *
 * ⚠️ بعد از تست موفق، این فایل را از public_html پاک کن.
 */

const SETKEYS_KEY = 'setkeys-9f3c71ab60d54e8f';

header('Content-Type: text/html; charset=utf-8');

$key = (string)($_POST['k'] ?? $_GET['key'] ?? '');
if (!hash_equals(SETKEYS_KEY, $key)) {
    http_response_code(403);
    exit("<h3 style='font-family:Tahoma; color:#b91c1c;'>403 — کلید لازم است.</h3>");
}

$cfgPath = __DIR__ . '/config.php';
if (!is_file($cfgPath)) {
    exit("<h3 style='font-family:Tahoma; color:#b91c1c;'>❌ config.php پیدا نشد.</h3>");
}

$mask = function ($s) {
    $s = (string)$s;
    if ($s === '') return '(خالی)';
    return substr($s, 0, 6) . '…' . substr($s, -3) . ' [' . strlen($s) . ' کاراکتر]';
};

/* ── مشاهدهٔ وضعیت فعلی (ماسک‌شده) ── */
if (($_GET['view'] ?? '') === '1' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    require_once $cfgPath;
    echo "<pre style='font-family:Tahoma; direction:ltr;'>"
       . "STT_HF_TOKEN     : " . $mask(defined('STT_HF_TOKEN') ? STT_HF_TOKEN : '') . "\n"
       . "OPENROUTER_API_KEY: " . $mask(defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '') . "\n"
       . "</pre>";
    exit;
}

/* ── ذخیره (POST) ── */
$done = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $hf = trim((string)($_POST['hf'] ?? ''));
    $or = trim((string)($_POST['or'] ?? ''));
    $cfg = file_get_contents($cfgPath);

    // پشتیبان اولیه (یک‌بار)
    if (!is_file($cfgPath . '.bak')) { @copy($cfgPath, $cfgPath . '.bak'); }

    $set = function ($name, $value) use (&$cfg) {
        $safe = str_replace(["\\", "'"], ["\\\\", "\\'"], (string)$value);
        $newLine = "define('{$name}', '{$safe}');";
        $pattern = "/define\\('" . preg_quote($name, '/') . "'\\s*,\\s*'[^']*'\\)/";
        if (preg_match($pattern, $cfg)) {
            $cfg = preg_replace($pattern, $newLine, $cfg, 1);
        } else {
            $cfg = rtrim($cfg) . "\n" . $newLine . "\n";
        }
    };

    if ($hf !== '') $set('STT_HF_TOKEN', $hf);
    if ($or !== '') $set('OPENROUTER_API_KEY', $or);
    if ($hf === '' && $or === '') {
        $done = "⚠️ هیچ مقداری وارد نشد.";
    } else {
        file_put_contents($cfgPath, $cfg);
        $done = "✅ ذخیره شد. حالا تست بزن.";
    }
}

/* ── فرم ── */
echo "<!DOCTYPE html><html lang='fa' dir='rtl'><head><meta charset='utf-8'><title>گذاشتن کلیدها</title></head>"
   . "<body style='font-family:Tahoma; background:#f8fafc; display:flex; justify-content:center; align-items:center; min-height:100vh;'>"
   . "<form method='POST' style='background:#fff; padding:28px; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,.1); width:420px;'>"
   . "<h2 style='color:#1e40af; margin-top:0;'>🔑 گذاشتن کلیدهای فعلی</h2>"
   . "<p style='color:#334155; font-size:13px;'>کلید جدید نساز — همان کلیدهایی را که داری اینجا بگذار.</p>"
   . "<input type='hidden' name='k' value='" . htmlspecialchars(SETKEYS_KEY, ENT_QUOTES) . "'>"
   . "<label style='display:block; font-weight:bold; margin:10px 0 4px;'>توکن Hugging Face (hf_…)</label>"
   . "<input type='password' name='hf' style='width:100%; padding:9px; border:1px solid #cbd5e1; border-radius:6px; direction:ltr;' placeholder='hf_…'>"
   . "<label style='display:block; font-weight:bold; margin:14px 0 4px;'>کلید OpenRouter (sk-or-v1-…)</label>"
   . "<input type='password' name='or' style='width:100%; padding:9px; border:1px solid #cbd5e1; border-radius:6px; direction:ltr;' placeholder='sk-or-v1-…'>"
   . "<button type='submit' style='margin-top:18px; width:100%; background:#2563eb; color:#fff; border:none; padding:11px; border-radius:8px; font-weight:bold; cursor:pointer;'>💾 ذخیره</button>"
   . ($done !== '' ? "<p style='margin-top:12px; color:#15803d; font-weight:bold;'>" . htmlspecialchars($done) . "</p>" : "")
   . "<hr style='margin:18px 0;'>"
   . "<p style='font-size:12px; color:#475569;'>بعد از ذخیره، این را باز کن:<br>"
   . "<code style='direction:ltr; display:block; word-break:break-all;'>https://دامنه/stt_test.php?key=stt-test-9f3c71ab60d54e8f</code></p>"
   . "</form></body></html>";
