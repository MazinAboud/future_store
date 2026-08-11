<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php'; // the inline stock-edit forms below call csrf_field()
require_once __DIR__ . '/../includes/audit.php';
require_role('admin');

$pdo = db();

// period filter drives the two time-series trend charts below (monthly
// sales + customer growth). Short ranges (7/30 days) bucket by day,
// longer ones bucket by month — both use the same $periodDays window.
$periodOptions = [
    '7d'  => ['label' => 'آخر 7 أيام',   'days' => 7],
    '30d' => ['label' => 'آخر 30 يومًا', 'days' => 30],
    '3m'  => ['label' => 'آخر 3 أشهر',   'days' => 90],
    '6m'  => ['label' => 'آخر 6 أشهر',   'days' => 180],
    '12m' => ['label' => 'آخر سنة',      'days' => 365],
];
$period = $_GET['period'] ?? '6m';
if (!isset($periodOptions[$period])) $period = '6m';
$periodDays = $periodOptions[$period]['days'];
$periodLabel = $periodOptions[$period]['label'];
$useDayBuckets = $periodDays <= 30;

// 1) Top 5 best-selling products
//
// "Sold" means DELIVERED, not "not yet cancelled". The previous rule counted
// every order still alive — including ones sitting in 'pending' that no employee
// has even looked at — so a basket someone abandoned inflated the ranking. On
// the current data that was 311 units reported against 224 actually delivered:
// a 28% overstatement, and it silently drives restocking decisions.
$bestSellers = $pdo->query("
    SELECT p.name, p.image_path, SUM(oi.quantity) AS sold
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    JOIN product_variants pv ON pv.id = oi.variant_id
    JOIN products p ON p.id = pv.product_id
    WHERE o.status = 'delivered'
    GROUP BY p.id ORDER BY sold DESC LIMIT 5
")->fetchAll();
$maxSold = $bestSellers ? max(array_column($bestSellers, 'sold')) : 1;

// 2) Sales trend, bucketed by day or month depending on the period filter
if ($useDayBuckets) {
    $monthlyStmt = $pdo->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m-%d') AS ym, SUM(total) AS total, COUNT(*) AS orders_count
        FROM orders
        WHERE status NOT IN ('rejected','cancelled') AND created_at >= CURDATE() - INTERVAL :days DAY
        GROUP BY ym ORDER BY ym
    ");
} else {
    $monthlyStmt = $pdo->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, SUM(total) AS total, COUNT(*) AS orders_count
        FROM orders
        WHERE status NOT IN ('rejected','cancelled') AND created_at >= CURDATE() - INTERVAL :days DAY
        GROUP BY ym ORDER BY ym
    ");
}
$monthlyStmt->execute(['days' => $periodDays]);
$monthly = $monthlyStmt->fetchAll();
$maxMonth = $monthly ? max(array_column($monthly, 'total')) : 1;

// Money actually in the till vs money merely promised.
//
// Everything above sums order totals, which for cash-on-delivery is what the
// store HOPES to collect, not what it HAS. Reporting that single number as
// "sales" overstated the current data by 37,458 (209,709 announced against
// 172,251 collected). Both figures are shown now, clearly labelled, so nobody
// plans against cash that is still sitting in a courier's pocket.
$collected = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status = 'paid'")->fetchColumn();
$grossValue = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status NOT IN ('rejected','cancelled')")->fetchColumn();
$pendingCod = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status = 'pending' AND status NOT IN ('rejected','cancelled')")->fetchColumn();
$lostValue  = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status IN ('rejected','cancelled')")->fetchColumn();

// 3) Customer growth, same bucketing rule as the sales trend
if ($useDayBuckets) {
    $custStmt = $pdo->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m-%d') AS ym, COUNT(*) AS c
        FROM users WHERE role = 'customer' AND created_at >= CURDATE() - INTERVAL :days DAY
        GROUP BY ym ORDER BY ym
    ");
} else {
    $custStmt = $pdo->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS c
        FROM users WHERE role = 'customer' AND created_at >= CURDATE() - INTERVAL :days DAY
        GROUP BY ym ORDER BY ym
    ");
}
$custStmt->execute(['days' => $periodDays]);
$custGrowth = $custStmt->fetchAll();
$maxCust = $custGrowth ? max(array_column($custGrowth, 'c')) : 1;

// 4) Low stock report — pv.id is selected so each row can carry an inline
// stock-edit form (posts to admin/product-action.php, action=update_stock)
$lowStock = $pdo->query("
    SELECT pv.id, p.name, pv.storage, pv.color, pv.stock_quantity, pv.sku
    FROM product_variants pv JOIN products p ON p.id = pv.product_id
    WHERE pv.stock_quantity <= " . LOW_STOCK_THRESHOLD . "
    ORDER BY pv.stock_quantity ASC
")->fetchAll();

// 4b) Full inventory — every variant, editable. The low-stock table above only
// shows what's already running out; this is where stock is corrected in general
// (after a delivery arrives, a stock count, a return, etc.).
$allStock = $pdo->query("
    SELECT pv.id, p.name, pv.storage, pv.color, pv.stock_quantity, pv.sku
    FROM product_variants pv JOIN products p ON p.id = pv.product_id
    ORDER BY p.name, pv.storage, pv.color
")->fetchAll();
$totalUnits = array_sum(array_column($allStock, 'stock_quantity'));

// 6) Top customers
//
// Two separate numbers, because they answer different questions: order_value is
// what the customer has committed to buying, paid_value is what the store has
// actually received from them. Ranking by the first alone would put a customer
// with three unpaid pending orders above one who has actually paid for two.
$topCustomers = $pdo->query("
    SELECT u.full_name, u.email, u.phone,
           COUNT(o.id) AS order_count,
           SUM(o.total) AS total_spent,
           SUM(CASE WHEN o.payment_status = 'paid' THEN o.total ELSE 0 END) AS paid_value
    FROM orders o JOIN users u ON u.id = o.user_id
    WHERE o.status NOT IN ('rejected','cancelled')
    GROUP BY u.id ORDER BY paid_value DESC, total_spent DESC LIMIT 10
")->fetchAll();

// 7) Orders by status — whole-system view
$orderStatusRows = $pdo->query("SELECT status, COUNT(*) c FROM orders GROUP BY status")->fetchAll();

// 8) Maintenance snapshot
$maintCounts = $pdo->query("SELECT status, COUNT(*) c FROM maintenance_requests GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

// 9) Team performance
$teamPerf = $pdo->query("
    SELECT u.full_name, u.role,
        (SELECT COUNT(*) FROM orders WHERE handled_by = u.id) AS orders_handled,
        (SELECT COUNT(*) FROM maintenance_requests WHERE handled_by = u.id) AS maint_handled
    FROM users u WHERE u.role IN ('admin','employee') ORDER BY orders_handled DESC
")->fetchAll();

$activeAdminPage = 'reports';
$pageTitle = 'التقارير';
require __DIR__ . '/../includes/admin-header.php';
?>

<div class="admin-topline">
  <h2>التقارير</h2>
  <a href="<?= BASE_URL ?>/admin/report-export.php?type=all&period=<?= e($period) ?>" class="btn btn-primary" target="_blank" rel="noopener">
    طباعة كل التقارير (PDF)
  </a>
</div>

<div class="admin-card" style="margin-bottom:18px">
  <h3>الملخص المالي</h3>
  <div class="hint">الدفع عند الاستلام يعني أن قيمة الطلب لا تساوي نقدًا في الصندوق. الرقمان مفصولان عمدًا.</div>
  <div class="stat-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:12px">
    <div class="admin-card" style="margin:0">
      <div style="font-size:12px;color:var(--text-muted)">مُحصَّل فعليًا (نقد مستلم)</div>
      <div style="font-size:22px;font-weight:800;color:#1a7f37"><?= money($collected) ?></div>
    </div>
    <div class="admin-card" style="margin:0">
      <div style="font-size:12px;color:var(--text-muted)">قيمة الطلبات القائمة</div>
      <div style="font-size:22px;font-weight:800"><?= money($grossValue) ?></div>
    </div>
    <div class="admin-card" style="margin:0">
      <div style="font-size:12px;color:var(--text-muted)">بانتظار التحصيل (COD)</div>
      <div style="font-size:22px;font-weight:800;color:#9a6700"><?= money($pendingCod) ?></div>
    </div>
    <div class="admin-card" style="margin:0">
      <div style="font-size:12px;color:var(--text-muted)">مرفوض / ملغى</div>
      <div style="font-size:22px;font-weight:800;color:var(--text-muted)"><?= money($lostValue) ?></div>
    </div>
  </div>
</div>

<div class="mini-tabs">
  <?php foreach ($periodOptions as $key => $opt): ?>
    <a href="?period=<?= $key ?>" class="<?= $period===$key?'active':'' ?>"><?= $opt['label'] ?></a>
  <?php endforeach; ?>
</div>

<div class="report-section-label"><?= kpi_icon('coins', 'amber') ?><h3>المبيعات والنمو</h3></div>

<div class="admin-card">
  <div class="admin-card-head"><h3>أفضل 5 منتجات مبيعًا</h3><a href="<?= BASE_URL ?>/admin/report-export.php?type=best_sellers" target="_blank" rel="noopener" class="btn btn-outline btn-sm">PDF</a></div>
  <?php if (empty($bestSellers)): ?><p class="text-muted">لا توجد بيانات مبيعات بعد.</p><?php else: ?>
    <?php foreach ($bestSellers as $b): ?>
      <div class="reorder-row">
        <span class="name"><?= e($b['name']) ?></span>
        <div style="flex:1;max-width:260px;background:var(--bg);border-radius:6px;overflow:hidden;height:10px">
          <div style="width:<?= max(4, round(($b['sold']/$maxSold)*100)) ?>%;background:var(--navy);height:100%"></div>
        </div>
        <span class="stat"><?= (int)$b['sold'] ?> قطعة مباعة</span>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<div class="admin-card">
  <div class="admin-card-head"><h3>تقرير المبيعات (<?= e($periodLabel) ?>)</h3><a href="<?= BASE_URL ?>/admin/report-export.php?type=monthly&period=<?= e($period) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm">PDF</a></div>
  <?php if (empty($monthly)): ?><p class="text-muted">لا توجد بيانات كافية بعد.</p><?php else: ?>
    <div class="bar-chart neutral" style="margin-top:10px">
      <?php foreach ($monthly as $m): $h = max(4, round(($m['total']/$maxMonth)*100)); ?>
        <div class="bar" style="height:<?= $h ?>%" title="<?= money($m['total']) ?>"><b><?= money($m['total']) ?></b><span><?= $useDayBuckets ? day_label_ar($m['ym']) : month_label_ar($m['ym']) ?></span></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="admin-card">
  <div class="admin-card-head"><h3>نمو قاعدة العملاء (<?= e($periodLabel) ?>)</h3><a href="<?= BASE_URL ?>/admin/report-export.php?type=customer_growth&period=<?= e($period) ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm">PDF</a></div>
  <?php if (empty($custGrowth)): ?><p class="text-muted">لا توجد بيانات تسجيل كافية بعد.</p><?php else: ?>
    <div class="bar-chart neutral" style="margin-top:10px">
      <?php foreach ($custGrowth as $c): $h = max(4, round(($c['c']/$maxCust)*100)); ?>
        <div class="bar" style="height:<?= $h ?>%" title="<?= (int)$c['c'] ?> عميل"><b><?= (int)$c['c'] ?></b><span><?= $useDayBuckets ? day_label_ar($c['ym']) : month_label_ar($c['ym']) ?></span></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="report-section-label"><?= kpi_icon('box', 'info') ?><h3>المخزون والطلب</h3></div>

<div class="admin-card">
  <div class="admin-card-head"><h3>تقرير المخزون المنخفض</h3><a href="<?= BASE_URL ?>/admin/report-export.php?type=low_stock" target="_blank" rel="noopener" class="btn btn-outline btn-sm">PDF</a></div>
  <div class="hint">نسخ بمخزون 5 قطع أو أقل — عدّل الكمية مباشرة من هنا بعد وصول شحنة جديدة.</div>
  <?php if (empty($lowStock)): ?><p class="text-muted"><?= icon_svg('check', 14, 2.6) ?> كل المخزون في مستوى جيد حاليًا</p><?php else: ?>
    <div class="table-wrap"><table class="simple-table">
      <tr><th>المنتج</th><th>السعة/اللون</th><th>SKU</th><th>المتبقي</th><th>تعديل الكمية</th></tr>
      <?php foreach ($lowStock as $l): ?>
        <tr>
          <td><?= e($l['name']) ?></td>
          <td><?= e($l['storage']) ?> · <?= e($l['color']) ?></td>
          <td class="text-muted"><?= e($l['sku']) ?></td>
          <td class="<?= $l['stock_quantity']==0?'text-danger':'' ?>" style="font-weight:800"><?= (int)$l['stock_quantity'] ?></td>
          <td>
            <form method="post" action="<?= BASE_URL ?>/admin/product-action.php" class="stock-edit">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="update_stock">
              <input type="hidden" name="variant_id" value="<?= (int)$l['id'] ?>">
              <input type="number" name="stock_quantity" value="<?= (int)$l['stock_quantity'] ?>" min="0" max="999999" required aria-label="الكمية الجديدة">
              <button type="submit" class="btn btn-sm btn-outline">حفظ</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </table></div>
  <?php endif; ?>
</div>

<div class="admin-card">
  <div class="admin-card-head"><h3>جرد المخزون الكامل</h3></div>
  <div class="hint">كل نسخ المنتجات وكمياتها الحالية — إجمالي <b style="color:var(--text)"><?= (int)$totalUnits ?></b> قطعة في <?= count($allStock) ?> نسخة. عدّل أي كمية واضغط حفظ.</div>
  <?php if (empty($allStock)): ?><p class="text-muted">لا توجد نسخ منتجات بعد.</p><?php else: ?>
    <details class="pw-reset" style="display:block;margin-top:6px">
      <summary class="btn btn-outline btn-sm">عرض وتعديل كل المخزون</summary>
      <div class="table-wrap" style="margin-top:12px"><table class="simple-table">
        <tr><th>المنتج</th><th>السعة/اللون</th><th>SKU</th><th>الكمية</th></tr>
        <?php foreach ($allStock as $s): ?>
          <tr>
            <td><?= e($s['name']) ?></td>
            <td><?= e($s['storage']) ?> · <?= e($s['color']) ?></td>
            <td class="text-muted"><?= e($s['sku']) ?></td>
            <td>
              <form method="post" action="<?= BASE_URL ?>/admin/product-action.php" class="stock-edit">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_stock">
                <input type="hidden" name="variant_id" value="<?= (int)$s['id'] ?>">
                <input type="number" name="stock_quantity" value="<?= (int)$s['stock_quantity'] ?>" min="0" max="999999" required aria-label="الكمية الجديدة">
                <button type="submit" class="btn btn-sm btn-outline">حفظ</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </table></div>
    </details>
  <?php endif; ?>
</div>


<div class="report-section-label"><?= kpi_icon('users', 'success') ?><h3>العملاء</h3></div>

<div class="admin-card">
  <div class="admin-card-head"><h3>أفضل العملاء</h3><a href="<?= BASE_URL ?>/admin/report-export.php?type=top_customers" target="_blank" rel="noopener" class="btn btn-outline btn-sm">PDF</a></div>
  <?php if (empty($topCustomers)): ?><p class="text-muted">لا توجد بيانات عملاء كافية بعد.</p><?php else: ?>
    <div class="table-wrap"><table class="simple-table">
      <tr><th>العميل</th><th>البريد الإلكتروني</th><th>عدد الطلبات</th></tr>
      <?php foreach ($topCustomers as $c): ?>
        <tr><td style="font-weight:700"><?= e($c['full_name']) ?></td><td class="text-muted"><?= e($c['email']) ?></td><td><?= (int)$c['order_count'] ?></td></tr>
      <?php endforeach; ?>
    </table></div>
  <?php endif; ?>
</div>

<div class="report-section-label"><?= kpi_icon('wrench', 'warning') ?><h3>العمليات والفريق</h3></div>

<div class="admin-card">
  <div class="admin-card-head"><h3>الطلبات حسب الحالة</h3><a href="<?= BASE_URL ?>/admin/report-export.php?type=order_status" target="_blank" rel="noopener" class="btn btn-outline btn-sm">PDF</a></div>
  <?php if (empty($orderStatusRows)): ?><p class="text-muted">لا توجد طلبات بعد.</p><?php else: ?>
    <ul class="dot-list">
      <?php foreach ($orderStatusRows as $s): $meta = order_status_meta($s['status']); ?>
        <li><span class="dot <?= e(status_tone($s['status'])) ?>"></span><?= e($meta['label']) ?><b><?= (int)$s['c'] ?> طلب</b></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<div class="admin-card">
  <div class="admin-card-head"><h3>تقرير الصيانة والضمان</h3><a href="<?= BASE_URL ?>/admin/report-export.php?type=maintenance" target="_blank" rel="noopener" class="btn btn-outline btn-sm">PDF</a></div>
  <ul class="dot-list">
    <?php foreach (['new' => ['جديد', 'warning'], 'in_progress' => ['قيد المعالجة', 'info'], 'resolved' => ['تم الحل', 'success']] as $k => [$label, $tone]): ?>
      <li><span class="dot <?= $tone ?>"></span><?= $label ?><b><?= (int)($maintCounts[$k] ?? 0) ?></b></li>
    <?php endforeach; ?>
  </ul>
</div>

<div class="admin-card">
  <div class="admin-card-head"><h3>أداء فريق العمل</h3><a href="<?= BASE_URL ?>/admin/report-export.php?type=employee_performance" target="_blank" rel="noopener" class="btn btn-outline btn-sm">PDF</a></div>
  <div class="hint">"طلبات تمت معالجتها" تعني كل طلب قبله أو رفضه أو حدّث حالته هذا الحساب فعليًا — وهو غير عدد الطلبات الموزّعة عليه تلقائيًا (شاهد "المسؤول" في صفحة الطلبات).</div>
  <?php if (empty($teamPerf)): ?><p class="text-muted">لا يوجد فريق عمل مسجّل بعد.</p><?php else: ?>
    <ul class="dot-list">
      <?php foreach ($teamPerf as $t): ?>
        <li>
          <span class="dot <?= $t['role'] === 'admin' ? 'info' : 'success' ?>"></span>
          <?= e($t['full_name']) ?> <span class="text-muted" style="font-size:12px"><?= $t['role'] === 'admin' ? 'أدمن' : 'موظف' ?></span>
          <b><?= (int)$t['orders_handled'] ?> طلب · <?= (int)$t['maint_handled'] ?> صيانة</b>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<div class="admin-card">
  <div class="admin-card-head"><h3>سجل العمليات الإدارية</h3></div>
  <div class="hint">كل عملية تغيّر المنتجات أو المخزون أو الحسابات تُسجَّل هنا. السجل <b>إضافة فقط</b> — لا يُعدَّل ولا يُحذف من أي مكان في التطبيق، لأنه الدليل الوحيد عند أي خلاف. حذف حساب يمحو طلباته وتقييماته، وهذا السطر هو ما يبقى منه.</div>
  <?php $auditRows = admin_events_recent(50); ?>
  <?php if (empty($auditRows)): ?>
    <p class="text-muted">لا توجد عمليات مسجّلة بعد.</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="simple-table">
        <tr><th>الوقت</th><th>المنفِّذ</th><th>العملية</th><th>التفاصيل</th><th>IP</th></tr>
        <?php foreach ($auditRows as $ev): ?>
          <tr>
            <td style="white-space:nowrap"><?= e($ev['created_at']) ?></td>
            <td><?= e($ev['actor_name'] ?? '—') ?><?php if ($ev['actor_role']): ?>
              <span class="text-muted" style="font-size:12px"><?= $ev['actor_role'] === 'admin' ? 'أدمن' : 'موظف' ?></span>
            <?php endif; ?></td>
            <td><?= e(admin_action_label($ev['action'])) ?></td>
            <td><?= e($ev['summary'] ?? '') ?></td>
            <td class="text-muted" style="font-size:12px"><?= e($ev['ip'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
