<?php

$app_url = '';
$app_timezone = 'Asia/Tehran';

$installer_lock_file = __DIR__ . '/data/veyrax_installed.php';
$installer_flag = false;
if (file_exists($installer_lock_file)) {
    $installer_flag = @file_get_contents($installer_lock_file);
    $installer_flag = (is_string($installer_flag) && strpos($installer_flag, 'veyrax-installed') !== false);
}

if (!$installer_flag) {
    require_once __DIR__ . '/install.php';
    exit;
}

/**
 * ─────────────────────────────────────────────────────────────────────────────
 *  Runtime configuration — connection & bootstrap credentials
 * ─────────────────────────────────────────────────────────────────────────────
 *  This file ships with every copy of the application, so it must never carry
 *  real credentials. Every value below resolves in a strict order:
 *
 *    1. Environment variables — the recommended way on Docker, CI and panel
 *       deploys; secrets never touch the repository.
 *    2. data/config.php — written by install.php on first run; excluded from
 *       version control and overrides everything below it.
 *    3. Built-in defaults — deliberately empty. They only exist so the app can
 *       boot, detect a missing configuration and show a clear message (or the
 *       installer) instead of a blank page or a PDO stack trace.
 *
 *  Supported environment variables (all optional):
 *
 *    DB_HOST  DB_PORT  DB_NAME  DB_USER  DB_PASS
 *    ADMIN_USERNAME  ADMIN_DISPLAY_NAME  ADMIN_PASSWORD
 */
function veyrax_env(string $key, $default = null)
{
    $value = getenv($key);
    if ($value !== false) return $value;
    if (isset($_ENV[$key]))    return (string)$_ENV[$key];
    if (isset($_SERVER[$key])) return (string)$_SERVER[$key];
    return $default;
}

$db_host = veyrax_env('DB_HOST', '127.0.0.1');
$db_port = veyrax_env('DB_PORT', 3306);
$db_name = veyrax_env('DB_NAME', '');
$db_user = veyrax_env('DB_USER', '');
$db_pass = veyrax_env('DB_PASS', '');

$admin_username        = veyrax_env('ADMIN_USERNAME', '');
$admin_display_name    = veyrax_env('ADMIN_DISPLAY_NAME', '');
$admin_password        = veyrax_env('ADMIN_PASSWORD', '');
$admin_pinned_username = $admin_username;

$font_text = 'Kalameh1';
$font_heading = 'Kalameh';
$favicon_path = 'assets/brand/favicon-32.png';
$favicon_base = 'assets/brand';

$site_brand_fa = 'ویراکس';
$site_brand_en = 'VIRAX';

$social_github_link = '';
$social_github_icon = 'icons/github.png';
$social_website_link = '';
$social_website_icon = 'icons/website.png';
$social_telegram_link = '';
$social_telegram_icon = 'icons/telegram.png';

$source_download_link = '';
$donate_link = '';

$trusted_proxies = [];

$ws_enabled = true;
$ws_url = '';
$ws_internal_url = 'http://127.0.0.1:3000';
$ws_secret = 'veyrax-ws-secret-change-me';
$ws_allowed_origins = '*';
$ws_reconnect_interval = 5;

$ws_db_settings_loaded = false;
// $ws_runtime is built after data/config.php is loaded (see below) so that installer
// generated values (especially $ws_secret) are not silently replaced by defaults.

$message_lifetime = 72 * 3600;
$file_size_limit = 50 * 1024 * 1024;
$spam_limit_count = 10;
$spam_limit_time = 10;
$ban_duration = 120;

$enable_intro_popup = true;

$stickers = ['😎', '👻', '🤖', '🐱', '👤', '🦊', '🦁', '🐸', '🐼', '🐲', '⭐', '🔥', '💀', '👽', '🤡', '👺', '👹', '💖', '🚀', '✅', '❌', '⚠'];

$allowed_extensions = ['jpg','jpeg','png','gif','webp','mp4','mov','avi','mkv','mp3','ogg','wav','pdf','zip','rar','7z','txt','doc','docx','xls','xlsx','ppt','pptx','apk','exe','dmg','iso','svg','ico','bmp','tiff','csv','npv'];

/**
 * Branded fatal page for database/connection failures. Self-contained inline
 * CSS + Kalameh fonts so it renders beautifully even when the app cannot boot.
 */
function veyrax_fatal_page($icon, $title, $message, $hintHtml = '', $actionsHtml = '') {
    header('Content-Type: text/html; charset=utf-8');
    header('HTTP/1.1 503 Service Unavailable', true, 503);
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
        . '<style>'
        . "@font-face{font-family:'AppFont';src:url('fonts/Kalameh.ttf') format('truetype');font-display:swap}"
        . "@font-face{font-family:'AppBold';src:url('fonts/Kalameh1.ttf') format('truetype');font-display:swap}"
        . '*{box-sizing:border-box}html,body{margin:0;min-height:100%}'
        . "body{font-family:'AppFont','Vazirmatn',Tahoma,sans-serif;background:radial-gradient(120% 80% at 50% -10%,rgba(99,102,241,0.22),transparent 60%),radial-gradient(90% 70% at 100% 110%,rgba(34,211,238,0.10),transparent 65%),linear-gradient(165deg,#101828 0%,#0b1220 60%,#080d18 100%);color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:22px}"
        . '.card{width:100%;max-width:400px;text-align:center;background:linear-gradient(168deg,rgba(30,41,59,0.72),rgba(15,23,42,0.85));border:1px solid rgba(255,255,255,0.08);border-radius:26px;padding:34px 26px 26px;position:relative;overflow:hidden;box-shadow:0 40px 80px -30px rgba(0,0,0,0.9),inset 0 1px 0 rgba(255,255,255,0.06)}'
        . ".card::before{content:'';position:absolute;inset:0 0 auto;height:150px;pointer-events:none;background:radial-gradient(60% 100% at 50% 0%,rgba(255,255,255,0.09),transparent 70%)}"
        . '.mark{width:64px;height:64px;border-radius:20px;margin:0 auto 18px;display:flex;align-items:center;justify-content:center;font-size:27px;color:#fff;background:linear-gradient(145deg,#f59e0b,#ef4444 70%);box-shadow:0 18px 40px -16px rgba(245,158,11,0.8),inset 0 1px 0 rgba(255,255,255,0.3)}'
        . ".title{font-family:'AppBold','AppFont',Tahoma,sans-serif;font-size:18.5px;color:#fff;margin:0 0 8px}"
        . '.sub{font-size:13px;color:#94a3b8;line-height:2;margin:0 0 20px}'
        . ".btn{display:inline-flex;align-items:center;justify-content:center;width:100%;padding:12px 16px;border-radius:14px;border:0;cursor:pointer;font-family:'AppBold','AppFont',Tahoma,sans-serif;font-size:14px;color:#fff;background:linear-gradient(135deg,#6366f1,#4f46e5);box-shadow:0 14px 30px -14px rgba(99,102,241,0.85);text-decoration:none;transition:transform .2s ease}"
        . '.btn:hover{transform:translateY(-2px)}.btn.ghost{background:rgba(148,163,184,0.12);border:1px solid rgba(148,163,184,0.22);box-shadow:none;color:#cbd5e1}'
        . '.actions{display:flex;flex-direction:column;gap:9px;margin-top:6px}'
        . '.hint{margin-top:16px;padding-top:14px;border-top:1px dashed rgba(148,163,184,0.18);font-size:11.5px;color:#64748b;line-height:2}'
        . '.hint code{background:rgba(148,163,184,0.12);padding:1px 6px;border-radius:6px;color:#94a3b8;font-family:Consolas,monospace;direction:ltr;display:inline-block}'
        . '</style></head><body><div class="card"><div class="mark">' . $icon . '</div>'
        . '<h1 class="title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p class="sub">' . $message . '</p>'
        . ($actionsHtml !== '' ? '<div class="actions">' . $actionsHtml . '</div>' : '')
        . ($hintHtml !== '' ? '<div class="hint">' . $hintHtml . '</div>' : '')
        . '</div></body></html>';
    exit;
}

if (!file_exists(__DIR__ . '/data/config.php')) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>ویراکس</title></head><body style="background:#0f172a;color:#fff;font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;"><div style="text-align:center;"><div style="font-size:40px;margin-bottom:10px;">🛠</div><div>فایل پیکربندی یافت نشد. لطفا نصب را دوباره اجرا کنید: <a style="color:#818cf8" href="install.php">install.php</a></div></div></body></html>';
    exit;
}

require_once __DIR__ . '/data/config.php';

// ── Environment variables win over the installer file ───────────────────────
// Docker, CI and panel deploys inject credentials as env vars; they must take
// precedence over whatever install.php wrote into data/config.php, otherwise a
// container could silently run against a stale file copy.
$veyrax_env_map = [
    'db_host'            => 'DB_HOST',
    'db_port'            => 'DB_PORT',
    'db_name'            => 'DB_NAME',
    'db_user'            => 'DB_USER',
    'db_pass'            => 'DB_PASS',
    'admin_username'     => 'ADMIN_USERNAME',
    'admin_display_name' => 'ADMIN_DISPLAY_NAME',
    'admin_password'     => 'ADMIN_PASSWORD',
];
foreach ($veyrax_env_map as $veyrax_var => $veyrax_env_key) {
    $veyrax_env_value = veyrax_env($veyrax_env_key);
    if ($veyrax_env_value !== null) $$veyrax_var = $veyrax_env_value;
}
unset($veyrax_env_map, $veyrax_var, $veyrax_env_key, $veyrax_env_value);
$admin_pinned_username = $admin_username;

// A missing connection block must never leak a PDO stack trace or a blank
// page. data/config.php (or the env vars) is the only supported source.
if ($db_name === '' || $db_user === '') {
    if (function_exists('veyrax_fatal_page')) {
        veyrax_fatal_page(
            '🔌',
            'اتصال دیتابیس تنظیم نشده است',
            'اطلاعات اتصال دیتابیس در دسترس نیست؛ نه متغیرهای محیطی تعریف شده‌اند و نه فایل تنظیمات نصب موجود است.',
            'فایل <code>data/config.php</code> را بررسی کنید یا نصب را از نو اجرا کنید: <a href="install.php">install.php</a>'
        );
    } else {
        echo 'Database configuration missing: create data/config.php (run install.php).';
    }
    exit;
}

$ws_runtime = [
    'enabled' => !empty($ws_enabled),
    'url' => isset($ws_url) ? $ws_url : '',
    'internal_url' => isset($ws_internal_url) && $ws_internal_url !== '' ? $ws_internal_url : 'http://127.0.0.1:3000',
    'secret' => isset($ws_secret) && $ws_secret !== '' ? $ws_secret : 'veyrax-ws-secret-change-me',
    'origins' => isset($ws_allowed_origins) ? $ws_allowed_origins : '*',
    'reconnect' => isset($ws_reconnect_interval) ? (int)$ws_reconnect_interval : 5,
];

if (!defined('ENCRYPTION_KEY') || !is_string(ENCRYPTION_KEY) || strlen(ENCRYPTION_KEY) < 16) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8"><title>ویراکس</title></head><body style="background:#0f172a;color:#fff;font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;"><div style="text-align:center;"><div style="font-size:40px;margin-bottom:10px;">🔑</div><div>کلید رمزنگاری نامعتبر است. نصب را دوباره اجرا کنید: <a style="color:#818cf8" href="install.php">install.php</a></div></div></body></html>';
    exit;
}

date_default_timezone_set(isset($app_timezone) && $app_timezone ? $app_timezone : 'Asia/Tehran');

if (file_exists('jdf.php')) {
    require_once 'jdf.php';
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');
ini_set('memory_limit', '512M');

ini_set('session.use_only_cookies', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 1 : 0);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 259200);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 259200,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    $sessionStarted = false;
    $maxRetries = 3;

    for ($i = 0; $i < $maxRetries && !$sessionStarted; $i++) {
        try {
            if ($i > 0) {
                if (isset($_COOKIE[session_name()])) {
                    setcookie(session_name(), '', time() - 3600, '/');
                    unset($_COOKIE[session_name()]);
                }
                session_id(bin2hex(random_bytes(16)));
            }
            if (@session_start()) {
                $sessionStarted = true;
            }
        } catch (Exception $e) {
            error_log("Session error attempt $i: " . $e->getMessage());
            if ($i < $maxRetries - 1) usleep(50000);
        }
    }

    if (!$sessionStarted) {
        if (isset($_COOKIE[session_name()])) setcookie(session_name(), '', time() - 3600, '/');
        session_id(bin2hex(random_bytes(16)));
        @session_start();
    }
}

if (!defined('IV_LENGTH')) define('IV_LENGTH', openssl_cipher_iv_length('aes-256-cbc'));

function send_security_headers() {
    if (headers_sent()) return;
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 1; mode=block');
    header('Permissions-Policy: camera=(self), microphone=(self), geolocation=()');
}
send_security_headers();

// Compress dynamic pages/JSON on the fly. index.php ships its CSS/JS inline,
// so gzip cuts the chat page from ~450 KB to well under 100 KB on slow links.
if (PHP_SAPI !== 'cli' && !headers_sent() && extension_loaded('zlib') && !ini_get('zlib.output_compression') && ob_get_level() < 2) {
    $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
    if (stripos($acceptEncoding, 'gzip') !== false) {
        @ob_start('ob_gzhandler');
    }
}

function verify_admin_password($input, $stored) {
    if (!is_string($stored)) return false;
    $info = password_get_info($stored);
    if ($info['algo'] !== null && $info['algo'] !== 0) {
        return password_verify($input, $stored);
    }
    return hash_equals($stored, $input);
}

