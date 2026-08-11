<?php
/**
 * POST /api/order-create.php
 *
 * Body: { items: [{variant_id, quantity}, ...], address, phone }
 * 201 -> { ok, order_id, subtotal, shipping_fee, total, message }
 *
 * ===========================================================================
 *  THE MOST IMPORTANT FILE IN THIS FOLDER
 * ===========================================================================
 * An order placed from the phone has to land in the database indistinguishable
 * from one placed in a browser, because the same employee opens both in
 * staff/index.php and the same reports count both. Four things make that true,
 * and each of them is a place where a mistake would go unnoticed for a long
 * time — the order would still be created, still appear, and only the numbers
 * would be quietly wrong:
 *
 *  1. SHIPPING_FEE comes from includes/config.php (8.00), never a constant
 *     retyped here. A hard-coded 0 would make every app order 8 cheaper than
 *     the identical web order, and the gap would surface months later as a
 *     revenue discrepancy nobody could trace.
 *
 *  2. assign_next_employee('orders') is CALLED, not reimplemented. It holds the
 *     rotation cursor with FOR UPDATE. Skipping it would write assigned_to =
 *     NULL, and unassigned orders fall through to "visible to everyone" — the
 *     round-robin that the whole staff workload report is built on would
 *     silently stop applying to app orders.
 *
 *  3. Prices are read from product_variants inside the transaction. The request
 *     supplies a variant id and a quantity and nothing else. A body carrying
 *     its own price field is not merely ignored, it is never looked at.
 *
 *  4. Stock is NOT deducted here. The website reserves stock when an employee
 *     ACCEPTS the order in admin/order-action.php, never at checkout, because
 *     the required workflow is that a human confirms availability. Deducting
 *     here would double-deduct the moment that employee accepts.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/throttle.php';

only('POST');

$me = require_customer();

// Idempotency (below) collapses a double tap into one order, but it does not
// stop a loop sending genuinely different baskets. Without a ceiling that
// fills the orders table and floods the employees' round-robin queues with
// work nobody placed. Keyed by account and by IP.
$wait = action_throttle_check('order_create', (string)$me['id']);
if ($wait > 0) {
    json_error('طلبات كثيرة في وقت قصير. حاول مرة أخرى بعد ' . throttle_wait_label($wait) . '.', 429);
}
action_throttle_hit('order_create', (string)$me['id']);

$address = body_str('address');
$phone   = body_str('phone');
$rawItems = body()['items'] ?? [];

$errors = [];
if ($address === '')          $errors['address'] = 'من فضلك أدخل عنوان التوصيل.';
if (mb_strlen($address) > 255) $errors['address'] = 'العنوان طويل جدًا (255 حرفًا كحد أقصى).';
if (!validate_phone($phone))  $errors['phone']   = 'أدخل رقم موبايل صحيح.';
if (!is_array($rawItems) || $rawItems === []) $errors['items'] = 'السلة فارغة.';

if ($errors) {
    json_error('من فضلك صحّح البيانات المميّزة.', 422, $errors);
}

/* --- collapse the request into variant_id => quantity ---------------------
   Summing duplicates rather than overwriting them: an app that sent the same
   variant on two lines means "three of these", not "one of these". */
$wanted = [];
foreach ($rawItems as $row) {
    if (!is_array($row)) continue;
    $vid = (int)($row['variant_id'] ?? 0);
    $qty = (int)($row['quantity'] ?? 0);
    if ($vid <= 0 || $qty <= 0) continue;
    $wanted[$vid] = ($wanted[$vid] ?? 0) + $qty;
}
if ($wanted === []) {
    json_error('السلة فارغة.', 422, ['items' => 'السلة فارغة.']);
}
foreach ($wanted as $vid => $qty) {
    if ($qty > 99) {
        json_error('الكمية القصوى لكل صنف 99.', 422, ['items' => 'الكمية القصوى لكل صنف 99.']);
    }
}

// Sorted by variant id so the rows of an order are written in a predictable
// sequence regardless of the order the app happened to put them in the body.
ksort($wanted);

/* --- idempotency ----------------------------------------------------------
   A phone loses signal after the server has already written the order, the app
   sees a timeout and retries — or the customer double-taps "confirm". Both
   produced TWO real orders for one basket (verified: ids 10287 and 10288 from
   two identical requests). The customer is then billed twice and stock is
   committed twice.

   Two ways to be safe here, and both are supported:

   1. An explicit key from the client (Idempotency-Key header or an
      idempotency_key body field). Honoured verbatim — the client decides what
      counts as "the same attempt".

   2. No key sent — which is the case for the Flutter build currently
      installed. The key is then derived from the request itself: who is
      ordering, exactly what, to which address, plus a 5-minute time bucket.
      Two identical submissions inside that window collapse to one order; the
      same basket ordered again tomorrow lands in a different bucket and is
      correctly treated as a new order. This protects the app already in the
      customer's hand without needing an app update.

   The UNIQUE(user_id, idempotency_key) index is what actually enforces this:
   two requests racing each other both pass the SELECT below, and the loser of
   the INSERT gets a duplicate-key error which is caught and answered with the
   winner's order. A check without the constraint would simply lose the race. */
