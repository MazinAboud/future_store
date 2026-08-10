<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';

require_role('customer');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/products.php');
csrf_verify();

$variantId = (int)($_POST['variant_id'] ?? 0);
$userId = current_user()['id'];

$stmt = db()->prepare('INSERT IGNORE INTO stock_notifications (variant_id, user_id) VALUES (?, ?)');
$stmt->execute([$variantId, $userId]);

flash_set('success', 'تمام! هنبلغك فور توفر هذا الجهاز.');
redirect('/product.php?slug=' . urlencode($_POST['slug'] ?? '') . '&variant=' . $variantId);
