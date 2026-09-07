/* Recovery 29: diagnostic boot support only. No server data or credentials are logged. */
(function () {
    'use strict';
    var backendVerified = false, messages = [], seen = {}, memory = {localStorage: {}, sessionStorage: {}}, mapPromise = null, mapRetryAt = 0;
    function clean(value) {
        return String(value || '').replace(/(?:sk-or-v\d-|hf_)[A-Za-z0-9_-]+/g, '[redacted]')
            .replace(/Bearer\s+[^\s]+/gi, 'Bearer [redacted]').replace(/eyJ[A-Za-z0-9_.-]{40,}/g, '[token redacted]').slice(0, 280);
    }
    function draw() {
        var out = document.getElementById('recovery29-log');
        if (out) out.textContent = (backendVerified ? 'نسخهٔ تازهٔ API بازیابی هم تأیید شد.\n' : '') + (messages.length ? messages.join('\n') : 'نسخهٔ بازیابی ۲۹ بارگذاری شده است. اگر خطایی رخ دهد، اینجا نمایش داده می‌شود.');
    }
    function report(value) {
        var message = clean(value);
        if (!message || seen[message]) return;
        seen[message] = true; messages.push(message); if (messages.length > 12) messages.shift();
        draw();
        var panel = document.getElementById('recovery29-panel'); if (panel) panel.open = true;
    }
    function store(area) {
        return {
            getItem: function (key) {
                if (Object.prototype.hasOwnProperty.call(memory[area], key)) return memory[area][key];
                try { return window[area].getItem(key); } catch (error) { report('حافظهٔ مرورگر در دسترس نیست؛ صفحه بدون آن ادامه می‌دهد.'); return null; }
            },
            setItem: function (key, value) {
                memory[area][key] = String(value);
                try { window[area].setItem(key, String(value)); } catch (error) { report('ذخیرهٔ مرورگر پر یا مسدود است؛ اطلاعات دریافتی همچنان نمایش داده می‌شود.'); }
            },
            removeItem: function (key) {
                memory[area][key] = null;
                try { window[area].removeItem(key); } catch (error) { report('حذف نشست مرورگر انجام نشد.'); }
            }
        };
    }
    var local = store('localStorage'), session = store('sessionStorage');
    function readObject(area, key) {
        var raw = (area === 'local' ? local : session).getItem(key);
        if (!raw) return null;
        try { var value = JSON.parse(raw); return value && typeof value === 'object' && !Array.isArray(value) ? value : null; }
        catch (error) { report('قالب نشست ذخیره‌شده نامعتبر بود؛ بدون پاک کردن داده‌ها، ورود دوباره لازم است.'); return null; }
    }
    function resource(kind, url, label) {
        return new Promise(function (resolve, reject) {
            var element = document.createElement(kind === 'css' ? 'link' : 'script'), settled = false;
            element.setAttribute('data-recovery29', 'managed');
            if (kind === 'css') { element.rel = 'stylesheet'; element.href = url; }
            else { element.async = true; element.src = url; }
            var timer = setTimeout(function () { finish(new Error('دریافت ' + label + ' طول کشید؛ بقیهٔ سایت مستقل از آن فعال می‌ماند.')); }, 10000);
            function finish(error) {
                if (settled) return; settled = true; clearTimeout(timer);
                if (error) { element.remove(); reject(error); } else resolve();
            }
            element.onload = function () { finish(); };
            element.onerror = function () { finish(new Error(label + ' دریافت نشد؛ بقیهٔ سایت مستقل از آن فعال می‌ماند.')); };
            document.head.appendChild(element);
        });
    }
    function optionalAssets() {
        // None of these resources may block parsing or the first visible page.
        Promise.all([
            resource('css', 'assets/jalalidatepicker.min.css?v=fix29', 'ظاهر تقویم'),
            resource('js', 'assets/jalalidatepicker.min.js?v=fix29', 'تقویم')
        ]).then(function () {
            if (window.jalaliDatepicker) window.jalaliDatepicker.startWatch({time: false, hasSecond: false});
        }).catch(function (error) { report(error.message); });
        resource('js', 'assets/chart.umd.js?v=fix29', 'نمودارها').then(function () {
            if (typeof window.renderDashboard === 'function') window.renderDashboard();
        }).catch(function (error) { report(error.message); });
    }
    function loadMap() {
        if (api.mapReady) return Promise.resolve();
        if (mapPromise) return mapPromise;
        if (Date.now() < mapRetryAt) return Promise.reject(new Error('سرویس نقشه در دسترس نیست؛ کمی بعد دوباره تلاش کنید.'));
        mapPromise = Promise.all([
            resource('css', 'https://static.neshan.org/sdk/leaflet/v1.9.4/neshan-sdk/v1.0.8/index.css', 'ظاهر نقشه'),
            resource('js', 'https://static.neshan.org/sdk/leaflet/v1.9.4/neshan-sdk/v1.0.8/index.js', 'سرویس نقشه')
        ]).then(function () {
            if (!window.L || !window.L.Map) throw new Error('سرویس نقشه آماده نیست.');
            api.mapReady = true;
        }).catch(function (error) { mapPromise = null; mapRetryAt = Date.now() + 60000; report(error.message); throw error; });
        return mapPromise;
    }
    function fetchText(url, options, action) {
        var limited = ['getData', 'loginManager', 'loginConsultant'].indexOf(action) !== -1;
        var controller = limited && typeof AbortController !== 'undefined' ? new AbortController() : null;
        if (controller) options.signal = controller.signal;
        var timer = controller ? setTimeout(function () { controller.abort(); }, 15000) : null;
        return fetch(url, options).then(function (response) {
            if (!response.ok) report('درخواست ' + action + ' → HTTP ' + response.status);
            if (response.headers.get('X-Amlak-Recovery') === 'fix29-baseline26') { backendVerified = true; draw(); }
            else if (response.ok) report('نشان نسخهٔ API بازیابی دریافت نشد؛ مسیر فایل جدید را بررسی کنید.');
            return response.text().then(function (text) { return {response: response, text: text}; });
        }).catch(function (error) {
            var reason = error && error.name === 'AbortError' ? 'سرور در ۱۵ ثانیه پاسخ کامل نداد.' : 'ارتباط با API برقرار نشد.';
            report(action + ': ' + reason);
            throw new Error(reason);
        }).finally(function () { if (timer) clearTimeout(timer); });
    }
    var api = window.AmlakRecovery = {report: report, local: local, session: session, readObject: readObject,
        optionalAssets: optionalAssets, loadMap: loadMap, mapReady: false, fetchText: fetchText};
    window.addEventListener('error', function (event) {
        if (event.message) report('خطای اجرای صفحه: ' + event.message);
        else if (event.target && event.target.getAttribute && event.target.getAttribute('data-recovery29') !== 'managed' && (event.target.tagName === 'SCRIPT' || (event.target.tagName === 'LINK' && event.target.rel === 'stylesheet'))) report('یکی از فایل‌های رابط دریافت نشد.');
    }, true);
    window.addEventListener('unhandledrejection', function (event) { report('خطای راه‌اندازی: ' + (event.reason && event.reason.message || event.reason)); });
    document.addEventListener('DOMContentLoaded', function () {
        draw();
        var button = document.getElementById('recovery29-copy');
        if (button) button.addEventListener('click', function () {
            var text = 'fix29 / baseline fix26\n' + location.pathname + '\n' + messages.join('\n');
            if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).catch(function () { report('گزارش را با عکس صفحه بفرستید.'); });
            else report('گزارش را با عکس صفحه بفرستید.');
        });
    });
})();
