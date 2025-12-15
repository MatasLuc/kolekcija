<?php
// expiry_checker.php - Galiojimo laiko tikrinimo įrankis (Su expires_at palaikymu)
// Veikia naršyklėje su automatiniu atsinaujinimu

set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/db.php';

// --- KONFIGŪRACIJA ---
$itemsPerBatch = 10; 
$pauseBetweenItems = 500000; // 0.5s

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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ($code === 200 && $data) ? $data : null;
}

function parse_pirkis_date($text) {
    $text = trim($text);
    $text = str_replace(' ', ' ', $text); 
    
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $text)) {
        return strtotime($text);
    }
    if (mb_stripos($text, 'šiandien') !== false) {
        $timePart = preg_replace('/[^0-9:]/', '', $text);
        return strtotime(date('Y-m-d') . ' ' . $timePart);
    }
    if (mb_stripos($text, 'rytoj') !== false) {
        $timePart = preg_replace('/[^0-9:]/', '', $text);
        return strtotime(date('Y-m-d', strtotime('+1 day')) . ' ' . $timePart);
    }
    return null;
}

// --- VAIZDAS ---
echo '<body style="font-family: monospace; background: #111; color: #0f0; padding: 20px; line-height: 1.5;">';
echo "<h2>GALIOJIMO LAIKO TIKRINTOJAS (V2)</h2>";
echo "<p style='color:#888'>Prioritetas: Nėra datos -> Greičiausiai baigsis</p><hr>";

// --- LOGIKA ---

// Imame prekes: 1. Kurios neturi datos. 2. Kurios baigiasi anksčiausiai.
$stmt = $pdo->prepare("SELECT id, url, title, expires_at FROM products ORDER BY (expires_at IS NULL) DESC, expires_at ASC LIMIT :limit");
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
    
    if (!$html) {
        $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$item['id']]);
        echo "<span style='color:red; font-weight:bold;'>IŠTRINTA (404/Klaida)</span></div>";
        $deletedCount++;
        flush();
        continue;
    }

    $isExpired = false;
    $reason = "";
    $newExpiry = null;

    if (preg_match('/Baigiasi:\s*(.*?)(?=<|\n|\r)/iu', $html, $matches)) {
        $dateString = $matches[1];
        $timestamp = parse_pirkis_date($dateString);
        
        if ($timestamp) {
            $newExpiry = date('Y-m-d H:i:s', $timestamp);
            
            if ($timestamp < time()) {
                $isExpired = true;
                $reason = "Laikas pasibaigė ($dateString)";
            } else {
                echo "<span style='color:#aaa;'>Galioja iki: $dateString</span> ... ";
            }
        }
    }

    if (!$isExpired) {
        if (mb_stripos($html, 'Aukcionas baigėsi') !== false) {
            $isExpired = true; $reason = "Statusas: Aukcionas baigėsi";
        } elseif (mb_stripos($html, 'Parduota') !== false) {
            $isExpired = true; $reason = "Statusas: Parduota";
        }
    }

    if ($isExpired) {
        $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$item['id']]);
        echo "<span style='color:red; font-weight:bold;'>IŠTRINTA ($reason)</span></div>";
        $deletedCount++;
    } else {
        if ($newExpiry) {
            $pdo->prepare("UPDATE products SET scraped_at = NOW(), expires_at = ? WHERE id = ?")->execute([$newExpiry, $item['id']]);
            echo "<span style='color:#0f0;'>OK (Data atnaujinta)</span></div>";
        } else {
            $pdo->prepare("UPDATE products SET scraped_at = NOW() WHERE id = ?")->execute([$item['id']]);
            echo "<span style='color:#0f0;'>OK (Tikrinama, data nerasta)</span></div>";
        }
        $updatedCount++;
    }

    if (ob_get_level() > 0) { ob_flush(); flush(); }
    usleep($pauseBetweenItems);
}

echo "<hr><p>Baigta partija. Ištrinta: <strong>$deletedCount</strong>. Patikrinta/Atnaujinta: <strong>$updatedCount</strong>.</p>";
echo "<p style='color:#ffeb3b'>Perkraunama... (Nesuždarykite skirtuko)</p>";

echo "<script>
    setTimeout(function(){ 
        window.location.reload(); 
    }, 1000);
</script>";
echo "</body>";
?>
