<?php
/**
 * 🩺 بررسی سلامت استقرار
 * ========================
 * همهٔ اصلاحات P0 تا P3 را روی سرور واقعی چک می‌کند و گزارش PASS/FAIL می‌دهد.
 * هیچ چیزی نمی‌نویسد و هیچ رازی را چاپ نمی‌کند — فقط می‌خواند.
 *
 * اجرا از خط فرمان:
 *     php tools/health_check.php
 *
 * اجرا از مرورگر (اگر SSH نداری):
 *     https://دامنهٔ-تو/tools/health_check.php?key=HEALTH_KEY
 *
 * ⚠️ بعد از اینکه یک بار اجرا کردی و همه‌چیز سبز بود، این فایل را پاک کن.
 */

// ─────────────────────────────────────────────────────────────────────────
// 🔑 کلید دسترسی از مرورگر. اگر از خط فرمان اجرا می‌کنی لازم نیست.
//    بعد از استفاده همین خط را عوض کن یا فایل را پاک کن.
const HEALTH_KEY = 'hk_9f3c71ab60d54e8fa2c17be4d5096e38';
// ─────────────────────────────────────────────────────────────────────────

$CLI = (PHP_SAPI === 'cli');

if (!$CLI) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!hash_equals(HEALTH_KEY, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("403 — کلید اشتباه است. از «php tools/health_check.php» استفاده کن.\n");
    }
}

define('ROOT', dirname(__DIR__));
$pass = 0; $fail = 0; $warn = 0;

function ok($g, $msg, $detail = '')   { global $pass; $pass++; line('✅', $g, $msg, $detail); }
function bad($g, $msg, $detail = '')  { global $fail; $fail++; line('❌', $g, $msg, $detail); }
function meh($g, $msg, $detail = '')  { global $warn; $warn++; line('⚠️ ', $g, $msg, $detail); }
function head($t) { echo "\n── $t " . str_repeat('─', max(0, 58 - mb_strlen($t))) . "\n"; }
function line($ic, $g, $msg, $detail = '') {
    echo "$ic [$g] $msg" . ($detail !== '' ? "\n      ↳ $detail" : '') . "\n";
}

echo "🩺 بررسی سلامت استقرار — " . date('Y-m-d H:i:s') . "\n";
echo "   ریشهٔ پروژه: " . ROOT . "\n";

/* ═══════════ ۱) محیط PHP ═══════════ */
head('۱) محیط PHP');
if (version_compare(PHP_VERSION, '7.4.0', '>=')) ok('php', 'نسخهٔ PHP ' . PHP_VERSION);
else bad('php', 'نسخهٔ PHP خیلی قدیمی است: ' . PHP_VERSION, 'حداقل 7.4 لازم است');

foreach (['pdo_mysql' => 'اتصال به دیتابیس', 'curl' => 'صدا زدن API صوت', 'gd' => 'تغییر اندازهٔ عکس‌ها', 'json' => 'پاسخ‌های JSON'] as $ext => $why) {
    if (extension_loaded($ext)) ok('php', "افزونهٔ $ext ($why)");
    else bad('php', "افزونهٔ $ext نصب نیست", $why);
}

$need = ['upload_max_filesize' => 8, 'post_max_size' => 8];
foreach ($need as $k => $minMB) {
    $v = ini_get($k);
    $mb = (int) preg_replace('/[^0-9]/', '', (string) $v);
    if ($mb >= $minMB) ok('php', "$k = $v");
    else meh('php', "$k = $v خیلی کم است", "برای فایل صوتی حداقل {$minMB}M لازم است");
}

/* ═══════════ ۲) config.php ═══════════ */
head('۲) config.php');
$cfg = ROOT . '/config.php';
if (!is_file($cfg)) {
    bad('config', 'فایل config.php وجود ندارد', 'از src/config.php آپلودش کن');
    echo "\nبدون config.php ادامه ممکن نیست.\n";
    summary();
}
require_once $cfg;

foreach (['DB_HOST','DB_NAME','DB_USER','DB_PASS','APP_SALT','MASTER_PASSWORD_HASH','ALLOWED_ORIGIN'] as $c) {
    if (defined($c)) ok('config', "ثابت $c تعریف شده");
    else bad('config', "ثابت $c تعریف نشده");
}

