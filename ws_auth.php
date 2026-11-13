<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || empty($_SESSION['username'])) {
    echo json_encode(['status' => 'error', 'message' => 'unauthorized']);
    exit;
}

ws_load_runtime_settings($pdo);

if (empty($ws_runtime['enabled'])) {
    echo json_encode(['status' => 'disabled']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$username = (string)$_SESSION['username'];

try {
    $stmt = $pdo->prepare("SELECT is_banned_until, device_token FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        echo json_encode(['status' => 'error', 'message' => 'unauthorized']);
        exit;
    }
    if ((int)$row['is_banned_until'] > time()) {
        echo json_encode(['status' => 'banned', 'message' => 'حساب شما مسدود است']);
        exit;
    }
    if ($username !== $admin_username && $row['device_token']) {
        $cookieToken = $_COOKIE['device_token'] ?? '';
        if ($cookieToken !== '' && !hash_equals((string)$row['device_token'], (string)$cookieToken)) {
            echo json_encode(['status' => 'error', 'message' => 'unauthorized']);
            exit;
        }
    }
    if ($username !== $admin_username && isset($_SESSION['session_token'])) {
        $stmtS = $pdo->prepare("SELECT id FROM user_sessions WHERE session_token = ? AND user_id = ?");
        $stmtS->execute([$_SESSION['session_token'], $userId]);
        if (!$stmtS->fetch()) {
            echo json_encode(['status' => 'error', 'message' => 'unauthorized']);
            exit;
        }
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'unauthorized']);
    exit;
}

$token = ws_issue_token($userId, $username);

echo json_encode([
    'status' => 'success',
    'token' => $token,
    'url' => $ws_runtime['url'],
    'reconnect' => (int)$ws_runtime['reconnect']
]);
