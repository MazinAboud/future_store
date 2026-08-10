<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_any_role(['admin', 'employee']);

$me = current_user();

$status = $_GET['status'] ?? '';
$q = trim($_GET['q'] ?? '');

$where = [];
$params = [];

// An employee sees only the orders routed to them (plus any still-unassigned),
// matching the "your work only" scope the nav badge already promises and the
// ownership gate admin/order-action.php now enforces. Admins see everything.
// Listing every customer's order to every employee both leaks other customers'
// details and invites acting on work that isn't theirs.
if ($me['role'] === 'employee') {
    $where[] = '(o.assigned_to = ? OR o.assigned_to IS NULL)';
    $params[] = $me['id'];
}

if ($status !== '') { $where[] = 'o.status = ?'; $params[] = $status; }
if ($q !== '') {
    $where[] = '(u.full_name LIKE ? OR o.id = ?)';
    $params[] = "%$q%"; $params[] = is_numeric($q) ? (int)$q : 0;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = db()->prepare("
    SELECT o.*, u.full_name AS customer_name, e.full_name AS assignee_name FROM orders o
    JOIN users u ON u.id = o.user_id
    LEFT JOIN users e ON e.id = o.assigned_to
    $whereSql ORDER BY o.created_at DESC LIMIT 200
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

$statusLabels = ['pending'=>'قيد الانتظار','confirmed'=>'مؤكد','processing'=>'قيد المعالجة','shipped'=>'تم الشحن','delivered'=>'تم التسليم','rejected'=>'مرفوض','cancelled'=>'ملغي'];

$activeAdminPage = 'orders';
$pageTitle = 'إدارة الطلبات';
require __DIR__ . '/../includes/admin-header.php';
?>

<div class="admin-topline"><h2>إدارة الطلبات (<?= count($orders) ?>)</h2></div>

<div class="toolbar">
  <form method="get" class="search-inline">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="رقم الطلب أو اسم العميل...">
    <select name="status" class="input" onchange="this.form.submit()">
      <option value="">كل الحالات</option>
      <?php foreach ($statusLabels as $k=>$l): ?><option value="<?= $k ?>" <?= $status===$k?'selected':'' ?>><?= $l ?></option><?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-outline btn-sm">بحث</button>
  </form>
</div>

<div class="table-wrap">
  <table class="simple-table">
    <tr><th>رقم</th><th>العميل</th><th>المسؤول</th><th>الإجمالي</th><th>الحالة</th><th>السداد</th><th>التاريخ</th><th>تحديث</th></tr>
    <?php foreach ($orders as $o): $pm = payment_status_meta($o['payment_status']); $sm = order_status_meta($o['status']); ?>
      <tr>
        <td>#<?= (int)$o['id'] ?></td>
        <td><?= e($o['customer_name']) ?></td>
        <td class="text-muted"><?= $o['assignee_name'] ? e($o['assignee_name']) : '—' ?></td>
        <td><?= money($o['total']) ?></td>
        <td><?= status_badge(...$sm) ?></td>
        <td>
          <?php if ($o['payment_status']==='paid'): ?><?= status_badge('تم السداد', 'delivered', 'check') ?>
          <?php else: ?>
            <form method="post" action="<?= BASE_URL ?>/admin/order-action.php" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="action" value="confirm_payment">
              <button type="submit" class="pay-btn">تأكيد السداد</button>
            </form>
          <?php endif; ?>
        </td>
        <td class="text-muted"><?= date('Y-m-d', strtotime($o['created_at'])) ?></td>
        <td>
          <form method="post" action="<?= BASE_URL ?>/admin/order-action.php" style="display:flex;gap:6px">
            <?= csrf_field() ?>
            <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
            <input type="hidden" name="action" value="set_status">
            <select name="status" class="mini-select">
              <?php foreach ($statusLabels as $k=>$l): ?><option value="<?= $k ?>" <?= $o['status']===$k?'selected':'' ?>><?= $l ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-outline">حفظ</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
