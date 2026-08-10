<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

// Print-friendly report view â€” the store's PDF export. There's no PDF library
// available in this environment (no Composer/Packagist access), so instead of
// a fragile third-party dependency this renders a clean, chrome-free page and
// lets the admin use the browser's native "Print > Save as PDF", which every
// modern browser supports with zero extra dependencies.

$pdo = db();
$type = $_GET['type'] ?? 'all';

// same period options as admin/reports.php, so an export triggered from a
// filtered view matches what was on screen
$periodOptions = [
    '7d'  => ['label' => 'Ø¢Ø®Ø± 7 Ø£ÙŠØ§Ù…',   'days' => 7],
    '30d' => ['label' => 'Ø¢Ø®Ø± 30 ÙŠÙˆÙ…Ù‹Ø§', 'days' => 30],
    '3m'  => ['label' => 'Ø¢Ø®Ø± 3 Ø£Ø´Ù‡Ø±',   'days' => 90],
    '6m'  => ['label' => 'Ø¢Ø®Ø± 6 Ø£Ø´Ù‡Ø±',   'days' => 180],
    '12m' => ['label' => 'Ø¢Ø®Ø± Ø³Ù†Ø©',      'days' => 365],
];
$period = $_GET['period'] ?? '6m';
if (!isset($periodOptions[$period])) $period = '6m';
$periodDays = $periodOptions[$period]['days'];
$periodLabel = $periodOptions[$period]['label'];
$useDayBuckets = $periodDays <= 30;

$reportTitles = [
    'best_sellers'         => 'Ø£ÙØ¶Ù„ Ø§Ù„Ù…Ù†ØªØ¬Ø§Øª Ù…Ø¨ÙŠØ¹Ù‹Ø§',
    'monthly'              => 'ØªÙ‚Ø±ÙŠØ± Ø§Ù„Ù…Ø¨ÙŠØ¹Ø§Øª (' . $periodLabel . ')',
    'low_stock'            => 'Ø§Ù„Ù…Ø®Ø²ÙˆÙ† Ø§Ù„Ù…Ù†Ø®ÙØ¶',
    'top_customers'        => 'Ø£ÙØ¶Ù„ Ø§Ù„Ø¹Ù…Ù„Ø§Ø¡',
    'order_status'         => 'Ø§Ù„Ø·Ù„Ø¨Ø§Øª Ø­Ø³Ø¨ Ø§Ù„Ø­Ø§Ù„Ø©',
    'maintenance'          => 'ØªÙ‚Ø±ÙŠØ± Ø§Ù„ØµÙŠØ§Ù†Ø© ÙˆØ§Ù„Ø¶Ù…Ø§Ù†',
    'employee_performance' => 'Ø£Ø¯Ø§Ø¡ ÙØ±ÙŠÙ‚ Ø§Ù„Ø¹Ù…Ù„',
    'customer_growth'      => 'Ù†Ù…Ùˆ Ù‚Ø§Ø¹Ø¯Ø© Ø§Ù„Ø¹Ù…Ù„Ø§Ø¡ (' . $periodLabel . ')',
];

if (!isset($reportTitles[$type]) && $type !== 'all') { http_response_code(404); exit('ØªÙ‚Ø±ÙŠØ± ØºÙŠØ± Ù…Ø¹Ø±ÙˆÙ'); }
$typesToRun = $type === 'all' ? array_keys($reportTitles) : [$type];

