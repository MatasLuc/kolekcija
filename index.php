<?php
require_once __DIR__ . '/partials.php';

// Fetch hero content
$stmt = $pdo->prepare('SELECT title, message, button_text, button_url, image_url, text_align, media_type, media_value FROM hero_content WHERE id = 1');
$stmt->execute();
$hero = $stmt->fetch() ?: [
    'title' => 'Kolekcionierių bendruomenė',
    'message' => 'Atraskite monetas, banknotus ir kitus radinius vienoje modernioje erdvėje.',
    'button_text' => 'Peržiūrėti naujienas',
    'button_url' => 'news.php',
    'image_url' => '',
    'text_align' => 'left',
    'media_type' => 'image',
    'media_value' => ''
];

$align = in_array($hero['text_align'], ['left', 'center', 'right'], true) ? $hero['text_align'] : 'left';
$mediaType = in_array($hero['media_type'], ['image', 'video', 'color'], true) ? $hero['media_type'] : 'image';
$mediaValue = trim($hero['media_value'] ?? '') ?: trim($hero['image_url'] ?? '');
$heroClasses = ['hero', 'align-' . $align];
$heroStyle = '';

if ($mediaType === 'color' && $mediaValue) {
    $heroClasses[] = 'hero--color';
    $heroStyle = '--hero-color:' . e($mediaValue) . ';';
} elseif ($mediaType === 'image' && $mediaValue) {
    $heroClasses[] = 'hero--image';
    $heroStyle = '--hero-image: url(' . e($mediaValue) . ');';
} elseif ($mediaType === 'video' && $mediaValue) {
    $heroClasses[] = 'hero--video';
}

render_head('e-kolekcija.lt');
render_nav();
?>
<section class="<?php echo e(implode(' ', $heroClasses)); ?>" style="<?php echo e($heroStyle); ?>">
    <?php if ($mediaType === 'video' && $mediaValue): ?>
        <video class="hero-video" src="<?php echo e($mediaValue); ?>" autoplay muted loop playsinline></video>
    <?php endif; ?>
    <div class="content">
        <h1><?php echo e($hero['title']); ?></h1>
        <p><?php echo e($hero['message']); ?></p>
        <?php if (!empty($hero['button_text'])): ?>
            <a class="cta" href="<?php echo e($hero['button_url']); ?>"><?php echo e($hero['button_text']); ?></a>
        <?php endif; ?>
    </div>
</section>

<section class="section partners-modern">
    <div class="partners-header">
        <h2>Partneriai</h2>
        <p>Apsilankykite mūsų partnerių parduotuvėse</p>
    </div>

    <div class="partners-container">
        <a href="https://pirkis.lt/shops/30147-e-kolekcija.html" target="_blank" rel="nofollow" class="partner-box pirkis">
            <div class="box-content">
                <span class="partner-label">Katalogas</span>
                <h3>Pirkis.lt</h3>
                <p>Patikimas asortimentas jūsų kolekcijai</p>
            </div>
            <div class="box-action">
                <span>Atidaryti</span>
                <span class="arrow">&rarr;</span>
            </div>
        </a>

        <a href="https://allegro.pl/uzytkownik/e-Kolekcija" target="_blank" rel="nofollow" class="partner-box allegro">
            <div class="box-content">
                <span class="partner-label">Aukcionai</span>
                <h3>Allegro.pl</h3>
                <p>Reti radiniai ir išskirtiniai pasiūlymai</p>
            </div>
            <div class="box-action">
                <span>Atidaryti</span>
                <span class="arrow">&rarr;</span>
            </div>
        </a>
    </div>
</section>
<section class="section">
    <div class="contact-block">
        <div class="contact-info">
            <h3>Kontaktai</h3>
            <p>Mes pasirengę atsakyti į klausimus apie pirkimus, pardavimus ar bendradarbiavimą.</p>
            <ul>
                <li>Telefono numeris: <strong>+37060093880</strong></li>
                <li>El. paštas: <strong>e.kolekcija@gmail.com</strong></li>
            </ul>
        </div>
        
        <div class="contact-form-wrapper">
            <h3>Užklausa</h3>
            <form action="#" method="post" onsubmit="alert('Tai demonstracinė forma.'); return false;">
                <label for="contact-email">Jūsų el. paštas</label>
                <input id="contact-email" type="email" placeholder="vardas@pastas.lt" required>
                <button type="submit">Siųsti žinutę</button>
            </form>
        </div>
    </div>
</section>
<?php render_footer(); ?>
