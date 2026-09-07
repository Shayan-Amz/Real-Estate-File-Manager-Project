<?php
// Independent, authenticated, read-only academic measurements. No DB connection.
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");

function evalReply(array $body, int $status = 200): void {
    http_response_code($status);
    if (ob_get_length()) ob_clean();
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
set_exception_handler(function($e) {
    error_log('[thesis-eval] ' . get_class($e)); // Do not log requests, passwords, or provider bodies.
    evalReply(['ok' => false, 'error' => 'خطای داخلی ابزار آزمون؛ تنظیمات PHP و امکان نوشتن پوشهٔ private را بررسی کنید.'], 500);
});
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') evalReply(['ok' => false, 'error' => 'درخواست باید از صفحهٔ ابزار ارسال شود.'], 405);
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 9 * 1024 * 1024) evalReply(['ok' => false, 'error' => 'حجم درخواست بیش از حد مجاز است.'], 413);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    if ($originHost !== $host || parse_url($origin, PHP_URL_SCHEME) !== 'https') evalReply(['ok' => false, 'error' => 'مبدأ درخواست مجاز نیست؛ ابزار را با HTTPS باز کنید.'], 403);
}

// Only this explicit file is read. No request parameter can choose a config path.
$config = dirname(__DIR__) . '/config.php';
if (!is_file($config)) evalReply(['ok' => false, 'error' => 'پوشهٔ thesis-evaluation باید مستقیماً داخل public_html و کنار config.php سایت باشد.'], 503);
require_once $config;
if (!defined('MASTER_PASSWORD_HASH') || !is_string(MASTER_PASSWORD_HASH) || MASTER_PASSWORD_HASH === '') evalReply(['ok' => false, 'error' => 'تنظیمات رمز مدیریت کل کامل نیست.'], 503);
define('AMLAK_THESIS_EVAL', true);
require_once __DIR__ . '/lib/Jwt.php';
require_once __DIR__ . '/lib/limits.php';
require_once __DIR__ . '/lib/stt.php';
require_once __DIR__ . '/lib/ai.php';
$evalKey = evalSigningKey();
$action = $_GET['action'] ?? '';
if (!is_string($action) || !in_array($action, ['login', 'status', 'transcribe', 'extract'], true)) evalReply(['ok' => false, 'error' => 'عملیات نامعتبر است.'], 400);

$input = [];
if ($action !== 'transcribe') {
    $raw = file_get_contents('php://input', false, null, 0, 32769);
    if (strlen($raw) > 32768) evalReply(['ok' => false, 'error' => 'متن درخواست بیش از حد مجاز است.'], 413);
    $input = json_decode($raw, true);
    if (!is_array($input)) evalReply(['ok' => false, 'error' => 'بدنهٔ درخواست معتبر نیست.'], 400);
}

if ($action === 'login') {
    // The owner enters the existing master password in this HTTPS form only.
    // It is not stored in browser storage, server files, logs, or exported results.
    $ipKey = hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown', $evalKey);
    if (!evalTake('login-' . $ipKey, 5, 600)) evalReply(['ok' => false, 'error' => 'تلاش ورود زیاد است؛ ده دقیقه صبر کنید.'], 429);
    $password = $input['password'] ?? null;
    if (!is_string($password) || strlen($password) > 512 || !password_verify($password, MASTER_PASSWORD_HASH)) evalReply(['ok' => false, 'error' => 'رمز مدیریت کل درست نیست.'], 403);
    if (($input['consent'] ?? false) !== true) evalReply(['ok' => false, 'error' => 'ارسال دادهٔ آزمایشی به سرویس‌ها و مصرف سهمیه را تأیید کنید.'], 400);
    $token = \Amlak\Defense\Jwt::issue(['sub' => 'thesis-owner', 'scope' => 'measure-ai'], $evalKey, 'amlak-thesis-eval-v1', 'amlak-thesis-ui-v1', 3600);
    evalReply(['ok' => true, 'token' => $token, 'expires_in' => 3600]);
}

