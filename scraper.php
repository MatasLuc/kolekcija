<?php
// scraper.php - V25 (Database State Version)
set_time_limit(0);
ignore_user_abort(true);

// 1. GLOBALUS PROCESO UŽRAKTAS (Failų sistemos lygmenyje, kad keli PHP procesai nesipjautų)
$lockFile = __DIR__ . '/scraper.lock';
$fpLock = fopen($lockFile, 'w+');
if (!flock($fpLock, LOCK_EX | LOCK_NB)) {
    die("SKIPPED: Skriptas jau veikia.");
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php'; 

// --- KONFIGŪRACIJA ---
$shopId = '30147'; 
$baseUrl = "https://pirkis.lt/shops/{$shopId}-e-Kolekcija.html";
$perPage = 20;
$maxLimit = 3000;
$cronTimeLimit = 25;    
$cooldownTime = 3600;   
$minItemsToAllowDelete = 100;

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'browser'; 
$startParam = isset($_GET['start']) ? (int)$_GET['start'] : null;
$secret = 'ManoSlaptasRaktas123'; 

$userAgents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/121.0',
];

// --- PAGALBINĖS FUNKCIJOS ---

function fetchUrl($url, $retries = 3) {
    global $userAgents;
    $attempt = 0;
    while ($attempt <= $retries) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $randomAgent = $userAgents[array_rand($userAgents)];
        curl_setopt($ch, CURLOPT_USERAGENT, $randomAgent);
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($data !== false && $httpCode === 200 && strlen($data) > 100) {
            return $data;
        }
        $attempt++;
        if ($attempt <= $retries) {
            sleep(pow(2, $attempt));
        }
    }
    return false;
}

// NAUJOS FUNKCIJOS darbui su DB būsena
function get_db_state(PDO $pdo): array {
    $stmt = $pdo->query("SELECT * FROM scraper_state WHERE id = 1");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Numatytieji nustatymai, jei lentelė tuščia (nors neturėtų būti)
    if (!$row) {
        return ['start' => 0, 'status' => 'finished', 'last_run' => 0, 'cycle_id' => '', 'total_processed' => 0, 'history' => []];
    }
    
    return [
        'start' => (int)$row['start_pos'],
        'status' => $row['status'],
        'last_run' => (int)$row['last_run'],
        'cycle_id' => $row['cycle_id'],
        'total_processed' => (int)$row['total_processed'],
        'history' => json_decode($row['history'] ?? '[]', true) ?: []
    ];
}

