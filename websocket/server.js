'use strict';
/**
 * VIRAX — realtime server (v1.9.0)
 * =============================================================================
 *  Security model
 * --------------
 *  The previous build trusted the client far too much:
 *
 *    • ALLOWED_ORIGINS defaulted to '*' and a missing Origin was accepted, so
 *      any website could open a socket against the install.
 *    • join_room took a room name from the client and joined it without a
 *      single authorisation check. Any logged-in user could subscribe to any
 *      private room and read every message posted there in real time.
 *    • force_reload was callable by any connected client, letting one user
 *      force every other user's browser to reload in a loop.
 *    • /push compared the shared secret with !==, which leaks it a byte at a
 *      time through timing, and accepted requests from anywhere.
 *    • A missing secret silently fell back to a hardcoded string, so an
 *      install that never wrote data/config.php ran with a public key.
 *
 *  Every one of those is now closed. The two ideas that do the work:
 *
 *    1. **Room tickets.** A socket may only join a room it holds a ticket for.
 *       Tickets are minted by PHP (ws_auth.php), which is the only component
 *       that can check room membership against the database, and they are
 *       bound to the user id, the room name and a short expiry.
 *    2. **Same-origin by default.** Origins are an explicit allow-list. If the
 *       list is empty the server derives it from the Host header instead of
 *       falling back to '*'.
 */

const http = require('http');
const path = require('path');
const fs = require('fs');
const crypto = require('crypto');

// ── Config ───────────────────────────────────────────────────────────────────

