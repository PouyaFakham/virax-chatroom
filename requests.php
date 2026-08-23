<?php
require_once 'config.php';
header('Content-Type: application/json');

if (isset($_GET['logout'])) {
    if (!isset($_GET['nonce']) || !isset($_SESSION['logout_nonce']) || $_GET['nonce'] !== $_SESSION['logout_nonce']) {
        echo json_encode(['status' => 'error', 'message' => 'درخواست نامعتبر']);
        exit;
    }
    if (isset($_SESSION['user_id'])) {
        $pdo->prepare("UPDATE users SET last_activity = NOW(), is_online = 0 WHERE id = ?")->execute([$_SESSION['user_id']]);
        if (isset($_SESSION['session_token'])) {
            $pdo->prepare("DELETE FROM user_sessions WHERE session_token = ?")->execute([$_SESSION['session_token']]);
        }
    }
    session_unset();
    session_destroy();
    setcookie('admin_token', '', time() - 3600, '/');
    echo json_encode(['status' => 'success']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Request']);
    exit;
}

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    echo json_encode(['status' => 'error', 'message' => 'نشست منقضی شده است']);
    exit;
}

if (isset($_POST['action'])) {

    if ($_POST['action'] === 'check_username_availability') {
        $u = trim(preg_replace('/\s+/', ' ', $_POST['username'] ?? ''));
        $u = xss_clean($u);
        if (empty($u)) { echo json_encode(['status' => 'empty']); exit; }
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$u]);
        if ($stmt->fetch()) {
            echo json_encode(['status' => 'taken', 'message' => 'این نام کاربری قبلاً ثبت شده است']);
        } else {
            echo json_encode(['status' => 'available']);
        }
        exit;
    }

    if ($_POST['action'] === 'report_user') {
        if (!isset($_SESSION['user_id'])) { echo json_encode(['status' => 'error', 'message' => 'عدم دسترسی']); exit; }

        $stmtLock = $pdo->prepare("SELECT value FROM settings WHERE name = 'report_lock'");
        $stmtLock->execute();
        if ($stmtLock->fetchColumn() === '1') { echo json_encode(['status' => 'error', 'message' => 'سیستم گزارش‌دهی موقتاً غیرفعال شده است']); exit; }

        if (!isset($_POST['captcha']) || !isset($_SESSION['report_captcha_result']) || $_POST['captcha'] != $_SESSION['report_captcha_result']) {
            echo json_encode(['status' => 'error', 'message' => 'کد امنیتی اشتباه است']);
            exit;
        }

        $tgt = xss_clean(trim(preg_replace('/\s+/', ' ', $_POST['target_username'] ?? '')));
        $reason = xss_clean(trim($_POST['reason'] ?? ''));

        if (empty($tgt) || empty($reason)) { echo json_encode(['status' => 'error', 'message' => 'لطفاً نام کاربر و دلیل گزارش را وارد کنید']); exit; }

        $stmtTargetLookup = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmtTargetLookup->execute([$tgt]);
        $tgtId = $stmtTargetLookup->fetchColumn();

        if (!$tgtId) { echo json_encode(['status' => 'error', 'message' => 'کاربر مورد نظر یافت نشد']); exit; }
        if ($tgtId == $_SESSION['user_id']) { echo json_encode(['status' => 'error', 'message' => 'شما نمی‌توانید خودتان را گزارش کنید']); exit; }

        $stmtExisting = $pdo->prepare("SELECT id FROM user_reports WHERE reporter_id = ? AND reported_id = ? AND status = 'pending'");
        $stmtExisting->execute([$_SESSION['user_id'], $tgtId]);
        if ($stmtExisting->fetch()) { echo json_encode(['status' => 'error', 'message' => 'شما قبلاً این کاربر را گزارش داده‌اید.']); exit; }

        try {
            $pdo->prepare("INSERT INTO user_reports (reporter_id, reported_id, reason, status, created_at) VALUES (?, ?, ?, 'pending', NOW())")
                ->execute([$_SESSION['user_id'], $tgtId, $reason]);
            echo json_encode(['status' => 'success', 'message' => 'گزارش شما با موفقیت ثبت شد']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'خطا در ثبت گزارش']);
        }
        exit;
    }

    if ($_POST['action'] === 'invite_user') {
        if (!isset($_SESSION['user_id'])) { echo json_encode(['status' => 'error', 'message' => 'عدم دسترسی']); exit; }

        $targetUser = xss_clean(trim(preg_replace('/\s+/', ' ', $_POST['username'] ?? '')));
        $roomName = xss_clean(trim($_POST['room_name'] ?? ''));

        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$targetUser]);
        $uid = $stmt->fetchColumn();
        if (!$uid) { echo json_encode(['status' => 'error', 'message' => 'کاربر یافت نشد']); exit; }

        $chk = $pdo->prepare("SELECT id FROM room_invites WHERE user_id = ? AND room_name = ?");
        $chk->execute([$uid, $roomName]);
        if ($chk->fetch()) { echo json_encode(['status' => 'error', 'message' => 'کاربر قبلاً دعوت شده است']); exit; }

        $pdo->prepare("INSERT INTO room_invites (user_id, room_name, invited_by) VALUES (?, ?, ?)")->execute([$uid, $roomName, $_SESSION['user_id']]);
        echo json_encode(['status' => 'success', 'message' => 'دعوت‌نامه با موفقیت ارسال شد']);
        exit;
    }

    if ($_POST['action'] === 'save_phone_number') {
        if (!isset($_SESSION['user_id'])) { echo json_encode(['status' => 'error', 'message' => 'عدم دسترسی']); exit; }
        $phone    = trim($_POST['phone_number'] ?? '');
        $authPass = trim($_POST['auth_password'] ?? '');

        if (empty($phone) && empty($authPass)) {
            echo json_encode(['status' => 'error', 'message' => 'شماره موبایل یا رمز احراز هویت را وارد کنید']);
            exit;
        }
        if (!empty($phone) && !preg_match('/^09[0-9]{9}$/', $phone)) {
            echo json_encode(['status' => 'error', 'message' => 'شماره موبایل باید ۱۱ رقم و با ۰۹ شروع شود']);
            exit;
        }
        if (!empty($authPass)) {
            if (mb_strlen($authPass) < 8 || mb_strlen($authPass) > 20) {
                echo json_encode(['status' => 'error', 'message' => 'رمز احراز هویت باید بین ۸ تا ۲۰ کاراکتر باشد']);
                exit;
            }
            if (preg_match('/[\x{0600}-\x{06FF}]/u', $authPass)) {
                echo json_encode(['status' => 'error', 'message' => 'رمز احراز هویت نباید شامل حروف فارسی باشد']);
                exit;
            }
        }
        // Ensure auth_password column exists
        try {
            $chkAP = $pdo->query("SHOW COLUMNS FROM users LIKE 'auth_password'");
            if ($chkAP->rowCount() == 0) {
                $pdo->exec("ALTER TABLE users ADD COLUMN auth_password VARCHAR(255) DEFAULT NULL");
            }
        } catch (Exception $e) {}

        $updates = []; $params = [];
        if (!empty($phone))    { $updates[] = 'phone_number = ?';  $params[] = $phone; }
        if (!empty($authPass)) { $updates[] = 'auth_password = ?'; $params[] = password_hash($authPass, PASSWORD_DEFAULT); }
        if (!empty($updates)) {
            $params[] = $_SESSION['user_id'];
            $pdo->prepare("UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
        }
        $_SESSION['phone_verified'] = true;
        $parts = [];
        if (!empty($phone))    $parts[] = 'شماره موبایل';
        if (!empty($authPass)) $parts[] = 'رمز احراز هویت';
        echo json_encode(['status' => 'success', 'message' => implode(' و ', $parts) . ' با موفقیت ذخیره شد']);
        exit;
    }

    if ($_POST['action'] === 'change_phone_number') {
        if (!isset($_SESSION['user_id'])) { echo json_encode(['status' => 'error', 'message' => 'عدم دسترسی']); exit; }
        $phone = trim($_POST['phone_number'] ?? '');
        if (!preg_match('/^09[0-9]{9}$/', $phone)) {
            echo json_encode(['status' => 'error', 'message' => 'شماره موبایل باید ۱۱ رقم و با ۰۹ شروع شود']);
            exit;
        }
        $secCode = xss_clean(trim($_POST['security_code'] ?? ''));
        $stmt = $pdo->prepare("SELECT security_code FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $sc = $stmt->fetchColumn();
        if ($sc !== $secCode) {
            echo json_encode(['status' => 'error', 'message' => 'کد امنیتی اشتباه است']);
            exit;
        }
        $pdo->prepare("UPDATE users SET phone_number = ? WHERE id = ?")->execute([$phone, $_SESSION['user_id']]);
        echo json_encode(['status' => 'success', 'message' => 'شماره موبایل با موفقیت تغییر یافت']);
        exit;
    }

    if ($_POST['action'] === 'login_phone_verify') {
        $u = xss_clean(trim(preg_replace('/\s+/', ' ', $_POST['username'] ?? '')));
        $otp = trim($_POST['otp'] ?? '');

        if (empty($u) || empty($otp)) { echo json_encode(['status' => 'error', 'message' => 'اطلاعات ناقص']); exit; }
        if (!isset($_SESSION['phone_otp_code']) || !isset($_SESSION['phone_otp_username'])) { echo json_encode(['status' => 'error', 'message' => 'کد تأیید منقضی شده است']); exit; }
        if ($_SESSION['phone_otp_username'] !== $u) { echo json_encode(['status' => 'error', 'message' => 'نام کاربری مطابقت ندارد']); exit; }
        if (time() > ($_SESSION['phone_otp_expires'] ?? 0)) {
            unset($_SESSION['phone_otp_code'], $_SESSION['phone_otp_username'], $_SESSION['phone_otp_expires']);
            echo json_encode(['status' => 'error', 'message' => 'کد تأیید منقضی شده است. دوباره تلاش کنید']);
            exit;
        }
        if ($_SESSION['phone_otp_code'] !== $otp) { echo json_encode(['status' => 'error', 'message' => 'کد تأیید اشتباه است']); exit; }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$u]);
        $user = $stmt->fetch();
        if (!$user) { echo json_encode(['status' => 'error', 'message' => 'کاربر یافت نشد']); exit; }

        unset($_SESSION['phone_otp_code'], $_SESSION['phone_otp_username'], $_SESSION['phone_otp_expires']);

        $ip = get_client_ip();
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 500);
        $deviceToken = bin2hex(random_bytes(32));
        setcookie('device_token', $deviceToken, time() + 259200, '/', '', isset($_SERVER['HTTPS']), true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $u;
        $_SESSION['sticker'] = $user['sticker'];
        $pdo->prepare("UPDATE users SET last_activity = NOW(), is_online = 1, user_agent = ?, ip_address = ?, device_token = ? WHERE id = ?")->execute([$ua, $ip, $deviceToken, $user['id']]);

        session_regenerate_id(true);
        $_SESSION['phone_verified'] = true;
        $_SESSION['spam_check'] = [];
        $_SESSION['bw_nonce'] = bin2hex(random_bytes(16));
        $_SESSION['logout_nonce'] = bin2hex(random_bytes(16));
        $st = bin2hex(random_bytes(32));
        $_SESSION['session_token'] = $st;
        try {
            $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ? AND last_activity < DATE_SUB(NOW(), INTERVAL 3 DAY)")->execute([$user['id']]);
            $pdo->prepare("INSERT INTO user_sessions (session_token, user_id, ip_address, user_agent) VALUES (?, ?, ?, ?)")->execute([$st, $user['id'], $ip, $ua]);
        } catch (Exception $e) {}

        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_POST['action'] === 'request_phone_otp') {
        $u = xss_clean(trim(preg_replace('/\s+/', ' ', $_POST['username'] ?? '')));
        $stmt = $pdo->prepare("SELECT id, phone_number, is_banned_until FROM users WHERE username = ?");
        $stmt->execute([$u]);
        $user = $stmt->fetch();
        if (!$user || empty($user['phone_number'])) {
            echo json_encode(['status' => 'error', 'message' => 'این حساب شماره موبایل ندارد. از کد امنیتی استفاده کنید.']);
            exit;
        }
        if ($user['is_banned_until'] > time()) { echo json_encode(['status' => 'error', 'message' => 'حساب مسدود است']); exit; }
        $otp = str_pad(rand(10000, 99999), 5, '0', STR_PAD_LEFT);
        $_SESSION['phone_otp_code'] = $otp;
        $_SESSION['phone_otp_username'] = $u;
        $_SESSION['phone_otp_expires'] = time() + 180;
        $maskedPhone = substr($user['phone_number'], 0, 4) . '***' . substr($user['phone_number'], -4);
        echo json_encode([
            'status' => 'success',
            'otp' => $otp,
            'phone_masked' => $maskedPhone,
            'message' => 'کد تأیید: ' . $otp
        ]);
        exit;
    }

    if ($_POST['action'] === 'refresh_captcha') {
        $n1 = rand(10, 50);
        $n2 = rand(1, 9);
        $_SESSION['captcha_result'] = $n1 + $n2;
        echo json_encode(['status' => 'success', 'n1' => $n1, 'n2' => $n2]);
        exit;
    }

    if ($_POST['action'] === 'refresh_report_captcha') {
        $n1 = rand(1, 9);
        $n2 = rand(1, 9);
        $_SESSION['report_captcha_result'] = $n1 + $n2;
        echo json_encode(['status' => 'success', 'n1' => $n1, 'n2' => $n2]);
        exit;
    }

    if ($_POST['action'] === 'login_check') {
        $u = xss_clean(trim(preg_replace('/\s+/', ' ', $_POST['username'] ?? '')));
        if ($u === $admin_username || is_admin_user($u, $pdo)) {
            echo json_encode(['status' => 'password_required']);
        } else {
            echo json_encode(['status' => 'proceed']);
        }
        exit;
    }

    if ($_POST['action'] === 'login_with_code') {
        if (!isset($_POST['captcha']) || $_POST['captcha'] != $_SESSION['captcha_result']) {
            echo json_encode(['status' => 'error', 'message' => 'کد امنیتی اشتباه است']);
            exit;
        }

        $u = xss_clean(trim(preg_replace('/\s+/', ' ', $_POST['username'] ?? '')));
        $secCode = xss_clean(trim($_POST['security_code'] ?? ''));
        $ip = get_client_ip();
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 500);

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$u]);
        $user = $stmt->fetch();

        if (!$user) { echo json_encode(['status' => 'error', 'message' => 'کاربر یافت نشد']); exit; }
        if ($user['security_code'] !== $secCode) { echo json_encode(['status' => 'error', 'message' => 'کد امنیتی وارد شده اشتباه است']); exit; }
        if ($user['is_banned_until'] > time()) { echo json_encode(['status' => 'banned', 'ban_type' => 'temp', 'message' => 'حساب شما مسدود است.']); exit; }

        $deviceToken = bin2hex(random_bytes(32));
        setcookie('device_token', $deviceToken, time() + 259200, '/', '', isset($_SERVER['HTTPS']), true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $u;
        $_SESSION['sticker'] = $user['sticker'];

        $pdo->prepare("UPDATE users SET last_activity = NOW(), is_online = 1, user_agent = ?, ip_address = ?, device_token = ? WHERE id = ?")->execute([$ua, $ip, $deviceToken, $user['id']]);

        session_regenerate_id(true);
        $_SESSION['phone_verified'] = false;
        $_SESSION['spam_check'] = [];
        $_SESSION['bw_nonce'] = bin2hex(random_bytes(16));
        $_SESSION['logout_nonce'] = bin2hex(random_bytes(16));
        $lwcToken = bin2hex(random_bytes(32));
        $_SESSION['session_token'] = $lwcToken;
        try {
            $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ? AND last_activity < DATE_SUB(NOW(), INTERVAL 3 DAY)")->execute([$user['id']]);
            $pdo->prepare("INSERT INTO user_sessions (session_token, user_id, ip_address, user_agent) VALUES (?, ?, ?, ?)")->execute([$lwcToken, $user['id'], $ip, $ua]);
        } catch (Exception $e) {}
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_POST['action'] === 'login') {
        if (!isset($_POST['captcha']) || $_POST['captcha'] != $_SESSION['captcha_result']) {
            echo json_encode(['status' => 'error', 'message' => 'کد امنیتی (کپچا) اشتباه است']);
            exit;
        }

        $u = xss_clean(trim(preg_replace('/\s+/', ' ', $_POST['username'] ?? '')));
        $ip = get_client_ip();

        $stmtIp = $pdo->prepare("SELECT id FROM banned_ips WHERE ip_address = ?");
        $stmtIp->execute([$ip]);
        if ($stmtIp->fetch()) {
            echo json_encode(['status' => 'banned', 'ban_type' => 'perm', 'message' => 'دسترسی دستگاه شما به دلیل تخلف مسدود شده است (بن دائمی)']);
            exit;
        }

        $isAdminLogin = ($u === $admin_username || is_admin_user($u, $pdo));

        if ($u === $admin_username) {
            if (!isset($_POST['password']) || $_POST['password'] !== $admin_password) {
                echo json_encode(['status' => 'error', 'message' => 'رمز عبور مدیریت اشتباه است']);
                exit;
            }
        } elseif (is_admin_user($u, $pdo)) {
            $stmtSA = $pdo->prepare("SELECT password_hash FROM admin_users WHERE username = ? AND is_active = 1");
            $stmtSA->execute([$u]);
            $saRow = $stmtSA->fetch();
            if (!$saRow || !password_verify($_POST['password'] ?? '', $saRow['password_hash'])) {
                echo json_encode(['status' => 'error', 'message' => 'رمز عبور اشتباه است']);
                exit;
            }
        } else {
            $len = mb_strlen($u, 'UTF-8');
            if ($len < 3 || $len > 10) {
                echo json_encode(['status' => 'error', 'message' => 'نام کاربری باید بین ۳ تا ۱۰ کاراکتر باشد']);
                exit;
            }
            if (!preg_match('/^[a-zA-Z0-9\x{0600}-\x{06FF}\s]+$/u', $u)) {
                echo json_encode(['status' => 'error', 'message' => 'نام کاربری فقط می‌تواند شامل حروف فارسی، انگلیسی و اعداد باشد']);
                exit;
            }
        }

        $stmt = $pdo->prepare("SELECT id, is_banned_until, sticker, ip_address, user_agent, device_token, security_code, phone_number FROM users WHERE username = ?");
        $stmt->execute([$u]);
        $user = $stmt->fetch();

        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 500);

        $deviceToken = null;
        if (isset($_COOKIE['device_token'])) {
            $deviceToken = $_COOKIE['device_token'];
        } else {
            $deviceToken = bin2hex(random_bytes(32));
            setcookie('device_token', $deviceToken, time() + 259200, '/', '', isset($_SERVER['HTTPS']), true);
        }

        $generatedSecurityCode = null;

        if ($user) {
            if ($user['is_banned_until'] > time()) {
                $rem = $user['is_banned_until'] - time();
                $banType = $rem > 31536000 ? 'perm' : 'temp';
                $msg = $banType === 'perm' ? 'حساب شما دائماً مسدود شده است' : 'حساب شما به مدت ' . ceil($rem / 60) . ' دقیقه دیگر مسدود است';
                echo json_encode(['status' => 'banned', 'ban_type' => $banType, 'message' => $msg]);
                exit;
            }

            if (!$isAdminLogin) {
                $tokenMatch = ($deviceToken && $user['device_token'] && $deviceToken === $user['device_token']);
                $ipMatch = ($user['ip_address'] === $ip);
                $uaMatch = ($user['user_agent'] === $ua);
                if (!$tokenMatch && !($ipMatch && $uaMatch)) {
                    $hasPhone = !empty($user['phone_number']);
                    echo json_encode([
                        'status' => 'device_mismatch',
                        'message' => 'این نام کاربری متعلق به دستگاه دیگری است.' . ($hasPhone ? ' برای احراز هویت از کد امنیتی استفاده کنید.' : ' از کد امنیتی استفاده کنید.'),
                        'has_phone' => $hasPhone,
                        'username' => $u
                    ]);
                    exit;
                }
            }

            if (empty($user['security_code']) && !$isAdminLogin) {
                $generatedSecurityCode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                $pdo->prepare("UPDATE users SET security_code = ? WHERE id = ?")->execute([$generatedSecurityCode, $user['id']]);
            }

            if ($isAdminLogin) {
                $hideOnline = false;
                try {
                    $stmtHide = $pdo->prepare("SELECT value FROM settings WHERE name = 'hide_admin_online'");
                    $stmtHide->execute();
                    $hideOnline = ($stmtHide->fetchColumn() === '1');
                } catch (Exception $e) {}
                $adminSticker = '✔️';
                $pdo->prepare("UPDATE users SET sticker = ? WHERE id = ?")->execute([$adminSticker, $user['id']]);
                $_SESSION['sticker'] = $adminSticker;
                $pdo->prepare("UPDATE users SET is_online = ? WHERE id = ?")->execute([$hideOnline ? 0 : 1, $user['id']]);
            } elseif (empty($user['sticker'])) {
                $randSticker = $stickers[array_rand($stickers)];
                $pdo->prepare("UPDATE users SET sticker = ? WHERE id = ?")->execute([$randSticker, $user['id']]);
                $_SESSION['sticker'] = $randSticker;
            } else {
                $_SESSION['sticker'] = $user['sticker'];
            }

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $u;

            $stmt = $pdo->prepare("SELECT id FROM messages ORDER BY id DESC LIMIT 1");
            $stmt->execute();
            $lastId = $stmt->fetchColumn() ?: 0;

            $pdo->prepare("UPDATE users SET last_activity = NOW(), is_online = 1, user_agent = ?, ip_address = ?, last_notif_id = ?, device_token = ? WHERE id = ?")->execute([$ua, $ip, $lastId, $deviceToken, $user['id']]);

            // For trusted-device logins, auto-verify if user already has auth registered
            if (!isset($_SESSION['phone_verified'])) {
                try {
                    $stmtAuthChk = $pdo->prepare("SELECT phone_number, auth_password FROM users WHERE id = ?");
                    $stmtAuthChk->execute([$user['id']]);
                    $authChkRow = $stmtAuthChk->fetch();
                    $hasAuthRegistered = !empty($authChkRow['phone_number']) || !empty($authChkRow['auth_password'] ?? '');
                    // Trusted device = same token OR same IP+UA
                    $tokenMatchHere = ($deviceToken && $user['device_token'] && $deviceToken === $user['device_token']);
                    $ipMatchHere = ($user['ip_address'] === $ip);
                    $uaMatchHere = ($user['user_agent'] === $ua);
                    $isTrustedDevice = $tokenMatchHere || ($ipMatchHere && $uaMatchHere);
                    $_SESSION['phone_verified'] = ($isTrustedDevice && $hasAuthRegistered) || $isAdminLogin;
                } catch (Exception $e) {
                    $_SESSION['phone_verified'] = $isAdminLogin;
                }
            }

            $needsPhone = empty($user['phone_number']);
            echo json_encode([
                'status' => 'success',
                'security_code' => $generatedSecurityCode,
                'needs_phone' => $needsPhone,
            ]);
        } else {
            $deviceTokenCookie = $_COOKIE['device_token'] ?? '';

            $stmtMaxU = $pdo->prepare("SELECT value FROM settings WHERE name = 'max_users'");
            $stmtMaxU->execute();
            $maxUsersRow = $stmtMaxU->fetchColumn();
            $maxUsersPerDevice = ($maxUsersRow !== false && (int)$maxUsersRow > 0) ? (int)$maxUsersRow : 10;

            if (!empty($deviceTokenCookie)) {
                $stmtCheckLimit = $pdo->prepare("SELECT COUNT(*) FROM users WHERE (device_token = ? OR (ip_address = ? AND user_agent = ?)) AND username != ?");
                $stmtCheckLimit->execute([$deviceTokenCookie, $ip, $ua, $admin_username]);
            } else {
                $stmtCheckLimit = $pdo->prepare("SELECT COUNT(*) FROM users WHERE ip_address = ? AND user_agent = ? AND username != ?");
                $stmtCheckLimit->execute([$ip, $ua, $admin_username]);
            }
            $cnt = (int)$stmtCheckLimit->fetchColumn();

            if ($cnt >= $maxUsersPerDevice) {
                if (!empty($deviceTokenCookie)) {
                    $stmtPrev = $pdo->prepare("SELECT DISTINCT username, sticker FROM users WHERE (device_token = ? OR (ip_address = ? AND user_agent = ?)) AND username != ? ORDER BY last_activity DESC LIMIT 5");
                    $stmtPrev->execute([$deviceTokenCookie, $ip, $ua, $admin_username]);
                } else {
                    $stmtPrev = $pdo->prepare("SELECT DISTINCT username, sticker FROM users WHERE ip_address = ? AND user_agent = ? AND username != ? ORDER BY last_activity DESC LIMIT 5");
                    $stmtPrev->execute([$ip, $ua, $admin_username]);
                }
                $prevAccounts = $stmtPrev->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['status' => 'capacity_full', 'previous_users' => $prevAccounts]);
                exit;
            }

            $randSticker = $isAdminLogin ? '✔️' : $stickers[array_rand($stickers)];

            $stmt = $pdo->prepare("SELECT id FROM messages ORDER BY id DESC LIMIT 1");
            $stmt->execute();
            $lastId = $stmt->fetchColumn() ?: 0;

            $generatedSecurityCode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

            $stmt = $pdo->prepare("INSERT INTO users (username, sticker, user_agent, ip_address, is_online, last_notif_id, device_token, security_code) VALUES (?, ?, ?, ?, 1, ?, ?, ?)");
            $stmt->execute([$u, $randSticker, $ua, $ip, $lastId, $deviceToken, $generatedSecurityCode]);
            $_SESSION['user_id'] = $pdo->lastInsertId();
            $_SESSION['username'] = $u;
            $_SESSION['sticker'] = $randSticker;

            session_regenerate_id(true);
            $_SESSION['phone_verified'] = false;
            $_SESSION['spam_check'] = [];
            $_SESSION['bw_nonce'] = bin2hex(random_bytes(16));
            $_SESSION['logout_nonce'] = bin2hex(random_bytes(16));

            $sessionToken = bin2hex(random_bytes(32));
            $_SESSION['session_token'] = $sessionToken;
            try {
                $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ? AND last_activity < DATE_SUB(NOW(), INTERVAL 3 DAY)")->execute([$_SESSION['user_id']]);
                $pdo->prepare("INSERT INTO user_sessions (session_token, user_id, ip_address, user_agent) VALUES (?, ?, ?, ?)")->execute([$sessionToken, $_SESSION['user_id'], $ip, $ua]);
            } catch (Exception $e) {}

            if ($isAdminLogin) {
                setcookie('admin_token', hash('sha256', $admin_password . $ua . 'salt'), time() + 259200, '/', '', isset($_SERVER['HTTPS']), false);
            }

            echo json_encode(['status' => 'success', 'security_code' => $generatedSecurityCode, 'needs_phone' => true]);
        }

        if (!isset($_SESSION['logout_nonce'])) {
            session_regenerate_id(true);
            $_SESSION['spam_check'] = [];
            $_SESSION['bw_nonce'] = bin2hex(random_bytes(16));
            $_SESSION['logout_nonce'] = bin2hex(random_bytes(16));
            $sessionToken = bin2hex(random_bytes(32));
            $_SESSION['session_token'] = $sessionToken;
            $loginUserId2 = $_SESSION['user_id'];
            $loginIp2 = get_client_ip();
            $loginUa2 = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 500);
            try {
                $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ? AND last_activity < DATE_SUB(NOW(), INTERVAL 3 DAY)")->execute([$loginUserId2]);
                $pdo->prepare("INSERT INTO user_sessions (session_token, user_id, ip_address, user_agent) VALUES (?, ?, ?, ?)")->execute([$sessionToken, $loginUserId2, $loginIp2, $loginUa2]);
            } catch (Exception $e) {}
        }
        exit;
    }

    if ($_POST['action'] === 'quick_login') {
        $u = xss_clean(trim(preg_replace('/\s+/', ' ', $_POST['username'] ?? '')));
        if (empty($u)) { echo json_encode(['status' => 'error', 'message' => 'نام کاربری الزامی است']); exit; }

        $ip = get_client_ip();
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 500);

        $stmtIp = $pdo->prepare("SELECT id FROM banned_ips WHERE ip_address = ?");
        $stmtIp->execute([$ip]);
        if ($stmtIp->fetch()) { echo json_encode(['status' => 'banned', 'ban_type' => 'perm', 'message' => 'دسترسی دستگاه شما مسدود شده است']); exit; }

        $stmt = $pdo->prepare("SELECT id, username, sticker, security_code, is_banned_until, ip_address, user_agent, device_token FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$u]);
        $user = $stmt->fetch();

        if (!$user) { echo json_encode(['status' => 'error', 'message' => 'کاربری با این نام یافت نشد']); exit; }

        if ($user['is_banned_until'] > time()) {
            $rem = $user['is_banned_until'] - time();
            $banType = $rem > 31536000 ? 'perm' : 'temp';
            $msg = $banType === 'perm' ? 'حساب شما دائماً مسدود شده است' : 'حساب شما به مدت ' . ceil($rem / 60) . ' دقیقه دیگر مسدود است';
            echo json_encode(['status' => 'banned', 'ban_type' => $banType, 'message' => $msg]);
            exit;
        }

        if ($u === $admin_username || is_admin_user($u, $pdo)) {
            echo json_encode(['status' => 'need_code', 'message' => 'برای ورود با حساب ادمین از کد امنیتی استفاده کنید']);
            exit;
        }

        $deviceToken = $_COOKIE['device_token'] ?? null;
        $ipMatch    = ($user['ip_address'] === $ip);
        $uaMatch    = ($user['user_agent'] === $ua);
        $tokenMatch = ($deviceToken && $user['device_token'] && $user['device_token'] === $deviceToken);

        if (!$ipMatch || (!$uaMatch && !$tokenMatch)) {
            echo json_encode(['status' => 'need_code', 'message' => 'دستگاه یا شبکه شما تغییر کرده. لطفاً با کد امنیتی وارد شوید']);
            exit;
        }

        if (!$deviceToken) {
            $deviceToken = bin2hex(random_bytes(32));
            setcookie('device_token', $deviceToken, time() + 259200, '/', '', isset($_SERVER['HTTPS']), true);
        }

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['sticker'] = $user['sticker'];

        $pdo->prepare("UPDATE users SET last_activity = NOW(), is_online = 1, ip_address = ?, user_agent = ?, device_token = ? WHERE id = ?")->execute([$ip, $ua, $deviceToken, $user['id']]);

        session_regenerate_id(true);
        // Auto-verify phone if user has auth registered (quick_login is already trusted-device only)
        try {
            $chkQLAuth = $pdo->query("SHOW COLUMNS FROM users LIKE 'auth_password'");
            $qlStmt = $pdo->prepare("SELECT phone_number" . ($chkQLAuth->rowCount() > 0 ? ", auth_password" : "") . " FROM users WHERE id = ?");
            $qlStmt->execute([$user['id']]);
            $qlRow = $qlStmt->fetch();
            $qlHasAuth = !empty($qlRow['phone_number']) || !empty($qlRow['auth_password'] ?? '');
            $_SESSION['phone_verified'] = $qlHasAuth;
        } catch (Exception $e) {
            $_SESSION['phone_verified'] = false;
        }
        $_SESSION['spam_check'] = [];
        $_SESSION['bw_nonce'] = bin2hex(random_bytes(16));
        $_SESSION['logout_nonce'] = bin2hex(random_bytes(16));

        $qlToken = bin2hex(random_bytes(32));
        $_SESSION['session_token'] = $qlToken;
        try {
            $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ? AND last_activity < DATE_SUB(NOW(), INTERVAL 3 DAY)")->execute([$user['id']]);
            $pdo->prepare("INSERT INTO user_sessions (session_token, user_id, ip_address, user_agent) VALUES (?, ?, ?, ?)")->execute([$qlToken, $user['id'], $ip, $ua]);
        } catch (Exception $e) {}

        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_POST['action'] === 'get_prev_users_for_burned') {
        $clientIp    = get_client_ip();
        $clientUa    = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $cookieToken = $_COOKIE['device_token'] ?? '';

        if (!empty($cookieToken)) {
            $stmt = $pdo->prepare("SELECT DISTINCT username, sticker FROM users WHERE ((device_token IS NOT NULL AND device_token != '' AND device_token = ?) OR (ip_address = ? AND user_agent = ?)) AND username != ? ORDER BY last_activity DESC LIMIT 5");
            $stmt->execute([$cookieToken, $clientIp, $clientUa, $admin_username]);
        } else {
            $stmt = $pdo->prepare("SELECT DISTINCT username, sticker FROM users WHERE ip_address = ? AND user_agent = ? AND username != ? ORDER BY last_activity DESC LIMIT 5");
            $stmt->execute([$clientIp, $clientUa, $admin_username]);
        }
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'users' => $users]);
        exit;
    }

    if ($_POST['action'] === 'get_update_notifications') {
        if (!isset($_SESSION['user_id'])) { echo json_encode(['status' => 'error']); exit; }
        $stmt = $pdo->prepare("SELECT un.id, un.title, un.message, un.icon, un.type, un.created_at FROM update_notifications un WHERE un.is_active = 1 AND un.id NOT IN (SELECT notif_id FROM update_notif_seen WHERE user_id = ?) ORDER BY un.created_at DESC LIMIT 5");
        $stmt->execute([$_SESSION['user_id']]);
        $notifs = $stmt->fetchAll();
        echo json_encode(['status' => 'success', 'notifications' => $notifs]);
        exit;
    }

    if ($_POST['action'] === 'mark_update_notification_seen') {
        if (!isset($_SESSION['user_id'])) { echo json_encode(['status' => 'error']); exit; }
        $nid = (int)($_POST['notif_id'] ?? 0);
        if ($nid > 0) {
            try {
                $pdo->prepare("INSERT IGNORE INTO update_notif_seen (user_id, notif_id) VALUES (?, ?)")->execute([$_SESSION['user_id'], $nid]);
            } catch (Exception $e) {}
        }
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($_POST['action'] === 'get_online_count') {
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_online = 1 AND username != '" . $admin_username . "'");
        $count = (int)$stmt->fetchColumn();
        echo json_encode(['status' => 'success', 'count' => $count]);
        exit;
    }

    if ($_POST['action'] === 'ping_server') {
        echo json_encode(['status' => 'ok', 'ts' => time()]);
        exit;
    }
}
?>
