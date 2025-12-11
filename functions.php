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
function detect_country(string $title): ?string {
    // Normalizuojame pavadinimą paieškai (mažosios raidės)
    $t = mb_strtolower($title);

    // Žodynas: 'PAIEŠKOS ŽODIS' => 'STANDARTINIS PAVADINIMAS'
    // Svarbu: specifinius žodžius rašyti aukščiau (pvz. 'Great Britain' prieš 'Britain')
    $map = [
        // Lietuva
        'lietuva' => 'Lietuva', 'lithuania' => 'Lietuva', 'litauen' => 'Lietuva',
        
        // JAV
        'jav' => 'JAV', 'usa' => 'JAV', 'america' => 'JAV', 'united states' => 'JAV',
        
        // Vokietija
        'vokietija' => 'Vokietija', 'germany' => 'Vokietija', 'deutschland' => 'Vokietija', 'dr' => 'Vokietija', 'frg' => 'Vokietija', 'gdr' => 'Vokietija',
        
        // Lenkija
        'lenkija' => 'Lenkija', 'poland' => 'Lenkija', 'polska' => 'Lenkija',
        
        // Rusija / SSRS
        'rusija' => 'Rusija', 'russia' => 'Rusija', 'ssrs' => 'SSRS', 'ussr' => 'SSRS', 'cccp' => 'SSRS',
        
        // Latvija / Estija
        'latvija' => 'Latvija', 'latvia' => 'Latvija',
        'estija' => 'Estija', 'estonia' => 'Estija',
        
        // Jungtinė Karalystė
        'didžioji britanija' => 'Didžioji Britanija', 'great britain' => 'Didžioji Britanija', 'uk' => 'Didžioji Britanija', 'england' => 'Didžioji Britanija',
        
        // Kitos populiarios
        'prancūzija' => 'Prancūzija', 'france' => 'Prancūzija',
        'italija' => 'Italija', 'italy' => 'Italija',
        'ispanija' => 'Ispanija', 'spain' => 'Ispanija',
        'kinija' => 'Kinija', 'china' => 'Kinija',
        'japonija' => 'Japonija', 'japan' => 'Japonija',
        'kanada' => 'Kanada', 'canada' => 'Kanada',
        'australija' => 'Australija', 'australia' => 'Australija',
        'suomija' => 'Suomija', 'finland' => 'Suomija',
        'švedija' => 'Švedija', 'sweden' => 'Švedija',
        'norvegija' => 'Norvegija', 'norway' => 'Norvegija',
        'ukraina' => 'Ukraina', 'ukraine' => 'Ukraina',
        'baltarusija' => 'Baltarusija', 'belarus' => 'Baltarusija',
    ];

    foreach ($map as $search => $standard) {
        // Tikriname ar žodis yra pavadinime. 
        // Naudojame tarpus aplink, kad nerastų žodžio viduryje kito žodžio (pvz 'us' žodyje 'plus')
        // Bet paprastumo dėlei čia naudojame paprastą paiešką, nes šalių pavadinimai retai būna kitų žodžių dalys.
        if (mb_strpos($t, $search) !== false) {
            return $standard;
        }
    }

    return null; // Šalis neatpažinta
}
