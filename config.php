<?php
$db_host = 'localhost';
$db_name = 'اسم دیتابیس';
$db_user = 'یوزرنیم متصل به دیتابیس';
$db_pass = 'پسورد یوزرنیم مربوط به دیتابیس';

$admin_username = 'نام کاربری ادمین';
$admin_display_name = 'نام نمایشی ادمین';
$admin_password = 'پسورد ادمین'; 
$admin_pinned_username = $admin_username;

$font_text = 'Kalameh1';
$font_heading = 'Kalameh';
$favicon_path = 'fav.png';

$social_github_link = 'https://github.com/PouyaFakham';
$social_github_icon = 'icons/github.png';
$social_website_link = 'https://blackwacker.com';
$social_website_icon = 'icons/website.png';
$social_telegram_link = 'https://t.me/PooyaFakham';
$social_telegram_icon = 'icons/telegram.png';

$source_download_link = 'https://chat.rootfa.ir/Source-ChatFa.zip';
$donate_link = 'https://rootfa.ir/donate/';

$message_lifetime = 72 * 3600;
$file_size_limit = 50 * 1024 * 1024;
$spam_limit_count = 10;
$spam_limit_time = 10;
$ban_duration = 120;

$enable_intro_popup = true;

$stickers = ['😎', '👻', '🤖', '🐱', '👤', '🦊', '🦁', '🐸', '🐼', '🐲', '⭐', '🔥', '💀', '👽', '🤡', '👺', '👹', '💖', '🚀', '✅', '❌', '⚠'];

$allowed_extensions = ['jpg','jpeg','png','gif','webp','mp4','mov','avi','mkv','mp3','ogg','wav','pdf','zip','rar','7z','txt','doc','docx','xls','xlsx','ppt','pptx','apk','exe','dmg','iso','svg','ico','bmp','tiff','csv','npv'];

date_default_timezone_set('Asia/Tehran');

if (file_exists('jdf.php')) {
    require_once 'jdf.php';
}

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');
ini_set('memory_limit', '5048M');

ini_set('session.use_only_cookies', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 1 : 0);
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', 259200);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 259200,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    $sessionStarted = false;
    $maxRetries = 3;

    for ($i = 0; $i < $maxRetries && !$sessionStarted; $i++) {
        try {
            if ($i > 0) {
                if (isset($_COOKIE[session_name()])) {
                    setcookie(session_name(), '', time() - 3600, '/');
                    unset($_COOKIE[session_name()]);
                }
                session_id(bin2hex(random_bytes(16)));
            }
            if (@session_start()) {
                $sessionStarted = true;
            }
        } catch (Exception $e) {
            error_log("Session error attempt $i: " . $e->getMessage());
            if ($i < $maxRetries - 1) usleep(50000);
        }
    }

    if (!$sessionStarted) {
        if (isset($_COOKIE[session_name()])) setcookie(session_name(), '', time() - 3600, '/');
        session_id(bin2hex(random_bytes(16)));
        @session_start();
    }
}

if (!defined('ENCRYPTION_KEY')) define('ENCRYPTION_KEY', 'کلید رمزنگاری (خیلی مهم)');
if (!defined('IV_LENGTH')) define('IV_LENGTH', openssl_cipher_iv_length('aes-256-cbc'));

function encrypt_data($data) {
    $iv = openssl_random_pseudo_bytes(IV_LENGTH);
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', ENCRYPTION_KEY, 0, $iv);
    return base64_encode($iv . $encrypted);
}

function decrypt_data($data) {
    $data = base64_decode($data);
    if (strlen($data) < IV_LENGTH) return false;
    $iv = substr($data, 0, IV_LENGTH);
    $encrypted = substr($data, IV_LENGTH);
    return openssl_decrypt($encrypted, 'aes-256-cbc', ENCRYPTION_KEY, 0, $iv);
}

function get_client_ip() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) return $_SERVER['HTTP_CLIENT_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    return $_SERVER['REMOTE_ADDR'];
}

function xss_clean($data) {
    if (is_array($data)) {
        return array_map('xss_clean', $data);
    }
    $data = trim($data);
    $data = strip_tags($data);
    $data = htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $data;
}

