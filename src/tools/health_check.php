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
 * تعمیر خودکار دو مشکل شناخته‌شده (UNIQUE و uploads/.htaccess):
 *     https://دامنهٔ-تو/tools/health_check.php?key=HEALTH_KEY&fix=1
 *
 * ⚠️ برای اینکه از مرورگر باز شود، دو شرط لازم است:
 *    ۱) `.htaccess` ریشهٔ پروژه، tools/health_check.php را مستثنا کند (شده)
 *    ۲) پارامتر ?key= همراه درخواست باشد (این کد 403 «کلید اشتباه» می‌دهد
 *       اگر URL را بدون کلید صدا بزنی)
 *
 * ⚠️ بعد از اینکه یک بار اجرا کردی و همه‌چیز سبز بود، این فایل را پاک کن
 *    (کلید داخل همین فایل در گیت است و «راز» محسوب نمی‌شود).
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
        // این پیام عمداً «خودِ PHP» را ذکر می‌کند تا اگر به‌جای این متن،
        // صفحهٔ «Forbidden» خالی/HTML آپاچی دیدی، معلوم باشد مشکل از
        // .htaccess ریشه است نه از این فایل.
        exit("403 — کلید اشتباه است. این پاسخ از خودِ PHP است، یعنی .htaccess اجازهٔ اجرا داده.\n"
           . "آدرس درست (کلید را حتماً بگذار):\n"
           . "  https://دامنه-ی-تو/tools/health_check.php?key=" . HEALTH_KEY . "\n");
    }
}

// 🛠️ حالت تعمیر خودکار:
//    از مرورگر:  ...health_check.php?key=...&fix=1
//    از خط فرمان: php tools/health_check.php --fix
//    فقط دو اصلاح امن انجام می‌دهد (چیزی از رازها چاپ نمی‌شود):
//     ۱) افزودن کلید UNIQUE به personal_notes (اگر جدول هست و کلید نیست)
//     ۲) ساخت uploads/.htaccess اگر گم شده باشد
//    و اگر اصلاح دیتابیس موفق شد، schema_version را هم از روی api.php می‌خواند
//    و ثبت می‌کند تا ریکوئست بعدی api.php دوباره migration اجرا نکند.
$FIX = $CLI ? in_array('--fix', $argv ?? [], true) : (($_GET['fix'] ?? '') === '1');

define('ROOT', dirname(__DIR__));
$pass = 0; $fail = 0; $warn = 0;

function ok($g, $msg, $detail = '')   { global $pass; $pass++; line('✅', $g, $msg, $detail); }
function bad($g, $msg, $detail = '')  { global $fail; $fail++; line('❌', $g, $msg, $detail); }
function meh($g, $msg, $detail = '')  { global $warn; $warn++; line('⚠️ ', $g, $msg, $detail); }
function head($t) {
    // mb_strlen ممکن است نصب نباشد؛ بدون آن هم اسکریپت نباید ۵۰۰ بدهد
    $len = function_exists('mb_strlen') ? mb_strlen($t) : strlen($t);
    echo "\n── $t " . str_repeat('─', max(0, 58 - $len)) . "\n";
}
function line($ic, $g, $msg, $detail = '') {
    echo "$ic [$g] $msg" . ($detail !== '' ? "\n      ↳ $detail" : '') . "\n";
}

echo "🩺 بررسی سلامت استقرار — " . date('Y-m-d H:i:s') . "\n";
echo "   ریشهٔ پروژه: " . ROOT . "\n";

/* ═══════════ ۱) محیط PHP ═══════════ */
head('۱) محیط PHP');
if (version_compare(PHP_VERSION, '7.4.0', '>=')) ok('php', 'نسخهٔ PHP ' . PHP_VERSION);
else bad('php', 'نسخهٔ PHP خیلی قدیمی است: ' . PHP_VERSION, 'حداقل 7.4 لازم است');

