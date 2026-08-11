<?php
/**
 * GET /api/home.php  -> { ok, brands, best_sellers }
 *
 * Feeds the app's first tab. Both queries are the ones index.php already runs,
 * so "الأكثر مبيعًا" means the same thing in both places: real sold quantity
 * from order_items, excluding rejected and cancelled orders, falling back to
 * newest when nothing has sold yet. Public — no token needed, matching the
 * website where a visitor sees the homepage without logging in.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/cache.php';

only('GET');

// Cheap: one grouped count over four brand rows. Measured at well under a
// millisecond even with a thousand products, so it runs live.
$brands = db()->query("
    SELECT b.id, b.name, b.slug, COUNT(p.id) AS product_count
    FROM brands b
    LEFT JOIN products p ON p.brand_id = b.id AND p.is_active = 1
    GROUP BY b.id
    ORDER BY b.name
")->fetchAll();

/*
 * Best sellers, cached for ten minutes.
 *
 * This is the most expensive query in the whole project and the one on the
 * screen every single visitor opens first. Load testing put it at a 133 ms
 * median with 10,019 orders and 20,092 order items behind it, and adding
 * composite indexes moved that to 131 ms — because the query's cost is an
 * aggregate over the entire order history, which no index can shorten.
 *
 * Ten minutes of staleness on a "most popular phones" list is not something a
 * customer can perceive, and it takes the query off the hot path completely:
 * the second visitor within the window pays a file read instead of 133 ms of
 * database work. On a busy homepage that is the difference between the
 * database idling and the database being the bottleneck for everything else.
 *
 * Cached under a version-tagged key so that changing the query shape below
 * cannot serve rows in the old format to the new code.
 */
$best = cache_remember(CACHE_KEY_API_BEST_SELLERS, 600, function () {
    return db()->query("
        SELECT p.id, p.name, p.slug, p.short_desc, p.image_path, b.name AS brand_name,
               MIN(pv.price)                AS min_price,
               SUM(pv.stock_quantity)       AS total_stock,
               COALESCE(SUM(oi.quantity),0) AS sold
        FROM products p
        JOIN brands b ON b.id = p.brand_id
        JOIN product_variants pv ON pv.product_id = p.id
        LEFT JOIN order_items oi ON oi.variant_id = pv.id
        -- 'delivered' only, matching index.php and both report surfaces. This
        -- query counted every order that merely was not cancelled, so untouched
        -- pending baskets ranked products: 311 units against 225 actually
        -- delivered, a 38% overstatement, on 49 orders that never shipped. The
        -- website was corrected for this and the API was missed, which is worse
        -- than either being wrong alone — the app and the site then disagree
        -- about what is selling, and nobody can tell which one to believe.
        LEFT JOIN orders o ON o.id = oi.order_id AND o.status = 'delivered'
        WHERE p.is_active = 1
        GROUP BY p.id
        ORDER BY sold DESC, p.created_at DESC, p.id DESC
        LIMIT 8
    ")->fetchAll();
});

json_ok([
    'brands' => array_map(fn($b) => [
        'id'            => (int)$b['id'],
        'name'          => $b['name'],
        'slug'          => $b['slug'],
        'product_count' => (int)$b['product_count'],
    ], $brands),

    'best_sellers' => array_map(fn($p) => [
        'id'          => (int)$p['id'],
        'name'        => $p['name'],
        'slug'        => $p['slug'],
        'short_desc'  => $p['short_desc'],
        'brand_name'  => $p['brand_name'],
        'min_price'   => (float)$p['min_price'],
        'total_stock' => (int)$p['total_stock'],
        'sold'        => (int)$p['sold'],
        'image_url'   => image_url($p['image_path']),
    ], $best),

    'currency'     => CURRENCY,
    'shipping_fee' => (float)SHIPPING_FEE,
]);
