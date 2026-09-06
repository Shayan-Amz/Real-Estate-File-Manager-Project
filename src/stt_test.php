<?php
/**
 * 🎙️ ابزار تست واقعی پایپلاین صوت (STT) + مغز جارویس — نسخهٔ وب
 * ================================================================
 * چرا؟  فایل tools/stt_probe.php فقط از SSH اجرا می‌شود (از وب بسته است)،
 * ولی این فایل در ریشهٔ سایت است و با کلید محافظت می‌شود — دقیقاً مثل
 * fix_pin.php که روی هاست جواب داد.
 *
 * کاری که می‌کند:
 *   ۱) توکن‌ها/کلیدها را فقط ماسک می‌کند (۶ کاراکتر اول + ۳ کاراکتر آخر) — چیزی لو نمی‌دهد
 *   ۲) با یک WAV سکوتِ ۱ ثانیه‌ای، سه ارائه‌دهنده را واقعاً صدا می‌زند:
 *        - Hugging Face Router (رسمی جدید)
 *        - Groq (اگر کلیدش را گذاشته باشی)
 *        - OpenAI Whisper (اگر کلیدش را گذاشته باشی)
 *   ۳) مغز متنی جارویس (OpenRouter) را هم با یک پیام کوتاه تست می‌کند
 *   ۴) در پایان پیشنهاد می‌دهد STT_PROVIDER را چه بگذاری
 *
 * 🔑 آدرس اجرا (کلید لازم است):
 *    https://دامنه/stt_test.php?key=stt-test-9f3c71ab60d54e8f
 *
 * ⚠️ بعد از تست، این فایل را از public_html پاک کن.
 */

const STT_TEST_KEY = 'stt-test-9f3c71ab60d54e8f';

if (($_GET['key'] ?? '') !== STT_TEST_KEY) {
    http_response_code(403);
    exit("403 — کلید لازم است. آدرس درست:\n  " . $_SERVER['HTTP_HOST'] . "/stt_test.php?key=" . STT_TEST_KEY . "\n");
}

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '0');

$mask = function ($s) {
    $s = (string) $s;
    if ($s === '') return '(خالی)';
    if (strlen($s) <= 12) return '(خیلی کوتاه — چک کن درست کپی شده)';
    return substr($s, 0, 6) . '…' . substr($s, -3) . ' [' . strlen($s) . ' کاراکتر]';
};

echo "=========================================================\n";
echo " 🎙️ تست پایپلاین صوت + مغز جارویس\n";
echo "=========================================================\n";
echo 'زمان            : ' . date('Y-m-d H:i:s') . "\n";
echo 'PHP             : ' . PHP_VERSION . "\n";
echo 'curl            : ' . (function_exists('curl_init') ? '✔ نصب است (' . curl_version()['version'] . ')' : '❌ نصب نیست') . "\n";
echo 'openssl         : ' . (extension_loaded('openssl') ? '✔' : '❌') . "\n";
echo 'upload_max_filesize : ' . ini_get('upload_max_filesize') . "\n";
echo 'post_max_size       : ' . ini_get('post_max_size') . "\n";

echo "\n--- تنظیمات فعلی (ماسک‌شده) ---\n";
echo 'STT_PROVIDER     : ' . (defined('STT_PROVIDER') ? STT_PROVIDER : '(تعریف نشده)') . "\n";
echo 'STT_MODEL        : ' . (defined('STT_MODEL') ? STT_MODEL : '(تعریف نشده)') . "\n";
echo 'STT_HF_TOKEN     : ' . $mask(defined('STT_HF_TOKEN') ? STT_HF_TOKEN : '') . "\n";
echo 'STT_GROQ_KEY     : ' . $mask(defined('STT_GROQ_KEY') ? STT_GROQ_KEY : '') . "\n";
echo 'STT_OPENAI_KEY   : ' . $mask(defined('STT_OPENAI_KEY') ? STT_OPENAI_KEY : '') . "\n";
echo 'OPENROUTER_KEY   : ' . $mask(defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '') . "\n";
echo 'OPENROUTER_URL   : ' . (defined('OPENROUTER_URL') ? OPENROUTER_URL : '(تعریف نشده)') . "\n";

