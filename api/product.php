<?php
/**
 * GET /api/product.php?slug=iphone-15   -> { ok, product }
 * GET /api/product.php?id=2             -> same, by id
 *
 * Everything the product-detail screen needs in one round trip: the phone's
 * specifications, every sellable variant with its own price and stock, the
 * reviews with their average, and four similar products from the same brand.
 * The website's product.php builds exactly this set across several queries.
 *
 * p.is_active = 1 is in the WHERE clause, not applied afterwards: a product the
 * admin hid must 404 here just as it does in the browser, or the app would
 * happily let someone deep-link to a withdrawn phone and add it to a cart.
 */

require_once __DIR__ . '/_bootstrap.php';

only('GET');

$slug = query_str('slug');
$id   = query_int('id');

if ($slug === '' && $id <= 0) {
    json_error('حدد المنتج المطلوب.', 400);
}

if ($slug !== '') {
    $stmt = db()->prepare("
        SELECT p.*, b.name AS brand_name
        FROM products p JOIN brands b ON b.id = p.brand_id
        WHERE p.slug = ? AND p.is_active = 1
    ");
    $stmt->execute([$slug]);
} else {
    $stmt = db()->prepare("
        SELECT p.*, b.name AS brand_name
        FROM products p JOIN brands b ON b.id = p.brand_id
        WHERE p.id = ? AND p.is_active = 1
    ");
    $stmt->execute([$id]);
}

$product = $stmt->fetch();
if (!$product) {
    json_error('المنتج غير موجود.', 404);
}

$vStmt = db()->prepare('SELECT * FROM product_variants WHERE product_id = ? ORDER BY price');
$vStmt->execute([$product['id']]);
$variants = $vStmt->fetchAll();

$rStmt = db()->prepare("
    SELECT r.id, r.rating, r.comment, r.created_at, u.full_name
    FROM reviews r JOIN users u ON u.id = r.user_id
    WHERE r.product_id = ?
    ORDER BY r.created_at DESC
");
$rStmt->execute([$product['id']]);
$reviews = $rStmt->fetchAll();

$avg = $reviews
    ? round(array_sum(array_column($reviews, 'rating')) / count($reviews), 2)
    : null;

$sStmt = db()->prepare("
    SELECT p.id, p.name, p.slug, p.image_path, MIN(pv.price) AS min_price
    FROM products p
    JOIN product_variants pv ON pv.product_id = p.id
    WHERE p.brand_id = ? AND p.id <> ? AND p.is_active = 1
    GROUP BY p.id
    ORDER BY p.id DESC
    LIMIT 4
");
$sStmt->execute([$product['brand_id'], $product['id']]);
$similar = $sStmt->fetchAll();

json_ok(['product' => [
    'id'              => (int)$product['id'],
    'name'            => $product['name'],
    'slug'            => $product['slug'],
    'short_desc'      => $product['short_desc'],
    'brand_id'        => (int)$product['brand_id'],
    'brand_name'      => $product['brand_name'],
    'warranty_months' => (int)$product['warranty_months'],
    'image_url'       => image_url($product['image_path']),

    // Kept as an ordered list of label/value pairs rather than loose keys so
    // the app can render the spec table without hard-coding six field names,
    // and so a spec left empty in the admin form simply disappears instead of
    // showing a blank row.
    'specs' => array_values(array_filter([
        ['label' => 'الشاشة',    'value' => $product['screen']],
        ['label' => 'المعالج',   'value' => $product['processor']],
        ['label' => 'الكاميرا',  'value' => $product['camera']],
        ['label' => 'البطارية',  'value' => $product['battery']],
        ['label' => 'المقاومة',  'value' => $product['resistance']],
        ['label' => 'الضمان',    'value' => (int)$product['warranty_months'] . ' شهرًا'],
    ], fn($s) => trim((string)$s['value']) !== '')),

    'variants' => array_map(fn($v) => [
        'id'             => (int)$v['id'],
        'storage'        => $v['storage'],
        'color'          => $v['color'],
        'sku'            => $v['sku'],
        'price'          => (float)$v['price'],
        'stock_quantity' => (int)$v['stock_quantity'],
        'in_stock'       => (int)$v['stock_quantity'] > 0,
    ], $variants),

    'rating' => [
        'average' => $avg !== null ? (float)$avg : null,
        'count'   => count($reviews),
    ],

    'reviews' => array_map(fn($r) => [
        'id'         => (int)$r['id'],
        'rating'     => (int)$r['rating'],
        'comment'    => $r['comment'],
        'author'     => $r['full_name'],
        'created_at' => $r['created_at'],
    ], $reviews),

    'similar' => array_map(fn($s) => [
        'id'        => (int)$s['id'],
        'name'      => $s['name'],
        'slug'      => $s['slug'],
        'min_price' => (float)$s['min_price'],
        'image_url' => image_url($s['image_path']),
    ], $similar),

    'currency' => CURRENCY,
]]);
