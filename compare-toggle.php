<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/products.php');
csrf_verify();

$productId = (int)($_POST['product_id'] ?? 0);
$list = $_SESSION['compare'] ?? [];

if (in_array($productId, $list)) {
    $list = array_values(array_diff($list, [$productId]));
} elseif (count($list) < 4) {
    $list[] = $productId;
} else {
    flash_set('info', 'يمكنك مقارنة 4 منتجات كحد أقصى في المرة الواحدة.');
}
$_SESSION['compare'] = $list;

$back = $_POST['back'] ?? '/products.php';
redirect($back);