function sanitize_message($text) {
    $text = trim($text);
    $text = strip_tags($text);
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return $text;
}

function is_admin_user($username, $pdo) {
    global $admin_username;
    if ($username === $admin_username) return true;
    $stmt = $pdo->prepare("SELECT id FROM admin_users WHERE username = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$username]);
    return (bool)$stmt->fetch();
}

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
    $pdo->exec("ALTER DATABASE `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 10);

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password_hash VARCHAR(255) DEFAULT NULL,
        sticker VARCHAR(10) DEFAULT '👤',
        last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_banned_until INT DEFAULT 0,
        muted_until INT DEFAULT 0,
        is_online TINYINT DEFAULT 0,
        notifications_enabled TINYINT DEFAULT 1,
        user_agent VARCHAR(512) DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        last_notif_id INT DEFAULT 0,
        typing_context VARCHAR(100) DEFAULT NULL,
        typing_time INT DEFAULT 0,
        device_token VARCHAR(64) DEFAULT NULL,
        security_code VARCHAR(20) DEFAULT NULL,
        phone_number VARCHAR(15) DEFAULT NULL,
        hide_last_seen TINYINT DEFAULT 0,
        INDEX(last_activity),
        INDEX(is_online),
        INDEX(username),
        INDEX(device_token)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS banned_ips (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ip_address VARCHAR(45) NOT NULL UNIQUE,
        banned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reason VARCHAR(255) DEFAULT 'Violation'
    )");

    $checkColsUsers = [
        'sticker'                => "VARCHAR(10) DEFAULT '👤'",
        'is_banned_until'        => "INT DEFAULT 0",
        'muted_until'            => "INT DEFAULT 0",
        'is_online'              => "TINYINT DEFAULT 0",
        'notifications_enabled'  => "TINYINT DEFAULT 1",
        'user_agent'             => "VARCHAR(512) DEFAULT NULL",
        'ip_address'             => "VARCHAR(45) DEFAULT NULL",
        'password_hash'          => "VARCHAR(255) DEFAULT NULL",
        'last_notif_id'          => "INT DEFAULT 0",
        'typing_context'         => "VARCHAR(100) DEFAULT NULL",
        'typing_time'            => "INT DEFAULT 0",
        'device_token'           => "VARCHAR(64) DEFAULT NULL",
        'security_code'          => "VARCHAR(20) DEFAULT NULL",
        'phone_number'           => "VARCHAR(15) DEFAULT NULL",
        'phone_auth_attempts'    => "TINYINT DEFAULT 0",
        'phone_auth_locked_until'=> "INT DEFAULT 0",
        'hide_last_seen'         => "TINYINT DEFAULT 0",
        'created_at'             => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
    ];
    foreach ($checkColsUsers as $col => $def) {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE '$col'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN $col $def");
        } elseif ($col === 'user_agent') {
            $pdo->exec("ALTER TABLE users MODIFY COLUMN user_agent VARCHAR(512) DEFAULT NULL");
        } elseif ($col === 'phone_number') {
            $pdo->exec("ALTER TABLE users MODIFY COLUMN phone_number VARCHAR(15) DEFAULT NULL");
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        display_name VARCHAR(100) DEFAULT NULL,
        created_by VARCHAR(50) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_login TIMESTAMP NULL DEFAULT NULL,
        is_active TINYINT DEFAULT 1,
        INDEX(username),
        INDEX(is_active)
    )");

    $client_ip = get_client_ip();
    $stmtBan = $pdo->prepare("SELECT id FROM banned_ips WHERE ip_address = ? LIMIT 1");
    $stmtBan->execute([$client_ip]);
    if ($stmtBan->fetch()) {
        ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>دسترسی مسدود شد</title>
    <style>
        @font-face { font-family: 'AppBold'; src: url('fonts/<?php echo $font_heading; ?>.ttf') format('truetype'); font-weight: bold; }
        * { box-sizing: border-box; font-family: 'AppBold', sans-serif; }
        body { margin: 0; background: #000; color: #fff; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .ban-container { width: 100%; max-width: 480px; }
        .ban-card { background: rgba(50, 0, 0, 0.8); backdrop-filter: blur(20px); padding: 50px 30px; border-radius: 35px; border: 1px solid rgba(239, 68, 68, 0.3); text-align: center; box-shadow: 0 30px 80px -10px rgba(0, 0, 0, 0.8); }
        .icon { font-size: 70px; margin-bottom: 25px; display: inline-block; }
        .title { font-size: 28px; font-weight: 900; margin-bottom: 15px; color: #ef4444; }
        .desc { font-size: 16px; color: #cbd5e1; line-height: 1.7; }
        .ban-badge { background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); border-radius: 20px; padding: 6px 16px; display: inline-block; margin: 10px 0; font-size: 13px; }
    </style>
</head>
<body>
    <div class="ban-container">
        <div class="ban-card">
            <div class="icon">🚫</div>
            <div class="title">دسترسی مسدود شد</div>
            <div class="ban-badge">IP: <?php echo htmlspecialchars($client_ip); ?></div>
            <div class="desc">دسترسی دستگاه شما به دلیل نقض قوانین سرور مسدود شده است.</div>
        </div>
    </div>
</body>
</html>
        <?php
        exit;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL UNIQUE,
        type ENUM('public', 'private') DEFAULT 'public',
        room_kind ENUM('room','channel') DEFAULT 'room',
        created_by INT,
        invite_code VARCHAR(20) DEFAULT NULL,
        category VARCHAR(50) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(name),
        INDEX(invite_code),
        INDEX(room_kind),
        INDEX(category)
    )");

    $stmtRoomCols = [
        'invite_code' => "VARCHAR(20) DEFAULT NULL",
        'room_kind'   => "ENUM('room','channel') DEFAULT 'room'",
        'category'    => "VARCHAR(50) DEFAULT NULL",
    ];
    foreach ($stmtRoomCols as $col => $def) {
        $chk = $pdo->query("SHOW COLUMNS FROM rooms LIKE '$col'");
        if ($chk->rowCount() == 0) $pdo->exec("ALTER TABLE rooms ADD COLUMN $col $def");
    }

    $pdo->exec("INSERT IGNORE INTO rooms (name, type, created_by) VALUES ('گفتگوی عمومی', 'public', 0)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS room_invites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        room_name VARCHAR(50) NOT NULL,
        invited_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(user_id),
        INDEX(room_name)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        receiver_id INT DEFAULT NULL,
        room_name VARCHAR(50) DEFAULT NULL,
        reply_to_id INT DEFAULT NULL,
        message LONGTEXT,
        file_path VARCHAR(255) DEFAULT NULL,
        file_name VARCHAR(255) DEFAULT NULL,
        file_token VARCHAR(64) DEFAULT NULL,
        msg_type ENUM('text', 'file', 'voice', 'image', 'video') DEFAULT 'text',
        is_edited TINYINT DEFAULT 0,
        is_read TINYINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(room_name),
        INDEX(sender_id),
        INDEX(receiver_id),
        INDEX(file_token),
        INDEX(created_at),
        INDEX(is_read)
    )");

    $checkColsMsgs = [
        'is_read' => "TINYINT DEFAULT 0",
        'msg_type' => "ENUM('text', 'file', 'voice', 'image', 'video') DEFAULT 'text'"
    ];
    foreach ($checkColsMsgs as $col => $def) {
        $stmt = $pdo->query("SHOW COLUMNS FROM messages LIKE '$col'");
        if ($stmt->rowCount() == 0) $pdo->exec("ALTER TABLE messages ADD COLUMN $col $def");
        else if ($col === 'msg_type') $pdo->exec("ALTER TABLE messages MODIFY COLUMN $col $def");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_token VARCHAR(128) NOT NULL UNIQUE,
        user_id INT NOT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(512) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX(user_id),
        INDEX(session_token),
        INDEX(last_activity)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(50) NOT NULL UNIQUE,
        setting_value VARCHAR(255) DEFAULT NULL
    )");

    $defaultSystemSettings = ['lock_upload' => '0', 'lock_voice' => '0'];
    foreach ($defaultSystemSettings as $k => $v) {
        try { $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]); } catch (Exception $e) {}
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reporter_id INT NOT NULL,
        reported_id INT NOT NULL,
        reason TEXT,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(created_at),
        INDEX(reporter_id),
        INDEX(reported_id)
    )");

    $stmtRCol = $pdo->query("SHOW COLUMNS FROM user_reports LIKE 'status'");
    if ($stmtRCol->rowCount() == 0) {
        $pdo->exec("ALTER TABLE user_reports ADD COLUMN status ENUM('pending','approved','rejected') DEFAULT 'pending'");
    }

    try {
        $pdo->exec("ALTER TABLE user_reports ADD UNIQUE KEY uq_reporter_reported_pending (reporter_id, reported_id, status)");
    } catch (Exception $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        name VARCHAR(50) PRIMARY KEY,
        value VARCHAR(255) DEFAULT NULL
    )");

    $defaultSettings = [
        'site_lock' => '0',
        'report_lock' => '0',
        'auto_reset_enabled' => '0',
        'auto_reset_target' => '0',
        'auto_reset_recurring' => '0',
        'delete_lock' => '0',
        'hide_admin_online' => '0',
        'max_rooms_per_user' => '1',
    ];
    foreach ($defaultSettings as $k => $v) {
        try { $pdo->prepare("INSERT IGNORE INTO settings (name, value) VALUES (?, ?)")->execute([$k, $v]); } catch (Exception $e) {}
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS global_alerts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS update_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        message TEXT NOT NULL,
        icon VARCHAR(10) DEFAULT '🔔',
        type ENUM('info','warning','success','update') DEFAULT 'info',
        is_active TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(is_active),
        INDEX(created_at)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS update_notif_seen (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        notif_id INT NOT NULL,
        seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_notif (user_id, notif_id),
        INDEX(user_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS pinned_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        msg_id INT NOT NULL DEFAULT 0,
        room_name VARCHAR(50) DEFAULT NULL,
        receiver_id INT DEFAULT NULL,
        pinned_by INT NOT NULL DEFAULT 0,
        sender_name VARCHAR(50) DEFAULT NULL,
        msg_type VARCHAR(20) DEFAULT 'text',
        message LONGTEXT DEFAULT NULL,
        file_name VARCHAR(255) DEFAULT NULL,
        file_token VARCHAR(64) DEFAULT NULL,
        pinned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pm_room(room_name),
        INDEX idx_pm_recv(receiver_id),
        INDEX idx_pm_msg(msg_id)
    )");

    try { $pdo->exec("ALTER TABLE pinned_messages DROP INDEX context"); } catch (Exception $e) {}

    $pinCols = [
        'msg_id'      => 'INT NOT NULL DEFAULT 0',
        'room_name'   => 'VARCHAR(50) DEFAULT NULL',
        'receiver_id' => 'INT DEFAULT NULL',
        'pinned_by'   => 'INT NOT NULL DEFAULT 0',
        'sender_name' => 'VARCHAR(50) DEFAULT NULL',
        'msg_type'    => "VARCHAR(20) DEFAULT 'text'",
        'message'     => 'LONGTEXT DEFAULT NULL',
        'file_name'   => 'VARCHAR(255) DEFAULT NULL',
        'file_token'  => 'VARCHAR(64) DEFAULT NULL',
        'pinned_at'   => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
    ];
    foreach ($pinCols as $colName => $colDef) {
        $chk = $pdo->query("SHOW COLUMNS FROM pinned_messages LIKE '$colName'");
        if ($chk->rowCount() == 0) $pdo->exec("ALTER TABLE pinned_messages ADD COLUMN $colName $colDef");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS message_reactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL,
        user_id INT NOT NULL,
        reaction VARCHAR(10) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_reaction (message_id, user_id),
        INDEX idx_msg_id(message_id),
        INDEX idx_user_id(user_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS channel_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        channel_name VARCHAR(50) NOT NULL,
        user_id INT NOT NULL,
        role ENUM('owner','admin','member') DEFAULT 'member',
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ch_user (channel_name, user_id),
        INDEX(channel_name),
        INDEX(user_id)
    )");

} catch (PDOException $e) {
    error_log("DB Error: " . $e->getMessage());
    die("خطا در سیستم پایگاه داده. لطفا بعدا تلاش کنید.");
}

function perform_system_reset($pdo) {
    global $admin_username;

    $files = glob('uploads/*');
    foreach ($files as $file) {
        if (is_file($file) && basename($file) !== 'index.php' && basename($file) !== '.htaccess') {
            @unlink($file);
        }
    }

    $pdo->exec("DELETE FROM rooms WHERE name != 'گفتگوی عمومی'");
    $pdo->exec("TRUNCATE TABLE messages");
    $pdo->exec("TRUNCATE TABLE room_invites");
    $pdo->exec("TRUNCATE TABLE banned_ips");
    $pdo->exec("TRUNCATE TABLE user_reports");
    $pdo->exec("TRUNCATE TABLE global_alerts");
    $pdo->exec("TRUNCATE TABLE pinned_messages");
    $pdo->exec("TRUNCATE TABLE channel_members");

    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$admin_username]);
    $adminId = $stmt->fetchColumn();

    if ($adminId) {
        $pdo->prepare("UPDATE rooms SET created_by = ?")->execute([$adminId]);
        $pdo->prepare("DELETE FROM users WHERE id != ?")->execute([$adminId]);
        $pdo->prepare("UPDATE users SET last_notif_id = 0, is_banned_until = 0, muted_until = 0, typing_time = 0, device_token = NULL WHERE id = ?")->execute([$adminId]);
    }
}

function clean_old_data($pdo, $lifetime) {
    $limit = date('Y-m-d H:i:s', time() - $lifetime);

    $stmt = $pdo->prepare("SELECT file_path FROM messages WHERE created_at < ? AND file_path IS NOT NULL");
    $stmt->execute([$limit]);
    while ($row = $stmt->fetch()) {
        if (file_exists($row['file_path'])) @unlink($row['file_path']);
    }

    $pdo->prepare("DELETE FROM messages WHERE created_at < ?")->execute([$limit]);
    try { $pdo->prepare("DELETE FROM pinned_messages WHERE pinned_at < ?")->execute([$limit]); } catch (Exception $e) {}
    $pdo->prepare("DELETE FROM room_invites WHERE created_at < DATE_SUB(NOW(), INTERVAL 3 DAY)")->execute();
    $pdo->prepare("UPDATE users SET is_online = 0 WHERE last_activity < DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->execute();
}

try {
    $stmtChk = $pdo->prepare("SELECT value FROM settings WHERE name = 'auto_reset_enabled'");
    $stmtChk->execute();
    if ($stmtChk->fetchColumn() === '1') {
        $stmtTgt = $pdo->prepare("SELECT value FROM settings WHERE name = 'auto_reset_target'");
        $stmtTgt->execute();
        $target = (int)$stmtTgt->fetchColumn();

        if (time() >= $target && $target > 0) {
            perform_system_reset($pdo);

            $stmtRec = $pdo->prepare("SELECT value FROM settings WHERE name = 'auto_reset_recurring'");
            $stmtRec->execute();
            $isRecurring = $stmtRec->fetchColumn() === '1';

            if ($isRecurring) {
                $newTarget = time() + (24 * 3600);
                $pdo->prepare("UPDATE settings SET value = ? WHERE name = 'auto_reset_target'")->execute([$newTarget]);
            } else {
                $pdo->prepare("UPDATE settings SET value = '0' WHERE name = 'auto_reset_enabled'")->execute();
                $pdo->prepare("UPDATE settings SET value = '0' WHERE name = 'auto_reset_target'")->execute();
            }
        }
    }
} catch (Exception $e) {}

if (!is_dir('uploads')) {
    mkdir('uploads', 0755, true);
    file_put_contents('uploads/index.php', '<?php header("HTTP/1.0 403 Forbidden");');
    file_put_contents('uploads/.htaccess', 'Deny from all');
}

if (rand(1, 50) === 1) {
    clean_old_data($pdo, $message_lifetime);
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
if (empty($_SESSION['bw_nonce'])) $_SESSION['bw_nonce'] = bin2hex(random_bytes(16));
?>
