<?php

/** Escape for safe HTML output — use on every piece of user/db data printed into HTML. */
function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function money(float $n): string {
    return CURRENCY . number_format($n, 2);
}

/**
 * عتبة "المخزون المنخفض" — رقم واحد لكل الشاشات.
 * كانت لوحة المدير تَعُدّ بـ 3 بينما التقارير تسرد بـ 5، فيقول المؤشر "39 منتجًا"
 * ثم تعرض القائمة عددًا آخر، والمدير لا يعرف أي الرقمين يصدّق.
 */
const LOW_STOCK_THRESHOLD = 5;

function redirect(string $path): void {
    // Only ever redirect to a path inside this site. Some callers pass a value
    // that came from the request (compare-toggle.php's "back" field), and a
    // value like "//evil.com" or "https://evil.com" would otherwise carry the
    // user off to another site — an open redirect, the usual first step of a
    // phishing chain. Anything that isn't a plain single-slash path is dropped.
    if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
        $path = '/index.php';
    }
    header('Location: ' . BASE_URL . $path);
    exit;
}

/**
 * Current request URI with BASE_URL stripped back off, so it's safe to feed
 * straight into redirect() (which always re-prepends BASE_URL itself). Without
 * this, capturing $_SERVER['REQUEST_URI'] directly double-prefixes the path
 * whenever the app lives in a sub-folder (e.g. /future_store/future_store/...),
 * which resolves to nothing and surfaces as Apache's bare 404 error page.
 */
function current_path(): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    if (BASE_URL !== '' && stripos($uri, BASE_URL) === 0) {
        $uri = substr($uri, strlen(BASE_URL));
    }
    return $uri === '' ? '/' : $uri;
}

