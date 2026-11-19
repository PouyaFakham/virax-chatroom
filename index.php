<?php
require_once 'config.php';
try {
    $lStmt = $pdo->prepare("SELECT value FROM settings WHERE name = 'site_lock'");
    $lStmt->execute();
    if ($lStmt->fetchColumn() == '1') {
        // The admin can write the reason next to the site-lock switch; it is
        // rendered by the shared lock screen so this page and the ban screen
        // stay visually identical.
        $lockReason = '';
        try {
            $rStmt = $pdo->prepare("SELECT value FROM settings WHERE name = 'site_lock_reason'");
            $rStmt->execute();
            $lockReason = trim((string)$rStmt->fetchColumn());
        } catch (Exception $e) {}
        veyrax_lock_page([
            'variant' => 'lock',
            'title'   => 'دسترسی موقتاً محدود است',
            'reason'  => $lockReason,
            'status'  => 503,
        ]);
    }
} catch (Exception $e) {}

$isReportLocked = false;
try {
    $stmtRep = $pdo->prepare("SELECT value FROM settings WHERE name = 'report_lock'");
    $stmtRep->execute();
    $isReportLocked = ($stmtRep->fetchColumn() == '1');
} catch (Exception $e) {}

// Access state for the login screen. The same three switches are enforced in
// requests.php; here they only decide what the visitor is shown.
$vxDemoOn      = veyrax_demo_enabled($pdo);
$vxRegLocked   = veyrax_registration_locked($pdo);
$vxLoginLocked = veyrax_login_locked($pdo);

if (empty($_SESSION['logout_nonce'])) {
    $_SESSION['logout_nonce'] = bin2hex(random_bytes(16));
}

$secCode = '';
$uiColor = '';
$uiScale = 1.0;
$reqMode = 'telegram';
$cacheEpoch = 0;
$cacheTtlHours = 24;
try {
    $reqMode = strtolower(trim(veyrax_setting($pdo, 'request_mode', 'telegram')));
    if ($reqMode !== 'normal' && $reqMode !== 'telegram') $reqMode = 'telegram';
    $cacheEpoch = (int)veyrax_setting($pdo, 'cache_epoch', '0');
    $cacheTtlHours = (int)veyrax_setting($pdo, 'cache_ttl_hours', '24');
    if ($cacheTtlHours < 1 || $cacheTtlHours > 720) $cacheTtlHours = 24;
} catch (Exception $e) {}
$welcomeSeen = 0;
if (isset($_SESSION['user_id'])) {
    try {
        $stmtSec = $pdo->prepare("SELECT security_code, welcome_seen, sticker FROM users WHERE id = ?");
        $stmtSec->execute([$_SESSION['user_id']]);
        $secRow = $stmtSec->fetch(PDO::FETCH_ASSOC);
        if ($secRow) {
            $secCode = $secRow['security_code'];
            $welcomeSeen = (int)($secRow['welcome_seen'] ?? 0);
            // Keep the session copy fresh — the page renders its first frame
            // from this value, and a stale one shows the wrong sticker on your
            // own messages until the first fetch lands.
            $_SESSION['sticker'] = ($secRow['sticker'] ?? '') !== '' ? $secRow['sticker'] : '👤';
        }
    } catch (Exception $e) {}
    list($uiColor, $uiScale) = veyrax_user_ui_prefs($pdo, (int)$_SESSION['user_id']);
}

if (!isset($_SESSION['user_id'])) {
    $clientIp = get_client_ip();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $deviceToken = $_COOKIE['device_token'] ?? '';
    
    if (!empty($deviceToken)) {
        $stmtPrevUsers = $pdo->prepare("
            SELECT username, sticker, id 
            FROM users 
            WHERE (
                (device_token != '' AND device_token IS NOT NULL AND device_token = ?)
                OR (ip_address = ? AND user_agent = ?)
            )
            AND username != ?
            ORDER BY last_activity DESC 
            LIMIT 5
        ");
        $stmtPrevUsers->execute([$deviceToken, $clientIp, $userAgent, $admin_username]);
    } else {
        $stmtPrevUsers = $pdo->prepare("
            SELECT username, sticker, id 
            FROM users 
            WHERE ip_address = ?
            AND username != ?
            ORDER BY last_activity DESC 
            LIMIT 5
        ");
        $stmtPrevUsers->execute([$clientIp, $admin_username]);
    }
    $previousUsers = $stmtPrevUsers->fetchAll(PDO::FETCH_ASSOC);
    
    $stmtTotalUsers = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username != ?");
    $stmtTotalUsers->execute([$admin_username]);
    $totalUsers = (int)$stmtTotalUsers->fetchColumn();
    $stmtMaxU = $pdo->prepare("SELECT value FROM settings WHERE name = 'max_users'");
    $stmtMaxU->execute();
    $maxUsersVal = $stmtMaxU->fetchColumn();
    $maxUsers = ($maxUsersVal !== false && (int)$maxUsersVal > 0) ? (int)$maxUsersVal : 3;
    $canCreateNew = ($totalUsers < $maxUsers);
    
    $n1 = rand(10, 50);
    $n2 = rand(1, 9);
    $_SESSION['captcha_result'] = $n1 + $n2;
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, interactive-widget=resizes-content">
    <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token']; ?>">
    <meta name="theme-color" content="#1e293b">
    <title>ورود و عضویت | <?php echo htmlspecialchars($site_brand_fa, ENT_QUOTES, 'UTF-8'); ?></title>
    <?php veyrax_theme_bootstrap(); ?>
    <?php veyrax_icon_links(); ?>
    <?php veyrax_ui_kit(); ?>
    <script src="assets/js/vx-fp.js?v=<?php echo VEYRAX_ASSET_VERSION; ?>"></script>
    <!-- Fetch the two UI fonts at the highest priority. With font-display:swap
         the first paint uses a fallback face and every line of text reflows
         when the real font lands — which reads as "the message settles late".
         Preloading moves that swap ahead of the first message paint. -->
    <link rel="preload" as="font" type="font/ttf" crossorigin href="fonts/<?php echo rawurlencode($font_text); ?>.ttf">
    <link rel="preload" as="font" type="font/ttf" crossorigin href="fonts/<?php echo rawurlencode($font_heading); ?>.ttf">
    <style>
        @font-face { font-family: 'AppFont'; src: url('fonts/<?php echo $font_text; ?>.ttf') format('truetype'); font-weight: normal; font-display: swap; }
        @font-face { font-family: 'AppBold'; src: url('fonts/<?php echo $font_heading; ?>.ttf') format('truetype'); font-weight: bold; font-display: swap; }
        * { font-family: 'AppFont', 'Noto Color Emoji', 'Segoe UI Emoji', 'Apple Color Emoji', sans-serif; box-sizing: border-box; outline: none; -webkit-tap-highlight-color: transparent; }
        body { margin: 0; padding: 16px; background-color: var(--v-bg-0, #0f172a); color: #fff; display: flex; flex-direction: column; justify-content: center; align-items: center; min-height: 100vh; min-height: 100dvh; overflow-x: hidden; overflow-y: auto; background-image: radial-gradient(880px 520px at 50% -12%, var(--v-glow-a, rgba(99,102,241,0.2)), transparent 62%), radial-gradient(720px 440px at 10% 108%, var(--v-glow-b, rgba(99,102,241,0.07)), transparent 60%), linear-gradient(180deg, var(--v-bg-1, #131a2b) 0%, var(--v-bg-0, #0b1020) 52%, var(--v-bg-2, #080b14) 100%); background-attachment: fixed; -webkit-touch-callout: none; }
        .login-card { background: linear-gradient(180deg, var(--v-surface-2a, rgba(30,41,59,0.9)), var(--v-surface-a, rgba(30,41,59,0.85))); backdrop-filter: blur(20px); padding: 40px 30px; border-radius: 28px; width: 90%; max-width: 400px; text-align: center; border: 1px solid var(--v-border, rgba(255,255,255,0.1)); box-shadow: 0 25px 60px rgba(0,0,0,0.6), inset 0 1px 0 rgba(255,255,255,0.05); margin: auto 0; transition: 0.3s; }
        @media (max-width: 480px), (max-height: 800px) {
            body { padding: 10px; }
            .login-card { padding: 24px 18px; border-radius: 22px; width: 100%; }
            .brand-title { font-size: 24px; }
            .brand-subtitle { margin-bottom: 16px; }
            input, .captcha-text, .refresh-btn, button, .btn-sec-code { height: 46px; }
            .refresh-btn { width: 46px; flex-shrink: 0; }
            #serverStatusBar { padding: 7px 10px; border-radius: 12px; margin-bottom: 13px; }
            .ssb-users, .ssb-users svg { width: 12px; height: 12px; }
        }
        @media (max-height: 700px) {
            .login-card { padding: 18px 16px; }
            .brand-title { font-size: 21px; }
            .brand-subtitle { margin-bottom: 10px; font-size: 12px; }
            input { margin-bottom: 10px; }
            .captcha-row { margin-bottom: 10px; }
            .btn-sec-code { margin-top: 7px; }
        }
        .brand-head { display: flex; align-items: center; justify-content: center; gap: 9px; margin: 0 0 11px; flex-wrap: nowrap; min-width: 0; }
        .brand-mark { flex: 0 0 30px; width: 30px; height: 30px; border-radius: 9px; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, var(--v-primary, #6366f1), var(--v-primary-active, #4f46e5)); color: var(--v-on-primary, #fff); font-family: 'AppBold'; font-size: 13px; overflow: hidden; box-shadow: 0 6px 16px -8px var(--v-primary-glow, rgba(99,102,241,0.7)); }
        .brand-mark img { width: 100%; height: 100%; object-fit: cover; }
        .brand-lockup { display: inline-flex; align-items: center; gap: 8px; min-width: 0; }
        .brand-wordmark { font-family: 'AppBold'; font-size: 12.5px; color: #8fa1ba; letter-spacing: .1px; white-space: nowrap; }
        .brand-divider { flex: 0 0 1px; width: 1px; height: 14px; border-radius: 1px; background: linear-gradient(180deg, transparent, var(--v-primary-border, rgba(99,102,241,0.6)), transparent); }
        .brand-room { font-family: 'AppBold'; font-size: 13px; color: #eaf0fb; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 44vw; }
        .brand-title { font-family: 'AppBold'; font-size: 26px; margin: 0 0 6px; color: #fff; letter-spacing: -0.2px; }
        .brand-subtitle { font-size: 12.5px; color: #94a3b8; margin-bottom: 26px; line-height: 1.8; }
        .brand-subtitle b { color: #c7d2fe; font-weight: bold; }
        #serverStatusBar {
            --ssb-accent: #fbbf24; --ssb-text: #fcd34d; --ssb-glow: rgba(251,191,36,0.16);
            position: relative; isolation: isolate; overflow: hidden;
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            width: 100%; margin: 0 0 16px; padding: 9px 12px; border-radius: 14px;
            background: linear-gradient(180deg, rgba(255,255,255,0.155), rgba(255,255,255,0.065));
            border: 1px solid rgba(255,255,255,0.185);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.17), 0 10px 22px -18px rgba(0,0,0,0.95);
            cursor: pointer; user-select: none; -webkit-tap-highlight-color: transparent;
            transition: background .22s ease, border-color .22s ease, box-shadow .22s ease, transform .15s ease;
        }
        #serverStatusBar::before {
            content: ''; position: absolute; z-index: -1; pointer-events: none;
            inset-inline-start: -34px; top: 50%; width: 120px; height: 120px;
            transform: translateY(-50%); border-radius: 50%;
            background: radial-gradient(circle, var(--ssb-glow), transparent 68%);
            transition: background .3s ease;
        }
        #serverStatusBar::after {
            content: ''; position: absolute; inset: 0 18% auto; height: 1px; pointer-events: none;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.42), transparent);
            opacity: .75;
        }
        #serverStatusBar:hover {
            background: linear-gradient(180deg, rgba(255,255,255,0.175), rgba(255,255,255,0.075));
            border-color: rgba(255,255,255,0.24);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.26), 0 14px 26px -18px rgba(0,0,0,1);
        }
        #serverStatusBar:active { transform: scale(.988); }
        #serverStatusBar:focus-visible { outline: 2px solid var(--v-primary, #6366f1); outline-offset: 2px; }
        #serverStatusBar[data-state="online"] { --ssb-accent: #34d399; --ssb-text: #6ee7b7; --ssb-glow: rgba(16,185,129,0.20); }
        #serverStatusBar[data-state="offline"] { --ssb-accent: #f87171; --ssb-text: #fca5a5; --ssb-glow: rgba(239,68,68,0.18); }
        .ssb-left, .ssb-right { display: flex; align-items: center; position: relative; z-index: 1; min-width: 0; }
        .ssb-left { gap: 8px; }
        .ssb-right { gap: 6px; flex: 0 0 auto; }
        .ssb-dot {
            position: relative; flex: 0 0 auto; width: 9px; height: 9px; border-radius: 50%;
            background: var(--ssb-accent);
            box-shadow: 0 0 0 3px rgba(255,255,255,0.05), 0 0 8px var(--ssb-accent);
            transition: box-shadow .25s ease;
        }
        .ssb-dot::after {
            content: ''; position: absolute; inset: -3px; border-radius: 50%;
            border: 1px solid var(--ssb-accent); opacity: 0;
            animation: ssbRing 2.4s cubic-bezier(.2,.7,.4,1) infinite;
        }
        #serverStatusBar[data-state="checking"] .ssb-dot { animation: ssbBlink 1.1s ease-in-out infinite; }
        #serverStatusBar[data-state="offline"] .ssb-dot::after { animation: none; }
        @keyframes ssbRing {
            0% { opacity: .5; transform: scale(.5); }
            70%, 100% { opacity: 0; transform: scale(1.5); }
        }
        @keyframes ssbBlink {
            0%, 100% { opacity: 1; box-shadow: 0 0 0 3px rgba(255,255,255,0.05), 0 0 8px var(--ssb-accent); }
            50% { opacity: .45; box-shadow: 0 0 0 4px rgba(255,255,255,0.03), 0 0 3px var(--ssb-accent); }
        }
        .ssb-status {
            font-size: 11px; font-weight: 600; letter-spacing: .15px; white-space: nowrap;
            color: var(--ssb-text);
        }
        .ssb-ms {
            display: none; align-items: center; height: 17px; padding: 0 6px; border-radius: 6px;
            background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.09);
            color: #94a3b8; font-size: 10px; font-family: Consolas, monospace; direction: ltr;
            font-variant-numeric: tabular-nums; white-space: nowrap;
        }
        .ssb-ms:not(:empty) { display: inline-flex; }
        #serverStatusBar[data-state="online"] .ssb-ms {
            color: var(--ssb-text); background: rgba(16,185,129,0.10); border-color: rgba(16,185,129,0.24);
        }
        #serverStatusBar[data-state="offline"] .ssb-ms {
            color: var(--ssb-text); background: rgba(239,68,68,0.10); border-color: rgba(239,68,68,0.22);
        }
        .ssb-sep {
            flex: 0 0 1px; width: 1px; height: 14px;
            background: linear-gradient(180deg, transparent, rgba(255,255,255,0.18), transparent);
        }
        .ssb-users {
            display: inline-flex; align-items: center; justify-content: center;
            width: 13px; height: 13px; color: #b6c2d4;
            transition: color .2s ease;
        }
        .ssb-users svg { display: block; }
        .ssb-count {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 20px; height: 18px; padding: 0 6px; border-radius: 999px;
            background: rgba(255,255,255,0.085); border: 1px solid rgba(255,255,255,0.11);
            color: var(--v-accent, #a5b4fc); font-family: 'AppBold', sans-serif; font-size: 11px;
            line-height: 1; font-variant-numeric: tabular-nums;
            transition: transform .26s cubic-bezier(.2,1.5,.4,1), background .2s ease, color .2s ease;
        }
        .ssb-count.is-bump { transform: scale(1.18); }
        .ssb-count-label { font-size: 10.5px; color: #94a3b8; white-space: nowrap; }
        @media (prefers-reduced-motion: reduce) {
            .ssb-dot::after, #serverStatusBar[data-state="checking"] .ssb-dot { animation: none; }
        }
        input { width: 100%; height: 50px; background: var(--v-input, rgba(15, 23, 42, 0.7)); border: 1px solid var(--v-border-strong, #334155); color: #fff; border-radius: 14px; text-align: center; font-size: 16px; margin-bottom: 15px; transition: 0.3s; }
        input:focus { border-color: var(--v-primary, #6366f1); background: var(--v-input, rgba(15, 23, 42, 0.95)); }
        input[type=number]::-webkit-inner-spin-button, input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        input[type=number] { -moz-appearance: textfield; }
        
        .input-error { border-color: #ef4444 !important; background: rgba(239, 68, 68, 0.1) !important; animation: shake 0.4s; }
        .error-msg-box {
            display: none; width: 100%; margin: 2px 0 12px; padding: 10px 38px 10px 12px;
            border-radius: 12px; font-size: 12px; line-height: 1.7; color: #fca5a5;
            background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.22);
            word-break: break-word; overflow-wrap: break-word; position: relative;
        }
        .error-msg-box::before {
            content: '!'; position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            width: 18px; height: 18px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: bold; font-family: 'AppBold', sans-serif;
            background: rgba(239,68,68,0.18); color: #f87171;
        }
        .error-msg-box.is-ok { color: #86efac; background: rgba(34,197,94,0.08); border-color: rgba(34,197,94,0.26); }
        .error-msg-box.is-ok::before { content: '✓'; background: rgba(34,197,94,0.18); color: #4ade80; }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        .captcha-row { display: flex; gap: 10px; margin-bottom: 15px; align-items: stretch; }
        .captcha-text { flex: 1; height: 50px; background: var(--v-primary-soft, var(--v-primary-glow, rgba(99, 102, 241, 0.1))); border: 1px dashed var(--v-primary-border, var(--v-primary-border, rgba(99,102,241,0.4))); border-radius: 14px; display: flex; justify-content: center; align-items: center; font-weight: bold; color: var(--v-primary-hover, var(--v-accent, #a5b4fc)); font-size: 20px; font-family: 'AppBold'; overflow: hidden; white-space: nowrap; user-select: none; transition: border-color .25s, background .25s, transform .25s; }
        .captcha-text.captcha-loading { opacity: .45; border-color: rgba(148,163,184,.5); animation: vCaptchaPulse 0.7s ease-in-out infinite; }
        .captcha-text.captcha-pop { animation: vCaptchaPop .4s cubic-bezier(.2,1.4,.4,1); }
        .refresh-btn { width: 50px; height: 50px; padding: 0; margin: 0; flex: 0 0 50px; background: var(--v-input, rgba(15, 23, 42, 0.7)); border: 1px solid var(--v-border-strong, #334155); border-radius: 14px; color: #94a3b8; cursor: pointer; display: flex; justify-content: center; align-items: center; font-size: 22px; line-height: 1; box-shadow: none; transition: background .25s, color .25s, border-color .25s, transform .15s; -webkit-tap-highlight-color: transparent; }
        .refresh-btn:hover { background: rgba(99,102,241,.18); color: #fff; border-color: var(--v-primary-border, rgba(99,102,241,.45)); }
        .refresh-btn:active { transform: scale(.94); }
        .refresh-btn .rf-ico { display: block; transition: transform .32s cubic-bezier(.2,.8,.2,1); }
        .refresh-btn:hover .rf-ico { transform: rotate(120deg); }
        .refresh-btn.rotating .rf-ico { animation: rfSpin .72s cubic-bezier(.2,.8,.2,1); }
        @keyframes rfSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        .refresh-btn.rotating { animation: spin-icon 0.75s cubic-bezier(.4,0,.2,1); background: var(--v-primary-border, rgba(99, 102, 241, 0.3)); color: #fff; border-color: var(--v-accent, #a5b4fc); }
        @keyframes vCaptchaPulse { 0%,100% { opacity: .35; } 50% { opacity: .7; } }
        @keyframes vCaptchaPop { 0% { transform: scale(.88); opacity: .3; } 60% { transform: scale(1.04); opacity: 1; } 100% { transform: scale(1); } }
        button { width: 100%; height: 50px; background: linear-gradient(135deg, var(--v-primary, #6366f1), var(--v-primary-active, #4f46e5)); color: var(--v-on-primary, #fff); border: none; border-radius: 14px; font-weight: bold; font-family: 'AppBold'; font-size: 16px; cursor: pointer; margin-top: 5px; box-shadow: none; transition: 0.3s; }
        button:hover { transform: translateY(-2px); background: linear-gradient(135deg, var(--v-primary-hover, var(--v-accent, #a5b4fc)), var(--v-primary, #6366f1)); }
        .btn-secondary { background: linear-gradient(135deg, #334155, #1e293b); box-shadow: none; margin-bottom: 10px; border: 1px solid rgba(255,255,255,0.1); }
        .btn-secondary:hover { background: linear-gradient(135deg, #475569, #334155); border-color: rgba(255,255,255,0.2); }
        .btn-sec-code { width: 100%; height: 50px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12); color: #cbd5e1; border-radius: 14px; font-size: 14px; cursor: pointer; margin-top: 10px; transition: all 0.3s; box-shadow: none; font-family: 'AppFont', sans-serif; display: flex; align-items: center; justify-content: center; gap: 8px; letter-spacing: 0.3px; }
        .btn-sec-code:hover { background: var(--v-soft, rgba(99,102,241,0.15)); border-color: rgba(99,102,241,0.4); color: #e2e8f0; transform: translateY(-1px); }
        .code-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); z-index: 3500; display: none; justify-content: center; align-items: center; padding: 20px; box-sizing: border-box; }
        .code-modal-overlay.open { display: flex; }
        .code-modal-card { background: rgba(30,41,59,0.98); border: 1px solid rgba(255,255,255,0.1); border-radius: 28px; width: 100%; max-width: 400px; max-height: min(90vh, 100%); overflow-y: auto; padding: 36px 30px 30px; position: relative; box-shadow: 0 30px 80px rgba(0,0,0,0.7); animation: codeCardIn 0.3s cubic-bezier(0.2,0.8,0.2,1); -webkit-overflow-scrolling: touch; }
        @keyframes codeCardIn { from { opacity:0; transform: scale(0.94) translateY(16px); } to { opacity:1; transform: scale(1) translateY(0); } }
        .code-modal-title { font-family: 'AppBold'; font-size: 22px; color: #fff; margin-bottom: 5px; text-align: center; }
        .code-modal-sub { font-size: 13px; color: #64748b; text-align: center; margin-bottom: 28px; }
        .code-modal-close { position: absolute; top: 18px; left: 18px; width: 32px; height: 32px; border-radius: 50%; background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.1); color: #94a3b8; font-size: 15px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.2s; }
        .code-modal-close:hover { background: rgba(239,68,68,0.2); border-color: rgba(239,68,68,0.4); color: #ef4444; }
        .code-modal-btn { width: 100%; height: 50px; background: linear-gradient(135deg, var(--v-primary, #6366f1), var(--v-primary-active, #4f46e5)); color: #fff; border: none; border-radius: 14px; font-family: 'AppBold'; font-size: 16px; cursor: pointer; margin-top: 6px; box-shadow: 0 8px 25px -5px rgba(99,102,241,0.4); transition: 0.3s; }
        .code-modal-btn:hover { background: linear-gradient(135deg, var(--v-primary, #6366f1), var(--v-primary-active, #4f46e5)); filter: brightness(1.07); transform: translateY(-1px); box-shadow: 0 12px 28px -8px var(--v-primary-glow, rgba(99,102,241,0.55)); }
        .code-modal-btn:active { transform: translateY(0) scale(0.99); filter: brightness(0.97); }

        /* ── Login helper buttons (compact row, icon-only on phones) ───────── */
        .login-aux { display: flex; gap: 8px; margin-top: 12px; }
        .aux-btn {
            flex: 1 1 0; min-width: 0; height: 42px; padding: 0 8px;
            display: flex; align-items: center; justify-content: center; gap: 6px;
            border-radius: 12px; background: rgba(255,255,255,0.04);
            border: 1px solid rgba(148,163,184,0.14); color: #cbd5e1;
            font-size: 12px; font-family: inherit; cursor: pointer;
            transition: background .18s, border-color .18s, color .18s, transform .12s;
        }
        .aux-btn:hover { background: var(--v-soft, rgba(99,102,241,0.15)); border-color: var(--v-primary-border, rgba(99,102,241,0.4)); color: #fff; transform: translateY(-1px); }
        .aux-btn:active { transform: scale(0.97); }
        .aux-ico { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; line-height: 1; }
        .aux-ico img { width: 18px; height: 18px; object-fit: contain; display: block; }
        @media (max-width: 480px) {
            .aux-label { display: none; }
            .aux-btn { height: 40px; }
            .aux-ico, .aux-ico img { width: 20px; height: 20px; }
        }

        .ql-row { display: none; align-items: center; justify-content: space-between; gap: 10px; margin-top: 12px; padding: 9px 9px 9px 11px; border-radius: 14px; background: rgba(255,255,255,0.035); border: 1px solid var(--v-border, rgba(255,255,255,0.08)); transition: border-color .2s, background .2s; }
        .ql-row:hover { border-color: var(--v-border-strong, rgba(255,255,255,0.16)); background: rgba(255,255,255,0.055); }
        .ql-box { display: flex; align-items: center; gap: 9px; min-width: 0; flex: 1 1 auto; cursor: pointer; user-select: none; -webkit-user-select: none; -webkit-tap-highlight-color: transparent; }
        .ql-box input { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
        .ql-mark { width: 21px; height: 21px; flex: 0 0 21px; border-radius: 7px; border: 1.6px solid rgba(148,163,184,0.42); background: rgba(2,6,23,0.55); display: flex; align-items: center; justify-content: center; transition: background .18s, border-color .18s, box-shadow .18s, transform .12s; }
        .ql-mark svg { width: 12px; height: 12px; fill: none; stroke: var(--v-on-primary, #fff); stroke-width: 3.4; stroke-linecap: round; stroke-linejoin: round; opacity: 0; transform: scale(0.45); transition: opacity .16s, transform .18s cubic-bezier(.2,.8,.2,1); }
        .ql-box input:checked + .ql-mark { background: linear-gradient(145deg, var(--v-primary, #6366f1), var(--v-primary-active, #4f46e5)); border-color: transparent; box-shadow: 0 6px 14px -6px var(--v-primary-glow, rgba(99,102,241,0.9)); }
        .ql-box input:checked + .ql-mark svg { opacity: 1; transform: scale(1); }
        .ql-box input:focus-visible + .ql-mark { box-shadow: 0 0 0 3px var(--v-primary-soft, rgba(99,102,241,0.25)); }
        .ql-box:active .ql-mark { transform: scale(0.92); }
        .ql-text { display: flex; flex-direction: column; gap: 1px; min-width: 0; text-align: right; }
        .ql-title { font-size: 12px; color: #dbe3ee; line-height: 1.35; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ql-sub { font-size: 10px; color: #7c8aa0; line-height: 1.3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .ql-open { flex: 0 0 auto; width: auto; height: 30px; margin: 0; padding: 0 11px; border-radius: 10px; border: 1px solid var(--v-primary-border, rgba(99,102,241,0.4)); background: var(--v-primary-soft, rgba(99,102,241,0.14)); color: var(--v-accent, #a5b4fc); font-family: 'AppFont'; font-weight: normal; font-size: 11px; cursor: pointer; box-shadow: none; transition: background .18s, color .18s, transform .12s; }
        .ql-open:hover { background: var(--v-soft, rgba(99,102,241,0.22)); color: #fff; }
        .ql-open:active { transform: scale(0.96); }
        @media (max-width: 360px) { .ql-open { display: none; } .ql-title { font-size: 11.5px; } }

        /* ── Security-code recovery modal ──────────────────────────────────── */        .sr-code { letter-spacing: 3px; font-weight: bold; font-family: monospace; text-align: center; }
        .sr-divider { display: flex; align-items: center; gap: 10px; margin: 16px 0 12px; color: #64748b; font-size: 11px; }
        .sr-divider::before, .sr-divider::after { content: ''; flex: 1; height: 1px; background: rgba(148,163,184,0.15); }
        .sr-optional-toggle { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin: 14px 0 10px; padding: 10px 12px; border-radius: 11px; background: rgba(148,163,184,0.06); border: 1px dashed rgba(148,163,184,0.22); color: #94a3b8; font-size: 12px; cursor: pointer; transition: background .18s, color .18s; }
        .sr-optional-toggle:hover { background: rgba(148,163,184,0.1); color: #cbd5e1; }
        .sr-optional-toggle.open { color: var(--v-accent, #a5b4fc); border-color: var(--v-primary-border, rgba(99,102,241,0.4)); }
        .sr-chev { transition: transform .2s ease; font-size: 11px; }
        .sr-optional-toggle.open .sr-chev { transform: rotate(180deg); }
        .sr-msg { display: none; margin-top: 10px; padding: 8px 10px; border-radius: 10px; font-size: 12px; text-align: center; line-height: 1.7; }
        .sr-msg.ok { display: block; background: rgba(16,185,129,0.12); border: 1px solid rgba(16,185,129,0.3); color: #6ee7b7; }
        .sr-msg.err { display: block; background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.3); color: #fca5a5; }
        .sr-sms-link { width: 100%; margin-top: 10px; background: none; border: 0; color: #64748b; font-size: 11px; font-family: inherit; cursor: pointer; text-decoration: underline; }
        .sr-sms-link:hover { color: var(--v-accent, #a5b4fc); }

        /* ── Custom colour picker (works with the native mobile picker) ─────── */
        .cp-card { max-width: 380px; }
        .cp-preview { height: 56px; border-radius: 14px; margin-bottom: 16px; border: 1px solid rgba(255,255,255,0.14); background: #6366f1; transition: background .15s ease; }
        .cp-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
        .cp-dot { width: 100%; min-width: 0; aspect-ratio: 1 / 1; border-radius: 12px; border: 2px solid transparent; cursor: pointer; transition: transform .12s ease, border-color .15s ease; -webkit-tap-highlight-color: transparent; }
        .cp-dot:active { transform: scale(0.92); }
        .cp-dot.active { border-color: #fff; box-shadow: 0 0 0 2px rgba(0,0,0,0.35); }
        .cp-native { display: flex; align-items: center; justify-content: center; gap: 10px; height: 50px; margin-bottom: 12px; border-radius: 12px; background: rgba(255,255,255,0.04); border: 1px dashed rgba(148,163,184,0.35); color: #cbd5e1; font-size: 13px; cursor: pointer; }
        .cp-native input[type="color"] { width: 34px; height: 34px; padding: 0; border: 0; border-radius: 10px; background: none; cursor: pointer; }
        @media (max-width: 420px) { .cp-grid { gap: 8px; } .cp-preview { height: 48px; } }
        .code-modal-back { width: 100%; height: 44px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.10); color: rgba(255,255,255,0.55); font-size: 13px; cursor: pointer; margin-top: 8px; font-family: 'AppFont', sans-serif; transition: all 0.25s ease; border-radius: 12px; backdrop-filter: blur(8px); display: flex; align-items: center; justify-content: center; gap: 7px; letter-spacing: 0.3px; }
        .code-modal-back:hover { background: rgba(255,255,255,0.09); border-color: rgba(255,255,255,0.22); color: rgba(255,255,255,0.85); transform: translateY(-1px); }
        .fp-hint { font-size: 11px; color: #94a3b8; text-align: center; margin: -4px 0 10px; line-height: 1.7; min-height: 14px; }
        .fp-phone-chip { display: inline-flex; align-items: center; gap: 6px; margin: 0 auto 12px; padding: 6px 14px; border-radius: 999px; background: var(--v-primary-soft, rgba(99,102,241,0.14)); border: 1px solid var(--v-primary-border, rgba(99,102,241,0.35)); color: var(--v-accent, #a5b4fc); font-size: 12px; direction: ltr; }
        #forgotStep2 { text-align: center; }
        #forgotStep2 .code-modal-sub { margin-bottom: 8px; }
        /* ── Phone password-reset modal ────────────────────────────────────── */
        .fp-mark { display: grid; place-items: center; width: 56px; height: 56px; margin: 0 auto 12px; border-radius: 18px; background: radial-gradient(circle at 50% 20%, var(--v-primary-soft, rgba(99,102,241,0.25)), rgba(15,23,42,0.6)); border: 1px solid var(--v-primary-border, rgba(99,102,241,0.4)); box-shadow: 0 10px 30px -8px var(--v-primary-glow, rgba(99,102,241,0.5)); }
        .fp-mark img { width: 30px; height: 30px; object-fit: contain; filter: drop-shadow(0 4px 8px rgba(0,0,0,0.4)); }
        #forgotPhoneModal .code-modal-sub b { color: #cbd5e1; }
        .fp-error { margin-top: 14px; padding: 10px 14px; border-radius: 12px; background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.35); color: #fca5a5; font-size: 12.5px; line-height: 1.8; text-align: center; }
        #fpDevCode { font-weight: bold; letter-spacing: 2px; }
        .fp-done-ico { width: 62px; height: 62px; margin: 4px auto 14px; display: grid; place-items: center; border-radius: 50%; background: rgba(16,185,129,0.14); border: 1.5px solid rgba(16,185,129,0.5); color: #34d399; font-size: 28px; font-weight: bold; box-shadow: 0 0 30px rgba(16,185,129,0.25); animation: fpPop .4s cubic-bezier(.2,.9,.3,1.4); }
        @keyframes fpPop { from { transform: scale(.4); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .code-modal-btn[disabled], .code-modal-back[disabled] { opacity: .5; cursor: not-allowed; pointer-events: none; filter: saturate(.4); }
        .code-modal-btn.busy { position: relative; color: transparent !important; pointer-events: none; }
        .code-modal-btn.busy::after { content: ''; position: absolute; inset: 0; margin: auto; width: 22px; height: 22px; border-radius: 50%; border: 3px solid rgba(255,255,255,0.35); border-top-color: #fff; animation: fpSpin .7s linear infinite; }
        @keyframes fpSpin { to { transform: rotate(360deg); } }
        .fp-resend-row { display: flex; flex-direction: column; align-items: center; gap: 4px; margin-top: 10px; }
        .fp-resend-timer { font-size: 11px; color: #64748b; }
        .fp-resend-btn { width: auto; height: auto; margin: 0; padding: 6px 12px; background: transparent; border: none; color: var(--v-accent, #a5b4fc); font-size: 12px; cursor: pointer; box-shadow: none; font-family: 'AppFont', sans-serif; }
        .fp-resend-btn:hover { background: transparent; color: #fff; transform: none; text-decoration: underline; }
        .code-modal-input { display: block; width: 100%; height: 50px; background: var(--v-input, rgba(15,23,42,0.7)); border: 1px solid var(--v-border-strong, #334155); color: #fff; border-radius: 14px; text-align: center; font-size: 16px; margin-bottom: 15px; transition: 0.3s; outline: none; font-family: 'AppFont', sans-serif; padding: 0 12px; }
        .code-modal-input:focus { border-color: var(--v-primary, #6366f1); background: rgba(15,23,42,0.95); }
        .code-modal-input[type=number]::-webkit-inner-spin-button, .code-modal-input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        .code-modal-input[type=number] { -moz-appearance: textfield; }
        
        #toast { position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%) translateY(50px); background: rgba(30, 41, 59, 0.95); backdrop-filter: blur(10px); border: 1px solid rgba(239, 68, 68, 0.5); color: white; padding: 15px 25px; border-radius: 20px; font-size: 14px; opacity: 0; transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); pointer-events: none; z-index: 999; max-width: 90%; width: max-content; text-align: center; line-height: 1.6; box-shadow: 0 10px 40px rgba(0,0,0,0.5); }
        #toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
        /* Success variant — a clean logout should not look like an error. */
        #toast.toast-ok { border-color: rgba(16, 185, 129, 0.55); background: rgba(6, 78, 59, 0.94); box-shadow: 0 10px 40px rgba(16, 185, 129, 0.22); }
        #toast.toast-ok::before { content: "✓"; display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; margin-inline-end: 8px; border-radius: 50%; background: rgba(16, 185, 129, 0.22); color: #6ee7b7; font-size: 11px; font-weight: bold; vertical-align: -2px; }
        
        #securityCodeInput::placeholder { font-family: 'AppFont'; opacity: 0.7; letter-spacing: 0px; font-weight: normal; }

        .hidden { display: none !important; }
        #loadingOverlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.95); z-index: 2000; display: none; flex-direction: column; justify-content: center; align-items: center; }
        .spinner { width: 50px; height: 50px; border: 5px solid rgba(255,255,255,0.1); border-top-color: var(--v-primary, #6366f1); border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 20px; }
        button[type=submit]:disabled { opacity: 0.65; cursor: not-allowed; transform: none; }
        @keyframes vAIPulse { 0%,100% { opacity: 0.35; transform: scale(0.85); } 50% { opacity: 1; transform: scale(1.15); } }
        .v-theme-row { display: flex; gap: 8px; justify-content: center; align-items: center; margin-top: 14px; flex-wrap: wrap; }
        .v-swatch { width: 26px; height: 26px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.25); cursor: pointer; transition: transform 0.15s, box-shadow 0.15s; padding: 0; }
        .v-swatch:hover { transform: scale(1.15); box-shadow: 0 0 0 3px rgba(255,255,255,0.15); }
        .v-swatch.active { border-color: #fff; box-shadow: 0 0 0 2px var(--v-primary, #6366f1); }
        .v-swatch-custom { background: conic-gradient(#ef4444, #f59e0b, #10b981, #3b82f6, #8b5cf6, #ef4444); }
        /* Instant theme switch: no transitions/animation while tokens change. */
        html.v-theme-instant, html.v-theme-instant *, html.v-theme-instant *::before, html.v-theme-instant *::after {
            transition: none !important; animation-duration: 0s !important;
        }
        /* Password fields: reveal button sits beside the box, never on top. */
        .v-pass-row { display: flex; align-items: stretch; gap: 8px; margin-bottom: 15px; }
        .v-pass-row > input { flex: 1 1 auto; min-width: 0; width: auto !important; margin-bottom: 0 !important; }
        .v-pass-row > .v-eye-btn {
            flex: 0 0 46px; width: 46px !important; height: auto !important; min-height: 46px;
            margin: 0 !important; padding: 0; display: flex; align-items: center; justify-content: center;
            background: rgba(15, 23, 42, 0.7); border: 1px solid #334155; border-radius: 12px;
            color: #94a3b8; cursor: pointer; font-size: 17px; box-shadow: none;
        }
        .v-pass-row > .v-eye-btn:hover { background: rgba(148,163,184,0.15); border-color: var(--v-primary, #6366f1); color: #e2e8f0; }
        .v-pass-row > .v-eye-btn:active { transform: scale(0.96); }
        .v-eye-ico { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; }
        .v-eye-btn .i3d, .v-eye-ico .i3d { width: 20px; height: 20px; filter: drop-shadow(0 2px 3px rgba(0,0,0,0.45)); }
        .eye-pulse .v-eye-ico { animation: vEyePop 0.18s cubic-bezier(.3,.8,.3,1); }
        @keyframes vEyePop { 0% { transform: scale(1); } 45% { transform: scale(0.8); } 100% { transform: scale(1); } }
        .login-footer { margin-top: 20px; display: flex; flex-direction: column; align-items: center; gap: 12px; font-size: 12px; color: #64748b; }
        /* One elegant meta line: terms · brand · version — glass chips that sit
           quiet at rest and lift gently on hover. The footer container shrinks
           to ~280px, so chips stay compact and the line never wraps. */
        .login-footer-meta { display: flex; align-items: center; justify-content: center; flex-wrap: nowrap; gap: 7px; white-space: nowrap; }
        .login-footer-meta .terms-link {
            display: inline-flex; align-items: center; gap: 4px;
            color: var(--v-accent, #a5b4fc); cursor: pointer; text-decoration: none;
            padding: 4px 10px; border-radius: 999px;
            background: rgba(148,163,184,0.08); border: 1px solid rgba(148,163,184,0.18);
            transition: transform .18s ease, background .18s ease, border-color .18s ease;
        }
        .login-footer-meta .terms-link:hover { background: rgba(99,102,241,0.14); border-color: rgba(99,102,241,0.4); transform: translateY(-1px); }
        .footer-dot { width: 3px; height: 3px; border-radius: 50%; background: rgba(148,163,184,0.45); flex-shrink: 0; }
        .login-footer-meta .dev-credit {
            text-decoration: none; cursor: pointer;
            font-family: 'AppBold', sans-serif; font-size: 12px; letter-spacing: 0.4px;
            color: #cbd5e1; padding: 4px 11px; border-radius: 999px;
            background: linear-gradient(160deg, rgba(99,102,241,0.16), rgba(99,102,241,0.06));
            border: 1px solid rgba(99,102,241,0.32);
            text-shadow: 0 1px 2px rgba(0,0,0,0.35);
            transition: transform .18s ease, border-color .18s ease;
        }
        .login-footer-meta .dev-credit:hover { transform: translateY(-1px); border-color: rgba(99,102,241,0.55); }
        .login-footer-meta .version-tag {
            font-size: 10px; letter-spacing: 0.8px; color: #94a3b8;
            padding: 4px 9px; border-radius: 999px;
            background: rgba(148,163,184,0.07); border: 1px solid rgba(148,163,184,0.16);
            font-family: 'Consolas', monospace; opacity: 0.95;
        }
        .login-footer-meta .donate-link {
            display: inline-flex; align-items: center; gap: 4px; text-decoration: none;
            color: #fbbf24; padding: 4px 10px; border-radius: 999px;
            background: rgba(251,191,36,0.08); border: 1px solid rgba(251,191,36,0.25);
            transition: transform .18s ease, background .18s ease;
        }
        .login-footer-meta .donate-link:hover { background: rgba(251,191,36,0.16); transform: translateY(-1px); }
        .terms-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 2500; display: flex; justify-content: center; align-items: center; backdrop-filter: blur(5px); opacity: 0; visibility: hidden; transition: 0.3s; }
        .terms-modal-overlay.active { opacity: 1; visibility: visible; }
        .terms-modal { background: var(--v-surface-2, #1e293b); width: 90%; max-width: 500px; max-height: 80vh; border-radius: 24px; border: 1px solid var(--v-border-strong, #334155); display: flex; flex-direction: column; position: relative; box-shadow: 0 25px 50px rgba(0,0,0,0.7); transform: scale(0.95); transition: 0.3s; }
        .terms-modal-overlay.active .terms-modal { transform: scale(1); }
        .terms-header { padding: 20px; border-bottom: 1px solid var(--v-border, #334155); display: flex; justify-content: space-between; align-items: center; }
        .terms-title { font-family: 'AppBold'; font-size: 18px; color: #fff; }
        .close-terms { cursor: pointer; color: #94a3b8; font-size: 24px; line-height: 1; }
        .terms-body { padding: 20px; overflow-y: auto; color: #cbd5e1; font-size: 14px; line-height: 1.8; text-align: justify; }
        
        .ban-modal-content { background: rgba(50, 0, 0, 0.8); backdrop-filter: blur(20px); border: 1px solid rgba(239, 68, 68, 0.4); background-image: linear-gradient(145deg, rgba(69, 10, 10, 0.9), rgba(26, 5, 5, 0.95)); border-radius: 30px; padding: 40px 30px; text-align: center; width: 100%; max-width: 450px; box-shadow: 0 25px 60px rgba(0,0,0,0.8); position: relative; overflow: hidden; margin: 0 auto; }
        .ban-modal-content::after { content: ''; position: absolute; inset: 0; border-radius: 30px; padding: 2px; background: linear-gradient(45deg, transparent, rgba(239, 68, 68, 0.3), transparent); -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0); -webkit-mask-composite: xor; mask-composite: exclude; pointer-events: none; }
        .ban-icon { font-size: 65px; margin-bottom: 20px; display: inline-block; filter: drop-shadow(0 0 25px rgba(239, 68, 68, 0.5)); animation: pulseBan 2s infinite; }
        .ban-title { color: #ef4444; font-size: 24px; margin-bottom: 15px; font-family: 'AppBold'; text-shadow: 0 0 20px rgba(239, 68, 68, 0.4); }
        .ban-message { color: #cbd5e1; font-size: 15px; line-height: 1.8; margin-bottom: 30px; background: rgba(0,0,0,0.25); padding: 20px; border-radius: 15px; border: 1px solid rgba(255,255,255,0.05); }
        .ban-btn { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 12px 30px; border-radius: 12px; cursor: pointer; transition: 0.3s; font-family: 'AppBold'; font-size: 14px; }
        .ban-btn:hover { background: rgba(255,255,255,0.2); transform: translateY(-2px); }
        @keyframes pulseBan { 0% { transform: scale(1); } 50% { transform: scale(1.05); filter: drop-shadow(0 0 35px rgba(239, 68, 68, 0.7)); } 100% { transform: scale(1); } }
        
        @keyframes spin { 100% { transform: rotate(360deg); } }
        @keyframes spin-icon { 100% { transform: rotate(360deg); } }

        .intro-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 3000; display: flex; justify-content: center; align-items: center; backdrop-filter: blur(8px); opacity: 0; visibility: hidden; transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        .intro-overlay.active { opacity: 1; visibility: visible; }
        .intro-overlay { padding: 16px; overflow-y: auto; }
        .intro-card { background: var(--v-surface-2, #1e293b); width: 90%; max-width: 420px; padding: 35px 25px; border-radius: 28px; border: 1px solid var(--v-border-strong, #334155); text-align: center; position: relative; box-shadow: 0 25px 50px rgba(0,0,0,0.8); transform: scale(0.9) translateY(20px); transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); overflow-y: auto; max-height: calc(100dvh - 32px); margin: auto; }
        @media (max-width: 400px) { .intro-card { padding: 24px 18px; border-radius: 22px; } }
        .intro-overlay.active .intro-card { transform: scale(1) translateY(0); }
        .intro-close { position: absolute; top: 15px; left: 15px; width: 32px; height: 32px; background: rgba(255,255,255,0.1); border-radius: 50%; display: flex; justify-content: center; align-items: center; cursor: pointer; color: #94a3b8; transition: 0.2s; font-size: 14px; }
        .intro-close:hover { background: #ef4444; color: #fff; transform: rotate(90deg); }
        .intro-icon { font-size: 55px; margin-bottom: 20px; animation: bounce 2s infinite; }
        /* Refreshed intro: aurora glow + 3D badge + feature chips */
        .intro-card { background: radial-gradient(130% 60% at 50% -12%, var(--v-primary-soft, rgba(99,102,241,0.20)), transparent 60%), var(--v-surface-2, #1e293b); }
        .intro-glow { position: absolute; top: -46px; left: 50%; transform: translateX(-50%); width: 240px; height: 110px; border-radius: 50%; background: radial-gradient(circle, var(--v-primary-glow, rgba(99,102,241,0.5)), transparent 68%); filter: blur(18px); opacity: .55; pointer-events: none; }
        .intro-hero { display: flex; justify-content: center; margin-bottom: 14px; }
        .intro-badge { width: 72px; height: 72px; border-radius: 24px; display: flex; align-items: center; justify-content: center; background: radial-gradient(120% 120% at 30% 15%, rgba(255,255,255,0.16), transparent 60%), var(--v-primary-soft, rgba(99,102,241,0.18)); border: 1px solid var(--v-primary-border, rgba(99,102,241,0.45)); box-shadow: 0 18px 36px -18px var(--v-primary-glow, rgba(99,102,241,0.95)), inset 0 1px 0 rgba(255,255,255,0.14); animation: introBadgeIn .5s cubic-bezier(.2,.9,.3,1.35) backwards, bounce 3.2s 1s infinite; }
        @keyframes introBadgeIn { from { transform: scale(.4) rotate(-8deg); opacity: 0; } to { transform: none; opacity: 1; } }
        .intro-badge img { width: 38px; height: 38px; object-fit: contain; filter: drop-shadow(0 6px 10px rgba(0,0,0,0.45)); }
        .intro-chips { display: flex; justify-content: center; flex-wrap: wrap; gap: 7px; margin: 0 0 16px; }
        .intro-chip { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 999px; background: rgba(148,163,184,0.08); border: 1px solid rgba(148,163,184,0.16); color: #cbd5e1; font-size: 11.5px; }
        .intro-chip img { width: 14px; height: 14px; object-fit: contain; }
        .intro-chip:nth-child(1) { animation: mTitleUp .4s .08s cubic-bezier(.2,.8,.2,1) backwards; }
        .intro-chip:nth-child(2) { animation: mTitleUp .4s .16s cubic-bezier(.2,.8,.2,1) backwards; }
        .intro-chip:nth-child(3) { animation: mTitleUp .4s .24s cubic-bezier(.2,.8,.2,1) backwards; }
        .intro-title { font-family: 'AppBold'; font-size: 22px; color: #fff; margin-bottom: 15px; background: linear-gradient(135deg, #fff, #94a3b8); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .intro-text { color: #cbd5e1; font-size: 13.5px; line-height: 1.9; margin-bottom: 22px; text-align: center; padding: 0 6px; }
        .intro-btn { width: 100%; padding: 14px; border-radius: 14px; background: linear-gradient(135deg, var(--v-primary, #6366f1), var(--v-primary-active, #4f46e5)); color: #fff; border: none; font-family: 'AppBold'; font-size: 15px; cursor: pointer; transition: 0.3s; box-shadow: 0 10px 25px -5px var(--v-primary-border, rgba(99,102,241,0.4)); }
        .intro-btn:hover { transform: translateY(-3px); box-shadow: 0 15px 35px -5px rgba(99, 102, 241, 0.5); }
        @keyframes bounce { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }

        .guide-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.88); z-index: 4000; display: flex; justify-content: center; align-items: center; padding: 16px; box-sizing: border-box; backdrop-filter: blur(10px); opacity: 0; visibility: hidden; transition: all 0.35s cubic-bezier(0.4,0,0.2,1); }
        .guide-overlay.active { opacity: 1; visibility: visible; }
        .guide-card { background: linear-gradient(145deg, #1e293b, #162032); width: 100%; max-width: 420px; max-height: 85vh; border-radius: 28px; border: 1px solid var(--v-primary-border, rgba(99,102,241,0.25)); display: flex; flex-direction: column; position: relative; box-shadow: 0 30px 70px rgba(0,0,0,0.8), 0 0 0 1px rgba(255,255,255,0.04); transform: translateY(30px) scale(0.96); transition: all 0.35s cubic-bezier(0.4,0,0.2,1); overflow: hidden; }
        .guide-overlay.active .guide-card { transform: translateY(0) scale(1); }
        .guide-header { padding: 22px 22px 16px; border-bottom: 1px solid rgba(255,255,255,0.07); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .guide-header-icon { width: 44px; height: 44px; background: linear-gradient(135deg, var(--v-primary-border, rgba(99,102,241,0.3)), var(--v-primary-strong, rgba(79,70,229,0.2))); border: 1px solid var(--v-active-soft, rgba(99,102,241,0.35)); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
        .guide-header-text { flex: 1; min-width: 0; }
        .guide-title { font-family: 'AppBold'; font-size: 17px; color: #fff; margin: 0 0 3px; }
        .guide-subtitle { font-size: 12px; color: #64748b; margin: 0; }
        .guide-close { width: 32px; height: 32px; border-radius: 50%; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); color: #94a3b8; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 15px; transition: 0.2s; flex-shrink: 0; }
        .guide-close:hover { background: rgba(239,68,68,0.2); border-color: rgba(239,68,68,0.4); color: #ef4444; transform: rotate(90deg); }
        .guide-body { padding: 20px 22px; overflow-y: auto; -webkit-overflow-scrolling: touch; flex: 1; scrollbar-width: none; -ms-overflow-style: none; }
        .guide-body::-webkit-scrollbar { display: none; }
        .guide-section { margin-bottom: 18px; background: rgba(255,255,255,0.025); border: 1px solid rgba(255,255,255,0.06); border-radius: 16px; padding: 16px; }
        .guide-section:last-child { margin-bottom: 0; }
        .guide-section-title { font-family: 'AppBold'; font-size: 13px; color: var(--v-accent, #a5b4fc); margin-bottom: 10px; display: flex; align-items: center; gap: 7px; }
        .guide-section-title::before { content: ''; width: 3px; height: 14px; background:linear-gradient(to bottom,var(--v-primary, #6366f1),var(--v-primary-active, #4f46e5)); border-radius: 2px; display: inline-block; }
        .guide-section-text { font-size: 13px; color: #cbd5e1; line-height: 1.9; }
        .guide-section-text b { color: #e2e8f0; }
        .guide-highlight { background: var(--v-primary-soft, rgba(99,102,241,0.12)); border: 1px solid var(--v-soft, rgba(99,102,241,0.2)); border-radius: 10px; padding: 10px 14px; margin-top: 10px; font-size: 12px; color: #94a3b8; line-height: 1.7; display: flex; gap: 8px; align-items: flex-start; }
        .guide-highlight-icon { font-size: 16px; flex-shrink: 0; margin-top: 1px; }
        .guide-footer { padding: 16px 22px 20px; border-top: 1px solid rgba(255,255,255,0.07); flex-shrink: 0; }
        .guide-btn { width: 100%; padding: 14px; border-radius: 14px; background: linear-gradient(135deg, var(--v-primary, #6366f1), var(--v-primary-active, #4f46e5)); color: #fff; border: none; font-family: 'AppBold'; font-size: 15px; cursor: pointer; transition: 0.3s; box-shadow: 0 8px 25px -5px rgba(99,102,241,0.4); }
        .guide-btn:hover { transform: translateY(-2px); box-shadow: 0 12px 30px -5px rgba(99,102,241,0.5); }
        .guide-badge { display: inline-flex; align-items: center; gap: 5px; background: rgba(16,185,129,0.12); border: 1px solid rgba(16,185,129,0.25); color: #34d399; border-radius: 8px; padding: 3px 10px; font-size: 11px; margin-top: 8px; }
    </style>
<style id="vFontFaces"><?php echo veyrax_font_css($pdo); ?></style>
</head>
<body>
<?php veyrax_eye_sprite(); ?>
    <div id="toast"></div>
    <div id="loadingOverlay"><div class="spinner"></div><div style="font-size:18px">لطفاً صبر کنید...</div></div>

<div id="introPopup" class="intro-overlay" onclick="closeIntroPopup()">
    <div class="intro-card" onclick="event.stopPropagation()">
        <span class="intro-close" onclick="closeIntroPopup()">✕</span>
        <span class="intro-glow" aria-hidden="true"></span>
        <div class="intro-hero">
            <span class="intro-badge"><img src="assets/icons3d/sparkles.png" alt=""></span>
        </div>
        <div class="intro-title">خوش اومدی به <?php echo $site_brand_fa; ?></div>
        <div class="intro-chips">
            <span class="intro-chip"><img src="assets/icons3d/mic.png" alt="">پیام صوتی</span>
            <span class="intro-chip"><img src="assets/icons3d/chat.png" alt="">اتاق خصوصی</span>
            <span class="intro-chip"><img src="assets/icons3d/megaphone.png" alt="">سیستم دعوت</span>
        </div>
        <div class="intro-text">
            خوش اومدی به چت‌روم <?php echo $site_brand_fa; ?> — جای امن و راحتی برای گفتگو با دوستات.
            <br>
            دستیار هوشمند هم در خدمته؛ کافیه تو پیامت از کلمه‌ی «<?php $vxKwI = array_values(array_filter(array_map('trim', preg_split('/[,\n]/u', trim(veyrax_setting($pdo, 'ai_keyword', '')))))); echo htmlspecialchars($vxKwI[0] ?? 'ویراکس', ENT_QUOTES, 'UTF-8'); ?>» استفاده کنی یا روی پیامش ریپلای بزنی. اگه رفتار نامناسبی دیدی، با گزارش تخلف سریع به پشتیبانی اطلاع بده.
        </div>
        <button class="intro-btn" onclick="closeIntroPopup()">شروع کنیم</button>
    </div>
</div>

<div id="guideOverlay" class="guide-overlay" onclick="closeGuide()">
    <div class="guide-card" onclick="event.stopPropagation()">
        <div class="guide-header">
            <div class="guide-header-icon">🔑</div>
            <div class="guide-header-text">
                <p class="guide-title">راهنمای ورود به سیستم</p>
                <p class="guide-subtitle">لطفاً قبل از ورود بخوانید</p>
            </div>
            <div class="guide-close" onclick="closeGuide()">✕</div>
        </div>
        <div class="guide-body">
            <div class="guide-section">
                <div class="guide-section-title">ورود بدون کد امنیتی</div>
                <div class="guide-section-text">
                    اگر قبلاً با همین دستگاه وارد شده‌اید و <b>نام کاربری</b> خود را انتخاب کرده‌اید، دیگر نیازی به کد امنیتی ندارید. کافی است همان نام کاربری را وارد کنید و روی <b>«ورود»</b> بزنید.
                    <div class="guide-highlight">
                        <span class="guide-highlight-icon">⚠️</span>
                        <span>این قابلیت فقط زمانی کار می‌کند که از <b>همان دستگاه</b>، <b>همان IP</b> و <b>همان مرورگر</b> وارد شوید. اگر دستگاه یا شبکه عوض شده باشد، باید از کد امنیتی استفاده کنید.</span>
                    </div>
                </div>
            </div>
            <div class="guide-section">
                <div class="guide-section-title">ورود با کد امنیتی</div>
                <div class="guide-section-text">
                    اگر دستگاه یا IP شما تغییر کرده، یا اولین بار وارد می‌شوید، باید از گزینه <b>«کد امنیتی دارم»</b> استفاده کنید. کد امنیتی ۸ رقمی خود را وارد کنید تا از هر جایی بتوانید وارد حساب کاربری‌تان شوید.
                    <div class="guide-highlight">
                        <span class="guide-highlight-icon">💡</span>
                        <span>کد امنیتی را در جای امنی نگه دارید. بدون این کد از دستگاه‌های دیگر امکان ورود وجود ندارد.</span>
                    </div>
                </div>
            </div>
            <div class="guide-section">
                <div class="guide-section-title">انتخاب نام کاربری</div>
                <div class="guide-section-text">
                    اگر اولین بار است وارد می‌شوید، نام کاربری دلخواه خود را وارد کنید. اگر این نام قبلاً ثبت نشده باشد، حساب جدیدی برایتان ساخته می‌شود. اگر ثبت شده باشد، وارد همان حساب می‌شوید.
                    <div class="guide-badge">✓ ورود و ثبت‌نام در یک مرحله</div>
                </div>
            </div>
        </div>
        <div class="guide-footer">
            <button class="guide-btn" onclick="closeGuide()">متوجه شدم، بریم ورود کنیم ✓</button>
        </div>
    </div>
</div>

    <?php if (!empty($previousUsers)): ?>
    <script>
    window._prevAccounts = <?php echo json_encode(array_values($previousUsers)); ?>;
    </script>
    <?php else: ?>
    <script>window._prevAccounts = [];</script>
    <?php endif; ?>

    <style>
        .quick-user-row:hover {
            background: var(--v-soft, rgba(99,102,241,0.15)) !important;
            border-color: var(--v-active-soft, rgba(99,102,241,0.35)) !important;
            transform: translateX(-3px);
        }
        .quick-user-row:active { transform: scale(0.98) !important; }

        /* Previous Accounts Popup - Centered Modal */
        #prevAccountsModal {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.78);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            z-index: 4500;
            display: flex; justify-content: center; align-items: center;
            padding: 20px;
            opacity: 0; visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }
        #prevAccountsModal.open {
            opacity: 1; visibility: visible;
        }
        #prevAccountsSheet {
            background: linear-gradient(160deg, var(--v-surface-2, #1a2540) 0%, var(--v-bg-0, #0f172a) 100%);
            border-radius: 28px;
            border: 1px solid var(--v-border-strong, rgba(255,255,255,0.12));
            width: 100%; max-width: 420px;
            padding-bottom: 0;
            box-shadow: 0 30px 80px rgba(0,0,0,0.7), 0 0 0 1px rgba(255,255,255,0.04);
            transform: scale(0.92) translateY(16px);
            transition: transform 0.35s cubic-bezier(0.2, 0.8, 0.2, 1);
            max-height: 85vh;
            overflow-y: auto;
        }
        #prevAccountsModal.open #prevAccountsSheet {
            transform: scale(1) translateY(0);
        }
        .sheet-handle { display: none; }
        .sheet-header {
            padding: 22px 22px 16px;
            border-bottom: 1px solid rgba(255,255,255,0.07);
            display: flex; align-items: center; justify-content: space-between;
        }
        .sheet-title {
            font-family: 'AppBold'; font-size: 17px; color: #fff;
            display: flex; align-items: center; gap: 10px;
        }
        .sheet-title-icon {
            width: 40px; height: 40px;
            background: linear-gradient(135deg, var(--v-primary-border, rgba(99,102,241,0.3)), var(--v-primary-strong, rgba(79,70,229,0.2)));
            border: 1px solid rgba(99,102,241,0.4);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px;
        }
        .sheet-close-btn {
            width: 32px; height: 32px; border-radius: 50%;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.1);
            color: #94a3b8; font-size: 15px; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: 0.2s; flex-shrink: 0;
        }
        .sheet-close-btn:hover { background: rgba(239,68,68,0.2); border-color: rgba(239,68,68,0.4); color: #ef4444; }
        .sheet-body { padding: 18px 18px 10px; }
        .sheet-subtitle {
            font-size: 12px; color: #64748b;
            margin-bottom: 14px;
            padding: 9px 13px;
            background: var(--v-glow-soft, rgba(99,102,241,0.07));
            border-radius: 10px;
            border: 1px solid var(--v-primary-soft, rgba(99,102,241,0.12));
            line-height: 1.6;
        }
        .sheet-user-row {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 16px; padding: 13px 15px;
            cursor: pointer; transition: all 0.25s;
            display: flex; align-items: center; gap: 13px;
            margin-bottom: 8px;
        }
        .sheet-user-row:hover {
            background: var(--v-primary-soft, rgba(99,102,241,0.14)) !important;
            border-color: var(--v-active-soft, rgba(99,102,241,0.35)) !important;
            transform: translateX(-2px);
        }
        .sheet-user-row:active { transform: scale(0.98); }
        .sheet-footer { padding: 6px 18px 20px; }
        .sheet-sec-btn {
            width: 100%; height: 46px;
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.1);
            color: rgba(255,255,255,0.55); font-size: 13px;
            cursor: pointer; border-radius: 14px;
            font-family: 'AppFont'; transition: all 0.25s;
            display: flex; align-items: center; justify-content: center; gap: 7px;
        }
        .sheet-sec-btn:hover { background: rgba(255,255,255,0.09); border-color: rgba(255,255,255,0.22); color: rgba(255,255,255,0.85); }

        /* ── Demo gate ─────────────────────────────────────────────────────── */
        #demoGate {
            position: fixed; inset: 0; z-index: 4000;
            display: none; align-items: center; justify-content: center;
            padding: 18px; background: rgba(4,7,18,0.78);
            -webkit-backdrop-filter: blur(9px); backdrop-filter: blur(9px);
        }
        #demoGate.open { display: flex; animation: demoGateFade .22s ease both; }
        @keyframes demoGateFade { from { opacity: 0; } to { opacity: 1; } }
        .demo-gate-card {
            position: relative; width: 100%; max-width: 430px;
            padding: 30px 26px 24px; border-radius: 26px; overflow: hidden;
            background:
                radial-gradient(120% 90% at 50% -20%, rgba(129,140,248,0.28), transparent 62%),
                linear-gradient(165deg, rgba(30,41,59,0.98), rgba(15,23,42,0.99));
            border: 1px solid rgba(129,140,248,0.34);
            box-shadow: 0 30px 70px -22px rgba(0,0,0,0.9), inset 0 1px 0 rgba(255,255,255,0.10);
            animation: demoGateRise .34s cubic-bezier(.2,.8,.2,1) both;
            text-align: center;
        }
        @keyframes demoGateRise { from { opacity: 0; transform: translateY(18px) scale(.97); } to { opacity: 1; transform: none; } }
        .demo-gate-card::before {
            content: ''; position: absolute; inset-inline: 0; top: 0; height: 3px;
            background: linear-gradient(90deg, transparent, var(--v-primary, #6366f1), #a78bfa, var(--v-primary, #6366f1), transparent);
            background-size: 200% 100%; animation: demoGateSheen 3.4s linear infinite;
        }
        @keyframes demoGateSheen { from { background-position: 0% 0; } to { background-position: 200% 0; } }
        .demo-gate-close {
            position: absolute; top: 12px; left: 12px; width: 32px; height: 32px;
            display: grid; place-items: center; border-radius: 10px; cursor: pointer;
            border: 1px solid rgba(255,255,255,0.10); background: rgba(255,255,255,0.05);
            color: #94a3b8; font-size: 15px; line-height: 1; transition: .18s;
        }
        .demo-gate-close:hover { background: rgba(239,68,68,0.16); border-color: rgba(239,68,68,0.45); color: #fca5a5; }
        .demo-gate-icon {
            width: 66px; height: 66px; margin: 2px auto 14px; border-radius: 20px;
            display: grid; place-items: center;
            background: linear-gradient(150deg, rgba(129,140,248,0.22), rgba(129,140,248,0.04));
            border: 1px solid rgba(129,140,248,0.30);
            box-shadow: 0 14px 30px -16px rgba(99,102,241,0.9), inset 0 1px 0 rgba(255,255,255,0.16);
        }
        .demo-gate-icon img { width: 38px; height: 38px; object-fit: contain; filter: drop-shadow(0 5px 8px rgba(0,0,0,0.45)); }
        #demoGateTitle { margin: 0 0 8px; font-family: 'AppBold', 'AppFont', sans-serif; font-size: 20px; color: #fff; letter-spacing: -0.2px; }
        .demo-gate-sub { margin: 0 0 18px; font-size: 13px; line-height: 1.9; color: #9fb0c8; }
        .demo-creds { display: grid; gap: 9px; margin-bottom: 18px; }
        .demo-cred {
            display: flex; align-items: center; gap: 10px;
            padding: 11px 13px; border-radius: 14px; text-align: right;
            background: rgba(2,6,23,0.55); border: 1px dashed rgba(129,140,248,0.34);
        }
        .demo-cred-label { font-size: 11px; color: #7d8ba3; flex: 0 0 auto; }
        .demo-cred code {
            flex: 1 1 auto; min-width: 0; direction: ltr; text-align: left;
            font-family: 'SFMono-Regular', Consolas, monospace; font-size: 16px;
            font-weight: 700; letter-spacing: 2px; color: #c7d2fe;
        }
        .demo-cred-copy {
            flex: 0 0 auto; width: 30px; height: 30px; display: grid; place-items: center;
            border-radius: 9px; cursor: pointer; border: 1px solid rgba(129,140,248,0.28);
            background: rgba(129,140,248,0.12); color: #c7d2fe; transition: .18s;
        }
        .demo-cred-copy:hover { background: rgba(129,140,248,0.26); color: #fff; }
        .demo-cred-copy svg { width: 15px; height: 15px; }
        .demo-gate-cta {
            width: 100%; height: 50px; border: 0; border-radius: 15px; cursor: pointer;
            font-family: 'AppBold', 'AppFont', sans-serif; font-size: 15px; color: #fff;
            background: linear-gradient(135deg, var(--v-primary, #6366f1), var(--v-primary-active, #4f46e5));
            box-shadow: 0 18px 34px -16px var(--v-primary-glow, rgba(99,102,241,0.95)), inset 0 1px 0 rgba(255,255,255,0.28);
            transition: transform .15s, box-shadow .2s, filter .2s;
        }
        .demo-gate-cta:hover { transform: translateY(-1px); filter: brightness(1.06); }
        .demo-gate-cta:active { transform: translateY(0) scale(.995); }
        .demo-gate-cta:disabled { filter: saturate(.6) brightness(.85); cursor: not-allowed; transform: none; }
        .demo-gate-note { margin-top: 12px; font-size: 11px; line-height: 1.8; color: #64748b; }
        .demo-gate-note b { color: #94a3b8; font-weight: 700; }

        /* Locked login card */
        #mainLoginCard.is-closed #loginForm,
        #mainLoginCard.is-closed #quickLoginRow,
        #mainLoginCard.is-closed .login-aux { display: none !important; }
        #mainLoginCard.is-demo #quickLoginRow,
        #mainLoginCard.is-demo .login-aux { display: none !important; }
        .vx-lock-note {
            display: none; margin: 4px 0 18px; padding: 14px 15px; border-radius: 15px;
            text-align: right; line-height: 1.9; font-size: 12.5px;
            background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.28); color: #fca5a5;
        }
        .vx-lock-note.show { display: block; }
        .vx-lock-note b { color: #fecaca; }
        /* Registration lock is a warning, not a failure: existing users still
           get in, so it reads amber instead of red. */
        .vx-lock-note.is-warn {
            background: rgba(245,158,11,0.08);
            border-color: rgba(245,158,11,0.30);
            color: #fcd34d;
        }
        .vx-lock-note.is-warn b { color: #fde68a; }
        .vx-lock-note .vx-lock-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 11px; }
        .vx-lock-btn {
            flex: 1 1 auto; min-width: 120px; height: 42px; border-radius: 12px; cursor: pointer;
            font-family: 'AppBold', 'AppFont', sans-serif; font-size: 13px; color: #fff;
            border: 1px solid rgba(129,140,248,0.4);
            background: linear-gradient(135deg, rgba(99,102,241,0.9), rgba(79,70,229,0.9));
            box-shadow: 0 12px 26px -16px rgba(99,102,241,0.95), inset 0 1px 0 rgba(255,255,255,0.22);
        }
        .vx-lock-btn.ghost { background: rgba(255,255,255,0.05); border-color: rgba(255,255,255,0.14); color: #cbd5e1; box-shadow: none; }
        .vx-lock-btn:hover { filter: brightness(1.07); }
        @media (max-width: 420px) {
            .demo-gate-card { padding: 26px 18px 20px; border-radius: 22px; }
            #demoGateTitle { font-size: 18px; }
        }
    </style>

    <!-- Previous Accounts Bottom Sheet Popup -->
    <div id="prevAccountsModal" onclick="closePrevAccountsModal()">
        <div id="prevAccountsSheet" onclick="event.stopPropagation()">
            <div class="sheet-handle"></div>
            <div class="sheet-header">
                <div class="sheet-title">
                    <div class="sheet-title-icon">⚡</div>
                    <div>
                        <div style="font-size:16px;">ورود سریع</div>
                        <div style="font-size:11px;color:#64748b;font-family:'AppFont';font-weight:normal;margin-top:1px;">بدون کپچا</div>
                    </div>
                </div>
                <button class="sheet-close-btn" onclick="closePrevAccountsModal()">✕</button>
            </div>
            <div class="sheet-body">
                <div class="sheet-subtitle" id="prevAccountsSubtitle">حساب‌های شناسایی‌شده از این دستگاه را انتخاب کنید</div>
                <div id="prevAccountsList"></div>
            </div>
            <div class="sheet-footer">
                <button class="sheet-sec-btn" onclick="closePrevAccountsModal(); showLoginWithCode();">
                    🔒 ورود با کد امنیتی از دستگاه دیگر
                </button>
            </div>
        </div>
    </div>

    <div id="demoGate" role="dialog" aria-modal="true" aria-labelledby="demoGateTitle">
        <div class="demo-gate-card">
            <button type="button" class="demo-gate-close" onclick="hideDemoGate()" aria-label="بستن">✕</button>
            <div class="demo-gate-icon"><img src="assets/icons3d/sparkles.png" alt=""></div>
            <h2 id="demoGateTitle">حالت نمایشی فعال است</h2>
            <p class="demo-gate-sub">این سایت در حالت آزمایشی است و ورود عادی بسته شده. برای دیدن محیط، با حساب نمایشی زیر وارد شوید.</p>
            <div class="demo-creds">
                <div class="demo-cred">
                    <span class="demo-cred-label">نام کاربری</span>
                    <code>demo</code>
                    <button type="button" class="demo-cred-copy" onclick="copyDemoCred('demo', this)" aria-label="کپی نام کاربری" title="کپی">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2.5"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                    </button>
                </div>
                <div class="demo-cred">
                    <span class="demo-cred-label">رمز عبور</span>
                    <code>demo</code>
                    <button type="button" class="demo-cred-copy" onclick="copyDemoCred('demo', this)" aria-label="کپی رمز عبور" title="کپی">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2.5"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                    </button>
                </div>
            </div>
            <button type="button" class="demo-gate-cta" id="demoGateCta" onclick="startDemoLogin()">ورود به محیط نمایشی</button>
            <div class="demo-gate-note">امکانات محدود · بدون آپلود و پیام خصوصی · <b>داده‌ها خودکار پاک می‌شوند</b></div>
        </div>
    </div>

    <div class="login-card" id="mainLoginCard">
        <div class="brand-head">
            <span class="brand-mark"><?php if ($brand_logo): ?><img src="<?php echo htmlspecialchars($brand_logo, ENT_QUOTES, 'UTF-8'); ?>" alt=""><?php else: ?><?php echo htmlspecialchars(mb_substr($site_brand_fa, 0, 1, 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></span>
            <span class="brand-lockup">
                <span class="brand-wordmark">ورود و عضویت</span>
                <span class="brand-divider" aria-hidden="true"></span>
                <span class="brand-room" title="<?php echo htmlspecialchars($site_brand_fa, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($site_brand_fa, ENT_QUOTES, 'UTF-8'); ?></span>
            </span>
        </div>
        <h1 class="brand-title">ورود و عضویت</h1>
        <div class="brand-subtitle">به <b><?php echo htmlspecialchars($site_brand_fa, ENT_QUOTES, 'UTF-8'); ?></b> خوش آمدید — <?php echo htmlspecialchars($brand_tagline, ENT_QUOTES, 'UTF-8'); ?></div>

        <div id="serverStatusBar" data-state="checking" onclick="refreshServerStatus()" title="برای بررسی مجدد کلیک کنید" role="button" tabindex="0" aria-label="وضعیت سرور و تعداد کاربران آنلاین">
            <div class="ssb-left">
                <span class="ssb-dot" id="serverPingDot"></span>
                <span class="ssb-status" id="serverPingLabel">در حال بررسی…</span>
                <span class="ssb-ms" id="serverPingMs"></span>
            </div>
            <span class="ssb-sep"></span>
            <div class="ssb-right">
                <span class="ssb-users" aria-hidden="true"><svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor"><circle cx="12" cy="8.2" r="4"/><path d="M4.6 20.4a7.4 7.4 0 0 1 14.8 0 .6.6 0 0 1-.6.6H5.2a.6.6 0 0 1-.6-.6z"/></svg></span>
                <span class="ssb-count" id="onlineCountLabel">—</span>
                <span class="ssb-count-label">آنلاین</span>
            </div>
        </div>
        <div class="vx-lock-note" id="vxLockNote"></div>
        <form id="loginForm">
            <div id="step1">
                <input type="text" id="username" name="username" placeholder="نام کاربری (حتی فاصله‌دار)" required autocomplete="off" maxlength="16">
                <div id="usernameError" class="error-msg-box"></div>
                <div class="captcha-row">
                    <div class="captcha-text" id="captchaBox"><?php echo $n1; ?> + <?php echo $n2; ?></div>
                    <button type="button" class="refresh-btn" onclick="refreshCaptcha(this)" title="کد جدید" aria-label="تازه‌سازی کد امنیتی"><svg class="rf-ico" viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 12a8.5 8.5 0 1 1-2.9-6.4"/><polyline points="20.5 3.5 20.5 9 15 9"/></svg></button>
                </div>
                <input type="number" name="captcha" id="captchaInput" placeholder="پاسخ امنیتی" required>
            </div>
            <div id="step2" class="hidden">
                <div class="v-pass-row">
                    <input type="password" id="password" name="password" placeholder="رمز عبور مدیریت" autocomplete="new-password">
                    <button type="button" class="v-eye-btn" onclick="togglePassVisibility('password',this)" aria-label="نمایش رمز"><span class="v-eye-ico"><svg class="i3d" viewBox="0 0 32 32" aria-hidden="true"><use href="#v3i-eye"/></svg></span></button>
                </div>
            </div>
            <button type="submit" id="submitBtn">ورود</button>
        </form>
        <div class="ql-row" id="quickLoginRow" style="display:none">
            <label class="ql-box" for="quickLoginToggle">
                <input type="checkbox" id="quickLoginToggle" onchange="setQuickLogin(this.checked)">
                <span class="ql-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></span>
                <span class="ql-text">
                    <span class="ql-title">نمایش منوی ورود سریع</span>
                    <span class="ql-sub"><span id="qlCount">0</span> حساب شناسایی‌شده روی این دستگاه</span>
                </span>
            </label>
            <button type="button" class="ql-open" onclick="openPrevAccountsModal(window._prevAccounts || [], false)">باز کردن</button>
        </div>
        <div class="login-aux">
            <button type="button" class="aux-btn" onclick="showSecRecover()" title="بازیابی با کد امنیتی" aria-label="بازیابی با کد امنیتی">
                <span class="aux-ico"><img class="i3d" src="assets/icons3d/key.png" alt=""></span><span class="aux-label">بازیابی</span>
            </button>
            <?php if (veyrax_sms_ready($pdo) || veyrax_sms_dev_mode()): ?>
            <button type="button" class="aux-btn" onclick="openForgotPhone()" title="بازنشانی رمز با شماره موبایل" aria-label="بازنشانی رمز با شماره موبایل">
                <span class="aux-ico"><img class="i3d" src="assets/icons3d/phone.png" alt=""></span><span class="aux-label">بازنشانی موبایل</span>
            </button>
            <?php endif; ?>
            <button type="button" class="aux-btn" onclick="showLoginWithCode()" title="ورود با کد امنیتی" aria-label="ورود با کد امنیتی">
                <span class="aux-ico"><img class="i3d" src="assets/icons3d/lock.png" alt=""></span><span class="aux-label">ورود با کد</span>
            </button>
            <button type="button" class="aux-btn" onclick="openGuide()" title="راهنمای ورود" aria-label="راهنمای ورود">
                <span class="aux-ico"><img class="i3d" src="assets/icons3d/question.png" alt=""></span><span class="aux-label">راهنما</span>
            </button>
        </div>
    </div>

    <div id="loginWithCodeModal" class="code-modal-overlay" onclick="hideLoginWithCode()">
        <div class="code-modal-card" onclick="event.stopPropagation()">
            <button class="code-modal-close" onclick="hideLoginWithCode()">✕</button>
            <div class="code-modal-title">ورود با کد امنیتی</div>
            <div class="code-modal-sub">کد ۸ رقمی خود را وارد کنید</div>
            <form id="loginCodeForm">
                <input type="text" id="codeUsername" class="code-modal-input" placeholder="نام کاربری" required autocomplete="off" inputmode="text" oninput="this.value=this.value.replace(/[^\u0021-\u007E\u0020]/g,'').replace(/[^a-zA-Z0-9_.@\- ]/g,'')">
                <input type="text" id="securityCodeInput" class="code-modal-input" placeholder="کد امنیتی" style="letter-spacing:3px;font-weight:bold;font-family:monospace;" required autocomplete="off" inputmode="text" maxlength="8" oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/g,'').substr(0,8)">
                <div class="captcha-row">
                    <div class="captcha-text" id="captchaBoxCode">...</div>
                    <button type="button" class="refresh-btn" onclick="refreshCaptchaCode(this)" title="کد جدید" aria-label="تازه‌سازی کد امنیتی"><svg class="rf-ico" viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 12a8.5 8.5 0 1 1-2.9-6.4"/><polyline points="20.5 3.5 20.5 9 15 9"/></svg></button>
                </div>
                <input type="number" id="captchaInputCode" class="code-modal-input" placeholder="پاسخ امنیتی" required inputmode="numeric">
                <button type="submit" class="code-modal-btn">تایید و ورود</button>
                <button type="button" class="code-modal-back" onclick="hideLoginWithCode()">← بازگشت</button>
            </form>
        </div>
    </div>

    <div id="forgotPhoneModal" class="code-modal-overlay" onclick="hideForgotPhone()">
        <div class="code-modal-card" onclick="event.stopPropagation()">
            <button class="code-modal-close" onclick="hideForgotPhone()">✕</button>
            <span class="fp-mark"><img src="assets/icons3d/phone.png" alt=""></span>
            <div class="code-modal-title">بازنشانی رمز با موبایل</div>

            <div id="fpStep1">
                <div class="code-modal-sub">شماره‌ای که در حساب خود ثبت کرده‌اید را وارد کنید</div>
                <input type="tel" id="fpPhone" class="code-modal-input" placeholder="09xxxxxxxxx" style="letter-spacing:1px;direction:ltr;font-family:'AppFont',sans-serif;" inputmode="numeric" maxlength="11" autocomplete="tel" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                <button type="button" id="fpStartBtn" class="code-modal-btn" onclick="forgotStart()">ارسال کد تأیید</button>
                <button type="button" class="code-modal-back" onclick="hideForgotPhone()">← بازگشت</button>
            </div>

            <div id="fpStep2" style="display:none;">
                <div class="code-modal-sub">کد ۶ رقمی پیامک‌شده به <b dir="ltr" id="fpPhoneMasked"></b> را وارد کنید <span id="fpDevCode" style="display:none;color:#fbbf24;"></span></div>
                <input type="text" id="fpCode" class="code-modal-input" placeholder="------" style="letter-spacing:8px;font-weight:bold;" inputmode="numeric" maxlength="6" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                <button type="button" id="fpVerifyBtn" class="code-modal-btn" onclick="forgotVerify()">تأیید کد</button>
                <button type="button" id="fpResendBtn" class="code-modal-back" onclick="forgotStart()" disabled>ارسال مجدد کد (۶۰ ثانیه)</button>
            </div>

            <div id="fpStep3" style="display:none;">
                <div class="code-modal-sub">رمز جدید خود را تعیین کنید (۸ تا ۲۰ کاراکتر، حروف انگلیسی و عدد)</div>
                <input type="password" id="fpPass1" class="code-modal-input" placeholder="رمز جدید" maxlength="20" autocomplete="new-password">
                <input type="password" id="fpPass2" class="code-modal-input" placeholder="تکرار رمز جدید" maxlength="20" autocomplete="new-password">
                <button type="button" id="fpResetBtn" class="code-modal-btn" onclick="forgotReset()">تغییر رمز عبور</button>
                <button type="button" class="code-modal-back" onclick="hideForgotPhone()">← بازگشت به ورود</button>
            </div>

            <div id="fpStepDone" style="display:none; text-align:center;">
                <div class="fp-done-ico">✓</div>
                <div class="code-modal-sub" style="margin-bottom:6px;">رمز عبور شما با موفقیت تغییر کرد.</div>
                <div class="code-modal-sub" style="margin-bottom:16px;">حالا با نام کاربری <b dir="ltr" id="fpDoneUser"></b> وارد شوید.</div>
                <button type="button" class="code-modal-btn" onclick="forgotDoneToLogin()">ورود به سیستم</button>
            </div>

            <div id="fpError" class="fp-error" style="display:none;"></div>
        </div>
    </div>

    <div id="secRecoverOverlay" class="code-modal-overlay" onclick="hideSecRecover()">
        <div class="code-modal-card" onclick="event.stopPropagation()">
            <button class="code-modal-close" onclick="hideSecRecover()">✕</button>
            <div class="code-modal-title">بازیابی با کد امنیتی</div>
            <div class="code-modal-sub">کد ۸ رقمی جای رمز عبور را می‌گیرد؛ بعد از ۵ تلاش ناموفق، ۱۵ دقیقه قفل می‌شود</div>
            <form id="secRecoverForm" autocomplete="off">
                <input type="text" id="srUsername" class="code-modal-input" placeholder="نام کاربری" required>
                <input type="text" id="srCode" class="code-modal-input sr-code" placeholder="کد امنیتی" maxlength="8" required inputmode="text"
                       oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/g,'').substr(0,8)">
                <div class="captcha-row">
                    <div class="captcha-text" id="captchaBoxRecover">…</div>
                    <button type="button" class="refresh-btn" onclick="refreshCaptchaRecover(this)" title="کد جدید" aria-label="تازه‌سازی کد امنیتی"><svg class="rf-ico" viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 12a8.5 8.5 0 1 1-2.9-6.4"/><polyline points="20.5 3.5 20.5 9 15 9"/></svg></button>
                </div>
                <input type="number" id="captchaInputRecover" class="code-modal-input" placeholder="پاسخ امنیتی" required inputmode="numeric">
                <div class="sr-optional-toggle" id="srOptToggle" onclick="toggleSrOptional()">
                    <span>می‌خواهم رمز یا نام کاربری را عوض کنم</span>
                    <span class="sr-chev" id="srChev">▾</span>
                </div>
                <div id="srOptional" style="display:none;">
                    <div class="v-pass-row">
                        <input type="password" id="srPass" class="code-modal-input" placeholder="رمز جدید" maxlength="20" autocomplete="new-password">
                        <button type="button" class="v-eye-btn" onclick="togglePassVisibility('srPass',this)" aria-label="نمایش رمز"><span class="v-eye-ico"><svg class="i3d" viewBox="0 0 32 32" aria-hidden="true"><use href="#v3i-eye"/></svg></span></button>
                    </div>
                    <div class="v-pass-row">
                        <input type="password" id="srPass2" class="code-modal-input" placeholder="تکرار رمز جدید" maxlength="20" autocomplete="new-password">
                        <button type="button" class="v-eye-btn" onclick="togglePassVisibility('srPass2',this)" aria-label="نمایش رمز"><span class="v-eye-ico"><svg class="i3d" viewBox="0 0 32 32" aria-hidden="true"><use href="#v3i-eye"/></svg></span></button>
                    </div>
                    <input type="text" id="srNewUser" class="code-modal-input" placeholder="نام کاربری جدید (اختیاری)" maxlength="16">
                </div>
                <button type="submit" class="code-modal-btn" id="srSubmitBtn">اعمال تغییرات</button>
                <div class="sr-msg" id="srMsg"></div>
                <button type="button" class="code-modal-back" onclick="hideSecRecover()">← بازگشت</button>
                
            </form>
        </div>
    </div>

    <div id="colorPickerOverlay" class="code-modal-overlay" onclick="closeColorPicker()">
        <div class="code-modal-card cp-card" onclick="event.stopPropagation()">
            <button class="code-modal-close" onclick="closeColorPicker()">✕</button>
            <div class="code-modal-title">رنگ دلخواه</div>
            <div class="code-modal-sub">یک رنگ انتخاب کن؛ پوسته فوراً همان رنگ می‌شود</div>
            <div class="cp-preview" id="cpPreview"></div>
            <div class="cp-grid" id="cpGrid"></div>
            <label class="cp-native" for="cpInput">
                <input type="color" id="cpInput" value="#6366f1" oninput="onCpInput(this.value)">
                <span>انتخاب دقیق رنگ</span>
            </label>
            <button type="button" class="code-modal-btn" onclick="applyCustomColor()">اعمال رنگ</button>
            <button type="button" class="code-modal-back" onclick="closeColorPicker()">← بازگشت</button>
        </div>
    </div>

    <div class="login-footer">
        <div class="v-theme-row" aria-label="انتخاب رنگ پوسته">
            <button type="button" class="v-swatch" data-color="#6366f1" style="background:#6366f1" onclick="veyraxApplyTheme('#6366f1')" aria-label="بنفش"></button>
            <button type="button" class="v-swatch" data-color="#3b82f6" style="background:#3b82f6" onclick="veyraxApplyTheme('#3b82f6')" aria-label="آبی"></button>
            <button type="button" class="v-swatch" data-color="#10b981" style="background:#10b981" onclick="veyraxApplyTheme('#10b981')" aria-label="سبز"></button>
            <button type="button" class="v-swatch" data-color="#ef4444" style="background:#ef4444" onclick="veyraxApplyTheme('#ef4444')" aria-label="قرمز"></button>
            <button type="button" class="v-swatch" data-color="#f59e0b" style="background:#f59e0b" onclick="veyraxApplyTheme('#f59e0b')" aria-label="نارنجی"></button>
            <button type="button" class="v-swatch v-swatch-custom" onclick="openColorPicker()" aria-label="رنگ دلخواه" title="رنگ دلخواه"></button>
        </div>
        <div class="login-footer-meta">
            <span class="terms-link" onclick="openTerms()">پذیرش قوانین</span>
            <span class="footer-dot" aria-hidden="true"></span>
            <a class="dev-credit" href="https://github.com/PouyaFakham/virax-chatroom" target="_blank" rel="noopener" title="سورس و طراحی VIRAX Chatroom"><?php echo htmlspecialchars($site_brand_fa, ENT_QUOTES, 'UTF-8'); ?></a>
            <span class="footer-dot" aria-hidden="true"></span>
            <span class="version-tag" dir="ltr">V <?php echo htmlspecialchars($site_version_label, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php if(!empty($donate_link)): ?>
            <span class="footer-dot" aria-hidden="true"></span>
            <a class="donate-link" href="<?php echo $donate_link; ?>" target="_blank" rel="noopener">&#x1F49B; حمایت از پروژه</a>
            <?php endif; ?>
        </div>
    </div>

    <div id="termsModal" class="terms-modal-overlay" onclick="closeTerms()">
        <div class="terms-modal" onclick="event.stopPropagation()">
            <div class="terms-header">
                <span class="terms-title">قوانین و مقررات</span>
                <span class="close-terms" onclick="closeTerms()">✕</span>
            </div>
            <div class="terms-body">
                <p>1. رعایت ادب و احترام متقابل در تمامی گفتگوها الزامی است.</p>
                <p>2. ارسال هرگونه محتوای غیراخلاقی، توهین‌آمیز یا خلاف قوانین کشور ممنوع می‌باشد.</p>
                <p>3. مسئولیت تمامی پیام‌های ارسال شده بر عهده کاربر می‌باشد.</p>
                <p>4. استفاده از نام‌های کاربری نامناسب منجر به مسدود شدن حساب کاربری خواهد شد.</p>
                <p>5. ارسال اسپم و پیام‌های رگباری ممنوع است.</p>
                <p>6. این پلتفرم صرفاً جهت ارتباط امن طراحی شده و هرگونه سوءاستفاده پیگرد قانونی دارد.</p>
                <br>
                <p style="text-align:center; color:var(--v-accent, #a5b4fc); font-weight:bold;">با ورود به چت، شما این قوانین را می‌پذیرید.</p>
            </div>
        </div>
    </div>
    
    <div id="banModal" class="terms-modal-overlay">
        <div class="ban-modal-content" onclick="event.stopPropagation()">
            <div class="ban-icon">🚫</div>
            <div class="ban-title">دسترسی مسدود شد</div>
            <div id="banMessageDetail" class="ban-message"></div>
            <button class="ban-btn" onclick="location.reload()">تلاش مجدد</button>
        </div>
    </div>

    <script>
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        let currentCaptchaResult = null;
        let isCodeMode = false;

        // ── Access state (mirrors the server-side gates) ────────────────────
        const VX_DEMO_MODE   = <?php echo $vxDemoOn ? 'true' : 'false'; ?>;
        const VX_REG_LOCKED  = <?php echo $vxRegLocked ? 'true' : 'false'; ?>;
        const VX_LOGIN_LOCKED = <?php echo $vxLoginLocked ? 'true' : 'false'; ?>;

        function showDemoGate() {
            const gate = document.getElementById('demoGate');
            if (!gate) return;
            gate.classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function hideDemoGate() {
            const gate = document.getElementById('demoGate');
            if (!gate) return;
            gate.classList.remove('open');
            document.body.style.overflow = '';
        }

        function copyDemoCred(text, btn) {
            const done = () => {
                if (btn) {
                    const old = btn.innerHTML;
                    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
                    setTimeout(() => { btn.innerHTML = old; }, 1200);
                }
                showToast('کپی شد');
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done).catch(() => {
                    const t = document.createElement('textarea');
                    t.value = text; t.style.cssText = 'position:fixed;opacity:0;left:-9999px';
                    document.body.appendChild(t); t.select();
                    try { document.execCommand('copy'); done(); } catch (e) {}
                    document.body.removeChild(t);
                });
            } else {
                const t = document.createElement('textarea');
                t.value = text; t.style.cssText = 'position:fixed;opacity:0;left:-9999px';
                document.body.appendChild(t); t.select();
                try { document.execCommand('copy'); done(); } catch (e) {}
                document.body.removeChild(t);
            }
        }

        function startDemoLogin() {
            const u = document.getElementById('username');
            const p = document.getElementById('password');
            const s1 = document.getElementById('step1');
            const s2 = document.getElementById('step2');
            const sb = document.getElementById('submitBtn');
            if (u) u.value = 'demo';
            if (p) p.value = 'demo';
            if (s1) s1.classList.remove('hidden');
            if (s2) s2.classList.add('hidden');
            if (p) p.required = false;
            if (sb) sb.innerText = 'ورود';
            hideDemoGate();
            const cap = document.getElementById('captchaInput');
            if (cap) {
                cap.value = '';
                cap.focus();
            }
            showToast('نام کاربری و رمز دمو پر شد — پاسخ امنیتی را وارد کنید');
        }

        function applyAccessUi() {
            const card = document.getElementById('mainLoginCard');
            const note = document.getElementById('vxLockNote');
            if (!card || !note) return;
            if (VX_DEMO_MODE) {
                card.classList.add('is-demo');
                const _du = document.getElementById('username');
                const _dp = document.getElementById('password');
                if (_du && !_du.value) _du.value = 'demo';
                if (_dp && !_dp.value) _dp.value = 'demo';
                note.className = 'vx-lock-note show';
                note.innerHTML = '<b>حالت نمایشی فعال است.</b> ورود عادی و ورود سریع بسته شده‌اند؛ فقط حساب آزمایشی ' +
                    '<b style="direction:ltr;display:inline-block;">demo / demo</b> کار می‌کند.' +
                    '<div class="vx-lock-actions"><button type="button" class="vx-lock-btn" onclick="showDemoGate()">نمایش اطلاعات دمو</button></div>';
            } else if (VX_LOGIN_LOCKED) {
                card.classList.add('is-closed');
                note.className = 'vx-lock-note show';
                note.innerHTML = '<b>ورود کاربران موقتاً بسته شده است.</b> فقط مدیر کل می‌تواند وارد شود و قفل را بردارد.' +
                    '<div class="vx-lock-actions"><a class="vx-lock-btn" href="admin-login.php" style="display:grid;place-items:center;text-decoration:none;">ورود مدیر</a></div>';
            } else if (VX_REG_LOCKED) {
                note.className = 'vx-lock-note show is-warn';
                note.innerHTML = '<b>ثبت‌نام بسته است.</b> فقط حساب‌های قبلی می‌توانند وارد شوند؛ نام کاربری جدید ساخته نمی‌شود.';
            }
        }

        function vxGuardEntry(point) {
            if (VX_DEMO_MODE) {
                showToast('در حالت نمایشی فقط ورود با demo ممکن است');
                showDemoGate();
                return true;
            }
            if (VX_LOGIN_LOCKED) {
                showToast('ورود کاربران موقتاً بسته شده است');
                return true;
            }
            return false;
        }

        const enableIntroPopup = <?php echo isset($enable_intro_popup) && $enable_intro_popup ? 'true' : 'false'; ?>;
        
        function closeIntroPopup() {
            const popup = document.getElementById('introPopup');
            popup.classList.remove('active');
            localStorage.setItem('bw_intro_seen_v1', 'true');
            setTimeout(() => {
                popup.style.display = 'none';
            }, 400);
        }

        function openGuide() {
            const ov = document.getElementById('guideOverlay');
            ov.style.display = 'flex';
            requestAnimationFrame(() => requestAnimationFrame(() => ov.classList.add('active')));
        }

        function closeGuide() {
            const ov = document.getElementById('guideOverlay');
            ov.classList.remove('active');
            localStorage.setItem('bw_login_guide_seen', '1');
            setTimeout(() => { ov.style.display = 'none'; }, 380);
        }

        window.addEventListener('load', () => {
            // requests.php?logout=1 now answers a browser navigation with a
            // 302 to index.php?bye=… instead of a raw JSON document.
            const byeState = <?php echo json_encode((string)($_GET['bye'] ?? '')); ?>;
            if (byeState === '1' || byeState === 'stale') {
                setTimeout(() => {
                    const t = document.getElementById('toast');
                    if (t) t.classList.add('toast-ok');
                    showToast(byeState === '1'
                        ? 'با موفقیت از حساب خارج شدید'
                        : 'نشست شما از قبل بسته شده بود');
                }, 240);
            }
            if(enableIntroPopup) {
                const seen = localStorage.getItem('bw_intro_seen_v1');
                if(!seen) {
                    const popup = document.getElementById('introPopup');
                    popup.style.display = 'flex';
                    setTimeout(() => popup.classList.add('active'), 100);
                    return;
                }
            }
            const guideSeen = localStorage.getItem('bw_login_guide_seen');
            if(!guideSeen) {
                setTimeout(() => openGuide(), 600);
            }
        });

        function showToast(msg) { 
            const t=document.getElementById("toast"); 
            t.innerHTML = msg; 
            t.classList.add("show"); 
            setTimeout(()=>t.classList.remove("show"), 4000); 
        }
        
        function refreshBothCaptchas() {
            refreshCaptcha();
            if(isCodeMode) refreshCaptchaCode();
        }

        // Shared refresh routine. The old code looked the button up with
        // `#loginWithCodeCard .refresh-btn`, an id that never existed, so the
        // icon never reacted. It is now passed straight from the click.
        function _captchaRefresh(btn, boxId, inputId) {
            if (btn && btn.dataset.busy === '1') return;
            if (btn) {
                btn.dataset.busy = '1';
                btn.classList.remove('rotating');
                void btn.offsetWidth;
                btn.classList.add('rotating');
                setTimeout(function () {
                    btn.classList.remove('rotating');
                    btn.dataset.busy = '0';
                }, 760);
            }
            const box = document.getElementById(boxId);
            const input = document.getElementById(inputId);
            if (box) { box.classList.remove('captcha-pop'); box.classList.add('captcha-loading'); }
            if (input) input.value = '';

            const fd = new FormData();
            fd.append('action', 'refresh_captcha');
            fd.append('csrf_token', csrfToken);

            fetch('requests.php', { method: 'POST', body: fd, cache: 'no-store' })
                .then(r => r.json())
                .then(d => {
                    if (d.status !== 'success') return;
                    if (box) {
                        box.classList.remove('captcha-loading');
                        box.textContent = d.n1 + ' + ' + d.n2;
                        void box.offsetWidth;
                        box.classList.add('captcha-pop');
                        setTimeout(() => box.classList.remove('captcha-pop'), 420);
                    }
                    if (input) input.focus();
                })
                .catch(() => {})
                .finally(() => { if (box) box.classList.remove('captcha-loading'); });
        }

        function refreshCaptcha(btn) {
            _captchaRefresh(btn || document.querySelector('#mainLoginCard .refresh-btn'), 'captchaBox', 'captchaInput');
        }

        function refreshCaptchaCode(btn) {
            _captchaRefresh(btn || document.querySelector('#loginWithCodeModal .refresh-btn'), 'captchaBoxCode', 'captchaInputCode');
        }
        
        function quickLogin(username) {
            if (vxGuardEntry('quick')) return;
            document.getElementById('loadingOverlay').style.display = 'flex';
            var rfd = new FormData();
            rfd.append('action', 'refresh_captcha');
            rfd.append('csrf_token', csrfToken);
            fetch('requests.php', { method: 'POST', body: rfd })
                .then(r => r.json())
                .then(d => {
                    if (d.status !== 'success') {
                        document.getElementById('loadingOverlay').style.display = 'none';
                        showToast('خطا در ورود سریع');
                        return;
                    }
                    var answer = parseInt(d.n1) + parseInt(d.n2);
                    document.getElementById('captchaBox').textContent = d.n1 + ' + ' + d.n2;
                    document.getElementById('captchaInput').value = answer;
                    document.getElementById('username').value = username;
                    var fd = new FormData();
                    fd.append('csrf_token', csrfToken);
                    fd.append('username', username);
                    fd.append('captcha', answer);
                    fd.append('action', 'login_check');
                    fetch('requests.php', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(res => {
                            if (res.status === 'password_required') {
                                document.getElementById('loadingOverlay').style.display = 'none';
                                document.getElementById('step1').classList.add('hidden');
                                document.getElementById('step2').classList.remove('hidden');
                                document.getElementById('password').required = true;
                                document.getElementById('password').focus();
                                document.getElementById('submitBtn').innerText = 'تایید نهایی';
                            } else {
                                fd.delete('action');
                                fd.append('action', 'login');
                                submitLogin(fd);
                            }
                        })
                        .catch(() => { _loginInFlight = false; if (_sb) { _sb.disabled = false; _sb.innerText = _sbText; _sb.style.opacity = ''; } document.getElementById('loadingOverlay').style.display='none'; showToast('خطا در ارتباط'); });
                })
                .catch(() => {
                    _loginInFlight = false;
                    if (_sb) { _sb.disabled = false; _sb.innerText = _sbText; _sb.style.opacity = ''; }
                    document.getElementById('loadingOverlay').style.display = 'none';
                    showToast('خطا در ارتباط با سرور');
                });
        }
        
        let usernameCheckTimeout;
        document.getElementById('username').addEventListener('keyup', function() {
            this.value = this.value.replace(/\s{2,}/g, ' ');

            clearTimeout(usernameCheckTimeout);
            const val = this.value.trim();
            const errBox = document.getElementById('usernameError');
            this.classList.remove('input-error');
            errBox.className = 'error-msg-box';
            errBox.style.display = 'none';
            
            if(val.length > 2) {
                usernameCheckTimeout = setTimeout(() => {
                    const fd = new FormData();
                    fd.append('action', 'check_username_availability');
                    fd.append('username', val);
                    fd.append('csrf_token', csrfToken);
                    fetch('requests.php', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(d => {
                            if(d.status === 'taken') {
                                // username موجوده = کاربر می‌تونه login کنه
                                document.getElementById('username').classList.remove('input-error');
                                errBox.className = 'error-msg-box is-ok';
                                errBox.style.display = 'block';
                                errBox.textContent = 'این نام کاربری موجود است — می‌توانید وارد شوید';
                            } else if(d.status === 'register_locked' || d.status === 'demo_only' || d.status === 'login_locked') {
                                // Say it now instead of after the whole form is filled.
                                document.getElementById('username').classList.add('input-error');
                                errBox.className = 'error-msg-box';
                                errBox.style.display = 'block';
                                errBox.textContent = d.message || 'ثبت‌نام در این سایت بسته شده است';
                            }
                        });
                }, 800);
            }
        });

        document.getElementById('loginForm').addEventListener('submit', function(e) {
            e.preventDefault();
            if (VX_LOGIN_LOCKED && !VX_DEMO_MODE) {
                showToast('ورود کاربران موقتاً بسته شده است');
                return;
            }
            if (VX_DEMO_MODE) {
                const _u = (document.getElementById('username').value || '').trim().toLowerCase();
                if (_u !== 'demo') {
                    showToast('در حالت نمایشی فقط ورود با demo ممکن است');
                    showDemoGate();
                    return;
                }
            }
            const fd = new FormData(this); fd.append('csrf_token', csrfToken);
            if (window.VXFP) VXFP.appendTo(fd);
            
            if(document.getElementById('step2').classList.contains('hidden')) {
                fd.append('action', 'login_check');
                fetch('requests.php', { method: 'POST', body: fd }).then(r=>r.json()).then(d=>{
                    if(d.status === 'password_required') { 
                        document.getElementById('step1').classList.add('hidden');
                        document.getElementById('step2').classList.remove('hidden');
                        document.getElementById('password').required = true; 
                        document.getElementById('password').focus(); 
                        document.getElementById('submitBtn').innerText = 'تایید نهایی'; 
                        // Demo has one shared credential, so the password step is
                        // pure friction. Continue straight to the login request.
                        if (VX_DEMO_MODE) {
                            const fd2 = new FormData();
                            fd.forEach((v, k) => { if (k !== 'action') fd2.append(k, v); });
                            setTimeout(() => submitLogin(fd2), 80);
                        }
                    } else if (d.status === 'demo_only' || d.status === 'login_locked') {
                        showToast(d.message || 'ورود بسته است');
                        if (d.status === 'demo_only') showDemoGate();
                    } else if (d.status === 'error' && d.message) {
                        // e.g. the demo sandbox is switched off on this site
                        showToast(d.message);
                    } else submitLogin(fd);
                });
            } else submitLogin(fd);
        });

        function handleBan(message, type) {
            document.getElementById('banMessageDetail').innerHTML = message ? message.replace(/\n/g, '<br>') : 'دسترسی شما به سیستم محدود شده است.';
            
            const modal = document.getElementById('banModal');
            modal.classList.add('active');
            
            const content = modal.querySelector('.ban-modal-content');
            content.style.background = 'linear-gradient(145deg, #7f1d1d, #450a0a)';
            content.style.borderColor = '#ef4444';
            modal.querySelector('.ban-icon').style.filter = 'drop-shadow(0 0 25px rgba(239, 68, 68, 0.8))';
            
            document.getElementById('loadingOverlay').style.display = 'none';
            
            if (type !== 'perm') {
                setTimeout(() => {
                    const logoutNonce = "<?php echo $_SESSION['logout_nonce'] ?? ''; ?>";
                    window.location.href = 'requests.php?logout=1&nonce=' + logoutNonce;
                }, 5000);
            }
        }
        
        let _loginInFlight = false;
        function submitLogin(fd) {
            if (_loginInFlight) return;
            _loginInFlight = true;
            const _sb = document.getElementById('submitBtn');
            const _sbText = _sb ? _sb.innerText : '';
            if (_sb) { _sb.disabled = true; _sb.innerText = 'در حال ورود...'; _sb.style.opacity = '0.7'; }
            document.getElementById('loadingOverlay').style.display = 'flex';
            fd.append('action', 'login');
            var loginUsername = fd.get ? fd.get('username') : '';
            fetch('requests.php', { method: 'POST', body: fd }).then(r=>r.json()).then(d=>{
                if(d.status === 'success') { 
                    localStorage.removeItem('welcomeShown'); 
                    if(d.security_code) {
                        localStorage.setItem('newSecurityCode', d.security_code);
                    }
                    if(d.needs_phone) {
                        localStorage.setItem('bw_needs_phone', '1');
                    }
                    if (_sb) { _sb.innerText = 'خوش آمدید ✓'; }
                    setTimeout(()=>location.reload(), 350); 
                } 
                else { 
                    _loginInFlight = false;
                    if (_sb) { _sb.disabled = false; _sb.innerText = _sbText; _sb.style.opacity = ''; }
                    document.getElementById('loadingOverlay').style.display = 'none';
                    if (d.status === 'banned') {
                        handleBan(d.message, d.ban_type);
                    } else if(d.status === 'device_mismatch') {
                        document.getElementById('loadingOverlay').style.display = 'none';
                        var errBox = document.getElementById('deviceMismatchError');
                        if (!errBox) {
                            errBox = document.createElement('div');
                            errBox.id = 'deviceMismatchError';
                            errBox.style.cssText = 'background:rgba(239,68,68,0.13);border:1px solid rgba(239,68,68,0.45);border-right:4px solid #ef4444;border-radius:12px;padding:14px 18px;margin-bottom:16px;color:#fca5a5;font-size:13px;line-height:1.8;text-align:right;animation:shake 0.4s;';
                            var card = document.getElementById('mainLoginCard');
                            if (card) {
                                var form = document.getElementById('loginForm');
                                if (form) card.insertBefore(errBox, form);
                                else card.insertBefore(errBox, card.firstChild);
                            }
                        }
                        errBox.innerHTML = '<span style="font-size:16px;margin-left:8px;">⚠️</span>' + (d.message || 'این نام کاربری متعلق به دستگاه دیگری است');
                        errBox.style.display = 'block';
                        clearTimeout(errBox._dismissTimer);
                        errBox._dismissTimer = setTimeout(function() {
                            errBox.style.transition = 'opacity 0.5s';
                            errBox.style.opacity = '0';
                            setTimeout(function() { errBox.style.display = 'none'; errBox.style.opacity = ''; errBox.style.transition = ''; }, 500);
                        }, 5000);
                        if (d.has_phone) {
                            var phoneFd = new FormData();
                            phoneFd.append('csrf_token', csrfToken);
                            phoneFd.append('action', 'request_phone_otp');
                            phoneFd.append('username', loginUsername || d.username || '');
                            phoneFd.append('captcha', document.getElementById('captchaInput') ? document.getElementById('captchaInput').value : '');
                            fetch('requests.php', { method: 'POST', body: phoneFd }).then(r2=>r2.json()).then(d2=>{
                                if (d2.status === 'success') {
                                    openPhoneLoginModal(loginUsername || d.username || '', d2.otp, d2.phone_masked);
                                }
                            });
                        }
                        refreshCaptcha();
                    } else if(d.status === 'capacity_full') {
                        openPrevAccountsModal(d.previous_users || [], true);
                        refreshCaptcha();
                    } else if(d.status === 'rate_limited') {
                        showToast('⏳ ' + (d.message || 'تلاش‌های زیاد؛ کمی بعد تلاش کنید'));
                        refreshCaptcha();
                    } else if(d.status === 'demo_only') {
                        showToast(d.message || 'در حالت نمایشی فقط ورود با demo ممکن است');
                        showDemoGate();
                        refreshCaptcha();
                    } else if(d.status === 'login_locked') {
                        showToast(d.message || 'ورود کاربران موقتاً بسته شده است');
                        refreshCaptcha();
                    } else if(d.status === 'register_locked') {
                        showToast(d.message || 'ثبت‌نام بسته شده است');
                        refreshCaptcha();
                    } else {
                        showToast(d.message); refreshCaptcha(); if(document.getElementById('step2').style.display!='none') document.getElementById('password').value=''; 
                    }
                }
            }).catch(()=>{ document.getElementById('loadingOverlay').style.display='none'; showToast("خطا در ارتباط"); });
        }

        // ─── Previous Accounts Bottom-Sheet Popup ──────────────────────────────
        function buildPrevAccountRows(users) {
            const list = document.getElementById('prevAccountsList');
            list.innerHTML = '';
            users.forEach(u => {
                const row = document.createElement('div');
                row.className = 'sheet-user-row';
                row.innerHTML = `
                    <span style="font-size:28px;flex-shrink:0;">${u.sticker || '👤'}</span>
                    <div style="flex:1;text-align:right;min-width:0;">
                        <div style="color:#e2e8f0;font-size:14px;font-weight:bold;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${u.username}</div>
                        <div style="font-size:10px;color:#64748b;margin-top:2px;">ورود مستقیم بدون کد امنیتی</div>
                    </div>
                    <span style="color:#10b981;font-size:11px;background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.25);padding:3px 10px;border-radius:8px;flex-shrink:0;">ورود ⚡</span>
                `;
                row.onclick = () => {
                    closePrevAccountsModal();
                    setTimeout(() => quickLogin(u.username), 200);
                };
                list.appendChild(row);
            });
        }

        function openPrevAccountsModal(users, isCapacityFull) {
            if (vxGuardEntry('prev')) return;
            if (!users || users.length === 0) {
                if (isCapacityFull) {
                    showToast('برای ورود از کد امنیتی استفاده کنید');
                    setTimeout(() => showLoginWithCode(), 600);
                }
                return;
            }
            const subtitle = document.getElementById('prevAccountsSubtitle');
            if (isCapacityFull) {
                subtitle.textContent = 'شما به سقف ساخت حساب رسیده‌اید. یکی از حساب‌های قبلی خود را انتخاب کنید:';
            } else {
                subtitle.textContent = 'حساب‌های شناسایی‌شده از این دستگاه — برای ورود سریع انتخاب کنید:';
            }
            buildPrevAccountRows(users);
            const modal = document.getElementById('prevAccountsModal');
            modal.style.display = 'flex';
            requestAnimationFrame(() => requestAnimationFrame(() => modal.classList.add('open')));
        }

        function closePrevAccountsModal() {
            const modal = document.getElementById('prevAccountsModal');
            modal.classList.remove('open');
            setTimeout(() => { modal.style.display = 'none'; }, 380);
        }

        // Auto-open popup on page load is handled by initQuickLogin (respects the checkbox).

        function veyraxSyncSwatches(c) {
            var sw = document.querySelectorAll('.v-swatch[data-color]');
            var anyPreset = false;
            for (var i = 0; i < sw.length; i++) {
                var active = sw[i].getAttribute('data-color').toLowerCase() === String(c || '').toLowerCase();
                sw[i].classList.toggle('active', active);
                if (active) anyPreset = true;
            }
            var custom = document.querySelector('.v-swatch-custom');
            if (custom) custom.classList.toggle('active', !anyPreset);
        }
        window.veyraxSyncSwatches = veyraxSyncSwatches;

        var CP_PRESETS = ['#6366f1','#8b5cf6','#a855f7','#d946ef','#ec4899','#f43f5e',
                          '#ef4444','#f97316','#f59e0b','#eab308','#84cc16','#22c55e',
                          '#10b981','#14b8a6','#06b6d4','#0ea5e9','#3b82f6','#64748b'];
        var _cpColor = '#6366f1';

        function _cpPaint(v) {
            _cpColor = v;
            var p = document.getElementById('cpPreview');
            if (p) p.style.background = v;
            var inp = document.getElementById('cpInput');
            if (inp && inp.value !== v) inp.value = v;
            var dots = document.querySelectorAll('#cpGrid .cp-dot');
            for (var i = 0; i < dots.length; i++) {
                dots[i].classList.toggle('active', dots[i].dataset.c === v);
            }
        }

        function openColorPicker() {
            var cur = window.veyraxThemeState ? window.veyraxThemeState().color : '#6366f1';
            var start = /^#[0-9a-fA-F]{6}$/.test(cur) ? cur.toLowerCase() : '#6366f1';
            var grid = document.getElementById('cpGrid');
            if (grid && !grid.childElementCount) {
                grid.innerHTML = CP_PRESETS.map(function (c) {
                    return '<button type="button" class="cp-dot" data-c="' + c + '" style="background:' + c + '" onclick="onCpInput(\'' + c + '\')" aria-label="' + c + '"></button>';
                }).join('');
            }
            _cpPaint(start);
            document.getElementById('colorPickerOverlay').classList.add('open');
        }

        function closeColorPicker() {
            var el = document.getElementById('colorPickerOverlay');
            if (el) el.classList.remove('open');
        }

        function onCpInput(v) {
            if (!/^#[0-9a-fA-F]{6}$/.test(v)) return;
            _cpPaint(v.toLowerCase());
        }

        function applyCustomColor() {
            veyraxApplyTheme(_cpColor);
            closeColorPicker();
        }

        function quickLoginPref() {
            try {
                var v = localStorage.getItem('veyrax_quicklogin');
                return v === null ? null : v === '1';
            } catch (e) { return null; }
        }

        function setQuickLogin(on) {
            try { localStorage.setItem('veyrax_quicklogin', on ? '1' : '0'); } catch (e) {}
            var box = document.getElementById('quickLoginToggle');
            if (box && box.checked !== !!on) box.checked = !!on;
            if (on) {
                if (window._prevAccounts && window._prevAccounts.length > 0) {
                    openPrevAccountsModal(window._prevAccounts, false);
                }
            } else {
                closePrevAccountsModal();
            }
        }

        function initQuickLogin() {
            var row = document.getElementById('quickLoginRow');
            if (!row) return;
            var accounts = window._prevAccounts || [];
            if (!accounts.length) { row.style.display = 'none'; return; }
            row.style.display = 'flex';
            var count = document.getElementById('qlCount');
            if (count) count.textContent = accounts.length;
            var pref = quickLoginPref();
            var on = pref === null ? true : pref;
            var box = document.getElementById('quickLoginToggle');
            if (box) box.checked = on;
            if (on) setTimeout(function () { openPrevAccountsModal(accounts, false); }, 620);
        }

        document.addEventListener('DOMContentLoaded', function () {
            try { veyraxSyncSwatches(window.veyraxThemeState ? window.veyraxThemeState().color : '#6366f1'); } catch (e) {}
            initQuickLogin();
        });

        function showLoginWithCode() {
            if (vxGuardEntry('code')) return;
            isCodeMode = true;
            const mainUser = document.getElementById('username').value.trim();
            const codeUser = document.getElementById('codeUsername');
            codeUser.value = mainUser;
            const modal = document.getElementById('loginWithCodeModal');
            modal.classList.add('open');
            refreshCaptchaCode();
            // Handle keyboard on mobile: reposition modal within visual viewport
            if(window.visualViewport) {
                const vp = window.visualViewport;
                const reposition = () => {
                    modal.style.position = 'fixed';
                    modal.style.top = vp.offsetTop + 'px';
                    modal.style.height = vp.height + 'px';
                };
                reposition();
                vp.addEventListener('resize', reposition);
                vp.addEventListener('scroll', reposition);
                modal._vpClean = () => {
                    vp.removeEventListener('resize', reposition);
                    vp.removeEventListener('scroll', reposition);
                    modal.style.top = '';
                    modal.style.height = '';
                };
            }
            setTimeout(() => {
                if(mainUser) document.getElementById('securityCodeInput').focus();
                else codeUser.focus();
            }, 100);
        }

        function hideLoginWithCode() {
            isCodeMode = false;
            const modal = document.getElementById('loginWithCodeModal');
            modal.classList.remove('open');
            if(modal._vpClean) { modal._vpClean(); modal._vpClean = null; }
        }

        // ── Password reset by SMS code (shown only while SMS is connected) ──
        // Backed by requests.php forgot_start / forgot_verify / forgot_reset:
        // phone → 6-digit code → new password, throttled server-side.
        const _fp = { phone: '', timer: null };

        function _fpShow(step) {
            ['fpStep1','fpStep2','fpStep3','fpStepDone'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.style.display = (id === step) ? 'block' : 'none';
            });
            const err = document.getElementById('fpError');
            if (err) { err.style.display = 'none'; err.textContent = ''; }
        }

        function _fpError(msg) {
            const err = document.getElementById('fpError');
            if (!err) return;
            err.textContent = msg;
            err.style.display = 'block';
        }

        function _fpBusy(id, on) {
            const b = document.getElementById(id);
            if (b) b.classList.toggle('busy', !!on);
        }

        function openForgotPhone() {
            hideLoginWithCode(); hideSecRecover();
            _fp.phone = '';
            const ph = document.getElementById('fpPhone');
            if (ph) ph.value = '';
            const cd = document.getElementById('fpCode');
            if (cd) cd.value = '';
            ['fpPass1','fpPass2'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
            const dev = document.getElementById('fpDevCode');
            if (dev) { dev.style.display = 'none'; dev.textContent = ''; }
            _fpShow('fpStep1');
            const modal = document.getElementById('forgotPhoneModal');
            modal.classList.add('open');
            setTimeout(() => ph && ph.focus(), 120);
        }

        function hideForgotPhone() {
            const modal = document.getElementById('forgotPhoneModal');
            if (modal) modal.classList.remove('open');
            if (_fp.timer) { clearInterval(_fp.timer); _fp.timer = null; }
        }

        function fpStartCooldown(seconds) {
            const btn = document.getElementById('fpResendBtn');
            if (!btn) return;
            let left = seconds;
            btn.disabled = true;
            const tick = () => {
                if (left <= 0) { btn.disabled = false; btn.textContent = 'ارسال مجدد کد'; if (_fp.timer) { clearInterval(_fp.timer); _fp.timer = null; } return; }
                const fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
                btn.textContent = 'ارسال مجدد کد (' + String(left).replace(/[0-9]/g, d => fa[d]) + ' ثانیه)';
                left--;
            };
            tick();
            if (_fp.timer) clearInterval(_fp.timer);
            _fp.timer = setInterval(tick, 1000);
        }

        function fpPost(action, data, btnId) {
            _fpBusy(btnId, true);
            const fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('action', action);
            Object.keys(data).forEach(k => fd.append(k, data[k]));
            return fetch('requests.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .finally(() => _fpBusy(btnId, false));
        }

        function forgotStart() {
            const phone = (document.getElementById('fpPhone').value || '').trim();
            if (!/^09[0-9]{9}$/.test(phone)) { _fpError('شماره موبایل معتبر وارد کنید (مثال: 09123456789)'); return; }
            _fp.phone = phone;
            fpPost('forgot_start', { phone: phone }, 'fpStartBtn').then(d => {
                if (d.status === 'rate_limited') { _fpError(d.message || 'تلاش بیش از حد؛ کمی صبر کنید'); return; }
                if (d.status === 'sms_offline' || d.status === 'sms_failed') { _fpError(d.message || 'سامانه پیامک در دسترس نیست'); return; }
                if (d.status !== 'success') { _fpError(d.message || 'ارسال کد ممکن نشد'); return; }
                document.getElementById('fpPhoneMasked').textContent = d.phone_masked || phone;
                const dev = document.getElementById('fpDevCode');
                if (dev) {
                    if (d.dev_code) { dev.style.display = 'inline'; dev.textContent = ' — کد آزمایشی: ' + d.dev_code; }
                    else { dev.style.display = 'none'; dev.textContent = ''; }
                }
                _fpShow('fpStep2');
                fpStartCooldown(d.resend_after ? Math.min(90, Math.max(15, parseInt(d.resend_after, 10) || 60)) : 60);
                setTimeout(() => document.getElementById('fpCode') && document.getElementById('fpCode').focus(), 120);
            }).catch(() => _fpError('خطای شبکه؛ دوباره تلاش کنید'));
        }

        function forgotVerify() {
            const code = (document.getElementById('fpCode').value || '').trim();
            if (!/^[0-9]{6}$/.test(code)) { _fpError('کد ۶ رقمی را کامل وارد کنید'); return; }
            fpPost('forgot_verify', { phone: _fp.phone, code: code }, 'fpVerifyBtn').then(d => {
                if (d.status !== 'success') {
                    _fpError(d.message || 'کد تأیید نشد');
                    if (d.expired) { _fpShow('fpStep1'); }
                    return;
                }
                _fpShow('fpStep3');
                setTimeout(() => document.getElementById('fpPass1') && document.getElementById('fpPass1').focus(), 120);
            }).catch(() => _fpError('خطای شبکه؛ دوباره تلاش کنید'));
        }

        function forgotReset() {
            const p1 = document.getElementById('fpPass1').value || '';
            const p2 = document.getElementById('fpPass2').value || '';
            if (p1.length < 8 || p1.length > 20) { _fpError('رمز باید ۸ تا ۲۰ کاراکتر باشد'); return; }
            if (p1 !== p2) { _fpError('تکرار رمز با رمز جدید یکسان نیست'); return; }
            if (!/[A-Za-z]/.test(p1) || !/[0-9]/.test(p1)) { _fpError('رمز باید ترکیبی از حروف انگلیسی و عدد باشد'); return; }
            fpPost('forgot_reset', { phone: _fp.phone, password: p1, password2: p2 }, 'fpResetBtn').then(d => {
                if (d.status !== 'success') {
                    _fpError(d.message || 'تغییر رمز ممکن نشد');
                    if (d.restart) { _fpShow('fpStep1'); }
                    return;
                }
                if (_fp.timer) { clearInterval(_fp.timer); _fp.timer = null; }
                document.getElementById('fpDoneUser').textContent = d.username || '';
                _fpShow('fpStepDone');
            }).catch(() => _fpError('خطای شبکه؛ دوباره تلاش کنید'));
        }

        function forgotDoneToLogin() {
            const user = document.getElementById('fpDoneUser').textContent || '';
            hideForgotPhone();
            const un = document.getElementById('username');
            if (un && user) { un.value = user; un.focus(); }
        }

        // ── Security-code recovery (replaces the SMS-only "forgot password") ──
        function refreshCaptchaRecover(btn) {
            _captchaRefresh(btn || null, 'captchaBoxRecover', 'captchaInputRecover');
        }

        // Same eye toggle the chat page has — the login document never defined
        // it, so the 👁 buttons were dead here.
        function togglePassVisibility(inputId, btn) {
            const el = document.getElementById(inputId);
            if (!el) return;
            const show = el.type === 'password';
            el.type = show ? 'text' : 'password';
            const use = btn.querySelector('use');
            if (use) use.setAttribute('href', show ? '#v3i-eye-off' : '#v3i-eye');
            else btn.textContent = show ? '•' : '•';
            btn.setAttribute('aria-label', show ? 'پنهان کردن رمز' : 'نمایش رمز');
            // Animate the icon, never the button: the eye in the phone-auth
            // sheet is absolutely positioned with translateY(-50%), and
            // overwriting `transform` was what dropped it half its height.
            btn.classList.remove('eye-pulse');
            void btn.offsetWidth;
            btn.classList.add('eye-pulse');
            setTimeout(function () { btn.classList.remove('eye-pulse'); }, 180);
        }

        function toggleSrOptional() {
            const box = document.getElementById('srOptional');
            const tgl = document.getElementById('srOptToggle');
            if (!box) return;
            const open = box.style.display === 'none' || !box.style.display;
            box.style.display = open ? 'block' : 'none';
            if (tgl) tgl.classList.toggle('open', open);
            if (open) {
                const p = document.getElementById('srPass');
                if (p) setTimeout(function () { p.focus(); }, 80);
            }
        }

        function showSecRecover() {
            if (vxGuardEntry('recover')) return;
            const modal = document.getElementById('secRecoverOverlay');
            const mainUser = document.getElementById('username');
            const srU = document.getElementById('srUsername');
            const msg = document.getElementById('srMsg');
            if (mainUser && mainUser.value.trim() && srU && !srU.value) srU.value = mainUser.value.trim();
            if (msg) { msg.textContent = ''; msg.className = 'sr-msg'; }
            const opt = document.getElementById('srOptional');
            if (opt) opt.style.display = 'none';
            const tgl = document.getElementById('srOptToggle');
            if (tgl) tgl.classList.remove('open');
            modal.classList.add('open');
            refreshCaptchaRecover(null);
            setTimeout(function () {
                const codeEl = document.getElementById('srCode');
                if (srU && srU.value) { if (codeEl) codeEl.focus(); }
                else if (srU) srU.focus();
            }, 120);
        }

        function hideSecRecover() {
            const modal = document.getElementById('secRecoverOverlay');
            if (modal) modal.classList.remove('open');
        }

        document.getElementById('secRecoverForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = document.getElementById('srSubmitBtn');
            const msg = document.getElementById('srMsg');
            const u = document.getElementById('srUsername').value.trim();
            const code = document.getElementById('srCode').value.trim().toUpperCase();
            const p1 = document.getElementById('srPass').value;
            const p2 = document.getElementById('srPass2').value;
            const nu = document.getElementById('srNewUser').value.trim();
            const cap = (document.getElementById('captchaInputRecover').value || '').trim();

            function say(text, ok) {
                if (!msg) return;
                msg.textContent = text;
                msg.className = 'sr-msg ' + (ok ? 'ok' : 'err');
            }

            if (!u || !code) return say('نام کاربری و کد امنیتی را وارد کنید', false);
            if (!cap) return say('پاسخ امنیتی را وارد کنید', false);
            if ((p1 || p2) && p1 !== p2) return say('رمز جدید و تکرارش یکسان نیست', false);

            btn.disabled = true;
            btn.textContent = 'در حال بررسی...';

            const fd = new FormData();
            fd.append('action', 'sec_recover');
            fd.append('csrf_token', csrfToken);
            fd.append('username', u);
            fd.append('security_code', code);
            fd.append('captcha', cap);
            if (p1) { fd.append('new_password', p1); fd.append('new_password2', p2); }
            if (nu) fd.append('new_username', nu);

            fetch('requests.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    btn.disabled = false;
                    btn.textContent = 'اعمال تغییرات';
                    if (d.status === 'success') {
                        say(d.message, true);
                        setTimeout(function () { window.location.href = 'index.php'; }, 900);
                    } else if (d.status === 'rate_limited' && d.retry_after) {
                        let left = parseInt(d.retry_after, 10) || 30;
                        say(d.message, false);
                        btn.disabled = true;
                        const tick = setInterval(function () {
                            left--;
                            if (left <= 0) {
                                clearInterval(tick);
                                btn.disabled = false;
                                btn.textContent = 'اعمال تغییرات';
                                say('می‌توانید دوباره تلاش کنید', false);
                            } else {
                                btn.textContent = 'تلاش دوباره تا ' + left + ' ثانیه';
                            }
                        }, 1000);
                    } else {
                        // The captcha is single-use; any failure needs a new one.
                        if (d.refresh_captcha !== false) refreshCaptchaRecover(null);
                        say(d.message || 'خطا در انجام عملیات', false);
                    }
                })
                .catch(function () {
                    btn.disabled = false;
                    btn.textContent = 'اعمال تغییرات';
                    refreshCaptchaRecover(null);
                    say('خطای شبکه؛ دوباره تلاش کنید', false);
                });
        });

        document.getElementById('loginCodeForm').addEventListener('submit', function(e) {
            e.preventDefault();
            document.getElementById('loadingOverlay').style.display = 'flex';
            const fd = new FormData();
            fd.append('action', 'login_with_code');
            fd.append('csrf_token', csrfToken);
            fd.append('username', document.getElementById('codeUsername').value);
            fd.append('security_code', document.getElementById('securityCodeInput').value);
            fd.append('captcha', document.getElementById('captchaInputCode').value);
            fetch('requests.php', { method: 'POST', body: fd }).then(r=>r.json()).then(d=>{
                if(d.status === 'success') {
                    localStorage.removeItem('welcomeShown');
                    setTimeout(()=>location.reload(), 500);
                } else {
                    document.getElementById('loadingOverlay').style.display = 'none';
                    if(d.status === 'banned') {
                        handleBan(d.message, d.ban_type);
                    } else if (d.status === 'rate_limited') {
                        showToast('⏳ ' + (d.message || 'تلاش‌های زیاد'));
                        refreshCaptchaCode();
                        document.getElementById('captchaInputCode').value = '';
                    } else {
                        showToast(d.message);
                        refreshCaptchaCode();
                        document.getElementById('captchaInputCode').value = '';
                    }
                }
            }).catch(()=>{ document.getElementById('loadingOverlay').style.display='none'; showToast('خطا در ارتباط'); });
        });

        function openTerms() {
            document.getElementById('termsModal').classList.add('active');
        }
        function closeTerms() {
            document.getElementById('termsModal').classList.remove('active');
        }

        function refreshServerStatus() {
            const bar = document.getElementById('serverStatusBar');
            const label = document.getElementById('serverPingLabel');
            const msEl = document.getElementById('serverPingMs');
            const countEl = document.getElementById('onlineCountLabel');
            if (!bar || !label) return;
            bar.dataset.state = 'checking';
            label.textContent = 'در حال بررسی…';
            msEl.textContent = '';
            const start = performance.now();
            const fd = new FormData();
            fd.append('action', 'ping_server');
            fd.append('csrf_token', csrfToken);
            fetch('requests.php', { method: 'POST', body: fd, cache: 'no-store' })
                .then(r => r.json())
                .then(() => {
                    const elapsed = Math.max(1, Math.round(performance.now() - start));
                    bar.dataset.state = 'online';
                    label.textContent = 'آنلاین';
                    msEl.textContent = elapsed + ' ms';
                })
                .catch(() => {
                    bar.dataset.state = 'offline';
                    label.textContent = 'قطع ارتباط';
                    msEl.textContent = '';
                });
            const fd2 = new FormData();
            fd2.append('action', 'get_online_count');
            fd2.append('csrf_token', csrfToken);
            fetch('requests.php', { method: 'POST', body: fd2, cache: 'no-store' })
                .then(r => r.json())
                .then(d => {
                    if (d.status !== 'success') return;
                    const next = String(d.count);
                    if (countEl.textContent === next) return;
                    countEl.textContent = next;
                    countEl.classList.remove('is-bump');
                    void countEl.offsetWidth;
                    countEl.classList.add('is-bump');
                    setTimeout(() => countEl.classList.remove('is-bump'), 280);
                }).catch(() => {});
        }

        const serverBarEl = document.getElementById('serverStatusBar');
        if (serverBarEl) {
            serverBarEl.addEventListener('keydown', e => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    refreshServerStatus();
                }
            });
        }

        window.addEventListener('load', () => {
            refreshServerStatus();
            setInterval(() => { if (!document.hidden) refreshServerStatus(); }, 60000);
        });

        // The access state is known before the page paints, so the locked /
        // demo variants are applied immediately instead of flashing the normal
        // form for a moment and then hiding it.
        applyAccessUi();
        if (VX_DEMO_MODE) {
            setTimeout(showDemoGate, 260);
        }
        (function () {
            const gate = document.getElementById('demoGate');
            if (gate) {
                gate.addEventListener('click', e => { if (e.target === gate) hideDemoGate(); });
            }
            document.addEventListener('keydown', e => {
                if (e.key === 'Escape') hideDemoGate();
            });
        })();
    </script>
</body>
</html>
<?php exit; } ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, interactive-widget=resizes-content">
    <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token']; ?>">
    <meta name="bw-nonce" content="<?php echo isset($_SESSION['bw_nonce']) ? $_SESSION['bw_nonce'] : ''; ?>">    <meta name="logout-nonce" content="<?php echo $_SESSION['logout_nonce']; ?>">
    <meta name="theme-color" content="#1e293b">
    <?php veyrax_theme_bootstrap($uiColor, $uiScale, true); ?>
    <?php veyrax_icon_links(); ?>
    <?php veyrax_ui_kit(); ?>
    <script src="assets/js/vx-cache.js?v=<?php echo VEYRAX_ASSET_VERSION; ?>" defer></script>
    <script src="assets/js/vx-net.js?v=<?php echo VEYRAX_ASSET_VERSION; ?>" defer></script>
    <title>چت روم <?php echo $site_brand_fa; ?></title>
    <style>
        @font-face { font-family: 'AppFont'; src: url('fonts/<?php echo $font_text; ?>.ttf') format('truetype'); font-weight: normal; font-display: swap; }
        @font-face { font-family: 'AppBold'; src: url('fonts/<?php echo $font_heading; ?>.ttf') format('truetype'); font-weight: bold; font-display: swap; }
        :root { --bg: var(--v-bg-0, #0f172a); --panel: var(--v-surface, #1e293b); --border: var(--v-border, #334155); --primary: var(--v-primary, #6366f1); --text: #f8fafc; --msg-me: var(--v-primary-active, #4f46e5); --msg-other: var(--v-bubble, #334155);
                --msg-font: <?php echo $msg_font_stack; ?>; --msg-weight: <?php echo (int)$msg_font_weight; ?>; }
        /* Reveal-password button lives beside the field, outside its border. */
        .v-pass-row { display: flex; align-items: stretch; gap: 8px; }
        .v-pass-row > input { flex: 1 1 auto; min-width: 0; width: auto !important; margin-bottom: 0 !important; }
        .v-pass-row > .v-eye-btn {
            flex: 0 0 44px; width: 44px !important; height: auto !important; min-height: 44px;
            margin: 0 !important; padding: 0; display: flex; align-items: center; justify-content: center;
            background: rgba(255,255,255,0.05); border: 1px solid var(--v-primary-border, rgba(99,102,241,0.25));
            border-radius: 11px; color: #94a3b8; cursor: pointer; font-size: 17px; box-shadow: none;
        }
        .v-pass-row > .v-eye-btn:hover { background: rgba(148,163,184,0.15); color: #e2e8f0; }
        .v-pass-row > .v-eye-btn:active { transform: scale(0.96); }
        .v-eye-ico { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; }
        .v-eye-btn .i3d, .v-eye-ico .i3d { width: 20px; height: 20px; filter: drop-shadow(0 2px 3px rgba(0,0,0,0.45)); }
        .eye-pulse .v-eye-ico { animation: vEyePop 0.18s cubic-bezier(.3,.8,.3,1); }
        * { box-sizing: border-box; font-family: 'AppFont', 'Noto Color Emoji', 'Segoe UI Emoji', 'Apple Color Emoji', 'Twemoji Mozilla', sans-serif; -webkit-tap-highlight-color: transparent; }
        ::-webkit-scrollbar { display: none; }
        * { -ms-overflow-style: none; scrollbar-width: none; }
        
        body { margin: 0; background-color: var(--v-bg-0, #0f172a); background-image: radial-gradient(760px 420px at 50% -10%, var(--v-glow-a, rgba(99,102,241,0.2)), transparent 62%), linear-gradient(180deg, var(--v-bg-1, #131a2b) 0%, var(--v-bg-0, #0b1020) 55%, var(--v-bg-2, #080b14) 100%); background-attachment: fixed; color: var(--text); height: 100vh; height: 100dvh; display: flex; flex-direction: column; overflow: hidden; position: fixed; width: 100%; top: 0; left: 0; -webkit-touch-callout: none; }
        
        .sidebar { position: absolute; right: 0; top: 0; width: min(86vw, 310px); height: 100%; background: var(--panel); border-left: 1px solid var(--border); z-index: 50; transition: transform 0.38s cubic-bezier(0.2, 0.8, 0.2, 1); transform: translateX(100%); display: flex; flex-direction: column; box-shadow: -10px 0 30px rgba(0,0,0,0.5); will-change: transform; }
        .sidebar.open { transform: translateX(0); }
        .backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(3px); z-index: 40; display: none; opacity: 0; transition: opacity 0.3s; }
        .backdrop.show { display: block; opacity: 1; }

        /* Sidebar header: the close button used to be absolutely positioned at
           top/left and floated on top of the search field, which broke on small
           screens. It now sits in a normal flex row with the brand. */
        .sidebar-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 14px 10px; flex-shrink: 0; border-bottom: 1px solid rgba(51,65,85,0.6); background: linear-gradient(180deg, rgba(99,102,241,0.10), rgba(30,41,59,0)); }
        .sidebar-brand { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .sidebar-brand-mark {
            position: relative; width: 36px; height: 36px; border-radius: 12px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-family: 'AppBold'; font-size: 18px; line-height: 1;
            padding-bottom: 2px; /* optical centering for the Persian glyph */
            color: var(--v-on-primary, #fff);
            background:
                radial-gradient(120% 120% at 30% 12%, rgba(255,255,255,0.38), rgba(255,255,255,0) 58%),
                linear-gradient(145deg, var(--v-primary, #6366f1), var(--v-primary-active, #4f46e5));
            box-shadow:
                0 8px 18px -8px var(--v-primary-glow, rgba(99,102,241,0.9)),
                inset 0 1px 0 rgba(255,255,255,0.4),
                inset 0 -1px 0 rgba(0,0,0,0.22);
            text-shadow: 0 1px 2px rgba(0,0,0,0.28);
        }
        .sidebar-brand-mark::after {
            content: ''; position: absolute; inset: 0; border-radius: inherit;
            border: 1px solid rgba(255,255,255,0.18); pointer-events: none;
        }
        /* When an admin uploads a logo it fills the tile exactly — the box keeps
           its size, the image is cropped/contained by object-fit. */
        .sidebar-brand-mark.has-image { padding: 0; background: var(--v-bg-0, #0f172a); }
        .sidebar-brand-mark.has-image img {
            width: 100%; height: 100%; display: block;
            /* "contain" keeps every pixel of the uploaded logo (a wide logo is
               letterboxed instead of being cropped), a square icon still fills
               the tile edge to edge. */
            object-fit: contain; object-position: center;
            border-radius: inherit;
        }
        .sidebar-brand-texts { display: flex; flex-direction: column; min-width: 0; line-height: 1.25; }
        .sidebar-brand-name { font-family: 'AppBold'; font-size: 14px; color: #f1f5f9; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-brand-sub { font-size: 10px; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-close { width: 34px; height: 34px; min-width: 34px; margin: 0; padding: 0; flex: 0 0 34px; border-radius: 11px; display: flex; align-items: center; justify-content: center; background: rgba(148,163,184,0.10); border: 1px solid rgba(148,163,184,0.18); color: #94a3b8; cursor: pointer; box-shadow: none; transition: background .2s, color .2s, border-color .2s, transform .15s; -webkit-tap-highlight-color: transparent; }
        .sidebar-close:hover { background: rgba(239,68,68,0.16); border-color: rgba(239,68,68,0.4); color: #fca5a5; transform: none; }
        .sidebar-close:active { transform: scale(0.93); }
        .close-menu { display: none !important; }

        @media (min-width: 769px) { 
            body { position: static; flex-direction: row; }
            .sidebar { position: relative; transform: translateX(0); z-index: 10; box-shadow: none; width: 310px; flex-shrink: 0; transition: width 0.4s cubic-bezier(0.2, 0.8, 0.2, 1), transform 0.4s; height: 100%; }
            .sidebar.closed { width: 0; transform: translateX(100%); overflow: hidden; border: none; } 
            .sidebar.closed .sidebar-head { opacity: 0; }
            .backdrop { display: none !important; } 
            .sidebar-close { display: none; }
        }

        @media (max-width: 768px) {
            .search-box { padding: 10px 14px; }
            .search-box .search-input { padding: 11px 14px; font-size: 13px; }
        }
        @media (max-width: 380px) {
            .sidebar { width: 88vw; }
            .sidebar-head { padding: 10px 12px 8px; }
            .sidebar-brand-sub { display: none; }
        }

        .main { flex: 1; display: flex; flex-direction: column; position: relative; background-color: var(--v-bg-0, #0f172a); background-image: radial-gradient(var(--v-dot, #334155) 1px, transparent 1px), radial-gradient(820px 460px at 50% -12%, var(--v-glow-a, rgba(99,102,241,0.2)), transparent 62%); background-size: 24px 24px, 100% 100%; background-position: 0 0, 50% 0; min-width: 0; height: 100%; overflow: hidden; }
        
        .header { height: 60px; background: var(--v-header, rgba(30,41,59,0.92)); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); border-bottom: 1px solid var(--border); display: flex; align-items: center; padding: 0 12px; gap: 8px; justify-content: space-between; z-index: 10; box-shadow: 0 6px 18px -12px rgba(0,0,0,.9); flex-shrink: 0; position: relative; }
        .ham-btn { width: 38px !important; height: 38px !important; min-width: 38px; padding: 0 !important; margin: 0 !important; flex: 0 0 38px; border-radius: 12px !important; background: rgba(148,163,184,0.10) !important; border: 1px solid rgba(148,163,184,0.18) !important; color: #cbd5e1 !important; display: flex; align-items: center; justify-content: center; box-shadow: none !important; transition: background .2s, border-color .2s, color .2s, transform .15s; }
        .ham-btn:hover { background: var(--v-soft, rgba(99,102,241,0.18)) !important; border-color: var(--v-primary-border, rgba(99,102,241,0.4)) !important; color: #fff !important; transform: none; }
        .ham-btn:active { transform: scale(0.94); }
        .header-title { font-family: 'AppBold'; color: #fff; font-size: clamp(13.5px, calc(16px * var(--v-fs, 1)), 19px); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 160px; display: block; }
        .header-status { font-size: 11px; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }
        
        .social-icons { display: flex; gap: 8px; align-items: center; }
        .social-btn { width: 36px; height: 36px; background: rgba(255, 255, 255, 0.08); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 10px; display: flex; justify-content: center; align-items: center; transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1); box-shadow: 0 4px 15px rgba(0,0,0,0.2); text-decoration: none; }
        .social-btn:hover { background: var(--v-soft, rgba(99, 102, 241, 0.2)); border-color: var(--v-primary-border, rgba(99,102,241,0.4)); transform: translateY(-3px); box-shadow: 0 8px 20px var(--v-primary-border, rgba(99, 102, 241, 0.3)); }
        .social-btn img { width: 20px; height: 20px; object-fit: contain; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.3)); }
        /* Block-confirmation sheet: red glow badge + weighted actions, no browser confirm(). */
        .blk-modal {
            max-width: 400px; padding: 26px 24px 22px; text-align: center;
            background: radial-gradient(140% 90% at 50% -20%, rgba(239,68,68,0.16), transparent 55%), var(--v-surface-2, #1e293b);
        }
        .blk-badge {
            width: 58px; height: 58px; margin: 0 auto 14px; border-radius: 18px;
            display: flex; align-items: center; justify-content: center; font-size: 26px;
            background: linear-gradient(150deg, rgba(239,68,68,0.35), rgba(239,68,68,0.10));
            border: 1px solid rgba(239,68,68,0.45);
            box-shadow: 0 0 0 6px rgba(239,68,68,0.08), 0 14px 30px -14px rgba(239,68,68,0.75), inset 0 1px 0 rgba(255,255,255,0.18);
        }
        .blk-title { margin-bottom: 8px; }
        .blk-text { margin: 0 0 18px; font-size: 12.5px; line-height: 2; color: #94a3b8; }
        .blk-text b { color: #fecaca; }
        .blk-actions { display: flex; gap: 9px; }
        .blk-actions .modal-btn { margin-top: 0; }
        .blk-yes {
            flex: 1.3; background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff;
            box-shadow: 0 10px 24px -12px rgba(239,68,68,0.85);
        }
        .blk-yes:hover { filter: brightness(1.07); }
        .blk-actions .btn-gray { flex: 1; background: rgba(148,163,184,0.10); border: 1px solid rgba(148,163,184,0.18); }
        /* Private-chat block banner: sits right above the composer and turns
           the whole row into a clear, one-action state. */
        .block-banner {
            display: flex; align-items: center; gap: 10px;
            margin: 0 12px 8px; padding: 10px 14px; border-radius: 14px;
            background: linear-gradient(140deg, rgba(239,68,68,0.14), rgba(239,68,68,0.05));
            border: 1px solid rgba(239,68,68,0.32); border-right: 3px solid #ef4444;
            color: #fecaca; font-size: 12.5px; direction: rtl;
        }
        .block-banner-ico { font-size: 16px; line-height: 1; flex-shrink: 0; }
        .block-banner-txt { flex: 1; min-width: 0; }
        .block-banner-btn {
            flex-shrink: 0; border: 1px solid rgba(239,68,68,0.45); background: rgba(239,68,68,0.16);
            color: #fecaca; border-radius: 9px; padding: 5px 12px; font-size: 11.5px;
            font-family: inherit; cursor: pointer; transition: background .16s ease, transform .12s ease;
        }
        .block-banner-btn:hover { background: rgba(239,68,68,0.28); transform: translateY(-1px); }
        #pvBlockBtn.is-blocked { filter: drop-shadow(0 0 6px rgba(239,68,68,0.8)); }
        /* 3D social tiles: glossy rounded squares with a brand glow on hover.
           Pure CSS chrome + inline SVG glyphs — no extra image requests. */
        .vx-social {
            width: 32px; height: 32px; border-radius: 10px; position: relative; overflow: hidden;
            display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0;
            background: linear-gradient(160deg, #303c5c 0%, #1a2338 52%, #101827 100%);
            border: 1px solid rgba(255,255,255,0.14);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.24), inset 0 -3px 7px rgba(0,0,0,0.55), 0 6px 14px -7px rgba(0,0,0,0.75);
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        }
        .vx-social::after {
            content: ''; position: absolute; inset: 0 0 52% 0; pointer-events: none;
            background: linear-gradient(180deg, rgba(255,255,255,0.18), rgba(255,255,255,0));
            border-radius: 10px 10px 40% 40%;
        }
        .vx-social svg { width: 18px; height: 18px; position: relative; z-index: 1; }
        .vx-social-github  { color: #f8fafc; }
        .vx-social-telegram{ color: #38bdf8; }
        .vx-social-website { color: #34d399; }
        .vx-social:hover { transform: translateY(-2px); border-color: rgba(255,255,255,0.26); }
        .vx-social-github:hover   { box-shadow: inset 0 1px 0 rgba(255,255,255,0.3), inset 0 -3px 7px rgba(0,0,0,0.55), 0 10px 18px -8px rgba(248,250,252,0.55); }
        .vx-social-telegram:hover { box-shadow: inset 0 1px 0 rgba(255,255,255,0.3), inset 0 -3px 7px rgba(0,0,0,0.55), 0 10px 18px -8px rgba(56,189,248,0.65); }
        .vx-social-website:hover  { box-shadow: inset 0 1px 0 rgba(255,255,255,0.3), inset 0 -3px 7px rgba(0,0,0,0.55), 0 10px 18px -8px rgba(52,211,153,0.6); }
        .vx-social.is-off { opacity: .42; cursor: default; filter: saturate(.35); }
        .vx-social.is-off:hover { transform: none; }
        @media (max-width: 400px) {
            .social-btn { width: 30px; height: 30px; border-radius: 8px; }
            .social-btn img { width: 16px; height: 16px; }
            .social-icons { gap: 5px; }
            .header-title { font-size: 13px; max-width: 110px; }
            .search-btn { width: 28px; height: 28px; }
        }
        @media (max-width: 320px) {
            .header-title { font-size: 11px; max-width: 80px; }
        }
        @media (max-width: 360px) {
            .input-box { padding-left: 10px; padding-right: 10px; }
            #voiceRec { padding: 0 12px; }
        }

        .search-box { padding: 15px; border-bottom: 1px solid var(--border); background: var(--panel); z-index: 2; flex-shrink: 0; }
        .search-input { width: 100%; background: var(--v-input, #0f172a); border: 1px solid var(--border); padding: 10px; border-radius: 10px; color: #fff; font-size: 13px; transition: 0.3s; }
        .search-input.error { border-color: #ef4444; animation: shake 0.4s; }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        .list-header { padding: 15px 15px 5px; font-size: 12px; color: #94a3b8; font-weight: bold; }
        .list { overflow-y: auto; flex: 1; padding: 5px 10px; min-height: 0; }
        .item { padding: 10px 12px; margin: 4px 0; border-radius: 10px; cursor: pointer; display: flex; flex-direction: column; align-items: flex-start; justify-content: center; color: #cbd5e1; transition: 0.2s; white-space: nowrap; overflow: hidden; gap: 4px; font-size: clamp(13.5px, calc(16px * var(--v-fs, 1)), 19px); }
        .item-row { display: flex; align-items: center; justify-content: space-between; width: 100%; }
        .item:hover { background: rgba(255,255,255,0.05); color: #fff; }
        .item.active { background: var(--v-soft, rgba(99, 102, 241, 0.15)); color: #fff; border: 1px solid var(--v-primary-border, rgba(99, 102, 241, 0.3)); font-family: 'AppBold'; }
        
        .unread-badge {
            min-width: 21px; height: 21px; padding: 0 6px;
            border-radius: 999px;
            display: flex; align-items: center; justify-content: center;
            background: linear-gradient(180deg, #f05252, #dc2626);
            color: #fff; font-size: 11px; font-weight: bold; line-height: 1;
            font-variant-numeric: tabular-nums; letter-spacing: .2px;
            box-shadow: 0 3px 9px -3px rgba(239,68,68,0.75), inset 0 1px 0 rgba(255,255,255,0.22);
            flex-shrink: 0;
        }
        
        .messages { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 8px; padding-bottom: 20px; overscroll-behavior: contain; -webkit-overflow-scrolling: touch; overflow-anchor: auto; scroll-behavior: auto; -webkit-transform: translateZ(0); transform: translateZ(0); }
        .sidebar-category { display:flex; align-items:center; justify-content:space-between; gap:8px; margin:4px 8px; padding:9px 10px; border-radius:12px; cursor:pointer; user-select:none; border:1px solid transparent; transition: background .18s ease, border-color .18s ease; -webkit-tap-highlight-color: transparent; }
        .sidebar-category:hover { background: rgba(148,163,184,0.07); border-color: rgba(148,163,184,0.12); }
        .sidebar-category:active { background: rgba(148,163,184,0.11); }
        .sidebar-category-title { display:flex; align-items:center; gap:7px; font-size:11px; font-weight:bold; color:#94a3b8; letter-spacing:0.3px; min-width:0; }
        .sidebar-category-title .i3d { flex:0 0 auto; }
        .sidebar-category:hover .sidebar-category-title { color:#cbd5e1; }
        .sidebar-category-arrow { display:flex; align-items:center; justify-content:center; width:22px; height:22px; flex:0 0 22px; border-radius:8px; color:#94a3b8; background: rgba(148,163,184,0.08); transition: transform .3s cubic-bezier(.2,.8,.2,1), background .2s ease, color .2s ease; }
        .sidebar-category:hover .sidebar-category-arrow { background: rgba(148,163,184,0.16); color:#e2e8f0; }
        .sidebar-category-arrow.collapsed { transform: rotate(-90deg); }
        .sidebar-category-body { overflow:hidden; transition:max-height .34s cubic-bezier(.2,.8,.2,1), opacity .24s ease; opacity:1; }
        .sidebar-category-body.collapsed { opacity:0; }
        @media (max-width: 480px) {
            .sidebar-category { padding:8px; margin:3px 6px; }
            .sidebar-category-arrow { width:20px; height:20px; flex-basis:20px; }
        }
        
        .msg { width: fit-content; max-width: 80%; padding: 11px 15px 10px; border-radius: 20px; position: relative; font-size: clamp(12.5px, calc(14px * var(--v-fs, 1)), 18.5px); line-height: 1.7; word-wrap: break-word; overflow-wrap: break-word; word-break: break-word; box-shadow: 0 6px 18px -10px rgba(0,0,0,0.75); transition: transform 0.14s ease, box-shadow .2s ease; -webkit-tap-highlight-color: transparent !important; user-select: none; -webkit-user-select: none; cursor: default; -webkit-touch-callout: none; }
        .msg:hover { box-shadow: 0 12px 26px -12px rgba(0,0,0,0.85); }

        .msg-row { display: flex; align-items: flex-end; gap: 6px; width: 100%; direction: ltr; margin-bottom: 12px; }
        /* Assistant typing row: comfortable breathing room, also against the pinned bar above */
        .msg-row.ai-wait-row { margin-top: 10px; margin-bottom: 16px; align-items: center; direction: rtl; justify-content: flex-start; }
        .msg-row.ai-wait-row .ai-typing { background: var(--v-primary-soft, rgba(99,102,241,0.10)); border: 1px dashed var(--v-primary-border, rgba(99,102,241,0.35)); border-radius: 14px; padding: 9px 14px; animation: mTitleUp .3s cubic-bezier(.2,.8,.2,1) backwards; }
        .msg-row.me { justify-content: flex-end; }
        .msg-row.other { justify-content: flex-start; }
        .msg-row .msg.me { direction: rtl; }
        .msg-row .msg.other { direction: rtl; }

        /* Out of flow on purpose: the rail hangs beside the bubble, so a row is
           exactly as tall as its message. Keeping it in the flex flow made
           every row 124px tall (four 28px buttons) and grew the row the moment
           the server confirmed a send — which is what pushed fresh messages
           below the fold. */
        .msg-actions { position: absolute; bottom: 0; z-index: 3; display: flex; flex-direction: column; gap: 4px; opacity: 0; visibility: hidden; pointer-events: none; transition: opacity 0.18s, visibility 0.18s; flex-shrink: 0; width: 28px; }
        .msg.me > .msg-actions { inset-inline-end: calc(100% + 6px); }
        .msg.other > .msg-actions { inset-inline-start: calc(100% + 6px); }
        .msg-actions-ghost { opacity: 0 !important; visibility: hidden !important; pointer-events: none; }
        .msg-row:hover .msg-actions { opacity: 1; visibility: visible; pointer-events: auto; }
        .msg-actions.touch-visible { opacity: 1; visibility: visible; pointer-events: auto; }
        .msg-actions.hover-visible { opacity: 1; visibility: visible; pointer-events: auto; }
        .msg-act-btn { width: 30px; height: 30px; border-radius: 50%; background: linear-gradient(160deg, rgba(51,65,85,0.98), rgba(15,23,42,0.98)); border: 1px solid rgba(255,255,255,0.14); color: #cbd5e1; cursor: pointer; display: flex; justify-content: center; align-items: center; font-size: 13px; transition: background 0.15s, color 0.15s, transform .15s, box-shadow .2s; -webkit-tap-highlight-color: transparent; flex-shrink: 0; box-shadow: 0 6px 14px -8px rgba(0,0,0,0.9), inset 0 1px 0 rgba(255,255,255,0.10); }
        .msg-act-btn:hover { background: linear-gradient(160deg, #7C8CF8, #4F46E5); color: #fff; border-color: rgba(165,180,252,0.7); transform: translateY(-1px); box-shadow: 0 10px 18px -8px rgba(79,70,229,0.9), inset 0 1px 0 rgba(255,255,255,0.28); }
        .msg-act-btn.del-btn:hover { background: linear-gradient(160deg, #F87171, #DC2626); border-color: rgba(252,165,165,0.7); box-shadow: 0 10px 18px -8px rgba(220,38,38,0.9), inset 0 1px 0 rgba(255,255,255,0.28); }
        
        /* One surface per bubble: a soft top-light gradient over the theme
           colour, an asymmetric corner as the "tail", and a hairline inner
           highlight instead of the old nested glass box. */
        .msg.me {
            align-self: flex-end; margin-right: auto; margin-left: 0;
            color: #FFFFFF;
            background:
                radial-gradient(120% 120% at 100% 0%, rgba(255,255,255,0.20), rgba(255,255,255,0) 52%),
                linear-gradient(165deg, rgba(255,255,255,0.10), rgba(255,255,255,0) 60%),
                var(--msg-me);
            border: 1px solid rgba(255,255,255,0.16);
            border-bottom-left-radius: 7px; border-bottom-right-radius: 20px;
            box-shadow: 0 10px 24px -14px rgba(0,0,0,0.9), inset 0 1px 0 rgba(255,255,255,0.20);
        }
        .msg.other {
            align-self: flex-start; margin-left: auto; margin-right: 0;
            color: #E8EEFA;
            background:
                linear-gradient(158deg, rgba(255,255,255,0.075), rgba(255,255,255,0) 60%),
                var(--msg-other);
            border: 1px solid rgba(255,255,255,0.075);
            border-bottom-right-radius: 7px; border-bottom-left-radius: 20px;
            box-shadow: 0 10px 24px -15px rgba(0,0,0,0.85), inset 0 1px 0 rgba(255,255,255,0.08);
        }
        /* A hairline of accent on the reader's own bubbles: it separates two
           adjacent "me" messages without adding a second surface. */
        .msg.me::before {
            content: ''; position: absolute; inset: 0; border-radius: inherit;
            border-top: 1px solid rgba(255,255,255,0.22);
            pointer-events: none;
        }
        
        .msg.system { align-self: center; background: rgba(255, 255, 255, 0.1); color: #94a3b8; font-size: 12px; padding: 5px 12px; border-radius: 20px; box-shadow: none; border: 1px solid rgba(255,255,255,0.1); margin: 10px 0; text-align: center; max-width: 90%; }

        /* The text itself no longer sits in a second glass box — that stacked
           surface was what made the old bubble look dated and muddy. The font
           family and weight come from the admin's message-font settings. */
        .text-glass-box {
            background: transparent;
            border: 0;
            padding: 0;
            border-radius: 0;
            margin-top: 5px;
            display: block;
            user-select: text;
            -webkit-user-select: text;
            line-height: 1.75;
            box-shadow: none;
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
            word-wrap: break-word;
            overflow-wrap: break-word;
            font-family: var(--msg-font, inherit);
            font-weight: var(--msg-weight, normal);
        }
        /* AI replies keep a tinted plate so they read as a different voice. */
        .msg .ai-bubble {
            background: rgba(99, 102, 241, 0.10);
            border-radius: 12px;
            padding: 9px 12px;
        }

        .msg.pinned-highlight { box-shadow: 0 0 0 1.5px rgba(245,158,11,0.55), 0 4px 16px rgba(245,158,11,0.16) !important; border-radius: 18px; }
        .msg.pending { opacity: 0.82; animation: vMsgIn .12s ease-out; }
        .msg.error { border: 1px solid #ef4444; }
        @keyframes vMsgIn { from { opacity: 0; } to { opacity: 0.82; } }
        
        .msg.highlight-target { animation: replyPulse 2.5s ease-out forwards; z-index: 10; }
        @keyframes replyPulse {
            0%   { box-shadow: 0 0 0 3px var(--v-accent, #a5b4fc), 0 0 24px rgba(129,140,248,0.6); outline: 2px solid var(--v-accent, #a5b4fc); }
            40%  { box-shadow: 0 0 0 4px var(--v-accent, #a5b4fc), 0 0 32px rgba(165,180,252,0.5); }
            100% { box-shadow: 0 3px 8px rgba(0,0,0,0.15); outline: 2px solid transparent; }
        }

        .msg-row.replied-to-me > .msg.me,
        .msg-row.replied-to-me > .msg.other {
            box-shadow: 0 0 0 2px rgba(99,102,241,0.7), 0 6px 24px var(--v-active-soft, rgba(99,102,241,0.35));
            border-radius: 18px;
        }
        .msg-row.replied-to-me > .msg.me {
            background: linear-gradient(135deg, var(--v-primary-border, rgba(99,102,241,0.25)) 0%, var(--msg-me) 40%);
        }
        .msg-row.replied-to-me > .msg.other {
            background: linear-gradient(135deg, var(--v-soft, rgba(99,102,241,0.18)) 0%, var(--msg-other) 40%);
        }
        .msg-row.replied-to-me .reply-to-me-badge {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            color: #c4b5fd;
            margin-bottom: 5px;
            font-family: 'AppBold';
            background: var(--v-soft, rgba(99,102,241,0.18));
            padding: 4px 10px;
            border-radius: 8px;
            border: 1px solid var(--v-primary-border, rgba(99,102,241,0.3));
        }
        .reply-to-me-badge svg { width:13px; height:13px; fill:#c4b5fd; flex-shrink:0; }

        .sender { font-size: clamp(10.5px, calc(11.5px * var(--v-fs, 1)), 15px); margin-bottom: 7px; font-family: 'AppBold'; display: flex; align-items: center; gap: 6px; font-weight: 700; letter-spacing: .1px; color: rgba(255,255,255,0.86); }
        .msg.other .sender { color: #c7d2fe; }
        .msg-sticker { font-size: 1.05em; line-height: 1; filter: drop-shadow(0 1px 2px rgba(0,0,0,0.45)); }
        .msg-sender-name { max-width: 58%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .msg-time-chip {
            margin-inline-start: auto; display: inline-flex; align-items: center; gap: 4px;
            font-family: 'AppFont', sans-serif; font-weight: 600; font-size: 10px;
            letter-spacing: .4px; padding: 3px 8px; border-radius: 999px;
            background: rgba(2,6,23,0.30); border: 1px solid rgba(255,255,255,0.11);
            color: rgba(255,255,255,0.80);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.07);
            white-space: nowrap;
        }
        .msg.other .msg-time-chip { background: rgba(2,6,23,0.26); color: #b9c5d8; }
        .msg-time-chip .msg-tick { margin: 0; }
        .msg-link { color: var(--v-accent, #a5b4fc); text-decoration: underline; text-underline-offset: 2px; word-break: break-word; overflow-wrap: anywhere; direction: ltr; unicode-bidi: isolate; display: inline-block; max-width: 100%; vertical-align: bottom; }
        .msg-link:hover { color: #fff; }
        .msg.me .msg-link { color: #FFFFFF; text-decoration-color: rgba(255,255,255,0.62); }
        .msg.other .msg-link { color: #B9C6FF; text-decoration-color: rgba(185,198,255,0.55); }
        .chat-text { margin-top: 5px; display: block; user-select: text; -webkit-user-select: text; }
        .retry-btn { font-size: 11px; color: #ef4444; cursor: pointer; font-weight: bold; margin-top: 5px; display: block; text-align: right; }

        .msg-tick { display: inline-flex; align-items: center; margin-right: 6px; flex-shrink: 0; vertical-align: middle; }
        .msg-tick svg { width: 18px; height: 12px; }
        .tick-sent .msg-tick { color: rgba(255,255,255,0.45); }
        .tick-seen .msg-tick { color: #60a5fa; }
        .tick-sent .msg-tick svg path { fill: rgba(255,255,255,0.45); }
        .tick-seen .msg-tick svg path { fill: #60a5fa; }

        .emoji-only { font-size: 48px; line-height: 1.15; display: block; padding: 4px 0; background: none; border: none; box-shadow: none; }
        .emoji-only-sm { font-size: 36px; line-height: 1.2; display: block; padding: 2px 0; background: none; border: none; box-shadow: none; }
        .emoji-large { font-size: 42px; line-height: 1.2; display: block; padding: 4px 2px; }
        .emoji-mix { font-size: 28px; line-height: 1.4; }
        .emoji-inline { font-size: 1.3em; vertical-align: middle; line-height: 1; }

        .proxy-box {
            margin-top: 11px; padding: 11px 13px;
            display: flex; align-items: center; gap: 11px; flex-wrap: wrap;
            border-radius: 14px;
            background:
                linear-gradient(150deg, rgba(129,140,248,0.14), rgba(129,140,248,0.04) 55%, transparent),
                rgba(2,6,23,0.42);
            border: 1px solid rgba(129,140,248,0.26);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.07), 0 6px 18px -12px rgba(0,0,0,0.8);
        }
        .proxy-box-icon {
            flex: 0 0 38px; width: 38px; height: 38px; border-radius: 12px;
            display: grid; place-items: center;
            background: rgba(129,140,248,0.13);
            border: 1px solid rgba(129,140,248,0.24);
        }
        .proxy-box-icon img { width: 23px; height: 23px; object-fit: contain; filter: drop-shadow(0 3px 5px rgba(0,0,0,0.45)); }
        .proxy-box-info { flex: 1 1 120px; min-width: 0; text-align: right; }
        .proxy-box-label { font-size: 10.5px; font-weight: 800; letter-spacing: .6px; color: #a5b4fc; text-transform: uppercase; }
        .proxy-box-count { font-size: 12.5px; color: #dbe4f3; margin-top: 3px; line-height: 1.5; }
        .proxy-box-count b { color: #fff; font-size: 13.5px; }
        .proxy-box-btn {
            flex: 0 0 auto; display: inline-flex; align-items: center; gap: 7px;
            height: 38px; padding: 0 14px; border-radius: 11px; cursor: pointer;
            font-family: 'AppBold', 'AppFont', sans-serif; font-size: 12px; color: #eef2ff;
            background: linear-gradient(135deg, rgba(99,102,241,0.85), rgba(79,70,229,0.85));
            border: 1px solid rgba(165,180,252,0.45);
            box-shadow: 0 10px 22px -14px rgba(99,102,241,0.95), inset 0 1px 0 rgba(255,255,255,0.22);
            transition: transform .15s, filter .2s, box-shadow .2s;
            white-space: nowrap;
        }
        .proxy-box-btn:hover { filter: brightness(1.09); transform: translateY(-1px); box-shadow: 0 14px 26px -14px rgba(99,102,241,1), inset 0 1px 0 rgba(255,255,255,0.3); }
        .proxy-box-btn:active { transform: translateY(0) scale(.985); }
        .proxy-box-btn .i3d { width: 16px; height: 16px; filter: drop-shadow(0 2px 3px rgba(0,0,0,0.4)); }
        @media (max-width: 380px) {
            .proxy-box { padding: 10px; gap: 9px; }
            .proxy-box-btn { width: 100%; justify-content: center; }
        }
        .media-container { max-width: 100%; border-radius: 12px; overflow: hidden; margin-top: 5px; background: rgba(0,0,0,0.2); cursor: zoom-in; }
        .media-container.video-wrap { position: relative; cursor: pointer; min-height: 120px; background: var(--v-bg-2, #0f172a); }
        .video-play-overlay { position: absolute; inset: 0; display: flex; justify-content: center; align-items: center; background: radial-gradient(circle at 50% 46%, rgba(0,0,0,0.06) 0%, rgba(0,0,0,0.30) 62%, rgba(0,0,0,0.46) 100%); border-radius: 12px; pointer-events: none; z-index: 2; }
        .video-play-3d { width: 64px; height: 64px; object-fit: contain; user-select: none; -webkit-user-drag: none; filter: drop-shadow(0 7px 16px rgba(0,0,0,0.55)) drop-shadow(0 1px 0 rgba(255,255,255,0.22)); transition: transform .18s cubic-bezier(.2,.8,.3,1), filter .18s ease; }
        .video-wrap:hover .video-play-3d { transform: scale(1.09); filter: drop-shadow(0 9px 20px rgba(0,0,0,0.62)) drop-shadow(0 1px 0 rgba(255,255,255,0.3)); }
        .video-wrap:active .video-play-3d { transform: scale(0.97); }
        @media (max-width: 560px) { .video-play-3d { width: 52px; height: 52px; } }
        .video-thumb { width: 100%; max-height: 300px; object-fit: cover; display: block; border-radius: 12px; }
        .chat-media { width: auto; max-width: 100%; max-height: 300px; object-fit: contain; display: block; }

        /* ── Deleted media placeholder ───────────────────────────────────────
           The admin "delete media" action keeps the chat row so the thread
           keeps its shape. This block stands in for the removed blob, keeps
           roughly the same footprint the media used to take, and stays legible
           on both the outgoing (accent) and incoming (dark) bubble. */
        .media-gone {
            position: relative; display: flex; align-items: center; gap: 11px;
            width: 100%; box-sizing: border-box; padding: 14px 15px;
            border-radius: 14px; overflow: hidden;
            border: 1px dashed rgba(255,255,255,0.30);
            background: linear-gradient(135deg, rgba(8,15,30,0.34), rgba(8,15,30,0.18));
            color: #eef2f8;
            text-align: right; cursor: default; user-select: none;
            animation: mediaGoneIn .3s cubic-bezier(.2,.8,.3,1) both;
        }
        @keyframes mediaGoneIn { from { opacity: 0; transform: translateY(3px) scale(.985); } to { opacity: 1; transform: none; } }
        .media-gone::before {
            content: ""; position: absolute; inset: 0; pointer-events: none;
            background-image: repeating-linear-gradient(-45deg,
                rgba(255,255,255,0.045) 0 1px, transparent 1px 10px);
        }
        .media-gone-ico {
            position: relative; flex: 0 0 auto; width: 42px; height: 42px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 13px;
            background: linear-gradient(150deg, rgba(255,255,255,0.20), rgba(255,255,255,0.06));
            border: 1px solid rgba(255,255,255,0.24);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.18), 0 3px 8px rgba(0,0,0,0.20);
        }
        /* Tiny trash badge on the icon corner: reads as "this media is gone"
           without spending a whole line of the narrow bubble on a text tag. */
        .media-gone-ico::after {
            content: ""; position: absolute; inset-inline-start: -5px; bottom: -5px;
            width: 18px; height: 18px; border-radius: 7px;
            background: #0f172a url("assets/icons3d/trash.png") center/11px 11px no-repeat;
            border: 1px solid rgba(255,255,255,0.22);
            box-shadow: 0 2px 5px rgba(0,0,0,0.35);
        }
        .media-gone-img { width: 23px; height: 23px; object-fit: contain; opacity: .88; filter: grayscale(.3) brightness(1.06); }
        .media-gone-body { position: relative; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
        .media-gone-title { font-size: 13.5px; font-weight: 700; letter-spacing: .1px; color: #f8fafc; }
        .media-gone-sub { font-size: 11px; color: #dbe4f0; opacity: .78; line-height: 1.55; }
        /* Footprints per media family, so the bubble does not jump in height. */
        .media-gone--video, .media-gone--image {
            min-height: 150px; min-width: 216px;
            flex-direction: column; justify-content: center;
            gap: 10px; padding: 16px 12px; text-align: center;
        }
        .media-gone--video .media-gone-body, .media-gone--image .media-gone-body { align-items: center; }
        .media-gone--video .media-gone-ico, .media-gone--image .media-gone-ico { width: 46px; height: 46px; }
        .media-gone--voice { min-height: 58px; min-width: 196px; }
        .media-gone--file  { min-height: 62px; min-width: 196px; }
        @media (max-width: 560px) {
            .media-gone--video, .media-gone--image { min-height: 126px; gap: 8px; }
            .media-gone { padding: 12px; gap: 9px; }
            .media-gone-ico { width: 36px; height: 36px; }
            .media-gone--video .media-gone-ico, .media-gone--image .media-gone-ico { width: 40px; height: 40px; }
            .media-gone-title { font-size: 12.5px; }
        }
        /* Incoming bubbles are already dark, so the overlay turns lighter. */
        .msg.other .media-gone {
            background: linear-gradient(135deg, rgba(255,255,255,0.075), rgba(255,255,255,0.025));
            border-color: rgba(148,163,184,0.34);
        }
        .msg.other .media-gone-ico { background: linear-gradient(150deg, rgba(255,255,255,0.12), rgba(255,255,255,0.04)); border-color: rgba(255,255,255,0.16); }
        audio { width: 260px; max-width: 100%; height: 40px; border-radius: 20px; margin-top: 5px; display: block; outline: none; }
        .vplayer { display:flex; align-items:center; gap:10px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1); border-radius:24px; padding:7px 14px 7px 9px; margin-top:5px; max-width:300px; min-width:210px; transition:background .18s, border-color .18s; }
        .vplayer:hover { background:rgba(255,255,255,0.09); border-color:rgba(255,255,255,0.16); }
        .vplay { width:38px;height:38px;border-radius:50%;background:transparent;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:transform .2s;padding:0; }
        .vplay:hover { transform:scale(1.08); }
        .vplay:active { transform:scale(0.95); }
        .vplay img.vp-ico { width:34px; height:34px; object-fit:contain; display:block; pointer-events:none; filter:drop-shadow(0 2px 6px rgba(0,0,0,0.35)); }
        .vplay img.vp-pause { display: none; }
        .vplayer.is-playing .vplay img.vp-play { display: none; }
        .vplayer.is-playing .vplay img.vp-pause { display: block; }
        .vbody { flex:1; min-width:0; }
        .vbar { height:6px; background:rgba(148,163,184,0.26); border-radius:6px; cursor:pointer; position:relative; margin:6px 0 5px; touch-action:none; transition:background .18s; }
        .vplayer:hover .vbar { background:rgba(148,163,184,0.36); }
        .vbar-fill { position:absolute; inset-inline-start:0; top:0; bottom:0; background:linear-gradient(90deg,#a5b4fc,#6366f1); border-radius:6px; width:0%; box-shadow:0 0 10px rgba(99,102,241,0.35); }
        .vbar-knob { position:absolute; top:50%; transform:translate(-50%,-50%); inset-inline-start:0%; width:11px;height:11px;border-radius:50%;background:#fff; box-shadow:0 1px 4px rgba(0,0,0,0.45); opacity:0; transition:opacity .15s; }
        .vplayer:hover .vbar-knob { opacity:1; }
        .vtime { display:flex; justify-content:space-between; align-items:center; font-size:10.5px; color:#8ea0b8; font-family:ui-monospace, monospace; direction:ltr; font-variant-numeric:tabular-nums; letter-spacing:.3px; }
        .vtime .vcur { color:#c7d2fe; font-weight:bold; }
        .vloading { width:15px;height:15px;border:2px solid rgba(255,255,255,0.25); border-top-color:#fff; border-radius:50%; display:inline-block; animation:vspin 0.8s linear infinite; }
        @keyframes vspin { 100% { transform:rotate(360deg); } }
        .vplayer.verr { border-color:rgba(239,68,68,0.45); }
        .vplayer.verr .vdur { color:#f87171; }
        .chat-img-wrap { position:relative; min-height:130px; display:flex; align-items:center; justify-content:center; background:rgba(255,255,255,0.03); border-radius:12px; overflow:hidden; }
        .img-loading-state { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; flex-direction:column; gap:8px; color:#94a3b8; font-size:11px; pointer-events:none; }
        .img-loading-state .vloading { width:22px; height:22px; border-width:3px; }
        .img-up-overlay { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:9px; background:rgba(2,6,23,0.32); backdrop-filter:blur(1px); pointer-events:none; }
        /* Media is uploaded completely before it is published, so the bubble
           carries its own state line: uploading → uploaded. */
        .media-up-badge { display:flex; align-items:center; gap:8px; margin-top:6px; padding:7px 11px; border-radius:11px; font-size:11.5px; line-height:1; background:rgba(15,23,42,0.74); border:1px solid rgba(148,163,184,0.22); color:#cbd5e1; }
        .media-up-badge .media-up-bar { flex:1; min-width:64px; height:4px; border-radius:3px; background:rgba(148,163,184,0.22); overflow:hidden; }
        .media-up-badge .media-up-bar > i { display:block; height:100%; width:0%; border-radius:3px; background:linear-gradient(90deg,#38bdf8,#818cf8); transition:width .18s linear; }
        .media-up-badge .media-up-pct { font-variant-numeric:tabular-nums; opacity:0.85; min-width:34px; text-align:left; }
        .media-up-badge.is-done { color:#86efac; border-color:rgba(34,197,94,0.38); background:rgba(6,78,59,0.42); }
        .media-up-badge.is-done .media-up-bar > i { background:linear-gradient(90deg,#34d399,#10b981); }
        .media-up-badge.is-fail { color:#fca5a5; border-color:rgba(239,68,68,0.4); background:rgba(69,10,10,0.45); }
        .img-up-spinner { width:26px; height:26px; border-radius:50%; border:3px solid rgba(255,255,255,0.22); border-top-color:#fff; animation:spin 0.9s linear infinite; }
        .img-up-label { max-width:90%; padding:4px 11px; border-radius:999px; background:rgba(15,23,42,0.78); border:1px solid rgba(148,163,184,0.25); color:#e2e8f0; font-size:11px; line-height:1.6; text-align:center; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .img-up-lock { position:absolute; bottom:8px; right:8px; width:26px; height:26px; border-radius:9px; background:rgba(15,23,42,0.72); border:1px solid rgba(148,163,184,0.28); display:flex; align-items:center; justify-content:center; backdrop-filter:blur(4px); }
        .img-up-lock img { width:16px; height:16px; object-fit:contain; display:block; }
        @media (max-width: 480px) {
            .img-up-spinner { width:22px; height:22px; border-width:2.5px; }
            .img-up-label { font-size:10px; padding:3px 9px; }
        }
        .img-error-state { position:absolute; inset:0; display:none; align-items:center; justify-content:center; flex-direction:column; gap:6px; color:#f87171; font-size:12px; background:rgba(239,68,68,0.06); cursor:pointer; }
        .img-error-state .retry-img { text-decoration:underline; font-size:11px; color:#93c5fd; }
        #lightboxSpinner { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); width:34px; height:34px; border:3px solid rgba(255,255,255,0.25); border-top-color:var(--v-accent, #a5b4fc); border-radius:50%; animation:vspin 0.8s linear infinite; display:none; z-index:1; }
        .file-box { display: flex; align-items: center; background: rgba(0,0,0,0.2); border-radius: 12px; padding: 10px; margin-top: 5px; gap: 10px; min-width: 150px; }
        .file-icon { font-size: 24px; }
        /* ── 3D icons in the file box ────────────────────────────────────────
           The sprite in <body> draws them; these rules only size them and add
           the soft contact shadow that makes them sit on the bubble instead of
           floating over it. */
        .file-icon { display: flex; align-items: center; justify-content: center; width: 34px; height: 34px; flex: 0 0 34px; }
        .i3d { display: block; flex: 0 0 auto; overflow: visible; }
        .file-icon .i3d { width: 32px; height: 32px; filter: drop-shadow(0 3px 5px rgba(0,0,0,0.38)); }
        /* The document glyph is one shape; the extension tints its text lines,
           so a PDF, a spreadsheet and a video still read apart at a glance
           without six different bitmaps. */
        .file-icon { color: #8F86AC; }
        .file-icon.t-pdf   { color: #E48A8A; }
        .file-icon.t-arch  { color: #E0B26E; }
        .file-icon.t-doc   { color: #8AA6E4; }
        .file-icon.t-sheet { color: #7FCB9A; }
        .file-icon.t-video { color: #C08FE0; }
        .file-icon.t-image { color: #79C9D4; }
        .file-icon.t-audio { color: #E09AC0; }
        .i3d-btn { width: 19px; height: 19px; filter: drop-shadow(0 2px 3px rgba(0,0,0,0.35)); }
        .file-info { flex: 1; overflow: hidden; }
        .file-name { font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; font-weight: bold; }
        .download-btn { background: linear-gradient(180deg, rgba(255,255,255,0.20), rgba(255,255,255,0.05)); border: 1px solid rgba(255,255,255,0.24); color: #fff; width: 36px; height: 36px; border-radius: 50%; display: flex; justify-content: center; align-items: center; text-decoration: none; transition: 0.2s; font-size: 18px; flex-shrink: 0; box-shadow: inset 0 1px 0 rgba(255,255,255,0.30), 0 4px 10px rgba(0,0,0,0.35); }
        .download-btn:hover { background: linear-gradient(180deg, var(--primary), rgba(0,0,0,0.18)); border-color: var(--primary); transform: translateY(-1px); box-shadow: inset 0 1px 0 rgba(255,255,255,0.35), 0 7px 16px rgba(0,0,0,0.45); }
        .download-btn:active { transform: translateY(1px) scale(0.97); box-shadow: inset 0 2px 6px rgba(0,0,0,0.4); }
        
        /* ── Composer (message input) ─────────────────────────────────────── */
        .input-box { position: relative; background: var(--panel); padding: 9px 12px; border-top: 1px solid var(--border); z-index: 20; flex-shrink: 0; padding-bottom: calc(9px + env(safe-area-inset-bottom)); -webkit-transform: translateZ(0); transform: translateZ(0); }

        /* A send that is still in flight after 400 ms gets a hairline shimmer on
           top of the composer. Fast sends (the normal case, ~60 ms) never show
           it, so it reads as "this one is taking a moment" and not as a flicker. */
        #sendProgress { position: absolute; inset-inline: 0; top: 0; height: 2px; overflow: hidden; opacity: 0; transition: opacity .18s ease; pointer-events: none; z-index: 3; }
        #sendProgress::before { content: ''; position: absolute; top: 0; bottom: 0; width: 45%; background: linear-gradient(90deg, transparent, var(--v-primary, #6366f1), transparent); animation: spSlide 1.05s linear infinite; }
        #sendProgress.on { opacity: 1; box-shadow: 0 0 9px rgba(99,102,241,0.55); }
        @keyframes spSlide { from { inset-inline-start: -45%; } to { inset-inline-start: 100%; } }

        .reply-bar, .file-preview { background: var(--v-input, rgba(15,23,42,0.9)); padding: 8px 12px; border-inline-start: 3px solid var(--primary); margin-bottom: 8px; border-radius: 12px; display: none; justify-content: space-between; align-items: center; font-size: 12px; color: #cbd5e1; }
        /* Assistant-conversation state of the reply bar */
        .reply-bar.ai-mode { border-inline-start: 3px solid var(--v-primary, #6366f1); background: linear-gradient(120deg, var(--v-primary-soft, rgba(99,102,241,0.16)), var(--v-input, rgba(15,23,42,0.9)) 55%); border-color: var(--v-primary-border, rgba(99,102,241,0.45)); box-shadow: 0 6px 22px -12px var(--v-primary-glow, rgba(99,102,241,0.7)); animation: aiBarIn .28s cubic-bezier(.2,.8,.2,1); }
        @keyframes aiBarIn { from { transform: translateY(6px); opacity: 0; } to { transform: none; opacity: 1; } }
        .ai-reply-chip { display: none; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; background: var(--v-primary-soft, rgba(99,102,241,0.18)); border: 1px solid var(--v-primary-border, rgba(99,102,241,0.4)); color: var(--v-accent, #a5b4fc); font-size: 10.5px; white-space: nowrap; flex-shrink: 0; }
        .ai-reply-chip img { width: 13px; height: 13px; object-fit: contain; }
        .reply-bar-label { color: var(--v-accent, #a5b4fc); font-weight: bold; font-size: 11px; }
        .reply-bar.ai-mode .reply-bar-label { color: var(--v-primary, #818cf8); }
        /* Assistant-active banner above the message list */
        .vx-ai-banner { display: flex; align-items: center; gap: 10px; margin: 10px 12px 8px; padding: 9px 14px; border-radius: 16px; background: linear-gradient(120deg, var(--v-primary-soft, rgba(99,102,241,0.16)), rgba(15,23,42,0.55) 70%); border: 1px solid var(--v-primary-border, rgba(99,102,241,0.35)); box-shadow: 0 10px 26px -16px var(--v-primary-glow, rgba(99,102,241,0.8)); overflow: hidden; position: relative; animation: aiBarIn .3s cubic-bezier(.2,.8,.2,1); }
        .vx-ai-banner.below-pin { margin-top: 14px; }
        .vx-ai-banner::after { content: ''; position: absolute; inset: 0; background: linear-gradient(100deg, transparent 30%, rgba(255,255,255,0.06) 50%, transparent 70%); transform: translateX(-100%); animation: vxBannerSheen 5.5s ease-in-out infinite; pointer-events: none; }
        @keyframes vxBannerSheen { 0%, 60% { transform: translateX(-100%); } 90%, 100% { transform: translateX(100%); } }
        .vx-ai-banner-ico { flex: 0 0 36px; width: 36px; height: 36px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: radial-gradient(120% 120% at 30% 15%, rgba(255,255,255,0.16), transparent 60%), var(--v-primary-soft, rgba(99,102,241,0.22)); border: 1px solid var(--v-primary-border, rgba(99,102,241,0.45)); box-shadow: 0 8px 18px -10px var(--v-primary-glow, rgba(99,102,241,0.9)); }
        .vx-ai-banner-ico img { width: 20px; height: 20px; object-fit: contain; }
        .vx-ai-banner-tx { display: flex; flex-direction: column; min-width: 0; text-align: right; }
        .vx-ai-banner-tx b { font-size: 12px; color: #e0e7ff; }
        .vx-ai-banner-tx small { font-size: 11px; color: #94a3b8; line-height: 1.7; }
        .input-row { display: flex; gap: 7px; align-items: flex-end; }

        /* One rounded field holds attach + text + emoji, exactly like Telegram:
           the row reads as a single surface, and only the send button floats
           outside it. */
        .composer-pill {
            position: relative; flex: 1 1 auto; min-width: 0;
            display: flex; align-items: flex-end; gap: 2px;
            padding: 4px;
            background: var(--v-input, #1b2637);
            border: 1px solid var(--v-border-strong, rgba(148,163,184,0.16));
            border-radius: 23px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.035), 0 1px 2px rgba(0,0,0,0.12);
            transition: background .18s ease, border-color .18s ease, box-shadow .18s ease;
        }
        .composer-pill:focus-within {
            background: #1f2b3e;
            border-color: var(--v-primary-border, rgba(99,102,241,0.55));
            box-shadow: 0 0 0 3px var(--v-primary-soft, rgba(99,102,241,0.14)), inset 0 1px 0 rgba(255,255,255,0.04);
        }

        #msgInput {
            flex: 1 1 0%; width: auto; min-width: 0;
            background: transparent; border: 0; color: #e8eef8;
            padding: 8px 6px;
            border-radius: 18px; resize: none;
            height: 36px; max-height: 150px; overflow-y: auto;
            outline: none; font-family: inherit; font-size: clamp(13px, calc(14px * var(--v-fs, 1)), 18px); line-height: 20px;
            box-shadow: none; transition: height .12s ease;
            user-select: text !important; -webkit-user-select: text !important; -webkit-touch-callout: default !important;
            scrollbar-width: thin; scrollbar-color: rgba(148,163,184,0.35) transparent;
        }
        #msgInput::placeholder { color: #7b8ba3; }
        #msgInput::-webkit-scrollbar { width: 5px; }
        #msgInput::-webkit-scrollbar-thumb { background: rgba(148,163,184,0.3); border-radius: 5px; }

        /* Round icon buttons that live inside the field. 36px keeps the pill at
           44px tall while still being a comfortable tap target. */
        .composer-emoji, .composer-attach {
            position: static; flex: 0 0 auto; align-self: flex-end;
            width: 36px; height: 36px; margin: 0; padding: 0;
            display: flex; align-items: center; justify-content: center;
            border: 0; background: none; color: #9fb0c7;
            border-radius: 50%; cursor: pointer;
            transition: background .16s ease, transform .12s ease, color .16s ease;
            z-index: 2; -webkit-tap-highlight-color: transparent;
        }
        .composer-emoji img, .composer-attach img { width: 20px; height: 20px; object-fit: contain; display: block; pointer-events: none; }
        .composer-emoji:hover, .composer-attach:hover { background: rgba(148,163,184,0.16); transform: scale(1.06); }
        .composer-emoji:active, .composer-attach:active { transform: scale(0.94); }
        .composer-emoji:focus-visible, .composer-attach:focus-visible { outline: none; background: rgba(148,163,184,0.16); box-shadow: 0 0 0 2px var(--v-primary-border, rgba(99,102,241,0.55)); }
        .i3d { width: 18px; height: 18px; object-fit: contain; display: inline-block; vertical-align: -4px; }
        .i3d-sm { width: 15px; height: 15px; vertical-align: -3px; }
        .i3d-lg { width: 22px; height: 22px; vertical-align: -5px; }
        .rx-3d { width: 17px; height: 17px; object-fit: contain; display: inline-block; vertical-align: -3px; pointer-events: none; }
        .rx-3d-pick { width: 26px; height: 26px; vertical-align: 0; }
        .rx-3d-btn { width: 18px; height: 18px; vertical-align: 0; }
        .msg-act-btn img { width: 15px; height: 15px; object-fit: contain; display: block; pointer-events: none; }
        .btn > .i3d, .btn > .i3d-lg { display: block; }
        @media (max-width: 480px) {
            .i3d { width: 16px; height: 16px; vertical-align: -3px; }
            .i3d-lg { width: 19px; height: 19px; }
            .composer-emoji img { width: 19px; height: 19px; }
        }

        /* Base textarea rule kept for other textareas (e.g. report reason). */
        textarea { flex: 1; background: #334155; border: 1px solid transparent; color: white; padding: 12px 15px; border-radius: 20px; resize: none; height: 46px; max-height: 160px; overflow-y: auto; outline: none; font-size: 14px; transition: height 0.15s, background .2s, border-color .2s; user-select: text !important; -webkit-user-select: text !important; -webkit-touch-callout: default !important; line-height: 1.4; }
        textarea:focus { background: #3b4a5f; border-color: var(--v-primary-border, rgba(99,102,241,0.45)); }
        .btn { width: 46px; height: 46px; border-radius: 50%; border: none; background: #334155; color: #cbd5e1; cursor: pointer; display: flex; justify-content: center; align-items: center; font-size: 20px; transition: 0.2s; flex-shrink: 0; }
        .btn:hover { background: #475569; color: #fff; }
        .btn:active { transform: scale(0.94); }

        /* Composer buttons stay flat and quiet so the pill is the focal point. */
        .input-row .btn { width: 46px; height: 46px; border: 1px solid transparent; background: transparent; color: #9fb0c7; font-size: 19px; transition: background .16s ease, color .16s ease, transform .12s ease; -webkit-tap-highlight-color: transparent; }
        .input-row .btn:hover { background: rgba(148,163,184,0.12); color: #fff; }
        .input-row .btn:active { transform: scale(0.92); }

        /* Telegram behaviour: one round action at the end of the row. Empty box
           shows the mic; any text (or a queued file) swaps it for send. */
        #inputRow:not(.has-text) .btn-send { display: none; }
        #inputRow.has-text #micBtn { display: none; }
        /* Admin locked voice messages → the mic is gone regardless of the swap. */
        #micBtn.is-locked { display: none !important; }
        #inputRow.no-mic:not(.has-text) .btn-send { display: flex; }

        .btn-send { width: 46px; height: 46px; border: 0; border-radius: 50%; background: linear-gradient(135deg, var(--v-primary, #6366f1), var(--v-primary-active, #4f46e5)); color: #fff; box-shadow: 0 8px 18px -10px var(--v-primary-glow, rgba(99,102,241,.9)); font-size: 18px; transition: background .18s ease, color .18s ease, box-shadow .18s ease, transform .12s ease; }
        .input-row .btn-send { width: 46px; height: 46px; background: linear-gradient(135deg, var(--v-primary, #6366f1), var(--v-primary-active, #4f46e5)); color: #fff; animation: cmpSendIn .18s ease-out; }
        @keyframes cmpSendIn { from { transform: scale(.8); opacity: .4; } to { transform: none; opacity: 1; } }
        .input-row .btn-send:hover { background: linear-gradient(135deg, var(--v-primary-hover, #818cf8), var(--v-primary, #6366f1)); color: #fff; box-shadow: 0 10px 22px -10px var(--v-primary-glow, rgba(99,102,241,.95)); }
        /* Empty composer → the button steps back to a quiet grey; typing brings
           the brand gradient back, so the two states are unmistakable. */
        .input-row .btn-send.is-idle { background: rgba(148,163,184,0.14); color: #8fa0b8; box-shadow: none; }
        .btn-send .send-ico { width: 23px; height: 23px; display: block; filter: drop-shadow(0 2px 4px rgba(15,23,42,0.45)); transition: transform .16s ease, opacity .18s ease, filter .18s ease; }
        .btn-send:hover .send-ico { transform: scale(1.06) rotate(-4deg); }
        .input-row .btn-send.is-idle .send-ico { opacity: .55; filter: grayscale(.65) drop-shadow(0 2px 4px rgba(15,23,42,0.3)); }
        .input-row .btn-send.is-idle:hover { background: rgba(148,163,184,0.22); color: #cbd5e1; }
        .input-row .btn-send.is-idle:active { transform: scale(0.94); }
        /* While the request is in flight the plane becomes a soft pulse, so the
           press always feels acknowledged even on a slow link. */
        .btn-send.is-sending { animation: cmpPulse 1s ease-in-out infinite; pointer-events: none; }
        @keyframes cmpPulse { 0%,100% { transform: scale(1); } 50% { transform: scale(.93); } }

        /* Phones get a slightly denser row so the text still has room to breathe
           at 320px without the pill collapsing. */
        @media (max-width: 480px) {
            .input-row { gap: 6px; }
            .composer-pill { padding: 3px; border-radius: 21px; }
            #msgInput { height: 34px; padding: 7px 5px; line-height: 20px; border-radius: 16px; }
            .composer-emoji, .composer-attach { width: 34px; height: 34px; }
            .composer-emoji img, .composer-attach img { width: 18px; height: 18px; }
            .input-row .btn { width: 42px; height: 42px; }
            .input-row .btn-send { width: 42px; height: 42px; }
            .btn-send .send-ico { width: 21px; height: 21px; }
            .voice-rec { height: 42px; border-radius: 21px; }
        }
        .voice-rec { display: none; flex: 1; align-items: center; gap: 10px; background: linear-gradient(135deg, rgba(239,68,68,0.16), rgba(15,23,42,0.55)); height: 46px; border-radius: 23px; padding: 0 7px 0 16px; color: #fecaca; border: 1px solid rgba(239,68,68,0.42); box-shadow: inset 0 1px 0 rgba(255,255,255,0.04); }
        .vr-dot { width: 9px; height: 9px; flex: 0 0 9px; border-radius: 50%; background: #ef4444; animation: vrPulse 1.4s infinite; }
        @keyframes vrPulse { 0% { box-shadow: 0 0 0 0 rgba(239,68,68,0.55); } 70% { box-shadow: 0 0 0 9px rgba(239,68,68,0); } 100% { box-shadow: 0 0 0 0 rgba(239,68,68,0); } }
        .vr-time { font-family: ui-monospace, monospace; font-size: 15px; font-weight: bold; color: #fff; font-variant-numeric: tabular-nums; letter-spacing: .5px; }
        .vr-bars { display: flex; align-items: center; gap: 3px; height: 20px; flex: 0 0 auto; }
        .vr-bars i { width: 3px; border-radius: 2px; background: rgba(254,202,202,0.8); animation: vrBar 0.9s ease-in-out infinite; }
        .vr-bars i:nth-child(1) { height: 7px; animation-delay: 0s; }
        .vr-bars i:nth-child(2) { height: 14px; animation-delay: .12s; }
        .vr-bars i:nth-child(3) { height: 20px; animation-delay: .24s; }
        .vr-bars i:nth-child(4) { height: 12px; animation-delay: .36s; }
        .vr-bars i:nth-child(5) { height: 8px; animation-delay: .48s; }
        @keyframes vrBar { 0%, 100% { transform: scaleY(0.45); opacity: .7; } 50% { transform: scaleY(1); opacity: 1; } }
        .vr-hint { font-size: 10.5px; color: #fca5a5; opacity: .9; white-space: nowrap; margin-inline-start: auto; }
        .vr-btn { width: 34px; height: 34px; flex: 0 0 34px; border-radius: 50%; border: 0; display: flex; align-items: center; justify-content: center; cursor: pointer; padding: 0; transition: transform .16s ease, background .18s ease, color .18s ease; }
        .vr-btn:active { transform: scale(0.92); }
        .vr-cancel { background: rgba(255,255,255,0.08); color: #fca5a5; }
        .vr-cancel:hover { background: rgba(239,68,68,0.25); color: #fff; }
        .vr-send { display: none; background: #fff; color: #ef4444; box-shadow: 0 3px 12px rgba(239,68,68,0.35); }
        .vr-send:hover { transform: scale(1.06); }
        @media (max-width: 420px) {
            .vr-hint { display: none; }
            .voice-rec { gap: 8px; padding: 0 6px 0 12px; }
            .vr-bars { display: none; }
        }

        /* Inspect mode (?inspect=1): free right-click + selectable bubbles. */
        html.v-inspect .msg, html.v-inspect .reply-content, html.v-inspect .msg-text { user-select: text !important; -webkit-user-select: text !important; cursor: text; }
        #inspectBadge {
            position: fixed; z-index: 99999; top: 62px; left: 14px;
            display: flex; align-items: center; gap: 8px;
            background: rgba(15,23,42,0.94); color: #c7d2fe;
            border: 1px solid var(--v-primary-border, rgba(99,102,241,0.45));
            border-radius: 12px; padding: 7px 12px;
            font-family: inherit; font-size: 11px; cursor: pointer;
            box-shadow: 0 10px 30px rgba(0,0,0,0.45);
            backdrop-filter: blur(8px);
        }
        #inspectBadge b { color: #f87171; font-size: 13px; line-height: 1; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(239,68,68,0.4); } 100% { box-shadow: 0 0 0 10px rgba(239,68,68,0); } }
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 2000; display: flex; justify-content: center; align-items: center; backdrop-filter: blur(8px); opacity: 0; transition: opacity 0.3s; visibility: hidden; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal { background: radial-gradient(120% 90% at 50% -20%, rgba(255,255,255,0.055), transparent 62%), var(--v-surface-2, #1e293b); padding: 30px; border-radius: 24px; width: 90%; max-width: 380px; text-align: center; border: 1px solid var(--v-border-strong, #334155); box-shadow: 0 30px 70px -22px rgba(0,0,0,0.85), 0 1px 0 rgba(255,255,255,0.06) inset, 0 0 0 1px rgba(0,0,0,0.25); transform: scale(0.92) translateY(10px); transition: transform 0.32s cubic-bezier(0.2,0.8,0.2,1), opacity 0.3s; }
        .modal-overlay.active .modal { transform: none; }
        .modal-title { color: #fff; font-size: 18px; margin-bottom: 10px; font-family: 'AppBold'; }
        .modal-text { color: #94a3b8; font-size: 14px; margin-bottom: 25px; line-height: 1.6; }
        /* Legacy modals (confirm / prompt / access denied / …) get the same 3D
           chrome as the new ones purely through CSS — markup untouched. */
        .modal-icon {
            width: 66px; height: 66px; margin: 0 auto 16px; border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            font-size: 30px; line-height: 1;
            background: radial-gradient(120% 120% at 30% 15%, rgba(255,255,255,0.14), rgba(255,255,255,0) 60%), var(--v-primary-soft, rgba(99,102,241,0.14));
            border: 1px solid var(--v-primary-border, rgba(99,102,241,0.4));
            box-shadow: 0 16px 34px -20px var(--v-primary-glow, rgba(99,102,241,0.9)), inset 0 1px 0 rgba(255,255,255,0.12);
            animation: mIconPop .38s cubic-bezier(.2,.9,.3,1.35) backwards;
        }
        @keyframes mIconPop { from { transform: scale(.5); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .modal-overlay.active .modal-title { animation: mTitleUp .34s cubic-bezier(.2,.8,.2,1) .06s backwards; }
        .modal-overlay.active .modal-text { animation: mTitleUp .34s cubic-bezier(.2,.8,.2,1) .12s backwards; }
        @keyframes mTitleUp { from { transform: translateY(7px); opacity: 0; } to { transform: none; opacity: 1; } }
        .modal-input { width: 100%; background: var(--v-input, #0f172a); border: 1px solid var(--v-border-strong, #334155); color: #fff; padding: 12px; border-radius: 12px; margin: 10px 0; outline: none; text-align: center; font-size: 16px; transition: border-color 0.2s, box-shadow 0.2s, background 0.2s; }
        .modal-input:focus { border-color: var(--v-primary-border, rgba(99,102,241,0.55)); box-shadow: 0 0 0 3px var(--v-primary-soft, rgba(99,102,241,0.16)); }
        .modal-btn { width: 100%; padding: 12px; border-radius: 12px; border: none; font-weight: bold; cursor: pointer; margin-top: 10px; font-family: 'AppBold'; font-size: 15px; transition: transform 0.12s ease, filter 0.18s ease, box-shadow 0.2s ease; }
        .modal-btn:active { transform: scale(0.985); }
        .btn-green { background: #10b981; color: #fff; }
        .btn-red { background: #ef4444; color: #fff; }
        .btn-gray { background: var(--v-surface-2, #334155); color: #cbd5e1; }

        /* ── Shared modal chrome (security code / create room) ─────────────── */
        .modal-icon-badge {
            width: 64px; height: 64px; margin: 0 auto 16px; border-radius: 20px;
            display: flex; align-items: center; justify-content: center; font-size: 28px;
            background: radial-gradient(120% 120% at 30% 15%, rgba(255,255,255,0.14), rgba(255,255,255,0) 60%), var(--v-primary-soft, rgba(99,102,241,0.14));
            border: 1px solid var(--v-primary-border, rgba(99,102,241,0.4));
            box-shadow: 0 16px 34px -20px var(--v-primary-glow, rgba(99,102,241,0.9)), inset 0 1px 0 rgba(255,255,255,0.12);
        }
        .modal-actions { display: flex; gap: 8px; margin-top: 14px; }
        .modal-actions .modal-btn { flex: 1 1 0; margin-top: 0; }
        .btn-primary-soft {
            background: linear-gradient(135deg, var(--v-primary, #6366f1), var(--v-primary-active, #4f46e5));
            color: #fff; box-shadow: 0 10px 24px -14px var(--v-primary-glow, rgba(99,102,241,0.95));
            transition: filter .18s ease, transform .12s ease;
        }
        .btn-primary-soft:hover { filter: brightness(1.08); }
        .btn-primary-soft:active { transform: scale(0.98); }
        .btn-danger-soft { background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.3); color: #f87171; transition: background .18s ease, color .18s ease; }
        .btn-danger-soft:hover { background: rgba(239,68,68,0.2); color: #fecaca; }

        /* ── Security-code modal ───────────────────────────────────────────── */
        /* One hero icon, one code, one warning, two actions. The code itself
           uses the same AppFont as the chat so it never looks like a foreign
           widget pasted into the dialog. */
        .sec-code-modal {
            position: relative; overflow: hidden;
            max-width: 396px; padding: 24px 22px 20px;
            border-color: var(--v-primary-border, rgba(99,102,241,0.45));
            background:
                radial-gradient(120% 82% at 50% -14%, var(--v-primary-soft, rgba(99,102,241,0.26)), transparent 62%),
                linear-gradient(160deg, #202b46, #151d31);
        }
        .scm-hero { position: relative; width: 86px; height: 86px; margin: 0 auto 12px; display: grid; place-items: center; }
        .scm-hero::before {
            content: ''; position: absolute; inset: -8px; border-radius: 50%;
            background: radial-gradient(circle, var(--v-primary-glow, rgba(99,102,241,0.6)), transparent 68%);
            filter: blur(7px); opacity: .5;
        }
        .scm-icon {
            position: relative; width: 62px; height: 62px; object-fit: contain;
            filter: drop-shadow(0 12px 20px rgba(0,0,0,0.5));
            animation: scmFloat 3.6s ease-in-out infinite;
        }
        @keyframes scmFloat { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }
        .scm-title { font-size: 18px; margin: 0 0 6px; }
        .scm-lede { margin: 0 0 15px; font-size: 12.5px; line-height: 1.9; color: #9fb0c7; }

        /* Per-character cells read faster than one long string and make a
           mis-typed code impossible to blame on the font. */
        .scm-code { display: flex; justify-content: center; gap: 6px; margin: 0 0 12px; direction: ltr; }
        .scm-char {
            flex: 1 1 0; min-width: 0; max-width: 40px; height: 47px;
            display: flex; align-items: center; justify-content: center;
            font-family: inherit; font-size: 21px; font-weight: bold;
            color: #fff; font-variant-numeric: tabular-nums;
            border-radius: 12px;
            background: linear-gradient(180deg, rgba(255,255,255,0.10), rgba(255,255,255,0.028));
            border: 1px solid var(--v-primary-border, rgba(99,102,241,0.45));
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.10), 0 10px 20px -16px var(--v-primary-glow, rgba(99,102,241,0.95));
        }
        .scm-code.scm-in .scm-char { animation: scmPop .36s cubic-bezier(.2,.9,.3,1.3) backwards; animation-delay: calc(var(--i, 0) * 32ms); }
        @keyframes scmPop { from { opacity: 0; transform: translateY(7px) scale(.86); } to { opacity: 1; transform: none; } }

        .scm-copy {
            display: flex; align-items: center; justify-content: center; gap: 7px;
            width: 100%; margin: 0 0 13px; padding: 9px 12px;
            border-radius: 12px; border: 1px solid rgba(148,163,184,0.18);
            background: rgba(148,163,184,0.07); color: #cbd5e1;
            font-family: inherit; font-size: 12.5px; cursor: pointer;
            transition: background .18s ease, color .18s ease, border-color .18s ease;
        }
        .scm-copy img { width: 16px; height: 16px; object-fit: contain; pointer-events: none; }
        .scm-copy:hover { background: var(--v-primary-soft, rgba(99,102,241,0.18)); border-color: var(--v-primary-border, rgba(99,102,241,0.5)); color: #fff; }
        .scm-copy.done { background: rgba(16,185,129,0.14); border-color: rgba(16,185,129,0.42); color: #6ee7b7; }

        .scm-note {
            display: flex; align-items: flex-start; gap: 9px; text-align: right;
            padding: 11px 12px; border-radius: 13px;
            background: rgba(245,158,11,0.075); border: 1px solid rgba(245,158,11,0.22);
            font-size: 11.5px; line-height: 1.85; color: #e3c89b;
        }
        .scm-note img { width: 17px; height: 17px; flex: 0 0 17px; margin-top: 1px; object-fit: contain; }

        .btn-ghost { background: rgba(148,163,184,0.10); border: 1px solid rgba(148,163,184,0.18); color: #cbd5e1; transition: background .18s ease, color .18s ease, border-color .18s ease; }
        .btn-ghost:hover { background: rgba(239,68,68,0.14); border-color: rgba(239,68,68,0.35); color: #fca5a5; }
        .btn-ghost.armed { background: rgba(239,68,68,0.18); border-color: rgba(239,68,68,0.45); color: #fecaca; }
        .sec-warn { color: #fca5a5; font-weight: bold; }
        .sec-reset-warning { font-size: 11px; color: #f87171; margin: 9px 0 0; text-align: center; display: none; line-height: 1.7; }

        /* ── Welcome modal (3D) ──────────────────────────────────────────────
           Layered glass card: a glowing orb with orbiting 3D badges, a sheen
           across the top, a soft floor glow under the card and three depth
           cards that lift on hover. Everything is CSS, so it stays crisp on
           any DPR and re-skins itself from the theme colour variables. */
        .welcome-modal {
            position: relative; overflow: hidden;
            max-width: 432px; width: calc(100% - 26px);
            padding: 28px 22px 18px; border-radius: 30px;
            border: 1px solid rgba(255,255,255,0.07);
            background:
                radial-gradient(140% 90% at 50% -18%, var(--v-primary-soft, rgba(99,102,241,0.32)), transparent 62%),
                radial-gradient(90% 70% at 108% 104%, rgba(56,189,248,0.10), transparent 70%),
                linear-gradient(168deg, #1d2842 0%, #141c2e 54%, #0e1524 100%);
            box-shadow:
                0 44px 90px -34px rgba(0,0,0,0.95),
                0 0 0 1px rgba(255,255,255,0.04) inset,
                0 1px 0 rgba(255,255,255,0.10) inset,
                0 -34px 70px -46px var(--v-primary-glow, rgba(99,102,241,0.85)) inset;
            isolation: isolate;
        }
        .welcome-modal::before {
            content: ''; position: absolute; inset: 0 0 auto; height: 190px; pointer-events: none;
            background: radial-gradient(62% 100% at 50% 0%, rgba(255,255,255,0.11), transparent 72%);
        }
        .welcome-modal::after {
            content: ''; position: absolute; left: -18%; right: -18%; bottom: -58px; height: 150px; pointer-events: none;
            background: radial-gradient(50% 100% at 50% 0%, var(--v-primary-glow, rgba(99,102,241,0.42)), transparent 72%);
            filter: blur(26px); opacity: 0.6;
        }
        .welcome-modal > * { position: relative; z-index: 1; }
        /* 1.7.5 refresh: luminous top hairline + travelling sheen + card shine */
        .welcome-modal { --wm-hair: linear-gradient(90deg, transparent, var(--v-primary, #818cf8) 30%, #22d3ee 55%, var(--v-primary, #818cf8) 80%, transparent); }
        .welcome-modal .wm-scene::after {
            content: ''; position: absolute; top: -2px; left: 8%; right: 8%; height: 2px; border-radius: 2px;
            background: var(--wm-hair); background-size: 200% 100%; opacity: .75; filter: blur(.4px);
            animation: wmHairline 6s linear infinite; pointer-events: none;
        }
        @keyframes wmHairline { from { background-position: 200% 0; } to { background-position: -200% 0; } }
        .wm-card { position: relative; overflow: hidden; }
        .wm-card::after { content: ''; position: absolute; inset: 0; background: linear-gradient(105deg, transparent 40%, rgba(255,255,255,0.07) 50%, transparent 60%); transform: translateX(-120%); transition: transform .7s ease; pointer-events: none; }
        .wm-card:hover::after { transform: translateX(120%); }

        .wm-scene { position: relative; width: 156px; height: 128px; margin: 0 auto 2px; display: grid; place-items: center; }
        .wm-orb { position: relative; width: 92px; height: 92px; display: grid; place-items: center; animation: wmFloat 5s ease-in-out infinite; }
        .wm-orb-halo {
            position: absolute; inset: -28px; border-radius: 50%;
            background: radial-gradient(circle, var(--v-primary-glow, rgba(99,102,241,0.78)), transparent 66%);
            filter: blur(15px); opacity: 0.55;
        }
        .wm-orb-ring {
            position: absolute; inset: -7px; border-radius: 50%;
            background: conic-gradient(from 210deg, rgba(255,255,255,0), var(--v-primary, #6366f1), rgba(56,189,248,0.9), rgba(255,255,255,0), var(--v-primary, #6366f1));
            opacity: 0.55; filter: blur(1px); animation: wmSpin 9s linear infinite;
            -webkit-mask: radial-gradient(circle, transparent 57%, #000 60%);
                    mask: radial-gradient(circle, transparent 57%, #000 60%);
        }
        .wm-icon {
            position: relative; width: 66px; height: 66px; object-fit: contain;
            filter: drop-shadow(0 16px 22px rgba(0,0,0,0.62)) drop-shadow(0 0 18px var(--v-primary-glow, rgba(99,102,241,0.55)));
            animation: wmTilt 6s ease-in-out infinite;
        }
        .wm-orbiter {
            position: absolute; width: 38px; height: 38px; border-radius: 14px; display: grid; place-items: center;
            background: linear-gradient(160deg, rgba(255,255,255,0.15), rgba(255,255,255,0.03));
            border: 1px solid rgba(255,255,255,0.14);
            box-shadow: 0 12px 24px -10px rgba(0,0,0,0.95), 0 1px 0 rgba(255,255,255,0.18) inset;
            backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
        }
        .wm-orbiter img { width: 22px; height: 22px; object-fit: contain; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.5)); }
        .wm-o1 { top: 0; right: 4px; animation: wmBob 4.4s ease-in-out infinite; }
        .wm-o2 { bottom: 4px; left: 0; animation: wmBob 5.2s ease-in-out infinite 0.5s; }
        .wm-o3 { bottom: 24px; right: 0; animation: wmBob 4.8s ease-in-out infinite 1s; }

        .wm-title {
            font-size: 21px; margin: 6px 0 8px; letter-spacing: -0.3px; line-height: 1.5;
            background: linear-gradient(180deg, #ffffff, #dbe3ff 62%, #a5b4fc);
            -webkit-background-clip: text; background-clip: text; color: transparent;
            filter: drop-shadow(0 2px 8px rgba(0,0,0,0.55));
        }
        .wm-lede { margin: 0 0 15px; font-size: 12.5px; line-height: 2; color: #9dafc8; }

        .wm-cards { display: flex; flex-direction: column; gap: 9px; margin-bottom: 16px; text-align: right; }
        .wm-card {
            display: flex; align-items: center; gap: 11px;
            padding: 11px 12px; border-radius: 16px;
            background: linear-gradient(160deg, rgba(255,255,255,0.078), rgba(255,255,255,0.018));
            border: 1px solid rgba(255,255,255,0.075);
            box-shadow: 0 12px 24px -18px rgba(0,0,0,0.9), 0 1px 0 rgba(255,255,255,0.06) inset;
            transition: transform 0.3s cubic-bezier(0.2,0.8,0.2,1), box-shadow 0.3s, border-color 0.3s;
        }
        .wm-card:hover {
            transform: perspective(700px) rotateX(3deg) translateY(-2px);
            border-color: var(--v-primary-border, rgba(99,102,241,0.42));
            box-shadow: 0 20px 34px -20px rgba(0,0,0,0.95), 0 0 0 1px var(--v-primary-soft, rgba(99,102,241,0.22)) inset;
        }
        .wm-card-ico {
            flex: 0 0 38px; width: 38px; height: 38px; border-radius: 12px; display: grid; place-items: center;
            background: linear-gradient(160deg, rgba(255,255,255,0.13), rgba(255,255,255,0.02));
            border: 1px solid rgba(255,255,255,0.09);
            box-shadow: 0 8px 16px -10px rgba(0,0,0,0.9), 0 1px 0 rgba(255,255,255,0.14) inset;
        }
        .wm-card-ico img { width: 23px; height: 23px; object-fit: contain; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.45)); }
        .wm-card-tx { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
        .wm-card-tx b { font-size: 12.5px; color: #eaf0fb; }
        .wm-card-tx small { font-size: 11px; color: #8fa1ba; line-height: 1.85; }
        .wm-tag { flex: 0 0 auto; font-size: 10px; font-weight: 700; padding: 4px 8px; border-radius: 999px; white-space: nowrap; }
        .wm-tag-live { color: #6ee7b7; background: rgba(16,185,129,0.14); border: 1px solid rgba(16,185,129,0.32); }
        .wm-tag-soon { color: #fcd34d; background: rgba(245,158,11,0.13); border: 1px solid rgba(245,158,11,0.30); }
        .wm-tag-open { color: #a5b4fc; background: var(--v-primary-soft, rgba(99,102,241,0.16)); border: 1px solid var(--v-primary-border, rgba(99,102,241,0.34)); }

        .wm-cta {
            position: relative; width: 100%; display: flex; align-items: center; justify-content: center; gap: 9px;
            padding: 14px 18px; border: 0; border-radius: 16px; cursor: pointer; overflow: hidden;
            font-family: 'AppBold', 'AppFont', sans-serif; font-size: 14.5px; color: #fff;
            background: linear-gradient(180deg, var(--v-primary, #6366f1), var(--v-primary-active, #4f46e5));
            box-shadow:
                0 16px 28px -14px var(--v-primary-glow, rgba(99,102,241,0.9)),
                0 1px 0 rgba(255,255,255,0.34) inset,
                0 -2px 0 rgba(0,0,0,0.22) inset;
            transition: transform 0.16s ease, box-shadow 0.2s ease, filter 0.2s ease;
        }
        .wm-cta::after {
            content: ''; position: absolute; top: 0; left: -60%; width: 45%; height: 100%;
            background: linear-gradient(100deg, transparent, rgba(255,255,255,0.34), transparent);
            transform: skewX(-18deg); animation: wmSheen 3.6s ease-in-out infinite;
        }
        .wm-cta svg { opacity: 0.9; }
        .wm-cta:hover { filter: brightness(1.07); transform: translateY(-1px); }
        .wm-cta:active { transform: translateY(1px) scale(0.995); filter: brightness(0.98); }
        .wm-foot { margin: 11px 0 0; font-size: 10.5px; color: #64748b; text-align: center; line-height: 1.7; }

        .modal-overlay.active .wm-card { animation: wmCardIn 0.45s cubic-bezier(0.2,0.8,0.2,1) backwards; }
        .modal-overlay.active .wm-card:nth-child(1) { animation-delay: 0.06s; }
        .modal-overlay.active .wm-card:nth-child(2) { animation-delay: 0.13s; }
        .modal-overlay.active .wm-card:nth-child(3) { animation-delay: 0.20s; }
        .modal-overlay.active .wm-cta { animation: wmCardIn 0.45s cubic-bezier(0.2,0.8,0.2,1) 0.27s backwards; }

        @keyframes wmFloat { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-7px); } }
        @keyframes wmTilt { 0%, 100% { transform: rotate(-4deg); } 50% { transform: rotate(4deg); } }
        @keyframes wmBob { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }
        @keyframes wmSpin { to { transform: rotate(360deg); } }
        @keyframes wmSheen { 0%, 58% { left: -60%; } 100% { left: 130%; } }
        @keyframes wmCardIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }

        @media (max-width: 420px) {
            .welcome-modal { padding: 24px 16px 16px; border-radius: 26px; }
            .wm-scene { width: 140px; height: 116px; }
            .wm-orb { width: 82px; height: 82px; }
            .wm-icon { width: 58px; height: 58px; }
            .wm-orbiter { width: 34px; height: 34px; border-radius: 12px; }
            .wm-orbiter img { width: 20px; height: 20px; }
            .wm-title { font-size: 19px; }
            .wm-card-tx small { font-size: 10.5px; }
        }
        /* Short screens (small phones, landscape): a centred flex child taller
           than the viewport loses its top edge, so switch to a scrollable
           top-aligned sheet instead of clipping the title. */
        @media (max-height: 730px) {
            #welcomeModal, #reportUserModal { align-items: flex-start; overflow-y: auto; -webkit-overflow-scrolling: touch; padding: 14px 0 18px; }
            #welcomeModal .welcome-modal, #reportUserModal .rp-modal { margin: 0 auto; }
            .wm-scene { width: 128px; height: 96px; margin-bottom: 0; }
            .wm-orb { width: 72px; height: 72px; }
            .wm-icon { width: 52px; height: 52px; }
            .wm-orb-halo { inset: -20px; }
            .wm-orbiter { width: 32px; height: 32px; border-radius: 11px; }
            .wm-orbiter img { width: 18px; height: 18px; }
            .welcome-modal { padding: 18px 16px 14px; }
            .wm-title { font-size: 18px; margin: 2px 0 6px; }
            .wm-lede { font-size: 11.5px; line-height: 1.85; margin-bottom: 11px; }
            .wm-cards { gap: 7px; margin-bottom: 12px; }
            .wm-card { padding: 9px 10px; }
            .wm-card-tx small { font-size: 10.5px; line-height: 1.75; }
        }
        @media (prefers-reduced-motion: reduce) {
            .wm-orb, .wm-icon, .wm-orbiter, .wm-orb-ring, .wm-cta::after,
            .modal-overlay.active .wm-card, .modal-overlay.active .wm-cta { animation: none !important; }
        }

        /* Create room / channel modal */
        /* ── Create room / channel modal ─────────────────────────────────────
           A compact, self-contained sheet: glowing badge header, a segmented
           tab control, a hint card and a count-aware name field. */
        .cr-modal {
            max-width: 430px; padding: 0; overflow: hidden; text-align: center;
            background: radial-gradient(140% 90% at 50% -20%, rgba(99,102,241,0.16), transparent 55%), var(--v-surface-2, #1e293b);
        }
        .cr-head { padding: 24px 24px 0; }
        .cr-badge {
            width: 58px; height: 58px; margin: 0 auto 12px; border-radius: 18px;
            display: flex; align-items: center; justify-content: center; font-size: 26px;
            background: linear-gradient(150deg, rgba(99,102,241,0.35), rgba(99,102,241,0.10));
            border: 1px solid rgba(99,102,241,0.45);
            box-shadow: 0 0 0 6px rgba(99,102,241,0.08), 0 14px 30px -14px rgba(99,102,241,0.75), inset 0 1px 0 rgba(255,255,255,0.18);
            animation: crBadgeFloat 3.6s ease-in-out infinite;
        }
        @keyframes crBadgeFloat { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-3px); } }
        .cr-title { font-size: 19px; margin-bottom: 4px; }
        .cr-subtitle { color: #7c8aa5; font-size: 12px; margin-bottom: 16px; }
        .cr-tabs {
            display: flex; gap: 5px; margin: 0 20px 14px; padding: 5px;
            background: rgba(8,13,24,0.55); border: 1px solid rgba(148,163,184,0.14);
            border-radius: 16px;
        }
        .cr-tab {
            flex: 1 1 0; min-width: 0; padding: 9px 4px; border: 0; border-radius: 11px;
            background: transparent; color: #8b9bb4; font-size: 11.5px; font-family: inherit;
            cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            display: flex; align-items: center; justify-content: center; gap: 4px;
            transition: background .2s ease, color .2s ease, box-shadow .2s ease, transform .12s ease;
        }
        .cr-tab-ico { font-size: 13px; line-height: 1; }
        .cr-tab:hover { background: rgba(148,163,184,0.10); color: #e2e8f0; }
        .cr-tab:active { transform: scale(0.97); }
        .cr-tab.active {
            background: linear-gradient(135deg, var(--v-primary, #6366f1), var(--v-primary-active, #4f46e5));
            color: #fff; font-weight: bold;
            box-shadow: 0 10px 22px -12px var(--v-primary-glow, rgba(99,102,241,0.95)), inset 0 1px 0 rgba(255,255,255,0.22);
        }
        .cr-hint {
            display: flex; align-items: flex-start; gap: 9px; text-align: right;
            margin: 0 20px 14px; padding: 11px 13px; border-radius: 13px;
            background: rgba(99,102,241,0.08); border: 1px solid rgba(99,102,241,0.20);
            border-right: 3px solid var(--v-primary, #6366f1);
        }
        .cr-hint-ico { font-size: 14px; line-height: 1.6; flex-shrink: 0; }
        .cr-desc { margin: 0; font-size: 12px; line-height: 1.85; color: #b7c2d6; }
        .cr-field { position: relative; margin: 0 20px; }
        .cr-input {
            margin: 0; text-align: right; padding: 13px 14px 13px 58px; border-radius: 14px;
            font-size: 15px; background: var(--v-input, #0f172a);
        }
        .cr-input:focus { border-color: var(--v-primary-border, rgba(99,102,241,0.55)); box-shadow: 0 0 0 3px var(--v-primary-soft, rgba(99,102,241,0.14)); }
        .cr-count {
            position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
            font-size: 10.5px; color: #64748b; direction: ltr; letter-spacing: .5px;
            background: rgba(148,163,184,0.08); border: 1px solid rgba(148,163,184,0.14);
            border-radius: 999px; padding: 2px 8px; pointer-events: none;
        }
        .cr-actions { margin: 16px 20px 20px; gap: 9px; }
        .cr-actions .modal-btn { margin-top: 0; }
        .cr-submit { flex: 1.4; }
        .cr-cancel { flex: 1; background: rgba(148,163,184,0.10); border: 1px solid rgba(148,163,184,0.18); }
        .cr-cancel:hover { background: rgba(148,163,184,0.16); }

        /* ── My-profile rows (were unstyled and ran into each other) ────────── */
        .profile-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px; border-radius: 12px; background: rgba(255,255,255,0.035); border: 1px solid rgba(148,163,184,0.10); margin-bottom: 8px; }
        .profile-row:last-child { margin-bottom: 0; }
        .profile-label { flex-shrink: 0; display: flex; align-items: center; gap: 6px; font-size: 12px; color: #94a3b8; }
        .profile-val { min-width: 0; font-size: 13px; font-weight: bold; color: #e2e8f0; text-align: left; word-break: break-word; }
        /* Security-code row: the value sits in a small live pill. The code
           itself is monospace (it is a code), but the "not set" state uses the
           site font so it never looks like a foreign element. */
        .profile-row-sec { background: linear-gradient(140deg, rgba(99,102,241,0.10), rgba(99,102,241,0.03)); border-color: rgba(99,102,241,0.22); }
        .profile-sec-wrap { display: inline-flex; align-items: center; gap: 8px; min-width: 0; }
        #profileSecCode { font-family: inherit; font-weight: normal; font-size: 12px; color: #94a3b8; background: rgba(148,163,184,0.08); border: 1px dashed rgba(148,163,184,0.25); border-radius: 999px; padding: 3px 12px; letter-spacing: 0; }
        #profileSecCode.has-code {
            font-family: 'Consolas', monospace; font-weight: bold; font-size: 13.5px;
            color: #e0e7ff; letter-spacing: 2.5px;
            background: linear-gradient(135deg, rgba(99,102,241,0.32), rgba(99,102,241,0.14));
            border: 1px solid rgba(99,102,241,0.5); border-radius: 10px;
            padding: 5px 14px;
            box-shadow: 0 0 0 3px rgba(99,102,241,0.08), inset 0 1px 0 rgba(255,255,255,0.12), 0 6px 16px -8px rgba(99,102,241,0.55);
            animation: vxSecGlow 3.2s ease-in-out infinite;
        }
        @keyframes vxSecGlow {
            0%, 100% { box-shadow: 0 0 0 3px rgba(99,102,241,0.08), inset 0 1px 0 rgba(255,255,255,0.12), 0 6px 16px -8px rgba(99,102,241,0.55); }
            50%      { box-shadow: 0 0 0 5px rgba(99,102,241,0.14), inset 0 1px 0 rgba(255,255,255,0.16), 0 8px 22px -8px rgba(99,102,241,0.75); }
        }
        .profile-copy-btn {
            flex-shrink: 0; width: 30px; height: 30px; border-radius: 9px; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center; font-size: 14px;
            background: rgba(99,102,241,0.14); border: 1px solid rgba(99,102,241,0.35); color: #c7d2fe;
            transition: transform .16s ease, background .16s ease, border-color .16s ease;
        }
        .profile-copy-btn:hover { background: rgba(99,102,241,0.26); border-color: rgba(99,102,241,0.6); transform: translateY(-1px); }
        .profile-copy-btn:active { transform: scale(0.94); }
        #profileRoom { max-width: 62%; padding: 4px 12px; border-radius: 9px; font-size: 12px; background: var(--v-soft, rgba(99,102,241,0.18)); border: 1px solid var(--v-primary-border, rgba(99,102,241,0.4)); color: var(--v-accent, #a5b4fc); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        @media (max-width: 420px) {
            .modal { padding: 22px 18px; border-radius: 20px; }
            .cr-tab { font-size: 11px; padding: 8px 4px; }
            .modal-actions { gap: 6px; }
            .modal-btn { font-size: 14px; padding: 11px; }
            .scm-char { height: 43px; font-size: 19px; border-radius: 10px; }
            .scm-code { gap: 4px; }
            .scm-hero { width: 74px; height: 74px; margin-bottom: 8px; }
            .scm-icon { width: 54px; height: 54px; }
        }
        /* ── Notification toast ─────────────────────────────────────────────── */
        .notif-popup {
            position: fixed; top: calc(env(safe-area-inset-top, 0px) + 12px); left: 50%;
            transform: translateX(-50%) translateY(-140%);
            width: min(430px, calc(100vw - 22px));
            display: flex; align-items: center; gap: 12px;
            padding: 11px 13px 11px 11px;
            border-radius: 18px; overflow: hidden;
            background: linear-gradient(160deg, rgba(33,44,71,0.985), rgba(20,28,47,0.985));
            border: 1px solid var(--v-primary-border, rgba(99,102,241,0.45));
            box-shadow: 0 24px 52px -20px rgba(0,0,0,0.9), inset 0 1px 0 rgba(255,255,255,0.05);
            backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
            z-index: 99999; cursor: pointer;
            opacity: 0; visibility: hidden;
            transition: transform .46s cubic-bezier(.34,1.4,.64,1), opacity .28s ease, visibility .28s;
        }
        .notif-popup.show { transform: translateX(-50%) translateY(0); opacity: 1; visibility: visible; }
        .nf-accent { position: absolute; inset-inline-start: 0; top: 0; bottom: 0; width: 3px; background: linear-gradient(180deg, var(--v-primary, #6366f1), transparent); }
        .notif-popup.kind-room .nf-accent { background: linear-gradient(180deg, #2dd4bf, transparent); }
        .notif-avatar {
            flex: 0 0 42px; width: 42px; height: 42px; border-radius: 14px;
            display: grid; place-items: center; font-size: 22px; line-height: 1;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(148,163,184,0.18);
            box-shadow: 0 8px 18px -12px rgba(0,0,0,0.9);
        }
        .notif-content { flex: 1 1 auto; min-width: 0; }
        .nf-top { display: flex; align-items: center; gap: 7px; margin-bottom: 2px; min-width: 0; }
        .notif-name { font-weight: bold; color: #fff; font-size: 13.5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .nf-kind {
            flex: 0 0 auto; font-size: 9.5px; font-weight: bold; padding: 2px 7px; border-radius: 6px;
            color: var(--v-accent, #a5b4fc); background: var(--v-primary-soft, rgba(99,102,241,0.16));
            border: 1px solid var(--v-primary-border, rgba(99,102,241,0.3));
            white-space: nowrap; max-width: 42%; overflow: hidden; text-overflow: ellipsis;
        }
        .notif-popup.kind-room .nf-kind { color: #5eead4; background: rgba(45,212,191,0.12); border-color: rgba(45,212,191,0.3); }
        .notif-text { color: #b7c4d6; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }
        .notif-hint { display: flex; align-items: center; gap: 5px; margin-top: 3px; color: var(--v-accent, #a5b4fc); font-size: 10.5px; font-weight: bold; }
        .nf-dot {
            width: 6px; height: 6px; border-radius: 50%; background: #ef4444; flex: 0 0 6px;
            box-shadow: 0 0 0 0 rgba(239,68,68,0.55);
            animation: nfPulse 1.9s ease-out infinite;
        }
        @keyframes nfPulse { 0% { box-shadow: 0 0 0 0 rgba(239,68,68,0.55); } 70% { box-shadow: 0 0 0 7px rgba(239,68,68,0); } 100% { box-shadow: 0 0 0 0 rgba(239,68,68,0); } }
        .msg-menu-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0); z-index: 2500; display: none; align-items: flex-end; justify-content: center; transition: background 0.25s ease; }
        .msg-menu-overlay.active { background: rgba(0,0,0,0.45); display: flex; }
        .msg-menu { background: var(--v-surface-2, #1e293b); width: 100%; max-width: 500px; border-radius: 20px 20px 0 0; padding: 25px; transform: translateY(100%); transition: transform 0.32s cubic-bezier(0.32, 0.72, 0, 1); box-shadow: 0 -10px 40px rgba(0,0,0,0.5); border-top: 1px solid var(--v-border-strong, #334155); padding-bottom: 40px; position: relative; z-index: 2501; will-change: transform; }
        .msg-menu-overlay.active .msg-menu { transform: translateY(0); }
        .menu-grid-actions { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 15px; }
        .action-item { background: rgba(255,255,255,0.05); border-radius: 15px; padding: 20px 10px; text-align: center; cursor: pointer; transition: 0.2s; display: flex; flex-direction: column; align-items: center; gap: 10px; color: #e2e8f0; font-size: 13px; border: 1px solid rgba(255,255,255,0.05); }
        .action-item:active { transform: scale(0.95); background: rgba(255,255,255,0.1); }
        .action-icon { font-size: 28px; margin-bottom: 5px; }
        .action-cancel { width: 100%; padding: 15px; background: var(--v-input, #0f172a); border-radius: 15px; border: none; color: #94a3b8; font-weight: bold; margin-top: 15px; cursor: pointer; font-size: 14px; }
        
        .menu-grid { display: flex; flex-wrap: wrap; gap: 8px; padding: 10px; margin-top: auto; border-top: 1px solid var(--border); background: var(--panel); z-index: 3; flex-shrink: 0; }
        .menu-item.locked { opacity: 0.55; }
        .menu-item.locked::after { content: '🔒'; font-size: 9px; margin-right: 4px; opacity: 0.8; }
        .menu-item.locked:hover { background: rgba(15, 23, 42, 0.5); border-color: var(--border); color: #cbd5e1; }
        .menu-item { background: rgba(15, 23, 42, 0.5); border: 1px solid var(--border); padding: 10px; border-radius: 12px; text-align: center; font-size: clamp(10px, calc(11px * var(--v-fs, 1)), 14px); cursor: pointer; color: #cbd5e1; display: flex; align-items: center; justify-content: center; min-height: 42px; flex: 1 1 calc(50% - 8px); transition: background .18s, border-color .18s, color .18s, transform .12s; -webkit-tap-highlight-color: transparent; }
        .menu-item:hover { background: var(--v-soft, rgba(99,102,241,0.15)); border-color: var(--v-primary-border, rgba(99,102,241,0.35)); color: #fff; }
        .menu-item:active { transform: scale(0.98); }
        .menu-item.full-width { flex: 1 1 100%; background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(185, 28, 28, 0.2)); color: #fca5a5; border-color: rgba(239, 68, 68, 0.3); font-weight: bold; }
        .menu-item.full-width:hover { background: linear-gradient(135deg, rgba(239,68,68,0.3), rgba(185,28,28,0.3)); color: #fff; }
        .menu-toggle-item { position: relative; display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 12px; text-align: right; background: rgba(15, 23, 42, 0.5); border: 1px solid var(--border); border-radius: 12px; color: #cbd5e1; font-size: 11px; cursor: pointer; min-height: 42px; flex: 1 1 calc(50% - 8px); transition: background .18s, border-color .18s; }
        /* Label keeps the right edge, the switch sits on the left with a real gap
           between them — no more text sliding under the toggle. */
        .menu-toggle-item > span:first-child { flex: 1 1 auto; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .menu-toggle-item > *:not(:first-child) { flex: 0 0 auto; }
        .menu-toggle-item:hover { background: rgba(99,102,241,0.12); border-color: rgba(99,102,241,0.3); }
        .menu-toggle-item.full-width { flex: 1 1 100%; }

        .ctx-menu, .msg-ctx-menu { position: fixed; background: var(--v-surface-2, #1e293b); border: 1px solid var(--v-border-strong, #334155); border-radius: 10px; padding: 5px; z-index: 9999; display: none; min-width: 150px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .ctx-item { padding: 8px 12px; cursor: pointer; color: #e2e8f0; font-size: 13px; border-radius: 6px; display: flex; align-items: center; justify-content: space-between; }
        .ctx-item:hover { background: var(--v-surface-2, #334155); color: #fff; }
        
        .typing-indicator { 
            align-self: flex-start; 
            margin-left: auto;      
            margin-right: 0; 
            background: var(--msg-other);
            color: #e2e8f0;
            padding: 10px 16px;
            border-radius: 16px;
            font-size: 13px;
            display: none;
            margin-bottom: 5px;
            border-bottom-right-radius: 4px;
            border-bottom-left-radius: 16px;
            animation: fadeIn 0.3s ease;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .typing-indicator::after {
            content: '...';
            animation: typing 1.5s infinite;
        }
        @keyframes typing {
            0% { content: '.'; }
            33% { content: '..'; }
            66% { content: '...'; }
        }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }

        .reply-content { background: rgba(255,255,255,0.1); border-right: 3px solid #6366f1; padding: 8px 12px; border-radius: 8px; margin-bottom: 10px; display: flex; flex-direction: column; cursor: pointer; font-size: 11px; position: relative; overflow: hidden; -webkit-tap-highlight-color: transparent !important; user-select: none; -webkit-user-select: none; }
        .reply-sender { font-weight: bold; color: var(--v-accent, #a5b4fc); margin-bottom: 3px; font-size: 12px; }
        .reply-text { color: #cbd5e1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        
        .disabled-overlay { position:absolute; inset:0; background:rgba(15,23,42,0.8); z-index:30; display:flex; justify-content:center; align-items:center; color:#94a3b8; font-size:12px; backdrop-filter:blur(2px); border-radius:20px; }
        
        .user-info-col { display: flex; flex-direction: column; justify-content: center; }
        .user-status-sub { font-size: 10px; margin-top: 3px; font-weight: normal; }
        .status-online { color: #34d399; } 
        .status-offline { color: #94a3b8; } 
        
        .found-room-card { background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(6, 95, 70, 0.2)); border: 1px solid rgba(16, 185, 129, 0.4); padding: 12px; border-radius: 12px; cursor: pointer; margin-top: 10px; transition: transform 0.2s; box-shadow: 0 4px 15px rgba(0,0,0,0.2); }
        .found-room-card:active { transform: scale(0.98); }
        .found-room-content { display: flex; justify-content: space-between; align-items: center; }
        .found-room-info { display: flex; flex-direction: column; gap: 4px; }
        .found-room-name { font-weight: bold; color: #34d399; font-size: 14px; }
        .found-room-action { background: #10b981; color: white; padding: 6px 12px; border-radius: 8px; font-size: 11px; font-weight: bold; box-shadow: 0 2px 5px rgba(16, 185, 129, 0.3); }

        .copy-code-box { display: flex; align-items: center; justify-content: space-between; background: var(--v-soft, rgba(99,102,241,0.2)); border: 1px dashed #6366f1; border-radius: 10px; padding: 10px 15px; margin-bottom: 20px; }
        .code-text { font-family: monospace; font-size: 24px; color: #fff; letter-spacing: 2px; }
        .copy-btn { background: rgba(255,255,255,0.1); border: none; color: #fff; width: 36px; height: 36px; border-radius: 8px; cursor: pointer; display: flex; justify-content: center; align-items: center; transition: 0.2s; }
        .copy-btn:hover { background: #6366f1; }
        
        .code-display-small { font-size: 10px; color: #94a3b8; background: rgba(0,0,0,0.2); padding: 2px 6px; border-radius: 4px; cursor: pointer; margin-top: 4px; display: inline-block; font-family: monospace; border: 1px dashed rgba(255,255,255,0.1); }
        .code-display-small:hover { background: var(--v-soft, rgba(99,102,241,0.2)); color: #fff; border-color: var(--v-primary, #6366f1); }
        
        .toggle-switch { position: relative; width: 34px; height: 18px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: var(--v-surface-2, #334155); transition: .4s; border-radius: 20px; }
        .slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 2px; bottom: 2px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #10b981; }
        input:checked + .slider:before { transform: translateX(16px); }
        
        .room-code-badge { font-size: 10px; color: var(--v-accent, #a5b4fc); background: var(--v-glow-soft, rgba(99,102,241,0.1)); padding: 2px 6px; border-radius: 4px; border: 1px dashed var(--v-primary-border, rgba(99,102,241,0.3)); margin-right: auto; cursor: pointer; font-family: monospace; }
        .room-code-badge:hover { background: var(--v-primary-border, rgba(99,102,241,0.3)); color: #fff; }
        
        .drag-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 9999; display: none; justify-content: center; align-items: center; flex-direction: column; font-size: 24px; color: #fff; border: 5px dashed #6366f1; backdrop-filter: blur(5px); }
        .drag-overlay.active { display: flex; }
        .drag-text { margin-top: 20px; font-weight: bold; }
        .drag-icon { font-size: 60px; animation: bounce 1s infinite; }
        @keyframes bounce { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-20px); } }

        #uploadProgressBox {
            position: fixed; bottom: 96px; left: 50%; transform: translateX(-50%);
            display: none; align-items: center; gap: 12px;
            width: min(92vw, 380px); padding: 12px 14px;
            background: rgba(15,23,42,0.97); backdrop-filter: blur(14px);
            border: 1px solid var(--v-primary-border, rgba(99,102,241,0.4));
            border-radius: 18px; z-index: 9000;
            box-shadow: 0 18px 50px rgba(0,0,0,0.55);
        }
        #uploadProgressBox.show { display: flex; }
        .up-thumb {
            width: 46px; height: 46px; flex: 0 0 46px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; overflow: hidden;
            background: var(--v-soft, rgba(99,102,241,0.18));
            border: 1px solid var(--v-primary-border, rgba(99,102,241,0.35));
        }
        .up-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .up-body { flex: 1 1 auto; min-width: 0; }
        .up-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .up-name { font-size: 12px; color: #e2e8f0; font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .up-pct { font-size: 13px; font-weight: bold; color: var(--v-accent, #a5b4fc); font-variant-numeric: tabular-nums; }
        .up-bar-bg { height: 6px; margin: 7px 0 6px; border-radius: 6px; background: rgba(148,163,184,0.18); overflow: hidden; }
        .up-bar { height: 100%; width: 0%; border-radius: 6px; background: linear-gradient(90deg, var(--v-primary, #6366f1), var(--v-primary-hover, #818cf8)); transition: width .18s ease; }
        .up-sub { font-size: 10.5px; color: #94a3b8; }
        .up-cancel { border: 0; background: transparent; color: #f87171; font-size: 11px; font-family: inherit; cursor: pointer; padding: 2px 4px; }
        .up-cancel:hover { color: #fecaca; }
        @media (max-width: 480px) {
            #uploadProgressBox { bottom: 84px; padding: 10px 12px; gap: 10px; }
            .up-thumb { width: 40px; height: 40px; flex-basis: 40px; }
        }

        .fp-thumb {
            width: 40px; height: 40px; flex: 0 0 40px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; overflow: hidden;
            background: var(--v-soft, rgba(99,102,241,0.18));
            border: 1px solid var(--v-primary-border, rgba(99,102,241,0.3));
        }
        .fp-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .fp-info { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 2px; margin-inline-start: 10px; }
        .fp-name { color: #fff; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .fp-meta { font-size: 10.5px; color: #94a3b8; }
        .fp-x { width: 26px; height: 26px; flex: 0 0 26px; border-radius: 8px; border: 1px solid rgba(239,68,68,0.3); background: rgba(239,68,68,0.12); color: #f87171; cursor: pointer; font-size: 12px; display: flex; align-items: center; justify-content: center; }
        .fp-x:hover { background: rgba(239,68,68,0.22); color: #fecaca; }

        #lightboxOverlay { transition: opacity 0.2s; }
        #lightboxImg { transition: transform 0.25s cubic-bezier(0.25, 0.8, 0.25, 1); }

        .captcha-row { display: flex; gap: 10px; margin-bottom: 15px; align-items: stretch; }
        .captcha-text { flex: 1; height: 50px; background: var(--v-primary-soft, var(--v-primary-glow, rgba(99, 102, 241, 0.1))); border: 1px dashed var(--v-primary-border, var(--v-primary-border, rgba(99,102,241,0.4))); border-radius: 14px; display: flex; justify-content: center; align-items: center; font-weight: bold; color: var(--v-primary-hover, var(--v-accent, #a5b4fc)); font-size: 20px; font-family: 'AppBold'; overflow: hidden; white-space: nowrap; user-select: none; transition: border-color .25s, background .25s, transform .25s; }
        .captcha-text.captcha-loading { opacity: .45; border-color: rgba(148,163,184,.5); animation: vCaptchaPulse 0.7s ease-in-out infinite; }
        .captcha-text.captcha-pop { animation: vCaptchaPop .4s cubic-bezier(.2,1.4,.4,1); }
        .refresh-btn { width: 50px; height: 50px; padding: 0; margin: 0; flex: 0 0 50px; background: var(--v-input, rgba(15, 23, 42, 0.7)); border: 1px solid var(--v-border-strong, #334155); border-radius: 14px; color: #94a3b8; cursor: pointer; display: flex; justify-content: center; align-items: center; font-size: 22px; line-height: 1; box-shadow: none; transition: background .25s, color .25s, border-color .25s, transform .15s; -webkit-tap-highlight-color: transparent; }
        .refresh-btn:hover { background: rgba(99,102,241,.18); color: #fff; border-color: var(--v-primary-border, rgba(99,102,241,.45)); }
        .refresh-btn:active { transform: scale(.94); }
        .refresh-btn.rotating { animation: spin-icon 0.75s cubic-bezier(.4,0,.2,1); background: var(--v-primary-border, rgba(99, 102, 241, 0.3)); color: #fff; border-color: var(--v-accent, #a5b4fc); }
        @keyframes vCaptchaPulse { 0%,100% { opacity: .35; } 50% { opacity: .7; } }
        @keyframes vCaptchaPop { 0% { transform: scale(.88); opacity: .3; } 60% { transform: scale(1.04); opacity: 1; } 100% { transform: scale(1); } }
        input[type=number]::-webkit-inner-spin-button, input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        input[type=number] { -moz-appearance: textfield; }

        /* ── Report-user modal (3D) ────────────────────────────────────────────
           Same language as the welcome sheet: glass surface, a lifted badge,
           inset fields and a pressed-3D primary button. Scoped to .rp-* so no
           other dialog inherits anything. */
        .rp-modal {
            position: relative; max-width: 424px; padding: 22px 20px 18px;
            text-align: right; border-radius: 26px; border: 1px solid rgba(255,255,255,0.08);
            background:
                radial-gradient(120% 80% at 88% -12%, rgba(239,68,68,0.15), transparent 62%),
                radial-gradient(90% 70% at 0% 108%, var(--v-primary-soft, rgba(99,102,241,0.16)), transparent 70%),
                linear-gradient(168deg, #1e283e, #141b2b 58%, #101724);
            box-shadow:
                0 36px 80px -28px rgba(0,0,0,0.95),
                0 0 0 1px rgba(255,255,255,0.04) inset,
                0 1px 0 rgba(255,255,255,0.09) inset;
        }
        .rp-head { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
        .rp-badge {
            flex: 0 0 52px; width: 52px; height: 52px; border-radius: 17px; display: grid; place-items: center;
            background: linear-gradient(160deg, rgba(239,68,68,0.24), rgba(239,68,68,0.05));
            border: 1px solid rgba(239,68,68,0.32);
            box-shadow: 0 14px 26px -16px rgba(239,68,68,0.9), 0 1px 0 rgba(255,255,255,0.14) inset;
        }
        .rp-badge img { width: 30px; height: 30px; object-fit: contain; filter: drop-shadow(0 5px 8px rgba(0,0,0,0.55)); }
        .rp-head-tx { flex: 1 1 auto; min-width: 0; }
        .rp-title { font-size: 17px; margin: 0 0 4px; text-align: right; }
        .rp-sub { margin: 0; font-size: 11.5px; line-height: 1.85; color: #8fa1ba; }

        .rp-field { position: relative; margin-bottom: 12px; }
        .rp-label { display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 700; color: #9fb0c7; margin-bottom: 6px; }
        .rp-label img { width: 17px; height: 17px; object-fit: contain; opacity: 1; filter: brightness(1.18) saturate(1.05) drop-shadow(0 2px 3px rgba(0,0,0,0.6)); }

        .rp-input, .rp-textarea {
            width: 100%; margin: 0; text-align: right; padding: 12px 14px; border-radius: 14px;
            font-size: 13.5px; color: #eaf0fb;
            background: rgba(9,14,26,0.72); border: 1px solid rgba(148,163,184,0.18);
            box-shadow: 0 1px 0 rgba(255,255,255,0.04) inset, 0 10px 20px -18px rgba(0,0,0,0.95);
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }
        .rp-input::placeholder, .rp-textarea::placeholder { color: #64748b; }
        .rp-input:focus, .rp-textarea:focus {
            border-color: var(--v-primary-border, rgba(99,102,241,0.6));
            box-shadow: 0 0 0 3px var(--v-primary-soft, rgba(99,102,241,0.16));
            background: rgba(9,14,26,0.92);
        }
        .rp-textarea { height: 86px; resize: none; line-height: 1.95; padding-bottom: 22px; }
        .rp-count { position: absolute; left: 10px; bottom: 8px; font-size: 10px; color: #5b6b82; pointer-events: none; }

        .rp-captcha { margin: 0 0 8px; }
        .rp-captcha .captcha-text { height: 46px; font-size: 18px; border-radius: 13px; }
        .rp-captcha .refresh-btn { width: 46px; height: 46px; flex: 0 0 46px; border-radius: 13px; }

        .rp-suggest-wrap { position: relative; }
        #reportUserSuggestions {
            position: absolute; top: calc(100% + 6px); right: 0; left: 0; width: auto;
            max-height: 168px; overflow-y: auto; z-index: 3000; display: none; margin: 0;
            background: rgba(20,28,44,0.98);
            backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(148,163,184,0.22); border-radius: 14px;
            box-shadow: 0 24px 42px -20px rgba(0,0,0,0.92), 0 1px 0 rgba(255,255,255,0.05) inset;
        }
        #reportUserSuggestions.show { display: block; }
        .suggestion-item {
            padding: 10px 14px; cursor: pointer;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            color: #dbe3f0; font-size: 13px;
            display: flex; align-items: center; gap: 9px;
            transition: background 0.16s, color 0.16s;
        }
        .suggestion-item:last-child { border-bottom: none; }
        .suggestion-item:hover { background: var(--v-primary-soft, rgba(99,102,241,0.2)); color: #fff; }

        .rp-actions { display: flex; gap: 9px; margin-top: 14px; }
        .rp-actions .modal-btn { margin-top: 0; flex: 1 1 0; }
        .rp-send {
            background: linear-gradient(180deg, #f87171, #dc2626); color: #fff; font-family: 'AppBold', 'AppFont', sans-serif;
            box-shadow: 0 16px 28px -14px rgba(239,68,68,0.85), 0 1px 0 rgba(255,255,255,0.28) inset, 0 -2px 0 rgba(0,0,0,0.2) inset;
            transition: transform 0.12s ease, filter 0.2s ease, box-shadow 0.2s ease;
        }
        .rp-send:hover { filter: brightness(1.07); transform: translateY(-1px); }
        .rp-send:active { transform: translateY(1px) scale(0.995); }
        .rp-send:disabled { filter: saturate(0.7) brightness(0.9); transform: none; cursor: not-allowed; }
        .rp-cancel {
            background: rgba(148,163,184,0.10); border: 1px solid rgba(148,163,184,0.18); color: #cbd5e1;
            transition: background 0.18s ease, color 0.18s ease, border-color 0.18s ease;
        }
        .rp-cancel:hover { background: rgba(148,163,184,0.18); color: #e2e8f0; }

        .scroll-controls { position: absolute; bottom: 85px; right: 20px; display: flex; flex-direction: column; gap: 10px; z-index: 900; pointer-events: none; }
        .scroll-btn { width: 40px; height: 40px; background: rgba(30, 41, 59, 0.8); backdrop-filter: blur(5px); border: 1px solid rgba(255,255,255,0.1); border-radius: 50%; color: #fff; display: flex; justify-content: center; align-items: center; cursor: pointer; pointer-events: auto; transition: 0.3s; opacity: 0; visibility: hidden; transform: scale(0.8); box-shadow: 0 5px 15px rgba(0,0,0,0.3); position: relative; }
        .scroll-btn.visible { opacity: 1; visibility: visible; transform: scale(1); }
        .scroll-btn:hover { background: var(--primary); border-color: var(--primary); }
        .scroll-btn svg { width: 20px; height: 20px; fill: currentColor; }

        .ban-modal-content { background: rgba(50, 0, 0, 0.8); backdrop-filter: blur(20px); border: 1px solid rgba(239, 68, 68, 0.4); background-image: linear-gradient(145deg, rgba(69, 10, 10, 0.9), rgba(26, 5, 5, 0.95)); border-radius: 30px; padding: 40px 30px; text-align: center; width: 100%; max-width: 450px; box-shadow: 0 25px 60px rgba(0,0,0,0.8); position: relative; overflow: hidden; margin: 0 auto; }
        .ban-modal-content::after { content: ''; position: absolute; inset: 0; border-radius: 30px; padding: 2px; background: linear-gradient(45deg, transparent, rgba(239, 68, 68, 0.3), transparent); -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0); -webkit-mask-composite: xor; mask-composite: exclude; pointer-events: none; }
        .ban-icon { font-size: 65px; margin-bottom: 20px; display: inline-block; filter: drop-shadow(0 0 25px rgba(239, 68, 68, 0.5)); animation: pulseBan 2s infinite; }
        .ban-title { color: #ef4444; font-size: 24px; margin-bottom: 15px; font-family: 'AppBold'; text-shadow: 0 0 20px rgba(239, 68, 68, 0.4); }
        .ban-message { color: #cbd5e1; font-size: 15px; line-height: 1.8; margin-bottom: 30px; background: rgba(0,0,0,0.25); padding: 20px; border-radius: 15px; border: 1px solid rgba(255,255,255,0.05); }
        .ban-btn { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.1); color: #fff; padding: 12px 30px; border-radius: 12px; cursor: pointer; transition: 0.3s; font-family: 'AppBold'; font-size: 14px; }
        .ban-btn:hover { background: rgba(255,255,255,0.2); transform: translateY(-2px); }
        @keyframes pulseBan { 0% { transform: scale(1); } 50% { transform: scale(1.05); filter: drop-shadow(0 0 35px rgba(239, 68, 68, 0.7)); } 100% { transform: scale(1); } }
        
        #toast { position: fixed; top: 20px; left: 50%; transform: translateX(-50%) translateY(-20px); background: rgba(239, 68, 68, 0.95); backdrop-filter: blur(8px); color: white; padding: 12px 24px; border-radius: 16px; font-size: 14px; opacity: 0; transition: 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); pointer-events: none; z-index: 9999; text-align: center; max-width: 90%; width: max-content; box-shadow: 0 10px 30px rgba(239, 68, 68, 0.3); line-height: 1.6; border: 1px solid rgba(255,255,255,0.2); }
        #toast.show { opacity: 1; transform: translateX(-50%) translateY(20px); }

        .load-more-box { background: var(--v-primary-glow, rgba(99, 102, 241, 0.1)); border: 1px dashed var(--v-primary-border, rgba(99,102,241,0.4)); color: var(--v-accent, #a5b4fc); padding: 10px 15px; text-align: center; border-radius: 12px; cursor: pointer; font-family: 'AppBold'; font-size: 13px; margin: 0 auto 20px auto; width: max-content; min-width: 200px; transition: all 0.3s ease; user-select: none; }
        .load-more-box:hover { background: var(--v-soft, rgba(99, 102, 241, 0.2)); border-color: var(--v-primary, #6366f1); color: #fff; }

        @keyframes spin-circle { 100% { transform: rotate(360deg); } }
        @keyframes spin-icon { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        .history-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.92); z-index: 99999; display: flex; justify-content: center; align-items: center; flex-direction: column; backdrop-filter: blur(10px); opacity: 0; visibility: hidden; transition: all 0.3s ease; }
        .history-overlay.active { opacity: 1; visibility: visible; }
        .history-spinner-wrap { position: relative; width: 64px; height: 64px; display: flex; align-items: center; justify-content: center; margin-bottom: 20px; }
        .history-spinner { width: 64px; height: 64px; border: 4px solid var(--v-soft, rgba(99, 102, 241, 0.15)); border-top-color: var(--v-primary, #6366f1); border-radius: 50%; animation: spin 1s linear infinite; box-shadow: 0 0 20px var(--v-primary-border, rgba(99, 102, 241, 0.3)); position: absolute; inset: 0; }
        .history-spinner-inner { width: 44px; height: 44px; border: 3px solid var(--v-primary-glow, rgba(99, 102, 241, 0.1)); border-bottom-color: var(--v-accent, #a5b4fc); border-radius: 50%; animation: spin 0.7s linear infinite reverse; position: absolute; top: 10px; left: 10px; }
        .history-spinner-dot { width: 10px; height: 10px; background: #6366f1; border-radius: 50%; box-shadow: 0 0 12px rgba(99, 102, 241, 0.9); animation: hdotPulse 1.5s ease-in-out infinite; }
        @keyframes hdotPulse { 0%,100%{transform:scale(1)} 50%{transform:scale(0.5);opacity:0.5} }
        .history-text { color: #fff; font-family: 'AppBold'; font-size: 15px; text-shadow: 0 2px 10px rgba(0,0,0,0.5); animation: pulseText 1.5s infinite; letter-spacing: 0.3px; }
        @keyframes pulseText { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }

        .ham-wrap { position: relative; display: inline-flex; align-items: center; justify-content: center; }
        .ham-badge {
            position: absolute; top: -5px; inset-inline-end: -5px;
            background: linear-gradient(180deg, #f05252, #dc2626);
            color: #fff; font-size: 10px; font-weight: bold;
            min-width: 17px; height: 17px; padding: 0 4px;
            border-radius: 999px; display: none;
            align-items: center; justify-content: center; line-height: 1;
            font-variant-numeric: tabular-nums;
            box-shadow: 0 2px 8px -2px rgba(239,68,68,0.8), inset 0 1px 0 rgba(255,255,255,0.2);
            pointer-events: none;
        }

        .help-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.65); backdrop-filter: blur(10px); z-index: 9000; display: none; justify-content: center; align-items: center; padding: 16px; }
        .help-overlay.open { display: flex; }
        .help-card { background: var(--v-surface-2, #1e293b); border: 1px solid rgba(255,255,255,0.08); border-radius: 24px; width: 100%; max-width: 480px; max-height: 88vh; display: flex; flex-direction: column; overflow: hidden; animation: helpIn 0.3s cubic-bezier(0.2,0.8,0.2,1); }
        @keyframes helpIn { from { opacity:0; transform: scale(0.95) translateY(12px); } to { opacity:1; transform: scale(1) translateY(0); } }
        .help-header { padding: 20px 20px 16px; border-bottom: 1px solid rgba(255,255,255,0.06); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
        .help-title { font-family: 'AppBold'; font-size: 18px; color: #fff; }
        .help-close { width: 30px; height: 30px; border-radius: 50%; background: rgba(255,255,255,0.07); border: none; color: #94a3b8; font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.2s; }
        .help-close:hover { background: rgba(239,68,68,0.2); color: #ef4444; }
        .help-body { overflow-y: auto; padding: 16px 20px 24px; }
        .help-section { margin-bottom: 20px; }
        .help-section-title { font-family: 'AppBold'; font-size: 13px; color: #6366f1; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
        .help-item { display: flex; align-items: flex-start; gap: 12px; padding: 10px 0; border-bottom: 1px solid rgba(255,255,255,0.04); }
        .help-item:last-child { border-bottom: none; }
        .help-icon { font-size: 22px; flex-shrink: 0; width: 32px; text-align: center; margin-top: 1px; }
        .help-text { flex: 1; }
        .help-name { font-size: 14px; color: #e2e8f0; font-weight: bold; margin-bottom: 3px; }
        .help-desc { font-size: 12px; color: #64748b; line-height: 1.6; }

        .chat-trans-overlay { position: absolute; inset: 0; background: var(--v-bg-0, #0f172a); z-index: 800; display: none; justify-content: center; align-items: center; flex-direction: column; gap: 16px; }
        .chat-trans-overlay.active { display: flex; }
        .chat-trans-spin-wrap { position: relative; width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; }
        .chat-trans-spin-outer { position: absolute; inset: 0; border: 3px solid var(--v-soft, rgba(99,102,241,0.15)); border-top-color: var(--v-primary, #6366f1); border-radius: 50%; animation: spin 1s linear infinite; }
        .chat-trans-spin-inner { position: absolute; inset: 10px; border: 2px solid var(--v-glow-soft, rgba(99,102,241,0.1)); border-bottom-color: var(--v-accent, #a5b4fc); border-radius: 50%; animation: spin 0.7s linear infinite reverse; }
        .chat-trans-dot { width: 8px; height: 8px; background: #6366f1; border-radius: 50%; box-shadow: 0 0 10px rgba(99,102,241,0.8); animation: ctDotPulse 1.5s ease-in-out infinite; }
        @keyframes ctDotPulse { 0%,100%{transform:scale(1);opacity:1} 50%{transform:scale(0.6);opacity:0.5} }
        .chat-trans-name { color: #64748b; font-size: 13px; animation: ctNamePulse 1.5s ease-in-out infinite; letter-spacing: 0.3px; }
        @keyframes ctNamePulse { 0%,100%{opacity:0.7} 50%{opacity:1} }
        .chat-trans-sub { color: #334155; font-size: 11px; }

        .emoji-display { font-family: 'Noto Color Emoji', 'Segoe UI Emoji', 'Apple Color Emoji', 'Twemoji Mozilla', 'EmojiOne Color', sans-serif !important; font-style: normal; line-height: 1.2; text-rendering: optimizeLegibility; -webkit-font-smoothing: antialiased; }

        .pin-bar { background: linear-gradient(90deg, var(--v-primary-soft, rgba(99,102,241,0.14)) 0%, var(--v-primary-strong, rgba(79,70,229,0.09)) 100%); border-bottom: 1px solid var(--v-primary-border, rgba(99,102,241,0.25)); padding: 0 12px; display: none; align-items: center; gap: 8px; flex-shrink: 0; min-height: 52px; max-height: 56px; transition: background 0.2s; position: relative; overflow: hidden; }
        .pin-bar::before { content:''; position:absolute; inset:0; background: linear-gradient(90deg, var(--v-soft, rgba(99,102,241,0.18)), transparent); opacity:0; transition: opacity 0.2s; pointer-events:none; }
        .pin-bar:hover::before { opacity:1; }
        .pin-bar.active { display: flex; }
        .pin-bar-left { display:flex; align-items:center; gap:10px; flex:1; min-width:0; cursor:pointer; height:100%; padding: 10px 0; }
        .pin-bar-icon-wrap { display:flex; align-items:center; justify-content:center; width:38px; height:38px; background:var(--v-soft, rgba(99,102,241,0.22)); border-radius:10px; flex-shrink:0; }
        .pin-bar-icon { font-size:18px; display:block; }
        .pin-bar-texts { flex:1; min-width:0; }
        .pin-bar-label { font-size:11px; color:var(--v-accent, #a5b4fc); font-weight:bold; letter-spacing:0.6px; display:block; line-height:1.3; text-transform:uppercase; margin-bottom:4px; }
        .pin-bar-preview { font-size:14px; color:#e2e8f0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:block; line-height:1.5; font-weight:bold; }
        .pin-bar-nav { display:flex; align-items:center; gap:4px; flex-shrink:0; }
        .pin-bar-nav-btn { width:30px; height:30px; border-radius:8px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1); color:#94a3b8; font-size:15px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.18s; user-select:none; }
        .pin-bar-nav-btn:hover { background:var(--v-soft, rgba(99,102,241,0.2)); border-color:rgba(99,102,241,0.4); color:#fff; }
        .pin-bar-count { font-size:11px; color:#6366f1; font-weight:bold; min-width:26px; text-align:center; }
        .pin-bar-unpin { width:30px; height:30px; border-radius:8px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.08); color:#64748b; font-size:13px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.18s; flex-shrink:0; margin-right:2px; }
        .pin-bar-unpin:hover { background:rgba(239,68,68,0.15); border-color:rgba(239,68,68,0.35); color:#ef4444; }

        .pin-modal-overlay { position:fixed; inset:0; z-index:9500; display:flex; justify-content:center; align-items:center; padding:20px; pointer-events:none; }
        .pin-modal-overlay.active { pointer-events:all; }
        .pin-modal-backdrop { position:absolute; inset:0; background:rgba(0,0,0,0.0); backdrop-filter:blur(0px); transition: background 0.3s ease, backdrop-filter 0.3s ease; }
        .pin-modal-overlay.active .pin-modal-backdrop { background:rgba(0,0,0,0.75); backdrop-filter:blur(12px); }
        .pin-modal-card { background:linear-gradient(145deg,#1a2540,#1e293b); border:1px solid var(--v-primary-border, rgba(99,102,241,0.25)); border-radius:24px; width:100%; max-width:600px; max-height:88vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 40px 100px rgba(0,0,0,0.8), 0 0 0 1px rgba(255,255,255,0.04); position:relative; z-index:1; transform:scale(0.93) translateY(18px); opacity:0; transition:transform 0.3s cubic-bezier(0.2,0.85,0.25,1), opacity 0.3s ease; }
        .pin-modal-overlay.active .pin-modal-card { transform:scale(1) translateY(0); opacity:1; }
        .pin-modal-header { padding:16px 18px 14px; border-bottom:1px solid rgba(255,255,255,0.06); display:flex; align-items:center; gap:10px; flex-shrink:0; }
        .pin-modal-header-icon { width:34px; height:34px; background:var(--v-soft, rgba(99,102,241,0.2)); border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; }
        .pin-modal-title-wrap { flex:1; }
        .pin-modal-title { font-family:'AppBold'; font-size:15px; color:#fff; display:block; }
        .pin-modal-subtitle { font-size:11px; color:#64748b; display:block; margin-top:1px; }
        .pin-modal-close { width:32px; height:32px; border-radius:10px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.08); color:#64748b; font-size:16px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.18s; flex-shrink:0; }
        .pin-modal-close:hover { background:rgba(239,68,68,0.15); border-color:rgba(239,68,68,0.3); color:#ef4444; }
        .pin-modal-body { overflow-y:auto; padding:14px 16px 20px; -webkit-overflow-scrolling:touch; display:flex; flex-direction:column; gap:10px; }
        .pin-modal-body::-webkit-scrollbar { width:4px; }
        .pin-modal-body::-webkit-scrollbar-track { background:transparent; }
        .pin-modal-body::-webkit-scrollbar-thumb { background:var(--v-primary-border, rgba(99,102,241,0.25)); border-radius:4px; }
        .pin-modal-empty { text-align:center; padding:48px 20px; color:#475569; }
        .pin-modal-empty-icon { font-size:36px; display:block; margin-bottom:10px; opacity:0.5; }
        .pin-modal-empty-text { font-size:13px; }
        .pin-modal-footer { padding:12px 16px; border-top:1px solid rgba(255,255,255,0.05); display:none; gap:8px; flex-shrink:0; }
        .pin-modal-footer.has-pins { display:flex; }
        .pin-modal-unpin-all { flex:1; padding:9px; border-radius:12px; background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.2); color:#fca5a5; font-size:13px; cursor:pointer; transition:0.18s; font-family:'AppFont'; }
        .pin-modal-unpin-all:hover { background:rgba(239,68,68,0.2); border-color:rgba(239,68,68,0.4); color:#fff; }

        .pin-item { background:rgba(15,23,42,0.7); border:1px solid rgba(255,255,255,0.06); border-radius:16px; padding:13px 14px; cursor:pointer; transition:border-color 0.18s, background 0.18s, transform 0.15s; position:relative; animation:pinItemIn 0.25s ease; }
        @keyframes pinItemIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
        .pin-item:hover { background:var(--v-glow-soft, rgba(99,102,241,0.08)); border-color:var(--v-active-soft, rgba(99,102,241,0.35)); transform:translateY(-1px); }
        .pin-item:active { transform:translateY(0); }
        .pin-item-top { display:flex; align-items:flex-start; gap:10px; }
        .pin-item-pin-mark { width:4px; flex-shrink:0; height:100%; position:absolute; right:0; top:0; bottom:0; background:linear-gradient(180deg,var(--v-primary, #6366f1),var(--v-primary-active, #4f46e5)); border-radius:0 16px 16px 0; }
        .pin-item-body { flex:1; min-width:0; padding-right:6px; }
        .pin-item-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; }
        .pin-item-sender { font-size:12px; color:var(--v-accent, #a5b4fc); font-weight:bold; display:flex; align-items:center; gap:5px; }
        .pin-item-time { font-size:10px; color:#334155; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.06); padding:2px 7px; border-radius:6px; }
        .pin-item-content { font-size:13px; color:#cbd5e1; line-height:1.6; word-break:break-word; }
        .pin-item-content-truncated { display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
        .pin-item-media { width:100%; max-height:180px; object-fit:cover; border-radius:10px; margin-top:8px; display:block; border:1px solid rgba(255,255,255,0.06); }
        .pin-item-voice { width:100%; margin-top:8px; border-radius:10px; height:38px; }
        .pin-item-file-box { display:flex; align-items:center; gap:10px; background:rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.06); border-radius:10px; padding:10px 12px; margin-top:8px; }
        .pin-item-file-icon { font-size:22px; flex-shrink:0; }
        .pin-item-file-name { font-size:12px; color:#e2e8f0; flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .pin-item-file-dl { background:var(--v-soft, rgba(99,102,241,0.2)); border:1px solid var(--v-primary-border, rgba(99,102,241,0.3)); color:var(--v-accent, #a5b4fc); padding:4px 10px; border-radius:8px; font-size:11px; text-decoration:none; transition:0.15s; white-space:nowrap; }
        .pin-item-file-dl:hover { background:var(--v-active-soft, rgba(99,102,241,0.35)); color:#fff; }
        .pin-item-actions { display:flex; gap:6px; align-items:center; flex-shrink:0; }
        .pin-item-go { width:30px; height:30px; border-radius:9px; background:var(--v-primary-soft, rgba(99,102,241,0.12)); border:1px solid var(--v-soft, rgba(99,102,241,0.2)); color:var(--v-accent, #a5b4fc); font-size:14px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.18s; }
        .pin-item-go:hover { background:var(--v-primary-border, rgba(99,102,241,0.25)); color:#fff; }
        .pin-item-del { width:30px; height:30px; border-radius:9px; background:rgba(239,68,68,0.07); border:1px solid rgba(239,68,68,0.15); color:#ef4444; font-size:13px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.18s; opacity:0; }
        .pin-item:hover .pin-item-del { opacity:1; }
        .pin-item-del:hover { background:rgba(239,68,68,0.2); border-color:rgba(239,68,68,0.4); }
        .pin-item-loading { display:flex; align-items:center; justify-content:center; gap:10px; padding:20px; color:#475569; font-size:13px; }
        .pin-item-spinner { width:18px; height:18px; border:2px solid var(--v-soft, rgba(99,102,241,0.2)); border-top-color:#6366f1; border-radius:50%; animation:spin 0.8s linear infinite; flex-shrink:0; }

        .pin-viewer-overlay { position:fixed; inset:0; z-index:9800; display:flex; justify-content:center; align-items:center; pointer-events:none; }
        .pin-viewer-overlay.active { pointer-events:all; }
        .pin-viewer-backdrop { position:absolute; inset:0; background:rgba(0,0,0,0); backdrop-filter:blur(0px); transition:background 0.28s, backdrop-filter 0.28s; }
        .pin-viewer-overlay.active .pin-viewer-backdrop { background:rgba(0,0,0,0.88); backdrop-filter:blur(18px); }
        .pin-viewer-card { background:linear-gradient(145deg,#111827,#1e293b); border:1px solid var(--v-soft, rgba(99,102,241,0.2)); border-radius:22px; width:100%; max-width:540px; max-height:88vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 50px 120px rgba(0,0,0,0.9); position:relative; z-index:1; transform:scale(0.9) translateY(24px); opacity:0; transition:transform 0.3s cubic-bezier(0.2,0.85,0.25,1), opacity 0.28s ease; }
        .pin-viewer-overlay.active .pin-viewer-card { transform:scale(1) translateY(0); opacity:1; }
        .pin-viewer-header { padding:14px 16px 12px; border-bottom:1px solid rgba(255,255,255,0.06); display:flex; align-items:center; gap:10px; flex-shrink:0; }
        .pin-viewer-sender { flex:1; display:flex; flex-direction:column; gap:2px; }
        .pin-viewer-sender-name { font-size:13px; color:var(--v-accent, #a5b4fc); font-weight:bold; }
        .pin-viewer-sender-time { font-size:10px; color:#475569; }
        .pin-viewer-close { width:32px; height:32px; border-radius:10px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.08); color:#64748b; font-size:16px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.18s; flex-shrink:0; }
        .pin-viewer-close:hover { background:rgba(239,68,68,0.15); border-color:rgba(239,68,68,0.3); color:#ef4444; }
        .pin-viewer-body { flex:1; overflow-y:auto; padding:16px; -webkit-overflow-scrolling:touch; display:flex; flex-direction:column; gap:12px; }
        .pin-viewer-body::-webkit-scrollbar { width:4px; }
        .pin-viewer-body::-webkit-scrollbar-thumb { background:var(--v-primary-border, rgba(99,102,241,0.25)); border-radius:4px; }
        .pin-viewer-img { width:100%; max-height:380px; object-fit:contain; border-radius:14px; display:block; background:var(--v-bg-2, #0f172a); cursor:zoom-in; }
        .pin-viewer-video { width:100%; max-height:340px; border-radius:14px; display:block; background:var(--v-bg-2, #0f172a); }
        .pin-viewer-audio { width:100%; border-radius:12px; margin-top:4px; }
        .pin-viewer-file-box { display:flex; align-items:center; gap:14px; background:rgba(0,0,0,0.3); border:1px solid rgba(255,255,255,0.08); border-radius:14px; padding:14px 16px; }
        .pin-viewer-file-icon { font-size:32px; flex-shrink:0; }
        .pin-viewer-file-info { flex:1; min-width:0; }
        .pin-viewer-file-name { font-size:14px; color:#e2e8f0; font-weight:bold; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:block; }
        .pin-viewer-file-dl { display:inline-flex; align-items:center; gap:6px; background:linear-gradient(135deg,var(--v-primary, #6366f1),var(--v-primary-active, #4f46e5)); border:none; color:#fff; padding:8px 18px; border-radius:10px; font-size:13px; text-decoration:none; cursor:pointer; transition:0.2s; margin-top:8px; font-family:'AppFont'; }
        .pin-viewer-file-dl:hover { background:linear-gradient(135deg,var(--v-accent, #a5b4fc),#6366f1); transform:translateY(-1px); }
        .pin-viewer-text { font-size:14px; color:#e2e8f0; line-height:1.7; white-space:pre-wrap; word-break:break-word; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.06); border-radius:12px; padding:14px 16px; }
        .pin-viewer-footer { padding:12px 16px; border-top:1px solid rgba(255,255,255,0.05); display:flex; gap:8px; flex-shrink:0; }
        .pin-viewer-goto { flex:1; padding:10px; border-radius:12px; background:var(--v-soft, rgba(99,102,241,0.15)); border:1px solid var(--v-primary-border, rgba(99,102,241,0.25)); color:var(--v-accent, #a5b4fc); font-size:13px; cursor:pointer; transition:0.18s; font-family:'AppFont'; }
        .pin-viewer-goto:hover { background:var(--v-primary-border, rgba(99,102,241,0.25)); color:#fff; }
        .pin-viewer-unpin { padding:10px 16px; border-radius:12px; background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.2); color:#fca5a5; font-size:13px; cursor:pointer; transition:0.18s; font-family:'AppFont'; }
        .pin-viewer-unpin:hover { background:rgba(239,68,68,0.2); color:#fff; }

        .search-btn { background:none; border:none; cursor:pointer; color:#94a3b8; font-size:18px; padding:6px; border-radius:8px; transition:color 0.15s, background 0.15s; display:flex; align-items:center; justify-content:center; width:34px; height:34px; }
        .search-btn:hover { color:var(--v-accent, #a5b4fc); background:rgba(165,180,252,0.1); }
        .search-panel { position:absolute; top:0; left:0; right:0; background:var(--v-bg-1, #0f172a); z-index:1200; display:none; align-items:center; gap:8px; padding:10px 14px; border-bottom:1px solid var(--v-border, #1e293b); box-shadow:0 4px 20px rgba(0,0,0,0.4); }
        .search-panel.active { display:flex; }
        .search-input-wrap { flex:1; position:relative; }
        .search-input-field { width:100%; background:var(--v-input, #1e293b); border:1px solid var(--v-border-strong, #334155); border-radius:10px; padding:8px 36px 8px 12px; color:#e2e8f0; font-size:14px; font-family:'AppFont'; outline:none; transition:border-color 0.2s; box-sizing:border-box; }
        .search-input-field:focus { border-color:#6366f1; }
        .search-input-field::placeholder { color:#64748b; }
        .search-clear-btn { position:absolute; left:10px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:#64748b; font-size:16px; padding:2px; line-height:1; }
        .search-close-btn { background:none; border:none; cursor:pointer; color:#94a3b8; font-size:20px; padding:4px; }
        .search-results-panel { position:absolute; top:55px; left:0; right:0; background:var(--v-bg-1, #0f172a); border-bottom:1px solid var(--v-border, #1e293b); z-index:1199; max-height:60vh; overflow-y:auto; display:none; box-shadow:0 8px 30px rgba(0,0,0,0.5); }
        .search-results-panel.active { display:block; }
        .search-result-item { padding:12px 16px; border-bottom:1px solid rgba(255,255,255,0.04); cursor:pointer; transition:background 0.15s; }
        .search-result-item:hover { background:var(--v-glow-soft, rgba(99,102,241,0.08)); }
        .search-result-sender { font-size:12px; color:var(--v-accent, #a5b4fc); font-weight:bold; margin-bottom:3px; }
        .search-result-text { font-size:13px; color:#cbd5e1; line-height:1.5; word-break:break-word; }
        .search-result-text mark { background:var(--v-active-soft, rgba(99,102,241,0.35)); color:#e2e8f0; border-radius:3px; padding:0 2px; }
        .search-result-time { font-size:11px; color:#475569; margin-top:4px; }
        .search-empty { padding:30px; text-align:center; color:#475569; font-size:13px; }
        .search-loading { padding:20px; text-align:center; color:#64748b; font-size:13px; }

        /* Pin Message Quick Popup */
        .pin-popup-overlay { position:fixed; inset:0; z-index:9700; display:flex; justify-content:center; align-items:center; padding:20px; pointer-events:none; }
        .pin-popup-overlay.active { pointer-events:all; }
        .pin-popup-backdrop { position:absolute; inset:0; background:rgba(0,0,0,0); backdrop-filter:blur(0px); transition:background 0.3s, backdrop-filter 0.3s; }
        .pin-popup-overlay.active .pin-popup-backdrop { background:rgba(0,0,0,0.82); backdrop-filter:blur(16px); }
        .pin-popup-card { background:linear-gradient(145deg,#1a0808,#2d0f0f,#1e1020); border:1px solid rgba(239,68,68,0.4); border-radius:24px; width:100%; max-width:500px; max-height:82vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 40px 100px rgba(0,0,0,0.85), 0 0 40px rgba(239,68,68,0.12), 0 0 0 1px rgba(239,68,68,0.1); position:relative; z-index:1; transform:scale(0.88) translateY(30px); opacity:0; transition:transform 0.35s cubic-bezier(0.2,0.85,0.25,1), opacity 0.3s ease; }
        .pin-popup-overlay.active .pin-popup-card { transform:scale(1) translateY(0); opacity:1; }
        .pin-popup-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:linear-gradient(90deg,#ef4444,#f87171,#ef4444); background-size:200% 100%; animation:ppGradient 3s linear infinite; z-index:2; }
        @keyframes ppGradient { 0%{background-position:0%} 100%{background-position:200%} }
        .pin-popup-header { padding:16px 18px 14px; border-bottom:1px solid rgba(239,68,68,0.15); display:flex; align-items:center; gap:12px; flex-shrink:0; }
        .pin-popup-icon { width:40px; height:40px; background:linear-gradient(135deg,rgba(239,68,68,0.3),rgba(185,28,28,0.2)); border:1px solid rgba(239,68,68,0.45); border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; box-shadow:0 0 15px rgba(239,68,68,0.2); }
        .pin-popup-info { flex:1; min-width:0; }
        .pin-popup-sender { font-family:'AppBold'; font-size:14px; color:#fca5a5; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .pin-popup-time { font-size:11px; color:#6b7280; margin-top:2px; }
        .pin-popup-close { width:32px; height:32px; border-radius:10px; background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.2); color:#f87171; font-size:16px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.18s; flex-shrink:0; }
        .pin-popup-close:hover { background:rgba(239,68,68,0.25); border-color:rgba(239,68,68,0.5); color:#fff; }
        .pin-popup-body { flex:1; overflow-y:auto; padding:20px; scrollbar-width:none; -ms-overflow-style:none; }
        .pin-popup-body::-webkit-scrollbar { display:none; }
        .pin-popup-label { font-size:10px; color:#ef4444; font-weight:bold; letter-spacing:1px; text-transform:uppercase; margin-bottom:10px; display:flex; align-items:center; gap:6px; opacity:0.8; }
        .pin-popup-label::before { content:''; display:inline-block; width:16px; height:2px; background:linear-gradient(90deg,#ef4444,#f87171); border-radius:1px; }
        .pin-popup-loading { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:14px; padding:40px 20px; }
        .pin-popup-spinner { width:42px; height:42px; border:3px solid rgba(239,68,68,0.15); border-top-color:#ef4444; border-radius:50%; animation:ppSpin 0.9s linear infinite; box-shadow:0 0 15px rgba(239,68,68,0.25); }
        @keyframes ppSpin { 100%{transform:rotate(360deg)} }
        .pin-popup-spinner-text { color:#6b7280; font-size:13px; animation:ppPulse 1.4s ease-in-out infinite; }
        @keyframes ppPulse { 0%,100%{opacity:1} 50%{opacity:0.45} }
        .pin-popup-msg-box { background:rgba(239,68,68,0.06); border:1px solid rgba(239,68,68,0.18); border-right:3px solid #ef4444; border-radius:14px; padding:16px 18px; font-size:14px; color:#e2e8f0; line-height:1.8; word-break:break-word; white-space:pre-wrap; }
        .pin-popup-footer { padding:14px 18px; border-top:1px solid rgba(239,68,68,0.12); display:flex; gap:10px; flex-shrink:0; }
        .pin-popup-goto { flex:1; padding:11px; border-radius:13px; background:linear-gradient(135deg,rgba(239,68,68,0.2),rgba(185,28,28,0.15)); border:1px solid rgba(239,68,68,0.4); color:#fca5a5; font-size:13px; cursor:pointer; transition:all 0.2s; font-family:'AppFont'; display:flex; align-items:center; justify-content:center; gap:7px; font-weight:bold; }
        .pin-popup-goto:hover { background:linear-gradient(135deg,rgba(239,68,68,0.35),rgba(185,28,28,0.3)); color:#fff; border-color:#ef4444; transform:translateY(-1px); box-shadow:0 6px 20px rgba(239,68,68,0.25); }
        .pin-popup-cancel { padding:11px 18px; border-radius:13px; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08); color:#6b7280; font-size:13px; cursor:pointer; transition:0.2s; font-family:'AppFont'; }
        .pin-popup-cancel:hover { background:rgba(255,255,255,0.08); color:#94a3b8; }

        /* File share button */
        .file-share-btn { background:linear-gradient(180deg, rgba(129,140,248,0.38), rgba(79,70,229,0.30)); border:1px solid var(--v-primary-border, rgba(99,102,241,0.3)); color:var(--v-accent, #a5b4fc); width:32px; height:32px; border-radius:50%; display:flex; justify-content:center; align-items:center; cursor:pointer; transition:0.2s; font-size:14px; flex-shrink:0; box-shadow: inset 0 1px 0 rgba(255,255,255,0.32), 0 3px 8px rgba(0,0,0,0.30); }
        .file-share-btn:hover { background: linear-gradient(180deg, var(--v-primary-border, rgba(99,102,241,0.45)), rgba(79,70,229,0.42)); color:#fff; border-color:#6366f1; transform: translateY(-1px); box-shadow: inset 0 1px 0 rgba(255,255,255,0.38), 0 6px 14px rgba(0,0,0,0.40); }
        .file-share-btn:active { transform: translateY(1px) scale(0.97); box-shadow: inset 0 2px 5px rgba(0,0,0,0.35); }
        .file-size-tag { font-size:10px; color:#64748b; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.07); border-radius:6px; padding:2px 7px; margin-top:3px; display:inline-block; }
        .srp-overlay { position:fixed; inset:0; z-index:9600; display:flex; justify-content:center; align-items:center; padding:20px; pointer-events:none; }
        .srp-overlay.active { pointer-events:all; }
        .srp-backdrop { position:absolute; inset:0; background:rgba(0,0,0,0); backdrop-filter:blur(0px); transition:background 0.3s, backdrop-filter 0.3s; }
        .srp-overlay.active .srp-backdrop { background:rgba(0,0,0,0.82); backdrop-filter:blur(16px); }
        .srp-card { background:linear-gradient(145deg,#111827,#1a2540); border:1px solid var(--v-primary-border, rgba(99,102,241,0.3)); border-radius:24px; width:100%; max-width:520px; max-height:85vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 40px 100px rgba(0,0,0,0.85), 0 0 0 1px rgba(255,255,255,0.04); position:relative; z-index:1; transform:scale(0.88) translateY(30px); opacity:0; transition:transform 0.35s cubic-bezier(0.2,0.85,0.25,1), opacity 0.3s ease; }
        .srp-overlay.active .srp-card { transform:scale(1) translateY(0); opacity:1; }
        .srp-header { padding:16px 18px 14px; border-bottom:1px solid rgba(255,255,255,0.07); display:flex; align-items:center; gap:12px; flex-shrink:0; background:var(--v-glow-soft, rgba(99,102,241,0.06)); }
        .srp-header-icon { width:40px; height:40px; background:linear-gradient(135deg,var(--v-active-soft, rgba(99,102,241,0.35)),var(--v-primary-strong, rgba(79,70,229,0.25))); border:1px solid rgba(99,102,241,0.4); border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:19px; flex-shrink:0; }
        .srp-header-info { flex:1; min-width:0; }
        .srp-sender { font-family:'AppBold'; font-size:14px; color:var(--v-accent, #a5b4fc); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .srp-time { font-size:11px; color:#475569; margin-top:2px; }
        .srp-close { width:32px; height:32px; border-radius:10px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.08); color:#64748b; font-size:16px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.18s; flex-shrink:0; }
        .srp-close:hover { background:rgba(239,68,68,0.18); border-color:rgba(239,68,68,0.35); color:#ef4444; }
        .srp-body { flex:1; overflow-y:auto; padding:20px; -webkit-overflow-scrolling:touch; scrollbar-width:none; -ms-overflow-style:none; }
        .srp-body::-webkit-scrollbar { display:none; }
        .srp-label { font-size:10px; color:#6366f1; font-weight:bold; letter-spacing:1px; text-transform:uppercase; margin-bottom:10px; display:flex; align-items:center; gap:6px; }
        .srp-label::before { content:''; display:inline-block; width:16px; height:2px; background:linear-gradient(90deg,#6366f1,var(--v-accent, #a5b4fc)); border-radius:1px; }
        .srp-message-box { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08); border-right:3px solid #6366f1; border-radius:14px; padding:16px 18px; font-size:14px; color:#e2e8f0; line-height:1.8; word-break:break-word; white-space:pre-wrap; }
        .srp-message-box mark { background:rgba(99,102,241,0.4); color:#fff; border-radius:4px; padding:1px 4px; font-weight:bold; }
        .srp-loading { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:14px; padding:40px 20px; }
        .srp-spinner { width:42px; height:42px; border:3px solid var(--v-soft, rgba(99,102,241,0.2)); border-top-color:#6366f1; border-radius:50%; animation:srpSpin 0.9s linear infinite; box-shadow:0 0 20px var(--v-primary-border, rgba(99,102,241,0.3)); }
        @keyframes srpSpin { 100% { transform:rotate(360deg); } }
        .srp-spinner-text { color:#64748b; font-size:13px; animation:srpPulse 1.4s ease-in-out infinite; }
        @keyframes srpPulse { 0%,100%{opacity:1} 50%{opacity:0.45} }
        .srp-footer { padding:14px 18px; border-top:1px solid rgba(255,255,255,0.06); display:flex; gap:10px; flex-shrink:0; }
        .srp-goto-btn { flex:1; padding:11px 14px; border-radius:13px; background:var(--v-soft, rgba(99,102,241,0.18)); border:1px solid var(--v-primary-border, rgba(99,102,241,0.4)); color:var(--v-accent, #a5b4fc); font-size:13px; cursor:pointer; transition:all 0.2s; font-family:'AppFont'; display:flex; align-items:center; justify-content:center; gap:7px; font-weight:bold; }
        .srp-goto-btn:hover { background:linear-gradient(135deg,var(--v-primary, #6366f1),var(--v-primary-active, #4f46e5)); color:#fff; border-color:transparent; transform:translateY(-1px); box-shadow:0 8px 22px -12px var(--v-primary-glow, rgba(99,102,241,0.9)); }
        .srp-goto-btn:active { transform:translateY(0) scale(0.99); }
        .srp-cancel-btn { padding:11px 18px; border-radius:13px; background:rgba(148,163,184,0.08); border:1px solid rgba(148,163,184,0.18); color:#94a3b8; font-size:13px; cursor:pointer; transition:all 0.2s; font-family:'AppFont'; }
        .srp-cancel-btn:hover { background:rgba(148,163,184,0.16); color:#e2e8f0; border-color:rgba(148,163,184,0.3); }

        .reaction-container { display: inline-flex; margin-top: 7px; }
        .reaction-btn { width: 30px; height: 30px; border-radius: 10px; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.13); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 16px; transition: background .18s, border-color .18s, transform .18s, box-shadow .2s; box-shadow: inset 0 1px 0 rgba(255,255,255,0.08), 0 4px 10px -6px rgba(0,0,0,0.8); }
        .reaction-btn:hover { background: rgba(255,255,255,0.15); border-color: rgba(165,180,252,0.55); transform: translateY(-1px); box-shadow: inset 0 1px 0 rgba(255,255,255,0.16), 0 8px 14px -8px rgba(0,0,0,0.9); }
        /* Placeholder used while a message is still sending: it occupies the
           final geometry but paints nothing, so confirming the message causes
           no reflow at all. */
        .rx-ghost { visibility: hidden; pointer-events: none; }
        #reactionPickerGlobal { position: fixed; display: flex; gap: 6px; align-items: center; background: var(--v-surface-2, rgba(22,32,52,0.99)); backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px); border: 1px solid rgba(255,255,255,0.18); border-radius: 16px; padding: 10px 12px; box-shadow: 0 16px 50px rgba(0,0,0,0.65), 0 0 0 1px rgba(255,255,255,0.05); z-index: 99999; white-space: nowrap; opacity: 0; pointer-events: none; transform: scale(0.93) translateY(6px); transition: opacity 0.15s ease, transform 0.15s cubic-bezier(0.2,0.8,0.2,1); }
        #reactionPickerGlobal.show { opacity: 1; pointer-events: auto; transform: scale(1) translateY(0); }
        .reaction-emoji { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 22px; cursor: pointer; transition: all 0.15s; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); flex-shrink: 0; }
        .reaction-emoji:hover { transform: scale(1.28); background: var(--v-soft, rgba(99,102,241,0.22)); border-color: rgba(99,102,241,0.45); box-shadow: 0 4px 14px var(--v-active-soft, rgba(99,102,241,0.35)); }
        .reaction-display { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
        .msg-row.me .reaction-display { justify-content: flex-end; }
        .msg-row.other .reaction-display { justify-content: flex-start; }
        .reaction-item { display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 12px; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); font-size: 14px; cursor: pointer; transition: all 0.2s; }
        .reaction-item:hover { background: rgba(255,255,255,0.12); border-color: rgba(255,255,255,0.2); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
        .reaction-item.my-reaction { background: linear-gradient(135deg, var(--v-active-soft, rgba(99,102,241,0.35)), var(--v-primary-strong, rgba(79,70,229,0.25))); border-color: rgba(99,102,241,0.6); box-shadow: 0 0 15px var(--v-primary-border, rgba(99,102,241,0.3)), inset 0 1px 2px rgba(255,255,255,0.1); }
        .reaction-item.my-reaction:hover { background: linear-gradient(135deg, rgba(99,102,241,0.45), var(--v-primary-strong, rgba(79,70,229,0.35))); border-color: rgba(99,102,241,0.7); box-shadow: 0 0 20px rgba(99,102,241,0.4), inset 0 1px 2px rgba(255,255,255,0.15); }
        .reaction-count { font-size: 12px; color: #cbd5e1; font-weight: bold; }
        @keyframes rxPop { 0% { transform: scale(0.72); } 55% { transform: scale(1.22); } 100% { transform: scale(1); } }
        @keyframes rxGlow { 0% { box-shadow: 0 0 0 0 var(--v-primary-glow, rgba(99,102,241,0.55)); } 100% { box-shadow: 0 0 0 12px rgba(99,102,241,0); } }
        .reaction-item.rx-pop { animation: rxPop 0.34s cubic-bezier(0.2,0.8,0.2,1), rxGlow 0.5s ease-out; }
        @media (prefers-reduced-motion: reduce) { .reaction-item.rx-pop { animation: none; } }
        @keyframes reactionFadeIn { from { opacity: 0; transform: translateY(6px) scale(0.93); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .reaction-tooltip { position: fixed; background: var(--v-surface, rgba(15,23,42,0.98)); backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px); color: #e2e8f0; padding: 8px 12px; border-radius: 10px; font-size: 12px; pointer-events: none; z-index: 10000; border: 1px solid rgba(255,255,255,0.15); box-shadow: 0 8px 30px rgba(0,0,0,0.6); white-space: nowrap; max-width: 200px; overflow: hidden; text-overflow: ellipsis; animation: tooltipFadeIn 0.15s ease; }
        @keyframes tooltipFadeIn { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }

        .ap-overlay { position: fixed; inset: 0; background: rgba(2,6,23,0.72); backdrop-filter: blur(7px); -webkit-backdrop-filter: blur(7px); z-index: 99998; display: flex; align-items: flex-end; justify-content: center; opacity: 0; pointer-events: none; transition: opacity .22s ease; }
        .ap-overlay.open { opacity: 1; pointer-events: auto; }
        .ap-card { width: 100%; max-width: 430px; background: linear-gradient(180deg, var(--v-surface-2, #1e293b), var(--v-surface, #16203a)); border: 1px solid var(--v-border-strong, rgba(255,255,255,0.14)); border-bottom: 0; border-radius: 22px 22px 0 0; padding: 16px 16px calc(18px + env(safe-area-inset-bottom, 0px)); transform: translateY(20px); transition: transform .26s cubic-bezier(.2,.8,.2,1); box-shadow: 0 -22px 60px rgba(0,0,0,0.55); max-height: 88vh; overflow-y: auto; }
        .ap-overlay.open .ap-card { transform: translateY(0); }
        @media (min-width: 600px) { .ap-overlay { align-items: center; } .ap-card { border-radius: 22px; border-bottom: 1px solid var(--v-border-strong, rgba(255,255,255,0.14)); max-height: 86vh; } }
        .ap-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 14px; }
        .ap-head-title { display: flex; align-items: center; gap: 8px; font-family: 'AppBold'; font-size: 15px; color: #f1f5f9; }
        .ap-head-ico { width: 30px; height: 30px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 15px; background: var(--v-primary-soft, rgba(99,102,241,0.14)); border: 1px solid var(--v-primary-border, rgba(99,102,241,0.4)); }
        .ap-close { width: 32px; height: 32px; flex: 0 0 32px; border-radius: 10px; border: 1px solid rgba(148,163,184,0.2); background: rgba(148,163,184,0.08); color: #94a3b8; cursor: pointer; font-size: 14px; line-height: 1; display: flex; align-items: center; justify-content: center; }
        .ap-close:hover { background: rgba(239,68,68,0.16); border-color: rgba(239,68,68,0.4); color: #fca5a5; }
        .ap-label { display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 12px; color: #94a3b8; margin-bottom: 9px; }
        .ap-label-hint { color: var(--v-accent, #a5b4fc); font-family: 'AppBold'; font-size: 11.5px; }
        .ap-swatches { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 8px; }
        .ap-sw { position: relative; aspect-ratio: 1 / 1; border-radius: 12px; border: 2px solid transparent; cursor: pointer; padding: 0; transition: transform .12s ease, border-color .15s ease, box-shadow .15s ease; -webkit-tap-highlight-color: transparent; }
        .ap-sw:active { transform: scale(0.9); }
        .ap-sw.active { border-color: #fff; box-shadow: 0 0 0 3px var(--v-primary-soft, rgba(99,102,241,0.3)), 0 4px 14px -4px rgba(0,0,0,0.6); }
        .ap-row { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
        .ap-custom { flex: 1 1 auto; display: flex; align-items: center; gap: 9px; height: 42px; padding: 0 12px; border-radius: 12px; border: 1px dashed var(--v-border-strong, rgba(255,255,255,0.18)); background: rgba(255,255,255,0.04); color: #cbd5e1; font-size: 12px; cursor: pointer; min-width: 0; }
        .ap-custom:hover { border-color: var(--v-primary-border, rgba(99,102,241,0.4)); color: #fff; }
        .ap-custom input[type="color"] { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
        .ap-custom-dot { width: 22px; height: 22px; flex: 0 0 22px; border-radius: 8px; background: var(--v-primary, #6366f1); box-shadow: inset 0 0 0 1px rgba(255,255,255,0.35); }
        .ap-reset { flex: 0 0 auto; height: 42px; padding: 0 13px; border-radius: 12px; border: 1px solid rgba(148,163,184,0.2); background: rgba(148,163,184,0.08); color: #cbd5e1; font-family: inherit; font-size: 12px; cursor: pointer; }
        .ap-reset:hover { background: rgba(148,163,184,0.16); color: #fff; }
        .ap-divider { height: 1px; background: var(--v-border, rgba(255,255,255,0.08)); margin: 16px 0 14px; }
        .ap-fs { display: flex; align-items: center; gap: 10px; direction: ltr; }
        .ap-fs-btn { width: 42px; height: 38px; flex: 0 0 42px; border-radius: 11px; border: 1px solid var(--v-border-strong, rgba(255,255,255,0.14)); background: rgba(255,255,255,0.05); color: #dbe3ee; font-family: 'AppBold'; font-size: 13px; cursor: pointer; transition: background .15s, transform .1s; }
        .ap-fs-btn:hover { background: var(--v-primary-soft, rgba(99,102,241,0.16)); }
        .ap-fs-btn:active { transform: scale(0.94); }
        #apFsRange { flex: 1 1 auto; -webkit-appearance: none; appearance: none; height: 6px; border-radius: 99px; background: linear-gradient(90deg, var(--v-primary, #6366f1) 0%, var(--v-primary, #6366f1) var(--ap-fill, 25%), rgba(148,163,184,0.22) var(--ap-fill, 25%)); outline: none; min-width: 0; }
        #apFsRange::-webkit-slider-thumb { -webkit-appearance: none; width: 22px; height: 22px; border-radius: 50%; background: #fff; border: 3px solid var(--v-primary, #6366f1); box-shadow: 0 3px 10px rgba(0,0,0,0.45); cursor: pointer; }
        #apFsRange::-moz-range-thumb { width: 16px; height: 16px; border-radius: 50%; background: #fff; border: 3px solid var(--v-primary, #6366f1); box-shadow: 0 3px 10px rgba(0,0,0,0.45); cursor: pointer; }
        .ap-preview { margin-top: 13px; padding: 12px 13px; border-radius: 14px; background: rgba(255,255,255,0.04); border: 1px dashed var(--v-border-strong, rgba(255,255,255,0.16)); }
        .ap-preview-name { font-size: 10.5px; color: #7c8aa0; margin-bottom: 5px; }
        .ap-preview-text { font-size: clamp(12.5px, calc(14px * var(--v-fs, 1)), 18.5px); line-height: 1.6; color: #e2e8f0; word-break: break-word; }

        #emojiPicker {
            background: linear-gradient(145deg, rgba(10,15,30,0.99), rgba(20,30,50,0.98));
            border-top: 1px solid var(--v-soft, rgba(99,102,241,0.2));
            display: none;
            flex-direction: column;
            max-height: 280px;
            direction: rtl;
        }
        .ep-head { display: flex; align-items: center; justify-content: space-between; gap: 6px; padding: 6px 10px 0; }
        .ep-cats { display: flex; gap: 2px; flex: 1; overflow-x: auto; scrollbar-width: none; }
        .ep-cats::-webkit-scrollbar { display: none; }
        .ep-cat { background: none; border: none; font-size: 17px; cursor: pointer; padding: 4px 7px; border-radius: 8px; opacity: 0.55; transition: opacity 0.15s, background 0.15s; line-height: 1; flex-shrink: 0; -webkit-tap-highlight-color: transparent; }
        .ep-cat:hover { opacity: 0.85; }
        .ep-cat.active { opacity: 1; background: var(--v-soft, var(--v-soft, rgba(99,102,241,0.18))); }
        .ep-close { background: none; border: none; color: #94a3b8; cursor: pointer; font-size: 13px; padding: 4px 9px; border-radius: 8px; line-height: 1; flex-shrink: 0; }
        .ep-close:hover { color: #fff; background: rgba(255,255,255,0.08); }
        .ep-search { padding: 6px 10px; }
        .ep-search input { width: 100%; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); border-radius: 10px; color: #fff; font-family: inherit; font-size: 13px; padding: 7px 12px; outline: none; transition: border-color 0.15s; }
        .ep-search input:focus { border-color: var(--v-primary-border, rgba(99,102,241,0.45)); }
        .ep-search input::placeholder { color: #64748b; }
        .ep-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(36px, 1fr)); gap: 2px; overflow-y: auto; padding: 4px 10px 12px; direction: ltr; flex: 1; min-height: 0; scrollbar-width: thin; scrollbar-color: var(--v-primary-border, rgba(99,102,241,0.3)) transparent; }
        .ep-grid::-webkit-scrollbar { width: 4px; }
        .ep-grid::-webkit-scrollbar-thumb { background: var(--v-primary-border, rgba(99,102,241,0.3)); border-radius: 4px; }
        .ep-sec { grid-column: 1 / -1; font-size: 11px; color: var(--v-accent, #a5b4fc); font-weight: bold; padding: 7px 2px 3px; text-align: right; direction: rtl; }
        .ep-empty { grid-column: 1 / -1; text-align: center; color: #64748b; font-size: 12px; padding: 16px 0; }
        .emoji-pick-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            padding: 5px 4px;
            border-radius: 8px;
            line-height: 1;
            transition: background 0.12s, transform 0.1s;
            -webkit-tap-highlight-color: transparent;
        }
        .emoji-pick-btn:hover { background: var(--v-soft, rgba(99,102,241,0.18)); transform: scale(1.25); }
        .emoji-pick-btn:active { transform: scale(0.95); }

        .dm-overlay { position: fixed; inset: 0; background: rgba(2,6,23,0.74); backdrop-filter: blur(7px); -webkit-backdrop-filter: blur(7px); z-index: 99998; display: flex; align-items: flex-end; justify-content: center; opacity: 0; pointer-events: none; transition: opacity .22s ease; }
        .dm-overlay.open { opacity: 1; pointer-events: auto; }
        .dm-card { width: 100%; max-width: 440px; background: linear-gradient(180deg, var(--v-surface-2, #1e293b), var(--v-surface, #16203a)); border: 1px solid var(--v-border-strong, rgba(255,255,255,0.14)); border-bottom: 0; border-radius: 24px 24px 0 0; padding: 16px 16px calc(18px + env(safe-area-inset-bottom, 0px)); transform: translateY(22px); transition: transform .28s cubic-bezier(.2,.8,.2,1); box-shadow: 0 -22px 60px rgba(0,0,0,0.6); max-height: 90vh; overflow-y: auto; }
        .dm-overlay.open .dm-card { transform: translateY(0); }
        @media (min-width: 600px) { .dm-overlay { align-items: center; } .dm-card { border-radius: 24px; border-bottom: 1px solid var(--v-border-strong, rgba(255,255,255,0.14)); max-height: 86vh; } }
        .dm-head { display: flex; align-items: center; gap: 11px; margin-bottom: 14px; }
        .dm-ico { flex: 0 0 40px; width: 40px; height: 40px; border-radius: 13px; display: flex; align-items: center; justify-content: center; background: rgba(239,68,68,0.14); border: 1px solid rgba(239,68,68,0.3); font-size: 19px; }
        .dm-head-texts { flex: 1; min-width: 0; }
        .dm-title { font-family: 'AppBold'; font-size: 14px; color: #f1f5f9; line-height: 1.6; }
        .dm-sub { font-size: 10.5px; color: #8fa0bd; line-height: 1.7; margin-top: 1px; }
        .dm-x { flex: 0 0 30px; width: 30px; height: 30px; border-radius: 9px; border: 1px solid var(--v-border-strong, rgba(255,255,255,0.14)); background: rgba(255,255,255,0.05); color: #94a3b8; font-size: 13px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .dm-opts { display: flex; flex-direction: column; gap: 8px; }
        .dm-opt { position: relative; display: flex; align-items: center; gap: 11px; padding: 11px 13px; border-radius: 14px; border: 1px solid var(--v-border, rgba(255,255,255,0.1)); background: rgba(255,255,255,0.03); cursor: pointer; transition: border-color .16s, background .16s; }
        .dm-opt:hover { background: rgba(255,255,255,0.06); }
        .dm-opt input { position: absolute; opacity: 0; pointer-events: none; }
        .dm-opt-dot { flex: 0 0 18px; width: 18px; height: 18px; border-radius: 50%; border: 2px solid rgba(148,163,184,0.45); position: relative; transition: border-color .16s; }
        .dm-opt-dot::after { content: ''; position: absolute; inset: 3px; border-radius: 50%; background: var(--v-primary, #6366f1); transform: scale(0); transition: transform .16s; }
        .dm-opt input:checked ~ .dm-opt-dot { border-color: var(--v-primary, #6366f1); }
        .dm-opt input:checked ~ .dm-opt-dot::after { transform: scale(1); }
        .dm-opt:has(input:checked) { border-color: var(--v-primary-border, rgba(99,102,241,0.45)); background: var(--v-primary-soft, rgba(99,102,241,0.1)); }
        .dm-opt-body { flex: 1; min-width: 0; }
        .dm-opt-title { display: block; font-size: 12.5px; color: #e2e8f0; line-height: 1.6; }
        .dm-opt-desc { display: block; font-size: 10px; color: #8494b0; line-height: 1.6; margin-top: 1px; }
        .dm-count { flex: 0 0 auto; font-size: 10px; font-weight: bold; padding: 2px 8px; border-radius: 7px; background: rgba(148,163,184,0.14); color: #b6c4dc; font-variant-numeric: tabular-nums; }
        .dm-note { margin-top: 12px; padding: 10px 12px; border-radius: 12px; background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.22); font-size: 10.5px; line-height: 1.85; color: #f0a8a8; }
        .dm-actions { display: flex; gap: 9px; margin-top: 14px; }
        .dm-btn { flex: 1 1 0; height: 44px; border-radius: 13px; font-family: 'AppBold'; font-size: 12.5px; cursor: pointer; border: 1px solid transparent; transition: transform .12s, background .18s; }
        .dm-btn:active { transform: scale(0.98); }
        .dm-btn.cancel { background: rgba(148,163,184,0.13); color: #cbd5e1; border-color: rgba(148,163,184,0.18); }
        .dm-btn.go { background: linear-gradient(135deg, #ef4444, #dc2626); color: #fff; box-shadow: 0 10px 26px -10px rgba(239,68,68,0.7); }
        .dm-btn.go[disabled] { opacity: 0.55; cursor: not-allowed; box-shadow: none; }
    </style>
<style id="vFontFaces"><?php echo veyrax_font_css($pdo); ?></style>
</head>
<body onclick="hideCtx()">
<?php veyrax_eye_sprite(); ?>
<!-- ── 3D icon sprite ───────────────────────────────────────────────────────
     Gradients and symbols live once here; every bubble just <use>s them. That
     keeps a hundred file bubbles cheap and means the icons stay crisp at any
     size without shipping bitmaps. Palette is matched to assets/icons3d/*.png
     so the file box sits next to the reaction picker without clashing. -->
<svg width="0" height="0" style="position:absolute;overflow:hidden" aria-hidden="true" focusable="false">
    <defs>
        <linearGradient id="v3PageGrad" x1="0" y1="0" x2="0.85" y2="1">
            <stop offset="0" stop-color="#F6F1FE"/>
            <stop offset="0.55" stop-color="#E7DDF8"/>
            <stop offset="1" stop-color="#D3C4EE"/>
        </linearGradient>
        <linearGradient id="v3PageSide" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#C0AEDD"/>
            <stop offset="1" stop-color="#9E8AC4"/>
        </linearGradient>
        <linearGradient id="v3FoldGrad" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0" stop-color="#FFFFFF"/>
            <stop offset="1" stop-color="#C6B6E4"/>
        </linearGradient>
        <linearGradient id="v3LinkBack" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#B7A9EA"/>
            <stop offset="1" stop-color="#7C6BC9"/>
        </linearGradient>
        <linearGradient id="v3LinkFront" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#9C8CE4"/>
            <stop offset="1" stop-color="#5B4BB4"/>
        </linearGradient>
        <linearGradient id="v3TrayGrad" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#D7C9F4"/>
            <stop offset="1" stop-color="#AC97DB"/>
        </linearGradient>
        <linearGradient id="v3TraySide" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#A895D4"/>
            <stop offset="1" stop-color="#8270B4"/>
        </linearGradient>
        <linearGradient id="v3ArrowGrad" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0" stop-color="#7A68D8"/>
            <stop offset="1" stop-color="#4C3CA8"/>
        </linearGradient>
    </defs>

    <!-- Document -->
    <symbol id="v3i-file" viewBox="0 0 32 32">
        <ellipse cx="16.5" cy="28.6" rx="8.4" ry="2" fill="#1B1630" opacity="0.20"/>
        <path d="M8.6 5.6h9.6l6.2 6.2v15.6a1.8 1.8 0 0 1-1.8 1.8H8.6a1.8 1.8 0 0 1-1.8-1.8V7.4a1.8 1.8 0 0 1 1.8-1.8z" fill="url(#v3PageSide)"/>
        <path d="M7 4h9.6l6.2 6.2v15.6a1.8 1.8 0 0 1-1.8 1.8H7a1.8 1.8 0 0 1-1.8-1.8V5.8A1.8 1.8 0 0 1 7 4z" fill="url(#v3PageGrad)"/>
        <path d="M16.6 4l6.2 6.2h-4.9a1.3 1.3 0 0 1-1.3-1.3z" fill="url(#v3FoldGrad)"/>
        <path d="M7 4h9.6l6.2 6.2v1.1H7z" fill="#FFFFFF" opacity="0.45"/>
        <rect x="7.6" y="13.6" width="10.4" height="1.7" rx="0.85" fill="currentColor" opacity="0.9"/>
        <rect x="7.6" y="17.6" width="7.8" height="1.5" rx="0.75" fill="currentColor" opacity="0.62"/>
        <rect x="7.6" y="20.9" width="5.6" height="1.5" rx="0.75" fill="currentColor" opacity="0.42"/>
    </symbol>

    <!-- Copy link -->
    <symbol id="v3i-copy" viewBox="0 0 32 32">
        <ellipse cx="16" cy="28.4" rx="7.2" ry="1.8" fill="#1B1630" opacity="0.18"/>
        <g transform="rotate(-42 16 16)" fill="none" stroke-linecap="round" stroke-linejoin="round">
            <rect x="2.9" y="11.2" width="15.2" height="9.6" rx="4.8" stroke="url(#v3LinkBack)" stroke-width="3.6"/>
            <rect x="2.9" y="11.2" width="15.2" height="9.6" rx="4.8" stroke="#FFFFFF" stroke-width="1" opacity="0.28" transform="translate(0,-0.7)"/>
            <rect x="13.9" y="11.2" width="15.2" height="9.6" rx="4.8" stroke="url(#v3LinkFront)" stroke-width="3.6"/>
            <rect x="13.9" y="11.2" width="15.2" height="9.6" rx="4.8" stroke="#FFFFFF" stroke-width="1" opacity="0.42" transform="translate(0,-0.7)"/>
        </g>
    </symbol>

    <!-- Download -->
    <symbol id="v3i-download" viewBox="0 0 32 32">
        <ellipse cx="16" cy="28.6" rx="7.6" ry="1.9" fill="#1B1630" opacity="0.20"/>
        <path d="M5.4 21.6h21.2v4.2a2.2 2.2 0 0 1-2.2 2.2H7.6a2.2 2.2 0 0 1-2.2-2.2z" fill="url(#v3TraySide)" transform="translate(0.9,1.5)"/>
        <path d="M5.4 21.6h21.2v4.2a2.2 2.2 0 0 1-2.2 2.2H7.6a2.2 2.2 0 0 1-2.2-2.2z" fill="url(#v3TrayGrad)"/>
        <rect x="5.4" y="21.6" width="21.2" height="2.2" rx="1.1" fill="#FFFFFF" opacity="0.55"/>
        <rect x="13.9" y="2.8" width="4.2" height="12.4" rx="2.1" fill="url(#v3ArrowGrad)"/>
        <path d="M16 22.2l-7.1-7.7h14.2z" fill="url(#v3ArrowGrad)"/>
        <rect x="13.9" y="2.8" width="1.6" height="12.4" rx="0.8" fill="#FFFFFF" opacity="0.42"/>
        <path d="M16 20.9l-2.4-2.6h4.8z" fill="#2A1F5C" opacity="0.18"/>
    <!-- Password visibility symbols are printed by veyrax_eye_sprite() so the
         login document and the app document share one copy. -->
</svg>

<div id="reactionPickerGlobal">
    <div class="reaction-emoji" data-emoji="❤️"><img class="rx-3d rx-3d-pick" src="assets/icons3d/heart_red.png" alt=""></div>
    <div class="reaction-emoji" data-emoji="👍"><img class="rx-3d rx-3d-pick" src="assets/icons3d/thumbsup.png" alt=""></div>
    <div class="reaction-emoji" data-emoji="😂"><img class="rx-3d rx-3d-pick" src="assets/icons3d/joy.png" alt=""></div>
    <div class="reaction-emoji" data-emoji="😮"><img class="rx-3d rx-3d-pick" src="assets/icons3d/wow.png" alt=""></div>
    <div class="reaction-emoji" data-emoji="😢"><img class="rx-3d rx-3d-pick" src="assets/icons3d/cry.png" alt=""></div>
</div>

<div id="appearanceOverlay" class="ap-overlay" onclick="closeAppearance()">
    <div class="ap-card" onclick="event.stopPropagation()" role="dialog" aria-modal="true" aria-label="ظاهر و تم">
        <div class="ap-head">
            <div class="ap-head-title"><span class="ap-head-ico">🎨</span> ظاهر و تم</div>
            <button type="button" class="ap-close" onclick="closeAppearance()" aria-label="بستن">✕</button>
        </div>
        <div class="ap-label"><span>رنگ اصلی</span><span class="ap-label-hint" id="apColorHint">#6366f1</span></div>
        <div class="ap-swatches" id="apSwatches"></div>
        <div class="ap-row">
            <label class="ap-custom" for="apColorInput">
                <input type="color" id="apColorInput" value="#6366f1" oninput="apPickCustom(this.value)">
                <span class="ap-custom-dot" id="apCustomDot"></span>
                <span>رنگ دلخواه</span>
            </label>
            <button type="button" class="ap-reset" onclick="apReset()">پیش‌فرض</button>
        </div>
        <div class="ap-divider"></div>
        <div class="ap-label"><span>اندازه فونت</span><span class="ap-label-hint" id="apFsLabel">۱۰۰٪</span></div>
        <div class="ap-fs">
            <button type="button" class="ap-fs-btn" onclick="apStepFont(-1)" aria-label="کوچک‌تر">A−</button>
            <input type="range" id="apFsRange" min="0" max="4" step="1" value="1" oninput="apSetFont(this.value)" aria-label="اندازه فونت">
            <button type="button" class="ap-fs-btn" onclick="apStepFont(1)" aria-label="بزرگ‌تر">A+</button>
        </div>
        <div class="ap-preview">
            <div class="ap-preview-name">پیش‌نمایش</div>
            <div class="ap-preview-text">سلام! این اندازهٔ متن پیام‌ها و فهرست گفتگوهاست.</div>
        </div>
    </div>
</div>

<div id="deleteMineOverlay" class="dm-overlay" onclick="closeDeleteMine()">
    <div class="dm-card" onclick="event.stopPropagation()" role="dialog" aria-modal="true" aria-label="حذف پیام‌های من">
        <div class="dm-head">
            <div class="dm-ico">🗑</div>
            <div class="dm-head-texts">
                <div class="dm-title">حذف پیام‌های من</div>
                <div class="dm-sub">پیام‌هایی که خودتان فرستاده‌اید برای همیشه پاک می‌شوند</div>
            </div>
            <button type="button" class="dm-x" onclick="closeDeleteMine()" aria-label="بستن">✕</button>
        </div>
        <div class="dm-opts">
            <label class="dm-opt">
                <input type="radio" name="dmScope" value="room" checked onchange="dmSyncGo()">
                <span class="dm-opt-dot"></span>
                <span class="dm-opt-body">
                    <span class="dm-opt-title">فقط همین گفتگو</span>
                    <span class="dm-opt-desc" id="dmRoomName">گفتگوی فعلی</span>
                </span>
                <span class="dm-count" id="dmCountRoom">۰</span>
            </label>
            <label class="dm-opt">
                <input type="radio" name="dmScope" value="dm" onchange="dmSyncGo()">
                <span class="dm-opt-dot"></span>
                <span class="dm-opt-body">
                    <span class="dm-opt-title">همهٔ گفتگوهای خصوصی</span>
                    <span class="dm-opt-desc">پیام‌های دونفره با هر کاربر</span>
                </span>
                <span class="dm-count" id="dmCountDm">۰</span>
            </label>
            <label class="dm-opt">
                <input type="radio" name="dmScope" value="all" onchange="dmSyncGo()">
                <span class="dm-opt-dot"></span>
                <span class="dm-opt-body">
                    <span class="dm-opt-title">همهٔ پیام‌های من</span>
                    <span class="dm-opt-desc">در همهٔ گفتگوها و اتاق‌ها</span>
                </span>
                <span class="dm-count" id="dmCountAll">۰</span>
            </label>
        </div>
        <div class="dm-note" id="dmNote">این عملیات قابل بازگشت نیست و پیام‌ها برای همهٔ اعضا حذف می‌شوند.</div>
        <div class="dm-actions">
            <button type="button" class="dm-btn cancel" onclick="closeDeleteMine()">انصراف</button>
            <button type="button" class="dm-btn go" id="dmGoBtn" onclick="confirmDeleteMine()">حذف کن</button>
        </div>
    </div>
</div>

<div id="pinModal" class="pin-modal-overlay">
    <div class="pin-modal-backdrop" onclick="closePinModal()"></div>
    <div class="pin-modal-card">
        <div class="pin-modal-header">
            <div class="pin-modal-header-icon">📌</div>
            <div class="pin-modal-title-wrap">
                <span class="pin-modal-title">پیام‌های پین شده</span>
                <span class="pin-modal-subtitle" id="pinModalSubtitle">۰ پیام</span>
            </div>
            <button class="pin-modal-close" onclick="closePinModal()">✕</button>
        </div>
        <div class="pin-modal-body" id="pinModalBody">
            <div class="pin-modal-empty">
                <span class="pin-modal-empty-icon">📌</span>
                <span class="pin-modal-empty-text">پیامی پین نشده است</span>
            </div>
        </div>
        <div class="pin-modal-footer" id="pinModalFooter">
            <button class="pin-modal-unpin-all" onclick="unpinAll()">🗑 حذف همه پین‌ها</button>
        </div>
    </div>
</div>

<div id="pinViewer" class="pin-viewer-overlay">
    <div class="pin-viewer-backdrop" onclick="closePinViewer()"></div>
    <div class="pin-viewer-card">
        <div class="pin-viewer-header">
            <div style="width:34px;height:34px;background:var(--v-soft, rgba(99,102,241,0.2));border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;">📌</div>
            <div class="pin-viewer-sender">
                <span class="pin-viewer-sender-name" id="pinViewerSender"></span>
                <span class="pin-viewer-sender-time" id="pinViewerTime"></span>
            </div>
            <button class="pin-viewer-close" onclick="closePinViewer()">✕</button>
        </div>
        <div class="pin-viewer-body" id="pinViewerBody"></div>
        <div class="pin-viewer-nav" id="pinViewerNav" style="display:none; align-items:center; justify-content:center; gap:10px; padding:8px 16px 0; flex-shrink:0;">
            <button class="pin-bar-nav-btn" onclick="navigatePinAndView(-1)" title="پین قبلی" style="width:32px;height:32px;">‹</button>
            <span id="pinViewerCount" style="font-size:11px;color:#6366f1;font-weight:bold;"></span>
            <button class="pin-bar-nav-btn" onclick="navigatePinAndView(1)" title="پین بعدی" style="width:32px;height:32px;">›</button>
        </div>
        <div class="pin-viewer-footer">
            <button class="pin-viewer-goto" id="pinViewerGoto" onclick="closePinViewer();scrollToPinned(_currentViewPin)">↩ رفتن به پیام</button>
            <button class="pin-viewer-unpin" id="pinViewerUnpin" style="display:none" onclick="closePinViewer();unpinMsgById(_currentViewPin)">🗑 حذف پین</button>
        </div>
    </div>
</div>

<div id="toast"></div>

<div id="historyOverlay" class="history-overlay">
    <div class="history-spinner-wrap">
        <div class="history-spinner"></div>
        <div class="history-spinner-inner"></div>
        <div class="history-spinner-dot"></div>
    </div>
    <div class="history-text">در حال بارگذاری تاریخچه پیام‌ها...</div>
</div>

<!-- Pin Message Quick Popup -->

<div id="profileModal" style="display:none;position:fixed;inset:0;z-index:9800;background:rgba(0,0,0,0.85);backdrop-filter:blur(16px);display:none;align-items:center;justify-content:center;padding:20px;">
    <div style="background:linear-gradient(145deg,#0f172a,#1e293b);border:1px solid var(--v-primary-border, rgba(99,102,241,0.3));border-radius:24px;width:100%;max-width:420px;padding:0;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,0.8);">
        <div style="padding:20px 22px 16px;border-bottom:1px solid var(--v-soft, rgba(99,102,241,0.15));display:flex;align-items:center;gap:14px;">
            <div id="profileSticker" style="font-size:44px;line-height:1;"></div>
            <div style="flex:1;min-width:0;">
                <div id="profileUsername" style="font-size:18px;font-weight:bold;color:#e2e8f0;"></div>
                <div id="profileJoinDate" style="font-size:12px;color:#64748b;margin-top:3px;"></div>
            </div>
            <button onclick="closeMyProfile()" style="width:32px;height:32px;border-radius:10px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);color:#94a3b8;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;">✕</button>
        </div>
        <div style="padding:18px 22px;" id="profileBody">
            <div class="profile-row profile-row-sec">
                <span class="profile-label">🔐 کد امنیتی</span>
                <span class="profile-sec-wrap">
                    <span id="profileSecCode" class="profile-val">—</span>
                    <button type="button" id="profileSecCopyBtn" class="profile-copy-btn" onclick="copySecCode()" title="کپی کد امنیتی" aria-label="کپی کد امنیتی" style="display:none;">📋</button>
                </span>
            </div>
            <div class="profile-row">
                <span class="profile-label">📱 موبایل</span>
                <span id="profilePhone" class="profile-val"></span>
            </div>
            <div class="profile-row" id="profileRoomRow" style="display:none;">
                <span class="profile-label">🏠 اتاق ساخته‌شده</span>
                <span id="profileRoom" class="profile-val"></span>
            </div>
        </div>
    </div>
</div>

<style>
/* ── Auth settings modal ───────────────────────────────────────────────────
   Replaces a wall of inline styles. Everything is scoped to #phoneModal so it
   cannot leak into the chat UI, and every colour comes from the theme vars so
   a custom theme repaints it for free. */
#phoneModal {
    position: fixed; inset: 0; z-index: 9800; display: none;
    align-items: center; justify-content: center; padding: 16px;
    background: rgba(6, 10, 20, 0.78);
    -webkit-backdrop-filter: blur(14px); backdrop-filter: blur(14px);
}
#phoneModal .pa-card {
    width: 100%; max-width: 404px;
    max-height: calc(100dvh - 32px); overflow-y: auto; overscroll-behavior: contain;
    padding: 20px;
    border-radius: 26px;
    border: 1px solid var(--v-primary-border, rgba(99, 102, 241, 0.34));
    background:
        radial-gradient(120% 60% at 50% -8%, var(--v-primary-soft, rgba(99,102,241,.20)), transparent 62%),
        linear-gradient(165deg, #1b2540, #121a2c);
    box-shadow: 0 34px 80px -26px rgba(0, 0, 0, 0.9), inset 0 1px 0 rgba(255, 255, 255, 0.05);
    animation: paIn 0.28s cubic-bezier(0.2, 0.9, 0.3, 1.2) both;
}
@keyframes paIn { from { opacity: 0; transform: translateY(10px) scale(0.985); } to { opacity: 1; transform: none; } }

#phoneModal .pa-head { display: flex; align-items: center; gap: 11px; margin-bottom: 18px; }
#phoneModal .pa-mark {
    flex: 0 0 40px; width: 40px; height: 40px; border-radius: 13px;
    display: grid; place-items: center;
    background: linear-gradient(160deg, rgba(255,255,255,.10), rgba(255,255,255,.02));
    border: 1px solid var(--v-primary-border, rgba(99,102,241,.34));
}
#phoneModal .pa-mark img { width: 24px; height: 24px; object-fit: contain; }
#phoneModal .pa-headtext { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 1px; }
#phoneModal .pa-headtext b { font-size: 14.5px; color: #eaf0fb; font-family: 'AppBold', inherit; }
#phoneModal .pa-headtext small { font-size: 11px; color: #7f92ad; }
#phoneModal .pa-x {
    flex: 0 0 30px; width: 30px; height: 30px; border-radius: 10px; cursor: pointer;
    border: 1px solid rgba(255,255,255,.10); background: rgba(255,255,255,.05);
    color: #94a3b8; font-size: 13px; line-height: 1; display: grid; place-items: center;
    transition: background .15s ease, color .15s ease, border-color .15s ease;
}
#phoneModal .pa-x:hover { background: rgba(239,68,68,.14); color: #fca5a5; border-color: rgba(239,68,68,.35); }

#phoneModal .pa-state { display: none; }
#phoneModal .pa-lede { margin: 0 0 12px; font-size: 12.5px; color: #93a4bd; line-height: 1.75; }

#phoneModal .pa-tip {
    display: flex; gap: 8px; align-items: flex-start;
    padding: 11px 12px; margin-bottom: 16px;
    border-radius: 13px; font-size: 11.5px; line-height: 1.75; color: #8fa3c0;
    background: var(--v-glow-soft, rgba(99,102,241,.06));
    border: 1px solid var(--v-soft, rgba(99,102,241,.16));
}
#phoneModal .pa-tip b { color: var(--v-accent, #a5b4fc); }
#phoneModal .pa-tip-ic { flex: 0 0 18px; width: 18px; height: 18px; object-fit: contain; margin-top: 1px; }

#phoneModal .pa-label {
    display: block; margin: 0 0 6px;
    font-size: 11.5px; font-weight: bold; color: var(--v-accent, #a5b4fc);
}
#phoneModal .pa-field {
    display: flex; align-items: center; gap: 8px;
    padding: 0 12px; margin-bottom: 13px;
    border-radius: 12px;
    background: rgba(255,255,255,.045);
    border: 1px solid var(--v-primary-border, rgba(99,102,241,.24));
    transition: border-color .16s ease, box-shadow .16s ease;
}
#phoneModal .pa-field:focus-within {
    border-color: var(--v-primary, #6366f1);
    box-shadow: 0 0 0 3px var(--v-primary-soft, rgba(99,102,241,.18));
}
#phoneModal .pa-fi { flex: 0 0 17px; width: 17px; height: 17px; object-fit: contain; opacity: .85; }
#phoneModal .pa-field > input {
    flex: 1 1 auto; min-width: 0; width: auto;
    padding: 12px 0; margin: 0; border: 0; background: none; outline: none;
    color: #e2e8f0; font-size: 15px; font-family: inherit;
}
#phoneModal .pa-field > input::placeholder { color: #5b6c85; font-size: 12.5px; }
#phoneModal .v-pass-row { margin-bottom: 4px; }
#phoneModal .v-pass-row > input {
    padding: 12px 13px; border-radius: 12px;
    background: rgba(255,255,255,.045);
    border: 1px solid var(--v-primary-border, rgba(99,102,241,.24));
    color: #e2e8f0; font-size: 14px; outline: none;
    transition: border-color .16s ease, box-shadow .16s ease;
}
#phoneModal .v-pass-row > input:focus {
    border-color: var(--v-primary, #6366f1);
    box-shadow: 0 0 0 3px var(--v-primary-soft, rgba(99,102,241,.18));
}
#phoneModal .pa-hint { font-size: 10.5px; color: #7b8da8; margin-bottom: 14px; line-height: 1.7; }

#phoneModal .pa-btn {
    width: 100%; padding: 12px; border-radius: 13px; cursor: pointer;
    font-family: inherit; font-size: 13.5px; font-weight: bold;
    display: inline-flex; align-items: center; justify-content: center; gap: 7px;
    transition: transform .15s ease, filter .15s ease, box-shadow .15s ease;
}
#phoneModal .pa-btn:active { transform: scale(.985); }
#phoneModal .pa-btn[disabled] { opacity: .65; cursor: progress; }
#phoneModal .pa-btn-primary {
    border: 1px solid var(--v-primary-border, rgba(99,102,241,.5));
    background: linear-gradient(135deg, var(--v-primary, #6366f1), var(--v-primary-active, #4f46e5));
    color: var(--v-on-primary, #fff);
    box-shadow: 0 14px 30px -14px var(--v-primary-glow, rgba(99,102,241,.8));
}
#phoneModal .pa-btn-primary:hover { filter: brightness(1.07); transform: translateY(-1px); }
#phoneModal .pa-btn-ok {
    border: 1px solid rgba(16,185,129,.45);
    background: linear-gradient(135deg, rgba(16,185,129,.24), rgba(5,150,105,.16));
    color: #6ee7b7;
}
#phoneModal .pa-btn-ok:hover { filter: brightness(1.1); }

#phoneModal .pa-ok {
    display: flex; gap: 11px; align-items: flex-start;
    padding: 14px 15px; margin-bottom: 13px;
    border-radius: 15px;
    background: linear-gradient(160deg, rgba(16,185,129,.13), rgba(16,185,129,.04));
    border: 1px solid rgba(16,185,129,.32);
}
#phoneModal .pa-ok-badge {
    flex: 0 0 26px; width: 26px; height: 26px; border-radius: 50%;
    display: grid; place-items: center; font-size: 13px; font-weight: bold;
    color: #052e22; background: linear-gradient(135deg, #6ee7b7, #10b981);
    box-shadow: 0 6px 16px -6px rgba(16,185,129,.9);
}
#phoneModal .pa-ok-body { flex: 1 1 auto; min-width: 0; }
#phoneModal .pa-ok-body b { display: block; font-size: 12.5px; color: #6ee7b7; margin-bottom: 3px; }
#phoneModal .pa-num {
    font-size: 17px; font-weight: bold; color: #d1fae5;
    letter-spacing: 1.5px; font-family: ui-monospace, 'Cascadia Mono', monospace;
    direction: ltr; text-align: right; word-break: break-all;
}
#phoneModal .pa-ok-line { font-size: 11px; color: #34d399; margin-top: 5px; }
#phoneModal .pa-note {
    margin: 0 0 14px; padding: 10px 12px;
    font-size: 11.5px; line-height: 1.75; color: #7f93ad;
    background: rgba(16,185,129,.05);
    border: 1px solid rgba(16,185,129,.14);
    border-radius: 11px;
}
#phoneModal .pa-msg {
    display: none; margin-top: 12px; padding: 10px 13px;
    border-radius: 11px; font-size: 12px; line-height: 1.7;
}

#phoneModal .pa-loading { text-align: center; padding: 26px 0 20px; }
#phoneModal .pa-spin {
    width: 26px; height: 26px; margin: 0 auto 12px;
    border-radius: 50%;
    border: 2.5px solid rgba(148,163,184,.22);
    border-top-color: var(--v-primary, #6366f1);
    animation: paSpin .8s linear infinite;
}
@keyframes paSpin { to { transform: rotate(360deg); } }
#phoneModal .pa-loading div:last-child { font-size: 12px; color: #64748b; }

@media (max-width: 420px) {
    #phoneModal { padding: 12px; }
    #phoneModal .pa-card { padding: 17px; border-radius: 22px; }
}
@media (prefers-reduced-motion: reduce) {
    #phoneModal .pa-card { animation: none; }
    #phoneModal .pa-spin { animation-duration: 2s; }
}
</style>

<div id="phoneModal">
    <div class="pa-card" role="dialog" aria-modal="true" aria-labelledby="paTitle">
        <div class="pa-head">
            <span class="pa-mark"><img src="assets/icons3d/shield.png" alt=""></span>
            <span class="pa-headtext">
                <b id="paTitle">تنظیم احراز هویت</b>
                <small>ورود امن با شماره موبایل یا رمز</small>
            </span>
            <button type="button" class="pa-x" onclick="closePhoneSettings()" aria-label="بستن">✕</button>
        </div>

        <div id="phoneModalLoading" class="pa-loading" style="display:none;">
            <div class="pa-spin"></div>
            <div>در حال بارگذاری…</div>
        </div>

        <div id="phoneModalRegistered" class="pa-state">
            <div class="pa-ok">
                <span class="pa-ok-badge">✓</span>
                <div class="pa-ok-body">
                    <b>احراز هویت فعال است</b>
                    <div id="phoneModalRegisteredNum" class="pa-num" dir="ltr"></div>
                    <div id="phoneModalRegisteredPass" class="pa-ok-line" style="display:none;">
                        <img src="assets/icons3d/key.png" alt="" style="width:12px;height:12px;vertical-align:-2px;">
                        رمز احراز هویت هم تنظیم شده است
                    </div>
                </div>
            </div>
            <p class="pa-note">در ورودهای بعدی می‌توانید با شماره موبایل یا رمز احراز هویت وارد شوید.</p>
            <button type="button" class="pa-btn pa-btn-ok" onclick="closePhoneSettings()">متوجه شدم</button>
        </div>

        <div id="phoneModalRegister" class="pa-state">
            <p class="pa-lede">برای ورود سریع در دفعات بعد، یکی یا هر دو مورد را تنظیم کنید.</p>
            <div class="pa-tip">
                <img class="pa-tip-ic" src="assets/icons3d/bulb.png" alt="">
                <span>اگر <b>هر دو</b> را وارد کنید، بعداً با هر کدام می‌توانید وارد شوید. با وارد کردن تنها یکی، همان ملاک احراز هویت خواهد بود.</span>
            </div>

            <label class="pa-label" for="phoneInput">شماره موبایل</label>
            <div class="pa-field">
                <img class="pa-fi" src="assets/icons3d/phone.png" alt="">
                <input type="tel" id="phoneInput" maxlength="11" inputmode="numeric"
                       placeholder="مثال: 09123456789 (اختیاری)" dir="ltr"
                       oninput="this.value=this.value.replace(/[^0-9]/g,'').substr(0,11)">
            </div>

            <label class="pa-label" for="phoneAuthPassSave">رمز احراز هویت</label>
            <div class="v-pass-row">
                <input type="password" id="phoneAuthPassSave" maxlength="20" dir="ltr"
                       placeholder="۸ تا ۲۰ کاراکتر انگلیسی (اختیاری)"
                       oninput="this.value=this.value.replace(/[\u0600-\u06FF\u0750-\u077F]/g,'').substr(0,20)"
                       onkeydown="if(event.key==='Enter')savePhoneNumber()">
                <button onclick="togglePassVisibility('phoneAuthPassSave',this)" type="button" aria-label="نمایش رمز" class="v-eye-btn"><span class="v-eye-ico"><svg class="i3d" viewBox="0 0 32 32" aria-hidden="true"><use href="#v3i-eye"/></svg></span></button>
            </div>
            <div id="phonePassHint" class="pa-hint">حروف فارسی مجاز نیست • حداقل ۸ کاراکتر</div>

            <button type="button" class="pa-btn pa-btn-primary" onclick="savePhoneNumber()">
                <img src="assets/icons3d/check.png" alt="" style="width:15px;height:15px;">
                <span>ذخیره</span>
            </button>
            <div id="phoneSaveMsg" class="pa-msg"></div>
        </div>
    </div>
</div>

<div id="phoneLoginModal" style="display:none;position:fixed;inset:0;z-index:9900;background:rgba(0,0,0,0.92);backdrop-filter:blur(20px);align-items:center;justify-content:center;padding:20px;">
    <div style="background:linear-gradient(145deg,#0a0f1e,#111827);border:1px solid rgba(99,102,241,0.4);border-radius:24px;width:100%;max-width:380px;padding:28px 24px;box-shadow:0 30px 80px rgba(0,0,0,0.9);">
        <div style="text-align:center;margin-bottom:22px;">
            <div style="font-size:42px;margin-bottom:10px;">📱</div>
            <div style="font-size:16px;font-weight:bold;color:#e2e8f0;">احراز هویت دو مرحله‌ای</div>
            <div id="phoneLoginDesc" style="font-size:13px;color:#64748b;margin-top:8px;line-height:1.7;"></div>
        </div>
        <div id="phoneOtpCodeBox" style="background:var(--v-glow-soft, rgba(99,102,241,0.1));border:1px solid var(--v-primary-border, rgba(99,102,241,0.3));border-radius:14px;padding:16px;text-align:center;margin-bottom:18px;">
            <div style="font-size:11px;color:var(--v-accent, #a5b4fc);margin-bottom:8px;letter-spacing:1px;">کد تأیید</div>
            <div id="phoneOtpDisplay" style="font-size:28px;font-weight:bold;color:var(--v-accent, #a5b4fc);letter-spacing:6px;font-family:monospace;"></div>
        </div>
        <input type="text" id="phoneOtpInput" maxlength="5" placeholder="کد ۵ رقمی را وارد کنید" dir="ltr" style="width:100%;padding:13px 14px;background:rgba(255,255,255,0.05);border:1px solid var(--v-primary-border, rgba(99,102,241,0.3));border-radius:12px;color:#e2e8f0;font-size:18px;text-align:center;letter-spacing:4px;margin-bottom:14px;box-sizing:border-box;font-family:monospace;" oninput="this.value=this.value.replace(/[^0-9]/g,'').substr(0,5)">
        <button onclick="submitPhoneOtp()" style="width:100%;padding:13px;background:linear-gradient(135deg,var(--v-active-soft, rgba(99,102,241,0.35)),var(--v-primary-strong, rgba(79,70,229,0.3)));border:1px solid rgba(99,102,241,0.6);border-radius:12px;color:var(--v-accent, #a5b4fc);font-size:14px;cursor:pointer;font-weight:bold;margin-bottom:10px;">تأیید و ورود</button>
        <button onclick="closePhoneLoginModal()" style="width:100%;padding:10px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:12px;color:#64748b;font-size:13px;cursor:pointer;">بازگشت</button>
        <div id="phoneOtpError" style="display:none;margin-top:12px;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:10px;padding:10px 14px;color:#fca5a5;font-size:13px;text-align:center;"></div>
    </div>
</div>

<div id="pinPopupOverlay" class="pin-popup-overlay">
    <div class="pin-popup-backdrop" onclick="closePinPopup()"></div>
    <div class="pin-popup-card">
        <div class="pin-popup-header">
            <div class="pin-popup-icon">📌</div>
            <div class="pin-popup-info">
                <div class="pin-popup-sender" id="pinPopupSender"></div>
                <div class="pin-popup-time" id="pinPopupTime"></div>
            </div>
            <button class="pin-popup-close" onclick="closePinPopup()">✕</button>
        </div>
        <div class="pin-popup-body" id="pinPopupBody">
            <div class="pin-popup-loading">
                <div class="pin-popup-spinner"></div>
                <div class="pin-popup-spinner-text">در حال بارگذاری...</div>
            </div>
        </div>
        <div class="pin-popup-footer" id="pinPopupFooter">
            <button class="pin-popup-goto" onclick="gotoMsgFromPinPopup()">↩ رفتن به پیام</button>
            <button id="pinPopupUnpinBtn" style="display:none;padding:11px 14px;border-radius:13px;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.35);color:#fca5a5;font-size:13px;cursor:pointer;transition:0.2s;" onclick="unpinFromPopup()">📌 حذف پین</button>
            <button class="pin-popup-cancel" onclick="closePinPopup()">بستن</button>
        </div>
    </div>
</div>
<div id="srpOverlay" class="srp-overlay">
    <div class="srp-backdrop" onclick="closeSearchResultPopup()"></div>
    <div class="srp-card">
        <div class="srp-header">
            <div class="srp-header-icon">🔍</div>
            <div class="srp-header-info">
                <div class="srp-sender" id="srpSender"></div>
                <div class="srp-time" id="srpTime"></div>
            </div>
            <button class="srp-close" onclick="closeSearchResultPopup()">✕</button>
        </div>
        <div class="srp-body" id="srpBody">
            <div class="srp-loading">
                <div class="srp-spinner"></div>
                <div class="srp-spinner-text">در حال بارگذاری...</div>
            </div>
        </div>
        <div class="srp-footer">
            <button class="srp-goto-btn" id="srpGotoBtn" onclick="goToMsgFromPopup()">↩ رفتن به پیام در چت</button>
            <button class="srp-cancel-btn" onclick="closeSearchResultPopup()">بستن</button>
        </div>
    </div>
</div>

<div id="helpOverlay" class="help-overlay" onclick="closeHelp()">
    <div class="help-card" onclick="event.stopPropagation()">
        <div class="help-header">
            <span class="help-title">📖 راهنمای استفاده</span>
            <button class="help-close" onclick="closeHelp()">✕</button>
        </div>
        <div class="help-body">
            <div class="help-section">
                <div class="help-section-title">پیام‌رسانی</div>
                <div class="help-item"><span class="help-icon">💬</span><div class="help-text"><div class="help-name">گفتگوی عمومی</div><div class="help-desc">اتاق مشترک همه کاربران. پیام‌های شما برای همه قابل مشاهده است.</div></div></div>
                <div class="help-item"><span class="help-icon">🔒</span><div class="help-text"><div class="help-name">چت خصوصی</div><div class="help-desc">روی نام کاربر در لیست کلیک کنید تا گفتگوی خصوصی آغاز شود. فقط شما و طرف مقابل پیام‌ها را می‌بینید.</div></div></div>
                <div class="help-item"><span class="help-icon">↩</span><div class="help-text"><div class="help-name">پاسخ به پیام</div><div class="help-desc">روی پیام نگه دارید یا کلیک راست کنید تا گزینه پاسخ ظاهر شود.</div></div></div>
                <div class="help-item"><span class="help-icon">✎</span><div class="help-text"><div class="help-name">ویرایش پیام</div><div class="help-desc">پیام‌های ارسالی خودتان را می‌توانید ویرایش کنید.</div></div></div>
            </div>
            <div class="help-section">
                <div class="help-section-title">فایل و رسانه</div>
                <div class="help-item"><span class="help-icon">📎</span><div class="help-text"><div class="help-name">ارسال فایل</div><div class="help-desc">دکمه فایل در پایین صفحه را بزنید یا فایل را مستقیم داخل صفحه رها کنید (drag & drop).</div></div></div>
                <div class="help-item"><span class="help-icon">📋</span><div class="help-text"><div class="help-name">کپی و پیست تصویر</div><div class="help-desc">تصویر کپی شده را با Ctrl+V مستقیم در چت پیست کنید تا ارسال شود.</div></div></div>
                <div class="help-item"><span class="help-icon">🎤</span><div class="help-text"><div class="help-name">ارسال ویس</div><div class="help-desc">دکمه میکروفون را نگه دارید تا ضبط شروع شود. برای قفل کردن رکورد، انگشت را به بالا بکشید.</div></div></div>
            </div>
            <div class="help-section">
                <div class="help-section-title">اتاق‌ها</div>
                <div class="help-item"><span class="help-icon">🔎</span><div class="help-text"><div class="help-name">پیدا کردن اتاق</div><div class="help-desc">کد دعوت اتاق خصوصی را در کادر جستجو وارد کنید تا درخواست عضویت ارسال شود.</div></div></div>
                <div class="help-item"><span class="help-icon">🏠</span><div class="help-text"><div class="help-name">ساخت اتاق جدید</div><div class="help-desc">از منوی کنار می‌توانید اتاق عمومی یا خصوصی بسازید.</div></div></div>
            </div>
            <div class="help-section">
                <div class="help-section-title">تنظیمات و امنیت</div>
                <div class="help-item"><span class="help-icon">🔑</span><div class="help-text"><div class="help-name">کد امنیتی</div><div class="help-desc">کد ۸ رقمی شما برای ورود از دستگاه جدید استفاده می‌شود. آن را در جای امنی نگه دارید.</div></div></div>
                <div class="help-item"><span class="help-icon">🔔</span><div class="help-text"><div class="help-name">اعلان‌ها</div><div class="help-desc">از منوی کنار می‌توانید اعلان‌های مرورگر را روشن یا خاموش کنید.</div></div></div>
                <div class="help-item"><span class="help-icon">🚪</span><div class="help-text"><div class="help-name">خروج</div><div class="help-desc">از آخرین گزینه منوی کنار می‌توانید از حساب کاربری خارج شوید.</div></div></div>
            </div>
        </div>
    </div>
</div>

<div id="lightboxOverlay" onclick="closeLightbox()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.95);z-index:99999;justify-content:center;align-items:center;cursor:zoom-out;backdrop-filter:blur(8px);">
    <div id="lightboxSpinner"></div>
    <div id="lightboxContent" onclick="event.stopPropagation()" style="position:relative;max-width:95vw;max-height:95vh;display:flex;justify-content:center;align-items:center;">
        <img id="lightboxImg" src="" style="max-width:100%;max-height:90vh;border-radius:12px;box-shadow:0 25px 80px rgba(0,0,0,0.8);transform:scale(1);transition:transform 0.2s;cursor:zoom-in;user-select:none;" ondblclick="toggleLightboxZoom(this)">
        <video id="lightboxVideo" src="" controls style="display:none;max-width:100%;max-height:90vh;border-radius:12px;"></video>
    </div>
    <button onclick="closeLightbox()" style="position:fixed;top:20px;right:20px;background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);color:#fff;width:40px;height:40px;border-radius:50%;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;z-index:100000;">✕</button>
    <div style="position:fixed;bottom:20px;left:50%;transform:translateX(-50%);display:flex;gap:10px;">
        <button id="lightboxDownloadBtn" onclick="event.stopPropagation()" style="background:rgba(99,102,241,0.8);border:1px solid #6366f1;color:#fff;padding:8px 20px;border-radius:12px;font-size:13px;cursor:pointer;">⬇ دانلود</button>
        <button onclick="closeLightbox()" style="background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);color:#fff;padding:8px 20px;border-radius:12px;font-size:13px;cursor:pointer;">بستن</button>
    </div>
</div>

<div id="dragOverlay" class="drag-overlay">
    <div class="drag-icon">📂</div>
    <div class="drag-text">فایل‌ها را اینجا رها کنید (حداکثر ۵ فایل)</div>
</div>

<div id="uploadProgressBox">
    <div class="up-thumb" id="upThumb">📎</div>
    <div class="up-body">
        <div class="up-row">
            <span class="up-name" id="uploadProgName">در حال آپلود…</span>
            <span class="up-pct" id="uploadProgPct">0%</span>
        </div>
        <div class="up-bar-bg">
            <div class="up-bar" id="uploadProgBar"></div>
        </div>
        <div class="up-row up-sub">
            <span id="uploadProgQueue"></span>
            <button type="button" class="up-cancel" onclick="cancelUpload()">لغو</button>
        </div>
    </div>
</div>

<div id="welcomeModal" class="modal-overlay">
    <div class="modal welcome-modal">
        <div class="wm-scene" aria-hidden="true">
            <span class="wm-orbiter wm-o1"><img src="assets/icons3d/shield.png" alt=""></span>
            <span class="wm-orbiter wm-o2"><img src="assets/icons3d/chat.png" alt=""></span>
            <span class="wm-orbiter wm-o3"><img src="assets/icons3d/mic.png" alt=""></span>
            <div class="wm-orb">
                <span class="wm-orb-halo"></span>
                <span class="wm-orb-ring"></span>
                <img class="wm-icon" src="assets/icons3d/sparkles.png" alt="">
                <span class="wm-orb-sheen"></span>
            </div>
        </div>

        <div class="modal-title wm-title">خوش اومدی به <?php echo htmlspecialchars($site_brand_fa, ENT_QUOTES, 'UTF-8'); ?></div>
        <p class="wm-lede">به جمع ما خوش اومدی؛ همه‌چیز آماده‌ست تا راحت و امن گفتگو کنی.</p>

        <div class="wm-cards">
            <div class="wm-card">
                <span class="wm-card-ico"><img src="assets/icons3d/mic.png" alt=""></span>
                <span class="wm-card-tx">
                    <b>پیام صوتی</b>
                    <small>دکمه میکروفون رو نگه دار و با صدا حرف بزن.</small>
                </span>
                <span class="wm-tag wm-tag-live">فعال</span>
            </div>
            <div class="wm-card">
                <span class="wm-card-ico"><img src="assets/icons3d/bulb.png" alt=""></span>
                <span class="wm-card-tx">
                    <b>امکانات کامل</b>
                    <small>گزارش تخلف، سیستم دعوت و اتاق خصوصی همگی در دسترسه.</small>
                </span>
                <span class="wm-tag wm-tag-live">فعال</span>
            </div>
            <div class="wm-card">
                <span class="wm-card-ico"><img src="assets/icons3d/megaphone.png" alt=""></span>
                <span class="wm-card-tx">
                    <b>پشتیبانی</b>
                    <small>اگه رفتار نامناسبی دیدی، با گزارش تخلف سریع اطلاع بده.</small>
                </span>
                <span class="wm-tag wm-tag-open">همیشه</span>
            </div>
        </div>

        <button class="wm-cta" onclick="dismissWelcome()">
            <span class="wm-cta-tx">متوجه شدم</span>
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
        </button>
        <p class="wm-foot">این پیام فقط یک‌بار برای هر حساب نمایش داده می‌شود.</p>
    </div>
</div>

<div id="securityCodeModal" class="modal-overlay">
    <div class="modal sec-code-modal">
        <div class="scm-hero">
            <img class="scm-icon" src="assets/icons3d/key.png" alt="">
        </div>
        <div class="modal-title scm-title">کد امنیتی اکانت شما</div>
        <p class="scm-lede">این کد مخصوص حساب شماست و فقط همین یک‌بار نمایش داده می‌شود.</p>

        <div class="scm-code" id="scmCodeBox" aria-hidden="true"></div>
        <span id="newSecurityCodeDisplay" hidden></span>

        <button type="button" class="scm-copy" id="scmCopyBtn" onclick="copyNewSecurityCode()">
            <img src="assets/icons3d/clipboard.png" alt=""><span id="scmCopyLabel">کپی کد</span>
        </button>

        <div class="scm-note">
            <img src="assets/icons3d/warning.png" alt="">
            <span>اگر با دستگاه دیگری وارد می‌شوید یا نشست شما پاک شد، این کد تنها راه بازگردانی حساب است. حتماً جایی امن ذخیره‌اش کنید.</span>
        </div>

        <div class="modal-actions">
            <button class="modal-btn btn-primary-soft" onclick="closeModal('securityCodeModal')">ذخیره کردم</button>
            <button class="modal-btn btn-ghost" id="resetSecCodeBtn" onclick="resetSecurityCode()">ریست کد</button>
        </div>
        <p class="sec-reset-warning" id="resetSecWarning">کد فعلی برای همیشه از کار می‌افتد و کد تازه‌ای ساخته می‌شود.</p>
    </div>
</div>

<div id="globalAlertModal" class="modal-overlay">
    <div class="modal" style="border-color:#f59e0b; background: linear-gradient(145deg, var(--v-surface-2, #1e293b), var(--v-bg-1, #1e1b15));">
        <div class="modal-icon">📢</div>
        <div class="modal-title" style="color:#f59e0b">اطلاعیه سراسری</div>
        <p id="globalAlertText" class="modal-text" style="color:#fff; font-weight:bold; font-size:15px;"></p>
        <button class="modal-btn btn-gray" onclick="closeModal('globalAlertModal')">خواندم</button>
    </div>
</div>

<style>
/* ── Auth modal (phone / password) ──────────────────────────────────────────
   Scoped to #phoneAuthModal so nothing here can leak into the rest of the app.
   Every colour comes from the theme variables, so changing the primary colour
   in the admin panel re-skins the whole card. */
#phoneAuthModal {
    position: fixed; inset: 0; z-index: 99990;
    display: none; align-items: flex-start; justify-content: center;
    padding: clamp(14px, 4vw, 34px);
    overflow-y: auto; overscroll-behavior: contain;
    background:
        radial-gradient(90% 60% at 50% -10%, rgba(99,102,241,.22), transparent 62%),
        radial-gradient(70% 50% at 50% 112%, rgba(56,189,248,.12), transparent 66%),
        rgba(4,7,15,.80);
    -webkit-backdrop-filter: blur(26px) saturate(130%);
    backdrop-filter: blur(26px) saturate(130%);
}
#phoneAuthModal .pa2-card {
    position: relative; margin: auto;
    width: 100%; max-width: 402px;
    padding: 26px 24px 22px;
    border-radius: 28px;
    background:
        radial-gradient(120% 60% at 50% -8%, rgba(255,255,255,.10), transparent 62%),
        linear-gradient(168deg, rgba(30,39,62,.96), rgba(10,14,26,.98));
    box-shadow:
        0 46px 100px -34px rgba(0,0,0,.95),
        0 30px 80px -50px var(--v-primary-glow, rgba(99,102,241,.9)),
        0 0 0 1px rgba(255,255,255,.05),
        inset 0 1px 0 rgba(255,255,255,.10);
    animation: pa2In .46s cubic-bezier(.2,.9,.3,1.1) both;
    overflow: hidden;
}
/* Glossy 1px rim: a conic gradient masked down to the border only. */
#phoneAuthModal .pa2-card::before {
    content: ''; position: absolute; inset: 0; border-radius: inherit; padding: 1px;
    background: conic-gradient(from 200deg at 50% 0%,
        rgba(255,255,255,.55), rgba(255,255,255,.05) 22%,
        var(--v-primary, #6366f1) 48%, rgba(255,255,255,.05) 74%,
        rgba(255,255,255,.45));
    -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    -webkit-mask-composite: xor; mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    mask-composite: exclude;
    pointer-events: none; opacity: .88;
}
#phoneAuthModal .pa2-card::after {
    content: ''; position: absolute; inset-inline: 12%; top: 0; height: 1px;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,.75), transparent);
    opacity: .8; pointer-events: none;
}
@keyframes pa2In { from { opacity: 0; transform: translateY(18px) scale(.965); } to { opacity: 1; transform: none; } }

/* Head */
#phoneAuthModal .pa2-head { text-align: center; margin-bottom: 18px; }
#phoneAuthModal .pa2-mark {
    position: relative; width: 74px; height: 74px; margin: 0 auto 14px;
    display: grid; place-items: center; border-radius: 24px;
    background: linear-gradient(160deg, rgba(255,255,255,.14), rgba(255,255,255,.02));
    box-shadow: 0 18px 34px -18px var(--v-primary-glow, rgba(99,102,241,.9)), inset 0 1px 0 rgba(255,255,255,.22);
    border: 1px solid rgba(255,255,255,.10);
}
#phoneAuthModal .pa2-mark::before {
    content: ''; position: absolute; inset: -7px; border-radius: 30px;
    border: 1px solid var(--v-primary-border, rgba(99,102,241,.45));
    animation: pa2Ring 3.4s ease-out infinite;
}
#phoneAuthModal .pa2-mark-img {
    position: absolute; width: 40px; height: 40px; object-fit: contain;
    filter: drop-shadow(0 8px 14px rgba(0,0,0,.55));
    transition: opacity .32s ease, transform .42s cubic-bezier(.2,.9,.3,1.3);
}
#phoneAuthModal .pa2-mark-phone { opacity: 0; transform: scale(.6) rotate(14deg); }
#phoneAuthModal .pa2-mark[data-kind="phone"] .pa2-mark-lock { opacity: 0; transform: scale(.6) rotate(-14deg); }
#phoneAuthModal .pa2-mark[data-kind="phone"] .pa2-mark-phone { opacity: 1; transform: none; }
@keyframes pa2Ring {
    0% { transform: scale(.94); opacity: .6; }
    70%, 100% { transform: scale(1.12); opacity: 0; }
}
#phoneAuthModal .pa2-title {
    margin: 0 0 6px; font-size: 18.5px; font-weight: 800; color: #fff;
    letter-spacing: -.2px; font-family: 'AppBold', inherit;
}
#phoneAuthModal .pa2-desc {
    margin: 0 auto; max-width: 30ch; font-size: 12.5px; line-height: 1.85; color: #94a5c0;
}

/* Locked / error strip */
#phoneAuthModal .pa2-lock {
    display: none; align-items: center; gap: 9px;
    margin: 0 0 14px; padding: 11px 14px; border-radius: 14px;
    background: rgba(239,68,68,.10); border: 1px solid rgba(239,68,68,.28);
    color: #fca5a5; font-size: 12.5px; line-height: 1.6; text-align: start;
}
#phoneAuthModal .pa2-lock img { width: 20px; height: 20px; flex: 0 0 auto; }

/* Segmented tabs with a sliding pill */
#phoneAuthModal .pa2-tabs {
    position: relative; display: grid; grid-template-columns: 1fr 1fr;
    gap: 0; padding: 4px; margin-bottom: 14px;
    background: rgba(255,255,255,.045);
    border: 1px solid rgba(255,255,255,.06);
    border-radius: 16px;
}
#phoneAuthModal .pa2-tab-pill {
    position: absolute; top: 4px; bottom: 4px; inset-inline-start: 4px;
    width: calc(50% - 4px); border-radius: 12px; z-index: 0;
    background: linear-gradient(160deg, rgba(255,255,255,.20), rgba(255,255,255,.04)), var(--v-primary, #6366f1);
    box-shadow: 0 10px 22px -12px var(--v-primary-glow, rgba(99,102,241,.95)), inset 0 1px 0 rgba(255,255,255,.38);
    transition: transform .34s cubic-bezier(.4,.1,.2,1);
}
#phoneAuthModal .pa2-tabs[data-tab="pass"] .pa2-tab-pill { transform: translateX(-100%); }
#phoneAuthModal .pa2-tab {
    position: relative; z-index: 1; width: auto; height: auto; margin: 0;
    display: flex; align-items: center; justify-content: center; gap: 7px;
    padding: 10px 6px; border: 0; border-radius: 12px; background: transparent;
    color: #8ea0bb; font-family: inherit; font-size: 12.5px; font-weight: 700;
    cursor: pointer; box-shadow: none; transition: color .25s ease;
}
#phoneAuthModal .pa2-tab img { width: 16px; height: 16px; object-fit: contain; opacity: .55; transition: opacity .25s, transform .25s; }
#phoneAuthModal .pa2-tab.is-active { color: #fff; }
#phoneAuthModal .pa2-tab.is-active img { opacity: 1; transform: scale(1.08); }

/* Fields */
#phoneAuthModal .pa2-panel { margin-bottom: 4px; }
#phoneAuthModal .pa2-field { position: relative; }
#phoneAuthModal .pa2-field input {
    display: block; width: 100%; height: 52px;
    border-radius: 15px; border: 1px solid rgba(255,255,255,.10);
    background: rgba(255,255,255,.045);
    color: #eaf1ff; font-family: inherit; font-size: 15px;
    transition: border-color .2s, background .2s, box-shadow .2s;
    outline: none;
}
#phoneAuthModal .pa2-field input::placeholder { color: #6b7a93; }
#phoneAuthModal .pa2-field input:focus {
    border-color: var(--v-primary, #6366f1);
    background: rgba(255,255,255,.075);
    box-shadow: 0 0 0 4px var(--v-primary-soft, rgba(99,102,241,.16));
}
#phoneAuthModal .pa2-field input:disabled { opacity: .55; }
#phoneAuthModal .pa2-fi {
    position: absolute; top: 50%; left: 14px; transform: translateY(-50%);
    width: 20px; height: 20px; object-fit: contain; opacity: .9; pointer-events: none;
    filter: drop-shadow(0 3px 6px rgba(0,0,0,.5));
}
#phoneAuthModal .pa2-field input[dir="ltr"] {
    direction: ltr; text-align: left; padding: 0 16px 0 44px;
    letter-spacing: 1.4px;
}
#phoneAuthModal .pa2-field.is-pass input[dir="ltr"] { padding-inline-end: 46px; letter-spacing: .6px; }
#phoneAuthModal .pa2-eye {
    position: absolute; top: 50%; right: 8px; transform: translateY(-50%);
    width: 36px; height: 36px; margin: 0; display: grid; place-items: center;
    border: 0; border-radius: 11px; background: rgba(255,255,255,.06);
    color: #b9c7dc; font-size: 15px; cursor: pointer; transition: background .18s, color .18s;
}
#phoneAuthModal .pa2-eye:hover { background: rgba(255,255,255,.12); color: #fff; }
#phoneAuthModal .pa2-eye .v-eye-ico { display: grid; place-items: center; width: 21px; height: 21px; }
#phoneAuthModal .pa2-eye .i3d { width: 21px; height: 21px; filter: drop-shadow(0 2px 4px rgba(0,0,0,.5)); }

/* Inline message */
#phoneAuthModal .pa2-msg {
    min-height: 18px; margin: 8px 2px 12px;
    text-align: center; font-size: 12px; line-height: 1.6; color: #f87171;
}

/* Primary action */
#phoneAuthModal .pa2-btn {
    position: relative; overflow: hidden;
    width: 100%; height: 52px; margin: 0;
    border: 0; border-radius: 15px; cursor: pointer;
    font-family: inherit; font-size: 15px; font-weight: 800; color: #fff;
    background: linear-gradient(135deg, var(--v-primary, #6366f1), var(--v-primary-active, #4f46e5));
    box-shadow:
        0 20px 38px -18px var(--v-primary-glow, rgba(99,102,241,.95)),
        inset 0 1px 0 rgba(255,255,255,.34);
    transition: transform .15s ease, box-shadow .2s ease, filter .2s ease;
}
#phoneAuthModal .pa2-btn::after {
    content: ''; position: absolute; top: -20%; bottom: -20%; width: 42%;
    background: linear-gradient(100deg, transparent, rgba(255,255,255,.45), transparent);
    transform: translateX(-180%) skewX(-16deg);
    animation: pa2Sweep 4.2s ease-in-out infinite;
    pointer-events: none;
}
#phoneAuthModal .pa2-btn:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 26px 46px -18px var(--v-primary-glow, rgba(99,102,241,1)), inset 0 1px 0 rgba(255,255,255,.4); }
#phoneAuthModal .pa2-btn:active:not(:disabled) { transform: translateY(0) scale(.99); }
#phoneAuthModal .pa2-btn:disabled { filter: saturate(.55) brightness(.85); cursor: not-allowed; }
#phoneAuthModal .pa2-btn:disabled::after { animation: none; opacity: 0; }
@keyframes pa2Sweep {
    0%, 58% { transform: translateX(-180%) skewX(-16deg); }
    86%, 100% { transform: translateX(340%) skewX(-16deg); }
}

/* Secondary */
#phoneAuthModal .pa2-skip {
    width: 100%; height: 44px; margin: 10px 0 0;
    border: 1px solid rgba(255,255,255,.10); border-radius: 13px;
    background: rgba(255,255,255,.03); color: #93a3bd;
    font-family: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer;
    transition: background .2s, color .2s, border-color .2s;
}
#phoneAuthModal .pa2-skip:hover { background: rgba(255,255,255,.07); color: #dbe5f5; border-color: rgba(255,255,255,.18); }

/* First-run note */
#phoneAuthModal .pa2-new {
    display: none; align-items: flex-start; gap: 9px;
    margin-top: 14px; padding: 12px 13px; border-radius: 14px;
    background: rgba(16,185,129,.075); border: 1px solid rgba(16,185,129,.22);
    color: #7ee2b8; font-size: 11.5px; line-height: 1.85; text-align: start;
}
#phoneAuthModal .pa2-new img { width: 18px; height: 18px; flex: 0 0 auto; margin-top: 1px; }

@media (max-width: 420px) {
    #phoneAuthModal { padding: 12px; }
    #phoneAuthModal .pa2-card { padding: 22px 17px 18px; border-radius: 24px; }
    #phoneAuthModal .pa2-mark { width: 66px; height: 66px; border-radius: 21px; }
    #phoneAuthModal .pa2-mark-img { width: 36px; height: 36px; }
}
@media (prefers-reduced-motion: reduce) {
    #phoneAuthModal .pa2-card,
    #phoneAuthModal .pa2-mark::before,
    #phoneAuthModal .pa2-btn::after { animation: none !important; }
    #phoneAuthModal .pa2-tab-pill { transition: none; }
}
</style>

<div id="phoneAuthModal" style="display:none;">
    <div class="pa2-card" role="dialog" aria-modal="true" aria-labelledby="phoneAuthTitle">
        <div class="pa2-head">
            <div class="pa2-mark" id="phoneAuthIcon" data-kind="lock">
                <img class="pa2-mark-img pa2-mark-lock" src="assets/icons3d/lock.png" alt="">
                <img class="pa2-mark-img pa2-mark-phone" src="assets/icons3d/phone.png" alt="">
            </div>
            <div class="pa2-title" id="phoneAuthTitle">احراز هویت</div>
            <div class="pa2-desc" id="phoneAuthDesc">شماره موبایل یا رمز احراز هویت خود را وارد کنید.</div>
        </div>

        <div class="pa2-lock" id="phoneAuthLockBox">
            <img src="assets/icons3d/shield.png" alt="">
            <span id="phoneAuthLockMsg">حساب قفل شده</span>
        </div>

        <div class="pa2-tabs" id="phoneAuthTabs" data-tab="phone">
            <span class="pa2-tab-pill" aria-hidden="true"></span>
            <button type="button" class="pa2-tab is-active" id="phoneAuthTabPhone" onclick="switchPhoneAuthTab('phone')">
                <img src="assets/icons3d/phone.png" alt=""><span>شماره موبایل</span>
            </button>
            <button type="button" class="pa2-tab" id="phoneAuthTabPass" onclick="switchPhoneAuthTab('pass')">
                <img src="assets/icons3d/key.png" alt=""><span>رمز احراز هویت</span>
            </button>
        </div>

        <div class="pa2-panel" id="phoneAuthPanelPhone">
            <div class="pa2-field">
                <img class="pa2-fi" src="assets/icons3d/phone.png" alt="">
                <input type="tel" id="phoneAuthInput" maxlength="11" placeholder="09xxxxxxxxx" dir="ltr" autocomplete="tel"
                    oninput="this.value=this.value.replace(/[^0-9]/g,'').substr(0,11)"
                    onkeydown="if(event.key==='Enter')submitPhoneAuth()">
            </div>
        </div>

        <div class="pa2-panel" id="phoneAuthPanelPass" style="display:none;">
            <div class="pa2-field is-pass">
                <img class="pa2-fi" src="assets/icons3d/key.png" alt="">
                <input type="password" id="phoneAuthPassInput" maxlength="20" placeholder="رمز احراز هویت — ۸ تا ۲۰ کاراکتر" dir="ltr" autocomplete="current-password"
                    oninput="this.value=this.value.replace(/[\u0600-\u06FF\u0750-\u077F]/g,'')"
                    onkeydown="if(event.key==='Enter')submitPhoneAuth()">
                <button onclick="togglePassVisibility('phoneAuthPassInput',this)" type="button" aria-label="نمایش رمز" class="pa2-eye"><span class="v-eye-ico"><svg class="i3d" viewBox="0 0 32 32" aria-hidden="true"><use href="#v3i-eye"/></svg></span></button>
            </div>
        </div>

        <div class="pa2-msg" id="phoneAuthAttempts"></div>

        <button id="phoneAuthBtn" class="pa2-btn" onclick="submitPhoneAuth()">تأیید و ورود</button>
        <button type="button" id="phoneAuthSkipBtn" class="pa2-skip" onclick="skipPhoneAuth()">فعلاً رد کن</button>

        <div class="pa2-new" id="phoneAuthNewUser">
            <img src="assets/icons3d/sparkles.png" alt="">
            <span>این اولین ورود شماست. شماره موبایل یا رمز احراز هویت ثبت کنید تا در دفعات بعد استفاده شود.</span>
        </div>
    </div>
</div>

<div id="banModal" class="modal-overlay" style="z-index: 99999;">
    <div class="ban-modal-content">
        <div class="ban-icon">🚫</div>
        <div class="ban-title">دسترسی مسدود شد</div>
        <div id="banMessageDetail" class="ban-message">
            متاسفانه حساب کاربری شما مسدود شده است.
        </div>
        <button class="ban-btn" onclick="location.reload()">تلاش مجدد</button>
    </div>
</div>

<div id="sessionBurnedModal" class="modal-overlay" style="z-index:99998; display:none;">
    <div class="modal" style="border-color:rgba(245,158,11,0.4); max-width:420px; text-align:center;">
        <div class="modal-icon">🔥</div>
        <div class="modal-title" style="color:#f59e0b;">سشن شما پایان یافت</div>
        <p class="modal-text" style="line-height:1.8;">ادمین سشن شما را پایان داد یا از دستگاه دیگری وارد شدید.<br>لطفاً مجدداً وارد شوید.</p>
        <div id="burnedPrevUsers" style="display:none; margin-bottom:16px; text-align:right;"></div>
        <div class="modal-actions" style="flex-direction:column;">
            <button id="burnedLoginCodeBtn" class="modal-btn btn-green" style="display:none;" onclick="window.location.href='index.php'">🔒 ورود با کد امنیتی</button>
            <button class="modal-btn btn-gray" onclick="window.location.href='index.php'">بازگشت به صفحه ورود</button>
        </div>
    </div>
</div>

<div id="reportUserModal" class="modal-overlay">
    <div class="modal rp-modal">
        <div class="rp-head">
            <div class="rp-badge"><img src="assets/icons3d/megaphone.png" alt=""></div>
            <div class="rp-head-tx">
                <div class="modal-title rp-title">گزارش تخلف کاربر</div>
                <p class="rp-sub">نام کاربر و دلیل گزارش را وارد کن؛ تیم پشتیبانی بررسی می‌کند.</p>
            </div>
        </div>

        <div class="rp-field">
            <label class="rp-label" for="reportTargetUser"><img src="assets/icons3d/user.png" alt="">کاربر مورد نظر</label>
            <div class="rp-suggest-wrap">
                <input type="text" id="reportTargetUser" class="modal-input rp-input" placeholder="جستجوی نام کاربری..." autocomplete="off">
                <div id="reportUserSuggestions"></div>
            </div>
        </div>

        <div class="rp-field">
            <label class="rp-label" for="reportReason"><img src="assets/icons3d/pencil.png" alt="">دلیل گزارش</label>
            <textarea id="reportReason" class="modal-input rp-textarea" maxlength="500" placeholder="چه اتفاقی افتاد؟ هر جزئیاتی که کمک می‌کند را بنویس..."></textarea>
            <span class="rp-count" id="reportReasonCount">۰ / ۵۰۰</span>
        </div>

        <div class="rp-field">
            <label class="rp-label"><img src="assets/icons3d/lock.png" alt="">تأیید امنیتی</label>
            <div class="captcha-row rp-captcha">
                <div class="captcha-text" id="reportCaptchaBox">···</div>
                <button type="button" class="refresh-btn" onclick="refreshReportCaptcha(this)" title="کد جدید" aria-label="تازه‌سازی کد امنیتی"><svg class="rf-ico" viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 12a8.5 8.5 0 1 1-2.9-6.4"/><polyline points="20.5 3.5 20.5 9 15 9"/></svg></button>
            </div>
            <input type="number" id="reportCaptchaInput" class="modal-input rp-input" placeholder="پاسخ عبارت بالا" required>
        </div>

        <div class="rp-actions">
            <button onclick="submitReportUser()" class="modal-btn rp-send" id="btnReportSend">ارسال گزارش</button>
            <button onclick="closeModal('reportUserModal')" class="modal-btn rp-cancel">لغو</button>
        </div>
    </div>
</div>

<div id="msgMenu" class="msg-menu-overlay" onclick="closeMsgMenu()">
    <div class="msg-menu" onclick="event.stopPropagation()">
        <div style="text-align:center; color:#94a3b8; font-size:14px; margin-bottom:20px; font-weight:bold;" id="msgMenuTitle">مدیریت پیام</div>
        <div class="menu-grid-actions" id="msgActionsGrid"></div>
        <button class="action-cancel" onclick="closeMsgMenu()">انصراف</button>
    </div>
</div>

<div id="accessDeniedModal" class="modal-overlay">
    <div class="modal" style="border-color:#f59e0b;">
        <div class="modal-icon">🔒</div>
        <div class="modal-title" style="color:#f59e0b">عدم دسترسی</div>
        <p class="modal-text">شما مجوز ورود به این اتاق خصوصی را ندارید.</p>
        <button class="modal-btn btn-gray" onclick="closeModal('accessDeniedModal'); switchRoom('گفتگوی عمومی', 'گفتگوی عمومی', null);">بازگشت به عمومی</button>
    </div>
</div>

<div id="modalConfirm" class="modal-overlay">
    <div class="modal">
        <div class="modal-icon">🤔</div>
        <div class="modal-title">تایید عملیات</div>
        <p id="confirmMsg" class="modal-text"></p>
        <div class="modal-actions">
            <button class="modal-btn btn-primary-soft" id="confirmYes">بله</button>
            <button class="modal-btn btn-gray" onclick="closeModal('modalConfirm')">خیر</button>
        </div>
    </div>
</div>

<div id="modalPrompt" class="modal-overlay">
    <div class="modal">
        <div class="modal-icon">✏️</div>
        <div id="promptMsg" class="modal-title"></div>
        <input type="text" id="promptInput" class="modal-input">
        <div class="modal-actions">
            <button class="modal-btn btn-primary-soft" id="promptOk">تایید</button>
            <button class="modal-btn btn-gray" onclick="closeModal('modalPrompt')">لغو</button>
        </div>
    </div>
</div>

<div id="roomSelectModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-title">انتخاب اتاق برای دعوت</div>
        <div id="inviteRoomList" style="max-height:200px; overflow-y:auto; margin:15px 0;"></div>
        <button onclick="closeModal('roomSelectModal')" class="modal-btn btn-gray">لغو</button>
    </div>
</div>

<div id="blockConfirmModal" class="modal-overlay">
    <div class="modal blk-modal">
        <div class="blk-badge">🛡</div>
        <div class="modal-title blk-title">مسدود کردن کاربر</div>
        <p class="blk-text">با مسدود کردن <b id="blockTargetName">این کاربر</b>، ارسال پیام بین شما متوقف می‌شود. تاریخچه گفتگو باقی می‌ماند و هر زمان خواستید می‌توانید رفع بلاک کنید.</p>
        <div class="blk-actions">
            <button type="button" class="modal-btn blk-yes" onclick="confirmBlockUser()">🚫 بله، مسدود کن</button>
            <button type="button" class="modal-btn btn-gray" onclick="closeModal('blockConfirmModal')">انصراف</button>
        </div>
    </div>
</div>

<div id="createRoomModal" class="modal-overlay">
    <div class="modal cr-modal">
        <div class="cr-head">
            <div class="modal-icon-badge cr-badge">✨</div>
            <div class="modal-title cr-title">ساخت اتاق و کانال</div>
            <div class="cr-subtitle">فضای گفتگوی خودت را بساز — اتاق خصوصی یا کانال</div>
        </div>
        <div class="cr-tabs" role="tablist">
            <button class="cr-tab active" id="crTabRoom" onclick="switchCreateTab('room')" role="tab"><span class="cr-tab-ico">🔒</span><span class="cr-tab-tx">اتاق شخصی</span></button>
            <button class="cr-tab" id="crTabChPub" onclick="switchCreateTab('channel_public')" role="tab"><span class="cr-tab-ico">📢</span><span class="cr-tab-tx">کانال عمومی</span></button>
            <button class="cr-tab" id="crTabChPriv" onclick="switchCreateTab('channel_private')" role="tab"><span class="cr-tab-ico">🔐</span><span class="cr-tab-tx">کانال خصوصی</span></button>
        </div>
        <div class="cr-hint"><span class="cr-hint-ico">💡</span><p class="cr-desc" id="crDesc">یک اتاق خصوصی با کد دعوت ۸ رقمی برای حداکثر ۸ نفر.</p></div>
        <div class="cr-field">
            <input type="text" id="newRoomName" class="modal-input cr-input" placeholder="نام (۴ تا ۱۰ حرف)..." maxlength="10" autocomplete="off" oninput="_crUpdateCount(this)">
            <span class="cr-count" id="crNameCount">۰/۱۰</span>
        </div>
        <div class="modal-actions cr-actions">
            <button id="btnCreateRoomSubmit" onclick="submitCreateRoom(event)" class="modal-btn btn-primary-soft cr-submit">✨ ساخت</button>
            <button onclick="closeModal('createRoomModal')" class="modal-btn btn-gray cr-cancel">لغو</button>
        </div>
    </div>
</div>

<div id="showCodeModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-icon">🎉</div>
        <div class="modal-title">اتاق ساخته شد!</div>
        <p class="modal-text">کد دعوت اتاق شما:</p>
        <div class="copy-code-box">
            <span id="createdCodeDisplay" class="code-text"></span>
            <button class="copy-btn" onclick="copyInviteCode()">📋</button>
        </div>
        <button onclick="closeModal('showCodeModal')" class="modal-btn btn-gray">متوجه شدم</button>
    </div>
</div>

<div id="notifPopup" class="notif-popup" onclick="handleNotifClick()">
    <div class="nf-accent"></div>
    <div class="notif-avatar" id="notifAvatar"></div>
    <div class="notif-content">
        <div class="nf-top">
            <span class="notif-name" id="notifName"></span>
            <span class="nf-kind" id="notifKind"></span>
        </div>
        <div class="notif-text" id="notifText"></div>
        <div class="notif-hint"><span class="nf-dot"></span><span id="notifHintText">ورود به گفتگو</span></div>
    </div>
</div>

<div id="ctxMenu" class="ctx-menu">
    <div class="ctx-item" onclick="handleCtx('private')">💬 پیام خصوصی</div>
    <div class="ctx-item" id="ctxInvite" onclick="handleCtx('invite')">📩 دعوت به اتاق</div>
    <div class="ctx-item" id="ctxBan" style="color:#fca5a5" onclick="handleCtx('ban')">🚫 مسدود کردن</div>
</div>

<div id="roomCtxMenu" class="ctx-menu">
    <div class="ctx-item" id="roomCtxViewPins" onclick="handleRoomCtx('view_pins')">📌 پیام‌های پین شده</div>
    <div class="ctx-item" id="roomCtxUnpinAll" onclick="handleRoomCtx('unpin_all')" style="color:#fca5a5;">📌 آن‌پین کردن همه</div>
</div>

<div id="msgCtxMenu" class="msg-ctx-menu">
    <div class="ctx-item" onclick="handleMsgCtxAction('reply')">↩ پاسخ</div>
    <div class="ctx-item" id="ctxEditMsg" onclick="handleMsgCtxAction('edit')">✎ ویرایش</div>
    <div class="ctx-item" onclick="handleMsgCtxAction('copy')">📋 کپی</div>
    <div class="ctx-item" id="ctxPinMsg" onclick="handleMsgCtxAction('pin')">📌 پین</div>
    <div class="ctx-item" id="ctxUnpinMsg" onclick="handleMsgCtxAction('unpin')">📌 آن‌پین</div>
    <div class="ctx-item" id="ctxDelMsg" style="color:#fca5a5" onclick="handleMsgCtxAction('delete')">🗑 حذف</div>
</div>

<div class="backdrop" onclick="toggleMenu()"></div>

<div class="sidebar" id="sidebar" aria-label="منوی کناری">
    <div class="sidebar-head">
        <div class="sidebar-brand">
            <span class="sidebar-brand-mark<?php echo $brand_logo ? ' has-image' : ''; ?>"><?php if ($brand_logo): ?><img src="<?php echo htmlspecialchars($brand_logo, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($site_brand_fa, ENT_QUOTES, 'UTF-8'); ?>" style="object-fit:<?php echo $brand_logo_fit; ?>"><?php else: ?><?php echo htmlspecialchars(mb_substr($site_brand_fa, 0, 1, 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></span>
            <div class="sidebar-brand-texts">
                <span class="sidebar-brand-name"><?php echo htmlspecialchars($site_brand_fa, ENT_QUOTES, 'UTF-8'); ?></span>
                <span class="sidebar-brand-sub"><?php echo htmlspecialchars($brand_tagline, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        </div>
        <button type="button" class="sidebar-close" onclick="toggleMenu()" aria-label="بستن منو" title="بستن">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>
        </button>
    </div>
    <div class="search-box">
        <input type="text" class="search-input" placeholder="جستجو (یا وارد کردن کد دعوت)..." onkeyup="handleSearch(this.value)">
    </div>
    
    <div class="list" id="sidebarList"></div>
    
    <div class="menu-grid">
        <div class="menu-toggle-item <?php echo $isReportLocked ? 'full-width' : ''; ?>">
            <span>نوتیفیکیشن</span>
            <label class="toggle-switch">
                <input type="checkbox" id="notifToggle" onchange="toggleNotifications(this)">
                <span class="slider"></span>
            </label>
        </div>
        <?php if (!$isReportLocked && empty($_SESSION['is_demo'])): ?>
        <div class="menu-toggle-item" style="cursor:pointer;" onclick="openReportModal()">
            <span>گزارش کاربر</span>
            <span style="display:flex;"><img class="i3d i3d-sm" src="assets/icons3d/warning.png" alt=""></span>
        </div>
        <?php endif; ?>
        <?php if (empty($_SESSION['is_demo'])): ?>
        <div class="menu-item" onclick="openMyProfile()"><img class="i3d" src="assets/icons3d/user.png" alt=""> پروفایل من</div>
        <div class="menu-item" onclick="showMySecurityCode()"><img class="i3d" src="assets/icons3d/seccode.png" alt=""> کد امنیتی من</div>
        <div class="menu-item" onclick="openPhoneSettings()"><img class="i3d" src="assets/icons3d/phone.png" alt=""> شماره موبایل <span id="menuPhoneDisplay" style="font-size:11px;color:#64748b;margin-right:4px;"></span></div>
        <div class="menu-item" id="menuDeleteAllItem" onclick="askDeleteAll()"><img class="i3d" src="assets/icons3d/trash.png" alt=""> حذف پیام‌های من</div>
        <div class="menu-item" onclick="openCreateRoom()"><img class="i3d" src="assets/icons3d/sparkles.png" alt=""> ساخت اتاق و کانال</div>
        <?php endif; ?>
        <div class="menu-item" onclick="openAppearance()"><img class="i3d" src="assets/icons3d/moon.png" alt=""> ظاهر و تم</div>
        <div class="menu-item full-width" onclick="askLogout()">خروج از حساب</div>
        <?php if(!empty($donate_link)): ?>
        <div class="menu-item full-width" onclick="openDonate()" style="background:linear-gradient(135deg,rgba(245,158,11,0.2),rgba(217,119,6,0.15));border-color:rgba(245,158,11,0.3);color:#fbbf24;"><img class="i3d" src="assets/icons3d/heart.png" alt=""> دونیت / حمایت مالی</div>
        <?php endif; ?>
        <div class="menu-item full-width" id="adminPanelBtn" style="display:none; background:var(--v-soft, rgba(99,102,241,0.2)); color:var(--v-accent, #a5b4fc)" onclick="location.href='admin.php'"><img class="i3d" src="assets/icons3d/gear.png" alt=""> پنل مدیریت پیشرفته</div>
    </div>
</div>

<div class="main">
    <div id="chatTransOverlay" class="chat-trans-overlay">
        <div class="chat-trans-spin-wrap">
            <div class="chat-trans-spin-outer"></div>
            <div class="chat-trans-spin-inner"></div>
            <div class="chat-trans-dot"></div>
        </div>
        <div class="chat-trans-name" id="chatTransName"></div>
        <div class="chat-trans-sub">در حال بارگذاری...</div>
    </div>
    <div class="header">
        <div style="display:flex; align-items:center; gap:10px; min-width:0; flex:1;">
            <div class="ham-wrap" style="flex-shrink:0;">
                <button type="button" class="btn ham-btn" onclick="toggleMenu()" aria-label="منو" title="منو">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round"><line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="16" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/></svg>
                </button>
                <span class="ham-badge" id="hamBadge"></span>
            </div>
            <div style="min-width:0; flex:1; overflow:hidden;">
                <span id="chatTitle" class="header-title"></span>
                <span id="chatStatus" class="header-status"></span>
                <span id="wsStatusDot" style="display:none;"></span><span id="wsStatusLabel" style="display:none;"></span>
            </div>
        </div>
        <div class="social-icons" style="flex-shrink:0; margin-right:8px;">
            <button class="search-btn" onclick="toggleSearchPanel()" title="جستجو در پیام‌ها" id="searchToggleBtn">
                <img class="i3d" src="assets/icons3d/search.png" alt="">
            </button>
            <button onclick="openHelp()" class="social-btn" title="به راهنما نیاز داری؟" style="cursor:pointer; color:#cbd5e1;"><img class="i3d" src="assets/icons3d/question.png" alt=""></button>
            <button type="button" id="pvBlockBtn" onclick="toggleBlockUser()" title="مسدود کردن کاربر" style="display:none; cursor:pointer; border:0; background:transparent; padding:0;"><img class="i3d" src="assets/icons3d/shield.png" alt=""></button>
            <?php if(!empty($source_download_link)): ?>
            <a href="<?php echo $source_download_link; ?>" target="_blank" class="social-btn" title="دانلود سورس کد" style="font-size:16px; color:#cbd5e1; text-decoration:none;">⬇</a>
            <?php endif; ?>
            <?php echo veyrax_social_icons_html(); ?>
        </div>
    </div>

    <div class="search-panel" id="searchPanel">
        <div class="search-input-wrap">
            <input type="text" class="search-input-field" id="searchField" placeholder="جستجو در پیام‌های این چت..." oninput="onSearchInput(this.value)" onkeydown="if(event.key==='Escape')closeSearchPanel()">
            <button class="search-clear-btn" onclick="clearSearch()" title="پاک کردن">✕</button>
        </div>
        <button class="search-close-btn" onclick="closeSearchPanel()">✕</button>
    </div>
    <div class="search-results-panel" id="searchResultsPanel"></div>

    <div id="pinBar" class="pin-bar">
        <div class="pin-bar-left" onclick="openPinBarPopup()">
            <div class="pin-bar-icon-wrap">
                <span class="pin-bar-icon">📌</span>
            </div>
            <div class="pin-bar-texts">
                <span class="pin-bar-label">پیام پین شده</span>
                <span class="pin-bar-preview" id="pinBarPreview"></span>
            </div>
        </div>
        <div class="pin-bar-nav">
            <button class="pin-bar-nav-btn" id="pinBarPrev" onclick="event.stopPropagation();navigatePin(-1)" title="پین قبلی">‹</button>
            <span class="pin-bar-count" id="pinBarCount">1/1</span>
            <button class="pin-bar-nav-btn" id="pinBarNext" onclick="event.stopPropagation();navigatePin(1)" title="پین بعدی">›</button>
        </div>
        <button class="pin-bar-unpin" id="pinBarUnpin" onclick="event.stopPropagation(); pinBarDismiss()" title="حذف همه پین‌ها">✕</button>
    </div>

    <?php
    // ── Demo banner ─────────────────────────────────────────────────────────
    // A visitor in the sandbox must never be confused about whether what they
    // are typing is real. The strip is rendered server-side (so it cannot be
    // toggled off from the console) and the counter comes from the session.
    $vxIsDemo = !empty($_SESSION['is_demo']);
    if ($vxIsDemo):
        $vxDemoLeft = max(0, (int)VEYRAX_DEMO_MAX_MSGS - (int)($_SESSION['demo_msg_count'] ?? 0));
    ?>
    <style>
        .demo-strip {
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
            margin: 10px 12px 0; padding: 9px 14px; border-radius: 12px;
            background: linear-gradient(90deg, rgba(99,102,241,0.16), rgba(139,92,246,0.10));
            border: 1px solid rgba(129,140,248,0.32);
            color: #c7d2fe; font-size: 0.8rem; line-height: 1.6;
        }
        .demo-strip-dot {
            width: 8px; height: 8px; border-radius: 50%; background: #818cf8;
            box-shadow: 0 0 0 0 rgba(129,140,248,0.6); animation: demoPulse 2s infinite;
            flex: 0 0 auto;
        }
        @keyframes demoPulse {
            0%   { box-shadow: 0 0 0 0 rgba(129,140,248,0.55); }
            70%  { box-shadow: 0 0 0 8px rgba(129,140,248,0); }
            100% { box-shadow: 0 0 0 0 rgba(129,140,248,0); }
        }
        .demo-strip b { color: #a5b4fc; }
        .demo-strip-left { flex: 1 1 auto; min-width: 180px; }
        .demo-strip-badge {
            flex: 0 0 auto; padding: 3px 10px; border-radius: 999px;
            background: rgba(129,140,248,0.18); border: 1px solid rgba(129,140,248,0.35);
            color: #c7d2fe; font-weight: 700; font-size: 0.75rem; white-space: nowrap;
        }
        .demo-strip-badge.is-low { background: rgba(245,158,11,0.16); border-color: rgba(245,158,11,0.4); color: #fcd34d; }
        .demo-strip-exit {
            flex: 0 0 auto; padding: 4px 12px; border-radius: 9px; cursor: pointer;
            background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.16);
            color: #e2e8f0; font-size: 0.75rem; font-family: inherit;
        }
        .demo-strip-exit:hover { background: rgba(255,255,255,0.14); }
        @media (max-width: 520px) {
            .demo-strip { font-size: 0.72rem; padding: 8px 10px; }
            .demo-strip-badge { display: none; }
        }
    </style>
    <div class="demo-strip" id="demoStrip">
        <span class="demo-strip-dot"></span>
        <div class="demo-strip-left">
            <b>حالت نمایشی</b> — این محیط آزمایشی است. پیام‌های شما پس از خروج خودکار پاک می‌شوند.
        </div>
        <span class="demo-strip-badge<?php echo $vxDemoLeft <= 3 ? ' is-low' : ''; ?>" id="demoLeftBadge">
            <?php echo (int)$vxDemoLeft; ?> پیام باقی‌مانده
        </span>
        <button class="demo-strip-exit" onclick="window.location.href='requests.php?logout=1&nonce=<?php echo urlencode($_SESSION['logout_nonce'] ?? ''); ?>'">خروج از دمو</button>
    </div>
    <?php endif; ?>

    <div class="messages" id="messagesBox">
        <div id="loadMoreBtn" class="load-more-box" onclick="loadMoreMessages()" style="display:none;">بارگذاری پیام‌های قبلی ⟳</div>
        <div id="msgsContent"></div>
        <div class="typing-indicator" id="typingStatus">در حال نوشتن</div>
    </div>

    <div class="scroll-controls">
        <div class="scroll-btn up" onclick="scrollToTop()">
            <svg viewBox="0 0 24 24"><path d="M12 8l6 6H6z"/></svg>
        </div>
        <div class="scroll-btn down" id="scrollDownBtn" onclick="scrollToBottom()">
            <svg viewBox="0 0 24 24"><path d="M12 16l-6-6h12z"/></svg>
            <span id="scrollUnreadBadge" style="display:none; position:absolute; top:-6px; right:-6px; background:#ef4444; color:#fff; font-size:11px; border-radius:4px; min-width:22px; height:22px; justify-content:center; align-items:center; font-weight:bold; box-shadow: 0 2px 5px rgba(239,68,68,0.5); padding:0 4px;"></span>
        </div>
    </div>

    <div id="blockBanner" class="block-banner" style="display:none;">
        <span class="block-banner-ico">🚫</span>
        <span class="block-banner-txt" id="blockBannerTxt">این کاربر مسدود شده است</span>
        <button type="button" class="block-banner-btn" id="blockBannerBtn" onclick="toggleBlockUser()">رفع بلاک</button>
    </div>

    <div class="input-box" id="inputBox">
        <div id="sendProgress" aria-hidden="true"></div>
        <div class="file-preview" id="filePreview">
            <div class="fp-thumb" id="fpThumb">📎</div>
            <div class="fp-info">
                <b id="fileName" class="fp-name"></b>
                <span class="fp-meta" id="fileMeta"></span>
            </div>
            <button type="button" class="fp-x" onclick="cancelFile()" aria-label="حذف فایل">✕</button>
        </div>
        <div class="reply-bar" id="replyBar">
            <span class="ai-reply-chip" id="aiReplyChip"><img src="assets/icons3d/sparkles.png" alt="">در حال گفتگو با دستیار</span>
            <div style="display:flex; flex-direction:column; flex:1 1 auto; min-width:0">
                <span class="reply-bar-label" id="replyLabel">پاسخ به:</span>
                <span id="replyText" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; font-size:12px;"></span>
            </div>
            <span onclick="cancelReply()" style="cursor:pointer; color:#ef4444; padding:5px;">✕</span>
        </div>

        <div class="input-row" id="inputRow">
            <input type="file" id="fileInput" hidden multiple accept="*">
            <div class="composer-pill" id="composerPill">
                <button type="button" class="composer-attach" id="attachBtn" onclick="document.getElementById('fileInput').click()" title="پیوست فایل" aria-label="پیوست فایل"<?php echo $vxIsDemo ? ' style="display:none"' : ''; ?>><img src="assets/icons3d/clip.png" alt=""></button>
                <textarea id="msgInput" placeholder="<?php echo $vxIsDemo ? 'پیام آزمایشی…' : 'پیام...'; ?>" maxlength="320" rows="1" autocomplete="off" spellcheck="false"></textarea>
                <button type="button" id="emojiBtn" class="composer-emoji" onclick="toggleEmojiPicker()" title="ایموجی" aria-label="ایموجی"><img src="assets/icons3d/smile.png" alt=""></button>
            </div>
            <button class="btn" id="micBtn" style="touch-action:none;<?php echo $vxIsDemo ? 'display:none;' : ''; ?>" title="پیام صوتی" aria-label="پیام صوتی"><img class="i3d-lg" src="assets/icons3d/mic.png" alt=""></button>
            <button class="btn btn-send" id="sendBtn" onclick="sendMessage()" title="ارسال" aria-label="ارسال"><svg class="send-ico" viewBox="0 0 24 24" width="24" height="24" fill="none" aria-hidden="true"><defs><linearGradient id="spA" x1="2" y1="6" x2="20" y2="18" gradientUnits="userSpaceOnUse"><stop stop-color="#ffffff"/><stop offset="1" stop-color="#bfdbfe"/></linearGradient></defs><path d="M2.4 3.2l18.9 8.3c.5.2.5.8 0 1L2.4 20.8c-.5.2-1-.2-.9-.7l1.1-6.4c.1-.3.3-.6.7-.6l10.4-1.1L3.3 10.9c-.3 0-.6-.3-.7-.6L1.5 3.9c-.1-.5.4-.9.9-.7z" fill="url(#spA)"/><path d="M2.4 3.2l18.9 8.3c.2.1.3.3.3.5L3.3 10.9c-.3 0-.6-.3-.7-.6L1.5 3.9c-.1-.5.4-.9.9-.7z" fill="#ffffff" opacity=".55"/></svg></button>
        </div>
        
        <div id="emojiPicker" style="display:none;">
            <div class="ep-head">
                <div class="ep-cats" id="epCats"></div>
                <button type="button" class="ep-close" onclick="toggleEmojiPicker()" title="بستن">✕</button>
            </div>
            <div class="ep-search"><input id="epSearch" type="text" placeholder="جستجوی ایموجی..." autocomplete="off"></div>
            <div id="epGrid" class="ep-grid"></div>
        </div>
        
        <div class="voice-rec" id="voiceUI">
            <span class="vr-dot"></span>
            <span id="recTimer" class="vr-time">00:00</span>
            <span class="vr-bars"><i></i><i></i><i></i><i></i><i></i></span>
            <span class="vr-hint">برای قفل بالا بکشید</span>
            <button type="button" class="vr-btn vr-cancel" onclick="cancelVoice()" title="لغو" aria-label="لغو">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
            <button type="button" class="vr-btn vr-send" id="sendVoiceBtn" onclick="stopRecManual()" title="ارسال" aria-label="ارسال">
                <svg viewBox="0 0 24 24" width="17" height="17" fill="currentColor"><path d="M3.4 20.4l17.45-7.48a1 1 0 000-1.84L3.4 3.6a.993.993 0 00-1.39.91L2 9.12c0 .5.37.93.87.99L17 12 2.87 13.88c-.5.07-.87.5-.87 1l.01 4.61c0 .71.73 1.2 1.39.91z"/></svg>
            </button>
        </div>
    </div>
</div>

<script>
let csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
let bwNonce = document.querySelector('meta[name="bw-nonce"]').getAttribute('content');
var VX_RUNTIME = <?php echo json_encode([
    'requestMode'   => $reqMode,
    'cacheEpoch'    => $cacheEpoch,
    'cacheTtlMs'    => $cacheTtlHours * 3600 * 1000,
    'assetVersion'  => VEYRAX_ASSET_VERSION,
], JSON_UNESCAPED_UNICODE); ?>;
const WS_CONFIG = <?php echo json_encode([
    'enabled'   => (bool)$ws_runtime['enabled'],
    'url'       => $ws_runtime['url'],
    'origins'   => $ws_runtime['origins'],
    'reconnect' => (int)$ws_runtime['reconnect'],
    // Short-lived token embedded once, so the first connection needs no extra
    // round-trip to ws_auth.php. Reconnects still fetch a fresh token.
    'token'     => (isset($_SESSION['user_id']) && !empty($ws_runtime['url']))
                    ? ws_issue_token((int)$_SESSION['user_id'], (string)($_SESSION['username'] ?? ''))
                    : '',
]); ?>;
var VX_WELCOME_SEEN = <?php echo $welcomeSeen ? '1' : '0'; ?>;

// One formatter for every unread counter: 1–99 exact, above that "99+".
// Keeps the sidebar, the header badge and the scroll pill in agreement.
function vxBadge(n) {
    n = parseInt(n, 10) || 0;
    if (n <= 0) return '';
    return n > 99 ? '99+' : String(n);
}
let logoutNonce = document.querySelector('meta[name="logout-nonce"]').getAttribute('content');

// ── Inspect mode ────────────────────────────────────────────────────────────
// The page has its own right-click menus (message / room / user). Those menus
// call preventDefault themselves, so the browser menu is *not* blocked
// globally any more — right-click anywhere else (background, header, sidebar
// gap, the composer) opens the native menu and "Inspect element" works.
//   • Shift + right-click on a message / room / user → skips the app menu
//   • ?inspect=1 (sticky) or Alt+Shift+I            → skips every app menu
//   • ?inspect=0                                    → turn it back off
var V_INSPECT = (function () {
    try {
        var q = new URLSearchParams(location.search).get('inspect');
        if (q === '1') { sessionStorage.setItem('v_inspect', '1'); return true; }
        if (q === '0') { sessionStorage.removeItem('v_inspect'); return false; }
        return sessionStorage.getItem('v_inspect') === '1';
    } catch (e) { return false; }
})();

function _applyInspectMode(on) {
    document.documentElement.classList.toggle('v-inspect', !!on);
    var badge = document.getElementById('inspectBadge');
    if (on) {
        if (!badge) {
            badge = document.createElement('div');
            badge.id = 'inspectBadge';
            badge.innerHTML = '<span>حالت Inspect فعال — راست‌کلیک روی همه‌چیز آزاد است</span><b>✕</b>';
            badge.title = 'برای خروج کلیک کنید (یا Alt+Shift+I)';
            badge.addEventListener('click', function (ev) {
                ev.stopPropagation();
                try { sessionStorage.removeItem('v_inspect'); } catch (e) {}
                location.href = location.pathname;
            });
            document.body.appendChild(badge);
        }
    } else if (badge) {
        badge.remove();
    }
}

function _vInspectBypass(e) {
    return !!(V_INSPECT || (e && e.shiftKey));
}

// Alt+Shift+I toggles inspect mode without touching the URL.
document.addEventListener('keydown', function (e) {
    if (e.altKey && e.shiftKey && (e.code === 'KeyI' || e.key === 'I' || e.key === 'i' || e.key === 'ی')) {
        e.preventDefault();
        V_INSPECT = !V_INSPECT;
        try {
            if (V_INSPECT) sessionStorage.setItem('v_inspect', '1');
            else sessionStorage.removeItem('v_inspect');
        } catch (err) {}
        _applyInspectMode(V_INSPECT);
        try { showToast(V_INSPECT ? 'حالت Inspect روشن شد' : 'حالت Inspect خاموش شد'); } catch (err) {}
    }
}, false);

_applyInspectMode(V_INSPECT);

// Demo visitors start inside the sandbox room, not in the public one. The
// server pins every request to that room anyway; this just keeps the header,
// the highlight and the composer honest from the first paint.
const VX_IS_DEMO  = <?php echo !empty($_SESSION['is_demo']) ? 'true' : 'false'; ?>;
const VX_DEMO_ROOM = <?php echo json_encode(VEYRAX_DEMO_ROOM, JSON_UNESCAPED_UNICODE); ?>;
// First configured wake word — the banner and intro copy follow the admin's setting.
const VX_AI_KEYWORD = <?php
    $vxKwRaw = trim(veyrax_setting($pdo, 'ai_keyword', ''));
    $vxKwList = array_values(array_filter(array_map('trim', preg_split('/[,\n]/u', $vxKwRaw))));
    echo json_encode($vxKwList[0] ?? 'ویراکس', JSON_UNESCAPED_UNICODE);
?>;
let currentRoom = VX_IS_DEMO ? VX_DEMO_ROOM : 'گفتگوی عمومی', currentRoomId = null, targetUser = null, currentUser = null, isAdmin = false, canPin = false, canDelete = true;
// Last conversation fingerprint the server reported. It changes when an
// existing row is edited, reacted to, or has its media removed — none of which
// move the row id, so the incremental "id > after_id" channel cannot see them.
// Declared here (not next to the long-poll) so it exists before the first fetch.
var _chatRev = '';
// The sticker this user picked. Own messages used to be drawn with a generic
// 👤 while sending and then never corrected, so the real sticker only appeared
// after a page reload. It is refreshed from every fetch payload, because the
// session copy can be stale after a sticker change.
let MY_STICKER = <?php echo json_encode($_SESSION['sticker'] ?? '👤', JSON_UNESCAPED_UNICODE); ?>;
let _phoneAuthModalOpen = false;
let currentUsername = '';
let replyToId = null, mediaRecorder, audioChunks=[], isRecording=false, recInterval, isLocked=false, startY=0, recStartedAt=0;
let ctxId=null, ctxName=null, selectedMsgId=null, selectedMsgSender=null, selectedMsgText=null;
let lastMsgId = 0, notifQueue = [], isShowingNotif = false, notifData = null;

let lastUnreadCounts = {}; 
let myInvites = [];
let allRoomsCache = [];
let allUsersCache = [];
let _stableUserOrder = [];
let replyContent = null;
let replyName = null;
let shownNotificationIds = new Set();
let oldestMsgId = null;
let _historyComplete = false;
let typingTimeout;
let msgCache = {};
let searchTimeout;
let lastJoinAttempt = 0;
let notificationsEnabled = false;
let uploadQueue = [];
let isUploading = false;
let isLoadingMore = false;
let newMessagesSinceScroll = 0;
let userScrolledUp = false;
let _programScrollUntil = 0;
let _pendingScrollToMsgId = null;
let _lastRenderedKey = '';
let _isRenderingMessages = false;
let videoThumbCache = {};
let roomUnreadLocal = {}; 
let loadedHistoryHtml = '';
const MAX_FILE_SIZE = <?php echo $file_size_limit; ?>;

if (window.visualViewport) {
    window.visualViewport.addEventListener('resize', () => {
        document.body.style.height = window.visualViewport.height + 'px';
        const box = document.getElementById('messagesBox');
        if (box) {
            box.scrollTop = box.scrollHeight; 
        }
    });
}

function escapeHTML(str) {
    if (!str) return '';
    return str.replace(/[&<>'"]/g, function(tag) {
        const charsToReplace = { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' };
        return charsToReplace[tag] || tag;
    });
}

// ── Security-code modal ──────────────────────────────────────────────────────
// The dialog renders the code as one cell per character. Every existing caller
// still writes the raw value into #newSecurityCodeDisplay, so a MutationObserver
// repaints the cells instead of touching each call site.
function scmPaint() {
    var rawEl = document.getElementById('newSecurityCodeDisplay');
    var box = document.getElementById('scmCodeBox');
    if (!rawEl || !box) return;
    var raw = (rawEl.textContent || '').trim();
    if (!raw) { box.innerHTML = ''; box.classList.remove('scm-in'); return; }
    box.innerHTML = raw.split('').map(function (ch, i) {
        var safe = ch.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        return '<span class="scm-char" style="--i:' + i + '">' + safe + '</span>';
    }).join('');
    box.classList.remove('scm-in');
    void box.offsetWidth; // restart the entrance animation
    box.classList.add('scm-in');
}

(function () {
    var el = document.getElementById('newSecurityCodeDisplay');
    if (!el || typeof MutationObserver === 'undefined') return;
    new MutationObserver(scmPaint).observe(el, { childList: true, characterData: true, subtree: true });
})();

function _scmResetButton() {
    var btn = document.getElementById('resetSecCodeBtn');
    if (btn) { btn.classList.remove('armed'); btn.disabled = false; btn.textContent = 'ریست کد'; }
    var warn = document.getElementById('resetSecWarning');
    if (warn) warn.style.display = 'none';
}

function showMySecurityCode() {
    document.getElementById('newSecurityCodeDisplay').textContent = '<?php echo $secCode; ?>';
    _scmResetButton();
    openModal('securityCodeModal');
    closeMenu();
}

function resetSecurityCode() {
    const warn = document.getElementById('resetSecWarning');
    const btn = document.getElementById('resetSecCodeBtn');
    if (warn.style.display === 'none') {
        warn.style.display = 'block';
        btn.classList.add('armed');
        btn.textContent = 'تایید ریست';
        return;
    }
    btn.disabled = true;
    btn.textContent = 'در حال ساخت...';
    apiCall('chat.php', 'reset_security_code', {}, (d) => {
        if (d.status === 'success') {
            document.getElementById('newSecurityCodeDisplay').textContent = d.new_code;
            _scmResetButton();
            showToast('کد امنیتی جدید ساخته شد. حتماً آن را ذخیره کنید!');
        } else {
            showToast(d.message || 'خطا در ریست کد');
            _scmResetButton();
        }
    });
}

function toggleMenu() {
    const sidebar = document.getElementById('sidebar');
    const bd = document.querySelector('.backdrop');
    if (window.innerWidth >= 769) {
        sidebar.classList.toggle('closed');
        return;
    }
    const willOpen = !sidebar.classList.contains('open');
    sidebar.classList.toggle('open', willOpen);
    if (bd) bd.classList.toggle('show', willOpen);
    document.documentElement.classList.toggle('v-menu-open', willOpen);
}

function closeMenu() {
    if (window.innerWidth >= 769) return;
    document.getElementById('sidebar').classList.remove('open');
    const bd = document.querySelector('.backdrop');
    if (bd) bd.classList.remove('show');
    document.documentElement.classList.remove('v-menu-open');
}

// Escape closes the drawer, and rotating the phone back to landscape puts the
// sidebar in its permanent desktop position without leaving a stuck overlay.
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeMenu();
});
window.addEventListener('resize', function () {
    if (window.innerWidth >= 769) closeMenu();
});

function openModal(id) {
    const m = document.getElementById(id);
    m.style.display = 'flex';
    if(id !== 'msgMenu') m.style.visibility = 'visible';
    requestAnimationFrame(function() {
        requestAnimationFrame(function() {
            m.classList.add('active');
        });
    });
}

function closeModal(id) {
    const m = document.getElementById(id);
    m.classList.remove('active');
    setTimeout(function() {
        m.style.display = 'none';
        if(id !== 'msgMenu') m.style.visibility = 'hidden';
    }, 320);
}

function showToast(msg) { 
    const t = document.getElementById("toast"); 
    t.innerHTML = msg; 
    t.classList.add("show"); 
    setTimeout(() => t.classList.remove("show"), 3000); 
}

function showConfirm(msg, cb) { 
    document.getElementById('confirmMsg').textContent = msg; openModal('modalConfirm');
    const oldBtn = document.getElementById('confirmYes'); const newBtn = oldBtn.cloneNode(true);
    oldBtn.parentNode.replaceChild(newBtn, oldBtn);
    newBtn.onclick = () => { cb(); closeModal('modalConfirm'); }; 
}

function showPrompt(msg, val, cb) { 
    document.getElementById('promptMsg').textContent = msg; const i = document.getElementById('promptInput'); i.value = val || '';
    openModal('modalPrompt'); i.focus();
    const oldBtn = document.getElementById('promptOk'); const newBtn = oldBtn.cloneNode(true);
    oldBtn.parentNode.replaceChild(newBtn, oldBtn);
    newBtn.onclick = () => { if(i.value) cb(i.value); closeModal('modalPrompt'); }; 
}

function performLogout() {
    fetch(`requests.php?logout=1&nonce=${logoutNonce}`)
    .then(() => {
        window.location.href = 'index.php';
    })
    .catch(() => {
        window.location.href = 'index.php';
    });
}

function askLogout() { 
    showConfirm('آیا مطمئن هستید که می‌خواهید خارج شوید؟', performLogout); 
}

function handleBan(message, type) {
    document.getElementById('banMessageDetail').innerHTML = message ? message.replace(/\n/g, '<br>') : 'دسترسی شما به سیستم محدود شده است.';
    
    const modal = document.getElementById('banModal');
    modal.classList.add('active');
    
    const content = modal.querySelector('.ban-modal-content');
    content.style.background = 'linear-gradient(145deg, #7f1d1d, #450a0a)';
    content.style.borderColor = '#ef4444';
    modal.querySelector('.ban-icon').style.filter = 'drop-shadow(0 0 25px rgba(239, 68, 68, 0.8))';
    
    if (type !== 'perm') {
        setTimeout(() => {
            window.location.href = 'requests.php?logout=1&nonce=' + logoutNonce;
        }, 5000);
    }
}

let _sessionBurnedHandled = false;
function handleSessionBurned() {
    if (_sessionBurnedHandled) return;
    _sessionBurnedHandled = true;

    // Stop every background loop first — otherwise the poll keeps firing with
    // a dead session and the page looks "frozen" instead of logged out.
    try { if (typeof _pollTimer !== 'undefined' && _pollTimer) { clearTimeout(_pollTimer); _pollTimer = null; } } catch (e) {}
    try { if (typeof _fetchToken !== 'undefined') _fetchToken++; } catch (e) {}
    try { if (typeof _phoneAuthTimer !== 'undefined' && _phoneAuthTimer) clearInterval(_phoneAuthTimer); } catch (e) {}
    try { if (window.veyraxWs && window.veyraxWs.disconnect) window.veyraxWs.disconnect(); } catch (e) {}

    try {
        fetch('requests.php?logout=1&nonce=' + encodeURIComponent(logoutNonce), { cache: 'no-store' }).catch(() => {});
    } catch(e) {}

    // Wipe anything that could leak the previous identity back into the UI.
    try {
        ['bw_last_target','bw_last_target_name','bw_last_room','bw_last_room_id','welcomeShown','newSecurityCode','bw_needs_phone']
            .forEach(function (k) { localStorage.removeItem(k); });
    } catch (e) {}

    var content = document.getElementById('msgsContent');
    if (content) content.innerHTML = '';
    var box = document.getElementById('messagesBox');
    if (box) box.innerHTML = '';
    var input = document.getElementById('msgInput');
    if (input) { input.value = ''; input.disabled = true; input.placeholder = 'نشست پایان یافت'; }

    const modal = document.getElementById('sessionBurnedModal');
    if (!modal) { window.location.href = 'index.php'; return; }
    modal.style.display = 'flex';
    modal.style.visibility = 'visible';
    modal.classList.add('active');

    const prevContainer = document.getElementById('burnedPrevUsers');
    const codeBtn = document.getElementById('burnedLoginCodeBtn');

    const cookieToken = (document.cookie.match(/(?:^|;\s*)device_token=([^;]*)/) || [])[1] || '';
    const clientUa = navigator.userAgent;

    if (cookieToken || clientUa) {
        fetch('requests.php', {
            method: 'POST',
            body: (() => { const f = new FormData(); f.append('action', 'get_prev_users_for_burned'); return f; })()
        })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'success' && d.users && d.users.length > 0) {
                let html = '<div style="font-size:12px; color:#94a3b8; margin-bottom:8px; font-weight:bold;">حساب‌های شناسایی‌شده روی این دستگاه:</div>';
                d.users.forEach(u => {
                    html += `<div style="background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); border-radius:12px; padding:10px 14px; margin-bottom:8px; display:flex; align-items:center; gap:10px; cursor:pointer;" onclick="window.location.href='index.php'">
                        <span style="font-size:22px;">${u.sticker}</span>
                        <div style="flex:1; text-align:right;">
                            <div style="color:#e2e8f0; font-size:13px; font-weight:bold;">${u.username}</div>
                            <div style="font-size:10px; color:#64748b; margin-top:2px;">برای ورود کلیک کنید</div>
                        </div>
                        <span style="color:#10b981; font-size:11px;">ورود</span>
                    </div>`;
                });
                prevContainer.innerHTML = html;
                prevContainer.style.display = 'block';
                codeBtn.style.display = 'block';
            }
        }).catch(() => {});
    }
}

function showNotification(senderId, name, sticker, txt, msgId, roomName) {
    if (!notificationsEnabled) return;
    if (msgId && shownNotificationIds.has(msgId)) return;
    if (msgId) shownNotificationIds.add(msgId);
    
    if(notifQueue.some(n => n.senderId === senderId && n.txt === txt)) return; 
    notifQueue.push({senderId, name, sticker, txt, msgId, roomName});
    processNotifQueue();
}

function processNotifQueue() {
    if (!notificationsEnabled) {
        notifQueue = [];
        isShowingNotif = false;
        document.getElementById('notifPopup').classList.remove('show');
        return;
    }

    if (isShowingNotif || notifQueue.length === 0) return;
    
    const item = notifQueue[0];
    
    if (targetUser && item.senderId == targetUser) {
        notifQueue.shift();
        processNotifQueue();
        return;
    }
    
    isShowingNotif = true;
    notifQueue.shift();
    notifData = item;
    
    const p = document.getElementById('notifPopup');
    document.getElementById('notifAvatar').textContent = item.sticker;
    document.getElementById('notifName').textContent = item.name;
    document.getElementById('notifText').textContent = item.txt;

    const isRoom = !!(item.roomName && item.roomName !== 'null');
    p.classList.toggle('kind-room', isRoom);
    const kindEl = document.getElementById('notifKind');
    if (kindEl) kindEl.textContent = isRoom ? item.roomName : 'پیام خصوصی';
    const hintEl = document.getElementById('notifHintText');
    if (hintEl) hintEl.textContent = isRoom ? 'مشاهده در گفتگو' : 'پاسخ در پیوی';

    p.classList.add('show');
    
    const sound = new Audio('data:audio/wav;base64,UklGRl9vT19XQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YU'); 
    sound.play().catch(()=>{});

    setTimeout(() => {
        p.classList.remove('show');
        setTimeout(() => { isShowingNotif = false; notifData = null; setTimeout(processNotifQueue, 600); }, 500);
    }, 4500);
}

function handleNotifClick() {
    if(notifData) {
        if (notifData.type === 'room_invite') {
            switchRoom(notifData.room, notifData.room, notifData.roomId);
        } else if (notifData.roomName && notifData.roomName !== 'null') {
            switchRoom(notifData.roomName, notifData.roomName, null);
        } else {
            startPrivate(notifData.senderId, notifData.name);
        }
        document.getElementById('notifPopup').classList.remove('show');
        isShowingNotif = false;
        notifData = null;
    }
}

function toggleNotifications(el) {
    notificationsEnabled = el.checked;
    if (!notificationsEnabled) {
        notifQueue = [];
        isShowingNotif = false;
        document.getElementById('notifPopup').classList.remove('show');
    }
    apiCall('chat.php', 'toggle_notifications', {state: notificationsEnabled ? 1 : 0});
}

function apiCall(file, action, data, cb, errCb) {
    const fd = new FormData();
    fd.append('action', action); fd.append('csrf_token', csrfToken);
    if(bwNonce) fd.append('bw_nonce', bwNonce);
    for(let k in data) fd.append(k, data[k]);
    
    fetch(file, {method:'POST', body:fd})
    .then(r => r.text())
    .then(text => {
        if (text.includes('دسترسی شما به دلیل نقض قوانین') || text.trim().startsWith('<div')) {
            handleBan(null, 'perm');
            if(errCb) errCb(null);
            return;
        }
        try {
            const d = JSON.parse(text);
            if(d.status === 'banned') { handleBan(d.message, d.ban_type || 'temp'); if(errCb) errCb(d); }
            else if(d.status === 'session_burned') { handleSessionBurned(); }
            else if(d.status === 'rate_limited') { _netBackoff(d.retry_after || 2, true); if(errCb) errCb(d); }
            else if(d.status === 'error' && d.message === 'کاربر یافت نشد') { window.location.href = 'index.php'; if(errCb) errCb(d); }
            else if(d.status === 'auth_fail') { window.location.href = 'index.php'; if(errCb) errCb(d); }
            else if(d.status === 'access_denied') { try { localStorage.removeItem('bw_last_target'); localStorage.removeItem('bw_last_target_name'); localStorage.removeItem('bw_last_room'); localStorage.removeItem('bw_last_room_id'); } catch(e){} openModal('accessDeniedModal'); if(errCb) errCb(d); }
            else if(d.status === 'success') { if(cb) cb(d); }
            else if(d.status === 'error') { if(errCb) errCb(d); else showToast(d.message || 'خطا'); }
            else { if(errCb) errCb(d); else showToast(d.message || 'خطا'); }
        } catch(e) {
            if(errCb) errCb(null);
        }
    }).catch(function() {
        if(errCb) errCb(null);
    });
}

// ── Link & proxy detection ──────────────────────────────────────────────────
// A web address and a proxy subscription line are two different things, but
// they used to share one detector: `http` sat in the proxy scheme list, so any
// ordinary link produced a "proxy config" card. They are separate now — a link
// is a link, and a proxy needs a real proxy scheme (or an HTTP proxy URL that
// carries credentials and a port).
const VX_PROXY_SCHEMES = 'vless|vmess|trojan|ssr|ss|socks5|socks|hysteria2|hysteria|tuic|warp|wireguard|anytls|mtproto|nekoray|nekobox|sing-box|netmod|outline|ssconf';

function isProxyConfig(t) {
    if (!t) return false;
    const s = String(t).trim();
    if (!s) return false;
    if (new RegExp('^(?:' + VX_PROXY_SCHEMES + '):\\/\\/', 'i').test(s)) return true;
    if (/^tg:\/\/proxy/i.test(s)) return true;
    if (/^https?:\/\/t\.me\/proxy/i.test(s)) return true;
    // A real HTTP proxy URI has credentials and a port; a normal site does not.
    if (/^https?:\/\/[^\s\/@]+@[^\s\/]+:\d+/i.test(s)) return true;
    if (/^\[Interface\]/i.test(s)) return true;
    if (/^\{[\s\S]*"outbounds"/i.test(s)) return true;
    return false;
}

function getProxyType(line) {
    const s = String(line).trim();
    if (/^\[Interface\]/i.test(s)) return 'WireGuard';
    if (/^tg:\/\/proxy/i.test(s) || /^https?:\/\/t\.me\/proxy/i.test(s)) return 'Telegram';
    if (/^https?:\/\//i.test(s)) return 'HTTP';
    const m = s.match(/^([a-z0-9\-_]+):\/\//i);
    if (!m) return 'Proxy';
    const p = m[1].toLowerCase();
    const map = {
        'vless': 'VLESS', 'vmess': 'VMess', 'trojan': 'Trojan', 'ss': 'Shadowsocks',
        'ssr': 'SSR', 'hysteria': 'Hysteria', 'hysteria2': 'Hysteria2', 'tuic': 'TUIC',
        'warp': 'WARP', 'wireguard': 'WireGuard', 'socks5': 'SOCKS5', 'socks': 'SOCKS',
        'http': 'HTTP', 'nekoray': 'Nekoray', 'nekobox': 'Nekobox', 'sing-box': 'Sing-box',
        'netmod': 'Netmod', 'anytls': 'AnyTLS', 'outline': 'Outline', 'ssconf': 'ShadowSocks Conf'
    };
    return map[p] || p.toUpperCase();
}

function detectAndSaveProxies(text) {
    if (!text) return null;
    const out = [];
    const seen = Object.create(null);
    const push = (v) => {
        v = String(v).trim().replace(/[.,;،؛]+$/, '');
        if (!v || seen[v]) return;
        seen[v] = 1;
        out.push(v);
    };
    let m;
    const schemes = new RegExp('(?:' + VX_PROXY_SCHEMES + '):\\/\\/[^\\s<>"\']+', 'gi');
    while ((m = schemes.exec(text)) !== null) push(m[0]);
    const tg = /(?:tg:\/\/proxy|https?:\/\/t\.me\/proxy)[^\s<>"']*/gi;
    while ((m = tg.exec(text)) !== null) push(m[0]);
    const httpProxy = /https?:\/\/[^\s\/@]+@[^\s\/]+:\d+[^\s<>"']*/gi;
    while ((m = httpProxy.exec(text)) !== null) push(m[0]);
    // Structured configs (WireGuard / sing-box) only make sense line by line.
    text.split('\n').forEach(line => {
        const s = line.trim();
        if (/^\[Interface\]/i.test(s) || /^\{[\s\S]*"outbounds"/i.test(s)) push(s);
    });
    return out.length ? out : null;
}

function saveProxiesAsTxt(rawText) {
    const text = rawText.replace(/&amp;/g,'&').replace(/&quot;/g,'"').replace(/&#39;/g,"'").replace(/&lt;/g,'<').replace(/&gt;/g,'>');
    const proxies = detectAndSaveProxies(text);
    if (!proxies || proxies.length === 0) return;
    const content = proxies.join('\n');
    const name = 'configs_' + Math.random().toString(36).substr(2, 8) + '.txt';
    const blob = new Blob([content], {type: 'text/plain'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(a.href);
    showToast('کانفیگ‌ها ذخیره شدند: ' + name);
}

function isEmojiOnly(str) {
    if (!str || !str.trim()) return false;
    const s = str.trim();
    // Must not contain ASCII letters, digits, punctuation, Persian/Arabic letters, Cyrillic, CJK, etc.
    if (/[a-zA-Z0-9\u0600-\u06FF\u0400-\u04FF\u4e00-\u9fff!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?\u200f\u200e\u060c\u061b\u061f]/.test(s)) return false;
    // After removing emoji chars, spaces and zero-width chars, nothing should remain
    const stripped = s.replace(/[\p{Emoji_Presentation}\p{Emoji}\uFE0F\u20E3\u200d\s\u200b\u200c\u200d\uFE0F\u{1F3FB}-\u{1F3FF}]/gmu, '');
    return stripped.length === 0 && s.length > 0;
}

function countEmoji(str) {
    const matches = str.match(/\p{Emoji_Presentation}|\p{Emoji}\uFE0F/gu);
    return matches ? matches.length : 0;
}

// Recognised endings for bare domains. Keeping a list (instead of linking any
// `word.word`) is what stops `index.php`, `v1.7.0` and `file.name` from turning
// into links.
const VX_LINK_TLDS = 'com|net|org|ir|io|dev|app|me|co|xyz|info|site|online|tech|store|club|live|link|top|pro|ai|tv|cc|ru|de|uk|fr|tr|ae|ca|au|jp|cn|in|br|nl|se|no|fi|dk|pl|it|es|ch|at|be|cz|gr|pt|ro|hu|bg|hr|sk|si|lt|lv|ee|by|kz|uz|az|ge|am|pk|af|iq|sa|kw|qa|bh|om|jo|lb|eg|ma|tn|dz|ly|sd|ye|ps';

function vxLinkify(t) {
    if (!t) return '';
    const re = new RegExp(
        '(https?:\\/\\/[^\\s<>"\'،؛]+' +
        '|www\\.[^\\s<>"\'،؛]+' +
        '|(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)+(?:' + VX_LINK_TLDS + ')(?::\\d+)?(?:\\/[^\\s<>"\'،؛]*)?)',
        'gi'
    );
    return t.replace(re, function (match, url, offset, whole) {
        // Do not link the domain part of an email, a path or a longer token.
        const before = offset > 0 ? whole.charAt(offset - 1) : '';
        if (before && /[A-Za-z0-9@._\/-]/.test(before)) return match;
        let tail = '';
        const punct = url.match(/[.,!?;:،؛»)\]}]+$/);
        if (punct) { tail = punct[0]; url = url.slice(0, -tail.length); }
        if (!url || /^(https?:\/\/|www\.)$/i.test(url)) return match;
        const href = /^https?:\/\//i.test(url) ? url : ('http://' + url);
        const safeUrl = sanitizeUrl(href);
        if (safeUrl === '#') return escapeHTMLDisplay(url) + tail;
        return '<a class="msg-link" href="' + escapeHTMLDisplay(safeUrl) + '" target="_blank" rel="noopener noreferrer" ' +
            'onclick="event.stopPropagation()">' + escapeHTMLDisplay(url) + '</a>' + tail;
    });
}

function formatTextForDisplay(t, isEmojiMsg) {
    if (!t) return '';
    if (isEmojiMsg) return t;
    return vxLinkify(stripXSS(t)).replace(/\n/g, '<br>');
}

function sanitizeUrl(url) {
    // block dangerous protocols
    var lower = url.trim().toLowerCase().replace(/\s/g,'').replace(/\0/g,'');
    if (/^(javascript|vbscript|data|svg|blob):/i.test(lower)) return '#';
    return url;
}

function stripXSS(text) {
    if (!text) return '';
    // Remove script tags, event handlers, svg with scripts, etc.
    return text
        .replace(/<script[\s\S]*?>[\s\S]*?<\/script>/gi, '')
        .replace(/<svg[\s\S]*?>/gi, function(m) {
            // remove svg tags entirely if they contain script or event handlers
            if (/on\w+\s*=|<script|href\s*=\s*['"]?javascript/i.test(m)) return '';
            return m;
        })
        .replace(/on\w+\s*=\s*(['"]?)[^'">\s]*/gi, '')
        .replace(/javascript\s*:/gi, '')
        .replace(/vbscript\s*:/gi, '')
        .replace(/<iframe[\s\S]*?>/gi, '')
        .replace(/<object[\s\S]*?>/gi, '')
        .replace(/<embed[\s\S]*?>/gi, '');
}

function formatFileSize(bytes) {
    if(!bytes || bytes <= 0) return '';
    if(bytes < 1024) return bytes + ' B';
    if(bytes < 1024*1024) return (bytes/1024).toFixed(1) + ' KB';
    if(bytes < 1024*1024*1024) return (bytes/(1024*1024)).toFixed(1) + ' MB';
    return (bytes/(1024*1024*1024)).toFixed(1) + ' GB';
}

// ── 3D icons ────────────────────────────────────────────────────────────────
// One place that turns a sprite symbol into markup, so the file box, the share
// button and the download button can never drift apart visually.
const VX3D_ICONS = {
    file:     '<use href="#v3i-file"/>',
    copy:     '<use href="#v3i-copy"/>',
    download: '<use href="#v3i-download"/>'
};
function vx3dIcon(name, cls) {
    const use = VX3D_ICONS[name] || VX3D_ICONS.file;
    return `<svg class="i3d ${cls || 'i3d-btn'}" viewBox="0 0 32 32" aria-hidden="true" focusable="false">${use}</svg>`;
}

function copyFileLink(token) {
    if(!token) return;
    const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '');
    const link = baseUrl + '/file.php?token=' + token + '&dl=1';
    if(navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(link).then(() => showToast('لینک دانلود کپی شد 🔗')).catch(() => _fallbackCopyText(link));
    } else {
        _fallbackCopyText(link);
    }
}

function _fallbackCopyText(text) {
    const el = document.createElement('textarea');
    el.value = text;
    el.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0;pointer-events:none;';
    document.body.appendChild(el);
    el.focus(); el.select();
    try { document.execCommand('copy'); showToast('لینک دانلود کپی شد 🔗'); } catch(e) { showToast('کپی ممکن نشد'); }
    document.body.removeChild(el);
}

function formatText(t) {
    if (!t) return '';
    t = stripXSS(t);
    let result = vxLinkify(t).replace(/\n/g, '<br>');

    const proxies = detectAndSaveProxies(t);
    if (proxies && proxies.length > 0) {
        // Only the detected config lines travel in data-text, so the download
        // can never contain the surrounding chat message.
        const payload = proxies.join('\n');
        const safeDataText = escapeHTMLDisplay(payload).replace(/\n/g, '&#10;');
        const types = [...new Set(proxies.map(l => getProxyType(l)))].slice(0, 3).join('، ');
        result += '<div class="proxy-box">' +
            '<span class="proxy-box-icon"><img src="assets/icons3d/lock.png" alt=""></span>' +
            '<div class="proxy-box-info">' +
                '<div class="proxy-box-label">کانفیگ پروکسی</div>' +
                '<div class="proxy-box-count"><b>' + proxies.length + '</b> کانفیگ · ' + escapeHTMLDisplay(types) + '</div>' +
            '</div>' +
            '<button type="button" class="proxy-box-btn" onclick="event.stopPropagation();saveProxiesAsTxt(this.getAttribute(\'data-text\'))" data-text="' + safeDataText + '">' +
                '<svg class="i3d i3d-btn" viewBox="0 0 32 32" aria-hidden="true"><use href="#v3i-download"/></svg>' +
                '<span>دریافت</span>' +
            '</button>' +
        '</div>';
    }
    return result;
}

function escapeHTMLDisplay(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

const _rxIcons = { '❤️': 'heart_red', '👍': 'thumbsup', '😂': 'joy', '😮': 'wow', '😢': 'cry' };
function _rxImg(emoji, cls) {
    const n = _rxIcons[emoji];
    if (!n) return '<span>' + emoji + '</span>';
    return '<img class="' + (cls || 'rx-3d') + '" src="assets/icons3d/' + n + '.png" alt="' + emoji + '">';
}

function _rxUsersText(users) {
    const list = (users || []).filter(function (u) {
        if (!u) return false;
        return String(u).trim().toLowerCase() !== 'admin';
    });
    return list.join('، ');
}

function _rxChip(msgId, emoji, count, users, mine) {
    const tip = _rxUsersText(users);
    const attrUsers = escapeHTMLDisplay((users || []).join('|'));
    return `<div class="reaction-item ${mine ? 'my-reaction' : ''}" data-emoji="${emoji}" data-count="${count}" data-users="${attrUsers}"
        onclick="event.stopPropagation(); reactToMessage(${msgId}, '${emoji}')"
        ${tip ? `onmouseenter="showReactionTooltip(event, '${tip.replace(/'/g, "\\'")}')" onmouseleave="hideReactionTooltip()"` : ''}>
        ${_rxImg(emoji)}
        <span class="reaction-count">${count}</span>
    </div>`;
}

function renderReactions(m) {
    if (!m.id) return '';
    
    const reactions = m.reactions || {};
    const myReaction = m.my_reaction || null;
    
    let reactionsHtml = '';
    
    if (Object.keys(reactions).length > 0) {
        reactionsHtml = '<div class="reaction-display">';
        for (const [emoji, data] of Object.entries(reactions)) {
            reactionsHtml += _rxChip(m.id, emoji, data.count, data.users || [], myReaction === emoji);
        }
        reactionsHtml += '</div>';
    }
    
    const pickerHtml = `<div class="reaction-container"><div class="reaction-btn" data-msg-id="${m.id}" onclick="event.stopPropagation(); toggleReactionPicker(${m.id}, this)"><img class="rx-3d rx-3d-btn" src="assets/icons3d/smile.png" alt=""></div></div>`;
    
    return reactionsHtml + pickerHtml;
}

// A pending bubble reserves the exact space the reaction row will occupy, so
// the row never grows when the server confirms the message. Without this the
// smiley popped in ~70 ms late and pushed the bubble up by its own height.
function renderReactionsGhost() {
    return '<div class="reaction-container rx-ghost" aria-hidden="true"><div class="reaction-btn"><img class="rx-3d rx-3d-btn" src="assets/icons3d/smile.png" alt=""></div></div>';
}

function renderMessage(m, isPending=false, isError=false) {
    if (m.message && m.message.startsWith('***') && m.message.endsWith('***')) {
        const text = m.message.replace(/\*\*\*/g, '').trim();
        return `<div class="msg system">${text}</div>`;
    }

    let sName = m.sender_name || '...';
    let sSticker = m.sender_sticker || '👤';
    const adminUser = '<?php echo $admin_username; ?>';
    const adminDisplay = '<?php echo $admin_display_name; ?>';
    
    if (sName === adminUser) {
        sName = adminDisplay + ' <svg style="width:12px;height:12px;vertical-align:middle;fill:#3b82f6" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>';
    }

    const isMe = m.sender_id == currentUser || isPending;
    
    let displaySenderName = isMe ? 'شما' : sName;

    const fileExt = m.file_name?.split('.').pop()?.toLowerCase();
    // Trust the stored type as well as the extension: upload.php labels the
    // row from the real MIME family, and the old extension list silently
    // dropped .mkv / .m4v / .3gp into the generic file box.
    const isImage = m.msg_type === 'image' || ['jpg','jpeg','png','gif','webp','bmp','svg','ico'].includes(fileExt);
    const isVideo = m.msg_type === 'video' || ['mp4','webm','mov','m4v','mkv','avi','3gp','wmv'].includes(fileExt);
    const isAudio = m.msg_type === 'voice' || m.msg_type === 'audio' || fileExt === 'mp3';
    const isMediaType = isImage || isVideo || isAudio;
    // The admin can remove the uploaded blob without removing the chat row, so
    // the thread keeps its shape. When that happened we render a placeholder
    // instead of pointing an <img>/<video>/<audio> at a file that is gone.
    const mediaGone = (m.media_deleted == 1);
    const goneType = String(m.media_prev_type || m.msg_type || '').toLowerCase();
    const goneMeta = goneType === 'video' ? { ico: 'play.png', cls: 'video', sub: 'ویدیو توسط مدیر حذف شد' }
        : goneType === 'image' ? { ico: 'image.png', cls: 'image', sub: 'تصویر توسط مدیر حذف شد' }
        : (goneType === 'voice' || goneType === 'audio') ? { ico: 'mic.png', cls: 'voice', sub: 'پیام صوتی توسط مدیر حذف شد' }
        : { ico: 'file.png', cls: 'file', sub: 'فایل توسط مدیر حذف شد' };

    let cnt = '';
    
    if(m.msg_type === 'ai') {
        cnt = `<div class="text-glass-box ai-bubble" style="border-right:3px solid var(--v-primary,#6366f1);"><span style="opacity:0.75;font-size:11px;display:block;margin-bottom:3px;">🤖 دستیار هوشمند</span>${formatText(m.message || '')}</div>`;
    } else if(m.msg_type === 'text') {
        const rawText = m.message || '';
        const emojiOnly = isEmojiOnly(rawText);
        const emojiCount = emojiOnly ? countEmoji(rawText) : 0;
        if (emojiOnly) {
            const displayCount = Math.min(emojiCount, 5);
            const displayText = emojiCount > 5 ? (function() {
                var matches = rawText.match(/\p{Emoji_Presentation}|\p{Emoji}\uFE0F/gu) || [];
                return matches.slice(0, 5).join('');
            })() : rawText;
            const fontSize = displayCount <= 1 ? '64px' : displayCount <= 3 ? '48px' : '36px';
            const padding = displayCount <= 1 ? '6px 4px' : '4px 2px';
            const topPad = m.reply_to_id ? '8px' : padding.split(' ')[0];
            cnt = `<div class="emoji-display" style="background:transparent;border:none;padding:${topPad} ${padding.split(' ')[1] || '4px'};box-shadow:none;display:block;max-width:100%;overflow:hidden;"><span class="emoji-display" style="font-size:${fontSize};line-height:1.25;display:block;letter-spacing:2px;white-space:nowrap;overflow:hidden;">${displayText}</span></div>`;
        } else {
            cnt = `<div class="text-glass-box">${formatText(rawText)}${m.is_edited==1 ? ' <small style="opacity:0.5">(ویرایش)</small>' : ''}</div>`;
        }
    } else if(mediaGone) {
        cnt = `<div class="media-gone media-gone--${goneMeta.cls}" role="note" aria-label="این پیام حذف شده">
            <span class="media-gone-ico"><img class="media-gone-img" src="assets/icons3d/${goneMeta.ico}" alt=""></span>
            <span class="media-gone-body">
                <span class="media-gone-title">این پیام حذف شده</span>
                <span class="media-gone-sub">${goneMeta.sub}</span>
            </span>
        </div>`;
        if(m.message) cnt += `<div class="text-glass-box chat-text">${formatText(m.message)}</div>`;
    } else if(m.msg_type === 'voice' || m.msg_type === 'audio') {
        const vSrc = isPending ? URL.createObjectURL(m.blob) : `voice.php?token=${m.file_token}`;
        const vid = 'vp' + (m.id || m.tempId || Math.random().toString(36).substr(2,8)) + '_' + Math.floor(Math.random()*100000);
        cnt = `<div class="vplayer" id="${vid}">
            <button class="vplay" data-vp-role="toggle" title="پخش / توقف" aria-label="پخش / توقف"><img class="vp-ico vp-play" src="assets/icons3d/play.png" alt=""><img class="vp-ico vp-pause" src="assets/icons3d/pause.png" alt=""></button>
            <div class="vbody">
                <div class="vbar" data-vp-role="bar"><div class="vbar-fill"></div><div class="vbar-knob"></div></div>
                <div class="vtime"><span class="vcur" data-vp-role="cur">0:00</span><span class="vdur" data-vp-role="dur">--:--</span></div>
            </div>
        </div>
        <audio preload="metadata" src="${vSrc}" style="display:none"></audio>`;
    } else if(isImage) {
        const src = isPending ? '' : `file.php?token=${m.file_token}`;
        const localSrc = (isPending && m.blob) ? URL.createObjectURL(m.blob) : '';
        cnt = `<div class="media-container chat-img-wrap" style="min-height:130px;" onclick="event.stopPropagation();${isPending ? '' : `openLightbox('${src}','img')`}">
            ${localSrc ? `<img src="${localSrc}" class="chat-media" style="opacity:.82;filter:blur(.4px);">` : ''}
            ${!isPending ? `<img src="${src}" class="chat-media" style="cursor:zoom-in;" loading="lazy" decoding="async" onload="_onChatImgLoad(this)" onerror="_onChatImgError(this)">` : ''}
            ${isPending
                ? `<div class="img-up-overlay"><span class="img-up-spinner"></span><span class="img-up-label">در حال ارسال تصویر…</span><span class="img-up-lock"><img src="assets/icons3d/lock.png" alt=""></span></div>`
                : `<div class="img-loading-state"><span class="vloading"></span><span>در حال بارگذاری تصویر…</span></div>`}
            <div class="img-error-state" onclick="event.stopPropagation();_retryChatImg(this)"><span>⚠ بارگذاری تصویر ناموفق بود</span><span class="retry-img">تلاش مجدد</span></div>
        </div>`;
        if(m.message) cnt += `<div class="text-glass-box chat-text">${formatText(m.message)}</div>`;
    } else if(isVideo) {
        const src = isPending ? '' : `file.php?token=${m.file_token}`;
        const cacheKey = m.file_token || ('id'+m.id);
        const canvasId = 'vthumb-' + (m.id || m.tempId || Math.random().toString(36).substr(2,6));
        if(videoThumbCache[cacheKey]) {
            cnt = `<div class="media-container video-wrap" onclick="event.stopPropagation();openLightbox('${src}','video')">
                <img src="${videoThumbCache[cacheKey]}" class="video-thumb">
                <div class="video-play-overlay"><img class="video-play-3d" src="assets/icons3d/play.png" alt="پخش" draggable="false"></div>
            </div>`;
        } else {
            cnt = `<div class="media-container video-wrap" onclick="event.stopPropagation();openLightbox('${src}','video')">
                <canvas id="${canvasId}" class="video-thumb" style="display:none;"></canvas>
                <div id="${canvasId}-ph" style="width:100%;height:160px;background:#0f172a;border-radius:12px;display:flex;align-items:center;justify-content:center;"><span style="opacity:0.3;font-size:40px;">🎬</span></div>
                <div class="video-play-overlay"><img class="video-play-3d" src="assets/icons3d/play.png" alt="پخش" draggable="false"></div>
            </div>`;
            if(src && cacheKey) {
                setTimeout(() => {
                    if(videoThumbCache[cacheKey]) {
                        const cv = document.getElementById(canvasId), ph = document.getElementById(canvasId+'-ph');
                        if(cv) { const img = document.createElement('img'); img.src = videoThumbCache[cacheKey]; img.className = 'video-thumb'; if(cv.parentNode) cv.parentNode.insertBefore(img, cv); cv.remove(); }
                        if(ph) ph.remove();
                        return;
                    }
                    const cv = document.getElementById(canvasId);
                    if(!cv) return;
                    const vid = document.createElement('video');
                    vid.preload = 'metadata'; vid.muted = true; vid.playsInline = true; vid.crossOrigin = 'anonymous'; vid.src = src;
                    const doCapture = () => {
                        try {
                            const ctx = cv.getContext('2d');
                            cv.width = vid.videoWidth||320; cv.height = vid.videoHeight||180;
                            ctx.drawImage(vid, 0, 0, cv.width, cv.height);
                            const dataUrl = cv.toDataURL('image/jpeg', 0.72);
                            videoThumbCache[cacheKey] = dataUrl;
                            const img = document.createElement('img'); img.src = dataUrl; img.className = 'video-thumb';
                            if(cv.parentNode) cv.parentNode.insertBefore(img, cv);
                            cv.remove();
                            const ph = document.getElementById(canvasId+'-ph');
                            if(ph) ph.remove();
                        } catch(e) {}
                    };
                    vid.addEventListener('loadeddata', () => { try { vid.currentTime = Math.min(0.5, (vid.duration||2)*0.05); } catch(e) { doCapture(); } }, {once:true});
                    vid.addEventListener('seeked', doCapture, {once:true});
                    vid.load();
                }, 250);
            }
        }
        if(m.message) cnt += `<div class="text-glass-box chat-text">${formatText(m.message)}</div>`;
    } else if(m.file_name?.split('.').pop()?.toLowerCase() === 'mp3') {
        const src = isPending ? '' : `file.php?token=${m.file_token}`;
        const dlLink = isPending ? '#' : `file.php?token=${m.file_token}&dl=1`;
        const songTitle = m.file_name ? m.file_name.replace(/\.mp3$/i,'').replace(/_/g,' ') : 'فایل صوتی';
        const sizeTag = m.file_size ? `<span class="file-size-tag">${formatFileSize(m.file_size)}</span>` : '';
        const shareBtn = (!isPending && m.file_token) ? `<button class="file-share-btn" onclick="event.stopPropagation();copyFileLink('${m.file_token}')" title="کپی لینک دانلود">${vx3dIcon('copy')}</button>` : '';
        cnt = `<div class="file-box" style="flex-direction:column;align-items:flex-start;gap:8px;">
            <div style="display:flex;align-items:center;gap:10px;width:100%;">
                <span class="file-icon t-audio">${vx3dIcon('file', 'i3d-file')}</span>
                <div class="file-info"><span class="file-name" style="color:var(--v-accent, #a5b4fc);">${escapeHTML(songTitle)}</span>${sizeTag}</div>
                ${shareBtn}
                ${!isPending ? `<a href="${dlLink}" class="download-btn" download onclick="event.stopPropagation()" title="دانلود">${vx3dIcon('download')}</a>` : ''}
            </div>
            ${src ? `<audio controls preload="none" src="${src}" style="width:100%;height:36px;border-radius:18px;" onclick="event.stopPropagation()"></audio>` : ''}
        </div>`;
        if(m.message) cnt += `<div class="text-glass-box chat-text">${formatText(m.message)}</div>`;
    } else {
        const dlLink = isPending ? '#' : `file.php?token=${m.file_token}&dl=1`;
        const ext = m.file_name?.split('.').pop()?.toLowerCase();
        const iconTint = ['pdf'].includes(ext) ? 't-pdf'
            : ['zip','rar','7z'].includes(ext) ? 't-arch'
            : ['doc','docx','txt','rtf'].includes(ext) ? 't-doc'
            : ['xls','xlsx','csv'].includes(ext) ? 't-sheet'
            : ['mp4','webm','mov','mkv'].includes(ext) ? 't-video'
            : ['jpg','jpeg','png','gif','webp'].includes(ext) ? 't-image'
            : 't-doc';
        const sizeTag = m.file_size ? `<span class="file-size-tag">${formatFileSize(m.file_size)}</span>` : '';
        const shareBtn = (!isPending && m.file_token) ? `<button class="file-share-btn" onclick="event.stopPropagation();copyFileLink('${m.file_token}')" title="کپی لینک دانلود">${vx3dIcon('copy')}</button>` : '';
        cnt = `<div class="file-box">
                <div class="file-icon ${iconTint}">${vx3dIcon('file', 'i3d-file')}</div>
                <div class="file-info"><span class="file-name">${escapeHTML(m.file_name)}</span>${sizeTag}</div>
                ${shareBtn}
                ${!isPending ? `<a href="${dlLink}" class="download-btn" download target="_blank" onclick="event.stopPropagation()" title="دانلود">${vx3dIcon('download')}</a>` : ''}
               </div>`;
        if(m.message) cnt += `<div class="text-glass-box chat-text">${formatText(m.message)}</div>`;
    }
    
    let rep = '';
    if(m.reply_to_id) {
        let repTxt = m.reply_message;
        if ((!repTxt || repTxt === '...') && m.reply_file_name) {
            repTxt = '[فایل پیوست]';
        } else if (!repTxt && !m.reply_file_name) {
            repTxt = '<span style="font-style:italic;opacity:0.6;">پیام حذف شده</span>';
        } else {
            // XSS sanitize reply text
            repTxt = escapeHTML(repTxt);
        }
        
        let rSender = m.reply_sender;
        if (!rSender || rSender === '...') {
            if (msgCache[m.reply_to_id]) {
                rSender = msgCache[m.reply_to_id].name;
            } else if (m.reply_sender_username) {
                rSender = m.reply_sender_username;
            } else {
                rSender = '...';
            }
        }
        
        if (rSender === adminUser) {
             rSender = adminDisplay;
        }
        
        rep = `<div class="reply-content" onclick="event.stopPropagation(); scrollToMsg(${m.reply_to_id})">
            <span class="reply-sender">${rSender}</span>
            <span class="reply-text">${repTxt}</span>
        </div>`;
    }

    const safeMsg = m.msg_type === 'text' ? (m.message || '').replace(/'/g, "&apos;").replace(/"/g, "&quot;").replace(/\n/g, "\\n") : '';
    const pendingClass = isPending ? 'pending' : '';
    const errorClass = isError ? 'error' : '';
    const retryBtn = isError ? `<div class="retry-btn" onclick="retrySend(${m.tempId})">تلاش مجدد ↻</div>` : '';
    const msgDataId = m.id || m.tempId;
    const safeSender = (m.sender_name || '').replace(/'/g, '').replace(/"/g, '').replace(/</g,'').replace(/>/g,'');
    const hasText = !!(m.msg_type === 'text' && (m.message || '').trim());

    if(m.id) {
        msgCache[m.id] = { name: m.sender_name || safeSender, text: m.msg_type === 'text' ? (m.message || '') : '', type: m.msg_type || 'text', file_name: m.file_name || null, isMe: isMe, reply_to_id: m.reply_to_id || null };
    }

    const isSeen = !isPending && isMe && m.seen_by && m.seen_by > 0;
    const tickColor = isPending ? '#94a3b8' : (isSeen ? '#60a5fa' : '#94a3b8');
    // The tick is drawn from the very first frame — grey while the message is
    // still in flight, blue once the server confirms it. It used to be injected
    // ~70 ms after the bubble appeared, which widened the time chip and made
    // the whole sender row nudge sideways right after you pressed Enter.
    const tickHtml = isMe ? `<span class="msg-tick" title="${isPending ? 'در حال ارسال' : (isSeen ? 'دیده شد' : 'ارسال شد')}" style="display:inline-flex;align-items:center;margin-right:3px;opacity:0.85;"><svg width="18" height="10" viewBox="0 0 18 10" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 5L4 8L10 2" stroke="${tickColor}" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 5L9 8L15 2" stroke="${tickColor}" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>` : '';

    const isReplyToMe = !isMe && m.reply_to_id && (
        (m.reply_sender_id && currentUser && String(m.reply_sender_id) === String(currentUser)) ||
        (m.reply_sender && currentUsername && (m.reply_sender === currentUsername || m.reply_sender === (currentUsername + ' '))) ||
        (msgCache[m.reply_to_id] && msgCache[m.reply_to_id].isMe === true)
    );

    const replyToMeBadge = isReplyToMe ? `<div class="reply-to-me-badge"><svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/></svg> 💬 پاسخی به پیام شما</div>` : '';

    // The action rail now lives *inside* the bubble and is absolutely
    // positioned, so it no longer contributes to the row height. It used to be
    // a flex sibling: four stacked 28px buttons forced every row to 124px, and
    // the instant the server answered, the row grew and pushed the message the
    // user had just sent below the fold.
    let actionsSlot = '';
    if (isPending) {
        actionsSlot = '<div class="msg-actions msg-actions-ghost" aria-hidden="true"></div>';
    } else {
        const replyBtn = `<button class="msg-act-btn" title="پاسخ" onclick="event.stopPropagation();setReplyFromMsg(${msgDataId})"><img src="assets/icons3d/reply.png" alt=""></button>`;
        const copyBtn = hasText ? `<button class="msg-act-btn" title="کپی" onclick="event.stopPropagation();copyMsgById(${msgDataId})"><img src="assets/icons3d/clipboard.png" alt=""></button>` : '';
        const editBtn = (isMe && !isMediaType) ? `<button class="msg-act-btn" title="ویرایش" onclick="event.stopPropagation();editMsgById(${msgDataId})"><img src="assets/icons3d/pencil.png" alt=""></button>` : '';
        const delBtn = (canDelete && (isMe || isAdmin)) ? `<button class="msg-act-btn del-btn" title="حذف" onclick="event.stopPropagation();delMsg(${msgDataId})"><img src="assets/icons3d/trash.png" alt=""></button>` : '';
        actionsSlot = `<div class="msg-actions" id="acts-${msgDataId}">${replyBtn}${copyBtn}${editBtn}${delBtn}</div>`;
    }

    const msgBubble = `<div class="msg ${isMe?'me':'other'} ${pendingClass} ${errorClass}${pinnedMessages && pinnedMessages.find(p => p.id == (m.id||m.tempId)) ? ' pinned-highlight' : ''}" id="msg-${m.id || m.tempId}" data-msg-id="${msgDataId}" data-is-me="${isMe?'1':'0'}" data-sender="${safeSender}" onclick="onMsgClick(event,this)" oncontextmenu="onMsgCtx(event,this)">
        ${replyToMeBadge}
        ${rep} 
        <div class="sender"><span class="msg-sticker">${sSticker}</span><span class="msg-sender-name">${displaySenderName}</span><span class="msg-time-chip">${m.created_at ? m.created_at.substr(11,5) : '...'}${tickHtml}</span></div> 
        ${cnt}
        ${!isError ? (isPending ? renderReactionsGhost() : renderReactions(m)) : ''}
        ${retryBtn}
        ${actionsSlot}
    </div>`;

    const repliedClass = isReplyToMe ? ' replied-to-me' : '';
    return `<div class="msg-row ${isMe ? 'me' : 'other'}${repliedClass}" id="row-${msgDataId}">${msgBubble}</div>`;
}


function openMyProfile() {
    closeMenu();
    const modal = document.getElementById('profileModal');
    modal.style.display = 'flex';
    const sticker = document.getElementById('profileSticker');
    const username = document.getElementById('profileUsername');
    const joinDate = document.getElementById('profileJoinDate');
    const secCode = document.getElementById('profileSecCode');
    const phoneEl = document.getElementById('profilePhone');
    const roomRow = document.getElementById('profileRoomRow');
    const roomEl = document.getElementById('profileRoom');

    const myUsername = '<?php echo htmlspecialchars($_SESSION["username"] ?? "", ENT_QUOTES); ?>';
    sticker.textContent = '<?php echo $_SESSION["sticker"] ?? "👤"; ?>';

    // username + copy button
    const usernameEl = document.getElementById('profileUsername');
    usernameEl.innerHTML = myUsername + ' <button onclick="copyProfileInfo()" title="کپی اطلاعات" style="background:var(--v-soft, rgba(99,102,241,0.15));border:1px solid var(--v-primary-border, rgba(99,102,241,0.3));color:var(--v-accent, #a5b4fc);border-radius:8px;padding:2px 8px;font-size:11px;cursor:pointer;margin-right:6px;">📋 کپی</button>';

    apiCall('chat.php', 'get_profile', {}, function(d) {
        if (d.status === 'success') {
            joinDate.textContent = 'عضویت: ' + (d.join_date || 'کاربر قدیمی');
            if (d.security_code) {
                secCode.textContent = d.security_code;
                secCode.classList.add('has-code');
                const cpBtn = document.getElementById('profileSecCopyBtn');
                if (cpBtn) cpBtn.style.display = '';
            } else {
                secCode.textContent = 'تنظیم نشده';
                secCode.classList.remove('has-code');
                const cpBtn = document.getElementById('profileSecCopyBtn');
                if (cpBtn) cpBtn.style.display = 'none';
            }
            const ph = d.phone_number;
            if (ph) {
                // show full phone number (read-only, no change button)
                phoneEl.innerHTML = '<span dir="ltr">' + ph + '</span>';
                window._profilePhone = ph;
                // باگ ۳: نمایش شماره در منو
                const menuPh = document.getElementById('menuPhoneDisplay');
                if (menuPh) menuPh.textContent = '(' + ph + ')';
            } else {
                phoneEl.innerHTML = '<span style="color:#64748b;">ثبت نشده</span>';
                window._profilePhone = '';
                const menuPh = document.getElementById('menuPhoneDisplay');
                if (menuPh) menuPh.textContent = '';
            }
            window._profileSecCode = d.security_code || '';
            window._profileUsername = myUsername;
            if (d.created_room) {
                roomRow.style.display = 'flex';
                roomEl.textContent = d.created_room;
            } else {
                roomRow.style.display = 'none';
            }
        }
    });
}

function copyProfileInfo() {
    const u = window._profileUsername || '';
    const sc = window._profileSecCode || '';
    const ph = window._profilePhone || '';
    const text = 'نام کاربری: ' + u + '\nکد امنیتی: ' + sc + (ph ? '\nشماره موبایل: ' + ph : '');
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => showToast('اطلاعات کپی شد ✔'));
    } else {
        const t = document.createElement('textarea');
        t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
        document.body.appendChild(t); t.select(); document.execCommand('copy');
        document.body.removeChild(t); showToast('اطلاعات کپی شد ✔');
    }
}

// Copy just the security code — the one thing people actually share with
// support. Falls back to the legacy execCommand path on http hosts where the
// clipboard API is unavailable.
function copySecCode() {
    const sc = (window._profileSecCode || '').trim();
    if (!sc) { showToast('کد امنیتی تنظیم نشده است', 'error'); return; }
    const done = () => showToast('کد امنیتی کپی شد ✔');
    if (navigator.clipboard && window.isSecureContext !== false) {
        navigator.clipboard.writeText(sc).then(done).catch(() => _copyFallback(sc, done));
    } else {
        _copyFallback(sc, done);
    }
}
function _copyFallback(text, done) {
    const t = document.createElement('textarea');
    t.value = text; t.style.position = 'fixed'; t.style.opacity = '0';
    document.body.appendChild(t); t.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(t);
    if (done) done();
}

function closeMyProfile() {
    document.getElementById('profileModal').style.display = 'none';
}

document.getElementById('profileModal').addEventListener('click', function(e) {
    if (e.target === this) closeMyProfile();
});

function openPhoneSettings() {
    closeMenu();
    closeMyProfile();
    const modal = document.getElementById('phoneModal');
    document.getElementById('phoneSaveMsg').style.display = 'none';
    document.getElementById('phoneInput').value = '';
    const passEl = document.getElementById('phoneAuthPassSave');
    if (passEl) passEl.value = '';

    const cachedPhone = window._profilePhone;
    const cachedHasPass = window._profileHasAuth;
    if (cachedPhone || cachedHasPass) {
        const numEl = document.getElementById('phoneModalRegisteredNum');
        const passInfoEl = document.getElementById('phoneModalRegisteredPass');
        if (numEl) numEl.textContent = cachedPhone || '';
        if (numEl) numEl.style.display = cachedPhone ? 'block' : 'none';
        if (passInfoEl) passInfoEl.style.display = cachedHasPass ? 'block' : 'none';
        document.getElementById('phoneModalRegistered').style.display = 'block';
        document.getElementById('phoneModalRegister').style.display = 'none';
        document.getElementById('phoneModalLoading').style.display = 'none';
        modal.style.display = 'flex';
        return;
    }
    document.getElementById('phoneModalRegistered').style.display = 'none';
    document.getElementById('phoneModalRegister').style.display = 'none';
    document.getElementById('phoneModalLoading').style.display = 'block';
    modal.style.display = 'flex';
    apiCall('chat.php', 'get_profile', {}, function(d) {
        document.getElementById('phoneModalLoading').style.display = 'none';
        if (d.status === 'success' && (d.phone_number || d.has_auth)) {
            window._profilePhone = d.phone_number || '';
            window._profileHasAuth = !!d.has_auth;
            const menuPh = document.getElementById('menuPhoneDisplay');
            if (menuPh) menuPh.textContent = d.phone_number ? '(' + d.phone_number + ')' : '';
            const numEl = document.getElementById('phoneModalRegisteredNum');
            const passInfoEl = document.getElementById('phoneModalRegisteredPass');
            if (numEl) { numEl.textContent = d.phone_number || ''; numEl.style.display = d.phone_number ? 'block' : 'none'; }
            if (passInfoEl) passInfoEl.style.display = d.has_auth ? 'block' : 'none';
            document.getElementById('phoneModalRegistered').style.display = 'block';
            document.getElementById('phoneModalRegister').style.display = 'none';
        } else {
            document.getElementById('phoneModalRegister').style.display = 'block';
        }
    }, function() {
        document.getElementById('phoneModalLoading').style.display = 'none';
        document.getElementById('phoneModalRegister').style.display = 'block';
    });
}

function closePhoneSettings() {
    document.getElementById('phoneModal').style.display = 'none';
}

document.getElementById('phoneModal').addEventListener('click', function(e) {
    if (e.target === this) closePhoneSettings();
});

function togglePassVisibility(inputId, btn) {
    const el = document.getElementById(inputId);
    if (!el) return;
    const show = el.type === 'password';
    el.type = show ? 'text' : 'password';
    const use = btn.querySelector('use');
    if (use) use.setAttribute('href', show ? '#v3i-eye-off' : '#v3i-eye');
    btn.setAttribute('aria-label', show ? 'پنهان کردن رمز' : 'نمایش رمز');
    // The button sits beside the field (not on top of it), so the pulse must
    // never translate it — that is what used to push it onto the input box.
    btn.classList.remove('eye-pulse');
    void btn.offsetWidth;
    btn.classList.add('eye-pulse');
    setTimeout(() => btn.classList.remove('eye-pulse'), 180);
}

function savePhoneNumber() {
    const phone    = document.getElementById('phoneInput').value.trim();
    const passEl   = document.getElementById('phoneAuthPassSave');
    const authPass = passEl ? passEl.value.trim() : '';
    const msg      = document.getElementById('phoneSaveMsg');

    if (!phone && !authPass) {
        msg.style.display = 'block';
        msg.style.background = 'rgba(239,68,68,0.1)';
        msg.style.border = '1px solid rgba(239,68,68,0.3)';
        msg.style.color = '#fca5a5';
        msg.textContent = 'شماره موبایل یا رمز احراز هویت را وارد کنید';
        return;
    }
    if (phone && !/^09[0-9]{9}$/.test(phone)) {
        msg.style.display = 'block';
        msg.style.background = 'rgba(239,68,68,0.1)';
        msg.style.border = '1px solid rgba(239,68,68,0.3)';
        msg.style.color = '#fca5a5';
        msg.textContent = 'شماره باید ۱۱ رقم و با ۰۹ شروع شود';
        return;
    }
    if (authPass) {
        if (authPass.length < 8 || authPass.length > 20) {
            msg.style.display = 'block';
            msg.style.background = 'rgba(239,68,68,0.1)';
            msg.style.border = '1px solid rgba(239,68,68,0.3)';
            msg.style.color = '#fca5a5';
            msg.textContent = 'رمز احراز هویت باید بین ۸ تا ۲۰ کاراکتر باشد';
            return;
        }
        if (/[\u0600-\u06FF]/.test(authPass)) {
            msg.style.display = 'block';
            msg.style.background = 'rgba(239,68,68,0.1)';
            msg.style.border = '1px solid rgba(239,68,68,0.3)';
            msg.style.color = '#fca5a5';
            msg.textContent = 'رمز احراز هویت نباید شامل حروف فارسی باشد';
            return;
        }
    }
    const payload = {};
    if (phone)    payload.phone_number  = phone;
    if (authPass) payload.auth_password = authPass;
    apiCall('requests.php', 'save_phone_number', payload, function(d) {
        msg.style.display = 'block';
        if (d.status === 'success') {
            msg.style.background = 'rgba(16,185,129,0.1)';
            msg.style.border = '1px solid rgba(16,185,129,0.3)';
            msg.style.color = '#6ee7b7';
            msg.textContent = '✔ ' + d.message;
            if (phone)    { window._profilePhone   = phone;   const m = document.getElementById('menuPhoneDisplay'); if(m) m.textContent = '('+phone+')'; }
            if (authPass) { window._profileHasAuth = true; }
            setTimeout(() => closePhoneSettings(), 1500);
        } else {
            msg.style.background = 'rgba(239,68,68,0.1)';
            msg.style.border = '1px solid rgba(239,68,68,0.3)';
            msg.style.color = '#fca5a5';
            msg.textContent = d.message;
        }
    });
}

function openChangePhoneModal() {
    closeMyProfile();
    const html = `<div style="background:linear-gradient(145deg,#0f172a,#1e293b);border:1px solid var(--v-primary-border, rgba(99,102,241,0.3));border-radius:24px;width:100%;max-width:380px;padding:24px;box-shadow:0 30px 80px rgba(0,0,0,0.8);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
            <span style="font-size:16px;font-weight:bold;color:#e2e8f0;">📱 تغییر شماره موبایل</span>
            <button onclick="document.getElementById('changePhoneWrap').style.display='none'" style="width:30px;height:30px;border-radius:8px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);color:#94a3b8;cursor:pointer;font-size:15px;">✕</button>
        </div>
        <p style="color:#94a3b8;font-size:13px;margin-bottom:14px;line-height:1.7;">برای تغییر شماره موبایل، کد امنیتی خود را وارد کنید.</p>
        <input type="text" id="changePhoneSecCode" maxlength="8" placeholder="کد امنیتی ۸ رقمی" dir="ltr" style="width:100%;padding:11px 14px;background:rgba(255,255,255,0.05);border:1px solid var(--v-primary-border, rgba(99,102,241,0.25));border-radius:12px;color:#e2e8f0;font-size:14px;margin-bottom:10px;box-sizing:border-box;font-family:monospace;letter-spacing:2px;" oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/g,'').substr(0,8)">
        <input type="tel" id="changePhoneNewNum" maxlength="11" placeholder="شماره جدید: 09xxxxxxxxx" dir="ltr" style="width:100%;padding:11px 14px;background:rgba(255,255,255,0.05);border:1px solid var(--v-primary-border, rgba(99,102,241,0.25));border-radius:12px;color:#e2e8f0;font-size:14px;margin-bottom:14px;box-sizing:border-box;" oninput="this.value=this.value.replace(/[^0-9]/g,'').substr(0,11)">
        <button onclick="submitChangePhone()" style="width:100%;padding:12px;background:linear-gradient(135deg,var(--v-primary-border, rgba(99,102,241,0.3)),var(--v-primary-strong, rgba(79,70,229,0.25)));border:1px solid rgba(99,102,241,0.5);border-radius:12px;color:var(--v-accent, #a5b4fc);font-size:14px;cursor:pointer;font-weight:bold;">ذخیره شماره جدید</button>
        <div id="changePhoneMsg" style="display:none;margin-top:10px;padding:10px 14px;border-radius:10px;font-size:13px;"></div>
    </div>`;
    let wrap = document.getElementById('changePhoneWrap');
    if (!wrap) {
        wrap = document.createElement('div');
        wrap.id = 'changePhoneWrap';
        wrap.style.cssText = 'position:fixed;inset:0;z-index:9900;background:rgba(0,0,0,0.85);backdrop-filter:blur(16px);display:flex;align-items:center;justify-content:center;padding:20px;';
        wrap.addEventListener('click', function(e) { if (e.target === this) this.style.display='none'; });
        document.body.appendChild(wrap);
    }
    wrap.innerHTML = html;
    wrap.style.display = 'flex';
}

function submitChangePhone() {
    const sc = document.getElementById('changePhoneSecCode').value.trim();
    const ph = document.getElementById('changePhoneNewNum').value.trim();
    const msg = document.getElementById('changePhoneMsg');
    if (!sc || sc.length < 8) { msg.style.display='block'; msg.style.color='#fca5a5'; msg.textContent='کد امنیتی نامعتبر است'; return; }
    if (!/^09[0-9]{9}$/.test(ph)) { msg.style.display='block'; msg.style.color='#fca5a5'; msg.textContent='شماره موبایل نامعتبر است'; return; }
    apiCall('requests.php', 'change_phone_number', { security_code: sc, phone_number: ph }, function(d) {
        msg.style.display = 'block';
        if (d.status === 'success') {
            msg.style.color = '#6ee7b7';
            msg.textContent = '✔ ' + d.message;
            setTimeout(() => { const w = document.getElementById('changePhoneWrap'); if(w) w.style.display='none'; }, 1500);
        } else {
            msg.style.color = '#fca5a5';
            msg.textContent = d.message;
        }
    });
}

let _updateNotifsShown = false;
function checkUpdateNotifications() {
    if (_updateNotifsShown) return;
    const fd = new FormData();
    fd.append('action', 'get_update_notifications');
    fd.append('csrf_token', csrfToken);
    fetch('requests.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'success' && d.notifications && d.notifications.length > 0) {
                _updateNotifsShown = true;
                showUpdateNotifBanner(d.notifications[0]);
            }
        }).catch(() => {});
}

function showUpdateNotifBanner(notif) {
    let banner = document.getElementById('updateNotifBanner');
    if (!banner) {
        banner = document.createElement('div');
        banner.id = 'updateNotifBanner';
        banner.style.cssText = 'position:fixed;top:70px;left:50%;transform:translateX(-50%) translateY(-120px);z-index:99990;background:linear-gradient(145deg,rgba(15,23,42,0.98),rgba(30,41,59,0.98));border:1px solid rgba(99,102,241,0.4);border-radius:18px;padding:14px 18px;max-width:380px;width:90%;box-shadow:0 20px 50px rgba(0,0,0,0.6);backdrop-filter:blur(16px);transition:transform 0.45s cubic-bezier(0.34,1.56,0.64,1);cursor:pointer;';
        document.body.appendChild(banner);
    }
    const typeColors = { info: '#6366f1', warning: '#f59e0b', success: '#10b981', update: '#8b5cf6' };
    const color = typeColors[notif.type] || '#6366f1';
    banner.innerHTML = `<div style="display:flex;align-items:flex-start;gap:12px;">
        <div style="font-size:28px;flex-shrink:0;">${notif.icon || '🔔'}</div>
        <div style="flex:1;min-width:0;">
            <div style="font-weight:bold;color:#e2e8f0;font-size:14px;margin-bottom:4px;">${escapeHTML(notif.title)}</div>
            <div style="font-size:12px;color:#94a3b8;line-height:1.6;">${escapeHTML(notif.message)}</div>
        </div>
        <button onclick="dismissUpdateNotif(${notif.id})" style="width:26px;height:26px;border-radius:8px;background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.1);color:#64748b;font-size:13px;cursor:pointer;flex-shrink:0;">✕</button>
    </div>
    <div style="height:3px;background:${color};border-radius:2px;margin-top:10px;animation:notifProg 6s linear forwards;" id="notifProgBar"></div>`;
    banner.style.borderColor = color.replace('1)', '0.4)').replace('#', 'rgba(').concat(',0.4)') || 'rgba(99,102,241,0.4)';
    setTimeout(() => { banner.style.transform = 'translateX(-50%) translateY(0)'; }, 100);
    setTimeout(() => { if (document.getElementById('updateNotifBanner')) dismissUpdateNotif(notif.id); }, 7000);
}

function dismissUpdateNotif(nid) {
    const banner = document.getElementById('updateNotifBanner');
    if (banner) {
        banner.style.transform = 'translateX(-50%) translateY(-120px)';
        setTimeout(() => { if (banner.parentNode) banner.parentNode.removeChild(banner); }, 500);
    }
    const fd = new FormData();
    fd.append('action', 'mark_update_notification_seen');
    fd.append('csrf_token', csrfToken);
    fd.append('notif_id', nid);
    fetch('requests.php', { method: 'POST', body: fd }).catch(() => {});
}

function switchPhoneAuthTab(tab) {
    const isPhone = tab === 'phone';
    document.getElementById('phoneAuthPanelPhone').style.display = isPhone ? 'block' : 'none';
    document.getElementById('phoneAuthPanelPass').style.display = isPhone ? 'none' : 'block';
    // The sliding pill is driven by a single data attribute; the buttons only
    // carry the active state. No inline colours to fight the theme.
    const tabsEl = document.getElementById('phoneAuthTabs');
    if (tabsEl) tabsEl.dataset.tab = isPhone ? 'phone' : 'pass';
    const tp = document.getElementById('phoneAuthTabPhone');
    const tq = document.getElementById('phoneAuthTabPass');
    if (tp) tp.classList.toggle('is-active', isPhone);
    if (tq) tq.classList.toggle('is-active', !isPhone);
    document.getElementById('phoneAuthAttempts').textContent = '';
    if (isPhone) {
        document.getElementById('phoneAuthInput').focus();
    } else {
        document.getElementById('phoneAuthPassInput').focus();
    }
}

// Swaps the 3D mark between the lock and the phone artwork without touching
// the two <img> nodes (setting textContent here used to wipe them).
function _setPhoneAuthIcon(kind) {
    const el = document.getElementById('phoneAuthIcon');
    if (el) el.dataset.kind = (kind === 'phone') ? 'phone' : 'lock';
}

function _showPhoneAuthModal(hasPhone, isNewUser) {
    const modal = document.getElementById('phoneAuthModal');
    if (modal.style.display === 'flex') return;
    _phoneAuthModalOpen = true;
    modal.style.display = 'flex';
    document.getElementById('phoneAuthInput').value = '';
    document.getElementById('phoneAuthPassInput').value = '';
    document.getElementById('phoneAuthLockBox').style.display = 'none';
    document.getElementById('phoneAuthAttempts').textContent = '';
    document.getElementById('phoneAuthNewUser').style.display = isNewUser && !hasPhone ? 'flex' : 'none';
    const tabs = document.getElementById('phoneAuthTabs');
    if (isNewUser && !hasPhone) {
        document.getElementById('phoneAuthTitle').textContent = 'ثبت اطلاعات احراز هویت';
        document.getElementById('phoneAuthDesc').textContent = 'شماره موبایل یا رمز احراز هویت خود را وارد کنید. در ورودهای بعدی از همین اطلاعات استفاده می‌شود.';
        _setPhoneAuthIcon('phone');
        document.getElementById('phoneAuthBtn').textContent = 'ثبت و ادامه';
        tabs.style.display = 'grid';
    } else {
        document.getElementById('phoneAuthTitle').textContent = 'احراز هویت';
        document.getElementById('phoneAuthDesc').textContent = 'شماره موبایل یا رمز احراز هویت خود را وارد کنید.';
        _setPhoneAuthIcon('lock');
        document.getElementById('phoneAuthBtn').textContent = 'تأیید و ورود';
        tabs.style.display = 'grid';
    }
    switchPhoneAuthTab('phone');
}

function skipPhoneAuth() {
    const fd = new FormData();
    fd.append('action', 'phone_auth_skip');
    fd.append('csrf_token', csrfToken);
    if (bwNonce) fd.append('bw_nonce', bwNonce);
    fetch('chat.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .catch(() => ({ status: 'error' }))
        .then(() => {
            const modal = document.getElementById('phoneAuthModal');
            if (modal) modal.style.display = 'none';
            _phoneAuthModalOpen = false;
            showToast('می‌توانید بعداً از منو، احراز هویت را تنظیم کنید');
        });
}

function submitPhoneAuth() {
    const btn = document.getElementById('phoneAuthBtn');
    const attemptsEl = document.getElementById('phoneAuthAttempts');
    const isPassTab = document.getElementById('phoneAuthPanelPass').style.display !== 'none';

    let payload = {};
    if (isPassTab) {
        const pass = document.getElementById('phoneAuthPassInput').value;
        if (!pass || pass.length < 8 || pass.length > 20) {
            attemptsEl.textContent = 'رمز باید بین ۸ تا ۲۰ کاراکتر باشد';
            attemptsEl.style.color = '#f87171';
            return;
        }
        if (/[\u0600-\u06FF]/.test(pass)) {
            attemptsEl.textContent = 'رمز نباید شامل حروف فارسی باشد';
            attemptsEl.style.color = '#f87171';
            return;
        }
        payload = { auth_password: pass };
    } else {
        const phone = document.getElementById('phoneAuthInput').value.trim();
        if (!phone || !/^09[0-9]{9}$/.test(phone)) {
            attemptsEl.textContent = 'شماره باید ۱۱ رقم و با ۰۹ شروع شود';
            attemptsEl.style.color = '#f87171';
            return;
        }
        payload = { phone };
    }

    btn.disabled = true;
    btn.textContent = 'در حال بررسی...';
    attemptsEl.textContent = '';

    const fd = new FormData();
    fd.append('action', 'phone_auth_verify');
    fd.append('csrf_token', csrfToken);
    if (bwNonce) fd.append('bw_nonce', bwNonce);
    for (let k in payload) fd.append(k, payload[k]);

    fetch('chat.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            btn.disabled = false;
            if (d.status === 'success') {
                document.getElementById('phoneAuthModal').style.display = 'none';
                _phoneAuthModalOpen = false;
                showToast('✔ احراز هویت موفق');
            } else if (d.status === 'locked') {
                document.getElementById('phoneAuthLockBox').style.display = 'flex';
                document.getElementById('phoneAuthLockMsg').textContent = d.message;
                document.getElementById('phoneAuthInput').disabled = true;
                document.getElementById('phoneAuthPassInput').disabled = true;
                btn.disabled = true;
                btn.textContent = 'قفل شده';
            } else {
                attemptsEl.textContent = d.message || 'اطلاعات اشتباه است';
                attemptsEl.style.color = '#f87171';
                if (isPassTab) {
                    document.getElementById('phoneAuthPassInput').value = '';
                    document.getElementById('phoneAuthPassInput').focus();
                } else {
                    document.getElementById('phoneAuthInput').value = '';
                    document.getElementById('phoneAuthInput').focus();
                }
                btn.textContent = 'تأیید و ورود';
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.textContent = 'تأیید و ورود';
            attemptsEl.textContent = 'خطا در ارتباط با سرور';
            attemptsEl.style.color = '#f87171';
        });
}

function checkPhoneAuthRequired() {
    apiCall('chat.php', 'phone_auth_check', {}, function(d) {
        if (d.status !== 'success') return;
        const isVerified = !d.needs_verify;
        const hasPhone = d.has_phone;
        const hasAuth = d.has_auth;
        const isLocked = d.is_locked;
        if (isLocked) {
            const modal = document.getElementById('phoneAuthModal');
            modal.style.display = 'flex';
            _phoneAuthModalOpen = true;
            document.getElementById('phoneAuthLockBox').style.display = 'flex';
            document.getElementById('phoneAuthLockMsg').textContent = 'حساب به مدت ' + d.lock_remaining + ' دقیقه قفل است. صبر کنید.';
            document.getElementById('phoneAuthInput').disabled = true;
            document.getElementById('phoneAuthPassInput').disabled = true;
            document.getElementById('phoneAuthBtn').disabled = true;
            return;
        }
        if (!isVerified) {
            _showPhoneAuthModal(hasPhone, !hasAuth);
        }
    });
}

function checkNeedsPhone() {
    localStorage.removeItem('bw_needs_phone');
}

let _phoneLoginUsername = '';

function openPhoneLoginModal(username, otpCode, phoneMasked) {
    _phoneLoginUsername = username;
    document.getElementById('phoneLoginModal').style.display = 'flex';
    const codeBox = document.getElementById('phoneOtpCodeBox');
    if (codeBox) codeBox.style.display = otpCode ? 'block' : 'none';
    document.getElementById('phoneOtpDisplay').textContent = otpCode || '';
    document.getElementById('phoneLoginDesc').textContent = otpCode
        ? 'کد تأیید زیر را وارد کنید (ارسال به: ' + phoneMasked + ')'
        : 'کد ۵ رقمی ارسال‌شده به ' + phoneMasked + ' را وارد کنید';
    document.getElementById('phoneOtpInput').value = '';
    document.getElementById('phoneOtpError').style.display = 'none';
}

function closePhoneLoginModal() {
    document.getElementById('phoneLoginModal').style.display = 'none';
}

function submitPhoneOtp() {
    const otp = document.getElementById('phoneOtpInput').value.trim();
    const errEl = document.getElementById('phoneOtpError');
    if (otp.length !== 5) {
        errEl.style.display = 'block';
        errEl.textContent = 'کد باید ۵ رقم باشد';
        return;
    }
    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('meta[name=csrf]') ? document.querySelector('meta[name=csrf]').content : '<?php echo $_SESSION["csrf_token"] ?? ""; ?>');
    fd.append('action', 'login_phone_verify');
    fd.append('username', _phoneLoginUsername);
    fd.append('otp', otp);
    document.getElementById('loadingOverlay').style.display = 'flex';
    fetch('requests.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => {
        document.getElementById('loadingOverlay').style.display = 'none';
        if (d.status === 'success') {
            closePhoneLoginModal();
            localStorage.removeItem('welcomeShown');
            setTimeout(() => location.reload(), 300);
        } else {
            errEl.style.display = 'block';
            errEl.textContent = d.message;
        }
    }).catch(() => {
        document.getElementById('loadingOverlay').style.display = 'none';
        errEl.style.display = 'block';
        errEl.textContent = 'خطا در ارتباط';
    });
}


function deleteMyRoom(roomId, roomName) {
    showConfirm('آیا مطمئنید؟ اتاق "' + roomName + '" و تمام پیام‌های آن حذف خواهد شد.', function() {
        apiCall('chat.php', 'delete_my_room', { room_id: roomId }, function(d) {
            if (d.status === 'success') {
                showToast('اتاق حذف شد');
                if (currentRoom === roomName) {
                    currentRoom = 'گفتگوی عمومی';
                    currentRoomId = null;
                    targetUser = null;
                    document.getElementById('msgsContent').innerHTML = '';
                    loadedHistoryHtml = '';
                }
                fetchMessages();
            } else {
                showToast(d.message || 'خطا در حذف اتاق');
            }
        });
    });
}

function scrollToMsg(id) {
    var box = document.getElementById('messagesBox');
    var el = document.getElementById('msg-' + id);
    if(el) {
        _doScrollToEl(el, id);
        return;
    }
    if(_historyComplete) {
        showToast('پیام مرجع یافت نشد');
        return;
    }
    showToast('در حال جستجوی پیام...');
    _loadHistoryUntilMsg(id, 0);
}

function _doScrollToEl(el, id) {
    var box = document.getElementById('messagesBox');
    userScrolledUp = true;
    _pendingScrollToMsgId = id;

    var boxRect = box.getBoundingClientRect();
    var elRect = el.getBoundingClientRect();
    var targetScrollTop = box.scrollTop + (elRect.top - boxRect.top) - (box.clientHeight / 2) + (elRect.height / 2);
    var clampedTarget = Math.max(0, Math.min(box.scrollHeight - box.clientHeight, targetScrollTop));

    box.style.scrollBehavior = 'smooth';
    box.scrollTo({ top: clampedTarget, behavior: 'smooth' });

    var scrollDuration = 600;
    _programScrollUntil = Date.now() + scrollDuration + 300;

    setTimeout(function() {
        el.classList.remove('highlight-target');
        void el.offsetWidth;
        el.classList.add('highlight-target');
        _programScrollUntil = Date.now() + 3500;
        setTimeout(function() {
            el.classList.remove('highlight-target');
            _pendingScrollToMsgId = null;
            _programScrollUntil = Date.now() + 500;
        }, 2500);
    }, scrollDuration);
}

function _loadHistoryUntilMsg(targetId, attempt) {
    if(attempt > 10) { showToast('پیام مرجع یافت نشد'); return; }
    if(!oldestMsgId || isLoadingMore || _historyComplete) {
        showToast('پیام مرجع یافت نشد');
        return;
    }
    isLoadingMore = true;
    var fd2 = new FormData();
    fd2.append('action', 'fetch_history');
    fd2.append('csrf_token', csrfToken);
    if(bwNonce) fd2.append('bw_nonce', bwNonce);
    fd2.append('room', currentRoom || 'null');
    fd2.append('target_user', targetUser || 0);
    fd2.append('before_id', oldestMsgId);

    fetch('chat.php', { method: 'POST', body: fd2 })
    .then(function(r) { if(!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
    .then(function(text) {
        isLoadingMore = false;
        if(!text || !text.trim()) { showToast('خطا در بارگذاری'); return; }
        var d;
        try { d = JSON.parse(text); } catch(e) { showToast('خطا در بارگذاری'); return; }
        if(!d.messages || d.messages.length === 0) { _historyComplete = true; showToast('پیام مرجع یافت نشد'); return; }

        var html = '';
        d.messages.forEach(function(m) {
            msgCache[m.id] = { name: m.sender_name, text: m.msg_type === 'text' ? (m.message || '') : '', type: m.msg_type || 'text', file_name: m.file_name || null, isMe: (m.sender_id == currentUser), reply_to_id: m.reply_to_id || null };
            html += renderMessage(m);
        });

        var box = document.getElementById('messagesBox');
        var contentBox = document.getElementById('msgsContent');
        var oldScrollTop = box.scrollTop;
        var oldScrollHeight = box.scrollHeight;

        box.style.scrollBehavior = 'auto';
        loadedHistoryHtml = html + loadedHistoryHtml;
        _lastRenderedKey = '';
        contentBox.insertAdjacentHTML('afterbegin', html);
        oldestMsgId = d.messages[0].id;

        void box.offsetHeight;
        var heightDelta = box.scrollHeight - oldScrollHeight;
        _programScrollUntil = Date.now() + 800;
        box.scrollTop = oldScrollTop + (heightDelta > 0 ? heightDelta : 0);

        if(d.messages.length < 20) _historyComplete = true;

        var el = document.getElementById('msg-' + targetId);
        if(el) {
            setTimeout(function() { _doScrollToEl(el, targetId); }, 200);
        } else if(!_historyComplete) {
            setTimeout(function() { _loadHistoryUntilMsg(targetId, attempt + 1); }, 100);
        } else {
            showToast('پیام مرجع یافت نشد');
        }
    })
    .catch(function() { isLoadingMore = false; showToast('خطا در بارگذاری'); });
}

// The welcome sheet is per-account, not per-browser: the flag lives on the
// users row so a second device (or a cleared localStorage) never sees it again.
function dismissWelcome() {
    closeModal('welcomeModal');
    if (!VX_WELCOME_SEEN) {
        VX_WELCOME_SEEN = 1;
        try { apiCall('chat.php', 'welcome_seen', {}, function () {}); } catch (e) {}
    }
    checkAndShowSecurityCode();
}

function checkAndShowSecurityCode() {
    const sc = localStorage.getItem('newSecurityCode');
    if(sc) {
        document.getElementById('newSecurityCodeDisplay').textContent = sc;
        openModal('securityCodeModal');
        localStorage.removeItem('newSecurityCode');
        setTimeout(() => { checkPhoneAuthRequired(); checkUpdateNotifications(); }, 1200);
    } else {
        setTimeout(() => { checkPhoneAuthRequired(); checkUpdateNotifications(); }, 800);
    }
}

function updateScrollBadge() {
    const badge = document.getElementById('scrollUnreadBadge');
    if (newMessagesSinceScroll > 0) {
        badge.style.display = 'flex';
        badge.textContent = vxBadge(newMessagesSinceScroll);
    } else {
        badge.style.display = 'none';
    }
}

let _fetchRetryCount = 0;
let _fetchInFlight = false;
// A refresh requested while one is already running used to be thrown away — and
// that request is usually the only signal that a new message exists. The result
// was a message sitting invisible until the *next* poll (15 s with a live
// socket). Now the request is remembered and replayed the moment the current
// one finishes, so nothing is ever dropped.
let _fetchDirty = false;
let _fetchWaiters = [];
let _fetchStartedAt = 0;
let _fetchAbort = null;
const _FETCH_TIMEOUT_MS = 8000;

function _fetchFinish() {
    _fetchInFlight = false;
    _fetchAbort = null;
    // Capture the replay flag *before* running the waiters: a waiter may start
    // a fresh fetch of its own, and we must not then clear a dirty flag that
    // belongs to the request after that one.
    const needReplay = _fetchDirty;
    _fetchDirty = false;
    const waiters = _fetchWaiters.splice(0);
    waiters.forEach(function (cb) { try { if (typeof cb === 'function') cb(); } catch (e) {} });
    if (!needReplay) return;
    if (_fetchInFlight) { _fetchDirty = true; return; }   // a waiter already refetched
    setTimeout(function () {
        if (_fetchInFlight || document.hidden) { _fetchDirty = true; return; }
        fetchMessages(null, { incremental: true });
    }, 0);
}

// ── Client-side request governor ─────────────────────────────────────────────
// The server has its own token bucket (api_guard.php) and answers 429 with a
// Retry-After value. This layer makes sure we rarely get there in the first
// place and — when we do — that the UI backs off quietly instead of stacking
// retries on top of an already busy server.
var _net = {
    backoffUntil: 0,
    errors: 0,
    lastRateToast: 0,
    lastIncomingAt: Date.now(),
    pollCount: 0
};

function _netBackoff(seconds, quiet) {
    _net.errors++;
    var ms = Math.max(1500, Math.min(45000, (seconds || 2) * 1000));
    _net.backoffUntil = Date.now() + ms;
    if (!quiet && Date.now() - _net.lastRateToast > 60000) {
        _net.lastRateToast = Date.now();
        try { showToast('برای جلوگیری از محدودیت، همگام‌سازی چند لحظه کند شد'); } catch (e) {}
    }
}

function _netOk() {
    _net.errors = 0;
    _net.backoffUntil = 0;
}

function _netBlocked() {
    return Date.now() < _net.backoffUntil;
}

function fetchMessages(callback, opts) {
    opts = opts || {};
    if (_netBlocked()) { if (typeof callback === 'function') callback(); return; }
    var _inc = !!opts.incremental && lastMsgId > 0;
    if (_fetchInFlight) {
        _fetchDirty = true;
        if (typeof callback === 'function') _fetchWaiters.push(callback);
        return;
    }
    _fetchInFlight = true;
    _fetchStartedAt = Date.now();
    const capturedToken = _fetchToken;

    var fd = new FormData();
    fd.append('action', 'fetch');
    fd.append('csrf_token', csrfToken);
    if (bwNonce) fd.append('bw_nonce', bwNonce);
    fd.append('room', currentRoom != null ? currentRoom : 'null');
    fd.append('target_user', targetUser != null ? targetUser : '');
    if (_inc) {
        fd.append('after_id', String(lastMsgId));
        // One in six polls also refreshes the room list, system settings and
        // the pinned strip; the other five only ask for what is new.
        if ((_net.pollCount++ % 6) !== 0) fd.append('light', '1');
    }

    // A request that never answers used to freeze the whole chat: _fetchInFlight
    // stayed true and every later refresh was dropped. Bounded now.
    const _fCtrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    _fetchAbort = _fCtrl;
    const _fGuard = _fCtrl ? setTimeout(function () { try { _fCtrl.abort(); } catch (e) {} }, _FETCH_TIMEOUT_MS) : null;

    var _tPoll = (window.performance && performance.now) ? performance.now() : Date.now();
    fetch('chat.php', { method: 'POST', body: fd, cache: 'no-store', signal: _fCtrl ? _fCtrl.signal : undefined })
    .then(function(r) {
        if (r.status === 429) {
            return r.text().then(function(body) {
                var wait = 3;
                try { var j = JSON.parse(body); if (j && j.retry_after) wait = j.retry_after; } catch (e) {}
                _netBackoff(wait);
                return null;
            });
        }
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
    })
    .then(function(text) {
        if (_fGuard) clearTimeout(_fGuard);
        _fetchFinish();
        _drainPendingWake();
        _notePollLatency(((window.performance && performance.now) ? performance.now() : Date.now()) - _tPoll);
        if (text === null) { if (typeof callback === 'function') callback(); return; }
        // The view changed under us (room switch). The answer is stale, but the
        // new view still needs a refresh — queue one instead of going quiet.
        if (capturedToken !== _fetchToken) { _fetchDirty = true; if (typeof callback === 'function') callback(); return; }
        if (!text || !text.trim()) { _fetchRetryCount++; if (typeof callback === 'function') callback(); return; }
        if (text.trim().startsWith('<') || text.includes('دسترسی شما به دلیل نقض قوانین')) {
            handleBan(null, 'perm');
            if (typeof callback === 'function') callback();
            return;
        }
        var d;
        try { d = JSON.parse(text); } catch(e) {
            _fetchRetryCount++;
            showToast('خطا در دریافت پیام‌ها - تلاش مجدد...');
            if (_fetchRetryCount <= 3) setTimeout(function() { fetchMessages(callback); }, 1500);
            else { _fetchRetryCount = 0; if (typeof callback === 'function') callback(); }
            return;
        }
        _fetchRetryCount = 0;
        _netOk();

        if (d.status === 'banned') { handleBan(d.message, d.ban_type || 'temp'); if (typeof callback === 'function') callback(); return; }
        if (d.status === 'force_reload' || d.reason === 'banned') { handleBan(d.message || null, 'temp'); return; }
        if (d.status === 'session_burned') { handleSessionBurned(); return; }
        if (d.status === 'error' && d.message === 'سایت موقتاً در دسترس نیست') { handleSessionBurned(); return; }
        if (d.status === 'auth_fail' || (d.status === 'error' && d.message === 'کاربر یافت نشد')) { window.location.href = 'index.php'; return; }
        if (d.status === 'access_denied') {
            try { localStorage.removeItem('bw_last_target'); localStorage.removeItem('bw_last_target_name'); localStorage.removeItem('bw_last_room'); localStorage.removeItem('bw_last_room_id'); } catch(e){}
            openModal('accessDeniedModal');
            if (typeof callback === 'function') callback();
            return;
        }

        _processFetchData(d, capturedToken, callback, _inc);
    })
    .catch(function(err) {
        if (_fGuard) clearTimeout(_fGuard);
        _fetchFinish();
        _drainPendingWake();
        if (capturedToken !== _fetchToken) { _fetchDirty = true; if (typeof callback === 'function') callback(); return; }
        _fetchRetryCount++;
        // An aborted request is our own 8 s guard, not a dead network: retry
        // quickly and quietly instead of scaring the user with a toast.
        var wasAbort = !!(err && err.name === 'AbortError');
        if (!wasAbort) {
            var errMsg = _fetchRetryCount <= 2 ? 'خطای شبکه - تلاش مجدد...' : 'اتصال برقرار نشد - بررسی شبکه';
            showToast(errMsg);
        }
        if (wasAbort || _fetchRetryCount <= 1) {
            setTimeout(function () { fetchMessages(callback); }, wasAbort ? 250 : 1500);
        } else {
            _fetchRetryCount = 0;
            if (typeof callback === 'function') callback();
        }
    });
}

// Belt and braces: if a request somehow escapes both the guard and the abort,
// release the lock so the chat can never freeze permanently.
setInterval(function () {
    if (!_fetchInFlight) return;
    if (Date.now() - _fetchStartedAt < (_FETCH_TIMEOUT_MS + 4000)) return;
    try { if (_fetchAbort) _fetchAbort.abort(); } catch (e) {}
    _fetchInFlight = false;
    _fetchAbort = null;
}, 2000);

function _catToggle(key) {
    const body = document.getElementById('cat-body-' + key);
    const arrow = document.getElementById('cat-arrow-' + key);
    if (!body) return;
    const willCollapse = !body.classList.contains('collapsed');
    body.style.maxHeight = body.scrollHeight + 'px';
    void body.offsetHeight;
    if (willCollapse) {
        body.classList.add('collapsed');
        body.style.maxHeight = '0px';
    } else {
        body.classList.remove('collapsed');
        body.style.maxHeight = body.scrollHeight + 'px';
        setTimeout(function () {
            if (!body.classList.contains('collapsed')) body.style.maxHeight = 'none';
        }, 360);
    }
    if (arrow) arrow.classList.toggle('collapsed', willCollapse);
    try { localStorage.setItem('cat_' + key, willCollapse ? '1' : '0'); } catch(e){}
}
function _catCollapsed(key) {
    try { return localStorage.getItem('cat_' + key) === '1'; } catch(e){ return false; }
}
function _makeCategory(key, title, bodyHtml, iconName) {
    const collapsed = _catCollapsed(key);
    const iconHtml = iconName ? `<img class="i3d i3d-sm" src="assets/icons3d/${iconName}.png" alt="">` : '';
    return `<div class="sidebar-category" onclick="_catToggle('${key}')">
        <span class="sidebar-category-title">${iconHtml}${title}</span>
        <span class="sidebar-category-arrow ${collapsed ? 'collapsed' : ''}" id="cat-arrow-${key}"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg></span>
    </div>
    <div class="sidebar-category-body ${collapsed ? 'collapsed' : ''}" id="cat-body-${key}" style="max-height:${collapsed ? '0px' : 'none'}">
        ${bodyHtml}
    </div>`;
}

function _veyraxSetAiBanner(active, mode) {
    const existing = document.getElementById('veyraxAiBanner');
    if (active) {
        // The copy follows where the assistant is active: a group room, a
        // direct chat with the assistant, or a private thread it can be
        // summoned into. Repaint when the mode changes (switching chats).
        const m = mode || 'room';
        if (existing && existing.dataset.mode === m) { _veyraxSyncBannerGap(); return; }
        if (existing) existing.remove();
        const b = document.createElement('div');
        b.id = 'veyraxAiBanner';
        b.className = 'vx-ai-banner';
        b.dataset.mode = m;
        let tx = '<b>دستیار هوش مصنوعی این گفتگو فعال است</b>'
            + '<small>بگو «' + VX_AI_KEYWORD + '» یا روی پیام دستیار ریپلای بزن تا پاسخ بگیره</small>';
        if (m === 'pv_direct') {
            tx = '<b>دستیار هوش مصنوعی فعال است</b><small>هر پیامی بفرستی، دستیار جواب می‌ده</small>';
        } else if (m === 'pv_keyword') {
            tx = '<b>می‌تونی دستیار رو وارد گفتگو کنی</b><small>پیامت رو با «' + VX_AI_KEYWORD + '» شروع کن یا روی پیام دستیار ریپلای بزن</small>';
        }
        b.innerHTML = '<span class="vx-ai-banner-ico"><img src="assets/icons3d/sparkles.png" alt=""></span>'
            + '<span class="vx-ai-banner-tx">' + tx + '</span>';
        const anchor = document.getElementById('messagesBox');
        if (anchor && anchor.parentElement) anchor.parentElement.insertBefore(b, anchor);
        _veyraxSyncBannerGap();
    } else if (existing) {
        existing.remove();
    }
}

// Breathing room between the pinned-message bar and the assistant banner —
// they sit in the same stack and used to touch.
function _veyraxSyncBannerGap() {
    const banner = document.getElementById('veyraxAiBanner');
    if (!banner) return;
    const pin = document.getElementById('pinBar');
    banner.classList.toggle('below-pin', !!(pin && pin.classList.contains('active')));
}

// Cheap 32-bit string hash, used only to detect that a message body changed.
// A plain string comparison would mean carrying every message's full text in
// the render signature on every poll.
function _strHash(s) {
    let h = 5381;
    for (let i = 0; i < s.length; i++) h = ((h * 33) ^ s.charCodeAt(i)) | 0;
    return h;
}

function _applyIncrementalMessages(d) {
    const box = document.getElementById('messagesBox');
    const contentBox = document.getElementById('msgsContent');
    if (!box || !contentBox) return;

    if (d.users) {
        allUsersCache = d.users;
    }

    if (d.unread) {
        updateHamBadge(d.unread);
        for (var uidKey in d.unread) {
            if (lastUnreadCounts[uidKey] !== d.unread[uidKey]) lastUnreadCounts[uidKey] = d.unread[uidKey];
        }
    }
    // The sidebar must follow the poll, not only a full refresh: a message
    // from another user used to sit invisible in the menu until the user
    // clicked something that triggered a full fetch.
    if (d.users || d.unread) {
        try { _renderSidebarFromData(d); } catch (e) {}
    }

    // Demo visitors never get notifications: the sandbox shares the public room,
    // so a brand-new throw-away account would be pinged for every message that
    // had ever been posted there before they arrived.
    if (!VX_IS_DEMO && d.notifications && d.notifications.length > 0) {
        d.notifications.forEach(n => {
            showNotification(n.sender_id, n.name, n.sticker, n.txt, n.msg_id, n.room_name);
            if (n.room_name && n.room_name !== currentRoom && targetUser == null) {
                roomUnreadLocal[n.room_name] = (roomUnreadLocal[n.room_name] || 0) + 1;
            }
        });
    }

    if (!d.messages || d.messages.length === 0) return;

    _net.lastIncomingAt = Date.now();

    let incomingNew = 0;
    let html = '';
    d.messages.forEach(m => {
        // Already on screen — either painted by an earlier poll or still the
        // optimistic bubble the send handler just renamed. Appending it again
        // produced two elements carrying the same id.
        if (m.id && document.getElementById('msg-' + m.id)) {
            if (m.id > lastMsgId) lastMsgId = m.id;
            return;
        }
        // Own message still travelling as an optimistic bubble: the socket
        // echo (WS on) can trigger this refetch BEFORE the send response has
        // renamed the bubble, and painting the fetched row here meant the
        // message existed twice. Skip the paint — the send handler settles
        // the optimistic copy — and only advance the cursor.
        if (m.sender_id == currentUser && (m.message || '') !== '' &&
            pendingMessages.some(p => p.msg === m.message)) {
            if (m.id > lastMsgId) lastMsgId = m.id;
            msgCache[m.id] = { name: 'شما', text: m.message || '', type: m.msg_type || 'text', isMe: true, reply_to_id: m.reply_to_id || null };
            if (m.reply_to_id && _aiWatched.has(String(m.reply_to_id))) _removeAiPlaceholder(m.reply_to_id);
            return;
        }
        if (m.id > lastMsgId) {
            if (m.sender_id != currentUser) incomingNew++;
            lastMsgId = m.id;
            msgCache[m.id] = { name: m.sender_name, text: m.msg_type === 'text' ? (m.message || '') : '', type: m.msg_type || 'text', file_name: m.file_name || null, isMe: (m.sender_id == currentUser), reply_to_id: m.reply_to_id || null };
            html += renderMessage(m);
            if (m.reply_to_id && _aiWatched.has(String(m.reply_to_id))) _removeAiPlaceholder(m.reply_to_id);
        }
    });
    if (!html) return;

    const oldScrollHeight = box.scrollHeight;
    const oldScrollTop = box.scrollTop;
    const distFromBottom = oldScrollHeight - oldScrollTop - box.clientHeight;
    const atBottom = distFromBottom < 80;

    _isRenderingMessages = true;
    _programScrollUntil = Date.now() + 1200;
    box.style.scrollBehavior = 'auto';

    let anchorRow = null;
    const rows = contentBox.children;
    for (let i = 0; i < rows.length; i++) {
        if (rows[i].querySelector && rows[i].querySelector('.msg.pending')) { anchorRow = rows[i]; break; }
    }
    if (anchorRow) anchorRow.insertAdjacentHTML('beforebegin', html);
    else contentBox.insertAdjacentHTML('beforeend', html);

    void box.offsetHeight;

    if (atBottom || incomingNew === 0) {
        box.scrollTop = box.scrollHeight;
        if (incomingNew > 0) { newMessagesSinceScroll = 0; updateScrollBadge(); userScrolledUp = false; }
    } else {
        newMessagesSinceScroll += incomingNew;
        updateScrollBadge();
        var downBtn = document.querySelector('.scroll-btn.down');
        if (downBtn) downBtn.classList.add('visible');
    }

    requestAnimationFrame(function() {
        _isRenderingMessages = false;
        box.style.scrollBehavior = 'smooth';
    });
}

/**
 * Paint a message that arrived over the socket, without any HTTP request.
 *
 * The push already carries the finished row (same shape as a fetch row), so the
 * exact renderer the poll uses can take it directly. Only the conversation on
 * screen is painted; counters, sidebar previews and read receipts are
 * reconciled by one light poll a moment later — invisible, because the bubble
 * is already there.
 *
 * Returns true when the event was fully handled and no fetch is needed.
 */
function _applyWsMessage(m) {
    if (!m || !m.id) return false;
    if (document.hidden) return false;
    // My own message is already on screen as an optimistic bubble; letting the
    // push paint it too would create a duplicate row.
    if (String(m.sender_id) === String(currentUser)) return false;
    if (document.getElementById('msg-' + m.id)) return true;   // already rendered

    if (targetUser) {
        if (String(m.sender_id) !== String(targetUser)) return false;
    } else {
        if (!m.room_name || !currentRoom || m.room_name !== currentRoom) return false;
        if (m.receiver_id) return false;
    }

    _applyIncrementalMessages({ messages: [m] });

    setTimeout(function () {
        if (!document.hidden) fetchMessages(null, { incremental: true });
    }, 260);
    return true;
}

function _processFetchData(d, capturedToken, callback, incremental) {
        if(capturedToken !== _fetchToken) { if (typeof callback === 'function') callback(); return; }
        currentUser = d.current_user_id; isAdmin = d.is_admin; canPin = (d.can_pin === true || d.can_pin === 1);
        // Keep the conversation fingerprint in sync. When it moves during an
        // incremental poll, something already on screen changed (edit,
        // reaction, media removal) and only a full refresh can repaint it.
        if (typeof d.rev === 'string' && d.rev !== '') {
            var _revMoved = (_chatRev !== '' && d.rev !== _chatRev);
            _chatRev = d.rev;
            if (_revMoved && incremental) {
                setTimeout(function () { if (!document.hidden) fetchMessages(); }, 30);
            }
        }
        if (d.current_sticker) MY_STICKER = d.current_sticker;
        canDelete = !(d.delete_lock === true || d.delete_lock === 1 || d.delete_lock === '1');
        var delAllItem = document.getElementById('menuDeleteAllItem');
        if (delAllItem) {
            delAllItem.style.display = 'flex';
            delAllItem.classList.toggle('locked', !canDelete);
            delAllItem.title = canDelete ? 'حذف پیام‌هایی که خودتان فرستاده‌اید' : 'این قابلیت توسط مدیر غیرفعال شده است';
        }
        if(d.bw_nonce) bwNonce = d.bw_nonce;
        // The full refresh also carries the assistant state: without this the
        // banner only appeared after the next incremental poll (or never, when
        // nothing new arrived).
        if (typeof d.ai_active !== 'undefined') _veyraxSetAiBanner(d.ai_active, d.ai_mode);
        if (typeof d.block_state !== 'undefined') _applyBlockState(d.block_state);
        if (d.ai_user_id) window.VX_AI_USER_ID = parseInt(d.ai_user_id, 10) || null;
        document.getElementById('adminPanelBtn').style.display = isAdmin ? 'flex' : 'none';

        if (d.deleted && d.deleted.length) {
            for (let i = 0; i < d.deleted.length; i++) _removeMsgEverywhere(d.deleted[i]);
        }

        if (incremental && d.incremental) {
            _applyIncrementalMessages(d);
            if (d.typing_users) { window._veyraxPollTypers = d.typing_users; }
            if (typeof d.ai_active !== 'undefined') _veyraxSetAiBanner(d.ai_active, d.ai_mode);
            if (typeof d.block_state !== 'undefined') _applyBlockState(d.block_state);
            if (typeof callback === 'function') callback(d);
            return;
        }
        
        if (d.pinned_messages && Array.isArray(d.pinned_messages)) {
            let filtered;
            if (targetUser) {
                const cu = parseInt(currentUser);
                const tu = parseInt(targetUser);
                filtered = d.pinned_messages.filter(p => {
                    const sid = p.sender_id !== null && p.sender_id !== undefined ? parseInt(p.sender_id) : null;
                    const rid = p.receiver_id !== null && p.receiver_id !== undefined ? parseInt(p.receiver_id) : null;
                    const tgt = p.target_user !== null && p.target_user !== undefined ? parseInt(p.target_user) : null;
                    if (sid !== null && rid !== null) {
                        return (sid === cu && rid === tu) || (sid === tu && rid === cu);
                    }
                    if (tgt !== null) {
                        return tgt === tu || tgt === cu;
                    }
                    return false;
                });
            } else {
                filtered = d.pinned_messages.filter(p => {
                    const rid = p.receiver_id !== null && p.receiver_id !== undefined ? parseInt(p.receiver_id) : null;
                    const tgt = p.target_user !== null && p.target_user !== undefined ? parseInt(p.target_user) : null;
                    return (rid === null || rid === 0) && (tgt === null || tgt === 0);
                });
            }
            pinnedMessages = filtered.filter(p => !_localUnpinnedIds.has(parseInt(p.id)));
            renderPinBar();
        } else {
            pinnedMessages = [];
            renderPinBar();
        }
        
        const me = d.users.find(u => u.id == currentUser);
        if(me) currentUsername = me.username;

        if (targetUser && d.users && !d.users.find(u => u.id == targetUser)) {
            try { localStorage.removeItem('bw_last_target'); localStorage.removeItem('bw_last_target_name'); } catch(e){}
            switchRoom('گفتگوی عمومی', 'گفتگوی عمومی', null);
            if(typeof callback === 'function') callback(d);
            return;
        }
        
        if (d.user_settings && typeof d.user_settings.notifications !== 'undefined') {
            notificationsEnabled = (d.user_settings.notifications == 1);
            document.getElementById('notifToggle').checked = notificationsEnabled;
        }

        if(!VX_WELCOME_SEEN && !isAdmin) {
            openModal('welcomeModal');
        } else if (localStorage.getItem('newSecurityCode')) {
            checkAndShowSecurityCode();
        } else {
            setTimeout(() => { checkPhoneAuthRequired(); checkUpdateNotifications(); }, 1000);
        }

        // Same rule as above: a demo visitor is never notified about messages
        // that were already sitting in the shared public room.
        if(!VX_IS_DEMO && d.notifications && d.notifications.length > 0) {
            d.notifications.forEach(n => {
                showNotification(n.sender_id, n.name, n.sticker, n.txt, n.msg_id, n.room_name);
                if (n.room_name && n.room_name !== currentRoom && targetUser == null) {
                    roomUnreadLocal[n.room_name] = (roomUnreadLocal[n.room_name] || 0) + 1;
                }
            });
        }

        const list = document.getElementById('sidebarList');
        _renderSidebarFromData(d);

        let vLock = false;
        let fLock = false;
        
        if (d.settings) {
            vLock = (d.settings.lock_voice == 1);
            fLock = (d.settings.lock_upload == 1);
        }
        else {
            if (typeof d.lock_voice !== 'undefined') vLock = (d.lock_voice == 1);
            if (typeof d.lock_upload !== 'undefined') fLock = (d.lock_upload == 1);
        }

        const attachBtn = document.getElementById('attachBtn');
        const micBtn = document.getElementById('micBtn');
        
        if (attachBtn) attachBtn.style.display = (fLock || VX_IS_DEMO) ? 'none' : 'flex';
        // The mic is hidden by a class, not an inline style: the composer swap
        // (`#inputRow.has-text #micBtn`) also needs to control its visibility,
        // and an inline `display` would win over both rules.
        if (micBtn) {
            micBtn.style.display = VX_IS_DEMO ? 'none' : '';
            micBtn.classList.toggle('is-locked', !!vLock);
            // With the mic gone there is nothing to fall back to, so the send
            // plane stays on screen even while the box is empty.
            const irow = document.getElementById('inputRow');
            if (irow) irow.classList.toggle('no-mic', !!vLock || VX_IS_DEMO);
        }

        if(d.global_alert && d.global_alert.message) {
            const lastSeen = localStorage.getItem('last_seen_alert_id');
            const alertId = d.global_alert.id || d.global_alert.message; 
            
            if(lastSeen != alertId) {
                document.getElementById('globalAlertText').innerHTML = d.global_alert.message.replace(/\n/g, '<br>');
                openModal('globalAlertModal');
                localStorage.setItem('last_seen_alert_id', alertId);
            }
        }

        const box = document.getElementById('messagesBox');
        const contentBox = document.getElementById('msgsContent');
        const loadBtn = document.getElementById('loadMoreBtn');
        
        const oldScrollHeight = box.scrollHeight;
        const oldScrollTop = box.scrollTop;
        const scrollBottom = oldScrollHeight - oldScrollTop - box.clientHeight;
        
        const isFirstLoad = contentBox.innerHTML.trim() === '' || contentBox.innerHTML.trim() === '<div style="text-align:center;margin-top:50px;opacity:0.5;font-size:12px">پیامی نیست</div>';

        let typingText = '';
        if(d.typing_users && d.typing_users.length > 0) {
            let filteredTypers = [];
            const firstTyper = d.typing_users[0];
            const hasObjectTypers = firstTyper && typeof firstTyper === 'object';

            if (targetUser) {
                // حالت خصوصی: فقط نمایش اگر طرف مقابل برای ما تایپ می‌کند
                const cuStr = String(currentUser);
                const tuStr = String(targetUser);
                if (hasObjectTypers) {
                    filteredTypers = d.typing_users.filter(function(u) {
                        const uid = String(u.user_id || u.id || '');
                        const tgt = String(u.target_user || u.target_id || '');
                        // باید: تایپ‌کننده = targetUser و هدف = currentUser
                        if (u.target_user !== undefined || u.target_id !== undefined) {
                            return uid === tuStr && tgt === cuStr;
                        }
                        // اگر target_user نداشت ولی object بود
                        return uid === tuStr;
                    });
                } else {
                    // string array - match by username
                    var partnerObj = d.users ? d.users.find(function(x) { return x.id == targetUser; }) : null;
                    if (partnerObj) {
                        filteredTypers = d.typing_users.filter(function(u) { return u === partnerObj.username; });
                    }
                }
            } else if (currentRoom) {
                // حالت اتاق: فقط نمایش کاربرانی که در همین اتاق تایپ می‌کنند و private نیستند
                if (hasObjectTypers) {
                    filteredTypers = d.typing_users.filter(function(u) {
                        var uid = String(u.user_id || u.id || '');
                        var tgt = u.target_user || u.target_id;
                        var rm = u.room;
                        if (uid === String(currentUser)) return false; // خودم نه
                        if (tgt && parseInt(tgt) !== 0) return false; // private typing نه
                        if (rm && rm !== currentRoom) return false;
                        return true;
                    });
                } else {
                    var myUname = d.users ? ((d.users.find(function(x) { return x.id == currentUser; }) || {}).username || '') : '';
                    filteredTypers = d.typing_users.filter(function(u) { return u && u !== myUname; });
                }
            }

            if (filteredTypers.length > 0) {
                var names = filteredTypers.map(function(u) {
                    if (typeof u === 'string') return u;
                    // پیدا کردن نام از لیست کاربران
                    var found = d.users ? d.users.find(function(x) { return String(x.id) === String(u.user_id || u.id); }) : null;
                    return found ? found.username : (u.username || u.name || '...');
                }).filter(Boolean);
                typingText = names.join('، ') + ' در حال نوشتن...';
            }
        }
        if (typeof window !== 'undefined' && window._veyraxRemoteTypers && Object.keys(window._veyraxRemoteTypers).length > 0) {
            var wsNames = [];
            var nowTs = Date.now();
            Object.keys(window._veyraxRemoteTypers).forEach(function(uid) {
                var tInfo = window._veyraxRemoteTypers[uid];
                if (nowTs - tInfo.at > 5000) { delete window._veyraxRemoteTypers[uid]; return; }
                // avoid showing the same person twice (DB poll + realtime event)
                if (typingText && typingText.indexOf(tInfo.name) !== -1) return;
                wsNames.push(tInfo.name);
            });
            if (wsNames.length > 0) {
                var wsLine = wsNames.join('، ') + ' در حال نوشتن...';
                typingText = typingText ? (typingText + ' | ' + wsLine) : wsLine;
            }
        }
        const typingEl = document.getElementById('typingStatus');
        var wasTypingVisible = typingEl.style.display === 'block';
        if(typingText) {
            typingEl.textContent = typingText;
            typingEl.style.display = 'block';
            // اگر کاربر نزدیک پایین بود و تایپ نشان داده شد → اسکرول کن
            if (!wasTypingVisible && !userScrolledUp) {
                var _tb = document.getElementById('messagesBox');
                var _dist = _tb.scrollHeight - _tb.scrollTop - _tb.clientHeight;
                if (_dist < 120) {
                    _tb.style.scrollBehavior = 'smooth';
                    _tb.scrollTop = _tb.scrollHeight;
                }
            }
        } else {
            typingEl.style.display = 'none';
        }

        if(d.messages && d.messages.length > 0) {
            let msgHtml = '';
            let firstId = d.messages[0].id;
            let incomingNew = 0;
            
            if (!oldestMsgId || firstId < oldestMsgId) oldestMsgId = firstId;

            d.messages.forEach(m=>{
                if (m.id > lastMsgId) {
                    if (m.sender_id != currentUser) incomingNew++;
                    lastMsgId = m.id;
                }
                msgCache[m.id] = { name: m.sender_name, text: m.msg_type === 'text' ? (m.message || '') : '', type: m.msg_type || 'text', file_name: m.file_name || null, isMe: (m.sender_id == currentUser), reply_to_id: m.reply_to_id || null };
                msgHtml += renderMessage(m);
            });

            const seenKey = d.messages.map(m => m.id + ':' + (m.seen_by||0)).join(',');
            // Reactions have to be part of the signature. They change without
            // touching the id, the count or the read state, so a reaction from
            // another user used to produce an identical key and the list was
            // never repainted — the chip only appeared after a manual refresh.
            const rxKey = d.messages.map(function (m) {
                const r = m.reactions || {};
                const keys = Object.keys(r).sort();
                return m.id + ':' + keys.map(function (k) { return k + (r[k] && r[k].count ? r[k].count : 0); }).join('') + '/' + (m.my_reaction || '');
            }).join(',');
            // Edits change neither the id nor the count nor the read state, so
            // an edited message used to keep the list's signature identical and
            // the new text only appeared after a manual refresh.
            const editKey = d.messages.map(function (m) {
                return m.id + ':' + (m.is_edited == 1 ? 1 : 0) + ':' + _strHash(m.message || '') + ':' + (m.media_deleted == 1 ? 'D' : '');
            }).join(',');
            const newKey = loadedHistoryHtml.length + '|' + lastMsgId + '|' + d.messages.length + '|' + seenKey + '|' + rxKey + '|' + editKey;
            const needsUpdate = newKey !== _lastRenderedKey;

            if(needsUpdate) {
                _lastRenderedKey = newKey;

                if(_pendingScrollToMsgId) {
                    if(typeof callback === 'function') callback();
                    return;
                }

                const pendingMsgs = document.querySelectorAll('.msg.pending');
                let pendingHtml = '';
                pendingMsgs.forEach(el => { pendingHtml += el.outerHTML; });

                const snapScrollTop = box.scrollTop;
                const snapScrollHeight = box.scrollHeight;
                const snapClientHeight = box.clientHeight;
                const distFromBottom = snapScrollHeight - snapScrollTop - snapClientHeight;
                const snapAtBottom = distFromBottom < 80;
                const isProgramScroll = Date.now() < _programScrollUntil;
                const hasPendingNav = !!_pendingScrollToMsgId;
                const shouldLock = !isFirstLoad && (userScrolledUp || hasPendingNav) && !snapAtBottom;

                _isRenderingMessages = true;
                _programScrollUntil = Date.now() + 1500;

                box.style.scrollBehavior = 'auto';

                if (shouldLock) {
                    box.style.overflow = 'hidden';
                }

                const _playingMedia = [];
                contentBox.querySelectorAll('audio, video').forEach(function(el) {
                    if (!el.paused) {
                        _playingMedia.push({ src: el.src, currentTime: el.currentTime, msgId: el.closest('[data-msg-id]') ? el.closest('[data-msg-id]').dataset.msgId : null, btnState: el.parentElement && el.parentElement.querySelector ? (el.parentElement.querySelector('[data-vp-role="toggle"]') || {}).textContent : null });
                        el.pause();
                    }
                });

                contentBox.innerHTML = loadedHistoryHtml + msgHtml + pendingHtml;
                _restoreAiPlaceholders(contentBox);

                if (_playingMedia.length > 0) {
                    requestAnimationFrame(function() {
                        _playingMedia.forEach(function(pm) {
                            let target = null;
                            if (pm.msgId) {
                                const msgEl = contentBox.querySelector('[data-msg-id="' + pm.msgId + '"]');
                                if (msgEl) target = msgEl.querySelector('audio, video');
                            }
                            if (!target) {
                                contentBox.querySelectorAll('audio, video').forEach(function(el) {
                                    if (el.src === pm.src) target = el;
                                });
                            }
                            if (target) {
                                target.currentTime = pm.currentTime;
                                target.play().catch(function(){});
                                if (pm.btnState) {
                                    var _card = target.parentElement && target.parentElement.querySelector ? target.parentElement.querySelector('.vplayer') : null;
                                    var _btn = _card && _card.querySelector('[data-vp-role="toggle"]');
                                    if (_card && pm.btnState.indexOf('⏸') !== -1) _card.classList.add('is-playing');
                                }
                            }
                        });
                    });
                }

                // بازگذاری video thumbها از کش که قبلاً لود شده بودند
                contentBox.querySelectorAll('.video-wrap').forEach(function(wrap) {
                    var ph = wrap.querySelector('[id$="-ph"]');
                    var cv = wrap.querySelector('canvas');
                    if(ph || cv) {
                        var vid = wrap.querySelector('video, [onclick]');
                        var srcMatch = wrap.getAttribute('onclick') || '';
                        var cacheKey = null;
                        wrap.querySelectorAll('*').forEach(function(el) {
                            if(el.id && el.id.startsWith('vthumb-')) {
                                var raw = el.id.replace('vthumb-', '');
                                if(videoThumbCache[raw]) cacheKey = raw;
                            }
                        });
                        if(!cacheKey) {
                            var onclickStr = wrap.getAttribute('onclick') || '';
                            var tkMatch = onclickStr.match(/file\.php\?token=([a-f0-9]+)/);
                            if(tkMatch) cacheKey = tkMatch[1];
                        }
                        if(cacheKey && videoThumbCache[cacheKey]) {
                            if(ph) ph.remove();
                            if(cv) cv.remove();
                            var existing = wrap.querySelector('img.video-thumb');
                            if(!existing) {
                                var img = document.createElement('img');
                                img.src = videoThumbCache[cacheKey];
                                img.className = 'video-thumb';
                                wrap.insertBefore(img, wrap.firstChild);
                            }
                        }
                    }
                });

                void box.offsetHeight;

                const newScrollHeight = box.scrollHeight;
                const heightDelta = newScrollHeight - snapScrollHeight;

                if (shouldLock) {
                    box.scrollTop = snapScrollTop + (heightDelta > 0 ? heightDelta : 0);
                    box.style.overflow = '';
                    if (incomingNew > 0 && !hasPendingNav) {
                        newMessagesSinceScroll += incomingNew;
                        updateScrollBadge();
                        var downBtn = document.querySelector('.scroll-btn.down');
                        if (downBtn) downBtn.classList.add('visible');
                    }
                } else if (isProgramScroll && !hasPendingNav) {
                    box.scrollTop = snapScrollTop + (heightDelta > 0 ? heightDelta : 0);
                } else if (!hasPendingNav) {
                    box.style.scrollBehavior = 'auto';
                    box.scrollTop = box.scrollHeight;
                    newMessagesSinceScroll = 0;
                    updateScrollBadge();
                    userScrolledUp = false;
                }

                requestAnimationFrame(function() {
                    _isRenderingMessages = false;
                    box.style.scrollBehavior = 'smooth';
                });
            }
            
            if (d.messages.length >= 20) {
                loadBtn.style.display = 'block';
            } else {
                loadBtn.style.display = 'none';
            }
            
        } else {
            let pendingHtml = '';
            document.querySelectorAll('.msg.pending').forEach(el => pendingHtml += el.outerHTML);
            if (!pendingHtml) {
                contentBox.innerHTML = '<div style="text-align:center;margin-top:50px;opacity:0.5;font-size:12px">پیامی نیست</div>';
            } else {
                contentBox.innerHTML = pendingHtml;
            }
            loadBtn.style.display = 'none';
            loadedHistoryHtml = '';
        }
        if(typeof callback === 'function') callback();
}

function scrollToTop() {
    document.getElementById('messagesBox').scrollTo({ top: 0, behavior: 'smooth' });
}

function scrollToBottom() {
    const box = document.getElementById('messagesBox');
    _programScrollUntil = Date.now() + 400;
    userScrolledUp = false;
    box.scrollTo({ top: box.scrollHeight, behavior: 'smooth' });
    newMessagesSinceScroll = 0;
    updateScrollBadge();
}

// Re-glue the view to the newest message after something above it changed
// height (pending bubble turning into a real one, media finishing its load,
// a reaction row appearing). Silent drift was the reason a just-sent message
// could end up half-hidden below the fold. Does nothing if the reader has
// deliberately scrolled up into older messages.
function _pinIfAtBottom(force) {
    const box = document.getElementById('messagesBox');
    if (!box) return;
    const gap = box.scrollHeight - box.scrollTop - box.clientHeight;
    if (!force && (userScrolledUp || gap > 160)) return;
    _programScrollUntil = Date.now() + 700;
    box.style.scrollBehavior = 'auto';
    box.scrollTop = box.scrollHeight;
    requestAnimationFrame(function () {
        box.scrollTop = box.scrollHeight;
        box.style.scrollBehavior = 'smooth';
    });
}

let scrollTimeout;
document.getElementById('messagesBox').addEventListener('scroll', function() {
    if (_isRenderingMessages) return;

    const box = this;
    const scrollTop = box.scrollTop;
    const scrollHeight = box.scrollHeight;
    const clientHeight = box.clientHeight;
    const distFromBottom = scrollHeight - scrollTop - clientHeight;
    const atBottom = distFromBottom < 60;
    
    const upBtn = document.querySelector('.scroll-btn.up');
    const downBtn = document.querySelector('.scroll-btn.down');
    
    const isProgram = Date.now() < _programScrollUntil;

    if (!isProgram && !_pendingScrollToMsgId) {
        userScrolledUp = distFromBottom > 100;
    }

    if (scrollTop > 300) {
        upBtn.classList.add('visible');
    } else {
        upBtn.classList.remove('visible');
    }

    if (!atBottom) {
        if (!isProgram) downBtn.classList.add('visible');
    } else {
        downBtn.classList.remove('visible');
        if (!isProgram && !_pendingScrollToMsgId) {
            newMessagesSinceScroll = 0;
            updateScrollBadge();
            userScrolledUp = false;
        }
    }

    if (scrollTop <= 10 && !isLoadingMore && !_historyComplete && document.getElementById('loadMoreBtn').style.display !== 'none' && !isProgram && !_pendingScrollToMsgId) {
        loadMoreMessages();
    }
    
    clearTimeout(scrollTimeout);
    scrollTimeout = setTimeout(() => {
        upBtn.classList.remove('visible');
        if (newMessagesSinceScroll === 0) {
            downBtn.classList.remove('visible');
        }
    }, 2500); 
});

function loadMoreMessages() {
    if(!oldestMsgId || isLoadingMore || _historyComplete) return;
    isLoadingMore = true;

    const overlay = document.getElementById('historyOverlay');
    overlay.classList.add('active');
    
    const btn = document.getElementById('loadMoreBtn');

    var fd2 = new FormData();
    fd2.append('action', 'fetch_history');
    fd2.append('csrf_token', csrfToken);
    if(bwNonce) fd2.append('bw_nonce', bwNonce);
    fd2.append('room', currentRoom || 'null');
    fd2.append('target_user', targetUser || 0);
    fd2.append('before_id', oldestMsgId);

    fetch('chat.php', { method: 'POST', body: fd2 })
    .then(function(r) {
        if(!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
    })
    .then(function(text) {
        if(!text || !text.trim()) throw new Error('empty');
        var d;
        try { d = JSON.parse(text); } catch(e) { throw new Error('parse'); }
        if(d.status !== 'success' && !d.messages) throw new Error(d.message || 'error');

        if(d.messages && d.messages.length > 0) {
            let html = '';
            d.messages.forEach(function(m) {
                msgCache[m.id] = { name: m.sender_name, text: m.msg_type === 'text' ? (m.message || '') : '', type: m.msg_type || 'text', file_name: m.file_name || null, isMe: (m.sender_id == currentUser), reply_to_id: m.reply_to_id || null };
                html += renderMessage(m);
            });
            
            const box = document.getElementById('messagesBox');
            const contentBox = document.getElementById('msgsContent');
            
            const oldScrollHeight = box.scrollHeight;
            const oldScrollTop = box.scrollTop;
            
            box.style.scrollBehavior = 'auto';
            
            loadedHistoryHtml = html + loadedHistoryHtml;
            _lastRenderedKey = '';

            contentBox.insertAdjacentHTML('afterbegin', html);
            oldestMsgId = d.messages[0].id;
            
            void box.offsetHeight;
            
            const newScrollHeight = box.scrollHeight;
            _programScrollUntil = Date.now() + 800;
            box.scrollTop = oldScrollTop + (newScrollHeight - oldScrollHeight);
            userScrolledUp = true;
            
            requestAnimationFrame(function() {
                requestAnimationFrame(function() {
                    box.style.scrollBehavior = 'smooth';
                });
            });
            
            if (d.messages.length >= 20) {
                btn.style.display = 'block';
            } else {
                _historyComplete = true;
                btn.style.display = 'none';
                setTimeout(function() { showToast('همه پیام‌های قبلی بارگذاری شدند'); }, 700);
            }
        } else {
            _historyComplete = true;
            btn.style.display = 'none';
            setTimeout(function() { showToast('پیام قدیمی‌تری برای نمایش وجود ندارد'); }, 700);
        }
        
        setTimeout(function() {
            overlay.classList.remove('active');
            setTimeout(function() { isLoadingMore = false; }, 300);
        }, 400);
    })
    .catch(function(err) {
        showToast('خطا در بارگذاری پیام‌های قبلی - دوباره تلاش کنید');
        setTimeout(function() {
            overlay.classList.remove('active');
            isLoadingMore = false;
            btn.style.display = 'block';
        }, 400);
    });
}

function openHelp() { document.getElementById('helpOverlay').classList.add('open'); }
function closeHelp() { document.getElementById('helpOverlay').classList.remove('open'); }
function openDonate() {
    var link = '<?php echo isset($donate_link) ? addslashes($donate_link) : ""; ?>';
    if(link !== "") window.open(link, '_blank');
}
function pinBarDismiss() { if(canPin) { unpinAll(); } else { document.getElementById('pinBar').classList.remove('active'); } }

let pinnedMessages = [];
let _localUnpinnedIds = new Set();
let _pinIndex = 0;
let _pinModalOpen = false;

let _currentViewPin = null;

function _escPin(t) {
    if (!t) return '';
    return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

function _buildPinItemHtml(p) {
    const delBtn = canPin ? `<button class="pin-item-del" onclick="event.stopPropagation();unpinMsgById(${p.id})" title="حذف پین">🗑</button>` : '';
    const goBtn = `<button class="pin-item-go" onclick="event.stopPropagation();scrollToPinned(${p.id})" title="رفتن به پیام">↩</button>`;
    let previewHtml = '';
    if (p.type === 'image') {
        previewHtml = `<img src="${_escPin(p.url)}" class="pin-item-media" loading="lazy" style="pointer-events:none;">`;
        if (p.text) previewHtml += `<div class="pin-item-content" style="margin-top:6px;font-size:12px;color:#94a3b8">${_escPin(p.text)}</div>`;
    } else if (p.type === 'video') {
        previewHtml = `<div style="display:flex;align-items:center;gap:8px;margin-top:6px;"><span style="font-size:18px">🎬</span><span style="font-size:12px;color:#94a3b8">${_escPin(p.file_name||'ویدیو')}</span></div>`;
    } else if (p.type === 'voice') {
        previewHtml = `<div style="display:flex;align-items:center;gap:8px;margin-top:4px;"><span style="font-size:16px">🎤</span><span style="font-size:12px;color:#94a3b8">پیام صوتی</span></div>`;
    } else if (p.type === 'file') {
        previewHtml = `<div class="pin-item-file-box"><span class="pin-item-file-icon">📄</span><span class="pin-item-file-name">${_escPin(p.file_name||'فایل')}</span></div>`;
    } else {
        previewHtml = `<div class="pin-item-content pin-item-content-truncated">${_escPin(p.text||'')}</div>`;
    }
    const pidx = pinnedMessages.findIndex(x => x.id == p.id);
    return `<div class="pin-item" onclick="openPinViewer(${pidx})">
        <div class="pin-item-pin-mark"></div>
        <div class="pin-item-top">
            <div class="pin-item-body">
                <div class="pin-item-header">
                    <span class="pin-item-sender">📌 ${_escPin(p.sender||'')}</span>
                    <span class="pin-item-time">${_escPin(p.time||'')}</span>
                </div>
                ${previewHtml}
            </div>
            <div class="pin-item-actions">${goBtn}${delBtn}</div>
        </div>
    </div>`;
}

function openPinViewer(idx) {
    const p = pinnedMessages[idx];
    if (!p) return;
    _pinIndex = idx;
    _currentViewPin = p.id;
    document.getElementById('pinViewerSender').textContent = p.sender || '';
    document.getElementById('pinViewerTime').textContent = p.time || '';
    const unpinBtn = document.getElementById('pinViewerUnpin');
    if (unpinBtn) unpinBtn.style.display = 'flex';
    const navEl = document.getElementById('pinViewerNav');
    const countEl = document.getElementById('pinViewerCount');
    if (navEl) navEl.style.display = pinnedMessages.length > 1 ? 'flex' : 'none';
    if (countEl) countEl.textContent = (idx + 1) + '/' + pinnedMessages.length;
    const body = document.getElementById('pinViewerBody');
    let html = '';
    if (p.type === 'image') {
        html = `<img src="${_escPin(p.url)}" class="pin-viewer-img" onclick="openLightbox('${_escPin(p.url)}','img')" loading="lazy">`;
        if (p.text) html += `<div class="pin-viewer-text">${_escPin(p.text)}</div>`;
    } else if (p.type === 'video') {
        html = `<video controls class="pin-viewer-video" src="${_escPin(p.url)}" preload="metadata"></video>`;
        if (p.text) html += `<div class="pin-viewer-text">${_escPin(p.text)}</div>`;
    } else if (p.type === 'voice') {
        html = `<audio controls class="pin-viewer-audio" src="${_escPin(p.url)}" preload="none"></audio>`;
    } else if (p.type === 'file') {
        const ext = (p.file_name||'').split('.').pop().toLowerCase();
        const icon = ['pdf'].includes(ext)?'📑':['zip','rar','7z'].includes(ext)?'🗜':['doc','docx'].includes(ext)?'📝':['xls','xlsx'].includes(ext)?'📊':['mp4','webm','mov'].includes(ext)?'🎬':'📄';
        html = `<div class="pin-viewer-file-box">
            <span class="pin-viewer-file-icon">${icon}</span>
            <div class="pin-viewer-file-info">
                <span class="pin-viewer-file-name">${_escPin(p.file_name||'فایل')}</span>
                ${p.url ? `<a href="${_escPin(p.url)}" download class="pin-viewer-file-dl">⬇ دانلود فایل</a>` : ''}
            </div>
        </div>`;
        if (p.text) html += `<div class="pin-viewer-text">${_escPin(p.text)}</div>`;
    } else {
        html = `<div class="pin-viewer-text">${_escPin(p.text||'')}</div>`;
    }
    body.innerHTML = html;
    document.getElementById('pinViewer').classList.add('active');
    document.addEventListener('keydown', _pinViewerEsc);
}

function _pinViewerEsc(e) {
    if (e.key === 'Escape') closePinViewer();
}

function closePinViewer() {
    document.getElementById('pinViewer').classList.remove('active');
    document.removeEventListener('keydown', _pinViewerEsc);
    setTimeout(() => { const b = document.getElementById('pinViewerBody'); if(b) b.innerHTML = ''; }, 300);
}

function openPinModal() {
    const overlay = document.getElementById('pinModal');
    const body = document.getElementById('pinModalBody');
    const subtitle = document.getElementById('pinModalSubtitle');
    const footer = document.getElementById('pinModalFooter');
    _pinModalOpen = true;
    if (pinnedMessages.length === 0) {
        body.innerHTML = `<div class="pin-modal-empty"><span class="pin-modal-empty-icon">📌</span><span class="pin-modal-empty-text">پیامی پین نشده است</span></div>`;
        if (subtitle) subtitle.textContent = '۰ پیام';
        if (footer) footer.classList.remove('has-pins');
    } else {
        if (subtitle) subtitle.textContent = pinnedMessages.length + ' پیام پین شده';
        if (footer) footer.classList.toggle('has-pins', canPin);
        body.innerHTML = `<div class="pin-item-loading"><div class="pin-item-spinner"></div><span>در حال بارگذاری...</span></div>`;
        setTimeout(() => {
            if (!_pinModalOpen) return;
            body.innerHTML = pinnedMessages.map(p => _buildPinItemHtml(p)).join('');
        }, 80);
    }
    overlay.classList.add('active');
    document.addEventListener('keydown', _pinEscHandler);
}

function _pinEscHandler(e) {
    if (e.key === 'Escape') closePinModal();
}

function closePinModal() {
    const overlay = document.getElementById('pinModal');
    _pinModalOpen = false;
    overlay.classList.remove('active');
    document.removeEventListener('keydown', _pinEscHandler);
}

function scrollToPinnedCurrent() {
    if(!pinnedMessages.length) return;
    var p = pinnedMessages[_pinIndex];
    if(p && p.id) scrollToMsg(p.id);
}

// ─── Pin Bar Popup ───
let _pinPopupCurrentId = null;

function openPinBarPopup() {
    if(!pinnedMessages.length) return;
    var p = pinnedMessages[_pinIndex];
    if(!p) return;
    _pinPopupCurrentId = p.id;

    document.getElementById('pinPopupSender').textContent = p.sender || '';
    document.getElementById('pinPopupTime').textContent = p.time || '';

    var unpinBtn = document.getElementById('pinPopupUnpinBtn');
    if (unpinBtn) {
        if (isAdmin || canPin) {
            unpinBtn.style.display = 'block';
        } else {
            unpinBtn.style.display = 'none';
        }
    }

    var bodyEl = document.getElementById('pinPopupBody');
    bodyEl.innerHTML = '<div class="pin-popup-loading"><div class="pin-popup-spinner"></div><div class="pin-popup-spinner-text">در حال بارگذاری پیام...</div></div>';

    var overlay = document.getElementById('pinPopupOverlay');
    overlay.classList.add('active');
    document.addEventListener('keydown', _pinPopupEscHandler);

    setTimeout(function() {
        var html = '';
        if(p.type === 'image') {
            html = '<div class="pin-popup-label">تصویر پین شده</div>';
            html += `<img src="${_escPin(p.url)}" style="width:100%;max-height:280px;object-fit:contain;border-radius:12px;background:#0f172a;cursor:zoom-in;border:1px solid rgba(239,68,68,0.15);" onclick="closePinPopup();openLightbox('${_escPin(p.url)}','img')" loading="lazy">`;
            if(p.text) html += `<div class="pin-popup-msg-box" style="margin-top:10px;">${_escPin(p.text)}</div>`;
        } else if(p.type === 'video') {
            html = '<div class="pin-popup-label">ویدیو پین شده</div>';
            html += `<video controls style="width:100%;max-height:250px;border-radius:12px;background:#0f172a;border:1px solid rgba(239,68,68,0.15);" src="${_escPin(p.url)}" preload="metadata"></video>`;
            if(p.text) html += `<div class="pin-popup-msg-box" style="margin-top:10px;">${_escPin(p.text)}</div>`;
        } else if(p.type === 'voice') {
            html = '<div class="pin-popup-label">پیام صوتی پین شده</div>';
            html += `<audio controls style="width:100%;border-radius:12px;margin-top:4px;" src="${_escPin(p.url)}" preload="none"></audio>`;
        } else if(p.type === 'file') {
            const ext = (p.file_name||'').split('.').pop().toLowerCase();
            const icon = ['pdf'].includes(ext)?'📑':['zip','rar','7z'].includes(ext)?'🗜':['doc','docx'].includes(ext)?'📝':['xls','xlsx'].includes(ext)?'📊':['mp4','webm','mov'].includes(ext)?'🎬':'📄';
            html = '<div class="pin-popup-label">فایل پین شده</div>';
            html += `<div style="background:rgba(239,68,68,0.07);border:1px solid rgba(239,68,68,0.18);border-radius:14px;padding:14px 16px;display:flex;align-items:center;gap:12px;">
                <span style="font-size:28px;flex-shrink:0;">${icon}</span>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:13px;color:#e2e8f0;font-weight:bold;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${_escPin(p.file_name||'فایل')}</div>
                    ${p.url ? `<a href="${_escPin(p.url)}" download style="display:inline-flex;align-items:center;gap:5px;background:rgba(239,68,68,0.2);border:1px solid rgba(239,68,68,0.35);color:#fca5a5;padding:5px 12px;border-radius:8px;font-size:12px;text-decoration:none;margin-top:7px;transition:0.18s;" onmouseover="this.style.background='rgba(239,68,68,0.35)'" onmouseout="this.style.background='rgba(239,68,68,0.2)'">⬇ دانلود فایل</a>` : ''}
                </div>
            </div>`;
            if(p.text) html += `<div class="pin-popup-msg-box" style="margin-top:10px;">${_escPin(p.text)}</div>`;
        } else {
            html = '<div class="pin-popup-label">متن پیام پین شده</div>';
            var msgText = (p.text || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
            html += `<div class="pin-popup-msg-box">${msgText}</div>`;
        }
        bodyEl.innerHTML = html;
    }, 380);
}


function unpinFromPopup() {
    var id = _pinPopupCurrentId;
    if (!id) return;
    closePinPopup();
    setTimeout(function() { unpinMsgById(id); }, 200);
}

function _pinPopupEscHandler(e) {
    if(e.key === 'Escape') closePinPopup();
}

function closePinPopup() {
    var overlay = document.getElementById('pinPopupOverlay');
    overlay.classList.remove('active');
    document.removeEventListener('keydown', _pinPopupEscHandler);
    setTimeout(function() {
        document.getElementById('pinPopupBody').innerHTML = '<div class="pin-popup-loading"><div class="pin-popup-spinner"></div><div class="pin-popup-spinner-text">در حال بارگذاری...</div></div>';
    }, 350);
}

function gotoMsgFromPinPopup() {
    closePinPopup();
    var id = _pinPopupCurrentId;
    if(!id) return;
    setTimeout(function() { scrollToMsg(id); }, 200);
}

function scrollToPinned(id) {
    closePinModal();
    if (id) scrollToMsg(id);
}

function navigatePin(dir) {
    if (!pinnedMessages.length) return;
    _pinIndex = (_pinIndex + dir + pinnedMessages.length) % pinnedMessages.length;
    _updatePinBar();
    const p = pinnedMessages[_pinIndex];
    if (p && p.id) scrollToMsg(p.id);
}

function navigatePinAndView(dir) {
    if (!pinnedMessages.length) return;
    _pinIndex = (_pinIndex + dir + pinnedMessages.length) % pinnedMessages.length;
    _updatePinBar();
    openPinViewer(_pinIndex);
}

function renderPinBar() {
    const bar = document.getElementById('pinBar');
    const unpinBtn = document.getElementById('pinBarUnpin');
    if (pinnedMessages.length > 0) {
        if (_pinIndex >= pinnedMessages.length) _pinIndex = pinnedMessages.length - 1;
        _updatePinBar();
        bar.classList.add('active');
        if (unpinBtn) unpinBtn.style.display = canPin ? 'flex' : 'none';
    } else {
        bar.classList.remove('active');
        _pinIndex = 0;
    }
    if (typeof _veyraxSyncBannerGap === 'function') _veyraxSyncBannerGap();
    if (_pinModalOpen) openPinModal();
}

function _updatePinBar() {
    const preview = document.getElementById('pinBarPreview');
    const countEl = document.getElementById('pinBarCount');
    const p = pinnedMessages[_pinIndex];
    if (!p) return;
    let previewText = '';
    if (p.type === 'image') previewText = '🖼 تصویر';
    else if (p.type === 'file') previewText = '📄 ' + (p.file_name||'فایل');
    else if (p.type === 'voice') previewText = '🎤 پیام صوتی';
    else if (p.text) {
        // Truncate on a word-ish boundary and mark the cut, otherwise the bar
        // ends mid-token ("https://example.c") and reads like a rendering bug.
        const _plain = p.text.replace(/<[^>]+>/g, '').trim();
        previewText = _plain.length > 55 ? _plain.substring(0, 55).replace(/\s+\S*$/, '') + '…' : _plain;
    }
    else previewText = '...';
    if (preview) preview.textContent = previewText;
    if (countEl) countEl.textContent = (_pinIndex+1) + '/' + pinnedMessages.length;
    const navSection = document.getElementById('pinBarPrev')?.parentElement;
    if (navSection) navSection.style.display = pinnedMessages.length > 1 ? 'flex' : 'none';
}

function pinMsg(id) {
    if (!canPin) return;
    const c = msgCache[id];
    if (!c) { showToast('پیام یافت نشد'); return; }
    if (pinnedMessages.find(p => p.id == id)) { showToast('این پیام قبلاً پین شده است'); return; }
    apiCall('chat.php', 'pin_message', { msg_id: id, room: currentRoom || 'null', target_user: targetUser || 0 }, function(d) {
        showToast('پیام پین شد 📌');
        const el = document.getElementById('msg-' + id);
        if (el) el.classList.add('pinned-highlight');
        fetchMessages();
    }, function(d) {
        var msg = (d && d.message) ? d.message : 'خطا در پین کردن';
        showToast(msg);
    });
}

function unpinMsgByMsgId(msgId) {
    const p = pinnedMessages.find(x => x.id == msgId);
    if (!p) return;
    unpinMsgById(p.id);
}

function unpinAll() {
    if (!canPin) return;
    showConfirm('تمام پین‌ها حذف شود؟', () => {
        pinnedMessages.forEach(p => _localUnpinnedIds.add(parseInt(p.id)));
        pinnedMessages = [];
        _pinIndex = 0;
        renderPinBar();
        closePinModal();
        apiCall('chat.php', 'unpin_all', { room: currentRoom || 'null', target_user: targetUser || 0 }, () => {
            showToast('پین‌ها حذف شدند');
        }, () => {
            showToast('خطا در حذف پین‌ها');
        });
    });
}

function unpinMsgById(id) {
    if (!canPin) { showToast('دسترسی ندارید'); return; }
    const pinObj = pinnedMessages.find(p => p.id == id || p.pin_id == id);
    const msgId = pinObj ? pinObj.id : id;
    const pinRecordId = pinObj ? (pinObj.pin_id || pinObj.id) : id;
    _localUnpinnedIds.add(parseInt(msgId));
    pinnedMessages = pinnedMessages.filter(p => p.id != msgId);
    if (_pinIndex >= pinnedMessages.length) _pinIndex = Math.max(0, pinnedMessages.length - 1);
    renderPinBar();
    const el = document.getElementById('msg-' + msgId);
    if (el) el.classList.remove('pinned-highlight');
    document.querySelectorAll('.pinned-highlight').forEach(function(e) { if (e.dataset.msgId == msgId) e.classList.remove('pinned-highlight'); });
    apiCall('chat.php', 'unpin_message', { pin_record_id: pinRecordId, msg_id: msgId, room: currentRoom || 'null', target_user: targetUser || 0 }, function() {
        showToast('پین حذف شد');
    }, function(d) {
        _localUnpinnedIds.delete(parseInt(msgId));
        showToast((d && d.message) ? d.message : 'خطا در حذف پین');
    });
}

function updateHamBadge(unreadMap) {
    const badge = document.getElementById('hamBadge');
    let total = 0;
    if(unreadMap) {
        Object.entries(unreadMap).forEach(([uid, cnt]) => {
            if(String(uid) !== String(targetUser)) total += (cnt || 0);
        });
    }
    if(total > 0) {
        badge.textContent = vxBadge(total);
        badge.style.display = 'flex';
    } else {
        badge.style.display = 'none';
    }
}

let _fetchToken = 0;

function showChatTransition(name) {
    const ov = document.getElementById('chatTransOverlay');
    document.getElementById('chatTransName').textContent = name;
    ov.classList.add('active');
}
function hideChatTransition() {
    document.getElementById('chatTransOverlay').classList.remove('active');
}

function _updateChannelInputVisibility(roomName) {
    const roomData = allRoomsCache ? allRoomsCache.find(x => x.name === roomName) : null;
    const isChannel = roomData && roomData.room_kind === 'channel';
    const isChannelCreator = roomData && parseInt(roomData.is_channel_creator) === 1;
    const inputBox = document.getElementById('inputBox');
    if (inputBox) inputBox.style.display = (isChannel && !isChannelCreator) ? 'none' : '';
}

function switchRoom(r, t, id){ 
    _fetchToken++;
    const token = _fetchToken;
    currentRoom=r; currentRoomId=id; targetUser=null;
    if (typeof _applyBlockState === 'function') _applyBlockState('none');
    // Different conversation, different fingerprint: drop the old one so the
    // first response of the new room is treated as a fresh baseline.
    _chatRev = '';
    _aiWatched.clear();
    const _oldBanner = document.getElementById('veyraxAiBanner');
    if (_oldBanner) _oldBanner.remove();
    _localUnpinnedIds.clear();
    const roomData = allRoomsCache ? allRoomsCache.find(x => x.name === r) : null;
    const isChannel = roomData && roomData.room_kind === 'channel';
    const isChannelCreator = roomData && parseInt(roomData.is_channel_creator) === 1;
    _updateChannelInputVisibility(r);
    const typingEl = document.getElementById('typingStatus');
    if (typingEl) typingEl.style.display = 'none';
    document.getElementById('chatTitle').textContent=t; 
    document.getElementById('chatStatus').textContent= isChannel ? (roomData && roomData.type === 'public' ? 'کانال عمومی' : 'کانال خصوصی') : 'اتاق عمومی'; 
    lastMsgId = 0; oldestMsgId = null; _historyComplete = false;
    loadedHistoryHtml = '';
    userScrolledUp = false;
    _lastRenderedKey = '';
    _pendingScrollToMsgId = null;
    roomUnreadLocal[r] = 0;
    cancelReply();
    closeSearchPanel();
    document.getElementById('msgsContent').innerHTML = '';
    document.getElementById('loadMoreBtn').style.display = 'none';
    showChatTransition(t);
    try { localStorage.setItem('bw_last_room', r); localStorage.setItem('bw_last_room_id', id||''); localStorage.removeItem('bw_last_target'); localStorage.removeItem('bw_last_target_name'); } catch(e){}
    setTimeout(() => {
        if(token !== _fetchToken) return;
        fetchMessages(() => {
            if(token !== _fetchToken) return;
            _scrollToBottomOnLoad();
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    setTimeout(() => {
                        if(token !== _fetchToken) return;
                        hideChatTransition();
                    }, 60);
                });
            });
        });
    }, 80);
    closeMenu(); 
}

// ── Private-chat blocking ───────────────────────────────────────────────────
// State comes from every fetch (`block_state`), so a block made on another
// device shows up here on the next poll. The composer is disabled in both
// directions; only the blocker gets the unblock button.
let _blockState = 'none';
function _applyBlockState(state) {
    _blockState = state || 'none';
    const banner = document.getElementById('blockBanner');
    const txt = document.getElementById('blockBannerTxt');
    const btn = document.getElementById('blockBannerBtn');
    const pvBtn = document.getElementById('pvBlockBtn');
    const input = document.getElementById('msgInput');
    const sendBtn = document.getElementById('sendBtn');
    if (!banner) return;

    if (!targetUser) {
        banner.style.display = 'none';
        if (pvBtn) pvBtn.style.display = 'none';
        if (input) { input.disabled = false; input.placeholder = 'پیام...'; }
        if (sendBtn) sendBtn.disabled = false;
        return;
    }
    if (pvBtn) {
        pvBtn.style.display = '';
        const blocked = (_blockState === 'by_me' || _blockState === 'mutual');
        pvBtn.title = blocked ? 'رفع بلاک' : 'مسدود کردن کاربر';
        pvBtn.classList.toggle('is-blocked', blocked);
    }
    if (_blockState === 'none') {
        banner.style.display = 'none';
        if (input) { input.disabled = false; input.placeholder = 'پیام...'; }
        if (sendBtn) sendBtn.disabled = false;
        return;
    }
    banner.style.display = 'flex';
    if (_blockState === 'by_them') {
        txt.textContent = 'این کاربر شما را مسدود کرده است؛ پیام‌های شما به او نمی‌رسد.';
        btn.style.display = 'none';
        if (input) { input.disabled = true; input.placeholder = 'این کاربر شما را مسدود کرده است'; }
    } else {
        txt.textContent = _blockState === 'mutual'
            ? 'بلاک دوطرفه — برای ارسال، بلاک خود را بردارید.'
            : 'این کاربر را مسدود کرده‌اید؛ پیام‌ها به او نمی‌رسد.';
        btn.textContent = 'رفع بلاک';
        btn.style.display = '';
        if (input) { input.disabled = true; input.placeholder = 'برای ارسال ابتدا رفع بلاک کنید'; }
    }
    if (sendBtn) sendBtn.disabled = true;
}

function toggleBlockUser() {
    if (!targetUser) return;
    const unblocking = (_blockState === 'by_me' || _blockState === 'mutual');
    if (!unblocking) {
        const nameEl = document.getElementById('blockTargetName');
        const t = document.getElementById('chatTitle');
        if (nameEl) nameEl.textContent = (t && t.textContent.trim()) ? t.textContent.trim() : 'این کاربر';
        openModal('blockConfirmModal');
        return;
    }
    apiCall('chat.php', unblocking ? 'unblock_user' : 'block_user', { target_user: targetUser }, function (d) {
        if (!d) return;
        if (d.status === 'success') {
            showToast(d.message);
            _applyBlockState(d.blocked || 'none');
            try { fetchMessages(null, { incremental: true }); } catch (e) {}
        } else {
            showToast(d.message || 'خطا', 'error');
        }
    });
}


// Runs after the in-app confirmation sheet (no browser confirm()).
function confirmBlockUser() {
    closeModal('blockConfirmModal');
    if (!targetUser) return;
    apiCall('chat.php', 'block_user', { target_user: targetUser }, function (d) {
        if (!d) return;
        if (d.status === 'success') {
            showToast(d.message);
            _applyBlockState(d.blocked || 'by_me');
            try { fetchMessages(null, { incremental: true }); } catch (e) {}
        } else {
            showToast(d.message || 'خطا', 'error');
        }
    });
}

function startPrivate(u, n){ 
    _fetchToken++;
    const token = _fetchToken;
    targetUser=u; currentRoom=null;
    _chatRev = '';
    _localUnpinnedIds.clear();
    const inputBox = document.getElementById('inputBox');
    if (inputBox) inputBox.style.display = '';
    const typingEl = document.getElementById('typingStatus');
    if (typingEl) typingEl.style.display = 'none';
    document.getElementById('chatTitle').textContent=n; 
    document.getElementById('chatStatus').textContent='گفتگوی خصوصی'; 
    _applyBlockState('none'); // resets the banner; the next fetch brings the real state
    lastMsgId = 0; oldestMsgId = null; _historyComplete = false;
    loadedHistoryHtml = '';
    userScrolledUp = false;
    _lastRenderedKey = '';
    _pendingScrollToMsgId = null;
    cancelReply();
    closeSearchPanel();
    document.getElementById('msgsContent').innerHTML = '';
    document.getElementById('loadMoreBtn').style.display = 'none';
    showChatTransition(n);
    try { localStorage.setItem('bw_last_target', u); localStorage.setItem('bw_last_target_name', n); localStorage.removeItem('bw_last_room'); localStorage.removeItem('bw_last_room_id'); } catch(e){}
    setTimeout(() => {
        if(token !== _fetchToken) return;
        fetchMessages(() => {
            if(token !== _fetchToken) return;
            _scrollToBottomOnLoad();
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    setTimeout(() => {
                        if(token !== _fetchToken) return;
                        hideChatTransition();
                    }, 60);
                });
            });
        });
    }, 80);
    closeMenu(); 
}

function _scrollToBottomOnLoad() {
    var box = document.getElementById('messagesBox');
    box.style.scrollBehavior = 'auto';
    box.scrollTop = box.scrollHeight;
    void box.offsetHeight;
    requestAnimationFrame(function() {
        box.scrollTop = box.scrollHeight;
        userScrolledUp = false;
        newMessagesSinceScroll = 0;
        updateScrollBadge();
        setTimeout(function() {
            box.style.scrollBehavior = 'smooth';
            _programScrollUntil = 0;
        }, 100);
    });
}

function handleUserCtx(e, id, name){ if (_vInspectBypass(e)) return; e.preventDefault(); ctxId=id; ctxName=name; const cu=(allUsersCache||[]).find(x=>x.id==id); if(cu&&cu.display_name) ctxName=cu.display_name; showCtx(e); }
function showCtx(e){ const m=document.getElementById('ctxMenu'); let x=e.pageX, y=e.pageY; if(x+160>window.innerWidth)x-=160; m.style.left=x+'px'; m.style.top=y+'px'; m.style.display='block'; document.getElementById('ctxBan').style.display=isAdmin?'flex':'none'; document.getElementById('ctxInvite').style.display=isAdmin?'flex':'none'; }
function hideCtx(){ document.getElementById('ctxMenu').style.display='none'; document.getElementById('msgCtxMenu').style.display='none'; const rc = document.getElementById('roomCtxMenu'); if(rc) rc.style.display='none'; }
function handleCtx(act){ hideCtx(); if(act==='private') startPrivate(ctxId, ctxName); if(act==='ban') showConfirm('مسدود شود؟', ()=>apiCall('admin.php','ban_user',{user_id:ctxId},()=>showToast('انجام شد'))); if(act==='invite') openInviteModal(); }

let _roomCtxRoomName = '';
let _roomCtxRoomId = null;
function roomCtxMenu(e, roomName, roomId) {
    if (_vInspectBypass(e)) return;
    e.preventDefault();
    e.stopPropagation();
    hideCtx();
    _roomCtxRoomName = roomName;
    _roomCtxRoomId = roomId;
    const m = document.getElementById('roomCtxMenu');
    let x = e.pageX, y = e.pageY;
    if (x + 180 > window.innerWidth) x -= 180;
    if (y + 100 > window.innerHeight) y -= 80;
    m.style.left = x + 'px';
    m.style.top = y + 'px';
    m.style.display = 'block';
    const hasPins = (pinnedMessages && pinnedMessages.length > 0 && currentRoom === roomName);
    document.getElementById('roomCtxUnpinAll').style.display = (hasPins && (isAdmin || canPin)) ? 'flex' : 'none';
}
function handleRoomCtx(act) {
    hideCtx();
    if (act === 'view_pins') {
        if (currentRoom !== _roomCtxRoomName) {
            switchRoom(_roomCtxRoomName, _roomCtxRoomName, _roomCtxRoomId);
            setTimeout(() => openPinModal(), 800);
        } else {
            openPinModal();
        }
    } else if (act === 'unpin_all') {
        showConfirm('همه پین‌های این اتاق حذف شود؟', () => unpinAll());
    }
}

function getMsgData(id) {
    return msgCache[id] || null;
}

function getMsgIsMe(el) {
    if(!el) return false;
    const row = el.closest ? el.closest('.msg-row') : null;
    if(row) return row.classList.contains('me');
    const bubble = el.classList.contains('msg') ? el : el.closest('.msg');
    if(bubble) return bubble.classList.contains('me');
    return false;
}

function getMsgIdFromEl(el) {
    const bubble = el.classList && el.classList.contains('msg') ? el : el.closest && el.closest('.msg');
    if(!bubble) return null;
    return parseInt(bubble.dataset.msgId) || null;
}

function onMsgClick(e, el) {
    if(e.target.closest('.reply-content') || e.target.closest('.media-container') || e.target.closest('.file-box') || e.target.closest('audio') || e.target.closest('a') || e.target.closest('.msg-act-btn')) return;
    if(Date.now() - _longPressTime < 800) return;
    
    // اگر پیام پین‌شده‌ست، پاپ‌آپ پین رو باز کن
    if(el.classList.contains('pinned-highlight')) {
        const msgId = getMsgIdFromEl(el);
        if(msgId) {
            const pinIdx = pinnedMessages.findIndex(p => p.id == msgId);
            if(pinIdx !== -1) {
                _pinIndex = pinIdx;
                openPinBarPopup();
                return;
            }
        }
    }
    
    if(window.innerWidth >= 769) return;
}

function onMsgCtx(e, el) {
    if(e.target.closest('.msg-act-btn')) return;
    if (_vInspectBypass(e)) return;
    e.preventDefault();
    e.stopPropagation();
    hideCtx();
    const id = getMsgIdFromEl(el);
    const isMe = getMsgIsMe(el);
    const sender = el.dataset.sender || '';
    if(!id) return;
    selectedMsgId = id;
    selectedMsgSender = sender;
    selectedMsgText = (msgCache[id] ? msgCache[id].text : '') || '';
    const hasText = !!(selectedMsgText && selectedMsgText.trim());

    const m = document.getElementById('msgCtxMenu');
    let x = e.pageX, y = e.pageY;
    if(x + 180 > window.innerWidth) x -= 180;
    if(y + 180 > window.innerHeight) y -= 180;
    m.style.left = x + 'px';
    m.style.top = y + 'px';
    m.style.display = 'block';

    const isTextMsg = !msgCache[id] || msgCache[id].type === 'text' || !msgCache[id].type;
    document.getElementById('ctxEditMsg').style.display = (isMe && isTextMsg) ? 'flex' : 'none';
    document.getElementById('ctxDelMsg').style.display = (isMe || isAdmin) ? 'flex' : 'none';
    const alreadyPinned = pinnedMessages.find(p => p.id == id);
    document.getElementById('ctxPinMsg').style.display = (canPin && !alreadyPinned) ? 'flex' : 'none';
    document.getElementById('ctxUnpinMsg').style.display = (canPin && !!alreadyPinned) ? 'flex' : 'none';
}

function handleMsgCtxAction(act) {
    hideCtx();
    if(act === 'reply') doReplySelected();
    if(act === 'edit') editMsgById(selectedMsgId);
    if(act === 'delete') delMsg(selectedMsgId);
    if(act === 'copy') copyMsg(selectedMsgText);
    if(act === 'pin') pinMsg(selectedMsgId);
    if(act === 'unpin') unpinMsgByMsgId(selectedMsgId);
}

function setReplyFromMsg(id) {
    const c = msgCache[id];
    const name = (c && c.name) ? c.name : '...';
    const text = (c && c.text != null) ? c.text : null;
    setReply(id, name, text);
}

function copyMsgById(id) {
    const c = msgCache[id];
    const text = c ? c.text : null;
    if(text) copyMsg(text);
    else showToast('متنی برای کپی وجود ندارد');
}

function editMsgById(id) {
    const c = msgCache[id];
    if(!c) { showToast('پیام یافت نشد'); return; }
    editMsg(id, c.text || '');
}

function openMsgMenu(id, isMe, _unused, senderName) {
    if(!id) return;
    selectedMsgId = id;
    selectedMsgSender = senderName;
    const c = msgCache[id];
    selectedMsgText = (c ? c.text : '') || '';
    const realName = (c && c.name) ? c.name : senderName;
    const hasText = !!(selectedMsgText && selectedMsgText.trim());
    const isTextMsg = c && (c.type === 'text' || !c.type);

    const grid = document.getElementById('msgActionsGrid');
    let btns = `<div class="action-item" onclick="doReplySelected();closeMsgMenu()"><span class="action-icon">↩</span> پاسخ</div>`;
    if(hasText) btns += `<div class="action-item" onclick="copyMsg(selectedMsgText);closeMsgMenu()"><span class="action-icon">📋</span> کپی</div>`;
    if(isMe && isTextMsg) btns += `<div class="action-item" onclick="editMsgById(selectedMsgId);closeMsgMenu()"><span class="action-icon">✎</span> ویرایش</div>`;
    if(canPin) {
        const alreadyPinned2 = pinnedMessages.find(p => p.id == selectedMsgId);
        if(!alreadyPinned2) btns += `<div class="action-item" onclick="pinMsg(selectedMsgId);closeMsgMenu()"><span class="action-icon">📌</span> پین</div>`;
        else btns += `<div class="action-item" onclick="unpinMsgByMsgId(selectedMsgId);closeMsgMenu()"><span class="action-icon">📌</span> آن‌پین</div>`;
    }
    if(canDelete && (isMe || isAdmin)) btns += `<div class="action-item" onclick="delMsg(selectedMsgId);closeMsgMenu()" style="color:#f87171;border-color:rgba(239,68,68,0.3)"><span class="action-icon">🗑</span> حذف</div>`;

    grid.innerHTML = btns;
    document.getElementById('msgMenuTitle').textContent = 'گزینه‌های پیام ' + realName;
    openModal('msgMenu');
}

function doReplySelected() {
    const c = msgCache[selectedMsgId];
    const name = (c && c.name) ? c.name : selectedMsgSender;
    const text = (c && c.text != null) ? c.text : selectedMsgText;
    setReply(selectedMsgId, name, text);
}

function closeMsgMenu() {
    closeModal('msgMenu');
}

/* ─── DEPRECATED wrappers kept for back-compat with right-click ctx ─── */
function handleMsgClick(el, id, isMe, textAttr, senderName) { onMsgClick({target: el, stopPropagation:()=>{}}, el); }
function handleMsgCtx(e, id, isMe, textAttr, senderName) { onMsgCtx(e, e.currentTarget || e.target.closest('.msg') || e.target); }

let _lbSpinTimer = null;
function _lbSpin(on) {
    const s = document.getElementById('lightboxSpinner');
    if (!s) return;
    s.style.display = on ? 'block' : 'none';
    if (!on && _lbSpinTimer) { clearTimeout(_lbSpinTimer); _lbSpinTimer = null; }
}

function openLightbox(src, type) {
    if(!src) return;
    const overlay = document.getElementById('lightboxOverlay');
    const img = document.getElementById('lightboxImg');
    const video = document.getElementById('lightboxVideo');
    const dlBtn = document.getElementById('lightboxDownloadBtn');

    // Every open re-arms the same handlers. Assigning `on*` (instead of
    // addEventListener) replaces the previous one, so a few hundred opens do
    // not leave a pile of listeners behind.
    video.onloadeddata = video.oncanplay = video.onplaying = video.onerror = null;
    img.onload = img.onerror = null;

    if(type === 'video') {
        img.style.display = 'none';
        video.style.display = 'block';
        _lbSpin(true);
        const hideSpin = function () { _lbSpin(false); };
        // The spinner used to be switched on for videos and never switched
        // off — it sat in the middle of the picture and spun forever.
        video.onloadeddata = hideSpin;
        video.oncanplay    = hideSpin;
        video.onplaying    = hideSpin;
        video.onerror      = hideSpin;
        video.src = src;
        try { video.load(); } catch (e) {}
        const p = video.play();
        if (p && p.catch) p.catch(function () {});
        // Safety net: a codec the browser refuses can fire nothing at all.
        if (_lbSpinTimer) clearTimeout(_lbSpinTimer);
        _lbSpinTimer = setTimeout(hideSpin, 12000);
    } else {
        video.style.display = 'none';
        try { video.pause(); } catch (e) {}
        video.removeAttribute('src');
        img.style.display = 'block';
        _lbSpin(true);
        img.onload  = function () { _lbSpin(false); };
        img.onerror = function () { _lbSpin(false); };
        img.src = src;
        img._zoomed = false;
        img.style.transform = 'scale(1)';
        if (_lbSpinTimer) clearTimeout(_lbSpinTimer);
        _lbSpinTimer = setTimeout(function () { _lbSpin(false); }, 12000);
    }

    dlBtn.href = src;
    dlBtn.setAttribute('onclick', `event.stopPropagation(); window.open('${src}', '_blank')`);

    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';

    overlay.addEventListener('keydown', lightboxKeyHandler);
    document.addEventListener('keydown', lightboxKeyHandler);
}

function closeLightbox() {
    const overlay = document.getElementById('lightboxOverlay');
    const video = document.getElementById('lightboxVideo');
    video.onloadeddata = video.oncanplay = video.onplaying = video.onerror = null;
    try { video.pause(); } catch (e) {}
    video.removeAttribute('src');
    try { video.load(); } catch (e) {}
    _lbSpin(false);
    overlay.style.display = 'none';
    document.body.style.overflow = '';
    document.removeEventListener('keydown', lightboxKeyHandler);
}

function lightboxKeyHandler(e) {
    if(e.key === 'Escape') closeLightbox();
}

function toggleLightboxZoom(img) {
    img._zoomed = !img._zoomed;
    img.style.transform = img._zoomed ? 'scale(2)' : 'scale(1)';
    img.style.cursor = img._zoomed ? 'zoom-out' : 'zoom-in';
}

let _lastTapEl = null;
let _longPressTimer = null;
let _longPressFired = false;
let _longPressTime = 0;
let _touchStartX = 0;
let _touchStartY = 0;
let _touchMoved = false;

document.getElementById('messagesBox').addEventListener('mouseover', function(e) {
    var row = e.target.closest('.msg-row');
    if(!row) return;
    var acts = row.querySelector('.msg-actions');
    if(acts) acts.classList.add('hover-visible');
});

document.getElementById('messagesBox').addEventListener('mouseout', function(e) {
    var row = e.target.closest('.msg-row');
    if(!row) return;
    var toEl = e.relatedTarget;
    if(toEl && row.contains(toEl)) return;
    var acts = row.querySelector('.msg-actions');
    if(acts) acts.classList.remove('hover-visible');
});

document.getElementById('messagesBox').addEventListener('touchstart', function(e) {
    var row = e.target.closest('.msg-row');
    if(!row) {
        if(_lastTapEl) {
            var la = _lastTapEl.querySelector('.msg-actions');
            if(la) la.classList.remove('touch-visible');
            _lastTapEl = null;
        }
        clearTimeout(_longPressTimer);
        return;
    }
    if(_lastTapEl && _lastTapEl !== row) {
        var prevActs = _lastTapEl.querySelector('.msg-actions');
        if(prevActs) prevActs.classList.remove('touch-visible');
    }
    _touchStartX = e.touches[0].clientX;
    _touchStartY = e.touches[0].clientY;
    _longPressFired = false;
    _touchMoved = false;

    _longPressTimer = setTimeout(function() {
        if(_touchMoved) return;
        _longPressFired = true;
        _longPressTime = Date.now();
        var msgEl = row.querySelector('.msg');
        if(!msgEl) return;
        var id = parseInt(msgEl.dataset.msgId) || null;
        var isMe = row.classList.contains('me');
        var sender = msgEl.dataset.sender || '';
        if(!id) return;
        selectedMsgId = id;
        selectedMsgSender = sender;
        selectedMsgText = (msgCache[id] ? msgCache[id].text : '') || '';
        var hasText = !!(selectedMsgText && selectedMsgText.trim());
        hideCtx();
        var m = document.getElementById('msgCtxMenu');
        var rect = msgEl.getBoundingClientRect();
        var x = isMe ? rect.left : rect.right;
        if(isMe) { x = rect.left - 155; } else { x = rect.right + 5; }
        if(x + 160 > window.innerWidth) x = window.innerWidth - 165;
        if(x < 5) x = 5;
        var y = _touchStartY - 20;
        if(y + 220 > window.innerHeight) y = window.innerHeight - 225;
        if(y < 5) y = 5;
        m.style.left = x + 'px';
        m.style.top = y + 'px';
        m.style.display = 'block';
        var isTextMsg = !msgCache[id] || msgCache[id].type === 'text' || !msgCache[id].type;
        document.getElementById('ctxEditMsg').style.display = (isMe && isTextMsg) ? 'flex' : 'none';
        document.getElementById('ctxDelMsg').style.display = (isMe || isAdmin) ? 'flex' : 'none';
        var alreadyPinned = pinnedMessages.find(function(p) { return p.id == id; });
        document.getElementById('ctxPinMsg').style.display = (canPin && !alreadyPinned) ? 'flex' : 'none';
        document.getElementById('ctxUnpinMsg').style.display = (canPin && !!alreadyPinned) ? 'flex' : 'none';
    }, 600);
    _lastTapEl = row;
}, {passive: true});

document.getElementById('messagesBox').addEventListener('touchmove', function(e) {
    var dx = Math.abs(e.touches[0].clientX - _touchStartX);
    var dy = Math.abs(e.touches[0].clientY - _touchStartY);
    if(dx > 8 || dy > 8) {
        _touchMoved = true;
        if(_longPressTimer) {
            clearTimeout(_longPressTimer);
            _longPressTimer = null;
        }
    }
}, {passive: true});

document.getElementById('messagesBox').addEventListener('touchend', function(e) {
    clearTimeout(_longPressTimer);
    _longPressTimer = null;
    if(_longPressFired) {
        e.preventDefault();
        e.stopPropagation();
        _longPressFired = false;
        return;
    }
    if(_touchMoved) return;
    var row = e.target.closest('.msg-row');
    if(row) {
        if(e.target.closest('.reply-content') || e.target.closest('.media-container') || e.target.closest('.file-box') || e.target.closest('audio') || e.target.closest('a') || e.target.closest('.msg-act-btn')) return;
        var acts = row.querySelector('.msg-actions');
        if(acts) {
            acts.classList.add('touch-visible');
            _lastTapEl = row;
        }
    }
}, {passive: false});

document.addEventListener('touchstart', function(e) {
    if(_lastTapEl && !e.target.closest('.msg-row') && !e.target.closest('.msg-menu-overlay') && !e.target.closest('.msg-ctx-menu')) {
        var la = _lastTapEl.querySelector('.msg-actions');
        if(la) la.classList.remove('touch-visible');
        _lastTapEl = null;
    }
    if(!e.target.closest('.msg-ctx-menu') && !e.target.closest('.ctx-menu')) {
        hideCtx();
    }
}, {passive: true});
document.getElementById('lightboxOverlay').addEventListener('touchstart', function(e) {
    if(e.touches.length === 2) {
        _lightboxPinchStart = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
    }
}, {passive: true});

document.getElementById('lightboxOverlay').addEventListener('touchmove', function(e) {
    if(e.touches.length === 2) {
        e.preventDefault();
        const dist = Math.hypot(e.touches[0].clientX - e.touches[1].clientX, e.touches[0].clientY - e.touches[1].clientY);
        const img = document.getElementById('lightboxImg');
        const scale = Math.min(Math.max(dist / _lightboxPinchStart, 0.5), 4);
        img.style.transform = `scale(${scale})`;
    }
}, {passive: false});



function copyMsg(text) {
    if(!text) return;
    const decoded = (typeof text === 'string') ? text.replace(/\\n/g, '\n').replace(/&apos;/g, "'").replace(/&quot;/g, '"').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>') : String(text);
    if(navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(decoded).then(() => showToast('متن کپی شد ✓')).catch(() => fallbackCopy(decoded));
    } else {
        fallbackCopy(decoded);
    }
}

function fallbackCopy(text) {
    const el = document.createElement('textarea');
    el.value = text;
    el.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0;pointer-events:none;';
    document.body.appendChild(el);
    el.focus();
    el.select();
    try { document.execCommand('copy'); showToast('متن کپی شد ✓'); } catch(e) { showToast('کپی ممکن نشد'); }
    document.body.removeChild(el);
}

function copyNewSecurityCode() {
    const code = (document.getElementById('newSecurityCodeDisplay').textContent || '').trim();
    if (!code) return;
    copyMsg(code);
    showToast('کد با موفقیت کپی شد! در جای امن نگه‌دارید.');
    const btn = document.getElementById('scmCopyBtn');
    const lbl = document.getElementById('scmCopyLabel');
    if (!btn || !lbl) return;
    btn.classList.add('done');
    lbl.textContent = 'کپی شد ✓';
    clearTimeout(btn._doneTimer);
    btn._doneTimer = setTimeout(function () {
        btn.classList.remove('done');
        lbl.textContent = 'کپی کد';
    }, 2200);
}

function _onChatImgLoad(img) {
    var wrap = img.closest('.chat-img-wrap');
    if (wrap) {
        var ld = wrap.querySelector('.img-loading-state');
        var er = wrap.querySelector('.img-error-state');
        if (ld) ld.style.display = 'none';
        if (er) er.style.display = 'none';
        if (wrap.style.minHeight) wrap.style.minHeight = '';
    }
    var box = document.getElementById('messagesBox');
    if(!box) return;
    if(userScrolledUp) return;
    var distFromBottom = box.scrollHeight - box.scrollTop - box.clientHeight;
    if(distFromBottom < 80) {
        box.scrollTop = box.scrollHeight;
    }
}

function _onChatImgError(img) {
    img.style.display = 'none';
    var wrap = img.closest('.chat-img-wrap');
    if (!wrap) return;
    var ld = wrap.querySelector('.img-loading-state');
    if (ld) ld.style.display = 'none';
    var er = wrap.querySelector('.img-error-state');
    if (er) er.style.display = 'flex';
    var box = document.getElementById('messagesBox');
    if (box && !userScrolledUp) { var d0 = box.scrollHeight - box.scrollTop - box.clientHeight; if (d0 < 80) box.scrollTop = box.scrollHeight; }
}

function _retryChatImg(errEl) {
    var wrap = errEl.closest('.chat-img-wrap');
    if (!wrap) return;
    var img = wrap.querySelector('img.chat-media');
    if (!img) return;
    img.style.display = '';
    errEl.style.display = 'none';
    var ld = wrap.querySelector('.img-loading-state');
    if (ld) ld.style.display = 'flex';
    var src = img.getAttribute('src') || '';
    img.removeAttribute('src');
    setTimeout(function() { img.src = src + (src.indexOf('?') === -1 ? '?' : '&') + 'r=' + Date.now(); }, 50);
}

function _fmtVTime(s) {
    if (!isFinite(s) || s < 0) s = 0;
    var m = Math.floor(s / 60);
    var sec = Math.floor(s % 60);
    return m + ':' + (sec < 10 ? '0' : '') + sec;
}

function _vpToggle(audioEl, card) {
    if (audioEl.paused) {
        document.querySelectorAll('.vplayer audio, audio.vpglobal').forEach(function(a) { if (a && !a.paused && a !== audioEl) a.pause(); });
        var pr = audioEl.play();
        if (pr && pr.catch) pr.catch(function() {
            card.classList.add('verr');
            var d = card.querySelector('[data-vp-role="dur"]');
            if (d) d.textContent = 'خطا';
        });
    } else {
        audioEl.pause();
    }
}

(function() {
    var boxEl = document.getElementById('msgsContent');
    if (!boxEl) return;

    boxEl.addEventListener('click', function(e) {
        var role = e.target.closest && e.target.closest('[data-vp-role]');
        if (!role) return;
        var card = role.closest('.vplayer');
        if (!card) return;
        var audioEl = card.parentElement ? card.parentElement.querySelector('audio') : null;
        if (!audioEl) return;
        e.preventDefault();
        e.stopPropagation();
        if (role.dataset.vpRole === 'toggle') {
            _vpToggle(audioEl, card);
        } else if (role.dataset.vpRole === 'bar') {
            var dur = audioEl.duration;
            if (!isFinite(dur)) return;
            var rect = role.getBoundingClientRect();
            var frac = (rect.right - e.clientX) / rect.width;
            if (document.dir === 'rtl' || document.documentElement.dir === 'rtl' || getComputedStyle(document.documentElement).direction === 'rtl') {
            } else {
                frac = (e.clientX - rect.left) / rect.width;
            }
            if (frac < 0) frac = 0; if (frac > 1) frac = 1;
            audioEl.currentTime = frac * dur;
        }
    });

    var barDragging = null;
    boxEl.addEventListener('pointerdown', function(e) {
        var bar = e.target.closest && e.target.closest('.vbar');
        if (!bar) return;
        var card = bar.closest('.vplayer');
        var audioEl = card && card.parentElement ? card.parentElement.querySelector('audio') : null;
        if (!audioEl || !isFinite(audioEl.duration)) return;
        barDragging = { bar: bar, audio: audioEl, card: card };
        bar.setPointerCapture && bar.setPointerCapture(e.pointerId);
    });
    boxEl.addEventListener('pointermove', function(e) {
        if (!barDragging) return;
        var rect = barDragging.bar.getBoundingClientRect();
        var frac = (e.clientX - rect.left) / rect.width;
        if (frac < 0) frac = 0; if (frac > 1) frac = 1;
        barDragging.audio.currentTime = frac * barDragging.audio.duration;
        var fill = barDragging.bar.querySelector('.vbar-fill');
        var knob = barDragging.bar.querySelector('.vbar-knob');
        var pct = (frac * 100) + '%';
        if (fill) fill.style.width = pct;
        if (knob) knob.style.insetInlineStart = pct;
    });
    boxEl.addEventListener('pointerup', function() { barDragging = null; });
    boxEl.addEventListener('pointercancel', function() { barDragging = null; });

    boxEl.addEventListener('play', function(e) {
        var t = e.target;
        if (!(t instanceof HTMLAudioElement)) return;
        var card = t.parentElement && t.parentElement.querySelector ? t.parentElement.querySelector('.vplayer') : null;
        if (!card) return;
        var btn = card.querySelector('[data-vp-role="toggle"]');
        if (btn) card.classList.add('is-playing');
    }, true);
    boxEl.addEventListener('pause', function(e) {
        var t = e.target;
        if (!(t instanceof HTMLAudioElement)) return;
        var card = t.parentElement && t.parentElement.querySelector ? t.parentElement.querySelector('.vplayer') : null;
        if (!card) return;
        var btn = card.querySelector('[data-vp-role="toggle"]');
        if (btn) card.classList.remove('is-playing');
    }, true);
    boxEl.addEventListener('timeupdate', function(e) {
        var t = e.target;
        if (!(t instanceof HTMLAudioElement)) return;
        var card = t.parentElement && t.parentElement.querySelector ? t.parentElement.querySelector('.vplayer') : null;
        if (!card) return;
        var cur = card.querySelector('[data-vp-role="cur"]');
        var fill = card.querySelector('.vbar-fill');
        var knob = card.querySelector('.vbar-knob');
        var dur = t.duration;
        var pct = isFinite(dur) && dur > 0 ? (t.currentTime / dur) * 100 : 0;
        if (cur) cur.textContent = _fmtVTime(t.currentTime);
        if (fill) fill.style.width = pct + '%';
        if (knob) knob.style.insetInlineStart = pct + '%';
    }, true);
    boxEl.addEventListener('loadedmetadata', function(e) {
        var t = e.target;
        if (!(t instanceof HTMLAudioElement)) return;
        var card = t.parentElement && t.parentElement.querySelector ? t.parentElement.querySelector('.vplayer') : null;
        if (!card) return;
        var dur = card.querySelector('[data-vp-role="dur"]');
        if (dur && isFinite(t.duration)) dur.textContent = _fmtVTime(t.duration);
    }, true);
    boxEl.addEventListener('waiting', function(e) {
        var t = e.target;
        if (!(t instanceof HTMLAudioElement)) return;
        var card = t.parentElement && t.parentElement.querySelector ? t.parentElement.querySelector('.vplayer') : null;
        if (!card) return;
        var btn = card.querySelector('[data-vp-role="toggle"]');
        if (btn) { btn.innerHTML = '<span class="vloading"></span>'; }
    }, true);
    boxEl.addEventListener('playing', function(e) {
        var t = e.target;
        if (!(t instanceof HTMLAudioElement)) return;
        var card = t.parentElement && t.parentElement.querySelector ? t.parentElement.querySelector('.vplayer') : null;
        if (!card) return;
        var btn = card.querySelector('[data-vp-role="toggle"]');
        if (btn) card.classList.add('is-playing');
    }, true);
    boxEl.addEventListener('ended', function(e) {
        var t = e.target;
        if (!(t instanceof HTMLAudioElement)) return;
        var card = t.parentElement && t.parentElement.querySelector ? t.parentElement.querySelector('.vplayer') : null;
        if (!card) return;
        var btn = card.querySelector('[data-vp-role="toggle"]');
        if (btn) card.classList.remove('is-playing');
        var fill = card.querySelector('.vbar-fill');
        var knob = card.querySelector('.vbar-knob');
        if (fill) fill.style.width = '0%';
        if (knob) knob.style.insetInlineStart = '0%';
    }, true);
    boxEl.addEventListener('error', function(e) {
        var t = e.target;
        if (!(t instanceof HTMLAudioElement)) return;
        var card = t.parentElement && t.parentElement.querySelector ? t.parentElement.querySelector('.vplayer') : null;
        if (!card) return;
        card.classList.add('verr');
        var dur = card.querySelector('[data-vp-role="dur"]');
        if (dur) dur.textContent = 'خطا';
        var btn = card.querySelector('[data-vp-role="toggle"]');
        if (btn) card.classList.remove('is-playing');
    }, true);
})();

let _searchDebounceTimer = null;
let _searchActive = false;
let _searchResultsData = [];
let _srpCurrentMsgId = null;

function toggleSearchPanel() {
    var panel = document.getElementById('searchPanel');
    if(_searchActive) {
        closeSearchPanel();
    } else {
        _searchActive = true;
        panel.classList.add('active');
        setTimeout(function() {
            document.getElementById('searchField').focus();
        }, 80);
    }
}

function closeSearchPanel() {
    _searchActive = false;
    _searchResultsData = [];
    document.getElementById('searchPanel').classList.remove('active');
    document.getElementById('searchResultsPanel').classList.remove('active');
    document.getElementById('searchResultsPanel').innerHTML = '';
    document.getElementById('searchField').value = '';
    clearTimeout(_searchDebounceTimer);
}

function clearSearch() {
    document.getElementById('searchField').value = '';
    document.getElementById('searchResultsPanel').classList.remove('active');
    document.getElementById('searchResultsPanel').innerHTML = '';
}

function onSearchInput(val) {
    clearTimeout(_searchDebounceTimer);
    var q = val.trim();
    if(q.length < 2) {
        document.getElementById('searchResultsPanel').classList.remove('active');
        document.getElementById('searchResultsPanel').innerHTML = '';
        return;
    }
    _searchDebounceTimer = setTimeout(function() {
        doSearch(q);
    }, 350);
}

function doSearch(q) {
    var panel = document.getElementById('searchResultsPanel');
    panel.innerHTML = '<div class="search-loading">در حال جستجو...</div>';
    panel.classList.add('active');
    _searchResultsData = [];

    var fd = new FormData();
    fd.append('action', 'search_messages');
    fd.append('csrf_token', csrfToken);
    if(bwNonce) fd.append('bw_nonce', bwNonce);
    fd.append('query', q);
    fd.append('room', currentRoom || 'null');
    fd.append('target_user', targetUser || 0);

    fetch('chat.php', {method:'POST', body:fd})
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if(d.status !== 'success' || !d.results || d.results.length === 0) {
            panel.innerHTML = '<div class="search-empty">نتیجه‌ای یافت نشد</div>';
            _searchResultsData = [];
            return;
        }
        _searchResultsData = d.results;
        var html = '';
        d.results.forEach(function(item) {
            var highlighted = item.message.replace(/</g,'&lt;').replace(/>/g,'&gt;')
                .replace(new RegExp('(' + q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')', 'gi'), '<mark>$1</mark>');
            var timeStr = item.created_at ? item.created_at.substring(11,16) + ' ' + item.created_at.substring(0,10) : '';
            html += '<div class="search-result-item" onclick="goToSearchResult(' + item.id + ',\'' + q.replace(/'/g,"\\'") + '\')">'
                + '<div class="search-result-sender">' + item.sender_name.replace(/</g,'&lt;') + '</div>'
                + '<div class="search-result-text">' + highlighted + '</div>'
                + '<div class="search-result-time">' + timeStr + '</div>'
                + '</div>';
        });
        panel.innerHTML = html;
    })
    .catch(function() {
        panel.innerHTML = '<div class="search-empty">خطا در جستجو</div>';
    });
}

function goToSearchResult(msgId, query) {
    // Show popup with the message content - do NOT navigate in chat
    var item = _searchResultsData.find(function(x) { return x.id == msgId; });
    if(!item) {
        // fallback: try to navigate directly
        _doNavigateToMsg(msgId);
        return;
    }
    _srpCurrentMsgId = msgId;
    showSearchResultPopup(item, query || '');
}

function showSearchResultPopup(item, query) {
    var overlay = document.getElementById('srpOverlay');
    var senderEl = document.getElementById('srpSender');
    var timeEl = document.getElementById('srpTime');
    var bodyEl = document.getElementById('srpBody');

    senderEl.textContent = item.sender_name || '';
    var timeStr = item.created_at ? item.created_at.substring(11,16) + '  •  ' + item.created_at.substring(0,10) : '';
    timeEl.textContent = timeStr;

    // Show loading briefly for polish, then render
    bodyEl.innerHTML = '<div class="srp-loading"><div class="srp-spinner"></div><div class="srp-spinner-text">در حال بارگذاری پیام...</div></div>';
    overlay.classList.add('active');
    document.addEventListener('keydown', _srpEscHandler);

    setTimeout(function() {
        var msgText = (item.message || '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        if(query) {
            msgText = msgText.replace(new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')', 'gi'), '<mark>$1</mark>');
        }
        // Replace newlines with <br>
        msgText = msgText.replace(/\n/g, '<br>');
        bodyEl.innerHTML = '<div class="srp-label">متن پیام</div><div class="srp-message-box">' + msgText + '</div>';
    }, 420);
}

function _srpEscHandler(e) {
    if(e.key === 'Escape') closeSearchResultPopup();
}

function closeSearchResultPopup() {
    var overlay = document.getElementById('srpOverlay');
    overlay.classList.remove('active');
    document.removeEventListener('keydown', _srpEscHandler);
    setTimeout(function() {
        document.getElementById('srpBody').innerHTML = '<div class="srp-loading"><div class="srp-spinner"></div><div class="srp-spinner-text">در حال بارگذاری...</div></div>';
    }, 350);
}

function goToMsgFromPopup() {
    closeSearchResultPopup();
    if(!_srpCurrentMsgId) return;
    var id = _srpCurrentMsgId;
    _srpCurrentMsgId = null;
    // Small delay to let popup close smoothly
    setTimeout(function() {
        _doNavigateToMsg(id);
    }, 200);
}

function _doNavigateToMsg(msgId) {
    closeSearchPanel();
    var existing = document.getElementById('msg-' + msgId);
    if(existing) {
        userScrolledUp = true;
        _programScrollUntil = Date.now() + 3000;
        _doScrollToEl(existing, msgId);
        return;
    }
    _pendingScrollToMsgId = msgId;
    userScrolledUp = true;
    _programScrollUntil = Date.now() + 30000;
    var histOverlay = document.getElementById('historyOverlay');
    if(histOverlay) histOverlay.classList.add('active');
    _loadMoreUntilMsgCount = 0;
    loadMoreUntilMsg(msgId);
}

var _loadMoreUntilMsgCount = 0;

function loadMoreUntilMsg(targetId) {
    if(!oldestMsgId) {
        _hideSearchLoadOverlay();
        _pendingScrollToMsgId = null;
        return;
    }
    _loadMoreUntilMsgCount = (_loadMoreUntilMsgCount || 0) + 1;
    if(_loadMoreUntilMsgCount > 30) {
        _hideSearchLoadOverlay();
        _pendingScrollToMsgId = null;
        showToast('پیام پیدا نشد');
        return;
    }
    var fd = new FormData();
    fd.append('action', 'fetch_history');
    fd.append('csrf_token', csrfToken);
    if(bwNonce) fd.append('bw_nonce', bwNonce);
    fd.append('before_id', oldestMsgId);
    if(targetUser) { fd.append('target_user', targetUser); }
    else { fd.append('room', currentRoom || 'گفتگوی عمومی'); }

    fetch('chat.php', {method:'POST', body:fd})
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if(!d.messages || d.messages.length === 0) {
            _hideSearchLoadOverlay();
            _pendingScrollToMsgId = null;
            showToast('پیام پیدا نشد');
            return;
        }
        var html = '';
        d.messages.forEach(function(m) {
            msgCache[m.id] = {name:m.sender_name, text:m.msg_type==='text'?(m.message||''):'', type:m.msg_type||'text', file_name:m.file_name||null, isMe:(m.sender_id==currentUser)};
            if(!oldestMsgId || m.id < oldestMsgId) oldestMsgId = m.id;
            html += renderMessage(m);
        });
        loadedHistoryHtml = html + loadedHistoryHtml;
        var contentBox = document.getElementById('msgsContent');
        var box = document.getElementById('messagesBox');
        var oldH = box.scrollHeight;
        var oldT = box.scrollTop;
        box.style.scrollBehavior = 'auto';
        contentBox.insertAdjacentHTML('afterbegin', html);
        void box.offsetHeight;
        box.scrollTop = Math.max(0, oldT + (box.scrollHeight - oldH));

        var found = document.getElementById('msg-' + targetId);
        if(found) {
            _pendingScrollToMsgId = null;
            _loadMoreUntilMsgCount = 0;
            userScrolledUp = true;
            _hideSearchLoadOverlay();
            _programScrollUntil = Date.now() + 4000;
            setTimeout(function() {
                box.style.scrollBehavior = 'smooth';
                _doScrollToEl(found, targetId);
            }, 80);
        } else if(d.messages.length >= 20) {
            loadMoreUntilMsg(targetId);
        } else {
            _hideSearchLoadOverlay();
            _loadMoreUntilMsgCount = 0;
            _pendingScrollToMsgId = null;
            showToast('پیام پیدا نشد');
        }
    })
    .catch(function() {
        _hideSearchLoadOverlay();
        _loadMoreUntilMsgCount = 0;
        _pendingScrollToMsgId = null;
        showToast('خطا در بارگذاری تاریخچه');
    });
}

function _hideSearchLoadOverlay() {
    var histOverlay = document.getElementById('historyOverlay');
    if(histOverlay) histOverlay.classList.remove('active');
    _programScrollUntil = Date.now() + 500;
}

let pendingMessages = [];

const MSG_QUEUE_DELAY = 0;
let _msgSendQueues = {};
// One in-flight send per conversation: a busy room never delays another one.
let _msgSendInFlight = {};
let _aiWatched = new Set();

function _buildActions(msgId, isMe, hasText, isMedia) {
    const replyBtn = `<button class="msg-act-btn" title="پاسخ" onclick="event.stopPropagation();setReplyFromMsg(${msgId})"><img src="assets/icons3d/reply.png" alt=""></button>`;
    const copyBtn = hasText ? `<button class="msg-act-btn" title="کپی" onclick="event.stopPropagation();copyMsgById(${msgId})"><img src="assets/icons3d/clipboard.png" alt=""></button>` : '';
    const editBtn = (isMe && !isMedia) ? `<button class="msg-act-btn" title="ویرایش" onclick="event.stopPropagation();editMsgById(${msgId})"><img src="assets/icons3d/pencil.png" alt=""></button>` : '';
    const delBtn = (canDelete && isMe) ? `<button class="msg-act-btn del-btn" title="حذف" onclick="event.stopPropagation();delMsg(${msgId})"><img src="assets/icons3d/trash.png" alt=""></button>` : '';
    return replyBtn + copyBtn + editBtn + delBtn;
}

function _finalizeActions(el, msgId, isMe, hasText, isMedia) {
    if (!el) return;
    const row = el.closest('.msg-row') || el;
    const acts = row.querySelector('.msg-actions');
    if (!acts) return;
    acts.classList.remove('msg-actions-ghost');
    acts.id = 'acts-' + msgId;
    acts.innerHTML = _buildActions(msgId, isMe, hasText, isMedia);
}

function _addSentTick(el) {
    if (!el) return;
    const chip = el.querySelector('.msg-time-chip');
    if (!chip) return;
    const existing = chip.querySelector('.msg-tick');
    if (existing) {
        // The tick was already drawn while sending — just recolour it. No node
        // is inserted, so the chip keeps its width and nothing moves.
        existing.setAttribute('title', 'ارسال شد');
        existing.querySelectorAll('path').forEach(function (p) { p.setAttribute('stroke', '#93c5fd'); });
        return;
    }
    chip.insertAdjacentHTML('beforeend', '<span class="msg-tick" title="ارسال شد" style="display:inline-flex;align-items:center;margin-right:3px;opacity:0.85;"><svg width="18" height="10" viewBox="0 0 18 10" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 5L4 8L10 2" stroke="#93c5fd" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 5L9 8L15 2" stroke="#93c5fd" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>');
}

/**
 * Turns a pending bubble into a real one without changing its geometry.
 * Everything the confirmed message needs is already reserved on screen: the
 * reaction row exists as an invisible ghost, the tick is drawn grey, and the
 * sticker is the user's own from the first frame. This function only fills in
 * the parts that need a server id.
 */
function _settleBubble(el, msgId) {
    if (!el || !msgId) return;
    const ghost = el.querySelector('.rx-ghost');
    if (ghost) {
        const real = document.createElement('div');
        real.innerHTML = renderReactions({ id: msgId, reactions: {}, my_reaction: null });
        const node = real.firstElementChild;
        if (node) ghost.parentNode.replaceChild(node, ghost);
        else ghost.classList.remove('rx-ghost');
    }
    const st = el.querySelector('.msg-sticker');
    if (st && MY_STICKER && st.textContent !== MY_STICKER) st.textContent = MY_STICKER;
}

function _nowStamp() {
    const d = new Date();
    const p = function (n) { return String(n).padStart(2, '0'); };
    return '0000-00-00 ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':00';
}

function _processMsgSendQueue(key) {
    const k = key || _msgSendQueueKey();
    const q = _msgSendQueues[k] || [];
    if (_msgSendInFlight[k] || q.length === 0) return;
    _msgSendInFlight[k] = true;
    const job = q.shift();
    job(() => {
        _msgSendInFlight[k] = false;
        // Send the next queued message right away; when the queue is empty the
        // next message starts immediately on click (no artificial delay).
        if ((_msgSendQueues[k] || []).length > 0) _processMsgSendQueue(k);
    });
}
function _msgSendQueueKey() {
    return targetUser ? 'dm:' + targetUser : 'room:' + (currentRoom || 'گفتگوی عمومی');
}
function _msgQueuePush(job) {
    const k = _msgSendQueueKey();
    if (!_msgSendQueues[k]) _msgSendQueues[k] = [];
    _msgSendQueues[k].push(job);
    const cur = _msgSendQueueKey();
    if (cur === k) _processMsgSendQueue();
}
function _msgQueueNext() {
    _processMsgSendQueue();
}

// Sends normally settle in well under 100 ms, so the shimmer is delayed: it
// only appears when something is genuinely slow, which is exactly when the user
// needs to know the request is still alive.
let _sendsInFlight = 0;
let _sendSlowTimer = null;
function _sendFlightStart() {
    _sendsInFlight++;
    if (_sendsInFlight !== 1) return;
    if (_sendSlowTimer) clearTimeout(_sendSlowTimer);
    _sendSlowTimer = setTimeout(function () {
        const p = document.getElementById('sendProgress');
        if (p && _sendsInFlight > 0) p.classList.add('on');
    }, 400);
}
function _sendFlightEnd() {
    _sendsInFlight = Math.max(0, _sendsInFlight - 1);
    if (_sendsInFlight > 0) return;
    if (_sendSlowTimer) { clearTimeout(_sendSlowTimer); _sendSlowTimer = null; }
    const p = document.getElementById('sendProgress');
    if (p) p.classList.remove('on');
}

function _queueSendFetch(fd, tempId, currentMsg, onDone, attempt) {
    attempt = attempt || 0;
    _msgQueuePush((done) => {
        // A hung request used to keep the whole room's queue blocked until the
        // browser gave up (minutes). The controller frees the queue after 15 s
        // and marks the bubble as failed so it can be retried.
        const ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        const guard = ctrl ? setTimeout(function () { try { ctrl.abort(); } catch (e) {} }, 15000) : null;
        const sBtn = document.getElementById('sendBtn');
        if (sBtn) sBtn.classList.add('is-sending');
        _sendFlightStart();
        const finish = function () {
            if (guard) clearTimeout(guard);
            if (sBtn) sBtn.classList.remove('is-sending');
            _sendFlightEnd();
            done();
        };

        fetch('chat.php', { method: 'POST', body: fd, signal: ctrl ? ctrl.signal : undefined })
            .then(r => r.json())
            .then(d => {
                if (d.status === 'success') {
                    if (typeof vxDemoTick === 'function') vxDemoTick();
                    const el = document.getElementById(`msg-${tempId}`);
                    const row = document.getElementById(`row-${tempId}`);
                    // The poll can win the race: it sees the row the moment the
                    // server inserts it, while this response is still travelling
                    // back. Renaming the optimistic bubble on top of the copy
                    // the poll already painted left two elements with the same
                    // id, so the message appeared twice. If the real bubble is
                    // already here, drop the optimistic one instead.
                    const already = d.new_msg_id ? document.getElementById(`msg-${d.new_msg_id}`) : null;
                    if (already && already !== el) {
                        if (row && row.parentNode) row.remove();
                        else if (el && el.parentNode) el.remove();
                        _addSentTick(already);
                        _settleBubble(already, d.new_msg_id);
                        msgCache[d.new_msg_id] = { name: 'شما', text: currentMsg || '', type: 'text' };
                    } else if (el) {
                        el.id = `msg-${d.new_msg_id}`;
                        el.dataset.msgId = d.new_msg_id;
                        el.classList.remove('pending', 'error');
                        _addSentTick(el);
                        _settleBubble(el, d.new_msg_id);
                        _finalizeActions(el, d.new_msg_id, true, !!currentMsg, false);
                        msgCache[d.new_msg_id] = { name: 'شما', text: currentMsg || '', type: 'text' };
                    }
                    if (row && row.parentNode) row.id = `row-${d.new_msg_id}`;
                    const acts = document.getElementById(`acts-${tempId}`);
                    if (acts) acts.id = `acts-${d.new_msg_id}`;
                    if (lastMsgId < d.new_msg_id) lastMsgId = d.new_msg_id;
                    _pinIfAtBottom();
                    if (d.ai_pending) {
                        _aiWatched.add(String(d.new_msg_id));
                        _insertAiPlaceholder(d.new_msg_id);
                    } else if (d.ai_limited) {
                        const mins = Math.max(1, Math.ceil((d.ai_retry_after || 60) / 60));
                        showToast(`سقف استفاده از دستیار پر شده؛ حدود ${mins} دقیقه دیگر دوباره تلاش کنید`, 'error');
                    }
                    pendingMessages = pendingMessages.filter(p => p.tempId !== tempId);
                    _netOk();
                } else if (d.status === 'rate_limited' && attempt < 2) {
                    // The bubble stays "pending": the server asked us to wait,
                    // so we wait and retry instead of flashing an error at the
                    // user for something that is not their fault.
                    const wait = Math.max(1, Math.min(15, d.retry_after || 2)) * 1000;
                    finish();
                    setTimeout(function () {
                        _queueSendFetch(fd, tempId, currentMsg, onDone, attempt + 1);
                    }, wait);
                    return;
                } else {
                    markAsError(tempId);
                    if (d.demo && d.limit) vxDemoExhausted();
                    showToast(d.message);
                }
                finish();
                if (onDone) onDone(d);
            })
            .catch(() => {
                markAsError(tempId);
                showToast('خطای شبکه');
                finish();
            });
    });
}

function _insertAiPlaceholder(triggerId) {
    const contentBox = document.getElementById('msgsContent');
    if (!contentBox) return;
    if (document.getElementById(`ai-wait-${triggerId}`)) return;
    // The answer may already be on screen (fast model, duplicate-retry settle,
    // or the reply landed between the send and this call). In that case the
    // "typing…" row would be a zombie that no event would ever clear.
    for (const k in msgCache) {
        const cm = msgCache[k];
        if (cm && cm.reply_to_id && String(cm.reply_to_id) === String(triggerId)) {
            _aiWatched.delete(String(triggerId));
            return;
        }
    }
    const div = document.createElement('div');
    div.className = 'msg-row ai-wait-row';
    div.id = `ai-wait-${triggerId}`;
    div.innerHTML = '<div class="ai-typing" style="display:flex;align-items:center;gap:8px;padding:8px 4px;color:#94a3b8;font-size:13px;"><span class="ai-dot" style="width:8px;height:8px;border-radius:50%;background:var(--v-primary,#6366f1);display:inline-block;animation:vAIPulse 1s infinite;"></span>دستیار در حال پاسخ‌گویی…</div>';
    contentBox.appendChild(div);
    const box = document.getElementById('messagesBox');
    if (box && !userScrolledUp) box.scrollTop = box.scrollHeight;
    setTimeout(() => { const t = document.getElementById(`ai-wait-${triggerId}`); if (t) t.remove(); _aiWatched.delete(String(triggerId)); }, 45000);
}

/**
 * A full re-render (room switch, typing-indicator key change, force reload)
 * rebuilds msgsContent and used to swallow the "assistant is typing" row —
 * it vanished seconds after sending even though the answer was still on its
 * way. Call after any innerHTML rebuild to re-attach live placeholders.
 * Watched ids whose answer has already landed (msgCache holds the reply) are
 * un-watched instead of resurrecting a zombie "typing…" row.
 */
function _restoreAiPlaceholders(contentBox) {
    const box = contentBox || document.getElementById('msgsContent');
    if (!box || !_aiWatched.size) return;
    const answered = (id) => {
        for (const k in msgCache) {
            const m = msgCache[k];
            if (m && m.reply_to_id && String(m.reply_to_id) === String(id)) return true;
        }
        return false;
    };
    _aiWatched.forEach(id => {
        if (answered(id)) { _aiWatched.delete(id); const gone = document.getElementById(`ai-wait-${id}`); if (gone) gone.remove(); return; }
        const el = document.getElementById(`ai-wait-${id}`);
        if (el && el.parentNode === box) return; // already there, keep position
        if (el) { box.appendChild(el); return; }
        _insertAiPlaceholder(id);
    });
}

// Server says this trigger produced no reply (cooldown / assistant disabled):
// clear its typing row immediately instead of waiting for the timeout.
function _aiSkipEvent(data) {
    const id = data && data.trigger_id;
    if (!id) return;
    _removeAiPlaceholder(String(id));
}

function _removeAiPlaceholder(triggerId) {
    const t = document.getElementById(`ai-wait-${triggerId}`);
    if (t) t.remove();
    _aiWatched.delete(String(triggerId));
}

// ── Demo counter ────────────────────────────────────────────────────────────
// Purely cosmetic: the real ceiling is enforced in chat.php. This only keeps
// the strip honest so nobody types a message that is going to be refused.
function _vxDemoSetLeft(n) {
    const badge = document.getElementById('demoLeftBadge');
    if (!badge) return;
    if (n < 0) n = 0;
    badge.textContent = n + ' پیام باقی‌مانده';
    badge.classList.toggle('is-low', n <= 3);
}
function vxDemoTick() {
    const badge = document.getElementById('demoLeftBadge');
    if (!badge) return;
    const m = String(badge.textContent).match(/\d+/);
    if (m) _vxDemoSetLeft(parseInt(m[0], 10) - 1);
}
function vxDemoExhausted() {
    _vxDemoSetLeft(0);
    const input = document.getElementById('msgInput');
    const send = document.getElementById('sendBtn');
    if (input) { input.disabled = true; input.placeholder = 'سقف پیام‌های آزمایشی پر شد'; }
    if (send) send.disabled = true;
}

// 22-char idempotency key: URL-safe, matches the server's
// /^[A-Za-z0-9_-]{8,64}$/ contract. crypto.randomUUID needs a secure context;
// the time+random fallback keeps http hosts working.
function _genClientMsgId() {
    try {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID().replace(/-/g, '').slice(0, 22);
        }
    } catch (e) {}
    return (Date.now().toString(36) + Math.random().toString(36).slice(2, 12) + Math.random().toString(36).slice(2, 10)).slice(0, 22);
}

function sendMessage(blob, isV, retryId = null){
    // A blocked private thread never reaches the network: the banner already
    // explains why, and the server enforces the same rule independently.
    if (targetUser && typeof _blockState !== 'undefined' && _blockState !== 'none') {
        showToast(_blockState === 'by_them' ? 'این کاربر شما را مسدود کرده است' : 'ابتدا رفع بلاک کنید', 'error');
        return;
    }
    const txt = document.getElementById('msgInput');
    const file = document.getElementById('fileInput');
    
    if (!blob && file.files.length > 0 && !retryId) {
        const fileList = Array.from(file.files);
        fileList.forEach(f => {
            if (f.size > MAX_FILE_SIZE) {
                showToast(`حجم فایل ${f.name} بیشتر از ۵ مگابایت است`);
                return;
            }
            uploadQueue.push(f);
        });
        
        processUploadQueue();
        document.getElementById('fileInput').value = '';
        cancelFile();
        return;
    }

    const msgVal = txt.value.trim();
    // reply_* become `let`: a retry must be able to restore them from the
    // stored pending entry, because cancelReply() already cleared the live state.
    let currentReplyId = replyToId;
    let currentReplyName = replyName;
    let currentReplyText = replyContent;

    let currentBlob = blob;
    let currentFile = file.files.length ? file.files[0] : null;
    // Sent raw. Escaping belongs to the render path (formatText → escapeHTMLDisplay);
    // escaping here stored "a &lt; b" in the database, and the reader then saw
    // the entity itself — every message containing < > or & came out garbled.
    let currentMsg = msgVal || '';

    if (retryId) {
        const pObj = pendingMessages.find(p => p.tempId === retryId);
        if(pObj) {
            currentBlob = pObj.blob;
            currentFile = pObj.file;
            currentMsg = pObj.msg;
            // Preserve the original reply linkage (e.g. a reply to the
            // assistant) — retry used to drop it and the AI never answered.
            if (pObj.reply_to_id) {
                currentReplyId = pObj.reply_to_id;
                currentReplyName = pObj.reply_sender;
                currentReplyText = pObj.reply_text;
            }
        }
    } else {
        if(!blob && !file.files.length && !currentMsg) return;
    }

    // Idempotency key: generated once per logical message and reused by every
    // retry, so a send whose response was lost in a network drop is stored
    // exactly once. The server refuses a second insert with the same key.
    const _retryPending = retryId ? pendingMessages.find(p => p.tempId === retryId) : null;
    const clientMsgId = (_retryPending && _retryPending.clientMsgId) ? _retryPending.clientMsgId : _genClientMsgId();

    const tempId = retryId || Date.now();
    const tempMsgObj = {
        id: null, tempId: tempId, sender_id: currentUser, sender_name: 'شما', sender_sticker: MY_STICKER, created_at: _nowStamp(),
        message: currentMsg, msg_type: isV ? 'voice' : (currentFile ? 'file' : 'text'),
        file_name: currentFile ? currentFile.name : (isV ? 'voice.webm' : null),
        blob: currentBlob || currentFile,
        reply_to_id: currentReplyId, 
        reply_sender: currentReplyName || '...',
        reply_message: currentReplyId ? currentReplyText : null
    };

    if (!retryId) {
        pendingMessages.push({ tempId, blob: currentBlob, file: currentFile, msg: currentMsg, isV, reply_to_id: currentReplyId, reply_sender: currentReplyName, reply_text: currentReplyText, clientMsgId });
        const contentBox = document.getElementById('msgsContent');
        const box = document.getElementById('messagesBox');
        
        contentBox.insertAdjacentHTML('beforeend', renderMessage(tempMsgObj, true));
        
        // اسکرول دقیق به پایین - بدون jump
        userScrolledUp = false;
        _programScrollUntil = Date.now() + 2000;
        box.style.scrollBehavior = 'auto';
        box.scrollTop = box.scrollHeight;
        requestAnimationFrame(function() {
            box.scrollTop = box.scrollHeight;
            box.style.scrollBehavior = 'smooth';
        });
        
        cancelReply(); cancelFile(); txt.value='';
        txt.style.height = '';   // back to the CSS height (36px) — no leftover growth
        _syncSendState();
        const ep = document.getElementById('emojiPicker');
        if(ep) ep.style.display = 'none';
    } else {
        const el = document.getElementById(`msg-${tempId}`);
        if(el) { el.classList.remove('error'); el.querySelector('.retry-btn')?.remove(); }
    }
    
    const fd = new FormData(); 
    fd.append('action','send'); 
    fd.append('csrf_token', csrfToken); 
    if(bwNonce) fd.append('bw_nonce', bwNonce);
    
    if(targetUser) {
        fd.append('receiver_id', targetUser);
        fd.append('room', 'null');
    } else {
        fd.append('room', currentRoom || 'گفتگوی عمومی');
        if(currentRoomId) fd.append('room_id', currentRoomId);
    }

    if(currentReplyId) fd.append('reply_to', currentReplyId);
    fd.append('client_msg_id', clientMsgId);

    // Voice notes and attachments follow the same rule as videos: the bytes
    // finish uploading first, then the message is published. Posting them in
    // one multipart request hit `upload_max_filesize` (2 MB) and failed with
    // no visible reason.
    if (currentBlob || currentFile) {
        _sendMediaStaged(currentBlob || currentFile, !!currentBlob, tempId, currentMsg, currentReplyId, clientMsgId);
        return;
    }

    if (currentMsg) fd.append('message', currentMsg);
    _queueSendFetch(fd, tempId, currentMsg, null);
}

let _uploadXhr = null;
let _uploadCancelled = false;

function _fmtSize(b) {
    if (!b && b !== 0) return '';
    const u = ['B', 'KB', 'MB', 'GB'];
    let i = 0, n = b;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (n >= 10 || i === 0 ? Math.round(n) : n.toFixed(1)) + ' ' + u[i];
}

function _fileIcon(f) {
    const t = (f && f.type) || '';
    if (t.indexOf('image/') === 0) return '🖼';
    if (t.indexOf('audio/') === 0) return '🎙';
    if (t.indexOf('video/') === 0) return '🎬';
    return '📎';
}

function _thumbHtml(f) {
    if (f && f.type && f.type.indexOf('image/') === 0) {
        try { return '<img src="' + URL.createObjectURL(f) + '" alt="">'; } catch (e) {}
    }
    return _fileIcon(f);
}

function showUploadProgress(name, pct, file) {
    const box = document.getElementById('uploadProgressBox');
    document.getElementById('uploadProgName').textContent = name;
    document.getElementById('uploadProgPct').textContent = Math.round(pct) + '%';
    document.getElementById('uploadProgBar').style.width = Math.max(0, Math.min(100, pct)) + '%';
    const thumb = document.getElementById('upThumb');
    if (file && !thumb.getAttribute('data-filled')) {
        thumb.innerHTML = _thumbHtml(file);
        thumb.setAttribute('data-filled', '1');
    }
    const q = document.getElementById('uploadProgQueue');
    const left = (typeof uploadQueue !== 'undefined') ? uploadQueue.length : 0;
    q.textContent = left > 0 ? (left + ' فایل دیگر در صف') : '';
    box.classList.add('show');
}

function hideUploadProgress() {
    const box = document.getElementById('uploadProgressBox');
    if (!box) return;
    box.classList.remove('show');
    const thumb = document.getElementById('upThumb');
    if (thumb) { thumb.innerHTML = '📎'; thumb.removeAttribute('data-filled'); }
    const bar = document.getElementById('uploadProgBar');
    if (bar) bar.style.width = '0%';
}

function cancelUpload() {
    _uploadCancelled = true;
    try { uploadQueue.length = 0; } catch (e) {}
    if (_uploadXhr) { try { _uploadXhr.abort(); } catch (e) {} }
    hideUploadProgress();
    showToast('آپلود لغو شد');
}

let _voiceTimer = null;
function _voiceProgressStart(name) {
    _voiceProgressStop();
    let pct = 0;
    showUploadProgress(name || 'پیام صوتی', 0, null);
    const thumb = document.getElementById('upThumb');
    if (thumb) thumb.innerHTML = '🎙';
    _voiceTimer = setInterval(function () {
        pct = Math.min(90, pct + Math.random() * 12 + 3);
        document.getElementById('uploadProgPct').textContent = Math.round(pct) + '%';
        document.getElementById('uploadProgBar').style.width = pct + '%';
    }, 220);
}
function _voiceProgressFinish() {
    if (_voiceTimer) { clearInterval(_voiceTimer); _voiceTimer = null; }
    document.getElementById('uploadProgPct').textContent = '100%';
    document.getElementById('uploadProgBar').style.width = '100%';
    setTimeout(hideUploadProgress, 420);
}
function _voiceProgressStop() {
    if (_voiceTimer) { clearInterval(_voiceTimer); _voiceTimer = null; }
    hideUploadProgress();
}
function _voiceProgressWatch(tempId) {
    _voiceProgressStart('پیام صوتی');
    const t = setInterval(function () {
        const still = pendingMessages.some(function (p) { return p.tempId === tempId; });
        const el = document.getElementById('msg-' + tempId);
        const failed = el && el.classList.contains('error');
        if (!still) { clearInterval(t); _voiceProgressFinish(); }
        else if (failed) { clearInterval(t); _voiceProgressStop(); }
    }, 260);
    setTimeout(function () { clearInterval(t); hideUploadProgress(); }, 25000);
}

// ── Staged media upload ────────────────────────────────────────────────────
// The file is pushed to the server in small chunks and only after the last
// chunk lands is the message published. Two reasons:
//   1. `upload_max_filesize` is 2 MB on a stock WAMP install, so posting a
//      video inside `action=send` died silently and the user saw nothing.
//   2. A media message that appears for everyone while its bytes are still
//      travelling is a broken bubble on every other client.
const _UP_CHUNK = 512 * 1024;

function _upForm(action, fields) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', csrfToken);
    if (bwNonce) fd.append('bw_nonce', bwNonce);
    for (const k in fields) fd.append(k, fields[k]);
    return fd;
}

function _upJson(url, fd) {
    return new Promise(function (resolve, reject) {
        const xhr = new XMLHttpRequest();
        _uploadXhr = xhr;
        xhr.open('POST', url, true);
        xhr.onload = function () {
            _uploadXhr = null;
            let d = null;
            try { d = JSON.parse(xhr.responseText); } catch (e) { reject(new Error('پاسخ نامعتبر سرور')); return; }
            if (d && d.status === 'success') resolve(d);
            else reject(new Error((d && d.message) || 'خطا در آپلود'));
        };
        xhr.onerror = function () { _uploadXhr = null; reject(new Error('ارتباط برقرار نشد')); };
        xhr.onabort = function () { _uploadXhr = null; reject(new Error('__aborted__')); };
        xhr.send(fd);
    });
}

function _upChunk(url, blob, onProgress) {
    return new Promise(function (resolve, reject) {
        const xhr = new XMLHttpRequest();
        _uploadXhr = xhr;
        xhr.open('POST', url, true);
        xhr.setRequestHeader('Content-Type', 'application/octet-stream');
        xhr.upload.onprogress = function (e) { if (e.lengthComputable && onProgress) onProgress(e.loaded); };
        xhr.onload = function () {
            _uploadXhr = null;
            let d = null;
            try { d = JSON.parse(xhr.responseText); } catch (e) { reject(new Error('پاسخ نامعتبر سرور')); return; }
            if (d && d.status === 'success') resolve(d);
            else reject(new Error((d && d.message) || 'خطا در آپلود قطعه'));
        };
        xhr.onerror = function () { _uploadXhr = null; reject(new Error('ارتباط برقرار نشد')); };
        xhr.onabort = function () { _uploadXhr = null; reject(new Error('__aborted__')); };
        xhr.send(blob);
    });
}

async function _uploadMediaChunked(file, isVoice, onPct) {
    const init = await _upJson('upload.php', _upForm('init', {
        name: file.name || (isVoice ? 'voice.webm' : 'file'),
        size: file.size,
        mime: file.type || '',
        is_voice: isVoice ? 'true' : 'false'
    }));
    const id = init.upload_id;
    const total = file.size || 1;
    try {
        for (let offset = 0; offset < total; offset += _UP_CHUNK) {
            const slice = file.slice(offset, Math.min(offset + _UP_CHUNK, total));
            const url = 'upload.php?action=chunk'
                + '&upload_id=' + encodeURIComponent(id)
                + '&csrf_token=' + encodeURIComponent(csrfToken)
                + (bwNonce ? '&bw_nonce=' + encodeURIComponent(bwNonce) : '');
            const base = offset;
            await _upChunk(url, slice, function (loaded) {
                if (onPct) onPct(Math.min(99, ((base + loaded) / total) * 100));
            });
            if (onPct) onPct(Math.min(99, (Math.min(total, offset + slice.size) / total) * 100));
        }
        const fin = await _upJson('upload.php', _upForm('finish', { upload_id: id }));
        if (onPct) onPct(100);
        return fin;
    } catch (e) {
        try { await _upJson('upload.php', _upForm('cancel', { upload_id: id })); } catch (e2) {}
        throw e;
    }
}

function _mediaUpBadge(el) {
    if (!el) return null;
    let b = el.querySelector('.media-up-badge');
    if (!b) {
        b = document.createElement('div');
        b.className = 'media-up-badge';
        b.innerHTML = '<span class="media-up-txt">در حال آپلود…</span>'
                    + '<span class="media-up-bar"><i></i></span>'
                    + '<span class="media-up-pct">0%</span>';
        el.appendChild(b);
    }
    return b;
}

function _setMediaUp(el, pct, state) {
    const b = _mediaUpBadge(el);
    if (!b) return;
    const txt = b.querySelector('.media-up-txt');
    const bar = b.querySelector('.media-up-bar > i');
    const pc  = b.querySelector('.media-up-pct');
    b.classList.toggle('is-done', state === 'done');
    b.classList.toggle('is-fail', state === 'fail');
    if (state === 'done') {
        if (txt) txt.textContent = 'آپلود شد';
        if (bar) bar.style.width = '100%';
        if (pc) pc.textContent = '✓';
    } else if (state === 'fail') {
        if (txt) txt.textContent = 'آپلود ناموفق';
        if (bar) bar.style.width = '100%';
        if (pc) pc.textContent = '✕';
    } else {
        if (txt) txt.textContent = 'در حال آپلود…';
        if (bar) bar.style.width = Math.max(0, Math.min(100, pct)) + '%';
        if (pc) pc.textContent = Math.round(pct) + '%';
    }
}

function _clearMediaUp(el) {
    if (!el) return;
    const b = el.querySelector('.media-up-badge');
    if (b) b.remove();
}

/**
 * Upload the file completely, then publish a tiny message that only names it.
 * `tempId` is the optimistic bubble already on screen.
 */
async function _sendMediaStaged(file, isVoice, tempId, caption, replyId, clientMsgId) {
    const el = document.getElementById('msg-' + tempId);
    const label = file.name || (isVoice ? 'پیام صوتی' : 'فایل');
    try {
        _setMediaUp(el, 0, 'up');
        showUploadProgress(label, 0, file);
        const fin = await _uploadMediaChunked(file, isVoice, function (p) {
            _setMediaUp(el, p, 'up');
            showUploadProgress(label, p, file);
        });
        hideUploadProgress();
        _setMediaUp(el, 100, 'done');

        const fd = new FormData();
        fd.append('action', 'send');
        fd.append('csrf_token', csrfToken);
        if (bwNonce) fd.append('bw_nonce', bwNonce);
        if (targetUser) {
            fd.append('receiver_id', targetUser);
            fd.append('room', 'null');
        } else {
            fd.append('room', currentRoom || 'گفتگوی عمومی');
            if (currentRoomId) fd.append('room_id', currentRoomId);
        }
        if (replyId) fd.append('reply_to', replyId);
        if (clientMsgId) fd.append('client_msg_id', clientMsgId);
        fd.append('staged_token', fin.token);
        if (caption) fd.append('message', caption);

        _queueSendFetch(fd, tempId, caption || '', function () {
            const doneEl = document.getElementById('msg-' + tempId) || el;
            _clearMediaUp(doneEl);
        });
    } catch (e) {
        hideUploadProgress();
        const msg = (e && e.message) || 'آپلود ناموفق بود';
        if (msg === '__aborted__') {
            const row = el ? (el.closest('.msg-row') || el) : null;
            if (row && row.parentNode) row.remove();
            pendingMessages = pendingMessages.filter(p => p.tempId !== tempId);
            return;
        }
        _setMediaUp(el, 100, 'fail');
        markAsError(tempId);
        showToast(msg);
    }
}

function processUploadQueue() {
    if (isUploading || uploadQueue.length === 0) return;

    isUploading = true;
    const f = uploadQueue.shift();

    const tId = Date.now() + Math.floor(Math.random() * 1000);
    const mime = (f.type || '');
    const tObj = {
        id: null, tempId: tId, sender_id: currentUser, sender_name: 'شما', sender_sticker: MY_STICKER, created_at: _nowStamp(),
        message: '', msg_type: mime.indexOf('video/') === 0 ? 'video' : (mime.indexOf('image/') === 0 ? 'image' : 'file'),
        file_name: f.name, blob: f,
        reply_to_id: null, reply_sender: '...', reply_message: null
    };

    pendingMessages.push({ tempId: tId, blob: null, file: f, msg: '', isV: false });
    document.getElementById('msgsContent').insertAdjacentHTML('beforeend', renderMessage(tObj, true));
    userScrolledUp = false;
    _programScrollUntil = Date.now() + 2000;
    const _ubox = document.getElementById('messagesBox');
    _ubox.style.scrollBehavior = 'auto';
    _ubox.scrollTop = _ubox.scrollHeight;
    requestAnimationFrame(function () { _ubox.scrollTop = _ubox.scrollHeight; _ubox.style.scrollBehavior = 'smooth'; });

    _sendMediaStaged(f, false, tId, '', null).finally(function () {
        isUploading = false;
        setTimeout(processUploadQueue, 300);
    });
}

function markAsError(tempId) {
    const el = document.getElementById(`msg-${tempId}`);
    if(el) {
        el.classList.add('error');
        if(!el.querySelector('.retry-btn')) {
            el.innerHTML += `<div class="retry-btn" onclick="retrySend(${tempId})">تلاش مجدد ↻</div>`;
        }
    }
}

function retrySend(tempId) {
    const pObj = pendingMessages.find(p => p.tempId === tempId);
    if(pObj) sendMessage(null, pObj.isV, tempId);
}

function _syncSendState() {
    const btn = document.getElementById('sendBtn');
    const box = document.getElementById('msgInput');
    const fi = document.getElementById('fileInput');
    const row = document.getElementById('inputRow');
    if (!btn || !box) return;
    const hasText = box.value.trim().length > 0;
    const hasFile = !!(fi && fi.files && fi.files.length > 0);
    const has = hasText || hasFile;
    btn.classList.toggle('is-idle', !has);
    // The composer swaps the mic plane for the send plane the moment the box
    // has anything to send — exactly like Telegram. These classes are what the
    // CSS swap keys off, so they must be kept in step with `has`.
    if (row) {
        row.classList.toggle('has-text', has);
        row.classList.toggle('has-file', hasFile);
    }
}

// Grows with the text and shrinks back to one line as soon as it is cleared.
// `height:auto` first is what makes shrinking work: measuring scrollHeight
// while the old (tall) inline height is still applied would keep it tall.
function _autoGrowComposer(el) {
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 150) + 'px';
}

document.getElementById('msgInput').addEventListener('input', function() {
    _autoGrowComposer(this);
    _syncSendState();
});

// Typing pings used to fire on every single keydown — a 30-character message
// meant 30 POSTs, each one an UPDATE on the users row. Now the indicator is
// refreshed at most once every 2.5 s and only while the box actually has text.
let _typingSentAt = 0;
function _typingPayload(clear) {
    const base = targetUser
        ? { room: 'null', target_user: parseInt(targetUser) }
        : { room: currentRoom || 'گفتگوی عمومی', target_user: 0 };
    if (clear) base.clear = 1;
    return base;
}
function _sendTypingPing() {
    const box = document.getElementById('msgInput');
    if (!box || !box.value.trim()) return;
    const now = Date.now();
    if (now - _typingSentAt < 2500) return;
    _typingSentAt = now;
    apiCall('chat.php', 'update_typing', _typingPayload(false));
}

document.getElementById('msgInput').addEventListener('keydown', function(e) {
    if(e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }

    _sendTypingPing();

    clearTimeout(typingTimeout);
    typingTimeout = setTimeout(function() {
        if (!_typingSentAt) return;
        _typingSentAt = 0;
        apiCall('chat.php', 'update_typing', _typingPayload(true));
    }, 3200);
});

_syncSendState();

const _emojiCats = [
  { id:'smileys', icon:'<img class="i3d-sm" src="assets/icons3d/grinning.png" alt="">', label:'صورتک‌ها', k:'face smile happy sad laugh صورتک خنده شادی غم', e:['😀','😃','😄','😁','😆','😅','🤣','😂','🙂','🙃','😉','😊','😇','🥰','😍','🤩','😘','😗','😚','😙','🥲','😋','😛','😜','🤪','😝','🤑','🤗','🤭','🤫','🤔','🫡','🤐','🤨','😐','😑','😶','🫥','😏','😒','🙄','😬','🤥','😌','😔','😪','🤤','😴','😷','🤒','🤕','🤢','🤮','🥵','🥶','🥴','😵','🤯','🤠','🥳','🥸','😎','🤓','🧐','😕','🫤','😟','🙁','😮','😯','😲','😳','🥺','🥹','😦','😧','😨','😰','😥','😢','😭','😱','😖','😣','😞','😓','😩','😫','🥱','😤','😡','🤬','😈','👿','💀','💩','🤡','👻','👽','🤖'] },
  { id:'gestures', icon:'<img class="i3d-sm" src="assets/icons3d/thumbsup.png" alt="">', label:'اشاره‌ها', k:'hand gesture finger thumb wave like دست اشاره', e:['👋','🤚','🖐️','✋','🖖','🫱','🫲','🫳','🫴','👌','🤌','🤏','✌️','🤞','🫰','🤟','🤘','🤙','👈','👉','👆','👇','☝️','🫵','👍','👎','✊','👊','🤛','🤜','👏','🙌','🫶','👐','🤲','🤝','🙏','💅','🤳','💪','🦾','🦵','🦶','👂','👀','🧠','🫀'] },
  { id:'hearts', icon:'<img class="i3d-sm" src="assets/icons3d/heart_red.png" alt="">', label:'قلب‌ها', k:'heart love قلب عشق دوستت دارم', e:['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❤️‍🔥','❤️‍🩹','💕','💞','💓','💗','💖','💘','💝','💟','♥️','💌'] },
  { id:'animals', icon:'<img class="i3d-sm" src="assets/icons3d/dog.png" alt="">', label:'حیوانات و طبیعت', k:'animal nature dog cat bird flower حیوان گربه سگ پرنده گل طبیعت', e:['🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐨','🐯','🦁','🐮','🐷','🐸','🐵','🙈','🙉','🙊','🐔','🐧','🐦','🦅','🦉','🦇','🐺','🐗','🐴','🦄','🐝','🦋','🐌','🐞','🐢','🐍','🦖','🐙','🦈','🐬','🐳','🌵','🌲','🌳','🌴','🌱','🍀','🌹','🌸','🌺','🌻','🌼','🌷'] },
  { id:'food', icon:'<img class="i3d-sm" src="assets/icons3d/pizza.png" alt="">', label:'غذا و نوشیدنی', k:'food drink pizza coffee tea fruit غذا نوشیدنی قهوه چای میوه', e:['🍏','🍎','🍐','🍊','🍋','🍌','🍉','🍇','🍓','🫐','🍒','🍑','🥭','🍍','🥥','🥝','🍅','🥑','🥦','🥕','🌽','🥔','🍞','🥐','🥖','🧀','🥚','🍳','🥞','🧇','🥓','🍔','🍟','🍕','🌭','🥪','🌮','🌯','🥗','🍝','🍜','🍲','🍣','🍱','🍛','🍚','🍢','🍡','🍧','🍨','🍦','🥧','🧁','🍰','🎂','🍫','🍬','🍭','🍩','🍪','☕','🍵','🧃','🥤','🧋','🍺','🍻','🥂','🍷'] },
  { id:'activity', icon:'<img class="i3d-sm" src="assets/icons3d/ball.png" alt="">', label:'ورزش و فعالیت', k:'sport activity football game play ورزش فوتبال بازی تفریح', e:['⚽','🏀','🏈','⚾','🥎','🎾','🏐','🏉','🥏','🎱','🏓','🏸','🥊','🥋','⛳','⛸️','🎣','🤿','🎽','🛹','🛼','🏆','🥇','🥈','🥉','🏅','🎖️','🎯','🎮','🕹️','🎲','🧩','🎰','🎳','🎪','🎭','🎨','🎬','🎤','🎧','🎼','🎹','🥁','🎸','🎺'] },
  { id:'objects', icon:'<img class="i3d-sm" src="assets/icons3d/bulb.png" alt="">', label:'اشیاء', k:'object tech phone computer gift money thing شی موبایل کامپیوتر هدیه پول', e:['📱','💻','⌨️','🖥️','🖨️','🖱️','💾','💿','📀','📷','📹','🎥','📞','☎️','📺','📻','⏰','⌚','🔋','🔌','💡','🔦','🕯️','🧯','🛒','🎁','🎈','🎉','🎊','🎀','🧸','🔮','🧿','💰','💎','🔑','🔒','📌','📎','✂️','🔨','🛠️','🧲','🧪','🚀','🛰️','🚗','✈️','🚲','🛵','🏠','🏢','🗺️','🧭'] },
  { id:'symbols', icon:'<img class="i3d-sm" src="assets/icons3d/check.png" alt="">', label:'نمادها', k:'symbol check star fire sign weather symbol نماد ستاره آتش علامت هوا', e:['✅','❌','⭕','🛑','⛔','♻️','⚠️','🚸','🔱','⚜️','🔰','✳️','❇️','💯','🔞','⚡','☄️','🔥','💥','⭐','🌟','✨','💫','☀️','🌤️','⛅','🌧️','⛈️','❄️','☃️','🌈','💧','🌊','☪️','✡️','🕉️','☯️','♠️','♥️','♦️','♣️','🔲','⚫','⚪','🟢','🟡','🔴','🟣'] }
];

let _emojiPickerInited = false;
let _epActiveCat = 'smileys';
let _epQuery = '';

function _epEsc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); }

function _epGridHtml() {
    let out = '';
    for (const c of _emojiCats) {
        let em;
        if (_epQuery) {
            const keys = (c.k || '').split(' ').filter(Boolean);
            em = c.e.filter(e => e === _epQuery || keys.some(k => _epQuery.includes(k)));
        } else {
            em = (c.id === _epActiveCat) ? c.e : [];
        }
        if (!em.length) continue;
        out += `<div class="ep-sec">${c.icon} ${_epEsc(c.label)}</div>`;
        out += em.map(e => `<button type="button" class="emoji-pick-btn" data-emoji="${e}">${e}</button>`).join('');
    }
    return out;
}

function _epRender() {
    const grid = document.getElementById('epGrid');
    if (!grid) return;
    grid.innerHTML = _epGridHtml() || '<div class="ep-empty">ایموجی پیدا نشد</div>';
}

function _epClose(picker, btn) {
    picker.style.display = 'none';
    if (btn) btn.style.background = '';
    const si = document.getElementById('epSearch');
    if (si) si.blur();
}

function _epInit() {
    const cats = document.getElementById('epCats');
    const grid = document.getElementById('epGrid');
    const search = document.getElementById('epSearch');
    cats.innerHTML = _emojiCats.map(c =>
        `<button type="button" class="ep-cat${c.id === _epActiveCat ? ' active' : ''}" data-cat="${c.id}" title="${_epEsc(c.label)}">${c.icon}</button>`
    ).join('');
    cats.addEventListener('click', ev => {
        const b = ev.target.closest('.ep-cat');
        if (!b) return;
        _epActiveCat = b.dataset.cat;
        cats.querySelectorAll('.ep-cat').forEach(x => x.classList.toggle('active', x.dataset.cat === _epActiveCat));
        search.value = ''; _epQuery = '';
        _epRender(); grid.scrollTop = 0;
    });
    let epTimer = null;
    search.addEventListener('input', function() {
        clearTimeout(epTimer);
        epTimer = setTimeout(() => { _epQuery = this.value.trim().toLowerCase(); _epRender(); grid.scrollTop = 0; }, 120);
    });
    search.addEventListener('keydown', ev => { if (ev.key === 'Escape') { ev.stopPropagation(); toggleEmojiPicker(); } });
    grid.addEventListener('click', ev => {
        const b = ev.target.closest('.emoji-pick-btn');
        if (b) insertEmoji(b.dataset.emoji);
    });
    _epRender();
}

function toggleEmojiPicker() {
    const picker = document.getElementById('emojiPicker');
    const btn = document.getElementById('emojiBtn');
    const isOpen = picker.style.display === 'flex';
    if (isOpen) { _epClose(picker, btn); return; }
    if (!_emojiPickerInited) { _emojiPickerInited = true; _epInit(); }
    picker.style.display = 'flex';
    if (btn) btn.style.background = 'var(--v-soft, rgba(99,102,241,0.2))';
    _epQuery = '';
    const si = document.getElementById('epSearch');
    if (si) si.value = '';
    _epRender();
    picker.scrollTop = 0;
    setTimeout(() => {
        function outsideClose(ev) {
            if (!picker.contains(ev.target) && !btn.contains(ev.target)) {
                _epClose(picker, btn);
                document.removeEventListener('click', outsideClose, true);
            }
        }
        document.addEventListener('click', outsideClose, true);
    }, 10);
}

function insertEmoji(emoji) {
    const ta = document.getElementById('msgInput');
    const start = ta.selectionStart;
    const end = ta.selectionEnd;
    const val = ta.value;
    ta.value = val.substring(0, start) + emoji + val.substring(end);
    ta.selectionStart = ta.selectionEnd = start + emoji.length;
    ta.focus();
    ta.dispatchEvent(new Event('input'));
}

function setReply(id, name, text){
    const realText = (id && msgCache[id]) ? msgCache[id].text : (text || null);
    replyToId = id;
    replyContent = realText || '...';
    replyName = name || '...';
    var box = document.getElementById('messagesBox');
    var savedScrollTop = box.scrollTop;
    var savedScrollHeight = box.scrollHeight;
    var distFromBottom = savedScrollHeight - savedScrollTop - box.clientHeight;
    var wasNearBottom = distFromBottom < 80;
    if (!wasNearBottom) { userScrolledUp = true; }
    _programScrollUntil = Date.now() + 1500;
    box.style.scrollBehavior = 'auto';
    // Replying to the assistant gets its own glowing "AI conversation" state.
    const rBar = document.getElementById('replyBar');
    const isAi = !!(id && msgCache[id] && (msgCache[id].type === 'ai' || (msgCache[id].name || '').includes('دستیار')));
    rBar.classList.toggle('ai-mode', isAi);
    document.getElementById('aiReplyChip').style.display = isAi ? 'inline-flex' : 'none';
    document.getElementById('replyLabel').textContent = isAi ? 'پیام به دستیار:' : 'پاسخ به:';
    rBar.style.display='flex';
    document.getElementById('replyText').textContent = replyName + ': ' + (realText ? realText.substr(0,40) : '[فایل]');
    document.getElementById('msgInput').focus({preventScroll: true});
    requestAnimationFrame(function() {
        var delta = box.scrollHeight - savedScrollHeight;
        if (delta > 0) { box.scrollTop = savedScrollTop + delta; }
        requestAnimationFrame(function() { box.style.scrollBehavior = 'smooth'; });
    });
}

function cancelReply(){ replyToId=null; replyContent=null; replyName=null; const rBar=document.getElementById('replyBar'); rBar.classList.remove('ai-mode'); rBar.style.display='none'; }
function cancelFile(){ document.getElementById('fileInput').value=''; document.getElementById('filePreview').style.display='none'; const t=document.getElementById('fpThumb'); if(t) t.innerHTML='📎'; const m=document.getElementById('fileMeta'); if(m) m.textContent=''; _syncSendState(); }
document.getElementById('fileInput').onchange = function(){
    if(this.files.length > 0){
        const box = document.getElementById('filePreview');
        const thumb = document.getElementById('fpThumb');
        const meta = document.getElementById('fileMeta');
        box.style.display = 'flex';
        if(this.files.length > 1) {
            document.getElementById('fileName').textContent = this.files.length + ' فایل انتخاب شد';
            meta.textContent = _fmtSize(Array.from(this.files).reduce(function (s, f) { return s + f.size; }, 0));
            thumb.innerHTML = '📚';
        } else {
            const f = this.files[0];
            document.getElementById('fileName').textContent = f.name;
            meta.textContent = _fmtSize(f.size);
            thumb.innerHTML = _thumbHtml(f);
        }
    }
    // A queued attachment is sendable on its own, so the composer must offer
    // the send plane even while the text box is still empty.
    _syncSendState();
};

function _removeMsgEverywhere(id) {
    const el = document.getElementById('msg-' + id);
    if (el) {
        const row = el.closest('.msg-row') || el;
        row.remove();
    }
    try { delete msgCache[id]; } catch (e) {}
    if (loadedHistoryHtml) {
        const tmp = document.createElement('div');
        tmp.innerHTML = loadedHistoryHtml;
        const histEl = tmp.querySelector('#msg-' + id);
        if (histEl) {
            const histRow = histEl.closest('.msg-row') || histEl;
            histRow.remove();
            loadedHistoryHtml = tmp.innerHTML;
        }
    }
}

function delMsg(id){ 
    if(!canDelete){ showToast('حذف پیام‌ها توسط مدیر غیرفعال شده است'); return; }
    showConfirm('آیا این پیام حذف شود؟', () => {
        apiCall('chat.php', 'delete_msg', {id: id}, () => {
            _removeMsgEverywhere(id);
            const contentBox = document.getElementById('msgsContent');
            if (contentBox && !contentBox.querySelector('.msg') && !document.querySelector('.msg.pending')) {
                contentBox.innerHTML = '<div style="text-align:center;margin-top:50px;opacity:0.5;font-size:12px">پیامی نیست</div>';
            }
            fetchMessages();
        }, d => {
            if (d && d.delete_lock) {
                canDelete = false;
                const delAllItem = document.getElementById('menuDeleteAllItem');
                if (delAllItem) delAllItem.style.display = 'none';
                fetchMessages();
            }
        });
    }); 
}

function editMsg(id, txt){ showPrompt('ویرایش پیام:', txt, n=>{ 
    if(n!==txt) {
        apiCall('chat.php','edit_msg',{id:id, text:n}, d => {
            if(d.status === 'success') {
                showToast('پیام ویرایش شد');
                const formattedMsg = formatText(n) + ' <small style="opacity:0.5">(ویرایش)</small>';
                msgCache[id] = Object.assign(msgCache[id] || {}, { text: n });
                const msgEl = document.getElementById('msg-' + id);
                if(msgEl) {
                    let domTb = msgEl.querySelector('.text-glass-box');
                    if (domTb) {
                        domTb.innerHTML = formattedMsg;
                    } else {
                        msgEl.innerHTML += `<div class="text-glass-box chat-text">${formattedMsg}</div>`;
                    }
                }
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = loadedHistoryHtml;
                const histEl = tempDiv.querySelector('#msg-' + id);
                if(histEl) {
                    let histTb = histEl.querySelector('.text-glass-box');
                    if(histTb) {
                        histTb.innerHTML = formattedMsg;
                    } else {
                        histEl.innerHTML += `<div class="text-glass-box chat-text">${formattedMsg}</div>`;
                    }
                    loadedHistoryHtml = tempDiv.innerHTML;
                }
    
                fetchMessages();
            } else {
                showToast(d.message || 'خطا در ویرایش پیام');
            }
        });
    }
}); }

// توابع سیستم ری‌اکشن
let _reactionPickerMsgId = null;

var AP_PRESETS = ['#6366f1','#3b82f6','#0ea5e9','#06b6d4','#14b8a6','#10b981','#84cc16','#eab308','#f59e0b','#f97316','#ef4444','#ec4899'];
var AP_STEPS = [0.9, 1, 1.1, 1.2, 1.3];
var AP_LABELS = ['۹۰٪', '۱۰۰٪', '۱۱۰٪', '۱۲۰٪', '۱۳۰٪'];

function apBuild() {
    var grid = document.getElementById('apSwatches');
    if (!grid || grid.childElementCount) return;
    grid.innerHTML = AP_PRESETS.map(function (c) {
        return '<button type="button" class="ap-sw" data-c="' + c + '" style="background:' + c + '" onclick="apPick(\'' + c + '\')" aria-label="' + c + '"></button>';
    }).join('');
}

function apSync(color) {
    var c = String(color || '').toLowerCase();
    var dots = document.querySelectorAll('#apSwatches .ap-sw');
    for (var i = 0; i < dots.length; i++) {
        dots[i].classList.toggle('active', dots[i].getAttribute('data-c') === c);
    }
    var hint = document.getElementById('apColorHint');
    if (hint) hint.textContent = c;
    var dot = document.getElementById('apCustomDot');
    if (dot) dot.style.background = c || '#6366f1';
    var inp = document.getElementById('apColorInput');
    if (inp && c && inp.value !== c) inp.value = c;
}
window.veyraxSyncSwatches = apSync;

function apSyncScale(scale) {
    var v = parseFloat(scale) || 1;
    var idx = 1;
    for (var i = 0; i < AP_STEPS.length; i++) { if (Math.abs(AP_STEPS[i] - v) < 0.01) { idx = i; break; } }
    var range = document.getElementById('apFsRange');
    if (range && parseInt(range.value, 10) !== idx) range.value = idx;
    if (range) range.style.setProperty('--ap-fill', (idx / (AP_STEPS.length - 1) * 100) + '%');
    var lab = document.getElementById('apFsLabel');
    if (lab) lab.textContent = AP_LABELS[idx];
}
window.veyraxSyncScale = apSyncScale;

function apPick(color) {
    veyraxApplyTheme(color);
}

function apPickCustom(color) {
    if (!/^#[0-9a-fA-F]{6}$/.test(color)) return;
    veyraxApplyTheme(color.toLowerCase());
}

function apSetFont(idx) {
    var i = Math.max(0, Math.min(AP_STEPS.length - 1, parseInt(idx, 10) || 0));
    veyraxApplyFontScale(AP_STEPS[i]);
    apSyncScale(AP_STEPS[i]);
}

function apStepFont(delta) {
    var st = window.veyraxThemeState ? window.veyraxThemeState() : { scale: 1 };
    var i = 1;
    for (var k = 0; k < AP_STEPS.length; k++) { if (Math.abs(AP_STEPS[k] - st.scale) < 0.01) { i = k; break; } }
    apSetFont(i + delta);
}

function apReset() {
    veyraxResetTheme();
    var st = window.veyraxThemeState ? window.veyraxThemeState() : { color: '#6366f1', scale: 1 };
    apSync(st.color);
    apSyncScale(st.scale);
}

function openAppearance() {
    apBuild();
    var st = window.veyraxThemeState ? window.veyraxThemeState() : { color: '#6366f1', scale: 1 };
    apSync(st.color);
    apSyncScale(st.scale);
    var ov = document.getElementById('appearanceOverlay');
    if (ov) ov.classList.add('open');
}

function closeAppearance() {
    var ov = document.getElementById('appearanceOverlay');
    if (ov) ov.classList.remove('open');
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAppearance();
});

document.addEventListener('DOMContentLoaded', function () {
    var st = window.veyraxThemeState ? window.veyraxThemeState() : { color: '#6366f1', scale: 1 };
    apBuild();
    apSync(st.color);
    apSyncScale(st.scale);
});

function toggleReactionPicker(msgId, btnEl) {
    const popup = document.getElementById('reactionPickerGlobal');
    if (!popup) return;

    if (_reactionPickerMsgId === msgId && popup.classList.contains('show')) {
        _closeReactionPicker();
        return;
    }

    _reactionPickerMsgId = msgId;

    popup.querySelectorAll('.reaction-emoji').forEach(function(el) {
        el.onclick = function(e) {
            e.stopPropagation();
            reactToMessage(msgId, el.getAttribute('data-emoji'));
            _closeReactionPicker();
        };
    });

    popup.classList.remove('show');

    const btn = btnEl || document.querySelector('.reaction-btn[data-msg-id="' + msgId + '"]');
    if (!btn) { popup.classList.add('show'); return; }

    const btnRect = btn.getBoundingClientRect();
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const pw = 268;
    const ph = 62;
    const margin = 8;

    let top = btnRect.top - ph - 10;
    let left = btnRect.left + (btnRect.width / 2) - (pw / 2);

    if (top < margin) top = btnRect.bottom + 10;
    if (top + ph > vh - margin) top = vh - ph - margin;
    if (left < margin) left = margin;
    if (left + pw > vw - margin) left = vw - pw - margin;

    popup.style.top = Math.round(top) + 'px';
    popup.style.left = Math.round(left) + 'px';
    popup.classList.add('show');
}

function _closeReactionPicker() {
    const popup = document.getElementById('reactionPickerGlobal');
    if (popup) {
        popup.classList.remove('show');
        setTimeout(function() { _reactionPickerMsgId = null; }, 160);
    }
}

function _rxBump(el) {
    if (!el) return;
    el.classList.remove('rx-pop');
    void el.offsetWidth;
    el.classList.add('rx-pop');
    setTimeout(function () { el.classList.remove('rx-pop'); }, 340);
}

function _rxSetCount(el, count) {
    if (!el) return;
    el.setAttribute('data-count', String(count));
    const n = el.querySelector('.reaction-count');
    if (n) n.textContent = count;
}

function _rxApplyTip(el, users) {
    if (!el) return;
    const tip = _rxUsersText(users);
    el.setAttribute('data-users', (users || []).join('|'));
    if (tip) {
        el.onmouseenter = function (e) { showReactionTooltip(e, tip); };
        el.onmouseleave = function () { hideReactionTooltip(); };
    } else {
        el.onmouseenter = null;
        el.onmouseleave = null;
    }
}

function _rxDec(el) {
    if (!el) return;
    const c = (parseInt(el.getAttribute('data-count'), 10) || 1) - 1;
    if (c <= 0) { el.remove(); return; }
    _rxSetCount(el, c);
}

function _rxEnsureDisplay(msgEl) {
    let display = msgEl.querySelector('.reaction-display');
    if (display) return display;
    display = document.createElement('div');
    display.className = 'reaction-display';
    const container = msgEl.querySelector('.reaction-container');
    if (container) msgEl.insertBefore(display, container);
    else msgEl.appendChild(display);
    return display;
}

function _rxFind(msgEl, emoji) {
    return Array.from(msgEl.querySelectorAll('.reaction-item')).find(function (el) {
        return el.getAttribute('data-emoji') === emoji;
    }) || null;
}

function _rxReconcile(msgEl, msgId, data) {
    const all = data.all || {};
    const mine = data.my_reaction || null;
    const keys = Object.keys(all);

    if (!keys.length) {
        const d = msgEl.querySelector('.reaction-display');
        if (d) d.remove();
        return;
    }

    const display = _rxEnsureDisplay(msgEl);
    keys.forEach(function (emoji) {
        const info = all[emoji] || {};
        let chip = _rxFind(msgEl, emoji);
        if (!chip) {
            const tmp = document.createElement('div');
            tmp.innerHTML = _rxChip(msgId, emoji, info.count || 0, info.users || [], mine === emoji);
            chip = tmp.firstElementChild;
            display.appendChild(chip);
        } else {
            _rxSetCount(chip, info.count || 0);
            _rxApplyTip(chip, info.users || []);
        }
        chip.classList.toggle('my-reaction', mine === emoji);
    });

    Array.from(display.querySelectorAll('.reaction-item')).forEach(function (el) {
        if (!all[el.getAttribute('data-emoji')]) el.remove();
    });

    if (!display.children.length) display.remove();
}

function reactToMessage(msgId, reaction) {
    const msgEl = document.getElementById('msg-' + msgId);
    if (!msgEl) return;

    const snapshot = (function () {
        const d = msgEl.querySelector('.reaction-display');
        return d ? d.outerHTML : '';
    })();
    const myChip = msgEl.querySelector('.reaction-item.my-reaction');
    const wasMine = !!(myChip && myChip.getAttribute('data-emoji') === reaction);
    const target = _rxFind(msgEl, reaction);

    if (wasMine) {
        _rxDec(target);
        const d = msgEl.querySelector('.reaction-display');
        if (d && !d.children.length) d.remove();
    } else {
        if (myChip && myChip !== target) _rxDec(myChip);
        const display = _rxEnsureDisplay(msgEl);
        let chip = target;
        if (chip) {
            _rxSetCount(chip, (parseInt(chip.getAttribute('data-count'), 10) || 0) + 1);
            chip.classList.add('my-reaction');
        } else {
            const tmp = document.createElement('div');
            tmp.innerHTML = _rxChip(msgId, reaction, 1, ['شما'], true);
            chip = tmp.firstElementChild;
            display.appendChild(chip);
        }
        _rxBump(chip);
    }

    if (navigator.vibrate) { try { navigator.vibrate(8); } catch (e) {} }

    const revert = function (message) {
        const current = msgEl.querySelector('.reaction-display');
        if (!snapshot) {
            if (current) current.remove();
        } else {
            const tmp = document.createElement('div');
            tmp.innerHTML = snapshot;
            const fresh = tmp.firstElementChild;
            if (current) current.replaceWith(fresh);
            else {
                const container = msgEl.querySelector('.reaction-container');
                if (container) msgEl.insertBefore(fresh, container);
                else msgEl.appendChild(fresh);
            }
        }
        if (message) showToast(message);
    };

    apiCall('chat.php', 'react', {
        msg_id: msgId,
        reaction: reaction
    }, function (data) {
        if (!data || data.status !== 'success') {
            revert((data && data.message) || 'خطا در ثبت ری‌اکشن');
            return;
        }
        _rxReconcile(msgEl, msgId, data);
    }, function (d) {
        revert((d && d.message) || 'ارتباط برقرار نشد');
    });
}

let currentTooltip = null;
let _tooltipHideTimer = null;

function showReactionTooltip(event, users) {
    hideReactionTooltip();
    
    const tooltip = document.createElement('div');
    tooltip.className = 'reaction-tooltip';
    tooltip.id = 'activeReactionTooltip';
    tooltip.textContent = users;
    document.body.appendChild(tooltip);
    
    const rect = event.currentTarget ? event.currentTarget.getBoundingClientRect() : event.target.getBoundingClientRect();
    const tipW = Math.min(200, tooltip.scrollWidth + 4);
    let left = rect.left + (rect.width / 2) - (tipW / 2);
    left = Math.max(8, Math.min(left, window.innerWidth - tipW - 8));
    tooltip.style.left = left + 'px';
    tooltip.style.top = (rect.top - tooltip.offsetHeight - 8) + 'px';
    
    currentTooltip = tooltip;

    // auto-hide after 2.5s
    clearTimeout(_tooltipHideTimer);
    _tooltipHideTimer = setTimeout(hideReactionTooltip, 2500);
}

function hideReactionTooltip() {
    clearTimeout(_tooltipHideTimer);
    const existing = document.getElementById('activeReactionTooltip');
    if (existing) existing.remove();
    if (currentTooltip) { currentTooltip.remove(); currentTooltip = null; }
}

// hide tooltip on any scroll or click or touch
document.addEventListener('scroll', hideReactionTooltip, true);
document.addEventListener('touchstart', hideReactionTooltip, { passive: true });
document.addEventListener('click', function(e) {
    if (!e.target.closest('.reaction-item')) hideReactionTooltip();
});

document.addEventListener('click', function(e) {
    if (!e.target.closest('.reaction-container') && !e.target.closest('#reactionPickerGlobal')) {
        _closeReactionPicker();
    }
});
document.getElementById('messagesBox').addEventListener('scroll', function() {
    _closeReactionPicker();
}, {passive: true});
window.addEventListener('resize', function() {
    _closeReactionPicker();
}, {passive: true});

var _dmCounts = { room: 0, dm: 0, all: 0 };
var _dmBusy = false;

function dmScope() {
    var el = document.querySelector('input[name="dmScope"]:checked');
    return el ? el.value : 'room';
}

function dmPaintCounts() {
    var fmt = function (n) { try { return Number(n || 0).toLocaleString('fa-IR'); } catch (e) { return String(n || 0); } };
    var r = document.getElementById('dmCountRoom');
    var d = document.getElementById('dmCountDm');
    var a = document.getElementById('dmCountAll');
    if (r) r.textContent = fmt(_dmCounts.room);
    if (d) d.textContent = fmt(_dmCounts.dm);
    if (a) a.textContent = fmt(_dmCounts.all);
}

function dmSyncGo() {
    var btn = document.getElementById('dmGoBtn');
    if (!btn) return;
    var n = _dmCounts[dmScope()] || 0;
    btn.disabled = _dmBusy || n === 0;
    btn.textContent = n > 0 ? 'حذف کن' : 'پیامی نیست';
}

function openDeleteMine() {
    if (!canDelete) {
        if (window.VXUI && VXUI.alert) {
            VXUI.alert({
                title: 'حذف پیام غیرفعال است',
                html: 'مدیر سیستم قابلیت حذف پیام را موقتاً غیرفعال کرده است.<br>برای فعال‌سازی با مدیر تماس بگیرید.',
                icon: 'info',
                confirmText: 'متوجه شدم'
            });
        } else {
            showToast('حذف پیام‌ها توسط مدیر غیرفعال شده است');
        }
        return;
    }
    var ov = document.getElementById('deleteMineOverlay');
    if (!ov) return;
    var nm = document.getElementById('dmRoomName');
    if (nm) nm.textContent = targetUser ? 'گفتگوی خصوصی فعلی' : (currentRoom || 'گفتگوی عمومی');
    var r = document.querySelector('input[name="dmScope"][value="room"]');
    if (r) r.checked = true;
    _dmCounts = { room: 0, dm: 0, all: 0 };
    dmPaintCounts();
    dmSyncGo();
    ov.classList.add('open');
    apiCall('chat.php', 'my_msg_count', { room: currentRoom || 'گفتگوی عمومی' }, function (d) {
        _dmCounts.room = d.room || 0;
        _dmCounts.dm = d.dm || 0;
        _dmCounts.all = d.all || 0;
        dmPaintCounts();
        dmSyncGo();
    }, function () {
        dmSyncGo();
    });
}

function closeDeleteMine() {
    var ov = document.getElementById('deleteMineOverlay');
    if (ov) ov.classList.remove('open');
}

function askDeleteAll() { openDeleteMine(); }

function confirmDeleteMine() {
    if (_dmBusy) return;
    var scope = dmScope();
    var n = _dmCounts[scope] || 0;
    if (n === 0) { showToast('پیامی برای حذف پیدا نشد'); return; }

    var label = scope === 'room'
        ? (targetUser ? 'همین گفتگوی خصوصی' : '«' + (currentRoom || 'گفتگوی عمومی') + '»')
        : (scope === 'dm' ? 'همهٔ گفتگوهای خصوصی' : 'همهٔ گفتگوها');

    var run = function () {
        _dmBusy = true;
        var btn = document.getElementById('dmGoBtn');
        if (btn) { btn.disabled = true; btn.textContent = 'در حال حذف…'; }
        apiCall('chat.php', 'delete_all_msgs', { confirm: true, scope: scope, room: currentRoom || 'گفتگوی عمومی' }, function (d) {
            _dmBusy = false;
            closeDeleteMine();
            try {
                if (window.VXCache) {
                    VXCache.del('snap:msgs:' + _vxRoomKey());
                    VXCache.del('snap:sidebar');
                }
            } catch (e) {}
            lastMsgId = 0;
            oldestMsgId = null;
            fetchMessages();
            var msg = (d && d.message) || 'پیام‌های شما حذف شد';
            if (window.VXUI) VXUI.toast(msg, 'success'); else showToast(msg);
        }, function (err) {
            _dmBusy = false;
            dmSyncGo();
            if (err && err.delete_lock) {
                canDelete = false;
                var item = document.getElementById('menuDeleteAllItem');
                if (item) item.style.display = 'none';
                closeDeleteMine();
            }
            showToast((err && err.message) || 'خطا در حذف پیام‌ها');
        });
    };

    if (window.VXUI && VXUI.confirm) {
        VXUI.confirm({
            title: 'حذف ' + Number(n).toLocaleString('fa-IR') + ' پیام',
            html: 'پیام‌های شما در ' + label + ' برای همیشه پاک می‌شوند.<br><b>این عملیات قابل بازگشت نیست.</b>',
            danger: true,
            confirmText: 'بله، حذف کن',
            cancelText: 'انصراف'
        }).then(function (ok) { if (ok) run(); });
    } else if (confirm('حذف ' + n + ' پیام قابل بازگشت نیست. ادامه می‌دهید؟')) {
        run();
    }
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        var ov = document.getElementById('deleteMineOverlay');
        if (ov && ov.classList.contains('open')) closeDeleteMine();
    }
});

function filterList(q){ document.querySelectorAll('#sidebarList .item').forEach(el=>el.style.display=el.textContent.toLowerCase().includes(q.toLowerCase())?'flex':'none'); }

function openInviteModal(roomName = null){ 
    openModal('roomSelectModal');
    const l=document.getElementById('inviteRoomList'); 
    l.innerHTML=''; 
    if(roomName) {
        l.innerHTML = `<div style="text-align:center;padding:10px;">در حال دعوت به اتاق: <b>${roomName}</b></div>
        <div class="input-group" style="margin-top:10px;">
            <input type="text" id="inviteUserDirect" class="modal-input" placeholder="نام کاربری..." style="width:100%;margin-bottom:10px;">
            <button onclick="sendDirectInvite('${roomName}')" class="modal-btn btn-green">ارسال دعوت</button>
        </div>`;
        return;
    }

    if (allRoomsCache && allRoomsCache.length > 0) {
        allRoomsCache.forEach(r => {
            if(r.name === 'گفتگوی عمومی' || r.name === 'public') return;
            const d = document.createElement('div'); 
            d.className='item'; 
            d.innerHTML = `<span>${r.type === 'private' ? '🔒' : '💬'} ${r.name}</span>`; 
            d.onclick=()=>{ 
                apiCall('requests.php','invite_user',{username:ctxName, room_name:r.name},()=>showToast('درخواست دعوت ارسال شد')); 
                closeModal('roomSelectModal'); 
            }; 
            l.appendChild(d); 
        });
    } else {
        l.innerHTML = '<div style="text-align:center;padding:10px;color:#94a3b8">هیچ اتاقی یافت نشد</div>';
    }
}

function sendDirectInvite(roomName) {
    const user = document.getElementById('inviteUserDirect').value;
    if(!user) return showToast('نام کاربری را وارد کنید');
    apiCall('requests.php','invite_user',{username:user, room_name:roomName}, (d) => {
        if(d.status === 'success') {
            showToast('درخواست دعوت ارسال شد');
            closeModal('roomSelectModal');
        } else {
            showToast(d.message);
        }
    });
}

let _createTabMode = 'room';

function switchCreateTab(mode) {
    _createTabMode = mode;
    document.querySelectorAll('.cr-tab').forEach(t => t.classList.remove('active'));
    const descEl = document.getElementById('crDesc');
    if(mode === 'room') {
        document.getElementById('crTabRoom').classList.add('active');
        descEl.textContent = 'یک اتاق خصوصی با کد دعوت ۸ رقمی برای حداکثر ۸ نفر.';
    } else if(mode === 'channel_public') {
        document.getElementById('crTabChPub').classList.add('active');
        descEl.textContent = 'کانال عمومی در لیست همه نمایش داده می‌شود. فقط سازنده می‌تواند پیام ارسال کند.';
    } else {
        document.getElementById('crTabChPriv').classList.add('active');
        descEl.textContent = 'کانال خصوصی با کد دعوت ۸ رقمی. فقط سازنده می‌تواند پیام ارسال کند.';
    }
}

function openCreateRoom() {
    _createTabMode = 'room';
    openModal('createRoomModal');
    document.getElementById('newRoomName').value = '';
    _crUpdateCount(document.getElementById('newRoomName'));
    switchCreateTab('room');
    setTimeout(() => document.getElementById('newRoomName').focus(), 100);
}

// Live character counter for the room-name field (Persian digits).
function _crUpdateCount(input) {
    const el = document.getElementById('crNameCount');
    if (!el || !input) return;
    const n = (input.value || '').length;
    let fa = String(n);
    try { fa = n.toLocaleString('fa-IR'); } catch (e) {}
    el.textContent = fa + '/۱۰';
    el.style.color = n >= 10 ? '#f59e0b' : (n >= 4 ? '#94a3b8' : '#64748b');
}

function submitCreateRoom(event) {
    if(event) event.preventDefault();
    const name = document.getElementById('newRoomName').value.trim();
    if(!name) return showToast('نام الزامی است');
    if(name.length < 4) return showToast('نام باید حداقل ۴ حرف باشد');
    
    const btn = document.getElementById('btnCreateRoomSubmit');
    if(btn) btn.disabled = true;

    if(_createTabMode === 'room') {
        apiCall('chat.php', 'create_private_room', {room_name: name}, (d) => {
            if(btn) btn.disabled = false;
            if(d.status === 'success') {
                closeModal('createRoomModal');
                document.getElementById('createdCodeDisplay').textContent = d.invite_code;
                openModal('showCodeModal');
                fetchMessages();
            } else {
                showToast(d.message);
            }
        });
    } else {
        const chType = _createTabMode === 'channel_public' ? 'public' : 'private';
        apiCall('chat.php', 'create_channel', {room_name: name, channel_type: chType}, (d) => {
            if(btn) btn.disabled = false;
            if(d.status === 'success') {
                closeModal('createRoomModal');
                if(d.invite_code) {
                    document.getElementById('createdCodeDisplay').textContent = d.invite_code;
                    openModal('showCodeModal');
                } else {
                    showToast('کانال عمومی با موفقیت ساخته شد');
                }
                fetchMessages();
            } else {
                showToast(d.message);
            }
        });
    }
}

let foundRoom = null;
let foundCode = null;
let _roomNotFound = false;

function handleSearch(q) {
    q = q.trim();
    if(q.length === 0) {
        foundRoom = null;
        foundCode = null;
        _roomNotFound = false;
        fetchMessages();
        return;
    }
    
    if(q.length >= 6) {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            apiCall('chat.php', 'search_invite_code', {code: q}, (d) => {
                if(d.status === 'success') {
                    const isMember = allRoomsCache.some(r => r.id == d.room.id);
                    if(isMember) {
                        foundRoom = null;
                        foundCode = null;
                        _roomNotFound = false;
                    } else {
                        foundRoom = d.room;
                        foundCode = q;
                        _roomNotFound = false;
                    }
                } else {
                    foundRoom = null;
                    foundCode = null;
                    _roomNotFound = true;
                }
                fetchMessages();
            }, () => {
                foundRoom = null;
                foundCode = null;
                _roomNotFound = true;
                fetchMessages();
            });
        }, 500);
    } else {
        foundRoom = null;
        foundCode = null;
        _roomNotFound = false;
    }
    
    fetchMessages(); 
}

function joinRoomByCode(code) {
    if(!code) return;
    if(Date.now() - lastJoinAttempt < 3000) { showToast('لطفاً صبر کنید...'); return; }
    lastJoinAttempt = Date.now();

    apiCall('chat.php', 'join_via_code', {code: code}, (d) => {
        if(d.status === 'success') {
            showToast('به اتاق ' + d.room_name + ' پیوستید');
            document.querySelector('.search-input').value = '';
            foundRoom = null;
            foundCode = null;
            _roomNotFound = false;
            handleSearch('');
            switchRoom(d.room_name, d.room_name, d.room_id);
        } else {
            const inp = document.querySelector('.search-input');
            inp.classList.add('error');
            setTimeout(() => inp.classList.remove('error'), 500);
            showToast(d.message);
        }
    });
}

function copyInviteCode() {
    const code = document.getElementById('createdCodeDisplay').textContent;
    if(code) {
        const tempInput = document.createElement("input");
        tempInput.style = "position: absolute; left: -1000px; top: -1000px";
        tempInput.value = code;
        document.body.appendChild(tempInput);
        tempInput.select();
        document.execCommand("copy");
        document.body.removeChild(tempInput);
        showToast('کپی شد با موفقیت');
    }
}

function copyText(txt) {
    const tempInput = document.createElement("input");
    tempInput.style = "position: absolute; left: -1000px; top: -1000px";
    tempInput.value = txt;
    document.body.appendChild(tempInput);
    tempInput.select();
    document.execCommand("copy");
    document.body.removeChild(tempInput);
    showToast('کپی شد');
}

const mb = document.getElementById('micBtn');
const startRec = e => {
    if(e.type==='touchstart') e.preventDefault(); if(isRecording) return;
    startY = e.type.includes('touch')?e.touches[0].clientY:e.clientY;
    navigator.mediaDevices.getUserMedia({audio:true}).then(s=>{
        const options = { mimeType: 'audio/webm' };
        if (!MediaRecorder.isTypeSupported(options.mimeType)) delete options.mimeType;
        
        mediaRecorder = new MediaRecorder(s, options); 
        mediaRecorder.start(); 
        // Measured from the moment the recorder actually starts, not from the
        // click: the first call waits on the permission prompt and that wait
        // must not count towards the one-second minimum.
        recStartedAt = Date.now();
        audioChunks = [];
        mediaRecorder.ondataavailable = e => { if (e.data.size > 0) audioChunks.push(e.data); };
        
        isRecording=true; isLocked=false;
        document.getElementById('inputRow').style.display='none'; document.getElementById('voiceUI').style.display='flex';
        let sec=0; recInterval=setInterval(()=>{ sec++; document.getElementById('recTimer').textContent=new Date(sec*1000).toISOString().substr(14,5); },1000);
    }).catch(()=>showToast('دسترسی میکروفون لازم است'));
};
const checkSwipe = e => { if(!isRecording||isLocked)return; let y=e.type.includes('touch')?e.touches[0].clientY:e.clientY; if(startY-y>50){ isLocked=true; document.getElementById('sendVoiceBtn').style.display='flex'; } };
const endRec = () => { if(!isRecording||isLocked)return; stopRecManual(); };

function stopRecManual() { 
    if(mediaRecorder && mediaRecorder.state !== 'inactive'){ 
        mediaRecorder.onstop = () => { 
            // A clip shorter than one second is almost always an accidental
            // tap. Sending it puts an unlistenable 200 ms blip in the chat, so
            // the whole take is thrown away and nothing is uploaded.
            const heldMs = recStartedAt ? (Date.now() - recStartedAt) : 0;
            recStartedAt = 0;
            if (heldMs < 1000 || audioChunks.length === 0) {
                audioChunks = [];
                cancelVoice();
                showToast('پیام صوتی باید بیشتر از ۱ ثانیه باشد');
                return;
            }
            const blob = new Blob(audioChunks, { type: 'audio/webm' });
            sendMessage(blob, true); 
            cancelVoice(); 
        }; 
        mediaRecorder.stop(); 
    } 
}

function cancelVoice(){ 
    if(mediaRecorder){ 
        mediaRecorder.onstop=null; 
        if(mediaRecorder.state !== 'inactive') mediaRecorder.stop(); 
        mediaRecorder.stream.getTracks().forEach(t=>t.stop()); 
    } 
    isRecording=false; clearInterval(recInterval); 
    recStartedAt = 0;
    document.getElementById('voiceUI').style.display='none'; 
    document.getElementById('inputRow').style.display='flex'; 
    document.getElementById('sendVoiceBtn').style.display='none'; 
    document.getElementById('recTimer').textContent='00:00'; 
}

mb.addEventListener('mousedown', startRec); document.addEventListener('mouseup', endRec); document.addEventListener('mousemove', checkSwipe);
mb.addEventListener('touchstart', startRec); document.addEventListener('touchend', endRec); document.addEventListener('touchmove', checkSwipe);    if(document.getElementById('chatTitle')) {
    (function() {
        try {
            // A demo visitor always lands in the sandbox room. Whatever this
            // browser remembered from a real session (a room, a DM partner)
            // must not be restored — it would point at conversations the
            // demo account has no business seeing.
            if (VX_IS_DEMO) {
                switchRoom(VX_DEMO_ROOM, VX_DEMO_ROOM, null);
                return;
            }
            const lastTarget = localStorage.getItem('bw_last_target');
            const lastTargetName = localStorage.getItem('bw_last_target_name');
            const lastRoom = localStorage.getItem('bw_last_room');
            const lastRoomId = localStorage.getItem('bw_last_room_id');
            if (lastTarget && lastTargetName) {
                startPrivate(parseInt(lastTarget), lastTargetName);
            } else if (lastRoom && lastRoom !== 'گفتگوی عمومی') {
                switchRoom(lastRoom, lastRoom, lastRoomId ? parseInt(lastRoomId) : null);
            } else {
                switchRoom('گفتگوی عمومی', 'گفتگوی عمومی', null);
            }
        } catch(e) {
            if (VX_IS_DEMO) switchRoom(VX_DEMO_ROOM, VX_DEMO_ROOM, null);
            else switchRoom('گفتگوی عمومی', 'گفتگوی عمومی', null);
        }
    })();
    // ── Adaptive polling ────────────────────────────────────────────────────
    // With a live WebSocket a slow safety poll is enough. Without it (the usual
    // case on shared hosting / WAMP) 10 s felt like a delay, so the fallback
    // polls fast and the loop re-tunes itself whenever the socket state changes.
    _vxBoot();
    startAdaptivePolling();
    setTimeout(checkNeedsPhone, 10000);
    setTimeout(checkUpdateNotifications, 10000);
}

var _vxReady = false;
function _vxBoot() {
    if (_vxReady) return;
    if (!window.VXNet || !window.VXCache) { setTimeout(_vxBoot, 120); return; }
    _vxReady = true;
    VXNet.configure({
        endpoint: 'chat.php',
        csrf: function () { return csrfToken; },
        nonce: function () { return bwNonce; },
        mode: (window.VX_RUNTIME && VX_RUNTIME.requestMode === 'normal') ? 'normal' : 'telegram'
    });
    VXCache.configure({
        ttl: (window.VX_RUNTIME && VX_RUNTIME.cacheTtlMs) || 86400000,
        version: String((window.VX_RUNTIME && VX_RUNTIME.assetVersion) || '1'),
        epoch: (window.VX_RUNTIME && VX_RUNTIME.cacheEpoch) || 0
    });
    _vxSnapshotRestore();
    if (typeof startAdaptivePolling === 'function') startAdaptivePolling();
}

function _vxRoomKey() {
    return targetUser ? ('dm:' + targetUser) : ('room:' + (currentRoom || 'public'));
}

function _vxSnapshotSave() {
    if (!window.VXCache) return;
    try {
        var sb = document.getElementById('sidebarList');
        var ms = document.getElementById('msgsContent');
        var mb = document.getElementById('messagesBox');
        if (!sb || !ms) return;
        if (!ms.innerHTML || ms.innerHTML.length < 40) return;
        VXCache.set('snap:sidebar', sb.innerHTML);
        VXCache.set('snap:msgs:' + _vxRoomKey(), { html: ms.innerHTML, at: Date.now() });
        VXCache.set('snap:meta', { key: _vxRoomKey(), room: currentRoom, target: targetUser, at: Date.now() });
    } catch (e) {}
}

var _vxSnapTimer = null;
function _vxSnapshotSchedule() {
    if (!window.VXCache) return;
    if (_vxSnapTimer) clearTimeout(_vxSnapTimer);
    _vxSnapTimer = setTimeout(_vxSnapshotSave, 1600);
}

function _vxSnapshotRestore() {
    if (!window.VXCache) return;
    try {
        var meta = VXCache.get('snap:meta', null);
        if (!meta || meta.key !== _vxRoomKey()) return;
        var sb = VXCache.get('snap:sidebar', '');
        var ms = VXCache.get('snap:msgs:' + meta.key, null);
        var sbEl = document.getElementById('sidebarList');
        var msEl = document.getElementById('msgsContent');
        var mbEl = document.getElementById('messagesBox');
        if (sbEl && sb && !sbEl.innerHTML.trim()) sbEl.innerHTML = sb;
        if (msEl && ms && ms.html && !msEl.innerHTML.trim()) {
            msEl.innerHTML = ms.html;
            if (mbEl) mbEl.scrollTop = mbEl.scrollHeight;
        }
    } catch (e) {}
}

var _tgChannelOn = false;
var _tgPendingWake = false;
// (_chatRev is declared next to the conversation globals so it exists before
// the first fetch — see `let currentRoom` near the top of the script.)
function _startTelegramChannel() {
    if (_tgChannelOn) return;
    if (!window.VXNet) return;
    _tgChannelOn = true;
    VXNet.channel.start({
        payload: function () {
            if (document.hidden) return null;
            return {
                after_id: lastMsgId || 0,
                room: currentRoom != null ? currentRoom : 'null',
                target_user: targetUser != null ? targetUser : '',
                rev: _chatRev || '',
                timeout: 18
            };
        },
        onWake: function (d) {
            _net.lastIncomingAt = Date.now();
            // An in-place change (edit / reaction / media removal) carries no
            // new row, so it needs the full refresh — that is the only path
            // that repaints existing bubbles. A plain new message stays on the
            // cheap incremental path.
            var inPlace = !!(d && d.reason === 'update');
            if (inPlace && typeof d.rev === 'string' && d.rev !== '') _chatRev = d.rev;
            // Never discard a wake. If a refresh is already running, mark the
            // state dirty so it replays the instant that request settles.
            if (_fetchInFlight) { _tgPendingWake = true; _fetchDirty = true; return; }
            fetchMessages(null, inPlace ? undefined : { incremental: true });
        }
    });
}

function _stopTelegramChannel() {
    _tgChannelOn = false;
    if (window.VXNet) VXNet.channel.stop();
}

function _drainPendingWake() {
    if (!_tgPendingWake) return;
    _tgPendingWake = false;
    if (document.hidden) return;
    // fetchMessages() now queues itself when a request is in flight, so there
    // is no reason to check _fetchInFlight here and lose the wake.
    setTimeout(function () {
        if (!document.hidden) fetchMessages(null, { incremental: true });
    }, 140);
}

var _pollTimer = null;
function _wsIsLive() {
    try { return !!(window.veyraxWs && window.veyraxWs.isConnected && window.veyraxWs.isConnected()); } catch (e) { return false; }
}
var _pollLat = [];
function _notePollLatency(ms) {
    if (!_pollLat) _pollLat = [];
    if (!isFinite(ms) || ms <= 0) return;
    _pollLat.push(ms);
    if (_pollLat.length > 7) _pollLat.shift();
}
function _pollLoadFactor() {
    if (!_pollLat || _pollLat.length < 3) return 1;
    var s = _pollLat.slice().sort(function (a, b) { return a - b; });
    var med = s[Math.floor(s.length / 2)];
    if (med > 1500) return 2.4;
    if (med > 800) return 1.8;
    if (med > 400) return 1.35;
    if (med > 250) return 1.15;
    return 1;
}
function _pollInterval() {
    if (document.hidden) return 20000;
    if (navigator.onLine === false) return 5000;
    // The long-poll channel is the real listener whenever it runs, so the
    // periodic fetch is only a safety net behind it — it does not need to be
    // fast, and it must never be the thing that carries a message.
    if (_tgChannelOn) return 10000 + Math.round(Math.random() * 2500);
    if (_wsIsLive()) return 15000;
    // No socket and no channel: this is the slow path. Keep it as short as the
    // load allows, because every millisecond here is visible to the user.
    var idle = Date.now() - (_net.lastIncomingAt || Date.now());
    var base = idle > 120000 ? 4000 : (idle > 45000 ? 3000 : 2500);
    base = Math.min(6000, Math.round(base * _pollLoadFactor()));
    var blocked = _net.backoffUntil - Date.now();
    if (blocked > base) base = blocked;
    return base + Math.round(Math.random() * 250); // jitter: no thundering herd
}

// ── Transport selection ──────────────────────────────────────────────────────
// The old code picked a transport once, at page load, and never looked again.
// If the socket was up at that moment the long-poll was never started — and
// when the socket later died (server restart, sleep, Wi-Fi blip) the tab fell
// back to a 3–10 s poll. That is exactly the "4–5 seconds, even on websocket"
// the user was seeing. The transport is now re-evaluated continuously.
var _transport = '';
var _transportWatch = null;

function _desiredTransport() {
    if (!window.VXNet) return 'poll';
    // The long-poll is kept running even while the socket is healthy. It costs
    // one idle request per 18 s and it is the only thing that guarantees a
    // message is noticed when a socket goes half-open — the failure mode where
    // the client still believes it is connected but nothing is arriving.
    return 'longpoll';
}

function _applyTransport(force) {
    var want = _desiredTransport();
    if (!force && want === _transport) return;
    var prev = _transport;
    _transport = want;
    if (want === 'longpoll') _startTelegramChannel();
    else _stopTelegramChannel();
    // A transport change is a hole in the stream: close it immediately.
    if (prev && prev !== want && !document.hidden) {
        fetchMessages(null, { incremental: true });
    }
}

function startAdaptivePolling() {
    if (_pollTimer) { clearTimeout(_pollTimer); _pollTimer = null; }
    _transport = '';
    _applyTransport(true);
    if (_transportWatch) clearInterval(_transportWatch);
    // Cheap local state check — no network — so a dropped socket is noticed in
    // about a second instead of at the next poll.
    _transportWatch = setInterval(function () {
        if (document.hidden) return;
        _applyTransport(false);
    }, 1000);
    (function loop() {
        _pollTimer = setTimeout(function () {
            // The safety net poll must never be skipped either — a skipped
            // tick can leave a message invisible for a whole cycle.
            if (!document.hidden && !_phoneAuthModalOpen && navigator.onLine !== false) {
                fetchMessages(null, { incremental: true });
            }
            loop();
        }, _pollInterval());
    })();
}

window.addEventListener('online', function() {
    _netOk();
    _net.lastIncomingAt = Date.now();
    fetchMessages();
});
window.addEventListener('offline', function() {
    _netBackoff(10, true);
});

var _lastOfflineBeacon = 0;
function sendBeaconStatus(status) {
    // beforeunload and visibilitychange both fire on a tab switch; one beacon
    // is enough, and each one is a real UPDATE on the users row.
    if (status === 'set_offline') {
        if (Date.now() - _lastOfflineBeacon < 3000) return;
        _lastOfflineBeacon = Date.now();
    }
    const fd = new FormData();
    fd.append('action', status);
    fd.append('csrf_token', csrfToken);
    navigator.sendBeacon('chat.php', fd);
}

document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'hidden') {
        if (window.VXNet) VXNet.pause();
        sendBeaconStatus('set_offline');
    } else {
        // Coming back to the tab is the one moment where an immediate refresh
        // matters — clear any backoff so the user never stares at stale text.
        _netOk();
        _net.lastIncomingAt = Date.now();
        if (window.VXNet) VXNet.resume();
        fetchMessages();
    }
});

window.addEventListener('beforeunload', function() {
    sendBeaconStatus('set_offline');
});

function openReportModal() {
    openModal('reportUserModal');
    document.getElementById('reportTargetUser').value = '';
    document.getElementById('reportReason').value = '';
    document.getElementById('reportUserSuggestions').innerHTML = '';
    document.getElementById('reportUserSuggestions').classList.remove('show');
    document.getElementById('reportCaptchaInput').value = '';
    updateReportReasonCount();
    refreshReportCaptcha();
}

// Live character counter under the reason box. Persian digits, so it matches
// the rest of the UI instead of showing latin numbers.
function updateReportReasonCount() {
    const ta = document.getElementById('reportReason');
    const out = document.getElementById('reportReasonCount');
    if (!ta || !out) return;
    const max = parseInt(ta.getAttribute('maxlength') || '500', 10);
    const fa = (n) => String(n).replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
    out.textContent = fa(ta.value.length) + ' / ' + fa(max);
    out.style.color = ta.value.length > max * 0.9 ? '#f87171' : '#5b6b82';
}
document.getElementById('reportReason').addEventListener('input', updateReportReasonCount);

function refreshReportCaptcha(btn) {
    if (!btn) btn = document.querySelector('#reportUserModal .refresh-btn');
    if (btn) {
        btn.classList.remove('rotating');
        void btn.offsetWidth;
        btn.classList.add('rotating');
        setTimeout(() => btn.classList.remove('rotating'), 760);
    }
    const box = document.getElementById('reportCaptchaBox');
    if (box) box.classList.add('captcha-loading');

    const fd = new FormData();
    fd.append('action', 'refresh_report_captcha');
    fd.append('csrf_token', csrfToken);
    fetch('requests.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
        if(d.status === 'success') {
            if (box) {
                box.textContent = `${d.n1} + ${d.n2}`;
                box.classList.remove('captcha-loading');
                box.classList.add('captcha-pop');
                setTimeout(() => box.classList.remove('captcha-pop'), 420);
            }
            const inp = document.getElementById('reportCaptchaInput');
            if (inp) inp.value = '';
        }
    })
    .catch(() => { if (box) box.classList.remove('captcha-loading'); });
}

function submitReportUser() {
    const target = document.getElementById('reportTargetUser').value;
    const reason = document.getElementById('reportReason').value;
    const captcha = document.getElementById('reportCaptchaInput').value;
    const btn = document.getElementById('btnReportSend');

    if (!target || !reason) {
        showToast('لطفاً نام کاربر و دلیل را وارد کنید');
        return;
    }
    if (!captcha) {
        showToast('لطفاً کد امنیتی را وارد کنید');
        return;
    }

    const originalText = btn.textContent;
    const resetBtn = () => { btn.textContent = originalText; btn.disabled = false; btn.style.opacity = '1'; };

    btn.textContent = 'در حال ارسال...';
    btn.disabled = true;
    btn.style.opacity = '0.7';

    // One handler for every outcome: apiCall routes errors (wrong captcha,
    // flood, JSON/network failure) to errCb — without it the button stayed
    // stuck on "sending" until a page refresh.
    const handle = (d) => {
        resetBtn();
        if (d && d.status === 'success') {
            showToast(d.message);
            closeModal('reportUserModal');
            document.getElementById('reportTargetUser').value = '';
            document.getElementById('reportReason').value = '';
            document.getElementById('reportCaptchaInput').value = '';
            document.getElementById('reportUserSuggestions').innerHTML = '';
            refreshReportCaptcha();
        } else {
            showToast((d && d.message) || 'خطا در ارسال گزارش؛ دوباره تلاش کنید');
            refreshReportCaptcha();
            document.getElementById('reportCaptchaInput').value = '';
        }
    };
    apiCall('requests.php', 'report_user', { target_username: target, reason: reason, captcha: captcha }, handle, handle);
}

document.getElementById('reportTargetUser').addEventListener('keyup', function() {
    this.value = this.value.replace(/\s{2,}/g, ' ');
    const val = this.value.toLowerCase();
    const sug = document.getElementById('reportUserSuggestions');
    sug.innerHTML = '';
    sug.classList.remove('show');
    
    if (val.length < 1) return;

    if (allUsersCache) {
        let hasMatches = false;
        allUsersCache.forEach(u => {
            if (u.username.toLowerCase().includes(val) && u.username !== currentUsername) {
                hasMatches = true;
                const div = document.createElement('div');
                div.className = 'suggestion-item';
                div.innerHTML = `<span style="font-size:16px">${u.sticker}</span> ${u.username}`;
                div.onclick = () => {
                    document.getElementById('reportTargetUser').value = u.username;
                    sug.innerHTML = '';
                    sug.classList.remove('show');
                };
                sug.appendChild(div);
            }
        });
        if(hasMatches) sug.classList.add('show');
    }
});
document.addEventListener('click', function(e) {
    if (!e.target.closest('#reportTargetUser') && !e.target.closest('#reportUserSuggestions')) {
        document.getElementById('reportUserSuggestions').classList.remove('show');
    }
});

document.addEventListener('paste', function(e) {
    const items = e.clipboardData && e.clipboardData.items;
    if(!items) return;
    for(let i = 0; i < items.length; i++) {
        if(items[i].type.startsWith('image/')) {
            const file = items[i].getAsFile();
            if(!file) continue;
            e.preventDefault();
            const ext = items[i].type.split('/')[1] || 'png';
            const named = new File([file], `screenshot_${Date.now()}.${ext}`, { type: items[i].type });
            if(named.size > MAX_FILE_SIZE) { showToast('حجم تصویر بیشتر از حد مجاز است'); return; }
            uploadQueue.push(named);
            processUploadQueue();
            showToast('تصویر کلیپ‌بورد آماده ارسال شد');
            return;
        }
    }
});

const dragOverlay = document.getElementById('dragOverlay');
let dragCounter = 0;

document.body.addEventListener('dragenter', (e) => {
    e.preventDefault();
    dragCounter++;
    dragOverlay.classList.add('active');
});

document.body.addEventListener('dragleave', (e) => {
    e.preventDefault();
    dragCounter--;
    if (dragCounter === 0) {
        dragOverlay.classList.remove('active');
    }
});

document.body.addEventListener('dragover', (e) => {
    e.preventDefault();
});

document.body.addEventListener('drop', (e) => {
    e.preventDefault();
    dragCounter = 0;
    dragOverlay.classList.remove('active');
    
    if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
        if(e.dataTransfer.files.length > 5) {
            showToast('حداکثر ۵ فایل می‌توانید انتخاب کنید');
            return;
        }

        const dt = new DataTransfer();
        for(let i=0; i<e.dataTransfer.files.length; i++) {
            if (e.dataTransfer.files[i].size > MAX_FILE_SIZE) {
                showToast(`حجم فایل ${e.dataTransfer.files[i].name} بیشتر از حد مجاز است`);
                continue;
            }
            dt.items.add(e.dataTransfer.files[i]);
        }
        
        if (dt.files.length > 0) {
            document.getElementById('fileInput').files = dt.files;
            document.getElementById('fileInput').onchange();
        }
    }
});

</script>
<script>
window._veyraxRemoteTypers = {};
function showRemoteTyping(data) {
    if (!data || !data.user_id || data.user_id == currentUser) return;
    var relevant = false;
    if (data.target_user && parseInt(data.target_user, 10) === parseInt(currentUser, 10)) relevant = true;
    if (!data.target_user && data.room && data.room === currentRoom && !targetUser) relevant = true;
    if (!relevant) return;
    window._veyraxRemoteTypers[data.user_id] = { name: data.username || '...', at: Date.now() };
}
function hideRemoteTyping(data) {
    if (data && data.user_id) delete window._veyraxRemoteTypers[data.user_id];
}
function applyPresenceUpdate(data) {
    if (!data || typeof data.user_id === 'undefined') return;
    var uid = parseInt(data.user_id, 10);
    var cached = (typeof allUsersCache !== 'undefined') ? allUsersCache : null;
    if (cached) {
        var u = cached.find(function(x) { return parseInt(x.id, 10) === uid; });
        if (u) u.is_online = data.online ? 1 : 0;
    }
}
function applyReadReceipt(data) {
    if (!data || !data.reader_id) return;
    if (typeof targetUser !== 'undefined' && targetUser && parseInt(data.reader_id, 10) === parseInt(targetUser, 10)) {
        document.querySelectorAll('.msg.mine .seen-icon').forEach(function(el) { el.classList.add('seen'); });
    }
}
</script>
<style>
/* ── All-users sheet ───────────────────────────────────────────────────────
   Opened from the "مشاهده همه" link under the online list. Centred card on
   desktop, full-screen on phones; rows stream in page by page as you scroll. */
#allUsersModal {
    position: fixed; inset: 0; z-index: 9950; display: none;
    align-items: center; justify-content: center; padding: 18px;
    background: rgba(6, 10, 20, 0.78);
    -webkit-backdrop-filter: blur(14px); backdrop-filter: blur(14px);
}
#allUsersModal .au-card {
    width: 100%; max-width: 560px; height: min(82vh, 720px);
    display: flex; flex-direction: column; overflow: hidden;
    border-radius: 26px;
    border: 1px solid var(--v-primary-border, rgba(99,102,241,.34));
    background:
        radial-gradient(120% 55% at 50% -6%, var(--v-primary-soft, rgba(99,102,241,.18)), transparent 60%),
        linear-gradient(165deg, #1b2540, #121a2c);
    box-shadow: 0 34px 80px -26px rgba(0,0,0,.9), inset 0 1px 0 rgba(255,255,255,.05);
    animation: auIn .26s cubic-bezier(.2,.9,.3,1.2) both;
}
@keyframes auIn { from { opacity: 0; transform: translateY(12px) scale(.985); } to { opacity: 1; transform: none; } }

#allUsersModal .au-head { display: flex; align-items: center; gap: 10px; padding: 15px 17px 12px; border-bottom: 1px solid rgba(255,255,255,.07); }
#allUsersModal .au-mark {
    flex: 0 0 36px; width: 36px; height: 36px; border-radius: 12px;
    display: grid; place-items: center;
    background: linear-gradient(160deg, rgba(255,255,255,.10), rgba(255,255,255,.02));
    border: 1px solid var(--v-primary-border, rgba(99,102,241,.30));
}
#allUsersModal .au-mark img { width: 21px; height: 21px; object-fit: contain; }
#allUsersModal .au-title { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; }
#allUsersModal .au-title b { font-size: 14.5px; color: #eaf0fb; font-family: 'AppBold', inherit; }
#allUsersModal .au-title small { font-size: 10.5px; color: #7f92ad; }
#allUsersModal .au-count {
    flex: 0 0 auto; padding: 4px 10px; border-radius: 999px;
    font-size: 11px; font-weight: bold; font-variant-numeric: tabular-nums;
    color: var(--v-accent, #a5b4fc);
    background: var(--v-glow-soft, rgba(99,102,241,.12));
    border: 1px solid var(--v-soft, rgba(99,102,241,.22));
}
#allUsersModal .au-x {
    flex: 0 0 30px; width: 30px; height: 30px; border-radius: 10px; cursor: pointer;
    border: 1px solid rgba(255,255,255,.10); background: rgba(255,255,255,.05);
    color: #94a3b8; font-size: 13px; display: grid; place-items: center;
    transition: background .15s ease, color .15s ease, border-color .15s ease;
}
#allUsersModal .au-x:hover { background: rgba(239,68,68,.14); color: #fca5a5; border-color: rgba(239,68,68,.35); }

#allUsersModal .au-search { position: relative; padding: 12px 17px 6px; }
#allUsersModal .au-search input {
    width: 100%; padding-block: 11px; padding-inline-start: 36px; padding-inline-end: 14px;
    border-radius: 13px;
    border: 1px solid var(--v-primary-border, rgba(99,102,241,.24));
    background: rgba(255,255,255,.045); color: #e2e8f0;
    font-size: 12.5px; font-family: inherit; outline: none;
    transition: border-color .16s ease, box-shadow .16s ease;
}
#allUsersModal .au-search input:focus {
    border-color: var(--v-primary, #6366f1);
    box-shadow: 0 0 0 3px var(--v-primary-soft, rgba(99,102,241,.18));
}
#allUsersModal .au-search input::placeholder { color: #5b6c85; }
#allUsersModal .au-si {
    position: absolute; inset-inline-start: 30px; top: 50%; transform: translateY(-40%);
    width: 15px; height: 15px; object-fit: contain; opacity: .55; pointer-events: none;
}

#allUsersModal .au-list { flex: 1 1 auto; overflow-y: auto; padding: 6px 10px 8px; overscroll-behavior: contain; }
#allUsersModal .au-row {
    display: flex; align-items: center; gap: 11px;
    padding: 8px 9px; border-radius: 14px; cursor: pointer;
    transition: background .15s ease;
}
#allUsersModal .au-row:hover { background: rgba(148,163,184,.09); }
#allUsersModal .au-row:active { background: rgba(148,163,184,.14); }
#allUsersModal .au-av {
    position: relative; flex: 0 0 40px; width: 40px; height: 40px; border-radius: 13px;
    display: grid; place-items: center; font-size: 20px;
    background: linear-gradient(160deg, rgba(255,255,255,.10), rgba(255,255,255,.03));
    border: 1px solid rgba(255,255,255,.09);
}
#allUsersModal .au-dot {
    position: absolute; inset-inline-end: -2px; bottom: -2px;
    width: 11px; height: 11px; border-radius: 50%;
    background: #64748b; border: 2px solid #121a2c;
}
#allUsersModal .au-row.online .au-dot { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.16); }
#allUsersModal .au-info { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 1px; }
#allUsersModal .au-name { font-size: 12.5px; color: #e6edf9; font-weight: bold; display: flex; align-items: center; gap: 5px; min-width: 0; }
#allUsersModal .au-name span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
#allUsersModal .au-name img { flex: 0 0 13px; width: 13px; height: 13px; }
#allUsersModal .au-sub { font-size: 10.5px; color: #7f92ad; }
#allUsersModal .au-row.online .au-sub { color: #4ade80; }
#allUsersModal .au-badge {
    flex: 0 0 auto; min-width: 20px; height: 20px; padding: 0 6px;
    border-radius: 999px; display: grid; place-items: center;
    background: linear-gradient(180deg, #f05252, #dc2626); color: #fff;
    font-size: 10.5px; font-weight: bold; font-variant-numeric: tabular-nums;
    box-shadow: 0 3px 9px -3px rgba(239,68,68,.75);
}
#allUsersModal .au-empty, #allUsersModal .au-load { text-align: center; padding: 26px 12px; font-size: 12px; color: #64748b; }
#allUsersModal .au-spin {
    display: inline-block; width: 13px; height: 13px; margin-inline-end: 6px;
    border-radius: 50%; border: 2px solid rgba(148,163,184,.25);
    border-top-color: var(--v-primary, #6366f1);
    animation: auSpin .75s linear infinite; vertical-align: -2px;
}
@keyframes auSpin { to { transform: rotate(360deg); } }
#allUsersModal .au-foot { padding: 8px 14px 12px; text-align: center; font-size: 11px; color: #64748b; min-height: 20px; }

/* Sidebar trigger */
.see-all-users {
    border: 1px solid var(--v-soft, rgba(99,102,241,.22));
    background: var(--v-glow-soft, rgba(99,102,241,.10));
    color: var(--v-accent, #a5b4fc);
    font-family: inherit; font-size: 10.5px; font-weight: bold;
    padding: 4px 9px; border-radius: 999px; cursor: pointer;
    transition: background .15s ease, color .15s ease;
    -webkit-tap-highlight-color: transparent;
}
.see-all-users:hover { background: var(--v-active-soft, rgba(99,102,241,.26)); color: #fff; }

@media (max-width: 520px) {
    #allUsersModal { padding: 0; }
    #allUsersModal .au-card { max-width: none; height: 100dvh; border-radius: 0; border: 0; }
    #allUsersModal .au-list { padding-bottom: 18px; }
}
@media (prefers-reduced-motion: reduce) {
    #allUsersModal .au-card { animation: none; }
    #allUsersModal .au-spin { animation-duration: 2s; }
}
</style>

<div id="allUsersModal">
    <div class="au-card" role="dialog" aria-modal="true" aria-labelledby="auTitle">
        <div class="au-head">
            <span class="au-mark"><img src="assets/icons3d/user.png" alt=""></span>
            <span class="au-title">
                <b id="auTitle">همه کاربران</b>
                <small>برای گفتگو انتخاب کنید یا نام کاربری را جست‌وجو کنید</small>
            </span>
            <span class="au-count" id="auCount">۰</span>
            <button type="button" class="au-x" onclick="closeAllUsers()" aria-label="بستن">✕</button>
        </div>
        <div class="au-search">
            <input type="text" id="auSearchInput" placeholder="جست‌وجوی نام کاربری…" autocomplete="off" oninput="auOnSearch()">
            <img class="au-si" src="assets/icons3d/search.png" alt="">
        </div>
        <div class="au-list" id="auList"></div>
        <div class="au-foot" id="auFoot"></div>
    </div>
</div>

<script>
/* ── All-users sheet: paged, searchable, lazily loaded ─────────────────── */
var _auState = { page: 1, pages: 1, q: '', loading: false, items: [], total: 0 };
var _auRendered = 0;
var _auBound = false;

function auFa(n) {
    return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; });
}

function openAllUsers() {
    var m = document.getElementById('allUsersModal');
    if (!m) return;
    _auState = { page: 1, pages: 1, q: '', loading: false, items: [], total: 0 };
    _auRendered = 0;
    var inp = document.getElementById('auSearchInput');
    if (inp) inp.value = '';
    var list = document.getElementById('auList');
    if (list) { list.innerHTML = ''; list.scrollTop = 0; }
    var foot = document.getElementById('auFoot');
    if (foot) foot.textContent = '';
    m.style.display = 'flex';

    if (!_auBound && list) {
        _auBound = true;
        list.addEventListener('scroll', function () {
            if (this.scrollTop + this.clientHeight >= this.scrollHeight - 140) loadAllUsers(false);
        });
    }
    loadAllUsers(true);
}

function closeAllUsers() {
    var m = document.getElementById('allUsersModal');
    if (m) m.style.display = 'none';
}

function auOnSearch() {
    clearTimeout(window._auTimer);
    window._auTimer = setTimeout(function () {
        var el = document.getElementById('auSearchInput');
        _auState.q = el ? el.value.trim() : '';
        _auState.page = 1;
        _auState.items = [];
        _auRendered = 0;
        var list = document.getElementById('auList');
        if (list) list.innerHTML = '';
        loadAllUsers(true);
    }, 300);
}

function loadAllUsers(reset) {
    if (_auState.loading) return;
    if (!reset && _auState.page > _auState.pages) return;
    _auState.loading = true;

    var foot = document.getElementById('auFoot');
    if (foot) foot.innerHTML = '<span class="au-spin"></span>در حال بارگذاری…';

    var fd = new FormData();
    fd.append('action', 'list_users');
    fd.append('csrf_token', csrfToken);
    fd.append('page', _auState.page);
    fd.append('per', 20);
    fd.append('q', _auState.q);

    fetch('chat.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            _auState.loading = false;
            if (!d || d.status !== 'success') {
                if (foot) foot.textContent = 'خطا در دریافت فهرست کاربران';
                return;
            }
            _auState.pages = d.pages || 1;
            _auState.total = d.total || 0;
            _auState.items = _auState.items.concat(d.users || []);
            _auState.page = (d.page || 1) + 1;
            renderAllUsers();
            if (foot) {
                foot.textContent = _auState.page > _auState.pages
                    ? (_auState.total ? 'همه کاربران نمایش داده شد' : '')
                    : '';
            }
        })
        .catch(function () {
            _auState.loading = false;
            if (foot) foot.textContent = 'خطای شبکه — دوباره تلاش کنید';
        });
}

function renderAllUsers() {
    var list = document.getElementById('auList');
    if (!list) return;

    var countEl = document.getElementById('auCount');
    if (countEl) countEl.textContent = auFa(_auState.total);

    if (_auState.items.length === 0) {
        list.innerHTML = '<div class="au-empty">کاربری با این نام پیدا نشد</div>';
        _auRendered = 0;
        return;
    }
    if (_auRendered === 0) list.innerHTML = '';

    var html = '';
    for (var i = _auRendered; i < _auState.items.length; i++) {
        var u = _auState.items[i];
        var name = String(u.display_name || u.username || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        var crown = u.is_admin ? '<img src="assets/icons3d/crown.png" alt="">' : '';
        var me = u.is_me ? ' <span style="font-size:10px;color:#7f92ad;">(شما)</span>' : '';
        var badge = u.unread ? '<span class="au-badge">' + (u.unread > 99 ? '99+' : u.unread) + '</span>' : '';
        html += '<div class="au-row' + (u.is_online ? ' online' : '') + '" onclick="auPick(' + u.id + ', \'' + name + '\')">' +
                    '<span class="au-av">' + (u.sticker || '👤') + '<span class="au-dot"></span></span>' +
                    '<span class="au-info">' +
                        '<span class="au-name">' + crown + '<span>' + name + '</span>' + me + '</span>' +
                        '<span class="au-sub">' + (u.is_online ? 'آنلاین' : 'آفلاین') + '</span>' +
                    '</span>' + badge +
                '</div>';
    }
    list.insertAdjacentHTML('beforeend', html);
    _auRendered = _auState.items.length;
}

function auPick(id, name) {
    closeAllUsers();
    if (typeof startPrivate === 'function') startPrivate(id, name);
}

document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var m = document.getElementById('allUsersModal');
    if (m && m.style.display === 'flex') closeAllUsers();
});

// Sidebar (rooms + users + unread badges) is rendered from the latest fetch
// payload. It used to live inline in _processFetchData, which meant the
// incremental poll never refreshed it — a message from another user only
// showed up after an interaction forced a full fetch.
var _sidebarCache = { rooms: [], invites: [] };
function _renderSidebarFromData(d) {
    if (d && d.rooms) _sidebarCache.rooms = d.rooms;
    if (d && d.my_invites) _sidebarCache.invites = d.my_invites;
    if (d) {
        d.rooms = d.rooms || _sidebarCache.rooms || [];
        d.my_invites = d.my_invites || _sidebarCache.invites || [];
    }
        const list = document.getElementById('sidebarList');
        if (!list) return;
        const q = document.querySelector('.search-input').value.toLowerCase();

        let publicRoomId = null;
        if(d.rooms) { 
            allRoomsCache = d.rooms;
            d.rooms.forEach(r => { if(r.name === 'گفتگوی عمومی' || r.name === 'public') publicRoomId = r.id; });
            if (currentRoom && !targetUser) _updateChannelInputVisibility(currentRoom);
        }
        if (currentRoom === 'گفتگوی عمومی' && !currentRoomId && publicRoomId) currentRoomId = publicRoomId;
        if (VX_IS_DEMO && !currentRoomId) {
            const _dr = (d.rooms || []).find(r => r.name === VX_DEMO_ROOM);
            if (_dr) { currentRoom = _dr.name; currentRoomId = _dr.id; }
        }

        if (d.my_invites) {
            d.my_invites.forEach(invRoom => {
                if (!myInvites.includes(invRoom)) {
                    myInvites.push(invRoom);
                    let r = d.rooms.find(x => x.name === invRoom);
                    if (r) {
                        showNotification(0, "دعوت سیستمی", "📩", `شما به اتاق "${invRoom}" دعوت شدید`, null, invRoom);
                        notifData = { type: 'room_invite', room: invRoom, roomId: r.id }; 
                        switchRoom(invRoom, invRoom, r.id); 
                    }
                }
            });
        }



        let pubCount = roomUnreadLocal['گفتگوی عمومی'] || 0;
        let publicRoomUnread = '';
        if(pubCount > 0 && currentRoom !== 'گفتگوی عمومی') {
            let pDispCount = vxBadge(pubCount);
            publicRoomUnread = `<span class="unread-badge">${pDispCount}</span>`;
        }
        const isPublicActive = currentRoom === 'گفتگوی عمومی' && !targetUser ? 'active' : '';
        let cat1Html = '';
        if('گفتگوی عمومی'.includes(q) || 'public'.includes(q)) {
            cat1Html = `<div class="item ${isPublicActive}" onclick="switchRoom('گفتگوی عمومی', 'گفتگوی عمومی', ${publicRoomId})" oncontextmenu="roomCtxMenu(event,'گفتگوی عمومی',${publicRoomId})">
                <div class="item-row" style="align-items:center;">
                    <div style="display:flex;flex-direction:column;min-width:0;flex:1;">
                        <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:bold;">🌐 گفتگوی عمومی</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex-shrink:0;">${publicRoomUnread}</div>
                </div>
            </div>`;
        }

        let cat2Html = '';
        let cat3Html = '';
        let cat4Html = '';

        if(d.rooms) {
            d.rooms.forEach(r => {
                if(r.name === 'گفتگوی عمومی' || r.name === 'public') return;
                if(!r.name.toLowerCase().includes(q)) return;
                const rKind = r.room_kind || 'room';
                const isActive = currentRoom === r.name && !targetUser ? 'active' : '';
                let unreadBadge = '';
                let rc = roomUnreadLocal[r.name] || 0;
                if(rc > 0 && r.name !== currentRoom) unreadBadge = `<span class="unread-badge">${vxBadge(rc)}</span>`;
                let codeDisplay = '';
                if(r.invite_code && r.creator === currentUsername) {
                    codeDisplay = `<span class="room-code-badge" onclick="event.stopPropagation();copyText('${r.invite_code}')">${r.invite_code}</span>`;
                }
                let creatorHtml = r.creator ? `<span style="font-size:10px;opacity:0.6;margin-top:2px;display:flex;align-items:center;gap:4px;"><img class="i3d" style="width:11px;height:11px;vertical-align:0" src="assets/icons3d/user.png" alt="">${r.creator}</span>` : '';
                let icon = '';
                if(rKind === 'channel' && r.type === 'public') icon = '<img class="i3d i3d-sm" src="assets/icons3d/megaphone.png" alt="">';
                else if(rKind === 'channel' && r.type === 'private') icon = '<img class="i3d i3d-sm" src="assets/icons3d/seccode.png" alt="">';
                else if(r.type === 'private') icon = '<img class="i3d i3d-sm" src="assets/icons3d/lock.png" alt="">';
                else icon = '<img class="i3d i3d-sm" src="assets/icons3d/chat.png" alt="">';
                const isMyRoom = (r.creator === currentUsername);
                const deleteRoomBtn = isMyRoom ? `<button onclick="event.stopPropagation();deleteMyRoom(${r.id},'${r.name.replace(/'/g,"\\'")}')" style="width:22px;height:22px;border-radius:6px;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#f87171;font-size:11px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:0.18s;" title="حذف اتاق"><img class="i3d-sm" src="assets/icons3d/trash.png" alt=""></button>` : '';
                const itemHtml = `<div class="item ${isActive}" onclick="switchRoom('${r.name}','${r.name}',${r.id})" oncontextmenu="roomCtxMenu(event,'${r.name}',${r.id})">
                    <div class="item-row" style="align-items:center;">
                        <div style="display:flex;flex-direction:column;min-width:0;flex:1;">
                            <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:bold;">${icon} ${r.name}</span>
                            ${creatorHtml}
                        </div>
                        <div style="display:flex;align-items:center;gap:6px;flex-shrink:0;">${deleteRoomBtn}${codeDisplay}${unreadBadge}</div>
                    </div>
                </div>`;
                if(rKind === 'channel' && r.type === 'public') cat2Html += itemHtml;
                else if(rKind === 'channel' && r.type === 'private') cat3Html += itemHtml;
                else cat4Html += itemHtml;
            });
        }

        if(foundRoom && !cat4Html.includes(foundRoom.name) && !cat2Html.includes(foundRoom.name) && !cat3Html.includes(foundRoom.name)) {
            cat4Html += `<div class="found-room-card" onclick="joinRoomByCode('${foundCode}')">
                <div class="found-room-content">
                    <div class="found-room-info">
                        <span class="found-room-name">${foundRoom.name}</span>
                        <span style="font-size:10px;color:#a7f3d0">پیوستن با کد دعوت</span>
                    </div>
                    <span class="found-room-action">عضویت +</span>
                </div>
            </div>`;
        } else if(_roomNotFound && document.querySelector('.search-input').value.trim().length >= 6) {
            cat4Html += `<div style="margin:8px 0;padding:12px 14px;background:rgba(239,68,68,0.08);border:1px solid rgba(239,68,68,0.25);border-radius:12px;text-align:center;">
                <div style="font-size:13px;color:#f87171;font-weight:bold;margin-bottom:3px;">اتاقی یافت نشد</div>
                <div style="font-size:11px;color:#6b7280;">کد دعوت وارد شده معتبر نیست</div>
            </div>`;
        }

        // A demo visitor sees exactly one room. The public room, channels and
        // private rooms are not "hidden" — they are simply not built.
        let html = VX_IS_DEMO ? '' : _makeCategory('public', 'گفتگوی عمومی', cat1Html, 'chat');
        if(!VX_IS_DEMO && cat2Html) html += _makeCategory('pub_channels', 'کانال‌های عمومی', cat2Html, 'megaphone');
        if(!VX_IS_DEMO && cat3Html) html += _makeCategory('priv_channels', 'کانال‌های خصوصی', cat3Html, 'seccode');
        if(VX_IS_DEMO) {
            html += _makeCategory('demo_room', 'اتاق نمایشی', cat4Html || '<div style="padding:6px 14px;font-size:12px;opacity:0.4;">اتاقی وجود ندارد</div>', 'chat');
        } else {
            html += _makeCategory('priv_rooms', 'اتاق‌های خصوصی', cat4Html || '<div style="padding:6px 14px;font-size:12px;opacity:0.4;">اتاقی وجود ندارد</div>', 'lock');
        }


        html += '<div class="list-header" style="display:flex;align-items:center;justify-content:space-between;gap:8px;">' +
                    '<span>کاربران آنلاین</span>' +
                    (VX_IS_DEMO ? '' : '<button type="button" class="see-all-users" onclick="openAllUsers()">مشاهده همه</button>') +
                '</div>';
        allUsersCache = d.users;
        
        const adminUser = '<?php echo $admin_username; ?>';
        
        // اضافه کردن کاربران جدید به ترتیب پایدار
        d.users.forEach(u => {
            if(u.id == currentUser) return;
            if(!_stableUserOrder.includes(u.id)) {
                _stableUserOrder.push(u.id);
            }
        });
        // ادمین همیشه اول
        const adminObj = d.users.find(u => u.username === adminUser && u.id != currentUser);
        if(adminObj && _stableUserOrder[0] !== adminObj.id) {
            _stableUserOrder = _stableUserOrder.filter(id => id !== adminObj.id);
            _stableUserOrder.unshift(adminObj.id);
        }
        
        // مرتب‌سازی: کاربران با پیام خوانده‌نشده اول، بقیه ترتیب پایدار
        const sortedUsers = _stableUserOrder
            .map(id => d.users.find(u => u.id == id))
            .filter(Boolean)
            .sort((a, b) => {
                if(a.id == currentUser) return 1;
                if(b.id == currentUser) return -1;
                // ادمین همیشه اول
                if(a.username === adminUser) return -1;
                if(b.username === adminUser) return 1;
                const aUnread = d.unread[a.id] || 0;
                const bUnread = d.unread[b.id] || 0;
                // کاربران با پیام خوانده نشده بالا
                if(aUnread > 0 && bUnread === 0) return -1;
                if(bUnread > 0 && aUnread === 0) return 1;
                // اگر هر دو پیام دارند یا هیچکدام، ترتیب پایدار (بر اساس index در _stableUserOrder)
                return _stableUserOrder.indexOf(a.id) - _stableUserOrder.indexOf(b.id);
            });
        
        sortedUsers.forEach(u => {
            if(u.id == currentUser) return;
            const _displayName = u.display_name || u.username;
            if(!String(_displayName).toLowerCase().includes(q) && !String(u.username).toLowerCase().includes(q)) return;

            const currentUnread = d.unread[u.id] || 0;
            const prevUnread = lastUnreadCounts[u.id] || 0;

            if (currentUnread > prevUnread && targetUser != u.id) {
                showNotification(u.id, _displayName, u.sticker, "پیام جدید", null, null);
            }
            lastUnreadCounts[u.id] = currentUnread;

            const isActive = targetUser == u.id ? 'active' : '';
            let dispUnread = vxBadge(currentUnread);
            const unread = (currentUnread && targetUser != u.id) ? `<span class="unread-badge">${dispUnread}</span>` : '';
            
            let statusHtml = '';
            if(u.is_online == 1) {
                statusHtml = '<div style="font-size:10px;margin-top:2px;" class="status-online">آنلاین</div>';
            } else if (u.status_hidden) {
                statusHtml = '<div style="font-size:10px;margin-top:2px;" class="status-offline">آفلاین</div>';
            } else {
                let timeStr = u.last_activity ? u.last_activity.split(' ')[1].substr(0, 5) : '';
                statusHtml = `<div style="font-size:10px;margin-top:2px;" class="status-offline">آخرین بازدید: ${timeStr}</div>`;
            }
            
            let displayUsername = _displayName;
            let checkBadge = '';
            
            if (u.username === adminUser) {
                displayUsername = '<?php echo $admin_display_name; ?>';
                checkBadge = '<svg style="width:14px;height:14px;vertical-align:middle;margin-right:4px;fill:#3b82f6;" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>';
            }
            // Pass the display name to the chat header, not the raw login name.
            const _dnAttr = String(displayUsername).replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '&quot;');

            html += `<div class="item ${isActive}" onclick="startPrivate(${u.id}, '${_dnAttr}')" oncontextmenu="handleUserCtx(event, ${u.id}, '${u.username}')">
                <div class="item-row" style="align-items: center;">
                    <div class="user-info-col" style="min-width:0; flex:1;">
                        <span style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; font-weight:bold;">${u.sticker} ${displayUsername} ${checkBadge}</span>
                        ${statusHtml}
                    </div>
                    <div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
                        ${unread}
                    </div>
                </div>
            </div>`;
        });
        
        if(list.innerHTML !== html) list.innerHTML = html;
        _vxSnapshotSchedule();

        updateHamBadge(d.unread || {});
}
</script>

<script src="assets/js/veyrax-ws.js"></script>
</body>
</html>
