# راهنمای استقرار روی هاست

این فایل قدم‌به‌قدم می‌گوید چه چیزی را از کجا برداری، کجا بگذاری، و **دقیقاً چه کارهایی
را باید دستی انجام دهی**.

---

## ⚠️ قبل از هر چیز: یک نکتهٔ مهم دربارهٔ GitHub

**PR #2 هنوز مرج نشده.** یعنی شاخهٔ `main` هیچ‌کدام از این تغییرات را ندارد.
اگر از `main` دانلود کنی، نسخهٔ قدیمی را می‌گیری.

باید از این شاخه برداری:

```
arena/01a07379-real-estate-file-manager-proje
```

آخرین کامیت آن: `577efeb`

---

## راه اول (پیشنهادی): بستهٔ آماده

فایل **`amlak-deploy.zip`** را که برایت ساخته‌ام بگیر. داخلش دقیقاً همان ۳۱ فایلی است
که باید برود داخل `public_html` — نه بیشتر، نه کمتر.

- `config.php` با رمز دیتابیس تازه داخلش هست
- `uploads/` عمداً خالی است (عکس‌های خودت روی هاست دست‌نخورده بمانند)
- `test.php` و `htaccess` داخلش نیستند (باید از هاست پاک شوند)

## راه دوم: از خود GitHub

1. برو به `github.com/Shayan-Amz/Real-Estate-File-Manager-Project`
2. بالا، روی dropdown شاخه (که نوشته `main`) کلیک کن → تب **Branches** →
   `arena/01a07379-real-estate-file-manager-proje` را انتخاب کن
3. دکمهٔ سبز **Code** → **Download ZIP**
4. از داخل آن زیپ، **فقط پوشهٔ `src`** را لازم داری. محتوای `src` همان چیزی است
   که باید برود داخل `public_html`.
   (`Test.zip` و `docs` را آپلود نکن.)

> ⚠️ در این روش `config.php` **داخل زیپ نیست**، چون از ردیابی git خارج شده.
> باید جداگانه از `amlak-deploy.zip` برداریش یا دستی روی هاست بسازی.

---

# قدم‌های دستی

## قدم ۰ — بک‌آپ بگیر (این را رد نکن)

**الف) فایل‌ها:**
DirectAdmin → **File Manager** → `domains/test.amlak-e-man.ir/public_html` →
همه را انتخاب کن → **Compress** → یک `backup-before-fix.zip` بساز و دانلودش کن.

**ب) دیتابیس:**
DirectAdmin → **Account Manager** → **MySQL Management** → روی `owihpfoa_test_db` →
**Download Backup** (یا `Create Backup`).

> 🔴 اگر این دو را نداشته باشی و چیزی خراب شود، راه برگشتی نیست.

## قدم ۱ — آپلود

DirectAdmin → **File Manager** → برو داخل `public_html`

1. اگر دکمهٔ «Show Hidden Files» داری، روشنش کن (وگرنه `.htaccess` را نمی‌بینی)
2. **Upload** → `amlak-deploy.zip` را آپلود کن
3. روی همان زیپ → **Extract** → مقصد: `public_html`
4. «Overwrite existing files» را **تیک بزن**
5. بعد از استخراج، خودِ `amlak-deploy.zip` را از `public_html` **پاک کن**

> ⚠️ اگر FTP استفاده می‌کنی: `.htaccess` و پوشه‌های `uploads`/`backups`/`tools`/`cgi-bin`
> را هم بفرست. خیلی از کلاینت‌های FTP فایل‌های نقطه‌دار را پنهان می‌کنند.

## قدم ۲ — دو فایل را از هاست پاک کن

این‌ها در بسته نیستند ولی **روی هاست تو وجود دارند**:

```
public_html/test.php     ← حذف کن
public_html/htaccess     ← حذف کن (بدون نقطه!)
```

- `test.php` ورودی کاربر را بدون escape برمی‌گرداند
- `htaccess` (بی‌نقطه) غیرفعال است ولی اگر کسی فعالش کند `api.php` را مسدود می‌کند

## قدم ۳ — 🔴 رمز دیتابیس

**این را انجام ندهی، سایت بالا نمی‌آید.**

داخل `config.php` این مقدار گذاشته شده:

