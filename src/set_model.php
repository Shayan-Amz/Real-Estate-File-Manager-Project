<?php
/**
 * 🎛️ ابزار موقت: تغییر مدل جارویس (OpenRouter) در config.php
 * ============================================================
 * چرا؟  طبق تست stt_test.php، کلید OpenRouter معتبر است ولی مدل فعلی
 * («laguna-xs-2.1:free») احتمالاً منسوخ شده و 403 «Access denied by
 * security policy» می‌دهد. این ابزار فقط خط OPENROUTER_MODEL را عوض
 * می‌کند — هیچ‌چیز دیگری (DB_PASS، توکن‌ها، APP_SALT و...) تغییر نمی‌کند.
 *
 * 🔑 اجرا:
 *   https://دامنه/set_model.php?key=setmodel-9f3c71ab60d54e8f&model=google/gemma-4-26b-a4b-it:free
 *
 * اگر پارامتر model ندهی، مدل پیش‌فرض زیر گذاشته می‌شود:
 *   google/gemma-4-26b-a4b-it:free
 *
 * ⚠️ بعد از تست موفق، این فایل را از public_html حذف کن.
 */

const SETMODEL_KEY = 'setmodel-9f3c71ab60d54e8f';
const DEFAULT_MODEL = 'google/gemma-4-26b-a4b-it:free';

if (($_GET['key'] ?? '') !== SETMODEL_KEY) {
    http_response_code(403);
    exit("403 — کلید لازم است.\n");
}

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '0');

$cfgPath = __DIR__ . '/config.php';
if (!is_file($cfgPath)) {
    exit("❌ config.php در public_html پیدا نشد.\n");
}

/* فقط کاراکترهای امن: حروف، عدد، نقطه، خط تیره، اسلش، دونقطه */
$model = (string)($_GET['model'] ?? '');
if ($model === '') $model = DEFAULT_MODEL;
if (!preg_match('#^[A-Za-z0-9_./:\-]{1,100}$#', $model)) {
    exit("❌ نام مدل نامعتبر است.\n");
}
// خیر، عمداً URL دیگری را همین‌جا عوض نمی‌کنیم؛ فقط مدل.
if (strpos($model, 'https://') === 0) {
    exit("❌ مدل نباید URL باشد.\n");
}

$cfg = file_get_contents($cfgPath);

/* پشتیبان یک‌باره (برای بازگشت آسان) */
if (!is_file($cfgPath . '.bak_model')) {
    @copy($cfgPath, $cfgPath . '.bak_model');
}

$oldModel = '(در این فایل تعریف نشده بود)';
$pattern = "/define\\s*\\(\\s*'OPENROUTER_MODEL'\\s*,\\s*'[^']*'\\s*\\)/";
if (preg_match($pattern, $cfg, $m)) {
    if (preg_match("/define\\s*\\(\\s*'OPENROUTER_MODEL'\\s*,\\s*'([^']*)'\\s*\\)/", $m[0], $mm)) {
        $oldModel = $mm[1];
    }
    $cfg = preg_replace($pattern, "define('OPENROUTER_MODEL', '$model')", $cfg, 1);
} else {
    // اگر خط وجود نداشت، قبل از خط END/آخر اضافه می‌کنیم
    $cfg = rtrim($cfg) . "\n" . "define('OPENROUTER_MODEL', '$model');\n";
}

if (file_put_contents($cfgPath, $cfg) === false) {
    exit("❌ نوشتن config.php ناموفق بود (دسترسی را چک کن).\n");
}

echo "════════════════════════════════════════\n";
echo " ✅ مدل جارویس عوض شد\n";
echo "════════════════════════════════════════\n";
echo 'مدل قبلی     : ' . $oldModel . "\n";
echo 'مدل جدید     : ' . $model . "\n";
echo 'فایل         : ' . $cfgPath . "\n";
echo "\n▶ حالا دوباره تست بزن:\n";
echo "  https://" . ($_SERVER['HTTP_HOST'] ?? 'دامنه') . "/stt_test.php?key=stt-test-9f3c71ab60d54e8f\n";
echo "\n⚠️ اگر باز هم 403 دیدی، یعنی مشکل از URL است نه مدل — بگو تا OPENROUTER_URL را هم به openrouter.ai رسمی تغییر بدهم.\n";
echo "⚠️ بعد از اتمام، set_model.php را از هاست حذف کن.\n";
