<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

require_role('customer');
$me = current_user();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? 'update_info';

    if ($action === 'update_info') {
        $fullName = trim($_POST['full_name'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $address  = trim($_POST['address'] ?? '');
        if (mb_strlen($fullName) < 3) $errors['full_name'] = 'أدخل الاسم الكامل.';
        if (!preg_match('/^[0-9+ ]{8,15}$/', $phone)) $errors['phone'] = 'رقم موبايل غير صحيح.';
        if (mb_strlen($address) < 5) $errors['address'] = 'أدخل عنوانًا واضحًا.';

        if (empty($errors)) {
            db()->prepare("UPDATE users SET full_name=?, phone=?, address=? WHERE id=?")
                ->execute([$fullName, $phone, $address, $me['id']]);
            $_SESSION['name'] = $fullName;
            flash_set('success', 'تم تحديث بياناتك بنجاح.');
            redirect('/account/profile.php');
        }
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new1 = $_POST['new_password'] ?? '';
        $new2 = $_POST['new_password2'] ?? '';
        if (!password_verify($current, $me['password_hash'])) {
            $errors['current_password'] = 'كلمة السر الحالية غير صحيحة.';
        } elseif (mb_strlen($new1) < 8) {
            $errors['new_password'] = '8 أحرف على الأقل.';
        } elseif ($new1 !== $new2) {
            $errors['new_password2'] = 'كلمتا السر غير متطابقتين.';
        } else {
            $newHash = password_hash($new1, PASSWORD_BCRYPT);
            db()->prepare("UPDATE users SET password_hash=? WHERE id=?")
                ->execute([$newHash, $me['id']]);
            // Re-fingerprint THIS session so the change doesn't sign the user out
            // of the very browser they changed it in; every OTHER session still
            // holds the old fingerprint and is ended on its next request.
            $_SESSION['pwd_fp'] = pwd_fingerprint($newHash);
            flash_set('success', 'تم تغيير كلمة السر بنجاح.');
            redirect('/account/profile.php');
        }
    }
    $me = current_user();
}

$activePage = 'profile';
$pageTitle = 'بياناتي';
require __DIR__ . '/../includes/header.php';
?>
<div class="account-layout">
  <?php require __DIR__ . '/../includes/account-nav.php'; ?>
  <div class="account-main">
    <h2 style="font-size:19px;margin-bottom:18px">بياناتي الشخصية</h2>

    <div class="admin-card">
      <h3>المعلومات الأساسية</h3>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_info">
        <div class="form-group"><label>الاسم الكامل</label><input type="text" name="full_name" value="<?= e($me['full_name']) ?>">
          <?php if (!empty($errors['full_name'])): ?><div class="field-error"><?= e($errors['full_name']) ?></div><?php endif; ?>
        </div>
        <div class="form-group"><label>البريد الإلكتروني</label><input type="email" value="<?= e($me['email']) ?>" disabled style="background:var(--bg)">
          <div class="help">البريد الإلكتروني لا يمكن تغييره</div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>رقم الموبايل</label><input type="tel" name="phone" value="<?= e($me['phone']) ?>">
            <?php if (!empty($errors['phone'])): ?><div class="field-error"><?= e($errors['phone']) ?></div><?php endif; ?>
          </div>
        </div>
        <div class="form-group"><label>عنوان التوصيل</label><input type="text" name="address" value="<?= e($me['address']) ?>">
          <?php if (!empty($errors['address'])): ?><div class="field-error"><?= e($errors['address']) ?></div><?php endif; ?>
        </div>
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
          <div class="form-group"><label>تأكيد كلمة السر الجديدة</label><input type="password" name="new_password2">
            <?php if (!empty($errors['new_password2'])): ?><div class="field-error"><?= e($errors['new_password2']) ?></div><?php endif; ?>
          </div>
        </div>
        <button type="submit" class="btn btn-outline">تغيير كلمة السر</button>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
