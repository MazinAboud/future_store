<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';

require_role('customer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/products.php');
csrf_verify();

$variantId = (int)($_POST['variant_id'] ?? 0);
$qty = max(1, (int)($_POST['qty'] ?? 1));

$stmt = db()->prepare('SELECT stock_quantity FROM product_variants WHERE id = ?');
$stmt->execute([$variantId]);
$stock = $stmt->fetchColumn();

if ($stock === false) {
    flash_set('error', 'المنتج غير موجود.');
} elseif ($stock < 1) {
    flash_set('error', 'هذه النسخة غير متوفرة حاليًا في المخزون.');
} else {
    cart_add($variantId, min($qty, (int)$stock));
    flash_set('success', 'تمت إضافة المنتج إلى السلة.');
}

if (($_POST['redirect'] ?? '') === 'product' && !empty($_POST['slug'])) {
    redirect('/product.php?slug=' . urlencode($_POST['slug']));
}
redirect('/cart.php');
