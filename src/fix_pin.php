<?php
/**
 * 🔑 ابزار موقت: بازیابی / بازنشانی پین مدیر آژانس
 * ==================================================
 * چرا؟ فایل‌های قبلی (health_check.php و api.php) روی هاست به‌درستی جایگزین
 * نشده بودند و ورود با پین هنوز «رمز عبور اشتباه» می‌داد. این فایل نامش تازه است
 * پس هیچ فایل قدیمی‌ای روی آن نیست و حتماً نسخهٔ جدید اجرا می‌شود.
 *
 * 🔒 فقط با کلید پایین از مرورگر باز می‌شود. هیچ رمزی را چاپ نمی‌کند،
 *    فقط «نوع» پین ذخیره‌شده را نشان می‌دهد (ساده یا هش).
 *
 * ⚠️ بعد از برطرف‌شدن مشکل، این فایل را از public_html پاک کن.
 *
 * ── فهرست آژانس‌ها و وضعیت پین ──
 *   https://دامنه/fix_pin.php?key=KEY&do=list
 *
 * ── بازنشانی پین مدیر یک آژانس (با bcrypt ذخیره می‌شود) ──
 *   https://دامنه/fix_pin.php?key=KEY&do=reset&id=کد-آژانس&pin=1234
 *
 * ── تعمیر کلید UNIQUE جدول یادداشت‌ها (اگر لازم بود) ──
 *   https://دامنه/fix_pin.php?key=KEY&do=fixdb
 */

const PIN_KEY = 'pin-fix-9f3c71ab60d54e8f';

if (($_GET['key'] ?? '') !== PIN_KEY) {
    http_response_code(403);
    exit("403 — کلید لازم است.\n");
}

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    exit("❌ اتصال به دیتابیس ناموفق بود (") . $e->getMessage() . ")\n";
}

$do = $_GET['do'] ?? '';

/* ── ۱) فهرست ── */
if ($do === 'list') {
    echo "═══ آژانس‌ها ═══\n";
    $rows = $pdo->query("SELECT id, name, managerName, expireAt, plan_type, adminPin FROM agencies ORDER BY id")->fetchAll();
    if (!$rows) { echo "(هیچ آژانسی ثبت نشده — از پنل «املاک کریتور» اول یکی بساز)\n"; exit; }
    foreach ($rows as $r) {
        $pin = $r['adminPin'];
        if ($pin === null || $pin === '') {
            $info = "⚠️ پین خالی است!";
        } else {
            $algo = password_get_info($pin)['algo'];
            $info = ($algo === 0) ? "پین ساده (ذخیره‌شده): " . $pin : "پین هش‌شده (bcrypt) ✓";
        }
        echo "[{$r['id']}] {$r['name']} | مدیر: {$r['managerName']} | پلن: {$r['plan_type']} | انقضا: {$r['expireAt']} | $info\n";
    }
    echo "\n▶ برای بازنشانی پین این آدرس را صدا بزن:\n";
    echo "  https://دامنه/fix_pin.php?key=" . PIN_KEY . "&do=reset&id=کد-آژانس&pin=1234\n";
    exit;
}

/* ── ۲) بازنشانی پین ── */
if ($do === 'reset') {
    $id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['id'] ?? ''));
    $pin = (string)($_GET['pin'] ?? '');
    if ($id === '' || strlen($pin) < 4) {
        exit("❌ پارامتر ناقص: id و pin (حداقل ۴ کاراکتر) لازم است.\n");
    }
    $hash = password_hash($pin, PASSWORD_BCRYPT);
    $st = $pdo->prepare("UPDATE agencies SET adminPin = ? WHERE id = ?");
    $st->execute([$hash, $id]);
    if ($st->rowCount() > 0) {
        echo "✅ پین آژانس «{$id}» با bcrypt ذخیره شد.\n";
        echo "▶ حالا در سایت با همین کد و پین وارد شو: {$pin}\n";
        echo "⚠️ بعد از ورود، همین فایل (fix_pin.php) را از هاست پاک کن.\n";
    } else {
        echo "❌ آژانس «{$id}» پیدا نشد. اول do=list را بزن.\n";
    }
    exit;
}

/* ── ۳) تعمیر UNIQUE (در صورت نیاز) ── */
if ($do === 'fixdb') {
    try {
        $uniq = false;
        foreach ($pdo->query("SHOW INDEX FROM personal_notes") as $ix) {
            if ((int)$ix['Non_unique'] === 0 && $ix['Key_name'] !== 'PRIMARY') { $uniq = true; break; }
        }
        if ($uniq) { echo "✅ کلید UNIQUE از قبل وجود دارد.\n"; exit; }

        // ستون کلید اولیه را از SHOW INDEX پیدا می‌کنیم (به‌جای فرض id)
        $pk = 'id';
        foreach ($pdo->query("SHOW INDEX FROM personal_notes") as $ix) {
            if (($ix['Key_name'] ?? '') === 'PRIMARY') { $pk = $ix['Column_name']; break; }
        }
        echo "ℹ️  کلید اولیه: {$pk}\n";

        // جفت‌های تکراری را پیدا کن و برای هر گروه، همه‌چیز جز کمترین id را حذف کن
        // (نسخهٔ قبلی با «DELETE t1 FROM ... JOIN ...» روی MySQL هاست خطای
        //  1054 Unknown column 't1.id' می‌داد؛ این روش استاندارد است)
        $dups = $pdo->query("SELECT agencyId, username, MIN(`$pk`) AS keep_id, COUNT(*) AS c
                             FROM personal_notes
                             GROUP BY agencyId, username
                             HAVING COUNT(*) > 1")->fetchAll();
        $nGroups = count($dups);
        $del = $pdo->prepare("DELETE FROM personal_notes
                              WHERE agencyId = ? AND username = ? AND `$pk` <> ?");
        $removed = 0;
        foreach ($dups as $d) {
            $del->execute([$d['agencyId'], $d['username'], $d['keep_id']]);
            $removed += (int)$del->rowCount();
        }
        echo "ℹ️  {$nGroups} جفت تکراری پیدا شد و {$removed} ردیف حذف شد.\n";

        $pdo->exec("ALTER TABLE personal_notes ADD UNIQUE KEY uniq_agency_user (agencyId, username)");
        echo "✅ کلید UNIQUE اضافه شد.\n";
    } catch (Throwable $e) {
        echo "❌ شکست: " . $e->getMessage() . "\n(این پیام را کامل برای من بفرست)\n";
        // دیاگنوستیک: ساختار واقعی جدول را نشان بده تا دقیق بدانیم مشکل چیست
        try {
            echo "── SHOW CREATE TABLE personal_notes ──\n";
            foreach ($pdo->query("SHOW CREATE TABLE personal_notes") as $r) {
                foreach ($r as $v) { if (is_string($v) && stripos($v, 'CREATE') === 0) { echo $v . "\n"; } }
            }
            echo "── SHOW COLUMNS ──\n";
            foreach ($pdo->query("SHOW COLUMNS FROM personal_notes") as $c) { echo "  " . $c['Field'] . " : " . $c['Type'] . "\n"; }
        } catch (Throwable $e2) { echo "(خواندن ساختار هم ناموفق بود: " . $e2->getMessage() . ")\n"; }
    }
    exit;
}

/* ── راهنما ── */
echo "🩺 ابزار پین — دستورها:\n";
echo "  ?key=" . PIN_KEY . "&do=list\n";
echo "  ?key=" . PIN_KEY . "&do=reset&id=کد-آژانس&pin=1234\n";
echo "  ?key=" . PIN_KEY . "&do=fixdb\n";
