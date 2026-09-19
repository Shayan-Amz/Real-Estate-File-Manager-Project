<?php
/**
 * 🔬 ابزار تشخیص پایپلاین صوت (STT)
 * ------------------------------------------------------------------
 * چرا؟  در محیط توسعه امکان اجرای PHP وجود ندارد، پس اینکه کدام ارائه‌دهندهٔ
 *       تبدیل صوت روی هاست شما واقعاً جواب می‌دهد فقط با اجرای واقعی روی
 *       همان هاست قابل تعیین است. این اسکریپت همان تست را انجام می‌دهد.
 *
 * اجرا از خط فرمان:
 *     php tools/stt_probe.php
 * اجرا از مرورگر:
 *     https://your-domain/tools/stt_probe.php?key=PROBE-AMLAK-2026
 *
 * ⚠️ بعد از استفاده این پوشه را حذف کنید.
 * ⚠️ هیچ کلیدی چاپ نمی‌شود (فقط ۶ کاراکتر اول).
 */

$fromCli = (php_sapi_name() === 'cli');
if (!$fromCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (($_GET['key'] ?? '') !== 'PROBE-AMLAK-2026') {
        http_response_code(403);
        exit("برای اجرا از مرورگر، پارامتر ?key=PROBE-AMLAK-2026 لازم است (یا از خط فرمان اجرا کنید).\n");
    }
}

$configPath = __DIR__ . '/../config.php';
if (!is_file($configPath)) exit("❌ config.php پیدا نشد (مسیر مورد انتظار: $configPath)\n");
require_once $configPath;

$mask = function ($s) {
    $s = (string) $s;
    return $s === '' ? '(خالی)' : substr($s, 0, 6) . '…' . substr($s, -3) . ' [' . strlen($s) . ' کاراکتر]';
};

echo "=========================================================\n";
echo " 🔬 تشخیص پایپلاین تبدیل گفتار به متن\n";
echo "=========================================================\n";
echo 'زمان            : ' . date('Y-m-d H:i:s') . "\n";
echo 'PHP             : ' . PHP_VERSION . "\n";
echo 'curl            : ' . (function_exists('curl_init') ? '✔ نصب است (' . curl_version()['version'] . ')' : '❌ نصب نیست') . "\n";
echo 'openssl         : ' . (extension_loaded('openssl') ? '✔' : '❌') . "\n";
echo 'upload_max_filesize : ' . ini_get('upload_max_filesize') . "\n";
echo 'post_max_size       : ' . ini_get('post_max_size') . "\n";
echo 'max_execution_time  : ' . ini_get('max_execution_time') . "\n";
echo "\n--- تنظیمات STT از config.php ---\n";
echo 'STT_PROVIDER     : ' . (defined('STT_PROVIDER') ? STT_PROVIDER : '(تعریف نشده)') . "\n";
echo 'STT_MODEL        : ' . (defined('STT_MODEL') ? STT_MODEL : '(تعریف نشده)') . "\n";
echo 'STT_HF_TOKEN     : ' . $mask(defined('STT_HF_TOKEN') ? STT_HF_TOKEN : '') . "\n";
echo 'STT_GROQ_KEY     : ' . $mask(defined('STT_GROQ_KEY') ? STT_GROQ_KEY : '') . "\n";
echo 'STT_OPENAI_KEY   : ' . $mask(defined('STT_OPENAI_KEY') ? STT_OPENAI_KEY : '') . "\n";
echo 'STT_LANGUAGE     : ' . (defined('STT_LANGUAGE') ? STT_LANGUAGE : '(تعریف نشده)') . "\n";

if (!function_exists('curl_init')) {
    exit("\n❌ بدون افزونهٔ curl این پایپلاین کار نمی‌کند.\n");
}