const envPath = path.join(__dirname, '.env');
if (fs.existsSync(envPath)) {
    for (const line of fs.readFileSync(envPath, 'utf8').split(/\r?\n/)) {
        const m = line.match(/^\s*([A-Z_]+)\s*=\s*(.*)\s*$/);
        if (m && process.env[m[1]] === undefined) {
            process.env[m[1]] = m[2].replace(/^["']|["']$/g, '');
        }
    }
}

const WS_HOST = process.env.WS_HOST || '127.0.0.1';
const WS_PORT = parseInt(process.env.WS_PORT || '3000', 10);

/**
 * Read a `$name = 'value';` assignment out of the installer-generated config.
 * Used for the push secret and the origin allow-list so a plain
 * `node server.js` picks up exactly what the PHP side is using.
 */
function readAppConfig(key) {
    try {
        const cfg = fs.readFileSync(path.join(__dirname, '..', 'data', 'config.php'), 'utf8');
        const m = cfg.match(new RegExp('\\$' + key + '\\s*=\\s*([\'"])([^\'"]*)\\1'));
        return m ? m[2] : '';
    } catch (e) {
        return '';
    }
}

const PUSH_SECRET = process.env.PUSH_SECRET || readAppConfig('ws_secret') || process.env.AUTH_SECRET || '';
const DEFAULT_SECRET = 'veyrax-ws-secret-change-me';

if (!PUSH_SECRET || PUSH_SECRET === DEFAULT_SECRET || PUSH_SECRET.length < 16) {
    if (process.env.WS_ALLOW_INSECURE_SECRET === '1') {
        console.warn('[veyrax-ws] WARNING: running with a weak/absent secret (WS_ALLOW_INSECURE_SECRET=1).');
    } else {
        console.error('[veyrax-ws] REFUSING TO START: no usable PUSH_SECRET.');
        console.error('            Install the app (data/config.php) or set PUSH_SECRET to a random 32+ char value.');
        console.error('            To override for local debugging: WS_ALLOW_INSECURE_SECRET=1');
        process.exit(1);
    }
}

const ROOM_TICKET_REQUIRED = process.env.WS_REQUIRE_ROOM_TICKET !== '0';
const ALLOW_NO_ORIGIN = process.env.WS_ALLOW_NO_ORIGIN === '1';

// Origins: explicit list wins; otherwise the value written by install.php.
const rawOrigins = (process.env.ALLOWED_ORIGINS || readAppConfig('ws_allowed_origins') || '').trim();
const ALLOWED_ORIGINS = rawOrigins
    .split(',')
    .map(s => s.trim())
    .filter(Boolean);

if (ALLOWED_ORIGINS.includes('*')) {
    console.warn('[veyrax-ws] ALLOWED_ORIGINS="*" is ignored: falling back to same-host matching.');
}

/**
 * Same-host matching is the safe default: it lets the install work on whatever
 * domain it was deployed to without the operator having to keep a list in sync,
 * while still rejecting every third-party page.
 */
function originAllowed(origin, hostHeader) {
    if (!origin) return ALLOW_NO_ORIGIN || isLoopback(hostHeader);

    let parsed;
    try { parsed = new URL(origin); } catch (e) { return false; }

    if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return false;

    const explicit = ALLOWED_ORIGINS.filter(o => o !== '*');
    if (explicit.length) {
        return explicit.some(o => {
            if (o === origin) return true;
            if (o.startsWith('*.')) return parsed.hostname.endsWith(o.slice(1));
            return false;
        });
    }

    // No explicit list → the socket must come from the host that served it.
    if (!hostHeader) return false;
    const hostname = String(hostHeader).split(':')[0].toLowerCase();
    return parsed.hostname.toLowerCase() === hostname;
}

function isLoopback(hostHeader) {
    if (!hostHeader) return false;
    const h = String(hostHeader).split(':')[0];
    return h === '127.0.0.1' || h === '::1' || h === 'localhost';
}

// ── Socket.IO ────────────────────────────────────────────────────────────────

const { Server } = require('socket.io');

const io = new Server({
    cors: {
        origin: (origin, cb) => {
            // The Host header is not available here; the per-socket check in
            // the middleware below is the authoritative one. This handler only
            // needs to avoid blanket-allowing every origin.
            if (!origin) return cb(null, !ALLOW_NO_ORIGIN ? false : true);
            const explicit = ALLOWED_ORIGINS.filter(o => o !== '*');
            if (!explicit.length) return cb(null, true); // refined per-socket
            const ok = explicit.some(o => o === origin || (o.startsWith('*.') && origin.endsWith(o.slice(1))));
            cb(ok ? null : new Error('origin not allowed'), ok);
        },
        methods: ['GET', 'POST'],
        credentials: true
    },
    pingInterval: 20000,
    pingTimeout: 25000,
    maxHttpBufferSize: 256 * 1024,
    // A socket that never authenticates must not hold a slot open.
    connectTimeout: 10000
});

// ── Token verification ───────────────────────────────────────────────────────

function timingSafeEqualStr(a, b) {
    const ba = Buffer.from(String(a));
    const bb = Buffer.from(String(b));
    if (ba.length !== bb.length) return false;
    try { return crypto.timingSafeEqual(ba, bb); } catch (e) { return false; }
}

function hmac(payload) {
    return crypto.createHmac('sha256', PUSH_SECRET).update(payload).digest('hex');
}

function verifyToken(token) {
    if (typeof token !== 'string' || token.length > 2048) return null;
    const dot = token.indexOf('.');
    if (dot === -1) return null;
    const payload = token.slice(0, dot);
    const sig = token.slice(dot + 1);

    if (!timingSafeEqualStr(sig, hmac(payload))) return null;

    let data;
    try {
        data = JSON.parse(Buffer.from(payload, 'hex').toString('utf8'));
    } catch (e) {
        return null;
    }
    if (!data || typeof data.exp !== 'number' || data.exp < Math.floor(Date.now() / 1000)) return null;
    if (typeof data.uid !== 'number' || typeof data.u !== 'string') return null;
    return data;
}

/**
 * Room tickets — minted by ws_auth.php.
 *
 * Payload: { r: 'room'|'private', n: name, u: uid, e: exp }
 * Signature: HMAC-SHA256 over the base64url payload, same key as the auth
 * token, so a ticket cannot be forged without the server secret.
 */
function verifyRoomTicket(ticket, kind, name, uid) {
    if (!ROOM_TICKET_REQUIRED) return true;
    if (typeof ticket !== 'string' || ticket.length > 1024) return false;

    const dot = ticket.indexOf('.');
    if (dot === -1) return false;
    const payload = ticket.slice(0, dot);
    const sig = ticket.slice(dot + 1);

    if (!timingSafeEqualStr(sig, hmac(payload))) return false;

    let data;
    try {
        data = JSON.parse(Buffer.from(payload, 'hex').toString('utf8'));
    } catch (e) {
        return false;
    }
    if (!data || typeof data.e !== 'number' || data.e < Math.floor(Date.now() / 1000)) return false;
    if (data.r !== kind) return false;
    if (String(data.n) !== String(name)) return false;
    if (Number(data.u) !== Number(uid)) return false;
    return true;
}

function roomKey(kind, name) {
    return kind + ':' + name;
}

// ── Connection handling ──────────────────────────────────────────────────────

const presence = new Map();
const ROOM_NAME_RE = /^[\p{L}\p{N} _.\-]{1,100}$/u;

io.use((socket, next) => {
    const origin = socket.handshake.headers.origin;
    const host = socket.handshake.headers.host;
    if (!originAllowed(origin, host)) {
        console.warn('[veyrax-ws] rejected origin:', origin || '(none)', 'host:', host || '(none)');
        return next(new Error('origin_not_allowed'));
    }

    const token = socket.handshake.auth && socket.handshake.auth.token;
    const user = verifyToken(token);
    if (!user) return next(new Error('auth_failed'));

    socket.data.user = user;
    next();
});

io.on('connection', (socket) => {
    const user = socket.data.user;
    const uid = user.uid;
    const uname = user.u;

    socket.join('user:' + uid);
    presence.set(uid, (presence.get(uid) || 0) + 1);

    const broadcastPresence = (online) => {
        socket.broadcast.emit('presence', { user_id: uid, username: uname, online });
    };
    broadcastPresence(true);
    socket.emit('presence_snapshot', { user_ids: Array.from(presence.keys()) });

    /**
     * Join a room — only with a ticket that names this room and this user.
     * Without ROOM_TICKET_REQUIRED (dev mode) the name is still validated so a
     * client cannot create arbitrary room keys.
     */
    socket.on('join_room', (data) => {
        const room = String((data && data.room) || '').slice(0, 100);
        if (!room || !ROOM_NAME_RE.test(room)) return;

        if (!verifyRoomTicket(data && data.ticket, 'room', room, uid)) {
            socket.emit('room_denied', { room });
            return;
        }
        socket.join(roomKey('room', room));
        socket.data.currentRoom = room;
    });

    socket.on('leave_room', (data) => {
        const room = String((data && data.room) || '').slice(0, 100);
        if (!room) return;
        socket.leave(roomKey('room', room));
        if (socket.data.currentRoom === room) socket.data.currentRoom = null;
    });

    /**
     * Private conversations are delivered through the per-user room
     * (`user:<id>`) that every socket joins automatically, so a client never
     * needs to subscribe to somebody else's private channel. Kept as a no-op
     * for wire compatibility with older clients — silently ignoring it is what
     * closes the eavesdropping hole.
     */
    socket.on('join_private', () => { /* intentionally ignored */ });
    socket.on('leave_private', () => { /* intentionally ignored */ });

    socket.on('typing', (data) => {
        const payload = {
            user_id: uid,
            username: uname,
            room: data && data.room ? String(data.room).slice(0, 100) : null,
            target_user: data && data.target_user ? parseInt(data.target_user, 10) || null : null
        };
        if (payload.target_user) {
            io.to('user:' + payload.target_user).emit('typing', payload);
        } else if (payload.room) {
            socket.to(roomKey('room', payload.room)).emit('typing', payload);
        }
    });

    socket.on('typing_stop', (data) => {
        const payload = {
            user_id: uid,
            username: uname,
            room: data && data.room ? String(data.room).slice(0, 100) : null,
            target_user: data && data.target_user ? parseInt(data.target_user, 10) || null : null
        };
        if (payload.target_user) {
            io.to('user:' + payload.target_user).emit('typing_stop', payload);
        } else if (payload.room) {
            socket.to(roomKey('room', payload.room)).emit('typing_stop', payload);
        }
    });

    socket.on('read_receipt', (data) => {
        const sender = parseInt((data && data.sender_id) || 0, 10);
        if (!sender) return;
        io.to('user:' + sender).emit('read_receipt', { reader_id: uid, sender_id: sender, at: Math.floor(Date.now() / 1000) });
    });

    // force_reload is a server → client instruction only. It used to be
    // callable by any client, which let one user reload everybody else.
    socket.on('force_reload', () => { /* intentionally ignored */ });

    socket.on('disconnect', () => {
        const count = (presence.get(uid) || 1) - 1;
        if (count <= 0) {
            presence.delete(uid);
            broadcastPresence(false);
        } else {
            presence.set(uid, count);
        }
    });
});

// ── HTTP side: /health and /push ─────────────────────────────────────────────

/** Coarse per-IP bucket for the push endpoint: 120 requests / minute. */
const pushBuckets = new Map();
function pushAllowed(ip) {
    const now = Date.now();
    const b = pushBuckets.get(ip) || { tokens: 120, t: now };
    const elapsed = Math.max(0, now - b.t) / 1000;
    b.tokens = Math.min(120, b.tokens + elapsed * 2); // 2/s refill
    b.t = now;
    if (b.tokens < 1) {
        pushBuckets.set(ip, b);
        return false;
    }
    b.tokens -= 1;
    pushBuckets.set(ip, b);
    if (pushBuckets.size > 4096) pushBuckets.clear();
    return true;
}

function clientIp(req) {
    const ip = req.socket.remoteAddress || '';
    return ip.replace(/^::ffff:/, '');
}

function isLocalIp(ip) {
    return ip === '127.0.0.1' || ip === '::1' || ip === '::ffff:127.0.0.1' || ip.startsWith('10.') || ip.startsWith('192.168.');
}

const httpServer = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');

    // Public, information-free liveness probe. The old one leaked the number
    // of connected users and the process uptime.
    if (url.pathname === '/health') {
        res.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
        res.end(JSON.stringify({ status: 'ok' }));
        return;
    }

    // Detailed health requires the shared secret.
    if (url.pathname === '/health/full') {
        const secret = String(req.headers['x-push-secret'] || url.searchParams.get('secret') || '');
        if (!timingSafeEqualStr(secret, PUSH_SECRET)) {
            res.writeHead(403); res.end(); return;
        }
        res.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
        res.end(JSON.stringify({
            status: 'ok',
            clients: io.engine.clientsCount,
            uptime: Math.round(process.uptime()),
            originLocked: ALLOWED_ORIGINS.filter(o => o !== '*').length > 0,
            ticketRequired: ROOM_TICKET_REQUIRED
        }));
        return;
    }

    if (url.pathname === '/push' && req.method === 'POST') {
        const ip = clientIp(req);
        if (!isLocalIp(ip) && process.env.WS_PUSH_REMOTE !== '1') {
            res.writeHead(403); res.end(); return;
        }
        if (!pushAllowed(ip)) {
            res.writeHead(429, { 'Retry-After': '30' }); res.end(); return;
        }

        let body = '';
        let oversized = false;
        req.on('data', (chunk) => {
            body += chunk;
            if (body.length > 262144) { oversized = true; req.destroy(); }
        });
        req.on('end', () => {
            if (oversized) { res.writeHead(413); res.end(); return; }

            let parsed;
            try { parsed = JSON.parse(body); } catch (e) { res.writeHead(400); res.end(); return; }

            // Constant-time compare: `!==` leaks the secret a byte at a time.
            if (!parsed || !timingSafeEqualStr(parsed.secret, PUSH_SECRET)) {
                res.writeHead(403); res.end(); return;
            }
            dispatch(parsed.event, parsed.data || {});
            res.writeHead(200, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({ ok: true }));
        });
        return;
    }

    res.writeHead(404);
    res.end();
});

