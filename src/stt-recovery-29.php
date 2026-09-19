<?php
if (!defined('AMLAK_RECOVERY_29')) { http_response_code(404); exit; }

/**
 * 🎙️ موتور تبدیل گفتار به متن (STT) — نسخهٔ چندارائه‌دهنده
 * ---------------------------------------------------------
 * چرا این فایل جداست؟
 *   ۱) اندپوینت قبلی (api-inference.huggingface.co) از اواخر ۲۰۲۵ کاملاً از رده خارج شده
 *      و 410 Gone / 404 برمی‌گرداند. جایگزین رسمی: router.huggingface.co/hf-inference
 *   ۲) شکل پاسخ ارائه‌دهنده‌ها متفاوت است: HF آرایه می‌دهد ([{"text":...}])،
 *      ولی OpenAI/Groq آبجکت می‌دهند ({"text":...}). کد قبلی فقط آبجکت را می‌خواند
 *      و به‌همین دلیل حتی در صورت موفقیت هم متن را پیدا نمی‌کرد.
 *
 * انتخاب ارائه‌دهنده با ثابت STT_PROVIDER در config.php انجام می‌شود:
 *   'hf'     → Hugging Face Router / hf-inference (بایت خام)
 *   'groq'   → Groq (سازگار با OpenAI، multipart) — سریع و رایگان
 *   'openai' → OpenAI Whisper (multipart)
 *
 * همهٔ توابع یک آرایهٔ یکسان برمی‌گردانند:
 *   ['ok'=>bool, 'text'=>string, 'error'=>string, 'provider'=>string]
 */

if (!defined('STT_PROVIDER'))   define('STT_PROVIDER', 'hf');
if (!defined('STT_MODEL'))      define('STT_MODEL', 'openai/whisper-large-v3');
if (!defined('STT_HF_TOKEN'))   define('STT_HF_TOKEN', '');
if (!defined('STT_GROQ_KEY'))   define('STT_GROQ_KEY', '');
if (!defined('STT_OPENAI_KEY')) define('STT_OPENAI_KEY', '');
if (!defined('STT_MODEL_GROQ'))   define('STT_MODEL_GROQ', 'whisper-large-v3-turbo');
if (!defined('STT_MODEL_OPENAI')) define('STT_MODEL_OPENAI', 'whisper-1');
if (!defined('STT_LANGUAGE'))   define('STT_LANGUAGE', 'fa');
if (!defined('STT_MAX_BYTES'))  define('STT_MAX_BYTES', 8 * 1024 * 1024);
if (!defined('STT_TIMEOUT'))    define('STT_TIMEOUT', 45);
if (!defined('STT_CONNECT_TIMEOUT')) define('STT_CONNECT_TIMEOUT', 10);

/**
 * نرمال‌سازی پاسخ هر ارائه‌دهنده به یک رشتهٔ متن.
 * این همان باگی است که باعث می‌شد پاسخ موفق HF دور ریخته شود.
 */
function stt_extract_text($decoded) {
    if (!is_array($decoded)) return '';

    // ۱) شکل OpenAI / Groq:  {"text": "..."}
    if (isset($decoded['text']) && is_string($decoded['text'])) return trim($decoded['text']);

    // ۲) شکل Hugging Face ASR:  [{"text": "..."}]
    if (isset($decoded[0]) && is_array($decoded[0]) && isset($decoded[0]['text'])) {
        return trim($decoded[0]['text']);
    }

    // ۳) برخی مدل‌های Whisper خروجی تکه‌تکه می‌دهند: {"chunks":[{"text":"..."}]}
    if (!empty($decoded['chunks']) && is_array($decoded['chunks'])) {
        $parts = [];
        foreach ($decoded['chunks'] as $c) {
            if (is_array($c) && isset($c['text'])) $parts[] = $c['text'];
        }
        return trim(implode('', $parts));
    }

    // ۴) شکل‌های تودرتو: {"output":{"text":...}} یا {"result":{"text":...}}
    foreach (['output', 'result', 'data'] as $k) {
        if (!empty($decoded[$k]) && is_array($decoded[$k])) {
            $inner = stt_extract_text($decoded[$k]);
            if ($inner !== '') return $inner;
        }
    }

    return '';
}

/** پیام خطای خوانا از پاسخ ارائه‌دهنده (بدون نشت جزئیات به کاربر) */
function stt_extract_error($decoded, $httpCode) {
    if (is_array($decoded)) {
        foreach (['error', 'message', 'detail'] as $k) {
            if (isset($decoded[$k])) {
                $v = $decoded[$k];
                if (is_array($v) && isset($v['message'])) $v = $v['message'];
                if (is_string($v) && $v !== '') return substr($v, 0, 300);
            }
        }
    }
    return 'پاسخ ناموفق از موتور تبدیل صوت (HTTP ' . $httpCode . ').';
}

