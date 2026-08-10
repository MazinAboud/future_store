<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/admin/users.php');
csrf_verify();

$action = $_POST['action'] ?? '';

if ($action === 'create_employee') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    if (mb_strlen($fullName) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($password) < 8) {
        flash_set('error', 'تأكد من صحة كل البيانات (كلمة السر 8 أحرف على الأقل).');
    } else {
        $chk = db()->prepare("SELECT 1 FROM users WHERE email = ?");
        $chk->execute([$email]);
        if ($chk->fetchColumn()) {
            flash_set('error', 'البريد الإلكتروني مستخدم بالفعل.');
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            db()->prepare("INSERT INTO users (role, full_name, email, phone, password_hash) VALUES ('employee',?,?,?,?)")
                ->execute([$fullName, $email, $phone, $hash]);
            flash_set('success', "تم إنشاء حساب الموظف $fullName بنجاح.");
        }
    }
    redirect('/admin/users.php?tab=employees');
}

if ($action === 'toggle_active') {
    $id = (int)($_POST['id'] ?? 0);
    $me = current_user();
    $target = db()->prepare("SELECT role, is_active FROM users WHERE id = ?");
    $target->execute([$id]);
    $targetUser = $target->fetch();

    // this control only ever renders for customer/employee rows, but the
    // server enforces the same limit regardless of what a request claims —
    // an admin account can never be toggled through this action, self included
    if ($id === $me['id'] || !$targetUser || $targetUser['role'] === 'admin') {
        flash_set('error', 'لا يمكن تنفيذ هذا الإجراء على هذا الحساب.');
    } else {
        db()->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?")->execute([$id]);

        // an employee going from active -> inactive must hand off every order
        // still open on their queue, so nothing sits stuck behind a stopped account.
        // $targetUser['is_active'] here is the value BEFORE the toggle above, so
        // "was active" means it just flipped to inactive.
        if ($targetUser['role'] === 'employee' && $targetUser['is_active']) {
            reassign_employee_work($id);
        }
        flash_set('success', 'تم تحديث حالة الحساب.');
    }
}

if ($action === 'reset_password') {
    $id = (int)($_POST['id'] ?? 0);
    $newPassword = $_POST['new_password'] ?? '';
    $me = current_user();
    $target = db()->prepare("SELECT role FROM users WHERE id = ?");
    $target->execute([$id]);
    $targetRole = $target->fetchColumn();

    // same server-side limit as toggle_active: only customer/employee accounts,
    // never admins (including the acting admin's own account — use the profile
    // page's change-password flow for that, which requires the current password)
    if ($id === $me['id'] || !in_array($targetRole, ['customer', 'employee'], true)) {
        flash_set('error', 'لا يمكن تنفيذ هذا الإجراء على هذا الحساب.');
    } elseif (mb_strlen($newPassword) < 8) {
        flash_set('error', 'كلمة السر يجب أن تكون 8 أحرف على الأقل.');
    } else {
        db()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($newPassword, PASSWORD_BCRYPT), $id]);
        flash_set('success', 'تم تغيير كلمة السر بنجاح.');
    }
}