function render_report_section(string $key, PDO $pdo, bool $useDayBuckets, int $periodDays): string {
    switch ($key) {
        case 'best_sellers':
            $rows = $pdo->query("SELECT p.name, SUM(oi.quantity) sold FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN product_variants pv ON pv.id=oi.variant_id JOIN products p ON p.id=pv.product_id WHERE o.status = 'delivered' GROUP BY p.id ORDER BY sold DESC LIMIT 10")->fetchAll();
            if (!$rows) return '<p class="pr-empty">Ù„Ø§ ØªÙˆØ¬Ø¯ Ø¨ÙŠØ§Ù†Ø§Øª Ù…Ø¨ÙŠØ¹Ø§Øª Ø¨Ø¹Ø¯.</p>';
            $html = '<table class="print-table"><thead><tr><th>Ø§Ù„Ù…Ù†ØªØ¬</th><th>Ø§Ù„ÙƒÙ…ÙŠØ© Ø§Ù„Ù…Ø¨Ø§Ø¹Ø©</th></tr></thead><tbody>';
            foreach ($rows as $r) $html .= '<tr><td>' . e($r['name']) . '</td><td>' . (int)$r['sold'] . '</td></tr>';
            return $html . '</tbody></table>';

        case 'monthly':
            $fmt = $useDayBuckets ? '%Y-%m-%d' : '%Y-%m';
            $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at,'$fmt') ym, COUNT(*) c, SUM(total) t FROM orders WHERE status NOT IN ('rejected','cancelled') AND created_at >= CURDATE() - INTERVAL :days DAY GROUP BY ym ORDER BY ym");
            $stmt->execute(['days' => $periodDays]);
            $rows = $stmt->fetchAll();
            if (!$rows) return '<p class="pr-empty">Ù„Ø§ ØªÙˆØ¬Ø¯ Ø¨ÙŠØ§Ù†Ø§Øª ÙƒØ§ÙÙŠØ© Ø¨Ø¹Ø¯.</p>';
            $html = '<table class="print-table"><thead><tr><th>' . ($useDayBuckets ? 'Ø§Ù„ÙŠÙˆÙ…' : 'Ø§Ù„Ø´Ù‡Ø±') . '</th><th>Ø¹Ø¯Ø¯ Ø§Ù„Ø·Ù„Ø¨Ø§Øª</th><th>Ø¥Ø¬Ù…Ø§Ù„ÙŠ Ø§Ù„Ù…Ø¨ÙŠØ¹Ø§Øª</th></tr></thead><tbody>';
            foreach ($rows as $r) $html .= '<tr><td>' . ($useDayBuckets ? day_label_ar($r['ym']) : month_label_ar($r['ym'])) . '</td><td>' . (int)$r['c'] . '</td><td>' . money((float)$r['t']) . '</td></tr>';
            return $html . '</tbody></table>';

        case 'low_stock':
            $rows = $pdo->query("SELECT p.name, pv.storage, pv.color, pv.sku, pv.stock_quantity FROM product_variants pv JOIN products p ON p.id=pv.product_id WHERE pv.stock_quantity <= 5 ORDER BY pv.stock_quantity ASC")->fetchAll();
            if (!$rows) return '<p class="pr-empty">ÙƒÙ„ Ø§Ù„Ù…Ø®Ø²ÙˆÙ† ÙÙŠ Ù…Ø³ØªÙˆÙ‰ Ø¬ÙŠØ¯ Ø­Ø§Ù„ÙŠÙ‹Ø§.</p>';
            $html = '<table class="print-table"><thead><tr><th>Ø§Ù„Ù…Ù†ØªØ¬</th><th>Ø§Ù„Ø³Ø¹Ø©</th><th>Ø§Ù„Ù„ÙˆÙ†</th><th>SKU</th><th>Ø§Ù„Ù…ØªØ¨Ù‚ÙŠ</th></tr></thead><tbody>';
            foreach ($rows as $r) $html .= '<tr><td>' . e($r['name']) . '</td><td>' . e($r['storage']) . '</td><td>' . e($r['color']) . '</td><td>' . e($r['sku']) . '</td><td>' . (int)$r['stock_quantity'] . '</td></tr>';
            return $html . '</tbody></table>';

        case 'top_customers':
            $rows = $pdo->query("SELECT u.full_name, u.email, u.phone, COUNT(o.id) order_count, SUM(CASE WHEN o.payment_status='paid' THEN o.total ELSE 0 END) total_spent FROM orders o JOIN users u ON u.id=o.user_id WHERE o.status NOT IN ('rejected','cancelled') GROUP BY u.id ORDER BY total_spent DESC LIMIT 15")->fetchAll();
            if (!$rows) return '<p class="pr-empty">Ù„Ø§ ØªÙˆØ¬Ø¯ Ø¨ÙŠØ§Ù†Ø§Øª Ø¹Ù…Ù„Ø§Ø¡ ÙƒØ§ÙÙŠØ© Ø¨Ø¹Ø¯.</p>';
            // total_spent still drives the ranking, it just isn't printed
            $html = '<table class="print-table"><thead><tr><th>Ø§Ù„Ø¹Ù…ÙŠÙ„</th><th>Ø§Ù„Ø¨Ø±ÙŠØ¯ Ø§Ù„Ø¥Ù„ÙƒØªØ±ÙˆÙ†ÙŠ</th><th>Ø§Ù„Ù…ÙˆØ¨Ø§ÙŠÙ„</th><th>Ø¹Ø¯Ø¯ Ø§Ù„Ø·Ù„Ø¨Ø§Øª</th></tr></thead><tbody>';
            foreach ($rows as $r) $html .= '<tr><td>' . e($r['full_name']) . '</td><td>' . e($r['email']) . '</td><td>' . e($r['phone']) . '</td><td>' . (int)$r['order_count'] . '</td></tr>';
            return $html . '</tbody></table>';

        case 'order_status':
            $rows = $pdo->query("SELECT status, COUNT(*) c FROM orders GROUP BY status")->fetchAll();
            if (!$rows) return '<p class="pr-empty">Ù„Ø§ ØªÙˆØ¬Ø¯ Ø·Ù„Ø¨Ø§Øª Ø¨Ø¹Ø¯.</p>';
            $html = '<table class="print-table"><thead><tr><th>Ø§Ù„Ø­Ø§Ù„Ø©</th><th>Ø¹Ø¯Ø¯ Ø§Ù„Ø·Ù„Ø¨Ø§Øª</th></tr></thead><tbody>';
            foreach ($rows as $r) { $meta = order_status_meta($r['status']); $html .= '<tr><td>' . e($meta['label']) . '</td><td>' . (int)$r['c'] . '</td></tr>'; }
            return $html . '</tbody></table>';

        case 'maintenance':
            $counts = $pdo->query("SELECT status, COUNT(*) c FROM maintenance_requests GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
            $html = '<table class="print-table"><thead><tr><th>Ø§Ù„Ø­Ø§Ù„Ø©</th><th>Ø§Ù„Ø¹Ø¯Ø¯</th></tr></thead><tbody>';
            foreach (['new' => 'Ø¬Ø¯ÙŠØ¯', 'in_progress' => 'Ù‚ÙŠØ¯ Ø§Ù„Ù…Ø¹Ø§Ù„Ø¬Ø©', 'resolved' => 'ØªÙ… Ø§Ù„Ø­Ù„'] as $k => $label) {
                $html .= '<tr><td>' . $label . '</td><td>' . (int)($counts[$k] ?? 0) . '</td></tr>';
            }
            return $html . '</tbody></table>';

        case 'employee_performance':
            $rows = $pdo->query("
                SELECT u.full_name, u.role,
                    (SELECT COUNT(*) FROM orders WHERE handled_by = u.id) AS orders_handled,
                    (SELECT COUNT(*) FROM maintenance_requests WHERE handled_by = u.id) AS maint_handled
                FROM users u WHERE u.role IN ('admin','employee') ORDER BY orders_handled DESC
            ")->fetchAll();
            if (!$rows) return '<p class="pr-empty">Ù„Ø§ ÙŠÙˆØ¬Ø¯ ÙØ±ÙŠÙ‚ Ø¹Ù…Ù„ Ù…Ø³Ø¬Ù‘Ù„ Ø¨Ø¹Ø¯.</p>';
            $html = '<table class="print-table"><thead><tr><th>Ø§Ù„Ø§Ø³Ù…</th><th>Ø§Ù„Ø¯ÙˆØ±</th><th>Ø·Ù„Ø¨Ø§Øª ØªÙ…Øª Ù…Ø¹Ø§Ù„Ø¬ØªÙ‡Ø§</th><th>Ø·Ù„Ø¨Ø§Øª ØµÙŠØ§Ù†Ø© ØªÙ… Ø­Ù„Ù‡Ø§</th></tr></thead><tbody>';
            foreach ($rows as $r) $html .= '<tr><td>' . e($r['full_name']) . '</td><td>' . ($r['role'] === 'admin' ? 'Ø£Ø¯Ù…Ù†' : 'Ù…ÙˆØ¸Ù') . '</td><td>' . (int)$r['orders_handled'] . '</td><td>' . (int)$r['maint_handled'] . '</td></tr>';
            $html .= '</tbody></table>';
            $html .= '<p class="pr-note">"Ø·Ù„Ø¨Ø§Øª ØªÙ…Øª Ù…Ø¹Ø§Ù„Ø¬ØªÙ‡Ø§" ØªØ¹Ù†ÙŠ ÙƒÙ„ Ø·Ù„Ø¨ ØªÙ… Ù‚Ø¨ÙˆÙ„Ù‡ Ø£Ùˆ Ø±ÙØ¶Ù‡ Ø£Ùˆ ØªØ­Ø¯ÙŠØ« Ø­Ø§Ù„ØªÙ‡ Ù…Ù† Ù‚ÙØ¨Ù„ Ù‡Ø°Ø§ Ø§Ù„Ø­Ø³Ø§Ø¨ â€” ÙˆÙ‡Ùˆ ØºÙŠØ± Ø¹Ø¯Ø¯ Ø§Ù„Ø·Ù„Ø¨Ø§Øª Ø§Ù„Ù…ÙˆØ²Ù‘Ø¹Ø© Ø¹Ù„ÙŠÙ‡ ØªÙ„Ù‚Ø§Ø¦ÙŠÙ‹Ø§ Ø¨Ø§Ù„ØªÙ†Ø§ÙˆØ¨.</p>';
            return $html;

        case 'customer_growth':
            $fmt = $useDayBuckets ? '%Y-%m-%d' : '%Y-%m';
            $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at,'$fmt') ym, COUNT(*) c FROM users WHERE role='customer' AND created_at >= CURDATE() - INTERVAL :days DAY GROUP BY ym ORDER BY ym");
            $stmt->execute(['days' => $periodDays]);
            $rows = $stmt->fetchAll();
            if (!$rows) return '<p class="pr-empty">Ù„Ø§ ØªÙˆØ¬Ø¯ Ø¨ÙŠØ§Ù†Ø§Øª ØªØ³Ø¬ÙŠÙ„ ÙƒØ§ÙÙŠØ© Ø¨Ø¹Ø¯.</p>';
            $html = '<table class="print-table"><thead><tr><th>' . ($useDayBuckets ? 'Ø§Ù„ÙŠÙˆÙ…' : 'Ø§Ù„Ø´Ù‡Ø±') . '</th><th>Ø¹Ù…Ù„Ø§Ø¡ Ø¬Ø¯Ø¯</th></tr></thead><tbody>';
            foreach ($rows as $r) $html .= '<tr><td>' . ($useDayBuckets ? day_label_ar($r['ym']) : month_label_ar($r['ym'])) . '</td><td>' . (int)$r['c'] . '</td></tr>';
            return $html . '</tbody></table>';
    }
    return '';
}

