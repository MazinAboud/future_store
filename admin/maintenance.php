<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_any_role(['admin', 'employee']);

$me = current_user();
$isAdmin = has_role('admin');

$status = $_GET['status'] ?? '';
$conds = [];
$params = [];
if ($status) { $conds[] = 'mr.status = ?'; $params[] = $status; }

// Requests rotate across employees one at a time, so an employee sees only the
// ones routed to them (plus any still unassigned, as a safety net). Admins keep
// the full oversight view — same rule the orders queue uses.
if (!$isAdmin) {
    $conds[] = '(mr.assigned_to = ? OR mr.assigned_to IS NULL)';
    $params[] = $me['id'];
}
$where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';

$stmt = db()->prepare("
    SELECT mr.*, u.full_name AS customer_name, oi.product_name, oi.variant_desc,
           e.full_name AS assignee_name
    FROM maintenance_requests mr
    JOIN users u ON u.id = mr.user_id
    JOIN order_items oi ON oi.id = mr.order_item_id
    LEFT JOIN users e ON e.id = mr.assigned_to
    $where ORDER BY mr.created_at DESC
");
$stmt->execute($params);
$requests = $stmt->fetchAll();

// the tab counters must respect the same scope, or an employee sees "5 جديد"
// and then a list with two rows in it
if ($isAdmin) {
    $counts = db()->query("SELECT status, COUNT(*) c FROM maintenance_requests GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
} else {
    $cStmt = db()->prepare("SELECT status, COUNT(*) c FROM maintenance_requests WHERE assigned_to = ? OR assigned_to IS NULL GROUP BY status");
    $cStmt->execute([$me['id']]);
    $counts = $cStmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

$activeAdminPage = 'maintenance';
$pageTitle = 'الصيانة';
require __DIR__ . '/../includes/admin-header.php';
?>

<div class="admin-topline"><h2>طلبات الصيانة والضمان</h2></div>

<div class="mini-tabs">
  <a href="?status=" class="<?= $status===''?'active':'' ?>">الكل (<?= array_sum($counts) ?>)</a>
  <a href="?status=new" class="<?= $status==='new'?'active':'' ?>">جديد (<?= (int)($counts['new'] ?? 0) ?>)</a>
  <a href="?status=in_progress" class="<?= $status==='in_progress'?'active':'' ?>">قيد المعالجة (<?= (int)($counts['in_progress'] ?? 0) ?>)</a>
  <a href="?status=resolved" class="<?= $status==='resolved'?'active':'' ?>">تم الحل (<?= (int)($counts['resolved'] ?? 0) ?>)</a>
</div>

<div class="table-wrap">
  <table class="simple-table">
    <tr><th>العميل</th><th>الجهاز</th><th>وصف العطل</th><?php if ($isAdmin): ?><th>المسؤول</th><?php endif; ?><th>التاريخ</th><th>الحالة</th><th>تحديث</th></tr>
    <?php if (empty($requests)): ?><tr><td colspan="<?= $isAdmin ? 7 : 6 ?>" style="text-align:center;color:var(--text-muted)">لا توجد طلبات</td></tr><?php endif; ?>
    <?php foreach ($requests as $m): $sm = maintenance_status_meta($m['status']); ?>
      <tr>
        <td style="font-weight:700"><?= e($m['customer_name']) ?></td>
        <td><?= e($m['product_name']) ?> <span class="text-muted">(<?= e($m['variant_desc']) ?>)</span></td>
        <td><?= e($m['issue']) ?></td>
        <?php if ($isAdmin): ?><td><?= $m['assignee_name'] ? e($m['assignee_name']) : '—' ?></td><?php endif; ?>
        <td class="text-muted"><?= date('Y-m-d', strtotime($m['created_at'])) ?></td>
        <td><?= status_badge(...$sm) ?></td>
        <td>
          <form method="post" action="<?= BASE_URL ?>/admin/maintenance-action.php" style="display:flex;gap:6px">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <select name="status" class="mini-select">
              <option value="new" <?= $m['status']==='new'?'selected':'' ?>>جديد</option>
              <option value="in_progress" <?= $m['status']==='in_progress'?'selected':'' ?>>قيد المعالجة</option>
              <option value="resolved" <?= $m['status']==='resolved'?'selected':'' ?>>تم الحل</option>
            </select>
            <button type="submit" class="btn btn-sm btn-outline">حفظ</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
