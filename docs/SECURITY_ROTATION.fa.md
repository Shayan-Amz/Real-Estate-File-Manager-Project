# راهنمای چرخش رمزها و کلیدها

این فایل فقط یک چک‌لیست عملی است. هر سه مورد زیر **باید توسط تو و از طریق پنل‌ها** انجام شود —
از داخل کد یا از این ریپو قابل انجام نیست.

---

## چه چیزی لو رفته و کجا

| راز | وضعیت فعلی | کجا لو رفته |
|---|---|---|
| کلید Hugging Face (`hf_KvLBxi…DVHP`) | فعال | کامیت `1e53fcf` و `d5684a2` |
| کلید OpenRouter (`sk-or-v1-8fe1f36d…5d28`) | فعال | کامیت `1e53fcf` و `d5684a2` |
| رمز دیتابیس فعلی (`JY8FLF…rAP`) | فعال | کامیت `1e53fcf` |
| رمز دیتابیس قدیمی (`Mx3eu4rR7ydyVyynfnGM`) | احتمالاً باطل | داخل `Test.zip` در کامیت `d6cd3b9` (شاخهٔ `main`) |
| `APP_SALT` | ✅ چرخید | کامیت `18eaf1a` |
| رمز اصلی | ✅ چرخید | کامیت `18eaf1a` |

> ⚠️ **مهم:** پاک کردن از `config.php` کافی نیست. این مقادیر تا ابد در تاریخچهٔ git می‌مانند
> (`git log -S "hf_KvLBxiGEUZ"` آن‌ها را برمی‌گرداند). تنها راه مطمئن، **باطل کردن خودِ کلید** است.

ریپو private است، پس خطر فوری محدود است — ولی هر کسی که دسترسی Collaborator دارد
(و هر ابزار CI یا افزونه‌ای که توکن ریپو را داشته باشد) این تاریخچه را می‌بیند.

---

## ۱) کلید Hugging Face

1. وارد <https://huggingface.co/settings/tokens> شو.
   (اگر لینک باز نشد: عکس پروفایل گوشهٔ بالا → **Settings** → **Access Tokens**.)
2. توکن `hf_KvLBxi…DVHP` را پیدا کن → **Revoke**.
3. **New token** بزن:
   - Name: `amlak-stt`
   - Type: **Read** کافی است
   - Permission: حتماً تیک **Make calls to Inference Providers** را بزن — بدون آن،
     `router.huggingface.co` خطای ۴۰۱ می‌دهد.
4. توکن جدید (`hf_…`) را کپی کن.

## ۲) کلید OpenRouter

1. وارد <https://openrouter.ai/settings/keys> شو.
   (اگر لینک باز نشد: عکس پروفایل → **Keys**.)
2. کلید `sk-or-v1-8fe1f36d…5d28` را پیدا کن → **Delete** / **Revoke**.
3. **Create Key** بزن، نام `amlak-jarvis`، و **Credit limit** را روی یک عدد کم
   (مثلاً `2$`) بگذار تا اگر لو رفت، خسارت محدود بماند.
4. کلید جدید را **همان لحظه** کپی کن — دیگر نمایش داده نمی‌شود.

> 💡 این کلید فقط وقتی لازم است که `STT_PROVIDER` را روی `'openai'` بگذاری.
> اگر از `'hf'` استفاده می‌کنی، حتی می‌توانی در `config.php` خالی‌اش بگذاری.

## ۳) رمز دیتابیس

مسیر پروژه (`/home/owihpfoa/domains/test.amlak-e-man.ir/public_html`) نشان می‌دهد
هاست **DirectAdmin** است. اگر cPanel داری، بگو تا مسیرش را بدهم.

1. وارد پنل DirectAdmin شو.
2. بخش **Account Manager** → **MySQL Management**.
3. روی دیتابیس `owihpfoa_test_db` کلیک کن.
4. در بخش کاربران، کنار `owihpfoa_test_db` → **Change Password**.
5. رمز تازه را وارد کن. یک رمز تصادفی ۲۴ کاراکتری آماده کرده‌ام:

   ```
   7LEGnOffqZaUSSwOO8X4vyvA
   ```

   (بدون `'` و `\` و کاراکترهای خطرناک برای PHP و shell. اگر خواستی خودت بساز، حتماً
    از همین قاعده پیروی کن.)
6. **Save** / **Change**.

> 🔴 **ترتیب مهم:** اول رمز را در پنل عوض کن، بعد **فوراً** `config.php` را ویرایش کن.
> بین این دو، سایت خطای اتصال به دیتابیس می‌دهد. بهتر است هر دو را در یک نشست و پشت‌سرهم انجام دهی.

---

## ۴) به‌روزرسانی `config.php` روی سرور

این فایل را **روی هاست** ویرایش کن، نه در git:

```
File Manager → public_html → config.php → Edit
```

سه خطی که عوض می‌شود:

```php
define('DB_PASS', '7LEGnOffqZaUSSwOO8X4vyvA');
define('STT_HF_TOKEN', 'hf_توکن_جدید');
define('OPENROUTER_API_KEY', 'sk-or-v1-کلید_جدید');
```

⚠️ `config.php` را **روی هاست** نگه دار و تغییراتش را در git کامیت نکن،
وگرنه رمز تازه دوباره لو می‌رود و کل این کار بی‌فایده می‌شود.

## ۵) تست اینکه چرخش درست انجام شده

از خط فرمان هاست (SSH) یا از یک فایل موقت:

```bash
php -r 'require "config.php"; echo DB_NAME, " → ", (new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS) ? "OK" : "?"), PHP_EOL;'
```

خروجی باید `owihpfoa_test_db → OK` باشد. بعد فایل را پاک کن.

برای کلید Hugging Face:

```bash
php tools/stt_probe.php
```

اگر `401` یا `Invalid credentials` دیدی، یعنی تیک **Make calls to Inference Providers**
را نزده‌ای.

---

## ۶) پیشنهاد: دیگر `config.php` را در git نگذار

الان `src/config.php` در git ردیابی می‌شود، پس هر بار که رمزها را عوض کنی
دوباره لو می‌روند. راه‌حل:

```bash
git rm --cached src/config.php
echo "src/config.php" >> .gitignore
```

قالب بدون راز (`src/config.example.php`) در ریپو می‌ماند و هر کسی که پروژه را
کلون می‌کند، از روی آن `config.php` خودش را می‌سازد.

> ⚠️ این کار **تاریخچهٔ قبلی را پاک نمی‌کند** — فقط از لو رفتن در آینده جلوگیری می‌کند.
> برای پاک کردن تاریخچه، `git filter-repo` لازم است که تاریخچه را بازنویسی می‌کند
> و PR #2 و شاخهٔ `Test` را خراب می‌کند. توصیهٔ من: **اول چرخش رمزها، بعد تصمیم دربارهٔ تاریخچه.**
