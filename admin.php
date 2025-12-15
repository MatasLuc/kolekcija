<?php
require_once __DIR__ . '/partials.php';
require_admin();
require_csrf();

// --- KINTAMIEJI IR FUNKCIJOS ---

$alert = ['type' => '', 'message' => ''];
$uploadDir = __DIR__ . '/uploads';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

function set_alert(string $type, string $message): void
{
    global $alert;
    $alert = ['type' => $type, 'message' => $message];
}

function upload_news_image(int $newsId, array $file, string $caption, string $uploadDir, PDO $pdo): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [false, ''];
    if (!is_uploaded_file($file['tmp_name'] ?? '')) return [false, 'Nepavyko gauti failo.'];
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) return [false, 'Paveikslėlis per didelis (maks. 5MB).'];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($allowed[$mime])) return [false, 'Nepalaikomas paveikslėlio formatas.'];

    $filename = 'news_' . $newsId . '_' . uniqid() . '.' . $allowed[$mime];
    $targetPath = rtrim($uploadDir, "/\\") . '/' . $filename;
    $publicPath = 'uploads/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) return [false, 'Nepavyko įkelti paveikslėlio.'];

    $primaryStmt = $pdo->prepare('SELECT COUNT(*) FROM news_images WHERE news_id = :id AND is_primary = 1');
    $primaryStmt->execute([':id' => $newsId]);
    $shouldBePrimary = (int)$primaryStmt->fetchColumn() === 0 ? 1 : 0;

    $insert = $pdo->prepare('INSERT INTO news_images (news_id, path, caption, is_primary) VALUES (:news_id, :path, :caption, :is_primary)');
    $insert->execute([':news_id' => $newsId, ':path' => $publicPath, ':caption' => $caption ?: null, ':is_primary' => $shouldBePrimary]);

    return [true, $shouldBePrimary ? 'Pagrindinis paveikslėlis pridėtas.' : 'Paveikslėlis pridėtas.'];
}

function upload_hero_media(array $file, string $type, string $uploadDir): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [false, ''];
    if (!is_uploaded_file($file['tmp_name'] ?? '')) return [false, 'Nepavyko gauti failo.'];
    
    $maxSize = $type === 'video' ? 30 * 1024 * 1024 : 8 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxSize) return [false, 'Failas per didelis.'];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowedImages = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $allowedVideos = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/ogg' => 'ogv'];
    $allowed = $type === 'video' ? $allowedVideos : $allowedImages;

    if (!isset($allowed[$mime])) return [false, 'Nepalaikomas formato tipas.'];

    $filename = 'hero_' . uniqid() . '.' . $allowed[$mime];
    $targetPath = rtrim($uploadDir, "/\\") . '/' . $filename;
    $publicPath = 'uploads/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) return [false, 'Nepavyko įkelti failo.'];

    return [true, $publicPath];
}

// --- LOGIKA (POST UŽKLAUSOS) ---

