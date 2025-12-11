<?php
require_once __DIR__ . '/partials.php';
require_admin();

// --- LOGIKA (POST užklausų apdorojimas) ---
// Paliekame tą pačią logiką viršuje, kad ji suveiktų prieš atvaizdavimą

$alert = ['type' => '', 'message' => ''];
$uploadDir = __DIR__ . '/uploads';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

// Funkcijos paveikslėliams ir žinutėms
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

// 1. DIZAINAS (HERO) - Išsaugojimas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hero') {
    // Gauname esamą informaciją, kad neprarastume media_value jei nieko nekeičiam
    $hStmt = $pdo->query('SELECT media_value FROM hero_content WHERE id = 1');
    $curr = $hStmt->fetch();
    
    $mediaValue = $curr['media_value'] ?? '';
    $mediaType = $_POST['media_type'] ?? 'image';

    // Failų kėlimo logika
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

// 2. NAUJIENOS - Išsaugojimas
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

// Papildomi naujienų veiksmai (nuotraukos, trinimas)
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
}

// 3. VARTOTOJAI - Rolės
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'role') {
    $role = $_POST['role'] === 'admin' ? 'admin' : 'user';
    $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $_POST['user_id']]);
    set_alert('success', 'Vartotojo rolė pakeista.');
}


// --- DUOMENŲ GAVIMAS ---

// Nustatome aktyvų tab'ą
$activeTab = $_GET['tab'] ?? 'design';

// Gauname duomenis tik tam tab'ui, kurio reikia (arba visus, jei paprasčiau)
// Hero duomenys
$heroStmt = $pdo->query('SELECT * FROM hero_content WHERE id = 1');
$hero = $heroStmt->fetch() ?: [
    'title' => '', 'message' => '', 'button_text' => '', 'button_url' => '', 
    'image_url' => '', 'text_align' => 'left', 'media_type' => 'image', 'media_value' => ''
];
$heroColorValue = (preg_match('/^#[0-9a-fA-F]{6}$/', $hero['media_value'] ?? '')) ? $hero['media_value'] : '#000000';

// Naujienų duomenys
$newsList = $pdo->query('SELECT * FROM news ORDER BY created_at DESC')->fetchAll();
$imagesRaw = $pdo->query('SELECT * FROM news_images ORDER BY is_primary DESC, created_at DESC')->fetchAll();
$imagesByNews = [];
foreach ($imagesRaw as $img) $imagesByNews[$img['news_id']][] = $img;

// Vartotojų duomenys
$usersList = $pdo->query('SELECT * FROM users ORDER BY name ASC')->fetchAll();


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
            <a href="?tab=users" class="<?php echo $activeTab === 'users' ? 'active' : ''; ?>">Vartotojai</a>
        </div>


        <?php if ($activeTab === 'design'): ?>
            <h2>Pagrindinis puslapis (Hero)</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="hero">
                
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
                    <div>
                        <label for="button_text">Mygtuko tekstas</label>
                        <input id="button_text" name="button_text" value="<?php echo e($hero['button_text']); ?>">
                    </div>
                    <div>
                        <label for="button_url">Mygtuko nuoroda</label>
                        <input id="button_url" name="button_url" value="<?php echo e($hero['button_url']); ?>">
                    </div>
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
                    <label>Įkelti nuotrauką</label>
                    <input type="file" name="hero_image" accept="image/*">
                    <label style="margin-top:10px;">Arba nuoroda (URL)</label>
                    <input name="image_url" value="<?php echo e($hero['image_url']); ?>" placeholder="https://...">
                </div>

                <div id="media-video" class="media-input" style="display: <?php echo $hero['media_type'] === 'video' ? 'block' : 'none'; ?>;">
                    <label>Įkelti video (mp4, webm)</label>
                    <input type="file" name="hero_video" accept="video/mp4,video/webm,video/ogg">
                </div>

                <div id="media-color" class="media-input" style="display: <?php echo $hero['media_type'] === 'color' ? 'block' : 'none'; ?>;">
                    <label>Pasirinkti spalvą</label>
                    <input type="color" name="media_color" value="<?php echo e($heroColorValue); ?>" style="height: 50px; cursor:pointer;">
                </div>

                <button type="submit" style="margin-top:20px;">Išsaugoti pakeitimus</button>
            </form>
        <?php endif; ?>


        <?php if ($activeTab === 'news'): ?>
            <h2>Kurti naujieną</h2>
            <form method="post" enctype="multipart/form-data" style="background:#f9f9f9; padding:20px; border-radius:12px; margin-bottom:30px;">
                <input type="hidden" name="action" value="news">
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
                                            <input type="hidden" name="action" value="news">
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
                                            <input type="hidden" name="action" value="news_image_add">
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
                                                                <input type="hidden" name="action" value="news_image_primary">
                                                                <input type="hidden" name="news_id" value="<?php echo e($item['id']); ?>">
                                                                <input type="hidden" name="image_id" value="<?php echo e($img['id']); ?>">
                                                                <button class="ghost" style="font-size:0.7rem; padding:4px;">★</button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <form method="post" style="display:inline;" onsubmit="return confirm('Trinti foto?');">
                                                            <input type="hidden" name="action" value="news_image_delete">
                                                            <input type="hidden" name="image_id" value="<?php echo e($img['id']); ?>">
                                                            <button class="ghost danger" style="font-size:0.7rem; padding:4px;">✕</button>
                                                        </form>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <hr style="margin:20px 0;">
                                        <form method="post" onsubmit="return confirm('Ar tikrai ištrinti visą naujieną?');">
                                            <input type="hidden" name="action" value="news_delete">
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


        <?php if ($activeTab === 'users'): ?>
            <h2>Vartotojų valdymas</h2>
            <p style="color:#666;">Čia galite suteikti arba atimti administratoriaus teises.</p>
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
                                    <input type="hidden" name="action" value="role">
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
