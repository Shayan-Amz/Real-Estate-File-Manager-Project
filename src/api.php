<?php
ob_start("ob_gzhandler");
// فایل API فوق‌پیشرفته بر پایه معماری Enterprise SQL - Secure Mode
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ⚡ سیستم جلوگیری از کرش کردن سرور و نشت اطلاعات
set_exception_handler(function($e) {
    http_response_code(500);
    error_log('Server Error: ' . $e->getMessage()); 
    echo json_encode(['error' => 'خطای داخلی سرور رخ داده است. لطفاً بعداً تلاش کنید.']); 
    exit;
});

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://unpkg.com https://cdnjs.cloudflare.com; img-src 'self' data: https://*.tile.openstreetmap.org https://*.google.com;");
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Agency-ID, X-User-Role, X-Auth-Token, X-User-Name, X-Data-Hash, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
if (!file_exists($uploadDir . 'index.php')) { file_put_contents($uploadDir . 'index.php', '<?php // Silence is golden. ?>'); }

function getRealIp() {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) { $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']); return trim($ips[0]); }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function sanitizeInput($data) {
    if (is_string($data)) {
        if (strpos($data, 'data:image') === 0) return $data; 
        return htmlspecialchars(strip_tags($data), ENT_QUOTES, 'UTF-8');
    } elseif (is_array($data)) { foreach ($data as $k => $v) $data[$k] = sanitizeInput($v); }
    return $data;
}

// 🛡️ اضافه شدن متغیر plan به توکن امنیتی برای جلوگیری از هک در مرورگر
function generateSecureToken($agency, $role, $name, $salt, $plan = 'Basic') {
    $payload = base64_encode(json_encode(['a' => $agency, 'r' => $role, 'n' => $name, 'p' => $plan, 'exp' => time() + 108000]));
    return $payload . '.' . hash_hmac('sha256', $payload, $salt);
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    try {
        $stmtCheck = $pdo->query("SHOW TABLES LIKE 'properties'");
        if ($stmtCheck->rowCount() > 0) {
            try { $pdo->query("SELECT showImagesGuest FROM properties LIMIT 1"); } 
            catch (PDOException $e) { $pdo->exec("ALTER TABLE properties ADD COLUMN showImagesGuest TINYINT(1) DEFAULT 0 AFTER showPriceGuest"); }
            try { $pdo->query("SELECT showToGuest FROM properties LIMIT 1"); } 
            catch (PDOException $e) { $pdo->exec("ALTER TABLE properties ADD COLUMN showToGuest TINYINT(1) DEFAULT 0 AFTER isVIP"); }
            try { $pdo->query("SELECT floor FROM properties LIMIT 1"); } 
            catch (PDOException $e) { 
                $pdo->exec("ALTER TABLE properties ADD COLUMN floor VARCHAR(50) DEFAULT '' AFTER rooms");
                $pdo->exec("ALTER TABLE properties ADD COLUMN unit VARCHAR(50) DEFAULT '' AFTER floor");
            }
            try { $pdo->query("SELECT buildArea FROM properties LIMIT 1"); } 
            catch (PDOException $e) { $pdo->exec("ALTER TABLE properties ADD COLUMN buildArea INT(11) DEFAULT 0 AFTER area"); }
            try { $pdo->query("SELECT isPreSale FROM properties LIMIT 1"); } 
            catch (PDOException $e) { $pdo->exec("ALTER TABLE properties ADD COLUMN isPreSale TINYINT(1) DEFAULT 0 AFTER canPartner"); }
            try { $pdo->query("SELECT deposit FROM demands LIMIT 1"); } 
            catch (PDOException $e) { 
                $pdo->exec("ALTER TABLE demands ADD COLUMN deposit BIGINT(20) DEFAULT 0 AFTER budget");
                $pdo->exec("ALTER TABLE demands ADD COLUMN rent BIGINT(20) DEFAULT 0 AFTER deposit");
            }
            // اطمینان از وجود ستون نوع پلن در آژانس‌ها
            try { $pdo->query("SELECT plan_type FROM agencies LIMIT 1"); } 
            catch (PDOException $e) { $pdo->exec("ALTER TABLE agencies ADD COLUMN plan_type VARCHAR(20) DEFAULT 'Basic' AFTER managerName"); }
            // اطمینان از وجود ستون آخرین بازدید برای سیستم ضربان قلب
            try { $pdo->query("SELECT lastSeen FROM members LIMIT 1"); } 
            catch (PDOException $e) { $pdo->exec("ALTER TABLE members ADD COLUMN lastSeen INT(11) DEFAULT 0"); }
        }
    } catch (Exception $e) { }

    $ip = getRealIp();
    $now = time();
    $pdo->exec("DELETE FROM rate_limits WHERE request_time < " . ($now - 60));
    $pdo->prepare("INSERT INTO rate_limits (ip, request_time) VALUES (?, ?)")->execute([$ip, $now]);
    
    $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE ip = ?");
    $stmtCnt->execute([$ip]);
    if ($stmtCnt->fetchColumn() > 150) { http_response_code(429); echo json_encode(['error' => 'سیستم ضد ربات فعال شد.']); exit; }

} catch(PDOException $e) { http_response_code(500); echo json_encode(['error' => 'خطا در اتصال به دیتابیس.']); exit; }

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$agencyId = $_SERVER['HTTP_X_AGENCY_ID'] ?? '';
$authToken = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';