$duplicatesResult = []; 
$staleResult = []; 

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'products_control') {
    $subAction = $_POST['sub_action'] ?? '';

    if ($subAction === 'delete_all') {
        $pdo->exec("TRUNCATE TABLE products");
        $newCycleId = uniqid('RUN_');
        $pdo->prepare("UPDATE scraper_state SET start_pos=0, status='running', last_run=0, total_processed=0, cycle_id=? WHERE id=1")->execute([$newCycleId]);
        set_alert('success', 'Visos prekės ištrintos. Scraperis nustatytas iš naujo.');
        
    } elseif ($subAction === 'reset_countries') {
        $pdo->exec("UPDATE products SET country = NULL, category = NULL");
        set_alert('success', 'Visų prekių šalių ir kategorijų priskyrimai panaikinti.');
        
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
                $sql = rtrim($sql, ", ");
                $sql .= " WHERE id = ?";
                $params[] = $row['id'];
                $pdo->prepare($sql)->execute($params);
                $count++;
            }
        }
        set_alert('success', "Informacija atnaujinta $count prekėms.");
        
    } elseif ($subAction === 'reset_cron') {
        // Priverstinis Reset
        $newCycleId = uniqid('RUN_');
        $stmt = $pdo->prepare("UPDATE scraper_state SET start_pos = 0, status = 'running', last_run = 0, total_processed = 0, cycle_id = ? WHERE id = 1");
        $stmt->execute([$newCycleId]);
        
        header("Location: admin.php?tab=products&reset=success");
        exit;

    } elseif ($subAction === 'toggle_cooldown') {
        // Poilsio režimo perjungimas
        $pdo->exec("UPDATE scraper_state SET cooldown_enabled = NOT cooldown_enabled WHERE id = 1");
        set_alert('success', 'Poilsio režimas pakeistas.');

    } elseif ($subAction === 'check_duplicates') {
        $sql = "SELECT url, COUNT(*) as cnt, GROUP_CONCAT(id) as ids, MIN(title) as sample_title
                FROM products GROUP BY url HAVING cnt > 1";
        $duplicatesResult = $pdo->query($sql)->fetchAll();
        if (empty($duplicatesResult)) {
            set_alert('success', 'Puiku! Dublikatų (identiškų nuorodų) nerasta.');
        } else {
            set_alert('error', 'Rasta dublikatų: ' . count($duplicatesResult) . ' skirtingų nuorodų turi pasikartojimų.');
        }

    } elseif ($subAction === 'remove_duplicates') {
        $sql = "DELETE p1 FROM products p1 INNER JOIN products p2 WHERE p1.url = p2.url AND p1.id < p2.id";
        $stmt = $pdo->query($sql);
        $deleted = $stmt->rowCount();
        set_alert('success', "Sėkmingai ištrinta $deleted pasikartojančių prekių.");

    } elseif ($subAction === 'check_stale') {
        $sql = "SELECT id, title, scraped_at FROM products WHERE scraped_at < (NOW() - INTERVAL 48 HOUR) ORDER BY scraped_at ASC LIMIT 50";
        $staleResult = $pdo->query($sql)->fetchAll();
        if (empty($staleResult)) {
            set_alert('success', 'Visos prekės yra naujos (atnaujintos per paskutines 48 val.).');
        } else {
            $countTotal = $pdo->query("SELECT COUNT(*) FROM products WHERE scraped_at < (NOW() - INTERVAL 48 HOUR)")->fetchColumn();
            set_alert('error', "Rasta $countTotal senų prekių.");
        }

    } elseif ($subAction === 'remove_stale') {
        $sql = "DELETE FROM products WHERE scraped_at < (NOW() - INTERVAL 48 HOUR)";
        $stmt = $pdo->query($sql);
        $deleted = $stmt->rowCount();
        set_alert('success', "Ištrinta $deleted senų prekių.");
    }
}

// 2. DIZAINAS (HERO)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hero') {
    $hStmt = $pdo->query('SELECT media_value FROM hero_content WHERE id = 1');
    $curr = $hStmt->fetch();
    $mediaValue = $curr['media_value'] ?? '';
    $mediaType = $_POST['media_type'] ?? 'image';

    if ($mediaType === 'image') {
        if (!empty($_FILES['hero_image']['name'])) {
            [$ok, $res] = upload_hero_media($_FILES['hero_image'], 'image', $uploadDir);
            if ($ok) $mediaValue = $res; else set_alert('error', $res);
        } elseif (!empty($_POST['image_url'])) {
            $mediaValue = trim($_POST['image_url']);
        }
    } elseif ($mediaType === 'video') {
        if (!empty($_FILES['hero_video']['name'])) {
            [$ok, $res] = upload_hero_media($_FILES['hero_video'], 'video', $uploadDir);
            if ($ok) $mediaValue = $res; else set_alert('error', $res);
        }
    } elseif ($mediaType === 'color') {
        $mediaValue = $_POST['media_color'] ?? '#000000';
    }

    $stmt = $pdo->prepare('INSERT INTO hero_content (id, title, message, button_text, button_url, image_url, text_align, media_type, media_value) 
        VALUES (1, :t, :m, :bt, :bu, :iu, :al, :mt, :mv)
        ON DUPLICATE KEY UPDATE title=VALUES(title), message=VALUES(message), button_text=VALUES(button_text), button_url=VALUES(button_url), image_url=VALUES(image_url), text_align=VALUES(text_align), media_type=VALUES(media_type), media_value=VALUES(media_value)');
    
    $stmt->execute([
        ':t' => trim($_POST['title']), ':m' => trim($_POST['message']),
        ':bt' => trim($_POST['button_text']), ':bu' => trim($_POST['button_url']),
        ':iu' => trim($_POST['image_url']), ':al' => $_POST['text_align'],
        ':mt' => $mediaType, ':mv' => $mediaValue
    ]);
    if (!$alert['message']) set_alert('success', 'Dizaino nustatymai atnaujinti.');
}

