/**
 * 🔥 Service Worker — نسخهٔ «خاموشی کامل» (v5)
 * =============================================
 * از این به بعد SW هیچ درخواستی را رهگیری نمی‌کند:
 *   - وقتی نصب می‌شود، همهٔ کش‌های قدیمی (v2/v3/v4) را پاک می‌کند.
 *   - بعد از فعال‌شدن، خودش را unregister می‌کند (خودکشی).
 *   - هیچ fetch handler ندارد → مرورگر همیشه مستقیم از شبکه می‌گیرد.
 *
 * این یعنی «صفحهٔ اصلی به‌جای پنل» و «خروجی خالی» دیگر هرگز از کش نمی‌آید؛
 * هر مشکلی که باشد، همان پاسخ واقعی سرور را می‌بینی.
 *
 * ⚠️ بعد از اینکه سایت بالا آمد، این فایل را هم از هاست پاک کن
 *    (مثل فایل‌های zz_* و diag.php و fix_pin.php).
 */

self.addEventListener('install', event => {
    self.skipWaiting();
    // پاک کردن همهٔ کش‌های قبلی
    event.waitUntil(
        caches.keys().then(keys => Promise.all(keys.map(k => caches.delete(k)))).catch(() => {})
    );
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => Promise.all(keys.map(k => caches.delete(k)))).catch(() => {})
            .then(() => self.registration.unregister().catch(() => {}))
    );
});

// ⚠️ عمداً هیچ self.addEventListener('fetch', ...) وجود ندارد.
//    یعنی SW هیچ درخواستی را نمی‌بیند؛ شبکه ۱۰۰٪ مستقیم.
