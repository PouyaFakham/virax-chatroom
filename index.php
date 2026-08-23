<?php
require_once 'config.php';
// chat.rootfa.ir main site //
// developer PouyaFakham Made with LOVE //
try {
    $lStmt = $pdo->prepare("SELECT value FROM settings WHERE name = 'site_lock'");
    $lStmt->execute();
    if ($lStmt->fetchColumn() == '1') {
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <link rel="icon" type="image/png" href="<?php echo $favicon_path; ?>">
    <title>سایت در دسترس نیست</title>
    <style>
        @font-face { font-family: 'AppFont'; src: url('fonts/<?php echo $font_text ?? 'font.ttf'; ?>.ttf') format('truetype'); }
        * { box-sizing: border-box; font-family: 'AppFont', sans-serif; }
        body { margin: 0; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #fff; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; overflow: hidden; }
        .lock-container { width: 100%; max-width: 450px; position: relative; z-index: 10; padding: 10px; }
        .lock-card { background: rgba(255, 255, 255, 0.05); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); padding: 50px 30px; border-radius: 35px; border: 1px solid rgba(255, 255, 255, 0.1); text-align: center; box-shadow: 0 30px 80px -10px rgba(0, 0, 0, 0.6); position: relative; overflow: hidden; }
        .lock-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 5px; background: linear-gradient(90deg, #ef4444, #f87171, #ef4444); background-size: 200% 100%; animation: gradientMove 3s linear infinite; }
        .lock-icon-box { width: 90px; height: 90px; background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(239, 68, 68, 0.05)); border-radius: 50%; display: flex; justify-content: center; align-items: center; margin: 0 auto 30px; border: 1px solid rgba(239, 68, 68, 0.3); animation: pulse 3s infinite; }
        .icon { font-size: 40px; animation: float 4s ease-in-out infinite; filter: drop-shadow(0 0 10px rgba(239, 68, 68, 0.5)); }
        .title { font-size: 26px; font-weight: bold; margin-bottom: 20px; color: #fff; letter-spacing: -0.5px; text-shadow: 0 2px 10px rgba(0,0,0,0.3); }
        .desc { font-size: 15px; color: #cbd5e1; line-height: 1.8; margin-bottom: 35px; padding: 0 15px; }
        .btn-check { width: 100%; background: linear-gradient(135deg, #334155, #1e293b); color: #fff; border: 1px solid rgba(255,255,255,0.15); padding: 16px; border-radius: 18px; cursor: pointer; font-size: 15px; transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1); font-weight: bold; box-shadow: 0 8px 25px rgba(0,0,0,0.2); }
        .btn-check:hover { transform: translateY(-3px); box-shadow: 0 15px 35px rgba(0,0,0,0.3); border-color: rgba(255,255,255,0.3); background: linear-gradient(135deg, #475569, #334155); }
        .btn-check:active { transform: translateY(0); }
        @keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-8px); } }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); } 70% { box-shadow: 0 0 0 20px rgba(239, 68, 68, 0); } 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); } }
        @keyframes gradientMove { 0% { background-position: 0% 50%; } 100% { background-position: 100% 50%; } }
        .glow { position: absolute; width: 200px; height: 200px; background: #ef4444; filter: blur(120px); opacity: 0.12; top: -80px; left: -80px; border-radius: 50%; z-index: -1; }
    </style>
</head>
<body>
    <div class="lock-container">
        <div class="lock-card">
            <div class="glow"></div>
            <div class="lock-icon-box">
                <div class="icon">🔒</div>
            </div>
            <div class="title">دسترسی موقتاً محدود است</div>
            <div class="desc">
                کاربر گرامی، سیستم جهت ارتقاء زیرساخت و به‌روزرسانی فنی موقتاً از دسترس خارج شده است.
                <br>از شکیبایی شما سپاسگزاریم.
            </div>
            <button class="btn-check" onclick="location.reload()">بررسی وضعیت اتصال</button>
        </div>
    </div>
</body>
</html>
<?php
        exit;
    }
} catch (Exception $e) {}

$isReportLocked = false;
try {
    $stmtRep = $pdo->prepare("SELECT value FROM settings WHERE name = 'report_lock'");
    $stmtRep->execute();
    $isReportLocked = ($stmtRep->fetchColumn() == '1');
} catch (Exception $e) {}

if (empty($_SESSION['logout_nonce'])) {
    $_SESSION['logout_nonce'] = bin2hex(random_bytes(16));
}

$secCode = '';
if (isset($_SESSION['user_id'])) {
    try {
        $stmtSec = $pdo->prepare("SELECT security_code FROM users WHERE id = ?");
        $stmtSec->execute([$_SESSION['user_id']]);
        $secCode = $stmtSec->fetchColumn();
    } catch (Exception $e) {}
}

