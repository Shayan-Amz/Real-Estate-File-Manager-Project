// Integration of the real HTML/inline handlers/GuestPager with a fixture HTTP API.
// No network, production credentials, or host data are used.
// Needs jsdom 26.1.0; see tests/README.md.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { JSDOM, VirtualConsole } = require('jsdom');
const root = path.resolve(__dirname, '..');
const html = fs.readFileSync(path.join(root, 'src/index.html'), 'utf8');
const guestScript = fs.readFileSync(path.join(root, 'src/assets/guest-browser.js'), 'utf8');
const main = [...html.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)].map(match => match[1]).find(text => text.includes("const API_URL = 'api.php'"));
const agencyId = 'ag_' + 'a'.repeat(32);
const tick = () => new Promise(resolve => setImmediate(resolve));
function property(id) {
    return { id, agencyId, status: 'موجود', authorName: 'مشاور', city: 'تهران', location: 'مرکز', usage: 'مسکونی',
        area: 100, buildArea: 90, rooms: '2', floor: '1', unit: '2', yearBuilt: '1400', dealType: 'فروش',
        description: 'توضیحات عمومی', date: '2026-09-07T12:00:00Z', isVIP: false, showToGuest: true,
        showPriceGuest: true, showImagesGuest: true, price: 1000, deposit: 0, rent: 0, images: ['uploads/' + id + '.jpg'] };
}
function agency() {
    return { id: agencyId, name: 'املاک آزمایشی', city: 'تهران', phone: '02100000000', phone2: '02100000001', plan_type: 'vip', selector: 'g1.fixture-selector', publicCount: 57 };
}
function fixtureResponse(url, options) {
    const action = url.searchParams.get('action');
    if (action === 'ping') return { response: { success: true } };
    if (action === 'getData') {
        return { response: { agencies: { '100001': { ...agency(), id: '100001', managerName: 'مدیر', expireAt: '2099-12-31' } },
            properties: { own_property: { ...property('own_property'), agencyId: '100001', phone: 'PRIVATE_OWNER', referrer: 'مالک' } },
            demands: {}, members: {}, dataHash: 'manager-version' } };
    }
    const next = !!url.searchParams.get('cursor');
    const data = { guestApiVersion: 1, properties: {}, agencies: {},
        pagination: { pageSize: 20, hasMore: !next, nextCursor: next ? null : 'g1.fixture-next' }, dataHash: (next ? 'b' : 'a').repeat(64) };
    if (action === 'getGuestAgencies') {
        data.agencies = { [agencyId]: agency() };
    } else if (action === 'getGuestProperties') {
        const count = next ? 3 : 20;
        for (let i = 0; i < count; i++) {
            const id = (url.searchParams.get('agency') ? 'selected_' : '') + (next ? 'next_' : 'first_') + i;
            data.properties[id] = property(id);
        }
        const { selector, publicCount, ...metadata } = agency();
        data.agencies = { [agencyId]: metadata };
    } else throw new Error('Unexpected API action: ' + action);
    return { response: data };
}
async function browser({ restoredGuest = false, manager = false, respond = fixtureResponse, cached = null } = {}) {
    const errors = [], calls = [];
    const console = new VirtualConsole();
    console.on('jsdomError', error => errors.push(error));
    const dom = new JSDOM(html, { url: 'https://preview.example.e2b.app/', runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole: console });
    const w = dom.window;
    w.scrollTo = () => {};
    w.HTMLElement.prototype.scrollIntoView = () => {};
    w.alert = () => {};
    w.setInterval = () => 1; w.clearInterval = () => {};
    w.jalaliDatepicker = { startWatch() {} };
    w.Chart = class { constructor() {} destroy() {} update() {} };
    w.HTMLCanvasElement.prototype.getContext = () => ({});
    w.IntersectionObserver = class { observe() {} unobserve() {} disconnect() {} };
    w.addEventListener('error', event => errors.push(event.error || event.message));
    w.fetch = async (input, options = {}) => {
        const url = new URL(input, w.location.href); calls.push({ url, options });
        const body = await respond(url, options);
        return { ok: !body.error, status: body.error ? 503 : 200, json: async () => body, text: async () => JSON.stringify(body) };
    };
    if (restoredGuest) w.sessionStorage.setItem('guestProfile', JSON.stringify({ name: 'کاربر', role: 'مهمان', agencyId: 'GUEST_USER', agencyName: 'جستجوی سراسری' }));
    if (manager) w.localStorage.setItem('amlakProfile', JSON.stringify({ name: 'مدیر', role: 'مدیر', agencyId: '100001', agencyName: 'املاک مدیر', token: 'test-signed-token', plan: 'vip' }));
    if (cached) w.localStorage.setItem('amlakDataCache', JSON.stringify(cached));
    new vm.Script(guestScript).runInContext(dom.getInternalVMContext());
    new vm.Script(main).runInContext(dom.getInternalVMContext());
    w.eval('guestPager.delay = 0');
    await tick(); await tick();
    return { dom, w, errors, calls, async settle() { await tick(); await tick(); }, cleanup() { w.eval('guestPager.suspend()'); dom.window.close(); } };
}

