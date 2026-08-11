<?php
/**
 * Login throttling — brute-force protection.
 * ---------------------------------------------------------------------------
 * Before this file existed, every login path in the project accepted unlimited
 * password guesses at full speed: login.php, staff/login.php, and
 * api/login.php alike. bcrypt makes each guess expensive, which slows an
 * attacker down but does not stop one — and it does nothing at all against the
 * attack that actually works in practice, credential stuffing, where a list of
 * email/password pairs leaked from some other site is replayed here. Those
 * attempts need no guessing; they only need to be allowed to run.
 *
 * On a graduation-project server nobody notices. On a public host with real
 * customers it is the single most likely way an account is taken over.
 *
 * WHY FILES AND NOT A DATABASE TABLE
 * A table would be tidier, but it would need a migration run before the
 * protection took effect — and a security control that only works after
 * somebody remembers a manual step is a security control that will be missing
 * on the day it is needed. This works the moment the file is on disk.
 *
 * WHAT IS COUNTED
 * Two independent counters per attempt:
 *   - by IP     — stops one machine hammering many accounts.
 *   - by account— stops a botnet spread across many IPs hammering one account.
 * Either one tripping is enough to refuse. An attacker would have to stay under
 * both limits at once, which reduces the attempt rate to the point where bcrypt
 * makes the search hopeless.
 *
 * WHAT IS NOT CLAIMED
 * This is not a substitute for a real WAF or fail2ban, and a large distributed
 * botnet with one attempt per IP per hour would still get through eventually.
 * It closes the realistic attack, not every conceivable one.
 */

/** Where counters live. Outside the document root, so no request can read them. */
function throttle_dir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'future_store_throttle';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

/**
 * Path for one counter.
 *
 * The key is hashed rather than used directly in the filename. The key contains
 * an email address, and writing user email addresses into predictable filenames
 * on a shared host leaks who has accounts here to anyone who can list the temp
 * directory. The hash also guarantees a filesystem-safe name regardless of what
 * the user typed.
 */
function throttle_file(string $key): string
{
    return throttle_dir() . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
}

