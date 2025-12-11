<?php
require_once __DIR__ . '/partials.php';
require_admin();

$alert = ['type' => '', 'message' => ''];
$uploadDir = __DIR__ . '/uploads';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0775, true);
}

$heroStmt = $pdo->prepare('SELECT title, message, button_text, button_url, image_url, text_align, media_type, media_value FROM hero_content WHERE id = 1');
$heroStmt->execute();
$currentHero = $heroStmt->fetch() ?: [
    'title' => '',
    'message' => '',
    'button_text' => '',
    'button_url' => '',
    'image_url' => '',
    'text_align' => 'left',
    'media_type' => 'image',
    'media_value' => '',
];

function set_alert(string $type, string $message): void
{
    global $alert;
    $alert = ['type' => $type, 'message' => $message];
}

function upload_news_image(int $newsId, array $file, string $caption, string $uploadDir, PDO $pdo): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [false, ''];
    }

    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        return [false, 'Nepavyko gauti failo.'];
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        return [false, 'Paveikslėlis per didelis (maks. 5MB).'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    if (!isset($allowed[$mime])) {
        return [false, 'Nepalaikomas paveikslėlio formatas.'];
    }

    $filename = 'news_' . $newsId . '_' . uniqid() . '.' . $allowed[$mime];
    $targetPath = rtrim($uploadDir, "/\\") . '/' . $filename;
    $publicPath = 'uploads/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return [false, 'Nepavyko įkelti paveikslėlio.'];
    }

    $primaryStmt = $pdo->prepare('SELECT COUNT(*) FROM news_images WHERE news_id = :id AND is_primary = 1');
    $primaryStmt->execute([':id' => $newsId]);
    $shouldBePrimary = (int)$primaryStmt->fetchColumn() === 0 ? 1 : 0;

    $insert = $pdo->prepare('INSERT INTO news_images (news_id, path, caption, is_primary) VALUES (:news_id, :path, :caption, :is_primary)');
    $insert->execute([
        ':news_id' => $newsId,
        ':path' => $publicPath,
        ':caption' => $caption !== '' ? $caption : null,
        ':is_primary' => $shouldBePrimary,
    ]);

    return [true, $shouldBePrimary ? 'Pagrindinis paveikslėlis pridėtas.' : 'Paveikslėlis pridėtas.'];
}

function upload_hero_media(array $file, string $type, string $uploadDir): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [false, ''];
    }

    if (!is_uploaded_file($file['tmp_name'] ?? '')) {
        return [false, 'Nepavyko gauti failo.'];
    }

    $maxSize = $type === 'video' ? 30 * 1024 * 1024 : 8 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxSize) {
        return [false, 'Failas per didelis.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    $allowedImages = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    $allowedVideos = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/ogg' => 'ogv',
    ];

    $allowed = $type === 'video' ? $allowedVideos : $allowedImages;

    if (!isset($allowed[$mime])) {
        return [false, 'Nepalaikomas formato tipas.'];
    }

    $filename = 'hero_' . uniqid() . '.' . $allowed[$mime];
    $targetPath = rtrim($uploadDir, "/\\") . '/' . $filename;
    $publicPath = 'uploads/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return [false, 'Nepavyko įkelti failo.'];
    }

    return [true, $publicPath];
}

