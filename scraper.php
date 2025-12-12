<?php
// scraper.php - V11 (Kokybė > Greitis. Garantuotos nuotraukos)
set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php'; 

// --- KONFIGŪRACIJA ---
$shopId = '30147'; 
$baseUrl = "https://pirkis.lt/shops/{$shopId}-e-Kolekcija.html";
$perPage = 20;
$maxLimit = 3000;

// GRIEŽTI LIMITAI
$cronTimeLimit = 20;    // Dirbame tik 20 sekundžių (paliekame rezervą serveriui)
$cooldownTime = 3600;   // 1 valanda poilsio po pilno ciklo
$stateFile = __DIR__ . '/scraper_state.json';

$mode = isset($_GET['mode']) ? $_GET['mode'] : 'browser'; 
$secret = 'ManoSlaptasRaktas123'; 

// --- PAGALBINĖS FUNKCIJOS ---
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

function stopAndSave($stateFile, $currentStart, $reason) {
    // Svarbu: Išsaugome TĄ PATĮ startą, kad kitą kartą pabaigtume šį puslapį
    $state = ['start' => $currentStart, 'status' => 'running', 'last_run' => time()];
    
    if (file_exists($stateFile)) {
        $old = json_decode(file_get_contents($stateFile), true);
        if (isset($old['history'])) $state['history'] = $old['history'];
    }
    file_put_contents($stateFile, json_encode($state));
    die($reason);
}

// --- SAUGUMAS ---
if ($mode === 'cron') {
    if (!isset($_GET['key']) || $_GET['key'] !== $secret) {
        die('Klaida: Neteisingas saugos raktas.');
    }
}

// --- BŪSENOS GAVIMAS ---
$start = isset($_GET['start']) ? (int)$_GET['start'] : 0;

if ($mode === 'cron') {
    $state = ['start' => 0, 'status' => 'running', 'last_run' => 0];
    if (file_exists($stateFile)) {
        $savedState = json_decode(file_get_contents($stateFile), true);
        if ($savedState) $state = $savedState;
    }

    if (($state['status'] ?? '') === 'finished') {
        $secondsSinceFinish = time() - ($state['last_run'] ?? 0);
        if ($secondsSinceFinish < $cooldownTime) {
            die("Ilsisi (Liko " . round(($cooldownTime - $secondsSinceFinish)/60) . " min).");
        } else {
            $state['start'] = 0;
            $state['status'] = 'running';
        }
    }
    $start = $state['start'];
}

if ($mode === 'browser') {
    echo '<body style="font-family: monospace; background: #222; color: #0f0; padding: 20px; line-height: 1.5;">';
    echo "<h2>DUOMENŲ NUSKAITYMAS (V11 - Quality First)</h2>";
    echo "<p>Skenuojama... </p><hr>";
}

// --- PAGRINDINIS CIKLAS ---
$startTime = microtime(true);