if (!function_exists('curl_init')) {
    exit("\n❌ بدون افزونهٔ curl این پایپلاین کار نمی‌کند.\n");
}

/** ساخت فایل WAV کوتاه و معتبر (یک ثانیه سکوت) برای تست مسیر */
function sttest_make_wav($seconds = 1, $rate = 16000) {
    $len = $rate * $seconds;
    $data = str_repeat("\x00\x00", $len);
    $h  = 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE';
    $h .= 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1);
    $h .= pack('V', $rate) . pack('V', $rate * 2) . pack('v', 2) . pack('v', 16);
    $h .= 'data' . pack('V', strlen($data));
    return $h . $data;
}

/** یک درخواست را اجرا و نتیجه را گزارش می‌کند */
function sttest_request($label, $url, $headers, $post, $timeout = 45) {
    echo "\n---------------------------------------------------------\n";
    echo "▶ $label\n  $url\n";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 2);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);

    $t0 = microtime(true);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $ms = round((microtime(true) - $t0) * 1000);

    echo '  HTTP        : ' . $code . "  ({$ms}ms)\n";
    if ($err !== '') echo '  curl error  : ' . $err . "\n";
    echo '  پاسخ (۴۰۰ کاراکتر اول):' . "\n    " . str_replace("\n", "\n    ", substr((string) $body, 0, 400)) . "\n";

    $decoded = json_decode((string) $body, true);
    $hasText = is_array($decoded)
        && (isset($decoded['text']) || isset($decoded[0]['text']) || !empty($decoded['chunks'])
            || (isset($decoded['choices'][0]['message']['content'])));

    if ($code === 200 && $hasText) {
        echo "  ✅ نتیجه: کار می‌کند\n";
        return [true, $code];
    }
    if ($code === 200) {
        echo "  ⚠️ نتیجه: HTTP 200 ولی ساختار ناشناخته\n";
        return [false, $code];
    }
    if ($code === 401 || $code === 403) {
        echo "  ❌ نتیجه: توکن/کلید نامعتبر یا بدون دسترسی لازم\n";
    } elseif ($code === 410 || $code === 404) {
        echo "  ❌ نتیجه: اندپوینت وجود ندارد / از رده خارج شده\n";
    } elseif ($code === 413) {
        echo "  ❌ نتیجه: حجم بدنه بیش از حد مجاز\n";
    } elseif ($code === 0) {
        echo "  ❌ نتیجه: اتصال برقرار نشد (فایروال/DNS هاست)\n";
    } else {
        echo "  ❌ نتیجه: ناموفق (کد $code)\n";
    }
    return [false, $code];
}

$wav = sttest_make_wav(1, 16000);
echo "\nفایل تست: WAV مونو ۱۶kHz، " . strlen($wav) . " بایت\n";
$okHf = false;
$okGroq = false;
$okOpenAI = false;
$okJarvis = false;

/* ۱) Hugging Face Router */
if (defined('STT_HF_TOKEN') && STT_HF_TOKEN !== '') {
    list($okHf) = sttest_request(
        'Hugging Face Router / hf-inference',
        'https://router.huggingface.co/hf-inference/models/' . (defined('STT_MODEL') ? STT_MODEL : 'openai/whisper-large-v3'),
        ['Authorization: Bearer ' . STT_HF_TOKEN, 'Content-Type: audio/wav', 'Accept: application/json'],
        $wav
    );
} else {
    echo "\n⚠️ STT_HF_TOKEN خالی است — تست Hugging Face رد شد.\n";
}

