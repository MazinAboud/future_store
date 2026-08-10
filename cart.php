<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';

require_role('customer');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $variantId = (int)($_POST['variant_id'] ?? 0);
    if ($action === 'update') {
        cart_set_qty($variantId, max(0, (int)($_POST['qty'] ?? 1)));
    } elseif ($action === 'remove') {
        cart_remove($variantId);
        flash_set('info', 'تمت إزالة المنتج من السلة.');
    }
    redirect('/cart.php');
}

$items = cart_items();
$subtotal = cart_subtotal();
$shipping = empty($items) ? 0 : SHIPPING_FEE;
$total = $subtotal + $shipping;

$pageTitle = 'السلة';
require __DIR__ . '/includes/header.php';
?>

<div class="breadcrumb"><a href="<?= BASE_URL ?>/index.php">الرئيسية</a> &gt; <b>السلة</b></div>

<?php if (empty($items)): ?>
  <div class="empty-state">
    <h3>سلتك فارغة</h3>
    <p>لم تضِف أي منتجات بعد.</p>
    <a href="<?= BASE_URL ?>/products.php" class="btn btn-primary" style="margin-top:16px">تصفح المنتجات</a>
  </div>
<?php else: ?>
<div class="cart-layout">
  <div class="cart-items">
    <?php foreach ($items as $it): ?>
      <div class="cart-item">
        <div class="thumb"><img src="<?= BASE_URL . '/' . e($it['image_path']) ?>" alt=""></div>
        <div class="cart-item-info">
          <div class="name"><?= e($it['product_name']) ?></div>
          <div class="variant"><?= e($it['storage']) ?> · <?= e($it['color']) ?></div>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="variant_id" value="<?= (int)$it['variant_id'] ?>">
            <button type="submit" class="remove-link">إزالة</button>
          </form>
        </div>
        <form method="post" class="qty-stepper" style="border:1px solid var(--border)">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="variant_id" value="<?= (int)$it['variant_id'] ?>">
          <button type="submit" name="qty" value="<?= max(0, $it['quantity']-1) ?>">−</button>
          <input type="number" value="<?= (int)$it['quantity'] ?>" readonly style="pointer-events:none">
          <button type="submit" name="qty" value="<?= $it['quantity']+1 ?>" <?= $it['quantity'] >= $it['stock_quantity'] ? 'disabled' : '' ?>>+</button>
        </form>
        <div class="price"><?= money($it['line_total']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="summary-card">
    <h3 style="font-size:16px;margin-bottom:14px">ملخص الطلب</h3>
    <div class="summary-row"><span>المجموع الفرعي</span><span><?= money($subtotal) ?></span></div>
    <div class="summary-row"><span>التوصيل</span><span><?= money($shipping) ?></span></div>
    <div class="summary-row total"><span>الإجمالي</span><span><?= money($total) ?></span></div>
    <a href="<?= BASE_URL ?>/checkout.php" class="btn btn-primary btn-block" style="margin-top:14px">متابعة للدفع</a>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