if (!isset($_SESSION['user_id'])) {
    $clientIp = get_client_ip();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $deviceToken = $_COOKIE['device_token'] ?? '';
    
    if (!empty($deviceToken)) {
        $stmtPrevUsers = $pdo->prepare("
            SELECT DISTINCT username, sticker, id 
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
            SELECT DISTINCT username, sticker, id 
            FROM users 
            WHERE ip_address = ?
            AND username != ?
            ORDER BY last_activity DESC 
            LIMIT 5
        ");
        $stmtPrevUsers->execute([$clientIp, $admin_username]);
    }
    $previousUsers = $stmtPrevUsers->fetchAll(PDO::FETCH_ASSOC);
    
    $stmtTotalUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE username != '$admin_username'");
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
    <link rel="icon" type="image/png" href="<?php echo $favicon_path; ?>">
    <title>ورود به چت</title>
    <style>
        @font-face { font-family: 'AppFont'; src: url('fonts/<?php echo $font_text; ?>.ttf') format('truetype'); font-weight: normal; }
        @font-face { font-family: 'AppBold'; src: url('fonts/<?php echo $font_heading; ?>.ttf') format('truetype'); font-weight: bold; }
        * { font-family: 'AppFont', 'Noto Color Emoji', 'Segoe UI Emoji', 'Apple Color Emoji', sans-serif; box-sizing: border-box; outline: none; -webkit-tap-highlight-color: transparent; }
        body { margin: 0; background-color: #0f172a; color: #fff; display: flex; flex-direction: column; justify-content: center; align-items: center; min-height: 100vh; min-height: 100dvh; overflow: hidden; background-image: radial-gradient(circle at top right, #1e293b, #0f172a); -webkit-touch-callout: none; }
        .login-card { background: rgba(30, 41, 59, 0.85); backdrop-filter: blur(20px); padding: 40px 30px; border-radius: 28px; width: 90%; max-width: 400px; text-align: center; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 25px 60px rgba(0,0,0,0.6); margin-bottom: 20px; transition: 0.3s; }
        .brand-title { font-family: 'AppBold'; font-size: 28px; margin: 0 0 5px; color: #fff; }
        .brand-subtitle { font-size: 13px; color: #94a3b8; margin-bottom: 30px; }
        input { width: 100%; height: 50px; background: rgba(15, 23, 42, 0.7); border: 1px solid #334155; color: #fff; border-radius: 14px; text-align: center; font-size: 16px; margin-bottom: 15px; transition: 0.3s; }
        input:focus { border-color: #6366f1; background: rgba(15, 23, 42, 0.95); }
        input[type=number]::-webkit-inner-spin-button, input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        input[type=number] { -moz-appearance: textfield; }
        
        .input-error { border-color: #ef4444 !important; background: rgba(239, 68, 68, 0.1) !important; animation: shake 0.4s; }
        .error-msg-box { color: #ef4444; font-size: 12px; margin-bottom: 10px; display: none; width: 100%; word-break: break-word; overflow-wrap: break-word; line-height: 1.4; }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        .captcha-row { display: flex; gap: 10px; margin-bottom: 15px; }
        .captcha-text { flex: 1; height: 50px; background: rgba(99, 102, 241, 0.1); border: 1px dashed rgba(99, 102, 241, 0.4); border-radius: 14px; display: flex; justify-content: center; align-items: center; font-weight: bold; color: #818cf8; font-size: 20px; font-family: 'AppBold'; overflow: hidden; white-space: nowrap; user-select: none; }
        .refresh-btn { width: 50px; height: 50px; background: rgba(15, 23, 42, 0.7); border: 1px solid #334155; border-radius: 14px; color: #94a3b8; cursor: pointer; display: flex; justify-content: center; align-items: center; font-size: 22px; transition: 0.3s; }
        .refresh-btn.rotating { animation: spin-icon 0.65s linear; background: rgba(99, 102, 241, 0.3); color: #fff; border-color: #818cf8; }
        button { width: 100%; height: 50px; background: linear-gradient(135deg, #6366f1, #4f46e5); color: white; border: none; border-radius: 14px; font-weight: bold; font-family: 'AppBold'; font-size: 16px; cursor: pointer; margin-top: 5px; box-shadow: none; transition: 0.3s; }
        button:hover { transform: translateY(-2px); background: linear-gradient(135deg, #818cf8, #6366f1); }
        .btn-secondary { background: linear-gradient(135deg, #334155, #1e293b); box-shadow: none; margin-bottom: 10px; border: 1px solid rgba(255,255,255,0.1); }
        .btn-secondary:hover { background: linear-gradient(135deg, #475569, #334155); border-color: rgba(255,255,255,0.2); }
        .btn-sec-code { width: 100%; height: 50px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12); color: #cbd5e1; border-radius: 14px; font-size: 14px; cursor: pointer; margin-top: 10px; transition: all 0.3s; box-shadow: none; font-family: 'AppFont', sans-serif; display: flex; align-items: center; justify-content: center; gap: 8px; letter-spacing: 0.3px; }
        .btn-sec-code:hover { background: rgba(99,102,241,0.15); border-color: rgba(99,102,241,0.4); color: #e2e8f0; transform: translateY(-1px); }
        .code-modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); z-index: 3500; display: none; justify-content: center; align-items: center; padding: 20px; box-sizing: border-box; }
        .code-modal-overlay.open { display: flex; }
        .code-modal-card { background: rgba(30,41,59,0.98); border: 1px solid rgba(255,255,255,0.1); border-radius: 28px; width: 100%; max-width: 400px; max-height: min(90vh, 100%); overflow-y: auto; padding: 36px 30px 30px; position: relative; box-shadow: 0 30px 80px rgba(0,0,0,0.7); animation: codeCardIn 0.3s cubic-bezier(0.2,0.8,0.2,1); -webkit-overflow-scrolling: touch; }
        @keyframes codeCardIn { from { opacity:0; transform: scale(0.94) translateY(16px); } to { opacity:1; transform: scale(1) translateY(0); } }
        .code-modal-title { font-family: 'AppBold'; font-size: 22px; color: #fff; margin-bottom: 5px; text-align: center; }
        .code-modal-sub { font-size: 13px; color: #64748b; text-align: center; margin-bottom: 28px; }
        .code-modal-close { position: absolute; top: 18px; left: 18px; width: 32px; height: 32px; border-radius: 50%; background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.1); color: #94a3b8; font-size: 15px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.2s; }
        .code-modal-close:hover { background: rgba(239,68,68,0.2); border-color: rgba(239,68,68,0.4); color: #ef4444; }
        .code-modal-btn { width: 100%; height: 50px; background: linear-gradient(135deg, #6366f1, #4f46e5); color: #fff; border: none; border-radius: 14px; font-family: 'AppBold'; font-size: 16px; cursor: pointer; margin-top: 6px; box-shadow: 0 8px 25px -5px rgba(99,102,241,0.4); transition: 0.3s; }
        .code-modal-btn:hover { background: linear-gradient(135deg, #818cf8, #6366f1); transform: translateY(-2px); box-shadow: 0 12px 30px -5px rgba(99,102,241,0.5); }
        .code-modal-back { width: 100%; height: 44px; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.10); color: rgba(255,255,255,0.55); font-size: 13px; cursor: pointer; margin-top: 8px; font-family: 'AppFont', sans-serif; transition: all 0.25s ease; border-radius: 12px; backdrop-filter: blur(8px); display: flex; align-items: center; justify-content: center; gap: 7px; letter-spacing: 0.3px; }
        .code-modal-back:hover { background: rgba(255,255,255,0.09); border-color: rgba(255,255,255,0.22); color: rgba(255,255,255,0.85); transform: translateY(-1px); }
        .code-modal-input { display: block; width: 100%; height: 50px; background: rgba(15,23,42,0.7); border: 1px solid #334155; color: #fff; border-radius: 14px; text-align: center; font-size: 16px; margin-bottom: 15px; transition: 0.3s; outline: none; font-family: 'AppFont', sans-serif; padding: 0 12px; }
        .code-modal-input:focus { border-color: #6366f1; background: rgba(15,23,42,0.95); }
        .code-modal-input[type=number]::-webkit-inner-spin-button, .code-modal-input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        .code-modal-input[type=number] { -moz-appearance: textfield; }
        
        #toast { position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%) translateY(50px); background: rgba(30, 41, 59, 0.95); backdrop-filter: blur(10px); border: 1px solid rgba(239, 68, 68, 0.5); color: white; padding: 15px 25px; border-radius: 20px; font-size: 14px; opacity: 0; transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); pointer-events: none; z-index: 999; max-width: 90%; width: max-content; text-align: center; line-height: 1.6; box-shadow: 0 10px 40px rgba(0,0,0,0.5); }
        #toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
        
        #securityCodeInput::placeholder { font-family: 'AppFont'; opacity: 0.7; letter-spacing: 0px; font-weight: normal; }

        .hidden { display: none !important; }
        #loadingOverlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.95); z-index: 2000; display: none; flex-direction: column; justify-content: center; align-items: center; }
        .spinner { width: 50px; height: 50px; border: 5px solid rgba(255,255,255,0.1); border-top-color: #6366f1; border-radius: 50%; animation: spin 1s linear infinite; margin-bottom: 20px; }
        .login-footer { margin-top: 20px; display: flex; flex-direction: column; align-items: center; gap: 8px; font-size: 12px; color: #64748b; }
        .terms-link { color: #818cf8; cursor: pointer; text-decoration: none; border-bottom: 1px dashed #818cf8; padding-bottom: 2px; transition: 0.2s; }
        .terms-link:hover { color: #a5b4fc; border-bottom-color: #a5b4fc; }
        .dev-credit { font-family: 'monospace', sans-serif; opacity: 0.7; letter-spacing: 0.5px; }
        .version-tag { font-size: 10px; color: #64748b; margin-top: 5px; opacity: 0.6; font-family: sans-serif; letter-spacing: 1px; }
        .terms-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 2500; display: flex; justify-content: center; align-items: center; backdrop-filter: blur(5px); opacity: 0; visibility: hidden; transition: 0.3s; }
        .terms-modal-overlay.active { opacity: 1; visibility: visible; }
        .terms-modal { background: #1e293b; width: 90%; max-width: 500px; max-height: 80vh; border-radius: 24px; border: 1px solid #334155; display: flex; flex-direction: column; position: relative; box-shadow: 0 25px 50px rgba(0,0,0,0.7); transform: scale(0.95); transition: 0.3s; }
        .terms-modal-overlay.active .terms-modal { transform: scale(1); }
        .terms-header { padding: 20px; border-bottom: 1px solid #334155; display: flex; justify-content: space-between; align-items: center; }
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
        .intro-card { background: #1e293b; width: 90%; max-width: 420px; padding: 35px 25px; border-radius: 28px; border: 1px solid #334155; text-align: center; position: relative; box-shadow: 0 25px 50px rgba(0,0,0,0.8); transform: scale(0.9) translateY(20px); transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); overflow: hidden; }
        .intro-overlay.active .intro-card { transform: scale(1) translateY(0); }
        .intro-close { position: absolute; top: 15px; left: 15px; width: 32px; height: 32px; background: rgba(255,255,255,0.1); border-radius: 50%; display: flex; justify-content: center; align-items: center; cursor: pointer; color: #94a3b8; transition: 0.2s; font-size: 14px; }
        .intro-close:hover { background: #ef4444; color: #fff; transform: rotate(90deg); }
        .intro-icon { font-size: 55px; margin-bottom: 20px; animation: bounce 2s infinite; }
        .intro-title { font-family: 'AppBold'; font-size: 22px; color: #fff; margin-bottom: 15px; background: linear-gradient(135deg, #fff, #94a3b8); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .intro-text { color: #cbd5e1; font-size: 14px; line-height: 1.8; margin-bottom: 25px; text-align: justify; padding: 0 10px; border-right: 2px solid #6366f1; padding-right: 15px; }
        .intro-btn { width: 100%; padding: 14px; border-radius: 14px; background: linear-gradient(135deg, #6366f1, #4f46e5); color: #fff; border: none; font-family: 'AppBold'; font-size: 15px; cursor: pointer; transition: 0.3s; box-shadow: 0 10px 25px -5px rgba(99, 102, 241, 0.4); }
        .intro-btn:hover { transform: translateY(-3px); box-shadow: 0 15px 35px -5px rgba(99, 102, 241, 0.5); }
        @keyframes bounce { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }

        .guide-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.88); z-index: 4000; display: flex; justify-content: center; align-items: center; padding: 16px; box-sizing: border-box; backdrop-filter: blur(10px); opacity: 0; visibility: hidden; transition: all 0.35s cubic-bezier(0.4,0,0.2,1); }
        .guide-overlay.active { opacity: 1; visibility: visible; }
        .guide-card { background: linear-gradient(145deg, #1e293b, #162032); width: 100%; max-width: 420px; max-height: 85vh; border-radius: 28px; border: 1px solid rgba(99,102,241,0.25); display: flex; flex-direction: column; position: relative; box-shadow: 0 30px 70px rgba(0,0,0,0.8), 0 0 0 1px rgba(255,255,255,0.04); transform: translateY(30px) scale(0.96); transition: all 0.35s cubic-bezier(0.4,0,0.2,1); overflow: hidden; }
        .guide-overlay.active .guide-card { transform: translateY(0) scale(1); }
        .guide-header { padding: 22px 22px 16px; border-bottom: 1px solid rgba(255,255,255,0.07); display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .guide-header-icon { width: 44px; height: 44px; background: linear-gradient(135deg, rgba(99,102,241,0.3), rgba(79,70,229,0.2)); border: 1px solid rgba(99,102,241,0.35); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; flex-shrink: 0; }
        .guide-header-text { flex: 1; min-width: 0; }
        .guide-title { font-family: 'AppBold'; font-size: 17px; color: #fff; margin: 0 0 3px; }
        .guide-subtitle { font-size: 12px; color: #64748b; margin: 0; }
        .guide-close { width: 32px; height: 32px; border-radius: 50%; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); color: #94a3b8; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 15px; transition: 0.2s; flex-shrink: 0; }
        .guide-close:hover { background: rgba(239,68,68,0.2); border-color: rgba(239,68,68,0.4); color: #ef4444; transform: rotate(90deg); }
        .guide-body { padding: 20px 22px; overflow-y: auto; -webkit-overflow-scrolling: touch; flex: 1; scrollbar-width: none; -ms-overflow-style: none; }
        .guide-body::-webkit-scrollbar { display: none; }
        .guide-section { margin-bottom: 18px; background: rgba(255,255,255,0.025); border: 1px solid rgba(255,255,255,0.06); border-radius: 16px; padding: 16px; }
        .guide-section:last-child { margin-bottom: 0; }
        .guide-section-title { font-family: 'AppBold'; font-size: 13px; color: #a5b4fc; margin-bottom: 10px; display: flex; align-items: center; gap: 7px; }
        .guide-section-title::before { content: ''; width: 3px; height: 14px; background: linear-gradient(to bottom, #6366f1, #4f46e5); border-radius: 2px; display: inline-block; }
        .guide-section-text { font-size: 13px; color: #cbd5e1; line-height: 1.9; }
        .guide-section-text b { color: #e2e8f0; }
        .guide-highlight { background: rgba(99,102,241,0.12); border: 1px solid rgba(99,102,241,0.2); border-radius: 10px; padding: 10px 14px; margin-top: 10px; font-size: 12px; color: #94a3b8; line-height: 1.7; display: flex; gap: 8px; align-items: flex-start; }
        .guide-highlight-icon { font-size: 16px; flex-shrink: 0; margin-top: 1px; }
        .guide-footer { padding: 16px 22px 20px; border-top: 1px solid rgba(255,255,255,0.07); flex-shrink: 0; }
        .guide-btn { width: 100%; padding: 14px; border-radius: 14px; background: linear-gradient(135deg, #6366f1, #4f46e5); color: #fff; border: none; font-family: 'AppBold'; font-size: 15px; cursor: pointer; transition: 0.3s; box-shadow: 0 8px 25px -5px rgba(99,102,241,0.4); }
        .guide-btn:hover { transform: translateY(-2px); box-shadow: 0 12px 30px -5px rgba(99,102,241,0.5); }
        .guide-badge { display: inline-flex; align-items: center; gap: 5px; background: rgba(16,185,129,0.12); border: 1px solid rgba(16,185,129,0.25); color: #34d399; border-radius: 8px; padding: 3px 10px; font-size: 11px; margin-top: 8px; }
    </style>
</head>
<body>
    <div id="toast"></div>
    <div id="loadingOverlay"><div class="spinner"></div><div style="font-size:18px">لطفاً صبر کنید...</div></div>

<div id="introPopup" class="intro-overlay" onclick="closeIntroPopup()">
    <div class="intro-card" onclick="event.stopPropagation()">
        <span class="intro-close" onclick="closeIntroPopup()">✕</span>
        <div class="intro-icon">👋</div>
        <div class="intro-title">خوش اومدی به بلک‌واکر</div>
        <div class="intro-text">
            خوش اومدی به چت‌روم بلک‌واکر نسخه 1. این نسخه به‌صورت عمومی منتشر شده اما هنوز در حال توسعه‌ست و ممکنه با باگ یا محدودیت روبه‌رو بشی.
            <br><br>
            بعضی قابلیت‌ها مثل گزارش‌دهی، سیستم دعوت و ویس‌دهی (ویس‌کال) فعلاً فعال نیستن و به‌زودی اضافه می‌شن.
            <br><br>
            اگه با مشکلی برخورد کردی لطفاً به توسعه‌دهنده <b>«پویا فخام»</b> در تلگرام به آیدی 
            <span style="color:#818cf8;direction:ltr;display:inline-block">@PooyaFakham</span> 
            گزارش بده.
        </div>
        <button class="intro-btn" onclick="closeIntroPopup()">متوجه شدم</button>
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
            background: rgba(99,102,241,0.15) !important;
            border-color: rgba(99,102,241,0.35) !important;
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
            background: linear-gradient(160deg, #1a2540 0%, #0f172a 100%);
            border-radius: 28px;
            border: 1px solid rgba(255,255,255,0.12);
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
            background: linear-gradient(135deg, rgba(99,102,241,0.3), rgba(79,70,229,0.2));
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
            background: rgba(99,102,241,0.07);
            border-radius: 10px;
            border: 1px solid rgba(99,102,241,0.12);
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
            background: rgba(99,102,241,0.14) !important;
            border-color: rgba(99,102,241,0.35) !important;
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

    <div id="loginTopStatus" style="margin-bottom:14px;text-align:center;">
        <div id="pingBar" style="display:inline-flex;align-items:center;gap:8px;background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.2);border-radius:20px;padding:6px 14px;cursor:pointer;transition:all 0.2s;" onclick="checkPing()" title="کلیک برای بررسی مجدد">
            <span id="pingDot" style="width:8px;height:8px;border-radius:50%;background:#94a3b8;display:inline-block;transition:background 0.3s;"></span>
            <span id="pingLabel" style="font-size:12px;color:#94a3b8;">بررسی اتصال...</span>
            <span id="pingMs" style="font-size:11px;color:#64748b;font-family:monospace;"></span>
        </div>
        <div id="onlineCount" style="font-size:11px;color:#64748b;margin-top:6px;"></div>
    </div>

    <div class="login-card" id="mainLoginCard">
        <div class="brand-title">بلک واکر</div>
        <div class="brand-subtitle">پلتفرم گفتگوی امن</div>

        <div id="serverStatusBar" style="display:flex;align-items:center;justify-content:space-between;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.07);border-radius:12px;padding:8px 14px;margin-bottom:16px;cursor:pointer;" onclick="refreshServerStatus()" title="برای بررسی مجدد کلیک کنید">
            <div style="display:flex;align-items:center;gap:7px;">
                <span id="serverPingDot" style="width:8px;height:8px;border-radius:50%;background:#64748b;flex-shrink:0;transition:background 0.3s;"></span>
                <span id="serverPingLabel" style="font-size:11px;color:#64748b;">در حال بررسی...</span>
                <span id="serverPingMs" style="font-size:11px;color:#64748b;"></span>
            </div>
            <div style="display:flex;align-items:center;gap:5px;">
                <span style="font-size:11px;color:#64748b;">👥</span>
                <span id="onlineCountLabel" style="font-size:11px;color:#64748b;">—</span>
                <span style="font-size:11px;color:#475569;">آنلاین</span>
            </div>
        </div>
        <form id="loginForm">
            <div id="step1">
                <input type="text" id="username" name="username" placeholder="نام کاربری (حتی فاصله‌دار)" required autocomplete="off">
                <div id="usernameError" class="error-msg-box"></div>
                <div class="captcha-row">
                    <div class="captcha-text" id="captchaBox"><?php echo $n1; ?> + <?php echo $n2; ?></div>
                    <div class="refresh-btn" onclick="refreshCaptcha()">↻</div>
                </div>
                <input type="number" name="captcha" id="captchaInput" placeholder="پاسخ امنیتی" required>
            </div>
            <div id="step2" class="hidden">
                <input type="password" id="password" name="password" placeholder="رمز عبور مدیریت" autocomplete="new-password">
            </div>
            <button type="submit" id="submitBtn">ورود</button>
        </form>
        <button type="button" class="btn-sec-code" onclick="showLoginWithCode()">🔒 کد امنیتی دارم</button>
        <button type="button" class="btn-sec-code" onclick="openGuide()" style="margin-top:8px; border-color:rgba(99,102,241,0.25); color:#94a3b8;">❓ راهنمای ورود</button>
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
                    <div class="refresh-btn" onclick="refreshCaptchaCode()">↻</div>
                </div>
                <input type="number" id="captchaInputCode" class="code-modal-input" placeholder="پاسخ امنیتی" required inputmode="numeric">
                <button type="submit" class="code-modal-btn">تایید و ورود</button>
                <button type="button" class="code-modal-back" onclick="hideLoginWithCode()">← بازگشت</button>
            </form>
        </div>
    </div>

    <div class="login-footer">
        <span class="terms-link" onclick="openTerms()">پذیرش قوانین</span>
        <?php if(!empty($donate_link)): ?>
        <a href="<?php echo $donate_link; ?>" target="_blank" style="color:#fbbf24;text-decoration:none;border-bottom:1px dashed #fbbf24;padding-bottom:2px;font-size:12px;">&#x1F49B; حمایت از پروژه</a>
        <?php endif; ?>
        <span class="dev-credit">طراحی توسعه : PouyaFakham</span>
        <span class="version-tag">Version 1.1</span>
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
                <p style="text-align:center; color:#818cf8; font-weight:bold;">با ورود به چت، شما این قوانین را می‌پذیرید.</p>
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

        function refreshCaptcha() {
            const btn = document.querySelector('#mainLoginCard .refresh-btn');
            if(btn) { btn.classList.remove('rotating'); void btn.offsetWidth; btn.classList.add('rotating'); setTimeout(() => btn.classList.remove('rotating'), 700); }

            const fd = new FormData(); fd.append('action', 'refresh_captcha'); fd.append('csrf_token', csrfToken);
            fetch('requests.php', { method: 'POST', body: fd }).then(r=>r.json()).then(d=>{ 
                if(d.status === 'success') { 
                    document.getElementById('captchaBox').textContent = `${d.n1} + ${d.n2}`; 
                    document.getElementById('captchaInput').value = ''; 
                }
            });
        }

        function refreshCaptchaCode() {
            const btn = document.querySelector('#loginWithCodeCard .refresh-btn');
            if(btn) { btn.classList.remove('rotating'); void btn.offsetWidth; btn.classList.add('rotating'); setTimeout(() => btn.classList.remove('rotating'), 700); }

            const fd = new FormData(); fd.append('action', 'refresh_captcha'); fd.append('csrf_token', csrfToken);
            fetch('requests.php', { method: 'POST', body: fd }).then(r=>r.json()).then(d=>{ 
                if(d.status === 'success') { 
                    document.getElementById('captchaBoxCode').textContent = `${d.n1} + ${d.n2}`; 
                    document.getElementById('captchaInputCode').value = ''; 
                }
            });
        }
        
        function quickLogin(username) {
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
                        .catch(() => { document.getElementById('loadingOverlay').style.display='none'; showToast('خطا در ارتباط'); });
                })
                .catch(() => {
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
                                errBox.style.display = 'block';
                                errBox.style.background = 'rgba(34,197,94,0.1)';
                                errBox.style.border = '1px solid rgba(34,197,94,0.3)';
                                errBox.style.color = '#86efac';
                                errBox.textContent = '✔ این نام کاربری موجود است - می‌توانید وارد شوید';
                            }
                        });
                }, 800);
            }
        });

        document.getElementById('loginForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const fd = new FormData(this); fd.append('csrf_token', csrfToken);
            
            if(document.getElementById('step2').classList.contains('hidden')) {
                fd.append('action', 'login_check');
                fetch('requests.php', { method: 'POST', body: fd }).then(r=>r.json()).then(d=>{
                    if(d.status === 'password_required') { 
                        document.getElementById('step1').classList.add('hidden');
                        document.getElementById('step2').classList.remove('hidden');
                        document.getElementById('password').required = true; 
                        document.getElementById('password').focus(); 
                        document.getElementById('submitBtn').innerText = 'تایید نهایی'; 
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
        
        function submitLogin(fd) {
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
                    setTimeout(()=>location.reload(), 500); 
                } 
                else { 
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

        // Auto-open popup on page load if previous accounts detected
        window.addEventListener('load', () => {
            if (window._prevAccounts && window._prevAccounts.length > 0) {
                setTimeout(() => openPrevAccountsModal(window._prevAccounts, false), 600);
            }
        });

        function showLoginWithCode() {
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
            const dot = document.getElementById('serverPingDot');
            const label = document.getElementById('serverPingLabel');
            const msEl = document.getElementById('serverPingMs');
            const countEl = document.getElementById('onlineCountLabel');
            dot.style.background = '#f59e0b';
            label.textContent = 'بررسی...';
            msEl.textContent = '';
            const start = Date.now();
            const fd = new FormData();
            fd.append('action', 'ping_server');
            fd.append('csrf_token', csrfToken);
            fetch('requests.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(() => {
                    const elapsed = Date.now() - start;
                    dot.style.background = '#10b981';
                    label.style.color = '#10b981';
                    label.textContent = 'آنلاین';
                    msEl.style.color = '#10b981';
                    msEl.textContent = elapsed + ' ms';
                })
                .catch(() => {
                    dot.style.background = '#ef4444';
                    label.style.color = '#ef4444';
                    label.textContent = 'قطع ارتباط';
                    msEl.textContent = '';
                });
            const fd2 = new FormData();
            fd2.append('action', 'get_online_count');
            fd2.append('csrf_token', csrfToken);
            fetch('requests.php', { method: 'POST', body: fd2 })
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'success') {
                        countEl.textContent = d.count;
                        countEl.style.color = '#6366f1';
                    }
                }).catch(() => {});
        }

        window.addEventListener('load', () => {
            refreshServerStatus();
        });
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
    <meta name="bw-nonce" content="<?php echo isset($_SESSION['bw_nonce']) ? $_SESSION['bw_nonce'] : ''; ?>">
    <meta name="logout-nonce" content="<?php echo $_SESSION['logout_nonce']; ?>">
    <meta name="theme-color" content="#1e293b">
    <link rel="icon" type="image/png" href="<?php echo $favicon_path; ?>">
    <title>چت روم بلک واکر</title>
    <style>
        @font-face { font-family: 'AppFont'; src: url('fonts/<?php echo $font_text; ?>.ttf') format('truetype'); font-weight: normal; }
        @font-face { font-family: 'AppBold'; src: url('fonts/<?php echo $font_heading; ?>.ttf') format('truetype'); font-weight: bold; }
        :root { --bg: #0f172a; --panel: #1e293b; --border: #334155; --primary: #6366f1; --text: #f8fafc; --msg-me: #4f46e5; --msg-other: #334155; }
        * { box-sizing: border-box; font-family: 'AppFont', 'Noto Color Emoji', 'Segoe UI Emoji', 'Apple Color Emoji', 'Twemoji Mozilla', sans-serif; -webkit-tap-highlight-color: transparent; }
        ::-webkit-scrollbar { display: none; }
        * { -ms-overflow-style: none; scrollbar-width: none; }
        
        body { margin: 0; background: var(--bg); color: var(--text); height: 100vh; height: 100dvh; display: flex; flex-direction: column; overflow: hidden; position: fixed; width: 100%; top: 0; left: 0; -webkit-touch-callout: none; }
        
        .sidebar { position: absolute; right: 0; top: 0; width: 280px; height: 100%; background: var(--panel); border-left: 1px solid var(--border); z-index: 50; transition: transform 0.4s cubic-bezier(0.2, 0.8, 0.2, 1); transform: translateX(100%); display: flex; flex-direction: column; box-shadow: -10px 0 30px rgba(0,0,0,0.5); }
        .sidebar.open { transform: translateX(0); }
        .backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(3px); z-index: 40; display: none; opacity: 0; transition: opacity 0.3s; }
        .backdrop.show { display: block; opacity: 1; }
        .close-menu { position: absolute; top: 15px; left: 15px; font-size: 24px; cursor: pointer; color: #94a3b8; padding: 5px; }
        
        @media (min-width: 769px) { 
            body { position: static; flex-direction: row; }
            .sidebar { position: relative; transform: translateX(0); z-index: 10; box-shadow: none; width: 300px; flex-shrink: 0; transition: width 0.4s cubic-bezier(0.2, 0.8, 0.2, 1), transform 0.4s; height: 100%; }
            .sidebar.closed { width: 0; transform: translateX(100%); overflow: hidden; border: none; } 
            .backdrop, .close-menu { display: none !important; } 
        }

        .main { flex: 1; display: flex; flex-direction: column; position: relative; background-image: radial-gradient(#334155 1px, transparent 1px); background-size: 24px 24px; min-width: 0; height: 100%; overflow: hidden; }
        
        .header { height: 60px; background: #1e293b; border-bottom: 1px solid var(--border); display: flex; align-items: center; padding: 0 15px; justify-content: space-between; z-index: 10; box-shadow: none; flex-shrink: 0; position: relative; }
        .header-title { font-family: 'AppBold'; color: #fff; font-size: 16px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 160px; display: block; }
        .header-status { font-size: 11px; color: #94a3b8; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }
        
        .social-icons { display: flex; gap: 8px; align-items: center; }
        .social-btn { width: 36px; height: 36px; background: rgba(255, 255, 255, 0.08); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 10px; display: flex; justify-content: center; align-items: center; transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1); box-shadow: 0 4px 15px rgba(0,0,0,0.2); text-decoration: none; }
        .social-btn:hover { background: rgba(99, 102, 241, 0.2); border-color: rgba(99, 102, 241, 0.4); transform: translateY(-3px); box-shadow: 0 8px 20px rgba(99, 102, 241, 0.3); }
        .social-btn img { width: 20px; height: 20px; object-fit: contain; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.3)); }
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

        .search-box { padding: 15px; border-bottom: 1px solid var(--border); background: var(--panel); z-index: 2; flex-shrink: 0; }
        .search-input { width: 100%; background: #0f172a; border: 1px solid var(--border); padding: 10px; border-radius: 10px; color: #fff; font-size: 13px; transition: 0.3s; }
        .search-input.error { border-color: #ef4444; animation: shake 0.4s; }
        @keyframes shake { 0%, 100% { transform: translateX(0); } 25% { transform: translateX(-5px); } 75% { transform: translateX(5px); } }

        .list-header { padding: 15px 15px 5px; font-size: 12px; color: #94a3b8; font-weight: bold; }
        .list { overflow-y: auto; flex: 1; padding: 5px 10px; min-height: 0; }
        .item { padding: 10px 12px; margin: 4px 0; border-radius: 10px; cursor: pointer; display: flex; flex-direction: column; align-items: flex-start; justify-content: center; color: #cbd5e1; transition: 0.2s; white-space: nowrap; overflow: hidden; gap: 4px; }
        .item-row { display: flex; align-items: center; justify-content: space-between; width: 100%; }
        .item:hover { background: rgba(255,255,255,0.05); color: #fff; }
        .item.active { background: rgba(99, 102, 241, 0.15); color: #fff; border: 1px solid rgba(99, 102, 241, 0.3); font-family: 'AppBold'; }
        
        .unread-badge { background: #ef4444; color: #fff; min-width: 20px; height: 20px; border-radius: 4px; display: flex; justify-content: center; align-items: center; font-size: 11px; font-weight: bold; padding: 0 6px; box-shadow: 0 2px 5px rgba(239,68,68,0.4); flex-shrink: 0; line-height: 1; }
        
        .messages { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 8px; padding-bottom: 20px; overscroll-behavior: contain; -webkit-overflow-scrolling: touch; overflow-anchor: auto; scroll-behavior: auto; -webkit-transform: translateZ(0); transform: translateZ(0); }
        .cr-tab { flex:1; padding:8px 4px; border:none; border-radius:8px; background:transparent; color:var(--text-muted); font-size:12px; cursor:pointer; transition:all 0.2s; font-family:inherit; }
        .cr-tab.active { background:var(--accent); color:#fff; font-weight:bold; }
        .sidebar-category { display:flex; align-items:center; justify-content:space-between; padding:8px 14px 4px; cursor:pointer; user-select:none; }
        .sidebar-category-title { font-size:11px; font-weight:bold; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; }
        .sidebar-category-arrow { font-size:11px; color:var(--text-muted); transition:transform 0.2s; }
        .sidebar-category-arrow.collapsed { transform:rotate(-90deg); }
        .sidebar-category-body { overflow:hidden; transition:max-height 0.3s ease; }
        .sidebar-category-body.collapsed { max-height:0 !important; }
        
        .msg { width: fit-content; max-width: 80%; padding: 12px 16px; border-radius: 16px; position: relative; font-size: 14px; line-height: 1.6; word-wrap: break-word; overflow-wrap: break-word; word-break: break-word; box-shadow: 0 3px 8px rgba(0,0,0,0.15); transition: transform 0.1s; -webkit-tap-highlight-color: transparent !important; user-select: none; -webkit-user-select: none; cursor: default; -webkit-touch-callout: none; }

        .msg-row { display: flex; align-items: flex-end; gap: 6px; width: 100%; direction: ltr; margin-bottom: 12px; }
        .msg-row.me { justify-content: flex-end; }
        .msg-row.other { justify-content: flex-start; }
        .msg-row .msg.me { direction: rtl; }
        .msg-row .msg.other { direction: rtl; }

        .msg-actions { display: flex; flex-direction: column; gap: 4px; opacity: 0; visibility: hidden; transition: opacity 0.18s, visibility 0.18s; flex-shrink: 0; width: 28px; }
        .msg-row:hover .msg-actions { opacity: 1; visibility: visible; }
        .msg-actions.touch-visible { opacity: 1; visibility: visible; }
        .msg-actions.hover-visible { opacity: 1; visibility: visible; }        .msg-act-btn { width: 28px; height: 28px; border-radius: 50%; background: rgba(30,41,59,0.92); border: 1px solid rgba(255,255,255,0.12); color: #cbd5e1; cursor: pointer; display: flex; justify-content: center; align-items: center; font-size: 13px; transition: background 0.15s, color 0.15s; -webkit-tap-highlight-color: transparent; flex-shrink: 0; }
        .msg-act-btn:hover { background: #6366f1; color: #fff; border-color: #6366f1; }
        .msg-act-btn.del-btn:hover { background: #ef4444; border-color: #ef4444; }
        
        .msg.me { align-self: flex-end; margin-right: auto; margin-left: 0; background: var(--msg-me); color: #fff; border-bottom-left-radius: 4px; border-bottom-right-radius: 16px; }
        .msg.other { align-self: flex-start; margin-left: auto; margin-right: 0; background: var(--msg-other); color: #e2e8f0; border-bottom-right-radius: 4px; border-bottom-left-radius: 16px; }
        
        .msg.system { align-self: center; background: rgba(255, 255, 255, 0.1); color: #94a3b8; font-size: 12px; padding: 5px 12px; border-radius: 20px; box-shadow: none; border: 1px solid rgba(255,255,255,0.1); margin: 10px 0; text-align: center; max-width: 90%; }

        .text-glass-box {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.15);
            padding: 12px 16px;
            border-radius: 14px;
            margin-top: 8px;
            display: block;
            user-select: text;
            -webkit-user-select: text;
            line-height: 1.7;
            box-shadow: inset 0 1px 2px rgba(255, 255, 255, 0.1), 0 4px 15px rgba(0,0,0,0.1);
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .msg.pinned-highlight { box-shadow: 0 0 0 2px #ef4444, 0 4px 16px rgba(239,68,68,0.25) !important; border-radius: 18px; }
        .msg.pending { opacity: 0.7; }
        .msg.error { border: 1px solid #ef4444; }
        
        .msg.highlight-target { animation: replyPulse 2.5s ease-out forwards; z-index: 10; }
        @keyframes replyPulse {
            0%   { box-shadow: 0 0 0 3px #818cf8, 0 0 24px rgba(129,140,248,0.6); outline: 2px solid #818cf8; }
            40%  { box-shadow: 0 0 0 4px #a5b4fc, 0 0 32px rgba(165,180,252,0.5); }
            100% { box-shadow: 0 3px 8px rgba(0,0,0,0.15); outline: 2px solid transparent; }
        }

        .msg-row.replied-to-me > .msg.me,
        .msg-row.replied-to-me > .msg.other {
            box-shadow: 0 0 0 2px rgba(99,102,241,0.7), 0 6px 24px rgba(99,102,241,0.35);
            border-radius: 18px;
        }
        .msg-row.replied-to-me > .msg.me {
            background: linear-gradient(135deg, rgba(99,102,241,0.25) 0%, var(--msg-me) 40%);
        }
        .msg-row.replied-to-me > .msg.other {
            background: linear-gradient(135deg, rgba(99,102,241,0.18) 0%, var(--msg-other) 40%);
        }
        .msg-row.replied-to-me .reply-to-me-badge {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            color: #c4b5fd;
            margin-bottom: 5px;
            font-family: 'AppBold';
            background: rgba(99,102,241,0.18);
            padding: 4px 10px;
            border-radius: 8px;
            border: 1px solid rgba(99,102,241,0.3);
        }
        .reply-to-me-badge svg { width:13px; height:13px; fill:#c4b5fd; flex-shrink:0; }

        .sender { font-size: 12px; opacity: 0.85; margin-bottom: 6px; font-family: 'AppBold'; display: flex; align-items: center; gap: 5px; font-weight: bold; }
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

        .proxy-box { margin-top: 10px; background: rgba(15,23,42,0.6); border: 1px solid rgba(99,102,241,0.3); border-radius: 12px; padding: 10px 12px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .proxy-box-icon { font-size: 22px; flex-shrink: 0; }
        .proxy-box-info { flex: 1; min-width: 0; }
        .proxy-box-label { font-size: 11px; color: #6366f1; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; }
        .proxy-box-count { font-size: 13px; color: #e2e8f0; margin-top: 2px; }
        .proxy-box-btn { background: linear-gradient(135deg, rgba(99,102,241,0.25), rgba(79,70,229,0.2)); border: 1px solid rgba(99,102,241,0.45); color: #a5b4fc; padding: 7px 14px; border-radius: 10px; font-size: 12px; cursor: pointer; font-family: 'AppFont'; transition: 0.2s; white-space: nowrap; }
        .proxy-box-btn:hover { background: linear-gradient(135deg, rgba(99,102,241,0.4), rgba(79,70,229,0.35)); color: #fff; }
        .media-container { max-width: 100%; border-radius: 12px; overflow: hidden; margin-top: 5px; background: rgba(0,0,0,0.2); cursor: zoom-in; }
        .media-container.video-wrap { position: relative; cursor: pointer; min-height: 120px; background: #0f172a; }
        .video-play-overlay { position: absolute; inset: 0; display: flex; justify-content: center; align-items: center; background: rgba(0,0,0,0.45); border-radius: 12px; pointer-events: none; z-index: 2; }
        .video-play-icon { width: 54px; height: 54px; background: rgba(255,255,255,0.92); border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 22px; box-shadow: 0 4px 20px rgba(0,0,0,0.5); padding-right: 2px; }
        .video-thumb { width: 100%; max-height: 300px; object-fit: cover; display: block; border-radius: 12px; }
        .chat-media { width: auto; max-width: 100%; max-height: 300px; object-fit: contain; display: block; }
        audio { width: 260px; max-width: 100%; height: 40px; border-radius: 20px; margin-top: 5px; display: block; outline: none; }
        .file-box { display: flex; align-items: center; background: rgba(0,0,0,0.2); border-radius: 12px; padding: 10px; margin-top: 5px; gap: 10px; min-width: 150px; }
        .file-icon { font-size: 24px; }
        .file-info { flex: 1; overflow: hidden; }
        .file-name { font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; font-weight: bold; }
        .download-btn { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); color: #fff; width: 36px; height: 36px; border-radius: 50%; display: flex; justify-content: center; align-items: center; text-decoration: none; transition: 0.2s; font-size: 18px; flex-shrink: 0; }
        .download-btn:hover { background: var(--primary); border-color: var(--primary); }
        
        .input-box { background: var(--panel); padding: 10px 15px; border-top: 1px solid var(--border); z-index: 20; flex-shrink: 0; padding-bottom: calc(10px + env(safe-area-inset-bottom)); -webkit-transform: translateZ(0); transform: translateZ(0); }
        
        .reply-bar, .file-preview { background: #0f172a; padding: 8px 12px; border-left: 3px solid var(--primary); margin-bottom: 8px; border-radius: 8px; display: none; justify-content: space-between; align-items: center; font-size: 12px; color: #cbd5e1; }
        .input-row { display: flex; gap: 8px; align-items: center; }
        textarea { flex: 1; background: #334155; border: none; color: white; padding: 12px 15px; border-radius: 20px; resize: none; height: 46px; max-height: 160px; overflow-y: auto; outline: none; font-size: 14px; transition: height 0.15s; user-select: text !important; -webkit-user-select: text !important; -webkit-touch-callout: default !important; line-height: 1.4; }
        textarea:focus { background: #475569; }
        .btn { width: 46px; height: 46px; border-radius: 50%; border: none; background: #334155; color: #cbd5e1; cursor: pointer; display: flex; justify-content: center; align-items: center; font-size: 20px; transition: 0.2s; flex-shrink: 0; }
        .btn:hover { background: #475569; color: #fff; }
        .btn-send { background: var(--primary); color: white; }
        .btn-send:hover { background: #4f46e5; }
        .voice-rec { display: none; flex: 1; align-items: center; justify-content: space-between; background: #334155; height: 46px; border-radius: 23px; padding: 0 20px; color: #ef4444; border: 1px solid #ef4444; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(239,68,68,0.4); } 100% { box-shadow: 0 0 0 10px rgba(239,68,68,0); } }
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 2000; display: flex; justify-content: center; align-items: center; backdrop-filter: blur(8px); opacity: 0; transition: opacity 0.3s; visibility: hidden; }
        .modal-overlay.active { opacity: 1; visibility: visible; }
        .modal { background: #1e293b; padding: 30px; border-radius: 24px; width: 90%; max-width: 380px; text-align: center; border: 1px solid #334155; box-shadow: 0 20px 60px rgba(0,0,0,0.7); transform: scale(0.9); transition: transform 0.3s; }
        .modal-overlay.active .modal { transform: scale(1); }
        .modal-title { color: #fff; font-size: 18px; margin-bottom: 10px; font-family: 'AppBold'; }
        .modal-text { color: #94a3b8; font-size: 14px; margin-bottom: 25px; line-height: 1.6; }
        .modal-icon { font-size: 50px; margin-bottom: 20px; display: block; }
        .modal-input { width: 100%; background: #0f172a; border: 1px solid #334155; color: #fff; padding: 12px; border-radius: 12px; margin: 10px 0; outline: none; text-align: center; font-size: 16px; }
        .modal-btn { width: 100%; padding: 12px; border-radius: 12px; border: none; font-weight: bold; cursor: pointer; margin-top: 10px; font-family: 'AppBold'; font-size: 15px; }
        .btn-green { background: #10b981; color: #fff; }
        .btn-red { background: #ef4444; color: #fff; }
        .btn-gray { background: #334155; color: #cbd5e1; }
        .notif-popup { position: fixed; top: 70px; left: 50%; transform: translateX(-50%) translateY(-200%); background: rgba(30, 41, 59, 0.98); border: 1px solid #6366f1; border-radius: 16px; padding: 15px 20px; z-index: 99999; box-shadow: 0 15px 40px rgba(0,0,0,0.6); display: flex; align-items: center; gap: 15px; transition: transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1); min-width: 320px; max-width: 90%; backdrop-filter: blur(12px); cursor: pointer; opacity: 0; visibility: hidden; }
        .notif-popup.show { transform: translateX(-50%) translateY(0); opacity: 1; visibility: visible; }
        .notif-avatar { width: 45px; height: 45px; background: #334155; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 24px; border: 2px solid #6366f1; flex-shrink: 0; }
        .notif-content { flex: 1; overflow: hidden; }
        .notif-name { font-weight: bold; color: #fff; font-size: 14px; margin-bottom: 3px; display: block; }
        .notif-text { color: #cbd5e1; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }
        .notif-hint { color: #818cf8; font-size: 11px; margin-top: 4px; font-weight: bold; display: block; }
        .msg-menu-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0); z-index: 2500; display: none; align-items: flex-end; justify-content: center; transition: background 0.25s ease; }
        .msg-menu-overlay.active { background: rgba(0,0,0,0.45); display: flex; }
        .msg-menu { background: #1e293b; width: 100%; max-width: 500px; border-radius: 20px 20px 0 0; padding: 25px; transform: translateY(100%); transition: transform 0.32s cubic-bezier(0.32, 0.72, 0, 1); box-shadow: 0 -10px 40px rgba(0,0,0,0.5); border-top: 1px solid #334155; padding-bottom: 40px; position: relative; z-index: 2501; will-change: transform; }
        .msg-menu-overlay.active .msg-menu { transform: translateY(0); }
        .menu-grid-actions { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 15px; }
        .action-item { background: rgba(255,255,255,0.05); border-radius: 15px; padding: 20px 10px; text-align: center; cursor: pointer; transition: 0.2s; display: flex; flex-direction: column; align-items: center; gap: 10px; color: #e2e8f0; font-size: 13px; border: 1px solid rgba(255,255,255,0.05); }
        .action-item:active { transform: scale(0.95); background: rgba(255,255,255,0.1); }
        .action-icon { font-size: 28px; margin-bottom: 5px; }
        .action-cancel { width: 100%; padding: 15px; background: #0f172a; border-radius: 15px; border: none; color: #94a3b8; font-weight: bold; margin-top: 15px; cursor: pointer; font-size: 14px; }
        
        .menu-grid { display: flex; flex-wrap: wrap; gap: 8px; padding: 10px; margin-top: auto; border-top: 1px solid var(--border); background: var(--panel); z-index: 3; flex-shrink: 0; }
        .menu-item { background: rgba(15, 23, 42, 0.5); border: 1px solid var(--border); padding: 10px; border-radius: 12px; text-align: center; font-size: 11px; cursor: pointer; color: #cbd5e1; display: flex; align-items: center; justify-content: center; min-height: 42px; flex: 1 1 calc(50% - 8px); }
        .menu-item.full-width { flex: 1 1 100%; background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(185, 28, 28, 0.2)); color: #fca5a5; border-color: rgba(239, 68, 68, 0.3); font-weight: bold; }
        .menu-toggle-item { display: flex; justify-content: space-between; align-items: center; padding: 10px; background: rgba(15, 23, 42, 0.5); border: 1px solid var(--border); border-radius: 12px; color: #cbd5e1; font-size: 11px; cursor: pointer; min-height: 42px; flex: 1 1 calc(50% - 8px); }
        .menu-toggle-item.full-width { flex: 1 1 100%; }

        .ctx-menu, .msg-ctx-menu { position: fixed; background: #1e293b; border: 1px solid #334155; border-radius: 10px; padding: 5px; z-index: 9999; display: none; min-width: 150px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .ctx-item { padding: 8px 12px; cursor: pointer; color: #e2e8f0; font-size: 13px; border-radius: 6px; display: flex; align-items: center; justify-content: space-between; }
        .ctx-item:hover { background: #334155; color: #fff; }
        
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
        .reply-sender { font-weight: bold; color: #a5b4fc; margin-bottom: 3px; font-size: 12px; }
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

        .copy-code-box { display: flex; align-items: center; justify-content: space-between; background: rgba(99,102,241,0.2); border: 1px dashed #6366f1; border-radius: 10px; padding: 10px 15px; margin-bottom: 20px; }
        .code-text { font-family: monospace; font-size: 24px; color: #fff; letter-spacing: 2px; }
        .copy-btn { background: rgba(255,255,255,0.1); border: none; color: #fff; width: 36px; height: 36px; border-radius: 8px; cursor: pointer; display: flex; justify-content: center; align-items: center; transition: 0.2s; }
        .copy-btn:hover { background: #6366f1; }
        
        .code-display-small { font-size: 10px; color: #94a3b8; background: rgba(0,0,0,0.2); padding: 2px 6px; border-radius: 4px; cursor: pointer; margin-top: 4px; display: inline-block; font-family: monospace; border: 1px dashed rgba(255,255,255,0.1); }
        .code-display-small:hover { background: rgba(99,102,241,0.2); color: #fff; border-color: #6366f1; }
        
        .toggle-switch { position: relative; width: 34px; height: 18px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #334155; transition: .4s; border-radius: 20px; }
        .slider:before { position: absolute; content: ""; height: 14px; width: 14px; left: 2px; bottom: 2px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #10b981; }
        input:checked + .slider:before { transform: translateX(16px); }
        
        .room-code-badge { font-size: 10px; color: #a5b4fc; background: rgba(99,102,241,0.1); padding: 2px 6px; border-radius: 4px; border: 1px dashed rgba(99,102,241,0.3); margin-right: auto; cursor: pointer; font-family: monospace; }
        .room-code-badge:hover { background: rgba(99,102,241,0.3); color: #fff; }
        
        .drag-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.85); z-index: 9999; display: none; justify-content: center; align-items: center; flex-direction: column; font-size: 24px; color: #fff; border: 5px dashed #6366f1; backdrop-filter: blur(5px); }
        .drag-overlay.active { display: flex; }
        .drag-text { margin-top: 20px; font-weight: bold; }
        .drag-icon { font-size: 60px; animation: bounce 1s infinite; }
        @keyframes bounce { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-20px); } }

        #uploadProgressBox { position: fixed; bottom: 90px; left: 50%; transform: translateX(-50%); background: rgba(15,23,42,0.97); backdrop-filter: blur(12px); border: 1px solid rgba(99,102,241,0.4); border-radius: 16px; padding: 14px 20px; z-index: 9000; min-width: 280px; max-width: 90%; display: none; box-shadow: 0 10px 40px rgba(0,0,0,0.5); }
        .upload-prog-title { font-size: 13px; color: #cbd5e1; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; }
        .upload-prog-name { color: #a5b4fc; font-weight: bold; max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .upload-prog-pct { color: #10b981; font-weight: bold; font-size: 14px; }
        .upload-prog-bar-bg { height: 6px; background: rgba(255,255,255,0.1); border-radius: 3px; overflow: hidden; }
        .upload-prog-bar { height: 100%; background: linear-gradient(90deg, #6366f1, #10b981); border-radius: 3px; transition: width 0.15s ease; width: 0%; }

        #lightboxOverlay { transition: opacity 0.2s; }
        #lightboxImg { transition: transform 0.25s cubic-bezier(0.25, 0.8, 0.25, 1); }

        .captcha-row { display: flex; gap: 10px; margin-bottom: 15px; }
        .captcha-text { flex: 1; height: 50px; background: rgba(99, 102, 241, 0.1); border: 1px dashed rgba(99, 102, 241, 0.4); border-radius: 14px; display: flex; justify-content: center; align-items: center; font-weight: bold; color: #818cf8; font-size: 20px; font-family: 'AppBold'; overflow: hidden; white-space: nowrap; user-select: none; }
        .refresh-btn { width: 50px; height: 50px; background: rgba(15, 23, 42, 0.7); border: 1px solid #334155; border-radius: 14px; color: #94a3b8; cursor: pointer; display: flex; justify-content: center; align-items: center; font-size: 22px; transition: 0.3s; }
        .refresh-btn.rotating { animation: spin-icon 0.65s linear; background: rgba(99, 102, 241, 0.3); color: #fff; border-color: #818cf8; }
        input[type=number]::-webkit-inner-spin-button, input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        input[type=number] { -moz-appearance: textfield; }

        #reportUserSuggestions {
            position: absolute;
            background: rgba(30, 41, 59, 0.95);
            backdrop-filter: blur(10px);
            border: 1px solid #475569;
            border-radius: 12px;
            width: calc(100% - 60px);
            max-height: 150px;
            overflow-y: auto;
            z-index: 3000;
            display: none;
            margin-top: -10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.5);
        }
        #reportUserSuggestions.show {
            display: block;
        }
        .suggestion-item {
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 1px solid rgba(255,255,255,0.05);
            color: #e2e8f0;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: 0.2s;
        }
        .suggestion-item:last-child {
            border-bottom: none;
        }
        .suggestion-item:hover {
            background: rgba(99, 102, 241, 0.2);
            color: #fff;
        }

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

        .load-more-box { background: rgba(99, 102, 241, 0.1); border: 1px dashed rgba(99, 102, 241, 0.4); color: #818cf8; padding: 10px 15px; text-align: center; border-radius: 12px; cursor: pointer; font-family: 'AppBold'; font-size: 13px; margin: 0 auto 20px auto; width: max-content; min-width: 200px; transition: all 0.3s ease; user-select: none; }
        .load-more-box:hover { background: rgba(99, 102, 241, 0.2); border-color: #6366f1; color: #fff; }

        @keyframes spin-circle { 100% { transform: rotate(360deg); } }
        @keyframes spin-icon { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        .history-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.92); z-index: 99999; display: flex; justify-content: center; align-items: center; flex-direction: column; backdrop-filter: blur(10px); opacity: 0; visibility: hidden; transition: all 0.3s ease; }
        .history-overlay.active { opacity: 1; visibility: visible; }
        .history-spinner-wrap { position: relative; width: 64px; height: 64px; display: flex; align-items: center; justify-content: center; margin-bottom: 20px; }
        .history-spinner { width: 64px; height: 64px; border: 4px solid rgba(99, 102, 241, 0.15); border-top-color: #6366f1; border-radius: 50%; animation: spin 1s linear infinite; box-shadow: 0 0 20px rgba(99, 102, 241, 0.3); position: absolute; inset: 0; }
        .history-spinner-inner { width: 44px; height: 44px; border: 3px solid rgba(99, 102, 241, 0.1); border-bottom-color: #a5b4fc; border-radius: 50%; animation: spin 0.7s linear infinite reverse; position: absolute; top: 10px; left: 10px; }
        .history-spinner-dot { width: 10px; height: 10px; background: #6366f1; border-radius: 50%; box-shadow: 0 0 12px rgba(99, 102, 241, 0.9); animation: hdotPulse 1.5s ease-in-out infinite; }
        @keyframes hdotPulse { 0%,100%{transform:scale(1)} 50%{transform:scale(0.5);opacity:0.5} }
        .history-text { color: #fff; font-family: 'AppBold'; font-size: 15px; text-shadow: 0 2px 10px rgba(0,0,0,0.5); animation: pulseText 1.5s infinite; letter-spacing: 0.3px; }
        @keyframes pulseText { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }

        .ham-wrap { position: relative; display: inline-flex; align-items: center; justify-content: center; }
        .ham-badge { position: absolute; top: -4px; right: -4px; background: #ef4444; color: #fff; font-size: 10px; font-weight: bold; min-width: 16px; height: 16px; border-radius: 4px; display: none; align-items: center; justify-content: center; padding: 0 3px; line-height: 1; box-shadow: 0 2px 6px rgba(239,68,68,0.5); pointer-events: none; }

        .help-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.65); backdrop-filter: blur(10px); z-index: 9000; display: none; justify-content: center; align-items: center; padding: 16px; }
        .help-overlay.open { display: flex; }
        .help-card { background: #1e293b; border: 1px solid rgba(255,255,255,0.08); border-radius: 24px; width: 100%; max-width: 480px; max-height: 88vh; display: flex; flex-direction: column; overflow: hidden; animation: helpIn 0.3s cubic-bezier(0.2,0.8,0.2,1); }
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

        .chat-trans-overlay { position: absolute; inset: 0; background: #0f172a; z-index: 800; display: none; justify-content: center; align-items: center; flex-direction: column; gap: 16px; }
        .chat-trans-overlay.active { display: flex; }
        .chat-trans-spin-wrap { position: relative; width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; }
        .chat-trans-spin-outer { position: absolute; inset: 0; border: 3px solid rgba(99,102,241,0.15); border-top-color: #6366f1; border-radius: 50%; animation: spin 1s linear infinite; }
        .chat-trans-spin-inner { position: absolute; inset: 10px; border: 2px solid rgba(99,102,241,0.1); border-bottom-color: #a5b4fc; border-radius: 50%; animation: spin 0.7s linear infinite reverse; }
        .chat-trans-dot { width: 8px; height: 8px; background: #6366f1; border-radius: 50%; box-shadow: 0 0 10px rgba(99,102,241,0.8); animation: ctDotPulse 1.5s ease-in-out infinite; }
        @keyframes ctDotPulse { 0%,100%{transform:scale(1);opacity:1} 50%{transform:scale(0.6);opacity:0.5} }
        .chat-trans-name { color: #64748b; font-size: 13px; animation: ctNamePulse 1.5s ease-in-out infinite; letter-spacing: 0.3px; }
        @keyframes ctNamePulse { 0%,100%{opacity:0.7} 50%{opacity:1} }
        .chat-trans-sub { color: #334155; font-size: 11px; }

        .emoji-display { font-family: 'Noto Color Emoji', 'Segoe UI Emoji', 'Apple Color Emoji', 'Twemoji Mozilla', 'EmojiOne Color', sans-serif !important; font-style: normal; line-height: 1.2; text-rendering: optimizeLegibility; -webkit-font-smoothing: antialiased; }

        .pin-bar { background: linear-gradient(90deg, rgba(99,102,241,0.14) 0%, rgba(79,70,229,0.09) 100%); border-bottom: 1px solid rgba(99,102,241,0.25); padding: 0 12px; display: none; align-items: center; gap: 8px; flex-shrink: 0; min-height: 52px; max-height: 56px; transition: background 0.2s; position: relative; overflow: hidden; }
        .pin-bar::before { content:''; position:absolute; inset:0; background: linear-gradient(90deg, rgba(99,102,241,0.18), transparent); opacity:0; transition: opacity 0.2s; }
        .pin-bar:hover::before { opacity:1; }
        .pin-bar.active { display: flex; }
        .pin-bar-left { display:flex; align-items:center; gap:10px; flex:1; min-width:0; cursor:pointer; height:100%; padding: 10px 0; }
        .pin-bar-icon-wrap { display:flex; align-items:center; justify-content:center; width:38px; height:38px; background:rgba(99,102,241,0.22); border-radius:10px; flex-shrink:0; }
        .pin-bar-icon { font-size:18px; display:block; }
        .pin-bar-texts { flex:1; min-width:0; }
        .pin-bar-label { font-size:11px; color:#818cf8; font-weight:bold; letter-spacing:0.6px; display:block; line-height:1.3; text-transform:uppercase; margin-bottom:4px; }
        .pin-bar-preview { font-size:14px; color:#e2e8f0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:block; line-height:1.5; font-weight:bold; }
        .pin-bar-nav { display:flex; align-items:center; gap:4px; flex-shrink:0; }
        .pin-bar-nav-btn { width:30px; height:30px; border-radius:8px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.1); color:#94a3b8; font-size:15px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.18s; user-select:none; }
        .pin-bar-nav-btn:hover { background:rgba(99,102,241,0.2); border-color:rgba(99,102,241,0.4); color:#fff; }
        .pin-bar-count { font-size:11px; color:#6366f1; font-weight:bold; min-width:26px; text-align:center; }
        .pin-bar-unpin { width:30px; height:30px; border-radius:8px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.08); color:#64748b; font-size:13px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.18s; flex-shrink:0; margin-right:2px; }
        .pin-bar-unpin:hover { background:rgba(239,68,68,0.15); border-color:rgba(239,68,68,0.35); color:#ef4444; }

        .pin-modal-overlay { position:fixed; inset:0; z-index:9500; display:flex; justify-content:center; align-items:center; padding:20px; pointer-events:none; }
        .pin-modal-overlay.active { pointer-events:all; }
        .pin-modal-backdrop { position:absolute; inset:0; background:rgba(0,0,0,0.0); backdrop-filter:blur(0px); transition: background 0.3s ease, backdrop-filter 0.3s ease; }
        .pin-modal-overlay.active .pin-modal-backdrop { background:rgba(0,0,0,0.75); backdrop-filter:blur(12px); }
        .pin-modal-card { background:linear-gradient(145deg,#1a2540,#1e293b); border:1px solid rgba(99,102,241,0.25); border-radius:24px; width:100%; max-width:600px; max-height:88vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 40px 100px rgba(0,0,0,0.8), 0 0 0 1px rgba(255,255,255,0.04); position:relative; z-index:1; transform:scale(0.93) translateY(18px); opacity:0; transition:transform 0.3s cubic-bezier(0.2,0.85,0.25,1), opacity 0.3s ease; }
        .pin-modal-overlay.active .pin-modal-card { transform:scale(1) translateY(0); opacity:1; }
        .pin-modal-header { padding:16px 18px 14px; border-bottom:1px solid rgba(255,255,255,0.06); display:flex; align-items:center; gap:10px; flex-shrink:0; }
        .pin-modal-header-icon { width:34px; height:34px; background:rgba(99,102,241,0.2); border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; }
        .pin-modal-title-wrap { flex:1; }
        .pin-modal-title { font-family:'AppBold'; font-size:15px; color:#fff; display:block; }
        .pin-modal-subtitle { font-size:11px; color:#64748b; display:block; margin-top:1px; }
        .pin-modal-close { width:32px; height:32px; border-radius:10px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.08); color:#64748b; font-size:16px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.18s; flex-shrink:0; }
        .pin-modal-close:hover { background:rgba(239,68,68,0.15); border-color:rgba(239,68,68,0.3); color:#ef4444; }
        .pin-modal-body { overflow-y:auto; padding:14px 16px 20px; -webkit-overflow-scrolling:touch; display:flex; flex-direction:column; gap:10px; }
        .pin-modal-body::-webkit-scrollbar { width:4px; }
        .pin-modal-body::-webkit-scrollbar-track { background:transparent; }
        .pin-modal-body::-webkit-scrollbar-thumb { background:rgba(99,102,241,0.25); border-radius:4px; }
        .pin-modal-empty { text-align:center; padding:48px 20px; color:#475569; }
        .pin-modal-empty-icon { font-size:36px; display:block; margin-bottom:10px; opacity:0.5; }
        .pin-modal-empty-text { font-size:13px; }
        .pin-modal-footer { padding:12px 16px; border-top:1px solid rgba(255,255,255,0.05); display:none; gap:8px; flex-shrink:0; }
        .pin-modal-footer.has-pins { display:flex; }
        .pin-modal-unpin-all { flex:1; padding:9px; border-radius:12px; background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.2); color:#fca5a5; font-size:13px; cursor:pointer; transition:0.18s; font-family:'AppFont'; }
        .pin-modal-unpin-all:hover { background:rgba(239,68,68,0.2); border-color:rgba(239,68,68,0.4); color:#fff; }

        .pin-item { background:rgba(15,23,42,0.7); border:1px solid rgba(255,255,255,0.06); border-radius:16px; padding:13px 14px; cursor:pointer; transition:border-color 0.18s, background 0.18s, transform 0.15s; position:relative; animation:pinItemIn 0.25s ease; }
        @keyframes pinItemIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
        .pin-item:hover { background:rgba(99,102,241,0.08); border-color:rgba(99,102,241,0.35); transform:translateY(-1px); }
        .pin-item:active { transform:translateY(0); }
        .pin-item-top { display:flex; align-items:flex-start; gap:10px; }
        .pin-item-pin-mark { width:4px; flex-shrink:0; height:100%; position:absolute; right:0; top:0; bottom:0; background:linear-gradient(180deg,#6366f1,#4f46e5); border-radius:0 16px 16px 0; }
        .pin-item-body { flex:1; min-width:0; padding-right:6px; }
        .pin-item-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:6px; }
        .pin-item-sender { font-size:12px; color:#818cf8; font-weight:bold; display:flex; align-items:center; gap:5px; }
        .pin-item-time { font-size:10px; color:#334155; background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.06); padding:2px 7px; border-radius:6px; }
        .pin-item-content { font-size:13px; color:#cbd5e1; line-height:1.6; word-break:break-word; }
        .pin-item-content-truncated { display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
        .pin-item-media { width:100%; max-height:180px; object-fit:cover; border-radius:10px; margin-top:8px; display:block; border:1px solid rgba(255,255,255,0.06); }
        .pin-item-voice { width:100%; margin-top:8px; border-radius:10px; height:38px; }
        .pin-item-file-box { display:flex; align-items:center; gap:10px; background:rgba(0,0,0,0.25); border:1px solid rgba(255,255,255,0.06); border-radius:10px; padding:10px 12px; margin-top:8px; }
        .pin-item-file-icon { font-size:22px; flex-shrink:0; }
        .pin-item-file-name { font-size:12px; color:#e2e8f0; flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .pin-item-file-dl { background:rgba(99,102,241,0.2); border:1px solid rgba(99,102,241,0.3); color:#a5b4fc; padding:4px 10px; border-radius:8px; font-size:11px; text-decoration:none; transition:0.15s; white-space:nowrap; }
        .pin-item-file-dl:hover { background:rgba(99,102,241,0.35); color:#fff; }
        .pin-item-actions { display:flex; gap:6px; align-items:center; flex-shrink:0; }
        .pin-item-go { width:30px; height:30px; border-radius:9px; background:rgba(99,102,241,0.12); border:1px solid rgba(99,102,241,0.2); color:#818cf8; font-size:14px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.18s; }
        .pin-item-go:hover { background:rgba(99,102,241,0.25); color:#fff; }
        .pin-item-del { width:30px; height:30px; border-radius:9px; background:rgba(239,68,68,0.07); border:1px solid rgba(239,68,68,0.15); color:#ef4444; font-size:13px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.18s; opacity:0; }
        .pin-item:hover .pin-item-del { opacity:1; }
        .pin-item-del:hover { background:rgba(239,68,68,0.2); border-color:rgba(239,68,68,0.4); }
        .pin-item-loading { display:flex; align-items:center; justify-content:center; gap:10px; padding:20px; color:#475569; font-size:13px; }
        .pin-item-spinner { width:18px; height:18px; border:2px solid rgba(99,102,241,0.2); border-top-color:#6366f1; border-radius:50%; animation:spin 0.8s linear infinite; flex-shrink:0; }

        .pin-viewer-overlay { position:fixed; inset:0; z-index:9800; display:flex; justify-content:center; align-items:center; pointer-events:none; }
        .pin-viewer-overlay.active { pointer-events:all; }
        .pin-viewer-backdrop { position:absolute; inset:0; background:rgba(0,0,0,0); backdrop-filter:blur(0px); transition:background 0.28s, backdrop-filter 0.28s; }
        .pin-viewer-overlay.active .pin-viewer-backdrop { background:rgba(0,0,0,0.88); backdrop-filter:blur(18px); }
        .pin-viewer-card { background:linear-gradient(145deg,#111827,#1e293b); border:1px solid rgba(99,102,241,0.2); border-radius:22px; width:100%; max-width:540px; max-height:88vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 50px 120px rgba(0,0,0,0.9); position:relative; z-index:1; transform:scale(0.9) translateY(24px); opacity:0; transition:transform 0.3s cubic-bezier(0.2,0.85,0.25,1), opacity 0.28s ease; }
        .pin-viewer-overlay.active .pin-viewer-card { transform:scale(1) translateY(0); opacity:1; }
        .pin-viewer-header { padding:14px 16px 12px; border-bottom:1px solid rgba(255,255,255,0.06); display:flex; align-items:center; gap:10px; flex-shrink:0; }
        .pin-viewer-sender { flex:1; display:flex; flex-direction:column; gap:2px; }
        .pin-viewer-sender-name { font-size:13px; color:#a5b4fc; font-weight:bold; }
        .pin-viewer-sender-time { font-size:10px; color:#475569; }
        .pin-viewer-close { width:32px; height:32px; border-radius:10px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.08); color:#64748b; font-size:16px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.18s; flex-shrink:0; }
        .pin-viewer-close:hover { background:rgba(239,68,68,0.15); border-color:rgba(239,68,68,0.3); color:#ef4444; }
        .pin-viewer-body { flex:1; overflow-y:auto; padding:16px; -webkit-overflow-scrolling:touch; display:flex; flex-direction:column; gap:12px; }
        .pin-viewer-body::-webkit-scrollbar { width:4px; }
        .pin-viewer-body::-webkit-scrollbar-thumb { background:rgba(99,102,241,0.25); border-radius:4px; }
        .pin-viewer-img { width:100%; max-height:380px; object-fit:contain; border-radius:14px; display:block; background:#0f172a; cursor:zoom-in; }
        .pin-viewer-video { width:100%; max-height:340px; border-radius:14px; display:block; background:#0f172a; }
        .pin-viewer-audio { width:100%; border-radius:12px; margin-top:4px; }
        .pin-viewer-file-box { display:flex; align-items:center; gap:14px; background:rgba(0,0,0,0.3); border:1px solid rgba(255,255,255,0.08); border-radius:14px; padding:14px 16px; }
        .pin-viewer-file-icon { font-size:32px; flex-shrink:0; }
        .pin-viewer-file-info { flex:1; min-width:0; }
        .pin-viewer-file-name { font-size:14px; color:#e2e8f0; font-weight:bold; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:block; }
        .pin-viewer-file-dl { display:inline-flex; align-items:center; gap:6px; background:linear-gradient(135deg,#6366f1,#4f46e5); border:none; color:#fff; padding:8px 18px; border-radius:10px; font-size:13px; text-decoration:none; cursor:pointer; transition:0.2s; margin-top:8px; font-family:'AppFont'; }
        .pin-viewer-file-dl:hover { background:linear-gradient(135deg,#818cf8,#6366f1); transform:translateY(-1px); }
        .pin-viewer-text { font-size:14px; color:#e2e8f0; line-height:1.7; white-space:pre-wrap; word-break:break-word; background:rgba(255,255,255,0.03); border:1px solid rgba(255,255,255,0.06); border-radius:12px; padding:14px 16px; }
        .pin-viewer-footer { padding:12px 16px; border-top:1px solid rgba(255,255,255,0.05); display:flex; gap:8px; flex-shrink:0; }
        .pin-viewer-goto { flex:1; padding:10px; border-radius:12px; background:rgba(99,102,241,0.15); border:1px solid rgba(99,102,241,0.25); color:#a5b4fc; font-size:13px; cursor:pointer; transition:0.18s; font-family:'AppFont'; }
        .pin-viewer-goto:hover { background:rgba(99,102,241,0.25); color:#fff; }
        .pin-viewer-unpin { padding:10px 16px; border-radius:12px; background:rgba(239,68,68,0.1); border:1px solid rgba(239,68,68,0.2); color:#fca5a5; font-size:13px; cursor:pointer; transition:0.18s; font-family:'AppFont'; }
        .pin-viewer-unpin:hover { background:rgba(239,68,68,0.2); color:#fff; }

        .search-btn { background:none; border:none; cursor:pointer; color:#94a3b8; font-size:18px; padding:6px; border-radius:8px; transition:color 0.15s, background 0.15s; display:flex; align-items:center; justify-content:center; width:34px; height:34px; }
        .search-btn:hover { color:#a5b4fc; background:rgba(165,180,252,0.1); }
        .search-panel { position:absolute; top:0; left:0; right:0; background:#0f172a; z-index:1200; display:none; align-items:center; gap:8px; padding:10px 14px; border-bottom:1px solid #1e293b; box-shadow:0 4px 20px rgba(0,0,0,0.4); }
        .search-panel.active { display:flex; }
        .search-input-wrap { flex:1; position:relative; }
        .search-input-field { width:100%; background:#1e293b; border:1px solid #334155; border-radius:10px; padding:8px 36px 8px 12px; color:#e2e8f0; font-size:14px; font-family:'AppFont'; outline:none; transition:border-color 0.2s; box-sizing:border-box; }
        .search-input-field:focus { border-color:#6366f1; }
        .search-input-field::placeholder { color:#64748b; }
        .search-clear-btn { position:absolute; left:10px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:#64748b; font-size:16px; padding:2px; line-height:1; }
        .search-close-btn { background:none; border:none; cursor:pointer; color:#94a3b8; font-size:20px; padding:4px; }
        .search-results-panel { position:absolute; top:55px; left:0; right:0; background:#0f172a; border-bottom:1px solid #1e293b; z-index:1199; max-height:60vh; overflow-y:auto; display:none; box-shadow:0 8px 30px rgba(0,0,0,0.5); }
        .search-results-panel.active { display:block; }
        .search-result-item { padding:12px 16px; border-bottom:1px solid rgba(255,255,255,0.04); cursor:pointer; transition:background 0.15s; }
        .search-result-item:hover { background:rgba(99,102,241,0.08); }
        .search-result-sender { font-size:12px; color:#a5b4fc; font-weight:bold; margin-bottom:3px; }
        .search-result-text { font-size:13px; color:#cbd5e1; line-height:1.5; word-break:break-word; }
        .search-result-text mark { background:rgba(99,102,241,0.35); color:#e2e8f0; border-radius:3px; padding:0 2px; }
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
        .file-share-btn { background:rgba(99,102,241,0.15); border:1px solid rgba(99,102,241,0.3); color:#a5b4fc; width:32px; height:32px; border-radius:50%; display:flex; justify-content:center; align-items:center; cursor:pointer; transition:0.2s; font-size:14px; flex-shrink:0; }
        .file-share-btn:hover { background:rgba(99,102,241,0.3); color:#fff; border-color:#6366f1; }
        .file-size-tag { font-size:10px; color:#64748b; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.07); border-radius:6px; padding:2px 7px; margin-top:3px; display:inline-block; }
        .srp-overlay { position:fixed; inset:0; z-index:9600; display:flex; justify-content:center; align-items:center; padding:20px; pointer-events:none; }
        .srp-overlay.active { pointer-events:all; }
        .srp-backdrop { position:absolute; inset:0; background:rgba(0,0,0,0); backdrop-filter:blur(0px); transition:background 0.3s, backdrop-filter 0.3s; }
        .srp-overlay.active .srp-backdrop { background:rgba(0,0,0,0.82); backdrop-filter:blur(16px); }
        .srp-card { background:linear-gradient(145deg,#111827,#1a2540); border:1px solid rgba(99,102,241,0.3); border-radius:24px; width:100%; max-width:520px; max-height:85vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 40px 100px rgba(0,0,0,0.85), 0 0 0 1px rgba(255,255,255,0.04); position:relative; z-index:1; transform:scale(0.88) translateY(30px); opacity:0; transition:transform 0.35s cubic-bezier(0.2,0.85,0.25,1), opacity 0.3s ease; }
        .srp-overlay.active .srp-card { transform:scale(1) translateY(0); opacity:1; }
        .srp-header { padding:16px 18px 14px; border-bottom:1px solid rgba(255,255,255,0.07); display:flex; align-items:center; gap:12px; flex-shrink:0; background:rgba(99,102,241,0.06); }
        .srp-header-icon { width:40px; height:40px; background:linear-gradient(135deg,rgba(99,102,241,0.35),rgba(79,70,229,0.25)); border:1px solid rgba(99,102,241,0.4); border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:19px; flex-shrink:0; }
        .srp-header-info { flex:1; min-width:0; }
        .srp-sender { font-family:'AppBold'; font-size:14px; color:#a5b4fc; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .srp-time { font-size:11px; color:#475569; margin-top:2px; }
        .srp-close { width:32px; height:32px; border-radius:10px; background:rgba(255,255,255,0.06); border:1px solid rgba(255,255,255,0.08); color:#64748b; font-size:16px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:0.18s; flex-shrink:0; }
        .srp-close:hover { background:rgba(239,68,68,0.18); border-color:rgba(239,68,68,0.35); color:#ef4444; }
        .srp-body { flex:1; overflow-y:auto; padding:20px; -webkit-overflow-scrolling:touch; scrollbar-width:none; -ms-overflow-style:none; }
        .srp-body::-webkit-scrollbar { display:none; }
        .srp-label { font-size:10px; color:#6366f1; font-weight:bold; letter-spacing:1px; text-transform:uppercase; margin-bottom:10px; display:flex; align-items:center; gap:6px; }
        .srp-label::before { content:''; display:inline-block; width:16px; height:2px; background:linear-gradient(90deg,#6366f1,#a5b4fc); border-radius:1px; }
        .srp-message-box { background:rgba(255,255,255,0.04); border:1px solid rgba(255,255,255,0.08); border-right:3px solid #6366f1; border-radius:14px; padding:16px 18px; font-size:14px; color:#e2e8f0; line-height:1.8; word-break:break-word; white-space:pre-wrap; }
        .srp-message-box mark { background:rgba(99,102,241,0.4); color:#fff; border-radius:4px; padding:1px 4px; font-weight:bold; }
        .srp-loading { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:14px; padding:40px 20px; }
        .srp-spinner { width:42px; height:42px; border:3px solid rgba(99,102,241,0.2); border-top-color:#6366f1; border-radius:50%; animation:srpSpin 0.9s linear infinite; box-shadow:0 0 20px rgba(99,102,241,0.3); }
        @keyframes srpSpin { 100% { transform:rotate(360deg); } }
        .srp-spinner-text { color:#64748b; font-size:13px; animation:srpPulse 1.4s ease-in-out infinite; }
        @keyframes srpPulse { 0%,100%{opacity:1} 50%{opacity:0.45} }
        .srp-footer { padding:14px 18px; border-top:1px solid rgba(255,255,255,0.06); display:flex; gap:10px; flex-shrink:0; }
        .srp-goto-btn { flex:1; padding:11px; border-radius:13px; background:linear-gradient(135deg,rgba(99,102,241,0.25),rgba(79,70,229,0.18)); border:1px solid rgba(99,102,241,0.4); color:#a5b4fc; font-size:13px; cursor:pointer; transition:all 0.2s; font-family:'AppFont'; display:flex; align-items:center; justify-content:center; gap:7px; font-weight:bold; }
        .srp-goto-btn:hover { background:linear-gradient(135deg,rgba(99,102,241,0.45),rgba(79,70,229,0.35)); color:#fff; border-color:#6366f1; transform:translateY(-1px); box-shadow:0 6px 20px rgba(99,102,241,0.3); }
        .srp-cancel-btn { padding:11px 18px; border-radius:13px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); color:#64748b; font-size:13px; cursor:pointer; transition:all 0.2s; font-family:'AppFont'; }
        .srp-cancel-btn:hover { background:rgba(255,255,255,0.09); color:#94a3b8; }

        .reaction-container { display: inline-block; margin-top: 6px; }
        .reaction-btn { width: 28px; height: 28px; border-radius: 10px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 16px; transition: all 0.2s; }
        .reaction-btn:hover { background: rgba(255,255,255,0.12); border-color: rgba(255,255,255,0.2); transform: scale(1.1); }
        #reactionPickerGlobal { position: fixed; display: flex; gap: 6px; align-items: center; background: rgba(22,32,52,0.99); backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px); border: 1px solid rgba(255,255,255,0.18); border-radius: 16px; padding: 10px 12px; box-shadow: 0 16px 50px rgba(0,0,0,0.65), 0 0 0 1px rgba(255,255,255,0.05); z-index: 99999; white-space: nowrap; opacity: 0; pointer-events: none; transform: scale(0.93) translateY(6px); transition: opacity 0.15s ease, transform 0.15s cubic-bezier(0.2,0.8,0.2,1); }
        #reactionPickerGlobal.show { opacity: 1; pointer-events: auto; transform: scale(1) translateY(0); }
        .reaction-emoji { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 22px; cursor: pointer; transition: all 0.15s; background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); flex-shrink: 0; }
        .reaction-emoji:hover { transform: scale(1.28); background: rgba(99,102,241,0.22); border-color: rgba(99,102,241,0.45); box-shadow: 0 4px 14px rgba(99,102,241,0.35); }
        .reaction-display { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
        .msg-row.me .reaction-display { justify-content: flex-end; }
        .msg-row.other .reaction-display { justify-content: flex-start; }
        .reaction-item { display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 12px; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); font-size: 14px; cursor: pointer; transition: all 0.2s; }
        .reaction-item:hover { background: rgba(255,255,255,0.12); border-color: rgba(255,255,255,0.2); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
        .reaction-item.my-reaction { background: linear-gradient(135deg, rgba(99,102,241,0.35), rgba(79,70,229,0.25)); border-color: rgba(99,102,241,0.6); box-shadow: 0 0 15px rgba(99,102,241,0.3), inset 0 1px 2px rgba(255,255,255,0.1); }
        .reaction-item.my-reaction:hover { background: linear-gradient(135deg, rgba(99,102,241,0.45), rgba(79,70,229,0.35)); border-color: rgba(99,102,241,0.7); box-shadow: 0 0 20px rgba(99,102,241,0.4), inset 0 1px 2px rgba(255,255,255,0.15); }
        .reaction-count { font-size: 12px; color: #cbd5e1; font-weight: bold; }
        @keyframes reactionFadeIn { from { opacity: 0; transform: translateY(6px) scale(0.93); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .reaction-tooltip { position: fixed; background: rgba(15,23,42,0.98); backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px); color: #e2e8f0; padding: 8px 12px; border-radius: 10px; font-size: 12px; pointer-events: none; z-index: 10000; border: 1px solid rgba(255,255,255,0.15); box-shadow: 0 8px 30px rgba(0,0,0,0.6); white-space: nowrap; max-width: 200px; overflow: hidden; text-overflow: ellipsis; animation: tooltipFadeIn 0.15s ease; }
        @keyframes tooltipFadeIn { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }

        #emojiPicker {
            background: linear-gradient(145deg, rgba(10,15,30,0.99), rgba(20,30,50,0.98));
            border-top: 1px solid rgba(99,102,241,0.2);
            padding: 10px 10px 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 2px;
            max-height: 200px;
            overflow-y: auto;
            direction: ltr;
            scrollbar-width: thin;
            scrollbar-color: rgba(99,102,241,0.3) transparent;
        }
        #emojiPicker::-webkit-scrollbar { width: 4px; }
        #emojiPicker::-webkit-scrollbar-thumb { background: rgba(99,102,241,0.3); border-radius: 4px; }
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
        .emoji-pick-btn:hover { background: rgba(99,102,241,0.18); transform: scale(1.25); }
        .emoji-pick-btn:active { transform: scale(0.95); }
    </style>
</head>
<body oncontextmenu="return false;" onclick="hideCtx()">

<div id="reactionPickerGlobal">
    <div class="reaction-emoji" data-emoji="❤️">❤️</div>
    <div class="reaction-emoji" data-emoji="👍">👍</div>
    <div class="reaction-emoji" data-emoji="😂">😂</div>
    <div class="reaction-emoji" data-emoji="😮">😮</div>
    <div class="reaction-emoji" data-emoji="😢">😢</div>
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
            <div style="width:34px;height:34px;background:rgba(99,102,241,0.2);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;">📌</div>
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
    <div style="background:linear-gradient(145deg,#0f172a,#1e293b);border:1px solid rgba(99,102,241,0.3);border-radius:24px;width:100%;max-width:420px;padding:0;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,0.8);">
        <div style="padding:20px 22px 16px;border-bottom:1px solid rgba(99,102,241,0.15);display:flex;align-items:center;gap:14px;">
            <div id="profileSticker" style="font-size:44px;line-height:1;"></div>
            <div style="flex:1;min-width:0;">
                <div id="profileUsername" style="font-size:18px;font-weight:bold;color:#e2e8f0;"></div>
                <div id="profileJoinDate" style="font-size:12px;color:#64748b;margin-top:3px;"></div>
            </div>
            <button onclick="closeMyProfile()" style="width:32px;height:32px;border-radius:10px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);color:#94a3b8;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;">✕</button>
        </div>
        <div style="padding:18px 22px;" id="profileBody">
            <div class="profile-row">
                <span class="profile-label">🔐 کد امنیتی</span>
                <span id="profileSecCode" class="profile-val"></span>
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

<div id="phoneModal" style="display:none;position:fixed;inset:0;z-index:9800;background:rgba(0,0,0,0.85);backdrop-filter:blur(16px);align-items:center;justify-content:center;padding:20px;">
    <div style="background:linear-gradient(145deg,#0f172a,#1e293b);border:1px solid rgba(99,102,241,0.3);border-radius:24px;width:100%;max-width:400px;padding:24px;box-shadow:0 30px 80px rgba(0,0,0,0.8);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
            <span style="font-size:16px;font-weight:bold;color:#e2e8f0;">🔐 تنظیم احراز هویت</span>
            <button onclick="closePhoneSettings()" style="width:30px;height:30px;border-radius:8px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);color:#94a3b8;cursor:pointer;font-size:15px;">✕</button>
        </div>
        <div id="phoneModalLoading" style="display:none;text-align:center;padding:24px 0;">
            <div style="font-size:28px;margin-bottom:10px;animation:spin 1s linear infinite;display:inline-block;">⏳</div>
            <div style="font-size:13px;color:#64748b;">در حال بارگذاری...</div>
        </div>
        <div id="phoneModalRegistered" style="display:none;">
            <div style="background:rgba(16,185,129,0.08);border:1.5px solid rgba(16,185,129,0.35);border-radius:16px;padding:16px 18px;margin-bottom:14px;">
                <div style="font-size:12px;color:#6ee7b7;margin-bottom:4px;font-weight:bold;">✅ احراز هویت ثبت شده</div>
                <div id="phoneModalRegisteredNum" dir="ltr" style="font-size:18px;font-weight:bold;color:#a7f3d0;letter-spacing:2px;font-family:monospace;"></div>
                <div id="phoneModalRegisteredPass" style="font-size:12px;color:#34d399;margin-top:4px;display:none;">🔑 رمز احراز هویت هم تنظیم شده</div>
            </div>
            <p style="color:#64748b;font-size:12px;margin-bottom:14px;line-height:1.7;background:rgba(16,185,129,0.04);border:1px solid rgba(16,185,129,0.12);border-radius:10px;padding:10px 12px;">در ورودهای بعدی می‌توانید با شماره موبایل یا رمز احراز هویت وارد شوید.</p>
            <button onclick="closePhoneSettings()" style="width:100%;padding:11px;background:linear-gradient(135deg,rgba(16,185,129,0.2),rgba(5,150,105,0.15));border:1px solid rgba(16,185,129,0.4);border-radius:12px;color:#6ee7b7;font-size:14px;cursor:pointer;font-weight:bold;">متوجه شدم</button>
        </div>
        <div id="phoneModalRegister" style="display:block;">
            <p style="color:#94a3b8;font-size:13px;margin-bottom:12px;line-height:1.7;">برای احراز هویت در ورودهای بعدی، یکی یا هر دو گزینه را پر کنید:</p>
            <div style="background:rgba(99,102,241,0.05);border:1px solid rgba(99,102,241,0.15);border-radius:12px;padding:12px 14px;margin-bottom:14px;font-size:12px;color:#64748b;line-height:1.7;">
                💡 اگر <b style="color:#a5b4fc">هر دو</b> را وارد کنید، در آینده با یکی از آن‌ها می‌توانید وارد شوید. اگر فقط یکی را وارد کنید، همان برای احراز هویت استفاده می‌شود.
            </div>
            <label style="font-size:12px;color:#818cf8;display:block;margin-bottom:6px;font-weight:bold;">📱 شماره موبایل</label>
            <input type="tel" id="phoneInput" maxlength="11" placeholder="مثال: 09123456789 (اختیاری)" dir="ltr"
                style="width:100%;padding:11px 14px;background:rgba(255,255,255,0.05);border:1px solid rgba(99,102,241,0.25);border-radius:11px;color:#e2e8f0;font-size:15px;margin-bottom:12px;box-sizing:border-box;"
                oninput="this.value=this.value.replace(/[^0-9]/g,'').substr(0,11)">
            <label style="font-size:12px;color:#818cf8;display:block;margin-bottom:6px;font-weight:bold;">🔑 رمز احراز هویت</label>
            <div style="position:relative;">
                <input type="password" id="phoneAuthPassSave" maxlength="20" placeholder="۸ تا ۲۰ کاراکتر انگلیسی (اختیاری)" dir="ltr"
                    style="width:100%;padding:11px 44px 11px 14px;background:rgba(255,255,255,0.05);border:1px solid rgba(99,102,241,0.25);border-radius:11px;color:#e2e8f0;font-size:14px;margin-bottom:4px;box-sizing:border-box;"
                    oninput="this.value=this.value.replace(/[\u0600-\u06FF\u0750-\u077F]/g,'').substr(0,20)" onkeydown="if(event.key==='Enter')savePhoneNumber()">
                <button onclick="togglePassVisibility('phoneAuthPassSave',this)" type="button"
                    style="position:absolute;left:10px;top:50%;transform:translateY(-60%);background:none;border:none;cursor:pointer;color:#64748b;font-size:17px;padding:2px;">👁</button>
            </div>
            <div id="phonePassHint" style="font-size:11px;color:#64748b;margin-bottom:12px;line-height:1.6;">حروف فارسی مجاز نیست • حداقل ۸ کاراکتر</div>
            <button onclick="savePhoneNumber()" style="width:100%;padding:12px;background:linear-gradient(135deg,rgba(99,102,241,0.35),rgba(79,70,229,0.3));border:1px solid rgba(99,102,241,0.55);border-radius:12px;color:#a5b4fc;font-size:14px;cursor:pointer;font-weight:bold;">💾 ذخیره</button>
            <div id="phoneSaveMsg" style="display:none;margin-top:12px;padding:10px 14px;border-radius:10px;font-size:13px;"></div>
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
        <div style="background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.3);border-radius:14px;padding:16px;text-align:center;margin-bottom:18px;">
            <div style="font-size:11px;color:#818cf8;margin-bottom:8px;letter-spacing:1px;">کد تأیید</div>
            <div id="phoneOtpDisplay" style="font-size:28px;font-weight:bold;color:#a5b4fc;letter-spacing:6px;font-family:monospace;"></div>
        </div>
        <input type="text" id="phoneOtpInput" maxlength="5" placeholder="کد ۵ رقمی را وارد کنید" dir="ltr" style="width:100%;padding:13px 14px;background:rgba(255,255,255,0.05);border:1px solid rgba(99,102,241,0.3);border-radius:12px;color:#e2e8f0;font-size:18px;text-align:center;letter-spacing:4px;margin-bottom:14px;box-sizing:border-box;font-family:monospace;" oninput="this.value=this.value.replace(/[^0-9]/g,'').substr(0,5)">
        <button onclick="submitPhoneOtp()" style="width:100%;padding:13px;background:linear-gradient(135deg,rgba(99,102,241,0.35),rgba(79,70,229,0.3));border:1px solid rgba(99,102,241,0.6);border-radius:12px;color:#a5b4fc;font-size:14px;cursor:pointer;font-weight:bold;margin-bottom:10px;">تأیید و ورود</button>
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
    <div class="upload-prog-title">
        <span class="upload-prog-name" id="uploadProgName">در حال آپلود...</span>
        <span class="upload-prog-pct" id="uploadProgPct">0%</span>
    </div>
    <div class="upload-prog-bar-bg">
        <div class="upload-prog-bar" id="uploadProgBar"></div>
    </div>
</div>

<div id="welcomeModal" class="modal-overlay">
    <div class="modal">
        <div class="modal-icon">👋</div>
        <div class="modal-title">به بلک واکر خوش آمدید</div>
        <p class="modal-text">لطفاً جهت حفظ محیطی امن، قوانین را رعایت کنید:</p>
        <ul style="text-align: right; margin-bottom: 25px; color: #cbd5e1; font-size: 13px; padding-right: 25px; line-height: 1.8;">
            <li>🔹 رعایت ادب و احترام متقابل</li>
            <li>🔹 عدم ارسال محتوای غیراخلاقی</li>
            <li>🔹 جلوگیری از ارسال اسپم</li>
        </ul>
        <button class="modal-btn btn-green" onclick="localStorage.setItem('welcomeShown','true'); closeModal('welcomeModal'); checkAndShowSecurityCode();">متوجه شدم</button>
    </div>
</div>

<div id="securityCodeModal" class="modal-overlay">
    <div class="modal" style="border-color:#10b981; background: linear-gradient(145deg, #1e293b, #064e3b);">
        <div class="modal-icon">🔐</div>
        <div class="modal-title" style="color:#10b981">کد امنیتی اکانت شما</div>
        <p class="modal-text" style="color:#fff; font-size:14px; line-height:1.8">
            این کد اختصاصی برای شما ساخته شده است. <br>
            <span style="color:#fca5a5; font-weight:bold;">توجه:</span> اگر با دستگاه دیگری قصد ورود داشتید یا نشست شما پاک شد، این کد تنها راه بازگردانی اکانت شماست! حتماً آن را ذخیره کنید.
        </p>
        <div class="copy-code-box" style="margin-top:15px; border-color:#10b981; background:rgba(16,185,129,0.2);">
            <span id="newSecurityCodeDisplay" class="code-text" style="color:#34d399"></span>
            <button class="copy-btn" style="background:#10b981" onclick="copyNewSecurityCode()">📋</button>
        </div>
        <div style="display:flex;gap:8px;margin-top:10px;">
            <button class="modal-btn btn-gray" style="flex:1" onclick="closeModal('securityCodeModal')">ذخیره کردم</button>
            <button class="modal-btn" id="resetSecCodeBtn" style="flex:1;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#f87171;" onclick="resetSecurityCode()">🔄 ریست کد</button>
        </div>
        <p style="font-size:11px;color:#f87171;margin-top:8px;text-align:center;display:none;" id="resetSecWarning">⚠️ کد قدیمی برای همیشه حذف می‌شود!</p>
    </div>
</div>

<div id="globalAlertModal" class="modal-overlay">
    <div class="modal" style="border-color:#f59e0b; background: linear-gradient(145deg, #1e293b, #1e1b15);">
        <div class="modal-icon">📢</div>
        <div class="modal-title" style="color:#f59e0b">اطلاعیه سراسری</div>
        <p id="globalAlertText" class="modal-text" style="color:#fff; font-weight:bold; font-size:15px;"></p>
        <button class="modal-btn btn-gray" onclick="closeModal('globalAlertModal')">خواندم</button>
    </div>
</div>

<div id="phoneAuthModal" style="display:none;position:fixed;inset:0;z-index:99990;background:rgba(0,0,0,0.97);backdrop-filter:blur(20px);align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto;">
    <div style="background:linear-gradient(145deg,#0a0f1e,#111827);border:1px solid rgba(99,102,241,0.4);border-radius:24px;width:100%;max-width:400px;padding:28px 24px;box-shadow:0 30px 80px rgba(0,0,0,0.9);position:relative;margin:auto;">
        <div style="text-align:center;margin-bottom:20px;">
            <div style="font-size:42px;margin-bottom:10px;" id="phoneAuthIcon">🔐</div>
            <div style="font-size:18px;font-weight:bold;color:#e2e8f0;" id="phoneAuthTitle">احراز هویت</div>
            <div style="font-size:13px;color:#64748b;margin-top:8px;line-height:1.7;" id="phoneAuthDesc">شماره موبایل یا رمز احراز هویت خود را وارد کنید.</div>
        </div>
        <div id="phoneAuthLockBox" style="display:none;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:12px;padding:12px 16px;text-align:center;margin-bottom:16px;">
            <span style="color:#fca5a5;font-size:13px;" id="phoneAuthLockMsg">حساب قفل شده</span>
        </div>
        <div id="phoneAuthTabs" style="display:flex;gap:6px;margin-bottom:16px;background:rgba(255,255,255,0.04);border-radius:12px;padding:4px;">
            <button id="phoneAuthTabPhone" onclick="switchPhoneAuthTab('phone')" style="flex:1;padding:9px;border-radius:9px;border:none;background:rgba(99,102,241,0.35);color:#a5b4fc;font-size:13px;cursor:pointer;font-family:inherit;font-weight:bold;transition:0.2s;">📱 شماره موبایل</button>
            <button id="phoneAuthTabPass" onclick="switchPhoneAuthTab('pass')" style="flex:1;padding:9px;border-radius:9px;border:none;background:transparent;color:#64748b;font-size:13px;cursor:pointer;font-family:inherit;font-weight:bold;transition:0.2s;">🔑 رمز احراز هویت</button>
        </div>
        <div id="phoneAuthPanelPhone" style="min-height:60px;">
            <input type="tel" id="phoneAuthInput" maxlength="11" placeholder="شماره موبایل: 09xxxxxxxxx" dir="ltr"
                style="width:100%;padding:13px 14px;background:rgba(255,255,255,0.05);border:1.5px solid rgba(99,102,241,0.3);border-radius:12px;color:#e2e8f0;font-size:16px;margin-bottom:4px;box-sizing:border-box;text-align:center;letter-spacing:2px;display:block;"
                oninput="this.value=this.value.replace(/[^0-9]/g,'').substr(0,11)"
                onkeydown="if(event.key==='Enter')submitPhoneAuth()">
        </div>
        <div id="phoneAuthPanelPass" style="display:none;min-height:60px;">
            <div style="position:relative;">
                <input type="password" id="phoneAuthPassInput" maxlength="20" placeholder="رمز احراز هویت (۸ تا ۲۰ کاراکتر)" dir="ltr"
                    style="width:100%;padding:13px 44px 13px 14px;background:rgba(255,255,255,0.05);border:1.5px solid rgba(99,102,241,0.3);border-radius:12px;color:#e2e8f0;font-size:15px;margin-bottom:4px;box-sizing:border-box;"
                    oninput="this.value=this.value.replace(/[\u0600-\u06FF\u0750-\u077F]/g,'')"
                    onkeydown="if(event.key==='Enter')submitPhoneAuth()">
                <button onclick="togglePassVisibility('phoneAuthPassInput',this)" type="button"
                    style="position:absolute;left:10px;top:50%;transform:translateY(-60%);background:none;border:none;cursor:pointer;color:#64748b;font-size:17px;padding:2px;">👁</button>
            </div>
        </div>
        <div id="phoneAuthAttempts" style="text-align:center;font-size:12px;color:#f87171;margin-bottom:10px;min-height:20px;margin-top:6px;line-height:1.5;"></div>
        <button id="phoneAuthBtn" onclick="submitPhoneAuth()" style="width:100%;padding:13px;background:linear-gradient(135deg,rgba(99,102,241,0.4),rgba(79,70,229,0.3));border:1px solid rgba(99,102,241,0.6);border-radius:12px;color:#a5b4fc;font-size:15px;cursor:pointer;font-weight:bold;font-family:inherit;">تأیید و ورود به حساب</button>
        <div id="phoneAuthNewUser" style="display:none;margin-top:14px;padding:12px;background:rgba(16,185,129,0.07);border:1px solid rgba(16,185,129,0.2);border-radius:10px;font-size:12px;color:#6ee7b7;text-align:center;line-height:1.7;">
            ⚠️ این اولین ورود شماست. شماره موبایل یا رمز احراز هویت ثبت کنید تا در دفعات بعد استفاده شود.
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
        <div style="display:flex; flex-direction:column; gap:10px;">
            <button id="burnedLoginCodeBtn" class="modal-btn btn-green" style="display:none;" onclick="window.location.href='index.php'">🔒 ورود با کد امنیتی</button>
            <button class="modal-btn btn-gray" onclick="window.location.href='index.php'">بازگشت به صفحه ورود</button>
        </div>
    </div>
</div>

<div id="reportUserModal" class="modal-overlay">
    <div class="modal" style="position:relative;">
        <div class="modal-title">گزارش تخلف کاربر</div>
        <p class="modal-text">لطفاً نام کاربر و دلیل گزارش را وارد کنید.</p>
        
        <input type="text" id="reportTargetUser" class="modal-input" placeholder="جستجوی نام کاربری..." autocomplete="off">
        <div id="reportUserSuggestions"></div>
        
        <textarea id="reportReason" class="modal-input" style="height:80px;resize:none;" placeholder="دلیل گزارش..."></textarea>
        
        <div class="captcha-row" style="margin-top:10px;">
            <div class="captcha-text" id="reportCaptchaBox" style="font-size:16px;">Loading...</div>
            <div class="refresh-btn" onclick="refreshReportCaptcha()">↻</div>
        </div>
        <input type="number" id="reportCaptchaInput" class="modal-input" placeholder="کد امنیتی را وارد کنید" required>
        
        <div style="display:flex; gap:10px; margin-top:10px;">
            <button onclick="submitReportUser()" class="modal-btn btn-red" id="btnReportSend">ارسال گزارش</button>
            <button onclick="closeModal('reportUserModal')" class="modal-btn btn-gray">لغو</button>
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
        <div style="display:flex; gap:10px;">
            <button class="modal-btn btn-green" id="confirmYes">بله</button>
            <button class="modal-btn btn-gray" onclick="closeModal('modalConfirm')">خیر</button>
        </div>
    </div>
</div>

<div id="modalPrompt" class="modal-overlay">
    <div class="modal">
        <div class="modal-icon">✏️</div>
        <div id="promptMsg" class="modal-title"></div>
        <input type="text" id="promptInput" class="modal-input">
        <div style="display:flex; gap:10px;">
            <button class="modal-btn btn-green" id="promptOk">تایید</button>
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

<div id="createRoomModal" class="modal-overlay">
    <div class="modal" style="max-width:400px;">
        <div class="modal-icon">✨</div>
        <div class="modal-title">ساخت اتاق و کانال</div>
        <div style="display:flex;gap:6px;margin:12px 0 16px;background:rgba(255,255,255,0.05);border-radius:12px;padding:4px;">
            <button class="cr-tab active" id="crTabRoom" onclick="switchCreateTab('room')">🔒 اتاق شخصی</button>
            <button class="cr-tab" id="crTabChPub" onclick="switchCreateTab('channel_public')">📢 کانال عمومی</button>
            <button class="cr-tab" id="crTabChPriv" onclick="switchCreateTab('channel_private')">🔐 کانال خصوصی</button>
        </div>
        <p class="modal-text" id="crDesc">یک اتاق خصوصی با کد دعوت ۸ رقمی برای حداکثر ۸ نفر.</p>
        <input type="text" id="newRoomName" class="modal-input" placeholder="نام (۴ تا ۱۰ حرف)..." maxlength="10">
        <div style="display:flex;gap:10px;margin-top:10px;">
            <button id="btnCreateRoomSubmit" onclick="submitCreateRoom(event)" class="modal-btn btn-green">ساخت</button>
            <button onclick="closeModal('createRoomModal')" class="modal-btn btn-gray">لغو</button>
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
    <div class="notif-avatar" id="notifAvatar"></div>
    <div class="notif-content">
        <div class="notif-name" id="notifName"></div>
        <div class="notif-text" id="notifText"></div>
        <div class="notif-hint">ورود به گپ ↩</div>
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

<div class="sidebar" id="sidebar">
    <div class="close-menu" onclick="toggleMenu()">✕</div>
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
        <?php if (!$isReportLocked): ?>
        <div class="menu-toggle-item" style="cursor:pointer;" onclick="openReportModal()">
            <span>گزارش کاربر</span>
            <span style="font-size:12px;">⚠️</span>
        </div>
        <?php endif; ?>
        <div class="menu-item" onclick="openMyProfile()">👤 پروفایل من</div>
        <div class="menu-item" onclick="showMySecurityCode()">🔐 کد امنیتی من</div>
        <div class="menu-item" onclick="openPhoneSettings()">📱 شماره موبایل <span id="menuPhoneDisplay" style="font-size:11px;color:#64748b;margin-right:4px;"></span></div>
        <div class="menu-item" onclick="askDeleteAll()">🗑 حذف پیام‌های من</div>
        <div class="menu-item" onclick="openCreateRoom()">✨ ساخت اتاق و کانال</div>
        <div class="menu-item full-width" onclick="askLogout()">خروج از حساب</div>
        <?php if(!empty($donate_link)): ?>
        <div class="menu-item full-width" onclick="openDonate()" style="background:linear-gradient(135deg,rgba(245,158,11,0.2),rgba(217,119,6,0.15));border-color:rgba(245,158,11,0.3);color:#fbbf24;">💛 دونیت / حمایت مالی</div>
        <?php endif; ?>
        <div class="menu-item full-width" id="adminPanelBtn" style="display:none; background:rgba(99,102,241,0.2); color:#a5b4fc" onclick="location.href='admin.php'">⚙ پنل مدیریت پیشرفته</div>
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
                <button class="btn" onclick="toggleMenu()" style="width:35px;height:35px;background:transparent;font-size:22px;">☰</button>
                <span class="ham-badge" id="hamBadge"></span>
            </div>
            <div style="min-width:0; flex:1; overflow:hidden;">
                <span id="chatTitle" class="header-title"></span>
                <span id="chatStatus" class="header-status"></span>
            </div>
        </div>
        <div class="social-icons" style="flex-shrink:0; margin-right:8px;">
            <button class="search-btn" onclick="toggleSearchPanel()" title="جستجو در پیام‌ها" id="searchToggleBtn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="18" height="18"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </button>
            <button onclick="openHelp()" class="social-btn" title="به راهنما نیاز داری؟" style="cursor:pointer; font-size:16px; color:#cbd5e1;">❓</button>
            <?php if(!empty($source_download_link)): ?>
            <a href="<?php echo $source_download_link; ?>" target="_blank" class="social-btn" title="دانلود سورس کد" style="font-size:16px; color:#cbd5e1; text-decoration:none;">⬇</a>
            <?php endif; ?>
            <?php if(!empty($social_github_link)): ?>
            <a href="<?php echo $social_github_link; ?>" target="_blank" class="social-btn" title="GitHub">
                <img src="<?php echo $social_github_icon; ?>" alt="GitHub">
            </a>
            <?php endif; ?>
            <?php if(!empty($social_website_link)): ?>
            <a href="<?php echo $social_website_link; ?>" target="_blank" class="social-btn" title="Website">
                <img src="<?php echo $social_website_icon; ?>" alt="Website">
            </a>
            <?php endif; ?>
            <?php if(!empty($social_telegram_link)): ?>
            <a href="<?php echo $social_telegram_link; ?>" target="_blank" class="social-btn" title="Telegram">
                <img src="<?php echo $social_telegram_icon; ?>" alt="Telegram">
            </a>
            <?php endif; ?>
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

    <div class="input-box" id="inputBox">
        <div class="file-preview" id="filePreview">
            <span style="display:flex;align-items:center;gap:5px;">📎 <b id="fileName" style="color:#fff"></b></span>
            <span onclick="cancelFile()" style="cursor:pointer; color:#ef4444">✕</span>
        </div>
        <div class="reply-bar" id="replyBar">
            <div style="display:flex; flex-direction:column; width:100%">
                <span style="color:#818cf8; font-weight:bold; font-size:11px;">پاسخ به:</span>
                <span id="replyText" style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; font-size:12px;"></span>
            </div>
            <span onclick="cancelReply()" style="cursor:pointer; color:#ef4444; padding:5px;">✕</span>
        </div>

        <div class="input-row" id="inputRow">
            <input type="file" id="fileInput" hidden multiple accept="*">
            <button class="btn" id="attachBtn" onclick="document.getElementById('fileInput').click()">📎</button>
            <button class="btn" id="micBtn" style="touch-action:none;">🎤</button>
            <div style="flex:1;position:relative;display:flex;align-items:center;gap:0;">
                <textarea id="msgInput" placeholder="پیام..." maxlength="1000" style="flex:1;border-radius:20px 0 0 20px;padding-left:20px;"></textarea>
                <button id="emojiBtn" onclick="toggleEmojiPicker()" style="position:absolute;left:8px;bottom:50%;transform:translateY(50%);background:none;border:none;font-size:20px;cursor:pointer;width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:50%;transition:background 0.2s;z-index:2;" title="ایموجی">😊</button>
            </div>
            <button class="btn btn-send" onclick="sendMessage()" style="border-radius:50 50% 50% 0;width:46px;">➤</button>
        </div>
        
        <div id="emojiPicker" style="display:none;"></div>
        
        <div class="voice-rec" id="voiceUI">
            <span style="font-size:11px;opacity:0.8">برای قفل بالا بکشید</span>
            <span id="recTimer" style="font-family:monospace;font-size:16px">00:00</span>
            <button onclick="cancelVoice()" style="background:none;border:none;color:#fff;cursor:pointer">لغو</button>
            <button id="sendVoiceBtn" onclick="stopRecManual()" style="display:none;width:36px;height:36px;border-radius:50%;background:#fff;border:none;color:#ef4444">➤</button>
        </div>
    </div>
</div>

<script>
let csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
let bwNonce = document.querySelector('meta[name="bw-nonce"]').getAttribute('content');
let logoutNonce = document.querySelector('meta[name="logout-nonce"]').getAttribute('content');
let currentRoom = 'گفتگوی عمومی', currentRoomId = null, targetUser = null, currentUser = null, isAdmin = false, canPin = false;
let _phoneAuthModalOpen = false;
let currentUsername = '';
let replyToId = null, mediaRecorder, audioChunks=[], isRecording=false, recInterval, isLocked=false, startY=0;
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

function showMySecurityCode() {
    document.getElementById('newSecurityCodeDisplay').textContent = '<?php echo $secCode; ?>';
    document.getElementById('resetSecWarning').style.display = 'none';
    openModal('securityCodeModal');
    closeMenu();
}

function resetSecurityCode() {
    const warn = document.getElementById('resetSecWarning');
    if (warn.style.display === 'none') {
        warn.style.display = 'block';
        document.getElementById('resetSecCodeBtn').textContent = '⚠️ تایید ریست';
        return;
    }
    const btn = document.getElementById('resetSecCodeBtn');
    btn.disabled = true;
    btn.textContent = '...';
    apiCall('chat.php', 'reset_security_code', {}, (d) => {
        btn.disabled = false;
        if (d.status === 'success') {
            document.getElementById('newSecurityCodeDisplay').textContent = d.new_code;
            warn.style.display = 'none';
            btn.textContent = '🔄 ریست کد';
            showToast('کد امنیتی جدید ساخته شد. حتماً آن را ذخیره کنید!');
        } else {
            showToast(d.message || 'خطا در ریست کد');
            btn.textContent = '🔄 ریست کد';
        }
    });
}

function toggleMenu() {
    const sidebar = document.getElementById('sidebar');
    if (window.innerWidth >= 769) { 
        sidebar.classList.toggle('closed'); 
    } else { 
        sidebar.classList.toggle('open'); 
        const bd = document.querySelector('.backdrop'); 
        if(sidebar.classList.contains('open')) bd.classList.add('show'); 
        else bd.classList.remove('show'); 
    }
}

function closeMenu() { 
    if (window.innerWidth < 769) { 
        document.getElementById('sidebar').classList.remove('open'); 
        document.querySelector('.backdrop').classList.remove('show'); 
    } 
}

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

    try {
        fetch('requests.php?logout=1&nonce=' + logoutNonce).catch(() => {});
    } catch(e) {}

    const modal = document.getElementById('sessionBurnedModal');
    if (!modal) { window.location.href = 'index.php'; return; }
    modal.style.display = 'flex';

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

function isProxyConfig(t) {
    if (!t) return false;
    const s = t.trim();
    return /^(vless|vmess|trojan|ss|ssr|socks5?|http|hysteria2?|tuic|warp|wireguard|anytls):\/\//i.test(s) ||
           /^(tg:\/\/proxy|https?:\/\/t\.me\/proxy)/i.test(s) ||
           /^mtproto:/i.test(s) ||
           /^(nekoray|nekobox|sing-box|xray|netmod|outline|ssconf):\/\//i.test(s) ||
           /^\[Interface\]/i.test(s) ||
           /^\{.*["']outbounds["']/i.test(s) ||
           /^\{.*["']log["'].*["']dns["']/i.test(s);
}

function getProxyType(line) {
    const s = line.trim();
    if (/^\[Interface\]/i.test(s)) return 'WireGuard';
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
    const lines = text.split('\n');
    const proxyLines = lines.filter(l => isProxyConfig(l.trim()));
    if (proxyLines.length === 0) return null;
    return proxyLines;
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

function formatTextForDisplay(t, isEmojiMsg) {
    if (!t) return '';
    if (isEmojiMsg) return t;
    t = stripXSS(t);
    const urlRegex = /https?:\/\/[^\s<>"'،؛]+/gi;
    let result = t.replace(urlRegex, function(url) {
        url = url.replace(/[.,!?;:،؛)]+$/, '');
        var safeUrl = sanitizeUrl(url);
        if (safeUrl === '#') return escapeHTMLDisplay(url);
        return `<a href="${safeUrl}" target="_blank" rel="noopener noreferrer" style="color:#818cf8;text-decoration:underline;word-break:break-all;" onclick="event.stopPropagation()">${escapeHTMLDisplay(url)}</a>`;
    }).replace(/\n/g, '<br>');
    return result;
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
    // sanitize XSS before processing
    t = stripXSS(t);
    const urlRegex = /https?:\/\/[^\s<>"'،؛]+/gi;
    let result = t.replace(urlRegex, function(url) {
        url = url.replace(/[.,!?;:،؛)]+$/, '');
        var safeUrl = sanitizeUrl(url);
        if (safeUrl === '#') return escapeHTMLDisplay(url);
        return `<a href="${safeUrl}" target="_blank" rel="noopener noreferrer" style="color:#818cf8;text-decoration:underline;word-break:break-all;" onclick="event.stopPropagation()">${escapeHTMLDisplay(url)}</a>`;
    }).replace(/\n/g, '<br>');
    
    const hasProxy = isProxyConfig(t.trim()) ? detectAndSaveProxies(t) || [t.trim()] : detectAndSaveProxies(t);
    if (hasProxy && hasProxy.length > 0) {
        const safeDataText = t.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        const types = [...new Set(hasProxy.map(l => getProxyType(l)))].slice(0,3).join('، ');
        result += `<div class="proxy-box"><span class="proxy-box-icon">🔒</span><div class="proxy-box-info"><div class="proxy-box-label">کانفیگ‌های پروکسی</div><div class="proxy-box-count">${hasProxy.length} کانفیگ · ${types}</div></div><button class="proxy-box-btn" onclick="event.stopPropagation();saveProxiesAsTxt(this.getAttribute('data-text'))" data-text="${safeDataText}">⬇ دریافت</button></div>`;
    }
    return result;
}

function escapeHTMLDisplay(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function renderReactions(m) {
    if (!m.id) return '';
    
    const reactions = m.reactions || {};
    const myReaction = m.my_reaction || null;
    
    let reactionsHtml = '';
    const reactionEmojis = ['❤️', '👍', '😂', '😮', '😢'];
    
    if (Object.keys(reactions).length > 0) {
        reactionsHtml = '<div class="reaction-display">';
        for (const [emoji, data] of Object.entries(reactions)) {
            const isMyReaction = myReaction === emoji;
            const users = data.users.join('، ');
            reactionsHtml += `<div class="reaction-item ${isMyReaction ? 'my-reaction' : ''}" data-emoji="${emoji}"
                onclick="event.stopPropagation(); reactToMessage(${m.id}, '${emoji}')"
                onmouseenter="showReactionTooltip(event, '${users.replace(/'/g, "\\'")}')"
                onmouseleave="hideReactionTooltip()">
                <span>${emoji}</span>
                <span class="reaction-count">${data.count}</span>
            </div>`;
        }
        reactionsHtml += '</div>';
    }
    
    const pickerHtml = `<div class="reaction-container"><div class="reaction-btn" data-msg-id="${m.id}" onclick="event.stopPropagation(); toggleReactionPicker(${m.id}, this)">😊</div></div>`;
    
    return reactionsHtml + pickerHtml;
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
    const isImage = ['jpg','jpeg','png','gif','webp'].includes(fileExt);
    const isVideo = ['mp4','webm','mov'].includes(fileExt);
    const isAudio = m.msg_type === 'voice' || fileExt === 'mp3';
    const isMediaType = isImage || isVideo || isAudio;

    let cnt = '';
    
    if(m.msg_type === 'text') {
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
    } else if(m.msg_type === 'voice') {
        const src = isPending ? URL.createObjectURL(m.blob) : `voice.php?token=${m.file_token}`;
        cnt = `<audio controls preload="none" src="${src}"></audio>`;
    } else if(isImage) {
        const src = isPending ? '' : `file.php?token=${m.file_token}`;
        cnt = `<div class="media-container" onclick="event.stopPropagation();openLightbox('${src}','img')"><img src="${src}" class="chat-media" style="cursor:zoom-in;" loading="lazy" onload="_onChatImgLoad(this)"></div>`;
        if(m.message) cnt += `<div class="text-glass-box chat-text">${formatText(m.message)}</div>`;
    } else if(isVideo) {
        const src = isPending ? '' : `file.php?token=${m.file_token}`;
        const cacheKey = m.file_token || ('id'+m.id);
        const canvasId = 'vthumb-' + (m.id || m.tempId || Math.random().toString(36).substr(2,6));
        if(videoThumbCache[cacheKey]) {
            cnt = `<div class="media-container video-wrap" onclick="event.stopPropagation();openLightbox('${src}','video')">
                <img src="${videoThumbCache[cacheKey]}" class="video-thumb">
                <div class="video-play-overlay"><div class="video-play-icon">▶</div></div>
            </div>`;
        } else {
            cnt = `<div class="media-container video-wrap" onclick="event.stopPropagation();openLightbox('${src}','video')">
                <canvas id="${canvasId}" class="video-thumb" style="display:none;"></canvas>
                <div id="${canvasId}-ph" style="width:100%;height:160px;background:#0f172a;border-radius:12px;display:flex;align-items:center;justify-content:center;"><span style="opacity:0.3;font-size:40px;">🎬</span></div>
                <div class="video-play-overlay"><div class="video-play-icon">▶</div></div>
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
        const shareBtn = (!isPending && m.file_token) ? `<button class="file-share-btn" onclick="event.stopPropagation();copyFileLink('${m.file_token}')" title="کپی لینک دانلود">🔗</button>` : '';
        cnt = `<div class="file-box" style="flex-direction:column;align-items:flex-start;gap:8px;">
            <div style="display:flex;align-items:center;gap:10px;width:100%;">
                <span class="file-icon">🎵</span>
                <div class="file-info"><span class="file-name" style="color:#a5b4fc;">${songTitle}</span>${sizeTag}</div>
                ${shareBtn}
                ${!isPending ? `<a href="${dlLink}" class="download-btn" download onclick="event.stopPropagation()">⬇</a>` : ''}
            </div>
            ${src ? `<audio controls preload="none" src="${src}" style="width:100%;height:36px;border-radius:18px;" onclick="event.stopPropagation()"></audio>` : ''}
        </div>`;
        if(m.message) cnt += `<div class="text-glass-box chat-text">${formatText(m.message)}</div>`;
    } else {
        const dlLink = isPending ? '#' : `file.php?token=${m.file_token}&dl=1`;
        const ext = m.file_name?.split('.').pop()?.toLowerCase();
        const fileIcon = ['pdf'].includes(ext)?'📑':['zip','rar','7z'].includes(ext)?'🗜':['doc','docx'].includes(ext)?'📝':['xls','xlsx'].includes(ext)?'📊':['mp4','webm','mov'].includes(ext)?'🎬':['jpg','jpeg','png','gif','webp'].includes(ext)?'🖼':'📄';
        const sizeTag = m.file_size ? `<span class="file-size-tag">${formatFileSize(m.file_size)}</span>` : '';
        const shareBtn = (!isPending && m.file_token) ? `<button class="file-share-btn" onclick="event.stopPropagation();copyFileLink('${m.file_token}')" title="کپی لینک دانلود">🔗</button>` : '';
        cnt = `<div class="file-box">
                <div class="file-icon">${fileIcon}</div>
                <div class="file-info"><span class="file-name">${m.file_name}</span>${sizeTag}</div>
                ${shareBtn}
                ${!isPending ? `<a href="${dlLink}" class="download-btn" download target="_blank" onclick="event.stopPropagation()">⬇</a>` : ''}
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
        msgCache[m.id] = { name: m.sender_name || safeSender, text: m.msg_type === 'text' ? (m.message || '') : '', type: m.msg_type || 'text', file_name: m.file_name || null, isMe: isMe };
    }

    const isSeen = !isPending && isMe && m.seen_by && m.seen_by > 0;
    const tickColor = isSeen ? '#60a5fa' : '#94a3b8';
    const tickHtml = isMe && !isPending ? `<span class="msg-tick" title="${isSeen ? 'دیده شد' : 'ارسال شد'}" style="display:inline-flex;align-items:center;margin-right:3px;opacity:0.85;"><svg width="18" height="10" viewBox="0 0 18 10" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 5L4 8L10 2" stroke="${tickColor}" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M6 5L9 8L15 2" stroke="${tickColor}" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></span>` : '';

    const isReplyToMe = !isMe && m.reply_to_id && (
        (m.reply_sender_id && currentUser && String(m.reply_sender_id) === String(currentUser)) ||
        (m.reply_sender && currentUsername && (m.reply_sender === currentUsername || m.reply_sender === (currentUsername + ' '))) ||
        (msgCache[m.reply_to_id] && msgCache[m.reply_to_id].isMe === true)
    );

    const replyToMeBadge = isReplyToMe ? `<div class="reply-to-me-badge"><svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/></svg> 💬 پاسخی به پیام شما</div>` : '';

    const msgBubble = `<div class="msg ${isMe?'me':'other'} ${pendingClass} ${errorClass}${pinnedMessages && pinnedMessages.find(p => p.id == (m.id||m.tempId)) ? ' pinned-highlight' : ''}" id="msg-${m.id || m.tempId}" data-msg-id="${msgDataId}" data-is-me="${isMe?'1':'0'}" data-sender="${safeSender}" onclick="onMsgClick(event,this)" oncontextmenu="onMsgCtx(event,this)">
        ${replyToMeBadge}
        ${rep} 
        <div class="sender" style="align-items:center;">${sSticker} ${displaySenderName} <span style="font-size:10px; margin-right:auto; background:rgba(0,0,0,0.2); padding:3px 8px; border-radius:12px; color:#e2e8f0; border: 1px solid rgba(255,255,255,0.05); box-shadow:inset 0 1px 2px rgba(0,0,0,0.2); letter-spacing:0.5px; display:inline-flex; align-items:center; gap:4px;">${m.created_at ? m.created_at.substr(11,5) : '...'}${tickHtml}</span></div> 
        ${cnt}
        ${!isPending && !isError ? renderReactions(m) : ''}
        ${retryBtn}
    </div>`;

    if(isPending) return msgBubble;

    const replyBtn = `<button class="msg-act-btn" title="پاسخ" onclick="event.stopPropagation();setReplyFromMsg(${msgDataId})">↩</button>`;
    const copyBtn = hasText ? `<button class="msg-act-btn" title="کپی" onclick="event.stopPropagation();copyMsgById(${msgDataId})">📋</button>` : '';
    const editBtn = (isMe && !isMediaType) ? `<button class="msg-act-btn" title="ویرایش" onclick="event.stopPropagation();editMsgById(${msgDataId})">✎</button>` : '';
    const delBtn = (isMe || isAdmin) ? `<button class="msg-act-btn del-btn" title="حذف" onclick="event.stopPropagation();delMsg(${msgDataId})">🗑</button>` : '';

    const actionsHtml = `<div class="msg-actions" id="acts-${msgDataId}">${replyBtn}${copyBtn}${editBtn}${delBtn}</div>`;
    const repliedClass = isReplyToMe ? ' replied-to-me' : '';

    if(isMe) {
        return `<div class="msg-row me${repliedClass}" id="row-${msgDataId}">${actionsHtml}${msgBubble}</div>`;
    } else {
        return `<div class="msg-row other${repliedClass}" id="row-${msgDataId}">${msgBubble}${actionsHtml}</div>`;
    }
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
    usernameEl.innerHTML = myUsername + ' <button onclick="copyProfileInfo()" title="کپی اطلاعات" style="background:rgba(99,102,241,0.15);border:1px solid rgba(99,102,241,0.3);color:#a5b4fc;border-radius:8px;padding:2px 8px;font-size:11px;cursor:pointer;margin-right:6px;">📋 کپی</button>';

    apiCall('chat.php', 'get_profile', {}, function(d) {
        if (d.status === 'success') {
            joinDate.textContent = 'عضویت: ' + (d.join_date || 'کاربر قدیمی');
            secCode.textContent = d.security_code ? d.security_code : 'تنظیم نشده';
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
    if (el.type === 'password') { el.type = 'text'; btn.textContent = '🙈'; }
    else { el.type = 'password'; btn.textContent = '👁'; }
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
    const html = `<div style="background:linear-gradient(145deg,#0f172a,#1e293b);border:1px solid rgba(99,102,241,0.3);border-radius:24px;width:100%;max-width:380px;padding:24px;box-shadow:0 30px 80px rgba(0,0,0,0.8);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;">
            <span style="font-size:16px;font-weight:bold;color:#e2e8f0;">📱 تغییر شماره موبایل</span>
            <button onclick="document.getElementById('changePhoneWrap').style.display='none'" style="width:30px;height:30px;border-radius:8px;background:rgba(255,255,255,0.06);border:1px solid rgba(255,255,255,0.1);color:#94a3b8;cursor:pointer;font-size:15px;">✕</button>
        </div>
        <p style="color:#94a3b8;font-size:13px;margin-bottom:14px;line-height:1.7;">برای تغییر شماره موبایل، کد امنیتی خود را وارد کنید.</p>
        <input type="text" id="changePhoneSecCode" maxlength="8" placeholder="کد امنیتی ۸ رقمی" dir="ltr" style="width:100%;padding:11px 14px;background:rgba(255,255,255,0.05);border:1px solid rgba(99,102,241,0.25);border-radius:12px;color:#e2e8f0;font-size:14px;margin-bottom:10px;box-sizing:border-box;font-family:monospace;letter-spacing:2px;" oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/g,'').substr(0,8)">
        <input type="tel" id="changePhoneNewNum" maxlength="11" placeholder="شماره جدید: 09xxxxxxxxx" dir="ltr" style="width:100%;padding:11px 14px;background:rgba(255,255,255,0.05);border:1px solid rgba(99,102,241,0.25);border-radius:12px;color:#e2e8f0;font-size:14px;margin-bottom:14px;box-sizing:border-box;" oninput="this.value=this.value.replace(/[^0-9]/g,'').substr(0,11)">
        <button onclick="submitChangePhone()" style="width:100%;padding:12px;background:linear-gradient(135deg,rgba(99,102,241,0.3),rgba(79,70,229,0.25));border:1px solid rgba(99,102,241,0.5);border-radius:12px;color:#a5b4fc;font-size:14px;cursor:pointer;font-weight:bold;">ذخیره شماره جدید</button>
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
    document.getElementById('phoneAuthTabPhone').style.background = isPhone ? 'rgba(99,102,241,0.35)' : 'transparent';
    document.getElementById('phoneAuthTabPhone').style.color = isPhone ? '#a5b4fc' : '#64748b';
    document.getElementById('phoneAuthTabPass').style.background = isPhone ? 'transparent' : 'rgba(99,102,241,0.35)';
    document.getElementById('phoneAuthTabPass').style.color = isPhone ? '#64748b' : '#a5b4fc';
    document.getElementById('phoneAuthAttempts').textContent = '';
    if (isPhone) {
        document.getElementById('phoneAuthInput').focus();
    } else {
        document.getElementById('phoneAuthPassInput').focus();
    }
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
    document.getElementById('phoneAuthNewUser').style.display = isNewUser && !hasPhone ? 'block' : 'none';
    const tabs = document.getElementById('phoneAuthTabs');
    if (isNewUser && !hasPhone) {
        document.getElementById('phoneAuthTitle').textContent = 'ثبت اطلاعات احراز هویت';
        document.getElementById('phoneAuthDesc').textContent = 'شماره موبایل یا رمز احراز هویت خود را وارد کنید. در ورودهای بعدی از همین اطلاعات استفاده می‌شود.';
        document.getElementById('phoneAuthIcon').textContent = '📱';
        document.getElementById('phoneAuthBtn').textContent = 'ثبت و ادامه';
        tabs.style.display = 'flex';
    } else {
        document.getElementById('phoneAuthTitle').textContent = 'احراز هویت';
        document.getElementById('phoneAuthDesc').textContent = 'شماره موبایل یا رمز احراز هویت خود را وارد کنید.';
        document.getElementById('phoneAuthIcon').textContent = '🔐';
        document.getElementById('phoneAuthBtn').textContent = 'تأیید و ورود';
        tabs.style.display = 'flex';
    }
    switchPhoneAuthTab('phone');
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
                document.getElementById('phoneAuthLockBox').style.display = 'block';
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
            document.getElementById('phoneAuthLockBox').style.display = 'block';
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
    document.getElementById('phoneOtpDisplay').textContent = otpCode;
    document.getElementById('phoneLoginDesc').textContent = 'کد تأیید زیر را وارد کنید (ارسال به: ' + phoneMasked + ')';
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
            msgCache[m.id] = { name: m.sender_name, text: m.msg_type === 'text' ? (m.message || '') : '', type: m.msg_type || 'text', file_name: m.file_name || null, isMe: (m.sender_id == currentUser) };
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

function checkAndShowSecurityCode() {
    const sc = localStorage.getItem('newSecurityCode');
    if(sc) {
        document.getElementById('newSecurityCodeDisplay').textContent = sc;
        openModal('securityCodeModal');
        localStorage.removeItem('newSecurityCode');
        setTimeout(() => { checkPhoneAuthRequired(); checkUpdateNotifications(); }, 1200);
    } else {
        localStorage.setItem('welcomeShown','true');
        setTimeout(() => { checkPhoneAuthRequired(); checkUpdateNotifications(); }, 800);
    }
}

function updateScrollBadge() {
    const badge = document.getElementById('scrollUnreadBadge');
    if (newMessagesSinceScroll > 0) {
        badge.style.display = 'flex';
        badge.textContent = newMessagesSinceScroll > 99 ? '+99' : newMessagesSinceScroll;
    } else {
        badge.style.display = 'none';
    }
}

let _fetchRetryCount = 0;
let _fetchInFlight = false;

function fetchMessages(callback) {
    if (_fetchInFlight) {
        if (typeof callback === 'function') {
            var _waitCb = setInterval(function() {
                if (!_fetchInFlight) { clearInterval(_waitCb); if (typeof callback === 'function') callback(); }
            }, 100);
        }
        return;
    }
    _fetchInFlight = true;
    const capturedToken = _fetchToken;

    var fd = new FormData();
    fd.append('action', 'fetch');
    fd.append('csrf_token', csrfToken);
    if (bwNonce) fd.append('bw_nonce', bwNonce);
    fd.append('room', currentRoom != null ? currentRoom : 'null');
    fd.append('target_user', targetUser != null ? targetUser : '');

    fetch('chat.php', { method: 'POST', body: fd })
    .then(function(r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
    })
    .then(function(text) {
        _fetchInFlight = false;
        if (capturedToken !== _fetchToken) { if (typeof callback === 'function') callback(); return; }
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

        if (d.status === 'banned') { handleBan(d.message, d.ban_type || 'temp'); if (typeof callback === 'function') callback(); return; }
        if (d.status === 'session_burned') { handleSessionBurned(); return; }
        if (d.status === 'error' && d.message === 'سایت موقتاً در دسترس نیست') { handleSessionBurned(); return; }
        if (d.status === 'auth_fail' || (d.status === 'error' && d.message === 'کاربر یافت نشد')) { window.location.href = 'index.php'; return; }
        if (d.status === 'access_denied') {
            try { localStorage.removeItem('bw_last_target'); localStorage.removeItem('bw_last_target_name'); localStorage.removeItem('bw_last_room'); localStorage.removeItem('bw_last_room_id'); } catch(e){}
            openModal('accessDeniedModal');
            if (typeof callback === 'function') callback();
            return;
        }

        _processFetchData(d, capturedToken, callback);
    })
    .catch(function(err) {
        _fetchInFlight = false;
        if (capturedToken !== _fetchToken) { if (typeof callback === 'function') callback(); return; }
        _fetchRetryCount++;
        var errMsg = _fetchRetryCount <= 2 ? 'خطای شبکه - تلاش مجدد...' : 'اتصال برقرار نشد - بررسی شبکه';
        showToast(errMsg);
        if (_fetchRetryCount <= 1) {
            setTimeout(function() { fetchMessages(callback); }, Math.min(20000 * _fetchRetryCount, 20000));
        } else {
            _fetchRetryCount = 0;
            if (typeof callback === 'function') callback();
        }
    });
}

function _catToggle(key) {
    const body = document.getElementById('cat-body-' + key);
    const arrow = document.getElementById('cat-arrow-' + key);
    if (!body) return;
    const isCollapsed = body.classList.toggle('collapsed');
    if (arrow) arrow.classList.toggle('collapsed', isCollapsed);
    try { localStorage.setItem('cat_' + key, isCollapsed ? '1' : '0'); } catch(e){}
}
function _catCollapsed(key) {
    try { return localStorage.getItem('cat_' + key) === '1'; } catch(e){ return false; }
}
function _makeCategory(key, title, bodyHtml) {
    const collapsed = _catCollapsed(key);
    return `<div class="sidebar-category" onclick="_catToggle('${key}')">
        <span class="sidebar-category-title">${title}</span>
        <span class="sidebar-category-arrow ${collapsed ? 'collapsed' : ''}" id="cat-arrow-${key}">▼</span>
    </div>
    <div class="sidebar-category-body ${collapsed ? 'collapsed' : ''}" id="cat-body-${key}" style="max-height:${collapsed ? '0' : '2000px'}">
        ${bodyHtml}
    </div>`;
}

function _processFetchData(d, capturedToken, callback) {
        if(capturedToken !== _fetchToken) { if (typeof callback === 'function') callback(); return; }
        currentUser = d.current_user_id; isAdmin = d.is_admin; canPin = (d.can_pin === true || d.can_pin === 1);
        if(d.bw_nonce) bwNonce = d.bw_nonce;
        document.getElementById('adminPanelBtn').style.display = isAdmin ? 'flex' : 'none';
        
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

        if(!localStorage.getItem('welcomeShown') && !isAdmin) {
            openModal('welcomeModal');
        } else if (localStorage.getItem('newSecurityCode')) {
            checkAndShowSecurityCode();
        } else {
            setTimeout(() => { checkPhoneAuthRequired(); checkUpdateNotifications(); }, 1000);
        }

        if(d.notifications && d.notifications.length > 0) {
            d.notifications.forEach(n => {
                showNotification(n.sender_id, n.name, n.sticker, n.txt, n.msg_id, n.room_name);
                if (n.room_name && n.room_name !== currentRoom && targetUser == null) {
                    roomUnreadLocal[n.room_name] = (roomUnreadLocal[n.room_name] || 0) + 1;
                }
            });
        }

        const list = document.getElementById('sidebarList');
        const q = document.querySelector('.search-input').value.toLowerCase();

        let publicRoomId = null;
        if(d.rooms) { 
            allRoomsCache = d.rooms;
            d.rooms.forEach(r => { if(r.name === 'گفتگوی عمومی' || r.name === 'public') publicRoomId = r.id; });
            if (currentRoom && !targetUser) _updateChannelInputVisibility(currentRoom);
        }
        if (currentRoom === 'گفتگوی عمومی' && !currentRoomId && publicRoomId) currentRoomId = publicRoomId;

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
            let pDispCount = pubCount > 99 ? '+99' : pubCount;
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
                if(rc > 0 && r.name !== currentRoom) unreadBadge = `<span class="unread-badge">${rc > 99 ? '+99' : rc}</span>`;
                let codeDisplay = '';
                if(r.invite_code && r.creator === currentUsername) {
                    codeDisplay = `<span class="room-code-badge" onclick="event.stopPropagation();copyText('${r.invite_code}')">${r.invite_code}</span>`;
                }
                let creatorHtml = r.creator ? `<span style="font-size:10px;opacity:0.6;margin-top:2px;display:block;">👤 ${r.creator}</span>` : '';
                let icon = '';
                if(rKind === 'channel' && r.type === 'public') icon = '📢';
                else if(rKind === 'channel' && r.type === 'private') icon = '🔐';
                else if(r.type === 'private') icon = '🔒';
                else icon = '💬';
                const isMyRoom = (r.creator === currentUsername);
                const deleteRoomBtn = isMyRoom ? `<button onclick="event.stopPropagation();deleteMyRoom(${r.id},'${r.name.replace(/'/g,"\\'")}')" style="width:22px;height:22px;border-radius:6px;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);color:#f87171;font-size:11px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:0.18s;" title="حذف اتاق">🗑</button>` : '';
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

        let html = _makeCategory('public', 'گفتگوی عمومی', cat1Html);
        if(cat2Html) html += _makeCategory('pub_channels', 'کانال‌های عمومی', cat2Html);
        if(cat3Html) html += _makeCategory('priv_channels', 'کانال‌های خصوصی', cat3Html);
        html += _makeCategory('priv_rooms', 'اتاق‌های خصوصی', cat4Html || '<div style="padding:6px 14px;font-size:12px;opacity:0.4;">اتاقی وجود ندارد</div>');


        html += '<div class="list-header">کاربران آنلاین</div>';
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
            if(!u.username.toLowerCase().includes(q)) return;
            
            const currentUnread = d.unread[u.id] || 0;
            const prevUnread = lastUnreadCounts[u.id] || 0;
            
            if (currentUnread > prevUnread && targetUser != u.id) {
                showNotification(u.id, u.username, u.sticker, "پیام جدید", null, null);
            }
            lastUnreadCounts[u.id] = currentUnread;

            const isActive = targetUser == u.id ? 'active' : '';
            let dispUnread = currentUnread > 99 ? '+99' : currentUnread;
            const unread = (currentUnread && targetUser != u.id) ? `<span class="unread-badge">${dispUnread}</span>` : '';
            
            let statusHtml = '';
            if(u.is_online == 1) {
                statusHtml = '<div style="font-size:10px;margin-top:2px;" class="status-online">آنلاین</div>';
            } else {
                let timeStr = u.last_activity ? u.last_activity.split(' ')[1].substr(0, 5) : '';
                statusHtml = `<div style="font-size:10px;margin-top:2px;" class="status-offline">آخرین بازدید: ${timeStr}</div>`;
            }
            
            let displayUsername = u.username;
            let checkBadge = '';
            
            if (u.username === adminUser) {
                displayUsername = '<?php echo $admin_display_name; ?>';
                checkBadge = '<svg style="width:14px;height:14px;vertical-align:middle;margin-right:4px;fill:#3b82f6;" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>';
            }

            html += `<div class="item ${isActive}" onclick="startPrivate(${u.id}, '${u.username}')" oncontextmenu="handleUserCtx(event, ${u.id}, '${u.username}')">
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

        updateHamBadge(d.unread || {});

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
        
        if (attachBtn) attachBtn.style.display = fLock ? 'none' : 'flex';
        if (micBtn) micBtn.style.display = vLock ? 'none' : 'flex';

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
                msgCache[m.id] = { name: m.sender_name, text: m.msg_type === 'text' ? (m.message || '') : '', type: m.msg_type || 'text', file_name: m.file_name || null, isMe: (m.sender_id == currentUser) };
                msgHtml += renderMessage(m);
            });

            const seenKey = d.messages.map(m => m.id + ':' + (m.seen_by||0)).join(',');
            const newKey = loadedHistoryHtml.length + '|' + lastMsgId + '|' + d.messages.length + '|' + seenKey;
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
                        _playingMedia.push({ src: el.src, currentTime: el.currentTime, msgId: el.closest('[data-msg-id]') ? el.closest('[data-msg-id]').dataset.msgId : null });
                        el.pause();
                    }
                });

                contentBox.innerHTML = loadedHistoryHtml + msgHtml + pendingHtml;

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
                msgCache[m.id] = { name: m.sender_name, text: m.msg_type === 'text' ? (m.message || '') : '', type: m.msg_type || 'text', file_name: m.file_name || null, isMe: (m.sender_id == currentUser) };
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
    else previewText = p.text ? p.text.replace(/<[^>]+>/g,'').substring(0,55) : '...';
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
        badge.textContent = total > 99 ? '+99' : total;
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

function startPrivate(u, n){ 
    _fetchToken++;
    const token = _fetchToken;
    targetUser=u; currentRoom=null;
    _localUnpinnedIds.clear();
    const inputBox = document.getElementById('inputBox');
    if (inputBox) inputBox.style.display = '';
    const typingEl = document.getElementById('typingStatus');
    if (typingEl) typingEl.style.display = 'none';
    document.getElementById('chatTitle').textContent=n; 
    document.getElementById('chatStatus').textContent='گفتگوی خصوصی'; 
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

function handleUserCtx(e, id, name){ e.preventDefault(); ctxId=id; ctxName=name; showCtx(e); }
function showCtx(e){ const m=document.getElementById('ctxMenu'); let x=e.pageX, y=e.pageY; if(x+160>window.innerWidth)x-=160; m.style.left=x+'px'; m.style.top=y+'px'; m.style.display='block'; document.getElementById('ctxBan').style.display=isAdmin?'flex':'none'; document.getElementById('ctxInvite').style.display=isAdmin?'flex':'none'; }
function hideCtx(){ document.getElementById('ctxMenu').style.display='none'; document.getElementById('msgCtxMenu').style.display='none'; const rc = document.getElementById('roomCtxMenu'); if(rc) rc.style.display='none'; }
function handleCtx(act){ hideCtx(); if(act==='private') startPrivate(ctxId, ctxName); if(act==='ban') showConfirm('مسدود شود؟', ()=>apiCall('admin.php','ban_user',{user_id:ctxId},()=>showToast('انجام شد'))); if(act==='invite') openInviteModal(); }

let _roomCtxRoomName = '';
let _roomCtxRoomId = null;
function roomCtxMenu(e, roomName, roomId) {
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
    if(isMe || isAdmin) btns += `<div class="action-item" onclick="delMsg(selectedMsgId);closeMsgMenu()" style="color:#f87171;border-color:rgba(239,68,68,0.3)"><span class="action-icon">🗑</span> حذف</div>`;

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

function openLightbox(src, type) {
    if(!src) return;
    const overlay = document.getElementById('lightboxOverlay');
    const img = document.getElementById('lightboxImg');
    const video = document.getElementById('lightboxVideo');
    const dlBtn = document.getElementById('lightboxDownloadBtn');
    
    if(type === 'video') {
        img.style.display = 'none';
        video.style.display = 'block';
        video.src = src;
        video.play().catch(()=>{});
    } else {
        video.style.display = 'none';
        video.pause();
        video.src = '';
        img.style.display = 'block';
        img.src = src;
        img._zoomed = false;
        img.style.transform = 'scale(1)';
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
    video.pause();
    video.src = '';
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
    const code = document.getElementById('newSecurityCodeDisplay').textContent;
    if(code) {
        copyMsg(code);
        showToast('کد با موفقیت کپی شد! در جای امن نگه‌دارید.');
    }
}

function _onChatImgLoad(img) {
    var box = document.getElementById('messagesBox');
    if(!box) return;
    if(userScrolledUp) return;
    var distFromBottom = box.scrollHeight - box.scrollTop - box.clientHeight;
    if(distFromBottom < 80) {
        box.scrollTop = box.scrollHeight;
    }
}

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

const MSG_QUEUE_DELAY = 5;
const MSG_QUEUE_BATCH = 10;
let _msgSendQueue = [];
let _msgSendInFlight = false;
let _msgSendCount = 0;
let _msgSendCountReset = null;

function _processMsgSendQueue() {
    if (_msgSendInFlight || _msgSendQueue.length === 0) return;
    const now = Date.now();
    if (!_msgSendCountReset) _msgSendCountReset = now + 40000;
    if (now > _msgSendCountReset) { _msgSendCount = 0; _msgSendCountReset = now + 40000; }
    if (_msgSendCount >= MSG_QUEUE_BATCH) {
        const wait = _msgSendCountReset - now;
        setTimeout(_processMsgSendQueue, wait + 70);
        return;
    }
    _msgSendInFlight = true;
    _msgSendCount++;
    const job = _msgSendQueue.shift();
    job(() => {
        _msgSendInFlight = false;
        if (_msgSendQueue.length > 0) setTimeout(_processMsgSendQueue, MSG_QUEUE_DELAY);
    });
}

function _queueSendFetch(fd, tempId, currentMsg, onDone) {
    _msgSendQueue.push((done) => {
        fetch('chat.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.status === 'success') {
                    const el = document.getElementById(`msg-${tempId}`);
                    const row = document.getElementById(`row-${tempId}`);
                    if (el) {
                        el.id = `msg-${d.new_msg_id}`;
                        el.dataset.msgId = d.new_msg_id;
                        el.classList.remove('pending', 'error');
                        msgCache[d.new_msg_id] = { name: 'شما', text: currentMsg || '', type: 'text' };
                    }
                    if (row) row.id = `row-${d.new_msg_id}`;
                    const acts = document.getElementById(`acts-${tempId}`);
                    if (acts) acts.id = `acts-${d.new_msg_id}`;
                    pendingMessages = pendingMessages.filter(p => p.tempId !== tempId);
                } else {
                    markAsError(tempId);
                    showToast(d.message);
                }
                done();
                if (onDone) onDone(d);
            })
            .catch(() => {
                markAsError(tempId);
                showToast('خطای شبکه');
                done();
            });
    });
    _processMsgSendQueue();
}

function sendMessage(blob, isV, retryId = null){
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
    const currentReplyId = replyToId;
    const currentReplyName = replyName;
    const currentReplyText = replyContent;

    let currentBlob = blob;
    let currentFile = file.files.length ? file.files[0] : null;
    let currentMsg = msgVal ? escapeHTML(msgVal) : ''; 

    if (retryId) {
        const pObj = pendingMessages.find(p => p.tempId === retryId);
        if(pObj) {
            currentBlob = pObj.blob;
            currentFile = pObj.file;
            currentMsg = pObj.msg;
        }
    } else {
        if(!blob && !file.files.length && !currentMsg) return;
    }

    const tempId = retryId || Date.now();
    const tempMsgObj = {
        id: null, tempId: tempId, sender_id: currentUser, sender_name: 'شما', sender_sticker: '👤', created_at: 'در حال ارسال...',
        message: currentMsg, msg_type: isV ? 'voice' : (currentFile ? 'file' : 'text'),
        file_name: currentFile ? currentFile.name : (isV ? 'voice.webm' : null),
        blob: currentBlob || currentFile,
        reply_to_id: currentReplyId, 
        reply_sender: currentReplyName || '...',
        reply_message: currentReplyId ? currentReplyText : null
    };

    if (!retryId) {
        pendingMessages.push({ tempId, blob: currentBlob, file: currentFile, msg: currentMsg, isV });
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
        txt.style.height = '46px';
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
    
    if(currentBlob) { 
        fd.append('file', currentBlob, 'voice.webm'); 
        fd.append('is_voice','true'); 
    } else {
        if(currentFile) fd.append('file', currentFile);
        if(currentMsg) fd.append('message', currentMsg);
    }
    
    _queueSendFetch(fd, tempId, currentMsg, null);
}

function showUploadProgress(name, pct) {
    const box = document.getElementById('uploadProgressBox');
    document.getElementById('uploadProgName').textContent = name;
    document.getElementById('uploadProgPct').textContent = Math.round(pct) + '%';
    document.getElementById('uploadProgBar').style.width = pct + '%';
    box.style.display = 'block';
}
function hideUploadProgress() {
    document.getElementById('uploadProgressBox').style.display = 'none';
}

function processUploadQueue() {
    if (isUploading || uploadQueue.length === 0) return;
    
    isUploading = true;
    const f = uploadQueue.shift();
    
    const tId = Date.now();
    const tObj = {
        id: null, tempId: tId, sender_id: currentUser, sender_name: 'شما', sender_sticker: '👤', created_at: 'در حال ارسال...',
        message: '', msg_type: 'file', file_name: f.name, blob: f,
        reply_to_id: null, reply_sender: '...', reply_message: null
    };
    
    pendingMessages.push({ tempId: tId, blob: null, file: f, msg: '', isV: false });
    document.getElementById('msgsContent').insertAdjacentHTML('beforeend', renderMessage(tObj, true));
    userScrolledUp = false;
    _programScrollUntil = Date.now() + 2000;
    var _ubox = document.getElementById('messagesBox');
    _ubox.style.scrollBehavior = 'auto';
    _ubox.scrollTop = _ubox.scrollHeight;
    requestAnimationFrame(function() { _ubox.scrollTop = _ubox.scrollHeight; _ubox.style.scrollBehavior = 'smooth'; });

    const fd = new FormData();
    fd.append('action', 'send');
    fd.append('csrf_token', csrfToken);
    if(bwNonce) fd.append('bw_nonce', bwNonce);
    
    if(targetUser) {
        fd.append('receiver_id', targetUser);
        fd.append('room', 'null');
    } else {
        fd.append('room', currentRoom || 'گفتگوی عمومی');
        if(currentRoomId) fd.append('room_id', currentRoomId);
    }
    fd.append('file', f);

    showUploadProgress(f.name, 0);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'chat.php', true);
    xhr.upload.onprogress = (e) => {
        if(e.lengthComputable) showUploadProgress(f.name, (e.loaded / e.total) * 100);
    };
    xhr.onload = () => {
        hideUploadProgress();
        try {
            const d = JSON.parse(xhr.responseText);
            const el = document.getElementById(`msg-${tId}`);
            if (d.status === 'success') {
                if(el) {
                    el.id = `msg-${d.new_msg_id}`;
                    el.classList.remove('pending', 'error');
                    el.dataset.msgId = d.new_msg_id;
                    msgCache[d.new_msg_id] = { name: 'شما', text: '', type: 'file', file_name: f.name };
                }
                const row = document.getElementById(`row-${tId}`);
                if(row) row.id = `row-${d.new_msg_id}`;
                const acts = document.getElementById(`acts-${tId}`);
                if(acts) acts.id = `acts-${d.new_msg_id}`;
                pendingMessages = pendingMessages.filter(p => p.tempId !== tId);
            } else {
                markAsError(tId);
                showToast(d.message);
            }
        } catch(e) { markAsError(tId); }
        isUploading = false;
        setTimeout(processUploadQueue, 300);
    };
    xhr.onerror = () => { hideUploadProgress(); markAsError(tId); isUploading = false; setTimeout(processUploadQueue, 300); };
    xhr.send(fd);
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

document.getElementById('msgInput').addEventListener('input', function() {
    this.style.height = '46px';
    const sh = this.scrollHeight;
    this.style.height = Math.min(sh, 160) + 'px';
});

document.getElementById('msgInput').addEventListener('keydown', function(e) {
    if(e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
    
    clearTimeout(typingTimeout);
    // ارسال وضعیت تایپ دقیق - فقط به طرف مقابل
    if (targetUser) {
        apiCall('chat.php', 'update_typing', { room: 'null', target_user: parseInt(targetUser) });
    } else if (currentRoom) {
        apiCall('chat.php', 'update_typing', { room: currentRoom, target_user: 0 });
    }
    
    typingTimeout = setTimeout(function() {
        // clear typing after 2.5 seconds of inactivity
        if (targetUser) {
            apiCall('chat.php', 'update_typing', { room: 'null', target_user: parseInt(targetUser), clear: 1 });
        }
    }, 2500);
});

const _emojiList = [
  '😀','😂','🤣','😍','😘','😊','😎','🥰','😅','😭','😢','😤','😡','🤔','😏','🙄','😴','😷','🤮','😆',
  '🥳','🤩','😬','😳','🤯','😱','🫡','👍','👎','✌️','🤙','👋','🙏','💪','🤝','👏','🫶','❤️','🧡','💛',
  '💚','💙','💜','🖤','🤍','💔','❤️‍🔥','💯','✅','❌','⭐','🔥','💥','🎉','🎊','🎁','🏆','🥇','🚀','💡',
  '💰','💎','🍕','🍔','☕','🍺','🎮','📱','💻','⚽','🏀','👑','🌹','🌊','🌙','☀️','⚡','🌈','👀','🫠',
  '😇','🤗','🤫','🤭','😌','😔','😪','🥹','😻','🙈','🙉','🙊','🐶','🐱','🐸','🦊','🐼','🦁','🐯','🐷',
];

let _emojiPickerInited = false;

function toggleEmojiPicker() {
    const picker = document.getElementById('emojiPicker');
    const btn = document.getElementById('emojiBtn');
    if (!_emojiPickerInited) {
        _emojiPickerInited = true;
        picker.innerHTML = _emojiList.map(e =>
            `<button class="emoji-pick-btn" onclick="insertEmoji('${e}')">${e}</button>`
        ).join('');
    }
    const isOpen = picker.style.display === 'flex';
    if (isOpen) {
        picker.style.display = 'none';
        if (btn) btn.style.background = '';
    } else {
        picker.style.display = 'flex';
        if (btn) btn.style.background = 'rgba(99,102,241,0.2)';
        picker.scrollTop = 0;
        setTimeout(() => {
            function outsideClose(ev) {
                if (!picker.contains(ev.target) && !btn.contains(ev.target)) {
                    picker.style.display = 'none';
                    if (btn) btn.style.background = '';
                    document.removeEventListener('click', outsideClose, true);
                }
            }
            document.addEventListener('click', outsideClose, true);
        }, 10);
    }
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
    document.getElementById('replyBar').style.display='flex'; 
    document.getElementById('replyText').textContent = replyName + ': ' + (realText ? realText.substr(0,40) : '[فایل]'); 
    document.getElementById('msgInput').focus({preventScroll: true}); 
    requestAnimationFrame(function() {
        var delta = box.scrollHeight - savedScrollHeight;
        if (delta > 0) { box.scrollTop = savedScrollTop + delta; }
        requestAnimationFrame(function() { box.style.scrollBehavior = 'smooth'; });
    });
}

function cancelReply(){ replyToId=null; replyContent=null; replyName=null; document.getElementById('replyBar').style.display='none'; }
function cancelFile(){ document.getElementById('fileInput').value=''; document.getElementById('filePreview').style.display='none'; }
document.getElementById('fileInput').onchange = function(){ 
    if(this.files.length > 0){ 
        document.getElementById('filePreview').style.display='flex'; 
        if(this.files.length > 1) {
             document.getElementById('fileName').textContent = this.files.length + ' فایل انتخاب شد';
        } else {
             document.getElementById('fileName').textContent = this.files[0].name; 
        }
    } 
};

function delMsg(id){ 
    showConfirm('آیا این پیام حذف شود؟', () => {
        const el = document.getElementById('msg-' + id);
        if(el) {
            const row = el.closest('.msg-row') || el;
            row.remove();
        }
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = loadedHistoryHtml;
        const histEl = tempDiv.querySelector('#msg-' + id);
        if(histEl) {
            const histRow = histEl.closest('.msg-row') || histEl;
            histRow.remove();
            loadedHistoryHtml = tempDiv.innerHTML;
        }

        const contentBox = document.getElementById('msgsContent');
        if (!contentBox.querySelector('.msg') && !document.querySelector('.msg.pending')) {
            contentBox.innerHTML = '<div style="text-align:center;margin-top:50px;opacity:0.5;font-size:12px">پیامی نیست</div>';
        }
        apiCall('chat.php', 'delete_msg', {id: id}, fetchMessages);
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

function reactToMessage(msgId, reaction) {
    apiCall('chat.php', 'react', {
        msg_id: msgId,
        reaction: reaction
    }, data => {
        if (data.status === 'success') {
            const wasRemoved = data.removed || false;
            const msgEl = document.getElementById('msg-' + msgId);
            if (!msgEl) return;
            
            let reactionDisplay = msgEl.querySelector('.reaction-display');
            const reactionContainer = msgEl.querySelector('.reaction-container');
            
            if (wasRemoved) {
                if (reactionDisplay) {
                    const item = Array.from(reactionDisplay.querySelectorAll('.reaction-item')).find(el => 
                        el.querySelector('span:first-child').textContent === reaction
                    );
                    if (item) {
                        const countEl = item.querySelector('.reaction-count');
                        let count = parseInt(countEl.textContent) - 1;
                        if (count <= 0) {
                            item.remove();
                            if (reactionDisplay.children.length === 0) {
                                reactionDisplay.remove();
                            }
                        } else {
                            countEl.textContent = count;
                            item.classList.remove('my-reaction');
                        }
                    }
                }
            } else {
                if (!reactionDisplay) {
                    reactionDisplay = document.createElement('div');
                    reactionDisplay.className = 'reaction-display';
                    if (reactionContainer) {
                        msgEl.insertBefore(reactionDisplay, reactionContainer);
                    } else {
                        msgEl.appendChild(reactionDisplay);
                    }
                }
                
                const allMyReactions = reactionDisplay.querySelectorAll('.reaction-item.my-reaction');
                allMyReactions.forEach(el => {
                    const emoji = el.querySelector('span:first-child').textContent;
                    if (emoji !== reaction) {
                        const countEl = el.querySelector('.reaction-count');
                        let count = parseInt(countEl.textContent) - 1;
                        if (count <= 0) {
                            el.remove();
                        } else {
                            countEl.textContent = count;
                            el.classList.remove('my-reaction');
                        }
                    }
                });
                
                let item = Array.from(reactionDisplay.querySelectorAll('.reaction-item')).find(el => 
                    el.querySelector('span:first-child').textContent === reaction
                );
                
                if (item) {
                    const countEl = item.querySelector('.reaction-count');
                    if (!item.classList.contains('my-reaction')) {
                        let count = parseInt(countEl.textContent) + 1;
                        countEl.textContent = count;
                    }
                    item.classList.add('my-reaction');
                } else {
                    item = document.createElement('div');
                    item.className = 'reaction-item my-reaction';
                    item.onclick = (e) => { e.stopPropagation(); reactToMessage(msgId, reaction); };
                    item.onmouseenter = (e) => showReactionTooltip(e, 'شما');
                    item.onmouseleave = () => hideReactionTooltip();
                    item.innerHTML = `<span>${reaction}</span><span class="reaction-count">1</span>`;
                    reactionDisplay.appendChild(item);
                }
            }
        } else {
            showToast(data.message || 'خطا در ثبت ری‌اکشن');
        }
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

function askDeleteAll(){
    showConfirm('آیا از حذف تمام پیام‌ها مطمئن هستید؟', function(){
        setTimeout(function(){
            showConfirm('هشدار جدی: این عملیات غیرقابل بازگشت است. ادامه می‌دهید؟', function(){
                document.querySelectorAll('.msg-row.me').forEach(el => el.remove());
                document.querySelectorAll('.msg.me:not(.msg-row .msg)').forEach(el => el.remove());
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = loadedHistoryHtml;
                tempDiv.querySelectorAll('.msg-row.me').forEach(el => el.remove());
                tempDiv.querySelectorAll('.msg.me').forEach(el => el.remove());
                loadedHistoryHtml = tempDiv.innerHTML;
                
                const contentBox = document.getElementById('msgsContent');
                if (!contentBox.querySelector('.msg') && !document.querySelector('.msg.pending')) {
                    contentBox.innerHTML = '<div style="text-align:center;margin-top:50px;opacity:0.5;font-size:12px">پیامی نیست</div>';
                }

                apiCall('chat.php', 'delete_all_msgs', {confirm: true, room_id: currentRoomId}, (d) => {
                    if (d.status === 'success') {
                        showToast(d.message);
                        lastMsgId = 0;
                        oldestMsgId = null;
            
                        fetchMessages();
                    } else {
                        showToast(d.message || 'خطا در حذف پیام‌ها');
            
                        fetchMessages();
                    }
                });
            });
        }, 500);
    });
}

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
    switchCreateTab('room');
    setTimeout(() => document.getElementById('newRoomName').focus(), 100);
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
        audioChunks = [];
        mediaRecorder.ondataavailable = e => { if (e.data.size > 0) audioChunks.push(e.data); };
        
        isRecording=true; isLocked=false;
        document.getElementById('inputRow').style.display='none'; document.getElementById('voiceUI').style.display='flex';
        let sec=0; recInterval=setInterval(()=>{ sec++; document.getElementById('recTimer').textContent=new Date(sec*1000).toISOString().substr(14,5); },1000);
    }).catch(()=>showToast('دسترسی میکروفون لازم است'));
};
const checkSwipe = e => { if(!isRecording||isLocked)return; let y=e.type.includes('touch')?e.touches[0].clientY:e.clientY; if(startY-y>50){ isLocked=true; document.getElementById('sendVoiceBtn').style.display='block'; } };
const endRec = () => { if(!isRecording||isLocked)return; stopRecManual(); };

function stopRecManual() { 
    if(mediaRecorder && mediaRecorder.state !== 'inactive'){ 
        mediaRecorder.onstop = () => { 
            if(audioChunks.length > 0) {
                const blob = new Blob(audioChunks, { type: 'audio/webm' });
                sendMessage(blob, true); 
            }
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
    document.getElementById('voiceUI').style.display='none'; 
    document.getElementById('inputRow').style.display='flex'; 
    document.getElementById('sendVoiceBtn').style.display='none'; 
    document.getElementById('recTimer').textContent='00:00'; 
}

mb.addEventListener('mousedown', startRec); document.addEventListener('mouseup', endRec); document.addEventListener('mousemove', checkSwipe);
mb.addEventListener('touchstart', startRec); document.addEventListener('touchend', endRec); document.addEventListener('touchmove', checkSwipe);

if(document.getElementById('chatTitle')) {
    (function() {
        try {
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
            switchRoom('گفتگوی عمومی', 'گفتگوی عمومی', null);
        }
    })();
    setInterval(function() {
        if(!document.hidden && !_fetchInFlight && !_phoneAuthModalOpen) fetchMessages();
    }, 10000);
    setTimeout(checkNeedsPhone, 10000);
    setTimeout(checkUpdateNotifications, 10000);
}

function sendBeaconStatus(status) {
    const fd = new FormData();
    fd.append('action', status);
    fd.append('csrf_token', csrfToken);
    navigator.sendBeacon('chat.php', fd);
}

document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'hidden') {
        sendBeaconStatus('set_offline');
    } else {
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
    refreshReportCaptcha();
}

function refreshReportCaptcha() {
    const btn = document.querySelector('.refresh-btn');
    if (btn) {
        btn.classList.remove('rotating');
        void btn.offsetWidth;
        btn.classList.add('rotating');
        setTimeout(() => btn.classList.remove('rotating'), 700);
    }

    const fd = new FormData();
    fd.append('action', 'refresh_report_captcha');
    fd.append('csrf_token', csrfToken);
    fetch('requests.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
        if(d.status === 'success') {
            document.getElementById('reportCaptchaBox').textContent = `${d.n1} + ${d.n2}`;
        }
    });
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
    btn.textContent = 'در حال ارسال...';
    btn.disabled = true;
    btn.style.opacity = '0.7';

    apiCall('requests.php', 'report_user', {target_username: target, reason: reason, captcha: captcha}, (d) => {
        btn.textContent = originalText;
        btn.disabled = false;
        btn.style.opacity = '1';
        
        if(d.status === 'success') {
            showToast(d.message);
            closeModal('reportUserModal');
            document.getElementById('reportTargetUser').value = '';
            document.getElementById('reportReason').value = '';
            document.getElementById('reportCaptchaInput').value = '';
            document.getElementById('reportUserSuggestions').innerHTML = '';
            refreshReportCaptcha();
        } else {
            showToast(d.message);
            refreshReportCaptcha();
            document.getElementById('reportCaptchaInput').value = '';
        }
    });
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
// chat.rootfa.ir main site //
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
</body>
</html>