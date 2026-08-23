<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: admin-login.php');
    exit;
}

$sessionUser = $_SESSION['username'];
$isMainAdmin = ($sessionUser === $admin_username);
$isSubAdmin  = (!$isMainAdmin && is_admin_user($sessionUser, $pdo));

if (!$isMainAdmin && !$isSubAdmin) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'شما ادمین نیستید']);
        exit;
    }
    ?>
    <!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>عدم دسترسی</title>
        <link rel="icon" type="image/png" href="<?php echo $favicon_path; ?>">
        <style>
            body { margin:0; height:100vh; display:flex; justify-content:center; align-items:center; background:#000; color:#fff; font-family:sans-serif; }
            .error-box { text-align:center; padding:40px; background:#1e293b; border-radius:20px; border:1px solid #f59e0b; max-width:90%; width:400px; }
            .icon { font-size:60px; margin-bottom:20px; display:block; }
            h1 { margin:0 0 10px; color:#f59e0b; }
            p { color:#94a3b8; margin-bottom:25px; }
            .btn { background:#334155; color:#fff; text-decoration:none; padding:10px 25px; border-radius:10px; display:inline-block; }
        </style>
    </head>
    <body>
        <div class="error-box">
            <span class="icon">⚠️</span>
            <h1>دسترسی محدود</h1>
            <p>فقط مدیران به این بخش دسترسی دارند.</p>
            <a href="index.php" class="btn">بازگشت به چت</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if (isset($_GET['backup']) && $isMainAdmin && isset($_GET['csrf_token']) && $_GET['csrf_token'] === $_SESSION['csrf_token']) {
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="blackwacker_backup_' . date('Y-m-d_H-i-s') . '.sql"');
    
    $tables = ['users', 'rooms', 'room_invites', 'messages', 'settings', 'system_settings', 'user_reports', 'banned_ips', 'global_alerts'];
    $out = "-- BlackWalker Chat Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    
    foreach ($tables as $table) {
        try {
            $stmt = $pdo->query("SELECT * FROM $table");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $out .= "TRUNCATE TABLE `$table`;\n";
            foreach ($rows as $row) {
                $keys = array_keys($row);
                $vals = array_map(function($v) use ($pdo) { return $v === null ? 'NULL' : $pdo->quote($v); }, array_values($row));
                $out .= "INSERT INTO `$table` (`" . implode("`,`", $keys) . "`) VALUES (" . implode(",", $vals) . ");\n";
            }
            $out .= "\n";
        } catch(Exception $e) {}
    }
    
    $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
    echo $out;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!isset($_POST['action']) || !isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['status' => 'error', 'message' => 'نشست نامعتبر']);
        exit;
    }

    $action = $_POST['action'];

    try {
        if ($action === 'get_live_data') {
            $usersCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $msgsCount = $pdo->query("SELECT COUNT(*) FROM messages")->fetchColumn();
            $roomsCount = $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
            
            $rooms = $pdo->query("SELECT r.*, u.username as creator FROM rooms r LEFT JOIN users u ON r.created_by = u.id ORDER BY r.type='public' DESC, r.id DESC")->fetchAll(PDO::FETCH_ASSOC);
            
            $sql = "SELECT id, username, sticker, is_online, last_activity, ip_address, is_banned_until FROM users ORDER BY 
                    CASE WHEN username = :admin THEN 0 ELSE 1 END, 
                    is_online DESC, 
                    last_activity DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['admin' => $admin_username]);
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $processedUsers = [];
            foreach($users as $u) {
                $isAdmin = ($u['username'] === $admin_username);
                $isBanned = ($u['is_banned_until'] > time());
                $isOnline = ($u['is_online'] == 1 && strtotime($u['last_activity']) > time() - 300);
                
                $statusHtml = '<span class="badge badge-success">فعال</span>';
                if ($isBanned) {
                    $rem = $u['is_banned_until'] - time();
                    if ($rem > 31536000) {
                        $statusHtml = '<span class="badge badge-danger">مسدود (دائم)</span>';
                    } else {
                        $min = ceil($rem / 60);
                        $statusHtml = "<span class=\"badge badge-danger\">مسدود ($min دقیقه)</span>";
                    }
                }

                $u['is_admin'] = $isAdmin;
                $u['is_online_bool'] = $isOnline;
                $u['online_text'] = $isOnline ? 'آنلاین' : 'آفلاین';
                $u['time_ago'] = time_elapsed_string($u['last_activity']);
                $u['status_html'] = $statusHtml;
                $processedUsers[] = $u;
            }

            $processedRooms = [];
            foreach($rooms as $r) {
                $isDefault = ($r['name'] === 'گفتگوی عمومی');
                
                if ($r['type'] === 'public') {
                    $r['type_html'] = '<span class="badge badge-success">عمومی</span>';
                } else {
                    $r['type_html'] = '<span class="badge badge-info">خصوصی</span>';
                }
                
                $r['creator'] = $r['creator'] ?: 'سیستم';
                $r['is_default'] = $isDefault;
                $processedRooms[] = $r;
            }
            
            $stmtLock = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'lock_upload'");
            $stmtLock->execute();
            $isUploadLocked = ($stmtLock->fetchColumn() === '1');

            $stmtVoice = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'lock_voice'");
            $stmtVoice->execute();
            $isVoiceLocked = ($stmtVoice->fetchColumn() === '1');

            $stmtSiteLock = $pdo->prepare("SELECT value FROM settings WHERE name = 'site_lock'");
            $stmtSiteLock->execute();
            $isSiteLocked = ($stmtSiteLock->fetchColumn() === '1');
            
            $stmtReportLock = $pdo->prepare("SELECT value FROM settings WHERE name = 'report_lock'");
            $stmtReportLock->execute();
            $isReportLocked = ($stmtReportLock->fetchColumn() === '1');

            $stmtDeleteLock = $pdo->prepare("SELECT value FROM settings WHERE name = 'delete_lock'");
            $stmtDeleteLock->execute();
            $isDeleteLocked = ($stmtDeleteLock->fetchColumn() === '1');

            $stmtMaxU = $pdo->prepare("SELECT value FROM settings WHERE name = 'max_users'");
            $stmtMaxU->execute();
            $maxUsersVal = $stmtMaxU->fetchColumn();
            $currentMaxUsers = ($maxUsersVal !== false && (int)$maxUsersVal > 0) ? (int)$maxUsersVal : 3;

            $stmtAR = $pdo->prepare("SELECT value FROM settings WHERE name = 'auto_reset_enabled'");
            $stmtAR->execute();
            $autoResetEnabled = ($stmtAR->fetchColumn() === '1');
            
            $stmtART = $pdo->prepare("SELECT value FROM settings WHERE name = 'auto_reset_target'");
            $stmtART->execute();
            $autoResetTarget = (int)$stmtART->fetchColumn();

            $stmtARR = $pdo->prepare("SELECT value FROM settings WHERE name = 'auto_reset_recurring'");
            $stmtARR->execute();
            $autoResetRecurring = ($stmtARR->fetchColumn() === '1');
            
            $autoResetText = 'غیرفعال';
            if ($autoResetEnabled && $autoResetTarget > time()) {
                $rem = $autoResetTarget - time();
                $h = floor($rem / 3600);
                $m = floor(($rem % 3600) / 60);
                $recText = $autoResetRecurring ? ' (تکرار ۲۴ ساعته)' : '';
                $autoResetText = "فعال: {$h} ساعت و {$m} دقیقه دیگر{$recText}";
            } elseif ($autoResetEnabled) {
                $autoResetText = "در حال اجرا...";
            }

            try {
                // ensure status column exists on user_reports
                $chkStatus = $pdo->query("SHOW COLUMNS FROM user_reports LIKE 'status'");
                if ($chkStatus->rowCount() == 0) {
                    $pdo->exec("ALTER TABLE user_reports ADD COLUMN status ENUM('pending','approved','rejected') DEFAULT 'pending'");
                }
                $chkReportCat = $pdo->query("SHOW COLUMNS FROM user_reports LIKE 'created_at'");
                if ($chkReportCat->rowCount() == 0) {
                    $pdo->exec("ALTER TABLE user_reports ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
                }
                $reports = $pdo->query("SELECT ur.id, ur.reason, ur.status, ur.created_at, u1.username as reporter, u2.username as reported, u2.id as reported_id FROM user_reports ur JOIN users u1 ON ur.reporter_id = u1.id JOIN users u2 ON ur.reported_id = u2.id ORDER BY FIELD(ur.status,'pending','approved','rejected'), ur.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { $reports = []; }
            
            foreach ($reports as &$rep) {
                $rep['time_ago'] = time_elapsed_string($rep['created_at']);
            }

            $bannedIps = $pdo->query("SELECT id, ip_address, banned_at, reason FROM banned_ips ORDER BY banned_at DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'users' => $processedUsers,
                'rooms' => $processedRooms,
                'reports' => $reports,
                'lock_upload' => $isUploadLocked,
                'lock_voice' => $isVoiceLocked,
                'site_lock' => $isSiteLocked,
                'report_lock' => $isReportLocked,
                'delete_lock' => $isDeleteLocked,
                'max_users' => $currentMaxUsers,
                'auto_reset_enabled' => $autoResetEnabled,
                'auto_reset_text' => $autoResetText,
                'auto_reset_recurring' => $autoResetRecurring,
                'banned_ips' => $bannedIps,
                'hide_admin_online' => (bool)($pdo->query("SELECT value FROM settings WHERE name='hide_admin_online'")->fetchColumn() === '1'),
                'stats' => [
                    'users' => number_format($usersCount),
                    'msgs' => number_format($msgsCount),
                    'rooms' => number_format($roomsCount)
                ]
            ]);
            exit;
        }
        elseif ($action === 'create_room') {
            $name = trim($_POST['name']);
            $type = $_POST['type'];
            
            if (empty($name)) throw new Exception('نام اتاق نمی‌تواند خالی باشد');
            
            $inviteCode = null;
            if ($type === 'private') {
                $inviteCode = bin2hex(random_bytes(4));
            }

            $stmt = $pdo->prepare("INSERT INTO rooms (name, type, created_by, invite_code) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $type, $_SESSION['user_id'], $inviteCode]);
            echo json_encode(['status' => 'success', 'message' => 'اتاق با موفقیت ساخته شد']);
        }
        elseif ($action === 'invite_user') {
            $roomName = $_POST['room_name'];
            $username = trim($_POST['username']);
            
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $uid = $stmt->fetchColumn();
            
            if (!$uid) throw new Exception('کاربر یافت نشد');
            
            $chk = $pdo->prepare("SELECT id FROM room_invites WHERE user_id = ? AND room_name = ?");
            $chk->execute([$uid, $roomName]);
            if ($chk->fetch()) throw new Exception('کاربر قبلاً دعوت شده است');
            
            $stmt = $pdo->prepare("INSERT INTO room_invites (user_id, room_name, invited_by) VALUES (?, ?, ?)");
            $stmt->execute([$uid, $roomName, $_SESSION['user_id']]);
            
            echo json_encode(['status' => 'success', 'message' => "کاربر $username با موفقیت دعوت شد"]);
        }
        elseif ($action === 'delete_room') {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("SELECT name FROM rooms WHERE id = ?");
            $stmt->execute([$id]);
            $roomName = $stmt->fetchColumn();

            if (!$roomName) throw new Exception('اتاق یافت نشد');
            if ($roomName === 'گفتگوی عمومی') throw new Exception('اتاق عمومی اصلی قابل حذف نیست');

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM messages WHERE room_name = ?")->execute([$roomName]);
            $pdo->prepare("DELETE FROM room_invites WHERE room_name = ?")->execute([$roomName]);
            $pdo->prepare("DELETE FROM rooms WHERE id = ?")->execute([$id]);
            $pdo->commit();

            echo json_encode(['status' => 'success', 'message' => 'اتاق با موفقیت حذف شد']);
        }
        elseif ($action === 'delete_all_custom_rooms') {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT name FROM rooms WHERE name != 'گفتگوی عمومی'");
            $stmt->execute();
            $roomsToDelete = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($roomsToDelete)) {
                $placeholders = implode(',', array_fill(0, count($roomsToDelete), '?'));
                $pdo->prepare("DELETE FROM messages WHERE room_name IN ($placeholders)")->execute($roomsToDelete);
                $pdo->prepare("DELETE FROM room_invites WHERE room_name IN ($placeholders)")->execute($roomsToDelete);
                $pdo->exec("DELETE FROM rooms WHERE name != 'گفتگوی عمومی'");
            }
            
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'تمام اتاق‌های اضافی حذف شدند']);
        }
        elseif ($action === 'delete_all_msgs_global') {
            $pdo->exec("TRUNCATE TABLE messages");
            echo json_encode(['status' => 'success', 'message' => 'تمام پیام‌های سیستم پاکسازی شد']);
        }
        elseif ($action === 'import_db') {
            if (!$isMainAdmin) {
                echo json_encode(['status' => 'error', 'message' => 'فقط ادمین اصلی به این بخش دسترسی دارد']);
                exit;
            }
            if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('فایلی انتخاب نشده است یا آپلود با خطا مواجه شد');
            }
            $ext = strtolower(pathinfo($_FILES['backup_file']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'sql') {
                throw new Exception('فقط فایل‌های SQL مجاز هستند');
            }
            
            $sqlContent = file_get_contents($_FILES['backup_file']['tmp_name']);
            if (empty($sqlContent)) throw new Exception('محتوای فایل خالی است');
            
            try {
                $pdo->exec($sqlContent);
                echo json_encode(['status' => 'success', 'message' => 'اطلاعات دیتابیس با موفقیت بازگردانی شد']);
            } catch(PDOException $e) {
                throw new Exception('خطا در اجرای کوئری‌ها: ' . $e->getMessage());
            }
        }
        elseif ($action === 'ban_user') {
            $userId = (int)$_POST['user_id'];
            $duration = isset($_POST['duration']) ? $_POST['duration'] : 'perm';
            
            if ($userId == $_SESSION['user_id']) throw new Exception('نمی‌توانید خودتان را مسدود کنید');

            $banTime = 0;
            if ($duration === '5') $banTime = time() + (5 * 60);
            elseif ($duration === '10') $banTime = time() + (10 * 60);
            else $banTime = time() + (365 * 24 * 3600); 

            $pdo->prepare("UPDATE users SET is_banned_until = ?, is_online = 0 WHERE id = ?")->execute([$banTime, $userId]);
            
            if ($duration === 'perm') {
                $stmt = $pdo->prepare("SELECT ip_address FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $ip = $stmt->fetchColumn();
                if ($ip) {
                    $pdo->prepare("INSERT IGNORE INTO banned_ips (ip_address) VALUES (?)")->execute([$ip]);
                }
            }
            
            echo json_encode(['status' => 'success', 'message' => 'کاربر مسدود شد']);
        }
        elseif ($action === 'unban_user') {
            $userId = (int)$_POST['user_id'];
            $pdo->prepare("UPDATE users SET is_banned_until = 0 WHERE id = ?")->execute([$userId]);
            $stmt = $pdo->prepare("SELECT ip_address FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $ip = $stmt->fetchColumn();
            if($ip) $pdo->prepare("DELETE FROM banned_ips WHERE ip_address = ?")->execute([$ip]);
            echo json_encode(['status' => 'success', 'message' => 'کاربر رفع مسدودیت شد']);
        }
        elseif ($action === 'global_reset') {
            $pass = $_POST['password'];
            if ($pass !== $admin_password) throw new Exception('رمز عبور اشتباه است');

            perform_system_reset($pdo);
            
            echo json_encode(['status' => 'success', 'message' => 'سیستم با موفقیت بازنشانی شد']);
        }
        elseif ($action === 'enable_auto_reset') {
            $hours = (int)$_POST['hours'];
            $recurring = isset($_POST['recurring']) && $_POST['recurring'] === '1' ? '1' : '0';
            
            if ($hours < 1) throw new Exception('حداقل زمان ۱ ساعت است');
            
            $targetTime = time() + ($hours * 3600);
            $pdo->prepare("UPDATE settings SET value = '1' WHERE name = 'auto_reset_enabled'")->execute();
            $pdo->prepare("UPDATE settings SET value = ? WHERE name = 'auto_reset_target'")->execute([$targetTime]);
            $pdo->prepare("UPDATE settings SET value = ? WHERE name = 'auto_reset_recurring'")->execute([$recurring]);
            
            $recMsg = $recurring === '1' ? ' (با تکرار ۲۴ ساعته)' : '';
            echo json_encode(['status' => 'success', 'message' => "ریست خودکار برای $hours ساعت دیگر تنظیم شد$recMsg"]);
        }
        elseif ($action === 'disable_auto_reset') {
            $pdo->prepare("UPDATE settings SET value = '0' WHERE name = 'auto_reset_enabled'")->execute();
            $pdo->prepare("UPDATE settings SET value = '0' WHERE name = 'auto_reset_target'")->execute();
            $pdo->prepare("UPDATE settings SET value = '0' WHERE name = 'auto_reset_recurring'")->execute();
            echo json_encode(['status' => 'success', 'message' => 'ریست خودکار لغو شد']);
        }
        elseif ($action === 'delete_user_msgs') {
            $userId = (int)$_POST['user_id'];
            $pdo->prepare("DELETE FROM messages WHERE sender_id = ?")->execute([$userId]);
            echo json_encode(['status' => 'success', 'message' => 'تمام پیام‌های کاربر حذف شد']);
        }
        elseif ($action === 'burn_session') {
            $userId = (int)$_POST['user_id'];
            $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $targetUsername = $stmt->fetchColumn();
            if (!$targetUsername) throw new Exception('کاربر یافت نشد');
            if ($targetUsername === $admin_username) throw new Exception('سشن ادمین قابل سوزاندن نیست');
            $pdo->prepare("UPDATE users SET is_online = 0, device_token = NULL WHERE id = ?")->execute([$userId]);
            echo json_encode(['status' => 'success', 'message' => "سشن کاربر $targetUsername سوزانده شد"]);
        }
        elseif ($action === 'burn_all_sessions') {
            $pdo->prepare("UPDATE users SET is_online = 0, device_token = NULL WHERE username != ?")->execute([$admin_username]);
            echo json_encode(['status' => 'success', 'message' => 'سشن تمام کاربران (بجز ادمین) سوزانده شد']);
        }
        elseif ($action === 'set_max_users') {
            $val = (int)$_POST['max_users'];
            if ($val < 1 || $val > 9999) throw new Exception('عدد وارد شده معتبر نیست');
            $chk = $pdo->prepare("SELECT name FROM settings WHERE name = 'max_users'");
            $chk->execute();
            if ($chk->fetch()) {
                $pdo->prepare("UPDATE settings SET value = ? WHERE name = 'max_users'")->execute([$val]);
            } else {
                $pdo->prepare("INSERT INTO settings (name, value) VALUES ('max_users', ?)")->execute([$val]);
            }
            echo json_encode(['status' => 'success', 'message' => "سقف ساخت حساب به $val تغییر یافت"]);
        }
        elseif ($action === 'toggle_upload_lock') {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'lock_upload'");
            $stmt->execute();
            $curr = $stmt->fetchColumn();
            $new = ($curr === '1') ? '0' : '1';
            $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'lock_upload'")->execute([$new]);
            echo json_encode(['status' => 'success', 'message' => ($new === '1' ? 'آپلود فایل غیرفعال شد' : 'آپلود فایل فعال شد')]);
        }
        elseif ($action === 'toggle_voice_lock') {
            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'lock_voice'");
            $stmt->execute();
            $curr = $stmt->fetchColumn();
            $new = ($curr === '1') ? '0' : '1';
            $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'lock_voice'")->execute([$new]);
            echo json_encode(['status' => 'success', 'message' => ($new === '1' ? 'ویس غیرفعال شد' : 'ویس فعال شد')]);
        }
        elseif ($action === 'toggle_site_lock') {
            $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = 'site_lock'");
            $stmt->execute();
            $curr = $stmt->fetchColumn();
            $new = ($curr === '1') ? '0' : '1';
            $pdo->prepare("UPDATE settings SET value = ? WHERE name = 'site_lock'")->execute([$new]);
            echo json_encode(['status' => 'success', 'message' => ($new === '1' ? 'سایت قفل شد' : 'سایت باز شد')]);
        }
        elseif ($action === 'toggle_report_lock') {
            $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = 'report_lock'");
            $stmt->execute();
            $curr = $stmt->fetchColumn();
            $new = ($curr === '1') ? '0' : '1';
            $pdo->prepare("UPDATE settings SET value = ? WHERE name = 'report_lock'")->execute([$new]);
            echo json_encode(['status' => 'success', 'message' => ($new === '1' ? 'گزارش‌دهی قفل شد' : 'گزارش‌دهی آزاد شد')]);
        }
        elseif ($action === 'toggle_delete_lock') {
            $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = 'delete_lock'");
            $stmt->execute();
            $curr = $stmt->fetchColumn();
            $new = ($curr === '1') ? '0' : '1';
            $pdo->prepare("UPDATE settings SET value = ? WHERE name = 'delete_lock'")->execute([$new]);
            echo json_encode(['status' => 'success', 'message' => ($new === '1' ? 'حذف پیام‌ها و محتوا غیرفعال شد' : 'حذف پیام‌ها و محتوا فعال شد')]);
        }
        elseif ($action === 'delete_report') {
            $id = (int)$_POST['id'];
            $pdo->prepare("DELETE FROM user_reports WHERE id = ?")->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'گزارش حذف شد']);
        }

        elseif ($action === 'approve_report') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE user_reports SET status = 'approved' WHERE id = ?")->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'گزارش تأیید شد']);
        }

        elseif ($action === 'reject_report') {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE user_reports SET status = 'rejected' WHERE id = ?")->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'گزارش رد شد']);
        }

        elseif ($action === 'get_user_msgs') {
            $targetId = (int)$_POST['user_id'];
            $stmt = $pdo->prepare("
                SELECT m.id, m.message, m.msg_type, m.file_name, m.room_name, m.receiver_id, m.created_at,
                       u.username as sender_name
                FROM messages m 
                JOIN users u ON m.sender_id = u.id 
                WHERE m.sender_id = ? OR m.receiver_id = ?
                ORDER BY m.created_at DESC 
                LIMIT 300
            ");
            $stmt->execute([$targetId, $targetId]);
            $msgs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmtUser = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $stmtUser->execute([$targetId]);
            $targetUsername = $stmtUser->fetchColumn();

            $stmtAll = $pdo->query("SELECT id, username FROM users");
            $usersMap = [];
            foreach ($stmtAll->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $usersMap[$u['id']] = $u['username'];
            }

            $grouped = [];
            foreach ($msgs as &$m) {
                if ($m['msg_type'] === 'text') $m['message'] = decrypt_data($m['message']);
                $ts = strtotime($m['created_at']);
                $m['created_at'] = jdate("Y/m/d H:i", $ts);

                if ($m['room_name']) {
                    $key = 'room::' . $m['room_name'];
                    $label = 'اتاق: ' . $m['room_name'];
                } elseif ($m['receiver_id']) {
                    $otherId = ($m['sender_name'] === $targetUsername) ? $m['receiver_id'] : $targetId;
                    $otherName = $usersMap[$otherId] ?? ('کاربر #' . $otherId);
                    $key = 'pm::' . $otherId;
                    $label = 'خصوصی با: ' . $otherName;
                } else {
                    $key = 'other';
                    $label = 'سایر';
                }

                if (!isset($grouped[$key])) {
                    $grouped[$key] = ['label' => $label, 'msgs' => []];
                }
                $grouped[$key]['msgs'][] = $m;
            }

            echo json_encode(['status' => 'success', 'groups' => array_values($grouped), 'target_username' => $targetUsername]);
        }
        elseif ($action === 'send_global_notif') {
            $msg = trim($_POST['message']);
            if(empty($msg)) throw new Exception('متن نوتیفیکیشن نمی‌تواند خالی باشد');
            
            $pdo->exec("CREATE TABLE IF NOT EXISTS global_alerts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            
            $stmt = $pdo->prepare("INSERT INTO global_alerts (message) VALUES (?)");
            $stmt->execute([$msg]);
            
            echo json_encode(['status' => 'success', 'message' => 'نوتیفیکیشن سراسری برای تمامی کاربران ارسال شد']);
        }

        elseif ($action === 'ban_ip') {
            $ip = trim($_POST['ip_address']);
            $reason = trim($_POST['reason'] ?? 'بن توسط مدیر');
            if (empty($ip)) throw new Exception('آدرس IP نمی‌تواند خالی باشد');
            if (!filter_var($ip, FILTER_VALIDATE_IP)) throw new Exception('آدرس IP معتبر نیست');
            $pdo->prepare("INSERT IGNORE INTO banned_ips (ip_address, reason) VALUES (?, ?)")->execute([$ip, $reason]);
            $pdo->prepare("UPDATE users SET is_banned_until = ? WHERE ip_address = ?")->execute([time() + (365 * 24 * 3600), $ip]);
            $pdo->prepare("DELETE FROM user_sessions WHERE ip_address = ?")->execute([$ip]);
            echo json_encode(['status' => 'success', 'message' => "آدرس IP $ip مسدود شد"]);
        }
        elseif ($action === 'unban_ip') {
            $ip = trim($_POST['ip_address']);
            if (empty($ip)) throw new Exception('آدرس IP نمی‌تواند خالی باشد');
            $pdo->prepare("DELETE FROM banned_ips WHERE ip_address = ?")->execute([$ip]);
            $pdo->prepare("UPDATE users SET is_banned_until = 0 WHERE ip_address = ? AND is_banned_until > 0")->execute([$ip]);
            echo json_encode(['status' => 'success', 'message' => "مسدودیت IP $ip رفع شد"]);
        }
        elseif ($action === 'get_user_sessions') {
            $targetId = (int)$_POST['user_id'];
            $stmt = $pdo->prepare("SELECT id, session_token, ip_address, user_agent, created_at, last_activity FROM user_sessions WHERE user_id = ? ORDER BY last_activity DESC");
            $stmt->execute([$targetId]);
            $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmtUsr = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $stmtUsr->execute([$targetId]);
            $uname = $stmtUsr->fetchColumn();
            foreach ($sessions as &$s) {
                $s['session_token_short'] = substr($s['session_token'], 0, 12) . '...';
                $s['is_recent'] = (strtotime($s['last_activity']) > time() - 300);
            }
            echo json_encode(['status' => 'success', 'sessions' => $sessions, 'username' => $uname]);
        }
        elseif ($action === 'kill_session') {
            $sessionId = (int)$_POST['session_id'];
            $stmt = $pdo->prepare("SELECT user_id FROM user_sessions WHERE id = ?");
            $stmt->execute([$sessionId]);
            $row = $stmt->fetch();
            if (!$row) throw new Exception('نشست یافت نشد');
            $stmt2 = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $stmt2->execute([$row['user_id']]);
            $uname = $stmt2->fetchColumn();
            if ($uname === $admin_username) throw new Exception('نشست ادمین قابل حذف نیست');
            $pdo->prepare("DELETE FROM user_sessions WHERE id = ?")->execute([$sessionId]);
            echo json_encode(['status' => 'success', 'message' => 'نشست حذف شد. کاربر از حساب خارج می‌شود.']);
        }
        elseif ($action === 'kill_all_sessions_user') {
            $targetId = (int)$_POST['user_id'];
            $stmt2 = $pdo->prepare("SELECT username FROM users WHERE id = ?");
            $stmt2->execute([$targetId]);
            $uname = $stmt2->fetchColumn();
            if ($uname === $admin_username) throw new Exception('نشست‌های ادمین قابل حذف نیست');
            $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?")->execute([$targetId]);
            $pdo->prepare("UPDATE users SET is_online = 0, device_token = NULL WHERE id = ?")->execute([$targetId]);
            echo json_encode(['status' => 'success', 'message' => "تمام نشست‌های $uname حذف شد"]);
        }
        elseif ($action === 'approve_report_and_ban') {
            if (!$isMainAdmin) throw new Exception('فقط مدیر اصلی');
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT reported_id FROM user_reports WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) throw new Exception('گزارش یافت نشد');
            $reportedId = $row['reported_id'];
            $stmtU = $pdo->prepare("SELECT username, ip_address FROM users WHERE id = ?");
            $stmtU->execute([$reportedId]);
            $tgtUser = $stmtU->fetch();
            if (!$tgtUser) throw new Exception('کاربر یافت نشد');
            if ($tgtUser['username'] === $admin_username) throw new Exception('ادمین قابل مسدود شدن نیست');
            $banTime = time() + (365 * 24 * 3600);
            $pdo->prepare("UPDATE users SET is_banned_until = ?, is_online = 0 WHERE id = ?")->execute([$banTime, $reportedId]);
            if ($tgtUser['ip_address']) {
                $pdo->prepare("INSERT IGNORE INTO banned_ips (ip_address, reason) VALUES (?, 'تأیید گزارش')")->execute([$tgtUser['ip_address']]);
            }
            $pdo->prepare("UPDATE user_reports SET status = 'approved' WHERE id = ?")->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'گزارش تأیید و کاربر بن شد']);
        }
        elseif ($action === 'list_admins') {
            if (!$isMainAdmin) throw new Exception('دسترسی محدود');
            $rows = $pdo->query("SELECT id, username, display_name, created_by, created_at, last_login, is_active FROM admin_users ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'admins' => $rows]);
        }
        elseif ($action === 'add_admin') {
            if (!$isMainAdmin) throw new Exception('فقط مدیر اصلی');
            $uname = xss_clean(trim($_POST['username'] ?? ''));
            $pass  = trim($_POST['password'] ?? '');
            $dname = xss_clean(trim($_POST['display_name'] ?? $uname));
            if (empty($uname) || empty($pass)) throw new Exception('نام کاربری و رمز عبور الزامی است');
            if (strlen($pass) < 8) throw new Exception('رمز عبور باید حداقل ۸ کاراکتر باشد');
            if ($uname === $admin_username) throw new Exception('این نام کاربری رزرو شده است');
            $chk = $pdo->prepare("SELECT id FROM admin_users WHERE username = ?");
            $chk->execute([$uname]);
            if ($chk->fetch()) throw new Exception('این ادمین قبلاً ثبت شده است');
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO admin_users (username, password_hash, display_name, created_by) VALUES (?, ?, ?, ?)")
                ->execute([$uname, $hash, $dname, $admin_username]);
            $chkUser = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $chkUser->execute([$uname]);
            if (!$chkUser->fetch()) {
                $pdo->prepare("INSERT INTO users (username, sticker, is_online) VALUES (?, '👑', 0)")->execute([$uname]);
            }
            echo json_encode(['status' => 'success', 'message' => "ادمین $uname اضافه شد"]);
        }
        elseif ($action === 'remove_admin') {
            if (!$isMainAdmin) throw new Exception('فقط مدیر اصلی');
            $id = (int)($_POST['admin_id'] ?? 0);
            $pdo->prepare("DELETE FROM admin_users WHERE id = ?")->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'ادمین حذف شد']);
        }
        elseif ($action === 'toggle_admin_active') {
            if (!$isMainAdmin) throw new Exception('دسترسی محدود');
            $id = (int)($_POST['admin_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT is_active FROM admin_users WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) throw new Exception('ادمین یافت نشد');
            $new = $row['is_active'] ? 0 : 1;
            $pdo->prepare("UPDATE admin_users SET is_active = ? WHERE id = ?")->execute([$new, $id]);
            echo json_encode(['status' => 'success', 'message' => $new ? 'ادمین فعال شد' : 'ادمین غیرفعال شد']);
        }
        elseif ($action === 'get_hide_admin_state') {
            $stmt2 = $pdo->prepare("SELECT value FROM settings WHERE name = 'hide_admin_online'");
            $stmt2->execute();
            $val = $stmt2->fetchColumn();
            echo json_encode(['status' => 'success', 'state' => ($val === '1') ? 1 : 0]);
        }
        elseif ($action === 'toggle_hide_admin_online') {
            if (!$isMainAdmin) throw new Exception('دسترسی محدود');
            $stmt = $pdo->prepare("SELECT value FROM settings WHERE name = 'hide_admin_online'");
            $stmt->execute();
            $curr = $stmt->fetchColumn();
            $new = ($curr === '1') ? '0' : '1';
            $pdo->prepare("UPDATE settings SET value = ? WHERE name = 'hide_admin_online'")->execute([$new]);
            if ($new === '1') {
                $pdo->prepare("UPDATE users SET is_online = 0 WHERE username = ?")->execute([$admin_username]);
            }
            echo json_encode(['status' => 'success', 'message' => ($new === '1' ? 'وضعیت آنلاین ادمین مخفی شد' : 'وضعیت آنلاین ادمین نمایش داده می‌شود'), 'state' => (int)$new]);
        }
        elseif ($action === 'create_channel') {
            $name    = xss_clean(trim($_POST['name'] ?? ''));
            $type    = ($_POST['type'] ?? 'public') === 'private' ? 'private' : 'public';
            $cat     = xss_clean(trim($_POST['category'] ?? ''));
            if (empty($name)) throw new Exception('نام کانال نمی‌تواند خالی باشد');
            $inviteCode = ($type === 'private') ? bin2hex(random_bytes(4)) : null;
            $pdo->prepare("INSERT INTO rooms (name, type, room_kind, created_by, invite_code, category) VALUES (?, ?, 'channel', ?, ?, ?)")
                ->execute([$name, $type, $_SESSION['user_id'], $inviteCode, $cat ?: null]);
            echo json_encode(['status' => 'success', 'message' => "کانال $name ساخته شد", 'invite_code' => $inviteCode]);
        }
        elseif ($action === 'delete_channel') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT name, room_kind FROM rooms WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) throw new Exception('کانال یافت نشد');
            if ($row['room_kind'] !== 'channel') throw new Exception('این مورد کانال نیست');
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM messages WHERE room_name = ?")->execute([$row['name']]);
            $pdo->prepare("DELETE FROM room_invites WHERE room_name = ?")->execute([$row['name']]);
            $pdo->prepare("DELETE FROM channel_members WHERE channel_name = ?")->execute([$row['name']]);
            $pdo->prepare("DELETE FROM rooms WHERE id = ?")->execute([$id]);
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'کانال حذف شد']);
        }
        elseif ($action === 'list_update_notifs') {
            if (!$isMainAdmin) throw new Exception('دسترسی محدود');
            $rows = $pdo->query("SELECT * FROM update_notifications ORDER BY created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'notifications' => $rows]);
        }
        elseif ($action === 'add_update_notif') {
            if (!$isMainAdmin) throw new Exception('دسترسی محدود');
            $title = xss_clean(trim($_POST['title'] ?? ''));
            $msg   = xss_clean(trim($_POST['message'] ?? ''));
            $icon  = trim($_POST['icon'] ?? '🔔');
            $type  = in_array($_POST['type'] ?? 'info', ['info','warning','success','update']) ? $_POST['type'] : 'info';
            if (empty($title) || empty($msg)) throw new Exception('عنوان و متن الزامی است');
            $pdo->prepare("INSERT INTO update_notifications (title, message, icon, type, is_active) VALUES (?, ?, ?, ?, 1)")
                ->execute([$title, $msg, $icon, $type]);
            echo json_encode(['status' => 'success', 'message' => 'اطلاعیه اضافه شد']);
        }
        elseif ($action === 'delete_update_notif') {
            if (!$isMainAdmin) throw new Exception('دسترسی محدود');
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM update_notifications WHERE id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM update_notif_seen WHERE notif_id = ?")->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'اطلاعیه حذف شد']);
        }
        elseif ($action === 'toggle_update_notif') {
            if (!$isMainAdmin) throw new Exception('دسترسی محدود');
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT is_active FROM update_notifications WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) throw new Exception('اطلاعیه یافت نشد');
            $new = $row['is_active'] ? 0 : 1;
            $pdo->prepare("UPDATE update_notifications SET is_active = ? WHERE id = ?")->execute([$new, $id]);
            echo json_encode(['status' => 'success', 'message' => $new ? 'فعال شد' : 'غیرفعال شد']);
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>پنل مدیریت حرفه‌ای</title>
    <meta name="csrf-token" content="<?php echo $_SESSION['csrf_token']; ?>">
    <link rel="icon" type="image/png" href="<?php echo $favicon_path; ?>">
    <style>
        @font-face { font-family: 'AppFont'; src: url('fonts/<?php echo $font_text; ?>.ttf') format('truetype'); font-weight: normal; }
        @font-face { font-family: 'AppHeading'; src: url('fonts/<?php echo $font_heading; ?>.ttf') format('truetype'); font-weight: bold; }
        
        :root { --bg: #0f172a; --panel: #1e293b; --border: #334155; --primary: #6366f1; --primary-hover: #4f46e5; --danger: #ef4444; --success: #10b981; --text: #f8fafc; --text-muted: #94a3b8; }
        
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body { background-color: var(--bg); color: var(--text); font-family: 'AppFont', Tahoma, sans-serif; margin: 0; padding: 0; padding-bottom: 80px; overflow-x: hidden; }
        
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        
        .header { background: rgba(30, 41, 59, 0.95); backdrop-filter: blur(20px); border-bottom: 1px solid var(--border); padding: 15px 30px; position: sticky; top: 0; z-index: 100; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 10px 40px -10px rgba(0,0,0,0.5); border-radius: 0 0 25px 25px; margin-bottom: 30px; }
        .header h1 { margin: 0; font-size: 1.4rem; color: #fff; font-family: 'AppHeading'; text-shadow: 0 0 20px rgba(99,102,241,0.5); }
        
        .btn { padding: 12px 20px; border-radius: 14px; border: none; cursor: pointer; color: #fff; font-family: 'AppFont'; font-size: 0.95rem; transition: all 0.3s ease; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; justify-content: center; font-weight: bold; white-space: nowrap; box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
        .btn:active { transform: scale(0.96); }
        .btn-primary { background: linear-gradient(135deg, var(--primary), #818cf8); }
        .btn-primary:hover { box-shadow: 0 8px 25px rgba(99,102,241,0.4); }
        .btn-danger { background: linear-gradient(135deg, var(--danger), #f87171); }
        .btn-danger:hover { box-shadow: 0 8px 25px rgba(239,68,68,0.4); }
        .btn-ghost { background: rgba(255,255,255,0.05); border: 1px solid var(--border); color: var(--text-muted); box-shadow: none; }
        .btn-ghost:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .btn-success { background: linear-gradient(135deg, var(--success), #34d399); box-shadow: 0 5px 15px rgba(16, 185, 129, 0.2); }
        .btn-success:hover { box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4); }
        .btn-sm { padding: 6px 14px; font-size: 0.8rem; border-radius: 10px; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: linear-gradient(145deg, #1e293b, #172033); border: 1px solid var(--border); padding: 25px; border-radius: 20px; text-align: center; position: relative; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.2); transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); border-color: var(--primary); }
        .stat-value { font-size: 2.2rem; font-family: 'AppHeading'; display: block; margin-bottom: 5px; background: linear-gradient(to right, #fff, #94a3b8); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .stat-label { font-size: 0.9rem; color: var(--text-muted); letter-spacing: 0.5px; }

        .card { background: #1e293b; border: 1px solid var(--border); border-radius: 24px; padding: 30px; margin-bottom: 25px; overflow: hidden; position: relative; box-shadow: 0 20px 50px -10px rgba(0,0,0,0.3); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 20px; }
        .card-title { margin: 0; font-size: 1.3rem; border-right: 5px solid var(--primary); padding-right: 15px; color: #fff; font-family: 'AppHeading'; }

        .action-box { background: rgba(0,0,0,0.2); border-radius: 18px; padding: 20px; margin-bottom: 20px; border: 1px dashed var(--border); display: flex; flex-direction: column; gap: 15px; }
        .action-box h4 { margin: 0 0 10px; color: var(--text-muted); font-size: 0.95rem; }

        .form-control { background: #0f172a; border: 1px solid var(--border); color: var(--text); padding: 15px; border-radius: 14px; width: 100%; outline: none; font-family: inherit; transition: 0.3s; font-size: 1rem; }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15); }
        
        .input-group { display: flex; gap: 12px; flex-wrap: wrap; }
        .input-group > * { flex: 1; min-width: 200px; }
        .input-group button { flex: 0 0 auto; }

        .table-responsive { overflow-x: auto; border-radius: 18px; border: 1px solid var(--border); background: #0f172a; -ms-overflow-style: none; scrollbar-width: none; }
        .table-responsive::-webkit-scrollbar { display: none; }
        table { width: 100%; border-collapse: collapse; min-width: 800px; }
        th, td { padding: 18px; text-align: right; border-bottom: 1px solid rgba(255,255,255,0.05); }
        th { background: rgba(255,255,255,0.03); color: var(--text-muted); font-weight: normal; font-size: 0.9rem; letter-spacing: 0.5px; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(255,255,255,0.02); }
        
        .badge { padding: 6px 12px; border-radius: 10px; font-size: 0.8rem; display: inline-block; font-weight: bold; }
        .badge-success { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.2); }
        .badge-danger { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.2); }
        .badge-info { background: rgba(99, 102, 241, 0.15); color: #818cf8; border: 1px solid rgba(99, 102, 241, 0.2); }
        .badge-secondary { background: rgba(255, 255, 255, 0.1); color: #cbd5e1; border: 1px solid rgba(255, 255, 255, 0.2); }
        
        .status-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-left: 8px; position: relative; }
        .status-dot.online { background: var(--success); box-shadow: 0 0 10px var(--success); }
        .status-dot.online::after { content:''; position:absolute; inset:-4px; border-radius:50%; border:1px solid var(--success); opacity:0.5; animation: ping 1.5s infinite; }
        .status-dot.offline { background: var(--text-muted); opacity: 0.5; }
        @keyframes ping { 0%{transform:scale(0.8);opacity:0.8} 100%{transform:scale(2);opacity:0} }

        .modal-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.9); backdrop-filter: blur(10px); z-index: 2000; display: none; justify-content: center; align-items: center; padding: 20px; }
        .modal-content { background: linear-gradient(145deg, #1e293b, #172033); width: 100%; max-width: 450px; padding: 40px; border-radius: 30px; border: 1px solid rgba(255,255,255,0.1); text-align: center; animation: popIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1); box-shadow: 0 25px 80px rgba(0,0,0,0.6); display:flex; flex-direction:column; max-height:85vh; }
        @keyframes popIn { from { transform: scale(0.9) translateY(20px); opacity: 0; } to { transform: scale(1) translateY(0); opacity: 1; } }
        .modal-icon { font-size: 4rem; margin-bottom: 20px; display: block; filter: drop-shadow(0 0 20px rgba(255,255,255,0.2)); }

        .danger-zone { border: 1px dashed var(--danger); background: rgba(239, 68, 68, 0.02); }
        .danger-card-title { color: var(--danger); border-color: var(--danger); }
        
        #toast { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%) translateY(100px); background: rgba(16, 185, 129, 0.95); backdrop-filter: blur(5px); color: #fff; padding: 12px 25px; border-radius: 50px; z-index: 3000; transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); font-weight: bold; box-shadow: 0 10px 30px rgba(0,0,0,0.4); display:flex; align-items:center; gap:10px; font-size: 0.95rem; border: 1px solid rgba(255,255,255,0.2); width: max-content; max-width: 90%; white-space: nowrap; }
        #toast.show { transform: translateX(-50%) translateY(0); }
        #toast.error { background: rgba(239, 68, 68, 0.95); }
        
        .msg-list { overflow-y:auto; text-align:right; margin-bottom:20px; padding:10px; border:1px solid var(--border); border-radius:15px; background:rgba(0,0,0,0.2); flex:1; -ms-overflow-style: none; scrollbar-width: none; }
        .msg-list::-webkit-scrollbar { display: none; }
        .admin-msg-item { background:rgba(255,255,255,0.05); padding:10px; margin-bottom:8px; border-radius:10px; font-size:0.9rem; border:1px solid rgba(255,255,255,0.05); }
        .admin-msg-meta { font-size:0.75rem; color:var(--text-muted); margin-bottom:5px; display:flex; justify-content:space-between; }

        .settings-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; }
        
        #paginationControls .btn { transition: 0.2s; }
        #paginationControls .btn-primary { background: var(--primary); color: #fff; box-shadow: 0 2px 10px rgba(99,102,241,0.3); border-color: var(--primary); }

        @media (max-width: 768px) {
            .header { padding: 15px; margin-bottom: 20px; border-radius: 0 0 20px 20px; flex-direction: column; gap: 10px; text-align: center; }
            .header h1 { font-size: 1.1rem; }
            .btn-header span { display: none; }
            .card { padding: 20px; border-radius: 20px; }
            .action-box { padding: 15px; }
            .btn { width: 100%; }
            .input-group > * { min-width: 100%; }
            .stat-value { font-size: 1.8rem; }
            #toast { bottom: 20px; font-size: 0.85rem; padding: 10px 20px; }
        }
    </style>
</head>
<body>

<div id="toast"></div>

<div id="confirmModal" class="modal-overlay">
    <div class="modal-content" style="max-height:auto">
        <span class="modal-icon">🤔</span>
        <h3 style="margin-top:0; font-size:1.5rem; color:#fff">تایید عملیات</h3>
        <p id="confirmText" style="color:var(--text-muted); margin-bottom:30px; line-height:1.7; font-size:1.1rem"></p>
        <div style="display:flex; gap:15px;">
            <button id="confirmYes" class="btn btn-primary" style="flex:1; padding:15px;">بله، انجام شود</button>
            <button onclick="closeModal('confirmModal')" class="btn btn-ghost" style="flex:1; padding:15px;">انصراف</button>
        </div>
    </div>
</div>

<div id="banModalOptions" class="modal-overlay">
    <div class="modal-content" style="max-height:auto">
        <span class="modal-icon">🚫</span>
        <h3 style="margin-top:0; font-size:1.5rem; color:#ef4444">مسدودسازی کاربر</h3>
        <p style="color:var(--text-muted); font-size:1rem; margin-bottom:20px">کاربر: <b id="banUserName" style="color:#fff"></b></p>
        <input type="hidden" id="banUserId">
        <div style="margin-bottom:25px; text-align:right;">
            <label style="color:#cbd5e1; font-size:0.9rem; margin-bottom:8px; display:block;">مدت زمان مسدودیت:</label>
            <select id="banDuration" class="form-control" style="background:#0f172a">
                <option value="5">۵ دقیقه</option>
                <option value="10">۱۰ دقیقه</option>
                <option value="perm">دائمی (۱ سال)</option>
            </select>
        </div>
        <div style="display:flex; gap:15px;">
            <button onclick="submitBan()" class="btn btn-danger" style="flex:1; padding:15px;">اعمال مسدودیت</button>
            <button onclick="closeModal('banModalOptions')" class="btn btn-ghost" style="flex:1; padding:15px;">لغو</button>
        </div>
    </div>
</div>

<div id="inviteModal" class="modal-overlay">
    <div class="modal-content" style="max-height:auto">
        <span class="modal-icon">📩</span>
        <h3 style="margin-top:0; font-size:1.5rem; color:#fff">دعوت کاربر</h3>
        <p style="color:var(--text-muted); font-size:1rem; margin-bottom:25px">برای دعوت به اتاق خصوصی، نام کاربری را وارد کنید</p>
        <input type="hidden" id="inviteRoomName">
        <input type="text" id="inviteUsername" class="form-control" placeholder="نام کاربری..." style="margin-bottom:25px; text-align:center; font-size:1.2rem; background:#0f172a">
        <div style="display:flex; gap:15px;">
            <button onclick="submitInvite()" class="btn btn-primary" style="flex:1; padding:15px;">ارسال دعوت‌نامه</button>
            <button onclick="closeModal('inviteModal')" class="btn btn-ghost" style="flex:1; padding:15px;">لغو</button>
        </div>
    </div>
</div>

<div id="msgViewModal" class="modal-overlay">
    <div class="modal-content" style="max-width:650px;">
        <h3 style="margin-top:0; margin-bottom:12px; font-size:1.2rem; color:#fff" id="msgViewTitle">پیام‌های کاربر</h3>
        <div id="msgViewTabs" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; padding-bottom:12px; border-bottom:1px solid rgba(255,255,255,0.08);"></div>
        <div class="msg-list" id="msgViewList"></div>
        <button onclick="closeModal('msgViewModal')" class="btn btn-ghost" style="width:100%; padding:15px; margin-top:10px;">بستن</button>
    </div>
</div>

<div class="header">
    <h1>پنل مدیریت پیشرفته</h1>
    <a href="index.php" class="btn btn-ghost btn-header"><span>بازگشت به چت</span> ↩</a>
</div>

<div class="container">
    <div class="stats-grid">
        <div class="stat-card">
            <span class="stat-value" id="statUsers">...</span>
            <span class="stat-label">کاربران ثبت‌نامی</span>
        </div>
        <div class="stat-card">
            <span class="stat-value" id="statMsgs">...</span>
            <span class="stat-label">کل پیام‌های ارسالی</span>
        </div>
        <div class="stat-card">
            <span class="stat-value" id="statRooms">...</span>
            <span class="stat-label">اتاق‌های فعال</span>
        </div>
    </div>

    <?php if ($isMainAdmin): ?>
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">مدیریت دیتابیس (بکاپ و بازگردانی)</h2>
        </div>
        <div class="settings-grid">
            <div class="action-box">
                <h4>تهیه نسخه پشتیبان (Backup)</h4>
                <p style="font-size:0.85rem; color:var(--text-muted); margin-top:0;">دریافت فایل حاوی تمام اطلاعات فعلی سیستم.</p>
                <button onclick="downloadBackup()" class="btn btn-success" style="width:100%">📥 دانلود فایل بکاپ (.sql)</button>
            </div>
            <div class="action-box">
                <h4>بازگردانی دیتابیس (Import)</h4>
                <p style="font-size:0.85rem; color:var(--text-muted); margin-top:0;">انتخاب فایل .sql و جایگزینی کامل اطلاعات.</p>
                <div class="input-group">
                    <input type="file" id="backupFileInput" class="form-control" accept=".sql" style="padding:10px">
                    <button onclick="importBackup()" class="btn btn-danger">آپلود و ایمپورت 📤</button>
                </div>
            </div>
        </div>
    </div>

    <?php endif; // isMainAdmin - backup ?>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">تنظیمات سیستمی و قفل‌ها</h2>
        </div>
        <div class="settings-grid">
            <div class="action-box" style="flex-direction:row; align-items:center; justify-content:space-between; flex-wrap:wrap;">
                <div>
                    <h4 style="margin:0; color:#fff;">قفل فایل (File Upload)</h4>
                    <div id="lockStatusText" style="font-size:0.9rem; color:var(--text-muted); margin-top:5px">در حال بررسی...</div>
                </div>
                <button id="lockBtn" onclick="toggleLock()" class="btn btn-primary">تغییر وضعیت</button>
            </div>
            
            <div class="action-box" style="flex-direction:row; align-items:center; justify-content:space-between; flex-wrap:wrap;">
                <div>
                    <h4 style="margin:0; color:#fff;">قفل ویس (Voice)</h4>
                    <div id="voiceLockStatusText" style="font-size:0.9rem; color:var(--text-muted); margin-top:5px">در حال بررسی...</div>
                </div>
                <button id="voiceLockBtn" onclick="toggleVoiceLock()" class="btn btn-primary">تغییر وضعیت</button>
            </div>

            <div class="action-box" style="flex-direction:row; align-items:center; justify-content:space-between; flex-wrap:wrap;">
                <div>
                    <h4 style="margin:0; color:#fff;">قفل گزارش کاربر (Report)</h4>
                    <div id="reportLockStatusText" style="font-size:0.9rem; color:var(--text-muted); margin-top:5px">در حال بررسی...</div>
                </div>
                <button id="reportLockBtn" onclick="toggleReportLock()" class="btn btn-primary">تغییر وضعیت</button>
            </div>

            <div class="action-box" style="flex-direction:row; align-items:center; justify-content:space-between; flex-wrap:wrap;">
                <div>
                    <h4 style="margin:0; color:#fff;">قفل حذف پیام‌ها و محتوا 🗑️</h4>
                    <div id="deleteLockStatusText" style="font-size:0.9rem; color:var(--text-muted); margin-top:5px">در حال بررسی...</div>
                </div>
                <button id="deleteLockBtn" onclick="toggleDeleteLock()" class="btn btn-primary">تغییر وضعیت</button>
            </div>

            <div class="action-box" style="flex-direction:row; align-items:center; justify-content:space-between; flex-wrap:wrap; border-color:rgba(239, 68, 68, 0.3); grid-column: 1 / -1;">
                <div>
                    <h4 style="margin:0; color:#fff;">وضعیت قفل کل سایت (Maintenance)</h4>
                    <div id="siteLockStatusText" style="font-size:0.9rem; color:var(--text-muted); margin-top:5px">در حال بررسی...</div>
                </div>
                <button id="siteLockBtn" onclick="toggleSiteLock()" class="btn btn-danger">تغییر وضعیت</button>
            </div>

            <div class="action-box" style="grid-column: 1 / -1;">
                <h4 style="margin:0 0 12px; color:#fff;">سقف ساخت حساب (تعداد مجاز کاربران جدید از هر دستگاه)</h4>
                <div style="font-size:0.85rem; color:var(--text-muted); margin-bottom:14px;">وضعیت فعلی: <span id="maxUsersStatusText" style="color:#a5b4fc; font-weight:bold;">در حال بررسی...</span></div>
                <div class="input-group">
                    <input type="number" id="maxUsersInput" class="form-control" placeholder="مثلاً ۵" min="1" max="9999" style="max-width:160px;">
                    <button onclick="setMaxUsers()" class="btn btn-primary">ذخیره</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">زمان‌بندی ریست خودکار</h2>
        </div>
        <div class="action-box">
            <h4>تنظیم زمان پاکسازی کامل سیستم (بر حسب ساعت)</h4>
            <p style="font-size:0.85rem; color:var(--text-muted); margin-top:0;">پس از اتمام زمان، تمام اطلاعات (پیام‌ها، اتاق‌ها، فایل‌ها و کاربران به جز ادمین) حذف خواهند شد.</p>
            <div id="autoResetStatus" style="margin-bottom:10px; font-weight:bold; color:#f59e0b">وضعیت: ...</div>
            <div class="input-group">
                <input type="number" id="autoResetHours" class="form-control" placeholder="مثلا 72 (ساعت)" min="1">
                <button onclick="enableAutoReset()" class="btn btn-success">فعال‌سازی تایمر</button>
                <button onclick="disableAutoReset()" class="btn btn-danger">لغو تایمر</button>
            </div>
            <div style="margin-top:10px; display:flex; align-items:center; gap:10px; color:#cbd5e1;">
                <input type="checkbox" id="autoResetRecurring" style="width:18px; height:18px; cursor:pointer;">
                <label for="autoResetRecurring" style="cursor:pointer; font-size:0.9rem;">تکرار هر ۲۴ ساعت (پس از انجام ریست، تایمر برای فردا تنظیم می‌شود)</label>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">ارسال نوتیفیکیشن سراسری</h2>
        </div>
        <div class="action-box">
            <h4>متن پیام (برای تمام کاربران نمایش داده خواهد شد)</h4>
            <div class="input-group">
                <input type="text" id="globalNotifMsg" class="form-control" placeholder="متن اطلاعیه مهم...">
                <button onclick="sendGlobalNotif()" class="btn btn-primary">ارسال به همه 📢</button>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">مدیریت گزارش‌ها</h2>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>گزارش‌دهنده</th>
                        <th>کاربر متخلف</th>
                        <th>دلیل</th>
                        <th>زمان</th>
                        <th width="150">عملیات</th>
                    </tr>
                </thead>
                <tbody id="reportsList"></tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">مدیریت اتاق‌ها</h2>
        </div>
        
        <div class="action-box">
            <h4>ساخت اتاق جدید</h4>
            <div class="input-group">
                <input type="text" id="roomName" class="form-control" placeholder="نام اتاق...">
                <select id="roomType" class="form-control" style="flex:0.5;">
                    <option value="private">🔒 خصوصی</option>
                    <option value="public">🌐 عمومی</option>
                </select>
                <button onclick="createRoom()" class="btn btn-primary">ساختن ✚</button>
            </div>
        </div>

        <div class="action-box" style="background:rgba(239, 68, 68, 0.05); border-color:rgba(239, 68, 68, 0.2)">
            <h4>حذف گروهی</h4>
            <button onclick="confirmAction('delete_all_custom_rooms', 0, 'آیا مطمئن هستید؟ تمام اتاق‌های عمومی و خصوصی (به جز اتاق اصلی) و تمام پیام‌های آن‌ها حذف خواهند شد.')" class="btn btn-danger" style="width:100%">
                🗑 حذف یکجای تمام اتاق‌های اضافی
            </button>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>نام اتاق</th>
                        <th>نوع</th>
                        <th>سازنده</th>
                        <th width="180">عملیات</th>
                    </tr>
                </thead>
                <tbody id="roomsList"></tbody>
            </table>
        </div>
    </div>

    <div class="card" id="ipBanCard">
        <div class="card-header">
            <h2 class="card-title">مدیریت بن IP</h2>
        </div>
        <div class="action-box">
            <h4>بن کردن آدرس IP</h4>
            <p style="font-size:0.85rem;color:var(--text-muted);margin-top:0;">کاربر با این آدرس IP از هر مرورگری نمی‌تواند وارد شود.</p>
            <div class="input-group">
                <input type="text" id="banIpInput" class="form-control" placeholder="مثلاً 192.168.1.1" dir="ltr">
                <input type="text" id="banIpReason" class="form-control" placeholder="دلیل (اختیاری)">
                <button onclick="banIpAction()" class="btn btn-danger">بن IP 🚫</button>
            </div>
        </div>
        <div class="table-responsive" id="bannedIpsTableWrap">
            <table>
                <thead>
                    <tr>
                        <th>آدرس IP</th>
                        <th>دلیل</th>
                        <th>تاریخ بن</th>
                        <th width="120">عملیات</th>
                    </tr>
                </thead>
                <tbody id="bannedIpsList"></tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">کاربران و اعضا</h2>
            <div style="font-size:0.85rem; color:var(--text-muted); display:flex; align-items:center;">
                <span class="status-dot online" style="margin-left:8px;"></span> وضعیت زنده
            </div>
        </div>
        
        <div class="action-box">
            <h4>جستجوی کاربر</h4>
            <input type="text" id="searchUser" class="form-control" placeholder="نام کاربری را وارد کنید..." onkeyup="filterUsers()">
        </div>

        <div class="action-box" style="flex-direction:row; align-items:center; justify-content:space-between; flex-wrap:wrap; background:rgba(239,68,68,0.05); border-color:rgba(239,68,68,0.25);">
            <div>
                <h4 style="margin:0; color:#fff;">سوزاندن همه سشن‌ها</h4>
                <div style="font-size:0.85rem; color:var(--text-muted); margin-top:4px;">تمام کاربران (بجز ادمین) از حساب خارج و device_token پاک می‌شود</div>
            </div>
            <button onclick="confirmAction('burn_all_sessions', 0, 'سشن تمام کاربران (بجز ادمین) سوزانده می‌شود. ادامه می‌دهید؟')" class="btn btn-danger" style="white-space:nowrap">🔥 سوزاندن همه</button>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>کاربر</th>
                        <th>وضعیت آنلاین</th>
                        <th>IP</th>
                        <th>وضعیت حساب</th>
                        <th width="180">عملیات</th>
                    </tr>
                </thead>
                <tbody id="usersList"></tbody>
            </table>
        </div>
        <div id="paginationControls" style="display:flex; justify-content:center; flex-wrap:wrap; gap:5px; margin-top:20px;"></div>
    </div>

    <div class="card" id="channelsCard">
        <div class="card-header"><h2 class="card-title">مدیریت کانال‌ها</h2></div>
        <div class="action-box">
            <h4>ساخت کانال جدید</h4>
            <div class="input-group">
                <input type="text" id="newChannelName" class="form-control" placeholder="نام کانال (۳-۲۰ حرف)" maxlength="20">
                <select id="newChannelType" class="form-control" style="max-width:140px;">
                    <option value="public">عمومی 📢</option>
                    <option value="private">خصوصی 🔐</option>
                </select>
                <input type="text" id="newChannelCat" class="form-control" placeholder="دسته‌بندی (اختیاری)" style="max-width:150px;">
                <button onclick="createChannel()" class="btn btn-primary">ساخت کانال</button>
            </div>
        </div>
        <div class="table-responsive">
            <table>
                <thead><tr><th>نام</th><th>نوع</th><th>دسته‌بندی</th><th>کد دعوت</th><th>سازنده</th><th width="120">عملیات</th></tr></thead>
                <tbody id="channelsList"></tbody>
            </table>
        </div>
    </div>

    <div class="card" id="adminsCard" style="<?php echo $isMainAdmin ? '' : 'display:none;'; ?>">
        <div class="card-header"><h2 class="card-title">مدیریت ادمین‌ها</h2></div>
        <div class="action-box">
            <h4>افزودن ادمین جدید</h4>
            <p style="font-size:0.85rem;color:var(--text-muted);margin-top:0;">ادمین‌های فرعی می‌توانند به پنل مدیریت دسترسی داشته باشند.</p>
            <div class="input-group">
                <input type="text" id="newAdminUser" class="form-control" placeholder="نام کاربری">
                <input type="password" id="newAdminPass" class="form-control" placeholder="رمز عبور (حداقل ۸ کاراکتر)">
                <input type="text" id="newAdminDisplay" class="form-control" placeholder="نام نمایشی (اختیاری)">
                <button onclick="addAdmin()" class="btn btn-primary">افزودن ادمین</button>
            </div>
        </div>
        <div class="table-responsive">
            <table>
                <thead><tr><th>نام کاربری</th><th>نام نمایشی</th><th>آخرین ورود</th><th>وضعیت</th><th width="140">عملیات</th></tr></thead>
                <tbody id="adminsList"></tbody>
            </table>
        </div>
        <div class="action-box" style="margin-top:16px;background:rgba(99,102,241,0.07);border-color:rgba(99,102,241,0.2);">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <div>
                    <h4 style="margin:0;color:#fff;">مخفی کردن وضعیت آنلاین ادمین</h4>
                    <div style="font-size:0.85rem;color:var(--text-muted);margin-top:4px;">کاربران وضعیت آنلاین/آفلاین ادمین را نمی‌بینند</div>
                </div>
                <button id="hideAdminOnlineBtn" onclick="toggleHideAdminOnline()" class="btn btn-primary" style="white-space:nowrap;">بارگذاری...</button>
            </div>
        </div>
    </div>

    <div class="card" id="updateNotifsCard" style="<?php echo $isMainAdmin ? '' : 'display:none;'; ?>">
        <div class="card-header"><h2 class="card-title">اطلاعیه‌های آپدیت</h2></div>
        <div class="action-box">
            <h4>افزودن اطلاعیه جدید</h4>
            <div class="input-group">
                <input type="text" id="notifTitle" class="form-control" placeholder="عنوان اطلاعیه">
                <input type="text" id="notifIcon" class="form-control" placeholder="آیکون (مثل 🔔)" style="max-width:80px;" value="🔔">
                <select id="notifType" class="form-control" style="max-width:130px;">
                    <option value="info">اطلاعیه 🔵</option>
                    <option value="update">آپدیت 🟣</option>
                    <option value="success">موفقیت 🟢</option>
                    <option value="warning">هشدار 🟡</option>
                </select>
            </div>
            <textarea id="notifMsg" class="form-control" style="width:100%;min-height:80px;resize:vertical;margin-top:8px;" placeholder="متن اطلاعیه..."></textarea>
            <button onclick="addUpdateNotif()" class="btn btn-primary" style="margin-top:10px;width:100%;">➕ افزودن اطلاعیه</button>
        </div>
        <div class="table-responsive">
            <table>
                <thead><tr><th>آیکون</th><th>عنوان</th><th>نوع</th><th>وضعیت</th><th>تاریخ</th><th width="120">عملیات</th></tr></thead>
                <tbody id="updateNotifsList"></tbody>
            </table>
        </div>
    </div>

    <?php if ($isMainAdmin): ?>
    <div class="card danger-zone">
        <div class="card-header">
            <h2 class="card-title danger-card-title">ناحیه خطرناک</h2>
        </div>
        
        <div class="action-box">
            <h4>پاکسازی دیتابیس</h4>
            <button onclick="confirmAction('delete_all_msgs_global', 0, 'هشدار: تمام پیام‌های کل سیستم برای همیشه حذف خواهند شد!')" class="btn btn-danger" style="width:100%">حذف تمام پیام‌های سرور</button>
        </div>

        <div class="action-box">
            <h4>ریست فکتوری (بازگشت به کارخانه)</h4>
            <div class="input-group">
                <input type="password" id="resetPass" class="form-control" placeholder="رمز عبور مدیریت جهت تایید...">
                <button onclick="globalReset()" class="btn btn-danger">انجام ریست کامل</button>
            </div>
        </div>
    </div>
    <?php endif; // isMainAdmin - danger zone ?>
</div>

<div id="sessionsModal" class="modal-overlay">
    <div class="modal-content" style="max-width:700px;">
        <h3 style="margin-top:0;font-size:1.2rem;color:#fff" id="sessionsModalTitle">نشست‌های فعال</h3>
        <div id="sessionsModalBody" style="overflow-y:auto;max-height:60vh;margin-bottom:15px;"></div>
        <button onclick="closeModal('sessionsModal')" class="btn btn-ghost" style="width:100%;padding:15px;">بستن</button>
    </div>
</div>

<script>
const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
const adminUsername = '<?php echo $admin_username; ?>';

let allUsersCache = [];
let currentPage = 1;
let usersPerPage = 10;

function showToast(msg, type='success') {
    const t = document.getElementById('toast');
    t.innerHTML = (type === 'error' ? '✖ ' : '✔ ') + msg;
    t.className = type === 'error' ? 'error show' : 'show';
    setTimeout(() => t.className = '', 3000);
}

function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}

function confirmAction(action, id, text) {
    const m = document.getElementById('confirmModal');
    document.getElementById('confirmText').textContent = text;
    m.style.display = 'flex';
    document.getElementById('confirmYes').onclick = () => {
        closeModal('confirmModal');
        performAction(action, id);
    };
}

function openBanModal(id, name) {
    document.getElementById('banUserId').value = id;
    document.getElementById('banUserName').textContent = name;
    document.getElementById('banModalOptions').style.display = 'flex';
}

function submitBan() {
    const id = document.getElementById('banUserId').value;
    const dur = document.getElementById('banDuration').value;
    const name = document.getElementById('banUserName').textContent;
    
    closeModal('banModalOptions');
    performAction('ban_user', 0, {user_id: id, duration: dur});
}

function openInviteModal(roomName) {
    document.getElementById('inviteRoomName').value = roomName;
    document.getElementById('inviteUsername').value = '';
    document.getElementById('inviteModal').style.display = 'flex';
    document.getElementById('inviteUsername').focus();
}

function performAction(action, id, extraData = {}) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', csrfToken);
    
    if (id) fd.append(action.includes('user') || action === 'get_user_msgs' || action === 'burn_session' || action === 'view_user_msgs' ? 'user_id' : 'id', id);
    for (let k in extraData) fd.append(k, extraData[k]);

    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'success') {
                showToast(d.message);
                refreshData(); 
            } else {
                showToast(d.message, 'error');
            }
        })
        .catch(() => showToast('خطای شبکه', 'error'));
}

function downloadBackup() {
    window.location.href = 'admin.php?backup=1&csrf_token=' + csrfToken;
}

function importBackup() {
    const fileInput = document.getElementById('backupFileInput');
    if(fileInput.files.length === 0) return showToast('لطفاً یک فایل .sql انتخاب کنید', 'error');
    
    confirmAction('import_db', 0, 'هشدار: اطلاعات فعلی دیتابیس به طور کامل با محتویات این فایل جایگزین خواهد شد. ادامه می‌دهید؟');
    document.getElementById('confirmYes').onclick = () => {
        closeModal('confirmModal');
        const fd = new FormData();
        fd.append('action', 'import_db');
        fd.append('csrf_token', csrfToken);
        fd.append('backup_file', fileInput.files[0]);
        
        fetch('admin.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.status === 'success') {
                    showToast(d.message);
                    fileInput.value = '';
                    refreshData(); 
                } else {
                    showToast(d.message, 'error');
                }
            })
            .catch(() => showToast('خطای شبکه', 'error'));
    };
}

function createRoom() {
    const name = document.getElementById('roomName').value;
    const type = document.getElementById('roomType').value;
    if(!name) return showToast('لطفاً نام اتاق را وارد کنید', 'error');
    performAction('create_room', 0, {name: name, type: type});
    document.getElementById('roomName').value = '';
}

function submitInvite() {
    const room = document.getElementById('inviteRoomName').value;
    const user = document.getElementById('inviteUsername').value;
    if(!user) return showToast('لطفاً نام کاربری را وارد کنید', 'error');
    
    closeModal('inviteModal');
    performAction('invite_user', 0, {room_name: room, username: user});
}

function globalReset() {
    const pass = document.getElementById('resetPass').value;
    if(!pass) return showToast('رمز عبور الزامی است', 'error');
    confirmAction('global_reset', 0, 'هشدار نهایی: کل دیتابیس (کاربران، اتاق‌های شخصی، پیام‌ها) پاک می‌شود!');
    document.getElementById('confirmYes').onclick = () => {
        closeModal('confirmModal');
        performAction('global_reset', 0, {password: pass});
    };
}

function enableAutoReset() {
    const h = document.getElementById('autoResetHours').value;
    const recurring = document.getElementById('autoResetRecurring').checked ? 1 : 0;
    if(!h || h < 1) return showToast('لطفاً ساعت را وارد کنید', 'error');
    performAction('enable_auto_reset', 0, {hours: h, recurring: recurring});
}

function disableAutoReset() {
    performAction('disable_auto_reset', 0);
}

function toggleLock() {
    performAction('toggle_upload_lock', 0);
}

function toggleVoiceLock() {
    performAction('toggle_voice_lock', 0);
}

function toggleSiteLock() {
    performAction('toggle_site_lock', 0);
}

function toggleReportLock() {
    performAction('toggle_report_lock', 0);
}

function toggleDeleteLock() {
    performAction('toggle_delete_lock', 0);
}

function sendGlobalNotif() {
    const msg = document.getElementById('globalNotifMsg').value;
    if(!msg) return showToast('متن پیام خالی است', 'error');
    
    confirmAction('send_global_notif', 0, 'آیا از ارسال این پیام به تمام کاربران مطمئن هستید؟');
    document.getElementById('confirmYes').onclick = () => {
        closeModal('confirmModal');
        performAction('send_global_notif', 0, {message: msg});
        document.getElementById('globalNotifMsg').value = '';
    };
}

let _msgGroups = [];

function viewUserMsgs(id, name) {
    const fd = new FormData();
    fd.append('action', 'get_user_msgs');
    fd.append('user_id', id);
    fd.append('csrf_token', csrfToken);
    
    document.getElementById('msgViewList').innerHTML = '<div style="text-align:center;color:#94a3b8;padding:20px">در حال بارگذاری...</div>';
    document.getElementById('msgViewTabs').innerHTML = '';
    document.getElementById('msgViewTitle').textContent = 'پیام‌های ' + name;
    document.getElementById('msgViewModal').style.display = 'flex';
    
    fetch('admin.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(d => {
        if (d.status === 'success') {
            _msgGroups = d.groups || [];
            if (_msgGroups.length === 0) {
                document.getElementById('msgViewTabs').innerHTML = '';
                document.getElementById('msgViewList').innerHTML = '<div style="text-align:center;color:#94a3b8;padding:20px">هیچ پیامی یافت نشد</div>';
                return;
            }
            renderMsgTabs(0);
        } else {
            showToast(d.message, 'error');
        }
    });
}

function renderMsgTabs(activeIdx) {
    const tabsEl = document.getElementById('msgViewTabs');
    let tabHtml = '';
    _msgGroups.forEach((g, i) => {
        const isActive = i === activeIdx;
        tabHtml += `<button onclick="renderMsgTabs(${i})" style="padding:6px 14px; border-radius:10px; border:1px solid ${isActive ? 'rgba(99,102,241,0.7)' : 'rgba(255,255,255,0.12)'}; background:${isActive ? 'rgba(99,102,241,0.25)' : 'rgba(255,255,255,0.05)'}; color:${isActive ? '#a5b4fc' : '#94a3b8'}; cursor:pointer; font-family:inherit; font-size:0.8rem; white-space:nowrap; transition:all 0.2s;">${g.label} <span style="opacity:0.6">(${g.msgs.length})</span></button>`;
    });
    tabsEl.innerHTML = tabHtml;

    const msgs = _msgGroups[activeIdx].msgs;
    let html = '';
    msgs.forEach(m => {
        const typeIcon = m.msg_type === 'text' ? '📝' : (m.msg_type === 'voice' ? '🎤' : '📁');
        const content = m.msg_type === 'text' ? (m.message || '') : (m.msg_type === 'voice' ? 'پیام صوتی' : 'فایل: ' + (m.file_name || ''));
        const isSent = m.sender_name === _msgGroups[activeIdx].label.split(': ')[1] || m.sender_name;
        html += `<div class="admin-msg-item">
            <div class="admin-msg-meta">
                <span style="color:#a5b4fc">${m.sender_name}</span>
                <span>${m.created_at}</span>
            </div>
            <div style="color:#fff; word-break:break-word">${typeIcon} ${content}</div>
        </div>`;
    });
    document.getElementById('msgViewList').innerHTML = html;
}

function refreshData() {
    const fd = new FormData();
    fd.append('action', 'get_live_data');
    fd.append('csrf_token', csrfToken);
    
    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if(d.status === 'success') {
                document.getElementById('statUsers').textContent = d.stats.users;
                document.getElementById('statMsgs').textContent = d.stats.msgs;
                document.getElementById('statRooms').textContent = d.stats.rooms;
                
                const lockBtn = document.getElementById('lockBtn');
                const lockText = document.getElementById('lockStatusText');
                if (d.lock_upload) {
                    lockBtn.textContent = 'باز کردن قفل فایل';
                    lockBtn.className = 'btn btn-success'; 
                    lockBtn.style.background = '#10b981';
                    lockText.innerHTML = '<span style="color:#ef4444">⛔ ارسال فایل قفل است</span>';
                } else {
                    lockBtn.textContent = 'قفل کردن فایل';
                    lockBtn.className = 'btn btn-danger';
                    lockBtn.style.background = ''; 
                    lockText.innerHTML = '<span style="color:#10b981">✔ ارسال فایل آزاد است</span>';
                }

                const voiceLockBtn = document.getElementById('voiceLockBtn');
                const voiceLockText = document.getElementById('voiceLockStatusText');
                if (d.lock_voice) {
                    voiceLockBtn.textContent = 'باز کردن قفل ویس';
                    voiceLockBtn.className = 'btn btn-success'; 
                    voiceLockBtn.style.background = '#10b981';
                    voiceLockText.innerHTML = '<span style="color:#ef4444">⛔ ارسال ویس قفل است</span>';
                } else {
                    voiceLockBtn.textContent = 'قفل کردن ویس';
                    voiceLockBtn.className = 'btn btn-danger';
                    voiceLockBtn.style.background = ''; 
                    voiceLockText.innerHTML = '<span style="color:#10b981">✔ ارسال ویس آزاد است</span>';
                }

                const reportLockBtn = document.getElementById('reportLockBtn');
                const reportLockText = document.getElementById('reportLockStatusText');
                if (d.report_lock) {
                    reportLockBtn.textContent = 'باز کردن قفل گزارش';
                    reportLockBtn.className = 'btn btn-success'; 
                    reportLockBtn.style.background = '#10b981';
                    reportLockText.innerHTML = '<span style="color:#ef4444">⛔ گزارش‌دهی قفل است</span>';
                } else {
                    reportLockBtn.textContent = 'قفل کردن گزارش';
                    reportLockBtn.className = 'btn btn-danger';
                    reportLockBtn.style.background = ''; 
                    reportLockText.innerHTML = '<span style="color:#10b981">✔ گزارش‌دهی فعال است</span>';
                }

                const deleteLockBtn = document.getElementById('deleteLockBtn');
                const deleteLockText = document.getElementById('deleteLockStatusText');
                if (d.delete_lock) {
                    deleteLockBtn.textContent = 'فعال کردن حذف';
                    deleteLockBtn.className = 'btn btn-success';
                    deleteLockBtn.style.background = '#10b981';
                    deleteLockText.innerHTML = '<span style="color:#ef4444">🔒 حذف پیام‌ها و محتوا غیرفعال است</span>';
                } else {
                    deleteLockBtn.textContent = 'غیرفعال کردن حذف';
                    deleteLockBtn.className = 'btn btn-danger';
                    deleteLockBtn.style.background = '';
                    deleteLockText.innerHTML = '<span style="color:#10b981">✔ حذف پیام‌ها و محتوا فعال است</span>';
                }

                const siteLockBtn = document.getElementById('siteLockBtn');
                const siteLockText = document.getElementById('siteLockStatusText');
                if (d.site_lock) {
                    siteLockBtn.textContent = 'باز کردن سایت';
                    siteLockBtn.className = 'btn btn-success';
                    siteLockBtn.style.background = '#10b981';
                    siteLockText.innerHTML = '<span style="color:#ef4444">⛔ سایت قفل و از دسترس خارج است</span>';
                } else {
                    siteLockBtn.textContent = 'قفل کردن سایت';
                    siteLockBtn.className = 'btn btn-danger';
                    siteLockBtn.style.background = '';
                    siteLockText.innerHTML = '<span style="color:#10b981">✔ سایت در دسترس است</span>';
                }

                document.getElementById('autoResetStatus').textContent = 'وضعیت: ' + d.auto_reset_text;
                if(d.auto_reset_recurring) {
                    document.getElementById('autoResetRecurring').checked = true;
                }

                if (d.max_users !== undefined) {
                    document.getElementById('maxUsersStatusText').textContent = d.max_users + ' حساب از هر دستگاه';
                    const inp = document.getElementById('maxUsersInput');
                    if (inp && !inp.value) inp.placeholder = d.max_users;
                }

                updateRooms(d.rooms);
                allUsersCache = d.users;
                renderUsers();
                updateReports(d.reports);
                updateBannedIps(d.banned_ips || []);
                updateChannelsList(d.rooms || []);
                loadAdminsList();
                loadUpdateNotifs();
                loadHideAdminOnlineState();
            }
        });
}

function setMaxUsers() {
    const val = parseInt(document.getElementById('maxUsersInput').value);
    if (!val || val < 1) return showToast('یک عدد معتبر وارد کنید', 'error');
    confirmAction('set_max_users', 0, `سقف ساخت حساب به ${val} تغییر می‌یابد. ادامه می‌دهید؟`);
    document.getElementById('confirmYes').onclick = () => {
        closeModal('confirmModal');
        performAction('set_max_users', 0, { max_users: val });
        document.getElementById('maxUsersInput').value = '';
    };
}

function updateRooms(rooms) {
    const tbody = document.getElementById('roomsList');
    let html = '';
    rooms.forEach(r => {
        let btn = '';
        if(r.is_default) {
            btn = '<span class="badge badge-secondary">اصلی</span>';
        } else {
            let invite = r.type === 'private' ? `<button onclick="openInviteModal('${r.name}')" class="btn btn-primary btn-sm" style="margin-left:5px">دعوت</button>` : '';
            btn = `${invite}<button onclick="confirmAction('delete_room', ${r.id}, 'آیا از حذف اتاق ${r.name} مطمئن هستید؟')" class="btn btn-danger btn-sm">حذف</button>`;
        }
        
        let creatorInfo = r.creator;
        if(r.creator !== 'سیستم') {
            creatorInfo = `<span style="color:#a5b4fc">${r.creator}</span>`;
        }
        
        html += `<tr id="room-${r.id}">
            <td><b>${r.name}</b></td>
            <td>${r.type_html}</td>
            <td>${creatorInfo}</td>
            <td><div style="display:flex; justify-content:flex-end;">${btn}</div></td>
        </tr>`;
    });
    if(tbody.innerHTML !== html) tbody.innerHTML = html;
}

function updateReports(reports) {
    const tbody = document.getElementById('reportsList');
    if (!reports || reports.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:#94a3b8">هیچ گزارشی ثبت نشده است</td></tr>';
        return;
    }
    let html = '';
    reports.forEach(r => {
        const statusBadge = r.status === 'approved' ? '<span style="color:#10b981;font-size:11px;">✔ تأیید شده</span>' : r.status === 'rejected' ? '<span style="color:#ef4444;font-size:11px;">✘ رد شده</span>' : '<span style="color:#fbbf24;font-size:11px;">⏳ در انتظار</span>';
        const actionBtns = r.status === 'pending' ? `
            <button onclick="performAction('approve_report', ${r.id}, null, 'تأیید گزارش و اقدام')" class="btn btn-sm" style="background:rgba(16,185,129,0.2);border-color:rgba(16,185,129,0.4);color:#6ee7b7">✔ تأیید</button>
            <button onclick="approveReportAndBan(${r.id})" class="btn btn-sm" style="background:rgba(239,68,68,0.2);border-color:rgba(239,68,68,0.4);color:#fca5a5">🚫 تأیید+بن</button>
            <button onclick="performAction('reject_report', ${r.id})" class="btn btn-sm" style="background:rgba(239,68,68,0.1);border-color:rgba(239,68,68,0.3);color:#fca5a5">✘ رد</button>
            <button onclick="openBanModal(${r.reported_id}, '${r.reported}')" class="btn btn-danger btn-sm">🚫 بن</button>
        ` : `<button onclick="confirmAction('delete_report', ${r.id}, 'حذف گزارش؟')" class="btn btn-ghost btn-sm" style="color:#ef4444;border-color:#ef4444">🗑</button>`;
        html += `<tr id="report-${r.id}">
            <td style="color:#a5b4fc">${r.reporter}</td>
            <td style="color:#f87171; font-weight:bold">${r.reported}</td>
            <td><div style="max-width:180px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis" title="${r.reason}">${r.reason}</div></td>
            <td style="font-size:0.8rem; color:#94a3b8">${r.time_ago}<br>${statusBadge}</td>
            <td>
                <div style="display:flex; justify-content:flex-end; gap:5px; flex-wrap:wrap">
                    ${actionBtns}
                </div>
            </td>
        </tr>`;
    });
    if(tbody.innerHTML !== html) tbody.innerHTML = html;
}

function renderUsers() {
    const tbody = document.getElementById('usersList');
    const q = document.getElementById('searchUser').value.toLowerCase();
    
    let filtered = allUsersCache.filter(u => u.username.toLowerCase().includes(q));
    
    const totalPages = Math.ceil(filtered.length / usersPerPage) || 1;
    if (currentPage > totalPages) currentPage = totalPages;
    
    const start = (currentPage - 1) * usersPerPage;
    const paginated = filtered.slice(start, start + usersPerPage);
    
    let html = '';
    paginated.forEach(u => {
        let actions = '';
        if (!u.is_admin) {
            let banBtn = u.status_html.includes('مسدود') 
                ? `<button onclick="confirmAction('unban_user', ${u.id}, 'آیا از رفع مسدودیت کاربر ${u.username} اطمینان دارید؟')" class="btn btn-primary btn-sm" style="margin-left:5px">رفع بن</button>`
                : `<button onclick="openBanModal(${u.id}, '${u.username}')" class="btn btn-danger btn-sm" style="margin-left:5px">مسدود</button>`;
            
            actions = `${banBtn}
            <button onclick="viewUserSessions(${u.id}, '${u.username}')" class="btn btn-primary btn-sm" style="margin-left:5px; background:linear-gradient(135deg,#0ea5e9,#38bdf8);" title="نشست‌های فعال">📡</button>
            <button onclick="confirmAction('burn_session', ${u.id}, 'سشن کاربر ${u.username} سوزانده می‌شود و از حساب خارج می‌شود. ادامه می‌دهید؟')" class="btn btn-danger btn-sm" style="margin-left:5px; background:linear-gradient(135deg,#b45309,#f59e0b); border:none;" title="سوزاندن همه سشن‌ها">🔥</button>
            <button onclick="viewUserMsgs(${u.id}, '${u.username}')" class="btn btn-primary btn-sm" style="margin-left:5px; background:linear-gradient(135deg, #3b82f6, #60a5fa)">👁</button>
            <button onclick="confirmAction('delete_user_msgs', ${u.id}, 'تمام پیام‌های کاربر ${u.username} حذف شود؟')" class="btn btn-ghost btn-sm" style="color:#ef4444; border-color:#ef4444">پاکسازی</button>`;
        }

        const onlineBadge = u.is_online_bool 
            ? '<span class="status-dot online"></span> آنلاین' 
            : '<span class="status-dot offline"></span> آفلاین';

        html += `<tr id="user-${u.id}">
            <td>
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="font-size:1.5em; background:rgba(255,255,255,0.1); width:40px; height:40px; display:flex; justify-content:center; align-items:center; border-radius:12px;">${u.sticker}</span>
                    <div style="display:flex; flex-direction:column; align-items:flex-start">
                        <span>${u.username}</span>
                        ${u.is_admin ? '<span style="font-size:0.7em; opacity:0.7; color:#818cf8">مدیر کل</span>' : ''}
                    </div>
                </div>
            </td>
            <td>${onlineBadge} <div style="font-size:0.75em; opacity:0.5; margin-top:4px">${u.time_ago}</div></td>
            <td class="mono" style="direction:ltr">${u.ip_address || '-'}</td>
            <td>${u.status_html}</td>
            <td><div style="display:flex; justify-content:flex-end;">${actions}</div></td>
        </tr>`;
    });
    
    tbody.innerHTML = html;
    
    let pagHtml = '';
    for(let i=1; i<=totalPages; i++) {
        pagHtml += `<button onclick="changePage(${i})" class="btn ${i === currentPage ? 'btn-primary' : 'btn-ghost'} btn-sm" style="margin:2px; min-width:35px">${i}</button>`;
    }
    document.getElementById('paginationControls').innerHTML = pagHtml;
}

function changePage(p) {
    currentPage = p;
    renderUsers();
}

function filterUsers() {
    currentPage = 1; 
    renderUsers();
}

setInterval(refreshData, 3000);
refreshData();

function banIpAction() {
    const ip = document.getElementById('banIpInput').value.trim();
    const reason = document.getElementById('banIpReason').value.trim() || 'بن توسط مدیر';
    if (!ip) return showToast('آدرس IP را وارد کنید', 'error');
    confirmAction('ban_ip', 0, `آیا از مسدود کردن IP ${ip} اطمئن هستید؟`);
    document.getElementById('confirmYes').onclick = () => {
        closeModal('confirmModal');
        const fd = new FormData();
        fd.append('action', 'ban_ip');
        fd.append('csrf_token', csrfToken);
        fd.append('ip_address', ip);
        fd.append('reason', reason);
        fetch('admin.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.status === 'success') {
                    showToast(d.message);
                    document.getElementById('banIpInput').value = '';
                    document.getElementById('banIpReason').value = '';
                    refreshData();
                } else {
                    showToast(d.message, 'error');
                }
            });
    };
}

function unbanIpAction(ip) {
    confirmAction('unban_ip', 0, `رفع مسدودیت IP ${ip}؟`);
    document.getElementById('confirmYes').onclick = () => {
        closeModal('confirmModal');
        const fd = new FormData();
        fd.append('action', 'unban_ip');
        fd.append('csrf_token', csrfToken);
        fd.append('ip_address', ip);
        fetch('admin.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.status === 'success') { showToast(d.message); refreshData(); }
                else showToast(d.message, 'error');
            });
    };
}

function updateBannedIps(ips) {
    const tbody = document.getElementById('bannedIpsList');
    if (!ips || ips.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#94a3b8">هیچ IP مسدودی وجود ندارد</td></tr>';
        return;
    }
    let html = '';
    ips.forEach(ip => {
        html += `<tr>
            <td style="direction:ltr;font-family:monospace;color:#f87171">${ip.ip_address}</td>
            <td>${ip.reason || '-'}</td>
            <td style="font-size:0.8rem;color:#94a3b8">${ip.banned_at || '-'}</td>
            <td><button onclick="unbanIpAction('${ip.ip_address}')" class="btn btn-success btn-sm">رفع بن</button></td>
        </tr>`;
    });
    if (tbody.innerHTML !== html) tbody.innerHTML = html;
}

function viewUserSessions(userId, username) {
    document.getElementById('sessionsModalTitle').textContent = 'نشست‌های فعال: ' + username;
    document.getElementById('sessionsModalBody').innerHTML = '<div style="text-align:center;color:#94a3b8;padding:20px">در حال بارگذاری...</div>';
    document.getElementById('sessionsModal').style.display = 'flex';
    
    const fd = new FormData();
    fd.append('action', 'get_user_sessions');
    fd.append('user_id', userId);
    fd.append('csrf_token', csrfToken);
    
    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status !== 'success') { showToast(d.message, 'error'); return; }
            const sessions = d.sessions || [];
            if (sessions.length === 0) {
                document.getElementById('sessionsModalBody').innerHTML = '<div style="text-align:center;color:#94a3b8;padding:20px">هیچ نشست فعالی یافت نشد</div>';
                return;
            }
            let html = `<div style="margin-bottom:10px;display:flex;justify-content:flex-end;">
                <button onclick="killAllSessions(${userId},'${username}')" class="btn btn-danger btn-sm">🔥 حذف همه نشست‌ها</button>
            </div>`;
            sessions.forEach(s => {
                const isActive = s.is_recent;
                const statusDot = isActive ? '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#10b981;margin-left:6px;box-shadow:0 0 6px #10b981"></span>' : '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#6b7280;margin-left:6px;"></span>';
                html += `<div style="background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:14px;margin-bottom:8px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                        <div>
                            ${statusDot}<span style="color:${isActive ? '#34d399' : '#94a3b8'};font-size:0.85rem;">${isActive ? 'فعال' : 'غیرفعال'}</span>
                            <div style="font-family:monospace;font-size:0.8rem;color:#818cf8;margin-top:4px;direction:ltr">${s.ip_address || '-'}</div>
                            <div style="font-size:0.75rem;color:#64748b;margin-top:2px;">آخرین فعالیت: ${s.last_activity}</div>
                            <div style="font-size:0.7rem;color:#475569;margin-top:2px;direction:ltr;word-break:break-all;">${s.user_agent ? s.user_agent.substring(0,80)+'...' : '-'}</div>
                        </div>
                        <button onclick="killSession(${s.id},'${username}')" class="btn btn-danger btn-sm" style="flex-shrink:0">حذف نشست</button>
                    </div>
                </div>`;
            });
            document.getElementById('sessionsModalBody').innerHTML = html;
        })
        .catch(() => showToast('خطای شبکه', 'error'));
}

