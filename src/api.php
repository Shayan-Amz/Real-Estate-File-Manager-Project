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
require_once __DIR__ . '/stt.php';   // 🎙️ موتور تبدیل گفتار به متن (جارویس)

header('Content-Type: application/json; charset=utf-8');
// 🛡️ CORS: قبلاً '*' بود، یعنی هر سایتی می‌توانست به این API درخواست بدهد.
//    ALLOWED_ORIGIN = ''             → فقط Origin هم‌دامنه با خودِ سرور (پیش‌فرض امن)
//    ALLOWED_ORIGIN = 'a.ir, b.ir'   → فقط همین دامنه‌ها
//    ALLOWED_ORIGIN = '*'            → همه (توصیه نمی‌شود)
$__acao = '';
$__origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (ALLOWED_ORIGIN === '*') {
    $__acao = '*';
} elseif (trim(ALLOWED_ORIGIN) !== '') {
    foreach (array_map('trim', explode(',', ALLOWED_ORIGIN)) as $__o) {
        if ($__o !== '' && strcasecmp(rtrim($__o, '/'), rtrim($__origin, '/')) === 0) { $__acao = $__origin; break; }
    }
} elseif ($__origin !== '') {
    $__srvHost = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
    if (strcasecmp((string) parse_url($__origin, PHP_URL_HOST), $__srvHost) === 0) { $__acao = $__origin; }
}
if ($__acao !== '') { header('Access-Control-Allow-Origin: ' . $__acao); header('Vary: Origin'); }
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://unpkg.com https://cdnjs.cloudflare.com; img-src 'self' data: https://*.tile.openstreetmap.org https://*.google.com;");
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Agency-ID, X-User-Role, X-Auth-Token, X-User-Name, X-Data-Hash, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
if (!file_exists($uploadDir . 'index.php')) { file_put_contents($uploadDir . 'index.php', '<?php // Silence is golden. ?>'); }

// 🛡️ خودترمیمی: اگر uploads/.htaccess گم شده باشد (مثلاً هنگام Extract زیپ
//    فایل‌های نقطه‌دار جا مانده‌اند)، همین‌جا ساخته می‌شود تا اجرای اسکریپت در
//    پوشهٔ عکس‌ها همیشه مسدود بماند. محتوایش با src/uploads/.htaccess یکسان است.
if (!file_exists($uploadDir . '.htaccess')) {
    @file_put_contents($uploadDir . '.htaccess',
        "# ⚡ پوشهٔ آپلود: فقط فایل داده، هیچ اسکریپتی اجرا نشود\n"
        . "Options -Indexes\n\n"
        . "<IfModule mod_authz_core.c>\n"
        . "    <FilesMatch \"\\.(php|phtml|php5|php7|phps|phar|pl|py|cgi|sh)$\">\n"
        . "        Require all denied\n"
        . "    </FilesMatch>\n"
        . "</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n"
        . "    <FilesMatch \"\\.(php|phtml|php5|php7|phps|phar|pl|py|cgi|sh)$\">\n"
        . "        Order allow,deny\n"
        . "        Deny from all\n"
        . "    </FilesMatch>\n"
        . "</IfModule>\n\n"
        . "<IfModule mod_mime.c>\n"
        . "    RemoveHandler  .php .phtml .php5 .php7 .phps .phar\n"
        . "    RemoveType     .php .phtml .php5 .php7 .phps .phar\n"
        . "    SetHandler     none\n"
        . "</IfModule>\n");
}

if (!defined('TRUST_PROXY_HEADER')) define('TRUST_PROXY_HEADER', false);

/**
 * 🛡️ آی‌پی واقعی کلاینت.
 * قبلاً X-Forwarded-For بدون هیچ بررسی‌ای قبول می‌شد؛ یعنی هر کسی می‌توانست
 * با فرستادن یک هدر جعلی، محدودیت نرخ را کاملاً دور بزند.
 * حالا فقط وقتی به هدرهای forwarding اعتماد می‌شود که TRUST_PROXY_HEADER
 * در config.php روی true باشد (یعنی سرور واقعاً پشت Cloudflare/پروکسی است).
 */
