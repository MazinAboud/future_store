<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/cache.php';
require_role('admin');

$id = (int)($_GET['id'] ?? 0);
$product = null;
$variants = [];
if ($id) {
    $stmt = db()->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$id]);
    $product = $stmt->fetch();
    if (!$product) { flash_set('error', 'المنتج غير موجود.'); redirect('/admin/products.php'); }
    $vStmt = db()->prepare("SELECT * FROM product_variants WHERE product_id = ? ORDER BY id");
    $vStmt->execute([$id]);
    $variants = $vStmt->fetchAll();
}
$brands = db()->query("SELECT * FROM brands ORDER BY name")->fetchAll();
$errors = [];

function slugify($text) {
    $text = preg_replace('/[^A-Za-z0-9]+/', '-', $text);
    return strtolower(trim($text, '-'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name = trim($_POST['name'] ?? '');
    $brandId = (int)($_POST['brand_id'] ?? 0);
    $shortDesc = trim($_POST['short_desc'] ?? '');
    $screen = trim($_POST['screen'] ?? '');
    $processor = trim($_POST['processor'] ?? '');
    $camera = trim($_POST['camera'] ?? '');
    $battery = trim($_POST['battery'] ?? '');
    $resistance = trim($_POST['resistance'] ?? '');
    $warranty = max(0, (int)($_POST['warranty_months'] ?? 24));
    $variantRows = $_POST['variants'] ?? [];

    if (mb_strlen($name) < 2) $errors['name'] = 'أدخل اسم المنتج.';
    if (!$brandId) $errors['brand_id'] = 'اختر الماركة.';

    $validVariants = [];
    foreach ($variantRows as $row) {
        if (!empty($row['delete'])) continue;
        $storage = trim($row['storage'] ?? '');
        $color   = trim($row['color'] ?? '');
        $price   = (float)($row['price'] ?? 0);
        $stock   = max(0, (int)($row['stock'] ?? 0));
        $sku     = trim($row['sku'] ?? '');
        if ($storage === '' && $color === '' && $price <= 0) continue; // blank template row
        if ($storage === '' || $color === '' || $price <= 0 || $sku === '') {
            $errors['variants'] = 'كل نسخة محتاجة سعة، لون، سعر أكبر من صفر، وSKU.';
            break;
        }
        $validVariants[] = ['id' => $row['id'] ?? '', 'storage' => $storage, 'color' => $color, 'price' => $price, 'stock' => $stock, 'sku' => $sku];
    }
    if (empty($validVariants)) $errors['variants'] = $errors['variants'] ?? 'أضف نسخة واحدة على الأقل.';

    // image upload (optional)
    $imagePath = $product['image_path'] ?? null;
    if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        // SVG intentionally excluded from uploads: an uploaded SVG can carry
        // embedded <script>, which would execute if the file is ever opened
        // directly in a browser tab (stored XSS). Placeholder illustrations
        // are still SVG — but those are generated server-side, never uploaded.
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array($ext, $allowed)) {
            $errors['image'] = 'صيغة الصورة يجب أن تكون jpg, png, أو webp.';
        } elseif ($_FILES['image']['size'] > 3 * 1024 * 1024) {
            $errors['image'] = 'حجم الصورة يجب ألا يتجاوز 3 ميجابايت.';
        } elseif (@getimagesize($_FILES['image']['tmp_name']) === false) {
            // Confirms the upload is really a decodable image, not just a
            // renamed file wearing an image extension.
            $errors['image'] = 'الملف المرفوع ليس صورة صالحة.';
        } else {
            $fname = slugify($name) . '-' . time() . '.' . $ext;
            $dest = __DIR__ . '/../assets/images/products/' . $fname;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $dest)) {
                $imagePath = 'assets/images/products/' . $fname;
            }
        }
    }

    if (empty($errors)) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($product) {
                $stmt = $pdo->prepare("UPDATE products SET brand_id=?, name=?, short_desc=?, screen=?, processor=?, camera=?, battery=?, resistance=?, warranty_months=?, image_path=? WHERE id=?");
                $stmt->execute([$brandId, $name, $shortDesc, $screen, $processor, $camera, $battery, $resistance, $warranty, $imagePath, $product['id']]);
                $productId = $product['id'];
            } else {
                $slug = slugify($name);
                $chk = $pdo->prepare("SELECT COUNT(*) FROM products WHERE slug = ?");
                $base = $slug; $n = 2;
                while (true) {
                    $chk->execute([$slug]);
                    if (!$chk->fetchColumn()) break;
                    $slug = $base . '-' . $n++;
                }
                if (!$imagePath) {
                    // auto-generate a placeholder illustration consistent with the catalog style
                    $imagePath = 'assets/images/products/' . $slug . '.svg';
                    $hue = crc32($name) % 360;
                    $svg = product_placeholder_svg($hue);
                    file_put_contents(__DIR__ . '/../' . $imagePath, $svg);
                }
                $stmt = $pdo->prepare("INSERT INTO products (brand_id, name, slug, short_desc, screen, processor, camera, battery, resistance, warranty_months, image_path) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$brandId, $name, $slug, $shortDesc, $screen, $processor, $camera, $battery, $resistance, $warranty, $imagePath]);
                $productId = $pdo->lastInsertId();
            }

            // sync variants
            $insV = $pdo->prepare("INSERT INTO product_variants (product_id, storage, color, price, stock_quantity, sku) VALUES (?,?,?,?,?,?)");
            $updV = $pdo->prepare("UPDATE product_variants SET storage=?, color=?, price=?, stock_quantity=?, sku=? WHERE id=? AND product_id=?");
            foreach ($validVariants as $v) {
                if ($v['id'] !== '') {
                    $updV->execute([$v['storage'], $v['color'], $v['price'], $v['stock'], $v['sku'], (int)$v['id'], $productId]);
                } else {
                    $insV->execute([$productId, $v['storage'], $v['color'], $v['price'], $v['stock'], $v['sku']]);
                }
            }
            // deletions
            foreach ($variantRows as $row) {
                if (!empty($row['delete']) && !empty($row['id'])) {
                    try {
                        $pdo->prepare("DELETE FROM product_variants WHERE id = ? AND product_id = ?")->execute([(int)$row['id'], $productId]);
                    } catch (PDOException $e) {
                        // has order history — keep it but zero its stock instead of hard delete
                        $pdo->prepare("UPDATE product_variants SET stock_quantity = 0 WHERE id = ?")->execute([(int)$row['id']]);
                    }
                }
            }

            // Inside the transaction, so a rollback also removes the claim
            // that the product was saved.
            admin_log($product ? 'update_product' : 'create_product', 'product', (int)$productId,
                ($product ? 'تعديل' : 'إضافة') . " المنتج \"$name\" (" . count($validVariants) . " نسخة)"
                . (!empty($_FILES['image']['name']) ? ' — مع رفع صورة' : ''));
            $pdo->commit();
            cache_forget(CACHE_KEY_API_BEST_SELLERS);
            flash_set('success', $product ? 'تم تحديث المنتج بنجاح.' : 'تم إضافة المنتج بنجاح.');
            redirect('/admin/products.php');
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Product save failed: ' . $e->getMessage());
            $errors['general'] = 'حدث خطأ أثناء الحفظ. تأكد أن SKU كل نسخة فريد ولم يتكرر.';
        }
    }
}