function killSession(sessionId, username) {
    if (!confirm(`نشست حذف شود و کاربر ${username} از حساب خارج می‌شود؟`)) return;
    const fd = new FormData();
    fd.append('action', 'kill_session');
    fd.append('session_id', sessionId);
    fd.append('csrf_token', csrfToken);
    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'success') {
                showToast(d.message);
                closeModal('sessionsModal');
                refreshData();
            } else showToast(d.message, 'error');
        });
}

function killAllSessions(userId, username) {
    if (!confirm(`تمام نشست‌های ${username} حذف شود؟`)) return;
    const fd = new FormData();
    fd.append('action', 'kill_all_sessions_user');
    fd.append('user_id', userId);
    fd.append('csrf_token', csrfToken);
    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'success') {
                showToast(d.message);
                closeModal('sessionsModal');
                refreshData();
            } else showToast(d.message, 'error');
        });
}

function updateChannelsList(rooms) {
    const tbody = document.getElementById('channelsList');
    if (!tbody) return;
    const channels = rooms.filter(r => r.room_kind === 'channel');
    if (!channels.length) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#64748b;padding:20px;">کانالی وجود ندارد</td></tr>';
        return;
    }
    tbody.innerHTML = channels.map(r => {
        const typeLabel = r.type === 'public' ? '<span style="background:rgba(16,185,129,0.15);color:#34d399;padding:2px 10px;border-radius:8px;font-size:12px;">عمومی 📢</span>' : '<span style="background:rgba(99,102,241,0.15);color:#a5b4fc;padding:2px 10px;border-radius:8px;font-size:12px;">خصوصی 🔐</span>';
        const invCode = r.invite_code ? `<span style="font-family:monospace;font-size:12px;color:#818cf8;background:rgba(99,102,241,0.1);padding:2px 8px;border-radius:6px;cursor:pointer;" onclick="navigator.clipboard.writeText('${r.invite_code}').then(()=>showToast('کپی شد'))">${r.invite_code}</span>` : '—';
        return `<tr>
            <td style="font-weight:bold;">📢 ${escapeAdminHtml(r.name)}</td>
            <td>${typeLabel}</td>
            <td>${r.category ? escapeAdminHtml(r.category) : '—'}</td>
            <td>${invCode}</td>
            <td>${escapeAdminHtml(r.creator || 'سیستم')}</td>
            <td><button onclick="deleteChannelAdmin(${r.id},'${escapeAdminHtml(r.name)}')" class="btn btn-danger btn-sm">🗑 حذف</button></td>
        </tr>`;
    }).join('');
}