// Handle hero content
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hero') {
    $title = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $btnText = trim($_POST['button_text'] ?? '');
    $btnUrl = trim($_POST['button_url'] ?? '');
    $image = trim($_POST['image_url'] ?? '');
    $textAlign = in_array($_POST['text_align'] ?? 'left', ['left', 'center', 'right'], true) ? $_POST['text_align'] : 'left';
    $mediaType = in_array($_POST['media_type'] ?? 'image', ['image', 'video', 'color'], true) ? $_POST['media_type'] : 'image';
    $mediaColor = trim($_POST['media_color'] ?? '#000000');
    $mediaValue = $currentHero['media_value'] ?? '';

    if ($mediaType === 'image') {
        if (!empty($_FILES['hero_image']['name'])) {
            [$ok, $pathOrError] = upload_hero_media($_FILES['hero_image'], 'image', $uploadDir);
            if ($ok) {
                $mediaValue = $pathOrError;
            } elseif ($pathOrError) {
                set_alert('error', $pathOrError);
            }
        } elseif ($image !== '') {
            $mediaValue = $image;
        }
    } elseif ($mediaType === 'video') {
        if (!empty($_FILES['hero_video']['name'])) {
            [$ok, $pathOrError] = upload_hero_media($_FILES['hero_video'], 'video', $uploadDir);
            if ($ok) {
                $mediaValue = $pathOrError;
            } elseif ($pathOrError) {
                set_alert('error', $pathOrError);
            }
        }
    } elseif ($mediaType === 'color') {
        $mediaValue = $mediaColor ?: '#000000';
    }

    $stmt = $pdo->prepare('INSERT INTO hero_content (id, title, message, button_text, button_url, image_url, text_align, media_type, media_value) VALUES (1, :title, :message, :btn, :url, :image, :align, :media_type, :media_value)
        ON DUPLICATE KEY UPDATE title = VALUES(title), message = VALUES(message), button_text = VALUES(button_text), button_url = VALUES(button_url), image_url = VALUES(image_url), text_align = VALUES(text_align), media_type = VALUES(media_type), media_value = VALUES(media_value)');
    $stmt->execute([
        ':title' => $title,
        ':message' => $message,
        ':btn' => $btnText,
        ':url' => $btnUrl,
        ':image' => $image,
        ':align' => $textAlign,
        ':media_type' => $mediaType,
        ':media_value' => $mediaValue,
    ]);
    set_alert('success', 'Hero antraštė atnaujinta.');
}

// Handle new news
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'news') {
    $title = trim($_POST['news_title'] ?? '');
    $body = trim($_POST['news_body'] ?? '');
    $id = $_POST['news_id'] ?? '';
    $caption = trim($_POST['news_image_caption'] ?? '');

    if ($id) {
        $stmt = $pdo->prepare('UPDATE news SET title = :title, body = :body, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':title' => $title, ':body' => $body, ':id' => $id]);
        if (!empty($_FILES['news_image']['name'])) {
            [$ok, $msg] = upload_news_image((int)$id, $_FILES['news_image'], $caption, $uploadDir, $pdo);
            if ($ok && $msg) {
                set_alert('success', 'Naujiena atnaujinta ir ' . strtolower($msg));
            } elseif ($msg) {
                set_alert('error', $msg);
            } else {
                set_alert('success', 'Naujiena atnaujinta.');
            }
        } else {
            set_alert('success', 'Naujiena atnaujinta.');
        }
    } else {
        $stmt = $pdo->prepare('INSERT INTO news (title, body, created_at, updated_at) VALUES (:title, :body, NOW(), NOW())');
        $stmt->execute([':title' => $title, ':body' => $body]);
        $newsId = (int)$pdo->lastInsertId();

        if (!empty($_FILES['news_image']['name'])) {
            [$ok, $msg] = upload_news_image($newsId, $_FILES['news_image'], $caption, $uploadDir, $pdo);
            if ($ok && $msg) {
                set_alert('success', 'Naujiena pridėta ir ' . strtolower($msg));
            } elseif ($msg) {
                set_alert('error', $msg);
            } else {
                set_alert('success', 'Naujiena pridėta.');
            }
        } else {
            set_alert('success', 'Naujiena pridėta.');
        }
    }
}

// Handle adding image to an existing news entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'news_image_add') {
    $newsId = (int)($_POST['news_id'] ?? 0);
    $caption = trim($_POST['caption'] ?? '');

    if ($newsId && isset($_FILES['news_image'])) {
        [$ok, $msg] = upload_news_image($newsId, $_FILES['news_image'], $caption, $uploadDir, $pdo);
        if ($ok) {
            set_alert('success', $msg ?: 'Paveikslėlis pridėtas.');
        } elseif ($msg) {
            set_alert('error', $msg);
        }
    }
}