/** تنظیمات مشترک curl — SSL verify همیشه روشن */
function stt_curl_base($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);   // ⚡ قبلاً false بود
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, STT_CONNECT_TIMEOUT);
    curl_setopt($ch, CURLOPT_TIMEOUT, STT_TIMEOUT);   // ⚡ قبلاً بی‌نهایت بود
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 2);
    return $ch;
}

/** اجرا و جمع‌آوری نتیجهٔ curl — curl_error حتماً قبل از آزادسازی هندل خوانده می‌شود */
function stt_curl_run($ch) {
    $response  = curl_exec($ch);
    $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);          // ⚡ قبل از curl_close
    $errno     = curl_errno($ch);
    curl_close($ch);
    return ['body' => $response, 'http' => $httpCode, 'err' => $curlError, 'errno' => $errno];
}

/** درایور Hugging Face Router (hf-inference) — بدنه = بایت خام صوت */
function stt_call_hf($bytes, $mime) {
    $token = STT_HF_TOKEN;
    if ($token === '') {
        return ['ok' => false, 'text' => '', 'provider' => 'hf',
                'error' => 'توکن Hugging Face در config.php تنظیم نشده است.'];
    }

    // مسیر جدید و رسمی؛ مسیر قدیمی api-inference.huggingface.co مرده است.
    $url = 'https://router.huggingface.co/hf-inference/models/' . STT_MODEL;

    $ch = stt_curl_base($url);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $bytes);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: ' . $mime,
        'Accept: application/json',
    ]);
    $r = stt_curl_run($ch);

    if ($r['err'] !== '' || $r['errno'] !== 0) {
        error_log('[STT/hf] curl error: ' . $r['err']);
        return ['ok' => false, 'text' => '', 'provider' => 'hf',
                'error' => 'ارتباط با موتور صوت برقرار نشد.'];
    }

    $decoded = json_decode((string) $r['body'], true);
    $text = stt_extract_text($decoded);

    if ($r['http'] === 200 && $text !== '') {
        return ['ok' => true, 'text' => $text, 'provider' => 'hf', 'error' => ''];
    }

    $msg = stt_extract_error($decoded, $r['http']);
    error_log('[STT/hf] HTTP ' . $r['http'] . ' body=' . substr((string) $r['body'], 0, 800));

    if ($r['http'] === 503 || stripos($msg, 'loading') !== false || stripos($msg, 'warm') !== false) {
        return ['ok' => false, 'text' => '', 'provider' => 'hf',
                'error' => 'مدل صوتی در حال بارگذاری اولیه است؛ چند ثانیه دیگر دوباره تلاش کنید.'];
    }
    if ($r['http'] === 401 || $r['http'] === 403) {
        return ['ok' => false, 'text' => '', 'provider' => 'hf',
                'error' => 'توکن Hugging Face معتبر نیست یا دسترسی Inference Providers ندارد.'];
    }
    if ($r['http'] === 413 || stripos($msg, 'too large') !== false) {
        return ['ok' => false, 'text' => '', 'provider' => 'hf',
                'error' => 'حجم صدا بیش از حد مجاز است (سقف Hugging Face حدود ۲ مگابایت).'];
    }
    return ['ok' => false, 'text' => '', 'provider' => 'hf', 'error' => $msg];
}

/** درایور سازگار با OpenAI (Groq / OpenAI) — multipart/form-data */
function stt_call_openai_compatible($provider, $url, $key, $model, $tmpPath, $filename) {
    if ($key === '') {
        return ['ok' => false, 'text' => '', 'provider' => $provider,
                'error' => 'کلید ' . $provider . ' در config.php تنظیم نشده است.'];
    }

    $post = [
        'file'            => new CURLFile($tmpPath, 'audio/wav', $filename),
        'model'           => $model,
        'response_format' => 'json',
    ];
    if (STT_LANGUAGE !== '') $post['language'] = STT_LANGUAGE;

    $ch = stt_curl_base($url);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $key,
        'Accept: application/json',
    ]);
    $r = stt_curl_run($ch);

    if ($r['err'] !== '' || $r['errno'] !== 0) {
        error_log('[STT/' . $provider . '] curl error: ' . $r['err']);
        return ['ok' => false, 'text' => '', 'provider' => $provider,
                'error' => 'ارتباط با موتور صوت برقرار نشد.'];
    }

    $decoded = json_decode((string) $r['body'], true);
    $text = stt_extract_text($decoded);

    if ($r['http'] === 200 && $text !== '') {
        return ['ok' => true, 'text' => $text, 'provider' => $provider, 'error' => ''];
    }

    $msg = stt_extract_error($decoded, $r['http']);
    error_log('[STT/' . $provider . '] HTTP ' . $r['http'] . ' body=' . substr((string) $r['body'], 0, 800));

    if ($r['http'] === 401 || $r['http'] === 403) {
        return ['ok' => false, 'text' => '', 'provider' => $provider,
                'error' => 'کلید ' . $provider . ' معتبر نیست.'];
    }
    if ($r['http'] === 413) {
        return ['ok' => false, 'text' => '', 'provider' => $provider,
                'error' => 'حجم فایل صوتی بیش از حد مجاز است.'];
    }
    return ['ok' => false, 'text' => '', 'provider' => $provider, 'error' => $msg];
}

