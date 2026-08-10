<?php
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements
        ];
        try {
            // Primary credentials, as set in config.php.
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Most local XAMPP installs never had DB_USER created and only have
            // the default "root" account with an empty password. Falling back to
            // it keeps a fresh local setup working out of the box.
            //
            // That fallback is now GATED TO LOCAL REQUESTS ONLY, and the gate is
            // the point of this block. On a public host the same code was a real
            // hole: if the configured credentials ever stopped working — a
            // password rotated, a user dropped, a typo in a deploy — the site
            // would silently retry as root with no password instead of failing.
            // On any server where root@localhost is reachable without a password
            // (a misconfiguration that is common enough to be worth assuming),
            // the entire application would then be running with full database
            // superuser rights, and nothing on the page would look wrong. A
            // failure that repairs itself into a privilege escalation is far
            // worse than a failure that stops.
            //
            // is_local_request() is checked against the SERVER address, not any
            // client-supplied header. REMOTE_ADDR can be spoofed only by someone
            // who already controls the network path; X-Forwarded-For, which is
            // trivially forged, is deliberately not consulted.
            $isLocal = in_array($_SERVER['SERVER_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
                || in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
                || PHP_SAPI === 'cli';

            if ($isLocal && DB_USER !== 'root') {
                try {
                    $pdo = new PDO($dsn, 'root', '', $options);
                    error_log("DB: connected using fallback 'root' account (LOCAL request only) — DB_USER/DB_PASS in includes/config.php do not match this MySQL server (" . $e->getMessage() . ')');
                    return $pdo;
                } catch (PDOException $e2) {
                    error_log('DB connection failed with configured credentials (' . $e->getMessage() . ') and with root fallback (' . $e2->getMessage() . ')');
                }
            } else {
                error_log('DB connection failed with configured credentials (' . $e->getMessage() . '). Root fallback refused: request is not local.');
            }

            http_response_code(500);
            // The message deliberately says nothing about which credentials
            // failed, which user was tried, or which database was targeted.
            // The detail is in the PHP error log, where the operator can read
            // it and a visitor cannot.
            die('تعذر الاتصال بقاعدة البيانات. حاول مرة أخرى بعد قليل.');
        }
    }
    return $pdo;
}
