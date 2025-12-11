<?php
// scraper.php - V3 (Su išmaniu atnaujinimu)
require_once __DIR__ . '/db.php';

// NUSTATYMAI
$shopId = '30147'; 
$baseUrl = "https://pirkis.lt/shops/{$shopId}-e-Kolekcija.html";
$perPage = 20;

// Gauname esamą poziciją
$start = isset($_GET['start']) ? (int)$_GET['start'] : 0;

// Dizainas
echo '<body style="font-family: monospace; background: #222; color: #0f0; padding: 20px; line-height: 1.5;">';
echo "<h2>DUOMENŲ NUSKAITYMAS (Išmanusis režimas)</h2>";
echo "<p style='color:#bbb'>Skriptas tikrina pasikeitimus ir pildo trūkstamas nuotraukas.</p>";
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

if ($productNodes->length === 0) {
    echo "<hr><h1 style='color: white; background: green; padding: 20px; text-align: center;'>DARBAS BAIGTAS!</h1>";
    echo "<p>Visos prekės patikrintos.</p>";
    echo "<p><a href='shop.php' style='color:white; font-size:1.5rem;'>Eiti į Parduotuvę &rarr;</a></p>";
    exit;
}

foreach ($productNodes as $node) {
    // --- 1. Surenkame bazinius duomenis iš sąrašo ---
    $rowId = $node->getAttribute('id'); 
    $externalId = str_replace('item-row-', '', $rowId);

    $linkNode = $xpath->query(".//div[contains(@class, 'uk-prekes-title')]//a", $node)->item(0);
    if (!$linkNode) continue;

    $title = trim($linkNode->textContent);
    
    // Nuorodos tvarkymas
    $href = $linkNode->getAttribute('href');
    if (strpos($href, '/') !== 0) $href = '/' . $href;
    $url = "https://pirkis.lt" . $href;

    // Kaina
    $priceNode = $xpath->query(".//div[contains(@class, 'uk-prekes-kaina')]//span", $node)->item(0);
    $priceRaw = $priceNode ? trim($priceNode->textContent) : '0';
    $price = (float)str_replace([',', ' €', ' '], ['.', '', ''], $priceRaw);

    if ($externalId && $title) {
        echo "ID: $externalId | ";

        // --- 2. Tikriname, ką turime DB ---
        $stmtCheck = $pdo->prepare("SELECT title, price, image_url FROM products WHERE external_id = ?");
        $stmtCheck->execute([$externalId]);
        $existing = $stmtCheck->fetch();

        // Nustatom būsenas
        $isNew = !$existing;
        $hasPhoto = ($existing && !empty($existing['image_url']));
        
        // Tikriname ar pasikeitė kaina arba pavadinimas (su minimalia paklaida kainai)
        $dataChanged = false;
        if ($existing) {
            if (abs($existing['price'] - $price) > 0.001) $dataChanged = true; // Pasikeitė kaina
            if ($existing['title'] !== $title) $dataChanged = true; // Pasikeitė pavadinimas
        }

        $imgUrl = $hasPhoto ? $existing['image_url'] : '';
        $shouldUpdateDB = false;
        $statusMsg = "";

        // --- 3. Sprendimų logika ---

        // Jei prekė nauja ARBA neturi foto -> bandome skenuoti vidų
        if ($isNew || !$hasPhoto) {
            $statusMsg .= $isNew ? "<span style='color:cyan'>[Nauja]</span> " : "<span style='color:yellow'>[Nėra foto]</span> ";
            
            // Einame į vidų ieškoti foto
            usleep(200000); // 0.2s pauzė
            $innerHtml = @file_get_contents($url, false, $context);
            
            if ($innerHtml) {
                $innerDom = new DOMDocument();
                @$innerDom->loadHTML($innerHtml);
                $innerXpath = new DOMXPath($innerDom);

                // Ieškome foto
                $linkImg = $innerXpath->query("//a[@id='img1']")->item(0);
                if ($linkImg) {
                    $foundUrl = $linkImg->getAttribute('href');
                    if ($foundUrl) {
                        $imgUrl = $foundUrl;
                        $statusMsg .= "<span style='color:#0f0'>+Foto rasta</span> ";
                        $shouldUpdateDB = true; // Radom foto - reikia atnaujinti
                    }
                }
            } else {
                $statusMsg .= "<span style='color:red'>Klaida skenuojant vidų</span> ";
            }
        }

        // Jei pasikeitė duomenys (kaina/pavadinimas)
        if ($dataChanged) {
            $statusMsg .= "<span style='color:orange'>[Duomenų pokytis]</span> ";
            $shouldUpdateDB = true;
        }

        // Jei prekė nauja - visada rašom
        if ($isNew) {
            $shouldUpdateDB = true;
        }

        // --- 4. Veiksmas ---
        
        if ($shouldUpdateDB) {
            echo $statusMsg;
            
            $stmt = $pdo->prepare("
                INSERT INTO products (external_id, title, price, image_url, url, scraped_at) 
                VALUES (:eid, :title, :price, :img, :url, NOW())
                ON DUPLICATE KEY UPDATE 
                    title = VALUES(title), 
                    price = VALUES(price), 
                    image_url = VALUES(image_url),
                    scraped_at = NOW()
            ");
            
            $stmt->execute([
                ':eid' => $externalId,
                ':title' => $title,
                ':price' => $price,
                ':img' => $imgUrl,
                ':url' => $url
            ]);
            echo " -> <span style='color:#fff; background:green; padding:0 5px;'>ĮRAŠYTA</span><br>";
        } else {
            // Jei niekas nesikeitė ir foto turim
            echo "<span style='color:#555'>Be pakitimų</span><br>";
            
            // (Pasirinktinai) Galima atnaujinti tik 'scraped_at' laiką, kad žinotume, jog prekė dar aktyvi,
            // bet tai nebūtina, jei norime maksimalaus greičio.
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
    }, 1500); // Šiek tiek greičiau, nes mažiau darbo DB
</script>";

echo "</body>";
?>