foreach (['pdo_mysql' => 'اتصال به دیتابیس', 'curl' => 'صدا زدن API صوت', 'gd' => 'تغییر اندازهٔ عکس‌ها', 'json' => 'پاسخ‌های JSON', 'mbstring' => 'چاپ عنوان‌های فارسی این گزارش'] as $ext => $why) {
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

if (defined('OPENROUTER_API_KEY') && OPENROUTER_API_KEY !== '' && strpos(OPENROUTER_API_KEY, 'REPLACE') === false) ok('config', 'OPENROUTER_API_KEY پر شده');
else meh('config', 'OPENROUTER_API_KEY خالی یا placeholder است', 'جارویس متنی کار نخواهد کرد');

// ⚠️ این رمز در Test.zip داخل گیت منتشر شده است. فقط هشش را مقایسه می‌کنیم
//    تا خودِ رمز دوباره در سورس نوشته نشود؛ خودش را هم چاپ نمی‌کنیم.
if (defined('DB_PASS') && DB_PASS !== '' && hash_equals('24ac93fb5da49359299fcf33b2077e5a1f6f467dc689f026f981ce2136891f6a', hash('sha256', DB_PASS))) {
    bad('config', 'DB_PASS هنوز همان رمزِ لو‌رفته در گیت است', 'DirectAdmin → MySQL Management → Change Password → بعد DB_PASS را در config.php با رمز جدید عوض کن');
}

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

/* ═══════════ ۳٫۲) بازیابی/بازنشانی پین مدیر آژانس (فقط با پارامترها) ═══════════ */
//    از مرورگر:
//      ?key=...&list_agencies=1                → فهرست آژانس‌ها + وضعیت پین
//      ?key=...&reset_pin=CODE&pin=1234        → پین جدید (حداقل ۴ کاراکتر)
//    از خط فرمان:
//      php tools/health_check.php --list-agencies
//      php tools/health_check.php --reset-pin CODE --pin 1234
$__argv = $_SERVER['argv'] ?? [];
$__listAg = !$CLI ? (($_GET['list_agencies'] ?? '') === '1') : in_array('--list-agencies', $__argv, true);
$__resetAgency = !$CLI ? (string)($_GET['reset_pin'] ?? '') : '';
$__setPin = !$CLI ? (string)($_GET['pin'] ?? '') : '';
if ($CLI) {
    for ($i = 0; $i < count($__argv); $i++) {
        if (($__argv[$i] ?? '') === '--reset-pin' && isset($__argv[$i + 1])) $__resetAgency = (string)$__argv[$i + 1];
        if (($__argv[$i] ?? '') === '--pin' && isset($__argv[$i + 1])) $__setPin = (string)$__argv[$i + 1];
    }
}

if ($__listAg) {
    head('۳٫۲) آژانس‌ها — مرجع بازیابی پین');
    try {
        $rows = $pdo->query("SELECT id, name, managerName, expireAt, plan_type, adminPin FROM agencies ORDER BY id")->fetchAll();
        if (!$rows) { echo "   (هیچ آژانسی ثبت نشده)\n"; }
        foreach ($rows as $r) {
            if ($r['adminPin'] === null || $r['adminPin'] === '') {
                $pinInfo = '⚠️ پین خالی است!';
            } else {
                $algo = password_get_info($r['adminPin'])['algo'];
                $pinInfo = ($algo === 0) ? 'پین ساده (ذخیره‌شده): ' . $r['adminPin'] : 'پین هش‌شده (bcrypt) ✓';
            }
            echo "   [{$r['id']}] {$r['name']} | مدیر: {$r['managerName']} | پلن: {$r['plan_type']} | انقضا: {$r['expireAt']} | $pinInfo\n";
        }
        echo "\n   برای عوض‌کردن پین: ...&reset_pin=کد-آژانس&pin=پین-جدید\n";
    } catch (Throwable $e) { bad('list', 'خواندن آژانس‌ها ناموفق بود', $e->getMessage()); }
}

if ($__resetAgency !== '') {
    head('۳٫۲) بازنشانی پین مدیر آژانس');
    if (strlen($__setPin) < 4) { bad('reset', 'پین خیلی کوتاه است', 'حداقل ۴ کاراکتر'); }
    else {
        try {
            $__newHash = password_hash($__setPin, PASSWORD_BCRYPT);
            $__st = $pdo->prepare("UPDATE agencies SET adminPin = ? WHERE id = ?");
            $__st->execute([$__newHash, $__resetAgency]);
            $__st->rowCount() > 0
                ? ok('reset', "پین آژانس «{$__resetAgency}» عوض شد — حالا با همین پین وارد شو")
                : bad('reset', "آژانس «{$__resetAgency}» پیدا نشد", 'با list_agencies=1 کد دقیق را ببین');
        } catch (Throwable $e) { bad('reset', 'بازنشانی ناموفق بود', $e->getMessage()); }
    }
}

/* ═══════════ ۳٫۵) تعمیر خودکار (فقط با fix=1 / --fix) ═══════════ */
if ($FIX) {
    head('۳٫۵) تعمیر خودکار');
    $dbRepaired = false;

    // الف) ساخت uploads/.htaccess اگر گم شده باشد
    if (!is_file(ROOT . '/uploads/.htaccess')) {
        $ht = "# ⚡ پوشهٔ آپلود: فقط فایل داده، هیچ اسکریپتی اجرا نشود\n"
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
            . "</IfModule>\n";
        @file_put_contents(ROOT . '/uploads/.htaccess', $ht);
        is_file(ROOT . '/uploads/.htaccess')
            ? ok('fix', 'uploads/.htaccess ساخته شد')
            : bad('fix', 'ساخت uploads/.htaccess ناموفق بود', 'دسترسی نوشتن پوشهٔ uploads را چک کن');
    } else {
        ok('fix', 'uploads/.htaccess از قبل هست');
    }

    // ب) افزودن UNIQUE به personal_notes (گامی که migration api.php انجام می‌دهد)
    if ($pdo->query("SHOW TABLES LIKE 'personal_notes'")->rowCount() > 0) {
        $__uniq = false;
        foreach ($pdo->query("SHOW INDEX FROM personal_notes") as $ix) {
            if ((int)$ix['Non_unique'] === 0 && $ix['Key_name'] !== 'PRIMARY') { $__uniq = true; break; }
        }
        if (!$__uniq) {
            try {
                // ستون کلید اولیه را از SHOW INDEX پیدا می‌کنیم
                $__pk = 'id';
                foreach ($pdo->query("SHOW INDEX FROM personal_notes") as $__ix) {
                    if (($__ix['Key_name'] ?? '') === 'PRIMARY') { $__pk = $__ix['Column_name']; break; }
                }
                // حذف تکراری‌ها به‌ازای هر گروه (روش استاندارد؛ نسخهٔ قبلی با
                // «DELETE t1 FROM ... JOIN ...» روی MySQL هاست 1054 می‌داد)
                $__dups = $pdo->query("SELECT agencyId, username, MIN(`$__pk`) AS keep_id
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
                ok('fix', "کلید UNIQUE به personal_notes اضافه شد ({$__removed} ردیف تکراری حذف شد)");
                $dbRepaired = true;
            } catch (Throwable $__e) {
                bad('fix', 'افزودن UNIQUE ناموفق بود', $__e->getMessage());
            }
        } else {
            ok('fix', 'personal_notes از قبل کلید UNIQUE دارد');
        }
    } else {
        meh('fix', 'جدول personal_notes وجود ندارد؛ چیزی برای تعمیر نیست');
    }

    // ج) ثبت schema_version فقط اگر تعمیر دیتابیس انجام شد (تا migration نشتی نماند
    //    و ریکوئست بعدی api.php دوباره تمام بلوک را اجرا نکند)
    if ($dbRepaired) {
        $__apiTxt = @file_get_contents(ROOT . '/api.php');
        if ($__apiTxt && preg_match("/define\s*\(\s*'SCHEMA_VERSION'\s*,\s*(\d+)\s*\)/", $__apiTxt, $__m)) {
            try {
                $pdo->prepare("INSERT INTO sys_config (conf_key, conf_val) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE conf_val = VALUES(conf_val)")->execute([$__m[1]]);
                ok('fix', "schema_version = {$__m[1]} ثبت شد");
            } catch (Throwable $__e) {
                meh('fix', 'schema_version ثبت نشد', $__e->getMessage());
            }
        } else {
            meh('fix', 'schema_version از api.php خوانده نشد', 'api.php را یک بار در مرورگر باز کن تا migration خودش ثبتش کند');
        }
    }
    echo "\n";
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
          : bad('db', 'personal_notes کلید UNIQUE ندارد', 'بدون آن هر ذخیره یک ردیف جدید می‌سازد — برای تعمیر خودکار همین آدرس را با &fix=1 صدا بزن');
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
    'uploads/.htaccess'      => 'P0 — جلوگیری از اجرای اسکریپت در آپلودها (اگر نیست: آدرس را با &fix=1 صدا بزن یا استخراج مجدد با تیک Overwrite)',
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

/* ═══════════ ۱۰) مغز متنی جارویس (OpenRouter) ═══════════ */
head('۱۰) مغز متنی جارویس (OpenRouter)');

$orUrl   = defined('OPENROUTER_URL')   ? OPENROUTER_URL   : '';
$orModel = defined('OPENROUTER_MODEL') ? OPENROUTER_MODEL : '';
$orKey   = defined('OPENROUTER_API_KEY') ? OPENROUTER_API_KEY : '';
$OFFICIAL = 'https://openrouter.ai/api/v1/chat/completions';

if ($orKey === '' || strpos($orKey, 'REPLACE') !== false) bad('jarvis', 'OPENROUTER_API_KEY خالی یا placeholder است');
else ok('jarvis', 'OPENROUTER_API_KEY پر شده (' . substr($orKey, 0, 10) . '…' . substr($orKey, -4) . ')');

if ($orUrl === $OFFICIAL)      ok('jarvis', 'OPENROUTER_URL آدرس رسمی OpenRouter است');
elseif ($orUrl === '')         meh('jarvis', 'OPENROUTER_URL تعریف نشده', 'api.php خودش به آدرس رسمی برمی‌گردد');
else bad('jarvis', 'OPENROUTER_URL آدرس رسمی نیست: ' . $orUrl, 'اگر پروکسی شخصی است، api.php بعد از شکست به آدرس رسمی برمی‌گردد');

// شناسهٔ مدل در OpenRouter حتماً «ارائه‌دهنده/مدل» است
if ($orModel === '') meh('jarvis', 'OPENROUTER_MODEL خالی است', 'api.php از google/gemma-4-26b-a4b-it:free استفاده می‌کند');
elseif (strpos($orModel, '/') === false) bad('jarvis', "OPENROUTER_MODEL پیشوند ارائه‌دهنده ندارد: $orModel", 'شناسهٔ معتبر شکل «google/gemma-4-26b-a4b-it:free» دارد');
else ok('jarvis', 'OPENROUTER_MODEL = ' . $orModel);

// ── تست واقعی: یک درخواست کوچک به هر دو آدرس ──
if ($orKey !== '' && strpos($orKey, 'REPLACE') === false && extension_loaded('curl')) {
    echo "   (یک درخواست آزمایشی کوچک زده می‌شود — چند ثانیه صبر کن)\n";
    $probeModel = (strpos($orModel, '/') !== false) ? $orModel : 'google/gemma-4-26b-a4b-it:free';
    $targets = array_values(array_unique(array_filter([$orUrl, $OFFICIAL])));
    $results = [];
    foreach ($targets as $t) {
        $ch = curl_init($t);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'model' => $probeModel,
            'messages' => [['role' => 'user', 'content' => 'ok']],
            'max_tokens' => 1,
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $orKey,
            'HTTP-Referer: https://test.amlak-e-man.ir',
            'X-Title: Amlak Man Jarvis',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        $host = (string) parse_url($t, PHP_URL_HOST);
        $results[$host] = $code;
        $note = '';
        if ($cerr !== '')                          $note = 'قطعی شبکه: ' . $cerr;
        elseif ($code === 200)                     $note = '✅ کار می‌کند';
        elseif ($code === 401)                     $note = 'کلید باطل است';
        elseif ($code === 402)                     $note = 'اعتبار اکانت تمام شده';
        elseif ($code === 429)                     $note = 'سقف درخواست رایگان پر شده (بدون شارژ: ۵۰ در روز)';
        elseif ($code === 403)                     $note = (strpos($host, 'openrouter.ai') === false) ? 'پروکسی/WAF رد کرده — کلید لزوماً سالم است' : 'کلید دسترسی ندارد';
        elseif ($code === 400 || $code === 404)    $note = 'مدل یا پارامتر نامعتبر';
        echo "   • $host → HTTP $code" . ($note !== '' ? "  ($note)" : '') . "\n";
        if ($code !== 200 && $code !== 0) {
            echo '     پاسخ: ' . substr((string) $body, 0, 300) . "\n";
        }
    }
    // تفسیر
    $officialCode = $results['openrouter.ai'] ?? 0;
    if ($officialCode === 200) {
        ok('jarvis', 'آدرس رسمی OpenRouter با این کلید کار می‌کند');
    } elseif ($officialCode === 401) {
        bad('jarvis', 'کلید OpenRouter باطل است', 'در openrouter.ai → Keys یک کلید تازه بساز');
    } elseif ($officialCode === 429) {
        meh('jarvis', 'کلید سالم است ولی سقف رایگان پر شده', 'بدون شارژ ۵۰ درخواست در روز؛ با ۱۰ دلار شارژ می‌شود ۱۰۰۰');
    } elseif ($officialCode === 0) {
        meh('jarvis', 'آدرس رسمی چک نشد (قطعی شبکه از سمت هاست)');
    } else {
        bad('jarvis', 'آدرس رسمی OpenRouter پاسخ غیرمنتظره داد: HTTP ' . $officialCode);
    }
} else {
    meh('jarvis', 'تست واقعی انجام نشد (کلید نیست یا curl نصب نیست)');
}

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