```
7LEGnOffqZaUSSwOO8X4vyvA
```

حالا همان را در پنل ست کن:

1. DirectAdmin → **Account Manager** → **MySQL Management**
2. روی دیتابیس `owihpfoa_test_db` کلیک کن
3. در بخش کاربران، کنار `owihpfoa_test_db` → **Change Password**
4. بگذار: `7LEGnOffqZaUSSwOO8X4vyvA`
5. **Save** / **Change**

## قدم ۴ — دسترسی پوشه‌ها

در File Manager:

| پوشه | دسترسی |
|---|---|
| `uploads/` | `755` |
| `backups/` | `755` |
| `config.php` | `644` (یا `640` اگر هاست اجازه دهد) |

اگر `uploads/` قابل نوشتن نباشد، آپلود عکس کار نمی‌کند.

## قدم ۵ — بررسی سلامت

این آدرس را در مرورگر باز کن:

```
https://test.amlak-e-man.ir/tools/health_check.php?key=hk_9f3c71ab60d54e8fa2c17be4d5096e38
```

یا اگر SSH داری:

```bash
cd ~/domains/test.amlak-e-man.ir/public_html
php tools/health_check.php
```

خروجی باید همه ✅ باشد و در آخر بنویسد `🎉 همه‌چیز سبز است`.

**کل خروجی را کپی کن و بفرست.** اولین بار است که این کد روی سرور واقعی اجرا می‌شود
و احتمال زیاد چیزهایی پیدا می‌کند که از راه دور دیده نمی‌شود.

## قدم ۶ — تست سایت

1. `https://test.amlak-e-man.ir` را باز کن
2. ⚠️ **همه باید دوباره لاگین کنند** — `APP_SALT` عوض شده و سشن‌های قبلی باطل‌اند
3. این‌ها را چک کن:
   - لاگین با کد آژانس و پین
   - ثبت یک ملک با عکس
   - ویرایش یک تقاضا (دکمهٔ ✏️ — قابلیت تازه)
   - توضیحات تقاضا نمایش داده شود
   - لوگو در بالا سمت راست
4. رمز اصلی پنل مدیریت لایسنس عوض شده:

   ```
   2BLRWARqLyGwRjDBE27m
   ```

## قدم ۷ — پاک‌کاری

بعد از اینکه همه‌چیز سبز شد:

```
public_html/tools/health_check.php   ← حذف کن
```

---

# اگر سایت خراب شد

## خطای ۵۰۰ فوراً بعد از آپلود

به احتمال زیاد `.htaccess`. موقتاً نامش را بگذار `htaccess.bak` و دوباره تست کن.
اگر درست شد، مشکل از بلوک Basic Auth است:

```
AuthUserFile /home/owihpfoa/domains/test.amlak-e-man.ir/.htpasswd/public_html/.htpasswd
```

این مسیر یک `htpasswd/` اضافه به‌عنوان پوشه دارد که مشکوک است. در File Manager چک کن
فایل `.htpasswd` واقعاً کجاست و مسیر را درست کن.

## «خطا در اتصال به دیتابیس»

یعنی رمز `config.php` با رمز DirectAdmin یکی نیست. قدم ۳ را دوباره چک کن.

## عکس‌ها آپلود نمی‌شوند

دسترسی `uploads/` را `755` کن (قدم ۴).

## جارویس (صوت) کار نمی‌کند

1. `php tools/stt_probe.php` را از SSH اجرا کن
2. پلن آژانس باید `vip` باشد — وگرنه قبل از رسیدن به سرویس صوت، ۴۰۳ می‌گیری
3. توکن Hugging Face باید تیک **Make calls to Inference Providers** داشته باشد

---

# کارهایی که فقط تو می‌توانی انجام دهی

- 🔴 **ابطال کلید Hugging Face** — `huggingface.co` → عکس پروفایل → Settings →
  Access Tokens → Revoke
- 🔴 **ابطال کلید OpenRouter** — `openrouter.ai` → عکس پروفایل → Keys → Revoke
- 🔴 چرخش رمز دیتابیس (قدم ۳ بالا)

این سه تا پشت لاگین اکانت خودت هستند و از هیچ ابزاری قابل انجام نیستند.
