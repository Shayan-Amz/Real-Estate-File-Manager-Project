<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('JARVIS_BUILD','fix30-form-r1');
// Executes the actual response-contract block, not a copy of its implementation.
// The eval input is trusted repository PHP source; model text is only JSON-decoded.
function contractBlock($source) {
    $start = strpos($source, '                $parsedData = json_decode($aiContent, true);');
    $end = strpos($source, '            // ... (کدهای قبلی)', $start);
    if ($start === false || $end === false) throw new RuntimeException('Contract boundary missing');
    return substr($source,$start,$end-$start);
}
function runContract($block,$aiContent) {
    ob_start();eval($block);$out=ob_get_clean();
    return json_decode($out,true);
}
$a=file_get_contents(__DIR__.'/../src/api.php');
$b=file_get_contents(__DIR__.'/../src/api-recovery-29.php');
$block=contractBlock($a);$failed=0;$count=0;
function check($name,$condition){global $failed,$count;$count++;echo($condition?'OK ':'FAIL ').$name."\n";if(!$condition)$failed++;}
check('both active API paths share the same contract', $block===contractBlock($b));
check('both active API paths use the shared versioned prompt', substr_count($a,"'/jarvis-prompt30.txt'")===1 && substr_count($b,"'/jarvis-prompt30.txt'")===1);
$r=runContract($block,json_encode(['ai_message'=>'آمادهٔ بررسی','params'=>['dealType'=>'فروش','price'=>5000000000,'floor'=>0,'hasParking'=>false]]));
check('valid numbers, zero and false survive the response', $r['response']['params']['price']===5000000000 && $r['response']['params']['floor']===0 && $r['response']['params']['hasParking']===false);
check('only the fixed safe draft action is emitted', $r['response']['action']==='openPropertyModal' && $r['response']['jarvisBuild']===JARVIS_BUILD);
$r=runContract($block,json_encode(['action'=>'deleteEverything','params'=>['area'=>120,'id'=>'victim','agencyId'=>'other','role'=>'مدیر','images'=>['bad'],'showToGuest'=>true,'isVIP'=>true]]));
check('model cannot choose an executable or destructive action', $r['response']['action']==='openPropertyModal');
$keys=array_keys($r['response']['params']);
check('identity/images/publication keys are excluded', !array_intersect($keys,['id','agencyId','role','images','showToGuest','isVIP']));
$r=runContract($block,'{"params":{"area":[120],"price":{"value":5},"hasParking":false}}');
check('nested unexpected field types become unknown, not code or strings', $r['response']['params']['area']===null && $r['response']['params']['price']===null && $r['response']['action']===null);
$r=runContract($block,'{"params":{}}');check('empty properties do not request a form reset',$r['response']['action']===null);
foreach(['not-json','[]','{"params":[]}','{"params":"bad"}'] as $input)check('invalid structure rejected: '.$input,isset(runContract($block,$input)['error']));
$r=runContract($block,'{"ai_message":{},"params":{"price":"۵ میلیارد تومان","usage":"آپارتمان"}}');
check('legacy string values are retained for deterministic client normalization', $r['response']['params']['price']==='۵ میلیارد تومان' && is_string($r['response']['ai_message']));
$prompt=file_get_contents(__DIR__.'/../src/jarvis-prompt30.txt');
check('prompt specifies Toman conversion and per-meter versus total price',strpos($prompt,'5200000000')!==false && strpos($prompt,'pricePerMeter')!==false && strpos($prompt,'ریال را بر ده تقسیم')!==false);
echo "$count PHP contract tests; $failed failures. No DB or model service used.\n";exit($failed?1:0);
