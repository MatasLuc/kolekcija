<?php
require_once __DIR__ . '/config.php';

function flash(string $key, ?string $message = null): ?string
{
    if ($message === null) {
        if (!isset($_SESSION['flash'][$key])) {
            return null;
        }
        $value = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $value;
    }

    $_SESSION['flash'][$key] = $message;
    return null;
}

function current_user(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    global $pdo;
    $stmt = $pdo->prepare('SELECT id, name, email, role FROM users WHERE id = :id');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        return null;
    }

    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];

    return [
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ];
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function require_login(): void
{
    if (!current_user()) {
        header('Location: login.php');
        exit;
    }
}

function require_admin(): void
{
    if (!current_user()) {
        header('Location: login.php');
        exit;
    }

    if (!is_admin()) {
        header('Location: index.php');
        exit;
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
// Sugeneruoja arba grąžina esamą CSRF raktą
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Patikrina, ar formos atsiųstas raktas sutampa su sesijos raktu
function require_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
            // Galite rodyti gražesnį puslapį, bet saugumui užtenka nutraukti darbą
            die('Saugumo klaida: CSRF patikra nepavyko. Bandykite perkrauti puslapį.');
        }
    }
}
