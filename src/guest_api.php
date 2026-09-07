<?php
// fix27: included only by the public, read-only actions in api.php.
if (!defined('AMLAK_GUEST_API')) { http_response_code(404); exit; }

const GUEST_PAGE_SIZE = 20;

function guestScalar(array $query, $key, $maxBytes = 640) {
    $value = $query[$key] ?? '';
    if (!is_string($value) || strlen($value) > $maxBytes || strpos($value, "\0") !== false || preg_match('//u', $value) !== 1) {
        throw new InvalidArgumentException('فیلتر جستجو نامعتبر است.');
    }
    return trim($value);
}

function guestNormalize($text) {
    $text = str_replace(['ي', 'ك', 'ة'], ['ی', 'ک', 'ه'], $text);
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function guestNumber(array $query, $key) {
    $text = guestScalar($query, $key, 64);
    $text = str_replace(
        ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩',',','٬'],
        ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9','',''], $text
    );
    if ($text === '') return null;
    if (!preg_match('/^[0-9]{1,16}$/D', $text) || (float)$text > 1000000000000000) {
        throw new InvalidArgumentException('عدد واردشده در فیلتر نامعتبر است.');
    }
    return (int)$text;
}

// Authenticated encryption keeps the agency's login code out of public IDs and
// pagination cursors. No lookup by SHA2(id), schema column, or whole-directory scan.
function guestSeal($value, $purpose) {
    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('افزونهٔ OpenSSL برای جستجوی مهمان باید فعال باشد.');
    }
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(json_encode($value, JSON_UNESCAPED_UNICODE), 'aes-256-gcm',
        hash('sha256', 'amlak-guest-fix27|' . APP_SALT, true), OPENSSL_RAW_DATA, $iv, $tag,
        'guest-v1|' . $purpose, 16);
    if ($ciphertext === false) throw new RuntimeException('رمزنگاری شناسهٔ عمومی در دسترس نیست.');
    return 'g1.' . rtrim(strtr(base64_encode($iv . $tag . $ciphertext), '+/', '-_'), '=');
}

function guestOpen($token, $purpose) {
    if (strlen($token) > 2048 || !preg_match('/^g1\.[A-Za-z0-9_-]+$/D', $token)) {
        throw new InvalidArgumentException('شناسهٔ صفحه یا آژانس نامعتبر است؛ جستجو را از ابتدا باز کنید.');
    }
    if (!function_exists('openssl_decrypt')) throw new RuntimeException('افزونهٔ OpenSSL باید فعال باشد.');
    $raw = base64_decode(strtr(substr($token, 3), '-_', '+/'), true);
    if ($raw === false || strlen($raw) <= 28) throw new InvalidArgumentException('شناسهٔ صفحه نامعتبر است.');
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm',
        hash('sha256', 'amlak-guest-fix27|' . APP_SALT, true), OPENSSL_RAW_DATA,
        substr($raw, 0, 12), substr($raw, 12, 16), 'guest-v1|' . $purpose);
    if ($plain === false) throw new InvalidArgumentException('شناسهٔ صفحه یا آژانس معتبر نیست.');
    $value = json_decode($plain, true);
    if (json_last_error() !== JSON_ERROR_NONE) throw new InvalidArgumentException('شناسهٔ صفحه نامعتبر است.');
    return $value;
}

function guestPublicAgencyId($id) {
    // 128 bits, rather than the old 32-bit mask: no small nationwide ID namespace.
    return 'ag_' . substr(hash('sha256', (string)$id . APP_SALT), 0, 32);
}

