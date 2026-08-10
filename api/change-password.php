<?php
/**
 * POST /api/change-password.php
 *
 * Body: current_password, new_password, new_password_confirm?
 * 200 -> { ok, token, message }
 *
 * Rules copied from account/profile.php's "change_password" branch.
 *
 * The response carries a NEW token, and that is not a convenience. Tokens are
 * bound to the password they were issued under (api_tokens.pwd_check), so the
 * moment the hash changes every existing token — including the one that
 * authorised this very request — stops working. Without handing back a fresh
 * one, a customer who changed their password would be thrown out to the login
 * screen by their own success. Every OTHER device is still signed out, which
 * is the behaviour you want: changing a password should end sessions you no
 * longer trust.
 */

require_once __DIR__ . '/_bootstrap.php';

only('POST');

$me = require_customer();

$current = (string)(body()['current_password'] ?? '');
$new1    = (string)(body()['new_password'] ?? '');
$new2    = (string)(body()['new_password_confirm'] ?? $new1);

// The password_hash column is stripped from api_user()'s return value, so it
// has to be read back here rather than trusted from the cached row.
$stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
$stmt->execute([$me['id']]);
$hash = $stmt->fetchColumn();

$errors = [];
if (!$hash || !password_verify($current, $hash)) $errors['current_password']     = 'كلمة السر الحالية غير صحيحة.';
if (mb_strlen($new1) < 8)                        $errors['new_password']         = 'كلمة السر 8 أحرف على الأقل.';
if ($new1 !== $new2)                             $errors['new_password_confirm'] = 'كلمتا السر غير متطابقتين.';

if ($errors) {
    json_error('من فضلك صحّح البيانات المميّزة.', 422, $errors);
}

// db() returns one shared PDO instance per request, so issue_token()'s own
// INSERT below runs inside this same transaction.
$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($new1, PASSWORD_BCRYPT), $me['id']]);

    // Sign every device out, then re-admit this one.
    $pdo->prepare('DELETE FROM api_tokens WHERE user_id = ?')->execute([$me['id']]);

    $fresh = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $fresh->execute([$me['id']]);
    $token = issue_token($fresh->fetch(), body_str('device_label'));

    $pdo->commit();
} catch (Throwable $ex) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $ex;
}

json_ok(['token' => $token, 'message' => 'تم تغيير كلمة السر بنجاح.']);
