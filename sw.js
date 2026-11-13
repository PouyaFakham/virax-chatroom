const VX_SW_VERSION = 'vx-1.7.1';
const VX_SW_SHELL = VX_SW_VERSION + '-shell';

const STATIC_ALLOW = [
    /\/assets\//i,
    /\/fonts\//i,
    /\/icons\//i,
    /\/websocket\/.*\.js$/i
];

self.addEventListener('install', function (e) {
    self.skipWaiting();
});

self.addEventListener('activate', function (e) {
    e.waitUntil((async function () {
        const keys = await caches.keys();
        await Promise.all(keys.map(function (k) {
            if (k !== VX_SW_SHELL) return caches.delete(k);
        }));
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', function (e) {
    const req = e.request;
    if (req.method !== 'GET') return;

    let url;
    try { url = new URL(req.url); } catch (err) { return; }
    if (url.origin !== self.location.origin) return;
    if (url.pathname.indexOf('/ch/') !== 0 && url.pathname !== '/ch') return;

    const isStatic = STATIC_ALLOW.some(function (rx) { return rx.test(url.pathname); });
    if (!isStatic) return;

    e.respondWith((async function () {
        const cache = await caches.open(VX_SW_SHELL);
        const hit = await cache.match(req);
        if (hit) {
            fetch(req).then(function (res) {
                if (res && res.ok) cache.put(req, res.clone());
            }).catch(function () {});
            return hit;
        }
        try {
            const res = await fetch(req);
            if (res && res.ok) cache.put(req, res.clone());
            return res;
        } catch (err) {
            const fallback = await cache.match(req);
            if (fallback) return fallback;
            throw err;
        }
    })());
});

self.addEventListener('message', function (e) {
    if (e.data && e.data.type === 'VX_SW_PURGE') {
        e.waitUntil((async function () {
            const keys = await caches.keys();
            await Promise.all(keys.map(function (k) { return caches.delete(k); }));
            if (e.source && e.source.postMessage) e.source.postMessage({ type: 'VX_SW_PURGED' });
        })());
    }
});
