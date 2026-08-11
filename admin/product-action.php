<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/admin/products.php');
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';

if ($action === 'toggle_active') {
    db()->prepare("UPDATE products SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
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

    if ((int)$sold->fetchColumn() > 0) {
        db()->prepare("UPDATE products SET is_active = 0 WHERE id = ?")->execute([$id]);
        flash_set('info', 'هذا المنتج له طلبات سابقة فلا يمكن حذفه نهائيًا — تم إخفاؤه من المتجر بدل الحذف حفاظًا على سجل المبيعات.');
    } else {
        try {
            db()->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
            flash_set('success', 'تم حذف المنتج.');
        } catch (PDOException $e) {
            // أي ارتباط آخر لم نتوقعه: أخفِ بدل أن تفشل بصمت
            db()->prepare("UPDATE products SET is_active = 0 WHERE id = ?")->execute([$id]);
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
    redirect('/admin/reports.php');
} elseif ($action === 'delete_brand') {
    $brandId = (int)($_POST['brand_id'] ?? 0);
    $count = db()->prepare("SELECT COUNT(*) FROM products WHERE brand_id = ?");
    $count->execute([$brandId]);
    if ($count->fetchColumn() > 0) {
        flash_set('error', 'لا يمكن حذف هذه الماركة لأنها مرتبطة بمنتجات موجودة. احذف هذه المنتجات أو غيّر ماركتها أولًا.');
    } else {
        try {
            db()->prepare("DELETE FROM brands WHERE id = ?")->execute([$brandId]);
            flash_set('success', 'تم حذف الماركة بنجاح.');
        } catch (PDOException $e) {
            flash_set('error', 'تعذر حذف الماركة، حاول مرة أخرى.');
        }
    }
}
redirect('/admin/products.php');