function ws_load_runtime_settings($pdo) {
    global $ws_runtime, $ws_db_settings_loaded;
    if ($ws_db_settings_loaded || !$pdo instanceof PDO) return;
    try {
        $rows = $pdo->query("SELECT name, value FROM settings WHERE name LIKE 'ws_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (isset($rows['ws_enabled'])) $ws_runtime['enabled'] = ($rows['ws_enabled'] === '1');
        if (isset($rows['ws_url'])) $ws_runtime['url'] = $rows['ws_url'];
        if (isset($rows['ws_internal_url']) && $rows['ws_internal_url'] !== '') $ws_runtime['internal_url'] = $rows['ws_internal_url'];
        if (isset($rows['ws_secret']) && $rows['ws_secret'] !== '') $ws_runtime['secret'] = $rows['ws_secret'];
        if (isset($rows['ws_origins'])) $ws_runtime['origins'] = $rows['ws_origins'];
        if (isset($rows['ws_reconnect']) && (int)$rows['ws_reconnect'] > 0) $ws_runtime['reconnect'] = (int)$rows['ws_reconnect'];
        $ws_db_settings_loaded = true;
    } catch (Exception $e) {}
}

function ws_issue_token($userId, $username) {
    global $ws_runtime;
    $payload = base64_encode(json_encode(['uid' => (int)$userId, 'u' => $username, 'exp' => time() + 90, 'n' => bin2hex(random_bytes(8))]));
    $sig = hash_hmac('sha256', $payload, $ws_runtime['secret']);
    return $payload . '.' . $sig;
}

/**
 * Is a realtime hop even possible right now?
 *
 * Before this check every send opened a cURL handle to the Socket.IO server.
 * When that server is not running (the normal case on WAMP / shared hosting)
 * the connect either waited for the timeout or cost a full round-trip after
 * the response had already been flushed — ~70 ms of pure dead weight on every
 * single message. A 200 ms socket probe is cached for 60 s in
 * data/ws_push_state.json, so a dead endpoint is paid for once a minute
 * instead of once per message.
 */
function ws_push_reachable() {
    global $ws_runtime;
    static $reachable = null;
    if ($reachable !== null) return $reachable;

    if (empty($ws_runtime['enabled']) || empty($ws_runtime['internal_url'])) {
        return $reachable = false;
    }

    $stateFile = __DIR__ . '/data/ws_push_state.json';
    $now = time();
    $state = [];
    if (is_file($stateFile)) {
        $raw = @file_get_contents($stateFile);
        if ($raw) $state = json_decode($raw, true);
        if (is_array($state) && !empty($state['down_until']) && (int)$state['down_until'] > $now) {
            return $reachable = false;
        }
    }

    $parts = @parse_url($ws_runtime['internal_url']);
    if (!is_array($parts) || empty($parts['host'])) return $reachable = false;

    $host = $parts['host'];
    $port = isset($parts['port'])
        ? (int)$parts['port']
        : ((($parts['scheme'] ?? 'http') === 'https') ? 443 : 80);

    $alive = false;
    if (function_exists('curl_init')) {
        // A 5 ms connect budget: cURL aborts the handshake almost instantly on
        // a closed/filtered port, unlike fsockopen which sits on the Windows
        // TCP timeout (~200 ms) for exactly the same check.
        $ch = curl_init($ws_runtime['internal_url']);
        curl_setopt_array($ch, [
            CURLOPT_CONNECTTIMEOUT_MS => 5,
            CURLOPT_TIMEOUT_MS        => 8,
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_NOSIGNAL          => 1,
            CURLOPT_NOBODY            => 1,
        ]);
        curl_exec($ch);
        $err         = curl_errno($ch);
        $connectTime = (float)curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        curl_close($ch);
        // 0 = the server answered. 28 = we ran out of time, which only counts
        // as "alive" when the TCP handshake itself had already completed
        // (connect_time > 0) — a filtered port never gets that far.
        $alive = ($err === 0) || ($err === 28 && $connectTime > 0);
    } else {
        $fp = @fsockopen($host, $port, $errno, $errstr, 0.05);
        if ($fp) { @fclose($fp); $alive = true; }
    }

    if (!$alive) {
        @file_put_contents($stateFile, json_encode(['down_until' => $now + 60, 'checked_at' => $now]), LOCK_EX);
        return $reachable = false;
    }

    if (!empty($state['down_until'])) {
        @file_put_contents($stateFile, json_encode(['down_until' => 0, 'checked_at' => $now]), LOCK_EX);
    }
    return $reachable = true;
}

/**
 * Is the realtime server running on this very machine?
 *
 * When it is, realtime events travel through the local outbox file (see
 * ws_push()) instead of an HTTP hop. That removes the last blocking call from
 * the send path: publishing an event becomes one append to a file — roughly
 * 0.05 ms — instead of a cURL round-trip whose cost the sender used to pay
 * because mod_php cannot finish the response before the shutdown hook runs.
 */
function ws_push_is_local() {
    global $ws_runtime;
    if (empty($ws_runtime['internal_url'])) return false;
    $parts = @parse_url($ws_runtime['internal_url']);
    if (!is_array($parts) || empty($parts['host'])) return false;
    $host = strtolower($parts['host']);
    return in_array($host, ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
}

function ws_outbox_path() {
    return __DIR__ . '/data/ws_outbox.log';
}

/**
 * Publish a realtime event.
 *
 * Local server  → append one JSON line to data/ws_outbox.log. The Node process
 *                 tails that file and fans the event out to sockets. Nothing
 *                 here touches the network, so the message response is never
 *                 held hostage by the realtime hop.
 * Remote server → HTTP POST to /push (the original behaviour).
 */
function ws_push($event, $data) {
    global $ws_runtime;
    if (empty($ws_runtime['enabled'])) return;

    if (ws_push_is_local()) {
        $line = json_encode(['e' => (string)$event, 'd' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false) return;
        $file = ws_outbox_path();
        // A dead socket server must not grow the outbox forever.
        $size = @filesize($file);
        if ($size !== false && $size > 1048576) { @file_put_contents($file, '', LOCK_EX); }
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        return;
    }

    if (!ws_push_reachable()) return;
    $url = rtrim($ws_runtime['internal_url'], '/') . '/push';
    $body = json_encode(['secret' => $ws_runtime['secret'], 'event' => $event, 'data' => $data]);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT_MS => 60,
            CURLOPT_CONNECTTIMEOUT_MS => 40,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOSIGNAL => 1,
        ]);
        curl_exec($ch);
        curl_close($ch);
        return;
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nConnection: Close\r\n",
            'content' => $body,
            'timeout' => 0.06,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    @file_get_contents($url, false, $ctx);
}

/**
 * Kept as a separate name because callers read as "do not wait for this".
 * With the outbox there is nothing left to wait for: the append is the whole
 * operation, so both names do exactly the same, instant thing.
 */
function ws_push_async($event, $data) {
    ws_push($event, $data);
}

function veyrax_note_deletion($msgId, $fromId, $toId, $roomName) {
    $f = __DIR__ . '/data/deleted_msgs.json';
    $fp = @fopen($f, 'c+');
    if (!$fp) return;
    if (@flock($fp, LOCK_EX)) {
        $raw = stream_get_contents($fp);
        $list = json_decode((string)$raw, true);
        if (!is_array($list)) $list = [];
        $now = time();
        $list = array_values(array_filter($list, function ($e) use ($now) {
            return ($now - (int)($e['t'] ?? 0)) < 600;
        }));
        $list[] = ['id' => (int)$msgId, 'from' => (int)$fromId, 'to' => (int)$toId, 'room' => (string)$roomName, 't' => $now];
        if (count($list) > 300) $list = array_slice($list, -300);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($list));
        fflush($fp);
        @flock($fp, LOCK_UN);
    }
    fclose($fp);
}

function veyrax_recent_deletions($viewerId, $roomName, $peerId, $maxAge = 300) {
    $f = __DIR__ . '/data/deleted_msgs.json';
    if (!is_file($f)) return [];
    $list = json_decode((string)@file_get_contents($f), true);
    if (!is_array($list)) return [];
    $now = time();
    $out = [];
    foreach ($list as $e) {
        if (($now - (int)($e['t'] ?? 0)) > $maxAge) continue;
        $from = (int)($e['from'] ?? 0);
        $to = (int)($e['to'] ?? 0);
        $room = (string)($e['room'] ?? '');
        $match = false;
        if ($room !== '' && $room !== 'null') {
            $match = ($room === (string)$roomName);
        } else {
            $match = ($from === (int)$viewerId || $to === (int)$viewerId);
        }
        if ($match) $out[] = (int)$e['id'];
    }
    return array_values(array_unique($out));
}

function encrypt_data($data) {
    $iv = openssl_random_pseudo_bytes(IV_LENGTH);
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', ENCRYPTION_KEY, 0, $iv);
    return base64_encode($iv . $encrypted);
}

function decrypt_data($data) {
    $data = base64_decode($data);
    if (strlen($data) < IV_LENGTH) return false;
    $iv = substr($data, 0, IV_LENGTH);
    $encrypted = substr($data, IV_LENGTH);
    return openssl_decrypt($encrypted, 'aes-256-cbc', ENCRYPTION_KEY, 0, $iv);
}

/**
 * Pinned messages used to be stored as plaintext: chat.php decrypted the
 * source message and wrote the readable copy straight into pinned_messages,
 * and the read path never decrypted anything. One `SELECT *` on that table
 * (or a backup, or a shared host's phpMyAdmin) exposed every pinned message in
 * clear text, which completely bypassed the AES layer used everywhere else.
 *
 * New rows are encrypted. Rows written before this change stay readable
 * through the fallback below, and `veyrax_upgrade()` rewrites them in place.
 */
function veyrax_pin_text_encode($plain) {
    $plain = (string)$plain;
    if ($plain === '') return '';
    return encrypt_data($plain);
}

function veyrax_pin_text_decode($stored) {
    $stored = (string)$stored;
    if ($stored === '') return '';
    $plain = decrypt_data($stored);
    if (is_string($plain) && $plain !== '') return $plain;
    return $stored; // legacy plaintext row (or an empty/very short value)
}

/**
 * Does this value look like our AES container (base64 of IV + ciphertext)?
 *
 * Needed before rewriting a row: if the key ever changes, decrypt_data() fails
 * on perfectly valid ciphertext, and re-encrypting the ciphertext would double
 * wrap it and destroy the message. Only values that are clearly *not* ours get
 * re-encrypted.
 */
function veyrax_pin_text_is_cipher($stored) {
    $stored = (string)$stored;
    if ($stored === '') return false;
    $raw = base64_decode($stored, true);
    if ($raw === false || strlen($raw) <= IV_LENGTH) return false;
    // encrypt_data() keeps the ciphertext as base64 and then base64s the whole
    // container, so the payload after the IV is base64 of a whole number of
    // AES blocks. Checking that is what stops a second pass from wrapping an
    // already encrypted value again.
    $inner = base64_decode(substr($raw, IV_LENGTH), true);
    if ($inner === false) return false;
    return strlen($inner) > 0 && (strlen($inner) % 16) === 0;
}

/**
 * Files get their own container format.
 *
 * Messages are small and live in a TEXT column, so they must stay base64 —
 * raw ciphertext is not valid UTF-8 and a utf8mb4 column would mangle it.
 * Files have no such constraint, so they can skip the double base64 that used
 * to turn a 50 MB video into roughly 200 MB of intermediate strings and blow
 * the 128 MB memory limit.
 *
 * Layout: "VXFILE1\n" + 16-byte IV + raw AES-256-CBC ciphertext.
 * Anything without the magic is a legacy blob and is handed to decrypt_data().
 */
if (!defined('VEYRAX_FILE_MAGIC')) define('VEYRAX_FILE_MAGIC', "VXFILE1\n");

function veyrax_raise_memory_for($bytes) {
    if ($bytes < 8 * 1024 * 1024) return;
    $want = (int)($bytes * 2.4) + 32 * 1024 * 1024;
    $cur  = ini_get('memory_limit');
    if ($cur === false || $cur === '' ) return;
    if ((int)$cur === -1) return;                       // already unlimited
    $curBytes = (int)$cur;
    $unit = strtolower(substr(trim((string)$cur), -1));
    if ($unit === 'g') $curBytes *= 1024 * 1024 * 1024;
    elseif ($unit === 'm') $curBytes *= 1024 * 1024;
    elseif ($unit === 'k') $curBytes *= 1024;
    if ($curBytes >= $want) return;
    @ini_set('memory_limit', (string)$want);
}

function encrypt_file_data($data) {
    veyrax_raise_memory_for(strlen($data));
    $iv = openssl_random_pseudo_bytes(IV_LENGTH);
    $raw = openssl_encrypt($data, 'aes-256-cbc', ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
    if ($raw === false) return false;
    return VEYRAX_FILE_MAGIC . $iv . $raw;
}

function decrypt_file_data($data) {
    if (!is_string($data) || $data === '') return false;
    $magicLen = strlen(VEYRAX_FILE_MAGIC);
    if (strncmp($data, VEYRAX_FILE_MAGIC, $magicLen) !== 0) {
        return decrypt_data($data);                     // legacy blob
    }
    veyrax_raise_memory_for(strlen($data));
    $iv  = substr($data, $magicLen, IV_LENGTH);
    $raw = substr($data, $magicLen + IV_LENGTH);
    if (strlen($iv) < IV_LENGTH || $raw === '') return false;
    return openssl_decrypt($raw, 'aes-256-cbc', ENCRYPTION_KEY, OPENSSL_RAW_DATA, $iv);
}

function get_client_ip() {
    global $trusted_proxies;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (!empty($trusted_proxies) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        if (in_array($remote, $trusted_proxies, true)) {
            $chain = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
            $chain = array_filter($chain, 'strlen');
            if (!empty($chain)) {
                $candidate = end($chain);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) $ip = $candidate;
            }
        }
    }
    return $ip;
}

function xss_clean($data) {
    if (is_array($data)) {
        return array_map('xss_clean', $data);
    }
    $data = trim($data);
    $data = strip_tags($data);
    $data = htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $data;
}

function sanitize_message($text) {
    $text = trim($text);
    $text = strip_tags($text);
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $text;
}

