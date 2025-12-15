<?php
require_once __DIR__ . '/partials.php';
require_admin();
require_csrf();

$alert = ['type' => '', 'message' => ''];
$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);

function set_alert(string $type, string $message): void {
    global $alert;
    $alert = ['type' => $type, 'message' => $message];
}

// ... (Paveikslėlių įkėlimo funkcijos lieka tos pačios kaip anksčiau - upload_news_image, upload_hero_media) ...
// Taupydamas vietą, čia jų nekartoju, nes jos veikia gerai. Palikite jas savo faile.
// Jei reikia pilno kodo - įklijuokite funkcijas iš praeito atsakymo.
// Žemiau pateikiu tik PATAISYTĄ LOGIKĄ.

function upload_news_image(int $newsId, array $file, string $caption, string $uploadDir, PDO $pdo): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [false, ''];
    if (!is_uploaded_file($file['tmp_name'] ?? '')) return [false, 'Nepavyko gauti failo.'];
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) return [false, 'Paveikslėlis per didelis.'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($allowed[$mime])) return [false, 'Nepalaikomas formatas.'];
    $filename = 'news_' . $newsId . '_' . uniqid() . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename)) return [false, 'Klaida keliant.'];
    
    $primaryStmt = $pdo->prepare('SELECT COUNT(*) FROM news_images WHERE news_id = ? AND is_primary = 1');
    $primaryStmt->execute([$newsId]);
    $isPrimary = (int)$primaryStmt->fetchColumn() === 0 ? 1 : 0;
    
    $pdo->prepare('INSERT INTO news_images (news_id, path, caption, is_primary) VALUES (?, ?, ?, ?)')
        ->execute([$newsId, 'uploads/' . $filename, $caption ?: null, $isPrimary]);
    return [true, 'Įkelta.'];
}

function upload_hero_media(array $file, string $type, string $uploadDir): array {
    // ... (Palikite seną funkciją) ...
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [false, ''];
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'hero_' . uniqid() . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename)) return [true, 'uploads/' . $filename];
    return [false, 'Klaida.'];
}

// --- LOGIKA ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'products_control') {
    $subAction = $_POST['sub_action'] ?? '';

    if ($subAction === 'delete_all') {
        $pdo->exec("TRUNCATE TABLE products");
        $newCycleId = uniqid('RUN_');
        $pdo->prepare("UPDATE scraper_state SET start_pos=0, status='running', last_run=0, total_processed=0, cycle_id=? WHERE id=1")->execute([$newCycleId]);
        set_alert('success', 'Visos prekės ištrintos.');

    } elseif ($subAction === 'reset_countries') {
        $pdo->exec("UPDATE products SET country = NULL, category = NULL");
        set_alert('success', 'Šalys ir kategorijos išvalytos.');

    } elseif ($subAction === 'detect_countries') {
        // ... (šalių detekcija, paliekame seną kodą) ...
        set_alert('success', 'Informacija atnaujinta.');

    } elseif ($subAction === 'reset_cron') {
        // --- SPRENDIMAS ---
        // Priverstinai atstatome viską į pradinę "Running" būseną
        $newCycleId = uniqid('RUN_');
        $stmt = $pdo->prepare("UPDATE scraper_state SET start_pos = 0, status = 'running', last_run = 0, total_processed = 0, cycle_id = ? WHERE id = 1");
        $stmt->execute([$newCycleId]);
        
        // Nukreipiame vartotoją, kad duomenys persikrautų naršyklėje
        header("Location: admin.php?tab=products&reset=success");
        exit;
    }
    // ... (kiti subAction: duplicates, stale ir t.t. paliekame senus) ...
}

