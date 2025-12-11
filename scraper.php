<?php
// scraper.php - Automatinis prekių nuskaitymas naršyklėje
require_once __DIR__ . '/db.php';

// NUSTATYMAI
$shopId = '30147'; 
$baseUrl = "https://pirkis.lt/shops/{$shopId}-e-Kolekcija.html";
$perPage = 20;

// Gauname esamą poziciją
$start = isset($_GET['start']) ? (int)$_GET['start'] : 0;

// Dizainas informacijai
echo '<body style="font-family: monospace; background: #222; color: #0f0; padding: 20px; line-height: 1.5;">';
echo "<h2>DUOMENŲ NUSKAITYMAS (Hotlink režimas)</h2>";
echo "<p style='color:#bbb'>Neleiskite kompiuteriui užmigti. Puslapis atsinaujins automatiškai.</p>";
echo "<hr>";

// Naršyklės imitacija
$context = stream_context_create([
    "http" => [
        "method" => "GET",
        "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36\r\n"
    ]
]);

// 1. Siunčiamės sąrašą
$listUrl = $baseUrl . '?start=' . $start;
echo "<strong>Tikrinamas puslapis:</strong> start=$start ... <br><br>";

$html = @file_get_contents($listUrl, false, $context);

if (!$html) {
    die("<h3 style='color:red'>KLAIDA: Nepavyko gauti puslapio. Bandykite atnaujinti (F5).</h3>");
}

$dom = new DOMDocument();
@$dom->loadHTML($html);
$xpath = new DOMXPath($dom);

// Ieškome prekių eilučių
$productNodes = $xpath->query("//div[contains(@class, 'uk-prekes-row')]");

// --- JEI PREKIŲ NĖRA - PABAIGA ---
if ($productNodes->length === 0) {
    echo "<hr><h1 style='color: white; background: green; padding: 20px; text-align: center;'>DARBAS BAIGTAS!</h1>";
    echo "<p>Visos prekės sėkmingai nuskaitytos.</p>";
    echo "<p><a href='shop.php' style='color:white; font-size:1.5rem;'>Eiti į Parduotuvę &rarr;</a></p>";
    exit;
}

foreach ($productNodes as $node) {
    // ID
    $rowId = $node->getAttribute('id'); 
    $externalId = str_replace('item-row-', '', $rowId);

    // Pavadinimas ir Nuoroda
    $linkNode = $xpath->query(".//div[contains(@class, 'uk-prekes-title')]//a", $node)->item(0);
    if (!$linkNode) continue;

    $title = trim($linkNode->textContent);
    $url = "https://pirkis.lt" . ltrim($linkNode->getAttribute('href'), '/');

    // Kaina
    $priceNode = $xpath->query(".//div[contains(@class, 'uk-prekes-kaina')]//span", $node)->item(0);
    $priceRaw = $priceNode ? trim($priceNode->textContent) : '0';
    $price = (float)str_replace([',', ' €', ' '], ['.', '', ''], $priceRaw);

    if ($externalId && $title) {
        echo "ID: $externalId | ";

        // --- FOTO LOGIKA ---
        // 1. Tikriname DB
        $stmtCheck = $pdo->prepare("SELECT image_url FROM products WHERE external_id = ?");
        $stmtCheck->execute([$externalId]);
        $existing = $stmtCheck->fetch();

        $imgUrl = '';
        $status = '<span style="color:#888">Yra</span>';

        // 2. Jei neturime - einame į vidų pasiimti
        if (!$existing || empty($existing['image_url'])) {
            $status = '<span style="color:yellow">Skenuojama...</span>';
            
            // Pauzė serverio saugumui
            usleep(200000); // 0.2 sek

            $innerHtml = @file_get_contents($url, false, $context);
            if ($innerHtml) {
                $innerDom = new DOMDocument();
                @$innerDom->loadHTML($innerHtml);
                $innerXpath = new DOMXPath($innerDom);

                // Ieškome <meta itemprop="image"> (geriausia kokybė)
                $metaImg = $innerXpath->query("//meta[@itemprop='http://schema.org/image']")->item(0);
                if ($metaImg) {
                    $imgUrl = $metaImg->getAttribute('content');
                } else {
                    // Atsarginis variantas
                    $linkImg = $innerXpath->query("//a[contains(@class, 'uk-item-image')]")->item(0);
                    if ($linkImg) $imgUrl = $linkImg->getAttribute('href');
                }
            }
            $status .= ($imgUrl ? " OK" : " <span style='color:red'>Nerasta</span>");
        } else {
            $imgUrl = $existing['image_url'];
        }

        echo "$status <br>";

        // 3. Išsaugome (arba atnaujiname)
        $stmt = $pdo->prepare("
            INSERT INTO products (external_id, title, price, image_url, url, scraped_at) 
            VALUES (:eid, :title, :price, :img, :url, NOW())
            ON DUPLICATE KEY UPDATE 
                title = VALUES(title), 
                price = VALUES(price), 
                image_url = IF(VALUES(image_url) != '', VALUES(image_url), image_url),
                scraped_at = NOW()
        ");
        
        $stmt->execute([
            ':eid' => $externalId,
            ':title' => $title,
            ':price' => $price,
            ':img' => $imgUrl,
            ':url' => $url
        ]);
        
        // Išvedame eigą realiu laiku
        if (ob_get_level() > 0) { ob_flush(); flush(); }
    }
}

// --- PERSIKROVIMAS ---
$nextStart = $start + $perPage;

echo "<hr>";
echo "<h3 style='color: yellow;'>Puslapis baigtas. Tęsiame... ($nextStart)</h3>";

// JavaScript automatinis nukreipimas
echo "<script>
    setTimeout(function(){
        window.location.href = '?start=$nextStart';
    }, 2000);
</script>";

echo "</body>";
?>
