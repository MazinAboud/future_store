<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';

function qs2(array $override = []) {
    $params = array_merge($_GET, $override);
    return '?' . http_build_query($params);
}

$slug = $_GET['slug'] ?? '';
$stmt = db()->prepare("SELECT p.*, b.name AS brand_name FROM products p JOIN brands b ON b.id = p.brand_id WHERE p.slug = ? AND p.is_active = 1");
$stmt->execute([$slug]);
$product = $stmt->fetch();
if (!$product) { http_response_code(404); require __DIR__ . '/includes/header.php'; echo '<div class="empty-state"><h3>المنتج غير موجود</h3></div>'; require __DIR__ . '/includes/footer.php'; exit; }

$variants = db()->prepare("SELECT * FROM product_variants WHERE product_id = ? ORDER BY price");
$variants->execute([$product['id']]);
$variants = $variants->fetchAll();

$variantId = (int)($_GET['variant'] ?? $variants[0]['id']);
$selected = null;
foreach ($variants as $v) if ($v['id'] == $variantId) $selected = $v;
if (!$selected) $selected = $variants[0];

// reviews + average
$reviewStmt = db()->prepare("SELECT r.*, u.full_name FROM reviews r JOIN users u ON u.id = r.user_id WHERE product_id = ? ORDER BY created_at DESC");
$reviewStmt->execute([$product['id']]);
$reviews = $reviewStmt->fetchAll();
$avgRating = $reviews ? array_sum(array_column($reviews, 'rating')) / count($reviews) : null;

// group variants by storage for the chip UI
$storages = array_values(array_unique(array_column($variants, 'storage')));
$colors   = array_values(array_unique(array_column(array_filter($variants, fn($v)=>$v['storage']===$selected['storage']), 'color')));