function createChannel() {
    const name = document.getElementById('newChannelName').value.trim();
    const type = document.getElementById('newChannelType').value;
    const cat  = document.getElementById('newChannelCat').value.trim();
    if (!name) return showToast('نام کانال را وارد کنید', 'error');
    performActionCustom('create_channel', { name, type, category: cat }, d => {
        showToast(d.message);
        document.getElementById('newChannelName').value = '';
        document.getElementById('newChannelCat').value = '';
        refreshData();
    });
}

function deleteChannelAdmin(id, name) {
    if (!confirm(`کانال "${name}" حذف شود؟`)) return;
    performAction('delete_channel', id);
    setTimeout(refreshData, 700);
}

function loadAdminsList() {
    const tbody = document.getElementById('adminsList');
    if (!tbody) return;
    const fd = new FormData();
    fd.append('action', 'list_admins');
    fd.append('csrf_token', csrfToken);
    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status !== 'success') return;
            if (!d.admins.length) {
                tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#64748b;padding:20px;">هیچ ادمین فرعی ثبت نشده</td></tr>';
                return;
            }
            tbody.innerHTML = d.admins.map(a => {
                const activeLabel = a.is_active == 1 ? '<span style="color:#34d399;font-size:12px;">✔ فعال</span>' : '<span style="color:#ef4444;font-size:12px;">✕ غیرفعال</span>';
                const lastLogin = a.last_login ? a.last_login.substr(0, 16) : 'هرگز';
                return `<tr>
                    <td style="font-weight:bold;font-family:monospace;">${escapeAdminHtml(a.username)}</td>
                    <td>${escapeAdminHtml(a.display_name || '—')}</td>
                    <td style="font-size:12px;color:#64748b;">${lastLogin}</td>
                    <td>${activeLabel}</td>
                    <td style="display:flex;gap:6px;">
                        <button onclick="toggleAdminActive(${a.id})" class="btn btn-sm" style="background:rgba(99,102,241,0.2);color:#a5b4fc;border:1px solid rgba(99,102,241,0.3);">${a.is_active ? 'غیرفعال' : 'فعال'}</button>
                        <button onclick="removeAdmin(${a.id},'${escapeAdminHtml(a.username)}')" class="btn btn-danger btn-sm">🗑</button>
                    </td>
                </tr>`;
            }).join('');
        });
}

