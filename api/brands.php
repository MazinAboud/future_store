<?php
/**
 * GET /api/brands.php -> { ok, brands }
 *
 * Drives the brand filter chips on the products tab. Counts only active
 * products, so a brand whose entire catalogue was hidden shows 0 rather than
 * sending the customer to an empty list.
 */

require_once __DIR__ . '/_bootstrap.php';

only('GET');

$rows = db()->query("
    SELECT b.id, b.name, b.slug, COUNT(p.id) AS product_count
    FROM brands b
    LEFT JOIN products p ON p.brand_id = b.id AND p.is_active = 1
    GROUP BY b.id
    ORDER BY b.name
")->fetchAll();

json_ok(['brands' => array_map(fn($b) => [
    'id'            => (int)$b['id'],
    'name'          => $b['name'],
    'slug'          => $b['slug'],
    'product_count' => (int)$b['product_count'],
], $rows)]);