// A custom header avoids replacing Apache Basic Auth's Authorization header.
$token = $_SERVER['HTTP_X_EVALUATION_TOKEN'] ?? '';
$claims = is_string($token) ? \Amlak\Defense\Jwt::verify($token, $evalKey, 'amlak-thesis-eval-v1', 'amlak-thesis-ui-v1') : null;
if (!$claims || ($claims['scope'] ?? '') !== 'measure-ai') evalReply(['ok' => false, 'error' => 'ورود ابزار منقضی یا نامعتبر است؛ دوباره وارد شوید.'], 401);

$meta = ['build' => 'thesis-eval-v1', 'baseline' => 'a24348e', 'utc' => gmdate('c'),
    'php_version' => PHP_VERSION, 'server_timezone' => date_default_timezone_get(),
    'asr_provider' => STT_PROVIDER, 'asr_model' => evalAsrModel(), 'llm_model' => evalModel(),
    'prompt_sha256' => hash('sha256', require __DIR__ . '/lib/prompt.php'),
    'dataset_sha256' => hash_file('sha256', __DIR__ . '/cases.json'),
    'asr_timeout_s' => STT_TIMEOUT, 'max_llm_attempts' => 3,
    'measurement_scope' => 'server service-call latency includes network/queue; not GPU-only inference time',
    'database_access' => false, 'audio_persisted_by_tool' => false];
if ($action === 'status') {
    $meta['curl_available'] = function_exists('curl_init');
    $meta['asr_configured'] = STT_PROVIDER === 'hf' ? STT_HF_TOKEN !== '' : (STT_PROVIDER === 'groq' ? STT_GROQ_KEY !== '' : STT_OPENAI_KEY !== '');
    $meta['llm_configured'] = defined('OPENROUTER_API_KEY') && OPENROUTER_API_KEY !== '';
    evalReply(['ok' => true, 'metadata' => $meta]);
}
if (!function_exists('curl_init')) evalReply(['ok' => false, 'error' => 'افزونهٔ cURL فعال نیست.'], 503);
if (!evalTake('actions-' . $action, 6, 60)) evalReply(['ok' => false, 'error' => 'تعداد آزمون زیاد است؛ یک دقیقه صبر کنید.'], 429);
$lease = evalAcquire();
if ($lease === null) evalReply(['ok' => false, 'error' => 'آزمون دیگری هنوز در حال پردازش است؛ پس از پایان آن دوباره اقدام کنید.'], 409);
$response = [];
try {
    if ($action === 'transcribe') {
        $file = $_FILES['audio_file'] ?? null;
        $valid = stt_validate_upload($file);
        if (!$valid['ok']) $response = ['ok' => false, 'error' => $valid['error']];
        elseif (!evalProviderBudget('asr')) $response = ['ok' => false, 'error' => 'سقف روزانهٔ آزمایش صوت تکمیل شده است؛ فردا ادامه دهید.'];
        else {
            $start = microtime(true);
            $GLOBALS['evalAsrTrace'] = [];
            $result = stt_transcribe($file);
            $response = ['ok' => $result['ok'], 'result' => $result, 'error' => $result['error'] ?? '',
                'server_asr_ms' => round((microtime(true) - $start) * 1000, 3),
                'audio_sha256' => hash_file('sha256', $file['tmp_name']), 'audio_bytes' => (int)$file['size'],
                'attempts' => $GLOBALS['evalAsrTrace']];
        }
    } else {
        $text = $input['text'] ?? null;
        if (!is_string($text) || trim($text) === '' || strlen($text) > 24000 || preg_match('//u', $text) !== 1) {
            $response = ['ok' => false, 'error' => 'متن آزمایشی معتبر نیست.'];
        } else {
            $result = evalExtract(trim($text));
            $response = ['ok' => $result['ok'], 'result' => $result, 'error' => $result['error']];
        }
    }
} finally { evalRelease($lease); }
$response['metadata'] = $meta;
evalReply($response);
