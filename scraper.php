<?php
// scraper.php - V21 (Enterprise: Delta Checks + Strict Source Protection + Atomic State)
set_time_limit(0);
ignore_user_abort(true);

// ---------------------------------------------------------
// 1. GLOBALUS PROCESO UŽRAKTAS (CRITICAL SECTION)
// ---------------------------------------------------------
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
$cronTimeLimit = 20;    
$cooldownTime = 3600;   
$stateFile = __DIR__ . '/scraper_state.json';
$minItemsToAllowDelete = 100;

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'browser'; 
$secret = 'ManoSlaptasRaktas123'; 

// ---------------------------------------------------------
// PAGALBINĖS FUNKCIJOS
// ---------------------------------------------------------

function fetchUrl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}

// ATOMINIS ĮRAŠYMAS (Atomic Write + LOCK_EX)
function saveState($file, $state) {
    $tempFile = $file . '.tmp';
    $json = json_encode($state, JSON_PRETTY_PRINT);
    
    // Rašome į laikiną failą su užraktu
    if (file_put_contents($tempFile, $json, LOCK_EX) === false) {
        return false;
    }
    
    // Atomic rename
    return rename($tempFile, $file);
}

function stopAndSave($stateFile, $state, $currentStart, $reason) {
    $state['start'] = $currentStart;
    $state['last_run'] = time();
    $state['status'] = 'running';
    saveState($stateFile, $state);
    die($reason);
}

function finish_cycle($state, $stateFile, $pdo, $minItemsToAllowDelete) {
    $currentCycleId = $state['cycle_id'];
    $totalFound = isset($state['total_processed']) ? (int)$state['total_processed'] : 0;
    
    // SAUGIKLIS
    if ($totalFound < $minItemsToAllowDelete) {
        $state['start'] = 0;
        $state['status'] = 'finished';
        $state['last_run'] = time();
        $state['total_processed'] = 0;
        saveState($stateFile, $state);
        die("SAUGIKLIS: Rasta tik $totalFound prekių. Trynimas ATŠAUKTAS.");
    }

    $deleted = 0;
    if (!empty($currentCycleId)) {
        // TRYNIMAS: Griežtai tik source='pirkis'
        $stmt = $pdo->prepare("DELETE FROM products WHERE source = 'pirkis' AND (cycle_id != ? OR cycle_id IS NULL)");
        $stmt->execute([$currentCycleId]);
        $deleted = $stmt->rowCount();
    }
    
    $history = isset($state['history']) ? $state['history'] : [];
    array_unshift($history, ['time' => time(), 'count' => $deleted]);
    $history = array_slice($history, 0, 5);
    
    $state['start'] = 0;
    $state['status'] = 'finished';
    $state['last_run'] = time();
    $state['history'] = $history;
    $state['total_processed'] = 0;
    
    saveState($stateFile, $state);
    die("CIKLAS BAIGTAS. Rasta: $totalFound. Išvalyta: $deleted.");
}

// ---------------------------------------------------------
// LOGIKA
// ---------------------------------------------------------

if ($mode === 'cron') {
    if (!isset($_GET['key']) || $_GET['key'] !== $secret) die('Klaida: Neteisingas saugos raktas.');
}

$state = ['start' => 0, 'status' => 'running', 'last_run' => 0, 'cycle_id' => uniqid('RUN_'), 'total_processed' => 0];

// SAUGUS NUSKAITYMAS
if (file_exists($stateFile)) {
    $content = file_get_contents($stateFile);
    if ($content) {
        $decoded = json_decode($content, true);
        // Tikriname, ar JSON nesugadintas
        if (is_array($decoded)) {
            $state = array_merge($state, $decoded);
        }
    }
}

// CIKLO VALDYMAS
if ($mode === 'cron' && ($state['status'] ?? '') === 'finished') {
    $secondsSinceFinish = time() - ($state['last_run'] ?? 0);
    if ($secondsSinceFinish < $cooldownTime) {
        die("Ilsisi (Liko " . round(($cooldownTime - $secondsSinceFinish)/60) . " min).");
    } else {
        $state['start'] = 0;
        $state['status'] = 'running';
        $state['total_processed'] = 0;
        $state['cycle_id'] = uniqid('RUN_');
        saveState($stateFile, $state);
    }
}

