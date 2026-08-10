<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('customer');
$me = current_user();

$stmt = db()->prepare("SELECT id, created_at, payment_method, payment_status, total, status FROM orders WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$me['id']]);
$orders = $stmt->fetchAll();

$activePage = 'payments';
$pageTitle = 'السداد والفواتير';
require __DIR__ . '/../includes/header.php';
?>
<div class="account-layout">
  <?php require __DIR__ . '/../includes/account-nav.php'; ?>
  <div class="account-main">
    <h2 style="font-size:19px;margin-bottom:18px">السداد والفواتير</h2>
    <?php if (empty($orders)): ?>
      <div class="empty-state"><h3>لا توجد فواتير بعد</h3></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="simple-table">
          <tr><th>رقم الطلب</th><th>التاريخ</th><th>طريقة الدفع</th><th>المبلغ</th><th>حالة السداد</th></tr>
          <?php foreach ($orders as $o): $pm = payment_status_meta($o['payment_status']); ?>
            <tr>
              <td>#<?= (int)$o['id'] ?></td>
              <td><?= date('Y-m-d', strtotime($o['created_at'])) ?></td>
              <td>كاش عند الاستلام</td>
              <td><?= money($o['total']) ?></td>
              <td><?= status_badge(...$pm) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
      <p style="font-size:12px;color:var(--text-muted);margin-top:14px">الدفع في Future Store كاش عند الاستلام فقط. يتم تأكيد السداد من فريقنا بعد تسليم الطلب.</p>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
