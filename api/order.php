<?php
/**
 * GET /api/order.php?id=12 -> { ok, order }
 *
 * A single order with its lines and the progress track the website draws.
 *
 * user_id sits in the WHERE clause next to the id, and that placement is the
 * whole security of this endpoint. Fetching by id and then comparing owners in
 * PHP would work too, right up until someone edits the comparison; keeping it
 * in SQL means another customer's order id simply does not match a row, and
 * the answer is 404 — not 403, which would confirm the order exists.
 */

require_once __DIR__ . '/_bootstrap.php';

only('GET');

$me = require_customer();
$id = query_int('id');

if ($id <= 0) {
    json_error('حدد رقم الطلب.', 400);
}

$stmt = db()->prepare('SELECT * FROM orders WHERE id = ? AND user_id = ? LIMIT 1');
$stmt->execute([$id, $me['id']]);
$order = $stmt->fetch();

if (!$order) {
    json_error('الطلب غير موجود.', 404);
}

$itemStmt = db()->prepare('SELECT * FROM order_items WHERE order_id = ?');
$itemStmt->execute([$id]);

$items = array_map(fn($it) => [
    'id'           => (int)$it['id'],
    'variant_id'   => (int)$it['variant_id'],
    'product_name' => $it['product_name'],
    'variant_desc' => $it['variant_desc'],
    'unit_price'   => (float)$it['unit_price'],
    'quantity'     => (int)$it['quantity'],
    'line_total'   => round((float)$it['unit_price'] * (int)$it['quantity'], 2),
], $itemStmt->fetchAll());

// The five-step track the website renders in render_status_track(). Sent as
// data rather than HTML so the app can draw it natively; 'rejected' and
// 'cancelled' are outside the track entirely, matching that function.
$trackSteps = ['pending', 'confirmed', 'processing', 'shipped', 'delivered'];
$isTerminal = in_array($order['status'], ['rejected', 'cancelled'], true);
$currentIdx = $isTerminal ? -1 : array_search($order['status'], $trackSteps, true);

json_ok(['order' => [
    'id'             => (int)$order['id'],
    'status'         => shape_status($order['status']),
    'payment_status' => [
        'code'  => $order['payment_status'],
        'label' => payment_status_meta($order['payment_status'])['label'],
    ],
    'payment_method'   => $order['payment_method'],
    'subtotal'         => (float)$order['subtotal'],
    'shipping_fee'     => (float)$order['shipping_fee'],
    'total'            => (float)$order['total'],
    'shipping_address' => $order['shipping_address'],
    'shipping_phone'   => $order['shipping_phone'],
    'rejection_reason' => $order['rejection_reason'],
    'delivered_at'     => $order['delivered_at'],
    'created_at'       => $order['created_at'],
    'items'            => $items,

    'track' => [
        'is_terminal' => $isTerminal,
        'steps'       => array_map(fn($s, $i) => [
            'code'  => $s,
            'label' => order_status_meta($s)['label'],
            'done'  => $currentIdx !== false && $i <= $currentIdx,
        ], $trackSteps, array_keys($trackSteps)),
    ],

    'currency' => CURRENCY,
]]);