// Handle marking primary image
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'news_image_primary') {
    $imageId = (int)($_POST['image_id'] ?? 0);
    $newsId = (int)($_POST['news_id'] ?? 0);

    $belongs = $pdo->prepare('SELECT id FROM news_images WHERE id = :id AND news_id = :news_id');
    $belongs->execute([':id' => $imageId, ':news_id' => $newsId]);
    if ($belongs->fetch()) {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE news_images SET is_primary = 0 WHERE news_id = :news_id')->execute([':news_id' => $newsId]);
        $pdo->prepare('UPDATE news_images SET is_primary = 1 WHERE id = :id')->execute([':id' => $imageId]);
        $pdo->commit();
        set_alert('success', 'Pagrindinis paveikslėlis nustatytas.');
    }
}

// Handle caption edits
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'news_image_caption') {
    $imageId = (int)($_POST['image_id'] ?? 0);
    $caption = trim($_POST['caption'] ?? '');

    if ($imageId) {
        $stmt = $pdo->prepare('UPDATE news_images SET caption = :caption WHERE id = :id');
        $stmt->execute([':caption' => $caption !== '' ? $caption : null, ':id' => $imageId]);
        set_alert('success', 'Paveikslėlio aprašas atnaujintas.');
    }
}

// Handle image removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'news_image_delete') {
    $imageId = (int)($_POST['image_id'] ?? 0);

    if ($imageId) {
        $stmt = $pdo->prepare('SELECT path, news_id, is_primary FROM news_images WHERE id = :id');
        $stmt->execute([':id' => $imageId]);
        if ($row = $stmt->fetch()) {
            $fullPath = __DIR__ . '/' . ltrim($row['path'], '/');
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }

            $pdo->prepare('DELETE FROM news_images WHERE id = :id')->execute([':id' => $imageId]);

            if ((int)$row['is_primary'] === 1) {
                $next = $pdo->prepare('SELECT id FROM news_images WHERE news_id = :news_id ORDER BY created_at DESC LIMIT 1');
                $next->execute([':news_id' => $row['news_id']]);
                if ($nextId = $next->fetchColumn()) {
                    $pdo->prepare('UPDATE news_images SET is_primary = 0 WHERE news_id = :news_id')->execute([':news_id' => $row['news_id']]);
                    $pdo->prepare('UPDATE news_images SET is_primary = 1 WHERE id = :id')->execute([':id' => $nextId]);
                }
            }

            set_alert('success', 'Paveikslėlis pašalintas.');
        }
    }
}

// Handle user role change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'role') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $role = $_POST['role'] === 'admin' ? 'admin' : 'user';
    if ($userId) {
        $stmt = $pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
        $stmt->execute([':role' => $role, ':id' => $userId]);
        set_alert('success', 'Vartotojo rolė atnaujinta.');
    }
}

// Fetch data for display
$heroStmt = $pdo->prepare('SELECT title, message, button_text, button_url, image_url, text_align, media_type, media_value FROM hero_content WHERE id = 1');
$heroStmt->execute();
$hero = $heroStmt->fetch() ?: ['title' => '', 'message' => '', 'button_text' => '', 'button_url' => '', 'image_url' => '', 'text_align' => 'left', 'media_type' => 'image', 'media_value' => ''];
$heroColorValue = '#000000';
if (!empty($hero['media_value']) && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $hero['media_value'])) {
    $heroColorValue = $hero['media_value'];
}

$news = $pdo->query('SELECT id, title, body, updated_at FROM news ORDER BY updated_at DESC')->fetchAll();
$imageStmt = $pdo->query('SELECT id, news_id, path, caption, is_primary, created_at FROM news_images ORDER BY is_primary DESC, created_at DESC');
$imagesByNews = [];
foreach ($imageStmt->fetchAll() as $img) {
    $imagesByNews[$img['news_id']][] = $img;
}
$users = $pdo->query('SELECT id, name, email, role FROM users ORDER BY name ASC')->fetchAll();

