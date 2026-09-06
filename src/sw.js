/**
 * ⚡ Service Worker — نسخهٔ تنظیمشده (v4)
 * =======================================
 * تغییر مهم نسبت به v2/v3:
 *   قبلاً هر درخواستی که fetch رد می‌شد (تایم‌اوت/خطای شبکه)، با «index.html
 *   کش‌شده» جواب داده می‌شد؛ برای همین وقتی سرور کند/خطا می‌داد، به‌جای پیام
 *   واقعی، «صفحهٔ اصلی» می‌آمد و خطا قایم می‌شد.
 *
 * حالا:
 *   - فایل‌های .php (پنل‌ها، api.php، ابزارها) هرگز از کش/بودن SW پاسخ نمی‌گیرند.
 *   - استثنای آفلاین فقط برای ناوبریِ خودِ صفحهٔ اصلی است (index.html).
 *   - بقیهٔ فایل‌ها (عکس/فونت/JS) آفلاین → فقط اگر دقیقاً در کش باشند.
 */

const CACHE_NAME = 'amlak-safe-cache-v4';
const urlsToCache = [
    './',
    './index.html'
];

// ۱. نصب سریع و ذخیرهٔ فایل‌های حیاتی
self.addEventListener('install', event => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll(urlsToCache).catch(() => {});
        })
    );
});

// ۲. پاک کردن کش‌های خراب قبلی
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (cacheName !== CACHE_NAME) {
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
    self.clients.claim();
});

// ۳. مدیریت هوشمند درخواست‌ها
self.addEventListener('fetch', event => {
    if (!event.request.url.startsWith(self.location.origin)) {
        return;
    }

    const url = new URL(event.request.url);

    // 🔴 فایل‌های PHP هرگز توسط SW لمس نشوند (پنل‌ها، api، ابزارها)
    if (url.pathname.endsWith('.php')) {
        return;
    }

    // 🔴 درخواست‌های دیتابیس (api.php) مستثنی (پوشش فوق هم کافی است، این تضمین دوباره است)
    if (url.pathname === '/api.php') {
        return;
    }

    // فقط ناوبری صفحهٔ اصلی: آفلاین → index.html کش‌شده
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(() => caches.match('./index.html'))
        );
        return;
    }

    // بقیهٔ فایل‌ها: همیشه شبکه؛ در صورت قطعی، فقط اگر دقیقاً در کش باشند
    event.respondWith(
        fetch(event.request).catch(() => {
            return caches.match(event.request).then(r => r || Response.error());
        })
    );
});