/** ساخت یک فایل WAV کوتاه و معتبر (یک ثانیه سکوت) برای تست مسیر */
function probe_make_wav($seconds = 1, $rate = 16000) {
    $len = $rate * $seconds;
    $data = str_repeat("\x00\x00", $len);
    $h  = 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE';
    $h .= 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1);
    $h .= pack('V', $rate) . pack('V', $rate * 2) . pack('v', 2) . pack('v', 16);
    $h .= 'data' . pack('V', strlen($data));
    return $h . $data;
}

function probe_request($label, $url, $headers, $post) {
    echo "\n---------------------------------------------------------\n";
    echo "▶ $label\n  $url\n";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);
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
    echo '  پاسخ (۴۰۰ کاراکتر اول):\n    ' . str_replace("\n", "\n    ", substr((string) $body, 0, 400)) . "\n";

    $decoded = json_decode((string) $body, true);
    $hasText = is_array($decoded)
        && (isset($decoded['text']) || (isset($decoded[0]['text'])) || !empty($decoded['chunks']));

    if ($code === 200 && $hasText) {
        echo "  ✅ نتیجه: کار می‌کند (پاسخ معتبر با کلید text)\n";
    } elseif ($code === 200) {
        echo "  ⚠️ نتیجه: HTTP 200 ولی ساختار پاسخ ناشناخته — خروجی بالا را بررسی کنید\n";
    } elseif ($code === 410 || $code === 404) {
        echo "  ❌ نتیجه: اندپوینت وجود ندارد / از رده خارج شده است\n";
    } elseif ($code === 401 || $code === 403) {
        echo "  ❌ نتیجه: توکن/کلید نامعتبر یا بدون دسترسی لازم\n";
    } elseif ($code === 413) {
        echo "  ❌ نتیجه: حجم بدنه بیش از حد مجاز\n";
    } elseif ($code === 0) {
        echo "  ❌ نتیجه: اتصال برقرار نشد (احتمالاً فایروال یا DNS هاست)\n";
    } else {
        echo "  ❌ نتیجه: ناموفق\n";
    }
    return $code;
}

$wav = probe_make_wav(1, 16000);
echo "\nفایل تست: WAV مونو ۱۶kHz، " . strlen($wav) . " بایت\n";

// ۱) مسیر جدید و رسمی Hugging Face
if (defined('STT_HF_TOKEN') && STT_HF_TOKEN !== '') {
    probe_request(
        'Hugging Face Router / hf-inference (مسیر جدید و درست)',
        'https://router.huggingface.co/hf-inference/models/' . (defined('STT_MODEL') ? STT_MODEL : 'openai/whisper-large-v3'),
        ['Authorization: Bearer ' . STT_HF_TOKEN, 'Content-Type: audio/wav', 'Accept: application/json'],
        $wav
    );

    // ۲) مسیر قدیمی — فقط برای اثبات اینکه مرده است
    probe_request(
        'Hugging Face (مسیر قدیمی api-inference — انتظار 410/404)',
        'https://api-inference.huggingface.co/models/' . (defined('STT_MODEL') ? STT_MODEL : 'openai/whisper-large-v3'),
        ['Authorization: Bearer ' . STT_HF_TOKEN, 'Content-Type: audio/wav', 'Accept: application/json'],
        $wav
    );
} else {
    echo "\n⚠️ STT_HF_TOKEN خالی است — تست Hugging Face رد شد.\n";
}

// ۳) Groq
if (defined('STT_GROQ_KEY') && STT_GROQ_KEY !== '') {
    $tmp = tempnam(sys_get_temp_dir(), 'stt');
    file_put_contents($tmp, $wav);
    probe_request(
        'Groq (OpenAI-compatible)',
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
}

// ۴) OpenAI
if (defined('STT_OPENAI_KEY') && STT_OPENAI_KEY !== '') {
    $tmp = tempnam(sys_get_temp_dir(), 'stt');
    file_put_contents($tmp, $wav);
    probe_request(
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
}

echo "\n=========================================================\n";
echo "✅ پایان. کل خروجی بالا را برای من بفرستید تا STT_PROVIDER درست تنظیم شود.\n";
echo "⚠️ بعد از این کار پوشهٔ tools/ را از هاست حذف کنید.\n";
