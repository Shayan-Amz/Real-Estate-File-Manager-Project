<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../shared/Jwt.php';
use Amlak\Defense\Jwt;
$key = str_repeat('k', 32); // Public test fixture, never a deployment key.
$now = 1700000000;
$issuer = 'test-issuer'; $audience = 'test-client';
$base = ['sub' => 'test-subject', 'a' => '123456', 'r' => 'مدیر', 'n' => 'کاربر آزمایشی', 'p' => 'vip'];
$token = Jwt::issue($base, $key, $issuer, $audience, 60, $now);
if (($argv[1] ?? '') === '--interop-issue') { echo json_encode(compact('token','now','issuer','audience')); exit; }
if (($argv[1] ?? '') === '--verify-peer') {
    $peer = trim(file_get_contents($argv[2]));
    $claims = Jwt::verify($peer, $key, $issuer, $audience, $now + 10);
    echo $claims && $claims['sub'] === 'node-peer' ? 'PEER OK' : 'PEER FAIL'; exit($claims ? 0 : 1);
}
function b64u($value) { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
function signedToken($claims, $header = ['alg'=>'HS256','typ'=>'JWT']) {
    global $key;
    $input = b64u(json_encode($header)) . '.' . b64u(json_encode($claims));
    return $input . '.' . b64u(hash_hmac('sha256', $input, $key, true));
}
$claims = Jwt::verify($token, $key, $issuer, $audience, $now);
$tests = [];
$tests['three base64url segments and 32-byte HS256 signature'] = function() use ($token) {
    $p = explode('.', $token); return count($p)===3 && strpos($token,'=')===false && strlen(base64_decode(strtr($p[2],'-_','+/')))===32;
};
$tests['round trip preserves Unicode and binds the intended agency/role'] = function() use ($claims) { return $claims['n']==='کاربر آزمایشی' && $claims['a']==='123456' && $claims['r']==='مدیر'; };
$tests['wrong key is rejected'] = function() use ($token,$issuer,$audience,$now) { return Jwt::verify($token,str_repeat('x',32),$issuer,$audience,$now)===null; };
$tests['wrong issuer is rejected'] = function() use ($token,$key,$audience,$now) { return Jwt::verify($token,$key,'wrong',$audience,$now)===null; };
$tests['wrong audience is rejected'] = function() use ($token,$key,$issuer,$now) { return Jwt::verify($token,$key,$issuer,'wrong',$now)===null; };
$tests['expiry boundary is exclusive'] = function() use ($token,$key,$issuer,$audience,$now) { return Jwt::verify($token,$key,$issuer,$audience,$now+60)===null; };
$tests['future not-before is rejected'] = function() use ($claims,$key,$issuer,$audience,$now) { $c=$claims;$c['nbf']=$now+20;return Jwt::verify(signedToken($c),$key,$issuer,$audience,$now)===null; };
$tests['future issued-at is rejected'] = function() use ($claims,$key,$issuer,$audience,$now) { $c=$claims;$c['iat']=$now+20;return Jwt::verify(signedToken($c),$key,$issuer,$audience,$now)===null; };
$tests['string expiry is rejected by the application claim profile'] = function() use ($claims,$key,$issuer,$audience,$now) { $c=$claims;$c['exp']=(string)$c['exp'];return Jwt::verify(signedToken($c),$key,$issuer,$audience,$now)===null; };
$tests['missing expiry is rejected'] = function() use ($claims,$key,$issuer,$audience,$now) { $c=$claims;unset($c['exp']);return Jwt::verify(signedToken($c),$key,$issuer,$audience,$now)===null; };
$tests['none algorithm cannot be enabled through a signed header'] = function() use ($claims,$key,$issuer,$audience,$now) { return Jwt::verify(signedToken($claims,['alg'=>'none','typ'=>'JWT']),$key,$issuer,$audience,$now)===null; };
$tests['algorithm substitution HS384 is rejected'] = function() use ($claims,$key,$issuer,$audience,$now) { return Jwt::verify(signedToken($claims,['alg'=>'HS384','typ'=>'JWT']),$key,$issuer,$audience,$now)===null; };
$tests['key URL and unknown critical header parameters are rejected'] = function() use ($claims,$key,$issuer,$audience,$now) { return Jwt::verify(signedToken($claims,['alg'=>'HS256','typ'=>'JWT','jku'=>'https://example.invalid/keys']),$key,$issuer,$audience,$now)===null; };
$tests['payload tampering without resigning is rejected'] = function() use ($token,$key,$issuer,$audience,$now) { $p=explode('.',$token);$p[1]=b64u('{"sub":"attacker"}');return Jwt::verify(implode('.',$p),$key,$issuer,$audience,$now)===null; };
$tests['legacy two-part custom token is not accepted as JWT'] = function() use ($key,$issuer,$audience,$now) { return Jwt::verify('e30.'.hash_hmac('sha256','e30',$key),$key,$issuer,$audience,$now)===null; };
$tests['oversized and malformed tokens fail closed'] = function() use ($key,$issuer,$audience,$now) { return Jwt::verify(str_repeat('x',8200),$key,$issuer,$audience,$now)===null && Jwt::verify('a.!.c',$key,$issuer,$audience,$now)===null; };
$tests['short and placeholder secrets are rejected'] = function() use ($base,$issuer,$audience,$now) { $n=0;foreach([str_repeat('k',31),'CHANGE_TO_AN_INDEPENDENT_RANDOM_SECRET'] as $k){try{Jwt::issue($base,$k,$issuer,$audience,60,$now);}catch(InvalidArgumentException $e){$n++;}}return $n===2; };
$tests['signer controls reserved expiry and issuer claims'] = function() use ($base,$key,$issuer,$audience,$now) { $c=Jwt::verify(Jwt::issue(array_merge($base,['exp'=>9999999999,'iss'=>'attacker']),$key,$issuer,$audience,60,$now),$key,$issuer,$audience,$now);return $c['exp']===$now+60 && $c['iss']===$issuer; };
$tests['unique token identifier changes on each issue'] = function() use ($base,$key,$issuer,$audience,$now,$claims) { $c=Jwt::verify(Jwt::issue($base,$key,$issuer,$audience,60,$now),$key,$issuer,$audience,$now);return $c['jti']!==$claims['jti']; };
$tests['limited clock tolerance is explicit and bounded'] = function() use ($token,$key,$issuer,$audience,$now) { return Jwt::verify($token,$key,$issuer,$audience,$now+61,30)!==null && Jwt::verify($token,$key,$issuer,$audience,$now+91,30)===null && Jwt::verify($token,$key,$issuer,$audience,$now,61)===null; };
$tests['JWT validity is independent of PHP display timezone'] = function() use ($token,$key,$issuer,$audience,$now) { $old=date_default_timezone_get();date_default_timezone_set('Asia/Tehran');$a=Jwt::verify($token,$key,$issuer,$audience,$now);date_default_timezone_set('UTC');$b=Jwt::verify($token,$key,$issuer,$audience,$now);date_default_timezone_set($old);return $a===$b; };
$tests['evaluation tokens cannot be used with the defense application audience'] = function() use ($key,$now) { $t=Jwt::issue(['sub'=>'thesis-owner','scope'=>'measure-ai'],$key,'amlak-thesis-eval-v1','amlak-thesis-ui-v1',60,$now);return Jwt::verify($t,$key,'amlak-defense-api-v1','amlak-defense-web-v1',$now)===null; };
$tests['audience arrays must contain only strings, not object or numeric values'] = function() use ($claims,$key,$issuer,$audience,$now) {
    $c=$claims;$c['aud']=[$audience,7];if(Jwt::verify(signedToken($c),$key,$issuer,$audience,$now)!==null)return false;
    $c['aud']=(object)['0'=>$audience];if(Jwt::verify(signedToken($c),$key,$issuer,$audience,$now)!==null)return false;
    $c['aud']=[$audience,'other'];return Jwt::verify(signedToken($c),$key,$issuer,$audience,$now)!==null;
};
$failed=0;
foreach($tests as $name=>$run){try{$ok=$run();}catch(Throwable $e){$ok=false;}echo($ok?'OK ':'FAIL ').$name."\n";if(!$ok)$failed++;}
echo count($tests)." JWT tests; $failed failures.\n";exit($failed?1:0);
