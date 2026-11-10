/*
 * audiEdiPipe service worker (PWA).
 * - static assets: cache first (versioned by query string)
 * - pages: network first, offline page as fallback
 * - API/audio/uploads: always network (never cached)
 */
'use strict';

var VERSION = 'aep-v1';
var SCOPE = self.registration ? self.registration.scope : '/';
var OFFLINE_URL = SCOPE + 'offline';
var PRECACHE = [
    OFFLINE_URL,
    SCOPE + 'assets/vendor/bootstrap.min.css',
    SCOPE + 'assets/vendor/bootstrap.bundle.min.js',
    SCOPE + 'assets/vendor/bootstrap-icons/bootstrap-icons.min.css',
    SCOPE + 'assets/css/app.css',
    SCOPE + 'assets/js/theme.js',
    SCOPE + 'assets/js/app.js',
    SCOPE + 'assets/img/logo.svg'
];

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(VERSION).then(function (cache) {
            return Promise.all(PRECACHE.map(function (url) {
                return cache.add(new Request(url, { credentials: 'same-origin' })).catch(function () { /* optional */ });
            }));
        }).then(function () { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.filter(function (k) { return k !== VERSION; }).map(function (k) { return caches.delete(k); }));
        }).then(function () { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function (event) {
    var request = event.request;
    if (request.method !== 'GET') { return; }
    var url = new URL(request.url);
    if (url.origin !== self.location.origin) { return; }
    var path = url.pathname;

    // never cache dynamic data, media or authentication
    if (/\/(jobs\/\d+\/(audio|peaks|download)|preview\/|media\/|feed\/|cron|login|logout|jobs\/status)/.test(path) || request.headers.get('range')) {
        return;
    }

    if (path.indexOf('/assets/') !== -1) {
        event.respondWith(
            caches.match(request).then(function (cached) {
                return cached || fetch(request).then(function (response) {
                    if (response.ok) {
                        var copy = response.clone();
                        caches.open(VERSION).then(function (cache) { cache.put(request, copy); });
                    }
                    return response;
                });
            })
        );
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(function () {
                return caches.match(OFFLINE_URL).then(function (r) { return r || new Response('Offline', { status: 503 }); });
            })
        );
    }
});
