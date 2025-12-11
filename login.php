<?php
require_once __DIR__ . '/partials.php';

// 1. Apsauga: Tikriname CSRF žetoną (jei tai POST užklausa)
require_csrf();

$error = '';
$flashSuccess = flash('success');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT id, name, email, password, role FROM users WHERE email = :email');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        // 2. Apsauga: Sesijos fiksacijos prevencija
        // Pakeičiame sesijos ID po sėkmingo prisijungimo, kad senas ID taptų negaliojantis
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        header('Location: index.php');
        exit;
    }
    $error = 'Neteisingas el. paštas arba slaptažodis.';
}

render_head('Prisijungimas');
render_nav();
?>
<section class="section">
    <div class="card form-card">
        <h2>Prisijungti</h2>
        <?php if ($flashSuccess): ?>
            <div class="alert success"><?php echo e($flashSuccess); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert error"><?php echo e($error); ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo csrf_token(); ?>">

            <label for="email">El. paštas</label>
            <input id="email" name="email" type="email" required>

            <label for="password">Slaptažodis</label>
            <input id="password" name="password" type="password" required>

            <button type="submit">Prisijungti</button>
        </form>
        <p>Neturite paskyros? <a href="register.php">Registruokitės</a></p>
    </div>
</section>
<?php render_footer(); ?>
