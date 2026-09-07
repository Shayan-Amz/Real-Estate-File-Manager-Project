<?php
// Run: php tests/guest_api_test.php
// Also executable by @php-wasm/cli. Real PHP + in-memory SQLite, NOT live MySQL/load testing.
define('AMLAK_GUEST_API', true);
define('APP_SALT', 'guest-test-only-not-a-deployment-secret');
require __DIR__ . '/../src/guest_api.php';

function check($condition, $message = 'Assertion failed') {
    if (!$condition) throw new RuntimeException($message);
}
function same($actual, $expected, $message = 'Values differ') {
    if ($actual !== $expected) throw new RuntimeException($message . ': ' . json_encode([$actual, $expected], JSON_UNESCAPED_UNICODE));
}
function invalid($callback) {
    try { $callback(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException('Expected an invalid-input error');
}
function addProperty($pdo, array $changes) {
    $row = array_merge([
        'id' => 'prop_extra', 'agencyId' => '100001', 'authorName' => 'مشاور آزمایشی', 'status' => 'موجود',
        'city' => 'تهران', 'location' => 'مرکز', 'lat' => '35.1', 'lng' => '51.1', 'usage_type' => 'مسکونی',
        'area' => 100, 'buildArea' => 90, 'rooms' => '2', 'floor' => '1', 'unit' => '2', 'yearBuilt' => '1400',
        'hasParking' => 1, 'hasElevator' => 1, 'hasStorage' => 1, 'dealType' => 'فروش', 'description' => 'آپارتمان روشن',
        'canExchange' => 0, 'canPartner' => 0, 'isPreSale' => 0, 'isVIP' => 0, 'showToGuest' => 1,
        'showPriceGuest' => 1, 'showImagesGuest' => 1, 'date' => '2026-09-07 12:00:00',
        'images' => '["uploads/public.jpg"]', 'price' => 1000, 'deposit' => 200, 'rent' => 20,
        'phone' => 'PRIVATE_OWNER_PHONE', 'phone2' => 'PRIVATE_SECOND_PHONE', 'referrer' => 'PRIVATE_OWNER_NAME',
        'exactAddress' => 'PRIVATE_EXACT_ADDRESS', 'internalNote' => 'PRIVATE_NOTE', 'soldBy' => 'PRIVATE_SOLD_BY'
    ], $changes);
    $pdo->prepare('INSERT INTO properties (' . implode(',', array_keys($row)) . ') VALUES ('
        . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
}
function fixture() {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE sys_config (conf_key TEXT PRIMARY KEY, conf_val TEXT);
        INSERT INTO sys_config VALUES ('last_update', '100');
        CREATE TABLE agencies (id TEXT PRIMARY KEY, name TEXT, city TEXT, phone TEXT, phone2 TEXT, plan_type TEXT,
            managerName TEXT, expireAt TEXT, createdAt TEXT, adminPin TEXT, masterPass TEXT);
        CREATE TABLE properties (id TEXT PRIMARY KEY, agencyId TEXT, authorName TEXT, status TEXT, city TEXT,
            location TEXT, lat TEXT, lng TEXT, usage_type TEXT, area INTEGER, buildArea INTEGER, rooms TEXT,
            floor TEXT, unit TEXT, yearBuilt TEXT, hasParking INTEGER, hasElevator INTEGER, hasStorage INTEGER,
            dealType TEXT, description TEXT, canExchange INTEGER, canPartner INTEGER, isPreSale INTEGER,
            isVIP INTEGER, showToGuest INTEGER, showPriceGuest INTEGER, showImagesGuest INTEGER, date TEXT,
            images TEXT, price INTEGER, deposit INTEGER, rent INTEGER, phone TEXT, phone2 TEXT, referrer TEXT,
            exactAddress TEXT, internalNote TEXT, soldBy TEXT);
        CREATE INDEX idx_guest_page ON properties (status, showToGuest, isVIP, date, id);
        CREATE INDEX idx_agency_guest_page ON properties (agencyId, status, showToGuest, isVIP, date, id);");
    $pdo->beginTransaction();
    for ($i = 1; $i <= 25; $i++) {
        $pdo->prepare('INSERT INTO agencies VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            (string)(100000 + $i), $i === 2 ? 'املاك ياس' : 'دفتر ' . $i, 'تهران', 'AGENCY_PHONE_' . $i, '', 'vip',
            'PRIVATE_MANAGER', '2099-01-01', '2026-09-07', 'PRIVATE_PIN_HASH', 'PRIVATE_MASTER_HASH'
        ]);
    }
    for ($i = 1; $i <= 65; $i++) {
        addProperty($pdo, ['id' => sprintf('prop_%04d', $i), 'agencyId' => $i <= 45 ? '100001' : '100002',
            'isVIP' => $i <= 24 ? 1 : 0, 'area' => 50 + $i, 'price' => 1000 + $i]);
    }
    addProperty($pdo, ['id' => 'prop_hidden', 'showToGuest' => 0]);
    addProperty($pdo, ['id' => 'prop_sold', 'status' => 'واگذار شده']);
    addProperty($pdo, ['id' => 'prop_secret_price', 'showPriceGuest' => 0, 'showImagesGuest' => 0,
        'price' => 987654321, 'deposit' => 9876543, 'rent' => 123456, 'images' => '["uploads/PRIVATE_IMAGE.jpg"]']);
    addProperty($pdo, ['id' => 'prop_rent', 'dealType' => 'رهن و اجاره', 'deposit' => 500, 'rent' => 50]);
    addProperty($pdo, ['id' => 'prop_full_rent', 'dealType' => 'رهن کامل', 'deposit' => 600, 'rent' => 0]);
    addProperty($pdo, ['id' => 'prop_farsi', 'location' => 'كيان ياس', 'usage_type' => 'تجاری']);
    addProperty($pdo, ['id' => 'prop_literal', 'location' => '100%_!']);
    $pdo->commit();
    return $pdo;
}
function crawl($pdo, $kind, array $query = []) {
    $items = []; $cursor = ''; $field = $kind === 'agencies' ? 'agencies' : 'properties';
    for ($i = 0; $i < 100; $i++) {
        $page = guestReadPage($pdo, $kind, array_merge($query, ['cursor' => $cursor]));
        check(count((array)$page[$field]) <= 20, 'Page too large');
        foreach ((array)$page[$field] as $id => $row) {
            check(!isset($items[$id]), 'Duplicate ID while paging'); $items[$id] = $row;
        }
        if (!$page['pagination']['hasMore']) { same($page['pagination']['nextCursor'], null); return $items; }
        $cursor = $page['pagination']['nextCursor'];
    }
    throw new RuntimeException('Pagination failed to terminate');
}
class RecordedDB {
    public $sql = [];
    private $pdo;
    function __construct($pdo) { $this->pdo = $pdo; }
    function query($sql) { $this->sql[] = $sql; return $this->pdo->query($sql); }
    function prepare($sql) { $this->sql[] = $sql; return $this->pdo->prepare($sql); }
}

$tests = [];
$tests['stable keyset pages have no omissions, duplicates, or sold/hidden files'] = function($pdo) {
    $rows = crawl($pdo, 'properties');
    $expected = $pdo->query("SELECT id FROM properties WHERE status = 'موجود' AND showToGuest = 1 ORDER BY isVIP DESC, date DESC, id DESC")->fetchAll(PDO::FETCH_COLUMN);
    same(array_keys($rows), $expected);
    check(!isset($rows['prop_hidden']) && !isset($rows['prop_sold']));
};
$tests['page limit cannot be raised by client parameters'] = function($pdo) {
    $page = guestReadPage($pdo, 'properties', ['limit' => '100000', 'pageSize' => '100000', 'page' => '-1']);
    same(count((array)$page['properties']), 20);
    same($page['pagination']['pageSize'], 20);
    check($page['pagination']['hasMore']);
    check(count((array)$page['agencies']) <= 20);
};
$tests['public property projection excludes every private field and hidden value'] = function($pdo) {
    $rows = crawl($pdo, 'properties');
    foreach ($rows as $row) {
        foreach (['phone','phone2','referrer','exactAddress','internalNote','soldBy'] as $private) check(!array_key_exists($private, $row));
        check((bool)preg_match('/^ag_[a-f0-9]{32}$/D', $row['agencyId']));
    }
    foreach (['price', 'deposit', 'rent'] as $key) check(!array_key_exists($key, $rows['prop_secret_price']));
    same($rows['prop_secret_price']['images'], []);
    same($rows['prop_0001']['images'], ['uploads/public.jpg']);
    check(strpos(json_encode($rows), 'PRIVATE_') === false);
};
$tests['directory is paged and counts all public files, not just the current property page'] = function($pdo) {
    $first = guestReadPage($pdo, 'agencies', []);
    same(count((array)$first['agencies']), 20);
    same(count((array)$first['properties']), 0);
    $agencies = crawl($pdo, 'agencies');
    same(count($agencies), 25);
    $own = $agencies[guestPublicAgencyId('100001')];
    $expected = (int)$pdo->query("SELECT COUNT(*) FROM properties WHERE agencyId = '100001' AND status = 'موجود' AND showToGuest = 1")->fetchColumn();
    same($own['publicCount'], $expected);
    check($own['publicCount'] > 20);
    same($agencies[guestPublicAgencyId('100025')]['publicCount'], 0);
    foreach ($agencies as $row) {
        same(guestPublicAgencyId(guestOpen($row['selector'], 'agency')), $row['id']);
        foreach (['managerName','expireAt','createdAt','adminPin','masterPass'] as $private) check(!array_key_exists($private, $row));
    }
    check(strpos(json_encode($agencies), 'PRIVATE_') === false);
    check(strpos(json_encode($agencies), '"100001"') === false);
};
$tests['selected agency uses an encrypted selector and still returns only public files'] = function($pdo) {
    $rows = crawl($pdo, 'properties', ['agency' => guestSeal('100001', 'agency')]);
    check(count($rows) > 20);
    foreach ($rows as $row) same($row['agencyId'], guestPublicAgencyId('100001'));
    check(!isset($rows['prop_hidden']) && !isset($rows['prop_sold']));
    invalid(function() use ($pdo) { guestReadPage($pdo, 'properties', ['agency' => '100001']); });
    $empty = guestReadPage($pdo, 'properties', ['agency' => guestSeal('100025', 'agency')]);
    same(count((array)$empty['properties']), 0);
    same(count((array)$empty['agencies']), 1); // Keep selected agency contact metadata even with no files.
};
$tests['Arabic/Persian equivalents work for public property and directory text search'] = function($pdo) {
    same(array_keys(crawl($pdo, 'properties', ['q' => 'کیان یاس'])), ['prop_farsi']);
    same(array_keys(crawl($pdo, 'agencies', ['q' => 'املاک یاس'])), [guestPublicAgencyId('100002')]);
    same(count(crawl($pdo, 'properties', ['q' => 'PRIVATE_OWNER_PHONE'])), 0);
    same(count(crawl($pdo, 'properties', ['q' => 'PRIVATE_NOTE'])), 0);
};
$tests['LIKE metacharacters are literal and injection strings do not broaden results'] = function($pdo) {
    same(array_keys(crawl($pdo, 'properties', ['q' => '%_!'])), ['prop_literal']);
    same(count(crawl($pdo, 'properties', ['q' => "' OR 1=1 --"])), 0);
};
$tests['usage, deal, and inclusive numeric bounds are applied on the server'] = function($pdo) {
    same(array_keys(crawl($pdo, 'properties', ['usage' => 'تجاری'])), ['prop_farsi']);
    same(array_keys(crawl($pdo, 'properties', ['deal' => 'رهن و اجاره', 'minPrice' => '۵۰۰', 'maxPrice' => '500', 'minRent' => '۵۰', 'maxRent' => '50', 'minArea' => '100', 'maxArea' => '100'])), ['prop_rent']);
    same(array_keys(crawl($pdo, 'properties', ['deal' => 'رهن کامل', 'minPrice' => '600', 'maxPrice' => '600'])), ['prop_full_rent']);
    same(count(crawl($pdo, 'properties', ['minArea' => '200', 'maxArea' => '100'])), 0);
    same(count(crawl($pdo, 'properties', ['deal' => 'فروش', 'minRent' => '0'])), 0);
};
$tests['hidden prices behave like zero, never like their secret stored amount'] = function($pdo) {
    $maxZero = crawl($pdo, 'properties', ['maxPrice' => '0']);
    same(array_keys($maxZero), ['prop_secret_price']);
    check(!isset(crawl($pdo, 'properties', ['minPrice' => '1'])['prop_secret_price']));
    $before = array_keys(crawl($pdo, 'properties', ['minPrice' => '500', 'maxPrice' => '999999999']));
    $pdo->exec("UPDATE properties SET price = 800, deposit = 1, rent = 1 WHERE id = 'prop_secret_price'");
    same(array_keys(crawl($pdo, 'properties', ['minPrice' => '500', 'maxPrice' => '999999999'])), $before);
    addProperty($pdo, ['id' => 'hidden_rent', 'showPriceGuest' => 0, 'dealType' => 'رهن و اجاره', 'rent' => 999999]);
    check(isset(crawl($pdo, 'properties', ['minRent' => '0', 'maxRent' => '0'])['hidden_rent']));
    check(!isset(crawl($pdo, 'properties', ['minRent' => '1'])['hidden_rent']));
};
$tests['hash is bound to query, kind, cursor, and the existing global version'] = function($pdo) {
    $db = new RecordedDB($pdo);
    $first = guestReadPage($db, 'properties', []);
    $db->sql = [];
    same(guestReadPage($db, 'properties', [], $first['dataHash'])['unmodified'], true);
    same(count($db->sql), 1); // Only last_update lookup, before all listing/count queries.
    check(!isset(guestReadPage($db, 'properties', ['q' => 'کیان'], $first['dataHash'])['unmodified']));
    check(!isset(guestReadPage($db, 'agencies', [], $first['dataHash'])['unmodified']));
    check(!isset(guestReadPage($db, 'properties', ['cursor' => $first['pagination']['nextCursor']], $first['dataHash'])['unmodified']));
    $pdo->exec("UPDATE sys_config SET conf_val = '101' WHERE conf_key = 'last_update'");
    check(!isset(guestReadPage($db, 'properties', [], $first['dataHash'])['unmodified']));
    $pdo->exec("UPDATE sys_config SET conf_val = '0' WHERE conf_key = 'last_update'");
    $zero = guestReadPage($db, 'properties', []);
    check(!isset(guestReadPage($db, 'properties', [], $zero['dataHash'])['unmodified']));
};
$tests['tampered, wrong-purpose, and wrong-query cursors are rejected'] = function($pdo) {
    $first = guestReadPage($pdo, 'properties', []);
    $cursor = $first['pagination']['nextCursor'];
    $bad = $cursor; $bad[30] = $bad[30] === 'A' ? 'B' : 'A';
    invalid(function() use ($pdo, $bad) { guestReadPage($pdo, 'properties', ['cursor' => $bad]); });
    invalid(function() use ($pdo, $cursor) { guestReadPage($pdo, 'properties', ['cursor' => $cursor, 'q' => 'کیان']); });
    invalid(function() use ($pdo, $cursor) { guestReadPage($pdo, 'agencies', ['cursor' => $cursor]); });
    invalid(function() use ($pdo, $cursor) { guestReadPage($pdo, 'properties', ['agency' => $cursor]); });
    invalid(function() use ($pdo) { guestReadPage($pdo, 'properties', ['cursor' => guestSeal('100001', 'agency')]); });
};
$tests['malformed scalar/number/cursor filters fail without running a listing'] = function($pdo) {
    foreach ([['q' => ['array']], ['q' => "\xFF"], ['q' => str_repeat('x', 641)], ['minArea' => '-1'], ['minPrice' => '1e9'],
        ['maxPrice' => '1000000000000001'], ['usage' => "' OR 1=1"], ['cursor' => str_repeat('x', 2049)], ['agency' => ['array']]] as $query) {
        $db = new RecordedDB($pdo);
        invalid(function() use ($db, $query) { guestReadPage($db, 'properties', $query); });
        same(count($db->sql), 0);
    }
};
$tests['legacy NULL sort values and tied dates terminate without skipped rows'] = function($pdo) {
    for ($i = 0; $i < 45; $i++) addProperty($pdo, ['id' => 'prop_null_' . $i, 'isVIP' => $i < 22 ? 0 : null, 'date' => null]);
    $rows = crawl($pdo, 'properties');
    $expected = $pdo->query("SELECT id FROM properties WHERE status = 'موجود' AND showToGuest = 1 ORDER BY isVIP DESC, date DESC, id DESC")->fetchAll(PDO::FETCH_COLUMN);
    same(array_keys($rows), $expected);
};
$tests['a cursor beyond deleted results returns a bounded empty page, not a full fallback'] = function($pdo) {
    $first = guestReadPage($pdo, 'properties', []);
    $pdo->exec('DELETE FROM properties');
    $page = guestReadPage($pdo, 'properties', ['cursor' => $first['pagination']['nextCursor']]);
    same(count((array)$page['properties']), 0);
    same($page['pagination']['hasMore'], false);
};
$tests['query shapes have bounded listings, bounded metadata, and no global property count'] = function($pdo) {
    $db = new RecordedDB($pdo);
    guestReadPage($db, 'properties', []);
    same(count($db->sql), 3);
    check(strpos($db->sql[1], ' LIMIT 21') !== false && strpos($db->sql[1], 'OFFSET') === false);
    check(strpos($db->sql[1], 'SELECT *') === false);
    check(strpos($db->sql[2], 'WHERE id IN (') !== false);
    $db->sql = [];
    guestReadPage($db, 'agencies', []);
    same(count($db->sql), 3);
    check(strpos($db->sql[1], ' LIMIT 21') !== false);
    check(strpos($db->sql[2], 'WHERE agencyId IN (') !== false);
    check(substr_count($db->sql[2], '?') <= 20);
};
$tests['a property page supplies agency names/phones for every displayed card'] = function($pdo) {
    $page = guestReadPage($pdo, 'properties', []);
    foreach ((array)$page['properties'] as $row) {
        check(isset($page['agencies']->{$row['agencyId']}));
        check(strpos($page['agencies']->{$row['agencyId']}['phone'], 'AGENCY_PHONE_') === 0);
    }
};

$tests['public action allowlist still blocks notes, writes, ping, and AI without auth'] = function($pdo) {
    $source = file_get_contents(__DIR__ . '/../src/api.php');
    check((bool)preg_match('/if \(!\$isFullyAuthenticated && !in_array\(\$action, (\[[^\n]+\]), true\)\)/', $source, $match));
    $allowed = eval('return ' . $match[1] . ';');
    same($allowed, ['loginManager', 'loginConsultant', 'saveAgency', 'getData', 'getGuestProperties', 'getGuestAgencies']);
    foreach (['getNotes','saveNotes','ping','jarvisProcess','transcribe_audio','saveProperty','deleteProperty','saveDemand','deleteDemand','saveMember','deleteMember','changeMyPassword','resetMemberPassword'] as $action) {
        check(!in_array($action, $allowed, true));
    }
};
$tests['legacy guest getData is rejected before hashing/queries; public routes are GET-only'] = function($pdo) {
    $source = file_get_contents(__DIR__ . '/../src/api.php');
    $body = explode("if (\$action === 'getData') {", $source, 2)[1];
    $beforeHash = explode('$clientHash', $body, 2)[0];
    check(strpos($beforeHash, 'http_response_code(409)') !== false);
    check(strpos($beforeHash, "'guest_client_upgrade'") !== false);
    check(strpos($beforeHash, 'exit;') !== false);
    check((bool)preg_match('/if \((!\$isFullyAuthenticated \|\| !\$agencyId \|\| !in_array\(\$userRole, \[[^\n]+\], true\))\)/', $beforeHash, $match));
    $isFullyAuthenticated = false; $agencyId = 'spoofed-agency'; $userRole = 'مهمان';
    same(eval('return ' . $match[1] . ';'), true);
    $isFullyAuthenticated = true; $agencyId = '100001'; $userRole = 'مدیر';
    same(eval('return ' . $match[1] . ';'), false);
    $userRole = 'مشاور'; same(eval('return ' . $match[1] . ';'), false);
    $route = explode("if (in_array(\$action, ['getGuestProperties', 'getGuestAgencies'], true)) {", $source, 2)[1];
    $route = explode("define('AMLAK_GUEST_API'", $route, 2)[0];
    check(strpos($route, "if (\$method !== 'GET')") !== false);
    check(strpos($route, 'http_response_code(405)') !== false);
};

// Optional: emit the actual prepared SQL for a separate MySQL-dialect parser.
if (($argv[1] ?? '') === '--dump-sql') {
    $db = new RecordedDB(fixture());
    $first = guestReadPage($db, 'properties', []);
    guestReadPage($db, 'properties', ['cursor' => $first['pagination']['nextCursor']]);
    guestReadPage($db, 'properties', ['q' => 'مرکز', 'usage' => 'مسکونی', 'deal' => 'رهن و اجاره',
        'minArea' => '80', 'maxArea' => '200', 'minPrice' => '10', 'maxPrice' => '1000', 'minRent' => '0', 'maxRent' => '100', 'agency' => guestSeal('100001', 'agency')]);
    $directory = guestReadPage($db, 'agencies', ['q' => 'تهران']);
    guestReadPage($db, 'agencies', ['q' => 'تهران', 'cursor' => $directory['pagination']['nextCursor']]);
    echo json_encode(array_values(array_unique($db->sql)), JSON_UNESCAPED_UNICODE);
    exit;
}

$failed = 0;
foreach ($tests as $name => $run) {
    try { $run(fixture()); echo "OK  $name\n"; }
    catch (Throwable $e) { $failed++; fwrite(STDERR, "FAIL $name: " . $e->getMessage() . "\n"); }
}
echo count($tests) . " PHP/SQLite tests; $failed failures.\n";
exit($failed ? 1 : 0);
