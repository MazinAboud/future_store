<?php
// Expects $pageTitle to be set by the including page. Assumes auth.php/functions.php already loaded.
require_once __DIR__ . '/csrf.php'; // header renders a CSRF-tokenised logout link
$me = current_user();
$q  = trim($_GET['q'] ?? '');
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? SITE_NAME) ?> | <?= e(SITE_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<?php $flash = flash_get(); if ($flash): ?>
<div class="flash">
  <div class="flash-msg flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
</div>
<?php endif; ?>

<header class="site-header">
  <a class="logo" href="<?= BASE_URL ?>/index.php"><?= e(SITE_NAME) ?></a>
  <nav class="main-nav">
    <a href="<?= BASE_URL ?>/index.php" class="<?= basename($_SERVER['SCRIPT_NAME'])==='index.php'?'active':'' ?>">الرئيسية</a>
    <a href="<?= BASE_URL ?>/products.php" class="<?= basename($_SERVER['SCRIPT_NAME'])==='products.php'?'active':'' ?>">المنتجات</a>
    <a href="<?= BASE_URL ?>/compare.php" class="<?= basename($_SERVER['SCRIPT_NAME'])==='compare.php'?'active':'' ?>">قارن</a>
  </nav>
  <form class="search-bar" action="<?= BASE_URL ?>/products.php" method="get">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="ابحث عن هاتف أو موديل...">
  </form>
  <div class="header-actions">
    <?php if (has_role('customer')): ?>
      <a class="icon-btn" href="<?= BASE_URL ?>/cart.php" aria-label="سلة المشتريات">
        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
        <?php $c = cart_count(); if ($c > 0): ?><span class="badge"><?= (int)$c ?></span><?php endif; ?>
      </a>
      <a class="user-chip" href="<?= BASE_URL ?>/account/orders.php">
        <span class="avatar"><?= e(mb_substr($me['full_name'], 0, 1)) ?></span>
        <?= e(explode(' ', $me['full_name'])[0]) ?>
      </a>
      <a class="icon-btn" href="<?= BASE_URL ?>/logout.php?token=<?= urlencode(csrf_token()) ?>" aria-label="تسجيل الخروج" title="تسجيل الخروج">
        <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      </a>
    <?php else: ?>
      <a class="btn btn-primary" href="<?= BASE_URL ?>/login.php">تسجيل الدخول</a>
    <?php endif; ?>
  </div>
</header>
<main>
