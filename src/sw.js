const CACHE_NAME = 'amlak-safe-cache-v2';
const urlsToCache = [
    './',
    './index.html'
    // اگر فایل استایل یا عکسی دارید که همیشه ثابت است، نام آن را اینجا اضافه کنید
];

// ۱. نصب سریع و ذخیره فایل‌های حیاتی سایت در حافظه گوشی
self.addEventListener('install', event => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll(urlsToCache);
        })
    );
});

// ۲. پاک کردن تمام کش‌های خرابِ قبلی
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

// ۳. سیستم هوشمند مدیریت درخواست‌ها
self.addEventListener('fetch', event => {
    if (!event.request.url.startsWith(self.location.origin)) {
        return;
    }

    // ⚡ درخواست‌های دیتابیس (api.php) را از کش سرویس ورکر مستثنی می‌کنیم
    if (event.request.url.includes('api.php')) {
        return;
    }

    event.respondWith(
        fetch(event.request).catch(() => {
            return caches.match(event.request).then(response => {
                return response || caches.match('./index.html');
            });
        })
    );
});