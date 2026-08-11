<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

// Print-friendly report view — the store's PDF export. There's no PDF library
// available in this environment (no Composer/Packagist access), so instead of
// a fragile third-party dependency this renders a clean, chrome-free page and
// lets the admin use the browser's native "Print > Save as PDF", which every
// modern browser supports with zero extra dependencies.

$pdo = db();
$type = $_GET['type'] ?? 'all';

// same period options as admin/reports.php, so an export triggered from a
// filtered view matches what was on screen
$periodOptions = [
    '7d'  => ['label' => 'آخر 7 أيام',   'days' => 7],
    '30d' => ['label' => 'آخر 30 يومًا', 'days' => 30],
    '3m'  => ['label' => 'آخر 3 أشهر',   'days' => 90],
    '6m'  => ['label' => 'آخر 6 أشهر',   'days' => 180],
    '12m' => ['label' => 'آخر سنة',      'days' => 365],
];
$period = $_GET['period'] ?? '6m';
if (!isset($periodOptions[$period])) $period = '6m';
$periodDays = $periodOptions[$period]['days'];
$periodLabel = $periodOptions[$period]['label'];
$useDayBuckets = $periodDays <= 30;

$reportTitles = [
    'best_sellers'         => 'أفضل المنتجات مبيعًا',
    'monthly'              => 'تقرير المبيعات (' . $periodLabel . ')',
    'low_stock'            => 'المخزون المنخفض',
    'top_customers'        => 'أفضل العملاء',
    'order_status'         => 'الطلبات حسب الحالة',
    'maintenance'          => 'تقرير الصيانة والضمان',
    'employee_performance' => 'أداء فريق العمل',
    'customer_growth'      => 'نمو قاعدة العملاء (' . $periodLabel . ')',
];

if (!isset($reportTitles[$type]) && $type !== 'all') { http_response_code(404); exit('تقرير غير معروف'); }
$typesToRun = $type === 'all' ? array_keys($reportTitles) : [$type];

