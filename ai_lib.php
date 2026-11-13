<?php

if (!defined('VEYRAX_AI_LIB')) {
    define('VEYRAX_AI_LIB', 1);

    function veyrax_ai_cfg($pdo) {
        // Read once per request: the send path asks for it on every message,
        // and a settings query there is pure overhead.
        static $cache = null;
        if ($cache !== null) return $cache;
        $cfg = [];
        try {
            $st = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'ai\\_%'");
            while ($r = $st->fetch(PDO::FETCH_ASSOC)) $cfg[$r['setting_key']] = (string)$r['setting_value'];
        } catch (Exception $e) {}
        return $cache = $cfg;
    }

    function veyrax_ai_user_id($pdo) {
        static $aiId = null;
        if ($aiId !== null) return $aiId;
        $name = 'دستیار هوشمند';
        try {
            $st = $pdo->prepare("SELECT id FROM users WHERE username = ? ORDER BY id ASC LIMIT 1");
            $st->execute([$name]);
            $aiId = $st->fetchColumn();
            if (!$aiId) {
                // WHERE NOT EXISTS keeps two concurrent requests from creating
                // two assistant accounts on a fresh install (the first poll
                // after enabling the assistant can race with the first send).
                $pdo->prepare("INSERT INTO users (username, sticker, is_online, notifications_enabled)
                               SELECT ?, '🤖', 0, 0 FROM DUAL
                               WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = ?)")
                    ->execute([$name, $name]);
                $st2 = $pdo->prepare("SELECT id FROM users WHERE username = ? ORDER BY id ASC LIMIT 1");
                $st2->execute([$name]);
                $aiId = $st2->fetchColumn();
            }
        } catch (Exception $e) { $aiId = 0; }
        return (int)$aiId;
    }

    function veyrax_ai_http($url, $headers, $body, $timeout) {
        // WAMP/Windows PHP ships without a CA bundle, so every HTTPS call to
        // the model dies with "unable to get local issuer certificate" before
        // it ever reaches the API. Prefer a real bundle; fall back to an
        // unverified retry ONLY when none exists (logged loudly).
        $ca = veyrax_ai_ca_bundle();
        $attempt = function ($verify) use ($url, $headers, $body, $timeout, $ca) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_POST => 1,
                CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => max(5, (int)$timeout),
                CURLOPT_CONNECTTIMEOUT => 8,
            ];
            if ($ca !== '') {
                $opts[CURLOPT_CAINFO] = $ca;
            } else if (!$verify) {
                $opts[CURLOPT_SSL_VERIFYPEER] = 0;
                $opts[CURLOPT_SSL_VERIFYHOST] = 0;
            }
            curl_setopt_array($ch, $opts);
            $res = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            return [$res, $code, $err];
        };

        [$res, $code, $err] = $attempt(true);
        if ($res === false && $ca === '' && stripos($err, 'certificate') !== false) {
            error_log('veyrax_ai: no CA bundle found, retrying TLS unverified');
            [$res, $code, $err] = $attempt(false);
        }
        if ($res === false || $code < 200 || $code >= 300) {
            error_log('veyrax_ai: http=' . $code . ' err=' . $err);
            return null;
        }
        return $res;
    }

    /**
     * Best-effort CA bundle discovery. ships with data/cacert.pem; also honours
     * php.ini directives and the common Windows/WAMP locations.
     */
    function veyrax_ai_ca_bundle() {
        static $ca = null;
        if ($ca !== null) return $ca;
        $candidates = [
            (string)ini_get('curl.cainfo'),
            (string)ini_get('openssl.cafile'),
            __DIR__ . '/data/cacert.pem',
            __DIR__ . '/cacert.pem',
            dirname(__DIR__) . '/data/cacert.pem',
            'C:/Windows/system32/curl/curl-ca-bundle.crt',
            'C:/wamp64/bin/php/cacert.pem',
        ];
        foreach ($candidates as $c) {
            if ($c !== '' && is_file($c) && filesize($c) > 1000) return $ca = $c;
        }
        return $ca = '';
    }

    /**
     * Reasoning models (MiniMax-M2.x, DeepSeek-R1, …) inline their chain of
     * thought as <think>…</think> inside the message content. Users must never
     * see that in chat, so it is stripped here — along with any stray tags.
     */
    function veyrax_ai_strip_think($text) {
        $text = (string)$text;
        $text = preg_replace('/<think\b[^>]*>.*?<\/think\s*>/is', '', $text);
        $text = preg_replace('/^<think\b[^>]*>.*$/is', '', $text); // unterminated opening
        $text = preg_replace('/<\/think\s*>/i', '', $text);
        return trim($text);
    }

    function veyrax_ai_request($cfg, $messages) {
        $base = rtrim(trim($cfg['ai_base_url'] ?? ''), '/');
        $key = trim($cfg['ai_api_key'] ?? '');
        $model = trim($cfg['ai_model'] ?? '');
        if ($base === '' || $model === '') return null;
        if (!preg_match('#^https?://#i', $base)) return null;
        $url = $base . '/chat/completions';
        $headers = ['Content-Type: application/json'];
        if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;
        $payload = [
            'model' => $model,
            'messages' => $messages,
            // Thinking models burn tokens on hidden reasoning before the
            // answer, so the floor is generous; the admin can still raise it.
            'max_tokens' => max(200, min(4000, (int)($cfg['ai_max_tokens'] ?: 800))),
            'temperature' => max(0, min(2, (float)($cfg['ai_temperature'] ?: 0.7))),
        ];
        $raw = veyrax_ai_http($url, $headers, $payload, (int)($cfg['ai_timeout'] ?: 30));
        if ($raw === null) return null;
        $d = json_decode($raw, true);
        $text = $d['choices'][0]['message']['content'] ?? ($d['choices'][0]['text'] ?? null);
        if (!is_string($text)) return null;
        $text = veyrax_ai_strip_think($text);
        return trim($text) !== '' ? trim($text) : null;
    }

    /**
     * Conversation memory for the model call.
     * kind='room' → the last messages of the room (broadcast rows only).
     * kind='pv'   → the private thread between the asker and $peerId, plus
     *               assistant replies that were addressed to the asker there.
     */
    function veyrax_ai_history($pdo, $room, $kind = 'room', $peerId = 0, $askerId = 0, $limit = 6) {
        $out = [];
        try {
            if ($kind === 'pv' && $askerId > 0) {
                $aiUid = veyrax_ai_user_id($pdo);
                $sql = "SELECT m.message, u.username FROM messages m JOIN users u ON m.sender_id = u.id
                        WHERE m.room_name IS NULL AND (
                            (m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?)";
                $args = [$askerId, $peerId, $peerId, $askerId];
                if ($aiUid) {
                    // Only assistant replies that were summoned inside THIS
                    // thread (their trigger message lives here) — never replies
                    // from the asker's other private conversations.
                    $sql .= " OR (m.sender_id = ? AND m.receiver_id = ? AND m.msg_type = 'ai' AND EXISTS (
                                SELECT 1 FROM messages t WHERE t.id = m.reply_to_id AND t.room_name IS NULL
                                AND ((t.sender_id = ? AND t.receiver_id = ?) OR (t.sender_id = ? AND t.receiver_id = ?))
                            ))";
                    array_push($args, $aiUid, $askerId, $askerId, $peerId, $peerId, $askerId);
                }
                $sql .= ") ORDER BY m.id DESC LIMIT " . (int)$limit;
                $st = $pdo->prepare($sql);
                $st->execute($args);
            } else {
                $st = $pdo->prepare("SELECT m.message, u.username FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.room_name = ? AND m.receiver_id IS NULL ORDER BY m.id DESC LIMIT " . (int)$limit);
                $st->execute([$room]);
            }
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach (array_reverse($rows) as $r) {
                try { $txt = $r['message'] ? decrypt_data($r['message']) : ''; } catch (Exception $e) { $txt = ''; }
                if ($txt === '') continue;
                $out[] = ['role' => ($r['username'] === 'دستیار هوشمند' ? 'assistant' : 'user'), 'content' => mb_substr($r['username'], 0, 20) . ': ' . $txt];
            }
        } catch (Exception $e) {}
        return $out;
    }

    /**
     * $kind='room' → $room is the room name, reply lands in the room.
     * $kind='pv'   → $askerId is the user who gets the reply (receiver_id),
     *                $peerId is the other side of the private thread.
     */
    function veyrax_ai_process($pdo, $room, $triggerMsgId, $userText, $kind = 'room', $peerId = 0, $askerId = 0) {
        $cfg = veyrax_ai_cfg($pdo);
        if (($cfg['ai_enabled'] ?? '0') !== '1') return 'disabled';
        // An empty prompt means the trigger lost its text somewhere — calling
        // the model with an empty message burns tokens and most APIs reject it.
        if (trim((string)$userText) === '') return 'skip';
        $aiId = veyrax_ai_user_id($pdo);
        if (!$aiId) throw new Exception('ai_user_missing');
        $cooldown = max(0, (int)($cfg['ai_cooldown'] ?: 0));
        if ($cooldown > 0) {
            try {
                if ($kind === 'pv' && $askerId > 0) {
                    $st = $pdo->prepare("SELECT created_at FROM messages WHERE sender_id = ? AND receiver_id = ? AND room_name IS NULL ORDER BY id DESC LIMIT 1");
                    $st->execute([$aiId, (int)$askerId]);
                } else {
                    $st = $pdo->prepare("SELECT created_at FROM messages m JOIN users u ON m.sender_id = u.id WHERE m.room_name = ? AND u.username = 'دستیار هوشمند' ORDER BY m.id DESC LIMIT 1");
                    $st->execute([$room]);
                }
                $lastAi = $st->fetchColumn();
                if ($lastAi && (time() - strtotime($lastAi)) < $cooldown) return 'cooldown';
            } catch (Exception $e) {}
        }
        $sys = trim(($cfg['ai_system_prompt'] ?: 'You are the assistant of this chat room.'));
        $behavior = trim($cfg['ai_behavior'] ?? '');
        if ($behavior !== '') $sys .= "\n" . $behavior;
        $msgs = [['role' => 'system', 'content' => $sys]];
        foreach (veyrax_ai_history($pdo, $room, $kind, (int)$peerId, (int)$askerId) as $h) $msgs[] = $h;
        $msgs[] = ['role' => 'user', 'content' => (string)$userText];
        $reply = veyrax_ai_request($cfg, $msgs);
        if ($reply === null) {
            // Thrown, not swallowed: the queue worker owns retries and the
            // graceful "unavailable" notice shown to the room.
            throw new Exception('ai_api_failed');
        }
        $reply = mb_substr($reply, 0, 4000);
        $isPv = ($kind === 'pv' && $askerId > 0);
        if ($isPv) {
            $st = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, room_name, message, msg_type, reply_to_id) VALUES (?, ?, NULL, ?, 'ai', ?)");
            $st->execute([$aiId, (int)$askerId, encrypt_data($reply), $triggerMsgId]);
        } else {
            $st = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, room_name, message, msg_type, reply_to_id) VALUES (?, NULL, ?, ?, 'ai', ?)");
            $st->execute([$aiId, $room, encrypt_data($reply), $triggerMsgId]);
        }
        $newId = (int)$pdo->lastInsertId();
        // The full message rides along (same shape as one fetch row) so the
        // client paints the bubble instantly instead of polling for it.
        $wsAiMsg = [
            'id'             => $newId,
            'sender_id'      => (int)$aiId,
            'sender_name'    => 'دستیار هوشمند',
            'sender_sticker' => '🤖',
            'receiver_id'    => $isPv ? (int)$askerId : null,
            'room_name'      => $isPv ? null : $room,
            'message'        => $reply,
            'msg_type'       => 'ai',
            'file_name'      => null,
            'reply_to_id'    => (int)$triggerMsgId,
            'reply_message'  => null,
            'reply_sender'   => null,
            'reply_msg_type' => null,
            'is_read'        => 0,
            'seen_by'        => 0,
            'created_at'     => date('Y-m-d H:i:s'),
            'reactions'      => [],
        ];
        ws_push('new_message', [
            'message_id' => $newId,
            'sender_id'  => $aiId,
            'room_name'  => $isPv ? null : $room,
            'receiver_id'=> $isPv ? (int)$askerId : null,
            'msg_type'   => 'ai',
            'force'      => true,
            'message'    => $wsAiMsg,
            'targets'    => $isPv ? [(int)$askerId] : [],
            'rooms'      => $isPv ? [] : [['kind' => 'room', 'name' => $room]],
        ]);
        return 'ok';
    }

    // ── Request queue ─────────────────────────────────────────────────────────
    // Concurrent triggers used to race into parallel model calls and random
    // failures. Every trigger now lands in ai_queue (FIFO) and a single
    // lock-holder drains it in order — one model call at a time, with one
    // retry and a graceful room notice when the model stays unreachable.

    function veyrax_ai_queue_table($pdo) {
        static $ready = null;
        if ($ready !== null) return $ready;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS ai_queue (
                id INT AUTO_INCREMENT PRIMARY KEY,
                room_name VARCHAR(50) NOT NULL,
                trigger_msg_id INT NOT NULL,
                trigger_user_id INT NOT NULL DEFAULT 0,
                user_text TEXT,
                kind VARCHAR(10) NOT NULL DEFAULT 'room',
                peer_id INT NOT NULL DEFAULT 0,
                status VARCHAR(12) NOT NULL DEFAULT 'pending',
                attempts TINYINT NOT NULL DEFAULT 0,
                last_error VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                processed_at TIMESTAMP NULL DEFAULT NULL,
                INDEX idx_status (status, id),
                INDEX idx_user_time (trigger_user_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
            // Installations that created the table before pv support existed:
            // add the two columns quietly when they are missing.
            foreach ([
                "ALTER TABLE ai_queue ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'room'",
                "ALTER TABLE ai_queue ADD COLUMN peer_id INT NOT NULL DEFAULT 0",
            ] as $upgrade) {
                try { $pdo->exec($upgrade); } catch (Exception $e) { /* already there */ }
            }
            return $ready = true;
        } catch (Exception $e) {
            error_log('[VIRAX ai queue] create: ' . $e->getMessage());
            return $ready = false;
        }
    }

    function veyrax_ai_enqueue($pdo, $room, $triggerMsgId, $userText, $userId, $kind = 'room', $peerId = 0) {
        if (!veyrax_ai_queue_table($pdo)) return false;
        try {
            $pdo->prepare("INSERT INTO ai_queue (room_name, trigger_msg_id, trigger_user_id, user_text, kind, peer_id) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$room, (int)$triggerMsgId, (int)$userId, (string)$userText, ($kind === 'pv' ? 'pv' : 'room'), (int)$peerId]);
            return true;
        } catch (Exception $e) {
            error_log('[VIRAX ai queue] enqueue: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Per-user cap on assistant requests, admin-configurable:
     *   ai_user_limit_count  — allowed requests per window (0 = unlimited)
     *   ai_user_limit_window — window length in minutes (0 = no window)
     * Counted from the queue itself, so denied/failed attempts still count
     * (an abuser cannot bypass the cap by breaking the model).
     */
    function veyrax_ai_user_limit_check($pdo, $userId, $cfg) {
        $count = max(0, (int)($cfg['ai_user_limit_count'] ?? 0));
        $windowMin = max(0, (int)($cfg['ai_user_limit_window'] ?? 0));
        if ($count <= 0 || $windowMin <= 0) return ['ok' => true, 'retry' => 0];
        if (!veyrax_ai_queue_table($pdo)) return ['ok' => true, 'retry' => 0];
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM ai_queue WHERE trigger_user_id = ? AND status <> 'limited' AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)");
            $st->execute([(int)$userId, $windowMin]);
            if ((int)$st->fetchColumn() < $count) return ['ok' => true, 'retry' => 0];
            // When does the oldest request inside the window age out?
            $st2 = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(created_at, INTERVAL ? MINUTE)) FROM ai_queue WHERE trigger_user_id = ? AND status <> 'limited' AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE) ORDER BY created_at ASC LIMIT 1");
            $st2->execute([$windowMin, (int)$userId, $windowMin]);
            $retry = (int)$st2->fetchColumn();
            return ['ok' => false, 'retry' => max(5, $retry)];
        } catch (Exception $e) {
            return ['ok' => true, 'retry' => 0];
        }
    }

    /** The graceful notice posted to the conversation when the model stays down. */
    function veyrax_ai_unavailable_reply($pdo, $room, $triggerMsgId, $kind = 'room', $askerId = 0) {
        try {
            $aiId = veyrax_ai_user_id($pdo);
            if (!$aiId) return;
            $isPv = ($kind === 'pv' && $askerId > 0);
            if ($isPv) {
                $st = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, room_name, message, msg_type, reply_to_id) VALUES (?, ?, NULL, ?, 'ai', ?)");
                $st->execute([$aiId, (int)$askerId, encrypt_data('دستیار فعلاً در دسترس نیست؛ لطفاً کمی بعد دوباره امتحان کنید 🙏'), $triggerMsgId]);
            } else {
                $st = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, room_name, message, msg_type, reply_to_id) VALUES (?, NULL, ?, ?, 'ai', ?)");
                $st->execute([$aiId, $room, encrypt_data('دستیار فعلاً در دسترس نیست؛ لطفاً کمی بعد دوباره امتحان کنید 🙏'), $triggerMsgId]);
            }
            $newId = (int)$pdo->lastInsertId();
            ws_push('new_message', [
                'message_id' => $newId,
                'sender_id' => $aiId,
                'room_name' => $isPv ? null : $room,
                'receiver_id' => $isPv ? (int)$askerId : null,
                'msg_type' => 'ai',
                'force' => true,
                'targets' => $isPv ? [(int)$askerId] : [],
                'rooms' => $isPv ? [] : [['kind' => 'room', 'name' => $room]],
            ]);
        } catch (Exception $e) {
            error_log('[VIRAX ai queue] unavailable reply: ' . $e->getMessage());
        }
    }

    /**
     * Drains ai_queue FIFO under a MySQL lock so exactly one worker runs at a
     * time. Called via register_shutdown_function after the send response has
     * already been flushed to the client, so the room never waits on the model.
     *
     * Drains until the queue is empty (bounded by an item cap and a wall-clock
     * budget), not by a fixed pass count — a burst of 100 simultaneous triggers
     * must not be left half-drained just because every early shutdown worker
     * found an empty queue and exited before the later items were enqueued.
     */
    function veyrax_ai_queue_worker($pdo) {
        if (!veyrax_ai_queue_table($pdo)) return;
        try {
            $got = $pdo->query("SELECT GET_LOCK('veyrax_ai_queue', 0)")->fetchColumn();
            if (!$got) return; // another request is already draining the queue
            try {
                $cfg = veyrax_ai_cfg($pdo);
                // Admin-configurable attempts per trigger (1 = no retry).
                $maxAttempts = max(1, min(5, (int)($cfg['ai_max_attempts'] ?: 3)));
                $itemBudget = 400;
                $deadline = microtime(true) + 25.0; // seconds the worker may run
                while ($itemBudget-- > 0) {
                    $rows = $pdo->query("SELECT * FROM ai_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
                    if (!$rows) break;
                    foreach ($rows as $row) {
                        $claimed = $pdo->prepare("UPDATE ai_queue SET status = 'processing', attempts = attempts + 1 WHERE id = ? AND status = 'pending'");
                        $claimed->execute([$row['id']]);
                        if ($claimed->rowCount() === 0) continue; // another pass took it
                        try {
                            $kind = (string)($row['kind'] ?? 'room');
                            $peerId = (int)($row['peer_id'] ?? 0);
                            $askerId = (int)$row['trigger_user_id'];
                            $result = veyrax_ai_process($pdo, $row['room_name'], (int)$row['trigger_msg_id'], (string)$row['user_text'], $kind, $peerId, $askerId);
                            $pdo->prepare("UPDATE ai_queue SET status = 'done', processed_at = NOW() WHERE id = ?")->execute([$row['id']]);
                            if ($result === 'cooldown' || $result === 'disabled' || $result === 'skip') {
                                // No reply will be inserted for this trigger —
                                // tell the room so the "typing…" row clears
                                // instead of hanging until its timeout.
                                try {
                                    ws_push('ai_skip', [
                                        'trigger_id' => (int)$row['trigger_msg_id'],
                                        'reason' => $result,
                                        'targets' => ($kind === 'pv' && $askerId > 0) ? [$askerId] : [],
                                        'rooms' => ($kind === 'pv') ? [] : [['kind' => 'room', 'name' => $row['room_name']]],
                                    ]);
                                } catch (Exception $e) {}
                            }
                        } catch (Exception $e) {
                            $kind = (string)($row['kind'] ?? 'room');
                            $askerId = (int)$row['trigger_user_id'];
                            if (((int)$row['attempts'] + 1) >= $maxAttempts) {
                                veyrax_ai_unavailable_reply($pdo, $row['room_name'], (int)$row['trigger_msg_id'], $kind, $askerId);
                                $pdo->prepare("UPDATE ai_queue SET status = 'failed', last_error = ?, processed_at = NOW() WHERE id = ?")
                                    ->execute([substr($e->getMessage(), 0, 250), $row['id']]);
                            } else {
                                // Backing off briefly before the retry: the most
                                // common failure is an upstream 429, and an
                                // immediate second hit would fail the same way.
                                // The worker holds the queue lock, so this only
                                // delays the retry, never a live request.
                                sleep(2);
                                $pdo->prepare("UPDATE ai_queue SET status = 'pending', last_error = ? WHERE id = ?")
                                    ->execute([substr($e->getMessage(), 0, 250), $row['id']]);
                            }
                        }
                    }
                    if (microtime(true) > $deadline) break;
                }
                // Housekeeping: the queue is operational data, not history.
                try { $pdo->exec("DELETE FROM ai_queue WHERE processed_at < DATE_SUB(NOW(), INTERVAL 1 DAY) OR (status IN ('done','failed','limited') AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY))"); } catch (Exception $e) {}
            } finally {
                try { $pdo->exec("SELECT RELEASE_LOCK('veyrax_ai_queue')"); } catch (Exception $e) {}
            }
        } catch (Exception $e) {
            error_log('[VIRAX ai queue] worker: ' . $e->getMessage());
        }
    }

    /**
     * Sends the send-response to the client IMMEDIATELY, before the shutdown
     * worker starts. ob_gzhandler held the JSON in the buffer while the model
     * call ran, so the browser waited 10-25 s: the optimistic bubble and the
     * polled server copy both stayed on screen and the message looked
     * duplicated. Ending the buffers and declaring Content-Length lets the
     * browser complete the request at once.
     */
    function veyrax_ai_json_respond($payload) {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Length: ' . strlen($json));
        }
        echo $json;
        flush();
    }
}
