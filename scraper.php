<?php
// scraper.php - GALUTINĖ VERSIJA (Su šalių atpažinimu ir išvalymu)
require_once __DIR__ . '/db.php';

// --- FUNKCIJA: Šalies atpažinimas ---
function detect_country(string $title): ?string {
    $t = mb_strtolower($title);
    $map = [
        // Lietuva
        'lietuva'=>'Lietuva', 'lithuania'=>'Lietuva', 'litauen'=>'Lietuva',
        
        // JAV
        'jav'=>'JAV', 'usa'=>'JAV', 'america'=>'JAV', 'united states'=>'JAV',
        
        // Vokietija
        'vokietija'=>'Vokietija', 'germany'=>'Vokietija', 'deutschland'=>'Vokietija', 'dr'=>'Vokietija', 'frg'=>'Vokietija', 'gdr'=>'Vokietija',
        
        // Lenkija
        'lenkija'=>'Lenkija', 'poland'=>'Lenkija', 'polska'=>'Lenkija',
        
        // Rusija / SSRS
        'rusija'=>'Rusija', 'russia'=>'Rusija', 'ssrs'=>'SSRS', 'ussr'=>'SSRS', 'cccp'=>'SSRS',
        
        // Kitos šalys
        'latvija'=>'Latvija', 'latvia'=>'Latvija',
        'estija'=>'Estija', 'estonia'=>'Estija',
        'didžioji britanija'=>'Didžioji Britanija', 'great britain'=>'Didžioji Britanija', 'uk'=>'Didžioji Britanija', 'england'=>'Didžioji Britanija',
        'prancūzija'=>'Prancūzija', 'france'=>'Prancūzija',
        'italija'=>'Italija', 'italy'=>'Italija',
        'ispanija'=>'Ispanija', 'spain'=>'Ispanija',
        'kinija'=>'Kinija', 'china'=>'Kinija',
        'japonija'=>'Japonija', 'japan'=>'Japonija',
        'kanada'=>'Kanada', 'canada'=>'Kanada',
        'australija'=>'Australija', 'australia'=>'Australija',
        'suomija'=>'Suomija', 'finland'=>'Suomija',
        'švedija'=>'Švedija', 'sweden'=>'Švedija',
        'norvegija'=>'Norvegija', 'norway'=>'Norvegija',
        'ukraina'=>'Ukraina', 'ukraine'=>'Ukraina',
        'baltarusija'=>'Baltarusija', 'belarus'=>'Baltarusija',
        'vatikanas'=>'Vatikanas', 'vatican'=>'Vatikanas',
        'izraelis'=>'Izraelis', 'israel'=>'Izraelis',
        'airija'=>'Airija', 'ireland'=>'Airija',
    ];

    foreach ($map as $search => $standard) {
        if (mb_strpos($t, $search) !== false) {
            return $standard;
        }
    }
    return null; // Šalis neatpažinta
}

// --- NUSTATYMAI ---
$shopId = '30147'; 
$baseUrl = "https://pirkis.lt/shops/{$shopId}-e-Kolekcija.html";
$perPage = 20;
$maxLimit = 3000; // Saugiklis

// Gauname esamą poziciją
$start = isset($_GET['start']) ? (int)$_GET['start'] : 0;