function render_report_section(string $key, PDO $pdo, bool $useDayBuckets, int $periodDays): string {
    switch ($key) {
        case 'best_sellers':
            $rows = $pdo->query("SELECT p.name, SUM(oi.quantity) sold FROM order_items oi JOIN orders o ON o.id=oi.order_id JOIN product_variants pv ON pv.id=oi.variant_id JOIN products p ON p.id=pv.product_id WHERE o.status = 'delivered' GROUP BY p.id ORDER BY sold DESC LIMIT 10")->fetchAll();
            if (!$rows) return '<p class="pr-empty">لا توجد بيانات مبيعات بعد.</p>';
            $html = '<table class="print-table"><thead><tr><th>المنتج</th><th>الكمية المباعة</th></tr></thead><tbody>';
            foreach ($rows as $r) $html .= '<tr><td>' . e($r['name']) . '</td><td>' . (int)$r['sold'] . '</td></tr>';
            return $html . '</tbody></table>';

        case 'monthly':
            $fmt = $useDayBuckets ? '%Y-%m-%d' : '%Y-%m';
            // عمودان لا عمود واحد: "قيمة الطلبات" ليست "المُحصَّل". دمجهما تحت
            // اسم "إجمالي المبيعات" كان يعرض نقدًا لم يدخل الصندوق بعد — الدفع
            // عند الاستلام لا يُحصَّل إلا عند التسليم.
            $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at,'$fmt') ym, COUNT(*) c, SUM(total) t,
                                          SUM(CASE WHEN payment_status='paid' THEN total ELSE 0 END) collected
                                     FROM orders
                                    WHERE status NOT IN ('rejected','cancelled')
                                      AND created_at >= CURDATE() - INTERVAL :days DAY
                                 GROUP BY ym ORDER BY ym");
            $stmt->execute(['days' => $periodDays]);
            $rows = $stmt->fetchAll();
            if (!$rows) return '<p class="pr-empty">لا توجد بيانات كافية بعد.</p>';
            $html = '<table class="print-table"><thead><tr><th>' . ($useDayBuckets ? 'اليوم' : 'الشهر') . '</th><th>عدد الطلبات</th><th>قيمة الطلبات</th><th>المُحصَّل فعليًا</th></tr></thead><tbody>';
            foreach ($rows as $r) $html .= '<tr><td>' . ($useDayBuckets ? day_label_ar($r['ym']) : month_label_ar($r['ym'])) . '</td><td>' . (int)$r['c'] . '</td><td>' . money((float)$r['t']) . '</td><td>' . money((float)$r['collected']) . '</td></tr>';
            return $html . '</tbody></table>';

        case 'low_stock':
            $rows = $pdo->query("SELECT p.name, pv.storage, pv.color, pv.sku, pv.stock_quantity FROM product_variants pv JOIN products p ON p.id=pv.product_id WHERE pv.stock_quantity <= " . LOW_STOCK_THRESHOLD . " ORDER BY pv.stock_quantity ASC")->fetchAll();
            if (!$rows) return '<p class="pr-empty">كل المخزون في مستوى جيد حاليًا.</p>';
            $html = '<table class="print-table"><thead><tr><th>المنتج</th><th>السعة</th><th>اللون</th><th>SKU</th><th>المتبقي</th></tr></thead><tbody>';
            foreach ($rows as $r) $html .= '<tr><td>' . e($r['name']) . '</td><td>' . e($r['storage']) . '</td><td>' . e($r['color']) . '</td><td>' . e($r['sku']) . '</td><td>' . (int)$r['stock_quantity'] . '</td></tr>';
            return $html . '</tbody></table>';

        case 'top_customers':
            $rows = $pdo->query("SELECT u.full_name, u.email, u.phone, COUNT(o.id) order_count, SUM(CASE WHEN o.payment_status='paid' THEN o.total ELSE 0 END) total_spent FROM orders o JOIN users u ON u.id=o.user_id WHERE o.status NOT IN ('rejected','cancelled') GROUP BY u.id ORDER BY total_spent DESC LIMIT 15")->fetchAll();
            if (!$rows) return '<p class="pr-empty">لا توجد بيانات عملاء كافية بعد.</p>';
            // total_spent still drives the ranking, it just isn't printed
            $html = '<table class="print-table"><thead><tr><th>العميل</th><th>البريد الإلكتروني</th><th>الموبايل</th><th>عدد الطلبات</th></tr></thead><tbody>';
            foreach ($rows as $r) $html .= '<tr><td>' . e($r['full_name']) . '</td><td>' . e($r['email']) . '</td><td>' . e($r['phone']) . '</td><td>' . (int)$r['order_count'] . '</td></tr>';
            return $html . '</tbody></table>';

        case 'order_status':
            $rows = $pdo->query("SELECT status, COUNT(*) c FROM orders GROUP BY status")->fetchAll();
            if (!$rows) return '<p class="pr-empty">لا توجد طلبات بعد.</p>';
            $html = '<table class="print-table"><thead><tr><th>الحالة</th><th>عدد الطلبات</th></tr></thead><tbody>';
            foreach ($rows as $r) { $meta = order_status_meta($r['status']); $html .= '<tr><td>' . e($meta['label']) . '</td><td>' . (int)$r['c'] . '</td></tr>'; }
            return $html . '</tbody></table>';

        case 'maintenance':
            $counts = $pdo->query("SELECT status, COUNT(*) c FROM maintenance_requests GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
            $html = '<table class="print-table"><thead><tr><th>الحالة</th><th>العدد</th></tr></thead><tbody>';
            foreach (['new' => 'جديد', 'in_progress' => 'قيد المعالجة', 'resolved' => 'تم الحل'] as $k => $label) {
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
            if (!$rows) return '<p class="pr-empty">لا يوجد فريق عمل مسجّل بعد.</p>';
            $html = '<table class="print-table"><thead><tr><th>الاسم</th><th>الدور</th><th>طلبات تمت معالجتها</th><th>طلبات صيانة تم حلها</th></tr></thead><tbody>';
            foreach ($rows as $r) $html .= '<tr><td>' . e($r['full_name']) . '</td><td>' . ($r['role'] === 'admin' ? 'أدمن' : 'موظف') . '</td><td>' . (int)$r['orders_handled'] . '</td><td>' . (int)$r['maint_handled'] . '</td></tr>';
            $html .= '</tbody></table>';
            $html .= '<p class="pr-note">"طلبات تمت معالجتها" تعني كل طلب تم قبوله أو رفضه أو تحديث حالته من قِبل هذا الحساب — وهو غير عدد الطلبات الموزّعة عليه تلقائيًا بالتناوب.</p>';
            return $html;

        case 'customer_growth':
            $fmt = $useDayBuckets ? '%Y-%m-%d' : '%Y-%m';
            $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at,'$fmt') ym, COUNT(*) c FROM users WHERE role='customer' AND created_at >= CURDATE() - INTERVAL :days DAY GROUP BY ym ORDER BY ym");
            $stmt->execute(['days' => $periodDays]);
            $rows = $stmt->fetchAll();
            if (!$rows) return '<p class="pr-empty">لا توجد بيانات تسجيل كافية بعد.</p>';
            $html = '<table class="print-table"><thead><tr><th>' . ($useDayBuckets ? 'اليوم' : 'الشهر') . '</th><th>عملاء جدد</th></tr></thead><tbody>';
            foreach ($rows as $r) $html .= '<tr><td>' . ($useDayBuckets ? day_label_ar($r['ym']) : month_label_ar($r['ym'])) . '</td><td>' . (int)$r['c'] . '</td></tr>';
            return $html . '</tbody></table>';
    }
    return '';
}

$generatedAt = date('Y-m-d H:i');
$reportLabel = $type === 'all' ? 'كل التقارير' : $reportTitles[$type];
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
    رجوع للتقارير
  </a>
  <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">طباعة / حفظ PDF</button>
</div>

<div class="print-sheet">
  <div class="print-head">
    <span class="print-head-mark">FS</span>
    <div>
      <b><?= e(SITE_NAME) ?></b>
      <div class="print-head-sub"><?= e($reportLabel) ?> — تم الإنشاء في <?= e($generatedAt) ?></div>
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
