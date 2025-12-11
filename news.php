<?php
require_once __DIR__ . '/partials.php';

$stmt = $pdo->query('SELECT id, title, body, created_at, updated_at FROM news ORDER BY created_at DESC');
$items = $stmt->fetchAll();

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
                <article class="news-item">
                    <h3><?php echo e($news['title']); ?></h3>
                    <time><?php echo date('Y-m-d H:i', strtotime($news['updated_at'] ?: $news['created_at'])); ?></time>
                    <p><?php echo nl2br(e($news['body'])); ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php render_footer(); ?>
