<?php
session_start();
require_once 'config.php';

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

function gregorian_to_jalali($gy, $gm, $gd) {
    $g_d_m = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100)) + ((int)(($gy2 + 399) / 400)) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * ((int)($days / 12053))); $days %= 12053; $jy += 4 * ((int)($days / 1461)); $days %= 1461;
    if ($days > 365) { $jy += (int)(($days - 1) / 365); $days = ($days - 1) % 365; }
    $jm = ($days < 186) ? 1 + (int)($days / 31) : 7 + (int)(($days - 186) / 30);
    $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));
    return array($jy, $jm, $jd);
}

function getJalaliDate($dateStr) {
    if (empty($dateStr)) return 'نامشخص';
    $ts = strtotime($dateStr);
    list($jy, $jm, $jd) = gregorian_to_jalali((int)date('Y', $ts), (int)date('m', $ts), (int)date('d', $ts));
    return $jy . '/' . sprintf('%02d', $jm) . '/' . sprintf('%02d', $jd);
}

if (isset($_GET['logout'])) { session_destroy(); header("Location: " . basename($_SERVER['PHP_SELF'])); exit; }

if (isset($_POST['password'])) {
    sleep(1);
    if (password_verify($_POST['password'], MASTER_PASSWORD_HASH)) {
        $_SESSION['master_logged_in'] = true; session_regenerate_id(true); 
    } else { $error = "رمز عبور اشتباه است!"; }
}

if (!isset($_SESSION['master_logged_in'])) {
    echo "<html dir='rtl'><body style='font-family: Tahoma; background: #f8fafc; display: flex; justify-content: center; align-items: center; height: 100vh;'><form method='POST' style='background: white; padding: 30px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); text-align: center;'><h2 style='color: #1e40af; margin-top: 0;'>🔒 ورود به مدیریت لایسنس‌ها</h2>";
    if (isset($error)) echo "<p style='color: red; font-weight:bold;'>$error</p>";
    echo "<input type='password' name='password' placeholder='رمز مالک سیستم...' style='padding: 10px; width: 250px; border: 1px solid #ccc; border-radius: 6px; margin-bottom: 15px;' required autocomplete='off'><br><button type='submit' style='background: #2563eb; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-weight: bold;'>ورود امن</button></form></body></html>";
    exit;
}