function addAdmin() {
    const uname = document.getElementById('newAdminUser').value.trim();
    const pass  = document.getElementById('newAdminPass').value;
    const dname = document.getElementById('newAdminDisplay').value.trim();
    if (!uname || !pass) return showToast('نام کاربری و رمز عبور الزامی است', 'error');
    if (pass.length < 8) return showToast('رمز عبور باید حداقل ۸ کاراکتر باشد', 'error');
    performActionCustom('add_admin', { username: uname, password: pass, display_name: dname }, d => {
        showToast(d.message);
        document.getElementById('newAdminUser').value = '';
        document.getElementById('newAdminPass').value = '';
        document.getElementById('newAdminDisplay').value = '';
        loadAdminsList();
    });
}

function removeAdmin(id, name) {
    if (!confirm(`ادمین "${name}" حذف شود؟`)) return;
    performActionCustom('remove_admin', { admin_id: id }, d => { showToast(d.message); loadAdminsList(); });
}

function toggleAdminActive(id) {
    performActionCustom('toggle_admin_active', { admin_id: id }, d => { showToast(d.message); loadAdminsList(); });
}

let _hideAdminOnlineState = false;
function loadHideAdminOnlineState() {
    const btn = document.getElementById('hideAdminOnlineBtn');
    if (!btn) return;
    // use performActionCustom to get current state
    const fd = new FormData();
    fd.append('action', 'get_hide_admin_state');
    fd.append('csrf_token', csrfToken);
    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'success') {
                _hideAdminOnlineState = d.state == 1;
                btn.textContent = _hideAdminOnlineState ? '✔ آنلاین مخفی است — کلیک برای نمایش' : '👁 نمایش آنلاین — کلیک برای مخفی کردن';
                btn.className = _hideAdminOnlineState ? 'btn btn-success' : 'btn btn-primary';
                btn.disabled = false;
            }
        })
        .catch(() => {
            btn.textContent = '👁 وضعیت آنلاین ادمین';
            btn.disabled = false;
        });
}

