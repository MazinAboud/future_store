<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/throttle.php';

// Unified internal login: one form for employees and admins. The account's
// actual role (from the users table) decides which dashboard it lands on —
// nobody has to remember two different URLs.
if (has_role('admin')) redirect('/admin/index.php');
if (has_role('employee')) redirect('/staff/index.php');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // This form is the higher-value target of the two: the accounts behind it
    // can edit prices, read every customer's address, and delete orders. It
    // gets the same throttle as the customer login, and needs it more.
    $wait = login_throttle_check($email);

    if ($wait > 0) {
        $error = 'محاولات دخول كثيرة. حاول مرة أخرى بعد ' . throttle_wait_label($wait) . '.';
    } else {
        $role = attempt_team_login($email, $password);
        if ($role) {
            login_throttle_success($email);
            $fallback = $role === 'admin' ? '/admin/index.php' : '/staff/index.php';
            $target = $_SESSION['redirect_after_login'] ?? $fallback;
            unset($_SESSION['redirect_after_login']);
            redirect($target);
        }
        login_throttle_fail($email);
        $error = 'البريد الإلكتروني أو كلمة السر غير صحيحة.';
    }
}
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>دخول الفريق | <?= e(SITE_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="team-login">
  <div class="team-login-main">
    <div class="team-login-card">
      <h2>تسجيل الدخول</h2>
      <p class="hint">أدخل بيانات حسابك للمتابعة إلى لوحة التحكم.</p>
      <?php if ($error): ?><div class="flash-msg flash-error" style="margin-bottom:18px"><?= e($error) ?></div><?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="form-group">
          <label>البريد الإلكتروني</label>
          <input type="email" name="email" required autofocus autocomplete="username">
        </div>
        <div class="form-group">
          <label>كلمة السر</label>
          <div class="password-field">
            <input type="password" name="password" id="teamPassword" required autocomplete="current-password">
            <button type="button" id="togglePassword" aria-label="إظهار كلمة السر">
              <svg class="eye-show" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
              <svg class="eye-hide" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><path d="M17.94 17.94A10.94 10.94 0 0112 19c-7 0-11-7-11-7a20.4 20.4 0 015.06-5.94M9.9 4.24A10.4 10.4 0 0112 4c7 0 11 7 11 7a20.3 20.3 0 01-2.16 3.19M14.12 14.12a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
            </button>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-block">دخول</button>
      </form>
      <a href="<?= BASE_URL ?>/index.php" class="back-link">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        رجوع للمتجر
      </a>
    </div>
  </div>

  <footer class="site-footer">
    <div class="container">
      <div>
        <h4><?= e(SITE_NAME) ?></h4>
        <p style="max-width:260px;font-size:13px;color:#8B96B8">لوحة تحكم فريق العمل الداخلي — إدارة كاملة لمتجر الهواتف من مكان واحد.</p>
      </div>
      <div>
        <h4>تواصل معنا</h4>
        <a href="mailto:<?= e(SITE_EMAIL) ?>" class="contact-link">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6l9 7 9-7"/><path d="M3 6h18v12H3z"/></svg>
          <?= e(SITE_EMAIL) ?>
        </a>
        <a href="tel:<?= e(str_replace(' ', '', SITE_PHONE)) ?>" class="contact-link">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3.1 19.5 19.5 0 01-6-6A19.8 19.8 0 012.1 4.2 2 2 0 014.1 2h3a2 2 0 012 1.7c.1 1 .3 2 .6 2.9a2 2 0 01-.5 2.1L8 9.9a16 16 0 006 6l1.2-1.2a2 2 0 012.1-.5c.9.3 1.9.5 2.9.6a2 2 0 011.8 2.1z"/></svg>
          <?= e(SITE_PHONE) ?>
        </a>
      </div>
    </div>
  </footer>
</div>

<script>
document.getElementById('togglePassword').addEventListener('click', function () {
  var input = document.getElementById('teamPassword');
  var show = this.querySelector('.eye-show');
  var hide = this.querySelector('.eye-hide');
  var isHidden = input.type === 'password';
  input.type = isHidden ? 'text' : 'password';
  show.style.display = isHidden ? 'none' : '';
  hide.style.display = isHidden ? '' : 'none';
  this.setAttribute('aria-label', isHidden ? 'إخفاء كلمة السر' : 'إظهار كلمة السر');
});
</script>
</body>
</html>