// similar products (same brand)
$similar = db()->prepare("
    SELECT p.*, MIN(pv.price) AS min_price FROM products p
    JOIN product_variants pv ON pv.product_id = p.id
    WHERE p.brand_id = ? AND p.id != ? AND p.is_active = 1
    GROUP BY p.id LIMIT 4
");
$similar->execute([$product['brand_id'], $product['id']]);
$similar = $similar->fetchAll();

// did the current customer already ask to be notified for this variant?
$alreadyNotified = false;
if (has_role('customer')) {
    $chk = db()->prepare("SELECT 1 FROM stock_notifications WHERE variant_id = ? AND user_id = ?");
    $chk->execute([$selected['id'], current_user()['id']]);
    $alreadyNotified = (bool)$chk->fetchColumn();
}

$pageTitle = $product['name'];
require __DIR__ . '/includes/header.php';
?>

<div class="breadcrumb"><a href="<?= BASE_URL ?>/index.php">الرئيسية</a> &gt; <a href="<?= BASE_URL ?>/products.php?brand=<?= (int)$product['brand_id'] ?>"><?= e($product['brand_name']) ?></a> &gt; <b><?= e($product['name']) ?></b></div>

<div class="detail-layout">
  <div class="gallery">
    <div class="gallery-main">
      <img src="<?= BASE_URL . '/' . e($product['image_path']) ?>" alt="<?= e($product['name']) ?>">
    </div>
  </div>
  <div class="detail-info">
    <div class="product-brand"><?= e($product['brand_name']) ?></div>
    <h1><?= e($product['name']) ?></h1>
    <?php if ($avgRating): ?>
      <div class="rating-row"><span class="stars"><?= str_repeat('★', round($avgRating)) . str_repeat('☆', 5 - round($avgRating)) ?></span> <?= number_format($avgRating, 1) ?> · (<?= count($reviews) ?> تقييم)</div>
    <?php else: ?>
      <div class="rating-row">لا توجد تقييمات بعد</div>
    <?php endif; ?>
    <div class="detail-price"><?= money($selected['price']) ?></div>

    <?php if (count($storages) > 1): ?>
    <div class="variant-group">
      <h4>السعة التخزينية</h4>
      <div class="variant-options">
        <?php foreach ($storages as $s):
          $rep = null; foreach ($variants as $v) if ($v['storage']===$s) { $rep = $v; break; } ?>
          <a href="<?= qs2(['variant'=>$rep['id']]) ?>" class="variant-chip <?= $s===$selected['storage']?'selected':'' ?>" style="text-decoration:none;display:inline-flex"><?= e($s) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php
      $colorVariants = array_values(array_filter($variants, fn($v)=>$v['storage']===$selected['storage']));
      if (count($colorVariants) > 1): ?>
    <div class="variant-group">
      <h4>اللون</h4>
      <div class="variant-options">
        <?php foreach ($colorVariants as $cv): ?>
          <a href="<?= qs2(['variant'=>$cv['id']]) ?>" class="variant-chip <?= $cv['id']===$selected['id']?'selected':'' ?>" style="text-decoration:none;display:inline-flex"><?= e($cv['color']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <ul class="highlights">
      <?php if ($product['screen']): ?><li><?= e($product['screen']) ?></li><?php endif; ?>
      <?php if ($product['processor']): ?><li>معالج <?= e($product['processor']) ?></li><?php endif; ?>
      <?php if ($product['camera']): ?><li>كاميرا <?= e($product['camera']) ?></li><?php endif; ?>
      <li>ضمان رسمي <?= (int)$product['warranty_months'] ?> شهرًا من Future Store</li>
    </ul>

    <?php if ($selected['stock_quantity'] > 0): ?>
      <?php if (has_role('customer')): ?>
        <form method="post" action="<?= BASE_URL ?>/cart-add.php" class="sticky-cta">
          <?= csrf_field() ?>
          <input type="hidden" name="variant_id" value="<?= (int)$selected['id'] ?>">
          <input type="hidden" name="redirect" value="product">
          <input type="hidden" name="slug" value="<?= e($slug) ?>">
          <div class="qty-stepper">
            <button type="button" onclick="stepQty(-1)">−</button>
            <input type="number" name="qty" id="qtyInput" value="1" min="1" max="<?= (int)$selected['stock_quantity'] ?>">
            <button type="button" onclick="stepQty(1)">+</button>
          </div>
          <button type="submit" class="btn btn-primary" style="flex:1">أضف للسلة — <?= money($selected['price']) ?></button>
        </form>
      <?php else: ?>
        <div class="sticky-cta">
          <a href="<?= BASE_URL ?>/login.php" class="btn btn-primary" style="flex:1">سجّل الدخول للشراء</a>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <div class="notify-box <?= $alreadyNotified ? 'done' : '' ?>" id="notifyBox">
        <?php if ($alreadyNotified): ?>
          <p><?= icon_svg('check', 14, 2.6) ?> هنبلغك بالإيميل فور توفر هذا الجهاز.</p>
        <?php else: ?>
          <p>هذه النسخة غير متوفرة حاليًا في المخزون.</p>
          <?php if (has_role('customer')): ?>
            <form method="post" action="<?= BASE_URL ?>/notify-me.php">
              <?= csrf_field() ?>
              <input type="hidden" name="variant_id" value="<?= (int)$selected['id'] ?>">
              <input type="hidden" name="slug" value="<?= e($slug) ?>">
              <button type="submit" class="btn btn-outline btn-block">نبّهني لما يتوفر</button>
            </form>
          <?php else: ?>
            <a href="<?= BASE_URL ?>/login.php" class="btn btn-outline btn-block">سجّل الدخول لتفعيل التنبيه</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="tabs">
  <div class="tab-bar">
    <button class="tab-btn active" data-tab="tab-specs">المواصفات</button>
    <button class="tab-btn" data-tab="tab-reviews">التقييمات (<?= count($reviews) ?>)</button>
    <button class="tab-btn" data-tab="tab-faq">أسئلة شائعة</button>
  </div>
  <div id="tab-specs" class="tab-panel active">
    <table class="spec-table">
      <?php if ($product['screen']): ?><tr><td>الشاشة</td><td><?= e($product['screen']) ?></td></tr><?php endif; ?>
      <?php if ($product['processor']): ?><tr><td>المعالج</td><td><?= e($product['processor']) ?></td></tr><?php endif; ?>
      <?php if ($product['camera']): ?><tr><td>الكاميرا</td><td><?= e($product['camera']) ?></td></tr><?php endif; ?>
      <?php if ($product['battery']): ?><tr><td>البطارية</td><td><?= e($product['battery']) ?></td></tr><?php endif; ?>
      <?php if ($product['resistance']): ?><tr><td>المقاومة</td><td><?= e($product['resistance']) ?></td></tr><?php endif; ?>
      <tr><td>السعة المتاحة</td><td><?= e($selected['storage']) ?> · <?= e($selected['color']) ?></td></tr>
    </table>
  </div>
  <div id="tab-reviews" class="tab-panel">
    <?php if (empty($reviews)): ?>
      <p style="color:var(--text-muted);font-size:13.5px">لا توجد تقييمات بعد لهذا المنتج.</p>
    <?php else: foreach ($reviews as $r): ?>
      <div class="review-card">
        <div class="review-head"><span class="review-name"><?= e($r['full_name']) ?></span><span class="review-date"><?= time_ago($r['created_at']) ?></span></div>
        <div class="stars" style="margin-bottom:6px"><?= str_repeat('★', $r['rating']) . str_repeat('☆', 5 - $r['rating']) ?></div>
        <?php if ($r['comment']): ?><p style="font-size:13.5px;color:var(--text-muted)"><?= e($r['comment']) ?></p><?php endif; ?>
      </div>
    <?php endforeach; endif; ?>

    <?php if (has_role('customer')): ?>
      <form method="post" action="<?= BASE_URL ?>/review-add.php" style="margin-top:18px;border-top:1px solid var(--border);padding-top:18px">
        <?= csrf_field() ?>
        <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
        <input type="hidden" name="slug" value="<?= e($slug) ?>">
        <div class="form-group">
          <label>تقييمك</label>
          <select name="rating" class="input" required>
            <option value="5">5 - ممتاز</option><option value="4">4 - جيد جدًا</option><option value="3">3 - جيد</option><option value="2">2 - مقبول</option><option value="1">1 - ضعيف</option>
          </select>
        </div>
        <div class="form-group"><label>تعليقك (اختياري)</label><textarea name="comment" maxlength="500" placeholder="شاركنا تجربتك مع هذا الجهاز"></textarea></div>
        <button type="submit" class="btn btn-outline">إرسال التقييم</button>
      </form>
    <?php endif; ?>
  </div>
  <div id="tab-faq" class="tab-panel">
    <p style="font-size:13.5px;color:var(--text-muted)">هل الجهاز أصلي وعليه ضمان؟ نعم، كل أجهزتنا أصلية 100% وعليها ضمان رسمي <?= (int)$product['warranty_months'] ?> شهرًا من Future Store.</p>
    <p style="font-size:13.5px;color:var(--text-muted);margin-top:10px">كم مدة التوصيل؟ التوصيل داخل الخرطوم عادة 1-3 أيام عمل، ولباقي الولايات حسب الموقع.</p>
  </div>
</div>

<?php if ($similar): ?>
<section class="section">
  <div class="section-head"><h2>منتجات مشابهة</h2></div>
  <div class="product-grid">
    <?php foreach ($similar as $sp): ?>
      <div class="product-card">
        <div class="product-thumb">
          <a href="<?= BASE_URL ?>/product.php?slug=<?= e($sp['slug']) ?>" aria-label="<?= e($sp['name']) ?>">
            <img src="<?= BASE_URL . '/' . e($sp['image_path']) ?>" alt="<?= e($sp['name']) ?>" loading="lazy">
          </a>
        </div>
        <div class="product-info">
          <div class="product-name"><a href="<?= BASE_URL ?>/product.php?slug=<?= e($sp['slug']) ?>"><?= e($sp['name']) ?></a></div>
          <div class="product-price"><?= money($sp['min_price']) ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<script>
function stepQty(delta){
  const input = document.getElementById('qtyInput');
  let v = parseInt(input.value) + delta;
  const max = parseInt(input.max);
  if (v < 1) v = 1; if (v > max) v = max;
  input.value = v;
}
document.querySelectorAll('.tab-btn').forEach(btn=>{
  btn.addEventListener('click',()=>{
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
    document.getElementById(btn.dataset.tab).classList.add('active');
  });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
