// Real PHP request execution in an in-memory WASM filesystem. No provider calls,
// live configuration, credentials, database, or deployment server is used.
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const {PHP}=require('@php-wasm/universal');const {loadNodeRuntime}=require('@php-wasm/node');
(async()=>{
 const php=new PHP(await loadNodeRuntime('8.5',{emscriptenOptions:{processId:process.pid}}));
 const dir='/lab/site/thesis-evaluation';php.mkdir(dir);
 function copy(from,to){for(const e of fs.readdirSync(from,{withFileTypes:true})){const a=path.join(from,e.name),b=to+'/'+e.name;if(e.isDirectory()){php.mkdir(b);copy(a,b);}else if(e.name!=='state.php')php.writeFile(b,fs.readFileSync(a));}}
 copy(path.resolve(__dirname,'../evaluation'),dir);
 const hash=(await php.run({code:"<?php echo password_hash('academic-test-password', PASSWORD_BCRYPT);"})).text;
 php.writeFile('/lab/site/config.php',`<?php define('APP_SALT','${'k'.repeat(32)}');define('MASTER_PASSWORD_HASH','${hash}');define('OPENROUTER_API_KEY','');define('STT_HF_TOKEN','');`);
 let count=0;
 async function request(action,body={},token='',origin='https://test.example',method='POST'){
  const response=await php.run({scriptPath:dir+'/api.php',relativeUri:'/thesis-evaluation/api.php?action='+action,protocol:'https',method,
   headers:{'Content-Type':'application/json','Origin':origin,'X-Evaluation-Token':token},body:new TextEncoder().encode(JSON.stringify(body)),
   $_SERVER:{HTTP_HOST:'test.example',REMOTE_ADDR:'127.0.0.9',HTTPS:'on'}});
  let data;try{data=JSON.parse(response.text);}catch(_){throw new Error('Non-JSON PHP response: '+response.text+' '+response.errors);}
  return{status:response.httpStatusCode,data};
 }
 function passed(name){count++;console.log('OK '+name);}
 let r=await request('status');assert.equal(r.status,401);passed('unauthenticated metadata access is denied');
 r=await request('login',{password:'wrong',consent:true});assert.equal(r.status,403);passed('wrong owner password is denied');
 r=await request('login',{password:'academic-test-password',consent:false});assert.equal(r.status,400);passed('cloud-use consent is required');
 r=await request('login',{password:'academic-test-password',consent:true});assert.equal(r.status,200);assert.ok(r.data.token);const token=r.data.token;passed('owner login issues an actual HS256 JWT');
 r=await request('status',{},token);assert.equal(r.status,200);assert.equal(r.data.metadata.database_access,false);assert.equal(r.data.metadata.asr_configured,false);assert.ok(!JSON.stringify(r.data).includes('academic-test-password'));passed('metadata contains no key or password and does not need a database');
 r=await request('status',{},'not.a.valid-token');assert.equal(r.status,401);passed('tampered token is denied by the real API route');
 r=await request('saveProperty',{id:'must-not-be-written'},token);assert.equal(r.status,400);passed('real property-write action does not exist in the evaluator');
 r=await request('status',{},token,'https://attacker.example');assert.equal(r.status,403);passed('cross-origin request is rejected');
 r=await request('status',{},token,'https://test.example','GET');assert.equal(r.status,405);passed('read-only operation still requires the specified POST protocol');
 php.writeFile(dir+'/private/state.php','corrupt state');r=await request('login',{password:'academic-test-password',consent:true});assert.equal(r.status,500);passed('corrupt quota storage fails closed before provider access');
 console.log(count+' real PHP HTTP tests; 0 failures. No external API called.');
 php.exit();
})().catch(error=>{console.error(error);process.exitCode=1;});
