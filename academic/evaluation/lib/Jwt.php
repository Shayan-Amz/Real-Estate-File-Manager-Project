<?php
namespace Amlak\Defense;

/** RFC 7519 compact JWS, fixed HS256 (RFC 7518), with an application claim profile.
 * Signing authenticates claims; it DOES NOT encrypt them. Use TLS and protect keys.
 */
final class Jwt {
    private static function key(string $key): void {
        if (strlen($key) < 32 || strpos($key, 'CHANGE_') === 0) {
            throw new \InvalidArgumentException('A non-placeholder secret of at least 32 bytes is required.');
        }
    }

    private static function base64url(string $bytes): string {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function unbase64url(string $text): string {
        if ($text === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $text)) throw new \InvalidArgumentException('Bad base64url.');
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);
        if ($bytes === false || self::base64url($bytes) !== $text) throw new \InvalidArgumentException('Non-canonical base64url.');
        return $bytes;
    }

    public static function issue(array $claims, string $key, string $issuer, string $audience, int $ttl = 3600, ?int $now = null): string {
        self::key($key);
        $now = $now ?? time();
        if ($ttl < 1 || $ttl > 108000 || $issuer === '' || $audience === ''
            || !isset($claims['sub']) || !is_string($claims['sub']) || $claims['sub'] === '' || strlen($claims['sub']) > 256) {
            throw new \InvalidArgumentException('Invalid token parameters.');
        }
        // Reserved security claims are signer-controlled, not request-controlled.
        $claims = array_merge($claims, ['iss' => $issuer, 'aud' => $audience, 'iat' => $now,
            'nbf' => $now, 'exp' => $now + $ttl, 'jti' => bin2hex(random_bytes(16))]);
        $header = self::base64url('{"alg":"HS256","typ":"JWT"}');
        $payload = self::base64url(json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $input = $header . '.' . $payload;
        return $input . '.' . self::base64url(hash_hmac('sha256', $input, $key, true));
    }

    public static function verify(string $token, string $key, string $issuer, string $audience, ?int $now = null, int $leeway = 0): ?array {
        self::key($key);
        if (strlen($token) > 8192 || $leeway < 0 || $leeway > 60) return null;
        $now = $now ?? time();
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) return null;
            $header = json_decode(self::unbase64url($parts[0]), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256' || ($header['typ'] ?? '') !== 'JWT'
                || array_diff(array_keys($header), ['alg', 'typ'])) return null;
            $signature = self::unbase64url($parts[2]);
            $expected = hash_hmac('sha256', $parts[0] . '.' . $parts[1], $key, true);
            if (strlen($signature) !== 32 || !hash_equals($expected, $signature)) return null;
            $object = json_decode(self::unbase64url($parts[1]), false, 16, JSON_THROW_ON_ERROR);
            if (!is_object($object)) return null;
            $claims = (array)$object;
            if (($claims['iss'] ?? null) !== $issuer) return null;
            $aud = $claims['aud'] ?? null;
            if (is_array($aud)) {
                if (!$aud || count(array_filter($aud, function($v) { return is_string($v) && $v !== ''; })) !== count($aud)
                    || !in_array($audience, $aud, true)) return null;
            } elseif ($aud !== $audience) return null;
            foreach (['iat', 'nbf', 'exp'] as $name) if (!isset($claims[$name]) || !is_int($claims[$name])) return null;
            if ($claims['exp'] <= $now - $leeway || $claims['nbf'] > $now + $leeway || $claims['iat'] > $now + $leeway
                || $claims['iat'] < 0 || $claims['exp'] <= $claims['iat'] || $claims['nbf'] >= $claims['exp']) return null;
            foreach (['sub', 'jti'] as $name) {
                if (!isset($claims[$name]) || !is_string($claims[$name]) || $claims[$name] === '' || strlen($claims[$name]) > 256) return null;
            }
            return $claims;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
