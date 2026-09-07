// Pure Node tests for debounce, request races, cursors, offline errors, and bounded state.
// Run: node --test tests/guest_pager.test.cjs
const test = require('node:test');
const assert = require('node:assert/strict');
const GuestPager = require('../src/assets/guest-browser.js');

function page(id = 'one', more = true) {
    return {
        guestApiVersion: 1, properties: { [id]: { id, agencyId: 'ag_public' } },
        agencies: { ag_public: { id: 'ag_public', name: 'آژانس' } },
        pagination: { pageSize: 20, hasMore: more, nextCursor: more ? 'after-' + id : null },
        dataHash: id === 'one' ? 'a'.repeat(64) : 'b'.repeat(64)
    };
}
function deferred() {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
}
const wait = ms => new Promise(resolve => setTimeout(resolve, ms));
function fixture(implementation = async () => page(), extra = {}) {
    const env = { enabled: true, active: 'guestAll', filters: { guestAll: { q: '' }, guestAgencies: { q: '' }, guestAgency: { agency: 'opaque-agency' } }, calls: [], renders: [], now: 1000 };
    const pager = new GuestPager({
        enabled: () => env.enabled, isActive: view => view === env.active,
        filters: view => env.filters[view],
        fetchPage: (...args) => { env.calls.push(args); return implementation(...args); },
        render: (...args) => env.renders.push(args), delay: 15, timeout: 1000, now: () => env.now, ...extra
    });
    return { env, pager };
}

test('only the visible guest view requests a page; manager calls do nothing', async () => {
    const { env, pager } = fixture();
    pager.show('guestAgencies'); assert.equal(env.calls.length, 0);
    await pager.show('guestAll'); assert.equal(env.calls.length, 1);
    assert.deepEqual(env.calls[0].slice(0, 3), ['guestAll', { q: '', cursor: '' }, '']);
    assert.equal(pager.state('guestAll').index, 0);
    env.enabled = false; pager.show('guestAll', { refresh: true }); assert.equal(env.calls.length, 1);
    pager.suspend();
});

test('next/previous/first use cursor history and never reuse the hash of another page', async () => {
    const { env, pager } = fixture(async (_, params) => params.cursor ? page('two', false) : page());
    await pager.show('guestAll'); await pager.move('guestAll', 1);
    assert.equal(pager.state('guestAll').index, 1);
    assert.equal(env.calls[1][1].cursor, 'after-one'); assert.equal(env.calls[1][2], '');
    await pager.move('guestAll', -1);
    assert.equal(pager.state('guestAll').index, 0); assert.equal(env.calls[2][1].cursor, '');
    await pager.move('guestAll', 1); await pager.first('guestAll');
    assert.equal(pager.state('guestAll').index, 0);
    assert.deepEqual(pager.state('guestAll').cursors, ['']);
});

test('failed Next preserves the old page/controls; Retry retries the failed destination', async () => {
    let fail = true;
    const { env, pager } = fixture(async (_, params) => {
        if (!params.cursor) return page();
        if (fail) throw new Error('offline');
        return page('two', false);
    });
    await pager.show('guestAll'); await pager.move('guestAll', 1);
    const state = pager.state('guestAll');
    assert.equal(state.index, 0); assert.ok(state.data.properties.one); assert.equal(state.error, 'offline');
    assert.equal(env.renders.at(-1)[1].page, 1);
    fail = false; await pager.retry('guestAll');
    assert.equal(state.index, 1); assert.ok(state.data.properties.two); assert.equal(state.error, null);
});

test('an old response arriving during debounce cannot replace the new search', async () => {
    const old = deferred();
    const { env, pager } = fixture(async (_, params) => params.q ? page(params.q, false) : old.promise);
    const oldRequest = pager.show('guestAll');
    env.filters.guestAll = { q: 'تهران' }; pager.show('guestAll');
    assert.equal(env.calls[0][3].aborted, true);
    old.resolve(page('stale')); await oldRequest;
    assert.equal(pager.state('guestAll').data, null);
    assert.ok(!env.renders.some(([, state]) => state.data?.properties.stale));
    await wait(40);
    assert.equal(env.calls.length, 2); assert.ok(pager.state('guestAll').data.properties['تهران']);
});

test('typing burst is debounced to one request with the final filter and no old cursor', async () => {
    const { env, pager } = fixture(async (_, params) => page(params.q || 'one'));
    await pager.show('guestAll'); await pager.move('guestAll', 1);
    const before = env.calls.length;
    for (const q of ['م', 'مش', 'مشهد']) { env.filters.guestAll = { q }; pager.show('guestAll'); }
    assert.equal(pager.state('guestAll').data, null);
    await wait(40);
    assert.equal(env.calls.length, before + 1);
    assert.deepEqual(env.calls.at(-1).slice(1, 3), [{ q: 'مشهد', cursor: '' }, '']);
    assert.equal(pager.state('guestAll').index, 0);
});

test('double Next and overlapping refreshes cannot start duplicate in-flight requests', async () => {
    const next = deferred();
    const { env, pager } = fixture(async (_, params) => params.cursor ? next.promise : page());
    await pager.show('guestAll'); const request = pager.move('guestAll', 1);
    pager.move('guestAll', 1); pager.show('guestAll', { refresh: true });
    assert.equal(env.calls.length, 2); assert.equal(pager.state('guestAll').index, 0);
    next.resolve(page('two')); await request;
    assert.equal(pager.state('guestAll').index, 1);
});