/**
 * Auto-generated placeholder shown until the admin uploads a real photo:
 * a color-matched "phone from the back" illustration with a dual-lens
 * camera module, consistent with the rest of the catalog's illustration style.
 */
function product_placeholder_svg($hue) {
    $c1 = "hsl($hue, 45%, 68%)";
    $c2 = "hsl($hue, 55%, 38%)";
    return '<svg viewBox="0 0 300 420" xmlns="http://www.w3.org/2000/svg">'
        . '<defs><linearGradient id="s" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $c1 . '"/><stop offset="1" stop-color="' . $c2 . '"/></linearGradient>'
        . '<linearGradient id="sh" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fff" stop-opacity="0.22"/><stop offset="0.35" stop-color="#fff" stop-opacity="0"/></linearGradient></defs>'
        . '<ellipse cx="150" cy="404" rx="92" ry="13" fill="#000" opacity="0.13"/>'
        . '<rect x="18" y="10" width="264" height="402" rx="46" fill="#101114"/>'
        . '<rect x="24" y="16" width="252" height="390" rx="40" fill="url(#s)"/>'
        . '<rect x="24" y="16" width="252" height="390" rx="40" fill="url(#sh)"/>'
        . '<rect x="25.5" y="17.5" width="249" height="387" rx="38.5" fill="none" stroke="#fff" stroke-opacity="0.2" stroke-width="1.5"/>'
        . '<rect x="277" y="108" width="4" height="50" rx="2" fill="#000" opacity="0.28"/>'
        . '<rect x="19" y="92" width="4" height="30" rx="2" fill="#000" opacity="0.28"/><rect x="19" y="130" width="4" height="30" rx="2" fill="#000" opacity="0.28"/>'
        . '<circle cx="66" cy="56" r="16" fill="#05060a"/><circle cx="66" cy="56" r="11" fill="#1c1e24"/><circle cx="61" cy="51" r="3.5" fill="#fff" opacity="0.4"/>'
        . '<circle cx="98" cy="88" r="16" fill="#05060a"/><circle cx="98" cy="88" r="11" fill="#1c1e24"/><circle cx="93" cy="83" r="3.5" fill="#fff" opacity="0.4"/>'
        . '<circle cx="66" cy="88" r="6.5" fill="#eef1f5" opacity="0.85"/>'
        . '</svg>';
}

