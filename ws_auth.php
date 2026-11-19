<?php
/**
 * VIRAX — realtime credential endpoint (v1.9.0)
 * =============================================================================
 *  Two jobs:
 *
 *    1. Hand an authenticated browser a short-lived socket token.
 *    2. Hand it a *room ticket* for one specific room — the piece the realtime
 *       server needs in order to know that this user is actually allowed to
 *       subscribe there.
 *
 *  Before 1.9.0 the socket server joined whatever room the client asked for.
 *  Any logged-in user could subscribe to a private room and read it live. The
 *  membership check cannot live in the socket server (no database there), so it
 *  lives here and is delivered as a signature the socket server can verify.
 */
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

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
$isAdmin = ($username === $admin_username) || is_admin_user($username, $pdo);

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

// ── Optional: mint a room ticket ─────────────────────────────────────────────
// Read the parameter explicitly rather than through $_REQUEST: a superglobal
// that merges GET, POST and cookies is hard to reason about and is a standing
// invitation to parameter confusion.
$roomIn = $_POST['room'] ?? $_GET['room'] ?? '';
$roomReq = is_string($roomIn) ? trim($roomIn) : '';
if ($roomReq !== '') {
    $roomReq = mb_substr($roomReq, 0, 100, 'UTF-8');

    $allowed = false;
    if ($isAdmin || $roomReq === 'گفتگوی عمومی' || $roomReq === 'public') {
        $allowed = true;
    } else {
        try {
            $stmtRoom = $pdo->prepare("SELECT type FROM rooms WHERE name = ? LIMIT 1");
            $stmtRoom->execute([$roomReq]);
            $type = $stmtRoom->fetchColumn();
            if ($type === 'public') {
                $allowed = true;
            } elseif ($type !== false) {
                $stmtInv = $pdo->prepare("SELECT id FROM room_invites WHERE user_id = ? AND room_name = ? LIMIT 1");
                $stmtInv->execute([$userId, $roomReq]);
                $allowed = (bool)$stmtInv->fetchColumn();
            }
        } catch (Exception $e) {
            $allowed = false;
        }
    }

    if (!$allowed) {
        vx_audit('ws.room_ticket_denied', ['user' => $username, 'room' => $roomReq]);
        echo json_encode(['status' => 'error', 'message' => 'room_forbidden']);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'room'   => $roomReq,
        'ticket' => ws_issue_room_ticket($userId, $roomReq, 'room', 120),
        'ttl'    => 120,
    ]);
    exit;
}

// ── Default: socket token ────────────────────────────────────────────────────
$token = ws_issue_token($userId, $username);

echo json_encode([
    'status' => 'success',
    'token' => $token,
    'url' => $ws_runtime['url'],
    'reconnect' => (int)$ws_runtime['reconnect'],
    'room_tickets' => true,
]);
