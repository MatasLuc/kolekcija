<?php
require_once __DIR__ . '/partials.php';
require_admin();

$alert = ['type' => '', 'message' => ''];

function set_alert(string $type, string $message): void
{
    global $alert;
    $alert = ['type' => $type, 'message' => $message];
}

// Handle hero content
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hero') {
    $title = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $btnText = trim($_POST['button_text'] ?? '');
    $btnUrl = trim($_POST['button_url'] ?? '');
    $image = trim($_POST['image_url'] ?? '');

    $stmt = $pdo->prepare('INSERT INTO hero_content (id, title, message, button_text, button_url, image_url) VALUES (1, :title, :message, :btn, :url, :image)
        ON DUPLICATE KEY UPDATE title = VALUES(title), message = VALUES(message), button_text = VALUES(button_text), button_url = VALUES(button_url), image_url = VALUES(image_url)');
    $stmt->execute([
        ':title' => $title,
        ':message' => $message,
        ':btn' => $btnText,
        ':url' => $btnUrl,
        ':image' => $image,
    ]);
    set_alert('success', 'Hero antraštė atnaujinta.');
}

// Handle new news
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'news') {
    $title = trim($_POST['news_title'] ?? '');
    $body = trim($_POST['news_body'] ?? '');
    $id = $_POST['news_id'] ?? '';

    if ($id) {
        $stmt = $pdo->prepare('UPDATE news SET title = :title, body = :body, updated_at = NOW() WHERE id = :id');
        $stmt->execute([':title' => $title, ':body' => $body, ':id' => $id]);
        set_alert('success', 'Naujiena atnaujinta.');
    } else {
        $stmt = $pdo->prepare('INSERT INTO news (title, body, created_at, updated_at) VALUES (:title, :body, NOW(), NOW())');
        $stmt->execute([':title' => $title, ':body' => $body]);
        set_alert('success', 'Naujiena pridėta.');
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
$heroStmt = $pdo->prepare('SELECT title, message, button_text, button_url, image_url FROM hero_content WHERE id = 1');
$heroStmt->execute();
$hero = $heroStmt->fetch() ?: ['title' => '', 'message' => '', 'button_text' => '', 'button_url' => '', 'image_url' => ''];

$news = $pdo->query('SELECT id, title, body, updated_at FROM news ORDER BY updated_at DESC')->fetchAll();
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
        <form method="post">
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

            <button type="submit">Išsaugoti</button>
        </form>

        <h2 style="margin-top:42px;">Naujienos</h2>
        <form method="post">
            <input type="hidden" name="action" value="news">
            <input type="hidden" name="news_id" value="">
            <label for="news_title">Pavadinimas</label>
            <input id="news_title" name="news_title" required>

            <label for="news_body">Turinys</label>
            <textarea id="news_body" name="news_body" rows="4" required></textarea>

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
                                <form method="post" style="margin-top:10px;">
                                    <input type="hidden" name="action" value="news">
                                    <input type="hidden" name="news_id" value="<?php echo e($item['id']); ?>">
                                    <label>Pavadinimas</label>
                                    <input name="news_title" value="<?php echo e($item['title']); ?>" required>
                                    <label>Turinys</label>
                                    <textarea name="news_body" rows="3" required><?php echo e($item['body']); ?></textarea>
                                    <button type="submit">Išsaugoti</button>
                                </form>
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
