<?php
require_once __DIR__ . '/partials.php';

// 1. Gauname šalių sąrašą filtrui (tik tas, kurių turime prekių)
$countriesStmt = $pdo->query("SELECT DISTINCT country FROM products WHERE country IS NOT NULL ORDER BY country ASC");
$countries = $countriesStmt->fetchAll(PDO::FETCH_COLUMN);

// 2. Filtravimo logika
$where = '';
$params = [];
$selectedCountry = $_GET['country'] ?? null;

if ($selectedCountry) {
    $where = "WHERE country = :country";
    $params[':country'] = $selectedCountry;
}

// Puslapiavimas
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 40;
$offset = ($page - 1) * $perPage;

// 3. Gauname prekes su filtru
$sql = "SELECT * FROM products $where ORDER BY scraped_at DESC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
if ($selectedCountry) $stmt->bindValue(':country', $selectedCountry);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll();

// Bendras kiekis (puslapiavimui su filtru)
$countSql = "SELECT COUNT(*) FROM products $where";
$countStmt = $pdo->prepare($countSql);
if ($selectedCountry) $countStmt->bindValue(':country', $selectedCountry);
$countStmt->execute();
$total = $countStmt->fetchColumn();
$totalPages = ceil($total / $perPage);

render_head('Parduotuvė');
render_nav();
?>

<section class="section">
    <div class="partners-header">
        <h2>Kolekcijos parduotuvė</h2>
        <p>Viso prekių: <?php echo $total; ?></p>
    </div>

    <div class="country-filter" style="margin-bottom: 30px; text-align: center;">
        <span style="font-weight:bold; margin-right:10px;">Filtruoti pagal šalį:</span>
        
        <a href="shop.php" class="pill <?php echo !$selectedCountry ? 'active-filter' : ''; ?>" style="margin: 3px; font-size: 0.9rem; <?php echo !$selectedCountry ? 'background:#000; color:#fff;' : ''; ?>">Visos</a>
        
        <?php foreach ($countries as $c): ?>
            <a href="shop.php?country=<?php echo urlencode($c); ?>" 
               class="pill" 
               style="margin: 3px; font-size: 0.9rem; <?php echo $selectedCountry === $c ? 'background:#000; color:#fff;' : ''; ?>">
               <?php echo e($c); ?>
            </a>
        <?php endforeach; ?>
    </div>
    <?php if (!$products): ?>
        <p style="text-align:center">Prekių nerasta.</p>
    <?php else: ?>
        <div class="products-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 30px;">
            <?php foreach ($products as $item): ?>
                <div class="card" style="padding: 0; overflow: hidden; display:flex; flex-direction:column; height: 100%;">
                    <div style="height: 220px; overflow: hidden; background: #fff; display:flex; align-items:center; justify-content:center; border-bottom:1px solid #eee; position:relative;">
                        <?php if ($item['country']): ?>
                            <span style="position:absolute; top:10px; right:10px; background:rgba(0,0,0,0.7); color:#fff; padding:2px 8px; border-radius:10px; font-size:0.7rem;">
                                <?php echo e($item['country']); ?>
                            </span>
                        <?php endif; ?>
                        
                        <?php if ($item['image_url']): ?>
                            <img src="<?php echo e($item['image_url']); ?>" style="width:100%; height:100%; object-fit:contain; padding:10px;" loading="lazy">
                        <?php else: ?>
                            <span style="color:#ccc">Nėra foto</span>
                        <?php endif; ?>
                    </div>
                    
                    <div style="padding: 20px; flex-grow: 1; display:flex; flex-direction:column;">
                        <h3 style="font-size: 1rem; margin: 0 0 10px 0; line-height:1.4;">
                            <a href="<?php echo e($item['url']); ?>" target="_blank" style="text-decoration:none; color:inherit;">
                                <?php echo e($item['title']); ?>
                            </a>
                        </h3>
                        
                        <div style="margin-top: auto; padding-top: 15px;">
                            <span style="font-size: 1.3rem; font-weight:bold; color:#000; display:block; margin-bottom:15px;">
                                <?php echo number_format($item['price'], 2); ?> €
                            </span>
                            <a href="<?php echo e($item['url']); ?>" target="_blank" style="display:block; text-align:center; background:#85bf27; color:#fff; padding:10px; border-radius:30px; font-weight:600; text-decoration:none; transition: background 0.2s;">
                                Pirkti (Pirkis.lt)
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <div style="margin-top: 50px; text-align: center;">
                <?php 
                    $urlParams = $_GET; // Paimame esamus parametrus (country)
                    unset($urlParams['page']); // Išimame seną puslapį
                    $qs = http_build_query($urlParams);
                    $prefix = $qs ? "?$qs&" : "?";
                ?>
                
                <?php if ($page > 1): ?>
                    <a href="<?php echo $prefix; ?>page=<?php echo $page - 1; ?>" class="pill">&larr; Atgal</a>
                <?php endif; ?>
                
                <span style="margin: 0 15px;">Puslapis <?php echo $page; ?> iš <?php echo $totalPages; ?></span>
                
                <?php if ($page < $totalPages): ?>
                    <a href="<?php echo $prefix; ?>page=<?php echo $page + 1; ?>" class="pill">Toliau &rarr;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php render_footer(); ?>