$userRole = 'مهمان';
$userName = '';
$userPlan = 'Basic';
$isFullyAuthenticated = false;

if (!empty($authToken) && strpos($authToken, '.') !== false) {
    list($payload, $signature) = explode('.', $authToken);
    if (hash_equals(hash_hmac('sha256', $payload, APP_SALT), $signature)) {
        $dec = json_decode(base64_decode($payload), true);
        if ($dec && $dec['exp'] >= time()) {
            $userRole = $dec['r'];
            $userName = $dec['n'];
            $tokenAgencyId = $dec['a'];
            $userPlan = $dec['p'] ?? 'Basic'; 
            
            if ($tokenAgencyId === $agencyId) {
                if ($userRole === 'مشاور') {
                    $stmtCheck = $pdo->prepare("SELECT status FROM members WHERE name = ? AND agencyId = ?");
                    $stmtCheck->execute([$userName, $agencyId]);
                    if ($stmtCheck->fetchColumn() === 'active') { $isFullyAuthenticated = true; } 
                    else { http_response_code(403); echo json_encode(['error' => 'حساب مسدود شده است!']); exit; }
                } else {
                     $isFullyAuthenticated = true;
                }
            }
        }
    }
    if (!$isFullyAuthenticated && !in_array($action, ['loginManager', 'loginConsultant', 'saveAgency'])) {
        http_response_code(403); echo json_encode(['error' => 'نشست شما منقضی شده یا نامعتبر است. لطفاً دوباره وارد شوید.']); exit;
    }
}

if ($userRole === 'مهمان' && in_array($action, ['saveProperty', 'deleteProperty', 'saveDemand', 'deleteDemand', 'saveMember', 'changeMyPassword', 'resetMemberPassword', 'deleteMember'])) {
    http_response_code(403); echo json_encode(['error' => 'غیرمجاز.']); exit;
}

function markSystemUpdated($pdo) {
    $pdo->exec("INSERT INTO sys_config (conf_key, conf_val) VALUES ('last_update', UNIX_TIMESTAMP()) ON DUPLICATE KEY UPDATE conf_val = UNIX_TIMESTAMP()");
}

function formatSqlDate($isoDate) {
    if (empty($isoDate)) return date('Y-m-d H:i:s');
    $ts = strtotime($isoDate);
    return $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
}

