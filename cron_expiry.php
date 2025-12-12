<?php
// cron_expiry.php - Automatinis galiojimo tikrinimas (Cron Job)
// Nustatyti cron-job.org vykdyti kas 15-30 minučių.

require_once __DIR__ . '/db.php';

// --- KONFIGŪRACIJA ---
$secretKey = 'ManoSlaptasRaktas123'; // Turi sutapti su jūsų raktu
$itemsPerRun = 50; // Kiek prekių tikrinti per vieną kartą (kad neviršytų laiko limito)
$logFile = __DIR__ . '/expiry.log';

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
    $text = trim(str_replace(' ', ' ', $text));
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $text)) return strtotime($text);
    if (mb_stripos($text, 'šiandien') !== false) {
        return strtotime(date('Y-m-d') . ' ' . preg_replace('/[^0-9:]/', '', $text));
    }
    if (mb_stripos($text, 'rytoj') !== false) {
        return strtotime(date('Y-m-d', strtotime('+1 day')) . ' ' . preg_replace('/[^0-9:]/', '', $text));
    }
    return null;
}

// --- LOGIKA ---

try {
    // 1. Imame seniausias prekes
    $stmt = $pdo->prepare("SELECT id, url, title FROM products ORDER BY scraped_at ASC LIMIT :limit");
    $stmt->bindValue(':limit', $itemsPerRun, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll();

    if (!$items) {
        die("Prekių nerasta arba duomenų bazė tuščia.");
    }

    $deleted = 0;
    $checked = 0;

    foreach ($items as $item) {
        $html = fetch_html_cron($item['url']);
        $checked++;
        
        $shouldDelete = false;
        $reason = "";

        if (!$html) {
            $shouldDelete = true;
            $reason = "404/Nepasiekiamas";
        } else {
            // Ieškome "Baigiasi: ..."
            if (preg_match('/Baigiasi:\s*(.*?)(?=<|\n|\r)/iu', $html, $matches)) {
                $ts = parse_date_cron($matches[1]);
                if ($ts && $ts < time()) {
                    $shouldDelete = true;
                    $reason = "Laikas pasibaigė ({$matches[1]})";
                }
            }
            // Ieškome statuso žinučių
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
            // Atnaujiname laiką, kad prekė nukeliautų į eilės galą
            $pdo->prepare("UPDATE products SET scraped_at = NOW() WHERE id = ?")->execute([$item['id']]);
        }
        
        // Maža pauzė serverio apkrovai mažinti
        usleep(200000); 
    }

    // Rezultatų išvedimas (tai matysite Cron Job istorijoje)
    $msg = "Patikrinta: $checked. Ištrinta: $deleted.";
    echo $msg;

    // Loguojame į failą tik jei kažką ištrynėme
    if ($deleted > 0) {
        $logEntry = "[" . date('Y-m-d H:i:s') . "] $msg\n";
        file_put_contents($logFile, $logEntry, FILE_APPEND);
    }

} catch (Exception $e) {
    echo "KLAIDA: " . $e->getMessage();
}
?>
