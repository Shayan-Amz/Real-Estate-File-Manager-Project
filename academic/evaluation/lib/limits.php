<?php
if (!defined('AMLAK_THESIS_EVAL')) { http_response_code(404); exit; }

// A fresh local signing secret, counters, and a short-lived lease are persisted.
// No audio, transcript, password, provider credential, or real-estate record is stored.
function evalState(callable $change) {
    $file = __DIR__ . '/../private/state.php';
    $handle = @fopen($file, 'c+');
    if ($handle) @chmod($file, 0600);
    if (!$handle || !flock($handle, LOCK_EX)) throw new RuntimeException('فضای شمارندهٔ آزمایش قابل نوشتن نیست؛ دسترسی پوشهٔ private را بررسی کنید.');
    try {
        if (fstat($handle)['size'] > 1048576) throw new RuntimeException('فضای شمارنده بیش از حد مجاز است.');
        $raw = stream_get_contents($handle);
        $prefix = "<?php exit; ?>\n";
        if ($raw !== '' && strpos($raw, $prefix) !== 0) throw new RuntimeException('قالب شمارنده معتبر نیست.');
        $state = strpos($raw, $prefix) === 0 ? json_decode(substr($raw, strlen($prefix)), true) : [];
        if (!is_array($state)) throw new RuntimeException('شمارندهٔ آزمایش قابل خواندن نیست؛ برای جلوگیری از مصرف سهمیه آزمون متوقف شد.');
        $now = time();
        foreach ($state as $key => $value) if (is_array($value) && isset($value['until']) && $value['until'] < $now) unset($state[$key]);
        $result = $change($state, $now);
        $encoded = $prefix . json_encode($state);
        rewind($handle);
        if (fwrite($handle, $encoded) !== strlen($encoded) || !ftruncate($handle, strlen($encoded)) || !fflush($handle)) {
            throw new RuntimeException('ذخیرهٔ شمارنده ناموفق بود؛ آزمون متوقف شد.');
        }
        return $result;
    } finally { flock($handle, LOCK_UN); fclose($handle); }
}

function evalTake(string $bucket, int $limit, int $seconds): bool {
    return evalState(function (&$state, $now) use ($bucket, $limit, $seconds) {
        $entry = $state[$bucket] ?? ['count' => 0, 'until' => $now + $seconds];
        if ($entry['count'] >= $limit) return false;
        $entry['count']++; $state[$bucket] = $entry; return true;
    });
}

function evalProviderBudget(string $kind): bool {
    // Count every external request, including failed HTTP calls and fallbacks.
    // These limits do not include other uses of the same provider account.
    $limit = $kind === 'llm' ? 40 : 20;
    return evalTake('provider-' . $kind . '-' . gmdate('Y-m-d'), $limit, 86400);
}

function evalAcquire(): ?string {
    return evalState(function (&$state, $now) {
        if (isset($state['lease']) && $state['lease']['until'] >= $now) return null;
        $id = bin2hex(random_bytes(12)); $state['lease'] = ['id' => $id, 'until' => $now + 240]; return $id;
    });
}

function evalRelease(string $id): void {
    evalState(function (&$state, $now) use ($id) {
        if (($state['lease']['id'] ?? '') === $id) unset($state['lease']);
        return true;
    });
}

function evalSigningKey(): string {
    $base = evalState(function (&$state, $now) {
        if (!isset($state['signing_key_hex'])) $state['signing_key_hex'] = bin2hex(random_bytes(32));
        if (!is_string($state['signing_key_hex']) || !preg_match('/^[a-f0-9]{64}$/D', $state['signing_key_hex'])) {
            throw new RuntimeException('کلید محلی آزمون معتبر نیست.');
        }
        return hex2bin($state['signing_key_hex']);
    });
    // An independent random key, not the main app's historically shared APP_SALT.
    // A master-password hash change invalidates previously issued evaluator tokens.
    return hash_hmac('sha256', 'amlak-thesis-eval-v1|' . MASTER_PASSWORD_HASH, $base, true);
}