function is_admin_user($username, $pdo) {
    global $admin_username;
    if ($username === $admin_username) return true;
    $stmt = $pdo->prepare("SELECT id FROM admin_users WHERE username = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$username]);
    return (bool)$stmt->fetch();
}

// ─────────────────────────────────────────────────────────────────────────────
// Ban ledger — one identity, many signals.
//
// A ban used to be an IP row plus a column on the user. That leaks: the same
// person opens another browser, gets a fresh device_token and a different
// User-Agent, and the registration counter starts from zero again.
//
// We now store every signal we can observe for the banned user:
//   ip   → their address
//   fp   → their browser fingerprint (canvas + WebGL + UA)
//   fpc  → their *device* fingerprint, stable across browsers
//   tok  → their device_token cookie
//   ua   → ip + sha256(User-Agent), the cheap pair that survives a wiped cookie
// Any single match blocks the session, so switching browsers no longer helps.
// ─────────────────────────────────────────────────────────────────────────────

function veyrax_fp_clean($value) {
    $v = strtolower((string)$value);
    $v = preg_replace('/[^a-z0-9]/', '', $v);
    return substr($v, 0, 64);
}

function veyrax_client_fp() {
    static $fp = null;
    if ($fp !== null) return $fp;
    $fp = '';
    if (!empty($_COOKIE['vx_fp'])) $fp = veyrax_fp_clean($_COOKIE['vx_fp']);
    if ($fp === '' && !empty($_POST['fp'])) $fp = veyrax_fp_clean($_POST['fp']);
    if ($fp === '' && !empty($_SERVER['HTTP_X_VX_FP'])) $fp = veyrax_fp_clean($_SERVER['HTTP_X_VX_FP']);
    return $fp;
}

function veyrax_client_fpc() {
    static $fpc = null;
    if ($fpc !== null) return $fpc;
    $fpc = '';
    if (!empty($_COOKIE['vx_fpc'])) $fpc = veyrax_fp_clean($_COOKIE['vx_fpc']);
    if ($fpc === '' && !empty($_POST['fp_coarse'])) $fpc = veyrax_fp_clean($_POST['fp_coarse']);
    if ($fpc === '' && !empty($_SERVER['HTTP_X_VX_FPC'])) $fpc = veyrax_fp_clean($_SERVER['HTTP_X_VX_FPC']);
    return $fpc;
}

/**
 * Build the full signal list for the current request.
 *
 * `fpc` and `ua` are scoped to the IP on purpose: a bare device hash (screen +
 * timezone + platform + cores) is identical for every owner of the same laptop
 * model, so matching it globally would ban innocent strangers. Pairing it with
 * the address keeps the useful part — "same machine on this network, different
 * browser" — without the collateral damage.
 */
function veyrax_ban_signals_for($ip, $fp = null, $fpc = null, $deviceToken = null, $ua = null) {
    $ip  = trim((string)$ip);
    $fp  = ($fp === null)  ? veyrax_client_fp()  : veyrax_fp_clean($fp);
    $fpc = ($fpc === null) ? veyrax_client_fpc() : veyrax_fp_clean($fpc);
    $deviceToken = trim((string)$deviceToken);
    $ua = ($ua === null) ? (string)($_SERVER['HTTP_USER_AGENT'] ?? '') : (string)$ua;

    $out = [];
    if ($ip !== '')  $out[] = ['ip',  $ip];
    if ($fp !== '')  $out[] = ['fp',  $fp];
    if ($ip !== '' && $fpc !== '') $out[] = ['fpc', $ip . '|' . $fpc];
    if ($deviceToken !== '') $out[] = ['tok', substr($deviceToken, 0, 190)];
    if ($ip !== '' && $ua !== '') $out[] = ['ua', $ip . '|' . hash('sha256', $ua)];
    return $out;
}

/**
 * Return the first active ban row matching any of $signals, or null.
 * One indexed query: signal_value is the leading column of the unique key, so
 * `signal_value IN (...)` is a range scan, and the exact pair is verified in
 * PHP afterwards (an ip string can never collide with a fingerprint hash).
 */
function veyrax_ban_lookup($pdo, array $signals) {
    if (!$signals) return null;
    $values = [];
    $pairs = [];
    foreach ($signals as $s) {
        $values[$s[1]] = true;
        $pairs[$s[0] . "\0" . $s[1]] = true;
    }
    $values = array_keys($values);
    if (!$values) return null;
    $in = implode(',', array_fill(0, count($values), '?'));
    $args = $values;
    $args[] = time();
    try {
        $st = $pdo->prepare("SELECT signal_type, signal_value, reason, is_permanent, expires_at, username
                             FROM ban_signals
                             WHERE signal_value IN ($in)
                               AND (is_permanent = 1 OR expires_at = 0 OR expires_at > ?)
                             LIMIT 12");
        $st->execute($args);
        foreach ($st->fetchAll() as $row) {
            if (isset($pairs[$row['signal_type'] . "\0" . $row['signal_value']])) return $row;
        }
    } catch (Exception $e) {
        return null;
    }
    return null;
}

/** Record (or refresh) a ban for every signal in $signals. */
function veyrax_ban_add($pdo, array $signals, $reason, $isPermanent, $expiresAt, $userId = null, $username = null, $createdBy = null) {
    if (!$signals) return 0;
    $sql = "INSERT INTO ban_signals (signal_value, signal_type, user_id, username, reason, is_permanent, expires_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE reason = VALUES(reason), is_permanent = VALUES(is_permanent),
                                    expires_at = VALUES(expires_at), user_id = VALUES(user_id),
                                    username = VALUES(username)";
    $st = $pdo->prepare($sql);
    $n = 0;
    foreach ($signals as $s) {
        try {
            $st->execute([$s[1], $s[0], $userId, $username, mb_substr((string)$reason, 0, 255, 'UTF-8'), $isPermanent ? 1 : 0, (int)$expiresAt, $createdBy]);
            $n++;
        } catch (Exception $e) {}
    }
    return $n;
}

/** Drop every signal that points at this user (unban). */
function veyrax_ban_remove_for_user($pdo, $userId, $username = null) {
    try {
        if ($userId) $pdo->prepare("DELETE FROM ban_signals WHERE user_id = ?")->execute([(int)$userId]);
        if ($username) $pdo->prepare("DELETE FROM ban_signals WHERE username = ?")->execute([$username]);
    } catch (Exception $e) {}
}

function veyrax_ban_remove_signal($pdo, $type, $value) {
    try {
        $pdo->prepare("DELETE FROM ban_signals WHERE signal_type = ? AND signal_value = ?")->execute([$type, $value]);
    } catch (Exception $e) {}
}

function veyrax_ban_active($userRow) {
    if (!is_array($userRow)) return false;
    if ((int)($userRow['is_banned_until'] ?? 0) > time()) return true;
    if (array_key_exists('ip_banned', $userRow) && $userRow['ip_banned']) return true;
    return false;
}

/**
 * Renders the full-page "you cannot come in right now" screen.
 *
 * Used by two callers that used to ship two completely different, unstyled
 * documents: the site_lock branch at the top of index.php and the ban screen
 * in veyrax_require_not_banned(). Keeping one renderer means the two screens
 * can never drift apart again, and the admin-supplied reason shows up in both.
 *
 * Options: variant (lock|ban), title, reason, until (unix ts, 0 = open ended),
 *          ip, show_ip, status (HTTP code), extra (small footnote).
 */
function veyrax_lock_page(array $opts = []) {
    if (PHP_SAPI === 'cli') { fwrite(STDERR, "locked\n"); exit(1); }
    global $pdo, $font_text, $font_heading;

    $variant = (($opts['variant'] ?? 'lock') === 'ban') ? 'ban' : 'lock';
    $title   = trim((string)($opts['title'] ?? ''));
    $reason  = trim((string)($opts['reason'] ?? ''));
    $until   = (int)($opts['until'] ?? 0);
    $ip      = trim((string)($opts['ip'] ?? ''));
    $showIp  = !empty($opts['show_ip']) && $ip !== '';
    $status  = (int)($opts['status'] ?? ($variant === 'ban' ? 403 : 503));

    if ($title === '') {
        $title = $variant === 'ban' ? 'دسترسی مسدود شد' : 'دسترسی موقتاً محدود است';
    }
    if ($reason === '') {
        $reason = $variant === 'ban'
            ? 'دسترسی شما به دلیل نقض قوانین این گفتگو مسدود شده است.'
            : 'سایت برای به‌روزرسانی فنی موقتاً از دسترس خارج شده است. از شکیبایی شما سپاسگزاریم.';
    }

    $icon    = $variant === 'ban' ? 'shield.png' : 'lock.png';
    $accent  = $variant === 'ban' ? '#f87171' : '#fbbf24';
    $accentD = $variant === 'ban' ? '#ef4444' : '#f59e0b';
    $soft    = $variant === 'ban' ? 'rgba(239,68,68,.16)' : 'rgba(251,191,36,.16)';
    $edge    = $variant === 'ban' ? 'rgba(239,68,68,.34)' : 'rgba(251,191,36,.30)';

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        header('Retry-After: ' . ($until > 0 ? max(30, $until - time()) : 120));
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }

    $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    $fa = function ($n) {
        return str_replace(['0','1','2','3','4','5','6','7','8','9'], ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], (string)$n);
    };
    ?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?php echo $e($title); ?></title>
<?php if (function_exists('veyrax_icon_links')) veyrax_icon_links(); ?>
<?php
// Same faces the rest of the app uses. Without this the lock screen fell back
// to the system font, so it never matched whatever the admin had picked.
// The two base faces mirror index.php; veyrax_font_css() then overrides them
// when the admin selected a named family.
$lk_base = 'fonts/' . rawurlencode((string)$font_text) . '.ttf';
$lk_head = 'fonts/' . rawurlencode((string)$font_heading) . '.ttf';
echo '<style id="vFontFaces">';
echo "@font-face{font-family:'AppFont';src:url('" . $lk_base . "') format('truetype');font-weight:normal;font-display:swap}";
echo "@font-face{font-family:'AppBold';src:url('" . $lk_head . "') format('truetype');font-weight:bold;font-display:swap}";
echo "@font-face{font-family:'AppHeading';src:url('" . $lk_head . "') format('truetype');font-weight:bold;font-display:swap}";
if (function_exists('veyrax_font_css')) {
    try { echo (string)veyrax_font_css($pdo); } catch (Throwable $e) {}
}
echo '</style>';
?>
<style>
    :root {
        --lk-accent: <?php echo $accent; ?>;
        --lk-accent-deep: <?php echo $accentD; ?>;
        --lk-soft: <?php echo $soft; ?>;
        --lk-edge: <?php echo $edge; ?>;
    }
    * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
    html, body { height: 100%; }
    body {
        margin: 0; min-height: 100dvh;
        display: flex; align-items: center; justify-content: center;
        padding: clamp(16px, 4vw, 40px);
        background: #0a0f1c;
        color: #e8eefc;
        font-family: 'AppFont', system-ui, -apple-system, 'Segoe UI', sans-serif;
        line-height: 1.75;
        overflow-x: hidden;
        position: relative;
    }
    /* Aurora: two soft colour fields behind a dark veil — cheap, no images. */
    body::before, body::after {
        content: ''; position: fixed; border-radius: 50%;
        filter: blur(90px); pointer-events: none; z-index: 0;
    }
    body::before {
        width: 46vmax; height: 46vmax; top: -18vmax; inset-inline-end: -14vmax;
        background: radial-gradient(circle, var(--lk-soft), transparent 68%);
        opacity: .9;
    }
    body::after {
        width: 40vmax; height: 40vmax; bottom: -16vmax; inset-inline-start: -12vmax;
        background: radial-gradient(circle, rgba(99,102,241,.20), transparent 68%);
        opacity: .8;
    }

    .lk-card {
        position: relative; z-index: 1;
        width: 100%; max-width: 468px;
        padding: clamp(26px, 6vw, 40px) clamp(20px, 5vw, 34px) clamp(24px, 5vw, 32px);
        text-align: center;
        border-radius: 30px;
        border: 1px solid rgba(255,255,255,.09);
        background:
            radial-gradient(120% 70% at 50% -10%, var(--lk-soft), transparent 60%),
            linear-gradient(168deg, rgba(30,41,64,.92), rgba(14,20,35,.96));
        box-shadow: 0 40px 90px -30px rgba(0,0,0,.9), inset 0 1px 0 rgba(255,255,255,.06);
        animation: lkIn .5s cubic-bezier(.2,.9,.3,1.15) both;
    }
    @keyframes lkIn { from { opacity: 0; transform: translateY(16px) scale(.98); } to { opacity: 1; transform: none; } }

    /* Icon tile */
    .lk-icon {
        position: relative;
        width: clamp(78px, 22vw, 96px); height: clamp(78px, 22vw, 96px);
        margin: 0 auto clamp(16px, 4vw, 22px);
        display: grid; place-items: center;
        border-radius: 28px;
        background: linear-gradient(160deg, rgba(255,255,255,.10), rgba(255,255,255,.02));
        border: 1px solid var(--lk-edge);
        box-shadow: 0 18px 40px -18px var(--lk-accent-deep), inset 0 1px 0 rgba(255,255,255,.10);
    }
    .lk-icon::after {
        content: ''; position: absolute; inset: -6px;
        border-radius: 34px; border: 1px solid var(--lk-edge);
        opacity: .5; animation: lkPulse 3.2s ease-out infinite;
    }
    .lk-icon img {
        width: 56%; height: 56%; object-fit: contain;
        filter: drop-shadow(0 10px 18px rgba(0,0,0,.55));
        animation: lkFloat 4.2s ease-in-out infinite;
    }
    @keyframes lkFloat { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }
    @keyframes lkPulse {
        0%   { transform: scale(.96); opacity: .55; }
        70%  { transform: scale(1.10); opacity: 0; }
        100% { transform: scale(1.10); opacity: 0; }
    }

    .lk-title {
        margin: 0 0 10px; font-size: clamp(1.18rem, 4.4vw, 1.5rem); font-weight: 800;
        letter-spacing: -.2px; color: #fff;
    }
    .lk-sub {
        margin: 0 auto clamp(18px, 4vw, 24px); max-width: 34ch;
        font-size: clamp(.86rem, 3.3vw, .95rem); color: #9fb0cc;
    }
    /* The reason reads as a quoted note, not a wall of text. */
    .lk-reason {
        position: relative;
        margin: 0 0 clamp(18px, 4vw, 24px);
        padding: 14px 16px 14px 16px;
        text-align: start;
        border-radius: 16px;
        border: 1px solid rgba(255,255,255,.07);
        background: rgba(255,255,255,.035);
        font-size: clamp(.84rem, 3.2vw, .92rem);
        color: #c8d5ea;
        white-space: pre-wrap; word-break: break-word;
    }
    .lk-reason::before {
        content: ''; position: absolute; inset-inline-start: 0; top: 12px; bottom: 12px;
        width: 3px; border-radius: 3px;
        background: linear-gradient(180deg, var(--lk-accent), transparent);
    }
    .lk-reason b { color: #eaf2ff; }

    .lk-meta { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; margin-bottom: clamp(18px, 4vw, 24px); }
    .lk-chip {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 7px 13px; border-radius: 999px;
        font-size: 12px; font-weight: 600;
        background: rgba(255,255,255,.05);
        border: 1px solid rgba(255,255,255,.09);
        color: #b9c8e0;
    }
    .lk-chip b { color: var(--lk-accent); font-variant-numeric: tabular-nums; }
    .lk-chip.lk-chip-ip { direction: ltr; font-family: ui-monospace, 'Cascadia Mono', monospace; letter-spacing: .3px; }

    .lk-btn {
        width: 100%; padding: 14px 18px; border: 0; border-radius: 16px; cursor: pointer;
        font-family: inherit; font-size: clamp(.9rem, 3.4vw, .98rem); font-weight: 800; color: #0b1120;
        background: linear-gradient(135deg, var(--lk-accent), var(--lk-accent-deep));
        box-shadow: 0 16px 34px -14px var(--lk-accent-deep);
        transition: transform .16s ease, box-shadow .16s ease, filter .16s ease;
        display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    }
    .lk-btn:hover { transform: translateY(-2px); filter: brightness(1.06); }
    .lk-btn:active { transform: translateY(0); }
    .lk-btn[disabled] { opacity: .72; cursor: progress; }
    .lk-btn .lk-spin {
        width: 15px; height: 15px; border-radius: 50%;
        border: 2px solid rgba(11,17,32,.28); border-top-color: #0b1120;
        animation: lkSpin .7s linear infinite;
    }
    @keyframes lkSpin { to { transform: rotate(360deg); } }

    .lk-foot { margin: 14px 0 0; font-size: 11.5px; color: #6b7f9c; }
    .lk-foot b { color: #8ea4c4; font-weight: 700; }

    @media (max-width: 380px) {
        .lk-card { border-radius: 24px; }
        .lk-meta { gap: 6px; }
        .lk-chip { font-size: 11px; padding: 6px 10px; }
    }
    @media (prefers-reduced-motion: reduce) {
        .lk-card, .lk-icon img, .lk-icon::after { animation: none !important; }
    }
</style>
</head>
<body>
<main class="lk-card" role="alertdialog" aria-labelledby="lkTitle" aria-describedby="lkReason">
    <div class="lk-icon"><img src="assets/icons3d/<?php echo $e($icon); ?>" alt=""></div>
    <h1 class="lk-title" id="lkTitle"><?php echo $e($title); ?></h1>
    <p class="lk-sub"><?php echo $variant === 'ban'
        ? 'این محدودیت توسط مدیریت اعمال شده است.'
        : 'به‌زودی برمی‌گردیم؛ می‌توانید دوباره تلاش کنید.'; ?></p>

    <div class="lk-reason" id="lkReason"><?php echo nl2br($e($reason)); ?></div>

    <?php if ($until > 0 || $showIp): ?>
    <div class="lk-meta">
        <?php if ($until > 0): ?>
            <span class="lk-chip">⏳ زمان باقی‌مانده: <b id="lkTimer">—</b></span>
        <?php endif; ?>
        <?php if ($showIp): ?>
            <span class="lk-chip lk-chip-ip">IP: <?php echo $e($ip); ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <button class="lk-btn" id="lkBtn" onclick="lkRetry(this)">
        <span>بررسی وضعیت اتصال</span>
    </button>
    <p class="lk-foot">اگر فکر می‌کنید اشتباهی رخ داده، با <b>مدیریت</b> در تماس باشید.</p>
</main>
<script>
(function () {
    var until = <?php echo (int)$until; ?>;
    var timerEl = document.getElementById('lkTimer');

    function two(n) { return n < 10 ? '0' + n : '' + n; }
    function fa(s) {
        return String(s).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
    }
    function tick() {
        if (!timerEl || !until) return;
        var left = until - Math.floor(Date.now() / 1000);
        if (left <= 0) {
            timerEl.textContent = 'به پایان رسید';
            location.reload();
            return;
        }
        var d = Math.floor(left / 86400), h = Math.floor(left % 86400 / 3600);
        var m = Math.floor(left % 3600 / 60), s = left % 60;
        var txt = d > 0 ? d + ' روز و ' + two(h) + ':' + two(m)
                        : (h > 0 ? h + ':' + two(m) + ':' + two(s) : two(m) + ':' + two(s));
        timerEl.textContent = fa(txt);
    }
    tick();
    if (until) setInterval(tick, 1000);

    window.lkRetry = function (btn) {
        btn.disabled = true;
        var label = btn.querySelector('span');
        var old = label.textContent;
        label.textContent = 'در حال بررسی…';
        btn.insertAdjacentHTML('afterbegin', '<span class="lk-spin"></span>');
        setTimeout(function () { location.reload(); }, 420);
    };
})();
</script>
</body>
</html><?php
    exit;
}

function veyrax_require_not_banned(array $userRow) {
    if (!veyrax_ban_active($userRow)) return;
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "banned\n");
        exit(1);
    }
    $perm = ((int)$userRow['is_banned_until'] > time() + 31536000) || !empty($userRow['ip_banned']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (!empty($_SESSION['session_token'])) {
            try { global $pdo; $pdo->prepare("DELETE FROM user_sessions WHERE session_token = ?")->execute([$_SESSION['session_token']]); } catch (Exception $e) {}
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_unset();
        session_destroy();
    }
    if (isset($_COOKIE['device_token'])) {
        setcookie('device_token', '', time() - 3600, '/');
    }
    http_response_code(403);
    $rem = max(0, (int)$userRow['is_banned_until'] - time());

    // The admin types the reason when banning; it is stored on the signal row.
    // Fall back to a neutral sentence when an older ban has none.
    $banReason = trim((string)($userRow['ban_reason'] ?? ''));
    if ($banReason === '') {
        $banReason = $perm
            ? 'دسترسی شما به دلیل نقض قوانین به‌طور دائمی مسدود شده است.'
            : 'دسترسی شما موقتاً مسدود شده است. پس از پایان این مدت می‌توانید دوباره وارد شوید.';
    }

    veyrax_lock_page([
        'variant' => 'ban',
        'title'   => $perm ? 'دسترسی شما مسدود شده است' : 'دسترسی موقتاً محدود است',
        'reason'  => $banReason,
        'until'   => $perm ? 0 : (int)$userRow['is_banned_until'],
        'ip'      => (string)($userRow['client_ip'] ?? ''),
        'show_ip' => true,
        'status'  => 403,
    ]);
}

// Bump together with schema.php when the database layout changes.
if (!defined('VEYRAX_SCHEMA_VERSION')) define('VEYRAX_SCHEMA_VERSION', '2026.11.13.2');
define('VEYRAX_ASSET_VERSION', '1.8.7');

/**
 * Tables that get an unprefixed compatibility VIEW when a prefix is used.
 *
 * Every query in this codebase says `messages`, `users`, … and relies on the
 * view to reach the real `{prefix}messages` table. The views used to be created
 * only inside the migration, which runs once. If a view was missing (dropped by
 * hand, created before view support existed, or the migration was skipped
 * because `settings.schema_version` already matched) the whole feature died
 * with "Table 'db.messages' doesn't exist" — exactly what happened with the
 * admin "delete all messages" button.
 */
function veyrax_compat_view_tables() {
    return [
        'users', 'user_auth', 'banned_ips', 'ban_signals', 'admin_users', 'rooms',
        'room_invites', 'messages', 'user_sessions', 'system_settings', 'user_reports',
        'settings', 'global_alerts', 'update_notifications', 'update_notif_seen',
        'pinned_messages', 'message_reactions', 'channel_members', 'blocked_users',
    ];
}

/**
 * Make sure every unprefixed compatibility view exists. Cheap: the result is
 * stamped in data/compat_views.json and re-checked only when the prefix or the
 * schema version changes. A failed run never writes the stamp, so the next
 * request tries again.
 *
 * A real BASE TABLE with the same name is never touched — that is a legacy
 * install that never used a prefix, and replacing it with a view would destroy
 * the data.
 */
function veyrax_ensure_compat_views($pdo, $prefix = '') {
    $prefix = preg_replace('/[^A-Za-z0-9_]/', '', (string)$prefix);
    if ($prefix === '') return true;

    $sig = $prefix . '|' . VEYRAX_SCHEMA_VERSION . '|' . count(veyrax_compat_view_tables());
    $stampFile = __DIR__ . '/data/compat_views.json';
    if (is_file($stampFile)) {
        $raw = @file_get_contents($stampFile);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($j) && ($j['sig'] ?? '') === $sig) return true;
    }

    $wanted = veyrax_compat_view_tables();

    // One metadata query tells us which names exist and what they are.
    $kinds = [];
    try {
        $in = implode(',', array_fill(0, count($wanted), '?'));
        $st = $pdo->prepare("SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)");
        $st->execute($wanted);
        foreach ($st->fetchAll(PDO::FETCH_NUM) as $row) {
            $kinds[(string)$row[0]] = strtoupper((string)$row[1]) === 'VIEW' ? 'view' : 'table';
        }
    } catch (Exception $e) {
        error_log('[VIRAX views] metadata: ' . $e->getMessage());
        return false;
    }

    // Which of the prefixed base tables actually exist?
    $baseExists = [];
    try {
        $in2 = implode(',', array_fill(0, count($wanted), '?'));
        $st2 = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES
                              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in2)");
        $st2->execute(array_map(function ($n) use ($prefix) { return $prefix . $n; }, $wanted));
        foreach ($st2->fetchAll(PDO::FETCH_COLUMN) as $row) $baseExists[(string)$row] = true;
    } catch (Exception $e) {
        error_log('[VIRAX views] base tables: ' . $e->getMessage());
        return false;
    }

    $made = 0; $failed = 0;
    foreach ($wanted as $name) {
        $kind = $kinds[$name] ?? '';
        if ($kind === 'table') continue;                 // legacy install: keep it
        if (!isset($baseExists[$prefix . $name])) continue; // nothing to point at yet
        try {
            // Always rebuild a view that is missing; refresh one that exists only
            // when the schema changed (the stamp covers that case).
            $pdo->exec("CREATE OR REPLACE VIEW `$name` AS SELECT * FROM `" . $prefix . $name . "`");
            $made++;
        } catch (Exception $e) {
            $failed++;
            error_log('[VIRAX views] ' . $name . ': ' . $e->getMessage());
        }
    }

    if ($failed === 0) {
        @file_put_contents($stampFile, json_encode(['sig' => $sig, 'n' => $made, 't' => time()]), LOCK_EX);
        return true;
    }
    return false;
}