// ==========================================
// GET METHODS
// ==========================================
if ($method === 'GET' || $action === 'getData') {
    if ($action === 'getData') {
        $clientHash = $_SERVER['HTTP_X_DATA_HASH'] ?? '';
        $stmtSys = $pdo->query("SELECT conf_val FROM sys_config WHERE conf_key = 'last_update'");
        $serverHash = $stmtSys->fetchColumn() ?: '0';

        if ($clientHash === $serverHash && $serverHash !== '0') { 
            echo json_encode(['response' => ['unmodified' => true]]); exit; 
        }

        $out = ['agencies' => (object)[], 'properties' => (object)[], 'demands' => (object)[], 'members' => (object)[]];
        
        if ($isFullyAuthenticated && $userRole === 'مدیر') {
            $stmt = $pdo->prepare("SELECT id, name, city, phone, phone2, managerName, expireAt, createdAt, plan_type FROM agencies WHERE id = ?");
            $stmt->execute([$agencyId]);
            if($row = $stmt->fetch()) { $out['agencies']->{$row['id']} = $row; }
            $stmt = $pdo->prepare("SELECT id, name, city, phone, phone2, plan_type FROM agencies WHERE id != ?");
            $stmt->execute([$agencyId]);
            while($row = $stmt->fetch()) { $out['agencies']->{$row['id']} = $row; }
            
        } elseif ($isFullyAuthenticated && $userRole === 'مشاور') {
            $stmt = $pdo->query("SELECT id, name, city, phone, phone2, plan_type FROM agencies");
            while($row = $stmt->fetch()) { $out['agencies']->{$row['id']} = $row; }
        } else {
            $stmt = $pdo->query("SELECT id, name, city, phone, phone2, plan_type FROM agencies");
            while($row = $stmt->fetch()) { 
                $maskedId = 'ag_' . substr(hash('sha256', $row['id'] . APP_SALT), 0, 8);
                $row['id'] = $maskedId;
                $out['agencies']->{$maskedId} = $row; 
            }
        }
        
        if ($isFullyAuthenticated && $agencyId) {
            $stmt = $pdo->prepare("SELECT * FROM properties WHERE agencyId = ? OR (status = 'موجود' AND showToGuest = 1)"); 
            $stmt->execute([$agencyId]);
        } else {
            $stmt = $pdo->query("SELECT * FROM properties WHERE status = 'موجود' AND showToGuest = 1");
        }
        
        while($row = $stmt->fetch()) { 
            $prop = [
                'id' => $row['id'], 'agencyId' => $row['agencyId'], 'authorName' => $row['authorName'], 'status' => $row['status'],
                'city' => $row['city'], 'location' => $row['location'], 'lat' => $row['lat'], 'lng' => $row['lng'], 'usage' => $row['usage_type'],
                'area' => (int)$row['area'], 'buildArea' => (int)($row['buildArea'] ?? 0), 'rooms' => $row['rooms'], 'floor' => $row['floor'] ?? '', 'unit' => $row['unit'] ?? '', 'yearBuilt' => $row['yearBuilt'],
                'hasParking' => (bool)$row['hasParking'], 'hasElevator' => (bool)$row['hasElevator'], 'hasStorage' => (bool)$row['hasStorage'],
                'dealType' => $row['dealType'], 'description' => $row['description'], 'canExchange' => (bool)$row['canExchange'],
                'canPartner' => (bool)$row['canPartner'], 'isPreSale' => (bool)($row['isPreSale'] ?? 0), 'isVIP' => (bool)$row['isVIP'],
                'showToGuest' => (bool)($row['showToGuest'] ?? 0),
                'showPriceGuest' => (bool)$row['showPriceGuest'], 'showImagesGuest' => (bool)($row['showImagesGuest'] ?? 0),
                'date' => str_replace(' ', 'T', $row['date']) . 'Z', 'images' => $row['images'] ? json_decode($row['images'], true) : []
            ];
            
            $isOwnAgency = ($isFullyAuthenticated && $agencyId && $row['agencyId'] === $agencyId);
            
            if ($isOwnAgency) {
                $prop['referrer'] = $row['referrer']; $prop['phone'] = $row['phone']; $prop['phone2'] = $row['phone2'];
                $prop['exactAddress'] = $row['exactAddress']; $prop['internalNote'] = $row['internalNote']; $prop['soldBy'] = $row['soldBy'];
                $prop['price'] = (float)$row['price']; $prop['deposit'] = (float)$row['deposit']; $prop['rent'] = (float)$row['rent'];
            } else {
                if ($row['showPriceGuest']) {
                    $prop['price'] = (float)$row['price']; $prop['deposit'] = (float)$row['deposit']; $prop['rent'] = (float)$row['rent'];
                }
                if (empty($row['showImagesGuest'])) { $prop['images'] = []; }
                if (!$isFullyAuthenticated || $userRole === 'مهمان') {
                    $prop['agencyId'] = 'ag_' . substr(hash('sha256', $row['agencyId'] . APP_SALT), 0, 8);
                }
            }
            $out['properties']->{$row['id']} = $prop; 
        }

        if ($isFullyAuthenticated && $agencyId) {
            $stmt = $pdo->prepare("SELECT * FROM demands WHERE agencyId = ?"); $stmt->execute([$agencyId]);
            while($row = $stmt->fetch()) {
                $row['usage'] = $row['usage_type']; unset($row['usage_type']);
                $row['area'] = (int)$row['area']; 
                $row['budget'] = (float)$row['budget'];
                $row['deposit'] = (float)($row['deposit'] ?? 0);
                $row['rent'] = (float)($row['rent'] ?? 0);
                $row['date'] = str_replace(' ', 'T', $row['date']) . 'Z';
                $out['demands']->{$row['id']} = $row;
            }
            
            $stmt = $pdo->prepare("SELECT id, agencyId, name, role, status, joinedAt, lastSeen FROM members WHERE agencyId = ?"); $stmt->execute([$agencyId]);
            while($row = $stmt->fetch()) { $out['members']->{$row['id']} = $row; }
        }
        
        $out['dataHash'] = $serverHash !== '0' ? $serverHash : md5(time());
        echo json_encode(['response' => $out], JSON_UNESCAPED_UNICODE); exit;
    }
}