test('switching views aborts the old request, and a late hidden-view response is ignored', async () => {
    const old = deferred();
    const { env, pager } = fixture(async view => {
        if (view === 'guestAll') return old.promise;
        const result = page(); result.properties = {}; return result;
    });
    const request = pager.show('guestAll');
    env.active = 'guestAgencies'; await pager.show('guestAgencies');
    old.resolve(page('late')); await request;
    assert.equal(env.calls[0][3].aborted, true);
    assert.equal(pager.state('guestAll').data, null);
    assert.ok(pager.state('guestAgencies').data);
    assert.equal(env.renders.at(-1)[0], 'guestAgencies');
});

test('end of results blocks Next; a deleted empty later page still permits Previous', async () => {
    let emptyLaterPage = false;
    const { env, pager } = fixture(async (_, params) => {
        if (!params.cursor) return page();
        const result = page('two', false); if (emptyLaterPage) result.properties = {}; return result;
    });
    await pager.show('guestAll'); await pager.move('guestAll', 1);
    const count = env.calls.length; await pager.move('guestAll', 1); assert.equal(env.calls.length, count);
    emptyLaterPage = true; await pager.show('guestAll', { refresh: true });
    assert.equal(env.renders.at(-1)[1].canPrev, true); assert.equal(env.renders.at(-1)[1].canNext, false);
    await pager.move('guestAll', -1); assert.equal(pager.state('guestAll').index, 0);
});

test('unmodified only works for the exact page already cached', async () => {
    const { env, pager } = fixture(async (_, params, hash) => hash ? { guestApiVersion: 1, unmodified: true } : page());
    await pager.show('guestAll'); const original = pager.state('guestAll').data;
    await pager.show('guestAll', { refresh: true });
    assert.equal(env.calls[1][2], original.dataHash); assert.equal(pager.state('guestAll').data, original);
    const bad = fixture(async () => ({ guestApiVersion: 1, unmodified: true }));
    await bad.pager.show('guestAll'); assert.equal(bad.pager.state('guestAll').data, null);
    assert.ok(bad.pager.state('guestAll').error);
});

test('old/full-dump or malformed responses are errors, never local-filter fallbacks', async () => {
    for (const payload of [{ properties: {} }, { ...page(), pagination: null }, { ...page(), properties: Object.fromEntries(Array.from({ length: 21 }, (_, i) => [i, { id: i }])) }]) {
        const { pager } = fixture(async () => payload);
        await pager.show('guestAll'); assert.equal(pager.state('guestAll').data, null);
        assert.ok(pager.state('guestAll').error);
    }
});

test('automatic refresh backs off after errors; manual Retry can recover immediately', async () => {
    let online = false;
    const { env, pager } = fixture(async () => { if (!online) throw new Error('offline'); return page(); });
    await pager.show('guestAll'); assert.equal(env.calls.length, 1);
    await pager.show('guestAll', { refresh: true }); assert.equal(env.calls.length, 1);
    env.now += 15000; await pager.show('guestAll', { refresh: true }); assert.equal(env.calls.length, 2);
    env.now += 15000; await pager.show('guestAll', { refresh: true }); assert.equal(env.calls.length, 2);
    online = true; await pager.retry('guestAll'); assert.equal(env.calls.length, 3);
    assert.equal(pager.state('guestAll').error, null);
});

test('request timeout leaves a usable retry instead of a permanently pending page', async () => {
    const { pager } = fixture((_, params, hash, signal) => new Promise((resolve, reject) => {
        signal.addEventListener('abort', () => reject(new Error('aborted')), { once: true });
    }), { timeout: 15 });
    await pager.show('guestAll');
    assert.equal(pager.state('guestAll').pending, false);
    assert.match(pager.state('guestAll').error, /طول کشید/);
});

test('changing selected agency resets its cursor independently of global search filters', async () => {
    const { env, pager } = fixture(async (_, params) => page(params.agency || 'one'));
    await pager.show('guestAll'); env.active = 'guestAgency'; await pager.show('guestAgency');
    await pager.move('guestAgency', 1); env.filters.guestAgency = { agency: 'another-opaque-agency' };
    pager.show('guestAgency'); await wait(40);
    assert.deepEqual(env.calls.at(-1).slice(1, 3), [{ agency: 'another-opaque-agency', cursor: '' }, '']);
    assert.equal(pager.state('guestAgency').index, 0);
    assert.ok(pager.state('guestAll').data.properties.one);
});

test('many pages retain only the current page data, not every visited property', async () => {
    let id = 0;
    const { pager } = fixture(async () => page(String(++id)));
    await pager.show('guestAll');
    for (let i = 0; i < 100; i++) await pager.move('guestAll', 1);
    assert.equal(pager.state('guestAll').index, 100);
    assert.deepEqual(Object.keys(pager.state('guestAll').data.properties), ['101']);
    assert.equal(pager.state('guestAll').cursors.length, 101); // Only small encrypted cursors retained.
});