/**
 * Fan one realtime event out to the sockets it belongs to.
 * Used by both the HTTP /push endpoint (remote PHP hosts) and the local outbox
 * tailer below, so the two paths can never drift apart.
 */
function dispatch(event, data) {
    data = data || {};
    const targets = Array.isArray(data.targets) ? data.targets : [];
    const rooms = Array.isArray(data.rooms) ? data.rooms : [];
    const targetsSeen = new Set();
    for (const t of targets) {
        const uid = parseInt(t, 10);
        if (uid > 0 && !targetsSeen.has(uid)) {
            targetsSeen.add(uid);
            io.to('user:' + uid).emit(event, data);
        }
    }
    if (event === 'force_reload' && Array.isArray(data.user_ids)) {
        for (const id of data.user_ids) {
            const uid = parseInt(id, 10);
            if (uid > 0) io.to('user:' + uid).emit('force_reload', { reason: data.reason || 'reload' });
        }
    }
    if (event === 'force_reload' && data.all === true) {
        io.emit('force_reload', { reason: data.reason || 'reload' });
    }
    for (const r of rooms) {
        const kind = r.kind === 'private' ? 'private' : 'room';
        const name = String(r.name || '').slice(0, 100);
        if (name) io.to(roomKey(kind, name)).emit(event, data);
    }
}