$db_port = (isset($db_port) && (int)$db_port > 0) ? (int)$db_port : 3306;

try {
    $pdo = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 10);
    // Note: the database default charset used to be ALTERed on every single
    // request. MySQL locks the schema for each ALTER DATABASE (~60ms even on
    // localhost, far worse under load), which was the biggest part of a slow
    // page load. The DSN already forces utf8mb4 on the connection and every
    // table is created with an explicit charset, so the check now runs only
    // during a migration (see the schema block below).

    // Fast path: the migration runs once (install or version bump) and is then
    // skipped with a single indexed SELECT + a zero-row column probe. Before
    // this, every request rebuilt tables and took ~1s even on localhost.
    $schemaCurrent = false;
    try {
        $schemaVer = $pdo->query("SELECT value FROM settings WHERE name = 'schema_version'")->fetchColumn();
        if ($schemaVer !== false && (string)$schemaVer === VEYRAX_SCHEMA_VERSION) {
            $pdo->query("SELECT theme_color, phone_auth_attempts, last_notif_id FROM users LIMIT 0");
            $schemaCurrent = true;
        }
    } catch (Exception $e) {
        $schemaCurrent = false;
    }

    if (!$schemaCurrent) {
        // One-off database default charset repair, kept out of the per-request
        // path because ALTER DATABASE takes a metadata lock.
        try {
            $csRow = $pdo->query("SHOW VARIABLES LIKE 'character_set_database'")->fetch(PDO::FETCH_NUM);
            if ($csRow && isset($csRow[1]) && $csRow[1] !== '' && strcasecmp((string)$csRow[1], 'utf8mb4') !== 0) {
                $pdo->exec("ALTER DATABASE `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
            }
        } catch (Exception $e) {}

        require_once __DIR__ . '/schema.php';
        $prefix = defined('VEYRAX_DB_PREFIX') ? VEYRAX_DB_PREFIX : '';
        $schemaError = null;
        try {
            veyrax_schema($pdo, $prefix);
        } catch (Exception $e) {
            $schemaError = $e;
            error_log('[VIRAX schema] ' . $e->getMessage());
        }
        try {
            veyrax_upgrade($pdo, $prefix);
        } catch (Exception $e) {
            error_log('[VIRAX upgrade] ' . $e->getMessage());
        }
        if ($schemaError) throw $schemaError;
        // Best-effort marker; a read-only settings table only costs a slower
        // first query on the next request, never a broken page.
        try {
            $pdo->query("SELECT theme_color, phone_auth_attempts FROM users LIMIT 0");
            $pdo->prepare("INSERT INTO settings (name, value) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
                ->execute([VEYRAX_SCHEMA_VERSION]);
        } catch (Exception $e) {}
    }

    // Self-healing compatibility views. Runs at most once per prefix/schema
    // version; without it a single dropped view takes a whole feature down.
    try {
        veyrax_ensure_compat_views($pdo, defined('VEYRAX_DB_PREFIX') ? VEYRAX_DB_PREFIX : '');
    } catch (Exception $e) {
        error_log('[VIRAX views] ' . $e->getMessage());
    }

    $client_ip = get_client_ip();
    $stmtBan = $pdo->prepare("SELECT id FROM banned_ips WHERE ip_address = ? LIMIT 1");
    $stmtBan->execute([$client_ip]);
    $ipBannedRow = $stmtBan->fetch();
    if ($ipBannedRow && (empty($_SESSION['username']) || (!isset($admin_username) || $_SESSION['username'] !== $admin_username))) {
        veyrax_require_not_banned(['is_banned_until' => time() + 400 * 24 * 3600, 'ip_banned' => true, 'client_ip' => $client_ip]);
    }

    // Multi-signal ban check. Sessions remember the last verdict for a minute so
    // this costs at most one indexed query per user per minute, not one per
    // poll — the long-poll channel alone would otherwise hammer the table.
    try {
        $banSignals = veyrax_ban_signals_for($client_ip);
        if ($banSignals) {
            $banSigKey = md5(json_encode($banSignals));
            $banCheckedAt = (int)($_SESSION['ban_checked_at'] ?? 0);
            $banCachedKey = (string)($_SESSION['ban_sig_key'] ?? '');
            $banHit = null;
            if ($banSigKey !== $banCachedKey || (time() - $banCheckedAt) > 60) {
                $banHit = veyrax_ban_lookup($pdo, $banSignals);
                $_SESSION['ban_sig_key'] = $banSigKey;
                $_SESSION['ban_checked_at'] = time();
                $_SESSION['ban_hit'] = $banHit ? 1 : 0;
                $_SESSION['ban_hit_perm'] = $banHit ? (int)$banHit['is_permanent'] : 0;
                $_SESSION['ban_hit_until'] = $banHit ? (int)$banHit['expires_at'] : 0;
            } elseif (!empty($_SESSION['ban_hit'])) {
                $banHit = [
                    'is_permanent' => (int)($_SESSION['ban_hit_perm'] ?? 0),
                    'expires_at'   => (int)($_SESSION['ban_hit_until'] ?? 0),
                ];
            }
            if ($banHit) {
                $banSessionName = (string)($_SESSION['username'] ?? '');
                $banIsAdmin = $banSessionName !== ''
                    && ((isset($admin_username) && $banSessionName === $admin_username) || is_admin_user($banSessionName, $pdo));
                if (!$banIsAdmin) {
                    $banPerm = ((int)$banHit['is_permanent'] === 1);
                    veyrax_require_not_banned([
                        'is_banned_until' => $banPerm ? time() + 400 * 24 * 3600 : max(time() + 1, (int)$banHit['expires_at']),
                        'ip_banned'       => $banPerm,
                        'client_ip'       => $client_ip,
                        'ban_reason'      => (string)($banHit['reason'] ?? ''),
                    ]);
                }
            }
        }
    } catch (Exception $e) {
        error_log('[VIRAX ban] ' . $e->getMessage());
    }
} catch (PDOException $e) {
    error_log("DB Error: " . $e->getMessage());
    if (!$installer_flag || true) {
        $friendly = 'خطا در سیستم پایگاه داده. لطفا بعدا تلاش کنید.';
        if (strpos($e->getMessage(), '1049') !== false || stripos($e->getMessage(), 'Unknown database') !== false) $friendly = 'پایگاه داده یافت نشد.';
        elseif (strpos($e->getMessage(), '1045') !== false || stripos($e->getMessage(), 'Access denied') !== false) $friendly = 'اطلاعات اتصال دیتابیس اشتباه است.';
        elseif (strpos($e->getMessage(), '2002') !== false) $friendly = 'اتصال به سرور دیتابیس برقرار نشد.';
        veyrax_fatal_page(
            '🗄',
            $friendly,
            'اتصال به پایگاه داده برقرار نشد.<br><b>داده‌های شما دست‌نخورده‌اند.</b>',
            'اگر سورس جدید آپلود کرده‌اید، از وجود <code>data/config.php</code> و دیتابیس قبلی مطمئن شوید.<br>اگر تازه نصب کرده‌اید، <a style="color:#818cf8;text-decoration:none" href="install.php">نصب‌کننده</a> را اجرا کنید.',
            '<a class="btn" href="' . htmlspecialchars((isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : 'index.php'), ENT_QUOTES, 'UTF-8') . '">تلاش مجدد</a>'
        );
    }
    exit;
}

// ---- Authentication-password storage -------------------------------------
// auth_password normally lives on the users table. If the hosting account is
// not allowed to ALTER that table, it is read from / written to user_auth
// instead, so the feature never throws "Unknown column" again.

function veyrax_user_has_auth_column($pdo) {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $pdo->query("SELECT auth_password FROM users LIMIT 0");
        $has = true;
    } catch (Exception $e) {
        $has = false;
    }
    return $has;
}

