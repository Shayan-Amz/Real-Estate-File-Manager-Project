<?php
if (!defined('AMLAK_THESIS_EVAL')) { http_response_code(404); exit; }

function evalModel(): string {
    $model = defined('OPENROUTER_MODEL') ? trim((string)OPENROUTER_MODEL) : '';
    return $model !== '' ? $model : 'google/gemma-4-26b-a4b-it:free';
}

function evalAsrModel(): string {
    if (STT_PROVIDER === 'groq') return STT_MODEL_GROQ;
    if (STT_PROVIDER === 'openai') return STT_MODEL_OPENAI;
    return STT_MODEL;
}

function evalHttp(string $url, array $body, string $key): array {
    if (!evalProviderBudget('llm')) return ['http' => null, 'errno' => null, 'body' => '', 'budget' => true, 'skipped' => true, 'total_ms' => null];
    $ch = curl_init($url);
    $raw = ''; $overflow = false;
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2,
        CURLOPT_CONNECTTIMEOUT => STT_CONNECT_TIMEOUT, CURLOPT_TIMEOUT => STT_TIMEOUT,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key,
            'HTTP-Referer: https://test.amlak-e-man.ir', 'X-Title: Amlak Man Jarvis'],
        CURLOPT_WRITEFUNCTION => function($handle, $chunk) use (&$raw, &$overflow) {
            if (strlen($raw) + strlen($chunk) > 262144) { $overflow = true; return 0; }
            $raw .= $chunk; return strlen($chunk);
        }
    ]);
    curl_exec($ch);
    $out = ['http' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'errno' => curl_errno($ch),
        'total_ms' => round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000, 3),
        'connect_ms' => round(curl_getinfo($ch, CURLINFO_CONNECT_TIME) * 1000, 3),
        'starttransfer_ms' => round(curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) * 1000, 3),
        'overflow' => $overflow, 'body' => $raw];
    curl_close($ch); return $out;
}

/** Same prompt, configured model, JSON/no-JSON variants, and official-first order
 * as baseline a24348e. Added only instrumentation, response-size and quota guards.
 * A 429 stops the experiment, rather than spending additional account quota.
 */
function evalExtract(string $text, ?callable $transport = null): array {
    $started = microtime(true);
    $prompt = require __DIR__ . '/prompt.php';
    $model = evalModel();
    $key = defined('OPENROUTER_API_KEY') ? (string)OPENROUTER_API_KEY : '';
    $out = ['ok' => false, 'model_requested' => $model, 'model_returned' => null, 'params' => null,
        'raw_content' => null, 'json_valid' => false, 'attempts' => [], 'error' => '',
        'prompt_sha256' => hash('sha256', $prompt), 'input_sha256' => hash('sha256', $text)];
    if ($key === '' || strpos($model, '/') === false) {
        $out['error'] = 'کلید یا شناسهٔ مدل در تنظیمات سایت کامل نیست؛ مدل دیگری به‌جای انتخاب شما استفاده نمی‌شود.';
        $out['server_ms'] = null; return $out;
    }
    $urls = ['https://openrouter.ai/api/v1/chat/completions'];
    if (defined('OPENROUTER_URL') && trim((string)OPENROUTER_URL) !== '') $urls[] = trim((string)OPENROUTER_URL);
    $tries = [];
    foreach (array_unique($urls) as $url) {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) !== null) continue;
        $tries[] = [$url, true]; $tries[] = [$url, false];
    }
    $transport = $transport ?? 'evalHttp';
    foreach (array_slice($tries, 0, 3) as $attempt) {
        [$url, $jsonMode] = $attempt;
        $body = ['model' => $model, 'provider' => ['allow_fallbacks' => true],
            'messages' => [['role' => 'system', 'content' => $prompt], ['role' => 'user', 'content' => $text]]];
        if ($jsonMode) $body['response_format'] = ['type' => 'json_object'];
        $r = $transport($url, $body, $key);
        $trace = $r; unset($trace['body']);
        $trace['endpoint_host'] = parse_url($url, PHP_URL_HOST); $trace['json_mode'] = $jsonMode;
        $out['attempts'][] = $trace;
        if ($r['http'] === 200 && !$r['errno']) {
            $decoded = json_decode($r['body'], true);
            $out['model_returned'] = $decoded['model'] ?? null;
            $content = $decoded['choices'][0]['message']['content'] ?? null;
            if (!is_string($content)) { $out['error'] = 'پاسخ متنی مدل موجود نیست.'; break; }
            $out['raw_content'] = $content;
            // Same JSON-fence cleanup as the actual application, no answer repair.
            $clean = trim(preg_replace('/```(?:json)?\s*/', '', $content));
            $parsed = json_decode($clean, true);
            $out['json_valid'] = json_last_error() === JSON_ERROR_NONE;
            if (!is_array($parsed) || !isset($parsed['params']) || !is_array($parsed['params']) || !$parsed['params']) {
                $out['error'] = 'پاسخ مدل، شیء مشخصات معتبر ندارد؛ این اجرا ناموفق ثبت می‌شود.'; break;
            }
            $out['params'] = $parsed['params'];
            $out['action'] = $parsed['action'] ?? null;
            $out['ok'] = true; break;
        }
        $out['error'] = !empty($r['budget']) ? 'سقف روزانهٔ ابزار تکمیل شد؛ ادامه را روز دیگر انجام دهید.'
            : ('خطای سرویس مدل؛ وضعیت ' . $r['http'] . ($r['errno'] ? '، خطای ارتباط ' . $r['errno'] : ''));
        if (!empty($r['budget']) || in_array($r['http'], [401, 402, 429], true)) break;
    }
    $out['server_ms'] = round((microtime(true) - $started) * 1000, 3);
    return $out;
}
