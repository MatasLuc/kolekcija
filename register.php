<?php
require_once __DIR__ . '/functions.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (strlen($password) < 6) {
        $error = 'Slaptažodis turi būti bent 6 simbolių.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute([':email' => $email]);
        if ($stmt->fetch()) {
            $error = 'Paskyra su šiuo el. paštu jau egzistuoja.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $insert = $pdo->prepare('INSERT INTO users (name, email, password, role) VALUES (:name, :email, :password, :role)');
            $insert->execute([
                ':name' => $name,
                ':email' => $email,
                ':password' => $hash,
                ':role' => 'user'
            ]);
            $success = 'Registracija sėkminga! Galite prisijungti.';
        }
    }
}

render_head('Registracija');
render_nav();
?>
<section class="section">
    <div class="card form-card">
        <h2>Registruotis</h2>
        <?php if ($error): ?><div class="alert error"><?php echo e($error); ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert success"><?php echo e($success); ?></div><?php endif; ?>
        <form method="post">
            <label for="name">Vardas</label>
            <input id="name" name="name" type="text" required>

            <label for="email">El. paštas</label>
            <input id="email" name="email" type="email" required>

            <label for="password">Slaptažodis</label>
            <input id="password" name="password" type="password" required>

            <button type="submit">Registruotis</button>
        </form>
        <p>Jau turite paskyrą? <a href="login.php">Prisijunkite</a></p>
    </div>
</section>
<?php render_footer(); ?>
