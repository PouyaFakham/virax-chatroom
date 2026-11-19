<?php

// Bump this whenever the schema below changes. config.php stores it in the
// settings table and skips the whole migration when it already matches, which
// keeps every request fast. Keep the value in sync with config.php.
if (!defined('VEYRAX_SCHEMA_VERSION')) define('VEYRAX_SCHEMA_VERSION', '2026.11.12.1');

if (!defined('VEYRAX_SCHEMA_PHP')) {
    define('VEYRAX_SCHEMA_PHP', 1);

    function veyrax_detect_mysql($pdo) {
        try {
            $v = $pdo->query("SELECT VERSION()")->fetchColumn();
            $isMaria = stripos($v, 'MariaDB') !== false;
            $clean = preg_replace('/[^0-9.].*$/', '', (string)$v);
            return ['version' => $v, 'is_maria' => $isMaria, 'label' => ($isMaria ? 'MariaDB' : 'MySQL') . ' ' . $clean];
        } catch (Exception $e) {
            return ['version' => '', 'is_maria' => false, 'label' => 'نامشخص'];
        }
    }

    function veyrax_schema($pdo, $prefix = '', $onProgress = null) {
        $prefix = preg_replace('/[^A-Za-z0-9_]/', '', (string)$prefix);
        $t = function ($name) use ($prefix) { return $prefix . $name; };
        $step = function ($label) use ($onProgress) {
            if (is_callable($onProgress)) $onProgress($label);
        };
        $charset = 'utf8mb4';
        $collate = 'utf8mb4_general_ci';

        $step('ایجاد جدول کاربران');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `" . $t('users') . "` (
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
            auth_password VARCHAR(255) DEFAULT NULL,
            hide_last_seen TINYINT DEFAULT 0,
            INDEX(last_activity),
            INDEX(is_online),
            INDEX(username),
            INDEX(device_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate");

        $cols = [
            'sticker'                 => "VARCHAR(10) DEFAULT '👤'",
            'is_banned_until'         => "INT DEFAULT 0",
            'muted_until'             => "INT DEFAULT 0",
            'is_online'               => "TINYINT DEFAULT 0",
            'notifications_enabled'   => "TINYINT DEFAULT 1",
            'user_agent'              => "VARCHAR(512) DEFAULT NULL",
            'ip_address'              => "VARCHAR(45) DEFAULT NULL",
            'password_hash'           => "VARCHAR(255) DEFAULT NULL",
            'last_notif_id'           => "INT DEFAULT 0",
            'typing_context'          => "VARCHAR(100) DEFAULT NULL",
            'typing_time'             => "INT DEFAULT 0",
            'device_token'            => "VARCHAR(64) DEFAULT NULL",
            'security_code'           => "VARCHAR(20) DEFAULT NULL",
            'phone_number'            => "VARCHAR(15) DEFAULT NULL",
            'auth_password'           => "VARCHAR(255) DEFAULT NULL",
            'phone_auth_attempts'     => "TINYINT DEFAULT 0",
            'phone_auth_locked_until' => "INT DEFAULT 0",
            'hide_last_seen'          => "TINYINT DEFAULT 0",
            'created_at'              => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
            'login_code_attempts'     => "TINYINT DEFAULT 0",
            'login_code_locked_until' => "INT DEFAULT 0",
            'theme_color'             => "VARCHAR(9) DEFAULT NULL",
            'font_scale'              => "DECIMAL(3,2) DEFAULT NULL",
            'reset_hash'              => "VARCHAR(64) DEFAULT NULL",
            'reset_attempts'          => "TINYINT DEFAULT 0",
            'reset_expires'           => "INT DEFAULT 0",
            'welcome_seen'            => "TINYINT DEFAULT 0",
            'fp'                      => "VARCHAR(64) DEFAULT NULL",
            'fp_coarse'               => "VARCHAR(64) DEFAULT NULL",
        ];
        // One SHOW COLUMNS instead of one query per column, and never run an
        // unconditional MODIFY (that rebuilt the whole table on every request).
        $existingCols = [];
        try {
            foreach ($pdo->query("SHOW COLUMNS FROM `" . $t('users') . "`")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                $existingCols[$c['Field']] = $c;
            }
        } catch (Exception $e) {}
        foreach ($cols as $col => $def) {
            if (!isset($existingCols[$col])) {
                try { $pdo->exec("ALTER TABLE `" . $t('users') . "` ADD COLUMN $col $def"); }
                catch (Exception $e) { error_log('[VIRAX schema] users.' . $col . ': ' . $e->getMessage()); }
            } elseif ($col === 'user_agent' && stripos((string)$existingCols[$col]['Type'], 'varchar(512)') === false) {
                try { $pdo->exec("ALTER TABLE `" . $t('users') . "` MODIFY COLUMN user_agent VARCHAR(512) DEFAULT NULL"); }
                catch (Exception $e) { error_log('[VIRAX schema] user_agent: ' . $e->getMessage()); }
            } elseif ($col === 'phone_number' && stripos((string)$existingCols[$col]['Type'], 'varchar(15)') === false) {
                try { $pdo->exec("ALTER TABLE `" . $t('users') . "` MODIFY COLUMN phone_number VARCHAR(15) DEFAULT NULL"); }
                catch (Exception $e) { error_log('[VIRAX schema] phone_number: ' . $e->getMessage()); }
            }
        }

        // Fallback storage for hosts where the database user may not ALTER the
        // users table. The app reads auth_password from here when the column is
        // unavailable, so the two-step login keeps working everywhere.
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `" . $t('user_auth') . "` (
                user_id INT NOT NULL PRIMARY KEY,
                auth_password VARCHAR(255) DEFAULT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate");
        } catch (Exception $e) { error_log('[VIRAX schema] user_auth: ' . $e->getMessage()); }

        $step('ایجاد جدول مسدودی‌ها');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `" . $t('banned_ips') . "` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL UNIQUE,
            banned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            reason VARCHAR(255) DEFAULT 'Violation'
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate");

        // Ban ledger: one row per identity signal (ip / browser fingerprint /
        // cross-browser device fingerprint / device token / ip+ua pair). A ban
        // writes every signal we have for that user, so opening a different
        // browser still matches on ip, fp_coarse or device token.
        $step('ایجاد جدول ردپای مسدودی');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `" . $t('ban_signals') . "` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            signal_value VARCHAR(190) NOT NULL,
            signal_type VARCHAR(12) NOT NULL,
            user_id INT DEFAULT NULL,
            username VARCHAR(50) DEFAULT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            is_permanent TINYINT DEFAULT 1,
            expires_at INT DEFAULT 0,
            created_by VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_ban_signal (signal_value, signal_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate");

        $step('ایجاد جدول مدیران');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `" . $t('admin_users') . "` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            display_name VARCHAR(100) DEFAULT NULL,
            level ENUM('full','limited') NOT NULL DEFAULT 'full',
            created_by VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_login TIMESTAMP NULL DEFAULT NULL,
            is_active TINYINT DEFAULT 1,
            INDEX(username),
            INDEX(is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate");

        // Access level for sub-admins ("full" = everything except managing
        // other admins, "limited" = day-to-day moderation only).
        try {
            $lvlChk = $pdo->query("SHOW COLUMNS FROM `" . $t('admin_users') . "` LIKE 'level'");
            if (!$lvlChk->fetch()) {
                $pdo->exec("ALTER TABLE `" . $t('admin_users') . "` ADD COLUMN level ENUM('full','limited') NOT NULL DEFAULT 'full'");
            }
        } catch (Exception $e) { error_log('[VIRAX schema] admin_users.level: ' . $e->getMessage()); }

        // Per-user block list for private chats. Blocking is one-directional:
        // the blocker stops receiving messages from the blocked user, and the
        // blocked user can no longer send to the blocker.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `" . $t('blocked_users') . "` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            blocker_id INT NOT NULL,
            blocked_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_block_pair (blocker_id, blocked_id),
            INDEX(blocked_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate");

        $step('ایجاد جدول اتاق‌ها');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `" . $t('rooms') . "` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL UNIQUE,
            type ENUM('public', 'private') DEFAULT 'public',
            room_kind ENUM('room','channel') DEFAULT 'room',
            ai_enabled TINYINT DEFAULT 0,
            created_by INT,
            invite_code VARCHAR(20) DEFAULT NULL,
            category VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(name),
            INDEX(invite_code),
            INDEX(room_kind),
            INDEX(category)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate");

        $roomCols = [];
        try {
            foreach ($pdo->query("SHOW COLUMNS FROM `" . $t('rooms') . "`")->fetchAll(PDO::FETCH_ASSOC) as $c) $roomCols[$c['Field']] = $c;
        } catch (Exception $e) {}
        foreach (['invite_code' => "VARCHAR(20) DEFAULT NULL", 'room_kind' => "ENUM('room','channel') DEFAULT 'room'", 'category' => "VARCHAR(50) DEFAULT NULL", 'ai_enabled' => "TINYINT DEFAULT 0"] as $col => $def) {
            if (!isset($roomCols[$col])) {
                try { $pdo->exec("ALTER TABLE `" . $t('rooms') . "` ADD COLUMN $col $def"); }
                catch (Exception $e) { error_log('[VIRAX schema] rooms.' . $col . ': ' . $e->getMessage()); }
            }
        }

        $pdo->exec("INSERT IGNORE INTO `" . $t('rooms') . "` (name, type, created_by) VALUES ('گفتگوی عمومی', 'public', 0)");

        $step('ایجاد جدول دعوت‌ها');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `" . $t('room_invites') . "` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            room_name VARCHAR(50) NOT NULL,
            invited_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(user_id),
            INDEX(room_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate");

        $step('ایجاد جدول پیام‌ها');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `" . $t('messages') . "` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sender_id INT NOT NULL,
            receiver_id INT DEFAULT NULL,
            room_name VARCHAR(50) DEFAULT NULL,
            reply_to_id INT DEFAULT NULL,
            message LONGTEXT,
            file_path VARCHAR(255) DEFAULT NULL,
            file_name VARCHAR(255) DEFAULT NULL,
            file_token VARCHAR(64) DEFAULT NULL,
            msg_type ENUM('text', 'file', 'voice', 'image', 'video', 'ai') DEFAULT 'text',
            is_edited TINYINT DEFAULT 0,
            is_read TINYINT DEFAULT 0,
            client_msg_id VARCHAR(64) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(room_name),
            INDEX(sender_id),
            INDEX(receiver_id),
            INDEX(file_token),
            INDEX(created_at),
            INDEX(is_read),
            UNIQUE KEY uniq_client_msg (sender_id, client_msg_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate");

        $msgCols = [];
        try {
            foreach ($pdo->query("SHOW COLUMNS FROM `" . $t('messages') . "`")->fetchAll(PDO::FETCH_ASSOC) as $c) $msgCols[$c['Field']] = $c;
        } catch (Exception $e) {}
        foreach (['is_read' => "TINYINT DEFAULT 0", 'msg_type' => "ENUM('text', 'file', 'voice', 'image', 'video', 'ai') DEFAULT 'text'"] as $col => $def) {
            if (!isset($msgCols[$col])) {
                try { $pdo->exec("ALTER TABLE `" . $t('messages') . "` ADD COLUMN $col $def"); }
                catch (Exception $e) { error_log('[VIRAX schema] messages.' . $col . ': ' . $e->getMessage()); }
            } elseif ($col === 'msg_type' && stripos((string)$msgCols[$col]['Type'], 'enum') === false) {
                try { $pdo->exec("ALTER TABLE `" . $t('messages') . "` MODIFY COLUMN $col $def"); }
                catch (Exception $e) { error_log('[VIRAX schema] messages.msg_type: ' . $e->getMessage()); }
            }
        }
        // Idempotent send: the browser stamps every message with a client id
        // and reuses it on retry, so a retried request can never insert the
        // same message twice. The unique key is the atomic guard.
        if (!isset($msgCols['client_msg_id'])) {
            try {
                $pdo->exec("ALTER TABLE `" . $t('messages') . "` ADD COLUMN client_msg_id VARCHAR(64) DEFAULT NULL");
            } catch (Exception $e) { error_log('[VIRAX schema] messages.client_msg_id: ' . $e->getMessage()); }
        }
        try {
            $idxChk = $pdo->query("SHOW INDEX FROM `" . $t('messages') . "` WHERE Key_name = 'uniq_client_msg'");
            if (!$idxChk || !$idxChk->fetch()) {
                $pdo->exec("ALTER TABLE `" . $t('messages') . "` ADD UNIQUE KEY uniq_client_msg (sender_id, client_msg_id)");
            }
        } catch (Exception $e) { error_log('[VIRAX schema] uniq_client_msg: ' . $e->getMessage()); }

        $step('ایجاد جدول نشست‌ها');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `" . $t('user_sessions') . "` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate");

        $step('ایجاد جدول تنظیمات');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `" . $t('system_settings') . "` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(50) NOT NULL UNIQUE,
            setting_value VARCHAR(255) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate");

        foreach (['lock_upload' => '0', 'lock_voice' => '0'] as $k => $v) {
            $pdo->prepare("INSERT IGNORE INTO `" . $t('system_settings') . "` (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]);
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS `" . $t('user_reports') . "` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            reporter_id INT NOT NULL,
            reported_id INT NOT NULL,
            reason TEXT,
            status ENUM('pending','approved','rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(created_at),
            INDEX(reporter_id),
            INDEX(reported_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate");

        $stmtR = $pdo->query("SHOW COLUMNS FROM `" . $t('user_reports') . "` LIKE 'status'");
        if ($stmtR->rowCount() == 0) {
            $pdo->exec("ALTER TABLE `" . $t('user_reports') . "` ADD COLUMN status ENUM('pending','approved','rejected') DEFAULT 'pending'");
        }
        try {
            $pdo->exec("ALTER TABLE `" . $t('user_reports') . "` ADD UNIQUE KEY uq_reporter_reported_pending (reporter_id, reported_id, status)");
        } catch (Exception $e) {}

        $pdo->exec("CREATE TABLE IF NOT EXISTS `" . $t('settings') . "` (
            name VARCHAR(50) PRIMARY KEY,
            value VARCHAR(255) DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate");

        foreach ([
            'site_lock' => '0', 'report_lock' => '0', 'auto_reset_enabled' => '0', 'auto_reset_target' => '0',
            'auto_reset_recurring' => '0', 'delete_lock' => '0', 'hide_admin_online' => '0', 'max_rooms_per_user' => '1',
            'site_lock_reason' => '', 'demo_mode' => '0',
            'lock_register' => '0', 'lock_login' => '0',
        ] as $k => $v) {
            try { $pdo->prepare("INSERT IGNORE INTO `" . $t('settings') . "` (name, value) VALUES (?, ?)")->execute([$k, $v]); } catch (Exception $e) {}
        }

        $step('ایجاد جدول اعلان‌ها');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `" . $t('global_alerts') . "` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `" . $t('update_notifications') . "` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(200) NOT NULL,
            message TEXT NOT NULL,
            icon VARCHAR(10) DEFAULT '🔔',
            type ENUM('info','warning','success','update') DEFAULT 'info',
            is_active TINYINT DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(is_active),
            INDEX(created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `" . $t('update_notif_seen') . "` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            notif_id INT NOT NULL,
            seen_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_notif (user_id, notif_id),
            INDEX(user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate");

        $step('ایجاد جدول پیام‌های سنجاق‌شده');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `" . $t('pinned_messages') . "` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate");

        try { $pdo->exec("ALTER TABLE `" . $t('pinned_messages') . "` DROP INDEX context"); } catch (Exception $e) {}

        foreach ([
            'msg_id' => 'INT NOT NULL DEFAULT 0', 'room_name' => 'VARCHAR(50) DEFAULT NULL', 'receiver_id' => 'INT DEFAULT NULL',
            'pinned_by' => 'INT NOT NULL DEFAULT 0', 'sender_name' => 'VARCHAR(50) DEFAULT NULL',
            'msg_type' => "VARCHAR(20) DEFAULT 'text'", 'message' => 'LONGTEXT DEFAULT NULL',
            'file_name' => 'VARCHAR(255) DEFAULT NULL', 'file_token' => 'VARCHAR(64) DEFAULT NULL',
            'pinned_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        ] as $colName => $colDef) {
            $colSafe = str_replace(["'", '\\', '%', '_'], ['', '', '\\%', '\\_'], $colName);
            $chk = $pdo->query("SHOW COLUMNS FROM `" . $t('pinned_messages') . "` LIKE '" . $colSafe . "'");
            if ($chk->rowCount() == 0) $pdo->exec("ALTER TABLE `" . $t('pinned_messages') . "` ADD COLUMN $colName $colDef");
        }

        $step('ایجاد جدول واکنش‌ها');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `" . $t('message_reactions') . "` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            user_id INT NOT NULL,
            reaction VARCHAR(10) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_reaction (message_id, user_id),
            INDEX idx_msg_id(message_id),
            INDEX idx_user_id(user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate");

        $step('ایجاد جدول اعضای کانال');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `" . $t('channel_members') . "` (
            id INT AUTO_INCREMENT PRIMARY KEY,
            channel_name VARCHAR(50) NOT NULL,
            user_id INT NOT NULL,
            role ENUM('owner','admin','member') DEFAULT 'member',
            joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ch_user (channel_name, user_id),
            INDEX(channel_name),
            INDEX(user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collate");

        $step('ایجاد ایندکس‌های بهینه');
        $compositeIndexes = [
            'messages' => [
                'idx_msg_room_id'   => "CREATE INDEX idx_msg_room_id ON `" . $t('messages') . "` (room_name, id)",
                'idx_msg_pair_id'   => "CREATE INDEX idx_msg_pair_id ON `" . $t('messages') . "` (sender_id, receiver_id, id)",
                'idx_msg_recv_read' => "CREATE INDEX idx_msg_recv_read ON `" . $t('messages') . "` (receiver_id, is_read)",
                'idx_msg_reply'     => "CREATE INDEX idx_msg_reply ON `" . $t('messages') . "` (reply_to_id)",
            ],
            'users' => [
                'idx_users_ip_ua'   => "CREATE INDEX idx_users_ip_ua ON `" . $t('users') . "` (ip_address, user_agent(64))",
            ],
            'room_invites' => [
                'idx_ri_user_room'  => "CREATE INDEX idx_ri_user_room ON `" . $t('room_invites') . "` (user_id, room_name)",
            ],
            'user_sessions' => [
                'uq_sessions_token' => "CREATE UNIQUE INDEX uq_sessions_token ON `" . $t('user_sessions') . "` (session_token)",
            ],
        ];
        foreach ($compositeIndexes as $tbl => $idxList) {
            foreach ($idxList as $idxName => $ddl) {
                try {
                    $idxSafe = str_replace(["'", '\\', '%', '_'], ['', '', '\\%', '\\_'], $idxName);
                    $chk = $pdo->query("SHOW INDEX FROM `" . $t($tbl) . "` WHERE Key_name = '" . $idxSafe . "'");
                    if (!$chk->fetch()) $pdo->exec($ddl);
                } catch (Exception $e) {}
            }
        }

        $step('پاک‌سازی ایندکس‌های تکراری');
        $redundantIndexes = [
            'messages' => ['room_name', 'sender_id', 'receiver_id', 'is_read'],
            'users'    => ['username_2'],
        ];
        foreach ($redundantIndexes as $tbl => $names) {
            foreach ($names as $idxName) {
                try {
                    $chk = $pdo->query("SHOW INDEX FROM `" . $t($tbl) . "` WHERE Key_name = '" . $idxName . "'");
                    if ($chk->fetch()) {
                        $pdo->exec("ALTER TABLE `" . $t($tbl) . "` DROP INDEX `" . $idxName . "`");
                    }
                } catch (Exception $e) {}
            }
        }

        // Mark the migration as done so config.php can skip it entirely on
        // every following request (no SHOW COLUMNS / ALTER / CREATE at all).
        try {
            $pdo->prepare("INSERT INTO `" . $t('settings') . "` (name, value) VALUES ('schema_version', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
                ->execute([VEYRAX_SCHEMA_VERSION]);
        } catch (Exception $e) { error_log('[VIRAX schema] version marker: ' . $e->getMessage()); }

        if ($prefix !== '') {
            $baseTables = ['users','user_auth','banned_ips','ban_signals','admin_users','rooms','room_invites','messages','user_sessions','system_settings','user_reports','settings','global_alerts','update_notifications','update_notif_seen','pinned_messages','message_reactions','channel_members'];
            $step('ایجاد نمای سازگاری برای پیشوند');
            foreach ($baseTables as $bt) {
                try {
                    $pdo->exec("CREATE OR REPLACE VIEW `$bt` AS SELECT * FROM `" . $t($bt) . "`");
                } catch (Exception $e) {
                    throw new Exception('خطا در ایجاد نمای جدول ' . $bt . ': ' . $e->getMessage());
                }
            }
        }
        return true;
    }

    function veyrax_upgrade($pdo, $prefix = '') {
        // One-time repair: older builds wrote the network/runtime settings into the
        // legacy `settings` table while every reader used veyrax_setting()
        // (system_settings) — so "حالت عادی" was saved and never seen. Carry any
        // existing choice over the first time this migration runs.
        try {
            foreach (['request_mode', 'cache_ttl_hours', 'cache_epoch'] as $runtimeKey) {
                $chkNew = $pdo->prepare("SELECT setting_key FROM system_settings WHERE setting_key = ? LIMIT 1");
                $chkNew->execute([$runtimeKey]);
                if ($chkNew->fetch()) continue;
                $oldVal = $pdo->query("SELECT value FROM settings WHERE name = " . $pdo->quote($runtimeKey) . " LIMIT 1")->fetchColumn();
                if ($oldVal !== false && $oldVal !== null && $oldVal !== '') {
                    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$runtimeKey, (string)$oldVal]);
                }
            }
        } catch (Exception $e) { error_log('[VIRAX schema] runtime settings migration: ' . $e->getMessage()); }

        foreach (['ai_enabled','ai_base_url','ai_api_key','ai_model','ai_system_prompt','ai_behavior','ai_max_tokens','ai_temperature','ai_timeout','ai_cooldown','ai_rooms','ai_mention_only'] as $k) {
            try { $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, '')")->execute([$k]); } catch (Exception $e) {}
        }

        // SMS gateway (password reset / OTP delivery). Empty by default: the
        // admin picks a provider in the panel and the reset flow starts working.
        foreach ([
            'sms_enabled'  => '0',
            'sms_provider' => 'none',
            'sms_api_key'  => '',
            'sms_sender'   => '',
            'sms_pattern'  => '',
            'sms_webhook'  => '',
            'sms_dev_mode' => '0',
        ] as $k => $v) {
            try { $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)")->execute([$k, $v]); } catch (Exception $e) {}
        }

        // Brute-force protection for login / OTP / password reset. Database
        // backed so it cannot be reset by dropping the session cookie.
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip_address VARCHAR(45) NOT NULL,
                username VARCHAR(50) DEFAULT NULL,
                scope VARCHAR(20) NOT NULL DEFAULT 'login',
                success TINYINT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_la_ip (ip_address, scope, created_at),
                INDEX idx_la_user (username, scope, created_at),
                INDEX idx_la_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (Exception $e) { error_log('[VIRAX schema] login_attempts: ' . $e->getMessage()); }

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS sms_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                phone VARCHAR(15) NOT NULL,
                provider VARCHAR(30) DEFAULT NULL,
                purpose VARCHAR(30) DEFAULT 'otp',
                ok TINYINT DEFAULT 0,
                detail VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sms_created (created_at),
                INDEX idx_sms_phone (phone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (Exception $e) { error_log('[VIRAX schema] sms_log: ' . $e->getMessage()); }

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS reset_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                identifier_hash CHAR(64) NOT NULL,
                code_hash CHAR(64) NOT NULL,
                expires INT NOT NULL,
                used TINYINT DEFAULT 0,
                attempts TINYINT DEFAULT 0,
                UNIQUE KEY uq_reset_identifier (identifier_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (Exception $e) {}
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ai_backups (
                id INT AUTO_INCREMENT PRIMARY KEY,
                kind ENUM('db','source','both') DEFAULT 'db',
                filename VARCHAR(120) NOT NULL,
                size_bytes BIGINT DEFAULT 0,
                status ENUM('done','failed') DEFAULT 'done',
                created_by VARCHAR(50) DEFAULT 'admin',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_backup_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (Exception $e) {}
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM rooms LIKE 'ai_enabled'");
            if (!$chk->fetch()) $pdo->exec("ALTER TABLE rooms ADD COLUMN ai_enabled TINYINT DEFAULT 0");
        } catch (Exception $e) {}

        // Soft media deletion. The chat row (sender, time, reply thread, text
        // caption) survives so the conversation keeps its shape; only the
        // uploaded blob is removed and the bubble turns into a placeholder.
        //   media_deleted        1  -> the blob is gone, show the placeholder
        //   media_deleted_at         -> when the admin removed it
        //   media_deleted_by         -> which admin removed it
        //   media_prev_type          -> original msg_type, so the placeholder
        //                               can still say "voice / image / video"
        try {
            $msgTbl = '`' . $prefix . 'messages`';
            $chk = $pdo->query("SHOW COLUMNS FROM $msgTbl LIKE 'media_deleted'");
            if (!$chk->fetch()) {
                $pdo->exec("ALTER TABLE $msgTbl
                    ADD COLUMN media_deleted TINYINT NOT NULL DEFAULT 0,
                    ADD COLUMN media_deleted_at TIMESTAMP NULL DEFAULT NULL,
                    ADD COLUMN media_deleted_by VARCHAR(50) DEFAULT NULL,
                    ADD COLUMN media_prev_type VARCHAR(16) DEFAULT NULL");
            }
            // Set by hand on edit / media removal. Deliberately NOT an
            // `ON UPDATE CURRENT_TIMESTAMP` column: read receipts write to the
            // same row constantly and would keep the conversation fingerprint
            // changing forever.
            $chk2 = $pdo->query("SHOW COLUMNS FROM $msgTbl LIKE 'touch_at'");
            if (!$chk2->fetch()) {
                $pdo->exec("ALTER TABLE $msgTbl ADD COLUMN touch_at DATETIME NULL DEFAULT NULL");
            }
        } catch (Exception $e) { error_log('[VIRAX schema] media_deleted: ' . $e->getMessage()); }

        // Demo mode. `is_demo` is the only thing that separates a throw-away
        // visitor from a real account: every demo row is deleted by the sweep,
        // and every query that could leak real data checks this flag.
        try {
            $usrTbl = '`' . $prefix . 'users`';
            $chkD = $pdo->query("SHOW COLUMNS FROM $usrTbl LIKE 'is_demo'");
            if (!$chkD->fetch()) {
                $pdo->exec("ALTER TABLE $usrTbl ADD COLUMN is_demo TINYINT NOT NULL DEFAULT 0");
                $pdo->exec("ALTER TABLE $usrTbl ADD INDEX idx_users_demo (is_demo, last_activity)");
            }
        } catch (Exception $e) { error_log('[VIRAX schema] users.is_demo: ' . $e->getMessage()); }

        // The unprefixed compatibility views are declared as an explicit column
        // list (`SELECT *` is frozen when the view is created), so a column
        // added after the view existed stays invisible through the view until
        // it is rebuilt. Rebuilding is a no-op for callers but keeps
        // `messages.media_deleted` reachable on every deployment.
        if ($prefix !== '') {
            foreach (['messages', 'pinned_messages', 'users', 'rooms'] as $vt) {
                try {
                    $pdo->exec("CREATE OR REPLACE VIEW `$vt` AS SELECT * FROM `" . $prefix . $vt . "`");
                } catch (Exception $e) { error_log('[VIRAX schema] view ' . $vt . ': ' . $e->getMessage()); }
            }
        }

        // One-off: pinned messages were stored as plaintext until 1.7.2. Encrypt
        // every legacy row once, then never look at this block again. The read
        // path already falls back to plaintext, so a failure here only means the
        // old rows stay readable — nothing breaks.
        try {
            $done = $pdo->query("SELECT value FROM settings WHERE name = 'pinned_text_enc'")->fetchColumn();
            if ((string)$done !== '1') {
                $rows = $pdo->query("SELECT id, message FROM pinned_messages WHERE message IS NOT NULL AND message <> ''")->fetchAll(PDO::FETCH_ASSOC);
                if ($rows) {
                    $upd = $pdo->prepare("UPDATE pinned_messages SET message = ? WHERE id = ?");
                    foreach ($rows as $r) {
                        if (veyrax_pin_text_is_cipher((string)$r['message'])) continue;
                        try { $upd->execute([encrypt_data((string)$r['message']), (int)$r['id']]); } catch (Exception $e) {}
                    }
                }
                $pdo->prepare("INSERT INTO settings (name, value) VALUES ('pinned_text_enc', '1')
                               ON DUPLICATE KEY UPDATE value = '1'")->execute();
            }
        } catch (Exception $e) {
            error_log('[VIRAX schema] pinned_text_enc: ' . $e->getMessage());
        }

        return true;
    }
}
