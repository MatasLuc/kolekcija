<?php
// expiry_checker.php - Galiojimo laiko tikrinimas (V4 - GZIP & Encoding Fix)
// Pataisyta: GZIP išpakavimas ir Windows-1257 konvertavimas

set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/db.php';

// --- KONFIGŪRACIJA ---
$itemsPerBatch = 10; 
$pauseBetweenItems = 500000; // 0.5s

// --- FUNKCIJOS ---

function fetch_html_simple($url) {
    $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/119.0.0.0 Safari/537.36'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgents[array_rand($userAgents)]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    // SVARBU: Leidžiame serveriui siųsti GZIP ir jį išpakuojame
    curl_setopt($ch, CURLOPT_ENCODING, ''); 
    
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($code !== 200 || !$data) return null;

    // KONVERTUOJAME KODUOTĘ (Pirkis naudoja Windows-1257 arba ISO-8859-13)
    // Patikriname, ar tai nėra UTF-8, ir konvertuojame
    if (!mb_check_encoding($data, 'UTF-8')) {
        $data = mb_convert_encoding($data, 'UTF-8', 'Windows-1257');
    }

    return $data;
}

function parse_pirkis_date($text) {
    $text = trim($text);
    // Regex datai: YYYY-MM-DD arba YYYY-MM-DD HH:MM
    if (preg_match('/^\d{4}-\d{2}-\d{2}(?:\s+\d{1,2}:\d{2})?/', $text, $m)) {
        return strtotime($m[0]);
    }
    
    if (mb_stripos($text, 'šiandien') !== false) {
        $timePart = preg_replace('/[^0-9:]/', '', $text);
        // Jei nerado laiko, grąžiname tiesiog dienos pabaigą ar dabartį?
        // Saugiau: jei nėra laiko, tai galioja visą dieną (23:59:59), bet čia dažniausiai būna laikas.
        if (!$timePart) $timePart = '23:59';
        return strtotime(date('Y-m-d') . ' ' . $timePart);
    }
    
    if (mb_stripos($text, 'rytoj') !== false) {
        $timePart = preg_replace('/[^0-9:]/', '', $text);
        if (!$timePart) $timePart = '23:59';
        return strtotime(date('Y-m-d', strtotime('+1 day')) . ' ' . $timePart);
    }
    return null;
}

function extract_pirkis_date_debug($html) {
    // 1. Randame apytikslę vietą
    $pos = mb_stripos($html, 'Baigiasi');
    if ($pos === false) return [null, "HTML'e nerastas žodis 'Baigiasi'. Gal tai 'Pirk iš karto'?"];

    // 2. Paimame gabalą teksto
    $chunk = mb_substr($html, $pos, 400);

    // 3. Pakeičiame tagus į tarpus (svarbu!)
    // Pvz: <label>Baigiasi : </label>Šiandien -> Baigiasi :  Šiandien
    $clean = preg_replace('/<[^>]+>/', ' ', $chunk);
    
    // 4. Išvalome tarpus ir HTML simbolius
    $clean = html_entity_decode($clean);
    $clean = trim(preg_replace('/\s+/u', ' ', $clean));

    // 5. Ieškome datos švariame tekste
    // Palaikome: "Baigiasi : 2025...", "Baigiasi: Šiandien...", "Baigiasi Rytoj..."
    $regex = '/Baigiasi\s*:?\s*(\d{4}-\d{2}-\d{2}(?:\s+\d{1,2}:\d{2})?|Šiandien\s+\d{1,2}:\d{2}|Rytoj\s+\d{1,2}:\d{2})/iu';
    
    if (preg_match($regex, $clean, $m)) {
        return [$m[1], $clean];
    }
    
    return [null, $clean];
}

// --- VAIZDAS ---
echo '<body style="font-family: monospace; background: #111; color: #eee; padding: 20px; line-height: 1.6;">';
echo "<h2 style='color:#fff; border-bottom:1px solid #444; padding-bottom:10px;'>GALIOJIMO LAIKO TIKRINTOJAS (V4 - FIX)</h2>";

// --- LOGIKA ---

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
    echo "<div style='border-bottom:1px solid #222; padding:5px 0;'>";
    echo "<span style='color:#888;'>ID: {$item['id']}</span> | ";
    echo "<a href='{$item['url']}' target='_blank' style='color:#8bc34a; text-decoration:none;'>Nuoroda</a> | ";
    
    $html = fetch_html_simple($item['url']);
    
    if (!$html) {
        // Jei puslapis visiškai nepasiekiamas (404)
        $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$item['id']]);
        echo "<span style='color:red; font-weight:bold;'>[IŠTRINTA: 404/Klaida]</span></div>";
        $deletedCount++;
        flush();
        continue;
    }

    $isExpired = false;
    $reason = "";
    $newExpiry = null;
    
    // Bandome ištraukti datą
    [$rawDateFound, $debugText] = extract_pirkis_date_debug($html);

    if ($rawDateFound) {
        $timestamp = parse_pirkis_date($rawDateFound);
        if ($timestamp) {
            $newExpiry = date('Y-m-d H:i:s', $timestamp);
            if ($timestamp < time()) {
                $isExpired = true;
                $reason = "Laikas: $rawDateFound";
            }
        }
    }

    // Papildomi statusai
    if (!$isExpired) {
        if (mb_stripos($html, 'Aukcionas baigėsi') !== false) {
            $isExpired = true; $reason = "Statusas: Baigėsi";
        } elseif (mb_stripos($html, 'Parduota') !== false) {
            $isExpired = true; $reason = "Statusas: Parduota";
        }
    }

    if ($isExpired) {
        $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$item['id']]);
        echo "<span style='color:red; font-weight:bold;'>[IŠTRINTA: $reason]</span>";
    } else {
        if ($newExpiry) {
            $pdo->prepare("UPDATE products SET scraped_at = NOW(), expires_at = ? WHERE id = ?")->execute([$newExpiry, $item['id']]);
            echo "<span style='color:#4fc3f7;'>[Rasta: \"$rawDateFound\" -> $newExpiry]</span> <span style='color:#0f0;'>OK</span>";
        } else {
            // Datos neradome (gal "Pirk iš karto" be pabaigos), tiesiog atnaujiname laiką
            $pdo->prepare("UPDATE products SET scraped_at = NOW() WHERE id = ?")->execute([$item['id']]);
            
            // Parodome trumpą debug info
            $shortDebug = mb_substr($debugText, 0, 60) . '...';
            echo "<span style='color:#777;'>[Data nerasta. Aplink: \"$shortDebug\"]</span> <span style='color:#0f0;'>OK</span>";
        }
        $updatedCount++;
    }
    echo "</div>";

    if (ob_get_level() > 0) { ob_flush(); flush(); }
    usleep($pauseBetweenItems);
}

echo "<br><div style='background:#222; padding:10px; color:#fff;'>Baigta partija. Ištrinta: <strong>$deletedCount</strong>. Atnaujinta: <strong>$updatedCount</strong>.</div>";
echo "<p style='color:#ffeb3b'>Perkraunama...</p>";

echo "<script>
    setTimeout(function(){ 
        window.location.reload(); 
    }, 1500);
</script>";
echo "</body>";
?>
