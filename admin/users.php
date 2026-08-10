<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_role('admin');

$tab = $_GET['tab'] ?? 'customers';
if (!in_array($tab, ['customers', 'employees'])) $tab = 'customers';
$role = $tab === 'customers' ? 'customer' : 'employee';

$stmt = db()->prepare("
    SELECT u.*, (SELECT COUNT(*) FROM orders WHERE user_id = u.id) AS order_count
    FROM users u WHERE u.role = ? ORDER BY u.created_at DESC
");
$stmt->execute([$role]);
$users = $stmt->fetchAll();

$counts = db()->query("SELECT role, COUNT(*) c FROM users WHERE role IN ('customer','employee') GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);

$activeAdminPage = 'users';
$pageTitle = 'المستخدمون';
require __DIR__ . '/../includes/admin-header.php';
?>

<div class="admin-topline"><h2>المستخدمون</h2></div>

<div class="mini-tabs">
  <a href="?tab=customers" class="<?= $tab==='customers'?'active':'' ?>">العملاء (<?= (int)($counts['customer'] ?? 0) ?>)</a>
  <a href="?tab=employees" class="<?= $tab==='employees'?'active':'' ?>">الموظفون (<?= (int)($counts['employee'] ?? 0) ?>)</a>
</div>

<?php if ($tab === 'employees'): ?>
<div class="admin-card">
  <h3>إنشاء حساب موظف جديد</h3>
  <form method="post" action="<?= BASE_URL ?>/admin/user-action.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_employee">
    <div class="form-row">
      <div class="form-group"><label>الاسم الكامل</label><input type="text" name="full_name" required></div>
      <div class="form-group"><label>البريد الإلكتروني</label><input type="email" name="email" required></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>رقم الموبايل</label><input type="tel" name="phone" placeholder="09xxxxxxxx" required></div>
      <div class="form-group"><label>كلمة سر مؤقتة</label><input type="text" name="password" required minlength="8"></div>
    </div>
    <button type="submit" class="btn btn-primary">إنشاء الحساب</button>
  </form>
</div>
<?php endif; ?>

<div class="table-wrap">
  <table class="simple-table">
    <tr><th>الاسم</th><th>البريد الإلكتروني</th><th>الموبايل</th><th><?= $tab==='customers'?'عدد الطلبات':'العنوان' ?></th><th>الحالة</th><th></th></tr>
    <?php foreach ($users as $u): ?>
      <tr>
        <td style="font-weight:700"><?= e($u['full_name']) ?></td>
        <td><?= e($u['email']) ?></td>
        <td><?= e($u['phone']) ?></td>
        <td><?= $tab==='customers' ? (int)$u['order_count'] : e($u['address']) ?></td>
        <td><?= $u['is_active'] ? status_badge('نشط', 'delivered', 'check') : status_badge('موقوف', 'rejected', 'x') ?></td>
        <td style="white-space:nowrap">
          <form method="post" action="<?= BASE_URL ?>/admin/user-action.php" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <input type="hidden" name="action" value="toggle_active">
            <button type="submit" class="btn btn-sm btn-ghost"><?= $u['is_active'] ? 'إيقاف' : 'تفعيل' ?></button>
          </form>
          <details class="pw-reset">
            <summary class="btn btn-sm btn-outline">تغيير كلمة السر</summary>
            <form method="post" action="<?= BASE_URL ?>/admin/user-action.php" class="pw-reset-form">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <input type="hidden" name="tab" value="<?= e($tab) ?>">
              <input type="hidden" name="action" value="reset_password">
              <input type="password" name="new_password" placeholder="كلمة سر جديدة" required minlength="8">
              <button type="submit" class="btn btn-sm btn-primary">حفظ</button>
            </form>
          </details>
          <details class="pw-reset">
            <summary class="btn btn-sm btn-outline">تعديل</summary>
            <form method="post" action="<?= BASE_URL ?>/admin/user-action.php" class="pw-reset-form stack">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
              <input type="hidden" name="tab" value="<?= e($tab) ?>">
              <input type="hidden" name="action" value="edit_user">
              <label>الاسم الكامل</label>
              <input type="text" name="full_name" value="<?= e($u['full_name']) ?>" required minlength="3" maxlength="120">
              <label>البريد الإلكتروني</label>
              <input type="email" name="email" value="<?= e($u['email']) ?>" required maxlength="150">
              <label>رقم الموبايل</label>
              <input type="tel" name="phone" value="<?= e($u['phone']) ?>" maxlength="30">
              <label>العنوان</label>
              <input type="text" name="address" value="<?= e($u['address']) ?>" maxlength="255">
              <button type="submit" class="btn btn-sm btn-primary">حفظ التعديلات</button>
            </form>
          </details>
          <form method="post" action="<?= BASE_URL ?>/admin/user-action.php" style="display:inline"
                onsubmit="return confirm('سيتم حذف هذا الحساب وكل بياناته المرتبطة (الطلبات، التقييمات، طلبات الصيانة) نهائيًا من قاعدة البيانات. لا يمكن التراجع. متأكد؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <input type="hidden" name="action" value="delete_user">
            <button type="submit" class="btn btn-sm btn-danger">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
