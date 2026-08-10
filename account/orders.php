<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('customer');
$me = current_user();

$stmt = db()->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$me['id']]);
$orders = $stmt->fetchAll();

$itemsStmt = db()->prepare("SELECT * FROM order_items WHERE order_id = ?");

$activePage = 'orders';
$pageTitle = 'طلباتي';
require __DIR__ . '/../includes/header.php';
?>
<div class="account-layout">
  <?php require __DIR__ . '/../includes/account-nav.php'; ?>
  <div class="account-main">
    <h2 style="font-size:19px;margin-bottom:18px">طلباتي</h2>
    <?php if (empty($orders)): ?>
      <div class="empty-state">
        <h3>لسه ما عندك أي طلبات</h3>
        <a href="<?= BASE_URL ?>/products.php" class="btn btn-primary" style="margin-top:14px">ابدأ التسوق</a>
      </div>
    <?php else: foreach ($orders as $o):
      $itemsStmt->execute([$o['id']]);
      $items = $itemsStmt->fetchAll();
      $pm = payment_status_meta($o['payment_status']);
      $sm = order_status_meta($o['status']);
    ?>
      <div class="order-row">
        <div class="order-row-head">
          <div>
            <div class="oid">طلب #<?= (int)$o['id'] ?></div>
            <div class="odate"><?= date('Y-m-d', strtotime($o['created_at'])) ?></div>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <?= status_badge(...$sm) ?>
            <?= status_badge(...$pm) ?>
          </div>
        </div>
        <?php foreach ($items as $it): ?>
          <div class="order-line"><span><?= e($it['product_name']) ?> (<?= e($it['variant_desc']) ?>) × <?= (int)$it['quantity'] ?></span><span><?= money($it['unit_price'] * $it['quantity']) ?></span></div>
        <?php endforeach; ?>
        <div class="order-line" style="font-weight:800;color:var(--text);border-top:1px solid var(--border);margin-top:8px;padding-top:10px"><span>الإجمالي</span><span><?= money($o['total']) ?></span></div>
        <?php if ($o['status'] === 'rejected' && $o['rejection_reason']): ?>
          <div class="flash-msg flash-error" style="margin-top:10px"><?= e($o['rejection_reason']) ?></div>
        <?php endif; ?>
        <?= render_status_track($o['status']) ?>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
