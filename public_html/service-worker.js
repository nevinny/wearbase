const VERSION = 'wearbase-family-v1';
const STATIC_CACHE = `${VERSION}-static`;
const STATIC_ASSETS = [
    '/favicon.ico',
    '/images/logo.svg',
    '/images/pwa/icon-180.png',
    '/images/pwa/icon-192.png',
    '/images/pwa/icon-512.png',
    '/manifest.webmanifest'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then((cache) => cache.addAll(STATIC_ASSETS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys
                    .filter((key) => key.startsWith('wearbase-family-') && key !== STATIC_CACHE)
                    .map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => new Response(offlinePage(), {
                status: 503,
                headers: {
                    'Content-Type': 'text/html; charset=utf-8',
                    'Cache-Control': 'no-store'
                }
            }))
        );

        return;
    }

    if (STATIC_ASSETS.includes(url.pathname)) {
        event.respondWith(
            caches.match(request).then((cached) => cached || fetch(request))
        );
    }
});

function offlinePage() {
    return `<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#4f46e5">
    <title>Нет подключения — WEARBASE</title>
    <style>
        body{margin:0;background:#f8fafc;color:#0f172a;font:16px/1.5 system-ui,sans-serif}
        main{min-height:100vh;display:grid;place-content:center;box-sizing:border-box;padding:24px;text-align:center}
        section{max-width:360px;background:#fff;border:1px solid #e2e8f0;border-radius:24px;padding:32px;box-shadow:0 12px 35px rgba(15,23,42,.08)}
        h1{margin:0 0 8px;font-size:24px}p{margin:0 0 24px;color:#475569}
        button{border:0;border-radius:14px;background:#4f46e5;color:#fff;padding:13px 20px;font:inherit;font-weight:700}
    </style>
</head>
<body><main><section><h1>Нет подключения</h1><p>Подключитесь к интернету, чтобы продолжить работу с семейным гардеробом.</p><button onclick="location.reload()">Повторить</button></section></main></body>
</html>`;
}
