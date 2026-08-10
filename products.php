<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';

$allBrands = db()->query("SELECT * FROM brands ORDER BY name")->fetchAll();

// ---- read + sanitize filters ----
// brand can arrive as a single scalar (?brand=3, from the homepage brand cards)
// or as an array (brand[]=1&brand[]=2, from the checkbox filters below) — handle both.
$brandParam = $_GET['brand'] ?? [];
$selectedBrands = is_array($brandParam) ? array_map('intval', $brandParam) : [(int)$brandParam];
$minPrice = isset($_GET['min']) && $_GET['min'] !== '' ? (float)$_GET['min'] : null;
$maxPrice = isset($_GET['max']) && $_GET['max'] !== '' ? (float)$_GET['max'] : null;
$selectedStorage = $_GET['storage'] ?? [];
if (!is_array($selectedStorage)) $selectedStorage = [$selectedStorage];
$selectedStorage = array_filter(array_map('strval', $selectedStorage));
$q = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'newest';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;

// ---- build query dynamically but safely (bound params only) ----
$where = ['p.is_active = 1'];
$params = [];

if ($selectedBrands) {
    $in = implode(',', array_fill(0, count($selectedBrands), '?'));
    $where[] = "p.brand_id IN ($in)";
    array_push($params, ...$selectedBrands);
}
if ($minPrice !== null) { $where[] = 'pv.price >= ?'; $params[] = $minPrice; }
if ($maxPrice !== null) { $where[] = 'pv.price <= ?'; $params[] = $maxPrice; }
if ($selectedStorage) {
    $in = implode(',', array_fill(0, count($selectedStorage), '?'));
    $where[] = "pv.storage IN ($in)";
    array_push($params, ...$selectedStorage);
}
if ($q !== '') { $where[] = 'p.name LIKE ?'; $params[] = '%' . $q . '%'; }

$orderBy = match ($sort) {
    'price_asc'  => 'min_price ASC',
    'price_desc' => 'min_price DESC',
    default      => 'p.created_at DESC',
};

$whereSql = implode(' AND ', $where);

