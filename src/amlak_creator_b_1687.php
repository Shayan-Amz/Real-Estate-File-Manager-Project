<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>👑 کنترل پنل مالک سیستم</title>
    <style>
        body { font-family: Tahoma, sans-serif; background: #0f172a; color: white; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
        .admin-card { background: #1e293b; padding: 30px; border-radius: 20px; border: 1px solid #334155; width: 100%; max-width: 450px; box-shadow: 0 20px 40px rgba(0,0,0,0.5); }
        h2 { text-align: center; margin-top: 0; color: #3b82f6; border-bottom: 1px solid #334155; padding-bottom: 15px; margin-bottom: 20px; }
        input { width: 100%; padding: 12px 15px; margin-bottom: 15px; border-radius: 10px; border: 1px solid #475569; background: #020617; color: white; box-sizing: border-box; font-size: 14px; outline: none; }
        input:focus { border-color: #3b82f6; }
        button { width: 100%; padding: 14px; background: linear-gradient(135deg, #2563eb, #1d4ed8); color: white; border: none; border-radius: 10px; cursor: pointer; font-weight: bold; font-size: 16px; transition: 0.2s; }
        button:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(37,99,235,0.4); }
        .result-box { margin-top: 20px; padding: 15px; border-radius: 10px; background: #064e3b; border: 1px solid #047857; color: #34d399; font-weight: bold; text-align: center; display: none; line-height: 1.8; }
    </style>
</head>
<body>

    <div class="admin-card">
        <h2>مدیریت و ساخت آژانس جدید</h2>
        <form id="form-super-admin" onsubmit="handleCreateAgency(event)">
            <input required type="password" id="sa-master-password" placeholder="رمز عبور ادمین کل (Master Password)" style="text-align: center; letter-spacing: 2px;" />
            <input required type="text" id="sa-agency-name" placeholder="نام آژانس جدید" />
            <input required type="text" id="sa-agency-city" placeholder="شهر آژانس" />
            <input required type="tel" id="sa-agency-phone" placeholder="شماره تماس اصلی (مثال: 09121112222)" dir="ltr" />
            <input type="tel" id="sa-agency-phone2" placeholder="شماره تماس دوم (اختیاری)" dir="ltr" />
            <input required type="text" id="sa-manager-name" placeholder="نام مدیر آژانس" />
            <input required type="text" id="sa-manager-pin" placeholder="تعیین رمز عبور برای ورود مدیر" style="text-align: center; letter-spacing: 2px;" />
            
            <div style="margin-bottom: 15px;">
                <label style="font-size: 13px; color: #94a3b8; display: block; margin-bottom: 5px;">تعداد روزهای اعتبار اشتراک:</label>
                <input required type="number" id="sa-validity-days" value="30" />
            </div>
            
            <button type="submit" id="submit-btn">تولید کد جدید آژانس</button>
        </form>
        <div id="sa-result" class="result-box"></div>
    </div>

    <script>
        // ⚡ توجه: اگر این فایل در پوشه‌ای غیر از پوشه اصلی سایت است، مسیر api.php را اصلاح کنید
        const API_URL = 'api.php';
        // ⚡ کلید اجباری api.php (خط ۲۵۶). بدون این کلید سرور ۴۰۳ با پیام «درخواست نامعتبر» برمی‌گرداند.
        const API_KEY = "AmLaK_Super_Secret_2026!";

        async function handleCreateAgency(e) {
            e.preventDefault();
            const btn = document.getElementById('submit-btn');
            btn.disabled = true;
            btn.innerText = 'در حال برقراری ارتباط با سرور...';
            
            try {
                const masterPass = document.getElementById('sa-master-password').value.trim();
                const agencyCode = Math.floor(100000 + Math.random() * 900000).toString();
                const validityDays = parseInt(document.getElementById('sa-validity-days').value) || 30;
                const expireAt = new Date(Date.now() + validityDays * 24 * 60 * 60 * 1000).toISOString();
                
                const payload = { 
                    api_key: API_KEY, // ⚡ رفع باگ: این کلید قبلاً فرستاده نمی‌شد و سرور ۴۰۳ می‌داد
                    id: agencyCode, 
                    masterPass: masterPass, 
                    name: document.getElementById('sa-agency-name').value.trim(), 
                    city: document.getElementById('sa-agency-city').value.trim(), 
                    phone: document.getElementById('sa-agency-phone').value.trim(), 
                    phone2: document.getElementById('sa-agency-phone2').value.trim(), 
                    managerName: document.getElementById('sa-manager-name').value.trim() || 'مدیر', 
                    adminPin: document.getElementById('sa-manager-pin').value.trim(), 
                    createdAt: new Date().toISOString(), 
                    expireAt: expireAt 
                };

                const res = await fetch(`${API_URL}?action=saveAgency`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-API-Key': API_KEY },
                    body: JSON.stringify(payload)
                });
                
                // ⚡ اگر سرور به‌جای JSON صفحهٔ خطا بفرستد، پیام قابل‌فهم نشان بده نه خطای پارس
                const rawText = await res.text();
                let data;
                try { data = JSON.parse(rawText); }
                catch (parseErr) { throw new Error(`سرور پاسخ نامعتبر فرستاد (HTTP ${res.status}).`); }
                if (data.error) throw new Error(data.error);

                const resultEl = document.getElementById('sa-result');
                resultEl.innerHTML = `✅ آژانس با موفقیت در دیتابیس ثبت شد!<br>کد آژانس: <b style="font-size:20px; color:white;">${agencyCode}</b><br>اعتبار: ${validityDays} روز`;
                resultEl.style.display = 'block';
                
                document.getElementById('sa-master-password').value = ''; // پاک کردن رمز برای امنیت
                
            } catch (err) {
                alert("❌ خطا در ثبت: " + (err.message || "سرور پاسخگو نیست"));
            } finally {
                btn.disabled = false;
                btn.innerText = 'تولید کد جدید آژانس';
            }
        }
    </script>
</body>
</html>