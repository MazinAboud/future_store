<?php
/**
 * POST /api/logout.php
 *
 * Deletes the token row behind the Authorization header, so the value the app
 * is about to forget cannot be replayed by anyone who captured it.
 * Sending an unknown or already-deleted token still returns 200: logging out
 * is not an operation the app should ever have to show an error for.
 */

require_once __DIR__ . '/_bootstrap.php';

only('POST');

$token = bearer_token();
if ($token !== null) {
    db()->prepare('DELETE FROM api_tokens WHERE token_hash = ?')
        ->execute([hash('sha256', $token)]);
}

json_ok(['message' => 'تم تسجيل الخروج.']);
