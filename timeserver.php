<?php
require_once 'config.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];

if (isset($_SESSION['session_token'])) {
    $stmtSess = $pdo->prepare("SELECT id FROM user_sessions WHERE session_token = ? AND user_id = ?");
    $stmtSess->execute([$_SESSION['session_token'], $userId]);
    if (!$stmtSess->fetch()) {
        echo json_encode(['status' => 'session_burned']);
        exit;
    }
}

$clientIp = get_client_ip();
$stmtIpBan = $pdo->prepare("SELECT id FROM banned_ips WHERE ip_address = ? LIMIT 1");
$stmtIpBan->execute([$clientIp]);
if ($stmtIpBan->fetch()) {
    echo json_encode(['status' => 'banned']);
    exit;
}

$stmt = $pdo->prepare("SELECT is_banned_until FROM users WHERE id = ?");
$stmt->execute([$userId]);
$row = $stmt->fetch();

if (!$row || $row['is_banned_until'] > time()) {
    echo json_encode(['status' => 'banned']);
    exit;
}

echo json_encode(['time' => time(), 'status' => 'success']);
?>
