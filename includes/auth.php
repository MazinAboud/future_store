<?php
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    // 'secure' is computed rather than hard-coded, and that matters in both
    // directions. Hard-coding it true breaks local development entirely: the
    // browser would refuse to store the cookie over http://localhost, so
    // nobody could stay logged in while working on the site. Hard-coding it
    // false leaves the session cookie travelling in clear text on the public
    // HTTPS site, where anyone sharing a network with a customer could read it
    // and resume that customer's session without ever needing the password.
    //
    // Detecting the scheme gives the correct answer in both places with no
    // switch to remember to flip at deploy time. The proxy header is honoured
    // because a site behind a load balancer or Cloudflare terminates TLS at
    // the edge, so PHP sees plain HTTP even though the browser used HTTPS.
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $isHttps,  // never send the session cookie over plain HTTP
        'httponly' => true,      // JS cannot read the session cookie
        'samesite' => 'Lax',     // CSRF-resistant default
    ]);

    // The default session name announces the technology in every request.
    // Renaming it removes one free hint for an attacker fingerprinting the
    // stack, and costs nothing.
    session_name('FSSESSID');
    session_start();
}

/**
 * A short fingerprint of the account's current password hash. Stored in the
 * session at login and re-checked on every request (see current_user()), it is
 * the web-session equivalent of api_tokens.pwd_check: when the password changes,
 * the fingerprint changes, and every session still carrying the old one is ended
 * on its next page load. That is what makes "change my password" actually evict
 * other devices — and lets an admin's password reset lock a user out — instead
 * of leaving old browser sessions alive until they happen to log out.
 */
function pwd_fingerprint(string $hash): string {
    return hash('sha256', $hash);
}

/** Attempt to log a user with a specific role in. Returns true/false. */
function attempt_login(string $email, string $password, string $requiredRole): bool {
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? AND role = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$email, $requiredRole]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    // Prevent session fixation
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role']    = $user['role'];
    $_SESSION['name']    = $user['full_name'];
    $_SESSION['pwd_fp']  = pwd_fingerprint($user['password_hash']);
    return true;
}

/**
 * Shared internal-team login: looks the account up by email only (no role
 * pre-filter), so one form serves both employees and admins. Returns the
 * account's actual role on success ('employee'|'admin') so the caller can
 * redirect to the right dashboard, or null on failure. Customers can never
 * authenticate through this path even if they guess the form exists.
 */
function attempt_team_login(string $email, string $password): ?string {
    $stmt = db()->prepare("SELECT * FROM users WHERE email = ? AND role IN ('employee','admin') AND is_active = 1 LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return null;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role']    = $user['role'];
    $_SESSION['name']    = $user['full_name'];
    $_SESSION['pwd_fp']  = pwd_fingerprint($user['password_hash']);
    return $user['role'];
}

/**
 * The account behind the current session, re-read from the database once per
 * request.
 *
 * Re-reading matters for more than freshness: an admin can deactivate or delete
 * an account while its owner is still browsing. Checking is_active only at login
 * time would leave that session fully privileged until the person happened to log
 * out, so "إيقاف الحساب" would look like it worked without actually doing
 * anything. Ending the session here makes every admin change take effect on the
 * next page the user loads.
 */
function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    static $cache = null, $resolved = false;
    if ($resolved) return $cache;
    $resolved = true;

    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;

    if (!$user || !$user['is_active']) {
        logout();              // account deleted or stopped since this session began
        return $cache = null;
    }

    // The password changed since this session was opened (the owner changed it on
    // another device, or an admin reset it). Any session still holding the old
    // fingerprint is no longer trusted, so it ends here. Sessions opened before
    // this control existed carry no fingerprint and are left alone rather than
    // mass-logged-out — they simply gain the protection at their next login.
    if (isset($_SESSION['pwd_fp'])
        && !hash_equals($_SESSION['pwd_fp'], pwd_fingerprint($user['password_hash']))) {
        logout();
        return $cache = null;
    }

    // an admin may have changed the role since login — the session must follow
    $_SESSION['role'] = $user['role'];
    $_SESSION['name'] = $user['full_name'];
    return $cache = $user;
}

function is_logged_in(): bool {
    return current_user() !== null;
}

/* Role checks read the live account row rather than the session copy, so a role
   change or a deactivation is honoured immediately. */
function has_role(string $role): bool {
    $u = current_user();
    return $u !== null && $u['role'] === $role;
}

/** True if logged in as any of the given roles — used by pages shared between employee + admin. */
function has_any_role(array $roles): bool {
    $u = current_user();
    return $u !== null && in_array($u['role'], $roles, true);
}

/** Redirects to the given role's login page if the user isn't authenticated with that role. */
function require_role(string $role): void {
    if (has_role($role)) return;
    $loginPage = match ($role) {
        'admin', 'employee' => '/staff/login.php', // unified team login
        default              => '/login.php',
    };
    $_SESSION['redirect_after_login'] = current_path();
    header('Location: ' . BASE_URL . $loginPage);
    exit;
}

/** Redirects to the team login unless the user is authenticated as one of the given roles. */
function require_any_role(array $roles): void {
    if (has_any_role($roles)) return;
    $_SESSION['redirect_after_login'] = current_path();
    header('Location: ' . BASE_URL . '/staff/login.php');
    exit;
}

function logout(): void {
    $_SESSION = [];
    session_destroy();
}
