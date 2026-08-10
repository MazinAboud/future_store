<?php
/**
 * GET /api/orders.php -> { ok, orders }
 *
 * Every order belonging to the token holder, newest first, each with its lines
 * already attached so the "طلباتي" tab renders in one round trip.
 *
 * WHERE user_id = the id from the token. There is no way to widen it: the
 * endpoint accepts no id parameter at all, so there is nothing to tamper with.
 */

require_once __DIR__ . '/_bootstrap.php';

only('GET');

$me = require_customer();

$stmt = db()->prepare('SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC, id DESC');
$stmt->execute([$me['id']]);
$orders = $stmt->fetchAll();

if (!$orders) {
    json_ok(['orders' => [], 'currency' => CURRENCY]);
}

// One query for all the lines instead of one per order: with a long order
// history the per-order version turns a single screen into dozens of round
// trips to MySQL. The id list is built from database output, not from the
// request, so interpolating it introduces nothing.
$ids = implode(',', array_map(fn($o) => (int)$o['id'], $orders));
$itemRows = db()->query("SELECT * FROM order_items WHERE order_id IN ($ids)")->fetchAll();

$itemsByOrder = [];
foreach ($itemRows as $it) {
    $itemsByOrder[(int)$it['order_id']][] = [
        'id'           => (int)$it['id'],
        'variant_id'   => (int)$it['variant_id'],
        'product_name' => $it['product_name'],
        'variant_desc' => $it['variant_desc'],
        'unit_price'   => (float)$it['unit_price'],
        'quantity'     => (int)$it['quantity'],
        'line_total'   => round((float)$it['unit_price'] * (int)$it['quantity'], 2),
    ];
}

json_ok([
    'orders' => array_map(fn($o) => [
        'id'             => (int)$o['id'],
        'status'         => shape_status($o['status']),
        'payment_status' => [
            'code'  => $o['payment_status'],
            'label' => payment_status_meta($o['payment_status'])['label'],
        ],
        'payment_method'   => $o['payment_method'],
        'subtotal'         => (float)$o['subtotal'],
        'shipping_fee'     => (float)$o['shipping_fee'],
        'total'            => (float)$o['total'],
        'shipping_address' => $o['shipping_address'],
        'shipping_phone'   => $o['shipping_phone'],
        'rejection_reason' => $o['rejection_reason'],
        'delivered_at'     => $o['delivered_at'],
        'created_at'       => $o['created_at'],
        'items'            => $itemsByOrder[(int)$o['id']] ?? [],
    ], $orders),

    'currency' => CURRENCY,
]);
