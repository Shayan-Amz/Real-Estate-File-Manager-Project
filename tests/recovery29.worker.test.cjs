const test=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
function worker(fail=false){
 const handlers={},removed=[],requests=[];
 const context={self:{location:{origin:'https://app.test'},clients:{async claim(){}},async skipWaiting(){},addEventListener(name,fn){handlers[name]=fn;}},
  caches:{async keys(){return['amlak-safe-cache-v2','amlak-safe-cache-fix27','another-app-cache'];},async delete(name){removed.push(name);return true;}},
  async fetch(request,options){requests.push({request,options});if(fail)throw new Error('offline');return new Response('ok');},Response,URL};
 vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../src/sw.js'),'utf8'),context);
 return{handlers,removed,requests};
}
test('activation clears only obsolete amlak shell caches, not another app cache',async()=>{const w=worker();let p;w.handlers.activate({waitUntil(x){p=x;}});await p;assert.deepEqual(w.removed,['amlak-safe-cache-v2','amlak-safe-cache-fix27']);});
test('old and fresh API paths, and recovery query, bypass the worker',()=>{const w=worker();for(const url of ['https://app.test/api.php?action=getData','https://app.test/api-recovery-29.php?action=getData','https://app.test/recover29.html?api.php=29'])w.handlers.fetch({request:{url,method:'GET',mode:'navigate'},respondWith(){assert.fail('must bypass');}});assert.equal(w.requests.length,0);});
test('new HTML and JS revalidate, while image HTTP caching is retained',async()=>{const w=worker();for(const [destination,cache] of [['document','no-store'],['script','no-store'],['image',undefined]]){let p;w.handlers.fetch({request:{url:'https://app.test/file',method:'GET',mode:'cors',destination},respondWith(x){p=x;}});await p;assert.equal(w.requests.at(-1).options?.cache,cache);}});
test('a failed script is never replaced by cached HTML',async()=>{const w=worker(true);let p;w.handlers.fetch({request:{url:'https://app.test/assets/chart.js',method:'GET',mode:'cors',destination:'script'},respondWith(x){p=x;}});assert.equal((await p).type,'error');});
test('navigation network failure has a visible diagnostic, not a stale blank shell',async()=>{const w=worker(true);let p;w.handlers.fetch({request:{url:'https://app.test/',method:'GET',mode:'navigate',destination:'document'},respondWith(x){p=x;}});const r=await p;assert.equal(r.status,503);assert.match(await r.text(),/ارتباط با هاست برقرار نشد/);});
