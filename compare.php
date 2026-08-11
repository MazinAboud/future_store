<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';

$ids = $_SESSION['compare'] ?? [];
$products = [];
if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("
        SELECT p.*, b.name AS brand_name, MIN(pv.price) AS min_price
        FROM products p JOIN brands b ON b.id = p.brand_id
        JOIN product_variants pv ON pv.product_id = p.id
        WHERE p.id IN ($in) AND p.is_active = 1 GROUP BY p.id
    ");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll();
    // preserve the order the user added them in
    foreach ($ids as $id) foreach ($rows as $r) if ($r['id'] == $id) $products[] = $r;
}

$specRows = [
    'brand_name' => 'الماركة',
    'min_price'  => 'السعر (يبدأ من)',
    'screen'     => 'الشاشة',
    'processor'  => 'المعالج',
    'camera'     => 'الكاميرا',
    'battery'    => 'البطارية',
    'resistance' => 'المقاومة',
    'warranty_months' => 'الضمان',
];

// Empty state gets a real, working preview: a few actual best-selling products
// the visitor can add to the comparison in one click, instead of a bare message.
$previewProducts = [];
if (empty($products)) {
    $previewProducts = db()->query("
        SELECT p.id, p.name, p.slug, p.image_path, b.name AS brand_name,
               MIN(pv.price) AS min_price,
               COALESCE(SUM(oi.quantity), 0) AS sold
        FROM products p
        JOIN brands b ON b.id = p.brand_id
        JOIN product_variants pv ON pv.product_id = p.id
        LEFT JOIN order_items oi ON oi.variant_id = pv.id
        -- same 'delivered only' rule the rest of the project uses; see api/home.php
        LEFT JOIN orders o ON o.id = oi.order_id AND o.status = 'delivered'
        WHERE p.is_active = 1
        GROUP BY p.id
        ORDER BY sold DESC, p.created_at DESC
        LIMIT 3
    ")->fetchAll();
}

$pageTitle = 'قارن الأجهزة';
require __DIR__ . '/includes/header.php';
?>

<div class="breadcrumb"><a href="<?= BASE_URL ?>/index.php">الرئيسية</a> &gt; <b>قارن الأجهزة</b></div>

<?php if (empty($products)): ?>
  <div class="compare-empty">
    <div class="compare-empty-icon">
      <svg width="52" height="52" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6">
        <rect x="2.5" y="4" width="8" height="16" rx="1.8"/>
        <rect x="13.5" y="4" width="8" height="16" rx="1.8" opacity=".45"/>
        <line x1="6.5" y1="16.3" x2="6.5" y2="16.32"/>
        <line x1="17.5" y1="16.3" x2="17.5" y2="16.32" opacity=".45"/>
        <path d="M11 8.5l1.6 1.6L11 11.7M12.6 10.1H9.5" opacity=".85"/>
      </svg>
    </div>
    <h3>لا توجد أجهزة للمقارنة بعد</h3>
    <p>اضغط "قارن" على أي منتج من صفحة المنتجات لإضافته هنا — حتى 4 أجهزة جنبًا إلى جنب، بكل مواصفاتها.</p>
    <a href="<?= BASE_URL ?>/products.php" class="btn btn-primary" style="margin-top:18px">تصفح المنتجات</a>
  </div>

  <?php if ($previewProducts): ?>
  <section class="compare-suggest">
    <div class="section-head"><h2>ابدأ المقارنة بأحد الأجهزة الأكثر طلبًا</h2></div>
    <div class="product-grid">
      <?php foreach ($previewProducts as $p): ?>
        <div class="product-card">
          <div class="product-thumb">
            <a href="<?= BASE_URL ?>/product.php?slug=<?= e($p['slug']) ?>" aria-label="<?= e($p['name']) ?>">
              <img src="<?= BASE_URL . '/' . e($p['image_path']) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
            </a>
            <form method="post" action="<?= BASE_URL ?>/compare-toggle.php">
              <?= csrf_field() ?>
              <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
              <input type="hidden" name="back" value="<?= e(current_path()) ?>">
              <button type="submit" class="compare-checkbox">+ قارن</button>
            </form>
          </div>
          <div class="product-info">
            <div class="product-brand"><?= e($p['brand_name']) ?></div>
            <div class="product-name"><a href="<?= BASE_URL ?>/product.php?slug=<?= e($p['slug']) ?>"><?= e($p['name']) ?></a></div>
            <div class="product-price"><?= money($p['min_price']) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>
<?php else: ?>
<div class="compare-table-wrap">
  <table class="compare-table">
    <thead>
      <tr>
        <th></th>
        <?php foreach ($products as $p): ?>
          <th>
            <a href="<?= BASE_URL ?>/product.php?slug=<?= e($p['slug']) ?>" style="color:inherit">
              <img src="<?= BASE_URL . '/' . e($p['image_path']) ?>" alt="<?= e($p['name']) ?>">
              <div style="font-weight:800;font-size:13px"><?= e($p['name']) ?></div>
            </a>
            <form method="post" action="<?= BASE_URL ?>/compare-toggle.php" style="margin-top:8px">
              <?= csrf_field() ?>
              <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
              <input type="hidden" name="back" value="/compare.php">
              <button type="submit" class="btn btn-sm btn-outline">إزالة</button>
            </form>
          </th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($specRows as $key => $label):
        $values = array_map(fn($p) => $key === 'min_price' ? money($p[$key]) : ($key === 'warranty_months' ? $p[$key] . ' شهرًا' : ($p[$key] ?: '—')), $products);
        $allSame = count(array_unique($values)) <= 1;
      ?>
        <tr>
          <td><?= e($label) ?></td>
          <?php foreach ($values as $v): ?>
            <td class="<?= $allSame ? '' : 'diff' ?>"><?= e($v) ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      <tr>
        <td>&nbsp;</td>
        <?php foreach ($products as $p): ?>
          <td><a href="<?= BASE_URL ?>/product.php?slug=<?= e($p['slug']) ?>" class="btn btn-sm btn-primary">عرض المنتج</a></td>
        <?php endforeach; ?>
      </tr>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
