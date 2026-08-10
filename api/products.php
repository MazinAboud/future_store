<?php
/**
 * GET /api/products.php -> { ok, products, meta, filters }
 *
 * Query: q, brand (repeatable or comma list), min, max, storage (repeatable or
 *        comma list), sort=newest|price_asc|price_desc, page, per_page
 *
 * Mirrors the filtering and sorting in the website's products.php, including
 * the fact that a price filter matches a product when ANY of its variants
 * falls in the range, and that min_price is the cheapest variant that survived
 * the filter — not the cheapest variant overall.
 *
 * ONE DELIBERATE DIFFERENCE, and the reason is worth reading. products.php
 * sorts by p.created_at DESC then slices the array in PHP. Every seeded
 * product carries the identical created_at, so that sort is entirely tied and
 * MySQL is free to return the rows in any order it likes — different on each
 * execution. The website gets away with it because it fetches the whole set
 * once. Paging cannot: if the order shifts between the request for page 1 and
 * the request for page 2, the customer sees some products twice and never sees
 * others at all. Adding p.id DESC as a tiebreaker makes the order total and
 * therefore stable, while still reading as "newest first".
 */

require_once __DIR__ . '/_bootstrap.php';

only('GET');

/** Accepts ?brand=1&brand=2, ?brand[]=1&brand[]=2, or ?brand=1,2 — the app
 *  sends the comma form, curl and the browser tend to send the others. */
function multi_param(string $key): array
{
    $raw = $_GET[$key] ?? null;
    if ($raw === null || $raw === '') return [];
    if (!is_array($raw)) $raw = explode(',', (string)$raw);
    return array_values(array_filter(array_map('trim', $raw), fn($v) => $v !== ''));
}

$q              = query_str('q');
$selectedBrands = array_values(array_filter(array_map('intval', multi_param('brand'))));
$selectedStorage= multi_param('storage');
$minPrice       = isset($_GET['min']) && $_GET['min'] !== '' ? (float)$_GET['min'] : null;
$maxPrice       = isset($_GET['max']) && $_GET['max'] !== '' ? (float)$_GET['max'] : null;
$sort           = query_str('sort', 'newest');
$page           = max(1, query_int('page', 1));
$perPage        = min(48, max(1, query_int('per_page', 12)));

$where  = ['p.is_active = 1'];
$params = [];

if ($selectedBrands) {
    $where[] = 'p.brand_id IN (' . implode(',', array_fill(0, count($selectedBrands), '?')) . ')';
    array_push($params, ...$selectedBrands);
}
if ($minPrice !== null) { $where[] = 'pv.price >= ?'; $params[] = $minPrice; }
if ($maxPrice !== null) { $where[] = 'pv.price <= ?'; $params[] = $maxPrice; }
if ($selectedStorage) {
    $where[] = 'pv.storage IN (' . implode(',', array_fill(0, count($selectedStorage), '?')) . ')';
    array_push($params, ...$selectedStorage);
}
// Bound parameter, not interpolation: the search box is the one field on this
// endpoint whose contents come straight from a person's keyboard.
if ($q !== '') { $where[] = 'p.name LIKE ?'; $params[] = '%' . $q . '%'; }

$whereSql = implode(' AND ', $where);

// Whitelist, never the raw value: $sort lands in the SQL string itself, where a
// bound parameter is not allowed, so it must never carry request text.
$orderBy = match ($sort) {
    'price_asc'  => 'min_price ASC, p.id DESC',
    'price_desc' => 'min_price DESC, p.id DESC',
    default      => 'p.created_at DESC, p.id DESC',
};

// COUNT over the grouped set: COUNT(*) alone would count variant rows, so a
// phone sold in four storage sizes would be reported as four products and the
// app would render an extra, permanently empty page.
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
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$stmt = db()->prepare("
    SELECT p.id, p.name, p.slug, p.short_desc, p.image_path, p.warranty_months,
           b.id AS brand_id, b.name AS brand_name,
           MIN(pv.price)          AS min_price,
           SUM(pv.stock_quantity) AS total_stock
    FROM products p
    JOIN brands b ON b.id = p.brand_id
    JOIN product_variants pv ON pv.product_id = p.id
    WHERE $whereSql
    GROUP BY p.id
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
");
// $perPage and $offset are interpolated rather than bound because MySQL will
// not accept a placeholder in LIMIT with emulated prepares switched off (see
// includes/db.php). Both passed through (int) casts and were clamped above, so
// nothing from the request survives into the string as text.
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Storage options for the filter sheet, taken from active products only so the
// app never offers a capacity that returns nothing.
$storages = db()->query("
    SELECT DISTINCT pv.storage
    FROM product_variants pv
    JOIN products p ON p.id = pv.product_id
    WHERE p.is_active = 1
    ORDER BY CAST(pv.storage AS UNSIGNED), pv.storage
")->fetchAll(PDO::FETCH_COLUMN);

$priceRange = db()->query("
    SELECT MIN(pv.price) AS lo, MAX(pv.price) AS hi
    FROM product_variants pv
    JOIN products p ON p.id = pv.product_id
    WHERE p.is_active = 1
")->fetch();

json_ok([
    'products' => array_map(fn($p) => [
        'id'              => (int)$p['id'],
        'name'            => $p['name'],
        'slug'            => $p['slug'],
        'short_desc'      => $p['short_desc'],
        'brand_id'        => (int)$p['brand_id'],
        'brand_name'      => $p['brand_name'],
        'warranty_months' => (int)$p['warranty_months'],
        'min_price'       => (float)$p['min_price'],
        'total_stock'     => (int)$p['total_stock'],
        'image_url'       => image_url($p['image_path']),
    ], $rows),

    'meta' => [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $totalPages,
        'has_more'    => $page < $totalPages,
    ],

    'filters' => [
        'storages'  => $storages,
        'price_min' => $priceRange && $priceRange['lo'] !== null ? (float)$priceRange['lo'] : 0.0,
        'price_max' => $priceRange && $priceRange['hi'] !== null ? (float)$priceRange['hi'] : 0.0,
    ],

    'currency' => CURRENCY,
]);