function veyrax_auth_table_ready($pdo) {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $pdo->query("SELECT auth_password FROM user_auth LIMIT 0");
        $ready = true;
    } catch (Exception $e) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_auth (
                user_id INT NOT NULL PRIMARY KEY,
                auth_password VARCHAR(255) DEFAULT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            $ready = true;
        } catch (Exception $e2) {
            $ready = false;
        }
    }
    return $ready;
}

function veyrax_get_auth_hash($pdo, $userId) {
    try {
        if (veyrax_user_has_auth_column($pdo)) {
            $stmt = $pdo->prepare("SELECT auth_password FROM users WHERE id = ?");
            $stmt->execute([(int)$userId]);
            return (string)($stmt->fetchColumn() ?: '');
        }
        if (veyrax_auth_table_ready($pdo)) {
            $stmt = $pdo->prepare("SELECT auth_password FROM user_auth WHERE user_id = ?");
            $stmt->execute([(int)$userId]);
            return (string)($stmt->fetchColumn() ?: '');
        }
    } catch (Exception $e) {}
    return '';
}

function veyrax_set_auth_hash($pdo, $userId, $hash) {
    try {
        if (veyrax_user_has_auth_column($pdo)) {
            $pdo->prepare("UPDATE users SET auth_password = ? WHERE id = ?")->execute([$hash, (int)$userId]);
            return true;
        }
        if (veyrax_auth_table_ready($pdo)) {
            $pdo->prepare("INSERT INTO user_auth (user_id, auth_password) VALUES (?, ?) ON DUPLICATE KEY UPDATE auth_password = VALUES(auth_password)")
                ->execute([(int)$userId, $hash]);
            return true;
        }
    } catch (Exception $e) { error_log('[VIRAX auth] ' . $e->getMessage()); }
    return false;
}

// ---- Demo mode ------------------------------------------------------------
// A visitor logs in with demo/demo and gets a throw-away account. Everything
// that account produces lives under a fixed demo room and is wiped when the
// session goes idle, so a demo never touches real data and two visitors never
// collide (each login gets its own user row).
if (!defined('VEYRAX_DEMO_ROOM'))     define('VEYRAX_DEMO_ROOM', 'اتاق دمو');
if (!defined('VEYRAX_DEMO_LOGIN'))    define('VEYRAX_DEMO_LOGIN', 'demo');
if (!defined('VEYRAX_DEMO_PASSWORD')) define('VEYRAX_DEMO_PASSWORD', 'demo');
if (!defined('VEYRAX_DEMO_TTL'))      define('VEYRAX_DEMO_TTL', 2700);   // 45 min idle
if (!defined('VEYRAX_DEMO_MAX_MSGS')) define('VEYRAX_DEMO_MAX_MSGS', 25);
if (!defined('VEYRAX_DEMO_GAP'))      define('VEYRAX_DEMO_GAP', 1);      // seconds between messages

function veyrax_demo_enabled($pdo) {
    static $cached = null;
    if ($cached !== null) return $cached;
    try {
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = 'demo_mode'");
        $stmt->execute();
        $cached = ((string)$stmt->fetchColumn() === '1');
    } catch (Exception $e) {
        $cached = false;
    }
    return $cached;
}

/**
 * Read a boolean switch out of `settings` once per request.
 *
 * The access locks below are consulted on every login path, so a tiny static
 * cache keeps that to a single query. Each request is isolated, which means a
 * toggle saved in the admin panel is visible on the very next page load.
 */
function veyrax_flag($pdo, $name, $default = false) {
    static $cache = [];
    $name = (string)$name;
    if (array_key_exists($name, $cache)) return $cache[$name];
    try {
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = ? LIMIT 1");
        $stmt->execute([$name]);
        $raw = $stmt->fetchColumn();
        $cache[$name] = ($raw === false) ? (bool)$default : ((string)$raw === '1');
    } catch (Exception $e) {
        $cache[$name] = (bool)$default;
    }
    return $cache[$name];
}

/** New accounts cannot be created while this is on. Existing users still log in. */
function veyrax_registration_locked($pdo) {
    return veyrax_flag($pdo, 'lock_register');
}

/** Normal user login is closed while this is on; the main admin is exempt. */
function veyrax_login_locked($pdo) {
    return veyrax_flag($pdo, 'lock_login');
}

/**
 * One place that decides whether this username may start a login at all.
 *
 * Returns null when the login may continue, or a JSON-ready error payload.
 * The order matters: demo mode is the strictest gate, then the login lock.
 * Admin identities are always allowed through the login lock so the site can
 * never lock its owner out — they still have to pass the normal password check.
 */
function veyrax_login_gate($pdo, $username, $isAdmin) {
    $u = (string)$username;
    if (veyrax_demo_enabled($pdo) && mb_strtolower($u, 'UTF-8') !== VEYRAX_DEMO_LOGIN) {
        return [
            'status'  => 'demo_only',
            'message' => 'حالت نمایشی فعال است — ورود فقط با نام کاربری demo و رمز demo ممکن است',
        ];
    }
    if (veyrax_login_locked($pdo) && !$isAdmin) {
        return [
            'status'  => 'login_locked',
            'message' => 'ورود کاربران موقتاً بسته شده است. برای ورود مدیر از پنل مدیریت استفاده کنید',
        ];
    }
    return null;
}

/**
 * Anonymous flows that are not a login (signup probe, SMS reset, phone OTP).
 *
 * They share the same two switches but need their own wording, so they get a
 * separate helper instead of pretending to be a login attempt.
 */
function veyrax_closed_notice($pdo, $context = 'flow') {
    if (veyrax_demo_enabled($pdo)) {
        $msg = ($context === 'reset')
            ? 'در حالت نمایشی امکان بازیابی یا تغییر حساب وجود ندارد'
            : 'حالت نمایشی فعال است — این بخش در حالت دمو در دسترس نیست';
        return ['status' => 'demo_only', 'message' => $msg];
    }
    if (veyrax_login_locked($pdo)) {
        return ['status' => 'login_locked', 'message' => 'ورود کاربران موقتاً بسته شده است'];
    }
    return null;
}

function veyrax_is_demo_user($pdo, $userId) {
    if (isset($_SESSION['is_demo'])) return !empty($_SESSION['is_demo']);
    static $cache = [];
    $userId = (int)$userId;
    if ($userId <= 0) return false;
    if (array_key_exists($userId, $cache)) return $cache[$userId];
    $flag = false;
    try {
        $stmt = $pdo->prepare("SELECT is_demo FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $flag = ((int)$stmt->fetchColumn() === 1);
    } catch (Exception $e) {}
    $cache[$userId] = $flag;
    return $flag;
}

/** Remove demo accounts (and everything they produced). $onlyId = one user. */
function veyrax_demo_purge($pdo, $onlyId = 0, $force = false) {
    try {
        if ((int)$onlyId > 0) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND is_demo = 1");
            $stmt->execute([(int)$onlyId]);
        } elseif ($force) {
            // Admin pressed "purge now" (or switched the demo off): every
            // throw-away account goes, no matter how recently it was used.
            $stmt = $pdo->prepare("SELECT id FROM users WHERE is_demo = 1");
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE is_demo = 1
                                   AND (last_activity IS NULL OR last_activity < DATE_SUB(NOW(), INTERVAL ? SECOND))");
            $stmt->execute([VEYRAX_DEMO_TTL]);
        }
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) return 0;

        $in = implode(',', array_fill(0, count($ids), '?'));

        // Collect the rows first: `messages` is a compatibility VIEW on some
        // installs, and deleting through a view with a self-referencing
        // subquery is not portable.
        $msgIds = [];
        $files  = [];
        try {
            $q = $pdo->prepare("SELECT id, file_path FROM messages WHERE sender_id IN ($in)");
            $q->execute($ids);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $msgIds[] = (int)$row['id'];
                if (!empty($row['file_path'])) $files[] = (string)$row['file_path'];
            }
        } catch (Exception $e) {}

        $upBase = realpath(__DIR__ . '/uploads');
        foreach ($files as $p) {
            $full = realpath(__DIR__ . '/' . ltrim($p, '/\\'));
            if ($full && $upBase && strpos($full, $upBase . DIRECTORY_SEPARATOR) === 0 && is_file($full)) {
                @unlink($full);
            }
        }

        if ($msgIds) {
            $min = implode(',', array_fill(0, count($msgIds), '?'));
            try { $pdo->prepare("DELETE FROM message_reactions WHERE message_id IN ($min)")->execute($msgIds); } catch (Exception $e) {}
            try { $pdo->prepare("DELETE FROM pinned_messages WHERE msg_id IN ($min)")->execute($msgIds); } catch (Exception $e) {}
            try { $pdo->prepare("DELETE FROM messages WHERE id IN ($min)")->execute($msgIds); } catch (Exception $e) {}
        }
        try { $pdo->prepare("DELETE FROM message_reactions WHERE user_id IN ($in)")->execute($ids); } catch (Exception $e) {}
        try { $pdo->prepare("DELETE FROM user_sessions WHERE user_id IN ($in)")->execute($ids); } catch (Exception $e) {}
        try { $pdo->prepare("DELETE FROM users WHERE id IN ($in) AND is_demo = 1")->execute($ids); } catch (Exception $e) {}

        return count($ids);
    } catch (Exception $e) {
        error_log('[VIRAX demo purge] ' . $e->getMessage());
        return 0;
    }
}

/**
 * Background sweep, at most once every five minutes.
 *
 * A demo that nobody logs back into would otherwise leave its rows behind, so
 * the clean-up has to run on ordinary traffic too — but not on every request.
 */
function veyrax_demo_sweep($pdo) {
    $stampFile = __DIR__ . '/data/demo_sweep.json';
    $now = time();
    $last = 0;
    if (is_file($stampFile)) {
        $raw = @file_get_contents($stampFile);
        $j = json_decode((string)$raw, true);
        if (is_array($j) && isset($j['t'])) $last = (int)$j['t'];
    }
    if ($now - $last < 300) return 0;
    @file_put_contents($stampFile, json_encode(['t' => $now]), LOCK_EX);
    return veyrax_demo_purge($pdo);
}

function veyrax_demo_active_count($pdo) {
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_demo = 1")->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/** Create the demo room if it is missing. Idempotent, safe on every login. */
function veyrax_demo_ensure_room($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM rooms WHERE name = ? LIMIT 1");
        $stmt->execute([VEYRAX_DEMO_ROOM]);
        if ($stmt->fetchColumn()) return true;
        $pdo->prepare("INSERT INTO rooms (name, type, room_kind, created_by, invite_code)
                       VALUES (?, 'public', 'room', NULL, NULL)")
            ->execute([VEYRAX_DEMO_ROOM]);
        return true;
    } catch (Exception $e) {
        error_log('[VIRAX demo room] ' . $e->getMessage());
        return false;
    }
}

/**
 * The room a single demo visitor actually chats in.
 *
 * The visible room is shared (`اتاق دمو` in the sidebar), but the messages are
 * scoped per visitor so two strangers exploring the demo at the same time can
 * never read or delete each other's test messages.
 */
function veyrax_demo_room_for($userId) {
    return VEYRAX_DEMO_ROOM . ' #' . (int)$userId;
}

/** True when a room name belongs to the demo sandbox. */
function veyrax_is_demo_room($roomName) {
    $roomName = (string)$roomName;
    return $roomName === VEYRAX_DEMO_ROOM || strpos($roomName, VEYRAX_DEMO_ROOM . ' #') === 0;
}

/** Create the throw-away account for one demo visitor. */
function veyrax_demo_create_user($pdo, $ip, $ua, $fp = '', $fpc = '') {
    global $stickers;
    veyrax_demo_ensure_room($pdo);
    $name = '';
    for ($i = 0; $i < 6; $i++) {
        $candidate = 'demo_' . strtolower(bin2hex(random_bytes(3)));
        try {
            $chk = $pdo->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
            $chk->execute([$candidate]);
            if (!$chk->fetchColumn()) { $name = $candidate; break; }
        } catch (Exception $e) {}
    }
    if ($name === '') $name = 'demo_' . substr((string)microtime(true), -6);

    $sticker = is_array($stickers) && $stickers ? $stickers[array_rand($stickers)] : '🙂';
    $deviceToken = bin2hex(random_bytes(32));

    $stmt = $pdo->prepare("INSERT INTO users
        (username, sticker, user_agent, ip_address, is_online, device_token, security_code, fp, fp_coarse, is_demo, welcome_seen, notifications_enabled)
        VALUES (?, ?, ?, ?, 1, ?, '', ?, ?, 1, 1, 1)");
    $stmt->execute([
        $name, $sticker, $ua, $ip, $deviceToken,
        ($fp !== '' ? $fp : null), ($fpc !== '' ? $fpc : null),
    ]);

    $newId = (int)$pdo->lastInsertId();

    // A fresh visitor must not be notified about everything that was ever sent
    // in the public room before they arrived. The cursor starts at the newest
    // message id, so only messages sent from now on could ever notify them —
    // and the poll handler skips demo accounts entirely anyway.
    try {
        $maxId = (int)$pdo->query("SELECT MAX(id) FROM messages")->fetchColumn();
        $pdo->prepare("UPDATE users SET last_notif_id = ? WHERE id = ?")->execute([$maxId, $newId]);
    } catch (Exception $e) {}

    return [
        'id'       => $newId,
        'username' => $name,
        'sticker'  => $sticker,
        'device_token' => $deviceToken,
    ];
}


function perform_system_reset($pdo) {
    global $admin_username;

    $files = glob('uploads/*');
    foreach ($files as $file) {
        if (is_file($file) && basename($file) !== 'index.php' && basename($file) !== '.htaccess') {
            @unlink($file);
        }
    }

    $pdo->exec("DELETE FROM rooms WHERE name != 'گفتگوی عمومی'");
    $pdo->exec("DELETE FROM messages");
    $pdo->exec("DELETE FROM room_invites");
    $pdo->exec("DELETE FROM banned_ips");
    $pdo->exec("DELETE FROM user_reports");
    $pdo->exec("DELETE FROM global_alerts");
    $pdo->exec("DELETE FROM pinned_messages");
    $pdo->exec("DELETE FROM channel_members");

    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$admin_username]);
    $adminId = $stmt->fetchColumn();

    if ($adminId) {
        $pdo->prepare("UPDATE rooms SET created_by = ?")->execute([$adminId]);
        $pdo->prepare("DELETE FROM users WHERE id != ?")->execute([$adminId]);
        $pdo->prepare("UPDATE users SET last_notif_id = 0, is_banned_until = 0, muted_until = 0, typing_time = 0, device_token = NULL WHERE id = ?")->execute([$adminId]);
    }
}

