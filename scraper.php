<?php
// scraper.php - V28 (Dynamic Cooldown)
set_time_limit(0);
ignore_user_abort(true);

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
$minItemsToAllowDelete = 100;
$secret = 'ManoSlaptasRaktas123'; 

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'browser'; 
$startParam = isset($_GET['start']) ? (int)$_GET['start'] : null;

$userAgents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/119.0.0.0 Safari/537.36',
];

// --- FUNKCIJOS ---

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
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgents[array_rand($userAgents)]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($data && $code === 200 && strlen($data) > 100) return $data;
        $attempt++;
        sleep(1);
    }
    return false;
}

function get_db_state(PDO $pdo): array {
    $row = $pdo->query("SELECT * FROM scraper_state WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ['start' => 0, 'status' => 'finished', 'last_run' => 0, 'cycle_id' => '', 'total_processed' => 0, 'history' => [], 'cooldown_enabled' => 0];
    return [
        'start' => (int)$row['start_pos'],
        'status' => $row['status'],
        'last_run' => (int)$row['last_run'],
        'cycle_id' => $row['cycle_id'],
        'total_processed' => (int)$row['total_processed'],
        'history' => json_decode($row['history'] ?? '[]', true) ?: [],
        'cooldown_enabled' => (int)($row['cooldown_enabled'] ?? 0)
    ];
}

function save_db_state(PDO $pdo, array $state): void {
    $pdo->prepare("UPDATE scraper_state SET start_pos=:s, status=:st, last_run=:lr, cycle_id=:c, total_processed=:tp, history=:h WHERE id=1")
        ->execute([
            ':s' => $state['start'], ':st' => $state['status'], ':lr' => $state['last_run'],
            ':c' => $state['cycle_id'], ':tp' => $state['total_processed'], ':h' => json_encode($state['history'])
        ]);
}

function stopAndSave($pdo, $state, $currentStart, $reason) {
    $state['start'] = $currentStart;
    $state['status'] = 'running';
    $state['last_run'] = time();
    save_db_state($pdo, $state);
    die($reason);
}

function finish_cycle($state, $pdo, $minItemsToAllowDelete) {
    $totalFound = (int)$state['total_processed'];
    
    // SAUGIKLIS
    if ($totalFound < 5 && $state['start'] < 100) {
        stopAndSave($pdo, $state, $state['start'], "KLAIDA: Ciklas nutrauktas per anksti (rasta tik $totalFound).");
    }

    $msg = "";
    if ($totalFound < $minItemsToAllowDelete) {
        $msg = "SAUGIKLIS: Rasta tik $totalFound. Trynimas atšauktas.";
    } else {
        $pdo->prepare("DELETE FROM products WHERE source='pirkis' AND (cycle_id != ? OR cycle_id IS NULL)")->execute([$state['cycle_id']]);
        $deleted = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
        $msg = "BAIGTA. Ištrinta: $deleted.";
    }

    $hist = $state['history'] ?? [];
    array_unshift($hist, ['time' => time(), 'count' => ($msg === "" ? 0 : 1), 'msg' => $msg]); 
    $state['history'] = array_slice($hist, 0, 5);
    
    // Čia svarbus momentas: 
    // Jei cooldown įjungtas -> nustatome statusą 'finished'.
    // Jei išjungtas -> nustatome 'running', kad nesustotų.
    
    $cooldownEnabled = $state['cooldown_enabled'] ?? 0;
    
    if ($cooldownEnabled) {
        $state['status'] = 'finished';
    } else {
        $state['status'] = 'running';
    }

    $state['start'] = 0;
    $state['last_run'] = time();
    $state['total_processed'] = 0;
    $state['cycle_id'] = uniqid('RUN_');
    
    save_db_state($pdo, $state);
    die($msg . ($cooldownEnabled ? " (Ilsėsis)" : " (Perkrautas)"));
}

// --- LOGIKA ---

if ($mode === 'cron') {
    if (!isset($_GET['key']) || $_GET['key'] !== $secret) die('Blogas raktas.');
}

$state = get_db_state($pdo);

// Browser Reset
if ($mode === 'browser' && $startParam === 0) {
    $state = ['start' => 0, 'status' => 'running', 'last_run' => 0, 'cycle_id' => uniqid('RUN_'), 'total_processed' => 0, 'history' => $state['history'], 'cooldown_enabled' => $state['cooldown_enabled']];
    save_db_state($pdo, $state);
}

$start = ($mode === 'browser' && $startParam !== null) ? $startParam : $state['start'];

// CRON Logika
if ($mode === 'cron') {
    // Dinaminis poilsio laikas
    $cooldownTime = ($state['cooldown_enabled'] ?? 0) ? 3600 : 0;

    if ($state['status'] === 'finished') {
        if ((time() - $state['last_run']) < $cooldownTime) {
            die("Ilsisi (liko " . round(($cooldownTime - (time() - $state['last_run']))/60) . " min).");
        }
        // Laikas praėjo, pradedam iš naujo
        $state['start'] = 0; 
        $state['status'] = 'running'; 
        $state['total_processed'] = 0; 
        $state['cycle_id'] = uniqid('RUN_');
        save_db_state($pdo, $state);
        $start = 0;
    }
}

// SQL
$upsertStmt = $pdo->prepare("INSERT INTO products (external_id, title, price, image_url, url, country, category, cycle_id, source, scraped_at) VALUES (:eid, :title, :price, :img, :url, :country, :category, :cid, 'pirkis', NOW()) ON DUPLICATE KEY UPDATE title=VALUES(title), price=VALUES(price), image_url=IF(VALUES(image_url)!='',VALUES(image_url),image_url), country=VALUES(country), category=VALUES(category), cycle_id=VALUES(cycle_id), scraped_at=NOW()");
$touchStmt = $pdo->prepare("UPDATE products SET cycle_id=?, scraped_at=NOW() WHERE external_id=? AND source='pirkis'");

$startTime = microtime(true);

do {
    if ($start > $maxLimit) { finish_cycle($state, $pdo, $minItemsToAllowDelete); }
    if ($mode === 'cron' && (microtime(true) - $startTime) >= $cronTimeLimit) {
        stopAndSave($pdo, $state, $start, "Laikas baigėsi ($start).");
    }

    $html = fetchUrl($baseUrl . '?start=' . $start);
    if (!$html) {
        stopAndSave($pdo, $state, $start, "Klaida: Nepavyko gauti HTML. Bandysim vėl.");
    }

    $dom = new DOMDocument(); @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query("//div[contains(@class, 'uk-prekes-row')]");

    if ($nodes->length === 0) {
        if ($start == 0) {
            stopAndSave($pdo, $state, 0, "Klaida: 0 prekių startiniame puslapyje.");
        }
        finish_cycle($state, $pdo, $minItemsToAllowDelete);
        break;
    }

    $batchIds = []; $items = [];
    foreach ($nodes as $n) {
        $eid = str_replace('item-row-', '', $n->getAttribute('id'));
        $link = $xpath->query(".//div[contains(@class, 'uk-prekes-title')]//a", $n)->item(0);
        if (!$link) continue;
        $title = trim($link->textContent);
        $url = "https://pirkis.lt" . ($link->getAttribute('href')[0] === '/' ? '' : '/') . $link->getAttribute('href');
        $price = (float)str_replace([',',' €',' '], ['.','',''], $xpath->query(".//div[contains(@class, 'uk-prekes-kaina')]//span", $n)->item(0)->textContent ?? '0');
        $items[] = compact('eid', 'title', 'url', 'price');
        $batchIds[] = $eid;
    }

    $existing = [];
    if ($batchIds) {
        $stmt = $pdo->prepare("SELECT external_id, image_url, country, category, title, price FROM products WHERE source='pirkis' AND external_id IN (" . implode(',', array_fill(0, count($batchIds), '?')) . ")");
        $stmt->execute($batchIds);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) $existing[$r['external_id']] = $r;
    }

    foreach ($items as $item) {
        $ex = $existing[$item['eid']] ?? null;
        $update = !$ex || $ex['title'] !== $item['title'] || abs($ex['price'] - $item['price']) > 0.001;
        $img = $ex['image_url'] ?? '';
        $cnt = $ex['country'] ?? (function_exists('detect_country') ? detect_country($item['title']) : null);
        $cat = $ex['category'] ?? (function_exists('detect_category') ? detect_category($item['url']) : null);
        if (!$ex && ($cnt || $cat)) $update = true;

        if ($update && !$img) {
            usleep(100000);
            $subHtml = fetchUrl($item['url']);
            if ($subHtml) {
                $subDom = new DOMDocument(); @$subDom->loadHTML('<?xml encoding="UTF-8">' . $subHtml);
                $subX = new DOMXPath($subDom);
                $imgNode = $subX->query("//a[@id='img1']")->item(0) ?? $subX->query("//meta[@itemprop='http://schema.org/image']")->item(0);
                if ($imgNode) {
                    $src = $imgNode->getAttribute('href') ?: $imgNode->getAttribute('content');
                    if ($src) { $img = (strpos($src, 'http') === false ? "https://pirkis.lt" . ($src[0] === '/' ? '' : '/') : '') . $src; $update = true; }
                }
            }
        }

        if ($update) {
            $upsertStmt->execute([':eid'=>$item['eid'], ':title'=>$item['title'], ':price'=>$item['price'], ':img'=>$img, ':url'=>$item['url'], ':country'=>$cnt, ':category'=>$cat, ':cid'=>$state['cycle_id']]);
            if ($mode==='browser') echo ".";
        } else {
            $touchStmt->execute([$state['cycle_id'], $item['eid']]);
        }
        $state['total_processed']++;
    }

    $start += $perPage;
    if ($mode === 'browser') {
        $state['start'] = $start; $state['status'] = 'running'; save_db_state($pdo, $state);
        echo "<script>window.location.href='?start=$start';</script>"; exit;
    }

} while ($mode === 'cron');
?>
