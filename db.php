<?php
// MySQL connection and schema bootstrapper

// Load environment variables from .env if present
$envFile = __DIR__ . '/.env';
if (file_exists($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines !== false) {
        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip comments
            if ($trimmed === '' || strpos($trimmed, '#') === 0 || strpos($trimmed, ';') === 0) {
                continue;
            }

            // Support KEY=VALUE pairs (with or without surrounding quotes)
            $parts = explode('=', $trimmed, 2);
            if (count($parts) === 2) {
                [$key, $value] = $parts;
                $key = trim($key);
                $value = trim($value, " \t\n\r\0\x0B\"'" );

                if ($key !== '' && !getenv($key)) {
                    putenv("{$key}={$value}");
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }
    }
}

$dbHost = getenv('DB_HOST');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');

$missing = [];
foreach (['DB_HOST' => $dbHost, 'DB_NAME' => $dbName, 'DB_USER' => $dbUser, 'DB_PASS' => $dbPass] as $key => $val) {
    if ($val === false || $val === null || $val === '') {
        $missing[] = $key;
    }
}

if ($missing) {
    $location = file_exists($envFile) ? basename($envFile) : '.env';
    die('Trūksta reikšmių (' . implode(', ', $missing) . ") failo {$location} faile arba aplinkoje.");
}

// Connect to server and ensure database exists
try {
    $serverPdo = new PDO(
        "mysql:host={$dbHost};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
} catch (PDOException $e) {
    die('Nepavyko sukurti duomenų bazės: ' . htmlspecialchars($e->getMessage()));
}

// Connect to the target database
try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die('Nepavyko prisijungti prie duomenų bazės: ' . htmlspecialchars($e->getMessage()));
}

// Ensure required tables exist
function ensure_schema(PDO $pdo): void
{
    $statements = [
        "CREATE TABLE IF NOT EXISTS users (\n" .
        "    id INT AUTO_INCREMENT PRIMARY KEY,\n" .
        "    name VARCHAR(100) NOT NULL,\n" .
        "    email VARCHAR(190) NOT NULL UNIQUE,\n" .
        "    password VARCHAR(255) NOT NULL,\n" .
        "    role ENUM('user','admin') NOT NULL DEFAULT 'user',\n" .
        "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP\n" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS hero_content (\n" .
        "    id INT PRIMARY KEY,\n" .
        "    title VARCHAR(255) NOT NULL,\n" .
        "    message TEXT NOT NULL,\n" .
        "    button_text VARCHAR(120) DEFAULT NULL,\n" .
        "    button_url VARCHAR(255) DEFAULT NULL,\n" .
        "    image_url VARCHAR(255) DEFAULT NULL\n" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "CREATE TABLE IF NOT EXISTS news (\n" .
        "    id INT AUTO_INCREMENT PRIMARY KEY,\n" .
        "    title VARCHAR(255) NOT NULL,\n" .
        "    body TEXT NOT NULL,\n" .
        "    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n" .
        "    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP\n" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

        "INSERT INTO hero_content (id, title, message, button_text, button_url, image_url)\n" .
        "VALUES (1, 'Kolekcionierių bendruomenė', 'Atraskite monetas, banknotus ir kitus radinius vienoje modernioje erdvėje.', 'Peržiūrėti naujienas', 'news.php', '')\n" .
        "ON DUPLICATE KEY UPDATE title = VALUES(title), message = VALUES(message), button_text = VALUES(button_text), button_url = VALUES(button_url), image_url = VALUES(image_url);",
    ];

    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
}

ensure_schema($pdo);
