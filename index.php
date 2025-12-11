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

<section class="section partners">
    <div class="partners-header">
        <h2>Mus galite rasti ir čia</h2>
        <p>Monetos, banknotai, albumai, kapsulės ir kitos prekės bei jų aukcionai</p>
    </div>
    <div class="partner-grid">
        <a class="partner-card" href="https://pirkis.lt/shops/30147-e-kolekcija.html" target="_blank" rel="nofollow">
            <div class="partner-media" style="--accent:#85bf27;">
                <img src="https://assets.zyrosite.com/cdn-cgi/image/format=auto,w=606,h=304,fit=crop,trim=71.40718562874251;441.9848484848485;0;0/YZ9VqrOVpxS49M9g/pirkis2-m6Lwny9oz8tyXw83.png" alt="pirkis.lt" loading="lazy">
            </div>
            <div class="partner-meta">
                <span class="label" style="color:#85bf27;">pirkis.lt</span>
                <strong>Patikimas mūsų katalogas</strong>
            </div>
        </a>
        <a class="partner-card" href="https://allegro.pl/uzytkownik/e-Kolekcija" target="_blank" rel="nofollow">
            <div class="partner-media" style="--accent:#ff5a00;">
                <img src="https://assets.zyrosite.com/cdn-cgi/image/format=auto,w=606,h=304,fit=crop,trim=0;0;405.87740805604204;0/YZ9VqrOVpxS49M9g/allegro-YX4yvkxeqyi1r0Vz.png" alt="allegro.pl" loading="lazy">
            </div>
            <div class="partner-meta">
                <span class="label" style="color:#ff5a00;">allegro.pl</span>
                <strong>Reti radiniai kolekcionieriams</strong>
            </div>
        </a>
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