// ── Local outbox ─────────────────────────────────────────────────────────────
/**
 * PHP publishes events by appending one JSON line to a state file in data/.
 * That makes the publish step a single ~0.05 ms file append with no network
 * hop, so "send message" never waits on the realtime layer. This tailer turns
 * the file back into socket events.
 *
 * The file name is not fixed. It is derived from the installation's AES key
 * with the same one-liner config.php uses:
 *
 *     data/.vx-<first 24 hex of HMAC-SHA256(key, "vx-state:<name>")>
 *
 * A fixed name (ws_outbox.log) is a URL anyone can type if the host ever drops
 * data/.htaccess; a keyed name is not. Both sides must agree, so the key is
 * read from data/config.php — never from this file.
 */
function readAppConst(name) {
    try {
        const cfg = fs.readFileSync(path.join(__dirname, '..', 'data', 'config.php'), 'utf8');
        const m = cfg.match(new RegExp("define\\(\\s*['\"]" + name + "['\"]\\s*,\\s*['\"]([^'\"]*)['\"]"));
        return m ? m[1] : '';
    } catch (e) {
        return '';
    }
}

const STATE_KEY = process.env.VX_STATE_KEY || readAppConst('ENCRYPTION_KEY');

function statePath(name) {
    const legacy = path.join(__dirname, '..', 'data', name + '.json');
    if (!STATE_KEY) return legacy;              // very old install, no key yet
    const token = crypto.createHmac('sha256', STATE_KEY)
        .update('vx-state:' + name)
        .digest('hex')
        .slice(0, 24);
    return path.join(__dirname, '..', 'data', '.vx-' + token);
}