// رمزها را چاپ نمی‌کنیم، فقط چک می‌کنیم
if (defined('APP_SALT')) {
    if (strpos(APP_SALT, 'Change_This_To_Any_Random_Text') !== false) bad('config', 'APP_SALT هنوز مقدار نمونهٔ کد است', 'باید یک مقدار تصادفی باشد');
    elseif (strlen(APP_SALT) < 32) meh('config', 'APP_SALT کوتاه است (' . strlen(APP_SALT) . ' کاراکتر)', '۶۴ کاراکتر تصادفی توصیه می‌شود');
    else ok('config', 'APP_SALT مقدار نمونه نیست (' . strlen(APP_SALT) . ' کاراکتر)');
}
if (defined('MASTER_PASSWORD_HASH')) {
    $h = MASTER_PASSWORD_HASH;
    if (preg_match('/^\$2[aby]\$\d{2}\$/', $h) && strlen($h) === 60) ok('config', 'MASTER_PASSWORD_HASH یک bcrypt آماده است');
    else bad('config', 'MASTER_PASSWORD_HASH هش bcrypt معتبر نیست', 'طول ' . strlen($h) . ' — باید ۶۰ و با $2y$ شروع شود');
    if ($h === '$2y$10$REPLACE_WITH_output_of_password_hash') bad('config', 'MASTER_PASSWORD_HASH هنوز placeholder است');
}
if (defined('ALLOWED_ORIGIN')) {
    if (ALLOWED_ORIGIN === '*') meh('config', "ALLOWED_ORIGIN روی '*' است", 'هر سایتی می‌تواند به api.php درخواست بدهد');
    elseif (ALLOWED_ORIGIN === '') ok('config', "ALLOWED_ORIGIN خالی است (فقط هم‌دامنه)");
    else ok('config', 'ALLOWED_ORIGIN محدود شده');
}
if (defined('STT_HF_TOKEN') && STT_HF_TOKEN !== '' && strpos(STT_HF_TOKEN, 'REPLACE') === false) ok('config', 'STT_HF_TOKEN پر شده');
else meh('config', 'STT_HF_TOKEN خالی یا placeholder است', 'تبدیل صوت به متن کار نخواهد کرد');

/* ═══════════ ۳) اتصال دیتابیس ═══════════ */
head('۳) اتصال دیتابیس');
$pdo = null;
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    ok('db', 'اتصال به دیتابیس برقرار شد');
} catch (Throwable $e) {
    bad('db', 'اتصال به دیتابیس ناموفق بود', $e->getMessage());
    echo "\n⚠️ اگر تازه رمز دیتابیس را عوض کرده‌ای، مطمئن شو همان مقدار در config.php هم هست.\n";
    summary();
}

/* ═══════════ ۴) جدول‌ها ═══════════ */
head('۴) جدول‌های دیتابیس');
// این سه جدول قبلاً هیچ‌جا ساخته نمی‌شدند
$tables = [
    'agencies'       => 'ضروری — آژانس‌ها',
    'properties'     => 'ضروری — ملک‌ها',
    'demands'        => 'ضروری — تقاضاها',
    'members'        => 'ضروری — مشاوران',
    'sys_config'     => 'P2 — قبلاً ساخته نمی‌شد؛ بدون آن هر عمل نوشتنی می‌میرد',
    'rate_limits'    => 'P1 — قبلاً ساخته نمی‌شد؛ بدون آن کل API با ۵۰۰ می‌خوابد',
    'personal_notes' => 'P0 — قبلاً ساخته نمی‌شد؛ تب یادداشت‌ها می‌ترکید',
];
foreach ($tables as $t => $why) {
    $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->rowCount() > 0;
    $exists ? ok('db', "جدول $t", $why) : bad('db', "جدول $t وجود ندارد", $why . ' — php setup_db.php را اجرا کن');
}

/* ═══════════ ۵) ستون‌ها و ایندکس‌ها ═══════════ */
head('۵) ستون‌ها و ایندکس‌ها');
$cols = [
    'agencies'       => ['plan_type'],
    'properties'     => ['showToGuest', 'showImagesGuest', 'description'],
    'demands'        => ['description', 'deposit'],
    'personal_notes' => ['agencyId', 'username', 'note_text'],
    'rate_limits'    => ['ip', 'request_time'],
    'sys_config'     => ['conf_key', 'conf_val'],
];
foreach ($cols as $t => $list) {
    if ($pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->rowCount() === 0) { meh('db', "جدول $t نیست، ستون‌هایش چک نشد"); continue; }
    $have = [];
    foreach ($pdo->query("SHOW COLUMNS FROM `$t`") as $c) $have[$c['Field']] = true;
    foreach ($list as $c) {
        isset($have[$c]) ? ok('db', "$t.$c") : bad('db', "$t.$c وجود ندارد");
    }
}

// کلید UNIQUE روی personal_notes — بدون آن ON DUPLICATE KEY UPDATE کار نمی‌کند
if ($pdo->query("SHOW TABLES LIKE 'personal_notes'")->rowCount() > 0) {
    $uniq = false;
    foreach ($pdo->query("SHOW INDEX FROM personal_notes") as $ix) { if ((int)$ix['Non_unique'] === 0 && $ix['Key_name'] !== 'PRIMARY') $uniq = true; }
    $uniq ? ok('db', 'personal_notes کلید UNIQUE دارد')
          : bad('db', 'personal_notes کلید UNIQUE ندارد', 'بدون آن هر ذخیره یک ردیف جدید می‌سازد');
}

