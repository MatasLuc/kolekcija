<?php
// cron_expiry.php - Automatinis galiojimo tikrinimas (V4 - GZIP & Encoding Fix)
// Skirtas Cron Job'ams

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

// --- KONFIGŪRACIJA ---
$secretKey = 'ManoSlaptasRaktas123'; 
$itemsPerRun = 100; 
$logFile = __DIR__ . '/expiry.log'; 
set_time_limit(120); 

// --- APSAUGA ---
$key = $_GET['key'] ?? '';
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    parse_str($argv[1], $args);
    $key = $args['key'] ?? '';
}

if ($key !== $secretKey) {
    die('Klaida: Neteisingas saugos raktas.');
}

// --- FUNKCIJOS ---

function fetch_html_cron($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (CronJob Check) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    // SVARBU: GZIP išpakavimas
    curl_setopt($ch, CURLOPT_ENCODING, ''); 
    
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($code !== 200 || !$data) return null;

    // KONVERTUOJAME KODUOTĘ
    if (!mb_check_encoding($data, 'UTF-8')) {
        $data = mb_convert_encoding($data, 'UTF-8', 'Windows-1257');
    }

    return $data;
}

function parse_date_cron($text) {
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

function extract_pirkis_date_cron($html) {
    $pos = mb_stripos($html, 'Baigiasi');
    if ($pos === false) return null;

    $chunk = mb_substr($html, $pos, 400);
    $clean = preg_replace('/<[^>]+>/', ' ', $chunk);
    $clean = html_entity_decode($clean);
    $clean = trim(preg_replace('/\s+/u', ' ', $clean));

    // Ieškome: "Baigiasi: YYYY...", "Baigiasi: Šiandien..."
    $regex = '/Baigiasi\s*:?\s*(\d{4}-\d{2}-\d{2}(?:\s+\d{1,2}:\d{2})?|Šiandien\s+\d{1,2}:\d{2}|Rytoj\s+\d{1,2}:\d{2})/iu';
    
    if (preg_match($regex, $clean, $m)) {
        return $m[1];
    }
    return null;
}

// --- LOGIKA ---

try {
    $sql = "SELECT id, url, title FROM products 
            ORDER BY (expires_at IS NULL) DESC, expires_at ASC 
            LIMIT :limit";
            
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $itemsPerRun, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll();

    if (!$items) {
        $msg = "Prekių nerasta arba DB tuščia.";
        log_cron_history(2, $msg, 0); 
        die($msg);
    }

    $deleted = 0;
    $checked = 0;
    $updated = 0;

    foreach ($items as $item) {
        $html = fetch_html_cron($item['url']);
        $checked++;
        
        $shouldDelete = false;
        $reason = "";
        $foundExpiryDate = null;

        if (!$html) {
            $shouldDelete = true;
            $reason = "404/Nepasiekiamas";
        } else {
            // Bandome rasti datą
            $rawDate = extract_pirkis_date_cron($html);
            if ($rawDate) {
                $ts = parse_date_cron($rawDate);
                if ($ts) {
                    $foundExpiryDate = date('Y-m-d H:i:s', $ts);
                    if ($ts < time()) {
                        $shouldDelete = true;
                        $reason = "Laikas pasibaigė ($rawDate)";
                    }
                }
            }
            
            if (!$shouldDelete) {
                if (mb_stripos($html, 'Aukcionas baigėsi') !== false) {
                    $shouldDelete = true; $reason = "Aukcionas baigėsi";
                } elseif (mb_stripos($html, 'Parduota') !== false) {
                    $shouldDelete = true; $reason = "Parduota";
                }
            }
        }

        if ($shouldDelete) {
            $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$item['id']]);
            $deleted++;
        } else {
            if ($foundExpiryDate) {
                $upd = $pdo->prepare("UPDATE products SET scraped_at = NOW(), expires_at = :exp WHERE id = :id");
                $upd->execute([':exp' => $foundExpiryDate, ':id' => $item['id']]);
            } else {
                $pdo->prepare("UPDATE products SET scraped_at = NOW() WHERE id = ?")->execute([$item['id']]);
            }
            $updated++;
        }
        
        usleep(200000); 
    }

    $msg = "Patikrinta: $checked. Ištrinta: $deleted. Atnaujinta: $updated.";
    log_cron_history(2, $msg, $deleted);
    echo $msg;

    if ($deleted > 0) {
        $logEntry = "[" . date('Y-m-d H:i:s') . "] $msg\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }

} catch (Exception $e) {
    $msg = "KLAIDA: " . $e->getMessage();
    log_cron_history(2, $msg, 0);
    echo $msg;
}
?>
