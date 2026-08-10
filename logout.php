<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';

// Logout is a state change, so it needs the same CSRF guard as any POST action.
// Without it, a third-party page could embed <img src=".../logout.php"> and
// silently sign the visitor out on every page they browse — an annoyance rather
// than a breach, but a real one. The token rides the logout link in the site
// header; a request without it is left signed in and bounced to the home page.
if (!csrf_check($_GET['token'] ?? null)) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$role = $_SESSION['role'] ?? 'customer';
logout();

// Customers land back on the storefront home page; staff/admin go to the
// team login screen since they have no public-facing page to return to.
$target = match ($role) {
    'admin'    => '/staff/login.php',
    'employee' => '/staff/login.php',
    default    => '/index.php',
};
header('Location: ' . BASE_URL . $target);
exit;
