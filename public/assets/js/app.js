/*
 * audiEdiPipe – shared client side behaviour (no build step, no dependencies
 * besides Bootstrap). Exposes window.AEP with helpers used by page scripts.
 */
(function () {
    'use strict';

    var i18nEl = document.getElementById('aep-i18n');
    var messages = {};
    try { messages = i18nEl ? JSON.parse(i18nEl.textContent || '{}') : {}; } catch (e) { messages = {}; }
    if (Array.isArray(messages)) { messages = {}; }

    var meta = function (name) {
        var el = document.querySelector('meta[name="' + name + '"]');
        return el ? el.getAttribute('content') || '' : '';
    };

    var AEP = {
        base: meta('base-url'),
        csrf: meta('csrf-token'),

        /** Translate a key with {placeholder} replacements. */
        t: function (key, params) {
            var text = Object.prototype.hasOwnProperty.call(messages, key) ? messages[key] : key;
            if (params) {
                Object.keys(params).forEach(function (name) {
                    text = text.split('{' + name + '}').join(String(params[name]));
                });
            }
            return text;
        },

        url: function (path) {
            return AEP.base + '/' + String(path).replace(/^\//, '');
        },

        /** JSON request with CSRF header. Resolves with parsed JSON, rejects with Error(message). */
        request: function (method, url, body) {
            var options = {
                method: method,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-CSRF-Token': AEP.csrf, 'X-Requested-With': 'fetch' }
            };
            if (body instanceof FormData) {
                options.body = body;
            } else if (body !== undefined) {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(body);
            }
            return fetch(url, options).then(function (response) {
                return response.text().then(function (text) {
                    var data = {};
                    try { data = text ? JSON.parse(text) : {}; } catch (e) { data = { error: text.slice(0, 300) }; }
                    if (!response.ok) {
                        throw new Error(data.error || data.message || ('HTTP ' + response.status));
                    }
                    return data;
                });
            });
        },

        toast: function (message, type) {
            var container = document.getElementById('aepToasts');
            if (!container || !window.bootstrap) { return; }
            var el = document.createElement('div');
            el.className = 'toast align-items-center border-0 text-bg-' + (type || 'secondary');
            el.setAttribute('role', 'status');
            el.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
            el.querySelector('.toast-body').textContent = message;
            container.appendChild(el);
            var toast = new window.bootstrap.Toast(el, { delay: 4000 });
            el.addEventListener('hidden.bs.toast', function () { el.remove(); });
            toast.show();
        },

        escape: function (value) {
            return String(value === null || value === undefined ? '' : value)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        },

        formatTime: function (seconds, withMillis) {
            seconds = Math.max(0, seconds || 0);
            var m = Math.floor(seconds / 60);
            var s = Math.floor(seconds % 60);
            var out = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
            if (withMillis) {
                var ms = Math.floor((seconds % 1) * 1000);
                out += '.' + ('00' + ms).slice(-3);
            }
            return out;
        },

        /* --- WebAuthn helpers (base64url <-> ArrayBuffer) --- */
        b64urlToBuffer: function (value) {
            var base64 = String(value).replace(/-/g, '+').replace(/_/g, '/');
            while (base64.length % 4) { base64 += '='; }
            var binary = atob(base64);
            var bytes = new Uint8Array(binary.length);
            for (var i = 0; i < binary.length; i++) { bytes[i] = binary.charCodeAt(i); }
            return bytes.buffer;
        },
        bufferToB64url: function (buffer) {
            var bytes = new Uint8Array(buffer);
            var binary = '';
            for (var i = 0; i < bytes.length; i++) { binary += String.fromCharCode(bytes[i]); }
            return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
        }
    };
    window.AEP = AEP;

    /* ---------- Theme toggle: auto -> light -> dark -> auto ---------- */
    var applyTheme = function (pref) {
        var dark = pref === 'dark' || (pref === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme-active-pref', pref);
        document.dispatchEvent(new CustomEvent('aep:theme'));
    };
    document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            var order = ['auto', 'light', 'dark'];
            var current = document.documentElement.getAttribute('data-theme-active-pref') || 'auto';
            var next = order[(order.indexOf(current) + 1) % order.length];
            try { localStorage.setItem('aep-theme', next); } catch (e) { /* ignore */ }
            applyTheme(next);
            AEP.toast(AEP.t('js.theme.' + next));
        });
    });
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
            applyTheme(document.documentElement.getAttribute('data-theme-active-pref') || 'auto');
        });
    }
    // A server side theme preference (profile) wins over an old local toggle once saved.
    var themeSelect = document.querySelector('select[name="theme"]');
    if (themeSelect) {
        themeSelect.form.addEventListener('submit', function () {
            try { localStorage.removeItem('aep-theme'); } catch (e) { /* ignore */ }
        });
    }

    /* ---------- Confirmations & auto submit ---------- */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (form instanceof HTMLFormElement && form.hasAttribute('data-confirm')) {
            if (!window.confirm(form.getAttribute('data-confirm'))) {
                event.preventDefault();
            }
        }
    });
    document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
        el.addEventListener('change', function () { el.form.submit(); });
    });

    /* ---------- Copy to clipboard ---------- */
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-copy]');
        if (!button) { return; }
        var text = button.getAttribute('data-copy');
        var done = function () { AEP.toast(AEP.t('js.copied'), 'success'); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done);
        } else {
            var ta = document.createElement('textarea');
            ta.value = text; document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); done(); } catch (e) { /* ignore */ }
            ta.remove();
        }
    });
    document.querySelectorAll('.aep-placeholder').forEach(function (el) {
        el.addEventListener('click', function () {
            var active = document.activeElement;
            if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA')) { return; }
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(el.textContent.replace('[:3]', '')).then(function () { AEP.toast(AEP.t('js.copied'), 'success'); });
            }
        });
    });

    /* ---------- Local time for <time data-local-time> ---------- */
    document.querySelectorAll('time[data-local-time]').forEach(function (el) {
        var date = new Date(el.getAttribute('datetime'));
        if (!isNaN(date.getTime())) {
            el.textContent = date.toLocaleString(document.documentElement.lang || undefined, { dateStyle: 'medium', timeStyle: 'short' });
            el.title = date.toISOString();
        }
    });

    /* ---------- Remember the active tab ---------- */
    document.querySelectorAll('[data-remember-tab]').forEach(function (list) {
        var key = 'aep-tab-' + location.pathname;
        var target = location.hash || (function () { try { return sessionStorage.getItem(key); } catch (e) { return null; } })();
        if (target) {
            var button = list.querySelector('[data-bs-target="' + target + '"]');
            if (button && window.bootstrap) { window.bootstrap.Tab.getOrCreateInstance(button).show(); }
        }
        list.addEventListener('shown.bs.tab', function (event) {
            try { sessionStorage.setItem(key, event.target.getAttribute('data-bs-target')); } catch (e) { /* ignore */ }
        });
    });

    /* ---------- Upload form with drag & drop and progress ---------- */
    document.querySelectorAll('[data-upload-form]').forEach(function (form) {
        var zone = form.querySelector('[data-dropzone]');
        var input = form.querySelector('input[type="file"]');
        var label = form.querySelector('[data-dropzone-label]');
        var progress = form.querySelector('[data-upload-progress]');
        var showFile = function () {
            if (input.files && input.files[0]) {
                label.textContent = input.files[0].name + ' (' + (input.files[0].size / 1048576).toFixed(1) + ' MB)';
                zone.classList.add('has-file');
            }
        };
        input.addEventListener('change', showFile);
        ['dragenter', 'dragover'].forEach(function (name) {
            zone.addEventListener(name, function (e) { e.preventDefault(); zone.classList.add('dragover'); });
        });
        ['dragleave', 'drop'].forEach(function (name) {
            zone.addEventListener(name, function (e) { e.preventDefault(); zone.classList.remove('dragover'); });
        });
        zone.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files.length) {
                input.files = e.dataTransfer.files;
                showFile();
            }
        });
        form.addEventListener('submit', function (e) {
            if (!window.FormData || !input.files || !input.files[0]) { return; }
            e.preventDefault();
            var xhr = new XMLHttpRequest();
            var bar = progress.querySelector('.progress-bar');
            progress.classList.remove('d-none');
            form.querySelector('button[type="submit"]').disabled = true;
            xhr.upload.addEventListener('progress', function (ev) {
                if (ev.lengthComputable) { bar.style.width = Math.round(ev.loaded / ev.total * 100) + '%'; }
            });
            xhr.addEventListener('load', function () {
                var data = {};
                try { data = JSON.parse(xhr.responseText); } catch (err) { data = {}; }
                if (xhr.status >= 200 && xhr.status < 300 && data.redirect) {
                    location.href = data.redirect;
                } else {
                    form.querySelector('button[type="submit"]').disabled = false;
                    progress.classList.add('d-none');
                    AEP.toast(data.error || AEP.t('js.upload_failed'), 'danger');
                }
            });
            xhr.addEventListener('error', function () {
                form.querySelector('button[type="submit"]').disabled = false;
                AEP.toast(AEP.t('js.upload_failed'), 'danger');
            });
            xhr.open('POST', form.action);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-CSRF-Token', AEP.csrf);
            xhr.send(new FormData(form));
        });
    });

    /* ---------- Passkey login ---------- */
    var passkeyError = function (root, error) {
        var el = root.querySelector('[data-passkey-error]') || document.querySelector('[data-passkey-error]');
        if (el) {
            el.textContent = error && error.name === 'NotAllowedError' ? AEP.t('js.passkey_cancelled') : (error && error.message ? error.message : String(error));
            el.classList.remove('d-none');
        }
    };
    document.querySelectorAll('[data-passkey-login]').forEach(function (button) {
        if (!window.PublicKeyCredential) { button.disabled = true; button.title = AEP.t('js.passkey_unsupported'); return; }
        button.addEventListener('click', function () {
            var root = button.parentElement;
            AEP.request('POST', button.getAttribute('data-options-url'), {}).then(function (options) {
                return navigator.credentials.get({
                    publicKey: {
                        challenge: AEP.b64urlToBuffer(options.challenge),
                        rpId: options.rpId,
                        timeout: options.timeout,
                        userVerification: options.userVerification || 'preferred',
                        allowCredentials: (options.allowCredentials || []).map(function (c) {
                            return { type: 'public-key', id: AEP.b64urlToBuffer(c.id) };
                        })
                    }
                });
            }).then(function (credential) {
                return AEP.request('POST', button.getAttribute('data-verify-url'), {
                    id: credential.id,
                    rawId: AEP.bufferToB64url(credential.rawId),
                    type: credential.type,
                    response: {
                        clientDataJSON: AEP.bufferToB64url(credential.response.clientDataJSON),
                        authenticatorData: AEP.bufferToB64url(credential.response.authenticatorData),
                        signature: AEP.bufferToB64url(credential.response.signature),
                        userHandle: credential.response.userHandle ? AEP.bufferToB64url(credential.response.userHandle) : null
                    }
                });
            }).then(function (result) {
                location.href = result.redirect || AEP.url('/');
            }).catch(function (error) { passkeyError(root, error); });
        });
    });

    /* ---------- Passkey registration (account page) ---------- */
    document.querySelectorAll('[data-passkey-register]').forEach(function (button) {
        if (!window.PublicKeyCredential) { button.disabled = true; button.title = AEP.t('js.passkey_unsupported'); return; }
        button.addEventListener('click', function () {
            var root = button.closest('.aep-panel') || document;
            var nameInput = root.querySelector('[data-passkey-name]');
            AEP.request('POST', button.getAttribute('data-options-url'), {}).then(function (options) {
                return navigator.credentials.create({
                    publicKey: {
                        challenge: AEP.b64urlToBuffer(options.challenge),
                        rp: options.rp,
                        user: { id: AEP.b64urlToBuffer(options.user.id), name: options.user.name, displayName: options.user.displayName },
                        pubKeyCredParams: options.pubKeyCredParams,
                        timeout: options.timeout,
                        attestation: options.attestation || 'none',
                        authenticatorSelection: options.authenticatorSelection,
                        excludeCredentials: (options.excludeCredentials || []).map(function (c) {
                            return { type: 'public-key', id: AEP.b64urlToBuffer(c.id) };
                        })
                    }
                });
            }).then(function (credential) {
                return AEP.request('POST', button.getAttribute('data-verify-url'), {
                    name: nameInput ? nameInput.value : 'Passkey',
                    id: credential.id,
                    rawId: AEP.bufferToB64url(credential.rawId),
                    type: credential.type,
                    response: {
                        clientDataJSON: AEP.bufferToB64url(credential.response.clientDataJSON),
                        attestationObject: AEP.bufferToB64url(credential.response.attestationObject)
                    }
                });
            }).then(function () {
                location.reload();
            }).catch(function (error) { passkeyError(root, error); });
        });
    });

    /* ---------- Live job status (lists and detail page) ---------- */
    var pollTargets = [];
    document.querySelectorAll('[data-job-poll] [data-job-id]').forEach(function (row) {
        var status = row.querySelector('[data-job-status]');
        pollTargets.push({ id: row.getAttribute('data-job-id'), status: status ? status.getAttribute('data-job-status') : '', stages: null });
    });
    var live = document.querySelector('[data-job-live]');
    if (live && live.getAttribute('data-job-final') !== '1') {
        var stages = [];
        document.querySelectorAll('.aep-pipeline-graph [data-stage-position]').forEach(function (node) {
            var match = node.className.match(/aep-stage-([a-z_]+)/);
            stages.push(match ? match[1] : '');
        });
        pollTargets.push({ id: live.getAttribute('data-job-live'), status: live.getAttribute('data-job-status-value'), stages: stages.join(',') });
    }
    var active = pollTargets.filter(function (t) { return ['success', 'failed', 'rejected', 'canceled'].indexOf(t.status) === -1; });
    if (active.length) {
        var poll = function () {
            if (document.hidden) { return; }
            var ids = active.map(function (t) { return t.id; }).join(',');
            AEP.request('GET', AEP.url('/jobs/status') + '?ids=' + encodeURIComponent(ids)).then(function (data) {
                var changed = (data.jobs || []).some(function (job) {
                    var target = active.filter(function (t) { return String(t.id) === String(job.id); })[0];
                    if (!target) { return false; }
                    if (target.status !== job.status) { return true; }
                    if (target.stages !== null) {
                        var now = job.stages.map(function (s) { return s.status; }).join(',');
                        return now !== target.stages;
                    }
                    return false;
                });
                if (changed) { location.reload(); }
            }).catch(function () { /* offline – try again later */ });
        };
        setInterval(poll, 4000);
    }

    /* ---------- A/B player ---------- */
    document.querySelectorAll('[data-ab-player]').forEach(function (player) {
        var audio = player.querySelector('audio');
        player.querySelectorAll('[data-ab-src]').forEach(function (button) {
            button.addEventListener('click', function () {
                var time = audio.currentTime;
                var playing = !audio.paused;
                player.querySelectorAll('[data-ab-src]').forEach(function (b) { b.classList.toggle('active', b === button); });
                audio.src = button.getAttribute('data-ab-src');
                audio.addEventListener('loadedmetadata', function once() {
                    audio.removeEventListener('loadedmetadata', once);
                    audio.currentTime = Math.min(time, audio.duration || time);
                    if (playing) { audio.play(); }
                });
            });
        });
    });

    /* ---------- Storage / publish forms: show fields of the selected type ---------- */
    document.querySelectorAll('[data-source-type]').forEach(function (select) {
        var form = select.form;
        var update = function () {
            form.querySelectorAll('[data-source-field]').forEach(function (field) {
                var types = field.getAttribute('data-source-field').split(' ');
                var visible = types.indexOf(select.value) !== -1;
                field.classList.toggle('d-none', !visible);
                field.querySelectorAll('input, select, textarea').forEach(function (el) { el.disabled = !visible; });
            });
            var portInput = form.querySelector('[name="config[port]"]');
            if (portInput && !portInput.value) {
                portInput.placeholder = { ftp: '21', sftp: '22', scp: '22' }[select.value] || '';
            }
            var hint = form.querySelector('[data-hint-for="url"]');
            if (hint) {
                hint.textContent = AEP.t(select.value === 'nextcloud' ? 'js.hint.nextcloud_url' : 'js.hint.webdav_url');
            }
        };
        select.addEventListener('change', update);
        update();
    });

    document.querySelectorAll('[data-source-test]').forEach(function (button) {
        button.addEventListener('click', function () {
            var box = document.querySelector('[data-source-test-result]');
            button.disabled = true;
            box.classList.remove('d-none');
            box.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>' + AEP.escape(AEP.t('js.testing'));
            AEP.request('POST', button.getAttribute('data-source-test'), {}).then(function (data) {
                var html = '<div class="d-flex gap-2 align-items-start"><span class="aep-led on ' + (data.ok ? 'aep-led-green' : 'aep-led-red') + ' mt-1"></span><div><div class="fw-semibold">' + AEP.escape(data.message) + '</div>';
                if (data.files && data.files.length) {
                    html += '<ul class="small font-monospace mb-0 mt-2">' + data.files.map(function (f) {
                        return '<li>' + AEP.escape(f.path) + ' <span class="text-body-secondary">(' + (f.size / 1048576).toFixed(1) + ' MB)</span></li>';
                    }).join('') + '</ul>';
                }
                box.innerHTML = html + '</div></div>';
            }).catch(function (error) {
                box.innerHTML = '<span class="aep-led aep-led-red on me-2"></span>' + AEP.escape(error.message);
            }).finally(function () { button.disabled = false; });
        });
    });

    /* ---------- Cover upload in metadata templates ---------- */
    document.querySelectorAll('[data-cover-upload]').forEach(function (group) {
        var file = group.querySelector('input[type="file"]');
        var target = group.querySelector('input[name="fields[cover]"]');
        file.addEventListener('change', function () {
            if (!file.files[0]) { return; }
            var data = new FormData();
            data.append('cover', file.files[0]);
            AEP.request('POST', group.getAttribute('data-cover-upload'), data).then(function (result) {
                target.value = result.path;
                AEP.toast(AEP.t('js.cover_uploaded'), 'success');
            }).catch(function (error) { AEP.toast(error.message, 'danger'); });
        });
    });

    /* ---------- Branding: live color preview & presets ---------- */
    document.querySelectorAll('[data-color-preview]').forEach(function (input) {
        input.addEventListener('input', function () {
            document.documentElement.style.setProperty(input.getAttribute('data-color-preview'), input.value);
        });
    });
    document.querySelectorAll('[data-color-set]').forEach(function (button) {
        button.addEventListener('click', function () {
            var colors = button.getAttribute('data-color-set').split(',');
            var form = button.closest('form');
            form.querySelector('[name="primary_color"]').value = colors[0];
            form.querySelector('[name="secondary_color"]').value = colors[1];
            document.documentElement.style.setProperty('--bs-primary', colors[0]);
            document.documentElement.style.setProperty('--aep-secondary', colors[1]);
        });
    });

    /* ---------- PWA: service worker & offline indicator ---------- */
    if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(AEP.url('/sw.js'), { scope: AEP.base + '/' }).catch(function () { /* optional */ });
        });
    }
    var offline = document.getElementById('aepOffline');
    var updateOnline = function () { if (offline) { offline.classList.toggle('d-none', navigator.onLine); } };
    window.addEventListener('online', updateOnline);
    window.addEventListener('offline', updateOnline);
    updateOnline();
})();