// طول ستون ip در rate_limits — کلید ما ۱۵ کاراکتر است
if ($pdo->query("SHOW TABLES LIKE 'rate_limits'")->rowCount() > 0) {
    foreach ($pdo->query("SHOW COLUMNS FROM rate_limits LIKE 'ip'") as $c) {
        if (preg_match('/\((\d+)\)/', (string)$c['Type'], $m)) {
            ((int)$m[1] >= 15) ? ok('db', 'rate_limits.ip طول ' . $m[1] . ' دارد')
                               : bad('db', 'rate_limits.ip طول ' . $m[1] . ' دارد', 'حداقل ۱۵ لازم است وگرنه محدودیت نرخ خطا می‌دهد (fail-open می‌شود)');
        }
    }
}

// نسخهٔ ساختار
try {
    $sv = $pdo->query("SELECT conf_val FROM sys_config WHERE conf_key = 'schema_version'")->fetchColumn();
    $sv ? ok('db', "schema_version = $sv (migration در هر ریکوئست اجرا نمی‌شود)")
        : meh('db', 'schema_version ثبت نشده', 'در اولین ریکوئست migration اجرا و ثبت می‌شود');
} catch (Throwable $e) { meh('db', 'schema_version چک نشد', $e->getMessage()); }

/* ═══════════ ۶) فایل‌ها ═══════════ */
head('۶) فایل‌ها');
$files = [
    'api.php'                => 'ضروری',
    'stt.php'                => 'P1 — api.php به آن require_once دارد؛ بدون آن fatal',
    'index.html'             => 'ضروری',
    '.htaccess'              => 'P0 — محافظت از config.php و backups',
    'uploads/.htaccess'      => 'P0 — جلوگیری از اجرای اسکریپت در آپلودها',
    'backups/.htaccess'      => 'P0 — بک‌آپ‌ها قابل دانلود نباشند',
    'tools/.htaccess'        => 'P0 — ابزارها از وب بسته باشند',
    'assets/logo.png'        => 'P2 — قبلاً گم شده بود؛ لوگو در ۳ جا شکسته بود',
    'icon-192.png'           => 'P2 — آیکون PWA',
    'icon-512.png'           => 'P2 — آیکون PWA',
    'assets/images/marker-icon.png' => 'P2 — مارکر نقشه',
];
foreach ($files as $f => $why) {
    is_file(ROOT . '/' . $f) ? ok('file', $f, $why) : bad('file', "$f وجود ندارد", $why);
}

// فایل‌هایی که باید پاک شده باشند
$gone = [
    'test.php' => 'P1 — $_GET را بدون escape بازتاب می‌داد',
    'htaccess' => 'P0 — نسخهٔ بی‌نقطه که api.php را مسدود می‌کرد',
];
foreach ($gone as $f => $why) {
    is_file(ROOT . '/' . $f) ? bad('file', "$f هنوز وجود دارد", "$why — پاکش کن") : ok('file', "$f پاک شده");
}

foreach (['uploads', 'backups'] as $d) {
    if (!is_dir(ROOT . '/' . $d)) { meh('file', "پوشهٔ $d وجود ندارد"); continue; }
    is_writable(ROOT . '/' . $d) ? ok('file', "پوشهٔ $d قابل نوشتن است") : bad('file', "پوشهٔ $d قابل نوشتن نیست");
}

/* ═══════════ ۷) محافظت وب ═══════════ */
head('۷) محافظت از طریق وب');
$ht = ROOT . '/.htaccess';
if (is_file($ht)) {
    $c = file_get_contents($ht);
    foreach (['config.php' => 'config.php', 'backups' => 'پوشهٔ backups', 'uploads/' => 'اجرای اسکریپت در uploads'] as $needle => $label) {
        strpos($c, $needle) !== false ? ok('web', ".htaccess جلوی $label را می‌گیرد") : bad('web', ".htaccess به $label اشاره نمی‌کند");
    }
    if (strpos($c, 'AuthGroupFile') !== false) bad('web', 'AuthGroupFile هنوز در .htaccess است', 'در آپاچی ۲.۴ وجود ندارد و ۵۰۰ می‌دهد');
    else ok('web', 'AuthGroupFile حذف شده');
} else bad('web', '.htaccess وجود ندارد');

