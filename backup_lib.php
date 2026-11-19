<?php
/**
 * VIRAX — Backup engine (v1.9.2)
 * =============================================================================
 *  A database dump is the single most valuable file on the server: every
 *  username, every password hash, every private message. Three things protect
 *  it here, and none of them involve inventing a file format:
 *
 *    1. **Location.** data/backups/ ships with .htaccess + web.config +
 *       index.php guards, and the root .htaccess refuses the whole data/
 *       tree. Four independent ways to say "not over HTTP".
 *    2. **Name.** 64 bits of randomness in the filename, so the old
 *       `veyrax_backup_db_2026-11-18_12-00-00.sql` guess no longer exists.
 *    3. **Encryption at rest**, through encrypt_file_data() — the same helper
 *       the app has always used for uploaded media. One call, no loops, no
 *       custom container, nothing deleted.
 *
 *  The dump is built in memory and encrypted on the way to disk, so a plaintext
 *  .sql is never written at all. That is a real improvement over writing then
 *  encrypting: there is no window where the file exists unprotected.
 */

if (!defined('VEYRAX_BACKUP_LIB')) {
    define('VEYRAX_BACKUP_LIB', '1.9.3');

    /**
     * The backup directory.
     *
     * Kept inside data/ (not above the web root) because shared hosts often
     * refuse writes outside the document root; the guard files are what make
     * that safe, not the location. Those guards are static files in the
     * package — nothing here creates them.
     */
    function veyrax_backup_dir() {
        $dir = __DIR__ . '/data/backups';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        if (is_dir($dir)) @chmod($dir, 0750);
        return $dir;
    }

    /** Canonical, unguessable name for a new backup artefact. */
    function veyrax_backup_name($kind) {
        $kind = in_array($kind, ['db', 'source'], true) ? $kind : 'db';
        $stamp = date('Y-m-d_H-i-s');
        $nonce = bin2hex(random_bytes(8));   // 64 bits — not enumerable
        $ext = ($kind === 'source') ? 'zip' : 'sql';
        return 'veyrax_backup_' . $kind . '_' . $stamp . '_' . $nonce . '.' . $ext;
    }

    /** Strip the storage suffix so the panel and history show the real name. */
    function veyrax_backup_display_name($name) {
        return preg_replace('/\.enc$/', '', (string)$name);
    }

    function veyrax_db_backup_sql($pdo) {
        $tables = ['users', 'rooms', 'room_invites', 'messages', 'settings', 'system_settings', 'user_reports', 'banned_ips', 'global_alerts', 'update_notifications', 'update_notif_seen', 'pinned_messages', 'message_reactions', 'channel_members', 'user_sessions', 'admin_users', 'ai_backups'];
        $out = "-- VIRAX Chat Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n"
             . "-- Stored encrypted at rest; download through the admin panel.\n\n"
             . "SET FOREIGN_KEY_CHECKS=0;\n\n";
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->query("SELECT * FROM `$table`");
                $out .= "DROP TABLE IF EXISTS `$table`;\n";
                $out .= "DELETE FROM `$table`;\n";
                $batch = [];
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $keys = array_keys($row);
                    $vals = array_map(function ($v) use ($pdo) { return $v === null ? 'NULL' : $pdo->quote($v); }, array_values($row));
                    $batch[] = "INSERT INTO `$table` (`" . implode("`,`", $keys) . "`) VALUES (" . implode(",", $vals) . ");";
                    if (count($batch) >= 200) {
                        $out .= implode("\n", $batch) . "\n";
                        $batch = [];
                    }
                }
                if ($batch) $out .= implode("\n", $batch) . "\n";
                $out .= "\n";
                if (function_exists('veyrax_ai_user_id')) veyrax_ai_user_id($pdo);
            } catch (Exception $e) {
                $out .= "-- error dumping $table\n";
            }
        }
        $out .= "SET FOREIGN_KEY_CHECKS=1;\n";
        return $out;
    }

    /**
     * Build the source archive.
     *
     * The exclusion list is the security boundary: secrets, backups, the audit
     * trail, staging chunks, rate-limit buckets and node_modules must never end
     * up inside an archive that then gets copied to a laptop or a chat app.
     */
    function veyrax_source_backup_zip() {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'افزونه Zip روی سرور فعال نیست و آرشیو ساخته نشد'];
        }

        $root = str_replace('\\', '/', __DIR__);
        $skipPrefixes = [
            $root . '/data/backups',
            $root . '/data/security',
            $root . '/data/staging',
            $root . '/data/rate',
            $root . '/websocket/node_modules',
            $root . '/.git',
        ];
        $skipNames = ['config.php', 'php_errors.log', basename(ws_outbox_path()), '.env'];

        $shouldSkip = function ($path) use ($root, $skipPrefixes, $skipNames) {
            $path = str_replace('\\', '/', $path);
            foreach ($skipPrefixes as $p) {
                if (strpos($path, $p) === 0) return true;
            }
            if (dirname($path) === $root . '/data' && in_array(basename($path), $skipNames, true)) return true;
            if (basename($path) === 'php_errors.log') return true;
            return false;
        };

        $tmp = tempnam(sys_get_temp_dir(), 'vbk');
        if ($tmp === false) return ['ok' => false, 'error' => 'ساخت فایل موقت ممکن نشد'];

        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            return ['ok' => false, 'error' => 'آرشیو ساخته نشد'];
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            $path = $file->getPathname();
            if ($shouldSkip($path)) continue;
            $rel = ltrim(substr(str_replace('\\', '/', $path), strlen($root)), '/');
            $zip->addFile($path, 'veyrax/' . $rel);
        }
        $zip->addFromString('veyrax/data/config.php.example', "<?php\n\n// Generated by install.php. Never commit the real file.\n");
        $zip->close();

        return ['ok' => true, 'tmp' => $tmp];
    }

    /**
     * Move a freshly built artefact into the vault: encrypt it, name it, lock it
     * down. Returns the stored filename for the panel/history.
     */
    function veyrax_backup_store($tmpPath, $kind) {
        $dir = veyrax_backup_dir();
        $name = veyrax_backup_name($kind);
        $target = $dir . '/' . $name . '.enc';

        $raw = @file_get_contents($tmpPath);
        @unlink($tmpPath);
        if ($raw === false) {
            return ['ok' => false, 'error' => 'خواندن فایل پشتیبان ممکن نشد'];
        }

        $sealed = encrypt_file_data($raw);
        unset($raw);
        if ($sealed === false) {
            return ['ok' => false, 'error' => 'رمزگذاری فایل پشتیبان ناموفق بود'];
        }

        if (@file_put_contents($target, $sealed) === false) {
            return ['ok' => false, 'error' => 'ذخیره فایل پشتیبان ممکن نشد'];
        }
        @chmod($target, 0600);

        return [
            'ok'       => true,
            'filename' => basename($target),
            'size'     => (int)@filesize($target),
        ];
    }

    function veyrax_create_backup($pdo, $kind) {
        $dir = veyrax_backup_dir();
        if (!is_dir($dir)) return ['ok' => false, 'error' => 'پوشه پشتیبان قابل ساخت نیست'];

        if ($kind === 'source') {
            $zip = veyrax_source_backup_zip();
            if (empty($zip['ok'])) return $zip;
            return veyrax_backup_store($zip['tmp'], 'source');
        }

        // Built in memory, encrypted on the way out. No plaintext dump is ever
        // written, so there is no window where it exists unprotected.
        $sql = veyrax_db_backup_sql($pdo);
        $name = veyrax_backup_name('db');
        $target = $dir . '/' . $name . '.enc';

        $sealed = encrypt_file_data($sql);
        unset($sql);
        if ($sealed === false) {
            return ['ok' => false, 'error' => 'رمزگذاری فایل پشتیبان ناموفق بود'];
        }
        if (@file_put_contents($target, $sealed) === false) {
            return ['ok' => false, 'error' => 'ذخیره فایل پشتیبان ممکن نشد'];
        }
        @chmod($target, 0600);

        $result = [
            'ok'       => true,
            'filename' => basename($target),
            'size'     => (int)@filesize($target),
        ];

        if ($kind === 'both') {
            $zip = veyrax_source_backup_zip();
            if (!empty($zip['ok'])) {
                $src = veyrax_backup_store($zip['tmp'], 'source');
                if (!empty($src['ok'])) {
                    $result['filename'] = $result['filename'] . ' + ' . $src['filename'];
                    $result['size']     = $result['size'] + $src['size'];
                }
            }
        }
        return $result;
    }

    /**
     * Retention. Counts stored artefacts only — the guard files are not
     * backups and must survive the sweep.
     */
    function veyrax_backup_retention($pdo, $keep = 10) {
        $dir = veyrax_backup_dir();
        $files = [];
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..' || !is_file($dir . '/' . $f)) continue;
            if (strpos($f, 'veyrax_backup_') !== 0) continue;
            $files[] = ['name' => $f, 'mtime' => (int)@filemtime($dir . '/' . $f)];
        }
        usort($files, function ($a, $b) { return $b['mtime'] <=> $a['mtime']; });
        foreach (array_slice($files, $keep) as $old) {
            @unlink($dir . '/' . $old['name']);
            try {
                $pdo->prepare("DELETE FROM ai_backups WHERE filename = ? OR filename = ?")
                    ->execute([$old['name'], veyrax_backup_display_name($old['name'])]);
            } catch (Exception $e) {}
        }
        return true;
    }
}