function clean_old_data($pdo, $lifetime) {
    $limit = date('Y-m-d H:i:s', time() - $lifetime);

    $stmt = $pdo->prepare("SELECT file_path FROM messages WHERE created_at < ? AND file_path IS NOT NULL");
    $stmt->execute([$limit]);
    while ($row = $stmt->fetch()) {
        if (file_exists($row['file_path'])) @unlink($row['file_path']);
    }

    $pdo->prepare("DELETE FROM messages WHERE created_at < ?")->execute([$limit]);
    try { $pdo->prepare("DELETE FROM pinned_messages WHERE pinned_at < ?")->execute([$limit]); } catch (Exception $e) {}
    $pdo->prepare("DELETE FROM room_invites WHERE created_at < DATE_SUB(NOW(), INTERVAL 3 DAY)")->execute();
    $pdo->prepare("UPDATE users SET is_online = 0 WHERE last_activity < DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->execute();
}

try {
    $stmtChk = $pdo->prepare("SELECT value FROM settings WHERE name = 'auto_reset_enabled'");
    $stmtChk->execute();
    if ($stmtChk->fetchColumn() === '1') {
        $stmtTgt = $pdo->prepare("SELECT value FROM settings WHERE name = 'auto_reset_target'");
        $stmtTgt->execute();
        $target = (int)$stmtTgt->fetchColumn();

        if (time() >= $target && $target > 0) {
            perform_system_reset($pdo);

            $stmtRec = $pdo->prepare("SELECT value FROM settings WHERE name = 'auto_reset_recurring'");
            $stmtRec->execute();
            $isRecurring = $stmtRec->fetchColumn() === '1';

            if ($isRecurring) {
                $newTarget = time() + (24 * 3600);
                $pdo->prepare("UPDATE settings SET value = ? WHERE name = 'auto_reset_target'")->execute([$newTarget]);
            } else {
                $pdo->prepare("UPDATE settings SET value = '0' WHERE name = 'auto_reset_enabled'")->execute();
                $pdo->prepare("UPDATE settings SET value = '0' WHERE name = 'auto_reset_target'")->execute();
            }
        }
    }
} catch (Exception $e) {}

if (!is_dir('uploads')) {
    mkdir('uploads', 0755, true);
    file_put_contents('uploads/index.php', '<?php header("HTTP/1.0 403 Forbidden");');
    file_put_contents('uploads/.htaccess', 'Deny from all');
}

// Housekeeping used to fire on 1 of every 50 requests, so a random 2 % of
// message sends paid for a full DELETE sweep (visible as an occasional 1–2 s
// stall). It now runs roughly once every 500 requests and never inside the
// first seconds of a request that is still cheap to serve.
if (random_int(1, 500) === 1) {
    @set_time_limit(20);
    clean_old_data($pdo, $message_lifetime);
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
if (empty($_SESSION['bw_nonce'])) $_SESSION['bw_nonce'] = bin2hex(random_bytes(16));
ws_load_runtime_settings($pdo);

// ─────────────────────────────────────────────────────────────────────────────
// Settings helper (cached per request, tiny table, one query on first use).
// ─────────────────────────────────────────────────────────────────────────────
function veyrax_settings_all($pdo, $refresh = false) {
    static $cache = null;
    if ($refresh) $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    try {
        $rows = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (is_array($rows)) $cache = $rows;
    } catch (Exception $e) {}
    return $cache;
}

function veyrax_setting($pdo, $key, $default = '') {
    $all = veyrax_settings_all($pdo);
    return array_key_exists($key, $all) && $all[$key] !== null ? (string)$all[$key] : $default;
}

// ─────────────────────────────────────────────────────────────────────────────
// Branding — name / tagline / logo are editable from the admin panel and are
// applied here so *every* page (chat, login, admin, page titles) picks them up
// without touching config.php on the server.
// ─────────────────────────────────────────────────────────────────────────────
$brand_name_db = trim(veyrax_setting($pdo, 'brand_name', ''));
if ($brand_name_db !== '') $site_brand_fa = $brand_name_db;

$brand_tagline = trim(veyrax_setting($pdo, 'brand_tagline', ''));
if ($brand_tagline === '') $brand_tagline = 'چت امن و بلادرنگ';

// ── Social tiles (admin panel → شبکه‌های اجتماعی) ──────────────────────────
// Master switch + per-network visibility + links. The file-level defaults
// above stay untouched so a fresh install shows the tiles immediately; the
// panel writes these settings to the DB.
$social_enabled      = veyrax_setting($pdo, 'social_enabled', '1') === '1';
$social_github_on    = veyrax_setting($pdo, 'social_github_on', '1') === '1';
$social_website_on   = veyrax_setting($pdo, 'social_website_on', '1') === '1';
$social_telegram_on  = veyrax_setting($pdo, 'social_telegram_on', '1') === '1';
$social_github_link  = trim(veyrax_setting($pdo, 'social_github_link', $social_github_link));
$social_website_link = trim(veyrax_setting($pdo, 'social_website_link', $social_website_link));
$social_telegram_link = trim(veyrax_setting($pdo, 'social_telegram_link', $social_telegram_link));

/**
 * 3D brand glyphs for the header tiles. Pure inline SVG: no extra requests,
 * and the tile chrome (gradient, gloss, shadow) comes from CSS so a theme
 * change repaints them for free.
 */
function veyrax_social_svg($key) {
    switch ($key) {
        case 'github':
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></svg>';
        case 'telegram':
            return '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"/></svg>';
        default:
            return '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9s1.3-6.4 3.8-9z"/></svg>';
    }
}

/** Header tiles: only rendered when the panel switch is on. A network with no
 *  link yet still shows its tile (dimmed, not clickable) so the row never looks
 *  broken on a fresh install. */
function veyrax_social_icons_html() {
    global $social_enabled, $social_github_on, $social_website_on, $social_telegram_on,
           $social_github_link, $social_website_link, $social_telegram_link;
    if (empty($social_enabled)) return '';
    $networks = [];
    if ($social_github_on)   $networks[] = ['key' => 'github',   'label' => 'GitHub',   'link' => $social_github_link];
    if ($social_website_on)  $networks[] = ['key' => 'website',  'label' => 'وب‌سایت',  'link' => $social_website_link];
    if ($social_telegram_on) $networks[] = ['key' => 'telegram', 'label' => 'تلگرام',   'link' => $social_telegram_link];
    $html = '';
    foreach ($networks as $n) {
        $cls = 'vx-social vx-social-' . $n['key'];
        $svg = veyrax_social_svg($n['key']);
        if ($n['link'] !== '') {
            $html .= '<a href="' . htmlspecialchars($n['link'], ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer" class="' . $cls . '" title="' . $n['label'] . '" aria-label="' . $n['label'] . '">' . $svg . '</a>';
        } else {
            $html .= '<span class="' . $cls . ' is-off" title="' . $n['label'] . ' — لینک هنوز در پنل مدیریت تنظیم نشده" aria-label="' . $n['label'] . '">' . $svg . '</span>';
        }
    }
    return $html;
}

// Footer version chip. Admin-editable (branding card); when empty the build
// constant keeps it honest, so an empty field never shows a blank version.
$site_version_label = trim(strip_tags(veyrax_setting($pdo, 'site_version_label', '')));
if ($site_version_label === '') $site_version_label = VEYRAX_ASSET_VERSION;
if (mb_strlen($site_version_label, 'UTF-8') > 20) $site_version_label = mb_substr($site_version_label, 0, 20);

// ── Message-text typography (admin only) ────────────────────────────────────
// Deliberately separate from the UI font: the chat body can use its own face
// and weight without changing buttons, menus or the composer.
$msg_font_family = strtolower(trim(veyrax_setting($pdo, 'msg_font_family', 'default')));
$ui_font_family  = strtolower(trim(veyrax_setting($pdo, 'ui_font_family', 'default')));
$msg_font_weight = (int)veyrax_setting($pdo, 'msg_font_weight', '400');
if (!in_array($msg_font_weight, [300, 400, 500, 700, 800, 900], true)) $msg_font_weight = 400;

if ($msg_font_family === '' || $msg_font_family === 'default' || $msg_font_family === $ui_font_family) {
    // Reuse the faces already emitted for the UI — no extra download.
    $msg_font_stack = "'AppFont', inherit";
} else {
    $msg_font_stack = "'AppMsg', 'AppFont', inherit";
}

$brand_logo = trim(veyrax_setting($pdo, 'brand_logo', ''));
$brand_logo_fit = 'contain';
if ($brand_logo !== '') {
    $brand_logo_abs = __DIR__ . '/' . ltrim(str_replace('\\', '/', $brand_logo), '/');
    if (!is_file($brand_logo_abs)) {
        $brand_logo = '';
    } else {
        // Square-ish logos fill the tile; anything else is letterboxed so the
        // uploaded artwork is never cropped. The admin does not have to care
        // about the aspect ratio — it is detected here.
        $sz = @getimagesize($brand_logo_abs);
        if ($sz && $sz[0] > 0 && $sz[1] > 0) {
            $ratio = $sz[0] / $sz[1];
            $brand_logo_fit = ($ratio >= 0.8 && $ratio <= 1.25) ? 'cover' : 'contain';
        }
        // The file name only changes when a new logo is uploaded; pointing the
        // <img> at the same path after re-using an icon left browsers showing
        // the previous artwork from cache. Version it like every other asset.
        $brand_logo .= '?v=' . (defined('VEYRAX_ASSET_VERSION') ? VEYRAX_ASSET_VERSION : '1');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Typography — the admin picks a family + base weight and every page swaps the
// AppFont / AppBold / AppHeading faces for the selected family, so all the
// existing CSS keeps working untouched.
// ─────────────────────────────────────────────────────────────────────────────
function veyrax_font_map($family) {
    $maps = [
        'dana' => [
            100 => 'dana-thin', 200 => 'dana-extralight', 300 => 'dana-light',
            400 => 'dana-regular', 500 => 'dana-medium', 700 => 'dana-bold',
            800 => 'dana-extrabold', 900 => 'dana-black',
        ],
        'kalameh' => [
            100 => 'kalameh-thin', 300 => 'kalameh-light', 400 => 'kalameh-regular',
            500 => 'kalameh-medium', 700 => 'kalameh-bold', 800 => 'kalameh-extrabold',
            900 => 'kalameh-black',
        ],
    ];
    return $maps[$family] ?? [];
}

function veyrax_font_css($pdo) {
    $family = strtolower(trim(veyrax_setting($pdo, 'ui_font_family', 'default')));
    $weights = veyrax_font_map($family);
    if (!$weights) return '';

    $weight = (int)veyrax_setting($pdo, 'ui_font_weight', '400');
    if (!isset($weights[$weight])) $weight = 400;

    $base = 'assets/fonts/' . $family . '/';
    $css = '';
    foreach ($weights as $w => $file) {
        $css .= "@font-face{font-family:'AppFont';src:url('{$base}{$file}.ttf') format('truetype');font-weight:{$w};font-style:normal;font-display:swap}";
    }
    $boldFile = $weights[700] ?? $weights[400];
    $headFile = $weights[800] ?? $boldFile;
    $css .= "@font-face{font-family:'AppBold';src:url('{$base}{$boldFile}.ttf') format('truetype');font-weight:bold;font-style:normal;font-display:swap}";
    $css .= "@font-face{font-family:'AppHeading';src:url('{$base}{$headFile}.ttf') format('truetype');font-weight:bold;font-style:normal;font-display:swap}";

    // A different face for the message body needs its own family name, or the
    // browser would reuse the UI faces and the setting would appear to do
    // nothing. Only emitted when it actually differs, to avoid double fetches.
    $msgFam = strtolower(trim(veyrax_setting($pdo, 'msg_font_family', 'default')));
    if ($msgFam !== '' && $msgFam !== 'default' && $msgFam !== $family) {
        $msgWeights = veyrax_font_map($msgFam);
        if ($msgWeights) {
            $mbase = 'assets/fonts/' . $msgFam . '/';
            foreach ($msgWeights as $w => $file) {
                $css .= "@font-face{font-family:'AppMsg';src:url('{$mbase}{$file}.ttf') format('truetype');font-weight:{$w};font-style:normal;font-display:swap}";
            }
        }
    }

    $css .= "body{font-weight:{$weight}}";
    return $css;
}

function veyrax_fs_steps() {
    return [0.9, 1.0, 1.1, 1.2, 1.3];
}

function veyrax_fs_clamp($v) {
    $v = is_numeric($v) ? (float)$v : 1.0;
    $best = 1.0;
    $bestDiff = 99.0;
    foreach (veyrax_fs_steps() as $step) {
        $d = abs($step - $v);
        if ($d < $bestDiff) { $bestDiff = $d; $best = $step; }
    }
    return $best;
}

function veyrax_icon_links() {
    global $favicon_base;
    $b = rtrim((string)$favicon_base, '/');
    $v = defined('VEYRAX_ASSET_VERSION') ? VEYRAX_ASSET_VERSION : '1';
    // .ico first: legacy browsers and OS shortcuts still ask for /favicon.ico,
    // and "sizes=any" lets them pick the 48px frame out of the multi-size file.
    echo '<link rel="icon" href="' . $b . '/favicon.ico?v=' . $v . '" sizes="any">' . "\n    ";
    echo '<link rel="icon" type="image/png" sizes="32x32" href="' . $b . '/favicon-32.png?v=' . $v . '">' . "\n    ";
    echo '<link rel="icon" type="image/png" sizes="16x16" href="' . $b . '/favicon-16.png?v=' . $v . '">' . "\n    ";
    echo '<link rel="icon" type="image/png" sizes="96x96" href="' . $b . '/favicon-96.png?v=' . $v . '">' . "\n    ";
    echo '<link rel="icon" type="image/png" sizes="192x192" href="' . $b . '/favicon-192.png?v=' . $v . '">' . "\n    ";
    echo '<link rel="apple-touch-icon" sizes="180x180" href="' . $b . '/apple-touch-icon.png?v=' . $v . '">' . "\n    ";
    echo '<link rel="manifest" href="manifest.php?v=' . $v . '">';
}

/**
 * The password-eye sprite, printed wherever a password field can appear.
 *
 * The login screen and the in-app modals are different documents, so the
 * symbols cannot live in only one of them — an unresolved <use> renders as an
 * empty box, which is exactly how the old emoji got replaced by nothing.
 */
function veyrax_eye_sprite() {
    static $printed = false;
    if ($printed) return;
    $printed = true;
    echo <<<'SVG'
<svg width="0" height="0" style="position:absolute;overflow:hidden" aria-hidden="true" focusable="false">
    <defs>
        <linearGradient id="v3EyeWhite" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#FFFFFF"/>
            <stop offset="0.55" stop-color="#EEF2FF"/>
            <stop offset="1" stop-color="#C7D2FE"/>
        </linearGradient>
        <linearGradient id="v3EyeIris" x1="0" y1="0" x2="0.4" y2="1">
            <stop offset="0" stop-color="#93A4FF"/>
            <stop offset="0.5" stop-color="#6366F1"/>
            <stop offset="1" stop-color="#3730A3"/>
        </linearGradient>
        <linearGradient id="v3EyeRim" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#FFFFFF" stop-opacity="0.95"/>
            <stop offset="1" stop-color="#A5B4FC" stop-opacity="0.85"/>
        </linearGradient>
    </defs>
    <symbol id="v3i-eye" viewBox="0 0 32 32">
        <ellipse cx="16" cy="28.4" rx="8.6" ry="1.8" fill="#1B1630" opacity="0.20"/>
        <path d="M2.7 16S7.9 7.6 16 7.6 29.3 16 29.3 16 24.1 24.4 16 24.4 2.7 16 2.7 16Z" fill="url(#v3EyeWhite)"/>
        <path d="M2.7 16S7.9 7.6 16 7.6 29.3 16 29.3 16 24.1 24.4 16 24.4 2.7 16 2.7 16Z" fill="none" stroke="url(#v3EyeRim)" stroke-width="1.5"/>
        <path d="M4.4 15.2S9.1 9.4 16 9.4c2.2 0 4.1.7 5.8 1.6" fill="none" stroke="#FFFFFF" stroke-width="1.5" stroke-linecap="round" opacity="0.55"/>
        <circle cx="16" cy="16" r="5.2" fill="url(#v3EyeIris)"/>
        <circle cx="16" cy="16" r="2.4" fill="#171233"/>
        <circle cx="13.9" cy="13.8" r="1.5" fill="#FFFFFF" opacity="0.92"/>
        <circle cx="18.2" cy="18.4" r="0.9" fill="#FFFFFF" opacity="0.35"/>
    </symbol>
    <symbol id="v3i-eye-off" viewBox="0 0 32 32">
        <ellipse cx="16" cy="28.4" rx="8.6" ry="1.8" fill="#1B1630" opacity="0.20"/>
        <path d="M2.7 16S7.9 7.6 16 7.6 29.3 16 29.3 16 24.1 24.4 16 24.4 2.7 16 2.7 16Z" fill="url(#v3EyeWhite)" opacity="0.45"/>
        <path d="M2.7 16S7.9 7.6 16 7.6 29.3 16 29.3 16 24.1 24.4 16 24.4 2.7 16 2.7 16Z" fill="none" stroke="url(#v3EyeRim)" stroke-width="1.4" opacity="0.75"/>
        <circle cx="16" cy="16" r="5.2" fill="url(#v3EyeIris)" opacity="0.55"/>
        <circle cx="16" cy="16" r="2.3" fill="#171233" opacity="0.8"/>
        <path d="M5.6 26.2 26.4 5.4" stroke="#1B1630" stroke-width="5.6" stroke-linecap="round" opacity="0.35"/>
        <path d="M5.6 26.2 26.4 5.4" stroke="#F87171" stroke-width="3.1" stroke-linecap="round"/>
        <path d="M6.6 24.9 25.2 6.3" stroke="#FFFFFF" stroke-width="0.9" stroke-linecap="round" opacity="0.5"/>
    </symbol>
</svg>
SVG;
    echo "\n";
}

function veyrax_ui_kit() {
    $v = defined('VEYRAX_ASSET_VERSION') ? VEYRAX_ASSET_VERSION : '1';
    echo '<link rel="stylesheet" href="assets/vendor/animate.min.css?v=' . $v . '">' . "\n    ";
    echo '<link rel="stylesheet" href="assets/vendor/sweetalert2.min.css?v=' . $v . '">' . "\n    ";
    echo '<link rel="stylesheet" href="assets/css/vx-ui.css?v=' . $v . '">' . "\n    ";
    echo '<script src="assets/vendor/sweetalert2.all.min.js?v=' . $v . '" defer></script>' . "\n    ";
    echo '<script src="assets/js/vx-ui.js?v=' . $v . '" defer></script>';
}

function veyrax_hex_rgb($hex) {
    if (!is_string($hex) || !preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) return null;
    return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
}

function veyrax_rgb_hsl($r, $g, $b) {
    $r /= 255; $g /= 255; $b /= 255;
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    $h = 0.0; $s = 0.0;
    $d = $max - $min;
    if ($d > 0) {
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        if ($max == $r) $h = fmod(($g - $b) / $d, 6);
        elseif ($max == $g) $h = (($b - $r) / $d) + 2;
        else $h = (($r - $g) / $d) + 4;
        $h *= 60;
        if ($h < 0) $h += 360;
    }
    return [(int)round($h), (int)round($s * 100), (int)round($l * 100)];
}

function veyrax_theme_palette($hex) {
    $rgb = veyrax_hex_rgb($hex);
    if (!$rgb) $rgb = [99, 102, 241];
    list($r, $g, $b) = $rgb;
    $hex = '#' . sprintf('%02x%02x%02x', $r, $g, $b);
    list($h, $s) = veyrax_rgb_hsl($r, $g, $b);
    $lum = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
    $up = function ($v, $t) { return (int)round($v + (255 - $v) * $t); };
    $dn = function ($v, $t) { return (int)round($v * $t); };
    $sBg = min(26, max(7, (int)round($s * 0.30)));
    $sSf = min(24, max(7, (int)round($s * 0.28)));
    return [
        'primary'        => $hex,
        'on-primary'     => $lum > 0.62 ? '#0b0f19' : '#ffffff',
        'primary-hover'  => sprintf('rgb(%d,%d,%d)', $up($r, 0.16), $up($g, 0.16), $up($b, 0.16)),
        'primary-active' => sprintf('rgb(%d,%d,%d)', $dn($r, 0.8), $dn($g, 0.8), $dn($b, 0.8)),
        'primary-strong' => sprintf('rgb(%d,%d,%d)', $dn($r, 0.8), $dn($g, 0.8), $dn($b, 0.8)),
        'primary-soft'   => sprintf('rgba(%d,%d,%d,0.14)', $r, $g, $b),
        'primary-border' => sprintf('rgba(%d,%d,%d,0.4)', $r, $g, $b),
        'primary-glow'   => sprintf('rgba(%d,%d,%d,0.35)', $r, $g, $b),
        'soft'           => sprintf('rgba(%d,%d,%d,0.18)', $r, $g, $b),
        'glow-soft'      => sprintf('rgba(%d,%d,%d,0.28)', $r, $g, $b),
        'active-soft'    => sprintf('rgba(%d,%d,%d,0.35)', $r, $g, $b),
        'accent'         => sprintf('rgb(%d,%d,%d)', $up($r, 0.25), $up($g, 0.25), $up($b, 0.25)),
        'bg-0'           => sprintf('hsl(%d,%d%%,5%%)', $h, $sBg),
        'bg-1'           => sprintf('hsl(%d,%d%%,8%%)', $h, $sBg),
        'bg-2'           => sprintf('hsl(%d,%d%%,3.5%%)', $h, $sBg),
        'surface'        => sprintf('hsl(%d,%d%%,12%%)', $h, $sSf),
        'surface-2'      => sprintf('hsl(%d,%d%%,15.5%%)', $h, $sSf),
        'surface-a'      => sprintf('hsla(%d,%d%%,12%%,0.88)', $h, $sSf),
        'surface-2a'     => sprintf('hsla(%d,%d%%,15.5%%,0.9)', $h, $sSf),
        'border'         => sprintf('hsla(%d,%d%%,66%%,0.14)', $h, $sBg),
        'border-strong'  => sprintf('hsla(%d,%d%%,66%%,0.26)', $h, $sBg),
        'dot'            => sprintf('hsla(%d,%d%%,72%%,0.10)', $h, $sBg),
        'glow-a'         => sprintf('rgba(%d,%d,%d,0.20)', $r, $g, $b),
        'glow-b'         => sprintf('rgba(%d,%d,%d,0.07)', $r, $g, $b),
        'bubble'         => sprintf('hsl(%d,%d%%,19%%)', $h, $sSf),
        'input'          => sprintf('hsla(%d,%d%%,9%%,0.9)', $h, $sSf),
        'header'         => sprintf('hsla(%d,%d%%,10%%,0.84)', $h, $sSf),
    ];
}

function veyrax_theme_css($hex = '#6366f1', $scale = 1.0) {
    $css = ':root{';
    foreach (veyrax_theme_palette($hex) as $k => $v) {
        $css .= '--v-' . $k . ':' . $v . ';';
    }
    return $css . '--v-fs:' . number_format(veyrax_fs_clamp($scale), 2, '.', '') . '}';
}

function veyrax_theme_js($color = '', $scale = 1.0, $userBound = false) {
    $color = (is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) ? strtolower($color) : '';
    $cfg = [
        'color'  => $color,
        'scale'  => veyrax_fs_clamp($scale),
        'user'   => (bool)$userBound,
        'steps'  => veyrax_fs_steps(),
        'def'    => '#6366f1',
    ];
    return '(function(){var VX=' . json_encode($cfg) . ';'
        . 'var el=function(){return document.getElementById("vThemeTokens")};'
        . 'function up(v,t){return Math.round(v+(255-v)*t)}'
        . 'function dn(v,t){return Math.round(v*t)}'
        . 'function hsl(c){var r=parseInt(c.substr(1,2),16),g=parseInt(c.substr(3,2),16),b=parseInt(c.substr(5,2),16);'
        . 'var R=r/255,G=g/255,B=b/255,mx=Math.max(R,G,B),mn=Math.min(R,G,B),l=(mx+mn)/2,h=0,s=0,d=mx-mn;'
        . 'if(d>0){s=l>0.5?d/(2-mx-mn):d/(mx+mn);if(mx===R)h=((G-B)/d)%6;else if(mx===G)h=(B-R)/d+2;else h=(R-G)/d+4;h*=60;if(h<0)h+=360;}'
        . 'return[Math.round(h),Math.round(s*100),Math.round(l*100),r,g,b]}'
        . 'function palette(c){var q=hsl(c),h=q[0],s=q[1],r=q[3],g=q[4],b=q[5];'
        . 'var sBg=Math.min(26,Math.max(7,Math.round(s*0.30))),sSf=Math.min(24,Math.max(7,Math.round(s*0.28)));'
        . 'var lum=(0.2126*r+0.7152*g+0.0722*b)/255;'
        . 'var p={};'
        . 'p["primary"]=c;p["on-primary"]=lum>0.62?"#0b0f19":"#ffffff";'
        . 'p["primary-hover"]="rgb("+up(r,0.16)+","+up(g,0.16)+","+up(b,0.16)+")";'
        . 'p["primary-active"]="rgb("+dn(r,0.8)+","+dn(g,0.8)+","+dn(b,0.8)+")";'
        . 'p["primary-strong"]=p["primary-active"];'
        . 'p["primary-soft"]="rgba("+r+","+g+","+b+",0.14)";'
        . 'p["primary-border"]="rgba("+r+","+g+","+b+",0.4)";'
        . 'p["primary-glow"]="rgba("+r+","+g+","+b+",0.35)";'
        . 'p["soft"]="rgba("+r+","+g+","+b+",0.18)";'
        . 'p["glow-soft"]="rgba("+r+","+g+","+b+",0.28)";'
        . 'p["active-soft"]="rgba("+r+","+g+","+b+",0.35)";'
        . 'p["accent"]="rgb("+up(r,0.25)+","+up(g,0.25)+","+up(b,0.25)+")";'
        . 'p["bg-0"]="hsl("+h+","+sBg+"%,5%)";p["bg-1"]="hsl("+h+","+sBg+"%,8%)";p["bg-2"]="hsl("+h+","+sBg+"%,3.5%)";'
        . 'p["surface"]="hsl("+h+","+sSf+"%,12%)";p["surface-2"]="hsl("+h+","+sSf+"%,15.5%)";'
        . 'p["surface-a"]="hsla("+h+","+sSf+"%,12%,0.88)";p["surface-2a"]="hsla("+h+","+sSf+"%,15.5%,0.9)";'
        . 'p["border"]="hsla("+h+","+sBg+"%,66%,0.14)";p["border-strong"]="hsla("+h+","+sBg+"%,66%,0.26)";'
        . 'p["dot"]="hsla("+h+","+sBg+"%,72%,0.10)";'
        . 'p["glow-a"]="rgba("+r+","+g+","+b+",0.20)";p["glow-b"]="rgba("+r+","+g+","+b+",0.07)";'
        . 'p["bubble"]="hsl("+h+","+sSf+"%,19%)";p["input"]="hsla("+h+","+sSf+"%,9%,0.9)";p["header"]="hsla("+h+","+sSf+"%,10%,0.84)";'
        . 'return p}'
        . 'function tokens(c,sc){var p=palette(c),o=":root{";for(var k in p)o+="--v-"+k+":"+p[k]+";";return o+"--v-fs:"+sc.toFixed(2)+"}"}'
        . 'function paint(){if(!/^#[0-9a-fA-F]{6}$/.test(VX.color))VX.color=VX.def;var n=el();if(n)n.textContent=tokens(VX.color,VX.scale);'
        . 'var m=document.querySelector(\'meta[name="theme-color"]\');if(m)m.setAttribute("content",VX.color)}'
        . 'var t=null,synced=false;'
        . 'function sync(){if(!VX.user||synced)return;synced=true;'
        . 'try{var f=new FormData(),m=document.querySelector(\'meta[name="csrf-token"]\');'
        . 'f.append("action","save_ui_prefs");f.append("csrf_token",m?m.getAttribute("content"):"");'
        . 'f.append("theme_color",VX.color);f.append("font_scale",String(VX.scale));'
        . 'fetch("chat.php",{method:"POST",body:f}).catch(function(){})}catch(e){}}'
        . 'function store(){try{localStorage.setItem("veyrax_theme_color",VX.color);localStorage.setItem("veyrax_font_scale",String(VX.scale))}catch(e){}'
        . 'if(!VX.user)return;synced=false;clearTimeout(t);t=setTimeout(sync,500)}'
        . 'window.veyraxThemeState=function(){return{color:VX.color,scale:VX.scale}};'
        . 'window.veyraxApplyTheme=function(c,opt){if(!/^#[0-9a-fA-F]{6}$/.test(c||""))return;VX.color=c.toLowerCase();paint();'
        . 'if(!opt||opt.save!==false)store();if(window.veyraxSyncSwatches)window.veyraxSyncSwatches(VX.color)};'
        . 'window.veyraxApplyFontScale=function(v,opt){VX.scale=(function(x){var b=1,bd=99;for(var i=0;i<VX.steps.length;i++){var d=Math.abs(VX.steps[i]-x);if(d<bd){bd=d;b=VX.steps[i]}}return b})(parseFloat(v));'
        . 'paint();if(!opt||opt.save!==false)store();if(window.veyraxSyncScale)window.veyraxSyncScale(VX.scale)};'
        . 'window.veyraxResetTheme=function(){VX.color=VX.def;VX.scale=1;paint();store();'
        . 'if(window.veyraxSyncSwatches)window.veyraxSyncSwatches(VX.color);if(window.veyraxSyncScale)window.veyraxSyncScale(1)};'
        . 'var sc=null,ss=null;try{sc=localStorage.getItem("veyrax_theme_color");ss=parseFloat(localStorage.getItem("veyrax_font_scale"))}catch(e){}'
        . 'if(!VX.color&&sc&&/^#[0-9a-fA-F]{6}$/.test(sc)){VX.color=sc.toLowerCase();if(VX.user)VX.needsync=1}'
        . 'if(!ss||!isFinite(ss))ss=VX.scale;'
        . 'if(!VX.color)VX.color=VX.def;'
        . 'if(!VX.user||VX.needsync)VX.scale=(function(x){var b=1,bd=99;for(var i=0;i<VX.steps.length;i++){var d=Math.abs(VX.steps[i]-x);if(d<bd){bd=d;b=VX.steps[i]}}return b})(ss);'
        . 'paint();'
        . 'if(VX.user){try{localStorage.setItem("veyrax_theme_color",VX.color);localStorage.setItem("veyrax_font_scale",String(VX.scale))}catch(e){}'
        . 'if(VX.needsync&&!localStorage.getItem("veyrax_pref_synced")){try{localStorage.setItem("veyrax_pref_synced","1")}catch(e){}setTimeout(sync,900)}}'
        . '})();';
}

function veyrax_theme_bootstrap($color = '', $scale = 1.0, $userBound = false) {
    $base = (is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) ? strtolower($color) : '#6366f1';
    echo '<style id="vThemeTokens">' . veyrax_theme_css($base, $scale) . '</style>';
    echo '<script>' . veyrax_theme_js($color, $scale, $userBound) . '</script>';
}

function veyrax_user_ui_prefs($pdo, $userId, $fallbackColor = '', $fallbackScale = 1.0) {
    $color = '';
    $scale = 1.0;
    if (isset($_SESSION['ui_theme']) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$_SESSION['ui_theme'])) {
        $color = strtolower($_SESSION['ui_theme']);
    }
    if (isset($_SESSION['ui_fs'])) $scale = veyrax_fs_clamp($_SESSION['ui_fs']);
    try {
        $stmt = $pdo->prepare("SELECT theme_color FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $dbColor = $stmt->fetchColumn();
        if ($color === '' && is_string($dbColor) && preg_match('/^#[0-9a-fA-F]{6}$/', $dbColor)) $color = strtolower($dbColor);
    } catch (Exception $e) {}
    try {
        $stmt = $pdo->prepare("SELECT font_scale FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $dbScale = $stmt->fetchColumn();
        if ($dbScale !== false && $dbScale !== null && is_numeric($dbScale) && !isset($_SESSION['ui_fs'])) $scale = veyrax_fs_clamp($dbScale);
    } catch (Exception $e) {}
    return [$color, $scale];
}

function veyrax_setting_set($pdo, $key, $value) {
    try {
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([$key, (string)$value]);
        veyrax_settings_all($pdo, true); // drop the per-request cache
        return true;
    } catch (Exception $e) {
        error_log('[VIRAX settings] ' . $key . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Delete a previously stored brand logo — but only a file this uploader made.
 *
 * The favicon set, the apple-touch icon and the manifest icons all live in
 * assets/brand/ next to the uploaded logos. brand_logo has been pointed at
 * favicon-192.png before, and an unguarded unlink() then wiped the 192 px
 * icon on the next logo upload, leaving a 404 in every <head> and in the PWA
 * manifest. Only logo-<hex>.<ext> may ever be removed.
 */
function veyrax_brand_logo_unlink($storedPath) {
    $rel = ltrim(str_replace('\\', '/', (string)$storedPath), '/');
    if ($rel === '' || strpos($rel, 'assets/brand/') !== 0) return false;
    $base = substr($rel, strlen('assets/brand/'));
    if (!preg_match('/^logo-[0-9a-f]{8,}\.(png|jpe?g|webp|gif)$/i', $base)) return false;
    $abs = __DIR__ . '/' . $rel;
    return is_file($abs) ? @unlink($abs) : false;
}

// ─────────────────────────────────────────────────────────────────────────────
// SMS gateway — multi provider, configured from the admin panel.
//   sms_enabled  : 1|0
//   sms_provider : kavenegar | smsir | melipayamak | ghasedak | webhook | none
//   sms_api_key  : provider credential (API key / token / hash)
//   sms_sender   : sender line / originator (optional for some providers)
//   sms_pattern  : provider template id for OTP messages (sms.ir / kavenegar)
//   sms_webhook  : custom endpoint for provider=webhook
//   sms_dev_mode : 1 = never call the gateway, log the code and return it in
//                  the JSON response (local testing only)
// ─────────────────────────────────────────────────────────────────────────────
function veyrax_sms_cfg($pdo) {
    return [
        'enabled'  => veyrax_setting($pdo, 'sms_enabled', '0') === '1',
        'provider' => strtolower(trim(veyrax_setting($pdo, 'sms_provider', 'none'))),
        'api_key'  => trim(veyrax_setting($pdo, 'sms_api_key', '')),
        'sender'   => trim(veyrax_setting($pdo, 'sms_sender', '')),
        'pattern'  => trim(veyrax_setting($pdo, 'sms_pattern', '')),
        'webhook'  => trim(veyrax_setting($pdo, 'sms_webhook', '')),
        'dev_mode' => veyrax_setting($pdo, 'sms_dev_mode', '0') === '1',
    ];
}

function veyrax_sms_dev_mode() {
    if (getenv('VIRAX_SMS_DEV') === '1' || getenv('VEYRAX_SMS_DEV') === '1') return true;
    global $pdo;
    if ($pdo instanceof PDO) {
        try { return veyrax_sms_cfg($pdo)['dev_mode']; } catch (Exception $e) {}
    }
    return false;
}

/** True when a real gateway is configured and enabled. */
function veyrax_sms_ready($pdo) {
    $c = veyrax_sms_cfg($pdo);
    if (!$c['enabled']) return false;
    if ($c['provider'] === 'webhook') return $c['webhook'] !== '';
    if ($c['provider'] === 'none' || $c['provider'] === '') return false;
    return $c['api_key'] !== '';
}

function veyrax_sms_normalize_phone($phone) {
    $p = preg_replace('/[^0-9+]/', '', (string)$phone);
    if (strpos($p, '+98') === 0) $p = '0' . substr($p, 3);
    elseif (strpos($p, '0098') === 0) $p = '0' . substr($p, 4);
    elseif (strpos($p, '98') === 0 && strlen($p) === 12) $p = '0' . substr($p, 2);
    elseif (strpos($p, '9') === 0 && strlen($p) === 10) $p = '0' . $p;
    return preg_replace('/[^0-9]/', '', $p);
}

function veyrax_sms_log($pdo, $phone, $provider, $ok, $detail, $purpose = 'otp') {
    try {
        $pdo->prepare("INSERT INTO sms_log (phone, provider, purpose, ok, detail, created_at) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([substr($phone, 0, 15), substr($provider, 0, 30), substr($purpose, 0, 30), $ok ? 1 : 0, mb_substr((string)$detail, 0, 250, 'UTF-8'), date('Y-m-d H:i:s')]);
        // Keep the log bounded without a cron job.
        if (random_int(1, 25) === 1) {
            $pdo->exec("DELETE FROM sms_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        }
    } catch (Exception $e) {}
}

/** Low level HTTP helper used by every provider. */
function veyrax_http_post($url, $body, array $headers = [], $timeout = 8) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return ['code' => $code, 'body' => (string)$resp, 'error' => $err];
    }
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers),
        'content'       => $body,
        'timeout'       => $timeout,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $code = (int)$m[1];
    return ['code' => $code, 'body' => (string)$resp, 'error' => $resp === false ? 'request_failed' : ''];
}

/**
 * Send an SMS through the configured gateway.
 * @return array{ok:bool,provider:string,error:string,dev_code:?string}
 */
function veyrax_sms_send_ex($phone, $message, $purpose = 'otp', $code = null) {
    global $pdo;
    $phone = veyrax_sms_normalize_phone($phone);
    $out = ['ok' => false, 'provider' => 'none', 'error' => '', 'dev_code' => null];
    if ($phone === '' || strlen($phone) < 10) {
        $out['error'] = 'invalid_phone';
        return $out;
    }
    if (!($pdo instanceof PDO)) {
        $out['error'] = 'no_db';
        return $out;
    }
    $cfg = veyrax_sms_cfg($pdo);
    $out['provider'] = $cfg['provider'];

    if ($cfg['dev_mode']) {
        error_log('[VIRAX SMS DEV] ' . $phone . ' => ' . $message);
        veyrax_sms_log($pdo, $phone, 'dev', true, 'dev_mode', $purpose);
        return ['ok' => true, 'provider' => 'dev', 'error' => '', 'dev_code' => $code];
    }
    if (!veyrax_sms_ready($pdo)) {
        $out['error'] = 'not_configured';
        error_log('[VIRAX SMS] gateway not configured — message for ' . $phone . ' was not delivered.');
        veyrax_sms_log($pdo, $phone, $cfg['provider'], false, 'not_configured', $purpose);
        return $out;
    }

    $text = $message;
    $res = ['code' => 0, 'body' => '', 'error' => ''];
    $key = $cfg['api_key'];
    $from = $cfg['sender'];

    switch ($cfg['provider']) {
        case 'kavenegar':
            // https://kavenegar.com/rest.html
            $url = 'https://api.kavenegar.com/v1/' . rawurlencode($key) . '/sms/send.json';
            $fields = ['receptor' => $phone, 'message' => $text];
            if ($from !== '') $fields['sender'] = $from;
            $res = veyrax_http_post($url, http_build_query($fields), ['Content-Type: application/x-www-form-urlencoded']);
            break;

        case 'smsir':
            // https://sms.ir — bulk endpoint (works with a shared line).
            $url = 'https://api.sms.ir/v1/send/bulk';
            $payload = ['messageText' => $text, 'mobiles' => [$phone]];
            if ($from !== '') $payload['lineNumber'] = $from;
            $res = veyrax_http_post($url, json_encode($payload, JSON_UNESCAPED_UNICODE), ['Content-Type: application/json', 'Accept: application/json', 'x-api-key: ' . $key]);
            break;

        case 'melipayamak':
            $url = 'https://console.melipayamak.com/api/send/simple/' . rawurlencode($key);
            $res = veyrax_http_post($url, json_encode(['from' => $from, 'to' => $phone, 'text' => $text], JSON_UNESCAPED_UNICODE), ['Content-Type: application/json']);
            break;

        case 'ghasedak':
            $url = 'https://api.ghasedak.me/v2/sms/send/simple';
            $payload = ['receptor' => $phone, 'message' => $text];
            if ($from !== '') $payload['linenumber'] = $from;
            $res = veyrax_http_post($url, http_build_query($payload), ['Content-Type: application/x-www-form-urlencoded', 'apikey: ' . $key]);
            break;

        case 'webhook':
            $res = veyrax_http_post($cfg['webhook'], json_encode(['phone' => $phone, 'message' => $text, 'purpose' => $purpose], JSON_UNESCAPED_UNICODE), ['Content-Type: application/json']);
            break;

        default:
            $out['error'] = 'unknown_provider';
            veyrax_sms_log($pdo, $phone, $cfg['provider'], false, 'unknown_provider', $purpose);
            return $out;
    }

    $body = (string)$res['body'];
    $httpOk = $res['code'] >= 200 && $res['code'] < 300;
    // Providers answer 200 with an error payload; catch the common ones.
    $bodyFail = (bool)preg_match('/"status"\s*:\s*(4|5)\d\d|"return"\s*:\s*"[^"]*(error|fail)|"IsSuccess"\s*:\s*false|"status"\s*:\s*"error"/i', $body);
    $out['ok'] = $httpOk && !$bodyFail && $res['error'] === '';
    if (!$out['ok']) {
        $out['error'] = $res['error'] !== '' ? $res['error'] : ('http_' . $res['code'] . '_' . mb_substr(strip_tags($body), 0, 120, 'UTF-8'));
    }
    veyrax_sms_log($pdo, $phone, $cfg['provider'], $out['ok'], $out['ok'] ? 'sent' : $out['error'], $purpose);
    return $out;
}

/** Backwards-compatible boolean wrapper. */
function veyrax_send_sms($phone, $message) {
    $r = veyrax_sms_send_ex($phone, $message);
    return (bool)$r['ok'];
}

// ─────────────────────────────────────────────────────────────────────────────
// Login / OTP brute-force protection (database backed, survives a new session
// and a cleared cookie — a session counter alone is trivially bypassed).
// ─────────────────────────────────────────────────────────────────────────────
function veyrax_throttle_state($pdo, $ip, $username, $scope = 'login', $fixedLock = 0) {
    $window  = 900;   // 15 minutes
    $ipMax   = 12;    // failures per IP inside the window
    $userMax = 5;     // failures per username inside the window
    $state = ['blocked' => false, 'retry' => 0, 'ip_fails' => 0, 'user_fails' => 0, 'left' => $userMax];
    try {
        $since = date('Y-m-d H:i:s', time() - $window);
        $st = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND scope = ? AND success = 0 AND created_at >= ?");
        $st->execute([$ip, $scope, $since]);
        $state['ip_fails'] = (int)$st->fetchColumn();

        if ($username !== '') {
            $st = $pdo->prepare("SELECT COUNT(*), MAX(created_at) FROM login_attempts WHERE username = ? AND scope = ? AND success = 0 AND created_at >= ?");
            $st->execute([$username, $scope, $since]);
            $row = $st->fetch(PDO::FETCH_NUM);
            $state['user_fails'] = (int)($row[0] ?? 0);
            $last = $row[1] ?? null;
        } else {
            $last = null;
        }

        $state['left'] = max(0, $userMax - $state['user_fails']);

        if ($state['ip_fails'] >= $ipMax || $state['user_fails'] >= $userMax) {
            if ($fixedLock > 0) {
                // Security-code recovery uses one flat lock (15 minutes) instead
                // of the exponential ladder.
                $lock = (int)$fixedLock;
            } else {
                $excess = max($state['ip_fails'] - $ipMax, $state['user_fails'] - $userMax, 0);
                $lock = min(3600, 60 * (int)pow(2, min($excess, 6)));
            }
            $lastTs = $last ? strtotime($last) : time();
            $until = $lastTs + $lock;
            if ($until > time()) {
                $state['blocked'] = true;
                $state['retry'] = $until - time();
            }
        }
    } catch (Exception $e) {}
    return $state;
}

function veyrax_throttle_record($pdo, $ip, $username, $scope, $success) {
    try {
        $pdo->prepare("INSERT INTO login_attempts (ip_address, username, scope, success, created_at) VALUES (?, ?, ?, ?, ?)")
            ->execute([substr((string)$ip, 0, 45), substr((string)$username, 0, 50), substr((string)$scope, 0, 20), $success ? 1 : 0, date('Y-m-d H:i:s')]);
        if ($success && $username !== '') {
            $pdo->prepare("DELETE FROM login_attempts WHERE username = ? AND scope = ? AND success = 0")->execute([$username, $scope]);
        }
        if (random_int(1, 20) === 1) {
            $pdo->exec("DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)");
        }
    } catch (Exception $e) {}
}

/** Human readable Persian wait time, e.g. "۳ دقیقه و ۲۰ ثانیه". */
function veyrax_human_wait($seconds) {
    $seconds = max(0, (int)$seconds);
    if ($seconds < 60) return $seconds . ' ثانیه';
    $m = intdiv($seconds, 60);
    $s = $seconds % 60;
    return $s > 0 ? ($m . ' دقیقه و ' . $s . ' ثانیه') : ($m . ' دقیقه');
}