function guestFilters(array $query, $kind) {
    $filters = ['q' => guestNormalize(guestScalar($query, 'q'))];
    if ($kind === 'agencies') return $filters;
    $filters['usage'] = guestScalar($query, 'usage', 80);
    $filters['deal'] = guestScalar($query, 'deal', 80);
    if ($filters['usage'] === 'همه') $filters['usage'] = '';
    if ($filters['deal'] === 'همه') $filters['deal'] = '';
    if (!in_array($filters['usage'], ['', 'مسکونی', 'تجاری', 'اداری', 'زمین/کلنگی', 'باغ', 'ویلایی'], true)
        || !in_array($filters['deal'], ['', 'فروش', 'رهن و اجاره', 'رهن کامل'], true)) {
        throw new InvalidArgumentException('نوع کاربری یا واگذاری نامعتبر است.');
    }
    foreach (['minArea', 'maxArea', 'minPrice', 'maxPrice', 'minRent', 'maxRent'] as $key) {
        $filters[$key] = guestNumber($query, $key);
    }
    $selector = guestScalar($query, 'agency', 2048);
    $filters['agencyId'] = $selector === '' ? null : guestOpen($selector, 'agency');
    if ($filters['agencyId'] !== null
        && (!is_string($filters['agencyId']) || $filters['agencyId'] === '' || strlen($filters['agencyId']) > 255)) {
        throw new InvalidArgumentException('آژانس انتخاب‌شده نامعتبر است.');
    }
    return $filters;
}

function guestSearchCondition(array $columns, $text, array &$params) {
    // LIKE treats %, _ and ! literally, just like the old JS includes() search.
    $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text) . '%';
    $terms = [];
    foreach ($columns as $column) {
        // Column names are developer-owned constants, never request parameters.
        $terms[] = "REPLACE(REPLACE(REPLACE(LOWER(COALESCE($column, '')), 'ي', 'ی'), 'ك', 'ک'), 'ة', 'ه') LIKE ? ESCAPE '!'";
        $params[] = $pattern;
    }
    return '(' . implode(' OR ', $terms) . ')';
}

function guestWhere(array $filters, $kind, array &$params) {
    $where = [];
    if ($kind === 'agencies') {
        if ($filters['q'] !== '') $where[] = guestSearchCondition(['a.city', 'a.name'], $filters['q'], $params);
    } else {
        $where = ["p.status = 'موجود'", 'p.showToGuest = 1'];
        if ($filters['agencyId'] !== null) { $where[] = 'p.agencyId = ?'; $params[] = $filters['agencyId']; }
        if ($filters['q'] !== '') {
            $where[] = guestSearchCondition(['p.city', 'p.location', 'p.usage_type', 'p.dealType', 'p.description'], $filters['q'], $params);
        }
        foreach (['usage' => 'p.usage_type', 'deal' => 'p.dealType'] as $key => $column) {
            if ($filters[$key] !== '') { $where[] = "$column = ?"; $params[] = $filters[$key]; }
        }
        // Never filter on a secret price: the old guest JSON omitted these values,
        // and its JS treated them as zero. Preserve that behavior, including max=0.
        $price = "CASE WHEN p.showPriceGuest = 1 THEN CASE WHEN p.dealType = 'فروش' THEN COALESCE(p.price, 0) ELSE COALESCE(p.deposit, 0) END ELSE 0 END";
        $rent = 'CASE WHEN p.showPriceGuest = 1 THEN COALESCE(p.rent, 0) ELSE 0 END';
        if ($filters['minRent'] !== null || $filters['maxRent'] !== null) $where[] = "p.dealType = 'رهن و اجاره'";
        foreach (['Area' => 'COALESCE(p.area, 0)', 'Price' => $price, 'Rent' => $rent] as $field => $expression) {
            foreach (['min' => '>=', 'max' => '<='] as $prefix => $operator) {
                $value = $filters[$prefix . $field];
                if ($value !== null) { $where[] = "($expression) $operator ?"; $params[] = $value; }
            }
        }
    }
    return $where;
}

// Keyset pagination: no OFFSET and no nationwide COUNT on every page. The ID is
// the final tie-breaker. DESC handles legacy NULLs the same way as MySQL sorting.
function guestSeek(array $keys, $kind, array &$params) {
    if ($kind === 'agencies') { $params[] = $keys[0]; return 'a.id > ?'; }
    $columns = ['p.isVIP', 'p.date', 'p.id'];
    $terms = [];
    foreach ($columns as $i => $column) {
        if ($keys[$i] === null) continue; // NULL is already last in DESC order.
        $equal = [];
        for ($j = 0; $j < $i; $j++) {
            if ($keys[$j] === null) $equal[] = $columns[$j] . ' IS NULL';
            else { $equal[] = $columns[$j] . ' = ?'; $params[] = $keys[$j]; }
        }
        $equal[] = $i === 2 ? "$column < ?" : "($column < ? OR $column IS NULL)";
        $params[] = $keys[$i];
        $terms[] = '(' . implode(' AND ', $equal) . ')';
    }
    return '(' . implode(' OR ', $terms) . ')';
}

