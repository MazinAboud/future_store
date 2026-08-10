<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_role('admin'); // catalog management stays admin-only

$q = trim($_GET['q'] ?? '');
$brandFilter = (int)($_GET['brand'] ?? 0);

$where = [];
$params = [];
if ($q !== '') { $where[] = 'p.name LIKE ?'; $params[] = "%$q%"; }
if ($brandFilter) { $where[] = 'p.brand_id = ?'; $params[] = $brandFilter; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = db()->prepare("
    SELECT p.*, b.name AS brand_name,
           COUNT(pv.id) AS variant_count,
           MIN(pv.price) AS min_price, MAX(pv.price) AS max_price,
           COALESCE(SUM(pv.stock_quantity),0) AS total_stock
    FROM products p
    JOIN brands b ON b.id = p.brand_id
    LEFT JOIN product_variants pv ON pv.product_id = p.id
    $whereSql
    GROUP BY p.id ORDER BY p.created_at DESC
");
$stmt->execute($params);
$products = $stmt->fetchAll();
$brands = db()->query("
    SELECT b.*, COUNT(p.id) AS product_count
    FROM brands b LEFT JOIN products p ON p.brand_id = b.id
    GROUP BY b.id ORDER BY b.name
")->fetchAll();

$activeAdminPage = 'products';
$pageTitle = 'إدارة المنتجات';
require __DIR__ . '/../includes/admin-header.php';
?>

<div class="admin-topline">
  <h2>إدارة المنتجات (<?= count($products) ?>)</h2>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <details class="pw-reset">
      <summary class="btn btn-outline">إدارة الماركات</summary>
      <div style="margin-top:8px;min-width:250px">
        <form method="post" action="<?= BASE_URL ?>/admin/product-action.php" class="pw-reset-form" style="margin-bottom:4px">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_brand">
          <input type="text" name="brand_name" placeholder="اسم ماركة جديدة" required minlength="2">
          <button type="submit" class="btn btn-sm btn-primary">إضافة</button>
        </form>
        <?php foreach ($brands as $b): ?>
          <form method="post" action="<?= BASE_URL ?>/admin/product-action.php" style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:7px 0;border-top:1px solid var(--border)" onsubmit="return confirm('حذف هذه الماركة؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_brand">
            <input type="hidden" name="brand_id" value="<?= (int)$b['id'] ?>">
            <span style="font-size:13px"><?= e($b['name']) ?><?php if ($b['product_count'] > 0): ?><span class="text-muted" style="font-size:11px"> — <?= (int)$b['product_count'] ?> منتج</span><?php endif; ?></span>
            <button type="submit" class="btn btn-sm btn-danger">حذف</button>
          </form>
        <?php endforeach; ?>
      </div>
    </details>
    <a href="<?= BASE_URL ?>/admin/product-form.php" class="btn btn-primary">+ إضافة منتج جديد</a>
  </div>
</div>

<div class="toolbar">
  <form method="get" class="search-inline">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="ابحث بالاسم...">
    <select name="brand" class="input" onchange="this.form.submit()">
      <option value="0">كل الماركات</option>
      <?php foreach ($brands as $b): ?><option value="<?= $b['id'] ?>" <?= $brandFilter==$b['id']?'selected':'' ?>><?= e($b['name']) ?></option><?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-outline btn-sm">بحث</button>
  </form>
</div>

<div class="table-wrap">
  <table class="simple-table">
    <tr><th></th><th>الاسم</th><th>الماركة</th><th>النسخ</th><th>السعر</th><th>المخزون</th><th>الحالة</th><th></th></tr>
    <?php foreach ($products as $p): ?>
      <tr>
        <td><div class="thumb-sm"><img src="<?= BASE_URL . '/' . e($p['image_path']) ?>" alt=""></div></td>
        <td style="font-weight:700"><?= e($p['name']) ?></td>
        <td><?= e($p['brand_name']) ?></td>
        <td><?= (int)$p['variant_count'] ?></td>
        <td><?= $p['min_price'] == $p['max_price'] ? money($p['min_price']) : money($p['min_price']).' - '.money($p['max_price']) ?></td>
        <td><?= $p['total_stock'] == 0 ? '<span class="text-danger">نفد</span>' : (int)$p['total_stock'] ?></td>
        <td><?= $p['is_active'] ? status_badge('مفعّل', 'delivered', 'check') : status_badge('مخفي', 'rejected', 'x') ?></td>
        <td style="white-space:nowrap">
          <a href="<?= BASE_URL ?>/admin/product-form.php?id=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline">تعديل</a>
          <form method="post" action="<?= BASE_URL ?>/admin/product-action.php" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <input type="hidden" name="action" value="toggle_active">
            <button type="submit" class="btn btn-sm btn-ghost"><?= $p['is_active'] ? 'إخفاء' : 'تفعيل' ?></button>
          </form>
          <form method="post" action="<?= BASE_URL ?>/admin/product-action.php" style="display:inline" onsubmit="return confirm('حذف هذا المنتج نهائيًا؟')">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <input type="hidden" name="action" value="delete">
            <button type="submit" class="btn btn-sm btn-danger">حذف</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<?php require __DIR__ . '/../includes/admin-footer.php'; ?>