test('fresh guest login loads only one bounded public page, with gallery/details/agency phones working', async t => {
    const b = await browser(); t.after(() => b.cleanup());
    await b.w.handleGuestLogin(); await b.settle();
    assert.deepEqual(b.calls.map(call => call.url.searchParams.get('action')), ['getGuestProperties']);
    assert.equal(b.calls[0].options.method, 'GET');
    assert.equal(b.calls[0].url.origin, 'https://preview.example.e2b.app');
    assert.ok(!('X-Auth-Token' in b.calls[0].options.headers));
    assert.equal(b.w.document.querySelectorAll('#guest-search-container .property-card').length, 20);
    assert.equal(b.w.document.getElementById('btn-prev-guest-all').disabled, true);
    assert.equal(b.w.document.getElementById('btn-next-guest-all').disabled, false);
    assert.ok(b.w.document.querySelector('#guest-search-container a[href="tel:02100000000"]'));
    b.w.openGallery('first_0', 0, true);
    assert.ok(b.w.document.getElementById('gallery-main-img').src.endsWith('/uploads/first_0.jpg'));
    b.w.closeGallery(); b.w.toggleViewMode(); b.w.openPropertyModal('first_0', 'guest');
    assert.ok(b.w.document.getElementById('property-detail-content').textContent.includes('املاک آزمایشی'));
    assert.deepEqual(b.errors, []);
});

test('restored guest session boots after handlers exist and never requests legacy getData', async t => {
    const b = await browser({ restoredGuest: true }); t.after(() => b.cleanup());
    assert.equal(b.w.document.getElementById('main-app').classList.contains('hidden'), false);
    assert.equal(b.calls.length, 1); assert.equal(b.calls[0].url.searchParams.get('action'), 'getGuestProperties');
    assert.equal(b.w.document.querySelectorAll('#guest-search-container .property-card').length, 20);
    assert.deepEqual(b.errors, []);
});

test('real next/previous/first handlers update the current page without leaking old page models', async t => {
    const b = await browser({ restoredGuest: true }); t.after(() => b.cleanup());
    b.w.changePage('guestAll', 1); await b.settle();
    assert.equal(b.w.document.getElementById('page-info-guest-all').textContent, 'صفحه 2');
    assert.equal(b.w.document.querySelectorAll('#guest-search-container .property-card').length, 3);
    assert.equal(b.w.document.getElementById('btn-next-guest-all').disabled, true);
    assert.equal(b.w.eval('allGuestProperties.length'), 3);
    b.w.changePage('guestAll', -1); await b.settle();
    assert.equal(b.w.document.getElementById('page-info-guest-all').textContent, 'صفحه 1');
    assert.equal(b.w.document.querySelectorAll('#guest-search-container .property-card').length, 20);
    b.w.changePage('guestAll', 1); await b.settle(); b.w.guestFirstPage('guestAll'); await b.settle();
    assert.equal(b.w.document.getElementById('page-info-guest-all').textContent, 'صفحه 1');
    assert.deepEqual(b.errors, []);
});

