<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_any_role(['admin', 'employee']);

$me = current_user();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'update_info') {
        $fullName = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if (mb_strlen($fullName) < 3) $errors['full_name'] = 'أدخل الاسم الكامل.';
        if (empty($errors)) {
            db()->prepare("UPDATE users SET full_name=?, phone=? WHERE id=?")->execute([$fullName, $phone, $me['id']]);
            flash_set('success', 'تم تحديث بياناتك.');
            redirect('/admin/profile.php');
        }
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new1 = $_POST['new_password'] ?? '';
        $new2 = $_POST['new_password2'] ?? '';
        if (!password_verify($current, $me['password_hash'])) $errors['current_password'] = 'كلمة السر الحالية غير صحيحة.';
        elseif (mb_strlen($new1) < 8) $errors['new_password'] = '8 أحرف على الأقل.';
        elseif ($new1 !== $new2) $errors['new_password2'] = 'كلمتا السر غير متطابقتين.';
        else {
            $newHash = password_hash($new1, PASSWORD_BCRYPT);
            db()->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$newHash, $me['id']]);
            // Keep the acting session alive; other sessions holding the old
            // fingerprint are evicted on their next request (see current_user()).
            $_SESSION['pwd_fp'] = pwd_fingerprint($newHash);
            flash_set('success', 'تم تغيير كلمة السر.');
            redirect('/admin/profile.php');
        }
    }
    $me = current_user();
}

$activeAdminPage = 'profile';
$pageTitle = 'ملفي الشخصي';
require __DIR__ . '/../includes/admin-header.php';
?>
<div class="admin-topline"><h2>ملفي الشخصي</h2></div>

<div class="admin-card">
  <h3>المعلومات الأساسية</h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update_info">
    <div class="form-row">
      <div class="form-group"><label>الاسم الكامل</label><input type="text" name="full_name" value="<?= e($me['full_name']) ?>">
        <?php if (!empty($errors['full_name'])): ?><div class="field-error"><?= e($errors['full_name']) ?></div><?php endif; ?>
      </div>
      <div class="form-group"><label>البريد الإلكتروني</label><input type="email" value="<?= e($me['email']) ?>" disabled style="background:var(--bg)"></div>
    </div>
    <div class="form-group"><label>رقم الموبايل</label><input type="tel" name="phone" value="<?= e($me['phone']) ?>"></div>
    <button type="submit" class="btn btn-primary">حفظ التعديلات</button>
  </form>
</div>

<div class="admin-card">
  <h3>تغيير كلمة السر</h3>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="change_password">
    <div class="form-group"><label>كلمة السر الحالية</label><input type="password" name="current_password">
      <?php if (!empty($errors['current_password'])): ?><div class="field-error"><?= e($errors['current_password']) ?></div><?php endif; ?>
    </div>
    <div class="form-row">
      <div class="form-group"><label>كلمة السر الجديدة</label><input type="password" name="new_password">
        <?php if (!empty($errors['new_password'])): ?><div class="field-error"><?= e($errors['new_password']) ?></div><?php endif; ?>
      </div>
      <div class="form-group"><label>تأكيد كلمة السر</label><input type="password" name="new_password2">
        <?php if (!empty($errors['new_password2'])): ?><div class="field-error"><?= e($errors['new_password2']) ?></div><?php endif; ?>
      </div>
    </div>
    <button type="submit" class="btn btn-outline">تغيير كلمة السر</button>
  </form>
</div>

<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
