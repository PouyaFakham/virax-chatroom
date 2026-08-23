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
        <link rel="icon" type="image/png" href="<?php echo $favicon_path; ?>">
        <style>
            @font-face { font-family: 'AppFont'; src: url('fonts/<?php echo $font_text; ?>.ttf') format('truetype'); }
            * { font-family: 'AppFont', sans-serif; box-sizing: border-box; margin: 0; padding: 0; }
            body { margin:0; height:100vh; display:flex; justify-content:center; align-items:center; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color:#fff; overflow: hidden; }
            .error-box { text-align:center; padding:50px 40px; background: rgba(30, 41, 59, 0.9); backdrop-filter: blur(20px); border-radius:25px; border:2px solid #f59e0b; box-shadow:0 20px 60px rgba(245,158,11,0.3); animation:fadeIn 0.6s ease; max-width:90%; width:450px; position: relative; overflow: hidden; }
            .error-box::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #f59e0b, #fbbf24, #f59e0b); background-size: 200% 100%; animation: gradientMove 3s linear infinite; }
            .icon { font-size:70px; margin-bottom:25px; display:block; animation: pulse 2s infinite; filter: drop-shadow(0 0 20px rgba(245,158,11,0.5)); }
            h1 { margin:0 0 15px; color:#f59e0b; font-size: 26px; font-weight: bold; letter-spacing: -0.5px; }
            p { color:#cbd5e1; margin-bottom:30px; line-height: 1.7; font-size: 15px; }
            .btn { background: linear-gradient(135deg, #334155, #1e293b); color:#fff; text-decoration:none; padding:14px 30px; border-radius:14px; transition:0.3s; display:inline-block; font-weight: bold; border: 1px solid rgba(255,255,255,0.1); box-shadow: 0 8px 20px rgba(0,0,0,0.3); }
            .btn:hover { background: linear-gradient(135deg, #475569, #334155); transform: translateY(-2px); box-shadow: 0 12px 30px rgba(0,0,0,0.4); }
            @keyframes fadeIn { from{opacity:0;transform:scale(0.95) translateY(20px)} to{opacity:1;transform:scale(1) translateY(0)} }
            @keyframes pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.05); } }
            @keyframes gradientMove { 0% { background-position: 0% 50%; } 100% { background-position: 100% 50%; } }
        </style>
    </head>
    <body>
        <div class="error-box">
            <span class="icon">⚠️</span>
            <h1>دسترسی محدود</h1>
            <p>این بخش مخصوص مدیریت سیستم است.<br>فقط مدیر کل می‌تواند وارد شود.</p>
            <a href="index.php" class="btn">🏠 بازگشت به چت اصلی</a>
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
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_message = 'درخواست نامعتبر است';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if ($username === $admin_username && $password === $admin_password) {
            // لاگین موفق ادمین
            $stmt = $pdo->prepare("SELECT id, sticker FROM users WHERE username = ?");
            $stmt->execute([$admin_username]);
            $admin = $stmt->fetch();
            
            if (!$admin) {
                // اگر یوزر ادمین توی دیتابیس نیست، بسازش
                $hashedPass = password_hash($admin_password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, sticker) VALUES (?, ?, '👑')");
                $stmt->execute([$admin_username, $hashedPass]);
                $admin_id = $pdo->lastInsertId();
                $admin_sticker = '👑';
            } else {
                $admin_id = $admin['id'];
                $admin_sticker = $admin['sticker'] ?: '👑';
            }
            
            $_SESSION['user_id'] = $admin_id;
            $_SESSION['username'] = $admin_username;
            $_SESSION['sticker'] = $admin_sticker;
            $_SESSION['is_admin'] = true;
            
            // آپدیت آخرین فعالیت
            $stmt = $pdo->prepare("UPDATE users SET last_activity = NOW(), is_online = 1 WHERE id = ?");
            $stmt->execute([$admin_id]);
            
            header('Location: admin.php');
            exit;
        } else {
            $error_message = 'نام کاربری یا رمز عبور اشتباه است';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token']; ?>">
    <meta name="theme-color" content="#0f172a">
    <link rel="icon" type="image/png" href="<?php echo $favicon_path; ?>">
    <title>ورود مدیر سیستم</title>
    <style>
        @font-face { font-family: 'AppFont'; src: url('fonts/<?php echo $font_text; ?>.ttf') format('truetype'); font-weight: normal; }
        @font-face { font-family: 'AppBold'; src: url('fonts/<?php echo $font_heading; ?>.ttf') format('truetype'); font-weight: bold; }
        
        * { 
            font-family: 'AppFont', sans-serif; 
            box-sizing: border-box; 
            outline: none; 
            -webkit-tap-highlight-color: transparent;
            margin: 0;
            padding: 0;
        }
        
        body { 
            margin: 0; 
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); 
            color: #fff; 
            display: flex; 
            flex-direction: column; 
            justify-content: center; 
            align-items: center; 
            min-height: 100vh; 
            min-height: 100dvh; 
            overflow: hidden; 
            position: relative;
            -webkit-touch-callout: none;
        }
        
        /* Background effects */
        body::before {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(239, 68, 68, 0.15), transparent);
            border-radius: 50%;
            top: -200px;
            right: -200px;
            animation: float 8s ease-in-out infinite;
        }
        
        body::after {
            content: '';
            position: absolute;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.1), transparent);
            border-radius: 50%;
            bottom: -150px;
            left: -150px;
            animation: float 10s ease-in-out infinite reverse;
        }
        
        .login-container {
            width: 90%;
            max-width: 420px;
            position: relative;
            z-index: 10;
            animation: fadeInUp 0.6s cubic-bezier(0.22, 1, 0.36, 1);
        }
        
        .login-card { 
            background: rgba(30, 41, 59, 0.9); 
            backdrop-filter: blur(20px); 
            -webkit-backdrop-filter: blur(20px);
            padding: 45px 35px; 
            border-radius: 28px; 
            border: 1px solid rgba(239, 68, 68, 0.2); 
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.6), 0 0 80px rgba(239, 68, 68, 0.1);
            position: relative;
            overflow: hidden;
        }
        
        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #ef4444, #f87171, #ef4444);
            background-size: 200% 100%;
            animation: gradientMove 3s linear infinite;
        }
        
        .admin-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(239, 68, 68, 0.05));
            border-radius: 50%;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0 auto 25px;
            border: 2px solid rgba(239, 68, 68, 0.3);
            font-size: 36px;
            animation: pulse 3s infinite;
            filter: drop-shadow(0 0 20px rgba(239, 68, 68, 0.4));
        }
        
        .brand-title { 
            font-family: 'AppBold'; 
            font-size: 26px; 
            margin: 0 0 8px; 
            color: #fff; 
            text-align: center;
            letter-spacing: -0.5px;
        }
        
        .brand-subtitle { 
            font-size: 14px; 
            color: #94a3b8; 
            margin-bottom: 35px; 
            text-align: center;
        }
        
        .input-group {
            position: relative;
            margin-bottom: 18px;
        }
        
        .input-icon {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 20px;
            opacity: 0.6;
            pointer-events: none;
        }
        
        input { 
            width: 100%; 
            height: 52px; 
            background: rgba(15, 23, 42, 0.8); 
            border: 1px solid #334155; 
            color: #fff; 
            border-radius: 14px; 
            padding: 0 50px 0 20px;
            font-size: 15px; 
            transition: all 0.3s;
        }
        
        input:focus { 
            border-color: #ef4444; 
            background: rgba(15, 23, 42, 0.95);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }
        
        input::placeholder { 
            color: #64748b; 
        }
        
        .btn-login { 
            width: 100%; 
            height: 52px; 
            background: linear-gradient(135deg, #ef4444, #dc2626); 
            color: #fff; 
            border: none; 
            border-radius: 14px; 
            font-size: 16px; 
            font-weight: bold; 
            cursor: pointer; 
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            margin-top: 10px;
            box-shadow: 0 10px 25px rgba(239, 68, 68, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }
        
        .btn-login:hover::before {
            left: 100%;
        }
        
        .btn-login:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 15px 35px rgba(239, 68, 68, 0.4);
            background: linear-gradient(135deg, #dc2626, #b91c1c);
        }
        
        .btn-login:active { 
            transform: translateY(0); 
        }
        
        .error-message {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 20px;
            text-align: center;
            font-size: 14px;
            animation: shake 0.5s;
        }
        
        .back-link {
            text-align: center;
            margin-top: 25px;
        }
        
        .back-link a {
            color: #94a3b8;
            text-decoration: none;
            font-size: 14px;
            transition: color 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .back-link a:hover {
            color: #cbd5e1;
        }
        
        .security-badge {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: #6ee7b7;
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 20px;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes pulse {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4);
            }
            50% {
                box-shadow: 0 0 0 15px rgba(239, 68, 68, 0);
            }
        }
        
        @keyframes gradientMove {
            0% { background-position: 0% 50%; }
            100% { background-position: 100% 50%; }
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-20px); }
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        
        @media (max-width: 480px) {
            .login-card {
                padding: 35px 25px;
            }
            .brand-title {
                font-size: 23px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="admin-icon">🛡️</div>
            <h1 class="brand-title">پنل مدیریت</h1>
            <p class="brand-subtitle">ورود به بخش مدیریت سیستم</p>
            
            <?php if ($error_message): ?>
                <div class="error-message">⚠️ <?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            
            <form method="POST" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="input-group">
                    <span class="input-icon">👤</span>
                    <input type="text" name="username" placeholder="نام کاربری مدیر" required autofocus>
                </div>
                
                <div class="input-group">
                    <span class="input-icon">🔒</span>
                    <input type="password" name="password" placeholder="رمز عبور" required>
                </div>
                
                <button type="submit" class="btn-login">🚀 ورود به پنل</button>
                
                <div style="text-align: center;">
                    <span class="security-badge">🔐 احراز هویت امن</span>
                </div>
            </form>
            
            <div class="back-link">
                <a href="index.php">← بازگشت به صفحه اصلی</a>
            </div>
        </div>
    </div>
</body>
</html>
