<?php
// cron_duplicates.php - Automatinis dublikatų valymas su Istorija
// Nustatyti cron-job.org vykdyti kas valandą.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php'; // Reikalinga log_cron_history

// --- KONFIGŪRACIJA ---
$secretKey = 'ManoSlaptasRaktas123'; 
$logFile = __DIR__ . '/duplicates.log';

// --- APSAUGA ---
$key = $_GET['key'] ?? '';
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    parse_str($argv[1], $args);
    $key = $args['key'] ?? '';
}

if ($key !== $secretKey) {
    die('Klaida: Neteisingas saugos raktas.');
}

// --- LOGIKA ---

try {
    // 1. Pirmiausia patikriname, ar išvis yra dublikatų
    $checkSql = "SELECT COUNT(*) FROM (
                    SELECT url FROM products
                    GROUP BY url
                    HAVING COUNT(*) > 1
                 ) AS temp";
    $count = $pdo->query($checkSql)->fetchColumn();

    if ($count > 0) {
        // 2. Jei radome, atliekame trynimą (paliekame naujausią ID)
        $deleteSql = "DELETE p1 FROM products p1
                      INNER JOIN products p2 
                      WHERE p1.url = p2.url AND p1.id < p2.id";
        
        $stmt = $pdo->query($deleteSql);
        $deletedCount = $stmt->rowCount();

        // 3. Įrašome į DB istoriją (ID 3 = Duplicates)
        $msg = "Rasta dublikatų grupių: $count. Ištrinta perteklinė: $deletedCount.";
        log_cron_history(3, $msg, $deletedCount);
        
        // Loguojame į failą
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$timestamp] $msg\n", FILE_APPEND);
        
        echo $msg;
    } else {
        // Loguojame, kad viskas tvarkinga
        $msg = "Dublikatų nerasta. Sistema švari.";
        log_cron_history(3, $msg, 0);
        echo $msg;
    }

} catch (PDOException $e) {
    $msg = "SQL KLAIDA: " . $e->getMessage();
    log_cron_history(3, $msg, 0);
    
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $msg\n", FILE_APPEND);
    
    die($msg);
}
?>