function outboxPath() {
    if (process.env.WS_OUTBOX) return process.env.WS_OUTBOX;
    const current = statePath('ws_outbox');
    // Not written yet by PHP but the previous fixed-name file is there: keep
    // tailing that one until the next publish creates the new name.
    if (!fs.existsSync(current)) {
        const old = path.join(__dirname, '..', 'data', 'ws_outbox.log');
        if (fs.existsSync(old)) return old;
    }
    return current;
}

const OUTBOX_PATH = outboxPath();
const OUTBOX_TICK_MS = parseInt(process.env.WS_OUTBOX_TICK || '12', 10);
let outboxOffset = 0;
let outboxQueued = false;

function outboxStart() {
    try {
        // Never replay history: a restart must not re-fire old force_reloads.
        outboxOffset = fs.existsSync(OUTBOX_PATH) ? fs.statSync(OUTBOX_PATH).size : 0;
    } catch (e) {
        outboxOffset = 0;
    }
}

function outboxTick() {
    let stat;
    try {
        stat = fs.statSync(OUTBOX_PATH);
    } catch (e) {
        return;
    }
    if (stat.size < outboxOffset) outboxOffset = 0;   // file was truncated
    if (stat.size === outboxOffset) return;

    let chunk;
    try {
        const fd = fs.openSync(OUTBOX_PATH, 'r');
        const len = stat.size - outboxOffset;
        const buf = Buffer.allocUnsafe(len);
        fs.readSync(fd, buf, 0, len, outboxOffset);
        fs.closeSync(fd);
        chunk = buf.toString('utf8');
    } catch (e) {
        return;
    }

    // Keep the trailing partial line for the next tick.
    const lastNl = chunk.lastIndexOf('\n');
    if (lastNl === -1) return;
    const complete = chunk.slice(0, lastNl);
    outboxOffset += Buffer.byteLength(chunk.slice(0, lastNl + 1), 'utf8');

    for (const line of complete.split('\n')) {
        if (!line) continue;
        let item;
        try { item = JSON.parse(line); } catch (e) { continue; }
        if (!item || !item.e) continue;
        try { dispatch(item.e, item.d); } catch (e) { /* one bad event must not stop the tail */ }
    }
}