test('directory count is server-provided; choosing an agency sends its selector, not raw ID', async t => {
    const b = await browser({ restoredGuest: true }); t.after(() => b.cleanup());
    b.w.switchTab('guest-agencies'); await b.settle();
    assert.equal(b.calls.at(-1).url.searchParams.get('action'), 'getGuestAgencies');
    assert.match(b.w.document.getElementById('guest-agencies-container').textContent, /57 فایل موجود/);
    b.w.document.querySelector('[data-guest-open]').click(); await b.settle();
    assert.equal(b.calls.at(-1).url.searchParams.get('agency'), 'g1.fixture-selector');
    assert.equal(b.calls.at(-1).url.searchParams.get('cursor'), '');
    assert.equal(b.w.document.querySelectorAll('#guest-properties-container .property-card').length, 20);
    assert.ok(b.w.document.getElementById('guest-selected-agency-name').textContent.includes('املاک آزمایشی'));
    b.w.changePage('guestAgency', 1); await b.settle();
    b.w.switchTab('guest-agencies'); await b.settle();
    assert.match(b.w.document.getElementById('guest-agencies-container').textContent, /57 فایل موجود/);
    b.w.document.querySelector('[data-guest-open]').click(); await b.settle();
    assert.equal(b.calls.at(-1).url.searchParams.get('cursor'), '');
    assert.equal(b.w.document.getElementById('page-info-guest-agency').textContent, 'صفحه 1');
    assert.ok(b.w.eval('allGuestProperties.length') <= 40); assert.ok(b.w.eval('allAgencies.length') <= 60);
    assert.deepEqual(b.errors, []);
});

test('typing/filter changes reset the page and send every existing guest filter to the API', async t => {
    const b = await browser({ restoredGuest: true }); t.after(() => b.cleanup());
    b.w.changePage('guestAll', 1); await b.settle();
    const fields = { 'search-guest-all': 'کیان', 'filter-guest-usage': 'مسکونی', 'filter-guest-deal': 'رهن و اجاره',
        'filter-guest-min-area': '80', 'filter-guest-max-area': '150', 'filter-guest-min-price': '1,000',
        'filter-guest-max-price': '2,000', 'filter-guest-min-rent': '20', 'filter-guest-max-rent': '50' };
    for (const [id, value] of Object.entries(fields)) b.w.document.getElementById(id).value = value;
    b.w.renderGuestAllProperties(); await new Promise(resolve => setTimeout(resolve, 10)); await b.settle();
    const query = b.calls.at(-1).url.searchParams;
    assert.deepEqual(Object.fromEntries(query), { action: 'getGuestProperties', q: 'کیان', usage: 'مسکونی', deal: 'رهن و اجاره',
        minArea: '80', maxArea: '150', minPrice: '1,000', maxPrice: '2,000', minRent: '20', maxRent: '50', cursor: '' });
    b.w.document.getElementById('filter-guest-deal').value = 'فروش';
    b.w.toggleGuestRentFilters(); b.w.renderGuestAllProperties();
    await new Promise(resolve => setTimeout(resolve, 10)); await b.settle();
    assert.equal(b.calls.at(-1).url.searchParams.get('minRent'), '');
    assert.equal(b.calls.at(-1).url.searchParams.get('maxRent'), ''); // Hidden stale rent fields must not filter sale results.
    assert.equal(b.w.document.getElementById('page-info-guest-all').textContent, 'صفحه 1');
    assert.deepEqual(b.errors, []);
});

test('offline guest error never displays the legacy shared/private localStorage cache', async t => {
    const b = await browser({ restoredGuest: true, respond: async () => { throw new Error('offline'); },
        cached: { properties: { secret: { ...property('secret'), description: 'PRIVATE_CACHED_OWNER' } }, agencies: {} } });
    t.after(() => b.cleanup());
    assert.equal(b.w.document.querySelectorAll('#guest-search-container .property-card').length, 0);
    assert.ok(b.w.document.getElementById('status-guest-all').textContent.includes('offline'));
    assert.ok(!b.w.document.getElementById('guest-search-container').textContent.includes('PRIVATE_CACHED_OWNER'));
    assert.equal(b.w.eval('allGuestProperties.length'), 0);
    assert.deepEqual(b.errors, []);
});

