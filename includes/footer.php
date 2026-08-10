</main>
<footer class="site-footer">
  <div class="container">
    <div>
      <h4><?= e(SITE_NAME) ?></h4>
      <p style="max-width:260px;font-size:13px;color:#8B96B8">أحدث الهواتف الذكية من آبل، سامسونج، شاومي، وجوجل. توصيل لكل السودان مع ضمان معتمد.</p>
    </div>
    <div>
      <h4>تسوّق</h4>
      <a href="<?= BASE_URL ?>/products.php">كل المنتجات</a>
      <a href="<?= BASE_URL ?>/compare.php">قارن الأجهزة</a>
    </div>
    <div>
      <h4>حسابي</h4>
      <a href="<?= BASE_URL ?>/login.php">تسجيل الدخول</a>
      <a href="<?= BASE_URL ?>/register.php">إنشاء حساب</a>
    </div>
    <div>
      <h4>تواصل معنا</h4>
      <a href="mailto:<?= e(SITE_EMAIL) ?>" class="contact-link">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v16H4z" opacity="0"/><path d="M3 6l9 7 9-7"/><path d="M3 6h18v12H3z"/></svg>
        <?= e(SITE_EMAIL) ?>
      </a>
      <a href="tel:<?= e(str_replace(' ', '', SITE_PHONE)) ?>" class="contact-link">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3.1 19.5 19.5 0 01-6-6A19.8 19.8 0 012.1 4.2 2 2 0 014.1 2h3a2 2 0 012 1.7c.1 1 .3 2 .6 2.9a2 2 0 01-.5 2.1L8 9.9a16 16 0 006 6l1.2-1.2a2 2 0 012.1-.5c.9.3 1.9.5 2.9.6a2 2 0 011.8 2.1z"/></svg>
        <?= e(SITE_PHONE) ?>
      </a>
    </div>
  </div>
</footer>
</body>
</html>
