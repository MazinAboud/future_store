<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';

require_role('customer');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/products.php');
csrf_verify();

$productId = (int)($_POST['product_id'] ?? 0);
$rating = max(1, min(5, (int)($_POST['rating'] ?? 0)));
$comment = trim($_POST['comment'] ?? '');
$comment = mb_substr($comment, 0, 500);

$userId = current_user()['id'];

// One review per customer per product. Without this, the same account could
// post the same rating repeatedly and drag the product's average up or down
// on its own — the average shown on product.php is a straight mean of rows.
$dup = db()->prepare('SELECT 1 FROM reviews WHERE product_id = ? AND user_id = ?');
$dup->execute([$productId, $userId]);

// Verified-purchase gate: a rating may only come from a customer who actually
// received this product. The average on product.php is a straight mean of rows,
// so without this check any logged-in customer could rate a phone they never
// bought — inflating one product's score or tanking a rival's — with a POST that
// carries any product_id. This mirrors the delivered-order rule the maintenance
// flow already enforces: the product must appear in one of this customer's
// delivered orders (matched via the variant that ties an order line to a product).
$bought = db()->prepare("
    SELECT 1 FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN product_variants pv ON pv.id = oi.variant_id
    WHERE o.user_id = ? AND o.status = 'delivered' AND pv.product_id = ?
    LIMIT 1
");
$bought->execute([$userId, $productId]);

if ($productId <= 0 || $rating < 1) {
    flash_set('error', 'حدث خطأ، حاول مرة أخرى.');
} elseif ($dup->fetchColumn()) {
    flash_set('info', 'لقد قمت بتقييم هذا المنتج من قبل.');
} elseif (!$bought->fetchColumn()) {
    flash_set('error', 'يمكنك تقييم المنتجات التي اشتريتها واستلمتها فقط.');
} else {
    // The duplicate check above is a read followed by a write, so two requests
    // submitted at the same moment can both pass it and both try to insert. The
    // UNIQUE key on (product_id, user_id) is what actually guarantees one review
    // per customer per product; catching its error here turns the race from an
    // unhandled exception (a blank 500 page) into the same message the
    // non-racing path already shows.
    try {
        $stmt = db()->prepare('INSERT INTO reviews (product_id, user_id, rating, comment) VALUES (?,?,?,?)');
        $stmt->execute([$productId, $userId, $rating, $comment ?: null]);
        flash_set('success', 'شكرًا لك! تم إضافة تقييمك.');
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            flash_set('info', 'لقد قمت بتقييم هذا المنتج من قبل.');
        } else {
            error_log('Review insert failed: ' . $e->getMessage());
            flash_set('error', 'تعذر إضافة التقييم، حاول مرة أخرى.');
        }
    }
}

redirect('/product.php?slug=' . urlencode($_POST['slug'] ?? ''));
