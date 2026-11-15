/*
 * audiEdiPipe – PWA client side (loaded in <head> on every page, including
 * login/2FA, so the app is installable before signing in):
 *
 *  - registers the service worker (public/sw.js),
 *  - shows visible messages when something cannot be loaded. In the installed
 *    app there is no browser bar with a loading indicator or error page – a
 *    failed request otherwise looks like "nothing happens":
 *      * fetch()/XMLHttpRequest without connection or with a server error (≥ 500),
 *      * an expired session (request ends up on /login),
 *      * no internet connection (sticky hint until it is back; forms are not
 *        submitted at all while offline),
 *  - drives the offline page (errors/offline.php): retry, automatic reload
 *    when the connection is back.
 *
 * Inline scripts are blocked by the Content-Security-Policy, so everything
 * lives in this file. Own scripts can use window.AEP_showLoadError(text, options);
 * requests with the header "X-No-Load-Error" are ignored.
 */
(function () {
    'use strict';

    if (window.AEP_showLoadError) {
        return;
    }

    var FALLBACK = {
        'js.pwa.network': 'No connection to the server. Please check your internet connection and try again.',
        'js.pwa.server': 'The server reported an error – the data could not be loaded (error {status}).',
        'js.pwa.session': 'Your session has expired. Please sign in again.',
        'js.pwa.offline': 'No internet connection – changes cannot be saved right now.',
        'js.pwa.offline_submit': 'No internet connection – the form was not submitted. Please try again once you are back online.',
        'js.pwa.reload': 'Reload page',
        'js.pwa.login': 'Sign in',
        'js.pwa.close': 'Close message',
        'js.pwa.messages': 'Messages',
        'js.pwa.offline_reason_server': 'The server cannot be reached right now. Please try again in a moment.',
        'js.pwa.reloading': 'Reloading …',
        'js.pwa.back_online': 'Connection is back – reloading …'
    };
    var messages = null;

    function t(key, params) {
        if (messages === null) {
            // the catalogue lives in <body>; until it is parsed use the fallback
            var el = document.getElementById('aep-i18n');
            if (el) {
                try { messages = JSON.parse(el.textContent || '{}'); } catch (e) { messages = {}; }
                if (!messages || Array.isArray(messages)) { messages = {}; }
            }
        }
        var source = messages || {};
        var text = Object.prototype.hasOwnProperty.call(source, key) ? source[key] : (FALLBACK[key] || key);
        Object.keys(params || {}).forEach(function (name) {
            text = text.split('{' + name + '}').join(String(params[name]));
        });
        return text;
    }

    function meta(name) {
        var el = document.querySelector('meta[name="' + name + '"]');
        return el ? el.getAttribute('content') || '' : '';
    }
    var base = meta('base-url');
    var loginPath = (base ? new URL(base + '/', location.href).pathname.replace(/\/$/, '') : '') + '/login';

    /* ---------- service worker ---------- */
    if ('serviceWorker' in navigator && window.isSecureContext) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(base + '/sw.js', { scope: base + '/' }).catch(function () {
                // e.g. not served via HTTPS – the app works fine without it
            });
        });
    }

    /* ---------- leaving the page is not an error ---------- */
    var unloading = false;
    var unloadTimer = null;
    function markUnloading() {
        // requests aborted by navigating away are no errors; downloads keep the
        // page open, so reset after a moment
        unloading = true;
        clearTimeout(unloadTimer);
        unloadTimer = setTimeout(function () { unloading = false; }, 3000);
    }
    window.addEventListener('beforeunload', markUnloading);
    window.addEventListener('pagehide', markUnloading);
    window.addEventListener('pageshow', function () { unloading = false; });

    /* ---------- toasts ---------- */
    var stack = null;
    var visible = {};
    var offlineToast = null;

    function container() {
        if (stack && document.body.contains(stack)) {
            return stack;
        }
        stack = document.createElement('div');
        stack.className = 'aep-le-stack';
        stack.setAttribute('role', 'region');
        stack.setAttribute('aria-label', t('js.pwa.messages'));
        document.body.appendChild(stack);
        return stack;
    }

    /**
     * Show a message. options: reload (button "Reload page"), login (button
     * "Sign in"), sticky (stays until closed), warn (yellow instead of red).
     */
    function show(message, options) {
        options = options || {};
        if (!document.body) {
            document.addEventListener('DOMContentLoaded', function () { show(message, options); });
            return null;
        }
        // do not stack the same message (e.g. a status poll failing every few seconds)
        if (visible[message] && document.body.contains(visible[message])) {
            return visible[message];
        }

        var toast = document.createElement('div');
        toast.className = 'aep-le-toast' + (options.warn ? ' aep-le-warn' : '');
        toast.setAttribute('role', 'alert');
        toast.innerHTML = '<i class="bi ' + (options.warn ? 'bi-wifi-off' : 'bi-exclamation-octagon') + ' aep-le-icon" aria-hidden="true"></i><div class="aep-le-text"></div>';
        var text = toast.querySelector('.aep-le-text');
        text.textContent = message;

        var actions = document.createElement('div');
        actions.className = 'aep-le-actions';
        if (options.reload) {
            var reload = document.createElement('button');
            reload.type = 'button';
            reload.className = 'aep-le-btn';
            reload.textContent = t('js.pwa.reload');
            reload.addEventListener('click', function () { window.location.reload(); });
            actions.appendChild(reload);
        }
        if (options.login) {
            var login = document.createElement('a');
            login.className = 'aep-le-btn';
            login.href = loginPath;
            login.textContent = t('js.pwa.login');
            actions.appendChild(login);
        }
        if (actions.children.length) {
            text.appendChild(actions);
        }

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'aep-le-close';
        close.setAttribute('aria-label', t('js.pwa.close'));
        close.innerHTML = '&times;';
        close.addEventListener('click', function () { remove(toast); });
        toast.appendChild(close);

        container().appendChild(toast);
        visible[message] = toast;
        if (!options.sticky) {
            setTimeout(function () { remove(toast); }, 10000);
        }
        return toast;
    }

    function remove(toast) {
        if (toast && toast.parentNode) {
            toast.parentNode.removeChild(toast);
        }
    }

    window.AEP_showLoadError = show;
    window.AEP_hideLoadError = remove;

    /* ---------- watch requests ---------- */
    function sameOrigin(url) {
        try {
            return new URL(url, window.location.href).origin === window.location.origin;
        } catch (e) {
            return false;
        }
    }

    function checkResponse(status, responseUrl, redirected) {
        if (unloading) {
            return;
        }
        if (redirected && responseUrl && sameOrigin(responseUrl) && new URL(responseUrl, location.href).pathname === loginPath && location.pathname !== loginPath) {
            show(t('js.pwa.session'), { login: true, sticky: true });
        } else if (status >= 500) {
            show(t('js.pwa.server', { status: status }), { reload: true });
        }
    }

    function networkFailed() {
        if (unloading) {
            return;
        }
        if (navigator.onLine === false) {
            showOffline();
        } else {
            show(t('js.pwa.network'), { reload: true });
        }
    }

    function optedOut(init) {
        var headers = init && init.headers;
        if (!headers) {
            return false;
        }
        if (typeof headers.get === 'function') {
            return headers.get('X-No-Load-Error') !== null;
        }
        return Object.prototype.hasOwnProperty.call(headers, 'X-No-Load-Error');
    }

    if (window.fetch) {
        var originalFetch = window.fetch;
        window.fetch = function (input, init) {
            var url = typeof input === 'string' ? input : (input && input.url) || String(input);
            var watched = sameOrigin(url) && !optedOut(init) && !(input && typeof input === 'object' && optedOut(input));
            return originalFetch.apply(this, arguments).then(function (response) {
                if (watched) {
                    checkResponse(response.status, response.url, response.redirected);
                }
                return response;
            }, function (error) {
                if (watched && !(error && error.name === 'AbortError')) {
                    networkFailed();
                }
                throw error;
            });
        };
    }

    if (window.XMLHttpRequest) {
        var originalOpen = XMLHttpRequest.prototype.open;
        var originalSend = XMLHttpRequest.prototype.send;
        var originalSetHeader = XMLHttpRequest.prototype.setRequestHeader;
        XMLHttpRequest.prototype.open = function (method, url) {
            this.__aepWatched = sameOrigin(url);
            return originalOpen.apply(this, arguments);
        };
        XMLHttpRequest.prototype.setRequestHeader = function (name) {
            if (String(name).toLowerCase() === 'x-no-load-error') {
                this.__aepWatched = false;
            }
            return originalSetHeader.apply(this, arguments);
        };
        XMLHttpRequest.prototype.send = function () {
            if (this.__aepWatched) {
                var xhr = this;
                xhr.addEventListener('load', function () { checkResponse(xhr.status, xhr.responseURL, xhr.responseURL !== '' && new URL(xhr.responseURL, location.href).pathname === loginPath); });
                xhr.addEventListener('error', networkFailed);
            }
            return originalSend.apply(this, arguments);
        };
    }

    /* ---------- offline ---------- */
    function showOffline() {
        if (offlineToast && document.body && document.body.contains(offlineToast)) {
            return;
        }
        offlineToast = show(t('js.pwa.offline'), { sticky: true, warn: true });
    }

    window.addEventListener('offline', showOffline);
    window.addEventListener('online', function () {
        remove(offlineToast);
        offlineToast = null;
    });

    // While offline, do not submit forms at all (otherwise the installed app
    // hangs without feedback). Capture phase, so it runs before other handlers.
    window.addEventListener('submit', function (e) {
        if (navigator.onLine === false) {
            e.preventDefault();
            e.stopImmediatePropagation();
            show(t('js.pwa.offline_submit'), { warn: true });
        }
    }, true);

    document.addEventListener('DOMContentLoaded', function () {
        var offlinePage = document.querySelector('[data-offline-page]');
        if (offlinePage) {
            initOfflinePage(offlinePage);
        } else if (navigator.onLine === false) {
            showOffline();
        }
    });

    /* ---------- offline page (shown by the service worker at the requested URL) ---------- */
    function initOfflinePage(page) {
        var status = page.querySelector('[data-offline-status]');
        var reason = page.querySelector('[data-offline-reason]');
        if (navigator.onLine && reason) {
            reason.textContent = t('js.pwa.offline_reason_server');
        }
        var retry = function (message) {
            if (status) { status.textContent = message; }
            window.location.reload();
        };
        page.querySelectorAll('[data-offline-retry]').forEach(function (button) {
            button.addEventListener('click', function (e) {
                e.preventDefault();
                retry(t('js.pwa.reloading'));
            });
        });
        window.addEventListener('online', function () {
            if (status) { status.textContent = t('js.pwa.back_online'); }
            setTimeout(function () { retry(t('js.pwa.back_online')); }, 600);
        });
    }
})();
