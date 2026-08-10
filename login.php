<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/throttle.php';

if (has_role('customer')) redirect('/account/orders.php');

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Checked BEFORE password_verify(), not after. Verifying first would still
    // run bcrypt on every request, so a locked attacker could keep the server
    // burning CPU indefinitely — the lock has to cut in before any expensive
    // work happens.
    $wait = login_throttle_check($email);

    if ($wait > 0) {
        $error = 'محاولات دخول كثيرة. حاول مرة أخرى بعد ' . throttle_wait_label($wait) . '.';
    } elseif (attempt_login($email, $password, 'customer')) {
        login_throttle_success($email);
        $target = $_SESSION['redirect_after_login'] ?? '/account/orders.php';
        unset($_SESSION['redirect_after_login']);
        redirect($target);
    } else {
        login_throttle_fail($email);
        $error = 'البريد الإلكتروني أو كلمة السر غير صحيحة.';
    }
}

$pageTitle = 'تسجيل الدخول';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-wrap">
  <div class="auth-card">
    <h1>تسجيل الدخول</h1>
    <p class="sub">ادخل لحسابك لمتابعة الشراء وإدارة طلباتك</p>
    <?php if ($error): ?><div class="flash-msg flash-error" style="margin-bottom:16px"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="form-group">
        <label>البريد الإلكتروني</label>
        <input type="email" name="email" required autofocus value="<?= e($_POST['email'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>كلمة السر</label>
        <div class="password-field">
          <input type="password" name="password" id="customerPassword" required autocomplete="current-password">
          <button type="button" id="togglePassword" aria-label="إظهار كلمة السر">
            <svg class="eye-show" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
            <svg class="eye-hide" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none"><path d="M17.94 17.94A10.94 10.94 0 0112 19c-7 0-11-7-11-7a20.4 20.4 0 015.06-5.94M9.9 4.24A10.4 10.4 0 0112 4c7 0 11 7 11 7a20.3 20.3 0 01-2.16 3.19M14.12 14.12a3 3 0 11-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
          </button>
        </div>
      </div>
      <button type="submit" class="btn btn-primary btn-block">دخول</button>
    </form>
    <script>
    // same show/hide control the team login uses, so both entry points behave alike
    document.getElementById('togglePassword').addEventListener('click', function () {
      var input = document.getElementById('customerPassword');
      var show = this.querySelector('.eye-show');
      var hide = this.querySelector('.eye-hide');
      var isHidden = input.type === 'password';
      input.type = isHidden ? 'text' : 'password';
      show.style.display = isHidden ? 'none' : '';
      hide.style.display = isHidden ? '' : 'none';
      this.setAttribute('aria-label', isHidden ? 'إخفاء كلمة السر' : 'إظهار كلمة السر');
    });
    </script>
    <p style="text-align:center;font-size:13px;color:var(--text-muted);margin-top:18px">مفيش حساب؟ <a href="<?= BASE_URL ?>/register.php" style="color:var(--navy);font-weight:700">أنشئ حساب جديد</a></p>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
