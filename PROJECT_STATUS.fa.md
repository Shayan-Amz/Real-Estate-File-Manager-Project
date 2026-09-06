# 📋 گزارش وضعیت پروژه «املاک من» — برای ادامهٔ کار در چت جدید

> **تاریخ:** 2026-09-07 · **آخرین کامیت:** `ed92f31` (برنچ `arena/01a076f5-real-estate-file-manager-proje`)
> این فایل را در چت بعدی هم بفرست تا همهچیز مشخص باشد.

---

## ✅ وضعیت کلی

سایت `https://test.amlak-e-man.ir` **بالا آمده و کار میکند** (پس از رفع مشکلات کش/سرویسورکر).
فایلهای اصلی (`index.html`, `sw.js`, `.htaccess`, `backup.php`) به **نسخهٔ سالم 3007cbd** بازگردانده شدهاند.

دامنهٔ اصلی `amlak-e-man.ir` هم سالم است.

---

## 🎛️ کلیدها (که در تاریخچهٔ گیت پیدا شدند)

| کلید | مقدار | وضعیت |
|---|---|---|
| **Hugging Face (STT)** | `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` | ✅ **کار میکند** (HTTP 200 در تست) |
| **OpenRouter (مغز متنی)** | `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` | ⚠️ **403 — نامعتبر/بدون دسترسی** |

---

## 🎙️ آخرین تست STT (خروجی کامل 2026-09-07 00:57)

| بخش | نتیجه |
|---|---|
| **Hugging Face Router** (`router.huggingface.co/...whisper-large-v3`) | ✅ **HTTP 200** در 1495ms — پاسخ `{"text":" you"}` |
| **Groq** | ⚠️ خالی (نیازی نیست — HF جواب میدهد) |
| **OpenAI** | ⚠️ خالی (نیازی نیست) |
| **مغز متنی (OpenRouter)** | ❌ **HTTP 403** — `"error": "Access denied by security policy."` |

**نتیجهٔ قطعی:**
- `STT_PROVIDER` = **`'hf'`** ✅ (در config.php از قبل همین است)
- `STT_HF_TOKEN` = تایید شده ✅
- `OPENROUTER_API_KEY` = معتبر است (طبق گفتهٔ کاربر)
- `OPENROUTER_MODEL` = ⚠️ احتمالاً منسوخ شده (`laguna-xs-2.1:free`) — خطای 403 «Access denied by security policy» می‌داد
- **اقدام انجامشده (2026-09-07):** بستهٔ `fix19-model.zip` → ابزار `set_model.php` ساخت که فقط مدل را به `google/gemma-4-26b-a4b-it:free` عوض می‌کند (بدون دست‌زدن به بقیهٔ فایل)

---

## ⚠️ مشکل باز: مغز متنی جارویس (OpenRouter) 403

**دو احتمال:**
1. **کلید `sk-or-v1-8fe1f36d...` نامعتبر/باطل شده** (باید در OpenRouter چک شود)
2. **URL سفارشی** `https://ai.shayan-api.ir/api/v1/chat/completions` با این کلید نمیخواند — شاید این دامنهٔ واسط باید با دامنهٔ رسمی `https://openrouter.ai/api/v1/chat/completions` عوض شود

**پیشنهاد بعدی (وقتی کلید موجود شد):**
- یا کلید OpenRouter را در `config.php` با کلید معتبر عوض کن
- یا `OPENROUTER_URL` را به `https://openrouter.ai/api/v1/chat/completions` تغییر بده
- بعد `stt_test.php` دوباره اجرا و خروجی بفرست

---

## 📦 فایلهای اضافهشده در طول پروژه (برای پاکسازی نهایی)

این فایلها **موقت/تست** هستند و باید از هاست (`public_html`) حذف شوند:
```
fix_pin.php
set_keys.php
stt_test.php
diag.php
zz_check.php
zz_check.txt
zz_check2.txt
zz_cfg.php
zz_reset_sw.html
zz_reset2.html
hostprobe.php
hostprobe.txt
ok_canary.php
ok_canary.txt
README_RESTORE.fa.txt
```
(در گیت فقط همان زیپهای fix18 و fix16 هستند؛ فایلهای اصلی امناند.)

---

## 📝 یادآوری برای ادامهٔ کار

- **دیتابیس، جداول و migration** همگی سبز و سالماند.
- **پین کاربران:** دقیقاً همان چیزی که میگذارند ذخیره میشود (A123 → A123) و رمز مالک سیستم (`2BLRWARqLyGwRjDBE27m`) جدا و در `config.php` است.
- **تنها کار باقیمانده:** رفع 403 OpenRouter برای فعالشدن «مغز متنی جارویس».

---

## 🧭 نقشهٔ قدم بعدی (مهم برای چت جدید)

1. در **OpenRouter** چک کن: کلید `sk-or-v1-8fe1f36d...` هنوز معتبره؟ (اگر نه، کلید جدید بساز)
2. در `config.php`:
   - `OPENROUTER_API_KEY` = کلید معتبر
   - `OPENROUTER_URL` = `https://openrouter.ai/api/v1/chat/completions` (اگر دامنهٔ سفارشی 403 میدهد)
3. دوباره `stt_test.php` اجرا کن و خروجی بفرست — باید **مغز متنی ✅** شود.
4. سپس در سایت، پلن آژانس تستی را **VIP** کن و دکمهٔ 🎤 را تست کن.

---

## 🔧 فایلهای زیپ موجود در مخزن (تاریخچهٔ کار)

| زیپ | محتوا | وضعیت |
|---|---|---|
| `fix16-restore.zip` | بازگردانی ۴ فایل اصلی به نسخهٔ سالم | ✅ اعمال شد — سایت بالا آمد |
| `fix17-cleanup.zip` | راهنمای حذف فایلهای تست | 📄 برای پاکسازی |
| `fix18-jarvis-keys.zip` | set_keys.php (پیشپر با کلیدهای موجود) + stt_test.php + راهنما | ⚠️ در انتظار اصلاح OpenRouter |

(زیپهای fix1 تا fix15 بیشتر تاریخیاند و لازم نیست دوباره استفاده شوند.)
