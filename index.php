<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$brands = db()->query("
    SELECT b.id, b.name, b.slug, COUNT(p.id) AS product_count
    FROM brands b LEFT JOIN products p ON p.brand_id = b.id AND p.is_active = 1
    GROUP BY b.id ORDER BY b.name
")->fetchAll();

// "Best sellers": ranked by DELIVERED units only, falling back to newest.
// Counting every order that merely wasn't cancelled let untouched 'pending'
// baskets rank products — a 28% overstatement on the current data (311 units
// reported against 224 actually delivered).
$bestSellers = db()->query("
    SELECT p.*, b.name AS brand_name,
           MIN(pv.price) AS min_price,
           COALESCE(SUM(oi.quantity), 0) AS sold
    FROM products p
    JOIN brands b ON b.id = p.brand_id
    JOIN product_variants pv ON pv.product_id = p.id
    LEFT JOIN order_items oi ON oi.variant_id = pv.id
    LEFT JOIN orders o ON o.id = oi.order_id AND o.status = 'delivered'
    WHERE p.is_active = 1
    GROUP BY p.id
    ORDER BY sold DESC, p.created_at DESC
    LIMIT 8
")->fetchAll();

$pageTitle = 'الرئيسية';
require __DIR__ . '/includes/header.php';
?>

<section class="hero">
  <div class="hero-text">
    <div class="white">FUTURE STORE — الخرطوم</div>
    <h1>أحدث الهواتف الذكية، بأفضل سعر وضمان معتمد</h1>
    <p>آيفون، سامسونج، شاومي، وجوجل — كل الموديلات في مكان واحد، مع شحن سريع لكل السودان وضمان رسمي على كل جهاز.</p>
    <div class="hero-actions">
      <a href="<?= BASE_URL ?>/products.php" class="btn btn-outline"style="color:#fff;border-color:rgba(255,255,255,.5)">تسوق الآن</a>
      <a href="<?= BASE_URL ?>/compare.php" class="btn btn-outline" style="color:#fff;border-color:rgba(255,255,255,.5)">قارن بين الأجهزة</a>
    </div>
    <ul class="hero-trust">
      <li><?= icon_svg('truck', 15, 2.1) ?> شحن سريع لكل السودان</li>
      <li><?= icon_svg('shield', 15, 2.1) ?> ضمان رسمي على كل جهاز</li>
      <li><?= icon_svg('coins', 15, 2.1) ?> الدفع كاش عند الاستلام</li>
    </ul>
  </div>
  <img src="<?= BASE_URL ?>/assets/images/hero-iphone.jpg" alt="أحدث هواتف iPhone" class="hero-img" loading="eager">
</section>

<section class="section">
  <div class="section-head"><h2>تصفح حسب الماركة</h2></div>
  <div class="brand-grid">
    <?php foreach ($brands as $b): ?>
      <a class="brand-card" href="<?= BASE_URL ?>/products.php?brand=<?= (int)$b['id'] ?>">
        <div class="brand-name"><?= e($b['name']) ?></div>
        <div class="brand-count"><?= (int)$b['product_count'] ?> منتج</div>
      </a>
    <?php endforeach; ?>
  </div>
</section>

<section class="section">
  <div class="section-head"><h2>الأكثر مبيعًا</h2><a href="<?= BASE_URL ?>/products.php">عرض الكل ←</a></div>
  <div class="product-grid">
    <?php foreach ($bestSellers as $p): ?>
      <div class="product-card">
        <div class="product-thumb">
          <a href="<?= BASE_URL ?>/product.php?slug=<?= e($p['slug']) ?>" aria-label="<?= e($p['name']) ?>">
            <img src="<?= BASE_URL . '/' . e($p['image_path']) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
          </a>
        </div>
        <div class="product-info">
          <div class="product-brand"><?= e($p['brand_name']) ?></div>
          <div class="product-name"><a href="<?= BASE_URL ?>/product.php?slug=<?= e($p['slug']) ?>"><?= e($p['name']) ?></a></div>
          <div class="product-price"><?= money($p['min_price']) ?> <small>ابتداءً من</small></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
