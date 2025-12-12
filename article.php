<?php
require_once __DIR__ . '/partials.php';

// Gauname ID iš URL (pvz. article.php?id=5)
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id) {
    header('Location: news.php');
    exit;
}

// Paimame konkrečią naujieną
$stmt = $pdo->prepare('SELECT title, body, created_at, updated_at FROM news WHERE id = :id');
$stmt->execute([':id' => $id]);
$news = $stmt->fetch();

if (!$news) {
    die('Naujiena nerasta.');
}

// Paimame nuotraukas tai naujienai
$imgStmt = $pdo->prepare('SELECT path, caption, is_primary FROM news_images WHERE news_id = :id ORDER BY is_primary DESC, created_at DESC');
$imgStmt->execute([':id' => $id]);
$images = $imgStmt->fetchAll();

render_head($news['title']);
render_nav();
?>

<section class="section">
    <div class="card">
        <a href="news.php" style="font-size: 0.9rem; color: #666;">&larr; Grįžti į naujienas</a>
        <h1 style="margin-top: 10px;"><?php echo e($news['title']); ?></h1>
        <time style="color:#888; display:block; margin-bottom: 20px;">
            <?php echo date('Y-m-d H:i', strtotime($news['updated_at'] ?: $news['created_at'])); ?>
        </time>

        <?php if ($images): ?>
            <div class="news-cover" style="margin-bottom: 20px;">
                <?php 
                // Pirmą nuotrauką rodome didelę
                $primary = $images[0]; 
                ?>
                <img src="<?php echo e($primary['path']); ?>" alt="" style="max-width:100%; height:auto; border-radius:10px;">
                <?php if (!empty($primary['caption'])): ?>
                    <figcaption><?php echo e($primary['caption']); ?></figcaption>
                <?php endif; ?>
            </div>
            
            <?php if (count($images) > 1): ?>
                <div class="news-thumbs" style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:20px;">
                    <?php foreach ($images as $index => $img): if ($index === 0) continue; ?>
                        <img src="<?php echo e($img['path']); ?>" style="height:100px; object-fit:cover; border-radius:6px;" alt="">
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="news-body" style="font-size: 1.1rem; line-height: 1.8;">
            <?php echo nl2br(e($news['body'])); ?>
        </div>
    </div>
</section>

<?php render_footer(); ?>