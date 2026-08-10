<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/order_state.php';
require_any_role(['admin', 'employee']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/admin/orders.php');
csrf_verify();

$me = current_user();
$pdo = db();
$action = $_POST['action'] ?? '';
$orderId = (int)($_POST['order_id'] ?? 0);

// Employees share this admin page (see the nav in includes/admin-header.php),
// but the round-robin model means an employee may act only on orders routed to
// them — exactly the rule staff/order-action.php and admin/maintenance-action.php
// already enforce, and the same scope the employee's order badge is counted
// under. Admins are unrestricted. Without this gate an employee could accept,
// reject, re-status, or mark-paid any colleague's order simply by POSTing its
// id here, bypassing the ownership check that the staff endpoint applies.
if (has_role('employee')) {
    $own = $pdo->prepare("SELECT 1 FROM orders WHERE id = ? AND (assigned_to = ? OR assigned_to IS NULL)");
    $own->execute([$orderId, $me['id']]);
    if (!$own->fetchColumn()) {
        flash_set('error', "الطلب #$orderId مُسند لموظف آخر.");
        redirect('/admin/orders.php');
    }
}

if ($action === 'accept') {
    try {
        $pdo->beginTransaction();
        $items = $pdo->prepare("SELECT variant_id, quantity FROM order_items WHERE order_id = ?");
        $items->execute([$orderId]);
        $items = $items->fetchAll();

        $dec = $pdo->prepare("UPDATE product_variants SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?");
        foreach ($items as $it) {
            // variant_id is NULL when the product was removed from the catalogue
            // after this order was placed (order_items.variant_id is ON DELETE
            // SET NULL so the order keeps its own name/price snapshot). There is
            // no stock row left to reserve, so skip the decrement instead of
            // treating it as out of stock — otherwise a pending order for a
            // discontinued item could never be accepted OR rejected cleanly.
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

        // The stock rows above are already decremented inside this transaction.
        // If the order is no longer 'pending' — a colleague accepted it while we
        // were holding the item locks — committing here would subtract the same
        // units a second time for a single sale. Aborting rolls the decrement
        // back with everything else.
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
    $status = $_POST['status'] ?? '';
    $valid = ['pending','confirmed','processing','shipped','delivered','rejected','cancelled'];
    if (in_array($status, $valid)) {
        $current = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
        $current->execute([$orderId]);
        $currentStatus = (string)$current->fetchColumn();

        // البوابة الجوهرية: الحالة الحالية تحكم ما يجوز بعدها.
        // بدونها كان مسموحًا بـ delivered -> pending، ثم "قبول" الطلب ثانيةً،
        // فيُخصم المخزون مرتين عن بيعة واحدة (أُثبت باختبار حي).
        if (!order_can_transition($currentStatus, $status)) {
            $allowed = order_next_states($currentStatus);
            flash_set('error', "لا يمكن نقل الطلب #$orderId من \"" . order_status_meta($currentStatus)['label'] . "\" إلى \"" . order_status_meta($status)['label'] . "\"."
                . ($allowed ? ' المسموح: ' . implode('، ', array_map(fn($s) => order_status_meta($s)['label'], $allowed)) . '.' : ' هذه حالة نهائية.'));
            redirect('/admin/orders.php');
        }

        // Re-submitting the current status is a no-op, not a failure. Treat it
        // as one explicitly: MySQL reports 0 affected rows when an UPDATE writes
        // identical values, which the guards below would otherwise read as a
        // lost race.
        if ($currentStatus === $status) {
            flash_set('info', "الطلب #$orderId في هذه الحالة بالفعل.");
            redirect('/admin/orders.php');
        }

        $wasPending = $currentStatus === 'pending';

        // Leaving 'pending' for a status that means the sale is going ahead must
        // decrement stock exactly once — mirror the employee accept flow so an
        // admin overriding status directly can never bypass that safety check.
        if ($wasPending && in_array($status, ['confirmed','processing','shipped','delivered'])) {
            try {
                $pdo->beginTransaction();
                $items = $pdo->prepare("SELECT variant_id, quantity FROM order_items WHERE order_id = ?");
                $items->execute([$orderId]);
                $dec = $pdo->prepare("UPDATE product_variants SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?");
                foreach ($items->fetchAll() as $it) {
                    // Same NULL case as the accept branch: a discontinued variant
                    // has no stock row, and "WHERE id = NULL" matches nothing —
                    // which would be misread as out-of-stock and freeze the order.
                    if ($it['variant_id'] === null) {
                        continue;
                    }
                    $dec->execute([$it['quantity'], $it['variant_id'], $it['quantity']]);
                    if ($dec->rowCount() === 0) throw new RuntimeException('OUT_OF_STOCK');
                }
                $extra = $status === 'delivered' ? ", delivered_at = NOW()" : "";
                $upd = $pdo->prepare("UPDATE orders SET status=?, handled_by=?$extra WHERE id=? AND status='pending'");
                $upd->execute([$status, $me['id'], $orderId]);
                if ($upd->rowCount() === 0) throw new RuntimeException('NOT_PENDING');

                // Logged inside the transaction on purpose. order_events is InnoDB,
                // so a rollback takes the row with it. Logging after the catch —
                // as this did — wrote an audit entry for a transition that never
                // happened: order #10271 was recorded as pending -> confirmed
                // while the row stayed 'pending' because stock ran out.
                order_log_event($pdo, $orderId, $currentStatus, $status, (int)$me['id'], $me['role']);
                $pdo->commit();
                flash_set('success', "تم تحديث حالة الطلب #$orderId.");
            } catch (Throwable $e) {
                $pdo->rollBack();
                $msg = $e->getMessage() === 'NOT_PENDING'
                    ? "الطلب #$orderId تغيّرت حالته للتو — أعد تحميل الصفحة."
                    : "تعذر التحديث — المخزون لم يعد كافيًا لأحد المنتجات في الطلب #$orderId.";
                flash_set('error', $msg);
            }
        } else {
            $extra = $status === 'delivered' ? ", delivered_at = NOW()" : "";
            // "AND status = ?" pins the row to the state the transition was
            // validated against, so two concurrent updates cannot both apply.
            $upd = $pdo->prepare("UPDATE orders SET status=?, handled_by=?$extra WHERE id=? AND status=?");
            $upd->execute([$status, $me['id'], $orderId, $currentStatus]);
            if ($upd->rowCount() > 0) {
                order_log_event($pdo, $orderId, $currentStatus, $status, (int)$me['id'], $me['role']);
                flash_set('success', "تم تحديث حالة الطلب #$orderId.");
            } else {
                flash_set('error', "الطلب #$orderId تغيّرت حالته للتو — أعد تحميل الصفحة.");
            }
        }
    }
} elseif ($action === 'confirm_payment') {
    // الدفع عند الاستلام يعني نقدًا في اليد. وسم طلب لم يُسلَّم بأنه مدفوع كان
    // ممكنًا (أُثبت على طلب بحالة pending)، وهو يضخّم الإيراد المُحصَّل في
    // التقارير بمبالغ لم تدخل الصندوق.
    $cur = $pdo->prepare("SELECT status, payment_method, payment_status FROM orders WHERE id = ?");
    $cur->execute([$orderId]);
    $o = $cur->fetch();

    if (!$o) {
        flash_set('error', "الطلب #$orderId غير موجود.");
    } elseif ($o['payment_status'] === 'paid') {
        flash_set('info', "الطلب #$orderId مسجَّل كمدفوع بالفعل.");
    } elseif (!order_can_mark_paid($o['status'], $o['payment_method'])) {
        flash_set('error', "لا يمكن تأكيد سداد الطلب #$orderId قبل تسليمه — الدفع عند الاستلام يُحصَّل عند التسليم.");
    } else {
        $pay = $pdo->prepare("UPDATE orders SET payment_status='paid' WHERE id=? AND status='delivered' AND payment_status<>'paid'");
        $pay->execute([$orderId]);
        if ($pay->rowCount() > 0) {
            order_log_event($pdo, $orderId, null, 'payment_collected', (int)$me['id'], $me['role'], 'COD collected');
            flash_set('success', "تم تأكيد السداد للطلب #$orderId.");
        } else {
            flash_set('error', "تعذر تسجيل السداد للطلب #$orderId — تحقق من حالته.");
        }
    }
}

redirect('/admin/orders.php');