/** The caller's IP, taken from REMOTE_ADDR only. */
function throttle_ip(): string
{
    // X-Forwarded-For is deliberately NOT trusted here. Any client can send
    // that header with any value, so throttling on it would let an attacker
    // reset their own counter on every request by changing one string —
    // turning the protection into decoration. If this app is ever deployed
    // behind a reverse proxy, the proxy's real client IP should be wired in
    // here explicitly, after confirming the proxy overwrites the header rather
    // than appending to it.
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Reads a counter, discarding attempts older than the window.
 *
 * @return array{count:int, first:int, locked_until:int}
 */
function throttle_read(string $key, int $windowSeconds): array
{
    $file = throttle_file($key);
    $now  = time();
    $empty = ['count' => 0, 'first' => $now, 'locked_until' => 0];

    if (!is_file($file)) return $empty;

    $raw = @file_get_contents($file);
    if ($raw === false) return $empty;

    $data = json_decode($raw, true);
    if (!is_array($data)) return $empty;

    $data += $empty;

    // A live lock outranks the window: while locked, the counter is not reset
    // just because the original attempts aged out.
    if (($data['locked_until'] ?? 0) > $now) return $data;

    // Window expired with no active lock — start fresh.
    if ($now - ($data['first'] ?? $now) > $windowSeconds) return $empty;

    return $data;
}

/**
 * Records one failed attempt and applies a lock once the limit is reached.
 *
 * @return int Seconds remaining on the lock, or 0 if not locked.
 */
function throttle_fail(string $key, int $maxAttempts, int $windowSeconds, int $lockSeconds): int
{
    $now  = time();
    $data = throttle_read($key, $windowSeconds);

    if ($data['locked_until'] > $now) {
        return $data['locked_until'] - $now;
    }

    $data['count']++;
    if ($data['count'] === 1) $data['first'] = $now;

    if ($data['count'] >= $maxAttempts) {
        $data['locked_until'] = $now + $lockSeconds;
    }

    // LOCK_EX so two simultaneous failed logins cannot interleave their writes
    // and lose a count — which is exactly the race a fast attacker creates.
    @file_put_contents(throttle_file($key), json_encode($data), LOCK_EX);

    return $data['locked_until'] > $now ? $data['locked_until'] - $now : 0;
}

/**
 * Seconds left on an active lock, or 0 when the caller may attempt a login.
 * Call this BEFORE verifying the password.
 */
function throttle_blocked_for(string $key, int $windowSeconds): int
{
    $data = throttle_read($key, $windowSeconds);
    $left = $data['locked_until'] - time();
    return $left > 0 ? $left : 0;
}

/** Clears a counter. Call on successful login so honest users are never punished. */
function throttle_clear(string $key): void
{
    @unlink(throttle_file($key));
}

/* --------------------------------------------------------------------------
 * The one function the login pages actually call.
 * ------------------------------------------------------------------------*/

const THROTTLE_MAX_PER_IP      = 10;   // failures from one IP...
const THROTTLE_MAX_PER_ACCOUNT = 5;    // ...or against one account...
const THROTTLE_WINDOW          = 900;  // ...within 15 minutes...
const THROTTLE_LOCK            = 900;  // ...locks that key for 15 minutes.

/**
 * Seconds the caller must wait, or 0 if a login attempt is allowed right now.
 *
 * The per-account limit is deliberately stricter than the per-IP limit: several
 * people behind one office router or one mobile carrier NAT share an IP and
 * would otherwise lock each other out, whereas five wrong passwords on a single
 * account in fifteen minutes is already abnormal for a real user.
 */
function login_throttle_check(string $email): int
{
    $ipWait      = throttle_blocked_for('ip:' . throttle_ip(), THROTTLE_WINDOW);
    $accountWait = throttle_blocked_for('acct:' . mb_strtolower($email), THROTTLE_WINDOW);
    return max($ipWait, $accountWait);
}

/** Record a failed login against both counters. */
function login_throttle_fail(string $email): void
{
    throttle_fail('ip:' . throttle_ip(), THROTTLE_MAX_PER_IP, THROTTLE_WINDOW, THROTTLE_LOCK);
    throttle_fail('acct:' . mb_strtolower($email), THROTTLE_MAX_PER_ACCOUNT, THROTTLE_WINDOW, THROTTLE_LOCK);
}

/** Clear both counters after a successful login. */
function login_throttle_success(string $email): void
{
    throttle_clear('ip:' . throttle_ip());
    throttle_clear('acct:' . mb_strtolower($email));
}

/* --------------------------------------------------------------------------
 * General action rate limiting.
 *
 * The login counters above only fire on a FAILED attempt, which is the right
 * shape for password guessing and the wrong shape for everything else. Three
 * endpoints were left with no ceiling at all:
 *
 *   api/register.php        — unlimited account creation from one machine.
 *   api/change-password.php — a stolen token could grind current_password at
 *                             full speed; the login throttle never sees it
 *                             because this is not a login.
 *   api/order-create.php    — idempotency stops an accidental double tap, but
 *                             nothing stopped a loop from filling the orders
 *                             table and the employee queues with distinct
 *                             requests.
 *
 * So these count EVERY attempt, successful or not. The counters and the file
 * storage are the same ones the login throttle uses — one mechanism, not two.
 * ------------------------------------------------------------------------*/

/** Per-action limits: [max attempts, window seconds, lock seconds]. */
const ACTION_LIMITS = [
    // Creating accounts is rare for a real person and cheap for a script.
    'register'        => [5,  3600, 3600],
    // Generous for a real user, far below what a password-grinder needs.
    'change_password' => [10, 900,  900],
    // A customer placing 20 distinct orders in 15 minutes is already unusual.
    'order_create'    => [20, 900,  900],
];

/**
 * Seconds the caller must wait before this action is allowed, or 0.
 * Call BEFORE doing the work.
 *
 * $subject scopes the counter — an IP for anonymous actions, a user id for
 * authenticated ones. The IP is always counted as well, so one account cannot
 * be used as a shield and one machine cannot spread the load across accounts.
 */
function action_throttle_check(string $action, string $subject = ''): int
{
    [, $window] = ACTION_LIMITS[$action] ?? [0, 0, 0];
    if ($window === 0) return 0;

    $ipWait = throttle_blocked_for("act:$action:ip:" . throttle_ip(), $window);
    $subWait = $subject !== '' ? throttle_blocked_for("act:$action:sub:$subject", $window) : 0;
    return max($ipWait, $subWait);
}

/** Record one attempt against both counters. Call AFTER the check passes. */
function action_throttle_hit(string $action, string $subject = ''): void
{
    [$max, $window, $lock] = ACTION_LIMITS[$action] ?? [0, 0, 0];
    if ($window === 0) return;

    throttle_fail("act:$action:ip:" . throttle_ip(), $max, $window, $lock);
    if ($subject !== '') {
        throttle_fail("act:$action:sub:$subject", $max, $window, $lock);
    }
}

/** Human-readable wait time for an Arabic error message. */
function throttle_wait_label(int $seconds): string
{
    $minutes = (int)ceil($seconds / 60);
    if ($minutes <= 1) return 'دقيقة واحدة';
    if ($minutes === 2) return 'دقيقتين';
    if ($minutes <= 10) return $minutes . ' دقائق';
    return $minutes . ' دقيقة';
}