function flash_set(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array {
    if (empty($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

/** Arabic labels + CSS class + icon key for each order status, kept in one place. */
function order_status_meta(string $status): array {
    return match ($status) {
        'pending'    => ['label' => 'قيد الانتظار',  'class' => 'pending',    'icon' => 'clock'],
        'confirmed'  => ['label' => 'مؤكد',           'class' => 'confirmed',  'icon' => 'check'],
        'processing' => ['label' => 'قيد المعالجة',   'class' => 'processing', 'icon' => 'loop'],
        'shipped'    => ['label' => 'تم الشحن',       'class' => 'shipped',    'icon' => 'truck'],
        'delivered'  => ['label' => 'تم التسليم',     'class' => 'delivered',  'icon' => 'check'],
        'rejected'   => ['label' => 'مرفوض',          'class' => 'rejected',   'icon' => 'x'],
        'cancelled'  => ['label' => 'ملغي',           'class' => 'rejected',   'icon' => 'x'],
        default      => ['label' => $status,          'class' => 'pending',    'icon' => 'clock'],
    };
}

/**
 * Semantic colour tone for an order status, used by the dot lists in the
 * reports. Kept separate from order_status_meta()'s 'class', which drives the
 * badge pill's own background/border pair.
 */
function status_tone(string $status): string {
    return match ($status) {
        'pending'                            => 'warning',
        'confirmed', 'processing', 'shipped' => 'info',
        'delivered'                          => 'success',
        'rejected', 'cancelled'              => 'danger',
        default                              => '',
    };
}

function payment_status_meta(string $status): array {
    return $status === 'paid'
        ? ['label' => 'تم السداد', 'class' => 'delivered', 'icon' => 'check']
        : ['label' => 'بانتظار السداد', 'class' => 'pending', 'icon' => 'clock'];
}

function maintenance_status_meta(string $status): array {
    return match ($status) {
        'new'         => ['label' => 'جديد', 'class' => 'pending', 'icon' => 'clock'],
        'in_progress' => ['label' => 'قيد المعالجة', 'class' => 'processing', 'icon' => 'loop'],
        'resolved'    => ['label' => 'تم الحل', 'class' => 'delivered', 'icon' => 'check'],
        default       => ['label' => $status, 'class' => 'pending', 'icon' => 'clock'],
    };
}

/** Tiny inline path/shape fragments — one consistent hand-drawn stroke-icon
 *  set (24x24 grid) reused everywhere site-wide (status pills, KPI cards,
 *  nav, reports) instead of emoji/characters, so nothing looks bolted-on. */
function status_icon_svg(string $key): string {
    return match ($key) {
        'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'check'     => '<circle cx="12" cy="12" r="9"/><path d="M8 12.3l2.6 2.6L16 9.3"/>',
        'loop'      => '<path d="M20 11A8 8 0 006.3 6.3L4 8.6"/><path d="M4 4v4.6h4.6"/><path d="M4 13a8 8 0 0013.7 4.7L20 15.4"/><path d="M20 20v-4.6h-4.6"/>',
        'truck'     => '<path d="M2 7h11v9H2z"/><path d="M13 10h4l3 3v3h-7z"/><circle cx="6.5" cy="18" r="1.6"/><circle cx="17" cy="18" r="1.6"/>',
        'x'         => '<circle cx="12" cy="12" r="9"/><line x1="9" y1="9" x2="15" y2="15"/><line x1="15" y1="9" x2="9" y2="15"/>',
        'coins'     => '<ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v5c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/><path d="M5 11v5c0 1.7 3.1 3 7 3s7-1.3 7-3v-5"/>',
        'warning'   => '<path d="M12 2.5L2 20.5h20L12 2.5z"/><line x1="12" y1="9.5" x2="12" y2="14.5"/><circle cx="12" cy="17.3" r="1" fill="currentColor" stroke="none"/>',
        'user-plus' => '<circle cx="9" cy="9" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6.2 6.5-6.2s6.5 2.6 6.5 6.2"/><line x1="18.5" y1="8" x2="18.5" y2="14"/><line x1="15.5" y1="11" x2="21.5" y2="11"/>',
        'wrench'    => '<path d="M14.7 6.3a4 4 0 01-5.4 5.4L4 17v3h3l5.3-5.3a4 4 0 015.4-5.4l-2.6 2.6-2-2 2.6-2.6z"/>',
        'box'       => '<path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/>',
        'chart'     => '<path d="M4 20V10M12 20V4M20 20v-7"/>',
        'users'     => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 19c0-3 2.5-5 5.5-5s5.5 2 5.5 5"/><circle cx="17.5" cy="8.5" r="2.6"/><path d="M15.5 14c2.4.2 4.5 2 4.5 5"/>',
        'star'      => '<path d="M12 3l2.6 5.6 6.1.6-4.6 4.1 1.3 6-5.4-3.2-5.4 3.2 1.3-6L3.3 9.2l6.1-.6z"/>',
        'trend-up'  => '<path d="M3 17l6-6 4 4 8-8"/><path d="M15 6h6v6"/>',
        'trend-down'=> '<path d="M3 7l6 6 4-4 8 8"/><path d="M15 18h6v-6"/>',
        'shield'    => '<path d="M12 2.5l8 3.2v5.3c0 5-3.4 8.7-8 10.5-4.6-1.8-8-5.5-8-10.5V5.7l8-3.2z"/><path d="M8.5 12l2.3 2.3L16 9.3"/>',
        default     => '<circle cx="12" cy="12" r="9"/>',
    };
}

/** Renders a plain colored-text status pill (no icon — just label + tone).
 *  Call as status_badge(...$meta) with a *_status_meta() result (keys
 *  label/class/icon match by name — $icon is accepted for backward
 *  compatibility with existing call sites but no longer rendered), or
 *  status_badge(label: '...', class: '...') for ad-hoc cases. */
function status_badge(string $label, string $class, string $icon = 'clock'): string {
    return '<span class="status-badge ' . e($class) . '">' . e($label) . '</span>';
}

/* ----------------------- session cart -----------------------
   Cart lives in the session as [variant_id => quantity]. Kept
   deliberately simple: a customer must be logged in to reach the
   cart page at all (per the project's own requirement), so there
   is no separate guest-cart merge step to worry about.
----------------------------------------------------------------*/
function cart_get(): array {
    return $_SESSION['cart'] ?? [];
}

function cart_add(int $variantId, int $qty = 1): void {
    $_SESSION['cart'][$variantId] = ($_SESSION['cart'][$variantId] ?? 0) + $qty;
}

function cart_set_qty(int $variantId, int $qty): void {
    if ($qty <= 0) {
        unset($_SESSION['cart'][$variantId]);
    } else {
        $_SESSION['cart'][$variantId] = $qty;
    }
}

function cart_remove(int $variantId): void {
    unset($_SESSION['cart'][$variantId]);
}

function cart_clear(): void {
    unset($_SESSION['cart']);
}

function cart_count(): int {
    return array_sum(cart_get());
}

/**
 * Fetch full row data (product + variant) for every item currently in the cart.
 *
 * p.is_active = 1 matters here: the cart lives in the session, so it can still
 * hold a product the admin hid or a variant that was deleted minutes ago. Without
 * the filter the customer would keep seeing it and checkout.php would happily
 * write it into a real order. Anything that no longer resolves is dropped from
 * the session too, so the header badge and the cart page never disagree.
 */
function cart_items(): array {
    $cart = cart_get();
    if (empty($cart)) return [];
    $ids = implode(',', array_map('intval', array_keys($cart)));
    $rows = db()->query("
        SELECT pv.id AS variant_id, pv.storage, pv.color, pv.price, pv.stock_quantity,
               p.name AS product_name, p.image_path, p.slug, b.name AS brand_name
        FROM product_variants pv
        JOIN products p ON p.id = pv.product_id
        JOIN brands b ON b.id = p.brand_id
        WHERE pv.id IN ($ids) AND p.is_active = 1
    ")->fetchAll();

    $available = array_column($rows, 'variant_id');
    foreach (array_keys($cart) as $vid) {
        if (!in_array((int)$vid, array_map('intval', $available), true)) {
            unset($_SESSION['cart'][$vid]);
        }
    }

    $items = [];
    foreach ($rows as $row) {
        $row['quantity'] = min($cart[$row['variant_id']], max($row['stock_quantity'], 0));
        $row['line_total'] = $row['quantity'] * $row['price'];
        $items[] = $row;
    }
    return $items;
}

function cart_subtotal(): float {
    $sum = 0.0;
    foreach (cart_items() as $i) { $sum += $i['line_total']; }
    return $sum;
}

function month_label_ar(string $ym): string {
    $months = ['01'=>'يناير','02'=>'فبراير','03'=>'مارس','04'=>'أبريل','05'=>'مايو','06'=>'يونيو','07'=>'يوليو','08'=>'أغسطس','09'=>'سبتمبر','10'=>'أكتوبر','11'=>'نوفمبر','12'=>'ديسمبر'];
    [$y, $m] = explode('-', $ym);
    return $months[$m] . ' ' . $y;
}

/** Day-of-month label for short-range report charts (7/30 day views). */
function day_label_ar(string $ymd): string {
    return date('d/m', strtotime($ymd));
}

/** URL-safe slug from a name; falls back to a unique generic base if the
 *  input has no ASCII letters/digits to work with (e.g. an Arabic-only name). */
function make_slug(string $text): string {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
    return $slug !== '' ? $slug : 'item-' . substr(uniqid(), -6);
}

/* --------------------- round-robin work assignment ---------------------
   Both new orders AND new maintenance requests rotate across active
   employees one at a time, so no single employee's queue fills up while
   another's sits empty, and an employee only ever sees their own work.

   The two queues rotate INDEPENDENTLY — a busy sales day shouldn't decide
   who gets the next repair job — so each has its own cursor row in
   assignment_rotation (see sql/migration_003_maintenance_assignment.sql).

   Deactivating or deleting an employee (admin/user-action.php) hands off
   everything still open on both of their queues to whoever remains active.
------------------------------------------------------------------------*/

/** The queue names that have a rotation cursor. Anything else is a bug, so it
 *  fails loudly rather than silently assigning from the wrong queue. */
const WORK_QUEUES = ['orders', 'maintenance'];

/** Returns the next active employee's id in the given queue's rotation and
 *  advances that queue's cursor. Returns null when there are no active employee
 *  accounts — the item is simply left unassigned until one exists. */
function assign_next_employee(string $queue = 'orders'): ?int {
    if (!in_array($queue, WORK_QUEUES, true)) {
        throw new InvalidArgumentException("Unknown work queue: $queue");
    }
    $pdo = db();
    $employees = $pdo->query("SELECT id FROM users WHERE role='employee' AND is_active=1 ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($employees)) return null;

    // FOR UPDATE: when this runs inside an outer transaction (checkout.php does),
    // it locks the cursor row so two simultaneous requests can't both read the
    // same cursor and land on the same employee. Harmless no-op otherwise.
    $cur = $pdo->prepare("SELECT last_employee_id FROM assignment_rotation WHERE queue = ? FOR UPDATE");
    $cur->execute([$queue]);
    $cursor = $cur->fetchColumn();

    $next = null;
    foreach ($employees as $id) {
        if (empty($cursor) || $id > $cursor) { $next = $id; break; }
    }
    if ($next === null) $next = $employees[0]; // wrapped past the last id — start over

    $pdo->prepare("UPDATE assignment_rotation SET last_employee_id = ? WHERE queue = ?")->execute([$next, $queue]);
    return $next;
}

/** True if this employee may act on the given maintenance request. Unassigned
 *  requests (pre-migration backlog) stay open to anyone, matching the orders rule. */
function employee_owns_maintenance(int $requestId, int $employeeId): bool {
    $chk = db()->prepare("SELECT 1 FROM maintenance_requests WHERE id = ? AND (assigned_to = ? OR assigned_to IS NULL)");
    $chk->execute([$requestId, $employeeId]);
    return (bool)$chk->fetchColumn();
}

/** Hands off everything still open on an employee's queues — orders not yet
 *  delivered/rejected/cancelled, and maintenance requests not yet resolved — to
 *  whichever employees remain active, using the same rotations as new work. With
 *  one employee left everything lands on them; with several it spreads normally.
 *  Called when an employee is deactivated or deleted, so no customer's order or
 *  repair sits stuck behind an account that no longer works. */
function reassign_employee_work(int $fromEmployeeId): void {
    $pdo = db();

    $stmt = $pdo->prepare("SELECT id FROM orders WHERE assigned_to = ? AND status IN ('pending','confirmed','processing','shipped')");
    $stmt->execute([$fromEmployeeId]);
    $orderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($orderIds) {
        $upd = $pdo->prepare("UPDATE orders SET assigned_to = ? WHERE id = ?");
        foreach ($orderIds as $oid) {
            $upd->execute([assign_next_employee('orders'), $oid]);
        }
    }

    $stmt = $pdo->prepare("SELECT id FROM maintenance_requests WHERE assigned_to = ? AND status IN ('new','in_progress')");
    $stmt->execute([$fromEmployeeId]);
    $maintIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if ($maintIds) {
        $upd = $pdo->prepare("UPDATE maintenance_requests SET assigned_to = ? WHERE id = ?");
        foreach ($maintIds as $mid) {
            $upd->execute([assign_next_employee('maintenance'), $mid]);
        }
    }
}

function time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 3600) return max(1, (int)($diff / 60)) . ' دقيقة';
    if ($diff < 86400) return (int)($diff / 3600) . ' ساعة';
    if ($diff < 2592000) return (int)($diff / 86400) . ' يوم';
    return date('Y-m-d', strtotime($datetime));
}

/** Small standalone <svg> (not just the inner path) for use outside status_badge().
 *  Inline-safe by default (base stylesheet sets svg{display:block} globally, so
 *  this needs its own display/vertical-align to sit correctly next to text). */
function icon_svg(string $key, int $size = 12, float $strokeWidth = 2.6): string {
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' . $strokeWidth . '" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:-2px;flex-shrink:0">' . status_icon_svg($key) . '</svg>';
}

/** Tinted icon chip for KPI/dashboard cards. $tone matches a .tone-* CSS class
 *  (navy/amber/success/warning/danger/info) controlling background + color. */
function kpi_icon(string $key, string $tone = 'navy'): string {
    return '<span class="kpi-icon tone-' . e($tone) . '">' . icon_svg($key, 19, 2) . '</span>';
}

/** Renders the visual pending->confirmed->processing->shipped->delivered timeline. */
function render_status_track(string $status): string {
    if (in_array($status, ['rejected', 'cancelled'])) {
        $label = $status === 'rejected' ? 'تم رفض الطلب' : 'ملغي';
        return '<div class="status-track rejected"><div class="step done"><div class="dot">' . icon_svg('x', 13, 2.8) . '</div>' . $label . '</div></div>';
    }
    $steps = ['pending' => 'قيد الانتظار', 'confirmed' => 'مؤكد', 'processing' => 'قيد المعالجة', 'shipped' => 'تم الشحن', 'delivered' => 'تم التسليم'];
    $keys = array_keys($steps);
    $currentIndex = array_search($status, $keys);
    $html = '<div class="status-track">';
    foreach ($keys as $i => $key) {
        $cls = $i < $currentIndex ? 'done' : ($i === $currentIndex ? 'done current' : '');
        $mark = $i <= $currentIndex ? icon_svg('check', 13, 2.8) : '';
        $html .= '<div class="step ' . $cls . '"><div class="dot">' . $mark . '</div>' . e($steps[$key]) . '</div>';
    }
    $html .= '</div>';
    return $html;
}
