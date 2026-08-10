<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/csrf.php';

require_role('customer');
$me = current_user();
$items = cart_items();

if (empty($items)) redirect('/cart.php');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $address = trim($_POST['address'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');

    if ($address === '') $errors['address'] = 'من فضلك أدخل عنوان التوصيل.';
    if ($phone === '' || !preg_match('/^[0-9+ ]{8,15}$/', $phone)) $errors['phone'] = 'أدخل رقم موبايل صحيح.';

    // re-validate stock right before committing, in case it changed
    $subtotal = 0;
    foreach ($items as $it) {
        if ($it['quantity'] > $it['stock_quantity']) {
            $errors['stock'] = 'الكمية المطلوبة من "' . $it['product_name'] . '" لم تعد متوفرة بالكامل. رجاءً حدّث السلة.';
        }
        $subtotal += $it['line_total'];
    }

    if (empty($errors)) {
        $pdo = db();
        try {
            $pdo->beginTransaction();

            $shipping = SHIPPING_FEE;
            $total = $subtotal + $shipping;

            // new orders rotate across active employees one at a time (see
            // includes/functions.php) so no single queue fills up while another sits empty
            $assignedTo = assign_next_employee();
            $stmt = $pdo->prepare("INSERT INTO orders (user_id, status, payment_method, payment_status, subtotal, shipping_fee, total, shipping_address, shipping_phone, assigned_to) VALUES (?, 'pending', 'cod', 'pending', ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$me['id'], $subtotal, $shipping, $total, $address, $phone, $assignedTo]);
            $orderId = $pdo->lastInsertId();

            $itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, variant_id, product_name, variant_desc, unit_price, quantity) VALUES (?,?,?,?,?,?)");
            foreach ($items as $it) {
                $desc = $it['storage'] . ' · ' . $it['color'];
                $itemStmt->execute([$orderId, $it['variant_id'], $it['product_name'], $desc, $it['price'], $it['quantity']]);
            }
            // Stock is NOT reserved at order time — the employee/admin checks live
            // availability and reserves it by accepting the order (see admin/order-action.php,
            // shared by both roles). This matches the required workflow:
            // "accept if stock available, reject if not".

            $pdo->commit();
            cart_clear();
            flash_set('success', 'تم استلام طلبك بنجاح! رقم الطلب #' . $orderId . '. سيقوم فريقنا بمراجعته قريبًا.');
            redirect('/account/orders.php');
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Checkout failed: ' . $e->getMessage());
            $errors['stock'] = 'تعذر إتمام الطلب، حاول مرة أخرى.';
        }
    }
}

$subtotal = cart_subtotal();
$shipping = SHIPPING_FEE;
$total = $subtotal + $shipping;

$pageTitle = 'إتمام الطلب';
require __DIR__ . '/includes/header.php';
?>

<div class="breadcrumb"><a href="<?= BASE_URL ?>/index.php">الرئيسية</a> &gt; <a href="<?= BASE_URL ?>/cart.php">السلة</a> &gt; <b>إتمام الطلب</b></div>

<div class="cart-layout">
  <div class="cart-items">
    <?php foreach ($items as $it): ?>
      <div class="cart-item">
        <div class="thumb"><img src="<?= BASE_URL . '/' . e($it['image_path']) ?>" alt=""></div>
        <div class="cart-item-info">
          <div class="name"><?= e($it['product_name']) ?> × <?= (int)$it['quantity'] ?></div>
          <div class="variant"><?= e($it['storage']) ?> · <?= e($it['color']) ?></div>
        </div>
        <div class="price"><?= money($it['line_total']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="summary-card">
    <h3 style="font-size:16px;margin-bottom:14px">ملخص الطلب</h3>
    <div class="summary-row"><span>المجموع الفرعي</span><span><?= money($subtotal) ?></span></div>
    <div class="summary-row"><span>التوصيل</span><span><?= money($shipping) ?></span></div>
    <div class="summary-row total"><span>الإجمالي</span><span><?= money($total) ?></span></div>

    <?php if (!empty($errors['stock'])): ?><div class="form-group"><div class="field-error"><?= e($errors['stock']) ?></div></div><?php endif; ?>

    <form method="post" style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border)">
      <?= csrf_field() ?>
      <div class="form-group">
        <label>عنوان التوصيل <span class="req">*</span></label>
        <input type="text" name="address" value="<?= e($_POST['address'] ?? $me['address']) ?>" placeholder="الحي، الشارع، المدينة">
        <?php if (!empty($errors['address'])): ?><div class="field-error"><?= e($errors['address']) ?></div><?php endif; ?>
      </div>
      <div class="form-group">
        <label>رقم الموبايل <span class="req">*</span></label>
        <input type="tel" name="phone" value="<?= e($_POST['phone'] ?? $me['phone']) ?>" placeholder="09xxxxxxxx">
        <?php if (!empty($errors['phone'])): ?><div class="field-error"><?= e($errors['phone']) ?></div><?php endif; ?>
      </div>
      <div class="form-group">
        <label>طريقة الدفع</label>
        <div class="pay-option"><span class="radio-dot"></span> كاش عند الاستلام</div>
      </div>
      <button type="submit" class="btn btn-primary btn-block">تأكيد الطلب</button>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