function guestPublicAgency(array $row, $includeSelector = false) {
    $out = [];
    foreach (['name', 'city', 'phone', 'phone2', 'plan_type'] as $key) $out[$key] = $row[$key] ?? '';
    $out['id'] = guestPublicAgencyId($row['id']);
    if ($includeSelector) $out['selector'] = guestSeal((string)$row['id'], 'agency');
    return $out;
}

function guestPublicProperty(array $row) {
    $out = [];
    foreach (['id', 'authorName', 'status', 'city', 'location', 'lat', 'lng', 'rooms', 'floor', 'unit', 'yearBuilt', 'dealType', 'description'] as $key) {
        $out[$key] = $row[$key] ?? '';
    }
    $out['agencyId'] = guestPublicAgencyId($row['agencyId']);
    $out['usage'] = $row['usage_type'];
    foreach (['area', 'buildArea'] as $key) $out[$key] = (int)($row[$key] ?? 0);
    foreach (['hasParking', 'hasElevator', 'hasStorage', 'canExchange', 'canPartner', 'isPreSale', 'isVIP', 'showToGuest', 'showPriceGuest', 'showImagesGuest'] as $key) {
        $out[$key] = (bool)($row[$key] ?? false);
    }
    $out['date'] = empty($row['date']) ? null : str_replace(' ', 'T', $row['date']) . 'Z';
    $out['images'] = [];
    if ($out['showImagesGuest']) {
        $images = json_decode($row['images'] ?? '[]', true);
        if (is_array($images)) $out['images'] = array_values($images);
    }
    if ($out['showPriceGuest']) {
        foreach (['price', 'deposit', 'rent'] as $key) $out[$key] = (float)($row[$key] ?? 0);
    }
    return $out;
}

