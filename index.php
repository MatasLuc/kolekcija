<?php
require_once __DIR__ . '/partials.php';

// Fetch hero content
$stmt = $pdo->prepare('SELECT title, message, button_text, button_url, image_url FROM hero_content WHERE id = 1');
$stmt->execute();
$hero = $stmt->fetch() ?: [
    'title' => 'Kolekcionierių bendruomenė',
    'message' => 'Atraskite monetas, banknotus ir kitus radinius vienoje modernioje erdvėje.',
    'button_text' => 'Peržiūrėti naujienas',
    'button_url' => 'news.php',
    'image_url' => ''
];

render_head('e-kolekcija.lt');
render_nav();
?>
<section class="hero" style="<?php echo $hero['image_url'] ? '--hero-image: url(' . e($hero['image_url']) . ');' : ''; ?>">
    <div class="content">
        <h1><?php echo e($hero['title']); ?></h1>
        <p><?php echo e($hero['message']); ?></p>
        <?php if (!empty($hero['button_text'])): ?>
            <a class="cta" href="<?php echo e($hero['button_url']); ?>"><?php echo e($hero['button_text']); ?></a>
        <?php endif; ?>
    </div>
</section>

<section class="section">
    <div class="card">
        <h2>Mus galite rasti ir čia</h2>
        <div class="cards" style="margin-top: 16px;">
            <div class="card">
                <h3>pirkis.lt</h3>
                <p>Monetos, banknotai, albumai, kapsulės ir kitos prekės bei jų aukcionai.</p>
            </div>
            <div class="card">
                <h3>allegro.pl</h3>
                <p>Nauji radiniai ir reti egzemplioriai kolekcionieriams.</p>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="cards">
        <div class="card">
            <h3>Kontaktai</h3>
            <p>Mes pasirengę atsakyti į klausimus apie pirkimus, pardavimus ar bendradarbiavimą.</p>
            <ul>
                <li>Telefono numeris: <strong>+37060093880</strong></li>
                <li>El. paštas: <strong>e.kolekcija@gmail.com</strong></li>
            </ul>
        </div>
        <div class="card">
            <h3>Užklausa</h3>
            <form action="#" method="post" onsubmit="alert('Tai demonstracinė forma. Prijunkite el. paštą arba API, kad gautumėte užklausas.'); return false;">
                <label for="contact-email">El. paštas</label>
                <input id="contact-email" type="email" placeholder="vardas@pastas.lt" required>
                <button type="submit">Patvirtinti</button>
            </form>
        </div>
    </div>
</section>

<?php render_footer(); ?>