$activeAdminPage = 'products';
$pageTitle = $product ? 'تعديل منتج' : 'إضافة منتج';
require __DIR__ . '/../includes/admin-header.php';
?>

<div class="admin-topline"><h2><?= $product ? 'تعديل: ' . e($product['name']) : 'إضافة منتج جديد' ?></h2></div>

<?php if (!empty($errors['general'])): ?><div class="flash-msg flash-error" style="margin-bottom:16px"><?= e($errors['general']) ?></div><?php endif; ?>
<?php if (!empty($errors['variants'])): ?><div class="flash-msg flash-error" style="margin-bottom:16px"><?= e($errors['variants']) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="admin-card">
    <h3>بيانات المنتج</h3>
    <div class="form-row">
      <div class="form-group"><label>اسم المنتج <span class="req">*</span></label>
        <input type="text" name="name" value="<?= e($_POST['name'] ?? $product['name'] ?? '') ?>" placeholder="مثال: iPhone 15 Pro Max">
        <?php if (!empty($errors['name'])): ?><div class="field-error"><?= e($errors['name']) ?></div><?php endif; ?>
      </div>
      <div class="form-group"><label>الماركة <span class="req">*</span></label>
        <select name="brand_id" class="input">
          <option value="">اختر...</option>
          <?php foreach ($brands as $b): $bid = $_POST['brand_id'] ?? $product['brand_id'] ?? null; ?>
            <option value="<?= $b['id'] ?>" <?= $bid==$b['id']?'selected':'' ?>><?= e($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!empty($errors['brand_id'])): ?><div class="field-error"><?= e($errors['brand_id']) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="form-group"><label>وصف مختصر</label><input type="text" name="short_desc" value="<?= e($_POST['short_desc'] ?? $product['short_desc'] ?? '') ?>"></div>
    <div class="form-row">
      <div class="form-group"><label>الشاشة</label><input type="text" name="screen" value="<?= e($_POST['screen'] ?? $product['screen'] ?? '') ?>"></div>
      <div class="form-group"><label>المعالج</label><input type="text" name="processor" value="<?= e($_POST['processor'] ?? $product['processor'] ?? '') ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>الكاميرا</label><input type="text" name="camera" value="<?= e($_POST['camera'] ?? $product['camera'] ?? '') ?>"></div>
      <div class="form-group"><label>البطارية</label><input type="text" name="battery" value="<?= e($_POST['battery'] ?? $product['battery'] ?? '') ?>"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label>المقاومة</label><input type="text" name="resistance" value="<?= e($_POST['resistance'] ?? $product['resistance'] ?? '') ?>"></div>
      <div class="form-group"><label>الضمان (بالشهور)</label><input type="number" name="warranty_months" value="<?= e($_POST['warranty_months'] ?? $product['warranty_months'] ?? 24) ?>"></div>
    </div>
    <div class="form-group">
      <label>صورة المنتج</label>
      <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
      <div class="help">اختياري — لو ما اخترتش صورة، هيتولّد شكل مبدئي تلقائيًا يمكن استبداله لاحقًا بصورة حقيقية للمنتج.</div>
      <?php if (!empty($errors['image'])): ?><div class="field-error"><?= e($errors['image']) ?></div><?php endif; ?>
      <?php if ($product): ?><div class="thumb-sm" style="margin-top:8px"><img src="<?= BASE_URL . '/' . e($product['image_path']) ?>" alt=""></div><?php endif; ?>
    </div>
  </div>

  <div class="admin-card">
    <h3>النسخ (السعة / اللون / السعر / المخزون)</h3>
    <div class="hint">أضف نسخة لكل توليفة سعة ولون، بسعرها ومخزونها الخاص.</div>
    <div id="variantRows">
      <?php
      $rows = $variants ?: [null];
      foreach ($rows as $i => $v): ?>
        <div class="form-row variant-row" style="align-items:flex-end;border-bottom:1px solid var(--border);padding-bottom:12px;margin-bottom:12px">
          <input type="hidden" name="variants[<?= $i ?>][id]" value="<?= $v['id'] ?? '' ?>">
          <div class="form-group" style="min-width:110px"><label>السعة</label><input type="text" name="variants[<?= $i ?>][storage]" value="<?= e($v['storage'] ?? '') ?>" placeholder="256GB"></div>
          <div class="form-group" style="min-width:130px"><label>اللون</label><input type="text" name="variants[<?= $i ?>][color]" value="<?= e($v['color'] ?? '') ?>" placeholder="أسود"></div>
          <div class="form-group" style="min-width:110px"><label>السعر (<?= CURRENCY ?>)</label><input type="number" step="0.01" name="variants[<?= $i ?>][price]" value="<?= e($v['price'] ?? '') ?>"></div>
          <div class="form-group" style="min-width:90px"><label>المخزون</label><input type="number" name="variants[<?= $i ?>][stock]" value="<?= e($v['stock_quantity'] ?? 0) ?>"></div>
          <div class="form-group" style="min-width:140px"><label>SKU</label><input type="text" name="variants[<?= $i ?>][sku]" value="<?= e($v['sku'] ?? '') ?>"></div>
          <div class="form-group" style="min-width:90px">
            <label><input type="checkbox" name="variants[<?= $i ?>][delete]" value="1"> حذف</label>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-outline btn-sm" onclick="addVariantRow()">+ أضف نسخة أخرى</button>
  </div>

  <button type="submit" class="btn btn-primary">حفظ المنتج</button>
  <a href="<?= BASE_URL ?>/admin/products.php" class="btn btn-ghost">إلغاء</a>
