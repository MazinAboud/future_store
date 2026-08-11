<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin'); // sales figures are admin-only; employees get staff/index.php as their overview

$pdo = db();

$todaySales = $pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at) = CURDATE() AND payment_status = 'paid'")->fetchColumn();
$yestSales  = $pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at) = CURDATE() - INTERVAL 1 DAY AND payment_status = 'paid'")->fetchColumn();
$pendingCount = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
$lowStockCount = $pdo->query("SELECT COUNT(*) FROM product_variants WHERE stock_quantity > 0 AND stock_quantity <= " . LOW_STOCK_THRESHOLD)->fetchColumn();
$newCustomers = $pdo->query("SELECT COUNT(*) FROM users WHERE role='customer' AND created_at >= CURDATE() - INTERVAL 7 DAY")->fetchColumn();

$delta = $yestSales > 0 ? round((($todaySales - $yestSales) / $yestSales) * 100) : ($todaySales > 0 ? 100 : 0);

function dayLabelAr($ymd) {
    $map = ['Sun'=>'أحد','Mon'=>'إثنين','Tue'=>'ثلاثاء','Wed'=>'أربعاء','Thu'=>'خميس','Fri'=>'جمعة','Sat'=>'سبت'];
    return $map[date('D', strtotime($ymd))];
}

// last 7 days sales for the bar chart
$days = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $sum = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at) = ? AND payment_status = 'paid'");
    $sum->execute([$d]);
    $days[] = ['date' => $d, 'label' => dayLabelAr($d), 'total' => (float)$sum->fetchColumn()];
}
$maxDay = max(array_column($days, 'total')) ?: 1;

$recentOrders = $pdo->query("
    SELECT o.id, o.total, o.status, u.full_name, GROUP_CONCAT(oi.product_name SEPARATOR '، ') AS products
    FROM orders o JOIN users u ON u.id=o.user_id
    JOIN order_items oi ON oi.order_id = o.id
    GROUP BY o.id ORDER BY o.created_at DESC LIMIT 5
")->fetchAll();

$activeAdminPage = 'overview';
$pageTitle = 'نظرة عامة';
require __DIR__ . '/../includes/admin-header.php';
?>

<div class="admin-topline"><h2>نظرة عامة</h2></div>

<div class="kpi-grid">
  <div class="kpi-card">
    <div class="label"><?= kpi_icon('coins', 'amber') ?>مبيعات اليوم</div>
    <div class="value"><?= money($todaySales) ?></div>
    <div class="delta <?= $delta >= 0 ? 'up' : 'warn' ?>"><?= icon_svg($delta >= 0 ? 'trend-up' : 'trend-down', 12, 2.8) ?> <?= abs($delta) ?>٪ عن أمس</div>
  </div>
  <div class="kpi-card">
    <div class="label"><?= kpi_icon('clock', 'warning') ?>طلبات معلقة</div>
    <div class="value"><?= (int)$pendingCount ?></div>
    <div class="delta warn">تحتاج مراجعة</div>
  </div>
  <div class="kpi-card">
    <div class="label"><?= kpi_icon('warning', 'danger') ?>منتجات منخفضة المخزون</div>
    <div class="value"><?= (int)$lowStockCount ?></div>
    <div class="delta warn"><?= LOW_STOCK_THRESHOLD ?> قطع أو أقل</div>
  </div>
  <div class="kpi-card">
    <div class="label"><?= kpi_icon('user-plus', 'success') ?>عملاء جدد</div>
    <div class="value"><?= (int)$newCustomers ?></div>
    <div class="delta up">آخر 7 أيام</div>
  </div>
</div>

<div class="chart-card">
  <h3 style="font-size:14px;margin-bottom:4px">المبيعات — آخر 7 أيام</h3>
  <div class="bar-chart neutral">
    <?php foreach ($days as $d): $h = $maxDay > 0 ? max(4, round(($d['total']/$maxDay)*100)) : 4; ?>
      <div class="bar" style="height:<?= $h ?>%" title="<?= money($d['total']) ?>">
        <b><?= $d['total'] > 0 ? money($d['total']) : '' ?></b>
        <span><?= $d['label'] ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<h3 style="font-size:15px;margin-bottom:12px">آخر الطلبات</h3>
<div class="table-wrap">
  <table class="simple-table">
    <tr><th>رقم الطلب</th><th>العميل</th><th>المنتج</th><th>الإجمالي</th><th>الحالة</th></tr>
    <?php foreach ($recentOrders as $o): $sm = order_status_meta($o['status']); ?>
      <tr>
        <td>#<?= (int)$o['id'] ?></td>
        <td><?= e($o['full_name']) ?></td>
        <td><?= e($o['products']) ?></td>
        <td><?= money($o['total']) ?></td>
        <td><?= status_badge(...$sm) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