$action_performed = false;
if (isset($_GET['delete']) || isset($_GET['extend']) || isset($_GET['change_pass']) || isset($_GET['change_plan'])) {
    if (!isset($_GET['token']) || !hash_equals($_SESSION['csrf_token'], $_GET['token'])) { die("<h3 style='color:red; text-align:center;'>⛔ خطای امنیتی!</h3>"); }
    $action_performed = true;
}

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    if (isset($_GET['delete'])) {
        $id = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['delete']);
        $pdo->prepare("DELETE FROM agencies WHERE id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM properties WHERE agencyId = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM members WHERE agencyId = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM demands WHERE agencyId = ?")->execute([$id]);
        $pdo->exec("INSERT INTO sys_config (conf_key, conf_val) VALUES ('last_update', UNIX_TIMESTAMP()) ON DUPLICATE KEY UPDATE conf_val = UNIX_TIMESTAMP()");
        $msg = "آژانس و اطلاعات آن با موفقیت حذف شد!";
    }

    if (isset($_GET['extend']) && isset($_GET['days'])) {
        $id = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['extend']);
        $days = (int)$_GET['days'];
        $stmt = $pdo->prepare("SELECT expireAt FROM agencies WHERE id = ?"); $stmt->execute([$id]);
        if ($row = $stmt->fetch()) {
            $currentExpire = strtotime($row['expireAt']);
            $newExpire = ($currentExpire > time() ? $currentExpire : time()) + ($days * 24 * 60 * 60);
            $pdo->prepare("UPDATE agencies SET expireAt = ? WHERE id = ?")->execute([date('Y-m-d H:i:s', $newExpire), $id]);
            $msg = "🎉 اشتراک آژانس با موفقیت تمدید شد!";
        }
    }

    if (isset($_GET['change_pass']) && !empty($_GET['new_pass'])) {
        $id = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['change_pass']);
        $newPass = password_hash($_GET['new_pass'], PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE agencies SET adminPin = ? WHERE id = ?")->execute([$newPass, $id]);
        $msg = "🔑 رمز عبور تغییر کرد!";
    }

    // ⚡ دکمه تغییر پلن
    if (isset($_GET['change_plan']) && isset($_GET['plan_type'])) {
        $id = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['change_plan']);
        $newPlan = $_GET['plan_type'];
        if(in_array($newPlan, ['basic', 'pro', 'vip'])) {
            $pdo->prepare("UPDATE agencies SET plan_type = ? WHERE id = ?")->execute([$newPlan, $id]);
            $planName = ($newPlan == 'basic') ? 'پایه 🥉' : (($newPlan == 'pro') ? 'حرفه‌ای 🥈' : 'وی‌آی‌پی 🥇');
            $msg = "✨ پلن آژانس با موفقیت به $planName تغییر یافت!";
        }
    }

    if ($action_performed && isset($msg)) {
        $_SESSION['flash_msg'] = $msg; header("Location: " . basename($_SERVER['PHP_SELF'])); exit;
    }

    $agencies = [];
    $stmt = $pdo->query("SELECT id, name, city, phone, phone2, managerName, expireAt, plan_type, (SELECT COUNT(*) FROM properties WHERE agencyId = agencies.id) as propCount FROM agencies");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $agencies[] = $row; }

} catch (Exception $e) { die("خطا در دیتابیس: " . htmlspecialchars($e->getMessage())); }
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت لایسنس‌ها</title>
    <style>
        body { font-family: Tahoma, sans-serif; background: #f1f5f9; margin: 0; padding: 20px; color: #334155; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border-top: 5px solid #1e40af;}
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: right; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; color: #0f172a; }
        .btn { padding: 6px 12px; border-radius: 6px; text-decoration: none; color: white; font-size: 12px; font-weight: bold; border: none; cursor: pointer; display: inline-block; margin: 2px; transition: 0.2s;}
        .btn:hover { opacity: 0.8; }
        .btn-extend-1m { background: #10b981; } .btn-extend-6m { background: #0ea5e9; } .btn-extend-1y { background: #8b5cf6; }
        .btn-delete { background: #ef4444; } .btn-pass { background: #3b82f6; }
        .expired { color: #ef4444; font-weight: bold; } .active { color: #10b981; font-weight: bold; }
        .badge { padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; color: white; }
        .bg-basic { background: #b45309; } .bg-pro { background: #475569; } .bg-vip { background: #ca8a04; }
    </style>
    <script>
        const csrfToken = "<?= $csrf ?>";
        function changePass(id, name) { let newPass = prompt("رمز جدید برای (" + name + "):"); if (newPass) { window.location.href = "?change_pass=" + encodeURIComponent(id) + "&new_pass=" + encodeURIComponent(newPass.trim()) + "&token=" + csrfToken; } }
        function confirmDelete(url) { if(confirm('حذف کامل آژانس؟ این عمل غیرقابل بازگشت است!')) { window.location.href = url; } return false; }
    </script>
</head>
<body>
    <div class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px;">
            <h2 style="color: #1e40af; margin: 0;">🛡️ کنترل پنل امن لایسنس‌ها</h2>
            <a href="?logout=1" style="color: #ef4444; font-weight: bold; background: #fee2e2; padding: 8px 12px; border-radius: 8px; text-decoration: none;">خروج 🚪</a>
        </div>
        
        <?php if(isset($_SESSION['flash_msg'])) { echo "<div style='background: #dcfce7; color: #166534; padding: 10px; border-radius: 8px; margin-top: 15px; font-weight: bold; border: 1px solid #bbf7d0;'>" . $_SESSION['flash_msg'] . "</div>"; unset($_SESSION['flash_msg']); } ?>

        <table>
            <thead><tr><th>کد / پلن</th><th>آژانس</th><th>فایل‌ها</th><th>انقضا</th><th>مدیریت پلن و تمدید</th></tr></thead>
            <tbody>
            <?php foreach($agencies as $ag): 
                $isExpired = strtotime($ag['expireAt']) < time();
                $shamsiExpDate = getJalaliDate($ag['expireAt']);
                $planClass = ($ag['plan_type'] == 'pro') ? 'bg-pro' : (($ag['plan_type'] == 'vip') ? 'bg-vip' : 'bg-basic');
                $planName = ($ag['plan_type'] == 'pro') ? 'حرفه‌ای 🥈' : (($ag['plan_type'] == 'vip') ? 'وی‌آی‌پی 🥇' : 'پایه 🥉');
            ?>
            <tr>
                <td><span style="font-weight: bold; color: #2563eb; font-size: 14px;"><?= htmlspecialchars($ag['id']) ?></span><br><br><span class="badge <?= $planClass ?>"><?= $planName ?></span></td>
                <td><b><?= htmlspecialchars($ag['name']) ?></b><br><small>مدیر: <?= htmlspecialchars($ag['managerName']) ?></small><br><button onclick="changePass('<?= htmlspecialchars($ag['id']) ?>', '<?= htmlspecialchars($ag['name']) ?>')" class="btn btn-pass" style="padding: 4px 8px; font-size: 11px; margin-top: 6px;">🔑 تغییر رمز</button></td>
                <td><span style="background: #f1f5f9; padding: 4px 8px; border-radius: 20px; font-weight: bold; border: 1px solid #cbd5e1;"><?= $ag['propCount'] ?></span></td>
                <td class="<?= $isExpired ? 'expired' : 'active' ?>"><span style="font-size: 14px;"><?= $shamsiExpDate ?></span><br><small><?= $isExpired ? '(منقضی)' : '(فعال)' ?></small></td>
                <td>
                    <div style="display: flex; flex-direction: column; gap: 5px;">
                        <div>
                            <a href="?extend=<?= urlencode($ag['id']) ?>&days=30&token=<?= $csrf ?>" class="btn btn-extend-1m">+ ۱ ماه</a>
                            <a href="?extend=<?= urlencode($ag['id']) ?>&days=180&token=<?= $csrf ?>" class="btn btn-extend-6m">+ ۶ ماه</a>
                            <a href="?extend=<?= urlencode($ag['id']) ?>&days=365&token=<?= $csrf ?>" class="btn btn-extend-1y">+ ۱ سال</a>
                            <button onclick="confirmDelete('?delete=<?= urlencode($ag['id']) ?>&token=<?= $csrf ?>')" class="btn btn-delete">حذف</button>
                        </div>
                        <div style="background: #f8fafc; padding: 5px; border-radius: 6px; border: 1px dashed #cbd5e1;">
                            <span style="font-size: 11px; color: gray; margin-left: 5px;">تغییر پلن:</span>
                            <a href="?change_plan=<?= urlencode($ag['id']) ?>&plan_type=basic&token=<?= $csrf ?>" class="btn" style="background:#b45309;">پایه</a>
                            <a href="?change_plan=<?= urlencode($ag['id']) ?>&plan_type=pro&token=<?= $csrf ?>" class="btn" style="background:#475569;">حرفه‌ای</a>
                            <a href="?change_plan=<?= urlencode($ag['id']) ?>&plan_type=vip&token=<?= $csrf ?>" class="btn" style="background:#ca8a04;">VIP</a>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>