function getRealIp() {
    $remote = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!TRUST_PROXY_HEADER) return $remote;
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        if (!empty($ips[0]) && filter_var($ips[0], FILTER_VALIDATE_IP)) return $ips[0];
    }
    return $remote;
}

/**
 * 🛡️ کلید محدودیت نرخ.
 * قبلاً فقط آی‌پی بود و دو مشکل داشت: همهٔ کاربران پشت یک NAT با هم قفل
 * می‌شدند، و با هدر جعلی قابل دور زدن بود. حالا آی‌پی + آژانس + توکن کاربر
 * با هم هش می‌شوند. طول ۱۵ کاراکتر تا در ستون ip با هر طولی جا شود.
 */
function rateLimitKey($ip) {
    $extra = ($_SERVER['HTTP_X_AGENCY_ID'] ?? '') . '|' . ($_SERVER['HTTP_X_AUTH_TOKEN'] ?? '');
    return substr(hash('sha256', $ip . '|' . $extra), 0, 15);
}

/**
 * 🛡️ فقط مسیرهای واقعاً داخل پوشهٔ uploads را قبول می‌کند.
 * خروجی: همان مسیر نسبی (مثل uploads/prop_x.jpg) یا null.
 * این جلوی «پاک کردن فایل دلخواه» را می‌گیرد: رشته‌ای مثل
 * uploads/../../config.php قبلاً هم ذخیره می‌شد و هم به unlink می‌رسید.
 */
function safeUploadRelPath($p) {
    if (!is_string($p)) return null;
    $p = trim($p);
    // ⚠️ عمداً کلاس کاراکتر سخت‌گیرانه (فقط a-z0-9_-) نگذاشتم: agencyId از
    //    ورودی کاربر می‌آید و هیچ‌جا اعتبارسنجی نمی‌شود، پس ممکن است فارسی یا
    //    فاصله داشته باشد و مسیر عکس‌های مشروع رد می‌شد.
    //    در عوض «/»، «\» و کاراکترهای کنترلی ممنوع‌اند — پس پیمایش به بالا
    //    اصولاً ممکن نیست — و پسوند هم باید دقیقاً در انتهای رشته یک عکس باشد.
    if (!preg_match('#^uploads/([^/\\\\\x00-\x1f]{1,200}\.(?:jpg|jpeg|png|webp|gif))$#i', $p, $m)) return null;
    $name = $m[1];
    if ($name === '' || $name[0] === '.' || strpos($name, '..') !== false) return null;
    return $p;
}

/**
 * 🔑 تطبیق پین/رمز اکانت‌ها (آژانس و مشاور) — «دقیقاً» با همان چیزی که کاربر
 * گذاشته:
 *   - اگر در دیتابیس هش bcrypt ذخیره شده (حساب‌های قدیمی) → password_verify
 *   - اگر پین ساده/متنی ذخیره شده (روش فعلی) → مقایسهٔ دقیق hash_equals
 * یعنی «A123» فقط با «A123» وارد می‌شود — بدون escape، بدون تبدیل، بدون هش اضافه.
 *
 * ⚠️ این تابع فقط برای پین اکانت‌هاست. رمز «مالک سیستم» کاملاً جدا است و
 *    فقط از MASTER_PASSWORD_HASH (تعریف config.php) می‌آید و دست نمی‌خورد.
 */