do {
    // 1. Apsauga
    if ($start > $maxLimit) {
        if ($mode === 'cron') finish_cycle($state, $stateFile, $pdo);
        die("STOP: Pasiektas limitas.");
    }

    // 2. Laiko patikra PRADŽIOJE
    if ($mode === 'cron' && (microtime(true) - $startTime) >= $cronTimeLimit) {
        stopAndSave($stateFile, $start, "Laikas baigėsi prieš užkraunant puslapį ($start).");
    }

    // 3. Puslapio gavimas
    $listUrl = $baseUrl . '?start=' . $start;
    if ($mode === 'browser') echo "<strong>Puslapis:</strong> start=$start ... <br>";
    
    $html = fetchUrl($listUrl);

    if (!$html) {
        if ($mode === 'cron') stopAndSave($stateFile, $start, "Klaida: Nepavyko gauti puslapio (Ryšio klaida).");
        sleep(2); continue;
    }

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $productNodes = $xpath->query("//div[contains(@class, 'uk-prekes-row')]");

    // --- Jei prekių nebėra -> PABAIGA ---
    if ($productNodes->length === 0) {
        if ($mode === 'cron') {
            finish_cycle($state, $stateFile, $pdo);
        } else {
            $pdo->query("DELETE FROM products WHERE scraped_at < DATE_SUB(NOW(), INTERVAL 3 HOUR)");
            echo "<hr><h1 style='color:green'>BAIGTA!</h1>";
        }
        break; 
    }

    // --- PREKIŲ APDOROJIMAS ---
    $processedCount = 0;

    foreach ($productNodes as $node) {
        // GRIEŽTA LAIKO KONTROLĖ:
        // Prieš kiekvieną prekę tikriname, ar turime bent 3 sekundes rezervui.
        // Jei ne -> STABDOME ir nieko nedidiname. Kitą kartą grįšime į šį puslapį.
        if ($mode === 'cron' && (microtime(true) - $startTime) > ($cronTimeLimit - 3)) {
            stopAndSave($stateFile, $start, "Laikas baigėsi viduryje puslapio ($start). Sutvarkyta prekių: $processedCount.");
        }

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

        $country = function_exists('detect_country') ? detect_country($title) : null;
        $category = function_exists('detect_category') ? detect_category($url) : null;

        if ($externalId && $title) {
            // Patikriname DB
            $stmtCheck = $pdo->prepare("SELECT title, price, image_url, country, category FROM products WHERE external_id = ?");
            $stmtCheck->execute([$externalId]);
            $existing = $stmtCheck->fetch();

            $isNew = !$existing;
            $hasPhoto = ($existing && !empty($existing['image_url']));
            
            $dataChanged = false;
            if ($existing) {
                if (abs($existing['price'] - $price) > 0.001) $dataChanged = true;
                if ($existing['title'] !== $title) $dataChanged = true;
                if ($existing['country'] !== $country) $dataChanged = true;
                if ($existing['category'] !== $category) $dataChanged = true;
            }

            $imgUrl = $hasPhoto ? $existing['image_url'] : '';
            $shouldUpdateDB = false;

            // --- FOTO GAVIMAS (Būtinas) ---
            if ($isNew || !$hasPhoto) {
                // Skiriame laiko kokybiškam nuskaitymui
                usleep(300000); // 0.3s pauzė (šiek tiek ilgesnė, saugesnė)
                
                $innerHtml = fetchUrl($url);
                if ($innerHtml) {
                    $innerDom = new DOMDocument();
                    @$innerDom->loadHTML($innerHtml);
                    $innerXpath = new DOMXPath($innerDom);

                    $linkImg = $innerXpath->query("//a[@id='img1']")->item(0);
                    if ($linkImg) {
                        $imgUrl = $linkImg->getAttribute('href');
                    } elseif ($metaImg = $innerXpath->query("//meta[@itemprop='http://schema.org/image']")->item(0)) {
                        $imgUrl = $metaImg->getAttribute('content');
                    }
                    
                    if ($imgUrl) {
                        if (strpos($imgUrl, 'http') === false) {
                            $imgUrl = "https://pirkis.lt" . (strpos($imgUrl, '/') === 0 ? '' : '/') . $imgUrl;
                        }
                        $shouldUpdateDB = true;
                    }
                }
            }

            if ($dataChanged || $isNew) $shouldUpdateDB = true;

            if ($shouldUpdateDB) {
                $stmt = $pdo->prepare("INSERT INTO products (external_id, title, price, image_url, url, country, category, scraped_at) VALUES (:eid, :title, :price, :img, :url, :country, :category, NOW()) ON DUPLICATE KEY UPDATE title = VALUES(title), price = VALUES(price), image_url = IF(VALUES(image_url) != '', VALUES(image_url), image_url), country = VALUES(country), category = VALUES(category), scraped_at = NOW()");
                $stmt->execute([':eid'=>$externalId, ':title'=>$title, ':price'=>$price, ':img'=>$imgUrl, ':url'=>$url, ':country'=>$country, ':category'=>$category]);
                if ($mode === 'browser') echo "ID: $externalId [Atnaujinta]<br>";
            } else {
                // Tik atnaujiname laiką
                $pdo->prepare("UPDATE products SET scraped_at = NOW() WHERE external_id = ?")->execute([$externalId]);
            }
            
            $processedCount++;
        }
    }

    // Jei sėkmingai praėjome VISĄ puslapį ir laikas nesibaigė:
    $start += $perPage;
    
    // Browser
    if ($mode === 'browser') {
        if (ob_get_level() > 0) { ob_flush(); flush(); }
        echo "<script>setTimeout(function(){ window.location.href = '?start=$start'; }, 1000);</script>";
        echo "</body>";
        exit;
    }

} while ($mode === 'cron');

// PABAIGA
function finish_cycle($state, $stateFile, $pdo) {
    $stmt = $pdo->query("DELETE FROM products WHERE scraped_at < DATE_SUB(NOW(), INTERVAL 3 HOUR)");
    $deleted = $stmt->rowCount();
    
    $history = isset($state['history']) ? $state['history'] : [];
    array_unshift($history, ['time' => time(), 'count' => $deleted]);
    $history = array_slice($history, 0, 5);
    
    $state['start'] = 0;
    $state['status'] = 'finished';
    $state['last_run'] = time();
    $state['history'] = $history;
    
    file_put_contents($stateFile, json_encode($state));
    die("CIKLAS BAIGTAS. Ištrinta: $deleted.");
}
?>