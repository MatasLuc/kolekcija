<?php
// expiry_checker.php - Galiojimo laiko tikrinimas (V7 - ID "aukciono_pabaiga")
// Debug versija: rodo ar randa ID.

set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/db.php';

// --- KONFIGŪRACIJA ---
$itemsPerBatch = 10; 
$pauseBetweenItems = 1000000; // 1s

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
    return null;
}

function extract_pirkis_date_debug($html) {
    // 1. Ieškome pagal ID "aukciono_pabaiga"
    if (preg_match('/id="aukciono_pabaiga"[^>]*>(.*?)<\/label>/is', $html, $match)) {
        $clean = strip_tags($match[1]);
        $clean = trim(preg_replace('/\s+/u', ' ', $clean));
        return [$clean, "Rasta pagal ID='aukciono_pabaiga'"];
    }

    // 2. Atsarginis variantas
    $pos = mb_stripos($html, 'Pardavimo pabaiga:');
    if ($pos !== false) {
        return [null, "ID nerastas, bet tekstas 'Pardavimo pabaiga:' yra."];
    }
    
    // Debug info
    $title = '';
    if (preg_match('/<title>(.*?)<\/title>/is', $html, $m)) $title = strip_tags($m[1]);
    return [null, "Nieko nerasta. Title: " . trim($title)];
}

// --- VAIZDAS ---
echo '<body style="font-family: monospace; background: #111; color: #eee; padding: 20px; line-height: 1.6;">';
echo "<h2 style='color:#fff; border-bottom:1px solid #444; padding-bottom:10px;'>GALIOJIMO LAIKO TIKRINTOJAS (V7 - TIKSLUS)</h2>";

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
            echo "<span style='color:#777;'>[Info: $shortDebug]</span> <span style='color:#0f0;'>OK</span>";
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
