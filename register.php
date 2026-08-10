<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';

if (has_role('customer')) redirect('/account/orders.php');

$errors = [];
$old = ['full_name'=>'','email'=>'','phone'=>'','address'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $old['full_name'] = trim($_POST['full_name'] ?? '');
    $old['email']     = trim($_POST['email'] ?? '');
    $old['phone']     = trim($_POST['phone'] ?? '');
    $old['address']   = trim($_POST['address'] ?? '');
    $password  = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (mb_strlen($old['full_name']) < 3) $errors['full_name'] = 'أدخل الاسم الكامل.';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'بريد إلكتروني غير صحيح.';
    if (!preg_match('/^[0-9+ ]{8,15}$/', $old['phone'])) $errors['phone'] = 'رقم موبايل غير صحيح.';
    if (mb_strlen($old['address']) < 5) $errors['address'] = 'أدخل عنوان توصيل واضح.';
    if (mb_strlen($password) < 8) $errors['password'] = 'كلمة السر 8 أحرف على الأقل.';
    if ($password !== $password2) $errors['password2'] = 'كلمتا السر غير متطابقتين.';

    if (empty($errors)) {
        $chk = db()->prepare('SELECT 1 FROM users WHERE email = ?');
        $chk->execute([$old['email']]);
        if ($chk->fetchColumn()) {
            $errors['email'] = 'هذا البريد الإلكتروني مسجل بالفعل.';
        }
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = db()->prepare("INSERT INTO users (role, full_name, email, phone, password_hash, address) VALUES ('customer', ?,?,?,?,?)");
        $stmt->execute([$old['full_name'], $old['email'], $old['phone'], $hash, $old['address']]);
        attempt_login($old['email'], $password, 'customer');
        flash_set('success', 'أهلًا بيك في Future Store! تم إنشاء حسابك بنجاح.');
        redirect('/account/orders.php');
    }
}

$pageTitle = 'إنشاء حساب';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-wrap">
  <div class="auth-card">
    <h1>إنشاء حساب جديد</h1>
    <p class="sub">تسجيل حساب مطلوب فقط عشان تقدر تشتري وتتابع طلباتك</p>
    <form method="post" novalidate>
      <?= csrf_field() ?>
      <div class="form-group">
        <label>الاسم الكامل <span class="req">*</span></label>
        <input type="text" name="full_name" value="<?= e($old['full_name']) ?>" required>
        <?php if (!empty($errors['full_name'])): ?><div class="field-error"><?= e($errors['full_name']) ?></div><?php endif; ?>
      </div>
      <div class="form-group">
        <label>البريد الإلكتروني <span class="req">*</span></label>
        <input type="email" name="email" value="<?= e($old['email']) ?>" required>
        <?php if (!empty($errors['email'])): ?><div class="field-error"><?= e($errors['email']) ?></div><?php endif; ?>
      </div>
      <div class="form-group">
        <label>رقم الموبايل <span class="req">*</span></label>
        <input type="tel" name="phone" value="<?= e($old['phone']) ?>" placeholder="09xxxxxxxx" required>
        <?php if (!empty($errors['phone'])): ?><div class="field-error"><?= e($errors['phone']) ?></div><?php endif; ?>
      </div>
      <div class="form-group">
        <label>عنوان التوصيل <span class="req">*</span></label>
        <input type="text" name="address" value="<?= e($old['address']) ?>" placeholder="الحي، الشارع، المدينة" required>
        <?php if (!empty($errors['address'])): ?><div class="field-error"><?= e($errors['address']) ?></div><?php endif; ?>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label>كلمة السر <span class="req">*</span></label>
          <input type="password" name="password" required>
          <div class="help">8 أحرف على الأقل</div>
          <?php if (!empty($errors['password'])): ?><div class="field-error"><?= e($errors['password']) ?></div><?php endif; ?>
        </div>
        <div class="form-group">
          <label>تأكيد كلمة السر <span class="req">*</span></label>
          <input type="password" name="password2" required>
          <?php if (!empty($errors['password2'])): ?><div class="field-error"><?= e($errors['password2']) ?></div><?php endif; ?>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-block">إنشاء الحساب</button>
    </form>
    <p style="text-align:center;font-size:13px;color:var(--text-muted);margin-top:18px">عندك حساب بالفعل؟ <a href="<?= BASE_URL ?>/login.php" style="color:var(--navy);font-weight:700">سجّل الدخول</a></p>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
