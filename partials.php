<?php
require_once __DIR__ . '/functions.php';

function render_head(string $title = 'e-kolekcija.lt'): void
{
    echo "<!DOCTYPE html>\n<html lang=\"lt\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n<title>" . e($title) . "</title>\n<link rel=\"stylesheet\" href=\"styles.css\">\n</head>\n<body>";
}

function render_nav(): void
{
    $user = current_user();
    ?>
    <header class="topbar">
        <a href="index.php" class="logo" aria-label="e-kolekcija.lt">e-kolekcija.lt</a>
        <nav class="menu">
            <a href="index.php">Pagrindinis</a>
            <a href="news.php">Naujienos</a>
            <?php if ($user): ?>
                <?php if (is_admin()): ?>
                    <a href="admin.php">Administratorius</a>
                <?php endif; ?>
                <span class="welcome">Sveiki, <?php echo e($user['name']); ?></span>
                <a class="pill" href="logout.php">Atsijungti</a>
            <?php else: ?>
                <a class="pill" href="login.php">Prisijungti</a>
                <a class="pill" href="register.php">Registruotis</a>
            <?php endif; ?>
        </nav>
    </header>
    <?php
}

function render_footer(): void
{
    echo "<footer class=\"footer\">© " . date('Y') . " e-kolekcija.lt</footer></body></html>";
}
