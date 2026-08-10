<?php
// $activePage should be set by the including page (e.g. 'orders')
$navItems = [
    'orders'      => ['طلباتي', '/account/orders.php'],
    'payments'    => ['السداد والفواتير', '/account/payments.php'],
    'devices'     => ['أجهزتي', '/account/my-devices.php'],
    'maintenance' => ['طلبات الصيانة', '/account/maintenance.php'],
    'profile'     => ['بياناتي', '/account/profile.php'],
];
?>
<aside class="account-side">
  <?php foreach ($navItems as $key => [$label, $href]): ?>
    <a href="<?= BASE_URL . $href ?>" class="<?= ($activePage ?? '') === $key ? 'active' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</aside>