function toggleHideAdminOnline() {
    performActionCustom('toggle_hide_admin_online', {}, d => {
        showToast(d.message);
        _hideAdminOnlineState = (d.state == 1);
        const btn = document.getElementById('hideAdminOnlineBtn');
        if (btn) {
            btn.textContent = _hideAdminOnlineState ? '✔ آنلاین مخفی است — کلیک برای نمایش' : '👁 نمایش آنلاین — کلیک برای مخفی کردن';
            btn.className = _hideAdminOnlineState ? 'btn btn-success' : 'btn btn-primary';
        }
    });
}

function loadUpdateNotifs() {
    const tbody = document.getElementById('updateNotifsList');
    if (!tbody) return;
    const fd = new FormData();
    fd.append('action', 'list_update_notifs');
    fd.append('csrf_token', csrfToken);
    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status !== 'success') return;
            if (!d.notifications.length) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#64748b;padding:20px;">هیچ اطلاعیه‌ای ثبت نشده</td></tr>';
                return;
            }
            const typeColors = { info: '#6366f1', update: '#8b5cf6', success: '#10b981', warning: '#f59e0b' };
            tbody.innerHTML = d.notifications.map(n => {
                const col = typeColors[n.type] || '#6366f1';
                const activeLabel = n.is_active == 1 ? '<span style="color:#34d399;font-size:12px;">✔ فعال</span>' : '<span style="color:#ef4444;font-size:12px;">✕ غیرفعال</span>';
                return `<tr>
                    <td style="font-size:22px;text-align:center;">${n.icon}</td>
                    <td style="font-weight:bold;">${escapeAdminHtml(n.title)}</td>
                    <td><span style="background:${col}22;color:${col};padding:2px 10px;border-radius:8px;font-size:12px;">${n.type}</span></td>
                    <td>${activeLabel}</td>
                    <td style="font-size:12px;color:#64748b;">${(n.created_at || '').substr(0,10)}</td>
                    <td style="display:flex;gap:4px;">
                        <button onclick="toggleUpdateNotif(${n.id})" class="btn btn-sm" style="background:rgba(99,102,241,0.15);color:#a5b4fc;border:1px solid rgba(99,102,241,0.3);">${n.is_active ? 'غیرفعال' : 'فعال'}</button>
                        <button onclick="deleteUpdateNotif(${n.id})" class="btn btn-danger btn-sm">🗑</button>
                    </td>
                </tr>`;
            }).join('');
        });
}

