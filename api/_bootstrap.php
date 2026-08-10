<?php
/**
 * Future Store — JSON API bootstrap (mobile app).
 * ---------------------------------------------------------------------------
 * Every endpoint under api/ starts with:  require_once __DIR__ . '/_bootstrap.php';
 *
 * DESIGN RULE FOR THIS WHOLE FOLDER: no file outside api/ is ever modified.
 * If the entire API broke, the website would not notice.
 *
 * Note what is NOT included here: includes/auth.php. That file calls
 * session_start() as a side effect of being included, which would hand every
 * API caller a session cookie it never asked for. The API identifies callers
 * by a Bearer token instead, so it pulls in only db.php (which loads
 * config.php) and functions.php — the latter is where assign_next_employee()
 * lives, and reusing it rather than re-implementing the rotation is the single
 * most important decision in this folder.
 */

require_once __DIR__ . '/../includes/db.php';        // -> config.php, db()
require_once __DIR__ . '/../includes/functions.php'; // -> assign_next_employee(), make_slug(), ...

/* --------------------------------------------------------------------------
 * Output plumbing
 * ------------------------------------------------------------------------*/

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/** Emit a JSON response and stop. */
function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Emit a failure. $fields carries per-field messages so the app can paint the
 * error under the right input instead of showing one generic banner.
 */
function json_error(string $message, int $status = 400, array $fields = []): never
{
    $body = ['ok' => false, 'message' => $message];
    if ($fields) $body['errors'] = $fields;
    json_out($body, $status);
}

/** Emit a success payload. */
function json_ok(array $data = [], int $status = 200): never
{
    json_out(['ok' => true] + $data, $status);
}

/**
 * A PHP fatal (not an Exception — those are caught below) would otherwise end
 * the request with a blank body, and the app would report "تعذر الاتصال
 * بالخادم" for what is really a code bug. This turns it into readable JSON
 * while still keeping the actual message out of the response.
 */
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        error_log('API fatal: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
        echo json_encode(['ok' => false, 'message' => 'خطأ داخلي في الخادم.'], JSON_UNESCAPED_UNICODE);
    }
});

set_exception_handler(function (Throwable $ex): void {
    error_log('API exception: ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    // The real message goes to the PHP error log only. Leaking it here would
    // hand an attacker table names and file paths.
    echo json_encode(['ok' => false, 'message' => 'خطأ داخلي في الخادم.'], JSON_UNESCAPED_UNICODE);
    exit;
});

/* --------------------------------------------------------------------------
 * Input
 * ------------------------------------------------------------------------*/

/** Reject anything that isn't one of the listed HTTP methods. */
function only(string ...$methods): void
{
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($m, $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        json_error('طريقة الطلب غير مسموحة.', 405);
    }
}

/**
 * The decoded JSON request body, cached.
 *
 * Falls back to $_POST so the endpoints can also be exercised from a plain
 * HTML form or curl -d during debugging, not only from the Flutter client.
 */
function body(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return $cache = $_POST;

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        if ($_POST) return $cache = $_POST;
        json_error('صيغة الطلب غير صحيحة (JSON غير صالح).', 400);
    }
    return $cache = $decoded;
}

/** One trimmed string field out of the JSON body. */
function body_str(string $key, string $default = ''): string
{
    $v = body()[$key] ?? $default;
    return is_scalar($v) ? trim((string)$v) : $default;
}

/** One integer field out of the JSON body. */
function body_int(string $key, int $default = 0): int
{
    $v = body()[$key] ?? $default;
    return is_numeric($v) ? (int)$v : $default;
}

/** One trimmed string field out of the query string. */
function query_str(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_scalar($v) ? trim((string)$v) : $default;
}

/** One integer field out of the query string. */
function query_int(string $key, int $default = 0): int
{
    $v = $_GET[$key] ?? $default;
    return is_numeric($v) ? (int)$v : $default;
}

/* --------------------------------------------------------------------------
 * Absolute URLs for images
 * ------------------------------------------------------------------------*/

/**
 * Scheme + host + the project's base path, e.g. http://10.0.2.2/future_store
 *
 * The database stores image_path as a path relative to the project root
 * ("assets/images/products/iphone-15.svg"). A browser resolves that against
 * the current page; an Android app has no such context and would fail to load
 * every image, so the API hands out absolute URLs.
 */
function api_origin(): string
{
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    return $scheme . '://' . $host . BASE_URL;
}

/**
 * Absolute, app-loadable URL for a stored image path.
 *
 * Eleven of the twelve product images ship as .svg, and Flutter's
 * Image.network cannot decode SVG at all — every product card would render
 * blank. A .png of each was generated alongside the .svg (same base name), so
 * this prefers the .png whenever the file is actually on disk and falls back
 * to the original otherwise. Nothing in the database changed, and the website
 * keeps serving the sharper SVG to browsers.
 */
function image_url(?string $path): ?string
{
    $path = trim((string)$path);
    if ($path === '') return null;
    if (preg_match('#^https?://#i', $path)) return $path;

    $path = ltrim(str_replace('\\', '/', $path), '/');

    if (str_ends_with(strtolower($path), '.svg')) {
        $png = substr($path, 0, -4) . '.png';
        if (is_file(dirname(__DIR__) . '/' . $png)) {
            $path = $png;
        }
    }

    // Cache-busting stamp: the file's last-modified time.
    //
    // Flutter's Image.network keys its cache on the URL alone, and so does the
    // HTTP layer beneath it. Uploading through the admin panel normally writes
    // a new filename (name + time()), so the URL changes on its own — but that
    // is a property of one call site, not a guarantee. Any path that reuses a
    // filename (a file replaced on disk, a regenerated placeholder, a future
    // "keep the same name" upload) would serve the OLD bytes from cache with no
    // way for the app to know, and the only fix a user could find is
    // reinstalling. Appending mtime makes the URL change exactly when the image
    // does: replaced file -> new URL -> new bytes; untouched file -> identical
    // URL -> caching still works normally.
    //
    // Kept out of the returned string when the file cannot be found on disk, so
    // an image served from somewhere else is never decorated with a misleading
    // version marker.
    $absolute = dirname(__DIR__) . '/' . $path;
    $stamp    = is_file($absolute) ? @filemtime($absolute) : false;

    return api_origin() . '/' . $path . ($stamp ? '?v=' . $stamp : '');
}

