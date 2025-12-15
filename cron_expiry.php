<?php
// cron_expiry.php - Automatinis galiojimo tikrinimas (Cron Job)
// FIX: Pridėtas &nbsp; ir HTML entitetų valymas geresniam datos atpažinimui.

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
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $data) ? $data : null;
}

function parse_date_cron($text) {
    // 1. Išvalome HTML entitetus (&nbsp; ir t.t.)
    $text = html_entity_decode($text);
    // 2. Išvalome nematomus simbolius ir tarpus
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    
    // 3. Bandome atpažinti formatus
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $text)) return strtotime($text);
    
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

// --- LOGIKA ---

try {
    // Prioritetas: be datos (NULL) -> artimiausia pabaiga (ASC)
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
            // Regex pataisymas: leidžiame tarpus, &nbsp; ir kitus simbolius po "Baigiasi:"
            // (?:\s|&nbsp;|&#160;)* - praleidžia visus tarpus
            if (preg_match('/Baigiasi:(?:\s|&nbsp;|&#160;)*(.*?)(?=<|\n|\r)/iu', $html, $matches)) {
                $rawDate = $matches[1];
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
