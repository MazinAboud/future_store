<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

require_role('customer');
$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $itemId = (int)($_POST['order_item_id'] ?? 0);
    $issue  = trim($_POST['issue'] ?? '');

    // verify this order_item actually belongs to this customer and was delivered
    $chk = db()->prepare("SELECT oi.id FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.id = ? AND o.user_id = ? AND o.status = 'delivered'");
    $chk->execute([$itemId, $me['id']]);

    if (!$chk->fetchColumn()) {
        flash_set('error', 'الجهاز المحدد غير صحيح.');
    } elseif (mb_strlen($issue) < 5) {
        flash_set('error', 'من فضلك اكتب وصفًا واضحًا للعطل.');
    } else {
        // routed to one employee at a time, same rotation rule as orders
        db()->prepare("INSERT INTO maintenance_requests (user_id, order_item_id, issue, assigned_to) VALUES (?,?,?,?)")
            ->execute([$me['id'], $itemId, $issue, assign_next_employee('maintenance')]);
        flash_set('success', 'تم استلام طلب الصيانة، هيتواصل معاك فريقنا قريبًا.');
    }
    redirect('/account/maintenance.php');
}

// devices eligible for a maintenance request (delivered order items)
$deviceStmt = db()->prepare("
    SELECT oi.id, oi.product_name, oi.variant_desc FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    WHERE o.user_id = ? AND o.status = 'delivered' ORDER BY o.delivered_at DESC
");
$deviceStmt->execute([$me['id']]);
$devices = $deviceStmt->fetchAll();

$reqStmt = db()->prepare("
    SELECT mr.*, oi.product_name, oi.variant_desc FROM maintenance_requests mr
    JOIN order_items oi ON oi.id = mr.order_item_id
    WHERE mr.user_id = ? ORDER BY mr.created_at DESC
");
$reqStmt->execute([$me['id']]);
$requests = $reqStmt->fetchAll();

$openId = (int)($_GET['open'] ?? 0);

$activePage = 'maintenance';
$pageTitle = 'طلبات الصيانة';
require __DIR__ . '/../includes/header.php';
?>
<div class="account-layout">
  <?php require __DIR__ . '/../includes/account-nav.php'; ?>
  <div class="account-main">
    <h2 style="font-size:19px;margin-bottom:18px">طلبات الصيانة والضمان</h2>

    <?php if (empty($devices)): ?>
      <div class="empty-state"><h3>محتاج تشتري جهاز الأول</h3><p>طلبات الصيانة متاحة فقط للأجهزة اللي وصلتك بالفعل.</p></div>
    <?php else: ?>
      <div class="admin-card" style="margin-bottom:24px">
        <h3>فتح طلب صيانة جديد</h3>
        <form method="post">
          <?= csrf_field() ?>
          <div class="form-group">
            <label>اختر الجهاز</label>
            <select name="order_item_id" class="input" required>
              <?php foreach ($devices as $d): ?>
                <option value="<?= (int)$d['id'] ?>" <?= $openId === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['product_name']) ?> — <?= e($d['variant_desc']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>وصف العطل</label>
            <textarea name="issue" required placeholder="مثال: البطارية تنفد بسرعة غير طبيعية"></textarea>
          </div>
          <button type="submit" class="btn btn-primary">إرسال الطلب</button>
        </form>
      </div>
    <?php endif; ?>

    <h3 style="font-size:15px;margin-bottom:12px">طلباتي السابقة</h3>
    <?php if (empty($requests)): ?>
      <p style="color:var(--text-muted);font-size:13.5px">لا توجد طلبات صيانة بعد.</p>
    <?php else: foreach ($requests as $r): $sm = maintenance_status_meta($r['status']); ?>
      <div class="maint-card">
        <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
          <div>
            <div style="font-weight:800;font-size:13.5px"><?= e($r['product_name']) ?> — <?= e($r['variant_desc']) ?></div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:3px"><?= time_ago($r['created_at']) ?></div>
          </div>
          <?= status_badge(...$sm) ?>
        </div>
        <p style="font-size:13px;margin-top:10px"><?= e($r['issue']) ?></p>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
