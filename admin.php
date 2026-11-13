<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: admin-login.php');
    exit;
}

$sessionUser = $_SESSION['username'];
$isMainAdmin = ($sessionUser === $admin_username);
$isSubAdmin  = (!$isMainAdmin && is_admin_user($sessionUser, $pdo));

// ── Access levels ───────────────────────────────────────────────────────────
//  owner   — the account from data/config.php. Everything, including creating
//            and managing other admins.
//  full    — a sub-admin that runs the panel: settings, backups, AI, rooms.
//            Everything except admin management (that stays with the owner).
//  limited — moderation only: users, sessions, reports, media and bans. Every
//            settings/system endpoint is refused below.
$adminLevel = 'owner';
if (!$isMainAdmin) {
    $adminLevel = 'limited';
    if ($isSubAdmin) {
        try {
            $stLvl = $pdo->prepare("SELECT level FROM admin_users WHERE username = ? AND is_active = 1 LIMIT 1");
            $stLvl->execute([$sessionUser]);
            if ((string)$stLvl->fetchColumn() === 'full') $adminLevel = 'full';
        } catch (Exception $e) {
            $adminLevel = 'limited';
        }
    }
}
$isFullAdmin = ($adminLevel === 'owner' || $adminLevel === 'full');
$isLimitedAdmin = ($adminLevel === 'limited');
list($adminUiColor, $adminUiScale) = veyrax_user_ui_prefs($pdo, (int)$_SESSION['user_id']);

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
        <title>دسترسی محدود | <?php echo $site_brand_fa; ?></title>
        <?php veyrax_icon_links(); ?>
    <?php veyrax_ui_kit(); ?>
    <script src="assets/js/vx-cache.js?v=<?php echo VEYRAX_ASSET_VERSION; ?>" defer></script>
        <style>
            @font-face { font-family: 'AppFont'; src: url('fonts/<?php echo $font_text; ?>.ttf') format('truetype'); font-weight: normal; }
            @font-face { font-family: 'AppBold'; src: url('fonts/<?php echo $font_heading; ?>.ttf') format('truetype'); font-weight: bold; }
            * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
            body { margin:0; min-height:100vh; min-height:100dvh; display:flex; justify-content:center; align-items:center; background:#0f172a; background-image: radial-gradient(circle at top right, #1e293b, #0f172a); color:#fff; font-family:'AppFont','Segoe UI Emoji',sans-serif; padding:20px; }
            .gate-card { text-align:center; padding:48px 36px; background:rgba(30,41,59,0.85); backdrop-filter:blur(20px); border-radius:28px; border:1px solid rgba(255,255,255,0.1); max-width:92%; width:420px; box-shadow:0 25px 60px rgba(0,0,0,0.6); animation:gateIn 0.4s cubic-bezier(0.2,0.8,0.2,1); }
            @keyframes gateIn { from { opacity:0; transform:translateY(18px) scale(0.96); } to { opacity:1; transform:none; } }
            .gate-icon { width:84px; height:84px; margin:0 auto 22px; border-radius:26px; display:flex; align-items:center; justify-content:center; font-size:40px; background:linear-gradient(135deg, rgba(245,158,11,0.2), rgba(245,158,11,0.08)); border:1px solid rgba(245,158,11,0.35); }
            .gate-title { font-family:'AppBold'; font-size:24px; margin:0 0 10px; color:#fbbf24; }
            .gate-text { color:#94a3b8; margin:0 0 28px; font-size:14px; line-height:1.9; }
            .gate-btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; background:linear-gradient(135deg, #6366f1, #4f46e5); color:#fff; text-decoration:none; padding:14px 34px; border-radius:14px; font-family:'AppBold'; font-size:15px; border:none; cursor:pointer; transition:transform 0.2s, box-shadow 0.2s; box-shadow:0 8px 25px -5px rgba(99,102,241,0.4); }
            .gate-btn:hover { transform:translateY(-2px); box-shadow:0 12px 30px -5px rgba(99,102,241,0.5); }
            .gate-brand { margin-top:24px; font-size:11px; color:#475569; letter-spacing:1px; font-family:'AppBold'; }
            @media (max-width:480px) { .gate-card { padding:38px 24px; border-radius:24px; } .gate-icon { width:70px; height:70px; font-size:34px; } }
        </style>
    <style id="vFontFaces"><?php echo veyrax_font_css($pdo); ?></style>
</head>
    <body>
        <div class="gate-card">
            <div class="gate-icon">🔐</div>
            <h1 class="gate-title">دسترسی محدود</h1>
            <p class="gate-text">فقط مدیران به این بخش دسترسی دارند.<br>اگر مدیر سیستم هستید، ابتدا وارد حساب مدیریتی شوید.</p>
            <a href="index.php" class="gate-btn">🏠 بازگشت به چت</a>
            <div class="gate-brand"><?php echo $site_brand_en; ?></div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

if (isset($_GET['backup']) && $isMainAdmin && isset($_GET['csrf_token']) && $_GET['csrf_token'] === $_SESSION['csrf_token']) {
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="veyrax_backup_' . date('Y-m-d_H-i-s') . '.sql"');
    
    $tables = ['users', 'rooms', 'room_invites', 'messages', 'settings', 'system_settings', 'user_reports', 'banned_ips', 'global_alerts'];
    $out = "-- VIRAX Chat Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    
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

/**
 * "Delete every message on the server".
 *
 * The old one-liner was `TRUNCATE TABLE messages` and it could never work:
 *   • with a table prefix the unprefixed `messages` is a VIEW, and MySQL
 *     refuses TRUNCATE on a view ("is not BASE TABLE");
 *   • when that view was missing the whole call died with
 *     "Table 'db.messages' doesn't exist" — the error seen in production.
 *
 * Now the real table is resolved first (prefixed base table, else the plain
 * one), rows are DELETEd inside a transaction, pinned rows / reactions /
 * notification cursors are cleared too, and the media blobs that belonged to
 * those rows are removed from uploads/ with the same containment check used
 * everywhere else. Returns a small report for the toast.
 */
function veyrax_admin_purge_messages(PDO $pdo) {
    $report = ['messages' => 0, 'files' => 0, 'pins' => 0, 'reactions' => 0];

    $prefix = defined('VEYRAX_DB_PREFIX') ? preg_replace('/[^A-Za-z0-9_]/', '', (string)VEYRAX_DB_PREFIX) : '';
    $candidates = [];
    if ($prefix !== '') $candidates[] = $prefix . 'messages';
    $candidates[] = 'messages';

    $msgTbl = null;
    foreach ($candidates as $cand) {
        try {
            $pdo->query("SELECT 1 FROM `$cand` LIMIT 0");
            $msgTbl = $cand;
            break;
        } catch (Exception $e) {}
    }
    if ($msgTbl === null) {
        throw new Exception('جدول پیام‌ها در دیتابیس پیدا نشد. یک‌بار صفحه را رفرش کنید تا نماهای سازگاری ساخته شوند.');
    }

    // Collect the blobs before the rows disappear.
    $paths = [];
    try {
        $st = $pdo->query("SELECT file_path FROM `$msgTbl` WHERE file_path IS NOT NULL AND file_path <> ''");
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $p) $paths[] = (string)$p;
    } catch (Exception $e) {}

    $pdo->beginTransaction();
    $report['messages'] = (int)$pdo->exec("DELETE FROM `$msgTbl`");

    foreach (['pinned_messages', 'message_reactions'] as $side) {
        foreach (array_filter([$prefix !== '' ? $prefix . $side : null, $side]) as $tblName) {
            try {
                $n = (int)$pdo->exec("DELETE FROM `$tblName`");
                $report[$side === 'pinned_messages' ? 'pins' : 'reactions'] = $n;
                break;
            } catch (Exception $e) {}
        }
    }

    // Nobody should get a notification burst for messages that no longer exist.
    try { $pdo->exec("UPDATE users SET last_notif_id = 0"); } catch (Exception $e) {}
    $pdo->commit();

    // Orphan tombstones would keep telling clients to hide ids that are gone.
    $tomb = __DIR__ . '/data/deleted_msgs.json';
    if (is_file($tomb)) @file_put_contents($tomb, '[]', LOCK_EX);

    $upBase = realpath(__DIR__ . '/uploads');
    if ($upBase) {
        foreach (array_unique($paths) as $rel) {
            $full = realpath(__DIR__ . '/' . ltrim($rel, '/\\'));
            if (!$full) continue;
            if (strpos($full, $upBase . DIRECTORY_SEPARATOR) !== 0) continue;
            if (!is_file($full) || basename($full) === '.htaccess') continue;
            if (@unlink($full)) $report['files']++;
        }
    }

    try { ws_push_async('force_reload', ['reason' => 'messages_purged', 'force' => true]); } catch (Exception $e) {}

    return $report;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!isset($_POST['action']) || !isset($_POST['csrf_token']) || !is_string($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        echo json_encode(['status' => 'error', 'message' => 'نشست نامعتبر']);
        exit;
    }

    $action = $_POST['action'];

    // ── Limited admins: moderation whitelist ────────────────────────────────
    // Anything not listed here is a settings/system endpoint and stays with a
    // full-level admin. Keeping this as an allow-list (instead of a deny-list)
    // means a newly added endpoint is never accidentally opened to limited
    // admins.
    if ($isLimitedAdmin) {
        $limitedAllowed = [
            // users & sessions
            'get_users', 'get_user_sessions', 'get_user_msgs', 'view_user_msgs',
            'delete_user_msgs', 'ban_user', 'unban_user', 'ban_ip', 'unban_ip',
            'kill_session', 'kill_all_sessions_user', 'burn_session', 'burn_all_sessions',
            'clear_login_attempts', 'get_login_attempts',
            // reports
            'approve_report', 'reject_report', 'delete_report', 'approve_report_and_ban',
            // media
            'list_media', 'delete_media', 'delete_media_all',
            // dashboard reads
            'get_live_data', 'get_access_state', 'get_demo_state', 'get_hide_admin_state',
        ];
        if (!in_array($action, $limitedAllowed, true)) {
            echo json_encode([
                'status'  => 'error',
                'message' => 'این بخش فقط برای مدیر با سطح دسترسی «کامل» فعال است',
                'level'   => 'limited',
            ]);
            exit;
        }
    }

    // ── Media removal, shared by the per-row action and the bulk one ────────
    // Removes the blob (only when no other live message still points at the
    // same token, and only when the path really sits inside uploads/) and
    // leaves the message row in place with media_deleted = 1, so the bubble
    // keeps its position and the client paints "این پیام حذف شده".
    $deleteMediaRows = function (array $rows) use ($pdo) {
        $upBase   = realpath(__DIR__ . '/uploads');
        $adminTag = (string)($_SESSION['username'] ?? 'admin');
        $done = []; $blobs = 0; $rooms = []; $targets = [];

        $upd = $pdo->prepare("
            UPDATE messages
            SET media_deleted = 1,
                media_deleted_at = NOW(),
                media_deleted_by = ?,
                touch_at = NOW(),
                media_prev_type = COALESCE(NULLIF(media_prev_type, ''), msg_type)
            WHERE id = ?
        ");
        $tokCount = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE file_token = ? AND media_deleted = 0 AND id <> ?");

        foreach ($rows as $r) {
            $msgId = (int)$r['id'];

            if (!empty($r['file_path'])) {
                $shared = 0;
                if (!empty($r['file_token'])) {
                    $tokCount->execute([(string)$r['file_token'], $msgId]);
                    $shared = (int)$tokCount->fetchColumn();
                }
                if ($shared === 0) {
                    $full = realpath(__DIR__ . '/' . ltrim((string)$r['file_path'], '/\\'));
                    if ($full && $upBase && strpos($full, $upBase . DIRECTORY_SEPARATOR) === 0
                        && is_file($full) && basename($full) !== '.htaccess') {
                        if (@unlink($full)) $blobs++;
                    }
                }
            }

            $upd->execute([$adminTag, $msgId]);
            $done[] = $msgId;

            if (!empty($r['room_name'])) {
                $rooms[$r['room_name']] = ['kind' => 'room', 'name' => (string)$r['room_name']];
            } else {
                foreach ([(int)$r['sender_id'], (int)$r['receiver_id']] as $uid) {
                    if ($uid > 0) $targets[$uid] = $uid;
                }
            }
        }

        return ['done' => $done, 'blobs' => $blobs, 'rooms' => $rooms, 'targets' => $targets];
    };

    // Shared enrichment for user rows, used by both the paged list and any
    // other endpoint that returns users. Kept in one place so the badges can
    // never drift apart between screens.
    $prepareUsers = function (array $rows) use ($admin_username) {
        $out = [];
        $now = time();
        foreach ($rows as $u) {
            $isAdmin = ($u['username'] === $admin_username);
            $isBanned = ((int)$u['is_banned_until'] > $now);
            $isOnline = ((int)$u['is_online'] === 1 && $u['last_activity'] && strtotime($u['last_activity']) > $now - 300);

            $statusHtml = '<span class="badge badge-success">فعال</span>';
            if ($isBanned) {
                $rem = (int)$u['is_banned_until'] - $now;
                if ($rem > (5 * 365 * 24 * 3600)) {
                    $statusHtml = '<span class="badge badge-danger">مسدود (دائم)</span>';
                } else {
                    $statusHtml = '<span class="badge badge-danger">مسدود (' . (int)ceil($rem / 60) . ' دقیقه)</span>';
                }
            }

            $u['is_admin'] = $isAdmin;
            $u['is_online_bool'] = $isOnline;
            $u['online_text'] = $isOnline ? 'آنلاین' : 'آفلاین';
            $u['time_ago'] = $u['last_activity'] ? time_elapsed_string($u['last_activity']) : '—';
            $u['status_html'] = $statusHtml;
            $out[] = $u;
        }
        return $out;
    };

    try {
        if ($action === 'get_users') {
            // Server-side search + paging: the panel never ships the whole user
            // table to the browser, so 10k accounts stay as fast as 10.
            $per = (int)($_POST['per'] ?? 10);
            if ($per < 5 || $per > 100) $per = 10;
            $page = max(1, (int)($_POST['page'] ?? 1));
            $q = trim((string)($_POST['q'] ?? ''));
            $q = mb_substr($q, 0, 50, 'UTF-8');

            $where = '';
            $args = [];
            if ($q !== '') {
                $where = "WHERE username LIKE ?";
                $args[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            }

            $stc = $pdo->prepare("SELECT COUNT(*) FROM users $where");
            $stc->execute($args);
            $total = (int)$stc->fetchColumn();

            $pages = max(1, (int)ceil($total / $per));
            if ($page > $pages) $page = $pages;
            $offset = ($page - 1) * $per;

            $stmt = $pdo->prepare("SELECT id, username, sticker, is_online, last_activity, ip_address, is_banned_until
                                   FROM users $where
                                   ORDER BY CASE WHEN username = ? THEN 0 ELSE 1 END, is_online DESC, last_activity DESC
                                   LIMIT $per OFFSET $offset");
            $stmt->execute(array_merge($args, [$admin_username]));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'status' => 'success',
                'users'  => $prepareUsers($rows),
                'total'  => $total,
                'page'   => $page,
                'pages'  => $pages,
                'per'    => $per,
                'q'      => $q,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'save_branding') {
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');

            $name = trim((string)($_POST['brand_name'] ?? ''));
            $tag  = trim((string)($_POST['brand_tagline'] ?? ''));
            if (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 40) {
                throw new Exception('نام برند باید بین ۲ تا ۴۰ حرف باشد');
            }
            if (mb_strlen($tag, 'UTF-8') > 60) {
                throw new Exception('زیرعنوان حداکثر ۶۰ حرف می‌تواند باشد');
            }

            if (!empty($_FILES['brand_logo']) && (int)$_FILES['brand_logo']['error'] === 0) {
                $f = $_FILES['brand_logo'];
                if ((int)$f['size'] > 2 * 1024 * 1024) throw new Exception('حجم لوگو حداکثر ۲ مگابایت است');
                $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true)) {
                    throw new Exception('فرمت لوگو باید PNG، JPG، WEBP یا GIF باشد');
                }
                if (@getimagesize($f['tmp_name']) === false) throw new Exception('فایل انتخاب‌شده تصویر معتبری نیست');

                $dir = __DIR__ . '/assets/brand';
                if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new Exception('پوشه assets/brand قابل ساخت نیست');
                }
                if (!is_file($dir . '/.htaccess')) {
                    @file_put_contents($dir . '/.htaccess', "<FilesMatch \"\\.(php|phtml|phar|php[0-9])$\">\n    Require all denied\n</FilesMatch>\n");
                }

                $newName = 'logo-' . bin2hex(random_bytes(8)) . '.' . $ext;
                if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $newName)) {
                    throw new Exception('ذخیره لوگو ناموفق بود');
                }
                $old = veyrax_setting($pdo, 'brand_logo', '');
                veyrax_brand_logo_unlink($old);
                veyrax_setting_set($pdo, 'brand_logo', 'assets/brand/' . $newName);
            }

            veyrax_setting_set($pdo, 'brand_name', $name);
            veyrax_setting_set($pdo, 'brand_tagline', $tag);

            // Footer version chip: empty = fall back to the build constant.
            $ver = trim(strip_tags((string)($_POST['site_version_label'] ?? '')));
            if (mb_strlen($ver, 'UTF-8') > 20) $ver = mb_substr($ver, 0, 20, 'UTF-8');
            veyrax_setting_set($pdo, 'site_version_label', $ver);

            echo json_encode(['status' => 'success', 'message' => 'برندینگ ذخیره شد']);
        }
        elseif ($action === 'reset_brand_logo') {
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $old = veyrax_setting($pdo, 'brand_logo', '');
            veyrax_brand_logo_unlink($old);
            veyrax_setting_set($pdo, 'brand_logo', '');
            echo json_encode(['status' => 'success', 'message' => 'لوگو حذف شد']);
        }
        elseif ($action === 'save_font') {
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $fam = strtolower(trim((string)($_POST['ui_font_family'] ?? 'default')));
            if (!in_array($fam, ['default', 'dana', 'kalameh'], true)) throw new Exception('فونت انتخاب‌شده معتبر نیست');

            $weight = (int)($_POST['ui_font_weight'] ?? 400);
            $allowed = $fam === 'default' ? [400] : array_keys(veyrax_font_map($fam));
            if (!in_array($weight, $allowed, true)) $weight = 400;

            veyrax_setting_set($pdo, 'ui_font_family', $fam);
            veyrax_setting_set($pdo, 'ui_font_weight', (string)$weight);
            echo json_encode(['status' => 'success', 'message' => 'فونت با موفقیت ذخیره شد']);
        }
        elseif ($action === 'save_msg_font') {
            // Message-body typography, kept separate from the UI font so the
            // chat can be bold while the rest of the app stays light.
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $fam = strtolower(trim((string)($_POST['msg_font_family'] ?? 'default')));
            if (!in_array($fam, ['default', 'dana', 'kalameh'], true)) {
                throw new Exception('فونت انتخاب‌شده معتبر نیست');
            }
            $weight = (int)($_POST['msg_font_weight'] ?? 400);
            $allowed = $fam === 'default' ? [300, 400, 500, 700, 800, 900] : array_keys(veyrax_font_map($fam));
            if (!in_array($weight, $allowed, true)) $weight = 400;

            veyrax_setting_set($pdo, 'msg_font_family', $fam);
            veyrax_setting_set($pdo, 'msg_font_weight', (string)$weight);
            echo json_encode([
                'status'  => 'success',
                'message' => 'فونت متن پیام‌ها ذخیره شد',
                'family'  => $fam,
                'weight'  => $weight,
            ]);
        }
        elseif ($action === 'get_live_data') {
            $usersCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $msgsCount = $pdo->query("SELECT COUNT(*) FROM messages")->fetchColumn();
            $roomsCount = $pdo->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
            
            $rooms = $pdo->query("SELECT r.*, u.username as creator FROM rooms r LEFT JOIN users u ON r.created_by = u.id ORDER BY r.type='public' DESC, r.id DESC")->fetchAll(PDO::FETCH_ASSOC);
            
            // The user table is paged by its own endpoint (get_users); shipping
            // the whole list on every 3s poll was the reason the panel got slow
            // once a few hundred accounts existed.
            $processedUsers = [];

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
                'site_lock_reason' => (string)($pdo->query("SELECT value FROM settings WHERE name='site_lock_reason'")->fetchColumn() ?: ''),
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
            $rep = veyrax_admin_purge_messages($pdo);
            $msg = 'تمام پیام‌های سیستم پاکسازی شد';
            $bits = [];
            if ($rep['messages'] > 0) $bits[] = number_format($rep['messages']) . ' پیام';
            if ($rep['pins'] > 0)    $bits[] = number_format($rep['pins']) . ' پین';
            if ($rep['files'] > 0)   $bits[] = number_format($rep['files']) . ' فایل';
            if ($bits) $msg .= ' (' . implode(' · ', $bits) . ')';
            echo json_encode(['status' => 'success', 'message' => $msg, 'report' => $rep]);
        }
        elseif ($action === 'import_db') {
            if (!$isFullAdmin) {
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

            $isPermanent = ($duration === 'perm' || $duration === '' || !is_numeric($duration));
            if ($isPermanent) {
                $banTime = time() + (10 * 365 * 24 * 3600);
            } else {
                $minutes = max(1, (int)$duration);
                $banTime = time() + ($minutes * 60);
            }

            // Reason typed by the admin — shown verbatim on the banned user's
            // lock screen, so keep it short and human readable.
            $banReason = trim((string)($_POST['reason'] ?? ''));
            if (mb_strlen($banReason, 'UTF-8') > 255) $banReason = mb_substr($banReason, 0, 255, 'UTF-8');
            if ($banReason === '') {
                $banReason = $isPermanent ? 'بن دائمی توسط مدیر' : 'بن موقت توسط مدیر';
            }

            $stmtTgt = $pdo->prepare("SELECT username, ip_address, user_agent, device_token, fp, fp_coarse FROM users WHERE id = ?");
            $stmtTgt->execute([$userId]);
            $tgt = $stmtTgt->fetch();
            if ($tgt && $tgt['username'] === $admin_username) throw new Exception('ادمین قابل مسدود شدن نیست');

            $pdo->prepare("UPDATE users SET is_banned_until = ?, is_online = 0 WHERE id = ?")->execute([$banTime, $userId]);
            $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?")->execute([$userId]);
            try { ws_push('force_reload', ['user_ids' => [$userId], 'reason' => 'banned', 'force' => true]); } catch (Exception $e) {}

            // Write every identity signal we have, so the same person cannot
            // come back with a fresh browser (new UA, new device_token).
            if ($tgt) {
                $tgtSignals = veyrax_ban_signals_for(
                    (string)($tgt['ip_address'] ?? ''),
                    (string)($tgt['fp'] ?? ''),
                    (string)($tgt['fp_coarse'] ?? ''),
                    (string)($tgt['device_token'] ?? ''),
                    (string)($tgt['user_agent'] ?? '')
                );
                veyrax_ban_add(
                    $pdo,
                    $tgtSignals,
                    $banReason,
                    $isPermanent,
                    $isPermanent ? 0 : $banTime,
                    $userId,
                    (string)$tgt['username'],
                    (string)($_SESSION['username'] ?? 'admin')
                );
            }

            if ($isPermanent && $tgt && $tgt['ip_address']) {
                $pdo->prepare("INSERT IGNORE INTO banned_ips (ip_address, reason) VALUES (?, 'بن کاربر')")->execute([$tgt['ip_address']]);
            }
            
            echo json_encode(['status' => 'success', 'message' => 'کاربر مسدود شد']);
        }
        elseif ($action === 'unban_user') {
            $userId = (int)$_POST['user_id'];
            $pdo->prepare("UPDATE users SET is_banned_until = 0 WHERE id = ?")->execute([$userId]);
            $stmt = $pdo->prepare("SELECT ip_address, username FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $ip = $row['ip_address'] ?? '';
            veyrax_ban_remove_for_user($pdo, $userId, (string)($row['username'] ?? ''));
            if ($ip) {
                $pdo->prepare("DELETE FROM banned_ips WHERE ip_address = ?")->execute([$ip]);
                veyrax_ban_remove_signal($pdo, 'ip', $ip);
            }
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
            // A real burn: drop every server-side session row (chat.php checks
            // this on every request), invalidate the device trust token and
            // push a realtime kick so the open tab logs out immediately.
            try { $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?")->execute([$userId]); } catch (Exception $e) {}
            $pdo->prepare("UPDATE users SET is_online = 0, device_token = NULL, ip_address = NULL, user_agent = NULL WHERE id = ?")->execute([$userId]);
            try {
                ws_push_async('force_reload', ['user_ids' => [$userId], 'reason' => 'session_burned', 'force' => true]);
            } catch (Exception $e) {}
            echo json_encode(['status' => 'success', 'message' => "سشن کاربر $targetUsername سوزانده شد و از حساب خارج شد"]);
        }
        elseif ($action === 'burn_all_sessions') {
            try { $pdo->exec("DELETE FROM user_sessions"); } catch (Exception $e) {}
            $pdo->prepare("UPDATE users SET is_online = 0, device_token = NULL, ip_address = NULL, user_agent = NULL WHERE username != ?")->execute([$admin_username]);
            try { ws_push_async('force_reload', ['reason' => 'session_burned', 'all' => true, 'force' => true]); } catch (Exception $e) {}
            echo json_encode(['status' => 'success', 'message' => 'سشن تمام کاربران (بجز ادمین) سوزانده شد']);
        }
        elseif ($action === 'get_sms_settings') {
            $cfg = veyrax_sms_cfg($pdo);
            $cfg['ready'] = veyrax_sms_ready($pdo);
            $last = [];
            try {
                $last = $pdo->query("SELECT phone, provider, purpose, ok, detail, created_at FROM sms_log ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
            echo json_encode(['status' => 'success', 'settings' => $cfg, 'log' => $last]);
        }
        elseif ($action === 'save_sms_settings') {
            $provider = strtolower(trim($_POST['sms_provider'] ?? 'none'));
            $allowed = ['none', 'kavenegar', 'smsir', 'melipayamak', 'ghasedak', 'webhook'];
            if (!in_array($provider, $allowed, true)) throw new Exception('سرویس‌دهنده نامعتبر است');
            veyrax_setting_set($pdo, 'sms_provider', $provider);
            veyrax_setting_set($pdo, 'sms_api_key', trim($_POST['sms_api_key'] ?? ''));
            veyrax_setting_set($pdo, 'sms_sender', trim($_POST['sms_sender'] ?? ''));
            veyrax_setting_set($pdo, 'sms_pattern', trim($_POST['sms_pattern'] ?? ''));
            veyrax_setting_set($pdo, 'sms_webhook', trim($_POST['sms_webhook'] ?? ''));
            veyrax_setting_set($pdo, 'sms_enabled', (($_POST['sms_enabled'] ?? '0') === '1') ? '1' : '0');
            veyrax_setting_set($pdo, 'sms_dev_mode', (($_POST['sms_dev_mode'] ?? '0') === '1') ? '1' : '0');
            echo json_encode(['status' => 'success', 'message' => 'تنظیمات پیامک ذخیره شد']);
        }
        elseif ($action === 'test_sms') {
            $phone = veyrax_sms_normalize_phone($_POST['phone'] ?? '');
            if (!preg_match('/^09[0-9]{9}$/', $phone)) throw new Exception('شماره موبایل معتبر وارد کنید');
            if (!veyrax_sms_ready($pdo) && !veyrax_sms_dev_mode()) throw new Exception('ابتدا سرویس‌دهنده و کلید API را ذخیره کنید');
            $r = veyrax_sms_send_ex($phone, 'پیام آزمایشی ویراکس — اتصال پیامک با موفقیت برقرار است.', 'test');
            if (!$r['ok']) throw new Exception('ارسال ناموفق: ' . $r['error']);
            echo json_encode(['status' => 'success', 'message' => 'پیام آزمایشی به ' . $phone . ' ارسال شد']);
        }
        elseif ($action === 'get_login_attempts') {
            $rows = [];
            try {
                $rows = $pdo->query("SELECT ip_address, username, scope, success, created_at FROM login_attempts ORDER BY id DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
            echo json_encode(['status' => 'success', 'attempts' => $rows]);
        }
        elseif ($action === 'clear_login_attempts') {
            try { $pdo->exec("DELETE FROM login_attempts"); } catch (Exception $e) {}
            echo json_encode(['status' => 'success', 'message' => 'سابقه تلاش‌های ورود پاک شد']);
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
        elseif ($action === 'save_site_lock_reason') {
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (mb_strlen($reason, 'UTF-8') > 500) {
                throw new Exception('دلیل حداکثر ۵۰۰ حرف می‌تواند باشد');
            }
            $pdo->prepare("INSERT INTO settings (name, value) VALUES ('site_lock_reason', ?)
                           ON DUPLICATE KEY UPDATE value = VALUES(value)")->execute([$reason]);
            echo json_encode([
                'status'  => 'success',
                'message' => $reason === '' ? 'دلیل پاک شد' : 'دلیل ذخیره شد',
                'reason'  => $reason,
            ]);
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
        elseif ($action === 'get_runtime_settings') {
            echo json_encode([
                'status' => 'success',
                'request_mode' => veyrax_setting($pdo, 'request_mode', 'telegram'),
                'cache_epoch' => (int)veyrax_setting($pdo, 'cache_epoch', '0'),
                'cache_ttl_hours' => (int)veyrax_setting($pdo, 'cache_ttl_hours', '24'),
            ]);
        }
        elseif ($action === 'save_runtime_settings') {
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $mode = trim((string)($_POST['request_mode'] ?? 'telegram'));
            if ($mode !== 'normal' && $mode !== 'telegram') $mode = 'telegram';
            $ttl = (int)($_POST['cache_ttl_hours'] ?? 24);
            if ($ttl < 1) $ttl = 1;
            if ($ttl > 720) $ttl = 720;
            $updates = ['request_mode' => $mode, 'cache_ttl_hours' => (string)$ttl];
            foreach ($updates as $k => $v) {
                $chk = $pdo->prepare("SELECT name FROM settings WHERE name = ?");
                $chk->execute([$k]);
                if ($chk->fetch()) {
                    $pdo->prepare("UPDATE settings SET value = ? WHERE name = ?")->execute([$v, $k]);
                } else {
                    $pdo->prepare("INSERT INTO settings (name, value) VALUES (?, ?)")->execute([$k, $v]);
                }
            }
            echo json_encode(['status' => 'success', 'message' => 'تنظیمات شبکه و کش ذخیره شد', 'request_mode' => $mode, 'cache_ttl_hours' => $ttl]);
        }
        elseif ($action === 'bump_cache_epoch') {
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $cur = (int)veyrax_setting($pdo, 'cache_epoch', '0');
            $next = (string)($cur + 1);
            $chk = $pdo->prepare("SELECT name FROM settings WHERE name = 'cache_epoch'");
            $chk->execute();
            if ($chk->fetch()) {
                $pdo->prepare("UPDATE settings SET value = ? WHERE name = 'cache_epoch'")->execute([$next]);
            } else {
                $pdo->prepare("INSERT INTO settings (name, value) VALUES ('cache_epoch', ?)")->execute([$next]);
            }
            echo json_encode(['status' => 'success', 'message' => 'کش همه کاربران پاک‌سازی شد', 'cache_epoch' => (int)$next]);
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

        // ── Media manager ───────────────────────────────────────────────────
        // Lists only voice / image / video rows and removes the stored blob
        // while keeping the chat row, so the conversation keeps its shape and
        // the bubble turns into a "این پیام حذف شده" placeholder.
        elseif ($action === 'list_media') {
            $mtype = strtolower(trim((string)($_POST['mtype'] ?? 'all')));
            if (!in_array($mtype, ['all', 'voice', 'image', 'video'], true)) $mtype = 'all';
            $q     = trim((string)($_POST['q'] ?? ''));
            $onlyGone = ((int)($_POST['gone'] ?? 0) === 1);
            $page  = max(1, (int)($_POST['page'] ?? 1));
            // Nine columns of dense data: ten rows keeps the pager reachable
            // without scrolling, and makes paging actually appear once a
            // library grows past a single screenful.
            $per   = 10;
            $off   = ($page - 1) * $per;

            $where = ["m.msg_type IN ('voice','image','video')"];
            $args  = [];
            if ($mtype !== 'all') { $where[] = 'm.msg_type = ?'; $args[] = $mtype; }
            if ($onlyGone)        { $where[] = 'm.media_deleted = 1'; }
            if ($q !== '') {
                $where[] = '(u.username LIKE ? OR m.file_name LIKE ? OR m.room_name LIKE ?)';
                $like = '%' . $q . '%';
                array_push($args, $like, $like, $like);
            }
            $w = implode(' AND ', $where);

            $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM messages m JOIN users u ON m.sender_id = u.id WHERE $w");
            $cntStmt->execute($args);
            $total = (int)$cntStmt->fetchColumn();

            $stmt = $pdo->prepare("
                SELECT m.id, m.msg_type, m.media_prev_type, m.media_deleted, m.media_deleted_at,
                       m.media_deleted_by, m.file_path, m.file_name, m.file_token, m.room_name,
                       m.receiver_id, m.created_at, u.username AS sender_name, r.username AS receiver_name
                FROM messages m
                JOIN users u ON m.sender_id = u.id
                LEFT JOIN users r ON m.receiver_id = r.id
                WHERE $w
                ORDER BY m.id DESC
                LIMIT $per OFFSET $off
            ");
            $stmt->execute($args);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $upBase = realpath(__DIR__ . '/uploads');
            $out = [];
            foreach ($rows as $r) {
                $size = 0;
                if (!empty($r['file_path'])) {
                    $full = realpath(__DIR__ . '/' . ltrim((string)$r['file_path'], '/\\'));
                    if ($full && $upBase && strpos($full, $upBase . DIRECTORY_SEPARATOR) === 0 && is_file($full)) {
                        $size = (int)filesize($full);
                    }
                }
                $out[] = [
                    'id'          => (int)$r['id'],
                    'type'        => (string)($r['media_prev_type'] ?: $r['msg_type']),
                    'deleted'     => ((int)$r['media_deleted'] === 1),
                    'deleted_at'  => $r['media_deleted_at'] ? jdate('Y/m/d H:i', strtotime($r['media_deleted_at'])) : '',
                    'deleted_by'  => (string)($r['media_deleted_by'] ?? ''),
                    'name'        => (string)($r['file_name'] ?? ''),
                    'size'        => $size,
                    'where'       => $r['room_name'] ? ('اتاق: ' . $r['room_name']) : ($r['receiver_id'] ? ('خصوصی با ' . ($r['receiver_name'] ?? ('#' . $r['receiver_id']))) : 'گفتگوی عمومی'),
                    'sender'      => (string)$r['sender_name'],
                    'created_at'  => jdate('Y/m/d H:i', strtotime($r['created_at'])),
                ];
            }

            echo json_encode([
                'status' => 'success',
                'items'  => $out,
                'total'  => $total,
                'page'   => $page,
                'pages'  => max(1, (int)ceil($total / $per)),
                'per'    => $per,
            ]);
        }

        elseif ($action === 'delete_media') {
            $raw = $_POST['ids'] ?? ($_POST['id'] ?? '');
            if (is_string($raw)) $raw = preg_split('/[,\s]+/', $raw);
            $ids = [];
            foreach ((array)$raw as $v) {
                $v = (int)$v;
                if ($v > 0) $ids[$v] = $v;
            }
            $ids = array_values($ids);
            if (!$ids) throw new Exception('رسانه‌ای انتخاب نشده است');
            // Hard cap keeps the request bounded and the transaction short.
            if (count($ids) > 50) $ids = array_slice($ids, 0, 50);

            $in = implode(',', array_fill(0, count($ids), '?'));
            $sel = $pdo->prepare("
                SELECT id, msg_type, file_path, file_token, media_deleted, sender_id, receiver_id, room_name
                FROM messages
                WHERE id IN ($in) AND msg_type IN ('voice','image','video')
            ");
            $sel->execute($ids);
            $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) throw new Exception('رسانه معتبری یافت نشد (فقط ویس، تصویر و ویدیو قابل حذف است)');

            $res   = $deleteMediaRows($rows);
            $done  = $res['done'];
            $blobs = $res['blobs'];

            // Let open tabs repaint without a manual refresh. The client also
            // notices the change on its next poll because media_deleted is part
            // of the render signature, so a failed push is not fatal.
            try {
                ws_push_async('message_updated', [
                    'kind'       => 'media_delete',
                    'ids'        => $done,
                    'force'      => true,
                    'rooms'      => array_values($res['rooms']),
                    'targets'    => array_values($res['targets']),
                ]);
            } catch (Exception $e) {}

            $n = count($done);
            echo json_encode([
                'status'  => 'success',
                'message' => $n === 1
                    ? 'رسانه حذف شد و جای آن پیام «حذف شده» باقی ماند'
                    : ($n . ' رسانه حذف شد و جای آن‌ها باقی ماند'),
                'ids'     => $done,
                'blobs'   => $blobs,
            ]);
        }

        elseif ($action === 'delete_media_all') {
            // "Delete everything that matches the current filter." The filter
            // is the same one list_media uses, so what the admin sees on screen
            // is exactly what gets removed — no hidden rows, no per-row picking.
            $mtype = strtolower(trim((string)($_POST['mtype'] ?? 'all')));
            if (!in_array($mtype, ['all', 'voice', 'image', 'video'], true)) $mtype = 'all';
            $q        = trim((string)($_POST['q'] ?? ''));
            $onlyGone = ((int)($_POST['gone'] ?? 0) === 1);

            $where = ["m.msg_type IN ('voice','image','video')", "m.media_deleted = 0"];
            $args  = [];
            if ($mtype !== 'all') { $where[] = 'm.msg_type = ?'; $args[] = $mtype; }
            if ($q !== '') {
                $where[] = '(u.username LIKE ? OR m.file_name LIKE ? OR m.room_name LIKE ?)';
                $like = '%' . $q . '%';
                array_push($args, $like, $like, $like);
            }
            $w = implode(' AND ', $where);

            // "Only already-deleted" is a browsing filter; there is nothing left
            // to remove under it, and silently deleting the *live* rows instead
            // would be the opposite of what the admin asked for.
            if ($onlyGone) {
                echo json_encode([
                    'status'  => 'success',
                    'removed' => 0,
                    'blobs'   => 0,
                    'message' => 'در حالت «فقط حذف‌شده‌ها» چیزی برای حذف باقی نمانده است',
                ]);
                exit;
            }

            // Batched so a library of thousands of clips cannot exhaust memory
            // or hold one giant transaction open.
            $batchSize = 200;
            $totalDone = 0;
            $totalBlobs = 0;
            $allRooms = [];
            $allTargets = [];

            for ($round = 0; $round < 200; $round++) {
                $stmt = $pdo->prepare("
                    SELECT m.id, m.msg_type, m.file_path, m.file_token, m.media_deleted,
                           m.sender_id, m.receiver_id, m.room_name
                    FROM messages m
                    JOIN users u ON m.sender_id = u.id
                    WHERE $w
                    ORDER BY m.id ASC
                    LIMIT $batchSize
                ");
                $stmt->execute($args);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) break;

                $res = $deleteMediaRows($rows);
                $totalDone  += count($res['done']);
                $totalBlobs += $res['blobs'];
                $allRooms   += $res['rooms'];
                $allTargets += $res['targets'];

                // Push per batch so open tabs start repainting immediately
                // instead of waiting for the whole sweep to finish.
                try {
                    ws_push_async('message_updated', [
                        'kind'    => 'media_delete',
                        'ids'     => $res['done'],
                        'force'   => true,
                        'rooms'   => array_values($res['rooms']),
                        'targets' => array_values($res['targets']),
                    ]);
                } catch (Exception $e) {}

                if (count($rows) < $batchSize) break;
            }

            echo json_encode([
                'status'  => 'success',
                'removed' => $totalDone,
                'blobs'   => $totalBlobs,
                'message' => $totalDone > 0
                    ? ($totalDone . ' رسانه حذف شد؛ جای همه در گفتگو باقی ماند')
                    : 'رسانه‌ای مطابق این فیلتر برای حذف پیدا نشد',
            ]);
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
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT reported_id FROM user_reports WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) throw new Exception('گزارش یافت نشد');
            $reportedId = $row['reported_id'];
            $stmtU = $pdo->prepare("SELECT username, ip_address, user_agent, device_token, fp, fp_coarse FROM users WHERE id = ?");
            $stmtU->execute([$reportedId]);
            $tgtUser = $stmtU->fetch();
            if (!$tgtUser) throw new Exception('کاربر یافت نشد');
            if ($tgtUser['username'] === $admin_username) throw new Exception('ادمین قابل مسدود شدن نیست');
            $banTime = time() + (365 * 24 * 3600);
            $pdo->prepare("UPDATE users SET is_banned_until = ?, is_online = 0 WHERE id = ?")->execute([$banTime, $reportedId]);
            $pdo->prepare("DELETE FROM user_sessions WHERE user_id = ?")->execute([$reportedId]);
            try { ws_push('force_reload', ['user_ids' => [$reportedId], 'reason' => 'banned', 'force' => true]); } catch (Exception $e) {}
            veyrax_ban_add(
                $pdo,
                veyrax_ban_signals_for(
                    (string)($tgtUser['ip_address'] ?? ''),
                    (string)($tgtUser['fp'] ?? ''),
                    (string)($tgtUser['fp_coarse'] ?? ''),
                    (string)($tgtUser['device_token'] ?? ''),
                    (string)($tgtUser['user_agent'] ?? '')
                ),
                'تأیید گزارش تخلف',
                false,
                $banTime,
                $reportedId,
                (string)$tgtUser['username'],
                (string)($_SESSION['username'] ?? 'admin')
            );
            if ($tgtUser['ip_address']) {
                $pdo->prepare("INSERT IGNORE INTO banned_ips (ip_address, reason) VALUES (?, 'تأیید گزارش')")->execute([$tgtUser['ip_address']]);
            }
            $pdo->prepare("UPDATE user_reports SET status = 'approved' WHERE id = ?")->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'گزارش تأیید و کاربر بن شد']);
        }
        elseif ($action === 'list_admins') {
            if (!$isMainAdmin) throw new Exception('دسترسی محدود');
            $rows = $pdo->query("SELECT id, username, display_name, level, created_by, created_at, last_login, is_active FROM admin_users ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'admins' => $rows]);
        }
        elseif ($action === 'add_admin') {
            if (!$isMainAdmin) throw new Exception('فقط مدیر اصلی');
            $uname = xss_clean(trim($_POST['username'] ?? ''));
            $pass  = trim($_POST['password'] ?? '');
            $dname = xss_clean(trim($_POST['display_name'] ?? $uname));
            $level = (($_POST['level'] ?? 'limited') === 'full') ? 'full' : 'limited';
            if (empty($uname) || empty($pass)) throw new Exception('نام کاربری و رمز عبور الزامی است');
            if (strlen($pass) < 8) throw new Exception('رمز عبور باید حداقل ۸ کاراکتر باشد');
            if ($uname === $admin_username) throw new Exception('این نام کاربری رزرو شده است');
            $chk = $pdo->prepare("SELECT id FROM admin_users WHERE username = ?");
            $chk->execute([$uname]);
            if ($chk->fetch()) throw new Exception('این ادمین قبلاً ثبت شده است');
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO admin_users (username, password_hash, display_name, level, created_by) VALUES (?, ?, ?, ?, ?)")
                ->execute([$uname, $hash, $dname, $level, $admin_username]);
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
        elseif ($action === 'get_social_settings') {
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            echo json_encode([
                'status'    => 'success',
                'enabled'   => veyrax_setting($pdo, 'social_enabled', '1') === '1',
                'github_on' => veyrax_setting($pdo, 'social_github_on', '1') === '1',
                'website_on'=> veyrax_setting($pdo, 'social_website_on', '1') === '1',
                'telegram_on'=> veyrax_setting($pdo, 'social_telegram_on', '1') === '1',
                'github'    => veyrax_setting($pdo, 'social_github_link', ''),
                'website'   => veyrax_setting($pdo, 'social_website_link', ''),
                'telegram'  => veyrax_setting($pdo, 'social_telegram_link', ''),
            ]);
        }
        elseif ($action === 'save_social_settings') {
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $cleanUrl = function ($raw) {
                $u = trim((string)$raw);
                if ($u === '') return '';
                if (!filter_var($u, FILTER_VALIDATE_URL)) throw new Exception('یکی از لینک‌ها معتبر نیست (مثال: https://example.com)');
                $scheme = strtolower((string)parse_url($u, PHP_URL_SCHEME));
                if (!in_array($scheme, ['http', 'https'], true)) throw new Exception('لینک‌ها باید با http یا https شروع شوند');
                if (mb_strlen($u, 'UTF-8') > 300) throw new Exception('لینک حداکثر ۳۰۰ کاراکتر باشد');
                return $u;
            };
            $github = $cleanUrl($_POST['github'] ?? '');
            $website = $cleanUrl($_POST['website'] ?? '');
            $telegram = $cleanUrl($_POST['telegram'] ?? '');
            veyrax_setting_set($pdo, 'social_enabled', ($_POST['enabled'] ?? '0') === '1' ? '1' : '0');
            veyrax_setting_set($pdo, 'social_github_on', ($_POST['github_on'] ?? '0') === '1' ? '1' : '0');
            veyrax_setting_set($pdo, 'social_website_on', ($_POST['website_on'] ?? '0') === '1' ? '1' : '0');
            veyrax_setting_set($pdo, 'social_telegram_on', ($_POST['telegram_on'] ?? '0') === '1' ? '1' : '0');
            veyrax_setting_set($pdo, 'social_github_link', $github);
            veyrax_setting_set($pdo, 'social_website_link', $website);
            veyrax_setting_set($pdo, 'social_telegram_link', $telegram);
            echo json_encode(['status' => 'success', 'message' => 'تنظیمات شبکه‌های اجتماعی ذخیره شد']);
        }
        elseif ($action === 'save_admin_display') {
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $newName = xss_clean(trim($_POST['display_name'] ?? ''));
            if ($newName === '' || mb_strlen($newName, 'UTF-8') > 50) throw new Exception('نام نمایشی باید بین ۱ تا ۵۰ کاراکتر باشد');
            $cfgPath = __DIR__ . '/data/config.php';
            if (!is_file($cfgPath) || !is_writable($cfgPath)) throw new Exception('فایل data/config.php قابل نوشتن نیست');
            $cfg = (string)file_get_contents($cfgPath);
            $replacement = '$admin_display_name = ' . var_export($newName, true) . ';';
            $cfg2 = preg_replace('/^\$admin_display_name\s*=\s*.*;\s*$/m', $replacement, $cfg, 1);
            if ($cfg2 === null || $cfg2 === $cfg) {
                // No existing line (very old install): insert above the password.
                $cfg2 = preg_replace('/^(\$admin_password\s*=\s*.*;\s*)$/m', $replacement . "\n$1", $cfg, 1);
            }
            if ($cfg2 === null || $cfg2 === $cfg) throw new Exception('به‌روزرسانی تنظیمات ناموفق بود');
            $tmp = $cfgPath . '.tmp';
            if (@file_put_contents($tmp, $cfg2) === false || !@rename($tmp, $cfgPath)) {
                @unlink($tmp);
                throw new Exception('نوشتن فایل تنظیمات ناموفق بود');
            }
            try { $pdo->prepare("UPDATE admin_users SET display_name = ? WHERE username = ?")->execute([$newName, $admin_username]); } catch (Exception $e) {}
            echo json_encode(['status' => 'success', 'message' => 'نام نمایشی مدیر به «' . $newName . '» تغییر کرد']);
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
        elseif ($action === 'set_admin_level') {
            if (!$isMainAdmin) throw new Exception('فقط مدیر اصلی');
            $id  = (int)($_POST['admin_id'] ?? 0);
            $lvl = (($_POST['level'] ?? '') === 'full') ? 'full' : 'limited';
            $stA = $pdo->prepare("SELECT username FROM admin_users WHERE id = ?");
            $stA->execute([$id]);
            $unameChk = $stA->fetchColumn();
            if (!$unameChk) throw new Exception('ادمین یافت نشد');
            $pdo->prepare("UPDATE admin_users SET level = ? WHERE id = ?")->execute([$lvl, $id]);
            echo json_encode([
                'status'  => 'success',
                'message' => 'سطح دسترسی «' . $unameChk . '» به ' . ($lvl === 'full' ? 'کامل' : 'محدود') . ' تغییر کرد',
                'level'   => $lvl,
            ]);
        }
        elseif ($action === 'get_hide_admin_state') {
            $stmt2 = $pdo->prepare("SELECT value FROM settings WHERE name = 'hide_admin_online'");
            $stmt2->execute();
            $val = $stmt2->fetchColumn();
            echo json_encode(['status' => 'success', 'state' => ($val === '1') ? 1 : 0]);
        }
        elseif ($action === 'toggle_hide_admin_online') {
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
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
        elseif ($action === 'get_demo_state') {
            $demoOn = ($pdo->query("SELECT value FROM settings WHERE name = 'demo_mode'")->fetchColumn() === '1');
            echo json_encode([
                'status'  => 'success',
                'enabled' => $demoOn ? 1 : 0,
                'active'  => veyrax_demo_active_count($pdo),
                'ttl_min' => (int)round(VEYRAX_DEMO_TTL / 60),
                'max_msg' => (int)VEYRAX_DEMO_MAX_MSGS,
            ]);
        }
        elseif ($action === 'toggle_demo_mode') {
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $new = ((string)($_POST['enabled'] ?? '0') === '1') ? '1' : '0';
            $pdo->prepare("UPDATE settings SET value = ? WHERE name = 'demo_mode'")->execute([$new]);
            if ($new === '0') {
                // Turning the demo off is also a clean-up: nothing should be
                // left behind for a visitor who still has the tab open.
                veyrax_demo_purge($pdo, 0, true);
            } else {
                veyrax_demo_ensure_room($pdo);
            }
            echo json_encode([
                'status'  => 'success',
                'state'   => (int)$new,
                'active'  => veyrax_demo_active_count($pdo),
                'message' => $new === '1' ? 'حالت نمایشی فعال شد — ورود با demo / demo' : 'حالت نمایشی غیرفعال و داده‌های آن پاک شد',
            ]);
        }
        elseif ($action === 'purge_demo_data') {
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $removed = veyrax_demo_purge($pdo, 0, true);
            echo json_encode([
                'status'  => 'success',
                'removed' => $removed,
                'active'  => veyrax_demo_active_count($pdo),
                'message' => $removed > 0
                    ? 'حساب‌های نمایشی و پیام‌هایشان پاک شد'
                    : 'داده‌ای برای پاکسازی نبود',
            ]);
        }
        elseif ($action === 'get_access_state') {
            echo json_encode([
                'status'        => 'success',
                'lock_register' => veyrax_registration_locked($pdo) ? 1 : 0,
                'lock_login'    => veyrax_login_locked($pdo) ? 1 : 0,
                'demo'          => veyrax_demo_enabled($pdo) ? 1 : 0,
            ]);
        }
        elseif ($action === 'toggle_register_lock' || $action === 'toggle_login_lock') {
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $key = ($action === 'toggle_register_lock') ? 'lock_register' : 'lock_login';
            $new = ((string)($_POST['enabled'] ?? '0') === '1') ? '1' : '0';
            $pdo->prepare("INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
                ->execute([$key, $new]);
            $labels = [
                'lock_register' => [$new === '1' ? 'ثبت‌نام بسته شد' : 'ثبت‌نام باز شد', 'ثبت‌نام باز است'],
                'lock_login'    => [$new === '1' ? 'ورود کاربران بسته شد' : 'ورود کاربران باز شد', 'ورود باز است'],
            ];
            echo json_encode([
                'status'  => 'success',
                'state'   => (int)$new,
                'message' => $labels[$key][0],
            ]);
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
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $rows = $pdo->query("SELECT * FROM update_notifications ORDER BY created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'notifications' => $rows]);
        }
        elseif ($action === 'add_update_notif') {
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
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
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM update_notifications WHERE id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM update_notif_seen WHERE notif_id = ?")->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'اطلاعیه حذف شد']);
        }
        elseif ($action === 'toggle_update_notif') {
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT is_active FROM update_notifications WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) throw new Exception('اطلاعیه یافت نشد');
            $new = $row['is_active'] ? 0 : 1;
            $pdo->prepare("UPDATE update_notifications SET is_active = ? WHERE id = ?")->execute([$new, $id]);
            echo json_encode(['status' => 'success', 'message' => $new ? 'فعال شد' : 'غیرفعال شد']);
        }
        elseif ($action === 'get_ws_settings') {
            ws_load_runtime_settings($pdo);
            global $ws_runtime;
            echo json_encode([
                'status' => 'success',
                'ws_enabled' => (bool)$ws_runtime['enabled'],
                'ws_url' => $ws_runtime['url'],
                'ws_internal_url' => $ws_runtime['internal_url'],
                'ws_origins' => $ws_runtime['origins'],
                'ws_reconnect' => (int)$ws_runtime['reconnect'],
                'is_main_admin' => (bool)$isMainAdmin
            ]);
        }
        elseif ($action === 'save_ws_settings') {
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $enabled = (isset($_POST['ws_enabled']) && $_POST['ws_enabled'] === '1') ? '1' : '0';
            $url = trim($_POST['ws_url'] ?? '');
            $internal = trim($_POST['ws_internal_url'] ?? '');
            $origins = trim($_POST['ws_origins'] ?? '*');
            $reconnect = max(2, (int)($_POST['ws_reconnect'] ?? 5));
            if ($url !== '' && !preg_match('#^(wss?|https?)://#i', $url)) {
                throw new Exception('آدرس WebSocket باید با ws:// یا wss:// شروع شود');
            }
            if ($internal !== '' && !preg_match('#^https?://#i', $internal)) {
                throw new Exception('آدرس داخلی باید با http:// یا https:// شروع شود');
            }
            $updates = [
                'ws_enabled' => $enabled,
                'ws_url' => $url,
                'ws_internal_url' => $internal,
                'ws_origins' => $origins,
                'ws_reconnect' => (string)$reconnect,
            ];
            foreach ($updates as $k => $v) {
                $chk = $pdo->prepare("SELECT name FROM settings WHERE name = ?");
                $chk->execute([$k]);
                if ($chk->fetch()) {
                    $pdo->prepare("UPDATE settings SET value = ? WHERE name = ?")->execute([$v, $k]);
                } else {
                    $pdo->prepare("INSERT INTO settings (name, value) VALUES (?, ?)")->execute([$k, $v]);
                }
            }
            echo json_encode(['status' => 'success', 'message' => 'تنظیمات WebSocket ذخیره شد']);
        }
        elseif ($action === 'get_ai_settings') {
            require_once __DIR__ . '/ai_lib.php';
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $cfg = veyrax_ai_cfg($pdo);
            $key = $cfg['ai_api_key'] ?? '';
            echo json_encode([
                'status' => 'success',
                'ai_enabled' => ($cfg['ai_enabled'] ?? '0') === '1',
                'ai_base_url' => $cfg['ai_base_url'] ?? '',
                'ai_api_key_set' => $key !== '',
                'ai_api_key_masked' => $key === '' ? '' : (substr($key, 0, 3) . str_repeat('*', max(4, min(12, strlen($key) - 6))) . substr($key, -3)),
                'ai_model' => $cfg['ai_model'] ?? '',
                'ai_system_prompt' => $cfg['ai_system_prompt'] ?? '',
                'ai_behavior' => $cfg['ai_behavior'] ?? '',
                'ai_max_tokens' => (int)($cfg['ai_max_tokens'] ?: 800),
                'ai_temperature' => (float)($cfg['ai_temperature'] ?: 0.7),
                'ai_timeout' => (int)($cfg['ai_timeout'] ?: 60),
                'ai_cooldown' => (int)($cfg['ai_cooldown'] ?: 5),
                'ai_max_attempts' => (int)($cfg['ai_max_attempts'] ?: 3),
                'ai_rooms' => $cfg['ai_rooms'] ?? '',
                'ai_all_rooms' => ($cfg['ai_all_rooms'] ?? '1') === '1',
                'ai_pv' => ($cfg['ai_pv'] ?? '1') === '1',
                'ai_mention_only' => ($cfg['ai_mention_only'] ?? '1') === '1',
                'ai_user_limit_count' => (int)($cfg['ai_user_limit_count'] ?? 0),
                'ai_user_limit_window' => (int)($cfg['ai_user_limit_window'] ?? 0),
                'ai_keyword' => ($cfg['ai_keyword'] ?? '') !== '' ? $cfg['ai_keyword'] : 'ویراکس',
                'ai_assistant_id' => veyrax_ai_user_id($pdo)
            ]);
        }
        elseif ($action === 'save_ai_settings') {
            require_once __DIR__ . '/ai_lib.php';
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $vals = [
                'ai_enabled' => (isset($_POST['ai_enabled']) && $_POST['ai_enabled'] === '1') ? '1' : '0',
                'ai_base_url' => trim($_POST['ai_base_url'] ?? ''),
                'ai_model' => trim($_POST['ai_model'] ?? ''),
                'ai_system_prompt' => trim($_POST['ai_system_prompt'] ?? ''),
                'ai_behavior' => trim($_POST['ai_behavior'] ?? ''),
                'ai_max_tokens' => (string)max(100, min(4000, (int)($_POST['ai_max_tokens'] ?? 800))),
                'ai_temperature' => (string)max(0, min(2, (float)($_POST['ai_temperature'] ?? 0.7))),
                'ai_timeout' => (string)max(5, min(120, (int)($_POST['ai_timeout'] ?? 60))),
                'ai_cooldown' => (string)max(0, min(600, (int)($_POST['ai_cooldown'] ?? 5))),
                // Total model-call attempts per trigger before the graceful
                // "unavailable" notice is posted (1 = no retry).
                'ai_max_attempts' => (string)max(1, min(5, (int)($_POST['ai_max_attempts'] ?? 3))),
                'ai_rooms' => trim($_POST['ai_rooms'] ?? ''),
                // Assistant scope: every group (default) or only marked rooms;
                // and whether private chats can summon it at all.
                'ai_all_rooms' => (isset($_POST['ai_all_rooms']) && $_POST['ai_all_rooms'] === '1') ? '1' : '0',
                'ai_pv' => (isset($_POST['ai_pv']) && $_POST['ai_pv'] === '1') ? '1' : '0',
                'ai_mention_only' => (isset($_POST['ai_mention_only']) && $_POST['ai_mention_only'] === '1') ? '1' : '0',
                // Per-user assistant cap: 0 on either field = unlimited.
                'ai_user_limit_count' => (string)max(0, min(50, (int)($_POST['ai_user_limit_count'] ?? 0))),
                'ai_user_limit_window' => (string)max(0, min(1440, (int)($_POST['ai_user_limit_window'] ?? 0))),
                'ai_keyword' => mb_substr(trim($_POST['ai_keyword'] ?? ''), 0, 200),
            ];
            if ($vals['ai_enabled'] === '1') {
                if ($vals['ai_base_url'] !== '' && !filter_var($vals['ai_base_url'], FILTER_VALIDATE_URL)) {
                    throw new Exception('آدرس پایه API نامعتبر است');
                }
                if ($vals['ai_model'] === '') throw new Exception('نام مدل الزامی است');
                if (mb_strlen($vals['ai_system_prompt']) > 4000) throw new Exception('پرامپت سیستم خیلی طولانی است');
                if (mb_strlen($vals['ai_behavior']) > 2000) throw new Exception('متن رفتار دستیار خیلی طولانی است');
            }
            if (isset($_POST['ai_api_key']) && trim($_POST['ai_api_key']) !== '') {
                $vals['ai_api_key'] = trim($_POST['ai_api_key']);
            }
            foreach ($vals as $k => $v) {
                $chk = $pdo->prepare("SELECT setting_key FROM system_settings WHERE setting_key = ?");
                $chk->execute([$k]);
                if ($chk->fetch()) {
                    $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?")->execute([$v, $k]);
                } else {
                    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
                }
            }
            veyrax_ai_user_id($pdo);
            echo json_encode(['status' => 'success', 'message' => 'تنظیمات دستیار ذخیره شد']);
        }
        elseif ($action === 'test_ai_connection') {
            require_once __DIR__ . '/ai_lib.php';
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $cfg = veyrax_ai_cfg($pdo);
            if (isset($_POST['ai_base_url']) && trim($_POST['ai_base_url']) !== '') $cfg['ai_base_url'] = trim($_POST['ai_base_url']);
            if (isset($_POST['ai_api_key']) && trim($_POST['ai_api_key']) !== '') $cfg['ai_api_key'] = trim($_POST['ai_api_key']);
            if (isset($_POST['ai_model']) && trim($_POST['ai_model']) !== '') $cfg['ai_model'] = trim($_POST['ai_model']);
            if (isset($_POST['ai_max_tokens']) && (int)$_POST['ai_max_tokens'] > 0) $cfg['ai_max_tokens'] = (string)(int)$_POST['ai_max_tokens'];
            if (isset($_POST['ai_timeout']) && (int)$_POST['ai_timeout'] > 0) $cfg['ai_timeout'] = (string)(int)$_POST['ai_timeout'];
            if (($cfg['ai_base_url'] ?? '') === '') throw new Exception('ابتدا آدرس پایه API را وارد کنید');
            if (($cfg['ai_model'] ?? '') === '') throw new Exception('ابتدا نام مدل را وارد کنید');
            // Reasoning models (MiniMax-M2, R1, …) can burn 1k+ tokens thinking
            // before any answer appears; a thin test budget only proves the
            // connection broke itself. Give the probe a generous ceiling.
            $cfg['ai_max_tokens'] = (string)max(2000, (int)($cfg['ai_max_tokens'] ?? 0));
            $cfg['ai_timeout'] = (string)max(60, (int)($cfg['ai_timeout'] ?? 0));
            $reply = veyrax_ai_request($cfg, [['role' => 'user', 'content' => 'سلام']]);
            echo json_encode([
                'status' => $reply !== null ? 'success' : 'error',
                'message' => $reply !== null ? 'اتصال موفق؛ مدل پاسخ داد' : 'اتصال ناموفق بود؛ آدرس، کلید و مدل را بررسی کنید',
            ]);
        }
        elseif ($action === 'get_backup_list') {
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $dir = __DIR__ . '/data/backups';
            $items = [];
            if (is_dir($dir)) {
                foreach (scandir($dir) ?: [] as $f) {
                    if ($f === '.' || $f === '..' || !is_file($dir . '/' . $f)) continue;
                    // Security placeholders are not backups and must never
                    // appear in the list (or be offered for download).
                    if ($f === '.htaccess' || $f === 'index.php' || $f[0] === '.') continue;
                    $items[] = ['name' => $f, 'size' => filesize($dir . '/' . $f), 'mtime' => filemtime($dir . '/' . $f)];
                }
            }
            usort($items, function ($a, $b) { return $b['mtime'] <=> $a['mtime']; });
            $hist = [];
            try {
                $hist = $pdo->query("SELECT kind, filename, size_bytes, status, created_by, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS created_at FROM ai_backups ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
                // A backup deleted by hand (FTP/panel) must not linger in the
                // history list. Drop those rows instead of showing ghosts.
                $live = [];
                foreach ($hist as $h) {
                    if (is_file($dir . '/' . $h['filename'])) $live[] = $h;
                    else {
                        try { $pdo->prepare("DELETE FROM ai_backups WHERE filename = ?")->execute([$h['filename']]); } catch (Exception $e) {}
                    }
                }
                $hist = $live;
            } catch (Exception $e) {}
            echo json_encode(['status' => 'success', 'backups' => $items, 'history' => $hist]);
        }
        elseif ($action === 'reset_backups') {
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $dir = __DIR__ . '/data/backups';
            $removed = 0;
            if (is_dir($dir)) {
                foreach (scandir($dir) ?: [] as $f) {
                    if ($f === '.' || $f === '..') continue;
                    // Keep only the security placeholders; every real backup
                    // (including dumps dropped in by hand) goes.
                    if ($f === '.htaccess' || $f === 'index.php') continue;
                    $path = $dir . '/' . $f;
                    if (is_file($path) && @unlink($path)) $removed++;
                }
            }
            try { $pdo->exec("DELETE FROM ai_backups"); } catch (Exception $e) {}
            echo json_encode(['status' => 'success', 'message' => 'همه‌ی پشتیبان‌ها پاک شد (' . $removed . ' فایل)', 'removed' => $removed]);
        }
        elseif ($action === 'create_backup') {
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $kind = ($_POST['kind'] ?? 'db');
            if (!in_array($kind, ['db', 'source', 'both'], true)) $kind = 'db';
            require_once __DIR__ . '/backup_lib.php';
            $result = veyrax_create_backup($pdo, $kind);
            if (!$result['ok']) throw new Exception($result['error'] ?? 'خطا در ساخت پشتیبان');
            try {
                $pdo->prepare("INSERT INTO ai_backups (kind, filename, size_bytes, status, created_by) VALUES (?, ?, ?, 'done', ?)")
                    ->execute([$kind, $result['filename'], $result['size'], ($_SESSION['username'] ?? 'admin')]);
            } catch (Exception $e) {}
            veyrax_backup_retention($pdo);
            echo json_encode(['status' => 'success', 'message' => 'پشتیبان ساخته شد: ' . $result['filename'], 'filename' => $result['filename'], 'size' => $result['size']]);
        }
        elseif ($action === 'delete_backup') {
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $name = basename(trim($_POST['filename'] ?? ''));
            if ($name === '' || !preg_match('/^veyrax_backup_[A-Za-z0-9_\-]+\.(sql|zip|tar\.gz)$/', $name)) {
                throw new Exception('نام فایل نامعتبر است');
            }
            $path = __DIR__ . '/data/backups/' . $name;
            if (!is_file($path)) throw new Exception('فایل پشتیبان یافت نشد');
            @unlink($path);
            try { $pdo->prepare("DELETE FROM ai_backups WHERE filename = ?")->execute([$name]); } catch (Exception $e) {}
            echo json_encode(['status' => 'success', 'message' => 'پشتیبان حذف شد']);
        }
        elseif ($action === 'download_backup') {
            if (!$isFullAdmin) throw new Exception('دسترسی محدود');
            $name = basename(trim($_POST['filename'] ?? ''));
            if ($name === '' || !preg_match('/^veyrax_backup_[A-Za-z0-9_\-]+\.(sql|zip|tar\.gz)$/', $name)) {
                throw new Exception('نام فایل نامعتبر است');
            }
            $path = __DIR__ . '/data/backups/' . $name;
            if (!is_file($path)) throw new Exception('فایل پشتیبان یافت نشد');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }
        elseif ($action === 'test_ws_connection') {
            ws_load_runtime_settings($pdo);
            global $ws_runtime;
            $internal = rtrim($ws_runtime['internal_url'], '/') . '/health';
            $ok = false;
            $info = null;
            if (!empty($internal)) {
                $ctx = stream_context_create([
                    'http' => ['method' => 'GET', 'timeout' => 3, 'ignore_errors' => true],
                    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
                ]);
                $resp = @file_get_contents($internal, false, $ctx);
                if ($resp !== false) {
                    $info = json_decode($resp, true);
                    $ok = is_array($info) && ($info['status'] ?? '') === 'ok';
                }
            }
            echo json_encode([
                'status' => $ok ? 'success' : 'error',
                'message' => $ok ? 'اتصال به سرور WebSocket برقرار است' : 'سرور WebSocket در دسترس نیست (چت با حالت عادی کار می‌کند)',
                'clients' => $info['clients'] ?? null
            ]);
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
    <?php veyrax_icon_links(); ?>
    <?php veyrax_ui_kit(); ?>
    <script src="assets/js/vx-cache.js?v=<?php echo VEYRAX_ASSET_VERSION; ?>" defer></script>
    <?php veyrax_theme_bootstrap($adminUiColor, $adminUiScale, true); ?>
    <style>
        @font-face { font-family: 'AppFont'; src: url('fonts/<?php echo $font_text; ?>.ttf') format('truetype'); font-weight: normal; }
        @font-face { font-family: 'AppHeading'; src: url('fonts/<?php echo $font_heading; ?>.ttf') format('truetype'); font-weight: bold; }
        
        :root { --bg: var(--v-bg-0, #0f172a); --panel: var(--v-surface, #1e293b); --border: var(--v-border, #334155); --primary: var(--v-primary, #6366f1); --primary-hover: var(--v-primary-active, #4f46e5); --danger: #ef4444; --success: #10b981; --text: #f8fafc; --text-muted: #94a3b8; }
        
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body { background-color: var(--v-bg-0, #0f172a); background-image: radial-gradient(880px 480px at 50% -12%, var(--v-glow-a, rgba(99,102,241,0.2)), transparent 62%), linear-gradient(180deg, var(--v-bg-1, #131a2b) 0%, var(--v-bg-0, #0b1020) 55%, var(--v-bg-2, #080b14) 100%); background-attachment: fixed; color: var(--text); font-family: 'AppFont', Tahoma, sans-serif; margin: 0; padding: 0; padding-bottom: 80px; overflow-x: hidden; }
        
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        
        .header { background: var(--v-header, rgba(30, 41, 59, 0.95)); backdrop-filter: blur(20px); border-bottom: 1px solid var(--border); padding: 15px 30px; position: sticky; top: 0; z-index: 100; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 10px 40px -10px rgba(0,0,0,0.5); border-radius: 0 0 25px 25px; margin-bottom: 30px; }
        .header h1 { margin: 0; font-size: 1.4rem; color: #fff; font-family: 'AppHeading'; text-shadow: 0 0 20px var(--v-primary-glow, rgba(99,102,241,0.5)); }
        .header-left { display: flex; align-items: center; gap: 14px; min-width: 0; }
        .admin-nav-btn { width: 44px; height: 44px; flex: 0 0 44px; border-radius: 13px; border: 1px solid rgba(99,102,241,0.35); background: rgba(99,102,241,0.12); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; cursor: pointer; transition: background .18s, border-color .18s; }
        .admin-nav-btn span { width: 18px; height: 2px; border-radius: 2px; background: #a5b4fc; transition: background .18s; }
        .admin-nav-btn:hover { background: rgba(99,102,241,0.24); border-color: rgba(99,102,241,0.6); }
        .admin-nav-btn:hover span { background: #fff; }
        .admin-nav-backdrop { position: fixed; inset: 0; background: rgba(2,6,23,0.6); backdrop-filter: blur(3px); z-index: 9500; opacity: 0; visibility: hidden; transition: opacity .25s, visibility .25s; }
        .admin-nav-backdrop.open { opacity: 1; visibility: visible; }
        .admin-nav-drawer { position: fixed; top: 0; right: 0; height: 100%; width: min(84vw, 310px); z-index: 9600; background: rgba(15,23,42,0.99); border-left: 1px solid rgba(99,102,241,0.25); box-shadow: -20px 0 60px rgba(0,0,0,0.6); transform: translateX(102%); transition: transform .28s cubic-bezier(.2,.8,.2,1); display: flex; flex-direction: column; }
        .admin-nav-drawer.open { transform: translateX(0); }
        .admin-nav-head { display: flex; align-items: center; justify-content: space-between; padding: 18px 16px 14px; border-bottom: 1px solid rgba(148,163,184,0.12); color: #fff; font-family: 'AppHeading'; font-size: 15px; }
        .admin-nav-close { width: 30px; height: 30px; border-radius: 9px; border: 1px solid rgba(148,163,184,0.2); background: rgba(148,163,184,0.08); color: #cbd5e1; cursor: pointer; }
        .admin-nav-list { padding: 12px; overflow-y: auto; display: flex; flex-direction: column; gap: 6px; }
        .admin-nav-item { display: flex; align-items: center; gap: 10px; width: 100%; padding: 11px 12px; border-radius: 12px; border: 1px solid transparent; background: transparent; color: #cbd5e1; font-family: inherit; font-size: 12.5px; text-align: right; cursor: pointer; transition: background .16s, color .16s, border-color .16s; }
        .admin-nav-item:hover { background: rgba(99,102,241,0.12); color: #fff; }
        .admin-nav-item.active { background: rgba(99,102,241,0.18); border-color: rgba(99,102,241,0.45); color: #fff; }
        .admin-nav-item .dot { width: 6px; height: 6px; border-radius: 50%; background: #6366f1; flex: 0 0 6px; }
        .card { scroll-margin-top: 92px; }
        @media (max-width: 640px) {
            .header { padding: 12px 16px; border-radius: 0 0 18px 18px; }
            .header h1 { font-size: 1.05rem; }
            .admin-nav-btn { width: 40px; height: 40px; flex-basis: 40px; }
        }
        
        .btn { padding: 12px 20px; border-radius: 14px; border: none; cursor: pointer; color: #fff; font-family: 'AppFont'; font-size: 0.95rem; transition: all 0.3s ease; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; justify-content: center; font-weight: bold; white-space: nowrap; box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
        .btn:active { transform: scale(0.96); }
        .btn-primary { background: linear-gradient(135deg, var(--primary), var(--v-primary-hover, #818cf8)); }
        .btn-primary:hover { box-shadow: 0 8px 25px rgba(99,102,241,0.4); }
        .btn-danger { background: linear-gradient(135deg, var(--danger), #f87171); }
        .btn-danger:hover { box-shadow: 0 8px 25px rgba(239,68,68,0.4); }
        .btn-ghost { background: rgba(255,255,255,0.05); border: 1px solid var(--border); color: var(--text-muted); box-shadow: none; }
        .btn-ghost:hover { background: rgba(255,255,255,0.1); color: #fff; }
        .btn-success { background: linear-gradient(135deg, var(--success), #34d399); box-shadow: 0 5px 15px rgba(16, 185, 129, 0.2); }
        .btn-success:hover { box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4); }
        .btn-sm { padding: 6px 14px; font-size: 0.8rem; border-radius: 10px; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: linear-gradient(145deg, var(--v-surface-2, #1e293b), var(--v-surface, #172033)); border: 1px solid var(--border); padding: 25px; border-radius: 20px; text-align: center; position: relative; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.2); transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); border-color: var(--primary); }
        .stat-value { font-size: 2.2rem; font-family: 'AppHeading'; display: block; margin-bottom: 5px; background: linear-gradient(to right, #fff, #94a3b8); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .stat-label { font-size: 0.9rem; color: var(--text-muted); letter-spacing: 0.5px; }

        .card { background: var(--v-surface-2, #1e293b); border: 1px solid var(--border); border-radius: 24px; padding: 30px; margin-bottom: 25px; overflow: hidden; position: relative; box-shadow: 0 20px 50px -10px rgba(0,0,0,0.3); }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 20px; }
        .card-title { margin: 0; font-size: 1.3rem; border-right: 5px solid var(--primary); padding-right: 15px; color: #fff; font-family: 'AppHeading'; }

        .action-box { background: rgba(0,0,0,0.2); border-radius: 18px; padding: 20px; margin-bottom: 20px; border: 1px dashed var(--border); display: flex; flex-direction: column; gap: 15px; }
        .action-box h4 { margin: 0 0 10px; color: var(--text-muted); font-size: 0.95rem; }

        .form-control { background: var(--v-input, #0f172a); border: 1px solid var(--border); color: var(--text); padding: 15px; border-radius: 14px; width: 100%; outline: none; font-family: inherit; transition: 0.3s; font-size: 1rem; }
        .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.15); }
        
        .input-group { display: flex; gap: 12px; flex-wrap: wrap; }
        .input-group > * { flex: 1; min-width: 200px; }
        .input-group button { flex: 0 0 auto; }

        .file-pick { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; width: 100%; background: var(--v-input, #0f172a); border: 1px dashed var(--border); border-radius: 14px; padding: 10px 14px; }
        .file-pick input[type="file"] { position: absolute; width: 1px; height: 1px; opacity: 0; overflow: hidden; }
        .file-pick .btn { width: auto; flex: 0 0 auto; padding: 10px 16px; }
        .file-pick-name { color: var(--text-muted); font-size: 0.85rem; flex: 1; min-width: 100px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        .table-responsive { overflow-x: auto; border-radius: 18px; border: 1px solid var(--border); background: var(--v-bg-0, #0f172a); -ms-overflow-style: none; scrollbar-width: none; }
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
        .modal-content { background: linear-gradient(145deg, var(--v-surface-2, #1e293b), var(--v-bg-1, #172033)); width: 100%; max-width: 450px; padding: 40px; border-radius: 30px; border: 1px solid rgba(255,255,255,0.1); text-align: center; animation: popIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1); box-shadow: 0 25px 80px rgba(0,0,0,0.6); display:flex; flex-direction:column; max-height:85vh; }
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
        /* ── SMS settings card ─────────────────────────────────────────────── */
        #smsCard .sms-status { display: inline-flex; align-items: center; gap: 8px; padding: 7px 14px; border-radius: 999px; background: rgba(148,163,184,0.08); border: 1px solid var(--border); font-size: 0.82rem; color: var(--text-muted); }
        #smsCard .sms-status #smsStatusDot { width: 9px; height: 9px; border-radius: 50%; background: #64748b; display: inline-block; box-shadow: 0 0 0 3px rgba(148,163,184,0.14); transition: background .3s; }
        #smsCard .sms-label { margin: 0 0 8px; color: var(--text-muted); font-size: 0.9rem; font-family: 'AppFont', Tahoma, sans-serif; font-weight: normal; }
        #smsCard .sms-label small { opacity: .7; font-size: 0.78rem; }
        #smsCard .sms-hint { font-size: 0.85rem; color: var(--text-muted); margin: 0; line-height: 1.9; }
        #smsCard .form-control { font-family: 'AppFont', Tahoma, sans-serif; }
        #smsCard select.form-control { cursor: pointer; }
        #smsCard .sms-mono { letter-spacing: .3px; }
        #smsCard .sms-toggles { display: flex; flex-direction: column; gap: 12px; margin: 4px 0; }
        #smsCard .sms-switch, #aiCard .sms-switch { display: flex; align-items: center; gap: 12px; cursor: pointer; padding: 12px 14px; border-radius: 14px; background: rgba(148,163,184,0.05); border: 1px solid rgba(148,163,184,0.12); transition: border-color .2s, background .2s; }
        #smsCard .sms-switch:hover, #aiCard .sms-switch:hover { border-color: var(--v-primary-border, rgba(99,102,241,0.4)); background: rgba(148,163,184,0.08); }
        #smsCard .sms-switch input, #aiCard .sms-switch input { display: none; }
        #smsCard .sms-switch .sms-track, #aiCard .sms-switch .sms-track { flex: 0 0 46px; width: 46px; height: 26px; border-radius: 999px; background: rgba(100,116,139,0.35); position: relative; transition: background .25s; }
        #smsCard .sms-switch .sms-track::after, #aiCard .sms-switch .sms-track::after { content: ''; position: absolute; top: 3px; right: 3px; width: 20px; height: 20px; border-radius: 50%; background: #e2e8f0; transition: transform .25s cubic-bezier(.4,0,.2,1); box-shadow: 0 2px 6px rgba(0,0,0,0.35); }
        #smsCard .sms-switch input:checked + .sms-track, #aiCard .sms-switch input:checked + .sms-track { background: #10b981; }
        #smsCard .sms-switch input:checked + .sms-track-dev, #aiCard .sms-switch input:checked + .sms-track-dev { background: #f59e0b; }
        #smsCard .sms-switch input:checked + .sms-track::after, #aiCard .sms-switch input:checked + .sms-track::after { transform: translateX(-20px); }
        #smsCard .sms-sw-text, #aiCard .sms-sw-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        #smsCard .sms-sw-text b, #aiCard .sms-sw-text b { font-size: 0.9rem; color: #e2e8f0; font-weight: bold; }
        #smsCard .sms-sw-text small, #aiCard .sms-sw-text small { font-size: 0.78rem; color: var(--text-muted); line-height: 1.7; }
        #smsCard .sms-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
        #smsCard .sms-actions .form-control { max-width: 190px; }
        #smsCard .sms-divider { width: 1px; height: 34px; background: var(--border); margin: 0 4px; }
        #smsCard .sms-log-wrap { margin-top: 4px; }
        #smsCard .sms-log { max-height: 200px; overflow: auto; font-size: 0.8rem; color: #cbd5e1; background: rgba(0,0,0,0.25); border: 1px solid var(--border); border-radius: 14px; padding: 10px 14px; line-height: 2; }
        #smsCard .sms-log .sms-log-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; border-bottom: 1px solid rgba(148,163,184,0.1); padding: 4px 0; flex-wrap: wrap; }
        #smsCard .sms-log .sms-log-row:last-child { border-bottom: none; }
        #smsCard .sms-log .sms-log-ok { color: #34d399; }
        #smsCard .sms-log .sms-log-bad { color: #f87171; }
        #smsCard .sms-log .sms-log-time { color: #64748b; font-size: 0.75rem; }
        #smsCard .sms-lock { margin-top: 6px; padding-top: 16px; border-top: 1px dashed var(--border); }
        @media (max-width: 520px) {
            #smsCard .sms-actions { flex-direction: column; align-items: stretch; }
            #smsCard .sms-actions .form-control { max-width: none; }
            #smsCard .sms-divider { display: none; }
            #smsCard .card-header { flex-wrap: wrap; gap: 8px; }
        }
        /* ── AI assistant card ─────────────────────────────────────────────── */
        #aiCard .ai-enable-row { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 4px; padding: 14px 16px; border-radius: 14px; background: rgba(148,163,184,0.05); border: 1px solid rgba(148,163,184,0.12); }
        #aiCard .ai-tuning { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 4px; align-items: stretch; }
        #aiCard .ai-tuning > div { flex: 0 1 auto; min-width: 150px; }
        #aiCard .ai-mention-box { flex: 1 1 220px !important; min-width: 220px; display: flex; align-items: center; padding: 10px 14px; border-radius: 14px; background: rgba(148,163,184,0.05); border: 1px solid rgba(148,163,184,0.12); }
        #aiCard .form-control { font-family: 'AppFont', Tahoma, sans-serif; }
        #aiCard .sms-mono { letter-spacing: .3px; }
        #aiCard .ai-key-input { padding-left: 46px; }
        #aiCard .ai-eye { position: absolute; left: 8px; top: 50%; transform: translateY(-50%); width: 34px; height: 34px; display: flex; align-items: center; justify-content: center; background: none; border: none; cursor: pointer; color: #64748b; font-size: 16px; border-radius: 8px; transition: color .2s, background .2s; }
        #aiCard .ai-eye:hover { color: #a5b4fc; background: rgba(99,102,241,0.12); }
        @media (max-width: 640px) {
            #aiCard .ai-tuning { flex-direction: row; }
            #aiCard .ai-tuning > div { flex: 1 1 40%; }
        }
        #aiCard .ai-limits { margin-top: 6px; padding: 14px 16px; border-radius: 14px; background: rgba(148,163,184,0.05); border: 1px solid rgba(148,163,184,0.12); }
        #aiCard .ai-limits-head small { display: block; margin-top: 4px; font-size: 0.78rem; color: var(--text-muted); line-height: 1.8; }
        #aiCard .ai-limits-fields { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 10px; }

        html { font-size: clamp(14px, calc(16px * var(--v-fs, 1)), 21px); }
        .ap-swatches { display: grid; grid-template-columns: repeat(8, minmax(0, 1fr)); gap: 9px; }
        .ap-sw { aspect-ratio: 1 / 1; border-radius: 12px; border: 2px solid transparent; cursor: pointer; padding: 0; transition: transform .12s ease, border-color .15s ease, box-shadow .15s ease; -webkit-tap-highlight-color: transparent; }
        .ap-sw:active { transform: scale(0.9); }
        .ap-sw.active { border-color: #fff; box-shadow: 0 0 0 3px var(--v-primary-soft, rgba(99,102,241,0.3)), 0 6px 16px -6px rgba(0,0,0,0.7); }
        .ap-row { display: flex; align-items: center; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
        .ap-custom { flex: 1 1 160px; display: flex; align-items: center; gap: 10px; height: 46px; padding: 0 14px; border-radius: 13px; border: 1px dashed var(--v-border-strong, rgba(255,255,255,0.18)); background: rgba(255,255,255,0.04); color: var(--text-muted); font-size: 0.9rem; cursor: pointer; min-width: 0; }
        .ap-custom:hover { border-color: var(--v-primary-border, rgba(99,102,241,0.4)); color: #fff; }
        .ap-custom input[type="color"] { position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
        .ap-custom-dot { width: 24px; height: 24px; flex: 0 0 24px; border-radius: 8px; background: var(--primary, #6366f1); box-shadow: inset 0 0 0 1px rgba(255,255,255,0.35); }
        .ap-fs { display: flex; align-items: center; gap: 12px; direction: ltr; }
        .ap-fs-btn { width: 48px; height: 44px; flex: 0 0 48px; border-radius: 12px; border: 1px solid var(--v-border-strong, rgba(255,255,255,0.14)); background: rgba(255,255,255,0.05); color: #dbe3ee; font-family: 'AppHeading'; font-size: 0.9rem; cursor: pointer; transition: background .15s, transform .1s; }
        .ap-fs-btn:hover { background: var(--v-primary-soft, rgba(99,102,241,0.16)); }
        .ap-fs-btn:active { transform: scale(0.94); }
        #apFsRange { flex: 1 1 auto; -webkit-appearance: none; appearance: none; height: 6px; border-radius: 99px; background: linear-gradient(90deg, var(--primary, #6366f1) 0%, var(--primary, #6366f1) var(--ap-fill, 25%), rgba(148,163,184,0.22) var(--ap-fill, 25%)); outline: none; min-width: 0; }
        #apFsRange::-webkit-slider-thumb { -webkit-appearance: none; width: 24px; height: 24px; border-radius: 50%; background: #fff; border: 3px solid var(--primary, #6366f1); box-shadow: 0 3px 10px rgba(0,0,0,0.45); cursor: pointer; }
        #apFsRange::-moz-range-thumb { width: 18px; height: 18px; border-radius: 50%; background: #fff; border: 3px solid var(--primary, #6366f1); box-shadow: 0 3px 10px rgba(0,0,0,0.45); cursor: pointer; }
        .ap-preview { margin-top: 14px; padding: 14px; border-radius: 14px; background: rgba(255,255,255,0.04); border: 1px dashed var(--v-border-strong, rgba(255,255,255,0.16)); }
        .ap-preview-name { font-size: 0.72rem; color: var(--text-muted); margin-bottom: 6px; }
        .ap-preview-text { font-size: 1rem; line-height: 1.7; color: #e2e8f0; }
        .ap-hint { margin: 12px 0 0; font-size: 0.8rem; color: var(--text-muted); line-height: 1.8; }
        .ap-value { font-family: 'AppHeading'; font-size: 0.85rem; color: var(--v-accent, #a5b4fc); }

        .net-modes { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
        .net-mode { position: relative; display: flex; gap: 10px; align-items: flex-start; padding: 13px 14px; border-radius: 14px; border: 1px solid var(--v-border-strong, rgba(255,255,255,0.12)); background: rgba(255,255,255,0.03); cursor: pointer; transition: border-color .18s, background .18s; }
        .net-mode:hover { background: rgba(255,255,255,0.06); }
        .net-mode input { position: absolute; opacity: 0; pointer-events: none; }
        .net-dot { flex: 0 0 18px; width: 18px; height: 18px; margin-top: 2px; border-radius: 50%; border: 2px solid rgba(148,163,184,0.45); position: relative; transition: border-color .18s; }
        .net-dot::after { content: ''; position: absolute; inset: 3px; border-radius: 50%; background: var(--v-primary, #6366f1); transform: scale(0); transition: transform .18s; }
        .net-mode input:checked ~ .net-dot { border-color: var(--v-primary, #6366f1); }
        .net-mode input:checked ~ .net-dot::after { transform: scale(1); }
        .net-mode input:checked ~ .net-body .net-title { color: var(--v-accent, #a5b4fc); }
        .net-mode:has(input:checked) { border-color: var(--v-primary-border, rgba(99,102,241,0.45)); background: var(--v-primary-soft, rgba(99,102,241,0.1)); }
        .net-body { min-width: 0; }
        .net-title { display: block; font-family: 'AppHeading'; font-size: 0.9rem; color: #e2e8f0; margin-bottom: 3px; }
        .net-desc { display: block; font-size: 0.74rem; color: var(--text-muted); line-height: 1.7; }
        .net-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 14px; }
        .net-field { flex: 1 1 160px; min-width: 140px; }
        .net-field label { display: block; font-size: 0.76rem; color: var(--text-muted); margin-bottom: 6px; }
        .net-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(96px, 1fr)); gap: 8px; margin-top: 14px; }
        .net-stat { padding: 10px 12px; border-radius: 12px; background: rgba(255,255,255,0.035); border: 1px solid var(--v-border, rgba(255,255,255,0.08)); text-align: center; }
        .net-stat b { display: block; font-family: 'AppHeading'; font-size: 1rem; color: #e2e8f0; margin-bottom: 2px; }
        .net-stat span { font-size: 0.68rem; color: var(--text-muted); }
        @media (max-width: 520px) { .net-modes { grid-template-columns: 1fr; } }
        @media (max-width: 520px) { .ap-swatches { grid-template-columns: repeat(6, minmax(0, 1fr)); } .card { padding: 20px; } }
        
        #paginationControls .btn { transition: 0.2s; }
        #paginationControls .btn-primary { background: var(--primary); color: #fff; box-shadow: 0 2px 10px rgba(99,102,241,0.3); border-color: var(--primary); }

        /* ── Media manager ────────────────────────────────────────────────── */
        .media-hint { font-size: 0.85rem; color: var(--text-muted); line-height: 1.85; margin: 0 0 14px; }
        .media-toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 14px; }
        .media-toolbar .form-control { width: auto; flex: 1 1 190px; min-width: 140px; }
        .media-toolbar select.form-control { flex: 0 0 auto; width: 178px; min-width: 0; }
        /* The refresh button must never shrink — flex would squeeze its label
           out of the pill on narrow cards. */
        .media-toolbar .btn { flex: 0 0 auto; padding: 10px 16px; font-size: 0.88rem; }
        .media-switch {
            display: inline-flex; align-items: center; gap: 7px; padding: 9px 13px; border-radius: 12px;
            border: 1px solid var(--border); background: rgba(255,255,255,0.03);
            font-size: 0.82rem; color: var(--text-muted); cursor: pointer; white-space: nowrap; user-select: none;
        }
        .media-switch input { accent-color: var(--primary); width: 15px; height: 15px; cursor: pointer; }
        .media-chip {
            font-size: 0.74rem; padding: 4px 11px; border-radius: 999px; white-space: nowrap;
            background: rgba(99,102,241,0.14); border: 1px solid rgba(99,102,241,0.3); color: #a5b4fc;
        }
        #mediaCard table { min-width: 860px; }
        #mediaCard th, #mediaCard td { white-space: nowrap; }
        .media-type {
            display: inline-flex; align-items: center; gap: 6px; font-size: 0.78rem;
            padding: 4px 10px; border-radius: 9px; border: 1px solid var(--border);
            background: rgba(255,255,255,0.04);
        }
        .media-type img { width: 15px; height: 15px; object-fit: contain; flex: 0 0 auto; }
        .media-size { unicode-bidi: isolate; direction: ltr; display: inline-block; font-size: 0.8rem; }
        .media-type.video { color: #93c5fd; border-color: rgba(59,130,246,0.35); background: rgba(59,130,246,0.12); }
        .media-type.image { color: #86efac; border-color: rgba(34,197,94,0.35); background: rgba(34,197,94,0.12); }
        .media-type.voice { color: #fcd34d; border-color: rgba(245,158,11,0.35); background: rgba(245,158,11,0.12); }
        .media-name { max-width: 190px; overflow: hidden; text-overflow: ellipsis; display: inline-block; vertical-align: middle; font-size: 0.82rem; }
        .media-state { font-size: 0.76rem; padding: 3px 9px; border-radius: 999px; }
        .media-state.live { color: #86efac; background: rgba(34,197,94,0.12); border: 1px solid rgba(34,197,94,0.3); }
        .media-state.gone { color: #fca5a5; background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.3); }
        .media-del-btn {
            border: 1px solid rgba(239,68,68,0.4); background: rgba(239,68,68,0.12); color: #fca5a5;
            padding: 6px 11px; border-radius: 10px; cursor: pointer; font-family: inherit;
            font-size: 0.78rem; transition: 0.18s; white-space: nowrap;
            display: inline-flex; align-items: center; gap: 6px; line-height: 1;
        }
        .media-del-btn img { width: 14px; height: 14px; object-fit: contain; }
        .media-del-btn:hover { background: rgba(239,68,68,0.25); color: #fff; box-shadow: 0 4px 14px rgba(239,68,68,0.25); }
        .media-del-btn:disabled { opacity: 0.4; cursor: not-allowed; box-shadow: none; }
        .media-empty { text-align: center; color: var(--text-muted); padding: 26px 10px !important; font-size: 0.88rem; }
        .media-footer { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between; margin-top: 14px; }
        .media-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        /* The "delete everything matching the filter" action. Deliberately the
           loudest control on the card: it is the one button here that can wipe
           a whole library in a single click. */
        .media-btn-nuke {
            background: linear-gradient(135deg, #b91c1c, #ef4444);
            color: #fff; border: 1px solid rgba(255,255,255,0.18);
            box-shadow: 0 6px 20px -6px rgba(239,68,68,0.65), inset 0 1px 0 rgba(255,255,255,0.22);
            font-weight: 700; letter-spacing: 0.2px;
        }
        .media-btn-nuke:hover { background: linear-gradient(135deg, #dc2626, #f87171); box-shadow: 0 10px 26px -6px rgba(239,68,68,0.8), inset 0 1px 0 rgba(255,255,255,0.28); transform: translateY(-1px); }
        .media-btn-nuke:active { transform: translateY(1px) scale(0.99); box-shadow: inset 0 2px 6px rgba(0,0,0,0.45); }
        .media-btn-nuke[disabled] { opacity: 0.5; cursor: not-allowed; box-shadow: none; transform: none; }
        .media-footer .btn { padding: 10px 18px; font-size: 0.86rem; }
        .media-pager { display: flex; gap: 7px; align-items: center; flex-wrap: wrap; }
        .media-pager button {
            border: 1px solid var(--border); background: rgba(255,255,255,0.04); color: var(--text-muted);
            min-width: 34px; height: 34px; border-radius: 10px; cursor: pointer; font-family: inherit; font-size: 0.82rem;
        }
        .media-pager button.active { background: rgba(99,102,241,0.25); border-color: rgba(99,102,241,0.6); color: #c7d2fe; }
        .media-pager button:disabled { opacity: 0.35; cursor: not-allowed; }
        #mediaCard input[type="checkbox"] { accent-color: var(--primary); width: 15px; height: 15px; cursor: pointer; }

        @media (max-width: 768px) {
            .header { padding: 15px; margin-bottom: 20px; border-radius: 0 0 20px 20px; flex-direction: column; gap: 10px; text-align: center; }
            .header h1 { font-size: 1.1rem; }
            .btn-header { width: auto; margin: 0 auto; }
            .btn-header span { display: inline; }
            .card { padding: 20px; border-radius: 20px; }
            .action-box { padding: 15px; }
            .btn { width: 100%; }
            .input-group > * { min-width: 100%; }
            .stat-value { font-size: 1.8rem; }
            #toast { bottom: 20px; font-size: 0.85rem; padding: 10px 20px; }
        }
    </style>
<style id="vFontFaces"><?php echo veyrax_font_css($pdo); ?></style>
</head>
<body>

<div id="toast"></div>

<?php if ($isLimitedAdmin): ?>
<div style="max-width:1280px;margin:14px auto 0;padding:0 20px;">
    <div style="display:flex;align-items:center;gap:10px;background:linear-gradient(140deg,rgba(245,158,11,0.14),rgba(245,158,11,0.05));border:1px solid rgba(245,158,11,0.35);border-right:3px solid #f59e0b;border-radius:14px;padding:11px 15px;color:#fde68a;font-size:0.88rem;">
        <span style="font-size:18px;">👁</span>
        <span>شما با سطح دسترسی <b>«محدود»</b> وارد شده‌اید: کاربران، نشست‌ها، گزارش‌ها، رسانه و بلاک در دسترس است — تنظیمات، بکاپ و دستیار فقط با سطح «کامل».</span>
    </div>
</div>
<?php endif; ?>

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
            <select id="banDuration" class="form-control" style="background:var(--v-input, #0f172a)">
                <option value="5">۵ دقیقه</option>
                <option value="10">۱۰ دقیقه</option>
                <option value="perm">دائمی</option>
            </select>
        </div>
        <div style="margin-bottom:25px; text-align:right;">
            <label for="banReason" style="color:#cbd5e1; font-size:0.9rem; margin-bottom:8px; display:block;">
                دلیل مسدودیت <span style="color:#64748b; font-weight:normal">(به خود کاربر نمایش داده می‌شود)</span>
            </label>
            <textarea id="banReason" class="form-control" rows="2" maxlength="255"
                      placeholder="مثلاً: ارسال پیام تبلیغاتی و توهین در گفتگوی عمومی"
                      style="background:var(--v-input, #0f172a); resize:vertical; line-height:1.8;"></textarea>
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
        <input type="text" id="inviteUsername" class="form-control" placeholder="نام کاربری..." style="margin-bottom:25px; text-align:center; font-size:1.2rem; background:var(--v-input, #0f172a)">
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
    <div class="header-left">
        <button type="button" class="admin-nav-btn" id="adminNavBtn" onclick="toggleAdminNav()" aria-label="منوی بخش‌ها" title="پرش سریع به بخش‌ها">
            <span></span><span></span><span></span>
        </button>
        <h1>پنل مدیریت پیشرفته</h1>
    </div>
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

    <div class="card" id="appearanceCard">
        <div class="card-header">
            <h2 class="card-title">ظاهر و تم پنل</h2>
        </div>
        <div class="settings-grid">
            <div class="action-box">
                <h4>رنگ اصلی</h4>
                <div class="ap-swatches" id="apSwatches"></div>
                <div class="ap-row">
                    <label class="ap-custom" for="apColorInput">
                        <input type="color" id="apColorInput" value="#6366f1" oninput="apPickCustom(this.value)">
                        <span class="ap-custom-dot" id="apCustomDot"></span>
                        <span>رنگ دلخواه</span>
                    </label>
                    <button type="button" class="btn btn-ghost" onclick="apReset()">بازگشت به پیش‌فرض</button>
                </div>
                <p class="ap-hint">پس‌زمینه، کارت‌ها و دکمه‌های پنل با همین رنگ هماهنگ می‌شوند.</p>
            </div>
            <div class="action-box">
                <h4>اندازه فونت <span class="ap-value" id="apFsLabel">۱۰۰٪</span></h4>
                <div class="ap-fs">
                    <button type="button" class="ap-fs-btn" onclick="apStepFont(-1)" aria-label="کوچک‌تر">A−</button>
                    <input type="range" id="apFsRange" min="0" max="4" step="1" value="1" oninput="apSetFont(this.value)" aria-label="اندازه فونت">
                    <button type="button" class="ap-fs-btn" onclick="apStepFont(1)" aria-label="بزرگ‌تر">A+</button>
                </div>
                <div class="ap-preview">
                    <div class="ap-preview-name">پیش‌نمایش</div>
                    <div class="ap-preview-text">این متن با اندازهٔ فونت انتخاب‌شده نمایش داده می‌شود.</div>
                </div>
            </div>
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
                <div class="file-pick">
                    <input type="file" id="backupFileInput" accept=".sql">
                    <label for="backupFileInput" class="btn btn-ghost">📁 انتخاب فایل .sql</label>
                    <span id="backupFileName" class="file-pick-name">فایلی انتخاب نشده</span>
                </div>
                <button onclick="importBackup()" class="btn btn-danger" style="width:100%">آپلود و ایمپورت 📤</button>
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

            <div class="action-box" style="grid-column: 1 / -1; border-color:rgba(239, 68, 68, 0.3);">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;">
                    <div>
                        <h4 style="margin:0; color:#fff;">وضعیت قفل کل سایت (Maintenance)</h4>
                        <div id="siteLockStatusText" style="font-size:0.9rem; color:var(--text-muted); margin-top:5px">در حال بررسی...</div>
                    </div>
                    <button id="siteLockBtn" onclick="toggleSiteLock()" class="btn btn-danger">تغییر وضعیت</button>
                </div>
                <div style="margin-top:16px; padding-top:16px; border-top:1px dashed rgba(255,255,255,0.09);">
                    <label for="siteLockReason" style="display:block; font-size:0.9rem; color:#cbd5e1; margin-bottom:8px;">
                        دلیلی که به کاربران نمایش داده می‌شود
                    </label>
                    <textarea id="siteLockReason" class="form-control" rows="3" maxlength="500"
                              placeholder="مثلاً: به‌دلیل به‌روزرسانی سرور، سایت تا ساعت ۲۲ در دسترس نیست."
                              oninput="this.dataset.dirty='1'"
                              style="background:var(--v-input, #0f172a); resize:vertical; line-height:1.8;"></textarea>
                    <div style="display:flex; align-items:center; gap:10px; margin-top:10px; flex-wrap:wrap;">
                        <button onclick="saveSiteLockReason()" class="btn btn-primary">ذخیره دلیل</button>
                        <span id="siteLockReasonMsg" style="font-size:0.85rem; color:var(--text-muted);"></span>
                    </div>
                </div>
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

    <div class="card" id="brandingCard">
        <div class="card-header">
            <h2 class="card-title">برندینگ و ظاهر</h2>
        </div>
        <div class="settings-grid">
            <div class="action-box" style="grid-column: 1 / -1;">
                <h4 style="margin:0 0 6px; color:#fff;">نام برند، زیرعنوان و نسخه فوتر</h4>
                <div style="font-size:0.85rem; color:var(--text-muted); margin-bottom:12px;">نام برند و زیرعنوان در سایدبار، عنوان صفحه و صفحه ورود اعمال می‌شوند؛ «متن نسخه» در فوتر صفحه ورود نمایش داده می‌شود (خالی = نسخه خودکار خود پروژه).</div>
                <div class="input-group" style="flex-wrap:wrap;">
                    <input type="text" id="brandNameInput" class="form-control" maxlength="40" placeholder="نام برند" value="<?php echo htmlspecialchars($site_brand_fa, ENT_QUOTES, 'UTF-8'); ?>" style="max-width:220px;">
                    <input type="text" id="brandTaglineInput" class="form-control" maxlength="60" placeholder="زیرعنوان" value="<?php echo htmlspecialchars($brand_tagline, ENT_QUOTES, 'UTF-8'); ?>" style="max-width:280px;">
                    <input type="text" id="siteVersionInput" class="form-control" maxlength="20" placeholder="مثلاً ۱.۸.۱ (خالی = خودکار)" value="<?php echo htmlspecialchars($site_version_label, ENT_QUOTES, 'UTF-8'); ?>" style="max-width:190px;">
                </div>
            </div>

            <div class="action-box" style="grid-column: 1 / -1;">
                <h4 style="margin:0 0 6px; color:#fff;">لوگوی سایدبار</h4>
                <div style="font-size:0.85rem; color:var(--text-muted); margin-bottom:12px;">PNG / JPG / WEBP / GIF — حداکثر ۲ مگابایت. تصویر خودکار داخل کادر ۳۶×۳۶ فیت می‌شود.</div>
                <div style="display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
                    <div id="brandLogoPreview" style="width:64px;height:64px;border-radius:18px;overflow:hidden;background:rgba(99,102,241,0.15);border:1px solid rgba(99,102,241,0.35);display:flex;align-items:center;justify-content:center;font-size:24px;color:#a5b4fc;flex-shrink:0;">
                        <?php if ($brand_logo): ?><img src="<?php echo htmlspecialchars($brand_logo, ENT_QUOTES, 'UTF-8'); ?>" style="width:100%;height:100%;object-fit:contain;display:block;"><?php else: ?><?php echo htmlspecialchars(mb_substr($site_brand_fa, 0, 1, 'UTF-8'), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                    </div>
                    <input type="file" id="brandLogoInput" accept="image/png,image/jpeg,image/webp,image/gif" style="display:none" onchange="previewBrandLogo(this)">
                    <button type="button" class="btn btn-primary" onclick="document.getElementById('brandLogoInput').click()">انتخاب تصویر</button>
                    <?php if ($brand_logo): ?>
                    <button type="button" class="btn btn-danger" onclick="resetBrandLogo()">حذف لوگو</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-primary" onclick="saveBranding()">ذخیره برندینگ</button>
                </div>
            </div>
        </div>
    </div>

    <div class="card" id="adminIdentityCard" style="<?php echo $isFullAdmin ? '' : 'display:none;'; ?>">
        <div class="card-header"><h2 class="card-title">هویت مدیر</h2></div>
        <div class="action-box">
            <h4>نام نمایشی مدیر</h4>
            <p class="ap-hint">این نام روی پیام‌های مدیر در چت، پاسخ‌ها و فهرست ادمین‌ها نمایش داده می‌شود. تغییر بلافاصله بعد از ذخیره اعمال می‌شود.</p>
            <div class="input-group">
                <input type="text" id="adminDisplayNameInput" class="form-control" maxlength="50" placeholder="مثلاً مدیر سیستم" value="<?php echo htmlspecialchars($admin_display_name, ENT_QUOTES, 'UTF-8'); ?>" style="max-width:300px;">
                <button onclick="saveAdminDisplayName()" class="btn btn-primary">💾 ذخیره نام نمایشی</button>
            </div>
        </div>
    </div>

    <?php $uiFontFamily = veyrax_setting($pdo, 'ui_font_family', 'default'); $uiFontWeight = (int)veyrax_setting($pdo, 'ui_font_weight', '400'); ?>
    <div class="card" id="socialCard" style="<?php echo $isFullAdmin ? '' : 'display:none;'; ?>">
        <div class="card-header"><h2 class="card-title">شبکه‌های اجتماعی (آیکون‌های هدر چت)</h2></div>
        <div class="action-box">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
                <div>
                    <h4 style="margin:0;color:#fff;">نمایش آیکون‌ها در هدر چت</h4>
                    <div style="font-size:0.85rem;color:var(--text-muted);margin-top:4px;">آیکون‌های سه‌بعدی GitHub / وب‌سایت / تلگرام کنار جستجو نمایش داده می‌شوند. لینک خالی = آیکون کم‌رنگ و غیرقابل کلیک.</div>
                </div>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="socialEnabled" style="width:18px;height:18px;">
                    <span style="font-size:0.9rem;color:#cbd5e1;">فعال</span>
                </label>
            </div>
            <div class="net-row" style="display:flex;gap:10px;flex-wrap:wrap;">
                <div class="net-field" style="flex:1;min-width:240px;">
                    <label style="display:flex;align-items:center;gap:7px;margin-bottom:6px;"><input type="checkbox" id="socialGithubOn" style="width:16px;height:16px;"> <b style="color:#e2e8f0;">GitHub</b></label>
                    <input type="url" id="socialGithubLink" class="form-control" dir="ltr" placeholder="https://github.com/username" maxlength="300">
                </div>
                <div class="net-field" style="flex:1;min-width:240px;">
                    <label style="display:flex;align-items:center;gap:7px;margin-bottom:6px;"><input type="checkbox" id="socialWebsiteOn" style="width:16px;height:16px;"> <b style="color:#e2e8f0;">وب‌سایت</b></label>
                    <input type="url" id="socialWebsiteLink" class="form-control" dir="ltr" placeholder="https://example.com" maxlength="300">
                </div>
                <div class="net-field" style="flex:1;min-width:240px;">
                    <label style="display:flex;align-items:center;gap:7px;margin-bottom:6px;"><input type="checkbox" id="socialTelegramOn" style="width:16px;height:16px;"> <b style="color:#e2e8f0;">تلگرام</b></label>
                    <input type="url" id="socialTelegramLink" class="form-control" dir="ltr" placeholder="https://t.me/username" maxlength="300">
                </div>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
                <button type="button" class="btn btn-primary" onclick="saveSocialSettings()">💾 ذخیره تنظیمات</button>
                <button type="button" class="btn btn-ghost" onclick="loadSocialSettings()">🔄 بازخوانی</button>
            </div>
        </div>
    </div>
    <div class="card" id="fontCard">
        <div class="card-header">
            <h2 class="card-title">فونت و تایپوگرافی</h2>
        </div>
        <div class="settings-grid">
            <div class="action-box" style="grid-column: 1 / -1;">
                <h4 style="margin:0 0 6px; color:#fff;">فونت کل سورس و چت</h4>
                <div style="font-size:0.85rem; color:var(--text-muted); margin-bottom:12px;">خانواده و وزن فونت روی همه‌ی صفحه‌ها (چت، ورود، پنل) اعمال می‌شود. تغییر بلافاصله بعد از ذخیره اعمال می‌شود.</div>
                <div class="input-group" style="flex-wrap:wrap;">
                    <select id="fontFamilySelect" class="form-control" style="max-width:200px;" onchange="updateFontWeights(false)">
                        <option value="default" <?php echo $uiFontFamily === 'default' ? 'selected' : ''; ?>>پیش‌فرض سایت</option>
                        <option value="dana" <?php echo $uiFontFamily === 'dana' ? 'selected' : ''; ?>>دانا — Dana</option>
                        <option value="kalameh" <?php echo $uiFontFamily === 'kalameh' ? 'selected' : ''; ?>>کلمه — Kalameh</option>
                    </select>
                    <select id="fontWeightSelect" class="form-control" style="max-width:180px;" onchange="applyFontPreview()"></select>
                    <button type="button" class="btn btn-primary" onclick="saveFont()">ذخیره فونت</button>
                </div>
                <div id="fontPreview" style="margin-top:14px; padding:16px 18px; border-radius:14px; background:rgba(15,23,42,0.6); border:1px solid rgba(148,163,184,0.15); color:#e2e8f0; font-size:16px; line-height:2;">
                    نمونه متن فارسی ۱۲۳۴۵۶۷۸۹۰ — ویراکس، چت امن و بلادرنگ.<br>
                    <span style="font-size:13px; color:#94a3b8;">The quick brown fox jumps over the lazy dog 0123456789</span>
                </div>
            </div>

            <div class="action-box" style="grid-column: 1 / -1;">
                <h4 style="margin:0 0 6px; color:#fff;">فونت متن پیام‌ها <span style="font-size:0.75rem; color:#a5b4fc; font-weight:normal;">(فقط ادمین)</span></h4>
                <div style="font-size:0.85rem; color:var(--text-muted); margin-bottom:12px;">
                    مستقل از فونت رابط کاربری است؛ فقط روی متن داخل حباب پیام‌ها اثر می‌گذارد. با «بولد» کردن، متن پیام‌ها ضخیم‌تر و خواناتر می‌شود.
                </div>
                <?php
                    $msgFontFamily = veyrax_setting($pdo, 'msg_font_family', 'default');
                    $msgFontWeight = (int)veyrax_setting($pdo, 'msg_font_weight', '400');
                    if (!in_array($msgFontWeight, [300,400,500,700,800,900], true)) $msgFontWeight = 400;
                ?>
                <div class="input-group" style="flex-wrap:wrap;">
                    <select id="msgFontFamilySelect" class="form-control" style="max-width:210px;" onchange="applyMsgFontPreview()">
                        <option value="default" <?php echo $msgFontFamily === 'default' ? 'selected' : ''; ?>>مثل فونت سایت</option>
                        <option value="dana" <?php echo $msgFontFamily === 'dana' ? 'selected' : ''; ?>>دانا — Dana</option>
                        <option value="kalameh" <?php echo $msgFontFamily === 'kalameh' ? 'selected' : ''; ?>>کلمه — Kalameh</option>
                    </select>
                    <select id="msgFontWeightSelect" class="form-control" style="max-width:190px;" onchange="applyMsgFontPreview()">
                        <option value="300" <?php echo $msgFontWeight === 300 ? 'selected' : ''; ?>>نازک</option>
                        <option value="400" <?php echo $msgFontWeight === 400 ? 'selected' : ''; ?>>معمولی</option>
                        <option value="500" <?php echo $msgFontWeight === 500 ? 'selected' : ''; ?>>متوسط</option>
                        <option value="700" <?php echo $msgFontWeight === 700 ? 'selected' : ''; ?>>بولد</option>
                        <option value="800" <?php echo $msgFontWeight === 800 ? 'selected' : ''; ?>>خیلی بولد</option>
                        <option value="900" <?php echo $msgFontWeight === 900 ? 'selected' : ''; ?>>سیاه (Black)</option>
                    </select>
                    <button type="button" class="btn btn-primary" onclick="saveMsgFont()">ذخیره فونت پیام</button>
                    <span id="msgFontMsg" style="font-size:0.85rem; color:var(--text-muted); align-self:center;"></span>
                </div>
                <div id="msgFontPreview" style="margin-top:14px; padding:14px 16px; border-radius:14px; background:rgba(15,23,42,0.6); border:1px solid rgba(148,163,184,0.15);">
                    <div style="display:flex; justify-content:flex-start;">
                        <div style="max-width:78%; padding:10px 14px; border-radius:18px 18px 18px 6px; background:#334155; color:#e6edf9;">
                            <span style="display:block; font-size:10px; color:#94a3b8; margin-bottom:4px;">کاربر</span>
                            <span id="msgFontSample" style="display:block; font-size:14px; line-height:1.75;">سلام! این یک نمونه از متن پیام است که با فونت انتخابی نمایش داده می‌شود.</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style id="fontPreviewFaces">
<?php foreach (['dana' => 'PrevDana', 'kalameh' => 'PrevKalameh'] as $fam => $prevName): ?>
<?php foreach (veyrax_font_map($fam) as $w => $file): ?>
@font-face{font-family:'<?php echo $prevName; ?>';src:url('assets/fonts/<?php echo $fam; ?>/<?php echo $file; ?>.ttf') format('truetype');font-weight:<?php echo $w; ?>;font-display:swap}
<?php endforeach; ?>
<?php endforeach; ?>
    </style>

    <div class="card" id="smsCard">
        <div class="card-header">
            <h2 class="card-title">تنظیمات پیامک</h2>
            <div class="sms-status">
                <span id="smsStatusDot"></span>
                <span id="smsStatusLabel">بررسی نشده</span>
            </div>
        </div>
        <div class="action-box">
            <p class="sms-hint">
                با اتصال درگاه پیامک، کد بازیابی رمز و کد ورود برای کاربران پیامک می‌شود و گزینه‌ی
                «بازنشانی با شماره موبایل» در صفحه‌ی ورود فعال خواهد شد.
                سرویس‌دهنده را انتخاب کنید، کلید API را وارد کنید، «ذخیره» و سپس «ارسال پیام آزمایشی» را بزنید.
            </p>
            <div class="settings-grid">
                <div>
                    <h4 class="sms-label">سرویس‌دهنده</h4>
                    <select id="smsProvider" class="form-control" onchange="smsToggleWebhook()">
                        <option value="none">غیرفعال</option>
                        <option value="kavenegar">کاوه‌نگار (Kavenegar)</option>
                        <option value="smsir">sms.ir</option>
                        <option value="melipayamak">ملی پیامک (Melipayamak)</option>
                        <option value="ghasedak">قاصدک (Ghasedak)</option>
                        <option value="webhook">وب‌هوک اختصاصی</option>
                    </select>
                </div>
                <div>
                    <h4 class="sms-label">کلید API / توکن</h4>
                    <input type="text" id="smsApiKey" class="form-control sms-mono" dir="ltr" placeholder="API KEY" autocomplete="off">
                </div>
                <div>
                    <h4 class="sms-label">شماره فرستنده / خط</h4>
                    <input type="text" id="smsSender" class="form-control sms-mono" dir="ltr" placeholder="مثلاً 3000xxxx" autocomplete="off">
                </div>
                <div>
                    <h4 class="sms-label">شناسه پترن <small>(اختیاری)</small></h4>
                    <input type="text" id="smsPattern" class="form-control sms-mono" dir="ltr" placeholder="template id" autocomplete="off">
                </div>
                <div class="sms-webhook-row" id="smsWebhookRow" style="grid-column:1/-1; display:none;">
                    <h4 class="sms-label">آدرس وب‌هوک <small>(فقط برای گزینه‌ی webhook)</small></h4>
                    <input type="text" id="smsWebhook" class="form-control sms-mono" dir="ltr" placeholder="https://example.com/sms/send" autocomplete="off">
                </div>
            </div>

            <div class="sms-toggles">
                <label class="sms-switch">
                    <input type="checkbox" id="smsEnabled">
                    <span class="sms-track" aria-hidden="true"></span>
                    <span class="sms-sw-text"><b>فعال‌سازی ارسال پیامک</b><small>قابلیت کد ورود و بازیابی رمز پیامکی</small></span>
                </label>
                <label class="sms-switch">
                    <input type="checkbox" id="smsDevMode">
                    <span class="sms-track sms-track-dev" aria-hidden="true"></span>
                    <span class="sms-sw-text"><b>حالت آزمایشی</b><small>کد بدون پیامک، فقط روی صفحه نمایش داده می‌شود</small></span>
                </label>
            </div>

            <div class="sms-actions">
                <button onclick="saveSmsSettings()" class="btn btn-primary">💾 ذخیره تنظیمات</button>
                <span class="sms-divider" aria-hidden="true"></span>
                <input type="text" id="smsTestPhone" class="form-control sms-mono" dir="ltr" placeholder="09xxxxxxxxx" inputmode="numeric">
                <button onclick="testSms()" class="btn btn-success">📨 ارسال پیام آزمایشی</button>
            </div>

            <div class="sms-log-wrap">
                <h4 class="sms-label">آخرین ارسال‌ها</h4>
                <div id="smsLogBox" class="sms-log">—</div>
            </div>

            <div class="sms-lock">
                <h4 class="sms-label">قفل ورود (محافظت در برابر تلاش مکرر)</h4>
                <p class="sms-hint" style="margin-top:0;">
                    پس از چند تلاش ناموفق، ورود همان کاربر/IP برای مدتی قفل می‌شود. اگر کاربری به‌اشتباه قفل شده، از این دکمه برای آزادسازی استفاده کنید.
                </p>
                <button onclick="clearLoginAttempts()" class="btn btn-ghost">🔓 آزادسازی قفل ورود</button>
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

    <div class="card" id="accessCard">
        <div class="card-header">
            <h2 class="card-title">کنترل دسترسی</h2>
            <div class="access-state">
                <span class="access-pill" id="accessRegPill" data-on="0">ثبت‌نام باز</span>
                <span class="access-pill" id="accessLoginPill" data-on="0">ورود باز</span>
            </div>
        </div>
        <style>
            #accessCard .access-state { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
            #accessCard .access-pill { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 999px; font-size: .78rem; font-weight: 700; border: 1px solid rgba(148,163,184,.25); background: rgba(148,163,184,.08); color: var(--text-muted); transition: .2s; }
            #accessCard .access-pill::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: #64748b; transition: .2s; }
            #accessCard .access-pill[data-on="1"] { color: #fca5a5; border-color: rgba(239,68,68,.45); background: rgba(239,68,68,.12); }
            #accessCard .access-pill[data-on="1"]::before { background: #ef4444; box-shadow: 0 0 10px rgba(239,68,68,.8); }
            #accessCard .access-row { display: flex; align-items: center; gap: 14px; padding: 14px 16px; border-radius: 16px; background: rgba(15,23,42,.45); border: 1px solid var(--border); cursor: pointer; transition: border-color .2s, background .2s; }
            #accessCard .access-row:hover { border-color: rgba(99,102,241,.5); background: rgba(99,102,241,.06); }
            #accessCard .access-ico { flex: 0 0 42px; width: 42px; height: 42px; border-radius: 13px; display: grid; place-items: center; background: rgba(99,102,241,.12); border: 1px solid rgba(99,102,241,.25); }
            #accessCard .access-ico img { width: 24px; height: 24px; object-fit: contain; filter: drop-shadow(0 3px 5px rgba(0,0,0,.45)); }
            #accessCard .access-copy { flex: 1 1 auto; min-width: 0; }
            #accessCard .access-copy b { display: block; color: #fff; font-size: .95rem; margin-bottom: 3px; }
            #accessCard .access-copy small { display: block; color: var(--text-muted); font-size: .8rem; line-height: 1.7; }
            #accessCard .access-switch { position: relative; flex: 0 0 48px; width: 48px; height: 26px; }
            #accessCard .access-switch input { position: absolute; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; z-index: 2; }
            #accessCard .access-slider { position: absolute; inset: 0; border-radius: 999px; background: rgba(148,163,184,.25); border: 1px solid rgba(148,163,184,.3); transition: .25s; }
            #accessCard .access-slider::after { content: ''; position: absolute; top: 2px; right: 2px; width: 20px; height: 20px; border-radius: 50%; background: #cbd5e1; box-shadow: 0 2px 6px rgba(0,0,0,.4); transition: .25s; }
            #accessCard .access-switch input:checked + .access-slider { background: linear-gradient(135deg,#ef4444,#dc2626); border-color: rgba(239,68,68,.7); box-shadow: 0 0 16px -4px rgba(239,68,68,.8); }
            #accessCard .access-switch input:checked + .access-slider::after { right: calc(100% - 22px); background: #fff; }
            #accessCard .access-switch input:focus-visible + .access-slider { outline: 2px solid var(--primary); outline-offset: 3px; }
            #accessCard .access-hint { padding: 12px 14px; border-radius: 13px; background: rgba(129,140,248,.07); border: 1px solid rgba(129,140,248,.22); color: #cbd5e1; font-size: .82rem; line-height: 1.9; }
            #accessCard .access-hint b { color: #a5b4fc; }
            @media (max-width: 560px) {
                #accessCard .access-row { align-items: flex-start; }
                #accessCard .access-ico { width: 36px; height: 36px; flex-basis: 36px; }
            }
        </style>
        <div class="action-box">
            <label class="access-row" for="lockRegisterToggle">
                <span class="access-ico"><img src="assets/icons3d/lock.png" alt=""></span>
                <span class="access-copy">
                    <b>قفل ثبت‌نام</b>
                    <small>ساخت حساب جدید متوقف می‌شود. کاربران قبلی بدون هیچ تغییری وارد می‌شوند و نام‌های کاربری تازه پذیرفته نمی‌شوند.</small>
                </span>
                <span class="access-switch">
                    <input type="checkbox" id="lockRegisterToggle" onchange="toggleAccessLock('register', this.checked)">
                    <span class="access-slider" aria-hidden="true"></span>
                </span>
            </label>

            <label class="access-row" for="lockLoginToggle">
                <span class="access-ico"><img src="assets/icons3d/key.png" alt=""></span>
                <span class="access-copy">
                    <b>قفل ورود کاربران</b>
                    <small>ورود همه کاربران عادی بسته می‌شود؛ فقط مدیر کل می‌تواند از پنل مدیریت وارد شود تا قفل را بردارد.</small>
                </span>
                <span class="access-switch">
                    <input type="checkbox" id="lockLoginToggle" onchange="toggleAccessLock('login', this.checked)">
                    <span class="access-slider" aria-hidden="true"></span>
                </span>
            </label>

            <div class="access-hint">
                <b>نکته امنیتی:</b> هر دو قفل سمت سرور اعمال می‌شوند، نه فقط در ظاهر صفحه. با فعال بودن حالت نمایشی، ورود عادی به‌طور کامل بسته است و فقط <b style="direction:ltr;display:inline-block;">demo / demo</b> کار می‌کند؛ قفل‌های این بخش پس از خاموش شدن دمو دوباره تعیین‌کننده‌اند.
            </div>

            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <button onclick="loadAccessState()" class="btn btn-ghost btn-sm">↻ بروزرسانی وضعیت</button>
                <span id="accessSavedHint" style="font-size:.8rem; color:var(--text-muted);">تغییرات فوراً روی صفحه ورود اعمال می‌شوند</span>
            </div>
        </div>
    </div>

    <div class="card" id="demoCard">
        <style>
            /* Shared pill switch. The demo card used a bare <input type=checkbox>
               while the access card next to it had a styled toggle, so the two
               neighbours looked like they came from different products. */
            .vx-toggle-row { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; color: #cbd5e1; font-size: .9rem; }
            .vx-toggle-label { font-weight: 600; }
            .vx-toggle { position: relative; display: inline-block; flex: 0 0 48px; width: 48px; height: 26px; }
            .vx-toggle input { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; opacity: 0; cursor: pointer; z-index: 2; }
            .vx-toggle-slider { position: absolute; inset: 0; border-radius: 999px; background: rgba(148,163,184,.25); border: 1px solid rgba(148,163,184,.3); transition: .25s; }
            .vx-toggle-slider::after { content: ''; position: absolute; top: 2px; right: 2px; width: 20px; height: 20px; border-radius: 50%; background: #cbd5e1; box-shadow: 0 2px 6px rgba(0,0,0,.4); transition: .25s; }
            .vx-toggle input:checked + .vx-toggle-slider { background: linear-gradient(135deg,#10b981,#059669); border-color: rgba(16,185,129,.7); box-shadow: 0 0 16px -4px rgba(16,185,129,.85); }
            .vx-toggle input:checked + .vx-toggle-slider::after { right: calc(100% - 22px); background: #fff; }
            .vx-toggle input:focus-visible + .vx-toggle-slider { outline: 2px solid var(--primary); outline-offset: 3px; }
        </style>
        <div class="card-header">
            <h2 class="card-title">حالت نمایشی (دمو)</h2>
            <div style="display:flex; align-items:center; gap:8px; font-size:0.85rem;">
                <span id="demoStatusDot" style="width:10px; height:10px; border-radius:50%; background:#64748b; display:inline-block;"></span>
                <span id="demoStatusLabel" style="color:var(--text-muted);">بررسی نشده</span>
            </div>
        </div>
        <div class="action-box">
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:14px;">
                <div>
                    <h4 style="margin:0; color:#fff;">فعال‌سازی ورود آزمایشی</h4>
                    <div style="font-size:0.85rem; color:var(--text-muted); margin-top:4px;">
                        وقتی روشن است، هر بازدیدکننده می‌تواند با نام کاربری <b style="color:#818cf8; direction:ltr; display:inline-block;">demo</b> و رمز <b style="color:#818cf8; direction:ltr; display:inline-block;">demo</b> وارد شود و محیط را ببیند.
                    </div>
                </div>
                <label class="vx-toggle-row">
                    <span class="vx-toggle-label">فعال</span>
                    <span class="vx-toggle">
                        <input type="checkbox" id="demoEnabled" onchange="toggleDemoMode(this.checked)">
                        <span class="vx-toggle-slider" aria-hidden="true"></span>
                    </span>
                </label>
            </div>

            <div id="demoStats" style="display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin-bottom:14px;">
                <div style="padding:12px 14px; background:rgba(0,0,0,0.22); border:1px solid var(--border); border-radius:12px;">
                    <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:4px;">بازدیدکننده فعال</div>
                    <div id="demoActiveCount" style="font-size:1.4rem; font-weight:800; color:#818cf8;">—</div>
                </div>
                <div style="padding:12px 14px; background:rgba(0,0,0,0.22); border:1px solid var(--border); border-radius:12px;">
                    <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:4px;">پاکسازی خودکار پس از</div>
                    <div style="font-size:1.1rem; font-weight:700; color:#cbd5e1;">۴۵ دقیقه بی‌فعالیتی</div>
                </div>
                <div style="padding:12px 14px; background:rgba(0,0,0,0.22); border:1px solid var(--border); border-radius:12px;">
                    <div style="font-size:0.75rem; color:var(--text-muted); margin-bottom:4px;">سقف پیام هر نشست</div>
                    <div style="font-size:1.1rem; font-weight:700; color:#cbd5e1;">۲۵ پیام</div>
                </div>
            </div>

            <div style="padding:12px 14px; background:rgba(129,140,248,0.08); border:1px solid rgba(129,140,248,0.25); border-radius:12px; font-size:0.85rem; color:#cbd5e1; line-height:1.9; margin-bottom:14px;">
                <b style="color:#818cf8;">در حالت نمایشی چه چیزی محدود است؟</b><br>
                فقط یک اتاق اختصاصی (<b>اتاق دمو</b>) · فقط پیام متنی، بدون رسانه و ویس · بدون پیام خصوصی · بدون ساخت اتاق یا کانال · بدون تنظیمات حساب و کد امنیتی · بدون دسترسی به لیست واقعی کاربران.<br>
                هر بازدیدکننده یک حساب موقت جدا می‌گیرد، پس چند نفر می‌توانند همزمان وارد شوند. با خروج یا پس از ۴۵ دقیقه بی‌فعالیتی، حساب و همه پیام‌هایش خودکار حذف می‌شود.
            </div>

            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <button onclick="purgeDemoData()" class="btn btn-danger" id="demoPurgeBtn" style="<?php echo $isMainAdmin ? '' : 'display:none;'; ?>">🗑 پاکسازی فوری همه داده‌های دمو</button>
                <button onclick="loadDemoState()" class="btn btn-ghost btn-sm">↻ بروزرسانی وضعیت</button>
                <span id="demoPurgeHint" style="font-size:0.8rem; color:var(--text-muted);">حساب‌های موقت و پیام‌هایشان فوراً حذف می‌شوند</span>
            </div>
        </div>
    </div>

    <div class="card" id="wsCard">
        <div class="card-header">
            <h2 class="card-title">تنظیمات WebSocket (چت بلادرنگ)</h2>
            <div style="display:flex; align-items:center; gap:8px; font-size:0.85rem;">
                <span id="wsStatusDot" style="width:10px; height:10px; border-radius:50%; background:#64748b; display:inline-block;"></span>
                <span id="wsStatusLabel" style="color:var(--text-muted);">بررسی نشده</span>
                <button onclick="testWsConnection()" class="btn btn-ghost btn-sm">تست اتصال</button>
            </div>
        </div>
        <div class="action-box">
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-bottom:14px;">
                <div>
                    <h4 style="margin:0; color:#fff;">فعال‌سازی چت بلادرنگ (WebSocket)</h4>
                    <div style="font-size:0.85rem; color:var(--text-muted); margin-top:4px;">در صورت غیرفعال بودن، چت با حالت استاندارد (به‌روزرسانی دوره‌ای) کار می‌کند</div>
                </div>
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; color:#cbd5e1; font-size:0.9rem;">
                    <input type="checkbox" id="wsEnabled" style="width:18px; height:18px; cursor:pointer;">
                    فعال
                </label>
            </div>
            <div class="settings-grid">
                <div>
                    <h4 style="margin:0 0 8px; color:var(--text-muted); font-size:0.9rem;">آدرس عمومی WebSocket (برای کاربران)</h4>
                    <input type="text" id="wsUrl" class="form-control" placeholder="wss://example.com/ws" dir="ltr" style="font-family:monospace;">
                    <div style="margin-top:6px; font-size:0.8rem; color:var(--text-muted);">لینک مستقیم اتصال:</div>
                    <div id="wsEndpointDisplay" style="margin-top:4px; padding:8px 12px; background:rgba(0,0,0,0.25); border:1px dashed var(--border); border-radius:10px; font-family:monospace; font-size:0.8rem; color:#818cf8; direction:ltr; word-break:break-all; cursor:pointer;" onclick="copyWsEndpoint()" title="کلیک برای کپی">—</div>
                </div>
                <div>
                    <h4 style="margin:0 0 8px; color:var(--text-muted); font-size:0.9rem;">آدرس داخلی Push سرور (از دید سرور PHP)</h4>
                    <input type="text" id="wsInternalUrl" class="form-control" placeholder="http://127.0.0.1:3000" dir="ltr" style="font-family:monospace;">
                    <h4 style="margin:14px 0 8px; color:var(--text-muted); font-size:0.9rem;">دامنه‌های مجاز (Allowed Origins)</h4>
                    <input type="text" id="wsOrigins" class="form-control" placeholder="* یا https://example.com" dir="ltr">
                </div>
            </div>
            <div style="display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; margin-top:14px;">
                <div>
                    <h4 style="margin:0 0 8px; color:var(--text-muted); font-size:0.9rem;">فاصله تلاش مجدد اتصال (ثانیه)</h4>
                    <input type="number" id="wsReconnect" class="form-control" min="2" max="120" style="max-width:140px;">
                </div>
                <button onclick="saveWsSettings()" class="btn btn-primary" id="wsSaveBtn" style="<?php echo $isMainAdmin ? '' : 'display:none;'; ?>">💾 ذخیره تنظیمات</button>
            </div>
        </div>
    </div>

    <div class="card" id="aiCard">
        <div class="card-header">
            <h2 class="card-title">دستیار هوش مصنوعی</h2>
            <button onclick="testAiConnection()" class="btn btn-ghost btn-sm">🔌 تست اتصال هوش مصنوعی</button>
        </div>
        <div class="action-box">
            <div class="ai-enable-row">
                <div>
                    <h4 style="margin:0; color:#fff;">فعال‌سازی دستیار</h4>
                    <div style="font-size:0.85rem; color:var(--text-muted); margin-top:4px; line-height:1.8;">
                        دستیار با استفاده از <b>کلمه‌های صدا زدن</b> در پیام یا <b>ریپلای روی پیام دستیار</b> در اتاق‌های فعال پاسخ می‌دهد.
                        <?php $adKw = array_values(array_filter(array_map('trim', preg_split('/[,\n]/u', (string)veyrax_setting($pdo, 'ai_keyword', ''))))); if ($adKw): ?>فعلی: <b dir="ltr"><?php echo htmlspecialchars(implode(' , ', $adKw), ENT_QUOTES, 'UTF-8'); ?></b><?php endif; ?>
                    </div>
                </div>
                <label class="sms-switch" style="background:transparent; border:none; padding:0;">
                    <input type="checkbox" id="aiEnabled">
                    <span class="sms-track" aria-hidden="true"></span>
                    <span class="sms-sw-text"><b>فعال</b></span>
                </label>
            </div>
            <div style="display:flex; gap:18px; flex-wrap:wrap; margin:10px 0 4px;">
                <label class="sms-switch" style="background:transparent; border:none; padding:0;">
                    <input type="checkbox" id="aiAllRooms">
                    <span class="sms-track" aria-hidden="true"></span>
                    <span class="sms-sw-text"><b>پاسخ در همه‌ی گروه‌ها</b><small>روشن = همه‌ی اتاق‌ها بدون تنظیم جداگانه؛ خاموش = فقط اتاق‌های فعال‌شده یا لیست «اتاق‌های فعال»</small></span>
                </label>
                <label class="sms-switch" style="background:transparent; border:none; padding:0;">
                    <input type="checkbox" id="aiPv">
                    <span class="sms-track" aria-hidden="true"></span>
                    <span class="sms-sw-text"><b>فعال در پیوی</b><small>گفتگوی مستقیم با «دستیار هوشمند» همیشه پاسخ می‌گیرد؛ در پیوی دو نفره فقط با کلمه‌ی صدا زدن یا ریپلای دستیار</small></span>
                </label>
            </div>
            <div class="settings-grid">
                <div>
                    <h4 class="sms-label">آدرس پایه API <small>(سازگار با OpenAI)</small></h4>
                    <input type="text" id="aiBaseUrl" class="form-control sms-mono" placeholder="https://example.com/v1" dir="ltr" autocomplete="off">
                    <h4 class="sms-label" style="margin-top:14px;">نام مدل</h4>
                    <input type="text" id="aiModel" class="form-control sms-mono" placeholder="model-name" dir="ltr" autocomplete="off">
                    <h4 class="sms-label" style="margin-top:14px;">کلید API</h4>
                    <div style="position:relative;">
                        <input type="password" id="aiApiKey" class="form-control sms-mono ai-key-input" placeholder="کلید جدید را وارد کنید (برای حفظ کلید فعلی خالی بگذارید)" dir="ltr" autocomplete="new-password">
                        <button type="button" onclick="togglePassVisibility('aiApiKey',this)" aria-label="نمایش کلید" class="ai-eye">👁</button>
                    </div>
                    <div id="aiKeyMasked" style="margin-top:6px; font-size:0.78rem; color:#64748b; direction:ltr; text-align:left;"></div>
                </div>
                <div>
                    <h4 class="sms-label">پرامپت سیستم (شخصیت پایه)</h4>
                    <textarea id="aiSystemPrompt" class="form-control" rows="3" style="resize:vertical;"></textarea>
                    <h4 class="sms-label" style="margin-top:14px;">رفتار / لحن پاسخ‌ها</h4>
                    <textarea id="aiBehavior" class="form-control" rows="2" style="resize:vertical;" placeholder="مثلا: دوستانه، کوتاه، به فارسی"></textarea>
                    <h4 class="sms-label" style="margin-top:14px;">اتاق‌های فعال <small>(نام‌ها با کاما — خالی = فقط اتاق‌های علامت‌خورده)</small></h4>
                    <input type="text" id="aiRooms" class="form-control" placeholder="گفتگوی عمومی, اتاق دوم">
                </div>
            </div>
            <div class="ai-tuning">
                <div>
                    <h4 class="sms-label">حداکثر توکن پاسخ</h4>
                    <input type="number" id="aiMaxTokens" class="form-control" min="100" max="4000" style="max-width:140px;">
                </div>
                <div>
                    <h4 class="sms-label">Temperature</h4>
                    <input type="number" id="aiTemperature" class="form-control" min="0" max="2" step="0.1" style="max-width:140px;">
                </div>
                <div>
                    <h4 class="sms-label">تایم‌اوت (ثانیه)</h4>
                    <input type="number" id="aiTimeout" class="form-control" min="5" max="120" style="max-width:140px;">
                </div>
                <div>
                    <h4 class="sms-label">فاصله بین پاسخ‌ها (ثانیه)</h4>
                    <input type="number" id="aiCooldown" class="form-control" min="0" max="600" style="max-width:140px;">
                </div>
                <div>
                    <h4 class="sms-label">تعداد تلاش برای هر پیام</h4>
                    <input type="number" id="aiMaxAttempts" class="form-control" min="1" max="5" style="max-width:140px;">
                </div>
                <div class="ai-mention-box">
                    <label class="sms-switch" style="background:transparent; border:none; padding:0;">
                        <input type="checkbox" id="aiReplyAll">
                        <span class="sms-track" aria-hidden="true"></span>
                        <span class="sms-sw-text"><b>پاسخ به همه‌ی پیام‌های اتاق</b><small>در حالت خاموش، فقط کلمه‌ی «ویراکس» یا ریپلای دستیار را صدا می‌زند</small></span>
                    </label>
                </div>
            </div>
            <div class="ai-limits">
                <div class="ai-limits-head">
                    <h4 class="sms-label" style="margin:0;">محدودیت هر کاربر</h4>
                    <small>درخواست‌های دستیار هر کاربر صف‌بندی و پشت‌سرهم پردازش می‌شوند؛ اگر مدل پاسخ ندهد، اتاق پیام «فعلاً در دسترس نیست» می‌گیرد.</small>
                </div>
                <div class="ai-limits-fields">
                    <div>
                        <h4 class="sms-label">تعداد درخواست مجاز <small>(۰ = نامحدود)</small></h4>
                        <input type="number" id="aiUserLimitCount" class="form-control" min="0" max="50" style="max-width:140px;">
                    </div>
                    <div>
                        <h4 class="sms-label">بازه زمانی <small>(دقیقه — ۰ = بدون بازه)</small></h4>
                        <input type="number" id="aiUserLimitWindow" class="form-control" min="0" max="1440" style="max-width:140px;">
                    </div>
                    <div style="flex:1 1 240px;">
                        <h4 class="sms-label">کلمه‌های صدا زدن دستیار <small>(با کاما جدا کنید)</small></h4>
                        <input type="text" id="aiKeyword" class="form-control" placeholder="ویراکس, virax" style="max-width:280px;">
                    </div>
                </div>
            </div>
            <div style="margin-top:4px; display:flex; gap:10px; flex-wrap:wrap;">
                <button onclick="saveAiSettings()" class="btn btn-primary" id="aiSaveBtn" style="<?php echo $isMainAdmin ? '' : 'display:none;'; ?>">💾 ذخیره تنظیمات دستیار</button>
            </div>
        </div>
    </div>

    <div class="card" id="networkCard">
        <div class="card-header">
            <h2 class="card-title">شبکه، کش و حالت دریافت پیام</h2>
        </div>
        <div class="action-box">
            <div class="net-modes" id="netModes">
                <label class="net-mode">
                    <input type="radio" name="reqMode" value="telegram" id="reqModeTelegram">
                    <span class="net-dot"></span>
                    <span class="net-body">
                        <span class="net-title">حالت تلگرامی</span>
                        <span class="net-desc">یک اتصال باز می‌ماند و پیام همان لحظه می‌رسد؛ هیچ نشانه‌ای از درخواست دیده نمی‌شود و ترافیک بیکار تقریباً صفر است.</span>
                    </span>
                </label>
                <label class="net-mode">
                    <input type="radio" name="reqMode" value="normal" id="reqModeNormal">
                    <span class="net-dot"></span>
                    <span class="net-body">
                        <span class="net-title">حالت عادی</span>
                        <span class="net-desc">هر چند ثانیه یک درخواست کوتاه ارسال می‌شود. ساده‌تر و سبک‌تر برای سرورهای اشتراکی و ضعیف.</span>
                    </span>
                </label>
            </div>

            <div class="net-row">
                <div class="net-field">
                    <label for="cacheTtlInput">مدت اعتبار کش (ساعت)</label>
                    <input type="number" id="cacheTtlInput" class="form-control" min="1" max="720" value="24">
                </div>
                <button type="button" class="btn btn-primary" id="netSaveBtn" onclick="saveRuntimeSettings()" style="<?php echo $isMainAdmin ? '' : 'display:none;'; ?>">💾 ذخیره تنظیمات</button>
            </div>

            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <button type="button" class="btn btn-ghost" onclick="purgeLocalCache()">🧹 پاک‌سازی کش این مرورگر</button>
                <button type="button" class="btn btn-ghost" onclick="forceReloadCache()">🔄 بازخوانی اجباری</button>
                <button type="button" class="btn btn-ghost" id="netPurgeAllBtn" onclick="bumpCacheEpoch()" style="<?php echo $isMainAdmin ? '' : 'display:none;'; ?>">🗑 پاک‌سازی کش همه کاربران</button>
            </div>

            <div class="net-stats" id="netStats">
                <div class="net-stat"><b id="nsEntries">0</b><span>مورد کش‌شده</span></div>
                <div class="net-stat"><b id="nsSize">0 KB</b><span>حجم</span></div>
                <div class="net-stat"><b id="nsAge">—</b><span>قدیمی‌ترین</span></div>
                <div class="net-stat"><b id="nsEpoch">0</b><span>نسخه کش</span></div>
            </div>
            <p class="ap-hint">کش هر ۲۴ ساعت (یا مقدار تعیین‌شده) به‌صورت خودکار پاک می‌شود. «بازخوانی اجباری» داده‌های این مرورگر را دور می‌زند و همه‌چیز را از سرور تازه می‌گیرد.</p>
        </div>
    </div>

    <div class="card" id="backupCard">
        <div class="card-header">
            <h2 class="card-title">پشتیبان‌گیری (دیتابیس / سورس)</h2>
        </div>
        <div class="action-box">
            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:14px;">
                <button onclick="createBackup('db')" class="btn btn-primary" style="<?php echo $isMainAdmin ? '' : 'display:none;'; ?>">🗄 پشتیبان دیتابیس</button>
                <button onclick="createBackup('source')" class="btn btn-ghost" style="<?php echo $isMainAdmin ? '' : 'display:none;'; ?>">📦 پشتیبان سورس کد</button>
                <button onclick="createBackup('both')" class="btn btn-ghost" style="<?php echo $isMainAdmin ? '' : 'display:none;'; ?>">🗄📦 هر دو</button>
                <button onclick="loadBackupList()" class="btn btn-ghost btn-sm">🔄 به‌روزرسانی فهرست</button>
                <button onclick="resetBackups()" class="btn btn-ghost btn-sm" style="color:#f87171; border-color:rgba(248,113,113,0.35); <?php echo $isMainAdmin ? '' : 'display:none;'; ?>">🧹 ریست و پاک‌سازی همه</button>
            </div>
            <p class="ap-hint" style="margin:-4px 0 12px;">«ریست و پاک‌سازی همه» تمام فایل‌های پشتیبان و تاریخچه‌ی آن‌ها را حذف می‌کند. پشتیبان‌هایی که دستی از سرور حذف شده باشند، خودکار از این فهرست کنار می‌روند.</p>
            <div id="backupList" style="display:flex; flex-direction:column; gap:8px;"></div>
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
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <input type="text" id="searchUser" class="form-control" style="flex:1 1 200px; min-width:0;" placeholder="نام کاربری را وارد کنید…" oninput="filterUsers()" autocomplete="off">
                <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('searchUser').value=''; filterUsers();" style="white-space:nowrap">پاک کردن</button>
            </div>
            <div id="usersSummary" style="margin-top:8px; font-size:0.8rem; color:var(--text-muted);"></div>
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

    <div class="card" id="mediaCard">
        <div class="card-header">
            <h2 class="card-title">مدیریت رسانه‌ها</h2>
            <span class="media-chip" id="mediaCount">—</span>
        </div>
        <p class="media-hint">
            فقط رسانه‌ها (پیام صوتی، تصویر و ویدیو) حذف می‌شوند. خودِ پیام در گفتگو باقی می‌ماند تا جایگاهش به‌هم نریزد و به‌جای رسانه، کادر «این پیام حذف شده» نمایش داده می‌شود.
        </p>
        <div class="media-toolbar">
            <select id="mediaType" class="form-control" onchange="loadMediaList(1)">
                <option value="all">همه رسانه‌ها</option>
                <option value="video">ویدیو 🎬</option>
                <option value="image">تصویر 🖼</option>
                <option value="voice">پیام صوتی 🎙</option>
            </select>
            <input type="text" id="mediaSearch" class="form-control" placeholder="جستجو: کاربر، اتاق یا نام فایل" onkeydown="if(event.key==='Enter'){loadMediaList(1);}">
            <label class="media-switch"><input type="checkbox" id="mediaGoneOnly" onchange="loadMediaList(1)"> فقط حذف‌شده‌ها</label>
            <button type="button" class="btn btn-primary" onclick="loadMediaList(mediaState.page||1)">به‌روزرسانی</button>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th width="34"><input type="checkbox" id="mediaAll" onchange="toggleAllMedia(this)" title="انتخاب همه در این صفحه"></th>
                        <th>نوع</th>
                        <th>فرستنده</th>
                        <th>مقصد</th>
                        <th>نام فایل</th>
                        <th>حجم</th>
                        <th>تاریخ</th>
                        <th>وضعیت</th>
                        <th width="96">عملیات</th>
                    </tr>
                </thead>
                <tbody id="mediaList">
                    <tr><td colspan="9" class="media-empty">در حال بارگذاری…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="media-footer">
            <div class="media-actions">
                <button type="button" class="btn btn-danger" id="mediaDelSel" onclick="deleteMediaSelected()" disabled>حذف انتخاب‌شده‌ها</button>
                <button type="button" class="btn media-btn-nuke" id="mediaDelAll" onclick="deleteMediaAllFiltered()" title="حذف همه رسانه‌های مطابق فیلتر فعلی، بدون نیاز به انتخاب تک‌تک">⚡ حذف یکجای همه (مطابق فیلتر)</button>
            </div>
            <div class="media-pager" id="mediaPager"></div>
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
                <select id="newAdminLevel" class="form-control" style="max-width:210px;" title="سطح دسترسی">
                    <option value="full">🛡 سطح کامل — مدیریت کل پنل</option>
                    <option value="limited">👁 سطح محدود — فقط نظارت و مدیریت کاربران</option>
                </select>
                <button onclick="addAdmin()" class="btn btn-primary">افزودن ادمین</button>
            </div>
            <p style="font-size:0.8rem;color:var(--text-muted);margin:8px 0 0;line-height:1.9;">
                <b>کامل:</b> همه‌ی تنظیمات، بکاپ، دستیار، اتاق‌ها — به‌جز مدیریت ادمین‌ها. &nbsp;|&nbsp;
                <b>محدود:</b> فقط نظارت: کاربران، نشست‌ها، گزارش‌ها، رسانه و بلاک — بدون دسترسی به تنظیمات و بکاپ.
            </p>
        </div>
        <div class="table-responsive">
            <table>
                <thead><tr><th>نام کاربری</th><th>نام نمایشی</th><th>سطح دسترسی</th><th>آخرین ورود</th><th>وضعیت</th><th width="190">عملیات</th></tr></thead>
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
    const reasonEl = document.getElementById('banReason');
    const reason = reasonEl ? reasonEl.value.trim() : '';

    closeModal('banModalOptions');
    performAction('ban_user', 0, {user_id: id, duration: dur, reason: reason});
    if (reasonEl) reasonEl.value = '';
}

function openInviteModal(roomName) {
    document.getElementById('inviteRoomName').value = roomName;
    document.getElementById('inviteUsername').value = '';
    document.getElementById('inviteModal').style.display = 'flex';
    document.getElementById('inviteUsername').focus();
}

// ── SMS gateway settings ────────────────────────────────────────────────────
function smsPost(action, data) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', csrfToken);
    Object.keys(data || {}).forEach(k => fd.append(k, data[k]));
    return fetch('admin.php', { method: 'POST', body: fd }).then(r => r.json());
}

function smsToggleWebhook() {
    const sel = document.getElementById('smsProvider');
    const row = document.getElementById('smsWebhookRow');
    if (sel && row) row.style.display = (sel.value === 'webhook') ? 'block' : 'none';
}

function loadSmsSettings() {
    smsPost('get_sms_settings').then(d => {
        if (d.status !== 'success') return;
        const s = d.settings || {};
        const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v || ''; };
        set('smsProvider', s.provider || 'none');
        set('smsApiKey', s.api_key || '');
        set('smsSender', s.sender || '');
        set('smsPattern', s.pattern || '');
        set('smsWebhook', s.webhook || '');
        const en = document.getElementById('smsEnabled');
        if (en) en.checked = !!s.enabled;
        const dev = document.getElementById('smsDevMode');
        if (dev) dev.checked = !!s.dev_mode;
        smsToggleWebhook();

        const dot = document.getElementById('smsStatusDot');
        const label = document.getElementById('smsStatusLabel');
        if (dot && label) {
            if (s.ready) { dot.style.background = '#10b981'; dot.style.boxShadow = '0 0 0 3px rgba(16,185,129,0.2)'; label.textContent = 'متصل و آماده'; label.style.color = '#10b981'; }
            else if (s.dev_mode) { dot.style.background = '#f59e0b'; dot.style.boxShadow = '0 0 0 3px rgba(245,158,11,0.2)'; label.textContent = 'حالت آزمایشی'; label.style.color = '#f59e0b'; }
            else { dot.style.background = '#ef4444'; dot.style.boxShadow = '0 0 0 3px rgba(239,68,68,0.2)'; label.textContent = 'متصل نیست'; label.style.color = '#ef4444'; }
        }

        const box = document.getElementById('smsLogBox');
        if (box) {
            if (!d.log || !d.log.length) { box.innerHTML = 'هنوز پیامکی ارسال نشده است.'; }
            else {
                box.innerHTML = d.log.map(function (r) {
                    const ok = String(r.ok) === '1';
                    const cls = ok ? 'sms-log-ok' : 'sms-log-bad';
                    const txt = ok ? 'موفق' : (r.detail || 'ناموفق');
                    return '<div class="sms-log-row">'
                        + '<span dir="ltr">' + (r.phone || '') + '</span>'
                        + '<span class="' + cls + '">' + txt + '</span>'
                        + '<span class="sms-log-time">' + (r.created_at || '') + '</span></div>';
                }).join('');
            }
        }
    }).catch(function () {});
}

function saveSmsSettings() {
    const data = {
        sms_provider: document.getElementById('smsProvider').value,
        sms_api_key: document.getElementById('smsApiKey').value,
        sms_sender: document.getElementById('smsSender').value,
        sms_pattern: document.getElementById('smsPattern').value,
        sms_webhook: document.getElementById('smsWebhook').value,
        sms_enabled: document.getElementById('smsEnabled').checked ? '1' : '0',
        sms_dev_mode: document.getElementById('smsDevMode').checked ? '1' : '0'
    };
    smsPost('save_sms_settings', data).then(function (d) {
        showToast(d.message, d.status === 'success' ? 'success' : 'error');
        loadSmsSettings();
    }).catch(function () { showToast('خطای شبکه', 'error'); });
}

function testSms() {
    const phone = (document.getElementById('smsTestPhone').value || '').trim();
    if (!/^09[0-9]{9}$/.test(phone)) { showToast('شماره موبایل معتبر وارد کنید', 'error'); return; }
    showToast('در حال ارسال پیام آزمایشی...', 'success');
    smsPost('test_sms', { phone: phone }).then(function (d) {
        showToast(d.message, d.status === 'success' ? 'success' : 'error');
        loadSmsSettings();
    }).catch(function () { showToast('خطای شبکه', 'error'); });
}

function clearLoginAttempts() {
    if (typeof confirmAction === 'function') {
        confirmAction('clear_login_attempts', 0, 'سابقه تلاش‌های ورود پاک شود؟ (قفل ورود کاربران آزاد می‌شود)');
    } else {
        smsPost('clear_login_attempts').then(function (d) { showToast(d.message, d.status === 'success' ? 'success' : 'error'); });
    }
}

/* ── Media manager ──────────────────────────────────────────────────────── */
const mediaState = { page: 1, pages: 1, total: 0, items: [], sel: new Set(), busy: false };

function _mediaFmtSize(b) {
    b = Number(b) || 0;
    if (b <= 0) return '—';
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
    if (b < 1073741824) return (b / 1048576).toFixed(2) + ' MB';
    return (b / 1073741824).toFixed(2) + ' GB';
}

function _mediaEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
}

function _mediaPost(action, data) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', csrfToken);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch('admin.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); });
}

function _mediaConfirm(text, onYes) {
    const m = document.getElementById('confirmModal');
    const txt = document.getElementById('confirmText');
    const yes = document.getElementById('confirmYes');
    if (!m || !txt || !yes) { if (window.confirm(text)) onYes(); return; }
    txt.textContent = text;
    m.style.display = 'flex';
    yes.onclick = function () { closeModal('confirmModal'); onYes(); };
}

function loadMediaList(page) {
    const tbody = document.getElementById('mediaList');
    if (!tbody) return;
    if (mediaState.busy) return;
    mediaState.busy = true;
    mediaState.page = Math.max(1, parseInt(page, 10) || 1);
    tbody.innerHTML = '<tr><td colspan="9" class="media-empty">در حال بارگذاری…</td></tr>';

    _mediaPost('list_media', {
        mtype: (document.getElementById('mediaType') || {}).value || 'all',
        q: ((document.getElementById('mediaSearch') || {}).value || '').trim(),
        gone: (document.getElementById('mediaGoneOnly') && document.getElementById('mediaGoneOnly').checked) ? 1 : 0,
        page: mediaState.page
    }).then(function (d) {
        mediaState.busy = false;
        if (!d || d.status !== 'success') {
            tbody.innerHTML = '<tr><td colspan="9" class="media-empty">خطا در دریافت فهرست رسانه‌ها</td></tr>';
            showToast((d && d.message) || 'خطا در دریافت فهرست', 'error');
            return;
        }
        mediaState.items = d.items || [];
        mediaState.pages = d.pages || 1;
        mediaState.total = d.total || 0;
        // Selection only lives as long as the page it was made on.
        mediaState.sel.clear();
        renderMediaRows();
        _mediaSyncSel();
        const cnt = document.getElementById('mediaCount');
        if (cnt) cnt.textContent = mediaState.total.toLocaleString('fa-IR') + ' رسانه';
    }).catch(function () {
        mediaState.busy = false;
        tbody.innerHTML = '<tr><td colspan="9" class="media-empty">خطای شبکه</td></tr>';
        showToast('خطای شبکه', 'error');
    });
}

function renderMediaRows() {
    const tbody = document.getElementById('mediaList');
    if (!tbody) return;
    if (!mediaState.items.length) {
        tbody.innerHTML = '<tr><td colspan="9" class="media-empty">رسانه‌ای با این فیلتر پیدا نشد</td></tr>';
        return;
    }
    const meta = { video: { ico: 'play.png', label: 'ویدیو' }, image: { ico: 'image.png', label: 'تصویر' }, voice: { ico: 'mic.png', label: 'صوتی' } };
    let html = '';
    mediaState.items.forEach(function (it) {
        const m = meta[it.type] || { ico: 'file.png', label: 'فایل' };
        const state = it.deleted
            ? '<span class="media-state gone" title="' + _mediaEsc(it.deleted_by ? ('توسط ' + it.deleted_by + ' در ' + it.deleted_at) : '') + '">حذف شده</span>'
            : '<span class="media-state live">موجود</span>';
        html += '<tr data-mid="' + it.id + '">'
            + '<td><input type="checkbox" class="media-pick" value="' + it.id + '" onchange="onMediaPick(this)"></td>'
            + '<td><span class="media-type ' + it.type + '"><img src="assets/icons3d/' + m.ico + '" alt="">' + m.label + '</span></td>'
            + '<td>' + _mediaEsc(it.sender) + '</td>'
            + '<td>' + _mediaEsc(it.where) + '</td>'
            + '<td><span class="media-name" title="' + _mediaEsc(it.name) + '">' + _mediaEsc(it.name || '—') + '</span></td>'
            + '<td><span class="media-size">' + _mediaFmtSize(it.size) + '</span></td>'
            + '<td style="font-size:0.78rem;opacity:0.8">' + _mediaEsc(it.created_at) + '</td>'
            + '<td>' + state + '</td>'
            + '<td><button type="button" class="media-del-btn" onclick="deleteMedia(['
            + it.id + '])"' + (it.deleted ? ' disabled' : '') + '><img src="assets/icons3d/trash.png" alt="">حذف</button></td>'
            + '</tr>';
    });
    tbody.innerHTML = html;
    renderMediaPager();
}

function renderMediaPager() {
    const box = document.getElementById('mediaPager');
    if (!box) return;
    const p = mediaState.page, n = mediaState.pages;
    if (n <= 1) { box.innerHTML = ''; return; }
    let html = '<button type="button" onclick="loadMediaList(' + (p - 1) + ')"' + (p <= 1 ? ' disabled' : '') + '>›</button>';
    const from = Math.max(1, p - 2), to = Math.min(n, from + 4);
    for (let i = from; i <= to; i++) {
        html += '<button type="button" class="' + (i === p ? 'active' : '') + '" onclick="loadMediaList(' + i + ')">' + i + '</button>';
    }
    html += '<button type="button" onclick="loadMediaList(' + (p + 1) + ')"' + (p >= n ? ' disabled' : '') + '>‹</button>';
    box.innerHTML = html;
}

function onMediaPick(cb) {
    const id = parseInt(cb.value, 10) || 0;
    if (!id) return;
    if (cb.checked) mediaState.sel.add(id); else mediaState.sel.delete(id);
    _mediaSyncSel();
}

function toggleAllMedia(el) {
    const boxes = document.querySelectorAll('#mediaList .media-pick');
    mediaState.sel.clear();
    boxes.forEach(function (cb) {
        cb.checked = !!el.checked;
        if (el.checked) mediaState.sel.add(parseInt(cb.value, 10) || 0);
    });
    _mediaSyncSel();
}

function _mediaSyncSel() {
    const btn = document.getElementById('mediaDelSel');
    if (!btn) return;
    const n = mediaState.sel.size;
    btn.disabled = n === 0;
    btn.textContent = n > 0 ? ('حذف انتخاب‌شده‌ها (' + n + ')') : 'حذف انتخاب‌شده‌ها';
    const all = document.getElementById('mediaAll');
    if (all) {
        const boxes = document.querySelectorAll('#mediaList .media-pick');
        all.checked = boxes.length > 0 && n === boxes.length;
        all.indeterminate = n > 0 && n < boxes.length;
    }
}

function deleteMedia(ids) {
    ids = (ids || []).filter(function (v) { return v > 0; });
    if (!ids.length) return;
    const one = ids.length === 1;
    _mediaConfirm(
        one
            ? 'رسانه این پیام حذف شود؟ فایل برای همیشه پاک می‌شود، اما جای پیام در گفتگو با کادر «این پیام حذف شده» باقی می‌ماند.'
            : ('رسانه ' + ids.length + ' پیام انتخاب‌شده حذف شود؟ فایل‌ها برای همیشه پاک می‌شوند، اما جای پیام‌ها باقی می‌ماند.'),
        function () {
            _mediaPost('delete_media', { ids: ids.join(',') }).then(function (d) {
                if (!d || d.status !== 'success') {
                    showToast((d && d.message) || 'حذف انجام نشد', 'error');
                    return;
                }
                showToast(d.message || 'رسانه حذف شد');
                loadMediaList(mediaState.page);
            }).catch(function () { showToast('خطای شبکه', 'error'); });
        }
    );
}

function deleteMediaSelected() {
    const ids = Array.from(mediaState.sel);
    if (!ids.length) { showToast('ابتدا رسانه‌ای را انتخاب کنید', 'error'); return; }
    deleteMedia(ids);
}

// One button, no row-picking: removes every media item that matches what the
// table is currently filtered to. Two confirmations, because there is no undo.
function deleteMediaAllFiltered() {
    const btn = document.getElementById('mediaDelAll');
    if (mediaState.busy) return;

    const mtype = (document.getElementById('mediaType') || {}).value || 'all';
    const q     = ((document.getElementById('mediaSearch') || {}).value || '').trim();
    const gone  = !!(document.getElementById('mediaGoneOnly') && document.getElementById('mediaGoneOnly').checked);

    if (gone) {
        showToast('در حالت «فقط حذف‌شده‌ها» چیزی برای حذف نمانده است', 'error');
        return;
    }

    const typeLabel = { all: 'همه رسانه‌ها', video: 'همه ویدیوها', image: 'همه تصاویر', voice: 'همه پیام‌های صوتی' }[mtype] || 'همه رسانه‌ها';
    const scope = q ? (typeLabel + ' که با «' + q + '» مطابقت دارند') : typeLabel;
    const total = mediaState.total || 0;

    _mediaConfirm(
        'حذف یکجای ' + scope + '؟ ' +
        (total > 0 ? ('حدود ' + total.toLocaleString('fa-IR') + ' رسانه در این فیلتر وجود دارد. ') : '') +
        'فایل‌ها برای همیشه حذف می‌شوند و جای پیام‌ها با «این پیام حذف شده» باقی می‌ماند. این کار قابل بازگشت نیست.',
        function () {
            // Second gate: a single stray click on the loudest button on the
            // page must not be enough to empty the library.
            if (!window.confirm('تأیید نهایی: ' + scope + ' برای همیشه حذف شود؟')) return;
            _runDeleteMediaAll(mtype, q, btn);
        }
    );
}

function _runDeleteMediaAll(mtype, q, btn) {
    mediaState.busy = true;
    const oldLabel = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'در حال حذف…'; }
    _mediaPost('delete_media_all', { mtype: mtype, q: q, gone: 0 })
        .then(function (d) {
            mediaState.busy = false;
            if (btn) { btn.disabled = false; btn.textContent = oldLabel; }
            if (!d || d.status !== 'success') {
                showToast((d && d.message) || 'خطا در حذف رسانه‌ها', 'error');
                return;
            }
            showToast(d.message);
            mediaState.sel.clear();
            loadMediaList(1);
        })
        .catch(function () {
            mediaState.busy = false;
            if (btn) { btn.disabled = false; btn.textContent = oldLabel; }
            showToast('خطای شبکه', 'error');
        });
}

document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('smsCard')) loadSmsSettings();
    if (document.getElementById('mediaCard')) loadMediaList(1);
});

function performAction(action, id, extraData = {}) {    const fd = new FormData();
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
                loadUsers();   // keep the paged user table in sync after any action
            } else {
                showToast(d.message, 'error');
            }
        })
        .catch(() => showToast('خطای شبکه', 'error'));
}

function downloadBackup() {
    window.location.href = 'admin.php?backup=1&csrf_token=' + csrfToken;
}

const backupFileInput = document.getElementById('backupFileInput');
if (backupFileInput) {
    backupFileInput.addEventListener('change', function () {
        const f = this.files && this.files[0];
        const nameEl = document.getElementById('backupFileName');
        if (nameEl) nameEl.textContent = f ? f.name : 'فایلی انتخاب نشده';
    });
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

/**
 * Saves the maintenance note shown on the public lock screen.
 * Kept separate from the toggle so an admin can fix the wording without
 * taking the site down (and vice versa).
 */
function saveSiteLockReason() {
    const el = document.getElementById('siteLockReason');
    const msg = document.getElementById('siteLockReasonMsg');
    const btn = (typeof event !== 'undefined' && event && event.currentTarget) ? event.currentTarget : null;
    if (btn) btn.disabled = true;
    if (msg) { msg.textContent = 'در حال ذخیره…'; msg.style.color = 'var(--text-muted)'; }

    const fd = new FormData();
    fd.append('action', 'save_site_lock_reason');
    fd.append('reason', el ? el.value : '');

    fetch('admin.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'success') {
                if (msg) { msg.textContent = d.message; msg.style.color = '#6ee7b7'; }
                if (el) el.dataset.dirty = '';
                showToast(d.message, 'success');
            } else {
                if (msg) { msg.textContent = d.message || 'خطا'; msg.style.color = '#f87171'; }
                showToast(d.message || 'خطا در ذخیره', 'error');
            }
        })
        .catch(() => {
            if (msg) { msg.textContent = 'خطای شبکه'; msg.style.color = '#f87171'; }
        })
        .finally(() => { if (btn) btn.disabled = false; });
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

                // Only fill the box when the admin is not typing in it.
                const lockReasonEl = document.getElementById('siteLockReason');
                if (lockReasonEl && document.activeElement !== lockReasonEl && !lockReasonEl.dataset.dirty) {
                    lockReasonEl.value = d.site_lock_reason || '';
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
                updateReports(d.reports);
                updateBannedIps(d.banned_ips || []);
                updateChannelsList(d.rooms || []);
                loadAdminsList();
                loadUpdateNotifs();
                loadHideAdminOnlineState();
                loadDemoState();
                loadAccessState();
            }
        })
        .catch(() => { /* transient abort (navigation/connection churn) — the next 3 s tick recovers */ });
}

function previewBrandLogo(input) {
    const f = input.files && input.files[0];
    if (!f) return;
    if (f.size > 2 * 1024 * 1024) {
        showToast('حجم تصویر حداکثر ۲ مگابایت است', 'error');
        input.value = '';
        return;
    }
    const box = document.getElementById('brandLogoPreview');
    if (box) box.innerHTML = '<img src="' + URL.createObjectURL(f) + '" style="width:100%;height:100%;object-fit:contain;display:block;">';
}

function saveBranding() {
    const name = document.getElementById('brandNameInput').value.trim();
    const tag = document.getElementById('brandTaglineInput').value.trim();
    const ver = document.getElementById('siteVersionInput') ? document.getElementById('siteVersionInput').value.trim() : '';
    if (name.length < 2) return showToast('نام برند حداقل ۲ حرف باشد', 'error');
    const fd = new FormData();
    fd.append('action', 'save_branding');
    fd.append('csrf_token', csrfToken);
    fd.append('brand_name', name);
    fd.append('brand_tagline', tag);
    fd.append('site_version_label', ver);
    const fi = document.getElementById('brandLogoInput');
    if (fi && fi.files && fi.files[0]) fd.append('brand_logo', fi.files[0]);
    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            showToast(d.message, d.status === 'success' ? 'success' : 'error');
            if (d.status === 'success') setTimeout(() => location.reload(), 800);
        })
        .catch(() => showToast('خطای شبکه', 'error'));
}

function resetBrandLogo() {
    confirmAction('reset_brand_logo', 0, 'لوگوی فعلی حذف شود و حرف برند نمایش داده شود؟');
    document.getElementById('confirmYes').onclick = () => {
        closeModal('confirmModal');
        const fd = new FormData();
        fd.append('action', 'reset_brand_logo');
        fd.append('csrf_token', csrfToken);
        fetch('admin.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                showToast(d.message, d.status === 'success' ? 'success' : 'error');
                if (d.status === 'success') setTimeout(() => location.reload(), 800);
            })
            .catch(() => showToast('خطای شبکه', 'error'));
    };
}

const FONT_WEIGHTS = {
    default: [{ v: 400, label: 'معمولی' }],
    dana: [
        { v: 100, label: 'نازک' }, { v: 200, label: 'خیلی سبک' }, { v: 300, label: 'سبک' },
        { v: 400, label: 'معمولی' }, { v: 500, label: 'متوسط' }, { v: 700, label: 'بولد' },
        { v: 800, label: 'فوق بولد' }, { v: 900, label: 'سیاه' }
    ],
    kalameh: [
        { v: 100, label: 'نازک' }, { v: 300, label: 'سبک' }, { v: 400, label: 'معمولی' },
        { v: 500, label: 'متوسط' }, { v: 700, label: 'بولد' }, { v: 800, label: 'فوق بولد' },
        { v: 900, label: 'سیاه' }
    ]
};

function updateFontWeights(keep) {
    const famEl = document.getElementById('fontFamilySelect');
    const sel = document.getElementById('fontWeightSelect');
    if (!famEl || !sel) return;
    const list = FONT_WEIGHTS[famEl.value] || FONT_WEIGHTS.default;
    const prev = keep ? parseInt(sel.value, 10) : null;
    sel.innerHTML = list.map(function (o) {
        return '<option value="' + o.v + '">' + o.label + ' — ' + o.v + '</option>';
    }).join('');
    if (prev && list.some(function (o) { return o.v === prev; })) sel.value = String(prev);
    applyFontPreview();
}

function applyFontPreview() {
    const famEl = document.getElementById('fontFamilySelect');
    const wEl = document.getElementById('fontWeightSelect');
    const p = document.getElementById('fontPreview');
    if (!famEl || !wEl || !p) return;
    const fam = famEl.value;
    const w = parseInt(wEl.value, 10) || 400;
    p.style.fontFamily = fam === 'default' ? "'AppFont', sans-serif" : (fam === 'dana' ? "'PrevDana'" : "'PrevKalameh'");
    p.style.fontWeight = w;
}

function saveFont() {
    const fd = new FormData();
    fd.append('action', 'save_font');
    fd.append('csrf_token', csrfToken);
    fd.append('ui_font_family', document.getElementById('fontFamilySelect').value);
    fd.append('ui_font_weight', document.getElementById('fontWeightSelect').value);
    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            showToast(d.message, d.status === 'success' ? 'success' : 'error');
            if (d.status === 'success') setTimeout(() => location.reload(), 700);
        })
        .catch(() => showToast('خطای شبکه', 'error'));
}

/* ── Message-body font (admin only) ─────────────────────────────────────── */
function applyMsgFontPreview() {
    const famEl = document.getElementById('msgFontFamilySelect');
    const wEl = document.getElementById('msgFontWeightSelect');
    const sample = document.getElementById('msgFontSample');
    if (!famEl || !wEl || !sample) return;
    const fam = famEl.value;
    // The preview faces are already registered by #fontPreviewFaces.
    sample.style.fontFamily = fam === 'dana' ? "'PrevDana', inherit"
                            : fam === 'kalameh' ? "'PrevKalameh', inherit"
                            : 'inherit';
    sample.style.fontWeight = wEl.value;
}

function saveMsgFont() {
    const msg = document.getElementById('msgFontMsg');
    const famEl = document.getElementById('msgFontFamilySelect');
    const wEl = document.getElementById('msgFontWeightSelect');
    if (!famEl || !wEl) return;

    const fd = new FormData();
    fd.append('action', 'save_msg_font');
    fd.append('csrf_token', csrfToken);
    fd.append('msg_font_family', famEl.value);
    fd.append('msg_font_weight', wEl.value);

    if (msg) { msg.textContent = 'در حال ذخیره…'; msg.style.color = 'var(--text-muted)'; }
    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'success') {
                if (msg) { msg.textContent = 'ذخیره شد ✓'; msg.style.color = '#6ee7b7'; }
                showToast(d.message, 'success');
                setTimeout(() => location.reload(), 800);
            } else {
                if (msg) { msg.textContent = d.message || 'خطا'; msg.style.color = '#f87171'; }
                showToast(d.message || 'خطا در ذخیره', 'error');
            }
        })
        .catch(() => {
            if (msg) { msg.textContent = 'خطای شبکه'; msg.style.color = '#f87171'; }
            showToast('خطای شبکه', 'error');
        });
}

(function initMsgFontUI() {
    if (document.getElementById('msgFontFamilySelect')) applyMsgFontPreview();
})();

(function initFontUI() {
    const famEl = document.getElementById('fontFamilySelect');
    if (!famEl) return;
    updateFontWeights(false);
    const saved = '<?php echo (int)$uiFontWeight; ?>';
    const sel = document.getElementById('fontWeightSelect');
    if (sel && [].some.call(sel.options, function (o) { return o.value === saved; })) sel.value = saved;
    applyFontPreview();
})();

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

// ── Users table: server-side search + paging ────────────────────────────────
// Only one page of accounts ever reaches the browser, so a 10k-user install
// renders exactly as fast as a 10-user one.
let usersPageCache = [];
let usersTotal = 0;
let usersPages = 1;
let currentPage = 1;
let usersPerPage = 10;
let usersQuery = '';
let _usersReqId = 0;
let _usersSearchTimer = null;

function loadUsers(page, q) {
    if (page !== undefined && page !== null) currentPage = Math.max(1, parseInt(page, 10) || 1);
    if (q !== undefined && q !== null) usersQuery = String(q);

    const fd = new FormData();
    fd.append('action', 'get_users');
    fd.append('csrf_token', csrfToken);
    fd.append('page', currentPage);
    fd.append('per', usersPerPage);
    fd.append('q', usersQuery);

    const reqId = ++_usersReqId;
    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (reqId !== _usersReqId) return; // a newer search already won
            if (d.status !== 'success') { showToast(d.message || 'خطا در بارگذاری کاربران', 'error'); return; }
            usersPageCache = d.users || [];
            usersTotal = d.total || 0;
            usersPages = d.pages || 1;
            currentPage = d.page || 1;
            renderUsers();
        })
        .catch(() => showToast('خطای شبکه', 'error'));
}

function renderUsers() {
    const tbody = document.getElementById('usersList');
    if (!tbody) return;

    if (!usersPageCache.length) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center; color:#94a3b8; padding:22px">کاربری با این مشخصات پیدا نشد</td></tr>';
    } else {
        let html = '';
        usersPageCache.forEach(u => {
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
    }

    // Compact pager: first, last, and a window around the current page.
    let pagHtml = '';
    const pages = Math.max(1, usersPages);
    const win = 2;
    let lastDrawn = 0;
    for (let i = 1; i <= pages; i++) {
        const visible = (i === 1 || i === pages || Math.abs(i - currentPage) <= win);
        if (!visible) continue;
        if (lastDrawn && i - lastDrawn > 1) pagHtml += '<span style="opacity:.4;margin:0 4px">…</span>';
        pagHtml += `<button onclick="changePage(${i})" class="btn ${i === currentPage ? 'btn-primary' : 'btn-ghost'} btn-sm" style="margin:2px; min-width:35px">${i}</button>`;
        lastDrawn = i;
    }
    const pagBox = document.getElementById('paginationControls');
    if (pagBox) pagBox.innerHTML = pagHtml;

    const sum = document.getElementById('usersSummary');
    if (sum) {
        sum.textContent = usersTotal === 0
            ? 'کاربری یافت نشد'
            : `${usersTotal.toLocaleString('fa-IR')} کاربر — صفحه ${currentPage} از ${pages}`;
    }
}

function changePage(p) {
    loadUsers(p);
}

function filterUsers() {
    // Debounced: typing "ali" fires one request, not three.
    clearTimeout(_usersSearchTimer);
    _usersSearchTimer = setTimeout(function () {
        const el = document.getElementById('searchUser');
        loadUsers(1, el ? el.value.trim() : '');
    }, 300);
}

setInterval(refreshData, 3000);
refreshData();
loadUsers(1, '');

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
                const isFull = (a.level === 'full');
                const levelBadge = isFull
                    ? '<span style="display:inline-block;background:rgba(99,102,241,0.18);border:1px solid rgba(99,102,241,0.4);color:#a5b4fc;border-radius:999px;padding:2px 10px;font-size:11px;">🛡 کامل</span>'
                    : '<span style="display:inline-block;background:rgba(245,158,11,0.14);border:1px solid rgba(245,158,11,0.35);color:#fbbf24;border-radius:999px;padding:2px 10px;font-size:11px;">👁 محدود</span>';
                const nextLevel = isFull ? 'limited' : 'full';
                return `<tr>
                    <td style="font-weight:bold;font-family:monospace;">${escapeAdminHtml(a.username)}</td>
                    <td>${escapeAdminHtml(a.display_name || '—')}</td>
                    <td>${levelBadge}</td>
                    <td style="font-size:12px;color:#64748b;">${lastLogin}</td>
                    <td>${activeLabel}</td>
                    <td style="display:flex;gap:6px;">
                        <button onclick="setAdminLevel(${a.id},'${escapeAdminHtml(a.username)}','${nextLevel}')" class="btn btn-sm" style="background:rgba(99,102,241,0.2);color:#a5b4fc;border:1px solid rgba(99,102,241,0.3);" title="تغییر سطح دسترسی">${isFull ? 'محدود کن' : 'کامل کن'}</button>
                        <button onclick="toggleAdminActive(${a.id})" class="btn btn-sm" style="background:rgba(99,102,241,0.2);color:#a5b4fc;border:1px solid rgba(99,102,241,0.3);">${a.is_active ? 'غیرفعال' : 'فعال'}</button>
                        <button onclick="removeAdmin(${a.id},'${escapeAdminHtml(a.username)}')" class="btn btn-danger btn-sm">🗑</button>
                    </td>
                </tr>`;
            }).join('');
        });
}

function loadSocialSettings() {
    performActionCustom('get_social_settings', {}, d => {
        if (d.status !== 'success') return;
        document.getElementById('socialEnabled').checked = !!d.enabled;
        document.getElementById('socialGithubOn').checked = !!d.github_on;
        document.getElementById('socialWebsiteOn').checked = !!d.website_on;
        document.getElementById('socialTelegramOn').checked = !!d.telegram_on;
        document.getElementById('socialGithubLink').value = d.github || '';
        document.getElementById('socialWebsiteLink').value = d.website || '';
        document.getElementById('socialTelegramLink').value = d.telegram || '';
    });
}
function saveSocialSettings() {
    performActionCustom('save_social_settings', {
        enabled: document.getElementById('socialEnabled').checked ? '1' : '0',
        github_on: document.getElementById('socialGithubOn').checked ? '1' : '0',
        website_on: document.getElementById('socialWebsiteOn').checked ? '1' : '0',
        telegram_on: document.getElementById('socialTelegramOn').checked ? '1' : '0',
        github: document.getElementById('socialGithubLink').value.trim(),
        website: document.getElementById('socialWebsiteLink').value.trim(),
        telegram: document.getElementById('socialTelegramLink').value.trim()
    }, d => showToast(d.message));
}

function saveAdminDisplayName() {
    const v = document.getElementById('adminDisplayNameInput').value.trim();
    if (!v) return showToast('نام نمایشی را وارد کنید', 'error');
    performActionCustom('save_admin_display', { display_name: v }, d => showToast(d.message));
}

function addAdmin() {
    const uname = document.getElementById('newAdminUser').value.trim();
    const pass  = document.getElementById('newAdminPass').value;
    const dname = document.getElementById('newAdminDisplay').value.trim();
    const level = (document.getElementById('newAdminLevel') || {}).value || 'limited';
    if (!uname || !pass) return showToast('نام کاربری و رمز عبور الزامی است', 'error');
    if (pass.length < 8) return showToast('رمز عبور باید حداقل ۸ کاراکتر باشد', 'error');
    performActionCustom('add_admin', { username: uname, password: pass, display_name: dname, level }, d => {
        showToast(d.message);
        document.getElementById('newAdminUser').value = '';
        document.getElementById('newAdminPass').value = '';
        document.getElementById('newAdminDisplay').value = '';
        loadAdminsList();
    });
}

function setAdminLevel(id, name, level) {
    const label = level === 'full' ? 'کامل' : 'محدود';
    if (!confirm(`سطح دسترسی «${name}» به «${label}» تغییر کند؟`)) return;
    performActionCustom('set_admin_level', { admin_id: id, level }, d => {
        showToast(d.message);
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

// ── Demo mode ───────────────────────────────────────────────────────────────
function loadAccessState() {
    const fd = new FormData();
    fd.append('action', 'get_access_state');
    fd.append('csrf_token', csrfToken);
    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status !== 'success') return;
            applyAccessState(d.lock_register == 1, d.lock_login == 1);
        })
        .catch(() => {});
}

function applyAccessState(reg, login) {
    const regCb = document.getElementById('lockRegisterToggle');
    const logCb = document.getElementById('lockLoginToggle');
    if (regCb) regCb.checked = !!reg;
    if (logCb) logCb.checked = !!login;

    const regPill = document.getElementById('accessRegPill');
    if (regPill) {
        regPill.dataset.on = reg ? '1' : '0';
        regPill.textContent = reg ? 'ثبت‌نام بسته' : 'ثبت‌نام باز';
    }
    const logPill = document.getElementById('accessLoginPill');
    if (logPill) {
        logPill.dataset.on = login ? '1' : '0';
        logPill.textContent = login ? 'ورود بسته' : 'ورود باز';
    }
}

function toggleAccessLock(kind, on) {
    const action = (kind === 'register') ? 'toggle_register_lock' : 'toggle_login_lock';
    const fd = new FormData();
    fd.append('action', action);
    fd.append('enabled', on ? '1' : '0');
    fd.append('csrf_token', csrfToken);
    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status !== 'success') {
                showToast(d.message || 'خطا');
                loadAccessState();
                return;
            }
            showToast(d.message || 'ذخیره شد');
            loadAccessState();
        })
        .catch(() => { showToast('خطا در ارتباط'); loadAccessState(); });
}

function loadDemoState() {
    const fd = new FormData();
    fd.append('action', 'get_demo_state');
    fd.append('csrf_token', csrfToken);
    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status !== 'success') return;
            const on = d.enabled == 1;
            const cb = document.getElementById('demoEnabled');
            if (cb) cb.checked = on;
            const dot = document.getElementById('demoStatusDot');
            const lbl = document.getElementById('demoStatusLabel');
            if (dot) dot.style.background = on ? '#34d399' : '#64748b';
            if (lbl) {
                lbl.textContent = on ? 'فعال' : 'غیرفعال';
                lbl.style.color = on ? '#34d399' : 'var(--text-muted)';
            }
            const ac = document.getElementById('demoActiveCount');
            if (ac) ac.textContent = d.active;
        })
        .catch(() => {});
}

function toggleDemoMode(on) {
    const fd = new FormData();
    fd.append('action', 'toggle_demo_mode');
    fd.append('enabled', on ? '1' : '0');
    fd.append('csrf_token', csrfToken);
    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status !== 'success') { showToast(d.message || 'خطا'); loadDemoState(); return; }
            showToast(d.message);
            loadDemoState();
        })
        .catch(() => { showToast('خطا در ارتباط'); loadDemoState(); });
}

function purgeDemoData() {
    if (!confirm('همه حساب‌های نمایشی و تمام پیام‌هایشان حذف می‌شوند. ادامه می‌دهید؟')) return;
    const btn = document.getElementById('demoPurgeBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'در حال پاکسازی…'; }
    const fd = new FormData();
    fd.append('action', 'purge_demo_data');
    fd.append('csrf_token', csrfToken);
    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            showToast(d.message || 'انجام شد');
            loadDemoState();
        })
        .catch(() => showToast('خطا در ارتباط'))
        .finally(() => {
            if (btn) { btn.disabled = false; btn.textContent = '🗑 پاکسازی فوری همه داده‌های دمو'; }
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

function performActionCustom(action, data, successCb, retry) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', csrfToken);
    for (const k in data) fd.append(k, data[k]);
    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'success') {
                // A throwing handler must not fall into the network catch below
                // and masquerade as a connection failure.
                try { if (successCb) successCb(d); } catch (e) { console.error('[VIRAX admin] ' + action + ' handler: ' + (e && e.message || e)); }
            } else showToast(d.message || 'خطا', 'error');
        })
        .catch(() => {
            // Page-load hand-off (chat long-poll abort, connection churn) can
            // kill a single init request — one silent retry before alarming
            // the admin, so the panel never opens with a phantom error toast.
            if (!retry) { performActionCustom(action, data, successCb, true); return; }
            showToast('خطا در ارتباط', 'error');
        });
}

function escapeAdminHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>'"]/g, t => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[t]));
}

function setWsStatusDot(status) {
    const dot = document.getElementById('wsStatusDot');
    const label = document.getElementById('wsStatusLabel');
    if (!dot || !label) return;
    if (status === 'online') {
        dot.style.background = '#10b981';
        label.textContent = 'متصل';
        label.style.color = '#34d399';
    } else if (status === 'offline') {
        dot.style.background = '#ef4444';
        label.textContent = 'قطع';
        label.style.color = '#f87171';
    } else {
        dot.style.background = '#64748b';
        label.textContent = 'بررسی نشده';
        label.style.color = 'var(--text-muted)';
    }
}

function togglePassVisibility(inputId, btn) {
    const el = document.getElementById(inputId);
    if (!el) return;
    const show = el.type === 'password';
    el.type = show ? 'text' : 'password';
    btn.textContent = show ? '🙈' : '👁';
    btn.setAttribute('aria-label', show ? 'پنهان کردن' : 'نمایش');
}

function loadAiSettings() {
    performActionCustom('get_ai_settings', {}, d => {
        document.getElementById('aiEnabled').checked = !!d.ai_enabled;
        document.getElementById('aiBaseUrl').value = d.ai_base_url || '';
        document.getElementById('aiModel').value = d.ai_model || '';
        document.getElementById('aiSystemPrompt').value = d.ai_system_prompt || '';
        document.getElementById('aiBehavior').value = d.ai_behavior || '';
        document.getElementById('aiMaxTokens').value = d.ai_max_tokens || 800;
        document.getElementById('aiTemperature').value = d.ai_temperature ?? 0.7;
        document.getElementById('aiTimeout').value = d.ai_timeout || 30;
        document.getElementById('aiCooldown').value = d.ai_cooldown ?? 5;
        document.getElementById('aiMaxAttempts').value = d.ai_max_attempts ?? 3;
        document.getElementById('aiRooms').value = d.ai_rooms || '';
        document.getElementById('aiAllRooms').checked = (d.ai_all_rooms !== false);
        document.getElementById('aiPv').checked = (d.ai_pv !== false);
        document.getElementById('aiUserLimitCount').value = d.ai_user_limit_count ?? 0;
        document.getElementById('aiUserLimitWindow').value = d.ai_user_limit_window ?? 0;
        document.getElementById('aiKeyword').value = d.ai_keyword || 'ویراکس';
        // Stored key is "mention_only" (strict); the toggle is its inverse.
        document.getElementById('aiReplyAll').checked = (d.ai_mention_only === false);
        document.getElementById('aiKeyMasked').textContent = d.ai_api_key_set ? ('کلید فعلی: ' + d.ai_api_key_masked) : 'کلیدی ذخیره نشده است';
    });
}

function saveAiSettings() {
    const data = {
        ai_enabled: document.getElementById('aiEnabled').checked ? '1' : '0',
        ai_base_url: document.getElementById('aiBaseUrl').value.trim(),
        ai_model: document.getElementById('aiModel').value.trim(),
        ai_system_prompt: document.getElementById('aiSystemPrompt').value.trim(),
        ai_behavior: document.getElementById('aiBehavior').value.trim(),
        ai_max_tokens: document.getElementById('aiMaxTokens').value || 800,
        ai_temperature: document.getElementById('aiTemperature').value ?? 0.7,
        ai_timeout: document.getElementById('aiTimeout').value || 60,
        ai_cooldown: document.getElementById('aiCooldown').value ?? 5,
        ai_max_attempts: document.getElementById('aiMaxAttempts').value ?? 3,
        ai_rooms: document.getElementById('aiRooms').value.trim(),
        ai_all_rooms: document.getElementById('aiAllRooms').checked ? '1' : '0',
        ai_pv: document.getElementById('aiPv').checked ? '1' : '0',
        ai_mention_only: document.getElementById('aiReplyAll').checked ? '0' : '1',
        ai_user_limit_count: document.getElementById('aiUserLimitCount').value || 0,
        ai_user_limit_window: document.getElementById('aiUserLimitWindow').value || 0,
        ai_keyword: document.getElementById('aiKeyword').value.trim()
    };
    const key = document.getElementById('aiApiKey').value.trim();
    if (key) data.ai_api_key = key;
    performActionCustom('save_ai_settings', data, d => {
        showToast(d.message);
        document.getElementById('aiApiKey').value = '';
        loadAiSettings();
    });
}

function testAiConnection() {
    const data = {
        ai_base_url: document.getElementById('aiBaseUrl').value.trim(),
        ai_model: document.getElementById('aiModel').value.trim(),
        ai_max_tokens: document.getElementById('aiMaxTokens').value || 800,
        ai_timeout: document.getElementById('aiTimeout').value || 60
    };
    const key = document.getElementById('aiApiKey').value.trim();
    if (key) data.ai_api_key = key;
    const fd = new FormData();
    fd.append('action', 'test_ai_connection');
    fd.append('csrf_token', csrfToken);
    for (const k in data) fd.append(k, data[k]);
    const toast = showToast('در حال تست اتصال...', 'info');
    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            showToast(d.message, d.status === 'success' ? 'success' : 'error');
        })
        .catch(() => showToast('خطا در تست اتصال', 'error'));
}

function createBackup(kind) {
    performActionCustom('create_backup', { kind }, d => {
        showToast(d.message);
        loadBackupList();
    });
}

function loadBackupList() {
    performActionCustom('get_backup_list', {}, d => {
        const wrap = document.getElementById('backupList');
        if (!wrap) return;
        if (!d.backups || d.backups.length === 0) {
            wrap.innerHTML = '<div style="color:var(--text-muted); font-size:0.85rem; text-align:center; padding:14px;">هنوز پشتیبانی ساخته نشده است</div>';
            return;
        }
        wrap.innerHTML = d.backups.map(b => {
            const kb = Math.max(1, Math.round(b.size / 1024));
            const sizeLabel = kb > 1024 ? (kb / 1024).toFixed(1) + ' MB' : kb + ' KB';
            const when = new Date(b.mtime * 1000).toLocaleString('fa-IR');
            return `<div style="display:flex; align-items:center; justify-content:space-between; gap:10px; background:rgba(0,0,0,0.2); border:1px solid var(--border); border-radius:10px; padding:10px 14px; flex-wrap:wrap;">
                <span style="font-family:monospace; font-size:0.82rem; color:#a5b4fc; direction:ltr;">${b.name}</span>
                <span style="font-size:0.8rem; color:var(--text-muted);">${sizeLabel} • ${when}</span>
                <span style="display:flex; gap:6px;">
                    <button class="btn btn-ghost btn-sm" onclick="downloadBackup('${b.name}')">⬇ دانلود</button>
                    <button class="btn btn-ghost btn-sm" style="color:#f87171;" onclick="deleteBackup('${b.name}')">🗑 حذف</button>
                </span>
            </div>`;
        }).join('');
    });
}

function downloadBackup(name) {
    const fd = new FormData();
    fd.append('action', 'download_backup');
    fd.append('csrf_token', csrfToken);
    fd.append('filename', name);
    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.ok ? r.blob() : Promise.reject())
        .then(blob => {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = name;
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(url), 4000);
        })
        .catch(() => showToast('دانلود ناموفق بود', 'error'));
}

function deleteBackup(name) {
    if (!confirm('این پشتیبان حذف شود؟ ' + name)) return;
    performActionCustom('delete_backup', { filename: name }, d => {
        showToast(d.message);
        loadBackupList();
    });
}

function resetBackups() {
    if (!confirm('⚠️ همه‌ی فایل‌های پشتیبان و تاریخچه‌ی آن‌ها حذف شوند؟ این کار قابل بازگشت نیست.')) return;
    if (!confirm('مطمئنید؟ آخرین پشتیبان‌ها هم پاک می‌شوند و دیگر نسخه‌ای برای بازگردانی نمی‌ماند.')) return;
    performActionCustom('reset_backups', {}, d => {
        showToast(d.message);
        loadBackupList();
    });
}

function loadWsSettings() {
    performActionCustom('get_ws_settings', {}, d => {
        const en = document.getElementById('wsEnabled');
        if (en) en.checked = !!d.ws_enabled;
        document.getElementById('wsUrl').value = d.ws_url || '';
        document.getElementById('wsInternalUrl').value = d.ws_internal_url || '';
        document.getElementById('wsOrigins').value = d.ws_origins || '*';
        document.getElementById('wsReconnect').value = d.ws_reconnect || 5;
        updateWsEndpointDisplay();
        if (d.ws_enabled && (d.ws_url || d.ws_internal_url)) {
            testWsConnection(true);
        }
    });
}

function updateWsEndpointDisplay() {
    const el = document.getElementById('wsEndpointDisplay');
    if (!el) return;
    const url = document.getElementById('wsUrl').value.trim();
    el.textContent = url || 'آدرس عمومی تنظیم نشده — کاربران در حالت عادی چت می‌کنند';
}

function copyWsEndpoint() {
    const url = document.getElementById('wsUrl').value.trim();
    if (!url) return showToast('ابتدا آدرس WebSocket را ذخیره کنید', 'error');
    navigator.clipboard.writeText(url).then(() => showToast('آدرس WebSocket کپی شد'));
}

function saveWsSettings() {
    const data = {
        ws_enabled: document.getElementById('wsEnabled').checked ? '1' : '0',
        ws_url: document.getElementById('wsUrl').value.trim(),
        ws_internal_url: document.getElementById('wsInternalUrl').value.trim(),
        ws_origins: document.getElementById('wsOrigins').value.trim() || '*',
        ws_reconnect: document.getElementById('wsReconnect').value || 5
    };
    performActionCustom('save_ws_settings', data, d => {
        showToast(d.message);
        updateWsEndpointDisplay();
    });
}

function testWsConnection(silent) {
    setWsStatusDot('checking');
    const fd = new FormData();
    fd.append('action', 'test_ws_connection');
    fd.append('csrf_token', csrfToken);
    fetch('admin.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'success') {
                setWsStatusDot('online');
                if (!silent) showToast(d.message + (d.clients !== null && d.clients !== undefined ? ' (' + d.clients + ' کلاینت)' : ''));
            } else {
                setWsStatusDot('offline');
                if (!silent) showToast(d.message, 'error');
            }
        })
        .catch(() => {
            setWsStatusDot('offline');
            if (!silent) showToast('خطا در بررسی اتصال', 'error');
        });
}

document.addEventListener('DOMContentLoaded', function() {
    loadWsSettings();
    loadAiSettings();
    loadBackupList();
    if (document.getElementById('socialCard')) loadSocialSettings();
    const wsUrlInput = document.getElementById('wsUrl');
    if (wsUrlInput) wsUrlInput.addEventListener('input', updateWsEndpointDisplay);
});

function toggleAdminNav() {
    const d = document.getElementById('adminNavDrawer');
    const b = document.getElementById('adminNavBackdrop');
    if (!d) return;
    const open = !d.classList.contains('open');
    d.classList.toggle('open', open);
    if (b) b.classList.toggle('open', open);
    document.body.style.overflow = open ? 'hidden' : '';
}

function closeAdminNav() {
    const d = document.getElementById('adminNavDrawer');
    const b = document.getElementById('adminNavBackdrop');
    if (d) d.classList.remove('open');
    if (b) b.classList.remove('open');
    document.body.style.overflow = '';
}

function initAdminNav() {
    const list = document.getElementById('adminNavList');
    if (!list || list.childElementCount) return;
    const cards = [].filter.call(document.querySelectorAll('.container .card'), function (c) {
        return c.offsetParent !== null && c.querySelector('.card-title');
    });
    const items = [];
    cards.forEach(function (c, i) {
        const t = c.querySelector('.card-title');
        if (!c.id) c.id = 'admin-card-' + i;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'admin-nav-item';
        btn.setAttribute('data-target', c.id);
        btn.innerHTML = '<span class="dot"></span><span>' + t.textContent.trim() + '</span>';
        btn.onclick = function () {
            closeAdminNav();
            setTimeout(function () { c.scrollIntoView({ behavior: 'smooth', block: 'start' }); }, 120);
        };
        list.appendChild(btn);
        items.push(btn);
    });
    if (!items.length) return;
    if ('IntersectionObserver' in window) {
        const obs = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (en.isIntersecting) {
                    items.forEach(function (b) {
                        b.classList.toggle('active', b.getAttribute('data-target') === en.target.id);
                    });
                }
            });
        }, { rootMargin: '-15% 0px -70% 0px', threshold: 0 });
        cards.forEach(function (c) { obs.observe(c); });
    }
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAdminNav(); });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdminNav);
} else {
    initAdminNav();
}

var AP_PRESETS = ['#6366f1','#3b82f6','#0ea5e9','#06b6d4','#14b8a6','#10b981','#84cc16','#eab308','#f59e0b','#f97316','#ef4444','#ec4899','#8b5cf6','#d946ef','#f43f5e','#64748b'];
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
    for (var i = 0; i < dots.length; i++) dots[i].classList.toggle('active', dots[i].getAttribute('data-c') === c);
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

function apPick(color) { veyraxApplyTheme(color); }

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

document.addEventListener('DOMContentLoaded', function () {
    var st = window.veyraxThemeState ? window.veyraxThemeState() : { color: '#6366f1', scale: 1 };
    apBuild();
    apSync(st.color);
    apSyncScale(st.scale);
});

var VX_ADMIN_CACHE_CFG = { epoch: 0, ttl: 86400000 };

function netPost(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', csrfToken);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch('admin.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); });
}

function loadRuntimeSettings() {
    netPost('get_runtime_settings').then(function (d) {
        if (!d || d.status !== 'success') return;
        var tg = document.getElementById('reqModeTelegram');
        var nm = document.getElementById('reqModeNormal');
        if (tg && nm) {
            if (d.request_mode === 'normal') nm.checked = true; else tg.checked = true;
        }
        var ttl = document.getElementById('cacheTtlInput');
        if (ttl) ttl.value = d.cache_ttl_hours || 24;
        VX_ADMIN_CACHE_CFG.epoch = d.cache_epoch || 0;
        VX_ADMIN_CACHE_CFG.ttl = (d.cache_ttl_hours || 24) * 3600 * 1000;
        if (window.VXCache) {
            VXCache.configure({ ttl: VX_ADMIN_CACHE_CFG.ttl, version: 'admin', epoch: VX_ADMIN_CACHE_CFG.epoch });
        }
        refreshCacheStats();
    }).catch(function () {});
}

function refreshCacheStats() {
    if (!window.VXCache) return;
    var s = VXCache.stats();
    var e = document.getElementById('nsEntries');
    var z = document.getElementById('nsSize');
    var a = document.getElementById('nsAge');
    var ep = document.getElementById('nsEpoch');
    if (e) e.textContent = s.entries;
    if (z) z.textContent = (s.bytes < 1024 ? s.bytes + ' B' : (s.bytes / 1024).toFixed(1) + ' KB');
    if (a) a.textContent = s.oldest ? timeAgoFa(s.oldest) : '—';
    if (ep) ep.textContent = VX_ADMIN_CACHE_CFG.epoch;
}

function timeAgoFa(ts) {
    var diff = Math.max(0, Date.now() - ts);
    var m = Math.floor(diff / 60000);
    if (m < 1) return 'همین حالا';
    if (m < 60) return m + ' دقیقه';
    var h = Math.floor(m / 60);
    if (h < 24) return h + ' ساعت';
    return Math.floor(h / 24) + ' روز';
}

function saveRuntimeSettings() {
    var mode = 'telegram';
    var nm = document.getElementById('reqModeNormal');
    if (nm && nm.checked) mode = 'normal';
    var ttl = parseInt(document.getElementById('cacheTtlInput').value, 10) || 24;
    netPost('save_runtime_settings', { request_mode: mode, cache_ttl_hours: ttl }).then(function (d) {
        if (d && d.status === 'success') {
            VX_ADMIN_CACHE_CFG.ttl = ttl * 3600 * 1000;
            if (window.VXUI) VXUI.toast(d.message || 'ذخیره شد', 'success');
            else if (window.showToast) showToast(d.message || 'ذخیره شد');
            refreshCacheStats();
        } else {
            var msg = (d && d.message) || 'خطا در ذخیره';
            if (window.VXUI) VXUI.toast(msg, 'error'); else if (window.showToast) showToast(msg);
        }
    }).catch(function () {
        if (window.VXUI) VXUI.toast('ارتباط برقرار نشد', 'error');
    });
}

function purgeLocalCache() {
    var run = function () {
        if (window.VXCache) VXCache.clearAll();
        refreshCacheStats();
        if (window.VXUI) VXUI.toast('کش این مرورگر پاک شد', 'success');
        else if (window.showToast) showToast('کش این مرورگر پاک شد');
    };
    if (window.VXUI && VXUI.confirm) {
        VXUI.confirm({ title: 'پاک‌سازی کش', text: 'داده‌های ذخیره‌شده در این مرورگر حذف می‌شود. ادامه می‌دهید؟', confirmText: 'پاک کن' }).then(function (ok) { if (ok) run(); });
    } else run();
}

function forceReloadCache() {
    var run = function () {
        if (window.VXCache) VXCache.clearAll();
        try { localStorage.setItem('vx_force_reload', String(Date.now())); } catch (e) {}
        location.reload();
    };
    if (window.VXUI && VXUI.confirm) {
        VXUI.confirm({ title: 'بازخوانی اجباری', text: 'کش پاک می‌شود و صفحه با داده‌های تازه از سرور بارگذاری می‌شود.', confirmText: 'بارگذاری مجدد' }).then(function (ok) { if (ok) run(); });
    } else run();
}

function bumpCacheEpoch() {
    if (window.VXUI && VXUI.confirm) {
        VXUI.confirm({
            title: 'پاک‌سازی کش همه کاربران',
            html: 'کش ذخیره‌شده روی <b>همه دستگاه‌ها</b> در اولین بازدید بعدی پاک می‌شود.<br>این عملیات برای کاربران فقط یک بار بارگذاری تازه ایجاد می‌کند.',
            danger: true,
            confirmText: 'پاک‌سازی کن'
        }).then(function (ok) { if (ok) doBumpEpoch(); });
    } else if (confirm('کش همه کاربران پاک شود؟')) doBumpEpoch();
}

function doBumpEpoch() {
    netPost('bump_cache_epoch').then(function (d) {
        if (d && d.status === 'success') {
            VX_ADMIN_CACHE_CFG.epoch = d.cache_epoch;
            if (window.VXCache) VXCache.configure({ epoch: d.cache_epoch, version: 'admin' });
            refreshCacheStats();
            if (window.VXUI) VXUI.toast(d.message || 'انجام شد', 'success');
            else if (window.showToast) showToast(d.message || 'انجام شد');
        } else {
            var msg = (d && d.message) || 'خطا';
            if (window.VXUI) VXUI.toast(msg, 'error'); else if (window.showToast) showToast(msg);
        }
    }).catch(function () {
        if (window.VXUI) VXUI.toast('ارتباط برقرار نشد', 'error');
    });
}

document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('networkCard')) {
        setTimeout(loadRuntimeSettings, 350);
        setInterval(refreshCacheStats, 15000);
    }
});

</script>

<div id="adminNavBackdrop" class="admin-nav-backdrop" onclick="closeAdminNav()"></div>
<aside id="adminNavDrawer" class="admin-nav-drawer" aria-label="بخش‌های پنل">
    <div class="admin-nav-head">
        <span>پرش سریع به بخش‌ها</span>
        <button type="button" class="admin-nav-close" onclick="closeAdminNav()" aria-label="بستن">✕</button>
    </div>
    <div class="admin-nav-list" id="adminNavList"></div>
</aside>
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