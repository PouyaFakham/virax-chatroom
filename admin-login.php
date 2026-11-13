<?php
require_once 'config.php';

// اگر کاربر عادی لاگین شده، دسترسی نداره
if (isset($_SESSION['user_id']) && $_SESSION['username'] !== $admin_username) {
    ?>
    <!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>دسترسی محدود</title>
        <?php veyrax_icon_links(); ?>
        <style>
            @font-face { font-family: 'AppFont'; src: url('fonts/<?php echo $font_text; ?>.ttf') format('truetype'); }
            * { font-family: 'AppFont', sans-serif; box-sizing: border-box; margin: 0; padding: 0; }
            body { margin:0; min-height:100vh; display:flex; justify-content:center; align-items:center; background: radial-gradient(900px 500px at 50% -10%, rgba(245,158,11,0.18), transparent 60%), linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color:#fff; padding:20px; }
            .error-box { text-align:center; padding:44px 34px; background: rgba(30, 41, 59, 0.92); border-radius:25px; border:1px solid rgba(245,158,11,0.45); box-shadow:0 20px 60px rgba(245,158,11,0.18); max-width:100%; width:450px; }
            .icon { width:64px; height:64px; margin:0 auto 18px; border-radius:18px; display:grid; place-items:center; background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.35); }
            .icon img { width:38px; height:38px; object-fit:contain; filter: drop-shadow(0 4px 8px rgba(0,0,0,0.45)); }
            h1 { margin:0 0 12px; color:#f59e0b; font-size: 24px; font-weight: bold; }
            p { color:#cbd5e1; margin-bottom:26px; line-height: 1.8; font-size: 14px; }
            .btn { background: linear-gradient(135deg, #334155, #1e293b); color:#fff; text-decoration:none; padding:13px 28px; border-radius:13px; display:inline-block; font-weight: bold; border: 1px solid rgba(255,255,255,0.12); }
            .btn:hover { background: linear-gradient(135deg, #475569, #334155); }
        </style>
    </head>
    <body>
        <div class="error-box">
            <div class="icon"><img src="assets/icons3d/warning.png" alt=""></div>
            <h1>دسترسی محدود</h1>
            <p>این بخش مخصوص مدیریت سیستم است.<br>فقط مدیر کل می‌تواند وارد شود.</p>
            <a href="index.php" class="btn">بازگشت به چت اصلی</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// اگر ادمین قبلا لاگین کرده، بفرست به پنل
if (isset($_SESSION['user_id']) && $_SESSION['username'] === $admin_username) {
    header('Location: admin.php');
    exit;
}

// پردازش لاگین
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !is_string($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $error_message = 'درخواست نامعتبر است';
    } else {
        // The captcha is checked before the password and is always consumed,
        // so a failed attempt can never be replayed and a correct password
        // cannot be brute-forced without solving a fresh challenge.
        $capState = $_SESSION['admin_captcha'] ?? null;
        unset($_SESSION['admin_captcha']);
        $capOk = false;
        if (is_array($capState) && isset($capState['hash'], $capState['exp']) && time() <= (int)$capState['exp']) {
            $capIn = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['captcha'] ?? '')));
            if ($capIn !== '') {
                $capOk = password_verify($capIn, (string)$capState['hash']);
            }
        }

        if (!$capOk) {
            $error_message = 'کد امنیتی اشتباه یا منقضی شده است. کد تازه را وارد کنید';
        } else {
            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $adminLoginIp = get_client_ip();
            $throttle = veyrax_throttle_state($pdo, $adminLoginIp, $username, 'admin_login');

            if ($throttle['blocked']) {
                $error_message = 'تلاش‌های ناموفق زیاد. ' . veyrax_human_wait($throttle['retry']) . ' دیگر تلاش کنید';
            } elseif (isset($_SESSION['admin_login_locked_until']) && time() < (int)$_SESSION['admin_login_locked_until']) {
                $rem = (int)$_SESSION['admin_login_locked_until'] - time();
                $error_message = "تلاش‌های ناموفق زیاد. $rem ثانیه دیگر تلاش کنید";
            } elseif (verify_admin_password($password, $admin_password) && hash_equals($admin_username, $username)) {
                veyrax_throttle_record($pdo, $adminLoginIp, $username, 'admin_login', true);
                $stmt = $pdo->prepare("SELECT id, sticker FROM users WHERE username = ?");
                $stmt->execute([$admin_username]);
                $admin = $stmt->fetch();

                if (!$admin) {
                    $hashedPass = password_hash($admin_password, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, sticker) VALUES (?, ?, '👑')");
                    $stmt->execute([$admin_username, $hashedPass]);
                    $admin_id = $pdo->lastInsertId();
                    $admin_sticker = '👑';
                } else {
                    $admin_id = $admin['id'];
                    $admin_sticker = $admin['sticker'] ?: '👑';
                }

                session_regenerate_id(true);
                $_SESSION['user_id'] = $admin_id;
                $_SESSION['username'] = $admin_username;
                $_SESSION['sticker'] = $admin_sticker;
                $_SESSION['is_admin'] = true;
                $_SESSION['admin_login_failures'] = 0;
                unset($_SESSION['admin_login_locked_until']);
                $_SESSION['logout_nonce'] = bin2hex(random_bytes(16));

                $stmt = $pdo->prepare("UPDATE users SET last_activity = NOW(), is_online = 1 WHERE id = ?");
                $stmt->execute([$admin_id]);

                header('Location: admin.php');
                exit;
            } else {
                veyrax_throttle_record($pdo, $adminLoginIp, $username, 'admin_login', false);
                $_SESSION['admin_login_failures'] = ($_SESSION['admin_login_failures'] ?? 0) + 1;
                if ($_SESSION['admin_login_failures'] >= 5) {
                    $_SESSION['admin_login_locked_until'] = time() + 300;
                    $_SESSION['admin_login_failures'] = 0;
                }
                $error_message = 'نام کاربری یا رمز عبور اشتباه است';
            }
        }
    }
}

$captchaSrc = 'admin-captcha.php?t=' . time();
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="theme-color" content="#0b1020">
    <meta name="robots" content="noindex, nofollow">
    <?php veyrax_icon_links(); ?>
    <title>ورود مدیر سیستم</title>
    <style>
        @font-face { font-family: 'AppFont'; src: url('fonts/<?php echo $font_text; ?>.ttf') format('truetype'); font-weight: normal; }
        @font-face { font-family: 'AppBold'; src: url('fonts/<?php echo $font_heading; ?>.ttf') format('truetype'); font-weight: bold; }

        * { font-family: 'AppFont', sans-serif; box-sizing: border-box; outline: none; -webkit-tap-highlight-color: transparent; margin: 0; padding: 0; }

        body {
            min-height: 100vh; min-height: 100dvh;
            display: flex; flex-direction: column; justify-content: center; align-items: center;
            padding: 22px 18px;
            background:
                radial-gradient(760px 420px at 50% -12%, rgba(99,102,241,0.28), transparent 62%),
                radial-gradient(600px 380px at 110% 110%, rgba(239,68,68,0.16), transparent 60%),
                linear-gradient(160deg, #0b1020 0%, #101a2e 55%, #070b14 100%);
            color: #fff; overflow-x: hidden; position: relative;
        }

        .login-container { width: 100%; max-width: 430px; position: relative; z-index: 2; }

        .login-card {
            position: relative; overflow: hidden;
            padding: 34px 26px 26px;
            border-radius: 26px;
            background: linear-gradient(168deg, rgba(30,41,59,0.96), rgba(12,18,32,0.98));
            border: 1px solid rgba(148,163,184,0.20);
            box-shadow: 0 34px 80px -28px rgba(0,0,0,0.95), inset 0 1px 0 rgba(255,255,255,0.09);
        }
        .login-card::before {
            content: ''; position: absolute; inset-inline: 0; top: 0; height: 3px;
            background: linear-gradient(90deg, transparent, #818cf8, #f87171, #818cf8, transparent);
            background-size: 220% 100%; animation: vxSheen 4s linear infinite;
        }
        @keyframes vxSheen { from { background-position: 0 0; } to { background-position: 220% 0; } }

        .admin-icon {
            width: 72px; height: 72px; margin: 0 auto 16px;
            border-radius: 22px; display: grid; place-items: center;
            background: linear-gradient(155deg, rgba(129,140,248,0.22), rgba(129,140,248,0.05));
            border: 1px solid rgba(129,140,248,0.34);
            box-shadow: 0 16px 34px -18px rgba(99,102,241,0.95), inset 0 1px 0 rgba(255,255,255,0.16);
        }
        .admin-icon img { width: 42px; height: 42px; object-fit: contain; filter: drop-shadow(0 5px 9px rgba(0,0,0,0.5)); }

        .brand-title { font-family: 'AppBold', 'AppFont', sans-serif; font-size: 23px; margin: 0 0 6px; color: #fff; text-align: center; }
        .brand-subtitle { font-size: 12.5px; color: #93a3bb; margin-bottom: 24px; text-align: center; line-height: 1.7; }

        .error-message {
            background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.36);
            color: #fca5a5; padding: 12px 14px; border-radius: 13px; margin-bottom: 18px;
            text-align: center; font-size: 12.5px; line-height: 1.8;
        }

        .field { position: relative; margin-bottom: 13px; }
        .field input {
            width: 100%; height: 52px;
            background: rgba(2,6,23,0.55);
            border: 1px solid rgba(148,163,184,0.24);
            color: #eef2ff; border-radius: 14px;
            padding: 0 48px 0 16px;
            font-size: 14.5px; transition: border-color .2s, box-shadow .2s, background .2s;
        }
        .field input::placeholder { color: #64748b; }
        .field input:focus {
            border-color: rgba(129,140,248,0.75);
            background: rgba(2,6,23,0.75);
            box-shadow: 0 0 0 4px rgba(99,102,241,0.14);
        }
        .field-icon {
            position: absolute; top: 50%; right: 14px; transform: translateY(-50%);
            width: 21px; height: 21px; object-fit: contain; opacity: .9; pointer-events: none;
            filter: drop-shadow(0 3px 5px rgba(0,0,0,0.5));
        }
        /* RTL: inline-start is the right edge, which is where the icons sit.
           Without this the eye button overlaps the placeholder text. */
        .field.is-pass input { padding-inline-start: 84px; }
        .eye-btn {
            position: absolute; top: 50%; right: 44px; transform: translateY(-50%);
            width: 32px; height: 32px; display: grid; place-items: center;
            border: 0; border-radius: 9px; background: rgba(255,255,255,0.06);
            cursor: pointer; transition: background .18s;
        }
        .eye-btn:hover { background: rgba(255,255,255,0.13); }
        .eye-btn .i3d { width: 19px; height: 19px; filter: drop-shadow(0 2px 3px rgba(0,0,0,0.45)); }

        .captcha-block {
            margin: 4px 0 6px; padding: 12px;
            border-radius: 15px;
            background: rgba(2,6,23,0.35);
            border: 1px solid rgba(148,163,184,0.16);
        }
        .captcha-label { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 9px; }
        .captcha-label span { font-size: 11.5px; color: #93a3bb; }
        .captcha-row { display: flex; align-items: center; gap: 10px; }
        .captcha-img {
            flex: 0 0 auto; width: 148px; height: 46px; border-radius: 11px;
            border: 1px solid rgba(129,140,248,0.28); background: #0b1220;
            object-fit: cover; display: block;
        }
        .captcha-refresh {
            flex: 0 0 40px; width: 40px; height: 46px; border-radius: 11px; cursor: pointer;
            border: 1px solid rgba(148,163,184,0.22); background: rgba(255,255,255,0.05);
            color: #a5b4fc; display: grid; place-items: center; transition: background .18s, border-color .18s;
        }
        .captcha-refresh:hover { background: rgba(129,140,248,0.16); border-color: rgba(129,140,248,0.5); }
        .captcha-refresh svg { width: 18px; height: 18px; }
        .captcha-input {
            flex: 1 1 auto; min-width: 0; height: 46px; border-radius: 11px;
            background: rgba(2,6,23,0.55); border: 1px solid rgba(148,163,184,0.24);
            color: #eef2ff; padding: 0 13px; font-size: 15px; letter-spacing: 3px;
            font-family: Consolas, monospace; text-align: center; text-transform: uppercase;
            transition: border-color .2s, box-shadow .2s;
        }
        .captcha-input::placeholder { letter-spacing: 0; font-family: 'AppFont'; font-size: 12px; color: #64748b; }
        .captcha-input:focus { border-color: rgba(129,140,248,0.75); box-shadow: 0 0 0 4px rgba(99,102,241,0.14); }

        .btn-login {
            width: 100%; height: 52px; margin-top: 8px;
            border: 0; border-radius: 14px; cursor: pointer;
            font-family: 'AppBold', 'AppFont', sans-serif; font-size: 15.5px; color: #fff;
            background: linear-gradient(135deg, #6366f1, #4338ca);
            box-shadow: 0 20px 38px -18px rgba(99,102,241,0.95), inset 0 1px 0 rgba(255,255,255,0.28);
            transition: transform .15s, box-shadow .2s, filter .2s;
            position: relative; overflow: hidden;
        }
        .btn-login::after {
            content: ''; position: absolute; top: -20%; bottom: -20%; width: 40%;
            background: linear-gradient(100deg, transparent, rgba(255,255,255,0.4), transparent);
            transform: translateX(-180%) skewX(-16deg);
            animation: vxSweep 4.4s ease-in-out infinite;
            pointer-events: none;
        }
        @keyframes vxSweep { 0%, 55% { transform: translateX(-180%) skewX(-16deg); } 85%, 100% { transform: translateX(320%) skewX(-16deg); } }
        .btn-login:hover { transform: translateY(-1px); filter: brightness(1.06); }
        .btn-login:active { transform: translateY(0) scale(.995); }
        .btn-login:disabled { filter: saturate(.6) brightness(.85); cursor: not-allowed; transform: none; }

        .security-badge {
            margin: 16px auto 0; width: fit-content;
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px; border-radius: 999px;
            background: rgba(16,185,129,0.09); border: 1px solid rgba(16,185,129,0.22);
            color: #6ee7b7; font-size: 11px;
        }
        .security-badge img { width: 14px; height: 14px; object-fit: contain; }

        .back-link { text-align: center; margin-top: 20px; }
        .back-link a { color: #8494b0; text-decoration: none; font-size: 13px; }
        .back-link a:hover { color: #cbd5e1; }

        @media (max-width: 420px) {
            .login-card { padding: 28px 18px 22px; border-radius: 22px; }
            .brand-title { font-size: 20px; }
            .captcha-img { width: 132px; }
        }
    </style>
</head>
<body>
<?php veyrax_eye_sprite(); ?>
    <div class="login-container">
        <div class="login-card">
            <div class="admin-icon"><img src="assets/icons3d/shield.png" alt=""></div>
            <h1 class="brand-title">پنل مدیریت</h1>
            <p class="brand-subtitle">ورود به بخش مدیریت سیستم</p>

            <?php if ($error_message): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

                <div class="field">
                    <img class="field-icon" src="assets/icons3d/user.png" alt="">
                    <input type="text" name="username" placeholder="نام کاربری مدیر" required autofocus
                           maxlength="64" spellcheck="false" autocapitalize="none">
                </div>

                <div class="field is-pass">
                    <img class="field-icon" src="assets/icons3d/lock.png" alt="">
                    <input type="password" name="password" id="adminPassword" placeholder="رمز عبور" required maxlength="128">
                    <button type="button" class="eye-btn" onclick="vxToggleAdminPass(this)" aria-label="نمایش رمز">
                        <svg class="i3d" viewBox="0 0 32 32" aria-hidden="true"><use href="#v3i-eye"/></svg>
                    </button>
                </div>

                <div class="captcha-block">
                    <div class="captcha-label"><span>کد امنیتی را وارد کنید</span></div>
                    <div class="captcha-row">
                        <img id="adminCaptchaImg" class="captcha-img" src="<?php echo htmlspecialchars($captchaSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="کد امنیتی" width="148" height="46">
                        <button type="button" class="captcha-refresh" onclick="vxRefreshAdminCaptcha(this)" aria-label="کد جدید" title="کد جدید">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 12a8.5 8.5 0 1 1-2.9-6.4"/><polyline points="20.5 3.5 20.5 9 15 9"/></svg>
                        </button>
                        <input class="captcha-input" type="text" name="captcha" placeholder="کد تصویر" required
                               maxlength="5" inputmode="text" spellcheck="false" autocapitalize="characters"
                               oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,5)">
                    </div>
                </div>

                <button type="submit" class="btn-login">ورود به پنل</button>

                <div style="text-align:center;">
                    <span class="security-badge"><img src="assets/icons3d/shield.png" alt="">ورود محافظت‌شده با کد امنیتی</span>
                </div>
            </form>

            <div class="back-link">
                <a href="index.php">← بازگشت به صفحه اصلی</a>
            </div>
        </div>
    </div>

    <script>
        function vxToggleAdminPass(btn) {
            var el = document.getElementById('adminPassword');
            if (!el) return;
            var show = el.type === 'password';
            el.type = show ? 'text' : 'password';
            var use = btn.querySelector('use');
            if (use) use.setAttribute('href', show ? '#v3i-eye-off' : '#v3i-eye');
            btn.setAttribute('aria-label', show ? 'پنهان کردن رمز' : 'نمایش رمز');
        }
        function vxRefreshAdminCaptcha(btn) {
            var img = document.getElementById('adminCaptchaImg');
            if (!img) return;
            img.src = 'admin-captcha.php?t=' + Date.now();
            var inp = document.querySelector('.captcha-input');
            if (inp) { inp.value = ''; inp.focus(); }
            if (btn) { btn.style.transform = 'rotate(180deg)'; setTimeout(function () { btn.style.transform = ''; }, 220); }
        }
    </script>
</body>
</html>