$clientKey = '';
foreach (['HTTP_IDEMPOTENCY_KEY', 'HTTP_X_IDEMPOTENCY_KEY'] as $h) {
    if (!empty($_SERVER[$h])) { $clientKey = (string)$_SERVER[$h]; break; }
}
if ($clientKey === '') $clientKey = body_str('idempotency_key');

if ($clientKey !== '') {
    $idempotencyKey = hash('sha256', 'k:' . $me['id'] . '|' . $clientKey);
} else {
    $idempotencyKey = hash('sha256', implode('|', [
        'auto', $me['id'], json_encode($wanted), $address, $phone,
        intdiv(time(), 300), // 5-minute bucket
    ]));
}

/** Returns the already-created order for this key, shaped like a fresh success. */
function existing_order_response(PDO $pdo, int $orderId): never
{
    $o = $pdo->prepare('SELECT subtotal, shipping_fee, total FROM orders WHERE id = ?');
    $o->execute([$orderId]);
    $row = $o->fetch() ?: ['subtotal' => 0, 'shipping_fee' => 0, 'total' => 0];
    json_ok([
        'order_id'     => $orderId,
        'subtotal'     => round((float)$row['subtotal'], 2),
        'shipping_fee' => round((float)$row['shipping_fee'], 2),
        'total'        => round((float)$row['total'], 2),
        'currency'     => CURRENCY,
        'duplicate'    => true,
        'message'      => 'تم استلام طلبك بنجاح! رقم الطلب #' . $orderId . '. سيقوم فريقنا بمراجعته قريبًا.',
    ], 200);
}

/**
 * Abort a half-open order.
 *
 * json_error() ends the request with exit(), which does not unwind into the
 * catch block below, so a validation failure discovered after beginTransaction()
 * would leave the transaction open until the connection closed. MySQL rolls it
 * back at that point anyway, but "anyway" is not a design — an explicit
 * rollback releases the rotation-cursor lock immediately instead of holding it
 * for the tail of the request, and it keeps the intent readable.
 */
function order_fail(PDO $pdo, string $message, int $status, array $fields = []): never
{
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_error($message, $status, $fields);
}

$pdo = db();

// المسار السريع: هل عولج هذا الطلب بالفعل؟ (يوفّر فتح معاملة بلا داعٍ)
$dupe = $pdo->prepare('SELECT id FROM orders WHERE user_id = ? AND idempotency_key = ? LIMIT 1');
$dupe->execute([$me['id'], $idempotencyKey]);
if ($existingId = $dupe->fetchColumn()) {
    existing_order_response($pdo, (int)$existingId);
}

$pdo->beginTransaction();

