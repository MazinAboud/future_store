<?php
/**
 * POST /api/login.php
 *
 * Body: email, password, device_label?
 * 200 -> { ok, token, user }
 *
 * The token replaces the session cookie the website uses. Because the header
 * is attached by the app explicitly and never by the browser automatically,
 * the entire CSRF attack class does not apply here — which is why no endpoint
 * under api/ calls csrf_verify().
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/throttle.php';

only('POST');

$email    = body_str('email');
$password = (string)(body()['password'] ?? '');

if ($email === '' || $password === '') {
    json_error('أدخل البريد الإلكتروني وكلمة السر.', 422);
}

// The API is the easiest login surface to attack: no form, no page load, no
// CSRF token to fetch first — just a JSON POST that can be repeated in a loop
// as fast as the network allows. It shares the same throttle counters as the
// web logins, so an attacker cannot dodge the limit by switching from the form
// to this endpoint, or spread attempts across both to stay under either.
$wait = login_throttle_check($email);
if ($wait > 0) {
    // 429 rather than 401, so the app can tell "wrong password" apart from
    // "stop asking" and show the right message.
    json_error('محاولات دخول كثيرة. حاول مرة أخرى بعد ' . throttle_wait_label($wait) . '.', 429);
}

// role = 'customer' in the WHERE clause, matching attempt_login($email, $pw,
// 'customer') on the website. Employees and admins therefore cannot obtain a
// token at all: their dashboards are built for a wide screen, and keeping them
// off this surface means a leaked staff password opens no door through the app.
$stmt = db()->prepare("
    SELECT * FROM users
    WHERE email = ? AND role = 'customer' AND is_active = 1
    LIMIT 1
");
$stmt->execute([$email]);
$user = $stmt->fetch();

// One message for "no such account", "wrong password" and "account stopped".
// Distinguishing them would turn this endpoint into a tool for discovering
// which email addresses are registered.
if (!$user || !password_verify($password, $user['password_hash'])) {
    login_throttle_fail($email);
    json_error('البريد الإلكتروني أو كلمة السر غير صحيحة.', 401);
}

login_throttle_success($email);

$token = issue_token($user, body_str('device_label'));

json_ok(['token' => $token, 'user' => shape_user($user)]);
