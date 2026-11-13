<?php
require_once 'config.php';
header('Content-Type: application/json');

if (isset($_GET['logout'])) {
    // Logout is reached with `window.location.href = ...`, i.e. a real browser
    // navigation. It used to answer with a JSON body, so after a session was
    // burned and the ban expired, refreshing left the user staring at a raw
    // {"status":"success"} document. A navigation now gets a 302 to the login
    // page; only programmatic callers (fetch) still receive JSON.
    $navAccept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
    $isBrowserNav = (($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '') === 'navigate')
        || (stripos($navAccept, 'text/html') !== false);

    $nonceOk = isset($_GET['nonce'], $_SESSION['logout_nonce'])
        && is_string($_GET['nonce'])
        && hash_equals((string)$_SESSION['logout_nonce'], (string)$_GET['nonce']);

    if ($nonceOk && isset($_SESSION['user_id'])) {
        // A demo account is disposable: drop the row (and everything it made)
        // the moment the visitor leaves, instead of waiting for the TTL sweep.
        if (!empty($_SESSION['is_demo'])) {
            veyrax_demo_purge($pdo, (int)$_SESSION['user_id']);
        }
        $pdo->prepare("UPDATE users SET last_activity = NOW(), is_online = 0 WHERE id = ?")->execute([$_SESSION['user_id']]);
        if (isset($_SESSION['session_token'])) {
            $pdo->prepare("DELETE FROM user_sessions WHERE session_token = ?")->execute([$_SESSION['session_token']]);
        }
    }
    session_unset();
    session_destroy();
    setcookie('admin_token', '', time() - 3600, '/');

    if ($isBrowserNav) {
        // `bye` tells the login page to greet the user; `stale` marks the
        // "session already gone" case so it is not reported as a clean exit.
        header('Location: index.php?bye=' . ($nonceOk ? '1' : 'stale'), true, 302);
        exit;
    }

    echo json_encode($nonceOk
        ? ['status' => 'success']
        : ['status' => 'error', 'message' => 'درخواست نامعتبر']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Request']);
    exit;
}

// Per-action anti-flood. The old global 150ms window rejected a login
// whenever a background call (captcha refresh, notifications, online count)
// had run just before it. Read-only/background actions are never throttled.
$reqAction = (isset($_POST['action']) && is_string($_POST['action'])) ? $_POST['action'] : '';
$unthrottledActions = ['refresh_captcha', 'refresh_report_captcha', 'get_update_notifications', 'mark_update_notification_seen', 'get_online_count', 'ping_server', 'get_prev_users_for_burned'];
if ($reqAction !== '' && !in_array($reqAction, $unthrottledActions, true)) {
    $rateKey = 'rate_' . $reqAction;
    $lastReq = $_SESSION[$rateKey] ?? 0;
    $nowTs = microtime(true);
    if ($nowTs - $lastReq < 0.3) {
        echo json_encode(['status' => 'error', 'message' => 'درخواست‌های سریع مجاز نیست']);
        exit;
    }
    $_SESSION[$rateKey] = $nowTs;
}

if (!isset($_POST['csrf_token']) || !is_string($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    echo json_encode(['status' => 'error', 'message' => 'نشست منقضی شده است']);
    exit;
}

if (isset($_POST['action'])) {

    // ── Demo sandbox gate ────────────────────────────────────────────────
    // A demo visitor never touches account, phone, report or recovery code
    // paths. The chat itself lives in chat.php; here we only keep the few
    // harmless background calls the shell needs to stay alive.
    if (!empty($_SESSION['is_demo'])) {
        $demoOk = [
            'ping_server', 'refresh_captcha', 'check_username_availability',
            'get_update_notifications', 'mark_update_notification_seen',
            'get_online_count',
        ];
        if (!in_array((string)$_POST['action'], $demoOk, true)) {
            echo json_encode([
                'status'  => 'error',
                'demo'    => true,
                'message' => 'این قابلیت در حالت نمایشی در دسترس نیست',
            ]);
            exit;
        }
    }

    if ($_POST['action'] === 'check_username_availability') {
        if (isset($_SESSION['username_check_last']) && (time() - (int)$_SESSION['username_check_last']) < 2) {
            echo json_encode(['status' => 'error', 'message' => 'کمی صبر کنید']);
            exit;
        }
        $_SESSION['username_check_last'] = time();
        $probeIsAdmin = !empty($_SESSION['user_id']) && (($_SESSION['username'] ?? '') === $admin_username);
        if (!$probeIsAdmin) {
            $probeGate = veyrax_closed_notice($pdo, 'signup');
            if ($probeGate) { echo json_encode($probeGate); exit; }
        }
        $u = trim(preg_replace('/\s+/', ' ', $_POST['username'] ?? ''));
        $u = xss_clean($u);
        if (empty($u)) { echo json_encode(['status' => 'empty']); exit; }
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$u]);
        if ($stmt->fetch()) {
            // Existing accounts stay reachable: the lock only stops new sign-ups.
            echo json_encode(['status' => 'taken', 'message' => 'این نام کاربری قبلاً ثبت شده است']);
        } elseif (veyrax_registration_locked($pdo)) {
            // Refuse a free name here instead of letting the visitor fill in the
            // whole form and fail at the very last step.
            echo json_encode([
                'status'  => 'register_locked',
                'message' => 'ثبت‌نام در این سایت بسته شده است. فقط حساب‌های قبلی می‌توانند وارد شوند',
            ]);
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

        $stmtRoom = $pdo->prepare("SELECT created_by FROM rooms WHERE name = ? LIMIT 1");
        $stmtRoom->execute([$roomName]);
        $roomOwner = $stmtRoom->fetchColumn();
        if ($roomOwner === false) { echo json_encode(['status' => 'error', 'message' => 'اتاق یافت نشد']); exit; }
        $inviterIsAdmin = (($_SESSION['username'] ?? '') === $admin_username) || is_admin_user($_SESSION['username'] ?? '', $pdo);
        if (!$inviterIsAdmin && (int)$roomOwner !== (int)$_SESSION['user_id']) {
            echo json_encode(['status' => 'error', 'message' => 'فقط سازنده اتاق می‌تواند دعوت بفرستد']);
            exit;
        }

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
        if (!empty($authPass) && !veyrax_set_auth_hash($pdo, $_SESSION['user_id'], password_hash($authPass, PASSWORD_DEFAULT))) {
            echo json_encode(['status' => 'error', 'message' => 'ذخیره رمز احراز هویت روی این هاست ممکن نیست؛ با شماره موبایل وارد شوید']);
            exit;
        }

        $updates = []; $params = [];
        if (!empty($phone)) { $updates[] = 'phone_number = ?'; $params[] = $phone; }
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

        $phoneGate = veyrax_login_gate($pdo, $u, ($u === $admin_username || is_admin_user($u, $pdo)));
        if ($phoneGate) { echo json_encode($phoneGate); exit; }

        if (empty($u) || empty($otp)) { echo json_encode(['status' => 'error', 'message' => 'اطلاعات ناقص']); exit; }
        if (!isset($_SESSION['phone_otp_code']) || !isset($_SESSION['phone_otp_username'])) { echo json_encode(['status' => 'error', 'message' => 'کد تأیید منقضی شده است']); exit; }
        if ($_SESSION['phone_otp_username'] !== $u) { echo json_encode(['status' => 'error', 'message' => 'نام کاربری مطابقت ندارد']); exit; }
        if (time() > ($_SESSION['phone_otp_expires'] ?? 0)) {
            unset($_SESSION['phone_otp_code'], $_SESSION['phone_otp_username'], $_SESSION['phone_otp_expires']);
            echo json_encode(['status' => 'error', 'message' => 'کد تأیید منقضی شده است. دوباره تلاش کنید']);
            exit;
        }
        $_SESSION['phone_otp_attempts'] = ($_SESSION['phone_otp_attempts'] ?? 0) + 1;
        if ($_SESSION['phone_otp_attempts'] > 5) {
            unset($_SESSION['phone_otp_code'], $_SESSION['phone_otp_username'], $_SESSION['phone_otp_expires'], $_SESSION['phone_otp_attempts']);
            echo json_encode(['status' => 'error', 'message' => 'تلاش‌های زیادی انجام شده. کد جدید درخواست کنید']);
            exit;
        }
        if (!hash_equals((string)$_SESSION['phone_otp_code'], (string)$otp)) { echo json_encode(['status' => 'error', 'message' => 'کد تأیید اشتباه است']); exit; }

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
        if (isset($_SESSION['phone_otp_last_request']) && (time() - (int)$_SESSION['phone_otp_last_request']) < 60) {
            $wait = 60 - (time() - (int)$_SESSION['phone_otp_last_request']);
            echo json_encode(['status' => 'error', 'message' => "برای درخواست کد جدید $wait ثانیه صبر کنید"]);
            exit;
        }
        $u = xss_clean(trim(preg_replace('/\s+/', ' ', $_POST['username'] ?? '')));
        $otpGate = veyrax_login_gate($pdo, $u, ($u === $admin_username || is_admin_user($u, $pdo)));
        if ($otpGate) { echo json_encode($otpGate); exit; }
        $stmt = $pdo->prepare("SELECT id, phone_number, is_banned_until FROM users WHERE username = ?");
        $stmt->execute([$u]);
        $user = $stmt->fetch();
        if (!$user || empty($user['phone_number'])) {
            echo json_encode(['status' => 'error', 'message' => 'این حساب شماره موبایل ندارد. از کد امنیتی استفاده کنید.']);
            exit;
        }
        if ($user['is_banned_until'] > time()) { echo json_encode(['status' => 'error', 'message' => 'حساب مسدود است']); exit; }
        $_SESSION['phone_otp_last_request'] = time();
        $otp = str_pad((string)random_int(10000, 99999), 5, '0', STR_PAD_LEFT);
        $_SESSION['phone_otp_code'] = $otp;
        $_SESSION['phone_otp_username'] = $u;
        $_SESSION['phone_otp_expires'] = time() + 180;
        $_SESSION['phone_otp_attempts'] = 0;
        $maskedPhone = substr($user['phone_number'], 0, 4) . '***' . substr($user['phone_number'], -4);

        if (!veyrax_sms_ready($pdo) && !veyrax_sms_dev_mode()) {
            unset($_SESSION['phone_otp_code'], $_SESSION['phone_otp_expires']);
            echo json_encode([
                'status'  => 'sms_offline',
                'message' => 'سامانه پیامک در پنل مدیریت متصل نشده است. لطفاً از کد امنیتی استفاده کنید.',
            ]);
            exit;
        }

        $sent = veyrax_sms_send_ex($user['phone_number'], 'کد ورود ویراکس: ' . $otp . "\n" . 'اعتبار: ۳ دقیقه', 'login_otp', $otp);
        $payload = [
            'status' => 'success',
            'phone_masked' => $maskedPhone,
            'sms_sent' => $sent['ok'],
            'expires_in' => 180,
            'message' => $sent['ok'] ? 'کد تأیید ارسال شد' : 'ارسال پیامک ممکن نشد؛ از کد امنیتی استفاده کنید',
        ];
        if (veyrax_sms_dev_mode()) {
            $payload['otp'] = $otp;
            $payload['message'] = 'کد تأیید (حالت توسعه): ' . $otp;
        }
        echo json_encode($payload);
        exit;
    }

    if ($_POST['action'] === 'refresh_captcha') {
        $n1 = rand(10, 50);
        $n2 = rand(1, 9);
        $_SESSION['captcha_result'] = $n1 + $n2;
        echo json_encode(['status' => 'success', 'n1' => $n1, 'n2' => $n2]);
        exit;
    }

    if ($_POST['action'] === 'forgot_start') {
        $resetGate = veyrax_closed_notice($pdo, 'reset');
        if ($resetGate) { echo json_encode($resetGate); exit; }
        $phone = veyrax_sms_normalize_phone($_POST['phone'] ?? '');
        if (!preg_match('/^09[0-9]{9}$/', $phone)) {
            echo json_encode(['status' => 'error', 'message' => 'شماره موبایل معتبر وارد کنید (مثال: ۰۹۱۲۳۴۵۶۷۸۹)']);
            exit;
        }

        $ip = get_client_ip();
        $now = time();

        // Database-backed throttle: a new session / cleared cookie must not
        // reset the counter, otherwise the SMS endpoint becomes a free relay.
        $th = veyrax_throttle_state($pdo, $ip, $phone, 'pwreset');
        if ($th['blocked']) {
            echo json_encode([
                'status' => 'rate_limited',
                'message' => 'تلاش‌های زیاد از این دستگاه. ' . veyrax_human_wait($th['retry']) . ' دیگر تلاش کنید.',
                'retry_after' => $th['retry'],
            ]);
            exit;
        }

        // Per-phone cooldown (60 s) so one number cannot be flooded.
        try {
            $st = $pdo->prepare("SELECT expires FROM reset_tokens WHERE identifier_hash = ? LIMIT 1");
            $st->execute([hash('sha256', $phone)]);
            $row = $st->fetch();
            if ($row && isset($row['expires'])) {
                $issuedAt = (int)$row['expires'] - 300; // token lifetime is 5 min
                $wait = 60 - ($now - $issuedAt);
                if ($wait > 0 && $wait <= 60) {
                    echo json_encode([
                        'status' => 'rate_limited',
                        'message' => 'کد قبلی هنوز معتبر است. ' . veyrax_human_wait($wait) . ' دیگر دوباره تلاش کنید.',
                        'retry_after' => $wait,
                    ]);
                    exit;
                }
            }
        } catch (Exception $e) {}

        $stmt = $pdo->prepare("SELECT id, username, is_banned_until FROM users WHERE phone_number = ? ORDER BY last_activity DESC LIMIT 1");
        $stmt->execute([$phone]);
        $user = $stmt->fetch();

        $masked = substr($phone, 0, 4) . '***' . substr($phone, -4);
        $generic = [
            'status'        => 'success',
            'message'       => 'اگر این شماره در سیستم ثبت شده باشد، کد تأیید برای شما پیامک می‌شود.',
            'phone_masked'  => $masked,
            'expires_in'    => 300,
            'resend_after'  => 60,
        ];

        if (!$user || ($user['is_banned_until'] > time())) {
            veyrax_throttle_record($pdo, $ip, $phone, 'pwreset', false);
            echo json_encode($generic);
            exit;
        }

        // Fail loudly when no gateway is connected — pretending success was the
        // reason the reset page looked broken (nothing ever arrived).
        if (!veyrax_sms_ready($pdo) && !veyrax_sms_dev_mode()) {
            echo json_encode([
                'status'  => 'sms_offline',
                'message' => 'سامانه پیامک هنوز در پنل مدیریت متصل نشده است. با مدیر سایت تماس بگیرید.',
            ]);
            exit;
        }

        $code = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = hash('sha256', 'virax' . $code);
        try {
            $pdo->prepare("INSERT INTO reset_tokens (identifier_hash, code_hash, expires, used, attempts) VALUES (?, ?, ?, 0, 0)
                           ON DUPLICATE KEY UPDATE code_hash = VALUES(code_hash), expires = VALUES(expires), used = 0, attempts = 0")
                ->execute([hash('sha256', $phone), $codeHash, $now + 300]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'خطای سیستمی؛ چند لحظه بعد دوباره تلاش کنید']);
            error_log('pwreset: ' . $e->getMessage());
            exit;
        }

        $sent = veyrax_sms_send_ex($phone, 'کد بازیابی رمز ویراکس: ' . $code . "\n" . 'این کد را در اختیار هیچ‌کس قرار ندهید.', 'pwreset', $code);
        veyrax_throttle_record($pdo, $ip, $phone, 'pwreset', false);

        if (!$sent['ok']) {
            echo json_encode([
                'status'  => 'sms_failed',
                'message' => 'ارسال پیامک ناموفق بود. لطفاً چند لحظه بعد دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.',
            ]);
            exit;
        }

        if (veyrax_sms_dev_mode()) {
            $generic['dev_code'] = $code;
            $generic['message'] = 'کد تأیید (حالت آزمایشی): ' . $code;
        }
        echo json_encode($generic);
        exit;
    }

    if ($_POST['action'] === 'forgot_verify') {
        $resetGate = veyrax_closed_notice($pdo, 'reset');
        if ($resetGate) { echo json_encode($resetGate); exit; }
        $phone = veyrax_sms_normalize_phone($_POST['phone'] ?? '');
        $code = preg_replace('/[^0-9]/', '', $_POST['code'] ?? '');
        $identHash = hash('sha256', $phone);
        if (!preg_match('/^09[0-9]{9}$/', $phone) || strlen($code) !== 6) {
            echo json_encode(['status' => 'error', 'message' => 'کد ۶ رقمی را کامل و صحیح وارد کنید']);
            exit;
        }

        $ip = get_client_ip();
        $now = time();

        $th = veyrax_throttle_state($pdo, $ip, $phone, 'pwverify');
        if ($th['blocked']) {
            echo json_encode([
                'status' => 'rate_limited',
                'message' => 'تلاش‌های ناموفق زیاد. ' . veyrax_human_wait($th['retry']) . ' دیگر تلاش کنید.',
                'retry_after' => $th['retry'],
            ]);
            exit;
        }

        $row = null;
        try {
            $st = $pdo->prepare("SELECT id, code_hash, expires, used, attempts FROM reset_tokens WHERE identifier_hash = ? ORDER BY id DESC LIMIT 1");
            $st->execute([$identHash]);
            $row = $st->fetch();
        } catch (Exception $e) { $row = null; }

        if (!$row || $row['used'] || $row['expires'] < $now || $row['attempts'] >= 5) {
            echo json_encode(['status' => 'error', 'message' => 'کد منقضی یا نامعتبر است؛ کد جدید بگیرید', 'expired' => true]);
            exit;
        }

        $valid = hash_equals($row['code_hash'], hash('sha256', 'virax' . $code))
              || hash_equals($row['code_hash'], hash('sha256', 'veyrax' . $code));

        if (!$valid) {
            $pdo->prepare("UPDATE reset_tokens SET attempts = attempts + 1 WHERE id = ?")->execute([$row['id']]);
            veyrax_throttle_record($pdo, $ip, $phone, 'pwverify', false);
            $left = max(0, 5 - ((int)$row['attempts'] + 1));
            echo json_encode([
                'status'  => 'error',
                'message' => $left > 0 ? ('کد تأیید اشتباه است. ' . $left . ' تلاش دیگر باقی مانده') : 'کد تأیید اشتباه است؛ کد جدید بگیرید',
            ]);
            exit;
        }

        $pdo->prepare("UPDATE reset_tokens SET used = 1 WHERE id = ?")->execute([$row['id']]);
        veyrax_throttle_record($pdo, $ip, $phone, 'pwverify', true);
        $_SESSION['pwreset_verified_phone'] = $phone;
        $_SESSION['pwreset_verified_at'] = $now;
        echo json_encode(['status' => 'success', 'message' => 'کد تأیید شد؛ رمز جدید را تعیین کنید']);
        exit;
    }

    if ($_POST['action'] === 'forgot_reset') {
        $resetGate = veyrax_closed_notice($pdo, 'reset');
        if ($resetGate) { echo json_encode($resetGate); exit; }
        $phone = veyrax_sms_normalize_phone($_POST['phone'] ?? '');
        $p1 = (string)($_POST['password'] ?? '');
        $p2 = (string)($_POST['password2'] ?? '');
        $now = time();
        if (empty($_SESSION['pwreset_verified_phone']) || $_SESSION['pwreset_verified_phone'] !== $phone || (($now - (int)($_SESSION['pwreset_verified_at'] ?? 0)) > 600)) {
            echo json_encode(['status' => 'error', 'message' => 'زمان تأیید کد تمام شده؛ از ابتدا تلاش کنید', 'restart' => true]);
            exit;
        }
        if (!preg_match('/^09[0-9]{9}$/', $phone)) {
            echo json_encode(['status' => 'error', 'message' => 'شماره معتبر نیست']);
            exit;
        }
        if (strlen($p1) < 8 || strlen($p1) > 20 || $p1 !== $p2) {
            echo json_encode(['status' => 'error', 'message' => 'رمز باید ۸ تا ۲۰ کاراکتر و با تکرارش یکسان باشد']);
            exit;
        }
        if (preg_match('/[\x{0600}-\x{06FF}]/u', $p1)) {
            echo json_encode(['status' => 'error', 'message' => 'رمز نباید شامل حروف فارسی باشد']);
            exit;
        }
        if (!preg_match('/[A-Za-z]/', $p1) || !preg_match('/[0-9]/', $p1)) {
            echo json_encode(['status' => 'error', 'message' => 'رمز باید ترکیبی از حروف انگلیسی و عدد باشد']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE phone_number = ? ORDER BY last_activity DESC LIMIT 1");
        $stmt->execute([$phone]);
        $user = $stmt->fetch();
        if (!$user) { echo json_encode(['status' => 'error', 'message' => 'حسابی با این شماره یافت نشد']); exit; }

        $newHash = password_hash($p1, PASSWORD_DEFAULT);
        if (!veyrax_set_auth_hash($pdo, $user['id'], $newHash)) {
            echo json_encode(['status' => 'error', 'message' => 'ذخیره رمز روی این هاست ممکن نیست']);
            exit;
        }
        // A password reset must invalidate every existing session of that user.
        try { $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?")->execute([$user['id']]); } catch (Exception $e) {}
        try { $pdo->prepare("UPDATE users SET device_token = NULL, phone_auth_attempts = 0, phone_auth_locked_until = 0, is_online = 0 WHERE id = ?")->execute([$user['id']]); } catch (Exception $e) {}
        try { $pdo->prepare("DELETE FROM reset_tokens WHERE identifier_hash = ?")->execute([hash('sha256', $phone)]); } catch (Exception $e) {}
        unset($_SESSION['pwreset_verified_phone'], $_SESSION['pwreset_verified_at']);
        echo json_encode(['status' => 'success', 'message' => 'رمز عبور با موفقیت تغییر کرد؛ اکنون وارد شوید', 'username' => $user['username']]);
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
        $gateAdmin = ($u === $admin_username || is_admin_user($u, $pdo));
        $gate = veyrax_login_gate($pdo, $u, $gateAdmin);
        if ($gate) { echo json_encode($gate); exit; }
        if ($u === $admin_username || is_admin_user($u, $pdo)) {
            echo json_encode(['status' => 'password_required']);
        } elseif (mb_strtolower($u, 'UTF-8') === VEYRAX_DEMO_LOGIN) {
            // The demo account is shared, so it always asks for its password —
            // but only while the sandbox is actually switched on, otherwise the
            // visitor would type a password just to be told it does not exist.
            if (veyrax_demo_enabled($pdo)) {
                echo json_encode(['status' => 'password_required']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'حالت دمو در این سایت فعال نیست']);
            }
        } else {
            echo json_encode(['status' => 'proceed']);
        }
        exit;
    }

    if ($_POST['action'] === 'login_with_code') {
        $captchaOk = isset($_POST['captcha']) && is_numeric($_POST['captcha']) && (int)$_POST['captcha'] === (int)($_SESSION['captcha_result'] ?? -1);
        // One-shot captcha: a solved challenge can never be replayed.
        $_SESSION['captcha_result'] = null;
        if (!$captchaOk) {
            echo json_encode(['status' => 'error', 'message' => 'کد امنیتی اشتباه است', 'refresh_captcha' => true]);
            exit;
        }

        $u = xss_clean(trim(preg_replace('/\s+/', ' ', $_POST['username'] ?? '')));
        $secCode = xss_clean(trim($_POST['security_code'] ?? ''));
        $ip = get_client_ip();
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 500);

        $th = veyrax_throttle_state($pdo, $ip, $u, 'code');
        if ($th['blocked']) {
            echo json_encode([
                'status' => 'rate_limited',
                'message' => 'تلاش‌های ناموفق زیاد. ' . veyrax_human_wait($th['retry']) . ' دیگر تلاش کنید.',
                'retry_after' => $th['retry'],
            ]);
            exit;
        }

        $codeGate = veyrax_login_gate($pdo, $u, ($u === $admin_username || is_admin_user($u, $pdo)));
        if ($codeGate) { echo json_encode($codeGate); exit; }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$u]);
        $user = $stmt->fetch();

        $storedCode = $user ? (string)($user['security_code'] ?? '') : '';
        if (!$user || $storedCode === '' || !hash_equals($storedCode, (string)$secCode)) {
            veyrax_throttle_record($pdo, $ip, $u, 'code', false);
            $left = max(0, 5 - ($th['user_fails'] + 1));
            echo json_encode([
                'status'  => 'error',
                'message' => $left > 0 ? ('نام کاربری یا کد امنیتی اشتباه است. ' . $left . ' تلاش دیگر باقی مانده') : 'نام کاربری یا کد امنیتی اشتباه است. کمی بعد تلاش کنید.',
            ]);
            exit;
        }
        if ($user['is_banned_until'] > time()) { echo json_encode(['status' => 'banned', 'ban_type' => 'temp', 'message' => 'حساب شما مسدود است.']); exit; }
        veyrax_throttle_record($pdo, $ip, $u, 'code', true);

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

    // ── Security-code recovery ──────────────────────────────────────────────
    // One endpoint that replaces the SMS-only reset: with the 8-character
    // security code a user can (a) just verify/login, (b) set a new password,
    // (c) rename the account, or both. Brute force is handled by the same
    // database-backed throttle as the code login (5 tries / username,
    // 12 tries / IP per 15 min + exponential lock).
    if ($_POST['action'] === 'sec_recover') {
        // One-shot captcha first: no captcha → no attempt, and the challenge is
        // consumed either way so it cannot be replayed.
        $captchaOk = isset($_POST['captcha']) && is_numeric($_POST['captcha'])
            && (int)$_POST['captcha'] === (int)($_SESSION['captcha_result'] ?? -1);
        $_SESSION['captcha_result'] = null;
        if (!$captchaOk) {
            echo json_encode(['status' => 'error', 'message' => 'پاسخ امنیتی اشتباه است', 'refresh_captcha' => true]);
            exit;
        }

        $ip = get_client_ip();
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 500);

        $u       = xss_clean(trim(preg_replace('/\s+/', ' ', $_POST['username'] ?? '')));
        $code    = strtoupper(xss_clean(trim($_POST['security_code'] ?? '')));
        $newPass = (string)($_POST['new_password'] ?? '');
        $newPass2 = (string)($_POST['new_password2'] ?? '');
        $newUser = xss_clean(trim(preg_replace('/\s+/', ' ', $_POST['new_username'] ?? '')));

        $sessionUid  = (int)($_SESSION['user_id'] ?? 0);
        $sessionName = (string)($_SESSION['username'] ?? '');
        if ($u === '' && $sessionUid > 0) $u = $sessionName;

        $recoverGate = veyrax_login_gate($pdo, $u, ($u === $admin_username || is_admin_user($u, $pdo)));
        if ($recoverGate) { echo json_encode($recoverGate); exit; }

        if ($u === '' || $code === '') {
            echo json_encode(['status' => 'error', 'message' => 'نام کاربری و کد امنیتی را وارد کنید']);
            exit;
        }

        $th = veyrax_throttle_state($pdo, $ip, $u, 'recover', 900);
        if ($th['blocked']) {
            echo json_encode([
                'status'      => 'rate_limited',
                'message'     => 'بعد از ۵ تلاش ناموفق، ورود کد امنیتی برای ۱۵ دقیقه قفل شد. ' . veyrax_human_wait($th['retry']) . ' دیگر تلاش کنید.',
                'retry_after' => $th['retry'],
            ]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$u]);
        $user = $stmt->fetch();

        $storedCode = $user ? (string)($user['security_code'] ?? '') : '';
        if (!$user || $storedCode === '' || !hash_equals($storedCode, $code)) {
            veyrax_throttle_record($pdo, $ip, $u, 'recover', false);
            $left = max(0, 5 - ($th['user_fails'] + 1));
            echo json_encode([
                'status'  => 'error',
                'message' => $left > 0
                    ? ('نام کاربری یا کد امنیتی اشتباه است. ' . $left . ' تلاش دیگر باقی مانده')
                    : 'نام کاربری یا کد امنیتی اشتباه است. کمی بعد تلاش کنید.',
                'left'    => $left,
            ]);
            exit;
        }
        if ($user['is_banned_until'] > time()) {
            echo json_encode(['status' => 'banned', 'ban_type' => 'temp', 'message' => 'حساب شما مسدود است.']);
            exit;
        }

        $doPass = ($newPass !== '' || $newPass2 !== '');
        $doName = ($newUser !== '');

        if ($doPass) {
            if (strlen($newPass) < 8 || strlen($newPass) > 20 || $newPass !== $newPass2) {
                echo json_encode(['status' => 'error', 'message' => 'رمز باید ۸ تا ۲۰ کاراکتر و با تکرارش یکسان باشد']);
                exit;
            }
            if (preg_match('/[\x{0600}-\x{06FF}]/u', $newPass)) {
                echo json_encode(['status' => 'error', 'message' => 'رمز نباید شامل حروف فارسی باشد']);
                exit;
            }
            if (!preg_match('/[A-Za-z]/', $newPass) || !preg_match('/[0-9]/', $newPass)) {
                echo json_encode(['status' => 'error', 'message' => 'رمز باید ترکیبی از حروف انگلیسی و عدد باشد']);
                exit;
            }
        }

        if ($doName) {
            if ($u === $admin_username) {
                echo json_encode(['status' => 'error', 'message' => 'نام کاربری حساب مدیر اصلی از این مسیر قابل تغییر نیست']);
                exit;
            }
            $len = mb_strlen($newUser, 'UTF-8');
            if ($len < 3 || $len > 16) {
                echo json_encode(['status' => 'error', 'message' => 'نام کاربری جدید باید بین ۳ تا ۱۶ کاراکتر باشد']);
                exit;
            }
            if (!preg_match('/^[a-zA-Z0-9\x{0600}-\x{06FF}\s]+$/u', $newUser)) {
                echo json_encode(['status' => 'error', 'message' => 'نام کاربری فقط می‌تواند شامل حروف فارسی، انگلیسی و اعداد باشد']);
                exit;
            }
            if ($newUser === $u) {
                $doName = false;
            } else {
                $chk = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $chk->execute([$newUser]);
                if ($chk->fetch()) {
                    echo json_encode(['status' => 'error', 'message' => 'این نام کاربری قبلاً استفاده شده است']);
                    exit;
                }
            }
        }

        veyrax_throttle_record($pdo, $ip, $u, 'recover', true);

        if ($doPass) {
            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
            if (!veyrax_set_auth_hash($pdo, $user['id'], $newHash)) {
                echo json_encode(['status' => 'error', 'message' => 'ذخیره رمز روی این هاست ممکن نیست']);
                exit;
            }
            try {
                $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?")->execute([$user['id']]);
                $pdo->prepare("UPDATE users SET device_token = NULL, phone_auth_attempts = 0, phone_auth_locked_until = 0 WHERE id = ?")
                    ->execute([$user['id']]);
            } catch (Exception $e) {}
        }

        if ($doName) {
            $pdo->prepare("UPDATE users SET username = ? WHERE id = ?")->execute([$newUser, $user['id']]);
            if ($sessionUid === (int)$user['id']) $_SESSION['username'] = $newUser;
        }

        $loggedInNow = false;
        if (!$sessionUid) {
            $deviceToken = bin2hex(random_bytes(32));
            setcookie('device_token', $deviceToken, time() + 259200, '/', '', isset($_SERVER['HTTPS']), true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $doName ? $newUser : $u;
            $_SESSION['sticker'] = $user['sticker'];

            $pdo->prepare("UPDATE users SET last_activity = NOW(), is_online = 1, user_agent = ?, ip_address = ?, device_token = ? WHERE id = ?")
                ->execute([$ua, $ip, $deviceToken, $user['id']]);

            session_regenerate_id(true);
            $_SESSION['phone_verified'] = false;
            $_SESSION['spam_check'] = [];
            $_SESSION['bw_nonce'] = bin2hex(random_bytes(16));
            $_SESSION['logout_nonce'] = bin2hex(random_bytes(16));
            $tok = bin2hex(random_bytes(32));
            $_SESSION['session_token'] = $tok;
            try {
                $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ? AND last_activity < DATE_SUB(NOW(), INTERVAL 3 DAY)")->execute([$user['id']]);
                $pdo->prepare("INSERT INTO user_sessions (session_token, user_id, ip_address, user_agent) VALUES (?, ?, ?, ?)")
                    ->execute([$tok, $user['id'], $ip, $ua]);
            } catch (Exception $e) {}
            $loggedInNow = true;
        }

        $parts = [];
        if ($doPass) $parts[] = 'رمز عبور';
        if ($doName) $parts[] = 'نام کاربری';
        $msg = $parts
            ? (implode(' و ', $parts) . ' با موفقیت تغییر کرد')
            : 'کد امنیتی معتبر است';

        echo json_encode([
            'status'      => 'success',
            'message'     => $msg,
            'changed_pass' => $doPass,
            'changed_name' => $doName,
            'username'    => $doName ? $newUser : $u,
            'logged_in'   => $loggedInNow,
        ]);
        exit;
    }

    if ($_POST['action'] === 'login') {
        $captchaOk = isset($_POST['captcha']) && is_numeric($_POST['captcha']) && (int)$_POST['captcha'] === (int)($_SESSION['captcha_result'] ?? -1);
        $_SESSION['captcha_result'] = null; // single use
        if (!$captchaOk) {
            echo json_encode(['status' => 'error', 'message' => 'کد امنیتی (کپچا) اشتباه است', 'refresh_captcha' => true]);
            exit;
        }

        $u = xss_clean(trim(preg_replace('/\s+/', ' ', $_POST['username'] ?? '')));
        $ip = get_client_ip();
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 500);
        $fpIn  = veyrax_fp_clean($_POST['fp'] ?? '');
        $fpcIn = veyrax_fp_clean($_POST['fp_coarse'] ?? '');
        $devTokIn = (string)($_COOKIE['device_token'] ?? '');

        // Every identity signal we hold for a banned user, checked in one query.
        $banHit = veyrax_ban_lookup($pdo, veyrax_ban_signals_for($ip, $fpIn, $fpcIn, $devTokIn, $ua));
        if ($banHit) {
            $banPerm = ((int)$banHit['is_permanent'] === 1);
            echo json_encode([
                'status'   => 'banned',
                'ban_type' => $banPerm ? 'perm' : 'temp',
                'message'  => $banPerm
                    ? 'دسترسی این دستگاه به دلیل تخلف مسدود شده است (بن دائمی)'
                    : 'دسترسی این دستگاه موقتاً مسدود است',
            ]);
            exit;
        }

        $stmtIp = $pdo->prepare("SELECT id FROM banned_ips WHERE ip_address = ?");
        $stmtIp->execute([$ip]);
        if ($stmtIp->fetch()) {
            echo json_encode(['status' => 'banned', 'ban_type' => 'perm', 'message' => 'دسترسی دستگاه شما به دلیل تخلف مسدود شده است (بن دائمی)']);
            exit;
        }

        // Progressive, database backed lockout (per IP and per username).
        $th = veyrax_throttle_state($pdo, $ip, $u, 'login');
        if ($th['blocked']) {
            echo json_encode([
                'status'      => 'rate_limited',
                'message'     => 'تلاش‌های ناموفق زیاد. ' . veyrax_human_wait($th['retry']) . ' دیگر تلاش کنید.',
                'retry_after' => $th['retry'],
            ]);
            exit;
        }

        // ── Access gates ────────────────────────────────────────────────────
        // Demo mode and the login lock are decided before any credential is
        // looked at, so a closed site cannot be probed for valid usernames.
        $isAdminLogin = ($u === $admin_username || is_admin_user($u, $pdo));
        $gate = veyrax_login_gate($pdo, $u, $isAdminLogin);
        if ($gate) {
            echo json_encode($gate);
            exit;
        }

        // ── Demo mode ───────────────────────────────────────────────────────
        // One shared credential (demo / demo) that mints a throw-away account
        // per visitor. It deliberately skips the device-fingerprint and
        // capacity rules: those exist to stop one person farming accounts, and
        // a demo account has nothing to farm.
        if (mb_strtolower($u, 'UTF-8') === VEYRAX_DEMO_LOGIN) {
            if (!veyrax_demo_enabled($pdo)) {
                echo json_encode(['status' => 'error', 'message' => 'حالت دمو در این سایت فعال نیست']);
                exit;
            }
            $thDemo = veyrax_throttle_state($pdo, $ip, 'demo', 'demo');
            if ($thDemo['blocked']) {
                echo json_encode([
                    'status'      => 'rate_limited',
                    'message'     => 'تلاش‌های زیاد. ' . veyrax_human_wait($thDemo['retry']) . ' دیگر تلاش کنید.',
                    'retry_after' => $thDemo['retry'],
                ]);
                exit;
            }
            $demoPass = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
            if (!hash_equals(VEYRAX_DEMO_PASSWORD, $demoPass)) {
                veyrax_throttle_record($pdo, $ip, 'demo', 'demo', false);
                echo json_encode(['status' => 'error', 'message' => 'رمز دمو اشتباه است — نام کاربری demo و رمز demo']);
                exit;
            }

            veyrax_demo_sweep($pdo);
            $demo = veyrax_demo_create_user($pdo, $ip, $ua, $fpIn, $fpcIn);

            // Start from an empty session: nothing from the previous identity
            // (csrf, nonce, security code, phone state) may survive into it.
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['user_id']       = $demo['id'];
            $_SESSION['username']      = $demo['username'];
            $_SESSION['sticker']       = $demo['sticker'];
            $_SESSION['is_demo']       = 1;
            $_SESSION['csrf_token']    = bin2hex(random_bytes(32));
            $_SESSION['bw_nonce']      = bin2hex(random_bytes(16));
            $_SESSION['logout_nonce']  = bin2hex(random_bytes(16));
            $_SESSION['phone_verified'] = true;
            $_SESSION['welcome_seen']  = 1;
            $_SESSION['spam_check']    = [];
            $demoSessionToken = bin2hex(random_bytes(32));
            $_SESSION['session_token'] = $demoSessionToken;
            try {
                $pdo->prepare("INSERT INTO user_sessions (session_token, user_id, ip_address, user_agent) VALUES (?, ?, ?, ?)")
                    ->execute([$demoSessionToken, $demo['id'], $ip, $ua]);
            } catch (Exception $e) {}
            setcookie('device_token', $demo['device_token'], time() + 259200, '/', '', isset($_SERVER['HTTPS']), true);
            veyrax_throttle_record($pdo, $ip, 'demo', 'demo', true);

            echo json_encode([
                'status'      => 'success',
                'demo'        => true,
                'needs_phone' => false,
                'security_code' => null,
            ]);
            exit;
        }

        if ($u === $admin_username) {
            $adminPassIn = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
            if ($adminPassIn === '' || !hash_equals((string)$admin_password, $adminPassIn)) {
                veyrax_throttle_record($pdo, $ip, $u, 'login', false);
                echo json_encode(['status' => 'error', 'message' => 'رمز عبور مدیریت اشتباه است']);
                exit;
            }
        } elseif (is_admin_user($u, $pdo)) {
            $stmtSA = $pdo->prepare("SELECT password_hash FROM admin_users WHERE username = ? AND is_active = 1");
            $stmtSA->execute([$u]);
            $saRow = $stmtSA->fetch();
            $saPassIn = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
            if (!$saRow || $saPassIn === '' || !password_verify($saPassIn, $saRow['password_hash'])) {
                veyrax_throttle_record($pdo, $ip, $u, 'login', false);
                echo json_encode(['status' => 'error', 'message' => 'رمز عبور اشتباه است']);
                exit;
            }
        } else {
            $len = mb_strlen($u, 'UTF-8');
            if ($len < 3 || $len > 16) {
                echo json_encode(['status' => 'error', 'message' => 'نام کاربری باید بین ۳ تا ۱۶ کاراکتر باشد']);
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
                $banType = $rem > (5 * 365 * 24 * 3600) ? 'perm' : 'temp';
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
                    veyrax_throttle_record($pdo, $ip, $u, 'login', false);
                    echo json_encode([
                        'status' => 'device_mismatch',
                        'message' => 'این نام کاربری متعلق به دستگاه دیگری است.' . ($hasPhone ? ' برای احراز هویت از کد امنیتی یا کد پیامکی استفاده کنید.' : ' از کد امنیتی استفاده کنید.'),
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

            $pdo->prepare("UPDATE users SET last_activity = NOW(), is_online = 1, user_agent = ?, ip_address = ?, last_notif_id = ?, device_token = ?, fp = COALESCE(NULLIF(?, ''), fp), fp_coarse = COALESCE(NULLIF(?, ''), fp_coarse) WHERE id = ?")
                ->execute([$ua, $ip, $lastId, $deviceToken, $fpIn, $fpcIn, $user['id']]);

            // For trusted-device logins, auto-verify if user already has auth registered
            if (!isset($_SESSION['phone_verified'])) {
                try {
                    $stmtAuthChk = $pdo->prepare("SELECT phone_number FROM users WHERE id = ?");
                    $stmtAuthChk->execute([$user['id']]);
                    $authChkRow = $stmtAuthChk->fetch();
                    $hasAuthRegistered = !empty($authChkRow['phone_number']) || veyrax_get_auth_hash($pdo, $user['id']) !== '';
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
            veyrax_throttle_record($pdo, $ip, $u, 'login', true);
            echo json_encode([
                'status' => 'success',
                'security_code' => $generatedSecurityCode,
                'needs_phone' => $needsPhone,
            ]);
        } else {
            $deviceTokenCookie = $_COOKIE['device_token'] ?? '';

            // Registration lock: this branch is the only place a normal account
            // is ever created, so refusing it here closes every signup route at
            // once. Existing users never reach this code path.
            if (veyrax_registration_locked($pdo)) {
                echo json_encode([
                    'status'  => 'register_locked',
                    'message' => 'ثبت‌نام در این سایت بسته شده است. فقط حساب‌های قبلی می‌توانند وارد شوند',
                ]);
                exit;
            }

            $stmtMaxU = $pdo->prepare("SELECT value FROM settings WHERE name = 'max_users'");
            $stmtMaxU->execute();
            $maxUsersRow = $stmtMaxU->fetchColumn();
            $maxUsersPerDevice = ($maxUsersRow !== false && (int)$maxUsersRow > 0) ? (int)$maxUsersRow : 10;

            // "Same device" now means: same token, same browser on this IP, or
            // the same device fingerprint on this IP. That last clause is what
            // closes the "just open another browser to reset the counter" hole.
            $devConds = [];
            $devArgs  = [];
            if (!empty($deviceTokenCookie)) {
                $devConds[] = 'device_token = ?';
                $devArgs[]  = $deviceTokenCookie;
            }
            $devConds[] = '(ip_address = ? AND user_agent = ?)';
            $devArgs[]  = $ip;
            $devArgs[]  = $ua;
            if ($fpcIn !== '') {
                $devConds[] = '(ip_address = ? AND fp_coarse = ?)';
                $devArgs[]  = $ip;
                $devArgs[]  = $fpcIn;
            }
            $devWhere = '(' . implode(' OR ', $devConds) . ') AND username != ?';
            $devArgsFull = array_merge($devArgs, [$admin_username]);

            $stmtCheckLimit = $pdo->prepare("SELECT COUNT(*) FROM users WHERE $devWhere");
            $stmtCheckLimit->execute($devArgsFull);
            $cnt = (int)$stmtCheckLimit->fetchColumn();

            if ($cnt >= $maxUsersPerDevice) {
                $stmtPrev = $pdo->prepare("SELECT username, sticker FROM users WHERE $devWhere ORDER BY last_activity DESC LIMIT 5");
                $stmtPrev->execute($devArgsFull);
                $prevAccounts = $stmtPrev->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['status' => 'capacity_full', 'previous_users' => $prevAccounts]);
                exit;
            }

            $randSticker = $isAdminLogin ? '✔️' : $stickers[array_rand($stickers)];

            $stmt = $pdo->prepare("SELECT id FROM messages ORDER BY id DESC LIMIT 1");
            $stmt->execute();
            $lastId = $stmt->fetchColumn() ?: 0;

            $generatedSecurityCode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));

            $stmt = $pdo->prepare("INSERT INTO users (username, sticker, user_agent, ip_address, is_online, last_notif_id, device_token, security_code, fp, fp_coarse) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?)");
            $stmt->execute([$u, $randSticker, $ua, $ip, $lastId, $deviceToken, $generatedSecurityCode, $fpIn !== '' ? $fpIn : null, $fpcIn !== '' ? $fpcIn : null]);
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

        $quickGate = veyrax_login_gate($pdo, $u, ($u === $admin_username || is_admin_user($u, $pdo)));
        if ($quickGate) { echo json_encode($quickGate); exit; }

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
            $banType = $rem > (5 * 365 * 24 * 3600) ? 'perm' : 'temp';
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
            $qlStmt = $pdo->prepare("SELECT phone_number FROM users WHERE id = ?");
            $qlStmt->execute([$user['id']]);
            $qlRow = $qlStmt->fetch();
            $qlHasAuth = !empty($qlRow['phone_number']) || veyrax_get_auth_hash($pdo, $user['id']) !== '';
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
            $stmt = $pdo->prepare("SELECT username, sticker FROM users WHERE ((device_token IS NOT NULL AND device_token != '' AND device_token = ?) OR (ip_address = ? AND user_agent = ?)) AND username != ? ORDER BY last_activity DESC LIMIT 5");
            $stmt->execute([$cookieToken, $clientIp, $clientUa, $admin_username]);
        } else {
            $stmt = $pdo->prepare("SELECT username, sticker FROM users WHERE ip_address = ? AND user_agent = ? AND username != ? ORDER BY last_activity DESC LIMIT 5");
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
        if (!empty($_SESSION['is_demo'])) {
            // A visitor only ever sees the sandbox, so the real head-count
            // would be a lie (and a small leak). Count demo sessions instead.
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE is_online = 1 AND is_demo = 1 AND last_activity > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
            $stmt->execute();
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE is_online = 1 AND username != ? AND is_demo = 0");
            $stmt->execute([$admin_username]);
        }
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