// --- VEIKSMAS: IŠTRINTI SENAS PREKES ---
if (isset($_GET['action']) && $_GET['action'] === 'cleanup') {
    echo '<body style="font-family: monospace; background: #222; color: #fff; padding: 20px;">';
    
    // Ištriname prekes, kurios nebuvo atnaujintos per paskutines 2 valandas
    $stmt = $pdo->query("DELETE FROM products WHERE scraped_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
    $deletedCount = $stmt->rowCount();
    
    echo "<h1 style='color:green'>IŠVALYMAS BAIGTAS</h1>";
    echo "<p>Ištrinta parduotų/dingusių prekių: <strong>$deletedCount</strong></p>";
    
    $total = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    echo "<p>Dabar duomenų bazėje yra: <strong>$total</strong> prekių.</p>";
    
    echo "<p><a href='shop.php' style='color:#0f0; font-size:1.5rem'>Eiti į parduotuvę &rarr;</a></p>";
    exit;
}

// --- APSAUGA NUO BEGALINIO CIKLO ---
if ($start > $maxLimit) {
    echo '<body style="font-family: monospace; background: #222; color: #fff; padding: 20px;">';
    echo "<h1 style='color:red'>STOP</h1>";
    echo "<p>Pasiektas saugiklis ($maxLimit).</p>";
    echo "<p><a href='?action=cleanup' style='color:orange; font-size:1.5rem'>[Paspauskite čia, kad išvalytumėte senas prekes]</a></p>";
    exit;
}

// --- DIZAINAS ---
echo '<body style="font-family: monospace; background: #222; color: #0f0; padding: 20px; line-height: 1.5;">';
echo "<h2>DUOMENŲ NUSKAITYMAS</h2>";
echo "<p style='color:#bbb'>Skenuojama... (Prašome neuždaryti lango)</p>";
echo "<hr>";

$context = stream_context_create([
    "http" => [
        "method" => "GET",
        "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36\r\n"
    ]
]);

// 1. Siunčiamės sąrašą
$listUrl = $baseUrl . '?start=' . $start;
echo "<strong>Tikrinamas sąrašas:</strong> start=$start ... <br><br>";

$html = @file_get_contents($listUrl, false, $context);

if (!$html) {
    die("<h3 style='color:red'>KLAIDA: Nepavyko gauti sąrašo. Bandykite atnaujinti (F5).</h3>");
}

$dom = new DOMDocument();
@$dom->loadHTML($html);
$xpath = new DOMXPath($dom);

$productNodes = $xpath->query("//div[contains(@class, 'uk-prekes-row')]");

// --- PABAIGA (JEI NĖRA PREKIŲ) ---
if ($productNodes->length === 0) {
    echo "<hr><h1 style='color: white; background: green; padding: 20px; text-align: center;'>SKENAVIMAS BAIGTAS!</h1>";
    
    $total = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    echo "<p>Jūsų duomenų bazėje: <strong>$total</strong> prekių.</p>";
    
    // MYGTUKAS IŠVALYMUI
    echo "<div style='text-align:center; margin-top:20px;'>";
    echo "<a href='?action=cleanup' style='background:red; color:white; padding:15px 30px; text-decoration:none; font-size:1.2rem; border-radius:5px;'>IŠTRINTI SENAS PREKES &rarr;</a>";
    echo "</div>";
    
    exit;
}

foreach ($productNodes as $node) {
    // --- 1. Duomenų rinkimas ---
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

    // ATPAŽĮSTAME ŠALĮ
    $country = detect_country($title);

    if ($externalId && $title) {
        echo "ID: $externalId | ";

        // --- 2. Patikra DB ---
        $stmtCheck = $pdo->prepare("SELECT title, price, image_url, country FROM products WHERE external_id = ?");
        $stmtCheck->execute([$externalId]);
        $existing = $stmtCheck->fetch();

        $isNew = !$existing;
        $hasPhoto = ($existing && !empty($existing['image_url']));
        
        $dataChanged = false;
        if ($existing) {
            if (abs($existing['price'] - $price) > 0.001) $dataChanged = true;
            if ($existing['title'] !== $title) $dataChanged = true;
            if ($existing['country'] !== $country) $dataChanged = true; // Jei pasikeitė/atsirado šalis
        }

        $imgUrl = $hasPhoto ? $existing['image_url'] : '';
        $shouldUpdateDB = false;
        $statusMsg = "";

        // --- 3. Sprendimai ---
        if ($isNew || !$hasPhoto) {
            $statusMsg .= $isNew ? "<span style='color:cyan'>[Nauja]</span> " : "<span style='color:yellow'>[Nėra foto]</span> ";
            
            usleep(200000); 
            $innerHtml = @file_get_contents($url, false, $context);
            
            if ($innerHtml) {
                $innerDom = new DOMDocument();
                @$innerDom->loadHTML($innerHtml);
                $innerXpath = new DOMXPath($innerDom);

                // Paieška: img1 ARBA meta image
                $linkImg = $innerXpath->query("//a[@id='img1']")->item(0);
                if ($linkImg) {
                    $imgUrl = $linkImg->getAttribute('href');
                }
                if (!$imgUrl) {
                    $metaImg = $innerXpath->query("//meta[@itemprop='http://schema.org/image']")->item(0);
                    if ($metaImg) $imgUrl = $metaImg->getAttribute('content');
                }
                // Fallback
                if (!$imgUrl) {
                     $anyImg = $innerXpath->query("//a[contains(@class, 'uk-item-image')]")->item(0);
                     if ($anyImg) $imgUrl = $anyImg->getAttribute('href');
                }

                if ($imgUrl) {
                    $statusMsg .= "<span style='color:#0f0'>+Foto</span> ";
                    $shouldUpdateDB = true;
                } else {
                    $statusMsg .= "<span style='color:red'>-Foto nerasta</span> ";
                }
            } else {
                $statusMsg .= "<span style='color:red'>Klaida (404)</span> ";
            }
        }

        if ($dataChanged) {
            $statusMsg .= "<span style='color:orange'>[Duomenys]</span> ";
            $shouldUpdateDB = true;
        }

        if ($isNew) $shouldUpdateDB = true;

        // --- 4. Įrašymas ---
        if ($shouldUpdateDB) {
            echo $statusMsg;
            if ($country) echo " <span style='color:magenta'>[$country]</span>";
            
            $stmt = $pdo->prepare("
                INSERT INTO products (external_id, title, price, image_url, url, country, scraped_at) 
                VALUES (:eid, :title, :price, :img, :url, :country, NOW())
                ON DUPLICATE KEY UPDATE 
                    title = VALUES(title), 
                    price = VALUES(price), 
                    image_url = IF(VALUES(image_url) != '', VALUES(image_url), image_url),
                    country = VALUES(country),
                    scraped_at = NOW()
            ");
            $stmt->execute([
                ':eid'=>$externalId, 
                ':title'=>$title, 
                ':price'=>$price, 
                ':img'=>$imgUrl, 
                ':url'=>$url,
                ':country'=>$country
            ]);
            echo " -> <span style='background:green;color:white'>ĮRAŠYTA</span><br>";
        } else {
            // Atnaujiname laiką, kad žinotume, jog prekė aktyvi
            $pdo->prepare("UPDATE products SET scraped_at = NOW() WHERE external_id = ?")->execute([$externalId]);
            echo "<span style='color:#555'>OK</span><br>";
        }

        if (ob_get_level() > 0) { ob_flush(); flush(); }
    }
}

// --- PERSIKROVIMAS ---
$nextStart = $start + $perPage;

echo "<hr>";
echo "<h3 style='color: yellow;'>Puslapis baigtas. Tęsiame... ($nextStart)</h3>";

echo "<script>
    setTimeout(function(){
        window.location.href = '?start=$nextStart';
    }, 1500);
</script>";

echo "</body>";
?>