function save_db_state(PDO $pdo, array $state): void {
    $stmt = $pdo->prepare("UPDATE scraper_state SET 
        start_pos = :start, 
        status = :status, 
        last_run = :last_run, 
        cycle_id = :cycle_id, 
        total_processed = :total_processed,
        history = :history
        WHERE id = 1");
    
    $stmt->execute([
        ':start' => $state['start'],
        ':status' => $state['status'],
        ':last_run' => $state['last_run'],
        ':cycle_id' => $state['cycle_id'],
        ':total_processed' => $state['total_processed'],
        ':history' => json_encode($state['history'])
    ]);
}

function stopAndSave($pdo, $state, $currentStart, $reason) {
    $state['start'] = $currentStart;
    $state['status'] = 'running'; // Užtikriname, kad liktų running
    $state['last_run'] = time();
    save_db_state($pdo, $state);
    die($reason);
}

function finish_cycle($state, $pdo, $minItemsToAllowDelete, $mode = 'cron') {
    $currentCycleId = $state['cycle_id'];
    $totalFound = isset($state['total_processed']) ? (int)$state['total_processed'] : 0;
    
    // SAUGIKLIS
    if ($totalFound < $minItemsToAllowDelete) {
        $state['start'] = 0;
        $state['status'] = 'finished';
        $state['last_run'] = time();
        $state['total_processed'] = 0;
        save_db_state($pdo, $state);
        
        $msg = "SAUGIKLIS: Rasta tik $totalFound prekių. Trynimas ATŠAUKTAS.";
        if ($mode === 'browser') {
            die("<h2 style='color:red'>$msg</h2><a href='admin.php'>Grįžti į admin</a>");
        }
        die($msg);
    }

    // TRYNIMAS
    $deleted = 0;
    if (!empty($currentCycleId)) {
        $stmt = $pdo->prepare("DELETE FROM products WHERE source = 'pirkis' AND (cycle_id != ? OR cycle_id IS NULL)");
        $stmt->execute([$currentCycleId]);
        $deleted = $stmt->rowCount();
    }
    
    // ISTORIJA
    $history = isset($state['history']) ? $state['history'] : [];
    array_unshift($history, ['time' => time(), 'count' => $deleted]);
    $history = array_slice($history, 0, 5);
    
    $state['start'] = 0;
    $state['status'] = 'finished';
    $state['last_run'] = time();
    $state['history'] = $history;
    $state['total_processed'] = 0;
    
    save_db_state($pdo, $state);

    $msg = "CIKLAS BAIGTAS. Rasta: $totalFound. Išvalyta (ištrinta): $deleted.";
    if ($mode === 'browser') {
        echo "<hr><h2 style='color:green'>$msg</h2>";
        echo "<p>Jūsų duomenų bazė dabar sinchronizuota su Pirkis.lt.</p>";
        echo "<a href='admin.php' style='padding:10px 20px; background:#000; color:#fff; text-decoration:none; border-radius:5px;'>Grįžti į Admin</a>";
        exit;
    }
    die($msg);
}

// --- LOGIKA ---

if ($mode === 'cron') {
    if (!isset($_GET['key']) || $_GET['key'] !== $secret) die('Klaida: Neteisingas saugos raktas.');
}

// Užkrauname būseną iš DB
$state = get_db_state($pdo);

// Browser Mode RESET: Jei leidžiama per naršyklę su start=0, pradedame visiškai iš naujo
if ($mode === 'browser' && $startParam === 0) {
    $state['start'] = 0;
    $state['status'] = 'running';
    $state['total_processed'] = 0;
    $state['cycle_id'] = uniqid('RUN_');
    save_db_state($pdo, $state);
}

// Nustatome startinę poziciją
$start = ($mode === 'browser' && $startParam !== null) ? $startParam : $state['start'];

// CRON logikos ir "Cooldowm" valdymas
if ($mode === 'cron') {
    if (($state['status'] ?? '') === 'finished') {
        $secondsSinceFinish = time() - ($state['last_run'] ?? 0);
        
        if ($secondsSinceFinish < $cooldownTime) {
            die("Ilsisi (Liko " . round(($cooldownTime - $secondsSinceFinish)/60) . " min).");
        } else {
            // Praėjo laikas -> Pradedame naują ciklą
            $state['start'] = 0;
            $state['status'] = 'running';
            $state['total_processed'] = 0;
            $state['cycle_id'] = uniqid('RUN_');
            save_db_state($pdo, $state);
            $start = 0;
        }
    }
}

// Saugiklis: Jei statusas 'running', bet nėra cycle_id
if (empty($state['cycle_id'])) {
    $state['cycle_id'] = uniqid('RUN_');
    save_db_state($pdo, $state);
}

if ($mode === 'browser') {
    echo '<body style="font-family: monospace; background: #222; color: #0f0; padding: 20px; line-height: 1.5;">';
    echo "<h2>DUOMENŲ NUSKAITYMAS (DB Mode)</h2>";
    echo "<p>Ciklo ID: <strong>{$state['cycle_id']}</strong> | Rasta prekių: <strong>{$state['total_processed']}</strong></p><hr>";
}

// SQL Paruošimas
$upsertStmt = $pdo->prepare("
    INSERT INTO products (external_id, title, price, image_url, url, country, category, cycle_id, source, scraped_at) 
    VALUES (:eid, :title, :price, :img, :url, :country, :category, :cid, 'pirkis', NOW())
    ON DUPLICATE KEY UPDATE 
        title = VALUES(title), 
        price = VALUES(price), 
        image_url = IF(VALUES(image_url) != '', VALUES(image_url), image_url),
        country = VALUES(country), 
        category = VALUES(category), 
        cycle_id = VALUES(cycle_id), 
        scraped_at = NOW()
");

$touchStmt = $pdo->prepare("
    UPDATE products SET cycle_id = ?, scraped_at = NOW() WHERE external_id = ? AND source = 'pirkis'
");

$startTime = microtime(true);

// PAGRINDINIS CIKLAS
do {
    // 1. Patikrinimai
    if ($start > $maxLimit) {
        finish_cycle($state, $pdo, $minItemsToAllowDelete, $mode);
    }
    if ($mode === 'cron' && (microtime(true) - $startTime) >= $cronTimeLimit) {
        stopAndSave($pdo, $state, $start, "Laikas baigėsi ($start).");
    }

    // 2. Siunčiame užklausą
    $listUrl = $baseUrl . '?start=' . $start;
    if ($mode === 'browser') echo "<strong>Puslapis:</strong> start=$start ... ";
    
    $html = fetchUrl($listUrl);
    if (!$html) {
        if ($mode === 'cron') stopAndSave($pdo, $state, $start, "Klaida: Nepavyko gauti HTML.");
        die("Klaida: Nepavyko gauti turinio. Bandykite perkrauti.");
    }

    // 3. Analizuojame HTML
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    $productNodes = $xpath->query("//div[contains(@class, 'uk-prekes-row')]");

    // JEI PREKIŲ NERASTA (PABAIGA)
    if ($productNodes->length === 0) {
        if ($start == 0) {
             if ($mode === 'cron') stopAndSave($pdo, $state, $start, "Klaida: 0 prekių pirmame puslapyje.");
             die("Klaida: Nerasta prekių. Galbūt pasikeitė svetainės struktūra?");
        }
        finish_cycle($state, $pdo, $minItemsToAllowDelete, $mode);
        break; 
    }

    // 4. Surenkame duomenis
    $batchItems = [];
    $idsToFetch = [];

    foreach ($productNodes as $node) {
        $rowId = $node->getAttribute('id'); 
        $externalId = str_replace('item-row-', '', $rowId);
        $linkNode = $xpath->query(".//div[contains(@class, 'uk-prekes-title')]//a", $node)->item(0);
        if (!$linkNode) continue;

        $title = trim($linkNode->textContent);
        $href = $linkNode->getAttribute('href');
        if (strpos($href, '/') !== 0) $href = '/' . $href;
        $url = "https://pirkis.lt" . $href;

        $priceNode = $xpath->query(".//div[contains(@class, 'uk-prekes-kaina')]//span", $node)->item(0);
        $priceRaw = $priceNode ? trim($priceNode->textContent) : '0';
        $price = (float)str_replace([',', ' €', ' '], ['.', '', ''], $priceRaw);

        if ($externalId && $title) {
            $batchItems[] = ['eid' => $externalId, 'title' => $title, 'price' => $price, 'url' => $url];
            $idsToFetch[] = $externalId;
        }
    }

    // 5. Patikriname DB
    $existingMap = [];
    if (!empty($idsToFetch)) {
        $placeholders = implode(',', array_fill(0, count($idsToFetch), '?'));
        $stmt = $pdo->prepare("SELECT external_id, title, price, image_url, country, category FROM products WHERE source = 'pirkis' AND external_id IN ($placeholders)");
        $stmt->execute($idsToFetch);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $existingMap[$row['external_id']] = $row;
    }

    // 6. Įrašome į DB
    foreach ($batchItems as $item) {
        if ($mode === 'cron' && (microtime(true) - $startTime) > ($cronTimeLimit - 3)) {
            stopAndSave($pdo, $state, $start, "Laikas baigėsi cikle ($start).");
        }

        $eid = $item['eid'];
        $existing = isset($existingMap[$eid]) ? $existingMap[$eid] : null; 
        $hasPhoto = ($existing && !empty($existing['image_url']));
        $imgUrl = $hasPhoto ? $existing['image_url'] : '';
        $shouldUpdateDB = false;

        $country = $existing['country'] ?? null;
        $category = $existing['category'] ?? null;

        if (!$existing || !$country || !$category) {
            if (!$country) $country = function_exists('detect_country') ? detect_country($item['title']) : null;
            if (!$category) $category = function_exists('detect_category') ? detect_category($item['url']) : null;
            $shouldUpdateDB = true; 
        }

        if ($existing) {
            if ($existing['title'] !== $item['title']) $shouldUpdateDB = true;
            if (abs((float)$existing['price'] - $item['price']) > 0.001) $shouldUpdateDB = true;
        } else {
            $shouldUpdateDB = true;
        }

        if ($shouldUpdateDB && !$hasPhoto) {
            usleep(100000); 
            $innerHtml = fetchUrl($item['url']);
            if ($innerHtml) {
                $innerDom = new DOMDocument();
                libxml_use_internal_errors(true);
                @$innerDom->loadHTML('<?xml encoding="UTF-8">' . $innerHtml);
                libxml_clear_errors();
                $innerXpath = new DOMXPath($innerDom);

                $linkImg = $innerXpath->query("//a[@id='img1']")->item(0);
                if ($linkImg) {
                    $imgUrl = $linkImg->getAttribute('href');
                    $shouldUpdateDB = true; 
                } elseif ($metaImg = $innerXpath->query("//meta[@itemprop='http://schema.org/image']")->item(0)) {
                    $imgUrl = $metaImg->getAttribute('content');
                    $shouldUpdateDB = true;
                }
                if ($imgUrl && strpos($imgUrl, 'http') === false) $imgUrl = "https://pirkis.lt" . (strpos($imgUrl, '/') === 0 ? '' : '/') . $imgUrl;
            }
        }

        if ($shouldUpdateDB) {
            $upsertStmt->execute([
                ':eid' => $eid, ':title' => $item['title'], ':price' => $item['price'], ':img' => $imgUrl, 
                ':url' => $item['url'], ':country' => $country, ':category' => $category, ':cid' => $state['cycle_id']
            ]);
            if ($mode === 'browser') echo "<span style='color:orange'>.</span>";
        } else {
            $touchStmt->execute([$state['cycle_id'], $eid]);
            if ($mode === 'browser') echo "<span style='color:gray'>.</span>";
        }
        
        $state['total_processed']++;
    }

    $start += $perPage;
    
    // 7. Peradresavimas / Būsenos saugojimas
    if ($mode === 'browser') {
        $state['start'] = $start;
        $state['status'] = 'running';
        save_db_state($pdo, $state);

        echo " OK<br>";
        if (ob_get_level() > 0) { ob_flush(); flush(); }
        echo "<script>setTimeout(function(){ window.location.href = '?start=$start'; }, 500);</script>";
        echo "</body>";
        exit;
    }

} while ($mode === 'cron');
?>