function guestExecute($stmt, array $params) {
    // PDO execute(array) treats all values as strings. Bind numeric bounds and
    // seek flags explicitly so CASE/COALESCE comparisons keep numeric semantics.
    foreach (array_values($params) as $index => $value) {
        $type = $value === null ? PDO::PARAM_NULL : (is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $stmt->bindValue($index + 1, $value, $type);
    }
    $stmt->execute();
}

function guestReadPage($pdo, $kind, array $query, $clientHash = '') {
    if (!in_array($kind, ['properties', 'agencies'], true)) throw new InvalidArgumentException('نوع جستجو نامعتبر است.');
    $filters = guestFilters($query, $kind);
    $fingerprint = hash('sha256', $kind . '|' . json_encode($filters, JSON_UNESCAPED_UNICODE));
    $cursor = guestScalar($query, 'cursor', 2048);
    $keys = null;
    if ($cursor !== '') {
        $decoded = guestOpen($cursor, 'cursor-' . $kind);
        if (!is_array($decoded) || ($decoded['f'] ?? '') !== $fingerprint || !isset($decoded['k']) || !is_array($decoded['k'])
            || count($decoded['k']) !== ($kind === 'agencies' ? 1 : 3)) {
            throw new InvalidArgumentException('صفحه متعلق به این جستجو نیست؛ از صفحهٔ اول شروع کنید.');
        }
        $keys = array_values($decoded['k']);
        foreach ($keys as $key) {
            if ($key !== null && !is_string($key) && !is_int($key)) throw new InvalidArgumentException('شناسهٔ صفحه نامعتبر است.');
        }
        if (!is_string($keys[count($keys) - 1]) || $keys[count($keys) - 1] === '') throw new InvalidArgumentException('شناسهٔ صفحه نامعتبر است.');
    }

    // Same global invalidation as before; the hash is also bound to THIS query
    // and cursor, so visiting another page/filter can never return unmodified.
    $version = (string)($pdo->query("SELECT conf_val FROM sys_config WHERE conf_key = 'last_update'")->fetchColumn() ?: '0');
    // Key rotation also invalidates cached public IDs/selectors.
    $hash = hash_hmac('sha256', 'guest-fix27|' . $version . '|' . $fingerprint . '|' . $cursor, APP_SALT);
    if ($version !== '0' && is_string($clientHash) && hash_equals($hash, $clientHash)) {
        return ['guestApiVersion' => 1, 'unmodified' => true];
    }

    $params = [];
    $where = guestWhere($filters, $kind, $params);
    if ($keys !== null) $where[] = guestSeek($keys, $kind, $params);
    $condition = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    if ($kind === 'agencies') {
        $sql = 'SELECT a.id, a.name, a.city, a.phone, a.phone2, a.plan_type FROM agencies a' . $condition . ' ORDER BY a.id ASC';
    } else {
        // Explicit public projection: private owner/address/note fields are not
        // even selected; hidden prices/images are nulled inside the database.
        $sql = "SELECT p.id, p.agencyId, p.authorName, p.status, p.city, p.location, p.lat, p.lng,
            p.usage_type, p.area, p.buildArea, p.rooms, p.floor, p.unit, p.yearBuilt, p.hasParking,
            p.hasElevator, p.hasStorage, p.dealType, p.description, p.canExchange, p.canPartner,
            p.isPreSale, p.isVIP, p.showToGuest, p.showPriceGuest, p.showImagesGuest, p.date,
            CASE WHEN p.showPriceGuest = 1 THEN p.price ELSE NULL END AS price,
            CASE WHEN p.showPriceGuest = 1 THEN p.deposit ELSE NULL END AS deposit,
            CASE WHEN p.showPriceGuest = 1 THEN p.rent ELSE NULL END AS rent,
            CASE WHEN p.showImagesGuest = 1 THEN p.images ELSE '[]' END AS images
            FROM properties p" . $condition . ' ORDER BY p.isVIP DESC, p.date DESC, p.id DESC';
    }
    // Only a compile-time integer is appended; client page/limit values are ignored.
    $stmt = $pdo->prepare($sql . ' LIMIT ' . (GUEST_PAGE_SIZE + 1));
    guestExecute($stmt, $params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > GUEST_PAGE_SIZE;
    if ($hasMore) array_pop($rows);
    $nextCursor = null;
    if ($hasMore) {
        $last = $rows[count($rows) - 1];
        $nextKeys = $kind === 'agencies' ? [(string)$last['id']] : [$last['isVIP'] === null ? null : (int)$last['isVIP'], $last['date'], (string)$last['id']];
        $nextCursor = guestSeal(['f' => $fingerprint, 'k' => $nextKeys], 'cursor-' . $kind);
    }

    $out = ['guestApiVersion' => 1, 'properties' => (object)[], 'agencies' => (object)[],
        'pagination' => ['pageSize' => GUEST_PAGE_SIZE, 'hasMore' => $hasMore, 'nextCursor' => $nextCursor], 'dataHash' => $hash];
    if ($kind === 'agencies') {
        $counts = [];
        if ($rows) {
            $ids = array_column($rows, 'id');
            $stmt = $pdo->prepare("SELECT agencyId, COUNT(*) AS publicCount FROM properties WHERE agencyId IN ("
                . implode(',', array_fill(0, count($ids), '?')) . ") AND status = 'موجود' AND showToGuest = 1 GROUP BY agencyId");
            $stmt->execute($ids);
            while ($count = $stmt->fetch(PDO::FETCH_ASSOC)) $counts[$count['agencyId']] = (int)$count['publicCount'];
        }
        foreach ($rows as $row) {
            $agency = guestPublicAgency($row, true);
            $agency['publicCount'] = $counts[$row['id']] ?? 0;
            $out['agencies']->{$agency['id']} = $agency;
        }
    } else {
        $ids = [];
        foreach ($rows as $row) {
            $property = guestPublicProperty($row);
            $out['properties']->{$property['id']} = $property;
            $ids[(string)$row['agencyId']] = (string)$row['agencyId'];
        }
        if ($filters['agencyId'] !== null) $ids[$filters['agencyId']] = $filters['agencyId'];
        if ($ids) {
            $stmt = $pdo->prepare('SELECT id, name, city, phone, phone2, plan_type FROM agencies WHERE id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ')');
            $stmt->execute(array_values($ids));
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $agency = guestPublicAgency($row);
                $out['agencies']->{$agency['id']} = $agency;
            }
        }
    }
    return $out;
}
