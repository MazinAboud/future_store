<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('customer');
$me = current_user();

// every device the customer has actually received, with its warranty window
$stmt = db()->prepare("
    SELECT oi.id AS item_id, oi.product_name, oi.variant_desc, o.delivered_at, o.id AS order_id,
           p.image_path, p.warranty_months
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN product_variants pv ON pv.id = oi.variant_id
    JOIN products p ON p.id = pv.product_id
    WHERE o.user_id = ? AND o.status = 'delivered'
    ORDER BY o.delivered_at DESC
");
$stmt->execute([$me['id']]);
$devices = $stmt->fetchAll();

$activePage = 'devices';
$pageTitle = 'أجهزتي';
require __DIR__ . '/../includes/header.php';
?>
<div class="account-layout">
  <?php require __DIR__ . '/../includes/account-nav.php'; ?>
  <div class="account-main">
    <h2 style="font-size:19px;margin-bottom:6px">أجهزتي</h2>
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:18px">كل جهاز اشتريته من Future Store، بحالة الضمان — واحصل على الصيانة مباشرة من هنا.</p>

    <?php if (empty($devices)): ?>
      <div class="empty-state"><h3>لسه ما عندك أجهزة مُسلَّمة</h3><p>هتظهر أجهزتك هنا بعد تسليم أول طلب ليك.</p></div>
    <?php else: foreach ($devices as $d):
      $expiry = strtotime($d['delivered_at'] . " +{$d['warranty_months']} months");
      $isActive = $expiry >= time();
      $daysLeft = (int)ceil(($expiry - time()) / 86400);
    ?>
      <div class="device-card">
        <div class="thumb"><img src="<?= BASE_URL . '/' . e($d['image_path']) ?>" alt=""></div>
        <div class="info">
          <div style="font-weight:800"><?= e($d['product_name']) ?></div>
          <div style="font-size:12px;color:var(--text-muted)"><?= e($d['variant_desc']) ?> · من طلب #<?= (int)$d['order_id'] ?></div>
          <?php if ($isActive): ?>
            <span class="warranty active">الضمان ساري حتى <?= date('Y-m-d', $expiry) ?> (<?= $daysLeft ?> يوم متبقي)</span>
          <?php else: ?>
            <span class="warranty expired">انتهى الضمان في <?= date('Y-m-d', $expiry) ?></span>
          <?php endif; ?>
        </div>
        <a href="<?= BASE_URL ?>/account/maintenance.php?open=<?= (int)$d['item_id'] ?>" class="btn btn-outline btn-sm">افتح طلب صيانة</a>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
