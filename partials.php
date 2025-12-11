<?php
require_once __DIR__ . '/functions.php';

function render_head(string $title = 'e-kolekcija.lt'): void
{
    echo "<!DOCTYPE html>\n<html lang=\"lt\">\n<head>\n<meta charset=\"UTF-8\">\n<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n<title>" . e($title) . "</title>\n";
    
    // NAUJOS EILUTĖS PRADŽIA (įkeliame Poppins šriftą)
    echo "<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n";
    echo "<link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin>\n";
    echo "<link href=\"https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;900&display=swap\" rel=\"stylesheet\">\n";
    // NAUJOS EILUTĖS PABAIGA
    
    echo "<link rel=\"stylesheet\" href=\"styles.css\">\n</head>\n<body>";
}

function render_nav(): void
{
    $user = current_user();
    ?>
    <header class="topbar">
        <a href="index.php" class="logo" aria-label="e-kolekcija.lt">E-KOLEKCIJA.LT</a>
        <nav class="menu">
            <a href="index.php">Pagrindinis</a>
            <a href="news.php">Naujienos</a>
            
            <?php if ($user): ?>
                <div class="dropdown">
                    <span class="welcome dropdown-trigger">
                        Sveiki, <?php echo e($user['name']); ?> &#9662;
                    </span>
                    <div class="dropdown-content">
                        <?php if (is_admin()): ?>
                            <a href="admin.php">Administratorius</a>
                        <?php endif; ?>
                        <a href="logout.php" class="logout-link">Atsijungti</a>
                    </div>
                </div>
            <?php else: ?>
                <a href="login.php">Prisijungti</a>
                <a href="register.php">Registruotis</a>
            <?php endif; ?>
        </nav>
    </header>
    <?php
}

function render_footer(): void
{
    echo "<footer class=\"footer\">© " . date('Y') . " e-kolekcija.lt</footer></body></html>";
}