// 3. NAUJIENOS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'news') {
    $title = trim($_POST['news_title']);
    $body = trim($_POST['news_body']);
    $id = $_POST['news_id'] ?? '';
    $caption = trim($_POST['news_image_caption'] ?? '');

    if ($id) {
        $pdo->prepare('UPDATE news SET title=?, body=?, updated_at=NOW() WHERE id=?')->execute([$title, $body, $id]);
        $newsId = (int)$id;
        $msg = 'Naujiena atnaujinta.';
    } else {
        $pdo->prepare('INSERT INTO news (title, body, created_at, updated_at) VALUES (?, ?, NOW(), NOW())')->execute([$title, $body]);
        $newsId = (int)$pdo->lastInsertId();
        $msg = 'Naujiena sukurta.';
    }

    if (!empty($_FILES['news_image']['name'])) {
        [$ok, $upMsg] = upload_news_image($newsId, $_FILES['news_image'], $caption, $uploadDir, $pdo);
        set_alert($ok ? 'success' : 'error', $msg . ' ' . $upMsg);
    } else {
        set_alert('success', $msg);
    }
}

// 4. KITI VEIKSMAI (paveiksliukai, trynimas, roles)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    if ($act === 'news_image_add') {
        [$ok, $msg] = upload_news_image((int)$_POST['news_id'], $_FILES['news_image'], $_POST['caption'], $uploadDir, $pdo);
        set_alert($ok ? 'success' : 'error', $msg);
    }
    if ($act === 'news_image_primary') {
        $pdo->prepare('UPDATE news_images SET is_primary = 0 WHERE news_id = ?')->execute([$_POST['news_id']]);
        $pdo->prepare('UPDATE news_images SET is_primary = 1 WHERE id = ?')->execute([$_POST['image_id']]);
        set_alert('success', 'Pagrindinė nuotrauka pakeista.');
    }
    if ($act === 'news_image_delete') {
        $stmt = $pdo->prepare('SELECT path, news_id FROM news_images WHERE id = ?');
        $stmt->execute([$_POST['image_id']]);
        if ($row = $stmt->fetch()) {
            @unlink(__DIR__ . '/' . ltrim($row['path'], '/'));
            $pdo->prepare('DELETE FROM news_images WHERE id = ?')->execute([$_POST['image_id']]);
            set_alert('success', 'Nuotrauka ištrinta.');
        }
    }
    if ($act === 'news_image_caption') {
        $pdo->prepare('UPDATE news_images SET caption = ? WHERE id = ?')->execute([$_POST['caption'] ?: null, $_POST['image_id']]);
        set_alert('success', 'Aprašas atnaujintas.');
    }
    if ($act === 'news_delete') {
        $pdo->prepare('DELETE FROM news WHERE id = ?')->execute([$_POST['news_id']]);
        set_alert('success', 'Naujiena ištrinta.');
    }
    if ($act === 'role') {
        $role = $_POST['role'] === 'admin' ? 'admin' : 'user';
        $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $_POST['user_id']]);
        set_alert('success', 'Vartotojo rolė pakeista.');
    }
}


// --- DUOMENŲ GAVIMAS ---

$activeTab = $_GET['tab'] ?? 'design';

if (isset($_GET['reset']) && $_GET['reset'] == 'success') {
    set_alert('success', 'Cron skaitiklis sėkmingai perkrautas (Reset). Pradėtas naujas ciklas.');
}

// Hero
$heroStmt = $pdo->query('SELECT * FROM hero_content WHERE id = 1');
$hero = $heroStmt->fetch() ?: [
    'title' => '', 'message' => '', 'button_text' => '', 'button_url' => '', 
    'image_url' => '', 'text_align' => 'left', 'media_type' => 'image', 'media_value' => ''
];
$heroColorValue = (preg_match('/^#[0-9a-fA-F]{6}$/', $hero['media_value'] ?? '')) ? $hero['media_value'] : '#000000';

// Naujienos
$newsList = $pdo->query('SELECT * FROM news ORDER BY created_at DESC')->fetchAll();
$imagesRaw = $pdo->query('SELECT * FROM news_images ORDER BY is_primary DESC, created_at DESC')->fetchAll();
$imagesByNews = [];
foreach ($imagesRaw as $img) $imagesByNews[$img['news_id']][] = $img;

// Vartotojai
$usersList = $pdo->query('SELECT * FROM users ORDER BY name ASC')->fetchAll();