outboxStart();
// The interval is the safety net; the watcher is the fast path. An append
// wakes the tailer immediately, so the delay between the PHP write and the
// socket push is the file write itself rather than a polling tick.
setInterval(outboxTick, OUTBOX_TICK_MS).unref();
try {
    // Event-driven: an append wakes the tailer immediately instead of waiting
    // for the next tick.
    fs.watch(OUTBOX_PATH, { persistent: false }, function () {
        if (outboxQueued) return;
        outboxQueued = true;
        setImmediate(function () {
            outboxQueued = false;
            try { outboxTick(); } catch (e) {}
        });
    });
} catch (e) {
    // The interval above is a complete fallback on its own.
}

io.attach(httpServer);

httpServer.listen(WS_PORT, WS_HOST, () => {
    console.log('[veyrax-ws] listening on ' + WS_HOST + ':' + WS_PORT);
    console.log('[veyrax-ws] origins: ' + (ALLOWED_ORIGINS.filter(o => o !== '*').join(', ') || 'same-host only'));
    console.log('[veyrax-ws] room tickets: ' + (ROOM_TICKET_REQUIRED ? 'required' : 'DISABLED (dev mode)'));
    console.log('[veyrax-ws] outbox tail: ' + OUTBOX_PATH + ' every ' + OUTBOX_TICK_MS + 'ms');
    if (WS_HOST === '0.0.0.0' || WS_HOST === '::') {
        console.warn('[veyrax-ws] WARNING: bound to all interfaces. Put this behind a reverse proxy');
        console.warn('[veyrax-ws]          with TLS, or keep it on 127.0.0.1 and proxy from Apache/nginx.');
    }
});

process.on('SIGTERM', () => {
    io.close(() => httpServer.close(() => process.exit(0)));
    setTimeout(() => process.exit(0), 3000);
});
process.on('SIGINT', () => {
    io.close(() => httpServer.close(() => process.exit(0)));
    setTimeout(() => process.exit(0), 3000);
});
