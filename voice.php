<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['token'])) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$isAdmin = ($username === $admin_username) || is_admin_user($username, $pdo);

$clientIp = get_client_ip();
$stmtIpBan = $pdo->prepare("SELECT id FROM banned_ips WHERE ip_address = ? LIMIT 1");
$stmtIpBan->execute([$clientIp]);
if ($stmtIpBan->fetch() && !$isAdmin) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

$stmt = $pdo->prepare("SELECT is_banned_until FROM users WHERE id = ?");
$stmt->execute([$userId]);
$userRow = $stmt->fetch();
if (!$userRow || $userRow['is_banned_until'] > time()) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

if (!$isAdmin && isset($_SESSION['session_token'])) {
    $stmtSess = $pdo->prepare("SELECT id FROM user_sessions WHERE session_token = ? AND user_id = ?");
    $stmtSess->execute([$_SESSION['session_token'], $userId]);
    if (!$stmtSess->fetch()) {
        header('HTTP/1.1 403 Forbidden');
        exit;
    }
}

$token = $_GET['token'];
if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    header('HTTP/1.1 404 Not Found');
    exit;
}

$stmt = $pdo->prepare("SELECT file_path, msg_type, sender_id, receiver_id, room_name FROM messages WHERE file_token = ? AND msg_type = 'voice' AND media_deleted = 0 LIMIT 1");
$stmt->execute([$token]);
$file = $stmt->fetch();

$realBase = realpath('uploads');
$realFile = $file ? realpath($file['file_path']) : false;
if ($realBase === false || $realFile === false || strpos($realFile, $realBase . DIRECTORY_SEPARATOR) !== 0) {
    header('HTTP/1.1 404 Not Found');
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
        header('HTTP/1.1 403 Forbidden');
        exit;
    }
}

$content = file_get_contents($file['file_path']);
$decrypted = decrypt_file_data($content);

if ($decrypted === false) {
    header('HTTP/1.1 500 Internal Server Error');
    exit;
}

$fileSize = strlen($decrypted);
$start = 0;
$end = $fileSize - 1;

if (isset($_SERVER['HTTP_RANGE'])) {
    $c_start = $start;
    $c_end = $end;
    list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
    if (strpos($range, ',') !== false) {
        header('HTTP/1.1 416 Requested Range Not Satisfiable');
        header("Content-Range: bytes */$fileSize");
        exit;
    }
    if ($range == '-') {
        $c_start = $fileSize - substr($range, 1);
    } else {
        $rangeParts = explode('-', $range);
        $c_start = (int)$rangeParts[0];
        $c_end = (isset($rangeParts[1]) && is_numeric($rangeParts[1])) ? (int)$rangeParts[1] : $c_end;
    }
    $c_end = ($c_end > $end) ? $end : $c_end;
    if ($c_start > $c_end || $c_start > $fileSize - 1 || $c_end >= $fileSize) {
        header('HTTP/1.1 416 Requested Range Not Satisfiable');
        header("Content-Range: bytes */$fileSize");
        exit;
    }
    $start = $c_start;
    $end = $c_end;
    header('HTTP/1.1 206 Partial Content');
    header("Content-Range: bytes $start-$end/$fileSize");
} else {
    header('HTTP/1.1 200 OK');
}

$length = $end - $start + 1;

while (ob_get_level()) ob_end_clean();

header('Content-Type: audio/webm');
header('Accept-Ranges: bytes');
header('Content-Length: ' . $length);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$bufferSize = 8192;
$pos = $start;
while ($pos <= $end) {
    $bytesToRead = min($bufferSize, $end - $pos + 1);
    echo substr($decrypted, $pos, $bytesToRead);
    flush();
    $pos += $bytesToRead;
    if (connection_status() != 0) break;
}
exit;
?>