// Scraper Būsena ir Produktai
$productStats = [];
$scraperState = ['start' => 0, 'status' => 'Nežinoma', 'last_run' => 0, 'history' => [], 'cooldown_enabled' => 0];
$otherJobsData = []; // ID 2 ir ID 3 duomenys

if ($activeTab === 'products') {
    $productStats['total'] = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $productStats['with_country'] = $pdo->query("SELECT COUNT(*) FROM products WHERE country IS NOT NULL AND country != ''")->fetchColumn();
    $productStats['with_category'] = $pdo->query("SELECT COUNT(*) FROM products WHERE category IS NOT NULL AND category != ''")->fetchColumn();
    $productStats['without_country'] = $productStats['total'] - $productStats['with_country'];
    
    // GAVIMAS IŠ DUOMENŲ BAZĖS (Scraper ID 1)
    $sStmt = $pdo->query("SELECT * FROM scraper_state WHERE id = 1");
    $sRow = $sStmt->fetch();
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
    
    // GAVIMAS IŠ DUOMENŲ BAZĖS (Expiry ID 2, Duplicates ID 3)
    $otherJobs = $pdo->query("SELECT id, last_run, history FROM scraper_state WHERE id IN (2, 3) ORDER BY id ASC")->fetchAll();
    foreach ($otherJobs as $j) {
        $otherJobsData[$j['id']] = [
            'last_run' => $j['last_run'],
            'history' => json_decode($j['history'], true) ?: []
        ];
    }
}

render_head('Administratoriaus pultas');
render_nav();
?>

