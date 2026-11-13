const http = require('http');
const path = require('path');
const fs = require('fs');

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

// Read $ws_secret from the installer-generated app config so a plain `node server.js`
// works without extra environment setup (the installer randomises the secret).
function readAppConfigSecret() {
    try {
        const cfg = fs.readFileSync(path.join(__dirname, '..', 'data', 'config.php'), 'utf8');
        const m = cfg.match(/\$ws_secret\s*=\s*(['"])([^'"]+)\1/);
        return m ? m[2] : '';
    } catch (e) {
        return '';
    }
}

const PUSH_SECRET = process.env.PUSH_SECRET || readAppConfigSecret() || process.env.AUTH_SECRET || 'veyrax-ws-secret-change-me';
const ALLOWED_ORIGINS = (process.env.ALLOWED_ORIGINS || '*').split(',').map(s => s.trim()).filter(Boolean);

function originAllowed(origin) {
    if (ALLOWED_ORIGINS.includes('*')) return true;
    if (!origin) return true;
    return ALLOWED_ORIGINS.some(o => o === origin || (o.startsWith('*.') && origin.endsWith(o.slice(1))));
}

const { Server } = require('socket.io');
const io = new Server({
    cors: {
        origin: (origin, cb) => {
            if (originAllowed(origin)) return cb(null, true);
            cb(new Error('origin not allowed'));
        },
        methods: ['GET', 'POST'],
        credentials: true
    },
    pingInterval: 20000,
    pingTimeout: 25000,
    maxHttpBufferSize: 1e6
});

const crypto = require('crypto');
function verifyToken(token) {
    if (typeof token !== 'string') return null;
    const dot = token.indexOf('.');
    if (dot === -1) return null;
    const payload = token.slice(0, dot);
    const sig = token.slice(dot + 1);
    const expected = crypto.createHmac('sha256', PUSH_SECRET).update(payload).digest('hex');
    if (sig.length !== expected.length) return null;
    try {
        if (!crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expected))) return null;
    } catch (e) {
        return null;
    }
    let data;
    try {
        data = JSON.parse(Buffer.from(payload, 'base64').toString('utf8'));
    } catch (e) {
        return null;
    }
    if (!data || typeof data.exp !== 'number' || data.exp < Math.floor(Date.now() / 1000)) return null;
    if (typeof data.uid !== 'number' || typeof data.u !== 'string') return null;
    return data;
}

function roomKey(kind, name) {
    return kind + ':' + name;
}

const presence = new Map();

io.use((socket, next) => {
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

    socket.on('join_room', (data) => {
        const room = String((data && data.room) || '').slice(0, 100);
        if (!room) return;
        socket.join(roomKey('room', room));
        socket.data.currentRoom = room;
    });

    socket.on('leave_room', (data) => {
        const room = String((data && data.room) || '').slice(0, 100);
        if (!room) return;
        socket.leave(roomKey('room', room));
        if (socket.data.currentRoom === room) socket.data.currentRoom = null;
    });

    socket.on('join_private', (data) => {
        const partner = parseInt((data && data.user_id) || 0, 10);
        if (!partner || partner <= 0 || partner > 2147483647) return;
        socket.join(roomKey('private', String(partner)));
        socket.data.privatePartner = partner;
    });

    socket.on('leave_private', (data) => {
        const partner = parseInt((data && data.user_id) || 0, 10);
        if (!partner) return;
        socket.leave(roomKey('private', String(partner)));
        if (socket.data.privatePartner === partner) socket.data.privatePartner = null;
    });

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

    socket.on('force_reload', (data) => {
        const ids = Array.isArray(data && data.user_ids) ? data.user_ids : [];
        if (ids.length === 0) return;
        const reason = (data && data.reason) || 'reload';
        ids.forEach((id) => {
            const uid = parseInt(id, 10);
            if (uid > 0) io.to('user:' + uid).emit('force_reload', { reason });
        });
    });

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

const httpServer = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    if (url.pathname === '/health') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ status: 'ok', clients: io.engine.clientsCount, uptime: process.uptime() }));
        return;
    }
    if (url.pathname === '/push' && req.method === 'POST') {
        let body = '';
        let oversized = false;
        req.on('data', (chunk) => {
            body += chunk;
            if (body.length > 1e6) { oversized = true; req.destroy(); }
        });
        req.on('end', () => {
            if (oversized) { res.writeHead(413); res.end(); return; }
            let parsed;
            try { parsed = JSON.parse(body); } catch (e) { res.writeHead(400); res.end(); return; }
            if (!parsed || parsed.secret !== PUSH_SECRET) { res.writeHead(403); res.end(); return; }
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

/**
 * ── Local outbox ───────────────────────────────────────────────────────────
 * PHP publishes events by appending one JSON line to data/ws_outbox.log. That
 * makes the publish step a single ~0.05 ms file append with no network hop, so
 * "send message" never waits on the realtime layer. This tailer turns the file
 * back into socket events.
 *
 * A 40 ms tick is the whole latency budget added on top of the socket push —
 * an order of magnitude less than the 500 ms database poll it replaces.
 */
const OUTBOX_PATH = process.env.WS_OUTBOX || path.join(__dirname, '..', 'data', 'ws_outbox.log');
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
    const source = process.env.PUSH_SECRET ? 'env' : (readAppConfigSecret() ? 'data/config.php' : 'default');
    console.log('[veyrax-ws] listening on ' + WS_HOST + ':' + WS_PORT + ' (secret: ' + source + ')');
    console.log('[veyrax-ws] outbox tail: ' + OUTBOX_PATH + ' every ' + OUTBOX_TICK_MS + 'ms');
    if (source === 'default') {
        console.log('[veyrax-ws] WARNING: no app secret found; set PUSH_SECRET or install the app so data/config.php exists.');
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
