<?php
/**
 * 🎛️ ابزار موقت: اصلاح کامل مغز متنی جارویس (مدل + URL) + تست خودکار
 * ====================================================================
 * چرا؟ تست قبلی نشان داد:
 *   - کلید OpenRouter معتبر است (طبق گفتهٔ کاربر)
 *   - تغییر مدل به google/gemma-4-26b-a4b-it:free هم جواب نداد
 *   - URL فعلی «https://ai.shayan-api.ir/...» یک پروکسی شخصی است و
 *     کلید OpenRouter را قبول نمی‌کند → 403 «Access denied by security policy»
 *
 * این ابزار:
 *   ۱) وضعیت فعلی (مدل/URL/کلید ماسک‌شده) را نشان می‌دهد
 *   ۲) با do=set، مدل و URL را به مقادیر درست OpenRouter تغییر می‌دهد
 *      (فقط این دو خط؛ بقیهٔ config.php دست نمی‌خورد + بک‌آپ می‌گیرد)
 *   ۳) بلافاصله خودش به OpenRouter رسمی درخواست реальی می‌زند و نتیجه را نشان می‌دهد
 *
 * 🔑 اجرا:
 *   https://دامنه/set_jarvis.php?key=jarvis-set-9f3c71ab60d54e8f&do=status
 *   https://دامنه/set_jarvis.php?key=jarvis-set-9f3c71ab60d54e8f&do=set
 *   https://دامنه/set_jarvis.php?key=jarvis-set-9f3c71ab60d54e8f&do=test
 *
 * ⚠️ بعد از موفقیت، این فایل را از public_html حذف کن.
 */

const JARVIS_KEY  = 'jarvis-set-9f3c71ab60d54e8f';
const NEW_MODEL   = 'google/gemma-4-26b-a4b-it:free';
const NEW_URL     = 'https://openrouter.ai/api/v1/chat/completions';

if (($_GET['key'] ?? '') !== JARVIS_KEY) {
    http_response_code(403);
    exit("403 — کلید لازم است.\n");
}

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '0');

$cfgPath = __DIR__ . '/config.php';
if (!is_file($cfgPath)) {
    exit("❌ config.php در public_html پیدا نشد.\n");
}

function cfg_val($cfg, $name) {
    if (preg_match("/define\\s*\\(\\s*'" . preg_quote($name, '/') . "'\\s*,\\s*'([^']*)'\\s*\\)/", $cfg, $m)) return $m[1];
    return null;
}
function cfg_set(&$cfg, $name, $value) {
    $value = str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
    $pat = "/define\\s*\\(\\s*'" . preg_quote($name, '/') . "'\\s*,\\s*'[^']*'\\s*\\)/";
    if (preg_match($pat, $cfg)) {
        $cfg = preg_replace($pat, "define('$name', '$value')", $cfg, 1);
        return true;
    }
    $cfg = rtrim($cfg) . "\n" . "define('$name', '$value');\n";
    return true;
}
function mask_key($s) {
    $s = (string)$s;
    if ($s === '') return '(خالی)';
    if (strlen($s) <= 12) return '(خیلی کوتاه — چک کن)';
    return substr($s, 0, 6) . '…' . substr($s, -3) . ' [' . strlen($s) . ' کاراکتر]';
}
function hr($t) { echo "\n── $t ──\n"; }

$do = (string)($_GET['do'] ?? 'status');
$cfg = file_get_contents($cfgPath);

/* ۱) وضعیت */
hr('وضعیت فعلی config.php');
echo 'OPENROUTER_MODEL : ' . (cfg_val($cfg, 'OPENROUTER_MODEL') ?? '(تعریف نشده)') . "\n";
echo 'OPENROUTER_URL   : ' . (cfg_val($cfg, 'OPENROUTER_URL')   ?? '(تعریف نشده)') . "\n";
echo 'OPENROUTER_API_KEY: ' . mask_key(cfg_val($cfg, 'OPENROUTER_API_KEY') ?? '') . "\n";
echo 'STT_PROVIDER     : ' . (cfg_val($cfg, 'STT_PROVIDER') ?? '(تعریف نشده)') . "\n";

/* ۲) تغییر */
if ($do === 'set' || $do === 'fix') {
    if (!is_file($cfgPath . '.bak_jarvis')) { @copy($cfgPath, $cfgPath . '.bak_jarvis'); }
    cfg_set($cfg, 'OPENROUTER_MODEL', NEW_MODEL);
    cfg_set($cfg, 'OPENROUTER_URL', NEW_URL);
    if (file_put_contents($cfgPath, $cfg) === false) {
        exit("❌ نوشتن config.php ناموفق بود.\n");
    }
    hr('تغییرات اعمال شد');
    echo 'OPENROUTER_MODEL : ' . NEW_MODEL . "\n";
    echo 'OPENROUTER_URL   : ' . NEW_URL . "\n";
    echo "بک‌آپ قبلی        : config.php.bak_jarvis\n";
}

/* ۳) تست خودکار (اگر کلید داریم) */
if ($do === 'set' || $do === 'fix' || $do === 'test') {
    $model = cfg_val($cfg, 'OPENROUTER_MODEL') ?? NEW_MODEL;
    $url   = cfg_val($cfg, 'OPENROUTER_URL')   ?? NEW_URL;
    $key   = cfg_val($cfg, 'OPENROUTER_API_KEY') ?? '';
    hr('تست واقعی به OpenRouter رسمی');
    if ($key === '') { echo "⚠️ کلید خالی است — تست ممکن نیست.\n"; exit; }

    $payload = json_encode([
        'model'      => $model,
        'messages'   => [['role' => 'user', 'content' => 'فقط بگو: سلام']],
        'max_tokens' => 8,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
    ]);
    $t0 = microtime(true);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $ms = round((microtime(true) - $t0) * 1000);
    echo 'URL     : ' . $url . "\n";
    echo 'MODEL   : ' . $model . "\n";
    echo 'HTTP    : ' . $code . " ({$ms}ms)\n";
    if ($err !== '') echo 'curl    : ' . $err . "\n";
    echo 'پاسخ    : ' . str_replace("\n", " ", substr((string)$body, 0, 400)) . "\n";

    $dec = json_decode((string)$body, true);
    if ($code === 200 && isset($dec['choices'][0]['message']['content'])) {
        echo "\n✅ مغز متنی جارویس کار می‌کند!\n";
    } elseif ($code === 401 || $code === 403) {
        echo "\n❌ هنوز 401/403 — یعنی کلید واقعاً نامعتبر/باطل شده است.\n";
        echo "   → در https://openrouter.ai/settings/keys چک کن؛ شاید کلید revoke شده.\n";
    } else {
        echo "\n⚠️ نتیجهٔ غیرمنتظره — خروجی بالا را کامل بفرست.\n";
    }
}

echo "\n────────────────────────────\n";
echo "⚠️ بعد از اتمام، set_jarvis.php را از هاست حذف کن.\n";
