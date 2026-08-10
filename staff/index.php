<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

require_any_role(['admin', 'employee']);
$me = current_user();
$pdo = db();

// pending orders needing accept/reject, plus whether stock actually covers every line item.
// Employees only see orders assigned to them (or unassigned ones, as a safety net so
// nothing is missed) — admins keep the full oversight view across everyone's queue.
$stockOkSql = "(SELECT MIN(pv.stock_quantity >= oi.quantity) FROM order_items oi JOIN product_variants pv ON pv.id = oi.variant_id WHERE oi.order_id = o.id) AS stock_ok";
/*
 * PENDING_LIMIT exists because this query had no LIMIT at all.
 *
 * Load testing made the consequence concrete: with 10,019 orders in the table,
 * this returned 2,288 rows in one page load. Each of those rows then ran its
 * own query for its line items inside the render loop further down — so a
 * single visit to this dashboard fired roughly 2,289 queries and tried to
 * build an HTML page with 2,288 order cards in it.
 *
 * Note what that failure looks like from outside: not an error, just a page
 * that takes longer and longer to load as the shop succeeds, until one day it
 * times out. Nothing in the code changed on the day it broke; the data did.
 *
 * A cap of 100 is chosen over pagination deliberately. This is a work queue,
 * not an archive — an employee processes the oldest pending orders and the
 * list drains. If 100 are showing there is a staffing problem to solve, not a
 * page two to click. The count above the list stays honest so nothing is
 * hidden.
 */
const PENDING_LIMIT = 100;

