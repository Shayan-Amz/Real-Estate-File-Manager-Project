const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const {JSDOM}=require('jsdom');
const root=path.resolve(__dirname,'../evaluation');
const dataset=JSON.parse(fs.readFileSync(path.join(root,'cases.json'),'utf8'));
const tick=()=>new Promise(resolve=>setImmediate(resolve));
async function setup({badJson=false,failExtract=false,wrongArea=false}={}){
 const dom=new JSDOM(fs.readFileSync(path.join(root,'index.html'),'utf8'),{url:'https://test.example/thesis-evaluation/',runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window,calls=[];
 w.URL.createObjectURL=()=> 'blob:fixture';w.URL.revokeObjectURL=()=>{};
 w.fetch=async(input,options={})=>{
  calls.push({input,options});
  let body;
  if(input==='cases.json')body=dataset;
  else if(input.includes('login'))body={ok:true,token:'fixture.jwt.token'};
  else if(input.includes('status'))body={ok:true,metadata:{asr_provider:'hf',asr_model:'test/whisper',llm_model:'test/one-model',curl_available:true,asr_configured:true,llm_configured:true}};
  else if(input.includes('extract'))body=failExtract?{ok:false,error:'quota fixture'}:{ok:true,result:{ok:true,params:{...dataset.cases[0].gold,...(wrongArea?{area:999}:{})},server_ms:10,json_valid:true,attempts:[]}};
  else throw new Error('Unexpected request');
  return{ok:true,status:200,json:async()=>{if(badJson&&input.includes('login'))throw new Error('bad JSON');return body;}};
 };
 for(const file of ['metrics.js','app.js'])new vm.Script(fs.readFileSync(path.join(root,file),'utf8')).runInContext(dom.getInternalVMContext());
 async function settle(){await tick();await tick();await tick();}
 async function login(){w.document.getElementById('master-password').value='test-password';w.document.getElementById('consent').checked=true;w.document.getElementById('login-form').dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));await settle();}
 return{dom,w,calls,settle,login,close(){dom.window.close();}};
}
test('owner login clears password and JWT is only a custom in-memory request header',async t=>{
 const b=await setup();t.after(()=>b.close());await b.login();
 assert.equal(b.w.document.getElementById('master-password').value,'');assert.equal(b.w.document.getElementById('workspace').hidden,false);
 const status=b.calls.find(c=>c.input.includes('status'));assert.equal(status.options.headers['X-Evaluation-Token'],'fixture.jwt.token');assert.ok(!status.options.headers.Authorization);
 assert.ok(!b.w.localStorage.getItem('amlakProfile'));assert.ok(!JSON.stringify(b.w.localStorage).includes('fixture.jwt.token'));
});
test('manual trial never invokes an external-processing endpoint or real property save',async t=>{
 const b=await setup();t.after(()=>b.close());await b.login();
 b.w.document.getElementById('mode').value='manual';b.w.document.getElementById('mode').dispatchEvent(new b.w.Event('change'));
 b.w.document.getElementById('start').click();await b.settle();
 assert.equal(b.w.document.getElementById('review').hidden,false);
 b.w.document.getElementById('review-form').dispatchEvent(new b.w.Event('submit',{cancelable:true}));
 assert.equal(b.calls.filter(c=>c.input.includes('extract')||c.input.includes('transcribe')||c.input.includes('saveProperty')).length,0);
 const data=JSON.parse(b.w.localStorage.getItem('amlak-thesis-observations-v1'));assert.equal(data.runs[0].mode,'manual');assert.equal(data.runs[0].status,'completed');
});
test('raw incorrect extraction is preserved separately from human correction',async t=>{
 const b=await setup({wrongArea:true});t.after(()=>b.close());await b.login();b.w.document.getElementById('start').click();await b.settle();
 const field=b.w.document.querySelector('[data-field="area"]');assert.equal(field.value,'999');field.value='120';
 b.w.document.getElementById('review-form').dispatchEvent(new b.w.Event('submit',{cancelable:true}));
 const run=JSON.parse(b.w.localStorage.getItem('amlak-thesis-observations-v1')).runs[0];
 assert.equal(run.llm.result.params.area,999);assert.equal(run.final_params.area,120);assert.ok(run.raw_field_score.accuracy<1);assert.equal(run.final_field_score.accuracy,1);
 assert.ok(run.workflow_ms>=0);assert.ok(!JSON.stringify(run).includes('fixture.jwt.token'));assert.ok(!JSON.stringify(run).includes('test-password'));
});
test('service failure is retained as a failure, never turned into a zero-latency success',async t=>{
 const b=await setup({failExtract:true});t.after(()=>b.close());await b.login();b.w.document.getElementById('start').click();await b.settle();
 const run=JSON.parse(b.w.localStorage.getItem('amlak-thesis-observations-v1')).runs[0];assert.equal(run.status,'failed');assert.equal(run.failed_at,'llm');assert.ok(!run.raw_field_score);assert.ok(!run.final_field_score);
 assert.equal(b.w.document.getElementById('download').disabled,false);assert.equal(b.w.document.getElementById('start').disabled,false);
});
test('invalid HTTP/HTML response is a readable error rather than a blank interface',async t=>{
 const b=await setup({badJson:true});t.after(()=>b.close());await b.login();assert.ok(b.w.document.getElementById('message').textContent.includes('JSON'));assert.equal(b.w.document.getElementById('login-panel').hidden,false);assert.equal(b.w.document.getElementById('login-button').disabled,false);
});
test('cancelling an unfinished manual trial keeps the observation in the export dataset',async t=>{
 const b=await setup();t.after(()=>b.close());await b.login();b.w.document.getElementById('mode').value='manual';b.w.document.getElementById('start').click();await b.settle();b.w.document.getElementById('cancel').click();
 const run=JSON.parse(b.w.localStorage.getItem('amlak-thesis-observations-v1')).runs[0];assert.equal(run.status,'cancelled');assert.ok(run.error);assert.equal(b.w.document.getElementById('start').disabled,false);
});
test('late permission from a cancelled recording stops only its own stream',async t=>{
 const b=await setup();t.after(()=>b.close());await b.login();
 let resolveFirst;const first=new Promise(resolve=>{resolveFirst=resolve;});
 const trackA={stopped:false,stop(){this.stopped=true;},getSettings(){return{};}},trackB={stopped:false,stop(){this.stopped=true;},getSettings(){return{};}};
 const a={getTracks:()=>[trackA],getAudioTracks:()=>[trackA]},c={getTracks:()=>[trackB],getAudioTracks:()=>[trackB]};let n=0;
 Object.defineProperty(b.w.navigator,'mediaDevices',{value:{getUserMedia:()=>++n===1?first:Promise.resolve(c)},configurable:true});
 b.w.MediaRecorder=class extends b.w.EventTarget{static isTypeSupported(){return true;}constructor(){super();this.state='inactive';this.mimeType='audio/webm';}start(){this.state='recording';}stop(){this.state='inactive';queueMicrotask(()=>this.dispatchEvent(new b.w.Event('stop')));}};
 b.w.document.getElementById('mode').value='voice';b.w.document.getElementById('start').click();await b.settle();
 b.w.document.getElementById('cancel').click();b.w.document.getElementById('start').click();await b.settle();
 resolveFirst(a);await b.settle();assert.equal(trackA.stopped,true);assert.equal(trackB.stopped,false);
 b.w.document.getElementById('cancel').click();await b.settle();assert.equal(trackB.stopped,true);
 assert.equal(b.calls.filter(call=>call.input.includes('transcribe')).length,0);
});
