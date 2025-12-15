<?php
// db.php - Prisijungimas ir DB struktūra (Su Cooldown ir History palaikymu)

// Nustatome Lietuvos laiką
date_default_timezone_set('Europe/Vilnius');

// 1. Užkrauname .env
$envFile = __DIR__ . '/.env';
if (file_exists($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '#') === 0) continue;
            $parts = explode('=', $trimmed, 2);
            if (count($parts) === 2) {
                [$key, $value] = $parts;
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'");
                if ($key !== '' && !getenv($key)) {
                    putenv("{$key}={$value}");
                    $_ENV[$key] = $value;
                }
            }
        }
    }
}

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');
$dbPort = getenv('DB_PORT') ?: '3306';

if (!$dbName || !$dbUser) {
    die('Klaida: Patikrinkite .env failą.');
}

// 2. Prisijungimas
try {
    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die('Nepavyko prisijungti prie DB: ' . $e->getMessage());
}

// 3. Struktūros užtikrinimas
function ensure_schema(PDO $pdo): void
{
    $statements = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role ENUM('user','admin') NOT NULL DEFAULT 'user',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS hero_content (
            id INT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            button_text VARCHAR(120) DEFAULT NULL,
            button_url VARCHAR(255) DEFAULT NULL,
            image_url VARCHAR(255) DEFAULT NULL,
            text_align ENUM('left','center','right') NOT NULL DEFAULT 'left',
            media_type ENUM('image','video','color') NOT NULL DEFAULT 'image',
            media_value VARCHAR(255) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS news (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS news_images (
            id INT AUTO_INCREMENT PRIMARY KEY,
            news_id INT NOT NULL,
            path VARCHAR(255) NOT NULL,
            caption VARCHAR(255) DEFAULT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_news_images_news FOREIGN KEY (news_id) REFERENCES news(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        
        // Scraper būsenos lentelė
        "CREATE TABLE IF NOT EXISTS scraper_state (
            id INT PRIMARY KEY DEFAULT 1,
            start_pos INT DEFAULT 0,
            status VARCHAR(50) DEFAULT 'finished',
            last_run INT DEFAULT 0,
            cycle_id VARCHAR(50) DEFAULT NULL,
            total_processed INT DEFAULT 0,
            history TEXT DEFAULT NULL,
            cooldown_enabled TINYINT DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        
        // ID 1 = Scraperis
        "INSERT INTO scraper_state (id, start_pos, status, last_run, cycle_id, total_processed, history, cooldown_enabled)
         VALUES (1, 0, 'finished', 0, '', 0, '[]', 0)
         ON DUPLICATE KEY UPDATE id=1;",

        // ID 2 = Expiry Checker (Galiojimas), ID 3 = Duplicates Cleaner (Dublikatai)
        "INSERT INTO scraper_state (id, start_pos, status, last_run, cycle_id, total_processed, history, cooldown_enabled)
         VALUES 
         (2, 0, 'idle', 0, 'EXPIRY', 0, '[]', 0),
         (3, 0, 'idle', 0, 'DUPLICATES', 0, '[]', 0)
         ON DUPLICATE KEY UPDATE id=id;",

        "INSERT INTO hero_content (id, title, message, button_text, button_url, image_url, text_align, media_type, media_value)
        VALUES (1, 'Kolekcionierių bendruomenė', 'Atraskite monetas, banknotus ir kitus radinius vienoje modernioje erdvėje.', 'Peržiūrėti naujienas', 'news.php', '', 'left', 'image', '')
        ON DUPLICATE KEY UPDATE title = VALUES(title);"
    ];

    foreach ($statements as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Ignoruojame klaidas, jei lentelės jau yra
        }
    }
    
    // Papildomai bandome pridėti stulpelį, jei lentelė jau sukurta seniau
    try {
        $pdo->exec("ALTER TABLE scraper_state ADD COLUMN cooldown_enabled TINYINT DEFAULT 0");
    } catch (PDOException $e) {
        // Stulpelis jau yra, ignoruojame
    }
}

try {
    ensure_schema($pdo);
} catch (Exception $e) {
    die('DB Schema Error: ' . $e->getMessage());
}
?>