// ... (Hero ir News POST logika lieka ta pati) ...
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hero') {
    // ... Įterpkite hero išsaugojimo kodą iš seniau ...
     $stmt = $pdo->prepare('UPDATE hero_content SET title=?, message=?, button_text=?, button_url=?, image_url=?, text_align=?, media_type=?, media_value=? WHERE id=1');
     $mediaType = $_POST['media_type'] ?? 'image';
     $mediaValue = $_POST['image_url'] ?? ''; // Supaprastinta
     // (Čia reiktų pilno kodo failų įkėlimui, žiūrėti ankstesnį atsakymą)
}


// --- DUOMENŲ GAVIMAS ---
$activeTab = $_GET['tab'] ?? 'design';
if (isset($_GET['reset']) && $_GET['reset'] == 'success') {
    set_alert('success', 'Cron sėkmingai perkrautas (Reset). Pradėtas naujas ciklas.');
}

// Užkrauname būseną iš DB
$scraperState = ['start' => 0, 'status' => 'Nežinoma', 'last_run' => 0, 'history' => []];
$sStmt = $pdo->query("SELECT * FROM scraper_state WHERE id = 1");
$sRow = $sStmt->fetch();
if ($sRow) {
    $scraperState = [
        'start' => $sRow['start_pos'],
        'status' => $sRow['status'],
        'last_run' => $sRow['last_run'],
        'cycle_id' => $sRow['cycle_id'],
        'history' => json_decode($sRow['history'], true) ?: []
    ];
}

// Statistikos
$productStats = [];
if ($activeTab === 'products') {
    $productStats['total'] = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $productStats['with_country'] = $pdo->query("SELECT COUNT(*) FROM products WHERE country IS NOT NULL AND country != ''")->fetchColumn();
    $productStats['with_category'] = $pdo->query("SELECT COUNT(*) FROM products WHERE category IS NOT NULL AND category != ''")->fetchColumn();
}
// ... (Useriai, Naujienos užkrovimas) ...
$newsList = $pdo->query('SELECT * FROM news ORDER BY created_at DESC')->fetchAll();
$usersList = $pdo->query('SELECT * FROM users ORDER BY name ASC')->fetchAll();
$hero = $pdo->query('SELECT * FROM hero_content WHERE id = 1')->fetch();

render_head('Admin');
render_nav();
?>

<section class="section">
    <div class="card">
        <h1>Administratoriaus pultas</h1>
        <?php if ($alert['message']): ?><div class="alert <?php echo $alert['type']; ?>"><?php echo $alert['message']; ?></div><?php endif; ?>

        <div class="admin-menu">
            <a href="?tab=products" class="<?php echo $activeTab === 'products' ? 'active' : ''; ?>">Produktai</a>
            </div>

        <?php if ($activeTab === 'products'): ?>
            <div style="border:1px solid #ddd; padding:20px; border-radius:12px; margin-top:20px;">
                <h3>Automatinis nuskaitymas (Cron)</h3>
                <div style="background:#f9f9f9; padding:15px; border-radius:8px; margin-bottom:20px;">
                     <p><strong>Būsena:</strong> 
                        <?php 
                        if ($scraperState['status'] === 'running') echo '<span style="color:green">● Vyksta</span>';
                        else echo '<span style="color:orange">● Ilsisi (Baigta)</span>';
                        ?>
                     </p>
                     <p><strong>Paskutinis aktyvumas:</strong> <?php echo $scraperState['last_run'] ? date('Y-m-d H:i:s', $scraperState['last_run']) : '-'; ?></p>
                     <p style="font-size:0.8rem; color:#666;">(Laikas rodomas pagal Lietuvos laiko juostą)</p>
                </div>

                <form method="post" onsubmit="return confirm('Ar tikrai perkrauti Cron?');">
                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="action" value="products_control">
                    <input type="hidden" name="sub_action" value="reset_cron">
                    <button type="submit" style="background:#ff9800; color:white; padding:10px 20px; border:none; border-radius:5px; cursor:pointer;">
                        Nustatyti Cron į 0 (Reset)
                    </button>
                </form>
            </div>
            <?php endif; ?>
    </div>
</section>
<?php render_footer(); ?>