// Griežtas ID resetas startuojant
if ($state['start'] == 0 && $mode === 'cron') {
    // Užtikriname, kad turime unikalų ID šiam bėgimui, jei jis kažkodėl senas
    if (empty($state['cycle_id'])) {
        $state['cycle_id'] = uniqid('RUN_');
        saveState($stateFile, $state);
    }
}

$start = $mode === 'browser' ? (isset($_GET['start']) ? (int)$_GET['start'] : 0) : $state['start'];

if ($mode === 'browser') {
    echo '<body style="font-family: monospace; background: #222; color: #0f0; padding: 20px; line-height: 1.5;">';
    echo "<h2>DUOMENŲ NUSKAITYMAS (V21 - Enterprise)</h2>";
    echo "<p>Ciklo ID: <strong>{$state['cycle_id']}</strong></p><hr>";
}

// ---------------------------------------------------------
// 2. PARUOŠIAME SQL UŽKLAUSAS (PREPARED STATEMENTS)
// ---------------------------------------------------------
// Tai daroma VIENĄ KARTĄ per skripto vykdymą, ne cikle.

// A. UPSERT (Įterpti arba Atnaujinti)
// Svarbu: source nustatomas tik įterpiant. Atnaujinant source neliečiamas (saugiau).
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

// B. TOUCH (Tik atnaujinti laiką ir ID)
// Svarbu: WHERE source='pirkis' apsaugo rankines prekes.
$touchStmt = $pdo->prepare("
    UPDATE products 
    SET cycle_id = ?, scraped_at = NOW() 
    WHERE external_id = ? AND source = 'pirkis'
");


// ---------------------------------------------------------
// 3. CIKLAS
// ---------------------------------------------------------
$startTime = microtime(true);

do {
    // 1. LIMITAI
    if ($start > $maxLimit) {
        if ($mode === 'cron') finish_cycle($state, $stateFile, $pdo, $minItemsToAllowDelete);
        die("STOP: Pasiektas limitas.");
    }

    if ($mode === 'cron' && (microtime(true) - $startTime) >= $cronTimeLimit) {
        stopAndSave($stateFile, $state, $start, "Laikas baigėsi ($start).");
    }

    // 2. GAVIMAS
    $listUrl = $baseUrl . '?start=' . $start;
    if ($mode === 'browser') echo "<strong>Puslapis:</strong> start=$start ... <br>";
    
    $html = fetchUrl($listUrl);

    if (!$html || strlen($html) < 500) {
        if ($mode === 'cron') stopAndSave($stateFile, $state, $start, "Klaida: HTML tuščias.");
        sleep(2); continue;
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $productNodes = $xpath->query("//div[contains(@class, 'uk-prekes-row')]");

    if ($productNodes->length === 0) {
        if ($start == 0) {
             if ($mode === 'cron') stopAndSave($stateFile, $state, $start, "Klaida: 0 prekių pirmame puslapyje.");
             die("Klaida: Nerasta prekių.");
        }
        if ($mode === 'cron') finish_cycle($state, $stateFile, $pdo, $minItemsToAllowDelete);
        else echo "<hr><h1 style='color:green'>BAIGTA (Browser)!</h1>";
        break; 
    }

    // --- A. GAVIMAS (RAW) ---
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
            $batchItems[] = [
                'eid' => $externalId, 
                'title' => $title, 
                'price' => $price, 
                'url' => $url
            ];
            $idsToFetch[] = $externalId;
        }
    }

    // --- B. BULK SELECT (TIK 'PIRKIS' ŠALTINIO) ---
    $existingMap = [];
    if (!empty($idsToFetch)) {
        $placeholders = implode(',', array_fill(0, count($idsToFetch), '?'));
        // Paimame ir Title, Price, kad galėtume palyginti (Delta Check)
        // SVARBU: source='pirkis' - kitų šaltinių prekes ignoruojame (laikome, kad jos neegzistuoja šiam skreiperiui)
        $stmt = $pdo->prepare("
            SELECT external_id, title, price, image_url, country, category 
            FROM products 
            WHERE source = 'pirkis' AND external_id IN ($placeholders)
        ");
        $stmt->execute($idsToFetch);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existingMap[$row['external_id']] = $row;
        }
    }

    // --- C. APDOROJIMAS ---
    foreach ($batchItems as $item) {
        if ($mode === 'cron' && (microtime(true) - $startTime) > ($cronTimeLimit - 3)) {
            stopAndSave($stateFile, $state, $start, "Laikas baigėsi viduryje puslapio ($start).");
        }

        $eid = $item['eid'];
        $existing = isset($existingMap[$eid]) ? $existingMap[$eid] : null; 
        
        $hasPhoto = ($existing && !empty($existing['image_url']));
        $imgUrl = $hasPhoto ? $existing['image_url'] : '';
        $shouldUpdateDB = false;

        // 1. LAZY DETECTION (Tik jei nauja arba trūksta duomenų)
        $country = $existing['country'] ?? null;
        $category = $existing['category'] ?? null;

        if (!$existing || !$country || !$category) {
            if (!$country) $country = function_exists('detect_country') ? detect_country($item['title']) : null;
            if (!$category) $category = function_exists('detect_category') ? detect_category($item['url']) : null;
            $shouldUpdateDB = true; 
        }

        // 2. DELTA CHECK (TIKRINAME AR PASIKEITĖ DUOMENYS)
        if ($existing) {
            // Pavadinimas pasikeitė?
            if ($existing['title'] !== $item['title']) $shouldUpdateDB = true;
            // Kaina pasikeitė? (Float lyginimas)
            if (abs((float)$existing['price'] - $item['price']) > 0.001) $shouldUpdateDB = true;
        } else {
            // Jei nėra DB (arba tai 'manual' prekė, kurią ignoruojame map'e) -> Nauja prekė
            $shouldUpdateDB = true;
        }

        // 3. FOTO
        if ($shouldUpdateDB && !$hasPhoto) {
            usleep(200000); 
            $innerHtml = fetchUrl($item['url']);
            if ($innerHtml) {
                $innerDom = new DOMDocument();
                @$innerDom->loadHTML($innerHtml);
                $innerXpath = new DOMXPath($innerDom);

                $linkImg = $innerXpath->query("//a[@id='img1']")->item(0);
                if ($linkImg) {
                    $imgUrl = $linkImg->getAttribute('href');
                    $shouldUpdateDB = true; // Radom foto -> reikia update
                } elseif ($metaImg = $innerXpath->query("//meta[@itemprop='http://schema.org/image']")->item(0)) {
                    $imgUrl = $metaImg->getAttribute('content');
                    $shouldUpdateDB = true;
                }
                
                if ($imgUrl && strpos($imgUrl, 'http') === false) {
                    $imgUrl = "https://pirkis.lt" . (strpos($imgUrl, '/') === 0 ? '' : '/') . $imgUrl;
                }
            }
        }

        // --- D. VEIKSMAS (EXECUTE) ---
        if ($shouldUpdateDB) {
            // UPSERT (Vienas brangus veiksmas)
            $upsertStmt->execute([
                ':eid' => $eid, 
                ':title' => $item['title'], 
                ':price' => $item['price'], 
                ':img' => $imgUrl, 
                ':url' => $item['url'], 
                ':country' => $country, 
                ':category' => $category, 
                ':cid' => $state['cycle_id']
            ]);
            if ($mode === 'browser') echo "ID: $eid [UPSERT]<br>";
        } else {
            // TOUCH (Pigus veiksmas)
            // Svarbu: source='pirkis' jau yra WHERE sąlygoje prepared statement'e
            $touchStmt->execute([$state['cycle_id'], $eid]);
        }
        
        if (isset($state['total_processed'])) $state['total_processed']++; else $state['total_processed'] = 1;
    }

    $start += $perPage;
    
    if ($mode === 'browser') {
        if (ob_get_level() > 0) { ob_flush(); flush(); }
        echo "<script>setTimeout(function(){ window.location.href = '?start=$start'; }, 1000);</script>";
        echo "</body>";
        exit;
    }

} while ($mode === 'cron');
?>
