<?php
/** The current session's CSRF token, minted on first use. */
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Call within a <form>: echo csrf_field(); */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

/** True if the request carries the session's CSRF token in the given value.
 *  Used by GET actions (logout) that cannot post a hidden field. */
function csrf_check(?string $token): bool {
    return !empty($_SESSION['csrf_token']) && is_string($token)
        && hash_equals($_SESSION['csrf_token'], $token);
}

/** Call at the top of any POST handler before touching $_POST data. */
function csrf_verify(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        die('انتهت صلاحية الجلسة، من فضلك أعد تحميل الصفحة وحاول مرة أخرى.');
    }
}