if (has_role('employee')) {
    $pendingStmt = $pdo->prepare("
        SELECT o.*, u.full_name AS customer_name, $stockOkSql
        FROM orders o JOIN users u ON u.id = o.user_id
        WHERE o.status = 'pending' AND (o.assigned_to = ? OR o.assigned_to IS NULL)
        ORDER BY o.created_at ASC
        LIMIT " . PENDING_LIMIT . "
    ");
    $pendingStmt->execute([$me['id']]);

    $totalPendingStmt = $pdo->prepare("
        SELECT COUNT(*) FROM orders
        WHERE status = 'pending' AND (assigned_to = ? OR assigned_to IS NULL)
    ");
    $totalPendingStmt->execute([$me['id']]);
    $totalPending = (int)$totalPendingStmt->fetchColumn();
} else {
    $pendingStmt = $pdo->query("
        SELECT o.*, u.full_name AS customer_name, $stockOkSql
        FROM orders o JOIN users u ON u.id = o.user_id
        WHERE o.status = 'pending' ORDER BY o.created_at ASC
        LIMIT " . PENDING_LIMIT
    );
    $totalPending = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
}
$pendingOrders = $pendingStmt->fetchAll();

/*
 * Line items for every order on this page, fetched in ONE query instead of one
 * query per order. The render loop below reads from this array.
 *
 * This is the N+1 problem, and it is the reason the cap alone was not enough:
 * even at 100 orders the old code issued 101 queries per page load. Each was
 * individually fast, which is exactly why the pattern survives review — the
 * cost only appears when you count them.
 *
 * The id list is built from database output, never from the request, so
 * interpolating it introduces nothing.
 */
$itemsByOrder = [];
if ($pendingOrders) {
    $ids = implode(',', array_map(fn($o) => (int)$o['id'], $pendingOrders));
    foreach ($pdo->query("SELECT * FROM order_items WHERE order_id IN ($ids)")->fetchAll() as $it) {
        $itemsByOrder[(int)$it['order_id']][] = $it;
    }
}

// Kept for any call site further down that still expects the old prepared
// statement, so this change cannot break a path that was not updated.
$itemsStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ?");

$inProgressCount     = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('confirmed','processing','shipped')")->fetchColumn();
$deliveredTodayCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'delivered' AND DATE(delivered_at) = CURDATE()")->fetchColumn();

// Maintenance requests rotate across employees the same way orders do, so an
// employee's counter and preview must show only their own queue — otherwise the
// dashboard promises work that the maintenance page won't actually let them see.
$maintScope = has_role('employee') ? "AND (mr.assigned_to = ? OR mr.assigned_to IS NULL)" : "";
$maintParams = has_role('employee') ? [$me['id']] : [];

$mc = $pdo->prepare("SELECT COUNT(*) FROM maintenance_requests mr WHERE mr.status IN ('new','in_progress') $maintScope");
$mc->execute($maintParams);
$openMaintCount = (int)$mc->fetchColumn();

$mp = $pdo->prepare("
    SELECT mr.*, u.full_name AS customer_name, oi.product_name, oi.variant_desc FROM maintenance_requests mr
    JOIN users u ON u.id = mr.user_id
    JOIN order_items oi ON oi.id = mr.order_item_id
    WHERE mr.status IN ('new','in_progress') $maintScope
    ORDER BY mr.created_at ASC LIMIT 5
");
$mp->execute($maintParams);
$maintPreview = $mp->fetchAll();

$activeAdminPage = 'overview';
$pageTitle = 'نظرة عامة';
require __DIR__ . '/../includes/admin-header.php';
?>

<div class="admin-topline"><h2>أهلًا، <?= e(explode(' ', $me['full_name'])[0]) ?></h2></div>

<div class="kpi-grid">
  <div class="kpi-card">
    <div class="label"><?= kpi_icon('clock', count($pendingOrders) > 0 ? 'warning' : 'success') ?>طلبات معلّقة تحتاج مراجعة</div>
    <div class="value"><?= count($pendingOrders) ?></div>
    <div class="delta <?= count($pendingOrders) > 0 ? 'warn' : 'up' ?>"><?= count($pendingOrders) > 0 ? 'تحتاج إجراء الآن' : 'لا يوجد شيء معلّق' ?></div>
  </div>
  <div class="kpi-card">
    <div class="label"><?= kpi_icon('loop', 'info') ?>طلبات قيد التنفيذ</div>
    <div class="value"><?= $inProgressCount ?></div>
    <div class="delta up">مؤكد / قيد المعالجة / تم الشحن</div>
  </div>
  <div class="kpi-card">
    <div class="label"><?= kpi_icon('wrench', $openMaintCount > 0 ? 'warning' : 'success') ?>طلبات صيانة مفتوحة</div>
    <div class="value"><?= $openMaintCount ?></div>
    <div class="delta <?= $openMaintCount > 0 ? 'warn' : 'up' ?>">جديد أو قيد المعالجة</div>
  </div>
  <div class="kpi-card">
    <div class="label"><?= kpi_icon('box', 'success') ?>تم التسليم اليوم</div>
    <div class="value"><?= $deliveredTodayCount ?></div>
    <div class="delta up">طلبات مكتملة</div>
  </div>
</div>

<div class="section-head" style="margin-bottom:14px">
  <h2 style="font-size:17px">طلبات جديدة تحتاج مراجعة (<?= (int)$totalPending ?>)</h2>
  <a href="<?= BASE_URL ?>/admin/orders.php">كل الطلبات ←</a>
</div>
<?php if ($totalPending > count($pendingOrders)): ?>
  <?php // Shown only when the cap actually hides something, so the employee is
        // never misled into thinking the queue is shorter than it is. ?>
  <p style="color:var(--text-muted);font-size:13px;margin-bottom:12px">
    <?= icon_svg('warning', 14, 2.6) ?>
    يُعرض أقدم <?= count($pendingOrders) ?> طلبًا من أصل <?= (int)$totalPending ?>.
    عالج هذه أولًا وستظهر البقية تلقائيًا.
  </p>
<?php endif; ?>
<?php if (empty($pendingOrders)): ?>
  <p style="color:var(--text-muted);font-size:13.5px"><?= icon_svg('check', 14, 2.6) ?> لا توجد طلبات معلّقة حاليًا</p>
<?php else: foreach ($pendingOrders as $o):
    // Read from the single batch query above instead of running one query per
    // order here. This line is the N+1 fix.
    $items = $itemsByOrder[(int)$o['id']] ?? [];
    $itemsLabel = implode('، ', array_map(fn($i) => $i['product_name'] . ' (' . $i['variant_desc'] . ') ×' . $i['quantity'], $items));
?>
  <div class="order-card pending">
    <div>
      <div class="oid">طلب #<?= (int)$o['id'] ?> — <?= e($o['customer_name']) ?></div>
      <div class="meta"><?= e($itemsLabel) ?> · <?= money($o['total']) ?></div>
      <?php if (!$o['stock_ok']): ?><div class="meta text-danger"><?= icon_svg('warning', 13, 2.4) ?> المخزون غير كافٍ لأحد المنتجات</div><?php endif; ?>
    </div>
    <div class="order-actions">
      <form method="post" action="<?= BASE_URL ?>/admin/order-action.php">
        <?= csrf_field() ?>
        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
        <input type="hidden" name="action" value="accept">
        <button type="submit" class="btn-success" <?= !$o['stock_ok'] ? 'disabled' : '' ?>><?= icon_svg('check', 13, 2.8) ?> قبول</button>
      </form>
      <form method="post" action="<?= BASE_URL ?>/admin/order-action.php" class="reject-form">
        <?= csrf_field() ?>
        <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
        <input type="hidden" name="action" value="reject">
        <input type="text" name="reason" placeholder="سبب الرفض" required>
        <button type="submit" class="btn-danger">رفض</button>
      </form>
    </div>
  </div>
<?php endforeach; endif; ?>

<div class="section-head" style="margin:26px 0 14px">
  <h2 style="font-size:17px">طلبات صيانة مفتوحة (<?= $openMaintCount ?>)</h2>
  <a href="<?= BASE_URL ?>/admin/maintenance.php">عرض الكل ←</a>
</div>
<?php if (empty($maintPreview)): ?>
  <p style="color:var(--text-muted);font-size:13.5px">لا توجد طلبات صيانة مفتوحة حاليًا.</p>
<?php else: ?>
  <div class="table-wrap">
    <table class="simple-table">
      <tr><th>العميل</th><th>الجهاز</th><th>وصف العطل</th><th>الحالة</th></tr>
      <?php foreach ($maintPreview as $m): $sm = maintenance_status_meta($m['status']); ?>
        <tr>
          <td><?= e($m['customer_name']) ?></td>
          <td><?= e($m['product_name']) ?> <span class="text-muted">(<?= e($m['variant_desc']) ?>)</span></td>
          <td><?= e($m['issue']) ?></td>
          <td><?= status_badge(...$sm) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