/* ═══════════ ۸) کد ═══════════ */
head('۸) بررسی خودِ کد');
$api = is_file(ROOT . '/api.php') ? file_get_contents(ROOT . '/api.php') : '';
$checks = [
    ['api.php', strpos($api, 'CREATE TABLE IF NOT EXISTS rate_limits')   !== false, 'rate_limits ساخته می‌شود'],
    ['api.php', strpos($api, 'CREATE TABLE IF NOT EXISTS sys_config')    !== false, 'sys_config ساخته می‌شود'],
    ['api.php', strpos($api, 'CREATE TABLE IF NOT EXISTS personal_notes')!== false, 'personal_notes ساخته می‌شود'],
    ['api.php', strpos($api, 'function safeUploadRelPath')               !== false, 'safeUploadRelPath تعریف شده (جلوگیری از unlink دلخواه)'],
    ['api.php', strpos($api, "unlink(__DIR__ . '/' . \$img)")            === false, 'unlink مهار‌شده با realpath'],
    ['api.php', strpos($api, "'خطای پایگاه داده: ' . \$e->getMessage()")  === false, 'خطای خام دیتابیس به کلاینت نمی‌رود'],
    ['api.php', strpos($api, 'function rateLimitKey')                    !== false, 'کلید محدودیت نرخ ترکیبی است'],
    ['api.php', strpos($api, "row['desc']")                              !== false, 'کلید desc برای تقاضاها برگردانده می‌شود'],
    ['api.php', strpos($api, 'SCHEMA_VERSION')                           !== false, 'migration پشت دروازهٔ نسخه است'],
];
foreach ($checks as [$f, $cond, $msg]) { $cond ? ok('code', "$f: $msg") : bad('code', "$f: $msg — انجام نشده"); }

$idx = is_file(ROOT . '/index.html') ? file_get_contents(ROOT . '/index.html') : '';
$ichecks = [
    [strpos($idx, 'window.showSuperAdminPanel = function') !== false, 'showSuperAdminPanel تعریف شده'],
    [strpos($idx, 'href="icon.svg"')                      === false, 'ارجاع مردهٔ icon.svg حذف شده'],
    [substr_count($idx, 'rel="manifest"') === 1,                     'manifest فقط یک بار لینک شده'],
    [substr_count($idx, "mapMarker.on('dragend'") === 1,             'dragend فقط یک بار ثبت می‌شود'],
    [strpos($idx, 'escapeHTML(prop.description)')         === false, 'encoding دوباره روی description رفع شده'],
    [strpos($idx, 'isVIP: document.getElementById')       === false, 'کلید تکراری isVIP حذف شده'],
];
foreach ($ichecks as [$cond, $msg]) { $cond ? ok('code', "index.html: $msg") : bad('code', "index.html: $msg — انجام نشده"); }

$cfgTxt = file_get_contents($cfg);
// ⚠️ عمداً دنبال «password_hash(» به‌تنهایی نمی‌گردیم: آن رشته در توضیحات
//    خودِ config.php هم آمده و گزارش غلط می‌داد. دنبال الگوی اجرایی‌اش می‌گردیم.
preg_match("/define\\(\\s*'MASTER_PASSWORD_HASH'\\s*,\\s*password_hash\\s*\\(/", $cfgTxt, $__ph);
empty($__ph)
    ? ok('code', 'config.php دیگر در هر ریکوئست password_hash نمی‌زند')
    : bad('code', 'config.php هنوز password_hash() را در زمان include صدا می‌زند', 'حدود ۵۰ تا ۱۰۰ میلی‌ثانیه در هر ریکوئست تلف می‌شود — یک هش آماده بگذار');

/* ═══════════ ۹) صوت ═══════════ */
head('۹) تبدیل صوت به متن');
if (is_file(ROOT . '/stt.php')) {
    ok('stt', 'stt.php وجود دارد');
    $stt = file_get_contents(ROOT . '/stt.php');
    (strpos($stt, 'router.huggingface.co') !== false)
        ? ok('stt', 'آدرس درست HF Router استفاده شده (نه api-inference که از کار افتاده)')
        : bad('stt', 'آدرس قدیمی api-inference.huggingface.co هنوز هست', 'آن سرویس ۴۱۰ Gone می‌دهد');
    if (defined('STT_PROVIDER')) ok('stt', 'STT_PROVIDER = ' . STT_PROVIDER);
} else bad('stt', 'stt.php وجود ندارد', 'api.php با require_once fatal می‌شود');

meh('stt', 'در دسترس بودن واقعی ارائه‌دهنده اینجا چک نشد', 'برای تست واقعی: php tools/stt_probe.php');

summary();

function summary() {
    global $pass, $fail, $warn;
    echo "\n" . str_repeat('═', 62) . "\n";
    echo "جمع: $pass سالم · $warn هشدار · $fail مشکل\n";
    if ($fail === 0) echo "🎉 همه‌چیز سبز است. این فایل را پاک کن.\n";
    else echo "❌ $fail مورد باید درست شود — پیام‌های بالا را دنبال کن.\n";
    echo str_repeat('═', 62) . "\n";
    exit($fail === 0 ? 0 : 1);
}