<section class="section">
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center;">
            <h1>Administratoriaus pultas</h1>
            <a href="index.php" target="_blank" class="pill" style="font-size:0.9rem;">Žiūrėti svetainę &rarr;</a>
        </div>

        <?php if ($alert['message']): ?>
            <div class="alert <?php echo e($alert['type']); ?>"><?php echo e($alert['message']); ?></div>
        <?php endif; ?>

        <div class="admin-menu">
            <a href="?tab=design" class="<?php echo $activeTab === 'design' ? 'active' : ''; ?>">Dizainas</a>
            <a href="?tab=news" class="<?php echo $activeTab === 'news' ? 'active' : ''; ?>">Naujienos</a>
            <a href="?tab=products" class="<?php echo $activeTab === 'products' ? 'active' : ''; ?>">Produktai</a>
            <a href="?tab=users" class="<?php echo $activeTab === 'users' ? 'active' : ''; ?>">Vartotojai</a>
        </div>


        <?php if ($activeTab === 'design'): ?>
            <h2>Pagrindinis puslapis (Hero)</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"> <input type="hidden" name="action" value="hero">
                
                <div class="two-up">
                    <div>
                        <label for="title">Antraštė</label>
                        <input id="title" name="title" value="<?php echo e($hero['title']); ?>" required>
                    </div>
                    <div>
                        <label for="text_align">Teksto lygiavimas</label>
                        <select id="text_align" name="text_align">
                            <option value="left" <?php echo $hero['text_align'] === 'left' ? 'selected' : ''; ?>>Kairė</option>
                            <option value="center" <?php echo $hero['text_align'] === 'center' ? 'selected' : ''; ?>>Centras</option>
                            <option value="right" <?php echo $hero['text_align'] === 'right' ? 'selected' : ''; ?>>Dešinė</option>
                        </select>
                    </div>
                </div>
                <label for="message">Aprašymas</label>
                <textarea id="message" name="message" rows="3" required><?php echo e($hero['message']); ?></textarea>
                <div class="two-up">
                    <div><label for="button_text">Mygtuko tekstas</label><input id="button_text" name="button_text" value="<?php echo e($hero['button_text']); ?>"></div>
                    <div><label for="button_url">Mygtuko nuoroda</label><input id="button_url" name="button_url" value="<?php echo e($hero['button_url']); ?>"></div>
                </div>
                <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">
                <h3>Fonas</h3>
                <label for="media_type">Fono tipas</label>
                <select id="media_type" name="media_type" onchange="document.querySelectorAll('.media-input').forEach(e => e.style.display='none'); document.getElementById('media-'+this.value).style.display='block';">
                    <option value="image" <?php echo $hero['media_type'] === 'image' ? 'selected' : ''; ?>>Nuotrauka</option>
                    <option value="video" <?php echo $hero['media_type'] === 'video' ? 'selected' : ''; ?>>Video</option>
                    <option value="color" <?php echo $hero['media_type'] === 'color' ? 'selected' : ''; ?>>Spalva</option>
                </select>
                <div id="media-image" class="media-input" style="display: <?php echo $hero['media_type'] === 'image' ? 'block' : 'none'; ?>;">
                    <label>Įkelti nuotrauką</label><input type="file" name="hero_image" accept="image/*">
                    <label style="margin-top:10px;">Arba nuoroda (URL)</label><input name="image_url" value="<?php echo e($hero['image_url']); ?>" placeholder="https://...">
                </div>
                <div id="media-video" class="media-input" style="display: <?php echo $hero['media_type'] === 'video' ? 'block' : 'none'; ?>;">
                    <label>Įkelti video (mp4, webm)</label><input type="file" name="hero_video" accept="video/mp4,video/webm,video/ogg">
                </div>
                <div id="media-color" class="media-input" style="display: <?php echo $hero['media_type'] === 'color' ? 'block' : 'none'; ?>;">
                    <label>Pasirinkti spalvą</label><input type="color" name="media_color" value="<?php echo e($heroColorValue); ?>" style="height: 50px; cursor:pointer;">
                </div>
                <button type="submit" style="margin-top:20px;">Išsaugoti pakeitimus</button>
            </form>
        <?php endif; ?>


        <?php if ($activeTab === 'news'): ?>
            <h2>Kurti naujieną</h2>
            <form method="post" enctype="multipart/form-data" style="background:#f9f9f9; padding:20px; border-radius:12px; margin-bottom:30px;">
                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"> <input type="hidden" name="action" value="news">
                <label>Pavadinimas</label>
                <input name="news_title" required placeholder="Įveskite pavadinimą...">

                <label>Turinys</label>
                <textarea name="news_body" rows="5" required placeholder="Rašykite tekstą čia..."></textarea>

                <div class="two-up">
                    <div>
                        <label>Pagrindinė nuotrauka</label>
                        <input name="news_image" type="file" accept="image/*">
                    </div>
                    <div>
                        <label>Nuotraukos aprašas</label>
                        <input name="news_image_caption" placeholder="(neprivaloma)">
                    </div>
                </div>

                <button type="submit">Skelbti naujieną</button>
            </form>

            <h3>Naujienų sąrašas</h3>
            <?php if (!$newsList): ?>
                <p>Naujienų nėra.</p>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>Pavadinimas</th><th>Data</th><th>Veiksmai</th></tr></thead>
                    <tbody>
                    <?php foreach ($newsList as $item): ?>
                        <tr>
                            <td><strong><?php echo e($item['title']); ?></strong></td>
                            <td><?php echo date('Y-m-d', strtotime($item['created_at'])); ?></td>
                            <td>
                                <details>
                                    <summary class="pill" style="cursor:pointer; display:inline-block; font-size:0.8rem;">Redaguoti</summary>
                                    <div style="margin-top:15px; padding:15px; border:1px solid #ddd; border-radius:8px; background:#fff;">
                                        <form method="post" enctype="multipart/form-data">
                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"> <input type="hidden" name="action" value="news">
                                            <input type="hidden" name="news_id" value="<?php echo e($item['id']); ?>">
                                            <label>Pavadinimas</label>
                                            <input name="news_title" value="<?php echo e($item['title']); ?>" required>
                                            <label>Turinys</label>
                                            <textarea name="news_body" rows="4" required><?php echo e($item['body']); ?></textarea>
                                            <button type="submit">Atnaujinti tekstą</button>
                                        </form>
                                        
                                        <hr style="margin:20px 0;">

                                        <h4>Galerija</h4>
                                        <form method="post" enctype="multipart/form-data" class="inline-form" style="margin-bottom:15px;">
                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"> <input type="hidden" name="action" value="news_image_add">
                                            <input type="hidden" name="news_id" value="<?php echo e($item['id']); ?>">
                                            <input type="file" name="news_image" accept="image/*" required>
                                            <input name="caption" placeholder="Aprašas" style="width:150px;">
                                            <button type="submit">Pridėti foto</button>
                                        </form>

                                        <div class="news-image-admin__grid">
                                            <?php foreach ($imagesByNews[$item['id']] ?? [] as $img): ?>
                                                <div class="news-image-card <?php echo $img['is_primary'] ? 'primary' : ''; ?>">
                                                    <img src="<?php echo e($img['path']); ?>" style="height:100px; object-fit:cover;">
                                                    <div class="news-image-card__actions" style="justify-content:space-between;">
                                                        <?php if (!$img['is_primary']): ?>
                                                            <form method="post" style="display:inline;">
                                                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"> <input type="hidden" name="action" value="news_image_primary">
                                                                <input type="hidden" name="news_id" value="<?php echo e($item['id']); ?>">
                                                                <input type="hidden" name="image_id" value="<?php echo e($img['id']); ?>">
                                                                <button class="ghost" style="font-size:0.7rem; padding:4px;">★</button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <form method="post" style="display:inline;" onsubmit="return confirm('Trinti foto?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"> <input type="hidden" name="action" value="news_image_delete">
                                                            <input type="hidden" name="image_id" value="<?php echo e($img['id']); ?>">
                                                            <button class="ghost danger" style="font-size:0.7rem; padding:4px;">✕</button>
                                                        </form>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <hr style="margin:20px 0;">
                                        <form method="post" onsubmit="return confirm('Ar tikrai ištrinti visą naujieną?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"> <input type="hidden" name="action" value="news_delete">
                                            <input type="hidden" name="news_id" value="<?php echo e($item['id']); ?>">
                                            <button type="submit" style="background:#fee; color:#c00; border:1px solid #faa;">Ištrinti naujieną</button>
                                        </form>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>


        <?php if ($activeTab === 'products'): ?>
            <h2>Produktų valdymas</h2>
            
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:20px; margin-bottom:30px;">
                <div style="background:#f4f4f4; padding:15px; border-radius:8px; text-align:center;">
                    <h3><?php echo $productStats['total']; ?></h3>
                    <p style="margin:0; color:#666;">Viso prekių</p>
                </div>
                <div style="background:#e8f6ef; padding:15px; border-radius:8px; text-align:center;">
                    <h3><?php echo $productStats['with_country']; ?></h3>
                    <p style="margin:0; color:#156a45;">Su priskirta šalimi</p>
                </div>
                <div style="background:#e0f7fa; padding:15px; border-radius:8px; text-align:center;">
                    <h3><?php echo $productStats['with_category']; ?></h3>
                    <p style="margin:0; color:#006064;">Su priskirta kategorija</p>
                </div>
            </div>

            <div style="display:grid; gap:20px; grid-template-columns: 1fr;">
                
                <div style="border:1px solid #ddd; padding:20px; border-radius:12px;">
                    <h3>1. Automatinis nuskaitymas (Cron)</h3>
                    <div style="background:#f9f9f9; padding:15px; border-radius:8px; margin-bottom:20px; border:1px solid #eee;">
                        
                        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
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
                                    </tr>
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
                                                <?php if (($log['count'] ?? 0) > 0): ?>
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

                    <hr style="margin: 30px 0; border: 0; border-top: 2px dashed #eee;">
                    
                    <h3>Sistemos priežiūros žurnalai</h3>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap:20px; margin-bottom:20px;">
                        
                        <div style="background:#fff; border:1px solid #dcedc8; border-radius:8px; overflow:hidden;">
                            <div style="background:#f1f8e9; padding:10px 15px; border-bottom:1px solid #dcedc8; display:flex; justify-content:space-between; align-items:center;">
                                <strong style="color:#33691e;">Galiojimo tikrinimas (Expiry)</strong>
                                <small style="color:#666;">
                                    <?php echo isset($otherJobsData[2]['last_run']) && $otherJobsData[2]['last_run'] > 0 ? date('m-d H:i', $otherJobsData[2]['last_run']) : '-'; ?>
                                </small>
                            </div>
                            <table style="width:100%; font-size:0.8rem; border-collapse:collapse;">
                                <?php if (empty($otherJobsData[2]['history'])): ?>
                                    <tr><td style="padding:10px; text-align:center; color:#999;">Įrašų nėra</td></tr>
                                <?php else: ?>
                                    <?php foreach ($otherJobsData[2]['history'] as $h): ?>
                                    <tr style="border-bottom:1px solid #f1f1f1;">
                                        <td style="padding:6px 10px; color:#555; width:90px;"><?php echo date('m-d H:i', $h['time']); ?></td>
                                        <td style="padding:6px 10px;"><?php echo e($h['msg']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </table>
                        </div>

                        <div style="background:#fff; border:1px solid #b3e5fc; border-radius:8px; overflow:hidden;">
                            <div style="background:#e1f5fe; padding:10px 15px; border-bottom:1px solid #b3e5fc; display:flex; justify-content:space-between; align-items:center;">
                                <strong style="color:#01579b;">Dublikatų valymas</strong>
                                <small style="color:#666;">
                                    <?php echo isset($otherJobsData[3]['last_run']) && $otherJobsData[3]['last_run'] > 0 ? date('m-d H:i', $otherJobsData[3]['last_run']) : '-'; ?>
                                </small>
                            </div>
                            <table style="width:100%; font-size:0.8rem; border-collapse:collapse;">
                                <?php if (empty($otherJobsData[3]['history'])): ?>
                                    <tr><td style="padding:10px; text-align:center; color:#999;">Įrašų nėra</td></tr>
                                <?php else: ?>
                                    <?php foreach ($otherJobsData[3]['history'] as $h): ?>
                                    <tr style="border-bottom:1px solid #f1f1f1;">
                                        <td style="padding:6px 10px; color:#555; width:90px;"><?php echo date('m-d H:i', $h['time']); ?></td>
                                        <td style="padding:6px 10px;"><?php echo e($h['msg']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>

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

                <div style="border:1px solid #ddd; padding:20px; border-radius:12px;">
                    <h3>2. Duomenų atnaujinimas</h3>
                    <p>Jei pakeitėte šalių sąrašą, spauskite čia, kad perrašytumėte informaciją esamoms prekėms.</p>
                    <form method="post" style="display:inline;">
                         <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                         <input type="hidden" name="action" value="products_control">
                         <input type="hidden" name="sub_action" value="detect_countries">
                         <button type="submit" style="background:#4CAF50; border:none; color:white; padding:8px 16px; border-radius:6px; font-size:0.9rem; cursor:pointer;">Atnaujinti šalis ir kategorijas</button>
                    </form>
                    
                    <form method="post" style="display:inline; margin-left:10px;" onsubmit="return confirm('Ar tikrai norite panaikinti VISŲ prekių šalis ir kategorijas?');">
                         <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                         <input type="hidden" name="action" value="products_control">
                         <input type="hidden" name="sub_action" value="reset_countries">
                         <button type="submit" style="background:#ddd; color:#333; border:none; padding:8px 16px; border-radius:6px; font-size:0.9rem; cursor:pointer;">Išvalyti priskyrimus</button>
                    </form>
                </div>

                <div style="border:1px solid #b3e5fc; background:#e1f5fe; padding:20px; border-radius:12px;">
                    <h3>3. Dublikatų valdymas</h3>
                    <p>Patikrinkite, ar duomenų bazėje nėra prekių su identišku URL adresu.</p>
                    
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="products_control">
                        <input type="hidden" name="sub_action" value="check_duplicates">
                        <button type="submit" style="background:#0288d1; border:none; color:white; padding:8px 16px; border-radius:6px; font-size:0.9rem; cursor:pointer;">Tikrinti dublikatus</button>
                    </form>

                    <?php if (!empty($duplicatesResult)): ?>
                        <div style="margin-top:20px; background:#fff; padding:15px; border-radius:8px; border:1px solid #b3e5fc;">
                            <h4 style="margin-top:0;">Rasta <?php echo count($duplicatesResult); ?> pasikartojančių nuorodų</h4>
                            <div style="max-height:200px; overflow-y:auto; border:1px solid #eee; margin-bottom:15px;">
                                <table class="table" style="font-size:0.85rem;">
                                    <thead><tr><th>URL</th><th>Kiekis</th><th>Pvz. pavadinimas</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($duplicatesResult as $dup): ?>
                                            <tr>
                                                <td style="word-break:break-all;"><?php echo e($dup['url']); ?></td>
                                                <td><strong><?php echo $dup['cnt']; ?></strong></td>
                                                <td><?php echo e($dup['sample_title']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <form method="post" onsubmit="return confirm('Ar tikrai norite ištrinti dublikatus?');">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                <input type="hidden" name="action" value="products_control">
                                <input type="hidden" name="sub_action" value="remove_duplicates">
                                <button type="submit" style="background:#0277bd; border:none; color:white; padding:10px 20px; border-radius:6px; font-weight:bold; cursor:pointer;">Ištrinti dublikatus</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="border:1px solid #ffe0b2; background:#fff3e0; padding:20px; border-radius:12px;">
                    <h3>4. Senų prekių valymas (Ghost Items)</h3>
                    <p>Rodo prekes, kurios nebuvo atnaujintos ilgiau nei 48 val.</p>

                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                        <input type="hidden" name="action" value="products_control">
                        <input type="hidden" name="sub_action" value="check_stale">
                        <button type="submit" style="background:#f57c00; border:none; color:white; padding:8px 16px; border-radius:6px; font-size:0.9rem; cursor:pointer;">Ieškoti senų prekių</button>
                    </form>

                    <?php if (!empty($staleResult)): ?>
                        <div style="margin-top:20px; background:#fff; padding:15px; border-radius:8px; border:1px solid #ffe0b2;">
                            <h4 style="margin-top:0;">Rasta senų prekių (pirmos 50):</h4>
                            <div style="max-height:200px; overflow-y:auto; border:1px solid #eee; margin-bottom:15px;">
                                <table class="table" style="font-size:0.85rem;">
                                    <thead><tr><th>ID</th><th>Pavadinimas</th><th>Paskutinį kartą matyta</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($staleResult as $stale): ?>
                                            <tr>
                                                <td><?php echo e($stale['id']); ?></td>
                                                <td><?php echo e($stale['title']); ?></td>
                                                <td style="color:#c00;"><?php echo e($stale['scraped_at']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <form method="post" onsubmit="return confirm('Ar tikrai norite ištrinti VISAS senas prekes (>48h)?');">
                                <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                                <input type="hidden" name="action" value="products_control">
                                <input type="hidden" name="sub_action" value="remove_stale">
                                <button type="submit" style="background:#e65100; border:none; color:white; padding:10px 20px; border-radius:6px; font-weight:bold; cursor:pointer;">Ištrinti senas prekes</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="border:1px solid #dcedc8; background:#f1f8e9; padding:20px; border-radius:12px;">
                    <h3>5. Galiojimo laiko tikrinimas</h3>
                    <p>Patikrina, ar Pirkis.lt prekės galiojimo laikas jau pasibaigęs, ir jas ištrina.</p>
                    
                    <a href="expiry_checker.php" target="_blank" class="cta" style="background:#689f38; color:#fff; text-decoration:none; display:inline-block; padding:10px 20px; border-radius:6px; font-weight:bold; font-size:0.95rem;">
                        Atidaryti Galiojimo Tikrintoją &nearr;
                    </a>
                </div>

                <div style="border:1px solid #fcc; background:#fff5f5; padding:20px; border-radius:12px;">
                    <h3 style="color:#c00;">6. Pavojinga zona</h3>
                    <p>Visiškai ištrina prekes iš duomenų bazės.</p>
                    <form method="post" onsubmit="return confirm('DĖMESIO! Ar tikrai norite IŠTRINTI VISAS PREKES? Šio veiksmo negalima atšaukti.');">
                         <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">
                         <input type="hidden" name="action" value="products_control">
                         <input type="hidden" name="sub_action" value="delete_all">
                         <button type="submit" style="background:#c00; border:none; color:white; padding:8px 16px; border-radius:6px; font-size:0.9rem; cursor:pointer;">Ištrinti VISAS prekes</button>
                    </form>
                </div>

            </div>
        <?php endif; ?>


        <?php if ($activeTab === 'users'): ?>
            <h2>Vartotojų valdymas</h2>
            <table class="table">
                <thead><tr><th>Vardas</th><th>El. paštas</th><th>Rolė</th><th>Veiksmas</th></tr></thead>
                <tbody>
                <?php foreach ($usersList as $user): ?>
                    <tr>
                        <td><?php echo e($user['name']); ?></td>
                        <td><?php echo e($user['email']); ?></td>
                        <td>
                            <?php if ($user['role'] === 'admin'): ?>
                                <span style="background:#e8f6ef; color:#156a45; padding:2px 8px; border-radius:10px; font-size:0.8rem;">Admin</span>
                            <?php else: ?>
                                User
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                <span style="color:#999; font-size:0.9rem;">(Tai jūs)</span>
                            <?php else: ?>
                                <form method="post" style="display:flex; gap:5px;">
                                    <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>"> <input type="hidden" name="action" value="role">
                                    <input type="hidden" name="user_id" value="<?php echo e($user['id']); ?>">
                                    <select name="role" style="padding:4px; font-size:0.9rem; margin:0;">
                                        <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>Vartotojas</option>
                                        <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                    </select>
                                    <button type="submit" style="padding:4px 10px; font-size:0.9rem; margin:0;">OK</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    </div>
</section>

<?php render_footer(); ?>