render_head('Administratoriaus pultas');
render_nav();
?>
<section class="section">
    <div class="card">
        <h1>Administratoriaus pultas</h1>
        <?php if ($alert['message']): ?>
            <div class="alert <?php echo e($alert['type']); ?>"><?php echo e($alert['message']); ?></div>
        <?php endif; ?>

        <h2>Hero antraštė</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="hero">
            <label for="title">Pavadinimas</label>
            <input id="title" name="title" value="<?php echo e($hero['title']); ?>" required>

            <label for="message">Žinutė</label>
            <textarea id="message" name="message" rows="3" required><?php echo e($hero['message']); ?></textarea>

            <label for="button_text">Mygtuko tekstas</label>
            <input id="button_text" name="button_text" value="<?php echo e($hero['button_text']); ?>">

            <label for="button_url">Mygtuko nuoroda</label>
            <input id="button_url" name="button_url" value="<?php echo e($hero['button_url']); ?>">

            <label for="image_url">Fono nuotrauka (URL)</label>
            <input id="image_url" name="image_url" value="<?php echo e($hero['image_url']); ?>">

            <label for="text_align">Teksto lygiuotė</label>
            <select id="text_align" name="text_align">
                <option value="left" <?php echo $hero['text_align'] === 'left' ? 'selected' : ''; ?>>Kairė</option>
                <option value="center" <?php echo $hero['text_align'] === 'center' ? 'selected' : ''; ?>>Centras</option>
                <option value="right" <?php echo $hero['text_align'] === 'right' ? 'selected' : ''; ?>>Dešinė</option>
            </select>

            <label for="media_type">Antraštės fonas</label>
            <select id="media_type" name="media_type">
                <option value="image" <?php echo $hero['media_type'] === 'image' ? 'selected' : ''; ?>>Nuotrauka</option>
                <option value="video" <?php echo $hero['media_type'] === 'video' ? 'selected' : ''; ?>>Video</option>
                <option value="color" <?php echo $hero['media_type'] === 'color' ? 'selected' : ''; ?>>Spalva</option>
            </select>

            <div class="two-up">
                <div>
                    <label for="hero_image">Įkelti nuotrauką</label>
                    <input id="hero_image" name="hero_image" type="file" accept="image/*">
                </div>
                <div>
                    <label for="hero_video">Įkelti video</label>
                    <input id="hero_video" name="hero_video" type="file" accept="video/mp4,video/webm,video/ogg">
                </div>
            </div>

            <label for="media_color">Fono spalva</label>
            <input id="media_color" name="media_color" type="color" value="<?php echo e($heroColorValue); ?>">

            <button type="submit">Išsaugoti</button>
        </form>

        <h2 style="margin-top:42px;">Naujienos</h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="news">
            <input type="hidden" name="news_id" value="">
            <label for="news_title">Pavadinimas</label>
            <input id="news_title" name="news_title" required>

            <label for="news_body">Turinys</label>
            <textarea id="news_body" name="news_body" rows="4" required></textarea>

            <div class="two-up">
                <div>
                    <label for="news_image">Paveikslėlis</label>
                    <input id="news_image" name="news_image" type="file" accept="image/*">
                </div>
                <div>
                    <label for="news_image_caption">Paveikslėlio aprašas</label>
                    <input id="news_image_caption" name="news_image_caption" placeholder="(pasirinktinai)">
                </div>
            </div>

            <button type="submit">Pridėti naujieną</button>
        </form>

        <?php if ($news): ?>
            <table class="table">
                <thead>
                <tr><th>Pavadinimas</th><th>Atnaujinta</th><th>Veiksmas</th></tr>
                </thead>
                <tbody>
                <?php foreach ($news as $item): ?>
                    <tr>
                        <td><?php echo e($item['title']); ?></td>
                        <td><?php echo e($item['updated_at']); ?></td>
                        <td>
                            <details>
                                <summary>Redaguoti</summary>
                                <form method="post" enctype="multipart/form-data" style="margin-top:10px;">
                                    <input type="hidden" name="action" value="news">
                                    <input type="hidden" name="news_id" value="<?php echo e($item['id']); ?>">
                                    <label>Pavadinimas</label>
                                    <input name="news_title" value="<?php echo e($item['title']); ?>" required>
                                    <label>Turinys</label>
                                    <textarea name="news_body" rows="3" required><?php echo e($item['body']); ?></textarea>
                                    <div class="two-up">
                                        <div>
                                            <label>Paveikslėlis</label>
                                            <input name="news_image" type="file" accept="image/*">
                                        </div>
                                        <div>
                                            <label>Paveikslėlio aprašas</label>
                                            <input name="news_image_caption" placeholder="(pasirinktinai)">
                                        </div>
                                    </div>
                                    <button type="submit">Išsaugoti</button>
                                </form>
                                <?php $images = $imagesByNews[$item['id']] ?? []; ?>
                                <div class="news-image-admin">
                                    <div class="news-image-admin__header">
                                        <h4>Paveikslėliai</h4>
                                        <form method="post" enctype="multipart/form-data" class="inline-form">
                                            <input type="hidden" name="action" value="news_image_add">
                                            <input type="hidden" name="news_id" value="<?php echo e($item['id']); ?>">
                                            <input name="caption" placeholder="Aprašas" style="flex:1;">
                                            <input type="file" name="news_image" accept="image/*" required>
                                            <button type="submit">Pridėti</button>
                                        </form>
                                    </div>
                                    <?php if ($images): ?>
                                        <div class="news-image-admin__grid">
                                            <?php foreach ($images as $img): ?>
                                                <div class="news-image-card <?php echo $img['is_primary'] ? 'primary' : ''; ?>">
                                                    <img src="<?php echo e($img['path']); ?>" alt="" loading="lazy">
                                                    <?php if ($img['is_primary']): ?><span class="chip">Pagrindinė</span><?php endif; ?>
                                                    <div class="news-image-card__actions">
                                                        <?php if (!$img['is_primary']): ?>
                                                        <form method="post" class="inline-form">
                                                            <input type="hidden" name="action" value="news_image_primary">
                                                            <input type="hidden" name="news_id" value="<?php echo e($item['id']); ?>">
                                                            <input type="hidden" name="image_id" value="<?php echo e($img['id']); ?>">
                                                            <button type="submit" class="ghost">Padaryti pagrindine</button>
                                                        </form>
                                                        <?php endif; ?>
                                                        <form method="post" class="inline-form" onsubmit="return confirm('Pašalinti paveikslėlį?');">
                                                            <input type="hidden" name="action" value="news_image_delete">
                                                            <input type="hidden" name="image_id" value="<?php echo e($img['id']); ?>">
                                                            <button type="submit" class="ghost danger">Šalinti</button>
                                                        </form>
                                                    </div>
                                                    <form method="post" class="caption-form">
                                                        <input type="hidden" name="action" value="news_image_caption">
                                                        <input type="hidden" name="image_id" value="<?php echo e($img['id']); ?>">
                                                        <input name="caption" value="<?php echo e($img['caption']); ?>" placeholder="Aprašas">
                                                        <button type="submit" class="ghost">Išsaugoti</button>
                                                    </form>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="muted">Nėra įkeltų paveikslėlių.</p>
                                    <?php endif; ?>
                                </div>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2 style="margin-top:42px;">Vartotojų rolės</h2>
        <?php if ($users): ?>
            <table class="table">
                <thead><tr><th>Vardas</th><th>El. paštas</th><th>Rolė</th><th>Atnaujinti</th></tr></thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo e($user['name']); ?></td>
                        <td><?php echo e($user['email']); ?></td>
                        <td><?php echo e($user['role']); ?></td>
                        <td>
                            <form method="post" style="display:flex; gap:8px; align-items:center;">
                                <input type="hidden" name="action" value="role">
                                <input type="hidden" name="user_id" value="<?php echo e($user['id']); ?>">
                                <select name="role">
                                    <option value="user" <?php echo $user['role'] === 'user' ? 'selected' : ''; ?>>Vartotojas</option>
                                    <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>Administratorius</option>
                                </select>
                                <button type="submit">Išsaugoti</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>
<?php render_footer(); ?>
