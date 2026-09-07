<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('AMLAK_THESIS_EVAL', true);
define('OPENROUTER_MODEL', 'fixture/one-model');
define('OPENROUTER_API_KEY', 'fixture-not-a-real-provider-credential');
define('OPENROUTER_URL', 'https://proxy.example.invalid/chat');
require __DIR__ . '/../evaluation/lib/stt.php';
require __DIR__ . '/../evaluation/lib/ai.php';
$tests=[];
$tests['text extraction supports real provider envelope variants'] = function() {
    return stt_extract_text(['text'=>'سلام'])==='سلام' && stt_extract_text([['text'=>'سلام']])==='سلام'
        && stt_extract_text(['output'=>['text'=>'سلام']])==='سلام'
        && stt_extract_text(['chunks'=>[['text'=>'سلام '],['text'=>'جهان']]])==='سلام جهان';
};
$tests['configured single model and original-first endpoint are preserved'] = function() {
    $calls=[];
    $r=evalExtract('متن ساختگی',function($url,$body,$key) use (&$calls){
        $calls[]=[$url,$body];return ['http'=>200,'errno'=>0,'body'=>json_encode(['model'=>'fixture/one-model','choices'=>[['message'=>['content'=>'{"params":{"area":120}}']]]]),'total_ms'=>12];
    });
    return $r['ok'] && count($calls)===1 && $calls[0][0]==='https://openrouter.ai/api/v1/chat/completions'
        && $calls[0][1]['model']==='fixture/one-model' && !isset($calls[0][1]['models']) && $r['params']['area']===120;
};
$tests['unsupported JSON mode retries without it, not with another model'] = function() {
    $calls=[];
    $r=evalExtract('متن ساختگی',function($url,$body,$key) use (&$calls){
        $calls[]=$body;
        return count($calls)===1 ? ['http'=>400,'errno'=>0,'body'=>'bad format','total_ms'=>1]
            : ['http'=>200,'errno'=>0,'body'=>json_encode(['choices'=>[['message'=>['content'=>"```json\n{\"params\":{\"area\":120}}\n```"]]]]),'total_ms'=>5];
    });
    return $r['ok'] && count($calls)===2 && isset($calls[0]['response_format']) && !isset($calls[1]['response_format']) && $calls[0]['model']===$calls[1]['model'];
};
$tests['rate and account failures stop instead of burning further quota'] = function() {
    foreach([401,402,429] as $code){$n=0;$r=evalExtract('متن',function()use(&$n,$code){$n++;return ['http'=>$code,'errno'=>0,'body'=>'denied','total_ms'=>1];});if($n!==1||$r['ok'])return false;}return true;
};
$tests['invalid model JSON is recorded as failure without fabricated/ repaired params'] = function() {
    $r=evalExtract('متن',function(){return ['http'=>200,'errno'=>0,'body'=>json_encode(['choices'=>[['message'=>['content'=>'not JSON']]]]),'total_ms'=>1];});
    return !$r['ok'] && $r['json_valid']===false && $r['params']===null && $r['raw_content']==='not JSON';
};
$tests['all attempts are bounded and credential values are never returned'] = function() {
    $n=0;$r=evalExtract('متن',function()use(&$n){$n++;return ['http'=>503,'errno'=>0,'body'=>'upstream failure','total_ms'=>1];});
    return $n===3 && count($r['attempts'])===3 && strpos(json_encode($r),OPENROUTER_API_KEY)===false;
};
$failed=0;foreach($tests as $name=>$run){try{$ok=$run();}catch(Throwable $e){$ok=false;}echo($ok?'OK ':'FAIL ').$name."\n";if(!$ok)$failed++;}
echo count($tests)." fixture AI tests; $failed failures. No external API called.\n";exit($failed?1:0);
