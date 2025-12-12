<?php
// expiry_checker.php - Galiojimo laiko tikrinimo įrankis
// Veikia naršyklėje su automatiniu atsinaujinimu (kaip scraper.php)

set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/db.php';

// --- KONFIGŪRACIJA ---
$itemsPerBatch = 10; // Kiek prekių tikrinti vienu užkrovimu (kad serveris neužlūžtų)
$pauseBetweenItems = 500000; // 0.5 sekundės pauzė tarp užklausų (mikrosekundėmis)

// --- FUNKCIJOS ---

function fetch_html_simple($url) {
    $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgents[array_rand($userAgents)]);
    // Pirkis.lt kartais reikalauja SSL nustatymų
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ($code === 200 && $data) ? $data : null;
}

function parse_pirkis_date($text) {
    // Formatai: "Šiandien 13:12", "Rytoj 10:36", "2026-01-09 12:31"
    $text = trim($text);
    $text = str_replace(' ', ' ', $text); // Išvalome &nbsp;
    
    // 1. YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $text)) {
        return strtotime($text);
    }
    
    // 2. Šiandien
    if (mb_stripos($text, 'šiandien') !== false) {
        $timePart = preg_replace('/[^0-9:]/', '', $text);
        return strtotime(date('Y-m-d') . ' ' . $timePart);
    }

    // 3. Rytoj
    if (mb_stripos($text, 'rytoj') !== false) {
        $timePart = preg_replace('/[^0-9:]/', '', $text);
        return strtotime(date('Y-m-d', strtotime('+1 day')) . ' ' . $timePart);
    }

    return null;
}

// --- VAIZDAS ---
echo '<body style="font-family: monospace; background: #111; color: #0f0; padding: 20px; line-height: 1.5;">';
echo "<h2>GALIOJIMO LAIKO TIKRINTOJAS</h2>";
echo "<p style='color:#888'>Tikrinamos seniausiai atnaujintos prekės...</p><hr>";

// --- LOGIKA ---

// Imame prekes, kurių `scraped_at` yra seniausias (ASC)
// Tai užtikrina, kad tikrintojas nuolat suksis ratu per visą duomenų bazę
$stmt = $pdo->prepare("SELECT id, url, title, scraped_at FROM products ORDER BY scraped_at ASC LIMIT :limit");
$stmt->bindValue(':limit', $itemsPerBatch, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll();

if (!$items) {
    die("<h3 style='color:red'>Prekių nerasta!</h3>");
}

$deletedCount = 0;
$updatedCount = 0;

foreach ($items as $item) {
    echo "<div>ID: <strong>{$item['id']}</strong> | <a href='{$item['url']}' target='_blank' style='color:#8bc34a; text-decoration:none;'>Atidaryti</a> | ";
    
    $html = fetch_html_simple($item['url']);
    
    // 1. Jei puslapis neegzistuoja (404) arba klaida
    if (!$html) {
        $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$item['id']]);
        echo "<span style='color:red; font-weight:bold;'>IŠTRINTA (404/Klaida)</span></div>";
        $deletedCount++;
        flush();
        continue;
    }

    // 2. Ieškome datos "Baigiasi: ..."
    $isExpired = false;
    $reason = "";

    if (preg_match('/Baigiasi:\s*(.*?)(?=<|\n|\r)/iu', $html, $matches)) {
        $dateString = $matches[1];
        $timestamp = parse_pirkis_date($dateString);
        
        if ($timestamp) {
            if ($timestamp < time()) {
                $isExpired = true;
                $reason = "Laikas pasibaigė ($dateString)";
            } else {
                echo "<span style='color:#aaa;'>Galioja iki: $dateString</span> ... ";
            }
        }
    }

    // 3. Tikriname raktažodžius "Aukcionas baigėsi" / "Parduota"
    if (!$isExpired) {
        if (mb_stripos($html, 'Aukcionas baigėsi') !== false) {
            $isExpired = true;
            $reason = "Statusas: Aukcionas baigėsi";
        } elseif (mb_stripos($html, 'Parduota') !== false) {
            $isExpired = true;
            $reason = "Statusas: Parduota";
        }
    }

    // 4. Veiksmai
    if ($isExpired) {
        $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$item['id']]);
        echo "<span style='color:red; font-weight:bold;'>IŠTRINTA ($reason)</span></div>";
        $deletedCount++;
    } else {
        // Atnaujiname laiką į DABAR, kad prekė nukeliautų į eilės galą
        $pdo->prepare("UPDATE products SET scraped_at = NOW() WHERE id = ?")->execute([$item['id']]);
        echo "<span style='color:#0f0;'>OK (Atnaujinta)</span></div>";
        $updatedCount++;
    }

    if (ob_get_level() > 0) { ob_flush(); flush(); }
    usleep($pauseBetweenItems);
}

// --- ATNAUJINIMAS ---
echo "<hr><p>Baigta partija. Ištrinta: <strong>$deletedCount</strong>. Patikrinta/Atnaujinta: <strong>$updatedCount</strong>.</p>";
echo "<p style='color:#ffeb3b'>Perkraunama... (Nesuždarykite skirtuko)</p>";

// JS Reload
echo "<script>
    setTimeout(function(){ 
        window.location.reload(); 
    }, 1000);
</script>";
echo "</body>";
?>
