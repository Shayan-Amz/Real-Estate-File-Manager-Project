// Real headless Chromium regression tests. All HTTP requests are intercepted;
// no production site, database, Neshan service, or model API is contacted.
const fs = require('node:fs'), path = require('node:path'), os = require('node:os');
const {execFileSync} = require('node:child_process');
const zlib = require('node:zlib'), assert = require('node:assert/strict');
const puppeteer = require('puppeteer-core'), chromium = require('@sparticuz/chromium');
const ROOT = path.resolve(__dirname, '..');
const latest = fs.readFileSync(path.join(ROOT, 'src/index.html'), 'utf8');
const baseline = execFileSync('git', ['show', 'a24348e:src/index.html'], {cwd: ROOT, encoding: 'utf8'});
const scratch = path.join(os.tmpdir(), 'amlak-browser29-' + process.pid);
fs.mkdirSync(scratch, {recursive: true});
// Minimal sandboxes may not ship NSS/NSPR. The Chromium package includes them.
const packageDir = path.dirname(require.resolve('@sparticuz/chromium'));
const archive = [path.resolve(packageDir, '../bin/al2023.tar.br'), path.resolve(packageDir, '../../bin/al2023.tar.br')].find(file => fs.existsSync(file));
if (archive) {
    fs.writeFileSync(path.join(scratch, 'libs.tar'), zlib.brotliDecompressSync(fs.readFileSync(archive)));
    execFileSync('tar', ['-xf', path.join(scratch, 'libs.tar'), '-C', scratch]);
    process.env.LD_LIBRARY_PATH = path.join(scratch, 'lib') + ':' + (process.env.LD_LIBRARY_PATH || '');
}
function data(agency = 'ag_public') {
    const property = {id:'p_fixture',agencyId:agency,status:'موجود',city:'سمنان',location:'محله آزمایشی',usage:'مسکونی',
        area:100,rooms:'2',floor:'1',unit:'1',yearBuilt:'1400',dealType:'فروش',price:5000000000,deposit:0,rent:0,
        description:'فایل آزمایشی',authorName:'مدیر',referrer:'مالک آزمایشی',date:'2026-09-07T12:00:00Z',images:[],showToGuest:true,showPriceGuest:true,showImagesGuest:true};
    return {response:{agencies:{[agency]:{id:agency,name:'آژانس آزمایشی',phone:'02100000000',city:'سمنان',expireAt:'2099-12-31',plan_type:'vip'}},
        properties:{p_fixture:property},demands:{},members:{},dataHash:'fixture-v1'}};
}
(async () => {
    const browser = await puppeteer.launch({args: chromium.args.filter(arg => arg !== '--single-process'), executablePath: await chromium.executablePath(), headless:true});
    let passed = 0;
    async function pageFor({html=latest,profile=null,guest=null,brokenStorage=false,apiStatus=200,holdApi=false,holdOptional=true}={}) {
        const context = await browser.createBrowserContext();
        const page = await context.newPage();
        await page.setViewport({width:1280,height:850});
        const requests=[],errors=[];
        page.on('pageerror', error=>errors.push(error.message));
        await page.evaluateOnNewDocument((profile,guest,brokenStorage)=>{
            // Tests do not register a real service worker on the fixture origin.
            if(navigator.serviceWorker) navigator.serviceWorker.register=async()=>({});
            if(profile) localStorage.setItem('amlakProfile',typeof profile==='string'?profile:JSON.stringify(profile));
            if(guest) sessionStorage.setItem('guestProfile',JSON.stringify(guest));
            if(brokenStorage) {
                Storage.prototype.setItem=function(){throw new DOMException('full','QuotaExceededError');};
                Storage.prototype.getItem=function(){throw new DOMException('blocked','SecurityError');};
            }
        },profile,guest,brokenStorage);
        await page.setRequestInterception(true);
        page.on('request',request=>{
            const url = new URL(request.url()); requests.push({url:request.url(),method:request.method()});
            if(url.host==='static.neshan.org') return; // Deliberately hangs, like an unavailable SDK/CDN.
            if(url.pathname==='/index.html') return request.respond({status:200,contentType:'text/html',body:html});
            if(url.pathname.startsWith('/api')) {
                if(holdApi) return;
                const id=request.headers()['x-agency-id'];
                const action=url.searchParams.get('action');
                const body=apiStatus!==200?{error:'خطای داخلی آزمایشی سرور'}:(action==='loginManager'?{response:{success:true,token:'fresh-fixture-token',managerName:'مدیر',agencyName:'آژانس آزمایشی',plan:'vip'}}:data(id==='100001'?'100001':'ag_public'));
                return request.respond({status:apiStatus,contentType:'application/json',headers:{'X-Amlak-Recovery':'fix29-baseline26'},body:JSON.stringify(body)});
            }
            if(holdOptional && /chart\.umd|jalalidatepicker/.test(url.pathname)) return;
            const rel=decodeURIComponent(url.pathname).replace(/^\//,'');
            const file=path.join(ROOT,'src',rel);
            if(!file.startsWith(path.join(ROOT,'src')+path.sep)||!fs.existsSync(file)||!fs.statSync(file).isFile()) return request.respond({status:404,body:''});
            const types={'.js':'application/javascript','.css':'text/css','.woff2':'font/woff2','.png':'image/png','.json':'application/json'};
            request.respond({status:200,contentType:types[path.extname(file)]||'application/octet-stream',body:fs.readFileSync(file)}).catch(()=>{});
        });
        return {page,context,requests,errors};
    }
    const visible = (page,id) => page.evaluate(id=>{const e=document.getElementById(id);return !!e&&getComputedStyle(e).display!=='none'&&e.getBoundingClientRect().height>0;},id);
    function ok(name){passed++;console.log('OK '+name);}
    try {
        let p=await pageFor({html:baseline});
        await p.page.goto('https://recovery.test/index.html',{waitUntil:'domcontentloaded',timeout:1800}).catch(()=>{});
        assert.equal(await p.page.evaluate(()=>typeof window.boot),'undefined');
        assert.equal(await visible(p.page,'landing-view'),false);
        assert.ok(p.requests.some(r=>r.url.includes('static.neshan.org')));
        ok('reproduced: the approved old HTML stalls before boot when Neshan is unavailable');
        await p.context.close();

        p=await pageFor();
        await p.page.goto('https://recovery.test/index.html?api.php=29',{waitUntil:'domcontentloaded',timeout:5000});
        await p.page.waitForFunction(()=>{const e=document.getElementById('landing-view');return e&&!e.classList.contains('hidden');});
        assert.equal(await visible(p.page,'landing-view'),true);
        assert.equal(p.requests.filter(r=>r.url.includes('static.neshan.org')).length,0);
        assert.equal(await p.page.$eval('meta[name="amlak-build"]',e=>e.content),'fix29-baseline26');
        assert.deepEqual(p.errors,[]);
        ok('fresh page boots in real Chromium even with every optional SDK/chart/calendar resource stalled');
        const imageDir=path.join(os.homedir(),'.cache/amlak-recovery29');fs.mkdirSync(imageDir,{recursive:true});
        await p.page.screenshot({path:path.join(imageDir,'recovered-landing.png')});
        await p.context.close();

        p=await pageFor({guest:{name:'مهمان',role:'مهمان',agencyId:'guest',agencyName:'جستجو'}});
        await p.page.goto('https://recovery.test/index.html?api.php=29',{waitUntil:'domcontentloaded'});
        await p.page.waitForSelector('#guest-search-container .property-card',{timeout:5000});
        assert.equal(await visible(p.page,'main-app'),true);assert.deepEqual(p.errors,[]);
        assert.ok(p.requests.some(r=>r.url.includes('/api-recovery-29.php?action=getData')));
        ok('existing guest session loads handlers before boot and uses the fresh approved API alias');
        await p.context.close();

        p=await pageFor({profile:'{broken-json'});
        await p.page.goto('https://recovery.test/index.html?api.php=29',{waitUntil:'domcontentloaded'});
        await p.page.waitForFunction(()=>!document.getElementById('landing-view').classList.contains('hidden'));
        assert.equal(await p.page.evaluate(()=>localStorage.getItem('amlakProfile')),'{broken-json');
        assert.match(await p.page.$eval('#recovery29-log',e=>e.textContent),/نامعتبر/);
        assert.deepEqual(p.errors,[]);ok('malformed stored profile does not blank the page or erase stored data');
        await p.context.close();

        p=await pageFor({brokenStorage:true});
        await p.page.goto('https://recovery.test/index.html?api.php=29',{waitUntil:'domcontentloaded'});
        await p.page.waitForFunction(()=>!document.getElementById('landing-view').classList.contains('hidden'));
        assert.equal(await visible(p.page,'landing-view'),true);assert.deepEqual(p.errors,[]);
        ok('blocked browser storage cannot prevent the initial screen');
        await p.context.close();

        const manager={name:'مدیر',role:'مدیر',agencyId:'100001',agencyName:'آژانس آزمایشی',token:'fixture-token',plan:'vip'};
        p=await pageFor({profile:manager});
        await p.page.goto('https://recovery.test/index.html?api.php=29',{waitUntil:'domcontentloaded'});
        await p.page.waitForSelector('#properties-container .property-card',{timeout:5000});
        assert.equal(await visible(p.page,'main-app'),true);assert.deepEqual(p.errors,[]);
        assert.equal(p.requests.filter(r=>r.url.includes('getGuestProperties')).length,0);
        ok('manager properties render even when chart scripts never respond; no fix27 guest endpoint is called');
        await p.context.close();

        p=await pageFor({holdOptional:false});
        await p.page.goto('https://recovery.test/index.html?api.php=29',{waitUntil:'domcontentloaded'});
        await p.page.waitForFunction(()=>typeof window.handleLogin==='function');
        await p.page.evaluate(()=>{window.goToAuth();window.switchAuthTab('manager');});
        await p.page.type('#manager-code','100001');await p.page.type('#manager-pin','fixture');
        await p.page.$eval('#form-login-manager',form=>form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
        await p.page.waitForSelector('#properties-container .property-card',{timeout:5000});
        assert.equal(await visible(p.page,'main-app'),true);assert.deepEqual(p.errors,[]);
        ok('fresh manager login succeeds through the new API path and reaches own files');
        await p.page.waitForFunction(()=>typeof Chart!=='undefined'&&typeof jalaliDatepicker!=='undefined',{timeout:5000});
        await p.page.evaluate(()=>window.switchTab('dashboard'));
        assert.equal(await visible(p.page,'dashboard-view'),true);assert.deepEqual(p.errors,[]);
        ok('when optional local libraries are available, dashboard and calendar initialize normally');
        await p.context.close();

        p=await pageFor({profile:manager,apiStatus:500});
        await p.page.goto('https://recovery.test/index.html?api.php=29',{waitUntil:'domcontentloaded'});
        await p.page.waitForFunction(()=>document.getElementById('recovery29-log').textContent.includes('HTTP 500'));
        assert.equal(await visible(p.page,'auth-view'),true);
        ok('server HTTP 500 becomes an on-page diagnostic and a usable auth view, not a blank page');
        await p.context.close();

        p=await pageFor({profile:manager,holdApi:true});
        await p.page.goto('https://recovery.test/index.html?api.php=29',{waitUntil:'domcontentloaded'});
        await p.page.waitForFunction(()=>document.getElementById('recovery29-log').textContent.includes('۱۵ ثانیه'),{timeout:18000});
        assert.equal(await visible(p.page,'auth-view'),true);
        ok('a stalled server request times out with a visible diagnostic');
        await p.context.close();

        console.log(passed+' real-browser checks; 0 failures. Production host not contacted.');
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
