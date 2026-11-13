<?php
/**
 * Chunked media upload.
 *
 * The old path posted the whole file inside `action=send`. That capped every
 * upload at PHP's `upload_max_filesize` (2 MB on a stock WAMP install), so any
 * real video died before it reached the server — and the failure was silent,
 * because `$_FILES['file']['error']` was simply non-zero and the send handler
 * fell through to "empty message".
 *
 * Here the browser slices the file into small chunks, the server appends them
 * to a staging file, and only when the last chunk has landed does the client
 * ask for a token. The token is then sent as a normal, tiny message — so the
 * media is completely uploaded before anyone else is told about it.
 */
require_once 'config.php';
header('Content-Type: application/json');

function up_fail($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function up_ok(array $extra = []) {
    echo json_encode(array_merge(['status' => 'success'], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    up_fail('عدم دسترسی', 403);
}

$userId   = (int)$_SESSION['user_id'];
$username = (string)($_SESSION['username'] ?? '');
$isAdmin  = ($username === $admin_username) || is_admin_user($username, $pdo);

// Demo visitors never upload: the sandbox is text-only, and a public demo is
// the last place that should accept arbitrary files from anonymous strangers.
if (veyrax_is_demo_user($pdo, $userId)) {
    up_fail('ارسال رسانه در حالت نمایشی غیرفعال است', 403);
}

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$csrf   = (string)($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
$nonce  = (string)($_POST['bw_nonce'] ?? $_GET['bw_nonce'] ?? '');

if ($csrf === '' || !isset($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrf)) {
    up_fail('نشست نامعتبر', 403);
}
if ($nonce === '' || !isset($_SESSION['bw_nonce']) || !hash_equals((string)$_SESSION['bw_nonce'], $nonce)) {
    up_fail('خطای امنیتی - لطفا صفحه را رفرش کنید', 403);
}

// Banned / muted users must not be able to fill the disk either.
$stmtU = $pdo->prepare("SELECT is_banned_until, muted_until FROM users WHERE id = ? LIMIT 1");
$stmtU->execute([$userId]);
$uRow = $stmtU->fetch(PDO::FETCH_ASSOC);
if (!$uRow) up_fail('کاربر یافت نشد', 403);
if ((int)$uRow['is_banned_until'] > time()) up_fail('دسترسی شما محدود شده است', 403);
if ((int)$uRow['muted_until'] > time()) up_fail('ارسال پیام برای شما موقتاً غیرفعال است', 403);

// Nothing below touches the session, so release the lock now: chunk uploads
// from several files would otherwise serialise on the session file.
session_write_close();

$stagingDir = __DIR__ . '/data/staging';
if (!is_dir($stagingDir)) @mkdir($stagingDir, 0777, true);

$extDenied = ['php','php3','php4','php5','php7','php8','phtml','phar','sh','bash','pl','py','rb',
              'htaccess','htpasswd','cgi','asp','aspx','jsp','exe','dll','bat','cmd','msi','scr','js'];
$extAllowed = ['jpg','jpeg','png','gif','webp','bmp','svg','ico',
               'mp3','wav','ogg','webm','m4a','flac','aac','opus',
               'mp4','mov','avi','mkv','m4v','3gp','wmv',
               'pdf','zip','rar','7z','txt','doc','docx','xls','xlsx','ppt','pptx','csv','json'];

function up_staging_meta($id) {
    global $stagingDir;
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) return null;
    $f = $stagingDir . '/' . $id . '.json';
    if (!is_file($f)) return null;
    $j = json_decode((string)@file_get_contents($f), true);
    return is_array($j) ? $j : null;
}

function up_staging_part($id) {
    global $stagingDir;
    return $stagingDir . '/' . $id . '.part';
}

function up_cleanup($id) {
    $part = up_staging_part($id);
    $meta = $stagingDir . '/' . $id . '.json';
    @unlink($part);
    @unlink($meta);
}

// Sweep abandoned staging files (a closed tab mid-upload). Cheap: one scandir
// and only stat() on files older than an hour.
foreach ((array)@scandir($stagingDir) as $f) {
    if ($f === '.' || $f === '..' || strpos($f, '.') === 0) continue;
    $p = $stagingDir . '/' . $f;
    if (!is_file($p)) continue;
    $age = time() - (int)@filemtime($p);
    if ($age > 3600) @unlink($p);
}

$CHUNK_LIMIT = 8 * 1024 * 1024;   // hard ceiling per request

switch ($action) {

    case 'init': {
        $name = (string)($_POST['name'] ?? 'file');
        $size = (int)($_POST['size'] ?? 0);
        $isVoice = ((string)($_POST['is_voice'] ?? '') === 'true');
        // `.webm` is both a video and an audio container, so the extension
        // alone cannot decide. The browser knows the real MIME family.
        $mime = strtolower(trim((string)($_POST['mime'] ?? '')));
        $mime = preg_replace('/[^a-z0-9\/+.\-]/', '', $mime);

        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F<>"\'&]/u', '', $name);
        $name = mb_substr(trim((string)$name), 0, 120, 'UTF-8');
        if ($name === '') $name = 'file';

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, $extDenied, true)) {
            up_fail('این فرمت فایل مجاز نیست (خطر سمت سرور)');
        }
        if ($ext !== '' && !in_array($ext, $extAllowed, true)) {
            up_fail('این فرمت فایل پشتیبانی نمی‌شود');
        }
        if ($size <= 0) up_fail('فایل خالی است');
        if ($size > $file_size_limit) {
            up_fail('حجم فایل زیاد است (حداکثر ' . round($file_size_limit / 1024 / 1024) . ' مگابایت)');
        }

        $id = bin2hex(random_bytes(16));
        $part = up_staging_part($id);
        if (@file_put_contents($part, '') === false) up_fail('خطا در آماده‌سازی آپلود', 500);

        @file_put_contents($stagingDir . '/' . $id . '.json', json_encode([
            'user_id'  => $userId,
            'name'     => $name,
            'ext'      => $ext,
            'mime'     => $mime,
            'size'     => $size,
            'is_voice' => $isVoice,
            'chunks'   => 0,
            'started'  => time(),
        ], JSON_UNESCAPED_UNICODE));

        up_ok(['upload_id' => $id, 'chunk_size' => 512 * 1024]);
    }

    case 'chunk': {
        $id    = (string)($_GET['upload_id'] ?? $_POST['upload_id'] ?? '');
        $index = (int)($_GET['index'] ?? $_POST['index'] ?? 0);
        $meta  = up_staging_meta($id);
        if (!$meta) up_fail('آپلود یافت نشد', 404);
        if ((int)$meta['user_id'] !== $userId) up_fail('عدم دسترسی', 403);

        $part = up_staging_part($id);
        if (!is_file($part)) up_fail('آپلود یافت نشد', 404);

        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') up_fail('قطعه خالی است');
        if (strlen($raw) > $CHUNK_LIMIT) up_fail('قطعه بیش از حد بزرگ است', 413);

        $current = (int)@filesize($part);
        if ($current + strlen($raw) > (int)$meta['size'] + 1024) {
            up_cleanup($id);
            up_fail('حجم آپلود از مقدار اعلام‌شده بیشتر شد');
        }

        $fh = @fopen($part, 'ab');
        if (!$fh) up_fail('خطا در نوشتن فایل', 500);
        $wrote = fwrite($fh, $raw);
        fclose($fh);
        if ($wrote === false) up_fail('خطا در نوشتن فایل', 500);

        $meta['chunks'] = (int)$meta['chunks'] + 1;
        @file_put_contents($stagingDir . '/' . $id . '.json', json_encode($meta, JSON_UNESCAPED_UNICODE));

        clearstatcache(true, $part);   // filesize() is stat-cached per request
        up_ok(['received' => (int)@filesize($part), 'index' => $index]);
    }

    case 'finish': {
        $id   = (string)($_POST['upload_id'] ?? $_GET['upload_id'] ?? '');
        $meta = up_staging_meta($id);
        if (!$meta) up_fail('آپلود یافت نشد', 404);
        if ((int)$meta['user_id'] !== $userId) up_fail('عدم دسترسی', 403);

        $part = up_staging_part($id);
        if (!is_file($part)) up_fail('آپلود یافت نشد', 404);

        $actual = (int)@filesize($part);
        if ($actual !== (int)$meta['size']) {
            up_cleanup($id);
            up_fail('آپلود کامل نشد (دریافت‌شده ' . $actual . ' از ' . (int)$meta['size'] . ' بایت)');
        }

        $content = @file_get_contents($part);
        if ($content === false) { up_cleanup($id); up_fail('خطا در خواندن فایل', 500); }

        $encrypted = encrypt_file_data($content);
        unset($content);
        if ($encrypted === false) { up_cleanup($id); up_fail('خطا در رمزگذاری فایل', 500); }

        $ext = (string)$meta['ext'];
        $newFileName = bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
        $uploadPath  = 'uploads/' . $newFileName;
        if (@file_put_contents(__DIR__ . '/' . $uploadPath, $encrypted) === false) {
            up_cleanup($id);
            up_fail('خطا در ذخیره فایل', 500);
        }
        unset($encrypted);

        $ext  = (string)$meta['ext'];
        $mime = (string)($meta['mime'] ?? '');
        $audioExt = ['mp3','wav','ogg','webm','m4a','flac','aac','opus'];
        $videoExt = ['mp4','mov','avi','mkv','m4v','3gp','wmv','webm'];
        $imageExt = ['jpg','jpeg','png','gif','webp','bmp','svg','ico'];

        if (!empty($meta['is_voice'])) {
            $msgType = 'voice';
        } elseif ($mime !== '') {
            // The declared MIME family wins: it is the only reliable way to
            // tell a screen recording from a voice note when both are .webm.
            if (strpos($mime, 'video/') === 0)      $msgType = 'video';
            elseif (strpos($mime, 'audio/') === 0)  $msgType = 'voice';
            elseif (strpos($mime, 'image/') === 0)  $msgType = 'image';
            else                                    $msgType = 'file';
        } elseif (in_array($ext, $audioExt, true)) {
            $msgType = 'voice';
        } elseif (in_array($ext, $videoExt, true)) {
            $msgType = 'video';
        } elseif (in_array($ext, $imageExt, true)) {
            $msgType = 'image';
        } else {
            $msgType = 'file';
        }

        $token = bin2hex(random_bytes(16));
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS staged_uploads (
                token VARCHAR(64) NOT NULL PRIMARY KEY,
                user_id INT NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                file_name VARCHAR(255) NOT NULL,
                file_size BIGINT NOT NULL DEFAULT 0,
                msg_type VARCHAR(16) NOT NULL DEFAULT 'file',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

            $pdo->prepare("DELETE FROM staged_uploads WHERE created_at < DATE_SUB(NOW(), INTERVAL 6 HOUR)")->execute();
            $pdo->prepare("INSERT INTO staged_uploads (token, user_id, file_path, file_name, file_size, msg_type)
                           VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$token, $userId, $uploadPath, (string)$meta['name'], (int)$meta['size'], $msgType]);
        } catch (Throwable $e) {
            @unlink(__DIR__ . '/' . $uploadPath);
            up_cleanup($id);
            up_fail('خطا در ثبت آپلود', 500);
        }

        up_cleanup($id);

        up_ok([
            'token'    => $token,
            'name'     => (string)$meta['name'],
            'size'     => (int)$meta['size'],
            'msg_type' => $msgType,
        ]);
    }

    case 'cancel': {
        $id = (string)($_POST['upload_id'] ?? $_GET['upload_id'] ?? '');
        $meta = up_staging_meta($id);
        if ($meta && (int)$meta['user_id'] === $userId) up_cleanup($id);
        up_ok();
    }

    default:
        up_fail('عملیات نامعتبر');
}