/* --------------------------------------------------------------------------
 * Bearer token authentication
 * ------------------------------------------------------------------------*/

/**
 * The raw Bearer token from the request, or null.
 *
 * Three lookups, not one: mod_php exposes the header as HTTP_AUTHORIZATION,
 * but under CGI/FastCGI Apache strips it unless CGIPassAuth is on, where it
 * may resurface as REDIRECT_HTTP_AUTHORIZATION or only through
 * apache_request_headers(). Checking just the first is the classic reason a
 * token API "works on my machine" and returns 401 everywhere else.
 * api/.htaccess covers the same gap from the server side.
 */
function bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if ($header === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) { $header = $value; break; }
        }
    }
    if ($header === '') return null;

    if (!preg_match('/^Bearer\s+([A-Za-z0-9]+)$/', trim($header), $m)) return null;
    return $m[1];
}

/**
 * The account behind the current Bearer token, or null.
 *
 * Deliberately mirrors current_user() in includes/auth.php: the row is re-read
 * from the database on every single request rather than trusted from the
 * token. An admin who stops an account from admin/users.php must lock that
 * person out of the app on their very next tap, exactly as it locks them out
 * of the website on their next page load.
 *
 * Four separate reasons to reject, all checked:
 *   1. no row for this fingerprint      -> token was never issued / was revoked
 *   2. expires_at has passed            -> token aged out
 *   3. is_active = 0                    -> account stopped by an admin
 *   4. pwd_check no longer matches      -> password changed since the token was
 *                                          issued, so every older token dies
 *   5. role is not 'customer'           -> see require_customer() below
 */
function api_user(): ?array
{
    static $cache = null, $resolved = false;
    if ($resolved) return $cache;
    $resolved = true;

    $token = bearer_token();
    if ($token === null) return $cache = null;

    $stmt = db()->prepare("
        SELECT u.*, t.id AS token_id, t.pwd_check, t.expires_at
        FROM api_tokens t
        JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = ?
        LIMIT 1
    ");
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    if (!$row) return $cache = null;

    if ($row['expires_at'] !== null && strtotime($row['expires_at']) < time()) {
        db()->prepare('DELETE FROM api_tokens WHERE id = ?')->execute([$row['token_id']]);
        return $cache = null;
    }
    if (!$row['is_active']) return $cache = null;
    if (!hash_equals($row['pwd_check'], hash('sha256', $row['password_hash']))) {
        db()->prepare('DELETE FROM api_tokens WHERE id = ?')->execute([$row['token_id']]);
        return $cache = null;
    }

    db()->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?')->execute([$row['token_id']]);

    unset($row['password_hash'], $row['pwd_check']);
    return $cache = $row;
}

/**
 * Gate for every endpoint that touches a customer's own data.
 *
 * The role check is not redundant with login: token issuing already refuses
 * employees and admins, but re-checking here means a role changed *after*
 * issuing (admin promotes a customer to employee) also closes the app door,
 * without waiting for the token to expire. Staff dashboards stay web-only, so
 * a stolen employee password still opens no door through the app.
 */
function require_customer(): array
{
    $u = api_user();
    if ($u === null) {
        json_error('انتهت الجلسة، من فضلك سجّل الدخول مرة أخرى.', 401);
    }
    if ($u['role'] !== 'customer') {
        json_error('هذا التطبيق مخصص للعملاء فقط.', 403);
    }
    return $u;
}

/** Issues a fresh token for a user and returns the raw value (shown once). */
function issue_token(array $user, ?string $deviceLabel = null): string
{
    $token = bin2hex(random_bytes(32)); // 64 hex chars
    db()->prepare('
        INSERT INTO api_tokens (user_id, token_hash, pwd_check, device_label, expires_at)
        VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 90 DAY))
    ')->execute([
        $user['id'],
        hash('sha256', $token),
        hash('sha256', $user['password_hash']),
        $deviceLabel !== null && $deviceLabel !== '' ? mb_substr($deviceLabel, 0, 80) : null,
    ]);
    return $token;
}

/* --------------------------------------------------------------------------
 * Shared shaping helpers
 * ------------------------------------------------------------------------*/

/** The public shape of a user account. Never includes password_hash. */
function shape_user(array $u): array
{
    return [
        'id'         => (int)$u['id'],
        'full_name'  => $u['full_name'],
        'email'      => $u['email'],
        'phone'      => $u['phone'],
        'address'    => $u['address'],
        'created_at' => $u['created_at'],
    ];
}

/**
 * Order status as the app should show it, reusing the website's own Arabic
 * labels from includes/functions.php so a status never reads one way in the
 * browser and another way on the phone.
 */
function shape_status(string $status): array
{
    $meta = order_status_meta($status);
    return [
        'code'  => $status,
        'label' => $meta['label'],
        'tone'  => status_tone($status),
    ];
}

/** Validation shared by register and profile-update, matching register.php. */
function validate_phone(string $phone): bool
{
    return (bool)preg_match('/^[0-9+ ]{8,15}$/', $phone);
}
