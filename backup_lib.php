<?php

if (!defined('VEYRAX_BACKUP_LIB')) {
    define('VEYRAX_BACKUP_LIB', 1);

    function veyrax_backup_dir() {
        $dir = __DIR__ . '/data/backups';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $ht = $dir . '/.htaccess';
        if (is_dir($dir) && !file_exists($ht)) {
            @file_put_contents($ht, "Require all denied\n");
        }
        $idx = $dir . '/index.php';
        if (is_dir($dir) && !file_exists($idx)) {
            @file_put_contents($idx, "<?php http_response_code(403); exit; ?>");
        }
        return $dir;
    }

    function veyrax_db_backup_sql($pdo) {
        $tables = ['users', 'rooms', 'room_invites', 'messages', 'settings', 'system_settings', 'user_reports', 'banned_ips', 'global_alerts', 'update_notifications', 'update_notif_seen', 'pinned_messages', 'message_reactions', 'channel_members', 'user_sessions', 'admin_users', 'ai_backups'];
        $out = "-- VIRAX Chat Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\nSET FOREIGN_KEY_CHECKS=0;\n\n";
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

    function veyrax_source_backup_zip() {
        $zipPath = null;
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            $tmp = tempnam(sys_get_temp_dir(), 'vbk');
            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                $skip = ['data/config.php', 'data/backups', 'php_errors.log'];
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    $path = str_replace('\\', '/', $file->getPathname());
                    if (strpos($path, str_replace('\\', '/', __DIR__) . '/data/backups') !== false) continue;
                    if (basename($path) === 'php_errors.log') continue;
                    $rel = ltrim(substr($path, strlen(str_replace('\\', '/', __DIR__))), '/');
                    $zip->addFile($path, 'veyrax/' . $rel);
                }
                $zip->addFromString('veyrax/data/config.php.example', "<?php\n\nreturn 'generate via install.php';\n");
                $zip->close();
                $zipPath = $tmp;
            }
        }
        if ($zipPath === null) {
            $zipPath = tempnam(sys_get_temp_dir(), 'vbk');
            $phar = null;
            try {
                $phar = new PharData($zipPath . '.tar');
                $phar->buildFromDirectory(__DIR__, '/^(?!.*data\/backups).*$/');
                $phar->compress(Phar::GZ);
                unset($phar);
                @unlink($zipPath . '.tar');
                @unlink($zipPath);
                $zipPath .= '.tar.gz';
            } catch (Exception $e) {
                return ['ok' => false, 'error' => 'افزونه Zip در دسترس نیست و آرشیو ساخته نشد'];
            }
        }
        $safe = veyrax_backup_dir() . '/veyrax_backup_source_' . date('Y-m-d_H-i-s') . (substr($zipPath, -7) === '.tar.gz' ? '.tar.gz' : '.zip');
        if (!@rename($zipPath, $safe)) {
            @unlink($zipPath);
            return ['ok' => false, 'error' => 'ذخیره فایل پشتیبان ممکن نشد'];
        }
        @chmod($safe, 0640);
        return ['ok' => true, 'filename' => basename($safe), 'size' => filesize($safe)];
    }

    function veyrax_create_backup($pdo, $kind) {
        $dir = veyrax_backup_dir();
        if (!is_dir($dir)) return ['ok' => false, 'error' => 'پوشه پشتیبان قابل ساخت نیست'];
        if ($kind === 'source') {
            return veyrax_source_backup_zip();
        }
        $stamp = date('Y-m-d_H-i-s');
        $safe = $dir . '/veyrax_backup_db_' . $stamp . '.sql';
        $fh = @fopen($safe, 'wb');
        if (!$fh) return ['ok' => false, 'error' => 'نوشتن فایل پشتیبان ممکن نشد'];
        $sql = veyrax_db_backup_sql($pdo);
        $chunkSize = 256 * 1024;
        for ($i = 0, $len = strlen($sql); $i < $len; $i += $chunkSize) {
            fwrite($fh, substr($sql, $i, $chunkSize));
        }
        fclose($fh);
        @chmod($safe, 0640);
        $result = ['ok' => true, 'filename' => basename($safe), 'size' => filesize($safe)];
        if ($kind === 'both') {
            $src = veyrax_source_backup_zip();
            if ($src['ok']) {
                $result['filename'] = $result['filename'] . ' + ' . $src['filename'];
                $result['size'] = $result['size'] + $src['size'];
            }
        }
        return $result;
    }

    function veyrax_backup_retention($pdo, $keep = 10) {
        $dir = veyrax_backup_dir();
        $files = [];
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..' || !is_file($dir . '/' . $f)) continue;
            if (strpos($f, 'veyrax_backup_') !== 0) continue;
            $files[] = ['name' => $f, 'mtime' => filemtime($dir . '/' . $f)];
        }
        usort($files, function ($a, $b) { return $b['mtime'] <=> $a['mtime']; });
        foreach (array_slice($files, $keep) as $old) {
            @unlink($dir . '/' . $old['name']);
            try { $pdo->prepare("DELETE FROM ai_backups WHERE filename = ?")->execute([$old['name']]); } catch (Exception $e) {}
        }
        return true;
    }
}