$generatedAt = date('Y-m-d H:i');
$reportLabel = $type === 'all' ? 'ÙƒÙ„ Ø§Ù„ØªÙ‚Ø§Ø±ÙŠØ±' : $reportTitles[$type];
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($reportLabel) ?> | <?= e(SITE_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<div class="no-print print-toolbar">
  <a href="<?= BASE_URL ?>/admin/reports.php" class="btn btn-outline btn-sm">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
    Ø±Ø¬ÙˆØ¹ Ù„Ù„ØªÙ‚Ø§Ø±ÙŠØ±
  </a>
  <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">Ø·Ø¨Ø§Ø¹Ø© / Ø­ÙØ¸ PDF</button>
</div>

<div class="print-sheet">
  <div class="print-head">
    <span class="print-head-mark">FS</span>
    <div>
      <b><?= e(SITE_NAME) ?></b>
      <div class="print-head-sub"><?= e($reportLabel) ?> â€” ØªÙ… Ø§Ù„Ø¥Ù†Ø´Ø§Ø¡ ÙÙŠ <?= e($generatedAt) ?></div>
    </div>
  </div>

  <?php foreach ($typesToRun as $key): ?>
    <section class="print-section">
      <h2><?= e($reportTitles[$key]) ?></h2>
      <?= render_report_section($key, $pdo, $useDayBuckets, $periodDays) ?>
    </section>
  <?php endforeach; ?>
</div>

</body>
</html>
