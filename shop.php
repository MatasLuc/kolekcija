<?php
require_once __DIR__ . '/partials.php';

// FILTRŲ DUOMENYS
$countriesStmt = $pdo->query("SELECT DISTINCT country FROM products WHERE country IS NOT NULL AND country != '' ORDER BY country ASC");
$countries = $countriesStmt->fetchAll(PDO::FETCH_COLUMN);

$categoriesStmt = $pdo->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != '' ORDER BY category ASC");
$categories = $categoriesStmt->fetchAll(PDO::FETCH_COLUMN);

// FILTRAVIMO LOGIKA
$whereClauses = [];
$params = [];

// 1. Kategorija
$selectedCategory = $_GET['category'] ?? null;
if ($selectedCategory) {
    $whereClauses[] = "category = :category";
    $params[':category'] = $selectedCategory;
}

// 2. Šalis
$selectedCountry = $_GET['country'] ?? null;
if ($selectedCountry === 'nepriskirta') {
    $whereClauses[] = "(country IS NULL OR country = '')";
} elseif ($selectedCountry) {
    $whereClauses[] = "country = :country";
    $params[':country'] = $selectedCountry;
}

// 3. Paieška
$search = trim($_GET['search'] ?? '');
if ($search) {
    // Ieškome pavadinime arba išoriniame ID
    $whereClauses[] = "(title LIKE :search OR external_id LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$whereSql = '';
if ($whereClauses) {
    $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);
}

// Puslapiavimas
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$perPage = 40; 
$offset = ($page - 1) * $perPage;

// UŽKLAUSOS
$sql = "SELECT * FROM products $whereSql ORDER BY scraped_at DESC LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll();

$countSql = "SELECT COUNT(*) FROM products $whereSql";
$countStmt = $pdo->prepare($countSql);
foreach ($params as $key => $val) {
    $countStmt->bindValue($key, $val);
}
$countStmt->execute();
$total = $countStmt->fetchColumn();
$totalPages = ceil($total / $perPage);

function filterUrl($key, $value) {
    $params = $_GET;
    if ($value === null) { unset($params[$key]); } else { $params[$key] = $value; }
    $params['page'] = 1; 
    return '?' . http_build_query($params);
}

render_head('Parduotuvė');
render_nav();
?>

<div class="section" style="padding-bottom: 0;">
    <div class="partners-header">
        <h2>Kolekcijos parduotuvė</h2>
    </div>

    <div class="shop-notice-wrapper" style="max-width: 1000px; margin: 0 auto 40px auto;">
        <div class="shop-info-card">
            <div class="shop-info-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/>
                </svg>
            </div>
            <div class="shop-info-content">
                <h3>Kaip pirkti?</h3>
                <p>
                    Ši svetainė veikia kaip mūsų kolekcijos katalogas. Pirkimo procesas yra vykdomas saugiai per mūsų partnerių platformą <strong>Pirkis.lt</strong>. 
                    Paspaudę mygtuką <span style="color:#85bf27; font-weight:600;">Pirkti</span>, būsite nukreipti į konkrečią prekę ten.
                    <br><br>
                    <span style="font-size: 0.9rem; color: #888;">
                        * Duomenys atnaujinami automatiškai, tačiau esant nesutapimams tarp šio katalogo ir Pirkis.lt (kaina ar likutis), 
                        prašome vadovautis informacija, pateikta <strong>Pirkis.lt</strong> sistemoje.
                    </span>
                </p>
            </div>
        </div>
    </div>

    <div class="filter-bar" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 15px;">
        
        <div style="display:flex; gap:15px; flex-wrap:wrap;">
            <details class="custom-select">
                <summary><?php echo $selectedCategory ? htmlspecialchars($selectedCategory) : 'Kategorija'; ?></summary>
                <div class="dropdown-menu">
                    <a href="<?php echo filterUrl('category', null); ?>" class="<?php echo !$selectedCategory ? 'active' : ''; ?>">Visos</a>
                    <?php foreach ($categories as $cat): ?>
                        <a href="<?php echo filterUrl('category', $cat); ?>" class="<?php echo $selectedCategory === $cat ? 'active' : ''; ?>"><?php echo e($cat); ?></a>
                    <?php endforeach; ?>
                </div>
            </details>

            <details class="custom-select">
                <summary><?php echo $selectedCountry ? (($selectedCountry === 'nepriskirta') ? 'Nepriskirta' : htmlspecialchars($selectedCountry)) : 'Valstybė'; ?></summary>
                <div class="dropdown-menu">
                    <a href="<?php echo filterUrl('country', null); ?>" class="<?php echo !$selectedCountry ? 'active' : ''; ?>">Visos</a>
                    <a href="<?php echo filterUrl('country', 'nepriskirta'); ?>" class="<?php echo $selectedCountry === 'nepriskirta' ? 'active' : ''; ?>" style="color:#d32f2f;">Nepriskirta</a>
                    <?php foreach ($countries as $c): ?>
                        <a href="<?php echo filterUrl('country', $c); ?>" class="<?php echo $selectedCountry === $c ? 'active' : ''; ?>"><?php echo e($c); ?></a>
                    <?php endforeach; ?>
                </div>
            </details>
        </div>

        <div style="display:flex; align-items:center; gap: 20px; margin-left: auto;">
            <?php if ($selectedCategory || $selectedCountry || $search): ?>
                <a href="shop.php" class="clear-filters" style="margin:0;">Išvalyti filtrus ✕</a>
            <?php endif; ?>
            <span style="font-weight: 600; font-size: 0.95rem; color: #333; white-space: nowrap;">Viso: <?php echo $total; ?></span>
        </div>
    </div>

    <div style="max-width: 100%; margin-bottom: 30px;">
        <form action="shop.php" method="get" style="display: flex; gap: 10px;">
            <?php if ($selectedCategory): ?><input type="hidden" name="category" value="<?php echo e($selectedCategory); ?>"><?php endif; ?>
            <?php if ($selectedCountry): ?><input type="hidden" name="country" value="<?php echo e($selectedCountry); ?>"><?php endif; ?>
            
            <input 
                type="text" 
                name="search" 
                value="<?php echo e($search); ?>" 
                placeholder="Ieškoti pavadinimo..." 
                style="flex-grow: 1; padding: 12px 20px; border-radius: 30px; border: 1px solid #ddd; font-size: 1rem; margin-top:0;"
            >
            <button type="submit" style="border-radius: 30px; margin-top:0; padding-left: 30px; padding-right: 30px;">Ieškoti</button>
        </form>
    </div>

