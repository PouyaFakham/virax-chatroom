<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'عدم دسترسی']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$username = $_SESSION['username'] ?? '';
$isAdmin = ($username === $admin_username) || is_admin_user($username, $pdo);
ws_load_runtime_settings($pdo);

// ── Demo isolation ──────────────────────────────────────────────────────────
// A demo account is a real row in `users` (so the whole chat stack keeps
// working untouched), but everything it can reach is pinned to one room and
// one feature set. This is enforced here, on the server: the browser is never
// trusted to keep itself inside the sandbox.
$isDemoUser = veyrax_is_demo_user($pdo, $userId);
if ($isDemoUser) {
    $isAdmin = false;
    $username = $_SESSION['username'] ?? 'demo';
    veyrax_demo_sweep($pdo);
}

function chat_admin_usernames($pdo) {
    static $set = null;
    if ($set !== null) return $set;
    global $admin_username;
    $set = [];
    if (!empty($admin_username)) $set[mb_strtolower((string)$admin_username, 'UTF-8')] = true;
    try {
        foreach ($pdo->query("SELECT username FROM admin_users WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN) as $u) {
            $set[mb_strtolower((string)$u, 'UTF-8')] = true;
        }
    } catch (Exception $e) {}
    return $set;
}

function fetch_reactions_bulk($pdo, $msgIds, $userId) {
    $reactionsByMsg = [];
    $myByMsg = [];
    if (empty($msgIds)) return [$reactionsByMsg, $myByMsg];
    $admins = chat_admin_usernames($pdo);
    $ph = implode(',', array_fill(0, count($msgIds), '?'));
    $stmt = $pdo->prepare("
        SELECT mr.message_id, mr.reaction, mr.user_id, u.username
        FROM message_reactions mr
        JOIN users u ON mr.user_id = u.id
        WHERE mr.message_id IN ($ph)
        ORDER BY mr.created_at ASC
    ");
    $stmt->execute($msgIds);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $mid = (int)$r['message_id'];
        if (!isset($reactionsByMsg[$mid][$r['reaction']])) {
            $reactionsByMsg[$mid][$r['reaction']] = ['count' => 0, 'users' => [], 'admin' => false];
        }
        $reactionsByMsg[$mid][$r['reaction']]['count']++;
        $uname = (string)$r['username'];
        if ((int)$r['user_id'] === (int)$userId) {
            $reactionsByMsg[$mid][$r['reaction']]['users'][] = 'شما';
        } elseif (isset($admins[mb_strtolower($uname, 'UTF-8')])) {
            $reactionsByMsg[$mid][$r['reaction']]['admin'] = true;
        } else {
            $reactionsByMsg[$mid][$r['reaction']]['users'][] = $uname;
        }
        if ((int)$r['user_id'] === (int)$userId) $myByMsg[$mid] = $r['reaction'];
    }
    return [$reactionsByMsg, $myByMsg];
}

function chat_can_access_room($pdo, $userId, $isAdmin, $roomName) {
    if ($roomName === null || $roomName === '' || $roomName === 'null' || $roomName === 'public' || $roomName === 'گفتگوی عمومی') return true;
    // The sandbox room only exists as a message scope, not as a row in
    // `rooms`; the demo gate has already pinned the visitor to their own one.
    if (veyrax_is_demo_room($roomName)) return true;
    $stmt = $pdo->prepare("SELECT type FROM rooms WHERE name = ? LIMIT 1");
    $stmt->execute([$roomName]);
    $type = $stmt->fetchColumn();
    if ($type === false) return false;
    if ($isAdmin || $type === 'public') return true;
    $stmt = $pdo->prepare("SELECT id FROM room_invites WHERE user_id = ? AND room_name = ? LIMIT 1");
    $stmt->execute([$userId, $roomName]);
    return (bool)$stmt->fetchColumn();
}

function chat_can_access_message($pdo, $userId, $isAdmin, $msgId) {
    $stmt = $pdo->prepare("SELECT sender_id, receiver_id, room_name FROM messages WHERE id = ? LIMIT 1");
    $stmt->execute([$msgId]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) return false;
    if ($isAdmin) return true;
    if ((int)$m['sender_id'] === (int)$userId) return true;
    if (!empty($m['receiver_id'])) return ((int)$m['receiver_id'] === (int)$userId);
    return chat_can_access_room($pdo, $userId, $isAdmin, $m['room_name']);
}

function attach_reactions_bulk($pdo, &$messages, $userId) {
    $ids = [];
    foreach ($messages as $m) $ids[] = (int)$m['id'];
    list($reactMap, $myMap) = fetch_reactions_bulk($pdo, $ids, $userId);
    foreach ($messages as &$m) {
        $mid = (int)$m['id'];
        $m['reactions'] = $reactMap[$mid] ?? [];
        $m['my_reaction'] = $myMap[$mid] ?? null;
    }
    unset($m);
}

require_once __DIR__ . '/ai_lib.php';

try {
    $clientIpChat = get_client_ip();

    $stmtIpBan = $pdo->prepare("SELECT id FROM banned_ips WHERE ip_address = ?");
    $stmtIpBan->execute([$clientIpChat]);
    if ($stmtIpBan->fetch() && !$isAdmin) {
        session_unset(); session_destroy();
        echo json_encode(['status' => 'session_burned']);
        exit;
    }

    if (!$isAdmin) {
        $stmtSiteLock = $pdo->prepare("SELECT value FROM settings WHERE name = 'site_lock'");
        $stmtSiteLock->execute();
        if ($stmtSiteLock->fetchColumn() === '1') {
            echo json_encode(['status' => 'error', 'message' => 'سایت موقتاً در دسترس نیست']);
            exit;
        }
    }

    if (!$isAdmin && isset($_SESSION['session_token'])) {
        $sessionTok = $_SESSION['session_token'];
        $stmtSess = $pdo->prepare("SELECT id FROM user_sessions WHERE session_token = ? AND user_id = ?");
        $stmtSess->execute([$sessionTok, $userId]);
        if (!$stmtSess->fetch()) {
            session_unset(); session_destroy();
            echo json_encode(['status' => 'session_burned']);
            exit;
        }
        $pdo->prepare("UPDATE user_sessions SET last_activity = NOW() WHERE session_token = ?")->execute([$sessionTok]);
    }

    $stmt = $pdo->prepare("SELECT username, sticker, is_banned_until, muted_until, last_notif_id, notifications_enabled, device_token, ip_address, user_agent FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch();

    if (!$userData) {
        session_unset(); session_destroy();
        echo json_encode(['status' => 'error', 'message' => 'کاربر یافت نشد']);
        exit;
    }

    if (!$isAdmin) {
        $dbToken   = $userData['device_token'] ?? '';
        $dbIp      = $userData['ip_address']   ?? '';
        $dbUa      = $userData['user_agent']   ?? '';
        $cookieToken = $_COOKIE['device_token'] ?? '';
        $clientIp    = get_client_ip();
        $clientUa    = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

        $hasDeviceData = ($dbToken !== '' && $dbIp !== '' && $dbUa !== '');

        if ($hasDeviceData) {
            $tokenMatch = ($cookieToken !== '' && $dbToken !== '' && $cookieToken === $dbToken);
            $ipUaMatch  = ($dbIp === $clientIp && $dbUa === $clientUa);

            if (!$tokenMatch && !$ipUaMatch) {
                session_unset(); session_destroy();
                echo json_encode(['status' => 'session_burned']);
                exit;
            }

            if ($dbToken === null || $dbToken === '') {
                session_unset(); session_destroy();
                echo json_encode(['status' => 'session_burned']);
                exit;
            }
        }
    }

    if ($userData['is_banned_until'] > time()) {
        $rem = $userData['is_banned_until'] - time();
        if ($rem > (5 * 365 * 24 * 3600)) {
            $msg = 'حساب شما دائماً مسدود شده است';
        } else {
            $min = ceil($rem / 60);
            $msg = "شما به مدت $min دقیقه مسدود شده‌اید و پس از آن آزاد می‌شوید";
        }
        echo json_encode(['status' => 'banned', 'message' => $msg]);
        exit;
    }

/**
 * Cheap fingerprint of everything the client currently has on screen.
 *
 * A new message is visible through `id > after_id`, but an edit, a reaction or
 * an admin media removal changes no id — the row keeps its place and only its
 * contents change. The long-poll compares this string between ticks so those
 * events reach the other side in about half a second instead of waiting for the
 * slow safety poll.
 *
 * Format: rows|maxId|lastTouched|mediaDeletedCount|reactions|maxReactionId
 */
function veyrax_chat_rev(PDO $pdo, $userId, $room, $targetId) {
    try {
        if ((int)$targetId > 0) {
            $sql = "SELECT COUNT(*) c, COALESCE(MAX(id),0) mx,
                           COALESCE(UNIX_TIMESTAMP(MAX(touch_at)),0) tt,
                           COALESCE(SUM(media_deleted),0) sd
                    FROM messages
                    WHERE room_name IS NULL
                      AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))";
            $args = [(int)$userId, (int)$targetId, (int)$targetId, (int)$userId];
        } else {
            $sql = "SELECT COUNT(*) c, COALESCE(MAX(id),0) mx,
                           COALESCE(UNIX_TIMESTAMP(MAX(touch_at)),0) tt,
                           COALESCE(SUM(media_deleted),0) sd
                    FROM messages
                    WHERE room_name = ? AND receiver_id IS NULL";
            $args = [(string)$room];
        }
        $st = $pdo->prepare($sql);
        $st->execute($args);
        $m = $st->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'mx' => 0, 'tt' => 0, 'sd' => 0];
        $r = ['c' => 0, 'mx' => 0];
        try {
            $r = $pdo->query("SELECT COUNT(*) c, COALESCE(MAX(id),0) mx FROM message_reactions")->fetch(PDO::FETCH_ASSOC) ?: $r;
        } catch (Exception $e) {}
        return $m['c'] . '|' . $m['mx'] . '|' . $m['tt'] . '|' . $m['sd'] . '|' . $r['c'] . '|' . $r['mx'];
    } catch (Exception $e) {
        return '';
    }
}

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            echo json_encode(['status' => 'error', 'message' => 'نشست نامعتبر']);
            exit;
        }

        $action = $_POST['action'];

        if (in_array($action, ['send', 'delete_msg', 'edit_msg', 'delete_all_msgs'])) {
            if (!isset($_POST['bw_nonce']) || !isset($_SESSION['bw_nonce']) || $_POST['bw_nonce'] !== $_SESSION['bw_nonce']) {
                echo json_encode(['status' => 'error', 'message' => 'خطای امنیتی - لطفا صفحه را رفرش کنید']);
                exit;
            }
        }

        // ── Request governor ────────────────────────────────────────────────
        // Burst-friendly token bucket per user + per IP. Normal usage never
        // sees it; a runaway client (double timers, a retry loop, a script)
        // gets 429 + Retry-After instead of melting the PHP workers.
        require_once __DIR__ . '/api_guard.php';
        veyrax_guard_enforce($action, $userId, $clientIpChat);

        // ── Demo sandbox gate ────────────────────────────────────────────────
        // A demo visitor gets one room and a short list of actions. Everything
        // else is refused here — before a single query runs — so poking the
        // network tab returns a clean "not available in the demo" instead of
        // reaching a real endpoint. The room is also overwritten, not just
        // validated: the client can ask for whatever it likes, the server
        // still puts it in the demo room.
        if ($isDemoUser) {
            $demoAllowed = [
                'fetch', 'wait', 'send', 'delete_msg', 'edit_msg',
                'react', 'get_reactions', 'update_typing', 'set_offline',
                'welcome_seen', 'toggle_notifications', 'save_ui_prefs',
                'my_msg_count', 'fetch_history', 'phone_auth_check',
            ];
            if (!in_array($action, $demoAllowed, true)) {
                echo json_encode([
                    'status'  => 'error',
                    'demo'    => true,
                    'message' => 'این قابلیت در حالت نمایشی در دسترس نیست',
                ]);
                exit;
            }

            // One room per visitor, no direct messages, no voice notes.
            $demoRoom = veyrax_demo_room_for($userId);
            $_POST['room'] = $demoRoom;
            unset($_POST['target_user'], $_POST['is_voice'], $_POST['receiver_id']);

            // Anything that names a message must name one from this visitor's room.
            $demoRefKeys = ['msg_id', 'reply_to'];
            if ($action === 'delete_msg' || $action === 'edit_msg') $demoRefKeys[] = 'id';
            foreach ($demoRefKeys as $demoRefKey) {
                if (!isset($_POST[$demoRefKey])) continue;
                $demoMsgId = (int)$_POST[$demoRefKey];
                $demoMsgRoom = '';
                if ($demoMsgId > 0) {
                    $dStmt = $pdo->prepare("SELECT room_name FROM messages WHERE id = ? LIMIT 1");
                    $dStmt->execute([$demoMsgId]);
                    $demoMsgRoom = (string)$dStmt->fetchColumn();
                }
                if ($demoMsgRoom !== $demoRoom) {
                    if ($demoRefKey === 'reply_to') {
                        // A stale reply target must not kill the message itself.
                        unset($_POST['reply_to']);
                        continue;
                    }
                    echo json_encode([
                        'status'  => 'error',
                        'demo'    => true,
                        'message' => 'این پیام در دسترس نیست',
                    ]);
                    exit;
                }
            }

            if ($action === 'send') {
                // Media and voice are off in the demo — text only.
                if (!empty($_FILES['file']) && (int)($_FILES['file']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
                    echo json_encode([
                        'status'  => 'error',
                        'demo'    => true,
                        'message' => 'ارسال رسانه در حالت نمایشی غیرفعال است',
                    ]);
                    exit;
                }

                // One deliberate test message at a time, and a hard ceiling so
                // a scripted visitor cannot fill the room or the database.
                $demoNow = microtime(true);
                $demoLast = (float)($_SESSION['demo_last_send'] ?? 0);
                if ($demoLast > 0 && ($demoNow - $demoLast) < VEYRAX_DEMO_GAP) {
                    echo json_encode([
                        'status'  => 'error',
                        'demo'    => true,
                        'message' => 'کمی آرام‌تر… یک لحظه صبر کنید',
                    ]);
                    exit;
                }
                $demoCount = (int)($_SESSION['demo_msg_count'] ?? 0);
                if ($demoCount >= VEYRAX_DEMO_MAX_MSGS) {
                    echo json_encode([
                        'status'  => 'error',
                        'demo'    => true,
                        'limit'   => true,
                        'message' => 'سقف پیام‌های آزمایشی این نشست پر شد؛ برای شروع دوباره از حساب خارج شوید',
                    ]);
                    exit;
                }
                $demoText = (string)($_POST['message'] ?? '');
                if (mb_strlen($demoText, 'UTF-8') > 320) {
                    $demoText = mb_substr($demoText, 0, 320, 'UTF-8');
                }
                $_POST['message'] = $demoText;
                $_SESSION['demo_last_send'] = $demoNow;
                $_SESSION['demo_msg_count'] = $demoCount + 1;
            }
        }

        // ── Release the PHP session lock as early as possible ────────────────
        // PHP serialises every request that shares one session file. With a
        // poll every few seconds plus send/typing/notification calls, the send
        // request was regularly queued behind a poll — that was the "delay"
        // users felt. Read-only actions never touch $_SESSION after this point,
        // so closing the session lets them run fully in parallel.
        $readOnlyActions = [
            'fetch', 'search_messages', 'get_reactions', 'get_profile', 'update_typing',
            'set_offline', 'toggle_notifications', 'phone_auth_check', 'fetch_history', 'wait',
        ];
        if (in_array($action, $readOnlyActions, true) && session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        if ($action === 'get_profile') {
            global $admin_username;
            // ensure created_at column exists
            try {
                $chkCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'created_at'");
                if ($chkCol->rowCount() == 0) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
                }
            } catch (Exception $e) {}

            $stmt = $pdo->prepare("SELECT security_code, phone_number, created_at FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $uRow = $stmt->fetch();

            // Check auth_password (users column or the user_auth fallback table)
            $hasAuth = veyrax_get_auth_hash($pdo, $userId) !== '';

            $joinDateStr = 'کاربر قدیمی';
            if ($uRow && !empty($uRow['created_at']) && $uRow['created_at'] !== '0000-00-00 00:00:00') {
                if (function_exists('jdate')) {
                    $ts = strtotime($uRow['created_at']);
                    $joinDateStr = jdate('Y/m/d', $ts);
                } else {
                    $joinDateStr = date('Y/m/d', strtotime($uRow['created_at']));
                }
            }

            $stmtRoom = $pdo->prepare("SELECT name FROM rooms WHERE created_by = ? AND name != 'گفتگوی عمومی' LIMIT 1");
            $stmtRoom->execute([$userId]);
            $myRoom = $stmtRoom->fetchColumn();

            echo json_encode([
                'status'        => 'success',
                'join_date'     => $joinDateStr,
                'security_code' => $uRow ? $uRow['security_code'] : null,
                'phone_number'  => $uRow ? $uRow['phone_number'] : null,
                'has_auth'      => $hasAuth,
                'created_room'  => $myRoom ?: null
            ]);
            exit;
        }

        if ($action === 'delete_my_room') {
            $roomId = (int)($_POST['room_id'] ?? 0);
            if (!$roomId) {
                echo json_encode(['status' => 'error', 'message' => 'اتاق نامعتبر']);
                exit;
            }
            $stmtChk = $pdo->prepare("SELECT name, created_by FROM rooms WHERE id = ? AND name != 'گفتگوی عمومی'");
            $stmtChk->execute([$roomId]);
            $room = $stmtChk->fetch();
            if (!$room) {
                echo json_encode(['status' => 'error', 'message' => 'اتاق یافت نشد']);
                exit;
            }
            global $admin_username;
            $isAdminUser = (isset($_SESSION['username']) && $_SESSION['username'] === $admin_username);
            if (!$isAdminUser && $room['created_by'] != $userId) {
                echo json_encode(['status' => 'error', 'message' => 'شما سازنده این اتاق نیستید']);
                exit;
            }
            $roomName = $room['name'];
            $stmtFileTokens = $pdo->prepare("SELECT file_path FROM messages WHERE room_name = ? AND file_path IS NOT NULL");
            $stmtFileTokens->execute([$roomName]);
            while ($fr = $stmtFileTokens->fetch()) {
                if (file_exists($fr['file_path'])) @unlink($fr['file_path']);
            }
            $pdo->prepare("DELETE FROM messages WHERE room_name = ?")->execute([$roomName]);
            $pdo->prepare("DELETE FROM room_invites WHERE room_name = ?")->execute([$roomName]);
            $pdo->prepare("DELETE FROM pinned_messages WHERE room_name = ?")->execute([$roomName]);
            $pdo->prepare("DELETE FROM rooms WHERE id = ?")->execute([$roomId]);
            echo json_encode(['status' => 'success', 'message' => 'اتاق حذف شد']);
            exit;
        }

        if ($action === 'reset_security_code') {
            $newCode = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
            $pdo->prepare("UPDATE users SET security_code = ? WHERE id = ?")->execute([$newCode, $userId]);
            echo json_encode(['status' => 'success', 'new_code' => $newCode]);
            exit;
        }

        if ($action === 'create_private_room') {
            $roomName = trim($_POST['room_name'] ?? '');

            if (empty($roomName) || mb_strlen($roomName, 'UTF-8') < 4 || mb_strlen($roomName, 'UTF-8') > 10) {
                echo json_encode(['status' => 'error', 'message' => 'نام اتاق باید بین ۴ تا ۱۰ کاراکتر باشد']);
                exit;
            }

            if (!preg_match('/^[a-zA-Z0-9\x{0600}-\x{06FF}\s\x{200C}]+$/u', $roomName)) {
                echo json_encode(['status' => 'error', 'message' => 'نام اتاق فقط می‌تواند شامل حروف فارسی، انگلیسی و اعداد باشد']);
                exit;
            }

            $roomName = htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8');

            $stmtChk = $pdo->prepare("SELECT id FROM rooms WHERE created_by = ?");
            $stmtChk->execute([$userId]);
            if ($stmtChk->rowCount() > 0) {
                echo json_encode(['status' => 'error', 'message' => 'شما قبلاً یک اتاق ساخته‌اید']);
                exit;
            }

            $stmtName = $pdo->prepare("SELECT id FROM rooms WHERE name = ?");
            $stmtName->execute([$roomName]);
            if ($stmtName->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'این نام اتاق تکراری است']);
                exit;
            }

            $inviteCode = substr(bin2hex(random_bytes(5)), 0, 8);
            $stmtInsert = $pdo->prepare("INSERT INTO rooms (name, type, room_kind, created_by, invite_code) VALUES (?, 'private', 'room', ?, ?)");
            $stmtInsert->execute([$roomName, $userId, $inviteCode]);

            $stmtSelfInvite = $pdo->prepare("INSERT INTO room_invites (user_id, room_name, invited_by) VALUES (?, ?, ?)");
            $stmtSelfInvite->execute([$userId, $roomName, $userId]);

            echo json_encode(['status' => 'success', 'message' => 'اتاق خصوصی با موفقیت ساخته شد', 'invite_code' => $inviteCode]);
            exit;
        }

        if ($action === 'create_channel') {
            $roomName = trim($_POST['room_name'] ?? '');
            $channelType = trim($_POST['channel_type'] ?? 'public');

            if (empty($roomName) || mb_strlen($roomName, 'UTF-8') < 4 || mb_strlen($roomName, 'UTF-8') > 10) {
                echo json_encode(['status' => 'error', 'message' => 'نام کانال باید بین ۴ تا ۱۰ کاراکتر باشد']);
                exit;
            }

            if (!preg_match('/^[a-zA-Z0-9\x{0600}-\x{06FF}\s\x{200C}]+$/u', $roomName)) {
                echo json_encode(['status' => 'error', 'message' => 'نام کانال فقط می‌تواند شامل حروف فارسی، انگلیسی و اعداد باشد']);
                exit;
            }

            $roomName = htmlspecialchars($roomName, ENT_QUOTES, 'UTF-8');

            $stmtChk = $pdo->prepare("SELECT id FROM rooms WHERE created_by = ?");
            $stmtChk->execute([$userId]);
            if ($stmtChk->rowCount() > 0) {
                echo json_encode(['status' => 'error', 'message' => 'شما قبلاً یک اتاق یا کانال ساخته‌اید']);
                exit;
            }

            $stmtName = $pdo->prepare("SELECT id FROM rooms WHERE name = ?");
            $stmtName->execute([$roomName]);
            if ($stmtName->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'این نام تکراری است']);
                exit;
            }

            if ($channelType === 'public') {
                $stmtInsert = $pdo->prepare("INSERT INTO rooms (name, type, room_kind, created_by, invite_code) VALUES (?, 'public', 'channel', ?, NULL)");
                $stmtInsert->execute([$roomName, $userId]);
                echo json_encode(['status' => 'success', 'message' => 'کانال عمومی با موفقیت ساخته شد', 'invite_code' => null]);
            } else {
                $inviteCode = substr(bin2hex(random_bytes(5)), 0, 8);
                $stmtInsert = $pdo->prepare("INSERT INTO rooms (name, type, room_kind, created_by, invite_code) VALUES (?, 'private', 'channel', ?, ?)");
                $stmtInsert->execute([$roomName, $userId, $inviteCode]);
                $stmtSelfInvite = $pdo->prepare("INSERT INTO room_invites (user_id, room_name, invited_by) VALUES (?, ?, ?)");
                $stmtSelfInvite->execute([$userId, $roomName, $userId]);
                echo json_encode(['status' => 'success', 'message' => 'کانال خصوصی با موفقیت ساخته شد', 'invite_code' => $inviteCode]);
            }
            exit;
        }

        if ($action === 'search_invite_code') {
            $code = trim($_POST['code'] ?? '');
            $stmt = $pdo->prepare("SELECT id, name FROM rooms WHERE invite_code = ?");
            $stmt->execute([$code]);
            $room = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($room) {
                echo json_encode(['status' => 'success', 'room' => $room]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'اتاقی با این کد یافت نشد']);
            }
            exit;
        }

        if ($action === 'join_via_code') {
            $code = trim($_POST['code'] ?? '');
            $stmt = $pdo->prepare("SELECT name, id FROM rooms WHERE invite_code = ?");
            $stmt->execute([$code]);
            $roomInfo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$roomInfo) {
                echo json_encode(['status' => 'error', 'message' => 'کد نامعتبر است']);
                exit;
            }
            $roomName = $roomInfo['name'];
            $roomId = $roomInfo['id'];

            $stmtChk = $pdo->prepare("SELECT id FROM room_invites WHERE user_id = ? AND room_name = ?");
            $stmtChk->execute([$userId, $roomName]);
            if ($stmtChk->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'شما قبلاً عضو این اتاق هستید']);
                exit;
            }

            $stmtJoin = $pdo->prepare("INSERT INTO room_invites (user_id, room_name, invited_by) VALUES (?, ?, ?)");
            $stmtJoin->execute([$userId, $roomName, $userId]);

            $sysMsg = "*** کاربر $username به اتاق پیوست ***";
            $encMsg = encrypt_data($sysMsg);
            $pdo->prepare("INSERT INTO messages (sender_id, room_name, message, msg_type) VALUES (?, ?, ?, 'text')")
                ->execute([$userId, $roomName, $encMsg]);

            echo json_encode(['status' => 'success', 'message' => 'شما با موفقیت به اتاق پیوستید', 'room_name' => $roomName, 'room_id' => $roomId]);
            exit;
        }

        if ($action === 'toggle_notifications') {
            $state = (int)$_POST['state'];
            $pdo->prepare("UPDATE users SET notifications_enabled = ? WHERE id = ?")->execute([$state, $userId]);
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($action === 'set_offline') {
            $pdo->prepare("UPDATE users SET is_online = 0 WHERE id = ?")->execute([$userId]);
            exit;
        }

        if ($action === 'update_typing') {
            $targetUserTyping = (isset($_POST['target_user']) && is_numeric($_POST['target_user']) && (int)$_POST['target_user'] > 0) ? (int)$_POST['target_user'] : null;
            $context = $targetUserTyping ? 'private_' . $targetUserTyping : ($_POST['room'] ?? 'گفتگوی عمومی');
            if ($context === 'public' || $context === 'null' || $context === '') $context = 'گفتگوی عمومی';
            if (!$targetUserTyping && !chat_can_access_room($pdo, $userId, $isAdmin, $context)) {
                echo json_encode(['status' => 'success']);
                exit;
            }
            $typingClear = !empty($_POST['clear']);
            if ($typingClear) {
                $pdo->prepare("UPDATE users SET typing_context = NULL, typing_time = 0 WHERE id = ?")->execute([$userId]);
            } else {
                $pdo->prepare("UPDATE users SET typing_context = ?, typing_time = ? WHERE id = ?")->execute([$context, time(), $userId]);
            }

            ws_push_async($typingClear ? 'typing_stop' : 'typing', [
                'user_id' => $userId,
                'username' => $username,
                'room' => $targetUserTyping ? null : ($context ?: null),
                'target_user' => $targetUserTyping,
                'targets' => $targetUserTyping ? [$targetUserTyping] : [],
                'rooms' => $targetUserTyping ? [] : [['kind' => 'room', 'name' => $context]]
            ]);

            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($action === 'my_msg_count') {
            $cntRoom = 0;
            $cntAll = 0;
            $cntDm = 0;
            $cntRoomName = isset($_POST['room']) ? (string)$_POST['room'] : 'گفتگوی عمومی';
            if ($cntRoomName === 'public' || $cntRoomName === 'null' || $cntRoomName === '') { $cntRoomName = 'گفتگوی عمومی'; }
            try {
                $c1 = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE sender_id = ? AND room_name = ? AND receiver_id IS NULL");
                $c1->execute([$userId, $cntRoomName]);
                $cntRoom = (int)$c1->fetchColumn();

                $c2 = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE sender_id = ?");
                $c2->execute([$userId]);
                $cntAll = (int)$c2->fetchColumn();

                $c3 = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE sender_id = ? AND room_name IS NULL");
                $c3->execute([$userId]);
                $cntDm = (int)$c3->fetchColumn();
            } catch (Exception $e) {}
            echo json_encode([
                'status' => 'success',
                'room' => $cntRoom,
                'dm' => $cntDm,
                'all' => $cntAll,
                'room_name' => $cntRoomName,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'delete_all_msgs') {
            
            $stmtDelLock = $pdo->prepare("SELECT value FROM settings WHERE name = 'delete_lock'");
            $stmtDelLock->execute();
            if ($stmtDelLock->fetchColumn() === '1') {
                echo json_encode(['status' => 'error', 'message' => 'قابلیت حذف پیام‌ها توسط مدیر غیرفعال شده است', 'delete_lock' => true]);
                exit;
            }

            $delScope = isset($_POST['scope']) ? (string)$_POST['scope'] : 'all';
            if (!in_array($delScope, ['room', 'dm', 'all'], true)) $delScope = 'all';
            $delRoom = isset($_POST['room']) ? (string)$_POST['room'] : 'گفتگوی عمومی';
            if ($delRoom === 'public' || $delRoom === 'null' || $delRoom === '') { $delRoom = 'گفتگوی عمومی'; }

            try {
                if ($delScope === 'room') {
                    $stmtWhere = "sender_id = ? AND room_name = ? AND receiver_id IS NULL";
                    $stmtArgs = [$userId, $delRoom];
                } elseif ($delScope === 'dm') {
                    $stmtWhere = "sender_id = ? AND room_name IS NULL";
                    $stmtArgs = [$userId];
                } else {
                    $stmtWhere = "sender_id = ?";
                    $stmtArgs = [$userId];
                }

                $stmtFiles = $pdo->prepare("SELECT file_path FROM messages WHERE " . $stmtWhere . " AND file_path IS NOT NULL");
                $stmtFiles->execute($stmtArgs);
                $filesToDelete = $stmtFiles->fetchAll(PDO::FETCH_COLUMN);

                $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE " . $stmtWhere);
                $stmtCnt->execute($stmtArgs);
                $deletedCount = (int)$stmtCnt->fetchColumn();

                $pdo->beginTransaction();

                $pdo->prepare("UPDATE messages SET reply_to_id = NULL WHERE reply_to_id IN (SELECT id FROM (SELECT id FROM messages WHERE " . $stmtWhere . ") AS sub)")
                    ->execute($stmtArgs);

                $stmtDel = $pdo->prepare("DELETE FROM messages WHERE " . $stmtWhere);
                $stmtDel->execute($stmtArgs);
                $pdo->prepare("DELETE FROM message_reactions WHERE message_id NOT IN (SELECT id FROM messages)")->execute();
                $pdo->prepare("DELETE FROM pinned_messages WHERE msg_id NOT IN (SELECT id FROM messages)")->execute();
                $pdo->commit();

                foreach ($filesToDelete as $path) {
                    if ($path && file_exists($path)) {
                        @unlink($path);
                    }
                }
                ws_push_async('message_updated', ['kind' => 'bulk_delete', 'scope' => $delScope, 'force' => true]);
                echo json_encode([
                    'status' => 'success',
                    'deleted' => $deletedCount,
                    'scope' => $delScope,
                    'message' => $deletedCount > 0 ? ('تعداد ' . $deletedCount . ' پیام حذف شد') : 'پیامی برای حذف پیدا نشد',
                ], JSON_UNESCAPED_UNICODE);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                echo json_encode(['status' => 'error', 'message' => 'خطا در حذف: ' . $e->getMessage()]);
            }
            exit;
        }

        if ($action === 'delete_msg') {
            
            $stmtDelLock = $pdo->prepare("SELECT value FROM settings WHERE name = 'delete_lock'");
            $stmtDelLock->execute();
            if ($stmtDelLock->fetchColumn() === '1') {
                echo json_encode(['status' => 'error', 'message' => 'قابلیت حذف پیام‌ها توسط مدیر غیرفعال شده است', 'delete_lock' => true]);
                exit;
            }

            $msgId = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT sender_id, receiver_id, room_name, file_path FROM messages WHERE id = ?");
            $stmt->execute([$msgId]);
            $msg = $stmt->fetch();

            if ($msg && ($msg['sender_id'] == $userId || $username === $admin_username)) {
                veyrax_note_deletion($msgId, $userId, (int)($msg['receiver_id'] ?? 0), (string)($msg['room_name'] ?? ''));
                $pdo->prepare("UPDATE messages SET reply_to_id = NULL WHERE reply_to_id = ?")->execute([$msgId]);
                $pdo->prepare("DELETE FROM messages WHERE id = ?")->execute([$msgId]);
                $pdo->prepare("DELETE FROM message_reactions WHERE message_id = ?")->execute([$msgId]);
                $pdo->prepare("DELETE FROM pinned_messages WHERE msg_id = ?")->execute([$msgId]);
                if ($msg['file_path'] && file_exists($msg['file_path'])) {
                    @unlink($msg['file_path']);
                }
                ws_push_async('message_updated', ['kind' => 'delete', 'message_id' => $msgId, 'force' => true]);
                echo json_encode(['status' => 'success', 'message' => 'پیام با موفقیت حذف شد']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'عدم دسترسی']);
            }
            exit;
        }

        if ($action === 'edit_msg') {
            $msgId = (int)($_POST['id'] ?? 0);
            $newText = trim($_POST['text'] ?? '');
            // Same rule as a new message: strip markup, store raw, escape once
            // on the way out.
            $newText = strip_tags($newText);
            if (mb_strlen($newText) > 2000) {
                echo json_encode(['status' => 'error', 'message' => 'متن طولانی است']);
                exit;
            }
            $stmt = $pdo->prepare("SELECT sender_id, receiver_id, room_name, msg_type FROM messages WHERE id = ?");
            $stmt->execute([$msgId]);
            $msg = $stmt->fetch();
            if ($msg && $msg['sender_id'] == $userId && $msg['msg_type'] === 'text') {
                $encText = encrypt_data($newText);
                $pdo->prepare("UPDATE messages SET message = ?, is_edited = 1, touch_at = NOW() WHERE id = ?")->execute([$encText, $msgId]);
                // Route the event, otherwise `dispatch()` has nowhere to send it
                // and the other side only sees the edit after a reload.
                $editPush = ['kind' => 'edit', 'message_id' => $msgId, 'force' => true];
                if (!empty($msg['room_name'])) {
                    $editPush['rooms'] = [['kind' => 'room', 'name' => (string)$msg['room_name']]];
                    $editPush['targets'] = [];
                } else {
                    $editPush['rooms'] = [];
                    $editPush['targets'] = array_values(array_unique(array_filter([
                        (int)$msg['sender_id'], (int)$msg['receiver_id'],
                    ])));
                }
                ws_push_async('message_updated', $editPush);
                echo json_encode(['status' => 'success', 'message' => 'پیام با موفقیت ویرایش شد']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'غیرقابل ویرایش']);
            }
            exit;
        }

        if ($action === 'pin_message') {
            $msgId = (int)($_POST['msg_id'] ?? 0);
            $roomName = isset($_POST['room']) && $_POST['room'] !== 'null' ? trim($_POST['room']) : null;
            $targetUserId = (isset($_POST['target_user']) && is_numeric($_POST['target_user']) && (int)$_POST['target_user'] > 0) ? (int)$_POST['target_user'] : null;

            if ($targetUserId) {
                $stmtChkPriv = $pdo->prepare("SELECT id FROM messages WHERE id = ? AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))");
                $stmtChkPriv->execute([$msgId, $userId, $targetUserId, $targetUserId, $userId]);
                if (!$stmtChkPriv->fetch()) {
                    echo json_encode(['status' => 'error', 'message' => 'عدم دسترسی به این پیام']);
                    exit;
                }
                $stmtCnt = $pdo->prepare("SELECT COUNT(*) FROM pinned_messages WHERE room_name IS NULL AND (receiver_id = ? OR receiver_id = ?)");
                $stmtCnt->execute([$userId, $targetUserId]);
                if ((int)$stmtCnt->fetchColumn() >= 3) {
                    echo json_encode(['status' => 'error', 'message' => 'حداکثر ۳ پیام قابل پین است']);
                    exit;
                }
            } elseif ($roomName) {
                $isRoomOwner = false;
                $stmtOwner = $pdo->prepare("SELECT created_by FROM rooms WHERE name = ?");
                $stmtOwner->execute([$roomName]);
                $roomRow = $stmtOwner->fetch();
                if ($roomRow && $roomRow['created_by'] == $userId) $isRoomOwner = true;
                if (!$isAdmin && !$isRoomOwner) {
                    echo json_encode(['status' => 'error', 'message' => 'عدم دسترسی']);
                    exit;
                }
            } else {
                if (!$isAdmin) {
                    echo json_encode(['status' => 'error', 'message' => 'عدم دسترسی']);
                    exit;
                }
            }

            if ($targetUserId) {
                $stmtDup = $pdo->prepare("SELECT id FROM pinned_messages WHERE msg_id = ? AND room_name IS NULL AND (receiver_id = ? OR receiver_id = ?)");
                $stmtDup->execute([$msgId, $userId, $targetUserId]);
            } elseif ($roomName) {
                $stmtDup = $pdo->prepare("SELECT id FROM pinned_messages WHERE msg_id = ? AND room_name = ?");
                $stmtDup->execute([$msgId, $roomName]);
            } else {
                $stmtDup = $pdo->prepare("SELECT id FROM pinned_messages WHERE msg_id = ? AND room_name = 'گفتگوی عمومی'");
                $stmtDup->execute([$msgId]);
            }
            if ($stmtDup->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'این پیام قبلاً پین شده است']);
                exit;
            }

            $stmtMsg = $pdo->prepare("SELECT m.*, u.username as sender_name FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.id = ?");
            $stmtMsg->execute([$msgId]);
            $msgData = $stmtMsg->fetch();
            if (!$msgData) {
                echo json_encode(['status' => 'error', 'message' => 'پیام یافت نشد']);
                exit;
            }

            if (!$targetUserId) {
                $expectedRoom = $roomName ?: 'گفتگوی عمومی';
                if ((string)($msgData['room_name'] ?? '') !== $expectedRoom || !empty($msgData['receiver_id'])) {
                    echo json_encode(['status' => 'error', 'message' => 'عدم دسترسی به این پیام']);
                    exit;
                }
            }

            $decryptedMsg = null;
            if (!empty($msgData['message'])) {
                try { $decryptedMsg = decrypt_data($msgData['message']); } catch (Exception $e) {}
            }

            $insertRoomName = $targetUserId ? null : ($roomName ?: 'گفتگوی عمومی');
            $insertReceiverId = $targetUserId ?: null;
            $pinSenderName = ($msgData['sender_name'] === $admin_username) ? $admin_display_name : $msgData['sender_name'];
            $pdo->prepare("INSERT INTO pinned_messages (msg_id, room_name, receiver_id, pinned_by, sender_name, msg_type, message, file_name, file_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$msgId, $insertRoomName, $insertReceiverId, $userId, $pinSenderName, $msgData['msg_type'], veyrax_pin_text_encode($decryptedMsg), $msgData['file_name'], $msgData['file_token']]);

            ws_push_async('message_updated', ['kind' => 'pin', 'message_id' => $msgId, 'force' => true]);
            echo json_encode(['status' => 'success', 'message' => 'پیام پین شد']);
            exit;
        }

        if ($action === 'unpin_message') {
            $pinRecordId = (int)($_POST['pin_record_id'] ?? 0);
            $msgId = (int)($_POST['msg_id'] ?? 0);
            $targetUserId = (isset($_POST['target_user']) && is_numeric($_POST['target_user']) && (int)$_POST['target_user'] > 0) ? (int)$_POST['target_user'] : null;
            $roomName = isset($_POST['room']) && $_POST['room'] !== 'null' ? trim($_POST['room']) : null;
            $canUnpin = $isAdmin;
            if (!$canUnpin) {
                if ($targetUserId) {
                    $canUnpin = true;
                } elseif ($roomName) {
                    $stmtO = $pdo->prepare("SELECT created_by FROM rooms WHERE name = ?");
                    $stmtO->execute([$roomName]);
                    $ro = $stmtO->fetch();
                    if ($ro && $ro['created_by'] == $userId) $canUnpin = true;
                }
            }
            if (!$canUnpin) { echo json_encode(['status' => 'error', 'message' => 'عدم دسترسی']); exit; }
            if ($pinRecordId <= 0 && $msgId <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'شناسه نامعتبر']);
                exit;
            }
            if ($targetUserId) {
                $scopeSql = " AND room_name IS NULL AND (receiver_id = ? OR receiver_id = ?)";
                $scopeParams = [$targetUserId, $userId];
            } elseif ($roomName) {
                $scopeSql = " AND room_name = ?";
                $scopeParams = [$roomName];
            } else {
                $scopeSql = " AND room_name = 'گفتگوی عمومی'";
                $scopeParams = [];
            }
            $unpinned = 0;
            if ($pinRecordId > 0) {
                $stDel = $pdo->prepare("DELETE FROM pinned_messages WHERE id = ?" . $scopeSql);
                $stDel->execute(array_merge([$pinRecordId], $scopeParams));
                $unpinned = $stDel->rowCount();
            }
            if (!$unpinned && $msgId > 0) {
                $stDel = $pdo->prepare("DELETE FROM pinned_messages WHERE msg_id = ?" . $scopeSql);
                $stDel->execute(array_merge([$msgId], $scopeParams));
                $unpinned = $stDel->rowCount();
            }
            if (!$unpinned) {
                echo json_encode(['status' => 'error', 'message' => 'عدم دسترسی']);
                exit;
            }
            echo json_encode(['status' => 'success', 'message' => 'پین حذف شد']);
            exit;
        }

        if ($action === 'unpin_all') {
            $roomName = isset($_POST['room']) && $_POST['room'] !== 'null' ? trim($_POST['room']) : null;
            $targetUserId = (isset($_POST['target_user']) && is_numeric($_POST['target_user']) && (int)$_POST['target_user'] > 0) ? (int)$_POST['target_user'] : null;
            $canUnpin = $isAdmin;
            if (!$canUnpin && $targetUserId) $canUnpin = true;
            if (!$canUnpin && $roomName) {
                $stmtO = $pdo->prepare("SELECT created_by FROM rooms WHERE name = ?");
                $stmtO->execute([$roomName]);
                $ro = $stmtO->fetch();
                if ($ro && $ro['created_by'] == $userId) $canUnpin = true;
            }
            if (!$canUnpin) { echo json_encode(['status' => 'error', 'message' => 'عدم دسترسی']); exit; }
            if ($targetUserId) {
                $pdo->prepare("DELETE FROM pinned_messages WHERE room_name IS NULL AND (receiver_id = ? OR receiver_id = ?)")->execute([$targetUserId, $userId]);
            } elseif ($roomName) {
                $pdo->prepare("DELETE FROM pinned_messages WHERE room_name = ?")->execute([$roomName]);
            } else {
                $pdo->prepare("DELETE FROM pinned_messages WHERE room_name = 'گفتگوی عمومی'")->execute();
            }
            echo json_encode(['status' => 'success', 'message' => 'همه پین‌ها حذف شدند']);
            exit;
        }

        if ($action === 'save_ui_prefs') {
            $uiColor = strtolower(trim((string)($_POST['theme_color'] ?? '')));
            $uiScale = veyrax_fs_clamp($_POST['font_scale'] ?? 1);
            if (!preg_match('/^#[0-9a-f]{6}$/', $uiColor)) {
                echo json_encode(['status' => 'error', 'message' => 'رنگ نامعتبر']);
                exit;
            }
            $_SESSION['ui_theme'] = $uiColor;
            $_SESSION['ui_fs'] = $uiScale;
            try {
                $pdo->prepare("UPDATE users SET theme_color = ? WHERE id = ?")->execute([$uiColor, $userId]);
            } catch (Exception $e) {}
            try {
                $pdo->prepare("UPDATE users SET font_scale = ? WHERE id = ?")->execute([$uiScale, $userId]);
            } catch (Exception $e) {}
            echo json_encode(['status' => 'success', 'theme_color' => $uiColor, 'font_scale' => $uiScale]);
            exit;
        }

        if ($action === 'welcome_seen') {
            // Per-account, so the welcome sheet never reappears on another
            // device or after clearing site data.
            try {
                $pdo->prepare("UPDATE users SET welcome_seen = 1 WHERE id = ?")->execute([$userId]);
            } catch (Exception $e) {}
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($action === 'list_users') {
            // Paged + searchable directory for the "all users" sheet. The
            // sidebar only ever shows the first screenful, so the full list is
            // fetched on demand instead of shipping every account up front.
            $per = (int)($_POST['per'] ?? 20);
            if ($per < 10 || $per > 50) $per = 20;
            $page = max(1, (int)($_POST['page'] ?? 1));
            $q = mb_substr(trim((string)($_POST['q'] ?? '')), 0, 40, 'UTF-8');

            $where = [
                "u.is_demo = 0",
                "u.is_banned_until < UNIX_TIMESTAMP()",
                "(u.last_activity > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                  OR EXISTS (SELECT 1 FROM messages m
                             WHERE (m.sender_id = u.id AND m.receiver_id = ?)
                                OR (m.receiver_id = u.id AND m.sender_id = ?)))",
            ];
            $args = [$userId, $userId];
            if ($q !== '') {
                $where[] = "u.username LIKE ?";
                $args[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            }
            $whereSql = 'WHERE ' . implode(' AND ', $where);

            $cnt = $pdo->prepare("SELECT COUNT(*) FROM users u $whereSql");
            $cnt->execute($args);
            $total = (int)$cnt->fetchColumn();
            $pages = max(1, (int)ceil($total / $per));
            if ($page > $pages) $page = $pages;
            $offset = ($page - 1) * $per;

            $st = $pdo->prepare(
                "SELECT u.id, u.username, u.sticker, u.is_online, u.last_activity,
                        (u.is_online = 1 AND u.last_activity > DATE_SUB(NOW(), INTERVAL 10 MINUTE)) AS online_now
                 FROM users u
                 $whereSql
                 ORDER BY online_now DESC, u.last_activity DESC, u.id ASC
                 LIMIT $per OFFSET $offset"
            );
            $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            $unreadStmt = $pdo->prepare("SELECT sender_id, COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0 GROUP BY sender_id");
            $unreadStmt->execute([$userId]);
            $unread = $unreadStmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $list = [];
            foreach ($rows as $r) {
                $isAdminRow = ($r['username'] === $admin_username);
                $list[] = [
                    'id'           => (int)$r['id'],
                    'username'     => (string)$r['username'],
                    'display_name' => $isAdminRow ? $admin_display_name : (string)$r['username'],
                    'sticker'      => ($r['sticker'] !== null && $r['sticker'] !== '') ? $r['sticker'] : '👤',
                    'is_online'    => ((int)$r['online_now'] === 1),
                    'is_admin'     => $isAdminRow,
                    'is_me'        => ((int)$r['id'] === (int)$userId),
                    'unread'       => (int)($unread[$r['id']] ?? 0),
                ];
            }

            echo json_encode([
                'status' => 'success',
                'users'  => $list,
                'total'  => $total,
                'page'   => $page,
                'pages'  => $pages,
                'per'    => $per,
                'q'      => $q,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'react') {
            $msgId = (int)($_POST['msg_id'] ?? 0);
            $reaction = trim($_POST['reaction'] ?? '');
            
            
            $allowedReactions = ['❤️', '👍', '😂', '😮', '😢'];
            if (!in_array($reaction, $allowedReactions)) {
                echo json_encode(['status' => 'error', 'message' => 'ری‌اکشن نامعتبر']);
                exit;
            }
            
            
            $stmtMsg = $pdo->prepare("SELECT id, sender_id, receiver_id, room_name FROM messages WHERE id = ?");
            $stmtMsg->execute([$msgId]);
            $msgRow = $stmtMsg->fetch();
            if (!$msgRow || !chat_can_access_message($pdo, $userId, $isAdmin, $msgId)) {
                echo json_encode(['status' => 'error', 'message' => 'پیام یافت نشد']);
                exit;
            }

            // The realtime fan-out only emits to the targets/rooms named in the
            // payload. Without them this event reached nobody, so a reaction
            // stayed invisible until the next full reload.
            $rxPush = ['kind' => 'reaction', 'message_id' => $msgId, 'force' => true];
            if (!empty($msgRow['room_name'])) {
                $rxPush['rooms'] = [['kind' => 'room', 'name' => (string)$msgRow['room_name']]];
                $rxPush['targets'] = [];
            } else {
                // Private thread: both ends need it, whichever one reacted.
                $rxPush['rooms'] = [];
                $rxPush['targets'] = array_values(array_unique(array_filter([
                    (int)$msgRow['sender_id'],
                    (int)$msgRow['receiver_id'],
                ])));
            }
            
            
            $stmtChk = $pdo->prepare("SELECT reaction FROM message_reactions WHERE message_id = ? AND user_id = ?");
            $stmtChk->execute([$msgId, $userId]);
            $existingReaction = $stmtChk->fetchColumn();
            $removed = false;

            if ($existingReaction === $reaction) {
                
                $pdo->prepare("DELETE FROM message_reactions WHERE message_id = ? AND user_id = ?")->execute([$msgId, $userId]);
                ws_push_async('message_updated', $rxPush);
                $removed = true;
            } else {
                
                if ($existingReaction) {
                    $pdo->prepare("UPDATE message_reactions SET reaction = ? WHERE message_id = ? AND user_id = ?")->execute([$reaction, $msgId, $userId]);
                } else {
                    $pdo->prepare("INSERT INTO message_reactions (message_id, user_id, reaction) VALUES (?, ?, ?)")->execute([$msgId, $userId, $reaction]);
                }
                ws_push_async('message_updated', $rxPush);
            }

            list($rxMap, $rxMine) = fetch_reactions_bulk($pdo, [$msgId], $userId);
            $rxNow = $rxMap[$msgId][$reaction] ?? ['count' => 0, 'users' => [], 'admin' => false];
            echo json_encode([
                'status'      => 'success',
                'message'     => $removed ? 'ری‌اکشن حذف شد' : 'ری‌اکشن ثبت شد',
                'removed'     => $removed,
                'reaction'    => $reaction,
                'count'       => (int)($rxNow['count'] ?? 0),
                'users'       => $rxNow['users'] ?? [],
                'admin'       => !empty($rxNow['admin']),
                'my_reaction' => $rxMine[$msgId] ?? null,
                'all'         => $rxMap[$msgId] ?? [],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'get_reactions') {
            $msgId = (int)$_POST['msg_id'];

            if (!chat_can_access_message($pdo, $userId, $isAdmin, $msgId)) {
                echo json_encode(['status' => 'error', 'message' => 'پیام یافت نشد']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT mr.reaction, u.username, mr.created_at
                FROM message_reactions mr
                JOIN users u ON mr.user_id = u.id
                WHERE mr.message_id = ?
                ORDER BY mr.created_at DESC
            ");
            $stmt->execute([$msgId]);
            $reactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            
            $grouped = [];
            $admins = chat_admin_usernames($pdo);
            foreach ($reactions as $r) {
                if (!isset($grouped[$r['reaction']])) {
                    $grouped[$r['reaction']] = [];
                }
                if (isset($admins[mb_strtolower((string)$r['username'], 'UTF-8')])) continue;
                $grouped[$r['reaction']][] = $r['username'];
            }
            
            echo json_encode(['status' => 'success', 'reactions' => $grouped]);
            exit;
        }

        if ($action === 'send') {
            if ($userData['muted_until'] > time()) {
                echo json_encode(['status' => 'error', 'message' => 'شما به دلیل اسپم موقتاً محدود شده‌اید']);
                exit;
            }

            $settings = [];
            $stmtSet = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('lock_upload','lock_voice')");
            $stmtSet->execute();
            while ($row = $stmtSet->fetch()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }

            $isUploadLocked = isset($settings['lock_upload']) && $settings['lock_upload'] === '1';
            $isVoiceLocked = isset($settings['lock_voice']) && $settings['lock_voice'] === '1';

            if (isset($_FILES['file']) && $isUploadLocked) {
                echo json_encode(['status' => 'error', 'message' => 'ارسال فایل توسط مدیریت مسدود شده است']);
                exit;
            }

            if (isset($_POST['is_voice']) && $_POST['is_voice'] === 'true' && $isVoiceLocked) {
                echo json_encode(['status' => 'error', 'message' => 'ارسال ویس توسط مدیریت مسدود شده است']);
                exit;
            }

            $now = time();
            if (!isset($_SESSION['spam_check'])) $_SESSION['spam_check'] = [];
            $_SESSION['spam_check'][] = $now;
            $_SESSION['spam_check'] = array_filter($_SESSION['spam_check'], function ($t) use ($now, $spam_limit_time) {
                return ($now - $t) < $spam_limit_time;
            });

            $rawRoom = isset($_POST['room']) ? $_POST['room'] : null;
            $roomId = ($rawRoom === 'null' || $rawRoom === '') ? null : trim((string)$rawRoom);
            if ($roomId === null || $roomId === '' || $roomId === 'null') $roomId = null;
            else $roomId = mb_substr($roomId, 0, 50, 'UTF-8');
            if ($roomId === 'public') $roomId = 'گفتگوی عمومی';

            if (count($_SESSION['spam_check']) > $spam_limit_count) {
                $muteUntil = $now + $ban_duration;
                $pdo->prepare("UPDATE users SET muted_until = ? WHERE id = ?")->execute([$muteUntil, $userId]);

                $sysMsg = "*** کاربر $username به دلیل ارسال پیام‌های مکرر به مدت ۲ دقیقه سکوت شد ***";
                $encMsg = encrypt_data($sysMsg);
                $pdo->prepare("INSERT INTO messages (sender_id, room_name, message, msg_type) VALUES (?, ?, ?, 'text')")
                    ->execute([$userId, $roomId ?: 'گفتگوی عمومی', $encMsg]);

                echo json_encode(['status' => 'error', 'message' => 'شما به دلیل اسپم ۲ دقیقه محدود شدید']);
                exit;
            }

            // Spam bookkeeping is done — release the session lock before the
            // insert/upload/push work so a concurrent poll never blocks sending.
            if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();

            $msgType = 'text';
            $messageRaw = '';
            if (isset($_POST['message'])) {
                $messageRaw = trim($_POST['message']);
                // Tags are removed so no markup can ever be stored, but the
                // remaining text is kept *raw*. Escaping it here as well meant
                // the renderer escaped it a second time and every message
                // containing < > or & was displayed as "&lt;" / "&amp;".
                $messageRaw = strip_tags($messageRaw);
                $messageRaw = mb_substr($messageRaw, 0, 320, 'UTF-8');
            }
            // Client-generated idempotency key. The browser reuses it when the
            // user retries a failed send, so a request whose response was lost
            // to a network drop can never store the same message twice.
            $clientMsgId = trim((string)($_POST['client_msg_id'] ?? ''));
            if ($clientMsgId !== '' && !preg_match('/^[A-Za-z0-9_-]{8,64}$/', $clientMsgId)) $clientMsgId = '';
            $filePath = null;
            $fileName = null;
            $fileToken = null;
            $stagedTokenUsed = null;

            $receiverId = (isset($_POST['receiver_id']) && is_numeric($_POST['receiver_id']) && $_POST['receiver_id'] > 0) ? (int)$_POST['receiver_id'] : null;
            $replyToId = null;
            if (isset($_POST['reply_to']) && !empty($_POST['reply_to'])) {
                $replyToId = (int)$_POST['reply_to'];
            }

            if ($roomId === 'گفتگوی عمومی') $receiverId = null;
            // A direct message must never carry a room name, otherwise it becomes
            // invisible to both participants (fetch filters room_name IS NULL).
            if ($receiverId) $roomId = null;

            // Private-chat block gate: neither direction may send through a
            // block. Checked before the duplicate probe so a retry of a blocked
            // send cannot slip in.
            if ($receiverId) {
                try {
                    $stBk = $pdo->prepare("SELECT blocker_id FROM blocked_users WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?) LIMIT 1");
                    $stBk->execute([$userId, $receiverId, $receiverId, $userId]);
                    $bkRow = $stBk->fetch(PDO::FETCH_ASSOC);
                    if ($bkRow) {
                        $byMe = ((int)$bkRow['blocker_id'] === (int)$userId);
                        echo json_encode([
                            'status'  => 'error',
                            'message' => $byMe
                                ? 'شما این کاربر را مسدود کرده‌اید؛ برای ارسال ابتدا رفع بلاک کنید'
                                : 'این کاربر شما را مسدود کرده است',
                            'blocked' => $byMe ? 'by_me' : 'by_them',
                        ]);
                        exit;
                    }
                } catch (Exception $e) {}
            }

            // Idempotent retry: the same logical message was already stored
            // (its response was lost in a network drop). Answer with the
            // existing row instead of inserting a second copy — no new AI
            // trigger either.
            if ($clientMsgId !== '') {
                try {
                    $stDup = $pdo->prepare("SELECT id FROM messages WHERE sender_id = ? AND client_msg_id = ? LIMIT 1");
                    $stDup->execute([$userId, $clientMsgId]);
                    $dupId = (int)$stDup->fetchColumn();
                    if ($dupId > 0) {
                        ignore_user_abort(true);
                        veyrax_ai_json_respond(['status' => 'success', 'new_msg_id' => $dupId, 'duplicate' => true]);
                        exit;
                    }
                } catch (Exception $e) {}
            }

            if (!$receiverId && $roomId && $roomId !== 'گفتگوی عمومی') {
                $stmtCheck = $pdo->prepare("SELECT type, room_kind, created_by FROM rooms WHERE name = ?");
                $stmtCheck->execute([$roomId]);
                $roomRow = $stmtCheck->fetch();
                $rType = $roomRow ? $roomRow['type'] : null;
                $rKind = $roomRow ? $roomRow['room_kind'] : 'room';
                $rCreatedBy = $roomRow ? (int)$roomRow['created_by'] : 0;
                if ($rType === 'private' && $username !== $admin_username) {
                    $stmtPerm = $pdo->prepare("SELECT id FROM room_invites WHERE user_id = ? AND room_name = ?");
                    $stmtPerm->execute([$userId, $roomId]);
                    if (!$stmtPerm->fetch()) {
                        echo json_encode(['status' => 'access_denied', 'message' => 'شما به این اتاق دعوت نشده‌اید']);
                        exit;
                    }
                }
                if ($rKind === 'channel' && (int)$userId !== (int)$rCreatedBy && !$isAdmin) {
                    echo json_encode(['status' => 'error', 'message' => 'فقط سازنده کانال می‌تواند پیام ارسال کند']);
                    exit;
                }
            }

            // ── Media that was already uploaded in chunks ────────────────
            // upload.php stores the file and hands back a token, so this
            // request carries no binary at all. That is what makes a video
            // publish instantly instead of dying halfway through a 2 MB
            // `upload_max_filesize` wall.
            $stagedToken = trim((string)($_POST['staged_token'] ?? ''));
            if ($stagedToken !== '') {
                if (!preg_match('/^[a-f0-9]{32}$/', $stagedToken)) {
                    echo json_encode(['status' => 'error', 'message' => 'شناسه رسانه نامعتبر است']);
                    exit;
                }
                $staged = null;
                try {
                    $st = $pdo->prepare("SELECT token, user_id, file_path, file_name, file_size, msg_type, created_at
                                         FROM staged_uploads WHERE token = ? LIMIT 1");
                    $st->execute([$stagedToken]);
                    $staged = $st->fetch(PDO::FETCH_ASSOC);
                } catch (Throwable $e) {
                    echo json_encode(['status' => 'error', 'message' => 'آپلود یافت نشد']);
                    exit;
                }
                if (!$staged || (int)$staged['user_id'] !== $userId) {
                    echo json_encode(['status' => 'error', 'message' => 'رسانه یافت نشد یا منقضی شده است']);
                    exit;
                }
                if (strtotime((string)$staged['created_at']) < time() - 21600) {
                    echo json_encode(['status' => 'error', 'message' => 'آپلود منقضی شده است، دوباره تلاش کنید']);
                    exit;
                }
                $stagedPath = (string)$staged['file_path'];
                $realBase = realpath(__DIR__ . '/uploads');
                $realFile = realpath(__DIR__ . '/' . $stagedPath);
                if ($realBase === false || $realFile === false ||
                    strpos($realFile, $realBase . DIRECTORY_SEPARATOR) !== 0) {
                    echo json_encode(['status' => 'error', 'message' => 'فایل رسانه یافت نشد']);
                    exit;
                }
                $stagedType = (string)$staged['msg_type'];
                if ($stagedType === 'voice' && $isVoiceLocked) {
                    echo json_encode(['status' => 'error', 'message' => 'ارسال ویس توسط مدیریت مسدود شده است']);
                    exit;
                }
                if ($stagedType !== 'voice' && $isUploadLocked) {
                    echo json_encode(['status' => 'error', 'message' => 'ارسال فایل توسط مدیریت مسدود شده است']);
                    exit;
                }
                $filePath       = $stagedPath;
                $fileName       = (string)$staged['file_name'];
                $fileToken      = $stagedToken;
                $msgType        = $stagedType;
                $stagedTokenUsed = $stagedToken;
            }

            if (isset($_FILES['file']) && $_FILES['file']['error'] === 0) {
                if ($_FILES['file']['size'] > $file_size_limit) {
                    echo json_encode(['status' => 'error', 'message' => 'حجم فایل زیاد است (حداکثر ' . round($file_size_limit / 1024 / 1024) . ' مگابایت)']);
                    exit;
                }
                $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
                $denied = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'sh', 'bash', 'pl', 'py', 'rb', 'htaccess', 'htpasswd', 'cgi', 'asp', 'aspx', 'jsp'];
                if (in_array($ext, $denied)) {
                    echo json_encode(['status' => 'error', 'message' => 'این فرمت فایل مجاز نیست (خطر سمت سرور)']);
                    exit;
                }

                $newFileName = bin2hex(random_bytes(16)) . '.' . $ext;
                $uploadPath = 'uploads/' . $newFileName;
                $fileContent = file_get_contents($_FILES['file']['tmp_name']);
                $encryptedContent = encrypt_file_data($fileContent);
                if (file_put_contents($uploadPath, $encryptedContent)) {
                    $filePath = $uploadPath;
                    $fileName = basename(str_replace('\\', '/', (string)$_FILES['file']['name']));
                    $fileName = preg_replace('/[\x00-\x1F\x7F<>"\'&]/u', '', $fileName);
                    $fileName = mb_substr(trim($fileName), 0, 120, 'UTF-8');
                    if ($fileName === '') $fileName = 'file';
                    $fileToken = bin2hex(random_bytes(16));
                    if (isset($_POST['is_voice']) && $_POST['is_voice'] === 'true') {
                        $msgType = 'voice';
                    } else {
                        // `.webm` is both audio and video; trust the declared
                        // MIME family before falling back to the extension.
                        $declared = strtolower((string)($_FILES['file']['type'] ?? ''));
                        if (strpos($declared, 'video/') === 0)      $msgType = 'video';
                        elseif (strpos($declared, 'image/') === 0)  $msgType = 'image';
                        elseif (strpos($declared, 'audio/') === 0)  $msgType = 'voice';
                        elseif (in_array($ext, ['mp3', 'wav', 'ogg', 'webm', 'm4a', 'flac', 'aac', 'opus'])) $msgType = 'voice';
                        elseif (in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'm4v', '3gp', 'wmv'])) $msgType = 'video';
                        elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'ico'])) $msgType = 'image';
                        else $msgType = 'file';
                    }
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'خطا در ذخیره فایل']);
                    exit;
                }
            }

            if (empty($messageRaw) && empty($filePath)) {
                echo json_encode(['status' => 'error', 'message' => 'پیام خالی است']);
                exit;
            }

            $messageEncrypted = $messageRaw ? encrypt_data($messageRaw) : '';
            $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, room_name, message, file_path, file_name, file_token, msg_type, reply_to_id, client_msg_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            // One transaction, one fsync. Before this, the INSERT and the
            // "last_activity" UPDATE each committed separately — two disk
            // flushes per message (~90 ms on Windows/WAMP, still 2× the I/O
            // everywhere else). The activity update is also skipped when the
            // row is already fresh, so the common case commits a single row.
            try {
                $pdo->beginTransaction();
                $stmt->execute([$userId, $receiverId, $roomId, $messageEncrypted, $filePath, $fileName, $fileToken, $msgType, $replyToId, ($clientMsgId !== '' ? $clientMsgId : null)]);
                $newMsgId = (int)$pdo->lastInsertId();
                $pdo->prepare("UPDATE users SET is_online = 1, last_activity = NOW()
                               WHERE id = ? AND (is_online = 0 OR last_activity < DATE_SUB(NOW(), INTERVAL 40 SECOND))")
                    ->execute([$userId]);
                $pdo->commit();
            } catch (Exception $eTx) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                // Two retries racing each other: the unique key lets exactly one
                // insert through. The loser answers with the winner's row.
                $sqlState = ($eTx instanceof PDOException) ? (string)$eTx->getCode() : '';
                if ($sqlState === '23000' && $clientMsgId !== '') {
                    try {
                        $stDup2 = $pdo->prepare("SELECT id FROM messages WHERE sender_id = ? AND client_msg_id = ? LIMIT 1");
                        $stDup2->execute([$userId, $clientMsgId]);
                        $dupId2 = (int)$stDup2->fetchColumn();
                        if ($dupId2 > 0) {
                            ignore_user_abort(true);
                            veyrax_ai_json_respond(['status' => 'success', 'new_msg_id' => $dupId2, 'duplicate' => true]);
                            exit;
                        }
                    } catch (Exception $e) {}
                }
                error_log('[VIRAX send] ' . $eTx->getMessage());
                echo json_encode(['status' => 'error', 'message' => 'خطا در ذخیره پیام']);
                exit;
            }

            // The staged row has done its job. Deleting it also makes a double
            // submit impossible: the second request finds no token.
            if ($stagedTokenUsed !== null) {
                try {
                    $pdo->prepare("DELETE FROM staged_uploads WHERE token = ?")->execute([$stagedTokenUsed]);
                } catch (Throwable $e) {}
            }

            // The push carries the finished message, not just its id: a client
            // that receives it can paint the bubble immediately instead of
            // spending another round-trip on "something changed, go fetch".
            // Same shape as one row of the fetch response, so the very same
            // renderer (_applyIncrementalMessages) can consume it.
            $wsSenderName = (string)($userData['username'] ?? $username);
            if ($wsSenderName === $admin_username) $wsSenderName = $admin_display_name;
            $wsMsg = [
                'id'             => $newMsgId,
                'sender_id'      => (int)$userId,
                'sender_name'    => $wsSenderName,
                'sender_sticker' => $userData['sticker'] ?? null,
                'receiver_id'    => $receiverId ? (int)$receiverId : null,
                'room_name'      => $roomId,
                'message'        => (string)$messageRaw,
                'msg_type'       => $msgType,
                'file_name'      => $fileName,
                'reply_to_id'    => $replyToId ? (int)$replyToId : null,
                'reply_message'  => null,
                'reply_sender'   => null,
                'reply_msg_type' => null,
                'is_read'        => 0,
                'seen_by'        => 0,
                'created_at'     => date('Y-m-d H:i:s'),
                'reactions'      => [],
            ];
            if ($replyToId) {
                try {
                    $rq = $pdo->prepare("SELECT m.message, m.msg_type, u.username FROM messages m LEFT JOIN users u ON m.sender_id = u.id WHERE m.id = ?");
                    $rq->execute([$replyToId]);
                    if ($rr = $rq->fetch(PDO::FETCH_ASSOC)) {
                        $wsMsg['reply_message']  = ($rr['message'] !== null && $rr['message'] !== '') ? decrypt_data($rr['message']) : '';
                        $wsMsg['reply_sender']   = ($rr['username'] === $admin_username) ? $admin_display_name : $rr['username'];
                        $wsMsg['reply_msg_type'] = $rr['msg_type'];
                    }
                } catch (Exception $e) {}
            }

            // ── Assistant trigger ─────────────────────────────────────────
            // One shared path for rooms AND private chats. The trigger carries
            // the user's actual text ($aiTriggerText): the queue worker feeds
            // exactly that to the model, so an empty prompt can never happen.
            $aiTriggerText = null;
            $aiKind = null;
            $aiPeerId = 0;
            $aiCfgNow = veyrax_ai_cfg($pdo);
            // "Enabled" is not the same as "configured". While the endpoint or the
            // model is still empty nothing is queued, so a question asked during
            // setup cannot resurface and get answered once the admin finishes.
            if (veyrax_ai_ready($aiCfgNow) && $msgType === 'text' && !$isDemoUser) {
                $probe = trim(strip_tags((string)$messageRaw));
                $aiKeywords = array_values(array_filter(array_map('trim', preg_split('/[,\n]/u', (string)($aiCfgNow['ai_keyword'] ?? '')))));
                if (!$aiKeywords) $aiKeywords = ['ویراکس'];
                $kwHit = false;
                foreach ($aiKeywords as $kw) {
                    if ($kw !== '' && mb_stripos($probe, $kw) !== false) { $kwHit = true; break; }
                }
                // Replying straight to an assistant message is always an
                // explicit call — keyword or not, in rooms and in PVs.
                $replyIsAi = false;
                if ($replyToId) {
                    try {
                        $stRA = $pdo->prepare("SELECT sender_id FROM messages WHERE id = ? LIMIT 1");
                        $stRA->execute([$replyToId]);
                        $replyIsAi = ((int)$stRA->fetchColumn() === (int)veyrax_ai_user_id($pdo));
                    } catch (Exception $e) { $replyIsAi = false; }
                }

                if ($roomId && !$receiverId && !veyrax_is_demo_room($roomId)) {
                    $roomOn = false;
                    try {
                        $stR = $pdo->prepare("SELECT ai_enabled FROM rooms WHERE name = ?");
                        $stR->execute([$roomId]);
                        $roomOn = ((int)$stR->fetchColumn() === 1);
                    } catch (Exception $e) { $roomOn = false; }
                    if (!$roomOn && trim((string)($aiCfgNow['ai_rooms'] ?? '')) !== '') {
                        $roomOn = in_array($roomId, array_map('trim', explode(',', (string)$aiCfgNow['ai_rooms'])), true);
                        if ($roomOn) {
                            try { $pdo->prepare("UPDATE rooms SET ai_enabled = 1 WHERE name = ?")->execute([$roomId]); } catch (Exception $e) {}
                        }
                    }
                    // ai_all_rooms (default on): the assistant answers in every
                    // group without per-room setup. Turning it off in the admin
                    // panel restores the per-room / ai_rooms list behaviour.
                    if (!$roomOn && (($aiCfgNow['ai_all_rooms'] ?? '1') === '1')) $roomOn = true;
                    if ($roomOn) {
                        if ($replyIsAi || $kwHit) {
                            $aiTriggerText = $probe;
                            $aiKind = 'room';
                        } elseif (($aiCfgNow['ai_mention_only'] ?? '1') !== '1') {
                            // Relaxed mode: every message in an active room.
                            $aiTriggerText = $probe;
                            $aiKind = 'room';
                        }
                    }
                } elseif ($receiverId && (($aiCfgNow['ai_pv'] ?? '1') === '1')) {
                    $aiUid = veyrax_ai_user_id($pdo);
                    if ($aiUid) {
                        if ((int)$receiverId === (int)$aiUid) {
                            // Talking directly to the assistant: every message
                            // is a conversation, no wake word needed.
                            $aiTriggerText = $probe;
                            $aiKind = 'pv';
                            $aiPeerId = (int)$aiUid;
                        } elseif ($replyIsAi || $kwHit) {
                            // Private chat with another human: the assistant
                            // only joins when explicitly called, so ordinary
                            // messages never leave the two participants.
                            $aiTriggerText = $probe;
                            $aiKind = 'pv';
                            $aiPeerId = (int)$receiverId;
                        }
                    }
                }
            }

            // ── Respond BEFORE pushing ────────────────────────────────────
            // The realtime push travels over a DIFFERENT connection than this
            // HTTP response, so it can arrive first. If it does while the
            // browser is still waiting for this JSON, its echo-refetch runs
            // with a stale after_id and paints the optimistic bubble a second
            // time (the classic duplicate, most visible in assistant PVs where
            // every send runs the slower assistant branch). Emitting the
            // response first lets the client advance its cursor before any
            // push can trigger a refetch.
            $resp = ['status' => 'success', 'new_msg_id' => $newMsgId];
            $aiEnqueued = false;
            if ($aiKind !== null && $aiTriggerText !== null && $aiTriggerText !== '') {
                $limitCheck = veyrax_ai_user_limit_check($pdo, (int)$userId, $aiCfgNow);
                if (!$limitCheck['ok']) {
                    // The message itself is fine; only the assistant call is
                    // refused. The client shows a toast with the wait time.
                    $resp['ai_limited'] = true;
                    $resp['ai_retry_after'] = (int)$limitCheck['retry'];
                } else {
                    veyrax_ai_enqueue($pdo, ($aiKind === 'room' ? $roomId : ''), (int)$newMsgId, $aiTriggerText, (int)$userId, $aiKind, $aiPeerId);
                    $aiEnqueued = true;
                    $resp['ai_pending'] = true;
                }
            }
            // Respond BEFORE the model call: send the JSON now and let a
            // locked shutdown worker drain the queue, so the browser is
            // never held hostage by the assistant's latency.
            ignore_user_abort(true);
            veyrax_ai_json_respond($resp);
            if ($aiEnqueued) register_shutdown_function('veyrax_ai_queue_worker', $pdo);

            ws_push_async('new_message', [
                'message_id' => $newMsgId,
                'sender_id'  => $userId,
                'room_name'  => $roomId,
                'receiver_id'=> $receiverId,
                'msg_type'   => $msgType,
                'force'      => true,
                'message'    => $wsMsg,
                'targets'    => $receiverId ? [$receiverId] : [],
                'rooms'      => $roomId ? [['kind' => 'room', 'name' => $roomId]] : []
            ]);
            exit;
        }

        if ($action === 'search_messages') {
            $query = trim($_POST['query'] ?? '');
            $roomId = isset($_POST['room']) && $_POST['room'] !== 'null' ? trim($_POST['room']) : null;
            $targetUserId = (isset($_POST['target_user']) && is_numeric($_POST['target_user']) && (int)$_POST['target_user'] > 0) ? (int)$_POST['target_user'] : null;

            if (mb_strlen($query, 'UTF-8') < 2) {
                echo json_encode(['status' => 'error', 'message' => 'عبارت جستجو کوتاه است']);
                exit;
            }
            if (mb_strlen($query, 'UTF-8') > 100) {
                echo json_encode(['status' => 'error', 'message' => 'عبارت جستجو طولانی است']);
                exit;
            }
            if (!chat_can_access_room($pdo, $userId, $isAdmin, $roomId)) {
                echo json_encode(['status' => 'access_denied']);
                exit;
            }

            $sql = "SELECT m.id, m.sender_id, m.msg_type, m.message, m.file_name, m.created_at, u.username as sender_name
                    FROM messages m
                    JOIN users u ON m.sender_id = u.id
                    WHERE m.msg_type IN ('text','ai') AND ";

            $params = [];

            if ($targetUserId) {
                $sql .= "((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)) AND m.room_name IS NULL";
                array_push($params, $userId, $targetUserId, $targetUserId, $userId);
            } else {
                $safeRoom = ($roomId && $roomId !== 'public') ? $roomId : 'گفتگوی عمومی';
                $sql .= "m.room_name = ? AND m.receiver_id IS NULL";
                $params[] = $safeRoom;
            }

            $sql .= " ORDER BY m.id DESC LIMIT 300";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $results = [];
            foreach ($rows as $row) {
                try {
                    $text = decrypt_data($row['message']);
                } catch (Exception $e) {
                    continue;
                }
                if (mb_stripos($text, $query, 0, 'UTF-8') === false) continue;
                if (count($results) >= 50) break;
                $results[] = [
                    'id'          => (int)$row['id'],
                    'sender_name' => ($row['sender_name'] === $admin_username) ? $admin_display_name : $row['sender_name'],
                    'sender_id'   => (int)$row['sender_id'],
                    'message'     => $text,
                    'created_at'  => $row['created_at'],
                ];
            }

            echo json_encode(['status' => 'success', 'results' => $results]);
            exit;
        }

        if ($action === 'fetch_history') {
            $roomId = isset($_POST['room']) && $_POST['room'] !== 'null' ? trim($_POST['room']) : null;
            $targetUserId = (isset($_POST['target_user']) && is_numeric($_POST['target_user']) && (int)$_POST['target_user'] > 0) ? (int)$_POST['target_user'] : null;
            $beforeId = (int)($_POST['before_id'] ?? 0);
            if (!$beforeId) { echo json_encode(['status' => 'success', 'messages' => []]); exit; }
            if (!chat_can_access_room($pdo, $userId, $isAdmin, $roomId)) {
                echo json_encode(['status' => 'access_denied']);
                exit;
            }

            $sql = "SELECT m.*, u.username as sender_name, u.sticker as sender_sticker,
                    rm.message as reply_message, rm.file_name as reply_file_name, rm.msg_type as reply_msg_type, ru.username as reply_sender
                    FROM messages m
                    JOIN users u ON m.sender_id = u.id
                    LEFT JOIN messages rm ON m.reply_to_id = rm.id
                    LEFT JOIN users ru ON rm.sender_id = ru.id
                    WHERE m.id < ? AND ";

            $params = [$beforeId];

            if ($targetUserId) {
                // Assistant replies into this private thread are addressed to
                // the asker; both participants must see them here. The EXISTS
                // pins each ai row to the thread its trigger message lives in,
                // so replies from the asker's OTHER private threads never leak.
                $aiUidPv = veyrax_ai_user_id($pdo);
                $sql .= "((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)";
                array_push($params, $userId, $targetUserId, $targetUserId, $userId);
                if ($aiUidPv) {
                    $sql .= " OR (m.sender_id = ? AND m.msg_type = 'ai' AND m.receiver_id IN (?, ?) AND EXISTS (
                                SELECT 1 FROM messages t WHERE t.id = m.reply_to_id AND t.room_name IS NULL
                                AND ((t.sender_id = ? AND t.receiver_id = ?) OR (t.sender_id = ? AND t.receiver_id = ?))
                            ))";
                    array_push($params, $aiUidPv, $userId, $targetUserId, $userId, $targetUserId, $targetUserId, $userId);
                }
                $sql .= ") AND m.room_name IS NULL";
            } else {
                $safeRoom = ($roomId && $roomId !== 'public') ? $roomId : 'گفتگوی عمومی';
                $sql .= "m.room_name = ? AND m.receiver_id IS NULL";
                $params[] = $safeRoom;
            }

            $sql .= " ORDER BY m.id DESC LIMIT 20";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rawMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $messages = [];
            foreach (array_reverse($rawMessages) as $m) {
                try {
                    if (!empty($m['message'])) $m['message'] = decrypt_data($m['message']);
                    else $m['message'] = '';
                } catch (Exception $e) {
                    $m['message'] = 'Error';
                }
                try {
                    if (!empty($m['reply_message'])) $m['reply_message'] = decrypt_data($m['reply_message']);
                } catch (Exception $e) {
                    $m['reply_message'] = '...';
                }
                $m['seen_by'] = ($m['sender_id'] == $userId && $m['is_read'] == 1) ? 1 : 0;

                // The admin account shows its display name instead of the raw
                // login username ("admin") in every bubble and reply quote.
                if ($m['sender_name'] === $admin_username) $m['sender_name'] = $admin_display_name;
                if (!empty($m['reply_sender']) && $m['reply_sender'] === $admin_username) $m['reply_sender'] = $admin_display_name;

                unset($m['file_path']);
                $messages[] = $m;
            }
            attach_reactions_bulk($pdo, $messages, $userId);
            echo json_encode(['status' => 'success', 'messages' => $messages]);
            exit;
        }

        if ($action === 'phone_auth_verify') {
            $phone = trim($_POST['phone'] ?? '');
            $phone = preg_replace('/[^0-9]/', '', $phone);
            $authPass = trim($_POST['auth_password'] ?? '');

            if (empty($phone) && empty($authPass)) {
                echo json_encode(['status' => 'error', 'message' => 'شماره موبایل یا رمز احراز هویت را وارد کنید']);
                exit;
            }

            $stmt = $pdo->prepare("SELECT phone_number, phone_auth_attempts, phone_auth_locked_until FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $uRow = $stmt->fetch();
            if (!$uRow) { echo json_encode(['status' => 'error', 'message' => 'کاربر یافت نشد']); exit; }

            $storedPhone = $uRow['phone_number'] ?? '';
            $storedPassHash = veyrax_get_auth_hash($pdo, $userId);
            $attempts = (int)($uRow['phone_auth_attempts'] ?? 0);
            $lockedUntil = (int)($uRow['phone_auth_locked_until'] ?? 0);

            if ($lockedUntil > time()) {
                $rem = ceil(($lockedUntil - time()) / 60);
                echo json_encode(['status' => 'locked', 'message' => "حساب شما به مدت $rem دقیقه دیگر قفل است"]);
                exit;
            }

            $isNewUser = empty($storedPhone) && empty($storedPassHash);

            if ($isNewUser) {
                $updates = [];
                $params = [];
                if (!empty($phone)) { $updates[] = 'phone_number = ?'; $params[] = $phone; }
                if (!empty($authPass)) {
                    if (mb_strlen($authPass) < 8 || mb_strlen($authPass) > 20 || preg_match('/[\x{0600}-\x{06FF}]/u', $authPass)) {
                        echo json_encode(['status' => 'error', 'message' => 'رمز عبور باید ۸ تا ۲۰ کاراکتر انگلیسی باشد']);
                        exit;
                    }
                    if (!veyrax_set_auth_hash($pdo, $userId, password_hash($authPass, PASSWORD_DEFAULT))) {
                        echo json_encode(['status' => 'error', 'message' => 'ذخیره رمز احراز هویت روی این هاست ممکن نیست؛ با شماره موبایل وارد شوید']);
                        exit;
                    }
                }
                $updates[] = 'phone_auth_attempts = 0'; $updates[] = 'phone_auth_locked_until = 0';
                $params[] = $userId;
                $pdo->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
                $_SESSION['phone_verified'] = true;
                echo json_encode(['status' => 'success', 'message' => 'اطلاعات احراز هویت ثبت شد']);
                exit;
            }

            $matched = false;
            if (!empty($phone) && !empty($storedPhone) && $phone === $storedPhone) $matched = true;
            if (!$matched && !empty($authPass) && !empty($storedPassHash) && password_verify($authPass, $storedPassHash)) $matched = true;

            if ($matched) {
                $pdo->prepare("UPDATE users SET phone_auth_attempts = 0, phone_auth_locked_until = 0 WHERE id = ?")->execute([$userId]);
                $_SESSION['phone_verified'] = true;
                echo json_encode(['status' => 'success']);
                exit;
            }

            $newAttempts = $attempts + 1;
            if ($newAttempts >= 5) {
                $lockTime = time() + 3600;
                $pdo->prepare("UPDATE users SET phone_auth_attempts = 0, phone_auth_locked_until = ? WHERE id = ?")->execute([$lockTime, $userId]);
                echo json_encode(['status' => 'locked', 'message' => 'به دلیل ۵ بار ورود اشتباه، حساب شما ۱ ساعت قفل شد']);
            } else {
                $pdo->prepare("UPDATE users SET phone_auth_attempts = ? WHERE id = ?")->execute([$newAttempts, $userId]);
                $rem = 5 - $newAttempts;
                echo json_encode(['status' => 'error', 'message' => "اطلاعات اشتباه است. $rem تلاش باقی مانده"]);
            }
            exit;
        }

        if ($action === 'phone_auth_skip') {
            $_SESSION['phone_skip'] = true;
            echo json_encode(['status' => 'success']);
            exit;
        }

        // ── Private-chat block list ──────────────────────────────────────────
        // Blocking is one-directional and instant: the blocked user can no
        // longer send into the thread, and the blocker sees a banner instead of
        // the composer. History stays visible on both sides so nobody loses
        // context, and unblock restores the thread immediately.
        if ($action === 'block_user' || $action === 'unblock_user') {
            $target = (int)($_POST['target_user'] ?? 0);
            if (!$target || $target === (int)$userId) {
                echo json_encode(['status' => 'error', 'message' => 'کاربر نامعتبر است']);
                exit;
            }
            $stT = $pdo->prepare("SELECT id FROM users WHERE id = ? LIMIT 1");
            $stT->execute([$target]);
            if (!$stT->fetchColumn()) {
                echo json_encode(['status' => 'error', 'message' => 'کاربر یافت نشد']);
                exit;
            }
            if ($action === 'block_user') {
                $pdo->prepare("INSERT IGNORE INTO blocked_users (blocker_id, blocked_id) VALUES (?, ?)")->execute([$userId, $target]);
                echo json_encode(['status' => 'success', 'message' => 'کاربر مسدود شد', 'blocked' => 'by_me']);
            } else {
                $pdo->prepare("DELETE FROM blocked_users WHERE blocker_id = ? AND blocked_id = ?")->execute([$userId, $target]);
                echo json_encode(['status' => 'success', 'message' => 'رفع بلاک انجام شد', 'blocked' => 'none']);
            }
            exit;
        }

        if ($action === 'phone_auth_check') {
            $stmt = $pdo->prepare("SELECT phone_number, phone_auth_locked_until FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $uRow = $stmt->fetch();
            $hasPhone = !empty($uRow['phone_number'] ?? '');
            $hasAuth = $hasPhone || veyrax_get_auth_hash($pdo, $userId) !== '';
            $lockedUntil = (int)($uRow['phone_auth_locked_until'] ?? 0);
            $isLocked = ($lockedUntil > time());
            $isVerified = isset($_SESSION['phone_verified']) && $_SESSION['phone_verified'];
            $skipped = !empty($_SESSION['phone_skip']);
            echo json_encode([
                'status' => 'success',
                'has_phone' => $hasPhone,
                'has_auth' => $hasAuth,
                'needs_verify' => (!$isVerified && !$skipped),
                'is_locked' => $isLocked,
                'lock_remaining' => $isLocked ? ceil(($lockedUntil - time()) / 60) : 0
            ]);
            exit;
        }

        if ($action === 'wait') {
            if (session_status() === PHP_SESSION_ACTIVE) { @session_write_close(); }
            @set_time_limit(40);
            @ignore_user_abort(false);

            $afterId = isset($_POST['after_id']) && is_numeric($_POST['after_id']) ? (int)$_POST['after_id'] : 0;
            $waitRoom = isset($_POST['room']) ? (string)$_POST['room'] : 'گفتگوی عمومی';
            if ($waitRoom === 'public' || $waitRoom === 'null' || $waitRoom === '') { $waitRoom = 'گفتگوی عمومی'; }
            $waitTarget = isset($_POST['target_user']) && is_numeric($_POST['target_user']) ? (int)$_POST['target_user'] : 0;
            $waitTimeout = isset($_POST['timeout']) && is_numeric($_POST['timeout']) ? (int)$_POST['timeout'] : 18;
            $waitTimeout = max(5, min(25, $waitTimeout));
            // Revision of the conversation as the client last saw it. New rows
            // are caught by the primary-key scan below; edits, reactions and
            // admin media removals change no id at all, so without this the
            // only thing that ever repainted them was the 30 s safety poll.
            $clientRev = isset($_POST['rev']) && is_string($_POST['rev']) ? (string)$_POST['rev'] : '';

            if ($afterId <= 0) {
                // A client that has not loaded this conversation yet (empty PV,
                // first open) anchors to the current tip so the wait reacts to
                // NEW rows only. Anchoring to 0 matched the whole table and
                // turned the long-poll into a busy loop (429 storm).
                try {
                    if ($waitTarget > 0) {
                        $stTip = $pdo->prepare("SELECT COALESCE(MAX(id),0) FROM messages WHERE room_name IS NULL AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))");
                        $stTip->execute([$userId, $waitTarget, $waitTarget, $userId]);
                    } else {
                        $stTip = $pdo->prepare("SELECT COALESCE(MAX(id),0) FROM messages WHERE room_name = ? AND receiver_id IS NULL");
                        $stTip->execute([$waitRoom]);
                    }
                    $afterId = (int)$stTip->fetchColumn();
                } catch (Exception $e) { $afterId = 0; }
            }

            if ($waitTarget > 0) {
                $aiUidWait = veyrax_ai_user_id($pdo);
                $wSql = "SELECT 1 FROM messages WHERE id > ? AND room_name IS NULL
                         AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)";
                $wArgs = [$afterId, $userId, $waitTarget, $waitTarget, $userId];
                if ($aiUidWait) {
                    $wSql .= " OR (sender_id = ? AND msg_type = 'ai' AND receiver_id IN (?, ?))";
                    array_push($wArgs, $aiUidWait, $userId, $waitTarget);
                }
                $wSql .= " OR (room_name IS NULL AND receiver_id = ? AND sender_id <> ?))";
                array_push($wArgs, $userId, $userId);
                $wSql .= " LIMIT 1";
                $wStmt = $pdo->prepare($wSql);
            } else {
                $wStmt = $pdo->prepare("SELECT 1 FROM messages WHERE id > ? AND ((room_name = ? AND receiver_id IS NULL) OR (room_name IS NULL AND receiver_id = ?)) LIMIT 1");
                $wArgs = [$afterId, $waitRoom, $userId];
            }

            $deadline = microtime(true) + $waitTimeout;
            $startedAt = microtime(true);
            $revTick = 0;

            while (microtime(true) < $deadline) {
                $wStmt->execute($wArgs);
                if ($wStmt->fetchColumn()) {
                    echo json_encode(['status' => 'success', 'changed' => true, 'after_id' => $afterId]);
                    exit;
                }
                // Same loop, second question: did anything already on screen
                // change? Polled at ~500 ms instead of 40 ms — an edit is a
                // human action, half a second is imperceptible, and the
                // aggregate only walks one room's rows.
                if ($clientRev !== '' && ($revTick++ % 12) === 0) {
                    $revNow = veyrax_chat_rev($pdo, (int)$userId, $waitRoom, $waitTarget);
                    if ($revNow !== '' && $revNow !== $clientRev) {
                        echo json_encode(['status' => 'success', 'changed' => true, 'reason' => 'update', 'rev' => $revNow]);
                        exit;
                    }
                }
                // Detection window, not a heartbeat. This loop is the thing
                // that decides how fast a message becomes visible, so it runs
                // hot (40 ms) for the first ten seconds — the window in which
                // an active conversation lives — and only then eases off.
                // The query is a single primary-key range scan, so 25 lookups
                // a second cost far less than one wasted second of the user's
                // time.
                $elapsed = microtime(true) - $startedAt;
                $sleepUs = $elapsed < 10 ? 40000 : 90000;
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) break;
                usleep((int)(min($sleepUs, $remaining * 1000000)));
            }

            $revOut = ($clientRev !== '') ? veyrax_chat_rev($pdo, (int)$userId, $waitRoom, $waitTarget) : '';
            echo json_encode(['status' => 'success', 'changed' => false, 'timeout' => $waitTimeout, 'rev' => $revOut]);
            exit;
        }

        if ($action === 'fetch') {
            // Only touch the row when it is actually stale. A write on every
            // poll (every 2–3 s per user) created row-lock contention that
            // slowed the send path down on busy rooms.
            $pdo->prepare("UPDATE users SET is_online = 1, last_activity = NOW()
                           WHERE id = ? AND (is_online = 0 OR last_activity < DATE_SUB(NOW(), INTERVAL 40 SECOND))")
                ->execute([$userId]);

            $roomId = isset($_POST['room']) ? $_POST['room'] : null;
            if ($roomId === 'public') $roomId = 'گفتگوی عمومی';
            $targetUserId = isset($_POST['target_user']) && is_numeric($_POST['target_user']) ? (int)$_POST['target_user'] : null;

            // Same two values the 'wait' action builds from its own POST body.
            // They were referenced here without ever being assigned, so every
            // cold poll (after_id = 0) raised two "Undefined variable" warnings
            // and then anchored to the wrong conversation.
            $waitTarget = (int)($targetUserId ?? 0);
            $waitRoom   = ($roomId !== null && $roomId !== '' && $roomId !== 'null') ? (string)$roomId : 'گفتگوی عمومی';
            if ($waitRoom === 'public') $waitRoom = 'گفتگوی عمومی';

            // after_id = 0 means "this client has nothing on screen yet", and
            // the only correct answer to that is the latest page of history —
            // see the ORDER BY ... DESC LIMIT 20 branch further down.
            //
            // The 'wait' action has its own copy of this variable, and there
            // anchoring to the tip is correct: a long-poll must react to NEW
            // rows only. This action is the one that paints the room, so
            // anchoring here left every fresh page load with an empty list and
            // made a just-sent message vanish on the next repaint.
            $afterId = isset($_POST['after_id']) && is_numeric($_POST['after_id']) ? (int)$_POST['after_id'] : 0;

            // A poll that already knows where it is (after_id > 0) only needs
            // the new messages + counters. The room list, system settings,
            // global alert and pinned strip are re-sent by the client's
            // periodic "full" refresh instead of on every 3-second tick.
            $lightFetch = !empty($_POST['light']) && $afterId > 0;

            if (!$targetUserId && $roomId && $roomId !== 'گفتگوی عمومی' && $roomId !== 'public' && $roomId !== 'null') {
                $stmtCheck = $pdo->prepare("SELECT type FROM rooms WHERE name = ?");
                $stmtCheck->execute([$roomId]);
                $rType = $stmtCheck->fetchColumn();
                if ($rType === 'private' && !$isAdmin) {
                    $stmtPerm = $pdo->prepare("SELECT id FROM room_invites WHERE user_id = ? AND room_name = ?");
                    $stmtPerm->execute([$userId, $roomId]);
                    if (!$stmtPerm->fetch()) {
                        echo json_encode(['status' => 'access_denied']);
                        exit;
                    }
                }
            }

            if ($targetUserId) {
                $pdo->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0")->execute([$targetUserId, $userId]);
            }

            $sql = "SELECT m.*, u.username as sender_name, u.sticker as sender_sticker,
                    rm.message as reply_message, rm.file_name as reply_file_name, rm.msg_type as reply_msg_type, ru.username as reply_sender
                    FROM messages m
                    JOIN users u ON m.sender_id = u.id
                    LEFT JOIN messages rm ON m.reply_to_id = rm.id
                    LEFT JOIN users ru ON rm.sender_id = ru.id
                    WHERE ";

            $params = [];

            if ($targetUserId) {
                // Same thread pinning as the fetch action: an assistant reply
                // is visible in the private thread that summoned it, and only
                // there — never in the asker's other conversations.
                $aiUidPv = veyrax_ai_user_id($pdo);
                $sql .= "((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)";
                array_push($params, $userId, $targetUserId, $targetUserId, $userId);
                if ($aiUidPv) {
                    $sql .= " OR (m.sender_id = ? AND m.msg_type = 'ai' AND m.receiver_id IN (?, ?) AND EXISTS (
                                SELECT 1 FROM messages t WHERE t.id = m.reply_to_id AND t.room_name IS NULL
                                AND ((t.sender_id = ? AND t.receiver_id = ?) OR (t.sender_id = ? AND t.receiver_id = ?))
                            ))";
                    array_push($params, $aiUidPv, $userId, $targetUserId, $userId, $targetUserId, $targetUserId, $userId);
                }
                $sql .= ") AND m.room_name IS NULL";
            } else {
                $safeRoom = ($roomId && $roomId !== 'null' && $roomId !== 'public') ? $roomId : 'گفتگوی عمومی';
                $sql .= "m.room_name = ? AND m.receiver_id IS NULL";
                $params[] = $safeRoom;
            }

            if ($afterId > 0) {
                $sql .= " AND m.id > ? ";
                $params[] = $afterId;
                $sql .= " ORDER BY m.id ASC LIMIT 100";
            } else {
                $sql .= " ORDER BY m.id DESC LIMIT 20";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rawMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($afterId === 0) $rawMessages = array_reverse($rawMessages);

            $messages = [];
            foreach ($rawMessages as $m) {
                try {
                    if (!empty($m['message'])) $m['message'] = decrypt_data($m['message']);
                    else $m['message'] = '';
                } catch (Exception $e) {
                    $m['message'] = 'Error';
                }
                try {
                    if (!empty($m['reply_message'])) $m['reply_message'] = decrypt_data($m['reply_message']);
                } catch (Exception $e) {
                    $m['reply_message'] = '...';
                }
                $m['seen_by'] = ($m['sender_id'] == $userId && $m['is_read'] == 1) ? 1 : 0;

                // The admin account shows its display name instead of the raw
                // login username ("admin") in every bubble and reply quote.
                if ($m['sender_name'] === $admin_username) $m['sender_name'] = $admin_display_name;
                if (!empty($m['reply_sender']) && $m['reply_sender'] === $admin_username) $m['reply_sender'] = $admin_display_name;

                unset($m['file_path']);
                $messages[] = $m;
            }
            attach_reactions_bulk($pdo, $messages, $userId);

            $lastNotifId = $userData['last_notif_id'] ?? 0;
            $newNotifs = [];

            $isAdminInt = ($username === $admin_username) ? 1 : 0;

            // Demo visitors never get notifications. The demo room is scoped per
            // visitor, but the public room is shared, so a brand-new throw-away
            // account with last_notif_id = 0 used to be notified about every
            // message that had ever been posted there. Only the cursor is moved.
            if ($isDemoUser) {
                try {
                    $demoMax = (int)$pdo->query("SELECT MAX(id) FROM messages")->fetchColumn();
                    if ($demoMax > (int)$lastNotifId) {
                        $pdo->prepare("UPDATE users SET last_notif_id = ? WHERE id = ?")->execute([$demoMax, $userId]);
                    }
                } catch (Exception $e) {}
            } else {

            $sqlNotif = "SELECT m.*, u.username as sender_name, u.sticker as sender_sticker
                         FROM messages m
                         JOIN users u ON m.sender_id = u.id
                         LEFT JOIN rooms r ON m.room_name = r.name
                         WHERE m.id > ?
                         AND m.sender_id != ?
                         AND (
                             m.receiver_id = ?
                             OR (m.receiver_id IS NULL AND (
                                 m.room_name = 'گفتگوی عمومی'
                                 OR m.room_name IS NULL
                                 OR r.type = 'public'
                                 OR r.created_by = ?
                                 OR EXISTS (SELECT 1 FROM room_invites ri WHERE ri.room_name = m.room_name AND ri.user_id = ?)
                                 OR ? = 1
                             ))
                         )
                         ORDER BY m.id ASC
                         LIMIT 40";
            $stmtNotif = $pdo->prepare($sqlNotif);
            $stmtNotif->execute([$lastNotifId, $userId, $userId, $userId, $userId, $isAdminInt]);
            $rawNotifs = $stmtNotif->fetchAll(PDO::FETCH_ASSOC);

            $maxId = $lastNotifId;
            foreach ($rawNotifs as $nm) {
                if ($nm['id'] > $maxId) $maxId = $nm['id'];
                $txt = $nm['msg_type'] === 'text' ? decrypt_data($nm['message']) : ($nm['msg_type'] === 'voice' ? 'پیام صوتی' : 'فایل');
                $newNotifs[] = [
                    'sender_id' => $nm['sender_id'],
                    'name'      => ($nm['sender_name'] === $admin_username) ? $admin_display_name : $nm['sender_name'],
                    'sticker'   => $nm['sender_sticker'],
                    'txt'       => $txt,
                    'msg_id'    => $nm['id'],
                    'room_name' => $nm['room_name']
                ];
            }

            if ($maxId > $lastNotifId) {
                $pdo->prepare("UPDATE users SET last_notif_id = ? WHERE id = ?")->execute([$maxId, $userId]);
            }
            }

            $typingContext = $targetUserId ? 'private_' . $userId : ($roomId ?: 'گفتگوی عمومی');
            $searchContext = $targetUserId ? 'private_' . $targetUserId : ($roomId ?: 'گفتگوی عمومی');

            $stmtTyping = $pdo->prepare("SELECT id, username, typing_context FROM users WHERE typing_context = ? AND typing_time > ? AND id != ?");
            $stmtTyping->execute([$searchContext, time() - 8, $userId]);
            $typingUsers = [];
            while ($t = $stmtTyping->fetch(PDO::FETCH_ASSOC)) {
                $tIsPrivate = strpos($t['typing_context'] ?? '', 'private_') === 0;
                $typingUsers[] = [
                    'user_id' => (int)$t['id'],
                    'username' => $t['username'],
                    'target_user' => $tIsPrivate ? $userId : null,
                    'room' => $tIsPrivate ? null : ($roomId ?: 'گفتگوی عمومی')
                ];
            }

            // Demo visitors only ever see other demo visitors, and real users
            // never see demo visitors. Two separate worlds, one query.
            // A demo visitor is alone in the sandbox: showing the other live
            // demo sessions would leak names and confuse the isolation.
            $demoFilter = $isDemoUser ? ("u.id = " . (int)$userId) : "u.is_demo = 0";
            $onlineStmt = $pdo->prepare(
                "SELECT DISTINCT u.id, u.username, u.sticker, u.last_activity, u.is_banned_until, u.is_online
                 FROM users u
                 WHERE $demoFilter
                   AND u.is_banned_until < UNIX_TIMESTAMP()
                   AND (
                     u.last_activity > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
                     OR EXISTS (
                         SELECT 1 FROM messages m
                         WHERE (m.sender_id = u.id AND m.receiver_id = ?)
                            OR (m.receiver_id = u.id AND m.sender_id = ?)
                     )
                   )
                 ORDER BY u.last_activity DESC"
            );
            $onlineStmt->execute([$userId, $userId]);
            $onlineUsers = $onlineStmt->fetchAll(PDO::FETCH_ASSOC);
            $hideAdminOnline = false;
            try {
                $hideAdminOnline = ($pdo->query("SELECT value FROM settings WHERE name = 'hide_admin_online'")->fetchColumn() === '1');
            } catch (Exception $e) {}
            foreach ($onlineUsers as &$ou) {
                $isAdminRow = ($ou['username'] === $admin_username);
                $ou['display_name'] = $isAdminRow ? $admin_display_name : $ou['username'];
                if ($isAdminRow && $hideAdminOnline && (int)$ou['id'] !== $userId) {
                    $ou['is_online'] = 0;
                    $ou['status_hidden'] = true;
                    $ou['last_activity'] = null;
                }
            }
            unset($ou);

            $unreadStmt = $pdo->prepare("SELECT sender_id, COUNT(*) as count FROM messages WHERE receiver_id = ? AND is_read = 0 GROUP BY sender_id");
            $unreadStmt->execute([$userId]);
            $unreadCounts = $unreadStmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $filteredRooms = [];
            $myInvites = [];
            $settings = [];
            $globalAlert = null;

            if (!$lightFetch) {
                $roomsStmt = $pdo->query("SELECT r.*, u.username as creator FROM rooms r LEFT JOIN users u ON r.created_by = u.id ORDER BY r.id DESC");
                $allRooms = $roomsStmt->fetchAll(PDO::FETCH_ASSOC);
                $stmtMyInvites = $pdo->prepare("SELECT room_name FROM room_invites WHERE user_id = ?");
                $stmtMyInvites->execute([$userId]);
                $myInvites = $stmtMyInvites->fetchAll(PDO::FETCH_COLUMN);
                foreach ($allRooms as $room) {
                    // The demo room is a sandbox, not a real room: it is shown
                    // only to demo visitors, and demo visitors see nothing else.
                    $isDemoRoom = ($room['name'] === VEYRAX_DEMO_ROOM);
                    if ($isDemoUser !== $isDemoRoom) continue;

                    $isPublicChannel = ($room['type'] === 'public' && isset($room['room_kind']) && $room['room_kind'] === 'channel');
                    $isPublicRoom = ($room['type'] === 'public');
                    if ($isPublicRoom || $isPublicChannel || $isAdmin || in_array($room['name'], $myInvites)) {
                        if (!$room['creator']) $room['creator'] = 'سیستم';
                        $room['room_kind'] = $room['room_kind'] ?? 'room';
                        $room['is_channel_creator'] = (isset($room['created_by']) && (int)$room['created_by'] === $userId) ? 1 : 0;
                        $filteredRooms[] = $room;
                    }
                }

                $stmtSet = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
                while ($row = $stmtSet->fetch()) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }

                $stmtAlert = $pdo->query("SELECT id, message FROM global_alerts ORDER BY id DESC LIMIT 1");
                $globalAlert = $stmtAlert->fetch(PDO::FETCH_ASSOC);
            }

            $pinnedMessages = [];
            if (!$lightFetch) {
                try {
                    if ($targetUserId) {
                        $stmtPinned = $pdo->prepare("SELECT pm.*, m.sender_id as msg_sender_id, m.receiver_id as msg_receiver_id FROM pinned_messages pm LEFT JOIN messages m ON pm.msg_id = m.id WHERE pm.room_name IS NULL AND (pm.receiver_id = ? OR pm.receiver_id = ?) ORDER BY pm.pinned_at ASC");
                        $stmtPinned->execute([$targetUserId, $userId]);
                    } else {
                        $safeRoomPin = ($roomId && $roomId !== 'null') ? $roomId : 'گفتگوی عمومی';
                        $stmtPinned = $pdo->prepare("SELECT pm.*, NULL as msg_sender_id, NULL as msg_receiver_id FROM pinned_messages pm WHERE pm.room_name = ? ORDER BY pm.pinned_at ASC");
                        $stmtPinned->execute([$safeRoomPin]);
                    }
                    $rawPinned = $stmtPinned->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rawPinned as $p) {
                        $fileUrl = null;
                        // Pinned text is encrypted at rest. Rows written by an
                        // older build are still plaintext: they are decrypted
                        // transparently and rewritten encrypted the first time
                        // they are read, so no manual migration is needed.
                        $pinText = (string)($p['message'] ?? '');
                        if ($pinText !== '' && veyrax_pin_text_is_cipher($pinText)) {
                            $pinPlain = decrypt_data($pinText);
                            if (is_string($pinPlain) && $pinPlain !== '') $pinText = $pinPlain;
                        } elseif ($pinText !== '') {
                            try {
                                $pdo->prepare("UPDATE pinned_messages SET message = ? WHERE id = ?")
                                    ->execute([encrypt_data($pinText), (int)$p['id']]);
                            } catch (Exception $e) {}
                        }
                        if ($p['file_token']) {
                            $fileUrl = 'file.php?token=' . $p['file_token'];
                            $ext = strtolower(pathinfo($p['file_name'] ?? '', PATHINFO_EXTENSION));
                            if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                                $pinType = 'image';
                            } elseif ($p['msg_type'] === 'voice' || in_array($ext, ['mp3', 'wav', 'ogg', 'webm', 'm4a'])) {
                                $pinType = 'voice';
                            } else {
                                $pinType = 'file';
                            }
                        } else {
                            $pinType = 'text';
                        }
                        $pinnedMessages[] = [
                            'id'          => (int)$p['msg_id'],
                            'pin_id'      => (int)$p['id'],
                            'type'        => $pinType,
                            'text'        => $pinText,
                            'file_name'   => $p['file_name'] ?? null,
                            'url'         => $fileUrl,
                            'sender'      => ($p['sender_name'] ?? '') === $admin_username ? $admin_display_name : ($p['sender_name'] ?? ''),
                            'time'        => $p['pinned_at'] ? substr($p['pinned_at'], 11, 5) : '',
                            'sender_id'   => $p['msg_sender_id'] !== null ? (int)$p['msg_sender_id'] : null,
                            'receiver_id' => $p['msg_receiver_id'] !== null ? (int)$p['msg_receiver_id'] : ($p['receiver_id'] !== null ? (int)$p['receiver_id'] : null),
                            'target_user' => $p['receiver_id'] !== null ? (int)$p['receiver_id'] : null
                        ];
                    }
                } catch (Exception $e) {
                    $pinnedMessages = [];
                }
            }

            $canPin = $isAdmin;
            if (!$canPin && $targetUserId) $canPin = true;
            if (!$canPin && $roomId) {
                $stmtOwn = $pdo->prepare("SELECT created_by FROM rooms WHERE name = ?");
                $stmtOwn->execute([$roomId]);
                $ownRow = $stmtOwn->fetch();
                if ($ownRow && $ownRow['created_by'] == $userId) $canPin = true;
            }

            $aiActiveForRoom = false;
            $aiMode = '';
            $aiUidFetch = veyrax_ai_user_id($pdo);
            if ($roomId && !$targetUserId) {
                $aiCfgFetch = veyrax_ai_cfg($pdo);
                if (($aiCfgFetch['ai_enabled'] ?? '0') === '1') {
                    if (!veyrax_is_demo_room($roomId)) {
                        try {
                            $stAiR = $pdo->prepare("SELECT ai_enabled FROM rooms WHERE name = ?");
                            $stAiR->execute([$roomId]);
                            $aiActiveForRoom = ((int)$stAiR->fetchColumn() === 1);
                        } catch (Exception $e) {}
                        if (!$aiActiveForRoom && trim((string)($aiCfgFetch['ai_rooms'] ?? '')) !== '') {
                            $aiActiveForRoom = in_array($roomId, array_map('trim', explode(',', (string)$aiCfgFetch['ai_rooms'])), true);
                        }
                        if (!$aiActiveForRoom && (($aiCfgFetch['ai_all_rooms'] ?? '1') === '1')) $aiActiveForRoom = true;
                    }
                    if ($aiActiveForRoom) $aiMode = 'room';
                }
            } elseif ($targetUserId && ($aiUidFetch ? (int)$targetUserId === (int)$aiUidFetch : false)) {
                $aiCfgFetch = veyrax_ai_cfg($pdo);
                if (($aiCfgFetch['ai_enabled'] ?? '0') === '1' && (($aiCfgFetch['ai_pv'] ?? '1') === '1')) {
                    $aiActiveForRoom = true;
                    $aiMode = 'pv_direct';
                }
            }
            // A private thread between two humans can also summon the assistant
            // (wake word / reply) when pv support is on — show the softer hint.
            if (!$aiActiveForRoom && $targetUserId) {
                $aiCfgPv = veyrax_ai_cfg($pdo);
                if (($aiCfgPv['ai_enabled'] ?? '0') === '1' && (($aiCfgPv['ai_pv'] ?? '1') === '1')) {
                    $aiMode = 'pv_keyword';
                }
            }

            $scopeRoom = $targetUserId ? '' : (($roomId && $roomId !== 'null' && $roomId !== 'public') ? $roomId : 'گفتگوی عمومی');
            $deletedIds = veyrax_recent_deletions($userId, $scopeRoom, (int)$targetUserId);

            $stmtDeleteLock = $pdo->prepare("SELECT value FROM settings WHERE name = 'delete_lock'");
            $stmtDeleteLock->execute();
            $deleteLocked = ($stmtDeleteLock->fetchColumn() === '1');

            $payload = [
                'status'          => 'success',
                'incremental'     => ($afterId > 0),
                'after_id'        => $afterId,
                'messages'        => $messages,
                'deleted'         => $deletedIds,
                'users'           => $onlineUsers,
                'unread'          => $unreadCounts,
                'notifications'   => $newNotifs,
                'typing_users'    => $typingUsers,
                'current_user_id' => $userId,
                // Sent so the client can paint its own messages with the real
                // sticker instead of a placeholder, and keep it in sync after
                // the user changes it in the profile modal.
                'current_sticker' => ($userData['sticker'] ?? '') !== '' ? $userData['sticker'] : '👤',
                'current_name'    => $userData['username'],
                'is_admin'        => $isAdmin,
                'can_pin'         => $canPin,
                'delete_lock'     => $deleteLocked,
                'bw_nonce'        => $_SESSION['bw_nonce'] ?? '',
                'ai_active'       => $aiActiveForRoom,
                'ai_mode'         => $aiMode,
                'ai_user_id'      => $aiUidFetch ? (int)$aiUidFetch : null,
                // Fingerprint of everything visible in this conversation. The
                // client keeps it and asks the long-poll to compare, which is
                // how an edit / reaction / media removal reaches the other side
                // without a manual refresh.
                'rev'             => veyrax_chat_rev($pdo, (int)$userId, (string)$scopeRoom, (int)$targetUserId),
            ];

            // Block state for the open private thread. The client uses it to
            // show the banner and to disable the composer before the user types.
            if ($targetUserId) {
                $blockState = 'none';
                try {
                    $stB1 = $pdo->prepare("SELECT 1 FROM blocked_users WHERE blocker_id = ? AND blocked_id = ? LIMIT 1");
                    $stB1->execute([$userId, $targetUserId]);
                    $iBlocked = (bool)$stB1->fetchColumn();
                    $stB2 = $pdo->prepare("SELECT 1 FROM blocked_users WHERE blocker_id = ? AND blocked_id = ? LIMIT 1");
                    $stB2->execute([$targetUserId, $userId]);
                    $theyBlocked = (bool)$stB2->fetchColumn();
                    if ($iBlocked && $theyBlocked) $blockState = 'mutual';
                    elseif ($iBlocked) $blockState = 'by_me';
                    elseif ($theyBlocked) $blockState = 'by_them';
                } catch (Exception $e) {}
                $payload['block_state'] = $blockState;
            }
            if (!$lightFetch) {
                $payload['rooms']           = $filteredRooms;
                $payload['my_invites']      = $myInvites;
                $payload['settings']        = $settings;
                $payload['global_alert']    = $isDemoUser ? null : $globalAlert;
                $payload['pinned_messages'] = $pinnedMessages;
                $payload['user_settings']   = ['notifications' => $userData['notifications_enabled']];
            }
            echo json_encode($payload);
            exit;
        }
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'خطای سرور: ' . $e->getMessage()]);
    exit;
}
?>