test('untrusted directory names are text and never interpolated into event-handler code', async t => {
    const malicious = "O&#039;Brien &lt;img src=x onerror=alert(1)&gt;";
    const b = await browser({ restoredGuest: true, respond(url, options) {
        const result = fixtureResponse(url, options);
        if (url.searchParams.get('action') === 'getGuestAgencies') result.response.agencies[agencyId].name = malicious;
        return result;
    } });
    t.after(() => b.cleanup()); b.w.switchTab('guest-agencies'); await b.settle();
    const container = b.w.document.getElementById('guest-agencies-container');
    assert.equal(container.querySelectorAll('img').length, 0);
    assert.ok(container.textContent.includes("O'Brien <img src=x onerror=alert(1)>"));
    assert.equal(container.querySelector('[data-guest-open]').getAttribute('onclick'), null);
    container.querySelector('[data-guest-open]').click(); await b.settle();
    assert.ok(b.w.document.getElementById('guest-selected-agency-name').textContent.includes("O'Brien"));
    assert.deepEqual(b.errors, []);
});

test('manager boot, private cards, and getData stay on the existing authenticated path', async t => {
    const b = await browser({ manager: true }); t.after(() => b.cleanup());
    assert.ok(b.calls.some(call => call.url.searchParams.get('action') === 'getData'));
    assert.ok(!b.calls.some(call => call.url.searchParams.get('action').startsWith('getGuest')));
    assert.ok(b.calls.filter(call => call.url.searchParams.get('action') === 'getData').every(call => call.options.headers['X-Auth-Token'] === 'test-signed-token'));
    assert.ok(b.w.document.getElementById('properties-container').textContent.includes('مالک'));
    assert.deepEqual(b.errors, []);
});

function worker() {
    const handlers = {}, fetches = [], additions = [];
    const context = {
        self: { location: { origin: 'https://preview.example.e2b.app' }, skipWaiting() {}, clients: { claim() {} }, addEventListener(name, fn) { handlers[name] = fn; } },
        Request: class { constructor(url, options) { this.url = new URL(url, 'https://preview.example.e2b.app/').href; this.cache = options.cache; } },
        caches: { async open() { return { async addAll(requests) { additions.push(...requests); } }; }, async match() { return 'cached-shell'; } },
        async fetch(request, options) { fetches.push({ request, options }); return 'network-response'; }
    };
    vm.runInNewContext(fs.readFileSync(path.join(root, 'src/sw.js'), 'utf8'), context);
    return { handlers, fetches, additions };
}

test('service worker installation reloads the matching HTML and versioned guest script', async () => {
    const w = worker(); let pending;
    w.handlers.install({ waitUntil(value) { pending = value; } }); await pending;
    assert.equal(w.additions.length, 3);
    assert.ok(w.additions.every(request => request.cache === 'reload'));
    assert.ok(w.additions.some(request => request.url.endsWith('/assets/guest-browser.js?v=fix27')));
    assert.ok(html.includes('assets/guest-browser.js?v=fix27'));
});

test('service worker revalidates navigations but still never caches API responses', async () => {
    const w = worker(); let pending;
    w.handlers.fetch({ request: { url: 'https://preview.example.e2b.app/', mode: 'navigate' }, respondWith(value) { pending = value; } });
    assert.equal(await pending, 'network-response'); assert.equal(w.fetches[0].options.cache, 'no-cache');
    for (const action of ['getData', 'getGuestProperties', 'getGuestAgencies']) {
        w.handlers.fetch({ request: { url: 'https://preview.example.e2b.app/api.php?action=' + action, mode: 'cors' }, respondWith() { assert.fail('API must bypass service worker'); } });
    }
    assert.equal(w.fetches.length, 1);
});
