<?php
require_once __DIR__ . '/partials.php';

$stmt = $pdo->query('SELECT id, title, body, created_at, updated_at FROM news ORDER BY created_at DESC');
$items = $stmt->fetchAll();
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
                ?>
                <article class="news-item">
                    <h3><?php echo e($news['title']); ?></h3>
                    <time><?php echo date('Y-m-d H:i', strtotime($news['updated_at'] ?: $news['created_at'])); ?></time>
                    <?php if ($primary): ?>
                        <figure class="news-cover">
                            <img src="<?php echo e($primary['path']); ?>" alt="" loading="lazy">
                            <?php if (!empty($primary['caption'])): ?><figcaption><?php echo e($primary['caption']); ?></figcaption><?php endif; ?>
                        </figure>
                        <?php if (count($images) > 1): ?>
                            <div class="news-thumbs">
                                <?php foreach ($images as $img): if ($img === $primary) continue; ?>
                                    <div class="thumb">
                                        <img src="<?php echo e($img['path']); ?>" alt="" loading="lazy">
                                        <?php if (!empty($img['caption'])): ?><span><?php echo e($img['caption']); ?></span><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <p><?php echo nl2br(e($news['body'])); ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php render_footer(); ?>