</div>

<div class="section" style="padding-top: 0;">
    <?php if (!$products): ?>
        <p style="text-align:center">Prekių nerasta.</p>
    <?php else: ?>
        <div class="products-grid">
            <?php foreach ($products as $item): ?>
                <?php
                // --- PATAISYTAS PAVADINIMŲ VALYMAS ---
                $displayTitle = $item['title'];

                // 1. Keičiame "_" sekas (net jei tarp jų yra tarpai) į tarpą
                // Pvz.: "_ _ _" arba "_" arba "______" -> " "
                $displayTitle = preg_replace('/(?:_\s*)+/', ' ', $displayTitle);

                // 2. Keičiame 2 ar daugiau taškų sekas (net jei su tarpais) į tarpą
                // Pvz.: ". . . ." -> " ", bet "2013." lieka nepaliestas
                $displayTitle = preg_replace('/(?:\.\s*){2,}/', ' ', $displayTitle);

                // 3. Išvalome dvigubus tarpus ir "nukerpame" galus
                $displayTitle = preg_replace('/\s+/', ' ', $displayTitle);
                $displayTitle = trim($displayTitle);
                ?>

                <article class="product-card">
                    <a href="<?php echo e($item['url']); ?>" target="_blank" class="product-image-wrap">
                        <div class="product-badges">
                            <?php if ($item['country']): ?><span class="badge"><?php echo e($item['country']); ?></span><?php endif; ?>
                        </div>
                        <?php if ($item['image_url']): ?>
                            <img src="<?php echo e($item['image_url']); ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span style="color:#ccc; font-size:0.8rem;">Nėra foto</span>
                        <?php endif; ?>
                    </a>
                    <div class="product-info">
                        <h3 class="product-title"><a href="<?php echo e($item['url']); ?>" target="_blank"><?php echo e($displayTitle); ?></a></h3>
                        <div class="product-meta"><?php echo $item['category'] ? e($item['category']) : '&nbsp;'; ?></div>
                        <div class="product-footer">
                            <span class="product-price"><?php echo number_format($item['price'], 2); ?> €</span>
                            <a href="<?php echo e($item['url']); ?>" target="_blank" class="btn-buy">Pirkti</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php 
                    $urlParams = $_GET; unset($urlParams['page']);
                    $qs = http_build_query($urlParams); $prefix = $qs ? "?$qs&" : "?";
                ?>
                <?php if ($page > 1): ?>
                    <a href="<?php echo $prefix; ?>page=<?php echo $page - 1; ?>" class="page-link">&larr;</a>
                <?php endif; ?>
                <span style="font-weight:600; margin:0 10px;"><?php echo $page; ?> / <?php echo $totalPages; ?></span>
                <?php if ($page < $totalPages): ?>
                    <a href="<?php echo $prefix; ?>page=<?php echo $page + 1; ?>" class="page-link">&rarr;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php render_footer(); ?>
