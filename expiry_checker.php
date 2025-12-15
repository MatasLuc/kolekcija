<?php
// expiry_checker.php - Galiojimo laiko tikrinimas (V6 - Target Class)
// Tikslingai ieško klasės "uk-prekes-data", kurioje yra data.

set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/db.php';

// --- KONFIGŪRACIJA ---
$itemsPerBatch = 10; 
$pauseBetweenItems = 1000000; // 1s

// --- FUNKCIJOS ---

function fetch_html_simple($url) {
    $userAgents = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome/120.0.0.0 Safari/537.36'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgents[array_rand($userAgents)]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_ENCODING, ''); 
    
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($code !== 200 || !$data) return null;

    if (!preg_match('//u', $data)) {
        $data = @mb_convert_encoding($data, 'UTF-8', 'Windows-1257');
    }
    return $data;
}

function parse_pirkis_date($text) {
    $text = trim($text);
    if (preg_match('/^\d{4}-\d{2}-\d{2}(?:\s+\d{1,2}:\d{2})?/', $text, $m)) {
        return strtotime($m[0]);
    }
    if (mb_stripos($text, 'šiandien') !== false) {
        $timePart = preg_replace('/[^0-9:]/', '', $text);
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
    // 1. Ieškome DIV bloko su klase "uk-prekes-data"
    if (preg_match('/<div[^>]*class="[^"]*uk-prekes-data[^"]*"[^>]*>(.*?)<\/div>/is', $html, $match)) {
        $chunk = $match[1];
        $clean = strip_tags($chunk);
        $clean = html_entity_decode($clean);
        $clean = trim(preg_replace('/\s+/u', ' ', $clean));
        
        if (preg_match('/(\d{4}-\d{2}-\d{2}(?:\s+\d{1,2}:\d{2})?|Šiandien\s+\d{1,2}:\d{2}|Rytoj\s+\d{1,2}:\d{2})/iu', $clean, $m)) {
            return [$m[1], $clean];
        }
        return [null, "DIV rastas, data nerasta. Turinys: " . mb_substr($clean, 0, 50)];
    }

    // 2. Atsarginis
    $pos = mb_stripos($html, 'Baigiasi');
    if ($pos !== false) {
        $chunk = mb_substr($html, $pos, 300);
        $clean = strip_tags($chunk);
        $clean = html_entity_decode($clean);
        $clean = trim(preg_replace('/\s+/u', ' ', $clean));
        
        if (preg_match('/(\d{4}-\d{2}-\d{2}(?:\s+\d{1,2}:\d{2})?|Šiandien\s+\d{1,2}:\d{2}|Rytoj\s+\d{1,2}:\d{2})/iu', $clean, $m)) {
            return [$m[1], $clean];
        }
    }
    
    return [null, "Nerasta nei klasė 'uk-prekes-data', nei žodis 'Baigiasi'"];
}

// --- VAIZDAS ---
echo '<body style="font-family: monospace; background: #111; color: #eee; padding: 20px; line-height: 1.6;">';
echo "<h2 style='color:#fff; border-bottom:1px solid #444; padding-bottom:10px;'>GALIOJIMO LAIKO TIKRINTOJAS (V6)</h2>";

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
        $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$item['id']]);
        echo "<span style='color:red; font-weight:bold;'>[IŠTRINTA: 404/Klaida]</span></div>";
        $deletedCount++;
        flush();
        continue;
    }

    $isExpired = false;
    $reason = "";
    $newExpiry = null;
    
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
            $pdo->prepare("UPDATE products SET scraped_at = NOW() WHERE id = ?")->execute([$item['id']]);
            $shortDebug = mb_substr($debugText, 0, 80) . '...';
            echo "<span style='color:#777;'>[Data nerasta. Info: $shortDebug]</span> <span style='color:#0f0;'>OK</span>";
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
