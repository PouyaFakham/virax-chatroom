<?php
/**
 * VIRAX — Request Governor (token bucket)
 * -------------------------------------------------------------
 * A tiny, dependency-free rate limiter used by the JSON endpoints.
 *
 * Why a file based bucket and not the database?
 *   • every DB write on this stack costs a real fsync (~40–60 ms on Windows /
 *     shared hosting), which would make the guard slower than the endpoint it
 *     is supposed to protect;
 *   • a bucket needs one atomic read-modify-write, which flock() gives us in
 *     well under a millisecond.
 *
 * Buckets are per user *and* per IP, per action class:
 *   read   → fetch / history / search / reactions (the polling traffic)
 *   send   → message + file send
 *   typing → typing indicator (the noisiest client call)
 *   write  → everything else that mutates state
 *   ip     → coarse safety net for the whole client IP
 *
 * The bucket allows a healthy burst (so a fast user never notices) and then
 * refills at a steady rate. When a client goes over, it gets HTTP 429 with a
 * `Retry-After` header and a JSON body the front-end understands, so it can
 * back off instead of hammering the server.
 */

if (!defined('VEYRAX_GUARD_LOADED')) {
    define('VEYRAX_GUARD_LOADED', true);

    /** Bucket definitions: capacity (burst) + refill rate per second. */
    function veyrax_guard_profiles()
    {
        return [
            'read'   => ['cap' => 50.0, 'rate' => 1.20], // ~72 req/min sustained
            'send'   => ['cap' => 20.0, 'rate' => 0.60], // ~36 req/min sustained
            'typing' => ['cap' => 10.0, 'rate' => 0.40],
            'write'  => ['cap' => 24.0, 'rate' => 0.70],
            'wait'   => ['cap' => 24.0, 'rate' => 2.00],
            'ip'     => ['cap' => 600.0, 'rate' => 10.0],
        ];
    }

    function veyrax_guard_map_action($action)
    {
        $read = ['fetch', 'fetch_history', 'search_messages', 'get_reactions', 'get_profile', 'phone_auth_check'];
        if (in_array($action, $read, true)) return 'read';
        if ($action === 'wait') return 'wait';
        if ($action === 'send') return 'send';
        if ($action === 'update_typing') return 'typing';
        return 'write';
    }

    function veyrax_guard_dir()
    {
        $dir = __DIR__ . '/data/rate';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir;
    }

    /**
     * Atomically consume `$cost` tokens from a bucket.
     *
     * @return array{ok:bool,retry_after:int,remaining:int}
     */
    function veyrax_guard_bucket($key, $capacity, $refill, $cost = 1.0)
    {
        $fallback = ['ok' => true, 'retry_after' => 0, 'remaining' => (int)$capacity];

        $dir = veyrax_guard_dir();
        if (!is_dir($dir) || !is_writable($dir)) return $fallback;

        $file = $dir . '/' . sha1((string)$key) . '.json';
        $fp = @fopen($file, 'c+');
        if (!$fp) return $fallback;

        $result = $fallback;
        if (@flock($fp, LOCK_EX)) {
            $raw   = stream_get_contents($fp);
            $now   = microtime(true);
            $state = json_decode((string)$raw, true);

            if (!is_array($state) || !isset($state['tokens'], $state['t'])) {
                $state = ['tokens' => $capacity, 't' => $now];
            }

            $elapsed = max(0.0, $now - (float)$state['t']);
            $tokens  = min($capacity, (float)$state['tokens'] + $elapsed * $refill);

            if ($tokens >= $cost) {
                $tokens -= $cost;
                $result = ['ok' => true, 'retry_after' => 0, 'remaining' => (int)floor($tokens)];
            } else {
                $need  = $cost - $tokens;
                $retry = (int)ceil($need / max(0.0001, $refill));
                $result = ['ok' => false, 'retry_after' => max(1, min(60, $retry)), 'remaining' => 0];
            }

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode(['tokens' => round($tokens, 3), 't' => $now]));
            fflush($fp);
            @flock($fp, LOCK_UN);
        }
        fclose($fp);

        // Housekeeping: drop buckets that have been idle for hours.
        if (random_int(1, 400) === 1) {
            foreach ((array)@glob($dir . '/*.json') as $f) {
                if (@filemtime($f) < time() - 7200) @unlink($f);
            }
        }

        return $result;
    }

    /**
     * Check both the per-user and the per-IP bucket.
     *
     * @return array{ok:bool,retry_after:int,remaining:int,scope:string}
     */
    function veyrax_guard_check($action, $userId = 0, $ip = '')
    {
        $profiles = veyrax_guard_profiles();
        $class    = veyrax_guard_map_action($action);

        $userBucket = $profiles[$class];
        $r = veyrax_guard_bucket('u:' . (int)$userId . ':' . $class, $userBucket['cap'], $userBucket['rate']);
        if (!$r['ok']) {
            $r['scope'] = $class;
            return $r;
        }

        if ($ip !== '') {
            $ipBucket = $profiles['ip'];
            $r2 = veyrax_guard_bucket('ip:' . $ip, $ipBucket['cap'], $ipBucket['rate']);
            if (!$r2['ok']) {
                $r2['scope'] = 'ip';
                return $r2;
            }
            $r['remaining'] = min($r['remaining'], $r2['remaining']);
        }

        $r['scope'] = $class;
        return $r;
    }

    /**
     * Enforce the limit for the current request. Emits 429 + Retry-After and
     * terminates the request when the client is over budget.
     */
    function veyrax_guard_enforce($action, $userId = 0, $ip = '')
    {
        $r = veyrax_guard_check($action, $userId, $ip);

        if (!headers_sent()) {
            header('X-RateLimit-Limit-Class: ' . $r['scope']);
            header('X-RateLimit-Remaining: ' . (int)$r['remaining']);
        }

        if ($r['ok']) return;

        if (!headers_sent()) {
            http_response_code(429);
            header('Retry-After: ' . (int)$r['retry_after']);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }

        echo json_encode([
            'status'      => 'rate_limited',
            'retry_after' => (int)$r['retry_after'],
            'message'     => 'تعداد درخواست‌ها زیاد است؛ لطفاً چند لحظه صبر کنید',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
