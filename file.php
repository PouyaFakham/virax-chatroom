<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['token'])) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$isAdmin = ($username === $admin_username) || is_admin_user($username, $pdo);

$clientIp = get_client_ip();
$stmtIpBan = $pdo->prepare("SELECT id FROM banned_ips WHERE ip_address = ? LIMIT 1");
$stmtIpBan->execute([$clientIp]);
if ($stmtIpBan->fetch() && !$isAdmin) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

$stmt = $pdo->prepare("SELECT is_banned_until, device_token, ip_address, user_agent FROM users WHERE id = ?");
$stmt->execute([$userId]);
$userRow = $stmt->fetch();

if (!$userRow) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

if ($userRow['is_banned_until'] > time()) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

if (!$isAdmin && isset($_SESSION['session_token'])) {
    $stmtSess = $pdo->prepare("SELECT id FROM user_sessions WHERE session_token = ? AND user_id = ?");
    $stmtSess->execute([$_SESSION['session_token'], $userId]);
    if (!$stmtSess->fetch()) {
        header('HTTP/1.0 403 Forbidden');
        exit;
    }
}

$token = $_GET['token'];
if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    header('HTTP/1.0 404 Not Found');
    exit;
}

$stmt = $pdo->prepare("SELECT file_path, file_name, msg_type, sender_id, receiver_id, room_name FROM messages WHERE file_token = ? AND media_deleted = 0 LIMIT 1");
$stmt->execute([$token]);
$file = $stmt->fetch();

if (!$file) {
    header('HTTP/1.0 404 Not Found');
    exit;
}

if (!$isAdmin) {
    $canAccess = false;
    if ($file['room_name']) {
        if ($file['room_name'] === 'گفتگوی عمومی') {
            $canAccess = true;
        } else {
            $stmtRoom = $pdo->prepare("SELECT type FROM rooms WHERE name = ?");
            $stmtRoom->execute([$file['room_name']]);
            $roomRow = $stmtRoom->fetch();
            if ($roomRow && $roomRow['type'] === 'public') {
                $canAccess = true;
            } else {
                $stmtInv = $pdo->prepare("SELECT id FROM room_invites WHERE user_id = ? AND room_name = ?");
                $stmtInv->execute([$userId, $file['room_name']]);
                $canAccess = (bool)$stmtInv->fetch();
            }
        }
    } elseif ($file['receiver_id']) {
        $canAccess = ($file['sender_id'] == $userId || $file['receiver_id'] == $userId);
    } else {
        $canAccess = ($file['sender_id'] == $userId);
    }

    if (!$canAccess) {
        header('HTTP/1.0 403 Forbidden');
        exit;
    }
}

$realBase = realpath('uploads');
$realFile = realpath($file['file_path']);
if ($realBase === false || $realFile === false || strpos($realFile, $realBase . DIRECTORY_SEPARATOR) !== 0 || basename($realFile) === '.htaccess') {
    header('HTTP/1.0 404 Not Found');
    exit;
}

if (!file_exists($file['file_path'])) {
    header('HTTP/1.0 404 Not Found');
    exit;
}

$content = file_get_contents($file['file_path']);
$decrypted = decrypt_file_data($content);

if ($decrypted === false) {
    header('HTTP/1.0 500 Internal Server Error');
    exit;
}

$ext = strtolower(pathinfo($file['file_name'], PATHINFO_EXTENSION));

$mimeTypes = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png', 'gif' => 'image/gif',
    'webp' => 'image/webp', 'svg' => 'image/svg+xml',
    'bmp' => 'image/bmp', 'ico' => 'image/x-icon',
    'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
    'ogg' => 'audio/ogg', 'webm' => 'audio/webm',
    'm4a' => 'audio/mp4', 'flac' => 'audio/flac',
    'mp4' => 'video/mp4', 'mov' => 'video/quicktime',
    'avi' => 'video/x-msvideo', 'mkv' => 'video/x-matroska',
    'pdf' => 'application/pdf',
    'txt' => 'text/plain',
];

$isMedia = isset($mimeTypes[$ext]);
$dl = isset($_GET['dl']);

$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$isBrowserNav = (strpos($accept, 'text/html') !== false);

$randomName = bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');
$safeName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $randomName);

if ($isMedia && !$dl && !$isBrowserNav) {
    header("Content-Type: " . $mimeTypes[$ext]);
    header("Content-Disposition: inline; filename=\"" . $safeName . "\"");
    if ($ext === 'svg') {
        // An uploaded SVG must not be able to run scripts on this origin.
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
    }
} else {
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
}

header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
// Never let a browser second-guess the type we declared: an uploaded file is
// user content and must not be sniffed into something executable.
header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');

// HTTP Range support (needed for seekable audio/video players).
$range = $_SERVER['HTTP_RANGE'] ?? '';
$size = strlen($decrypted);
$start = 0;
$end = $size - 1;
$status = 200;
if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
    if ($m[1] === '' && $m[2] !== '') {
        $suffix = (int)$m[2];
        $start = max(0, $size - $suffix);
    } else {
        $start = (int)$m[1];
        if ($m[2] !== '') $end = (int)$m[2];
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    if ($end >= $size) $end = $size - 1;
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    header('Content-Length: ' . ($end - $start + 1));
    http_response_code(206);
    echo substr($decrypted, $start, $end - $start + 1);
    exit;
}

header('Content-Length: ' . $size);

echo $decrypted;
exit;
?>