if ($action === 'edit_user') {
    $id       = (int)($_POST['id'] ?? 0);
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $address  = trim($_POST['address'] ?? '');
    $me = current_user();

    $target = db()->prepare("SELECT role FROM users WHERE id = ?");
    $target->execute([$id]);
    $targetRole = $target->fetchColumn();

    // Same limit as every other action on this page: an admin row can never be
    // edited from here (self included) — admins change their own details on
    // /admin/profile.php, which re-checks the current password.
    if ($id === $me['id'] || !in_array($targetRole, ['customer', 'employee'], true)) {
        flash_set('error', 'لا يمكن تنفيذ هذا الإجراء على هذا الحساب.');
    } elseif (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 120) {
        flash_set('error', 'الاسم يجب أن يكون بين 3 و120 حرفًا.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
        flash_set('error', 'البريد الإلكتروني غير صحيح.');
    } elseif ($phone !== '' && !preg_match('/^[0-9+ ]{8,15}$/', $phone)) {
        flash_set('error', 'رقم الموبايل غير صحيح.');
    } elseif (mb_strlen($address) > 255) {
        flash_set('error', 'العنوان طويل جدًا (255 حرفًا كحد أقصى).');
    } else {
        // email is UNIQUE in the schema — check before the UPDATE so the admin
        // gets a clear Arabic message instead of a raw duplicate-key failure
        $dup = db()->prepare("SELECT 1 FROM users WHERE email = ? AND id <> ?");
        $dup->execute([$email, $id]);
        if ($dup->fetchColumn()) {
            flash_set('error', 'هذا البريد الإلكتروني مستخدم بحساب آخر.');
        } else {
            db()->prepare("UPDATE users SET full_name = ?, email = ?, phone = ?, address = ? WHERE id = ?")
                ->execute([$fullName, $email, $phone !== '' ? $phone : null, $address !== '' ? $address : null, $id]);
            flash_set('success', 'تم تحديث بيانات الحساب بنجاح.');
        }
    }
}

if ($action === 'delete_user') {
    $id = (int)($_POST['id'] ?? 0);
    $me = current_user();
    $target = db()->prepare("SELECT role, full_name FROM users WHERE id = ?");
    $target->execute([$id]);
    $targetUser = $target->fetch();

    if ($id === $me['id'] || !$targetUser || $targetUser['role'] === 'admin') {
        flash_set('error', 'لا يمكن حذف هذا الحساب.');
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();

            // A deleted employee's open orders AND maintenance requests go back
            // into their rotations FIRST, while the employee row still exists —
            // otherwise that work would just lose its owner and sit unassigned.
            // Deactivating before the hand-off matters: assign_next_employee()
            // only picks from active employees, so without this the rotation
            // could hand the work straight back to the account being deleted.
            if ($targetUser['role'] === 'employee') {
                $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$id]);
                reassign_employee_work($id);
            }

            // Every table in the schema that points at users(id) is RESTRICT
            // (no ON DELETE CASCADE), so each reference has to be cleared by
            // hand or the final DELETE fails with a foreign-key error.

            // 1) references made BY other people's rows -> null them out, since
            //    that history (who handled an order) is not the deleted user's data
            $pdo->prepare("UPDATE orders SET handled_by = NULL WHERE handled_by = ?")->execute([$id]);
            $pdo->prepare("UPDATE orders SET assigned_to = NULL WHERE assigned_to = ?")->execute([$id]);
            $pdo->prepare("UPDATE maintenance_requests SET handled_by = NULL WHERE handled_by = ?")->execute([$id]);
            $pdo->prepare("UPDATE maintenance_requests SET assigned_to = NULL WHERE assigned_to = ?")->execute([$id]);
            $pdo->prepare("UPDATE assignment_rotation SET last_employee_id = NULL WHERE last_employee_id = ?")->execute([$id]);

            // 2) the user's own rows -> deleted, children before parents
            //    (maintenance_requests.order_item_id blocks deleting order_items,
            //     and order_items is what CASCADEs off orders)
            $pdo->prepare("
                DELETE FROM maintenance_requests
                WHERE order_item_id IN (
                    SELECT oi.id FROM order_items oi
                    JOIN orders o ON o.id = oi.order_id
                    WHERE o.user_id = ?
                )
            ")->execute([$id]);
            $pdo->prepare("DELETE FROM maintenance_requests WHERE user_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM stock_notifications WHERE user_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM reviews WHERE user_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM orders WHERE user_id = ?")->execute([$id]); // order_items CASCADE
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);

            $pdo->commit();
            flash_set('success', 'تم حذف الحساب "' . $targetUser['full_name'] . '" وكل بياناته نهائيًا من قاعدة البيانات.');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('User delete failed: ' . $ex->getMessage());
            flash_set('error', 'تعذر حذف الحساب. لم يتم تغيير أي شيء.');
        }
    }
}

// Return the admin to the tab they were on. Whitelisted rather than read from
// HTTP_REFERER, because that header is attacker-controlled and would otherwise
// turn this endpoint into an open redirect.
redirect('/admin/users.php' . (($_POST['tab'] ?? '') === 'employees' ? '?tab=employees' : ''));
