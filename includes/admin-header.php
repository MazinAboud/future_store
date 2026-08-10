<?php
// Shared internal dashboard shell for BOTH employees and admins. Expects
// $pageTitle and $activeAdminPage to be set by the including page.
// Individual pages still gate their own sensitive actions with require_role()
// where only admins should be allowed (products/reports/users) — this shell
// only controls what's visible in the nav, not page-level authorization.
require_any_role(['admin', 'employee']);
require_once __DIR__ . '/csrf.php'; // shell renders a CSRF-tokenised logout link
$me = current_user();
$isAdmin = $me['role'] === 'admin';

$navItems = [
    'overview' => ['نظرة عامة', $isAdmin ? '/admin/index.php' : '/staff/index.php'],
];
if ($isAdmin) $navItems['products'] = ['المنتجات', '/admin/products.php'];
$navItems['orders'] = ['الطلبات', '/admin/orders.php'];
if ($isAdmin) $navItems['reports'] = ['التقارير', '/admin/reports.php'];
if ($isAdmin) $navItems['users'] = ['المستخدمون', '/admin/users.php'];
$navItems['maintenance'] = ['الصيانة', '/admin/maintenance.php'];
$navItems['profile'] = ['ملفي الشخصي', '/admin/profile.php'];

// One consistent hand-drawn stroke-icon per nav item (24x24, 2px stroke,
// currentColor) — same visual language used site-wide instead of emoji.
$navIcons = [
    'overview'    => '<path d="M3 11.5L12 4l9 7.5"/><path d="M5 10v10h14V10"/><path d="M9.5 20v-6h5v6"/>',
    'products'    => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
    'orders'      => '<path d="M6 2h9l3 3v17H6z"/><path d="M15 2v3h3"/><line x1="9" y1="11" x2="15" y2="11"/><line x1="9" y1="15" x2="15" y2="15"/><line x1="9" y1="7" x2="11" y2="7"/>',
    'reports'     => '<path d="M4 20V10M12 20V4M20 20v-7"/>',
    'users'       => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19c0-3 2.5-5 5.5-5s5.5 2 5.5 5"/><circle cx="17.5" cy="8.5" r="2.6"/><path d="M15.5 14c2.4.2 4.5 2 4.5 5"/>',
    'maintenance' => '<path d="M14.7 6.3a4 4 0 01-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 015.4-5.4l-2.6 2.6-2-2 2.6-2.6z"/>',
    'profile'     => '<circle cx="12" cy="12" r="9.5"/><circle cx="12" cy="10" r="3"/><path d="M6 19c1-2.8 3.4-4.5 6-4.5s5 1.7 6 4.5"/>',
];

// Small demand signal in the nav itself so nothing urgent gets missed on a
// page the user isn't currently viewing (common pattern in real ops dashboards).
// Scoped to the viewer: both queues rotate across employees, so an employee's
// badge must count only their own work — a badge showing more than the page
// itself lists would just look broken.
if ($isAdmin) {
    $navBadges = [
        'orders'      => (int)db()->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn(),
        'maintenance' => (int)db()->query("SELECT COUNT(*) FROM maintenance_requests WHERE status IN ('new','in_progress')")->fetchColumn(),
    ];
} else {
    $ob = db()->prepare("SELECT COUNT(*) FROM orders WHERE status = 'pending' AND (assigned_to = ? OR assigned_to IS NULL)");
    $ob->execute([$me['id']]);
    $mb = db()->prepare("SELECT COUNT(*) FROM maintenance_requests WHERE status IN ('new','in_progress') AND (assigned_to = ? OR assigned_to IS NULL)");
    $mb->execute([$me['id']]);
    $navBadges = ['orders' => (int)$ob->fetchColumn(), 'maintenance' => (int)$mb->fetchColumn()];
}
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? 'الإدارة') ?> | <?= e(SITE_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<?php $flash = flash_get(); ?>
<div class="admin-shell">
  <aside class="admin-side">
    <div class="brand">
      <span class="brand-mark">FS</span>
      <span class="brand-text"><?= e(SITE_NAME) ?><small><?= $isAdmin ? 'لوحة الإدارة' : 'لوحة فريق العمل' ?></small></span>
    </div>
    <?php foreach ($navItems as $key => [$label, $href]): $badge = $navBadges[$key] ?? 0; ?>
      <a href="<?= BASE_URL . $href ?>" class="<?= ($activeAdminPage ?? '') === $key ? 'active' : '' ?>">
        <span class="nav-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?= $navIcons[$key] ?? '' ?></svg></span>
        <span class="nav-label"><?= e($label) ?></span>
        <?php if ($badge > 0): ?><span class="nav-badge"><?= $badge ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
    <a href="<?= BASE_URL ?>/logout.php?token=<?= urlencode(csrf_token()) ?>" class="logout-link">
      <span class="nav-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></span>
      <span class="nav-label">تسجيل الخروج</span>
    </a>
  </aside>
  <main class="admin-main">
    <?php if ($flash): ?><div class="flash-msg flash-<?= e($flash['type']) ?>" style="margin-bottom:18px"><?= e($flash['message']) ?></div><?php endif; ?>
