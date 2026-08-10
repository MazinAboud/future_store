<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/order_state.php';

require_role('employee');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/staff/index.php');
csrf_verify();

$me = current_user();
$pdo = db();
$action = $_POST['action'] ?? '';

// Round-robin routes each new pending order to one employee at a time (see
// assign_next_employee() in functions.php). Without this check here, any
// employee could bypass the queue by POSTing directly to an order_id that
// was routed to someone else — the check keeps that enforced server-side,
// not just hidden from the UI.
$ownsOrder = function (int $orderId) use ($pdo, $me): bool {
    $chk = $pdo->prepare("SELECT 1 FROM orders WHERE id = ? AND (assigned_to = ? OR assigned_to IS NULL)");
    $chk->execute([$orderId, $me['id']]);
    return (bool)$chk->fetchColumn();
};

if ($action === 'accept') {
    $orderId = (int)$_POST['order_id'];
    if (!$ownsOrder($orderId)) {
        flash_set('error', "الطلب #$orderId مُسند لموظف آخر.");
        redirect('/staff/index.php');
    }
    try {
        $pdo->beginTransaction();
        $items = $pdo->prepare("SELECT variant_id, quantity FROM order_items WHERE order_id = ?");
        $items->execute([$orderId]);
        $items = $items->fetchAll();

        $dec = $pdo->prepare("UPDATE product_variants SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?");
        foreach ($items as $it) {
            // Same rule as admin/order-action.php: a NULL variant_id means the
            // product was removed from the catalogue after the order was placed,
            // so there is no stock row to reserve. Skipping keeps the order
            // acceptable instead of failing it as out of stock forever.
            if ($it['variant_id'] === null) {
                continue;
            }
            $dec->execute([$it['quantity'], $it['variant_id'], $it['quantity']]);
            if ($dec->rowCount() === 0) {
                throw new RuntimeException('OUT_OF_STOCK');
            }
        }
        $upd = $pdo->prepare("UPDATE orders SET status = 'confirmed', handled_by = ? WHERE id = ? AND status = 'pending'");
        $upd->execute([$me['id'], $orderId]);

        // Stock is already decremented above. If the order left 'pending' while
        // we held the item locks, committing would charge the same units twice
        // for one sale — abort so the rollback undoes the decrement too.
        if ($upd->rowCount() === 0) {
            throw new RuntimeException('NOT_PENDING');
        }

        order_log_event($pdo, $orderId, 'pending', 'confirmed', (int)$me['id'], $me['role']);
        $pdo->commit();
        flash_set('success', "تم قبول الطلب #$orderId وتأكيده.");
    } catch (Throwable $e) {
        $pdo->rollBack();
        $msg = $e->getMessage() === 'NOT_PENDING'
            ? "الطلب #$orderId لم يعد بانتظار المراجعة — عولج بالفعل."
            : "تعذر قبول الطلب #$orderId — المخزون لم يعد كافيًا لأحد المنتجات. جرّب رفض الطلب بدل ذلك.";
        flash_set('error', $msg);
    }

} elseif ($action === 'reject') {
    $orderId = (int)$_POST['order_id'];
    if (!$ownsOrder($orderId)) {
        flash_set('error', "الطلب #$orderId مُسند لموظف آخر.");
        redirect('/staff/index.php');
    }
    $reason = trim($_POST['reason'] ?? '');
    $stmt = $pdo->prepare("UPDATE orders SET status = 'rejected', rejection_reason = ?, handled_by = ? WHERE id = ? AND status = 'pending'");
    $stmt->execute([$reason ?: 'المخزون غير متوفر', $me['id'], $orderId]);
    if ($stmt->rowCount() > 0) {
        order_log_event($pdo, $orderId, 'pending', 'rejected', (int)$me['id'], $me['role'], $reason ?: null);
        flash_set('info', "تم رفض الطلب #$orderId.");
    } else {
        flash_set('error', "الطلب #$orderId لم يعد بانتظار المراجعة.");
    }

} elseif ($action === 'set_status') {
    $orderId = (int)$_POST['order_id'];
    // Same ownership gate as accept/reject. Without it an employee could advance
    // — or silently finalise — an order routed to a colleague by POSTing its id
    // directly, which the queue view only hides, never enforces.
    if (!$ownsOrder($orderId)) {
        flash_set('error', "الطلب #$orderId مُسند لموظف آخر.");
        redirect('/staff/index.php');
    }
    $status = $_POST['status'] ?? '';
    if (in_array($status, ['confirmed', 'processing', 'shipped', 'delivered'])) {
        // نفس آلة الحالات التي يفرضها المسار الإداري — مصدر واحد للقاعدة، فلا
        // يصبح أحد المسارين أضعف من الآخر بمرور الوقت.
        $cur = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
        $cur->execute([$orderId]);
        $currentStatus = (string)$cur->fetchColumn();

        if (!order_can_transition($currentStatus, $status)) {
            $allowed = order_next_states($currentStatus);
            flash_set('error', "لا يمكن نقل الطلب #$orderId من \"" . order_status_meta($currentStatus)['label'] . "\" إلى \"" . order_status_meta($status)['label'] . "\"."
                . ($allowed ? ' المسموح: ' . implode('، ', array_map(fn($s) => order_status_meta($s)['label'], $allowed)) . '.' : ' هذه حالة نهائية.'));
            redirect('/staff/index.php');
        }

        if ($currentStatus === $status) {
            flash_set('info', "الطلب #$orderId في هذه الحالة بالفعل.");
            redirect('/staff/index.php');
        }

        // "AND status = ?" pins the update to the state the transition was
        // checked against; the event is written only if that update landed, so
        // the trail can never claim a transition the order did not make.
        $extra = $status === 'delivered' ? ", delivered_at = NOW()" : "";
        $stmt = $pdo->prepare("UPDATE orders SET status = ?, handled_by = ?$extra WHERE id = ? AND status = ?");
        $stmt->execute([$status, $me['id'], $orderId, $currentStatus]);
        if ($stmt->rowCount() > 0) {
            order_log_event($pdo, $orderId, $currentStatus, $status, (int)$me['id'], $me['role']);
            flash_set('success', "تم تحديث حالة الطلب #$orderId.");
        } else {
            flash_set('error', "الطلب #$orderId تغيّرت حالته للتو — أعد تحميل الصفحة.");
        }
    }

} elseif ($action === 'confirm_payment') {
    $orderId = (int)$_POST['order_id'];
    // Marking money received is exactly the kind of action that must be pinned
    // to the assigned employee — otherwise anyone on staff could flip a
    // colleague's order to "paid".
    if (!$ownsOrder($orderId)) {
        flash_set('error', "الطلب #$orderId مُسند لموظف آخر.");
        redirect('/staff/index.php');
    }
    // نفس قاعدة المسار الإداري: نقد الاستلام لا يُسجَّل قبل التسليم.
    $cur = $pdo->prepare("SELECT status, payment_method, payment_status FROM orders WHERE id = ?");
    $cur->execute([$orderId]);
    $o = $cur->fetch();

    if (!$o) {
        flash_set('error', "الطلب #$orderId غير موجود.");
    } elseif ($o['payment_status'] === 'paid') {
        flash_set('info', "الطلب #$orderId مسجَّل كمدفوع بالفعل.");
    } elseif (!order_can_mark_paid($o['status'], $o['payment_method'])) {
        flash_set('error', "لا يمكن تأكيد سداد الطلب #$orderId قبل تسليمه.");
    } else {
        $pay = $pdo->prepare("UPDATE orders SET payment_status='paid' WHERE id = ? AND status='delivered' AND payment_status<>'paid'");
        $pay->execute([$orderId]);
        if ($pay->rowCount() > 0) {
            order_log_event($pdo, $orderId, null, 'payment_collected', (int)$me['id'], $me['role'], 'COD collected');
            flash_set('success', "تم تأكيد استلام السداد للطلب #$orderId.");
        } else {
            flash_set('error', "تعذر تسجيل السداد للطلب #$orderId — تحقق من حالته.");
        }
    }

} elseif ($action === 'maintenance_status') {
    $id = (int)$_POST['maintenance_id'];
    $status = $_POST['status'] ?? '';
    if (!employee_owns_maintenance($id, (int)$me['id'])) {
        flash_set('error', 'طلب الصيانة هذا مُسند لموظف آخر.');
        redirect('/staff/index.php');
    }
    if (in_array($status, ['new', 'in_progress', 'resolved'])) {
        if ($status === 'resolved') {
            $stmt = $pdo->prepare("UPDATE maintenance_requests SET status = ?, handled_by = ?, resolved_at = NOW() WHERE id = ?");
        } else {
            $stmt = $pdo->prepare("UPDATE maintenance_requests SET status = ?, handled_by = ? WHERE id = ?");
        }
        $stmt->execute([$status, $me['id'], $id]);
        flash_set('success', 'تم تحديث حالة طلب الصيانة.');
    }
}

// Maintenance actions come from the maintenance page, everything else from the
// staff dashboard. Chosen from a fixed list rather than echoed back from
// HTTP_REFERER — that header is set by the client, so trusting it here would
// let a crafted link bounce the employee off to an external site after acting.
redirect($action === 'maintenance_status' ? '/admin/maintenance.php' : '/staff/index.php');
