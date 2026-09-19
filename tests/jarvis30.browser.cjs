// Actual Chromium + real application HTML, with fixture HTTP responses only.
// No live API, customer data, upload, deletion or provider request is performed.
const fs=require('node:fs'),path=require('node:path'),os=require('node:os'),zlib=require('node:zlib'),assert=require('node:assert/strict');
const {execFileSync}=require('node:child_process');
const puppeteer=require('puppeteer-core'),chromium=require('@sparticuz/chromium');
const ROOT=path.resolve(__dirname,'..'),latest=fs.readFileSync(path.join(ROOT,'src/index.html'),'utf8');
const legacy=execFileSync('git',['show','f6b8687:src/index.html'],{cwd:ROOT,encoding:'utf8'});
const dir=path.dirname(require.resolve('@sparticuz/chromium'));
const archive=[path.resolve(dir,'../bin/al2023.tar.br'),path.resolve(dir,'../../bin/al2023.tar.br')].find(fs.existsSync);
if(archive){const temp=fs.mkdtempSync(path.join(os.tmpdir(),'amlak-form30-'));fs.writeFileSync(path.join(temp,'libs.tar'),zlib.brotliDecompressSync(fs.readFileSync(archive)));execFileSync('tar',['-xf',path.join(temp,'libs.tar'),'-C',temp]);process.env.LD_LIBRARY_PATH=path.join(temp,'lib')+':'+(process.env.LD_LIBRARY_PATH||'');}
const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const sale={dealType:'فروش',usage:'مسکونی',area:120,price:'۵ میلیارد تومان',rooms:'۲',floor:'۰',unit:'۲',yearBuilt:'۱۴۰۰',city:'سمنان',location:'محله آزمایشی',referrer:'مالک آزمایشی',phone:'09000000000'.replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[Number(d)]),hasParking:'false',hasElevator:'true',hasStorage:false};
const response=params=>({response:{success:true,action:'openPropertyModal',ai_message:'پیش‌نویس آزمایشی',params,jarvisBuild:'fix30-form-r1'}});
const existing={id:'p_existing',agencyId:'100001',authorName:'مدیر',status:'موجود',usage:'مسکونی',area:80,rooms:'1',floor:'2',unit:'3',yearBuilt:'1395',dealType:'رهن و اجاره',deposit:100000000,rent:1000000,price:0,city:'شهر قبلی',location:'محله قبلی',referrer:'مالک قبلی',phone:'09000000001',date:'2026-09-07T12:00:00Z',images:['uploads/old-fixture.jpg'],showToGuest:true,showPriceGuest:true,showImagesGuest:true,hasParking:true,lat:35,lng:51};
const database={response:{agencies:{'100001':{id:'100001',name:'آژانس آزمایشی',city:'سمنان',phone:'02100000000',expireAt:'2099-12-31',plan_type:'vip'}},properties:{p_existing:existing},members:{},demands:{},dataHash:'fixture-30'}};
(async()=>{
 const browser=await puppeteer.launch({args:chromium.args.filter(a=>a!=='--single-process'),executablePath:await chromium.executablePath(),headless:true});let checks=0;
 async function open({html=latest,brain=async()=>response(sale),asr=async()=>({success:true,text:'متن آزمایشی صوت'})}={}){
  const context=await browser.createBrowserContext(),page=await context.newPage(),calls=[],errors=[];
  await page.setViewport({width:1280,height:900});page.on('pageerror',e=>errors.push(e.message));
  await page.evaluateOnNewDocument(()=>{if(navigator.serviceWorker)navigator.serviceWorker.register=async()=>({});localStorage.setItem('amlakProfile',JSON.stringify({name:'مدیر',role:'مدیر',agencyId:'100001',agencyName:'آژانس آزمایشی',token:'fixture-token',plan:'vip'}));});
  await page.setRequestInterception(true);
  page.on('request',req=>{(async()=>{
   const url=new URL(req.url());if(url.host==='static.neshan.org')return;
   if(url.pathname==='/index.html')return req.respond({status:200,contentType:'text/html',body:html});
   if(url.pathname.startsWith('/api')){
    const action=url.searchParams.get('action');let input={};try{input=JSON.parse(req.postData()||'{}');}catch(_){}
    calls.push({action,input});let body;
    if(action==='getData')body=database;else if(action==='jarvisProcess')body=await brain(input,calls);else if(action==='transcribe_audio')body=await asr();else body={response:{success:true}};
    return req.respond({status:200,headers:{'X-Amlak-Recovery':'fix29-baseline26'},contentType:'application/json',body:JSON.stringify(body)});
   }
   if(/chart\.umd|jalalidatepicker/.test(url.pathname))return;
   const file=path.join(ROOT,'src',decodeURIComponent(url.pathname).replace(/^\//,''));
   if(file.startsWith(path.join(ROOT,'src')+path.sep)&&fs.existsSync(file)&&fs.statSync(file).isFile())return req.respond({status:200,body:fs.readFileSync(file)});
   return req.respond({status:404,body:''});
  })().catch(()=>{});});
  await page.goto('https://form.test/index.html?api.php=30',{waitUntil:'domcontentloaded'});
  await page.waitForSelector('#properties-container .property-card');
  return{context,page,calls,errors,async send(text='فروش آزمایشی'){await page.evaluate(async text=>{document.getElementById('jarvis-modal').classList.remove('hidden');document.getElementById('jarvis-input').value=text;await window.sendToJarvis();},text);}};
 }
 const value=(page,id)=>page.$eval('#'+id,e=>e.value);
 const visible=(page,id)=>page.$eval('#'+id,e=>getComputedStyle(e).display!=='none');
 const ok=name=>{checks++;console.log('OK '+name);};
 try{
  let p=await open({html:legacy,brain:async()=>response({...sale,price:'۵۰۰۰۰۰۰۰۰۰'})});await p.send();await wait(2700);
  assert.equal(await value(p.page,'price'),'0');ok('reproduced the old Persian-price deletion in the actual browser');await p.context.close();

  p=await open();await p.send();assert.equal(await value(p.page,'price'),'5,000,000,000');assert.equal(await value(p.page,'area'),'120');assert.equal(await value(p.page,'floor'),'0');assert.equal(await value(p.page,'rooms'),'2');
  assert.equal(await p.page.$eval('#has-parking',e=>e.checked),false);assert.equal(await p.page.$eval('#has-elevator',e=>e.checked),true);assert.equal(await value(p.page,'phone'),'09000000000');
  assert.equal(await visible(p.page,'add-view'),true);assert.equal(await visible(p.page,'jarvis-modal'),false);assert.ok(!p.calls.some(c=>c.action==='saveProperty'));assert.deepEqual(p.errors,[]);
  ok('sale money/digits/booleans/zero floor land in the correct form without saving');
  await p.page.evaluate(()=>{for(const [id,v] of [['area','۱۲۰'],['floor','۰'],['year-built','۱۴۰۰'],['price','۵۰۰۰۰۰۰۰۰۰']]){const e=document.getElementById(id);e.value=v;e.dispatchEvent(new Event('input',{bubbles:true}));}});
  assert.equal(await value(p.page,'area'),'120');assert.equal(await value(p.page,'year-built'),'1400');assert.equal(await value(p.page,'price'),'5,000,000,000');ok('manual review with Persian digits also preserves numeric fields');
  await p.page.evaluate(()=>document.getElementById('add-form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));await wait(50);
  const saved=p.calls.find(c=>c.action==='saveProperty');assert.ok(saved);assert.equal(saved.input.price,5000000000);assert.notEqual(saved.input.id,'p_existing');
  ok('explicit submit sends the exact numeric sale price and a new property ID');await p.context.close();

  p=await open({brain:async()=>response({...sale,dealType:'رهن/اجاره',usage:'آپارتمان',price:null,deposit:'۳۰۰ میلیون',rent:'۱۲ میلیون',rooms:'۵+'})});await p.send();
  assert.equal(await value(p.page,'deal-type'),'رهن و اجاره');assert.equal(await value(p.page,'usage'),'مسکونی');assert.equal(await value(p.page,'deposit'),'300,000,000');assert.equal(await value(p.page,'rent'),'12,000,000');assert.equal(await value(p.page,'rooms'),'5+');
  assert.equal(await visible(p.page,'price-container'),false);assert.equal(await visible(p.page,'deposit-container'),true);assert.equal(await visible(p.page,'rent-container'),true);assert.deepEqual(p.errors,[]);
  ok('rental aliases select the real controls and populate separate deposit/rent amounts');
  await p.page.evaluate(()=>window.cancelEdit());await p.page.evaluate(()=>window.switchTab('add'));
  assert.equal(await value(p.page,'deal-type'),'فروش');assert.equal(await visible(p.page,'price-container'),true);assert.equal(await visible(p.page,'rent-container'),false);
  ok('cancel/reset also resets the visible price layout, not only the hidden deal field');await p.context.close();

  p=await open({brain:async()=>response({...sale,dealType:'رهن کامل',price:null,deposit:'یک و نیم میلیارد',rent:0})});await p.send();
  assert.equal(await value(p.page,'deposit'),'1,500,000,000');assert.equal(await value(p.page,'rent'),'0');assert.equal(await visible(p.page,'rent-container'),false);ok('full rent and explicit zero do not leave stale sale/rental amounts');await p.context.close();

  p=await open({brain:async()=>response({...sale,dealType:null,usage:null})});await p.send();
  assert.equal(await value(p.page,'deal-type'),'');assert.equal(await value(p.page,'usage'),'');assert.equal(await value(p.page,'price'),'5,000,000,000');
  await p.page.evaluate(()=>window.handlePropertySubmit({preventDefault(){}}));assert.ok(!p.calls.some(c=>c.action==='saveProperty'));
  ok('known prices survive missing enum values, but saving requires explicit type/usage selection');await p.context.close();

  p=await open({brain:async()=>response({...sale,usage:'دفتر کار',floor:'۴',unit:'۱۰',rooms:null})});await p.send();
  assert.equal(await visible(p.page,'residential-options'),true);assert.equal(await value(p.page,'floor'),'4');assert.equal(await value(p.page,'unit'),'10');assert.equal(await value(p.page,'rooms'),'');ok('office floor/unit are visible and an unspecified bedroom is not invented as studio');await p.context.close();

  p=await open({brain:async()=>response({...sale,usage:'ویلا',buildArea:'۱۵۰ متر',area:220})});await p.send();assert.equal(await visible(p.page,'land-metrics'),true);assert.equal(await value(p.page,'build-area'),'150');ok('villa built area remains visible rather than being cleared by usage layout');await p.context.close();

  p=await open({brain:async()=>response({...sale,area:100,price:null,pricePerMeter:'۶۰ میلیون'})});await p.send();assert.equal(await value(p.page,'price'),'6,000,000,000');assert.match(await p.page.$eval('#jarvis-draft-note',e=>e.textContent),/محاسبه/);ok('explicit price-per-meter calculation is disclosed and produces the exact total');await p.context.close();

  p=await open();await p.page.evaluate(()=>window.editProperty('p_existing'));await p.send();
  assert.equal(await value(p.page,'referrer'),'مالک قبلی');assert.equal(await value(p.page,'deposit'),'100000000');assert.equal(await p.page.evaluate(()=>editingId),'p_existing');
  const clickApply=()=>p.page.evaluate(()=>Array.from(document.querySelectorAll('#jarvis-chat-box button')).find(b=>b.textContent==='اعمال پیش‌نویس جدید').click());
  p.page.once('dialog',d=>d.dismiss());await clickApply();assert.equal(await p.page.evaluate(()=>editingId),'p_existing');
  p.page.once('dialog',d=>d.accept());await clickApply();
  assert.equal(await p.page.evaluate(()=>editingId),null);assert.equal(await p.page.evaluate(()=>tempImagesBase64.length),0);assert.equal(await p.page.$eval('#show-to-guest',e=>e.checked),false);assert.equal(await value(p.page,'deposit'),'');assert.equal(await value(p.page,'geo-lat'),'');
  assert.ok(!p.calls.some(c=>c.action==='saveProperty'));ok('editing/photos/private-public flags stay untouched until confirmed; accepted AI draft never updates the old ID');await p.context.close();

  let release;const pending=new Promise(resolve=>{release=resolve;});p=await open({brain:async()=>{await pending;return response(sale);}});
  await p.page.evaluate(()=>{document.getElementById('jarvis-modal').classList.remove('hidden');document.getElementById('jarvis-input').value='دستور کند';window.sendToJarvis();});
  await p.page.waitForFunction(()=>document.querySelector('[id^="typing-"]'));
  await p.page.evaluate(()=>{document.getElementById('city').value='ورود دستی تازه';document.getElementById('jarvis-modal').classList.add('hidden');});release();
  await p.page.waitForFunction(()=>Array.from(document.querySelectorAll('#jarvis-chat-box button')).some(b=>b.textContent==='اعمال پیش‌نویس جدید'));
  assert.equal(await value(p.page,'city'),'ورود دستی تازه');assert.equal(await visible(p.page,'jarvis-modal'),false);ok('manual edits and a manually closed chat are not overwritten or reopened by a delayed reply');await p.context.close();

  let releaseOld;const oldPending=new Promise(resolve=>{releaseOld=resolve;});p=await open({brain:async input=>{if(input.text==='old'){await oldPending;return response({...sale,price:100});}return response({...sale,price:7000000000});}});
  await p.page.evaluate(()=>{document.getElementById('jarvis-modal').classList.remove('hidden');document.getElementById('jarvis-input').value='old';window.sendToJarvis();});
  await p.send('new');assert.equal(await value(p.page,'price'),'7,000,000,000');releaseOld();await wait(100);assert.equal(await value(p.page,'price'),'7,000,000,000');ok('out-of-order model replies cannot replace the latest draft');
  await p.page.evaluate(()=>{document.getElementById('price').value='8,000,000,000';window.switchTab('profile');});await wait(2700);
  assert.equal(await value(p.page,'price'),'8,000,000,000');assert.equal(await visible(p.page,'profile-view'),true);assert.equal(await visible(p.page,'jarvis-modal'),false);ok('no old delayed price writer or chat toggle fires after the user moves elsewhere');await p.context.close();

  p=await open({brain:async()=>response({...sale,dealType:'رهن و اجاره',price:null,deposit:'۳۰۰ میلیون',rent:'۱۲ میلیون'})});
  await p.page.evaluate(async()=>{document.getElementById('jarvis-modal').classList.remove('hidden');await window.processAudioOnServer(new Blob(['fixture'],{type:'audio/wav'}));});
  assert.equal(await value(p.page,'deposit'),'300,000,000');assert.equal(await value(p.page,'rent'),'12,000,000');assert.ok(p.calls.some(c=>c.action==='transcribe_audio'));assert.equal(p.calls.filter(c=>c.action==='jarvisProcess').length,1);
  await p.page.evaluate(()=>document.getElementById('add-form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));await wait(50);
  const rented=p.calls.find(c=>c.action==='saveProperty');assert.equal(rented.input.deposit,300000000);assert.equal(rented.input.rent,12000000);assert.equal(rented.input.dealType,'رهن و اجاره');
  ok('successful voice path shares the same filler and explicit rental submit sends exact amounts');await p.context.close();

  let releaseAudio;const audioPending=new Promise(resolve=>{releaseAudio=resolve;});p=await open({asr:async()=>{await audioPending;return{success:true,text:'old voice'};},brain:async()=>response({...sale,price:9000000000})});
  await p.page.evaluate(()=>{document.getElementById('jarvis-modal').classList.remove('hidden');window.processAudioOnServer(new Blob(['fixture'],{type:'audio/wav'}));});
  await p.send('new text');releaseAudio();await wait(100);assert.equal(await value(p.page,'price'),'9,000,000,000');assert.equal(p.calls.filter(c=>c.action==='jarvisProcess').length,1);ok('late ASR response is dropped before it can issue a competing model request');await p.context.close();

  p=await open({brain:async()=>response({})});await p.page.evaluate(()=>document.getElementById('city').value='باقی بماند');await p.send();assert.equal(await value(p.page,'city'),'باقی بماند');assert.ok(!p.calls.some(c=>c.action==='saveProperty'));ok('empty model response does not clear an existing draft');await p.context.close();

  p=await open({brain:async()=>({response:{success:true,ai_message:'<img src=x onerror="window.injected=true">',action:'openPropertyModal',params:{}}})});await p.send('<svg onload="window.injected=true">');
  assert.equal(await p.page.evaluate(()=>!!window.injected),false);assert.equal(await p.page.$$eval('#jarvis-chat-box img,#jarvis-chat-box svg',es=>es.length),0);assert.deepEqual(p.errors,[]);ok('typed messages and model replies render as text, not executable HTML');await p.context.close();

  console.log(checks+' Jarvis real-browser checks; 0 failures. No live service called.');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
