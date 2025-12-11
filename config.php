<?php
// Basic configuration for database connection and sessions

// Load environment variables from .env if present
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $vars = parse_ini_file($envFile, false, INI_SCANNER_TYPED);
    if ($vars !== false) {
        foreach ($vars as $key => $value) {
            if (!getenv($key)) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

session_start();

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'kolekcija';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

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
