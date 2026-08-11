<?php
/**
 * Tiny file-based cache.
 * ---------------------------------------------------------------------------
 * Added after load testing, not from a general instinct that "caching is good".
 *
 * WHAT THE MEASUREMENT SHOWED
 * With 1,010 products / 10,019 orders / 20,092 order items, the homepage
 * best-sellers query ran at a 133 ms median (205 ms worst). Six carefully
 * chosen composite indexes were added and re-measured: 131 ms. No improvement
 * worth the name.
 *
 * WHY INDEXES COULD NOT HELP
 * That query computes SUM(oi.quantity) per product across every order item,
 * then sorts by that computed total. An index can find rows quickly; it cannot
 * remove the need to read every matching row when the answer IS an aggregate
 * over all of them. The work is proportional to the order history and grows
 * with it, forever. Indexing was the right thing to try and the honest result
 * was that it did not apply.
 *
 * WHY CACHING IS THE RIGHT ANSWER HERE SPECIFICALLY
 * "Which phones sold most" is not a figure that needs to be accurate to the
 * second. It changes meaningfully over days. Recomputing it for every single
 * visitor to the homepage spends 133 ms of database time to produce an answer
 * that is almost always byte-identical to the one produced a minute earlier.
 * Ten minutes of staleness is invisible to a customer and removes the query
 * from the hot path entirely.
 *
 * WHY FILES AND NOT APCu/REDIS
 * Shared hosting frequently has neither. A file cache works everywhere PHP
 * writes to a temp directory, needs no extension, no daemon, and no
 * configuration. For a homepage fragment refreshed every ten minutes the
 * performance difference against an in-memory store is irrelevant.
 *
 * WHAT THIS IS NOT SUITABLE FOR
 * Anything a user must see immediately after they change it: cart contents,
 * order status, stock levels at checkout. Those stay uncached, deliberately.
 */

/**
 * The one cache key the project uses. Named here rather than repeated as a
 * string literal at the write site and again at every invalidation site — that
 * is how an invalidation quietly stops matching the thing it was meant to drop.
 */
const CACHE_KEY_API_BEST_SELLERS = 'api:home:best_sellers:v1';

/** Cache directory, outside the document root so nothing can be fetched directly. */
function cache_dir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'future_store_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function cache_path(string $key): string
{
    return cache_dir() . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.cache';
}

/**
 * Returns the cached value for $key, or null when absent or expired.
 */
function cache_get(string $key, int $ttlSeconds)
{
    $file = cache_path($key);
    if (!is_file($file)) return null;

    // Expiry is judged by file mtime rather than a timestamp stored inside the
    // payload: it costs one stat() instead of reading and decoding a file that
    // is about to be thrown away.
    if (time() - (int)@filemtime($file) > $ttlSeconds) return null;

    $raw = @file_get_contents($file);
    if ($raw === false) return null;

    $data = @unserialize($raw, ['allowed_classes' => false]);
    return $data === false ? null : $data;
}

/**
 * Stores a value.
 *
 * Written to a temporary file and then renamed into place. rename() is atomic
 * on the same filesystem, so a reader never sees a half-written cache file —
 * which, with plain file_put_contents under concurrent traffic, is exactly what
 * would eventually happen and would surface as a corrupt-looking homepage that
 * fixes itself on reload and is almost impossible to reproduce on purpose.
 */
function cache_set(string $key, $value): void
{
    $file = cache_path($key);
    $tmp  = $file . '.' . getmypid() . '.tmp';

    // allowed_classes => false on read means only plain data survives, so a
    // tampered cache file cannot instantiate an object during unserialize().
    if (@file_put_contents($tmp, serialize($value), LOCK_EX) !== false) {
        @rename($tmp, $file);
    }
}

/**
 * Fetches from cache, or runs $producer and caches its result.
 *
 * The pattern that matters: on any failure the producer still runs. A broken
 * or unwritable cache directory must degrade to "slower", never to "broken".
 */
function cache_remember(string $key, int $ttlSeconds, callable $producer)
{
    $hit = cache_get($key, $ttlSeconds);
    if ($hit !== null) return $hit;

    $value = $producer();
    cache_set($key, $value);
    return $value;
}

/**
 * Drops cached entries. Call after an admin edits products or an order changes
 * state, so the change appears without waiting out the TTL.
 *
 * With no prefix, clears everything.
 */
function cache_forget(string $prefix = ''): void
{
    // $prefix used to be accepted and then ignored — every call flushed
    // everything. Nothing passed a prefix yet, so it never misbehaved, but a
    // parameter that silently does nothing is a trap for whoever trusts the
    // signature next. Keys are hashed into the filename, so a prefix cannot be
    // matched by globbing; the key list has to be given explicitly.
    if ($prefix !== '') {
        @unlink(cache_path($prefix));
        return;
    }
    foreach (glob(cache_dir() . DIRECTORY_SEPARATOR . '*.cache') ?: [] as $f) {
        @unlink($f);
    }
}