</form>

<template id="variantTemplate">
  <div class="form-row variant-row" style="align-items:flex-end;border-bottom:1px solid var(--border);padding-bottom:12px;margin-bottom:12px">
    <input type="hidden" name="variants[__I__][id]" value="">
    <div class="form-group" style="min-width:110px"><label>السعة</label><input type="text" name="variants[__I__][storage]" placeholder="256GB"></div>
    <div class="form-group" style="min-width:130px"><label>اللون</label><input type="text" name="variants[__I__][color]" placeholder="أسود"></div>
    <div class="form-group" style="min-width:110px"><label>السعر (<?= CURRENCY ?>)</label><input type="number" step="0.01" name="variants[__I__][price]"></div>
    <div class="form-group" style="min-width:90px"><label>المخزون</label><input type="number" name="variants[__I__][stock]" value="0"></div>
    <div class="form-group" style="min-width:140px"><label>SKU</label><input type="text" name="variants[__I__][sku]"></div>
    <div class="form-group" style="min-width:90px"><label><input type="checkbox" name="variants[__I__][delete]" value="1"> حذف</label></div>
  </div>
</template>
<script>
let variantIndex = <?= count($rows) ?>;
function addVariantRow(){
  const tpl = document.getElementById('variantTemplate').innerHTML.replaceAll('__I__', variantIndex);
  document.getElementById('variantRows').insertAdjacentHTML('beforeend', tpl);
  variantIndex++;
}
</script>

<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
