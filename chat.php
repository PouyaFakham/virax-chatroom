<?php
require_once 'config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'عدم دسترسی']);
    exit;
}

$userId = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? '';
$isAdmin = ($username === $admin_username);

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

    $stmt = $pdo->prepare("SELECT is_banned_until, muted_until, last_notif_id, notifications_enabled, device_token, ip_address, user_agent FROM users WHERE id = ?");
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
        if ($rem > 31536000) {
            $msg = 'حساب شما دائماً مسدود شده است';
        } else {
            $min = ceil($rem / 60);
            $msg = "شما به مدت $min دقیقه مسدود شده‌اید و پس از آن آزاد می‌شوید";
        }
        echo json_encode(['status' => 'banned', 'message' => $msg]);
        exit;
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

            // Check auth_password
            $hasAuth = false;
            try {
                $chkAP = $pdo->query("SHOW COLUMNS FROM users LIKE 'auth_password'");
                if ($chkAP->rowCount() > 0) {
                    $stmtAP = $pdo->prepare("SELECT auth_password FROM users WHERE id = ?");
                    $stmtAP->execute([$userId]);
                    $apRow = $stmtAP->fetch();
                    $hasAuth = !empty($apRow['auth_password'] ?? '');
                }
            } catch (Exception $e) {}

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
            $roomName = trim($_POST['room_name']);

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
            $roomName = trim($_POST['room_name']);
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
            $code = trim($_POST['code']);
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
            $code = trim($_POST['code']);
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
            $context = isset($_POST['room']) && $_POST['room'] !== 'null' ? $_POST['room'] : 'private_' . $_POST['target_user'];
            $pdo->prepare("UPDATE users SET typing_context = ?, typing_time = ? WHERE id = ?")->execute([$context, time(), $userId]);
            echo json_encode(['status' => 'success']);
            exit;
        }

        if ($action === 'delete_all_msgs') {
            
            $stmtDelLock = $pdo->prepare("SELECT value FROM settings WHERE name = 'delete_lock'");
            $stmtDelLock->execute();
            if ($stmtDelLock->fetchColumn() === '1') {
                echo json_encode(['status' => 'error', 'message' => 'قابلیت حذف پیام‌ها توسط مدیر غیرفعال شده است']);
                exit;
            }

            try {
                $stmtFiles = $pdo->prepare("SELECT file_path FROM messages WHERE sender_id = ? AND file_path IS NOT NULL");
                $stmtFiles->execute([$userId]);
                $filesToDelete = $stmtFiles->fetchAll(PDO::FETCH_COLUMN);

                $pdo->beginTransaction();

                $pdo->prepare("UPDATE messages SET reply_to_id = NULL WHERE reply_to_id IN (SELECT id FROM (SELECT id FROM messages WHERE sender_id = ?) AS sub)")->execute([$userId]);

                $stmtDel = $pdo->prepare("DELETE FROM messages WHERE sender_id = ?");
                $stmtDel->execute([$userId]);
                $pdo->prepare("DELETE FROM message_reactions WHERE message_id NOT IN (SELECT id FROM messages)")->execute();
                $pdo->prepare("DELETE FROM pinned_messages WHERE msg_id NOT IN (SELECT id FROM messages)")->execute();
                $pdo->commit();

                foreach ($filesToDelete as $path) {
                    if (file_exists($path)) {
                        @unlink($path);
                    }
                }
                echo json_encode(['status' => 'success', 'message' => 'تمام پیام‌های شما با موفقیت حذف شدند']);
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
                echo json_encode(['status' => 'error', 'message' => 'قابلیت حذف پیام‌ها توسط مدیر غیرفعال شده است']);
                exit;
            }

            $msgId = (int)$_POST['id'];
            $stmt = $pdo->prepare("SELECT sender_id, file_path FROM messages WHERE id = ?");
            $stmt->execute([$msgId]);
            $msg = $stmt->fetch();

            if ($msg && ($msg['sender_id'] == $userId || $username === $admin_username)) {
                $pdo->prepare("UPDATE messages SET reply_to_id = NULL WHERE reply_to_id = ?")->execute([$msgId]);
                $pdo->prepare("DELETE FROM messages WHERE id = ?")->execute([$msgId]);
                $pdo->prepare("DELETE FROM message_reactions WHERE message_id = ?")->execute([$msgId]);
                $pdo->prepare("DELETE FROM pinned_messages WHERE msg_id = ?")->execute([$msgId]);
                if ($msg['file_path'] && file_exists($msg['file_path'])) {
                    @unlink($msg['file_path']);
                }
                echo json_encode(['status' => 'success', 'message' => 'پیام با موفقیت حذف شد']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'عدم دسترسی']);
            }
            exit;
        }

        if ($action === 'edit_msg') {
            $msgId = (int)$_POST['id'];
            $newText = trim($_POST['text'] ?? '');
            $newText = strip_tags($newText);
            $newText = htmlspecialchars($newText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (mb_strlen($newText) > 2000) {
                echo json_encode(['status' => 'error', 'message' => 'متن طولانی است']);
                exit;
            }
            $stmt = $pdo->prepare("SELECT sender_id, msg_type FROM messages WHERE id = ?");
            $stmt->execute([$msgId]);
            $msg = $stmt->fetch();
            if ($msg && $msg['sender_id'] == $userId && $msg['msg_type'] === 'text') {
                $encText = encrypt_data($newText);
                $pdo->prepare("UPDATE messages SET message = ?, is_edited = 1 WHERE id = ?")->execute([$encText, $msgId]);
                echo json_encode(['status' => 'success', 'message' => 'پیام با موفقیت ویرایش شد']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'غیرقابل ویرایش']);
            }
            exit;
        }

        if ($action === 'pin_message') {
            $msgId = (int)$_POST['msg_id'];
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

            $decryptedMsg = null;
            if (!empty($msgData['message'])) {
                try { $decryptedMsg = decrypt_data($msgData['message']); } catch (Exception $e) {}
            }

            $insertRoomName = $targetUserId ? null : ($roomName ?: 'گفتگوی عمومی');
            $insertReceiverId = $targetUserId ?: null;
            $pdo->prepare("INSERT INTO pinned_messages (msg_id, room_name, receiver_id, pinned_by, sender_name, msg_type, message, file_name, file_token) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$msgId, $insertRoomName, $insertReceiverId, $userId, $msgData['sender_name'], $msgData['msg_type'], $decryptedMsg, $msgData['file_name'], $msgData['file_token']]);

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
            if ($pinRecordId > 0) {
                $chk = $pdo->prepare("SELECT id FROM pinned_messages WHERE id = ?");
                $chk->execute([$pinRecordId]);
                if ($chk->fetch()) {
                    $pdo->prepare("DELETE FROM pinned_messages WHERE id = ?")->execute([$pinRecordId]);
                } elseif ($msgId > 0) {
                    $pdo->prepare("DELETE FROM pinned_messages WHERE msg_id = ?")->execute([$msgId]);
                }
            } elseif ($msgId > 0) {
                $pdo->prepare("DELETE FROM pinned_messages WHERE msg_id = ?")->execute([$msgId]);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'شناسه نامعتبر']); exit;
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

        if ($action === 'react') {
            $msgId = (int)$_POST['msg_id'];
            $reaction = trim($_POST['reaction']);
            
            
            $allowedReactions = ['❤️', '👍', '😂', '😮', '😢'];
            if (!in_array($reaction, $allowedReactions)) {
                echo json_encode(['status' => 'error', 'message' => 'ری‌اکشن نامعتبر']);
                exit;
            }
            
            
            $stmtMsg = $pdo->prepare("SELECT id FROM messages WHERE id = ?");
            $stmtMsg->execute([$msgId]);
            if (!$stmtMsg->fetch()) {
                echo json_encode(['status' => 'error', 'message' => 'پیام یافت نشد']);
                exit;
            }
            
            
            $stmtChk = $pdo->prepare("SELECT reaction FROM message_reactions WHERE message_id = ? AND user_id = ?");
            $stmtChk->execute([$msgId, $userId]);
            $existingReaction = $stmtChk->fetchColumn();
            
            if ($existingReaction === $reaction) {
                
                $pdo->prepare("DELETE FROM message_reactions WHERE message_id = ? AND user_id = ?")->execute([$msgId, $userId]);
                echo json_encode(['status' => 'success', 'message' => 'ری‌اکشن حذف شد', 'removed' => true]);
            } else {
                
                if ($existingReaction) {
                    $pdo->prepare("UPDATE message_reactions SET reaction = ? WHERE message_id = ? AND user_id = ?")->execute([$reaction, $msgId, $userId]);
                } else {
                    $pdo->prepare("INSERT INTO message_reactions (message_id, user_id, reaction) VALUES (?, ?, ?)")->execute([$msgId, $userId, $reaction]);
                }
                echo json_encode(['status' => 'success', 'message' => 'ری‌اکشن ثبت شد']);
            }
            exit;
        }

        if ($action === 'get_reactions') {
            $msgId = (int)$_POST['msg_id'];
            
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
            foreach ($reactions as $r) {
                if (!isset($grouped[$r['reaction']])) {
                    $grouped[$r['reaction']] = [];
                }
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
            $stmtSet = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
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
            $roomId = ($rawRoom === 'null' || $rawRoom === '') ? null : htmlspecialchars($rawRoom);
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

            $msgType = 'text';
            $messageRaw = '';
            if (isset($_POST['message'])) {
                $messageRaw = trim($_POST['message']);
                $messageRaw = strip_tags($messageRaw);
                $messageRaw = htmlspecialchars($messageRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $messageRaw = substr($messageRaw, 0, 2000);
            }
            $filePath = null;
            $fileName = null;
            $fileToken = null;

            $receiverId = (isset($_POST['receiver_id']) && is_numeric($_POST['receiver_id']) && $_POST['receiver_id'] > 0) ? (int)$_POST['receiver_id'] : null;
            $replyToId = null;
            if (isset($_POST['reply_to']) && !empty($_POST['reply_to'])) {
                $replyToId = (int)$_POST['reply_to'];
            }

            if ($roomId === 'گفتگوی عمومی') $receiverId = null;

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
                if ($rKind === 'channel' && $userId !== $rCreatedBy && !$isAdmin) {
                    echo json_encode(['status' => 'error', 'message' => 'فقط سازنده کانال می‌تواند پیام ارسال کند']);
                    exit;
                }
            }

            if (isset($_FILES['file']) && $_FILES['file']['error'] === 0) {
                if ($_FILES['file']['size'] > $file_size_limit) {
                    echo json_encode(['status' => 'error', 'message' => 'حجم فایل زیاد است (حداکثر ۵ مگابایت)']);
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
                $encryptedContent = encrypt_data($fileContent);
                if (file_put_contents($uploadPath, $encryptedContent)) {
                    $filePath = $uploadPath;
                    $fileName = $_FILES['file']['name'];
                    $fileToken = bin2hex(random_bytes(16));
                    if (isset($_POST['is_voice']) && $_POST['is_voice'] === 'true') {
                        $msgType = 'voice';
                    } else {
                        $msgType = (in_array($ext, ['mp3', 'wav', 'ogg', 'webm', 'm4a'])) ? 'voice' : 'file';
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
            $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, room_name, message, file_path, file_name, file_token, msg_type, reply_to_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $receiverId, $roomId, $messageEncrypted, $filePath, $fileName, $fileToken, $msgType, $replyToId]);
            $pdo->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?")->execute([$userId]);
            echo json_encode(['status' => 'success', 'new_msg_id' => $pdo->lastInsertId()]);
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

            $sql = "SELECT m.id, m.sender_id, m.msg_type, m.message, m.file_name, m.created_at, u.username as sender_name
                    FROM messages m
                    JOIN users u ON m.sender_id = u.id
                    WHERE m.msg_type = 'text' AND ";

            $params = [];

            if ($targetUserId) {
                $sql .= "((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)) AND m.room_name IS NULL";
                array_push($params, $userId, $targetUserId, $targetUserId, $userId);
            } else {
                $safeRoom = $roomId ?: 'گفتگوی عمومی';
                $sql .= "m.room_name = ? AND m.receiver_id IS NULL";
                $params[] = $safeRoom;
            }

            $sql .= " ORDER BY m.id DESC LIMIT 1000";

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
                $results[] = [
                    'id'          => (int)$row['id'],
                    'sender_name' => $row['sender_name'],
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

            $sql = "SELECT m.*, u.username as sender_name, u.sticker as sender_sticker,
                    rm.message as reply_message, rm.file_name as reply_file_name, rm.msg_type as reply_msg_type, ru.username as reply_sender
                    FROM messages m
                    JOIN users u ON m.sender_id = u.id
                    LEFT JOIN messages rm ON m.reply_to_id = rm.id
                    LEFT JOIN users ru ON rm.sender_id = ru.id
                    WHERE m.id < ? AND ";

            $params = [$beforeId];

            if ($targetUserId) {
                $sql .= "((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)) AND m.room_name IS NULL";
                array_push($params, $userId, $targetUserId, $targetUserId, $userId);
            } else {
                $safeRoom = $roomId ?: 'گفتگوی عمومی';
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
                
                
                $stmtReact = $pdo->prepare("
                    SELECT reaction, COUNT(*) as count, GROUP_CONCAT(u.username) as users
                    FROM message_reactions mr
                    JOIN users u ON mr.user_id = u.id
                    WHERE mr.message_id = ?
                    GROUP BY reaction
                ");
                $stmtReact->execute([$m['id']]);
                $reactions = [];
                while ($r = $stmtReact->fetch()) {
                    $reactions[$r['reaction']] = [
                        'count' => (int)$r['count'],
                        'users' => explode(',', $r['users'])
                    ];
                }
                
                $stmtMyReact = $pdo->prepare("SELECT reaction FROM message_reactions WHERE message_id = ? AND user_id = ?");
                $stmtMyReact->execute([$m['id'], $userId]);
                $myReaction = $stmtMyReact->fetchColumn();
                
                $m['reactions'] = $reactions;
                $m['my_reaction'] = $myReaction ?: null;
                
                unset($m['file_path']);
                $messages[] = $m;
            }
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

            try {
                $chkCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'auth_password'");
                if ($chkCol->rowCount() == 0) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN auth_password VARCHAR(255) DEFAULT NULL");
                }
            } catch (Exception $e) {}

            $stmt = $pdo->prepare("SELECT phone_number, auth_password, phone_auth_attempts, phone_auth_locked_until FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $uRow = $stmt->fetch();
            if (!$uRow) { echo json_encode(['status' => 'error', 'message' => 'کاربر یافت نشد']); exit; }

            $storedPhone = $uRow['phone_number'] ?? '';
            $storedPassHash = $uRow['auth_password'] ?? '';
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
                    $updates[] = 'auth_password = ?';
                    $params[] = password_hash($authPass, PASSWORD_DEFAULT);
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

        if ($action === 'phone_auth_check') {
            try {
                $chkAuthCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'auth_password'");
                if ($chkAuthCol->rowCount() == 0) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN auth_password VARCHAR(255) DEFAULT NULL");
                }
            } catch (Exception $e) {}
            $stmt = $pdo->prepare("SELECT phone_number, auth_password, phone_auth_locked_until FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $uRow = $stmt->fetch();
            $hasPhone = !empty($uRow['phone_number'] ?? '');
            $hasAuth = $hasPhone || !empty($uRow['auth_password'] ?? '');
            $lockedUntil = (int)($uRow['phone_auth_locked_until'] ?? 0);
            $isLocked = ($lockedUntil > time());
            $isVerified = isset($_SESSION['phone_verified']) && $_SESSION['phone_verified'];
            echo json_encode([
                'status' => 'success',
                'has_phone' => $hasPhone,
                'has_auth' => $hasAuth,
                'needs_verify' => !$isVerified,
                'is_locked' => $isLocked,
                'lock_remaining' => $isLocked ? ceil(($lockedUntil - time()) / 60) : 0
            ]);
            exit;
        }

        if ($action === 'fetch') {
            $pdo->prepare("UPDATE users SET is_online = 1, last_activity = NOW() WHERE id = ?")->execute([$userId]);

            $roomId = isset($_POST['room']) ? $_POST['room'] : null;
            $targetUserId = isset($_POST['target_user']) && is_numeric($_POST['target_user']) ? (int)$_POST['target_user'] : null;

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
                $sql .= "((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)) AND m.room_name IS NULL";
                $params = [$userId, $targetUserId, $targetUserId, $userId];
            } else {
                $safeRoom = ($roomId && $roomId !== 'null') ? $roomId : 'گفتگوی عمومی';
                $sql .= "m.room_name = ? AND m.receiver_id IS NULL";
                $params = [$safeRoom];
            }

            $sql .= " ORDER BY m.created_at DESC LIMIT 20";

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
                
                
                $stmtReact = $pdo->prepare("
                    SELECT reaction, COUNT(*) as count, GROUP_CONCAT(u.username) as users
                    FROM message_reactions mr
                    JOIN users u ON mr.user_id = u.id
                    WHERE mr.message_id = ?
                    GROUP BY reaction
                ");
                $stmtReact->execute([$m['id']]);
                $reactions = [];
                while ($r = $stmtReact->fetch()) {
                    $reactions[$r['reaction']] = [
                        'count' => (int)$r['count'],
                        'users' => explode(',', $r['users'])
                    ];
                }
                
                
                $stmtMyReact = $pdo->prepare("SELECT reaction FROM message_reactions WHERE message_id = ? AND user_id = ?");
                $stmtMyReact->execute([$m['id'], $userId]);
                $myReaction = $stmtMyReact->fetchColumn();
                
                $m['reactions'] = $reactions;
                $m['my_reaction'] = $myReaction ?: null;
                
                unset($m['file_path']);
                $messages[] = $m;
            }

            $lastNotifId = $userData['last_notif_id'] ?? 0;
            $newNotifs = [];

            $isAdminInt = ($username === $admin_username) ? 1 : 0;

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
                         )";
            $stmtNotif = $pdo->prepare($sqlNotif);
            $stmtNotif->execute([$lastNotifId, $userId, $userId, $userId, $userId, $isAdminInt]);
            $rawNotifs = $stmtNotif->fetchAll(PDO::FETCH_ASSOC);

            $maxId = $lastNotifId;
            foreach ($rawNotifs as $nm) {
                if ($nm['id'] > $maxId) $maxId = $nm['id'];
                $txt = $nm['msg_type'] === 'text' ? decrypt_data($nm['message']) : ($nm['msg_type'] === 'voice' ? 'پیام صوتی' : 'فایل');
                $newNotifs[] = [
                    'sender_id' => $nm['sender_id'],
                    'name'      => $nm['sender_name'],
                    'sticker'   => $nm['sender_sticker'],
                    'txt'       => $txt,
                    'msg_id'    => $nm['id'],
                    'room_name' => $nm['room_name']
                ];
            }

            if ($maxId > $lastNotifId) {
                $pdo->prepare("UPDATE users SET last_notif_id = ? WHERE id = ?")->execute([$maxId, $userId]);
            }

            $typingContext = $targetUserId ? 'private_' . $userId : ($roomId ?: 'گفتگوی عمومی');
            $searchContext = $targetUserId ? 'private_' . $targetUserId : ($roomId ?: 'گفتگوی عمومی');

            $stmtTyping = $pdo->prepare("SELECT username FROM users WHERE typing_context = ? AND typing_time > ? AND id != ?");
            $stmtTyping->execute([$searchContext, time() - 3, $userId]);
            $typingUsers = $stmtTyping->fetchAll(PDO::FETCH_COLUMN);

            $onlineStmt = $pdo->prepare(
                "SELECT DISTINCT u.id, u.username, u.sticker, u.last_activity, u.is_banned_until, u.is_online
                 FROM users u
                 WHERE u.is_banned_until < UNIX_TIMESTAMP()
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

            $unreadStmt = $pdo->prepare("SELECT sender_id, COUNT(*) as count FROM messages WHERE receiver_id = ? AND is_read = 0 GROUP BY sender_id");
            $unreadStmt->execute([$userId]);
            $unreadCounts = $unreadStmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $roomsStmt = $pdo->query("SELECT r.*, u.username as creator FROM rooms r LEFT JOIN users u ON r.created_by = u.id ORDER BY r.id DESC");
            $allRooms = $roomsStmt->fetchAll(PDO::FETCH_ASSOC);
            $stmtMyInvites = $pdo->prepare("SELECT room_name FROM room_invites WHERE user_id = ?");
            $stmtMyInvites->execute([$userId]);
            $myInvites = $stmtMyInvites->fetchAll(PDO::FETCH_COLUMN);
            $filteredRooms = [];
            foreach ($allRooms as $room) {
                $isPublicChannel = ($room['type'] === 'public' && isset($room['room_kind']) && $room['room_kind'] === 'channel');
                $isPublicRoom = ($room['type'] === 'public');
                if ($isPublicRoom || $isPublicChannel || $isAdmin || in_array($room['name'], $myInvites)) {
                    if (!$room['creator']) $room['creator'] = 'سیستم';
                    $room['room_kind'] = $room['room_kind'] ?? 'room';
                    $room['is_channel_creator'] = (isset($room['created_by']) && (int)$room['created_by'] === $userId) ? 1 : 0;
                    $filteredRooms[] = $room;
                }
            }

            $settings = [];
            $stmtSet = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
            while ($row = $stmtSet->fetch()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }

            $stmtAlert = $pdo->query("SELECT id, message FROM global_alerts ORDER BY id DESC LIMIT 1");
            $globalAlert = $stmtAlert->fetch(PDO::FETCH_ASSOC);

            $pinnedMessages = [];
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
                        'text'        => $p['message'] ?? '',
                        'file_name'   => $p['file_name'] ?? null,
                        'url'         => $fileUrl,
                        'sender'      => $p['sender_name'] ?? '',
                        'time'        => $p['pinned_at'] ? substr($p['pinned_at'], 11, 5) : '',
                        'sender_id'   => $p['msg_sender_id'] !== null ? (int)$p['msg_sender_id'] : null,
                        'receiver_id' => $p['msg_receiver_id'] !== null ? (int)$p['msg_receiver_id'] : ($p['receiver_id'] !== null ? (int)$p['receiver_id'] : null),
                        'target_user' => $p['receiver_id'] !== null ? (int)$p['receiver_id'] : null
                    ];
                }
            } catch (Exception $e) {
                $pinnedMessages = [];
            }

            $canPin = $isAdmin;
            if (!$canPin && $targetUserId) $canPin = true;
            if (!$canPin && $roomId) {
                $stmtOwn = $pdo->prepare("SELECT created_by FROM rooms WHERE name = ?");
                $stmtOwn->execute([$roomId]);
                $ownRow = $stmtOwn->fetch();
                if ($ownRow && $ownRow['created_by'] == $userId) $canPin = true;
            }

            echo json_encode([
                'status'          => 'success',
                'messages'        => $messages,
                'users'           => $onlineUsers,
                'rooms'           => $filteredRooms,
                'my_invites'      => $myInvites,
                'unread'          => $unreadCounts,
                'notifications'   => $newNotifs,
                'typing_users'    => $typingUsers,
                'settings'        => $settings,
                'global_alert'    => $globalAlert,
                'current_user_id' => $userId,
                'user_settings'   => ['notifications' => $userData['notifications_enabled']],
                'is_admin'        => $isAdmin,
                'can_pin'         => $canPin,
                'bw_nonce'        => $_SESSION['bw_nonce'] ?? '',
                'pinned_messages' => $pinnedMessages
            ]);
            exit;
        }
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'خطای سرور: ' . $e->getMessage()]);
    exit;
}
?>