/* ۲) Groq */
if (defined('STT_GROQ_KEY') && STT_GROQ_KEY !== '') {
    $tmp = tempnam(sys_get_temp_dir(), 'stt');
    file_put_contents($tmp, $wav);
    list($okGroq) = sttest_request(
        'Groq (Whisper)',
        'https://api.groq.com/openai/v1/audio/transcriptions',
        ['Authorization: Bearer ' . STT_GROQ_KEY, 'Accept: application/json'],
        [
            'file'            => new CURLFile($tmp, 'audio/wav', 'probe.wav'),
            'model'           => defined('STT_MODEL_GROQ') ? STT_MODEL_GROQ : 'whisper-large-v3-turbo',
            'response_format' => 'json',
            'language'        => defined('STT_LANGUAGE') ? STT_LANGUAGE : 'fa',
        ]
    );
    @unlink($tmp);
} else {
    echo "\n⚠️ STT_GROQ_KEY خالی است — تست Groq رد شد.\n";
}

/* ۳) OpenAI Whisper */
if (defined('STT_OPENAI_KEY') && STT_OPENAI_KEY !== '') {
    $tmp = tempnam(sys_get_temp_dir(), 'stt');
    file_put_contents($tmp, $wav);
    list($okOpenAI) = sttest_request(
        'OpenAI Whisper',
        'https://api.openai.com/v1/audio/transcriptions',
        ['Authorization: Bearer ' . STT_OPENAI_KEY, 'Accept: application/json'],
        [
            'file'            => new CURLFile($tmp, 'audio/wav', 'probe.wav'),
            'model'           => defined('STT_MODEL_OPENAI') ? STT_MODEL_OPENAI : 'whisper-1',
            'response_format' => 'json',
            'language'        => defined('STT_LANGUAGE') ? STT_LANGUAGE : 'fa',
        ]
    );
    @unlink($tmp);
} else {
    echo "\n⚠️ STT_OPENAI_KEY خالی است — تست OpenAI رد شد.\n";
}

/* ۴) مغز متنی جارویس (OpenRouter) */
if (defined('OPENROUTER_API_KEY') && OPENROUTER_API_KEY !== '') {
    list($okJarvis) = sttest_request(
        'مغز متنی جارویس (OpenRouter)',
        defined('OPENROUTER_URL') ? OPENROUTER_URL : 'https://openrouter.ai/api/v1/chat/completions',
        ['Content-Type: application/json', 'Authorization: Bearer ' . OPENROUTER_API_KEY],
        json_encode([
            'model'      => defined('OPENROUTER_MODEL') ? OPENROUTER_MODEL : 'laguna-xs-2.1:free',
            'messages'   => [['role' => 'user', 'content' => 'فقط بگو: سلام']],
            'max_tokens' => 8,
        ]),
        30
    );
} else {
    echo "\n⚠️ OPENROUTER_API_KEY خالی است — تست مغز متنی رد شد.\n";
}

/* جمع‌بندی */
echo "\n=========================================================\n";
echo " 📋 جمع‌بندی — در config.php این‌ها را بگذار:\n";
echo "---------------------------------------------------------\n";
if ($okHf) {
    echo " ✅ Hugging Face جواب می‌دهد →  STT_PROVIDER = 'hf'\n";
} elseif ($okGroq) {
    echo " ✅ Groq جواب می‌دهد (و HF نه) →  STT_PROVIDER = 'groq'\n";
} elseif ($okOpenAI) {
    echo " ✅ OpenAI جواب می‌دهد (و بقیه نه) →  STT_PROVIDER = 'openai'\n";
} else {
    echo " ❌ هنوز هیچ ارائه‌دهندهٔ STT جواب نداده.\n";
    echo "    اگر توکن HF را عوض کردی: مطمئن شو تیک «Make calls to Inference Providers»\n";
    echo "    در huggingface.co → Settings → Access Tokens فعال است و کلید دوباره کپی شده.\n";
}
if ($okJarvis) echo " ✅ مغز متنی جارویس هم جواب می‌دهد.\n";
else echo " ⚠️ مغز متنی جارویس تست نشد/ناموفق بود (کلید OpenRouter لازم است).\n";
echo "---------------------------------------------------------\n";
echo "⚠️ بعد از تست، stt_test.php را از هاست پاک کن.\n";