// ==========================================
// POST METHODS
// ==========================================
if ($method === 'POST') {
            $rawInput = json_decode(file_get_contents('php://input'), true);
            if (!is_array($rawInput)) $rawInput = [];
            
            // ⚡ ادغام دیتای JSON متنی با دیتای فایل‌های صوتی (حیاتی برای عبور از فایروال)
            $input = sanitizeInput(array_merge($_POST, $rawInput));
            
            // ⚡ تشخیص قطعیِ اکشنِ درخواستی تا سرور گیج نشود
            if (empty($action)) {
                $action = $input['action'] ?? '';
            }

    $secretApiKey = "AmLaK_Super_Secret_2026!";
    if (!isset($input['api_key']) || $input['api_key'] !== $secretApiKey) {
        http_response_code(403);
        echo json_encode(['error' => 'عدم دسترسی! درخواست نامعتبر است.']);
        exit;
    }

    try {
        // 💓 سیستم ضربان قلب (پینگ آنلاین بودن)
        if ($action === 'ping') {
            if ($isFullyAuthenticated && $userRole === 'مشاور') {
                $pdo->prepare("UPDATE members SET lastSeen = ? WHERE name = ? AND agencyId = ?")->execute([time(), $userName, $agencyId]);
            }
            echo json_encode(['response' => ['success' => true]]); 
            exit;
        }
// =====================================
        // =====================================
        // 🤖 مغز متفکر جارویس (نسخه هوشمند و درک مطلب)
        // =====================================
      // ---------------------------------------------------------
        // 🎙️ سیستم تبدیل صوت به متن جارویس (نسخه Hugging Face)
        // ---------------------------------------------------------
        // ---------------------------------------------------------
        // 🎙️ سیستم تبدیل صوت به متن جارویس (Whisper Large v3)
        // ---------------------------------------------------------
        // ---------------------------------------------------------
        // 🎙️ سیستم تبدیل صوت به متن جارویس (Whisper Large v3)
        // ---------------------------------------------------------
        if ($action === 'transcribe_audio') {
            if (strtolower($userPlan) !== 'vip') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'دسترسی غیرمجاز! فرمان صوتی فقط برای مشترکین VIP فعال است.']);
                exit;
            }

            if (!isset($_FILES['audio_file']) || $_FILES['audio_file']['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'فایل صوتی در سرور دریافت نشد یا فرمت نامعتبر است.']);
                exit;
            }

            $hf_api_key = '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'; 
            $fileTmpName = $_FILES['audio_file']['tmp_name'];
            $fileData = file_get_contents($fileTmpName);

            if (empty($fileData)) {
                echo json_encode(['success' => false, 'error' => 'محتوای فایل صوتی خالی است.']);
                exit;
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://api-inference.huggingface.co/models/openai/whisper-large-v3'); 
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $fileData);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Bearer " . $hf_api_key,
                "Content-Type: audio/webm"
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                echo json_encode(['success' => false, 'error' => 'خطای ارتباط با موتور پردازش صوت: ' . $curlError]);
                exit;
            }

            $responseData = json_decode($response, true);

            if ($httpCode === 200 && !empty($responseData['text'])) {
                echo json_encode([
                    'success' => true,
                    'text' => trim($responseData['text'])
                ]);
            } elseif (isset($responseData['error']) && stripos($responseData['error'], 'loading') !== false) {
                echo json_encode([
                    'success' => false,
                    'error' => 'مدل صوتی در حال لود اولیه روی سرور است. لطفاً ۵ ثانیه دیگر مجدداً تلاش فرمایید.'
                ]);
            } else {
                error_log("Whisper API Error (" . $httpCode . "): " . $response);
                echo json_encode([
                    'success' => false,
                    'error' => 'خطا در تبدیل گفتار به متن.',
                    'details' => $responseData['error'] ?? 'خطای ناشناخته'
                ]);
            }
            exit;
        }
        if ($action === 'jarvisProcess') {
            if (strtolower($userPlan) !== 'vip') {
                echo json_encode(['error' => 'دسترسی غیرمجاز! جارویس فقط برای مشترکین VIP فعال است.']);
                exit;
            }

            $userText = "";
            
            // گرفتن دقیق متنی که کاربر تایپ کرده یا با موتور صوتی تبدیل به متن شده
            if (!empty($input['text'])) {
                $userText = trim($input['text']);
            } else {
                echo json_encode(['error' => 'دستوری دریافت نشد. لطفاً صحبت کنید یا تایپ کنید.']); 
                exit;
            }

            $workerUrl = "https://ai.shayan-api.ir/api/v1/chat/completions";
            $apiKey = "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";

            // ⚡ دیکشنری هوشمند: آموزش کلمات و تفکیک داده‌ها به جارویس
            $systemPrompt = 'شما "جارویس" هستید، دستیار فوق‌هوشمند املاک. 
وظیفه شما استخراج دقیق مشخصات ملک از پیام کاربر است.

قوانین تشخیص کاربری (usage):
- "آپارتمان"، "خانه"، "منزل"، "سوییت" -> مسکونی
- "ویلا"، "خانه باغ" -> ویلایی
- "مغازه"، "پاساژ"، "دکان"، "تجاری" -> تجاری
- "دفتر کار"، "مطب"، "شرکت" -> اداری
- "زمین"، "کلنگی"، "خاک" -> زمین/کلنگی
- "باغ"، "باغچه" -> باغ

قوانین واگذاری (dealType):
- خرید / فروش -> فروش
- رهن و اجاره / اجاره -> رهن و اجاره
- رهن کامل -> رهن کامل

قوانین استخراج و تفکیک (بسیار مهم):
۱. نام مالک باید در کلید referrer و تلفن مالک در کلید phone قرار گیرد.
۲. تعداد خواب (rooms)، طبقه (floor) و واحد (unit) حتما باید استخراج شوند و فقط شامل عدد باشند.
۳. امکانات (hasElevator, hasParking, hasStorage) فقط باید true یا false باشند.
۴. سال ساخت (yearBuilt) فقط عدد باشد.
۵. تفکیک آدرس: نام محله یا محدوده کلی را در (location) بنویسید و ادامه آدرس دقیق (خیابان، کوچه، پلاک و...) را در (exactAddress) قرار دهید.
۶. قانون توضیحات: به هیچ وجه اطلاعاتی که در فیلدهای بالا (مثل خواب، طبقه، قیمت، امکانات و...) ثبت کرده‌اید را در کلیدهای (description) و (internalNote) تکرار نکنید! در توضیحات فقط ویژگی‌های اضافه (مثل غرق نور، نیاز به بازسازی، معاوضه با ماشین، ویو ابدی و...) را بنویسید.

شما باید فقط و فقط یک خروجی JSON معتبر برگردانید. تمام کلیدها باید دقیقا مطابق ساختار زیر باشند و برای مقادیر نامشخص از null استفاده کنید (مقادیر عددی را بدون کوتیشن بنویسید):
{
  "ai_message": "پیام تایید کوتاه به فارسی",
  "action": "openPropertyModal",
  "params": {
    "dealType": null,
    "usage": null,
    "area": null,
    "price": null,
    "deposit": null,
    "rent": null,
    "location": null,
    "city": null,
    "exactAddress": null,
    "referrer": null,
    "phone": null,
    "rooms": null,
    "floor": null,
    "unit": null,
    "hasElevator": false,
    "hasParking": false,
    "hasStorage": false,
    "description": null,
    "internalNote": null,
    "yearBuilt": null,
    "buildArea": null
  }
}';

            $data = [
                "model" => "laguna-xs-2.1:free", // مدل قدرتمند، رایگان و هوشمند
                "messages" => [
                    ["role" => "system", "content" => $systemPrompt],
                    ["role" => "user", "content" => $userText]
                ],
                "response_format" => ["type" => "json_object"]
            ];

            $ch = curl_init($workerUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            //curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // حداکثر ۵ ثانیه برای پیدا کردن سرور
            //curl_setopt($ch, CURLOPT_TIMEOUT, 15);       // حداکثر ۱۵ ثانیه برای کل عملیات و دریافت جواب
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'HTTP-Referer: https://test.amlak-e-man.ir'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $aiResult = json_decode($response, true);
            
            if ($httpCode == 200 && isset($aiResult['choices'][0]['message']['content'])) {
                $aiContent = $aiResult['choices'][0]['message']['content'];
                
                // 🧹 پاک‌کننده هوشمند: حذف کدهای تزئینی که هوش مصنوعی تولید می‌کند
                $aiContent = preg_replace('/```json\s*/', '', $aiContent);
                $aiContent = preg_replace('/```\s*/', '', $aiContent);
                $aiContent = trim($aiContent);

                $parsedData = json_decode($aiContent, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    echo json_encode([
                        'response' => [
                            'success' => true,
                            'ai_message' => $parsedData['ai_message'] ?? "آماده شد.",
                            'action' => $parsedData['action'] ?? null,
                            'params' => $parsedData['params'] ?? []
                        ]
                    ]);
                } else {
                    echo json_encode(['error' => 'خطا در خواندن اطلاعات هوش مصنوعی.']);
                }
            // ... (کدهای قبلی)
            } else {
                // 🕵️‍♂️ دیباگر قدرتمند برای پیدا کردن مشکل واقعی
                $curlError = curl_error($ch);
                $errorReason = "کد وضعیت: " . $httpCode . " | ";
                
                if ($curlError) {
                    $errorReason .= "قطعی شبکه: " . $curlError;
                } else {
                    // گرفتن پیام ارور مستقیم از OpenRouter
                    $errorReason .= "پاسخ سرور: " . $response;
                }
                
                echo json_encode(['error' => 'ارور دقیق: ' . $errorReason]);
            }
            exit;
        }
        if ($action === 'changeMyPassword') {
            if (!$isFullyAuthenticated) { echo json_encode(['error' => 'غیرمجاز.']); exit; }
            $newPin = $input['newPin'] ?? '';
            if (!$newPin) { echo json_encode(['error' => 'رمز عبور نمی‌تواند خالی باشد']); exit; }
            $hashed = password_hash($newPin, PASSWORD_DEFAULT);

            if ($userRole === 'مدیر') {
                $pdo->prepare("UPDATE agencies SET adminPin = ? WHERE id = ?")->execute([$hashed, $agencyId]);
            } else if ($userRole === 'مشاور') {
                $pdo->prepare("UPDATE members SET pin = ? WHERE name = ? AND agencyId = ?")->execute([$hashed, $userName, $agencyId]);
            }
            markSystemUpdated($pdo); echo json_encode(['response' => ['success' => true]]); exit;
        }

        if ($action === 'resetMemberPassword') {
            if (!$isFullyAuthenticated || $userRole !== 'مدیر') { echo json_encode(['error' => 'فقط مدیر آژانس مجاز به انجام این عملیات است.']); exit; }
            $memberId = $input['memberId'] ?? '';
            $newPin = $input['newPin'] ?? '';
            if (!$memberId || !$newPin) { echo json_encode(['error' => 'اطلاعات ناقص است.']); exit; }
            
            $hashed = password_hash($newPin, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE members SET pin = ? WHERE id = ? AND agencyId = ?")->execute([$hashed, $memberId, $agencyId]);
            markSystemUpdated($pdo); echo json_encode(['response' => ['success' => true]]); exit;
        }

        if ($action === 'saveAgency') {
            if (!password_verify($input['masterPass'] ?? '', MASTER_PASSWORD_HASH)) { echo json_encode(['error' => 'رمز مالک اشتباه است.']); exit; }
            $hashed = !empty($input['adminPin']) ? password_hash($input['adminPin'], PASSWORD_BCRYPT) : '';
            
            $rawDate = $input['expireAt'] ?? '';
            $expireSql = date('Y-m-d H:i:s'); 
            if (preg_match('/^(\d{4}-\d{2}-\d{2})[T\s](\d{2}:\d{2}:\d{2})/', $rawDate, $matches)) {
                $expireSql = $matches[1] . ' ' . $matches[2];
            }
            
            $stmt = $pdo->prepare("INSERT INTO agencies (id, name, city, phone, phone2, managerName, adminPin, createdAt, expireAt) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name), city=VALUES(city), phone=VALUES(phone), phone2=VALUES(phone2), managerName=VALUES(managerName), adminPin=VALUES(adminPin), expireAt=VALUES(expireAt)");
            $stmt->execute([$input['id'], $input['name'], $input['city'], $input['phone'], $input['phone2'], $input['managerName']??'مدیر', $hashed, date('Y-m-d H:i:s'), $expireSql]);
            markSystemUpdated($pdo); echo json_encode(['response' => ['success' => true]]); exit;
        }

        // 🛡️ در هنگام لاگین، پلن آژانس از دیتابیس خوانده شده و در توکن مُهر و موم می‌شود
        if ($action === 'loginManager') {
            $reqAgencyId = $input['agencyId'] ?? $agencyId;
            if (!$reqAgencyId) { echo json_encode(['error' => 'کد آژانس نامعتبر']); exit; }
            
            $stmt = $pdo->prepare("SELECT adminPin, managerName, name, expireAt, plan_type FROM agencies WHERE id = ?"); $stmt->execute([$reqAgencyId]);
            $ag = $stmt->fetch(); if (!$ag) { echo json_encode(['error' => 'آژانس یافت نشد']); exit; }
            
            if (strtotime($ag['expireAt']) < time()) { echo json_encode(['error' => 'اشتراک آژانس پایان یافته است.']); exit; }

            $isMatch = (password_get_info($ag['adminPin'])['algo'] === 0) ? ($ag['adminPin'] === $input['pin']) : password_verify($input['pin'], $ag['adminPin']);
            if (!$isMatch) { echo json_encode(['error' => 'رمز عبور اشتباه است']); exit; }
            
            echo json_encode(['response' => ['success' => true, 'token' => generateSecureToken($reqAgencyId, 'مدیر', $ag['managerName']?:'مدیر', APP_SALT, $ag['plan_type'] ?? 'Basic'), 'managerName' => $ag['managerName'], 'agencyName' => $ag['name'], 'plan' => $ag['plan_type'] ?? 'Basic']]); exit;
        }

        if ($action === 'loginConsultant') {
            $reqAgencyId = $input['agencyId'] ?? $agencyId;
            if (!$reqAgencyId) { echo json_encode(['error' => 'کد آژانس نامعتبر']); exit; }
            
            $name = $input['name'] ?? ''; $pin = $input['pin'] ?? '';
            if (!$name || !$pin) { echo json_encode(['error' => 'اطلاعات ناقص است.']); exit; }

            $stmtAg = $pdo->prepare("SELECT name, expireAt, plan_type FROM agencies WHERE id = ?"); $stmtAg->execute([$reqAgencyId]);
            $ag = $stmtAg->fetch();
            if (!$ag) { echo json_encode(['error' => 'آژانس یافت نشد']); exit; }
            if (strtotime($ag['expireAt']) < time()) { echo json_encode(['error' => 'اشتراک آژانس پایان یافته است.']); exit; }

            $stmt = $pdo->prepare("SELECT id, pin, status FROM members WHERE name = ? AND agencyId = ?"); $stmt->execute([$name, $reqAgencyId]);
            $mem = $stmt->fetch();

            if ($mem) {
                $isMatch = (password_get_info($mem['pin'])['algo'] === 0) ? ($mem['pin'] === $pin) : password_verify($pin, $mem['pin']);
                if (!$isMatch) { echo json_encode(['error' => 'رمز عبور اشتباه است.']); exit; }
                if ($mem['status'] === 'blocked') { echo json_encode(['error' => 'حساب مسدود است.']); exit; }
                if ($mem['status'] === 'pending') { echo json_encode(['error' => 'حساب در انتظار تایید مدیر است.']); exit; }
                
                $pdo->prepare("UPDATE members SET lastSeen = ? WHERE id = ?")->execute([time(), $mem['id']]);
                echo json_encode(['response' => ['success' => true, 'token' => generateSecureToken($reqAgencyId, 'مشاور', $name, APP_SALT, $ag['plan_type'] ?? 'Basic'), 'id' => $mem['id'], 'agencyName' => $ag['name'], 'plan' => $ag['plan_type'] ?? 'Basic']]); exit;
            } else {
                $mId = uniqid('mem_');
                $pdo->prepare("INSERT INTO members (id, agencyId, name, role, status, pin, joinedAt, lastSeen) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$mId, $reqAgencyId, $name, 'مشاور', 'pending', password_hash($pin, PASSWORD_BCRYPT), date('Y-m-d H:i:s'), time()]);
                markSystemUpdated($pdo);
                echo json_encode(['response' => ['status' => 'pending_sent', 'message' => 'ثبت‌نام انجام شد! منتظر تایید بمانید.', 'agencyName' => $ag['name']]]); exit;
            }
        }
        
        if (!$isFullyAuthenticated) { echo json_encode(['error' => 'غیرمجاز.']); exit; }

        if ($action === 'getNotes') {
            $stmt = $pdo->prepare("SELECT note_text FROM personal_notes WHERE agencyId = ? AND username = ?");
            $stmt->execute([$agencyId, $userName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['response' => ['note_text' => $row ? $row['note_text'] : '']]);
            exit;
        }

        if ($action === 'saveNotes') {
            $note_text = '';
            if (isset($input['note_text'])) { $note_text = $input['note_text']; }
            elseif (isset($input['data']['note_text'])) { $note_text = $input['data']['note_text']; }
            elseif (isset($rawInput['note_text'])) { $note_text = $rawInput['note_text']; }
            
            $stmt = $pdo->prepare("INSERT INTO personal_notes (agencyId, username, note_text) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE note_text = ?");
            $stmt->execute([$agencyId, $userName, $note_text, $note_text]);
            echo json_encode(['response' => ['success' => true]]);
            exit;
        }
      
        if ($action === 'saveMember') {
            if ($userRole !== 'مدیر') { echo json_encode(['error' => 'غیرمجاز']); exit; }
            $pdo->prepare("UPDATE members SET status = ? WHERE id = ? AND agencyId = ?")->execute([$input['status'], $input['id'], $agencyId]);
            markSystemUpdated($pdo); echo json_encode(['response' => ['success' => true]]); exit;
        }

        if ($action === 'saveProperty') {
            $id = $input['id'] ?? uniqid('prop_');
            $stmtCheck = $pdo->prepare("SELECT agencyId FROM properties WHERE id = ?"); $stmtCheck->execute([$id]);
            $existing = $stmtCheck->fetch();
            if ($existing && $existing['agencyId'] !== $agencyId) { echo json_encode(['error' => 'شما مجوز دسترسی به این ملک را ندارید.']); exit; }

            $imagePaths = [];
            $uploadDir = __DIR__ . '/uploads/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }

            if (!empty($input['images']) && is_array($input['images'])) {
                foreach ($input['images'] as $index => $base64OrUrl) {
                    if (strpos($base64OrUrl, 'data:image') === 0) {
                        if (stripos($base64OrUrl, 'svg') !== false || stripos($base64OrUrl, 'xml') !== false) continue;

                        $parts = explode(',', $base64OrUrl);
                        if (count($parts) == 2) {
                            $imgData = base64_decode($parts[1]);
                            $fileName = 'prop_' . $agencyId . '_' . time() . '_' . $index . '_' . uniqid() . '.jpg';
                            $filePath = $uploadDir . $fileName;

                            $imageResource = @imagecreatefromstring($imgData);
                            if ($imageResource !== false) {
                                $width = imagesx($imageResource);
                                $height = imagesy($imageResource);
                                $maxWidth = 800; 
                                
                                if ($width > $maxWidth) {
                                    $newWidth = $maxWidth;
                                    $newHeight = floor($height * ($maxWidth / $width));
                                    $newImage = imagecreatetruecolor($newWidth, $newHeight);
                                    $white = imagecolorallocate($newImage, 255, 255, 255);
                                    imagefill($newImage, 0, 0, $white);
                                    imagecopyresampled($newImage, $imageResource, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                                    imagejpeg($newImage, $filePath, 70); 
                                    imagedestroy($newImage);
                                } else {
                                    imagejpeg($imageResource, $filePath, 70);
                                }
                                imagedestroy($imageResource);
                                $imagePaths[] = 'uploads/' . $fileName;
                            }
                        }
                    } else {
                        $imagePaths[] = $base64OrUrl;
                    }
                }
            }
            $imgsJson = json_encode($imagePaths);
            $sqlDate = formatSqlDate($input['date'] ?? null);

            $sql = "INSERT INTO properties (id, agencyId, authorName, status, referrer, phone, phone2, city, location, exactAddress, lat, lng, usage_type, area, buildArea, rooms, floor, unit, yearBuilt, hasParking, hasElevator, hasStorage, dealType, price, deposit, rent, description, internalNote, canExchange, canPartner, isPreSale, isVIP, showToGuest, showPriceGuest, showImagesGuest, date, soldBy, images) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status), referrer=VALUES(referrer), phone=VALUES(phone), phone2=VALUES(phone2), city=VALUES(city), location=VALUES(location), exactAddress=VALUES(exactAddress), lat=VALUES(lat), lng=VALUES(lng), usage_type=VALUES(usage_type), area=VALUES(area), buildArea=VALUES(buildArea), rooms=VALUES(rooms), floor=VALUES(floor), unit=VALUES(unit), yearBuilt=VALUES(yearBuilt), hasParking=VALUES(hasParking), hasElevator=VALUES(hasElevator), hasStorage=VALUES(hasStorage), dealType=VALUES(dealType), price=VALUES(price), deposit=VALUES(deposit), rent=VALUES(rent), description=VALUES(description), internalNote=VALUES(internalNote), canExchange=VALUES(canExchange), canPartner=VALUES(canPartner), isPreSale=VALUES(isPreSale), isVIP=VALUES(isVIP), showToGuest=VALUES(showToGuest), showPriceGuest=VALUES(showPriceGuest), showImagesGuest=VALUES(showImagesGuest), date=VALUES(date), soldBy=VALUES(soldBy), images=VALUES(images)";
            
            $pdo->prepare($sql)->execute([
                $id, $agencyId, $userName, $input['status']??'موجود', $input['referrer']??'', $input['phone']??'', $input['phone2']??'', 
                $input['city']??'', $input['location']??'', $input['exactAddress']??'', $input['lat']??'', $input['lng']??'', 
                $input['usage']??'', (int)($input['area']??0), (int)($input['buildArea']??0), $input['rooms']??'', $input['floor']??'', $input['unit']??'', $input['yearBuilt']??'', 
                !empty($input['hasParking'])?1:0, !empty($input['hasElevator'])?1:0, !empty($input['hasStorage'])?1:0, 
                $input['dealType']??'', (float)($input['price']??0), (float)($input['deposit']??0), (float)($input['rent']??0), 
                $input['description']??'', $input['internalNote']??'', !empty($input['canExchange'])?1:0, 
                !empty($input['canPartner'])?1:0, !empty($input['isPreSale'])?1:0, !empty($input['isVIP'])?1:0, 
                !empty($input['showToGuest'])?1:0, !empty($input['showPriceGuest'])?1:0, !empty($input['showImagesGuest'])?1:0, 
                $sqlDate, $input['soldBy']??null, $imgsJson
            ]);
            markSystemUpdated($pdo); echo json_encode(['response' => ['success' => true, 'id' => $id]]); exit;
        }

        if ($action === 'saveDemand') {
            $id = $input['id'] ?? uniqid('dem_');
            $sqlDate = formatSqlDate($input['date'] ?? null);
            
            $sql = "INSERT INTO demands (id, agencyId, authorName, clientName, clientPhone, clientPhone2, city, usage_type, dealType, area, budget, deposit, rent, description, followUpDate, date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE clientName=VALUES(clientName), clientPhone=VALUES(clientPhone), clientPhone2=VALUES(clientPhone2), city=VALUES(city), usage_type=VALUES(usage_type), dealType=VALUES(dealType), area=VALUES(area), budget=VALUES(budget), deposit=VALUES(deposit), rent=VALUES(rent), description=VALUES(description), followUpDate=VALUES(followUpDate)";
            
            $pdo->prepare($sql)->execute([
                $id, $agencyId, $userName, $input['clientName']??'', $input['clientPhone']??'', $input['clientPhone2']??'', 
                $input['city']??'', $input['usage']??'', $input['dealType']??'', (int)($input['area']??0), 
                (float)($input['budget']??0), (float)($input['deposit']??0), (float)($input['rent']??0), 
                $input['desc']??'', $input['followUpDate']??'', $sqlDate
            ]);
            
            markSystemUpdated($pdo); echo json_encode(['response' => ['success' => true, 'id' => $id]]); exit;
        }

        if (in_array($action, ['deleteProperty', 'deleteDemand', 'deleteMember'])) {
            if ($userRole !== 'مدیر') { echo json_encode(['error' => 'دسترسی محدود']); exit; }
            $id = $input['id']; $table = str_replace('delete', '', strtolower($action));
            if ($table === 'property') $table = 'properties'; elseif ($table === 'demand') $table = 'demands'; elseif ($table === 'member') $table = 'members';
            
            if ($table === 'properties') {
                $stmt = $pdo->prepare("SELECT images FROM properties WHERE id = ? AND agencyId = ?");
                $stmt->execute([$id, $agencyId]);
                $prop = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($prop && !empty($prop['images'])) {
                    $imgs = json_decode($prop['images'], true);
                    if (is_array($imgs)) {
                        foreach ($imgs as $img) {
                            if (strpos($img, 'uploads/') === 0 && file_exists(__DIR__ . '/' . $img)) {
                                unlink(__DIR__ . '/' . $img); 
                            }
                        }
                    }
                }
            }
            $pdo->prepare("DELETE FROM {$table} WHERE id = ? AND agencyId = ?")->execute([$id, $agencyId]);
            markSystemUpdated($pdo); echo json_encode(['response' => ['success' => true]]); exit;
        }
        
    } catch (PDOException $e) {
        http_response_code(500); 
        echo json_encode(['error' => 'خطای پایگاه داده: ' . $e->getMessage()]); 
        exit;
    }
}
echo json_encode(['error' => 'عملیات یافت نشد']);
?>