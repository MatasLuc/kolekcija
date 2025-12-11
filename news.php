<?php
require_once __DIR__ . '/partials.php';

// Paimame naujienas
$stmt = $pdo->query('SELECT id, title, body, created_at, updated_at FROM news ORDER BY created_at DESC');
$items = $stmt->fetchAll();

// Paimame nuotraukas
$imgStmt = $pdo->query('SELECT news_id, path, caption, is_primary, created_at FROM news_images ORDER BY is_primary DESC, created_at DESC');
$imagesByNews = [];
foreach ($imgStmt->fetchAll() as $img) {
    $imagesByNews[$img['news_id']][] = $img;
}

render_head('Naujienos');
render_nav();
?>
<section class="section">
    <div class="card">
        <h1>Naujienos</h1>
        <div class="news-list">
            <?php if (!$items): ?>
                <p>Dar nėra naujienų.</p>
            <?php endif; ?>
            <?php foreach ($items as $news): ?>
                <?php
                // Nuotraukų logika
                $images = $imagesByNews[$news['id']] ?? [];
                $primary = null;
                if ($images) {
                    foreach ($images as $img) {
                        if ((int)$img['is_primary'] === 1) {
                            $primary = $img;
                            break;
                        }
                    }
                    if (!$primary) {
                        $primary = $images[0];
                    }
                }

                // Santraukos logika (pirmieji 120 simbolių)
                $fullBody = e($news['body']);
                $excerpt = mb_strlen($fullBody) > 120 ? mb_substr($fullBody, 0, 120) . '...' : $fullBody;
                ?>
                
                <a href="article.php?id=<?php echo $news['id']; ?>" class="news-item news-card-link">
                    <?php if ($primary): ?>
                        <figure class="news-cover" style="height: 200px; margin: -18px -18px 15px -18px; border-radius: 12px 12px 0 0; border: none; border-bottom: 1px solid #eee; overflow: hidden;">
                            <img src="<?php echo e($primary['path']); ?>" alt="" loading="lazy" style="width: 100%; height: 100%; object-fit: cover; transition: transform 0.3s ease;">
                        </figure>
                    <?php else: ?>
                        <div style="height: 200px; background: #f0f0f0; margin: -18px -18px 15px -18px; display:flex; align-items:center; justify-content:center; color:#999; border-radius: 12px 12px 0 0;">
                            Be nuotraukos
                        </div>
                    <?php endif; ?>

                    <h3 style="margin: 0 0 10px; font-size: 1.15rem; line-height: 1.3; color: #000;"><?php echo e($news['title']); ?></h3>
                    
                    <time style="font-size: 0.8rem; color: #888; margin-bottom: 12px; display:block;">
                        <?php echo date('Y-m-d', strtotime($news['created_at'])); ?>
                    </time>
                    
                    <p style="margin: 0; color: #555; font-size: 0.95rem; line-height: 1.5; flex-grow: 1;">
                        <?php echo $excerpt; ?>
                    </p>
                    
                    <span style="display:inline-block; margin-top:15px; color: #000; font-weight:600; font-size: 0.9rem;">
                        Skaityti daugiau &rarr;
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php render_footer(); ?>