// Total distinct products matching the filters, counted in SQL over the grouped
// set. This replaces "fetch every matching row, then array_slice one page out of
// it in PHP": at catalogue scale that pulled the whole result set across the wire
// on every page view. COUNT(*) over the GROUP BY subquery (not a bare COUNT(*),
// which would count variant rows and inflate the page total) gives the row count
// the pager needs while the database returns only the current page below. Same
// shape api/products.php already uses.
$countStmt = db()->prepare("
    SELECT COUNT(*) FROM (
        SELECT p.id
        FROM products p
        JOIN brands b ON b.id = p.brand_id
        JOIN product_variants pv ON pv.product_id = p.id
        WHERE $whereSql
        GROUP BY p.id
    ) AS matched
");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// $perPage (the constant 12) and $offset are interpolated rather than bound:
// MySQL rejects a placeholder in LIMIT when emulated prepares are off (see
// includes/db.php). Both are plain integers — $offset derives from the clamped
// (int) $page — so nothing from the request reaches the SQL string as text.
$sql = "
    SELECT p.id, p.name, p.slug, p.image_path, b.name AS brand_name,
           MIN(pv.price) AS min_price,
           SUM(pv.stock_quantity) AS total_stock
    FROM products p
    JOIN brands b ON b.id = p.brand_id
    JOIN product_variants pv ON pv.product_id = p.id
    WHERE $whereSql
    GROUP BY p.id
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
";
$all = db()->prepare($sql);
$all->execute($params);
$rows = $all->fetchAll();

// counts per brand (for filter sidebar, respecting current search/price/storage but not brand selection itself)
$whereNoBrand = ['p.is_active = 1'];
$paramsNoBrand = [];
if ($minPrice !== null) { $whereNoBrand[] = 'pv.price >= ?'; $paramsNoBrand[] = $minPrice; }
if ($maxPrice !== null) { $whereNoBrand[] = 'pv.price <= ?'; $paramsNoBrand[] = $maxPrice; }
if ($selectedStorage) {
    $in = implode(',', array_fill(0, count($selectedStorage), '?'));
    $whereNoBrand[] = "pv.storage IN ($in)";
    array_push($paramsNoBrand, ...$selectedStorage);
}
if ($q !== '') { $whereNoBrand[] = 'p.name LIKE ?'; $paramsNoBrand[] = '%' . $q . '%'; }
$whereNoBrandSql = implode(' AND ', $whereNoBrand);

// One grouped query for every brand's count at once, replacing a COUNT run once
// per brand inside a loop (an N+1 that grew with the brand list). COUNT(DISTINCT
// p.id) keeps the storage-filter join from counting a product once per matching
// variant. Brands with no match simply don't come back from the GROUP BY, so the
// list is pre-seeded to 0 — identical output to the per-brand loop it replaces.
$brandCounts = array_fill_keys(array_map(fn($b) => (int)$b['id'], $allBrands), 0);
$bcStmt = db()->prepare("
    SELECT p.brand_id, COUNT(DISTINCT p.id) AS c
    FROM products p
    JOIN product_variants pv ON pv.product_id = p.id
    WHERE $whereNoBrandSql
    GROUP BY p.brand_id
");
$bcStmt->execute($paramsNoBrand);
foreach ($bcStmt->fetchAll() as $bc) {
    $brandCounts[(int)$bc['brand_id']] = (int)$bc['c'];
}
$storageOptions = db()->query("SELECT DISTINCT storage FROM product_variants ORDER BY CAST(storage AS UNSIGNED)")->fetchAll(PDO::FETCH_COLUMN);

function qs(array $override = []) {
    $params = array_merge($_GET, $override);
    return '?' . http_build_query($params);
}

$pageTitle = 'كل المنتجات';
require __DIR__ . '/includes/header.php';
?>

<div class="breadcrumb"><a href="<?= BASE_URL ?>/index.php">الرئيسية</a> &gt; <b>كل المنتجات</b></div>

<div class="listing-layout">
  <aside class="filters">
    <form method="get" id="filterForm">
      <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
      <div class="filter-group">
        <h4>الماركة</h4>
        <?php foreach ($allBrands as $b): ?>
          <label class="filter-row">
            <span><input type="checkbox" name="brand[]" value="<?= (int)$b['id'] ?>" onchange="this.form.submit()" <?= in_array($b['id'], $selectedBrands) ? 'checked' : '' ?>> <?= e($b['name']) ?></span>
            <span class="count"><?= $brandCounts[$b['id']] ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="filter-group">
        <h4>نطاق السعر (<?= CURRENCY ?>)</h4>
        <div class="price-inputs">
          <input type="number" name="min" placeholder="من" value="<?= e($_GET['min'] ?? '') ?>">
          <input type="number" name="max" placeholder="إلى" value="<?= e($_GET['max'] ?? '') ?>">
        </div>
        <button type="submit" class="btn btn-outline btn-sm" style="margin-top:10px;width:100%">تطبيق</button>
      </div>
      <div class="filter-group">
        <h4>السعة التخزينية</h4>
        <?php foreach ($storageOptions as $s): ?>
          <label class="filter-row">
            <span><input type="checkbox" name="storage[]" value="<?= e($s) ?>" onchange="this.form.submit()" <?= in_array($s, $selectedStorage) ? 'checked' : '' ?>> <?= e($s) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
    </form>
  </aside>

  <main class="listing-main">
    <?php if ($selectedBrands || $minPrice !== null || $maxPrice !== null || $selectedStorage || $q !== ''): ?>
      <div class="active-filters">
        <?php if ($q !== ''): ?><span class="chip">بحث: "<?= e($q) ?>" <a href="<?= qs(['q'=>null]) ?>">×</a></span><?php endif; ?>
        <a href="<?= BASE_URL ?>/products.php" class="chip" style="color:var(--navy)">مسح الكل</a>
      </div>
    <?php endif; ?>

    <div class="listing-toolbar">
      <span class="count-label"><?= (int)$total ?> منتج</span>
      <form method="get" id="sortForm">
        <?php foreach ($_GET as $k => $v) if ($k !== 'sort' && $k !== 'page'): foreach ((array)$v as $vv): ?><input type="hidden" name="<?= e($k) ?><?= is_array($v)?'[]':'' ?>" value="<?= e($vv) ?>"><?php endforeach; endif; ?>
        <select name="sort" class="sort-select" onchange="this.form.submit()">
          <option value="newest" <?= $sort==='newest'?'selected':'' ?>>الأحدث</option>
          <option value="price_asc" <?= $sort==='price_asc'?'selected':'' ?>>الأقل سعرًا</option>
          <option value="price_desc" <?= $sort==='price_desc'?'selected':'' ?>>الأعلى سعرًا</option>
        </select>
      </form>
    </div>

    <?php if (empty($rows)): ?>
      <div class="empty-state">
        <h3>لا توجد منتجات مطابقة</h3>
        <p>جرّب تعديل الفلاتر أو البحث بكلمة مختلفة.</p>
      </div>
    <?php else: ?>
      <div class="product-grid">
        <?php foreach ($rows as $p): ?>
          <div class="product-card">
            <div class="product-thumb">
              <a href="<?= BASE_URL ?>/product.php?slug=<?= e($p['slug']) ?>" aria-label="<?= e($p['name']) ?>">
                <img src="<?= BASE_URL . '/' . e($p['image_path']) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
              </a>
              <?php if ($p['total_stock'] == 0): ?><span class="stock-flag">نفد المخزون</span><?php endif; ?>
              <form method="post" action="<?= BASE_URL ?>/compare-toggle.php">
                <?= csrf_field() ?>
                <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="back" value="<?= e(current_path()) ?>">
                <button type="submit" class="compare-checkbox">
                  <?= in_array($p['id'], $_SESSION['compare'] ?? []) ? icon_svg('check', 12, 2.8) . ' في المقارنة' : '+ قارن' ?>
                </button>
              </form>
            </div>
            <div class="product-info">
              <div class="product-brand"><?= e($p['brand_name']) ?></div>
              <div class="product-name"><a href="<?= BASE_URL ?>/product.php?slug=<?= e($p['slug']) ?>"><?= e($p['name']) ?></a></div>
              <div class="product-price"><?= money($p['min_price']) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($totalPages > 1): ?>
        <div class="pagination">
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i == $page): ?><span class="current"><?= $i ?></span>
            <?php else: ?><a href="<?= qs(['page'=>$i]) ?>"><?= $i ?></a><?php endif; ?>
          <?php endfor; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