/**
 * اعتبارسنجی فایل آپلودشده.
 * @return array ['ok'=>bool,'error'=>string]
 */
function stt_validate_upload($file) {
    if (!isset($file) || !is_array($file)) {
        return ['ok' => false, 'error' => 'فایل صوتی در درخواست وجود ندارد.'];
    }

    $errMap = [
        UPLOAD_ERR_INI_SIZE   => 'حجم صدا از سقف مجاز سرور (upload_max_filesize) بیشتر است.',
        UPLOAD_ERR_FORM_SIZE  => 'حجم صدا از سقف مجاز فرم بیشتر است.',
        UPLOAD_ERR_PARTIAL    => 'فایل صوتی ناقص آپلود شد؛ دوباره تلاش کنید.',
        UPLOAD_ERR_NO_FILE    => 'فایل صوتی ارسال نشد.',
        UPLOAD_ERR_NO_TMP_DIR => 'پوشهٔ موقت سرور در دسترس نیست.',
        UPLOAD_ERR_CANT_WRITE => 'ذخیرهٔ فایل صوتی روی سرور ناموفق بود.',
        UPLOAD_ERR_EXTENSION  => 'آپلود توسط یک افزونهٔ PHP متوقف شد.',
    ];
    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => $errMap[(int) $file['error']] ?? 'خطای نامشخص در آپلود.'];
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0)            return ['ok' => false, 'error' => 'فایل صوتی خالی است.'];
    if ($size > STT_MAX_BYTES) {
        return ['ok' => false, 'error' => 'صدا خیلی طولانی است (حداکثر ' . round(STT_MAX_BYTES / 1048576) . ' مگابایت).'];
    }

    $name = (string) ($file['name'] ?? '');
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = ['wav', 'webm', 'mp3', 'ogg', 'oga', 'm4a', 'mp4', 'flac', 'aac'];
    if (!in_array($ext, $allowed, true)) {
        return ['ok' => false, 'error' => 'فرمت صوتی پشتیبانی نمی‌شود (مجاز: ' . implode('، ', $allowed) . ').'];
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'فایل صوتی معتبر نیست.'];
    }

    return ['ok' => true, 'error' => ''];
}

/** نگاشت پسوند به MIME واقعی — Content-Type غلط یکی از دلایل رد شدن درخواست است */
function stt_mime_for($name) {
    $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));
    $map = [
        'wav'  => 'audio/wav',
        'webm' => 'audio/webm',
        'mp3'  => 'audio/mpeg',
        'ogg'  => 'audio/ogg',
        'oga'  => 'audio/ogg',
        'm4a'  => 'audio/mp4',
        'mp4'  => 'audio/mp4',
        'flac' => 'audio/flac',
        'aac'  => 'audio/aac',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

/**
 * نقطهٔ ورود واحد.
 * @return array ['ok'=>bool,'text'=>string,'error'=>string,'provider'=>string]
 */
function stt_transcribe($file) {
    $v = stt_validate_upload($file);
    if (!$v['ok']) return ['ok' => false, 'text' => '', 'error' => $v['error'], 'provider' => STT_PROVIDER];

    $tmp   = $file['tmp_name'];
    $name  = $file['name'] ?? 'voice.wav';
    $mime  = stt_mime_for($name);
    $bytes = @file_get_contents($tmp);
    if ($bytes === false || $bytes === '') {
        return ['ok' => false, 'text' => '', 'error' => 'خواندن فایل صوتی ناموفق بود.', 'provider' => STT_PROVIDER];
    }

    $provider = strtolower((string) STT_PROVIDER);

    if ($provider === 'groq') {
        return stt_call_openai_compatible('groq', 'https://api.groq.com/openai/v1/audio/transcriptions',
                                          STT_GROQ_KEY, STT_MODEL_GROQ, $tmp, basename($name));
    }
    if ($provider === 'openai') {
        return stt_call_openai_compatible('openai', 'https://api.openai.com/v1/audio/transcriptions',
                                          STT_OPENAI_KEY, STT_MODEL_OPENAI, $tmp, basename($name));
    }
    // پیش‌فرض: Hugging Face Router
    return stt_call_hf($bytes, $mime);
}