function addUpdateNotif() {
    const title = document.getElementById('notifTitle').value.trim();
    const msg   = document.getElementById('notifMsg').value.trim();
    const icon  = document.getElementById('notifIcon').value.trim() || '🔔';
    const type  = document.getElementById('notifType').value;
    if (!title || !msg) return showToast('عنوان و متن الزامی است', 'error');
    performActionCustom('add_update_notif', { title, message: msg, icon, type }, d => {
        showToast(d.message);
        document.getElementById('notifTitle').value = '';
        document.getElementById('notifMsg').value = '';
        document.getElementById('notifIcon').value = '🔔';
        loadUpdateNotifs();
    });
}

function toggleUpdateNotif(id) {
    performActionCustom('toggle_update_notif', { id }, d => { showToast(d.message); loadUpdateNotifs(); });
}

function deleteUpdateNotif(id) {
    if (!confirm('این اطلاعیه حذف شود؟')) return;
    performActionCustom('delete_update_notif', { id }, d => { showToast(d.message); loadUpdateNotifs(); });
}

function approveReportAndBan(id) {
    if (!confirm('گزارش تأیید شده و کاربر بن شود؟')) return;
    performActionCustom('approve_report_and_ban', { id }, d => { showToast(d.message); refreshData(); });
}

function performActionCustom(action, data, successCb) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', csrfToken);
    for (const k in data) fd.append(k, data[k]);
    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'success') { if (successCb) successCb(d); }
            else showToast(d.message || 'خطا', 'error');
        })
        .catch(() => showToast('خطا در ارتباط', 'error'));
}

function escapeAdminHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>'"]/g, t => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[t]));
}

</script>
</body>
</html>
<?php
function time_elapsed_string($datetime) {
    if (empty($datetime) || $datetime == '0000-00-00 00:00:00') return 'نامشخص';
    try {
        $ts = strtotime($datetime);
        $diff = time() - $ts;
    } catch (Exception $e) { return '-'; }

    if ($diff < 60) return 'همین الان';
    if ($diff < 3600) return floor($diff / 60) . ' دقیقه پیش';
    if ($diff < 86400) return floor($diff / 3600) . ' ساعت پیش';
    return floor($diff / 86400) . ' روز پیش';
}
?>