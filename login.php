<?php
require_once __DIR__ . '/partials.php';

$error = '';
$flashSuccess = flash('success');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare('SELECT id, name, email, password, role FROM users WHERE email = :email');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
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
