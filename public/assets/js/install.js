/*
 * audiEdiPipe – installer: re-runs the requirements check live whenever the
 * ffmpeg / ffprobe path changes (or on "Check again"), re-renders the
 * checklist and enables the install button once everything required is met.
 * Standalone on purpose: it must work before app.js is loaded.
 */
(function () {
    'use strict';

    var list = document.getElementById('aep-install-checks');
    if (!list) {
        return;
    }
    var url = list.getAttribute('data-check-url') || 'install/check';
    var inputs = Array.prototype.slice.call(document.querySelectorAll('[data-install-binary]'));
    var recheck = document.getElementById('aep-install-recheck');
    var submit = document.getElementById('aep-install-submit');
    var warning = document.getElementById('aep-install-warning');
    var spinner = recheck ? recheck.querySelector('.spinner-border') : null;
    var timer = null;
    var controller = null;
    var lastQuery = null;

    function query() {
        var params = [];
        inputs.forEach(function (input) {
            params.push(encodeURIComponent(input.name) + '=' + encodeURIComponent(input.value.trim()));
        });
        return params.join('&');
    }

    function busy(state) {
        if (recheck) {
            recheck.disabled = state;
        }
        if (spinner) {
            spinner.classList.toggle('d-none', !state);
        }
        list.setAttribute('aria-busy', state ? 'true' : 'false');
    }

    function apply(data) {
        if (typeof data.html === 'string') {
            list.innerHTML = data.html;
        }
        var ok = data.ok === true;
        if (submit) {
            submit.disabled = !ok;
        }
        if (warning) {
            warning.classList.toggle('d-none', ok);
        }
    }

    function run(force) {
        var q = query();
        if (!force && q === lastQuery) {
            return;
        }
        lastQuery = q;
        if (controller) {
            controller.abort();
        }
        controller = typeof AbortController === 'function' ? new AbortController() : null;
        busy(true);
        fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + q, {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
            signal: controller ? controller.signal : undefined
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                apply(data);
                busy(false);
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }
                lastQuery = null; // allow retrying the same values
                busy(false);
            });
    }

    inputs.forEach(function (input) {
        input.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { run(false); }, 700);
        });
        input.addEventListener('change', function () {
            clearTimeout(timer);
            run(false);
        });
    });
    if (recheck) {
        recheck.addEventListener('click', function () {
            clearTimeout(timer);
            run(true);
        });
    }
    lastQuery = query();
})();
