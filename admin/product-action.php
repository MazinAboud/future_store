<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/cache.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/admin/products.php');
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';

if ($action === 'toggle_active') {
    // The name is read BEFORE the change: after a delete there is no row left
    // to look it up from, and an audit line that says "product #1028" and
    // nothing else is not much use to whoever reads it months later.
    $nameChk = db()->prepare("SELECT name, is_active FROM products WHERE id = ?");
    $nameChk->execute([$id]);
    $before = $nameChk->fetch();

    db()->prepare("UPDATE products SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
    if ($before) {
        admin_log('toggle_product', 'product', $id,
            ($before['is_active'] ? 'إخفاء' : 'إظهار') . ' المنتج: ' . $before['name']);
    }
    flash_set('success', 'تم تحديث حالة المنتج.');
} elseif ($action === 'delete') {
    // The intent here has always been "hide a product that has been sold rather
    // than destroy it" — but waiting for a foreign-key exception never triggered
    // it. order_items.variant_id is ON DELETE SET NULL, so the DELETE succeeds,
    // takes the variants with it, and leaves past order lines pointing at
    // nothing. The catch block was unreachable. Proven by deleting a product
    // with three sales: it vanished and the flash said "تم حذف المنتج".
    // The rule has to be checked before the delete, not inferred from its failure.
    $sold = db()->prepare("SELECT COUNT(*) FROM order_items oi
                             JOIN product_variants pv ON pv.id = oi.variant_id
                            WHERE pv.product_id = ?");
    $sold->execute([$id]);
    $soldCount = (int)$sold->fetchColumn();

    $pChk = db()->prepare("SELECT name FROM products WHERE id = ?");
    $pChk->execute([$id]);
    $pName = (string)($pChk->fetchColumn() ?: ('#' . $id));

    if ($soldCount > 0) {
        db()->prepare("UPDATE products SET is_active = 0 WHERE id = ?")->execute([$id]);
        admin_log('hide_product', 'product', $id, "إخفاء \"$pName\" بدل حذفه — له $soldCount سطر مبيعات");
        flash_set('info', 'هذا المنتج له طلبات سابقة فلا يمكن حذفه نهائيًا — تم إخفاؤه من المتجر بدل الحذف حفاظًا على سجل المبيعات.');
    } else {
        try {
            db()->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
            admin_log('delete_product', 'product', $id, "حذف المنتج \"$pName\" نهائيًا (بلا مبيعات)");
            flash_set('success', 'تم حذف المنتج.');
        } catch (PDOException $e) {
            // أي ارتباط آخر لم نتوقعه: أخفِ بدل أن تفشل بصمت
            db()->prepare("UPDATE products SET is_active = 0 WHERE id = ?")->execute([$id]);
            admin_log('hide_product', 'product', $id, "تعذّر حذف \"$pName\" لارتباطه بسجلات أخرى — أُخفي");
            flash_set('info', 'تعذّر حذف المنتج نهائيًا لارتباطه بسجلات أخرى — تم إخفاؤه من المتجر.');
        }
    }
} elseif ($action === 'add_brand') {
    $name = trim($_POST['brand_name'] ?? '');
    if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
        flash_set('error', 'اسم الماركة لازم يكون بين 2 و60 حرفًا.');
    } else {
        $chk = db()->prepare("SELECT 1 FROM brands WHERE name = ?");
        $chk->execute([$name]);
        if ($chk->fetchColumn()) {
            flash_set('error', 'هذه الماركة موجودة بالفعل.');
        } else {
            $base = make_slug($name);
            $slug = $base;
            $n = 2;
            $chkSlug = db()->prepare("SELECT COUNT(*) FROM brands WHERE slug = ?");
            while (true) {
                $chkSlug->execute([$slug]);
                if (!$chkSlug->fetchColumn()) break;
                $slug = $base . '-' . $n++;
            }
            db()->prepare("INSERT INTO brands (name, slug) VALUES (?, ?)")->execute([$name, $slug]);
            admin_log("add_brand", "brand", (int)db()->lastInsertId(), "إضافة ماركة: " . $name);
            flash_set('success', 'تمت إضافة ماركة "' . $name . '" بنجاح.');
        }
    }
} elseif ($action === 'update_stock') {
    // Inline stock correction from the inventory reports, so the admin can fix a
    // low/wrong count where they spot it instead of hunting for the product form.
    $variantId = (int)($_POST['variant_id'] ?? 0);
    $raw = $_POST['stock_quantity'] ?? '';
    if (!preg_match('/^\d{1,6}$/', (string)$raw)) {
        flash_set('error', 'الكمية يجب أن تكون رقمًا صحيحًا بين 0 و999999.');
    } else {
        $stock = (int)$raw;
        $chk = db()->prepare("SELECT p.name, pv.storage, pv.color, pv.stock_quantity FROM product_variants pv JOIN products p ON p.id = pv.product_id WHERE pv.id = ?");
        $chk->execute([$variantId]);
        $v = $chk->fetch();
        if (!$v) {
            flash_set('error', 'النسخة المطلوبة غير موجودة.');
        } else {
            db()->prepare("UPDATE product_variants SET stock_quantity = ? WHERE id = ?")->execute([$stock, $variantId]);
            admin_log("update_stock", "variant", $variantId,
                $v["name"] . " (" . $v["storage"] . " · " . $v["color"] . "): " . (int)$v["stock_quantity"] . " ← " . $stock);
            $msg = 'تم تحديث مخزون ' . $v['name'] . ' (' . $v['storage'] . ' · ' . $v['color'] . ') إلى ' . $stock . ' قطعة.';

            // Restocking from zero is exactly when the "notify me when available"
            // list becomes actionable — surface it here instead of leaving those
            // customers waiting on a record nobody ever looks at.
            if ((int)$v['stock_quantity'] === 0 && $stock > 0) {
                $wait = db()->prepare("SELECT COUNT(*) FROM stock_notifications WHERE variant_id = ? AND notified_at IS NULL");
                $wait->execute([$variantId]);
                $n = (int)$wait->fetchColumn();
                if ($n > 0) {
                    $msg .= ' تنبيه: يوجد ' . $n . ' عميل سجّلوا طلب إشعار عند توفر هذه النسخة.';
                }
            }
            flash_set('success', $msg);
        }
    }
    // This branch returns to a different page, so it exits before the shared
    // invalidation at the bottom of the file. Stock feeds the API's total_stock,
    // so it has to drop the entry on its own way out.
    cache_forget(CACHE_KEY_API_BEST_SELLERS);
    redirect('/admin/reports.php');
} elseif ($action === 'delete_brand') {
    $brandId = (int)($_POST['brand_id'] ?? 0);
    $count = db()->prepare("SELECT COUNT(*) FROM products WHERE brand_id = ?");
    $count->execute([$brandId]);
    $bChk = db()->prepare("SELECT name FROM brands WHERE id = ?");
    $bChk->execute([$brandId]);
    $bName = (string)($bChk->fetchColumn() ?: ('#' . $brandId));
    if ($count->fetchColumn() > 0) {
        flash_set('error', 'لا يمكن حذف هذه الماركة لأنها مرتبطة بمنتجات موجودة. احذف هذه المنتجات أو غيّر ماركتها أولًا.');
    } else {
        try {
            db()->prepare("DELETE FROM brands WHERE id = ?")->execute([$brandId]);
            admin_log('delete_brand', 'brand', $brandId, 'حذف الماركة: ' . $bName);
            flash_set('success', 'تم حذف الماركة بنجاح.');
        } catch (PDOException $e) {
            flash_set('error', 'تعذر حذف الماركة، حاول مرة أخرى.');
        }
    }
}
// Any of the branches above can change what the API homepage reports:
// a product hidden, deleted, restocked or renamed. Without this the app
// keeps showing the old list for up to ten minutes and looks broken.
cache_forget(CACHE_KEY_API_BEST_SELLERS);
redirect('/admin/products.php');