function pin_matches($stored, $candidate) {
    if ($stored === null || $stored === '') return false;
    $stored    = (string)$stored;
    $candidate = (string)$candidate;
    if (preg_match('/^\$2[aby]\$\d{2}\$/', $stored) && strlen($stored) === 60) {
        return password_verify($candidate, $stored);
    }
    return hash_equals($stored, $candidate);
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

    // ⚡ هر بار که ساختار دیتابیس عوض شد این عدد را یکی زیاد کن تا
    //    migration دوباره اجرا شود.
    if (!defined('SCHEMA_VERSION')) define('SCHEMA_VERSION', 5);

    try {
        // ⚡ جدول تنظیمات سیستم: مثل rate_limits هیچ‌جا ساخته نمی‌شد، ولی
        //    markSystemUpdated و getData و پنل مدیریت همه به آن تکیه دارند.
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_config (
            conf_key VARCHAR(64) NOT NULL,
            conf_val VARCHAR(255) NOT NULL,
            PRIMARY KEY (conf_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // ⚡ قبلاً در هر ریکوئست یک SHOW TABLES + هشت «SELECT ... LIMIT 1» +
        //    دو CREATE TABLE اجرا می‌شد (تقریباً ۱۱ کوئری اضافه بر هر درخواست،
        //    و index.html هر ۱۵ ثانیه درخواست می‌زند). حالا فقط یک SELECT سبک
        //    است و بقیهٔ بلوک فقط وقتی نسخهٔ ساختار عوض شده باشد اجرا می‌شود.
        $__sv = $pdo->query("SELECT conf_val FROM sys_config WHERE conf_key = 'schema_version'")->fetchColumn();
        if ((int) $__sv !== SCHEMA_VERSION) {

        // ⚡ این جدول هیچ‌جا ساخته نمی‌شد! فقط DELETE/INSERT/SELECT رویش بود.
        //    روی دیتابیس تازه، همان اولین ریکوئست PDOException می‌گرفت و
        //    کل API با ۵۰۰ «خطا در اتصال به دیتابیس» می‌خوابید.
        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            id INT(11) NOT NULL AUTO_INCREMENT,
            ip VARCHAR(64) NOT NULL,
            request_time INT(11) NOT NULL,
            PRIMARY KEY (id),
            KEY idx_ip (ip),
            KEY idx_time (request_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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

            // ⚡ جدول یادداشت‌های شخصی: قبلاً هیچ‌جا ساخته نمی‌شد و اولین فراخوانی
            //    getNotes/saveNotes با PDOException و خطای ۵۰۰ می‌مرد.
            //    کلید UNIQUE برای کارکرد صحیح ON DUPLICATE KEY UPDATE ضروری است؛
            //    بدون آن هر بار یک ردیف جدید درج می‌شد.
            $pdo->exec("CREATE TABLE IF NOT EXISTS personal_notes (
                id INT(11) NOT NULL AUTO_INCREMENT,
                agencyId VARCHAR(50) NOT NULL,
                username VARCHAR(191) NOT NULL,
                note_text MEDIUMTEXT,
                updatedAt TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_agency_user (agencyId, username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // ⚡ جدول personal_notes روی این دیتابیس از قبل وجود داشت (نسخهٔ
            //    قدیمی که schema_version در آن ثبت نشده بود) ولی بدون کلید UNIQUE.
            //    بدون این اصلاح، saveNotes هر بار یک ردیف جدید می‌ساخت.
            //    ⚠️ نسخهٔ اول این بلوک از «DELETE t1 FROM ... JOIN ...» استفاده
            //    می‌کرد که روی MySQL/MariaDB هاست با خطای
            //    «1054 Unknown column 't1.id' in 'WHERE'» رد می‌شد. حالا با یک
            //    روش استاندارد و قابل حمل (حذف به‌ازای هر گروه تکراری) انجام
            //    می‌شود — روی هر نسخهٔ MySQL/MariaDB کار می‌کند.
            //    این بخش در try/catch است: اگر دیتابیس اجازهٔ ALTER را ندهد،
            //    بقیهٔ migration از کار نمی‌افتد.
            try {
                $__uniq = false;
                foreach ($pdo->query("SHOW INDEX FROM personal_notes") as $ix) {
                    if ((int)$ix['Non_unique'] === 0 && $ix['Key_name'] !== 'PRIMARY') { $__uniq = true; break; }
                }
                if (!$__uniq) {
                    // ستون کلید اولیه را از SHOW INDEX پیدا می‌کنیم (انعطاف‌پذیر)
                    $__pk = 'id';
                    foreach ($pdo->query("SHOW INDEX FROM personal_notes") as $__ix) {
                        if (($__ix['Key_name'] ?? '') === 'PRIMARY') { $__pk = $__ix['Column_name']; break; }
                    }
                    // جفت‌های تکراری را پیدا کن؛ برای هر جفت فقط کمترین id را نگه می‌داریم
                    $__dups = $pdo->query("SELECT agencyId, username, MIN(`$__pk`) AS keep_id, COUNT(*) AS c
                                           FROM personal_notes
                                           GROUP BY agencyId, username
                                           HAVING COUNT(*) > 1")->fetchAll();
                    $__del = $pdo->prepare("DELETE FROM personal_notes
                                            WHERE agencyId = ? AND username = ? AND `$__pk` <> ?");
                    $__removed = 0;
                    foreach ($__dups as $__d) {
                        $__del->execute([$__d['agencyId'], $__d['username'], $__d['keep_id']]);
                        $__removed += (int)$__del->rowCount();
                    }
                    $pdo->exec("ALTER TABLE personal_notes ADD UNIQUE KEY uniq_agency_user (agencyId, username)");
                    error_log('[api.php] migration: ' . $__removed . ' ردیف تکراری حذف و کلید UNIQUE اضافه شد');
                }
            } catch (Throwable $__u) {
                error_log('[api.php] migration (UNIQUE personal_notes): ' . $__u->getMessage());
            }

            // ⚡ ثبت نسخهٔ ساختار تا این بلوک در ریکوئست‌های بعدی رد شود.
            //    عمداً داخل «if properties exists» است: اگر دیتابیس خالی بود
            //    نسخه ثبت نشود تا در اجرای بعدی دوباره تلاش شود.
            $pdo->prepare("INSERT INTO sys_config (conf_key, conf_val) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE conf_val = VALUES(conf_val)")->execute([(string) SCHEMA_VERSION]);

        } // ← پایان «if properties exists»
        } // ← پایان دروازهٔ نسخهٔ ساختار
    } catch (Throwable $e) { error_log('[api.php] migration: ' . $e->getMessage()); }

    $ip = getRealIp();
    $rateKey = rateLimitKey($ip);
    $now = time();
    // 🛡️ محدودیت نرخ داخل try/c riêng است: اگر جدول rate_limits خراب یا
    //    ستون ip کوتاه‌تر از حد انتظار بود، نباید کل API با ۵۰۰ بخوابد.
    //    در آن حالت fail-open می‌شویم (سایت کار می‌کند، فقط بدون محدودیت نرخ)
    //    و خطا در error_log ثبت می‌شود.
    try {
        // ⚡ پاک‌سازی سطرهای قدیمی دیگر در هر ریکوئست اجرا نمی‌شود (۱ از ۲۰)؛
        //    قبلاً روی هر درخواست یک DELETE تمام‌جدولی می‌خورد.
        if (random_int(1, 20) === 1) {
            $pdo->prepare("DELETE FROM rate_limits WHERE request_time < ?")->execute([$now - 300]);
        }
        $pdo->prepare("INSERT INTO rate_limits (ip, request_time) VALUES (?, ?)")->execute([$rateKey, $now]);

        $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM rate_limits WHERE ip = ? AND request_time > ?");
        $stmtCnt->execute([$rateKey, $now - 60]);
        if ($stmtCnt->fetchColumn() > 150) { http_response_code(429); echo json_encode(['error' => 'سیستم ضد ربات فعال شد.']); exit; }
    } catch (PDOException $e) {
        error_log('[api.php] rate_limits ناموفق بود (محدودیت نرخ غیرفعال): ' . $e->getMessage());
    }

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
                // 🐛 ستون دیتابیس «description» است ولی فرانت‌اند در دو جا
                //    «d.desc» می‌خواند (جستجو در خط ۲۷۱۷ و کارت در خط ۲۸۴۸)،
                //    پس توضیحات تقاضا هرگز نمایش داده نمی‌شد و جستجو هم پیدایش
                //    نمی‌کرد. هر دو کلید برگردانده می‌شود تا سازگار بماند.
                $row['desc'] = $row['description'] ?? '';
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
            $input = array_merge($_POST, $rawInput);
            // ⚠️ اصلاح حیاتی: رمز/پین‌ها نباید از sanitizeInput عبور کنند.
            //    htmlspecialchars روی کاراکترهایی مثل & < > " ' اثر می‌گذارد؛
            //    اگر پین ذخیره‌شده با مقدار «خام» hash شده باشد (مثلاً از طریق
            //    پنل لایسنس)، ورود همیشه «رمز عبور اشتباه است» می‌دهد.
            //    رمزها پس از login/save به‌صورت bcrypt ذخیره می‌شوند، پس
            //    sanitize روی آن‌ها فقط رفتار را می‌شکند (طبق OWASP برای
            //    password fields این غلط است).
            foreach ($input as $__k => $__v) {
                if (in_array($__k, ['pin', 'newPin', 'adminPin', 'masterPass', 'password', 'new_pass'], true)) continue;
                $input[$__k] = sanitizeInput($__v);
            }
            
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
        // 🎙️ تبدیل گفتار به متن — منطق کامل در stt.php است (چندارائه‌دهنده)
        if ($action === 'transcribe_audio') {
            if (strtolower($userPlan) !== 'vip') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'دسترسی غیرمجاز! فرمان صوتی فقط برای مشترکین VIP فعال است. (plan_type آژانس باید vip باشد)']);
                exit;
            }

            $stt = stt_transcribe($_FILES['audio_file'] ?? null);

            if ($stt['ok']) {
                echo json_encode(['success' => true, 'text' => $stt['text'], 'provider' => $stt['provider']], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(502);
                echo json_encode(['success' => false, 'error' => $stt['error'], 'provider' => $stt['provider']], JSON_UNESCAPED_UNICODE);
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

            // ⚡ آدرس و کلید به config.php منتقل شد (قبلاً hardcoded و داخل گیت بود)
            $apiKey = defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '';

            // 🛡️ زنجیرهٔ آدرس‌ها: اول آنچه در config.php است، بعد آدرس رسمی
            //    OpenRouter. دلیلش: آدرس فعلی یک پروکسی شخصی است
            //    (ai.shayan-api.ir) که «403 Access denied by security policy»
            //    می‌دهد — یعنی خودِ پروکسی درخواست را رد می‌کند، نه OpenRouter.
            //    با این زنجیره، حتی اگر config.php دست‌نخورده بماند کار می‌کند.
            $jarvisEndpoints = array_values(array_unique(array_filter([
                (defined('OPENROUTER_URL') && trim(OPENROUTER_URL) !== '') ? trim(OPENROUTER_URL) : null,
                'https://openrouter.ai/api/v1/chat/completions',
            ])));

            // 🛡️ زنجیرهٔ مدل‌ها. OpenRouter با آرایهٔ «models» خودش مدل بعدی را
            //    امتحان می‌کند اگر اولی نرخ‌خور یا در دسترس نبود.
            //    نکته: شناسهٔ مدل در OpenRouter حتماً «ارائه‌دهنده/مدل» است؛
            //    مقدار قبلی «laguna-xs-2.1:free» پیشوند نداشت و نامعتبر بود.
            $cfgModel = (defined('OPENROUTER_MODEL') && trim(OPENROUTER_MODEL) !== '') ? trim(OPENROUTER_MODEL) : '';
            // شناسهٔ بدون «/» نامعتبر است و اگر داخل آرایهٔ models برود ممکن است
            // کل درخواست ۴۰۰ شود — پس ردش می‌کنیم و به لاگ می‌نویسیم.
            if ($cfgModel !== '' && strpos($cfgModel, '/') === false) {
                error_log('[Jarvis] OPENROUTER_MODEL نامعتبر نادیده گرفته شد: ' . $cfgModel);
                $cfgModel = '';
            }
            // 🎯 فقط یک مدل: همان که خودت در OPENROUTER_MODEL گذاشته‌ای.
            //    (طبق درخواست، لیست جایگزین حذف شد. اگر این مدل منسوخ یا
            //    شلوغ شود جارویس کار نمی‌کند و باید مقدار config.php عوض شود.)
            $jarvisModel = ($cfgModel !== '') ? $cfgModel : 'google/gemma-4-26b-a4b-it:free';
            if (strpos($jarvisModel, '/') === false) {
                // شناسهٔ بدون پیشوند ارائه‌دهنده معتبر نیست (مثل laguna-xs-2.1:free)
                error_log('[Jarvis] OPENROUTER_MODEL پیشوند ارائه‌دهنده ندارد: ' . $jarvisModel);
            }

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
                "model"  => $jarvisModel,
                // ⚡ لایهٔ ارائه‌دهنده: اگر یک upstream شلوغ بود، همان مدل را روی
                //    یک ارائه‌دهندهٔ دیگر امتحان کند. پیش‌فرض روشن است ولی صریح
                //    می‌گذاریم تا به تنظیمات اکانت وابسته نباشد.
                "provider" => ["allow_fallbacks" => true],
                "messages" => [
                    ["role" => "system", "content" => $systemPrompt],
                    ["role" => "user", "content" => $userText]
                ],
                "response_format" => ["type" => "json_object"]
            ];

            $response = null; $httpCode = 0; $curlError = ''; $usedUrl = ''; $usedModel = '';
            $attemptLog = [];
            // 🛡️ حالا روی «مدل‌ها» هم می‌چرخیم، نه فقط آدرس‌ها. قبلاً همیشه
            //    همان مدل اول (از config.php) فرستاده می‌شد و اگر نامعتبر بود
            //    ۴۰۰ می‌گرفتیم و تمام — آرایهٔ models نجاتش نمی‌داد چون ۴۰۰
            //    خطای اعتبارسنجی است و قبل از routing رخ می‌دهد.
            $maxAttempts   = 2;      // یک تلاش + یک تلاش بدون response_format
            $attempt       = 0;
            $useJsonFormat = true;   // بعد از اولین ۴۰۰ خاموش می‌شود
            foreach ($jarvisEndpoints as $ep) {
                if ($attempt >= $maxAttempts) break;
                $attempt++;
                if (!$useJsonFormat) { unset($data['response_format']); }

                $ch = curl_init($ep);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);   // ⚡ قبلاً false بود (این درخواست کلید API را حمل می‌کند)
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_MAXREDIRS, 2);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, STT_CONNECT_TIMEOUT); // ⚡ قبلاً بی‌نهایت بود و ورکر PHP را اشغال می‌کرد
                curl_setopt($ch, CURLOPT_TIMEOUT, STT_TIMEOUT);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
                    'HTTP-Referer: https://test.amlak-e-man.ir',
                    'X-Title: Amlak Man Jarvis'
                ]);

                $r  = curl_exec($ch);
                $hc = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $ce = curl_error($ch);   // ⚡ باید قبل از curl_close خوانده شود؛ قبلاً بعد از آن خوانده می‌شد و همیشه خالی بود
                curl_close($ch);

                $attemptLog[] = (string) parse_url($ep, PHP_URL_HOST) . ' → HTTP ' . $hc . ($ce !== '' ? ' (' . $ce . ')' : '');
                $response = $r; $httpCode = $hc; $curlError = $ce; $usedUrl = $ep; $usedModel = $jarvisModel;

                if ($hc === 200) break;                 // موفق
                // ۴۰۱/۴۰۲ سطح «اکانت» هستند؛ آدرس دیگر کمکی نمی‌کند
                // و فقط یک سهمیهٔ دیگر از سقف روزانه هدر می‌دهد.
                if (in_array($hc, [401, 402], true)) break;
                // ۴۰۰ ممکن است از response_format باشد؛ یک بار بدون آن امتحان می‌کنیم
                if ($hc === 400 && $useJsonFormat) { $useJsonFormat = false; }
            }
            // 🛡️ جزئیات هر تلاش در لاگ سرور
            error_log('[Jarvis] ' . implode(' | ', $attemptLog));

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
                // ⚡ جزئیات فقط در لاگ سرور؛ پاسخ خام ارائه‌دهنده به کلاینت نشت نمی‌کند
                error_log('[Jarvis] HTTP ' . $httpCode . ' curlErr=' . $curlError . ' body=' . substr((string) $response, 0, 800));

                // 🛡️ تشخیص دقیق‌تر: ۴۰۳ از یک پروکسی شخصی با ۴۰۳ از خود
                //    OpenRouter یکی نیست. اولی یعنی پروکسی/WAF رد کرده،
                //    دومی یعنی کلید واقعاً باطل است.
                $failedHost = (string) parse_url((string) $usedUrl, PHP_URL_HOST);
                $errorReason = 'کد وضعیت: ' . $httpCode . ' از ' . ($failedHost !== '' ? $failedHost : 'نامشخص');
                if ($curlError !== '') {
                    $errorReason .= ' | قطعی شبکه: ' . $curlError;
                } elseif ($httpCode === 401) {
                    $errorReason .= ' | کلید OPENROUTER_API_KEY باطل است.';
                } elseif ($httpCode === 403) {
                    $errorReason .= (strpos($failedHost, 'openrouter.ai') === false)
                        ? ' | پروکسی/WAF درخواست را رد کرده (کلید لزوماً مشکل ندارد).'
                        : ' | کلید OPENROUTER_API_KEY دسترسی ندارد.';
                } elseif ($httpCode === 402) {
                    $errorReason .= ' | اعتبار اکانت OpenRouter تمام شده.';
                } elseif ($httpCode === 429) {
                    $errorReason .= ' | سقف درخواست رایگان پر شده (بدون شارژ: ۵۰ درخواست در روز).';
                } elseif ($httpCode === 400 || $httpCode === 404) {
                    $errorReason .= ' | مدل «' . $usedModel . '» معتبر نیست یا پارامتر نامعتبر است.';
                    // 🔎 تکه‌ای از پاسخ ارائه‌دهنده تا علت دقیق معلوم شود.
                    //    پاسخ OpenRouter رازی ندارد (برخلاف خطای دیتابیس).
                    $snippet = trim((string) preg_replace('/\s+/', ' ', (string) $response));
                    if ($snippet !== '') $errorReason .= ' | پاسخ: ' . mb_substr($snippet, 0, 220);
                }

                echo json_encode(['error' => 'خطا در ارتباط با موتور هوش مصنوعی. (' . $errorReason . ')'], JSON_UNESCAPED_UNICODE);
            }
            exit;
        }
        if ($action === 'changeMyPassword') {
            if (!$isFullyAuthenticated) { echo json_encode(['error' => 'غیرمجاز.']); exit; }
            $newPin = $input['newPin'] ?? '';
            if (!$newPin) { echo json_encode(['error' => 'رمز عبور نمی‌تواند خالی باشد']); exit; }
            // 🔑 پین دقیقاً همان‌که کاربر گذاشته ذخیره می‌شود (A123 → A123)
            $newPinValue = $newPin;

            if ($userRole === 'مدیر') {
                $pdo->prepare("UPDATE agencies SET adminPin = ? WHERE id = ?")->execute([$newPinValue, $agencyId]);
            } else if ($userRole === 'مشاور') {
                $pdo->prepare("UPDATE members SET pin = ? WHERE name = ? AND agencyId = ?")->execute([$newPinValue, $userName, $agencyId]);
            }
            markSystemUpdated($pdo); echo json_encode(['response' => ['success' => true]]); exit;
        }

        if ($action === 'resetMemberPassword') {
            if (!$isFullyAuthenticated || $userRole !== 'مدیر') { echo json_encode(['error' => 'فقط مدیر آژانس مجاز به انجام این عملیات است.']); exit; }
            $memberId = $input['memberId'] ?? '';
            $newPin = $input['newPin'] ?? '';
            if (!$memberId || !$newPin) { echo json_encode(['error' => 'اطلاعات ناقص است.']); exit; }
            
            // 🔑 پین دقیقاً همان‌که تعیین شده ذخیره می‌شود
            $pdo->prepare("UPDATE members SET pin = ? WHERE id = ? AND agencyId = ?")->execute([$newPin, $memberId, $agencyId]);
            markSystemUpdated($pdo); echo json_encode(['response' => ['success' => true]]); exit;
        }

        if ($action === 'saveAgency') {
            if (!password_verify($input['masterPass'] ?? '', MASTER_PASSWORD_HASH)) { echo json_encode(['error' => 'رمز مالک اشتباه است.']); exit; }

            // 🛡️ شناسهٔ آژانس هیچ‌جا اعتبارسنجی نمی‌شد، در حالی که مستقیم در
            //    نام فایل عکس‌ها (uploads/prop_<agencyId>_...) و در کوئری‌ها
            //    استفاده می‌شود. حالا برای «آژانس تازه» محدود به کاراکترهای
            //    امن است. آژانس‌های قدیمی با شناسهٔ دلخواه دست‌نخورده می‌مانند
            //    تا کسی از سیستم بیرون نیفتد.
            $newAgencyId = trim((string)($input['id'] ?? ''));
            $__ex = $pdo->prepare("SELECT id FROM agencies WHERE id = ?");
            $__ex->execute([$newAgencyId]);
            if (!$__ex->fetchColumn()) {
                if (!preg_match('/^[A-Za-z0-9_\-]{3,50}$/', $newAgencyId)) {
                    echo json_encode(['error' => 'کد آژانس باید ۳ تا ۵۰ کاراکتر و فقط شامل حروف انگلیسی، رقم، خط تیره (-) یا زیرخط (_) باشد.']);
                    exit;
                }
            }
            // 🔑 پین دقیقاً همان‌که در املاک کریتور تعیین شده (بدون هش اضافه)
            $hashed = (string)($input['adminPin'] ?? '');
            
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

            // 🔑 دقیقاً با همان رمزی که کاربر گذاشته (ساده یا هش قدیمی)
            $isMatch = pin_matches($ag['adminPin'], $input['pin'] ?? '');
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
                // 🔑 دقیقاً با همان رمزی که مشاور گذاشته (ساده یا هش قدیمی)
                $isMatch = pin_matches($mem['pin'], $pin);
                if (!$isMatch) { echo json_encode(['error' => 'رمز عبور اشتباه است.']); exit; }
                if ($mem['status'] === 'blocked') { echo json_encode(['error' => 'حساب مسدود است.']); exit; }
                if ($mem['status'] === 'pending') { echo json_encode(['error' => 'حساب در انتظار تایید مدیر است.']); exit; }
                
                $pdo->prepare("UPDATE members SET lastSeen = ? WHERE id = ?")->execute([time(), $mem['id']]);
                echo json_encode(['response' => ['success' => true, 'token' => generateSecureToken($reqAgencyId, 'مشاور', $name, APP_SALT, $ag['plan_type'] ?? 'Basic'), 'id' => $mem['id'], 'agencyName' => $ag['name'], 'plan' => $ag['plan_type'] ?? 'Basic']]); exit;
            } else {
                $mId = uniqid('mem_');
                $pdo->prepare("INSERT INTO members (id, agencyId, name, role, status, pin, joinedAt, lastSeen) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$mId, $reqAgencyId, $name, 'مشاور', 'pending', $pin, date('Y-m-d H:i:s'), time()]);
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
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }

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
                        // 🛡️ قبلاً هر رشته‌ای بی‌بررسی در دیتابیس ذخیره می‌شد و
                        //    بعداً در deleteProperty به unlink می‌رسید.
                        $safe = safeUploadRelPath($base64OrUrl);
                        if ($safe !== null) $imagePaths[] = $safe;
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
                        // 🛡️ بررسی دوم: حتی اگر رشتهٔ آلوده از قبل در دیتابیس باشد،
                        //    realpath باید واقعاً داخل uploads/ حل شود.
                        $uploadsRoot = realpath(__DIR__ . '/uploads');
                        $uploadsRoot = $uploadsRoot === false ? null : $uploadsRoot . DIRECTORY_SEPARATOR;
                        foreach ($imgs as $img) {
                            $safe = safeUploadRelPath($img);
                            if ($safe === null || $uploadsRoot === null) continue;
                            $abs = realpath(__DIR__ . '/' . $safe);
                            if ($abs === false || strpos($abs, $uploadsRoot) !== 0) continue;
                            if (is_file($abs)) { @unlink($abs); }
                        }
                    }
                }
            }
            $pdo->prepare("DELETE FROM {$table} WHERE id = ? AND agencyId = ?")->execute([$id, $agencyId]);
            markSystemUpdated($pdo); echo json_encode(['response' => ['success' => true]]); exit;
        }
        
    } catch (PDOException $e) {
        // 🛡️ پیام خام PDOException نام جدول‌ها، ستون‌ها و گاهی مسیر فایل را
        //    به کلاینت لو می‌داد. جزئیات فقط در error_log سرور می‌رود.
        error_log('[api.php] PDOException: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'خطای داخلی سرور. لطفاً دوباره تلاش کنید.']);
        exit;
    }
}
echo json_encode(['error' => 'عملیات یافت نشد']);
?>