try {
    /* --- read the authoritative rows --------------------------------------
       p.is_active = 1 repeats the rule cart_items() applies on the website: a
       product the admin hid must not be orderable even if it is still sitting
       in someone's phone cart.

       Deliberately NOT "FOR UPDATE", after writing it that way first and then
       taking it back out. The reasoning, because the instinct to lock here is
       a strong one:

       Locking would hold these variant rows for the length of the transaction
       so stock could not move underneath the check below. But stock is not
       deducted at order time in this system — the employee reserves it when
       they accept, using a conditional UPDATE ... WHERE stock_quantity >= ?
       in admin/order-action.php that cannot over-sell no matter what happened
       earlier. The check below is advisory: it exists to give the customer a
       useful message now, not to reserve anything.

       So the lock bought nothing real, and it cost something real. This
       endpoint would have taken locks on several variant rows and then on the
       assignment_rotation row, while a concurrent employee acceptance takes
       locks on the same variant rows in its own order — two transactions
       acquiring overlapping row locks in different sequences, which is the
       textbook shape of a deadlock. Rare, timing-dependent, and it would have
       surfaced as an occasional unexplained "تعذر إتمام الطلب".

       Reading without the lock leaves this endpoint doing exactly what
       checkout.php does, and leaves the real guarantee where the website
       already put it. */
    $in = implode(',', array_fill(0, count($wanted), '?'));
    $stmt = $pdo->prepare("
        SELECT pv.id, pv.price, pv.stock_quantity, pv.storage, pv.color,
               p.name AS product_name, p.is_active
        FROM product_variants pv
        JOIN products p ON p.id = pv.product_id
        WHERE pv.id IN ($in) AND p.is_active = 1
        ORDER BY pv.id
    ");
    $stmt->execute(array_keys($wanted));

    $found = [];
    foreach ($stmt->fetchAll() as $r) { $found[(int)$r['id']] = $r; }

    /* --- validate every line before writing anything ----------------------
       This is stricter than the website on purpose, and the difference is
       worth stating plainly. On the site, cart_items() silently trims the
       quantity down to whatever stock remains, so a customer who asked for
       five and can have two receives two without being told — and if stock hit
       zero the trimmed quantity becomes 0 and checkout.php writes a
       zero-quantity line into the order. The app has a real cart on screen, so
       it can be told the truth: the order is refused, the available number
       comes back with the error, and the customer decides. Nothing about the
       website changes; this endpoint is simply not repeating its shortcut. */
    $lines = [];
    $subtotal = 0.0;
    foreach ($wanted as $vid => $qty) {
        if (!isset($found[$vid])) {
            order_fail($pdo, 'أحد المنتجات في السلة لم يعد متاحًا. رجاءً حدّث السلة.', 409, [
                'items' => 'أحد المنتجات في السلة لم يعد متاحًا.',
                'variant_id' => (string)$vid,
            ]);
        }
        $v = $found[$vid];
        $stock = (int)$v['stock_quantity'];

        if ($stock <= 0) {
            order_fail($pdo, 'نفدت الكمية من "' . $v['product_name'] . '". رجاءً احذفه من السلة.', 409, [
                'items'      => 'نفدت الكمية من "' . $v['product_name'] . '".',
                'variant_id' => (string)$vid,
                'available'  => '0',
            ]);
        }
        if ($qty > $stock) {
            order_fail($pdo, 'الكمية المطلوبة من "' . $v['product_name'] . '" لم تعد متوفرة بالكامل. المتاح ' . $stock . '.', 409, [
                'items'      => 'المتاح من "' . $v['product_name'] . '" هو ' . $stock . ' فقط.',
                'variant_id' => (string)$vid,
                'available'  => (string)$stock,
            ]);
        }

        // (float) on a DECIMAL(10,2) column, which PDO hands back as a string.
        $price = (float)$v['price'];
        $subtotal += $price * $qty;

        $lines[] = [
            'variant_id'   => $vid,
            'product_name' => $v['product_name'],
            'variant_desc' => $v['storage'] . ' · ' . $v['color'], // same separator checkout.php uses
            'unit_price'   => $price,
            'quantity'     => $qty,
        ];
    }

    $shipping = (float)SHIPPING_FEE;
    $total    = $subtotal + $shipping;

    $assignedTo = assign_next_employee('orders');

    $pdo->prepare("
        INSERT INTO orders
            (user_id, status, payment_method, payment_status,
             subtotal, shipping_fee, total, shipping_address, shipping_phone, assigned_to, idempotency_key)
        VALUES (?, 'pending', 'cod', 'pending', ?, ?, ?, ?, ?, ?, ?)
    ")->execute([$me['id'], $subtotal, $shipping, $total, $address, $phone, $assignedTo, $idempotencyKey]);

    $orderId = (int)$pdo->lastInsertId();

    // product_name and unit_price are copied into the row rather than joined
    // later, exactly as checkout.php does, so renaming a phone or repricing it
    // next month does not rewrite what this customer actually bought today.
    $itemStmt = $pdo->prepare("
        INSERT INTO order_items (order_id, variant_id, product_name, variant_desc, unit_price, quantity)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($lines as $l) {
        $itemStmt->execute([
            $orderId, $l['variant_id'], $l['product_name'],
            $l['variant_desc'], $l['unit_price'], $l['quantity'],
        ]);
    }

    // Still no stock deduction. See point 4 in the header comment.

    $pdo->commit();

} catch (PDOException $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // 23000 on uniq_order_idem means a concurrent request for the SAME basket
    // won the race. That is not an error for the customer — the order they
    // asked for exists. Answer with it instead of creating a second one.
    if ($ex->getCode() === '23000') {
        $again = $pdo->prepare('SELECT id FROM orders WHERE user_id = ? AND idempotency_key = ? LIMIT 1');
        $again->execute([$me['id'], $idempotencyKey]);
        if ($winner = $again->fetchColumn()) {
            existing_order_response($pdo, (int)$winner);
        }
    }
    error_log('API order create failed: ' . $ex->getMessage());
    json_error('تعذر إتمام الطلب، حاول مرة أخرى.', 500);
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('API order create failed: ' . $ex->getMessage());
    json_error('تعذر إتمام الطلب، حاول مرة أخرى.', 500);
}

json_ok([
    'order_id'     => $orderId,
    'subtotal'     => round($subtotal, 2),
    'shipping_fee' => round($shipping, 2),
    'total'        => round($total, 2),
    'currency'     => CURRENCY,
    'message'      => 'تم استلام طلبك بنجاح! رقم الطلب #' . $orderId . '. سيقوم فريقنا بمراجعته قريبًا.',
], 201);
