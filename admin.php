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

// Funkcijos (News/Hero upload)
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
        set_alert('success', 'Priskyrimai panaikinti.');

    } elseif ($subAction === 'detect_countries') {
        $stmt = $pdo->query("SELECT id, title, url FROM products");
        $count = 0;
        while ($row = $stmt->fetch()) {
            $country = function_exists('detect_country') ? detect_country($row['title']) : null;
            $category = function_exists('detect_category') ? detect_category($row['url']) : null;
            if ($country || $category) {
                $sql = "UPDATE products SET ";
                $params = [];
                if ($country) { $sql .= "country = ?, "; $params[] = $country; }
                if ($category) { $sql .= "category = ?, "; $params[] = $category; }
                $sql = rtrim($sql, ", ") . " WHERE id = ?";
                $params[] = $row['id'];
                $pdo->prepare($sql)->execute($params);
                $count++;
            }
        }
        set_alert('success', "Atnaujinta: $count.");
        
    } elseif ($subAction === 'reset_cron') {
        $newCycleId = uniqid('RUN_');
        $stmt = $pdo->prepare("UPDATE scraper_state SET start_pos = 0, status = 'running', last_run = 0, total_processed = 0, cycle_id = ? WHERE id = 1");
        $stmt->execute([$newCycleId]);
        header("Location: admin.php?tab=products&reset=success");
        exit;

    } elseif ($subAction === 'toggle_cooldown') {
        $pdo->exec("UPDATE scraper_state SET cooldown_enabled = NOT cooldown_enabled WHERE id = 1");
        set_alert('success', 'Poilsio režimo nustatymas pakeistas.');

    } elseif ($subAction === 'check_duplicates') {
        $duplicatesResult = $pdo->query("SELECT url, COUNT(*) as cnt, MIN(title) as sample_title FROM products GROUP BY url HAVING cnt > 1")->fetchAll();
        if (empty($duplicatesResult)) set_alert('success', 'Dublikatų nerasta.');
        else set_alert('error', 'Rasta dublikatų: ' . count($duplicatesResult));

    } elseif ($subAction === 'remove_duplicates') {
        $deleted = $pdo->query("DELETE p1 FROM products p1 INNER JOIN products p2 WHERE p1.url = p2.url AND p1.id < p2.id")->rowCount();
        set_alert('success', "Ištrinta dublikatų: $deleted.");

    } elseif ($subAction === 'check_stale') {
        $staleResult = $pdo->query("SELECT id, title, scraped_at FROM products WHERE scraped_at < (NOW() - INTERVAL 48 HOUR) LIMIT 50")->fetchAll();
        if (empty($staleResult)) set_alert('success', 'Senų prekių nerasta.');
        else set_alert('error', 'Rasta senų prekių.');

    } elseif ($subAction === 'remove_stale') {
        $deleted = $pdo->query("DELETE FROM products WHERE scraped_at < (NOW() - INTERVAL 48 HOUR)")->rowCount();
        set_alert('success', "Ištrinta senų: $deleted.");
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hero') {
     $stmt = $pdo->prepare('UPDATE hero_content SET title=?, message=?, button_text=?, button_url=?, image_url=?, text_align=?, media_type=?, media_value=? WHERE id=1');
     $mediaType = $_POST['media_type'] ?? 'image';
     $mediaValue = $_POST['image_url'] ?? ''; 
     $stmt->execute([trim($_POST['title']), trim($_POST['message']), trim($_POST['button_text']), trim($_POST['button_url']), trim($_POST['image_url']), $_POST['text_align'], $mediaType, $mediaValue]);
     set_alert('success', 'Išsaugota.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'news') {
     $pdo->prepare('INSERT INTO news (title, body) VALUES (?, ?)')->execute([$_POST['news_title'], $_POST['news_body']]);
     set_alert('success', 'Naujiena sukurta.');
}

// GAVIMAS
$activeTab = $_GET['tab'] ?? 'design';
if (isset($_GET['reset']) && $_GET['reset'] == 'success') set_alert('success', 'Cron skaitiklis sėkmingai perkrautas (Reset).');

$scraperState = ['start' => 0, 'status' => 'Nežinoma', 'last_run' => 0, 'history' => [], 'cooldown_enabled' => 0];
$sRow = $pdo->query("SELECT * FROM scraper_state WHERE id = 1")->fetch();
if ($sRow) {
    $scraperState = [
        'start' => $sRow['start_pos'],
        'status' => $sRow['status'],
        'last_run' => $sRow['last_run'],
        'cycle_id' => $sRow['cycle_id'],
        'total_processed' => $sRow['total_processed'],
        'history' => json_decode($sRow['history'], true) ?: [],
        'cooldown_enabled' => (int)($sRow['cooldown_enabled'] ?? 0)
    ];
}

$productStats = [];
if ($activeTab === 'products') {
    $productStats['total'] = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $productStats['with_country'] = $pdo->query("SELECT COUNT(*) FROM products WHERE country IS NOT NULL AND country != ''")->fetchColumn();
    $productStats['with_category'] = $pdo->query("SELECT COUNT(*) FROM products WHERE category IS NOT NULL AND category != ''")->fetchColumn();
}
$newsList = $pdo->query('SELECT * FROM news ORDER BY created_at DESC')->fetchAll();
$usersList = $pdo->query('SELECT * FROM users ORDER BY name ASC')->fetchAll();
$hero = $pdo->query('SELECT * FROM hero_content WHERE id = 1')->fetch();

render_head('Admin');
render_nav();
?>

<section class="section">
    <div class="card">
        <h1>Admin Pultas</h1>
        <?php if ($alert['message']): ?><div class="alert <?php echo $alert['type']; ?>"><?php echo $alert['message']; ?></div><?php endif; ?>

        <div class="admin-menu">
            <a href="?tab=design" class="<?php echo $activeTab === 'design' ? 'active' : ''; ?>">Dizainas</a>
            <a href="?tab=news" class="<?php echo $activeTab === 'news' ? 'active' : ''; ?>">Naujienos</a>
            <a href="?tab=products" class="<?php echo $activeTab === 'products' ? 'active' : ''; ?>">Produktai</a>
            <a href="?tab=users" class="<?php echo $activeTab === 'users' ? 'active' : ''; ?>">Vartotojai</a>
        </div>

        <?php if ($activeTab === 'products'): ?>
            <div style="border:1px solid #ddd; padding:20px; border-radius:12px; margin-top:20px;">
                <h3>Automatinis nuskaitymas (Cron)</h3>
                
                <div style="background:#f9f9f9; padding:15px; border-radius:8px; margin-bottom:20px;">
                     <div style="display:flex; justify-content:space-between; align-items:center;">
                         <div>
                             <p style="margin:0 0 5px 0;"><strong>Būsena:</strong> 
                                <?php 
                                if ($scraperState['status'] === 'running') echo '<span style="color:#2ecc71;">● Vyksta</span>';
                                elseif ($scraperState['status'] === 'finished') echo '<span style="color:#f39c12;">● Ilsisi (Baigta)</span>';
                                else echo '<span style="color:#95a5a6;">● ' . e($scraperState['status']) . '</span>';
                                ?>
                             </p>
                             <p style="margin:0;"><strong>Poilsio režimas (1 val.):</strong> 
                                <?php echo $scraperState['cooldown_enabled'] ? '<span style="color:#c00;">ĮJUNGTAS</span>' : '<span style="color:green;">IŠJUNGTAS (Sukasi nuolat)</span>'; ?>
                             </p>
                         </div>
                         <form method="post" style="margin:0;">
                             <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                             <input type="hidden" name="action" value="products_control">
                             <input type="hidden" name="sub_action" value="toggle_cooldown">
                             <button type="submit" style="padding:6px 12px; font-size:0.85rem; border:1px solid #999; background:#fff; color:#333; border-radius:6px; cursor:pointer; margin-top:0;">
                                 <?php echo $scraperState['cooldown_enabled'] ? 'Išjungti poilsį' : 'Įjungti poilsį'; ?>
                             </button>
                         </form>
                     </div>

                     <hr style="margin:15px 0; border:0; border-top:1px solid #e5e5e5;">

                     <div style="font-size:0.9rem; color:#444;">
                        <p style="margin:4px 0;"><strong>Dabartinė pozicija (Start):</strong> <?php echo $scraperState['start']; ?></p>
                        <p style="margin:4px 0;"><strong>Rasta prekių cikle:</strong> <?php echo $scraperState['total_processed']; ?></p>
                        <p style="margin:4px 0;"><strong>Paskutinis aktyvumas:</strong> <?php echo $scraperState['last_run'] > 0 ? date('Y-m-d H:i:s', $scraperState['last_run']) : '-'; ?></p>
                     </div>
                </div>

                <?php if (!empty($scraperState['history'])): ?>
                    <div style="margin-bottom:20px;">
                        <h4 style="margin:0 0 10px 0; font-size:0.95rem; color:#555;">Paskutiniai 5 ciklų įvykiai:</h4>
                        <table style="width:100%; font-size:0.85rem; border-collapse:collapse;">
                            <thead style="background:#f4f4f4;">
                                <tr>
                                    <th style="padding:6px; text-align:left; border-bottom:1px solid #ddd;">Data</th>
                                    <th style="padding:6px; text-align:left; border-bottom:1px solid #ddd;">Žinutė</th>
                                    <th style="padding:6px; text-align:right; border-bottom:1px solid #ddd;">Ištrinta</th>
                                </thead>
                            <tbody>
                                <?php foreach ($scraperState['history'] as $log): ?>
                                    <tr>
                                        <td style="padding:6px; border-bottom:1px solid #eee; color:#333;">
                                            <?php echo date('Y-m-d H:i', $log['time']); ?>
                                        </td>
                                        <td style="padding:6px; border-bottom:1px solid #eee; color:#666;">
                                            <?php echo e($log['msg'] ?? '-'); ?>
                                        </td>
                                        <td style="padding:6px; text-align:right; border-bottom:1px solid #eee;">
                                            <?php 
                                            // Jei count yra 1 ir žinutė BAIGTA, bet nieko neištrynėm - tai tik vizualinis 1 (ištaisysiu scraper.php kitame žingsnyje)
                                            // Šiame admin.php tiesiog rodome skaičių
                                            if (($log['count'] ?? 0) > 0): ?>
                                                <span style="color:#c00; font-weight:bold;">-<?php echo $log['count']; ?></span>
                                            <?php else: ?>
                                                <span style="color:#ccc;">0</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <a href="scraper.php" target="_blank" class="cta" style="background:#000; color:#fff; text-decoration:none; display:inline-block; padding:8px 16px; border-radius:6px; font-size:0.9rem;">
                        Atidaryti Scraper (Testui) &nearr;
                    </a>
                    <form method="post" onsubmit="return confirm('Ar tikrai norite nustatyti skaitiklį į 0?');">
                         <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                         <input type="hidden" name="action" value="products_control">
                         <input type="hidden" name="sub_action" value="reset_cron">
                         <button type="submit" style="background:#ff9800; border:none; color:white; padding:8px 16px; border-radius:6px; font-size:0.9rem; cursor:pointer;">
                             Nustatyti Cron į 0 (Reset)
                         </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php render_footer(); ?>
