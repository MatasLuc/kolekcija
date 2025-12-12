<?php
// cron_duplicates.php - Automatinis dublikatų valymas
// Nustatykite šį failą vykdyti per serverio CRON kas valandą.

require_once __DIR__ . '/db.php';

// --- KONFIGŪRACIJA ---
$secretKey = 'ManoSlaptasRaktas123'; // Pakeiskite į tą patį, kurį naudojate scraper.php
$logFile = __DIR__ . '/duplicates.log';

// --- APSAUGA ---
// Tikriname, ar kreipiamasi su teisingu raktu (apsauga nuo atsitiktinių lankytojų)
$key = $_GET['key'] ?? '';
// Jei leidžiate per komandinę eilutę (CLI), raktas gali būti argumentuose
if (php_sapi_name() === 'cli' && isset($argv[1])) {
    parse_str($argv[1], $args);
    $key = $args['key'] ?? '';
}

if ($key !== $secretKey) {
    die('Klaida: Neteisingas saugos raktas.');
}

// --- LOGIKA ---

try {
    // 1. Pirmiausia patikriname, ar išvis yra dublikatų (kad be reikalo nerašytume į logą)
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

        // 3. Įrašome į logą
        if ($deletedCount > 0) {
            $timestamp = date('Y-m-d H:i:s');
            $logEntry = "[$timestamp] Rasta dublikatų grupių: $count. Ištrinta senų prekių: $deletedCount.\n";
            file_put_contents($logFile, $logEntry, FILE_APPEND);
            echo "Išvalyta: $deletedCount";
        } else {
            echo "Dublikatų rasta, bet SQL trynimas nepaveikė eilučių (keista situacija).";
        }
    } else {
        // Galime nieko nerašyti, kad nešiukšlintume logo, arba parašyti "OK"
        echo "Dublikatų nerasta.";
    }

} catch (PDOException $e) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] KLAIDA: " . $e->getMessage() . "\n", FILE_APPEND);
    die('Įvyko klaida.');
}
?>
