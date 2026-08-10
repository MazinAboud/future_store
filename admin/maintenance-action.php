<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_any_role(['admin', 'employee']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/admin/maintenance.php');
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';
$me = current_user();

// An employee may only touch a request routed to them. The list already hides
// other people's requests, but hiding is not enforcing — without this check a
// crafted POST could update any request id.
if (has_role('employee') && !employee_owns_maintenance($id, (int)$me['id'])) {
    flash_set('error', 'طلب الصيانة هذا مُسند لموظف آخر.');
    redirect('/admin/maintenance.php');
}

if (in_array($status, ['new', 'in_progress', 'resolved'])) {
    if ($status === 'resolved') {
        db()->prepare("UPDATE maintenance_requests SET status=?, handled_by=?, resolved_at=NOW() WHERE id=?")->execute([$status, $me['id'], $id]);
    } else {
        db()->prepare("UPDATE maintenance_requests SET status=?, handled_by=? WHERE id=?")->execute([$status, $me['id'], $id]);
    }
    flash_set('success', 'تم تحديث حالة طلب الصيانة.');
}
redirect('/admin/maintenance.php');
