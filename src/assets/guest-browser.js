/* fix27 — bounded guest pages; no full-dataset/offline-cache fallback. */
(function (root) {
    'use strict';

    class GuestPager {
        constructor(options) {
            this.options = options;
            this.states = {};
            this.active = null;
            this.delay = options.delay ?? 300;
            this.timeout = options.timeout ?? 20000;
            this.now = options.now || Date.now;
        }

        state(view) {
            if (!this.states[view]) {
                this.states[view] = {
                    filterKey: null, filters: {}, cursors: [''], index: 0,
                    data: null, cacheKey: null, sequence: 0, pending: false,
                    timer: null, abort: null, error: null, failedRequest: null, errors: 0, retryAt: 0
                };
            }
            return this.states[view];
        }

        allowed(view) {
            return this.options.enabled() && this.options.isActive(view);
        }

        cancel(state) {
            state.sequence++;
            if (state.timer !== null) clearTimeout(state.timer);
            state.timer = null;
            if (state.abort) state.abort.abort();
            state.abort = null;
            state.pending = false;
        }

        reset(view) {
            if (this.states[view]) this.cancel(this.states[view]);
            delete this.states[view];
        }

        suspend() {
            Object.values(this.states).forEach(state => this.cancel(state));
            this.active = null;
        }

        notify(view) {
            if (!this.allowed(view)) return;
            const state = this.state(view);
            const snapshots = Object.fromEntries(Object.entries(this.states).map(([key, value]) => [key, value.data]));
            this.options.render(view, {
                data: state.data, page: state.index + 1,
                loading: state.pending || state.timer !== null, error: state.error,
                canPrev: state.index > 0, canNext: !!state.data?.pagination.hasMore
            }, snapshots);
        }

        show(view, { refresh = false } = {}) {
            if (!this.allowed(view)) return;
            if (this.active !== view) {
                Object.entries(this.states).forEach(([key, state]) => { if (key !== view) this.cancel(state); });
                this.active = view;
            }
            const state = this.state(view);
            const filters = this.options.filters(view);
            const filterKey = JSON.stringify(filters);
            const first = state.filterKey === null;
            const changed = state.filterKey !== filterKey;
            if (changed) {
                // Invalidate immediately on input, not when the debounce timer fires.
                // A late response for the old text must never overwrite the new search.
                this.cancel(state);
                Object.assign(state, {
                    filterKey, filters, cursors: [''], index: 0, data: null,
                    cacheKey: null, error: null, failedRequest: null, errors: 0, retryAt: 0
                });
                if (!first) {
                    state.timer = setTimeout(() => {
                        state.timer = null;
                        if (this.allowed(view)) this.load(view, 0, '');
                    }, this.delay);
                    this.notify(view);
                    return;
                }
            }
            if (state.pending || state.timer !== null) { this.notify(view); return; }
            this.notify(view); // Repaint cached cards when the compact/full view changes.
            if ((!state.data || refresh) && this.now() >= state.retryAt) {
                return this.load(view, state.index, state.cursors[state.index]);
            }
        }

        move(view, direction) {
            const state = this.state(view);
            if (!this.allowed(view) || state.pending || state.timer !== null || !state.data) return;
            if (direction === 1 && state.data.pagination.hasMore) {
                return this.load(view, state.index + 1, state.data.pagination.nextCursor);
            }
            if (direction === -1 && state.index > 0) {
                return this.load(view, state.index - 1, state.cursors[state.index - 1]);
            }
        }

        first(view) {
            const state = this.state(view);
            if (!this.allowed(view) || state.pending || state.timer !== null) return;
            return this.load(view, 0, '');
        }

        retry(view) {
            const state = this.state(view);
            if (!this.allowed(view) || state.pending || state.timer !== null) return;
            const target = state.failedRequest || { index: state.index, cursor: state.cursors[state.index] };
            return this.load(view, target.index, target.cursor);
        }

        validate(data, view) {
            if (!data || data.guestApiVersion !== 1) throw new Error('نسخهٔ بخش مهمان هماهنگ نیست؛ صفحه را دوباره بارگذاری کنید.');
            if (data.unmodified) return;
            const page = data.pagination;
            if (!page || page.pageSize !== 20 || typeof page.hasMore !== 'boolean'
                || (page.hasMore ? typeof page.nextCursor !== 'string' || !page.nextCursor : page.nextCursor !== null)
                || !data.properties || Array.isArray(data.properties) || !data.agencies || Array.isArray(data.agencies)
                || typeof data.dataHash !== 'string' || !/^[a-f0-9]{64}$/.test(data.dataHash)
                || Object.keys(data.properties).length > 20 || Object.keys(data.agencies).length > 20
                || (view === 'guestAgencies' && Object.keys(data.properties).length !== 0)) {
                throw new Error('پاسخ صفحه‌بندی سرور نامعتبر است؛ فایل‌های به‌روزرسانی را بررسی کنید.');
            }
        }

        async load(view, targetIndex, cursor) {
            if (!this.allowed(view)) return;
            const state = this.state(view);
            this.cancel(state);
            const sequence = state.sequence;
            const request = { ...state.filters, cursor: cursor || '' };
            const key = JSON.stringify(request);
            const hash = state.data && state.cacheKey === key ? state.data.dataHash : '';
            const abort = new AbortController();
            state.abort = abort;
            state.pending = true;
            state.error = null;
            state.failedRequest = null;
            this.notify(view);
            let timedOut = false;
            const timeout = setTimeout(() => { timedOut = true; abort.abort(); }, this.timeout);
            try {
                const data = await this.options.fetchPage(view, request, hash, abort.signal);
                if (sequence !== state.sequence) return;
                if (!this.allowed(view)) { this.cancel(state); return; }
                this.validate(data, view);
                if (data.unmodified) {
                    if (!hash || !state.data || state.cacheKey !== key) throw new Error('صفحه در حافظه موجود نیست؛ دوباره تلاش کنید.');
                } else {
                    // Commit the page number only after success. Failed Next/Previous
                    // keeps the old page and its matching controls, not a mislabeled page.
                    state.data = data;
                    state.cacheKey = key;
                    state.index = targetIndex;
                    state.cursors = state.cursors.slice(0, targetIndex);
                    state.cursors[targetIndex] = cursor || '';
                }
                state.pending = false;
                state.errors = 0;
                state.retryAt = 0;
                this.notify(view);
            } catch (error) {
                if (sequence !== state.sequence) return;
                state.pending = false;
                state.error = timedOut ? 'پاسخ سرور طول کشید؛ دوباره تلاش کنید.' : (error.message || 'ارتباط با سرور برقرار نشد.');
                state.failedRequest = { index: targetIndex, cursor };
                state.errors++;
                state.retryAt = this.now() + Math.min(120000, 15000 * Math.pow(2, Math.min(state.errors - 1, 4)));
                this.notify(view);
            } finally {
                clearTimeout(timeout);
                if (sequence === state.sequence) state.abort = null;
            }
        }
    }

    if (typeof module !== 'undefined' && module.exports) module.exports = GuestPager;
    else root.GuestPager = GuestPager;
})(typeof window !== 'undefined' ? window : globalThis);
