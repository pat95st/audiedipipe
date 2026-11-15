/*
 * audiEdiPipe service worker (PWA): installability + offline availability of
 * the app shell. Registered by pwa.js on every page (also login/2FA) as a real
 * file under <base>/sw.js, so its scope covers the whole application – also
 * when installed in a sub directory.
 *
 * Strategies:
 *  - static assets (/assets/…, versioned via ?v=filemtime): cache first; an
 *    older version of the same file is replaced, offline the last known
 *    version is used even if the version parameter differs,
 *  - app icons / uploaded logo with version parameter: cache first in a
 *    separate cache that survives version bumps (the URL changes on upload),
 *  - manifest, theme.css: network first, cache as offline fallback,
 *  - pages: always fresh from the network – they contain a fresh CSRF token
 *    and live data; a cached page would carry a stale token and make every
 *    form fail. Without connection the offline page is shown instead,
 *  - everything else (API, job status, audio, peaks, downloads, feeds,
 *    uploads, cron, login/logout, range requests): not intercepted at all.
 *
 * Bump VERSION when the list below or the caching logic changes; changed
 * asset files are picked up automatically through their ?v= parameter.
 */
'use strict';

var VERSION = 'aep-v2';
var BRANDING_CACHE = 'aep-branding-v1';
var SCOPE = self.registration ? self.registration.scope : self.location.href.replace(/sw\.js.*$/, '');
var BASE = new URL(SCOPE).pathname; // e.g. "/" or "/audio/"
var OFFLINE_URL = SCOPE + 'offline';
var PRECACHE = [
    OFFLINE_URL,
    SCOPE + 'manifest.webmanifest',
    SCOPE + 'theme.css',
    SCOPE + 'assets/vendor/bootstrap.min.css',
    SCOPE + 'assets/vendor/bootstrap.bundle.min.js',
    SCOPE + 'assets/vendor/bootstrap-icons/bootstrap-icons.min.css',
    SCOPE + 'assets/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2',
    SCOPE + 'assets/css/app.css',
    SCOPE + 'assets/js/theme.js',
    SCOPE + 'assets/js/pwa.js',
    SCOPE + 'assets/js/app.js',
    SCOPE + 'assets/img/logo.svg',
    SCOPE + 'assets/img/icon-192.png',
    SCOPE + 'assets/img/icon-512.png',
    SCOPE + 'assets/img/icon-maskable-512.png',
    SCOPE + 'assets/img/apple-touch-icon.png'
];

// never intercepted (relative to the scope)
var BYPASS = /^(jobs\/\d+\/(audio|peaks|download|waveform)|jobs\/status|preview\/|media\/|feed\/|uploads\/(?!branding\/)|cron|login|logout|oauth\/|install|i18n\/|sw\.js)/;

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(VERSION).then(function (cache) {
            // individually, so one missing file does not break the installation
            return Promise.all(PRECACHE.map(function (url) {
                return cache.add(new Request(url, { credentials: 'same-origin', cache: 'reload' })).catch(function () { /* optional */ });
            }));
        }).then(function () { return self.skipWaiting(); })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.filter(function (key) {
                return key !== VERSION && key !== BRANDING_CACHE;
            }).map(function (key) { return caches.delete(key); }));
        }).then(function () { return self.clients.claim(); })
    );
});

/** Store a response and drop other versions (?v=…) of the same path. */
function putReplacing(cacheName, request, response) {
    var path = new URL(request.url).pathname;
    return caches.open(cacheName).then(function (cache) {
        return cache.keys().then(function (requests) {
            return Promise.all(requests.filter(function (r) {
                return new URL(r.url).pathname === path && r.url !== request.url;
            }).map(function (r) { return cache.delete(r); }));
        }).then(function () { return cache.put(request, response); });
    });
}

function cacheFirst(request, cacheName) {
    return caches.open(cacheName).then(function (cache) {
        return cache.match(request).then(function (cached) {
            if (cached) {
                return cached;
            }
            return fetch(request).then(function (response) {
                if (response && response.ok && response.type === 'basic') {
                    putReplacing(cacheName, request, response.clone());
                }
                return response;
            }).catch(function (error) {
                // offline: any cached version of the file is better than nothing
                return cache.match(request, { ignoreSearch: true }).then(function (fallback) {
                    if (fallback) {
                        return fallback;
                    }
                    throw error;
                });
            });
        });
    });
}

function networkFirst(request) {
    return fetch(request).then(function (response) {
        if (response && response.ok) {
            var copy = response.clone();
            caches.open(VERSION).then(function (cache) { cache.put(request, copy); });
        }
        return response;
    }).catch(function (error) {
        return caches.match(request, { ignoreSearch: true }).then(function (cached) {
            if (cached) {
                return cached;
            }
            throw error;
        });
    });
}

function offlineResponse() {
    return caches.match(OFFLINE_URL).then(function (cached) {
        return cached || new Response(
            '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Offline</title>' +
            '<p style="font-family:sans-serif;padding:2rem;text-align:center">The page could not be loaded. / Die Seite konnte nicht geladen werden. <a href="">↻</a></p>',
            { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
        );
    });
}

self.addEventListener('fetch', function (event) {
    var request = event.request;
    if (request.method !== 'GET') {
        return; // forms, uploads, API writes go straight to the network
    }
    var url = new URL(request.url);
    if (url.origin !== self.location.origin || url.pathname.indexOf(BASE) !== 0) {
        return;
    }
    // audio is loaded in parts (Range) – a cached complete response would break seeking
    if (request.headers.has('range') || request.destination === 'audio' || request.destination === 'video') {
        return;
    }
    var path = url.pathname.substring(BASE.length);
    if (BYPASS.test(path)) {
        return;
    }

    // app icons rendered from the logo and the uploaded logo itself
    if ((path.indexOf('app-icon/') === 0 && url.searchParams.has('v')) || path.indexOf('uploads/branding/') === 0) {
        event.respondWith(cacheFirst(request, BRANDING_CACHE));
        return;
    }

    if (path.indexOf('assets/') === 0) {
        event.respondWith(cacheFirst(request, VERSION));
        return;
    }

    if (path === 'manifest.webmanifest' || path === 'theme.css') {
        event.respondWith(networkFirst(request));
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(offlineResponse));
    }
    // everything else: untouched, the browser loads it as if there was no service worker
});
