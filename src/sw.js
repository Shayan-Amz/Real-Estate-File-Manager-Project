// fix29 emergency recovery: network-only until the recovered host is confirmed.
// This is not a per-agency data cache. No localStorage or uploaded files are deleted.
self.addEventListener('install', event => {
    event.waitUntil(self.skipWaiting());
});
self.addEventListener('activate', event => {
    event.waitUntil((async () => {
        // Remove only this application's obsolete shell caches, not other apps' caches.
        for (const name of await caches.keys()) {
            if (name.startsWith('amlak-safe-cache-')) await caches.delete(name);
        }
        await self.clients.claim();
    })());
});
self.addEventListener('fetch', event => {
    if (new URL(event.request.url).origin !== self.location.origin) return;
    // Both api.php and the fresh api-recovery-29.php alias bypass the worker entirely.
    if (event.request.url.includes('api.php') || event.request.url.includes('api-recovery-29.php') || event.request.method !== 'GET') return;
    const shell = event.request.mode === 'navigate' || ['document', 'script', 'style'].includes(event.request.destination);
    event.respondWith(fetch(event.request, shell ? {cache: 'no-store'} : undefined).catch(() => {
        // Never disguise a failed JS/API request as cached index.html.
        if (event.request.mode === 'navigate') {
            return new Response('<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>ارتباط با هاست</title><body style="font:18px Tahoma;padding:30px;line-height:2"><h1>ارتباط با هاست برقرار نشد</h1><p>این پاسخ نسخهٔ بازیابی ۲۹ است. اینترنت یا پاسخ هاست را بررسی کنید؛ داده‌ای حذف نشده است.</p></body></html>', {
                status: 503, headers: {'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store'}
            });
        }
        return Response.error();
    }));
});
